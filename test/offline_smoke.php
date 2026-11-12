<?php

/**
 * Offline smoke run for the oldest PHP that composer.json allows (the "floor"
 * job in .github/workflows/sdk_ci.yml).
 *
 * The PHPUnit suite cannot run there: no PHPUnit that installs on PHP 7.0
 * knows the assertions the suite uses, and PHP 7.0 has no void return type.
 * This script drives the same in-memory API (test/OfflineHttp.php) through
 * representative calls instead, with plain checks. It never touches the
 * network and needs no API key.
 *
 * Run: php test/offline_smoke.php   (after composer install or dump-autoload)
 */

namespace ZeroBounce\Tests;

use DateTime;
use ZeroBounce\SDK\ZBApiException;
use ZeroBounce\SDK\ZBException;
use ZeroBounce\SDK\ZBGetFileOptions;
use ZeroBounce\SDK\ZBValidateOptions;
use ZeroBounce\SDK\ZeroBounceWarmup;

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Any notice, warning or deprecation from the SDK fails the run, except the
// deprecation guessFormat() raises on purpose, which is counted instead.
// Errors silenced with @ (the SDK's cleanup unlinks) are left alone.
$deprecations = array();
set_error_handler(function ($level, $message, $file, $line) use (&$deprecations) {
    if (!(error_reporting() & $level)) {
        return false;
    }
    if ($level === E_USER_DEPRECATED) {
        $deprecations[] = $message;
        return true;
    }
    throw new \ErrorException($message, 0, $level, $file, $line);
});

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/OfflineHttp.php';
require __DIR__ . '/RequestSweep.php';

class SmokeWarmup extends ZeroBounceWarmup
{
    /** @var array<int, array<string, mixed>> */
    public $requests = array();

    protected function executeHttp($method, $url, array $headers = array(), $body = null, $timeout = 120)
    {
        $this->requests[] = array('method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body);
        return array(
            'status' => 200,
            'headers' => array('HTTP/1.1 200 OK', 'Content-Type: application/json'),
            'body' => '{"seed_count":3}',
        );
    }
}

$checks = 0;
$failures = array();

function check($ok, $label)
{
    global $checks, $failures;
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
}

function client(FakeApi $api, $key)
{
    $client = new OfflineClient($api);
    $client->initialize($key);
    return $client;
}

function only(FakeApi $api)
{
    $records = $api->take();
    if (count($records) !== 1) {
        throw new \RuntimeException('expected one request, got ' . count($records));
    }
    return $records[0];
}

/** @return ZBException|null */
function rejected($call)
{
    try {
        $call();
    } catch (ZBException $e) {
        return $e;
    }
    return null;
}

function scenario($name, $body)
{
    global $failures;
    try {
        $body();
    } catch (\Throwable $e) {
        $failures[] = $name . ': ' . get_class($e) . ': ' . $e->getMessage()
            . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    }
}

$csv = __DIR__ . '/email_file.csv';
$tmp = sys_get_temp_dir() . '/zb-smoke-' . getmypid();

scenario('getCredits', function () {
    $api = new FakeApi();
    $response = client($api, 'credits-key')->getCredits();
    $request = only($api);
    check((string)$response->credits === '7', 'getCredits: credits');
    check($request['method'] === 'GET', 'getCredits: GET');
    check($request['host'] === 'api.zerobounce.net', 'getCredits: host');
    check($request['path'] === '/v2/getcredits', 'getCredits: path');
    check($request['api_key'] === 'credits-key', 'getCredits: api_key');
});

scenario('validate', function () {
    $api = new FakeApi();
    $options = new ZBValidateOptions();
    $options->activityData = false;
    $options->verifyPlus = true;
    $response = client($api, 'validate-key')->validate('person@example.com', '', $options);
    $request = only($api);
    check($response->address === 'validate-key', 'validate: address');
    check($request['path'] === '/v2/validate', 'validate: path');
    check($request['fields']['email'] === 'person@example.com', 'validate: email');
    check($request['fields']['activity_data'] === 'false', 'validate: activity_data');
    check($request['fields']['verify_plus'] === 'true', 'validate: verify_plus');
});

scenario('validateBatch', function () {
    $api = new FakeApi();
    $response = client($api, 'batch-key')->validateBatch(array(
        array('a@example.com', ''),
        array('b@example.com', '203.0.113.9'),
    ));
    $request = only($api);
    check($response->emailBatch[0]->address === 'batch-key', 'validateBatch: address');
    check($request['method'] === 'POST', 'validateBatch: POST');
    check($request['path'] === '/v2/validatebatch', 'validateBatch: path');
    check(strpos($request['fields']['__json'], '"ip_address":"203.0.113.9"') !== false, 'validateBatch: JSON body');
});

scenario('usage, activity, scoring, finder', function () {
    $api = new FakeApi();
    $client = client($api, 'usage-key');
    $usage = $client->getApiUsage(new DateTime('2018-01-01'), new DateTime('2018-01-31'));
    $request = only($api);
    check((int)$usage->total === 1, 'getApiUsage: total');
    check($request['fields']['start_date'] === '2018-01-01', 'getApiUsage: start_date');

    check((bool)$client->getActivity('person@example.com')->found, 'getActivity: found');
    check(only($api)['path'] === '/v2/activity', 'getActivity: path');

    check($client->scoreEmail('person@example.com')->email === 'usage-key', 'scoreEmail: email');
    check(only($api)['path'] === '/v2/scoring', 'scoreEmail: path');
    $client->scoreEmailPost('person@example.com');
    check(only($api)['method'] === 'POST', 'scoreEmailPost: POST');

    check($client->findEmail('example.com', 'Ada', null, 'M', 'Lovelace')->email === 'usage-key', 'findEmail: email');
    $finder = only($api);
    check($finder['path'] === '/v2/guessformat', 'findEmail: path');
    check($finder['fields']['last_name'] === 'Lovelace', 'findEmail: last_name');
    $client->findEmailFormat('example.com');
    check(only($api)['fields']['domain'] === 'example.com', 'findEmailFormat: domain');
});

scenario('guessFormat deprecation', function () use (&$deprecations) {
    $api = new FakeApi();
    $before = count($deprecations);
    client($api, 'finder-key')->guessFormat('example.com', 'Ada', null, null);
    check(only($api)['path'] === '/v2/guessformat', 'guessFormat: path');
    check(count($deprecations) === $before + 1, 'guessFormat: raises E_USER_DEPRECATED');
});

scenario('bulk file round trip', function () use ($csv, $tmp) {
    $api = new FakeApi();
    $client = client($api, 'file-key');
    $sent = $client->sendFile($csv, 0, 'https://example.com/hook', 0, null, null, null, false, false);
    $request = only($api);
    check($sent->fileId === 'file-key', 'sendFile: fileId');
    check($request['host'] === 'bulkapi.zerobounce.net', 'sendFile: host');
    check(strpos($request['content_type'], 'multipart/form-data') === 0, 'sendFile: multipart');
    check($request['fields']['file'] === '<file>', 'sendFile: file part');

    check($client->fileStatus('file 1')->fileId === 'file-key', 'fileStatus: fileId');
    check(strpos(only($api)['url'], 'file_id=file+1') !== false, 'fileStatus: encoded id');

    $path = $tmp . '-get.csv';
    $got = $client->getFile('file-9', $path);
    only($api);
    check(file_get_contents($got->localFilePath) === "email\nfile-key@example.com\n", 'getFile: contents');
    @unlink($path);

    $options = new ZBGetFileOptions();
    $options->stream = true;
    $streamed = $client->getFile('file-9', $path, $options);
    check(only($api)['method'] === 'GET', 'getFile stream: GET');
    check(file_get_contents($streamed->localFilePath) === "email\nfile-key@example.com\n", 'getFile stream: contents');
    @unlink($path);

    $client->deleteFile('file-9');
    check(only($api)['path'] === '/v2/deletefile', 'deleteFile: path');
});

scenario('email finder file and filters', function () use ($csv, $tmp) {
    $api = new FakeApi();
    $client = client($api, 'p3-key');
    $client->sendEmailFinderFile($csv, 0, 0, null, null, null, false);
    $sent = only($api);
    check($sent['path'] === '/email-finder/sendfile', 'sendEmailFinderFile: path');
    check($sent['fields']['has_header_row'] === 'false', 'sendEmailFinderFile: has_header_row');
    $path = $tmp . '-finder.csv';
    $client->emailFinderGetFile('fid', $path);
    only($api);
    check(strpos(file_get_contents($path), 'p3-key@example.com') !== false, 'emailFinderGetFile: contents');
    @unlink($path);
    $added = $client->addFilter('exclude', 'domain', '0');
    $add = only($api);
    check($added['echo_api_key'] === 'p3-key', 'addFilter: result');
    check($add['path'] === '/v2/filters/add', 'addFilter: path');
    check($add['fields']['value'] === '0', 'addFilter: value');
    $client->listFilters(false);
    check(only($api)['path'] === '/v2/filters/list', 'listFilters: path');
});

scenario('inbox placement', function () {
    $api = new FakeApi();
    client($api, 'ipt-key')->getInboxPlacementProviders();
    $request = only($api);
    check($request['path'] === '/api/ipt/providers', 'inbox placement: path');
    check($request['authorization'] === 'Bearer ipt-key', 'inbox placement: bearer');
    check(!isset($request['fields']['api_key']), 'inbox placement: no api_key field');
});

scenario('warmup', function () {
    $warmup = new SmokeWarmup('warmup-key');
    $warmup->getSeedCount();
    check(count($warmup->requests) === 1, 'warmup: one request');
    $request = $warmup->requests[0];
    check(parse_url($request['url'], PHP_URL_HOST) === 'warmup-api.zerobounce.net', 'warmup: host');
    check(parse_url($request['url'], PHP_URL_PATH) === '/api/v1/warmup/seed-count', 'warmup: path');
    check(in_array('API-Key: warmup-key', $request['headers'], true), 'warmup: API-Key header');
});

scenario('error bodies', function () use ($tmp) {
    $api = new FakeApi();
    $html = rejected(function () use ($api) { client($api, 'html-403-user')->getCredits(); });
    check($html !== null && strpos($html->getMessage(), 'Access denied') !== false, 'HTML 403: Access denied');
    check($html !== null && strlen($html->getMessage()) < 500, 'HTML 403: short message');

    $path = $tmp . '-denied.csv';
    $denied = rejected(function () use ($api, $path) { client($api, 'html-200-file')->getFile('fid', $path); });
    check($denied !== null && !file_exists($path), 'HTML 200 getFile: rejected, nothing saved');

    $malformed = rejected(function () use ($api) { client($api, 'malformed-user')->validate('a@example.com', ''); });
    check($malformed !== null && strpos($malformed->getMessage(), 'not valid JSON') !== false, 'malformed JSON');

    $missing = rejected(function () use ($api) {
        client($api, 'success-false-key')->fileStatus('0d1b7a4e-1111-2222-3333-444455556666');
    });
    check($missing instanceof ZBApiException && $missing->getStatusCode() === 200, 'success:false is ZBApiException');
    check($missing !== null && $missing->getMessage() === 'File cannot be found.', 'success:false message');

    $dead = rejected(function () use ($api) { client($api, 'dead-key-user')->validate('a@example.com', ''); });
    check($dead !== null, 'dead key on HTTP 200 is an error');
    $api->clear();

    $plain = rejected(function () { client(new FakeApi(), 'key')->initialize('key', null, 'http://example.com/le'); });
    check($plain !== null && strpos($plain->getMessage(), 'https') !== false, 'http base URL rejected');
    $spaced = rejected(function () { client(new FakeApi(), " key\n"); });
    check($spaced !== null, 'key with whitespace rejected');
});

scenario('standard parity', function () {
    $response = new \ZeroBounce\SDK\ZBValidateResponse();
    $response->Deserialize('{"mx_found":"false","catchall_domain":"unknown","free_email":"TRUE","status":"brand_new"}');
    check($response->mxFound === false, 'mxFound "false" is false');
    check($response->catchallDomain === null, 'catchallDomain "unknown" is null');
    check($response->freeEmail === true, 'freeEmail "TRUE" is true');
    check($response->status === 'brand_new', 'unknown status kept');

    $api = new FakeApi();
    $options = new ZBValidateOptions();
    $options->activityData = 'false';
    client($api, 'bool-key')->validate('a@example.com', null, $options);
    check(only($api)['fields']['activity_data'] === 'false', 'activityData "false" sent as false');
    $options->activityData = 'yes';
    $junk = rejected(function () use ($api, $options) { client($api, 'bool-key')->validate('a@example.com', null, $options); });
    check($junk !== null && strpos($junk->getMessage(), 'must be true or false') !== false, 'junk boolean rejected');
    check($api->count() === 0, 'junk boolean: nothing sent');

    $client = client($api, 'secret-debug-key');
    check(strpos(print_r($client, true), 'secret-debug-key') === false, 'print_r hides the key');
});

// Before PHP 8.2 there is no #[\SensitiveParameter]: this is where the SDK's
// own trace scrubbing is what keeps the key out.
scenario('the key in traces, var_export and serialize', function () {
    $previous = ini_set('zend.exception_ignore_args', '0');
    $key = 'trace-secret-0123456789abcdef';
    $calls = array(
        'initialize, http baseUrl' => function () use ($key) { client(new FakeApi(), $key)->initialize($key, 'http://api.example.test/v2'); },
        'initialize, key with a newline' => function () use ($key) { client(new FakeApi(), 'k')->initialize($key . "\n"); },
        'warmup, http baseUrl' => function () use ($key) { new ZeroBounceWarmup($key, 'http://warmup.example.test'); },
        'warmup, key with a newline' => function () use ($key) { new ZeroBounceWarmup($key . "\n"); },
    );
    foreach ($calls as $name => $call) {
        $error = rejected($call);
        check($error !== null, $name . ': rejected');
        if ($error !== null) {
            check(strpos($error->getTraceAsString(), substr($key, 0, 15)) === false, $name . ': key not in the trace');
        }
    }
    ini_set('zend.exception_ignore_args', $previous === false ? '0' : $previous);

    $api = new FakeApi();
    $client = client($api, 'export-client-key');
    $warmup = new SmokeWarmup('export-warmup-key');
    check(strpos(var_export($client, true), 'export-client-key') === false, 'var_export hides the key');
    check(strpos(serialize($client), 'export-client-key') === false, 'serialize leaves the key out');
    check(strpos(var_export($warmup, true), 'export-warmup-key') === false, 'warmup var_export hides the key');
    check(strpos(serialize($warmup), 'export-warmup-key') === false, 'warmup serialize leaves the key out');
    $copy = unserialize(serialize($client));
    check(rejected(function () use ($copy) { $copy->getCredits(); }) instanceof \ZeroBounce\SDK\ZBMissingApiKeyException, 'an unserialized client has no key');
    check($api->count() === 0, 'an unserialized client sends nothing');
    $client->getCredits();
    check(only($api)['api_key'] === 'export-client-key', 'the original client keeps its key');
});

// The same sweep as TransportGuardsTest::testNoErrorCarriesASecretInItsStackTrace:
// PHP 7.0-7.3 always keeps call arguments in a trace and has no
// #[\SensitiveParameter], so the SDK's own scrubbing is all there is.
// The secrets are constants and static properties, never values a closure
// or a frame of this script holds, so only the SDK's frames could show them.
class TraceSecrets
{
    const KEY = 'floor+key/0123456789abcdef';
    const PASSWORD = 'floor+pass/0123456789';
    /** @var string */
    public static $root = '';
}

class TraceCannedClient extends \ZeroBounce\SDK\ZeroBounce
{
    /** @var array{status: int, headers: string[], body: string} */
    private $answer;

    public function __construct($status, $body)
    {
        parent::__construct();
        $this->answer = array('status' => $status, 'headers' => array('HTTP/1.1 ' . $status . ' X', 'Content-Type: text/plain'), 'body' => $body);
    }

    public function __debugInfo()
    {
        return array();
    }

    protected function executeHttp($method, $url, array $headers = array(), $body = null, $timeout = 120)
    {
        return $this->answer;
    }

    protected function streamHttpToHandle($url, $handle)
    {
        fwrite($handle, $this->answer['body']);
        return array('status' => $this->answer['status'], 'headers' => $this->answer['headers'], 'head' => $this->answer['body'], 'error' => '');
    }
}

/** Real curl requests to TraceSecrets::$root over plain http. */
class TraceLoopbackClient extends \ZeroBounce\SDK\ZeroBounce
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function checkedUrl($url)
    {
        foreach (array('https://api.zerobounce.net', 'https://bulkapi.zerobounce.net') as $host) {
            if (strpos($url, $host) === 0) {
                return TraceSecrets::$root . substr($url, strlen($host));
            }
        }
        return $url;
    }
}

class TraceWarmup extends ZeroBounceWarmup
{
    /** @var array{status: int, headers: string[], body: string}|null null: real curl to TraceSecrets::$root */
    private $answer;

    public function __construct($answer)
    {
        parent::__construct(TraceSecrets::KEY, 'https://warmup.test');
        $this->answer = $answer;
    }

    public function __debugInfo()
    {
        return array();
    }

    protected function executeHttp($method, $url, array $headers = array(), $body = null, $timeout = 120)
    {
        if ($this->answer !== null) {
            return $this->answer;
        }
        return parent::executeHttp($method, TraceSecrets::$root . substr($url, strlen('https://warmup.test')), $headers, $body, $timeout);
    }
}

class TraceSweep
{
    use RequestSweep;

    public function core(\ZeroBounce\SDK\ZeroBounce $c, $out)
    {
        $calls = $this->sweepCalls($c, $out);
        $missing = array_diff($this->requestMethodNames(), array_keys($calls));
        if ($missing) {
            throw new \RuntimeException('missing from the sweep: ' . implode(', ', $missing));
        }
        return $calls;
    }

    public function warmup(ZeroBounceWarmup $w)
    {
        $calls = array(
            'listSendingAccounts' => function () use ($w) { $w->listSendingAccounts(); },
            'getSendingAccount' => function () use ($w) { $w->getSendingAccount('a@b.com'); },
            'addSendingAccount' => function () use ($w) { $w->addSendingAccount('a@b.com', 'self_managed', 0); },
            'updateSendingAccount' => function () use ($w) { $w->updateSendingAccount('a@b.com', 1, 4, true); },
            'deleteSendingAccount' => function () use ($w) { $w->deleteSendingAccount('a@b.com'); },
            'changeFromAddress' => function () use ($w) { $w->changeFromAddress('a@b.com', 'c@d.com'); },
            'listFromAddresses' => function () use ($w) { $w->listFromAddresses(); },
            'getEngagementRules' => function () use ($w) { $w->getEngagementRules(); },
            'setEngagementRule' => function () use ($w) { $w->setEngagementRule('a@b.com', 2); },
            'getCustomEngagement' => function () use ($w) { $w->getCustomEngagement('a@b.com'); },
            'createCustomEngagement' => function () use ($w) { $w->createCustomEngagement('a@b.com', 10, 10, 5); },
            'updateCustomEngagement' => function () use ($w) { $w->updateCustomEngagement('a@b.com', 10, 10, 5); },
            'deleteCustomEngagement' => function () use ($w) { $w->deleteCustomEngagement('a@b.com', 1); },
            'getSeeds' => function () use ($w) { $w->getSeeds(); },
            'downloadSeeds' => function () use ($w) { $w->downloadSeeds(); },
            'getSeedCount' => function () use ($w) { $w->getSeedCount(); },
            'getProgressToday' => function () use ($w) { $w->getProgressToday(); },
            'getSentToday' => function () use ($w) { $w->getSentToday(); },
            'getEmailsPerDay' => function () use ($w) { $w->getEmailsPerDay(); },
            'getStatistics' => function () use ($w) { $w->getStatistics('a@b.com'); },
            'getStatisticsPerDay' => function () use ($w) { $w->getStatisticsPerDay('a@b.com', '2026-09-01', '2026-09-14'); },
            'getStatisticsDetailed' => function () use ($w) { $w->getStatisticsDetailed('a@b.com'); },
            'downloadStatistics' => function () use ($w) { $w->downloadStatistics('a@b.com'); },
            'getFromAddressStatistics' => function () use ($w) { $w->getFromAddressStatistics('a@b.com'); },
            'checkDomainDmarc' => function () use ($w) { $w->checkDomainDmarc('a@b.com'); },
            'testSmtpConfiguration' => function () use ($w) {
                $w->testSmtpConfiguration('a@b.com', 'u', TraceSecrets::PASSWORD, 'smtp.example.com', 587);
            },
        );
        foreach ((new \ReflectionClass(ZeroBounceWarmup::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isStatic() && strpos($method->getName(), '__') !== 0 && !isset($calls[$method->getName()])) {
                throw new \RuntimeException('missing from the Warmup sweep: ' . $method->getName());
            }
        }
        return $calls;
    }

    /** @return string[] where a secret shows in the error $call throws, or 'returned' */
    public function leaks(callable $call)
    {
        try {
            $this->quietly($call);
        } catch (\Exception $e) {
            $found = array();
            for ($error = $e; $error !== null; $error = $error->getPrevious()) {
                $views = array($error->getMessage(), (string)$error, var_export($error->getTrace(), true), print_r($error, true), var_export($error, true));
                try {
                    $views[] = serialize($error);
                } catch (\Exception $notSerializable) {
                }
                foreach ($views as $view => $text) {
                    foreach (array(TraceSecrets::KEY, TraceSecrets::PASSWORD) as $secret) {
                        foreach (array($secret, rawurlencode($secret), urlencode($secret), substr(json_encode($secret), 1, -1)) as $form) {
                            if (strpos($text, $form) !== false) {
                                $found[] = get_class($error) . ' view ' . $view;
                            }
                        }
                    }
                }
            }
            return array_values(array_unique($found));
        }
        return array('returned');
    }
}

scenario('no secret in any error\'s stack-trace arguments', function () use ($tmp) {
    $previous = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');
    $sweep = new TraceSweep();
    $out = $tmp . '-trace.csv';
    // A cut-short body: a 200 header promising more than it sends.
    $router = tempnam(sys_get_temp_dir(), 'zb-smoke-router-');
    file_put_contents($router, '<?php header("Content-Type: application/json"); header("Content-Length: 1000"); echo "{\"a\":1}"; return true;');
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($server, false);
    fclose($server);
    $cutPort = (int)substr(strrchr($name, ':'), 1);
    $process = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $cutPort . ' ' . escapeshellarg($router),
        array(0 => array('pipe', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
    for ($i = 0; $i < 100 && !($probe = @fsockopen('127.0.0.1', $cutPort, $errno, $errstr, 0.1)); $i++) {
        usleep(20000);
    }
    if (!empty($probe)) {
        fclose($probe);
    }
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($server, false);
    fclose($server);
    $refused = 'http://127.0.0.1:' . (int)substr(strrchr($name, ':'), 1);
    try {
        $roots = array('refused' => $refused, 'cut short' => 'http://127.0.0.1:' . $cutPort);
        $clients = array(
            '400 quoting the URL' => function () { return new TraceCannedClient(400, 'Bad request: https://x.example/v2/x?api_key=' . rawurlencode(TraceSecrets::KEY)); },
            '500' => function () { return new TraceCannedClient(500, 'Server error'); },
            'refused' => function () { return new TraceLoopbackClient(); },
            'cut short' => function () { return new TraceLoopbackClient(); },
        );
        foreach ($clients as $label => $make) {
            TraceSecrets::$root = isset($roots[$label]) ? $roots[$label] : '';
            $client = $make();
            $client->initialize(TraceSecrets::KEY);
            foreach ($sweep->core($client, $out) as $method => $variants) {
                foreach ($variants as $i => $call) {
                    $found = $sweep->leaks($call);
                    check($found === array(), 'trace: ' . $method . '#' . $i . ' (' . $label . '): ' . implode(', ', $found));
                    @unlink($out);
                }
            }
        }
        $warmups = array(
            '400' => array('status' => 400, 'headers' => array('HTTP/1.1 400 X'), 'body' => 'Bad request'),
            '500' => array('status' => 500, 'headers' => array('HTTP/1.1 500 X'), 'body' => 'Server error'),
            'refused' => null,
            'cut short' => null,
        );
        foreach ($warmups as $label => $answer) {
            TraceSecrets::$root = isset($roots[$label]) ? $roots[$label] : '';
            foreach ($sweep->warmup(new TraceWarmup($answer)) as $method => $call) {
                $found = $sweep->leaks($call);
                check($found === array(), 'trace: Warmup ' . $method . ' (' . $label . '): ' . implode(', ', $found));
            }
        }
    } finally {
        proc_terminate($process);
        proc_close($process);
        @unlink($router);
        if ($previous !== false) {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }
});

echo 'PHP ' . PHP_VERSION . ': ' . $checks . ' checks, ' . count($deprecations) . " expected deprecation(s)\n";
if ($failures) {
    echo count($failures) . " failure(s):\n  - " . implode("\n  - ", $failures) . "\n";
    exit(1);
}
echo "OK\n";

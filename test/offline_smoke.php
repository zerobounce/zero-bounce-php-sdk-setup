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

echo 'PHP ' . PHP_VERSION . ': ' . $checks . ' checks, ' . count($deprecations) . " expected deprecation(s)\n";
if ($failures) {
    echo count($failures) . " failure(s):\n  - " . implode("\n  - ", $failures) . "\n";
    exit(1);
}
echo "OK\n";

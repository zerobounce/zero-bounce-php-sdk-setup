<?php

namespace ZeroBounce\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ZeroBounce\SDK\ZBApiException;
use ZeroBounce\SDK\ZBException;
use ZeroBounce\SDK\ZBGetFileOptions;
use ZeroBounce\SDK\ZBRawJsonResponse;
use ZeroBounce\SDK\ZBSecret;
use ZeroBounce\SDK\ZeroBounce;
use ZeroBounce\SDK\ZeroBounceWarmup;

require_once __DIR__ . '/RequestSweep.php';

/**
 * Real curl requests to plain-http loopback servers: the https check is
 * skipped, every base URL points at one root, and the curl options and the
 * download file can be watched or replaced.
 */
class LoopbackClient extends ZeroBounce
{
    /** @var array<int, array<int, mixed>> */
    public $options = array();

    /** @var callable|null replaces opening the download file */
    public $openFile = null;

    public function __construct()
    {
        parent::__construct();
    }

    protected function checkedUrl($url)
    {
        return $url;
    }

    protected function applyCurlOptions($curl, array $options)
    {
        $this->options[] = $options;
        parent::applyCurlOptions($curl, $options);
    }

    protected function openDownloadFile($path)
    {
        return $this->openFile === null ? parent::openDownloadFile($path) : call_user_func($this->openFile, $path);
    }

    /**
     * Without what this fake records or answers with (request URLs, an
     * answer quoting the key): print_r() of an error shows the closures in
     * its trace, and their bound fakes, which are not the SDK's to mask.
     */
    public function __debugInfo()
    {
        return array();
    }

    public function pointAt($root)
    {
        $ref = new ReflectionClass(ZeroBounce::class);
        foreach (array(
            'apiBaseUrl' => '/v2', 'bulkApiBaseUrl' => '/v2', 'bulkApiRootUrl' => '',
            'emailFinderBulkBaseUrl' => '/email-finder', 'domainSearchBulkBaseUrl' => '/domain-search',
            'listEvaluatorBaseUrl' => '/v2/listevaluator', 'inboxPlacementBaseUrl' => '/api/ipt',
        ) as $name => $path) {
            $prop = $ref->getProperty($name);
            if (\PHP_VERSION_ID < 80100) {
                $prop->setAccessible(true);
            }
            $prop->setValue($this, $root . $path);
        }
    }
}

/** The Warmup client with its https URLs sent to a plain-http loopback root instead. */
class LoopbackWarmup extends ZeroBounceWarmup
{
    /** @var array<int, array<int, mixed>> */
    public $options = array();

    /** @var string|null */
    public $loopback = null;

    public function __construct($key = 'guard-key')
    {
        parent::__construct($key, 'https://warmup.test');
    }

    /**
     * Without what this fake records or answers with (request URLs, an
     * answer quoting the key): print_r() of an error shows the closures in
     * its trace, and their bound fakes, which are not the SDK's to mask.
     */
    public function __debugInfo()
    {
        return array();
    }

    protected function executeHttp($method, $url, array $headers = array(), $body = null, $timeout = 120)
    {
        if ($this->loopback !== null) {
            $url = $this->loopback . substr($url, strlen('https://warmup.test'));
        }
        return parent::executeHttp($method, $url, $headers, $body, $timeout);
    }

    protected function applyCurlOptions($curl, array $options)
    {
        $this->options[] = $options;
        parent::applyCurlOptions($curl, $options);
    }
}

/** Answers every request with one canned response, streamed downloads included. */
class CannedFileClient extends ZeroBounce
{
    /** @var array{status: int, headers: string[], body: string} */
    public $answer;

    public function __construct($status, $contentType, $body)
    {
        parent::__construct();
        $this->answer = array(
            'status' => $status,
            'headers' => array('HTTP/1.1 ' . $status . ' X', 'Content-Type: ' . $contentType),
            'body' => $body,
        );
    }

    protected function executeHttp($method, $url, array $headers = array(), $body = null, $timeout = 120)
    {
        return $this->answer;
    }

    /**
     * Without what this fake records or answers with (request URLs, an
     * answer quoting the key): print_r() of an error shows the closures in
     * its trace, and their bound fakes, which are not the SDK's to mask.
     */
    public function __debugInfo()
    {
        return array();
    }

    /** @var callable|null replaces opening the download file */
    public $openFile = null;

    protected function openDownloadFile($path)
    {
        return $this->openFile === null ? parent::openDownloadFile($path) : call_user_func($this->openFile, $path);
    }

    protected function streamHttpToHandle($url, $handle)
    {
        $body = $this->answer['body'];
        $written = @fwrite($handle, $body) === strlen($body) && @fflush($handle);
        return array(
            'status' => $this->answer['status'],
            'headers' => $this->answer['headers'],
            'head' => substr($body, 0, 65536),
            'error' => '',
            'writeFailed' => !$written,
        );
    }
}

/** Answers every Warmup request with one canned response. */
class CannedFileWarmup extends ZeroBounceWarmup
{
    /** @var array{status: int, headers: string[], body: string} */
    public $answer;

    public function __construct($status, $contentType, $body, $key = 'guard-key')
    {
        parent::__construct($key);
        $this->answer = array(
            'status' => $status,
            'headers' => array('HTTP/1.1 ' . $status . ' X', 'Content-Type: ' . $contentType),
            'body' => $body,
        );
    }

    /**
     * Without what this fake records or answers with (request URLs, an
     * answer quoting the key): print_r() of an error shows the closures in
     * its trace, and their bound fakes, which are not the SDK's to mask.
     */
    public function __debugInfo()
    {
        return array();
    }

    protected function executeHttp($method, $url, array $headers = array(), $body = null, $timeout = 120)
    {
        return $this->answer;
    }
}

/**
 * zbfull://<real path>: a file on a disk that fills up after $room bytes,
 * as a full disk does: the write takes what fits and reports the rest unwritten.
 */
class FullDiskStream
{
    /** @var int|null */
    public static $room = null;
    /** @var bool */
    public static $failFlush = false;
    /** @var resource|null */
    public $context;
    /** @var resource|null */
    private $handle;
    /** @var int */
    private $written = 0;

    private static function real($url)
    {
        return substr($url, strlen('zbfull://'));
    }

    public function stream_open($path, $mode, $options, &$openedPath)
    {
        $this->handle = fopen(self::real($path), $mode);
        return $this->handle !== false;
    }

    public function stream_write($data)
    {
        $take = strlen($data);
        if (self::$room !== null) {
            $take = max(0, min($take, self::$room - $this->written));
        }
        if ($take === 0) {
            return 0;
        }
        $written = (int)fwrite($this->handle, substr($data, 0, $take));
        $this->written += $written;
        return $written;
    }

    public function stream_flush()
    {
        return !self::$failFlush && fflush($this->handle);
    }

    public function stream_close()
    {
        fclose($this->handle);
    }

    public function stream_stat()
    {
        return fstat($this->handle);
    }

    public function url_stat($path, $flags)
    {
        $real = self::real($path);
        return file_exists($real) ? stat($real) : false;
    }

    public function unlink($path)
    {
        return unlink(self::real($path));
    }
}

class TransportGuardsTest extends TestCase
{
    use RequestSweep;

    /** @var array<int, array{process: resource, pipes: resource[], files: string[]}> */
    private $servers = array();

    /** @var string */
    private $dir;

    /**
     * What testNoErrorCarriesASecretInItsStackTrace looks for. Static, so a
     * dump of this test case (PHPUnit's own frames pass it as an argument)
     * does not show them either.
     *
     * @var string[]
     */
    private static $traceSecrets = array();

    /** @var string the password warmupCalls() tests SMTP with (static for the same reason) */
    private static $smtpPassword = 'p';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/zb-guards-' . getmypid() . '-' . mt_rand();
        mkdir($this->dir);
        if (!in_array('zbfull', stream_get_wrappers(), true)) {
            stream_wrapper_register('zbfull', FullDiskStream::class);
        }
        FullDiskStream::$room = null;
        FullDiskStream::$failFlush = false;
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            proc_terminate($server['process']);
            foreach ($server['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($server['process']);
            foreach ($server['files'] as $file) {
                @unlink($file);
            }
        }
        $this->servers = array();
        // scandir, not glob(..., GLOB_BRACE): musl (Alpine) has no GLOB_BRACE.
        foreach ((array)@scandir($this->dir) as $name) {
            $file = $this->dir . '/' . $name;
            if ($name !== '.' && $name !== '..' && is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->dir);
    }

    private function freePort()
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($socket, $errstr);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int)substr(strrchr($name, ':'), 1);
    }

    /**
     * Starts `php -S` with $router as its router script.
     *
     * @return string the server's root URL
     */
    private function serve($router)
    {
        $port = $this->freePort();
        $file = tempnam(sys_get_temp_dir(), 'zb-router-');
        file_put_contents($file, $router);
        $pipes = array();
        // An argument list, so proc_terminate() reaches the server, not a shell.
        // Its access log goes to /dev/null: a pipe nobody reads fills up and
        // blocks the server after a few hundred requests.
        $process = proc_open(
            array(PHP_BINARY, '-S', '127.0.0.1:' . $port, $file),
            array(0 => array('pipe', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
            $pipes
        );
        $this->assertIsResource($process);
        $this->servers[] = array('process' => $process, 'pipes' => $pipes, 'files' => array($file));
        for ($i = 0; $i < 100; $i++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($connection) {
                fclose($connection);
                return 'http://127.0.0.1:' . $port;
            }
            usleep(20000);
        }
        $this->fail('local server did not start');
    }

    private function lines($file)
    {
        return is_file($file) ? count(file($file)) : 0;
    }

    private function thrown(callable $call)
    {
        try {
            $this->quietly($call);
        } catch (ZBException $e) {
            return $e;
        }
        $this->fail('expected a ZBException');
    }

    private function leftovers()
    {
        return array_values(array_filter(scandir($this->dir), function ($name) {
            return $name !== '.' && $name !== '..';
        }));
    }

    /**
     * Every public method of ZeroBounceWarmup, called once.
     *
     * @return array<string, callable>
     */
    private function warmupCalls(ZeroBounceWarmup $w)
    {
        return array(
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
                $w->testSmtpConfiguration('a@b.com', 'u', self::$smtpPassword, 'smtp.example.com', 587);
            },
        );
    }

    private function warmupMethodNames()
    {
        $names = array();
        foreach ((new ReflectionClass(ZeroBounceWarmup::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isStatic() && strpos($method->getName(), '__') !== 0) {
                $names[] = $method->getName();
            }
        }
        return $names;
    }

    public function testTheWarmupSweepCoversEveryPublicMethod()
    {
        $calls = $this->warmupCalls(new LoopbackWarmup());
        $this->assertSame(array(), array_values(array_diff($this->warmupMethodNames(), array_keys($calls))));
    }

    // -- redirects -------------------------------------------------------------

    /**
     * A 3xx WITH a Location header from a real server, pointing at a second
     * one: a client that followed it would send the key there.
     *
     * @return array{0: string, 1: string, 2: string, 3: string} first root, status file, first hits, second hits
     */
    private function redirectServers()
    {
        $status = $this->dir . '/status';
        $firstHits = $this->dir . '/first-hits';
        $secondHits = $this->dir . '/second-hits';
        $second = $this->serve('<?php
file_put_contents(' . var_export($secondHits, true) . ', "hit\n", FILE_APPEND);
header("Content-Type: application/json");
echo "{\"Credits\":\"999\"}";
return true;
');
        $first = $this->serve('<?php
file_put_contents(' . var_export($firstHits, true) . ', "hit\n", FILE_APPEND);
header("Location: ' . $second . '/landed", true, (int)file_get_contents(' . var_export($status, true) . '));
header("Content-Type: text/plain");
return true;
');
        return array($first, $status, $firstHits, $secondHits);
    }

    private function assertRedirectRefused($name, callable $call, $status, $firstHits, $secondHits)
    {
        $before = $this->lines($firstHits);
        $error = $this->thrown($call);
        $this->assertInstanceOf(ZBApiException::class, $error, $name . ' ' . $status . ': ' . $error->getMessage());
        $this->assertSame($status, $error->getStatusCode(), $name);
        $this->assertGreaterThan($before, $this->lines($firstHits), $name . ' sent nothing');
        $this->assertSame(0, $this->lines($secondHits), $name . ' followed a ' . $status);
    }

    public function testNoRequestFollowsARedirect()
    {
        list($first, $statusFile, $firstHits, $secondHits) = $this->redirectServers();
        $client = new LoopbackClient();
        $client->initialize('guard-key');
        $client->pointAt($first);
        $calls = $this->sweepCalls($client, $this->dir . '/out.csv');
        $this->assertSame(array(), array_values(array_diff($this->requestMethodNames(), array_keys($calls))));
        foreach (array(301, 302, 303, 307, 308) as $status) {
            file_put_contents($statusFile, (string)$status);
            foreach ($calls as $name => $variants) {
                foreach ($variants as $call) {
                    $this->assertRedirectRefused($name, $call, $status, $firstHits, $secondHits);
                }
            }
        }
    }

    public function testNoWarmupRequestFollowsARedirect()
    {
        list($first, $statusFile, $firstHits, $secondHits) = $this->redirectServers();
        $warmup = new LoopbackWarmup();
        $warmup->loopback = $first;
        foreach (array(301, 302, 303, 307, 308) as $status) {
            file_put_contents($statusFile, (string)$status);
            foreach ($this->warmupCalls($warmup) as $name => $call) {
                $this->assertRedirectRefused($name, $call, $status, $firstHits, $secondHits);
            }
        }
    }

    // -- timeouts --------------------------------------------------------------

    /**
     * Each call reached curl, and every handle it opened has a connect
     * timeout and either an overall limit or (streamed downloads) a stall limit.
     */
    private function assertTimeouts($recorder, array $calls)
    {
        foreach ($calls as $name => $variants) {
            foreach ((array)$variants as $call) {
                $before = count($recorder->options);
                try {
                    $this->quietly($call);
                } catch (ZBException $e) {
                    // Refused by the closed port, as intended.
                }
                $this->assertGreaterThan($before, count($recorder->options), $name . ' opened no connection');
                foreach (array_slice($recorder->options, $before) as $options) {
                    $this->assertSame(30, isset($options[CURLOPT_CONNECTTIMEOUT]) ? $options[CURLOPT_CONNECTTIMEOUT] : null, $name);
                    $limit = isset($options[CURLOPT_TIMEOUT]) ? $options[CURLOPT_TIMEOUT] : null;
                    if ($limit === 0) {
                        $this->assertSame(1, $options[CURLOPT_LOW_SPEED_LIMIT], $name);
                        // The default stall limit of the client the constructor builds.
                        $this->assertSame(120, $options[CURLOPT_LOW_SPEED_TIME], $name . ' stall limit');
                    } else {
                        $this->assertSame(120, $limit, $name . ' has no overall timeout');
                    }
                }
            }
        }
    }

    public function testEveryRequestHasATimeout()
    {
        $client = new LoopbackClient();
        $client->initialize('guard-key');
        $client->pointAt('http://127.0.0.1:' . $this->freePort());
        $this->assertTimeouts($client, $this->sweepCalls($client, $this->dir . '/out.csv'));
    }

    public function testEveryWarmupRequestHasATimeout()
    {
        $warmup = new LoopbackWarmup();
        $warmup->loopback = 'http://127.0.0.1:' . $this->freePort();
        $this->assertTimeouts($warmup, $this->warmupCalls($warmup));
    }

    // -- result files ----------------------------------------------------------

    /**
     * @return array<string, callable>
     */
    private function downloads(ZeroBounce $c, $path)
    {
        $stream = new ZBGetFileOptions();
        $stream->stream = true;
        return array(
            'getFile' => function () use ($c, $path) { return $c->getFile('f1', $path); },
            'getFile stream' => function () use ($c, $path, $stream) { return $c->getFile('f1', $path, $stream); },
            'scoringGetFile' => function () use ($c, $path) { return $c->scoringGetFile('f1', $path); },
            'scoringGetFile stream' => function () use ($c, $path, $stream) { return $c->scoringGetFile('f1', $path, $stream); },
            'emailFinderGetFile' => function () use ($c, $path) { return $c->emailFinderGetFile('f1', $path); },
            'domainSearchGetFile' => function () use ($c, $path) { return $c->domainSearchGetFile('f1', $path); },
        );
    }

    public function testErrorBodiesAreNotSavedAsResultFiles()
    {
        $cases = array(
            // Neither HTML nor JSON: only the status says it is no file.
            array(500, 'text/plain', "Internal error\n"),
            array(404, 'text/csv', "email,status\n"),
            array(200, 'text/csv', ''),
            array(200, 'text/csv', "  \n"),
            array(200, 'text/html', '<!DOCTYPE html><html><head><title>Access denied</title></head><body>no</body></html>'),
        );
        $path = $this->dir . '/result.csv';
        foreach ($cases as $case) {
            list($status, $type, $body) = $case;
            $client = new CannedFileClient($status, $type, $body);
            $client->initialize('guard-key');
            foreach ($this->downloads($client, $path) as $name => $call) {
                $error = $this->thrown($call);
                $label = $name . ' ' . $status . ' ' . json_encode($body);
                $this->assertInstanceOf(ZBApiException::class, $error, $label);
                $this->assertSame($status, $error->getStatusCode(), $label);
                $this->assertFileDoesNotExist($path, $label);
                $this->assertSame(array(), $this->leftovers(), $label);
            }
        }
    }

    private static function isDownload($name)
    {
        return stripos($name, 'getfile') !== false;
    }

    /**
     * A text/html answer is an error by its content type alone: a JSON body
     * on the JSON calls, a CSV line on the downloads, with no HTML tag.
     */
    public function testATextHtmlContentTypeIsAnErrorOnEveryCall()
    {
        $path = $this->dir . '/result.csv';
        $answers = array(
            'json' => '{"success":true,"Credits":"5","file_id":"f1","email_batch":[],"status":"valid"}',
            'csv' => "email,status\na@example.com,valid\n",
        );
        $accepted = array();
        foreach ($answers as $kind => $body) {
            $client = new CannedFileClient(200, 'text/html; charset=UTF-8', $body);
            $client->initialize('guard-key');
            foreach ($this->sweepCalls($client, $path) as $name => $variants) {
                if (self::isDownload($name) !== ($kind === 'csv')) {
                    continue;
                }
                foreach ($variants as $call) {
                    $error = $this->thrown($call);
                    if (!$error instanceof ZBApiException) {
                        $accepted[] = $name . ' (' . $kind . ')';
                        continue;
                    }
                    $this->assertSame(200, $error->getStatusCode(), $name);
                }
            }
            $this->assertFileDoesNotExist($path, $kind);
        }
        $this->assertSame(array(), $accepted);
        foreach (array('downloadSeeds' => array(), 'downloadStatistics' => array('a@b.com')) as $name => $args) {
            $warmup = new CannedFileWarmup(200, 'text/html', $answers['csv']);
            $error = $this->thrown(function () use ($warmup, $name, $args) {
                call_user_func_array(array($warmup, $name), $args);
            });
            $this->assertInstanceOf(ZBApiException::class, $error, $name);
            $this->assertSame(200, $error->getStatusCode(), $name);
        }
    }

    /** The Warmup JSON calls: a body that parses as JSON, under text/html. */
    public function testATextHtmlContentTypeIsAnErrorOnEveryWarmupJsonCall()
    {
        $accepted = array();
        $warmup = new CannedFileWarmup(200, 'text/html; charset=UTF-8', '{"a":1}');
        foreach ($this->warmupCalls($warmup) as $name => $call) {
            if (in_array($name, array('downloadSeeds', 'downloadStatistics'), true)) {
                continue;
            }
            $error = $this->thrown($call);
            if (!$error instanceof ZBApiException) {
                $accepted[] = $name;
                continue;
            }
            $this->assertSame(200, $error->getStatusCode(), $name);
        }
        $this->assertSame(array(), $accepted);
    }

    /**
     * application/json on a body that reads as CSV: only the content type
     * says it is not a result file. Every download, streamed and not, and the
     * Warmup CSVs; the file already at the target is kept.
     */
    public function testAJsonContentTypeAloneIsAnErrorOnEveryDownload()
    {
        $csv = "email,status\na@example.com,valid\n";
        $client = new CannedFileClient(200, 'application/json', $csv);
        $client->initialize('guard-key');
        $calls = $this->downloadsOverExistingFiles($client, $this->dir);
        foreach ($calls as $name => $call) {
            $error = $this->thrown($call[0]);
            $this->assertInstanceOf(ZBApiException::class, $error, $name);
            $this->assertSame(200, $error->getStatusCode(), $name);
            $this->assertSame("keep me\n", file_get_contents($call[1]), $name);
        }
        $this->assertCount(count($calls), $this->leftovers());
        $warmup = new CannedFileWarmup(200, 'application/json; charset=utf-8', $csv);
        foreach (array('downloadSeeds' => array(), 'downloadStatistics' => array('a@b.com')) as $name => $args) {
            $error = $this->thrown(function () use ($warmup, $name, $args) {
                call_user_func_array(array($warmup, $name), $args);
            });
            $this->assertInstanceOf(ZBApiException::class, $error, $name);
            $this->assertSame(200, $error->getStatusCode(), $name);
        }
    }

    /** "success": "false" as a string, any case, is an error on every JSON call. */
    public function testASuccessOfTheStringFalseIsAnErrorOnEveryJsonCall()
    {
        $path = $this->dir . '/result.csv';
        $accepted = array();
        foreach (array('{"success":"false","file_id":"f1"}', '{"success":" False ","file_id":"f1"}') as $body) {
            $client = new CannedFileClient(200, 'application/json', $body);
            $client->initialize('guard-key');
            foreach ($this->sweepCalls($client, $path) as $name => $variants) {
                if (self::isDownload($name)) {
                    continue;
                }
                foreach ($variants as $call) {
                    $error = $this->thrown($call);
                    if (!$error instanceof ZBApiException) {
                        $accepted[] = $name . ' ' . $body;
                        continue;
                    }
                    $this->assertSame(200, $error->getStatusCode(), $name);
                }
            }
        }
        $this->assertSame(array(), $accepted);
    }

    /** The API's JSON error under a CSV content type: only the body tells it apart. */
    public function testAJsonErrorUnderACsvContentTypeIsNotSaved()
    {
        $path = $this->dir . '/result.csv';
        foreach (array('{"success":false,"message":"File cannot be found."}', "  \n{\"error\":\"File cannot be found.\"}") as $body) {
            $client = new CannedFileClient(200, 'text/csv', $body);
            $client->initialize('guard-key');
            $calls = 0;
            foreach ($this->sweepCalls($client, $path) as $name => $variants) {
                if (!self::isDownload($name)) {
                    continue;
                }
                foreach ($variants as $call) {
                    $calls++;
                    $error = $this->thrown($call);
                    $this->assertInstanceOf(ZBApiException::class, $error, $name . ' ' . $body);
                    $this->assertSame(200, $error->getStatusCode(), $name);
                    $this->assertStringContainsString('File cannot be found.', $error->getMessage(), $name);
                    $this->assertFileDoesNotExist($path, $name);
                    $this->assertSame(array(), $this->leftovers(), $name);
                }
            }
            $this->assertGreaterThanOrEqual(4, $calls);
        }
    }

    /**
     * Every download, streamed and not, each into its own file under $folder
     * that already holds "keep me".
     *
     * @return array<string, array{0: callable, 1: string}>
     */
    private function downloadsOverExistingFiles(ZeroBounce $client, $folder)
    {
        $stream = new ZBGetFileOptions();
        $stream->stream = true;
        $calls = array();
        foreach (array('getFile', 'scoringGetFile', 'emailFinderGetFile', 'domainSearchGetFile') as $name) {
            foreach (array('plain' => null, 'streamed' => $stream) as $how => $options) {
                $path = $folder . '/' . $name . '-' . $how . '.csv';
                file_put_contents($path, "keep me\n");
                $calls[$name . ' ' . $how] = array(function () use ($client, $name, $path, $options) {
                    $client->$name('f1', $path, $options);
                }, $path);
            }
        }
        return $calls;
    }

    /** A disk that fills up, or a write that cannot be flushed: no partial file, the old one kept. */
    public function testAFailedWriteKeepsTheExistingFileAndLeavesNoPartialFile()
    {
        $csv = "email,status\n" . str_repeat("a@example.com,valid\n", 100);
        $client = new CannedFileClient(200, 'text/csv', $csv);
        $client->initialize('guard-key');
        $client->openFile = function ($path) { return fopen('zbfull://' . $path, 'wb'); };
        $calls = $this->downloadsOverExistingFiles($client, $this->dir);
        foreach (array('full disk' => array(100, false), 'failed flush' => array(null, true)) as $how => $failure) {
            foreach ($calls as $name => $call) {
                FullDiskStream::$room = $failure[0];
                FullDiskStream::$failFlush = $failure[1];
                $error = $this->thrown($call[0]);
                $this->assertInstanceOf(ZBException::class, $error, $name . ' ' . $how);
                $this->assertSame('Could not write file', $error->getMessage(), $name . ' ' . $how);
                $this->assertSame("keep me\n", file_get_contents($call[1]), $name . ' ' . $how);
            }
        }
        $this->assertCount(count($calls), $this->leftovers(), 'a partial file was left behind');

        FullDiskStream::$room = null;
        FullDiskStream::$failFlush = false;
        foreach ($calls as $name => $call) {
            $call[0]();
            $this->assertSame($csv, file_get_contents($call[1]), $name . ' did not replace the old file');
            if (DIRECTORY_SEPARATOR === '/') {
                clearstatcache();
                // The usual mode for a new file, not tempnam()'s 0600.
                $this->assertSame(0666 & ~umask(), fileperms($call[1]) & 0777, $name);
            }
        }
        $this->assertCount(count($calls), $this->leftovers());
    }

    /** A folder the download cannot write to: an error, and the file already there is kept. */
    public function testAReadOnlyFolderKeepsTheExistingFile()
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes into a read-only folder');
        }
        $client = new CannedFileClient(200, 'text/csv', "email\na@example.com\n");
        $client->initialize('guard-key');
        $folder = $this->dir . '/read-only';
        mkdir($folder);
        $calls = $this->downloadsOverExistingFiles($client, $folder);
        chmod($folder, 0555);
        try {
            foreach ($calls as $name => $call) {
                $error = $this->thrown($call[0]);
                $this->assertInstanceOf(ZBException::class, $error, $name);
                $this->assertSame('Could not write file', $error->getMessage(), $name);
                $this->assertSame("keep me\n", file_get_contents($call[1]), $name);
            }
            $this->assertCount(count($calls), array_diff(scandir($folder), array('.', '..')));
        } finally {
            chmod($folder, 0755);
            foreach ($calls as $call) {
                @unlink($call[1]);
            }
            @rmdir($folder);
        }
    }

    public function testAFailedWriteFailsAStreamedDownloadAndLeavesNothing()
    {
        $root = $this->serve('<?php
header("Content-Type: text/csv");
$rows = isset($_GET["file_id"]) && $_GET["file_id"] === "big" ? 20000 : 20;
echo "email,status\n", str_repeat("someone@example.com,valid\n", $rows);
return true;
');
        $client = new LoopbackClient();
        $client->initialize('guard-key');
        $client->pointAt($root);
        $client->openFile = function ($path) { return fopen('zbfull://' . $path, 'wb'); };
        $stream = new ZBGetFileOptions();
        $stream->stream = true;
        $path = $this->dir . '/result.csv';

        // Big: the disk fills while the body streams in. Small: the body fits
        // in the sniff window and is written once the transfer ends.
        foreach (array('big' => 100000, 'small' => 100) as $fileId => $room) {
            FullDiskStream::$room = $room;
            $error = $this->thrown(function () use ($client, $fileId, $path, $stream) {
                $client->getFile($fileId, $path, $stream);
            });
            $this->assertSame('Could not write file', $error->getMessage(), $fileId);
            $this->assertFileDoesNotExist($path, $fileId);
            $this->assertSame(array(), $this->leftovers(), $fileId . ' left a partial file');
        }

        FullDiskStream::$room = null;
        FullDiskStream::$failFlush = true;
        $this->thrown(function () use ($client, $path, $stream) { $client->getFile('small', $path, $stream); });
        $this->assertSame(array(), $this->leftovers(), 'a failed flush left a file');

        FullDiskStream::$failFlush = false;
        $client->getFile('big', $path, $stream);
        $this->assertSame(13 + 20000 * 26, filesize($path), 'with room on the disk the download completes');
    }

    // -- Warmup CSV --------------------------------------------------------------

    public function testAnHtmlPageIsNotAWarmupCsv()
    {
        foreach (array('text/html; charset=UTF-8', 'text/csv') as $type) {
            $warmup = new CannedFileWarmup(200, $type, '<!DOCTYPE html><html><head><title>Access denied</title></head></html>');
            foreach (array('downloadSeeds' => array(), 'downloadStatistics' => array('a@b.com')) as $name => $args) {
                $error = $this->thrown(function () use ($warmup, $name, $args) {
                    call_user_func_array(array($warmup, $name), $args);
                });
                $this->assertInstanceOf(ZBApiException::class, $error, $name);
                $this->assertSame(200, $error->getStatusCode(), $name);
                $this->assertStringContainsString('HTML', $error->getMessage(), $name);
            }
        }
        $csv = new CannedFileWarmup(200, 'text/csv', "seed,provider\na@b.com,gmail\n");
        $this->assertSame("seed,provider\na@b.com,gmail\n", $csv->downloadSeeds());
    }

    // -- Warmup transport failures -------------------------------------------------

    /**
     * A refused connection and a body cut short after its 200 header are
     * transport failures (status 0) on every Warmup method, never a success
     * with an empty or partial payload.
     */
    public function testEveryWarmupRequestFailsOnATransportFailure()
    {
        $cutShort = $this->serve('<?php
$csv = strpos($_SERVER["REQUEST_URI"], "/download") !== false;
header("Content-Type: " . ($csv ? "text/csv" : "application/json"));
header("Content-Length: 1000");
echo $csv ? "seed,provider\na@b.com,gmail\n" : "{\"a\":1,\"b\":\"cccccccc\"}";
return true;
');
        $roots = array('refused' => 'http://127.0.0.1:' . $this->freePort(), 'cut short' => $cutShort);
        foreach ($roots as $kind => $root) {
            $warmup = new LoopbackWarmup();
            $warmup->loopback = $root;
            foreach ($this->warmupCalls($warmup) as $name => $call) {
                $error = $this->thrown($call);
                $label = $name . ' (' . $kind . ')';
                $this->assertInstanceOf(ZBApiException::class, $error, $label);
                $this->assertSame(0, $error->getStatusCode(), $label);
                $this->assertNotSame('', $error->getMessage(), $label);
                $this->assertStringNotContainsString('guard-key', $error->getMessage(), $label);
            }
        }
    }

    // -- the key in a response body -----------------------------------------------

    /**
     * Bodies that quote the key: an API message naming it, a proxy echoing the
     * request URL, an HTML title, on a 2xx and on a 4xx. JSON bodies quote it
     * JSON-escaped, with "/" escaped (as PHP writes it) and not (as most
     * servers do).
     *
     * @return array<string, array{0: int, 1: string, 2: string}>
     */
    private function keyEchoBodies($key)
    {
        $bodies = array(
            '200 HTML title' => array(200, 'text/html', '<html><head><title>Denied ' . $key . '</title></head></html>'),
            '400 text quoting the URL' => array(400, 'text/plain', 'Bad request: https://x.example/v2/x?api_key=' . rawurlencode($key)),
            '400 text quoting the raw key' => array(400, 'text/plain', 'Bad request for ' . $key),
        );
        $escaped = array(
            'escaped' => substr(json_encode($key), 1, -1),
            'escaped, plain /' => substr(json_encode($key, JSON_UNESCAPED_SLASHES), 1, -1),
        );
        foreach ($escaped as $how => $form) {
            $bodies['200 {"error"} ' . $how] = array(200, 'application/json', '{"error":"Invalid API key ' . $form . '"}');
            $bodies['200 {"success":false} ' . $how] = array(200, 'application/json', '{"success":false,"message":"Invalid API key ' . $form . '"}');
            $bodies['200 batch "all" error ' . $how] = array(200, 'application/json',
                '{"email_batch":[],"errors":[{"email_address":"all","error":"Invalid API key ' . $form . '"}]}');
            $bodies['400 {"error"} ' . $how] = array(400, 'application/json', '{"error":"Invalid API key ' . $form . '"}');
        }
        return $bodies;
    }

    /**
     * A secret in every form a body can quote it: raw, URL-encoded and
     * JSON-escaped.
     *
     * @return string[]
     */
    private static function echoForms($secret)
    {
        return array_values(array_unique(array(
            $secret, rawurlencode($secret), urlencode($secret),
            substr(json_encode($secret), 1, -1), substr(json_encode($secret, JSON_UNESCAPED_SLASHES), 1, -1),
        )));
    }

    /**
     * Runs $call and checks that no error it throws carries the key, in its
     * raw or URL-encoded form, in its message or any previous error's.
     *
     * @return bool whether it threw
     */
    private function assertKeyNotEchoed($key, $label, callable $call)
    {
        try {
            $this->quietly($call);
        } catch (\Exception $e) {
            $text = '';
            for ($error = $e; $error !== null; $error = $error->getPrevious()) {
                $text .= get_class($error) . ': ' . $error->getMessage() . "\n";
            }
            foreach (self::echoForms($key) as $form) {
                $this->assertStringNotContainsString($form, $text, $label);
            }
            return true;
        }
        return false;
    }

    public function testNoErrorFromAnyRequestCarriesAKeyTheBodyQuotes()
    {
        // Each character an encoding changes: '"', '/', '+' and '%'.
        $key = 'echo"key/4+2%25';
        $out = $this->dir . '/result.csv';
        foreach ($this->keyEchoBodies($key) as $label => $answer) {
            list($status, $type, $body) = $answer;
            $client = new CannedFileClient($status, $type, $body);
            $client->initialize($key);
            $calls = $this->sweepCalls($client, $out);
            $this->assertSame(array(), array_values(array_diff($this->requestMethodNames(), array_keys($calls))));
            foreach ($calls as $name => $variants) {
                foreach ($variants as $call) {
                    $threw = $this->assertKeyNotEchoed($key, $name . ': ' . $label, $call);
                    // Each of these bodies is an error on every call; the batch
                    // "all" error is one on validateBatch.
                    if (strpos($label, 'batch "all"') === false || $name === 'validateBatch') {
                        $this->assertTrue($threw, $name . ' returned for ' . $label);
                    }
                }
            }
            $this->assertSame(array(), $this->leftovers(), $label . ' left a file');
        }
    }

    public function testNoWarmupErrorCarriesAKeyTheBodyQuotes()
    {
        // Each character an encoding changes: '"', '/', '+' and '%'.
        $key = 'echo"key/4+2%25';
        $bodies = $this->keyEchoBodies($key);
        // Not JSON, on a 200: each JSON call builds its error from this text.
        $bodies['200 text quoting the key'] = array(200, 'text/plain', 'Denied for ' . $key . ' at https://x.example/?k=' . rawurlencode($key));
        foreach ($bodies as $label => $answer) {
            list($status, $type, $body) = $answer;
            $warmup = new CannedFileWarmup($status, $type, $body, $key);
            foreach ($this->warmupCalls($warmup) as $name => $call) {
                $threw = $this->assertKeyNotEchoed($key, 'Warmup ' . $name . ': ' . $label, $call);
                if ($status >= 400) {
                    $this->assertTrue($threw, 'Warmup ' . $name . ' returned for ' . $label);
                }
            }
        }
    }

    // -- the key in stack-trace arguments ------------------------------------------

    /**
     * Every form of every secret found anywhere in $e or its previous errors:
     * message, string form, trace (var_export), print_r, var_export and
     * serialize where the error can be serialized.
     *
     * @param string[] $secrets
     * @return string[] where each was found
     */
    private function secretsIn(\Throwable $e, array $secrets)
    {
        $found = array();
        for ($depth = 0, $error = $e; $error !== null; $depth++, $error = $error->getPrevious()) {
            $views = array(
                'message' => $error->getMessage(),
                'string' => (string)$error,
                'trace' => var_export($error->getTrace(), true),
                'print_r' => print_r($error, true),
                'var_export' => var_export($error, true),
            );
            try {
                $views['serialize'] = serialize($error);
            } catch (\Throwable $notSerializable) {
                // A closure or a SensitiveParameterValue among the arguments.
            }
            foreach ($views as $view => $text) {
                foreach ($secrets as $secret) {
                    foreach (array($secret, rawurlencode($secret), urlencode($secret), substr(json_encode($secret), 1, -1)) as $form) {
                        if (strpos($text, $form) !== false) {
                            $found[] = get_class($error) . ' #' . $depth . ' ' . $view . ': ' . $form;
                        }
                    }
                }
            }
        }
        return array_values(array_unique($found));
    }

    /**
     * The secrets are read from self::$traceSecrets, not passed in, so no
     * frame of the test itself holds them as an argument.
     */
    private function assertNoSecretInError($label, callable $call)
    {
        try {
            $this->quietly($call);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(ZBException::class, $e, $label . ': ' . get_class($e) . ': ' . $e->getMessage());
            $this->assertSame(array(), $this->secretsIn($e, self::$traceSecrets), $label);
            return;
        }
        $this->fail($label . ' did not throw');
    }

    /**
     * With zend.exception_ignore_args off (the php.ini-development and
     * official Docker image default, and the only behaviour on PHP 7.0-7.3)
     * a trace keeps every frame's arguments: the request URL with api_key, a
     * parameter array, the API-Key header, the SMTP password. No error any
     * request method throws may carry them, on an error status or a
     * transport failure.
     */
    public function testNoErrorCarriesASecretInItsStackTrace()
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $key = 'trace+key/0123456789abcdef';
            $password = 'smtp+pass/0123456789';
            self::$traceSecrets = array($key, $password);
            self::$smtpPassword = $password;
            $out = $this->dir . '/result.csv';
            $cutShort = $this->serve('<?php
header("Content-Type: application/json");
header("Content-Length: 1000");
echo "{\"a\":1,\"b\":\"cccccccc\"}";
return true;
');
            $statuses = array(
                '400 text quoting the URL' => array(400, 'text/plain', 'Bad request: https://x.example/v2/x?api_key=' . rawurlencode($key)),
                '500 {"error"}' => array(500, 'application/json', '{"error":"Server error"}'),
                '200 {"error"}' => array(200, 'application/json', '{"error":"Invalid API key"}'),
            );
            $clients = array();
            foreach ($statuses as $label => $answer) {
                $clients[$label] = new CannedFileClient($answer[0], $answer[1], $answer[2]);
            }
            foreach (array('refused' => 'http://127.0.0.1:' . $this->freePort(), 'cut short' => $cutShort) as $label => $root) {
                $clients[$label] = new LoopbackClient();
                $clients[$label . ' root'] = $root;
            }
            foreach ($clients as $label => $client) {
                if (!$client instanceof ZeroBounce) {
                    continue;
                }
                $client->initialize($key);
                if ($client instanceof LoopbackClient) {
                    $client->pointAt($clients[$label . ' root']);
                }
                $calls = $this->sweepCalls($client, $out);
                $this->assertSame(array(), array_values(array_diff($this->requestMethodNames(), array_keys($calls))));
                foreach ($calls as $name => $variants) {
                    foreach ($variants as $i => $call) {
                        $this->assertNoSecretInError($name . '#' . $i . ' (' . $label . ')', $call);
                    }
                }
            }

            $warmups = array();
            foreach (array(400, 429, 500) as $status) {
                $warmups[$status . ' text'] = new CannedFileWarmup($status, 'text/plain', 'Bad request', $key);
            }
            foreach (array('refused' => 'http://127.0.0.1:' . $this->freePort(), 'cut short' => $cutShort) as $label => $root) {
                $warmups[$label] = new LoopbackWarmup($key);
                $warmups[$label]->loopback = $root;
            }
            foreach ($warmups as $label => $warmup) {
                $calls = $this->warmupCalls($warmup);
                $this->assertSame(array(), array_values(array_diff($this->warmupMethodNames(), array_keys($calls))));
                foreach ($calls as $name => $call) {
                    $this->assertNoSecretInError('Warmup ' . $name . ' (' . $label . ')', $call);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '0' : $previous);
            self::$traceSecrets = array();
            self::$smtpPassword = 'p';
        }
    }

    // -- initialize() ------------------------------------------------------------

    private function state(ZeroBounce $client)
    {
        $state = array();
        foreach ((new ReflectionClass(ZeroBounce::class))->getProperties() as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            if (\PHP_VERSION_ID < 80100) {
                $prop->setAccessible(true);
            }
            $value = $prop->getValue($client);
            $state[$prop->getName()] = $value instanceof ZBSecret ? 'secret:' . $value->value() : $value;
        }
        return $state;
    }

    public function testAFailedInitializeChangesNothing()
    {
        $client = ZeroBounce::create();
        $client->initialize('key-before', 'https://api.before.test/v2', null, null, 'https://bulk.before.test');
        $before = $this->state($client);
        $this->assertSame('secret:key-before', $before['apiKey']);
        $attempts = array(
            'baseUrl' => array('key-after', 'http://api.after.test/v2'),
            'listEvaluatorBaseUrl' => array('key-after', 'https://api.after.test/v2', 'http://le.after.test'),
            'inboxPlacementBaseUrl' => array('key-after', 'https://api.after.test/v2', 'https://le.after.test', 'http://ipt.after.test'),
            'bulkBaseUrl' => array('key-after', 'https://api.after.test/v2', 'https://le.after.test', 'https://ipt.after.test', 'http://bulk.after.test'),
            'apiKey' => array(" key-after\n", 'https://api.after.test/v2'),
        );
        foreach ($attempts as $name => $args) {
            $this->thrown(function () use ($client, $args) { call_user_func_array(array($client, 'initialize'), $args); });
            $this->assertSame($before, $this->state($client), 'a bad ' . $name . ' changed the client');
        }
        $client->initialize('key-after', 'https://api.after.test/v2', null, null, 'https://bulk.after.test');
        $this->assertNotSame($before, $this->state($client));
    }

    // -- Inbox Placement delete --------------------------------------------------

    public function testAnEmptySuccessfulInboxPlacementDeleteIsASuccess()
    {
        foreach (array(200, 202, 204) as $status) {
            $client = new CannedFileClient($status, 'text/plain', '');
            $client->initialize('guard-key');
            $response = $client->deleteInboxPlacementTest(7);
            $this->assertInstanceOf(ZBRawJsonResponse::class, $response);
            $this->assertSame(array(), $response->data, (string)$status);
        }
        $json = new CannedFileClient(200, 'application/json', '{"deleted":true}');
        $json->initialize('guard-key');
        $this->assertSame(array('deleted' => true), $json->deleteInboxPlacementTest(7)->data);

        // Errors, and an empty body on the other calls, are unchanged.
        $missing = new CannedFileClient(404, 'text/plain', '');
        $missing->initialize('guard-key');
        $this->assertSame(404, $this->thrown(function () use ($missing) { $missing->deleteInboxPlacementTest(7); })->getStatusCode());
        $empty = new CannedFileClient(204, 'text/plain', '');
        $empty->initialize('guard-key');
        $this->assertInstanceOf(ZBApiException::class, $this->thrown(function () use ($empty) { $empty->getInboxPlacementTest(7); }));
    }

    // -- E7: the shared strict JSON corpus -------------------------------------------

    /**
     * (verdict, body) pairs. test/fixtures/strict-json-corpus.tsv is a
     * byte-identical copy of sdk-docs/skills/zerobounce-sdk/references/
     * strict-json-corpus.tsv (zerobounce/sdk-docs); refresh it from there.
     * Each line's second field is a JSON-encoded string holding the body.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function strictJsonCorpus()
    {
        $rows = array();
        foreach (file(__DIR__ . '/fixtures/strict-json-corpus.tsv', FILE_IGNORE_NEW_LINES) as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            list($verdict, $encoded) = explode("\t", $line, 2);
            $rows[] = array($verdict, json_decode($encoded));
        }
        return $rows;
    }

    /** Downloads are not JSON calls: any JSON body is an error there. */
    private static $downloads = array(
        'getFile', 'scoringGetFile', 'emailFinderGetFile', 'domainSearchGetFile', 'downloadSeeds', 'downloadStatistics',
    );

    /**
     * Every JSON call, answered with $body under a JSON content type on a 200.
     *
     * @return array<string, callable>
     */
    private function corpusCalls($body)
    {
        $client = new CannedFileClient(200, 'application/json', $body);
        $client->initialize('corpus-key');
        $calls = array();
        foreach ($this->sweepCalls($client, $this->dir . '/result.csv') as $name => $variants) {
            foreach ($variants as $i => $call) {
                $calls[$name . '#' . $i] = $call;
            }
        }
        foreach ($this->warmupCalls(new CannedFileWarmup(200, 'application/json', $body)) as $name => $call) {
            $calls['Warmup ' . $name . '#0'] = $call;
        }
        foreach (array_keys($calls) as $label) {
            $name = preg_replace('/^Warmup | ?#\d+$/', '', $label);
            if (in_array($name, self::$downloads, true)) {
                unset($calls[$label]);
            }
        }
        return $calls;
    }

    /**
     * An empty 200 (zero bytes) is the documented empty success of the
     * Warmup calls and of deleteInboxPlacementTest (check E8); a blank body
     * is never empty.
     */
    private static function emptySuccess($label, $body)
    {
        return $body === ''
            && (strpos($label, 'Warmup ') === 0 || strpos($label, 'deleteInboxPlacementTest#') === 0);
    }

    public function testTheCorpusHasBothVerdicts()
    {
        $verdicts = array_map(function ($row) { return $row[0]; }, self::strictJsonCorpus());
        $this->assertSame(array('ACCEPT', 'REJECT'), array_values(array_unique(array_merge(array('ACCEPT'), $verdicts))));
        $this->assertGreaterThan(30, count(array_keys($verdicts, 'REJECT', true)));
    }

    public function testEveryJsonCallRejectsEveryRejectBody()
    {
        $wrong = array();
        foreach (self::strictJsonCorpus() as $row) {
            list($verdict, $body) = $row;
            if ($verdict !== 'REJECT') {
                continue;
            }
            foreach ($this->corpusCalls($body) as $label => $call) {
                if (self::emptySuccess($label, $body)) {
                    continue;
                }
                try {
                    $this->quietly($call);
                    $wrong[] = $label . ' accepted ' . json_encode($body);
                } catch (ZBApiException $e) {
                    if ($e->getStatusCode() !== 200) {
                        $wrong[] = $label . ' status ' . $e->getStatusCode() . ' for ' . json_encode($body);
                    }
                } catch (\Throwable $e) {
                    $wrong[] = $label . ' ' . get_class($e) . ' for ' . json_encode($body);
                }
            }
        }
        $this->assertSame(array(), $wrong);
    }

    /**
     * An ACCEPT body is parsed: every call either returns it as a result or,
     * when it is the wrong shape for the call ("0" is not a validate result),
     * raises a ZBApiException with status 200 that says so. Never "not valid
     * JSON", another status, or a raw PHP error.
     */
    public function testNoJsonCallRejectsAnAcceptBodyAsInvalid()
    {
        $wrong = array();
        foreach (self::strictJsonCorpus() as $row) {
            list($verdict, $body) = $row;
            if ($verdict !== 'ACCEPT') {
                continue;
            }
            json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $wrong[] = 'json_decode refused ' . json_encode($body);
            }
            foreach ($this->corpusCalls($body) as $label => $call) {
                try {
                    $this->quietly($call);
                } catch (ZBApiException $e) {
                    if ($e->getStatusCode() !== 200 || strpos($e->getMessage(), 'unexpected shape') === false) {
                        $wrong[] = $label . ' ' . $e->getStatusCode() . ' ' . $e->getMessage() . ' for ' . json_encode($body);
                    }
                } catch (\Throwable $e) {
                    $wrong[] = $label . ' ' . get_class($e) . ' for ' . json_encode($body);
                }
            }
        }
        $this->assertSame(array(), $wrong);
    }

    // -- S4/S7: redaction closed under encoding --------------------------------------

    /** A secret with every character an encoding changes. */
    const FUZZ_SECRET = "k y\"/\\+%&=<>'z";

    /**
     * The test's own decoder, independent of the SDK's: every view of $text
     * after up to three decodings (URL %XX, URL +, JSON incl. \/ and \uXXXX).
     *
     * @return string[]
     */
    private static function fuzzViews($text)
    {
        $decoders = array(
            'rawurldecode',
            'urldecode',
            function ($t) { return strtr($t, '+', ' '); },
            function ($t) {
                $simple = array('"' => '"', '\\' => '\\', '/' => '/', 'b' => "\x08", 'f' => "\f", 'n' => "\n", 'r' => "\r", 't' => "\t");
                return preg_replace_callback('#\\\\(u[0-9a-fA-F]{4}|["\\\\/bfnrt])#', function ($m) use ($simple) {
                    if ($m[1][0] !== 'u') {
                        return $simple[$m[1]];
                    }
                    $decoded = json_decode('"\\' . $m[1] . '"');
                    return is_string($decoded) ? $decoded : $m[0];
                }, $t);
            },
        );
        $views = array($text);
        $frontier = array($text);
        for ($layer = 0; $layer < 3; $layer++) {
            $next = array();
            foreach ($frontier as $view) {
                foreach ($decoders as $decode) {
                    $candidate = (string)call_user_func($decode, $view);
                    if (!in_array($candidate, $views, true) && !in_array($candidate, $next, true)) {
                        $next[] = $candidate;
                    }
                }
            }
            $views = array_merge($views, $next);
            $frontier = $next;
        }
        return $views;
    }

    /**
     * Every sequence of up to three encodings of $secret, and random ones
     * (fixed seed).
     *
     * @return array<string, string> label => form
     */
    private static function fuzzForms($secret)
    {
        $encoders = array(
            'URL %20' => 'rawurlencode',
            'URL +' => 'urlencode',
            'JSON' => function ($t) { return substr(json_encode($t, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1); },
            'JSON \/' => function ($t) { return substr(json_encode($t, JSON_UNESCAPED_UNICODE), 1, -1); },
        );
        $names = array_keys($encoders);
        $sequences = array(array());
        $all = array();
        for ($depth = 1; $depth <= 3; $depth++) {
            $longer = array();
            foreach ($sequences as $sequence) {
                foreach ($names as $name) {
                    $longer[] = array_merge($sequence, array($name));
                }
            }
            $all = array_merge($all, $longer);
            $sequences = $longer;
        }
        mt_srand(8259);
        for ($i = 0; $i < 60; $i++) {
            $sequence = array();
            for ($n = mt_rand(1, 3); $n > 0; $n--) {
                $sequence[] = $names[mt_rand(0, 3)];
            }
            $all[] = $sequence;
        }
        $forms = array();
        foreach ($all as $i => $sequence) {
            $form = $secret;
            foreach ($sequence as $name) {
                $form = call_user_func($encoders[$name], $form);
            }
            $forms[$i . ': ' . implode(' > ', $sequence)] = $form;
        }
        return $forms;
    }

    /**
     * @return array{0: int, 1: string, 2: string} status, content type, body
     */
    private static function fuzzBody($form, $shape)
    {
        $shapes = array(
            array(400, 'text/plain', 'rejected: ' . $form),
            array(200, 'application/json', '{"error":"Invalid API key ' . $form . '"}'),
            array(200, 'application/json', '{"success":false,"message":"rejected ' . $form . '"}'),
            array(400, 'application/json', '{"message":"bad request ' . $form . '"}'),
            array(200, 'text/html', '<html><title>denied ' . $form . '</title></html>'),
            array(200, 'application/json', '{"email_batch":[],"errors":[{"email_address":"all","error":"Invalid API key ' . $form . '"}]}'),
        );
        return $shapes[$shape % count($shapes)];
    }

    /**
     * Where $secret survives in a decoded view of $e: message, string form
     * and stack-trace arguments, down the previous chain.
     *
     * @return string[]
     */
    private function fuzzLeaks(\Throwable $e, $secret)
    {
        $found = array();
        for ($error = $e; $error !== null; $error = $error->getPrevious()) {
            $texts = array(
                'message' => $error->getMessage(),
                'string' => (string)$error,
            );
            // Each string in the trace's arguments as it is: var_export() and
            // print_r() add their own escaping, which no decoder undoes.
            foreach (self::stringsIn($error->getTrace()) as $i => $text) {
                $texts['trace arg ' . $i] = $text;
            }
            foreach ($texts as $where => $text) {
                foreach (self::fuzzViews($text) as $view) {
                    if (strpos($view, $secret) !== false) {
                        $found[] = get_class($error) . ' ' . $where;
                        break;
                    }
                }
            }
        }
        return $found;
    }

    /**
     * Every string in $value: nested arrays and the properties of objects
     * (private and protected ones too), as print_r() and var_export() show.
     *
     * @return string[]
     */
    private static function stringsIn($value, $depth = 0)
    {
        if (is_string($value)) {
            return array($value);
        }
        if ($depth > 12 || $value instanceof \Closure) {
            return array();
        }
        if (is_object($value)) {
            $value = (array)$value;
        }
        $strings = array();
        if (is_array($value)) {
            foreach ($value as $item) {
                $strings = array_merge($strings, self::stringsIn($item, $depth + 1));
            }
        }
        return $strings;
    }

    /**
     * Each form goes to eight calls drawn at random (fixed seed), so every
     * call meets many forms without sending forms x calls requests.
     *
     * @param callable $build function (status, type, body): array<string, callable>
     * @return string[]
     */
    private function fuzzSweep($secret, callable $build)
    {
        $leaks = array();
        $shape = 0;
        foreach (self::fuzzForms($secret) as $label => $form) {
            list($status, $type, $body) = self::fuzzBody($form, $shape++);
            $calls = call_user_func($build, $status, $type, $body);
            mt_srand(crc32($label));
            $names = array_keys($calls);
            $chosen = array_rand($names, min(8, count($names)));
            foreach ((array)$chosen as $index) {
                $name = $names[$index];
                try {
                    $this->quietly($calls[$name]);
                } catch (\Throwable $e) {
                    foreach ($this->fuzzLeaks($e, $secret) as $where) {
                        $leaks[] = $name . ' [' . $label . '] ' . $status . ': ' . $where;
                    }
                }
            }
        }
        return $leaks;
    }

    public function testTheApiKeyNeverSurvivesAnyEncoding()
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $out = $this->dir . '/result.csv';
            $leaks = $this->fuzzSweep(self::FUZZ_SECRET, function ($status, $type, $body) use ($out) {
                $client = new CannedFileClient($status, $type, $body);
                $client->initialize(self::FUZZ_SECRET);
                $calls = array();
                foreach ($this->sweepCalls($client, $out) as $name => $variants) {
                    foreach ($variants as $i => $call) {
                        $calls[$name . '#' . $i] = $call;
                    }
                }
                // validateBatch, whose JSON request body a server can quote back.
                $calls['validateBatch#again'] = $calls['validateBatch#0'];
                return $calls;
            });
            $this->assertSame(array(), $leaks);
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '0' : $previous);
        }
    }

    public function testTheWarmupKeyAndSmtpCredentialsNeverSurviveAnyEncoding()
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $leaks = $this->fuzzSweep(self::FUZZ_SECRET, function ($status, $type, $body) {
                $warmup = new CannedFileWarmup($status, $type, $body, self::FUZZ_SECRET);
                return $this->warmupCalls($warmup);
            });
            $this->assertSame(array(), $leaks);
            foreach (array('password', 'username') as $which) {
                $leaks = $this->fuzzSweep(self::FUZZ_SECRET, function ($status, $type, $body) use ($which) {
                    $warmup = new CannedFileWarmup($status, $type, $body);
                    return array($which => function () use ($warmup, $which) {
                        $secret = self::FUZZ_SECRET;
                        $which === 'password'
                            ? $warmup->testSmtpConfiguration('a@b.com', 'u', $secret, 'smtp.example.com', 587)
                            : $warmup->testSmtpConfiguration('a@b.com', $secret, 'p', 'smtp.example.com', 587);
                    });
                });
                $this->assertSame(array(), $leaks, $which);
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '0' : $previous);
        }
    }

    /**
     * The audit's case: PHP sends the key JSON-escaped with "\/"; a server
     * quoting that JSON request body without escaping "/" writes it twice
     * escaped, mixed, which no generated spelling matches. It must not stay
     * in the arguments of any frame of the validateBatch error (readJsonBody
     * held the whole body).
     */
    public function testValidateBatchKeepsAMixedEscapedKeyOutOfItsTrace()
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $once = substr(json_encode(self::FUZZ_SECRET), 1, -1);
            $mixed = substr(json_encode($once, JSON_UNESCAPED_SLASHES), 1, -1);
            foreach (array(
                '{"email_batch":[],"errors":[{"email_address":"all","error":"Invalid API key ' . $mixed . '"}]}',
                '{"success":false,"message":"bad body {\\"api_key\\":\\"' . $mixed . '\\"}"}',
                '{"error":"echo {\\"api_key\\":\\"' . $mixed . '\\"}"',
            ) as $body) {
                $client = new CannedFileClient(200, 'application/json', $body);
                $client->initialize(self::FUZZ_SECRET);
                $e = $this->thrown(function () use ($client) { $client->validateBatch(array('a@example.com')); });
                $this->assertSame(array(), $this->fuzzLeaks($e, self::FUZZ_SECRET), $body);
                $this->assertNotContains($mixed, self::stringsIn($e->getTrace()), $body);
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '0' : $previous);
        }
    }

    public function testAMessageWithoutASecretIsKept()
    {
        $this->assertSame('Invalid email a+b%20c\/d', ZBSecret::redact('Invalid email a+b%20c\/d', array(self::FUZZ_SECRET)));
    }

    // -- E7: JSON of the wrong shape for the call --------------------------------------

    /** The calls whose documented answer is a JSON array (listS3Files: or an object with "files"). */
    private static $arrayCalls = array('listFilters');
    private static $eitherCalls = array('listS3Files');

    /**
     * Every typed ZeroBounce call (downloads aside) answered with each body
     * $bodiesFor gives it; what is not a ZBApiException with status 200.
     * The Warmup client returns the API's JSON as is, so only its parsing is
     * checked, by the corpus test.
     *
     * @return string[]
     */
    private function wrongShapes(callable $bodiesFor)
    {
        $wrong = array();
        $client = new CannedFileClient(200, 'application/json', '');
        $client->initialize('shape-key');
        foreach ($this->sweepCalls($client, $this->dir . '/result.csv') as $name => $variants) {
            if (in_array($name, self::$downloads, true)) {
                continue;
            }
            foreach ($bodiesFor($name) as $body) {
                $client->answer['body'] = $body;
                foreach ($variants as $i => $call) {
                    try {
                        $this->quietly($call);
                        $wrong[] = "$name#$i accepted $body";
                    } catch (ZBApiException $e) {
                        if ($e->getStatusCode() !== 200) {
                            $wrong[] = "$name#$i status " . $e->getStatusCode() . " for $body";
                        }
                    } catch (\Throwable $e) {
                        $wrong[] = "$name#$i " . get_class($e) . " for $body";
                    }
                }
            }
        }
        return $wrong;
    }

    public function testTheShapeSweepNamesRealCalls()
    {
        $client = new CannedFileClient(200, 'application/json', '');
        $names = array_keys($this->sweepCalls($client, $this->dir . '/result.csv'));
        foreach (array_merge(self::$arrayCalls, self::$eitherCalls) as $name) {
            $this->assertContains($name, $names);
        }
        $this->assertGreaterThan(50, count($names));
    }

    public function testAScalarOrNullIsAnErrorOnEveryCall()
    {
        $this->assertSame(array(), $this->wrongShapes(function () {
            return array('0', '"x"', 'null', 'true', '1.5');
        }));
    }

    public function testAnArrayWhereAnObjectIsExpectedIsAnError()
    {
        $this->assertSame(array(), $this->wrongShapes(function ($name) {
            if (in_array($name, self::$arrayCalls, true) || in_array($name, self::$eitherCalls, true)) {
                return array();
            }
            // Empty, one string, and positional arrays holding a call's own field values in order.
            return array('[]', '["x"]', '[{"status":"valid"}]', ' [1]', '["a@example.com",10]', '[true,"3"]',
                '["a@example.com","valid"]', '["2375323"]', '[2375323]');
        }));
    }

    public function testAnObjectWhereAnArrayIsExpectedIsAnError()
    {
        $this->assertSame(array(), $this->wrongShapes(function ($name) {
            if (in_array($name, self::$arrayCalls, true)) {
                return array('{}', '{"rule":"block"}');
            }
            if (in_array($name, self::$eitherCalls, true)) {
                return array('{}', '{"success":true}', '{"success":true,"files":5}');
            }
            return array();
        }));
    }

    public function testAFieldOfTheWrongShapeIsAnError()
    {
        $bodies = array(
            'validateBatch' => array('{"email_batch":5}', '{"email_batch":["x"]}', '{"errors":[1]}'),
        );
        $this->assertSame(array(), $this->wrongShapes(function ($name) use ($bodies) {
            return isset($bodies[$name]) ? $bodies[$name] : array();
        }));
    }

    public function testTheDocumentedShapesStillParse()
    {
        $client = new CannedFileClient(200, 'application/json', '[]');
        $client->initialize('shape-key');
        $this->assertSame(array(), $client->listFilters());
        $this->assertSame(array(), $client->listFilters(true));
        $this->assertSame(array(), $client->listS3Files());
        $client->answer['body'] = '[{"rule":"block","target":"email","value":"a@example.com"}]';
        $this->assertSame('block', $client->listFilters()[0]['rule']);
        $client->answer['body'] = '{"success":true,"files":[]}';
        $this->assertSame(array('success' => true, 'files' => array()), $client->listS3Files());
        $client->answer['body'] = '{"Credits":"7"}';
        $this->assertSame('7', (string)$client->getCredits()->credits);
    }

    // -- E7: a body nested deeper than the parser allows -------------------------------

    public function testADeeplyNestedBodyIsAnErrorOnEveryCall()
    {
        $wrong = array();
        $bodies = array(
            str_repeat('[', 200000),
            str_repeat('[', 100000) . str_repeat(']', 100000),
            str_repeat('{"a":', 100000) . '1' . str_repeat('}', 100000),
            // Past json_decode's default depth (512), within what its parser could read.
            str_repeat('{"a":', 1000) . '1' . str_repeat('}', 1000),
        );
        foreach ($bodies as $body) {
            $client = new CannedFileClient(200, 'application/json', $body);
            $client->initialize('depth-key');
            $calls = array();
            foreach ($this->sweepCalls($client, $this->dir . '/result.csv') as $name => $variants) {
                foreach ($variants as $i => $call) {
                    $calls["$name#$i"] = $call;
                }
            }
            foreach ($this->warmupCalls(new CannedFileWarmup(200, 'application/json', $body)) as $name => $call) {
                $calls["Warmup $name"] = $call;
            }
            foreach ($calls as $label => $call) {
                try {
                    $this->quietly($call);
                    $wrong[] = "$label accepted " . strlen($body);
                } catch (ZBApiException $e) {
                    if ($e->getStatusCode() !== 200) {
                        $wrong[] = "$label status " . $e->getStatusCode();
                    }
                } catch (\Throwable $e) {
                    $wrong[] = "$label " . get_class($e);
                }
            }
        }
        $this->assertSame(array(), $wrong);
        $this->assertSame(array(), $this->leftovers());
    }

    // -- an error body larger than the download sniff window ---------------------------

    /**
     * A streamed download sniffs DownloadSniffBytes of the body; an error body
     * larger than that keeps its HTTP status (a 500 stays 500, a 200 JSON body
     * is an error with status 200, also under a CSV content type, where it is
     * too long to parse) and saves no file.
     */
    public function testAnErrorBodyOverTheSniffWindowKeepsItsStatus()
    {
        $size = ZeroBounce::DownloadSniffBytes + 65536;
        $cases = array(
            array(500, 'text/plain', str_repeat('x', $size)),
            array(503, 'text/html', '<html>' . str_repeat('x', $size)),
            array(200, 'application/json', '{"error":"' . str_repeat('x', $size) . '"}'),
            array(200, 'text/csv', '{"error":"' . str_repeat('x', $size) . '"}'),
        );
        $stream = new ZBGetFileOptions();
        $stream->stream = true;
        foreach ($cases as $case) {
            list($status, $type, $body) = $case;
            $client = new CannedFileClient($status, $type, $body);
            $client->initialize('size-key');
            foreach (array('getFile', 'scoringGetFile', 'emailFinderGetFile', 'domainSearchGetFile') as $method) {
                $e = $this->thrown(function () use ($client, $method, $stream) {
                    $client->$method('f1', $this->dir . '/out.csv', $stream);
                });
                $this->assertInstanceOf(ZBApiException::class, $e, "$method $status $type");
                $this->assertSame($status, $e->getStatusCode(), "$method $status $type");
            }
        }
        $this->assertSame(array(), $this->leftovers());
    }

    // -- S4: the redaction decode check ------------------------------------------------

    private static function threeTimes($text)
    {
        return rawurlencode(rawurlencode(rawurlencode($text)));
    }

    private static function lowerHex($text)
    {
        $out = '';
        foreach (str_split($text) as $byte) {
            $out .= sprintf('%%%02x', ord($byte));
        }
        return $out;
    }

    private static function uEscaped($text)
    {
        $out = '';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            $out .= sprintf('\\u%04x', mb_ord($char, 'UTF-8'));
        }
        return $out;
    }

    /** Four layers: the SDK's own request encoding plus three echo layers (standard.md). */
    public function testAKeyUnderFourLayersIsOmitted()
    {
        $secret = 'probe"key/ZQXMARK01+23&ab c\\xyz';
        $body = self::threeTimes('GET /v2/getcredits?api_key=' . urlencode($secret) . ' HTTP/1.1');
        $this->assertSame(ZBSecret::CREDENTIAL_OMITTED_MESSAGE, ZBSecret::redact($body, array($secret)));
    }

    public function testEveryCallOmitsARequestUrlEchoedUnderThreeMoreEncodings()
    {
        $secret = 'probe"key/ZQXMARK01+23&ab c\\xyz';
        $root = $this->serve('<?php
            header("Content-Type: text/plain", true, 400);
            echo rawurlencode(rawurlencode(rawurlencode($_SERVER["REQUEST_URI"])));
        ');
        $client = new LoopbackClient();
        $client->initialize($secret);
        $client->pointAt($root);
        $leaks = array();
        $errors = 0;
        foreach ($this->sweepCalls($client, $this->dir . '/result.csv') as $name => $variants) {
            foreach ($variants as $call) {
                try {
                    $this->quietly($call);
                } catch (\Throwable $e) {
                    $errors++;
                    foreach (array($e->getMessage(), (string)$e) as $text) {
                        foreach (ZBSecret::decodedViews($text, 4) as $view) {
                            if (strpos($view, 'ZQXMARK') !== false) {
                                $leaks[$name] = true;
                            }
                        }
                    }
                }
            }
        }
        $this->assertGreaterThan(40, $errors);
        $this->assertSame(array(), array_keys($leaks));
    }

    /** Every decode step is needed; a NUL or %00 before the secret does not end the check. */
    public function testEveryDecodeStepIsNeeded()
    {
        $secret = 'zq x/ZQXMARK';
        $u = self::uEscaped($secret);
        $bodies = array(
            'lower-case %xx after invalid UTF-8' => '%ff%fe' . self::lowerHex($secret),
            '+ as space, then %xx' => self::lowerHex('zq') . '+' . self::lowerHex('x/ZQXMARK'),
            'JSON \\u escapes' => $u,
            '\\u escapes, URL-encoded' => self::lowerHex($u),
            'a literal + kept, %xx decoded' => 'zq+x' . self::lowerHex('/ZQXMARK'),
            'NUL first' => "\0" . $u,
            '%00 first' => '%00' . self::lowerHex($secret),
        );
        foreach ($bodies as $label => $body) {
            $secrets = $label === 'a literal + kept, %xx decoded' ? array('zq+x/ZQXMARK') : array($secret);
            $this->assertSame(ZBSecret::CREDENTIAL_OMITTED_MESSAGE, ZBSecret::redact('rejected ' . $body, $secrets), $label);
        }
    }

    /**
     * Every UTF-8 sequence length of the \uXXXX decoder (1, 2, 3 and 4 bytes,
     * the last from a surrogate pair): a secret with such a character, every
     * UTF-16 unit \u-escaped (a spelling no mask form covers), is found.
     */
    public function testEveryUtf8SequenceLengthIsDecoded()
    {
        foreach (array(1 => 'z', 2 => "\u{e9}", 3 => "\u{20ac}", 4 => "\u{1F600}") as $length => $char) {
            $this->assertSame($length, strlen($char));
            $secret = 'zq' . $char . 'ZQXMARK';
            $units = str_split(bin2hex(mb_convert_encoding($secret, 'UTF-16BE', 'UTF-8')), 4);
            $body = '\\u' . implode('\\u', $units);
            $this->assertSame(ZBSecret::CREDENTIAL_OMITTED_MESSAGE, ZBSecret::redact('rejected ' . $body, array($secret)), "$length bytes");
            // The same, URL-encoded on top.
            $this->assertSame(
                ZBSecret::CREDENTIAL_OMITTED_MESSAGE,
                ZBSecret::redact('rejected ' . self::lowerHex($body), array($secret)),
                "$length bytes, URL-encoded"
            );
        }
    }

    /**
     * "+" as a space without %-decoding is a step of its own: a secret
     * holding a literal %xx, echoed with only its space turned into +.
     */
    public function testPlusAsSpaceAloneIsADecodeStep()
    {
        $this->assertSame(
            ZBSecret::CREDENTIAL_OMITTED_MESSAGE,
            ZBSecret::redact('rejected zq+x%41ZQXMARK', array('zq x%41ZQXMARK'))
        );
    }

    public function testAUEscapedSurrogatePairIsJoined()
    {
        $secret = "pw\u{1F600}ZQXMARK";
        $once = substr(json_encode($secret), 1, -1); // pw😀ZQXMARK
        $body = self::uEscaped($once);
        $this->assertSame(ZBSecret::CREDENTIAL_OMITTED_MESSAGE, ZBSecret::redact($body, array($secret)));
    }
}

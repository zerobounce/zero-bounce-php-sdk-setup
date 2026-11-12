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

    public function __construct()
    {
        parent::__construct('guard-key', 'https://warmup.test');
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

    protected function streamHttpToHandle($url, $handle)
    {
        fwrite($handle, $this->answer['body']);
        return array(
            'status' => $this->answer['status'],
            'headers' => $this->answer['headers'],
            'head' => substr($this->answer['body'], 0, 65536),
            'error' => '',
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
        foreach ((array)glob($this->dir . '/{,.}*', GLOB_BRACE) as $file) {
            if (is_file($file)) {
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
                $w->testSmtpConfiguration('a@b.com', 'u', 'p', 'smtp.example.com', 587);
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
                        $this->assertGreaterThan(0, $options[CURLOPT_LOW_SPEED_TIME], $name);
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

    public function testAFailedWriteLeavesNoFile()
    {
        $csv = "email,status\n" . str_repeat("a@example.com,valid\n", 100);
        $client = new CannedFileClient(200, 'text/csv', $csv);
        $client->initialize('guard-key');
        $real = $this->dir . '/result.csv';
        foreach (array('getFile', 'scoringGetFile', 'emailFinderGetFile', 'domainSearchGetFile') as $name) {
            FullDiskStream::$room = 100;
            $error = $this->thrown(function () use ($client, $name, $real) { $client->$name('f1', 'zbfull://' . $real); });
            $this->assertSame('Could not write file', $error->getMessage(), $name);
            $this->assertFileDoesNotExist($real, $name . ' left a partial file');

            FullDiskStream::$room = null;
            FullDiskStream::$failFlush = true;
            $this->thrown(function () use ($client, $name, $real) { $client->$name('f1', 'zbfull://' . $real); });
            $this->assertFileDoesNotExist($real, $name . ' kept a file it could not flush');
            FullDiskStream::$failFlush = false;
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
     * request URL, an HTML title, on a 2xx and on a 4xx.
     *
     * @return array<string, array{0: int, 1: string, 2: string}>
     */
    private function keyEchoBodies($key)
    {
        $encoded = rawurlencode($key);
        return array(
            '200 {"error"}' => array(200, 'application/json', '{"error":"Invalid API key ' . $key . '"}'),
            '200 {"success":false}' => array(200, 'application/json', '{"success":false,"message":"Invalid API key ' . $key . '"}'),
            '200 batch "all" error' => array(200, 'application/json',
                '{"email_batch":[],"errors":[{"email_address":"all","error":"Invalid API key ' . $key . '"}]}'),
            '200 HTML title' => array(200, 'text/html', '<html><head><title>Denied ' . $key . '</title></head></html>'),
            '400 text quoting the URL' => array(400, 'text/plain', 'Bad request: https://x.example/v2/x?api_key=' . $encoded),
            '400 {"error"}' => array(400, 'application/json', '{"error":"Invalid API key ' . $key . '"}'),
        );
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
            foreach (array($key, rawurlencode($key), urlencode($key)) as $form) {
                $this->assertStringNotContainsString($form, $text, $label);
            }
            return true;
        }
        return false;
    }

    public function testNoErrorFromAnyRequestCarriesAKeyTheBodyQuotes()
    {
        $key = 'echo+key/42';
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
                    if ($label !== '200 batch "all" error' || $name === 'validateBatch') {
                        $this->assertTrue($threw, $name . ' returned for ' . $label);
                    }
                }
            }
            $this->assertSame(array(), $this->leftovers(), $label . ' left a file');
        }
    }

    public function testNoWarmupErrorCarriesAKeyTheBodyQuotes()
    {
        $key = 'echo+key/42';
        foreach ($this->keyEchoBodies($key) as $label => $answer) {
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
}

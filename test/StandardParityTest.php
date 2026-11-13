<?php

namespace ZeroBounce\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ZeroBounce\SDK\ZBActivityResponse;
use ZeroBounce\SDK\ZBApiException;
use ZeroBounce\SDK\ZBBatchValidateResponse;
use ZeroBounce\SDK\ZBException;
use ZeroBounce\SDK\ZBFileStatusResponse;
use ZeroBounce\SDK\ZBGetFileOptions;
use ZeroBounce\SDK\ZBListEvaluatorResponse;
use ZeroBounce\SDK\ZBMissingApiKeyException;
use ZeroBounce\SDK\ZBValidateOptions;
use ZeroBounce\SDK\ZBValidateResponse;
use ZeroBounce\SDK\ZeroBounce;
use ZeroBounce\SDK\ZeroBounceWarmup;

require_once __DIR__ . '/OfflineHttp.php';
require_once __DIR__ . '/RequestSweep.php';

/**
 * Answers every request with one canned response and records the URLs.
 * streamHttpToHandle is NOT overridden, so the streamed download runs the
 * real curl code (pointed only at loopback addresses).
 */
class CannedClient extends ZeroBounce
{
    /** @var string[] */
    public $urls = array();

    /** @var array{status: int, headers: string[], body: string} */
    public $answer;

    public function __construct($body = '{}', $status = 200, $contentType = 'application/json')
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
        $this->urls[] = $url;
        return $this->answer;
    }

    public function setUrls($scheme, $host)
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
            $prop->setValue($this, $scheme . '://' . $host . $path);
        }
    }
}

/** Reaches a plain-http loopback test server, which the SDK otherwise refuses. */
class LoopbackStreamClient extends ZeroBounce
{
    public function __construct($stallSeconds)
    {
        parent::__construct();
        $this->downloadStallSeconds = $stallSeconds;
    }

    protected function checkedUrl($url)
    {
        return $url;
    }

    public function useBulkHost($root)
    {
        $ref = new ReflectionClass(ZeroBounce::class);
        $prop = $ref->getProperty('bulkApiBaseUrl');
        if (\PHP_VERSION_ID < 80100) {
            $prop->setAccessible(true);
        }
        $prop->setValue($this, $root . '/v2');
    }
}

/** Records the curl options of real requests, sent only to a closed loopback port. */
class CurlOptionsClient extends ZeroBounce
{
    /** @var array<int, array<int, mixed>> */
    public $options = array();

    public function __construct()
    {
        parent::__construct();
    }

    protected function applyCurlOptions($curl, array $options)
    {
        $this->options[] = $options;
        parent::applyCurlOptions($curl, $options);
    }

    public function plainRequest($url)
    {
        return $this->executeHttp('GET', $url);
    }

    public function streamedRequest($url)
    {
        $handle = fopen('php://memory', 'w+');
        try {
            return $this->streamHttpToHandle($url, $handle);
        } finally {
            fclose($handle);
        }
    }
}

class CurlOptionsWarmup extends ZeroBounceWarmup
{
    /** @var array<int, array<int, mixed>> */
    public $options = array();

    protected function applyCurlOptions($curl, array $options)
    {
        $this->options[] = $options;
        parent::applyCurlOptions($curl, $options);
    }
}

/** Answers every Warmup request with an empty list and records the headers. */
class CannedWarmup extends ZeroBounceWarmup
{
    /** @var string[] */
    public $headers = array();

    protected function executeHttp($method, $url, array $headers = array(), $body = null, $timeout = 120)
    {
        $this->headers = array_merge($this->headers, $headers);
        return array('status' => 200, 'headers' => array('Content-Type: application/json'), 'body' => '[]');
    }
}

class StandardParityTest extends TestCase
{
    use RequestSweep;

    private function closedPort()
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($socket, $errstr);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int)substr(strrchr($name, ':'), 1);
    }

    private function thrown(callable $call)
    {
        try {
            $call();
        } catch (ZBException $e) {
            return $e;
        }
        $this->fail('expected a ZBException');
    }

    // -- response flags ------------------------------------------------------

    public function testValidateFlagsAreRealBooleans()
    {
        $response = new ZBValidateResponse();
        $response->Deserialize('{"address":"a@example.com","mx_found":"false","catchall_domain":"False","free_email":true,"status":"valid"}');
        $this->assertFalse($response->mxFound);
        $this->assertFalse($response->catchallDomain);
        $this->assertTrue($response->freeEmail);

        $response = new ZBValidateResponse();
        $response->Deserialize('{"address":"a@example.com","status":"valid","mx_found":"true","catchall_domain":"unknown","free_email":"FALSE"}');
        $this->assertTrue($response->mxFound);
        $this->assertNull($response->catchallDomain);
        $this->assertFalse($response->freeEmail);
    }

    public function testOtherDocumentedFlagsAreRealBooleans()
    {
        $activity = new ZBActivityResponse();
        $activity->Deserialize('{"found":"false","active_in_days":"30"}');
        $this->assertFalse($activity->found);

        $evaluator = new ZBListEvaluatorResponse();
        $evaluator->Deserialize('{"abuse":"false","do_not_mail":"true","spam_trap":false}');
        $this->assertFalse($evaluator->abuse);
        $this->assertTrue($evaluator->doNotMail);
        $this->assertFalse($evaluator->spamTrap);

        $status = new ZBFileStatusResponse();
        $status->Deserialize('{"success":"True","file_status":"Complete"}');
        $this->assertTrue($status->success);
    }

    public function testUnknownStatusValuesAreKeptAsSent()
    {
        $response = new ZBValidateResponse();
        $response->Deserialize('{"address":"a@example.com","status":"brand_new","sub_status":"newer_still"}');
        $this->assertSame('brand_new', $response->status);
        $this->assertSame('newer_still', $response->subStatus);

        $response = new ZBValidateResponse();
        $response->Deserialize('{"address":"a@example.com","status":"unknown","sub_status":"greylisted"}');
        $this->assertSame('unknown', $response->status);
        $this->assertSame('greylisted', $response->subStatus);
    }

    public function testToStringNamesEveryActivityFieldAndPrintsFlags()
    {
        $response = new ZBValidateResponse();
        $response->Deserialize('{"address":"a@example.com","status":"valid","active_in_days":"30","active_first_seen":"2024-01-01","mx_found":"false"}');
        $text = (string)$response;
        $this->assertStringContainsString("activeInDays='30'", $text);
        $this->assertStringContainsString("activeFirstSeen='2024-01-01'", $text);
        $this->assertStringContainsString('mxFound=false', $text);
    }

    // -- errors on a 2xx -----------------------------------------------------

    public function testErrorMessageWithoutSuccessIsAnErrorWithStatus200()
    {
        $client = new CannedClient('{"error_message":"Invalid API key"}');
        $client->initialize('parity-key');
        foreach (array(
            function () use ($client) { $client->getCredits(); },
            function () use ($client) { $client->validate('a@example.com'); },
            function () use ($client) { $client->fileStatus('f1'); },
        ) as $call) {
            $error = $this->thrown($call);
            $this->assertInstanceOf(ZBApiException::class, $error);
            $this->assertSame('Invalid API key', $error->getMessage());
            $this->assertSame(200, $error->getStatusCode());
        }
    }

    public function testTheGreylistMessageIsAResult()
    {
        $client = new CannedClient('{"Message":"Greylist validation still in progress..."}');
        $client->initialize('parity-key');
        $response = $client->validate('a@example.com');
        $this->assertSame('Greylist validation still in progress...', $response->message);
    }

    public function testGuessFormatTreatsAMessageOnlyBodyAsAnError()
    {
        $client = new CannedClient('{"Message":"Invalid API key or your account ran out of credits"}');
        $client->initialize('parity-key');
        $error = $this->thrown(function () use ($client) {
            $this->quietly(function () use ($client) {
                $client->guessFormat('example.com', null, null, null);
            });
        });
        $this->assertSame('Invalid API key or your account ran out of credits', $error->getMessage());
        $this->assertInstanceOf(ZBApiException::class, $error);
        $this->assertSame(200, $error->getStatusCode());
    }

    // -- transport -----------------------------------------------------------

    public function testARedirectIsReportedAsOne()
    {
        $client = new CannedClient('', 302, 'text/html');
        $client->initialize('parity-key');
        $error = $this->thrown(function () use ($client) { $client->getCredits(); });
        $this->assertSame('ZeroBounce answered with a redirect (HTTP 302), which is not followed', $error->getMessage());
        $this->assertSame(302, $error->getStatusCode());
    }

    public function testATransportFailureReportsCurlsError()
    {
        $client = ZeroBounce::create();
        $port = $this->closedPort();
        $client->initialize('parity-key', 'https://127.0.0.1:' . $port . '/v2');
        $error = $this->thrown(function () use ($client) { $client->getCredits(); });
        $this->assertStringNotContainsString('Empty response', $error->getMessage());
        $this->assertMatchesRegularExpression('/connect|refused/i', $error->getMessage());
        $this->assertStringNotContainsString('parity-key', $error->getMessage());
    }

    public function testAStalledStreamedDownloadIsAbandoned()
    {
        $port = $this->closedPort();
        $script = tempnam(sys_get_temp_dir(), 'zb-stall-');
        file_put_contents($script, '<?php
$server = stream_socket_server("tcp://127.0.0.1:' . $port . '");
$keep = array();
$until = time() + 40;
while (time() < $until && ($c = @stream_socket_accept($server, 1)) !== false || time() < $until) {
    if (!empty($c)) {
        fread($c, 4096);
        fwrite($c, "HTTP/1.1 200 OK\r\nContent-Type: text/csv\r\nContent-Length: 100000\r\n\r\nemail\n");
        $keep[] = $c;
        $c = null;
    }
}
');
        $pipes = array();
        // An argument list, so proc_terminate() reaches the server, not a shell.
        $process = proc_open(
            array(PHP_BINARY, $script),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        $this->assertIsResource($process);
        try {
            $ready = false;
            for ($i = 0; $i < 100 && !$ready; $i++) {
                usleep(20000);
                $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.1);
                if ($probe) {
                    fclose($probe);
                    $ready = true;
                }
            }
            $this->assertTrue($ready, 'stall server did not start');

            $client = new LoopbackStreamClient(2);
            $client->initialize('parity-key');
            $client->useBulkHost('http://127.0.0.1:' . $port);
            $options = new ZBGetFileOptions();
            $options->stream = true;
            $path = sys_get_temp_dir() . '/zb-php-' . getmypid() . '-stall.csv';
            $started = microtime(true);
            $error = $this->thrown(function () use ($client, $path, $options) {
                $client->getFile('f1', $path, $options);
            });
            $elapsed = microtime(true) - $started;
            $this->assertLessThan(15, $elapsed, 'a stalled download must not hang');
            $this->assertFileDoesNotExist($path);
            $this->assertStringNotContainsString('parity-key', $error->getMessage());
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
            @unlink($script);
        }
        $this->assertFalse(@stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.5), 'the stall server outlived the test');
    }

    // -- request options -----------------------------------------------------

    public function testBooleanOptionsAreParsedStrictly()
    {
        $api = new FakeApi();
        $client = new OfflineClient($api);
        $client->initialize('parity-key');

        $options = new ZBValidateOptions();
        $options->activityData = 'false';
        $options->verifyPlus = 'TRUE';
        $options->domainInfo = 0;
        $client->validate('a@example.com', null, $options);
        $sent = $api->take()[0]['fields'];
        $this->assertSame('false', $sent['activity_data']);
        $this->assertSame('true', $sent['verify_plus']);
        $this->assertSame('false', $sent['domain_info']);
        $this->assertSame('false', $options->activityData, 'the caller\'s options are not changed');

        $client->validateS3File(array(
            'file_name' => 'f.csv', 'has_header_row' => 'false', 'remove_duplicate' => 'False',
            'return_url' => 'https://example.com/done',
        ));
        $sent = $api->take()[0]['fields'];
        $this->assertSame('false', $sent['has_header_row']);
        $this->assertSame('false', $sent['remove_duplicate']);
    }

    public function testJunkBooleansAreRejectedBeforeSending()
    {
        $api = new FakeApi();
        $client = new OfflineClient($api);
        $client->initialize('parity-key');
        $done = 'https://example.com/done';
        $calls = array(
            function () use ($client) {
                $o = new ZBValidateOptions();
                $o->activityData = 'yes';
                $client->validate('a@example.com', null, $o);
            },
            function () use ($client) {
                $o = new ZBValidateOptions();
                $o->greylistProcessing = 'on';
                $client->validate('a@example.com', null, $o);
            },
            function () use ($client) {
                $o = new ZBValidateOptions();
                $o->verifyPlus = 2;
                $client->validateBatch(array('a@example.com'), $o);
            },
            function () use ($client) {
                $o = new ZBGetFileOptions();
                $o->activityData = 'nope';
                $client->getFile('f1', sys_get_temp_dir() . '/zb-junk.csv', $o);
            },
            function () use ($client, $done) {
                $client->validateS3File(array('file_name' => 'f.csv', 'has_header_row' => 'yes', 'return_url' => $done));
            },
            function () use ($client, $done) {
                $client->validateS3File(array('file_name' => 'f.csv', 'allow_phase_2' => 'maybe', 'return_url' => $done));
            },
            function () use ($client, $done) {
                $client->validateSFTPFile(array('file_name' => 'f.csv', 'remove_duplicates' => 'nope', 'return_url' => $done));
            },
            function () use ($client) { $client->sendFileStream('e\n', 'f.csv', 1, null, null, null, null, null, 'yes'); },
            function () use ($client) { $client->sendDomainSearchFileStream('d\n', 'f.csv', 1, 'y'); },
        );
        foreach ($calls as $i => $call) {
            $error = $this->thrown($call);
            $this->assertStringContainsString('must be true or false', $error->getMessage(), 'case ' . $i);
        }
        $this->assertSame(0, $api->count());
    }

    public function testDebugOutputHidesTheKeys()
    {
        $client = ZeroBounce::create();
        $client->initialize('secret-client-key');
        $warmup = new ZeroBounceWarmup('secret-warmup-key');
        $this->assertStringNotContainsString('secret-client-key', print_r($client, true));
        $this->assertStringNotContainsString('secret-warmup-key', print_r($warmup, true));
        ob_start();
        var_dump($client, $warmup);
        $dump = (string)ob_get_clean();
        $this->assertStringNotContainsString('secret-client-key', $dump);
        $this->assertStringNotContainsString('secret-warmup-key', $dump);
    }

    // -- https on every request path -----------------------------------------

    public function testEveryPublicRequestMethodRefusesHttp()
    {
        $client = new CannedClient('{"success":true}');
        $client->initialize('parity-key');
        // A closed loopback port: if a check were missing, the streamed
        // download's real curl call would fail to connect rather than go out.
        $client->setUrls('http', '127.0.0.1:' . $this->closedPort());
        $calls = $this->sweepCalls($client, sys_get_temp_dir() . '/zb-php-' . getmypid() . '-sweep.csv');

        $this->assertSame(
            array(),
            array_values(array_diff($this->requestMethodNames(), array_keys($calls))),
            'public methods missing from the sweep'
        );

        foreach ($calls as $name => $variants) {
            foreach ($variants as $i => $call) {
                $error = $this->thrown($call);
                $this->assertStringContainsString('https://', $error->getMessage(), $name . ' #' . $i);
            }
        }
        $this->assertSame(array(), $client->urls);
    }

    // -- connect timeout -------------------------------------------------------

    public function testEveryCurlRequestHasAConnectTimeout()
    {
        $port = $this->closedPort();
        $client = new CurlOptionsClient();
        $client->initialize('parity-key');
        $client->plainRequest('http://127.0.0.1:' . $port . '/v2/getcredits');
        $client->streamedRequest('https://127.0.0.1:' . $port . '/v2/getfile');
        $warmup = new CurlOptionsWarmup('parity-key', 'https://127.0.0.1:' . $port);
        try {
            $warmup->listSendingAccounts();
        } catch (ZBException $e) {
            // Refused by the closed port, as intended.
        }
        $recorded = array('executeHttp' => $client->options, 'warmup' => $warmup->options);
        $this->assertCount(2, $client->options, 'executeHttp and the streamed download');
        $this->assertCount(1, $warmup->options, 'the Warmup client');
        foreach ($recorded as $name => $requests) {
            foreach ($requests as $i => $options) {
                $this->assertArrayHasKey(CURLOPT_CONNECTTIMEOUT, $options, $name . ' #' . $i);
                $this->assertSame(30, $options[CURLOPT_CONNECTTIMEOUT], $name . ' #' . $i);
            }
        }
    }

    public function testEveryCurlHandleInTheSourceSetsAConnectTimeout()
    {
        // A new transport added later cannot skip the timeout unnoticed.
        foreach (glob(dirname(__DIR__) . '/src/*.php') as $file) {
            $source = (string)file_get_contents($file);
            $this->assertSame(
                substr_count($source, 'curl_init('),
                substr_count($source, 'CURLOPT_CONNECTTIMEOUT =>'),
                basename($file) . ': every curl handle needs CURLOPT_CONNECTTIMEOUT'
            );
        }
    }

    // -- the key in errors, traces and dumps -----------------------------------

    public function testTrailingSlashesOnTheListEvaluatorAndInboxPlacementUrlsAreDropped()
    {
        $client = new CannedClient('{"success":true}');
        $client->initialize('parity-key', null, 'https://le.example.test/v2/listevaluator//', 'https://ipt.example.test/api/ipt///');
        $client->getListEvaluatorResult('f1');
        $client->getInboxPlacementProviders();
        $client->getInboxPlacementTest(7);
        $this->assertCount(3, $client->urls);
        $this->assertStringStartsWith('https://le.example.test/v2/listevaluator/f1/?', $client->urls[0]);
        $this->assertSame('https://ipt.example.test/api/ipt/providers', $client->urls[1]);
        $this->assertSame('https://ipt.example.test/api/ipt/test/7', $client->urls[2]);
    }

    public function testTheKeyIsMaskedInErrorMessages()
    {
        $key = 'mask+me/key=1';
        $forms = array($key, urlencode($key), rawurlencode($key));
        $json = 'application/json';
        $answers = array(
            'transport error, urlencoded key' => array('status' => 0, 'body' => '', 'error' => 'Could not reach /v2/getcredits?api_key=' . urlencode($key)),
            'transport error, raw key' => array('status' => 0, 'body' => '', 'error' => 'Could not use key ' . $key),
            'error on a 200' => array('status' => 200, 'body' => json_encode(array('error' => 'Unknown key ' . $key))),
            'error on a 400' => array('status' => 400, 'body' => json_encode(array('error' => 'Unknown key ' . rawurlencode($key)))),
        );
        $masked = 0;
        foreach ($answers as $name => $answer) {
            $client = new CannedClient($answer['body'], $answer['status'], $json);
            if (isset($answer['error'])) {
                $client->answer['error'] = $answer['error'];
            }
            $client->initialize($key);
            $path = sys_get_temp_dir() . '/zb-php-' . getmypid() . '-mask.csv';
            foreach (array(
                'getCredits' => function () use ($client) { $client->getCredits(); },
                'getFile' => function () use ($client, $path) { $client->getFile('f1', $path); },
            ) as $call => $run) {
                $message = $this->thrown($run)->getMessage();
                foreach ($forms as $form) {
                    $this->assertStringNotContainsString($form, $message, $name . ', ' . $call);
                }
                $masked += substr_count($message, '***');
            }
        }
        $this->assertGreaterThanOrEqual(count($answers) * 2, $masked, 'each message should show *** where the key was');
    }

    public function testTheKeyStaysOutOfStackTraces()
    {
        // Exception traces keep call arguments unless this is set (php.ini-production sets it).
        $previous = ini_set('zend.exception_ignore_args', '0');
        $key = 'trace-secret-0123456789abcdef';
        $prefix = substr($key, 0, 15);
        try {
            $calls = array(
                'initialize, http baseUrl' => function () use ($key) { ZeroBounce::create()->initialize($key, 'http://api.example.test/v2'); },
                'initialize, http bulkBaseUrl' => function () use ($key) { ZeroBounce::create()->initialize($key, null, null, null, 'http://bulk.example.test'); },
                'initialize, key with a newline' => function () use ($key) { ZeroBounce::create()->initialize($key . "\n"); },
                'warmup, http baseUrl' => function () use ($key) { new ZeroBounceWarmup($key, 'http://warmup.example.test'); },
                'warmup, key with a newline' => function () use ($key) { new ZeroBounceWarmup($key . "\n"); },
            );
            foreach ($calls as $name => $call) {
                $error = $this->thrown($call);
                $this->assertStringNotContainsString($prefix, $error->getTraceAsString(), $name);
                $this->assertStringNotContainsString($prefix, (string)$error, $name);
                foreach ($error->getTrace() as $frame) {
                    foreach (isset($frame['args']) ? $frame['args'] : array() as $arg) {
                        if (is_string($arg)) {
                            $this->assertStringNotContainsString($prefix, $arg, $name);
                        }
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '0' : $previous);
        }
    }

    public function testVarExportAndSerializeDoNotRevealTheKey()
    {
        // Canned clients: nothing here may reach the network.
        $client = new CannedClient('{"Credits":"5"}');
        $client->initialize('export-client-key');
        $warmup = new CannedWarmup('export-warmup-key');
        foreach (array('export-client-key' => $client, 'export-warmup-key' => $warmup) as $key => $object) {
            $this->assertStringNotContainsString($key, var_export($object, true));
            $this->assertStringNotContainsString($key, serialize($object));
            $this->assertStringNotContainsString($key, print_r((array)$object, true));
            ob_start();
            var_dump((array)$object);
            $this->assertStringNotContainsString($key, (string)ob_get_clean());
        }

        // A copy made by unserialize() has no key: it refuses to send anything
        // until it is initialized again.
        $copy = unserialize(serialize($client));
        $this->assertInstanceOf(ZBMissingApiKeyException::class, $this->thrown(function () use ($copy) { $copy->getCredits(); }));
        $this->assertSame(array(), $copy->urls);
        $warmupCopy = unserialize(serialize($warmup));
        $this->assertInstanceOf(ZBMissingApiKeyException::class, $this->thrown(function () use ($warmupCopy) { $warmupCopy->listSendingAccounts(); }));
        $this->assertSame(array(), $warmupCopy->headers);
        // The originals keep their keys.
        $this->assertSame('5', (string)$client->getCredits()->credits);
        $warmup->listSendingAccounts();
        $this->assertContains('API-Key: export-warmup-key', $warmup->headers);
    }

    // -- toString --------------------------------------------------------------

    public function testToStringNamesItsTypeAndEveryPublicField()
    {
        $files = glob(dirname(__DIR__) . '/src/ZB*Response.php');
        $this->assertGreaterThan(10, count($files));
        foreach ($files as $file) {
            $short = basename($file, '.php');
            $ref = new ReflectionClass('ZeroBounce\\SDK\\' . $short);
            $object = $ref->newInstanceWithoutConstructor();
            $method = $ref->getMethod('boolFields');
            if (\PHP_VERSION_ID < 80100) {
                $method->setAccessible(true);
            }
            $bools = $method->invoke($object);
            $expected = array();
            foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $i => $prop) {
                if ($prop->isStatic()) {
                    continue;
                }
                $name = $prop->getName();
                if (in_array($name, $bools, true)) {
                    $prop->setValue($object, true);
                    $expected[$name] = $name . '=true';
                    continue;
                }
                $marker = 'MARK' . $i . 'X';
                $prop->setValue($object, is_array($prop->getValue($object)) ? array($marker) : $marker);
                $expected[$name] = $marker;
            }
            $text = (string)$object;
            $this->assertStringContainsString($short . '{', $text);
            foreach ($expected as $name => $needle) {
                $this->assertStringContainsString($needle, $text, $short . '::$' . $name . ' is missing from ' . $text);
            }
        }
    }

    public function testBatchToStringShowsEveryFieldOfEachResultAndTheErrors()
    {
        $email = new ZBValidateResponse();
        $markers = array();
        foreach ((new ReflectionClass(ZBValidateResponse::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $i => $prop) {
            if (!$prop->isStatic() && !in_array($prop->getName(), array('freeEmail', 'mxFound', 'catchallDomain'), true)) {
                $markers[$prop->getName()] = 'EMAIL' . $i . 'X';
                $prop->setValue($email, 'EMAIL' . $i . 'X');
            }
        }
        $batch = new ZBBatchValidateResponse();
        $batch->emailBatch = array($email);
        $batch->errors = array(array('email_address' => 'bad@', 'error' => 'Invalid email address'));
        $text = (string)$batch;
        foreach ($markers as $name => $marker) {
            $this->assertStringContainsString($marker, $text, $name);
        }
        $this->assertStringContainsString('bad@', $text);
        $this->assertStringContainsString('Invalid email address', $text);
    }
}

<?php

namespace ZeroBounce\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ZeroBounce\SDK\ZBActivityResponse;
use ZeroBounce\SDK\ZBApiException;
use ZeroBounce\SDK\ZBException;
use ZeroBounce\SDK\ZBFileStatusResponse;
use ZeroBounce\SDK\ZBGetFileOptions;
use ZeroBounce\SDK\ZBListEvaluatorResponse;
use ZeroBounce\SDK\ZBValidateOptions;
use ZeroBounce\SDK\ZBValidateResponse;
use ZeroBounce\SDK\ZeroBounce;
use ZeroBounce\SDK\ZeroBounceWarmup;

require_once __DIR__ . '/OfflineHttp.php';

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

class StandardParityTest extends TestCase
{
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

    private function quietly(callable $call)
    {
        set_error_handler(function ($level) {
            return $level === E_USER_DEPRECATED;
        });
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    // -- response flags ------------------------------------------------------

    public function testValidateFlagsAreRealBooleans()
    {
        $response = new ZBValidateResponse();
        $response->Deserialize('{"mx_found":"false","catchall_domain":"False","free_email":true,"status":"valid"}');
        $this->assertFalse($response->mxFound);
        $this->assertFalse($response->catchallDomain);
        $this->assertTrue($response->freeEmail);

        $response = new ZBValidateResponse();
        $response->Deserialize('{"mx_found":"true","catchall_domain":"unknown","free_email":"FALSE"}');
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
        $response->Deserialize('{"status":"brand_new","sub_status":"newer_still"}');
        $this->assertSame('brand_new', $response->status);
        $this->assertSame('newer_still', $response->subStatus);

        $response = new ZBValidateResponse();
        $response->Deserialize('{"status":"unknown","sub_status":"greylisted"}');
        $this->assertSame('unknown', $response->status);
        $this->assertSame('greylisted', $response->subStatus);
    }

    public function testToStringNamesEveryActivityFieldAndPrintsFlags()
    {
        $response = new ZBValidateResponse();
        $response->Deserialize('{"active_in_days":"30","active_first_seen":"2024-01-01","mx_found":"false"}');
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
        $process = proc_open(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script),
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

    /**
     * @return array<string, callable[]>
     */
    private function sweepCalls(ZeroBounce $c, $out)
    {
        $csv = __DIR__ . '/email_file.csv';
        $done = 'https://example.com/done';
        $post = new ZBValidateOptions();
        $post->usePost = true;
        $stream = new ZBGetFileOptions();
        $stream->stream = true;
        $job = array('file_name' => 'f.csv', 'return_url' => $done);
        return array(
            'validate' => array(
                function () use ($c) { $c->validate('a@example.com'); },
                function () use ($c, $post) { $c->validate('a@example.com', null, $post); },
            ),
            'validateBatch' => array(function () use ($c) { $c->validateBatch(array('a@example.com')); }),
            'getCredits' => array(
                function () use ($c) { $c->getCredits(); },
                function () use ($c) { $c->getCredits(true); },
            ),
            'getApiUsage' => array(
                function () use ($c) { $c->getApiUsage('2024-01-01', '2024-01-02'); },
                function () use ($c) { $c->getApiUsage('2024-01-01', '2024-01-02', true); },
            ),
            'getActivity' => array(
                function () use ($c) { $c->getActivity('a@example.com'); },
                function () use ($c) { $c->getActivity('a@example.com', true); },
            ),
            'scoreEmail' => array(
                function () use ($c) { $c->scoreEmail('a@example.com'); },
                function () use ($c) { $c->scoreEmail('a@example.com', true); },
            ),
            'scoreEmailPost' => array(function () use ($c) { $c->scoreEmailPost('a@example.com'); }),
            'sendFile' => array(function () use ($c, $csv) { $c->sendFile($csv, 1); }),
            'scoringSendFile' => array(function () use ($c, $csv) { $c->scoringSendFile($csv, 1); }),
            'sendFileStream' => array(function () use ($c) { $c->sendFileStream("e\n", 'f.csv', 1); }),
            'scoringSendFileStream' => array(function () use ($c) { $c->scoringSendFileStream("e\n", 'f.csv', 1); }),
            'fileStatus' => array(function () use ($c) { $c->fileStatus('f1'); }),
            'scoringFileStatus' => array(function () use ($c) { $c->scoringFileStatus('f1'); }),
            'getFile' => array(
                function () use ($c, $out) { $c->getFile('f1', $out); },
                function () use ($c, $out, $stream) { $c->getFile('f1', $out, $stream); },
            ),
            'scoringGetFile' => array(
                function () use ($c, $out) { $c->scoringGetFile('f1', $out); },
                function () use ($c, $out, $stream) { $c->scoringGetFile('f1', $out, $stream); },
            ),
            'deleteFile' => array(function () use ($c) { $c->deleteFile('f1'); }),
            'scoringDeleteFile' => array(function () use ($c) { $c->scoringDeleteFile('f1'); }),
            'guessFormat' => array(function () use ($c) {
                $this->quietly(function () use ($c) { $c->guessFormat('example.com', null, null, null); });
            }),
            'findEmail' => array(
                function () use ($c) { $c->findEmail('example.com', 'Ann'); },
                function () use ($c) { $c->findEmail('example.com', 'Ann', null, null, null, true); },
            ),
            'findEmailFormat' => array(
                function () use ($c) { $c->findEmailFormat('example.com'); },
                function () use ($c) { $c->findEmailFormat('example.com', null, true); },
            ),
            'sendEmailFinderFile' => array(function () use ($c, $csv) { $c->sendEmailFinderFile($csv, 1, 2); }),
            'sendEmailFinderFileStream' => array(function () use ($c) { $c->sendEmailFinderFileStream("e\n", 'f.csv', 1, 2); }),
            'emailFinderFileStatus' => array(function () use ($c) { $c->emailFinderFileStatus('f1'); }),
            'emailFinderGetFile' => array(function () use ($c, $out) { $c->emailFinderGetFile('f1', $out); }),
            'emailFinderDeleteFile' => array(function () use ($c) { $c->emailFinderDeleteFile('f1'); }),
            'sendDomainSearchFile' => array(function () use ($c, $csv) { $c->sendDomainSearchFile($csv, 1); }),
            'sendDomainSearchFileStream' => array(function () use ($c) { $c->sendDomainSearchFileStream("d\n", 'f.csv', 1); }),
            'domainSearchFileStatus' => array(function () use ($c) { $c->domainSearchFileStatus('f1'); }),
            'domainSearchGetFile' => array(function () use ($c, $out) { $c->domainSearchGetFile('f1', $out); }),
            'domainSearchDeleteFile' => array(function () use ($c) { $c->domainSearchDeleteFile('f1'); }),
            'sendListEvaluatorFile' => array(function () use ($c, $csv) { $c->sendListEvaluatorFile($csv, 1); }),
            'sendListEvaluatorFileStream' => array(function () use ($c) { $c->sendListEvaluatorFileStream("e\n", 'f.csv', 1); }),
            'getListEvaluatorResult' => array(function () use ($c) { $c->getListEvaluatorResult('f1'); }),
            'sendEvaluatedFile' => array(function () use ($c) { $c->sendEvaluatedFile('f1'); }),
            'listS3Files' => array(function () use ($c) { $c->listS3Files(); }),
            'validateS3File' => array(function () use ($c, $job) { $c->validateS3File($job); }),
            'scoreS3File' => array(function () use ($c, $job) { $c->scoreS3File($job); }),
            'exportS3File' => array(function () use ($c) { $c->exportS3File('f1'); }),
            'addFilter' => array(function () use ($c) { $c->addFilter('allow', 'email', 'a@example.com'); }),
            'listFilters' => array(
                function () use ($c) { $c->listFilters(); },
                function () use ($c) { $c->listFilters(true); },
            ),
            'deleteFilter' => array(function () use ($c) { $c->deleteFilter('allow', 'email', 'a@example.com'); }),
            'emailFinderS3File' => array(function () use ($c, $job) { $c->emailFinderS3File($job); }),
            'domainSearchS3File' => array(function () use ($c, $job) { $c->domainSearchS3File($job); }),
            'validateSFTPFile' => array(function () use ($c, $job) { $c->validateSFTPFile($job); }),
            'scoreSFTPFile' => array(function () use ($c, $job) { $c->scoreSFTPFile($job); }),
            'emailFinderSFTPFile' => array(function () use ($c, $job) { $c->emailFinderSFTPFile($job); }),
            'domainSearchSFTPFile' => array(function () use ($c, $job) { $c->domainSearchSFTPFile($job); }),
            'exportSFTPFile' => array(function () use ($c) { $c->exportSFTPFile('f1'); }),
            'getInboxPlacementProviders' => array(function () use ($c) { $c->getInboxPlacementProviders(); }),
            'getInboxPlacementTestTypes' => array(function () use ($c) { $c->getInboxPlacementTestTypes(); }),
            'createInboxPlacementTest' => array(function () use ($c) { $c->createInboxPlacementTest('Spring launch'); }),
            'getInboxPlacementTest' => array(function () use ($c) { $c->getInboxPlacementTest(1); }),
            'listInboxPlacementTests' => array(function () use ($c) { $c->listInboxPlacementTests(); }),
            'deleteInboxPlacementTest' => array(function () use ($c) { $c->deleteInboxPlacementTest(1); }),
            'getInboxPlacementWebhook' => array(function () use ($c) { $c->getInboxPlacementWebhook(); }),
            'setInboxPlacementWebhook' => array(function () use ($c) { $c->setInboxPlacementWebhook('https://example.com/h'); }),
        );
    }

    public function testEveryPublicRequestMethodRefusesHttp()
    {
        $client = new CannedClient('{"success":true}');
        $client->initialize('parity-key');
        // A closed loopback port: if a check were missing, the streamed
        // download's real curl call would fail to connect rather than go out.
        $client->setUrls('http', '127.0.0.1:' . $this->closedPort());
        $calls = $this->sweepCalls($client, sys_get_temp_dir() . '/zb-php-' . getmypid() . '-sweep.csv');

        $public = array();
        foreach ((new ReflectionClass(ZeroBounce::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isStatic() && strpos($method->getName(), '__') !== 0 && $method->getName() !== 'initialize') {
                $public[] = $method->getName();
            }
        }
        $this->assertSame(array(), array_values(array_diff($public, array_keys($calls))), 'public methods missing from the sweep');

        foreach ($calls as $name => $variants) {
            foreach ($variants as $i => $call) {
                $error = $this->thrown($call);
                $this->assertStringContainsString('https://', $error->getMessage(), $name . ' #' . $i);
            }
        }
        $this->assertSame(array(), $client->urls);
    }
}

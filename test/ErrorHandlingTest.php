<?php

namespace ZeroBounce\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBounce\SDK\ZBApiException;
use ZeroBounce\SDK\ZBException;
use ZeroBounce\SDK\ZBValidateOptions;
use ZeroBounce\SDK\ZeroBounce;
use ZeroBounce\SDK\ZeroBounceWarmup;

require_once __DIR__ . '/RequestSweep.php';
require_once __DIR__ . '/Seams.php';

/** Answers every request with one canned body and records what was sent. */
class ErrorsCannedClient extends ClientDouble
{
    public $body = '{}';
    public $sent = array();

    public function __construct()
    {
        $this->client = ZeroBounce::create();
        $self = $this;
        $this->hook(array(
            'executeHttp' => function ($method, $url, array $headers, $body) use ($self) {
                $self->sent[] = array('method' => $method, 'url' => $url, 'body' => (string)$body);
                return array('status' => 200, 'headers' => array('HTTP/1.1 200 OK', 'Content-Type: application/json'), 'body' => $self->body);
            },
        ));
    }
}

/** Message-only and falsy-success bodies are errors on every typed call; bad arguments raise the SDK's exception. */
class ErrorHandlingTest extends TestCase
{
    use RequestSweep;

    /** Raw-JSON, list-evaluator-result and Inbox Placement calls pass message bodies through; downloads are not JSON calls. */
    private static $exempt = array(
        'addFilter', 'listFilters', 'deleteFilter', 'getListEvaluatorResult',
        'getInboxPlacementProviders', 'getInboxPlacementTestTypes', 'createInboxPlacementTest',
        'getInboxPlacementTest', 'listInboxPlacementTests', 'deleteInboxPlacementTest',
        'getInboxPlacementWebhook', 'setInboxPlacementWebhook',
        'getFile', 'scoringGetFile', 'emailFinderGetFile', 'domainSearchGetFile',
    );

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

    private function client($body)
    {
        $client = new ErrorsCannedClient();
        $client->initialize('errors-key');
        $client->body = $body;
        return $client;
    }

    /** @return string[] the typed calls that accepted $body */
    private function accepted($body, array $skip)
    {
        $client = $this->client($body);
        $accepted = array();
        foreach ($this->sweepCalls($client, sys_get_temp_dir() . '/errors-out.csv') as $name => $variants) {
            if (in_array($name, $skip, true)) {
                continue;
            }
            foreach ($variants as $i => $call) {
                try {
                    $this->quietly($call);
                    $accepted[] = $name . '#' . $i;
                } catch (ZBApiException $e) {
                    $this->assertSame(200, $e->getStatusCode(), $name . ' ' . $body);
                }
            }
        }
        return $accepted;
    }

    public function testMessageOnlyBodyIsAnErrorOnEveryTypedCall()
    {
        foreach (array('{}', '{"message":"m"}', '{"Message":"m"}', '{"error":["a","b"]}', '{"detail":"d"}',
            '{"Error":null}', '{"message":""}') as $body) {
            $skip = self::$exempt;
            if ($body === '{"Message":"m"}') {
                $skip[] = 'validate'; // the greylist answer is a validate result
            }
            $this->assertSame(array(), $this->accepted($body, $skip), $body);
        }
    }

    public function testValidateAcceptsOnlyTheGreylistMessage()
    {
        $client = $this->client('{"Message":"Greylist validation still in progress"}');
        $this->assertNotNull($client->validate('a@example.com'));
        foreach (array('{"message":"m"}', '{"Message":" "}', '{"Message":["m"]}') as $body) {
            $client->body = $body;
            try {
                $client->validate('a@example.com');
                $this->fail('accepted ' . $body);
            } catch (ZBApiException $e) {
                $this->assertSame(200, $e->getStatusCode());
            }
        }
    }

    public function testEveryFalseSuccessIsAnError()
    {
        foreach (array('{"success":"0"}', '{"success":0}', '{"success":"False"}', '{"success":false}', '{"success":" 0 "}') as $body) {
            $this->assertSame(array(), $this->accepted($body, self::$exempt), $body);
        }
    }

    public function testAMessageListIsJoinedWithNewlines()
    {
        $client = $this->client('{"error":["a","b"]}');
        try {
            $client->getCredits();
            $this->fail('accepted');
        } catch (ZBApiException $e) {
            $this->assertSame("a\nb", $e->getMessage());
        }
        $client->body = '{"success":true,"message":["x","y"]}';
        $client->body = '{"success":false,"message":["x","y"]}';
        try {
            $client->getActivity('a@example.com');
            $this->fail('accepted');
        } catch (ZBApiException $e) {
            $this->assertSame("x\ny", $e->getMessage());
        }
    }

    public function testValidateBatchTupleWithoutAnIpSendsNoIp()
    {
        $client = $this->client('{"email_batch":[],"errors":[]}');
        $client->validateBatch(array(array('a@example.com'), array('b@example.com', '1.2.3.4')));
        $this->assertSame(
            '{"email_batch":[{"email_address":"a@example.com"},{"email_address":"b@example.com","ip_address":"1.2.3.4"}],"api_key":"errors-key"}',
            $client->sent[0]['body']
        );
    }

    public function testUsePostIsStrict()
    {
        $client = $this->client('{"Credits":"5"}');
        $client->getCredits("false");
        $client->getCredits(false);
        $this->assertSame('GET', $client->sent[0]['method']);
        $this->assertSame('GET', $client->sent[1]['method']);
        $client->getCredits("true");
        $this->assertSame('POST', $client->sent[2]['method']);
        foreach (array('junk', 2, array(), 1.5) as $bad) {
            try {
                $client->getCredits($bad);
                $this->fail('accepted');
            } catch (ZBException $e) {
                $this->assertCount(3, $client->sent);
            }
        }
        $options = new ZBValidateOptions();
        $options->usePost = 'false';
        $client->body = '{"address":"a@b.c","status":"valid"}';
        $client->validate('a@b.c', null, $options);
        $this->assertSame('GET', $client->sent[3]['method']);
    }

    public function testDatesAreRealCalendarDates()
    {
        $client = $this->client('{"total":0}');
        foreach (array("2024-01-01\n", '2024-13-45', '2024-02-30', '2024-1-01') as $bad) {
            try {
                $client->getApiUsage($bad, '2024-01-02');
                $this->fail('accepted ' . json_encode($bad));
            } catch (ZBException $e) {
                $this->assertSame(array(), $client->sent);
            }
        }
        $warmup = new ZeroBounceWarmup('errors-key');
        foreach (array("2024-01-01\n", '2024-13-45') as $bad) {
            try {
                $warmup->getStatisticsPerDay('a@example.com', $bad, '2024-01-02');
                $this->fail('accepted ' . json_encode($bad));
            } catch (ZBException $e) {
                $this->assertStringContainsString('start_date', $e->getMessage());
            }
        }
    }

    /** @return array<string, callable> */
    private function badArgumentCalls($c)
    {
        $csv = __DIR__ . '/email_file.csv';
        $calls = array();
        foreach (array(array('x'), ' ', 1.5, true, new \stdClass()) as $i => $bad) {
            $calls['validate' . $i] = function () use ($c, $bad) { $c->validate($bad); };
            $calls['fileStatus' . $i] = function () use ($c, $bad) { $c->fileStatus($bad); };
            $calls['scoringFileStatus' . $i] = function () use ($c, $bad) { $c->scoringFileStatus($bad); };
            $calls['deleteFile' . $i] = function () use ($c, $bad) { $c->deleteFile($bad); };
            $calls['getActivity' . $i] = function () use ($c, $bad) { $c->getActivity($bad); };
            $calls['scoreEmail' . $i] = function () use ($c, $bad) { $c->scoreEmail($bad); };
            $calls['scoreEmailPost' . $i] = function () use ($c, $bad) { $c->scoreEmailPost($bad); };
            $calls['findEmail' . $i] = function () use ($c, $bad) { $c->findEmail($bad, 'Ann'); };
            $calls['findEmailFormat' . $i] = function () use ($c, $bad) { $c->findEmailFormat($bad); };
            $calls['emailFinderFileStatus' . $i] = function () use ($c, $bad) { $c->emailFinderFileStatus($bad); };
            $calls['domainSearchFileStatus' . $i] = function () use ($c, $bad) { $c->domainSearchFileStatus($bad); };
            $calls['getListEvaluatorResult' . $i] = function () use ($c, $bad) { $c->getListEvaluatorResult($bad); };
            $calls['sendEvaluatedFile' . $i] = function () use ($c, $bad) { $c->sendEvaluatedFile($bad); };
            $calls['exportS3File' . $i] = function () use ($c, $bad) { $c->exportS3File($bad); };
            $calls['exportSFTPFile' . $i] = function () use ($c, $bad) { $c->exportSFTPFile($bad); };
            $calls['sendFileCol' . $i] = function () use ($c, $csv, $bad) { $c->sendFile($csv, $bad); };
            $calls['sendFileFirst' . $i] = function () use ($c, $csv, $bad) { $c->sendFile($csv, 1, null, $bad); };
            $calls['sendEmailFinderFile' . $i] = function () use ($c, $csv, $bad) { $c->sendEmailFinderFile($csv, $bad, 2); };
            $calls['sendListEvaluatorFile' . $i] = function () use ($c, $csv, $bad) { $c->sendListEvaluatorFile($csv, $bad); };
        }
        $calls['sendFile1abc'] = function () use ($c, $csv) { $c->sendFile($csv, '1abc'); };
        $calls['sendFileNeg'] = function () use ($c, $csv) { $c->sendFile($csv, -1); };
        $calls['validateTimeout'] = function () use ($c) {
            $o = new ZBValidateOptions();
            $o->timeout = 3.9;
            $c->validate('a@example.com', null, $o);
        };
        $calls['batchString'] = function () use ($c) { $c->validateBatch('str'); };
        $calls['batchMixed'] = function () use ($c) { $c->validateBatch(array('a@example.com', array('b@example.com'))); };
        $calls['batchMixed2'] = function () use ($c) { $c->validateBatch(array(array('a@example.com'), 'b@example.com')); };
        $calls['batchBlank'] = function () use ($c) { $c->validateBatch(array('a@example.com', ' ')); };
        $calls['batchFloat'] = function () use ($c) { $c->validateBatch(array(1.5)); };
        $calls['batchNested'] = function () use ($c) { $c->validateBatch(array(array(array('x')))); };
        return $calls;
    }

    public function testBadArgumentsRaiseTheSdkExceptionBeforeSending()
    {
        $client = $this->client('{}');
        foreach ($this->badArgumentCalls($client) as $label => $call) {
            try {
                $this->quietly($call);
                $this->fail($label . ' accepted');
            } catch (ZBException $e) {
                $this->assertNotSame('', $e->getMessage(), $label);
            }
        }
        $this->assertSame(array(), $client->sent);
    }

    public function testWarmupRejectsNonTextArguments()
    {
        $warmup = new ZeroBounceWarmup('errors-key');
        foreach (array(array('x'), ' ', new \stdClass()) as $bad) {
            try {
                $warmup->getStatistics($bad);
                $this->fail('accepted');
            } catch (ZBException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}

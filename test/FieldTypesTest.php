<?php

namespace ZeroBounce\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBounce\SDK\ZBApiException;
use ZeroBounce\SDK\ZBResponse;
use ZeroBounce\SDK\ZBValidateOptions;

require_once __DIR__ . '/RequestSweep.php';
require_once __DIR__ . '/TransportGuardsTest.php';

/**
 * E7: field types are part of the response shape (standard.md). On a typed
 * call a field of the wrong JSON type, or a required field that is missing,
 * is an API error with status 200: never coerced or defaulted. null is fine
 * for optional fields; every documented example response still parses.
 * E2: a 200 dead-key body is an error with status 200 on every call.
 */
class FieldTypesTest extends TestCase
{
    use RequestSweep;

    /** Calls that return the API's JSON as is (raw-JSON calls are exempt). */
    private static $rawCalls = array(
        'addFilter', 'deleteFilter', 'listFilters', 'listS3Files',
        'getInboxPlacementProviders', 'getInboxPlacementTestTypes', 'createInboxPlacementTest',
        'getInboxPlacementTest', 'listInboxPlacementTests', 'deleteInboxPlacementTest',
        'getInboxPlacementWebhook', 'setInboxPlacementWebhook',
    );

    private static $downloads = array('getFile', 'scoringGetFile', 'emailFinderGetFile', 'domainSearchGetFile');

    /** Properties the SDK fills itself, never from a JSON field. */
    private static $sdkProperties = array(
        'ZBGetFileResponse' => array('localFilePath'),
        'ZBListEvaluatorResponse' => array('data'),
    );

    /** @var string */
    private $dir;

    /** @var int how many call variants the last sweep made */
    private $swept = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/zb-fieldtypes-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: array() as $name) {
            if (is_file($this->dir . '/' . $name)) {
                unlink($this->dir . '/' . $name);
            }
        }
        @rmdir($this->dir);
    }

    /** @return ZBResponse[] one of every response model, by short class name */
    private static function models()
    {
        $models = array();
        foreach (glob(__DIR__ . '/../src/ZB*Response.php') as $file) {
            $class = 'ZeroBounce\\SDK\\' . basename($file, '.php');
            if ($class !== ZBResponse::class && $class !== 'ZeroBounce\\SDK\\ZBRawJsonResponse'
                && is_subclass_of($class, ZBResponse::class)) {
                $models[basename($file, '.php')] = new $class();
            }
        }
        return $models;
    }

    private static function call($object, $method, array $args = array())
    {
        $reflection = new \ReflectionMethod($object, $method);
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        return $reflection->invokeArgs($object, $args);
    }

    /** @return array the required fields of $model with valid values */
    private static function minimal(ZBResponse $model)
    {
        $data = array();
        $types = self::call($model, 'fieldTypes');
        foreach (self::call($model, 'requiredFields', array(array())) as $name) {
            $data[$name] = in_array('string', $types[$name], true) ? '5' : 5;
        }
        return $data;
    }

    /** @return array every decoded JSON value of a type $kinds does not allow */
    private static function wrongValues(array $kinds)
    {
        $wrong = array();
        foreach (array(array('a' => 1), 'x', true, 5, 5.5, array(1)) as $value) {
            if (is_array($value) && in_array('array', $kinds, true)) {
                continue; // an array's rows are checked by itemShapes, not here
            }
            if (!ZBResponse::jsonTypeOk($value, $kinds)) {
                $wrong[] = $value;
            }
        }
        return $wrong;
    }

    public function testTheWalkFindsTheModels()
    {
        $names = array_keys(self::models());
        foreach (array('ZBGetCreditsResponse', 'ZBValidateResponse', 'ZBBatchValidateResponse', 'ZBFileStatusResponse',
            'ZBSendFileResponse', 'ZBDeleteFileResponse', 'ZBGuessFormatResponse', 'ZBFindEmailResponse',
            'ZBFindEmailFormatResponse', 'ZBActivityResponse', 'ZBApiUsageResponse', 'ZBScoreEmailResponse',
            'ZBListEvaluatorResponse') as $name) {
            $this->assertContains($name, $names);
        }
    }

    public function testEveryPropertyOfEveryModelHasAJsonType()
    {
        $missing = array();
        foreach (self::models() as $name => $model) {
            $typed = isset(self::$sdkProperties[$name]) ? self::$sdkProperties[$name] : array();
            foreach (array_keys(self::call($model, 'fieldTypes')) as $key) {
                $typed[] = $model->getClassKey($key);
            }
            foreach ((new \ReflectionClass($model))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if (!$property->isStatic() && !in_array($property->getName(), $typed, true)) {
                    $missing[] = $name . '::' . $property->getName();
                }
            }
        }
        $this->assertSame(array(), $missing);
    }

    public function testEveryFieldRejectsEveryOtherJsonType()
    {
        $accepted = array();
        foreach (self::models() as $name => $model) {
            foreach (self::call($model, 'fieldTypes') as $field => $kinds) {
                foreach (self::wrongValues($kinds) as $wrong) {
                    try {
                        $model->checkShape(array_merge(self::minimal($model), array($field => $wrong)));
                        $accepted[] = "$name.$field " . json_encode($wrong);
                    } catch (ZBApiException $e) {
                        $this->assertSame(200, $e->getStatusCode());
                        $this->assertStringNotContainsString(json_encode($wrong), $e->getMessage());
                    }
                }
            }
        }
        $this->assertSame(array(), $accepted);
    }

    public function testNullIsFineForEveryOptionalField()
    {
        foreach (self::models() as $name => $model) {
            $required = self::call($model, 'requiredFields', array(array()));
            foreach (array_keys(self::call($model, 'fieldTypes')) as $field) {
                if (!in_array($field, $required, true)) {
                    $model->checkShape(array_merge(self::minimal($model), array($field => null)));
                }
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testArrayRowsAreChecked()
    {
        foreach (self::models() as $name => $model) {
            foreach (self::call($model, 'itemShapes') as $field => $shape) {
                $rowTypes = $shape instanceof ZBResponse ? self::call($shape, 'fieldTypes') : $shape;
                $row = $shape instanceof ZBResponse ? self::minimal($shape) : array();
                $row[array_keys($rowTypes)[0]] = array('a' => 1);
                foreach (array(array(5), array($row)) as $rows) {
                    try {
                        $model->checkShape(array_merge(self::minimal($model), array($field => $rows)));
                        $this->fail("$name.$field accepted " . json_encode($rows));
                    } catch (ZBApiException $e) {
                        $this->assertSame(200, $e->getStatusCode());
                    }
                }
            }
        }
    }

    /** @return string[] what each typed call does with $body, unless it is an error with status 200 */
    private function sweep($body)
    {
        $client = new CannedFileClient(200, 'application/json', $body);
        $client->initialize('fieldtypes-key');
        $wrong = array();
        $this->swept = 0;
        foreach ($this->sweepCalls($client, $this->dir . '/result.csv') as $name => $variants) {
            if (in_array($name, self::$downloads, true) || in_array($name, self::$rawCalls, true)
                || strpos($name, 'download') === 0) {
                continue;
            }
            foreach ($variants as $i => $call) {
                $this->swept++;
                try {
                    $this->quietly($call);
                    $wrong[] = "$name#$i accepted";
                } catch (ZBApiException $e) {
                    if ($e->getStatusCode() !== 200) {
                        $wrong[] = "$name#$i status " . $e->getStatusCode();
                    }
                } catch (\Throwable $e) {
                    $wrong[] = "$name#$i " . get_class($e);
                }
            }
        }
        return $wrong;
    }

    public function testEveryTypedCallChecksItsFields()
    {
        $fields = array();
        foreach (self::models() as $model) {
            $fields += self::call($model, 'fieldTypes');
        }
        // The error and success keys have their own checks (E2, E4); here only field types count.
        foreach (array('error', 'Error', 'error_message', 'message', 'Message', 'success') as $key) {
            unset($fields[$key]);
        }
        $good = array_fill_keys(array_keys($fields), null);
        $good['Credits'] = '5';
        $good['address'] = 'a@example.com';
        $good['status'] = 'valid';
        $this->assertSame(array(), array_filter($this->sweep(json_encode($good)), function ($line) {
            return strpos($line, 'accepted') === false;
        }), 'the valid body is accepted');
        $bad = array_fill_keys(array_keys($fields), array('a' => 1));
        $this->assertSame(array(), $this->sweep(json_encode($bad)));
        $this->assertGreaterThan(40, $this->swept);
        $names = array_keys($this->sweepCalls(new CannedFileClient(200, 'application/json', '{}'), $this->dir . '/r.csv'));
        foreach (self::$rawCalls as $raw) {
            $this->assertContains($raw, $names);
        }
    }

    private function errorFor(callable $call, $body)
    {
        try {
            $call();
        } catch (ZBApiException $e) {
            $this->assertSame(200, $e->getStatusCode(), $body);
            $this->assertStringContainsString('unexpected shape', $e->getMessage(), $body);
            return;
        }
        $this->fail('accepted ' . $body);
    }

    public function testCreditsMustBeAStringHoldingAnInteger()
    {
        $client = new CannedFileClient(200, 'application/json', '');
        $client->initialize('fieldtypes-key');
        foreach (array(false, true) as $post) {
            $call = function () use ($client, $post) { return $client->getCredits($post); };
            foreach (array('{}', '{"Credits":null}', '{"Credits":true}', '{"Credits":5}', '{"Credits":-1}',
                '{"Credits":"abc"}', '{"Credits":"5.0"}', '{"Credits":""}', '{"Credits":" 5"}', '{"Credits":"5\n"}',
                '{"Credits":[1]}', '{"Credits":{"a":1}}') as $body) {
                $client->answer['body'] = $body;
                $this->errorFor($call, $body);
            }
            $client->answer['body'] = '{"Credits":"-1"}';
            $this->assertSame('-1', $call()->credits);
            $client->answer['body'] = '{"Credits":"12345"}';
            $this->assertSame('12345', $call()->credits);
        }
    }

    public function testValidateNeedsAStringAddressAndStatus()
    {
        $client = new CannedFileClient(200, 'application/json', '');
        $client->initialize('fieldtypes-key');
        $post = new ZBValidateOptions();
        $post->usePost = true;
        foreach (array(null, $post) as $options) {
            $call = function () use ($client, $options) { return $client->validate('a@example.com', null, $options); };
            foreach (array('{"address":true,"status":"valid"}', '{"address":"a@b.c","status":5}', '{"address":"a@b.c"}',
                '{"status":"valid"}', '{"address":null,"status":"valid"}', '{}',
                '{"address":"a@b.c","status":"valid","free_email":5}',
                '{"address":"a@b.c","status":"valid","domain_age_days":9692}') as $body) {
                $client->answer['body'] = $body;
                $this->errorFor($call, $body);
            }
            $client->answer['body'] = '{"address":"a@b.c","status":"brand_new"}';
            $this->assertSame('brand_new', $call()->status);
        }
    }

    public function testBatchRowsAreChecked()
    {
        $client = new CannedFileClient(200, 'application/json', '');
        $client->initialize('fieldtypes-key');
        $call = function () use ($client) { return $client->validateBatch(array('a@example.com')); };
        foreach (array('{"email_batch":[{"address":"a@b.c","status":5}],"errors":[]}',
            '{"email_batch":[{"status":"valid"}],"errors":[]}',
            '{"email_batch":[],"errors":[{"error":5,"email_address":"a@b.c"}]}') as $body) {
            $client->answer['body'] = $body;
            $this->errorFor($call, $body);
        }
    }

    /** Documented example responses (zerobounce.net/docs) and the live-recorded shapes. */
    private static $documented = array(
        'getCredits' => '{"Credits":"2375323"}',
        'validate' => '{"address":"flowerjill@aol.com","status":"valid","sub_status":"","free_email":true,"did_you_mean":null,"account":"flowerjill","domain":"aol.com","domain_age_days":"9692","active_in_days":"180","smtp_provider":"yahoo","mx_found":"true","mx_record":"mx-aol.mail.gm0.yahoodns.net","firstname":"Jill","lastname":"Stein","gender":"female","country":"United States","region":"Florida","city":"West Palm Beach","zipcode":"33401","processed_at":"2017-04-01 02:48:02.592"}',
        'validateGreylist' => '{"Message":"Greylist validation still in progress..."}',
        'validateBatch' => '{"email_batch":[{"address":"valid@example.com","status":"valid","sub_status":"","free_email":false,"did_you_mean":null,"account":null,"domain":null,"domain_age_days":"9692","active_in_days":"180","smtp_provider":"example","mx_found":"true","mx_record":"mx.example.com","firstname":"zero","lastname":"bounce","gender":"male","country":null,"region":null,"city":null,"zipcode":null,"processed_at":"2020-09-17 17:43:11.829"}],"errors":[]}',
        'getApiUsage' => '{"total":3,"status_valid":1,"status_invalid":2,"status_catch_all":0,"status_do_not_mail":0,"status_spamtrap":0,"status_unknown":0,"sub_status_toxic":0,"sub_status_disposable":0,"sub_status_role_based":0,"sub_status_possible_trap":0,"sub_status_global_suppression":0,"sub_status_timeout_exceeded":0,"sub_status_mail_server_temporary_error":0,"sub_status_mail_server_did_not_respond":0,"sub_status_greylisted":0,"sub_status_antispam_system":0,"sub_status_does_not_accept_mail":0,"sub_status_exception_occurred":0,"sub_status_failed_syntax_check":0,"sub_status_mailbox_not_found":2,"sub_status_unroutable_ip_address":0,"sub_status_possible_typo":0,"sub_status_no_dns_entries":0,"sub_status_role_based_catch_all":0,"sub_status_mailbox_quota_exceeded":0,"sub_status_forcible_disconnect":0,"sub_status_failed_smtp_connection":0,"sub_status_mx_forward":0,"sub_status_alternate":0,"sub_status_blocked":0,"sub_status_allowed":0,"start_date":"4/1/2018","end_date":"9/9/2019"}',
        'getActivity' => '{"found":true,"active_in_days":"180"}',
        'scoreEmail' => '{"email":"valid@example.com","score":10}',
        'sendFile' => '{"success":true,"message":"File Accepted","file_name":"Your file name.csv","file_id":"aaaaaaaa-zzzz-xxxx-yyyy-5003727fffff"}',
        'fileStatus' => '{"success":true,"file_id":"84946fc8-3f89-428a-9d0d-382fbe18cb24","file_name":"validation.csv","upload_date":"2025-10-16T08:28:37Z","file_status":"Processing","complete_percentage":"0%","error_reason":null,"return_url":null}',
        'deleteFile' => '{"success":true,"message":"File Deleted","file_name":"test2","file_id":"b222a0fd-90d5-416c-8f1a-9cc3851fc823"}',
        'guessFormat' => '{"email":"john.doe@example.com","domain":"example.com","format":"first.last","status":"valid","sub_status":"","confidence":"high","did_you_mean":"","failure_reason":"","other_domain_formats":[{"format":"first_last","confidence":"high"},{"format":"first","confidence":"medium"}]}',
        'findEmail' => '{"email":"john.doe@example.com","email_confidence":"HIGH","domain":"example.com","company_name":"Example","did_you_mean":"","failure_reason":""}',
        'findEmailFormat' => '{"domain":"example.com","company_name":"Example","format":"first.last","confidence":"high","did_you_mean":"","failure_reason":"","other_domain_formats":[{"format":"first","confidence":"low"}]}',
        'getListEvaluatorResult' => '{"file_id":"ev-1","status":"finished","progress":100,"invalid_percentage":4.2,"catch_all_percentage":0,"activity_data_percentage":0,"abuse":true,"do_not_mail":false,"spam_trap":false,"total_risky_percentage":0,"error_message":""}',
    );

    public function testEveryDocumentedExampleParses()
    {
        $client = new CannedFileClient(200, 'application/json', '');
        $client->initialize('fieldtypes-key');
        $calls = $this->sweepCalls($client, $this->dir . '/result.csv');
        foreach (self::$documented as $example => $body) {
            $name = $example === 'validateGreylist' ? 'validate' : $example;
            $this->assertArrayHasKey($name, $calls, $example);
            $client->answer['body'] = $body;
            foreach ($calls[$name] as $call) {
                $this->quietly($call);
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testEveryCallReportsADeadKeyWithStatus200()
    {
        $uploads = array('sendFile', 'scoringSendFile', 'sendFileStream', 'scoringSendFileStream', 'sendEmailFinderFile',
            'sendEmailFinderFileStream', 'sendDomainSearchFile', 'sendDomainSearchFileStream', 'sendListEvaluatorFile',
            'sendListEvaluatorFileStream');
        $names = array_keys($this->sweepCalls(new CannedFileClient(200, 'application/json', '{}'), $this->dir . '/r.csv'));
        foreach ($uploads as $upload) {
            $this->assertContains($upload, $names);
        }
        foreach (array('{"error":"Invalid API key or your account ran out of credits"}', '{"error_message":"Invalid API key"}',
            '{"Error":"Invalid API key or your account ran out of credits"}') as $body) {
            $client = new CannedFileClient(200, 'application/json', $body);
            $client->initialize('fieldtypes-key');
            $wrong = array();
            foreach ($this->sweepCalls($client, $this->dir . '/result.csv') as $name => $variants) {
                foreach ($variants as $i => $call) {
                    try {
                        $this->quietly($call);
                        $wrong[] = "$name#$i accepted";
                    } catch (ZBApiException $e) {
                        if ($e->getStatusCode() !== 200 || strpos($e->getMessage(), 'Invalid API key') === false) {
                            $wrong[] = "$name#$i " . $e->getStatusCode() . ' ' . $e->getMessage();
                        }
                    }
                }
            }
            $this->assertSame(array(), $wrong, $body);
        }
    }
}

<?php

namespace ZeroBounce\Tests;

use DateTime;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ZeroBounce\SDK\ZBApiException;
use ZeroBounce\SDK\ZBBaseUrl;
use ZeroBounce\SDK\ZBDownloadType;
use ZeroBounce\SDK\ZBException;
use ZeroBounce\SDK\ZBGetFileOptions;
use ZeroBounce\SDK\ZBMissingApiKeyException;
use ZeroBounce\SDK\ZBMissingParameterException;
use ZeroBounce\SDK\ZBValidateOptions;
use ZeroBounce\SDK\ZeroBounce;

require_once __DIR__ . '/OfflineHttp.php';

class OfflineApiTest extends TestCase
{
    /** @var string */
    private $csv;

    protected function setUp(): void
    {
        $this->csv = __DIR__ . '/email_file.csv';
    }

    protected function tearDown(): void
    {
        $ref = new ReflectionClass(ZeroBounce::class);
        if ($ref->hasProperty('ApiBaseUrl')) {
            $prop = $ref->getProperty('ApiBaseUrl');
            if (\PHP_VERSION_ID < 80100) {
                $prop->setAccessible(true);
            }
            if ($prop->isStatic()) {
                $prop->setValue(null, ZBBaseUrl::API_DEFAULT_URL);
            }
        }
    }

    public function testGetCreditsReachesEndpoint()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'credits-key');
        $response = $client->getCredits();
        $this->assertSame('7', (string)$response->credits);
        $request = $this->only($api);
        $this->assertSame('GET', $request['method']);
        $this->assertSame('api.zerobounce.net', $request['host']);
        $this->assertSame('/v2/getcredits', $request['path']);
        $this->assertSame('credits-key', $request['api_key']);
        $this->assertSame(120, $request['timeout']);
        $this->assertNoUserAgentOrMatchingVersion($request);
    }

    public function testValidateOmitsUnsetOptionsAndEncodesFalse()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'validate-key');
        $options = new ZBValidateOptions();
        $options->activityData = false;
        $options->verifyPlus = true;
        $response = $client->validate('person@example.com', '', $options);
        $this->assertSame('validate-key', $response->address);
        $request = $this->only($api);
        $this->assertSame('GET', $request['method']);
        $this->assertSame('/v2/validate', $request['path']);
        $this->assertSame('api.zerobounce.net', $request['host']);
        $this->assertSame('person@example.com', $request['fields']['email']);
        $this->assertSame('', $request['fields']['ip_address']);
        $this->assertSame('false', $request['fields']['activity_data']);
        $this->assertSame('true', $request['fields']['verify_plus']);
        $this->assertArrayNotHasKey('timeout', $request['fields']);
    }

    public function testValidatePostFormTimeoutAndZeroIp()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'post-key');
        $options = new ZBValidateOptions();
        $options->usePost = true;
        $options->timeout = 15;
        $client->validate('person@example.com', '0', $options);
        $request = $this->only($api);
        $this->assertSame('POST', $request['method']);
        $this->assertSame('api.zerobounce.net', $request['host']);
        $this->assertSame('/v2/validate', $request['path']);
        $this->assertSame('application/x-www-form-urlencoded', $request['content_type']);
        $this->assertSame('post-key', $request['fields']['api_key']);
        $this->assertSame('person@example.com', $request['fields']['email']);
        $this->assertSame('0', $request['fields']['ip_address']);
        $this->assertSame('15', $request['fields']['timeout']);
        $this->assertArrayNotHasKey('activity_data', $request['fields']);
        $this->assertArrayNotHasKey('verify_plus', $request['fields']);
    }

    public function testValidateEncodesQueryValues()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'encode-key');
        $client->validate('a+b c@example.com', '203.0.113.5');
        $request = $this->only($api);
        $this->assertSame('a+b c@example.com', $request['fields']['email']);
        $this->assertStringContainsString('email=a%2Bb+c%40example.com', $request['url']);
        $this->assertStringNotContainsString('a+b c@example.com', $request['url']);
    }

    public function testValidateBatchUsesApiHostAndOmitsUnsetOptions()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'batch-key');
        $response = $client->validateBatch(array(
            array('a@example.com', ''),
            array('b@example.com', '203.0.113.9'),
        ));
        $this->assertSame('batch-key', $response->emailBatch[0]->address);
        $request = $this->only($api);
        $this->assertSame('POST', $request['method']);
        $this->assertSame('api.zerobounce.net', $request['host']);
        $this->assertSame('/v2/validatebatch', $request['path']);
        $this->assertStringNotContainsString('bulkapi', $request['url']);
        $this->assertSame('batch-key', $request['fields']['api_key']);
        $this->assertStringContainsString('"email_address":"a@example.com"', $request['fields']['__json']);
        $this->assertStringContainsString('"ip_address":""', $request['fields']['__json']);
        $this->assertStringContainsString('"ip_address":"203.0.113.9"', $request['fields']['__json']);
        $this->assertArrayNotHasKey('timeout', $request['fields']);
        $this->assertArrayNotHasKey('activity_data', $request['fields']);
        $this->assertArrayNotHasKey('verify_plus', $request['fields']);

        $api->clear();
        $client->validateBatch(array('only@example.com'));
        $strings = $this->only($api);
        $this->assertStringContainsString('"email_address":"only@example.com"', $strings['fields']['__json']);
        $this->assertStringNotContainsString('ip_address', $strings['fields']['__json']);
    }

    public function testValidateBatchSendsExplicitOptions()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'batch-opt');
        $options = new ZBValidateOptions();
        $options->timeout = 30;
        $options->activityData = false;
        $options->verifyPlus = true;
        $client->validateBatch(array('a@example.com'), $options);
        $request = $this->only($api);
        // LIVE: the API requires timeout as a JSON string.
        $this->assertStringContainsString('"timeout":"30"', $request['fields']['__json']);
        $this->assertStringContainsString('"activity_data":false', $request['fields']['__json']);
        $this->assertStringContainsString('"verify_plus":true', $request['fields']['__json']);
        $this->assertStringNotContainsString('"activity_data":"false"', $request['fields']['__json']);
    }

    public function testSendFileEncodesBooleansAndKeepsZeroColumn()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'file-key');
        $response = $client->sendFile(
            $this->csv,
            0,
            'https://example.com/hook',
            0,
            null,
            null,
            null,
            false,
            false
        );
        $this->assertSame('file-key', $response->fileId);
        $request = $this->only($api);
        $this->assertSame('POST', $request['method']);
        $this->assertSame('bulkapi.zerobounce.net', $request['host']);
        $this->assertSame('/v2/sendfile', $request['path']);
        $this->assertSame('file-key', $request['fields']['api_key']);
        $this->assertSame('0', (string)$request['fields']['email_address_column']);
        $this->assertSame('0', (string)$request['fields']['first_name_column']);
        $this->assertSame('<file>', $request['fields']['file']);
        $this->assertSame('false', $request['fields']['has_header_row']);
        $this->assertSame('false', $request['fields']['allow_phase_2']);
        $this->assertSame('https://example.com/hook', $request['fields']['return_url']);
        $this->assertArrayNotHasKey('last_name_column', $request['fields']);
        $this->assertArrayNotHasKey('gender_column', $request['fields']);
        $this->assertArrayNotHasKey('ip_address_column', $request['fields']);
        $this->assertStringNotContainsString('1', $request['fields']['has_header_row']);
    }

    public function testScoringSendFileOmitsValidationOnlyFields()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'score-file');
        $client->scoringSendFile($this->csv, 1, null, false);
        $request = $this->only($api);
        $this->assertSame('/v2/scoring/sendfile', $request['path']);
        $this->assertSame('bulkapi.zerobounce.net', $request['host']);
        $this->assertSame('1', (string)$request['fields']['email_address_column']);
        $this->assertSame('false', $request['fields']['has_header_row']);
        $this->assertArrayNotHasKey('allow_phase_2', $request['fields']);
        $this->assertArrayNotHasKey('first_name_column', $request['fields']);

        $api->clear();
        $client->scoringSendFileStream("a@b.com\n", 'scores.csv', 1, null, null);
        $stream = $this->only($api);
        $this->assertArrayNotHasKey('has_header_row', $stream['fields']);
        $this->assertSame('<file>', $stream['fields']['file']);
        $this->assertSame('scores.csv', $stream['fields']['__filename']);
    }

    public function testSendFileStreamKeepsZeroColumn()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'stream-key');
        $client->sendFileStream("a@b.com\n", 'emails.csv', 0, null, null, null, null, null, true, null);
        $request = $this->only($api);
        $this->assertSame('/v2/sendfile', $request['path']);
        $this->assertSame('0', (string)$request['fields']['email_address_column']);
        $this->assertSame('true', $request['fields']['has_header_row']);
        $this->assertArrayNotHasKey('allow_phase_2', $request['fields']);
    }

    public function testFileStatusAndDeleteUseBulkHost()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'status-key');
        $status = $client->fileStatus('file 1');
        $this->assertSame('status-key', $status->fileId);
        $request = $this->only($api);
        $this->assertSame('/v2/filestatus', $request['path']);
        $this->assertSame('bulkapi.zerobounce.net', $request['host']);
        $this->assertSame('file 1', $request['fields']['file_id']);
        $this->assertStringContainsString('file_id=file+1', $request['url']);

        $api->clear();
        $client->scoringFileStatus('file-2');
        $this->assertSame('/v2/scoring/filestatus', $this->only($api)['path']);

        $api->clear();
        $client->deleteFile('file-1');
        $this->assertSame('/v2/deletefile', $this->only($api)['path']);

        $api->clear();
        $client->scoringDeleteFile('file-2');
        $this->assertSame('/v2/scoring/deletefile', $this->only($api)['path']);
    }

    public function testGetFileWithOptionsParameterOrder()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'getfile-key');
        $path = $this->tempPath('offline-get.csv');
        $options = new ZBGetFileOptions();
        $options->downloadType = ZBDownloadType::PHASE_2;
        $options->activityData = false;
        $response = $client->getFile('file-9', $path, $options);
        $body = file_get_contents($response->localFilePath);
        $this->assertStringContainsString('getfile-key@example.com', $body);
        $this->assertStringNotContainsString('<html', $body);
        $request = $this->only($api);
        $this->assertSame('/v2/getfile', $request['path']);
        $this->assertSame('bulkapi.zerobounce.net', $request['host']);
        $this->assertSame('file-9', $request['fields']['file_id']);
        $this->assertSame('phase_2', $request['fields']['download_type']);
        $this->assertSame('false', $request['fields']['activity_data']);
        @unlink($path);

        $api->clear();
        $scorePath = $this->tempPath('offline-score.csv');
        $scoring = new ZBGetFileOptions();
        $scoring->downloadType = ZBDownloadType::PHASE_1;
        $scoring->activityData = true;
        $client->scoringGetFile('file-8', $scorePath, $scoring);
        $scoringRequest = $this->only($api);
        $this->assertSame('/v2/scoring/getfile', $scoringRequest['path']);
        $this->assertSame('phase_1', $scoringRequest['fields']['download_type']);
        $this->assertArrayNotHasKey('activity_data', $scoringRequest['fields']);
        @unlink($scorePath);
    }

    public function testActivityUsageAndScoreEndpoints()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'usage-key');
        $usage = $client->getApiUsage(new DateTime('2018-01-01'), new DateTime('2018-01-31'));
        $this->assertSame(1, (int)$usage->total);
        $request = $this->only($api);
        $this->assertSame('/v2/getapiusage', $request['path']);
        $this->assertSame('2018-01-01', $request['fields']['start_date']);
        $this->assertSame('2018-01-31', $request['fields']['end_date']);

        $api->clear();
        $activity = $client->getActivity('person@example.com');
        $this->assertTrue((bool)$activity->found);
        $activityRequest = $this->only($api);
        $this->assertSame('/v2/activity', $activityRequest['path']);
        $this->assertSame('person@example.com', $activityRequest['fields']['email']);

        $api->clear();
        $score = $client->scoreEmail('person@example.com');
        $this->assertSame('usage-key', $score->email);
        $scoreRequest = $this->only($api);
        $this->assertSame('GET', $scoreRequest['method']);
        $this->assertSame('/v2/scoring', $scoreRequest['path']);
        $this->assertSame('api.zerobounce.net', $scoreRequest['host']);

        $api->clear();
        $client->scoreEmailPost('person@example.com');
        $posted = $this->only($api);
        $this->assertSame('POST', $posted['method']);
        $this->assertSame('/v2/scoring', $posted['path']);
        $this->assertSame('person@example.com', $posted['fields']['email']);
        $this->assertSame('usage-key', $posted['fields']['api_key']);
    }

    public function testFinderAndDomainSearch()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'finder-key');
        $byDomain = $client->findEmail('example.com', 'Ada', null, 'M', 'Lovelace');
        $this->assertSame('finder-key', $byDomain->email);
        $request = $this->only($api);
        $this->assertSame('/v2/guessformat', $request['path']);
        $this->assertSame('example.com', $request['fields']['domain']);
        $this->assertSame('Ada', $request['fields']['first_name']);
        $this->assertSame('M', $request['fields']['middle_name']);
        $this->assertSame('Lovelace', $request['fields']['last_name']);
        $this->assertArrayNotHasKey('company_name', $request['fields']);

        $api->clear();
        $client->findEmail(null, 'Ada', 'ZeroBounce');
        $byCompany = $this->only($api);
        $this->assertSame('ZeroBounce', $byCompany['fields']['company_name']);
        $this->assertSame('Ada', $byCompany['fields']['first_name']);
        $this->assertArrayNotHasKey('domain', $byCompany['fields']);
        $this->assertArrayNotHasKey('last_name', $byCompany['fields']);

        $api->clear();
        $format = $client->findEmailFormat('example.com');
        $this->assertSame('finder-key', $format->domain);
        $this->assertArrayNotHasKey('company_name', $this->only($api)['fields']);

        $api->clear();
        $client->findEmailFormat(null, 'ZeroBounce');
        $company = $this->only($api);
        $this->assertSame('ZeroBounce', $company['fields']['company_name']);
        $this->assertArrayNotHasKey('domain', $company['fields']);

    }

    /**
     * @testdox guessFormat still calls /v2/guessformat
     */
    #[\PHPUnit\Framework\Attributes\IgnoreDeprecations]
    public function testGuessFormatStillReachesTheEndpoint()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'finder-key');
        $client->guessFormat('example.com', 'Ada', null, null);
        $guess = $this->only($api);
        $this->assertSame('/v2/guessformat', $guess['path']);
        $this->assertSame('Ada', $guess['fields']['first_name']);
        $this->assertArrayNotHasKey('middle_name', $guess['fields']);
        $this->assertArrayNotHasKey('last_name', $guess['fields']);
    }

    public function testPriority3EndpointsAndZeroColumn()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'p3-key');
        $client->sendEmailFinderFile($this->csv, 0, 0, null, null, null, false);
        $sent = $this->only($api);
        $this->assertSame('bulkapi.zerobounce.net', $sent['host']);
        $this->assertSame('/email-finder/sendfile', $sent['path']);
        $this->assertSame('0', (string)$sent['fields']['domain_column']);
        $this->assertSame('0', (string)$sent['fields']['first_name_column']);
        $this->assertSame('false', $sent['fields']['has_header_row']);
        $this->assertArrayNotHasKey('last_name_column', $sent['fields']);
        $this->assertSame('p3-key', $sent['fields']['api_key']);

        $api->clear();
        $client->sendEmailFinderFileStream("acme.com\n", 'finder.csv', 1, null, null, null, null, null);
        $finderStream = $this->only($api);
        $this->assertSame('/email-finder/sendfile', $finderStream['path']);
        $this->assertArrayNotHasKey('has_header_row', $finderStream['fields']);
        $this->assertSame('finder.csv', $finderStream['fields']['__filename']);

        $api->clear();
        $client->emailFinderFileStatus('fid');
        $this->assertSame('/email-finder/filestatus', $this->only($api)['path']);
        $api->clear();
        $finderPath = $this->tempPath('finder.csv');
        $client->emailFinderGetFile('fid', $finderPath);
        $this->assertSame('/email-finder/getfile', $this->only($api)['path']);
        $this->assertStringContainsString('p3-key@example.com', file_get_contents($finderPath));
        @unlink($finderPath);
        $api->clear();
        $client->emailFinderDeleteFile('fid');
        $this->assertSame('/email-finder/deletefile', $this->only($api)['path']);

        $api->clear();
        $client->sendDomainSearchFile($this->csv, 2, false);
        $domain = $this->only($api);
        $this->assertSame('/domain-search/sendfile', $domain['path']);
        $this->assertSame('2', (string)$domain['fields']['domain_column']);
        $this->assertSame('false', $domain['fields']['has_header_row']);

        $api->clear();
        $client->sendDomainSearchFileStream("acme.com\n", 'domains.csv', 1, null);
        $this->assertSame('/domain-search/sendfile', $this->only($api)['path']);
        $api->clear();
        $client->domainSearchFileStatus('fid');
        $this->assertSame('/domain-search/filestatus', $this->only($api)['path']);
        $api->clear();
        $domainPath = $this->tempPath('domain.csv');
        $client->domainSearchGetFile('fid', $domainPath);
        $this->assertSame('/domain-search/getfile', $this->only($api)['path']);
        @unlink($domainPath);
        $api->clear();
        $client->domainSearchDeleteFile('fid');
        $this->assertSame('/domain-search/deletefile', $this->only($api)['path']);

        $api->clear();
        $client->sendListEvaluatorFile($this->csv, 0);
        $evaluator = $this->only($api);
        $this->assertSame('/v2/listevaluator/', $evaluator['path']);
        $this->assertSame('0', (string)$evaluator['fields']['email_address_column']);

        $api->clear();
        $client->sendListEvaluatorFileStream("a@b.com\n", 'list.csv', 1);
        $this->assertSame('/v2/listevaluator/', $this->only($api)['path']);

        $api->clear();
        $raw = $client->getListEvaluatorResult('a/b');
        $this->assertSame('p3-key', $raw['echo_api_key']);
        $result = $this->only($api);
        $this->assertSame('/v2/listevaluator/a%2Fb/', $result['path']);
        $this->assertSame('p3-key', $result['fields']['api_key']);

        $api->clear();
        // LIVE: this takes the evaluator's file_id as a form field, not an upload.
        $client->sendEvaluatedFile('eval-file-1');
        $evaluated = $this->only($api);
        $this->assertSame('/v2/send-evaluated-file/', $evaluated['path']);
        $this->assertSame('eval-file-1', $evaluated['fields']['file_id']);

        $api->clear();
        $added = $client->addFilter('exclude', 'domain', '0');
        $this->assertSame('p3-key', $added['echo_api_key']);
        $add = $this->only($api);
        $this->assertSame('POST', $add['method']);
        $this->assertSame('api.zerobounce.net', $add['host']);
        $this->assertSame('/v2/filters/add', $add['path']);
        $this->assertSame('exclude', $add['fields']['rule']);
        $this->assertSame('domain', $add['fields']['target']);
        $this->assertSame('0', $add['fields']['value']);

        $api->clear();
        $listed = $client->listFilters(false);
        $this->assertSame('p3-key', $listed['echo_api_key']);
        $list = $this->only($api);
        $this->assertSame('GET', $list['method']);
        $this->assertSame('/v2/filters/list', $list['path']);

        $api->clear();
        $client->listFilters(true);
        $this->assertSame('POST', $this->only($api)['method']);

        $api->clear();
        $client->deleteFilter('exclude', 'domain', 'example.com');
        $this->assertSame('/v2/filters/delete', $this->only($api)['path']);

        $api->clear();
        $client->validateS3File(array(
            'file_name' => 'inbox.csv',
            'email_address_column' => 0,
            'has_header_row' => false,
            'remove_duplicate' => true,
            'first_name_column' => 0,
        ));
        $validated = $this->only($api);
        $this->assertSame('POST', $validated['method']);
        $this->assertSame('/validateS3file', $validated['path']);
        $this->assertSame('bulkapi.zerobounce.net', $validated['host']);
        $this->assertSame('inbox.csv', $validated['fields']['file_name']);
        $this->assertSame('0', (string)$validated['fields']['email_address_column']);
        $this->assertSame('false', $validated['fields']['has_header_row']);
        $this->assertSame('true', $validated['fields']['remove_duplicate']);
        $this->assertSame('0', (string)$validated['fields']['first_name_column']);
        $this->assertArrayNotHasKey('remove_duplicates', $validated['fields']);
        $this->assertArrayNotHasKey('allow_phase_2', $validated['fields']);

        $api->clear();
        $client->scoreS3File(array('file_name' => 'inbox.csv'));
        $scored = $this->only($api);
        $this->assertSame('/scoreS3file', $scored['path']);
        $this->assertArrayNotHasKey('email_address_column', $scored['fields']);
        $this->assertArrayNotHasKey('has_header_row', $scored['fields']);

        $api->clear();
        $client->exportS3File('fid', 'bucket-out');
        $exported = $this->only($api);
        $this->assertSame('/exportS3file', $exported['path']);
        $this->assertSame('fid', $exported['fields']['file_id']);
        $this->assertSame('bucket-out', $exported['fields']['export_bucket']);

        $api->clear();
        $files = $client->listS3Files();
        $this->assertSame('p3-key', $files[0]['file_name']);
        $listedFiles = $this->only($api);
        $this->assertSame('/listS3files', $listedFiles['path']);
        $this->assertArrayNotHasKey('bucket', $listedFiles['fields']);
        $this->assertArrayNotHasKey('search', $listedFiles['fields']);

        $api->clear();
        $client->listS3Files('inbox', 'csv');
        $filtered = $this->only($api);
        $this->assertSame('inbox', $filtered['fields']['bucket']);
        $this->assertSame('csv', $filtered['fields']['search']);

    }

    public function testRejectsMissingKeyAndInvalidArgumentsBeforeRequest()
    {
        $api = new FakeApi();
        $client = $this->client($api, '');
        $reject = array(
            function () use ($client) {
                $client->getCredits();
            },
            function () use ($client) {
                $client->findEmail('example.com', 'Ada');
            },
            function () use ($client) {
                $client->findEmailFormat('example.com');
            },
            function () use ($client) {
                $client->listFilters(false);
            },
            function () use ($client) {
                $client->emailFinderDeleteFile('fid');
            },
            function () use ($client) {
                $client->sendDomainSearchFile($this->csv, 1);
            },
            function () use ($client) {
                $client->validateS3File(array('file_name' => 'inbox.csv'));
            },
            function () use ($client) {
                $client->scoreS3File(array('file_name' => 'inbox.csv'));
            },
            function () use ($client) {
                $client->exportS3File('fid', 'bucket');
            },
            function () use ($client) {
                $client->sendEmailFinderFile($this->csv, 1);
            },
            function () use ($client) {
                $client->addFilter('exclude', 'domain', 'example.com');
            },
        );
        foreach ($reject as $call) {
            try {
                $call();
                $this->fail('empty API key was accepted');
            } catch (ZBMissingApiKeyException $e) {
                $this->assertStringContainsString('initialize', $e->getMessage());
            }
        }
        $this->assertSame(0, $api->count());

        $keyed = $this->client($api, 'keyed');
        $this->assertRejected(function () use ($keyed) {
            $options = new ZBValidateOptions();
            $options->timeout = 2;
            $keyed->validate('a@example.com', '', $options);
        }, '3 and 60');
        $this->assertRejected(function () use ($keyed) {
            $options = new ZBValidateOptions();
            $options->timeout = 9;
            $keyed->validateBatch(array('a@example.com'), $options);
        }, '10 and 120');
        $this->assertRejected(function () use ($keyed) {
            $keyed->validate('', null);
        }, 'email');
        $this->assertRejected(function () use ($keyed) {
            $keyed->validateBatch(array());
        }, 'emails');
        $this->assertRejected(function () use ($keyed) {
            $keyed->sendEmailFinderFile($this->csv, null);
        }, 'domainColumn');
        $this->assertRejected(function () use ($keyed) {
            $keyed->sendDomainSearchFile($this->csv, '');
        }, 'domainColumn');
        $this->assertRejected(function () use ($keyed) {
            $keyed->validateS3File(array());
        }, 'file_name');
        $this->assertRejected(function () use ($keyed) {
            $keyed->sendFile('', 1);
        }, 'filePath');
        $this->assertRejected(function () use ($keyed) {
            $keyed->fileStatus('');
        }, 'fileId');
        $this->assertRejected(function () use ($keyed) {
            $keyed->getFile('fid', '');
        }, 'downloadPath');
        $this->assertRejected(function () use ($keyed) {
            $keyed->addFilter('', 'domain', 'example.com');
        }, 'rule');
        $this->assertSame(0, $api->count());
    }

    public function testHtml403IsAShortError()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'html-403-user');
        $error = $this->capture(function () use ($client) {
            $client->getCredits();
        });
        $this->assertStringContainsString('Access denied', $error->getMessage());
        $this->assertGreaterThan(100000, strlen(FakeApi::accessDeniedPage()));
        $this->assertSame(1, $api->count());

        $api->clear();
        $posted = $this->client($api, 'html-403-post');
        $postError = $this->capture(function () use ($posted) {
            $posted->scoreEmailPost('a@example.com');
        });
        $this->assertStringContainsString('Access denied', $postError->getMessage());

        $api->clear();
        $fileClient = $this->client($api, 'html-403-file');
        $path = $this->tempPath('denied.csv');
        $fileError = $this->capture(function () use ($fileClient, $path) {
            $fileClient->sendFile($this->csv, 1);
        });
        $this->assertStringContainsString('Access denied', $fileError->getMessage());
        $this->assertFileDoesNotExist($path);
    }

    public function testHtml200OnValidateIsAnError()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'html-200-user');
        $error = $this->capture(function () use ($client) {
            $client->validate('a@example.com', '');
        });
        $this->assertStringContainsString('Access denied', $error->getMessage());
    }

    public function testHtml200GetFileIsNotSavedAsCsv()
    {
        $api = new FakeApi();
        $paths = array(
            'getFile' => $this->tempPath('offline-html.csv'),
            'emailFinderGetFile' => $this->tempPath('finder-html.csv'),
            'domainSearchGetFile' => $this->tempPath('domain-html.csv'),
        );
        $client = $this->client($api, 'html-200-file');
        $error = $this->capture(function () use ($client, $paths) {
            $client->getFile('fid', $paths['getFile']);
        });
        $this->assertStringContainsString('Access denied', $error->getMessage());
        $this->assertFileDoesNotExist($paths['getFile']);

        $finder = $this->capture(function () use ($client, $paths) {
            $client->emailFinderGetFile('fid', $paths['emailFinderGetFile']);
        });
        $this->assertStringContainsString('Access denied', $finder->getMessage());
        $this->assertFileDoesNotExist($paths['emailFinderGetFile']);

        $domain = $this->capture(function () use ($client, $paths) {
            $client->domainSearchGetFile('fid', $paths['domainSearchGetFile']);
        });
        $this->assertStringContainsString('Access denied', $domain->getMessage());
        $this->assertFileDoesNotExist($paths['domainSearchGetFile']);
    }

    public function testCloudflareJson403DoesNotDumpTheBody()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'cf-json-user');
        $error = $this->capture(function () use ($client) {
            $client->getCredits();
        });
        $this->assertStringNotContainsString('developers.cloudflare.com', $error->getMessage());
        $this->assertStringContainsString('Access denied', $error->getMessage());
    }

    public function testEmptyAndMalformedBodiesAreShortErrors()
    {
        $api = new FakeApi();
        $empty = $this->client($api, 'empty-user');
        $emptyError = $this->capture(function () use ($empty) {
            $empty->validate('a@example.com', '');
        });
        $this->assertStringContainsString('Empty response', $emptyError->getMessage());

        $path = $this->tempPath('empty-file.csv');
        $emptyFile = $this->capture(function () use ($empty, $path) {
            $empty->getFile('fid', $path);
        });
        $this->assertStringContainsString('Empty response', $emptyFile->getMessage());
        $this->assertFileDoesNotExist($path);

        $malformed = $this->client($api, 'malformed-user');
        $malformedError = $this->capture(function () use ($malformed) {
            $malformed->validate('a@example.com', '');
        });
        $this->assertStringContainsString('not valid JSON', $malformedError->getMessage());
        $this->assertStringNotContainsString('<<<not-json>>>', $malformedError->getMessage());
    }

    public function testDoubledErrorPrefixIsCollapsed()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'double-error-user');
        $error = $this->capture(function () use ($client) {
            $client->getActivity('a@example.com');
        });
        $this->assertSame('api_key is invalid', $error->getMessage());
    }

    public function testBatchAccountErrorIsNotASuccess()
    {
        $api = new FakeApi();
        $invalid = $this->client($api, 'account-invalid-user');
        $error = $this->capture(function () use ($invalid) {
            $invalid->validateBatch(array('a@example.com'));
        });
        $this->assertStringContainsString('Invalid API Key', $error->getMessage());
        $this->assertStringContainsString('ran out of credits', $error->getMessage());

        $credits = $this->client($api, 'account-invalid-credits');
        $creditError = $this->capture(function () use ($credits) {
            $credits->getCredits();
        });
        $this->assertStringContainsString('Invalid API Key', $creditError->getMessage());

        $none = $this->client($api, 'account-nocredits-user');
        $noneError = $this->capture(function () use ($none) {
            $none->validate('a@example.com', '');
        });
        $this->assertStringContainsString('ran out of credits', $noneError->getMessage());
    }

    public function testBatchPartialErrorsStayOnTheSuccessResult()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'batch-partial-user');
        $response = $client->validateBatch(array('good@example.com', 'bad@'));
        $this->assertCount(1, $response->emailBatch);
        $this->assertSame('good@example.com', $response->emailBatch[0]->address);
        $this->assertCount(1, $response->errors);
        $this->assertSame('bad@', $response->errors[0]['email_address']);
        $this->assertSame('Invalid email address', $response->errors[0]['error']);
    }

    public function testSeparateClientsDoNotShareKeyOrBaseUrl()
    {
        $api = new FakeApi();
        $us = new OfflineClient($api);
        $eu = new OfflineClient($api);
        $us->initialize('key-us', ZBBaseUrl::API_USA_URL);
        $eu->initialize('key-eu', ZBBaseUrl::API_EU_URL);
        $us->getCredits();
        $eu->getCredits();
        $records = $api->take();
        $this->assertCount(2, $records);
        $this->assertSame('api-us.zerobounce.net', $records[0]['host']);
        $this->assertSame('key-us', $records[0]['api_key']);
        $this->assertSame('api-eu.zerobounce.net', $records[1]['host']);
        $this->assertSame('key-eu', $records[1]['api_key']);
    }

    public function testCreateIsNotTheSingleton()
    {
        $a = ZeroBounce::create();
        $b = ZeroBounce::create();
        $this->assertNotSame($a, $b);
        $this->assertNotSame($a, ZeroBounce::Instance());
    }

    public function testConcurrentProcessesDoNotMixKeys()
    {
        $php = PHP_BINARY;
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $offline = __DIR__ . '/OfflineHttp.php';
        $script = tempnam(sys_get_temp_dir(), 'zb-conc-');
        file_put_contents($script, <<<'PHP'
<?php
require $argv[1];
require $argv[2];
$key = $argv[3];
$out = $argv[4];
$api = new ZeroBounce\Tests\FakeApi();
$client = new ZeroBounce\Tests\OfflineClient($api);
$client->initialize($key);
try {
    $response = $client->validate('person@example.com');
    $records = $api->take();
    $sent = isset($records[0]['api_key']) ? $records[0]['api_key'] : '';
    file_put_contents($out, json_encode(array(
        'address' => $response->address,
        'api_key' => $sent,
    )));
} catch (Throwable $e) {
    file_put_contents($out, json_encode(array('error' => $e->getMessage())));
}
PHP
        );
        $processes = array();
        $outputs = array();
        for ($i = 0; $i < 8; $i++) {
            $key = 'conc-' . $i;
            $out = $this->tempPath('conc-' . $i . '.json');
            $outputs[$i] = $out;
            $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' '
                . escapeshellarg($autoload) . ' ' . escapeshellarg($offline) . ' '
                . escapeshellarg($key) . ' ' . escapeshellarg($out);
            $pipes = array();
            $processes[$i] = proc_open($cmd, array(
                0 => array('pipe', 'r'),
                1 => array('pipe', 'w'),
                2 => array('pipe', 'w'),
            ), $pipes);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
        }
        foreach ($processes as $process) {
            proc_close($process);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->assertFileExists($outputs[$i]);
            $payload = json_decode(file_get_contents($outputs[$i]), true);
            $this->assertIsArray($payload, file_get_contents($outputs[$i]));
            $this->assertArrayNotHasKey('error', $payload);
            $this->assertSame('conc-' . $i, $payload['address']);
            $this->assertSame('conc-' . $i, $payload['api_key']);
            @unlink($outputs[$i]);
        }
        @unlink($script);
    }

    public function testRedirectsAreNotFollowed()
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($socket, $errstr);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int)substr(strrchr($name, ':'), 1);
        $router = tempnam(sys_get_temp_dir(), 'zb-router-');
        file_put_contents($router, '<?php
$uri = isset($_SERVER["REQUEST_URI"]) ? $_SERVER["REQUEST_URI"] : "";
if (strpos($uri, "/landed") !== false) {
    header("Content-Type: application/json");
    echo "{\"Credits\":\"999\"}";
    return true;
}
header("Location: http://127.0.0.1:' . $port . '/landed", true, 302);
header("Content-Type: text/html; charset=UTF-8");
echo "<!DOCTYPE html><html><title>redirect</title><body>go</body></html>";
return true;
');
        $pipes = array();
        $process = proc_open(
            escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        $this->assertIsResource($process);
        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            $connection = @fsockopen('127.0.0.1', $port, $cerrno, $cerr, 0.1);
            if ($connection) {
                fclose($connection);
                $ready = true;
                break;
            }
            usleep(20000);
        }
        $this->assertTrue($ready, 'local API did not start');
        try {
            $client = new DirectClient();
            $client->initialize('redirect-key');
            $this->forceBaseUrl($client, 'http://127.0.0.1:' . $port . '/v2');
            $error = $this->capture(function () use ($client) {
                $client->getCredits();
            });
            $this->assertStringNotContainsString('999', $error->getMessage());
        } finally {
            proc_terminate($process);
            proc_close($process);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            @unlink($router);
        }
    }

    public function testValidateSendsTheNewOptionsOnlyWhenSet()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'p1-key');

        $client->validate('a@example.com');
        $plain = $this->only($api);
        $this->assertArrayNotHasKey('domain_info', $plain['fields']);
        $this->assertArrayNotHasKey('greylist_processing', $plain['fields']);
        $this->assertArrayNotHasKey('callback_url', $plain['fields']);

        $api->clear();
        $options = new ZBValidateOptions();
        $options->domainInfo = true;
        $options->greylistProcessing = false;
        $client->validate('a@example.com', null, $options);
        $set = $this->only($api);
        $this->assertSame('true', (string)$set['fields']['domain_info']);
        $this->assertSame('false', (string)$set['fields']['greylist_processing']);

        $api->clear();
        $options = new ZBValidateOptions();
        $options->greylistProcessing = true;
        $options->callbackUrl = 'https://example.com/cb';
        $options->usePost = true;
        $client->validate('a@example.com', null, $options);
        $posted = $this->only($api);
        $this->assertSame('POST', $posted['method']);
        $this->assertSame('true', (string)$posted['fields']['greylist_processing']);
        $this->assertSame('https://example.com/cb', $posted['fields']['callback_url']);
    }

    public function testValidateRejectsCallbackUrlWithoutGreylistProcessing()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'p1-key');

        $options = new ZBValidateOptions();
        $options->callbackUrl = 'https://example.com/cb';
        $this->assertRejected(function () use ($client, $options) {
            $client->validate('a@example.com', null, $options);
        }, 'greylistProcessing');

        $blank = new ZBValidateOptions();
        $blank->greylistProcessing = true;
        $blank->callbackUrl = '   ';
        $this->assertRejected(function () use ($client, $blank) {
            $client->validate('a@example.com', null, $blank);
        }, 'callbackUrl');

        $this->assertSame(0, $api->count());
    }

    public function testInboxPlacementUsesItsOwnHostAndCamelCaseJson()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'ipt-key');

        $client->getInboxPlacementProviders();
        $providers = $this->only($api);
        $this->assertSame('GET', $providers['method']);
        $this->assertSame('bulkapi.zerobounce.net', $providers['host']);
        $this->assertSame('/api/ipt/providers', $providers['path']);
        // The documented auth for this API is a Bearer header, not a query key.
        $this->assertSame('Bearer ipt-key', $providers['authorization']);
        $this->assertArrayNotHasKey('api_key', $providers['fields']);

        $api->clear();
        $client->getInboxPlacementTestTypes();
        $this->assertSame('/api/ipt/test-types', $this->only($api)['path']);

        $api->clear();
        $client->createInboxPlacementTest('basic');
        $created = $this->only($api);
        $this->assertSame('POST', $created['method']);
        $this->assertSame('/api/ipt/test', $created['path']);
        $this->assertSame(array('name' => 'basic'), $created['json']);

        $api->clear();
        $client->createInboxPlacementTest(
            'full',
            1,
            'from@example.com',
            array(1, 2),
            array(3),
            array(array('id' => 4, 'maxSeeds' => 2)),
            'https://example.com/hook'
        );
        $this->assertSame(array(
            'name' => 'full',
            'testType' => 1,
            'senderEmail' => 'from@example.com',
            'providerIds' => array(1, 2),
            'seedIds' => array(3),
            'providers' => array(array('id' => 4, 'maxSeeds' => 2)),
            'webhookUrl' => 'https://example.com/hook',
        ), $this->only($api)['json']);

        $api->clear();
        $client->getInboxPlacementTest('a b/c');
        $this->assertSame('/api/ipt/test/a%20b%2Fc', $this->only($api)['path']);

        $api->clear();
        $client->deleteInboxPlacementTest('t1');
        $this->assertSame('DELETE', $this->only($api)['method']);

        $api->clear();
        $client->setInboxPlacementWebhook('https://example.com/hook');
        $webhook = $this->only($api);
        $this->assertSame('PUT', $webhook['method']);
        $this->assertSame(array('webhookUrl' => 'https://example.com/hook'), $webhook['json']);
    }

    public function testInboxPlacementListSendsOnlyTheFiltersThatAreSet()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'ipt-key');

        $client->listInboxPlacementTests();
        // The key travels in the Bearer header, so an unfiltered list sends no query.
        $this->assertSame(array(), array_keys($this->only($api)['fields']));

        $api->clear();
        $client->listInboxPlacementTests('finished', '2026-01-01', '2026-02-01', 2, 50);
        $fields = $this->only($api)['fields'];
        $this->assertSame('finished', $fields['status']);
        $this->assertSame('2026-01-01', $fields['createdAfter']);
        $this->assertSame('2026-02-01', $fields['createdBefore']);
        $this->assertSame('2', (string)$fields['page']);
        $this->assertSame('50', (string)$fields['page_size']);
    }

    public function testInboxPlacementRejectsInvalidArgumentsBeforeRequest()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'ipt-key');

        $cases = array(
            function () use ($client) { $client->createInboxPlacementTest(''); },
            function () use ($client) { $client->createInboxPlacementTest(str_repeat('x', 65)); },
            function () use ($client) { $client->createInboxPlacementTest('t', 4); },
            function () use ($client) { $client->createInboxPlacementTest('t', 1); },
            function () use ($client) { $client->createInboxPlacementTest('t', null, null, null, null, array(array('maxSeeds' => 2))); },
            function () use ($client) { $client->createInboxPlacementTest('t', null, null, null, null, array(array('id' => 1, 'maxSeeds' => 0))); },
            function () use ($client) { $client->listInboxPlacementTests('queued'); },
            function () use ($client) { $client->listInboxPlacementTests(null, null, null, 0); },
            function () use ($client) { $client->listInboxPlacementTests(null, null, null, null, 101); },
            function () use ($client) { $client->getInboxPlacementTest(''); },
            function () use ($client) { $client->deleteInboxPlacementTest(''); },
            function () use ($client) { $client->setInboxPlacementWebhook(''); },
        );
        foreach ($cases as $case) {
            $threw = false;
            try {
                $case();
            } catch (ZBException $e) {
                $threw = true;
            }
            $this->assertTrue($threw);
        }
        $this->assertSame(0, $api->count());
    }

    public function testInboxPlacementDetailErrorBecomesTheMessage()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'ipt-detail-key');

        try {
            $client->getInboxPlacementProviders();
            $this->fail('expected a ZBException');
        } catch (ZBException $e) {
            $this->assertStringContainsString('Inbox placement test not found', $e->getMessage());
            $this->assertLessThan(500, strlen($e->getMessage()));
        }
    }

    public function testListEvaluatorUsesItsOwnBaseUrlAndEncodesFileId()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'le-key');

        $client->getListEvaluatorResult('a b/c');
        $result = $this->only($api);
        $this->assertSame('bulkapi.zerobounce.net', $result['host']);
        $this->assertSame('/v2/listevaluator/a%20b%2Fc/', $result['path']);

        $api->clear();
        $overridden = new OfflineClient($api);
        $overridden->initialize('le-key', null, 'https://example.com/custom/listevaluator');
        $overridden->getListEvaluatorResult('fid');
        $custom = $this->only($api);
        $this->assertSame('example.com', $custom['host']);
        $this->assertSame('/custom/listevaluator/fid/', $custom['path']);
    }


    public function testS3AndSftpJobsSendDocumentedFieldsOnly()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'bulk-key');

        $client->emailFinderS3File(array('file_name' => 'inbox.csv', 'has_header_row' => false, 'return_url' => 'https://example.com/done'));
        $finder = $this->only($api);
        $this->assertSame('POST', $finder['method']);
        $this->assertSame('bulkapi.zerobounce.net', $finder['host']);
        $this->assertSame('/emailFinderS3file', $finder['path']);
        $this->assertSame('inbox.csv', $finder['fields']['file_name']);
        $this->assertSame('false', $finder['fields']['has_header_row']);
        // LIVE: return_url is required; remove_duplicates stays optional.
        $this->assertSame('https://example.com/done', $finder['fields']['return_url']);
        $this->assertArrayNotHasKey('remove_duplicates', $finder['fields']);

        $api->clear();
        $client->domainSearchS3File(array(
            'file_name' => 'inbox.csv',
            'has_header_row' => true,
            'remove_duplicates' => false,
            'return_url' => 'https://example.com/done',
        ));
        $search = $this->only($api);
        $this->assertSame('/domainSearchS3file', $search['path']);
        $this->assertSame('true', $search['fields']['has_header_row']);
        $this->assertSame('false', $search['fields']['remove_duplicates']);
        $this->assertSame('https://example.com/done', $search['fields']['return_url']);

        // LIVE: validateSFTPfile needs file_name too, although the docs mark it
        // optional ("file_name: This field is required").
        $api->clear();
        $client->validateSFTPFile(array(
            'file_name' => 'my_file.txt',
            'has_header_row' => true,
            'return_url' => 'https://example.com/done',
        ));
        $sftp = $this->only($api);
        $this->assertSame('/validateSFTPfile', $sftp['path']);
        $this->assertSame('my_file.txt', $sftp['fields']['file_name']);

        // A job without file_name or return_url never reaches the API.
        foreach (array(
            array('has_header_row' => true, 'return_url' => 'https://example.com/done'),
            array('file_name' => 'f.csv', 'has_header_row' => true),
        ) as $incomplete) {
            try {
                $client->validateSFTPFile($incomplete);
                $this->fail('expected a rejection');
            } catch (ZBMissingParameterException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $api->clear();
        $client->scoreSFTPFile(array('file_name' => 'my_file.txt', 'has_header_row' => true, 'return_url' => 'https://example.com/done'));
        $this->assertSame('/scoreSFTPfile', $this->only($api)['path']);

        $api->clear();
        $client->emailFinderSFTPFile(array('file_name' => 'my_file.txt', 'has_header_row' => true, 'return_url' => 'https://example.com/done'));
        $this->assertSame('/emailFinderSFTPfile', $this->only($api)['path']);

        $api->clear();
        $client->domainSearchSFTPFile(array('file_name' => 'my_file.txt', 'has_header_row' => true, 'return_url' => 'https://example.com/done'));
        $this->assertSame('/domainSearchSFTPfile', $this->only($api)['path']);

        // exportSFTPfile takes export_path, not the export_bucket exportS3File uses.
        $api->clear();
        $client->exportSFTPFile('fid');
        $exported = $this->only($api);
        $this->assertSame('/exportSFTPfile', $exported['path']);
        $this->assertSame('fid', $exported['fields']['file_id']);
        $this->assertArrayNotHasKey('export_path', $exported['fields']);

        $api->clear();
        $client->exportSFTPFile('fid', '/exports');
        $this->assertSame('/exports', $this->only($api)['fields']['export_path']);
    }

    public function testS3AndSftpJobsRejectInvalidArgumentsBeforeRequest()
    {
        $api = new FakeApi();
        $client = $this->client($api, 'bulk-key');

        $cases = array(
            function () use ($client) { $client->emailFinderS3File(array('has_header_row' => true, 'return_url' => 'https://example.com/done')); },
            function () use ($client) { $client->domainSearchS3File(array('file_name' => 'f.csv')); },
            function () use ($client) { $client->scoreSFTPFile(array('file_name' => '  ', 'has_header_row' => true, 'return_url' => 'https://example.com/done')); },
            function () use ($client) { $client->domainSearchSFTPFile(array('file_name' => 'f.csv')); },
            function () use ($client) { $client->exportSFTPFile(''); },
            function () use ($client) { $client->exportSFTPFile('fid', '  '); },
        );
        foreach ($cases as $case) {
            $threw = false;
            try {
                $case();
            } catch (ZBException $e) {
                $threw = true;
            }
            $this->assertTrue($threw);
        }
        $this->assertSame(0, $api->count());
    }

    public function testSuccessFalseOnAnHttp200IsAnError()
    {
        // LIVE: the API answers an unknown file id, and an unknown
        // list-evaluator id, with an HTTP 200 whose body says success: false.
        $api = new FakeApi();
        $client = $this->client($api, 'success-false-key');
        $error = $this->capture(function () use ($client) {
            $client->fileStatus('0d1b7a4e-1111-2222-3333-444455556666');
        });
        $this->assertSame('File cannot be found.', $error->getMessage());
        $this->assertInstanceOf(ZBApiException::class, $error);
        $this->assertSame(200, $error->getStatusCode());
    }

    private function client(FakeApi $api, $key)
    {
        $client = new OfflineClient($api);
        $client->initialize($key);
        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private function only(FakeApi $api)
    {
        $records = $api->take();
        $this->assertCount(1, $records);
        return $records[0];
    }

    private function capture(callable $call)
    {
        try {
            $call();
        } catch (ZBException $e) {
            $this->assertShort($e->getMessage());
            return $e;
        }
        $this->fail('expected ZBException');
    }

    private function assertRejected(callable $call, $needle)
    {
        try {
            $call();
        } catch (ZBException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
            return;
        }
        $this->fail('expected rejection containing ' . $needle);
    }

    private function assertShort($message)
    {
        $this->assertLessThan(500, strlen($message), substr($message, 0, 180));
        $lower = strtolower($message);
        $this->assertStringNotContainsString('<html', $lower);
        $this->assertStringNotContainsString('<!doctype', $lower);
        $this->assertStringNotContainsString('<p>blocked</p>', $message);
        $this->assertStringNotContainsString('Error: Error:', $message);
        $this->assertStringNotContainsString('Invalid response "', $message);
        $this->assertStringNotContainsString('developers.cloudflare.com', $message);
    }

    private function assertNoUserAgentOrMatchingVersion(array $request)
    {
        $version = json_decode(file_get_contents(dirname(__DIR__) . '/composer.json'))->version;
        foreach ($request['headers'] as $header) {
            if (stripos($header, 'User-Agent:') === 0) {
                $this->assertStringContainsString($version, $header);
            }
        }
    }

    private function forceBaseUrl($client, $url)
    {
        $ref = new ReflectionClass($client);
        if ($ref->hasProperty('apiBaseUrl')) {
            $prop = $ref->getProperty('apiBaseUrl');
        } else {
            $prop = $ref->getProperty('ApiBaseUrl');
        }
        if (\PHP_VERSION_ID < 80100) {
            $prop->setAccessible(true);
        }
        if ($prop->isStatic()) {
            $prop->setValue(null, $url);
        } else {
            $prop->setValue($client, $url);
        }
    }

    private function tempPath($name)
    {
        return sys_get_temp_dir() . '/zb-php-' . getmypid() . '-' . $name;
    }
}

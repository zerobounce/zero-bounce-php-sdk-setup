<?php

namespace ZeroBounce\Tests;

use DateTime;
use PHPUnit\Framework\TestCase;
use ZeroBounce\SDK\ZBApiException;
use ZeroBounce\SDK\ZBException;
use ZeroBounce\SDK\ZeroBounceWarmup;

require_once __DIR__ . '/OfflineHttp.php';

/**
 * In-memory Warmup API. Records the request and answers from the API key prefix.
 */
class FakeWarmupApi
{
    /** @var array<int, array<string, mixed>> */
    private $records = array();
    /** @var array{status:int, type:string, body:string}|null */
    public $next = null;

    public function handle($method, $url, array $headers, $body, $timeout)
    {
        $parsed = parse_url($url);
        $query = array();
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }
        $apiKey = '';
        foreach ($headers as $header) {
            if (stripos($header, 'API-Key:') === 0) {
                $apiKey = trim(substr($header, strlen('API-Key:')));
            }
        }
        $this->records[] = array(
            'method' => strtoupper($method),
            'url' => $url,
            'host' => isset($parsed['host']) ? $parsed['host'] : '',
            'path' => isset($parsed['path']) ? $parsed['path'] : '',
            'query' => $query,
            'headers' => $headers,
            'body' => $body === null ? '' : $body,
            'json' => ($body === null || $body === '') ? null : json_decode((string)$body, true),
            'api_key' => $apiKey,
            'timeout' => $timeout,
        );
        if ($this->next !== null) {
            $canned = $this->next;
            $this->next = null;
            return $this->respond($canned['status'], $canned['type'], $canned['body']);
        }
        return $this->respond(200, 'application/json', json_encode(array('ok' => true, 'echo_api_key' => $apiKey)));
    }

    public function reply($status, $type, $body)
    {
        $this->next = array('status' => $status, 'type' => $type, 'body' => $body);
    }

    public function take()
    {
        $records = $this->records;
        $this->records = array();
        return $records;
    }

    private function respond($status, $contentType, $body)
    {
        return array(
            'status' => $status,
            'headers' => array('HTTP/1.1 ' . $status, 'Content-Type: ' . $contentType),
            'body' => $body,
        );
    }
}

class OfflineWarmupClient extends ZeroBounceWarmup
{
    /** @var FakeWarmupApi */
    public $fake;

    public function __construct(FakeWarmupApi $fake, $apiKey = 'warmup-key', $baseUrl = null)
    {
        parent::__construct($apiKey, $baseUrl);
        $this->fake = $fake;
    }

    protected function executeHttp($method, $url, array $headers = array(), $body = null, $timeout = 120)
    {
        return $this->fake->handle($method, $url, $headers, $body, $timeout);
    }
}

class OfflineWarmupTest extends TestCase
{
    /** @var FakeWarmupApi */
    private $api;
    /** @var OfflineWarmupClient */
    private $client;

    protected function setUp(): void
    {
        $this->api = new FakeWarmupApi();
        $this->client = new OfflineWarmupClient($this->api);
    }

    private function only()
    {
        $records = $this->api->take();
        $this->assertCount(1, $records);
        return $records[0];
    }

    private function capture(callable $call)
    {
        try {
            $call();
        } catch (ZBException $e) {
            $this->assertLessThan(500, strlen($e->getMessage()), 'error message should stay short');
            return $e;
        }
        $this->fail('expected ZBException');
    }

    // -- construction --------------------------------------------------------

    public function testTheKeyTravelsInTheApiKeyHeaderOnTheWarmupHost()
    {
        $this->client->getSeedCount();
        $request = $this->only();
        $this->assertSame('warmup-api.zerobounce.net', $request['host']);
        $this->assertSame('/api/v1/warmup/seed-count', $request['path']);
        $this->assertSame('warmup-key', $request['api_key']);
        $this->assertSame(array(), $request['query']);
        // The key must never reach the query string, where it would be logged.
        $this->assertStringNotContainsString('warmup-key', (string)parse_url($request['url'], PHP_URL_QUERY));
    }

    public function testAnEmptyKeyIsRejected()
    {
        $error = $this->capture(function () { new ZeroBounceWarmup(''); });
        $this->assertStringContainsString('api_key', $error->getMessage());
    }

    public function testAKeyWithWhitespaceOrControlCharactersIsRejected()
    {
        foreach (array(" key ", "key\n", "key\tx", "key\x00") as $bad) {
            $error = $this->capture(function () use ($bad) { new ZeroBounceWarmup($bad); });
            $this->assertStringContainsString('whitespace', $error->getMessage());
        }
    }

    public function testAPlainHttpBaseUrlIsRejected()
    {
        $error = $this->capture(function () {
            new ZeroBounceWarmup('key', 'http://warmup-api.zerobounce.net');
        });
        $this->assertStringContainsString('https', $error->getMessage());
    }

    public function testACustomHttpsBaseUrlIsUsed()
    {
        $client = new OfflineWarmupClient($this->api, 'warmup-key', 'https://warmup.example.com/');
        $client->getSeeds();
        $request = $this->only();
        $this->assertSame('warmup.example.com', $request['host']);
        $this->assertSame('/api/v1/warmup/seeds', $request['path']);
    }

    public function testTheClientExposesExactlyTheDocumentedEndpoints()
    {
        $reflection = new \ReflectionClass(ZeroBounceWarmup::class);
        $names = array();
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class === ZeroBounceWarmup::class && $method->getName() !== '__construct') {
                $names[] = $method->getName();
            }
        }
        $this->assertCount(26, $names);
    }

    // -- paths and verbs -----------------------------------------------------

    public function testEveryReadEndpointUsesItsDocumentedPathAndVerb()
    {
        $cases = array(
            array(function ($c) { $c->listSendingAccounts(); }, 'GET', '/sending-accounts'),
            array(function ($c) { $c->getSendingAccount('a@b.com'); }, 'GET', '/sending-account'),
            array(function ($c) { $c->listFromAddresses(); }, 'GET', '/from-addresses'),
            array(function ($c) { $c->getEngagementRules(); }, 'GET', '/engagement-rules'),
            array(function ($c) { $c->getCustomEngagement('a@b.com'); }, 'GET', '/custom-engagement/a%40b.com'),
            array(function ($c) { $c->getSeeds(); }, 'GET', '/seeds'),
            array(function ($c) { $c->getSeedCount(); }, 'GET', '/seed-count'),
            array(function ($c) { $c->getProgressToday(); }, 'GET', '/progress-today'),
            array(function ($c) { $c->getSentToday(); }, 'GET', '/sent-today'),
            array(function ($c) { $c->getEmailsPerDay(); }, 'GET', '/emails-per-day'),
            array(function ($c) { $c->getStatistics('a@b.com'); }, 'GET', '/statistics'),
            array(function ($c) { $c->getStatisticsDetailed('a@b.com'); }, 'GET', '/statistics-detailed'),
            array(function ($c) { $c->getFromAddressStatistics('a@b.com'); }, 'GET', '/from-address-statistics'),
            array(function ($c) { $c->checkDomainDmarc('a@b.com'); }, 'GET', '/check-domain-dmarc'),
        );
        foreach ($cases as $case) {
            list($call, $method, $path) = $case;
            $call($this->client);
            $request = $this->only();
            $this->assertSame($method, $request['method'], $path);
            $this->assertSame('/api/v1/warmup' . $path, $request['path']);
        }
    }

    public function testEveryWriteEndpointUsesItsDocumentedPathAndVerb()
    {
        $cases = array(
            array(function ($c) { $c->addSendingAccount('a@b.com', 'self_managed', 0); }, 'POST', '/sending-accounts'),
            array(function ($c) { $c->updateSendingAccount('a@b.com', 1, 4, true); }, 'PUT', '/sending-accounts'),
            array(function ($c) { $c->deleteSendingAccount('a@b.com'); }, 'DELETE', '/sending-accounts'),
            array(function ($c) { $c->changeFromAddress('a@b.com', 'c@d.com'); }, 'PUT', '/smtp-credentials'),
            array(function ($c) { $c->setEngagementRule('a@b.com', 2); }, 'PUT', '/engagement-rule'),
            array(function ($c) { $c->createCustomEngagement('a@b.com', 10, 10, 5); }, 'POST', '/custom-engagement'),
            array(function ($c) { $c->updateCustomEngagement('a@b.com', 10, 10, 5); }, 'PUT', '/custom-engagement'),
            array(function ($c) { $c->deleteCustomEngagement('a@b.com', 1); }, 'DELETE', '/custom-engagement'),
            array(
                function ($c) { $c->testSmtpConfiguration('a@b.com', 'u', 'p', 'smtp.example.com', 587); },
                'POST',
                '/test-smtp-configuration'
            ),
        );
        foreach ($cases as $case) {
            list($call, $method, $path) = $case;
            $call($this->client);
            $request = $this->only();
            $this->assertSame($method, $request['method'], $path);
            $this->assertSame('/api/v1/warmup' . $path, $request['path']);
        }
    }

    public function testStatisticsPerDaySendsBothDates()
    {
        $this->client->getStatisticsPerDay('a@b.com', new DateTime('2026-01-02'), '2026-01-09');
        $request = $this->only();
        $this->assertSame('/api/v1/warmup/statistics-per-day', $request['path']);
        $this->assertSame(
            array('from_address' => 'a@b.com', 'start_date' => '2026-01-02', 'end_date' => '2026-01-09'),
            $request['query']
        );
    }

    // -- argument validation -------------------------------------------------

    public function testRequiredArgumentsAreCheckedBeforeAnyRequest()
    {
        $calls = array(
            function ($c) { $c->getSendingAccount(''); },
            function ($c) { $c->deleteSendingAccount(' '); },
            function ($c) { $c->changeFromAddress('', 'b@c.com'); },
            function ($c) { $c->changeFromAddress('a@b.com', ''); },
            function ($c) { $c->setEngagementRule('', 1); },
            function ($c) { $c->getStatistics(''); },
            function ($c) { $c->downloadStatistics(''); },
            function ($c) { $c->checkDomainDmarc(''); },
        );
        foreach ($calls as $call) {
            $this->capture(function () use ($call) { $call($this->client); });
            $this->assertSame(array(), $this->api->take(), 'nothing should reach the network');
        }
    }

    public function testEngagementRuleIdsAreRangeChecked()
    {
        foreach (array(-1, 5, '2') as $bad) {
            $error = $this->capture(function () use ($bad) {
                $this->client->setEngagementRule('a@b.com', $bad);
            });
            $this->assertStringContainsString('rule_id', $error->getMessage());
        }
        foreach (array(0, 1, 2, 3, 4) as $good) {
            $this->client->setEngagementRule('a@b.com', $good);
            $this->assertSame($good, $this->only()['json']['rule_id']);
        }
    }

    public function testCustomEngagementPercentagesAreRangeChecked()
    {
        $bad = array(
            array(4, 10, 5),    // reply_pct below 5
            array(46, 10, 5),   // reply_pct above 45
            array(10, 4, 5),    // important_pct below 5
            array(10, 41, 5),   // important_pct above 40
            array(10, 10, 71),  // click_pct above 70
            array(10, 10, 18),  // click_pct above important_pct x 1.75
        );
        foreach ($bad as $case) {
            $this->capture(function () use ($case) {
                $this->client->createCustomEngagement('a@b.com', $case[0], $case[1], $case[2]);
            });
            $this->assertSame(array(), $this->api->take());
        }
        $this->client->createCustomEngagement('a@b.com', 20, 20, 35);
        $this->assertSame(
            array('c_from' => 'a@b.com', 'reply_pct' => 20, 'important_pct' => 20, 'click_pct' => 35),
            $this->only()['json']
        );
    }

    public function testDeleteCustomEngagementOnlyFallsBackToAStandardRule()
    {
        $error = $this->capture(function () {
            $this->client->deleteCustomEngagement('a@b.com', 0);
        });
        $this->assertStringContainsString('engagement_rule_id', $error->getMessage());
        $this->client->deleteCustomEngagement('a@b.com', 3);
        $this->assertSame(3, $this->only()['json']['engagement_rule_id']);
    }

    public function testUpdateSendingAccountChecksItsRangesAndFlag()
    {
        $this->capture(function () { $this->client->updateSendingAccount('a@b.com', 5, 4, true); });
        $this->capture(function () { $this->client->updateSendingAccount('a@b.com', 1, 9, true); });
        $this->capture(function () { $this->client->updateSendingAccount('a@b.com', 1, 4, 'yes'); });
        $this->assertSame(array(), $this->api->take());
        $this->client->updateSendingAccount('a@b.com', 1, 5, false);
        $this->assertSame(
            array(
                'team_id' => 1,
                'from_address' => 'a@b.com',
                'engagement_rule_id' => 1,
                'status_id' => 5,
                'auto_enable' => false,
            ),
            $this->only()['json']
        );
    }

    public function testAddSendingAccountChecksTheOnboardingModel()
    {
        $error = $this->capture(function () {
            $this->client->addSendingAccount('a@b.com', 'managed', 0);
        });
        $this->assertStringContainsString('self_managed', $error->getMessage());

        // self_managed needs its engagement rule and takes no ZB-managed fields.
        $this->capture(function () { $this->client->addSendingAccount('a@b.com', 'self_managed'); });
        $this->capture(function () {
            $this->client->addSendingAccount('a@b.com', 'self_managed', 0, null, array('subject' => 'hi'));
        });
        $this->assertSame(array(), $this->api->take());

        $this->client->addSendingAccount('a@b.com', 'self_managed', 2, true);
        $this->assertSame(
            array(
                'from_address' => 'a@b.com',
                'onboarding_model' => 'self_managed',
                'engagement_rule_id' => 2,
                'ignore_conflict' => true,
            ),
            $this->only()['json']
        );
    }

    public function testAddSendingAccountChecksTheZbManagedPayload()
    {
        $this->capture(function () {
            $this->client->addSendingAccount(
                'a@b.com',
                'zb_managed',
                null,
                null,
                array('types_of_emails' => array('promotional'))
            );
        });
        $this->capture(function () {
            $this->client->addSendingAccount(
                'a@b.com',
                'zb_managed',
                null,
                null,
                null,
                array('timezone_offset' => 15)
            );
        });
        $this->capture(function () {
            $this->client->addSendingAccount(
                'a@b.com',
                'zb_managed',
                null,
                null,
                null,
                array('warmup_start_date' => '02/01/2026')
            );
        });
        $this->assertSame(array(), $this->api->take());

        $this->client->addSendingAccount(
            'a@b.com',
            'zb_managed',
            null,
            null,
            array('types_of_emails' => array('newsletter', 'marketing')),
            array('timezone_offset' => -5, 'warmup_start_date' => new DateTime('2026-03-04'))
        );
        $body = $this->only()['json'];
        $this->assertSame('zb_managed', $body['onboarding_model']);
        $this->assertSame(array('newsletter', 'marketing'), $body['email_content']['types_of_emails']);
        $this->assertSame('2026-03-04', $body['managed_sending']['warmup_start_date']);
    }

    public function testListFromAddressesChecksItsSearchAndPaging()
    {
        $this->capture(function () { $this->client->listFromAddresses('bad search!'); });
        $this->capture(function () { $this->client->listFromAddresses(null, -1); });
        $this->capture(function () { $this->client->listFromAddresses(null, null, '10'); });
        $this->assertSame(array(), $this->api->take());

        $this->client->listFromAddresses('a.b-c_d@e+f', 0, 50);
        // The API takes the paging values as strings.
        $this->assertSame(
            array('search_from' => 'a.b-c_d@e+f', 'offset' => '0', 'limit' => '50'),
            $this->only()['query']
        );
    }

    public function testStatisticsPerDayRejectsABackwardsRangeAndBadDates()
    {
        $this->capture(function () {
            $this->client->getStatisticsPerDay('a@b.com', '2026-02-02', '2026-01-01');
        });
        $this->capture(function () {
            $this->client->getStatisticsPerDay('a@b.com', '01/02/2026', '2026-01-01');
        });
        $this->assertSame(array(), $this->api->take());
    }

    public function testCheckDomainDmarcRequiresAnEmailAddressNotADomain()
    {
        // LIVE: the API answers a bare domain with "Please provide a valid
        // email address", although the docs describe a domain.
        $error = $this->capture(function () { $this->client->checkDomainDmarc('example.com'); });
        $this->assertStringContainsString('email address', $error->getMessage());
        $this->assertSame(array(), $this->api->take());

        $this->client->checkDomainDmarc('sender@example.com', 'zb');
        $this->assertSame(
            array('from_address' => 'sender@example.com', 'dkim_selector' => 'zb'),
            $this->only()['query']
        );
    }

    public function testCustomEngagementPathIdsCannotTraverse()
    {
        foreach (array('.', '..') as $bad) {
            $this->capture(function () use ($bad) { $this->client->getCustomEngagement($bad); });
            $this->assertSame(array(), $this->api->take());
        }
    }

    public function testSmtpConfigurationChecksItsPort()
    {
        foreach (array(0, 65536, '587') as $bad) {
            $error = $this->capture(function () use ($bad) {
                $this->client->testSmtpConfiguration('a@b.com', 'u', 'p', 's', $bad);
            });
            $this->assertStringContainsString('port', $error->getMessage());
        }
        $this->assertSame(array(), $this->api->take());
    }

    // -- responses -----------------------------------------------------------

    public function testAnEmptySuccessBecomesNull()
    {
        $this->api->reply(204, 'application/json', '');
        $this->assertNull($this->client->deleteSendingAccount('a@b.com'));
        $this->api->take();

        $this->api->reply(200, 'application/json', '');
        $this->assertNull($this->client->getSeedCount());
    }

    public function testAListPayloadComesBackUnderData()
    {
        $this->api->reply(200, 'application/json', '[{"seed":"a@b.com"},{"seed":"c@d.com"}]');
        $result = $this->client->getSeeds();
        $this->assertCount(2, $result['data']);
        $this->assertSame('a@b.com', $result['data'][0]['seed']);
    }

    public function testCsvDownloadsComeBackAsText()
    {
        $this->api->reply(200, 'text/csv', "seed,provider\na@b.com,gmail\n");
        $this->assertSame("seed,provider\na@b.com,gmail\n", $this->client->downloadSeeds());
        $this->api->take();

        $this->api->reply(200, 'text/csv', "day,sent\n2026-01-01,10\n");
        $this->client->downloadStatistics('a@b.com');
        $this->assertSame(array('c_from' => 'a@b.com'), $this->only()['query']);
    }

    public function testAJsonErrorInPlaceOfACsvIsAnError()
    {
        $this->api->reply(200, 'application/json', '{"message":"No statistics for this address."}');
        $error = $this->capture(function () { $this->client->downloadSeeds(); });
        $this->assertSame('No statistics for this address.', $error->getMessage());
    }

    public function testTheHttpStatusIsOnTheError()
    {
        $this->api->reply(429, 'application/json', '{"message":"Custom rule was updated less than 7 days ago."}');
        $error = $this->capture(function () {
            $this->client->updateCustomEngagement('a@b.com', 10, 10, 5);
        });
        $this->assertInstanceOf(ZBApiException::class, $error);
        $this->assertSame(429, $error->getStatusCode());
        $this->assertSame('Custom rule was updated less than 7 days ago.', $error->getMessage());
    }

    public function testAValidationErrorListBecomesOneShortMessage()
    {
        $this->api->reply(
            422,
            'application/json',
            '{"detail":[{"loc":["body","reply_pct"],"msg":"ensure this value is <= 45"}]}'
        );
        $error = $this->capture(function () {
            $this->client->createCustomEngagement('a@b.com', 10, 10, 5);
        });
        $this->assertSame('reply_pct: ensure this value is <= 45', $error->getMessage());
    }

    public function testATopLevelStringListBecomesOneShortMessage()
    {
        $this->api->reply(400, 'application/json', '["Sending account already exists."]');
        $error = $this->capture(function () {
            $this->client->addSendingAccount('a@b.com', 'self_managed', 0);
        });
        $this->assertSame('Sending account already exists.', $error->getMessage());
    }

    public function testAnHtmlErrorPageIsNotPastedIntoTheMessage()
    {
        $this->api->reply(403, 'text/html', FakeApi::accessDeniedPage());
        $error = $this->capture(function () { $this->client->getSeeds(); });
        $this->assertStringContainsString('HTML error page', $error->getMessage());
        $this->assertSame(403, $error->getStatusCode());
    }

    public function testARedirectIsRefusedRatherThanFollowed()
    {
        $this->api->reply(302, 'text/html', '');
        $error = $this->capture(function () { $this->client->getSeeds(); });
        $this->assertStringContainsString('redirect', $error->getMessage());
        $this->assertSame(302, $error->getStatusCode());
    }

    public function testTheKeyNeverAppearsInAnError()
    {
        $this->api->reply(401, 'application/json', '{"message":"Invalid key warmup-key"}');
        $error = $this->capture(function () { $this->client->getSeeds(); });
        $this->assertStringNotContainsString('warmup-key', $error->getMessage());
        $this->assertStringContainsString('***', $error->getMessage());
    }

    public function testSmtpCredentialsNeverAppearInAnError()
    {
        $this->api->reply(500, 'application/json', '{"message":"login failed for user bob with s3cret"}');
        $error = $this->capture(function () {
            $this->client->testSmtpConfiguration('a@b.com', 'bob', 's3cret', 'smtp.example.com', 587);
        });
        $this->assertStringNotContainsString('s3cret', $error->getMessage());
        $this->assertStringNotContainsString('bob', $error->getMessage());
        // LIVE: a failed SMTP login comes back as a 500, not a 4xx.
        $this->assertSame(500, $error->getStatusCode());
    }

    public function testAMalformedBodyDoesNotLeakAParserDump()
    {
        $this->api->reply(200, 'application/json', '<<<not-json>>>' . str_repeat('x', 5000));
        $error = $this->capture(function () { $this->client->getSeeds(); });
        $this->assertStringContainsString('not valid JSON', $error->getMessage());
    }
}

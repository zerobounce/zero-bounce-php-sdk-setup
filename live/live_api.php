<?php
/**
 * Opt-in live API suite: calls every SDK method against the real API to check
 * payloads and response shapes.
 *
 * phpunit.xml only includes test/, so `composer test` and CI never run this.
 * Run it explicitly:
 *
 *   export ZEROBOUNCE_API_KEY=...          # validation API (skipped without it)
 *   export ZEROBOUNCE_WARMUP_API_KEY=...   # Warmup API (skipped without it)
 *   composer run test:live
 *
 * By default only free calls run: sandbox addresses (no credits), reads, and
 * requests expected to fail (unknown file IDs, S3/SFTP without an integration)
 * that still prove the API accepts the payload. The free tier checks that the
 * credit balance did not change. Two flags opt in to more:
 *
 *   ZEROBOUNCE_LIVE_SPEND=1     spends credits: scoring, activity, email
 *                               finder, and bulk round trips (every job is
 *                               deleted again)
 *   ZEROBOUNCE_LIVE_CHANGES=1   reversible account changes, each undone in
 *                               cleanup even when a test fails: a test filter,
 *                               and a self-managed Warmup sending account
 *
 * Never called, because the API cannot undo them: creating an Inbox Placement
 * test (it cannot be deleted while running), setting the Inbox Placement
 * webhook (it cannot be cleared), and ZB-managed Warmup onboarding (it starts
 * mailbox provisioning).
 *
 * No key is ever written to a file or printed. Keys come from the environment.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ZeroBounce\SDK\ZBValidateOptions;
use ZeroBounce\SDK\ZeroBounce;
use ZeroBounce\SDK\ZeroBounceWarmup;

$API_KEY = trim((string)getenv('ZEROBOUNCE_API_KEY'));
$WARMUP_KEY = trim((string)getenv('ZEROBOUNCE_WARMUP_API_KEY'));
$SPEND = getenv('ZEROBOUNCE_LIVE_SPEND') === '1';
$CHANGES = getenv('ZEROBOUNCE_LIVE_CHANGES') === '1';

/** Well-formed but matching no file: a malformed ID can be Cloudflare-blocked instead of answered. */
const UNKNOWN_ID = '0d1b7a4e-1111-2222-3333-444455556666';
const CALLBACK = 'https://example.com/zerobounce-sdk-live-test';
const SANDBOX = 'valid@example.com';

$passed = 0;
$failed = 0;
$skipped = 0;
$failures = array();

function redact($text)
{
    global $API_KEY, $WARMUP_KEY;
    $value = (string)$text;
    foreach (array($API_KEY, $WARMUP_KEY) as $secret) {
        if ($secret !== '') {
            $value = str_replace($secret, '***', $value);
        }
    }
    return $value;
}

function test($name, callable $call, $when = true, $reason = '')
{
    global $passed, $failed, $skipped, $failures;
    if (!$when) {
        $skipped++;
        echo '  - SKIP ' . $name . ($reason === '' ? '' : ' (' . $reason . ')') . "\n";
        return;
    }
    try {
        $call();
        $passed++;
        echo '  + ' . $name . "\n";
    } catch (\Throwable $e) {
        $failed++;
        $message = redact($e->getMessage());
        $failures[] = $name . ': ' . $message;
        echo '  X ' . $name . "\n      " . $message . "\n";
    }
}

function check($condition, $message)
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

/** Run $call, expecting it to fail, and hand back the (redacted) message. */
function expectFailure(callable $call, $what)
{
    try {
        $call();
    } catch (\Throwable $e) {
        return redact($e->getMessage());
    }
    throw new \RuntimeException($what . ' unexpectedly succeeded');
}

function sdk()
{
    global $API_KEY;
    $client = ZeroBounce::create();
    $client->initialize($API_KEY);
    return $client;
}

function creditBalance(ZeroBounce $client)
{
    return (int)$client->getCredits()->credits;
}

// -- validation API, free -----------------------------------------------------

function validationFree()
{
    echo "Validation API (free calls)\n";
    $client = sdk();
    $before = creditBalance($client);

    test('getCredits returns a balance', function () use ($before) {
        check($before >= 0 || $before === -1, 'credits was not a number');
    });

    test('getApiUsage parses the documented date formats', function () use ($client) {
        $end = new DateTime();
        $start = (new DateTime())->modify('-7 days');
        $usage = $client->getApiUsage($start, $end);
        check($usage->total !== null, 'no total in response');
    });

    test('validate on a sandbox address spends nothing', function () use ($client) {
        $response = $client->validate(SANDBOX);
        check($response->address === SANDBOX, 'address did not echo');
        check(is_string($response->status), 'no status');
    });

    test('validate works over POST as well as GET', function () use ($client) {
        // No billable add-on here: activity_data, verify_plus and domain_info
        // are charged even on a sandbox address, so they live in the SPEND tier.
        $options = new ZBValidateOptions();
        $options->usePost = true;
        $options->timeout = 30;
        $response = $client->validate(SANDBOX, null, $options);
        check($response !== null, 'no response');
    });

    test('LIVE: validateBatch accepts timeout as a string', function () use ($client) {
        // Sent as a JSON number the API rejects the whole batch with the
        // account-level "Invalid API Key or your account ran out of credits".
        $options = new ZBValidateOptions();
        $options->timeout = 30;
        $response = $client->validateBatch(array(SANDBOX), $options);
        check(is_array($response->emailBatch), 'no email_batch');
        foreach ((array)$response->errors as $error) {
            $address = is_array($error) && isset($error['email_address']) ? $error['email_address'] : '';
            $reason = is_array($error) && isset($error['error']) ? $error['error'] : '';
            check(strtolower((string)$address) !== 'all', 'account-level error: ' . $reason);
        }
    });

    test('LIVE: the documented endpoints answer the same over POST as over GET', function () use ($client) {
        // The API takes api_key and the parameters form-encoded in the body on
        // these endpoints, which keeps the key out of request logs.
        $end = new DateTime();
        $start = (new DateTime())->modify('-7 days');
        check((int)$client->getCredits(true)->credits >= -1, 'no credits over POST');
        check($client->getApiUsage($start, $end, true)->total !== null, 'no usage total over POST');
        check($client->findEmailFormat('example.com', null, true) !== null, 'no format over POST');
    });

    test('an unknown file id is a short, clean error', function () use ($client) {
        $message = expectFailure(function () use ($client) {
            $client->fileStatus(UNKNOWN_ID);
        }, 'fileStatus');
        check(strlen($message) < 500, 'message too long: ' . strlen($message));
        check(strpos($message, '<html') === false, 'HTML leaked into the message');
    });

    test('listS3Files answers (empty without an S3 integration)', function () use ($client) {
        try {
            $client->listS3Files();
        } catch (\Throwable $e) {
            // No S3 integration configured is an expected, documented failure.
            check(strlen($e->getMessage()) < 500, 'message too long');
        }
    });

    test('LIVE: S3/SFTP jobs reject a payload without return_url before sending', function () use ($client) {
        $message = expectFailure(function () use ($client) {
            $client->emailFinderS3File(array('file_name' => 'f.csv', 'has_header_row' => true));
        }, 'emailFinderS3File without return_url');
        check(strpos($message, 'return_url') !== false, 'unexpected message: ' . $message);
    });

    test('LIVE: a complete S3 job payload is accepted by the API', function () use ($client) {
        // Without an S3 integration this fails on the integration, not the payload.
        try {
            $client->emailFinderS3File(array(
                'file_name' => 'f.csv',
                'has_header_row' => true,
                'return_url' => CALLBACK,
            ));
        } catch (\Throwable $e) {
            $message = redact($e->getMessage());
            check(
                !preg_match('/return URL is not permitted|This field is required/i', $message),
                'payload rejected: ' . $message
            );
        }
    });

    test('filters list', function () use ($client) {
        $client->listFilters();
    });

    test('LIVE: the Inbox Placement Tester accepts a Bearer key', function () use ($client) {
        $providers = $client->getInboxPlacementProviders();
        check($providers !== null, 'no providers payload');
    });

    test('inbox placement test types', function () use ($client) {
        check($client->getInboxPlacementTestTypes() !== null, 'no test types');
    });

    test('LIVE: inbox placement tests are listed from /tests, and the webhook reads back',
        function () use ($client) {
            // The listing is GET /tests (plural); /test is the single-test path
            // and answers "Method \"GET\" not allowed".
            $client->listInboxPlacementTests(null, null, null, 1, 10);
            $client->getInboxPlacementWebhook();
        });

    test('list evaluator result for an unknown id fails cleanly', function () use ($client) {
        $message = expectFailure(function () use ($client) {
            $client->getListEvaluatorResult(UNKNOWN_ID);
        }, 'getListEvaluatorResult');
        check(strlen($message) < 500, 'message too long');
    });

    test('LIVE: sendEvaluatedFile posts the file id as a form field', function () use ($client) {
        // The endpoint takes file_id in the body, not in the path.
        $message = expectFailure(function () use ($client) {
            $client->sendEvaluatedFile(UNKNOWN_ID);
        }, 'sendEvaluatedFile');
        check(strlen($message) < 500, 'message too long');
        check(stripos($message, 'not allowed') === false, 'wrong method or path: ' . $message);
    });

    test('the free calls did not change the credit balance', function () use ($before) {
        $after = creditBalance(sdk());
        check($after === $before, 'credits went from ' . $before . ' to ' . $after);
    });
}

// -- validation API, spends credits -------------------------------------------

function validationSpend()
{
    echo "\nValidation API (ZEROBOUNCE_LIVE_SPEND=1)\n";
    $client = sdk();

    test('validate accepts every documented option', function () use ($client) {
        // LIVE: activity_data, verify_plus and domain_info are billable add-ons
        // and are charged even for a sandbox address, so this is not free.
        $options = new ZBValidateOptions();
        $options->timeout = 30;
        $options->activityData = true;
        $options->verifyPlus = true;
        $options->domainInfo = true;
        $options->greylistProcessing = true;
        $options->callbackUrl = CALLBACK;
        check($client->validate(SANDBOX, null, $options) !== null, 'no response');
    });

    test('scoreEmail', function () use ($client) {
        check($client->scoreEmail(SANDBOX) !== null, 'no response');
    });

    test('scoreEmail over POST', function () use ($client) {
        check($client->scoreEmailPost(SANDBOX) !== null, 'no response');
    });

    test('getActivity', function () use ($client) {
        check($client->getActivity(SANDBOX) !== null, 'no response');
    });

    test('email finder', function () use ($client) {
        check($client->findEmail('example.com', 'John', null, null, 'Doe') !== null, 'no response');
    });

    test('domain search', function () use ($client) {
        check($client->findEmailFormat('example.com') !== null, 'no response');
    });

    $fileId = null;
    test('bulk validation round trip: upload, status, delete', function () use ($client, &$fileId) {
        $csv = "email\nvalid@example.com\ninvalid@example.com\n";
        $sent = $client->sendFileStream($csv, 'zb-sdk-live.csv', 1, null, null, null, null, null, true);
        check(!empty($sent->fileId), 'no file_id in the upload response');
        $fileId = $sent->fileId;
        $status = $client->fileStatus($fileId);
        check($status->fileId === $fileId, 'status did not echo the file id');
    });

    test('the uploaded validation job is deleted again', function () use ($client, &$fileId) {
        // LIVE: a just-accepted job answers "File cannot be found" for a short
        // while, so the delete is retried rather than leaving the job behind.
        $lastError = '';
        for ($attempt = 0; $attempt < 12; $attempt++) {
            try {
                $client->deleteFile($fileId);
                return;
            } catch (\Throwable $e) {
                $lastError = redact($e->getMessage());
                sleep(10);
            }
        }
        throw new \RuntimeException('could not delete ' . $fileId . ' after retrying: ' . $lastError);
    }, $fileId !== null, 'upload did not produce a file id');
}

// -- validation API, reversible account changes -------------------------------

function validationChanges()
{
    echo "\nValidation API (ZEROBOUNCE_LIVE_CHANGES=1)\n";
    $client = sdk();
    $value = 'zb-sdk-live-' . time() . '.example.com';
    $added = false;

    try {
        test('addFilter creates a reversible allow rule', function () use ($client, $value, &$added) {
            $client->addFilter('allow', 'domain', $value);
            $added = true;
        });
    } finally {
        test('the test filter is removed again', function () use ($client, $value) {
            $client->deleteFilter('allow', 'domain', $value);
        }, $added, 'filter was not created');
    }
}

// -- Warmup API ---------------------------------------------------------------

function warmup()
{
    global $WARMUP_KEY;
    return new ZeroBounceWarmup($WARMUP_KEY);
}

function warmupFree()
{
    global $WARMUP_KEY;
    echo "\nWarmup API (reads)\n";
    $client = warmup();

    $reads = array(
        'listSendingAccounts', 'listFromAddresses', 'getEngagementRules', 'getSeeds',
        'getSeedCount', 'getProgressToday', 'getSentToday', 'getEmailsPerDay',
    );
    foreach ($reads as $name) {
        test($name, function () use ($client, $name) {
            // A documented empty success comes back as null, which is a result.
            $client->{$name}();
        });
    }

    test('downloadSeeds returns CSV text', function () use ($client) {
        $csv = $client->downloadSeeds();
        check(is_string($csv) && $csv !== '', 'no CSV body');
        check(strpos(ltrim($csv), '{') !== 0, 'got JSON instead of CSV');
    });

    test('LIVE: checkDomainDmarc rejects a bare domain before sending', function () use ($client) {
        $message = expectFailure(function () use ($client) {
            $client->checkDomainDmarc('example.com');
        }, 'checkDomainDmarc with a domain');
        check(stripos($message, 'email address') !== false, 'unexpected message: ' . $message);
    });

    test('LIVE: checkDomainDmarc accepts an email address', function () use ($client) {
        check($client->checkDomainDmarc('sender@example.com') !== null, 'no response');
    });

    // The statistics endpoints need a from-address this team actually has; the
    // API answers "Sending account ... was not found" for anything else.
    $known = null;
    try {
        $accounts = $client->listSendingAccounts();
        $list = isset($accounts['data']) ? $accounts['data'] : $accounts;
        if (is_array($list) && isset($list[0]) && is_array($list[0])) {
            foreach (array('from_address', 'c_from', 'fromAddress') as $key) {
                if (!empty($list[0][$key])) {
                    $known = $list[0][$key];
                    break;
                }
            }
        }
    } catch (\Throwable $e) {
        $known = null;
    }
    $has = $known !== null;

    test('statistics for a registered from-address', function () use ($client, $known) {
        $client->getStatistics($known);
    }, $has, 'this team has no sending account');

    test('statistics per day over a short range', function () use ($client, $known) {
        $client->getStatisticsPerDay($known, (new DateTime())->modify('-6 days'), new DateTime());
    }, $has, 'this team has no sending account');

    test('detailed statistics and the CSV download for that address', function () use ($client, $known) {
        $client->getStatisticsDetailed($known);
        check(is_string($client->downloadStatistics($known)), 'no CSV body');
    }, $has, 'this team has no sending account');

    test('from-address statistics', function () use ($client, $known) {
        $client->getFromAddressStatistics($known);
    }, $has, 'this team has no sending account');

    test('an unknown Warmup resource fails with a status code', function () use ($client, $WARMUP_KEY) {
        try {
            $client->getCustomEngagement('no-such-address@example.com');
        } catch (\ZeroBounce\SDK\ZBApiException $e) {
            check(is_int($e->getStatusCode()), 'no status code on the error');
            check(strpos($e->getMessage(), $WARMUP_KEY) === false, 'the key leaked into the message');
        }
    });
}

function warmupChanges()
{
    echo "\nWarmup API (ZEROBOUNCE_LIVE_CHANGES=1)\n";
    $client = warmup();
    $address = 'zb-sdk-live-' . time() . '@example.com';
    $added = false;

    try {
        test('a self-managed sending account can be added', function () use ($client, $address, &$added) {
            $client->addSendingAccount($address, 'self_managed', 0, true);
            $added = true;
        });
    } finally {
        test('the test sending account is removed again', function () use ($client, $address) {
            $client->deleteSendingAccount($address);
        }, $added, 'account was not created');
    }
}

// -- main ---------------------------------------------------------------------

echo "ZeroBounce live API suite\n";
echo '  validation key: ' . ($API_KEY !== '' ? 'set' : 'MISSING') . "\n";
echo '  warmup key:     ' . ($WARMUP_KEY !== '' ? 'set' : 'MISSING') . "\n";
echo '  flags:          SPEND=' . ($SPEND ? 1 : 0) . ' CHANGES=' . ($CHANGES ? 1 : 0) . "\n\n";

if ($API_KEY !== '') {
    validationFree();
}
if ($API_KEY !== '' && $SPEND) {
    validationSpend();
}
if ($API_KEY !== '' && $CHANGES) {
    validationChanges();
}
if ($WARMUP_KEY !== '') {
    warmupFree();
}
if ($WARMUP_KEY !== '' && $CHANGES) {
    warmupChanges();
}

echo "\n" . $passed . ' passed, ' . $failed . ' failed, ' . $skipped . " skipped\n";
if (!empty($failures)) {
    echo "\nFailures:\n";
    foreach ($failures as $line) {
        echo '  - ' . $line . "\n";
    }
}
exit($failed ? 1 : 0);

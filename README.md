
# ZeroBounce PHP SDK
 

## Security

- Keep API keys on a trusted server. Do not embed them in mobile apps or browser JavaScript that untrusted users can inspect.
- Custom API base URLs (when supported) must use `https://`. Do not pass end-user-controlled hosts into those settings.
- Request URLs include `api_key` as a query parameter (ZeroBounce API contract). Do not log full request URLs or enable payload debug logging in production.

This SDK contains methods for interacting easily with ZeroBounce API. 
More information about ZeroBounce can be find in the [official documentation](https://www.zerobounce.net/docs/).

## Installation
To install the SDK you will need to use [composer](https://getcomposer.org/) in your project.
If you're not using composer, you can install it like so:
```bash
curl -sS https://getcomposer.org/installer | php
# or
sudo apt install -y composer
```

To install the SDK with composer, run:
```bash
composer install zero-bounce/sdk
#or
composer require zero-bounce/sdk
```

### Laravel
This package is Laravel compatible as is:
```bash
composer create-project laravel/laravel laravel-zero-bounce-test
laravel-zero-bounce-test
composer require zero-bounce/sdk
php artisan make:command ZeroBounceTest
```

```php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use ZeroBounce\SDK\ZeroBounce;

class ZeroBounceTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:zero-bounce-test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        ZeroBounce::Instance()->initialize("<YOUR_API_KEY>");
        $response = ZeroBounce::Instance()->getCredits();
        print_r($response);
    }
}
```

```bash
$ php artisan app:zero-bounce-test
ZeroBounce\SDK\ZBGetCreditsResponse Object
(
    [credits] => -1
)
```

However, this package is framework agnostic, so if you need it implemented in a service provider class, you'll need to [implement it](https://laravel.com/docs/10.x/providers).

## Usage
- _include the SDK in your file (you should always use Composer's autoloader in your application to automatically load your dependencies)_
```php
require 'vendor/autoload.php';
use ZeroBounce\SDK\ZeroBounce;
```

- _initialize the SDK with your API key_
```php
ZeroBounce::Instance()->initialize("<YOUR_API_KEY>");
```

- _Optional: customize the base URL. Accepted values are `API_DEFAULT_URL`(default), `API_USA_URL` and `API_EU_URL`. Invalid values will fall back to the default one._
```php
// you can use the provided constants
use ZeroBounce\SDK\ZBBaseUrl;
// ...
ZeroBounce::Instance()->initialize("<YOUR_API_KEY>", ZBBaseUrl::API_USA_URL);
// or
ZeroBounce::Instance()->initialize("<YOUR_API_KEY>", ZBBaseUrl::API_EU_URL);

// you can also hardcode the URL
ZeroBounce::Instance()->initialize("<YOUR_API_KEY>", "https://api-us.zerobounce.net/v2");
// or
ZeroBounce::Instance()->initialize("<YOUR_API_KEY>", "https://api-eu.zerobounce.net/v2");
```

### _Method documentation_

- Verify an email address:
```php
/** @var $response ZeroBounce\SDK\ZBValidateResponse */
$response = ZeroBounce::Instance()->validate(
                "<EMAIL_ADDRESS>",              // The email address you want to validate
                "<IP_ADDRESS>"                  // The IP Address the email signed up from (Can be blank)
            );

// can be: valid, invalid, catch-all, unknown, spamtrap, abuse, do_not_mail
$status = $response->status;
```

- Validate with domain info and greylist processing:
```php
use ZeroBounce\SDK\ZBValidateOptions;

$options = new ZBValidateOptions();
// domainInfo adds domainWebsiteExists and domainRegistrantCompanyName.
$options->domainInfo = true;
// greylistProcessing re-runs a greylisted address instead of returning unknown.
$options->greylistProcessing = true;
// callbackUrl is only allowed together with greylistProcessing = true.
$options->callbackUrl = "https://example.com/zerobounce-callback";

$response = ZeroBounce::Instance()->validate("<EMAIL_ADDRESS>", null, $options);

// While the greylist retry is still running the API returns a message
// instead of a result; validate again in about 30 minutes to pick it up.
echo $response->message;
echo $response->domainWebsiteExists;
```

- S3 and SFTP/FTP bulk jobs:
```php
// Amazon S3: https://www.zerobounce.net/docs/api-integrations/amazon-s3-bulk-api
ZeroBounce::Instance()->emailFinderS3File(array(
    'file_name' => 'lists/leads.csv',
    'has_header_row' => true,
));
ZeroBounce::Instance()->domainSearchS3File(array(
    'file_name' => 'lists/leads.csv',
    'has_header_row' => true,
    'remove_duplicates' => true,
    'return_url' => 'https://example.com/zerobounce-done',
));

// SFTP/FTP: https://www.zerobounce.net/docs/api-integrations/sftp_bulk_api
// The server, path and protocol come from your ZeroBounce settings.
// file_name is optional for validateSFTPFile only.
ZeroBounce::Instance()->validateSFTPFile(array('has_header_row' => true));
ZeroBounce::Instance()->scoreSFTPFile(array('file_name' => 'my_file.txt', 'has_header_row' => true));
ZeroBounce::Instance()->emailFinderSFTPFile(array('file_name' => 'my_file.txt', 'has_header_row' => true));
ZeroBounce::Instance()->domainSearchSFTPFile(array('file_name' => 'my_file.txt', 'has_header_row' => true));

// exportSFTPFile takes export_path, not export_bucket. Omit it to use your settings.
ZeroBounce::Instance()->exportSFTPFile('<FILE_ID>', '/exports');
```

- Inbox Placement Tester:
```php
// Lives on https://bulkapi.zerobounce.net/api/ipt and spends inbox test credits.
$providers = ZeroBounce::Instance()->getInboxPlacementProviders();
$testTypes = ZeroBounce::Instance()->getInboxPlacementTestTypes();

// testType 1 (Email) needs a sender email; 2 is Unique ID and 3 is Auto Alias.
$created = ZeroBounce::Instance()->createInboxPlacementTest(
    "October campaign",                        // required, at most 64 characters
    1,                                         // testType
    "news@example.com",                        // senderEmail, required for testType 1
    null,                                      // providerIds
    null,                                      // seedIds
    array(array("id" => 1, "maxSeeds" => 5)),  // per-provider seed cap
    "https://example.com/ipt-webhook"
);

// Send your campaign to the seeds in $created->data, then read the results.
$result = ZeroBounce::Instance()->getInboxPlacementTest($created->data["id"]);

$tests = ZeroBounce::Instance()->listInboxPlacementTests("finished", null, null, 1, 25);

ZeroBounce::Instance()->setInboxPlacementWebhook("https://example.com/ipt-webhook");
$webhook = ZeroBounce::Instance()->getInboxPlacementWebhook();
ZeroBounce::Instance()->deleteInboxPlacementTest($created->data["id"]);
```

- Point an API at a different base URL:
```php
// The list evaluator and the Inbox Placement Tester have their own base URLs,
// separate from the main and bulk hosts.
ZeroBounce::Instance()->initialize(
    "<YOUR_API_KEY>",
    null,                                                   // main API base URL
    "https://bulkapi.zerobounce.net/v2/listevaluator",      // list evaluator
    "https://bulkapi.zerobounce.net/api/ipt"                // Inbox Placement Tester
);
```

- Verify a batch of email addresses:
```php
/** @var response ZeroBounce\SDK\ZBBatchValidateResponse */
$response = ZeroBounce::Instance()->validateBatch([
		"EMAIL_ADDRESS_1", 		// Email address that needs to be validated
		"EMAIL_ADDRESS_2", 
		"EMAIL_ADDRESS_3",
	...
	]);
// or
$response = ZeroBounce::Instance()->validateBatch([
		["EMAIL_ADDRESS_1", "IP_ADDRESS_1"],	// Email and IP address that need to be validated
		["EMAIL_ADDRESS_2", "IP_ADDRESS_2"],
		["EMAIL_ADDRESS_3", "IP_ADDRESS_3"],
		...
	]);
// => 
$response->emailBatch 	// array of ZBValidateReponse type objects
```

- Check how many credits you have left on your account
```php
/** @var $response ZeroBounce\SDK\ZBGetCreditsResponse */
$response = ZeroBounce::Instance()->getCredits();
$credits = $response->credits;
```

- Check your API usage for a given period of time
```php
$startDate = new DateTime("-1 month"); // The start date of when you want to view API usage
$endDate = new DateTime();             // The end date of when you want to view API usage

/** @var $response ZeroBounce\SDK\ZBApiUsageResponse */
$response = ZeroBounce::Instance()->getApiUsage($startDate, $endDate);
$usage = $response->total;
```

- Check the activity of a subscriber given their email account
```php
/** @var $response ZeroBounce\SDK\ZBActivityResponse */
$response = ZeroBounce::Instance()->getActivity("<EMAIL_ADDRESS>");
$active_in_days = $response->activeInDays;
```

- Send a file for bulk email validation
```php
/** @var $response ZeroBounce\SDK\ZBSendFileResponse */
$response = ZeroBounce::Instance()->sendFile(
    "<FILE_PATH>",              // The csv or txt file
    "<EMAIL_ADDRESS_COLUMN>",   // The column index of the email address in the file. Index starts at 1
    "<RETURN_URL>",             // The URL will be used as a callback after the file is sent
    "<FIRST_NAME_COLUMN>",      // The column index of the user's first name in the file
    "<LAST_NAME_COLUMN>",       // The column index of the user's last name in the file
    "<GENDER_COLUMN>",          // The column index of the user's gender in the file
    "<IP_ADDRESS_COLUMN>",      // The column index of the IP address in the file
    "<HAS_HEADER_ROW>",         // If the first row from the submitted file is a header row. True or False
    true                        // Optional ninth argument: allowPhase2 — sends allow_phase_2 (validation bulk only; omit or null to skip)
);
$fileId = $response->fileId;    // e.g. "aaaaaaaa-zzzz-xxxx-yyyy-5003727fffff"
```

Bulk validation uses `https://bulkapi.zerobounce.net/v2`. See [v2 send file](https://www.zerobounce.net/docs/email-validation-api-quickstart/v2-send-file), [v2 file status](https://www.zerobounce.net/docs/email-validation-api-quickstart/v2-file-status), and [v2 get file](https://www.zerobounce.net/docs/email-validation-api-quickstart/v2-get-file).

- Check the status of a file uploaded via "sendFile" method
```php
$fileId = "<FILE_ID>";   // The file ID received from "sendFile" response
 
/** @var $response ZeroBounce\SDK\ZBFileStatusResponse */
$response = ZeroBounce::Instance()->fileStatus($fileId);
$status = $response->fileStatus;              // e.g. "Complete"
$phase2 = $response->filePhase2Status;      // When present (e.g. "N/A", "Processing", "Complete")
$errorReason = $response->errorReason;        // When present
```

- Get the validation results file for the file been submitted using sendfile API
```php
$fileId = "<FILE_ID>";              // The file ID received from "sendFile" response
$downloadPath = "<DOWNLOAD_PATH>";  // The path where the file will be downloaded
 
/** @var $response ZeroBounce\SDK\ZBGetFileResponse */
$response = ZeroBounce::Instance()->getFile($fileId, $downloadPath);
$localPath = $response->localFilePath;
```

Optional [v2 get file](https://www.zerobounce.net/docs/email-validation-api-quickstart/v2-get-file) query parameters use `ZBGetFileOptions` and `ZBDownloadType` (`PHASE_1`, `PHASE_2`, `COMBINED`). Set `activityData` on the options object for validation `getFile` only; it is not sent for `scoringGetFile`.

```php
use ZeroBounce\SDK\ZBGetFileOptions;
use ZeroBounce\SDK\ZBDownloadType;

$options = new ZBGetFileOptions();
$options->downloadType = ZBDownloadType::COMBINED;
$options->activityData = true;

$response = ZeroBounce::Instance()->getFile($fileId, $downloadPath, $options);
```

Set `$options->stream = true` for a large result file. The download is written to disk in chunks instead of being held in memory: the bytes go to a temporary file beside `$downloadPath` and move into place only once the transfer finishes, so a failed download leaves nothing behind.

```php
$options = new ZBGetFileOptions();
$options->stream = true;

$response = ZeroBounce::Instance()->getFile($fileId, $downloadPath, $options);
```

If the API returns a non-success HTTP status or a JSON error body (including some HTTP 200 responses with `success: false`), the SDK throws `ZBApiException`, whose `getStatusCode()` gives the HTTP status. To inspect raw bodies yourself, use `ZeroBounce::getFileJsonIndicatesError($jsonString)`.

- Deletes the file that was submitted using scoring sendfile API. File can be deleted only when its status is _`Complete`_
```php
$fileId = "<FILE_ID>";              // The file ID received from "sendFile" response
 
/** @var $response ZeroBounce\SDK\ZBDeleteFileResponse */
$response = ZeroBounce::Instance()->deleteFile($fileId);
$success = $response->success;      // True / False
```

#### AI Scoring API
- The scoring sendfile API allows a user to send a file for bulk email scoring
```php
/** @var $response ZeroBounce\SDK\ZBSendFileResponse */
$response = ZeroBounce::Instance()->scoringSendFile(
    "<FILE_PATH>",              // The csv or txt file
    "<EMAIL_ADDRESS_COLUMN>",   // The column index of the email address in the file. Index starts at 1
    "<RETURN_URL>",             // The URL will be used as a callback after the file is sent
    "<HAS_HEADER_ROW>"          // If the first row from the submitted file is a header row. True or False
);
$fileId = $response->fileId;    // e.g. "aaaaaaaa-zzzz-xxxx-yyyy-5003727fffff"
```

- Check the status of a file uploaded via "scoringSendFile" method
```php
$fileId = "<FILE_ID>";   // The file ID received from "sendFile" response
 
/** @var $response ZeroBounce\SDK\ZBFileStatusResponse */
$response = ZeroBounce::Instance()->scoringFileStatus($fileId);
$status = $response->fileStatus;    // e.g. "Complete"
```

- Get the validation results file for the file been submitted using scoringSendfile API
```php
$fileId = "<FILE_ID>";              // The file ID received from "sendFile" response
$downloadPath = "<DOWNLOAD_PATH>";  // The path where the file will be downloaded
 
/** @var $response ZeroBounce\SDK\ZBGetFileResponse */
$response = ZeroBounce::Instance()->scoringGetFile($fileId, $downloadPath);
$localPath = $response->localFilePath;

// Optional third argument: ZBGetFileOptions with downloadType only (activity_data is not used for scoring getfile)
// $response = ZeroBounce::Instance()->scoringGetFile($fileId, $downloadPath, $options);
```

- Deletes the file that was submitted using scoringSendfile API. File can be deleted only when its status is _`Complete`_
```php
$fileId = "<FILE_ID>";              // The file ID received from "sendFile" response
 
/** @var $response ZeroBounce\SDK\ZBDeleteFileResponse */
$response = ZeroBounce::Instance()->scoringDeleteFile($fileId);
$success = $response->success;      // True / False
```

#### Email Finder API
Guess an email address. The required arguments are `firstName` and either `domain` or `companyName`. If both are provided, only `domain` is used. If necessary, `domain` can be `null`.
```php
$response = ZeroBounce::Instance()->findEmail(
    $domain, $firstName, $companyName, $middleName, $lastName
);
$email = $response->email;
```

`getCredits`, `getApiUsage`, `getActivity`, `scoreEmail`, `findEmail` and `findEmailFormat` take a trailing `$usePost`. The API accepts the same parameters form-encoded, which keeps the key out of request logs:

```php
$response = ZeroBounce::Instance()->getCredits(true);
```

Guess the format of email addresses for a domain or company. Must provide either `domain` or `companyName`. If both are provided, only `domain` is used. If necessary, `domain` can be `null`.
```php
$response = ZeroBounce::Instance()->findEmailFormat($domain, $companyName);
$email = $response->format;
```

## Warmup API

The Warmup API is a separate product. It has its own host, its own team-scoped key, and its own client; your validation key will not work with it.

```php
use ZeroBounce\SDK\ZeroBounceWarmup;

$warmup = new ZeroBounceWarmup("<YOUR_WARMUP_API_KEY>");

$accounts = $warmup->listSendingAccounts();
$progress = $warmup->getProgressToday();
$csv      = $warmup->downloadSeeds();            // CSV text, columns seed,provider
```

Add a from-address you run yourself, with one of the five standard engagement rules (0 Default, 1 Slow Pace, 2 Repair, 3 Boost, 4 Protect):

```php
$warmup->addSendingAccount("sender@example.com", "self_managed", 0);
```

Or have ZeroBounce provision the mailbox. A 200 means the request was accepted; provisioning happens afterwards.

```php
$warmup->addSendingAccount(
    "sender@example.com",
    "zb_managed",
    null,
    null,
    array("types_of_emails" => array("newsletter", "marketing")),
    array("timezone_offset" => -5, "warmup_start_date" => new DateTime("2026-03-04"))
);
```

A custom engagement rule can be changed only once every seven days; inside that window the API answers 429.

```php
$warmup->createCustomEngagement("sender@example.com", 20, 20, 35);  // reply, important, click %
```

`checkDomainDmarc` takes an **email address**, not a domain — the live API rejects a bare domain even though the docs describe one:

```php
$dmarc = $warmup->checkDomainDmarc("sender@example.com");
```

Every failure is a `ZBApiException`, whose `getStatusCode()` gives the HTTP status. Nothing is stored by `testSmtpConfiguration`, and the credentials you pass it are masked out of any error it raises.

## Development

### Run tests with Docker
From the **`sdk-docs/`** folder in the SDKs monorepo:

```bash
cd sdk-docs
docker compose build php
docker compose run --rm php
```

### Run tests (local)
Install required PHP modules
```bash
sudo apt install -y php-curl php-dom php-xml php-xmlwriter
```

Install development dependencies
```bash
composer install --dev
```

Run tests 
```bash
./vendor/bin/phpunit test
```

### Live API suite

`phpunit.xml` only includes `test/`, so neither `composer test` nor CI ever touches the network. The live suite is opt-in and needs real keys, which come from the environment and are never written anywhere:

```bash
export ZEROBOUNCE_API_KEY=...          # validation API
export ZEROBOUNCE_WARMUP_API_KEY=...   # Warmup API
composer run test:live
```

By default it only makes free calls: sandbox addresses, reads, and requests that are expected to fail but still prove the API accepts the payload. It checks that the credit balance did not move. Two flags opt in to more:

| Flag | What it adds |
| --- | --- |
| `ZEROBOUNCE_LIVE_SPEND=1` | billable calls (scoring, activity, email finder) and a bulk round trip that deletes its own job |
| `ZEROBOUNCE_LIVE_CHANGES=1` | reversible account changes — a test filter and a self-managed Warmup sending account — each undone in cleanup even when a test fails |

It never creates an Inbox Placement test, sets the Inbox Placement webhook, or starts ZB-managed Warmup onboarding, because the API cannot undo any of those.

Run it by hand before every release: the offline suite proves the SDK sends what we think it sends, and only the live suite proves the API accepts it.

## Publish

1. Bump `version` in `composer.json`, commit, tag (`vX.Y.Z`), push tag.
2. **Actions → Publish → Run workflow** with that tag.

Registry: [zero-bounce/sdk on Packagist](https://packagist.org/packages/zero-bounce/sdk)

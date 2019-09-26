## ZeroBounce PHP SDK
 
This SDK contains methods for interacting easily with ZeroBounce API. 
More information about ZeroBounce you can find in the [official documentation](https://www.zerobounce.net/docs/).

## Installation
To install the SDK you will need to use [composer](https://getcomposer.org/) in your project.
If you're not using composer, you can install it like so:
```bash
curl -sS https://getcomposer.org/installer | php
```

To install the SDK with composer, run:
```bash
composer install zero-bounce/sdk
```

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

### _Method documentation_

- Verify an email address:
```php
/** @var $response ZeroBounse\SDK\ZBValidateResponse */
$response = ZeroBounce::Instance()->validate(
                "<EMAIL_ADDRESS>",              // The email address you want to validate
                "<IP_ADDRESS>"                  // The IP Address the email signed up from (Can be blank)
            );

// can be: valid, invalid, catch-all, unknown, spamtrap, abuse, do_not_mail
$status = $response->status;
```

- Check how many credits you have left on your account
```php
/** @var $response ZeroBounse\SDK\ZBGetCreditsResponse */
$response = ZeroBounce::Instance()->getCredits();
$credits = $response->credits;
```

- Check your API usage for a given period of time
```php
$startDate = new DateTime("-1 month"); // The start date of when you want to view API usage
$endDate = new DateTime();             // The end date of when you want to view API usage

/** @var $response ZeroBounse\SDK\ZBApiUsageResponse */
$response = ZeroBounce::Instance()->getApiUsage($startDate, $endDate);
$usage = $response->total;
```

- Send a file for bulk email validation
```php
/** @var $response ZeroBounse\SDK\ZBSendFileResponse */
$response = ZeroBounce::Instance()->sendFile(
    "<FILE_PATH>",              // The csv or txt file
    "<EMAIL_ADDRESS_COLUMN>",   // The column index of the email address in the file. Index starts at 1
    "<RETURN_URL>",             // The URL will be used as a callback after the file is sent
    "<FIRST_NAME_COLUMN>",      // The column index of the user's first name in the file
    "<LAST_NAME_COLUMN>",       // The column index of the user's last name in the file
    "<GENDER_COLUMN>",          // The column index of the user's gender in the file
    "<IP_ADDRESS_COLUMN>",      // The column index of the IP address in the file
    "<HAS_HEADER_ROW>"          // If the first row from the submitted file is a header row. True or False
);
$fileId = $response->fileId;    // e.g. "aaaaaaaa-zzzz-xxxx-yyyy-5003727fffff"
```

- Check the status of a file uploaded via "sendFile" method
```php
$fileId = "<FILE_ID>";   // The file ID received from "sendFile" response
 
/** @var $response ZeroBounse\SDK\ZBFileStatusResponse */
$response = ZeroBounce::Instance()->fileStatus($fileId);
$status = $response->fileStatus;    // e.g. "Complete"
```

- Get the validation results file for the file been submitted using sendfile API
```php
$fileId = "<FILE_ID>";              // The file ID received from "sendFile" response
$downloadPath = "<DOWNLOAD_PATH>";  // The path where the file will be downloaded
 
/** @var $response ZeroBounse\SDK\ZBGetFileResponse */
$response = ZeroBounce::Instance()->getFile($fileId, $downloadPath);
$localPath = $response->localFilePath;
```

- Deletes the file that was submitted using scoring sendfile API. ile can be deleted only when its status is _`Complete`_
```php
$fileId = "<FILE_ID>";              // The file ID received from "sendFile" response
 
/** @var $response ZeroBounse\SDK\ZBDeleteFileResponse */
$response = ZeroBounce::Instance()->deleteFile($fileId);
$success = $response->success;      // True / False
```

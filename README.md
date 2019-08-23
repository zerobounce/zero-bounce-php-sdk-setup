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
- include the SDK in your file (you should always use Composer's autoloader in your application to automatically load your dependencies)
```php
require 'vendor/autoload.php';
use ZeroBounce\SDK\ZeroBounce;
```

- initialize the SDK with your API key
```php
ZeroBounce::Instance()->initialize("<YOUR_API_KEY>");
```

- how to verify an email address:
```php
/** @var $response ZeroBounse\SDK\ZBValidateResponse */
$response = ZeroBounce::Instance()->validate(
                "<EMAIL_ADDRESS>",              // The email address you want to validate
                "<IP_ADDRESS>"                  // The IP Address the email signed up from (Can be blank)
            );

// can be: valid, invalid, catch-all, unknown, spamtrap, abuse, do_not_mail
$status = $response->status;
```

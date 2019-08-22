## ZeroBounce PHP SDK

This SDK contains methods for interacting easily with ZeroBounce API. 
More information about ZeroBounce you can find in their [official documentation](https://www.zerobounce.net/docs/).

## Installation
To install the SDK you will need to use [composer](https://getcomposer.org/) in your project.
If you're not using composer, you can install it like so:
```bash
curl -sS https://getcomposer.org/installer | php
```

To install the SDK with composer, run:
```bash
composer install zerobouncesdk
```

## Usage
You should always use Composer's autoloader in your application to automatically load your dependencies. 
The examples below assume you've already included this in your file:
```php
require 'vendor/autoload.php';
use ZeroBounce\SDK\ZeroBounce;
```

Here's an example about how to check your ZeroBounce API usage:
```php
$zb = new ZeroBounce();
$zb->initialize(<YOUR_API_KEY>);

$startDate = new DateTime("-7 Days");
$endDate   = new DateTime();

/** @var $apiUsage ZeroBounce\SDK\ZBResponse */
$apiUsage = $zb->getApiUsage($startDate, $endDate);
$total = $apiUsage->total;
```

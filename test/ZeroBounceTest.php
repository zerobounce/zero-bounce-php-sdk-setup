<?php

namespace ZeroBounce\Tests;

require_once __DIR__ . '/../vendor/autoload.php';
require_once('MockZeroBounce.php');

use ZeroBounce\SDK\ZBMissingApiKeyException;
use ZeroBounce\SDK\ZBMissingParameterException;
use ZeroBounce\SDK\ZBValidateStatus;
use ZeroBounce\Tests\MockZeroBounce as ZeroBounce;
use PHPUnit\Framework\TestCase;

ZeroBounce::Instance()->initialize("dummy_key");

class ZeroBounceTest extends TestCase
{

    public function testValidate()
    {
        ZeroBounce::Instance()->responseText = "{
            \"address\": \"valid@example.com\",
            \"status\": \"valid\",
            \"sub_status\": \"\",
            \"domain_age_days\": \"9692\",
            \"firstname\": \"zero\",
            \"lastname\": \"bounce\",
            \"gender\": \"male\"
        }";
        $response = ZeroBounce::Instance()->validate("valid@example.com");
        $this->assertEquals($response->address, "valid@example.com");
        $this->assertEquals($response->status, ZBValidateStatus::Valid);
    }

    public function testGetCredits()
    {
        ZeroBounce::Instance()->responseText = "{
            \"Credits\": \"50\"
        }";
        $response = ZeroBounce::Instance()->getCredits();
        $this->assertEquals($response->credits, "50");
    }

    public function testGetApiUsage()
    {
        ZeroBounce::Instance()->responseText = "{
            \"total\": 10,
            \"start_date\": \"3/15/2023\",
            \"end_date\": \"3/23/2023\"
        }";
        $start_date = new \DateTime('7 days ago');
        $end_date = new \DateTime();
        $response = ZeroBounce::Instance()->getApiUsage($start_date, $end_date);
        $this->assertEquals($response->total, 10);
        $this->assertEquals($response->startDate, "3/15/2023");
    }

    public function testGetActivity()
    {
        ZeroBounce::Instance()->responseText = "{
            \"found\": true,
            \"active_in_days\": \"180\"
        }";
        $response = ZeroBounce::Instance()->getActivity("valid@example.com");
        $this->assertEquals($response->found, true);
        $this->assertEquals($response->activeInDays, 180);
    }
}
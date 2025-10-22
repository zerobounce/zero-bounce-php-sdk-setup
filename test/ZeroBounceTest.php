<?php

namespace ZeroBounce\Tests;

require_once __DIR__ . '/../vendor/autoload.php';
require_once('MockZeroBounce.php');

use ZeroBounce\SDK\ZBValidateStatus;
use ZeroBounce\Tests\MockZeroBounce as ZeroBounce;
use PHPUnit\Framework\TestCase;

ZeroBounce::Instance()->initialize("dummy_key");

class ZeroBounceTest extends TestCase
{

    public function testValidateBatch()
    {
        ZeroBounce::Instance()->responseText = "{
            \"email_batch\": [
                {
                    \"address\": \"disposable@example.com\",
                    \"status\": \"do_not_mail\",
                    \"sub_status\": \"disposable\",
                    \"free_email\": false,
                    \"did_you_mean\": null,
                    \"account\": null,
                    \"domain\": null,
                    \"domain_age_days\": \"9692\",
                    \"smtp_provider\": \"example\",
                    \"mx_found\": \"true\",
                    \"mx_record\": \"mx.example.com\",
                    \"firstname\": \"zero\",
                    \"lastname\": \"bounce\",
                    \"gender\": \"male\",
                    \"country\": null,
                    \"region\": null,
                    \"city\": null,
                    \"zipcode\": null,
                    \"processed_at\": \"2023-05-16 16:33:51.059\"
                },
                {
                    \"address\": \"invalid@example.com\",
                    \"status\": \"invalid\",
                    \"sub_status\": \"mailbox_not_found\",
                    \"free_email\": false,
                    \"did_you_mean\": null,
                    \"account\": null,
                    \"domain\": null,
                    \"domain_age_days\": \"9692\",
                    \"smtp_provider\": \"example\",
                    \"mx_found\": \"true\",
                    \"mx_record\": \"mx.example.com\",
                    \"firstname\": \"zero\",
                    \"lastname\": \"bounce\",
                    \"gender\": \"male\",
                    \"country\": null,
                    \"region\": null,
                    \"city\": null,
                    \"zipcode\": null,
                    \"processed_at\": \"2023-05-16 16:33:51.059\"
                },
                {
                    \"address\": \"valid@example.com\",
                    \"status\": \"valid\",
                    \"sub_status\": \"\",
                    \"free_email\": false,
                    \"did_you_mean\": null,
                    \"account\": null,
                    \"domain\": null,
                    \"domain_age_days\": \"9692\",
                    \"smtp_provider\": \"example\",
                    \"mx_found\": \"true\",
                    \"mx_record\": \"mx.example.com\",
                    \"firstname\": \"zero\",
                    \"lastname\": \"bounce\",
                    \"gender\": \"male\",
                    \"country\": null,
                    \"region\": null,
                    \"city\": null,
                    \"zipcode\": null,
                    \"processed_at\": \"2023-05-16 16:33:51.059\"
                },
                {
                    \"address\": \"toxic@example.com\",
                    \"status\": \"do_not_mail\",
                    \"sub_status\": \"toxic\",
                    \"free_email\": false,
                    \"did_you_mean\": null,
                    \"account\": null,
                    \"domain\": null,
                    \"domain_age_days\": \"9692\",
                    \"smtp_provider\": \"example\",
                    \"mx_found\": \"true\",
                    \"mx_record\": \"mx.example.com\",
                    \"firstname\": \"zero\",
                    \"lastname\": \"bounce\",
                    \"gender\": \"male\",
                    \"country\": null,
                    \"region\": null,
                    \"city\": null,
                    \"zipcode\": null,
                    \"processed_at\": \"2023-05-16 16:33:51.059\"
                },
                {
                    \"address\": \"donotmail@example.com\",
                    \"status\": \"do_not_mail\",
                    \"sub_status\": \"role_based\",
                    \"free_email\": false,
                    \"did_you_mean\": null,
                    \"account\": null,
                    \"domain\": null,
                    \"domain_age_days\": \"9692\",
                    \"smtp_provider\": \"example\",
                    \"mx_found\": \"true\",
                    \"mx_record\": \"mx.example.com\",
                    \"firstname\": \"zero\",
                    \"lastname\": \"bounce\",
                    \"gender\": \"male\",
                    \"country\": null,
                    \"region\": null,
                    \"city\": null,
                    \"zipcode\": null,
                    \"processed_at\": \"2023-05-16 16:33:51.059\"
                },
                {
                    \"address\": \"spamtrap@example.com\",
                    \"status\": \"spamtrap\",
                    \"sub_status\": \"\",
                    \"free_email\": false,
                    \"did_you_mean\": null,
                    \"account\": null,
                    \"domain\": null,
                    \"domain_age_days\": \"9692\",
                    \"smtp_provider\": \"example\",
                    \"mx_found\": \"true\",
                    \"mx_record\": \"mx.example.com\",
                    \"firstname\": \"zero\",
                    \"lastname\": \"bounce\",
                    \"gender\": \"male\",
                    \"country\": null,
                    \"region\": null,
                    \"city\": null,
                    \"zipcode\": null,
                    \"processed_at\": \"2023-05-16 16:33:51.059\"
                }
            ],
            \"errors\": []
        }";

        $emails = [
            "disposable@example.com", 
            "invalid@example.com", 
            "valid@example.com", 
            "toxic@example.com", 
            "donotmail@example.com", 
            "spamtrap@example.com"
        ];

        $response = ZeroBounce::Instance()->validateBatch($emails);

        $this->assertEquals($response->emailBatch[0]->address, 
            'disposable@example.com');
        
        $this->assertEquals($response->emailBatch[1]->status,
            'invalid');
        $this->assertEquals($response->emailBatch[3]->sub_status,
            'toxic');
        $this->assertEquals($response->emailBatch[3]->smtp_provider,
            'example');


        $emailsAndIPs = [
            ["disposable@example.com", "168.10.11.1"],
            ["invalid@example.com", "168.10.11.2"],
            ["valid@example.com", "168.10.11.3"],
            ["toxic@example.com", "168.10.11.4"],
            ["donotmail@example.com", "168.10.11.5"],
            ["spamtrap@example.com", "168.10.11.6"]
        ];

        $response = ZeroBounce::Instance()->validateBatch($emailsAndIPs);

        $this->assertEquals($response->emailBatch[0]->address, 
            'disposable@example.com');
        
        $this->assertEquals($response->emailBatch[1]->status,
            'invalid');
        $this->assertEquals($response->emailBatch[3]->sub_status,
            'toxic');
        $this->assertEquals($response->emailBatch[3]->smtp_provider,
            'example');
    }

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

    public function testSendFile()
    {
        ZeroBounce::Instance()->responseText = "{
            \"success\": true,
            \"message\": \"File Accepted\",
            \"file_name\": \"email_list.txt\",
            \"file_id\": \"fae8b155-da88-45fb-8058-0ccfad168812\"
        }";
        $response = ZeroBounce::Instance()->sendFile(
            "./test/email_file.csv", 1, $hasHeaderRow = false);
        $this->assertEquals($response->success, true);
        $this->assertEquals($response->fileName, "email_list.txt");
    }

    public function testFileStatus()
    {
        ZeroBounce::Instance()->responseText = "{
            \"success\": true,
            \"file_id\": \"fae8b155-da88-45fb-8058-0ccfad168812\",
            \"file_name\": \"email_list.txt\",
            \"upload_date\": \"2023-03-24T14:18:31Z\",
            \"file_status\": \"Complete\",
            \"complete_percentage\": \"100% Complete.\",
            \"return_url\": \"returnUrl\"
        }";
        $response = ZeroBounce::Instance()->fileStatus("fae8b155-da88-45fb-8058-0ccfad168812");
        $this->assertEquals($response->success, true);
        $this->assertEquals($response->fileName, "email_list.txt");
    }

    public function testGetFile()
    {
        ZeroBounce::Instance()->responseText = 
            "\"Email Address\",\"ZB Status\",\"ZB Sub Status\",\"ZB Account\",\"ZB Domain\",\"ZB First Name\",\"ZB Last Name\",\"ZB Gender\",\"ZB Free Email\",\"ZB MX Found\",\"ZB MX Record\",\"ZB SMTP Provider\",\"ZB Did You Mean\"\n" .
            "\"disposable@example.com\",\"do_not_mail\",\"disposable\",\"\",\"\",\"zero\",\"bounce\",\"male\",\"False\",\"true\",\"mx.example.com\",\"example\",\"\"\n" .
            "\"invalid@example.com\",\"invalid\",\"mailbox_not_found\",\"\",\"\",\"zero\",\"bounce\",\"male\",\"False\",\"true\",\"mx.example.com\",\"example\",\"\"\n" .
            "\"valid@example.com\",\"valid\",\"\",\"\",\"\",\"zero\",\"bounce\",\"male\",\"False\",\"true\",\"mx.example.com\",\"example\",\"\"";
        $response = ZeroBounce::Instance()->getFile("fae8b155-da88-45fb-8058-0ccfad168812", "email_list.txt");
        $this->assertEquals($response->localFilePath, "email_list.txt");
        unlink("email_list.txt");
    }

    public function testDeleteFile()
    {
        ZeroBounce::Instance()->responseText = "{
            \"success\": true,
            \"message\": \"File Deleted\",
            \"file_name\": \"email_list.txt\",
            \"file_id\": \"fae8b155-da88-45fb-8058-0ccfad168812\"
        }";
        $response = ZeroBounce::Instance()->deleteFile("fae8b155-da88-45fb-8058-0ccfad168812");
        $this->assertEquals($response->success, true);
        $this->assertEquals($response->fileName, "email_list.txt");
    }

    public function testScoringSendFile()
    {
        ZeroBounce::Instance()->responseText = "{
            \"success\": true,
            \"message\": \"File Accepted\",
            \"file_name\": \"email_list.txt\",
            \"file_id\": \"fae8b155-da88-45fb-8058-0ccfad168812\"
        }";
        $response = ZeroBounce::Instance()->scoringSendFile(
            "./test/email_file.csv", 1, $hasHeaderRow = false);
        $this->assertEquals($response->success, true);
        $this->assertEquals($response->fileName, "email_list.txt");
    }

    public function testScoringFileStatus()
    {
        ZeroBounce::Instance()->responseText = "{
            \"success\": true,
            \"file_id\": \"fae8b155-da88-45fb-8058-0ccfad168812\",
            \"file_name\": \"email_list.txt\",
            \"upload_date\": \"2023-03-24T14:18:31Z\",
            \"file_status\": \"Complete\",
            \"complete_percentage\": \"100% Complete.\",
            \"return_url\": \"returnUrl\"
        }";
        $response = ZeroBounce::Instance()->scoringFileStatus("fae8b155-da88-45fb-8058-0ccfad168812");
        $this->assertEquals($response->success, true);
        $this->assertEquals($response->fileName, "email_list.txt");
    }
    
    public function testScoringGetFile()
    {
        ZeroBounce::Instance()->responseText = 
            "\"Email Address\",\"ZeroBounceQualityScore\"\n" .
            "\"disposable@example.com\",\"0\"\n" .
            "\"invalid@example.com\",\"10\"\n" .
            "\"valid@example.com\",\"10\"";
        $response = ZeroBounce::Instance()->scoringGetFile("fae8b155-da88-45fb-8058-0ccfad168812", "email_list.txt");
        $this->assertEquals($response->localFilePath, "email_list.txt");
        unlink("email_list.txt");
    }

    public function testScoringDeleteFile()
    {
        ZeroBounce::Instance()->responseText = "{
            \"success\": true,
            \"message\": \"File Deleted\",
            \"file_name\": \"email_list.txt\",
            \"file_id\": \"fae8b155-da88-45fb-8058-0ccfad168812\"
        }";
        $response = ZeroBounce::Instance()->scoringDeleteFile("fae8b155-da88-45fb-8058-0ccfad168812");
        $this->assertEquals($response->success, true);
        $this->assertEquals($response->fileName, "email_list.txt");
    }

    public function testGuessFormat()
    {
        ZeroBounce::Instance()->responseText = "{
            \"email\": \"\",
            \"domain\": \"zerobounce.net\",
            \"format\": \"first.last\",
            \"status\": \"valid\",
            \"sub_status\": \"\",
            \"confidence\": \"high\",
            \"did_you_mean\": \"\",
            \"failure_reason\": \"\",
            \"other_domain_formats\": [
                {
                    \"format\": \"first\",
                    \"confidence\": \"high\"
                },
                {
                    \"format\": \"last.first\",
                    \"confidence\": \"high\"
                },
                {
                    \"format\": \"lfirst\",
                    \"confidence\": \"high\"
                },
                {
                    \"format\": \"lastfirst\",
                    \"confidence\": \"high\"
                },
                {
                    \"format\": \"firstl\",
                    \"confidence\": \"high\"
                },
                {
                    \"format\": \"last\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"first.middle.last\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"first-last\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"l.first\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"f.last\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"f-last\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"first.l\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"first-l\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"firstlast\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"first_l\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"f_last\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"last.f\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"last-f\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"last-first\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"first_last\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"last_f\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"last_first\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"flast\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"lastf\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"l-first\",
                    \"confidence\": \"low\"
                },
                {
                    \"format\": \"l_first\",
                    \"confidence\": \"low\"
                }
            ]
        }";
        $domain = "zerobounce.net";
    	$response = ZeroBounce::Instance()->guessFormat(
                $domain, "firstname", "middlename", "lastname");
    	$this->assertEquals($response->domain, $domain);
        $this->assertEquals($response->format, "first.last");
        $this->assertEquals($response->confidence, "high");
        $this->assertEquals(count($response->otherDomainFormats), 26);
    }

    public function testFindEmail()
    {
        ZeroBounce::Instance()->responseText = "{
            \"email\": \"first.last@zerobounce.net\",
            \"domain\": \"zerobounce.net\",
            \"company_name\": \"Zero Bounce\",
            \"email_confidence\": \"high\",
            \"did_you_mean\": \"\",
            \"failure_reason\": \"\"
        }";
        $domain = "zerobounce.net";
        $response = ZeroBounce::Instance()->findEmail($domain, "First");
        $this->assertEquals($response->email, "first.last@zerobounce.net");
        $this->assertEquals($response->domain, $domain);
        $this->assertEquals($response->companyName, 'Zero Bounce');
        $this->assertEquals($response->emailConfidence, "high");
    }

    public function testFindEmailFormat()
    {
        ZeroBounce::Instance()->responseText = "{
            \"domain\": \"zerobounce.net\",
            \"company_name\": \"ZeroBounce\",
            \"format\": \"first.last\",
            \"confidence\": \"high\",
            \"did_you_mean\": \"\",
            \"failure_reason\": \"\",
            \"other_domain_formats\": [
                {
                    \"format\": \"first\",
                    \"confidence\": \"high\"
                },
                {
                    \"format\": \"last.first\",
                    \"confidence\": \"high\"
                },
                {
                    \"format\": \"lfirst\",
                    \"confidence\": \"high\"
                },
                {
                    \"format\": \"lastfirst\",
                    \"confidence\": \"high\"
                },
                {
                    \"format\": \"firstl\",
                    \"confidence\": \"high\"
                },
                {
                    \"format\": \"last\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"first.middle.last\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"first-last\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"l.first\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"f.last\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"f-last\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"first.l\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"first-l\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"firstlast\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"first_l\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"f_last\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"last.f\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"last-f\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"last-first\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"first_last\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"last_f\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"last_first\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"flast\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"lastf\",
                    \"confidence\": \"medium\"
                },
                {
                    \"format\": \"l-first\",
                    \"confidence\": \"low\"
                },
                {
                    \"format\": \"l_first\",
                    \"confidence\": \"low\"
                }
            ]
        }";
        $domain = "zerobounce.net";
        $response = ZeroBounce::Instance()->findEmailFormat($domain);
        $this->assertEquals($response->domain, $domain);
        $this->assertEquals($response->companyName, 'ZeroBounce');
        $this->assertEquals($response->format, "first.last");
        $this->assertEquals($response->confidence, "high");
        $this->assertEquals(count($response->otherDomainFormats), 26);
    }
}

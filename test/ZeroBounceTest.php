<?php


use ZeroBounce\SDK\ZBApiException;
use ZeroBounce\SDK\ZBException;
use ZeroBounce\SDK\ZBMissingApiKeyException;
use ZeroBounce\SDK\ZBMissingParameterException;
use ZeroBounce\SDK\ZeroBounce;

require_once __DIR__ . '/../vendor/autoload.php';


ZeroBounce::Instance()->initialize("<YOUR_API_KEY>");

function validate() {
    try {
        $response = ZeroBounce::Instance()->validate("<EMAIL_TO_TEST>");
        echo "response: ".$response;
    } catch (ZBMissingApiKeyException $e) {
        echo $e->getMessage();
    } catch (ZBMissingParameterException $e) {
        echo $e->getMessage();
    }
}

function sendFile() {
    try {
        $response = ZeroBounce::Instance()->sendFile(
            "./test/email_file.csv", 1, null, 2,
            3, null, null, true);
        echo "response: ".$response;
    } catch (ZBMissingApiKeyException $e) {
        echo $e->getMessage();
    } catch (ZBException $e) {
        echo $e->getMessage();
    }
}

function getFile() {
    try {
        $response = ZeroBounce::Instance()->getFile("<YOUR_FILE_ID>", "./test/downloads/file.csv");
        echo "response: ".$response;
    } catch (ZBMissingApiKeyException $e) {
        echo $e->getMessage();
    } catch (ZBException $e) {
        echo $e->getMessage();
    }
}

function getCredits() {
    try {
        $response = ZeroBounce::Instance()->getCredits();
        echo "response: ".$response;
    } catch (ZBException $e) {
        echo $e->getMessage();
    }
}

function getApiUsage() {
    $startDate = new DateTime();
    $startDate->modify('-5 day');
    $endDate = new DateTime();
    try {
        $response = ZeroBounce::Instance()->getApiUsage($startDate, $endDate);
        echo "response: ".$response;
    } catch (ZBException $e) {
        echo $e->getMessage();
    }
}

function fileStatus() {
    try {
        $response = ZeroBounce::Instance()->fileStatus("<YOUR_FILE_ID>");
        echo "response: ".$response;
    } catch (ZBException $e) {
        echo $e->getMessage();
    }
}

function deleteFile() {
    try {
        $response = ZeroBounce::Instance()->deleteFile("<YOUR_FILE_ID>");
        echo "response: ".$response;
    } catch (ZBException $e) {
        echo $e->getMessage();
    }
}

//validate();

//sendFile();

//getFile();

//getCredits();

//getApiUsage();

//fileStatus();

deleteFile();






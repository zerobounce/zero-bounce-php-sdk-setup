<?php

namespace ZeroBounce\Tests;

use ZeroBounce\SDK\ZeroBounce;
use ZeroBounce\SDK\ZBException;

/**
 */
class MockZeroBounce extends ZeroBounce
{
    /** Class used for mocking the requests within the ZeroBounce class, such that tests don't call the API */

    /**
     * Mock response text. Set this to whatever you want to simulate receiving from the ZeroBounce API.
     * @var string
     */
    public $responseText;

    /**
     * Last multipart form fields passed to curl() (sendfile).
     * @var array<string, mixed>|null
     */
    public $lastMultipartFields;

    /**
     * Last getfile URL passed to getBulkFileHttp().
     * @var string|null
     */
    public $lastGetFileUrl;

    /**
     * Call this method to get singleton
     *
     * @return MockZeroBounce
     */
    public static function Instance()
    {
        static $inst = null;
        if ($inst === null) {
            $inst = new MockZeroBounce();
        }
        return $inst;
    }

    /**
     * Overwrites the HTTP JSON POST request made within the ZeroBounce class
     * @param string $url
     * @param array $data
     * @return ZBResponse
     */
    protected function json($url, $data, $response)
    {
        try {
            $code = 200;
            $response->Deserialize($this->responseText);
            return $code;
        } catch (Exception $e) {
            throw new ZBException($e->getMessage());
        }
    }

    /**
     * Overwrites the HTTP request made within the ZeroBounce class
     * @param string $url
     * @param ZBResponse $response
     * @return int http statusCode
     * @throws ZBException
     */
    protected function request($url, $response)
    {
        try {
            $code = 200;
            $response->Deserialize($this->responseText);
            return $code;
        } catch (Exception $e) {
            throw new ZBException($e->getMessage());
        }
    }

    /**
     * Overwrites the curl request made within the ZeroBounce class
     * @param string $url
     * @param array $fields
     * @param array $files
     * @return string
     */
    protected function curl($url, $fields, $files)
    {
        $this->lastMultipartFields = $fields;
        return $this->responseText;
    }

    /**
     * @param string $url
     * @return array{body: string, headers: string[]}
     */
    protected function getBulkFileHttp($url)
    {
        $this->lastGetFileUrl = $url;
        $t = ltrim($this->responseText);
        $ct = ($t !== '' && $t[0] === '{') ? 'application/json' : 'text/csv';
        return array(
            'body' => $this->responseText,
            'headers' => array('HTTP/1.1 200 OK', 'Content-Type: ' . $ct),
        );
    }
}

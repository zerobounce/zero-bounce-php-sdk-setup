<?php

namespace ZeroBounce\Tests;

use Exception;
use ZeroBounce\SDK\ZeroBounce;
use ZeroBounce\SDK\ZBException;

require_once __DIR__ . '/Seams.php';

/**
 * Mocks the requests of a real ZeroBounce client through the test hooks, so tests
 * don't call the API. Holds the client (it is final) and records what it was asked.
 */
class MockZeroBounce extends ClientDouble
{
    /**
     * Mock response text. Set this to whatever you want to simulate receiving from the ZeroBounce API.
     * @var string
     */
    public $responseText;

    /** @var array<string, mixed>|null Last multipart form fields passed to curl() (sendfile). */
    public $lastMultipartFields;

    /** @var string|null Last getfile URL. */
    public $lastGetFileUrl;

    /** @var string|null Last JSON POST URL. */
    public $lastJsonUrl;

    /** @var string|null Last form POST URL. */
    public $lastFormPostUrl;

    /** @var string|null Last GET URL. */
    public $lastRequestUrl;

    /** @var string|null Last multipart POST URL. */
    public $lastCurlUrl;

    public function __construct()
    {
        $this->client = ZeroBounce::create();
        $self = $this;
        $this->hook(array(
            'json' => function ($url, $data, $response) use ($self) {
                $self->lastJsonUrl = $url;
                return $self->deserialize($response);
            },
            'request' => function ($url, $response) use ($self) {
                $self->lastRequestUrl = $url;
                return $self->deserialize($response);
            },
            'formPost' => function ($url, $data, $response) use ($self) {
                $self->lastFormPostUrl = $url;
                return $self->deserialize($response);
            },
            'curl' => function ($url, $fields, $files) use ($self) {
                $self->lastCurlUrl = $url;
                $self->lastMultipartFields = $fields;
                return $self->responseText;
            },
            'getBulkFileHttp' => function ($url) use ($self) {
                $self->lastGetFileUrl = $url;
                $t = ltrim($self->responseText);
                $ct = ($t !== '' && $t[0] === '{') ? 'application/json' : 'text/csv';
                return array(
                    'body' => $self->responseText,
                    'headers' => array('HTTP/1.1 200 OK', 'Content-Type: ' . $ct),
                );
            },
        ));
    }

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
     * @param \ZeroBounce\SDK\ZBResponse $response
     * @return int http statusCode
     * @throws ZBException
     */
    public function deserialize($response)
    {
        try {
            $response->Deserialize($this->responseText);
            return 200;
        } catch (Exception $e) {
            throw new ZBException($e->getMessage());
        }
    }
}

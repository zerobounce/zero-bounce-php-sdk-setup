<?php

namespace ZeroBounce\Tests;

use ZeroBounce\SDK\ZeroBounce;
use ZeroBounce\SDK\ZBException;

/**
 */
class MockZeroBounce extends ZeroBounce
{
    /** \PHPUnit\Framework\TestCase */

    /**
     * Mock response text
     * @var string
     */
    public $responseText;

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
     * @param string $url
     * @param array $fields
     * @param array $files
     * @return string
     */
    protected function curl($url, $fields, $files)
    {
        return $this->responseText;
    }
}

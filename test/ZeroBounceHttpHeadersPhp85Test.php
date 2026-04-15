<?php

namespace ZeroBounce\Tests;

require_once __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use ZeroBounce\SDK\ZeroBounce;

/**
 * Guards PHP 8.5 deprecation fix for $http_response_header (see PR #30, RFC deprecations).
 */
final class ZeroBounceHttpHeadersPhp85Test extends TestCase
{
    private static function zeroBounceSourcePath(): string
    {
        return dirname(__DIR__) . '/src/ZeroBounce.php';
    }

    private static function extractMethodSource(string $src, string $signatureNeedle, string $endBeforeNeedle): string
    {
        $start = strpos($src, $signatureNeedle);
        if ($start === false) {
            throw new \RuntimeException('Method signature not found: ' . $signatureNeedle);
        }
        $end = strpos($src, $endBeforeNeedle, $start + strlen($signatureNeedle));
        if ($end === false) {
            throw new \RuntimeException('Method end anchor not found: ' . $endBeforeNeedle);
        }
        return substr($src, $start, $end - $start);
    }

    public function testGetBulkFileHttpPrefersHttpGetLastResponseHeadersBeforeMagicVariable(): void
    {
        $src = file_get_contents(self::zeroBounceSourcePath());
        $method = self::extractMethodSource(
            $src,
            'protected function getBulkFileHttp($url)',
            'protected static function parseHttpStatusFromHeaders'
        );

        $this->assertStringContainsString("function_exists('http_get_last_response_headers')", $method);
        $this->assertStringContainsString('http_get_last_response_headers()', $method);
        $this->assertStringContainsString('isset($http_response_header)', $method);

        $posFn = strpos($method, "function_exists('http_get_last_response_headers')");
        $posIsset = strpos($method, 'isset($http_response_header)');
        $this->assertNotFalse($posFn);
        $this->assertNotFalse($posIsset);
        $this->assertLessThan(
            $posIsset,
            $posFn,
            'getBulkFileHttp must call http_get_last_response_headers() before any isset($http_response_header) fallback (PHP 8.5).'
        );
    }

    public function testRequestUsesHttpGetLastResponseHeadersBeforeReadingStatus(): void
    {
        $src = file_get_contents(self::zeroBounceSourcePath());
        $method = self::extractMethodSource(
            $src,
            'protected function request($url, $response)',
            'private function checkValidApiKey'
        );

        $this->assertStringContainsString("function_exists('http_get_last_response_headers')", $method);
        $this->assertStringContainsString('http_get_last_response_headers()', $method);
        $this->assertStringContainsString('$http_response_header = http_get_last_response_headers()', $method);

        $posFn = strpos($method, "function_exists('http_get_last_response_headers')");
        $posGetHttp = strpos($method, 'getHttpCode($http_response_header)');
        $this->assertNotFalse($posFn);
        $this->assertNotFalse($posGetHttp);
        $this->assertLessThan(
            $posGetHttp,
            $posFn,
            'request() must resolve headers via http_get_last_response_headers() before getHttpCode() (PHP 8.5).'
        );
    }

    public function testGetBulkFileHttpViaFileUrlDoesNotEmitHttpResponseHeaderDeprecation(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zb_');
        if ($tmp === false) {
            $this->markTestSkipped('Could not create temp file');
        }
        $this->assertNotFalse(file_put_contents($tmp, "col1,col2\na@b.com,1\n"));

        $hadDeprecation = false;
        $deprecationMessage = '';
        set_error_handler(
            function (int $errno, string $errstr) use (&$hadDeprecation, &$deprecationMessage): bool {
                if ($errno === E_DEPRECATED && strpos($errstr, 'http_response_header') !== false) {
                    $hadDeprecation = true;
                    $deprecationMessage = $errstr;
                }
                return false;
            },
            E_DEPRECATED
        );

        try {
            $zb = new GetBulkFileHttpProbe();
            $url = 'file://' . str_replace('\\', '/', $tmp);
            $out = $zb->callGetBulkFileHttp($url);
        } finally {
            restore_error_handler();
            @unlink($tmp);
        }

        $this->assertFalse(
            $hadDeprecation,
            $hadDeprecation ? ('Unexpected deprecation: ' . $deprecationMessage) : ''
        );
        $this->assertSame("col1,col2\na@b.com,1\n", $out['body']);
        $this->assertIsArray($out['headers']);
    }
}

/**
 * @internal
 */
final class GetBulkFileHttpProbe extends ZeroBounce
{
    public function __construct()
    {
        parent::__construct();
    }

    /** @return array{body: string, headers: array} */
    public function callGetBulkFileHttp(string $url): array
    {
        return $this->getBulkFileHttp($url);
    }
}

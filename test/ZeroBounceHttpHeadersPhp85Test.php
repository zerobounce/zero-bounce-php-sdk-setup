<?php

namespace ZeroBounce\Tests;

require_once __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use ZeroBounce\SDK\ZeroBounce;

/**
 * Guards the PHP 8.5 $http_response_header deprecation (see PR #30, RFC
 * deprecations).
 *
 * The SDK no longer uses the http:// stream wrapper at all, so the predefined
 * variable is never read. That also fixed a bug the earlier shape hid: the
 * variable is visible only to the function that called file_get_contents, so
 * reading it anywhere else always came back empty and every GET was recorded
 * as HTTP 0 — a 404 from the list evaluator looked like a successful response.
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

    public function testNoSourceFileMentionsTheDeprecatedPredefinedVariable(): void
    {
        foreach (glob(dirname(__DIR__) . '/src/*.php') as $file) {
            $this->assertFalse(
                strpos((string)file_get_contents($file), '$http_response_header') !== false,
                basename($file) . ' must not read the deprecated predefined variable (PHP 8.5).'
            );
        }
    }

    public function testExecuteHttpCollectsHeadersAndStatusFromCurl(): void
    {
        $src = (string)file_get_contents(self::zeroBounceSourcePath());
        $execute = self::extractMethodSource(
            $src,
            'protected function executeHttp($method, $url, array $headers = array(), $body = null, $timeout = 120)',
            'protected function request($url, $response)'
        );

        // The stream wrapper is what made the status unreadable; curl reports it.
        $this->assertStringNotContainsString('file_get_contents', $execute);
        $this->assertStringNotContainsString('stream_context_create', $execute);
        $this->assertStringContainsString('CURLOPT_HEADERFUNCTION', $execute);
        $this->assertStringContainsString('CURLINFO_HTTP_CODE', $execute);
        $this->assertStringContainsString('CURLOPT_FOLLOWLOCATION => false', $execute);
    }

    public function testTheSdkRefusesToSendTheKeyOverPlainHttp(): void
    {
        $probe = new class extends ZeroBounce {
            public function __construct()
            {
                parent::__construct();
            }

            public function call(string $url): string
            {
                return $this->checkedUrl($url);
            }
        };

        $this->expectException(\ZeroBounce\SDK\ZBMissingParameterException::class);
        $probe->call('http://api.zerobounce.net/v2/getcredits');
    }
}

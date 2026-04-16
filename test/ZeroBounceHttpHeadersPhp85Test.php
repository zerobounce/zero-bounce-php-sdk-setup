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

    /**
     * On PHP 8.5+, referencing $http_response_header (including isset) in getBulkFileHttp() is deprecated
     * and may emit at class load; the method must not contain a legacy elseif on that variable.
     */
    public function testGetBulkFileHttpSourceHasNoIssetOnHttpResponseHeaderOnPhp85(): void
    {
        if (\PHP_VERSION_ID < 80500) {
            $this->markTestSkipped('PHP 8.5 $http_response_header deprecation');
        }
        $src = file_get_contents(self::zeroBounceSourcePath());
        $method = self::extractMethodSource(
            $src,
            'protected function getBulkFileHttp($url)',
            'protected static function parseHttpStatusFromHeaders'
        );

        $this->assertStringContainsString("function_exists('http_get_last_response_headers')", $method);
        $this->assertStringContainsString('http_get_last_response_headers()', $method);
        $this->assertStringNotContainsString(
            'isset($http_response_header)',
            $method,
            'Remove isset($http_response_header) / elseif legacy branch; it is deprecated on PHP 8.5 (see RFC deprecations).'
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
        $this->assertStringContainsString('$responseHeaders = http_get_last_response_headers()', $method);
        $this->assertStringNotContainsString('getHttpCode($http_response_header)', $method);

        $posFn = strpos($method, "function_exists('http_get_last_response_headers')");
        $posGetHttp = strpos($method, 'getHttpCode($responseHeaders)');
        $this->assertNotFalse($posFn);
        $this->assertNotFalse($posGetHttp);
        $this->assertLessThan(
            $posGetHttp,
            $posFn,
            'request() must resolve headers via http_get_last_response_headers() before getHttpCode() (PHP 8.5).'
        );
    }

    /**
     * PHP 8.5 emits $http_response_header deprecations without invoking set_error_handler (Zend logs them).
     * A subprocess with stderr capture is required to fail the suite when they occur.
     */
    public function testGetBulkFileHttpViaFileUrlDoesNotEmitHttpResponseHeaderDeprecation(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zb_');
        if ($tmp === false) {
            $this->markTestSkipped('Could not create temp file');
        }
        $this->assertNotFalse(file_put_contents($tmp, "col1,col2\na@b.com,1\n"));
        $url = 'file://' . str_replace('\\', '/', $tmp);

        try {
            if (\PHP_VERSION_ID >= 80500) {
                $bootstrap = realpath(dirname(__DIR__) . '/vendor/autoload.php');
                $this->assertNotFalse($bootstrap);
                $probePath = sys_get_temp_dir() . '/zb_http_deprecation_probe_' . uniqid('', true) . '.php';
                $probeBody = self::getBulkFileHttpProbeScript($bootstrap, $tmp);
                $this->assertNotFalse(file_put_contents($probePath, $probeBody));

                $cmd = [\PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=' . (string) \E_ALL, $probePath];
                $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
                $this->assertNotFalse($proc);
                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($proc);
                @unlink($probePath);

                $this->assertSame(0, $code, 'Probe script exit code');
                $combined = $stdout . $stderr;
                $this->assertStringNotContainsStringIgnoringCase(
                    'deprecated',
                    $combined,
                    'Expected no $http_response_header deprecation on stderr/stdout (PHP 8.5). Output: ' . $combined
                );
                $this->assertStringNotContainsString(
                    'http_response_header',
                    $combined,
                    'Expected no http_response_header deprecation text. Output: ' . $combined
                );

                return;
            }

            $zb = new GetBulkFileHttpProbe();
            $out = $zb->callGetBulkFileHttp($url);
            $this->assertSame("col1,col2\na@b.com,1\n", $out['body']);
            $this->assertIsArray($out['headers']);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Standalone script so PHP 8.5 engine deprecation output is visible to the parent process on stderr.
     *
     * @param non-empty-string $bootstrap
     */
    private static function getBulkFileHttpProbeScript(string $bootstrap, string $dataFilePath): string
    {
        $bootstrapExport = var_export($bootstrap, true);
        $fileExport = var_export($dataFilePath, true);

        return <<<PHP
<?php
require {$bootstrapExport};

final class ZbGetBulkProbe extends \ZeroBounce\SDK\ZeroBounce
{
    public function __construct()
    {
        parent::__construct();
    }

    public function run(string \$url): array
    {
        return \$this->getBulkFileHttp(\$url);
    }
}

\$url = 'file://' . str_replace('\\\\', '/', {$fileExport});
\$out = (new ZbGetBulkProbe())->run(\$url);
if (\$out['body'] !== "col1,col2\\na@b.com,1\\n") {
    fwrite(STDERR, "unexpected body\\n");
    exit(1);
}
exit(0);

PHP;
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

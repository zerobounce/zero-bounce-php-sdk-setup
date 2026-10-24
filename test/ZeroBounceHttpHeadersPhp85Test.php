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

    public function testGetBulkFileHttpReadsAFileUrlWithoutEmittingADeprecation(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zb_');
        if ($tmp === false) {
            $this->markTestSkipped('Could not create temp file');
        }
        $this->assertNotFalse(file_put_contents($tmp, "col1,col2\na@b.com,1\n"));

        try {
            $bootstrap = realpath(dirname(__DIR__) . '/vendor/autoload.php');
            $this->assertNotFalse($bootstrap);
            $probePath = sys_get_temp_dir() . '/zb_http_deprecation_probe_' . uniqid('', true) . '.php';
            $this->assertNotFalse(
                file_put_contents($probePath, self::getBulkFileHttpProbeScript($bootstrap, $tmp))
            );

            $cmd = [\PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=' . (string) \E_ALL, $probePath];
            $proc = proc_open(
                $cmd,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                null,
                ['bypass_shell' => true]
            );
            $this->assertNotFalse($proc);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            @unlink($probePath);

            $combined = $stdout . $stderr;
            $this->assertSame(0, $code, 'Probe script exit code. Output: ' . $combined);
            $this->assertStringNotContainsStringIgnoringCase(
                'deprecated',
                $combined,
                'Expected no deprecation. Output: ' . $combined
            );
            $this->assertStringNotContainsString('http_response_header', $combined);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Standalone script so engine deprecation output is visible to the parent
     * process on stderr.
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
    fwrite(STDERR, "unexpected body: " . var_export(\$out['body'], true) . "\\n");
    exit(1);
}
if (!is_array(\$out['headers'])) {
    fwrite(STDERR, "headers were not an array\\n");
    exit(1);
}
exit(0);

PHP;
    }
}

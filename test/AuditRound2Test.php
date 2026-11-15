<?php

namespace ZeroBounce\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBounce\SDK\ZBException;
use ZeroBounce\SDK\ZBMissingParameterException;
use ZeroBounce\SDK\ZBSecret;
use ZeroBounce\SDK\ZBUrl;
use ZeroBounce\SDK\ZeroBounce;
use ZeroBounce\SDK\ZeroBounceWarmup;

require_once __DIR__ . '/Seams.php';

/** A callable a crafted unserialize() payload names; it records that it ran. */
function audit2_seam_probe()
{
    AuditRound2Test::$probeCalls++;
    return null;
}

/** Audit 2, final round: code findings (unserialize hooks, exception classes, download, types, test gaps). */
class AuditRound2Test extends TestCase
{
    /** @var int */
    public static $probeCalls = 0;

    private function csv()
    {
        return __DIR__ . '/email_file.csv';
    }

    private function thrown(callable $call)
    {
        try {
            $call();
        } catch (\Exception $e) {
            return $e;
        }
        $this->fail('nothing thrown');
    }

    private function payload($class, array $seam)
    {
        $prop = "\0" . $class . "\0seam";
        $items = '';
        foreach ($seam as $name => $callable) {
            $items .= serialize($name) . serialize($callable);
        }
        return 'O:' . strlen($class) . ':"' . $class . '":1:{s:' . strlen($prop) . ':"' . $prop . '";a:' . count($seam) . ':{' . $items . '}}';
    }

    private function seamOf($client)
    {
        $bound = \Closure::bind(function () {
            return $this->seam;
        }, $client, get_class($client));
        return $bound();
    }

    // -- M: unserialize() cannot plant hooks ---------------------------------

    public function testUnserializeClearsTheHooksOfTheClient()
    {
        self::$probeCalls = 0;
        $name = __NAMESPACE__ . '\\audit2_seam_probe';
        $hooks = array('request' => $name, 'applyCurlOptions' => $name, 'checkedUrl' => $name, 'executeHttp' => $name);
        $copy = unserialize($this->payload('ZeroBounce\\SDK\\ZeroBounce', $hooks));
        $this->assertInstanceOf(ZeroBounce::class, $copy);
        $this->assertSame(array(), $this->seamOf($copy));
        $copy->initialize('unser-key-1234');
        set_private($copy, 'apiBaseUrl', 'http://127.0.0.1:1/v2');
        $error = $this->thrown(function () use ($copy) {
            $copy->getCredits();
        });
        $this->assertInstanceOf(ZBException::class, $error);
        $this->assertSame(0, self::$probeCalls, 'a planted hook ran');
        // The ordinary round trip still works.
        $plain = ZeroBounce::create();
        $plain->initialize('unser-key-1234');
        $this->assertSame(array(), $this->seamOf(unserialize(serialize($plain))));
    }

    public function testUnserializeClearsTheHooksOfTheWarmupClient()
    {
        self::$probeCalls = 0;
        $name = __NAMESPACE__ . '\\audit2_seam_probe';
        $hooks = array('rewriteUrl' => $name, 'executeHttp' => $name, 'applyCurlOptions' => $name);
        $copy = unserialize($this->payload('ZeroBounce\\SDK\\ZeroBounceWarmup', $hooks));
        $this->assertInstanceOf(ZeroBounceWarmup::class, $copy);
        $this->assertSame(array(), $this->seamOf($copy));
        $this->assertSame(0, self::$probeCalls);
    }

    // -- M: redaction keeps the exception class --------------------------------

    public function testArgumentErrorsOfTheUploadFamilyKeepTheirClass()
    {
        $client = ZeroBounce::create();
        $client->initialize('class-key-1234');
        install_seams($client, array(
            'executeHttp' => function () {
                throw new \RuntimeException('sent');
            },
            'streamHttpToHandle' => function () {
                throw new \RuntimeException('sent');
            },
        ));
        $csv = $this->csv();
        $calls = array(
            'sendFile url' => function () use ($client, $csv) { $client->sendFile($csv, 1, 'ftp://x'); },
            'sendFile ip' => function () use ($client, $csv) { $client->sendFile($csv, 1, null, null, null, null, '1abc'); },
            'scoringSendFile url' => function () use ($client, $csv) { $client->scoringSendFile($csv, 1, 'ftp://x'); },
            'sendFileStream url' => function () use ($client) { $client->sendFileStream("a@b.com\n", 'f.csv', 1, 'ftp://x'); },
            'sendFileStream ip' => function () use ($client) { $client->sendFileStream("a@b.com\n", 'f.csv', 1, null, null, null, null, '1abc'); },
            'scoringSendFileStream url' => function () use ($client) { $client->scoringSendFileStream("a@b.com\n", 'f.csv', 1, 'ftp://x'); },
        );
        foreach ($calls as $label => $call) {
            $e = $this->thrown($call);
            $this->assertInstanceOf(ZBMissingParameterException::class, $e, $label . ': ' . get_class($e));
            $this->assertStringNotContainsString('class-key-1234', $e->getMessage());
        }
    }

    // -- list evaluator result: a message-only body is data --------------------

    public function testListEvaluatorResultReturnsAMessageOnlyBody()
    {
        $client = ZeroBounce::create();
        $client->initialize('eval-key-1234');
        install_seams($client, array(
            'executeHttp' => function () {
                return array('status' => 200, 'headers' => array('HTTP/1.1 200 OK', 'Content-Type: application/json'),
                    'body' => '{"message":"Your file is still being evaluated"}');
            },
        ));
        $result = $client->getListEvaluatorResult('file-1');
        $this->assertInstanceOf('ZeroBounce\\SDK\\ZBListEvaluatorResponse', $result);
    }

    // -- return_url of the S3/SFTP jobs ------------------------------------------

    public function testS3AndSftpJobsRefuseABadReturnUrl()
    {
        $client = ZeroBounce::create();
        $client->initialize('s3-key-1234');
        install_seams($client, array(
            'executeHttp' => function () {
                throw new \RuntimeException('sent');
            },
        ));
        foreach (array('ftp://x', 'not a url', 'http://user:pw@example.com/') as $bad) {
            foreach (array('validateS3File', 'validateSFTPFile') as $method) {
                $e = $this->thrown(function () use ($client, $method, $bad) {
                    $client->$method(array('file_name' => 'a.csv', 'return_url' => $bad, 'email_address_column' => 1));
                });
                $this->assertInstanceOf(ZBMissingParameterException::class, $e, $method . ' ' . $bad);
                $this->assertStringContainsString('return_url', $e->getMessage());
            }
        }
    }

    // -- ZBSecret: encoded echoes in another case --------------------------------

    public function testRedactionOfAnEncodedEchoIgnoresCase()
    {
        $key = 'dummykey1234';
        // %44 is "D": only the decoded view holds the key, in upper case.
        $this->assertSame(ZBSecret::CREDENTIAL_OMITTED_MESSAGE, ZBSecret::redact('%44UMMYKEY1234', array($key)));
    }

    public function testRedactionOfAnEncodedEchoFoldsUnicodeCase()
    {
        // The sharp s folds to "ss", which a case-insensitive pattern does not do.
        $key = "gro\xC3\x9Fe-dummy-key";
        $this->assertSame(ZBSecret::CREDENTIAL_OMITTED_MESSAGE, ZBSecret::redact('%47ROSSE-DUMMY-KEY', array($key)));
    }

    public function testRedactionDecodesNamedEntities()
    {
        $key = "caf\xC3\xA9-dummy-key";
        $this->assertSame(ZBSecret::CREDENTIAL_OMITTED_MESSAGE, ZBSecret::redact('caf&eacute;-dummy-key', array($key)));
    }

    // -- ZBUrl: bracketed IPv6 -----------------------------------------------------

    public function testBracketedIpv6IsValidated()
    {
        $this->assertFalse(ZBUrl::isSafeHttps('https://[::zz]/'));
        $this->assertFalse(ZBUrl::isSafeHttps('https://[1:2:3:4:5:6:7:8:9]/'));
        $this->assertTrue(ZBUrl::isSafeHttps('https://[2001:db8::1]/'));
    }

    // -- downloads --------------------------------------------------------------

    public function testANewDownloadHasTheUsualFileMode()
    {
        $dir = sys_get_temp_dir() . '/zb-audit2-' . getmypid() . '-' . mt_rand();
        mkdir($dir);
        $client = ZeroBounce::create();
        $client->initialize('mode-key-1234');
        install_seams($client, array(
            'getBulkFileHttp' => function () {
                return array('body' => "email\na@b.com\n", 'headers' => array('HTTP/1.1 200 OK', 'Content-Type: text/csv'));
            },
        ));
        $path = $dir . '/out.csv';
        $client->getFile('file-1', $path);
        $this->assertFileExists($path);
        $this->assertSame(0666 & ~umask(), fileperms($path) & 0777);
        unlink($path);
        rmdir($dir);
    }

    public function testAStreamedDownloadCreatesNothingBeforeTheResponseIsAFile()
    {
        $base = sys_get_temp_dir() . '/zb-audit2-' . getmypid() . '-' . mt_rand();
        mkdir($base);
        $answer = array('status' => 200, 'body' => "email\na@b.com\n");
        $client = ZeroBounce::create();
        $client->initialize('stream-key-1234');
        $seen = array();
        install_seams($client, array(
            'streamHttpToHandle' => function ($url, $handle) use (&$answer, &$seen, $base) {
                $seen[] = glob($base . '/*');
                fwrite($handle, $answer['body']);
                return array('status' => $answer['status'], 'headers' => array('Content-Type: ' . ($answer['status'] === 200 ? 'text/csv' : 'application/json')),
                    'head' => $answer['body'], 'error' => '');
            },
        ));
        $target = $base . '/new/deeper/out.csv';
        $answer = array('status' => 404, 'body' => '{"error":"file not found"}');
        $stream = new \ZeroBounce\SDK\ZBGetFileOptions();
        $stream->stream = true;
        $this->thrown(function () use ($client, $target, $stream) { $client->getFile('f1', $target, $stream); });
        $this->assertSame(array(), glob($base . '/*'), 'a failed download left a folder behind');
        $this->assertSame(array(array()), $seen, 'something existed before the response');

        $answer = array('status' => 200, 'body' => "email\na@b.com\n");
        $client->getFile('f1', $target, $stream);
        $this->assertSame("email\na@b.com\n", file_get_contents($target));
        $this->assertSame(array(array(), array()), $seen, 'folders were made before the response');
        $this->assertSame(array('out.csv'), array_values(array_diff(scandir($base . '/new/deeper'), array('.', '..'))));
        unlink($target);
        rmdir($base . '/new/deeper');
        rmdir($base . '/new');
        rmdir($base);
    }

    // -- upload of a folder ----------------------------------------------------------

    public function testUploadingAFolderIsRefusedAsAFolder()
    {
        $client = ZeroBounce::create();
        $client->initialize('folder-key-1234');
        install_seams($client, array(
            'executeHttp' => function () {
                throw new \RuntimeException('sent');
            },
        ));
        $e = $this->thrown(function () use ($client) { $client->sendFile(__DIR__, 1); });
        $this->assertSame('filePath is a folder, not a file', $e->getMessage());
    }

}

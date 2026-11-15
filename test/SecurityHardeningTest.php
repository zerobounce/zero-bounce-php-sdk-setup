<?php

namespace ZeroBounce\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBounce\SDK\ZBException;
use ZeroBounce\SDK\ZBMissingParameterException;
use ZeroBounce\SDK\ZBSecret;
use ZeroBounce\SDK\ZBUrl;
use ZeroBounce\SDK\ZeroBounce;
use ZeroBounce\SDK\ZeroBounceWarmup;

class SecurityHardeningTest extends TestCase
{
    public static function badUrls()
    {
        return array(
            'userinfo' => array('https://api.zerobounce.net@evil.example/v2'),
            'userinfo with password' => array('https://user:pw@evil.example/v2'),
            'space in host' => array('https://exa mple.com'),
            'bare scheme' => array('https://'),
            'no host, path only' => array('https:///v2'),
            'backslash' => array("https://evil.example\\@api.zerobounce.net/v2"),
            'bad port' => array('https://example.com:99999/v2'),
            'zero port' => array('https://example.com:0/v2'),
            'empty port' => array('https://example.com:/v2'),
            'letters in port' => array('https://example.com:80a/v2'),
            'newline' => array("https://example.com/v2\n"),
            'nbsp' => array("https://example.com/\xC2\xA0v2"),
            'zero width' => array("https://exam\xE2\x80\x8Bple.com"),
            'underscore host' => array('https://exa_mple.com'),
            'bad ipv6' => array('https://[zz::1]/v2'),
            'invalid utf-8' => array("https://example.com/\xff"),
            'http' => array('http://example.com'),
        );
    }

    /** @dataProvider badUrls */
    public function testUnsafeBaseUrlsAreRefusedEverywhere($url)
    {
        $this->assertFalse(ZBUrl::isSafeHttps($url));
        foreach (array(
            function () use ($url) { ZeroBounce::create()->initialize('dummykey', $url); },
            function () use ($url) { ZeroBounce::create()->initialize('dummykey', null, null, null, $url); },
            function () use ($url) { ZeroBounce::create()->initialize('dummykey', null, $url); },
            function () use ($url) { ZeroBounce::create()->initialize('dummykey', null, null, $url); },
            function () use ($url) { new ZeroBounceWarmup('dummykey', $url); },
        ) as $i => $call) {
            try {
                $call();
                $this->fail('accepted ' . $url . ' in variant ' . $i);
            } catch (ZBMissingParameterException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testValidUrlsPass()
    {
        foreach (array('https://api.zerobounce.net/v2', 'https://example.com:8443/v2', 'https://[::1]:443/v2', 'https://a-b.example.co/x?y=1#z', 'HTTPS://Example.com') as $url) {
            $this->assertTrue(ZBUrl::isSafeHttps($url), $url);
        }
    }

    public function testASubclassSeamCannotSendToUserinfoUrl()
    {
        $client = new UrlProbeClient();
        $this->setExpectedException(ZBMissingParameterException::class);
        $client->probe('https://api.zerobounce.net@evil.example/v2');
    }

    public static function badKeys()
    {
        return array(
            'nbsp' => array("dummy\xC2\xA0key"), 'em space pad' => array("\xE2\x80\x83dummykey"),
            'line sep' => array("dummykey\xE2\x80\xA8"), 'ideographic' => array("\xE3\x80\x80dummykey"),
            'zwsp' => array("dummy\xE2\x80\x8Bkey"), 'bom' => array("\xEF\xBB\xBFdummykey"),
            'mongolian' => array("dummykey\xE1\xA0\x8E"), 'e acute' => array("dummyk\xC3\xA9y"),
            'inner space' => array('dummy key'), 'tab' => array("dummy\tkey"),
        );
    }

    /** @dataProvider badKeys */
    public function testInvisibleAndNonAsciiKeysAreRefused($key)
    {
        foreach (array(
            function () use ($key) { ZeroBounce::create()->initialize($key); },
            function () use ($key) { new ZeroBounceWarmup($key); },
        ) as $call) {
            try {
                $call();
                $this->fail('accepted key');
            } catch (ZBMissingParameterException $e) {
                $this->assertFalse(strpos($e->getMessage(), 'dummy') !== false);
            }
        }
    }

    public function testRedactionIgnoresCase()
    {
        $key = 'dummykey1234';
        foreach (array('DUMMYKEY1234', 'DummyKey1234', '?API_KEY=DUMMYKEY1234&x=1', '<title>DUMMYKEY1234</title>') as $text) {
            $out = ZBSecret::redact($text, array($key));
            $this->assertSame(0, preg_match('/dummykey/i', $out), $text);
        }
    }

    public function testRedactionFoldsUnicode()
    {
        $key = "p\xC3\xA4ssw\xC3\xB6rd-dummy";
        $upper = mb_strtoupper($key, 'UTF-8');
        $this->assertNotSame($key, $upper);
        $out = ZBSecret::redact('error for ' . $upper, array($key));
        $this->assertFalse(strpos($out, $upper) !== false);
        $this->assertSame('error for ***', $out);
    }

    public function testRedactionHandlesEntitiesAndEscapedQuote()
    {
        $key = 'dummykey1234';
        $this->assertSame(ZBSecret::CREDENTIAL_OMITTED_MESSAGE, ZBSecret::redact('&#100;ummykey1234', array($key)));
        $this->assertSame(ZBSecret::CREDENTIAL_OMITTED_MESSAGE, ZBSecret::redact('&#x64ummykey1234', array($key)));
        $this->assertSame(ZBSecret::CREDENTIAL_OMITTED_MESSAGE, ZBSecret::redact('dummy&#107;ey1234', array($key)));
        $this->assertSame(ZBSecret::CREDENTIAL_OMITTED_MESSAGE, ZBSecret::redact('\\u0064ummykey1234', array($key)));
        $quoted = "dummy'key";
        $this->assertSame(ZBSecret::CREDENTIAL_OMITTED_MESSAGE, ZBSecret::redact("dummy\\u0027key", array($quoted)));
        $this->assertSame(ZBSecret::CREDENTIAL_OMITTED_MESSAGE, ZBSecret::redact("dummy\\'key", array($quoted)));
        $this->assertFalse(strpos(ZBSecret::redact('dummy&amp;key &lt;', array('dummy&key')), 'dummy') !== false);
    }

    public function testTraceScrubbingIgnoresCase()
    {
        $secret = new ZBSecret('dummykey1234');
        $e = new \Exception('x');
        $r = new \ReflectionProperty(\Exception::class, 'trace');
        if (PHP_VERSION_ID < 80100) {
            $r->setAccessible(true);
        }
        $r->setValue($e, array(array('function' => 'f', 'args' => array('url?API_KEY=DUMMYKEY1234'))));
        ZBSecret::scrubTrace($e);
        $trace = $e->getTrace();
        $this->assertSame(0, preg_match('/dummykey/i', json_encode($trace)));
        unset($secret);
    }

    private function setExpectedException($class)
    {
        $this->expectException($class);
    }
}

class UrlProbeClient extends ZeroBounce
{
    public function __construct()
    {
        parent::__construct();
    }

    public function probe($url)
    {
        return $this->checkedUrl($url);
    }
}

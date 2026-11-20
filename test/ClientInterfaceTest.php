<?php

namespace ZeroBounce\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ZeroBounce\SDK\ZBValidateResponse;
use ZeroBounce\SDK\ZeroBounce;
use ZeroBounce\SDK\ZeroBounceInterface;
use ZeroBounce\SDK\ZeroBounceWarmup;
use ZeroBounce\SDK\ZeroBounceWarmupInterface;

/**
 * The clients are final; their interfaces are what callers type-hint and mock
 * (issue #52). Every public API method must be on the interface, so a new
 * endpoint cannot be added to the class and forgotten here.
 */
class ClientInterfaceTest extends TestCase
{
    /** Public members of the classes that are setup or PHP hooks, not API calls. */
    const NOT_API = array(
        'Instance', 'create', 'initialize', 'getFileJsonIndicatesError',
        '__construct', '__wakeup', '__debugInfo',
    );

    public static function clients()
    {
        return array(
            'ZeroBounce' => array(ZeroBounce::class, ZeroBounceInterface::class),
            'ZeroBounceWarmup' => array(ZeroBounceWarmup::class, ZeroBounceWarmupInterface::class),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('clients')]
    public function testClientIsFinalAndImplementsItsInterface($class, $interface)
    {
        $r = new ReflectionClass($class);
        $this->assertTrue($r->isFinal(), "$class stays final");
        $this->assertTrue($r->implementsInterface($interface));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('clients')]
    public function testInterfaceHasEveryPublicApiMethod($class, $interface)
    {
        $api = array();
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if (!$m->isStatic() && !in_array($m->name, self::NOT_API, true)) {
                $api[] = $m->name;
            }
        }
        $declared = array_map(function ($m) {
            return $m->name;
        }, (new ReflectionClass($interface))->getMethods());
        sort($api);
        sort($declared);
        $this->assertSame($api, $declared);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('clients')]
    public function testSignaturesMatch($class, $interface)
    {
        foreach ((new ReflectionClass($interface))->getMethods() as $im) {
            $cm = new ReflectionMethod($class, $im->name);
            $this->assertSame(
                self::signature($cm),
                self::signature($im),
                "$interface::{$im->name}() must match $class"
            );
        }
    }

    public function testInterfaceCanBeMocked()
    {
        $response = new ZBValidateResponse();
        $response->status = 'valid';
        $zb = $this->createMock(ZeroBounceInterface::class);
        $zb->expects($this->once())->method('validate')->with('a@example.com')->willReturn($response);

        $this->assertSame('valid', $zb->validate('a@example.com')->status);

        $warmup = $this->createMock(ZeroBounceWarmupInterface::class);
        $warmup->method('getSeedCount')->willReturn(array('seed_count' => 3));
        $this->assertSame(array('seed_count' => 3), $warmup->getSeedCount());
    }

    private static function signature(ReflectionMethod $m)
    {
        $params = array();
        foreach ($m->getParameters() as $p) {
            $params[] = array(
                $p->getName(),
                (string) $p->getType(),
                $p->isOptional(),
                $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : null,
            );
        }
        return array($params, (string) $m->getReturnType());
    }
}

<?php

namespace ZeroBounce\SDK;

/**
 * Holds an API key so that var_export(), serialize(), print_r() and
 * var_dump() of a client do not show it. Internal to the SDK.
 *
 * The key lives in a closure: var_export() prints a closure without its
 * bound values, and a closure cannot be serialized, so serialize() writes
 * this object empty. A client restored by unserialize() has no key and must
 * be initialized again.
 *
 * @internal
 */
final class ZBSecret
{
    /** @var \Closure|null */
    private $read;

    /**
     * @param string $value
     */
    public function __construct(
        #[\SensitiveParameter]
        $value
    ) {
        $this->read = static function () use ($value) {
            return $value;
        };
    }

    /**
     * @return string|null null in a copy restored by unserialize()
     */
    public function value()
    {
        $read = $this->read;
        return $read === null ? null : $read();
    }

    /**
     * Before PHP 7.4: nothing is serialized.
     *
     * @return string[]
     */
    public function __sleep()
    {
        return array();
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize()
    {
        return array();
    }

    /**
     * @param array<string, mixed> $data
     * @return void
     */
    public function __unserialize(array $data)
    {
        $this->read = null;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo()
    {
        return array('value' => '***');
    }

    /**
     * $e with every argument equal to $secret in its stack trace replaced by
     * '***'. PHP keeps call arguments in a trace (unless
     * zend.exception_ignore_args is on) and prints the first 15 characters
     * of each, so an exception thrown while a key is a parameter of some
     * frame would carry it. PHP 8.2+ already hides parameters marked
     * #[\SensitiveParameter]; this covers older versions.
     *
     * @param \Exception $e
     * @param mixed $secret
     * @return \Exception the same exception
     */
    public static function withoutSecretInTrace(
        \Exception $e,
        #[\SensitiveParameter]
        $secret
    ) {
        if (!is_string($secret) || $secret === '') {
            return $e;
        }
        $property = new \ReflectionProperty(\Exception::class, 'trace');
        if (\PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $trace = $property->getValue($e);
        if (!is_array($trace)) {
            return $e;
        }
        foreach ($trace as $i => $frame) {
            if (!isset($frame['args']) || !is_array($frame['args'])) {
                continue;
            }
            foreach ($frame['args'] as $j => $arg) {
                if ($arg === $secret) {
                    $trace[$i]['args'][$j] = '***';
                }
            }
        }
        $property->setValue($e, $trace);
        return $e;
    }
}

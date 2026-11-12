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
     * Every secret some ZBSecret holds now, with the number of holders: what
     * scrubTrace() masks. Keys that look like integers are stored as ints.
     *
     * @var array<string|int, int>
     */
    private static $live = array();

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
        self::hold($value, 1);
    }

    public function __clone()
    {
        self::hold($this->value(), 1);
    }

    public function __destruct()
    {
        self::hold($this->value(), -1);
    }

    /**
     * @param string|null $value
     * @param int $change
     * @return void
     */
    private static function hold(
        #[\SensitiveParameter]
        $value,
        $change
    ) {
        if (!is_string($value) || $value === '') {
            return;
        }
        $count = (isset(self::$live[$value]) ? self::$live[$value] : 0) + $change;
        if ($count > 0) {
            self::$live[$value] = $count;
        } else {
            unset(self::$live[$value]);
        }
    }

    /**
     * $e and every previous error with each secret a ZBSecret holds (the API
     * keys of live clients, the SMTP password during testSmtpConfiguration)
     * masked out of the arguments in their stack traces. PHP records every
     * frame's arguments in a trace unless zend.exception_ignore_args is on
     * (it is off in the official Docker images and php.ini-development, and
     * PHP 7.0-7.3 has no such switch), and getTrace(), the string form,
     * print_r(), var_export() and serialize() all show them, so a request URL
     * with api_key, a parameter array or an API-Key header in any frame
     * would carry the key. ZBException calls this as it is built, so every
     * exception the SDK throws is covered, whatever path builds it.
     *
     * @param \Throwable $e
     * @return \Throwable the same error
     */
    public static function scrubTrace($e)
    {
        $forms = self::forms(array_map('strval', array_keys(self::$live)));
        if (!$forms) {
            return $e;
        }
        for ($error = $e; $error !== null; $error = $error->getPrevious()) {
            $property = new \ReflectionProperty($error instanceof \Exception ? \Exception::class : \Error::class, 'trace');
            if (\PHP_VERSION_ID < 80100) {
                $property->setAccessible(true);
            }
            $trace = $property->getValue($error);
            if (!is_array($trace)) {
                continue;
            }
            foreach ($trace as $i => $frame) {
                if (isset($frame['args']) && is_array($frame['args'])) {
                    $trace[$i]['args'] = self::masked($frame['args'], $forms);
                }
            }
            $property->setValue($error, $trace);
        }
        return $e;
    }

    /**
     * Every form the secrets can take in a body or URL echoed back to us,
     * composed from the encoders up to depth 2: {raw, JSON-escaped with "\/",
     * JSON-escaped with plain "/"} x {as is, URL-encoded with +, URL-encoded
     * with %20}, and JSON-escaped twice (with and without "\/"); each JSON
     * form also with non-ASCII as is or as \uXXXX. Longest first, so a form
     * that contains another is masked whole.
     *
     * @param array<int, string|null> $secrets
     * @return string[]
     */
    public static function forms(
        #[\SensitiveParameter]
        array $secrets
    ) {
        $forms = array();
        foreach ($secrets as $secret) {
            if (!is_string($secret) || $secret === '') {
                continue;
            }
            $flagSets = array(0, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $escaped = array($secret);
            foreach ($flagSets as $flags) {
                $escaped[] = self::jsonEscaped($secret, $flags);
            }
            $candidates = array();
            foreach ($escaped as $form) {
                if ($form !== null) {
                    $candidates[] = $form;
                    $candidates[] = urlencode($form);
                    $candidates[] = rawurlencode($form);
                }
            }
            // A JSON string carrying a JSON-escaped secret (an error body
            // quoting the JSON request body), escaped twice the same way.
            foreach ($flagSets as $flags) {
                $once = self::jsonEscaped($secret, $flags);
                $candidates[] = $once === null ? null : self::jsonEscaped($once, $flags);
            }
            foreach ($candidates as $form) {
                if (is_string($form) && $form !== '') {
                    $forms[$form] = strlen($form);
                }
            }
        }
        arsort($forms);
        return array_map('strval', array_keys($forms));
    }

    /**
     * @param string $text
     * @param int $flags json_encode flags
     * @return string|null $text as it appears inside a JSON string, or null
     *     when it cannot be encoded (invalid UTF-8)
     */
    private static function jsonEscaped(
        #[\SensitiveParameter]
        $text,
        $flags
    ) {
        $json = json_encode($text, $flags);
        return is_string($json) ? substr($json, 1, -1) : null;
    }

    /**
     * $text with every form of every secret replaced by ***.
     *
     * @param string $text
     * @param array<int, string|null> $secrets
     * @return string
     */
    public static function redact(
        $text,
        #[\SensitiveParameter]
        array $secrets
    ) {
        $forms = self::forms($secrets);
        return $forms ? str_replace($forms, '***', (string)$text) : (string)$text;
    }

    /**
     * @param mixed $value
     * @param string[] $forms
     * @return mixed $value with every form replaced by ***, in nested arrays
     *     too; an error passed as an argument (a caught exception handed to
     *     a helper that rebuilds it) by its class name, since its message
     *     and trace are the unredacted ones
     */
    private static function masked($value, array $forms)
    {
        if ($value instanceof \Exception || $value instanceof \Throwable) {
            return 'object(' . get_class($value) . ')';
        }
        if (is_string($value)) {
            return str_replace($forms, '***', $value);
        }
        if (is_array($value)) {
            foreach ($value as $k => $item) {
                $value[$k] = self::masked($item, $forms);
            }
        }
        return $value;
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

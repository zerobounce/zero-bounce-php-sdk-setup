<?php

namespace ZeroBounce\SDK;

/**
 * Strict parsing of boolean options, shared by ZeroBounce and
 * ZeroBounceWarmup. Internal to the SDK.
 */
trait ZBStrictBool
{
    /**
     * A boolean option, parsed strictly: true/false, "true"/"false" in any
     * case, or 1/0. Anything else is rejected, so "false" is never sent as
     * true and a typo is not taken as a flag.
     *
     * @param mixed $value
     * @param string $name
     * @return bool
     * @throws ZBMissingParameterException
     */
    protected static function strictBool($value, $name)
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return $value === 1;
        }
        if (is_string($value)) {
            $text = strtolower(trim($value));
            if ($text === 'true' || $text === '1') {
                return true;
            }
            if ($text === 'false' || $text === '0') {
                return false;
            }
        }
        throw new ZBMissingParameterException($name . ' must be true or false');
    }
}

<?php

namespace ZeroBounce\SDK;

/**
 * Strict parsing of boolean options, shared by ZeroBounce and
 * ZeroBounceWarmup. Internal to the SDK.
 */
trait ZBStrictBool
{
    /**
     * Whether $value is a real calendar date written YYYY-MM-DD (no trailing
     * newline, no 2024-13-45).
     *
     * @param mixed $value
     * @return bool
     */
    private static function isRealDate($value)
    {
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) {
            return false;
        }
        $date = \DateTime::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTime && $date->format('Y-m-d') === $value;
    }

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
    private static function strictBool($value, $name)
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

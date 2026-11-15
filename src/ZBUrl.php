<?php

namespace ZeroBounce\SDK;

/**
 * Checks of the URLs and the API key the SDK sends requests with. Internal to
 * the SDK.
 *
 * @internal
 */
final class ZBUrl
{
    /** Whitespace, control and format characters (NBSP, U+2028, U+200B, U+FEFF, U+180E, ...). */
    const KEY_CHARACTERS_PROBLEM = 'api_key has whitespace, control or invisible characters';
    const KEY_HEADER_PROBLEM = 'api_key has characters that cannot be sent in an HTTP header';

    const INVISIBLE = '/[\s\p{Z}\p{Cc}\p{Cf}\x{180E}]/u';

    /**
     * Whether $url is an absolute https URL with a valid host (DNS labels or
     * a bracketed IPv6 address) and a valid port, and with no userinfo, no
     * whitespace, control or format characters and no backslash anywhere.
     * `https://api.zerobounce.net@evil.example/v2` sends the key to
     * evil.example, so it is refused.
     *
     * @param mixed $url
     * @return bool
     */
    public static function isSafeHttps($url)
    {
        if (!is_string($url) || $url === '') {
            return false;
        }
        // false on invalid UTF-8 as well: refused.
        if (preg_match(self::INVISIBLE, $url) !== 0 || strpos($url, '\\') !== false) {
            return false;
        }
        if (!preg_match('~^https://([^/?#]*)(?:[/?#].*)?$~is', $url, $m)) {
            return false;
        }
        $authority = $m[1];
        if ($authority === '' || strpos($authority, '@') !== false) {
            return false;
        }
        if ($authority[0] === '[') {
            if (!preg_match('#^\[([0-9A-Fa-f:.]+)(?:%[0-9A-Za-z._~-]+)?\](?::([0-9]+))?$#', $authority, $h)) {
                return false;
            }
            if (!filter_var($h[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return false;
            }
            $port = isset($h[2]) ? $h[2] : null;
        } else {
            if (!preg_match('~^([^:]+)(?::([0-9]*))?$~', $authority, $h)) {
                return false;
            }
            if (!preg_match('~^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$~', $h[1])) {
                return false;
            }
            $port = isset($h[2]) ? $h[2] : null;
        }
        if ($port !== null && ($port === '' || (int)$port < 1 || (int)$port > 65535 || strlen($port) > 5)) {
            return false;
        }
        return true;
    }

    /**
     * Whether an API key or password carries whitespace, control or format
     * characters, or anything outside printable ASCII.
     *
     * @param string $key
     * @return bool
     */
    public static function hasInvisible(
        #[\SensitiveParameter]
        $key
    ) {
        return preg_match(self::INVISIBLE, $key) !== 0;
    }

    /**
     * @param string $key
     * @return bool
     */
    public static function hasNonPrintableAscii(
        #[\SensitiveParameter]
        $key
    ) {
        return preg_match('/[^\x21-\x7e]/', $key) === 1;
    }
}

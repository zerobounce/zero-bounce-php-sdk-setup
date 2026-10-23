<?php

namespace ZeroBounce\SDK;

/**
 * Turns HTTP failures, HTML pages, and empty or non-JSON bodies into a short ZBException message.
 */
class ZBHttpError
{
    public static function emptyMessage($status)
    {
        return 'Empty response from ZeroBounce (HTTP ' . (int)$status . ')';
    }

    public static function contentType(array $headers)
    {
        foreach ($headers as $header) {
            if (!is_string($header) || stripos($header, 'Content-Type:') !== 0) {
                continue;
            }
            $value = trim(substr($header, strlen('Content-Type:')));
            $semi = strpos($value, ';');
            if ($semi !== false) {
                $value = trim(substr($value, 0, $semi));
            }
            return $value;
        }
        return '';
    }

    public static function isHtml($body, array $headers = array())
    {
        if (stripos(self::contentType($headers), 'text/html') !== false) {
            return true;
        }
        $sample = strtolower(substr(ltrim((string)$body), 0, 2000));
        return strpos($sample, '<!doctype html') !== false
            || strpos($sample, '<html') !== false
            || strpos($sample, '<head') !== false
            || strpos($sample, '<body') !== false;
    }

    public static function message($status, $body, array $headers = array())
    {
        $trimmed = trim((string)$body);
        if ($trimmed === '') {
            return self::emptyMessage($status);
        }
        if (self::isHtml($body, $headers)) {
            $title = self::htmlTitle($body);
            if ($title === '') {
                return 'ZeroBounce returned an HTML error page instead of an API response.';
            }
            return 'ZeroBounce returned an HTML error page ("' . $title . '") instead of an API response.';
        }
        $first = $trimmed[0];
        if ($first === '{' || $first === '[') {
            $decoded = json_decode($trimmed, true);
            if (!is_array($decoded)) {
                return 'ZeroBounce returned a response that is not valid JSON.';
            }
            if (!self::isList($decoded) && isset($decoded['title']) && is_string($decoded['title'])) {
                $title = $decoded['title'];
                $accessDenied = stripos($title, 'Access denied') !== false || strpos($title, '1020') !== false;
                $cloudflare = !empty($decoded['cloudflare_error']);
                if ($accessDenied && ($cloudflare || !self::hasErrorOrMessageKey($decoded))) {
                    return self::stripPrefix($title);
                }
            }
            $fromField = self::stringField($decoded);
            if ($fromField !== '') {
                return self::stripPrefix($fromField);
            }
            if (strlen($trimmed) > 500) {
                return 'ZeroBounce returned a response that is not valid JSON.';
            }
            return self::stripPrefix($trimmed);
        }
        if (strlen($trimmed) > 1000) {
            return 'ZeroBounce returned a response that is not valid JSON.';
        }
        $message = self::stripPrefix($trimmed);
        if ($message === '') {
            return self::emptyMessage($status);
        }
        return $message;
    }

    public static function throwIfBareAccountError($decoded)
    {
        if (!is_array($decoded) || self::isList($decoded)) {
            return;
        }
        if (!isset($decoded['error']) || !is_string($decoded['error']) || trim($decoded['error']) === '') {
            return;
        }
        $payloadKeys = array(
            'address', 'email_batch', 'Credits', 'credits', 'file_id', 'success', 'found',
            'total', 'email', 'format', 'domain', 'file_status', 'echo_api_key',
        );
        foreach ($payloadKeys as $key) {
            if (array_key_exists($key, $decoded)) {
                return;
            }
        }
        throw new ZBException(self::stripPrefix(trim($decoded['error'])));
    }

    public static function stripPrefix($message)
    {
        $message = trim((string)$message);
        while ($message !== '') {
            if (stripos($message, 'Error:') === 0) {
                $message = trim(substr($message, strlen('Error:')));
                continue;
            }
            if (stripos($message, '[Error]:') === 0) {
                $message = trim(substr($message, strlen('[Error]:')));
                continue;
            }
            break;
        }
        return $message;
    }

    private static function htmlTitle($body)
    {
        $lower = strtolower((string)$body);
        $start = strpos($lower, '<title');
        if ($start === false) {
            return '';
        }
        $openEnd = strpos($lower, '>', $start);
        if ($openEnd === false) {
            return '';
        }
        $close = strpos($lower, '</title>', $openEnd);
        if ($close === false) {
            return '';
        }
        return trim(substr($body, $openEnd + 1, $close - $openEnd - 1));
    }

    private static function hasErrorOrMessageKey(array $decoded)
    {
        foreach ($decoded as $key => $value) {
            $name = (string)$key;
            if (stripos($name, 'error') !== false || stripos($name, 'message') !== false) {
                return true;
            }
        }
        return false;
    }

    private static function stringField(array $decoded)
    {
        foreach (array('message', 'error', 'error_message', 'detail') as $key) {
            if (!isset($decoded[$key]) || $decoded[$key] === '' || $decoded[$key] === null || $decoded[$key] === array()) {
                continue;
            }
            if (is_string($decoded[$key]) && trim($decoded[$key]) !== '') {
                return trim($decoded[$key]);
            }
            if (is_array($decoded[$key]) && isset($decoded[$key][0]) && is_string($decoded[$key][0]) && trim($decoded[$key][0]) !== '') {
                return trim($decoded[$key][0]);
            }
        }
        return '';
    }

    private static function isList(array $value)
    {
        if ($value === array()) {
            return true;
        }
        return array_keys($value) === range(0, count($value) - 1);
    }
}

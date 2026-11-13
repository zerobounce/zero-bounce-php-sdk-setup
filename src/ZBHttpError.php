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
        // Redirects are never followed (the key must not travel on to another
        // host), so say so instead of reporting the 3xx's usually empty body.
        if ((int)$status >= 300 && (int)$status < 400) {
            return 'ZeroBounce answered with a redirect (HTTP ' . (int)$status . '), which is not followed';
        }
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

    /**
     * A dead or invalid key on a 2xx: a non-empty "error", "Error" or
     * "error_message" string in a body that carries no result.
     *
     * @param mixed $decoded the parsed body
     * @param int $status the HTTP status, carried by the exception
     * @throws ZBApiException
     */
    public static function throwIfBareAccountError($decoded, $status = 200)
    {
        if (!is_array($decoded) || self::isList($decoded)) {
            return;
        }
        // LIVE: the filters spell it "Error": an invalid or drained key answers
        // them with {"Error": "Invalid API key or your account ran out of credits"}.
        $errorKey = null;
        foreach (array('error', 'Error', 'error_message') as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key]) && trim($decoded[$key]) !== '') {
                $errorKey = $key;
                break;
            }
        }
        if ($errorKey === null) {
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
        throw new ZBApiException(self::stripPrefix(trim($decoded[$errorKey])), $status);
    }

    /**
     * For a call whose result is never a bare message - the email finder and
     * domain search - a body made only of message fields is a failure: an
     * object with no "success" field whose keys are all message keys, whatever
     * their values (a blank, null or list message included). LIVE: guessformat answers an invalid or drained
     * key with an HTTP 200 and
     * {"Message": "Invalid API key or your account ran out of credits"}.
     * Not for validate: a repeated greylist_processing request answers
     * {"Message": "Greylist validation still in progress..."}, a result.
     *
     * @param mixed $decoded the parsed body
     * @param int $status the HTTP status, carried by the exception
     * @throws ZBApiException
     */
    public static function throwIfMessageOnly($decoded, $status = 200)
    {
        if (!is_array($decoded) || self::isList($decoded) || array_key_exists('success', $decoded) || $decoded === array()) {
            return;
        }
        $messageKeys = array('error', 'message', 'error_message', 'Message', 'Error', 'detail');
        foreach ($decoded as $key => $value) {
            if (!in_array((string)$key, $messageKeys, true)) {
                return;
            }
        }
        // Whatever the message's value (text, blank, null or a list), the body is a rejected request.
        $text = '';
        foreach ($messageKeys as $key) {
            if (array_key_exists($key, $decoded)) {
                $text = self::messageText($decoded[$key]);
                if ($text !== '') {
                    break;
                }
            }
        }
        throw new ZBApiException(self::stripPrefix($text !== '' ? $text : 'Request failed'), $status);
    }

    /**
     * The text of a message value: a non-blank string, or the first non-blank string of a list; else ''.
     *
     * @param mixed $value
     * @return string
     */
    private static function messageText($value)
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item) && trim($item) !== '') {
                    return trim($item);
                }
            }
            return '';
        }
        return is_string($value) ? trim($value) : '';
    }

    /**
     * {"success": false} on an HTTP 200 is a failure, not a result. The live
     * API answers an unknown file id and an unknown list-evaluator id that way,
     * with a 200.
     *
     * @param mixed $decoded the parsed body
     * @param int $status
     * @param string $body
     * @param string[] $headers
     * @throws ZBApiException
     */
    public static function throwIfSuccessIsFalse($decoded, $status, $body, array $headers = array())
    {
        if (!is_array($decoded) || self::isList($decoded) || !array_key_exists('success', $decoded)) {
            return;
        }
        if (!self::isFalsySuccess($decoded['success'])) {
            return;
        }
        throw new ZBApiException(self::message($status, $body, $headers), $status);
    }

    /**
     * Whether a "success" field means failure. The API sends it as a JSON
     * false, "false" or "False" depending on the endpoint.
     *
     * @param mixed $value
     * @return bool
     */
    private static function isFalsySuccess($value)
    {
        if (is_bool($value)) {
            return !$value;
        }
        return is_string($value) && strcasecmp(trim($value), 'false') === 0;
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
        foreach (array('message', 'error', 'error_message', 'Message', 'Error', 'detail') as $key) {
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

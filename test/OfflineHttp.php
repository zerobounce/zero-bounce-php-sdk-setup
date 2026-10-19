<?php

namespace ZeroBounce\Tests;

use ZeroBounce\SDK\ZeroBounce;

/**
 * In-memory ZeroBounce API. Records the request and answers from the API key prefix.
 */
class FakeApi
{
    /** @var array<int, array<string, mixed>> */
    private $records = array();

    /**
     * @param string $method
     * @param string $url
     * @param string[] $headers
     * @param string|null $body
     * @param int $timeout
     * @return array{status: int, headers: string[], body: string}
     */
    public function handle($method, $url, array $headers, $body, $timeout)
    {
        $parsed = parse_url($url);
        $host = isset($parsed['host']) ? $parsed['host'] : '';
        $path = isset($parsed['path']) ? $parsed['path'] : '';
        $query = array();
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }
        $contentType = '';
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $contentType = trim(substr($header, strlen('Content-Type:')));
            }
        }
        $fields = array();
        foreach ($query as $key => $value) {
            $fields[$key] = is_array($value) ? json_encode($value) : (string)$value;
        }
        if ($body !== null && $body !== '') {
            $fields = array_merge($fields, $this->fieldsFromBody($body, $contentType));
        }
        $apiKey = isset($fields['api_key']) ? (string)$fields['api_key'] : '';
        $record = array(
            'method' => strtoupper($method),
            'url' => $url,
            'host' => $host,
            'path' => $path,
            'headers' => $headers,
            'body' => $body === null ? '' : $body,
            'content_type' => $contentType,
            'fields' => $fields,
            'api_key' => $apiKey,
            'timeout' => $timeout,
        );
        $this->records[] = $record;
        usleep(random_int(0, 3000));
        return $this->answer($record);
    }

    public function clear()
    {
        $this->records = array();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function take()
    {
        $records = $this->records;
        $this->records = array();
        return $records;
    }

    public function count()
    {
        return count($this->records);
    }

    /**
     * @param array<string, mixed> $request
     * @return array{status: int, headers: string[], body: string}
     */
    private function answer(array $request)
    {
        $key = $request['api_key'];
        $path = $request['path'];
        if (strpos($key, 'html-403-') === 0 || strpos($key, 'html-200-') === 0) {
            $status = strpos($key, 'html-403-') === 0 ? 403 : 200;
            return $this->respond($status, 'text/html; charset=UTF-8', self::accessDeniedPage());
        }
        if (strpos($key, 'empty-') === 0) {
            return $this->respond(200, 'application/json', '');
        }
        if (strpos($key, 'malformed-') === 0) {
            return $this->respond(200, 'application/json', '<<<not-json>>>' . str_repeat('x', 5000));
        }
        if (strpos($key, 'cf-json-') === 0) {
            $body = '{"type":"https://developers.cloudflare.com/support/troubleshooting/http-status-codes/cloudflare-1xxx-errors/error-1020/","title":"Error 1020: Access denied","status":403,"detail":"The request was blocked by a Cloudflare firewall rule configured by the site owner.","error_code":1020,"cloudflare_error":true,"what_you_should_do":"Do not retry. This block is intentional."}';
            return $this->respond(403, 'application/json', $body);
        }
        if (strpos($key, 'double-error-') === 0) {
            return $this->respond(400, 'text/plain', 'Error: Error: api_key is invalid');
        }
        if (strpos($key, 'account-invalid-') === 0 || strpos($key, 'account-nocredits-') === 0) {
            $message = strpos($key, 'account-nocredits-') === 0
                ? 'Your account ran out of credits'
                : 'Invalid API Key or your account ran out of credits';
            if (strpos($path, 'validatebatch') !== false) {
                $body = json_encode(array(
                    'email_batch' => array(),
                    'errors' => array(array(
                        'email_address' => 'all',
                        'error' => $message,
                    )),
                ));
            } else {
                $body = json_encode(array('error' => $message));
            }
            return $this->respond(200, 'application/json', $body);
        }
        if (strpos($path, 'validatebatch') !== false && strpos($key, 'batch-partial-') === 0) {
            $body = json_encode(array(
                'email_batch' => array(array(
                    'address' => 'good@example.com',
                    'status' => 'valid',
                )),
                'errors' => array(array(
                    'email_address' => 'bad@',
                    'error' => 'Invalid email address',
                )),
            ));
            return $this->respond(200, 'application/json', $body);
        }

        $quoted = json_encode($key);
        if (strpos($path, 'getfile') !== false) {
            return $this->respond(200, 'text/csv', "email\n" . $key . "@example.com\n");
        }
        if (strpos($path, 'listS3files') !== false) {
            return $this->respond(200, 'application/json', '[{"file_name":' . $quoted . ',"size":1}]');
        }
        if (strpos($path, 'validatebatch') !== false) {
            return $this->respond(200, 'application/json', '{"email_batch":[{"address":' . $quoted . ',"status":"valid"}],"errors":[]}');
        }
        if (substr($path, -9) === '/validate') {
            return $this->respond(200, 'application/json', '{"address":' . $quoted . ',"status":"valid"}');
        }
        if (substr($path, -8) === '/scoring') {
            return $this->respond(200, 'application/json', '{"email":' . $quoted . ',"score":10}');
        }
        if (strpos($path, 'getcredits') !== false) {
            return $this->respond(200, 'application/json', '{"Credits":"7"}');
        }
        if (strpos($path, 'getapiusage') !== false) {
            return $this->respond(200, 'application/json', '{"total":1}');
        }
        if (substr($path, -9) === '/activity') {
            return $this->respond(200, 'application/json', '{"found":true,"active_in_days":"3"}');
        }
        if (strpos($path, 'filters') !== false || (strpos($path, 'listevaluator') !== false && strpos($path, 'sendfile') === false && $request['method'] === 'GET')) {
            return $this->respond(200, 'application/json', '{"echo_api_key":' . $quoted . '}');
        }
        if (strpos($path, 'guessformat') !== false) {
            return $this->respond(200, 'application/json', '{"email":' . $quoted . ',"domain":' . $quoted . ',"format":"first.last","confidence":"high","other_domain_formats":[]}');
        }
        return $this->respond(
            200,
            'application/json',
            '{"success":true,"message":"ok","file_id":' . $quoted . ',"file_name":"f.csv","email":' . $quoted . ',"domain":' . $quoted . ',"other_domain_formats":[]}'
        );
    }

    /**
     * @return array{status: int, headers: string[], body: string}
     */
    private function respond($status, $contentType, $body)
    {
        $reason = $status === 200 ? 'OK' : ($status === 403 ? 'Forbidden' : 'Error');
        return array(
            'status' => $status,
            'headers' => array(
                'HTTP/1.1 ' . $status . ' ' . $reason,
                'Content-Type: ' . $contentType,
            ),
            'body' => $body,
        );
    }

    /**
     * @return array<string, string>
     */
    private function fieldsFromBody($body, $contentType)
    {
        if (stripos($contentType, 'application/json') !== false || (isset($body[0]) && ($body[0] === '{' || $body[0] === '['))) {
            $decoded = json_decode($body, true);
            $fields = array('__json' => $body);
            if (!is_array($decoded) || $this->isList($decoded)) {
                return $fields;
            }
            foreach ($decoded as $key => $value) {
                if (is_bool($value)) {
                    $fields[$key] = $value ? 'true' : 'false';
                } elseif (is_array($value)) {
                    $fields[$key] = json_encode($value);
                } elseif ($value === null) {
                    $fields[$key] = '';
                } else {
                    $fields[$key] = (string)$value;
                }
            }
            return $fields;
        }
        if (stripos($contentType, 'multipart/form-data') !== false) {
            return $this->parseMultipart($body, $contentType);
        }
        $parsed = array();
        parse_str($body, $parsed);
        $fields = array();
        foreach ($parsed as $key => $value) {
            $fields[$key] = is_array($value) ? json_encode($value) : (string)$value;
        }
        return $fields;
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value)
    {
        if ($value === array()) {
            return true;
        }
        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @return array<string, string>
     */
    private function parseMultipart($body, $contentType)
    {
        $fields = array();
        if (!preg_match('/boundary=([^;]+)/', $contentType, $match)) {
            $fields['__raw'] = $body;
            return $fields;
        }
        $boundary = trim($match[1], " \t\"");
        $parts = explode('--' . $boundary, $body);
        foreach ($parts as $part) {
            if (strpos($part, 'Content-Disposition') === false) {
                continue;
            }
            if (!preg_match('/name="([^"]+)"/', $part, $name)) {
                continue;
            }
            $split = preg_split("/\r\n\r\n/", $part, 2);
            $value = isset($split[1]) ? preg_replace("/\r\n$/", '', $split[1]) : '';
            if (strpos($part, 'filename=') !== false) {
                $fields[$name[1]] = '<file>';
                if (preg_match('/filename="([^"]*)"/', $part, $fileName)) {
                    $fields['__filename'] = $fileName[1];
                }
                continue;
            }
            $fields[$name[1]] = $value;
        }
        return $fields;
    }

    public static function accessDeniedPage()
    {
        return '<!DOCTYPE html><html lang="en-US"><head><title>Access denied | zerobounce.net | Zero Bounce</title></head><body>'
            . str_repeat('<p>blocked</p>', 20000)
            . '</body></html>';
    }
}

class OfflineClient extends ZeroBounce
{
    /** @var FakeApi */
    public $fake;

    public function __construct(FakeApi $fake)
    {
        parent::__construct();
        $this->fake = $fake;
    }

    protected function executeHttp($method, $url, array $headers = array(), $body = null, $timeout = 120)
    {
        return $this->fake->handle($method, $url, $headers, $body, $timeout);
    }
}

class DirectClient extends ZeroBounce
{
    public function __construct()
    {
        parent::__construct();
    }
}

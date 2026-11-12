<?php

namespace ZeroBounce\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBounce\SDK\ZBGetFileOptions;
use ZeroBounce\SDK\ZBValidateOptions;
use ZeroBounce\SDK\ZeroBounce;
use ZeroBounce\SDK\ZeroBounceWarmup;

/** Records every request and answers each with a 200 {"success": true}. */
class PayloadRecordingClient extends ZeroBounce
{
    /** @var array<int, array<string, mixed>> */
    public $sent = array();

    public function __construct()
    {
        parent::__construct();
    }

    protected function executeHttp($method, $url, array $headers = array(), $body = null, $timeout = 120)
    {
        $this->sent[] = RequestPayloadsTest::shape($method, $url, $headers, $body);
        return array('status' => 200, 'headers' => array('HTTP/1.1 200 OK', 'Content-Type: application/json'), 'body' => '{"success":true}');
    }

    protected function streamHttpToHandle($url, $handle)
    {
        $this->sent[] = RequestPayloadsTest::shape('GET', $url, array(), null);
        fwrite($handle, "email\na@example.com\n");
        return array('status' => 200, 'headers' => array('HTTP/1.1 200 OK', 'Content-Type: text/csv'), 'head' => "email\na@example.com\n", 'error' => '');
    }
}

/** The Warmup client, recording like PayloadRecordingClient. */
class PayloadRecordingWarmup extends ZeroBounceWarmup
{
    /** @var array<int, array<string, mixed>> */
    public $sent = array();

    protected function executeHttp($method, $url, array $headers = array(), $body = null, $timeout = 120)
    {
        $this->sent[] = RequestPayloadsTest::shape($method, $url, $headers, $body);
        return array('status' => 200, 'headers' => array('HTTP/1.1 200 OK', 'Content-Type: application/json'), 'body' => '{"success":true}');
    }
}

/**
 * The exact request every public request method builds: verb, host, path,
 * query and body (JSON, form or multipart fields). A wrong field name - a
 * mutant that sends old_address for old_from_address, or c_from in the body
 * instead of the query - fails here. The tables cover every public request
 * method of ZeroBounce and ZeroBounceWarmup; a new method fails the
 * completeness checks until it is added.
 */
class RequestPayloadsTest extends TestCase
{
    /**
     * @param string $method
     * @param string $url
     * @param string[] $headers
     * @param string|null $body
     * @return array<string, mixed>
     */
    public static function shape($method, $url, array $headers, $body)
    {
        $parts = parse_url($url);
        $query = array();
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $type = '';
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $type = trim(substr($header, strlen('Content-Type:')));
            }
        }
        $fields = null;
        if ($body !== null && $body !== '') {
            if (stripos($type, 'json') !== false) {
                $fields = json_decode($body, true);
            } elseif (stripos($type, 'multipart/form-data') !== false) {
                $fields = self::multipart($body, $type);
            } else {
                parse_str($body, $fields);
            }
        }
        return array(
            'verb' => strtoupper($method),
            'url' => $parts['host'] . (isset($parts['path']) ? $parts['path'] : ''),
            'query' => $query,
            'body' => $fields,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function multipart($body, $type)
    {
        preg_match('/boundary=([^;]+)/', $type, $match);
        $fields = array();
        foreach (explode('--' . trim($match[1]), $body) as $part) {
            if (!preg_match('/name="([^"]+)"/', $part, $name)) {
                continue;
            }
            $split = explode("\r\n\r\n", $part, 2);
            $value = isset($split[1]) ? preg_replace("/\r\n$/", '', $split[1]) : '';
            if (preg_match('/filename="([^"]*)"/', $part, $file)) {
                $value = '<file ' . $file[1] . '>';
            }
            $fields[$name[1]] = $value;
        }
        return $fields;
    }

    private static function options(array $values)
    {
        $options = new ZBValidateOptions();
        foreach ($values as $name => $value) {
            $options->{$name} = $value;
        }
        return $options;
    }

    private static function fileOptions(array $values)
    {
        $options = new ZBGetFileOptions();
        foreach ($values as $name => $value) {
            $options->{$name} = $value;
        }
        return $options;
    }

    /**
     * name (the method, then an optional variant) => array(call, the one request it sends)
     *
     * @return array<string, array{0: callable, 1: array<string, mixed>}>
     */
    public static function coreTable()
    {
        return array(
            'validate' => array(
                function ($c, $w, $csv, $out, $D) { $c->validate("a@example.com", "1.2.3.4", self::options(array("timeout" => 10, "activityData" => true, "verifyPlus" => false, "domainInfo" => true, "greylistProcessing" => true, "callbackUrl" => $D))); },
                array (
                  'verb' => 'GET',
                  'url' => 'api.zerobounce.net/v2/validate',
                  'query' => array (
                    'api_key' => 'payload-key',
                    'email' => 'a@example.com',
                    'ip_address' => '1.2.3.4',
                    'timeout' => '10',
                    'activity_data' => 'true',
                    'verify_plus' => 'false',
                    'domain_info' => 'true',
                    'greylist_processing' => 'true',
                    'callback_url' => 'https://example.com/d',
                  ),
                  'body' => NULL,
                ),
            ),
            'validate usePost' => array(
                function ($c, $w, $csv, $out, $D) { $c->validate("a@example.com", "1.2.3.4", self::options(array("timeout" => 10, "activityData" => true, "usePost" => true))); },
                array (
                  'verb' => 'POST',
                  'url' => 'api.zerobounce.net/v2/validate',
                  'query' => array (
                  ),
                  'body' => array (
                    'email' => 'a@example.com',
                    'ip_address' => '1.2.3.4',
                    'timeout' => '10',
                    'activity_data' => 'true',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'validateBatch' => array(
                function ($c, $w, $csv, $out, $D) { $c->validateBatch(array(array("a@example.com", "1.2.3.4"), array("b@example.com", null)), self::options(array("timeout" => 30, "activityData" => true, "verifyPlus" => true))); },
                array (
                  'verb' => 'POST',
                  'url' => 'api.zerobounce.net/v2/validatebatch',
                  'query' => array (
                  ),
                  'body' => array (
                    'email_batch' => array (
                      0 => array (
                        'email_address' => 'a@example.com',
                        'ip_address' => '1.2.3.4',
                      ),
                      1 => array (
                        'email_address' => 'b@example.com',
                        'ip_address' => NULL,
                      ),
                    ),
                    'timeout' => '30',
                    'activity_data' => true,
                    'verify_plus' => true,
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'getCredits' => array(
                function ($c, $w, $csv, $out, $D) { $c->getCredits(); },
                array (
                  'verb' => 'GET',
                  'url' => 'api.zerobounce.net/v2/getcredits',
                  'query' => array (
                    'api_key' => 'payload-key',
                  ),
                  'body' => NULL,
                ),
            ),
            'getCredits usePost' => array(
                function ($c, $w, $csv, $out, $D) { $c->getCredits(true); },
                array (
                  'verb' => 'POST',
                  'url' => 'api.zerobounce.net/v2/getcredits',
                  'query' => array (
                  ),
                  'body' => array (
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'getApiUsage' => array(
                function ($c, $w, $csv, $out, $D) { $c->getApiUsage("2024-01-01", "2024-01-02"); },
                array (
                  'verb' => 'GET',
                  'url' => 'api.zerobounce.net/v2/getapiusage',
                  'query' => array (
                    'start_date' => '2024-01-01',
                    'end_date' => '2024-01-02',
                    'api_key' => 'payload-key',
                  ),
                  'body' => NULL,
                ),
            ),
            'getApiUsage usePost' => array(
                function ($c, $w, $csv, $out, $D) { $c->getApiUsage("2024-01-01", "2024-01-02", true); },
                array (
                  'verb' => 'POST',
                  'url' => 'api.zerobounce.net/v2/getapiusage',
                  'query' => array (
                  ),
                  'body' => array (
                    'start_date' => '2024-01-01',
                    'end_date' => '2024-01-02',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'getActivity' => array(
                function ($c, $w, $csv, $out, $D) { $c->getActivity("a@example.com"); },
                array (
                  'verb' => 'GET',
                  'url' => 'api.zerobounce.net/v2/activity',
                  'query' => array (
                    'email' => 'a@example.com',
                    'api_key' => 'payload-key',
                  ),
                  'body' => NULL,
                ),
            ),
            'getActivity usePost' => array(
                function ($c, $w, $csv, $out, $D) { $c->getActivity("a@example.com", true); },
                array (
                  'verb' => 'POST',
                  'url' => 'api.zerobounce.net/v2/activity',
                  'query' => array (
                  ),
                  'body' => array (
                    'email' => 'a@example.com',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'scoreEmail' => array(
                function ($c, $w, $csv, $out, $D) { $c->scoreEmail("a@example.com"); },
                array (
                  'verb' => 'GET',
                  'url' => 'api.zerobounce.net/v2/scoring',
                  'query' => array (
                    'email' => 'a@example.com',
                    'api_key' => 'payload-key',
                  ),
                  'body' => NULL,
                ),
            ),
            'scoreEmail usePost' => array(
                function ($c, $w, $csv, $out, $D) { $c->scoreEmail("a@example.com", true); },
                array (
                  'verb' => 'POST',
                  'url' => 'api.zerobounce.net/v2/scoring',
                  'query' => array (
                  ),
                  'body' => array (
                    'email' => 'a@example.com',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'scoreEmailPost' => array(
                function ($c, $w, $csv, $out, $D) { $c->scoreEmailPost("a@example.com"); },
                array (
                  'verb' => 'POST',
                  'url' => 'api.zerobounce.net/v2/scoring',
                  'query' => array (
                  ),
                  'body' => array (
                    'email' => 'a@example.com',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'sendFile' => array(
                function ($c, $w, $csv, $out, $D) { $c->sendFile($csv, 1, $D, 2, 3, 4, 5, true, true, false); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/v2/sendfile',
                  'query' => array (
                  ),
                  'body' => array (
                    'api_key' => 'payload-key',
                    'email_address_column' => '1',
                    'return_url' => 'https://example.com/d',
                    'first_name_column' => '2',
                    'last_name_column' => '3',
                    'gender_column' => '4',
                    'ip_address_column' => '5',
                    'has_header_row' => 'true',
                    'remove_duplicate' => 'false',
                    'allow_phase_2' => 'true',
                    'file' => '<file emails.csv>',
                  ),
                ),
            ),
            'sendFile unset' => array(
                function ($c, $w, $csv, $out, $D) { $c->sendFile($csv, 1); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/v2/sendfile',
                  'query' => array (
                  ),
                  'body' => array (
                    'api_key' => 'payload-key',
                    'email_address_column' => '1',
                    'file' => '<file emails.csv>',
                  ),
                ),
            ),
            'scoringSendFile' => array(
                function ($c, $w, $csv, $out, $D) { $c->scoringSendFile($csv, 2, $D, false, true); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/v2/scoring/sendfile',
                  'query' => array (
                  ),
                  'body' => array (
                    'api_key' => 'payload-key',
                    'email_address_column' => '2',
                    'return_url' => 'https://example.com/d',
                    'has_header_row' => 'false',
                    'remove_duplicate' => 'true',
                    'file' => '<file emails.csv>',
                  ),
                ),
            ),
            'sendFileStream' => array(
                function ($c, $w, $csv, $out, $D) { $c->sendFileStream("e\n", "f.csv", 2, null, null, null, null, null, null, null, false); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/v2/sendfile',
                  'query' => array (
                  ),
                  'body' => array (
                    'api_key' => 'payload-key',
                    'email_address_column' => '2',
                    'remove_duplicate' => 'false',
                    'file' => '<file f.csv>',
                  ),
                ),
            ),
            'scoringSendFileStream' => array(
                function ($c, $w, $csv, $out, $D) { $c->scoringSendFileStream("e\n", "f.csv", 1); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/v2/scoring/sendfile',
                  'query' => array (
                  ),
                  'body' => array (
                    'api_key' => 'payload-key',
                    'email_address_column' => '1',
                    'file' => '<file f.csv>',
                  ),
                ),
            ),
            'fileStatus' => array(
                function ($c, $w, $csv, $out, $D) { $c->fileStatus("f1"); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/v2/filestatus',
                  'query' => array (
                    'api_key' => 'payload-key',
                    'file_id' => 'f1',
                  ),
                  'body' => NULL,
                ),
            ),
            'scoringFileStatus' => array(
                function ($c, $w, $csv, $out, $D) { $c->scoringFileStatus("f1"); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/v2/scoring/filestatus',
                  'query' => array (
                    'api_key' => 'payload-key',
                    'file_id' => 'f1',
                  ),
                  'body' => NULL,
                ),
            ),
            'getFile' => array(
                function ($c, $w, $csv, $out, $D) { $c->getFile("f1", $out, self::fileOptions(array("downloadType" => "phase_1", "activityData" => true))); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/v2/getfile',
                  'query' => array (
                    'api_key' => 'payload-key',
                    'file_id' => 'f1',
                    'download_type' => 'phase_1',
                    'activity_data' => 'true',
                  ),
                  'body' => NULL,
                ),
            ),
            'getFile stream' => array(
                function ($c, $w, $csv, $out, $D) { $c->getFile("f1", $out, self::fileOptions(array("stream" => true))); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/v2/getfile',
                  'query' => array (
                    'api_key' => 'payload-key',
                    'file_id' => 'f1',
                  ),
                  'body' => NULL,
                ),
            ),
            'scoringGetFile' => array(
                function ($c, $w, $csv, $out, $D) { $c->scoringGetFile("f1", $out, self::fileOptions(array("downloadType" => "combined"))); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/v2/scoring/getfile',
                  'query' => array (
                    'api_key' => 'payload-key',
                    'file_id' => 'f1',
                    'download_type' => 'combined',
                  ),
                  'body' => NULL,
                ),
            ),
            'deleteFile' => array(
                function ($c, $w, $csv, $out, $D) { $c->deleteFile("f1"); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/v2/deletefile',
                  'query' => array (
                    'api_key' => 'payload-key',
                    'file_id' => 'f1',
                  ),
                  'body' => NULL,
                ),
            ),
            'scoringDeleteFile' => array(
                function ($c, $w, $csv, $out, $D) { $c->scoringDeleteFile("f1"); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/v2/scoring/deletefile',
                  'query' => array (
                    'api_key' => 'payload-key',
                    'file_id' => 'f1',
                  ),
                  'body' => NULL,
                ),
            ),
            'guessFormat' => array(
                function ($c, $w, $csv, $out, $D) { $c->guessFormat("example.com", "Ann", "B", "Lee"); },
                array (
                  'verb' => 'GET',
                  'url' => 'api.zerobounce.net/v2/guessformat',
                  'query' => array (
                    'api_key' => 'payload-key',
                    'domain' => 'example.com',
                    'first_name' => 'Ann',
                    'middle_name' => 'B',
                    'last_name' => 'Lee',
                  ),
                  'body' => NULL,
                ),
            ),
            'findEmail' => array(
                function ($c, $w, $csv, $out, $D) { $c->findEmail("example.com", "Ann", null, "B", "Lee"); },
                array (
                  'verb' => 'GET',
                  'url' => 'api.zerobounce.net/v2/guessformat',
                  'query' => array (
                    'first_name' => 'Ann',
                    'domain' => 'example.com',
                    'middle_name' => 'B',
                    'last_name' => 'Lee',
                    'api_key' => 'payload-key',
                  ),
                  'body' => NULL,
                ),
            ),
            'findEmail usePost' => array(
                function ($c, $w, $csv, $out, $D) { $c->findEmail(null, "Ann", "Acme", null, null, true); },
                array (
                  'verb' => 'POST',
                  'url' => 'api.zerobounce.net/v2/guessformat',
                  'query' => array (
                  ),
                  'body' => array (
                    'first_name' => 'Ann',
                    'company_name' => 'Acme',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'findEmailFormat' => array(
                function ($c, $w, $csv, $out, $D) { $c->findEmailFormat("example.com"); },
                array (
                  'verb' => 'GET',
                  'url' => 'api.zerobounce.net/v2/guessformat',
                  'query' => array (
                    'domain' => 'example.com',
                    'api_key' => 'payload-key',
                  ),
                  'body' => NULL,
                ),
            ),
            'findEmailFormat usePost' => array(
                function ($c, $w, $csv, $out, $D) { $c->findEmailFormat(null, "Acme", true); },
                array (
                  'verb' => 'POST',
                  'url' => 'api.zerobounce.net/v2/guessformat',
                  'query' => array (
                  ),
                  'body' => array (
                    'company_name' => 'Acme',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'sendEmailFinderFile' => array(
                function ($c, $w, $csv, $out, $D) { $c->sendEmailFinderFile($csv, 1, 2, 3, 4, null, true); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/email-finder/sendfile',
                  'query' => array (
                  ),
                  'body' => array (
                    'domain_column' => '1',
                    'has_header_row' => 'true',
                    'first_name_column' => '2',
                    'last_name_column' => '3',
                    'middle_name_column' => '4',
                    'api_key' => 'payload-key',
                    'file' => '<file emails.csv>',
                  ),
                ),
            ),
            'sendEmailFinderFileStream' => array(
                function ($c, $w, $csv, $out, $D) { $c->sendEmailFinderFileStream("e\n", "f.csv", 1, null, null, null, 2); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/email-finder/sendfile',
                  'query' => array (
                  ),
                  'body' => array (
                    'domain_column' => '1',
                    'full_name_column' => '2',
                    'api_key' => 'payload-key',
                    'file' => '<file f.csv>',
                  ),
                ),
            ),
            'emailFinderFileStatus' => array(
                function ($c, $w, $csv, $out, $D) { $c->emailFinderFileStatus("f1"); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/email-finder/filestatus',
                  'query' => array (
                    'api_key' => 'payload-key',
                    'file_id' => 'f1',
                  ),
                  'body' => NULL,
                ),
            ),
            'emailFinderGetFile' => array(
                function ($c, $w, $csv, $out, $D) { $c->emailFinderGetFile("f1", $out); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/email-finder/getfile',
                  'query' => array (
                    'api_key' => 'payload-key',
                    'file_id' => 'f1',
                  ),
                  'body' => NULL,
                ),
            ),
            'emailFinderDeleteFile' => array(
                function ($c, $w, $csv, $out, $D) { $c->emailFinderDeleteFile("f1"); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/email-finder/deletefile',
                  'query' => array (
                    'api_key' => 'payload-key',
                    'file_id' => 'f1',
                  ),
                  'body' => NULL,
                ),
            ),
            'sendDomainSearchFile' => array(
                function ($c, $w, $csv, $out, $D) { $c->sendDomainSearchFile($csv, 1, true); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/domain-search/sendfile',
                  'query' => array (
                  ),
                  'body' => array (
                    'domain_column' => '1',
                    'has_header_row' => 'true',
                    'api_key' => 'payload-key',
                    'file' => '<file emails.csv>',
                  ),
                ),
            ),
            'sendDomainSearchFileStream' => array(
                function ($c, $w, $csv, $out, $D) { $c->sendDomainSearchFileStream("d\n", "f.csv", 2); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/domain-search/sendfile',
                  'query' => array (
                  ),
                  'body' => array (
                    'domain_column' => '2',
                    'api_key' => 'payload-key',
                    'file' => '<file f.csv>',
                  ),
                ),
            ),
            'domainSearchFileStatus' => array(
                function ($c, $w, $csv, $out, $D) { $c->domainSearchFileStatus("f1"); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/domain-search/filestatus',
                  'query' => array (
                    'api_key' => 'payload-key',
                    'file_id' => 'f1',
                  ),
                  'body' => NULL,
                ),
            ),
            'domainSearchGetFile' => array(
                function ($c, $w, $csv, $out, $D) { $c->domainSearchGetFile("f1", $out); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/domain-search/getfile',
                  'query' => array (
                    'api_key' => 'payload-key',
                    'file_id' => 'f1',
                  ),
                  'body' => NULL,
                ),
            ),
            'domainSearchDeleteFile' => array(
                function ($c, $w, $csv, $out, $D) { $c->domainSearchDeleteFile("f1"); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/domain-search/deletefile',
                  'query' => array (
                    'api_key' => 'payload-key',
                    'file_id' => 'f1',
                  ),
                  'body' => NULL,
                ),
            ),
            'sendListEvaluatorFile' => array(
                function ($c, $w, $csv, $out, $D) { $c->sendListEvaluatorFile($csv, 3); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/v2/listevaluator/',
                  'query' => array (
                  ),
                  'body' => array (
                    'email_address_column' => '3',
                    'api_key' => 'payload-key',
                    'file' => '<file emails.csv>',
                  ),
                ),
            ),
            'sendListEvaluatorFileStream' => array(
                function ($c, $w, $csv, $out, $D) { $c->sendListEvaluatorFileStream("e\n", "f.csv", 2); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/v2/listevaluator/',
                  'query' => array (
                  ),
                  'body' => array (
                    'email_address_column' => '2',
                    'api_key' => 'payload-key',
                    'file' => '<file f.csv>',
                  ),
                ),
            ),
            'getListEvaluatorResult' => array(
                function ($c, $w, $csv, $out, $D) { $c->getListEvaluatorResult("f1"); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/v2/listevaluator/f1/',
                  'query' => array (
                    'api_key' => 'payload-key',
                  ),
                  'body' => NULL,
                ),
            ),
            'sendEvaluatedFile' => array(
                function ($c, $w, $csv, $out, $D) { $c->sendEvaluatedFile("f1"); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/v2/send-evaluated-file/',
                  'query' => array (
                  ),
                  'body' => array (
                    'file_id' => 'f1',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'listS3Files' => array(
                function ($c, $w, $csv, $out, $D) { $c->listS3Files("bkt", "abc"); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/listS3files',
                  'query' => array (
                    'api_key' => 'payload-key',
                    'bucket' => 'bkt',
                    'search' => 'abc',
                  ),
                  'body' => NULL,
                ),
            ),
            'validateS3File' => array(
                function ($c, $w, $csv, $out, $D) { $c->validateS3File(array("file_name" => "f.csv", "return_url" => $D, "has_header_row" => true, "email_address_column" => 1, "first_name_column" => 2, "last_name_column" => 3, "gender_column" => 4, "ip_address_column" => 5, "remove_duplicate" => false, "allow_phase_2" => true)); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/validateS3file',
                  'query' => array (
                  ),
                  'body' => array (
                    'file_name' => 'f.csv',
                    'has_header_row' => 'true',
                    'remove_duplicate' => 'false',
                    'email_address_column' => '1',
                    'first_name_column' => '2',
                    'last_name_column' => '3',
                    'gender_column' => '4',
                    'ip_address_column' => '5',
                    'allow_phase_2' => 'true',
                    'return_url' => 'https://example.com/d',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'scoreS3File' => array(
                function ($c, $w, $csv, $out, $D) { $c->scoreS3File(array("file_name" => "f.csv", "return_url" => $D)); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/scoreS3file',
                  'query' => array (
                  ),
                  'body' => array (
                    'file_name' => 'f.csv',
                    'return_url' => 'https://example.com/d',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'exportS3File' => array(
                function ($c, $w, $csv, $out, $D) { $c->exportS3File("f1", "out-bucket"); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/exportS3file',
                  'query' => array (
                  ),
                  'body' => array (
                    'file_id' => 'f1',
                    'export_bucket' => 'out-bucket',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'addFilter' => array(
                function ($c, $w, $csv, $out, $D) { $c->addFilter("allow", "domain", "example.com"); },
                array (
                  'verb' => 'POST',
                  'url' => 'api.zerobounce.net/v2/filters/add',
                  'query' => array (
                  ),
                  'body' => array (
                    'rule' => 'allow',
                    'target' => 'domain',
                    'value' => 'example.com',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'listFilters' => array(
                function ($c, $w, $csv, $out, $D) { $c->listFilters(); },
                array (
                  'verb' => 'GET',
                  'url' => 'api.zerobounce.net/v2/filters/list',
                  'query' => array (
                    'api_key' => 'payload-key',
                  ),
                  'body' => NULL,
                ),
            ),
            'listFilters usePost' => array(
                function ($c, $w, $csv, $out, $D) { $c->listFilters(true); },
                array (
                  'verb' => 'POST',
                  'url' => 'api.zerobounce.net/v2/filters/list',
                  'query' => array (
                  ),
                  'body' => array (
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'deleteFilter' => array(
                function ($c, $w, $csv, $out, $D) { $c->deleteFilter("block", "email", "a@example.com"); },
                array (
                  'verb' => 'POST',
                  'url' => 'api.zerobounce.net/v2/filters/delete',
                  'query' => array (
                  ),
                  'body' => array (
                    'rule' => 'block',
                    'target' => 'email',
                    'value' => 'a@example.com',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'emailFinderS3File' => array(
                function ($c, $w, $csv, $out, $D) { $c->emailFinderS3File(array("file_name" => "f.csv", "return_url" => $D)); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/emailFinderS3file',
                  'query' => array (
                  ),
                  'body' => array (
                    'file_name' => 'f.csv',
                    'return_url' => 'https://example.com/d',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'domainSearchS3File' => array(
                function ($c, $w, $csv, $out, $D) { $c->domainSearchS3File(array("file_name" => "f.csv", "return_url" => $D)); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/domainSearchS3file',
                  'query' => array (
                  ),
                  'body' => array (
                    'file_name' => 'f.csv',
                    'return_url' => 'https://example.com/d',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'validateSFTPFile' => array(
                function ($c, $w, $csv, $out, $D) { $c->validateSFTPFile(array("file_name" => "f.csv", "return_url" => $D)); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/validateSFTPfile',
                  'query' => array (
                  ),
                  'body' => array (
                    'file_name' => 'f.csv',
                    'return_url' => 'https://example.com/d',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'scoreSFTPFile' => array(
                function ($c, $w, $csv, $out, $D) { $c->scoreSFTPFile(array("file_name" => "f.csv", "return_url" => $D)); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/scoreSFTPfile',
                  'query' => array (
                  ),
                  'body' => array (
                    'file_name' => 'f.csv',
                    'return_url' => 'https://example.com/d',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'emailFinderSFTPFile' => array(
                function ($c, $w, $csv, $out, $D) { $c->emailFinderSFTPFile(array("file_name" => "f.csv", "return_url" => $D)); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/emailFinderSFTPfile',
                  'query' => array (
                  ),
                  'body' => array (
                    'file_name' => 'f.csv',
                    'return_url' => 'https://example.com/d',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'domainSearchSFTPFile' => array(
                function ($c, $w, $csv, $out, $D) { $c->domainSearchSFTPFile(array("file_name" => "f.csv", "return_url" => $D)); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/domainSearchSFTPfile',
                  'query' => array (
                  ),
                  'body' => array (
                    'file_name' => 'f.csv',
                    'return_url' => 'https://example.com/d',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'exportSFTPFile' => array(
                function ($c, $w, $csv, $out, $D) { $c->exportSFTPFile("f1", "/out"); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/exportSFTPfile',
                  'query' => array (
                  ),
                  'body' => array (
                    'file_id' => 'f1',
                    'export_path' => '/out',
                    'api_key' => 'payload-key',
                  ),
                ),
            ),
            'getInboxPlacementProviders' => array(
                function ($c, $w, $csv, $out, $D) { $c->getInboxPlacementProviders(); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/api/ipt/providers',
                  'query' => array (
                  ),
                  'body' => NULL,
                ),
            ),
            'getInboxPlacementTestTypes' => array(
                function ($c, $w, $csv, $out, $D) { $c->getInboxPlacementTestTypes(); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/api/ipt/test-types',
                  'query' => array (
                  ),
                  'body' => NULL,
                ),
            ),
            'createInboxPlacementTest' => array(
                function ($c, $w, $csv, $out, $D) { $c->createInboxPlacementTest("Test", 1, "s@example.com", array(1, 2), array(3), array(array("id" => 1, "maxSeeds" => 2)), "https://example.com/h"); },
                array (
                  'verb' => 'POST',
                  'url' => 'bulkapi.zerobounce.net/api/ipt/test',
                  'query' => array (
                  ),
                  'body' => array (
                    'name' => 'Test',
                    'testType' => 1,
                    'senderEmail' => 's@example.com',
                    'providerIds' => array (
                      0 => 1,
                      1 => 2,
                    ),
                    'seedIds' => array (
                      0 => 3,
                    ),
                    'providers' => array (
                      0 => array (
                        'id' => 1,
                        'maxSeeds' => 2,
                      ),
                    ),
                    'webhookUrl' => 'https://example.com/h',
                  ),
                ),
            ),
            'getInboxPlacementTest' => array(
                function ($c, $w, $csv, $out, $D) { $c->getInboxPlacementTest(7); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/api/ipt/test/7',
                  'query' => array (
                  ),
                  'body' => NULL,
                ),
            ),
            'listInboxPlacementTests' => array(
                function ($c, $w, $csv, $out, $D) { $c->listInboxPlacementTests("finished", "2026-01-01", "2026-02-01", 2, 10); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/api/ipt/tests',
                  'query' => array (
                    'status' => 'finished',
                    'createdAfter' => '2026-01-01',
                    'createdBefore' => '2026-02-01',
                    'page' => '2',
                    'page_size' => '10',
                  ),
                  'body' => NULL,
                ),
            ),
            'deleteInboxPlacementTest' => array(
                function ($c, $w, $csv, $out, $D) { $c->deleteInboxPlacementTest(7); },
                array (
                  'verb' => 'DELETE',
                  'url' => 'bulkapi.zerobounce.net/api/ipt/test/7',
                  'query' => array (
                  ),
                  'body' => NULL,
                ),
            ),
            'getInboxPlacementWebhook' => array(
                function ($c, $w, $csv, $out, $D) { $c->getInboxPlacementWebhook(); },
                array (
                  'verb' => 'GET',
                  'url' => 'bulkapi.zerobounce.net/api/ipt/webhook',
                  'query' => array (
                  ),
                  'body' => NULL,
                ),
            ),
            'setInboxPlacementWebhook' => array(
                function ($c, $w, $csv, $out, $D) { $c->setInboxPlacementWebhook("https://example.com/hook"); },
                array (
                  'verb' => 'PUT',
                  'url' => 'bulkapi.zerobounce.net/api/ipt/webhook',
                  'query' => array (
                  ),
                  'body' => array (
                    'webhookUrl' => 'https://example.com/hook',
                  ),
                ),
            ),
        );
    }

    /**
     * @return array<string, array{0: callable, 1: array<string, mixed>}>
     */
    public static function warmupTable()
    {
        return array(
            'listSendingAccounts' => array(
                function ($c, $w, $csv, $out, $D) { $w->listSendingAccounts(); },
                array (
                  'verb' => 'GET',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/sending-accounts',
                  'query' => array (
                  ),
                  'body' => NULL,
                ),
            ),
            'getSendingAccount' => array(
                function ($c, $w, $csv, $out, $D) { $w->getSendingAccount("a@example.com"); },
                array (
                  'verb' => 'GET',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/sending-account',
                  'query' => array (
                    'from_address' => 'a@example.com',
                  ),
                  'body' => NULL,
                ),
            ),
            'addSendingAccount' => array(
                function ($c, $w, $csv, $out, $D) { $w->addSendingAccount("a@example.com", "self_managed", 0, true); },
                array (
                  'verb' => 'POST',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/sending-accounts',
                  'query' => array (
                  ),
                  'body' => array (
                    'from_address' => 'a@example.com',
                    'onboarding_model' => 'self_managed',
                    'engagement_rule_id' => 0,
                    'ignore_conflict' => true,
                  ),
                ),
            ),
            'addSendingAccount zb_managed' => array(
                function ($c, $w, $csv, $out, $D) { $w->addSendingAccount("a@example.com", "zb_managed", null, null, array("company_name" => "Acme", "company_website" => "https://acme.example", "company_description" => "Tools", "types_of_emails" => array("newsletter"), "example_email" => "Hi"), array("weekdays_only" => true, "timezone" => "Asia/Kolkata", "timezone_offset" => 5.5, "warmup_start_date" => "2026-11-02")); },
                array (
                  'verb' => 'POST',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/sending-accounts',
                  'query' => array (
                  ),
                  'body' => array (
                    'from_address' => 'a@example.com',
                    'onboarding_model' => 'zb_managed',
                    'email_content' => array (
                      'company_name' => 'Acme',
                      'company_website' => 'https://acme.example',
                      'company_description' => 'Tools',
                      'types_of_emails' => array (
                        0 => 'newsletter',
                      ),
                      'example_email' => 'Hi',
                    ),
                    'managed_sending' => array (
                      'weekdays_only' => true,
                      'timezone' => 'Asia/Kolkata',
                      'timezone_offset' => 5.5,
                      'warmup_start_date' => '2026-11-02',
                    ),
                  ),
                ),
            ),
            'updateSendingAccount' => array(
                function ($c, $w, $csv, $out, $D) { $w->updateSendingAccount("a@example.com", 1, 4, true); },
                array (
                  'verb' => 'PUT',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/sending-accounts',
                  'query' => array (
                  ),
                  'body' => array (
                    'team_id' => 1,
                    'from_address' => 'a@example.com',
                    'engagement_rule_id' => 1,
                    'status_id' => 4,
                    'auto_enable' => true,
                  ),
                ),
            ),
            'deleteSendingAccount' => array(
                function ($c, $w, $csv, $out, $D) { $w->deleteSendingAccount("a@example.com"); },
                array (
                  'verb' => 'DELETE',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/sending-accounts',
                  'query' => array (
                    'from_address' => 'a@example.com',
                  ),
                  'body' => NULL,
                ),
            ),
            'changeFromAddress' => array(
                function ($c, $w, $csv, $out, $D) { $w->changeFromAddress("a@example.com", "b@example.com"); },
                array (
                  'verb' => 'PUT',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/smtp-credentials',
                  'query' => array (
                  ),
                  'body' => array (
                    'team_id' => 1,
                    'old_from_address' => 'a@example.com',
                    'new_from_address' => 'b@example.com',
                  ),
                ),
            ),
            'listFromAddresses' => array(
                function ($c, $w, $csv, $out, $D) { $w->listFromAddresses("a", 5, 10); },
                array (
                  'verb' => 'GET',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/from-addresses',
                  'query' => array (
                    'search_from' => 'a',
                    'offset' => '5',
                    'limit' => '10',
                  ),
                  'body' => NULL,
                ),
            ),
            'getEngagementRules' => array(
                function ($c, $w, $csv, $out, $D) { $w->getEngagementRules(); },
                array (
                  'verb' => 'GET',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/engagement-rules',
                  'query' => array (
                  ),
                  'body' => NULL,
                ),
            ),
            'setEngagementRule' => array(
                function ($c, $w, $csv, $out, $D) { $w->setEngagementRule("a@example.com", 2); },
                array (
                  'verb' => 'PUT',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/engagement-rule',
                  'query' => array (
                    'c_from' => 'a@example.com',
                  ),
                  'body' => array (
                    'rule_id' => 2,
                  ),
                ),
            ),
            'getCustomEngagement' => array(
                function ($c, $w, $csv, $out, $D) { $w->getCustomEngagement("a@example.com"); },
                array (
                  'verb' => 'GET',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/custom-engagement/a%40example.com',
                  'query' => array (
                  ),
                  'body' => NULL,
                ),
            ),
            'createCustomEngagement' => array(
                function ($c, $w, $csv, $out, $D) { $w->createCustomEngagement("a@example.com", 10, 20, 5); },
                array (
                  'verb' => 'POST',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/custom-engagement',
                  'query' => array (
                  ),
                  'body' => array (
                    'c_from' => 'a@example.com',
                    'reply_pct' => 10,
                    'important_pct' => 20,
                    'click_pct' => 5,
                  ),
                ),
            ),
            'updateCustomEngagement' => array(
                function ($c, $w, $csv, $out, $D) { $w->updateCustomEngagement("a@example.com", 11, 21, 6); },
                array (
                  'verb' => 'PUT',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/custom-engagement',
                  'query' => array (
                  ),
                  'body' => array (
                    'c_from' => 'a@example.com',
                    'reply_pct' => 11,
                    'important_pct' => 21,
                    'click_pct' => 6,
                  ),
                ),
            ),
            'deleteCustomEngagement' => array(
                function ($c, $w, $csv, $out, $D) { $w->deleteCustomEngagement("a@example.com", 1); },
                array (
                  'verb' => 'DELETE',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/custom-engagement',
                  'query' => array (
                  ),
                  'body' => array (
                    'c_from' => 'a@example.com',
                    'engagement_rule_id' => 1,
                  ),
                ),
            ),
            'getSeeds' => array(
                function ($c, $w, $csv, $out, $D) { $w->getSeeds(); },
                array (
                  'verb' => 'GET',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/seeds',
                  'query' => array (
                  ),
                  'body' => NULL,
                ),
            ),
            'downloadSeeds' => array(
                function ($c, $w, $csv, $out, $D) { $w->downloadSeeds(); },
                array (
                  'verb' => 'GET',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/seeds/download',
                  'query' => array (
                  ),
                  'body' => NULL,
                ),
            ),
            'getSeedCount' => array(
                function ($c, $w, $csv, $out, $D) { $w->getSeedCount(); },
                array (
                  'verb' => 'GET',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/seed-count',
                  'query' => array (
                  ),
                  'body' => NULL,
                ),
            ),
            'getProgressToday' => array(
                function ($c, $w, $csv, $out, $D) { $w->getProgressToday(); },
                array (
                  'verb' => 'GET',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/progress-today',
                  'query' => array (
                  ),
                  'body' => NULL,
                ),
            ),
            'getSentToday' => array(
                function ($c, $w, $csv, $out, $D) { $w->getSentToday(); },
                array (
                  'verb' => 'GET',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/sent-today',
                  'query' => array (
                  ),
                  'body' => NULL,
                ),
            ),
            'getEmailsPerDay' => array(
                function ($c, $w, $csv, $out, $D) { $w->getEmailsPerDay(); },
                array (
                  'verb' => 'GET',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/emails-per-day',
                  'query' => array (
                  ),
                  'body' => NULL,
                ),
            ),
            'getStatistics' => array(
                function ($c, $w, $csv, $out, $D) { $w->getStatistics("a@example.com"); },
                array (
                  'verb' => 'GET',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/statistics',
                  'query' => array (
                    'from_address' => 'a@example.com',
                  ),
                  'body' => NULL,
                ),
            ),
            'getStatisticsPerDay' => array(
                function ($c, $w, $csv, $out, $D) { $w->getStatisticsPerDay("a@example.com", "2026-09-01", "2026-09-14"); },
                array (
                  'verb' => 'GET',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/statistics-per-day',
                  'query' => array (
                    'from_address' => 'a@example.com',
                    'start_date' => '2026-09-01',
                    'end_date' => '2026-09-14',
                  ),
                  'body' => NULL,
                ),
            ),
            'getStatisticsDetailed' => array(
                function ($c, $w, $csv, $out, $D) { $w->getStatisticsDetailed("a@example.com"); },
                array (
                  'verb' => 'GET',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/statistics-detailed',
                  'query' => array (
                    'c_from' => 'a@example.com',
                  ),
                  'body' => NULL,
                ),
            ),
            'downloadStatistics' => array(
                function ($c, $w, $csv, $out, $D) { $w->downloadStatistics("a@example.com"); },
                array (
                  'verb' => 'GET',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/statistics/download',
                  'query' => array (
                    'c_from' => 'a@example.com',
                  ),
                  'body' => NULL,
                ),
            ),
            'getFromAddressStatistics' => array(
                function ($c, $w, $csv, $out, $D) { $w->getFromAddressStatistics("a@example.com"); },
                array (
                  'verb' => 'GET',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/from-address-statistics',
                  'query' => array (
                    'from_address' => 'a@example.com',
                  ),
                  'body' => NULL,
                ),
            ),
            'checkDomainDmarc' => array(
                function ($c, $w, $csv, $out, $D) { $w->checkDomainDmarc("a@example.com", "sel"); },
                array (
                  'verb' => 'GET',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/check-domain-dmarc',
                  'query' => array (
                    'from_address' => 'a@example.com',
                    'dkim_selector' => 'sel',
                  ),
                  'body' => NULL,
                ),
            ),
            'testSmtpConfiguration' => array(
                function ($c, $w, $csv, $out, $D) { $w->testSmtpConfiguration("a@example.com", "user", "pass", "smtp.example.com", 587); },
                array (
                  'verb' => 'POST',
                  'url' => 'warmup-api.zerobounce.net/api/v1/warmup/test-smtp-configuration',
                  'query' => array (
                  ),
                  'body' => array (
                    'from_address' => 'a@example.com',
                    'username' => 'user',
                    'password' => 'pass',
                    'server' => 'smtp.example.com',
                    'port' => 587,
                  ),
                ),
            ),
        );
    }

    /**
     * @return array<int, array<string, mixed>> what $call sent
     */
    public static function record(callable $call)
    {
        $dir = sys_get_temp_dir() . '/zb-payloads-' . getmypid();
        @mkdir($dir);
        $csv = $dir . '/emails.csv';
        file_put_contents($csv, "email\na@example.com\n");
        $client = new PayloadRecordingClient();
        $client->initialize('payload-key');
        $warmup = new PayloadRecordingWarmup('warmup-key');
        set_error_handler(function ($level) {
            return $level === E_USER_DEPRECATED; // guessFormat is deprecated
        });
        try {
            $call($client, $warmup, $csv, $dir . '/out.csv', 'https://example.com/d');
        } catch (\Exception $e) {
            // The canned answer does not fit every call; only the request matters.
        } finally {
            restore_error_handler();
            @unlink($csv);
            @unlink($dir . '/out.csv');
            @rmdir($dir);
        }
        return array_merge($client->sent, $warmup->sent);
    }

    private static function publicMethods($class)
    {
        $names = array();
        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isStatic() && strpos($method->getName(), '__') !== 0 && $method->getName() !== 'initialize') {
                $names[] = $method->getName();
            }
        }
        return $names;
    }

    private static function covered(array $table)
    {
        return array_map(function ($name) {
            $words = explode(' ', $name);
            return $words[0];
        }, array_keys($table));
    }

    public function testTheTablesCoverEveryPublicRequestMethod()
    {
        $this->assertSame(array(), array_values(array_diff(self::publicMethods(ZeroBounce::class), self::covered(self::coreTable()))));
        $this->assertSame(array(), array_values(array_diff(self::publicMethods(ZeroBounceWarmup::class), self::covered(self::warmupTable()))));
    }

    public function testEveryClientRequest()
    {
        foreach (self::coreTable() as $name => $row) {
            $this->assertSame(array($row[1]), self::record($row[0]), $name);
        }
    }

    public function testEveryWarmupRequest()
    {
        foreach (self::warmupTable() as $name => $row) {
            $this->assertSame(array($row[1]), self::record($row[0]), 'Warmup ' . $name);
        }
    }
}

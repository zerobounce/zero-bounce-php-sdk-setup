<?php

namespace ZeroBounce\SDK;

use DateTime;
use Exception;

/**
 */
class ZeroBounce
{

    /**
     * Call this method to get singleton
     *
     * @return ZeroBounce
     */
    public static function Instance()
    {
        static $inst = null;
        if ($inst === null) {
            $inst = new ZeroBounce();
        }
        return $inst;
    }

    /**
     * Private constructor so nobody else can instantiate it
     *
     */
    protected function __construct()
    {

    }

    protected static $ApiBaseUrl;
    const BulkApiBaseUrl = "https://bulkapi.zerobounce.net/v2";
    
    private $apiKey = null;

    /**
     * @param string $apiKey
     */
    public function initialize($apiKey, $baseUrl = null)
    {
        $this->apiKey = $apiKey;
        self::$ApiBaseUrl = ZBBaseUrl::getByValue($baseUrl);
    }

    /**
     * @param string $email The email address you want to validate
     * @param string|null $ipAddress The IP Address the email signed up from (Can be blank).
     * @return ZBValidateResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBMissingParameterException
     * @throws ZBException
     */
    public function validate($email, $ipAddress = NULL)
    {
        $this->checkValidApiKey();
        if (!$email) throw new ZBMissingParameterException("email is required");
        $response = new ZBValidateResponse();
        $this->request(self::$ApiBaseUrl . "/validate?api_key=" . $this->apiKey . "&email=" . urlencode($email) . "&ip_address=" . ($ipAddress ? $ipAddress : ""), $response);
        return $response;
    }

     /**
     * @param array $emails List of email addresses or list of (email_address, ip_address) tuples to be validated.
     * @return ZBBatchValidateResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBMissingParameterException
     * @throws ZBException
     */
    public function validateBatch($emails)
    {
        $this->checkValidApiKey();
        if (!$emails or !count($emails)) throw new ZBMissingParameterException("emails parameter is required");

        $params = [];
        if (gettype($emails[0]) === 'string')
            $params = array_map(
                function ($email) {
                    return ['email_address' => $email];
                },
                $emails
            );
        elseif (gettype($emails[0]) === 'array')
            $params = array_map(
                function ($email) {
                    return [
                        'email_address' => $email[0],
                        'ip_address' => $email[1]
                    ];
                },
                $emails
            );
        else
            throw new ZBException('Unknown Parameter Type');
        $params = ['email_batch' => $params];

        $response = new ZBBatchValidateResponse();
        $code = $this->json(
            self::BulkApiBaseUrl . "/validatebatch", $params, $response);
        return $response;
    }

    /**
     * This API will tell you how many credits you have left on your account. It's simple, fast and easy to use.
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function getCredits()
    {
        $this->checkValidApiKey();
        $response = new ZBGetCreditsResponse();
        $this->request(self::$ApiBaseUrl . "/getcredits?api_key=" . $this->apiKey, $response);
        return $response;
    }

    /**
     * @param DateTime $startDate The start date of when you want to view API usage
     * @param DateTime $endDate The end date of when you want to view API usage
     * @return ZBApiUsageResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function getApiUsage($startDate, $endDate)
    {
        $this->checkValidApiKey();

        if (!$startDate) throw new ZBMissingParameterException("startDate is required");
        if (!$endDate) throw new ZBMissingParameterException("endDate is required");

        $response = new ZBApiUsageResponse();
        $format = "Y-m-d";
        $this->request(self::$ApiBaseUrl . "/getapiusage?api_key=" . $this->apiKey
            . "&start_date=" . $startDate->format($format)
            . "&end_date=" . $endDate->format($format),
            $response);
        return $response;
    }

    /**
     * @param string $email The email address to check
     * @return ZBActivityResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function getActivity($email)
    {
        $this->checkValidApiKey();

        if (!$email) throw new ZBMissingParameterException("email is required");

        $response = new ZBActivityResponse();
        $format = "Y-m-d";
        $this->request(self::$ApiBaseUrl . "/activity?api_key=" . $this->apiKey
            . "&email=" . urlencode($email),
            $response);
        return $response;
    }

    /**
     * The sendfile API allows user to send a file for bulk email validation
     * @param string $filepath
     * @param int $emailAddressColumn
     * @param string|null $returnUrl
     * @param int|null $firstNameColumn
     * @param int|null $lastNameColumn
     * @param int|null $genderColumn
     * @param int|null $ipAddressColumn
     * @param bool|null $hasHeaderRow
     * @param bool|null $allowPhase2 When not null, sends allow_phase_2 (validation bulk sendfile only).
     * @return ZBSendFileResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function sendFile(
        $filepath, $emailAddressColumn, $returnUrl = NULL, $firstNameColumn = NULL, $lastNameColumn = NULL,
        $genderColumn = NULL, $ipAddressColumn = NULL, $hasHeaderRow = NULL, $allowPhase2 = null)
    {
        return $this->_sendFile(self::BulkApiBaseUrl . "/sendfile", $filepath, $emailAddressColumn,
            $returnUrl, $firstNameColumn, $lastNameColumn,
            $genderColumn, $ipAddressColumn, $hasHeaderRow, $allowPhase2);
    }

    /**
     * The sendfile API allows user to send a file for bulk email validation
     * @param string $filepath
     * @param int $emailAddressColumn
     * @param string|null $returnUrl
     * @param bool|null $hasHeaderRow
     * @return ZBSendFileResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function scoringSendFile(
        $filepath, $emailAddressColumn, $returnUrl = NULL, $hasHeaderRow = NULL)
    {
        return $this->_sendFile(self::BulkApiBaseUrl . "/scoring/sendfile", $filepath, $emailAddressColumn,
            $returnUrl, NULL, NULL,
            NULL, NULL, $hasHeaderRow, null);
    }

    /**
     * Send a file for bulk email validation from stream content (e.g. in-memory CSV).
     * @param string $streamContent The file content (e.g. from stream_get_contents() or file_get_contents())
     * @param string $fileName The file name to use for the upload (e.g. "emails.csv")
     * @param int $emailAddressColumn
     * @param string|null $returnUrl
     * @param int|null $firstNameColumn
     * @param int|null $lastNameColumn
     * @param int|null $genderColumn
     * @param int|null $ipAddressColumn
     * @param bool|null $hasHeaderRow
     * @param bool|null $allowPhase2 When not null, sends allow_phase_2 (validation bulk sendfile only).
     * @return ZBSendFileResponse
     */
    public function sendFileStream(
        $streamContent, $fileName, $emailAddressColumn, $returnUrl = NULL, $firstNameColumn = NULL,
        $lastNameColumn = NULL, $genderColumn = NULL, $ipAddressColumn = NULL, $hasHeaderRow = NULL,
        $allowPhase2 = null)
    {
        return $this->_sendFileWithContent(
            self::BulkApiBaseUrl . "/sendfile",
            $fileName, $streamContent, $emailAddressColumn,
            $returnUrl, $firstNameColumn, $lastNameColumn, $genderColumn, $ipAddressColumn, $hasHeaderRow,
            $allowPhase2
        );
    }

    /**
     * Send a file for bulk email scoring from stream content.
     * @param string $streamContent The file content
     * @param string $fileName The file name to use for the upload (e.g. "emails.csv")
     * @param int $emailAddressColumn
     * @param string|null $returnUrl
     * @param bool|null $hasHeaderRow
     * @return ZBSendFileResponse
     */
    public function scoringSendFileStream(
        $streamContent, $fileName, $emailAddressColumn, $returnUrl = NULL, $hasHeaderRow = NULL)
    {
        return $this->_sendFileWithContent(
            self::BulkApiBaseUrl . "/scoring/sendfile",
            $fileName, $streamContent, $emailAddressColumn,
            $returnUrl, NULL, NULL, NULL, NULL, $hasHeaderRow, null
        );
    }

    /**
     * @param string $url
     * @param string $filepath
     * @param int $emailAddressColumn
     * @param string|null $returnUrl
     * @param int|null $firstNameColumn
     * @param int|null $lastNameColumn
     * @param int|null $genderColumn
     * @param int|null $ipAddressColumn
     * @param bool|null $hasHeaderRow
     * @param bool|null $allowPhase2
     * @return ZBSendFileResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    private function _sendFile(
        $url,
        $filepath, $emailAddressColumn, $returnUrl = NULL, $firstNameColumn = NULL, $lastNameColumn = NULL,
        $genderColumn = NULL, $ipAddressColumn = NULL, $hasHeaderRow = NULL, $allowPhase2 = null)
    {

        $this->checkValidApiKey();
        // data fields for POST request

        if (!$filepath) throw new ZBMissingParameterException("filePath is required");
        if (!$emailAddressColumn) throw new ZBMissingParameterException("emailAddressColumn is required");

        try {
            $fields = array(
                "api_key" => $this->apiKey,
                "email_address_column" => $emailAddressColumn
            );
            if ($returnUrl) $fields["return_url"] = $returnUrl;
            if ($firstNameColumn) $fields["first_name_column"] = $firstNameColumn;
            if ($lastNameColumn) $fields["last_name_column"] = $lastNameColumn;
            if ($genderColumn) $fields["gender_column"] = $genderColumn;
            if ($ipAddressColumn) $fields["ip_address_column"] = $ipAddressColumn;
            if ($hasHeaderRow) $fields["has_header_row"] = 'true';
            if ($allowPhase2 !== null && strpos($url, '/scoring/') === false) {
                $fields['allow_phase_2'] = $allowPhase2 ? 'true' : 'false';
            }

            $files = array();
            $files[$filepath] = file_get_contents($filepath);

            $response = $this->curl($url, $fields, $files);

            $rsp = new ZBSendFileResponse();
            $rsp->Deserialize($response);
            return $rsp;
        } catch (Exception $e) {
            throw new ZBException($e->getMessage());
        }
    }

    /**
     * @param string $url
     * @param string $fileName
     * @param string $content
     * @param int $emailAddressColumn
     * @param string|null $returnUrl
     * @param int|null $firstNameColumn
     * @param int|null $lastNameColumn
     * @param int|null $genderColumn
     * @param int|null $ipAddressColumn
     * @param bool|null $hasHeaderRow
     * @param bool|null $allowPhase2
     * @return ZBSendFileResponse
     */
    private function _sendFileWithContent(
        $url, $fileName, $content, $emailAddressColumn, $returnUrl = NULL, $firstNameColumn = NULL,
        $lastNameColumn = NULL, $genderColumn = NULL, $ipAddressColumn = NULL, $hasHeaderRow = NULL,
        $allowPhase2 = null)
    {
        $this->checkValidApiKey();
        if (!$fileName) throw new ZBMissingParameterException("fileName is required");
        if (!$emailAddressColumn) throw new ZBMissingParameterException("emailAddressColumn is required");

        try {
            $fields = array(
                "api_key" => $this->apiKey,
                "email_address_column" => $emailAddressColumn
            );
            if ($returnUrl) $fields["return_url"] = $returnUrl;
            if ($firstNameColumn) $fields["first_name_column"] = $firstNameColumn;
            if ($lastNameColumn) $fields["last_name_column"] = $lastNameColumn;
            if ($genderColumn) $fields["gender_column"] = $genderColumn;
            if ($ipAddressColumn) $fields["ip_address_column"] = $ipAddressColumn;
            if ($hasHeaderRow) $fields["has_header_row"] = 'true';
            if ($allowPhase2 !== null && strpos($url, '/scoring/') === false) {
                $fields['allow_phase_2'] = $allowPhase2 ? 'true' : 'false';
            }

            $files = array($fileName => $content);

            $response = $this->curl($url, $fields, $files);

            $rsp = new ZBSendFileResponse();
            $rsp->Deserialize($response);
            return $rsp;
        } catch (Exception $e) {
            throw new ZBException($e->getMessage());
        }
    }

    /**
     * make a JSON post
     * @param string $url
     * @param array $data
     * @return ZBResponse
     */
    protected function json($url, $data, $response)
    {
        $data['api_key'] = $this->apiKey;
        $content = json_encode($data);

        $curl = curl_init($url);
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_HEADER => false, 
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $content
        ));

        $json = curl_exec($curl);
        $err = curl_error($curl);

        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        if (!$json) {
            throw new ZBException("No response");
        }

        $response->Deserialize($json);
        return $code;
    }

    /**
     * this function is separated like this for easy mocking in the tests
     * @param string $url
     * @param array $fields
     * @param array $files
     * @return string
     */
    protected function curl($url, $fields, $files)
    {
        $curl = curl_init();

        $boundary = uniqid();
        $delimiter = '-------------' . $boundary;

        $post_data = $this->build_data_files($boundary, $fields, $files);

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            //CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => $post_data,
            CURLOPT_HTTPHEADER => array(
                //"Authorization: Bearer $TOKEN",
                "Content-Type: multipart/form-data; boundary=" . $delimiter,
                "Content-Length: " . strlen($post_data)
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        /*if ($err && count_chars($err) > 0) {
            throw new ZBApiException($err);
        }*/

        return $response;
    }

    /**
     * @param string $fileId The returned file ID when calling sendFile API
     * @return ZBFileStatusResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function fileStatus($fileId)
    {
        return $this->_fileStatus(false, $fileId);
    }

    /**
     * @param string $fileId The returned file ID when calling sendFile API
     * @return ZBFileStatusResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function scoringFileStatus($fileId)
    {
        return $this->_fileStatus(true, $fileId);
    }

    /**
     * @param boolean $scoring Calls the scoring api
     * @param string $fileId The returned file ID when calling sendFile API
     * @return ZBFileStatusResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    private function _fileStatus($scoring, $fileId)
    {
        $this->checkValidApiKey();

        if (!$fileId) throw new ZBMissingParameterException("fileId is required");

        $response = new ZBFileStatusResponse();
        $this->request(self::BulkApiBaseUrl
            . ($scoring ? "/scoring" : "")
            . "/filestatus?api_key=" . $this->apiKey
            . "&file_id=" . $fileId,
            $response);

        return $response;
    }

    /**
     * The getfile API allows users to get the validation results file for the file been submitted using sendfile API
     * @param string $fileId
     * @param string $downloadPath
     * @param ZBGetFileOptions|null $options Optional download_type and activity_data (validation only).
     * @return ZBGetFileResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function getFile($fileId, $downloadPath, $options = null)
    {
        return $this->_getFile(false, $fileId, $downloadPath, $options);
    }

    /**
     * The scoring getfile API allows users to get the validation results file for the file been submitted using sendfile API
     * @param string $fileId
     * @param string $downloadPath
     * @param ZBGetFileOptions|null $options Optional download_type; activity_data is not sent.
     * @return ZBGetFileResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function scoringGetFile($fileId, $downloadPath, $options = null)
    {
        return $this->_getFile(true, $fileId, $downloadPath, $options);
    }

    /**
     * @param bool $scoring
     * @param string $fileId
     * @param string $downloadPath
     * @param ZBGetFileOptions|null $options
     * @return ZBGetFileResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    private function _getFile($scoring, $fileId, $downloadPath, $options = null)
    {
        $this->checkValidApiKey();

        if (!$fileId) throw new ZBMissingParameterException("fileId is required");
        if (!$downloadPath) throw new ZBMissingParameterException("downloadPath is required");

        try {
            $query = array(
                'api_key' => $this->apiKey,
                'file_id' => $fileId,
            );
            if ($options instanceof ZBGetFileOptions) {
                if ($options->downloadType !== null && $options->downloadType !== '') {
                    $query['download_type'] = $options->downloadType;
                }
                if (!$scoring && $options->activityData !== null) {
                    $query['activity_data'] = $options->activityData ? 'true' : 'false';
                }
            }

            $path = ($scoring ? '/scoring' : '') . '/getfile';
            $url = self::BulkApiBaseUrl . $path . '?' . http_build_query($query);

            $result = $this->getBulkFileHttp($url);
            $body = $result['body'];
            $headers = $result['headers'];
            $code = self::parseHttpStatusFromHeaders($headers);
            $contentType = self::parseContentTypeFromHeaders($headers);

            if ($code > 299) {
                throw new ZBException(self::formatGetFileErrorMessage($body));
            }

            if (self::shouldTreatGetFileBodyAsError($body, $contentType)) {
                throw new ZBException(self::formatGetFileErrorMessage($body));
            }

            $folder = dirname($downloadPath);
            if ($folder !== '' && $folder !== '.' && !file_exists($folder)) {
                mkdir($folder, 0777, true);
            }

            if (file_put_contents($downloadPath, $body) === false) {
                throw new ZBException('Could not write file');
            }

            $response = new ZBGetFileResponse();
            $response->localFilePath = $downloadPath;
            return $response;
        } catch (Exception $e) {
            throw new ZBException($e->getMessage());
        }
    }

    /**
     * @param string $url
     * @return array{body: string, headers: string[]}
     * @throws ZBException
     */
    protected function getBulkFileHttp($url)
    {
        $context = stream_context_create(array(
            'http' => array(
                'ignore_errors' => true,
            ),
        ));
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new ZBException('No response');
        }
        $headers = array();
        if (function_exists('http_get_last_response_headers')) {
            // @see https://wiki.php.net/rfc/deprecations_php_8_5#deprecate_the_http_response_header_predefined_variable
            $http_response_header = http_get_last_response_headers();
            if (is_array($http_response_header)) {
                $headers = $http_response_header;
            }
        } elseif (isset($http_response_header) && is_array($http_response_header)) {
            $headers = $http_response_header;
        }
        return array('body' => $body, 'headers' => $headers);
    }

    /**
     * @param string[] $headers
     * @return int
     */
    protected static function parseHttpStatusFromHeaders($headers)
    {
        if (!is_array($headers) || !isset($headers[0])) {
            return 200;
        }
        if (preg_match('#HTTP/\S+\s+(\d+)#', $headers[0], $m)) {
            return (int) $m[1];
        }
        return 200;
    }

    /**
     * @param string[] $headers
     * @return string
     */
    protected static function parseContentTypeFromHeaders($headers)
    {
        if (!is_array($headers)) {
            return '';
        }
        foreach ($headers as $h) {
            if (stripos($h, 'Content-Type:') === 0) {
                $v = trim(substr($h, strlen('Content-Type:')));
                $semi = strpos($v, ';');
                if ($semi !== false) {
                    $v = trim(substr($v, 0, $semi));
                }
                return $v;
            }
        }
        return '';
    }

    /**
     * Whether a getfile response body looks like a JSON error payload (including HTTP 200).
     *
     * @param string $body
     * @return bool
     */
    public static function getFileJsonIndicatesError($body)
    {
        $t = ltrim($body);
        if ($t === '' || $t[0] !== '{') {
            return false;
        }
        $o = json_decode($body, true);
        if (!is_array($o)) {
            return false;
        }
        if (array_key_exists('success', $o)) {
            $s = $o['success'];
            if ($s === false || $s === 'False' || $s === 'false') {
                return true;
            }
        }
        foreach (array('message', 'error', 'error_message') as $k) {
            if (!empty($o[$k])) {
                if (is_string($o[$k]) && trim($o[$k]) !== '') {
                    return true;
                }
                if (is_array($o[$k]) && count($o[$k]) > 0) {
                    return true;
                }
            }
        }
        return array_key_exists('success', $o);
    }

    /**
     * @param string $body
     * @param string $contentType
     * @return bool
     */
    protected static function shouldTreatGetFileBodyAsError($body, $contentType)
    {
        $ct = strtolower($contentType);
        if (strpos($ct, 'application/json') !== false) {
            return true;
        }
        return self::getFileJsonIndicatesError($body);
    }

    /**
     * @param string $body
     * @return string
     */
    protected static function formatGetFileErrorMessage($body)
    {
        $o = json_decode($body, true);
        if (!is_array($o)) {
            return $body !== '' ? $body : 'Invalid getfile response';
        }
        foreach (array('message', 'error', 'error_message') as $k) {
            if (empty($o[$k])) {
                continue;
            }
            if (is_string($o[$k]) && trim($o[$k]) !== '') {
                return trim($o[$k]);
            }
            if (is_array($o[$k]) && isset($o[$k][0]) && is_string($o[$k][0])) {
                return trim($o[$k][0]);
            }
        }
        return $body;
    }


    /**
     * @param string $fileId The returned file ID when calling sendfile API.
     * @return ZBDeleteFileResponse
     * @throws ZBException
     * @throws ZBMissingApiKeyException
     */
    public function scoringDeleteFile($fileId)
    {
        return $this->_deleteFile(true, $fileId);
    }

    /**
     * @param string $fileId The returned file ID when calling sendfile API.
     * @return ZBDeleteFileResponse
     * @throws ZBException
     * @throws ZBMissingApiKeyException
     */
    public function deleteFile($fileId)
    {
        return $this->_deleteFile(false, $fileId);
    }

    /**
     * @param bool $scoring use the scoring API
     * @param string $fileId The returned file ID when calling sendfile API.
     * @return ZBDeleteFileResponse
     * @throws ZBException
     * @throws ZBMissingApiKeyException
     */
    public function _deleteFile($scoring, $fileId)
    {
        $this->checkValidApiKey();

        if (!$fileId) throw new ZBMissingParameterException("fileId is required");

        $response = new ZBDeleteFileResponse();
        $this->request(self::BulkApiBaseUrl
            . ($scoring ? "/scoring" : "")
            . "/deletefile?api_key=" . $this->apiKey
            . "&file_id=" . $fileId,
            $response);

        return $response;
    }

    /**
     * @param string $url
     * @param ZBResponse $response
     * @return int http statusCode
     * @throws ZBException
     */
    protected function request($url, $response)
    {
        try {
            $context = stream_context_create(array(
                'http' => array(
                    'ignore_errors' => true
                )
            ));

            $json = @file_get_contents($url, false, $context);
          
            if (!$json) {
                throw new ZBException("No response");
            }

            if (function_exists('http_get_last_response_headers')) {
                // @see https://wiki.php.net/rfc/deprecations_php_8_5#deprecate_the_http_response_header_predefined_variable
                $http_response_header = http_get_last_response_headers();
            }
            $code = $this->getHttpCode($http_response_header);
            $response->Deserialize($json);
            return $code;
        } catch (Exception $e) {
            throw new ZBException($e->getMessage());
        }
    }

    /**
     * @throws ZBMissingApiKeyException
     */
    private function checkValidApiKey()
    {
        if (!$this->apiKey) throw new ZBMissingApiKeyException("ZeroBounce SDK is not initialized. Please call ZeroBounce::Instance()->initialize(\"API_KEY\") first");
    }

    private function getHttpCode($httpResponseHeader)
    {
        if (is_array($httpResponseHeader)) {
            $parts = explode(' ', $httpResponseHeader[0]);
            if (count($parts) > 1) //HTTP/1.0 <code> <text>
                return intval($parts[1]); //Get code
        }
        return 0;
    }

    private function build_data_files($boundary, $fields, $files)
    {
        $data = '';
        $eol = "\r\n";

        $delimiter = '-------------' . $boundary;

        foreach ($fields as $name => $content) {
            $data .= "--" . $delimiter . $eol
                . 'Content-Disposition: form-data; name="' . $name . "\"" . $eol . $eol
                . $content . $eol;
        }


        foreach ($files as $path => $content) {
            $name = basename($path);
            $data .= "--" . $delimiter . $eol
                . 'Content-Disposition: form-data; name="file"; filename="' . $name . '"' . $eol
                //. 'Content-Type: image/png'.$eol
                . 'Content-Transfer-Encoding: binary' . $eol;

            $data .= $eol;
            $data .= $content . $eol;
        }
        $data .= "--" . $delimiter . "--" . $eol;


        return $data;
    }

    /**
     * @deprecated 
     * @param string $domain The domain to check
     * @return ZBGuessFormatResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function guessFormat($domain, $firstName, $middleName, $lastName)
    {
        trigger_error(
            'Method ' . __METHOD__ . ' is deprecated. Use findEmail or findEmailFormat instead.',
            E_USER_DEPRECATED
        );

        $this->checkValidApiKey();

        if (!$domain) throw new ZBMissingParameterException("domain is required");

        $response = new ZBGuessFormatResponse();
        $args = [
            "api_key" => $this->apiKey,
            "domain" => $domain
        ];
        if ($firstName) $args['first_name'] = $firstName;
        if ($middleName) $args['middle_name'] = $middleName;
        if ($lastName) $args['last_name'] = $lastName;

        $query = http_build_query($args);

        $this->request(self::$ApiBaseUrl . "/guessformat?$query", $response);
        return $response;
    }

    /**
     * Search for new business email addresses
     * 
     * @param string|null $domain
     * @param string $firstName
     * @param string|null $companyName
     * @param string|null $middleName
     * @param string|null $lastName
     * @return ZBFindEmailResponse
     * @throws ZBException
     * @throws ZBMissingApiKeyException
     * @throws ZBMissingParameterException
     */
    public function findEmail($domain, $firstName, $companyName = null, $middleName = null, $lastName = null)
    {
        $this->checkValidApiKey();

        if (!$domain && !$companyName) {
            throw new ZBMissingParameterException('domain or companyName is required');
        }

        if (!$firstName) {
            throw new ZBMissingParameterException('firstName is required');
        }

        $response = new ZBFindEmailResponse();
        $args = [
            'api_key' => $this->apiKey,
            'first_name' => $firstName,
        ];

        // The API doesn't accept both
        if ($domain) {
            $args['domain'] = $domain;
        } else {
            $args['company_name'] = $companyName;
        }

        if ($middleName) $args['middle_name'] = $middleName;
        if ($lastName) $args['last_name'] = $lastName;

        $query = http_build_query($args);

        $this->request(self::$ApiBaseUrl . "/guessformat?$query", $response);
        return $response;
    }

    /**
     * Detect possible email patterns a specific company uses
     * 
     * @param string $domain
     * @param string|null $companyName
     * @return ZBFindEmailFormatResponse
     * @throws ZBException
     * @throws ZBMissingApiKeyException
     * @throws ZBMissingParameterException
     */
    public function findEmailFormat($domain, $companyName = null)
    {
        $this->checkValidApiKey();

        if (!$domain && !$companyName) {
            throw new ZBMissingParameterException('domain or companyName is required');
        }

        $response = new ZBFindEmailFormatResponse();
        $args = [
            'api_key' => $this->apiKey,
        ];

        // The API doesn't accept both
        if ($domain) {
            $args['domain'] = $domain;
        } else {
            $args['company_name'] = $companyName;
        }

        $query = http_build_query($args);

        $this->request(self::$ApiBaseUrl . "/guessformat?$query", $response);
        return $response;
    }
}

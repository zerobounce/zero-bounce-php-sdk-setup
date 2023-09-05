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

    const ApiBaseUrl = "https://api.zerobounce.net/v2";
    const BulkApiBaseUrl = "https://bulkapi.zerobounce.net/v2";
    
    private $apiKey = null;

    /**
     * @param string $apiKey
     */
    public function initialize($apiKey)
    {
        $this->apiKey = $apiKey;
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
        $this->request(self::ApiBaseUrl . "/validate?api_key=" . $this->apiKey . "&email=" . urlencode($email) . "&ip_address=" . ($ipAddress ? $ipAddress : ""), $response);
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
        if (gettype($emails[0])=='string')
            $params = array_map(
                fn($email) => ['email_address' => $email], 
                $emails
            );
        else if (gettype($emails[0])=='array')
            $params = array_map(
                fn($email) => [
                    'email_address' => $email[0],
                    'ip_address' => $email[1]
                ],
                $emails
            );
        else
            throw ZBException('Unknown Parameter Type');
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
        $this->request(self::ApiBaseUrl . "/getcredits?api_key=" . $this->apiKey, $response);
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
        $this->request(self::ApiBaseUrl . "/getapiusage?api_key=" . $this->apiKey
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
        $this->request(self::ApiBaseUrl . "/activity?api_key=" . $this->apiKey
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
     * @return ZBSendFileResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function sendFile(
        $filepath, $emailAddressColumn, $returnUrl = NULL, $firstNameColumn = NULL, $lastNameColumn = NULL,
        $genderColumn = NULL, $ipAddressColumn = NULL, $hasHeaderRow = NULL)
    {
        return $this->_sendFile(self::BulkApiBaseUrl . "/sendfile", $filepath, $emailAddressColumn,
            $returnUrl, $firstNameColumn, $lastNameColumn,
            $genderColumn, $ipAddressColumn, $hasHeaderRow);
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
            NULL, NULL, $hasHeaderRow);
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
     * @return ZBSendFileResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    private function _sendFile(
        $url,
        $filepath, $emailAddressColumn, $returnUrl = NULL, $firstNameColumn = NULL, $lastNameColumn = NULL,
        $genderColumn = NULL, $ipAddressColumn = NULL, $hasHeaderRow = NULL)
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
     * @return ZBGetFileResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function getFile($fileId, $downloadPath)
    {
        return $this->_getFile(false, $fileId, $downloadPath);
    }

    /**
     * The scoring getfile API allows users to get the validation results file for the file been submitted using sendfile API
     * @param string $fileId
     * @param string $downloadPath
     * @return ZBGetFileResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function scoringGetFile($fileId, $downloadPath)
    {
        return $this->_getFile(true, $fileId, $downloadPath);
    }

    /**
     * @param bool $scoring
     * @param string $fileId
     * @param string $downloadPath
     * @return ZBGetFileResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    private function _getFile($scoring, $fileId, $downloadPath)
    {
        $this->checkValidApiKey();

        if (!$fileId) throw new ZBMissingParameterException("fileId is required");
        if (!$downloadPath) throw new ZBMissingParameterException("downloadPath is required");

        try {
            $folder = dirname($downloadPath);
            if (!file_exists($folder)) {
                mkdir($folder, 0777, true);
            }

            $content = @file_put_contents($downloadPath,
                $this->downloadFile(self::BulkApiBaseUrl
                    . ($scoring ? "/scoring" : "")
                    . "/getfile?api_key=" . $this->apiKey
                    . "&file_id=" . $fileId));

            if ($content === FALSE) {
                throw new ZBException("Invalid request");
            }

            $response = new ZBGetFileResponse();
            $response->localFilePath = $downloadPath;
            return $response;
        } catch (Exception $e) {
            throw new ZBException($e->getMessage());
        }
    }

    /**
     * this function is separated like this for easy mocking in the tests
     * @param string $url
     * @return string
     */
    protected function downloadFile($url)
    {
        return fopen($url, 'r');
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

    private function getHttpCode($http_response_header)
    {
        if (is_array($http_response_header)) {
            $parts = explode(' ', $http_response_header[0]);
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
     * @param string $email The email address to check
     * @return ZBActivityResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function guessFormat($domain, $firstName, $middleName, $lastName)
    {
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

        $this->request(self::ApiBaseUrl . "/guessformat?$query", $response);
        return $response;
    }
}


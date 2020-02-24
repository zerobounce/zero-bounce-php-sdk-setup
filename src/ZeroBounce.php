<?php

namespace ZeroBounce\SDK;

require_once __DIR__ . '/../vendor/autoload.php';

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
    private function __construct()
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
        $this->request(self::ApiBaseUrl . "/validate?api_key=" . $this->apiKey . "&email=" . $email . "&ip_address=" . ($ipAddress ? $ipAddress : ""), $response);
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
        return $this->_sendFile(self::BulkApiBaseUrl . "/sendFile", $filepath, $emailAddressColumn,
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
        return $this->_sendFile(self::BulkApiBaseUrl . "/scoring/sendFile", $filepath, $emailAddressColumn,
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

// curl
            $curl = curl_init();

            //$url_data = http_build_query($fields);

            $boundary = uniqid();
            $delimiter = '-------------' . $boundary;

            $post_data = $this->build_data_files($boundary, $fields, $files);
            //echo "postData: ".$post_data;

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
            //$info = curl_getinfo($curl);
            //var_dump($response);
            $err = curl_error($curl);

            //echo "error";
            //var_dump($err);

            curl_close($curl);

            /*if ($err && count_chars($err) > 0) {
                throw new ZBApiException($err);
            }*/

            $rsp = new ZBSendFileResponse();
            $rsp->Deserialize($response);
            return $rsp;
        } catch (Exception $e) {
            throw new ZBException($e->getMessage());
        }
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
    public function _getFile($scoring, $fileId, $downloadPath)
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
                fopen(self::BulkApiBaseUrl
                    . ($scoring ? "/scoring" : "")
                    . "/getFile?api_key=" . $this->apiKey
                    . "&file_id=" . $fileId, 'r'));

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
    private function request($url, $response)
    {
        //echo "sendRequest " . $url . "\n";
        try {
            $context = stream_context_create(array(
                'http' => array(
                    'ignore_errors' => true
                )
            ));

            $json = @file_get_contents($url, false, $context);
            $code = $this->getHttpCode($http_response_header);
            //echo "response code: " . $code . "\n";
            var_dump($json);

            if (!$json) {
                throw new ZBException("No response");
            }

            $response->Deserialize($json);
            //print "response: ";
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
        if (!$this->apiKey) throw new ZBMissingApiKeyException("ZeroBounce SDK is not initialized. Please call ZeroBounceSDK.initialize(context, apiKey) first");
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
                . 'Content-Disposition: form-data; name="' . $path . '"; filename="' . $name . '"' . $eol
                //. 'Content-Type: image/png'.$eol
                . 'Content-Transfer-Encoding: binary' . $eol;

            $data .= $eol;
            $data .= $content . $eol;
        }
        $data .= "--" . $delimiter . "--" . $eol;


        return $data;
    }
}

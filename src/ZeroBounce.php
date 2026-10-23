<?php

namespace ZeroBounce\SDK;

use DateTime;
use Exception;

/**
 */
class ZeroBounce
{
    use ZeroBouncePriority3;
    use ZeroBounceInboxPlacement;

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
     * A new client. Use one instance per API key. Instance() is process-wide.
     *
     * @return static
     */
    public static function create()
    {
        return new static();
    }

    /**
     * Private constructor so nobody else can instantiate it
     *
     */
    protected function __construct()
    {
        $this->apiBaseUrl = ZBBaseUrl::API_DEFAULT_URL;
    }

    /** @var string */
    protected $apiBaseUrl;
    const BulkApiBaseUrl = "https://bulkapi.zerobounce.net/v2";

    /** The list evaluator and the Inbox Placement Tester are not under the bulk /v2 path. */
    const ListEvaluatorBaseUrl = "https://bulkapi.zerobounce.net/v2/listevaluator";
    const InboxPlacementBaseUrl = "https://bulkapi.zerobounce.net/api/ipt";

    /** @var string Overridable per instance; defaults to ListEvaluatorBaseUrl. */
    protected $listEvaluatorBaseUrl = self::ListEvaluatorBaseUrl;

    /** @var string Overridable per instance; defaults to InboxPlacementBaseUrl. */
    protected $inboxPlacementBaseUrl = self::InboxPlacementBaseUrl;
    
    private $apiKey = null;

    /**
     * @param string $apiKey
     */
    public function initialize(
        $apiKey,
        $baseUrl = null,
        $listEvaluatorBaseUrl = null,
        $inboxPlacementBaseUrl = null
    ) {
        $this->apiKey = $apiKey;
        $this->apiBaseUrl = ZBBaseUrl::getByValue($baseUrl);
        if ($listEvaluatorBaseUrl !== null && $listEvaluatorBaseUrl !== '') {
            $this->listEvaluatorBaseUrl = rtrim($listEvaluatorBaseUrl, '/');
        }
        if ($inboxPlacementBaseUrl !== null && $inboxPlacementBaseUrl !== '') {
            $this->inboxPlacementBaseUrl = rtrim($inboxPlacementBaseUrl, '/');
        }
    }

    /**
     * @param string $email The email address you want to validate
     * @param string|null $ipAddress The IP Address the email signed up from (Can be blank).
     * @return ZBValidateResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBMissingParameterException
     * @throws ZBException
     */
    public function validate($email, $ipAddress = NULL, $options = NULL)
    {
        $this->checkValidApiKey();
        if (!$email) throw new ZBMissingParameterException("email is required");
        if ($options instanceof ZBValidateOptions) {
            $this->checkTimeout($options->timeout, false);
            // callbackUrl only means anything while greylist processing is on, so
            // reject it here rather than letting the API take the request.
            if ($options->callbackUrl !== null && $options->greylistProcessing !== true) {
                throw new ZBMissingParameterException(
                    "callbackUrl requires greylistProcessing = true"
                );
            }
            if ($options->callbackUrl !== null && trim((string)$options->callbackUrl) === '') {
                throw new ZBMissingParameterException("callbackUrl is required");
            }
        }
        $response = new ZBValidateResponse();
        $ipValue = ($ipAddress === null) ? '' : (string)$ipAddress;
        $fields = array(
            'email' => $email,
            'ip_address' => $ipValue,
        );
        if ($options instanceof ZBValidateOptions) {
            if ($options->timeout !== null) {
                $fields['timeout'] = (string)$options->timeout;
            }
            if ($options->activityData !== null) {
                $fields['activity_data'] = $options->activityData ? "true" : "false";
            }
            if ($options->verifyPlus !== null) {
                $fields['verify_plus'] = $options->verifyPlus ? "true" : "false";
            }
            if ($options->domainInfo !== null) {
                $fields['domain_info'] = $options->domainInfo ? "true" : "false";
            }
            if ($options->greylistProcessing !== null) {
                $fields['greylist_processing'] = $options->greylistProcessing ? "true" : "false";
            }
            if ($options->callbackUrl !== null) {
                $fields['callback_url'] = $options->callbackUrl;
            }
            if ($options->usePost) {
                $this->formPost($this->apiBaseUrl . "/validate", $fields, $response);
                return $response;
            }
        }
        $url = $this->apiBaseUrl . "/validate?api_key=" . urlencode($this->apiKey)
            . "&email=" . urlencode($email) . "&ip_address=" . urlencode($ipValue);
        if ($options instanceof ZBValidateOptions) {
            if ($options->timeout !== null) {
                $url .= "&timeout=" . urlencode((string)$options->timeout);
            }
            if ($options->activityData !== null) {
                $url .= "&activity_data=" . ($options->activityData ? "true" : "false");
            }
            if ($options->verifyPlus !== null) {
                $url .= "&verify_plus=" . ($options->verifyPlus ? "true" : "false");
            }
            if ($options->domainInfo !== null) {
                $url .= "&domain_info=" . ($options->domainInfo ? "true" : "false");
            }
            if ($options->greylistProcessing !== null) {
                $url .= "&greylist_processing=" . ($options->greylistProcessing ? "true" : "false");
            }
            if ($options->callbackUrl !== null) {
                $url .= "&callback_url=" . urlencode($options->callbackUrl);
            }
        }
        $this->request($url, $response);
        return $response;
    }

     /**
     * @param array $emails List of email addresses or list of (email_address, ip_address) tuples to be validated.
     * @return ZBBatchValidateResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBMissingParameterException
     * @throws ZBException
     */
    public function validateBatch($emails, $options = NULL)
    {
        $this->checkValidApiKey();
        if (!$emails or !count($emails)) throw new ZBMissingParameterException("emails parameter is required");
        if ($options instanceof ZBValidateOptions) {
            $this->checkTimeout($options->timeout, true);
        }

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
        if ($options instanceof ZBValidateOptions) {
            if ($options->timeout !== null) {
                $params['timeout'] = $options->timeout;
            }
            if ($options->activityData !== null) {
                $params['activity_data'] = $options->activityData;
            }
            if ($options->verifyPlus !== null) {
                $params['verify_plus'] = $options->verifyPlus;
            }
        }

        $response = new ZBBatchValidateResponse();
        $this->json(
            $this->apiBaseUrl . "/validatebatch", $params, $response);
        $this->throwIfBatchAccountError($response);
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
        $this->request($this->apiBaseUrl . "/getcredits?api_key=" . urlencode($this->apiKey), $response);
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
        $this->request($this->apiBaseUrl . "/getapiusage?api_key=" . urlencode($this->apiKey)
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
        $this->request($this->apiBaseUrl . "/activity?api_key=" . urlencode($this->apiKey)
            . "&email=" . urlencode($email),
            $response);
        return $response;
    }

    /**
     * Real-time AI scoring for a single email (GET /scoring).
     * @param string $email
     * @return ZBScoreEmailResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function scoreEmail($email)
    {
        $this->checkValidApiKey();
        if (!$email) throw new ZBMissingParameterException("email is required");

        $response = new ZBScoreEmailResponse();
        $this->request($this->apiBaseUrl . "/scoring?api_key=" . urlencode($this->apiKey)
            . "&email=" . urlencode($email),
            $response);
        return $response;
    }

    /**
     * Real-time AI scoring for a single email (POST /scoring, form-encoded).
     * @param string $email
     * @return ZBScoreEmailResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function scoreEmailPost($email)
    {
        $this->checkValidApiKey();
        if (!$email) throw new ZBMissingParameterException("email is required");

        $response = new ZBScoreEmailResponse();
        $this->formPost($this->apiBaseUrl . "/scoring", array('email' => $email), $response);
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
        $this->requireProvided($filepath, "filePath is required");
        $this->requireProvided($emailAddressColumn, "emailAddressColumn is required");

        try {
            $fields = $this->multipartFileFields(
                $emailAddressColumn, $returnUrl, $firstNameColumn, $lastNameColumn,
                $genderColumn, $ipAddressColumn, $hasHeaderRow, $allowPhase2, $url
            );

            $files = array();
            $files[$filepath] = file_get_contents($filepath);

            $response = $this->curl($url, $fields, $files);

            $rsp = new ZBSendFileResponse();
            $rsp->Deserialize($response);
            return $rsp;
        } catch (Exception $e) {
            throw new ZBException($this->redactApiKey($e->getMessage()));
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
        $this->requireProvided($fileName, "fileName is required");
        $this->requireProvided($emailAddressColumn, "emailAddressColumn is required");

        try {
            $fields = $this->multipartFileFields(
                $emailAddressColumn, $returnUrl, $firstNameColumn, $lastNameColumn,
                $genderColumn, $ipAddressColumn, $hasHeaderRow, $allowPhase2, $url
            );

            $files = array($fileName => $content);

            $response = $this->curl($url, $fields, $files);

            $rsp = new ZBSendFileResponse();
            $rsp->Deserialize($response);
            return $rsp;
        } catch (Exception $e) {
            throw new ZBException($this->redactApiKey($e->getMessage()));
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
        $result = $this->executeHttp(
            'POST',
            $url,
            array('Content-Type: application/json'),
            $content,
            120
        );

        $json = $this->readJsonBody($result);
        $response->Deserialize($json);
        return (int)$result['status'];
    }

    /**
     * @param string $url
     * @param array $data
     * @param ZBResponse $response
     * @return int http statusCode
     * @throws ZBException
     */
    protected function formPost($url, $data, $response)
    {
        $data['api_key'] = $this->apiKey;
        $content = http_build_query($data);
        $result = $this->executeHttp(
            'POST',
            $url,
            array('Content-Type: application/x-www-form-urlencoded'),
            $content,
            120
        );

        $json = $this->readJsonBody($result);
        $response->Deserialize($json);
        return (int)$result['status'];
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
        $boundary = bin2hex(random_bytes(16));
        $delimiter = '-------------' . $boundary;
        $post_data = $this->build_data_files($boundary, $fields, $files);
        $result = $this->executeHttp(
            'POST',
            $url,
            array(
                "Content-Type: multipart/form-data; boundary=" . $delimiter,
                "Content-Length: " . strlen($post_data),
            ),
            $post_data,
            120
        );

        return $this->readJsonBody($result);
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

        $this->requireProvided($fileId, "fileId is required");

        $response = new ZBFileStatusResponse();
        $this->request(self::BulkApiBaseUrl
            . ($scoring ? "/scoring" : "")
            . "/filestatus?api_key=" . urlencode($this->apiKey)
            . "&file_id=" . urlencode($fileId),
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

        $this->requireProvided($fileId, "fileId is required");
        $this->requireProvided($downloadPath, "downloadPath is required");

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

            return $this->saveBulkDownload($this->getBulkFileHttp($url), $downloadPath);
        } catch (Exception $e) {
            throw new ZBException($this->redactApiKey($e->getMessage()));
        }
    }


    /**
     * Masks the API key in text that can end up in exception messages or logs.
     */
    private function redactApiKey($message)
    {
        $message = (string) $message;
        if (!$this->apiKey) {
            return $message;
        }
        return str_replace(array($this->apiKey, urlencode($this->apiKey), rawurlencode($this->apiKey)), '***', $message);
    }

    /**
     * @param string $url
     * @return array{body: string, headers: string[]}
     * @throws ZBException
     */
    protected function getBulkFileHttp($url)
    {
        $result = $this->executeHttp('GET', $url, array(), null, 120);
        if ($result['body'] === '' && (int)$result['status'] === 0) {
            throw new ZBException('No response');
        }
        return array('body' => $result['body'], 'headers' => $result['headers']);
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
        foreach (array('message', 'error', 'error_message', 'detail') as $k) {
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
            return ZBHttpError::message(0, $body);
        }
        foreach (array('message', 'error', 'error_message', 'detail') as $k) {
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
        return ZBHttpError::message(0, $body);
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

        $this->requireProvided($fileId, "fileId is required");

        $response = new ZBDeleteFileResponse();
        $this->request(self::BulkApiBaseUrl
            . ($scoring ? "/scoring" : "")
            . "/deletefile?api_key=" . urlencode($this->apiKey)
            . "&file_id=" . urlencode($fileId),
            $response);

        return $response;
    }

    /**
     * Lowest HTTP call. Tests override this and never touch the network.
     *
     * @param string $method
     * @param string $url
     * @param string[] $headers
     * @param string|null $body
     * @param int $timeout
     * @return array{status: int, headers: string[], body: string}
     * @throws ZBException
     */
    protected function executeHttp($method, $url, array $headers = array(), $body = null, $timeout = 120)
    {
        $method = strtoupper($method);
        if ($method === 'GET' && $body === null) {
            $contextOptions = array(
                'method' => 'GET',
                'ignore_errors' => true,
                'timeout' => $timeout,
                'follow_location' => 0,
                'max_redirects' => 0,
            );
            if ($headers) {
                $contextOptions['header'] = implode("\r\n", $headers);
            }
            $context = stream_context_create(array('http' => $contextOptions));
            $responseBody = @file_get_contents($url, false, $context);
            if ($responseBody === false) {
                $responseBody = '';
            }
            $responseHeaders = $this->lastHttpHeaders();
            return array(
                'status' => (int)$this->getHttpCode($responseHeaders),
                'headers' => is_array($responseHeaders) ? $responseHeaders : array(),
                'body' => $responseBody,
            );
        }

        $curl = curl_init();
        $curlOpts = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CUSTOMREQUEST => $method,
        );
        if ($headers) {
            $curlOpts[CURLOPT_HTTPHEADER] = $headers;
        }
        if ($body !== null) {
            $curlOpts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($curl, $curlOpts);
        $raw = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        curl_close($curl);
        if ($raw === false || $raw === null) {
            return array('status' => $status, 'headers' => array(), 'body' => '');
        }
        $rawHeaders = substr($raw, 0, $headerSize);
        $responseBody = substr($raw, $headerSize);
        $headerLines = preg_split("/\r\n|\n|\r/", trim((string)$rawHeaders));
        if (!is_array($headerLines)) {
            $headerLines = array();
        }
        return array(
            'status' => $status,
            'headers' => $headerLines,
            'body' => $responseBody === false ? '' : $responseBody,
        );
    }

    /**
     * @return string[]
     */
    private function lastHttpHeaders()
    {
        if (function_exists('http_get_last_response_headers')) {
            // @see https://wiki.php.net/rfc/deprecations_php_8_5#deprecate_the_http_response_header_predefined_variable
            $fromFn = http_get_last_response_headers();
            if (is_array($fromFn)) {
                return $fromFn;
            }
            return array();
        }
        if (\PHP_VERSION_ID < 80500) {
            $headers = require __DIR__ . '/ZBLegacyHttpResponseHeaders.php';
            return is_array($headers) ? $headers : array();
        }
        return array();
    }

    protected function request($url, $response)
    {
        try {
            $result = $this->executeHttp('GET', $url, array(), null, 120);
            $json = $this->readJsonBody($result);
            $response->Deserialize($json);
            return (int)$result['status'];
        } catch (Exception $e) {
            throw new ZBException($this->redactApiKey($e->getMessage()));
        }
    }

    /**
     * @param mixed $value
     * @return bool
     */
    protected function isProvided($value)
    {
        return $value !== null && $value !== '';
    }

    /**
     * @param mixed $value
     * @param string $message
     * @throws ZBMissingParameterException
     */
    protected function requireProvided($value, $message)
    {
        if (!$this->isProvided($value)) {
            throw new ZBMissingParameterException($message);
        }
    }

    /**
     * @param int|string|null $timeout
     * @param bool $batch
     * @throws ZBException
     */
    protected function checkTimeout($timeout, $batch)
    {
        if ($timeout === null || $timeout === '') {
            return;
        }
        $message = $batch
            ? 'Invalid parameter: timeout (must be between 10 and 120 seconds)'
            : 'Invalid parameter: timeout (must be between 3 and 60 seconds)';
        if (!is_numeric($timeout)) {
            throw new ZBException($message);
        }
        $timeout = (int)$timeout;
        if ($batch) {
            if ($timeout < 10 || $timeout > 120) {
                throw new ZBException($message);
            }
            return;
        }
        if ($timeout < 3 || $timeout > 60) {
            throw new ZBException($message);
        }
    }

    /**
     * @param array{status?: int, headers?: string[], body?: string} $result
     * @return string
     * @throws ZBException
     */
    private function readJsonBody(array $result)
    {
        $status = isset($result['status']) ? (int)$result['status'] : 0;
        $body = isset($result['body']) ? (string)$result['body'] : '';
        $headers = (isset($result['headers']) && is_array($result['headers'])) ? $result['headers'] : array();
        if (trim($body) === '' || $status > 299 || ZBHttpError::isHtml($body, $headers)) {
            throw new ZBException(ZBHttpError::message($status, $body, $headers));
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new ZBException('ZeroBounce returned a response that is not valid JSON.');
        }
        ZBHttpError::throwIfBareAccountError($decoded);
        return $body;
    }

    /**
     * @param ZBBatchValidateResponse $response
     * @throws ZBException
     */
    private function throwIfBatchAccountError(ZBBatchValidateResponse $response)
    {
        if (!is_array($response->errors)) {
            return;
        }
        foreach ($response->errors as $item) {
            if (!is_array($item)) {
                continue;
            }
            $address = isset($item['email_address']) ? (string)$item['email_address'] : '';
            if (strcasecmp($address, 'all') !== 0) {
                continue;
            }
            $message = '';
            if (isset($item['error']) && is_string($item['error'])) {
                $message = ZBHttpError::stripPrefix($item['error']);
            }
            if ($message === '') {
                $message = 'Invalid API Key or your account ran out of credits';
            }
            throw new ZBException($message);
        }
    }

    /**
     * @param array{body?: string, headers?: string[]} $result
     * @param string $downloadPath
     * @return ZBGetFileResponse
     * @throws ZBException
     */
    private function saveBulkDownload(array $result, $downloadPath)
    {
        $body = isset($result['body']) ? (string)$result['body'] : '';
        $headers = (isset($result['headers']) && is_array($result['headers'])) ? $result['headers'] : array();
        $code = self::parseHttpStatusFromHeaders($headers);
        $contentType = self::parseContentTypeFromHeaders($headers);
        if (trim($body) === '' || $code > 299 || ZBHttpError::isHtml($body, $headers) || self::shouldTreatGetFileBodyAsError($body, $contentType)) {
            throw new ZBException(ZBHttpError::message($code, $body, $headers));
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
    }

    /**
     * @return array<string, mixed>
     */
    private function multipartFileFields(
        $emailAddressColumn, $returnUrl, $firstNameColumn, $lastNameColumn,
        $genderColumn, $ipAddressColumn, $hasHeaderRow, $allowPhase2, $url)
    {
        $fields = array(
            'api_key' => $this->apiKey,
            'email_address_column' => $emailAddressColumn,
        );
        if ($returnUrl) {
            $fields['return_url'] = $returnUrl;
        }
        if ($this->isProvided($firstNameColumn)) {
            $fields['first_name_column'] = $firstNameColumn;
        }
        if ($this->isProvided($lastNameColumn)) {
            $fields['last_name_column'] = $lastNameColumn;
        }
        if ($this->isProvided($genderColumn)) {
            $fields['gender_column'] = $genderColumn;
        }
        if ($this->isProvided($ipAddressColumn)) {
            $fields['ip_address_column'] = $ipAddressColumn;
        }
        if ($hasHeaderRow !== null) {
            $fields['has_header_row'] = $hasHeaderRow ? 'true' : 'false';
        }
        if ($allowPhase2 !== null && strpos($url, '/scoring/') === false) {
            $fields['allow_phase_2'] = $allowPhase2 ? 'true' : 'false';
        }
        return $fields;
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
        if (is_array($httpResponseHeader) && !empty($httpResponseHeader)) {
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
            $name = str_replace(array('"', "\r", "\n"), array('%22', '%0D', '%0A'), basename($path));
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

        $this->request($this->apiBaseUrl . "/guessformat?$query", $response);
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

        $this->request($this->apiBaseUrl . "/guessformat?$query", $response);
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

        $this->request($this->apiBaseUrl . "/guessformat?$query", $response);
        return $response;
    }
}

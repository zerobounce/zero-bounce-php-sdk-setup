<?php

namespace ZeroBounce\SDK;

use Exception;

/**
 * Priority 3 API: bulk email finder, domain search, list evaluator, filters, S3 helpers.
 */
trait ZeroBouncePriority3
{
    private const BulkApiRootUrl = 'https://bulkapi.zerobounce.net';
    private const EmailFinderBulkBaseUrl = 'https://bulkapi.zerobounce.net/email-finder';
    private const DomainSearchBulkBaseUrl = 'https://bulkapi.zerobounce.net/domain-search';

    public function sendEmailFinderFile(
        $filepath,
        $domainColumn,
        $firstNameColumn = null,
        $lastNameColumn = null,
        $middleNameColumn = null,
        $fullNameColumn = null,
        $hasHeaderRow = null
    ) {
        if (!$domainColumn) {
            throw new ZBMissingParameterException('domainColumn is required');
        }
        $fields = $this->buildEmailFinderFields($domainColumn, $firstNameColumn, $lastNameColumn, $middleNameColumn, $fullNameColumn, $hasHeaderRow);
        return $this->sendBulkRootMultipartFile(self::EmailFinderBulkBaseUrl . '/sendfile', $filepath, $fields);
    }

    public function sendEmailFinderFileStream(
        $streamContent,
        $fileName,
        $domainColumn,
        $firstNameColumn = null,
        $lastNameColumn = null,
        $middleNameColumn = null,
        $fullNameColumn = null,
        $hasHeaderRow = null
    ) {
        if (!$domainColumn) {
            throw new ZBMissingParameterException('domainColumn is required');
        }
        $fields = $this->buildEmailFinderFields($domainColumn, $firstNameColumn, $lastNameColumn, $middleNameColumn, $fullNameColumn, $hasHeaderRow);
        return $this->sendBulkRootMultipartContent(self::EmailFinderBulkBaseUrl . '/sendfile', $fileName, $streamContent, $fields);
    }

    public function emailFinderFileStatus($fileId)
    {
        return $this->bulkRootFileStatus(self::EmailFinderBulkBaseUrl, $fileId);
    }

    public function emailFinderGetFile($fileId, $downloadPath)
    {
        return $this->bulkRootGetFile(self::EmailFinderBulkBaseUrl, $fileId, $downloadPath);
    }

    public function emailFinderDeleteFile($fileId)
    {
        return $this->bulkRootDeleteFile(self::EmailFinderBulkBaseUrl, $fileId);
    }

    public function sendDomainSearchFile($filepath, $domainColumn, $hasHeaderRow = null)
    {
        if (!$domainColumn) {
            throw new ZBMissingParameterException('domainColumn is required');
        }
        $fields = array('domain_column' => $domainColumn);
        if ($hasHeaderRow) {
            $fields['has_header_row'] = 'true';
        }
        return $this->sendBulkRootMultipartFile(self::DomainSearchBulkBaseUrl . '/sendfile', $filepath, $fields);
    }

    public function sendDomainSearchFileStream($streamContent, $fileName, $domainColumn, $hasHeaderRow = null)
    {
        if (!$domainColumn) {
            throw new ZBMissingParameterException('domainColumn is required');
        }
        $fields = array('domain_column' => $domainColumn);
        if ($hasHeaderRow) {
            $fields['has_header_row'] = 'true';
        }
        return $this->sendBulkRootMultipartContent(self::DomainSearchBulkBaseUrl . '/sendfile', $fileName, $streamContent, $fields);
    }

    public function domainSearchFileStatus($fileId)
    {
        return $this->bulkRootFileStatus(self::DomainSearchBulkBaseUrl, $fileId);
    }

    public function domainSearchGetFile($fileId, $downloadPath)
    {
        return $this->bulkRootGetFile(self::DomainSearchBulkBaseUrl, $fileId, $downloadPath);
    }

    public function domainSearchDeleteFile($fileId)
    {
        return $this->bulkRootDeleteFile(self::DomainSearchBulkBaseUrl, $fileId);
    }

    public function sendListEvaluatorFile($filepath, $emailAddressColumn)
    {
        if (!$emailAddressColumn) {
            throw new ZBMissingParameterException('emailAddressColumn is required');
        }
        $fields = array('email_address_column' => $emailAddressColumn);
        return $this->sendBulkRootMultipartFile(self::BulkApiBaseUrl . '/listevaluator/', $filepath, $fields);
    }

    public function sendListEvaluatorFileStream($streamContent, $fileName, $emailAddressColumn)
    {
        if (!$emailAddressColumn) {
            throw new ZBMissingParameterException('emailAddressColumn is required');
        }
        $fields = array('email_address_column' => $emailAddressColumn);
        return $this->sendBulkRootMultipartContent(self::BulkApiBaseUrl . '/listevaluator/', $fileName, $streamContent, $fields);
    }

    /**
     * @return array<string, mixed> decoded JSON
     */
    public function getListEvaluatorResult($fileId)
    {
        $this->checkValidApiKey();
        if (!$fileId) {
            throw new ZBMissingParameterException('fileId is required');
        }
        $url = self::BulkApiBaseUrl . '/listevaluator/' . rawurlencode($fileId) . '/?api_key=' . urlencode($this->apiKey);
        $holder = new ZBRawJsonResponse();
        $this->request($url, $holder);
        return $holder->data;
    }

    public function sendEvaluatedFile($filepath)
    {
        return $this->sendBulkRootMultipartFile(self::BulkApiBaseUrl . '/send-evaluated-file/', $filepath, array());
    }

    public function sendEvaluatedFileStream($streamContent, $fileName)
    {
        return $this->sendBulkRootMultipartContent(self::BulkApiBaseUrl . '/send-evaluated-file/', $fileName, $streamContent, array());
    }

    /**
     * @return array<string, mixed>
     */
    public function addFilter($rule, $target, $value)
    {
        $this->checkValidApiKey();
        if (!$rule || !$target || !$value) {
            throw new ZBMissingParameterException('rule, target, and value are required');
        }
        return $this->filterFormPost(self::$ApiBaseUrl . '/filters/add', array(
            'rule' => $rule,
            'target' => $target,
            'value' => $value,
        ));
    }

    /**
     * @return array<string, mixed>|mixed
     */
    public function listFilters($usePost = false)
    {
        $this->checkValidApiKey();
        if ($usePost) {
            return $this->filterFormPost(self::$ApiBaseUrl . '/filters/list', array());
        }
        $url = self::$ApiBaseUrl . '/filters/list?api_key=' . urlencode($this->apiKey);
        $holder = new ZBRawJsonResponse();
        $this->request($url, $holder);
        return $holder->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteFilter($rule, $target, $value)
    {
        $this->checkValidApiKey();
        if (!$rule || !$target || !$value) {
            throw new ZBMissingParameterException('rule, target, and value are required');
        }
        return $this->filterFormPost(self::$ApiBaseUrl . '/filters/delete', array(
            'rule' => $rule,
            'target' => $target,
            'value' => $value,
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listS3Files($bucket = null, $search = null)
    {
        $this->checkValidApiKey();
        $url = self::BulkApiRootUrl . '/listS3files?api_key=' . urlencode($this->apiKey);
        if ($bucket !== null && $bucket !== '') {
            $url .= '&bucket=' . urlencode($bucket);
        }
        if ($search !== null && $search !== '') {
            $url .= '&search=' . urlencode($search);
        }
        $holder = new ZBRawJsonResponse();
        $this->request($url, $holder);
        return $holder->data;
    }

    public function validateS3File(array $options)
    {
        if (empty($options['file_name'])) {
            throw new ZBMissingParameterException('file_name is required');
        }
        return $this->postBulkRootForm('/validateS3file', $this->buildValidateS3Fields($options));
    }

    public function scoreS3File(array $options)
    {
        if (empty($options['file_name'])) {
            throw new ZBMissingParameterException('file_name is required');
        }
        $fields = array('file_name' => $options['file_name']);
        $this->appendOptionalBool($fields, 'has_header_row', isset($options['has_header_row']) ? $options['has_header_row'] : null);
        $this->appendOptionalBool($fields, 'remove_duplicates', isset($options['remove_duplicates']) ? $options['remove_duplicates'] : null);
        $this->appendOptionalBool($fields, 'remove_duplicate', isset($options['remove_duplicate']) ? $options['remove_duplicate'] : null);
        if (isset($options['email_address_column'])) {
            $fields['email_address_column'] = $options['email_address_column'];
        }
        return $this->postBulkRootForm('/scoreS3file', $fields);
    }

    public function exportS3File($fileId, $exportBucket)
    {
        if (!$fileId) {
            throw new ZBMissingParameterException('fileId is required');
        }
        if (!$exportBucket) {
            throw new ZBMissingParameterException('exportBucket is required');
        }
        return $this->postBulkRootForm('/exportS3file', array(
            'file_id' => $fileId,
            'export_bucket' => $exportBucket,
        ));
    }

    private function buildEmailFinderFields($domainColumn, $firstNameColumn, $lastNameColumn, $middleNameColumn, $fullNameColumn, $hasHeaderRow)
    {
        $fields = array('domain_column' => $domainColumn);
        if ($hasHeaderRow) {
            $fields['has_header_row'] = 'true';
        }
        if ($firstNameColumn) {
            $fields['first_name_column'] = $firstNameColumn;
        }
        if ($lastNameColumn) {
            $fields['last_name_column'] = $lastNameColumn;
        }
        if ($middleNameColumn) {
            $fields['middle_name_column'] = $middleNameColumn;
        }
        if ($fullNameColumn) {
            $fields['full_name_column'] = $fullNameColumn;
        }
        return $fields;
    }

    private function buildValidateS3Fields(array $options)
    {
        $fields = array('file_name' => $options['file_name']);
        $this->appendOptionalBool($fields, 'has_header_row', isset($options['has_header_row']) ? $options['has_header_row'] : null);
        $this->appendOptionalBool($fields, 'remove_duplicates', isset($options['remove_duplicates']) ? $options['remove_duplicates'] : null);
        $this->appendOptionalBool($fields, 'remove_duplicate', isset($options['remove_duplicate']) ? $options['remove_duplicate'] : null);
        if (isset($options['email_address_column'])) {
            $fields['email_address_column'] = $options['email_address_column'];
        }
        if (!empty($options['return_url'])) {
            $fields['return_url'] = $options['return_url'];
        }
        if (!empty($options['first_name_column'])) {
            $fields['first_name_column'] = $options['first_name_column'];
        }
        if (!empty($options['last_name_column'])) {
            $fields['last_name_column'] = $options['last_name_column'];
        }
        if (!empty($options['gender_column'])) {
            $fields['gender_column'] = $options['gender_column'];
        }
        if (!empty($options['ip_address_column'])) {
            $fields['ip_address_column'] = $options['ip_address_column'];
        }
        if (isset($options['allow_phase_2'])) {
            $fields['allow_phase_2'] = $options['allow_phase_2'] ? 'true' : 'false';
        }
        return $fields;
    }

    private function appendOptionalBool(array &$fields, $key, $value)
    {
        if ($value !== null) {
            $fields[$key] = $value ? 'true' : 'false';
        }
    }

    private function sendBulkRootMultipartFile($url, $filepath, array $fields)
    {
        $this->checkValidApiKey();
        if (!$filepath) {
            throw new ZBMissingParameterException('filePath is required');
        }
        $fields['api_key'] = $this->apiKey;
        $files = array($filepath => file_get_contents($filepath));
        $response = $this->curl($url, $fields, $files);
        $rsp = new ZBSendFileResponse();
        $rsp->Deserialize($response);
        return $rsp;
    }

    private function sendBulkRootMultipartContent($url, $fileName, $content, array $fields)
    {
        $this->checkValidApiKey();
        $fields['api_key'] = $this->apiKey;
        $files = array($fileName => $content);
        $response = $this->curl($url, $fields, $files);
        $rsp = new ZBSendFileResponse();
        $rsp->Deserialize($response);
        return $rsp;
    }

    private function postBulkRootForm($path, array $fields)
    {
        $rsp = new ZBSendFileResponse();
        $this->formPost(self::BulkApiRootUrl . $path, $fields, $rsp);
        return $rsp;
    }

    private function bulkRootFileStatus($baseUrl, $fileId)
    {
        $this->checkValidApiKey();
        if (!$fileId) {
            throw new ZBMissingParameterException('fileId is required');
        }
        $response = new ZBFileStatusResponse();
        $this->request(
            $baseUrl . '/filestatus?api_key=' . urlencode($this->apiKey) . '&file_id=' . urlencode($fileId),
            $response
        );
        return $response;
    }

    private function bulkRootDeleteFile($baseUrl, $fileId)
    {
        $this->checkValidApiKey();
        if (!$fileId) {
            throw new ZBMissingParameterException('fileId is required');
        }
        $response = new ZBDeleteFileResponse();
        $this->request(
            $baseUrl . '/deletefile?api_key=' . urlencode($this->apiKey) . '&file_id=' . urlencode($fileId),
            $response
        );
        return $response;
    }

    private function bulkRootGetFile($baseUrl, $fileId, $downloadPath)
    {
        $this->checkValidApiKey();
        if (!$fileId) {
            throw new ZBMissingParameterException('fileId is required');
        }
        if (!$downloadPath) {
            throw new ZBMissingParameterException('downloadPath is required');
        }
        $url = $baseUrl . '/getfile?api_key=' . urlencode($this->apiKey) . '&file_id=' . urlencode($fileId);
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
    }

    /**
     * @return array<string, mixed>
     */
    private function filterFormPost($url, array $data)
    {
        $holder = new ZBRawJsonResponse();
        $this->formPost($url, $data, $holder);
        return $holder->data;
    }
}

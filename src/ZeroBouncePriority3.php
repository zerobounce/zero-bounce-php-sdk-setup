<?php

namespace ZeroBounce\SDK;

use Exception;

/**
 * Priority 3 API: bulk email finder, domain search, list evaluator, filters, S3 and SFTP/FTP bulk.
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
        $this->requireProvided($domainColumn, 'domainColumn is required');
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
        $this->requireProvided($domainColumn, 'domainColumn is required');
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
        $this->requireProvided($domainColumn, 'domainColumn is required');
        $fields = array('domain_column' => $domainColumn);
        if ($hasHeaderRow !== null) {
            $fields['has_header_row'] = $hasHeaderRow ? 'true' : 'false';
        }
        return $this->sendBulkRootMultipartFile(self::DomainSearchBulkBaseUrl . '/sendfile', $filepath, $fields);
    }

    public function sendDomainSearchFileStream($streamContent, $fileName, $domainColumn, $hasHeaderRow = null)
    {
        $this->requireProvided($domainColumn, 'domainColumn is required');
        $fields = array('domain_column' => $domainColumn);
        if ($hasHeaderRow !== null) {
            $fields['has_header_row'] = $hasHeaderRow ? 'true' : 'false';
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
        $this->requireProvided($emailAddressColumn, 'emailAddressColumn is required');
        $fields = array('email_address_column' => $emailAddressColumn);
        return $this->sendBulkRootMultipartFile($this->listEvaluatorBaseUrl . '/', $filepath, $fields);
    }

    public function sendListEvaluatorFileStream($streamContent, $fileName, $emailAddressColumn)
    {
        $this->requireProvided($emailAddressColumn, 'emailAddressColumn is required');
        $fields = array('email_address_column' => $emailAddressColumn);
        return $this->sendBulkRootMultipartContent($this->listEvaluatorBaseUrl . '/', $fileName, $streamContent, $fields);
    }

    /**
     * @return array<string, mixed> decoded JSON
     */
    public function getListEvaluatorResult($fileId)
    {
        $this->checkValidApiKey();
        $this->requireProvided($fileId, 'fileId is required');
        $url = $this->listEvaluatorBaseUrl . '/' . rawurlencode($fileId) . '/?api_key=' . urlencode($this->apiKey);
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
        if (!isset($options['file_name']) || !$this->isProvided($options['file_name'])) {
            throw new ZBMissingParameterException('file_name is required');
        }
        return $this->postBulkRootForm('/validateS3file', $this->buildValidateS3Fields($options));
    }

    public function scoreS3File(array $options)
    {
        if (!isset($options['file_name']) || !$this->isProvided($options['file_name'])) {
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
        $this->requireProvided($fileId, 'fileId is required');
        $this->requireProvided($exportBucket, 'exportBucket is required');
        return $this->postBulkRootForm('/exportS3file', array(
            'file_id' => $fileId,
            'export_bucket' => $exportBucket,
        ));
    }

    public function addFilter($rule, $target, $value)
    {
        $this->checkValidApiKey();
        if (!$this->isProvided($rule) || !$this->isProvided($target) || !$this->isProvided($value)) {
            throw new ZBMissingParameterException('rule, target, and value are required');
        }
        return $this->filterFormPost($this->apiBaseUrl . '/filters/add', array(
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
            return $this->filterFormPost($this->apiBaseUrl . '/filters/list', array());
        }
        $url = $this->apiBaseUrl . '/filters/list?api_key=' . urlencode($this->apiKey);
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
        if (!$this->isProvided($rule) || !$this->isProvided($target) || !$this->isProvided($value)) {
            throw new ZBMissingParameterException('rule, target, and value are required');
        }
        return $this->filterFormPost($this->apiBaseUrl . '/filters/delete', array(
            'rule' => $rule,
            'target' => $target,
            'value' => $value,
        ));
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
        if (isset($options['first_name_column']) && $this->isProvided($options['first_name_column'])) {
            $fields['first_name_column'] = $options['first_name_column'];
        }
        if (isset($options['last_name_column']) && $this->isProvided($options['last_name_column'])) {
            $fields['last_name_column'] = $options['last_name_column'];
        }
        if (isset($options['gender_column']) && $this->isProvided($options['gender_column'])) {
            $fields['gender_column'] = $options['gender_column'];
        }
        if (isset($options['ip_address_column']) && $this->isProvided($options['ip_address_column'])) {
            $fields['ip_address_column'] = $options['ip_address_column'];
        }
        if (isset($options['allow_phase_2'])) {
            $fields['allow_phase_2'] = $options['allow_phase_2'] ? 'true' : 'false';
        }
        return $fields;
    }

    private function postBulkRootForm($path, array $fields)
    {
        $this->checkValidApiKey();
        $rsp = new ZBSendFileResponse();
        $this->formPost(self::BulkApiRootUrl . $path, $fields, $rsp);
        return $rsp;
    }

    private function appendOptionalBool(array &$fields, $key, $value)
    {
        if ($value !== null) {
            $fields[$key] = $value ? 'true' : 'false';
        }
    }

    /**
     * Shared shape for the documented S3 and SFTP file jobs on the bulkapi root.
     * Only fields the caller set are sent; booleans go as the strings true/false.
     *
     * @param bool $fileNameRequired false for validateSFTPfile, whose file_name is optional
     * @return ZBSendFileResponse
     * @throws ZBException
     */
    private function bulkRootFileJob($path, array $options, $fileNameRequired = true)
    {
        $fileName = isset($options['file_name']) ? $options['file_name'] : null;
        if ($fileNameRequired && ($fileName === null || trim((string)$fileName) === '')) {
            throw new ZBMissingParameterException('file_name is required');
        }
        if ($fileName !== null && trim((string)$fileName) === '') {
            throw new ZBMissingParameterException('file_name is required');
        }
        if (!isset($options['has_header_row'])) {
            throw new ZBMissingParameterException('has_header_row is required');
        }

        $fields = array();
        if ($fileName !== null) {
            $fields['file_name'] = $fileName;
        }
        $this->appendOptionalBool($fields, 'has_header_row', $options['has_header_row']);
        if (isset($options['remove_duplicates'])) {
            $this->appendOptionalBool($fields, 'remove_duplicates', $options['remove_duplicates']);
        }
        if (isset($options['return_url'])) {
            $fields['return_url'] = $options['return_url'];
        }
        return $this->postBulkRootForm($path, $fields);
    }

    /**
     * Run the email finder on a file in your Amazon S3 bucket. POST /emailFinderS3file
     *
     * @return ZBSendFileResponse
     * @throws ZBException
     */
    public function emailFinderS3File(array $options)
    {
        return $this->bulkRootFileJob('/emailFinderS3file', $options);
    }

    /**
     * Run domain search on a file in your Amazon S3 bucket. POST /domainSearchS3file
     *
     * @return ZBSendFileResponse
     * @throws ZBException
     */
    public function domainSearchS3File(array $options)
    {
        return $this->bulkRootFileJob('/domainSearchS3file', $options);
    }

    // SFTP / FTP bulk. The server, path and protocol come from your ZeroBounce settings.

    /**
     * Validate a file from your SFTP/FTP server. POST /validateSFTPfile
     *
     * The docs list file_name as optional here; leave it out to use the input
     * bucket configured in your settings.
     *
     * @return ZBSendFileResponse
     * @throws ZBException
     */
    public function validateSFTPFile(array $options)
    {
        return $this->bulkRootFileJob('/validateSFTPfile', $options, false);
    }

    /**
     * Score a file from your SFTP/FTP server. POST /scoreSFTPfile
     *
     * @return ZBSendFileResponse
     * @throws ZBException
     */
    public function scoreSFTPFile(array $options)
    {
        return $this->bulkRootFileJob('/scoreSFTPfile', $options);
    }

    /**
     * Run the email finder on a file from your SFTP/FTP server. POST /emailFinderSFTPfile
     *
     * @return ZBSendFileResponse
     * @throws ZBException
     */
    public function emailFinderSFTPFile(array $options)
    {
        return $this->bulkRootFileJob('/emailFinderSFTPfile', $options);
    }

    /**
     * Run domain search on a file from your SFTP/FTP server. POST /domainSearchSFTPfile
     *
     * @return ZBSendFileResponse
     * @throws ZBException
     */
    public function domainSearchSFTPFile(array $options)
    {
        return $this->bulkRootFileJob('/domainSearchSFTPfile', $options);
    }

    /**
     * Export a validated or scored file back to your SFTP/FTP server. POST /exportSFTPfile
     *
     * Without $exportPath the export path from your ZeroBounce settings is used.
     * Note this takes export_path, not the export_bucket that exportS3File uses.
     *
     * @return ZBSendFileResponse
     * @throws ZBException
     */
    public function exportSFTPFile($fileId, $exportPath = null)
    {
        $this->requireProvided($fileId, 'fileId is required');
        if ($exportPath !== null && trim((string)$exportPath) === '') {
            throw new ZBMissingParameterException('exportPath is required');
        }
        $fields = array('file_id' => $fileId);
        if ($exportPath !== null) {
            $fields['export_path'] = $exportPath;
        }
        return $this->postBulkRootForm('/exportSFTPfile', $fields);
    }

    private function buildEmailFinderFields($domainColumn, $firstNameColumn, $lastNameColumn, $middleNameColumn, $fullNameColumn, $hasHeaderRow)
    {
        $fields = array('domain_column' => $domainColumn);
        if ($hasHeaderRow !== null) {
            $fields['has_header_row'] = $hasHeaderRow ? 'true' : 'false';
        }
        if ($this->isProvided($firstNameColumn)) {
            $fields['first_name_column'] = $firstNameColumn;
        }
        if ($this->isProvided($lastNameColumn)) {
            $fields['last_name_column'] = $lastNameColumn;
        }
        if ($this->isProvided($middleNameColumn)) {
            $fields['middle_name_column'] = $middleNameColumn;
        }
        if ($this->isProvided($fullNameColumn)) {
            $fields['full_name_column'] = $fullNameColumn;
        }
        return $fields;
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


    private function bulkRootFileStatus($baseUrl, $fileId)
    {
        $this->checkValidApiKey();
        $this->requireProvided($fileId, 'fileId is required');
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
        $this->requireProvided($fileId, 'fileId is required');
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
        $this->requireProvided($fileId, 'fileId is required');
        $this->requireProvided($downloadPath, 'downloadPath is required');
        $url = $baseUrl . '/getfile?api_key=' . urlencode($this->apiKey) . '&file_id=' . urlencode($fileId);
        return $this->saveBulkDownload($this->getBulkFileHttp($url), $downloadPath);
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

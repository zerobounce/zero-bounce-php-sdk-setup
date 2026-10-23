<?php

namespace ZeroBounce\SDK;

use Exception;

/**
 * Priority 3 API: bulk email finder, domain search, list evaluator, filters.
 */
trait ZeroBouncePriority3
{
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

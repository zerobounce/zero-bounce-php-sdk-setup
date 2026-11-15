<?php

namespace ZeroBounce\SDK;

/**
 * Inbox Placement Tester (bulkapi.zerobounce.net/api/ipt).
 *
 * The API key goes in an "Authorization: Bearer" header, never on the query
 * string or in the body, and bodies are JSON with camelCase fields. Only
 * fields the caller set are sent. Uses the account's inbox-test credits.
 */
trait ZeroBounceInboxPlacement
{
    /** @var array<int, int> */
    private static $iptTestTypes = array(1, 2, 3);

    /** @var array<int, string> */
    private static $iptTestStatuses = array('running', 'finished');

    /**
     * Seed providers available for new tests. GET /providers
     *
     * @return ZBRawJsonResponse
     * @throws ZBException
     */
    public function getInboxPlacementProviders()
    {
        return $this->iptRequest('GET', '/providers');
    }

    /**
     * Supported test types and the default one. GET /test-types
     *
     * @return ZBRawJsonResponse
     * @throws ZBException
     */
    public function getInboxPlacementTestTypes()
    {
        return $this->iptRequest('GET', '/test-types');
    }

    /**
     * Create an inbox placement test. POST /test
     *
     * @param string $name Unique test name, at most 64 characters.
     * @param int|null $testType 1 (Email, needs $senderEmail), 2 (Unique ID) or 3 (Auto Alias).
     * @param string|null $senderEmail The From address to match; required when $testType is 1.
     * @param array<int, int>|null $providerIds Use every active seed of these providers.
     * @param array<int, int>|null $seedIds Use these seed mailboxes.
     * @param array<int, array<string, mixed>>|null $providers array('id' => n, 'maxSeeds' => n).
     * @param string|null $webhookUrl Where the finished result is posted.
     * @return ZBRawJsonResponse
     * @throws ZBException
     */
    public function createInboxPlacementTest(
        $name,
        $testType = null,
        $senderEmail = null,
        $providerIds = null,
        $seedIds = null,
        $providers = null,
        $webhookUrl = null
    ) {
        if ($name === null || (is_string($name) && trim($name) === '')) {
            throw new ZBMissingParameterException("name is required");
        }
        if (!is_string($name)) {
            throw new ZBMissingParameterException("name must be a string");
        }
        if (strlen($name) > 64) {
            throw new ZBMissingParameterException("name must be at most 64 characters");
        }
        if ($testType !== null) {
            $testType = $this->iptWholeNumber($testType, 'testType must be 1, 2 or 3');
            if (!in_array($testType, self::$iptTestTypes, true)) {
                throw new ZBMissingParameterException("testType must be 1, 2 or 3");
            }
        }
        if ($senderEmail !== null && !is_string($senderEmail)) {
            throw new ZBMissingParameterException("senderEmail must be a string");
        }
        if ($testType === 1 && ($senderEmail === null || trim($senderEmail) === '')) {
            throw new ZBMissingParameterException("senderEmail is required when testType is 1");
        }
        $providerIds = $this->iptIdList($providerIds, 'providerIds');
        $seedIds = $this->iptIdList($seedIds, 'seedIds');
        if ($providers !== null && !is_array($providers)) {
            throw new ZBMissingParameterException("providers must be an array");
        }
        foreach (($providers === null ? array() : $providers) as $provider) {
            if (!is_array($provider) || !isset($provider['id'])) {
                throw new ZBMissingParameterException("each providers entry needs an id");
            }
            $this->iptWholeNumber($provider['id'], 'each providers id must be a whole number');
            if (isset($provider['maxSeeds'])
                && $this->iptWholeNumber($provider['maxSeeds'], 'providers maxSeeds must be at least 1') < 1) {
                throw new ZBMissingParameterException("providers maxSeeds must be at least 1");
            }
        }
        if ($webhookUrl !== null) {
            self::requireCallbackUrl($webhookUrl, 'webhookUrl');
        }

        $body = array('name' => $name);
        if ($testType !== null) {
            $body['testType'] = (int)$testType;
        }
        if ($senderEmail !== null) {
            $body['senderEmail'] = $senderEmail;
        }
        if ($providerIds !== null) {
            $body['providerIds'] = $providerIds;
        }
        if ($seedIds !== null) {
            $body['seedIds'] = $seedIds;
        }
        if ($providers !== null) {
            $body['providers'] = array_values($providers);
        }
        if ($webhookUrl !== null) {
            $body['webhookUrl'] = $webhookUrl;
        }
        return $this->iptRequest('POST', '/test', array(), $body);
    }

    /**
     * A test and its placement results. GET /test/{id}
     *
     * @param string|int $testId
     * @return ZBRawJsonResponse
     * @throws ZBException
     */
    public function getInboxPlacementTest($testId)
    {
        return $this->iptRequest('GET', '/test/' . $this->iptTestPath($testId));
    }

    /**
     * List tests, newest first. GET /tests
     *
     * @param string|null $status "running" or "finished".
     * @param string|null $createdAfter ISO 8601 date or datetime.
     * @param string|null $createdBefore ISO 8601 date or datetime.
     * @param int|null $page From 1.
     * @param int|null $pageSize 1 to 100.
     * @return ZBRawJsonResponse
     * @throws ZBException
     */
    public function listInboxPlacementTests(
        $status = null,
        $createdAfter = null,
        $createdBefore = null,
        $page = null,
        $pageSize = null
    ) {
        if ($status !== null && !in_array($status, self::$iptTestStatuses, true)) {
            throw new ZBMissingParameterException("status must be running or finished");
        }
        if ($page !== null) {
            $page = $this->iptWholeNumber($page, 'page must be at least 1');
            if ($page < 1) {
                throw new ZBMissingParameterException("page must be at least 1");
            }
        }
        if ($pageSize !== null) {
            $pageSize = $this->iptWholeNumber($pageSize, 'pageSize must be between 1 and 100');
            if ($pageSize < 1 || $pageSize > 100) {
                throw new ZBMissingParameterException("pageSize must be between 1 and 100");
            }
        }
        foreach (array('createdAfter' => $createdAfter, 'createdBefore' => $createdBefore) as $field => $value) {
            if ($value !== null && !self::isIsoDateOrDateTime($value)) {
                throw new ZBMissingParameterException($field . ' must be an ISO 8601 date or datetime');
            }
        }

        $query = array();
        if ($status !== null) {
            $query['status'] = $status;
        }
        if ($createdAfter !== null) {
            $query['createdAfter'] = $createdAfter;
        }
        if ($createdBefore !== null) {
            $query['createdBefore'] = $createdBefore;
        }
        if ($page !== null) {
            $query['page'] = (int)$page;
        }
        if ($pageSize !== null) {
            $query['page_size'] = (int)$pageSize;
        }
        return $this->iptRequest('GET', '/tests', $query);
    }

    /**
     * Delete a finished test. DELETE /test/{id}
     *
     * @param string|int $testId
     * @return ZBRawJsonResponse empty data when the API answers with an
     *     empty 2xx (204 No Content)
     * @throws ZBException
     */
    public function deleteInboxPlacementTest($testId)
    {
        return $this->iptRequest('DELETE', '/test/' . $this->iptTestPath($testId), array(), null, true);
    }

    /**
     * The webhook URL saved on this API key. GET /webhook
     *
     * @return ZBRawJsonResponse
     * @throws ZBException
     */
    public function getInboxPlacementWebhook()
    {
        return $this->iptRequest('GET', '/webhook');
    }

    /**
     * Save the URL finished results are posted to. PUT /webhook
     *
     * @param string $webhookUrl
     * @return ZBRawJsonResponse
     * @throws ZBException
     */
    public function setInboxPlacementWebhook($webhookUrl)
    {
        self::requireCallbackUrl($webhookUrl, 'webhookUrl');
        return $this->iptRequest('PUT', '/webhook', array(), array('webhookUrl' => $webhookUrl));
    }

    /**
     * An integer, or the digits of one, as an int.
     *
     * @param mixed $value
     * @param string $message
     * @return int
     * @throws ZBMissingParameterException
     */
    private function iptWholeNumber($value, $message)
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A[0-9]{1,15}\z/', $value)) {
            return (int)$value;
        }
        throw new ZBMissingParameterException($message);
    }

    /**
     * @param mixed $list
     * @param string $name
     * @return array<int, int>|null
     * @throws ZBMissingParameterException
     */
    private function iptIdList($list, $name)
    {
        if ($list === null) {
            return null;
        }
        if (!is_array($list)) {
            throw new ZBMissingParameterException($name . ' must be an array of whole numbers');
        }
        $ids = array();
        foreach ($list as $id) {
            $ids[] = $this->iptWholeNumber($id, $name . ' must be an array of whole numbers');
        }
        return $ids;
    }

    /**
     * @param string|int $testId
     * @return string
     * @throws ZBException
     */
    private function iptTestPath($testId)
    {
        return self::pathSegment($testId, 'testId');
    }

    /**
     * @param string $method
     * @param string $path
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body JSON body, sent without api_key.
     * @param bool $emptyIsSuccess an empty 2xx is a success with no data
     * @return ZBRawJsonResponse
     * @throws ZBException
     */
    private function iptRequest($method, $path, array $query = array(), $body = null, $emptyIsSuccess = false)
    {
        $this->checkValidApiKey();
        $url = $this->inboxPlacementBaseUrl . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        // The documented auth for this API is a Bearer header, not an api_key
        // query parameter.
        $headers = array('Authorization: Bearer ' . $this->apiKey());
        $content = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $content = json_encode($body);
        }

        // checkedUrl, like every other request: it enforces https and raises
        // the one-time warning when a regional baseUrl meets the global bulk host.
        $result = $this->executeHttp($method, $this->checkedUrl($url), $headers, $content, 120);
        $response = new ZBRawJsonResponse();
        $status = isset($result['status']) ? (int)$result['status'] : 0;
        // Empty means zero bytes: a blank body is not JSON, and is an error.
        if ($emptyIsSuccess && (!isset($result['error']) || (string)$result['error'] === '')
            && $status >= 200 && $status < 300 && (isset($result['body']) ? (string)$result['body'] : '') === '') {
            return $response;
        }
        $response->Deserialize($this->readJsonBody($result, 'exempt'));
        return $response;
    }
}

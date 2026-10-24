<?php

namespace ZeroBounce\SDK;

/**
 * Inbox Placement Tester (bulkapi.zerobounce.net/api/ipt).
 *
 * The API key goes on the query string rather than in the body, and bodies are
 * JSON with camelCase fields. Only fields the caller set are sent. Uses the
 * account's inbox-test credits.
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
        if ($name === null || trim((string)$name) === '') {
            throw new ZBMissingParameterException("name is required");
        }
        if (strlen((string)$name) > 64) {
            throw new ZBMissingParameterException("name must be at most 64 characters");
        }
        if ($testType !== null && !in_array((int)$testType, self::$iptTestTypes, true)) {
            throw new ZBMissingParameterException("testType must be 1, 2 or 3");
        }
        if ((int)$testType === 1 && ($senderEmail === null || trim((string)$senderEmail) === '')) {
            throw new ZBMissingParameterException("senderEmail is required when testType is 1");
        }
        foreach (($providers === null ? array() : $providers) as $provider) {
            if (!is_array($provider) || !isset($provider['id'])) {
                throw new ZBMissingParameterException("each providers entry needs an id");
            }
            if (isset($provider['maxSeeds']) && (int)$provider['maxSeeds'] < 1) {
                throw new ZBMissingParameterException("providers maxSeeds must be at least 1");
            }
        }

        $body = array('name' => $name);
        if ($testType !== null) {
            $body['testType'] = (int)$testType;
        }
        if ($senderEmail !== null) {
            $body['senderEmail'] = $senderEmail;
        }
        if ($providerIds !== null) {
            $body['providerIds'] = array_values($providerIds);
        }
        if ($seedIds !== null) {
            $body['seedIds'] = array_values($seedIds);
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
        if ($page !== null && (int)$page < 1) {
            throw new ZBMissingParameterException("page must be at least 1");
        }
        if ($pageSize !== null && ((int)$pageSize < 1 || (int)$pageSize > 100)) {
            throw new ZBMissingParameterException("pageSize must be between 1 and 100");
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
     * @return ZBRawJsonResponse
     * @throws ZBException
     */
    public function deleteInboxPlacementTest($testId)
    {
        return $this->iptRequest('DELETE', '/test/' . $this->iptTestPath($testId));
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
        if ($webhookUrl === null || trim((string)$webhookUrl) === '') {
            throw new ZBMissingParameterException("webhookUrl is required");
        }
        return $this->iptRequest('PUT', '/webhook', array(), array('webhookUrl' => $webhookUrl));
    }

    /**
     * @param string|int $testId
     * @return string
     * @throws ZBException
     */
    private function iptTestPath($testId)
    {
        if ($testId === null || trim((string)$testId) === '') {
            throw new ZBMissingParameterException("testId is required");
        }
        return rawurlencode(trim((string)$testId));
    }

    /**
     * @param string $method
     * @param string $path
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body JSON body, sent without api_key.
     * @return ZBRawJsonResponse
     * @throws ZBException
     */
    private function iptRequest($method, $path, array $query = array(), $body = null)
    {
        $this->checkValidApiKey();
        $url = $this->inboxPlacementBaseUrl . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        // The documented auth for this API is a Bearer header, not an api_key
        // query parameter.
        $headers = array('Authorization: Bearer ' . $this->apiKey);
        $content = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $content = json_encode($body);
        }

        $result = $this->executeHttp($method, $url, $headers, $content, 120);
        $response = new ZBRawJsonResponse();
        $response->Deserialize($this->readJsonBody($result));
        return $response;
    }
}

<?php

namespace ZeroBounce\SDK;

/**
 * Client for the ZeroBounce Warmup API (https://www.zerobounce.net/docs/warmup-api).
 *
 * The Warmup API is separate from the email validation API: its own host, its
 * own team-scoped key, and an "API-Key" header instead of an api_key
 * parameter. Use ZeroBounceWarmup with a Warmup API key; ZeroBounce stays for
 * the validation API key.
 */
class ZeroBounceWarmup
{
    const DefaultBaseUrl = 'https://warmup-api.zerobounce.net';
    const BasePath = '/api/v1/warmup';

    /** Documented onboarding models. */
    const OnboardingModels = array('self_managed', 'zb_managed');
    /** Documented types_of_emails values. */
    const EmailTypes = array('transactional', 'newsletter', 'marketing');
    /** Required by the schema but always replaced with the key's own team. */
    const AnyTeamId = 1;

    /** @var string */
    private $apiKey;
    /** @var string */
    private $root;

    /**
     * @param string $apiKey your Warmup API key, sent in the API-Key header
     * @param string|null $baseUrl defaults to https://warmup-api.zerobounce.net
     * @throws ZBException
     */
    public function __construct($apiKey, $baseUrl = null)
    {
        if ($apiKey === null || trim((string)$apiKey) === '') {
            throw new ZBMissingApiKeyException('api_key is required');
        }
        // A key with surrounding whitespace or control characters would be
        // quoted, unredacted, in the HTTP library's own error.
        if ($apiKey !== trim($apiKey) || preg_match('/[\x00-\x1f\x7f]/', $apiKey)) {
            throw new ZBMissingParameterException(
                'api_key has surrounding whitespace or control characters'
            );
        }
        $base = ($baseUrl === null || trim((string)$baseUrl) === '') ? self::DefaultBaseUrl : $baseUrl;
        if (stripos($base, 'https://') !== 0) {
            throw new ZBMissingParameterException('base_url must be an https:// URL');
        }
        $this->apiKey = $apiKey;
        $this->root = rtrim($base, '/') . self::BasePath;
    }

    // -- transport -----------------------------------------------------------

    /**
     * Replace every form of a secret in a message with ***.
     *
     * @param string $text
     * @param array<int, string> $secrets
     * @return string
     */
    private function redact($text, array $secrets = array())
    {
        $all = array_merge(array($this->apiKey), $secrets);
        foreach ($all as $secret) {
            if ($secret === null || $secret === '') {
                continue;
            }
            foreach (array($secret, rawurlencode($secret)) as $form) {
                $text = str_replace($form, '***', $text);
            }
        }
        return $text;
    }

    /**
     * @param string $method
     * @param string $path
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @param array<int, string> $secrets masked out of every error
     * @return array{status:int, headers:array, body:string}
     * @throws ZBException
     */
    private function send($method, $path, array $query = array(), $body = null, array $secrets = array())
    {
        $url = $this->root . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        if (stripos($url, 'https://') !== 0) {
            throw new ZBMissingParameterException(
                'refusing to send the API key to a URL that is not https://'
            );
        }

        $headers = array('API-Key: ' . $this->apiKey, 'Accept: application/json');
        $content = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $content = json_encode($body);
        }

        $result = $this->executeHttp($method, $url, $headers, $content, 120);
        $status = (int)$result['status'];
        if ($status >= 300 && $status < 400) {
            throw new ZBApiException(
                'ZeroBounce returned a redirect, which this client does not follow.',
                $status
            );
        }
        if ($status > 299) {
            throw new ZBApiException(
                $this->redact($this->messageFromBody((string)$result['body'], $status), $secrets),
                $status
            );
        }
        return $result;
    }

    /**
     * The JSON payload of a success, or null for the documented empty
     * successes (204, and an empty 200).
     *
     * @return array<string, mixed>|null
     * @throws ZBException
     */
    private function json($method, $path, array $query = array(), $body = null, array $secrets = array())
    {
        $result = $this->send($method, $path, $query, $body, $secrets);
        $text = (string)$result['body'];
        if ((int)$result['status'] === 204 || trim($text) === '') {
            return null;
        }
        $decoded = json_decode($text, true);
        if ($decoded === null && trim($text) !== 'null') {
            throw new ZBApiException(
                $this->redact($this->messageFromBody($text, (int)$result['status']), $secrets),
                (int)$result['status']
            );
        }
        // A list payload is wrapped so one return type covers every endpoint.
        return is_array($decoded) && array_keys($decoded) === range(0, count($decoded) - 1)
            ? array('data' => $decoded)
            : $decoded;
    }

    /**
     * A CSV download, returned as text.
     *
     * @return string
     * @throws ZBException
     */
    private function csv($path, array $query = array())
    {
        $result = $this->send('GET', $path, $query);
        $text = (string)$result['body'];
        $contentType = '';
        foreach ((array)$result['headers'] as $header) {
            if (stripos((string)$header, 'content-type:') === 0) {
                $contentType = strtolower(trim(substr((string)$header, strlen('content-type:'))));
            }
        }
        if (trim($text) === '' || strpos($contentType, 'application/json') !== false) {
            throw new ZBApiException(
                $this->redact($this->messageFromBody($text, (int)$result['status'])),
                (int)$result['status']
            );
        }
        return $text;
    }

    /**
     * Separated for easy mocking in the tests, like ZeroBounce::executeHttp.
     *
     * @return array{status:int, headers:array, body:string}
     */
    protected function executeHttp($method, $url, array $headers = array(), $body = null, $timeout = 120)
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
        ));
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            // Rebuilt rather than wrapped: the original holds the URL and headers.
            throw new ZBApiException($this->redact($error), 0);
        }
        return array(
            'status' => $status,
            'headers' => explode("\r\n", substr($raw, 0, $headerSize)),
            'body' => substr($raw, $headerSize),
        );
    }

    /** Turn an error body into a short message. Never raw HTML or a parser dump. */
    private function messageFromBody($body, $status)
    {
        $trimmed = trim((string)$body);
        if ($trimmed === '') {
            return 'Empty response from ZeroBounce (HTTP ' . $status . ')';
        }
        $head = strtolower(substr($trimmed, 0, 2000));
        if (strpos($head, '<!doctype html') !== false || strpos($head, '<html') !== false) {
            return 'ZeroBounce returned an HTML error page instead of an API response.';
        }
        if ($trimmed[0] === '{' || $trimmed[0] === '[') {
            $parsed = json_decode($trimmed, true);
            if ($parsed === null) {
                return 'ZeroBounce returned a response that is not valid JSON.';
            }
            // A top-level list of strings, e.g. ["In-progress tests cannot be deleted."].
            if (is_array($parsed) && array_keys($parsed) === range(0, count($parsed) - 1)) {
                foreach ($parsed as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        return $item;
                    }
                }
            }
            foreach (array('message', 'error', 'error_message', 'detail') as $key) {
                if (!isset($parsed[$key])) {
                    continue;
                }
                $value = $parsed[$key];
                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
                if (is_array($value) && !empty($value)) {
                    if (is_string($value[0]) && trim($value[0]) !== '') {
                        return $value[0];
                    }
                    // A validation error list: [{loc: [...], msg: "..."}].
                    if (is_array($value[0]) && isset($value[0]['msg'])) {
                        $field = '';
                        if (isset($value[0]['loc']) && is_array($value[0]['loc']) && count($value[0]['loc']) > 1) {
                            $field = implode('.', array_slice($value[0]['loc'], 1));
                        }
                        return $field === '' ? (string)$value[0]['msg'] : $field . ': ' . $value[0]['msg'];
                    }
                }
            }
            return 'ZeroBounce returned an error (HTTP ' . $status . ').';
        }
        if (strlen($trimmed) > 1000) {
            return 'ZeroBounce returned a response that is not valid JSON.';
        }
        return $trimmed;
    }

    // -- argument checks -----------------------------------------------------

    private function required($value, $name)
    {
        if ($value === null || trim((string)$value) === '') {
            throw new ZBMissingParameterException($name . ' is required');
        }
        return $value;
    }

    private function inRange($value, $low, $high, $name)
    {
        if (!is_int($value) || $value < $low || $value > $high) {
            throw new ZBMissingParameterException(
                $name . ' must be ' . $low . ' to ' . $high
            );
        }
        return $value;
    }

    /** A date for the API: a DateTime or a YYYY-MM-DD string. */
    private function isoDate($value, $name)
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        throw new ZBMissingParameterException($name . ' must be YYYY-MM-DD');
    }

    private function engagementBody($cFrom, $replyPct, $importantPct, $clickPct)
    {
        $this->required($cFrom, 'c_from');
        $this->inRange($replyPct, 5, 45, 'reply_pct');
        $this->inRange($importantPct, 5, 40, 'important_pct');
        $this->inRange($clickPct, 0, 70, 'click_pct');
        if ($clickPct > $importantPct * 1.75) {
            throw new ZBMissingParameterException('click_pct must be at most important_pct x 1.75');
        }
        return array(
            'c_from' => $cFrom,
            'reply_pct' => $replyPct,
            'important_pct' => $importantPct,
            'click_pct' => $clickPct,
        );
    }

    // -- sending accounts ----------------------------------------------------

    /** Every sending account of the key's team. GET /sending-accounts */
    public function listSendingAccounts()
    {
        return $this->json('GET', '/sending-accounts');
    }

    /** One sending account. GET /sending-account */
    public function getSendingAccount($fromAddress)
    {
        $this->required($fromAddress, 'from_address');
        return $this->json('GET', '/sending-account', array('from_address' => $fromAddress));
    }

    /**
     * Add a from-address to warmup. POST /sending-accounts
     *
     * self_managed runs your own mailbox and needs engagementRuleId 0-4.
     * zb_managed has ZeroBounce provision it and takes emailContent and
     * managedSending; a 200 means accepted, and the mailbox is provisioned
     * afterwards.
     */
    public function addSendingAccount(
        $fromAddress,
        $onboardingModel,
        $engagementRuleId = null,
        $ignoreConflict = null,
        $emailContent = null,
        $managedSending = null
    ) {
        $this->required($fromAddress, 'from_address');
        if (!in_array($onboardingModel, self::OnboardingModels, true)) {
            throw new ZBMissingParameterException(
                'onboarding_model must be self_managed or zb_managed'
            );
        }
        $body = array('from_address' => $fromAddress, 'onboarding_model' => $onboardingModel);
        if ($onboardingModel === 'self_managed') {
            if ($engagementRuleId === null) {
                throw new ZBMissingParameterException(
                    'engagement_rule_id is required for self_managed'
                );
            }
            $body['engagement_rule_id'] = $this->inRange($engagementRuleId, 0, 4, 'engagement_rule_id');
            if ($emailContent !== null || $managedSending !== null) {
                throw new ZBMissingParameterException(
                    'email_content and managed_sending are for zb_managed'
                );
            }
        }
        if ($ignoreConflict !== null) {
            $body['ignore_conflict'] = (bool)$ignoreConflict;
        }
        if ($emailContent !== null) {
            if (!is_array($emailContent)) {
                throw new ZBMissingParameterException('email_content must be an array');
            }
            if (isset($emailContent['types_of_emails'])) {
                foreach ((array)$emailContent['types_of_emails'] as $type) {
                    if (!in_array($type, self::EmailTypes, true)) {
                        throw new ZBMissingParameterException(
                            'types_of_emails must be transactional, newsletter and/or marketing'
                        );
                    }
                }
            }
            $body['email_content'] = $emailContent;
        }
        if ($managedSending !== null) {
            if (!is_array($managedSending)) {
                throw new ZBMissingParameterException('managed_sending must be an array');
            }
            if (isset($managedSending['timezone_offset'])) {
                $offset = $managedSending['timezone_offset'];
                if (!is_numeric($offset) || $offset < -11 || $offset > 14) {
                    throw new ZBMissingParameterException('timezone_offset must be -11 to 14');
                }
            }
            if (isset($managedSending['warmup_start_date'])) {
                $managedSending['warmup_start_date'] =
                    $this->isoDate($managedSending['warmup_start_date'], 'warmup_start_date');
            }
            $body['managed_sending'] = $managedSending;
        }
        return $this->json('POST', '/sending-accounts', array(), $body);
    }

    /**
     * Change an account's engagement rule, status and auto-enable flag.
     * PUT /sending-accounts. statusId is 0 to 8 (4 Paused, 5 Disabled).
     */
    public function updateSendingAccount($fromAddress, $engagementRuleId, $statusId, $autoEnable)
    {
        $this->required($fromAddress, 'from_address');
        if (!is_bool($autoEnable)) {
            throw new ZBMissingParameterException('auto_enable must be true or false');
        }
        return $this->json('PUT', '/sending-accounts', array(), array(
            'team_id' => self::AnyTeamId,
            'from_address' => $fromAddress,
            'engagement_rule_id' => $this->inRange($engagementRuleId, 0, 4, 'engagement_rule_id'),
            'status_id' => $this->inRange($statusId, 0, 8, 'status_id'),
            'auto_enable' => $autoEnable,
        ));
    }

    /** Remove a sending account. DELETE /sending-accounts. Destructive and final. */
    public function deleteSendingAccount($fromAddress)
    {
        $this->required($fromAddress, 'from_address');
        return $this->json('DELETE', '/sending-accounts', array('from_address' => $fromAddress));
    }

    /**
     * Move an account and its history to a new from-address.
     * PUT /smtp-credentials — despite the path, no SMTP credentials are stored.
     */
    public function changeFromAddress($oldFromAddress, $newFromAddress)
    {
        $this->required($oldFromAddress, 'old_from_address');
        $this->required($newFromAddress, 'new_from_address');
        return $this->json('PUT', '/smtp-credentials', array(), array(
            'team_id' => self::AnyTeamId,
            'old_from_address' => $oldFromAddress,
            'new_from_address' => $newFromAddress,
        ));
    }

    /**
     * Registered from-addresses. GET /from-addresses
     *
     * searchFrom takes letters, digits and . - _ @ + only. offset and limit go
     * on the wire as strings.
     */
    public function listFromAddresses($searchFrom = null, $offset = null, $limit = null)
    {
        $query = array();
        if ($searchFrom !== null) {
            if (!preg_match('/^[A-Za-z0-9.\-_@+]*$/', $searchFrom)) {
                throw new ZBMissingParameterException(
                    'search_from takes letters, digits and . - _ @ + only'
                );
            }
            $query['search_from'] = $searchFrom;
        }
        foreach (array('offset' => $offset, 'limit' => $limit) as $name => $value) {
            if ($value !== null) {
                if (!is_int($value) || $value < 0) {
                    throw new ZBMissingParameterException($name . ' must be a non-negative integer');
                }
                $query[$name] = (string)$value;
            }
        }
        return $this->json('GET', '/from-addresses', $query);
    }

    // -- engagement rules ----------------------------------------------------

    /** -1 Custom, 0 Default, 1 Slow Pace, 2 Repair, 3 Boost, 4 Protect. */
    public function getEngagementRules()
    {
        return $this->json('GET', '/engagement-rules');
    }

    /** Set a standard rule (0 to 4) on an address. PUT /engagement-rule */
    public function setEngagementRule($cFrom, $ruleId)
    {
        $this->required($cFrom, 'c_from');
        return $this->json('PUT', '/engagement-rule', array('c_from' => $cFrom), array(
            'rule_id' => $this->inRange($ruleId, 0, 4, 'rule_id'),
        ));
    }

    /** An address's custom rule, with can_update and remaining_seconds. */
    public function getCustomEngagement($cFrom)
    {
        $this->required($cFrom, 'c_from');
        $trimmed = trim((string)$cFrom);
        if ($trimmed === '.' || $trimmed === '..') {
            throw new ZBMissingParameterException('c_from is invalid');
        }
        return $this->json('GET', '/custom-engagement/' . rawurlencode($trimmed));
    }

    /** Create a custom rule. Starts a 7 day cooldown on updates. */
    public function createCustomEngagement($cFrom, $replyPct, $importantPct, $clickPct)
    {
        return $this->json('POST', '/custom-engagement', array(),
            $this->engagementBody($cFrom, $replyPct, $importantPct, $clickPct));
    }

    /** Update a custom rule. During the 7 day cooldown the API answers 429. */
    public function updateCustomEngagement($cFrom, $replyPct, $importantPct, $clickPct)
    {
        return $this->json('PUT', '/custom-engagement', array(),
            $this->engagementBody($cFrom, $replyPct, $importantPct, $clickPct));
    }

    /** Delete a custom rule and fall back to standard rule 1-4. */
    public function deleteCustomEngagement($cFrom, $engagementRuleId)
    {
        $this->required($cFrom, 'c_from');
        return $this->json('DELETE', '/custom-engagement', array(), array(
            'c_from' => $cFrom,
            'engagement_rule_id' => $this->inRange($engagementRuleId, 1, 4, 'engagement_rule_id'),
        ));
    }

    // -- statistics, seeds and utilities -------------------------------------

    /** The seed list. GET /seeds */
    public function getSeeds()
    {
        return $this->json('GET', '/seeds');
    }

    /** The seed list as CSV (columns seed,provider). GET /seeds/download */
    public function downloadSeeds()
    {
        return $this->csv('/seeds/download');
    }

    /** Number of seeds. GET /seed-count */
    public function getSeedCount()
    {
        return $this->json('GET', '/seed-count');
    }

    /** Today's warmup progress. GET /progress-today */
    public function getProgressToday()
    {
        return $this->json('GET', '/progress-today');
    }

    /** Messages sent today. GET /sent-today */
    public function getSentToday()
    {
        return $this->json('GET', '/sent-today');
    }

    /** Current daily volume. GET /emails-per-day */
    public function getEmailsPerDay()
    {
        return $this->json('GET', '/emails-per-day');
    }

    /** Statistics for an address. An address with no history returns zeros. */
    public function getStatistics($fromAddress)
    {
        $this->required($fromAddress, 'from_address');
        return $this->json('GET', '/statistics', array('from_address' => $fromAddress));
    }

    /** Daily statistics between two dates, inclusive. GET /statistics-per-day */
    public function getStatisticsPerDay($fromAddress, $startDate, $endDate)
    {
        $this->required($fromAddress, 'from_address');
        $start = $this->isoDate($startDate, 'start_date');
        $end = $this->isoDate($endDate, 'end_date');
        if ($start > $end) {
            throw new ZBMissingParameterException('start_date is later than end_date');
        }
        return $this->json('GET', '/statistics-per-day', array(
            'from_address' => $fromAddress,
            'start_date' => $start,
            'end_date' => $end,
        ));
    }

    /** Detailed statistics for an address. GET /statistics-detailed */
    public function getStatisticsDetailed($cFrom)
    {
        $this->required($cFrom, 'c_from');
        return $this->json('GET', '/statistics-detailed', array('c_from' => $cFrom));
    }

    /** An address's statistics as CSV. GET /statistics/download */
    public function downloadStatistics($cFrom)
    {
        $this->required($cFrom, 'c_from');
        return $this->csv('/statistics/download', array('c_from' => $cFrom));
    }

    /** Statistics for one from-address. GET /from-address-statistics */
    public function getFromAddressStatistics($fromAddress)
    {
        $this->required($fromAddress, 'from_address');
        return $this->json('GET', '/from-address-statistics', array('from_address' => $fromAddress));
    }

    /**
     * SPF, DKIM and DMARC status of a from-address's domain.
     * GET /check-domain-dmarc
     *
     * LIVE: pass an EMAIL ADDRESS. The live API answers "Please provide a valid
     * email address" for a bare domain, although the docs describe a domain.
     */
    public function checkDomainDmarc($fromAddress, $dkimSelector = null)
    {
        $this->required($fromAddress, 'from_address');
        if (strpos((string)$fromAddress, '@') === false) {
            throw new ZBMissingParameterException(
                'from_address must be an email address, e.g. sender@example.com'
            );
        }
        $query = array('from_address' => $fromAddress);
        if ($dkimSelector !== null) {
            $query['dkim_selector'] = $this->required($dkimSelector, 'dkim_selector');
        }
        return $this->json('GET', '/check-domain-dmarc', $query);
    }

    /**
     * Check that SMTP credentials connect and hand off a message.
     * POST /test-smtp-configuration
     *
     * Nothing is stored, and the test message goes to a ZeroBounce test
     * recipient, not $fromAddress.
     *
     * LIVE: the API answers any failed SMTP login with HTTP 500. That is an API
     * bug. The username and password are masked in every error from this method.
     */
    public function testSmtpConfiguration($fromAddress, $username, $password, $server, $port)
    {
        $this->required($fromAddress, 'from_address');
        $this->required($username, 'username');
        $this->required($password, 'password');
        $this->required($server, 'server');
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new ZBMissingParameterException('port must be 1 to 65535');
        }
        return $this->json('POST', '/test-smtp-configuration', array(), array(
            'from_address' => $fromAddress,
            'username' => $username,
            'password' => $password,
            'server' => $server,
            'port' => $port,
        ), array($password, $username));
    }
}

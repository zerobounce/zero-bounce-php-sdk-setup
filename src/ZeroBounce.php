<?php

namespace ZeroBounce\SDK;

use DateTime;
use DateTimeInterface;
use Exception;

/**
 */
final class ZeroBounce
{
    use ZeroBouncePriority3;
    use ZeroBounceInboxPlacement;
    use ZBStrictBool;

    /**
     * The SDK's version: the one source of truth. The publish workflow checks the release tag
     * against it (composer.json carries no "version"); CHANGELOG.md's newest release heading
     * must match it.
     */
    const VERSION = "3.0.1";

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
        return new self();
    }

    /**
     * Private constructor so nobody else can instantiate it
     *
     */
    private function __construct()
    {
        $this->apiBaseUrl = ZBBaseUrl::API_DEFAULT_URL;
    }

    /** @var string */
    private $apiBaseUrl;
    const BulkApiBaseUrl = "https://bulkapi.zerobounce.net/v2";

    /** @var string Overridable per instance; defaults to BulkApiBaseUrl. */
    private $bulkApiBaseUrl = self::BulkApiBaseUrl;

    /** The list evaluator and the Inbox Placement Tester are not under the bulk /v2 path. */
    const ListEvaluatorBaseUrl = "https://bulkapi.zerobounce.net/v2/listevaluator";
    const InboxPlacementBaseUrl = "https://bulkapi.zerobounce.net/api/ipt";

    /** Bulk host root and the two bulk APIs that are not under /v2. */
    const BulkApiRootUrl = "https://bulkapi.zerobounce.net";
    const EmailFinderBulkBaseUrl = "https://bulkapi.zerobounce.net/email-finder";
    const DomainSearchBulkBaseUrl = "https://bulkapi.zerobounce.net/domain-search";

    /**
     * @internal Test seam: callables by name, replacing the transport. Private, the
     * class is final, and nothing in the SDK sets it; only test code that binds
     * a closure to the instance can. A real client has none, so every real
     * request still runs checkedUrl() and curl.
     *
     * @var array<string, callable>
     */
    private $seam = array();

    /** @var string Overridable per instance; defaults to ListEvaluatorBaseUrl. */
    private $listEvaluatorBaseUrl = self::ListEvaluatorBaseUrl;

    /** @var string Overridable per instance; defaults to InboxPlacementBaseUrl. */
    private $inboxPlacementBaseUrl = self::InboxPlacementBaseUrl;

    /** @var ZBSecret|null The key, kept out of var_export(), serialize() and dumps. */
    private $apiKey = null;

    /** @var string|null "EU" or "US" when apiBaseUrl is a regional host. */
    private $region = null;

    /** @var bool The regional/bulk-host mismatch is reported once per client. */
    private $bulkRegionWarned = false;


    /**
     * @var string The JSON shape the current call answers with: 'object' (every
     *     call but two), 'array' (listFilters) or 'files' (listS3Files: an array,
     *     or an object with a "files" array). Strict JSON of another shape is an
     *     API error with the HTTP status (E7).
     */
    private $jsonShape = 'object';

    /** ZeroBounce documents no regional host for anything under this one. */
    const BulkHost = 'bulkapi.zerobounce.net';

    /**
     * @param string $apiKey
     * @param string|null $baseUrl the main API host. Any https:// URL is used
     *     as given; null selects ZBBaseUrl::API_DEFAULT_URL.
     * @param string|null $listEvaluatorBaseUrl
     * @param string|null $inboxPlacementBaseUrl
     * @param string|null $bulkBaseUrl the bulkapi root. Moves bulk files,
     *     scoring, email finder, domain search, S3/SFTP, and, unless they are
     *     given above, the list evaluator and Inbox Placement Tester.
     * @return void
     * @throws ZBException
     */
    public function initialize(
        #[\SensitiveParameter]
        $apiKey,
        $baseUrl = null,
        $listEvaluatorBaseUrl = null,
        $inboxPlacementBaseUrl = null,
        $bulkBaseUrl = null
    ) {
        // The key is a parameter of this frame, so an exception thrown from
        // here would carry it in its stack trace.
        try {
            $this->configure(self::checkedApiKey($apiKey), $baseUrl, $listEvaluatorBaseUrl, $inboxPlacementBaseUrl, $bulkBaseUrl);
        } catch (Exception $e) {
            throw ZBSecret::withoutSecretInTrace($e, $apiKey);
        }
    }

    /**
     * @param string|null $apiKey already checked
     * @param string|null $baseUrl
     * @param string|null $listEvaluatorBaseUrl
     * @param string|null $inboxPlacementBaseUrl
     * @param string|null $bulkBaseUrl
     * @return void
     * @throws ZBException
     */
    private function configure(
        #[\SensitiveParameter]
        $apiKey,
        $baseUrl,
        $listEvaluatorBaseUrl,
        $inboxPlacementBaseUrl,
        $bulkBaseUrl
    ) {
        // Every argument is checked before anything is stored, so a call that
        // throws leaves the client exactly as it was (no new key with the old
        // URLs, no half-applied set of URLs).
        $apiBaseUrl = self::resolvedBaseUrl($baseUrl);
        // Every bulkapi.zerobounce.net path hangs off one root, so a single
        // override points bulk files, scoring, email finder, domain search,
        // S3/SFTP, the list evaluator and the Inbox Placement Tester at a test
        // server. The two arguments before it still override their own host.
        $bulkRoot = $bulkBaseUrl === null || $bulkBaseUrl === ''
            ? self::BulkApiRootUrl
            : rtrim(self::requireHttpsUrl($bulkBaseUrl, 'bulkBaseUrl'), '/');
        $listEvaluator = null;
        if ($listEvaluatorBaseUrl !== null && $listEvaluatorBaseUrl !== '') {
            $listEvaluator = rtrim(self::requireHttpsUrl($listEvaluatorBaseUrl, 'listEvaluatorBaseUrl'), '/');
        }
        $inboxPlacement = null;
        if ($inboxPlacementBaseUrl !== null && $inboxPlacementBaseUrl !== '') {
            $inboxPlacement = rtrim(self::requireHttpsUrl($inboxPlacementBaseUrl, 'inboxPlacementBaseUrl'), '/');
        }

        $this->apiKey = ($apiKey === null || $apiKey === '') ? null : new ZBSecret((string)$apiKey);
        $this->apiBaseUrl = $apiBaseUrl;
        $this->setBulkBaseUrls($bulkRoot);
        if ($listEvaluator !== null) {
            $this->listEvaluatorBaseUrl = $listEvaluator;
        }
        if ($inboxPlacement !== null) {
            $this->inboxPlacementBaseUrl = $inboxPlacement;
        }
        $host = strtolower((string)parse_url($this->apiBaseUrl, PHP_URL_HOST));
        $regions = array('api-eu.zerobounce.net' => 'EU', 'api-us.zerobounce.net' => 'US');
        $this->region = isset($regions[$host]) ? $regions[$host] : null;
        $this->bulkRegionWarned = false;
    }

    /**
     * @return string|null
     */
    private function apiKey()
    {
        return $this->apiKey === null ? null : $this->apiKey->value();
    }

    /**
     * The key, or a clear error. A key with surrounding whitespace or control
     * characters would otherwise be quoted, unredacted, in a transport error.
     *
     * @param string $apiKey
     * @return string
     * @throws ZBException
     */
    private static function checkedApiKey(
        #[\SensitiveParameter]
        $apiKey
    ) {
        // An empty key stays deferred to checkValidApiKey(), which names the
        // call that initializes the client.
        if ($apiKey === null || trim((string)$apiKey) === '') {
            return $apiKey;
        }
        $apiKey = (string)$apiKey;
        if (ZBUrl::hasInvisible($apiKey) || preg_match('/[\x00-\x20\x7f]/', $apiKey)) {
            throw new ZBMissingParameterException(ZBUrl::KEY_CHARACTERS_PROBLEM);
        }
        if (ZBUrl::hasNonPrintableAscii($apiKey)) {
            throw new ZBMissingParameterException(ZBUrl::KEY_HEADER_PROBLEM);
        }
        return $apiKey;
    }

    /**
     * The base URL to use, without guessing. An unknown host used to be
     * replaced by ZBBaseUrl::getByValue with the production default, so a
     * custom or mock base URL was silently swapped for api.zerobounce.net and
     * live traffic went out of a test run. Any https URL is now taken as
     * given, and anything else raises.
     *
     * @param string|null $baseUrl null or '' selects the default host
     * @return string
     * @throws ZBMissingParameterException
     */
    private static function resolvedBaseUrl($baseUrl)
    {
        if ($baseUrl === null || $baseUrl === '') {
            return ZBBaseUrl::API_DEFAULT_URL;
        }
        return rtrim(self::requireHttpsUrl($baseUrl, 'baseUrl'), '/');
    }

    /**
     * @param string $url
     * @param string $name
     * @return string
     * @throws ZBMissingParameterException
     */
    private static function requireHttpsUrl($url, $name)
    {
        if (stripos((string)$url, 'https://') !== 0) {
            throw new ZBMissingParameterException($name . ' must be an https:// URL');
        }
        if (!ZBUrl::isSafeHttps($url)) {
            throw new ZBMissingParameterException(
                $name . ' must be a valid https:// URL (a host, an optional port, no credentials, no whitespace)'
            );
        }
        return $url;
    }

    /**
     * Whether $value is an ISO 8601 date (YYYY-MM-DD, a real day) or a
     * date and time (T or space, HH:MM[:SS[.fraction]], optional Z or offset).
     *
     * @param mixed $value
     * @return bool
     */
    private static function isIsoDateOrDateTime($value)
    {
        if (!is_string($value) || !preg_match(
            '/\A(\d{4}-\d{2}-\d{2})(?:[Tt ]([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d{1,9})?)?(?:[Zz]|[+-](?:[01]\d|2[0-3]):?[0-5]\d)?)?\z/',
            $value,
            $m
        )) {
            return false;
        }
        return self::isRealDate($m[1]);
    }

    /**
     * A URL the API calls back (return_url, callback_url, a webhook URL): a
     * string holding an http:// or https:// URL, refused before anything is sent.
     *
     * @param mixed $value
     * @param string $name
     * @return string
     * @throws ZBMissingParameterException
     */
    private static function requireCallbackUrl($value, $name)
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            throw new ZBMissingParameterException($name . ' is required');
        }
        if (!is_string($value)) {
            throw new ZBMissingParameterException($name . ' must be a string');
        }
        if (!ZBUrl::isSafeHttpOrHttps($value)) {
            throw new ZBMissingParameterException(
                $name . ' must be a valid http:// or https:// URL (a host, an optional port, no credentials, no whitespace)'
            );
        }
        return $value;
    }

    /**
     * One path segment, or a clear error. A segment of "." or ".." would walk
     * the request up out of the endpoint it was meant for.
     *
     * @param string|int $value
     * @param string $name
     * @return string url-encoded
     * @throws ZBMissingParameterException
     */
    private static function pathSegment($value, $name)
    {
        if ($value !== null && !is_string($value) && !is_int($value)) {
            throw new ZBMissingParameterException($name . ' must be a string');
        }
        $trimmed = trim((string)$value);
        if ($trimmed === '') {
            throw new ZBMissingParameterException($name . ' is required');
        }
        // Everything else, slashes included, is percent-encoded and stays
        // inside the segment; "." and ".." would walk the request elsewhere.
        if ($trimmed === '.' || $trimmed === '..') {
            throw new ZBMissingParameterException($name . ' is invalid');
        }
        return rawurlencode($trimmed);
    }

    /**
     * A strict boolean option as the string the API expects on the wire.
     *
     * @param mixed $value
     * @param string $name
     * @return string "true" or "false"
     * @throws ZBMissingParameterException
     */
    private static function wireBool($value, $name)
    {
        return self::strictBool($value, $name) ? 'true' : 'false';
    }

    /**
     * Keeps the API key out of var_dump() and print_r().
     *
     * @return array<string, mixed>
     */
    public function __debugInfo()
    {
        $vars = get_object_vars($this);
        if (isset($vars['apiKey'])) {
            $vars['apiKey'] = '***';
        }
        return $vars;
    }

    /**
     * The URL, after checking it is https and warning once when a regional
     * base URL is paired with the single global bulk host.
     *
     * @param string $url
     * @return string
     * @throws ZBMissingParameterException
     */
    private function checkedUrl($url)
    {
        if (isset($this->seam['checkedUrl'])) {
            return call_user_func($this->seam['checkedUrl'], $url);
        }
        self::requireHttpsUrl($url, 'url');
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if (!$this->bulkRegionWarned && $this->region !== null && $host === self::BulkHost) {
            $this->bulkRegionWarned = true;
            trigger_error(
                'baseUrl is the ' . $this->region . ' API, but this request goes to ' . self::BulkHost
                . ': ZeroBounce documents no regional host for bulk files, scoring files, email finder'
                . ' and domain search files, the list evaluator, S3/SFTP or the Inbox Placement Tester.'
                . ' Pass the matching base URLs to initialize() if ZeroBounce gave you regional ones.',
                E_USER_WARNING
            );
        }
        return $url;
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
        $this->requireText($email, 'email');
        if ($options instanceof ZBValidateOptions) {
            $this->checkTimeout($options->timeout, false);
            // Parsed strictly up front, on a copy so the caller's object is untouched.
            $options = clone $options;
            foreach (array('activityData' => 'activity_data', 'verifyPlus' => 'verify_plus',
                'domainInfo' => 'domain_info', 'greylistProcessing' => 'greylist_processing') as $property => $name) {
                if ($options->{$property} !== null) {
                    $options->{$property} = self::strictBool($options->{$property}, $name);
                }
            }
            // callbackUrl only means anything while greylist processing is on, so
            // reject it here rather than letting the API take the request.
            if ($options->callbackUrl !== null && $options->greylistProcessing !== true) {
                throw new ZBMissingParameterException(
                    "callbackUrl requires greylistProcessing = true"
                );
            }
            if ($options->callbackUrl !== null) {
                self::requireCallbackUrl($options->callbackUrl, 'callbackUrl');
            }
        }
        $response = new ZBValidateResponse();
        // ip_address is sent only when given, as in the other SDKs.
        $ipValue = ($ipAddress === null) ? '' : (string)$ipAddress;
        $fields = array('email' => $email);
        if ($ipValue !== '') {
            $fields['ip_address'] = $ipValue;
        }
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
            if (self::strictBool($options->usePost, 'usePost')) {
                $this->formPost($this->apiBaseUrl . "/validate", $fields, $response);
                return $response;
            }
        }
        $url = $this->apiBaseUrl . "/validate?api_key=" . urlencode($this->apiKey())
            . "&email=" . urlencode($email);
        if ($ipValue !== '') {
            $url .= "&ip_address=" . urlencode($ipValue);
        }
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
        if (!is_array($emails)) {
            throw new ZBMissingParameterException("emails must be an array");
        }
        if (!count($emails)) throw new ZBMissingParameterException("emails parameter is required");
        $emails = array_values($emails);
        $tuples = is_array($emails[0]);
        foreach ($emails as $entry) {
            if (is_array($entry) !== $tuples) {
                throw new ZBException('Unknown Parameter Type');
            }
            $address = $tuples ? (isset($entry[0]) ? $entry[0] : null) : $entry;
            if (!is_string($address) || trim($address) === '') {
                throw new ZBMissingParameterException("each email must be a non-blank string");
            }
            if ($tuples && isset($entry[1]) && !is_string($entry[1])) {
                throw new ZBMissingParameterException("each ip_address must be a string");
            }
        }
        if ($options instanceof ZBValidateOptions) {
            $this->checkTimeout($options->timeout, true);
            $options = clone $options;
            foreach (array('activityData' => 'activity_data', 'verifyPlus' => 'verify_plus') as $property => $name) {
                if ($options->{$property} !== null) {
                    $options->{$property} = self::strictBool($options->{$property}, $name);
                }
            }
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
                    $row = ['email_address' => $email[0]];
                    if (isset($email[1]) && $email[1] !== '') {
                        $row['ip_address'] = $email[1];
                    }
                    return $row;
                },
                $emails
            );
        else
            throw new ZBException('Unknown Parameter Type');
        $params = ['email_batch' => $params];
        if ($options instanceof ZBValidateOptions) {
            if ($options->timeout !== null) {
                // LIVE: the API requires timeout as a JSON string ("30"). As a
                // number it rejects the whole batch with the account-level
                // "Invalid API Key or your account ran out of credits" error.
                // activity_data and verify_plus stay JSON booleans.
                $params['timeout'] = (string)$options->timeout;
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
    public function getCredits($usePost = false)
    {
        $this->checkValidApiKey();
        $response = new ZBGetCreditsResponse();
        $this->getOrPostForm($this->apiBaseUrl . "/getcredits", array(), $response, $usePost);
        return $response;
    }

    /**
     * @param DateTime $startDate The start date of when you want to view API usage
     * @param DateTime $endDate The end date of when you want to view API usage
     * @return ZBApiUsageResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function getApiUsage($startDate, $endDate, $usePost = false)
    {
        $this->checkValidApiKey();

        if (!$startDate) throw new ZBMissingParameterException("startDate is required");
        if (!$endDate) throw new ZBMissingParameterException("endDate is required");

        $response = new ZBApiUsageResponse();
        $this->getOrPostForm(
            $this->apiBaseUrl . "/getapiusage",
            array(
                'start_date' => self::apiDate($startDate, 'startDate'),
                'end_date' => self::apiDate($endDate, 'endDate'),
            ),
            $response,
            $usePost
        );
        return $response;
    }

    /**
     * A date for the API: a DateTime, or a YYYY-MM-DD string.
     *
     * A DateTime cast to a string becomes the whole locale-formatted stamp,
     * which the API rejects and Cloudflare can block outright.
     *
     * @param DateTimeInterface|string $value
     * @param string $name
     * @return string
     * @throws ZBMissingParameterException
     */
    private static function apiDate($value, $name)
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (self::isRealDate($value)) {
            return $value;
        }
        throw new ZBMissingParameterException($name . ' must be a DateTime or a YYYY-MM-DD string');
    }

    /**
     * @param string $email The email address to check
     * @return ZBActivityResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function getActivity($email, $usePost = false)
    {
        $this->checkValidApiKey();

        $this->requireText($email, 'email');

        $response = new ZBActivityResponse();
        $this->getOrPostForm(
            $this->apiBaseUrl . "/activity", array('email' => $email), $response, $usePost
        );
        return $response;
    }

    /**
     * Real-time AI scoring for a single email (GET /scoring).
     * @param string $email
     * @return ZBScoreEmailResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function scoreEmail($email, $usePost = false)
    {
        $this->checkValidApiKey();
        $this->requireText($email, 'email');

        $response = new ZBScoreEmailResponse();
        $this->getOrPostForm(
            $this->apiBaseUrl . "/scoring", array('email' => $email), $response, $usePost
        );
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
        $this->requireText($email, 'email');

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
     * @param bool|null $removeDuplicate When not null, sends remove_duplicate (the API's default is true).
     * @return ZBSendFileResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function sendFile(
        $filepath, $emailAddressColumn, $returnUrl = NULL, $firstNameColumn = NULL, $lastNameColumn = NULL,
        $genderColumn = NULL, $ipAddressColumn = NULL, $hasHeaderRow = NULL, $allowPhase2 = null,
        $removeDuplicate = null)
    {
        return $this->_sendFile($this->bulkApiBaseUrl . "/sendfile", $filepath, $emailAddressColumn,
            $returnUrl, $firstNameColumn, $lastNameColumn,
            $genderColumn, $ipAddressColumn, $hasHeaderRow, $allowPhase2, $removeDuplicate);
    }

    /**
     * The sendfile API allows user to send a file for bulk email validation
     * @param string $filepath
     * @param int $emailAddressColumn
     * @param string|null $returnUrl
     * @param bool|null $hasHeaderRow
     * @param bool|null $removeDuplicate When not null, sends remove_duplicate (the API's default is true).
     * @return ZBSendFileResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function scoringSendFile(
        $filepath, $emailAddressColumn, $returnUrl = NULL, $hasHeaderRow = NULL, $removeDuplicate = null)
    {
        return $this->_sendFile($this->bulkApiBaseUrl . "/scoring/sendfile", $filepath, $emailAddressColumn,
            $returnUrl, NULL, NULL,
            NULL, NULL, $hasHeaderRow, null, $removeDuplicate);
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
     * @param bool|null $removeDuplicate When not null, sends remove_duplicate (the API's default is true).
     * @return ZBSendFileResponse
     */
    public function sendFileStream(
        $streamContent, $fileName, $emailAddressColumn, $returnUrl = NULL, $firstNameColumn = NULL,
        $lastNameColumn = NULL, $genderColumn = NULL, $ipAddressColumn = NULL, $hasHeaderRow = NULL,
        $allowPhase2 = null, $removeDuplicate = null)
    {
        return $this->_sendFileWithContent(
            $this->bulkApiBaseUrl . "/sendfile",
            $fileName, $streamContent, $emailAddressColumn,
            $returnUrl, $firstNameColumn, $lastNameColumn, $genderColumn, $ipAddressColumn, $hasHeaderRow,
            $allowPhase2, $removeDuplicate
        );
    }

    /**
     * Send a file for bulk email scoring from stream content.
     * @param string $streamContent The file content
     * @param string $fileName The file name to use for the upload (e.g. "emails.csv")
     * @param int $emailAddressColumn
     * @param string|null $returnUrl
     * @param bool|null $hasHeaderRow
     * @param bool|null $removeDuplicate When not null, sends remove_duplicate (the API's default is true).
     * @return ZBSendFileResponse
     */
    public function scoringSendFileStream(
        $streamContent, $fileName, $emailAddressColumn, $returnUrl = NULL, $hasHeaderRow = NULL,
        $removeDuplicate = null)
    {
        return $this->_sendFileWithContent(
            $this->bulkApiBaseUrl . "/scoring/sendfile",
            $fileName, $streamContent, $emailAddressColumn,
            $returnUrl, NULL, NULL, NULL, NULL, $hasHeaderRow, null, $removeDuplicate
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
     * @param bool|null $removeDuplicate
     * @return ZBSendFileResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    private function _sendFile(
        $url,
        $filepath, $emailAddressColumn, $returnUrl = NULL, $firstNameColumn = NULL, $lastNameColumn = NULL,
        $genderColumn = NULL, $ipAddressColumn = NULL, $hasHeaderRow = NULL, $allowPhase2 = null,
        $removeDuplicate = null)
    {

        $this->checkValidApiKey();
        $this->requireProvided($filepath, "filePath is required");
        $this->requireColumn($emailAddressColumn, 'emailAddressColumn');

        try {
            $fields = $this->multipartFileFields(
                $emailAddressColumn, $returnUrl, $firstNameColumn, $lastNameColumn,
                $genderColumn, $ipAddressColumn, $hasHeaderRow, $allowPhase2, $url, $removeDuplicate
            );

            $files = array();
            $files[$filepath] = self::readUploadFile($filepath);

            $response = $this->curl($url, $fields, $files);

            $rsp = new ZBSendFileResponse();
            $rsp->Deserialize($response);
            return $rsp;
        } catch (Exception $e) {
            throw $this->redactedException($e);
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
     * @param bool|null $removeDuplicate
     * @return ZBSendFileResponse
     */
    private function _sendFileWithContent(
        $url, $fileName, $content, $emailAddressColumn, $returnUrl = NULL, $firstNameColumn = NULL,
        $lastNameColumn = NULL, $genderColumn = NULL, $ipAddressColumn = NULL, $hasHeaderRow = NULL,
        $allowPhase2 = null, $removeDuplicate = null)
    {
        $this->checkValidApiKey();
        $this->requireProvided($fileName, "fileName is required");
        $this->requireColumn($emailAddressColumn, 'emailAddressColumn');

        try {
            $fields = $this->multipartFileFields(
                $emailAddressColumn, $returnUrl, $firstNameColumn, $lastNameColumn,
                $genderColumn, $ipAddressColumn, $hasHeaderRow, $allowPhase2, $url, $removeDuplicate
            );

            $files = array($fileName => $content);

            $response = $this->curl($url, $fields, $files);

            $rsp = new ZBSendFileResponse();
            $rsp->Deserialize($response);
            return $rsp;
        } catch (Exception $e) {
            throw $this->redactedException($e);
        }
    }

    /**
     * make a JSON post
     * @param string $url
     * @param array $data
     * @return ZBResponse
     */
    private function json($url, $data, $response)
    {
        if (isset($this->seam['json'])) {
            return call_user_func($this->seam['json'], $url, $data, $response);
        }
        $data['api_key'] = $this->apiKey();
        $content = json_encode($data);
        $result = $this->executeHttp(
            'POST',
            $this->checkedUrl($url),
            array('Content-Type: application/json'),
            $content,
            120
        );

        $json = $this->readJsonBody($result, self::messageOnlyMode($response));
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
    private function formPost($url, $data, $response)
    {
        if (isset($this->seam['formPost'])) {
            return call_user_func($this->seam['formPost'], $url, $data, $response);
        }
        $data['api_key'] = $this->apiKey();
        $content = http_build_query($data);
        $result = $this->executeHttp(
            'POST',
            $this->checkedUrl($url),
            array('Content-Type: application/x-www-form-urlencoded'),
            $content,
            120
        );

        $json = $this->readJsonBody($result, self::messageOnlyMode($response));
        $response->Deserialize($json);
        return (int)$result['status'];
    }

    /**
     * GET with query parameters, or the documented form-encoded POST with the
     * same parameters. Both are accepted by every endpoint that takes this.
     *
     * @param string $url without a query string
     * @param array<string, mixed> $params without api_key
     * @param ZBResponse $response
     * @param bool $usePost
     * @return int http statusCode
     * @throws ZBException
     */
    private function getOrPostForm($url, array $params, $response, $usePost)
    {
        if (self::strictBool($usePost, 'usePost')) {
            return $this->formPost($url, $params, $response);
        }
        $params['api_key'] = $this->apiKey();
        return $this->request($url . '?' . http_build_query($params), $response);
    }

    /**
     * getOrPostForm for the email finder and domain search, whose result is
     * never a bare message: see ZBHttpError::throwIfMessageOnly. A flag rather
     * than a parameter, so the private request methods keep the signatures
     * subclasses override.
     *
     * @param string $url without a query string
     * @param array<string, mixed> $params without api_key
     * @param ZBResponse $response
     * @param bool $usePost
     * @return int http statusCode
     */
    private function getOrPostFinder($url, array $params, $response, $usePost)
    {
        return $this->getOrPostForm($url, $params, $response, $usePost);
    }

    /**
     * The bytes of a file to upload. A missing, unreadable or directory path
     * is refused here, before anything is sent: file_get_contents() only
     * warns, and an empty file part would be uploaded and accepted.
     *
     * @param string $filepath
     * @return string
     * @throws ZBException
     */
    private static function readUploadFile($filepath)
    {
        if (!is_string($filepath) || $filepath === '' || strpos($filepath, "\0") !== false) {
            throw new ZBException('filePath cannot be read');
        }
        if (is_dir($filepath)) {
            throw new ZBException('filePath is a folder, not a file');
        }
        $content = (is_file($filepath) && is_readable($filepath)) ? @file_get_contents($filepath) : false;
        if ($content === false) {
            throw new ZBException('filePath cannot be read');
        }
        return $content;
    }

    /**
     * this function is separated like this for easy mocking in the tests
     * @param string $url
     * @param array $fields
     * @param array $files
     * @return string
     */
    private function curl($url, $fields, $files)
    {
        if (isset($this->seam['curl'])) {
            return call_user_func($this->seam['curl'], $url, $fields, $files);
        }
        $boundary = bin2hex(random_bytes(16));
        $delimiter = '-------------' . $boundary;
        $post_data = $this->build_data_files($boundary, $fields, $files);
        $result = $this->executeHttp(
            'POST',
            $this->checkedUrl($url),
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

        $this->requireText($fileId, 'fileId');

        $response = new ZBFileStatusResponse();
        $this->request($this->bulkApiBaseUrl
            . ($scoring ? "/scoring" : "")
            . "/filestatus?api_key=" . urlencode($this->apiKey())
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

        $this->requireText($fileId, 'fileId');
        $this->requireProvided($downloadPath, "downloadPath is required");
        // A target that cannot take the file is refused before anything is sent.
        self::downloadTarget($downloadPath);

        try {
            $query = array(
                'api_key' => $this->apiKey(),
                'file_id' => $fileId,
            );
            if ($options instanceof ZBGetFileOptions) {
                if ($options->downloadType !== null && $options->downloadType !== '') {
                    $query['download_type'] = $options->downloadType;
                }
                if (!$scoring && $options->activityData !== null) {
                    $query['activity_data'] = self::wireBool($options->activityData, 'activity_data');
                }
            }

            $path = ($scoring ? '/scoring' : '') . '/getfile';
            $url = $this->bulkApiBaseUrl . $path . '?' . http_build_query($query);

            if ($options instanceof ZBGetFileOptions && $options->stream) {
                return $this->streamBulkDownload($url, $downloadPath);
            }
            return $this->saveBulkDownload($this->getBulkFileHttp($url), $downloadPath);
        } catch (Exception $e) {
            throw $this->redactedException($e);
        }
    }


    /**
     * Masks the API key in text that can end up in exception messages or logs.
     */
    private function redactApiKey($message)
    {
        return ZBSecret::redact($message, array($this->apiKey()));
    }

    /**
     * @param string $url
     * @return array{body: string, headers: string[]}
     * @throws ZBException
     */
    private function getBulkFileHttp($url)
    {
        if (isset($this->seam['getBulkFileHttp'])) {
            return call_user_func($this->seam['getBulkFileHttp'], $url);
        }
        $result = $this->executeHttp('GET', $this->checkedUrl($url), array(), null, 120);
        if (isset($result['error']) && (string)$result['error'] !== '') {
            throw new ZBException($this->redactApiKey($result['error']));
        }
        if ($result['body'] === '' && (int)$result['status'] === 0) {
            throw new ZBException('No response');
        }
        return array('body' => $result['body'], 'headers' => $result['headers']);
    }

    /**
     * @param string[] $headers
     * @return int
     */
    private static function parseHttpStatusFromHeaders($headers)
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
    private static function parseContentTypeFromHeaders($headers)
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
        $t = ltrim(self::stripBom($body));
        if ($t === '' || $t[0] !== '{') {
            return false;
        }
        $o = json_decode($t, true);
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
    private static function shouldTreatGetFileBodyAsError($body, $contentType)
    {
        if (self::isJsonContentType($contentType)) {
            return true;
        }
        return self::getFileJsonIndicatesError($body) || self::isJsonContainer($body);
    }

    /**
     * application/json, text/json and any structured-syntax +json type
     * (application/problem+json, application/vnd.api+json).
     *
     * @param string $contentType
     * @return bool
     */
    private static function isJsonContentType($contentType)
    {
        $ct = strtolower(trim((string)$contentType));
        return strpos($ct, 'application/json') !== false || strpos($ct, 'text/json') !== false
            || preg_match('#^[a-z0-9.+_-]+/[a-z0-9.+_-]*\+json$#', $ct) === 1;
    }

    /**
     * Without a leading UTF-8 byte order mark.
     *
     * @param string $body
     * @return string
     */
    private static function stripBom($body)
    {
        $body = (string)$body;
        return strncmp($body, "\xEF\xBB\xBF", 3) === 0 ? (string)substr($body, 3) : $body;
    }

    /**
     * Whether a body is a JSON object or array: no result file is, so under
     * any content type it is an error body.
     *
     * @param string $body
     * @return bool
     */
    private static function isJsonContainer($body)
    {
        $t = trim(self::stripBom($body));
        if ($t === '' || ($t[0] !== '{' && $t[0] !== '[')) {
            return false;
        }
        return is_array(json_decode($t, true));
    }

    /**
     * @param string $body
     * @return string
     */
    private static function formatGetFileErrorMessage($body)
    {
        $body = self::stripBom($body);
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
    private function _deleteFile($scoring, $fileId)
    {
        $this->checkValidApiKey();

        $this->requireText($fileId, 'fileId');

        $response = new ZBDeleteFileResponse();
        $this->request($this->bulkApiBaseUrl
            . ($scoring ? "/scoring" : "")
            . "/deletefile?api_key=" . urlencode($this->apiKey())
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
    private function executeHttp($method, $url, array $headers = array(), $body = null, $timeout = 120)
    {
        if (isset($this->seam['executeHttp'])) {
            return call_user_func($this->seam['executeHttp'], $method, $url, $headers, $body, $timeout);
        }
        // Every verb goes through curl. The http:// stream wrapper reports its
        // status only through PHP's predefined response-header variable, which
        // the engine exposes to the calling function alone and 8.5 deprecates;
        // read from anywhere else it is always empty, so an error status was
        // silently recorded as 0 and a 404 looked like a successful response.
        $responseHeaders = array();
        $curl = curl_init();
        $curlOpts = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => self::ConnectTimeoutSeconds,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HEADERFUNCTION => function ($handle, $header) use (&$responseHeaders) {
                $line = trim($header);
                if ($line !== '') {
                    $responseHeaders[] = $line;
                }
                return strlen($header);
            },
        );
        if ($headers) {
            $curlOpts[CURLOPT_HTTPHEADER] = $headers;
        }
        if ($body !== null) {
            $curlOpts[CURLOPT_POSTFIELDS] = $body;
        }
        $this->applyCurlOptions($curl, $curlOpts);
        $raw = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        // PHP 7 returns the partial body of a transfer cut short (curl's
        // CURLE_PARTIAL_FILE) instead of false: any curl error is a failure.
        $failed = curl_errno($curl) !== 0;
        self::closeCurl($curl);
        if ($raw === false || $raw === null || $failed) {
            return array(
                'status' => $status,
                'headers' => $responseHeaders,
                'body' => '',
                'error' => $error === '' ? 'The request to ZeroBounce failed.' : $error,
            );
        }
        return array(
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => (string)$raw,
        );
    }

    private function request($url, $response)
    {
        if (isset($this->seam['request'])) {
            return call_user_func($this->seam['request'], $url, $response);
        }
        try {
            $result = $this->executeHttp('GET', $this->checkedUrl($url), array(), null, 120);
            $json = $this->readJsonBody($result, self::messageOnlyMode($response));
            $response->Deserialize($json);
            return (int)$result['status'];
        } catch (Exception $e) {
            throw $this->redactedException($e);
        }
    }

    /**
     * $e rebuilt with the API key masked out of its message. Rebuilt rather
     * than rethrown so the message is redacted, but a ZBApiException stays one
     * and keeps its HTTP status for callers that switch on it.
     *
     * @param Exception $e
     * @return ZBException
     */
    private function redactedException(Exception $e)
    {
        $message = $this->redactApiKey($e->getMessage());
        if ($e instanceof ZBApiException) {
            return new ZBApiException($message, $e->getStatusCode());
        }
        return new ZBException($message);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function isProvided($value)
    {
        return $value !== null && (!is_string($value) || trim($value) !== '');
    }

    /**
     * A required text argument (an email, a file id, a path): a non-blank
     * string, or an integer. A float, boolean, array or object is rejected
     * here, before anything is sent, instead of as a PHP TypeError.
     *
     * @param mixed $value
     * @param string $name
     * @throws ZBMissingParameterException
     */
    private function requireText($value, $name)
    {
        if (!$this->isProvided($value)) {
            throw new ZBMissingParameterException($name . ' is required');
        }
        if (!is_string($value) && !is_int($value)) {
            throw new ZBMissingParameterException($name . ' must be a string');
        }
    }

    /**
     * A column number: a non-negative integer, or its digits as a string.
     *
     * @param mixed $value
     * @param string $name
     * @return bool true when a column was given; false for null or ''
     * @throws ZBMissingParameterException
     */
    private function requireColumn($value, $name)
    {
        $this->requireProvided($value, $name . ' is required');
        $this->isProvidedColumn($value, $name);
    }

    /**
     * @param mixed $value
     * @param string $name
     * @return bool
     * @throws ZBMissingParameterException
     */
    private function isProvidedColumn($value, $name)
    {
        if ($value === null || $value === '') {
            return false;
        }
        if (is_int($value) ? $value >= 0 : (is_string($value) && preg_match('/\A[0-9]+\z/', $value))) {
            return true;
        }
        throw new ZBMissingParameterException($name . ' must be a column number');
    }

    /**
     * @param mixed $value
     * @param string $message
     * @throws ZBMissingParameterException
     */
    private function requireProvided($value, $message)
    {
        if (!$this->isProvided($value)) {
            throw new ZBMissingParameterException($message);
        }
        if (!is_string($value) && !is_int($value)) {
            $suffix = ' is required';
            $name = substr($message, -strlen($suffix)) === $suffix ? substr($message, 0, -strlen($suffix)) : $message;
            throw new ZBMissingParameterException($name . ' must be a string');
        }
    }

    /**
     * @param int|string|null $timeout
     * @param bool $batch
     * @throws ZBException
     */
    private function checkTimeout($timeout, $batch)
    {
        if ($timeout === null || $timeout === '') {
            return;
        }
        $message = $batch
            ? 'Invalid parameter: timeout (must be between 10 and 120 seconds)'
            : 'Invalid parameter: timeout (must be between 3 and 60 seconds)';
        if (!is_numeric($timeout) || (float)$timeout != floor((float)$timeout)) {
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
     * The JSON body of a successful response. Every JSON request (GET, form,
     * JSON and multipart POST, the Inbox Placement Tester) reads its answer
     * here, so this is where messages built from a body - which can quote the
     * key: "Invalid API key <key>", a proxy echoing the request URL, an HTML
     * title - are redacted, whatever the status.
     *
     * @param array{status?: int, headers?: string[], body?: string} $result
     * @return string
     * @throws ZBApiException an HTTP error status, an empty, HTML or non-JSON
     *     body, or {"success": false}; getStatusCode() is the HTTP status
     * @throws ZBException a bare account-level error (invalid key, no credits)
     */
    private function readJsonBody(array $result, $messageOnly = 'strict')
    {
        try {
            return $this->checkedJsonBody($result, $messageOnly);
        } catch (ZBException $e) {
            throw $this->redactedException($e);
        }
    }

    /**
     * How a body made only of message fields is treated for $response:
     * 'exempt' for the raw-JSON calls (filters) and the list evaluator result,
     * whose documented success can be a message; 'greylist' for validate,
     * where only {"Message": "<text>"} is a result; else 'strict' (an error).
     *
     * @param mixed $response
     * @return string
     */
    private static function messageOnlyMode($response)
    {
        if ($response instanceof ZBRawJsonResponse || $response instanceof ZBListEvaluatorResponse) {
            return 'exempt';
        }
        return $response instanceof ZBValidateResponse ? 'greylist' : 'strict';
    }

    /**
     * readJsonBody without the redaction.
     *
     * @param array{status?: int, headers?: string[], body?: string} $result
     * @return string
     */
    private function checkedJsonBody(array $result, $messageOnly = 'strict')
    {
        $status = isset($result['status']) ? (int)$result['status'] : 0;
        $body = isset($result['body']) ? (string)$result['body'] : '';
        $headers = (isset($result['headers']) && is_array($result['headers'])) ? $result['headers'] : array();
        if (isset($result['error']) && (string)$result['error'] !== '') {
            // A transport failure (connect error, timeout): curl's own message,
            // not an "Empty response (HTTP 0)".
            throw new ZBException($this->redactApiKey($result['error']));
        }
        if (trim($body) === '' || $status > 299 || ZBHttpError::isHtml($body, $headers)) {
            // A ZBApiException, so callers can tell a Cloudflare 403 from an
            // API 400/404; it is still a ZBException.
            throw new ZBApiException(ZBHttpError::message($status, $body, $headers), $status);
        }
        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Not JSON, or nested deeper than json_decode's depth limit.
            throw new ZBApiException('ZeroBounce returned a response that is not valid JSON.', $status);
        }
        if (!is_array($decoded)) {
            throw new ZBApiException(self::shapeMessage($this->jsonShape), $status);
        }
        ZBHttpError::throwIfBareAccountError($decoded, $status);
        ZBHttpError::throwIfSuccessIsFalse($decoded, $status, $body, $headers);
        // json_decode(..., true) reads [] and {} alike: the body says which it was.
        $isArray = substr(ltrim($body, " \t\r\n"), 0, 1) === '[';
        $greylist = $messageOnly === 'greylist' && ZBHttpError::isGreylistMessage($decoded);
        if ($messageOnly !== 'exempt' && !$greylist) {
            ZBHttpError::throwIfMessageOnly($decoded, $status, !$isArray);
        }
        $fits = $this->jsonShape === 'array' ? $isArray
            : ($this->jsonShape === 'files' ? ($isArray || (isset($decoded['files']) && is_array($decoded['files'])))
            : !$isArray);
        if (!$fits) {
            throw new ZBApiException(self::shapeMessage($this->jsonShape), $status);
        }
        return $body;
    }

    /**
     * @param string $shape
     * @return string
     */
    private static function shapeMessage($shape)
    {
        $expected = array('array' => 'an array', 'files' => 'an array or an object with files');
        return 'ZeroBounce returned JSON of an unexpected shape: expected '
            . (isset($expected[$shape]) ? $expected[$shape] : 'an object') . '.';
    }

    /**
     * Runs $call with the current call's JSON shape set to $shape.
     *
     * @param string $shape
     * @return mixed what $call returns
     */
    private function withJsonShape($shape, callable $call)
    {
        $this->jsonShape = $shape;
        try {
            return $call();
        } finally {
            $this->jsonShape = 'object';
        }
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
            // Reported on an HTTP 200, like a dead key on the other calls. The
            // text comes from the body, which can quote the key.
            throw new ZBApiException($this->redactApiKey($message), 200);
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
        $text = self::stripBom($body);
        if (trim($text) === '' || $code > 299 || ZBHttpError::isHtml($text, $headers) || self::shouldTreatGetFileBodyAsError($text, $contentType)) {
            throw new ZBApiException($this->redactApiKey(ZBHttpError::message($code, $text, $headers)), $code);
        }
        // Written beside the target and moved into place once complete, so
        // a failed write (a full disk, a read-only folder) leaves no partial
        // file and keeps a file already there.
        $target = self::downloadTarget($downloadPath);
        try {
            $partial = $this->partialFileBeside($target);
        } catch (Exception $e) {
            $this->discardCreatedFolders();
            throw $e;
        }
        $handle = $this->openDownloadFile($partial);
        if ($handle === false) {
            @unlink($partial);
            $this->discardCreatedFolders();
            throw new ZBException('Could not write file');
        }
        $written = self::writeAll($handle, $body);
        $flushed = @fflush($handle);
        if (!@fclose($handle) || !$flushed || !$written || !@rename($partial, $target)) {
            @unlink($partial);
            $this->discardCreatedFolders();
            throw new ZBException('Could not write file');
        }
        $this->createdFolders = array();
        $response = new ZBGetFileResponse();
        $response->localFilePath = $downloadPath;
        return $response;
    }

    /**
     * Stream a GET to $handle, keeping the first DownloadSniffBytes in memory
     * so the caller can tell a result file from an error body. Separated like
     * executeHttp so tests never touch the network.
     *
     * @param string $url
     * @param resource $handle open for writing
     * @return array{status: int, headers: string[], head: string, error: string}
     * @throws ZBException
     */
    private function streamHttpToHandle($url, $handle)
    {
        if (isset($this->seam['streamHttpToHandle'])) {
            return call_user_func($this->seam['streamHttpToHandle'], $url, $handle);
        }
        $head = '';
        $headers = array();
        $sniffing = true;
        $writeFailed = false;
        $curl = curl_init();
        $this->applyCurlOptions($curl, array(
            CURLOPT_URL => $this->checkedUrl($url),
            // No overall limit, so a large file can take as long as it needs;
            // a server that stops sending is dropped instead of hanging forever.
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CONNECTTIMEOUT => self::ConnectTimeoutSeconds,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => $this->downloadStallSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_HEADERFUNCTION => function ($curlHandle, $header) use (&$headers) {
                $line = trim($header);
                if ($line !== '') {
                    $headers[] = $line;
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => function ($curlHandle, $chunk) use (&$head, &$sniffing, &$writeFailed, $handle) {
                $size = strlen($chunk);
                if ($sniffing) {
                    $head .= $chunk;
                    if (strlen($head) < self::DownloadSniffBytes) {
                        return $size;
                    }
                    $sniffing = false;
                    $chunk = $head;
                }
                if (!self::writeAll($handle, $chunk)) {
                    // A short count makes curl stop the transfer
                    // (CURLE_WRITE_ERROR) instead of going on to "succeed".
                    $writeFailed = true;
                    return 0;
                }
                return $size;
            },
        ));
        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        // PHP 7 reports a transfer cut short (CURLE_PARTIAL_FILE) as success.
        if (curl_errno($curl) !== 0) {
            $ok = false;
        }
        self::closeCurl($curl);
        if ($sniffing && !$writeFailed && !self::writeAll($handle, $head)) {
            // The whole body fit inside the sniff window and was never written.
            $writeFailed = true;
        }
        if (!$writeFailed && !@fflush($handle)) {
            $writeFailed = true;
        }
        return array(
            'status' => $status,
            'headers' => $headers,
            'head' => $head,
            // A transfer curl stopped because the file could not be written
            // is reported as that, after any API error the body shows.
            'error' => ($ok === false && !$writeFailed) ? ($error === '' ? 'The download failed.' : $error) : '',
            'writeFailed' => $writeFailed,
        );
    }

    /**
     * Writes all of $data, or reports that it could not.
     *
     * @param resource $handle
     * @param string $data
     * @return bool
     */
    private static function writeAll($handle, $data)
    {
        // @: a full disk raises a notice; the caller throws instead.
        return $data === '' || @fwrite($handle, $data) === strlen($data);
    }

    /** @var string[] folders a download created, removed again if it fails */
    private $createdFolders = array();

    private function discardCreatedFolders()
    {
        foreach (array_reverse($this->createdFolders) as $folder) {
            @rmdir($folder);
        }
        $this->createdFolders = array();
    }

    /**
     * The path a download is finally written to, checked before anything is
     * sent: a symbolic link is followed (the file behind it is replaced, the
     * link stays), and a folder, a path ending in a separator, a folder that
     * cannot take a new file and a file used as a folder are refused. Missing
     * folders are not created here.
     *
     * @param string $downloadPath
     * @return string
     * @throws ZBException
     */
    private static function downloadTarget($downloadPath)
    {
        $path = (string)$downloadPath;
        if ($path === '' || strpos($path, "\0") !== false) {
            throw new ZBException('Could not write file');
        }
        $last = substr($path, -1);
        if ($last === '/' || $last === DIRECTORY_SEPARATOR) {
            throw new ZBException('downloadPath is a folder, not a file');
        }
        $viaLink = false;
        for ($hops = 0; is_link($path); $hops++) {
            $viaLink = true;
            $link = @readlink($path);
            if ($link === false || $hops >= 40) {
                throw new ZBException('Could not write file');
            }
            $absolute = $link[0] === '/' || (DIRECTORY_SEPARATOR === '\\' && preg_match('#^[A-Za-z]:[\\\\/]#', $link) === 1);
            $path = $absolute ? $link : dirname($path) . DIRECTORY_SEPARATOR . $link;
        }
        if (is_dir($path)) {
            throw new ZBException('downloadPath is a folder, not a file');
        }
        $folder = dirname($path);
        if ($viaLink && !is_dir($folder)) {
            // A link's folder is not made up for it.
            throw new ZBException('Could not write file');
        }
        $existing = $folder;
        while (!file_exists($existing) && !is_link($existing)) {
            $parent = dirname($existing);
            if ($parent === $existing) {
                break;
            }
            $existing = $parent;
        }
        if (!is_dir($existing) || !is_writable($existing)) {
            throw new ZBException('Could not write file');
        }
        return $path;
    }

    /**
     * An empty temporary file in $target's folder (created if missing), for a
     * download to be written to and then renamed over $target. It has the
     * mode of the file it replaces, or the usual mode for a new file.
     *
     * @param string $target a path from downloadTarget()
     * @return string
     * @throws ZBException when the folder cannot take a new file
     */
    private function partialFileBeside($target)
    {
        $folder = dirname($target);
        if ($folder === '' || $folder === '.') {
            $folder = '.';
        } elseif (!file_exists($folder)) {
            $missing = array();
            for ($dir = $folder; !file_exists($dir); $dir = dirname($dir)) {
                array_unshift($missing, $dir);
                if (dirname($dir) === $dir) {
                    break;
                }
            }
            foreach ($missing as $dir) {
                if (!@mkdir($dir, 0777) && !is_dir($dir)) {
                    throw new ZBException('Could not write file');
                }
                $this->createdFolders[] = $dir;
            }
        }
        // @: tempnam() falls back to the system temp folder, with a notice,
        // when $folder is not writable; a file there is refused below.
        $partial = @tempnam($folder, '.zb-download-');
        if ($partial === false || realpath(dirname($partial)) !== realpath($folder)) {
            if ($partial !== false) {
                @unlink($partial);
            }
            throw new ZBException('Could not write file');
        }
        // tempnam() creates the file 0600, before anything is written to it.
        $mode = is_file($target) ? (@fileperms($target) & 0777) : (0666 & ~umask());
        @chmod($partial, $mode);
        return $partial;
    }

    /**
     * Opens a download's file for writing. Separated so the tests can
     * simulate a disk that fills up.
     *
     * @param string $path
     * @return resource|false
     */
    private function openDownloadFile($path)
    {
        if (isset($this->seam['openDownloadFile'])) {
            return call_user_func($this->seam['openDownloadFile'], $path);
        }
        return @fopen($path, 'wb');
    }

    /** How long a connection may take to open, for every request. */
    const ConnectTimeoutSeconds = 30;

    /**
     * Sets the options on a curl handle. Separated so the tests can check
     * the options every request is sent with.
     *
     * @param resource|\CurlHandle $curl
     * @param array<int, mixed> $options
     * @return void
     */
    private function applyCurlOptions($curl, array $options)
    {
        if (isset($this->seam['applyCurlOptions'])) {
            call_user_func($this->seam['applyCurlOptions'], $options);
        }
        curl_setopt_array($curl, $options);
    }

    /**
     * curl_close() frees the handle before PHP 8.0; from 8.0 it does nothing
     * and 8.5 deprecates it, so it is called only where it still matters.
     *
     * @param resource|\CurlHandle $curl
     * @return void
     */
    private static function closeCurl($curl)
    {
        if (\PHP_VERSION_ID < 80000) {
            curl_close($curl);
        }
    }

    /**
     * @var int A streamed download that receives nothing for this long is
     *     aborted. There is no overall limit, so large files still finish.
     */
    private $downloadStallSeconds = 120;

    /** Sniff this much before deciding whether a download is really an error body. */
    const DownloadSniffBytes = 65536;

    /** An error body is short; reading more than this to quote it would defeat streaming. */
    const DownloadMaxErrorBytes = 2097152;

    /**
     * GET a result file and write it to $downloadPath in chunks.
     *
     * The bytes go to a temporary file beside $downloadPath and are moved into
     * place only once the transfer finishes, so a large file never has to fit
     * in memory and a failed download leaves nothing at $downloadPath.
     *
     * @param string $url
     * @param string $downloadPath
     * @return ZBGetFileResponse
     * @throws ZBException
     */
    private function streamBulkDownload($url, $downloadPath)
    {
        $target = self::downloadTarget($downloadPath);
        $partial = $this->partialFileBeside($target);
        $handle = $this->openDownloadFile($partial);
        if ($handle === false) {
            @unlink($partial);
            $this->discardCreatedFolders();
            throw new ZBException('Could not write file');
        }

        $transfer = $this->streamHttpToHandle($url, $handle);
        $closed = @fclose($handle);
        $head = (string)$transfer['head'];
        $headers = (array)$transfer['headers'];
        $status = (int)$transfer['status'];
        $error = (string)$transfer['error'];

        try {
            if ($error !== '') {
                // Rebuilt rather than wrapped: the original holds the URL.
                throw new ZBException($this->redactApiKey($error));
            }
            $contentType = self::parseContentTypeFromHeaders($headers);
            $sample = self::stripBom(substr($head, 0, self::DownloadMaxErrorBytes));
            if (trim($sample) === '' || $status > 299 || ZBHttpError::isHtml($sample, $headers)
                || self::shouldTreatGetFileBodyAsError($sample, $contentType)) {
                throw new ZBApiException(
                    $this->redactApiKey(ZBHttpError::message($status, $sample, $headers)),
                    $status
                );
            }
            if (strlen($head) >= self::DownloadSniffBytes && in_array(substr(ltrim(self::stripBom($head)), 0, 1), array('{', '['), true)) {
                // A JSON object longer than the sniff window cannot be parsed to
                // tell an error from a file; no result file starts with "{" or "[", so
                // it is an error with its status, as the same body under the
                // window would be.
                throw new ZBApiException(
                    'ZeroBounce returned a JSON body larger than the error limit instead of the file.',
                    $status
                );
            }
            if (!empty($transfer['writeFailed']) || !$closed) {
                // A full disk: the partial file is truncated.
                throw new ZBException('Could not write file');
            }
            if (!@rename($partial, $target)) {
                throw new ZBException('Could not write file');
            }
        } catch (Exception $e) {
            @unlink($partial);
            $this->discardCreatedFolders();
            throw $e;
        }
        $this->createdFolders = array();

        $response = new ZBGetFileResponse();
        $response->localFilePath = $downloadPath;
        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function multipartFileFields(
        $emailAddressColumn, $returnUrl, $firstNameColumn, $lastNameColumn,
        $genderColumn, $ipAddressColumn, $hasHeaderRow, $allowPhase2, $url, $removeDuplicate = null)
    {
        $fields = array(
            'api_key' => $this->apiKey(),
            'email_address_column' => $emailAddressColumn,
        );
        if ($returnUrl !== null && $returnUrl !== '') {
            $fields['return_url'] = self::requireCallbackUrl($returnUrl, 'return_url');
        }
        if ($this->isProvidedColumn($firstNameColumn, 'firstNameColumn')) {
            $fields['first_name_column'] = $firstNameColumn;
        }
        if ($this->isProvidedColumn($lastNameColumn, 'lastNameColumn')) {
            $fields['last_name_column'] = $lastNameColumn;
        }
        if ($this->isProvidedColumn($genderColumn, 'genderColumn')) {
            $fields['gender_column'] = $genderColumn;
        }
        if ($this->isProvidedColumn($ipAddressColumn, 'ipAddressColumn')) {
            $fields['ip_address_column'] = $ipAddressColumn;
        }
        if ($hasHeaderRow !== null) {
            $fields['has_header_row'] = self::wireBool($hasHeaderRow, 'has_header_row');
        }
        if ($removeDuplicate !== null) {
            $fields['remove_duplicate'] = self::wireBool($removeDuplicate, 'remove_duplicate');
        }
        if ($allowPhase2 !== null && strpos($url, '/scoring/') === false) {
            $fields['allow_phase_2'] = self::wireBool($allowPhase2, 'allow_phase_2');
        }
        return $fields;
    }

    /**
     * @throws ZBMissingApiKeyException
     */
    private function checkValidApiKey()
    {
        if (!$this->apiKey()) throw new ZBMissingApiKeyException("ZeroBounce SDK is not initialized. Please call ZeroBounce::Instance()->initialize(\"API_KEY\") first");
    }

    /**
     * A header parameter value: raw UTF-8, a quote as %22, CR and LF removed.
     *
     * @param string $text
     * @return string
     */
    private static function quoteMultipart($text)
    {
        return str_replace(array("\r", "\n", '"'), array('', '', '%22'), (string)$text);
    }

    private function build_data_files($boundary, $fields, $files)
    {
        $data = '';
        $eol = "\r\n";

        $delimiter = '-------------' . $boundary;

        foreach ($fields as $name => $content) {
            if ($name === 'return_url' && preg_match('/[\x00-\x1f\x7f]/', (string)$content)) {
                throw new ZBException('return_url must be a single-line URL');
            }
            $data .= "--" . $delimiter . $eol
                . 'Content-Disposition: form-data; name="' . self::quoteMultipart($name) . "\"" . $eol . $eol
                . $content . $eol;
        }


        foreach ($files as $path => $content) {
            $name = self::quoteMultipart(basename($path));
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

        $this->requireText($domain, 'domain');

        $response = new ZBGuessFormatResponse();
        $args = [
            "api_key" => $this->apiKey(),
            "domain" => $domain
        ];
        if ($firstName) $args['first_name'] = $firstName;
        if ($middleName) $args['middle_name'] = $middleName;
        if ($lastName) $args['last_name'] = $lastName;

        $query = http_build_query($args);

        // Like findEmail and findEmailFormat: a body made only of message fields
        // (LIVE: {"Message": ...} for a dead key) is an error, not a result.
        $this->request($this->apiBaseUrl . "/guessformat?$query", $response);
        return $response;
    }

    /**
     * Search for new business email addresses
     * 
     * @param string|null $domain
     * @param string|null $firstName Optional: the API returns the domain's format without it.
     * @param string|null $companyName
     * @param string|null $middleName
     * @param string|null $lastName
     * @return ZBFindEmailResponse
     * @throws ZBException
     * @throws ZBMissingApiKeyException
     * @throws ZBMissingParameterException
     */
    public function findEmail(
        $domain, $firstName, $companyName = null, $middleName = null, $lastName = null, $usePost = false)
    {
        $this->checkValidApiKey();

        foreach (array('domain' => $domain, 'companyName' => $companyName) as $name => $given) {
            if ($given !== null && !is_string($given)) {
                throw new ZBMissingParameterException($name . ' must be a string');
            }
        }
        if (!$this->isProvided($domain) && !$this->isProvided($companyName)) {
            throw new ZBMissingParameterException('domain or companyName is required');
        }

        $response = new ZBFindEmailResponse();
        $args = [];
        if ($firstName) $args['first_name'] = $firstName;

        // The API doesn't accept both
        if ($domain) {
            $args['domain'] = $domain;
        } else {
            $args['company_name'] = $companyName;
        }

        if ($middleName) $args['middle_name'] = $middleName;
        if ($lastName) $args['last_name'] = $lastName;

        $this->getOrPostFinder($this->apiBaseUrl . "/guessformat", $args, $response, $usePost);
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
    public function findEmailFormat($domain, $companyName = null, $usePost = false)
    {
        $this->checkValidApiKey();

        foreach (array('domain' => $domain, 'companyName' => $companyName) as $name => $given) {
            if ($given !== null && !is_string($given)) {
                throw new ZBMissingParameterException($name . ' must be a string');
            }
        }
        if (!$this->isProvided($domain) && !$this->isProvided($companyName)) {
            throw new ZBMissingParameterException('domain or companyName is required');
        }

        $response = new ZBFindEmailFormatResponse();
        $args = [];

        // The API doesn't accept both
        if ($domain) {
            $args['domain'] = $domain;
        } else {
            $args['company_name'] = $companyName;
        }

        $this->getOrPostFinder($this->apiBaseUrl . "/guessformat", $args, $response, $usePost);
        return $response;
    }
}

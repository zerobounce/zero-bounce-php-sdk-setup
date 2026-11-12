<?php

namespace ZeroBounce\SDK;

use DateTime;
use DateTimeInterface;
use Exception;

/**
 */
class ZeroBounce
{
    use ZeroBouncePriority3;
    use ZeroBounceInboxPlacement;
    use ZBStrictBool;

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

    /** @var string Overridable per instance; defaults to BulkApiBaseUrl. */
    protected $bulkApiBaseUrl = self::BulkApiBaseUrl;

    /** The list evaluator and the Inbox Placement Tester are not under the bulk /v2 path. */
    const ListEvaluatorBaseUrl = "https://bulkapi.zerobounce.net/v2/listevaluator";
    const InboxPlacementBaseUrl = "https://bulkapi.zerobounce.net/api/ipt";

    /** Bulk host root and the two bulk APIs that are not under /v2. */
    const BulkApiRootUrl = "https://bulkapi.zerobounce.net";
    const EmailFinderBulkBaseUrl = "https://bulkapi.zerobounce.net/email-finder";
    const DomainSearchBulkBaseUrl = "https://bulkapi.zerobounce.net/domain-search";

    /** @var string Overridable per instance; defaults to ListEvaluatorBaseUrl. */
    protected $listEvaluatorBaseUrl = self::ListEvaluatorBaseUrl;

    /** @var string Overridable per instance; defaults to InboxPlacementBaseUrl. */
    protected $inboxPlacementBaseUrl = self::InboxPlacementBaseUrl;

    /** @var ZBSecret|null The key, kept out of var_export(), serialize() and dumps. */
    private $apiKey = null;

    /** @var string|null "EU" or "US" when apiBaseUrl is a regional host. */
    private $region = null;

    /** @var bool The regional/bulk-host mismatch is reported once per client. */
    private $bulkRegionWarned = false;

    /** Set only for the duration of an email finder or domain search call. */
    private $messageOnlyIsFailure = false;

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
    protected static function checkedApiKey(
        #[\SensitiveParameter]
        $apiKey
    ) {
        // An empty key stays deferred to checkValidApiKey(), which names the
        // call that initializes the client.
        if ($apiKey === null || trim((string)$apiKey) === '') {
            return $apiKey;
        }
        if ($apiKey !== trim($apiKey) || preg_match('/[\x00-\x1f\x7f]/', $apiKey)) {
            throw new ZBMissingParameterException(
                'api_key has surrounding whitespace or control characters'
            );
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
    protected static function resolvedBaseUrl($baseUrl)
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
    protected static function requireHttpsUrl($url, $name)
    {
        if (stripos((string)$url, 'https://') !== 0) {
            throw new ZBMissingParameterException($name . ' must be an https:// URL');
        }
        return $url;
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
    protected static function pathSegment($value, $name)
    {
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
    protected static function wireBool($value, $name)
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
    protected function checkedUrl($url)
    {
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
        if (!$email) throw new ZBMissingParameterException("email is required");
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
            if ($options->callbackUrl !== null && trim((string)$options->callbackUrl) === '') {
                throw new ZBMissingParameterException("callbackUrl is required");
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
            if ($options->usePost) {
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
        if (!$emails or !count($emails)) throw new ZBMissingParameterException("emails parameter is required");
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
    protected static function apiDate($value, $name)
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
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

        if (!$email) throw new ZBMissingParameterException("email is required");

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
        if (!$email) throw new ZBMissingParameterException("email is required");

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
        return $this->_sendFile($this->bulkApiBaseUrl . "/sendfile", $filepath, $emailAddressColumn,
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
        return $this->_sendFile($this->bulkApiBaseUrl . "/scoring/sendfile", $filepath, $emailAddressColumn,
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
            $this->bulkApiBaseUrl . "/sendfile",
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
            $this->bulkApiBaseUrl . "/scoring/sendfile",
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
            throw $this->redactedException($e);
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
        $data['api_key'] = $this->apiKey();
        $content = json_encode($data);
        $result = $this->executeHttp(
            'POST',
            $this->checkedUrl($url),
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
        $data['api_key'] = $this->apiKey();
        $content = http_build_query($data);
        $result = $this->executeHttp(
            'POST',
            $this->checkedUrl($url),
            array('Content-Type: application/x-www-form-urlencoded'),
            $content,
            120
        );

        $json = $this->readJsonBody($result);
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
    protected function getOrPostForm($url, array $params, $response, $usePost)
    {
        if ($usePost) {
            return $this->formPost($url, $params, $response);
        }
        $params['api_key'] = $this->apiKey();
        return $this->request($url . '?' . http_build_query($params), $response);
    }

    /**
     * getOrPostForm for the email finder and domain search, whose result is
     * never a bare message: see ZBHttpError::throwIfMessageOnly. A flag rather
     * than a parameter, so the protected request methods keep the signatures
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
        $this->messageOnlyIsFailure = true;
        try {
            return $this->getOrPostForm($url, $params, $response, $usePost);
        } finally {
            $this->messageOnlyIsFailure = false;
        }
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

        $this->requireProvided($fileId, "fileId is required");

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

        $this->requireProvided($fileId, "fileId is required");
        $this->requireProvided($downloadPath, "downloadPath is required");

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
        $message = (string) $message;
        $key = $this->apiKey();
        if (!$key) {
            return $message;
        }
        return str_replace(array($key, urlencode($key), rawurlencode($key)), '***', $message);
    }

    /**
     * @param string $url
     * @return array{body: string, headers: string[]}
     * @throws ZBException
     */
    protected function getBulkFileHttp($url)
    {
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
    private function _deleteFile($scoring, $fileId)
    {
        $this->checkValidApiKey();

        $this->requireProvided($fileId, "fileId is required");

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
    protected function executeHttp($method, $url, array $headers = array(), $body = null, $timeout = 120)
    {
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
        self::closeCurl($curl);
        if ($raw === false || $raw === null) {
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

    protected function request($url, $response)
    {
        try {
            $result = $this->executeHttp('GET', $this->checkedUrl($url), array(), null, 120);
            $json = $this->readJsonBody($result);
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
     * @throws ZBApiException an HTTP error status, an empty, HTML or non-JSON
     *     body, or {"success": false}; getStatusCode() is the HTTP status
     * @throws ZBException a bare account-level error (invalid key, no credits)
     */
    private function readJsonBody(array $result)
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
        if (!is_array($decoded)) {
            throw new ZBApiException('ZeroBounce returned a response that is not valid JSON.', $status);
        }
        ZBHttpError::throwIfBareAccountError($decoded, $status);
        ZBHttpError::throwIfSuccessIsFalse($decoded, $status, $body, $headers);
        if ($this->messageOnlyIsFailure) {
            ZBHttpError::throwIfMessageOnly($decoded, $status);
        }
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
            // Reported on an HTTP 200, like a dead key on the other calls.
            throw new ZBApiException($message, 200);
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
            throw new ZBApiException(ZBHttpError::message($code, $body, $headers), $code);
        }
        $folder = dirname($downloadPath);
        if ($folder !== '' && $folder !== '.' && !file_exists($folder)) {
            mkdir($folder, 0777, true);
        }
        $handle = $this->openDownloadFile($downloadPath);
        if ($handle === false) {
            throw new ZBException('Could not write file');
        }
        $written = self::writeAll($handle, $body);
        $flushed = @fflush($handle);
        if (!@fclose($handle) || !$flushed || !$written) {
            // A short write (a full disk) leaves a truncated file that would
            // read as the result: remove it.
            @unlink($downloadPath);
            throw new ZBException('Could not write file');
        }
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
    protected function streamHttpToHandle($url, $handle)
    {
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

    /**
     * Opens a download's file for writing. Separated so the tests can
     * simulate a disk that fills up.
     *
     * @param string $path
     * @return resource|false
     */
    protected function openDownloadFile($path)
    {
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
    protected function applyCurlOptions($curl, array $options)
    {
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
    protected $downloadStallSeconds = 120;

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
    protected function streamBulkDownload($url, $downloadPath)
    {
        $folder = dirname($downloadPath);
        if ($folder === '' || $folder === '.') {
            $folder = '.';
        } elseif (!file_exists($folder)) {
            mkdir($folder, 0777, true);
        }
        $partial = tempnam($folder, '.zb-download-');
        if ($partial === false) {
            throw new ZBException('Could not write file');
        }
        $handle = $this->openDownloadFile($partial);
        if ($handle === false) {
            @unlink($partial);
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
            $sample = substr($head, 0, self::DownloadMaxErrorBytes);
            if (trim($sample) === '' || $status > 299 || ZBHttpError::isHtml($sample, $headers)
                || self::shouldTreatGetFileBodyAsError($sample, $contentType)) {
                throw new ZBApiException(
                    $this->redactApiKey(ZBHttpError::message($status, $sample, $headers)),
                    $status
                );
            }
            if (!empty($transfer['writeFailed']) || !$closed) {
                // A full disk: the partial file is truncated.
                throw new ZBException('Could not write file');
            }
            if (!rename($partial, $downloadPath)) {
                throw new ZBException('Could not write file');
            }
        } catch (Exception $e) {
            @unlink($partial);
            throw $e;
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
            'api_key' => $this->apiKey(),
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
            $fields['has_header_row'] = self::wireBool($hasHeaderRow, 'has_header_row');
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
            "api_key" => $this->apiKey(),
            "domain" => $domain
        ];
        if ($firstName) $args['first_name'] = $firstName;
        if ($middleName) $args['middle_name'] = $middleName;
        if ($lastName) $args['last_name'] = $lastName;

        $query = http_build_query($args);

        // Like findEmail and findEmailFormat: a body made only of message fields
        // (LIVE: {"Message": ...} for a dead key) is an error, not a result.
        $this->messageOnlyIsFailure = true;
        try {
            $this->request($this->apiBaseUrl . "/guessformat?$query", $response);
        } finally {
            $this->messageOnlyIsFailure = false;
        }
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

        if (!$domain && !$companyName) {
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

        if (!$domain && !$companyName) {
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

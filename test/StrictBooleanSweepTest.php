<?php

namespace ZeroBounce\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ZeroBounce\SDK\ZBException;
use ZeroBounce\SDK\ZBGetFileOptions;
use ZeroBounce\SDK\ZBValidateOptions;
use ZeroBounce\SDK\ZeroBounce;
use ZeroBounce\SDK\ZeroBounceWarmup;

/** Records every request and answers each with one JSON success. */
class BoolRecordingClient extends ZeroBounce
{
    /** @var array<int, array{url: string, body: string}> */
    public $sent = array();

    public function __construct()
    {
        parent::__construct();
    }

    protected function executeHttp($method, $url, array $headers = array(), $body = null, $timeout = 120)
    {
        $this->sent[] = array('url' => $url, 'body' => (string)$body);
        return array(
            'status' => 200,
            'headers' => array('HTTP/1.1 200 OK', 'Content-Type: application/json'),
            'body' => '{"success":true,"email_batch":[],"errors":[]}',
        );
    }
}

/** The Warmup client, recording every request. */
class BoolRecordingWarmup extends ZeroBounceWarmup
{
    /** @var array<int, array{url: string, body: string}> */
    public $sent = array();

    protected function executeHttp($method, $url, array $headers = array(), $body = null, $timeout = 120)
    {
        $this->sent[] = array('url' => $url, 'body' => (string)$body);
        return array('status' => 200, 'headers' => array('Content-Type: application/json'), 'body' => '{"ok":true}');
    }
}

/**
 * Every boolean parameter of every public method is parsed strictly: 'false'
 * is never sent as true and 'junk' is rejected before a request. The
 * parameters are found by reflection, so a new boolean parameter (or a new
 * method with an options argument) fails the completeness checks until it is
 * in the sweep.
 */
class StrictBooleanSweepTest extends TestCase
{
    /** Boolean parameters, with the name the API (and the error) uses. */
    const BOOLEAN_PARAMS = array(
        'hasHeaderRow' => 'has_header_row',
        'allowPhase2' => 'allow_phase_2',
        'removeDuplicate' => 'remove_duplicate',
        'autoEnable' => 'auto_enable',
        'ignoreConflict' => 'ignore_conflict',
    );

    /** Client-side switches that choose how a request is sent, not API fields. */
    const CLIENT_SWITCHES = array('usePost');

    /** Every other parameter name of the public methods. */
    const OTHER_PARAMS = array(
        'apiKey', 'baseUrl', 'bucket', 'bulkBaseUrl', 'cFrom', 'clickPct', 'companyName', 'createdAfter',
        'createdBefore', 'dkimSelector', 'domain', 'domainColumn', 'downloadPath', 'email', 'emailAddressColumn',
        'emailContent', 'emails', 'endDate', 'engagementRuleId', 'exportBucket', 'exportPath', 'fileId', 'fileName',
        'filepath', 'firstName', 'firstNameColumn', 'fromAddress', 'fullNameColumn', 'genderColumn', 'importantPct',
        'inboxPlacementBaseUrl', 'ipAddress', 'ipAddressColumn', 'lastName', 'lastNameColumn', 'limit',
        'listEvaluatorBaseUrl', 'managedSending', 'middleName', 'middleNameColumn', 'name', 'newFromAddress',
        'offset', 'oldFromAddress', 'onboardingModel', 'options', 'page', 'pageSize', 'password', 'port',
        'providerIds', 'providers', 'replyPct', 'returnUrl', 'rule', 'ruleId', 'search', 'searchFrom', 'seedIds',
        'senderEmail', 'server', 'startDate', 'status', 'statusId', 'streamContent', 'target', 'testId', 'testType',
        'username', 'value', 'webhookUrl',
    );

    /** @var string */
    private $out;

    protected function setUp(): void
    {
        $this->out = sys_get_temp_dir() . '/zb-bool-' . getmypid() . '.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->out);
    }

    /**
     * The booleans each method with an $options argument takes there, by API
     * name, with a builder that puts one of them into valid options. Every
     * method with an $options parameter is listed, with an empty list when
     * its options hold no boolean.
     *
     * @return array<string, array<string, callable>>
     */
    private function optionBooleans()
    {
        $validate = function ($property) {
            return function ($value) use ($property) {
                $o = new ZBValidateOptions();
                $o->{$property} = $value;
                return $o;
            };
        };
        $getFile = function ($value) {
            $o = new ZBGetFileOptions();
            $o->activityData = $value;
            return $o;
        };
        $job = function ($key) {
            return function ($value) use ($key) {
                return array('file_name' => 'f.csv', 'return_url' => 'https://example.com/done', $key => $value);
            };
        };
        $jobBooleans = array('has_header_row' => $job('has_header_row'), 'remove_duplicates' => $job('remove_duplicates'));
        return array(
            'validate' => array(
                'activity_data' => $validate('activityData'),
                'verify_plus' => $validate('verifyPlus'),
                'domain_info' => $validate('domainInfo'),
                'greylist_processing' => $validate('greylistProcessing'),
            ),
            'validateBatch' => array('activity_data' => $validate('activityData'), 'verify_plus' => $validate('verifyPlus')),
            'getFile' => array('activity_data' => $getFile),
            // Scoring results have no activity data: scoringGetFile does not send it.
            'scoringGetFile' => array(),
            'validateS3File' => $jobBooleans + array(
                'allow_phase_2' => $job('allow_phase_2'),
                'remove_duplicate' => $job('remove_duplicate'),
            ),
            'scoreS3File' => $jobBooleans + array('remove_duplicate' => $job('remove_duplicate')),
            'emailFinderS3File' => $jobBooleans,
            'domainSearchS3File' => $jobBooleans,
            'validateSFTPFile' => $jobBooleans,
            'scoreSFTPFile' => $jobBooleans,
            'emailFinderSFTPFile' => $jobBooleans,
            'domainSearchSFTPFile' => $jobBooleans,
        );
    }

    /** Valid values for the other required parameters, by name. */
    private function commonArgs()
    {
        return array(
            'email' => 'a@example.com', 'emails' => array('a@example.com'),
            'filepath' => __DIR__ . '/email_file.csv', 'streamContent' => "email\na@example.com\n",
            'fileName' => 'f.csv', 'emailAddressColumn' => 1, 'domainColumn' => 1, 'firstNameColumn' => 2,
            'fileId' => 'f1', 'downloadPath' => $this->out,
            'fromAddress' => 'a@b.com', 'onboardingModel' => 'self_managed', 'engagementRuleId' => 0,
            'statusId' => 4, 'autoEnable' => true,
        );
    }

    /**
     * @return array<string, ReflectionMethod> every public request method of both clients
     */
    private function publicMethods()
    {
        $methods = array();
        foreach (array('' => ZeroBounce::class, 'Warmup::' => ZeroBounceWarmup::class) as $prefix => $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $name = $method->getName();
                if (!$method->isStatic() && strpos($name, '__') !== 0 && $name !== 'initialize') {
                    $methods[$prefix . $name] = $method;
                }
            }
        }
        return $methods;
    }

    /**
     * @return array<string, string[]> method => API names of its booleans
     */
    private function booleansByMethod()
    {
        $options = $this->optionBooleans();
        $found = array();
        foreach ($this->publicMethods() as $name => $method) {
            $names = array();
            foreach ($method->getParameters() as $param) {
                if (isset(self::BOOLEAN_PARAMS[$param->getName()])) {
                    $names[] = self::BOOLEAN_PARAMS[$param->getName()];
                }
            }
            if (isset($options[$name])) {
                $names = array_merge($names, array_keys($options[$name]));
            }
            if ($names) {
                $found[$name] = $names;
            }
        }
        return $found;
    }

    public function testEveryParameterNameIsClassified()
    {
        $known = array_merge(array_keys(self::BOOLEAN_PARAMS), self::CLIENT_SWITCHES, self::OTHER_PARAMS);
        $unknown = array();
        foreach ($this->publicMethods() as $name => $method) {
            foreach ($method->getParameters() as $param) {
                if (!in_array($param->getName(), $known, true)) {
                    $unknown[] = $name . '($' . $param->getName() . ')';
                }
            }
        }
        $this->assertSame(array(), $unknown);
    }

    public function testEveryOptionsArgumentIsListed()
    {
        $missing = array();
        foreach ($this->publicMethods() as $name => $method) {
            foreach ($method->getParameters() as $param) {
                if ($param->getName() === 'options' && !array_key_exists($name, $this->optionBooleans())) {
                    $missing[] = $name;
                }
            }
        }
        $this->assertSame(array(), $missing);
    }

    public function testTheLibraryParsesNoBooleanTheSweepDoesNotKnow()
    {
        $source = '';
        foreach (glob(dirname(__DIR__) . '/src/*.php') as $file) {
            $source .= file_get_contents($file);
        }
        preg_match_all("/(?:wireBool|strictBool|appendOptionalBool)\\([^;]*?'(\\w+)'\\)/", $source, $matches);
        $parsed = array_unique($matches[1]);
        $this->assertNotEmpty($parsed);
        $known = array();
        foreach ($this->booleansByMethod() as $names) {
            $known = array_merge($known, $names);
        }
        $this->assertSame(array(), array_values(array_diff($parsed, $known)));
    }

    /**
     * Calls $name with every parameter at a valid value, $api set to $value.
     */
    private function call($client, $warmup, $name, $api, $value)
    {
        $methods = $this->publicMethods();
        $method = $methods[$name];
        $options = $this->optionBooleans();
        $common = $this->commonArgs();
        $args = array();
        foreach ($method->getParameters() as $param) {
            $paramName = $param->getName();
            if (isset(self::BOOLEAN_PARAMS[$paramName]) && self::BOOLEAN_PARAMS[$paramName] === $api) {
                $args[] = $value;
            } elseif ($paramName === 'options' && isset($options[$name][$api])) {
                $args[] = call_user_func($options[$name][$api], $value);
            } elseif (array_key_exists($paramName, $common)) {
                $args[] = $common[$paramName];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                $this->fail($name . ' needs a value for $' . $paramName);
            }
        }
        $target = strpos($name, 'Warmup::') === 0 ? $warmup : $client;
        return $method->invokeArgs($target, $args);
    }

    /**
     * What a request sent for $api: the query, a JSON body member, or a
     * multipart or form field, as 'true'/'false'; null when not sent.
     */
    private static function sentValue(array $request, $api)
    {
        $query = array();
        parse_str((string)parse_url($request['url'], PHP_URL_QUERY), $query);
        if (array_key_exists($api, $query)) {
            return (string)$query[$api];
        }
        $body = $request['body'];
        $json = json_decode($body, true);
        if (is_array($json)) {
            if (!array_key_exists($api, $json)) {
                return null;
            }
            return is_bool($json[$api]) ? ($json[$api] ? 'true' : 'false') : (string)$json[$api];
        }
        if (preg_match('/name="' . preg_quote($api, '/') . '"\r\n(?:[^\r\n]+\r\n)*\r\n([^\r\n]*)/', $body, $m)) {
            return $m[1];
        }
        $form = array();
        parse_str($body, $form);
        return array_key_exists($api, $form) ? (string)$form[$api] : null;
    }

    public function testJunkIsRejectedAndFalseIsNeverSentAsTrue()
    {
        $client = new BoolRecordingClient();
        $client->initialize('bool-key');
        $warmup = new BoolRecordingWarmup('bool-key');
        foreach ($this->booleansByMethod() as $name => $names) {
            $recorder = strpos($name, 'Warmup::') === 0 ? $warmup : $client;
            foreach ($names as $api) {
                $label = $name . ' ' . $api;
                $before = count($recorder->sent);
                $rejected = null;
                try {
                    $this->call($client, $warmup, $name, $api, 'junk');
                } catch (ZBException $e) {
                    $rejected = $e;
                }
                $this->assertNotNull($rejected, $label . ": 'junk' was taken");
                $this->assertStringContainsString($api, $rejected->getMessage(), $label);
                $this->assertSame($before, count($recorder->sent), $label . ": 'junk' was sent");

                $error = null;
                try {
                    $this->call($client, $warmup, $name, $api, 'false');
                } catch (ZBException $e) {
                    // A result-file download answered with JSON fails after
                    // the request; a parameter that takes only true/false
                    // rejects the string before it.
                    $error = $e;
                }
                if (count($recorder->sent) === $before) {
                    $this->assertNotNull($error, $label);
                    $this->assertStringContainsString($api, $error->getMessage(), $label);
                    continue;
                }
                $sent = self::sentValue($recorder->sent[count($recorder->sent) - 1], $api);
                $this->assertSame('false', $sent, $label . ": 'false' was sent as " . var_export($sent, true));
            }
        }
    }
}

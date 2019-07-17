<?php namespace ZeroBounce\SDK;

/**
 *  A sample class
 *
 *  Use this section to define what this class is doing, the PHPDocumentator will use this
 *  to automatically generate an API documentation using this information.
 *
 * @author yourname
 */
class ZeroBounce
{

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
     * Private ctor so nobody else can instantiate it
     *
     */
    private function __construct()
    {

    }

    private $apiKey = null;

    /**
     * @param string $apiKey
     */
    public function initialize($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * @param string $email
     *
     * @throws ZBMissingEmailException
     * @throws ZBMissingApiKeyException
     */
    public function validate($email)
    {
        if (!$this->apiKey) throw new ZBMissingApiKeyException("ZeroBounce SDK is not initialized. Please call ZeroBounceSDK.initialize(context, apiKey) first");
        if (!$email) throw new ZBMissingEmailException("email is required");

    }
}
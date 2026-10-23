<?php

namespace ZeroBounce\SDK;

/**
 * Optional v2 validate / validatebatch parameters.
 */
class ZBValidateOptions
{
    /** @var int|null Validation timeout (3–60 single, 10–120 batch). */
    public $timeout;

    /** @var bool|null */
    public $activityData;

    /** @var bool|null */
    public $verifyPlus;

    /**
     * @var bool|null When true, the response adds domainWebsiteExists and
     * domainRegistrantCompanyName (website availability and WHOIS).
     */
    public $domainInfo;

    /**
     * @var bool|null When true, a greylisted address is processed again instead of
     * being returned as unknown.
     */
    public $greylistProcessing;

    /**
     * @var string|null With greylistProcessing = true, the URL the result is sent to.
     * Rejected without it.
     */
    public $callbackUrl;

    /** @var bool When true, uses form-encoded POST for /validate. */
    public $usePost = false;
}

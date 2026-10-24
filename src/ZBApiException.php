<?php

namespace ZeroBounce\SDK;

/**
 * A failed API response.
 *
 * Carries the HTTP status so callers can tell a 403 (Cloudflare) from a
 * 400/404 (API) or a 429 (Warmup rate limit). Both the validation client and
 * the Warmup client throw it.
 */
class ZBApiException extends ZBException
{
    /** @var int */
    private $statusCode;

    /**
     * @param string $message
     * @param int $statusCode
     */
    public function __construct($message = '', $statusCode = 0)
    {
        parent::__construct($message);
        $this->statusCode = (int)$statusCode;
    }

    /** @return int the HTTP status of the failed response, or 0 */
    public function getStatusCode()
    {
        return $this->statusCode;
    }
}

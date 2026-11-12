<?php

namespace ZeroBounce\SDK;

use Exception;

class ZBException extends Exception
{
    /**
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct($message = '', $code = 0, $previous = null)
    {
        parent::__construct($message, $code, $previous);
        // The trace is recorded when the exception is created, before this
        // runs; mask the keys and passwords its frames' arguments hold.
        ZBSecret::scrubTrace($this);
    }
}

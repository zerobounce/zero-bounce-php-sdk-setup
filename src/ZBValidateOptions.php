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
}

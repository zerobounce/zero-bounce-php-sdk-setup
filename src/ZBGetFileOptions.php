<?php

namespace ZeroBounce\SDK;

/**
 * Optional query parameters for bulk getfile.
 * activityData applies to validation getFile only; scoringGetFile does not send activity_data.
 */
class ZBGetFileOptions
{
    /**
     * @var string|null One of ZBDownloadType::* constants.
     */
    public $downloadType;

    /**
     * @var bool|null When not null, sent as activity_data (validation bulk only).
     */
    public $activityData;
}

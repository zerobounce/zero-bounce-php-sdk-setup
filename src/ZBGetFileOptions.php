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

    /**
     * @var bool Write the result to disk in chunks instead of holding it in
     * memory. The download lands in a temporary file beside $downloadPath and
     * is moved into place only once it is complete, so a failed download
     * leaves nothing behind. Off by default, because the non-streaming path
     * returns the body the SDK already has.
     */
    public $stream = false;
}

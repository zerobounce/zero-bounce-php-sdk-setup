<?php

namespace ZeroBounce\SDK;

/**
 * Values for bulk getfile query parameter download_type (validation and scoring).
 */
final class ZBDownloadType
{
    const PHASE_1 = 'phase_1';
    const PHASE_2 = 'phase_2';
    const COMBINED = 'combined';

    private function __construct()
    {
    }
}

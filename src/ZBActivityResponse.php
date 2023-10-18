<?php

namespace ZeroBounce\SDK;

class ZBActivityResponse extends ZBResponse
{
    /**
     * @var bool|null
     */
    public $found;

    /**
     * @var int|null
     */
    public $activeInDays;

    public function __toString()
    {
        return "ZBActivityResponse{" .
            "found=" . $this->found . ", " .
            "activeInDays=" . $this->activeInDays . "}";
    }
}

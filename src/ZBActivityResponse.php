<?php

namespace ZeroBounce\SDK;

class ZBActivityResponse extends ZBResponse
{
    /**
     * @var bool
     */
    public $found;

    /**
     * @var int
     */
    public $activeInDays;

    public function __toString()
    {
        return "ZBActivityResponse{" .
            "found=" . $this->found . ", " .
            "activeInDays=" . $this->activeInDays . "}";
    }
}
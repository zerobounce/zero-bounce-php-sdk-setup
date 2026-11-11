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

    protected function boolFields()
    {
        return array('found');
    }

    public function __toString()
    {
        return "ZBActivityResponse{" .
            "found=" . $this->boolField($this->found) . ", " .
            "activeInDays=" . $this->activeInDays . "}";
    }
}

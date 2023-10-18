<?php

namespace ZeroBounce\SDK;

class ZBGetCreditsResponse extends ZBResponse
{
    /**
     * @var string|null
     */
    public $credits;

    public function getClassKey($key)
    {
        if($key == "Credits") return "credits";
        return parent::getClassKey($key);
    }

    public function __toString()
    {
        return "ZBGetCreditsResponse{credits=".$this->stringField($this->credits)."}";
    }


}

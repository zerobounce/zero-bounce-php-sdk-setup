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

    protected function fieldTypes()
    {
        return array(
            'found' => array('bool', 'string'),
            'active_in_days' => array('string'),
            'active_first_seen' => array('string'),
        );
    }

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

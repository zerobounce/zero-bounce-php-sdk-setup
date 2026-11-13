<?php

namespace ZeroBounce\SDK;

class ZBGetCreditsResponse extends ZBResponse
{
    /**
     * The credit balance as the API sends it: a string holding an integer
     * ("-1" for a dead key), as the live API sends it, or an int (zerobounce.net's
     * example is {"Credits":2375323}).
     *
     * @var string|int|null
     */
    public $credits;

    public function getClassKey($key)
    {
        if ($key === "Credits") {
            return "credits";
        }
        return parent::getClassKey($key);
    }

    protected function fieldTypes()
    {
        return array(
            'Credits' => array('string', 'int'),
        );
    }

    protected function requiredFields(array $json)
    {
        return array('Credits');
    }

    /**
     * Credits is an integer, sent as a JSON string ("-1" for a dead key) or a
     * JSON integer: "abc", "5.0", 5.5 or true is the wrong shape.
     */
    protected function checkValues(array $json)
    {
        if (is_string($json['Credits']) && !preg_match('/\A-?[0-9]+\z/', $json['Credits'])) {
            throw self::wrongField('Credits is not an integer');
        }
    }

    public function __toString()
    {
        return "ZBGetCreditsResponse{credits=" . $this->stringField($this->credits) . "}";
    }
}

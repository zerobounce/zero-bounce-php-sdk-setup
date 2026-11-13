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
        if ($key === "Credits") {
            return "credits";
        }
        return parent::getClassKey($key);
    }

    protected function fieldTypes()
    {
        return array(
            'Credits' => array('string'),
        );
    }

    protected function requiredFields(array $json)
    {
        return array('Credits');
    }

    /**
     * Credits is documented as a JSON string holding an integer ("-1" for a
     * dead key): a bare number, "abc" or "5.0" is the wrong shape.
     */
    protected function checkValues(array $json)
    {
        if (!preg_match('/\A-?[0-9]+\z/', $json['Credits'])) {
            throw self::wrongField('Credits is not an integer');
        }
    }

    public function __toString()
    {
        return "ZBGetCreditsResponse{credits=" . $this->stringField($this->credits) . "}";
    }
}

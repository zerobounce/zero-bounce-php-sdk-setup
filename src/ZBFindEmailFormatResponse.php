<?php

namespace ZeroBounce\SDK;

class ZBFindEmailFormatResponse extends ZBResponse
{
    /**
     * Domain guess is being performed for.
     * @var string|null
     */
    public $domain;

    /**
     * The company associated with the domain.
     * @var string|null
     */
    public $companyName;

    /**
     * Most likely email format.
     * @var string|null
     */
    public $format;

    /**
     * Confidence in guess.
     * @var string|null
     */
    public $confidence;

    /**
     * Suggestion for guess.
     * @var string|null
     */
    public $didYouMean;

    /**
     * Reason for error.
     * @var string|null
     */
    public $failureReason;

    /**
     * Other possible guess formats.
     * @var array
     */
    public $otherDomainFormats = [];

    protected function fieldTypes()
    {
        return array(
            'domain' => array('string'),
            'company_name' => array('string'),
            'format' => array('string'),
            'confidence' => array('string'),
            'did_you_mean' => array('string'),
            'failure_reason' => array('string'),
            'other_domain_formats' => array('array'),
        );
    }

    protected function itemShapes()
    {
        return array(
            'other_domain_formats' => array('format' => array('string'), 'confidence' => array('string')),
        );
    }

    public function getValue($classKey, $value)
    {
        if ($classKey === "confidence") {
            return ZBValidateConfidence::getByValue($value) ?? ZBValidateConfidence::__default;
        }
        return parent::getValue($classKey, $value);
    }

    public function __toString()
    {
        return "ZBFindEmailFormatResponse{" .
            "domain=" . $this->stringField($this->domain) . ", " .
            "companyName=" . $this->stringField($this->companyName) . ", " .
            "format=" . $this->stringField($this->format) . ", " .
            "confidence=" . $this->stringField($this->confidence) . ", " .
            "didYouMean=" . $this->stringField($this->didYouMean) . ", " .
            "failureReason=" . $this->stringField($this->failureReason) . ", " .
            "otherDomainFormats=" . $this->arrayField($this->otherDomainFormats) . "}";
    }
}
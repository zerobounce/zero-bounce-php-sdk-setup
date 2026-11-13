<?php

namespace ZeroBounce\SDK;

class ZBGuessFormatResponse extends ZBResponse
{
    /**
     * The email address being guessed.
     * @var string|null
     */
    public $email;

    /**
     * Domain guess is being performed for.
     * @var string|null
     */
    public $domain;

    /**
     * Most likely email format.
     * @var string|null
     */
    public $format;

    /**
     * Guess status.
     * @var string|null
     * @see ZBValidateStatus
     */
    public $status;

    /**
     * Guess substatus.
     * @var string|null
     * @see ZBValidateSubStatus
     */
    public $subStatus;

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
            'email' => array('string'),
            'domain' => array('string'),
            'format' => array('string'),
            'status' => array('string'),
            'sub_status' => array('string'),
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
        if ($classKey === "status") {
            return ZBValidateStatus::getByValue($value) ?? ZBValidateStatus::__default;
        }
        if ($classKey === "sub_status") {
            return ZBValidateSubStatus::getByValue($value) ?? ZBValidateSubStatus::__default;
        }
        return parent::getValue($classKey, $value);
    }

    public function __toString()
    {
        return "ZBGuessFormatResponse{" .
            "email=" . $this->stringField($this->email) . ", " .
            "domain=" . $this->stringField($this->domain) . ", " .
            "format=" . $this->stringField($this->format) . ", " .
            "status=" . $this->stringField($this->status) . ", " .
            "subStatus=" . $this->stringField($this->subStatus) . ", " .
            "confidence=" . $this->stringField($this->confidence) . ", " .
            "didYouMean=" . $this->stringField($this->didYouMean) . ", " .
            "failureReason=" . $this->stringField($this->failureReason) . ", " .
            "otherDomainFormats=" . $this->arrayField($this->otherDomainFormats) . "}";
    }
}

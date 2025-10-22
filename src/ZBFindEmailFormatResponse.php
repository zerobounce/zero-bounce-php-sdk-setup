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

    public function getValue($classKey, $value)
    {
        if ($classKey == "confidence") return ZBValidateConfidence::getByValue($value) ?? ZBValidateConfidence::__default;
        return parent::getValue($classKey, $value);
    }

    public function __toString()
    {
        return "ZBGuessFormatResponse{" .
            "domain=" . $this->domain . ", " .
            "companyName=" . $this->companyName . ", " .
            "format=" . $this->format . ", " .
            "confidence=" . $this->confidence . ", " .
            "didYouMean=" . $this->didYouMean . ", " .
            "failureReason=" . $this->failureReason . ", " .
            "}";
    }
}
<?php

namespace ZeroBounce\SDK;

class ZBGuessFormatResponse extends ZBResponse
{
    /**
     * The email address beign guessed.
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

    public function getValue($classKey, $value)
    {
        if ($classKey == "status") return ZBValidateStatus::getByValue($value) ?? ZBValidateStatus::__default;
        if ($classKey == "sub_status") return ZBValidateSubStatus::getByValue($value) ?? ZBValidateSubStatus::__default;
        return parent::getValue($classKey, $value);
    }

    public function __toString()
    {
        return "ZBValidateResponse{" .
            "email=" . $this->email . ", " .
            "status=" . $this->domain . ", " .
            "format=" . $this->format . ", " .
            "status=" . $this->status . ", " .
            "subStatus=" . $this->subStatus . ", " .
            "confidence=" . $this->confidence . ", " .
            "didYouMean=" . $this->didYouMean . ", " .
            "failureReason=" . $this->failureReason . ", " .
            "}";
    }
}

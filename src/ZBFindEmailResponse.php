<?php

namespace ZeroBounce\SDK;

class ZBFindEmailResponse extends ZBResponse
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
     * The company associated with the domain.
     * @var string|null
     */
    public $companyName;

    /**
     * Confidence in guess.
     * @var string|null
     */
    public $emailConfidence;

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

    public function getValue($classKey, $value)
    {
        if ($classKey === "emailConfidence") {
            return ZBValidateConfidence::getByValue($value) ?? ZBValidateConfidence::__default;
        }
        return parent::getValue($classKey, $value);
    }

    public function __toString()
    {
        return "ZBFindEmailResponse{" .
            "email=" . $this->stringField($this->email) . ", " .
            "domain=" . $this->stringField($this->domain) . ", " .
            "companyName=" . $this->stringField($this->companyName) . ", " .
            "emailConfidence=" . $this->stringField($this->emailConfidence) . ", " .
            "didYouMean=" . $this->stringField($this->didYouMean) . ", " .
            "failureReason=" . $this->stringField($this->failureReason) . "}";
    }
}
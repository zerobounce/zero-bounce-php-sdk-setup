<?php

namespace ZeroBounce\SDK;

class ZBBatchValidateResponse extends ZBResponse
{
    /**
     * The batch of email addresses you are validating.
     * @var array
     */
    public $emailBatch = [];

    /**
     * @var array
     */
    public $errors = [];

    public function __toString()
    {
        $result = "ZBBatchValidateResponse[";
        foreach ($this->emailBatch as $email)
            $result .= $this->__toStringEmail($email);
        $result .= "]";
        return $result;
    }

    private function __toStringEmail($email)
    {
        return "{" .
            "address=" . $email->address . ", " .
            "status=" . $email->status . ", " .
            "subStatus=" . $email->subStatus . ", " .
            "account=" . $this->stringField($email->account) . ", " .
            "domain=" . $this->stringField($email->domain) . ", " .
            "didYouMean=" . $this->stringField($email->didYouMean) . ", " .
            "domainAgeDays=" . $this->stringField($email->domainAgeDays) . ", " .
            "freeEmail=" . $email->freeEmail . ", " .
            "mxFound=" . $email->mxFound . ", " .
            "mxRecord=" . $this->stringField($email->mxRecord) . ", " .
            "smtpProvider=" . $this->stringField($email->smtpProvider) . ", " .
            "firstName=" . $this->stringField($email->firstName) . ", " .
            "lastName=" . $this->stringField($email->lastName) . ", " .
            "gender=" . $this->stringField($email->gender) . ", " .
            "city=" . $this->stringField($email->city) . ", " .
            "region=" . $this->stringField($email->region) . ", " .
            "zipCode=" . $this->stringField($email->zipcode) . ", " .
            "country=" . $this->stringField($email->country) . ", " .
            "processedAt=" . $this->stringField($email->processedAt) . ", " .
            "error=" . $this->stringField($email->error) . ", " .
            "}";
    }
}

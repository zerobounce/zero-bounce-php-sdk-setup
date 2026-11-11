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

    public function getValue($classKey, $value)
    {
        if ($classKey === 'emailBatch' && is_array($value)) {
            $batch = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $obj = new ZBValidateResponse();
                    $obj->Deserialize($item);
                    $batch[] = $obj;
                } else {
                    $batch[] = $item;
                }
            }
            return $batch;
        }
        return parent::getValue($classKey, $value);
    }

    public function __toString()
    {
        $emails = array();
        foreach ((array)$this->emailBatch as $email) {
            $emails[] = $email instanceof ZBValidateResponse ? (string)$email : json_encode($email);
        }
        return "ZBBatchValidateResponse{emailBatch=[" . implode(", ", $emails) . "]"
            . ", errors=" . $this->arrayField($this->errors) . "}";
    }
}

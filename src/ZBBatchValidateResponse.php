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

    /**
     * @throws ZBApiException (status 200) when email_batch or errors is not an
     *     array of objects: JSON of the wrong shape for this call (E7)
     */
    public function getValue($classKey, $value)
    {
        if ($classKey === 'emailBatch' || $classKey === 'errors') {
            if (!is_array($value)) {
                throw self::wrongShape();
            }
            foreach ($value as $item) {
                if (!is_array($item)) {
                    throw self::wrongShape();
                }
            }
        }
        if ($classKey === 'emailBatch') {
            $batch = [];
            foreach ($value as $item) {
                $obj = new ZBValidateResponse();
                $obj->Deserialize($item);
                $batch[] = $obj;
            }
            return $batch;
        }
        return parent::getValue($classKey, $value);
    }

    private static function wrongShape()
    {
        return new ZBApiException(
            'ZeroBounce returned JSON of an unexpected shape: email_batch and errors must be arrays of objects.',
            200
        );
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

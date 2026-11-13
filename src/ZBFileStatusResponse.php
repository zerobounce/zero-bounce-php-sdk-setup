<?php

namespace ZeroBounce\SDK;

class ZBFileStatusResponse extends ZBResponse
{
    /**
     * @var bool|null
     */
    public $success;

    /**
     * @var string|null
     */
    public $message;

    /**
     * @var string|null
     */
    public $fileStatus;

    /**
     * @var string|null
     */
    public $filePhase2Status;

    /**
     * @var string|null
     */
    public $errorReason;

    /**
     * @var string|null
     */
    public $fileId;

    /**
     * @var string|null
     */
    public $fileName;

    /**
     * @var string|null
     */
    public $uploadDate;

    /**
     * @var string|null
     */
    public $completePercentage;

    /**
     * @var string|null
     */
    public $returnUrl;

    protected function fieldTypes()
    {
        return array(
            'success' => array('bool', 'string'),
            'message' => array('string', 'strings'),
            'file_id' => array('string'),
            'file_name' => array('string'),
            'upload_date' => array('string'),
            'file_status' => array('string'),
            'file_phase_2_status' => array('string'),
            'complete_percentage' => array('string'),
            'error_reason' => array('string'),
            'return_url' => array('string'),
        );
    }

    /**
     * The public type of message is one string, so a list of strings is joined with newlines (standard.md section 3).
     */
    public function getValue($classKey, $value)
    {
        if ($classKey == "message" && is_array($value)) {
            return implode("\n", $value);
        }
        return parent::getValue($classKey, $value);
    }

    protected function boolFields()
    {
        return array('success');
    }

    public function __toString()
    {
        return "ZBFileStatusResponse{" .
            "success=" . $this->boolField($this->success) .
            ", message=" . $this->stringField($this->message) .
            ", fileStatus=" . $this->stringField($this->fileStatus) .
            ", filePhase2Status=" . $this->stringField($this->filePhase2Status) .
            ", errorReason=" . $this->stringField($this->errorReason) .
            ", fileId=" . $this->stringField($this->fileId) .
            ", fileName=" . $this->stringField($this->fileName) .
            ", uploadDate=" . $this->stringField($this->uploadDate) .
            ", completePercentage=" . $this->stringField($this->completePercentage) .
            ", returnUrl=" . $this->stringField($this->returnUrl) .
            "}";
    }
}

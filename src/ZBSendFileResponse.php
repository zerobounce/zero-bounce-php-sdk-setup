<?php

namespace ZeroBounce\SDK;

class ZBSendFileResponse extends ZBResponse
{
    /**
     * @var bool|null
     */
    public $success;

    /**
     * @var array
     */
    public $message = [];

    /**
     * @var string|null
     */
    public $fileName;

    /**
     * @var string|null
     */
    public $fileId;

    protected function fieldTypes()
    {
        return array(
            'success' => array('bool', 'string'),
            'message' => array('string', 'strings'),
            'file_name' => array('string'),
            'file_id' => array('string'),
        );
    }

    public function getValue($classKey, $value)
    {
        if ($classKey == "message") {
            return is_array($value) ? $value : array($value);
        }
        return parent::getValue($classKey, $value);
    }

    protected function boolFields()
    {
        return array('success');
    }

    public function __toString()
    {
        return "ZBSendFileResponse{" .
            "success=" . $this->boolField($this->success) .
            ", message=" . $this->arrayField($this->message) .
            ", fileName=" . $this->stringField($this->fileName) .
            ", fileId=" . $this->stringField($this->fileId) .
            "}";
    }
}

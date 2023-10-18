<?php

namespace ZeroBounce\SDK;

class ZBDeleteFileResponse extends ZBResponse
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

    public function getValue($classKey, $value)
    {
        if ($classKey == "message") {
            return is_array($value) ? $value : array($value);
        }
        return parent::getValue($classKey, $value);
    }

    public function __toString()
    {
        return "ZBDeleteFileResponse{".
            "success=".$this->success.
            ", message=".$this->arrayField($this->message).
            ", fileName=".$this->stringField($this->fileName).
            ", fileId=".$this->stringField($this->fileId).
            '}';
    }
}

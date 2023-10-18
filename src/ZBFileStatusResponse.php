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

    public function __toString()
    {
        return "ZBFileStatusResponse{" .
            "success=" . $this->success .
            ", message=" . $this->stringField($this->message) .
            ", fileStatus=" . $this->stringField($this->fileStatus) .
            ", fileId=" . $this->stringField($this->fileId) .
            ", fileName=" . $this->stringField($this->fileName) .
            ", uploadDate=" . $this->stringField($this->uploadDate) .
            ", completePercentage=" . $this->stringField($this->completePercentage) .
            ", returnUrl=" . $this->stringField($this->returnUrl) .
            "}";
    }


}

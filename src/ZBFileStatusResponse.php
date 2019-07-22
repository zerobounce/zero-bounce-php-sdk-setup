<?php


namespace ZeroBounce\SDK;


class ZBFileStatusResponse extends ZBResponse
{
    /**
     * @var bool
     */
    public $success;

    /**
     * @var string
     */
    public $message;

    /**
     * @var string
     */
    public $fileStatus;

    /**
     * @var string
     */
    public $fileId;

    /**
     * @var string
     */
    public $fileName;

    /**
     * @var string
     */
    public $uploadDate;

    /**
     * @var string
     */
    public $completePercentage;

    /**
     * @var string
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
<?php

namespace ZeroBounce\SDK;

class ZBGetFileResponse extends ZBResponse
{
    /**
     * @var string
     */
    public $localFilePath;

    public function __toString()
    {
        return "ZBGetFileResponse{localFilePath=".$this->stringField($this->localFilePath)."}";
    }
}
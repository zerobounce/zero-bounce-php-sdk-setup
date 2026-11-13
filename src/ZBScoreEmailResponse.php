<?php

namespace ZeroBounce\SDK;

class ZBScoreEmailResponse extends ZBResponse
{
    /**
     * @var string|null
     */
    public $email;

    /**
     * @var int|null
     */
    public $score;

    protected function fieldTypes()
    {
        return array(
            'email' => array('string'),
            'score' => array('int'),
        );
    }

    public function __toString()
    {
        return "ZBScoreEmailResponse{" .
            "email=" . $this->email . ", " .
            "score=" . $this->score . "}";
    }
}

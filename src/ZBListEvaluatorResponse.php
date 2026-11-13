<?php

namespace ZeroBounce\SDK;

/**
 * Response for GET /listevaluator/{file_id}/.
 */
class ZBListEvaluatorResponse extends ZBResponse
{
    /** @var string|null */
    public $fileId;

    /** @var string|null "processing" until the evaluation is done, then "finished". */
    public $status;

    /** @var int|null 0 to 100. */
    public $progress;

    /** @var float|null */
    public $invalidPercentage;

    /** @var float|null */
    public $catchAllPercentage;

    /** @var float|null */
    public $activityDataPercentage;

    /** @var bool|null Whether abuse addresses were found. */
    public $abuse;

    /** @var bool|null Whether do-not-mail addresses were found. */
    public $doNotMail;

    /** @var bool|null Whether spam traps were found. */
    public $spamTrap;

    /** @var float|null */
    public $totalRiskyPercentage;

    /** @var string|null */
    public $errorMessage;

    /** @var array<string, mixed> Everything the API sent, including fields this class does not name. */
    public $data = array();

    protected function fieldTypes()
    {
        return array(
            'file_id' => array('string'),
            'status' => array('string'),
            'progress' => array('number'),
            'invalid_percentage' => array('number'),
            'catch_all_percentage' => array('number'),
            'activity_data_percentage' => array('number'),
            'abuse' => array('bool', 'string'),
            'do_not_mail' => array('bool', 'string'),
            'spam_trap' => array('bool', 'string'),
            'total_risky_percentage' => array('number'),
            'error_message' => array('string'),
        );
    }

    public function Deserialize($json)
    {
        parent::Deserialize($json);
        $decoded = is_array($json) ? $json : json_decode((string)$json, true);
        $this->data = is_array($decoded) ? $decoded : array();
    }

    protected function boolFields()
    {
        return array('abuse', 'doNotMail', 'spamTrap');
    }

    public function __toString()
    {
        return "ZBListEvaluatorResponse{fileId=" . $this->stringField($this->fileId)
            . ", status=" . $this->stringField($this->status)
            . ", progress=" . $this->stringField($this->progress)
            . ", invalidPercentage=" . $this->stringField($this->invalidPercentage)
            . ", catchAllPercentage=" . $this->stringField($this->catchAllPercentage)
            . ", activityDataPercentage=" . $this->stringField($this->activityDataPercentage)
            . ", abuse=" . $this->boolField($this->abuse)
            . ", doNotMail=" . $this->boolField($this->doNotMail)
            . ", spamTrap=" . $this->boolField($this->spamTrap)
            . ", totalRiskyPercentage=" . $this->stringField($this->totalRiskyPercentage)
            . ", errorMessage=" . $this->stringField($this->errorMessage)
            . ", data=" . $this->arrayField($this->data) . "}";
    }
}

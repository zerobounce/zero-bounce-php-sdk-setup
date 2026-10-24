<?php

namespace ZeroBounce\SDK;

/**
 * Captures arbitrary JSON API responses (filters, list evaluator).
 */
class ZBRawJsonResponse extends ZBResponse
{
    /** @var array<string, mixed> */
    public $data = array();

    /**
     * @param string|array $json
     * @throws ZBException
     */
    public function Deserialize($json)
    {
        if (is_string($json)) {
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                throw new ZBException(sprintf('Invalid response "%s".', $json));
            }
            $this->data = $decoded;
            return;
        }
        if (!is_array($json)) {
            throw new ZBException('Deserialize expects a JSON string or array.');
        }
        $this->data = $json;
    }
}

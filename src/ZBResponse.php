<?php

namespace ZeroBounce\SDK;

class ZBResponse
{
    /**
     * @param string|array $json
     * @throws ZBException
     */
    public function Deserialize($json)
    {
        if (is_string($json)) {
            $decodedJson = json_decode($json, true);
            if (!\is_array($decodedJson)) {
                throw new ZBException(sprintf('Invalid response "%s".', $json));
            }
            $json = $decodedJson;
        }

        if (!\is_array($json)) {
            throw new ZBException('Deserialize expects a JSON string or array, null or invalid type given.');
        }

        foreach ($json as $key => $value) {
            $classKey = $this->getClassKey($key);
            if (!property_exists($this, $classKey)) {
                continue;
            }
            $classValue = $this->getValue($classKey, $value);
            $this->{$classKey} = $classValue;
        }
    }

    public function getClassKey($key)
    {
        return self::underlinesToCamelCase($key);
    }

    public function getValue($classKey, $value)
    {
        if (in_array($classKey, $this->boolFields(), true)) {
            return self::parseBool($value);
        }
        return $value;
    }

    /**
     * The properties documented as bool|null. The API sends some flags as JSON
     * booleans and others as "true"/"false" strings; kept as strings, "false"
     * would read as true.
     *
     * @return string[]
     */
    protected function boolFields()
    {
        return array();
    }

    /**
     * A flag from the API as a bool: true/false, "true"/"false" in any case,
     * or 1/0. Anything else (null, "unknown", "") is null.
     *
     * @param mixed $value
     * @return bool|null
     */
    public static function parseBool($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return $value === 1;
        }
        if (is_string($value)) {
            $text = strtolower(trim($value));
            if ($text === 'true' || $text === '1') {
                return true;
            }
            if ($text === 'false' || $text === '0') {
                return false;
            }
        }
        return null;
    }

    /**
     * @param bool|null $value
     * @return string "true", "false" or "NULL"
     */
    public function boolField($value)
    {
        if ($value === null) {
            return "NULL";
        }
        return $value ? "true" : "false";
    }

    protected final static function underlinesToCamelCase($string, $capitalizeFirstCharacter = false)
    {
        $str = str_replace('_', '', ucwords($string, '_'));
        if (!$capitalizeFirstCharacter) {
            $str = lcfirst($str);
        }
        return $str;
    }

    public function __toString()
    {
        return static::class . "{}";
    }

    public function stringField($value)
    {
        if ($value) {
            return "'" . $value . "'";
        }
        return "NULL";
    }

    public function arrayField($value)
    {
        if ($value) {
            return json_encode($value);
        }
        return "NULL";
    }

    /**
     * Return the response as an associative array (e.g. for JSON or array access).
     * Nested response objects and arrays of response objects are converted recursively.
     *
     * @return array<string, mixed>
     */
    public function toArray()
    {
        $result = [];
        foreach (get_object_vars($this) as $key => $value) {
            $result[$key] = $this->valueToArray($value);
        }
        return $result;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    protected function valueToArray($value)
    {
        if ($value instanceof ZBResponse) {
            return $value->toArray();
        }
        if (is_array($value)) {
            return array_map(function ($item) {
                return $this->valueToArray($item);
            }, $value);
        }
        return $value;
    }
}

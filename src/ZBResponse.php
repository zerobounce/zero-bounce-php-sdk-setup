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

        $this->checkShape($json);

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

    /**
     * The JSON type every documented field may arrive as, by JSON key: a list
     * of 'string', 'bool', 'int', 'number' or 'array'. Field types are part of
     * the response shape (E7): a field of another JSON type is an API error
     * with status 200, never coerced or defaulted. null is accepted for every
     * field not in requiredFields().
     *
     * @return array<string, string[]>
     */
    protected function fieldTypes()
    {
        return array();
    }

    /**
     * The fields that must be present and not null.
     *
     * @param array $json the decoded response
     * @return string[]
     */
    protected function requiredFields(array $json)
    {
        return array();
    }

    /**
     * The rows of array fields, by JSON key: a response object to check each
     * row with, or the field types of a plain row.
     *
     * @return array<string, ZBResponse|array<string, string[]>>
     */
    protected function itemShapes()
    {
        return array();
    }

    /**
     * Checks beyond the JSON type (e.g. a string that must hold an integer).
     *
     * @param array $json
     * @throws ZBApiException
     */
    protected function checkValues(array $json)
    {
    }

    /**
     * @param array $json the decoded response
     * @param string $path where $json sits in the response, for the message
     * @throws ZBApiException (status 200) naming the field, never its value
     */
    public function checkShape(array $json, $path = '')
    {
        foreach ($this->requiredFields($json) as $name) {
            if (!isset($json[$name])) {
                throw self::wrongField($path . $name . ' is missing');
            }
        }
        self::checkTypes($json, $this->fieldTypes(), $path);
        foreach ($this->itemShapes() as $name => $shape) {
            if (!isset($json[$name]) || !is_array($json[$name])) {
                continue;
            }
            foreach ($json[$name] as $index => $item) {
                $row = $path . $name . '[' . $index . ']';
                $where = $row . '.';
                if (!is_array($item) || (count($item) > 0 && self::isList($item))) {
                    throw self::wrongField($row . ' is not an object');
                }
                if ($shape instanceof ZBResponse) {
                    $shape->checkShape($item, $where);
                } else {
                    self::checkTypes($item, $shape, $where);
                }
            }
        }
        $this->checkValues($json);
    }

    /**
     * @param array $json
     * @param array<string, string[]> $types
     * @param string $path
     * @throws ZBApiException
     */
    private static function checkTypes(array $json, array $types, $path)
    {
        foreach ($types as $name => $kinds) {
            if (!array_key_exists($name, $json) || $json[$name] === null) {
                continue;
            }
            if (!self::jsonTypeOk($json[$name], $kinds)) {
                throw self::wrongField($path . $name . ' has the wrong type');
            }
        }
    }

    /**
     * @param mixed $value a json_decode(..., true) value
     * @param string[] $kinds
     * @return bool
     */
    public static function jsonTypeOk($value, array $kinds)
    {
        foreach ($kinds as $kind) {
            if (($kind === 'string' && is_string($value))
                || ($kind === 'bool' && is_bool($value))
                || ($kind === 'int' && is_int($value))
                || ($kind === 'number' && (is_int($value) || is_float($value)))
                || ($kind === 'array' && is_array($value) && self::isList($value))
                || ($kind === 'strings' && is_array($value) && self::isList($value)
                    && count(array_filter($value, 'is_string')) === count($value))) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $value
     * @return bool whether $value is a JSON array (keys 0..n-1); {} decodes as one too
     */
    private static function isList(array $value)
    {
        return $value === array() || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @param string $problem
     * @return ZBApiException
     */
    protected static function wrongField($problem)
    {
        return new ZBApiException(
            'ZeroBounce returned JSON of an unexpected shape for this call: ' . $problem . '.',
            200
        );
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

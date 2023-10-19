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

        foreach ($json as $key => $value) {
            $classKey = $this->getClassKey($key);
            //echo "Deserialize json key=" . $key . ", class key=" . $classKey . "\n";
            if (!property_exists($this, $classKey)) continue;

            $classValue = $this->getValue($classKey, $value);
            //print "Deserialize class key=" . $classKey . ", value=" . $classValue . "\n";
            $this->{$classKey} = $classValue;
        }

        //return $classInstance;
    }

    public function getClassKey($key)
    {
        return self::underlinesToCamelCase($key);
    }

    public function getValue($classKey, $value)
    {
        return $value;
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
        $className = get_called_class();
        return $className . "{}";
    }

    public function stringField($value) {
        if($value) return "'".$value."'";
        return "NULL";
    }

    public function arrayField($value) {
        if($value) return json_encode($value);
        return "NULL";
    }
}

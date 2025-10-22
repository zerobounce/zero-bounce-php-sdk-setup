<?php

namespace ZeroBounce\SDK;

class ZBBaseUrl extends BasicEnum
{
    const __default = self::API_DEFAULT_URL;
    
    const API_DEFAULT_URL = 'https://api.zerobounce.net/v2';
    const API_USA_URL = 'https://api-us.zerobounce.net/v2';
    const API_EU_URL = 'https://api-eu.zerobounce.net/v2';
    
    public static function getByValue($value)
    {
        $value = parent::getByValue($value);
        
        if (!$value) {
            return self::__default;
        }
        
        return $value;
    }
}
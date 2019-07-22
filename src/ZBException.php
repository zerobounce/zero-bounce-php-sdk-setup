<?php namespace ZeroBounce\SDK;

use Exception;

class ZBException extends Exception
{
}

class ZBMissingApiKeyException extends ZBException
{
}

class ZBMissingParameterException extends ZBException
{
}

class ZBApiException extends ZBException
{
}

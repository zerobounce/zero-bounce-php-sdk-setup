<?php namespace ZeroBounce\SDK;

use Exception;

class ZBException extends Exception
{

}

class ZBMissingApiKeyException extends ZBException {}

class ZBMissingEmailException extends ZBException {

}

<?php namespace ZeroBounce\SDK;

var_dump(get_declared_classes());

ZeroBounce::Instance()->initialize("2c2ba7ab280b4c529ec672cb52adb557");

try {
    ZeroBounce::Instance()->validate("andrei.tatomir@mountsoftware.ro");
} catch (ZBMissingApiKeyException $e) {
} catch (ZBMissingEmailException $e) {
}





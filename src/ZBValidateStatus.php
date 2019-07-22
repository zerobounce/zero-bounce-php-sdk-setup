<?php

namespace ZeroBounce\SDK;

class ZBValidateStatus extends BasicEnum
{
    const __default = self::None;

    const None = "";
    const Valid = "valid";
    const Invalid = "invalid";
    const CatchAll = "catch-all";
    const Unknown = "unknown";
    const Spamtrap = "spamtrap";
    const Abuse = "abuse";
    const DoNotMail = "do_not_mail";
}
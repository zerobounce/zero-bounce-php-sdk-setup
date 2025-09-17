<?php

namespace ZeroBounce\SDK;

class ZBValidateConfidence extends BasicEnum
{
    const __default = self::None;

    const None = "";
    const Low = "low";
    const Medium = "medium";
    const High = "high";
    const Undetermined = "undetermined";
    const Unknown = "unknown";
}

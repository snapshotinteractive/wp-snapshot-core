<?php

namespace Snapshot\Security\TwoFactor;

use DateTimeImmutable;
use Snapshot\Vendor\Psr\Clock\ClockInterface;

class Clock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable;
    }
}

<?php

use Snapshot\Security\Shield;

function snap_security(): Shield
{
    return Shield::instance();
}

function snap_security_log(string $event, string $message = '', array $context = []): int
{
    return Shield::instance()->log()->log($event, $message, $context);
}

Shield::boot();

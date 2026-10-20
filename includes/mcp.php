<?php

use Snapshot\Mcp\Mcp;

function snap_mcp(): Mcp
{
    return Mcp::instance();
}

Mcp::instance()->boot();

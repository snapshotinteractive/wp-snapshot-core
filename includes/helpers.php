<?php

function snap_filter(string $hook, $value)
{
    return function_exists('apply_filters') ? apply_filters($hook, $value) : $value;
}

<?php

use Snapshot\System\Panel;

function snap_system(): Panel
{
    return Panel::instance();
}

function snap_system_setting(string $key)
{
    return Panel::instance()->settings()->get($key);
}

add_filter('snap/security/event_types', fn (array $types) => $types + snap_activity_event_types());

add_action('after_setup_theme', fn () => Panel::instance()->boot(), 5);

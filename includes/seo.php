<?php

use Snapshot\Seo\Context;
use Snapshot\Seo\Engine;

function snap_seo(): Engine
{
    return Engine::instance();
}

function snap_seo_editor_enabled(): bool
{
    return Engine::instance()->modules()->on('editor');
}

function snap_seo_breadcrumbs(): array
{
    return Engine::instance()->graph()->trail(Context::current());
}

add_filter('snap/security/event_types', fn (array $types) => $types + [
    'seo.settings_changed' => ['severity' => 'notice', 'label' => 'SEO settings changed'],
    'seo.module_changed' => ['severity' => 'warning', 'label' => 'SEO module switched'],
    'seo.redirect_changed' => ['severity' => 'info', 'label' => 'Redirect added or removed'],
]);

add_action('acf/init', function () {
    if (function_exists('acf_add_local_field_group') && snap_seo_editor_enabled()) {
        foreach (snap_seo_field_groups() as $group) {
            acf_add_local_field_group($group);
        }
    }
});

Engine::boot();

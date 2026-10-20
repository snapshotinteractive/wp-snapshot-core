<?php

namespace Snapshot;

class Plugin
{
    public static function register(): void
    {
        Updater::register();

        add_action('admin_notices', [self::class, 'notices']);
        add_action('network_admin_notices', [self::class, 'notices']);
    }

    public static function notices(): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        $messages = [];

        if (! Core::loaded()) {
            $messages[] = __('Snapshot Core is active, but the active theme does not load it, so security, SEO and the Snapshot panel are off. It needs a theme built from the Snapshot starter.', 'snapshot-core');
        }

        if (! is_file(Core::path('vendor-prefixed/autoload.php')) || ! Core::built()) {
            $messages[] = __('Snapshot Core is missing its built files. Install it from the release zip on GitHub, not from a copy of the source.', 'snapshot-core');
        }

        foreach ($messages as $message) {
            printf('<div class="notice notice-warning"><p>%s</p></div>', esc_html($message));
        }
    }
}

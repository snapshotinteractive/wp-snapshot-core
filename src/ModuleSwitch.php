<?php

namespace Snapshot;

use Snapshot\Security\Modules as SecurityModules;
use Snapshot\Security\Schema;
use Snapshot\Security\Shield;
use Snapshot\Seo\Engine;
use Snapshot\Seo\Modules as SeoModules;

class ModuleSwitch
{
    public const LAYERS = ['security', 'seo'];

    public const CRITICAL = ['all', 'twofactor', 'firewall', 'login', 'alerts', 'status'];

    public const CRITICAL_SEO = ['all'];

    public const SOURCES = ['wp-cli' => 'WP-CLI', 'snapshot-os' => 'Snapshot OS', 'wp-admin' => 'wp-admin'];

    public const CHANGES = 'snapshot_module_changes';

    public const REASONS = [
        'not_loaded' => 'this layer is not loaded on this site',
        'unknown_layer' => 'there is no such layer',
        'unknown_module' => 'there is no such module',
        'invalid_value' => 'the value must be on or off',
        'locked' => 'wp-config.php sets it',
    ];

    public static function loaded(string $layer): bool
    {
        return match ($layer) {
            'security' => function_exists('snap_security_modules') && class_exists(Shield::class),
            'seo' => function_exists('snap_seo_modules') && class_exists(Engine::class),
            default => false,
        };
    }

    public static function registry(string $layer): array
    {
        if (! self::loaded($layer)) {
            return [];
        }

        return $layer === 'security' ? snap_security_modules() : snap_seo_modules();
    }

    public static function setting(string $layer, string $module): ?string
    {
        if ($module === 'all') {
            return self::loaded($layer) ? ($layer === 'security' ? SecurityModules::MASTER : SeoModules::MASTER) : null;
        }

        return self::registry($layer)[$module]['setting'] ?? null;
    }

    public static function label(string $layer, string $module): string
    {
        if ($module === 'all') {
            return $layer === 'security' ? 'Built-in security' : 'Built-in SEO';
        }

        return (string) (self::registry($layer)[$module]['label'] ?? $module);
    }

    public static function constant(string $layer, string $module): string
    {
        $setting = self::setting($layer, $module);

        return $setting === null ? '' : self::settings($layer)->constant($setting);
    }

    public static function apply(string $layer, array $changes, string $source, string $actor = ''): array
    {
        $changed = [];
        $refused = [];

        if (! in_array($layer, self::LAYERS, true) || ! self::loaded($layer)) {
            foreach (array_keys($changes) as $module) {
                $refused[(string) $module] = in_array($layer, self::LAYERS, true) ? 'not_loaded' : 'unknown_layer';
            }

            return ['changed' => $changed, 'refused' => $refused];
        }

        $settings = self::settings($layer);
        $alerts = self::loaded('security') ? Shield::instance()->alerts() : null;
        $switched = [];
        $alerts?->hold(true);

        try {
            foreach ($changes as $module => $on) {
                $module = (string) $module;
                $setting = self::setting($layer, $module);

                if ($setting === null) {
                    $refused[$module] = 'unknown_module';

                    continue;
                }

                if (! is_bool($on)) {
                    $refused[$module] = 'invalid_value';

                    continue;
                }

                if ($settings->locked($setting)) {
                    $refused[$module] = 'locked';

                    continue;
                }

                if ($settings->bool($setting) === $on) {
                    continue;
                }

                $settings->set($setting, $on);
                $changed[] = $module;
                $switched[$module] = $on;
                self::changed($layer, $module, $on, $source, $actor);
            }
        } finally {
            $alerts?->hold(false);
        }

        if ($changed !== []) {
            self::forgetStatus($layer);
            self::summary($layer, $switched, $source, $actor);
        }

        return ['changed' => $changed, 'refused' => $refused];
    }

    public static function fromForm(string $layer, array $posted, array $loaded, string $actor, string $source = 'wp-admin'): array
    {
        if (! self::loaded($layer)) {
            return ['changed' => [], 'refused' => [], 'conflicts' => []];
        }

        $settings = self::settings($layer);
        $changes = [];
        $conflicts = [];

        foreach (['all', ...array_keys(self::registry($layer))] as $module) {
            $setting = self::setting($layer, $module);

            if ($setting === null || ! array_key_exists($setting, $loaded) || ! is_scalar($loaded[$setting])) {
                continue;
            }

            $was = (string) $loaded[$setting] === '1';
            $want = ! empty($posted[$setting]);

            if ($settings->bool($setting) !== $was) {
                $conflicts[] = $module;

                continue;
            }

            if ($want !== $was) {
                $changes[$module] = $want;
            }
        }

        return self::apply($layer, $changes, $source, $actor) + ['conflicts' => $conflicts];
    }

    public static function saved(string $layer, array $result): array
    {
        $settings = self::loaded($layer) ? self::settings($layer) : null;
        $changed = array_map(
            fn ($module) => sprintf('%s %s', self::label($layer, $module), $settings?->bool((string) self::setting($layer, $module)) ? __('on', 'snapshot-core') : __('off', 'snapshot-core')),
            $result['changed'] ?? []
        );
        $message = $changed === [] ? __('No changes.', 'snapshot-core') : sprintf(__('Saved: %s.', 'snapshot-core'), implode(', ', $changed));
        $conflicts = $result['conflicts'] ?? [];

        if ($conflicts === []) {
            return [$message, [], 'success'];
        }

        return [$message.' '.sprintf(__('Left as it is: %s. It was changed somewhere else after you opened this page. Check the switch below and save again if you still want a change.', 'snapshot-core'), self::labels($layer, $conflicts)), [], 'warning'];
    }

    public static function labels(string $layer, array $modules): string
    {
        return implode(', ', array_map(fn ($module) => self::label($layer, (string) $module), $modules));
    }

    public static function refusal(string $layer, string $module, ?string $reason): ?string
    {
        return match ($reason) {
            null => null,
            'unknown_module' => "Unknown module {$module}. Run wp snap {$layer} modules for the list.",
            'locked' => sprintf('%s is defined in wp-config.php and cannot be changed here.', self::constant($layer, $module)),
            default => sprintf('%s was not changed: %s.', self::label($layer, $module), self::REASONS[$reason] ?? $reason),
        };
    }

    public static function critical(string $layer, string $module, bool $on): bool
    {
        return ! $on && in_array($module, self::criticalKeys($layer), true);
    }

    public static function criticalKeys(string $layer): array
    {
        return match ($layer) {
            'security' => self::CRITICAL,
            'seo' => self::CRITICAL_SEO,
            default => [],
        };
    }

    public static function record(string $layer, string $module, bool $on, string $source, string $actor = ''): void
    {
        $changes = get_option(self::CHANGES, []);
        $changes = is_array($changes) ? $changes : [];
        $changes[$layer] = is_array($changes[$layer] ?? null) ? $changes[$layer] : [];
        $changes[$layer][$module] = [
            'source' => array_key_exists($source, self::SOURCES) ? $source : 'wp-admin',
            'actor' => mb_substr($actor, 0, 100),
            'at' => gmdate('c'),
            'on' => $on,
        ];

        update_option(self::CHANGES, $changes, false);
    }

    public static function changes(string $layer): array
    {
        $changes = get_option(self::CHANGES, []);
        $rows = is_array($changes) && is_array($changes[$layer] ?? null) ? $changes[$layer] : [];
        $clean = [];

        foreach ($rows as $module => $row) {
            if (is_array($row) && isset($row['source'], $row['at'])) {
                $clean[(string) $module] = [
                    'source' => (string) $row['source'],
                    'actor' => (string) ($row['actor'] ?? ''),
                    'at' => (string) $row['at'],
                    'on' => (bool) ($row['on'] ?? false),
                ];
            }
        }

        return $clean;
    }

    private static function changed(string $layer, string $module, bool $on, string $source, string $actor): void
    {
        $from = self::SOURCES[$source] ?? $source;
        $message = sprintf('%s switched %s from %s', self::label($layer, $module), $on ? 'on' : 'off', $from);
        $context = ['module' => $module, 'enabled' => $on, 'source' => $source];

        if ($source !== 'wp-cli') {
            $message .= $actor !== '' ? ", by {$actor}" : '';
            $context['actor'] = $actor;
        }

        $context['severity'] = self::critical($layer, $module, $on) ? 'critical' : 'warning';

        if ($layer === 'security' && $module === 'feeds' && ! $on) {
            Shield::instance()->blocklist()->removeSources('feed:');
        }

        self::record($layer, $module, $on, $source, $actor);

        if (! self::loaded('security')) {
            return;
        }

        $admin = $source === 'wp-admin';
        Shield::instance()->log()->log("{$layer}.module_changed", $message, $context, $admin ? get_current_user_id() : 0, $admin ? $actor : $source, $source === 'wp-cli' ? '' : null);
    }

    private static function summary(string $layer, array $switched, string $source, string $actor): void
    {
        $off = array_keys(array_filter($switched, fn ($on) => ! $on));

        if ($off === [] || ! self::loaded('security') || ! Schema::ready()) {
            return;
        }

        $critical = array_filter($off, fn ($module) => self::critical($layer, (string) $module, false)) !== [];
        $shield = Shield::instance();
        $alerts = $shield->alerts();
        $remote = $source === 'snapshot-os' && ($layer === 'security' || $critical);

        if (! $remote && ! ($critical && $alerts->booted())) {
            return;
        }

        $on = array_keys(array_filter($switched));
        $message = sprintf('%s switched off from %s', self::labels($layer, $off), self::SOURCES[$source] ?? $source);
        $message .= $source !== 'wp-cli' && $actor !== '' ? ", by {$actor}" : '';
        $message .= $on !== [] ? sprintf('. Switched on: %s', self::labels($layer, $on)) : '';
        $last = $shield->log()->last();

        $alerts->alert((object) [
            'created_at' => gmdate('Y-m-d H:i:s'),
            'event' => "{$layer}.module_changed",
            'severity' => $critical ? 'critical' : 'warning',
            'ip' => (string) ($last->ip ?? ''),
            'username' => $source === 'wp-admin' ? $actor : $source,
            'message' => $message,
        ]);
    }

    private static function forgetStatus(string $layer): void
    {
        $layer === 'security' ? Shield::instance()->admin()->forgetStatus() : Engine::instance()->admin()->forgetStatus();
    }

    private static function settings(string $layer): Security\Settings|Seo\Settings
    {
        return $layer === 'security' ? Shield::instance()->settings() : Engine::instance()->settings();
    }
}

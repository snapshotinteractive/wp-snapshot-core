<?php

namespace Snapshot\Security;

class Modules
{
    public const MASTER = 'security_enabled';

    public function __construct(private Settings $settings) {}

    public function registry(): array
    {
        return snap_security_modules();
    }

    public function master(): bool
    {
        return $this->settings->bool(self::MASTER);
    }

    public function on(string $module): bool
    {
        $setting = $this->registry()[$module]['setting'] ?? null;

        return $this->master() && ($setting === null || $this->settings->bool($setting));
    }

    public function off(): array
    {
        return array_keys(array_filter($this->registry(), fn ($module, $key) => ! $this->on($key), ARRAY_FILTER_USE_BOTH));
    }

    public function states(): array
    {
        $states = [];

        foreach ($this->registry() as $key => $module) {
            $states[$key] = $this->on($key);
        }

        return $states;
    }

    public function overlaps(): array
    {
        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        $found = [];

        foreach (snap_security_overlapping_plugins() as $file => $plugin) {
            if (is_plugin_active($file) || (is_multisite() && is_plugin_active_for_network($file))) {
                $found[$file] = $plugin + ['active' => array_values(array_filter($plugin['modules'], fn ($module) => $this->on($module)))];
            }
        }

        return $found;
    }
}

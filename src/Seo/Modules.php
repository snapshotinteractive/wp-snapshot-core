<?php

namespace Snapshot\Seo;

class Modules
{
    public const MASTER = 'seo_enabled';

    private ?array $active = null;

    public function __construct(private Settings $settings) {}

    public function registry(): array
    {
        return snap_seo_modules();
    }

    public function master(): bool
    {
        return $this->settings->bool(self::MASTER);
    }

    public function enabled(string $module): bool
    {
        $setting = $this->registry()[$module]['setting'] ?? null;

        return $this->master() && ($setting === null || $this->settings->bool($setting));
    }

    public function on(string $module): bool
    {
        return $this->enabled($module) && $this->pausedBy($module) === [];
    }

    public function pausedBy(string $module): array
    {
        if (! $this->settings->bool('defer_to_plugins')) {
            return [];
        }

        return array_values(array_map(
            fn ($plugin) => $plugin['label'],
            array_filter($this->overlaps(), fn ($plugin) => in_array($module, $plugin['modules'], true))
        ));
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
        if ($this->active === null) {
            $files = (array) get_option('active_plugins', []);

            if (is_multisite()) {
                $files = [...$files, ...array_keys((array) get_site_option('active_sitewide_plugins', []))];
            }

            $this->active = array_intersect_key(snap_seo_overlapping_plugins(), array_flip($files));
        }

        return $this->active;
    }
}

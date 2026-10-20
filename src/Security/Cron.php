<?php

namespace Snapshot\Security;

class Cron
{
    public const HOURLY = 'snapshot_security_hourly';

    public const DAILY = 'snapshot_security_daily';

    public const LAST_DAILY = 'snapshot_security_daily_at';

    public function __construct(private Shield $shield) {}

    public function boot(): void
    {
        add_action('init', [$this, 'schedule']);
        add_action(self::HOURLY, [$this, 'hourly']);
        add_action(self::DAILY, [$this, 'daily']);
        add_action('switch_theme', [$this, 'unschedule']);
    }

    public function schedule(): void
    {
        if (! wp_next_scheduled(self::HOURLY)) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOURLY);
        }

        if (! wp_next_scheduled(self::DAILY)) {
            wp_schedule_event(time() + 2 * MINUTE_IN_SECONDS, 'daily', self::DAILY);
        }
    }

    public function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOURLY);
        wp_clear_scheduled_hook(self::DAILY);
    }

    public function hourly(): void
    {
        $this->shield->blocklist()->prune();
        $this->shield->throttle()->prune();
        $this->shield->cloudflare()->prune();
    }

    public function daily(): void
    {
        $settings = $this->shield->settings();

        if (function_exists('set_time_limit')) {
            @set_time_limit(1200);
        }

        $modules = $this->shield->modules();
        $this->shield->log()->prune(max(7, $settings->int('event_retention_days')));

        if ($modules->on('feeds')) {
            $this->shield->feeds()->sync();
        }

        if ($modules->on('cloudflare')) {
            $this->shield->cloudflare()->refreshRanges();
        }

        if ($modules->on('alerts')) {
            $this->shield->alerts()->digest();
        }

        if ($modules->on('vulns')) {
            $this->shield->vulnerabilities()->run();
        }

        if ($modules->on('scanning')) {
            $this->shield->scanner()->run();
        }

        update_option(self::LAST_DAILY, time(), false);
    }
}

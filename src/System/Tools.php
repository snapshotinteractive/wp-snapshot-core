<?php

namespace Snapshot\System;

use Throwable;

use function Roots\app;

class Tools
{
    public function __construct(private Diagnostics $diagnostics) {}

    public function registry(): array
    {
        return snap_filter('snap/system/tools', [
            'rebuild_views' => ['label' => __('Rebuild the Blade view cache', 'snapshot-core'), 'detail' => self::networkStorage()
                ? __('Not available on WP Engine, where web requests cannot write compiled views. Run wp acorn view:clear and wp acorn view:cache over SSH instead.', 'snapshot-core')
                : __('Compiles every template again, then removes compiled files no template uses any more. The site keeps working throughout. The same as wp acorn view:cache. Run it after a deploy that changed views.', 'snapshot-core'), 'primary' => true, 'available' => ! self::networkStorage()],
            'clear_views' => ['label' => __('Clear the Blade view cache', 'snapshot-core'), 'detail' => self::networkStorage()
                ? __('Not available on WP Engine: views must be compiled over SSH, so clearing them here would break the site.', 'snapshot-core')
                : __('Deletes compiled views only. Each one compiles again on its first visit.', 'snapshot-core'), 'available' => ! self::networkStorage(), 'confirm' => __('Delete every compiled view? Each compiles again on its first visit.', 'snapshot-core')],
            'clear_acorn' => ['label' => __('Clear Acorn caches', 'snapshot-core'), 'detail' => __('Removes the cached package, service and config manifests and empties the application cache. Needed after composer install changes packages.', 'snapshot-core')],
            'flush_rewrites' => ['label' => __('Flush permalinks', 'snapshot-core'), 'detail' => __('Rebuilds the address rules. Fixes 404s on content type archives and sitemaps after a deploy.', 'snapshot-core')],
            'flush_object_cache' => ['label' => __('Flush the object cache', 'snapshot-core'), 'detail' => __('Empties the persistent object cache, such as Redis, so every value is read fresh from the database.', 'snapshot-core')],
            'reset_opcache' => ['label' => __('Reset OPcache', 'snapshot-core'), 'detail' => __('Makes PHP read changed theme files immediately instead of serving cached compiled code.', 'snapshot-core'), 'available' => function_exists('opcache_reset')],
            'clear_transients' => ['label' => __('Clear transients and status caches', 'snapshot-core'), 'detail' => __('Deletes expired transients and the Security and SEO status caches, so dashboards recalculate.', 'snapshot-core')],
            'run_cron' => ['label' => __('Run overdue scheduled tasks', 'snapshot-core'), 'detail' => __('Runs every scheduled task that is already due, now, in this request. Refuses while WordPress is already running them.', 'snapshot-core')],
            'clear_debug_log' => ['label' => __('Clear the debug log', 'snapshot-core'), 'detail' => __('Empties wp-content/debug.log after you have read it.', 'snapshot-core'), 'confirm' => __('Empty wp-content/debug.log?', 'snapshot-core')],
        ]);
    }

    public function run(string $tool): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        try {
            return match ($tool) {
                'rebuild_views' => $this->rebuildViews(),
                'clear_views' => $this->clearViews(),
                'clear_acorn' => $this->clearAcorn(),
                'flush_rewrites' => $this->flushRewrites(),
                'flush_object_cache' => ['ok' => wp_cache_flush(), 'message' => __('Object cache flushed.', 'snapshot-core')],
                'reset_opcache' => ['ok' => function_exists('opcache_reset') && opcache_reset(), 'message' => function_exists('opcache_reset') ? __('OPcache reset.', 'snapshot-core') : __('OPcache is not available.', 'snapshot-core')],
                'clear_transients' => $this->clearTransients(),
                'run_cron' => $this->runCron(),
                'clear_debug_log' => $this->clearDebugLog(),
                default => ['ok' => false, 'message' => __('Unknown tool.', 'snapshot-core')],
            };
        } catch (Throwable $error) {
            return ['ok' => false, 'message' => sprintf(__('The tool failed: %s', 'snapshot-core'), self::scrub($error->getMessage()))];
        }
    }

    public function clearViews(): array
    {
        $views = $this->diagnostics->views();
        $removed = 0;

        if ($views['path'] !== '' && is_dir($views['path'])) {
            foreach (glob(rtrim($views['path'], '/').'/*.php') ?: [] as $file) {
                $removed += @unlink($file) ? 1 : 0;
            }
        }

        return ['ok' => true, 'message' => sprintf(_n('%d compiled view removed.', '%d compiled views removed.', $removed, 'snapshot-core'), $removed)];
    }

    public function rebuildViews(): array
    {
        $views = $this->diagnostics->views();

        if ($views['path'] === '' || (! is_dir($views['path']) && ! wp_mkdir_p($views['path'])) || ! wp_is_writable($views['path'])) {
            return ['ok' => false, 'message' => __('The compiled view folder is not writable, so nothing was changed. Run wp acorn view:cache over SSH.', 'snapshot-core')];
        }

        $compiler = app('blade.compiler');
        $compiled = 0;
        $failed = [];
        $keep = [];

        foreach ($this->diagnostics->viewSources() as $source) {
            try {
                $compiler->compile($source);
                $keep[realpath($compiler->getCompiledPath($source)) ?: $compiler->getCompiledPath($source)] = true;
                $compiled++;
            } catch (Throwable $error) {
                $failed[] = basename($source).': '.self::scrub($error->getMessage());
            }
        }

        $removed = 0;

        if ($failed === []) {
            foreach (glob(rtrim($views['path'], '/').'/*.php') ?: [] as $file) {
                if (! isset($keep[realpath($file) ?: $file]) && @unlink($file)) {
                    $removed++;
                }
            }
        }

        return [
            'ok' => $failed === [],
            'message' => $failed === []
                ? sprintf(__('%1$d views compiled, %2$d unused compiled files removed.', 'snapshot-core'), $compiled, $removed)
                : sprintf(__('%1$d views compiled, %2$d failed, nothing removed: %3$s', 'snapshot-core'), $compiled, count($failed), implode('; ', array_slice($failed, 0, 3))),
        ];
    }

    public function clearAcorn(): array
    {
        $removed = 0;

        foreach ($this->diagnostics->acornCaches() as $cache) {
            if ($cache['exists'] && @unlink($cache['path'])) {
                $removed++;
            }
        }

        try {
            app('cache')->flush();
        } catch (Throwable) {
        }

        return ['ok' => true, 'message' => sprintf(_n('%d Acorn cache file removed and the application cache emptied.', '%d Acorn cache files removed and the application cache emptied.', $removed, 'snapshot-core'), $removed)];
    }

    public function flushRewrites(): array
    {
        flush_rewrite_rules(false);

        return ['ok' => true, 'message' => __('Permalinks flushed.', 'snapshot-core')];
    }

    public function clearTransients(): array
    {
        global $wpdb;

        delete_expired_transients(true);

        foreach (['snapshot_sec_status', 'snapshot_seo_status', 'snapshot_seo_llms'] as $transient) {
            delete_transient($transient);
        }

        $remaining = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('_transient_').'%'));

        return ['ok' => true, 'message' => sprintf(__('Expired transients and status caches cleared. %d transients remain in use.', 'snapshot-core'), $remaining)];
    }

    public function runCron(): array
    {
        $lock = (float) get_transient('doing_cron');
        $timeout = defined('WP_CRON_LOCK_TIMEOUT') ? (int) WP_CRON_LOCK_TIMEOUT : 60;

        if ($lock && $lock > microtime(true) - $timeout) {
            return ['ok' => false, 'message' => __('WordPress is already running scheduled tasks. Try again in a minute.', 'snapshot-core')];
        }

        set_transient('doing_cron', sprintf('%.22F', microtime(true)));
        $ran = 0;

        try {
            foreach (_get_cron_array() ?: [] as $timestamp => $hooks) {
                if ($timestamp > time()) {
                    break;
                }

                foreach ($hooks as $hook => $events) {
                    foreach ($events as $event) {
                        if ($event['schedule']) {
                            wp_reschedule_event($timestamp, $event['schedule'], $hook, $event['args']);
                        }

                        wp_unschedule_event($timestamp, $hook, $event['args']);
                        do_action_ref_array($hook, $event['args']);
                        $ran++;
                    }
                }
            }
        } finally {
            delete_transient('doing_cron');
        }

        return ['ok' => true, 'message' => sprintf(_n('%d scheduled task ran.', '%d scheduled tasks ran.', $ran, 'snapshot-core'), $ran)];
    }

    public static function networkStorage(): bool
    {
        return function_exists('is_wpe') || defined('WPE_APIKEY') || getenv('IS_WPE');
    }

    private static function scrub(string $message): string
    {
        return str_replace([untrailingslashit(ABSPATH), WP_CONTENT_DIR], ['', 'wp-content'], $message);
    }

    public function clearDebugLog(): array
    {
        $log = WP_CONTENT_DIR.'/debug.log';

        if (! is_file($log)) {
            return ['ok' => true, 'message' => __('There is no debug log.', 'snapshot-core')];
        }

        return @file_put_contents($log, '') === 0
            ? ['ok' => true, 'message' => __('Debug log cleared.', 'snapshot-core')]
            : ['ok' => false, 'message' => __('The debug log could not be written.', 'snapshot-core')];
    }

    public function debugTail(int $lines = 60): string
    {
        $log = WP_CONTENT_DIR.'/debug.log';

        if (! is_readable($log) || ! filesize($log)) {
            return '';
        }

        $handle = fopen($log, 'rb');
        $size = (int) filesize($log);
        fseek($handle, max(0, $size - 64 * KB_IN_BYTES));
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return implode("\n", array_slice(preg_split('/\r?\n/', trim($text)), -$lines));
    }
}

<?php

namespace Snapshot\System;

use Composer\InstalledVersions;
use Snapshot\Core;
use Throwable;

use function Roots\app;

class Diagnostics
{
    private const MIN_PHP = '8.3.0';

    private const EXTENSIONS = ['curl', 'dom', 'fileinfo', 'json', 'mbstring', 'mysqli', 'openssl', 'sodium', 'xml', 'zip'];

    public function groups(): array
    {
        return [
            'server' => ['label' => __('Server and PHP', 'snapshot-core'), 'checks' => $this->server()],
            'wordpress' => ['label' => __('WordPress', 'snapshot-core'), 'checks' => $this->wordpress()],
            'database' => ['label' => __('Database', 'snapshot-core'), 'checks' => $this->database()],
            'theme' => ['label' => __('Theme, Acorn and Blade', 'snapshot-core'), 'checks' => $this->theme()],
        ];
    }

    public function summary(): array
    {
        $levels = [];

        foreach ($this->groups() as $group) {
            foreach ($group['checks'] as $check) {
                $levels[] = $check['status'];
            }
        }

        return [
            'fail' => count(array_keys($levels, 'fail', true)),
            'warn' => count(array_keys($levels, 'warn', true)),
            'ok' => count(array_keys($levels, 'ok', true)),
        ];
    }

    public function report(): string
    {
        $lines = [sprintf('%s diagnostics, %s', get_bloginfo('name'), gmdate('Y-m-d H:i').' UTC'), home_url('/'), ''];

        foreach ($this->groups() as $group) {
            $lines[] = '## '.$group['label'];

            foreach ($group['checks'] as $check) {
                $lines[] = sprintf('- [%s] %s: %s%s', strtoupper($check['status']), $check['label'], $check['value'], $check['detail'] !== '' ? ' ('.$check['detail'].')' : '');
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    public function views(): array
    {
        $compiler = $this->resolve('blade.compiler');
        $compiled = (string) ($this->config('view.compiled') ?? '');
        $sources = $this->viewSources();
        $missing = 0;
        $stale = 0;
        $newest = 0;

        foreach ($sources as $source) {
            $target = $compiler ? $compiler->getCompiledPath($source) : '';

            if ($target === '' || ! is_file($target)) {
                $missing++;

                continue;
            }

            $mtime = (int) filemtime($target);
            $newest = max($newest, $mtime);

            if ($mtime < (int) filemtime($source)) {
                $stale++;
            }
        }

        $files = $compiled !== '' && is_dir($compiled) ? glob(rtrim($compiled, '/').'/*.php') : [];

        return [
            'path' => $compiled,
            'writable' => $compiled !== '' && is_dir($compiled) && wp_is_writable($compiled),
            'sources' => count($sources),
            'compiled' => count($files ?: []),
            'missing' => $missing,
            'stale' => $stale,
            'newest' => $newest,
        ];
    }

    public function viewSources(): array
    {
        $paths = array_unique(array_filter(array_map(fn ($path) => realpath((string) $path) ?: '', [...(array) ($this->config('view.paths') ?? [get_theme_file_path('resources/views')]), Core::viewPath()])));
        $sources = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $sources[] = $file->getPathname();
                }
            }
        }

        sort($sources);

        return $sources;
    }

    public function acornCaches(): array
    {
        $app = $this->app();
        $caches = [];

        foreach (['getCachedPackagesPath' => 'Packages', 'getCachedServicesPath' => 'Services', 'getCachedConfigPath' => 'Config', 'getCachedRoutesPath' => 'Routes', 'getCachedEventsPath' => 'Events'] as $method => $label) {
            if ($app && method_exists($app, $method)) {
                $path = (string) $app->{$method}();
                $caches[$label] = ['path' => $path, 'exists' => is_file($path), 'modified' => is_file($path) ? (int) filemtime($path) : 0];
            }
        }

        return $caches;
    }

    private function server(): array
    {
        $memory = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
        $missing = array_values(array_filter(self::EXTENSIONS, fn ($extension) => ! extension_loaded($extension)));
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $free = function_exists('disk_free_space') ? @disk_free_space(WP_CONTENT_DIR) : false;
        $upload = min(wp_convert_hr_to_bytes((string) ini_get('upload_max_filesize')), wp_convert_hr_to_bytes((string) ini_get('post_max_size')));

        return [
            $this->check(__('PHP version', 'snapshot-core'), PHP_VERSION, version_compare(PHP_VERSION, self::MIN_PHP, '>=') ? 'ok' : 'fail', version_compare(PHP_VERSION, self::MIN_PHP, '>=') ? '' : sprintf(__('The theme needs %s or later.', 'snapshot-core'), self::MIN_PHP)),
            $this->check(__('PHP interface', 'snapshot-core'), PHP_SAPI, 'info'),
            $this->check(__('Web server', 'snapshot-core'), sanitize_text_field((string) ($_SERVER['SERVER_SOFTWARE'] ?? __('Unknown', 'snapshot-core'))), 'info'),
            $this->check(__('Operating system', 'snapshot-core'), PHP_OS_FAMILY.' '.php_uname('r'), 'info'),
            $this->check(__('Memory limit', 'snapshot-core'), $memory === -1 ? __('Unlimited', 'snapshot-core') : (string) size_format($memory), $memory === -1 || $memory >= 256 * MB_IN_BYTES ? 'ok' : 'warn', $memory >= 256 * MB_IN_BYTES || $memory === -1 ? '' : __('256 MB or more is recommended for the page builder and image editing.', 'snapshot-core')),
            $this->check(__('Maximum execution time', 'snapshot-core'), sprintf(__('%d seconds', 'snapshot-core'), (int) ini_get('max_execution_time')), (int) ini_get('max_execution_time') === 0 || (int) ini_get('max_execution_time') >= 30 ? 'ok' : 'warn'),
            $this->check(__('Largest upload', 'snapshot-core'), size_format($upload), $upload >= 16 * MB_IN_BYTES ? 'ok' : 'warn', $upload >= 16 * MB_IN_BYTES ? '' : __('Raise upload_max_filesize and post_max_size so editors can upload photographs.', 'snapshot-core')),
            $this->check(__('PHP extensions', 'snapshot-core'), $missing === [] ? __('All present', 'snapshot-core') : sprintf(__('Missing: %s', 'snapshot-core'), implode(', ', $missing)), $missing === [] ? 'ok' : (in_array('sodium', $missing, true) || in_array('openssl', $missing, true) ? 'fail' : 'warn'), implode(', ', self::EXTENSIONS)),
            $this->check(__('Image library', 'snapshot-core'), extension_loaded('imagick') ? 'Imagick' : (extension_loaded('gd') ? 'GD' : __('None', 'snapshot-core')), extension_loaded('imagick') || extension_loaded('gd') ? 'ok' : 'fail', extension_loaded('imagick') || extension_loaded('gd') ? '' : __('WordPress cannot create image sizes without GD or Imagick.', 'snapshot-core')),
            $this->check(__('OPcache', 'snapshot-core'), is_array($opcache) && ! empty($opcache['opcache_enabled']) ? sprintf(__('On, %s used', 'snapshot-core'), size_format((int) ($opcache['memory_usage']['used_memory'] ?? 0))) : __('Off', 'snapshot-core'), is_array($opcache) && ! empty($opcache['opcache_enabled']) ? 'ok' : 'warn', is_array($opcache) && ! empty($opcache['opcache_enabled']) ? '' : __('OPcache makes every PHP request faster. Ask the host to enable it.', 'snapshot-core')),
            $this->check(__('Free disk space', 'snapshot-core'), $free !== false ? size_format((int) $free) : __('Unknown', 'snapshot-core'), $free === false ? 'info' : ($free > GB_IN_BYTES ? 'ok' : 'warn'), $free !== false && $free <= GB_IN_BYTES ? __('Less than 1 GB left for uploads, caches and backups.', 'snapshot-core') : ''),
            $this->check(__('HTTPS', 'snapshot-core'), is_ssl() ? __('This request is encrypted', 'snapshot-core') : __('Plain HTTP', 'snapshot-core'), is_ssl() ? 'ok' : 'warn'),
        ];
    }

    private function wordpress(): array
    {
        global $wp_version;

        $crons = _get_cron_array() ?: [];
        $overdue = 0;

        foreach ($crons as $time => $hooks) {
            if ($time < time() - HOUR_IN_SECONDS) {
                $overdue += count($hooks);
            }
        }

        $log = WP_CONTENT_DIR.'/debug.log';
        $logSize = is_file($log) ? (int) filesize($log) : 0;
        $display = defined('WP_DEBUG') && WP_DEBUG && (! defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY);
        $acf = defined('ACF_VERSION') ? ACF_VERSION : '';
        $plugins = (array) get_option('active_plugins', []);

        return [
            $this->check(__('WordPress version', 'snapshot-core'), (string) $wp_version, 'info'),
            $this->check(__('Environment', 'snapshot-core'), wp_get_environment_type(), 'info'),
            $this->check(__('Site address', 'snapshot-core'), home_url('/'), 'info'),
            $this->check(__('Search engines', 'snapshot-core'), get_option('blog_public') ? __('Allowed', 'snapshot-core') : __('Discouraged', 'snapshot-core'), get_option('blog_public') || wp_get_environment_type() !== 'production' ? 'ok' : 'warn', get_option('blog_public') ? '' : __('Settings, Reading asks search engines not to index the site.', 'snapshot-core')),
            $this->check(__('Permalinks', 'snapshot-core'), get_option('permalink_structure') ?: __('Plain', 'snapshot-core'), get_option('permalink_structure') ? 'ok' : 'fail'),
            $this->check(__('Error display', 'snapshot-core'), $display ? __('On', 'snapshot-core') : __('Off', 'snapshot-core'), $display ? 'fail' : 'ok', $display ? __('Errors print on the page. Set WP_DEBUG_DISPLAY to false.', 'snapshot-core') : ''),
            $this->check(__('Debug log', 'snapshot-core'), $logSize ? size_format($logSize) : __('Empty or absent', 'snapshot-core'), $logSize > 10 * MB_IN_BYTES ? 'warn' : 'ok', $logSize > 10 * MB_IN_BYTES ? __('wp-content/debug.log is large. Read it, then clear it from Tools.', 'snapshot-core') : ''),
            $this->check(__('Scheduled tasks', 'snapshot-core'), sprintf(_n('%d overdue', '%d overdue', $overdue, 'snapshot-core'), $overdue).(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? ', '.__('run by server cron', 'snapshot-core') : ''), $overdue > 0 ? 'warn' : 'ok', $overdue > 0 ? __('Tasks more than an hour late. Run them from Tools, then check the server cron.', 'snapshot-core') : ''),
            $this->check(__('Persistent object cache', 'snapshot-core'), wp_using_ext_object_cache() ? __('On', 'snapshot-core') : __('Off', 'snapshot-core'), wp_using_ext_object_cache() ? 'ok' : 'info', wp_using_ext_object_cache() ? '' : __('Redis or Memcached makes the admin faster on busy sites.', 'snapshot-core')),
            $this->check(__('Advanced Custom Fields Pro', 'snapshot-core'), $acf !== '' ? $acf : __('Not active', 'snapshot-core'), $acf !== '' && class_exists('ACF') && defined('ACF_PRO') ? 'ok' : 'fail', $acf !== '' ? '' : __('The page builder and every field need ACF Pro.', 'snapshot-core')),
            $this->check(__('Active plugins', 'snapshot-core'), (string) count($plugins), 'info', implode(', ', array_map(fn ($plugin) => dirname($plugin) !== '.' ? dirname($plugin) : $plugin, $plugins))),
            $this->check(__('Timezone', 'snapshot-core'), wp_timezone_string(), str_starts_with(wp_timezone_string(), '+') || str_starts_with(wp_timezone_string(), '-') ? 'warn' : 'ok', str_starts_with(wp_timezone_string(), '+') || str_starts_with(wp_timezone_string(), '-') ? __('A city timezone follows daylight saving; a UTC offset does not.', 'snapshot-core') : ''),
        ];
    }

    private function database(): array
    {
        global $wpdb;

        $version = (string) $wpdb->get_var('SELECT VERSION()');
        $size = (int) $wpdb->get_var($wpdb->prepare('SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s', DB_NAME));
        $autoload = (int) $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')");
        $revisions = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'");
        $expired = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like('_transient_timeout_').'%', time()));

        return [
            $this->check(__('Server', 'snapshot-core'), $version, 'info'),
            $this->check(__('Database size', 'snapshot-core'), size_format($size), 'info'),
            $this->check(__('Table prefix', 'snapshot-core'), $wpdb->prefix, 'info'),
            $this->check(__('Charset', 'snapshot-core'), $wpdb->charset ?: __('Unknown', 'snapshot-core'), $wpdb->charset === 'utf8mb4' ? 'ok' : 'warn', $wpdb->charset === 'utf8mb4' ? '' : __('utf8mb4 is needed for emoji and many scripts.', 'snapshot-core')),
            $this->check(__('Autoloaded options', 'snapshot-core'), size_format($autoload), $autoload > 2 * MB_IN_BYTES ? 'warn' : 'ok', $autoload > 2 * MB_IN_BYTES ? __('Every request loads these. Over 2 MB slows the whole site.', 'snapshot-core') : ''),
            $this->check(__('Revisions', 'snapshot-core'), number_format_i18n($revisions), $revisions > 5000 ? 'warn' : 'ok', defined('WP_POST_REVISIONS') ? sprintf(__('WP_POST_REVISIONS is %s.', 'snapshot-core'), var_export(WP_POST_REVISIONS, true)) : __('Unlimited. Set WP_POST_REVISIONS in wp-config.php to cap them.', 'snapshot-core')),
            $this->check(__('Expired transients', 'snapshot-core'), number_format_i18n($expired), $expired > 500 ? 'warn' : 'ok', $expired > 500 ? __('Clear them from Tools.', 'snapshot-core') : ''),
        ];
    }

    private function theme(): array
    {
        $theme = wp_get_theme();
        $views = $this->views();
        $caches = $this->acornCaches();
        $manifest = get_theme_file_path('public/build/manifest.json');
        $hot = get_theme_file_path('public/hot');
        $storage = defined('ACORN_STORAGE_PATH') ? ACORN_STORAGE_PATH : '';
        $securityManifest = get_theme_file_path('security-manifest.json');
        $acorn = $this->version('roots/acorn');
        $laravel = $this->version('illuminate/support');

        $viewStatus = match (true) {
            ! $views['writable'] => 'fail',
            $views['stale'] > 0 => 'warn',
            $views['missing'] > 0 => 'info',
            default => 'ok',
        };

        $viewDetail = match (true) {
            ! $views['writable'] => sprintf(__('%s is not writable, so Blade cannot compile views.', 'snapshot-core'), $views['path']),
            $views['stale'] > 0 => sprintf(_n('%d compiled view is older than its template. Rebuild the Blade cache.', '%d compiled views are older than their templates. Rebuild the Blade cache.', $views['stale'], 'snapshot-core'), $views['stale']),
            $views['missing'] > 0 => sprintf(_n('%d view compiles on first use. Rebuild to compile everything now, required on WP Engine.', '%d views compile on first use. Rebuild to compile everything now, required on WP Engine.', $views['missing'], 'snapshot-core'), $views['missing']),
            default => '',
        };

        return [
            $this->check(__('Theme', 'snapshot-core'), sprintf('%s %s', $theme->get('Name'), $theme->get('Version')), 'info', get_stylesheet()),
            $this->check(__('Acorn', 'snapshot-core'), $acorn ?: __('Unknown', 'snapshot-core'), $acorn ? 'ok' : 'warn', $laravel ? sprintf(__('Laravel components %s', 'snapshot-core'), $laravel) : ''),
            $this->check(__('Composer dependencies', 'snapshot-core'), is_file(get_theme_file_path('vendor/autoload.php')) ? __('Installed', 'snapshot-core') : __('Missing', 'snapshot-core'), is_file(get_theme_file_path('vendor/autoload.php')) ? 'ok' : 'fail'),
            $this->check(__('snapshot-core', 'snapshot-core'), Core::version(), Core::version() === 'unknown' ? 'warn' : 'info', sprintf(__('Security, SEO, System and admin. SEO %1$s, grouped admin menu %2$s.', 'snapshot-core'), Core::enabled('seo') ? __('on', 'snapshot-core') : __('off', 'snapshot-core'), Core::enabled('admin_menu') ? __('on', 'snapshot-core') : __('off', 'snapshot-core'))),
            $this->check(__('snapshot-core assets', 'snapshot-core'), Core::built() ? __('Built', 'snapshot-core') : __('Missing', 'snapshot-core'), Core::built() ? 'ok' : 'fail', Core::built() ? '' : __('build/manifest.json is missing from the Snapshot Core plugin. Reinstall it from the release zip, or run bin/build in packages/snapshot-core.', 'snapshot-core')),
            $this->check(__('Acorn storage', 'snapshot-core'), $storage !== '' ? $storage : __('Not defined', 'snapshot-core'), $storage !== '' && wp_is_writable($storage) ? 'ok' : 'fail', $storage !== '' && wp_is_writable($storage) ? '' : __('Acorn needs a writable storage folder for views and caches.', 'snapshot-core')),
            $this->check(__('Blade view cache', 'snapshot-core'), sprintf(__('%1$d of %2$d templates compiled', 'snapshot-core'), $views['sources'] - $views['missing'], $views['sources']), $viewStatus, $viewDetail !== '' ? $viewDetail : ($views['newest'] ? sprintf(__('Last compiled %s ago.', 'snapshot-core'), human_time_diff($views['newest'])) : '')),
            $this->check(__('Acorn caches', 'snapshot-core'), implode(', ', array_keys(array_filter($caches, fn ($cache) => $cache['exists']))) ?: __('None', 'snapshot-core'), 'info', __('Packages and services are cached on first boot. Clear them after a Composer change.', 'snapshot-core')),
            $this->check(__('Front end build', 'snapshot-core'), is_file($manifest) ? sprintf(__('Built %s ago', 'snapshot-core'), human_time_diff((int) filemtime($manifest))) : __('Missing', 'snapshot-core'), is_file($manifest) ? 'ok' : 'fail', is_file($manifest) ? '' : __('public/build/manifest.json is missing. Run npm run build.', 'snapshot-core')),
            $this->check(__('Vite dev server', 'snapshot-core'), is_file($hot) ? __('Hot file present', 'snapshot-core') : __('Not in use', 'snapshot-core'), is_file($hot) && wp_get_environment_type() === 'production' ? 'fail' : 'ok', is_file($hot) ? __('public/hot makes every page load assets from a local dev server. Delete it on a live site.', 'snapshot-core') : ''),
            $this->check(__('Security manifest', 'snapshot-core'), is_file($securityManifest) ? sprintf(__('Written %s ago', 'snapshot-core'), human_time_diff((int) filemtime($securityManifest))) : __('Absent', 'snapshot-core'), is_file($securityManifest) || wp_get_environment_type() !== 'production' ? 'ok' : 'warn', is_file($securityManifest) ? '' : __('The deploy writes it. Without it the file scan baselines the theme instead.', 'snapshot-core')),
        ];
    }

    private function check(string $label, string $value, string $status, string $detail = ''): array
    {
        return compact('label', 'value', 'status', 'detail');
    }

    private function version(string $package): string
    {
        try {
            return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package) ? (string) InstalledVersions::getPrettyVersion($package) : '';
        } catch (Throwable) {
            return '';
        }
    }

    private function app()
    {
        try {
            return app();
        } catch (Throwable) {
            return null;
        }
    }

    private function resolve(string $abstract)
    {
        try {
            return app($abstract);
        } catch (Throwable) {
            return null;
        }
    }

    private function config(string $key)
    {
        $config = $this->resolve('config');

        return $config ? $config->get($key) : null;
    }
}

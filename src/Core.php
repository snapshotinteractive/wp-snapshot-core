<?php

namespace Snapshot;

use function Roots\app;

class Core
{
    public const PACKAGE = 'snapshotinteractive/snapshot-core';

    public const FEATURES = ['seo', 'admin_menu'];

    private static array $config = [];

    private static bool $loaded = false;

    private static bool $booted = false;

    private static ?array $manifest = null;

    private static bool $tokensPrinted = false;

    public static function load(array $config = []): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;
        self::$config = self::normalize($config);

        if (! function_exists('snap_filter')) {
            require __DIR__.'/../includes/helpers.php';
        }

        foreach (['security', 'seo', 'system'] as $layer) {
            self::defaults($layer);
        }

        require_once __DIR__.'/../includes/security-defaults.php';
        require_once __DIR__.'/../includes/security.php';

        if (self::enabled('seo')) {
            require_once __DIR__.'/../includes/seo-defaults.php';
            require_once __DIR__.'/../includes/seo.php';
        }

        require_once __DIR__.'/../includes/system-defaults.php';
        require_once __DIR__.'/../includes/system.php';

        require_once __DIR__.'/../includes/mcp-defaults.php';
        require_once __DIR__.'/../includes/mcp.php';
    }

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        app('view')->addNamespace('snapshot', self::viewPath());

        require_once __DIR__.'/../includes/admin.php';

        if (self::enabled('admin_menu')) {
            require_once __DIR__.'/../includes/admin-menu.php';
        }
    }

    public static function enabled(string $feature): bool
    {
        return ! in_array($feature, self::FEATURES, true) || (bool) (self::$config['features'][$feature] ?? true);
    }

    public static function config(?string $key = null)
    {
        return $key === null ? self::$config : (self::$config[$key] ?? null);
    }

    public static function loaded(): bool
    {
        return self::$loaded;
    }

    public static function version(): string
    {
        return defined('SNAPSHOT_CORE_VERSION') ? (string) SNAPSHOT_CORE_VERSION : 'unknown';
    }

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function viewPath(): string
    {
        return self::root().'/resources/views';
    }

    public static function path(string $path = ''): string
    {
        return self::root().'/'.ltrim($path, '/');
    }

    public static function url(string $path = ''): string
    {
        return plugins_url(ltrim($path, '/'), self::root().'/snapshot-core.php');
    }

    public static function built(): bool
    {
        return self::manifest() !== [];
    }

    public static function asset(string $entry): ?string
    {
        $chunk = self::manifest()[ltrim($entry, '/')] ?? null;

        return isset($chunk['file']) ? self::url('build/'.$chunk['file']) : null;
    }

    public static function assets(array $entries): string
    {
        $manifest = self::manifest();
        $styles = [];
        $scripts = [];

        foreach ($entries as $entry) {
            $chunk = $manifest[ltrim($entry, '/')] ?? null;

            if (! isset($chunk['file'])) {
                continue;
            }

            if (str_ends_with($chunk['file'], '.css')) {
                $styles[] = $chunk['file'];
            } else {
                $scripts[] = $chunk['file'];
            }

            foreach ((array) ($chunk['css'] ?? []) as $css) {
                $styles[] = $css;
            }
        }

        $html = '';

        foreach (array_unique($styles) as $file) {
            $html .= sprintf('<link rel="stylesheet" href="%s">', esc_url(self::url('build/'.$file)));
        }

        if ($styles !== [] && ! self::$tokensPrinted) {
            self::$tokensPrinted = true;
            $html .= self::tokensStyle();
        }

        foreach (array_unique($scripts) as $file) {
            $html .= sprintf('<script type="module" src="%s"></script>', esc_url(self::url('build/'.$file)));
        }

        return $html;
    }

    public static function tokensStyle(): string
    {
        $css = self::tokensCss();

        return $css === '' ? '' : '<style id="snapshot-core-tokens">'.$css.'</style>';
    }

    public static function tokensCss(): string
    {
        static $css = null;

        if ($css !== null) {
            return $css;
        }

        $css = '';
        $path = snap_filter('snap/core/tokens_path', get_theme_file_path('resources/css/tokens.css'));
        $source = is_readable($path) ? (string) file_get_contents($path) : '';

        if ($source === '' || ! preg_match_all('/@theme(?:\s+(?:inline|static))*\s*\{(.*?)\}/s', $source, $blocks)) {
            return $css;
        }

        $declarations = [];

        foreach ($blocks[1] as $block) {
            $block = preg_replace('#/\*.*?\*/#s', '', $block);

            if (preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;{}<>]+);/i', $block, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $value = trim($match[2]);

                    if ($value !== 'initial') {
                        $declarations[$match[1]] = $value;
                    }
                }
            }
        }

        if ($declarations !== []) {
            $css = ':root{'.implode('', array_map(fn ($name, $value) => "{$name}:{$value};", array_keys($declarations), $declarations)).'}';
        }

        return $css;
    }

    private static function manifest(): array
    {
        if (self::$manifest === null) {
            $path = self::path('build/manifest.json');
            $data = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
            self::$manifest = is_array($data) ? $data : [];
        }

        return self::$manifest;
    }

    private static function defaults(string $layer): void
    {
        $values = (array) (self::$config[$layer] ?? []);

        if ($values === []) {
            return;
        }

        add_filter("snap/{$layer}/settings", function (array $schema) use ($values) {
            foreach ($values as $key => $value) {
                if (isset($schema[$key])) {
                    $schema[$key]['default'] = $value;
                }
            }

            return $schema;
        });
    }

    private static function normalize(array $config): array
    {
        $config += ['features' => [], 'security' => [], 'seo' => [], 'system' => []];
        $config['features'] = array_intersect_key((array) $config['features'], array_flip(self::FEATURES));

        return $config;
    }
}

<?php

namespace Snapshot;

use WP_Error;

class Updater
{
    public const REPOSITORY = 'snapshotinteractive/wp-snapshot-core';

    public const SLUG = 'snapshot-core';

    private const CACHE = 'snapshot_core_update';

    public static function register(): void
    {
        add_filter('update_plugins_github.com', [self::class, 'check'], 10, 3);
        add_filter('plugins_api', [self::class, 'details'], 20, 3);
        add_filter('upgrader_source_selection', [self::class, 'source'], 10, 4);
        add_filter('plugin_row_meta', [self::class, 'rowMeta'], 10, 2);
        add_action('upgrader_process_complete', [self::class, 'flush'], 10, 0);
        add_action('load-update-core.php', function () {
            if (! empty($_GET['force-check']) && current_user_can('update_plugins')) {
                self::flush();
            }
        });
    }

    public static function basename(): string
    {
        return plugin_basename(SNAPSHOT_CORE_FILE);
    }

    public static function enabled(): bool
    {
        if (defined('SNAPSHOT_CORE_UPDATES') && ! SNAPSHOT_CORE_UPDATES) {
            return false;
        }

        return ! str_contains(Core::version(), '-') && (bool) apply_filters('snap/core/updates', true);
    }

    public static function major(): string
    {
        return (string) (int) Core::version();
    }

    public static function channelUrl(string $channel): string
    {
        $template = defined('SNAPSHOT_CORE_UPDATE_URL') ? (string) SNAPSHOT_CORE_UPDATE_URL : 'https://raw.githubusercontent.com/'.self::REPOSITORY.'/main/updates/{channel}.json';

        return str_replace('{channel}', rawurlencode($channel), (string) apply_filters('snap/core/update_url', $template));
    }

    public static function check($update, array $data, string $file)
    {
        if ($file !== self::basename() || ! self::enabled() || ! ($release = self::release(self::major()))) {
            return $update;
        }

        return [
            'slug' => self::SLUG,
            'version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'requires' => $release['requires'],
            'requires_php' => $release['requires_php'],
            'tested' => $release['tested'],
        ];
    }

    public static function details($result, $action, $args)
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== self::SLUG) {
            return $result;
        }

        $release = self::release(self::major()) ?? self::release('latest');

        if (! $release) {
            return $result;
        }

        return (object) [
            'name' => 'Snapshot Core',
            'slug' => self::SLUG,
            'version' => $release['version'],
            'author' => '<a href="https://snapshot.agency/">Snapshot Interactive</a>',
            'homepage' => $release['url'],
            'requires' => $release['requires'],
            'requires_php' => $release['requires_php'],
            'tested' => $release['tested'],
            'last_updated' => $release['released'],
            'download_link' => $release['package'],
            'sections' => [
                'description' => '<p>'.esc_html__('Built-in security, SEO, the Snapshot System panel, the activity log and the Snapshot admin for themes built from the Snapshot starter.', 'snapshot-core').'</p>'
                    .'<p>'.esc_html__('Released under the GPL, version 2 or later, without any warranty. Support and fixes come with a Snapshot retainer.', 'snapshot-core').'</p>',
                'changelog' => self::notes($release),
            ],
        ];
    }

    public static function source($source, $remote, $upgrader, $extra = [])
    {
        if (is_wp_error($source) || ($extra['plugin'] ?? '') !== self::basename()) {
            return $source;
        }

        global $wp_filesystem;

        $wanted = trailingslashit($remote).dirname(self::basename()).'/';

        if (untrailingslashit($source) === untrailingslashit($wanted)) {
            return $source;
        }

        if ($wp_filesystem && $wp_filesystem->move($source, $wanted, true)) {
            return $wanted;
        }

        return new WP_Error('snapshot_core_source', __('The Snapshot Core update could not be unpacked into the plugin folder.', 'snapshot-core'));
    }

    public static function rowMeta(array $links, string $file): array
    {
        if ($file !== self::basename()) {
            return $links;
        }

        $links[] = sprintf(
            '<a href="%s" class="thickbox open-plugin-details-modal">%s</a>',
            esc_url(self_admin_url('plugin-install.php?tab=plugin-information&plugin='.self::SLUG.'&TB_iframe=true&width=600&height=550')),
            esc_html__('View details', 'snapshot-core')
        );

        $latest = self::enabled() ? self::release('latest') : null;

        if ($latest && version_compare($latest['version'], Core::version(), '>') && (int) $latest['version'] > (int) Core::version()) {
            $links[] = sprintf(
                '<a href="%s">%s</a>',
                esc_url($latest['url']),
                esc_html(sprintf(__('Version %s is a major upgrade: read its upgrade notes, update the theme, then install it by hand.', 'snapshot-core'), $latest['version']))
            );
        }

        return $links;
    }

    public static function flush(): void
    {
        delete_site_transient(self::CACHE);
    }

    public static function release(string $channel): ?array
    {
        $cache = get_site_transient(self::CACHE);
        $cache = is_array($cache) ? $cache : [];

        if (array_key_exists($channel, $cache)) {
            return $cache[$channel];
        }

        $response = wp_remote_get(self::channelUrl($channel), ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $release = $code === 200 ? self::parse(json_decode(wp_remote_retrieve_body($response), true)) : null;

        $cache[$channel] = $release;
        set_site_transient(self::CACHE, $cache, $release ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);

        return $release;
    }

    public static function parse($data): ?array
    {
        if (! is_array($data) || ! is_string($data['version'] ?? null) || ! preg_match('/^\d+\.\d+\.\d+$/', $data['version'])) {
            return null;
        }

        $prefix = (string) apply_filters('snap/core/package_prefix', 'https://github.com/'.self::REPOSITORY.'/releases/download/');
        $package = (string) ($data['package'] ?? '');

        if (! str_starts_with($package, $prefix) || ! wp_http_validate_url($package)) {
            return null;
        }

        $text = fn (string $key) => is_scalar($data[$key] ?? null) ? sanitize_text_field((string) $data[$key]) : '';

        return [
            'version' => $data['version'],
            'package' => $package,
            'url' => esc_url_raw((string) ($data['url'] ?? 'https://github.com/'.self::REPOSITORY)),
            'requires' => $text('requires'),
            'requires_php' => $text('requires_php'),
            'tested' => $text('tested'),
            'released' => $text('released'),
            'notes' => is_string($data['notes'] ?? null) ? $data['notes'] : '',
        ];
    }

    private static function notes(array $release): string
    {
        $html = '<h4>'.esc_html($release['version']).($release['released'] !== '' ? ' ('.esc_html($release['released']).')' : '').'</h4>';
        $items = [];
        $paragraph = '';
        $flush = function () use (&$html, &$items, &$paragraph) {
            if ($paragraph !== '') {
                $html .= '<p>'.self::inline($paragraph).'</p>';
                $paragraph = '';
            }

            if ($items !== []) {
                $html .= '<ul>'.implode('', array_map(fn ($item) => '<li>'.self::inline($item).'</li>', $items)).'</ul>';
                $items = [];
            }
        };

        foreach (preg_split('/\R/', $release['notes']) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                $flush();
            } elseif (preg_match('/^#+\s+(.+)$/', $line, $heading)) {
                $flush();
                $html .= '<h4>'.self::inline($heading[1]).'</h4>';
            } elseif (preg_match('/^(?:-|\d+\.)\s+(.+)$/', $line, $item)) {
                if ($paragraph !== '') {
                    $flush();
                }

                $items[] = $item[1];
            } elseif ($items !== []) {
                $items[count($items) - 1] .= ' '.$line;
            } else {
                $paragraph = trim($paragraph.' '.$line);
            }
        }

        $flush();

        return $html.sprintf('<p><a href="%s">%s</a></p>', esc_url($release['url']), esc_html__('Full release notes', 'snapshot-core'));
    }

    private static function inline(string $text): string
    {
        $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', esc_html($text));
        $html = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html);

        return preg_replace_callback('/\[([^\]]+)\]\((https:\/\/[^)\s]+)\)/', fn ($m) => '<a href="'.esc_url(html_entity_decode($m[2])).'">'.$m[1].'</a>', $html);
    }
}

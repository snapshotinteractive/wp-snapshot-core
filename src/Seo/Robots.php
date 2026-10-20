<?php

namespace Snapshot\Seo;

use WP_Post;

class Robots
{
    public const LLMS_CACHE = 'snapshot_seo_llms';

    public function __construct(private Engine $engine) {}

    public function boot(bool $robots, bool $llms): void
    {
        if ($robots) {
            add_filter('robots_txt', [$this, 'robots'], 20, 2);
        }

        if ($llms) {
            add_action('parse_request', [$this, 'serveLlms'], 0);
            add_action('wp_head', fn () => print ('<link rel="alternate" type="text/markdown" title="llms.txt" href="'.esc_url(home_url('/llms.txt')).'">'."\n"), 4);

            foreach (['save_post', 'deleted_post', 'trashed_post', 'edited_term', 'wp_update_nav_menu'] as $hook) {
                add_action($hook, [$this, 'forget']);
            }
        }
    }

    public function robots(string $output, $public): string
    {
        if (! $public) {
            return $output;
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($output));
        $sitemaps = array_values(array_filter($lines, fn ($line) => stripos($line, 'sitemap:') === 0));
        $rules = array_values(array_filter($lines, fn ($line) => stripos($line, 'sitemap:') !== 0));

        while ($rules !== [] && trim((string) end($rules)) === '') {
            array_pop($rules);
        }

        $extra = trim(str_replace("\r", '', $this->engine->settings()->string('robots_extra')));

        if ($extra !== '') {
            if (preg_match('/^\s*user-agent:/im', $extra)) {
                $rules[] = '';
            }

            array_push($rules, ...explode("\n", $extra));
        }

        foreach ($this->blockedCrawlers() as $agent) {
            array_push($rules, '', "User-agent: {$agent}", 'Disallow: /');
        }

        return implode("\n", $rules).($sitemaps ? "\n\n".implode("\n", $sitemaps) : '')."\n";
    }

    public function blockedCrawlers(): array
    {
        $policy = $this->engine->settings()->string('ai_policy');

        if ($policy === 'allow') {
            return [];
        }

        return array_keys(array_filter(snap_seo_ai_crawlers(), fn ($crawler) => $policy === 'block' || $crawler['purpose'] === 'training'));
    }

    public function serveLlms(): void
    {
        if ($this->engine->requestPath() !== '/llms.txt') {
            return;
        }

        status_header(200);
        header('Content-Type: text/markdown; charset=utf-8');
        header('X-Robots-Tag: noindex');
        header('Cache-Control: public, max-age=3600');
        echo $this->llms();
        exit;
    }

    public function forget(): void
    {
        delete_transient(self::LLMS_CACHE);
    }

    public function llms(): string
    {
        $cached = get_transient(self::LLMS_CACHE);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $identity = $this->engine->identity();
        $settings = $this->engine->settings();
        $intro = $settings->string('llms_intro');
        $out = ['# '.$identity->name(), ''];

        if ($description = $identity->description()) {
            $out[] = '> '.$description;
            $out[] = '';
        }

        if ($intro !== '' && $intro !== $description) {
            $out[] = $intro;
            $out[] = '';
        }

        $key = $this->keyPages();

        if ($key) {
            $out[] = '## '.__('Key pages', 'snapshot-core');
            $out[] = '';
            array_push($out, ...$key);
            $out[] = '';
        }

        foreach (snap_seo_post_types() as $type => $config) {
            if (! $settings->bool("pt_{$type}_llms") || ! $settings->bool("pt_{$type}_index")) {
                continue;
            }

            $section = $this->typeSection($type, $config);

            if ($section) {
                array_push($out, ...$section);
            }
        }

        $out[] = '## '.__('Machine readable', 'snapshot-core');
        $out[] = '';
        $out[] = '- ['.__('XML sitemap', 'snapshot-core').']('.home_url('/wp-sitemap.xml').')';
        $out[] = '';

        $text = implode("\n", $out);
        set_transient(self::LLMS_CACHE, $text, 12 * HOUR_IN_SECONDS);

        return $text;
    }

    private function keyPages(): array
    {
        $ids = get_posts([
            'post_type' => snap_seo_editor_types(),
            'post_status' => 'publish',
            'posts_per_page' => 30,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => [
                'relation' => 'OR',
                ['key' => Meta::KEYS['key_page'], 'value' => '1'],
                ['key' => '_yoast_wpseo_is_cornerstone', 'value' => '1'],
            ],
            'orderby' => 'menu_order title',
            'order' => 'ASC',
        ]);

        $lines = [];
        $seen = [];

        foreach (array_unique($ids) as $id) {
            $post = get_post($id);

            if ($post instanceof WP_Post && $post->post_status === 'publish' && ($line = $this->entry($post))) {
                $lines[] = $line;
                $seen[untrailingslashit((string) get_permalink($post))] = true;
            }
        }

        $locations = get_nav_menu_locations();
        $menu = isset($locations['primary_navigation']) ? wp_get_nav_menu_items($locations['primary_navigation']) : [];
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        foreach (is_array($menu) ? $menu : [] as $item) {
            $url = (string) strtok((string) $item->url, '#');

            if ($url === '' || wp_parse_url($url, PHP_URL_HOST) !== $host) {
                continue;
            }

            $postId = $item->type === 'post_type' ? (int) $item->object_id : url_to_postid($url);
            $post = $postId ? get_post($postId) : null;
            $key = untrailingslashit($post instanceof WP_Post ? (string) get_permalink($post) : $url);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            if ($post instanceof WP_Post) {
                if ($post->post_status === 'publish' && ($line = $this->entry($post))) {
                    $lines[] = $line;
                }

                continue;
            }

            $lines[] = '- ['.str_replace(['[', ']'], ['(', ')'], wp_strip_all_tags($item->title)).']('.$url.')';
        }

        return $lines;
    }

    private function typeSection(string $type, array $config): array
    {
        $limit = $this->engine->settings()->int('llms_entries');
        $archive = $type === 'post'
            ? ((int) get_option('page_for_posts') ? get_permalink((int) get_option('page_for_posts')) : '')
            : ($config['has_archive'] ? get_post_type_archive_link($type) : '');
        $label = snap_post_type_config($type) ? snap_archive_label($type) : $config['plural'];
        $lines = ['## '.$label, ''];
        $intro = trim(wp_strip_all_tags((string) snap_archive_setting($type, 'intro', '')));

        if ($intro !== '') {
            $lines[] = $intro;
            $lines[] = '';
        }

        if ($archive) {
            $lines[] = '- ['.sprintf(__('All %s', 'snapshot-core'), strtolower($config['plural'])).']('.$archive.')';
        }

        if ($limit > 0) {
            $args = [
                'post_type' => $type,
                'post_status' => 'publish',
                'posts_per_page' => $limit,
                'no_found_rows' => true,
                'has_password' => false,
                'post__not_in' => Sitemaps::excludedPostIds($type, false),
            ];

            foreach (get_posts($args) as $post) {
                if ($line = $this->entry($post)) {
                    $lines[] = $line;
                }
            }
        }

        if (count($lines) <= 2 + ($intro !== '' ? 2 : 0)) {
            return [];
        }

        $lines[] = '';

        return $lines;
    }

    private function entry(WP_Post $post): string
    {
        $resolved = $this->engine->resolver()->resolve(Context::forPost($post));

        if (! $resolved['robots']['index']) {
            return '';
        }

        $title = str_replace(['[', ']'], ['(', ')'], snap_plain_title($post));
        $line = "- [{$title}](".get_permalink($post).')';

        return $resolved['description'] !== '' ? $line.': '.$resolved['description'] : $line;
    }
}

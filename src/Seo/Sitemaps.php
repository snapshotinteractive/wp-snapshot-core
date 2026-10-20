<?php

namespace Snapshot\Seo;

use WP_Post;
use WP_Query;

class Sitemaps
{
    public const CHECK = 'snapshot_seo_sitemap_check';

    private const LEGACY = '~^/(?:sitemap_index|sitemap-index|sitemap)\.xml$|^/[a-z0-9_-]+-sitemap\d*\.xml$~i';

    private const URL_LIMIT = 50000;

    private const REMOTE_CHECKS = 20;

    private const DEEP_CHECKS = 5000;

    private bool $applyRules = true;

    public function __construct(private Engine $engine) {}

    public function boot(): void
    {
        add_filter('wp_sitemaps_enabled', fn ($enabled) => $enabled && (bool) get_option('blog_public'));
        add_filter('wp_sitemaps_add_provider', fn ($provider, $name) => $name === 'users' ? false : $provider, 20, 2);
        add_filter('wp_sitemaps_post_types', [$this, 'postTypes'], 20);
        add_filter('wp_sitemaps_taxonomies', [$this, 'taxonomies'], 20);
        add_filter('wp_sitemaps_posts_query_args', [$this, 'postsQuery'], 20, 2);
        add_filter('wp_sitemaps_taxonomies_query_args', [$this, 'termsQuery'], 20, 2);
        add_filter('wp_sitemaps_posts_entry', [$this, 'postEntry'], 20, 2);
        add_filter('wp_sitemaps_posts_show_on_front_entry', [$this, 'frontEntry'], 20);
        add_filter('wp_sitemaps_max_urls', fn () => $this->engine->settings()->int('sitemap_max_urls'), 20);
        add_action('wp_sitemaps_init', [$this, 'register']);
        add_filter('wp_sitemaps_posts_pre_url_list', [$this, 'filterPosts'], 20, 3);
        add_filter('wp_sitemaps_taxonomies_pre_url_list', [$this, 'filterTerms'], 20, 3);

        if ($this->engine->settings()->bool('sitemap_legacy_urls')) {
            add_action('template_redirect', [$this, 'legacy'], 0);
        }
    }

    public function register($sitemaps): void
    {
        $sitemaps->registry->add_provider('extra', new SitemapExtra($this));
    }

    public function legacy(): void
    {
        $path = $this->engine->requestPath();

        if ($path !== '/wp-sitemap.xml' && preg_match(self::LEGACY, $path)) {
            wp_safe_redirect(home_url('/wp-sitemap.xml'), 301, 'Snapshot SEO');
            exit;
        }
    }

    public function postTypes(array $types): array
    {
        return array_filter($types, fn ($object, $type) => $this->includes('pt', $type), ARRAY_FILTER_USE_BOTH);
    }

    public function taxonomies(array $taxonomies): array
    {
        return array_filter($taxonomies, fn ($object, $taxonomy) => $this->includes('tax', $taxonomy), ARRAY_FILTER_USE_BOTH);
    }

    public function includes(string $kind, string $name): bool
    {
        $settings = $this->engine->settings();
        $schema = $settings->schema();

        foreach (["{$kind}_{$name}_index", "{$kind}_{$name}_sitemap"] as $key) {
            if (array_key_exists($key, $schema) && ! $settings->bool($key)) {
                return false;
            }
        }

        return true;
    }

    public function postsQuery(array $args, string $type): array
    {
        $args['has_password'] = false;

        if ($excluded = self::excludedPostIds($type)) {
            $args['post__not_in'] = array_values(array_unique(array_merge(array_map('intval', (array) ($args['post__not_in'] ?? [])), $excluded)));
        }

        return $args;
    }

    public function termsQuery(array $args, string $taxonomy): array
    {
        if ($excluded = self::excludedTermIds($taxonomy)) {
            $args['exclude'] = array_values(array_unique(array_merge(wp_parse_id_list($args['exclude'] ?? []), $excluded)));
        }

        return $args;
    }

    public static function excludedPostIds(string $type, bool $sitemap = true): array
    {
        global $wpdb;

        $robots = Meta::KEYS['robots'];
        $sql = "SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m
            INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = %s
            WHERE (m.meta_key = %s AND m.meta_value = 'noindex')
            OR (m.meta_key = '_yoast_wpseo_meta-robots-noindex' AND m.meta_value = '1'
                AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} r WHERE r.post_id = m.post_id AND r.meta_key = %s))";
        $values = [$type, $robots, $robots];

        if ($sitemap) {
            $sql .= " OR (m.meta_key = %s AND m.meta_value = 'exclude')";
            $values[] = Meta::KEYS['sitemap'];
        }

        return array_map('intval', $wpdb->get_col($wpdb->prepare($sql, $values)));
    }

    public static function excludedTermIds(string $taxonomy): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT m.term_id FROM {$wpdb->termmeta} m
            INNER JOIN {$wpdb->term_taxonomy} t ON t.term_id = m.term_id AND t.taxonomy = %s
            WHERE (m.meta_key = %s AND m.meta_value = 'noindex')
            OR (m.meta_key = %s AND m.meta_value = 'exclude')",
            $taxonomy, Meta::KEYS['robots'], Meta::KEYS['sitemap']
        )));
    }

    public function filterPosts($list, string $type, int $page)
    {
        return $this->filterList($list, 'wp_sitemaps_posts_pre_url_list', [$this, 'filterPosts'], fn () => (new \WP_Sitemaps_Posts)->get_url_list($page, $type));
    }

    public function filterTerms($list, string $taxonomy, int $page)
    {
        return $this->filterList($list, 'wp_sitemaps_taxonomies_pre_url_list', [$this, 'filterTerms'], fn () => (new \WP_Sitemaps_Taxonomies)->get_url_list($page, $taxonomy));
    }

    private function filterList($list, string $hook, callable $self, callable $build)
    {
        if ($list !== null || ! $this->applyRules || $this->rules() === []) {
            return $list;
        }

        remove_filter($hook, $self, 20);
        $urls = (array) $build();
        add_filter($hook, $self, 20, 3);

        return array_values(array_filter($urls, fn ($entry) => empty($entry['loc']) || ! $this->matchingRule((string) $entry['loc'])));
    }

    public function postEntry(array $entry, WP_Post $post): array
    {
        $source = $this->engine->settings()->string('sitemap_lastmod');

        unset($entry['lastmod']);

        if ($source !== 'none') {
            $date = $source === 'published' ? $post->post_date_gmt : $post->post_modified_gmt;
            $entry['lastmod'] = wp_date(DATE_W3C, strtotime($date.' UTC'));
        }

        return $entry;
    }

    public function frontEntry(array $entry): array
    {
        $latest = get_lastpostmodified('GMT');

        unset($entry['lastmod']);

        if ($latest && $this->engine->settings()->string('sitemap_lastmod') !== 'none') {
            $entry['lastmod'] = wp_date(DATE_W3C, strtotime($latest.' UTC'));
        }

        return $entry;
    }

    public function rules(): array
    {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $rules = [];

        foreach ($this->engine->settings()->lines('sitemap_exclude_rules') as $line) {
            $prefix = str_ends_with($line, '*');
            $line = rtrim($line, '*');

            if (preg_match('#^https?://#i', $line)) {
                if (strcasecmp((string) wp_parse_url($line, PHP_URL_HOST), $host) !== 0) {
                    continue;
                }

                $line = (string) wp_parse_url($line, PHP_URL_PATH);
            }

            $path = '/'.ltrim(strtolower($line), '/');
            $rules[] = ['raw' => $line.($prefix ? '*' : ''), 'path' => $prefix ? $path : untrailingslashit($path), 'prefix' => $prefix];
        }

        return $rules;
    }

    public function matchingRule(string $url): ?string
    {
        $path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));
        $path = '/'.ltrim($path, '/');

        foreach ($this->rules() as $rule) {
            if ($rule['prefix'] ? str_starts_with($path, $rule['path']) : untrailingslashit($path) === $rule['path']) {
                return $rule['raw'];
            }
        }

        return null;
    }

    public function extraUrls(): array
    {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $urls = [];

        foreach ($this->engine->settings()->lines('sitemap_extra_urls') as $line) {
            $parts = preg_split('/\s+/', $line);
            $address = (string) array_shift($parts);
            $date = (string) ($parts[0] ?? '');
            $url = preg_match('#^https?://#i', $address) ? $address : home_url('/'.ltrim($address, '/'));

            if (strcasecmp((string) wp_parse_url($url, PHP_URL_HOST), $host) !== 0 || ! wp_http_validate_url($url)) {
                continue;
            }

            $time = preg_match('/^\d{4}-\d{2}-\d{2}/', $date) ? strtotime($date) : false;
            $urls[$url] = ['loc' => esc_url_raw($url), 'lastmod' => $time ? gmdate(DATE_W3C, $time) : ''];
        }

        return array_values($urls);
    }

    public function sections(): array
    {
        $sections = [];

        foreach (snap_seo_post_types() as $type => $config) {
            if (! post_type_exists($type)) {
                continue;
            }

            $sections["pt:{$type}"] = [
                'kind' => 'pt',
                'name' => $type,
                'label' => $config['plural'],
                'setting' => "pt_{$type}_sitemap",
                'included' => $this->engine->settings()->bool("pt_{$type}_sitemap"),
                'searchable' => $this->engine->settings()->bool("pt_{$type}_index"),
                'count' => $this->includes('pt', $type) ? $this->countPosts($type) : 0,
                'excluded' => $this->countExcludedPosts($type),
                'xml' => get_sitemap_url('posts', $type),
            ];
        }

        foreach (snap_seo_taxonomies() as $taxonomy => $config) {
            if (! taxonomy_exists($taxonomy)) {
                continue;
            }

            $sections["tax:{$taxonomy}"] = [
                'kind' => 'tax',
                'name' => $taxonomy,
                'label' => $config['plural'],
                'setting' => "tax_{$taxonomy}_sitemap",
                'included' => $this->engine->settings()->bool("tax_{$taxonomy}_sitemap"),
                'searchable' => $this->engine->settings()->bool("tax_{$taxonomy}_index"),
                'count' => $this->includes('tax', $taxonomy) ? $this->countTerms($taxonomy) : 0,
                'excluded' => $this->countExcludedTerms($taxonomy),
                'xml' => get_sitemap_url('taxonomies', $taxonomy),
            ];
        }

        return $sections;
    }

    public function countPosts(string $type): int
    {
        $args = $this->postsQuery(['post_type' => $type, 'post_status' => 'publish', 'no_found_rows' => false], $type);
        $query = new WP_Query(['posts_per_page' => 1, 'fields' => 'ids', 'ignore_sticky_posts' => true] + $args);

        return (int) $query->found_posts;
    }

    public function countTerms(string $taxonomy): int
    {
        $count = get_terms($this->termsQuery(['taxonomy' => $taxonomy, 'hide_empty' => true, 'fields' => 'count'], $taxonomy));

        return is_numeric($count) ? (int) $count : 0;
    }

    public function countExcludedPosts(string $type): int
    {
        $query = new WP_Query([
            'post_type' => $type,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [['key' => Meta::KEYS['sitemap'], 'value' => 'exclude']],
        ]);

        return (int) $query->found_posts;
    }

    public function countExcludedTerms(string $taxonomy): int
    {
        $count = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'count', 'meta_query' => [['key' => Meta::KEYS['sitemap'], 'value' => 'exclude']]]);

        return is_numeric($count) ? (int) $count : 0;
    }

    public function postStatus(WP_Post $post, array $resolved): array
    {
        if (! $this->includes('pt', $post->post_type)) {
            return ['code' => 'type', 'label' => __('Content type left out', 'snapshot-core')];
        }

        if (! $resolved['robots']['index']) {
            return ['code' => 'hidden', 'label' => sprintf(__('Hidden from search: %s', 'snapshot-core'), $resolved['robots']['reason'])];
        }

        if ($this->engine->meta()->post($post->ID, 'sitemap') === 'exclude') {
            return ['code' => 'excluded', 'label' => __('Left out by hand', 'snapshot-core')];
        }

        if ($rule = $this->matchingRule((string) get_permalink($post))) {
            return ['code' => 'rule', 'label' => sprintf(__('Left out by the rule %s', 'snapshot-core'), $rule)];
        }

        return ['code' => 'in', 'label' => __('In the sitemap', 'snapshot-core')];
    }

    public function termStatus(\WP_Term $term, array $resolved): array
    {
        if (! $this->includes('tax', $term->taxonomy)) {
            return ['code' => 'type', 'label' => __('Taxonomy left out', 'snapshot-core')];
        }

        if (! $resolved['robots']['index']) {
            return ['code' => 'hidden', 'label' => sprintf(__('Hidden from search: %s', 'snapshot-core'), $resolved['robots']['reason'])];
        }

        if ((int) $term->count === 0) {
            return ['code' => 'empty', 'label' => __('Empty, so not listed', 'snapshot-core')];
        }

        if ($this->engine->meta()->term($term->term_id, 'sitemap') === 'exclude') {
            return ['code' => 'excluded', 'label' => __('Left out by hand', 'snapshot-core')];
        }

        $link = get_term_link($term);

        if (is_string($link) && ($rule = $this->matchingRule($link))) {
            return ['code' => 'rule', 'label' => sprintf(__('Left out by the rule %s', 'snapshot-core'), $rule)];
        }

        return ['code' => 'in', 'label' => __('In the sitemap', 'snapshot-core')];
    }

    public function enabled(): bool
    {
        return $this->engine->modules()->on('sitemaps') && (bool) get_option('blog_public') && wp_sitemaps_get_server()->sitemaps_enabled();
    }

    public function urls(int $limit = self::URL_LIMIT): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $urls = [];

        foreach (wp_sitemaps_get_server()->registry->get_providers() as $name => $provider) {
            $subtypes = array_keys((array) $provider->get_object_subtypes()) ?: [''];

            foreach ($subtypes as $subtype) {
                $pages = (int) $provider->get_max_num_pages($subtype);

                for ($page = 1; $page <= $pages; $page++) {
                    foreach ((array) $provider->get_url_list($page, $subtype) as $entry) {
                        if (empty($entry['loc'])) {
                            continue;
                        }

                        $urls[] = ['loc' => (string) $entry['loc'], 'section' => $subtype !== '' ? "{$name}:{$subtype}" : $name];

                        if (count($urls) >= $limit) {
                            return $urls;
                        }
                    }
                }
            }
        }

        return $urls;
    }

    public function check(): array
    {
        $issues = [];
        $add = function (string $level, string $type, string $url, string $detail) use (&$issues) {
            if (count($issues) < 500) {
                $issues[] = compact('level', 'type', 'url', 'detail');
            }
        };

        if (! get_option('blog_public')) {
            $add('fail', 'private', home_url('/wp-sitemap.xml'), __('Search engines are discouraged under Settings, Reading, so WordPress serves no sitemap at all.', 'snapshot-core'));
        } elseif (! $this->engine->modules()->on('sitemaps')) {
            $add('warn', 'module', home_url('/wp-sitemap.xml'), __('The sitemap module is off, so the untuned WordPress sitemap is served.', 'snapshot-core'));
        }

        $this->applyRules = false;
        $all = $this->urls();
        $this->applyRules = true;

        $rules = $this->rules();
        $matched = [];
        $urls = [];

        foreach ($all as $item) {
            $rule = $rules ? $this->matchingRule($item['loc']) : null;

            if ($rule !== null) {
                $matched[$rule] = true;

                continue;
            }

            $urls[] = $item;
        }

        foreach ($rules as $rule) {
            if (! isset($matched[$rule['raw']])) {
                $add('info', 'rule', $rule['raw'], __('This rule matches no address in the sitemap. Check it for a typo.', 'snapshot-core'));
            }
        }

        $seen = [];
        $sections = [];
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $redirects = $this->exactRedirects();
        $patterns = $this->engine->modules()->on('redirects') && Schema::ready() ? $this->engine->redirects()->patterns() : [];
        $canonicals = $this->customCanonicals();

        foreach ($urls as $index => $item) {
            $url = $item['loc'];
            $key = strtolower(untrailingslashit($url));
            $sections[$item['section']] = ($sections[$item['section']] ?? 0) + 1;

            if (isset($seen[$key])) {
                $add('warn', 'duplicate', $url, sprintf(__('Listed twice, in %1$s and %2$s.', 'snapshot-core'), $seen[$key], $item['section']));
            }

            $seen[$key] = $item['section'];

            if (strcasecmp((string) wp_parse_url($url, PHP_URL_HOST), $host) !== 0) {
                $add('warn', 'offsite', $url, __('Points to another site. Search engines ignore addresses on other hosts.', 'snapshot-core'));
            }

            $path = Redirects::normalize($url);

            if (isset($redirects[$path])) {
                $add('warn', 'redirect', $url, sprintf(__('A %1$d redirect sends this address to %2$s. List the destination instead.', 'snapshot-core'), $redirects[$path]['code'], $redirects[$path]['target']));
            } elseif ($patterns && $index < self::DEEP_CHECKS && ($rule = $this->engine->redirects()->match($path)) && Redirects::normalize((string) $rule['target']) !== $path) {
                $add('warn', 'redirect', $url, sprintf(__('A %1$d redirect sends this address to %2$s. List the destination instead.', 'snapshot-core'), (int) ($rule['code'] ?? 301), (string) ($rule['target'] ?? '')));
            }

            if (isset($canonicals[$key])) {
                $add('warn', 'canonical', $url, sprintf(__('Its canonical points to %s, so search engines index that address instead.', 'snapshot-core'), $canonicals[$key]));
            }
        }

        foreach (array_slice($this->extraUrls(), 0, self::REMOTE_CHECKS) as $extra) {
            $response = wp_remote_head($extra['loc'], ['timeout' => 3, 'redirection' => 0]);
            $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
            $robots = is_wp_error($response) ? '' : (string) wp_remote_retrieve_header($response, 'x-robots-tag');

            if ($code !== 200) {
                $add($code === 0 ? 'warn' : 'fail', 'extra', $extra['loc'], $code === 0
                    ? __('Extra address did not answer.', 'snapshot-core')
                    : sprintf(__('Extra address answered %d instead of 200.', 'snapshot-core'), $code));
            } elseif (stripos($robots, 'noindex') !== false) {
                $add('warn', 'extra', $extra['loc'], __('Extra address sends a noindex header, so listing it contradicts itself.', 'snapshot-core'));
            }
        }

        $result = [
            'at' => time(),
            'total' => count($urls),
            'capped' => count($all) >= self::URL_LIMIT,
            'sections' => $sections,
            'issues' => $issues,
            'counts' => array_count_values(array_column($issues, 'level')),
        ];

        update_option(self::CHECK, $result, false);

        return $result;
    }

    private function exactRedirects(): array
    {
        global $wpdb;

        if (! $this->engine->modules()->on('redirects') || ! Schema::ready()) {
            return [];
        }

        $rows = (array) $wpdb->get_results('SELECT source, target, code FROM '.Schema::table('redirects')." WHERE enabled = 1 AND match_type = 'exact' LIMIT 100000", ARRAY_A);
        $map = [];

        foreach ($rows as $row) {
            if (Redirects::normalize((string) $row['target']) !== $row['source']) {
                $map[$row['source']] = ['target' => (string) $row['target'], 'code' => (int) $row['code']];
            }
        }

        return $map;
    }

    private function customCanonicals(): array
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' LIMIT 20000", Meta::KEYS['canonical']));
        $map = [];

        foreach ($rows as $row) {
            $permalink = strtolower(untrailingslashit((string) get_permalink((int) $row->post_id)));
            $canonical = (string) $row->meta_value;

            if ($permalink !== '' && strtolower(untrailingslashit($canonical)) !== $permalink) {
                $map[$permalink] = $canonical;
            }
        }

        return $map;
    }

    public function lastCheck(): ?array
    {
        $check = get_option(self::CHECK, null);

        return is_array($check) ? $check : null;
    }

    public static function indexableMetaQuery(): array
    {
        return [
            'relation' => 'OR',
            [
                'relation' => 'AND',
                ['key' => Meta::KEYS['robots'], 'compare' => 'NOT EXISTS'],
                [
                    'relation' => 'OR',
                    ['key' => '_yoast_wpseo_meta-robots-noindex', 'compare' => 'NOT EXISTS'],
                    ['key' => '_yoast_wpseo_meta-robots-noindex', 'value' => '1', 'compare' => '!='],
                ],
            ],
            ['key' => Meta::KEYS['robots'], 'value' => 'noindex', 'compare' => '!='],
        ];
    }

    public static function notExcludedMetaQuery(): array
    {
        return [
            'relation' => 'OR',
            ['key' => Meta::KEYS['sitemap'], 'compare' => 'NOT EXISTS'],
            ['key' => Meta::KEYS['sitemap'], 'value' => 'exclude', 'compare' => '!='],
        ];
    }
}

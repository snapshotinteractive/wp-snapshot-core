<?php

namespace Snapshot\Seo;

use WP_Post;

class Redirects
{
    public const CODES = [301 => '301 Moved permanently', 302 => '302 Found, temporary', 307 => '307 Temporary redirect', 308 => '308 Permanent redirect', 410 => '410 Gone', 451 => '451 Unavailable for legal reasons'];

    public const MATCH = ['exact' => 'Exact address', 'prefix' => 'Starts with', 'regex' => 'Regular expression'];

    private const PATTERNS = 'snapshot_seo_redirect_patterns';

    public const COUNT = 'snapshot_seo_redirect_count';

    private const MAX_404_ROWS = 20000;

    private array $before = [];

    public function __construct(private Engine $engine) {}

    public function boot(bool $redirects, bool $monitor): void
    {
        if ($redirects) {
            add_action('template_redirect', [$this, 'redirect'], 0);

            if ($this->engine->settings()->bool('redirect_on_slug_change')) {
                add_action('pre_post_update', [$this, 'remember'], 10, 1);
                add_action('post_updated', [$this, 'changed'], 10, 3);
            }

            if ($this->engine->settings()->string('redirect_trashed') !== 'none') {
                add_action('wp_trash_post', [$this, 'trashed']);
            }

            add_action('untrashed_post', [$this, 'restored']);
            add_action('transition_post_status', [$this, 'published'], 10, 3);
        }

        if ($monitor) {
            add_action('template_redirect', [$this, 'record'], 9999);
        }
    }

    public static function normalize(string $url): string
    {
        $url = trim(html_entity_decode($url));
        $parts = wp_parse_url($url);

        if (! is_array($parts)) {
            return '';
        }

        $home = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        $path = '/'.ltrim(rawurldecode((string) ($parts['path'] ?? '/')), '/');

        if ($home !== '' && $home !== '/' && str_starts_with($path, rtrim($home, '/').'/')) {
            $path = substr($path, strlen(rtrim($home, '/')));
        }

        $path = strtolower(preg_replace('#/+#', '/', $path));
        $path = $path === '/' ? '/' : rtrim($path, '/');

        return isset($parts['query']) && $parts['query'] !== '' ? $path.'?'.$parts['query'] : $path;
    }

    public function redirect(): void
    {
        if (is_admin() || wp_doing_ajax() || wp_is_serving_rest_request() || (int) get_option(self::COUNT, 1) === 0) {
            return;
        }

        $request = self::normalize((string) ($_SERVER['REQUEST_URI'] ?? '/'));
        $match = $this->match($request);

        if (! $match) {
            return;
        }

        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE '.Schema::table('redirects').' SET hits = hits + 1, last_hit = %s WHERE id = %d', current_time('mysql', true), $match['id']));

        if (in_array((int) $match['code'], [410, 451], true)) {
            global $wp_query;
            $wp_query->set_404();
            status_header((int) $match['code']);
            nocache_headers();
            header('X-Robots-Tag: noindex', true);

            return;
        }

        $target = $match['target'];

        if (str_starts_with($target, '/')) {
            $path = (string) strtok($target, '?#');
            $rest = substr($target, strlen($path));
            $target = home_url(str_contains(basename($path), '.') ? $path : user_trailingslashit($path)).$rest;
        }

        if (! empty($_SERVER['QUERY_STRING']) && ! str_contains($target, '?') && ! str_contains($request, '?')) {
            $target .= '?'.wp_unslash((string) $_SERVER['QUERY_STRING']);
        }

        if (self::normalize($target) === $request && wp_parse_url($target, PHP_URL_HOST) === wp_parse_url(home_url(), PHP_URL_HOST)) {
            return;
        }

        nocache_headers();
        wp_redirect(esc_url_raw($target), (int) $match['code'], 'Snapshot SEO');
        exit;
    }

    public function match(string $request): ?array
    {
        global $wpdb;

        $path = strtok($request, '?') ?: '/';
        $table = Schema::table('redirects');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, source, target, code FROM {$table} WHERE match_type = 'exact' AND enabled = 1 AND source IN (%s, %s) ORDER BY CHAR_LENGTH(source) DESC LIMIT 1",
            $request,
            $path
        ), ARRAY_A);

        if ($row) {
            return $row;
        }

        foreach ($this->patterns() as $pattern) {
            if ($pattern['match_type'] === 'prefix') {
                $source = rtrim($pattern['source'], '/');

                if ($path === $source || str_starts_with($path, $source.'/')) {
                    $rest = substr($path, strlen($source));

                    return $pattern + ['target' => $pattern['target'] === '' ? '/' : rtrim($pattern['target'], '/').$rest];
                }

                continue;
            }

            $regex = '~'.str_replace('~', '\~', $pattern['source']).'~i';

            if (@preg_match($regex, $request, $groups) || @preg_match($regex, $path, $groups)) {
                $target = preg_replace_callback('/\$(\d+)/', fn ($m) => $groups[(int) $m[1]] ?? '', $pattern['target']);

                return array_merge($pattern, ['target' => $target]);
            }
        }

        return null;
    }

    public function patterns(): array
    {
        $cached = get_option(self::PATTERNS, null);

        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;
        $rows = $wpdb->get_results('SELECT id, source, target, code, match_type FROM '.Schema::table('redirects')." WHERE match_type IN ('prefix', 'regex') AND enabled = 1 ORDER BY match_type ASC, CHAR_LENGTH(source) DESC LIMIT 500", ARRAY_A) ?: [];
        update_option(self::PATTERNS, $rows, true);

        return $rows;
    }

    public function flush(): void
    {
        global $wpdb;
        delete_option(self::PATTERNS);
        update_option(self::COUNT, (int) $wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('redirects').' WHERE enabled = 1'), true);
    }

    public function add(string $source, string $target, int $code = 301, string $matchType = 'exact', string $origin = 'manual', int $objectId = 0, string $note = ''): array
    {
        global $wpdb;

        $matchType = array_key_exists($matchType, self::MATCH) ? $matchType : 'exact';
        $code = array_key_exists($code, self::CODES) ? $code : 301;
        $source = $matchType === 'regex' ? trim($source) : self::normalize($source);
        $target = trim($target);

        if ($source === '' || ($source === '/' && $matchType !== 'regex')) {
            return ['ok' => false, 'message' => __('The source address is empty or is the home page.', 'snapshot-core')];
        }

        if ($matchType === 'regex' && @preg_match('~'.str_replace('~', '\~', $source).'~', '') === false) {
            return ['ok' => false, 'message' => __('The regular expression is not valid.', 'snapshot-core')];
        }

        if (! in_array($code, [410, 451], true)) {
            if ($target === '') {
                return ['ok' => false, 'message' => __('A target address is needed for this redirect type.', 'snapshot-core')];
            }

            $target = $this->target($target);

            if ($matchType === 'exact' && self::normalize($target) === strtok($source, '?') && $this->isLocal($target)) {
                return ['ok' => false, 'message' => __('The source and target are the same address.', 'snapshot-core')];
            }
        } else {
            $target = '';
        }

        $table = Schema::table('redirects');
        $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE source = %s AND match_type = %s", $source, $matchType));
        $row = ['source' => mb_substr($source, 0, 191), 'match_type' => $matchType, 'target' => $target, 'code' => $code, 'enabled' => 1, 'origin' => $origin, 'object_id' => $objectId, 'note' => mb_substr($note, 0, 255)];

        if ($existing) {
            $wpdb->update($table, $row, ['id' => $existing]);
        } else {
            $wpdb->insert($table, $row + ['created_at' => current_time('mysql', true)]);
        }

        if ($matchType === 'exact' && $target !== '' && $this->isLocal($target)) {
            $wpdb->update($table, ['target' => $target], ['target' => $this->target($source)]);
            $wpdb->delete($table, ['source' => self::normalize($target), 'match_type' => 'exact', 'origin' => 'auto']);
        }

        $this->flush();

        return ['ok' => true, 'id' => $existing ?: (int) $wpdb->insert_id, 'message' => $existing ? __('Redirect updated.', 'snapshot-core') : __('Redirect added.', 'snapshot-core')];
    }

    public function update(int $id, array $fields): void
    {
        global $wpdb;

        $allowed = array_intersect_key($fields, array_flip(['enabled', 'target', 'code', 'note']));

        if ($allowed) {
            $wpdb->update(Schema::table('redirects'), $allowed, ['id' => $id]);
            $this->flush();
        }
    }

    public function remove(array $ids): int
    {
        global $wpdb;

        $ids = array_filter(array_map('intval', $ids));

        if (! $ids) {
            return 0;
        }

        $removed = (int) $wpdb->query('DELETE FROM '.Schema::table('redirects').' WHERE id IN ('.implode(',', $ids).')');
        $this->flush();

        return $removed;
    }

    public function query(array $filters, int $page = 1, int $perPage = 50): array
    {
        global $wpdb;

        $table = Schema::table('redirects');
        $where = ['1=1'];
        $args = [];

        if (($filters['search'] ?? '') !== '') {
            $like = '%'.$wpdb->esc_like($filters['search']).'%';
            $where[] = '(source LIKE %s OR target LIKE %s OR note LIKE %s)';
            array_push($args, $like, $like, $like);
        }

        if (($filters['origin'] ?? '') !== '') {
            $where[] = 'origin LIKE %s';
            $args[] = $wpdb->esc_like($filters['origin']).'%';
        }

        $sql = implode(' AND ', $where);
        $total = (int) $wpdb->get_var($args ? $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$sql}", ...$args) : "SELECT COUNT(*) FROM {$table} WHERE {$sql}");
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE {$sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", ...[...$args, $perPage, ($page - 1) * $perPage]), ARRAY_A) ?: [];

        return ['rows' => $rows, 'total' => $total, 'pages' => (int) ceil($total / $perPage)];
    }

    public function counts(): array
    {
        global $wpdb;

        return [
            'redirects' => (int) $wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('redirects')),
            'missing' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Schema::table('404').' WHERE last_seen >= %s AND bot = 0', gmdate('Y-m-d H:i:s', time() - WEEK_IN_SECONDS))),
        ];
    }

    public function remember(int $postId): void
    {
        $post = get_post($postId);

        if ($post && $post->post_status === 'publish' && is_post_type_viewable($post->post_type)) {
            $this->before[$postId] = (string) get_permalink($post);
        }
    }

    public function changed(int $postId, WP_Post $after, WP_Post $before): void
    {
        $old = $this->before[$postId] ?? '';
        unset($this->before[$postId]);

        if ($old === '' || $after->post_status !== 'publish' || wp_is_post_revision($postId)) {
            return;
        }

        $new = (string) get_permalink($after);

        if ($new === '' || self::normalize($old) === self::normalize($new)) {
            return;
        }

        $this->add($old, $new, 301, 'exact', 'auto', $postId, sprintf(__('Address of %s changed', 'snapshot-core'), snap_plain_title($after)));

        if (is_post_type_hierarchical($after->post_type) && get_children(['post_parent' => $postId, 'post_type' => $after->post_type, 'numberposts' => 1, 'fields' => 'ids'])) {
            $this->add($old, $new, 301, 'prefix', 'auto', $postId, sprintf(__('Child pages of %s moved', 'snapshot-core'), snap_plain_title($after)));
        }

        do_action('snap/seo/url_changed', $old, $new, $postId);
    }

    public function trashed(int $postId): void
    {
        $post = get_post($postId);

        if (! $post || $post->post_status !== 'publish' || ! is_post_type_viewable($post->post_type)) {
            return;
        }

        $url = (string) get_permalink($post);

        if ($this->engine->settings()->string('redirect_trashed') === '410') {
            $this->add($url, '', 410, 'exact', 'trash', $postId, sprintf(__('%s was deleted', 'snapshot-core'), snap_plain_title($post)));
        } else {
            $archive = $post->post_type === 'post' && get_option('page_for_posts') ? get_permalink((int) get_option('page_for_posts')) : get_post_type_archive_link($post->post_type);
            $parent = $post->post_parent ? get_permalink($post->post_parent) : '';
            $this->add($url, (string) ($parent ?: $archive ?: home_url('/')), 301, 'exact', 'trash', $postId, sprintf(__('%s was deleted', 'snapshot-core'), snap_plain_title($post)));
        }

        do_action('snap/seo/url_removed', $url, $postId);
    }

    public function restored(int $postId): void
    {
        global $wpdb;
        $wpdb->delete(Schema::table('redirects'), ['object_id' => $postId, 'origin' => 'trash']);
        $this->flush();
    }

    public function published(string $new, string $old, WP_Post $post): void
    {
        if ($new !== 'publish' || ! is_post_type_viewable($post->post_type)) {
            return;
        }

        global $wpdb;
        $removed = $wpdb->query($wpdb->prepare(
            'DELETE FROM '.Schema::table('redirects')." WHERE match_type = 'exact' AND source = %s AND origin IN ('auto', 'trash')",
            self::normalize((string) get_permalink($post))
        ));

        if ($removed) {
            $this->flush();
        }
    }

    public function record(): void
    {
        if (! is_404() || is_admin() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }

        $path = self::normalize((string) ($_SERVER['REQUEST_URI'] ?? '/'));

        foreach ($this->engine->settings()->lines('monitor_ignore') as $fragment) {
            if (str_contains($path, strtolower($fragment))) {
                return;
            }
        }

        if (mb_strlen($path) > 191 || $this->match($path)) {
            return;
        }

        global $wpdb;

        $referrer = (string) wp_get_raw_referer();
        $referrer = $referrer !== '' ? strtok(esc_url_raw($referrer), '?') : '';
        $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $bot = $agent === '' || (bool) preg_match('/bot|crawl|spider|slurp|curl|wget|python|scan|http/i', $agent);
        $now = current_time('mysql', true);

        $table = Schema::table('404');
        $referrer = mb_substr((string) $referrer, 0, 255);
        $updated = $referrer !== ''
            ? $wpdb->query($wpdb->prepare("UPDATE {$table} SET hits = hits + 1, last_seen = %s, bot = LEAST(bot, %d), referrer = %s WHERE path = %s", $now, $bot ? 1 : 0, $referrer, $path))
            : $wpdb->query($wpdb->prepare("UPDATE {$table} SET hits = hits + 1, last_seen = %s, bot = LEAST(bot, %d) WHERE path = %s", $now, $bot ? 1 : 0, $path));

        if (! $updated) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (path, hits, referrer, bot, first_seen, last_seen) VALUES (%s, 1, %s, %d, %s, %s)",
                $path,
                $referrer,
                $bot ? 1 : 0,
                $now,
                $now
            ));
        }
    }

    public function missing(array $filters, int $page = 1, int $perPage = 50): array
    {
        global $wpdb;

        $table = Schema::table('404');
        $where = ['1=1'];
        $args = [];

        if (empty($filters['bots'])) {
            $where[] = 'bot = 0';
        }

        if (($filters['search'] ?? '') !== '') {
            $where[] = 'path LIKE %s';
            $args[] = '%'.$wpdb->esc_like($filters['search']).'%';
        }

        $sql = implode(' AND ', $where);
        $total = (int) $wpdb->get_var($args ? $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$sql}", ...$args) : "SELECT COUNT(*) FROM {$table} WHERE {$sql}");
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE {$sql} ORDER BY hits DESC, last_seen DESC LIMIT %d OFFSET %d", ...[...$args, $perPage, ($page - 1) * $perPage]), ARRAY_A) ?: [];

        return ['rows' => $rows, 'total' => $total, 'pages' => (int) ceil($total / $perPage)];
    }

    public function suggest(string $path): string
    {
        $slug = sanitize_title(basename(strtok($path, '?')));

        if ($slug === '') {
            return '';
        }

        $found = get_posts([
            'name' => $slug,
            'post_type' => snap_seo_editor_types(),
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'no_found_rows' => true,
        ]);

        if (! $found) {
            $found = get_posts([
                's' => str_replace('-', ' ', $slug),
                'post_type' => snap_seo_editor_types(),
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'no_found_rows' => true,
            ]);
        }

        return $found ? (string) get_permalink($found[0]) : '';
    }

    public function forgetMissing(array $ids): void
    {
        global $wpdb;

        $ids = array_filter(array_map('intval', $ids));

        if ($ids) {
            $wpdb->query('DELETE FROM '.Schema::table('404').' WHERE id IN ('.implode(',', $ids).')');
        }
    }

    public function prune(): void
    {
        global $wpdb;

        $table = Schema::table('404');
        $days = $this->engine->settings()->int('monitor_retention_days');
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE last_seen < %s", gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)));

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        if ($count > self::MAX_404_ROWS) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} ORDER BY hits ASC, last_seen ASC LIMIT %d", $count - self::MAX_404_ROWS));
        }
    }

    public function export(): array
    {
        global $wpdb;

        return $wpdb->get_results('SELECT source, target, code, match_type, note FROM '.Schema::table('redirects').' ORDER BY id ASC', ARRAY_A) ?: [];
    }

    private function target(string $target): string
    {
        $target = trim($target);

        if ($this->isLocal($target)) {
            $parts = wp_parse_url($target);
            $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');
            $home = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');

            if ($home !== '' && str_starts_with($path, $home.'/')) {
                $path = substr($path, strlen($home));
            }

            return $path.(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
        }

        return esc_url_raw($target);
    }

    private function isLocal(string $url): bool
    {
        $host = wp_parse_url($url, PHP_URL_HOST);

        return $host === null || $host === false || strtolower((string) $host) === strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    }
}

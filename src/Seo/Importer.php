<?php

namespace Snapshot\Seo;

class Importer
{
    private const COPY = ['title', 'description', 'focuskw', 'robots', 'nofollow', 'canonical', 'social_title', 'social_description', 'social_image', 'breadcrumb_title', 'key_page'];

    public function __construct(private Engine $engine) {}

    public function redirectionTable(): ?string
    {
        global $wpdb;

        $table = $wpdb->prefix.'redirection_items';

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table ? $table : null;
    }

    public function redirectionCount(): int
    {
        global $wpdb;

        $table = $this->redirectionTable();

        return $table ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'enabled' AND match_type = 'url'") : 0;
    }

    public function sources(): array
    {
        global $wpdb;

        $rankMath = $wpdb->prefix.'rank_math_redirections';
        $legacy = $this->engine->meta()->hasLegacy();

        return [
            'yoast' => ['label' => 'Yoast SEO', 'entries' => $legacy['yoast'], 'terms' => $legacy['yoast_terms'], 'redirects' => count((array) get_option('wpseo-premium-redirects-base', []))],
            'rankmath' => ['label' => 'Rank Math', 'entries' => $legacy['rankmath'], 'terms' => 0, 'redirects' => $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $rankMath)) === $rankMath ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$rankMath} WHERE status = 'active'") : 0],
            'redirection' => ['label' => 'Redirection', 'entries' => 0, 'terms' => 0, 'redirects' => $this->redirectionCount()],
        ];
    }

    public function meta(bool $overwrite = false): array
    {
        global $wpdb;

        $meta = $this->engine->meta();
        $posts = 0;
        $terms = 0;

        $ids = $wpdb->get_col("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_yoast\\_wpseo\\_%' OR meta_key LIKE 'rank\\_math\\_%'");

        foreach (array_chunk(array_map('intval', $ids), 200) as $chunk) {
            update_meta_cache('post', $chunk);

            foreach ($chunk as $id) {
                $changed = false;

                foreach (self::COPY as $key) {
                    $metaKey = Meta::KEYS[$key];

                    if (! $overwrite && metadata_exists('post', $id, $metaKey) && get_post_meta($id, $metaKey, true) !== '') {
                        continue;
                    }

                    $value = $meta->legacyPost($id, $key);

                    if ($value !== '' && $value !== false && $value !== 0) {
                        update_post_meta($id, $metaKey, is_bool($value) ? ($value ? '1' : '0') : wp_slash((string) $value));
                        $changed = true;
                    }
                }

                $posts += $changed ? 1 : 0;
            }
        }

        $yoastTerms = get_option('wpseo_taxonomy_meta', []);

        foreach (is_array($yoastTerms) ? $yoastTerms : [] as $taxonomy => $rows) {
            foreach ((array) $rows as $termId => $row) {
                $changed = false;

                foreach (['title', 'description', 'robots', 'canonical', 'social_image', 'breadcrumb_title'] as $key) {
                    $metaKey = Meta::KEYS[$key];

                    if (! $overwrite && get_term_meta((int) $termId, $metaKey, true) !== '') {
                        continue;
                    }

                    $value = $meta->legacyTerm((int) $termId, $key);

                    if ($value !== '' && $value !== 0) {
                        update_term_meta((int) $termId, $metaKey, wp_slash((string) $value));
                        $changed = true;
                    }
                }

                $terms += $changed ? 1 : 0;
            }
        }

        return ['posts' => $posts, 'terms' => $terms];
    }

    public function redirection(): array
    {
        global $wpdb;

        $table = $this->redirectionTable();
        $added = 0;
        $skipped = 0;

        if (! $table) {
            return ['added' => 0, 'skipped' => 0];
        }

        $rows = $wpdb->get_results("SELECT url, regex, action_type, action_code, action_data, title FROM {$table} WHERE status = 'enabled' AND match_type = 'url' ORDER BY position ASC, id ASC", ARRAY_A) ?: [];

        foreach ($rows as $row) {
            $code = (int) $row['action_code'];
            $target = (string) $row['action_data'];

            if ($row['action_type'] === 'error') {
                $code = in_array($code, [410, 451], true) ? $code : 410;
                $target = '';
            } elseif ($row['action_type'] !== 'url') {
                $skipped++;

                continue;
            }

            $result = $this->engine->redirects()->add((string) $row['url'], $target, $code ?: 301, ((int) $row['regex']) ? 'regex' : 'exact', 'import:redirection', 0, (string) $row['title']);
            $result['ok'] ? $added++ : $skipped++;
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    public function rankMathRedirects(): array
    {
        global $wpdb;

        $table = $wpdb->prefix.'rank_math_redirections';
        $added = 0;
        $skipped = 0;

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return ['added' => 0, 'skipped' => 0];
        }

        foreach ($wpdb->get_results("SELECT sources, url_to, header_code FROM {$table} WHERE status = 'active'", ARRAY_A) ?: [] as $row) {
            foreach ((array) maybe_unserialize($row['sources']) as $source) {
                $comparison = $source['comparison'] ?? 'exact';
                $pattern = (string) ($source['pattern'] ?? '');
                $type = match ($comparison) {
                    'regex' => 'regex',
                    'start' => 'prefix',
                    default => 'exact',
                };

                if (! in_array($comparison, ['exact', 'regex', 'start'], true)) {
                    $skipped++;

                    continue;
                }

                $result = $this->engine->redirects()->add($type === 'exact' ? '/'.ltrim($pattern, '/') : $pattern, (string) $row['url_to'], (int) $row['header_code'] ?: 301, $type, 'import:rankmath');
                $result['ok'] ? $added++ : $skipped++;
            }
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    public function yoastRedirects(): array
    {
        $added = 0;
        $skipped = 0;

        foreach ((array) get_option('wpseo-premium-redirects-base', []) as $row) {
            $result = $this->engine->redirects()->add(
                ($row['format'] ?? 'plain') === 'regex' ? (string) ($row['origin'] ?? '') : '/'.ltrim((string) ($row['origin'] ?? ''), '/'),
                (string) ($row['url'] ?? ''),
                (int) ($row['type'] ?? 301),
                ($row['format'] ?? 'plain') === 'regex' ? 'regex' : 'exact',
                'import:yoast'
            );
            $result['ok'] ? $added++ : $skipped++;
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    public function csv(string $contents): array
    {
        $added = 0;
        $errors = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($contents));

        foreach ($lines as $number => $line) {
            $columns = str_getcsv($line, ',', '"', '');

            if ($number === 0 && strtolower(trim((string) ($columns[0] ?? ''))) === 'source') {
                continue;
            }

            if (count($columns) < 2 || trim((string) $columns[0]) === '') {
                continue;
            }

            $result = $this->engine->redirects()->add(
                trim((string) $columns[0]),
                trim((string) $columns[1]),
                (int) ($columns[2] ?? 301) ?: 301,
                in_array(trim((string) ($columns[3] ?? '')), ['exact', 'prefix', 'regex'], true) ? trim((string) $columns[3]) : 'exact',
                'import:csv',
                0,
                trim((string) ($columns[4] ?? ''))
            );

            if ($result['ok']) {
                $added++;
            } elseif (count($errors) < 10) {
                $errors[] = sprintf(__('Line %1$d: %2$s', 'snapshot-core'), $number + 1, $result['message']);
            }
        }

        return ['added' => $added, 'errors' => $errors];
    }
}

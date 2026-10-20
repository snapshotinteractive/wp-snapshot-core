<?php

namespace Snapshot\Seo;

class Audit
{
    public const REPORT = 'snapshot_seo_report';

    public const LAST_DAILY = 'snapshot_seo_last_daily';

    private const REPORT_LIMIT = 6000;

    public function __construct(private Engine $engine) {}

    public function checks(): array
    {
        $settings = $this->engine->settings();
        $modules = $this->engine->modules();
        $identity = $this->engine->identity();
        $base = admin_url('admin.php?page='.Admin\AdminPage::SLUG);
        $report = $this->report();
        $checks = [];

        $production = wp_get_environment_type() === 'production';
        $checks[] = $this->check('visibility', __('Search engines can index the site', 'snapshot-core'), get_option('blog_public')
            ? ['ok', __('Settings, Reading allows indexing.', 'snapshot-core')]
            : [$production ? 'fail' : 'info', $production ? __('Discourage search engines is ticked on production. Nothing will rank.', 'snapshot-core') : __('Discourage search engines is ticked. Correct for staging, untick it on production.', 'snapshot-core')], admin_url('options-reading.php'), 'check_visibility');

        $checks[] = $this->check('permalinks', __('Readable permalinks', 'snapshot-core'), get_option('permalink_structure')
            ? ['ok', __('Addresses use post names.', 'snapshot-core')]
            : ['fail', __('Plain permalinks are on, which breaks every pretty address.', 'snapshot-core')], admin_url('options-permalink.php'), 'check_permalinks');

        $checks[] = $this->check('https', __('HTTPS site address', 'snapshot-core'), str_starts_with(home_url(), 'https://')
            ? ['ok', __('The site address uses HTTPS.', 'snapshot-core')]
            : ['warn', __('The site address uses plain HTTP.', 'snapshot-core')], admin_url('options-general.php'), 'check_https');

        $overlaps = $modules->overlaps();
        $checks[] = $this->check('plugins', __('No conflicting SEO plugins', 'snapshot-core'), $overlaps === []
            ? ['ok', __('Built-in SEO is the only SEO system running.', 'snapshot-core')]
            : ['warn', sprintf(__('%s is active, so the modules it overlaps are paused. Deactivate it, or import its data from Tools first if the site already used it.', 'snapshot-core'), implode(', ', array_column($overlaps, 'label')))], admin_url('plugins.php'), 'defer');

        if (! $modules->master()) {
            $checks[] = $this->check('master', __('Built-in SEO', 'snapshot-core'), ['fail', __('The master switch is off. Pages have no descriptions, structured data or sitemap tuning.', 'snapshot-core')], $base.'&tab=modules', 'master');
        }

        $off = array_filter(array_keys($modules->registry()), fn ($module) => ! $modules->enabled($module));
        if ($modules->master() && $off) {
            $checks[] = $this->check('modules', __('Modules', 'snapshot-core'), ['warn', sprintf(__('Switched off: %s.', 'snapshot-core'), implode(', ', array_map(fn ($key) => $modules->registry()[$key]['label'], $off)))], $base.'&tab=modules', 'master');
        }

        $files = array_filter(['robots.txt' => 'robots', 'llms.txt' => 'llms', 'sitemap.xml' => 'sitemaps'], fn ($module, $file) => file_exists(ABSPATH.$file), ARRAY_FILTER_USE_BOTH);
        $checks[] = $this->check('files', __('No physical files override generated ones', 'snapshot-core'), $files === []
            ? ['ok', __('robots.txt, llms.txt and sitemap.xml are generated.', 'snapshot-core')]
            : ['warn', sprintf(__('Found at the site root: %s. Delete them so the generated versions are served.', 'snapshot-core'), implode(', ', array_keys($files)))], $base.'&tab=tools', 'check_physical_files');

        if ($modules->on('sitemaps') && get_option('blog_public')) {
            $sitemapCheck = $this->engine->sitemaps()->lastCheck();
            $problems = $sitemapCheck ? count(array_filter($sitemapCheck['issues'], fn ($issue) => $issue['level'] !== 'info')) : 0;
            $checks[] = $this->check('sitemap', __('Sitemap', 'snapshot-core'), match (true) {
                ! $sitemapCheck => ['info', __('Not checked yet. It runs nightly, or now from Sitemaps.', 'snapshot-core')],
                $problems > 0 => ['warn', sprintf(_n('%1$d problem among %2$d addresses.', '%1$d problems among %2$d addresses.', $problems, 'snapshot-core'), $problems, $sitemapCheck['total'])],
                default => ['ok', sprintf(__('%d addresses, no problems found.', 'snapshot-core'), $sitemapCheck['total'])],
            }, $base.'&tab=sitemaps', 'sitemap_check');
        }

        $logo = $identity->logo();
        $checks[] = $this->check('identity', __('Organization identity', 'snapshot-core'), $logo['url'] !== '' && $identity->description() !== ''
            ? ['ok', sprintf(__('%1$s, with logo and description. %2$d official profiles linked.', 'snapshot-core'), $identity->name(), count($identity->sameAs()))]
            : ['warn', __('Add a logo and a description under Site identity, or in Site Options.', 'snapshot-core')], $base.'&tab=settings&section=identity', 'org_name');

        $checks[] = $this->check('share', __('Default share image', 'snapshot-core'), $identity->shareImageId()
            ? ['ok', __('Shared links without a featured image still show a picture.', 'snapshot-core')]
            : ['warn', __('No default share image. Links to pages without a featured image share with no picture.', 'snapshot-core')], $base.'&tab=settings&section=social', 'og_image');

        $checks[] = $this->check('verification', __('Search Console verification', 'snapshot-core'), $settings->has('verify_google')
            ? ['ok', __('The Google verification tag is printed on the home page.', 'snapshot-core')]
            : ['info', __('No Google tag set. Fine if the domain is verified by DNS.', 'snapshot-core')], $base.'&tab=settings&section=verification', 'verification');

        if ($modules->on('redirects') && Schema::ready()) {
            $imported = (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM '.Schema::table('redirects')." WHERE origin LIKE 'import%'");
            $legacy = $this->engine->importer()->redirectionCount();

            if ($legacy > 0) {
                $checks[] = $this->check('redirection', __('Redirection plugin data', 'snapshot-core'), $imported > 0
                    ? ['ok', sprintf(__('%d redirects imported.', 'snapshot-core'), $imported)]
                    : ['warn', sprintf(__('The Redirection plugin holds %d redirects that are not imported yet.', 'snapshot-core'), $legacy)], $base.'&tab=tools', 'module_redirects');
            }

            $missing = $this->engine->redirects()->counts()['missing'];
            $checks[] = $this->check('404s', __('Broken addresses', 'snapshot-core'), $missing === 0
                ? ['ok', __('No unresolved 404s from visitors in the last week.', 'snapshot-core')]
                : ['warn', sprintf(_n('%d address visitors tried in the last week answered page not found.', '%d addresses visitors tried in the last week answered page not found.', $missing, 'snapshot-core'), $missing)], $base.'&tab=redirects&view=404', 'check_404s');
        }

        if ($report) {
            $issues = $report['issues'];
            $checks[] = $this->check('descriptions', __('Every page has a description', 'snapshot-core'), $issues['no_description'] === 0
                ? ['ok', sprintf(__('All %d indexable entries have one.', 'snapshot-core'), $report['total'])]
                : ['warn', sprintf(__('%1$d of %2$d indexable entries have no description.', 'snapshot-core'), $issues['no_description'], $report['total'])], $base.'&tab=content&issue=no_description', 'check_descriptions');

            $checks[] = $this->check('duplicates', __('Unique titles', 'snapshot-core'), $issues['duplicate_title'] === 0
                ? ['ok', __('No two indexable entries share a title.', 'snapshot-core')]
                : ['warn', sprintf(__('%d entries share their title with another entry.', 'snapshot-core'), $issues['duplicate_title'])], $base.'&tab=content&issue=duplicate_title', 'check_duplicates');

            $checks[] = $this->check('long_titles', __('Titles fit in search results', 'snapshot-core'), $issues['long_title'] === 0
                ? ['ok', __('No title is longer than 65 characters.', 'snapshot-core')]
                : ['info', sprintf(__('%d titles are longer than 65 characters and may be cut off.', 'snapshot-core'), $issues['long_title'])], $base.'&tab=content&issue=long_title', 'title_template');

            $checks[] = $this->check('key_pages', __('Key pages marked', 'snapshot-core'), $report['key_pages'] > 0
                ? ['ok', sprintf(_n('%d key page.', '%d key pages.', $report['key_pages'], 'snapshot-core'), $report['key_pages'])]
                : ['info', __('No key pages marked. Mark the most important pages in their editor panel.', 'snapshot-core')], $base.'&tab=content', 'llms');
        } else {
            $checks[] = $this->check('report', __('Content report', 'snapshot-core'), ['info', __('Not run yet. It runs nightly, or now from the overview.', 'snapshot-core')], $base, 'check_descriptions');
        }

        $alt = $this->missingAlt();
        $checks[] = $this->check('alt', __('Image alt text', 'snapshot-core'), $alt === 0
            ? ['ok', __('Every image in the media library has alt text.', 'snapshot-core')]
            : ['info', sprintf(_n('%d image has no alt text.', '%d images have no alt text.', $alt, 'snapshot-core'), $alt)], admin_url('upload.php?mode=list'), 'check_alt');

        $tagline = (string) get_option('blogdescription');
        $checks[] = $this->check('tagline', __('Site tagline', 'snapshot-core'), $tagline !== '' && ! str_contains(strtolower($tagline), 'just another')
            ? ['ok', __('A tagline is set.', 'snapshot-core')]
            : ['warn', __('The tagline is empty or the WordPress default. It appears in the home page title.', 'snapshot-core')], admin_url('options-general.php'), 'title_template');

        $last = (int) get_option(self::LAST_DAILY, 0);
        if ($last && $last < time() - 2 * DAY_IN_SECONDS) {
            $checks[] = $this->check('cron', __('Nightly tasks', 'snapshot-core'), ['warn', sprintf(__('Last ran %s ago. Check that WordPress cron runs.', 'snapshot-core'), human_time_diff($last))], $base, 'monitor');
        }

        if (! Schema::ready()) {
            $checks[] = $this->check('tables', __('Redirect tables', 'snapshot-core'), ['fail', __('The redirect tables could not be created. Run wp snap seo install.', 'snapshot-core')], $base, 'module_redirects');
        }

        return apply_filters('snap/seo/checks', $checks, $this->engine);
    }

    public function score(array $checks): int
    {
        $scored = array_filter($checks, fn ($check) => $check['status'] !== 'info');

        if ($scored === []) {
            return 100;
        }

        $points = array_sum(array_map(fn ($check) => ['ok' => 1, 'warn' => 0.5, 'fail' => 0][$check['status']], $scored));

        return (int) round($points / count($scored) * 100);
    }

    public function report(): ?array
    {
        $report = get_option(self::REPORT, null);

        return is_array($report) ? $report : null;
    }

    public function buildReport(): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $resolver = $this->engine->resolver();
        $entries = [];
        $titles = [];
        $keyPages = 0;
        $issues = ['no_description' => 0, 'duplicate_title' => 0, 'long_title' => 0, 'short_description' => 0, 'no_image' => 0];

        $ids = get_posts([
            'post_type' => snap_seo_editor_types(),
            'post_status' => 'publish',
            'posts_per_page' => self::REPORT_LIMIT,
            'fields' => 'ids',
            'no_found_rows' => true,
            'has_password' => false,
            'orderby' => 'modified',
        ]);

        foreach (array_chunk($ids, 200) as $chunk) {
            _prime_post_caches($chunk, true, true);

            foreach ($chunk as $id) {
                $post = get_post($id);
                $resolved = $resolver->resolve(Context::forPost($post));

                if (! $resolved['robots']['index']) {
                    continue;
                }

                $flags = [];

                if ($resolved['description'] === '') {
                    $flags[] = 'no_description';
                } elseif (mb_strlen($resolved['description']) < 70) {
                    $flags[] = 'short_description';
                }

                if (mb_strlen($resolved['title']) > 65) {
                    $flags[] = 'long_title';
                }

                if (empty($resolved['social']['image']) || $resolved['social']['image']['id'] === $this->engine->identity()->shareImageId()) {
                    $flags[] = 'no_image';
                }

                if ($this->engine->meta()->post($id, 'key_page')) {
                    $keyPages++;
                }

                $titles[mb_strtolower($resolved['title'])][] = $id;
                $entries[$id] = $flags;
            }

            wp_cache_flush_runtime();
        }

        foreach ($titles as $group) {
            if (count($group) > 1) {
                foreach ($group as $id) {
                    $entries[$id][] = 'duplicate_title';
                }
            }
        }

        foreach ($entries as $flags) {
            foreach ($flags as $flag) {
                $issues[$flag]++;
            }
        }

        $report = [
            'at' => time(),
            'total' => count($entries),
            'issues' => $issues,
            'key_pages' => $keyPages,
            'entries' => array_filter($entries),
        ];

        update_option(self::REPORT, $report, false);

        return $report;
    }

    public function missingAlt(): int
    {
        $cached = get_transient('snapshot_seo_missing_alt');

        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;

        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
             WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%' AND (m.meta_value IS NULL OR m.meta_value = '')"
        );
        set_transient('snapshot_seo_missing_alt', $count, HOUR_IN_SECONDS);

        return $count;
    }

    private function check(string $key, string $label, array $result, string $url, string $topic): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $result[0], 'detail' => $result[1], 'url' => $url, 'topic' => $topic];
    }
}

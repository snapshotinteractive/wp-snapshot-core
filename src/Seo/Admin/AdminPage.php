<?php

namespace Snapshot\Seo\Admin;

use Snapshot\Core;
use Snapshot\Seo\Audit;
use Snapshot\Seo\Context;
use Snapshot\Seo\Engine;
use Snapshot\Seo\IndexNow;
use Snapshot\Seo\Meta;
use Snapshot\Seo\Modules;
use Snapshot\Seo\Redirects;
use Snapshot\Seo\Robots;
use Snapshot\Seo\Schema;
use Throwable;

use function Roots\view;

class AdminPage
{
    public const SLUG = 'snapshot-seo';

    public const CAPABILITY = 'manage_options';

    public const SETUP = 'snapshot_seo_setup';

    public const LAST_IMPORT = 'snapshot_seo_last_import';

    private const STATUS_KEY = 'snapshot_seo_status';

    private const MENU_POSITION = 3.1;

    private const PER_PAGE = 25;

    private const TABS = [
        'overview' => 'Overview',
        'modules' => 'Control panel',
        'appearance' => 'Search appearance',
        'content' => 'Bulk editor',
        'redirects' => 'Redirects',
        'sitemaps' => 'Sitemaps',
        'tools' => 'Tools',
        'settings' => 'Settings',
    ];

    private const EDITOR_SCREENS = ['post', 'edit', 'term', 'edit-tags'];

    public function __construct(private Engine $engine) {}

    public static function tabs(): array
    {
        return self::TABS;
    }

    public static function url(string $tab = 'overview', array $args = []): string
    {
        return add_query_arg(array_filter(['page' => self::SLUG, 'tab' => $tab === 'overview' ? null : $tab] + $args), admin_url('admin.php'));
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_head', [$this, 'assets']);
        add_action('admin_enqueue_scripts', fn ($hook) => $hook === 'toplevel_page_'.self::SLUG ? wp_enqueue_media() : null);
        add_action('admin_footer', [$this, 'config']);
        add_action('admin_notices', [$this, 'notices']);

        foreach (['save_settings', 'save_modules', 'save_appearance', 'save_content', 'add_redirect', 'redirect_action', 'forget_404', 'import', 'import_csv', 'export_csv', 'run', 'setup', 'save_sitemap', 'sitemap_entries', 'sitemap_check', 'sitemap_indexnow'] as $action) {
            add_action("admin_post_snapshot_seo_{$action}", [$this, lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $action))))]);
        }
    }

    public function menu(): void
    {
        add_menu_page(__('Snapshot SEO', 'snapshot-core'), __('SEO', 'snapshot-core'), self::CAPABILITY, self::SLUG, [$this, 'render'], 'dashicons-search', self::MENU_POSITION);
    }

    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Sorry, you are not allowed to view this page.', 'snapshot-core'), 403);
        }

        $tab = sanitize_key((string) ($_GET['tab'] ?? 'overview'));
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'overview';
        $status = $this->status(true);

        echo view('snapshot::seo.admin.page', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'base' => self::url(),
            'notice' => $this->flash(),
            'status' => $status,
            'master' => $this->engine->modules()->master(),
            'data' => $this->{'data'.ucfirst($tab)}(),
        ])->render();
    }

    public function assets(): void
    {
        $screen = get_current_screen();

        if (! $screen) {
            return;
        }

        $own = $screen->id === 'toplevel_page_'.self::SLUG;
        $editor = in_array($screen->base, self::EDITOR_SCREENS, true) && $this->engine->modules()->on('editor');

        if (! $own && ! $editor) {
            return;
        }

        try {
            echo Core::assets(['resources/css/security-admin.css', 'resources/css/seo-admin.css', 'resources/js/seo-admin.js']);
        } catch (Throwable) {
        }
    }

    public function config(): void
    {
        $screen = get_current_screen();

        if (! $screen || ($screen->id !== 'toplevel_page_'.self::SLUG && ! (in_array($screen->base, self::EDITOR_SCREENS, true) && $this->engine->modules()->on('editor')))) {
            return;
        }

        global $post;
        $context = null;

        if ($post instanceof \WP_Post && $screen->base === 'post') {
            $context = ['kind' => 'post', 'id' => $post->ID];
        } elseif ($screen->base === 'term' && ($termId = (int) ($_GET['tag_ID'] ?? 0))) {
            $context = ['kind' => 'term', 'id' => $termId];
        }

        $config = [
            'rest' => [
                'preview' => rest_url('snapshot/v1/seo/preview'),
                'template' => rest_url('snapshot/v1/seo/template'),
                'nonce' => wp_create_nonce('wp_rest'),
            ],
            'context' => $context,
            'separator' => $this->engine->settings()->string('separator') ?: '-',
            'variables' => $this->engine->variables()->catalog(),
            'help' => snap_seo_help(),
            'fieldTopics' => [
                'seo_title' => 'title_template',
                'seo_description' => 'description_template',
                'seo_focus_keyphrase' => 'module_editor',
                'seo_social_title' => 'module_social',
                'seo_social_description' => 'module_social',
                'seo_social_image' => 'og_image',
                'seo_robots' => 'noindex',
                'seo_canonical' => 'canonical',
                'seo_schema_type' => 'schema_type',
                'seo_key_page' => 'llms',
                'seo_sitemap' => 'sitemap_entry',
            ],
            'i18n' => [
                'why' => __('Why this matters', 'snapshot-core'),
                'what' => __('What it does', 'snapshot-core'),
                'whyHeading' => __('Why it matters', 'snapshot-core'),
                'do' => __('What to do', 'snapshot-core'),
                'auto' => __('Filled in automatically', 'snapshot-core'),
                'close' => __('Close', 'snapshot-core'),
                'insert' => __('Insert a variable', 'snapshot-core'),
                'chars' => __('%d characters', 'snapshot-core'),
                'loading' => __('Updating preview', 'snapshot-core'),
                'hidden' => __('Hidden from search results', 'snapshot-core'),
                'template' => __('From the template', 'snapshot-core'),
                'custom' => __('Written for this entry', 'snapshot-core'),
                'example' => __('Example with %s:', 'snapshot-core'),
            ],
        ];

        printf('<script>window.snapSeo = %s;</script>', wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE));
        echo view('snapshot::seo.admin.help-dialog')->render();
    }

    public function notices(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        $screen = get_current_screen();

        if (! $screen || $screen->id !== 'dashboard') {
            return;
        }

        $failures = array_filter($this->status()['checks'], fn ($check) => $check['status'] === 'fail');

        if ($failures) {
            printf(
                '<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
                esc_html__('SEO: action required.', 'snapshot-core'),
                esc_html(implode(' ', array_map(fn ($check) => rtrim($check['detail'], '.').'.', $failures))),
                esc_url(self::url()),
                esc_html__('Open SEO', 'snapshot-core')
            );
        }
    }

    public function status(bool $fresh = false): array
    {
        $cached = get_transient(self::STATUS_KEY);

        if (! $fresh && is_array($cached)) {
            return $cached;
        }

        $checks = $this->engine->audit()->checks();
        $levels = array_column($checks, 'status');
        $level = in_array('fail', $levels, true) ? 'critical' : (in_array('warn', $levels, true) ? 'warning' : 'ok');
        $status = [
            'level' => $level,
            'score' => $this->engine->audit()->score($checks),
            'label' => ! $this->engine->modules()->master() ? __('SEO off', 'snapshot-core') : match ($level) {
                'critical' => __('Action required', 'snapshot-core'),
                'warning' => __('Improvements available', 'snapshot-core'),
                default => __('In good shape', 'snapshot-core'),
            },
            'critical' => count(array_keys($levels, 'fail', true)),
            'warning' => count(array_keys($levels, 'warn', true)),
            'checks' => $checks,
        ];

        set_transient(self::STATUS_KEY, $status, 10 * MINUTE_IN_SECONDS);

        return $status;
    }

    public function forgetStatus(): void
    {
        delete_transient(self::STATUS_KEY);
        delete_transient(Robots::LLMS_CACHE);
    }

    public function saveSettings(): void
    {
        $this->guard('snapshot_seo_save_settings');

        $section = sanitize_key((string) ($_POST['section'] ?? ''));

        if (! array_key_exists($section, snap_seo_settings_tabs())) {
            $this->back('settings', __('Settings not saved: the section was missing from the request.', 'snapshot-core'), [], 'error');
        }

        $changed = $this->engine->settings()->save(wp_unslash((array) ($_POST['snapshot_seo'] ?? [])), fn ($key, $field) => ($field['tab'] ?? '') === $section);

        if ($changed) {
            $this->audit('seo.settings_changed', 'Changed: '.implode(', ', $changed));
        }
        $this->markSetup($section);
        $this->forgetStatus();
        $this->back('settings', $changed ? __('Settings saved.', 'snapshot-core') : __('No changes.', 'snapshot-core'), ['section' => $section]);
    }

    public function saveModules(): void
    {
        $this->guard('snapshot_seo_save_modules');

        $input = wp_unslash((array) ($_POST['snapshot_seo'] ?? []));
        $modules = $this->engine->modules();
        $before = ['master' => $modules->master()] + array_map(fn ($key) => $modules->enabled($key), array_combine(array_keys($modules->registry()), array_keys($modules->registry())));
        $this->engine->settings()->save($input, fn ($key, $field) => ($field['tab'] ?? '') === 'modules');
        $changed = [];

        foreach ($before as $key => $was) {
            $now = $key === 'master' ? $modules->master() : $modules->enabled($key);

            if ($now !== $was) {
                $changed[] = sprintf('%s %s', $key === 'master' ? __('Built-in SEO', 'snapshot-core') : $modules->registry()[$key]['label'], $now ? __('on', 'snapshot-core') : __('off', 'snapshot-core'));
            }
        }

        if ($changed) {
            $this->audit('seo.module_changed', implode(', ', $changed));
        }

        $this->forgetStatus();
        $this->back('modules', $changed ? sprintf(__('Saved: %s.', 'snapshot-core'), implode(', ', $changed)) : __('No changes.', 'snapshot-core'));
    }

    public function saveAppearance(): void
    {
        $this->guard('snapshot_seo_save_appearance');

        $changed = $this->engine->settings()->save(wp_unslash((array) ($_POST['snapshot_seo'] ?? [])), fn ($key, $field) => ($field['tab'] ?? '') === 'appearance');

        if ($changed) {
            $this->audit('seo.settings_changed', 'Changed: '.implode(', ', $changed));
        }
        $this->markSetup('appearance');
        delete_option(Audit::REPORT);
        $this->forgetStatus();
        $this->back('appearance', __('Search appearance saved. The content report will refresh tonight, or run it now from the overview.', 'snapshot-core'));
    }

    public function saveContent(): void
    {
        $this->guard('snapshot_seo_save_content');

        $rows = wp_unslash((array) ($_POST['rows'] ?? []));
        $saved = 0;

        foreach ($rows as $id => $row) {
            $id = (int) $id;

            if (! $id || ! current_user_can('edit_post', $id) || ! is_array($row)) {
                continue;
            }

            $changed = false;

            foreach (['seo_title' => 'sanitize_text_field', 'seo_description' => 'sanitize_textarea_field', 'seo_robots' => 'sanitize_key'] as $name => $sanitize) {
                if (! array_key_exists($name, $row) || (string) $row[$name] === (string) ($row["{$name}__was"] ?? '')) {
                    continue;
                }

                $value = $sanitize((string) $row[$name]);

                if ($name === 'seo_robots' && ! in_array($value, ['', 'noindex', 'index'], true)) {
                    continue;
                }

                if (function_exists('update_field')) {
                    update_field($name, $value, $id);
                } else {
                    update_post_meta($id, $name, wp_slash($value));
                }

                $changed = true;
            }

            $saved += $changed ? 1 : 0;
        }

        $this->forgetStatus();
        $this->back('content', sprintf(_n('%d entry saved.', '%d entries saved.', $saved, 'snapshot-core'), $saved), array_filter([
            'type' => sanitize_key((string) ($_POST['type'] ?? '')),
            'issue' => sanitize_key((string) ($_POST['issue'] ?? '')),
            's' => sanitize_text_field(wp_unslash((string) ($_POST['s'] ?? ''))),
            'paged' => (int) ($_POST['paged'] ?? 0) ?: null,
        ]));
    }

    public function saveSitemap(): void
    {
        $this->guard('snapshot_seo_save_sitemap');

        $scope = sanitize_key((string) ($_POST['scope'] ?? 'basic'));
        $sectionKeys = array_column($this->engine->sitemaps()->sections(), 'setting');
        $basic = fn ($key) => in_array($key, $sectionKeys, true);
        $isSection = fn ($key) => str_ends_with($key, '_sitemap') && (str_starts_with($key, 'pt_') || str_starts_with($key, 'tax_'));
        $changed = $this->engine->settings()->save(
            wp_unslash((array) ($_POST['snapshot_seo'] ?? [])),
            fn ($key, $field) => ($field['tab'] ?? '') === 'sitemap' && ($scope === 'basic' ? $basic($key) : ! $isSection($key))
        );

        if ($changed) {
            $this->audit('seo.settings_changed', 'Sitemap: '.implode(', ', $changed));
        }

        $this->forgetStatus();
        $this->back('sitemaps', $changed ? __('Sitemap settings saved.', 'snapshot-core') : __('No changes.', 'snapshot-core'));
    }

    public function sitemapEntries(): void
    {
        $this->guard('snapshot_seo_sitemap_entries');

        $section = sanitize_text_field(wp_unslash((string) ($_POST['section'] ?? '')));
        [$kind] = explode(':', $section.':', 2);
        $exclude = sanitize_key((string) ($_POST['bulk'] ?? '')) === 'exclude';
        $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
        $key = Meta::KEYS['sitemap'];
        $done = 0;

        foreach ($ids as $id) {
            if ($kind === 'tax') {
                if (! current_user_can('edit_term', $id)) {
                    continue;
                }

                $exclude ? update_term_meta($id, $key, 'exclude') : delete_term_meta($id, $key);
                $done++;
            } else {
                if (! current_user_can('edit_post', $id)) {
                    continue;
                }

                $exclude ? update_post_meta($id, $key, 'exclude') : delete_post_meta($id, $key);
                $done++;
            }
        }

        if ($done) {
            $this->audit('seo.settings_changed', sprintf('Sitemap: %d entries %s', $done, $exclude ? 'left out' : 'put back'));
        }

        $this->forgetStatus();
        $this->back('sitemaps', $exclude
            ? sprintf(_n('%d entry left out of the sitemap.', '%d entries left out of the sitemap.', $done, 'snapshot-core'), $done)
            : sprintf(_n('%d entry put back.', '%d entries put back.', $done, 'snapshot-core'), $done), array_filter([
                'section' => $section,
                'status' => sanitize_key((string) ($_POST['status'] ?? '')),
                's' => sanitize_text_field(wp_unslash((string) ($_POST['s'] ?? ''))),
                'paged' => (int) ($_POST['paged'] ?? 0) ?: null,
            ]));
    }

    public function sitemapCheck(): void
    {
        $this->guard('snapshot_seo_sitemap_check');

        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $result = $this->engine->sitemaps()->check();
        $this->forgetStatus();
        $this->back('sitemaps', sprintf(__('Sitemap checked: %1$d addresses, %2$d issues.', 'snapshot-core'), $result['total'], count($result['issues'])));
    }

    public function sitemapIndexnow(): void
    {
        $this->guard('snapshot_seo_sitemap_indexnow');

        $indexNow = $this->engine->indexNow();

        if (! $this->engine->modules()->on('indexnow') || ! $indexNow->allowed()) {
            $this->back('sitemaps', __('IndexNow is off, or this is not a public production site.', 'snapshot-core'), [], 'error');
        }

        $urls = array_column($this->engine->sitemaps()->urls(10000), 'loc');
        $entry = $urls ? $indexNow->submit($urls) : ['code' => 0, 'error' => __('The sitemap is empty.', 'snapshot-core')];
        $ok = in_array((int) $entry['code'], [200, 202], true);

        $this->back('sitemaps', $ok
            ? sprintf(__('%d addresses sent to IndexNow.', 'snapshot-core'), count($urls))
            : sprintf(__('IndexNow did not accept the list: %s', 'snapshot-core'), $entry['error'] ?: (string) $entry['code']), [], $ok ? 'success' : 'error');
    }

    public function addRedirect(): void
    {
        $this->guard('snapshot_seo_add_redirect');

        $result = $this->engine->redirects()->add(
            sanitize_text_field(wp_unslash((string) ($_POST['source'] ?? ''))),
            sanitize_text_field(wp_unslash((string) ($_POST['target'] ?? ''))),
            (int) ($_POST['code'] ?? 301),
            sanitize_key((string) ($_POST['match_type'] ?? 'exact')),
            'manual',
            0,
            sanitize_text_field(wp_unslash((string) ($_POST['note'] ?? '')))
        );

        if ($result['ok']) {
            $this->audit('seo.redirect_changed', sprintf('Redirect %s', sanitize_text_field(wp_unslash((string) ($_POST['source'] ?? '')))));
        }

        if ($result['ok'] && ! empty($_POST['from_404'])) {
            $this->engine->redirects()->forgetMissing([(int) $_POST['from_404']]);
        }

        $this->forgetStatus();
        $this->back('redirects', $result['message'], ['view' => ! empty($_POST['from_404']) ? '404' : null], $result['ok'] ? 'success' : 'error');
    }

    public function redirectAction(): void
    {
        $this->guard('snapshot_seo_redirect_action');

        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $action = sanitize_key((string) ($_POST['bulk'] ?? ''));
        $redirects = $this->engine->redirects();

        $message = match ($action) {
            'delete' => (function () use ($ids, $redirects) {
                $removed = $redirects->remove($ids);
                $this->audit('seo.redirect_changed', sprintf('%d redirects deleted', $removed));

                return sprintf(__('%d redirects deleted.', 'snapshot-core'), $removed);
            })(),
            'enable', 'disable' => (function () use ($ids, $action, $redirects) {
                foreach ($ids as $id) {
                    $redirects->update($id, ['enabled' => $action === 'enable' ? 1 : 0]);
                }

                return sprintf(__('%d redirects updated.', 'snapshot-core'), count($ids));
            })(),
            default => __('Choose an action.', 'snapshot-core'),
        };

        $this->back('redirects', $message);
    }

    public function forget404(): void
    {
        $this->guard('snapshot_seo_forget_404');

        $this->engine->redirects()->forgetMissing(array_map('intval', (array) ($_POST['ids'] ?? [])));
        $this->forgetStatus();
        $this->back('redirects', __('Removed from the 404 log.', 'snapshot-core'), ['view' => '404']);
    }

    public function import(): void
    {
        $this->guard('snapshot_seo_import');

        $importer = $this->engine->importer();
        $this->engine->schema()->ensure();
        $sources = array_map('sanitize_key', (array) ($_POST['sources'] ?? []));
        $overwrite = ! empty($_POST['overwrite']);
        $results = [];

        foreach ($sources as $source) {
            $results[] = match ($source) {
                'meta' => vsprintf(__('titles and settings copied into %1$d entries and %2$d terms', 'snapshot-core'), array_values($importer->meta($overwrite))),
                'redirection' => vsprintf(__('Redirection: %1$d redirects imported, %2$d skipped', 'snapshot-core'), array_values($importer->redirection())),
                'rankmath' => vsprintf(__('Rank Math: %1$d redirects imported, %2$d skipped', 'snapshot-core'), array_values($importer->rankMathRedirects())),
                'yoast' => vsprintf(__('Yoast Premium: %1$d redirects imported, %2$d skipped', 'snapshot-core'), array_values($importer->yoastRedirects())),
                default => null,
            };
        }

        $results = array_filter($results);

        if ($results === []) {
            $this->back('tools', __('Choose at least one kind of data to import.', 'snapshot-core'), [], 'error');
        }

        $message = ucfirst(implode('; ', $results)).'.';
        update_option(self::LAST_IMPORT, ['at' => time(), 'message' => $message], false);
        $this->audit('seo.settings_changed', 'Imported: '.$message);
        delete_option(Audit::REPORT);
        $this->forgetStatus();
        $this->back('tools', $message);
    }

    public function importCsv(): void
    {
        $this->guard('snapshot_seo_import_csv');

        $file = $_FILES['csv'] ?? null;

        if (! is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 2 * MB_IN_BYTES || ! is_uploaded_file((string) $file['tmp_name'])) {
            $this->back('tools', __('Upload a CSV file smaller than 2 MB.', 'snapshot-core'), [], 'error');
        }

        $result = $this->engine->importer()->csv((string) file_get_contents((string) $file['tmp_name']));
        $message = sprintf(__('%d redirects imported.', 'snapshot-core'), $result['added']).($result['errors'] ? ' '.implode(' ', $result['errors']) : '');

        $this->back('tools', $message, [], $result['errors'] ? 'error' : 'success');
    }

    public function exportCsv(): void
    {
        $this->guard('snapshot_seo_export_csv');

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=redirects-'.gmdate('Y-m-d').'.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['source', 'target', 'code', 'match_type', 'note'], ',', '"', '');

        foreach ($this->engine->redirects()->export() as $row) {
            fputcsv($out, array_map(fn ($value) => preg_match('/^[=+\-@]/', (string) $value) ? "'".$value : $value, array_values($row)), ',', '"', '');
        }

        fclose($out);
        exit;
    }

    public function run(): void
    {
        $this->guard('snapshot_seo_run');

        $task = sanitize_key((string) ($_REQUEST['task'] ?? ''));
        $engine = $this->engine;
        $tab = sanitize_key((string) ($_REQUEST['return'] ?? 'overview'));

        $message = match ($task) {
            'report' => (function () use ($engine) {
                $report = $engine->audit()->buildReport();

                return sprintf(__('Content report complete: %1$d indexable entries, %2$d without a description.', 'snapshot-core'), $report['total'], $report['issues']['no_description']);
            })(),
            'install' => $engine->schema()->install() ? __('Redirect tables are ready.', 'snapshot-core') : __('The tables could not be created. Check the database user can CREATE TABLE.', 'snapshot-core'),
            'indexnow' => (function () use ($engine) {
                $urls = array_values(array_filter(array_map('esc_url_raw', preg_split('/\s+/', wp_unslash((string) ($_POST['urls'] ?? ''))))));

                if (! $urls) {
                    return __('Enter at least one address.', 'snapshot-core');
                }

                $entry = $engine->indexNow()->submit($urls);

                return $entry['code'] >= 200 && $entry['code'] < 300
                    ? sprintf(__('%d addresses submitted to IndexNow.', 'snapshot-core'), count($urls))
                    : sprintf(__('IndexNow answered %1$d. %2$s', 'snapshot-core'), $entry['code'], $entry['error']);
            })(),
            'caches' => (function () {
                delete_transient(Robots::LLMS_CACHE);
                delete_option('snapshot_seo_redirect_patterns');

                return __('llms.txt and redirect caches cleared.', 'snapshot-core');
            })(),
            default => __('Unknown task.', 'snapshot-core'),
        };

        $this->forgetStatus();
        $this->back(array_key_exists($tab, self::TABS) ? $tab : 'overview', $message);
    }

    public function setup(): void
    {
        $this->guard('snapshot_seo_setup');

        $step = sanitize_key((string) ($_POST['step'] ?? ''));
        $this->markSetup($step, empty($_POST['undo']));
        $this->back('overview', __('Setup progress saved.', 'snapshot-core'));
    }

    public function setupSteps(): array
    {
        $done = (array) get_option(self::SETUP, []);
        $identity = $this->engine->identity();

        $steps = [
            'identity' => [
                'label' => __('Confirm the organization', 'snapshot-core'),
                'detail' => sprintf(__('Detected: %1$s. Logo, description, contact details and %2$d social profiles come from Site Options.', 'snapshot-core'), $identity->name(), count($identity->socialProfiles())),
                'done' => ! empty($done['identity']),
                'url' => self::url('settings', ['section' => 'identity']),
                'action' => __('Review identity', 'snapshot-core'),
                'topic' => 'module_schema',
            ],
            'appearance' => [
                'label' => __('Review search appearance', 'snapshot-core'),
                'detail' => __('Every content type has title and description templates filled from its own fields. Check the examples once.', 'snapshot-core'),
                'done' => ! empty($done['appearance']),
                'url' => self::url('appearance'),
                'action' => __('Review templates', 'snapshot-core'),
                'topic' => 'title_template',
            ],
            'social' => [
                'label' => __('Set a default share image', 'snapshot-core'),
                'detail' => $identity->shareImageId() ? __('A default share image is set.', 'snapshot-core') : __('Used when an entry has no featured image.', 'snapshot-core'),
                'done' => (bool) $identity->shareImageId(),
                'url' => self::url('settings', ['section' => 'social']),
                'action' => __('Choose image', 'snapshot-core'),
                'topic' => 'og_image',
            ],
            'verify' => [
                'label' => __('Verify the site and submit the sitemap', 'snapshot-core'),
                'detail' => sprintf(__('In Google Search Console and Bing Webmaster Tools, submit %s.', 'snapshot-core'), home_url('/wp-sitemap.xml')),
                'done' => ! empty($done['verify']),
                'url' => self::url('settings', ['section' => 'verification']),
                'action' => __('Verification tags', 'snapshot-core'),
                'topic' => 'verification',
                'manual' => true,
            ],
            'monitor' => [
                'label' => __('Check the 404 log after launch', 'snapshot-core'),
                'detail' => __('Weekly for the first month: redirect the busiest broken addresses.', 'snapshot-core'),
                'done' => ! empty($done['monitor']),
                'url' => self::url('redirects', ['view' => '404']),
                'action' => __('Open 404 log', 'snapshot-core'),
                'topic' => 'monitor',
                'manual' => true,
            ],
        ];

        return $steps;
    }

    private function markSetup(string $step, bool $done = true): void
    {
        $setup = (array) get_option(self::SETUP, []);
        $setup[$step] = $done ? time() : null;
        update_option(self::SETUP, array_filter($setup), false);
    }

    private function dataOverview(): array
    {
        $redirects = Schema::ready() ? $this->engine->redirects()->counts() : ['redirects' => 0, 'missing' => 0];
        $report = $this->engine->audit()->report();

        return [
            'steps' => $this->setupSteps(),
            'report' => $report,
            'redirects' => $redirects,
            'links' => [
                'sitemap' => home_url('/wp-sitemap.xml'),
                'robots' => home_url('/robots.txt'),
                'llms' => home_url('/llms.txt'),
                'rich' => 'https://search.google.com/test/rich-results?url='.rawurlencode(home_url('/')),
                'schema' => 'https://validator.schema.org/#url='.rawurlencode(home_url('/')),
            ],
            'nonces' => [
                'run' => wp_nonce_field('snapshot_seo_run', '_wpnonce', true, false),
                'setup' => wp_nonce_field('snapshot_seo_setup', '_wpnonce', true, false),
            ],
        ];
    }

    private function dataModules(): array
    {
        $settings = $this->engine->settings();
        $modules = $this->engine->modules();
        $items = [];

        foreach ($modules->registry() as $key => $module) {
            $items[$key] = $module + [
                'value' => $settings->bool($module['setting']),
                'on' => $modules->on($key),
                'paused' => $modules->enabled($key) ? $modules->pausedBy($key) : [],
                'locked' => $settings->locked($module['setting']),
                'constant' => $settings->constant($module['setting']),
            ];
        }

        return [
            'master' => ['value' => $modules->master(), 'locked' => $settings->locked(Modules::MASTER), 'constant' => $settings->constant(Modules::MASTER)],
            'items' => $items,
            'overlaps' => $modules->overlaps(),
            'defer' => $settings->bool('defer_to_plugins'),
            'nonce' => wp_nonce_field('snapshot_seo_save_modules', '_wpnonce', true, false),
        ];
    }

    private function dataAppearance(): array
    {
        $settings = $this->engine->settings();
        $field = function (string $key) use ($settings) {
            $schema = $settings->schema()[$key] ?? null;

            return $schema ? $schema + ['key' => $key, 'value' => $settings->get($key), 'locked' => $settings->locked($key), 'constant' => $settings->constant($key)] : null;
        };
        $editor = $this->engine->editor();
        $sample = function (string $key, string $scope) use ($settings, $editor) {
            [$kind, $object] = array_pad(explode(':', $scope, 2), 2, '');
            $context = $editor->sampleContext($kind, $object);

            return $context ? [
                'name' => $context->post ? snap_plain_title($context->post) : ($context->term?->name ?? ''),
                'output' => $this->engine->variables()->replace((string) $settings->get($key), $context),
            ] : null;
        };

        $types = [];
        $groups = snap_content_type_field_groups();

        foreach (snap_seo_post_types() as $type => $config) {
            $types[$type] = $config + [
                'variables' => array_values(array_filter(array_map(
                    fn ($field) => in_array($field['type'] ?? '', ['tab', 'message', 'repeater', 'image', 'gallery', 'flexible_content', 'group', 'wysiwyg', 'file'], true) || ($field['name'] ?? '') === '' ? null : ['name' => $field['name'], 'label' => $field['label'] ?? $field['name']],
                    $groups[$type]['fields'] ?? []
                ))),
                'count' => (int) (wp_count_posts($type)->publish ?? 0),
                'fields' => array_filter(array_map($field, ["pt_{$type}_title", "pt_{$type}_description", "pt_{$type}_schema", "pt_{$type}_index", "pt_{$type}_llms", "pta_{$type}_title", "pta_{$type}_description"])),
                'samples' => [
                    "pt_{$type}_title" => $sample("pt_{$type}_title", "pt:{$type}"),
                    "pt_{$type}_description" => $sample("pt_{$type}_description", "pt:{$type}"),
                ],
            ];
        }

        $taxonomies = [];

        foreach (snap_seo_taxonomies() as $taxonomy => $config) {
            if (! taxonomy_exists($taxonomy)) {
                continue;
            }

            $taxonomies[$taxonomy] = $config + [
                'count' => (int) wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]),
                'fields' => array_filter(array_map($field, ["tax_{$taxonomy}_title", "tax_{$taxonomy}_description", "tax_{$taxonomy}_index"])),
            ];
        }

        return [
            'special' => [
                'home' => ['label' => __('Home page', 'snapshot-core'), 'fields' => array_filter([$field('home_title'), $field('home_description')]), 'scope' => 'home', 'samples' => ['home_title' => $sample('home_title', 'home'), 'home_description' => $sample('home_description', 'home')]],
                'search' => ['label' => __('Search results', 'snapshot-core'), 'fields' => array_filter([$field('search_title')]), 'scope' => 'search', 'samples' => ['search_title' => $sample('search_title', 'search')]],
                '404' => ['label' => __('Page not found', 'snapshot-core'), 'fields' => array_filter([$field('notfound_title')]), 'scope' => '404', 'samples' => ['notfound_title' => $sample('notfound_title', '404')]],
            ],
            'types' => $types,
            'taxonomies' => $taxonomies,
            'variables' => $this->engine->variables()->catalog(),
            'nonce' => wp_nonce_field('snapshot_seo_save_appearance', '_wpnonce', true, false),
        ];
    }

    private function dataContent(): array
    {
        $type = sanitize_key((string) ($_GET['type'] ?? ''));
        $issue = sanitize_key((string) ($_GET['issue'] ?? ''));
        $search = sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? '')));
        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $types = snap_seo_post_types();
        $report = $this->engine->audit()->report();
        $args = [
            'post_type' => array_key_exists($type, $types) ? $type : array_keys($types),
            'post_status' => 'publish',
            'posts_per_page' => self::PER_PAGE,
            'paged' => $paged,
            's' => $search,
            'orderby' => 'modified',
            'order' => 'DESC',
        ];

        if ($issue !== '' && $report) {
            $ids = array_keys(array_filter($report['entries'], fn ($flags) => in_array($issue, $flags, true)));
            $args['post__in'] = $ids ?: [0];
        }

        $query = new \WP_Query($args);
        $resolver = $this->engine->resolver();
        $meta = $this->engine->meta();
        $rows = [];

        foreach ($query->posts as $post) {
            $context = Context::forPost($post);
            $resolved = $resolver->resolve($context);
            $rows[] = [
                'id' => $post->ID,
                'title' => snap_plain_title($post),
                'type' => $types[$post->post_type]['singular'] ?? $post->post_type,
                'edit' => get_edit_post_link($post->ID, 'raw'),
                'view' => get_permalink($post),
                'seo_title' => (string) $meta->post($post->ID, 'title'),
                'seo_description' => (string) $meta->post($post->ID, 'description'),
                'seo_robots' => (string) $meta->post($post->ID, 'robots'),
                'resolved' => $resolved,
                'title_template' => $resolver->template($context, 'title'),
                'flags' => $report['entries'][$post->ID] ?? [],
            ];
        }

        return [
            'rows' => $rows,
            'types' => $types,
            'type' => $type,
            'issue' => $issue,
            'search' => $search,
            'paged' => $paged,
            'pages' => (int) $query->max_num_pages,
            'total' => (int) $query->found_posts,
            'report' => $report,
            'issues' => [
                'no_description' => __('Missing description', 'snapshot-core'),
                'short_description' => __('Short description', 'snapshot-core'),
                'duplicate_title' => __('Duplicate title', 'snapshot-core'),
                'long_title' => __('Long title', 'snapshot-core'),
                'no_image' => __('No share image of its own', 'snapshot-core'),
            ],
            'nonce' => wp_nonce_field('snapshot_seo_save_content', '_wpnonce', true, false),
        ];
    }

    private function dataRedirects(): array
    {
        $view = ($_GET['view'] ?? '') === '404' ? '404' : 'redirects';
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $search = sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? '')));
        $ready = Schema::ready() || $this->engine->schema()->ensure();
        $redirects = $this->engine->redirects();
        $result = ['rows' => [], 'total' => 0, 'pages' => 0];

        if ($ready) {
            $result = $view === '404'
                ? $redirects->missing(['search' => $search, 'bots' => ! empty($_GET['bots'])], $page, self::PER_PAGE)
                : $redirects->query(['search' => $search, 'origin' => sanitize_key((string) ($_GET['origin'] ?? ''))], $page, self::PER_PAGE);

            if ($view === '404') {
                foreach ($result['rows'] as &$row) {
                    $row['suggest'] = $redirects->suggest($row['path']);
                }
                unset($row);
            }
        }

        return $result + [
            'view' => $view,
            'ready' => $ready,
            'page' => $page,
            'search' => $search,
            'bots' => ! empty($_GET['bots']),
            'counts' => $ready ? $redirects->counts() : ['redirects' => 0, 'missing' => 0],
            'codes' => Redirects::CODES,
            'matches' => Redirects::MATCH,
            'enabled' => $this->engine->modules()->on('redirects'),
            'monitoring' => $this->engine->modules()->on('monitor'),
            'nonces' => [
                'add' => wp_nonce_field('snapshot_seo_add_redirect', '_wpnonce', true, false),
                'bulk' => wp_nonce_field('snapshot_seo_redirect_action', '_wpnonce', true, false),
                'forget' => wp_nonce_field('snapshot_seo_forget_404', '_wpnonce', true, false),
            ],
        ];
    }

    private function dataSitemaps(): array
    {
        $sitemaps = $this->engine->sitemaps();
        $settings = $this->engine->settings();
        $sections = $sitemaps->sections();
        $section = sanitize_text_field(wp_unslash((string) ($_GET['section'] ?? '')));
        $section = array_key_exists($section, $sections) ? $section : (string) array_key_first($sections);
        $status = sanitize_key((string) ($_GET['status'] ?? ''));
        $search = sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? '')));
        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $rows = [];
        $total = 0;
        $pages = 0;

        if ($section !== '') {
            [$kind, $name] = explode(':', $section, 2);
            $resolver = $this->engine->resolver();
            $onlyExcluded = $status === 'excluded' ? [['key' => Meta::KEYS['sitemap'], 'value' => 'exclude']] : [];

            if ($kind === 'pt') {
                $query = new \WP_Query(array_filter([
                    'post_type' => $name,
                    'post_status' => 'publish',
                    'posts_per_page' => self::PER_PAGE,
                    'paged' => $paged,
                    's' => $search,
                    'orderby' => 'title',
                    'order' => 'ASC',
                    'meta_query' => $onlyExcluded,
                ]));
                $total = (int) $query->found_posts;
                $pages = (int) $query->max_num_pages;

                foreach ($query->posts as $post) {
                    $rows[] = [
                        'id' => $post->ID,
                        'title' => snap_plain_title($post),
                        'url' => (string) get_permalink($post),
                        'edit' => (string) get_edit_post_link($post->ID, 'raw'),
                        'lastmod' => get_post_modified_time(get_option('date_format'), false, $post),
                        'status' => $sitemaps->postStatus($post, $resolver->resolve(Context::forPost($post))),
                    ];
                }
            } else {
                $args = array_filter(['taxonomy' => $name, 'hide_empty' => false, 'search' => $search, 'meta_query' => $onlyExcluded]);
                $total = (int) wp_count_terms($args);
                $pages = (int) ceil($total / self::PER_PAGE);
                $terms = get_terms($args + ['number' => self::PER_PAGE, 'offset' => ($paged - 1) * self::PER_PAGE, 'orderby' => 'name']);

                foreach (is_array($terms) ? $terms : [] as $term) {
                    $link = get_term_link($term);
                    $rows[] = [
                        'id' => $term->term_id,
                        'title' => wp_specialchars_decode($term->name, ENT_QUOTES),
                        'url' => is_string($link) ? $link : '',
                        'edit' => (string) get_edit_term_link($term->term_id, $term->taxonomy),
                        'lastmod' => sprintf(_n('%d entry', '%d entries', (int) $term->count, 'snapshot-core'), (int) $term->count),
                        'status' => $sitemaps->termStatus($term, $resolver->resolve(Context::forTerm($term))),
                    ];
                }
            }
        }

        $advanced = [];

        foreach (['sitemap_lastmod', 'sitemap_max_urls', 'sitemap_legacy_urls', 'sitemap_exclude_rules', 'sitemap_extra_urls'] as $key) {
            $advanced[$key] = $settings->schema()[$key] + [
                'key' => $key,
                'value' => $settings->get($key),
                'locked' => $settings->locked($key),
                'constant' => $settings->constant($key),
            ];
        }

        $home = home_url('/');

        return [
            'module' => $this->engine->modules()->on('sitemaps'),
            'public' => (bool) get_option('blog_public'),
            'index' => home_url('/wp-sitemap.xml'),
            'sections' => array_map(fn ($item) => $item + ['locked' => $settings->locked($item['setting'])], $sections),
            'section' => $section,
            'status' => $status,
            'search' => $search,
            'paged' => $paged,
            'pages' => $pages,
            'total' => $total,
            'rows' => $rows,
            'advanced' => $advanced,
            'extra' => $sitemaps->extraUrls(),
            'extraXml' => get_sitemap_url('extra'),
            'rules' => $sitemaps->rules(),
            'check' => $sitemaps->lastCheck(),
            'indexnow' => $this->engine->modules()->on('indexnow') && $this->engine->indexNow()->allowed(),
            'consoles' => [
                'google' => 'https://search.google.com/search-console/sitemaps?resource_id='.rawurlencode($home),
                'bing' => 'https://www.bing.com/webmasters/sitemaps?siteUrl='.rawurlencode($home),
            ],
            'nonces' => [
                'save' => wp_nonce_field('snapshot_seo_save_sitemap', '_wpnonce', true, false),
                'entries' => wp_nonce_field('snapshot_seo_sitemap_entries', '_wpnonce', true, false),
                'check' => wp_nonce_field('snapshot_seo_sitemap_check', '_wpnonce', true, false),
                'indexnow' => wp_nonce_field('snapshot_seo_sitemap_indexnow', '_wpnonce', true, false),
            ],
        ];
    }

    private function dataTools(): array
    {
        $robotsText = (string) apply_filters('robots_txt', "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n", (bool) get_option('blog_public'));

        $sources = $this->engine->importer()->sources();
        $entries = $sources['yoast']['entries'] + $sources['rankmath']['entries'];
        $terms = $sources['yoast']['terms'];

        return [
            'imports' => [
                'meta' => [
                    'label' => __('Titles, descriptions and settings', 'snapshot-core'),
                    'detail' => __('From Yoast SEO and Rank Math: search titles and descriptions, noindex, canonicals, share titles and images, breadcrumb titles, focus keyphrases and cornerstone pages.', 'snapshot-core'),
                    'count' => $entries + $terms,
                    'found' => sprintf(__('%1$d entries, %2$d terms', 'snapshot-core'), $entries, $terms),
                ],
                'redirection' => [
                    'label' => __('Redirects from the Redirection plugin', 'snapshot-core'),
                    'detail' => __('Enabled address redirects and 410 rules. Plain and regular expression rules both come across.', 'snapshot-core'),
                    'count' => $sources['redirection']['redirects'],
                    'found' => sprintf(_n('%d redirect', '%d redirects', $sources['redirection']['redirects'], 'snapshot-core'), $sources['redirection']['redirects']),
                ],
                'rankmath' => [
                    'label' => __('Redirects from Rank Math', 'snapshot-core'),
                    'detail' => __('Active exact, starts with and regular expression rules.', 'snapshot-core'),
                    'count' => $sources['rankmath']['redirects'],
                    'found' => sprintf(_n('%d redirect', '%d redirects', $sources['rankmath']['redirects'], 'snapshot-core'), $sources['rankmath']['redirects']),
                ],
                'yoast' => [
                    'label' => __('Redirects from Yoast SEO Premium', 'snapshot-core'),
                    'detail' => __('Plain and regular expression redirects.', 'snapshot-core'),
                    'count' => $sources['yoast']['redirects'],
                    'found' => sprintf(_n('%d redirect', '%d redirects', $sources['yoast']['redirects'], 'snapshot-core'), $sources['yoast']['redirects']),
                ],
            ],
            'lastImport' => get_option(self::LAST_IMPORT, null),
            'robots' => $robotsText,
            'llms' => $this->engine->modules()->on('llms') ? $this->engine->robots()->llms() : '',
            'indexnow' => [
                'on' => $this->engine->modules()->on('indexnow'),
                'allowed' => $this->engine->indexNow()->allowed(),
                'key' => $this->engine->modules()->on('indexnow') ? $this->engine->indexNow()->keyUrl() : '',
                'log' => (array) get_option(IndexNow::LOG, []),
            ],
            'files' => array_values(array_filter(['robots.txt', 'llms.txt', 'sitemap.xml'], fn ($file) => file_exists(ABSPATH.$file))),
            'blocked' => $this->engine->robots()->blockedCrawlers(),
            'nonces' => [
                'import' => wp_nonce_field('snapshot_seo_import', '_wpnonce', true, false),
                'csv' => wp_nonce_field('snapshot_seo_import_csv', '_wpnonce', true, false),
                'export' => wp_nonce_url(admin_url('admin-post.php?action=snapshot_seo_export_csv'), 'snapshot_seo_export_csv'),
                'run' => wp_nonce_field('snapshot_seo_run', '_wpnonce', true, false),
            ],
        ];
    }

    private function dataSettings(): array
    {
        $settings = $this->engine->settings();
        $sections = snap_seo_settings_tabs();
        $section = sanitize_key((string) ($_GET['section'] ?? array_key_first($sections)));
        $section = array_key_exists($section, $sections) ? $section : array_key_first($sections);
        $sources = $this->engine->identity()->sources();
        $fields = [];

        foreach ($settings->schema() as $key => $field) {
            if (($field['tab'] ?? '') !== $section) {
                continue;
            }

            $fields[$key] = $field + [
                'key' => $key,
                'value' => $settings->get($key),
                'locked' => $settings->locked($key),
                'constant' => $settings->constant($key),
                'inherited' => isset($field['inherit']) ? (string) ($sources[$field['inherit']] ?? '') : '',
            ];
        }

        return [
            'fields' => $fields,
            'sections' => $sections,
            'section' => $section,
            'sectionModules' => array_filter(
                array_map(fn ($module, $key) => $module + ['key' => $key, 'on' => $this->engine->modules()->on($key)], snap_seo_modules(), array_keys(snap_seo_modules())),
                fn ($module) => $module['section'] === $section
            ),
            'nonce' => wp_nonce_field('snapshot_seo_save_settings', '_wpnonce', true, false),
        ];
    }

    private function audit(string $event, string $message): void
    {
        if (function_exists('snap_security_log')) {
            snap_security_log($event, $message);
        }
    }

    private function guard(string $action): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'snapshot-core'), 403);
        }

        check_admin_referer($action);
    }

    private function back(string $tab, string $message, array $args = [], string $type = 'success'): never
    {
        set_transient('snapshot_seo_flash_'.get_current_user_id(), ['message' => $message, 'type' => $type], MINUTE_IN_SECONDS);
        wp_safe_redirect(self::url($tab, $args));
        exit;
    }

    private function flash(): ?array
    {
        $key = 'snapshot_seo_flash_'.get_current_user_id();
        $flash = get_transient($key);
        delete_transient($key);

        return is_array($flash) ? $flash : null;
    }
}

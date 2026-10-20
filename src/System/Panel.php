<?php

namespace Snapshot\System;

use Snapshot\Core;
use Snapshot\Mcp\Mcp;
use Snapshot\Security\Schema;
use Throwable;

use function Roots\view;

class Panel
{
    public const SLUG = 'snapshot-system';

    public const CAPABILITY = 'manage_options';

    private const PER_PAGE = 50;

    private static ?self $instance = null;

    private ?Settings $settings = null;

    private ?Diagnostics $diagnostics = null;

    private ?Tools $tools = null;

    private ?Experience $experience = null;

    private bool $booted = false;

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public static function url(string $tab = 'status', array $args = []): string
    {
        return add_query_arg(array_filter(['page' => self::SLUG, 'tab' => $tab === 'status' ? null : $tab] + $args), admin_url('admin.php'));
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;
        $this->experience()->boot();

        if (defined('WP_CLI') && WP_CLI) {
            Cli::register($this);
        }

        if (! is_admin()) {
            return;
        }

        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_head', [$this, 'assets']);
        add_action('admin_enqueue_scripts', fn ($hook) => $hook === 'toplevel_page_'.self::SLUG ? wp_enqueue_media() : null);

        foreach (['tool', 'save', 'export_activity', 'report', 'mcp_token', 'mcp_revoke', 'mcp_disconnect'] as $action) {
            add_action("admin_post_snapshot_system_{$action}", [$this, lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $action))))]);
        }
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings;
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics ??= new Diagnostics;
    }

    public function tools(): Tools
    {
        return $this->tools ??= new Tools($this->diagnostics());
    }

    public function experience(): Experience
    {
        return $this->experience ??= new Experience($this->settings());
    }

    public function menu(): void
    {
        add_menu_page(__('Snapshot', 'snapshot-core'), __('Snapshot', 'snapshot-core'), self::CAPABILITY, self::SLUG, [$this, 'render'], $this->icon(), 3.05);

        foreach (snap_system_tabs() as $tab => $label) {
            add_submenu_page(self::SLUG, __($label, 'snapshot-core'), __($label, 'snapshot-core'), self::CAPABILITY, $tab === 'status' ? self::SLUG : self::SLUG.'&tab='.$tab, $tab === 'status' ? [$this, 'render'] : '');
        }
    }

    public function assets(): void
    {
        $screen = get_current_screen();

        if (! $screen || $screen->id !== 'toplevel_page_'.self::SLUG) {
            return;
        }

        try {
            echo Core::assets(['resources/css/security-admin.css', 'resources/css/seo-admin.css', 'resources/js/seo-admin.js']);
        } catch (Throwable) {
        }
    }

    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Sorry, you are not allowed to view this page.', 'snapshot-core'), 403);
        }

        $tabs = snap_system_tabs();
        $tab = sanitize_key((string) ($_GET['tab'] ?? 'status'));
        $tab = array_key_exists($tab, $tabs) ? $tab : 'status';
        $summary = $this->diagnostics()->summary();

        echo view('snapshot::system.page', [
            'tab' => $tab,
            'tabs' => $tabs,
            'base' => self::url(),
            'notice' => $this->flash(),
            'summary' => $summary,
            'data' => $this->{'data'.ucfirst($tab)}(),
        ])->render();
    }

    public function tool(): void
    {
        $this->guard('snapshot_system_tool');

        $tool = sanitize_key((string) ($_POST['tool'] ?? ''));
        $registry = $this->tools()->registry();

        if (! isset($registry[$tool]) || ($registry[$tool]['available'] ?? true) === false) {
            $this->back('tools', __('That tool is not available here.', 'snapshot-core'), [], 'error');
        }

        $result = $this->tools()->run($tool);
        $this->log('system.tool_run', sprintf('%s: %s', $registry[$tool]['label'], $result['message']), ['tool' => $tool, 'ok' => $result['ok']]);
        $this->back('tools', $result['message'], [], $result['ok'] ? 'success' : 'error');
    }

    public function save(): void
    {
        $this->guard('snapshot_system_save');

        $section = sanitize_key((string) ($_POST['section'] ?? ''));

        if (! array_key_exists($section, snap_system_sections())) {
            $this->back('experience', __('Settings not saved: the section was missing from the request.', 'snapshot-core'), [], 'error');
        }

        $changed = $this->settings()->save(wp_unslash((array) ($_POST['snapshot_system'] ?? [])), $section);

        if ($changed) {
            $this->log('system.settings_changed', 'Changed: '.implode(', ', $changed), ['section' => $section]);
        }

        $this->back('experience', $changed ? __('Settings saved.', 'snapshot-core') : __('No changes.', 'snapshot-core'), ['section' => $section]);
    }

    public function mcpToken(): void
    {
        $this->guard('snapshot_system_mcp_token');

        if (! Mcp::enabled() || ! current_user_can(Mcp::capability())) {
            $this->back('mcp', __('MCP is switched off in wp-config.php, so no token was created.', 'snapshot-core'), [], 'error');
        }

        $created = snap_mcp()->tokens()->create(get_current_user_id(), (string) wp_unslash($_POST['name'] ?? ''), (int) ($_POST['days'] ?? 0));

        if (isset($created['error'])) {
            $this->back('mcp', $created['error'], [], 'error');
        }

        set_transient('snapshot_mcp_new_token_'.get_current_user_id(), $created['token'], 5 * MINUTE_IN_SECONDS);
        $this->back('mcp', __('Token created. Copy it now: it is shown once.', 'snapshot-core'));
    }

    public function mcpRevoke(): void
    {
        $this->guard('snapshot_system_mcp_revoke');

        $id = sanitize_key((string) ($_POST['token'] ?? ''));
        $revoked = snap_mcp()->tokens()->revoke($id, null, 'revoked by '.wp_get_current_user()->user_login);

        $this->back('mcp', $revoked ? __('Token revoked. Clients using it are disconnected.', 'snapshot-core') : __('That token was already gone.', 'snapshot-core'), [], $revoked ? 'success' : 'error');
    }

    public function mcpDisconnect(): void
    {
        $this->guard('snapshot_system_mcp_disconnect');

        $id = sanitize_key((string) ($_POST['grant'] ?? ''));
        $revoked = snap_mcp()->grants()->revoke($id, 'disconnected by '.wp_get_current_user()->user_login);

        $this->back('mcp', $revoked ? __('Disconnected. The app has to sign in again to reconnect.', 'snapshot-core') : __('That connection was already gone.', 'snapshot-core'), [], $revoked ? 'success' : 'error');
    }

    public function report(): void
    {
        $this->guard('snapshot_system_report');

        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.sanitize_file_name(wp_parse_url(home_url(), PHP_URL_HOST).'-diagnostics-'.gmdate('Ymd').'.txt').'"');
        echo $this->diagnostics()->report();
        exit;
    }

    public function exportActivity(): void
    {
        $this->guard('snapshot_system_export_activity');

        if (! function_exists('snap_security') || ! Schema::ready()) {
            wp_die(esc_html__('The activity log is not available.', 'snapshot-core'));
        }

        $filters = $this->activityFilters($_POST);
        $log = snap_security()->log();
        $types = snap_security_event_types();

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="activity-'.gmdate('Ymd-His').'.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['time_utc', 'user', 'event', 'action', 'message', 'ip', 'severity']);

        for ($page = 1; $page <= 200; $page++) {
            $result = $log->query($filters, $page, 500);

            foreach ($result['rows'] as $row) {
                fputcsv($out, array_map(fn ($value) => preg_match('/^[=+\-@\t\r]/', (string) $value) ? "'".$value : $value, [
                    $row->created_at,
                    $row->username,
                    $row->event,
                    $types[$row->event]['label'] ?? $row->event,
                    $row->message,
                    $row->ip,
                    $row->severity,
                ]));
            }

            if ($page >= $result['pages']) {
                break;
            }
        }

        fclose($out);
        exit;
    }

    private function dataStatus(): array
    {
        return [
            'groups' => $this->diagnostics()->groups(),
            'links' => array_filter([
                'security' => function_exists('snap_security') ? admin_url('admin.php?page=snapshot-security') : '',
                'seo' => function_exists('snap_seo') ? admin_url('admin.php?page=snapshot-seo') : '',
                'health' => admin_url('site-health.php'),
            ]),
            'nonce' => wp_nonce_field('snapshot_system_report', '_wpnonce', true, false),
        ];
    }

    private function dataTools(): array
    {
        return [
            'tools' => $this->tools()->registry(),
            'views' => $this->diagnostics()->views(),
            'caches' => $this->diagnostics()->acornCaches(),
            'debug' => $this->tools()->debugTail(),
            'nonce' => wp_nonce_field('snapshot_system_tool', '_wpnonce', true, false),
        ];
    }

    private function dataExperience(): array
    {
        $sections = snap_system_sections();
        $section = sanitize_key((string) ($_GET['section'] ?? array_key_first($sections)));
        $section = array_key_exists($section, $sections) ? $section : array_key_first($sections);
        $settings = $this->settings();
        $fields = [];

        foreach ($settings->schema() as $key => $field) {
            if (($field['section'] ?? '') !== $section) {
                continue;
            }

            $fields[$key] = $field + [
                'key' => $key,
                'value' => $settings->get($key),
                'locked' => $settings->locked($key),
                'constant' => $settings->constant($key),
                'inherited' => $key === 'login_logo' && function_exists('snap_site_logo_url') ? snap_site_logo_url() : '',
                'topic' => '',
            ];
        }

        return [
            'sections' => $sections,
            'section' => $section,
            'fields' => $fields,
            'preview' => wp_login_url(),
            'nonce' => wp_nonce_field('snapshot_system_save', '_wpnonce', true, false),
        ];
    }

    private function dataActivity(): array
    {
        $ready = function_exists('snap_security') && Schema::ready();
        $filters = $this->activityFilters($_GET);
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $result = $ready ? snap_security()->log()->query($filters, $page, self::PER_PAGE) : ['rows' => [], 'total' => 0, 'pages' => 0];

        return $result + [
            'ready' => $ready,
            'filters' => $filters,
            'raw' => [
                'user' => (int) ($_GET['user'] ?? 0),
                'category' => sanitize_key((string) ($_GET['category'] ?? '')),
                'from' => sanitize_text_field((string) ($_GET['from'] ?? '')),
                'to' => sanitize_text_field((string) ($_GET['to'] ?? '')),
                's' => sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? ''))),
                'people' => ! empty($_GET['people']),
            ],
            'page' => $page,
            'people' => $ready ? $this->people() : [],
            'categories' => snap_activity_categories(),
            'types' => snap_security_event_types(),
            'retention' => $ready ? snap_security()->settings()->int('event_retention_days') : 0,
            'nonce' => wp_nonce_field('snapshot_system_export_activity', '_wpnonce', true, false),
        ];
    }

    private function dataMcp(): array
    {
        $tokens = snap_mcp()->tokens();
        $key = 'snapshot_mcp_new_token_'.get_current_user_id();
        $new = get_transient($key);
        delete_transient($key);
        $mine = get_current_user_id();
        $usage = $tokens->usage();
        $all = [];

        foreach ($tokens->all() as $id => $token) {
            $user = get_userdata((int) $token['user']);
            $all[$id] = $token + ($usage[$id] ?? ['used' => 0, 'ip' => '']) + ['person' => $user ? $user->display_name : __('Deleted user', 'snapshot-core')];
        }

        uasort($all, fn ($a, $b) => ((int) $b['user'] === $mine) <=> ((int) $a['user'] === $mine) ?: ($b['created'] <=> $a['created']));

        $connections = [];

        foreach (snap_mcp()->grants()->all() as $id => $grant) {
            $user = get_userdata((int) $grant['user']);
            $connections[$id] = $grant + snap_mcp()->grants()->usage((string) $id) + ['person' => $user ? $user->display_name : __('Deleted user', 'snapshot-core')];
        }

        return [
            'enabled' => Mcp::enabled(),
            'oauth' => Mcp::oauth(),
            'pretty' => Mcp::pretty(),
            'access' => Mcp::siteAccess(),
            'levels' => snap_mcp_access_levels(),
            'twoFactor' => Mcp::twoFactorOn(),
            'connections' => $connections,
            'endpoint' => Mcp::endpoint(),
            'host' => sanitize_title((string) wp_parse_url(home_url(), PHP_URL_HOST)),
            'tokens' => $all,
            'mine' => $mine,
            'new' => is_string($new) ? $new : '',
            'lifetimes' => snap_mcp_token_lifetimes(),
            'tools' => Mcp::enabled() ? snap_mcp()->abilities()->tools() : [],
            'can' => current_user_can(Mcp::capability()),
            'createNonce' => wp_nonce_field('snapshot_system_mcp_token', '_wpnonce', true, false),
            'revokeNonce' => wp_nonce_field('snapshot_system_mcp_revoke', '_wpnonce', true, false),
            'disconnectNonce' => wp_nonce_field('snapshot_system_mcp_disconnect', '_wpnonce', true, false),
        ];
    }

    private function people(): array
    {
        $people = get_transient('snapshot_system_people');

        if (! is_array($people)) {
            $people = snap_security()->log()->people();
            set_transient('snapshot_system_people', $people, 10 * MINUTE_IN_SECONDS);
        }

        return $people;
    }

    private function activityFilters(array $source): array
    {
        $from = sanitize_text_field((string) ($source['from'] ?? ''));
        $to = sanitize_text_field((string) ($source['to'] ?? ''));
        $category = sanitize_key((string) ($source['category'] ?? ''));

        return array_filter([
            'user_id' => (int) ($source['user'] ?? 0),
            'category' => array_key_exists($category, snap_activity_categories()) ? $category : '',
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? get_gmt_from_date($from.' 00:00:00') : '',
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? get_gmt_from_date($to.' 23:59:59') : '',
            'search' => sanitize_text_field(wp_unslash((string) ($source['s'] ?? ''))),
            'people' => ! empty($source['people']),
        ]);
    }

    private function icon(): string
    {
        $path = Core::path('resources/images/snapshot-mark.svg');
        $svg = is_readable($path) ? (string) file_get_contents($path) : '';

        return $svg !== '' ? 'data:image/svg+xml;base64,'.base64_encode(str_replace('currentColor', 'black', $svg)) : 'dashicons-admin-generic';
    }

    private function log(string $event, string $message, array $context = []): void
    {
        if (function_exists('snap_security_log')) {
            snap_security_log($event, $message, $context);
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
        set_transient('snapshot_system_flash_'.get_current_user_id(), ['message' => $message, 'type' => $type], MINUTE_IN_SECONDS);
        wp_safe_redirect(self::url($tab, $args));
        exit;
    }

    private function flash(): ?array
    {
        $key = 'snapshot_system_flash_'.get_current_user_id();
        $flash = get_transient($key);
        delete_transient($key);

        return is_array($flash) ? $flash : null;
    }
}

<?php

namespace Snapshot\Security\Admin;

use Snapshot\Core;
use Snapshot\Security\Ip;
use Snapshot\Security\Modules;
use Snapshot\Security\Shield;
use Snapshot\Security\TwoFactor\TwoFactor;
use Throwable;

class AdminPage
{
    public const SLUG = 'snapshot-security';

    private const CAPABILITY = 'manage_options';

    private const MENU_POSITION = 3;

    private const STATUS_KEY = 'snapshot_sec_status';

    private const STATUS_TTL = 5 * MINUTE_IN_SECONDS;

    private const TABS = [
        'overview' => 'Overview',
        'modules' => 'Control panel',
        'events' => 'Events',
        'blocks' => 'Blocks',
        'scans' => 'Scans',
        'settings' => 'Settings',
    ];

    public function __construct(private Shield $shield) {}

    public static function tabs(): array
    {
        return self::TABS;
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('wp_dashboard_setup', [$this, 'widget']);
        add_action('admin_notices', [$this, 'notices']);
        add_action('admin_head', [$this, 'styles']);

        foreach (['save_settings', 'save_modules', 'add_block', 'remove_block', 'run', 'accept_baseline'] as $action) {
            add_action("admin_post_snapshot_security_{$action}", [$this, $this->method($action)]);
        }
    }

    public function menu(): void
    {
        add_menu_page(
            __('Snapshot Security', 'snapshot-core'),
            __('Security', 'snapshot-core'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'render'],
            'dashicons-shield',
            self::MENU_POSITION
        );
    }

    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Sorry, you are not allowed to view this page.', 'snapshot-core'), 403);
        }

        $tab = sanitize_key((string) ($_GET['tab'] ?? 'overview'));
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'overview';

        echo $this->shield->responder()->render('snapshot::security.admin.page', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'base' => admin_url('admin.php?page='.self::SLUG),
            'notice' => $this->flash(),
            'status' => $this->status(true),
            'summary' => $this->summary(),
            'data' => $this->{'data'.ucfirst($tab)}(),
        ]);
    }

    public function styles(): void
    {
        $screen = get_current_screen();

        if (! $screen || ! in_array($screen->id, ['dashboard', 'profile', 'user-edit', 'toplevel_page_'.self::SLUG], true)) {
            return;
        }

        $entries = $screen->id === 'toplevel_page_'.self::SLUG
            ? ['resources/css/security-admin.css', 'resources/js/security.js']
            : ['resources/css/security-admin.css'];

        try {
            echo Core::assets($entries);
        } catch (Throwable) {
        }
    }

    public function widget(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        wp_add_dashboard_widget('snap_security', __('Security status', 'snapshot-core'), function () {
            echo $this->shield->responder()->render('snapshot::security.admin.widget', $this->summary() + [
                'status' => $this->status(),
                'recent' => $this->shield->log()->query(['severity' => 'critical'], 1, 4)['rows'],
            ]);
        });
    }

    public function notices(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        $screen = get_current_screen();

        if (! $screen || ! in_array($screen->id, ['dashboard', 'toplevel_page_'.self::SLUG], true)) {
            return;
        }

        if ($screen->id === 'toplevel_page_'.self::SLUG) {
            return;
        }

        $critical = array_filter($this->status()['issues'], fn ($issue) => $issue['level'] === 'critical');

        if ($critical !== []) {
            printf(
                '<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
                esc_html__('Security: action required.', 'snapshot-core'),
                esc_html(implode(' ', array_map(fn ($issue) => rtrim($issue['text'], '.').'.', $critical))),
                esc_url(admin_url('admin.php?page='.self::SLUG)),
                esc_html__('Review issues', 'snapshot-core')
            );
        }
    }

    public function saveSettings(): void
    {
        $this->guard('snapshot_security_save_settings');

        $section = sanitize_key((string) ($_POST['section'] ?? ''));

        if (! array_key_exists($section, snap_security_settings_tabs())) {
            $this->back('settings', __('Settings not saved: the settings section was missing from the request.', 'snapshot-core'), [], 'error');
        }

        $input = wp_unslash((array) ($_POST['snapshot_security'] ?? []));
        $result = $this->shield->settings()->save($input, $section);

        if ($result['changed'] !== []) {
            $this->shield->log()->log('security.settings_changed', 'Changed: '.implode(', ', $result['changed']), ['keys' => $result['changed']]);
        }

        if ($result['token']) {
            set_transient('snapshot_sec_token_'.get_current_user_id(), $result['token'], 5 * MINUTE_IN_SECONDS);
        }

        if (in_array('cloudflare_enabled', $result['changed'], true)) {
            $this->shield->cloudflare()->refreshRanges(true);
        }

        if ($result['rejected'] !== []) {
            $this->forgetStatus();
            $this->back('settings', sprintf(__('Settings saved. Invalid IP addresses or CIDR ranges were discarded: %s', 'snapshot-core'), implode(', ', $result['rejected'])), ['section' => $section], 'error');
        }

        $this->forgetStatus();
        $this->back('settings', __('Settings saved.', 'snapshot-core'), ['section' => $section]);
    }

    public function saveModules(): void
    {
        $this->guard('snapshot_security_save_modules');

        $modules = $this->shield->modules();
        $before = ['master' => $modules->master()] + $modules->states();
        $input = wp_unslash((array) ($_POST['snapshot_security'] ?? []));

        if (($_POST['preset'] ?? '') === 'overlaps') {
            $input = [];

            foreach ($this->shield->settings()->schema() as $key => $field) {
                if (($field['tab'] ?? '') === 'modules') {
                    $input[$key] = $this->shield->settings()->bool($key);
                }
            }

            foreach ($modules->overlaps() as $plugin) {
                foreach ($plugin['modules'] as $module) {
                    $setting = snap_security_modules()[$module]['setting'] ?? null;

                    if ($setting !== null) {
                        $input[$setting] = false;
                    }
                }
            }
        }

        $this->shield->settings()->save($input, 'modules');
        $after = ['master' => $modules->master()] + $modules->states();
        $changed = [];

        foreach ($after as $key => $on) {
            if ($on === ($before[$key] ?? null)) {
                continue;
            }

            $label = $key === 'master' ? __('Built-in security', 'snapshot-core') : snap_security_modules()[$key]['label'];
            $changed[] = sprintf('%s %s', $label, $on ? __('on', 'snapshot-core') : __('off', 'snapshot-core'));
            $critical = ! $on && in_array($key, ['master', 'twofactor', 'firewall', 'login'], true);
            $this->shield->log()->log('security.module_changed', sprintf('%s switched %s', $label, $on ? 'on' : 'off'), ['module' => $key, 'enabled' => $on, 'severity' => $critical ? 'critical' : 'warning']);
        }

        if (($before['feeds'] ?? false) && ! ($after['feeds'] ?? false)) {
            $this->shield->blocklist()->removeSources('feed:');
        }

        $this->forgetStatus();
        $this->back('modules', $changed === [] ? __('No changes.', 'snapshot-core') : sprintf(__('Saved: %s.', 'snapshot-core'), implode(', ', $changed)));
    }

    public function addBlock(): void
    {
        $this->guard('snapshot_security_add_block');

        $target = sanitize_text_field(wp_unslash((string) ($_POST['target'] ?? '')));
        $action = in_array($_POST['block_action'] ?? '', ['block', 'allow'], true) ? $_POST['block_action'] : 'block';
        $minutes = max(0, (int) ($_POST['minutes'] ?? 0));
        $reason = sanitize_text_field(wp_unslash((string) ($_POST['reason'] ?? '')));

        if (Ip::range($target) === null) {
            $this->back('blocks', __('Invalid IP address or CIDR range.', 'snapshot-core'), [], 'error');
        }

        if (Ip::tooWide($target)) {
            $this->back('blocks', sprintf(__('Range too wide. Use a prefix of /%1$d or longer for IPv4 and /%2$d or longer for IPv6.', 'snapshot-core'), Ip::MIN_V4_PREFIX, Ip::MIN_V6_PREFIX), [], 'error');
        }

        if ($action === 'block' && Ip::matches($this->shield->ip()->client(), [$target])) {
            $this->back('blocks', __('Not blocked: the range includes your current IP address.', 'snapshot-core'), [], 'error');
        }

        $this->shield->blocklist()->add($target, $action, 'site', 'manual', $reason, $minutes ? $minutes * MINUTE_IN_SECONDS : null);
        $this->shield->log()->log('security.block_added', sprintf('%s %s%s', ucfirst($action), $target, $reason ? ": {$reason}" : ''), ['target' => $target, 'action' => $action, 'minutes' => $minutes]);

        $this->back('blocks', __('Rule saved.', 'snapshot-core'));
    }

    public function removeBlock(): void
    {
        $this->guard('snapshot_security_remove_block');

        $id = (int) ($_POST['id'] ?? 0);
        $row = $this->shield->blocklist()->find($id);

        if ($row && in_array($row->source, ['manual', 'lockout', 'firewall'], true)) {
            $this->shield->blocklist()->remove($id);
            $this->shield->throttle()->clear('lockouts:'.$row->label);
            $this->shield->log()->log('security.block_removed', "Removed {$row->action} for {$row->label}", ['source' => $row->source]);
        }

        $this->back('blocks', __('Rule removed.', 'snapshot-core'));
    }

    public function run(): void
    {
        $this->guard('snapshot_security_run');

        $task = sanitize_key((string) ($_REQUEST['task'] ?? ''));
        $shield = $this->shield;

        $message = match ($task) {
            'scan' => (function () use ($shield) {
                $result = $shield->scanner()->run();

                return sprintf(__('File integrity scan complete: %1$d findings, %2$d critical.', 'snapshot-core'), $result['total'], $result['counts']['critical']);
            })(),
            'vulns' => sprintf(__('Vulnerability check complete: %d known vulnerabilities.', 'snapshot-core'), count($shield->vulnerabilities()->run()['items'])),
            'feeds' => sprintf(__('Threat intelligence feeds updated: %d entries.', 'snapshot-core'), array_sum(array_column($shield->feeds()->sync(), 'count'))),
            'digest' => $shield->alerts()->digest(true) ? __('Security digest sent.', 'snapshot-core') : __('Security digest not sent. Check the site mail configuration.', 'snapshot-core'),
            'ranges' => $shield->cloudflare()->refreshRanges(true) ? __('Cloudflare IP ranges updated.', 'snapshot-core') : __('Cloudflare IP ranges not updated. Enable the Cloudflare setting first.', 'snapshot-core'),
            default => __('Unknown task.', 'snapshot-core'),
        };

        $this->forgetStatus();
        $this->back(in_array($task, ['scan', 'vulns'], true) ? 'scans' : 'overview', $message);
    }

    public function acceptBaseline(): void
    {
        $this->guard('snapshot_security_accept_baseline');

        $origin = sanitize_text_field(wp_unslash((string) ($_POST['origin'] ?? '')));
        $this->shield->scanner()->acceptBaseline($origin !== '' ? $origin : null);
        $this->shield->log()->log('security.baseline_accepted', $origin !== '' ? "Accepted current files for {$origin}" : 'Accepted current files for every baseline');
        $result = $this->shield->scanner()->run();

        $this->forgetStatus();
        $this->back('scans', sprintf(__('Baseline recorded. Current scan: %d findings.', 'snapshot-core'), $result['total']));
    }

    private function dataOverview(): array
    {
        return $this->summary() + [
            'checks' => $this->shield->posture()->checks(),
            'week' => $this->shield->log()->stats(gmdate('Y-m-d H:i:s', time() - WEEK_IN_SECONDS)),
            'recent' => $this->shield->log()->query(['severity' => 'critical'], 1, 8)['rows'],
        ];
    }

    private function dataModules(): array
    {
        $settings = $this->shield->settings();
        $modules = $this->shield->modules();
        $items = [];

        foreach ($modules->registry() as $key => $module) {
            $items[$key] = $module + [
                'value' => $settings->bool($module['setting']),
                'on' => $modules->on($key),
                'locked' => $settings->locked($module['setting']),
                'constant' => $settings->constant($module['setting']),
            ];
        }

        return [
            'master' => [
                'value' => $modules->master(),
                'locked' => $settings->locked(Modules::MASTER),
                'constant' => $settings->constant(Modules::MASTER),
            ],
            'items' => $items,
            'overlaps' => $modules->overlaps(),
            'emergency2fa' => TwoFactor::emergencyOff(),
            'nonce' => wp_nonce_field('snapshot_security_save_modules', '_wpnonce', true, false),
        ];
    }

    private function dataEvents(): array
    {
        $filters = [
            'severity' => sanitize_key((string) ($_GET['severity'] ?? '')),
            'event' => sanitize_text_field(wp_unslash((string) ($_GET['event'] ?? ''))),
            'ip' => sanitize_text_field(wp_unslash((string) ($_GET['ip'] ?? ''))),
            'user' => sanitize_user(wp_unslash((string) ($_GET['user'] ?? ''))),
        ];
        $page = max(1, (int) ($_GET['paged'] ?? 1));

        return ['filters' => $filters, 'page' => $page, 'types' => snap_security_event_types()] + $this->shield->log()->query($filters, $page, 50);
    }

    private function dataBlocks(): array
    {
        return [
            'rows' => $this->shield->blocklist()->active(['manual', 'lockout', 'firewall']),
            'counts' => $this->shield->blocklist()->counts(),
            'feeds' => snap_security_feeds(),
            'status' => $this->shield->feeds()->status(),
            'clientIp' => $this->shield->ip()->client(),
            'nonces' => [
                'add' => wp_nonce_field('snapshot_security_add_block', '_wpnonce', true, false),
                'remove' => wp_create_nonce('snapshot_security_remove_block'),
                'run' => wp_nonce_field('snapshot_security_run', '_wpnonce', true, false),
            ],
        ];
    }

    private function dataScans(): array
    {
        return [
            'scan' => $this->shield->scanner()->last(),
            'vulns' => $this->shield->vulnerabilities()->last(),
            'nonces' => [
                'run' => wp_nonce_field('snapshot_security_run', '_wpnonce', true, false),
                'accept' => wp_nonce_field('snapshot_security_accept_baseline', '_wpnonce', true, false),
            ],
        ];
    }

    private function dataSettings(): array
    {
        $settings = $this->shield->settings();
        $schema = $settings->schema();
        $fields = [];

        foreach ($schema as $key => $field) {
            $fields[$field['tab'] ?? 'login'][$key] = $field + [
                'value' => in_array($field['type'], ['secret', 'token'], true) ? '' : $settings->get($key),
                'isSet' => $settings->has($key),
                'locked' => $settings->locked($key),
                'constant' => $settings->constant($key),
            ];
        }

        $token = get_transient('snapshot_sec_token_'.get_current_user_id());
        delete_transient('snapshot_sec_token_'.get_current_user_id());

        $sections = snap_security_settings_tabs();
        $section = sanitize_key((string) ($_GET['section'] ?? array_key_first($sections)));
        $section = array_key_exists($section, $sections) ? $section : array_key_first($sections);

        return [
            'fields' => $fields,
            'sections' => snap_security_settings_tabs(),
            'section' => $section,
            'issuedToken' => is_string($token) ? $token : null,
            'statusUrl' => rest_url('snapshot/v1/security/status'),
            'nonce' => wp_nonce_field('snapshot_security_save_settings', '_wpnonce', true, false),
            'sectionModules' => array_filter(
                array_map(fn ($module, $key) => $module + ['key' => $key, 'on' => $this->shield->modules()->on($key)], snap_security_modules(), array_keys(snap_security_modules())),
                fn ($module) => $module['section'] === $section
            ),
            'modulesUrl' => admin_url('admin.php?page='.self::SLUG.'&tab=modules'),
        ];
    }

    private function summary(): array
    {
        $shield = $this->shield;
        $scan = $shield->scanner()->last();
        $vulns = $shield->vulnerabilities()->last();
        $counts = $shield->blocklist()->counts();

        return [
            'day' => $shield->log()->stats(gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)),
            'scan' => $scan,
            'vulnerabilities' => count($vulns['items'] ?? []),
            'vulnItems' => $vulns['items'] ?? [],
            'enrollment' => $shield->posture()->enrollment(),
            'firewallMode' => $shield->settings()->string('firewall_mode'),
            'lockouts' => array_sum($counts['lockout'] ?? []),
            'manual' => array_sum($counts['manual'] ?? []) + array_sum($counts['firewall'] ?? []),
            'feedEntries' => array_sum(array_map(
                fn ($source) => array_sum($counts[$source] ?? []),
                array_filter(array_keys($counts), fn ($source) => str_starts_with($source, 'feed:'))
            )),
            'base' => admin_url('admin.php?page='.self::SLUG),
            'runNonce' => wp_nonce_field('snapshot_security_run', '_wpnonce', true, false),
        ];
    }

    public function status(bool $fresh = false): array
    {
        $cached = get_transient(self::STATUS_KEY);

        if (! $fresh && is_array($cached)) {
            return $cached;
        }

        $base = admin_url('admin.php?page='.self::SLUG);
        $issues = [];

        foreach ($this->shield->posture()->checks() as $check) {
            if ($check['status'] !== 'ok') {
                $issues[] = [
                    'level' => $check['status'] === 'fail' ? 'critical' : 'warning',
                    'text' => $check['label'].': '.$check['detail'],
                    'url' => $check['key'] === 'modules' ? $base.'&tab=modules' : $base,
                ];
            }
        }

        $scan = $this->shield->scanner()->last();
        $findings = (int) ($scan['counts']['critical'] ?? 0);

        if ($findings > 0) {
            $issues[] = ['level' => 'critical', 'text' => sprintf(_n('%d critical file integrity finding', '%d critical file integrity findings', $findings, 'snapshot-core'), $findings), 'url' => $base.'&tab=scans'];
        }

        $vulns = count($this->shield->vulnerabilities()->last()['items'] ?? []);

        if ($vulns > 0) {
            $issues[] = ['level' => 'critical', 'text' => sprintf(_n('%d known vulnerability in installed software', '%d known vulnerabilities in installed software', $vulns, 'snapshot-core'), $vulns), 'url' => $base.'&tab=scans'];
        }

        $events = $this->shield->log()->stats(gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS))['critical'];

        if ($events > 0) {
            $issues[] = ['level' => 'warning', 'text' => sprintf(_n('%d critical event in the last 24 hours', '%d critical events in the last 24 hours', $events, 'snapshot-core'), $events), 'url' => $base.'&tab=events&severity=critical'];
        }

        usort($issues, fn ($a, $b) => ($a['level'] === 'critical' ? 0 : 1) <=> ($b['level'] === 'critical' ? 0 : 1));

        $levels = array_column($issues, 'level');
        $level = in_array('critical', $levels, true) ? 'critical' : ($levels !== [] ? 'warning' : 'ok');

        $status = [
            'level' => $level,
            'label' => ! $this->shield->modules()->master() ? __('Security off', 'snapshot-core') : match ($level) {
                'critical' => __('Action required', 'snapshot-core'),
                'warning' => __('Review recommended', 'snapshot-core'),
                default => __('Protected', 'snapshot-core'),
            },
            'critical' => count(array_keys($levels, 'critical', true)),
            'warning' => count(array_keys($levels, 'warning', true)),
            'issues' => $issues,
            'checked' => time(),
        ];

        set_transient(self::STATUS_KEY, $status, self::STATUS_TTL);

        return $status;
    }

    public function forgetStatus(): void
    {
        delete_transient(self::STATUS_KEY);
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
        set_transient('snapshot_sec_flash_'.get_current_user_id(), ['message' => $message, 'type' => $type], MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(array_filter(['page' => self::SLUG, 'tab' => $tab] + $args), admin_url('admin.php')));
        exit;
    }

    private function flash(): ?array
    {
        $key = 'snapshot_sec_flash_'.get_current_user_id();
        $flash = get_transient($key);
        delete_transient($key);

        return is_array($flash) ? $flash : null;
    }

    private function method(string $action): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $action))));
    }
}

<?php

namespace Snapshot\Security\Admin;

use Snapshot\Core;
use Snapshot\Security\Shield;
use Throwable;
use WP_Admin_Bar;

class AdminBar
{
    private const CAPABILITY = 'manage_options';

    private const NODE = 'snapshot-security';

    private const POSITION = 80;

    public function __construct(private Shield $shield) {}

    public function boot(): void
    {
        add_action('admin_bar_menu', [$this, 'menu'], self::POSITION);
        add_action('wp_head', [$this, 'styles']);
        add_action('admin_head', [$this, 'styles']);
    }

    public function menu(WP_Admin_Bar $bar): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        $status = $this->shield->admin()->status();
        $base = admin_url('admin.php?page='.AdminPage::SLUG);
        $count = $status['critical'] ?: $status['warning'];

        $badge = $count > 0
            ? sprintf(
                '<span class="snap-ab-badge is-%1$s" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span>',
                esc_attr($status['level']),
                esc_html(number_format_i18n($count)),
                esc_html(sprintf(_n('%1$d issue: %2$s', '%1$d issues: %2$s', $count, 'snapshot-core'), $count, $status['label']))
            )
            : '<span class="snap-ab-dot" aria-hidden="true"></span><span class="screen-reader-text">'.esc_html($status['label']).'</span>';

        $bar->add_node([
            'id' => self::NODE,
            'title' => '<span class="ab-icon dashicons dashicons-shield" aria-hidden="true"></span><span class="ab-label">'.esc_html__('Security', 'snapshot-core').'</span>'.$badge,
            'href' => $base,
            'meta' => ['class' => 'snap-ab is-'.$status['level']],
        ]);

        $bar->add_node([
            'parent' => self::NODE,
            'id' => self::NODE.'-status',
            'title' => esc_html(sprintf(__('Status: %s', 'snapshot-core'), $status['label'])),
            'href' => $base,
            'meta' => ['class' => 'snap-ab-status is-'.$status['level']],
        ]);

        foreach (array_slice($status['issues'], 0, 3) as $index => $issue) {
            $bar->add_node([
                'parent' => self::NODE,
                'id' => self::NODE.'-issue-'.$index,
                'title' => '<span class="screen-reader-text">'.esc_html($issue['level'] === 'critical' ? __('Critical:', 'snapshot-core') : __('Warning:', 'snapshot-core')).' </span>'.esc_html(wp_html_excerpt($issue['text'], 60, '…')),
                'href' => $issue['url'],
                'meta' => ['class' => 'snap-ab-issue is-'.$issue['level']],
            ]);
        }

        $bar->add_group(['parent' => self::NODE, 'id' => self::NODE.'-pages', 'meta' => ['class' => 'ab-sub-secondary']]);

        foreach (AdminPage::tabs() as $tab => $label) {
            $bar->add_node([
                'parent' => self::NODE.'-pages',
                'id' => self::NODE.'-'.$tab,
                'title' => esc_html__($label, 'snapshot-core'),
                'href' => $tab === 'overview' ? $base : add_query_arg('tab', $tab, $base),
            ]);
        }

        $bar->add_node([
            'parent' => self::NODE.'-pages',
            'id' => self::NODE.'-scan',
            'title' => esc_html__('Run file integrity scan', 'snapshot-core'),
            'href' => wp_nonce_url(admin_url('admin-post.php?action=snapshot_security_run&task=scan'), 'snapshot_security_run'),
        ]);
    }

    public function styles(): void
    {
        if (! is_admin_bar_showing() || ! current_user_can(self::CAPABILITY)) {
            return;
        }

        try {
            echo Core::assets(['resources/css/security-adminbar.css']);
        } catch (Throwable) {
        }
    }
}

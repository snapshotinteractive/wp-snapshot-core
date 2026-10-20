<?php

namespace Snapshot\Seo\Admin;

use Snapshot\Core;
use Snapshot\Seo\Engine;
use Throwable;
use WP_Admin_Bar;

class AdminBar
{
    private const NODE = 'snapshot-seo';

    private const POSITION = 81;

    public function __construct(private Engine $engine) {}

    public function boot(): void
    {
        add_action('admin_bar_menu', [$this, 'menu'], self::POSITION);
        add_action('wp_head', [$this, 'styles']);
        add_action('admin_head', [$this, 'styles']);
    }

    public function menu(WP_Admin_Bar $bar): void
    {
        if (! current_user_can(AdminPage::CAPABILITY)) {
            return;
        }

        $status = $this->engine->admin()->status();
        $base = AdminPage::url();
        $issues = array_values(array_filter($status['checks'], fn ($check) => in_array($check['status'], ['fail', 'warn'], true)));

        $bar->add_node([
            'id' => self::NODE,
            'title' => sprintf(
                '<span class="ab-icon dashicons dashicons-search" aria-hidden="true"></span><span class="ab-label">%s</span><span class="snap-ab-badge is-%s" aria-hidden="true">%d</span><span class="screen-reader-text">%s</span>',
                esc_html__('SEO', 'snapshot-core'),
                esc_attr($status['level']),
                (int) $status['score'],
                esc_html(sprintf(__('SEO health %1$d%%: %2$s', 'snapshot-core'), $status['score'], $status['label']))
            ),
            'href' => $base,
            'meta' => ['class' => 'snap-ab is-'.$status['level']],
        ]);

        if (! is_admin() && $this->engine->modules()->on('head')) {
            $current = $this->engine->head()->current();
            $url = $current['canonical'] ?: home_url(add_query_arg([]));

            $bar->add_node([
                'parent' => self::NODE,
                'id' => self::NODE.'-page',
                'title' => esc_html(sprintf(__('This page: %s', 'snapshot-core'), $current['robots']['index'] ? __('shown in search', 'snapshot-core') : __('hidden from search', 'snapshot-core').' ('.$current['robots']['reason'].')')),
                'href' => false,
                'meta' => ['class' => 'snap-ab-status is-'.($current['robots']['index'] ? 'ok' : 'warning')],
            ]);

            $bar->add_node([
                'parent' => self::NODE,
                'id' => self::NODE.'-title',
                'title' => esc_html(wp_html_excerpt(sprintf(__('Title: %s', 'snapshot-core'), $current['title']), 70, '…')),
                'href' => false,
            ]);

            $object = get_queried_object();

            if ($object instanceof \WP_Post && current_user_can('edit_post', $object->ID)) {
                $bar->add_node([
                    'parent' => self::NODE,
                    'id' => self::NODE.'-edit',
                    'title' => esc_html__('Edit search and sharing', 'snapshot-core'),
                    'href' => get_edit_post_link($object->ID, 'raw').'#acf-group_snapshot_seo',
                ]);
            }

            $bar->add_group(['parent' => self::NODE, 'id' => self::NODE.'-test', 'meta' => ['class' => 'ab-sub-secondary']]);

            foreach ([
                'rich' => [__('Test rich results', 'snapshot-core'), 'https://search.google.com/test/rich-results?url='.rawurlencode($url)],
                'schema' => [__('Validate structured data', 'snapshot-core'), 'https://validator.schema.org/#url='.rawurlencode($url)],
                'speed' => [__('Test page speed', 'snapshot-core'), 'https://pagespeed.web.dev/analysis?url='.rawurlencode($url)],
                'share' => [__('Preview on LinkedIn', 'snapshot-core'), 'https://www.linkedin.com/post-inspector/inspect/'.rawurlencode($url)],
            ] as $key => [$label, $href]) {
                $bar->add_node(['parent' => self::NODE.'-test', 'id' => self::NODE.'-'.$key, 'title' => esc_html($label), 'href' => $href, 'meta' => ['target' => '_blank', 'rel' => 'noopener']]);
            }
        } else {
            foreach (array_slice($issues, 0, 3) as $index => $issue) {
                $bar->add_node([
                    'parent' => self::NODE,
                    'id' => self::NODE.'-issue-'.$index,
                    'title' => esc_html(wp_html_excerpt($issue['label'].': '.$issue['detail'], 60, '…')),
                    'href' => $issue['url'],
                    'meta' => ['class' => 'snap-ab-issue is-'.($issue['status'] === 'fail' ? 'critical' : 'warning')],
                ]);
            }
        }

        $bar->add_group(['parent' => self::NODE, 'id' => self::NODE.'-pages', 'meta' => ['class' => 'ab-sub-secondary']]);

        foreach (AdminPage::tabs() as $tab => $label) {
            $bar->add_node([
                'parent' => self::NODE.'-pages',
                'id' => self::NODE.'-tab-'.$tab,
                'title' => esc_html__($label, 'snapshot-core'),
                'href' => AdminPage::url($tab),
            ]);
        }
    }

    public function styles(): void
    {
        if (! is_admin_bar_showing() || ! current_user_can(AdminPage::CAPABILITY)) {
            return;
        }

        try {
            echo Core::assets(['resources/css/security-adminbar.css']);
        } catch (Throwable) {
        }
    }
}

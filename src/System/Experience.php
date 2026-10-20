<?php

namespace Snapshot\System;

use Snapshot\Core;
use Throwable;
use WP_Admin_Bar;

class Experience
{
    private const LOGIN_VARIABLES = [
        'login_background' => '--color-dark',
        'login_panel' => '--color-paper',
        'login_text' => '--color-ink',
        'login_button' => '--color-primary',
        'login_button_text' => '--color-primary-fg',
        'login_accent' => '--color-accent',
    ];

    public function __construct(private Settings $settings) {}

    public function boot(): void
    {
        add_action('login_head', [$this, 'loginHead']);
        add_filter('login_headerurl', [$this, 'loginLogoUrl']);
        add_filter('login_headertext', fn () => get_bloginfo('name'));
        add_filter('login_message', [$this, 'loginMessage']);
        add_filter('login_body_class', fn ($classes) => [...$classes, 'snap-login']);
        add_filter('login_display_language_dropdown', fn ($show) => $this->settings->bool('login_hide_language') ? false : $show);

        if ($this->settings->bool('login_hide_back_link')) {
            add_action('login_head', fn () => print ('<style>body.login #backtoblog{display:none}</style>'));
        }

        add_filter('show_admin_bar', [$this, 'frontAdminBar'], 20);

        if (! is_admin()) {
            return;
        }

        add_action('admin_head', [$this, 'notices'], 1);
        add_action('admin_init', [$this, 'nags']);

        if ($this->settings->bool('clean_dashboard')) {
            add_action('wp_dashboard_setup', [$this, 'cleanDashboard'], 999);
        }

        if ($this->settings->bool('support_widget')) {
            add_action('wp_dashboard_setup', [$this, 'supportWidget']);
        }

        if ($this->settings->bool('hide_help_tabs')) {
            add_action('admin_head', fn () => get_current_screen()?->remove_help_tabs(), 999);
        }

        if ($this->settings->bool('hide_footer_version')) {
            add_filter('update_footer', '__return_empty_string', 999);
        }

        if ($this->settings->bool('replace_howdy')) {
            add_action('admin_bar_menu', [$this, 'greeting'], 9999);
        }

        if ($this->settings->bool('hide_editor_welcome')) {
            add_action('enqueue_block_editor_assets', [$this, 'skipWelcomeGuide']);
        }

        if ($this->settings->bool('force_admin_color')) {
            add_filter('get_user_option_admin_color', fn () => 'snapshot', 20);
            remove_action('admin_color_scheme_picker', 'admin_color_scheme_picker');
        }
    }

    public function loginHead(): void
    {
        try {
            echo Core::assets(['resources/css/login.css']);
        } catch (Throwable) {
            return;
        }

        $properties = ['--snap-login-logo' => 'url("'.esc_url($this->loginLogo()).'")'];

        foreach (self::LOGIN_VARIABLES as $key => $property) {
            if ($color = sanitize_hex_color($this->settings->string($key))) {
                $properties[$property] = $color;
            }
        }

        if (isset($properties['--color-primary'])) {
            $properties['--color-primary-dark'] = "color-mix(in oklab, {$properties['--color-primary']} 85%, black)";
        }

        $css = implode('', array_map(fn ($property, $value) => "{$property}:{$value};", array_keys($properties), $properties));

        printf('<style>:root{%s}</style>', $css);

        if ($this->settings->string('login_logo_link') === 'none') {
            echo '<style>body.login.snap-login h1 a{pointer-events:none}</style>';
        }
    }

    public function loginLogo(): string
    {
        $id = $this->settings->int('login_logo');

        if ($id && ($url = wp_get_attachment_image_url($id, 'full'))) {
            return $url;
        }

        return function_exists('snap_site_logo_url') ? snap_site_logo_url() : '';
    }

    public function loginLogoUrl(): string
    {
        return match ($this->settings->string('login_logo_link')) {
            'agency' => 'https://snapshot.agency/',
            default => home_url('/'),
        };
    }

    public function loginMessage(string $message): string
    {
        $text = $this->settings->string('login_message');

        if ($text === '' || ! in_array($_GET['action'] ?? 'login', ['login', ''], true)) {
            return $message;
        }

        return $message.'<p class="message snap-login-message">'.nl2br(esc_html($text)).'</p>';
    }

    public function frontAdminBar(bool $show): bool
    {
        if (is_admin() || ! $show) {
            return $show;
        }

        return match ($this->settings->string('admin_bar_front')) {
            'none' => false,
            'editors' => current_user_can('edit_others_posts'),
            default => $show,
        };
    }

    public function notices(): void
    {
        $mode = $this->settings->string('notices_mode');
        $screen = get_current_screen();

        if (! in_array($mode, ['quiet', 'silent'], true) || ($mode === 'quiet' && current_user_can('manage_options'))) {
            return;
        }

        if ($screen && (str_contains((string) $screen->id, 'snapshot') || in_array($screen->base, ['plugins', 'update-core', 'update', 'options-general'], true))) {
            return;
        }

        echo '<style>#wpbody-content > .notice:not(.notice-error):not(.settings-error):not(.inline):not(.snap-notice),#wpbody-content > .updated:not(.settings-error),#wpbody-content > .update-nag,#wpbody-content .wrap > .notice:not(.notice-error):not(.settings-error):not(.inline):not(.snap-notice){display:none!important}</style>';
    }

    public function nags(): void
    {
        $mode = $this->settings->string('notices_mode');

        if ($mode === 'all' || ($mode !== 'silent' && current_user_can('update_core'))) {
            return;
        }

        remove_action('admin_notices', 'update_nag', 3);
        remove_action('network_admin_notices', 'update_nag', 3);
        remove_action('admin_notices', 'maintenance_nag', 10);
        remove_action('admin_notices', 'site_admin_notice');
    }

    public function cleanDashboard(): void
    {
        remove_action('welcome_panel', 'wp_welcome_panel');

        foreach (['dashboard_primary' => 'side', 'dashboard_quick_press' => 'side', 'dashboard_secondary' => 'side', 'dashboard_recent_drafts' => 'side'] as $widget => $context) {
            remove_meta_box($widget, 'dashboard', $context);
        }
    }

    public function supportWidget(): void
    {
        wp_add_dashboard_widget('snapshot_support', __('Help with this site', 'snapshot-core'), function () {
            $email = sanitize_email($this->settings->string('support_email'));
            $url = esc_url($this->settings->string('support_url') ?: 'https://snapshot.agency/');

            echo '<p>'.esc_html__('This site is built and supported by Snapshot. For help with content, a change or something not working, get in touch.', 'snapshot-core').'</p><ul>';

            if ($email !== '') {
                printf('<li><a href="%s">%s</a></li>', esc_url('mailto:'.$email), esc_html($email));
            }

            printf('<li><a href="%s" target="_blank" rel="noopener">%s</a></li>', $url, esc_html(wp_parse_url($url, PHP_URL_HOST) ?: $url));

            if (current_user_can('manage_options')) {
                printf('<li><a href="%s">%s</a></li>', esc_url(admin_url('admin.php?page='.Panel::SLUG)), esc_html__('System status and tools', 'snapshot-core'));
            }

            echo '</ul>';
        });
    }

    public function greeting(WP_Admin_Bar $bar): void
    {
        $node = $bar->get_node('my-account');
        $user = wp_get_current_user();

        if (! $node || ! $user->exists()) {
            return;
        }

        $prefix = (string) strstr(__('Howdy, %s'), '%s', true);

        if ($prefix !== '') {
            $bar->add_node(['id' => 'my-account', 'title' => str_replace($prefix, '', (string) $node->title)]);
        }
    }

    public function skipWelcomeGuide(): void
    {
        wp_add_inline_script('wp-data', "window.addEventListener('DOMContentLoaded',function(){if(window.wp&&wp.data&&wp.data.select('core/preferences')&&wp.data.select('core/preferences').get('core/edit-post','welcomeGuide')){wp.data.dispatch('core/preferences').set('core/edit-post','welcomeGuide',false);}});");
    }
}

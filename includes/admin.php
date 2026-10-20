<?php

use Snapshot\Core;
use Snapshot\System\Panel;

function snap_admin_colors(): array
{
    static $colors = null;

    if ($colors !== null) {
        return $colors;
    }

    $colors = [];

    foreach ([Core::path('resources/css/tokens.css'), get_theme_file_path('resources/css/tokens.css')] as $path) {
        $tokens = is_readable($path) ? file_get_contents($path) : false;

        if (is_string($tokens) && preg_match_all('/--color-snapshot-([a-z0-9-]+):\s*(#[0-9a-fA-F]{3,8})\s*;/', $tokens, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $colors[$match[1]] = strtolower($match[2]);
            }
        }
    }

    return $colors;
}

function snap_logomark_svg(): string
{
    static $svg = null;

    if ($svg === null) {
        $path = Core::path('resources/images/snapshot-mark.svg');
        $file = is_readable($path) ? file_get_contents($path) : false;
        $svg = is_string($file) && str_starts_with(ltrim($file), '<svg') ? trim($file) : '';
    }

    return $svg;
}

function snap_site_logo_url(): string
{
    $id = (int) get_option('options_logo');

    if ($id && ($url = wp_get_attachment_image_url($id, 'full'))) {
        return $url;
    }

    try {
        return Core::url('resources/images/snapshot-wordmark.svg');
    } catch (Throwable) {
        return '';
    }
}

function snap_options_field_capabilities(string $fieldKey): array
{
    $field = acf_get_field($fieldKey);
    $seen = 0;

    while ($field && ! empty($field['parent']) && is_string($field['parent']) && str_starts_with($field['parent'], 'field_') && $seen++ < 10) {
        $field = acf_get_field($field['parent']);
    }

    $group = $field && ! empty($field['parent']) ? acf_get_field_group($field['parent']) : null;
    $caps = [];

    foreach ((array) ($group['location'] ?? []) as $rules) {
        foreach ((array) $rules as $rule) {
            if (($rule['param'] ?? '') === 'options_page' && ($rule['operator'] ?? '') === '==') {
                $page = acf_get_options_page((string) $rule['value']);
                $caps[] = $page['capability'] ?? 'manage_options';
            }
        }
    }

    return $caps;
}

add_action('acf/save_post', function ($postId) {
    if (! in_array($postId, ['option', 'options'], true) || ! is_array($_POST['acf'] ?? null) || ! function_exists('acf_get_options_page')) {
        return;
    }

    foreach (array_keys($_POST['acf']) as $fieldKey) {
        $caps = snap_options_field_capabilities((string) $fieldKey);

        if (! $caps || ! array_filter($caps, fn ($cap) => current_user_can($cap))) {
            unset($_POST['acf'][$fieldKey]);
        }
    }
}, 4);

add_action('admin_enqueue_scripts', function () {
    wp_enqueue_style('snap-admin', Core::asset('resources/css/snap-admin.css') ?? false, ['colors'], null);
    if (function_exists('snap_admin_menu_section_css')) {
        wp_add_inline_style('snap-admin', snap_admin_menu_section_css());
    }
});

add_action('admin_init', function () {
    $colors = snap_admin_colors();

    if (! isset($colors['edge'], $colors['navy'], $colors['orange'], $colors['sincere'], $colors['grey-400'], $colors['white'])) {
        return;
    }

    $suffix = (is_rtl() ? '-rtl' : '').(SCRIPT_DEBUG ? '' : '.min');

    wp_admin_css_color(
        'snapshot',
        'Snapshot',
        admin_url("css/colors/modern/colors{$suffix}.css"),
        [$colors['edge'], $colors['navy'], $colors['orange'], $colors['sincere']],
        ['base' => $colors['grey-400'], 'focus' => $colors['orange'], 'current' => $colors['white']]
    );
});

add_filter('get_user_option_admin_color', fn ($color) => empty($color) ? 'snapshot' : $color);

add_filter('insert_user_meta', function ($meta, $user, $update, $userdata) {
    if (! $update && empty($userdata['admin_color'])) {
        $meta['admin_color'] = 'snapshot';
    }

    return $meta;
}, 10, 4);

add_filter('admin_footer_text', function () {
    $custom = function_exists('snap_system_setting') ? trim((string) snap_system_setting('footer_text')) : '';

    return $custom !== ''
        ? '<span id="footer-thankyou">'.esc_html($custom).'</span>'
        : sprintf('<span id="footer-thankyou">Built and supported by <a href="%s" rel="noopener">Snapshot</a></span>', esc_url('https://snapshot.agency/'));
});

add_action('admin_bar_init', function () {
    $orange = snap_admin_colors()['orange'] ?? 'currentColor';

    wp_add_inline_style('admin-bar', implode('', [
        '#wpadminbar #wp-admin-bar-snapshot>.ab-item{display:flex;align-items:center;padding:0 10px}',
        '#wpadminbar #wp-admin-bar-snapshot .snap-ab-logo{display:flex;align-items:center;justify-content:center;float:none;height:32px;margin:0;padding:0;color:'.$orange.'}',
        '#wpadminbar #wp-admin-bar-snapshot .snap-ab-logo svg{display:block;width:17px;height:20px}',
        '@media screen and (max-width:782px){',
        '#wpadminbar li#wp-admin-bar-snapshot{display:block}',
        '#wpadminbar #wp-admin-bar-snapshot>.ab-item{justify-content:center;width:52px;height:46px;padding:0}',
        '#wpadminbar #wp-admin-bar-snapshot .snap-ab-logo{height:46px}',
        '#wpadminbar #wp-admin-bar-snapshot .snap-ab-logo svg{width:24px;height:28px}',
        '}',
    ]));
});

add_action('admin_bar_menu', function ($bar) {
    $bar->remove_node('wp-logo');

    $svg = snap_logomark_svg();

    $bar->add_node([
        'id' => 'snapshot',
        'title' => $svg !== ''
            ? '<span class="ab-icon snap-ab-logo" aria-hidden="true">'.$svg.'</span><span class="screen-reader-text">Snapshot</span>'
            : 'Snapshot',
        'href' => 'https://snapshot.agency/',
        'meta' => ['menu_title' => 'Snapshot'],
    ]);

    $bar->add_node([
        'parent' => 'snapshot',
        'id' => 'snapshot-support',
        'title' => 'Snapshot support',
        'href' => function_exists('snap_system_setting') ? (esc_url((string) snap_system_setting('support_url')) ?: 'https://snapshot.agency/') : 'https://snapshot.agency/',
    ]);

    if (current_user_can('manage_options')) {
        $bar->add_node([
            'parent' => 'snapshot',
            'id' => 'snapshot-system',
            'title' => 'System status and tools',
            'href' => admin_url('admin.php?page='.Panel::SLUG),
        ]);
    }

    $bar->add_node([
        'parent' => 'snapshot',
        'id' => 'snapshot-about-wordpress',
        'title' => 'About WordPress',
        'href' => self_admin_url('about.php'),
    ]);
}, 11);

<?php

use Snapshot\Security\Admin\AdminPage;
use Snapshot\System\Panel;

function snap_admin_menu_items(string $group): array
{
    $items = [];
    $seenTaxonomies = [];

    foreach (snap_admin_menu_group_types($group) as $type) {
        $object = get_post_type_object($type);

        if (! $object) {
            continue;
        }

        $config = snap_post_type_config($type);
        $slug = "edit.php?post_type={$type}";

        $items[] = [
            'title' => $config['menu_label'] ?? snap_sentence_case($config['plural'] ?? $object->labels->name),
            'cap' => $object->cap->edit_posts,
            'slug' => $slug,
            'class' => '',
            'screens' => [$type],
        ];

        foreach (snap_taxonomies_for($type) as $taxonomy) {
            $taxonomyObject = get_taxonomy($taxonomy);

            if (! $taxonomyObject || ! $taxonomyObject->show_ui) {
                continue;
            }

            if (isset($seenTaxonomies[$taxonomy])) {
                $items[$seenTaxonomies[$taxonomy]]['screens'][] = "{$taxonomy}|{$type}";

                continue;
            }

            $seenTaxonomies[$taxonomy] = count($items);

            $items[] = [
                'title' => snap_sentence_case(snap_taxonomies()[$taxonomy]['plural'] ?? $taxonomyObject->labels->name),
                'cap' => $taxonomyObject->cap->manage_terms,
                'slug' => "edit-tags.php?taxonomy={$taxonomy}&post_type={$type}",
                'class' => 'snap-menu-term',
                'screens' => ["{$taxonomy}|{$type}", "{$taxonomy}|"],
            ];
        }
    }

    return $items;
}

function snap_admin_menu_tree(): array
{
    static $trees = [];

    $user = get_current_user_id();

    if (isset($trees[$user])) {
        return $trees[$user];
    }

    $tree = ['groups' => [], 'screens' => []];

    foreach (snap_admin_menu_groups() as $group => $config) {
        $items = array_values(array_filter(
            snap_admin_menu_items($group),
            fn (array $item) => current_user_can($item['cap'])
        ));

        if (! $items) {
            continue;
        }

        $parent = $items[0]['slug'];

        $tree['groups'][$group] = [
            'label' => $config['label'],
            'icon' => $config['icon'] ?? 'dashicons-admin-post',
            'parent' => $parent,
            'items' => $items,
        ];

        foreach ($items as $item) {
            foreach ($item['screens'] as $screen) {
                $tree['screens'][$screen] ??= ['parent' => $parent, 'slug' => $item['slug']];
            }
        }
    }

    return $trees[$user] = $tree;
}

function snap_admin_menu_current(): ?array
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;

    if (! $screen) {
        return null;
    }

    $screens = snap_admin_menu_tree()['screens'];

    if ($screen->taxonomy && in_array($screen->base, ['edit-tags', 'term'], true)) {
        return $screens["{$screen->taxonomy}|{$screen->post_type}"] ?? $screens["{$screen->taxonomy}|"] ?? null;
    }

    if ($screen->post_type && in_array($screen->base, ['edit', 'post'], true)) {
        return $screens[$screen->post_type] ?? null;
    }

    return null;
}

function snap_comments_disabled(): bool
{
    if (get_option('default_comment_status') !== 'closed') {
        return false;
    }

    return (int) wp_count_comments()->total_comments === 0;
}

function snap_admin_menu_order(array $order): array
{
    $groups = array_column(snap_admin_menu_tree()['groups'], 'parent');

    $otherTypes = array_values(array_filter(
        $order,
        fn (string $slug) => str_starts_with($slug, 'edit.php?post_type=')
            && $slug !== 'edit.php?post_type=page'
            && $slug !== 'edit.php?post_type=acf-field-group'
            && ! in_array($slug, $groups, true)
    ));

    $wanted = snap_filter('snap/admin_menu_order', [
        'index.php',
        'separator1',
        'edit.php?post_type=page',
        'edit.php',
        'upload.php',
        'edit-comments.php',
        'separator2',
        ...$groups,
        ...$otherTypes,
        'separator-last',
        'site-options',
        'themes.php',
        'plugins.php',
        'users.php',
        'profile.php',
        'separator-snap-system',
        'tools.php',
        'options-general.php',
        Panel::SLUG,
        AdminPage::SLUG,
        Snapshot\Seo\Admin\AdminPage::SLUG,
        'edit.php?post_type=acf-field-group',
    ]);

    $known = array_values(array_intersect(array_unique($wanted), $order));

    return snap_admin_menu_drop_empty_sections(array_values(array_unique([...$known, ...array_diff($order, $known)])));
}

function snap_admin_menu_sections(): array
{
    return snap_filter('snap/admin_menu_sections', [
        'content' => ['label' => 'Content', 'separator' => 'separator1'],
        'library' => ['label' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) ?: 'Library', 'separator' => 'separator2'],
        'site' => ['label' => 'Site', 'separator' => 'separator-last'],
        'system' => ['label' => 'System', 'separator' => 'separator-snap-system'],
    ]);
}

function snap_admin_menu_drop_empty_sections(array $order): array
{
    $separators = array_column(snap_admin_menu_sections(), 'separator');
    $filled = [];
    $current = null;

    foreach ($order as $slug) {
        if (in_array($slug, $separators, true)) {
            $current = $slug;
            $filled[$slug] = false;
        } elseif ($current !== null && ! str_starts_with($slug, 'separator')) {
            $filled[$current] = true;
        }
    }

    $empty = array_keys(array_filter($filled, fn (bool $hasItems) => ! $hasItems));

    return [...array_values(array_diff($order, $empty)), ...$empty];
}

function snap_admin_menu_section_classes(array $menu): array
{
    $sections = [];

    foreach (snap_admin_menu_sections() as $key => $section) {
        if (trim((string) ($section['label'] ?? '')) !== '' && ! empty($section['separator'])) {
            $sections[$section['separator']] = sanitize_html_class((string) $key);
        }
    }

    foreach ($menu as $position => $item) {
        $key = $sections[$item[2] ?? ''] ?? '';

        if ($key !== '' && str_contains($item[4] ?? '', 'wp-menu-separator')) {
            $menu[$position][4] = trim(($item[4] ?? '').' snap-menu-section snap-menu-section-'.$key);
        }
    }

    return $menu;
}

function snap_css_string(string $text): string
{
    return '"'.preg_replace_callback(
        '/[^A-Za-z0-9 ]/u',
        fn (array $match) => sprintf('\\%X ', mb_ord($match[0], 'UTF-8')),
        $text
    ).'"';
}

function snap_admin_menu_section_css(): string
{
    $rules = [];

    foreach (snap_admin_menu_sections() as $key => $section) {
        $label = trim((string) ($section['label'] ?? ''));

        if ($label !== '') {
            $rules[] = sprintf(
                '#adminmenu li.snap-menu-section-%s div.separator::before{content:%s}',
                sanitize_html_class((string) $key),
                snap_css_string($label)
            );
        }
    }

    return implode("\n", $rules);
}

add_filter('post_type_labels_post', function ($labels) {
    $labels->menu_name = 'News';
    $labels->all_items = 'All news';
    $labels->add_new = 'Add news';
    $labels->name_admin_bar = 'News';

    return $labels;
});

add_action('admin_menu', function () {
    global $submenu;

    foreach (snap_admin_menu_groups() as $group => $config) {
        $types = snap_admin_menu_group_types($group);

        if ($types) {
            unset($submenu["edit.php?post_type={$types[0]}"]);
        }
    }

    foreach (snap_admin_menu_tree()['groups'] as $group) {
        $first = $group['items'][0];

        add_menu_page($group['label'], $group['label'], $first['cap'], $group['parent'], '', $group['icon']);

        foreach ($group['items'] as $item) {
            add_submenu_page($group['parent'], $item['title'], $item['title'], $item['cap'], $item['slug']);

            if ($item['class'] !== '' && ! empty($submenu[$group['parent']])) {
                $last = array_key_last($submenu[$group['parent']]);
                $submenu[$group['parent']][$last][4] = $item['class'];
            }
        }
    }

    if (snap_comments_disabled()) {
        remove_menu_page('edit-comments.php');
    }
}, 20);

add_action('admin_menu', function () {
    global $submenu;

    if (($submenu['site-options'][0][2] ?? '') === 'site-options') {
        $submenu['site-options'][0][0] = 'General';
    }
}, 100);

add_action('admin_menu', function () {
    global $menu;

    $menu[] = ['', 'read', 'separator-snap-system', '', 'wp-menu-separator'];
}, 20);

add_filter('custom_menu_order', '__return_true', 999);
add_filter('menu_order', fn ($order) => is_network_admin() || is_user_admin() ? $order : snap_admin_menu_order($order), 999);

add_filter('add_menu_classes', fn ($menu) => is_network_admin() || is_user_admin() ? $menu : snap_admin_menu_section_classes($menu), 999);

add_filter('parent_file', function ($parentFile) {
    return snap_admin_menu_current()['parent'] ?? $parentFile;
}, 20);

add_filter('submenu_file', function ($submenuFile, $parentFile) {
    if ($parentFile === Panel::SLUG && ! empty($_GET['tab'])) {
        return Panel::SLUG.'&tab='.sanitize_key((string) $_GET['tab']);
    }

    return snap_admin_menu_current()['slug'] ?? $submenuFile;
}, 20, 2);

add_filter('acf/settings/show_admin', function ($show) {
    return $show && current_user_can('manage_options');
});

add_action('admin_bar_menu', function ($bar) {
    if (snap_comments_disabled()) {
        $bar->remove_node('comments');
    }
}, 999);

add_action('admin_head', function () {
    echo '<style>#adminmenu .wp-submenu li.snap-menu-term a{padding-left:24px}</style>';
});

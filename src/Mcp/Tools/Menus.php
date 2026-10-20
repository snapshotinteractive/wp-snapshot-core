<?php

namespace Snapshot\Mcp\Tools;

use WP_Error;

class Menus
{
    public function list(array $input)
    {
        if (! current_user_can('edit_theme_options')) {
            return $this->denied();
        }

        $locations = get_nav_menu_locations();
        $registered = get_registered_nav_menus();
        $menus = [];

        foreach (wp_get_nav_menus() as $menu) {
            $menus[] = [
                'id' => $menu->term_id,
                'name' => $menu->name,
                'locations' => array_values(array_keys(array_filter($locations, fn ($id) => (int) $id === $menu->term_id))),
                'items' => array_map(fn ($item) => [
                    'id' => (int) $item->ID,
                    'title' => $item->title,
                    'url' => $item->url,
                    'type' => $item->type,
                    'object' => $item->object,
                    'object_id' => (int) $item->object_id,
                    'parent' => (int) $item->menu_item_parent,
                    'position' => (int) $item->menu_order,
                    'target' => $item->target,
                    'classes' => implode(' ', array_filter((array) $item->classes)),
                ], (array) wp_get_nav_menu_items($menu->term_id, ['post_status' => 'publish,draft'])),
            ];
        }

        return ['locations' => $registered, 'menus' => $menus];
    }

    public function saveItem(array $input)
    {
        if (! current_user_can('edit_theme_options')) {
            return $this->denied();
        }

        $menu = wp_get_nav_menu_object((int) $input['menu']);

        if (! $menu) {
            return new WP_Error('snapshot_mcp_menu', 'No menu with that ID. Use get-menus to list them.');
        }

        $itemId = (int) ($input['item_id'] ?? 0);
        $current = [];
        $stored = null;

        if ($itemId) {
            $stored = get_post($itemId);

            if (! $stored || $stored->post_type !== 'nav_menu_item' || ! is_nav_menu_item($itemId) || ! has_term($menu->term_id, 'nav_menu', $itemId)) {
                return new WP_Error('snapshot_mcp_menu_item', 'That item is not in this menu.');
            }

            $current = (array) wp_setup_nav_menu_item(clone $stored);
        }

        $args = [
            'menu-item-title' => isset($input['title']) ? (current_user_can('unfiltered_html') ? (string) $input['title'] : wp_kses_data((string) $input['title'])) : ($stored ? $stored->post_title : ''),
            'menu-item-parent-id' => (int) ($input['parent'] ?? ($current['menu_item_parent'] ?? 0)),
            'menu-item-position' => (int) ($input['position'] ?? ($current['menu_order'] ?? 0)),
            'menu-item-target' => array_key_exists('new_tab', $input) ? ($input['new_tab'] ? '_blank' : '') : ($current['target'] ?? ''),
            'menu-item-classes' => isset($input['classes']) ? implode(' ', array_map('sanitize_html_class', preg_split('/\s+/', (string) $input['classes'], -1, PREG_SPLIT_NO_EMPTY))) : implode(' ', array_filter((array) ($current['classes'] ?? []))),
            'menu-item-description' => $stored ? $stored->post_content : '',
            'menu-item-attr-title' => $stored ? $stored->post_excerpt : '',
            'menu-item-xfn' => $current['xfn'] ?? '',
            'menu-item-status' => $stored ? $stored->post_status : 'publish',
        ];

        if (! empty($input['object_id'])) {
            $objectId = (int) $input['object_id'];
            $isTerm = ($input['object_type'] ?? 'post') === 'term';
            $post = $isTerm ? null : get_post($objectId);
            $term = $isTerm ? get_term($objectId) : null;

            if ($post && is_post_type_viewable($post->post_type) && in_array($post->post_type, Content::types(), true)) {
                $args += ['menu-item-type' => 'post_type', 'menu-item-object' => $post->post_type, 'menu-item-object-id' => $objectId];
            } elseif ($term && ! is_wp_error($term)) {
                $args += ['menu-item-type' => 'taxonomy', 'menu-item-object' => $term->taxonomy, 'menu-item-object-id' => $objectId];
            } else {
                return new WP_Error('snapshot_mcp_menu_target', 'object_id must be the ID of an entry, or of a term with object_type "term".');
            }
        } elseif (! empty($input['url'])) {
            $args += ['menu-item-type' => 'custom', 'menu-item-url' => esc_url_raw((string) $input['url'])];
        } elseif ($current) {
            $args += [
                'menu-item-type' => $current['type'],
                'menu-item-object' => $current['object'],
                'menu-item-object-id' => (int) $current['object_id'],
                'menu-item-url' => $current['type'] === 'custom' ? $current['url'] : '',
            ];
        } else {
            return new WP_Error('snapshot_mcp_menu_target', 'A new item needs object_id (an entry or term) or url.');
        }

        $saved = Content::stored(fn () => wp_update_nav_menu_item($menu->term_id, $itemId, wp_slash($args)));

        if (is_wp_error($saved)) {
            return $saved;
        }

        do_action('wp_update_nav_menu', $menu->term_id);

        return ['saved' => (int) $saved, 'menu' => $menu->term_id];
    }

    public function removeItem(array $input)
    {
        if (! current_user_can('edit_theme_options')) {
            return $this->denied();
        }

        $itemId = (int) $input['item_id'];

        if (! is_nav_menu_item($itemId)) {
            return new WP_Error('snapshot_mcp_menu_item', 'No menu item with that ID.');
        }

        $menus = wp_get_object_terms($itemId, 'nav_menu', ['fields' => 'ids']);

        if (! wp_delete_post($itemId, true)) {
            return new WP_Error('snapshot_mcp_menu_item', 'The menu item could not be removed.');
        }

        foreach ((array) $menus as $menuId) {
            do_action('wp_update_nav_menu', (int) $menuId);
        }

        return ['removed' => $itemId];
    }

    private function denied(): WP_Error
    {
        return new WP_Error('snapshot_mcp_denied', 'This account cannot edit menus.');
    }
}

<?php

namespace Snapshot\Mcp\Tools;

use Snapshot\Core;
use Snapshot\Mcp\Fields;
use Snapshot\Mcp\Mcp;
use WP_Error;

class Site
{
    private const SETTINGS = [
        'title' => 'blogname',
        'tagline' => 'blogdescription',
        'show_on_front' => 'show_on_front',
        'page_on_front' => 'page_on_front',
        'page_for_posts' => 'page_for_posts',
    ];

    public function __construct(private Fields $fields) {}

    public function info(array $input)
    {
        $user = wp_get_current_user();
        $theme = wp_get_theme();
        $types = [];

        foreach (Content::types() as $type) {
            $object = get_post_type_object($type);
            $counts = (array) wp_count_posts($type);

            $types[] = [
                'name' => $type,
                'label' => $object->labels->name,
                'hierarchical' => (bool) $object->hierarchical,
                'public' => (bool) $object->public,
                'archive' => $object->has_archive ? (string) get_post_type_archive_link($type) : '',
                'taxonomies' => array_values(array_intersect(get_object_taxonomies($type), Terms::taxonomies())),
                'templates' => array_keys(wp_get_theme()->get_page_templates(null, $type)),
                'fields' => array_keys($this->fields->forPostType($type)),
                'counts' => array_intersect_key(array_map('intval', $counts), array_flip([...Content::STATUSES, 'trash'])),
            ];
        }

        return [
            'site' => [
                'name' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
                'tagline' => wp_specialchars_decode((string) get_bloginfo('description'), ENT_QUOTES),
                'url' => home_url('/'),
                'admin' => admin_url(),
                'language' => get_locale(),
                'timezone' => wp_timezone_string(),
                'front_page' => get_option('show_on_front') === 'page' ? (int) get_option('page_on_front') : 0,
                'posts_page' => (int) get_option('page_for_posts'),
            ],
            'software' => [
                'wordpress' => get_bloginfo('version'),
                'theme' => $theme->get('Name').' '.$theme->get('Version'),
                'snapshot_core' => Core::version(),
                'acf' => $this->fields->available(),
            ],
            'connection' => [
                'user' => ['id' => $user->ID, 'name' => $user->display_name, 'roles' => array_values($user->roles)],
                'access' => Mcp::access(),
                'access_means' => snap_mcp_access_levels()[Mcp::access()] ?? '',
            ],
            'content_types' => $types,
            'taxonomies' => array_map(fn ($taxonomy) => [
                'name' => $taxonomy,
                'label' => get_taxonomy($taxonomy)->labels->name,
                'hierarchical' => (bool) get_taxonomy($taxonomy)->hierarchical,
                'types' => array_values(array_intersect(get_taxonomy($taxonomy)->object_type, Content::types())),
            ], Terms::taxonomies()),
            'menus' => array_map(fn ($menu) => ['id' => $menu->term_id, 'name' => $menu->name], wp_get_nav_menus()),
            'options_pages' => array_values(array_map(fn ($page) => ['slug' => $page['slug'], 'title' => $page['title']], $this->fields->optionsPages())),
        ];
    }

    public function describe(array $input)
    {
        if (! $this->fields->available()) {
            return new WP_Error('snapshot_mcp_acf', 'Advanced Custom Fields is not active, so there are no custom fields.');
        }

        if (! empty($input['id'])) {
            $post = get_post((int) $input['id']);

            if (! $post || ! current_user_can('edit_post', $post->ID)) {
                return new WP_Error('snapshot_mcp_not_found', 'No entry with that ID that this account can edit.');
            }

            return ['for' => "entry {$post->ID}", 'fields' => $this->fields->describe($this->fields->forPost($post->ID))];
        }

        if (! empty($input['taxonomy'])) {
            return ['for' => 'taxonomy '.$input['taxonomy'], 'fields' => $this->fields->describe($this->fields->forTerm((string) $input['taxonomy']))];
        }

        if (! empty($input['options_page'])) {
            $page = $this->fields->optionsPages()[(string) $input['options_page']] ?? null;

            return $page ? ['for' => 'options page '.$page['slug'], 'fields' => $this->fields->describe($page['fields'])] : new WP_Error('snapshot_mcp_options', 'Unknown options page. site-info lists them.');
        }

        $type = (string) ($input['type'] ?? 'page');

        if (! in_array($type, Content::types(), true)) {
            return new WP_Error('snapshot_mcp_type', sprintf('Unknown content type "%s".', $type));
        }

        return ['for' => "content type {$type}", 'fields' => $this->fields->describe($this->fields->forPostType($type))];
    }

    public function options(array $input)
    {
        $pages = [];

        foreach ($this->fields->optionsPages() as $slug => $page) {
            if (current_user_can($page['capability'])) {
                $pages[$slug] = ['title' => $page['title'], 'values' => $this->fields->read($page['post_id'], $page['fields'])];
            }
        }

        $settings = [];

        if (current_user_can('manage_options')) {
            foreach (self::SETTINGS as $key => $option) {
                $settings[$key] = in_array($key, ['page_on_front', 'page_for_posts'], true) ? (int) get_option($option) : (string) get_option($option);
            }
        }

        return ['settings' => $settings, 'options_pages' => $pages];
    }

    public function updateOptions(array $input)
    {
        $page = $this->fields->optionsPages()[(string) $input['options_page']] ?? null;

        if (! $page) {
            return new WP_Error('snapshot_mcp_options', 'Unknown options page. site-info lists them.');
        }

        if (! current_user_can($page['capability'])) {
            return new WP_Error('snapshot_mcp_denied', 'This account cannot edit that options page.');
        }

        $written = $this->fields->write($page['post_id'], (array) $input['fields'], $page['fields']);

        if (is_wp_error($written)) {
            return $written;
        }

        return ['updated' => $written, 'values' => $this->fields->read($page['post_id'], array_intersect_key($page['fields'], array_flip($written)))];
    }

    public function updateSettings(array $input)
    {
        if (! current_user_can('manage_options')) {
            return new WP_Error('snapshot_mcp_denied', 'This account cannot change site settings.');
        }

        $changed = [];

        foreach (self::SETTINGS as $key => $option) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];

            if (in_array($key, ['page_on_front', 'page_for_posts'], true)) {
                $value = (int) $value;

                if ($value && get_post_type($value) !== 'page') {
                    return new WP_Error('snapshot_mcp_setting', sprintf('%s must be the ID of a page.', $key));
                }
            } else {
                $value = sanitize_option($option, (string) $value);
            }

            update_option($option, $value);
            $changed[] = $key;
        }

        return ['updated' => $changed] + $this->options([])['settings'];
    }

    public function users(array $input)
    {
        if (! current_user_can('list_users')) {
            return new WP_Error('snapshot_mcp_denied', 'This account cannot list users.');
        }

        $users = get_users([
            'role__in' => (array) ($input['roles'] ?? []),
            'search' => ! empty($input['search']) ? '*'.$input['search'].'*' : '',
            'number' => max(1, min(100, (int) ($input['per_page'] ?? 50))),
            'orderby' => 'display_name',
        ]);

        return ['items' => array_map(fn ($user) => [
            'id' => $user->ID,
            'login' => $user->user_login,
            'name' => $user->display_name,
            'roles' => array_values($user->roles),
            'registered' => mysql_to_rfc3339($user->user_registered),
        ], $users)];
    }
}

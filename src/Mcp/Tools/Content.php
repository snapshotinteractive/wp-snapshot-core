<?php

namespace Snapshot\Mcp\Tools;

use Snapshot\Mcp\Fields;
use Snapshot\Mcp\Mcp;
use WP_Error;
use WP_Post;
use WP_Query;

class Content
{
    public const STATUSES = ['publish', 'draft', 'pending', 'future', 'private'];

    public function __construct(private Fields $fields) {}

    public static function types(): array
    {
        $types = array_diff(get_post_types(['show_ui' => true]), snap_mcp_excluded_post_types());

        return array_values(snap_filter('snap/mcp/post_types', $types));
    }

    public static function summary(WP_Post $post): array
    {
        $author = get_userdata((int) $post->post_author);

        return [
            'id' => $post->ID,
            'type' => $post->post_type,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'slug' => $post->post_name,
            'parent' => $post->post_parent,
            'link' => (string) get_permalink($post),
            'modified' => mysql_to_rfc3339($post->post_modified_gmt !== '0000-00-00 00:00:00' ? $post->post_modified_gmt : $post->post_modified),
            'author' => $author ? $author->display_name : '',
        ];
    }

    public static function stored(callable $write)
    {
        $filtered = has_filter('content_save_pre', 'wp_filter_post_kses') !== false;

        if ($filtered) {
            kses_remove_filters();
        }

        try {
            return $write();
        } finally {
            if ($filtered) {
                kses_init_filters();
            }
        }
    }

    public function list(array $input)
    {
        $type = (string) ($input['type'] ?? 'page');
        $types = self::types();

        if ($type !== 'any' && ! in_array($type, $types, true)) {
            return $this->unknownType($type);
        }

        $status = (array) ($input['status'] ?? ['publish', 'draft', 'pending', 'future', 'private']);
        $args = [
            'post_type' => $type === 'any' ? $types : $type,
            'post_status' => $status,
            'posts_per_page' => max(1, min(100, (int) ($input['per_page'] ?? 20))),
            'paged' => max(1, (int) ($input['page'] ?? 1)),
            'orderby' => $input['orderby'] ?? 'modified',
            'order' => strtoupper((string) ($input['order'] ?? 'desc')) === 'ASC' ? 'ASC' : 'DESC',
            's' => (string) ($input['search'] ?? ''),
            'ignore_sticky_posts' => true,
            'no_found_rows' => false,
            'suppress_filters' => false,
        ];

        if (isset($input['parent'])) {
            $args['post_parent'] = (int) $input['parent'];
        }

        if (! empty($input['taxonomy']) && ! empty($input['term'])) {
            $args['tax_query'] = [[
                'taxonomy' => (string) $input['taxonomy'],
                'field' => is_numeric($input['term']) ? 'term_id' : 'slug',
                'terms' => $input['term'],
            ]];
        }

        $query = new WP_Query($args);
        $items = array_values(array_map([self::class, 'summary'], array_filter($query->posts, fn ($post) => current_user_can('read_post', $post->ID))));

        return [
            'items' => $items,
            'total' => (int) $query->found_posts,
            'pages' => (int) $query->max_num_pages,
            'page' => $args['paged'],
        ];
    }

    public function get(array $input)
    {
        $post = $this->find($input);

        if (is_wp_error($post)) {
            return $post;
        }

        if (! current_user_can('read_post', $post->ID)) {
            return $this->denied();
        }

        $terms = [];

        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            $found = get_the_terms($post, $taxonomy);
            $terms[$taxonomy] = is_array($found) ? array_map(fn ($term) => ['id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug], $found) : [];
        }

        $thumbnail = (int) get_post_thumbnail_id($post);

        return self::summary($post) + [
            'date' => mysql_to_rfc3339($post->post_date),
            'menu_order' => $post->menu_order,
            'template' => (string) get_page_template_slug($post),
            'excerpt' => $post->post_excerpt,
            'content' => $post->post_content,
            'featured_image' => $thumbnail ? Media::summary($thumbnail) : null,
            'terms' => $terms,
            'fields' => $this->fields->read($post->ID, $this->fields->forPost($post->ID)),
            'password_protected' => $post->post_password !== '',
            'revisions' => count(wp_get_post_revisions($post->ID, ['fields' => 'ids'])),
            'edit_link' => (string) get_edit_post_link($post->ID, 'raw'),
        ];
    }

    public function create(array $input)
    {
        $type = (string) ($input['type'] ?? 'page');

        if (! in_array($type, self::types(), true)) {
            return $this->unknownType($type);
        }

        $object = get_post_type_object($type);

        if (! $object || ! current_user_can($object->cap->create_posts)) {
            return $this->denied();
        }

        $data = $this->postData($input, null);

        if (is_wp_error($data)) {
            return $data;
        }

        $checked = $this->checkExtras($type, $input, $this->fields->forPostType($type));

        if (is_wp_error($checked)) {
            return $checked;
        }

        $id = self::stored(fn () => wp_insert_post(wp_slash($data + ['post_type' => $type, 'post_status' => 'draft', 'post_title' => '']), true));

        if (is_wp_error($id)) {
            return $id;
        }

        $applied = $this->applyExtras($id, $input);

        if (is_wp_error($applied)) {
            wp_delete_post($id, true);

            return $applied;
        }

        $this->snapshot($id);

        return ['created' => true] + $this->get(['id' => $id]);
    }

    public function update(array $input)
    {
        $post = $this->find($input);

        if (is_wp_error($post)) {
            return $post;
        }

        if (! current_user_can('edit_post', $post->ID)) {
            return $this->denied();
        }

        if ($post->post_status === 'trash') {
            return new WP_Error('snapshot_mcp_trashed', 'This entry is in the bin. Restore it with set-status first.');
        }

        $data = $this->postData($input, $post);

        if (is_wp_error($data)) {
            return $data;
        }

        $checked = $this->checkExtras($post->post_type, $input, $this->fields->forPost($post->ID));

        if (is_wp_error($checked)) {
            return $checked;
        }

        $this->snapshot($post->ID);

        if ($data !== []) {
            $result = self::stored(fn () => wp_update_post(wp_slash($data + ['ID' => $post->ID]), true));

            if (is_wp_error($result)) {
                return $result;
            }
        }

        $applied = $this->applyExtras($post->ID, $input);

        if (is_wp_error($applied)) {
            return $applied;
        }

        $this->snapshot($post->ID);

        return ['updated' => true] + $this->get(['id' => $post->ID]);
    }

    public function setStatus(array $input)
    {
        $post = $this->find($input);

        if (is_wp_error($post)) {
            return $post;
        }

        $status = (string) $input['status'];
        $object = get_post_type_object($post->post_type);

        if (! current_user_can('edit_post', $post->ID) || ! $object || (in_array($status, ['publish', 'future', 'private'], true) && ! current_user_can($object->cap->publish_posts))) {
            return $this->denied();
        }

        if ($post->post_status === 'trash') {
            self::stored(fn () => wp_untrash_post($post->ID));
            $post = get_post($post->ID);
        }

        $data = ['ID' => $post->ID, 'post_status' => $status];

        if ($status === 'future') {
            $date = date_create_immutable((string) ($input['date'] ?? ''), wp_timezone());

            if (! $date || $date->getTimestamp() <= time()) {
                return new WP_Error('snapshot_mcp_date', 'Scheduling needs "date" in the future, for example 2026-12-01T09:00:00 in the site time zone.');
            }

            $data['post_date'] = $date->setTimezone(wp_timezone())->format('Y-m-d H:i:s');
            $data['post_date_gmt'] = gmdate('Y-m-d H:i:s', $date->getTimestamp());
            $data['edit_date'] = true;
        }

        $result = self::stored(fn () => wp_update_post(wp_slash($data), true));

        if (is_wp_error($result)) {
            return $result;
        }

        return self::summary(get_post($post->ID));
    }

    public function trash(array $input)
    {
        $post = $this->find($input);

        if (is_wp_error($post)) {
            return $post;
        }

        if (! current_user_can('delete_post', $post->ID)) {
            return $this->denied();
        }

        if (! wp_trash_post($post->ID)) {
            return new WP_Error('snapshot_mcp_trash', 'The entry could not be moved to the bin.');
        }

        return ['trashed' => true] + self::summary(get_post($post->ID));
    }

    public function revisions(array $input)
    {
        $post = $this->find($input);

        if (is_wp_error($post)) {
            return $post;
        }

        if (! current_user_can('edit_post', $post->ID)) {
            return $this->denied();
        }

        $revisions = array_values(array_map(function ($revision) {
            $author = get_userdata((int) $revision->post_author);

            return [
                'id' => $revision->ID,
                'date' => mysql_to_rfc3339($revision->post_date),
                'author' => $author ? $author->display_name : '',
                'title' => $revision->post_title,
                'autosave' => wp_is_post_autosave($revision) !== false,
            ];
        }, wp_get_post_revisions($post->ID, ['posts_per_page' => max(1, min(50, (int) ($input['per_page'] ?? 20)))])));

        return ['id' => $post->ID, 'revisions' => $revisions];
    }

    public function restore(array $input)
    {
        $revisionId = (int) $input['revision_id'];
        $revision = wp_get_post_revision($revisionId);

        if (! $revision) {
            return new WP_Error('snapshot_mcp_not_found', 'No revision with that ID.');
        }

        $post = get_post((int) $revision->post_parent);

        if (! $post || ! in_array($post->post_type, self::types(), true) || ! current_user_can('edit_post', $post->ID)) {
            return $this->denied();
        }

        $this->snapshot($post->ID, true);

        if (! self::stored(fn () => wp_restore_post_revision($revision->ID))) {
            return new WP_Error('snapshot_mcp_restore', 'The revision could not be restored.');
        }

        return ['restored' => $revision->ID] + self::summary(get_post($post->ID));
    }

    public function find(array $input)
    {
        if (! empty($input['id'])) {
            $post = get_post((int) $input['id']);
        } elseif (! empty($input['slug'])) {
            $found = get_posts([
                'name' => sanitize_title((string) $input['slug']),
                'post_type' => (string) ($input['type'] ?? 'page'),
                'post_status' => ['publish', 'draft', 'pending', 'future', 'private', 'trash'],
                'posts_per_page' => 1,
            ]);
            $post = $found[0] ?? null;
        } else {
            return new WP_Error('snapshot_mcp_missing_id', 'Give "id", or "slug" with "type".');
        }

        if (! $post instanceof WP_Post || ! in_array($post->post_type, self::types(), true)) {
            return new WP_Error('snapshot_mcp_not_found', 'No entry found. Use list-content to find its ID.');
        }

        return $post;
    }

    private function postData(array $input, ?WP_Post $post)
    {
        $data = [];
        $trusted = current_user_can('unfiltered_html');
        $map = [
            'title' => ['post_title', 'wp_kses_data'],
            'content' => ['post_content', 'wp_kses_post'],
            'excerpt' => ['post_excerpt', 'wp_kses_post'],
            'menu_order' => ['menu_order', 'intval'],
        ];

        foreach ($map as $key => [$column, $clean]) {
            if (array_key_exists($key, $input)) {
                $data[$column] = $trusted && $clean !== 'intval' ? $input[$key] : $clean($input[$key]);
            }
        }

        $moving = array_key_exists('slug', $input) || array_key_exists('parent', $input);

        if ($moving && $post && in_array($post->post_status, ['publish', 'future', 'private'], true) && ! Mcp::allows('publish')) {
            return new WP_Error('snapshot_mcp_address', 'Changing the slug or parent of published content changes its address, which needs the publish access level.');
        }

        if (array_key_exists('slug', $input)) {
            $data['post_name'] = sanitize_title((string) $input['slug']);
        }

        if (array_key_exists('parent', $input)) {
            $parent = (int) $input['parent'];

            if ($parent && (! get_post($parent) || ($post && $parent === $post->ID))) {
                return new WP_Error('snapshot_mcp_parent', 'The parent entry does not exist.');
            }

            $data['post_parent'] = $parent;
        }

        if (array_key_exists('template', $input)) {
            $data['page_template'] = (string) $input['template'];
        }

        return $data;
    }

    private function checkExtras(string $type, array $input, array $fields)
    {
        $image = (int) ($input['featured_image'] ?? 0);

        if ($image && ! wp_attachment_is_image($image)) {
            return new WP_Error('snapshot_mcp_image', 'featured_image must be the ID of an image in the media library.');
        }

        foreach ((array) ($input['terms'] ?? []) as $taxonomy => $terms) {
            if (! is_object_in_taxonomy($type, (string) $taxonomy)) {
                return new WP_Error('snapshot_mcp_taxonomy', sprintf('"%s" is not a taxonomy of this content type.', $taxonomy));
            }

            if (! current_user_can(get_taxonomy((string) $taxonomy)->cap->assign_terms)) {
                return $this->denied();
            }

            $ids = Terms::resolve((string) $taxonomy, (array) $terms);

            if (is_wp_error($ids)) {
                return $ids;
            }
        }

        if (empty($input['fields'])) {
            return true;
        }

        if (! $this->fields->available()) {
            return new WP_Error('snapshot_mcp_acf', 'Advanced Custom Fields is not active, so fields cannot be written.');
        }

        $prepared = $this->fields->prepare((array) $input['fields'], $fields);

        return is_wp_error($prepared) ? $prepared : true;
    }

    private function applyExtras(int $id, array $input)
    {
        if (array_key_exists('featured_image', $input)) {
            (int) $input['featured_image'] ? set_post_thumbnail($id, (int) $input['featured_image']) : delete_post_thumbnail($id);
        }

        foreach ((array) ($input['terms'] ?? []) as $taxonomy => $terms) {
            $set = wp_set_object_terms($id, Terms::resolve((string) $taxonomy, (array) $terms), (string) $taxonomy);

            if (is_wp_error($set)) {
                return $set;
            }
        }

        if (! empty($input['fields'])) {
            $written = $this->fields->write($id, (array) $input['fields'], $this->fields->forPost($id));

            if (is_wp_error($written)) {
                return $written;
            }
        }

        return true;
    }

    private function snapshot(int $id, bool $always = false): void
    {
        $post = get_post($id);

        if (! $post || ! wp_revisions_enabled($post)) {
            return;
        }

        $latest = current(wp_get_post_revisions($id, ['posts_per_page' => 1])) ?: null;

        if (! $always && $latest && ! $this->differs($post, $latest)) {
            return;
        }

        add_filter('wp_save_post_revision_check_for_changes', '__return_false');
        $revisionId = self::stored(fn () => wp_save_post_revision($id));
        remove_filter('wp_save_post_revision_check_for_changes', '__return_false');

        if ($revisionId && ! is_wp_error($revisionId) && function_exists('acf_copy_postmeta')) {
            acf_copy_postmeta($id, $revisionId);
        }
    }

    private function differs(WP_Post $post, WP_Post $revision): bool
    {
        foreach (['post_title', 'post_content', 'post_excerpt'] as $column) {
            if ($post->{$column} !== $revision->{$column}) {
                return true;
            }
        }

        return function_exists('acf_get_meta') && acf_get_meta($post->ID) != acf_get_meta($revision->ID);
    }

    private function unknownType(string $type): WP_Error
    {
        return new WP_Error('snapshot_mcp_type', sprintf('Unknown content type "%s". Known types: %s.', $type, implode(', ', self::types())));
    }

    private function denied(): WP_Error
    {
        return new WP_Error('snapshot_mcp_denied', 'This account is not allowed to do that to this entry.');
    }
}

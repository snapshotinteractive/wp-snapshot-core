<?php

namespace Snapshot\Security;

use WP_Post;

class Activity
{
    private const SKIP_TYPES = ['revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'acf-field', 'acf-field-group', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page', 'custom_css'];

    private array $once = [];

    public function __construct(private EventLog $log) {}

    public function boot(): void
    {
        add_action('transition_post_status', [$this, 'status'], 10, 3);
        add_action('post_updated', [$this, 'updated'], 10, 3);
        add_action('acf/save_post', [$this, 'fields'], 20);

        add_action('add_attachment', fn ($id) => $this->media('media.uploaded', (int) $id));
        add_action('attachment_updated', fn ($id) => $this->media('media.updated', (int) $id));
        add_action('delete_attachment', fn ($id) => $this->media('media.deleted', (int) $id));

        add_action('created_term', fn ($id, $tt, $taxonomy) => $this->term('term.created', (int) $id, (string) $taxonomy), 10, 3);
        add_action('edited_term', fn ($id, $tt, $taxonomy) => $this->term('term.updated', (int) $id, (string) $taxonomy), 10, 3);
        add_action('pre_delete_term', fn ($id, $taxonomy) => $this->term('term.deleted', (int) $id, (string) $taxonomy), 10, 2);

        add_action('wp_update_nav_menu', [$this, 'menu']);
        add_action('wp_delete_nav_menu', fn ($id) => $this->log->log('menu.deleted', sprintf('Deleted menu %d', (int) $id), ['object' => 'menu', 'id' => (int) $id]));
        add_action('update_option_theme_mods_'.get_option('stylesheet'), [$this, 'menuLocations'], 10, 2);

        add_action('transition_comment_status', fn ($new, $old, $comment) => $this->log->log('comment.status', sprintf('Comment %d on "%s" %s, was %s', $comment->comment_ID, get_the_title((int) $comment->comment_post_ID), $new, $old), ['object' => 'comment', 'id' => (int) $comment->comment_ID, 'post' => (int) $comment->comment_post_ID]), 10, 3);
        add_action('edit_comment', fn ($id) => $this->log->log('comment.updated', sprintf('Edited comment %d', (int) $id), ['object' => 'comment', 'id' => (int) $id]));
        add_action('delete_comment', fn ($id) => $this->log->log('comment.deleted', sprintf('Deleted comment %d', (int) $id), ['object' => 'comment', 'id' => (int) $id]));

        add_action('profile_update', [$this, 'profile'], 20, 2);
        add_action('updated_option', [$this, 'option'], 20, 3);
        add_action('customize_save_after', fn () => $this->log->log('theme.customized', 'Published Customizer changes'));
        add_action('update_option_sidebars_widgets', fn () => $this->once('widgets', fn () => $this->log->log('theme.widgets', 'Changed widgets')));
        add_action('_core_updated_successfully', fn ($version) => $this->log->log('software.core_updated', "WordPress updated to {$version}"));
        add_action('export_wp', fn ($args) => $this->log->log('data.exported', sprintf('Exported %s', $args['content'] ?? 'all content'), ['args' => $args]));
        add_action('import_end', fn () => $this->log->log('data.imported', 'Imported content'));
        add_action('wp_privacy_personal_data_export_file_created', fn ($path, $url, $html, $requestId) => $this->log->log('data.personal_export', sprintf('Personal data export for request %d', (int) $requestId), ['request' => (int) $requestId]), 10, 4);
        add_action('wp_privacy_personal_data_erased', fn ($requestId) => $this->log->log('data.personal_erased', sprintf('Personal data erased for request %d', (int) $requestId), ['request' => (int) $requestId]));
    }

    public function status(string $new, string $old, WP_Post $post): void
    {
        if (! $this->tracked($post) || $new === $old) {
            return;
        }

        $event = match (true) {
            $old === 'new' || $old === 'auto-draft' => $new === 'publish' ? 'content.published' : 'content.created',
            $new === 'publish' => 'content.published',
            $new === 'future' => 'content.scheduled',
            $new === 'trash' => 'content.trashed',
            $old === 'trash' => 'content.restored',
            $old === 'publish' => 'content.unpublished',
            default => 'content.updated',
        };

        $this->once("status:{$post->ID}", fn () => $this->log->log($event, sprintf('%s %s "%s"', $this->verb($event), $this->typeLabel($post->post_type), $this->title($post)), [
            'object' => 'post',
            'id' => $post->ID,
            'type' => $post->post_type,
            'from' => $old,
            'to' => $new,
        ]));
    }

    public function updated(int $postId, WP_Post $after, WP_Post $before): void
    {
        if (! $this->tracked($after) || $after->post_status !== $before->post_status || in_array($after->post_status, ['auto-draft', 'inherit'], true)) {
            return;
        }

        $changed = [];

        foreach (['post_title' => 'title', 'post_content' => 'content', 'post_excerpt' => 'excerpt', 'post_name' => 'slug', 'post_author' => 'author', 'post_parent' => 'parent', 'menu_order' => 'order', 'post_date' => 'date', 'post_password' => 'password'] as $field => $label) {
            if ((string) $after->{$field} !== (string) $before->{$field}) {
                $changed[] = $label;
            }
        }

        $context = ['object' => 'post', 'id' => $postId, 'type' => $after->post_type, 'changed' => $changed];

        if (in_array('title', $changed, true)) {
            $context['old_title'] = $before->post_title;
        }

        if (in_array('slug', $changed, true)) {
            $context['old_slug'] = $before->post_name;
        }

        $this->once("updated:{$postId}", fn () => $this->log->log('content.updated', sprintf('Updated %s "%s"%s', $this->typeLabel($after->post_type), $this->title($after), $changed ? ': '.implode(', ', $changed) : ''), $context));
    }

    public function fields($postId): void
    {
        if ($postId === 'options' || $postId === 'option') {
            $page = sanitize_key((string) ($_GET['page'] ?? ''));
            $this->log->log('option.site_options', sprintf('Saved %s', $page !== '' ? $page : 'Site Options'), ['object' => 'options_page', 'page' => $page, 'fields' => $this->submittedFields()]);

            return;
        }

        if (! is_numeric($postId) || ! ($post = get_post((int) $postId)) || ! $this->tracked($post) || isset($this->once["updated:{$post->ID}"]) || isset($this->once["status:{$post->ID}"])) {
            return;
        }

        $fields = $this->submittedFields();

        if ($fields === []) {
            return;
        }

        $this->once("fields:{$post->ID}", fn () => $this->log->log('content.fields_updated', sprintf('Saved fields on %s "%s"', $this->typeLabel($post->post_type), $this->title($post)), ['object' => 'post', 'id' => $post->ID, 'type' => $post->post_type, 'fields' => $fields]));
    }

    public function media(string $event, int $id): void
    {
        $post = get_post($id);

        if (! $post) {
            return;
        }

        $verb = ['media.uploaded' => 'Uploaded', 'media.updated' => 'Updated', 'media.deleted' => 'Deleted'][$event];
        $this->log->log($event, sprintf('%s "%s"', $verb, $this->title($post) ?: basename((string) get_attached_file($id))), ['object' => 'attachment', 'id' => $id, 'mime' => $post->post_mime_type]);
    }

    public function term(string $event, int $id, string $taxonomy): void
    {
        if (in_array($taxonomy, ['nav_menu', 'post_format', 'wp_theme', 'wp_template_part_area'], true)) {
            return;
        }

        $term = get_term($id, $taxonomy);
        $name = $term && ! is_wp_error($term) ? $term->name : (string) $id;
        $label = get_taxonomy($taxonomy)?->labels->singular_name ?? $taxonomy;
        $verb = ['term.created' => 'Created', 'term.updated' => 'Updated', 'term.deleted' => 'Deleted'][$event];

        $this->log->log($event, sprintf('%s %s "%s"', $verb, strtolower((string) $label), $name), ['object' => 'term', 'id' => $id, 'taxonomy' => $taxonomy]);
    }

    public function menu(int $menuId): void
    {
        $menu = wp_get_nav_menu_object($menuId);

        $this->once("menu:{$menuId}", fn () => $this->log->log('menu.updated', sprintf('Updated menu "%s"', $menu ? $menu->name : $menuId), ['object' => 'menu', 'id' => $menuId]));
    }

    public function menuLocations($old, $new): void
    {
        $before = (array) ($old['nav_menu_locations'] ?? []);
        $after = (array) ($new['nav_menu_locations'] ?? []);

        if ($before != $after) {
            $this->log->log('menu.locations', 'Changed menu locations', ['object' => 'menu', 'from' => $before, 'to' => $after]);
        }
    }

    public function profile(int $userId, $old): void
    {
        $user = get_userdata($userId);

        if (! $user || ! $old instanceof \WP_User) {
            return;
        }

        $changed = [];

        foreach (['display_name' => 'display name', 'user_url' => 'website', 'user_nicename' => 'nicename', 'first_name' => 'first name', 'last_name' => 'last name'] as $field => $label) {
            if ((string) $user->{$field} !== (string) $old->{$field}) {
                $changed[] = $label;
            }
        }

        $this->once("profile:{$userId}", fn () => $this->log->log('user.updated', sprintf('Updated profile of %s%s', $user->user_login, $changed ? ': '.implode(', ', $changed) : ''), ['object' => 'user', 'id' => $userId, 'target' => $userId, 'changed' => $changed]));
    }

    public function option(string $option, $old, $new): void
    {
        if (! is_admin() || wp_doing_cron() || wp_doing_ajax() || ! get_current_user_id() || in_array($option, snap_security_watched_options(), true)) {
            return;
        }

        foreach (snap_system_noisy_options() as $noisy) {
            if ($option === $noisy || (str_ends_with($noisy, '_') && str_starts_with($option, $noisy)) || (str_starts_with($noisy, '_') && str_ends_with($option, $noisy))) {
                return;
            }
        }

        $context = ['object' => 'option', 'option' => $option];

        if (is_array($old) || is_array($new) || is_object($old) || is_object($new)) {
            $before = (array) $old;
            $after = (array) $new;
            $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
            $context['changed_keys'] = array_slice(array_values(array_filter($keys, fn ($key) => ($before[$key] ?? null) != ($after[$key] ?? null))), 0, 30);
        } elseif ($this->sensitive($option)) {
            $context['redacted'] = true;
        } else {
            $context['from'] = mb_substr((string) $old, 0, 120);
            $context['to'] = mb_substr((string) $new, 0, 120);
        }

        $this->once("option:{$option}", fn () => $this->log->log('option.updated', "{$option} changed", $context));
    }

    private function sensitive(string $option): bool
    {
        return (bool) preg_match('/pass|secret|token|key|salt|auth|credential|private|api|license|licence|smtp|webhook|hash|nonce/i', $option);
    }

    private function tracked(WP_Post $post): bool
    {
        return ! in_array($post->post_type, self::SKIP_TYPES, true)
            && ! wp_is_post_revision($post)
            && ! wp_is_post_autosave($post)
            && ! (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            && ! in_array($post->post_status, ['auto-draft', 'inherit'], true);
    }

    private function submittedFields(): array
    {
        $names = [];

        foreach (array_keys((array) ($_POST['acf'] ?? [])) as $key) {
            if (function_exists('acf_get_field') && ($field = acf_get_field((string) $key))) {
                $names[] = (string) ($field['name'] ?: $field['label']);
            }
        }

        return array_slice(array_values(array_unique(array_filter($names))), 0, 40);
    }

    private function once(string $key, callable $callback): void
    {
        if (isset($this->once[$key])) {
            return;
        }

        $this->once[$key] = true;
        $callback();
    }

    private function verb(string $event): string
    {
        return [
            'content.created' => 'Created',
            'content.published' => 'Published',
            'content.scheduled' => 'Scheduled',
            'content.trashed' => 'Binned',
            'content.restored' => 'Restored',
            'content.unpublished' => 'Unpublished',
        ][$event] ?? 'Updated';
    }

    private function typeLabel(string $type): string
    {
        return (string) (get_post_type_object($type)?->labels->singular_name ?? $type);
    }

    private function title(WP_Post $post): string
    {
        return mb_substr(html_entity_decode(wp_strip_all_tags($post->post_title), ENT_QUOTES), 0, 120) ?: '(no title)';
    }
}

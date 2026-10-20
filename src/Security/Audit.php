<?php

namespace Snapshot\Security;

use WP_User;

class Audit
{
    public function __construct(private EventLog $log) {}

    public function boot(): void
    {
        add_action('snap/security/signed_in', fn ($user, $method = '') => $this->log->log('login.success', '', ['method' => $method], $user->ID, $user->user_login), 10, 2);

        add_action('wp_logout', fn ($userId = 0) => $this->log->log('login.logout', '', [], (int) $userId));

        add_action('user_register', function ($userId) {
            $user = get_userdata($userId);
            $this->log->log('user.created', 'Created '.($user ? $user->user_login : "user {$userId}"), [
                'target' => (int) $userId,
                'roles' => $user ? $user->roles : [],
            ]);
        });

        add_action('delete_user', function ($userId) {
            $user = get_userdata($userId);
            $this->log->log('user.deleted', 'Deleted '.($user ? $user->user_login : "user {$userId}"), ['target' => (int) $userId]);
        });

        add_action('set_user_role', function ($userId, $role, $oldRoles) {
            $user = get_userdata($userId);
            $name = $user ? $user->user_login : "user {$userId}";
            $event = $role === 'administrator' && ! in_array('administrator', (array) $oldRoles, true) ? 'user.admin_granted' : 'user.role_changed';

            $this->log->log($event, sprintf('%s is now %s, was %s', $name, $role, implode(', ', (array) $oldRoles) ?: 'none'), [
                'target' => (int) $userId,
                'role' => $role,
                'old_roles' => array_values((array) $oldRoles),
            ]);
        }, 10, 3);

        add_action('profile_update', function ($userId, $old) {
            $user = get_userdata($userId);

            if (! $user || ! $old instanceof WP_User) {
                return;
            }

            if ($user->user_email !== $old->user_email) {
                $this->log->log('user.email_changed', "Email changed for {$user->user_login}", [
                    'target' => (int) $userId,
                    'from' => $old->user_email,
                    'to' => $user->user_email,
                ]);
            }

            if ($user->user_pass !== $old->user_pass) {
                $this->log->log('user.password_changed', "Password changed for {$user->user_login}", ['target' => (int) $userId]);
            }
        }, 10, 2);

        add_action('after_password_reset', fn ($user) => $user instanceof WP_User
            ? $this->log->log('user.password_changed', "Password reset by email for {$user->user_login}", ['target' => $user->ID], $user->ID)
            : null);

        add_action('activated_plugin', fn ($plugin) => $this->log->log('plugin.activated', (string) $plugin));
        add_action('deactivated_plugin', fn ($plugin) => $this->log->log('plugin.deactivated', (string) $plugin));
        add_action('deleted_plugin', fn ($plugin, $deleted) => $deleted ? $this->log->log('plugin.deleted', (string) $plugin) : null, 10, 2);

        add_action('upgrader_process_complete', function ($upgrader, $extra) {
            $items = array_values(array_filter(array_merge(
                (array) ($extra['plugins'] ?? []),
                (array) ($extra['themes'] ?? []),
                (array) ($extra['plugin'] ?? []),
                (array) ($extra['theme'] ?? [])
            )));
            $this->log->log('software.updated', sprintf('%s %s', $extra['type'] ?? 'package', $extra['action'] ?? 'changed'), [
                'type' => $extra['type'] ?? '',
                'action' => $extra['action'] ?? '',
                'items' => array_slice($items, 0, 20),
            ]);
        }, 10, 2);

        add_action('switch_theme', fn ($name) => $this->log->log('theme.switched', "Switched to {$name}. Built in security does not run under another theme."));

        add_action('updated_option', function ($option, $old, $new) {
            if (! in_array($option, snap_security_watched_options(), true)) {
                return;
            }

            $severity = match (true) {
                $option === 'default_role' && $new === 'administrator',
                $option === 'users_can_register' && (bool) $new,
                in_array($option, ['siteurl', 'home', 'admin_email'], true) => 'critical',
                default => 'warning',
            };

            $this->log->log('option.changed', "{$option} changed", [
                'severity' => $severity,
                'option' => $option,
                'from' => is_scalar($old) ? (string) $old : wp_json_encode($old),
                'to' => is_scalar($new) ? (string) $new : wp_json_encode($new),
            ]);
        }, 10, 3);

        add_action('before_delete_post', function ($postId, $post = null) {
            $post = $post ?: get_post($postId);

            if (! $post || in_array($post->post_type, ['revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache'], true) || in_array($post->post_status, ['auto-draft', 'inherit'], true)) {
                return;
            }

            $this->log->log('content.deleted', sprintf('Deleted %s "%s"', $post->post_type, mb_substr($post->post_title, 0, 120)), [
                'post' => (int) $post->ID,
                'type' => $post->post_type,
                'status' => $post->post_status,
            ]);
        }, 10, 2);
    }
}

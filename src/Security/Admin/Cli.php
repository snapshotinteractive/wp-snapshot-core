<?php

namespace Snapshot\Security\Admin;

use Snapshot\Security\Ip;
use Snapshot\Security\Modules;
use Snapshot\Security\Schema;
use Snapshot\Security\Shield;
use Snapshot\Security\TwoFactor\TwoFactor;
use WP_CLI;

class Cli
{
    public static function register(Shield $shield): void
    {
        $commands = [
            'status' => [
                'shortdesc' => 'Show the security posture, two factor enrollment and recent activity.',
                'run' => function () use ($shield) {
                    foreach ($shield->posture()->checks() as $check) {
                        WP_CLI::log(sprintf('  %-5s %s. %s', strtoupper($check['status']), $check['label'], $check['detail']));
                    }

                    $day = $shield->log()->stats(gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS));
                    WP_CLI::log(sprintf('Last 24 hours: %d critical, %d warning, %d notice, %d info.', $day['critical'], $day['warning'], $day['notice'], $day['info']));
                },
            ],
            'modules' => [
                'shortdesc' => 'List the security modules and whether each one is running.',
                'run' => function () use ($shield) {
                    $modules = $shield->modules();
                    WP_CLI::log(sprintf('  %-10s %-26s %s', 'master', 'Built-in security', $modules->master() ? 'on' : 'OFF'));

                    foreach ($modules->registry() as $key => $module) {
                        $set = $shield->settings()->bool($module['setting']);
                        WP_CLI::log(sprintf('  %-10s %-26s %s', $key, $module['label'], $modules->on($key) ? 'on' : ($set ? 'stopped by master' : 'OFF')));
                    }
                },
            ],
            'enable' => [
                'shortdesc' => 'Switch a module on, or the master switch with "all".',
                'synopsis' => [['type' => 'positional', 'name' => 'module', 'description' => 'A module key from `wp snap security modules`, or all.']],
                'run' => fn ($args) => self::toggle($shield, $args[0], true),
            ],
            'disable' => [
                'shortdesc' => 'Switch a module off, or every module with "all".',
                'synopsis' => [['type' => 'positional', 'name' => 'module', 'description' => 'A module key from `wp snap security modules`, or all.']],
                'run' => fn ($args) => self::toggle($shield, $args[0], false),
            ],
            'install' => [
                'shortdesc' => 'Create or upgrade the security tables.',
                'run' => fn () => $shield->schema()->install()
                    ? WP_CLI::success('Security tables are ready.')
                    : WP_CLI::error('The security tables could not be created. Check the database user can CREATE TABLE.'),
            ],
            'scan' => [
                'shortdesc' => 'Run the file integrity scan now.',
                'run' => function () use ($shield) {
                    $result = $shield->scanner()->run();

                    foreach (array_slice($result['findings'], 0, 50) as $finding) {
                        WP_CLI::log(sprintf('  %-8s %-20s %s  %s', $finding['severity'], $finding['type'], $finding['path'], $finding['detail']));
                    }

                    WP_CLI::success(sprintf('%d findings, %d critical, %d new.', $result['total'], $result['counts']['critical'], $result['new']));
                },
            ],
            'vulns' => [
                'shortdesc' => 'Check installed core, plugin and theme versions for known vulnerabilities.',
                'run' => function () use ($shield) {
                    $result = $shield->vulnerabilities()->run();

                    foreach ($result['items'] as $item) {
                        WP_CLI::log(sprintf('  %s %s: %s%s', $item['name'], $item['version'], $item['title'], $item['fixed_in'] ? " (fixed in {$item['fixed_in']})" : ''));
                    }

                    WP_CLI::success(sprintf('%d vulnerabilities, %d lookups failed.', count($result['items']), $result['errors']));
                },
            ],
            'feeds' => [
                'shortdesc' => 'Refresh the threat intelligence feeds.',
                'run' => function () use ($shield) {
                    foreach ($shield->feeds()->sync() as $key => $status) {
                        WP_CLI::log(sprintf('  %-18s %6d %s', $key, $status['count'], $status['error'] ?? 'ok'));
                    }

                    WP_CLI::success('Feeds refreshed.');
                },
            ],
            'block' => [
                'shortdesc' => 'Block an address or CIDR range from the whole site.',
                'synopsis' => [
                    ['type' => 'positional', 'name' => 'target', 'description' => 'IP address or CIDR range.'],
                    ['type' => 'assoc', 'name' => 'minutes', 'description' => 'Leave out for a permanent block.', 'optional' => true],
                    ['type' => 'assoc', 'name' => 'reason', 'optional' => true],
                ],
                'run' => function ($args, $assoc) use ($shield) {
                    if (Ip::tooWide($args[0])) {
                        WP_CLI::error(sprintf('Range too wide. Use a prefix of /%d or longer for IPv4 and /%d or longer for IPv6.', Ip::MIN_V4_PREFIX, Ip::MIN_V6_PREFIX));
                    }

                    $minutes = (int) ($assoc['minutes'] ?? 0);
                    $id = $shield->blocklist()->add($args[0], 'block', 'site', 'manual', (string) ($assoc['reason'] ?? 'WP-CLI'), $minutes ? $minutes * MINUTE_IN_SECONDS : null);
                    $id ? WP_CLI::success("Blocked {$args[0]}.") : WP_CLI::error('Not a valid IP address or CIDR range.');
                    $shield->log()->log('security.block_added', "Block {$args[0]} from WP-CLI", ['minutes' => $minutes], 0, 'wp-cli', '');
                },
            ],
            'allow' => [
                'shortdesc' => 'Always allow an address or CIDR range, overriding feeds and lockouts.',
                'synopsis' => [
                    ['type' => 'positional', 'name' => 'target'],
                    ['type' => 'assoc', 'name' => 'reason', 'optional' => true],
                ],
                'run' => function ($args, $assoc) use ($shield) {
                    $id = $shield->blocklist()->add($args[0], 'allow', 'site', 'manual', (string) ($assoc['reason'] ?? 'WP-CLI'));
                    $id ? WP_CLI::success("Allowed {$args[0]}.") : WP_CLI::error('Not a valid IP address or CIDR range.');
                    $shield->log()->log('security.block_added', "Allow {$args[0]} from WP-CLI", [], 0, 'wp-cli', '');
                },
            ],
            'unblock' => [
                'shortdesc' => 'Remove manual, lockout and firewall blocks for an address and reset its counters.',
                'synopsis' => [['type' => 'positional', 'name' => 'target']],
                'run' => function ($args) use ($shield) {
                    if (Ip::range($args[0]) === null) {
                        WP_CLI::error('Not a valid IP address or CIDR range.');
                    }

                    $removed = $shield->blocklist()->release($args[0]);
                    $range = Ip::range($args[0]);
                    $bucket = $range['prefix'] === ($range['v4'] ? 32 : 128) ? Ip::bucket($args[0]) : $range['label'];

                    foreach (["lockouts:{$bucket}", "login:ip:{$bucket}", "fw-hits:{$bucket}"] as $key) {
                        $shield->throttle()->clear($key);
                    }

                    $shield->log()->log('security.block_removed', "Unblocked {$args[0]} from WP-CLI", [], 0, 'wp-cli', '');
                    WP_CLI::success("Removed {$removed} block(s) for {$args[0]}.");
                },
            ],
            'events' => [
                'shortdesc' => 'List recent security events.',
                'synopsis' => [
                    ['type' => 'assoc', 'name' => 'severity', 'optional' => true, 'options' => ['info', 'notice', 'warning', 'critical']],
                    ['type' => 'assoc', 'name' => 'limit', 'optional' => true, 'default' => 25],
                ],
                'run' => function ($args, $assoc) use ($shield) {
                    $rows = $shield->log()->query(['severity' => $assoc['severity'] ?? ''], 1, max(1, (int) ($assoc['limit'] ?? 25)))['rows'];

                    foreach ($rows as $row) {
                        WP_CLI::log(sprintf('%s  %-8s %-26s %-15s %-14s %s', $row->created_at, $row->severity, $row->event, $row->ip, $row->username, $row->message));
                    }
                },
            ],
            'reset-2fa' => [
                'shortdesc' => 'Remove every two factor method for a user, who sets it up again at the next sign in.',
                'synopsis' => [['type' => 'positional', 'name' => 'user', 'description' => 'User ID, login or email.']],
                'run' => function ($args) use ($shield) {
                    $user = get_user_by(is_numeric($args[0]) ? 'id' : (is_email($args[0]) ? 'email' : 'login'), $args[0]);

                    if (! $user) {
                        WP_CLI::error("No user matches {$args[0]}.");
                    }

                    $shield->twoFactor()->resetUser($user->ID);
                    $shield->log()->log('twofactor.reset', "Two factor reset for {$user->user_login} from WP-CLI", ['target' => $user->ID], 0, 'wp-cli', '');
                    WP_CLI::success("Two factor reset for {$user->user_login}. Their sessions were signed out.");
                },
            ],
            'skip-email-check' => [
                'shortdesc' => 'Let one user set up two factor sign in without the emailed confirmation code, for when site mail is broken.',
                'synopsis' => [['type' => 'positional', 'name' => 'user', 'description' => 'User ID, login or email.']],
                'run' => function ($args) use ($shield) {
                    $user = get_user_by(is_numeric($args[0]) ? 'id' : (is_email($args[0]) ? 'email' : 'login'), $args[0]);

                    if (! $user) {
                        WP_CLI::error("No user matches {$args[0]}.");
                    }

                    update_user_meta($user->ID, TwoFactor::SKIP_EMAIL, time());
                    $shield->log()->log('twofactor.reset', "Email confirmation skipped for {$user->user_login}'s next two factor setup, from WP-CLI", ['target' => $user->ID], 0, 'wp-cli', '');
                    WP_CLI::success("{$user->user_login} can set up two factor sign in without an emailed code at their next sign in.");
                },
            ],
            'uninstall' => [
                'shortdesc' => 'Drop the security tables and delete every security option. Two factor methods on users are kept.',
                'synopsis' => [['type' => 'flag', 'name' => 'yes', 'optional' => true]],
                'run' => function ($args, $assoc) use ($shield) {
                    WP_CLI::confirm('Drop the security tables, events, blocks and settings?', $assoc);
                    $shield->cron()->unschedule();
                    $shield->schema()->drop();
                    delete_transient(Schema::FAILED);

                    foreach (['snapshot_security_settings', 'snapshot_security_scan', 'snapshot_security_vulns', 'snapshot_security_feeds_status', 'snapshot_security_digest_at', 'snapshot_security_daily_at', 'snapshot_security_cloudflare_ranges', 'snapshot_security_cloudflare_ranges_at', 'snap_security_cloudflare_ranges'] as $option) {
                        delete_option($option);
                    }

                    WP_CLI::success('Security tables and options removed. They are recreated on the next request while this theme is active.');
                },
            ],
            'digest' => [
                'shortdesc' => 'Send the security summary email now.',
                'run' => fn () => $shield->alerts()->digest(true)
                    ? WP_CLI::success('Summary sent to '.implode(', ', $shield->alerts()->recipients()).'.')
                    : WP_CLI::warning('The summary could not be sent.'),
            ],
            'cloudflare' => [
                'shortdesc' => 'Refresh the Cloudflare address ranges and prune expired edge blocks.',
                'run' => function () use ($shield) {
                    $shield->cloudflare()->refreshRanges(true)
                        ? WP_CLI::log('Ranges refreshed.')
                        : WP_CLI::warning('Ranges not refreshed. Is the Cloudflare setting on?');
                    WP_CLI::success(sprintf('%d expired edge blocks removed.', $shield->cloudflare()->prune()));
                },
            ],
            'prune' => [
                'shortdesc' => 'Delete expired blocks, counters and old events now.',
                'run' => function () use ($shield) {
                    $blocks = $shield->blocklist()->prune();
                    $counters = $shield->throttle()->prune();
                    $events = $shield->log()->prune(max(7, $shield->settings()->int('event_retention_days')));
                    WP_CLI::success("Removed {$blocks} blocks, {$counters} counters and {$events} events.");
                },
            ],
        ];

        foreach ($commands as $name => $command) {
            WP_CLI::add_command("snap security {$name}", $command['run'], array_filter([
                'shortdesc' => $command['shortdesc'],
                'synopsis' => $command['synopsis'] ?? null,
            ]));
        }
    }

    private static function toggle(Shield $shield, string $module, bool $on): void
    {
        $settings = $shield->settings();
        $setting = $module === 'all' ? Modules::MASTER : (snap_security_modules()[$module]['setting'] ?? null);

        if ($setting === null) {
            WP_CLI::error("Unknown module {$module}. Run wp snap security modules for the list.");
        }

        if ($settings->locked($setting)) {
            WP_CLI::error(sprintf('%s is defined in wp-config.php and cannot be changed here.', $settings->constant($setting)));
        }

        $values = [];

        foreach ($settings->schema() as $key => $field) {
            if (($field['tab'] ?? '') === 'modules') {
                $values[$key] = $settings->bool($key);
            }
        }

        $values[$setting] = $on;
        $settings->save($values, 'modules');

        if ($module === 'feeds' && ! $on) {
            $shield->blocklist()->removeSources('feed:');
        }

        $label = $module === 'all' ? 'Built-in security' : snap_security_modules()[$module]['label'];
        $critical = ! $on && in_array($module, ['all', 'twofactor', 'firewall', 'login'], true);
        $shield->log()->log('security.module_changed', sprintf('%s switched %s from WP-CLI', $label, $on ? 'on' : 'off'), ['module' => $module, 'enabled' => $on, 'severity' => $critical ? 'critical' : 'warning'], 0, 'wp-cli', '');
        $shield->admin()->forgetStatus();
        WP_CLI::success(sprintf('%s switched %s.', $label, $on ? 'on' : 'off'));
    }
}

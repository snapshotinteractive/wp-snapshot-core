<?php

namespace Snapshot\System;

use WP_CLI;

class Cli
{
    public static function register(Panel $panel): void
    {
        WP_CLI::add_command('snap system status', function () use ($panel) {
            foreach ($panel->diagnostics()->groups() as $group) {
                WP_CLI::log($group['label']);

                foreach ($group['checks'] as $check) {
                    WP_CLI::log(sprintf('  %-5s %-28s %s%s', strtoupper($check['status']), $check['label'], $check['value'], $check['detail'] !== '' ? '  ('.$check['detail'].')' : ''));
                }
            }
        }, ['shortdesc' => 'Show server, WordPress, database and theme diagnostics.']);

        WP_CLI::add_command('snap system tools', function () use ($panel) {
            foreach ($panel->tools()->registry() as $key => $tool) {
                WP_CLI::log(sprintf('  %-20s %s', $key, $tool['label']));
            }
        }, ['shortdesc' => 'List the system tools.']);

        WP_CLI::add_command('snap system run', function ($args) use ($panel) {
            $tool = (string) ($args[0] ?? '');

            if (! isset($panel->tools()->registry()[$tool])) {
                WP_CLI::error('Unknown tool. Run wp snap system tools for the list.');
            }

            $result = $panel->tools()->run($tool);

            if (function_exists('snap_security_log')) {
                snap_security_log('system.tool_run', sprintf('%s from WP-CLI: %s', $tool, $result['message']), ['tool' => $tool, 'ok' => $result['ok']]);
            }

            $result['ok'] ? WP_CLI::success($result['message']) : WP_CLI::error($result['message']);
        }, [
            'shortdesc' => 'Run a system tool, for example rebuild_views.',
            'synopsis' => [['type' => 'positional', 'name' => 'tool', 'description' => 'A tool key from wp snap system tools.']],
        ]);

        WP_CLI::add_command('snap system report', fn () => WP_CLI::log($panel->diagnostics()->report()), ['shortdesc' => 'Print the diagnostics report for a support request.']);
    }
}

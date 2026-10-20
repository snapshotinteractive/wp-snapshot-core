<?php

namespace Snapshot\Seo\Admin;

use Snapshot\Seo\Audit;
use Snapshot\Seo\Context;
use Snapshot\Seo\Engine;
use Snapshot\Seo\IndexNow;
use Snapshot\Seo\Meta;
use Snapshot\Seo\Modules;
use Snapshot\Seo\Redirects;
use Snapshot\Seo\Schema;
use WP_CLI;

class Cli
{
    public static function register(Engine $engine): void
    {
        $commands = [
            'status' => [
                'shortdesc' => 'Show the SEO health checks and score.',
                'run' => function () use ($engine) {
                    $checks = $engine->audit()->checks();

                    foreach ($checks as $check) {
                        WP_CLI::log(sprintf('  %-5s %s. %s', strtoupper($check['status']), $check['label'], $check['detail']));
                    }

                    WP_CLI::log(sprintf('Score: %d%%', $engine->audit()->score($checks)));
                },
            ],
            'modules' => [
                'shortdesc' => 'List the SEO modules and whether each one is running.',
                'run' => function () use ($engine) {
                    $modules = $engine->modules();
                    WP_CLI::log(sprintf('  %-10s %-28s %s', 'master', 'Built-in SEO', $modules->master() ? 'on' : 'OFF'));

                    foreach ($modules->registry() as $key => $module) {
                        $paused = $modules->enabled($key) ? $modules->pausedBy($key) : [];
                        $state = $modules->on($key) ? 'on' : ($paused ? 'paused by '.implode(', ', $paused) : ($engine->settings()->bool($module['setting']) ? 'stopped by master' : 'OFF'));
                        WP_CLI::log(sprintf('  %-10s %-28s %s', $key, $module['label'], $state));
                    }
                },
            ],
            'enable' => [
                'shortdesc' => 'Switch a module on, or the master switch with "all".',
                'synopsis' => [['type' => 'positional', 'name' => 'module', 'description' => 'A module key from `wp snap seo modules`, or all.']],
                'run' => fn ($args) => self::toggle($engine, $args[0], true),
            ],
            'disable' => [
                'shortdesc' => 'Switch a module off, or every module with "all".',
                'synopsis' => [['type' => 'positional', 'name' => 'module', 'description' => 'A module key from `wp snap seo modules`, or all.']],
                'run' => fn ($args) => self::toggle($engine, $args[0], false),
            ],
            'sitemap' => [
                'shortdesc' => 'Show, check or curate the sitemap.',
                'synopsis' => [
                    ['type' => 'positional', 'name' => 'action', 'description' => 'status, check, urls, exclude or include.'],
                    ['type' => 'positional', 'name' => 'ids', 'optional' => true, 'repeating' => true, 'description' => 'Post IDs for exclude and include.'],
                ],
                'run' => function ($args) use ($engine) {
                    $sitemaps = $engine->sitemaps();
                    $action = $args[0] ?? 'status';

                    if ($action === 'status') {
                        foreach ($sitemaps->sections() as $key => $section) {
                            $state = ! $section['searchable'] ? 'hidden from search' : ($section['included'] ? 'included' : 'left out');
                            WP_CLI::log(sprintf('  %-28s %-20s %6d listed %6d left out', $key, $state, $section['count'], $section['excluded']));
                        }

                        WP_CLI::log(sprintf('  %-28s %-20s %6d listed', 'extra', 'from settings', count($sitemaps->extraUrls())));

                        return;
                    }

                    if ($action === 'check') {
                        $result = $sitemaps->check();

                        foreach ($result['issues'] as $issue) {
                            WP_CLI::log(sprintf('  %-5s %-10s %s  %s', strtoupper($issue['level']), $issue['type'], $issue['url'], $issue['detail']));
                        }

                        WP_CLI::success(sprintf('%d addresses, %d issues.', $result['total'], count($result['issues'])));

                        return;
                    }

                    if ($action === 'urls') {
                        foreach ($sitemaps->urls() as $url) {
                            WP_CLI::log($url['loc']);
                        }

                        return;
                    }

                    if (in_array($action, ['exclude', 'include'], true)) {
                        $ids = array_filter(array_map('intval', array_slice($args, 1)));

                        foreach ($ids as $id) {
                            $action === 'exclude' ? update_post_meta($id, Meta::KEYS['sitemap'], 'exclude') : delete_post_meta($id, Meta::KEYS['sitemap']);
                        }

                        WP_CLI::success(sprintf('%d entries %s.', count($ids), $action === 'exclude' ? 'left out of the sitemap' : 'put back in the sitemap'));

                        return;
                    }

                    WP_CLI::error('Unknown action. Use status, check, urls, exclude or include.');
                },
            ],
            'install' => [
                'shortdesc' => 'Create or upgrade the redirect and 404 tables.',
                'run' => fn () => $engine->schema()->install()
                    ? WP_CLI::success('SEO tables are ready.')
                    : WP_CLI::error('The SEO tables could not be created. Check the database user can CREATE TABLE.'),
            ],
            'report' => [
                'shortdesc' => 'Rebuild the content report: missing descriptions, duplicate and long titles.',
                'run' => function () use ($engine) {
                    $report = $engine->audit()->buildReport();
                    WP_CLI::log(sprintf('%d indexable entries.', $report['total']));

                    foreach ($report['issues'] as $issue => $count) {
                        WP_CLI::log(sprintf('  %-20s %d', $issue, $count));
                    }

                    $engine->admin()->forgetStatus();
                    WP_CLI::success('Report saved.');
                },
            ],
            'inspect' => [
                'shortdesc' => 'Show the title, description, robots, canonical and structured data for an entry.',
                'synopsis' => [['type' => 'positional', 'name' => 'post', 'description' => 'Post ID or full address.']],
                'run' => function ($args) use ($engine) {
                    $id = is_numeric($args[0]) ? (int) $args[0] : url_to_postid($args[0]);
                    $post = get_post($id);

                    if (! $post) {
                        WP_CLI::error("No entry matches {$args[0]}.");
                    }

                    $context = Context::forPost($post);
                    $resolved = $engine->resolver()->resolve($context);
                    WP_CLI::log('Title:       '.$resolved['title'].' ('.$resolved['title_source'].')');
                    WP_CLI::log('Description: '.$resolved['description'].' ('.$resolved['description_source'].')');
                    WP_CLI::log('Robots:      '.($resolved['robots']['index'] ? 'index' : 'noindex, '.$resolved['robots']['reason']).($resolved['robots']['follow'] ? ', follow' : ', nofollow'));
                    WP_CLI::log('Canonical:   '.$resolved['canonical']);
                    WP_CLI::log('Schema:      '.$resolved['schema_type']);
                    WP_CLI::log(wp_json_encode($engine->graph()->build($context), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                },
            ],
            'import' => [
                'shortdesc' => 'Import SEO data: meta (Yoast and Rank Math titles, descriptions, robots), redirection, rankmath or yoast redirects, or a CSV of redirects.',
                'synopsis' => [
                    ['type' => 'positional', 'name' => 'source', 'description' => 'meta, redirection, rankmath, yoast or csv.'],
                    ['type' => 'assoc', 'name' => 'file', 'optional' => true, 'description' => 'CSV file for the csv source: source,target,code,match_type,note.'],
                    ['type' => 'flag', 'name' => 'overwrite', 'optional' => true, 'description' => 'Replace values already set in the theme fields.'],
                ],
                'run' => function ($args, $assoc) use ($engine) {
                    $importer = $engine->importer();
                    $engine->schema()->ensure();

                    $result = match ($args[0]) {
                        'meta' => $importer->meta(! empty($assoc['overwrite'])),
                        'redirection' => $importer->redirection(),
                        'rankmath' => $importer->rankMathRedirects(),
                        'yoast' => $importer->yoastRedirects(),
                        'csv' => is_readable((string) ($assoc['file'] ?? '')) ? $importer->csv((string) file_get_contents($assoc['file'])) : WP_CLI::error('Pass --file=<path> to a readable CSV.'),
                        default => WP_CLI::error('Unknown source. Use meta, redirection, rankmath, yoast or csv.'),
                    };

                    $engine->admin()->forgetStatus();
                    WP_CLI::success(wp_json_encode($result));
                },
            ],
            'redirect' => [
                'shortdesc' => 'Add a redirect.',
                'synopsis' => [
                    ['type' => 'positional', 'name' => 'source', 'description' => 'Old address or path.'],
                    ['type' => 'positional', 'name' => 'target', 'optional' => true, 'description' => 'New address. Omit for 410 or 451.'],
                    ['type' => 'assoc', 'name' => 'code', 'optional' => true, 'default' => '301'],
                    ['type' => 'assoc', 'name' => 'match', 'optional' => true, 'default' => 'exact', 'description' => 'exact, prefix or regex.'],
                ],
                'run' => function ($args, $assoc) use ($engine) {
                    $engine->schema()->ensure();
                    $result = $engine->redirects()->add($args[0], $args[1] ?? '', (int) ($assoc['code'] ?? 301), (string) ($assoc['match'] ?? 'exact'), 'manual', 0, 'WP-CLI');
                    $result['ok'] ? WP_CLI::success($result['message']) : WP_CLI::error($result['message']);
                },
            ],
            'redirects' => [
                'shortdesc' => 'List redirects.',
                'synopsis' => [['type' => 'assoc', 'name' => 'search', 'optional' => true]],
                'run' => function ($args, $assoc) use ($engine) {
                    $engine->schema()->ensure();
                    $rows = $engine->redirects()->query(['search' => (string) ($assoc['search'] ?? '')], 1, 500)['rows'];
                    \WP_CLI\Utils\format_items('table', $rows, ['id', 'source', 'target', 'code', 'match_type', 'origin', 'hits']);
                },
            ],
            'test' => [
                'shortdesc' => 'Show which redirect, if any, an address would follow.',
                'synopsis' => [['type' => 'positional', 'name' => 'address']],
                'run' => function ($args) use ($engine) {
                    $match = $engine->redirects()->match(Redirects::normalize($args[0]));
                    $match ? WP_CLI::log(sprintf('%d to %s (rule %d)', $match['code'], $match['target'] ?: '(none)', $match['id'])) : WP_CLI::log('No redirect matches.');
                },
            ],
            'indexnow' => [
                'shortdesc' => 'Submit addresses to IndexNow now.',
                'synopsis' => [['type' => 'positional', 'name' => 'address', 'repeating' => true]],
                'run' => function ($args) use ($engine) {
                    $entry = $engine->indexNow()->submit($args);
                    $entry['code'] >= 200 && $entry['code'] < 300 ? WP_CLI::success("IndexNow answered {$entry['code']}.") : WP_CLI::warning("IndexNow answered {$entry['code']}. {$entry['error']}");
                },
            ],
            'uninstall' => [
                'shortdesc' => 'Drop the redirect and 404 tables and delete every SEO option. Per entry SEO fields are kept.',
                'synopsis' => [['type' => 'flag', 'name' => 'yes', 'optional' => true]],
                'run' => function ($args, $assoc) use ($engine) {
                    WP_CLI::confirm('Drop the redirect and 404 tables and the SEO settings?', $assoc);
                    wp_clear_scheduled_hook(Engine::DAILY);
                    $engine->schema()->drop();
                    delete_transient(Schema::FAILED);

                    foreach (['snapshot_seo_settings', Audit::REPORT, Audit::LAST_DAILY, AdminPage::SETUP, Redirects::COUNT, 'snapshot_seo_redirect_patterns', IndexNow::LOG] as $option) {
                        delete_option($option);
                    }

                    WP_CLI::success('SEO tables and options removed.');
                },
            ],
        ];

        foreach ($commands as $name => $command) {
            WP_CLI::add_command("snap seo {$name}", $command['run'], array_filter([
                'shortdesc' => $command['shortdesc'],
                'synopsis' => $command['synopsis'] ?? null,
            ]));
        }
    }

    private static function toggle(Engine $engine, string $module, bool $on): void
    {
        $settings = $engine->settings();
        $setting = $module === 'all' ? Modules::MASTER : (snap_seo_modules()[$module]['setting'] ?? null);

        if ($setting === null) {
            WP_CLI::error("Unknown module {$module}. Run wp snap seo modules for the list.");
        }

        if ($settings->locked($setting)) {
            WP_CLI::error(sprintf('%s is defined in wp-config.php and cannot be changed here.', $settings->constant($setting)));
        }

        $settings->set($setting, $on);
        $label = $module === 'all' ? 'Built-in SEO' : snap_seo_modules()[$module]['label'];

        if (function_exists('snap_security_log')) {
            snap_security_log('seo.module_changed', sprintf('%s switched %s from WP-CLI', $label, $on ? 'on' : 'off'));
        }

        $engine->admin()->forgetStatus();
        WP_CLI::success(sprintf('%s switched %s.', $label, $on ? 'on' : 'off'));
    }
}

<?php

namespace Snapshot\Seo;

use Closure;
use Snapshot\Seo\Admin\AdminBar;
use Snapshot\Seo\Admin\AdminPage;
use Snapshot\Seo\Admin\Cli;
use Snapshot\Seo\Admin\Editor;

class Engine
{
    public const DAILY = 'snapshot_seo_daily';

    private static ?self $instance = null;

    private array $services = [];

    private bool $booted = false;

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public static function boot(): void
    {
        $engine = self::instance();

        if ($engine->booted) {
            return;
        }

        $engine->booted = true;
        $modules = $engine->modules();

        if (defined('WP_CLI') && WP_CLI) {
            Cli::register($engine);
        }

        if (is_admin()) {
            $engine->admin()->boot();
        }

        $engine->adminBar()->boot();
        add_action('init', [$engine, 'schedule']);
        add_action(self::DAILY, [$engine, 'daily']);
        add_action('switch_theme', fn () => wp_clear_scheduled_hook(self::DAILY));

        if (! $modules->master()) {
            return;
        }

        if ($modules->on('editor')) {
            $engine->editor()->boot();
        }

        $engine->head()->boot($modules->on('head'), $modules->on('social'));

        if ($modules->on('schema')) {
            $engine->graph()->boot();
        }

        if ($modules->on('sitemaps')) {
            $engine->sitemaps()->boot();
        }

        if ($modules->on('robots') || $modules->on('llms')) {
            $engine->robots()->boot($modules->on('robots'), $modules->on('llms'));
        }

        if ($modules->on('cleanup')) {
            $engine->cleanup()->boot();
        }

        if ($modules->on('head') && $engine->settings()->bool('attachment_redirect')) {
            $engine->cleanup()->attachments();
        }

        if ($modules->on('indexnow')) {
            $engine->indexNow()->boot();
        }

        if (($modules->on('redirects') || $modules->on('monitor')) && $engine->schema()->ensure()) {
            if (get_option(Redirects::COUNT) === false) {
                $engine->redirects()->flush();
            }

            $engine->redirects()->boot($modules->on('redirects'), $modules->on('monitor'));
        }
    }

    public function schedule(): void
    {
        if (! wp_next_scheduled(self::DAILY)) {
            wp_schedule_event(time() + 10 * MINUTE_IN_SECONDS, 'daily', self::DAILY);
        }
    }

    public function daily(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        if ($this->schema()->ensure()) {
            $this->redirects()->prune();
        }

        if ($this->modules()->master()) {
            $this->audit()->buildReport();
        }

        delete_transient(Robots::LLMS_CACHE);
        update_option(Audit::LAST_DAILY, time(), false);

        if ($this->modules()->on('sitemaps')) {
            $this->sitemaps()->check();
        }
    }

    public function requestPath(): string
    {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $home = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');

        if ($home !== '' && str_starts_with($path, $home.'/')) {
            $path = substr($path, strlen($home));
        }

        return '/'.ltrim($path, '/');
    }

    public function settings(): Settings
    {
        return $this->make(Settings::class, fn () => new Settings);
    }

    public function modules(): Modules
    {
        return $this->make(Modules::class, fn () => new Modules($this->settings()));
    }

    public function schema(): Schema
    {
        return $this->make(Schema::class, fn () => new Schema);
    }

    public function meta(): Meta
    {
        return $this->make(Meta::class, fn () => new Meta);
    }

    public function identity(): Identity
    {
        return $this->make(Identity::class, fn () => new Identity($this->settings()));
    }

    public function variables(): Variables
    {
        return $this->make(Variables::class, fn () => new Variables($this->settings(), $this->identity(), $this->meta()));
    }

    public function resolver(): Resolver
    {
        return $this->make(Resolver::class, fn () => new Resolver($this->settings(), $this->meta(), $this->variables(), $this->identity()));
    }

    public function head(): Head
    {
        return $this->make(Head::class, fn () => new Head($this));
    }

    public function graph(): Graph
    {
        return $this->make(Graph::class, fn () => new Graph($this));
    }

    public function sitemaps(): Sitemaps
    {
        return $this->make(Sitemaps::class, fn () => new Sitemaps($this));
    }

    public function robots(): Robots
    {
        return $this->make(Robots::class, fn () => new Robots($this));
    }

    public function cleanup(): Cleanup
    {
        return $this->make(Cleanup::class, fn () => new Cleanup($this->settings()));
    }

    public function redirects(): Redirects
    {
        return $this->make(Redirects::class, fn () => new Redirects($this));
    }

    public function indexNow(): IndexNow
    {
        return $this->make(IndexNow::class, fn () => new IndexNow($this));
    }

    public function audit(): Audit
    {
        return $this->make(Audit::class, fn () => new Audit($this));
    }

    public function analysis(): Analysis
    {
        return $this->make(Analysis::class, fn () => new Analysis($this));
    }

    public function importer(): Importer
    {
        return $this->make(Importer::class, fn () => new Importer($this));
    }

    public function admin(): AdminPage
    {
        return $this->make(AdminPage::class, fn () => new AdminPage($this));
    }

    public function adminBar(): AdminBar
    {
        return $this->make(AdminBar::class, fn () => new AdminBar($this));
    }

    public function editor(): Editor
    {
        return $this->make(Editor::class, fn () => new Editor($this));
    }

    private function make(string $key, Closure $factory): object
    {
        return $this->services[$key] ??= $factory();
    }
}

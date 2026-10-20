<?php

namespace Snapshot\Security;

use Closure;
use Snapshot\Security\Admin\AdminBar;
use Snapshot\Security\Admin\AdminPage;
use Snapshot\Security\Admin\Cli;
use Snapshot\Security\TwoFactor\EmailCode;
use Snapshot\Security\TwoFactor\Passkeys;
use Snapshot\Security\TwoFactor\Profile;
use Snapshot\Security\TwoFactor\RecoveryCodes;
use Snapshot\Security\TwoFactor\Totp;
use Snapshot\Security\TwoFactor\TwoFactor;
use Snapshot\Security\TwoFactor\Vault;

class Shield
{
    private static ?self $instance = null;

    private array $services = [];

    private bool $booted = false;

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public static function boot(): void
    {
        $shield = self::instance();

        if ($shield->booted) {
            return;
        }

        $shield->booted = true;
        $modules = $shield->modules();
        $shield->hardening()->boot($modules->on('hardening'), $modules->on('headers'));

        add_action('wp_login', function ($login, $user) {
            if (TwoFactor::bypassed() && ! empty($_POST['pwd']) && $user instanceof \WP_User) {
                do_action('snap/security/signed_in', $user, 'password');
            }
        }, 10, 2);

        if ($modules->on('passwords')) {
            $shield->passwords()->boot();
        }

        $shield->twoFactor()->boot();

        if (is_admin()) {
            $shield->profile()->boot();
        }

        if (defined('WP_CLI') && WP_CLI) {
            Cli::register($shield);
        }

        if (! $shield->schema()->ensure()) {
            add_action('admin_notices', [$shield, 'schemaNotice']);

            return;
        }

        $shield->loginGuard()->boot($modules->on('login'));

        if ($modules->on('login')) {
            $shield->turnstile()->boot();
        }

        $shield->audit()->boot();
        $shield->activity()->boot();

        if ($modules->on('alerts')) {
            $shield->alerts()->boot();
        }

        if ($modules->on('cloudflare')) {
            $shield->cloudflare()->boot();
        }

        if ($modules->master()) {
            $shield->reporter()->boot();
        }

        $shield->cron()->boot();

        if ($modules->on('status')) {
            $shield->status()->boot();
        }

        if (is_admin()) {
            $shield->admin()->boot();
        }

        $shield->adminBar()->boot();

        if ($modules->on('firewall')) {
            add_action('after_setup_theme', [$shield->firewall(), 'inspect'], 0);
        }
    }

    public function schemaNotice(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
            esc_html__('Security:', 'snapshot-core'),
            esc_html__('The security tables could not be created, so the firewall, sign in lockouts, the audit log and the limit on two factor guesses are off. Two factor sign in still runs. Give the database user CREATE and ALTER rights, then run wp snap security install.', 'snapshot-core')
        );
    }

    public function settings(): Settings
    {
        return $this->make(Settings::class, fn () => new Settings);
    }

    public function schema(): Schema
    {
        return $this->make(Schema::class, fn () => new Schema);
    }

    public function ip(): Ip
    {
        return $this->make(Ip::class, fn () => new Ip($this->settings()));
    }

    public function blocklist(): Blocklist
    {
        return $this->make(Blocklist::class, fn () => new Blocklist);
    }

    public function throttle(): Throttle
    {
        return $this->make(Throttle::class, fn () => new Throttle);
    }

    public function log(): EventLog
    {
        return $this->make(EventLog::class, fn () => new EventLog($this->ip()));
    }

    public function responder(): Responder
    {
        return $this->make(Responder::class, fn () => new Responder);
    }

    public function firewall(): Firewall
    {
        return $this->make(Firewall::class, fn () => new Firewall(
            $this->settings(), $this->ip(), $this->blocklist(), $this->throttle(), $this->log(), $this->responder()
        ));
    }

    public function hardening(): Hardening
    {
        return $this->make(Hardening::class, fn () => new Hardening($this->settings(), $this->throttle(), $this->log(), $this->ip()));
    }

    public function loginGuard(): LoginGuard
    {
        return $this->make(LoginGuard::class, fn () => new LoginGuard(
            $this->settings(), $this->ip(), $this->blocklist(), $this->throttle(), $this->log(), $this->firewall()
        ));
    }

    public function turnstile(): Turnstile
    {
        return $this->make(Turnstile::class, fn () => new Turnstile($this->settings(), $this->ip(), $this->throttle(), $this->log()));
    }

    public function passwords(): Passwords
    {
        return $this->make(Passwords::class, fn () => new Passwords($this->settings(), $this->log()));
    }

    public function vault(): Vault
    {
        return $this->make(Vault::class, fn () => new Vault);
    }

    public function totp(): Totp
    {
        return $this->make(Totp::class, fn () => new Totp($this->vault()));
    }

    public function passkeys(): Passkeys
    {
        return $this->make(Passkeys::class, fn () => new Passkeys);
    }

    public function recovery(): RecoveryCodes
    {
        return $this->make(RecoveryCodes::class, fn () => new RecoveryCodes);
    }

    public function emailCode(): EmailCode
    {
        return $this->make(EmailCode::class, fn () => new EmailCode);
    }

    public function twoFactor(): TwoFactor
    {
        return $this->make(TwoFactor::class, fn () => new TwoFactor(
            $this->settings(), $this->throttle(), $this->log(), $this->ip(), $this->totp(), $this->passkeys(),
            $this->recovery(), $this->emailCode(), $this->vault(), $this->responder(), $this->loginGuard()
        ));
    }

    public function profile(): Profile
    {
        return $this->make(Profile::class, fn () => new Profile(
            $this->twoFactor(), $this->totp(), $this->passkeys(), $this->recovery(), $this->vault(), $this->log(), $this->responder()
        ));
    }

    public function audit(): Audit
    {
        return $this->make(Audit::class, fn () => new Audit($this->log()));
    }

    public function activity(): Activity
    {
        return $this->make(Activity::class, fn () => new Activity($this->log()));
    }

    public function alerts(): Alerts
    {
        return $this->make(Alerts::class, fn () => new Alerts($this->settings(), $this->throttle(), $this->log()));
    }

    public function feeds(): Feeds
    {
        return $this->make(Feeds::class, fn () => new Feeds($this->settings(), $this->blocklist(), $this->log()));
    }

    public function cloudflare(): Cloudflare
    {
        return $this->make(Cloudflare::class, fn () => new Cloudflare($this->settings(), $this->throttle(), $this->log()));
    }

    public function reporter(): AbuseReporter
    {
        return $this->make(AbuseReporter::class, fn () => new AbuseReporter($this->settings(), $this->throttle(), $this->ip()));
    }

    public function scanner(): Scanner
    {
        return $this->make(Scanner::class, fn () => new Scanner($this->log()));
    }

    public function vulnerabilities(): Vulnerabilities
    {
        return $this->make(Vulnerabilities::class, fn () => new Vulnerabilities($this->log()));
    }

    public function posture(): Posture
    {
        return $this->make(Posture::class, fn () => new Posture($this));
    }

    public function cron(): Cron
    {
        return $this->make(Cron::class, fn () => new Cron($this));
    }

    public function status(): StatusEndpoint
    {
        return $this->make(StatusEndpoint::class, fn () => new StatusEndpoint($this));
    }

    public function admin(): AdminPage
    {
        return $this->make(AdminPage::class, fn () => new AdminPage($this));
    }

    public function modules(): Modules
    {
        return $this->make(Modules::class, fn () => new Modules($this->settings()));
    }

    public function adminBar(): AdminBar
    {
        return $this->make(AdminBar::class, fn () => new AdminBar($this));
    }

    private function make(string $key, Closure $factory): object
    {
        return $this->services[$key] ??= $factory();
    }
}

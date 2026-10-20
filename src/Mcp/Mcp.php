<?php

namespace Snapshot\Mcp;

use Snapshot\Mcp\OAuth\Clients;
use Snapshot\Mcp\OAuth\Endpoints;
use Snapshot\Mcp\OAuth\Grants;
use Snapshot\Security\Settings;
use Snapshot\Security\TwoFactor\TwoFactor;
use WP_Session_Tokens;
use WP_User;

class Mcp
{
    public const NAMESPACE = 'snapshot/v1';

    public const ROUTE = '/mcp';

    public const LEVELS = ['read', 'edit', 'publish'];

    private static ?self $instance = null;

    private array $services = [];

    private bool $booted = false;

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public static function enabled(): bool
    {
        return defined('SNAPSHOT_MCP_ENABLED') && filter_var(constant('SNAPSHOT_MCP_ENABLED'), FILTER_VALIDATE_BOOL);
    }

    public static function siteAccess(): string
    {
        $level = defined('SNAPSHOT_MCP_ACCESS') ? strtolower(trim((string) constant('SNAPSHOT_MCP_ACCESS'))) : 'edit';

        return in_array($level, self::LEVELS, true) ? $level : 'read';
    }

    public static function access(): string
    {
        $site = self::siteAccess();
        $credential = self::$instance?->auth()->token()['level'] ?? null;

        if (! is_string($credential) || ! in_array($credential, self::LEVELS, true)) {
            return $site;
        }

        return self::LEVELS[min(array_search($site, self::LEVELS, true), array_search($credential, self::LEVELS, true))];
    }

    public static function allows(string $level): bool
    {
        $wanted = array_search($level, self::LEVELS, true);

        return $wanted !== false && array_search(self::access(), self::LEVELS, true) >= $wanted;
    }

    public static function capability(): string
    {
        return (string) snap_filter('snap/mcp/capability', 'manage_options');
    }

    public static function pretty(): bool
    {
        return (string) get_option('permalink_structure') !== '';
    }

    public static function oauth(): bool
    {
        return self::enabled() && self::pretty() && (! defined('SNAPSHOT_MCP_OAUTH') || filter_var(constant('SNAPSHOT_MCP_OAUTH'), FILTER_VALIDATE_BOOL));
    }

    public static function alias(): bool
    {
        return self::pretty() && (bool) snap_filter('snap/mcp/alias', true);
    }

    public static function restEndpoint(): string
    {
        return rest_url(self::NAMESPACE.self::ROUTE);
    }

    public static function endpoint(): string
    {
        return self::alias() ? home_url('/mcp') : self::restEndpoint();
    }

    public static function issuer(): string
    {
        return untrailingslashit(home_url());
    }

    public static function twoFactorOn(): bool
    {
        return class_exists(TwoFactor::class) && function_exists('snap_security') && ! TwoFactor::bypassed();
    }

    public static function sessionVerified(int $userId): bool
    {
        $session = WP_Session_Tokens::get_instance($userId)->get(wp_get_session_token());

        return ! empty($session['snapshot_2fa']);
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;
        $this->lifecycle();

        if (! self::enabled()) {
            return;
        }

        $this->auth()->boot();
        $this->server()->boot();
        $this->abilities()->boot();

        if (self::oauth()) {
            $this->endpoints()->boot();
        }
    }

    public function revokeUser(int $userId, string $why): void
    {
        $this->tokens()->revokeUser($userId, $why);
        $this->grants()->revokeUser($userId, $why);
    }

    public function revokeUnverified(): void
    {
        if (! self::twoFactorOn()) {
            return;
        }

        $this->tokens()->revokeUnverified('made before two factor sign in was switched on');
        $this->grants()->revokeUnverified('made before two factor sign in was switched on');
    }

    public function tokens(): Tokens
    {
        return $this->services[Tokens::class] ??= new Tokens;
    }

    public function clients(): Clients
    {
        return $this->services[Clients::class] ??= new Clients;
    }

    public function grants(): Grants
    {
        return $this->services[Grants::class] ??= new Grants($this->clients());
    }

    public function endpoints(): Endpoints
    {
        return $this->services[Endpoints::class] ??= new Endpoints($this->clients(), $this->grants());
    }

    public function auth(): Auth
    {
        return $this->services[Auth::class] ??= new Auth($this->tokens(), $this->grants());
    }

    public function server(): Server
    {
        return $this->services[Server::class] ??= new Server($this->auth(), $this->abilities());
    }

    public function abilities(): Abilities
    {
        return $this->services[Abilities::class] ??= new Abilities(new Fields);
    }

    private function lifecycle(): void
    {
        add_action('delete_user', fn ($userId) => $this->revokeUser((int) $userId, 'account deleted'));
        add_action('update_option_'.Settings::OPTION, fn () => add_action('shutdown', [$this, 'revokeUnverified']));
        add_action('admin_init', [$this, 'revokeUnverified']);
        add_action('wp_set_password', function ($password, $userId, $before = null) {
            if ($before instanceof WP_User && wp_check_password((string) $password, $before->user_pass, (int) $userId)) {
                return;
            }

            $this->revokeUser((int) $userId, 'password changed');
        }, 10, 3);
        add_action('profile_update', function ($userId, $before) {
            $after = get_userdata((int) $userId);

            if ($after && $before instanceof WP_User && $after->user_pass !== $before->user_pass) {
                $this->revokeUser((int) $userId, 'password changed');
            }
        }, 10, 2);
    }
}

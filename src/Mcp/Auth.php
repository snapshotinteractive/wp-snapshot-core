<?php

namespace Snapshot\Mcp;

use Snapshot\Mcp\OAuth\Grants;
use Snapshot\Security\Ip;

class Auth
{
    private const DENIALS_PER_HOUR = 20;

    private ?bool $route = null;

    private ?array $token = null;

    private string $failure = '';

    private bool $candidate = false;

    public function __construct(private Tokens $tokens, private Grants $grants) {}

    public function boot(): void
    {
        add_filter('determine_current_user', [$this, 'user'], 30);
        add_action('parse_request', [$this, 'authenticate'], 0);
        add_filter('user_has_cap', [$this, 'withoutUnfiltered'], 10, 1);
        add_filter('map_meta_cap', [$this, 'denyUnfiltered'], 10, 2);
        add_filter('rest_pre_dispatch', [$this, 'onlyMcpRoute'], 0, 3);
    }

    public function withoutUnfiltered(array $caps): array
    {
        if ($this->token !== null) {
            $caps['unfiltered_html'] = false;
            $caps['unfiltered_upload'] = false;
        }

        return $caps;
    }

    public function denyUnfiltered(array $caps, string $cap): array
    {
        return $this->token !== null && in_array($cap, ['unfiltered_html', 'unfiltered_upload'], true) ? ['do_not_allow'] : $caps;
    }

    public function onlyMcpRoute($result, $server, $request)
    {
        if ($this->token !== null && untrailingslashit((string) $request->get_route()) !== self::route()) {
            return new \WP_Error('snapshot_mcp_route', __('An MCP token only works on the MCP address.', 'snapshot-core'), ['status' => 403]);
        }

        return $result;
    }

    public static function route(): string
    {
        return '/'.Mcp::NAMESPACE.Mcp::ROUTE;
    }

    public function isRoute(): bool
    {
        if ($this->route !== null) {
            return $this->route;
        }

        $route = isset($_REQUEST['rest_route']) && is_string($_REQUEST['rest_route']) ? (string) wp_unslash($_REQUEST['rest_route']) : '';
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        $home = untrailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH));
        $alias = Mcp::alias() && untrailingslashit(strtolower(rawurldecode($path))) === strtolower($home.'/mcp');

        return $this->route = $alias || str_contains(strtolower(rawurldecode($route.' '.$path)), strtolower(Mcp::NAMESPACE.Mcp::ROUTE));
    }

    public function user($userId)
    {
        if (! $this->isRoute()) {
            return $userId;
        }

        $GLOBALS['wp_rest_auth_cookie'] = false;

        if (! $this->candidate || ! defined('REST_REQUEST') || ! REST_REQUEST) {
            return false;
        }

        if ($this->failure === 'pending') {
            $this->verify();
        }

        return $this->token !== null ? (int) $this->token['user'] : false;
    }

    public function authenticate($wp): void
    {
        $this->failure = 'missing';

        if (! $this->served($wp)) {
            return;
        }

        if ($this->bearer() !== '') {
            $this->candidate = true;
            $this->failure = 'pending';
        }
    }

    private function verify(): void
    {
        $ip = $this->ip();
        $given = $this->bearer();
        $token = str_starts_with($given, 'snapmcpa_') ? $this->grants->verifyAccess($given) : $this->tokens->verify($given);

        if ($token === null) {
            $this->refuse($ip);

            return;
        }

        if (! empty($token['expired'])) {
            $this->failure = 'expired';

            return;
        }

        if (Mcp::twoFactorOn() && ! $token['verified']) {
            $this->failure = 'twofactor';

            return;
        }

        $this->token = $token;
        $this->failure = '';
        $token['kind'] === 'oauth' ? $this->grants->touch($token['id'], $ip) : $this->tokens->touch($token['id'], $ip);
    }

    public function authenticated(): bool
    {
        return $this->token !== null && get_current_user_id() === (int) $this->token['user'];
    }

    public function token(): ?array
    {
        return $this->token;
    }

    public function failure(): string
    {
        return $this->failure;
    }

    private function served($wp): bool
    {
        if (isset($_POST['rest_route']) || basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'index.php') {
            return false;
        }

        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) {
            return false;
        }

        $route = is_object($wp) && isset($wp->query_vars['rest_route']) && is_string($wp->query_vars['rest_route']) ? $wp->query_vars['rest_route'] : '';

        return $route !== '' && untrailingslashit('/'.ltrim($route, '/')) === self::route();
    }

    private function refuse(string $ip): void
    {
        $this->failure = 'invalid';
        $throttle = function_exists('snap_security') ? snap_security()->throttle() : null;

        if (! $throttle) {
            return;
        }

        $hits = $throttle->hit('mcp-denied:'.Ip::bucket($ip), HOUR_IN_SECONDS);

        if ($hits > self::DENIALS_PER_HOUR) {
            $this->failure = 'throttled';
        }

        if ($hits === 1 && function_exists('snap_security_log')) {
            snap_security_log('mcp.token_rejected', 'An MCP request was refused: the token is unknown, expired or revoked');
        }
    }

    private function bearer(): string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $header = trim((string) ($_SERVER[$key] ?? ''));

            if (stripos($header, 'Bearer ') === 0) {
                return trim(substr($header, 7));
            }
        }

        return trim((string) ($_SERVER['HTTP_X_SNAPSHOT_MCP_TOKEN'] ?? ''));
    }

    private function ip(): string
    {
        return function_exists('snap_security') ? snap_security()->ip()->client() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}

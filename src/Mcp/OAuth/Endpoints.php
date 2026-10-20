<?php

namespace Snapshot\Mcp\OAuth;

use Snapshot\Mcp\Mcp;
use Snapshot\Security\Ip;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

use function Roots\view;

class Endpoints
{
    public const ACTION = 'snapshot-mcp';

    private const REQUEST_KEYS = ['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'];

    private const TOKEN_FAILURES = 30;

    private const REGISTRATIONS = 30;

    public function __construct(private Clients $clients, private Grants $grants) {}

    public function boot(): void
    {
        add_action('init', [$this, 'wellKnown'], 0);
        add_action('init', [$this, 'authorizeRedirect'], 0);
        add_action('login_form_'.self::ACTION, [$this, 'consent']);
        add_action('rest_api_init', [$this, 'routes']);
        add_filter('application_password_is_api_request', fn ($api) => $this->oauthRoute() ? false : $api);
    }

    public function routes(): void
    {
        foreach (['token' => 'token', 'register' => 'register', 'revoke' => 'revoke'] as $route => $method) {
            register_rest_route(Mcp::NAMESPACE, '/oauth/'.$route, [
                'methods' => 'POST',
                'callback' => [$this, $method],
                'permission_callback' => '__return_true',
            ]);
        }
    }

    public function wellKnown(): void
    {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $home = preg_quote(untrailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH)), '#');

        if (! preg_match('#^(?:'.$home.')?/\.well-known/(oauth-protected-resource|oauth-authorization-server)(/[^?]*)?$#', $path, $match)) {
            return;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($method === 'OPTIONS') {
            $this->json(null, 204);
        }

        if (! in_array($method, ['GET', 'HEAD'], true)) {
            $this->json(['error' => 'invalid_request'], 405);
        }

        if ($match[1] !== 'oauth-protected-resource') {
            $this->json(self::serverMetadata());
        }

        $resource = self::resourceFor(untrailingslashit($match[2] ?? ''));

        $resource === null ? $this->json(['error' => 'not_found'], 404) : $this->json([
            'resource' => $resource,
            'authorization_servers' => [Mcp::issuer()],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => self::scopes(),
            'resource_name' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
        ]);
    }

    public static function serverMetadata(): array
    {
        return [
            'issuer' => Mcp::issuer(),
            'authorization_endpoint' => home_url('/mcp/authorize'),
            'token_endpoint' => rest_url(Mcp::NAMESPACE.'/oauth/token'),
            'registration_endpoint' => rest_url(Mcp::NAMESPACE.'/oauth/register'),
            'revocation_endpoint' => rest_url(Mcp::NAMESPACE.'/oauth/revoke'),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'scopes_supported' => self::scopes(),
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    public static function metadataUrl(string $resource): string
    {
        return home_url('/.well-known/oauth-protected-resource'.untrailingslashit((string) wp_parse_url($resource, PHP_URL_PATH)));
    }

    public static function resourceFor(string $suffix): ?string
    {
        if ($suffix === '') {
            return Mcp::endpoint();
        }

        foreach ([Mcp::endpoint(), Mcp::restEndpoint()] as $resource) {
            if (untrailingslashit((string) wp_parse_url($resource, PHP_URL_PATH)) === $suffix) {
                return $resource;
            }
        }

        return null;
    }

    public function authorizeRedirect(): void
    {
        if ($this->path() !== '/mcp/authorize') {
            return;
        }

        nocache_headers();
        wp_safe_redirect($this->consentUrl(wp_unslash($_GET)));
        exit;
    }

    public function consent(): never
    {
        nocache_headers();
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: frame-ancestors 'none'");

        $input = wp_unslash(array_intersect_key($_REQUEST, array_flip(self::REQUEST_KEYS)));
        $client = $this->clients->get((string) ($input['client_id'] ?? ''));
        $redirect = (string) ($input['redirect_uri'] ?? '');

        if ($client === null || $redirect === '' || ! Clients::redirectMatches($client, $redirect)) {
            $this->page(__('This connection request is not valid', 'snapshot-core'), 'snapshot::mcp.error', [
                'message' => __('The app that sent you here is unknown to this site, or asked to return somewhere it did not register. Start the connection again from the app. If this keeps happening, remove this site from the app (in Claude Code, claude mcp remove) and add it again so it registers afresh.', 'snapshot-core'),
            ]);
        }

        $state = (string) ($input['state'] ?? '');

        if (($input['response_type'] ?? '') !== 'code') {
            $this->back($redirect, $state, 'unsupported_response_type', 'Only response_type=code is supported.');
        }

        $challenge = (string) ($input['code_challenge'] ?? '');

        if (($input['code_challenge_method'] ?? '') !== 'S256' || ! preg_match('/^[A-Za-z0-9\-_]{43,128}$/', $challenge)) {
            $this->back($redirect, $state, 'invalid_request', 'PKCE with code_challenge_method=S256 is required.');
        }

        $resource = (string) ($input['resource'] ?? '');

        if ($resource !== '' && ! in_array(untrailingslashit($resource), array_map('untrailingslashit', [Mcp::endpoint(), Mcp::restEndpoint()]), true)) {
            $this->back($redirect, $state, 'invalid_target', 'The resource is not this site\'s MCP server.');
        }

        if (! is_user_logged_in()) {
            wp_safe_redirect(wp_login_url($this->consentUrl($input)));
            exit;
        }

        $user = wp_get_current_user();

        if (! user_can($user, Mcp::capability())) {
            $this->page(__('Only administrators can connect', 'snapshot-core'), 'snapshot::mcp.error', [
                'message' => __('AI assistants connect as an administrator of this site. Sign in with an administrator account, or ask one to connect.', 'snapshot-core'),
                'cancel' => $this->backUrl($redirect, $state, 'access_denied', 'The account is not allowed to use MCP.'),
            ]);
        }

        $levels = array_slice(Mcp::LEVELS, 0, (int) array_search(Mcp::siteAccess(), Mcp::LEVELS, true) + 1);

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (! wp_verify_nonce((string) ($_POST['_snapshot_mcp'] ?? ''), $this->nonceAction($client['id']))) {
                $this->page(__('This page expired', 'snapshot-core'), 'snapshot::mcp.error', [
                    'message' => __('The form expired before it was sent. Start the connection again from the app.', 'snapshot-core'),
                    'cancel' => $this->backUrl($redirect, $state, 'access_denied', 'The consent form expired.'),
                ]);
            }

            if (($_POST['decision'] ?? '') !== 'allow') {
                $this->back($redirect, $state, 'access_denied', 'The administrator declined.');
            }

            $level = (string) ($_POST['level'] ?? '');
            $level = in_array($level, $levels, true) ? $level : 'read';
            $code = $this->grants->issueCode($client, $user->ID, [
                'redirect_uri' => $redirect,
                'code_challenge' => $challenge,
                'resource' => $resource !== '' ? $resource : Mcp::endpoint(),
            ], $level, Mcp::sessionVerified($user->ID));

            $this->send($redirect, ['code' => $code, 'state' => $state !== '' ? $state : null, 'iss' => Mcp::issuer()]);
        }

        preg_match_all('/mcp:(read|edit|publish)/', (string) ($input['scope'] ?? ''), $scopes);
        $asked = $scopes[1] === [] ? 'edit' : Mcp::LEVELS[max(array_map(fn ($level) => array_search($level, Mcp::LEVELS, true), $scopes[1]))];
        $asked = $asked === 'publish' ? 'edit' : $asked;

        $this->page(__('Connect an AI assistant', 'snapshot-core'), 'snapshot::mcp.authorize', [
            'client' => $client,
            'destination' => Clients::destination($redirect),
            'known' => Clients::known($redirect),
            'user' => $user,
            'site' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            'levels' => array_intersect_key(snap_mcp_access_levels(), array_flip($levels)),
            'selected' => in_array($asked, $levels, true) ? $asked : end($levels),
            'action' => $this->consentUrl($input),
            'nonce' => wp_nonce_field($this->nonceAction($client['id']), '_snapshot_mcp', false, false),
            'twoFactor' => Mcp::twoFactorOn(),
        ]);
    }

    public function token(WP_REST_Request $request): WP_REST_Response
    {
        $ip = $this->ip();
        $throttle = function_exists('snap_security') ? snap_security()->throttle() : null;
        $bucket = 'mcp-oauth:'.Ip::bucket($ip);

        if ($throttle && $throttle->count($bucket, HOUR_IN_SECONDS) >= self::TOKEN_FAILURES) {
            return $this->error('invalid_request', 'Too many failed requests from this address. Try again in an hour.', 429);
        }

        [$clientId, $secret, $basic] = $this->credentials($request);
        $client = $this->clients->get($clientId);

        if (! $this->clients->authenticate($client, $secret)) {
            $throttle?->hit($bucket, HOUR_IN_SECONDS);

            return $this->error('invalid_client', 'Client authentication failed.', 401, $basic ? ['WWW-Authenticate' => 'Basic realm="mcp"'] : []);
        }

        $result = match ((string) $request->get_param('grant_type')) {
            'authorization_code' => $this->grants->exchange($client, (string) $request->get_param('code'), (string) $request->get_param('redirect_uri'), (string) $request->get_param('code_verifier'), (string) $request->get_param('resource')),
            'refresh_token' => $this->grants->refresh($client, (string) $request->get_param('refresh_token')),
            default => new WP_Error('unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.'),
        };

        if (is_wp_error($result)) {
            $throttle?->hit($bucket, HOUR_IN_SECONDS);

            return $this->error($result->get_error_code(), $result->get_error_message());
        }

        return $this->respond($result);
    }

    public function register(WP_REST_Request $request): WP_REST_Response
    {
        $throttle = function_exists('snap_security') ? snap_security()->throttle() : null;

        if ($throttle && $throttle->hit('mcp-register:'.Ip::bucket($this->ip()), HOUR_IN_SECONDS) > self::REGISTRATIONS) {
            return $this->error('invalid_client_metadata', 'Too many registrations from this address. Try again in an hour.', 429);
        }

        $result = $this->clients->register((array) ($request->get_json_params() ?: $request->get_body_params()));

        return is_wp_error($result)
            ? $this->error($result->get_error_code(), $result->get_error_message(), (int) ($result->get_error_data()['status'] ?? 400))
            : $this->respond($result, 201);
    }

    public function revoke(WP_REST_Request $request): WP_REST_Response
    {
        [$clientId, $secret] = $this->credentials($request);

        if (! $this->clients->authenticate($this->clients->get($clientId), $secret)) {
            return $this->error('invalid_client', 'Client authentication failed.', 401);
        }

        $this->grants->revokeToken((string) $request->get_param('token'), $clientId);

        return $this->respond([]);
    }

    private function credentials(WP_REST_Request $request): array
    {
        $header = (string) ($request->get_header('authorization') ?? '');

        if (stripos($header, 'Basic ') === 0) {
            $decoded = (string) base64_decode(substr($header, 6), true);
            [$id, $secret] = array_pad(explode(':', $decoded, 2), 2, '');

            return [urldecode($id), urldecode($secret), true];
        }

        $secret = $request->get_param('client_secret');

        return [(string) $request->get_param('client_id'), is_string($secret) ? $secret : null, false];
    }

    private function consentUrl(array $input): string
    {
        $query = array_filter(array_intersect_key($input, array_flip(self::REQUEST_KEYS)), 'is_string');

        return add_query_arg(array_map('rawurlencode', ['action' => self::ACTION] + $query), site_url('wp-login.php', 'login'));
    }

    private function back(string $redirect, string $state, string $error, string $description): never
    {
        $this->send($redirect, ['error' => $error, 'error_description' => $description, 'state' => $state !== '' ? $state : null, 'iss' => Mcp::issuer()]);
    }

    private function backUrl(string $redirect, string $state, string $error, string $description): string
    {
        $query = http_build_query(array_filter(['error' => $error, 'error_description' => $description, 'state' => $state !== '' ? $state : null, 'iss' => Mcp::issuer()], fn ($value) => $value !== null), '', '&', PHP_QUERY_RFC3986);

        return $redirect.(str_contains($redirect, '?') ? '&' : '?').$query;
    }

    private function send(string $redirect, array $params): never
    {
        $query = http_build_query(array_filter($params, fn ($value) => $value !== null), '', '&', PHP_QUERY_RFC3986);
        header('Location: '.$redirect.(str_contains($redirect, '?') ? '&' : '?').$query, true, 302);
        exit;
    }

    private function page(string $title, string $view, array $vars): never
    {
        login_header($title);
        echo view($view, $vars)->render();
        login_footer();
        exit;
    }

    private function nonceAction(string $clientId): string
    {
        return 'snapshot_mcp_consent_'.$clientId;
    }

    private function respond(array $body, int $status = 200): WP_REST_Response
    {
        $response = new WP_REST_Response($body === [] ? new \stdClass : $body, $status);
        $response->header('Cache-Control', 'no-store');
        $response->header('Pragma', 'no-cache');

        return $response;
    }

    private function error(string $code, string $description, int $status = 400, array $headers = []): WP_REST_Response
    {
        $response = $this->respond(['error' => $code, 'error_description' => $description], $status);

        foreach ($headers as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }

    private function json(?array $body, int $status = 200): never
    {
        status_header($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, MCP-Protocol-Version');
        header('Cache-Control: no-cache');

        if ($body !== null) {
            echo wp_json_encode($body, JSON_UNESCAPED_SLASHES);
        }

        exit;
    }

    private function path(): string
    {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $home = untrailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH));

        if ($home !== '' && str_starts_with($path, $home.'/')) {
            $path = substr($path, strlen($home));
        }

        return '/'.trim($path, '/');
    }

    private function oauthRoute(): bool
    {
        $route = (string) ($_GET['rest_route'] ?? '').' '.(string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        return str_contains($route, Mcp::NAMESPACE.'/oauth/');
    }

    private static function scopes(): array
    {
        return array_map(fn ($level) => 'mcp:'.$level, array_slice(Mcp::LEVELS, 0, (int) array_search(Mcp::siteAccess(), Mcp::LEVELS, true) + 1));
    }

    private function ip(): string
    {
        return function_exists('snap_security') ? snap_security()->ip()->client() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}

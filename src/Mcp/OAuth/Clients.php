<?php

namespace Snapshot\Mcp\OAuth;

use WP_Error;

class Clients
{
    public const PREFIX = 'snapshot_mcp_client_';

    public const MAX = 500;

    public const STALE = 30 * DAY_IN_SECONDS;

    private const KNOWN_HOSTS = ['claude.ai', 'claude.com', 'vscode.dev', 'insiders.vscode.dev', 'localhost', '127.0.0.1', '[::1]'];

    public function register(array $input)
    {
        $uris = $input['redirect_uris'] ?? null;

        if (! is_array($uris) || $uris === [] || count($uris) > 10) {
            return $this->invalid('invalid_redirect_uri', 'redirect_uris must list between one and ten addresses.');
        }

        foreach ($uris as $uri) {
            if (! is_string($uri) || ! self::redirectAllowed($uri)) {
                return $this->invalid('invalid_redirect_uri', 'Each redirect URI must be https, http on a loopback address, or a private-use scheme such as com.example.app:/callback, with no fragment.');
            }
        }

        $method = (string) ($input['token_endpoint_auth_method'] ?? 'client_secret_basic');

        if (! in_array($method, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
            return $this->invalid('invalid_client_metadata', 'token_endpoint_auth_method must be none, client_secret_post or client_secret_basic.');
        }

        foreach (['grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']] as $key => $allowed) {
            if (isset($input[$key]) && (! is_array($input[$key]) || array_diff($input[$key], $allowed) !== [])) {
                return $this->invalid('invalid_client_metadata', sprintf('%s may only contain %s.', $key, implode(', ', $allowed)));
            }
        }

        $this->prune();

        $waiting = array_filter($this->all(), fn ($client) => ! $client['authorized']);

        if (count($waiting) >= self::MAX) {
            uasort($waiting, fn ($a, $b) => $a['created'] <=> $b['created']);
            delete_option(self::PREFIX.array_key_first($waiting));
        }

        $id = bin2hex(random_bytes(12));
        $secret = $method === 'none' ? null : 'snapmcps_'.wp_generate_password(40, false);
        $name = mb_substr(sanitize_text_field((string) ($input['client_name'] ?? '')), 0, 80) ?: 'MCP client';
        $client = [
            'id' => $id,
            'name' => $name,
            'redirect_uris' => array_values(array_unique($uris)),
            'method' => $method,
            'secret' => $secret === null ? null : $this->hash($secret),
            'created' => time(),
            'authorized' => false,
        ];

        add_option(self::PREFIX.$id, $client, '', false);

        return array_filter([
            'client_id' => $id,
            'client_secret' => $secret,
            'client_id_issued_at' => $client['created'],
            'client_secret_expires_at' => $secret === null ? null : 0,
            'client_name' => $name,
            'redirect_uris' => $client['redirect_uris'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $method,
        ], fn ($value) => $value !== null);
    }

    public function get(string $id): ?array
    {
        if (! preg_match('/^[a-f0-9]{24}$/', $id)) {
            return null;
        }

        $client = get_option(self::PREFIX.$id);

        return is_array($client) ? $client : null;
    }

    public function authenticate(?array $client, ?string $secret): bool
    {
        if ($client === null) {
            return false;
        }

        if ($client['method'] === 'none') {
            return $secret === null || $secret === '';
        }

        return is_string($secret) && $secret !== '' && hash_equals((string) $client['secret'], $this->hash($secret));
    }

    public function markAuthorized(string $id): void
    {
        $client = $this->get($id);

        if ($client && ! $client['authorized']) {
            $client['authorized'] = true;
            update_option(self::PREFIX.$id, $client, false);
        }
    }

    public function forget(string $id): void
    {
        if (preg_match('/^[a-f0-9]{24}$/', $id)) {
            delete_option(self::PREFIX.$id);
        }
    }

    public function all(): array
    {
        global $wpdb;

        $clients = [];
        $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(self::PREFIX).'%'));

        foreach ($names as $name) {
            $client = $this->get(substr((string) $name, strlen(self::PREFIX)));

            if ($client !== null) {
                $clients[$client['id']] = $client;
            }
        }

        return $clients;
    }

    public static function redirectMatches(array $client, string $uri): bool
    {
        if (! self::redirectAllowed($uri)) {
            return false;
        }

        foreach ($client['redirect_uris'] as $registered) {
            if (hash_equals($registered, $uri)) {
                return true;
            }

            $loopback = self::withoutPort($registered);

            if ($loopback !== null && hash_equals($loopback, (string) self::withoutPort($uri))) {
                return true;
            }
        }

        return false;
    }

    public static function redirectAllowed(string $uri): bool
    {
        if ($uri === '' || strlen($uri) > 2000 || preg_match('/[\s<>"\'\\\\\x00-\x1f\x7f]/', $uri) || str_contains($uri, '#')) {
            return false;
        }

        if (! preg_match('#^([a-z][a-z0-9+.-]*):(.*)$#i', $uri, $parts)) {
            return false;
        }

        $scheme = strtolower($parts[1]);

        if ($scheme === 'https' || $scheme === 'http') {
            $authority = self::authority($uri);

            if ($authority === null) {
                return false;
            }

            return $scheme === 'https' ? $authority['host'] !== '' : self::loopback($authority['host']);
        }

        return preg_match('/^[a-z][a-z0-9+-]*(\.[a-z0-9+-]+)+$/', $scheme) === 1
            || in_array($scheme, (array) snap_filter('snap/mcp/redirect_schemes', ['cursor', 'vscode', 'vscode-insiders', 'windsurf']), true);
    }

    public static function known(string $uri): bool
    {
        $host = self::authority($uri)['host'] ?? '';
        $scheme = strtolower((string) strtok($uri, ':'));

        return in_array($host, self::KNOWN_HOSTS, true) || str_ends_with($host, '.claude.ai') || str_ends_with($host, '.claude.com')
            || in_array($scheme, (array) snap_filter('snap/mcp/redirect_schemes', ['cursor', 'vscode', 'vscode-insiders', 'windsurf']), true);
    }

    public static function destination(string $uri): string
    {
        $authority = self::authority($uri);

        return $authority !== null ? $authority['host'] : strtolower((string) strtok($uri, ':')).':';
    }

    private static function authority(string $uri): ?array
    {
        if (! preg_match('#^https?://([^/?]*)([/?].*)?$#i', $uri, $parts)) {
            return null;
        }

        if (! preg_match('/^(?<host>[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*|\[::1\])(?::(?<port>[0-9]{1,5}))?$/i', $parts[1], $match)) {
            return null;
        }

        return ['host' => strtolower($match['host']), 'port' => $match['port'] ?? ''];
    }

    private static function withoutPort(string $uri): ?string
    {
        if (! preg_match('#^http://(localhost|127\.0\.0\.1|\[::1\])(?::[0-9]{1,5})?([/?].*)?$#i', $uri, $parts)) {
            return null;
        }

        return 'http://'.strtolower($parts[1]).($parts[2] ?? '');
    }

    private static function loopback(string $host): bool
    {
        return in_array(strtolower($host), ['localhost', '127.0.0.1', '[::1]'], true);
    }

    private function prune(): void
    {
        foreach ($this->all() as $id => $client) {
            if (! $client['authorized'] && (int) $client['created'] < time() - self::STALE) {
                delete_option(self::PREFIX.$id);
            }
        }
    }

    private function hash(string $secret): string
    {
        return hash_hmac('sha256', $secret, wp_salt('secure_auth'));
    }

    private function invalid(string $code, string $message, int $status = 400): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status]);
    }
}

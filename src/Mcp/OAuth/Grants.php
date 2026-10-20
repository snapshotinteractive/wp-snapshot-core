<?php

namespace Snapshot\Mcp\OAuth;

use Snapshot\Mcp\Mcp;
use WP_Error;

class Grants
{
    public const PREFIX = 'snapshot_mcp_grant_';

    public const USED = 'snapshot_mcp_used_';

    public const CODE = 'snapshot_mcp_code_';

    public const ACCESS_TTL = HOUR_IN_SECONDS;

    public const REFRESH_TTL = 30 * DAY_IN_SECONDS;

    public const CODE_TTL = MINUTE_IN_SECONDS;

    public const PER_USER = 20;

    private const REUSE_GRACE = MINUTE_IN_SECONDS;

    private const LIVE = 3;

    private const HISTORY = 200;

    private const TOUCH_EVERY = 5 * MINUTE_IN_SECONDS;

    public function __construct(private Clients $clients) {}

    public function issueCode(array $client, int $userId, array $request, string $level, bool $verified): string
    {
        $code = 'snapmcpc_'.wp_generate_password(43, false);

        set_transient(self::CODE.$this->hash($code), [
            'client' => $client['id'],
            'user' => $userId,
            'redirect_uri' => $request['redirect_uri'],
            'challenge' => $request['code_challenge'],
            'resource' => $request['resource'],
            'level' => $level,
            'verified' => $verified,
            'expires' => time() + self::CODE_TTL,
        ], self::CODE_TTL);

        return $code;
    }

    public function exchange(array $client, string $code, string $redirect, string $verifier, string $resource)
    {
        $key = self::CODE.$this->hash($code);
        $data = get_transient($key);

        if (! is_array($data) || ! delete_transient($key)) {
            return $this->fail('invalid_grant', 'The authorization code is unknown, expired or already used.');
        }

        if ($data['client'] !== $client['id'] || ! hash_equals((string) $data['redirect_uri'], $redirect) || (int) $data['expires'] < time()) {
            return $this->fail('invalid_grant', 'The authorization code does not match this client, redirect URI or time.');
        }

        if (! preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) || ! hash_equals((string) $data['challenge'], self::challenge($verifier))) {
            return $this->fail('invalid_grant', 'The code verifier does not match the code challenge.');
        }

        if ($resource !== '' && ! hash_equals(untrailingslashit((string) $data['resource']), untrailingslashit($resource))) {
            return $this->fail('invalid_target', 'The resource does not match the one that was authorized.');
        }

        if (! $this->userAllowed((int) $data['user'])) {
            return $this->fail('invalid_grant', 'This account can no longer use MCP.');
        }

        return $this->create($client, (int) $data['user'], (string) $data['level'], (bool) $data['verified'], (string) $data['resource']);
    }

    public function refresh(array $client, string $token)
    {
        if (! preg_match('/^snapmcpr_([a-f0-9]{12})_([A-Za-z0-9]{40})$/', $token, $parts)) {
            return $this->fail('invalid_grant', 'The refresh token is malformed.');
        }

        $grant = $this->get($parts[1]);

        if ($grant === null || $grant['client'] !== $client['id']) {
            return $this->fail('invalid_grant', 'The refresh token is unknown or revoked.');
        }

        $hash = $this->hash($parts[2]);
        $current = in_array($hash, (array) $grant['refresh'], true);
        $retry = ! $current && in_array($hash, (array) $grant['previous'], true) && time() - (int) $grant['rotated'] <= self::REUSE_GRACE;

        if (! $current && ! $retry) {
            if (in_array($hash, (array) $grant['issued'], true)) {
                $this->revoke($grant['id'], 'a used refresh token was presented again', 'warning');
            }

            return $this->fail('invalid_grant', 'The refresh token is unknown or revoked.');
        }

        if ((int) $grant['expires'] < time() || ! $this->userAllowed((int) $grant['user'])) {
            $this->revoke($grant['id'], (int) $grant['expires'] < time() ? 'expired' : 'account can no longer use MCP');

            return $this->fail('invalid_grant', 'The connection expired or the account can no longer use MCP.');
        }

        $mode = match (true) {
            $client['method'] !== 'none' => 'access',
            $retry => 'join',
            default => 'rotate',
        };

        return $this->issue($grant, $mode) ?? $this->fail('invalid_grant', 'The connection was revoked or refreshed at the same time. Try again.');
    }

    public function verifyAccess(string $token): ?array
    {
        if (! preg_match('/^snapmcpa_([a-f0-9]{12})_([A-Za-z0-9]{40})$/', $token, $parts)) {
            return null;
        }

        $grant = $this->get($parts[1]);

        $hash = $this->hash($parts[2]);
        $match = $grant === null ? [] : array_filter((array) $grant['access'], fn ($access) => hash_equals((string) $access['h'], $hash));

        if ($match === []) {
            return null;
        }

        if (max(array_column($match, 'e')) < time()) {
            return ['expired' => true];
        }

        if (! $this->userAllowed((int) $grant['user'])) {
            return null;
        }

        return [
            'kind' => 'oauth',
            'id' => $grant['id'],
            'user' => (int) $grant['user'],
            'name' => $grant['name'],
            'level' => $grant['level'],
            'verified' => ! empty($grant['verified']),
        ];
    }

    public function revokeToken(string $token, string $clientId): void
    {
        if (preg_match('/^snapmcp[ar]_([a-f0-9]{12})_([A-Za-z0-9]{40})$/', $token, $parts) && ($grant = $this->get($parts[1])) && hash_equals((string) $grant['client'], $clientId)) {
            $hash = $this->hash($parts[2]);

            if (in_array($hash, array_column((array) $grant['access'], 'h'), true) || in_array($hash, (array) $grant['refresh'], true)) {
                $this->revoke($grant['id'], 'revoked by the app');
            }
        }
    }

    public function touch(string $id, string $ip): void
    {
        $used = get_option(self::USED.$id);

        if (is_array($used) && time() - (int) ($used['used'] ?? 0) < self::TOUCH_EVERY) {
            return;
        }

        update_option(self::USED.$id, ['used' => time(), 'ip' => $ip], false);
    }

    public function usage(string $id): array
    {
        $used = get_option(self::USED.$id);

        return is_array($used) ? $used : ['used' => 0, 'ip' => ''];
    }

    public function all(): array
    {
        $grants = [];

        foreach ($this->stored() as $id => $grant) {
            if ((int) $grant['expires'] < time()) {
                $this->revoke($grant['id'], 'expired');

                continue;
            }

            $grants[$id] = $grant;
        }

        uasort($grants, fn ($a, $b) => $b['created'] <=> $a['created']);

        return $grants;
    }

    public function revoke(string $id, string $why, string $severity = 'notice'): bool
    {
        $grant = $this->get($id);

        if ($grant === null || ! delete_option(self::PREFIX.$id)) {
            return false;
        }

        delete_option(self::USED.$id);

        $this->log('mcp.disconnected', sprintf('Disconnected "%s" (%s)', $grant['name'], $why), ['grant' => $id, 'severity' => $severity], (int) $grant['user']);

        return true;
    }

    public function revokeUser(int $userId, string $why): int
    {
        $count = 0;

        foreach ($this->stored() as $id => $grant) {
            if ((int) $grant['user'] === $userId) {
                $count += $this->revoke((string) $id, $why) ? 1 : 0;
            }
        }

        return $count;
    }

    public function revokeUnverified(string $why): int
    {
        $count = 0;

        foreach ($this->stored() as $id => $grant) {
            if (empty($grant['verified'])) {
                $count += $this->revoke((string) $id, $why) ? 1 : 0;
            }
        }

        return $count;
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function create(array $client, int $userId, string $level, bool $verified, string $resource)
    {
        $live = array_filter($this->stored(), fn ($grant) => (int) $grant['user'] === $userId);

        if (count($live) >= self::PER_USER) {
            uasort($live, fn ($a, $b) => $a['created'] <=> $b['created']);
            $this->revoke((string) array_key_first($live), 'too many connections, oldest removed');
        }

        $id = bin2hex(random_bytes(6));
        $grant = [
            'id' => $id,
            'user' => $userId,
            'client' => $client['id'],
            'name' => $client['name'],
            'redirect' => Clients::destination($client['redirect_uris'][0]),
            'level' => in_array($level, Mcp::LEVELS, true) ? $level : 'read',
            'verified' => $verified,
            'resource' => $resource,
            'created' => time(),
            'access' => [],
            'refresh' => [],
            'previous' => [],
            'issued' => [],
            'rotated' => 0,
        ];

        $this->clients->markAuthorized($client['id']);
        $this->log('mcp.connected', sprintf('Connected "%s" with %s access', $grant['name'], $grant['level']), ['grant' => $id, 'client' => $client['id']], $userId);

        return $this->issue($grant, 'new');
    }

    private function issue(array $grant, string $mode): ?array
    {
        global $wpdb;

        $before = $grant;
        $access = wp_generate_password(40, false);
        $refresh = $mode === 'access' ? null : wp_generate_password(40, false);
        $accessRow = ['h' => $this->hash($access), 'e' => time() + self::ACCESS_TTL];
        $live = array_filter((array) $grant['access'], fn ($row) => (int) $row['e'] > time());

        if ($mode === 'rotate') {
            $grant['issued'] = array_slice([...(array) $grant['issued'], ...(array) $grant['refresh']], -self::HISTORY);
            $grant['previous'] = (array) $grant['refresh'];
            $grant['refresh'] = [$this->hash($refresh)];
            $grant['rotated'] = time();
        } elseif ($mode === 'join') {
            $grant['refresh'] = array_slice([...(array) $grant['refresh'], $this->hash($refresh)], -self::LIVE);
        } elseif ($mode === 'new') {
            $grant['refresh'] = [$this->hash($refresh)];
        }

        $grant['access'] = array_values(array_slice([...$live, $accessRow], -self::LIVE));
        $grant['expires'] = time() + self::REFRESH_TTL;
        $name = self::PREFIX.$grant['id'];

        if ($mode === 'new') {
            add_option($name, $grant, '', false);
        } else {
            $saved = $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($grant)], ['option_name' => $name, 'option_value' => maybe_serialize($before)]);
            wp_cache_delete($name, 'options');

            if ($saved !== 1) {
                return null;
            }
        }

        return array_filter([
            'access_token' => 'snapmcpa_'.$grant['id'].'_'.$access,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
            'refresh_token' => $refresh === null ? null : 'snapmcpr_'.$grant['id'].'_'.$refresh,
            'scope' => 'mcp:'.$grant['level'],
        ], fn ($value) => $value !== null);
    }

    private function stored(): array
    {
        global $wpdb;

        $grants = [];
        $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(self::PREFIX).'%'));

        foreach ($names as $name) {
            $grant = $this->get(substr((string) $name, strlen(self::PREFIX)));

            if ($grant !== null) {
                $grants[$grant['id']] = $grant;
            }
        }

        return $grants;
    }

    private function get(string $id): ?array
    {
        if (! preg_match('/^[a-f0-9]{12}$/', $id)) {
            return null;
        }

        $grant = get_option(self::PREFIX.$id);

        return is_array($grant) && isset($grant['access'], $grant['refresh']) ? $grant : null;
    }

    private function userAllowed(int $userId): bool
    {
        $user = get_userdata($userId);

        return $user !== false && user_can($user, Mcp::capability());
    }

    private function hash(string $secret): string
    {
        return hash_hmac('sha256', $secret, wp_salt('secure_auth'));
    }

    private function fail(string $code, string $message): WP_Error
    {
        return new WP_Error($code, $message, ['status' => 400]);
    }

    private function log(string $event, string $message, array $context, int $userId): void
    {
        if (function_exists('snap_security')) {
            snap_security()->log()->log($event, $message, $context, $userId);
        }
    }
}

<?php

namespace Snapshot\Mcp;

class Tokens
{
    public const OPTION = 'snapshot_mcp_tokens';

    public const USAGE = 'snapshot_mcp_token_usage';

    public const PREFIX = 'snapmcp_';

    public const PER_USER = 10;

    private const TOUCH_EVERY = 5 * MINUTE_IN_SECONDS;

    public function all(): array
    {
        $tokens = get_option(self::OPTION, []);

        return is_array($tokens) ? $tokens : [];
    }

    public function forUser(int $userId): array
    {
        $tokens = array_filter($this->all(), fn ($token) => (int) ($token['user'] ?? 0) === $userId);
        uasort($tokens, fn ($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));

        return $tokens;
    }

    public function create(int $userId, string $name, int $days): array
    {
        $tokens = $this->prune($this->all());

        if (count(array_filter($tokens, fn ($token) => (int) $token['user'] === $userId && (int) $token['expires'] > time())) >= self::PER_USER) {
            return ['error' => sprintf(__('Each person can hold %d tokens. Revoke one first.', 'snapshot-core'), self::PER_USER)];
        }

        $id = bin2hex(random_bytes(6));
        $secret = wp_generate_password(40, false);
        $name = mb_substr(sanitize_text_field($name), 0, 60) ?: __('MCP client', 'snapshot-core');
        $days = array_key_exists($days, snap_mcp_token_lifetimes()) ? $days : (int) array_key_first(snap_mcp_token_lifetimes());

        $tokens[$id] = [
            'user' => $userId,
            'name' => $name,
            'hash' => $this->hash($secret),
            'created' => time(),
            'expires' => time() + $days * DAY_IN_SECONDS,
            'verified' => Mcp::sessionVerified($userId),
        ];

        update_option(self::OPTION, $tokens, false);
        $this->log('mcp.token_created', sprintf('Created MCP token "%s", expires in %d days', $name, $days), ['token' => $id], $userId);

        return ['id' => $id, 'token' => self::PREFIX.$id.'_'.$secret, 'record' => $tokens[$id]];
    }

    public function revoke(string $id, ?int $userId = null, string $why = 'revoked'): bool
    {
        $tokens = $this->all();

        if (! isset($tokens[$id]) || ($userId !== null && (int) $tokens[$id]['user'] !== $userId)) {
            return false;
        }

        $token = $tokens[$id];
        unset($tokens[$id]);
        update_option(self::OPTION, $tokens, false);
        $usage = $this->usage();
        unset($usage[$id]);
        update_option(self::USAGE, $usage, false);
        $this->log('mcp.token_revoked', sprintf('Revoked MCP token "%s" (%s)', $token['name'], $why), ['token' => $id], (int) $token['user']);

        return true;
    }

    public function revokeUser(int $userId, string $why): int
    {
        $count = 0;

        foreach (array_keys($this->forUser($userId)) as $id) {
            $count += $this->revoke((string) $id, $userId, $why) ? 1 : 0;
        }

        return $count;
    }

    public function revokeUnverified(string $why): int
    {
        $count = 0;

        foreach ($this->all() as $id => $token) {
            if (empty($token['verified'])) {
                $count += $this->revoke((string) $id, null, $why) ? 1 : 0;
            }
        }

        return $count;
    }

    public function usage(): array
    {
        $usage = get_option(self::USAGE, []);

        return is_array($usage) ? $usage : [];
    }

    public function verify(string $token): ?array
    {
        if (! preg_match('/^'.self::PREFIX.'([a-f0-9]{12})_([A-Za-z0-9]{40})$/', $token, $parts)) {
            return null;
        }

        $record = $this->all()[$parts[1]] ?? null;

        if (! is_array($record) || ! hash_equals((string) $record['hash'], $this->hash($parts[2]))) {
            return null;
        }

        if ((int) $record['expires'] < time()) {
            return null;
        }

        $user = get_userdata((int) $record['user']);

        if (! $user || ! user_can($user, Mcp::capability())) {
            return null;
        }

        return ['kind' => 'token', 'id' => $parts[1], 'user' => (int) $record['user'], 'name' => $record['name'], 'level' => null, 'verified' => ! empty($record['verified'])];
    }

    public function touch(string $id, string $ip): void
    {
        $usage = $this->usage();

        if (time() - (int) ($usage[$id]['used'] ?? 0) < self::TOUCH_EVERY) {
            return;
        }

        $usage[$id] = ['used' => time(), 'ip' => $ip];
        update_option(self::USAGE, array_intersect_key($usage, $this->all()), false);
    }

    private function prune(array $tokens): array
    {
        return array_filter($tokens, fn ($token) => is_array($token) && (int) ($token['expires'] ?? 0) > time() - 30 * DAY_IN_SECONDS);
    }

    private function hash(string $secret): string
    {
        return hash_hmac('sha256', $secret, wp_salt('secure_auth'));
    }

    private function log(string $event, string $message, array $context, int $userId): void
    {
        if (function_exists('snap_security_log')) {
            snap_security_log($event, $message, $context + ['user' => $userId]);
        }
    }
}

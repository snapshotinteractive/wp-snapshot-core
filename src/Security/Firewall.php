<?php

namespace Snapshot\Security;

class Firewall
{
    private bool $suspect = false;

    private bool $inspected = false;

    private const SLICE_BYTES = 64 * KB_IN_BYTES;

    private const BUDGET_BYTES = MB_IN_BYTES;

    private int $budget = self::BUDGET_BYTES;

    private bool $uninspected = false;

    private int $tagBudget = 5000;

    private const MAX_JSON_BYTES = 2 * MB_IN_BYTES;

    private ?array $targets = null;

    public function __construct(
        private Settings $settings,
        private Ip $ip,
        private Blocklist $blocklist,
        private Throttle $throttle,
        private EventLog $log,
        private Responder $responder,
    ) {}

    public function inspect(): void
    {
        if ($this->inspected || $this->exempt()) {
            return;
        }

        $this->inspected = true;
        $ip = $this->ip->client();

        if (Ip::matches($ip, $this->settings->lines('allowlist'))) {
            return;
        }

        $mode = $this->settings->string('firewall_mode');
        $decision = $this->blocklist->decide($ip, $mode, $this->isLoginRequest());

        if ($decision['allow']) {
            return;
        }

        if ($decision['deny']) {
            $this->denyBlocked($decision['deny']);
        }

        $this->suspect = $decision['suspect'];

        if ($mode === 'off') {
            return;
        }

        $trustedEditor = defined('LOGGED_IN_COOKIE') && ! empty($_COOKIE[LOGGED_IN_COOKIE]) && is_user_logged_in() && current_user_can('edit_posts');

        foreach (snap_security_firewall_rules() as $id => $rule) {
            $always = ! empty($rule['always']);

            if ($trustedEditor && ! $always) {
                continue;
            }

            $match = $this->match($rule);

            if ($match === null) {
                continue;
            }

            $this->handleMatch($id, $rule, $match, $always || $mode === 'enforce', $ip);
        }

        if ($this->uninspected && ! $trustedEditor) {
            $this->handleMatch('oversized-request', ['label' => 'Request too large to inspect fully'], [
                'target' => 'body',
                'value' => 'More than '.size_format(self::BUDGET_BYTES).' of input, a single value over '.size_format(2 * self::SLICE_BYTES).', or thousands of tags',
            ], $mode === 'enforce', $ip);
        }
    }

    public function isSuspect(): bool
    {
        return $this->suspect;
    }

    public function isLoginRequest(): bool
    {
        $path = strtolower($this->path());

        return str_ends_with($path, '/wp-login.php') || str_ends_with($path, '/xmlrpc.php')
            || ($GLOBALS['pagenow'] ?? '') === 'wp-login.php';
    }

    public function path(): string
    {
        $path = (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?#');

        for ($i = 0; $i < 2 && str_contains($path, '%'); $i++) {
            $path = rawurldecode($path);
        }

        return '/'.ltrim((string) preg_replace('#[/\\\\]+#', '/', str_replace("\0", '', $path)), '/');
    }

    public function targets(): array
    {
        if ($this->targets !== null) {
            return $this->targets;
        }

        $path = $this->path();
        $home = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);

        if ($home !== '/' && $home !== '' && str_starts_with($path, rtrim($home, '/'))) {
            $path = substr($path, strlen(rtrim($home, '/'))) ?: '/';
        }

        $files = [];

        foreach ($_FILES as $file) {
            foreach ((array) ($file['name'] ?? []) as $name) {
                if (is_string($name) && $name !== '') {
                    $files[] = $name;
                }
            }
        }

        return $this->targets = [
            'path' => [$path],
            'query' => $this->flatten($_GET),
            'body' => [...$this->flatten($_POST), ...$this->flatten($this->jsonBody())],
            'cookies' => $this->flatten(array_filter(
                $_COOKIE,
                fn ($name) => ! preg_match('/^(?:wordpress_|wp-settings|wp_|comment_author_|snapshot_2fa)/', (string) $name),
                ARRAY_FILTER_USE_KEY
            )),
            'ua' => [(string) ($_SERVER['HTTP_USER_AGENT'] ?? '')],
            'files' => $files,
        ];
    }

    private function match(array $rule): ?array
    {
        $targets = $this->targets();

        foreach ((array) ($rule['targets'] ?? []) as $target) {
            foreach ($targets[$target] ?? [] as $value) {
                if ($value === '') {
                    continue;
                }

                $result = @preg_match($rule['pattern'], $value);

                if ($result === 0 && ! empty($rule['strip_sql_comments']) && preg_match('~/\*|--|#~', $value)) {
                    $result = @preg_match($rule['pattern'], $this->stripSqlComments($value));
                }

                if ($result === 1) {
                    return ['target' => $target, 'value' => mb_substr($value, 0, 200)];
                }

                if ($result === false) {
                    return ['target' => $target, 'value' => mb_substr($value, 0, 200), 'error' => preg_last_error_msg()];
                }
            }
        }

        return null;
    }

    private function jsonBody(): array
    {
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if (! str_contains($type, 'json')) {
            return [];
        }

        if ($length > self::MAX_JSON_BYTES) {
            $this->uninspected = true;

            return [];
        }

        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_JSON_BYTES + 1);

        if (strlen($raw) > self::MAX_JSON_BYTES) {
            $this->uninspected = true;

            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function handleMatch(string $id, array $rule, array $match, bool $block, string $ip): void
    {
        $context = [
            'rule' => $id,
            'target' => $match['target'],
            'sample' => $match['value'],
            'pcre_error' => $match['error'] ?? null,
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'uri' => mb_substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 300),
        ];

        if (! $block) {
            if ($this->throttle->once('fw-log:'.Ip::bucket($ip).":{$id}", 300)) {
                $this->log->log('firewall.rule_matched', $rule['label'] ?? $id, $context);
            }

            return;
        }

        $reference = $this->responder->reference();
        $context['reference'] = $reference;
        $bucket = Ip::bucket($ip);
        $hits = $this->throttle->hit("fw-hits:{$bucket}", 600);

        if ($hits === 1 || $hits % 10 === 0) {
            $this->log->log('firewall.rule_blocked', $rule['label'] ?? $id, $context + ['hits' => $hits]);
        }

        $limit = max(1, $this->settings->int('firewall_auto_block_hits'));

        if ($hits >= $limit && $this->ip->blockable($ip)) {
            $minutes = max(1, $this->settings->int('firewall_auto_block_minutes'));
            $this->blocklist->add($bucket, 'block', 'site', 'firewall', "Firewall: {$id}", $minutes * MINUTE_IN_SECONDS);
            $this->throttle->clear("fw-hits:{$bucket}");
            $this->log->log('firewall.auto_block', sprintf('Blocked for %d minutes after %d blocked requests', $minutes, $hits), $context);
            do_action('snap/security/ip_blocked', $ip, $minutes * MINUTE_IN_SECONDS, 'firewall', $id);
        }

        $this->responder->deny('rule', $reference);
    }

    private function denyBlocked(object $row): never
    {
        $reference = $this->responder->reference();
        $ip = $this->ip->client();

        if ($this->throttle->once('fw-blocked-log:'.Ip::bucket($ip), 600)) {
            $this->log->log('firewall.blocked', $row->reason !== '' ? $row->reason : $row->source, [
                'source' => $row->source,
                'scope' => $row->scope,
                'reference' => $reference,
                'uri' => mb_substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 300),
            ]);
        }

        $retry = $row->expires_at ? max(1, strtotime($row->expires_at.' UTC') - time()) : null;

        $this->responder->deny($row->source === 'lockout' && $row->scope === 'login' ? 'lockout' : 'blocked', $reference, $retry);
    }

    private function exempt(): bool
    {
        return (defined('WP_CLI') && WP_CLI)
            || PHP_SAPI === 'cli'
            || (defined('DOING_CRON') && DOING_CRON)
            || (defined('SNAPSHOT_SECURITY_DISABLE_FIREWALL') && SNAPSHOT_SECURITY_DISABLE_FIREWALL);
    }

    private function flatten($data, string $prefix = ''): array
    {
        $values = [];
        $skipped = snap_security_skipped_fields();

        foreach ((array) $data as $key => $value) {
            $key = (string) $key;

            if (in_array(strtolower($key), $skipped, true)) {
                continue;
            }

            if (is_array($value)) {
                $values = [...$values, ...$this->flatten($value, $key)];

                continue;
            }

            $value = is_scalar($value) ? (string) $value : '';

            if (strlen($value) > 2 * self::SLICE_BYTES) {
                $value = substr($value, 0, self::SLICE_BYTES)."\n".substr($value, -self::SLICE_BYTES);
                $this->uninspected = true;
            }

            $normalized = $this->normalize($value);
            $tags = substr_count($normalized, '<');

            if ($this->budget <= 0 || $tags > $this->tagBudget) {
                $this->uninspected = true;

                break;
            }

            $this->tagBudget -= $tags;
            $this->budget -= strlen($key) + strlen($normalized);
            $values[] = $this->normalize($key);
            $values[] = $normalized;
        }

        return $values;
    }

    private function normalize(string $value): string
    {
        $value = wp_unslash($value);

        for ($i = 0; $i < 2 && str_contains($value, '%'); $i++) {
            $value = rawurldecode($value);
        }

        return str_replace("\0", '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function stripSqlComments(string $value): string
    {
        $out = '';
        $i = 0;
        $length = strlen($value);

        while (($start = strpos($value, '/*', $i)) !== false) {
            $out .= substr($value, $i, $start - $i).' ';
            $i = $start + 2;

            if (($value[$i] ?? '') === '!') {
                $i++;

                while ($i < $length && ctype_digit($value[$i])) {
                    $i++;
                }

                continue;
            }

            $close = strpos($value, '*/', $i);
            $i = $close === false ? $length : $close + 2;
        }

        $lines = explode("\n", str_replace('*/', ' ', $out.substr($value, $i)));
        $last = count($lines) - 1;

        foreach ($lines as $index => $line) {
            $cut = array_filter([strpos($line, '--'), strpos($line, '#')], fn ($position) => $position !== false);

            if ($index < $last && $cut !== []) {
                $lines[$index] = substr($line, 0, min($cut));
            }
        }

        return implode(' ', $lines);
    }
}

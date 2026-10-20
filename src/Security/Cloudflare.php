<?php

namespace Snapshot\Security;

class Cloudflare
{
    private const API = 'https://api.cloudflare.com/client/v4';

    private const RANGES_OPTION = 'snapshot_security_cloudflare_ranges';

    private const RANGES_CHECKED = 'snapshot_security_cloudflare_ranges_at';

    private const COMMENT_PREFIX = 'snapshot exp=';

    public function __construct(
        private Settings $settings,
        private Throttle $throttle,
        private EventLog $log,
    ) {}

    public function boot(): void
    {
        add_action('snap/security/ip_blocked', [$this, 'push'], 10, 4);
    }

    public function pushEnabled(): bool
    {
        return $this->settings->bool('cloudflare_enabled')
            && $this->settings->bool('cloudflare_push_blocks')
            && $this->settings->has('cloudflare_account_id')
            && $this->settings->has('cloudflare_list_id')
            && $this->settings->has('cloudflare_api_token');
    }

    public function refreshRanges(bool $force = false): bool
    {
        if (! $this->settings->bool('cloudflare_enabled')) {
            return false;
        }

        if (! $force && (int) get_option(self::RANGES_CHECKED, 0) > time() - WEEK_IN_SECONDS) {
            return false;
        }

        $ranges = [];

        foreach (['https://www.cloudflare.com/ips-v4', 'https://www.cloudflare.com/ips-v6'] as $url) {
            $response = wp_remote_get($url, ['timeout' => 15]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                $this->log->log('cloudflare.failed', "Could not refresh the Cloudflare address ranges from {$url}");

                return false;
            }

            foreach (preg_split('/\s+/', trim(wp_remote_retrieve_body($response))) as $line) {
                if (Ip::range($line) !== null && str_contains($line, '/')) {
                    $ranges[] = $line;
                }
            }
        }

        if (count($ranges) < 10) {
            return false;
        }

        update_option(self::RANGES_OPTION, array_values(array_unique($ranges)), true);
        update_option(self::RANGES_CHECKED, time(), false);

        return true;
    }

    public function push(string $ip, int $seconds, string $source = '', string $reason = ''): void
    {
        if (! $this->pushEnabled() || ! $this->throttle->once("cf-push:{$ip}", 15 * MINUTE_IN_SECONDS)) {
            return;
        }

        wp_remote_post($this->itemsUrl(), [
            'timeout' => 5,
            'blocking' => false,
            'headers' => $this->headers(),
            'body' => wp_json_encode([[
                'ip' => Ip::bucket($ip),
                'comment' => mb_substr(self::COMMENT_PREFIX.(time() + $seconds)." {$source} {$reason}", 0, 480),
            ]]),
        ]);
    }

    public function prune(): int
    {
        if (! $this->pushEnabled()) {
            return 0;
        }

        $expired = [];
        $cursor = null;

        for ($page = 0; $page < 40; $page++) {
            $response = wp_remote_get(add_query_arg(array_filter(['per_page' => 500, 'cursor' => $cursor]), $this->itemsUrl()), [
                'timeout' => 20,
                'headers' => $this->headers(),
            ]);

            $body = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);

            if (! is_array($body) || empty($body['success'])) {
                $this->log->log('cloudflare.failed', 'Could not read the Cloudflare IP list');

                return 0;
            }

            foreach ((array) ($body['result'] ?? []) as $item) {
                $comment = (string) ($item['comment'] ?? '');

                if (str_starts_with($comment, self::COMMENT_PREFIX) && (int) substr($comment, strlen(self::COMMENT_PREFIX)) < time()) {
                    $expired[] = ['id' => (string) $item['id']];
                }
            }

            $cursor = $body['result_info']['cursors']['after'] ?? null;

            if (! $cursor) {
                break;
            }
        }

        if ($expired === []) {
            return 0;
        }

        foreach (array_chunk($expired, 500) as $chunk) {
            wp_remote_request($this->itemsUrl(), [
                'method' => 'DELETE',
                'timeout' => 20,
                'headers' => $this->headers(),
                'body' => wp_json_encode(['items' => $chunk]),
            ]);
        }

        return count($expired);
    }

    private function itemsUrl(): string
    {
        return sprintf(
            '%s/accounts/%s/rules/lists/%s/items',
            self::API,
            rawurlencode($this->settings->string('cloudflare_account_id')),
            rawurlencode($this->settings->string('cloudflare_list_id'))
        );
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->settings->string('cloudflare_api_token'),
            'Content-Type' => 'application/json',
        ];
    }
}

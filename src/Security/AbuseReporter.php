<?php

namespace Snapshot\Security;

class AbuseReporter
{
    private const URL = 'https://api.abuseipdb.com/api/v2/report';

    private const CATEGORIES = [
        'lockout' => '18,21',
        'firewall' => '21',
    ];

    public function __construct(
        private Settings $settings,
        private Throttle $throttle,
        private Ip $ip,
    ) {}

    public function boot(): void
    {
        add_action('snap/security/ip_blocked', [$this, 'report'], 20, 4);
    }

    public function enabled(): bool
    {
        return $this->settings->bool('abuseipdb_report') && $this->settings->has('abuseipdb_api_key');
    }

    public function report(string $ip, int $seconds, string $source = '', string $reason = ''): void
    {
        if (! $this->enabled() || ! $this->ip->blockable($ip) || ! $this->throttle->once("abuseipdb:{$ip}", 20 * MINUTE_IN_SECONDS)) {
            return;
        }

        $comment = $source === 'lockout'
            ? 'WordPress sign in brute force, repeated lockouts'
            : 'Web application attack blocked by firewall rule '.sanitize_key($reason);

        wp_remote_post(self::URL, [
            'timeout' => 5,
            'blocking' => false,
            'headers' => [
                'Key' => $this->settings->string('abuseipdb_api_key'),
                'Accept' => 'application/json',
            ],
            'body' => [
                'ip' => preg_replace('#/\d+$#', '', $ip),
                'categories' => self::CATEGORIES[$source] ?? '21',
                'comment' => $comment,
            ],
        ]);
    }
}

<?php

namespace Snapshot\Security;

use Snapshot\Core;
use Snapshot\Mcp\Mcp;
use WP_REST_Request;

class StatusEndpoint
{
    private const DENIALS_PER_HOUR = 20;

    public function __construct(private Shield $shield) {}

    public function boot(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route('snapshot/v1', '/security/status', [
                'methods' => 'GET',
                'permission_callback' => [$this, 'allowed'],
                'callback' => [$this, 'payload'],
            ]);
        });
    }

    public function allowed(WP_REST_Request $request): bool
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        $stored = $this->shield->settings()->string('status_token');
        $given = trim((string) $request->get_header('x_snapshot_security_token'));

        if ($stored === '' || $given === '') {
            return false;
        }

        $ip = $this->shield->ip()->client();

        if ($this->shield->throttle()->count("status-denied:{$ip}", HOUR_IN_SECONDS) >= self::DENIALS_PER_HOUR) {
            return false;
        }

        if (hash_equals($stored, hash('sha256', $given))) {
            return true;
        }

        $this->shield->throttle()->hit("status-denied:{$ip}", HOUR_IN_SECONDS);

        return false;
    }

    public function payload(): array
    {
        $shield = $this->shield;
        $scan = $shield->scanner()->last();
        $vulns = $shield->vulnerabilities()->last();

        return [
            'generated_at' => gmdate('c'),
            'site' => home_url('/'),
            'core' => ['version' => Core::version(), 'features' => array_combine(Core::FEATURES, array_map([Core::class, 'enabled'], Core::FEATURES))],
            'firewall_mode' => $shield->settings()->string('firewall_mode'),
            'cloudflare' => $shield->settings()->bool('cloudflare_enabled'),
            'two_factor' => $shield->posture()->enrollment(),
            'mcp' => [
                'enabled' => Mcp::enabled(),
                'access' => Mcp::siteAccess(),
                'tokens' => count(array_filter(snap_mcp()->tokens()->all(), fn ($token) => (int) $token['expires'] > time())),
                'connections' => count(snap_mcp()->grants()->all()),
                'oauth' => Mcp::oauth(),
            ],
            'events_24h' => $shield->log()->stats(gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)),
            'blocks' => $shield->blocklist()->counts(),
            'scan' => [
                'at' => isset($scan['at']) ? gmdate('c', $scan['at']) : null,
                'counts' => $scan['counts'] ?? null,
                'new' => $scan['new'] ?? null,
            ],
            'vulnerabilities' => array_map(fn ($item) => [
                'name' => $item['name'],
                'version' => $item['version'],
                'title' => $item['title'],
                'fixed_in' => $item['fixed_in'],
            ], $vulns['items'] ?? []),
            'feeds' => $shield->feeds()->status(),
            'checks' => $shield->posture()->checks(),
        ];
    }
}

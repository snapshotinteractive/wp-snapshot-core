<?php

namespace Snapshot\Security;

use Snapshot\Security\TwoFactor\Passkeys;
use Snapshot\Security\TwoFactor\Totp;
use Snapshot\Security\TwoFactor\TwoFactor;
use WP_User_Query;

class Posture
{
    private const MAX_ADMINS = 5;

    public function __construct(private Shield $shield) {}

    public function enrollment(): array
    {
        $total = (int) (count_users()['total_users'] ?? 0);
        $query = new WP_User_Query([
            'fields' => 'ID',
            'number' => 1,
            'count_total' => true,
            'meta_query' => [
                'relation' => 'OR',
                ['key' => Totp::META, 'compare' => 'EXISTS'],
                ['key' => Passkeys::META, 'compare' => 'EXISTS'],
            ],
        ]);
        $enrolled = (int) $query->get_total();

        return ['users' => $total, 'enrolled' => $enrolled, 'pending' => max(0, $total - $enrolled), 'bypassed' => TwoFactor::bypassed()];
    }

    public function checks(): array
    {
        global $wpdb;

        $settings = $this->shield->settings();
        $enrollment = $this->enrollment();
        $lastDaily = (int) get_option(Cron::LAST_DAILY, 0);
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        $modules = $this->shield->modules();
        $off = array_map(fn ($key) => snap_security_modules()[$key]['label'], $modules->off());

        $checks = [
            $this->check('modules', ! $modules->master() ? 'fail' : ($off === [] ? 'ok' : 'warn'), 'Security modules', ! $modules->master()
                ? 'Built-in security is switched off in the control panel. No module is running.'
                : ($off === [] ? 'All modules are on.' : 'Off: '.implode(', ', $off).'. Confirm another plugin covers each one.')),
            $this->check('https', str_starts_with(home_url(), 'https://') ? 'ok' : 'fail', 'HTTPS', 'WordPress Address and Site Address must use https:// so credentials and cookies are encrypted in transit.'),
            $this->check('two-factor', TwoFactor::emergencyOff() ? 'fail' : (TwoFactor::bypassed() || $enrollment['pending'] > 0 ? 'warn' : 'ok'), 'Two-factor enrollment', TwoFactor::emergencyOff()
                ? 'Two-factor authentication is disabled by SNAPSHOT_SECURITY_2FA_DISABLED in wp-config.php. Remove the constant.'
                : (TwoFactor::bypassed() ? 'Two-factor authentication is off, which is the default. Configure SMTP, then switch it on under Security, Control panel, or confirm another plugin enforces it.' : sprintf('%d of %d accounts enrolled. Remaining accounts must enroll at next login.', $enrollment['enrolled'], $enrollment['users']))),
            $this->check('keys', $this->shield->vault()->usingDefaultKeys() ? 'fail' : 'ok', 'Secret keys', 'Define unique AUTH_KEY and SECURE_AUTH_KEY values, or SNAPSHOT_SECURITY_KEY, in wp-config.php. These keys also encrypt stored authenticator secrets.'),
            $this->check('debug', (defined('WP_DEBUG') && WP_DEBUG && (! defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY)) ? 'fail' : 'ok', 'Error display', 'WP_DEBUG_DISPLAY must be false in production to prevent disclosure of file paths and queries.'),
            $this->check('file-edit', defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT ? 'ok' : 'warn', 'File editor', 'Set DISALLOW_FILE_EDIT to true to prevent PHP edits from the dashboard.'),
            $this->check('xmlrpc', $modules->on('hardening') ? 'ok' : 'warn', 'XML-RPC and application passwords', $modules->on('hardening') ? 'Disabled. Both bypass two-factor authentication.' : 'Available, because the Hardening module is off. Both bypass two-factor authentication unless another plugin disables them.'),
            $this->check('firewall', match ($settings->string('firewall_mode')) {
                'enforce' => 'ok',
                'learning' => 'warn',
                default => 'fail',
            }, 'Firewall mode', 'Learning mode blocks probes only. Review firewall events, then switch to Enforce.'),
            $this->check('admin-user', username_exists('admin') ? 'warn' : 'ok', 'Default username', username_exists('admin') ? 'An account named admin exists. It is the most frequently targeted username; replace it.' : 'No account uses the username admin.'),
            $this->check('admins', count(get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => self::MAX_ADMINS + 1])) > self::MAX_ADMINS ? 'warn' : 'ok', 'Administrator count', 'Limit administrator accounts to staff who require full access. Assign Editor for content management.'),
            $this->check('prefix', $wpdb->prefix === 'wp_' ? 'warn' : 'ok', 'Database table prefix', $wpdb->prefix === 'wp_' ? 'The default wp_ prefix is in use. Low priority: change it during the next migration.' : 'A custom table prefix is in use.'),
            $this->check('cron', $lastDaily > time() - 2 * DAY_IN_SECONDS ? 'ok' : 'warn', 'Scheduled tasks', $lastDaily ? 'Daily tasks last ran '.human_time_diff($lastDaily).' ago.' : 'Daily tasks have not run. If WP-Cron is disabled, schedule wp cron event run --due-now every five minutes.'),
            $this->check('php', version_compare(PHP_VERSION, '8.3', '>=') ? 'ok' : 'warn', 'PHP version', 'PHP '.PHP_VERSION.' is installed. 8.3 or later is required.'),
        ];

        $client = $this->shield->ip()->client();

        if (! (defined('WP_CLI') && WP_CLI) && Ip::valid($client) && ! wp_doing_cron()) {
            $blockable = $this->shield->ip()->blockable($client) || Ip::matches($client, $settings->lines('allowlist'));
            $checks[] = $this->check('client-ip', $blockable ? 'ok' : 'warn', 'Client IP detection', $blockable
                ? "Client IP resolved as {$client}."
                : "Client IP resolved as {$client}, a private, proxy or Cloudflare address. Configure trusted proxies or enable Cloudflare integration, otherwise login lockouts cannot apply.");
        }

        if ($settings->bool('cloudflare_enabled') && Ip::valid($remote)) {
            $viaEdge = Ip::matches($remote, $this->shield->ip()->cloudflareRanges());
            $checks[] = $this->check('cloudflare-origin', $viaEdge ? 'ok' : 'warn', 'Cloudflare origin', $viaEdge
                ? 'Requests arrive through the Cloudflare edge.'
                : 'This request bypassed Cloudflare. Restrict the origin firewall to Cloudflare IP ranges so edge rules cannot be bypassed.');
        }

        return snap_filter('snap/security/checks', $checks);
    }

    private function check(string $key, string $status, string $label, string $detail): array
    {
        return compact('key', 'status', 'label', 'detail');
    }
}

<?php

function snap_security_settings_schema(): array
{
    return snap_filter('snap/security/settings', [
        'security_enabled' => [
            'tab' => 'modules',
            'type' => 'bool',
            'default' => true,
            'label' => 'Built-in security',
            'help' => 'Master switch. Off disables every module at once.',
        ],
        'module_firewall' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => 'Firewall', 'help' => 'Request inspection and IP blocking.'],
        'module_login' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => 'Login protection', 'help' => 'Lockouts, account pauses, reset throttling and Turnstile.'],
        'module_twofactor' => ['tab' => 'modules', 'type' => 'bool', 'default' => false, 'label' => 'Two-factor authentication', 'help' => 'Off by default. When on, every account must sign in with a passkey, authenticator app, recovery code or emailed code as well as a password. Configure SMTP first. Switching it on signs out every session created while it was off, including your own, and each person enrolls at their next sign in.'],
        'module_passwords' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => 'Password policy', 'help' => 'Minimum length and breached password checks.'],
        'module_hardening' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => 'Hardening', 'help' => 'XML-RPC, application passwords, file editor and user enumeration.'],
        'module_headers' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => 'Security headers', 'help' => 'Response headers, Content-Security-Policy and HSTS.'],
        'module_feeds' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => 'Threat intelligence', 'help' => 'Daily IP feed sync.'],
        'module_alerts' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => 'Notifications', 'help' => 'Immediate alerts and the daily digest by email.'],
        'module_status' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => 'Status endpoint', 'help' => 'REST endpoint for external monitoring.'],
        'alert_recipients' => [
            'tab' => 'alerts',
            'type' => 'text',
            'default' => '',
            'label' => 'Alert recipients',
            'help' => 'Comma-separated email addresses. Defaults to the site administration email when empty.',
        ],
        'alert_digest' => [
            'tab' => 'alerts',
            'type' => 'bool',
            'default' => true,
            'label' => 'Daily digest',
            'help' => 'Sends a daily summary of warning and critical events. Critical events are always sent immediately.',
        ],
        'login_max_attempts' => [
            'tab' => 'login',
            'type' => 'int',
            'default' => 5,
            'min' => 2,
            'label' => 'Failed attempts per IP',
            'help' => 'Failed sign-ins permitted from one IP address within the counting window before a lockout.',
        ],
        'login_window' => [
            'tab' => 'login',
            'type' => 'int',
            'default' => 15,
            'min' => 1,
            'label' => 'Counting window (minutes)',
            'help' => 'Period over which failed sign-ins are counted.',
        ],
        'lockout_minutes' => [
            'tab' => 'login',
            'type' => 'int',
            'default' => 15,
            'min' => 1,
            'label' => 'Initial lockout (minutes)',
            'help' => 'Duration of the first lockout. Each further lockout of the same address within 24 hours doubles, up to the maximum.',
        ],
        'lockout_max_minutes' => [
            'tab' => 'login',
            'type' => 'int',
            'default' => 1440,
            'min' => 1,
            'label' => 'Maximum lockout (minutes)',
            'help' => 'Upper limit for escalating lockouts.',
        ],
        'repeat_lockouts_to_block' => [
            'tab' => 'login',
            'type' => 'int',
            'default' => 3,
            'min' => 1,
            'label' => 'Lockouts before site-wide block',
            'help' => 'Lockouts within 24 hours after which the address is blocked from the entire site for 24 hours.',
        ],
        'username_max_attempts' => [
            'tab' => 'login',
            'type' => 'int',
            'default' => 20,
            'min' => 3,
            'label' => 'Failed attempts per account',
            'help' => 'Failed sign-ins permitted per account per hour, across all addresses, before the account is temporarily paused. Password reset remains available.',
        ],
        'lock_reserved_usernames' => [
            'tab' => 'login',
            'type' => 'bool',
            'default' => true,
            'label' => 'Block common username guesses',
            'help' => 'Locks out an address immediately when it attempts a non-existent common username such as admin, administrator or root.',
        ],
        'twofactor_email_fallback' => [
            'tab' => 'twofactor',
            'type' => 'bool',
            'default' => true,
            'label' => 'Email code fallback',
            'help' => 'Permits a single-use code sent to the account email as an alternative second factor.',
        ],
        'twofactor_email_fallback_admins' => [
            'tab' => 'twofactor',
            'type' => 'bool',
            'default' => false,
            'label' => 'Email code fallback for privileged roles',
            'help' => 'Extends the email code fallback to accounts that can manage options, edit users or post unfiltered HTML (administrators and editors). Disabled by default: with it enabled, control of such a mailbox is sufficient to pass both factors.',
        ],
        'twofactor_enroll_verify_email' => [
            'tab' => 'twofactor',
            'type' => 'bool',
            'default' => true,
            'label' => 'Verify email before enrollment',
            'help' => 'Requires a code sent to the account email before a first second factor can be registered, so a compromised password alone cannot enroll an attacker device.',
        ],
        'password_min_score' => [
            'tab' => 'passwords',
            'type' => 'int',
            'default' => 3,
            'min' => 0,
            'max' => 4,
            'label' => 'Minimum password strength',
            'help' => 'zxcvbn score from 0 to 4. A score of 3 rejects dictionary words, keyboard patterns and short passwords.',
        ],
        'password_breach_check' => [
            'tab' => 'passwords',
            'type' => 'bool',
            'default' => true,
            'label' => 'Reject breached passwords',
            'help' => 'Checks new passwords against Have I Been Pwned using k-anonymity. Only the first five characters of the SHA-1 hash are transmitted.',
        ],
        'firewall_mode' => [
            'tab' => 'firewall',
            'type' => 'select',
            'default' => 'learning',
            'choices' => [
                'off' => 'Disabled',
                'learning' => 'Learning: log matches, block probes only',
                'enforce' => 'Enforce: block all matching requests',
            ],
            'label' => 'Firewall mode',
            'help' => 'Run in learning mode for at least a week, review matches under Events, then switch to enforce.',
        ],
        'firewall_auto_block_hits' => [
            'tab' => 'firewall',
            'type' => 'int',
            'default' => 10,
            'min' => 1,
            'label' => 'Blocked requests before auto-block',
            'help' => 'Blocked requests from one address within 10 minutes that trigger an automatic block.',
        ],
        'firewall_auto_block_minutes' => [
            'tab' => 'firewall',
            'type' => 'int',
            'default' => 60,
            'min' => 1,
            'label' => 'Auto-block duration (minutes)',
            'help' => 'Duration of an automatic block applied by the firewall.',
        ],
        'allowlist' => [
            'tab' => 'firewall',
            'type' => 'lines',
            'validate' => 'ip',
            'default' => '',
            'label' => 'Allowlisted addresses',
            'help' => 'One IP address or CIDR range per line. Allowlisted addresses bypass all checks except two-factor authentication.',
        ],
        'trusted_proxies' => [
            'tab' => 'firewall',
            'type' => 'lines',
            'validate' => 'ip',
            'default' => '',
            'label' => 'Trusted proxies',
            'help' => 'One IP address or CIDR range per line. X-Forwarded-For is honored only for requests from these addresses. Leave empty when the server reports the client address directly.',
        ],
        'cloudflare_enabled' => [
            'tab' => 'modules',
            'type' => 'bool',
            'default' => false,
            'label' => 'Cloudflare',
            'help' => 'Reads the client address from CF-Connecting-IP for requests originating from published Cloudflare ranges.',
        ],
        'turnstile_site_key' => [
            'tab' => 'cloudflare',
            'type' => 'text',
            'default' => '',
            'label' => 'Turnstile site key',
            'help' => 'Enables Cloudflare Turnstile on sign-in and password reset. Requires the secret key.',
        ],
        'turnstile_secret' => [
            'tab' => 'cloudflare',
            'type' => 'secret',
            'default' => '',
            'label' => 'Turnstile secret key',
            'help' => 'Stored in the database. Define SNAPSHOT_SECURITY_TURNSTILE_SECRET in wp-config.php to keep it out of the database.',
        ],
        'cloudflare_push_blocks' => [
            'tab' => 'cloudflare',
            'type' => 'bool',
            'default' => false,
            'label' => 'Push blocks to Cloudflare',
            'help' => 'Adds repeat offenders to the Cloudflare IP list below for enforcement at the edge by a WAF custom rule.',
        ],
        'cloudflare_account_id' => [
            'tab' => 'cloudflare',
            'type' => 'text',
            'default' => '',
            'label' => 'Cloudflare account ID',
            'help' => 'Shown on the account home page in the Cloudflare dashboard.',
        ],
        'cloudflare_list_id' => [
            'tab' => 'cloudflare',
            'type' => 'text',
            'default' => '',
            'label' => 'Cloudflare IP list ID',
            'help' => 'ID of a custom IP list. The free plan allows one list of up to 10,000 entries.',
        ],
        'cloudflare_api_token' => [
            'tab' => 'cloudflare',
            'type' => 'secret',
            'default' => '',
            'label' => 'Cloudflare API token',
            'help' => 'API token scoped to Account Filter Lists: Edit.',
        ],
        'feed_spamhaus' => [
            'tab' => 'feeds',
            'type' => 'bool',
            'default' => true,
            'label' => 'Spamhaus DROP',
            'help' => 'Hijacked and criminal netblocks. Matching addresses are blocked in enforce mode.',
        ],
        'feed_emerging_threats' => [
            'tab' => 'feeds',
            'type' => 'bool',
            'default' => true,
            'label' => 'Emerging Threats compromised hosts',
            'help' => 'Hosts observed attacking networks in recent days. Matching addresses are blocked in enforce mode.',
        ],
        'feed_blocklist_de' => [
            'tab' => 'feeds',
            'type' => 'bool',
            'default' => true,
            'label' => 'blocklist.de brute force',
            'help' => 'Addresses reported for sign-in brute force. Treated as suspect: sign-in limits are halved; site access is unaffected.',
        ],
        'feed_abuseipdb' => [
            'tab' => 'feeds',
            'type' => 'bool',
            'default' => false,
            'label' => 'AbuseIPDB blocklist',
            'help' => 'Requires an API key. Treated as suspect.',
        ],
        'abuseipdb_api_key' => [
            'tab' => 'feeds',
            'type' => 'secret',
            'default' => '',
            'label' => 'AbuseIPDB API key',
            'help' => 'Available under Account, API at abuseipdb.com.',
        ],
        'abuseipdb_min_confidence' => [
            'tab' => 'feeds',
            'type' => 'int',
            'default' => 90,
            'min' => 25,
            'max' => 100,
            'label' => 'AbuseIPDB minimum confidence',
            'help' => 'Imports only addresses at or above this abuse confidence score (25 to 100).',
        ],
        'abuseipdb_report' => [
            'tab' => 'feeds',
            'type' => 'bool',
            'default' => false,
            'label' => 'Report blocked addresses to AbuseIPDB',
            'help' => 'Reports each site-wide block to AbuseIPDB. Reporting raises the free API quota.',
        ],
        'headers_hsts' => [
            'tab' => 'headers',
            'type' => 'bool',
            'default' => true,
            'label' => 'Strict-Transport-Security',
            'help' => 'Instructs browsers to use HTTPS only for one year. Sent on HTTPS requests only.',
        ],
        'headers_csp' => [
            'tab' => 'headers',
            'type' => 'text',
            'default' => "object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self' https:",
            'label' => 'Content-Security-Policy',
            'help' => 'Enforced on every response. The default disables plugins, base tag injection and third-party framing without restricting scripts. Leave empty to omit the header.',
        ],
        'scan_enabled' => [
            'tab' => 'modules',
            'type' => 'bool',
            'default' => true,
            'label' => 'Daily file integrity scan',
            'help' => 'Compares core, plugin and theme files with published checksums or the recorded baseline, and checks uploads for executable files.',
        ],
        'vuln_enabled' => [
            'tab' => 'modules',
            'type' => 'bool',
            'default' => true,
            'label' => 'Daily vulnerability check',
            'help' => 'Checks installed core, plugin and theme versions against the WPVulnerability database.',
        ],
        'event_retention_days' => [
            'tab' => 'scanning',
            'type' => 'int',
            'default' => 90,
            'min' => 7,
            'label' => 'Event retention (days)',
            'help' => 'Audit and firewall events older than this are deleted nightly.',
        ],
        'status_token' => [
            'tab' => 'scanning',
            'type' => 'token',
            'default' => '',
            'label' => 'Status endpoint token',
            'help' => 'Authorizes monitoring services such as Snapshot-OS to read /wp-json/snapshot/v1/security/status with the X-Snapshot-Security-Token header. Only a SHA-256 hash is stored. To pin it in wp-config.php, define SNAPSHOT_SECURITY_STATUS_TOKEN as that hash.',
        ],
    ]);
}

function snap_security_modules(): array
{
    return snap_filter('snap/security/modules', [
        'firewall' => [
            'setting' => 'module_firewall',
            'label' => 'Firewall',
            'summary' => 'Inspects every request against the rule set and enforces IP blocks, lockouts and feed entries.',
            'section' => 'firewall',
        ],
        'login' => [
            'setting' => 'module_login',
            'label' => 'Login protection',
            'summary' => 'Address lockouts, account pauses, password reset throttling, generic login errors and Turnstile.',
            'section' => 'login',
        ],
        'twofactor' => [
            'setting' => 'module_twofactor',
            'label' => 'Two-factor authentication',
            'summary' => 'Passkeys, authenticator codes, recovery codes and emailed codes, required for every account.',
            'section' => 'twofactor',
            'confirm' => 'Disable two-factor authentication? Every account can then sign in with a password alone. Only continue if another plugin enforces two-factor authentication.',
        ],
        'passwords' => [
            'setting' => 'module_passwords',
            'label' => 'Password policy',
            'summary' => 'Minimum password length and rejection of passwords found in known breaches.',
            'section' => 'passwords',
        ],
        'hardening' => [
            'setting' => 'module_hardening',
            'label' => 'Hardening',
            'summary' => 'Disables XML-RPC, application passwords and the file editor, and closes user enumeration.',
            'section' => null,
        ],
        'headers' => [
            'setting' => 'module_headers',
            'label' => 'Security headers',
            'summary' => 'Sends X-Frame-Options, Referrer-Policy, Permissions-Policy, Content-Security-Policy and HSTS.',
            'section' => 'headers',
        ],
        'feeds' => [
            'setting' => 'module_feeds',
            'label' => 'Threat intelligence',
            'summary' => 'Syncs IP blocklists daily. Off removes every feed entry from the blocklist.',
            'section' => 'feeds',
        ],
        'cloudflare' => [
            'setting' => 'cloudflare_enabled',
            'label' => 'Cloudflare',
            'summary' => 'Real client IP from Cloudflare, Turnstile on login, and edge blocks through a Cloudflare list.',
            'section' => 'cloudflare',
        ],
        'scanning' => [
            'setting' => 'scan_enabled',
            'label' => 'File integrity scan',
            'summary' => 'Daily comparison of core, plugin and theme files with checksums or the recorded baseline.',
            'section' => 'scanning',
        ],
        'vulns' => [
            'setting' => 'vuln_enabled',
            'label' => 'Vulnerability check',
            'summary' => 'Daily check of installed versions against the WPVulnerability database.',
            'section' => 'scanning',
        ],
        'alerts' => [
            'setting' => 'module_alerts',
            'label' => 'Notifications',
            'summary' => 'Emails critical events immediately and sends the daily digest.',
            'section' => 'alerts',
        ],
        'status' => [
            'setting' => 'module_status',
            'label' => 'Status endpoint',
            'summary' => 'Token-protected REST endpoint that reports posture to an external monitor.',
            'section' => 'alerts',
        ],
    ]);
}

function snap_security_overlapping_plugins(): array
{
    return snap_filter('snap/security/overlapping_plugins', [
        'wordfence/wordfence.php' => ['label' => 'Wordfence', 'modules' => ['firewall', 'login', 'twofactor', 'feeds', 'scanning', 'vulns', 'alerts']],
        'better-wp-security/better-wp-security.php' => ['label' => 'Solid Security', 'modules' => ['firewall', 'login', 'twofactor', 'passwords', 'hardening', 'scanning', 'vulns']],
        'ithemes-security-pro/ithemes-security-pro.php' => ['label' => 'Solid Security Pro', 'modules' => ['firewall', 'login', 'twofactor', 'passwords', 'hardening', 'scanning', 'vulns']],
        'all-in-one-wp-security-and-firewall/wp-security.php' => ['label' => 'All-In-One Security', 'modules' => ['firewall', 'login', 'twofactor', 'hardening', 'scanning']],
        'sucuri-scanner/sucuri.php' => ['label' => 'Sucuri Security', 'modules' => ['hardening', 'scanning', 'alerts']],
        'wp-defender/wp-defender.php' => ['label' => 'Defender', 'modules' => ['firewall', 'login', 'twofactor', 'hardening', 'headers', 'scanning']],
        'defender-security/wp-defender.php' => ['label' => 'Defender', 'modules' => ['firewall', 'login', 'twofactor', 'hardening', 'headers', 'scanning']],
        'wp-simple-firewall/icwp-wpsf.php' => ['label' => 'Shield Security', 'modules' => ['firewall', 'login', 'twofactor', 'scanning']],
        'wp-cerber/wp-cerber.php' => ['label' => 'WP Cerber', 'modules' => ['firewall', 'login', 'hardening', 'scanning']],
        'patchstack/patchstack.php' => ['label' => 'Patchstack', 'modules' => ['firewall', 'vulns']],
        'jetpack-protect/jetpack-protect.php' => ['label' => 'Jetpack Protect', 'modules' => ['firewall', 'scanning', 'vulns']],
        'two-factor/two-factor.php' => ['label' => 'Two Factor', 'modules' => ['twofactor']],
        'wp-2fa/wp-2fa.php' => ['label' => 'WP 2FA', 'modules' => ['twofactor']],
        'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php' => ['label' => 'Limit Login Attempts Reloaded', 'modules' => ['login']],
        'loginizer/loginizer.php' => ['label' => 'Loginizer', 'modules' => ['login']],
        'simple-cloudflare-turnstile/simple-cloudflare-turnstile.php' => ['label' => 'Simple Cloudflare Turnstile', 'modules' => ['login']],
        'headers-security-advanced-hsts-wp/headers-security-advanced-hsts-wp.php' => ['label' => 'Headers Security Advanced & HSTS', 'modules' => ['headers']],
    ]);
}

function snap_security_settings_tabs(): array
{
    return snap_filter('snap/security/settings_tabs', [
        'login' => 'Login security',
        'twofactor' => 'Two-factor authentication',
        'passwords' => 'Password policy',
        'firewall' => 'Firewall',
        'feeds' => 'Threat intelligence',
        'cloudflare' => 'Cloudflare',
        'headers' => 'Security headers',
        'scanning' => 'Scanning and retention',
        'alerts' => 'Notifications',
    ]);
}

function snap_security_event_types(): array
{
    return snap_filter('snap/security/event_types', [
        'login.success' => ['severity' => 'info', 'label' => 'Login succeeded'],
        'login.logout' => ['severity' => 'info', 'label' => 'Logout'],
        'login.failed' => ['severity' => 'notice', 'label' => 'Login failed'],
        'login.lockout' => ['severity' => 'warning', 'label' => 'IP locked out of login'],
        'login.user_paused' => ['severity' => 'warning', 'label' => 'Username locked after repeated failures'],
        'login.ip_blocked' => ['severity' => 'critical', 'label' => 'IP blocked after repeated lockouts'],
        'login.reserved_username' => ['severity' => 'warning', 'label' => 'Login attempt with reserved username'],
        'login.reset_requested' => ['severity' => 'info', 'label' => 'Password reset requested'],
        'login.session_revoked' => ['severity' => 'notice', 'label' => 'Session without two-factor revoked'],
        'twofactor.success' => ['severity' => 'info', 'label' => 'Two-factor verification succeeded'],
        'twofactor.failed' => ['severity' => 'notice', 'label' => 'Two-factor code rejected'],
        'twofactor.abandoned' => ['severity' => 'warning', 'label' => 'Two-factor challenge aborted after too many failures'],
        'twofactor.enrolled' => ['severity' => 'notice', 'label' => 'Two-factor method enrolled'],
        'twofactor.removed' => ['severity' => 'warning', 'label' => 'Two-factor method removed'],
        'twofactor.recovery_used' => ['severity' => 'warning', 'label' => 'Recovery code used'],
        'twofactor.recovery_regenerated' => ['severity' => 'notice', 'label' => 'Recovery codes regenerated'],
        'twofactor.locked' => ['severity' => 'warning', 'label' => 'Two-factor attempts locked for account'],
        'twofactor.reset' => ['severity' => 'critical', 'label' => 'Two-factor reset by administrator'],
        'twofactor.email_sent' => ['severity' => 'info', 'label' => 'Email verification code sent'],
        'twofactor.vault_unreadable' => ['severity' => 'critical', 'label' => 'Authenticator secret could not be decrypted'],
        'user.created' => ['severity' => 'notice', 'label' => 'User created'],
        'user.deleted' => ['severity' => 'warning', 'label' => 'User deleted'],
        'user.role_changed' => ['severity' => 'warning', 'label' => 'User role changed'],
        'user.admin_granted' => ['severity' => 'critical', 'label' => 'Administrator role granted'],
        'user.email_changed' => ['severity' => 'warning', 'label' => 'User email changed'],
        'user.password_changed' => ['severity' => 'notice', 'label' => 'Password changed'],
        'user.password_rejected' => ['severity' => 'info', 'label' => 'Weak or breached password rejected'],
        'plugin.activated' => ['severity' => 'warning', 'label' => 'Plugin activated'],
        'plugin.deactivated' => ['severity' => 'warning', 'label' => 'Plugin deactivated'],
        'plugin.deleted' => ['severity' => 'warning', 'label' => 'Plugin deleted'],
        'software.updated' => ['severity' => 'notice', 'label' => 'Software installed or updated'],
        'theme.switched' => ['severity' => 'critical', 'label' => 'Theme switched: built-in security disabled'],
        'option.changed' => ['severity' => 'warning', 'label' => 'Sensitive setting changed'],
        'content.deleted' => ['severity' => 'notice', 'label' => 'Published content deleted'],
        'security.module_changed' => ['severity' => 'warning', 'label' => 'Security module switched on or off'],
        'security.settings_changed' => ['severity' => 'warning', 'label' => 'Security settings changed'],
        'security.block_added' => ['severity' => 'notice', 'label' => 'IP rule added manually'],
        'security.block_removed' => ['severity' => 'notice', 'label' => 'IP rule removed'],
        'security.baseline_accepted' => ['severity' => 'warning', 'label' => 'File baseline updated'],
        'firewall.blocked' => ['severity' => 'notice', 'label' => 'Request from blocked IP'],
        'firewall.rule_blocked' => ['severity' => 'warning', 'label' => 'Request blocked by a firewall rule'],
        'firewall.rule_matched' => ['severity' => 'notice', 'label' => 'Firewall rule matched in learning mode'],
        'firewall.auto_block' => ['severity' => 'critical', 'label' => 'IP auto-blocked by firewall'],
        'hardening.enumeration' => ['severity' => 'notice', 'label' => 'User enumeration attempt'],
        'feeds.synced' => ['severity' => 'info', 'label' => 'Threat feed updated'],
        'feeds.failed' => ['severity' => 'warning', 'label' => 'Threat feed update failed'],
        'cloudflare.failed' => ['severity' => 'warning', 'label' => 'Cloudflare request failed'],
        'turnstile.unavailable' => ['severity' => 'warning', 'label' => 'Turnstile unavailable'],
        'scan.completed' => ['severity' => 'info', 'label' => 'File scan completed'],
        'scan.findings' => ['severity' => 'critical', 'label' => 'File scan reported new findings'],
        'vuln.found' => ['severity' => 'critical', 'label' => 'Vulnerable software installed'],
    ]);
}

function snap_security_severities(): array
{
    return snap_filter('snap/security/severities', ['info' => 0, 'notice' => 1, 'warning' => 2, 'critical' => 3]);
}

function snap_security_firewall_rules(): array
{
    return snap_filter('snap/security/rules', [
        'probe-sensitive-file' => [
            'label' => 'Probe for a configuration, backup or repository file',
            'targets' => ['path'],
            'pattern' => '~/(?:\.env(?:\.[\w-]+)?$|\.git/|\.svn/|\.hg/|\.ds_store$|\.htpasswd$|wp-config\.php[^/]+$|wp-config\.(?:bak|old|txt|save|orig)$|phpinfo\.php$|(?:debug|error)[._]log$|[\w-]+\.sql(?:\.gz|\.zip)?$|(?:backup|dump|db)\.(?:zip|tar|tgz|gz|rar|7z)$)~i',
            'always' => true,
        ],
        'probe-shell' => [
            'label' => 'Probe for a known web shell or installer',
            'targets' => ['path'],
            'pattern' => '~/(?:wso|alfa|c99|r57|b374k|shell|webshell|indoxploit|adminer|filemanager|wp-file-manager)(?:-?\d*)?\.php$~i',
            'always' => true,
        ],
        'xmlrpc' => [
            'label' => 'XML-RPC request',
            'targets' => ['path'],
            'pattern' => '~^/xmlrpc\.php~i',
            'always' => true,
        ],
        'traversal' => [
            'label' => 'Path traversal or local file inclusion',
            'targets' => ['path', 'query', 'body', 'cookies'],
            'pattern' => '~(?:\.\.[/\\\\]){2,}|/etc/(?:passwd|shadow|hosts)\b|proc/self/(?:environ|cmdline)|(?:php|phar|expect|zip|glob|data)://~i',
        ],
        'sql-injection' => [
            'label' => 'SQL injection',
            'targets' => ['query', 'body', 'cookies'],
            'strip_sql_comments' => true,
            'pattern' => '~(?<![a-z])union[\s(+]*+(?:(?:all|distinct)\b[\s(+]*+)?select\b(?![\s(]++(?!(?:null|user|version|database|schema|current_user|system_user|session_user|char|concat\w*|group_concat|cast|hex|unhex|load_file)\b)[a-z]++(?:\s*+(?:$|[.!?<])|\s++(?!from\b|into\b)[a-z]++\b(?![^;]{0,300}?\bfrom\b)))|\bfrom\s++information_schema\b|(?:[\'")\-+*/,=|&^]|\b(?:and|or|xor|having|where|select)\b)[\s(]*+(?:sleep|benchmark|pg_sleep)\s*+\(\s*+\d++\s*+[,)]|\bwaitfor\s++delay\s++\'|\bload_file\s*+\(|\binto\s++(?:out|dump)file\b|\b(?:extractvalue|updatexml)\s*+\(|[\'"]\s*+(?:or|and|xor)\s++[\'"]?(\w++)[\'"]?\s*+(?:=|<>?|>)\s*+[\'"]?\1\b|\b(?:or|xor)\s++(\d++)\s*+=\s*+\2\b|[\'"]\s*+(?:or|xor)\s++\d++\s*+[<>]=?\s*+\d~i',
        ],
        'cross-site-scripting' => [
            'label' => 'Cross site scripting',
            'targets' => ['query', 'body', 'cookies'],
            'pattern' => '~<\s*+script\b|\b(?:href|src|action|formaction|xlink:href|data)\s*+=\s*+[\'"]?\s*+j\s*+a\s*+v\s*+a\s*+s\s*+c\s*+r\s*+i\s*+p\s*+t\s*+:|<\s*+[a-z][^>]{0,200}?[\s"\'/]on(?!(?:line|board\w*|set|wards?|going|ion\w*|ly|ce|to|ward)\b)[a-z]{3,}\s*+=|<\s*+[a-z][^<]{0,1000}?[\s"\'/]on(?!(?:line|board\w*|set|wards?|going|ion\w*|ly|ce|to|ward)\b)[a-z]{3,}\s*+=|<\s*+(?:iframe|object|embed)\b~i',
        ],
        'code-injection' => [
            'label' => 'Code or command injection',
            'targets' => ['query', 'body', 'cookies', 'ua'],
            'pattern' => '~\$\{\s*jndi\s*:|<\?php|\b(?:shell_exec|passthru|proc_open|popen|pcntl_exec)\s*\(|\b(?:system|exec|assert|eval)\s*\(\s*[\'"$]|\bbase64_decode\s*\(|[;|`]\s*(?:wget|curl)\s+-?\S*\s*https?://~i',
        ],
        'scanner-user-agent' => [
            'label' => 'Known attack tool',
            'targets' => ['ua'],
            'pattern' => '~sqlmap|nikto|masscan|wpscan|acunetix|nessus|dirbuster|gobuster|nuclei|zgrab|havij|fimap|w3af|netsparker|jorgee|\bzmeu\b~i',
        ],
        'executable-upload' => [
            'label' => 'Upload of an executable file',
            'targets' => ['files'],
            'pattern' => '~\.(?:php\d?|phtml|phar|pht|phps|shtml|cgi)(?:\.|$)|^\.ht~i',
        ],
    ]);
}

function snap_security_skipped_fields(): array
{
    return snap_filter('snap/security/skipped_fields', [
        'pwd', 'pass', 'pass1', 'pass2', 'password', 'password_confirm', 'user_pass', 'post_password',
        'snapshot_2fa_code', 'snapshot_2fa_credential', 'cf-turnstile-response', 's',
    ]);
}

function snap_security_headers(): array
{
    return snap_filter('snap/security/headers', [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), browsing-topics=()',
        'Cross-Origin-Opener-Policy' => 'same-origin-allow-popups',
    ]);
}

function snap_security_feeds(): array
{
    return snap_filter('snap/security/feeds', [
        'spamhaus_v4' => [
            'label' => 'Spamhaus DROP, IPv4',
            'setting' => 'feed_spamhaus',
            'url' => 'https://www.spamhaus.org/drop/drop_v4.json',
            'format' => 'ndjson-cidr',
            'action' => 'block',
            'credit' => 'Spamhaus DROP data (c) The Spamhaus Project SLU, used under https://www.spamhaus.org/drop/terms/',
        ],
        'spamhaus_v6' => [
            'label' => 'Spamhaus DROP, IPv6',
            'setting' => 'feed_spamhaus',
            'url' => 'https://www.spamhaus.org/drop/drop_v6.json',
            'format' => 'ndjson-cidr',
            'action' => 'block',
            'credit' => 'Spamhaus DROP data (c) The Spamhaus Project SLU, used under https://www.spamhaus.org/drop/terms/',
        ],
        'emerging_threats' => [
            'label' => 'Emerging Threats compromised hosts',
            'setting' => 'feed_emerging_threats',
            'url' => 'https://rules.emergingthreats.net/blockrules/compromised-ips.txt',
            'format' => 'lines',
            'action' => 'block',
            'credit' => 'Proofpoint Emerging Threats open ruleset',
        ],
        'blocklist_de' => [
            'label' => 'blocklist.de brute force logins',
            'setting' => 'feed_blocklist_de',
            'url' => 'https://lists.blocklist.de/lists/bruteforcelogin.txt',
            'format' => 'lines',
            'action' => 'suspect',
            'credit' => 'blocklist.de',
        ],
        'abuseipdb' => [
            'label' => 'AbuseIPDB blocklist',
            'setting' => 'feed_abuseipdb',
            'url' => 'https://api.abuseipdb.com/api/v2/blacklist?plaintext=1&limit=10000&confidenceMinimum={confidence}',
            'format' => 'lines',
            'action' => 'suspect',
            'key_setting' => 'abuseipdb_api_key',
            'credit' => 'AbuseIPDB',
        ],
    ]);
}

function snap_security_feed_max_entries(): int
{
    return (int) snap_filter('snap/security/feed_max_entries', 60000);
}

function snap_security_cloudflare_ranges(): array
{
    return snap_filter('snap/security/cloudflare_ranges', [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ]);
}

function snap_security_reserved_usernames(): array
{
    return snap_filter('snap/security/reserved_usernames', [
        'admin', 'administrator', 'root', 'test', 'user', 'demo', 'wordpress', 'wp',
        'webmaster', 'support', 'manager', 'guest', 'info', 'sysadmin', 'superadmin',
    ]);
}

function snap_security_watched_options(): array
{
    return snap_filter('snap/security/watched_options', [
        'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role',
        'blog_public', 'permalink_structure', 'snapshot_security_cloudflare_ranges',
    ]);
}

function snap_security_executable_extensions(): array
{
    return snap_filter('snap/security/executable_extensions', [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'shtml', 'cgi', 'pl',
    ]);
}

function snap_security_known_dropins(): array
{
    return snap_filter('snap/security/known_dropins', [
        'index.php', 'advanced-cache.php', 'object-cache.php', 'db.php', 'db-error.php',
        'install.php', 'maintenance.php', 'php-error.php', 'fatal-error-handler.php',
        'sunrise.php', 'blog-deleted.php', 'blog-inactive.php', 'blog-suspended.php',
    ]);
}

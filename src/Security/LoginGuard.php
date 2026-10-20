<?php

namespace Snapshot\Security;

use Snapshot\Core;
use Throwable;
use WP_Error;
use WP_User;

class LoginGuard
{
    private const CREDENTIAL_CODES = ['invalid_username', 'incorrect_password', 'invalid_email'];

    private const RESETS_PER_ADDRESS = 5;

    private const RESETS_PER_ACCOUNT = 3;

    public function __construct(
        private Settings $settings,
        private Ip $ip,
        private Blocklist $blocklist,
        private Throttle $throttle,
        private EventLog $log,
        private Firewall $firewall,
    ) {}

    public function boot(bool $protect = true): void
    {
        if ($protect) {
            add_filter('authenticate', [$this, 'genericErrors'], 98, 1);
            add_filter('authenticate', [$this, 'enforceLocks'], 99, 2);
            add_action('wp_login_failed', [$this, 'failed'], 10, 2);
            add_action('snap/security/signed_in', fn ($user) => $this->succeeded($user->user_login, $user));
            add_action('after_password_reset', fn ($user) => $user instanceof WP_User ? $this->succeeded($user->user_login, $user) : null);
            add_action('lostpassword_post', [$this, 'throttleReset'], 1, 2);
            add_filter('lostpassword_errors', [$this, 'hideUnknownAccounts'], 10, 2);
        }

        add_action('login_enqueue_scripts', fn () => function_exists('App\\enqueue_font_stylesheets') ? \App\enqueue_font_stylesheets() : null);
        add_action('login_footer', [$this, 'brandScripts']);
    }

    public function genericErrors($user)
    {
        if (! is_wp_error($user)) {
            return $user;
        }

        $code = $user->get_error_code();

        if (! in_array($code, self::CREDENTIAL_CODES, true)) {
            return $user;
        }

        return new WP_Error(
            'snapshot_invalid_credentials',
            __('That username, email address or password is not right.', 'snapshot-core'),
            ['reason' => $code]
        );
    }

    public function enforceLocks($user, $username)
    {
        $ip = $this->ip->client();

        if ($this->allowed($ip)) {
            return $user;
        }

        if ($this->blocklist->decide($ip, $this->settings->string('firewall_mode'), true)['deny']) {
            return new WP_Error('snapshot_locked', __('Sign in from your network is paused after too many attempts. Try again later.', 'snapshot-core'));
        }

        $key = $this->userKey($username);

        if ($key !== '' && $this->throttle->count("login:user:{$key}", HOUR_IN_SECONDS) >= $this->settings->int('username_max_attempts')) {
            return new WP_Error('snapshot_paused', __('Sign in for this account is paused after too many failed attempts. Reset your password or try again in an hour.', 'snapshot-core'));
        }

        return $user;
    }

    public function failed($username, $error = null): void
    {
        $code = $error instanceof WP_Error ? $error->get_error_code() : 'unknown';

        if (in_array($code, ['snapshot_locked', 'snapshot_paused'], true)) {
            return;
        }

        $data = $error instanceof WP_Error ? $error->get_error_data() : null;
        $this->registerFailure((string) $username, is_array($data) && isset($data['reason']) ? $data['reason'] : $code);
    }

    public function registerFailure(string $username, string $reason): void
    {
        $ip = $this->ip->client();

        if ($this->allowed($ip)) {
            return;
        }

        $window = max(1, $this->settings->int('login_window')) * MINUTE_IN_SECONDS;
        $max = max(2, $this->settings->int('login_max_attempts'));

        if ($this->firewall->isSuspect()) {
            $max = max(2, intdiv($max, 2));
        }

        $bucket = Ip::bucket($ip);
        $ipHits = $this->throttle->hit("login:ip:{$bucket}", $window);
        $key = $this->userKey($username);
        $userHits = $key !== '' ? $this->throttle->hit("login:user:{$key}", HOUR_IN_SECONDS) : 0;

        $this->log->log('login.failed', sprintf('Failed sign in, attempt %d of %d', $ipHits, $max), [
            'reason' => $reason,
            'suspect' => $this->firewall->isSuspect(),
        ], 0, mb_substr($username, 0, 60));

        if ($key !== '' && $this->isReservedGuess($key)) {
            $this->log->log('login.reserved_username', "Tried the username {$key}", [], 0, $key);
            $this->lockout($ip, 'guessed a common username');

            return;
        }

        if ($ipHits >= $max) {
            $this->lockout($ip, "{$ipHits} failed sign ins");
        }

        if ($key !== '' && $userHits >= $this->settings->int('username_max_attempts') && $this->throttle->once("login:paused-log:{$key}", HOUR_IN_SECONDS)) {
            $this->log->log('login.user_paused', "Sign in paused for {$key} after {$userHits} failures in an hour", [], 0, $key);
        }
    }

    public function lockout(string $ip, string $why): void
    {
        if (! Ip::valid($ip)) {
            return;
        }

        $bucket = Ip::bucket($ip);

        if (! $this->ip->blockable($ip)) {
            if (! $this->throttle->once("lockout-skipped:{$bucket}", HOUR_IN_SECONDS)) {
                return;
            }

            $this->log->log('login.lockout', "Not locked out: {$why}. The address is private, a proxy or a Cloudflare edge, so blocking it would lock out everyone behind it. Check the visitor address settings.", ['blocked' => false], 0);

            return;
        }

        $count = $this->throttle->hit("lockouts:{$bucket}", DAY_IN_SECONDS);
        $base = max(1, $this->settings->int('lockout_minutes'));
        $minutes = (int) min(max($base, $this->settings->int('lockout_max_minutes')), $base * (2 ** min(10, $count - 1)));

        $this->blocklist->add($bucket, 'block', 'login', 'lockout', "Sign in: {$why}", $minutes * MINUTE_IN_SECONDS);
        $this->throttle->clear("login:ip:{$bucket}");

        $this->log->log('login.lockout', sprintf('Locked out of sign in for %d minutes: %s', $minutes, $why), [
            'minutes' => $minutes,
            'lockouts_today' => $count,
        ], 0);

        if ($count >= max(1, $this->settings->int('repeat_lockouts_to_block'))) {
            $this->blocklist->add($bucket, 'block', 'site', 'lockout', 'Repeated sign in lockouts', DAY_IN_SECONDS);
            $this->log->log('login.ip_blocked', sprintf('Blocked from the whole site for 24 hours after %d lockouts today', $count), [], 0);
            do_action('snap/security/ip_blocked', $ip, DAY_IN_SECONDS, 'lockout', 'login');
        }
    }

    public function succeeded($login, $user = null): void
    {
        $this->throttle->clear('login:ip:'.Ip::bucket($this->ip->client()));
        $this->throttle->clear('login:user:'.$this->userKey($user instanceof WP_User ? $user->user_login : (string) $login));
    }

    public function throttleReset($errors, $userData): void
    {
        if (($GLOBALS['pagenow'] ?? '') !== 'wp-login.php') {
            return;
        }

        $ip = $this->ip->client();
        $ipHits = $this->throttle->hit('lostpw:ip:'.Ip::bucket($ip), HOUR_IN_SECONDS);
        $userHits = $userData instanceof WP_User ? $this->throttle->hit("lostpw:user:{$userData->ID}", HOUR_IN_SECONDS) : 0;

        if ($userData instanceof WP_User && $userHits <= self::RESETS_PER_ACCOUNT && $ipHits <= self::RESETS_PER_ADDRESS) {
            $this->log->log('login.reset_requested', '', ['attempt' => $userHits], $userData->ID);
        }

        if (($ipHits > self::RESETS_PER_ADDRESS || $userHits > self::RESETS_PER_ACCOUNT) && ! $this->allowed($ip)) {
            $this->confirmReset();
        }
    }

    public function hideUnknownAccounts($errors, $userData)
    {
        if (($GLOBALS['pagenow'] ?? '') !== 'wp-login.php' || ! $errors instanceof WP_Error) {
            return $errors;
        }

        $codes = $errors->get_error_codes();

        if ($userData === false || array_intersect($codes, ['invalid_email', 'invalidcombo'])) {
            $this->confirmReset();
        }

        return $errors;
    }

    public function brandScripts(): void
    {
        try {
            echo Core::assets(['resources/js/security.js']);
        } catch (Throwable) {
        }
    }

    private function confirmReset(): never
    {
        wp_safe_redirect(add_query_arg('checkemail', 'confirm', site_url('wp-login.php', 'login')));
        exit;
    }

    private function isReservedGuess(string $key): bool
    {
        return $this->settings->bool('lock_reserved_usernames')
            && in_array($key, snap_security_reserved_usernames(), true)
            && ! username_exists($key)
            && ! email_exists($key);
    }

    private function allowed(string $ip): bool
    {
        if (Ip::matches($ip, $this->settings->lines('allowlist'))) {
            return true;
        }

        return $this->blocklist->decide($ip, $this->settings->string('firewall_mode'), true)['allow'];
    }

    private function userKey(string $username): string
    {
        $username = trim(wp_unslash($username));

        if (is_email($username)) {
            $user = get_user_by('email', $username);

            if ($user) {
                return strtolower($user->user_login);
            }
        }

        return strtolower(sanitize_user($username, false));
    }
}

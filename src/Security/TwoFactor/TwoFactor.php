<?php

namespace Snapshot\Security\TwoFactor;

use Snapshot\Security\EventLog;
use Snapshot\Security\Ip;
use Snapshot\Security\LoginGuard;
use Snapshot\Security\Responder;
use Snapshot\Security\Settings;
use Snapshot\Security\Shield;
use Snapshot\Security\Throttle;
use WP_Error;
use WP_Session_Tokens;
use WP_User;

class TwoFactor
{
    public const PENDING = '_snapshot_2fa_pending';

    public const COOKIE = 'snapshot_2fa';

    public const ACTION = 'snapshot-2fa';

    public const SKIP_EMAIL = '_snapshot_2fa_skip_email';

    private const LIFETIME = 900;

    private const MAX_FAILURES = 5;

    private const MAX_POSTS = 12;

    private const MAX_ACCOUNT_FAILURES = 10;

    private const ACCOUNT_WINDOW = HOUR_IN_SECONDS;

    private bool $completing = false;

    private int $verifiedUser = 0;

    private string $cookieToken = '';

    public function __construct(
        private Settings $settings,
        private Throttle $throttle,
        private EventLog $log,
        private Ip $ip,
        private Totp $totp,
        private Passkeys $passkeys,
        private RecoveryCodes $recovery,
        private EmailCode $email,
        private Vault $vault,
        private Responder $responder,
        private LoginGuard $guard,
    ) {}

    public function boot(): void
    {
        if (self::bypassed()) {
            return;
        }

        add_filter('authenticate', [$this, 'intercept'], 100, 3);
        add_action('auth_cookie_valid', function ($elements) {
            $this->cookieToken = (string) ($elements['token'] ?? '');
        }, 10, 1);
        add_action('login_form_'.self::ACTION, [$this, 'screen']);
        add_filter('attach_session_information', [$this, 'markSession'], 10, 2);
        add_action('init', [$this, 'enforceSession'], 1);
        add_filter('login_message', [$this, 'loginMessage']);
    }

    public static function bypassed(): bool
    {
        return self::emergencyOff() || ! Shield::instance()->modules()->on('twofactor');
    }

    public static function emergencyOff(): bool
    {
        return defined('SNAPSHOT_SECURITY_2FA_DISABLED') && SNAPSHOT_SECURITY_2FA_DISABLED;
    }

    public function hasPrimary(int $userId): bool
    {
        return $this->passkeys->enrolled($userId) || $this->totp->enrolled($userId);
    }

    public function intercept($user, $username = '', $password = '')
    {
        if (! $user instanceof WP_User || $this->completing || (defined('WP_CLI') && WP_CLI)) {
            return $user;
        }

        if (! is_string($password) || $password === '') {
            return $user;
        }

        $nonInteractive = (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
            || (defined('REST_REQUEST') && REST_REQUEST)
            || wp_doing_ajax()
            || wp_is_json_request()
            || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST';

        if ($nonInteractive) {
            return new WP_Error('snapshot_2fa_required', sprintf(
                __('Two factor sign in is required. <a href="%s">Sign in through the sign in page</a>.', 'snapshot-core'),
                esc_url(wp_login_url())
            ));
        }

        if ($this->throttle->count("2fa-fail:{$user->ID}", self::ACCOUNT_WINDOW) >= self::MAX_ACCOUNT_FAILURES) {
            $this->log->log('twofactor.locked', 'Sign in refused: too many wrong two-factor codes for this account', [], $user->ID);

            return new WP_Error('snapshot_2fa_locked', __('Too many wrong two-factor codes for this account. Try again in an hour.', 'snapshot-core'));
        }

        $this->begin($user);
    }

    public function begin(WP_User $user): never
    {
        $token = bin2hex(random_bytes(32));
        $redirect = wp_sanitize_redirect(self::input('redirect_to', $_REQUEST));
        $interim = ! empty($_REQUEST['interim-login']);

        update_user_meta($user->ID, self::PENDING, [
            'hash' => hash('sha256', $token),
            'expires' => time() + self::LIFETIME,
            'remember' => ! empty($_POST['rememberme']),
            'redirect' => $redirect,
            'interim' => $interim,
            'failures' => 0,
            'ip' => $this->ip->client(),
            'email_verified' => false,
            'email_sent' => false,
            'secret' => null,
            'challenge' => null,
            'codes' => null,
        ]);

        $this->setCookie($user->ID.'.'.$token, time() + self::LIFETIME);

        wp_safe_redirect($this->url($interim));
        exit;
    }

    public function screen(): never
    {
        $pending = $this->pending();

        if ($pending === null) {
            $this->setCookie('', time() - YEAR_IN_SECONDS);
            wp_safe_redirect(add_query_arg('snapshot_2fa', 'expired', site_url('wp-login.php', 'login')));
            exit;
        }

        [$user, $data] = $pending;
        nocache_headers();

        $error = null;
        $notice = null;
        $stage = $this->stage($user, $data);

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if ($this->throttle->hit("2fa-post:{$user->ID}:{$data['hash']}", self::LIFETIME) > self::MAX_POSTS) {
                $this->abandon($user);
            }

            if (! wp_verify_nonce(self::input('snapshot_2fa_nonce'), $this->nonceAction($user))) {
                $error = __('The form expired. Please try again.', 'snapshot-core');
            } else {
                [$data, $error, $notice] = $stage === 'challenge'
                    ? $this->handleChallenge($user, $data)
                    : $this->handleEnrollment($user, $data, $stage);
            }

            $stage = $this->stage($user, $data);
        }

        if ($stage === 'verify' && ! $data['email_sent']) {
            $data['email_sent'] = $this->email->send($user, 'enroll');

            if ($data['email_sent']) {
                $notice ??= __('We emailed you a six digit code to confirm it is you.', 'snapshot-core');
            } else {
                $error ??= __('We could not send the confirmation email. Ask the site administrator to check the site email settings, then try again.', 'snapshot-core');
            }
        }

        $view = $this->viewData($user, $data, $stage);
        wp_cache_delete($user->ID, 'user_meta');
        $current = get_user_meta($user->ID, self::PENDING, true);

        if (! is_array($current) || ! hash_equals((string) ($current['hash'] ?? ''), (string) $data['hash'])) {
            $this->setCookie('', time() - YEAR_IN_SECONDS);
            wp_safe_redirect(add_query_arg('snapshot_2fa', 'expired', site_url('wp-login.php', 'login')));
            exit;
        }

        update_user_meta($user->ID, self::PENDING, $view['data']);

        $errors = new WP_Error;

        if ($error) {
            $errors->add('snapshot_2fa', $error);
        }

        login_header(__('Two factor sign in', 'snapshot-core'), $notice ? '<p class="message">'.esc_html($notice).'</p>' : '', $errors);
        echo $this->responder->render('snapshot::security.two-factor.'.$stage, $view['vars']);
        login_footer();
        exit;
    }

    public function markSession($session, $userId)
    {
        if ($this->completing || ($this->verifiedUser !== 0 && $this->verifiedUser === (int) $userId)) {
            $session['snapshot_2fa'] = time();
        }

        return $session;
    }

    public function enforceSession(): void
    {
        if ((defined('WP_CLI') && WP_CLI) || wp_doing_cron() || ! is_user_logged_in()) {
            return;
        }

        $userId = get_current_user_id();
        $manager = WP_Session_Tokens::get_instance($userId);
        $tokens = [];

        foreach ([is_ssl() ? 'secure_auth' : 'auth', 'logged_in'] as $scheme) {
            $parsed = wp_parse_auth_cookie('', $scheme);

            if (is_array($parsed) && ! empty($parsed['token']) && wp_validate_auth_cookie('', $scheme) === $userId) {
                $tokens[] = (string) $parsed['token'];
            }
        }

        if ($this->cookieToken !== '') {
            $tokens[] = $this->cookieToken;
        }

        $tokens = array_values(array_unique($tokens));
        $verified = $tokens !== [] && array_reduce($tokens, fn ($ok, $token) => $ok && ! empty($manager->get($token)['snapshot_2fa']), true);

        if ($verified) {
            $this->verifiedUser = $userId;

            return;
        }

        foreach ($tokens as $token) {
            if (empty($manager->get($token)['snapshot_2fa'])) {
                $manager->destroy($token);
            }
        }

        wp_clear_auth_cookie();
        wp_set_current_user(0);

        $this->log->log('login.session_revoked', 'A session without two factor sign in was signed out', [], $userId);

        if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || wp_is_json_request()) {
            return;
        }

        $back = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? wp_validate_redirect(set_url_scheme('//'.($_SERVER['HTTP_HOST'] ?? '').($_SERVER['REQUEST_URI'] ?? '')), '') : '';

        wp_safe_redirect(add_query_arg(array_filter(['snapshot_2fa' => 'required', 'redirect_to' => $back !== '' ? rawurlencode($back) : null]), site_url('wp-login.php', 'login')));
        exit;
    }

    public function loginMessage($message)
    {
        $copy = [
            'expired' => __('Your two factor sign in timed out. Sign in again.', 'snapshot-core'),
            'failed' => __('Too many wrong codes. Sign in again to get a fresh attempt.', 'snapshot-core'),
            'required' => __('Two factor sign in is now required for every account. Sign in again to set it up, it takes a minute.', 'snapshot-core'),
        ];

        $key = sanitize_key((string) ($_GET['snapshot_2fa'] ?? ''));

        return isset($copy[$key]) ? $message.'<p class="message">'.esc_html($copy[$key]).'</p>' : $message;
    }

    public function resetUser(int $userId): void
    {
        $this->totp->remove($userId);
        $this->passkeys->removeAll($userId);
        $this->recovery->remove($userId);
        delete_user_meta($userId, self::PENDING);
        WP_Session_Tokens::get_instance($userId)->destroy_all();
    }

    public function emailFallback(WP_User $user): bool
    {
        if (! $this->settings->bool('twofactor_email_fallback')) {
            return false;
        }

        return ! ($user->has_cap('manage_options') || $user->has_cap('unfiltered_html') || $user->has_cap('edit_users')) || $this->settings->bool('twofactor_email_fallback_admins');
    }

    private function stage(WP_User $user, array $data): string
    {
        if (! empty($data['codes'])) {
            return 'codes';
        }

        if ($this->hasPrimary($user->ID)) {
            return 'challenge';
        }

        if ($this->settings->bool('twofactor_enroll_verify_email') && empty($data['email_verified']) && ! get_user_meta($user->ID, self::SKIP_EMAIL, true)) {
            return 'verify';
        }

        return 'enroll';
    }

    private function handleChallenge(WP_User $user, array $data): array
    {
        $method = sanitize_key(self::input('snapshot_2fa_method'));
        $code = self::input('snapshot_2fa_code');
        $passed = false;

        if ($method === 'email' && ! empty($_POST['snapshot_2fa_send'])) {
            if (! $this->emailFallback($user)) {
                return [$data, __('Emailed codes are turned off for this site.', 'snapshot-core'), null];
            }

            if ($this->email->send($user, 'challenge')) {
                $this->log->log('twofactor.email_sent', '', [], $user->ID);

                return [$data, null, sprintf(__('We sent a code to %s.', 'snapshot-core'), $this->email->masked($user))];
            }

            return [$data, __('A code was sent less than a minute ago. Check your inbox, or wait a moment and try again.', 'snapshot-core'), null];
        }

        if ($method === 'totp') {
            if ($this->totp->enrolled($user->ID) && $this->totp->secret($user->ID) === null) {
                $this->log->log('twofactor.vault_unreadable', 'The stored authenticator secret could not be decrypted. The site keys may have changed.', [], $user->ID);

                return [$data, __('Your authenticator app setting could not be read. Use a recovery code or an emailed code, then set the app up again.', 'snapshot-core'), null];
            }

            $passed = $this->totp->verify($user->ID, $code);
        } elseif ($method === 'passkey') {
            $passed = ! empty($data['challenge'])
                && $this->passkeys->verify($user->ID, Passkeys::parse(self::input('snapshot_2fa_credential')), (string) $data['challenge']);
        } elseif ($method === 'recovery') {
            $passed = $this->recovery->consume($user->ID, $code);

            if ($passed) {
                $this->log->log('twofactor.recovery_used', sprintf('%d recovery codes left', $this->recovery->remaining($user->ID)), [], $user->ID);
            }
        } elseif ($method === 'email' && $this->emailFallback($user)) {
            $passed = $this->email->verify($user->ID, $code);
        }

        if ($passed) {
            $this->complete($user, $data, $method);
        }

        return $this->failure($user, $data, $method);
    }

    private function handleEnrollment(WP_User $user, array $data, string $stage): array
    {
        $method = sanitize_key(self::input('snapshot_2fa_method'));
        $code = self::input('snapshot_2fa_code');

        if ($stage === 'codes') {
            if (empty($_POST['snapshot_2fa_saved'])) {
                return [$data, __('Confirm that you have saved your recovery codes to continue.', 'snapshot-core'), null];
            }

            $this->complete($user, ['codes' => null] + $data, 'enrollment');
        }

        if ($stage === 'verify') {
            if (! empty($_POST['snapshot_2fa_send'])) {
                $sent = $this->email->send($user, 'enroll');

                return [$data, $sent ? null : __('A code was sent less than a minute ago. Check your inbox, or wait a moment and try again.', 'snapshot-core'), $sent ? __('We sent a new code.', 'snapshot-core') : null];
            }

            if ($this->email->verify($user->ID, $code)) {
                $data['email_verified'] = true;

                return [$data, null, __('Thanks, that confirms it is you. Now choose how you will sign in.', 'snapshot-core')];
            }

            return $this->failure($user, $data, 'email');
        }

        $enrolled = false;

        if ($method === 'totp' && ! empty($data['secret'])) {
            $secret = $this->vault->open((string) $data['secret']);
            $step = $secret ? $this->totp->matchStep($secret, $code) : null;

            if ($step !== null) {
                $this->totp->save($user->ID, $secret, $step);
                $enrolled = 'authenticator app';
            }
        } elseif ($method === 'passkey' && ! empty($data['challenge'])) {
            $name = sanitize_text_field(self::input('snapshot_2fa_name'));

            if ($this->passkeys->register($user, Passkeys::parse(self::input('snapshot_2fa_credential')), (string) $data['challenge'], $name)) {
                $enrolled = 'passkey';
            }
        }

        if (! $enrolled) {
            return $this->failure($user, $data, $method);
        }

        $this->log->log('twofactor.enrolled', "Added a {$enrolled} at sign in", [], $user->ID);
        delete_user_meta($user->ID, self::SKIP_EMAIL);
        $data['secret'] = null;
        $data['codes'] = $this->vault->seal(wp_json_encode($this->recovery->generate($user->ID)));

        return [$data, null, null];
    }

    private function failure(WP_User $user, array $data, string $method): array
    {
        $attempt = $this->throttle->hit("2fa-fail:{$user->ID}:{$data['hash']}", self::LIFETIME);
        $account = $this->throttle->hit("2fa-fail:{$user->ID}", self::ACCOUNT_WINDOW);
        $data['failures'] = $attempt;
        $this->log->log('twofactor.failed', "Wrong {$method} code", ['failures' => $attempt, 'account_failures' => $account], $user->ID);
        $this->guard->registerFailure($user->user_login, 'two factor');

        if ($attempt >= self::MAX_FAILURES || $account >= self::MAX_ACCOUNT_FAILURES) {
            $this->abandon($user, false);
        }

        $message = $method === 'passkey' && $this->passkeys->error()
            ? __('That passkey could not be verified. Try again, or use another method.', 'snapshot-core')
            : __('That code did not work. Check it and try again.', 'snapshot-core');

        return [$data, $message, null];
    }

    private function abandon(WP_User $user, bool $countFailure = true): never
    {
        delete_user_meta($user->ID, self::PENDING);
        $this->setCookie('', time() - YEAR_IN_SECONDS);
        $this->log->log('twofactor.abandoned', '', [], $user->ID);

        if ($countFailure) {
            $this->guard->registerFailure($user->user_login, 'two factor');
        }

        wp_safe_redirect(add_query_arg('snapshot_2fa', 'failed', site_url('wp-login.php', 'login')));
        exit;
    }

    private function complete(WP_User $user, array $data, string $method): never
    {
        global $wpdb;

        if (! delete_user_meta($user->ID, self::PENDING)) {
            $this->setCookie('', time() - YEAR_IN_SECONDS);
            wp_safe_redirect(add_query_arg('snapshot_2fa', 'expired', site_url('wp-login.php', 'login')));
            exit;
        }

        $this->throttle->clear("2fa-fail:{$user->ID}");

        if ($user->user_activation_key !== '') {
            $wpdb->update($wpdb->users, ['user_activation_key' => ''], ['ID' => $user->ID]);
            clean_user_cache($user->ID);
        }

        $this->setCookie('', time() - YEAR_IN_SECONDS);

        $this->completing = true;
        wp_set_auth_cookie($user->ID, ! empty($data['remember']));
        $this->completing = false;
        wp_set_current_user($user->ID);

        $this->log->log('twofactor.success', "Two factor check passed with {$method}", ['method' => $method], $user->ID);
        do_action('wp_login', $user->user_login, $user);
        do_action('snap/security/signed_in', $user, $method);

        if (! empty($data['interim'])) {
            $GLOBALS['interim_login'] = 'success';
            login_header('', '<p class="message">'.esc_html__('You have signed in successfully.', 'snapshot-core').'</p>');
            echo '</div>';
            do_action('login_footer');
            echo '</body></html>';
            exit;
        }

        $requested = (string) ($data['redirect'] ?? '');
        $redirect = $requested !== '' ? $requested : admin_url();
        $redirect = apply_filters('login_redirect', $redirect, $requested, $user);

        if (($redirect === '' || $redirect === 'wp-admin/' || $redirect === admin_url()) && ! $user->has_cap('edit_posts')) {
            $redirect = $user->has_cap('read') ? admin_url('profile.php') : home_url('/');
        }

        wp_safe_redirect($redirect);
        exit;
    }

    private function viewData(WP_User $user, array $data, string $stage): array
    {
        $vars = [
            'user' => $user,
            'action' => $this->url(! empty($data['interim']), 'login_post'),
            'nonce' => wp_nonce_field($this->nonceAction($user), 'snapshot_2fa_nonce', false, false),
            'emailFallback' => $this->emailFallback($user),
            'maskedEmail' => $this->email->masked($user),
            'cancelUrl' => site_url('wp-login.php', 'login'),
        ];

        if ($stage === 'challenge') {
            $vars['hasTotp'] = $this->totp->enrolled($user->ID);
            $vars['hasPasskey'] = $this->passkeys->enrolled($user->ID);
            $vars['recoveryLeft'] = $this->recovery->remaining($user->ID);
            $vars['passkeyOptions'] = null;

            if ($vars['hasPasskey']) {
                $assertion = $this->passkeys->assertionOptions($user->ID);
                $data['challenge'] = $assertion['challenge'];
                $vars['passkeyOptions'] = wp_json_encode($assertion['options']);
            }
        }

        if ($stage === 'enroll') {
            $secret = ! empty($data['secret']) ? $this->vault->open((string) $data['secret']) : null;

            if ($secret === null) {
                $secret = $this->totp->newSecret();
                $data['secret'] = $this->vault->seal($secret);
            }

            $uri = $this->totp->uri($user, $secret);
            $registration = $this->passkeys->registrationOptions($user);
            $data['challenge'] = $registration['challenge'];

            $vars['qr'] = $this->totp->qr($uri);
            $vars['secret'] = Totp::format($secret);
            $vars['otpauth'] = $uri;
            $vars['passkeyOptions'] = wp_json_encode($registration['options']);
        }

        if ($stage === 'codes') {
            $vars['codes'] = json_decode((string) $this->vault->open((string) $data['codes']), true) ?: [];
        }

        return ['data' => $data, 'vars' => $vars];
    }

    private function pending(): ?array
    {
        $raw = self::input(self::COOKIE, $_COOKIE);
        [$userId, $token] = array_pad(explode('.', $raw, 2), 2, '');

        if (! ctype_digit($userId) || strlen($token) !== 64 || ! ctype_xdigit($token)) {
            return null;
        }

        $data = get_user_meta((int) $userId, self::PENDING, true);

        if (! is_array($data) || ($data['expires'] ?? 0) < time() || ! hash_equals((string) ($data['hash'] ?? ''), hash('sha256', $token))) {
            return null;
        }

        $user = get_userdata((int) $userId);

        return $user ? [$user, $data] : null;
    }

    private static function input(string $key, ?array $source = null): string
    {
        $value = ($source ?? $_POST)[$key] ?? '';

        return is_scalar($value) ? trim((string) wp_unslash((string) $value)) : '';
    }

    private function url(bool $interim, string $scheme = 'login'): string
    {
        return add_query_arg(array_filter([
            'action' => self::ACTION,
            'interim-login' => $interim ? '1' : null,
        ]), site_url('wp-login.php', $scheme));
    }

    private function nonceAction(WP_User $user): string
    {
        return 'snap-2fa-'.$user->ID;
    }

    private function setCookie(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => SITECOOKIEPATH ?: '/',
            'domain' => COOKIE_DOMAIN ?: '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

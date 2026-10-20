<?php

namespace Snapshot\Security\TwoFactor;

use Snapshot\Core;
use Snapshot\Security\EventLog;
use Snapshot\Security\Responder;
use Throwable;
use WP_Error;
use WP_User;

class Profile
{
    private const SETUP_META = '_snapshot_2fa_totp_setup';

    private const REG_META = '_snapshot_2fa_registration';

    private const FRESH_META = '_snapshot_2fa_fresh_codes';

    private const REGISTRATION_TTL = 5 * MINUTE_IN_SECONDS;

    private const FRESH_CODES_TTL = 10 * MINUTE_IN_SECONDS;

    private const SETUP_TTL = 30 * MINUTE_IN_SECONDS;

    private ?string $error = null;

    public function __construct(
        private TwoFactor $twoFactor,
        private Totp $totp,
        private Passkeys $passkeys,
        private RecoveryCodes $recovery,
        private Vault $vault,
        private EventLog $log,
        private Responder $responder,
    ) {}

    public function boot(): void
    {
        add_action('show_user_profile', [$this, 'render'], 1);
        add_action('edit_user_profile', [$this, 'render'], 1);
        add_action('personal_options_update', [$this, 'handle'], 1);
        add_action('edit_user_profile_update', [$this, 'handle'], 1);
        add_action('user_profile_update_errors', [$this, 'errors'], 1, 1);
        add_action('wp_ajax_snapshot_2fa_passkey_options', [$this, 'passkeyOptions']);
        add_action('wp_ajax_snapshot_2fa_passkey_register', [$this, 'passkeyRegister']);
        add_action('admin_footer-profile.php', [$this, 'scripts']);
        add_action('admin_footer-user-edit.php', [$this, 'scripts']);
    }

    public function render(WP_User $user): void
    {
        $self = $user->ID === get_current_user_id();
        $vars = [
            'self' => $self,
            'user' => $user,
            'canReset' => ! $self && current_user_can('edit_users') && current_user_can('edit_user', $user->ID),
            'bypassed' => TwoFactor::bypassed(),
            'emergency' => TwoFactor::emergencyOff(),
            'hasTotp' => $this->totp->enrolled($user->ID),
            'passkeys' => $this->passkeys->list($user->ID),
            'recoveryLeft' => $this->recovery->remaining($user->ID),
            'freshCodes' => $self ? $this->freshCodes($user->ID) : [],
            'nonce' => wp_nonce_field('snapshot_2fa_profile_'.$user->ID, 'snapshot_2fa_nonce', false, false),
            'passkeyNonce' => wp_create_nonce('snapshot_2fa_passkey'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'qr' => null,
            'secret' => null,
        ];

        if ($self && ! $vars['hasTotp']) {
            $secret = $this->setupSecret($user->ID);
            $vars['qr'] = $this->totp->qr($this->totp->uri($user, $secret));
            $vars['secret'] = Totp::format($secret);
        }

        echo $this->responder->render('snapshot::security.admin.profile', $vars);
    }

    public function handle(int $userId): void
    {
        $op = sanitize_text_field(wp_unslash(is_string($_POST['snapshot_2fa_op'] ?? null) ? $_POST['snapshot_2fa_op'] : ''));

        if ($op === '') {
            return;
        }

        if (! wp_verify_nonce(is_string($_POST['snapshot_2fa_nonce'] ?? null) ? $_POST['snapshot_2fa_nonce'] : '', 'snapshot_2fa_profile_'.$userId) || ! current_user_can('edit_user', $userId)) {
            $this->error = __('The two factor change could not be verified. Reload the page and try again.', 'snapshot-core');

            return;
        }

        $self = $userId === get_current_user_id();

        if ($op === 'reset') {
            if ($self || ! current_user_can('edit_users')) {
                $this->error = __('You cannot reset two factor sign in for this account.', 'snapshot-core');

                return;
            }

            $this->twoFactor->resetUser($userId);
            $this->log->log('twofactor.reset', 'Two factor sign in reset for user '.$userId, ['target' => $userId]);

            return;
        }

        if (! $self) {
            return;
        }

        if ($op === 'totp-enable') {
            $sealed = get_user_meta($userId, self::SETUP_META, true);
            $secret = is_array($sealed) && ($sealed['expires'] ?? 0) > time() ? $this->vault->open((string) $sealed['secret']) : null;
            $step = $secret ? $this->totp->matchStep($secret, wp_unslash(is_string($_POST['snapshot_2fa_code'] ?? null) ? $_POST['snapshot_2fa_code'] : '')) : null;

            if ($step === null) {
                $this->error = __('That authenticator code did not work. Scan the code again and enter the six digits it shows.', 'snapshot-core');

                return;
            }

            $this->totp->save($userId, $secret, $step);
            delete_user_meta($userId, self::SETUP_META);
            $this->log->log('twofactor.enrolled', 'Added an authenticator app from the profile', [], $userId);
            $this->ensureRecoveryCodes($userId);

            return;
        }

        if ($op === 'totp-remove') {
            if (! $this->passkeys->enrolled($userId) && $this->totp->secret($userId) !== null) {
                $this->error = __('Add a passkey before removing the authenticator app, so the account keeps a second factor.', 'snapshot-core');

                return;
            }

            $this->totp->remove($userId);
            $this->log->log('twofactor.removed', 'Removed the authenticator app', [], $userId);

            return;
        }

        if (str_starts_with($op, 'passkey-remove:')) {
            $id = substr($op, strlen('passkey-remove:'));
            $others = count($this->passkeys->list($userId)) > 1 || $this->totp->enrolled($userId);

            if (! $others) {
                $this->error = __('Set up another method before removing your only passkey.', 'snapshot-core');

                return;
            }

            if ($this->passkeys->remove($userId, $id)) {
                $this->log->log('twofactor.removed', 'Removed a passkey', [], $userId);
            }

            return;
        }

        if ($op === 'recovery-regenerate') {
            $this->storeFreshCodes($userId, $this->recovery->generate($userId));
            $this->log->log('twofactor.recovery_regenerated', '', [], $userId);
        }
    }

    public function errors($errors): void
    {
        if ($this->error && $errors instanceof WP_Error) {
            $errors->add('snapshot_2fa', $this->error);
        }
    }

    public function passkeyOptions(): void
    {
        check_ajax_referer('snapshot_2fa_passkey', 'nonce');
        $user = wp_get_current_user();

        if (! $user->exists() || ! current_user_can('read')) {
            wp_send_json_error(null, 403);
        }

        $registration = $this->passkeys->registrationOptions($user);
        update_user_meta($user->ID, self::REG_META, ['challenge' => $registration['challenge'], 'expires' => time() + self::REGISTRATION_TTL]);

        wp_send_json_success($registration['options']);
    }

    public function passkeyRegister(): void
    {
        check_ajax_referer('snapshot_2fa_passkey', 'nonce');
        $user = wp_get_current_user();
        $pending = get_user_meta($user->ID, self::REG_META, true);
        delete_user_meta($user->ID, self::REG_META);

        if (! $user->exists() || ! current_user_can('read') || ! is_array($pending) || ($pending['expires'] ?? 0) < time()) {
            wp_send_json_error(['message' => __('The passkey request expired. Try again.', 'snapshot-core')], 400);
        }

        $credential = Passkeys::parse(is_string($_POST['credential'] ?? null) ? $_POST['credential'] : '');
        $name = sanitize_text_field(wp_unslash(is_string($_POST['name'] ?? null) ? $_POST['name'] : ''));

        if (! $this->passkeys->register($user, $credential, (string) $pending['challenge'], $name)) {
            wp_send_json_error(['message' => __('That passkey could not be registered. Try again.', 'snapshot-core')], 400);
        }

        $this->log->log('twofactor.enrolled', 'Added a passkey from the profile', [], $user->ID);
        $this->ensureRecoveryCodes($user->ID);

        wp_send_json_success();
    }

    public function scripts(): void
    {
        try {
            echo Core::assets(['resources/js/security.js']);
        } catch (Throwable) {
        }
    }

    private function ensureRecoveryCodes(int $userId): void
    {
        if ($this->recovery->remaining($userId) === 0) {
            $this->storeFreshCodes($userId, $this->recovery->generate($userId));
        }
    }

    private function storeFreshCodes(int $userId, array $codes): void
    {
        update_user_meta($userId, self::FRESH_META, [
            'codes' => $this->vault->seal(wp_json_encode($codes)),
            'expires' => time() + self::FRESH_CODES_TTL,
        ]);
    }

    private function freshCodes(int $userId): array
    {
        $stored = get_user_meta($userId, self::FRESH_META, true);
        delete_user_meta($userId, self::FRESH_META);

        if (! is_array($stored) || ($stored['expires'] ?? 0) < time()) {
            return [];
        }

        return json_decode((string) $this->vault->open((string) $stored['codes']), true) ?: [];
    }

    private function setupSecret(int $userId): string
    {
        $stored = get_user_meta($userId, self::SETUP_META, true);

        if (is_array($stored) && ($stored['expires'] ?? 0) > time()) {
            $secret = $this->vault->open((string) $stored['secret']);

            if ($secret !== null) {
                return $secret;
            }
        }

        $secret = $this->totp->newSecret();
        update_user_meta($userId, self::SETUP_META, ['secret' => $this->vault->seal($secret), 'expires' => time() + self::SETUP_TTL]);

        return $secret;
    }
}

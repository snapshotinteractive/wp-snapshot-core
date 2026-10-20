<?php

namespace Snapshot\Security\TwoFactor;

use Snapshot\Vendor\lbuchs\WebAuthn\Binary\ByteBuffer;
use Snapshot\Vendor\lbuchs\WebAuthn\WebAuthn;
use Throwable;
use WP_User;

class Passkeys
{
    public const META = '_snapshot_2fa_passkeys';

    private const TIMEOUT = 120;

    private ?string $error = null;

    public function list(int $userId): array
    {
        $keys = get_user_meta($userId, self::META, true);

        return is_array($keys) ? $keys : [];
    }

    public function enrolled(int $userId): bool
    {
        return $this->list($userId) !== [];
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function registrationOptions(WP_User $user): array
    {
        $webauthn = $this->webauthn();
        $exclude = array_values(array_filter(array_map(
            fn ($key) => self::decode((string) ($key['id'] ?? '')),
            $this->list($user->ID)
        )));

        $options = $webauthn->getCreateArgs(
            $this->userHandle($user),
            $user->user_login,
            $user->display_name ?: $user->user_login,
            self::TIMEOUT,
            'preferred',
            'preferred',
            null,
            $exclude
        );

        return ['options' => json_decode(wp_json_encode($options), true), 'challenge' => $webauthn->getChallenge()->getHex()];
    }

    public function register(WP_User $user, array $credential, string $challenge, string $name): bool
    {
        $this->error = null;

        try {
            $data = $this->webauthn()->processCreate(
                self::decode((string) ($credential['clientDataJSON'] ?? '')),
                self::decode((string) ($credential['attestationObject'] ?? '')),
                ByteBuffer::fromHex($challenge),
                false,
                true,
                false
            );
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return false;
        }

        $id = self::encode($data->credentialId);
        $keys = $this->list($user->ID);
        $keys[$id] = [
            'id' => $id,
            'key' => (string) $data->credentialPublicKey,
            'counter' => (int) $data->signatureCounter,
            'name' => mb_substr(sanitize_text_field($name), 0, 60) ?: __('Passkey', 'snapshot-core'),
            'created' => time(),
            'used' => 0,
        ];

        update_user_meta($user->ID, self::META, $keys);

        return true;
    }

    public function assertionOptions(int $userId): array
    {
        $webauthn = $this->webauthn();
        $ids = array_values(array_filter(array_map(
            fn ($key) => self::decode((string) ($key['id'] ?? '')),
            $this->list($userId)
        )));

        $options = $webauthn->getGetArgs($ids, self::TIMEOUT, true, true, true, true, true, 'preferred');

        return ['options' => json_decode(wp_json_encode($options), true), 'challenge' => $webauthn->getChallenge()->getHex()];
    }

    public function verify(int $userId, array $credential, string $challenge): bool
    {
        $this->error = null;
        $keys = $this->list($userId);
        $id = (string) ($credential['id'] ?? '');

        if ($id === '' || ! isset($keys[$id])) {
            $this->error = 'unknown credential';

            return false;
        }

        $webauthn = $this->webauthn();

        try {
            $webauthn->processGet(
                self::decode((string) ($credential['clientDataJSON'] ?? '')),
                self::decode((string) ($credential['authenticatorData'] ?? '')),
                self::decode((string) ($credential['signature'] ?? '')),
                (string) $keys[$id]['key'],
                ByteBuffer::fromHex($challenge),
                (int) ($keys[$id]['counter'] ?? 0) ?: null,
                false,
                true
            );
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return false;
        }

        $keys[$id]['counter'] = (int) ($webauthn->getSignatureCounter() ?? $keys[$id]['counter']);
        $keys[$id]['used'] = time();
        update_user_meta($userId, self::META, $keys);

        return true;
    }

    public function remove(int $userId, string $id): bool
    {
        $keys = $this->list($userId);

        if (! isset($keys[$id])) {
            return false;
        }

        unset($keys[$id]);
        $keys === [] ? delete_user_meta($userId, self::META) : update_user_meta($userId, self::META, $keys);

        return true;
    }

    public function removeAll(int $userId): void
    {
        delete_user_meta($userId, self::META);
    }

    public static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? '' : $decoded;
    }

    public static function parse(string $json): array
    {
        $data = json_decode(wp_unslash($json), true);

        return is_array($data) ? array_map(fn ($value) => is_string($value) ? $value : '', array_intersect_key($data, array_flip([
            'id', 'clientDataJSON', 'attestationObject', 'authenticatorData', 'signature',
        ]))) : [];
    }

    private function webauthn(): WebAuthn
    {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) ?: 'WordPress';

        return new WebAuthn($site, $this->rpId(), null, true);
    }

    private function rpId(): string
    {
        return (string) wp_parse_url(site_url(), PHP_URL_HOST);
    }

    private function userHandle(WP_User $user): string
    {
        return substr(hash_hmac('sha256', 'snap-passkey-user|'.$user->ID, wp_salt('auth'), true), 0, 32);
    }
}

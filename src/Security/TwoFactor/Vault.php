<?php

namespace Snapshot\Security\TwoFactor;

class Vault
{
    private const PREFIX = 'v1:';

    public function seal(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    public function open(string $sealed): ?string
    {
        if (! str_starts_with($sealed, self::PREFIX)) {
            return null;
        }

        $raw = base64_decode(substr($sealed, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key()
        );

        return $plain === false ? null : $plain;
    }

    public function usingDefaultKeys(): bool
    {
        return ! defined('SNAPSHOT_SECURITY_KEY')
            && (! defined('AUTH_KEY') || AUTH_KEY === '' || str_contains((string) AUTH_KEY, 'put your unique phrase here'));
    }

    private function key(): string
    {
        $material = defined('SNAPSHOT_SECURITY_KEY')
            ? (string) SNAPSHOT_SECURITY_KEY
            : (defined('AUTH_KEY') ? (string) AUTH_KEY : '').(defined('SECURE_AUTH_KEY') ? (string) SECURE_AUTH_KEY : '');

        return sodium_crypto_generichash('snapshot-security-vault|'.$material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}

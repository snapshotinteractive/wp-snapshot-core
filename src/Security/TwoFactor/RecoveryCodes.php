<?php

namespace Snapshot\Security\TwoFactor;

class RecoveryCodes
{
    public const META = '_snapshot_2fa_recovery';

    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    private const COUNT = 10;

    public function generate(int $userId): array
    {
        $codes = [];
        $hashes = [];

        for ($i = 0; $i < self::COUNT; $i++) {
            $code = $this->code();
            $codes[] = substr($code, 0, 5).'-'.substr($code, 5);
            $hashes[] = password_hash($code, PASSWORD_DEFAULT);
        }

        update_user_meta($userId, self::META, $hashes);

        return $codes;
    }

    public function remaining(int $userId): int
    {
        return count($this->hashes($userId));
    }

    public function consume(int $userId, string $input): bool
    {
        $input = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $input));

        if (strlen($input) !== 10) {
            return false;
        }

        $hashes = $this->hashes($userId);

        foreach ($hashes as $index => $hash) {
            if (password_verify($input, $hash)) {
                unset($hashes[$index]);
                update_user_meta($userId, self::META, array_values($hashes));

                return true;
            }
        }

        return false;
    }

    public function remove(int $userId): void
    {
        delete_user_meta($userId, self::META);
    }

    private function hashes(int $userId): array
    {
        $hashes = get_user_meta($userId, self::META, true);

        return is_array($hashes) ? array_values(array_filter($hashes, 'is_string')) : [];
    }

    private function code(): string
    {
        $code = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < 10; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}

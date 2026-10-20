<?php

namespace Snapshot\Security\TwoFactor;

use Snapshot\Vendor\BaconQrCode\Renderer\Image\SvgImageBackEnd;
use Snapshot\Vendor\BaconQrCode\Renderer\ImageRenderer;
use Snapshot\Vendor\BaconQrCode\Renderer\RendererStyle\RendererStyle;
use Snapshot\Vendor\BaconQrCode\Writer;
use Snapshot\Vendor\OTPHP\TOTP as OtpTotp;
use WP_User;

class Totp
{
    public const META = '_snapshot_2fa_totp';

    public const STEP_META = '_snapshot_2fa_totp_step';

    private const PERIOD = 30;

    public function __construct(private Vault $vault) {}

    public function enrolled(int $userId): bool
    {
        return (string) get_user_meta($userId, self::META, true) !== '';
    }

    public function secret(int $userId): ?string
    {
        $sealed = (string) get_user_meta($userId, self::META, true);

        return $sealed === '' ? null : $this->vault->open($sealed);
    }

    public function newSecret(): string
    {
        return OtpTotp::generate(new Clock)->getSecret();
    }

    public function uri(WP_User $user, string $secret): string
    {
        $totp = OtpTotp::createFromSecret($secret, new Clock);
        $totp->setLabel(str_replace(':', '', $user->user_email ?: $user->user_login));
        $totp->setIssuer(str_replace(':', '', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) ?: 'WordPress'));

        return $totp->getProvisioningUri();
    }

    public function qr(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd));
        $svg = $writer->writeString($uri);

        return trim((string) preg_replace('/^<\?xml[^>]*\?>/', '', $svg));
    }

    public function matchStep(string $secret, string $code, int $lastStep = 0): ?int
    {
        $code = preg_replace('/\D/', '', $code);

        if (strlen($code) !== 6) {
            return null;
        }

        $totp = OtpTotp::createFromSecret($secret, new Clock);
        $current = intdiv(time(), self::PERIOD);

        foreach ([0, -1, 1] as $offset) {
            $step = $current + $offset;

            if ($step <= $lastStep) {
                continue;
            }

            if (hash_equals($totp->at($step * self::PERIOD), $code)) {
                return $step;
            }
        }

        return null;
    }

    public function verify(int $userId, string $code): bool
    {
        $secret = $this->secret($userId);

        if ($secret === null) {
            return false;
        }

        $step = $this->matchStep($secret, $code, (int) get_user_meta($userId, self::STEP_META, true));

        if ($step === null) {
            return false;
        }

        update_user_meta($userId, self::STEP_META, $step);

        return true;
    }

    public function save(int $userId, string $secret, int $step): void
    {
        update_user_meta($userId, self::META, $this->vault->seal($secret));
        update_user_meta($userId, self::STEP_META, $step);
    }

    public function remove(int $userId): void
    {
        delete_user_meta($userId, self::META);
        delete_user_meta($userId, self::STEP_META);
    }

    public static function format(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }
}

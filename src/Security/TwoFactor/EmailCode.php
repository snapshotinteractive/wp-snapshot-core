<?php

namespace Snapshot\Security\TwoFactor;

use WP_User;

class EmailCode
{
    public const META = '_snapshot_2fa_email';

    private const LIFETIME = 600;

    private const RESEND_AFTER = 60;

    private const MAX_TRIES = 5;

    public function send(WP_User $user, string $purpose): bool
    {
        $current = $this->current($user->ID);

        if ($current && ($current['sent'] ?? 0) > time() - self::RESEND_AFTER) {
            return false;
        }

        if (! is_email($user->user_email)) {
            return false;
        }

        $code = sprintf('%06d', random_int(0, 999999));

        update_user_meta($user->ID, self::META, [
            'hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires' => time() + self::LIFETIME,
            'sent' => time(),
            'tries' => 0,
        ]);

        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $lead = $purpose === 'enroll'
            ? __('Someone, hopefully you, signed in and is setting up two factor sign in for your account.', 'snapshot-core')
            : __('Someone, hopefully you, signed in and asked for a sign in code by email.', 'snapshot-core');

        $body = implode("\n\n", [
            sprintf(__('Hello %s,', 'snapshot-core'), $user->display_name),
            $lead,
            sprintf(__('Your code is: %s', 'snapshot-core'), $code),
            __('It works once and expires in 10 minutes.', 'snapshot-core'),
            __('If this was not you, your password is known to someone else. Reset it now and tell the site administrator.', 'snapshot-core'),
            $site,
        ]);

        return (bool) wp_mail($user->user_email, sprintf(__('[%s] Your sign in code', 'snapshot-core'), $site), $body);
    }

    public function verify(int $userId, string $input): bool
    {
        $current = $this->current($userId);
        $input = preg_replace('/\D/', '', $input);

        if (! $current || strlen($input) !== 6) {
            return false;
        }

        $current['tries'] = (int) ($current['tries'] ?? 0) + 1;

        if ($current['tries'] > self::MAX_TRIES) {
            delete_user_meta($userId, self::META);

            return false;
        }

        if (! password_verify($input, (string) $current['hash'])) {
            update_user_meta($userId, self::META, $current);

            return false;
        }

        delete_user_meta($userId, self::META);

        return true;
    }

    public function masked(WP_User $user): string
    {
        [$local, $domain] = array_pad(explode('@', $user->user_email, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat('•', max(2, min(6, mb_strlen($local) - 1))).'@'.$domain;
    }

    private function current(int $userId): ?array
    {
        $current = get_user_meta($userId, self::META, true);

        if (! is_array($current) || ($current['expires'] ?? 0) < time()) {
            return null;
        }

        return $current;
    }
}

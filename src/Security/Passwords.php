<?php

namespace Snapshot\Security;

use Snapshot\Vendor\ZxcvbnPhp\Zxcvbn;
use Throwable;
use WP_Error;
use WP_User;

class Passwords
{
    private const RANGE_URL = 'https://api.pwnedpasswords.com/range/';

    public function __construct(
        private Settings $settings,
        private EventLog $log,
    ) {}

    public function boot(): void
    {
        add_action('user_profile_update_errors', [$this, 'checkProfile'], 10, 3);
        add_action('validate_password_reset', [$this, 'checkReset'], 10, 2);
    }

    public function checkProfile($errors, $update, $user): void
    {
        $password = (string) ($user->user_pass ?? '');

        if ($password === '' || empty($_POST['pass1'])) {
            return;
        }

        $this->apply($errors, $password, [
            $user->user_login ?? '',
            $user->user_email ?? '',
            $user->first_name ?? '',
            $user->last_name ?? '',
            $user->nickname ?? '',
        ], isset($user->ID) ? (int) $user->ID : 0);
    }

    public function checkReset($errors, $user): void
    {
        $password = (string) wp_unslash($_POST['pass1'] ?? '');

        if ($password === '' || ! $user instanceof WP_User) {
            return;
        }

        $this->apply($errors, $password, [$user->user_login, $user->user_email, $user->first_name, $user->last_name], $user->ID);
    }

    public function problem(string $password, array $inputs = []): ?string
    {
        $inputs = array_values(array_filter(array_map('strval', [...$inputs, get_bloginfo('name'), 'wordpress'])));
        $minimum = $this->settings->int('password_min_score');

        try {
            $score = (int) ((new Zxcvbn)->passwordStrength($password, $inputs)['score'] ?? 0);
        } catch (Throwable) {
            $score = strlen($password) >= 12 ? 4 : 0;
        }

        if ($score < $minimum) {
            return __('That password is too easy to guess. Use three or four unrelated words, or let WordPress generate one.', 'snapshot-core');
        }

        if ($this->settings->bool('password_breach_check')) {
            $count = $this->breachCount($password);

            if ($count > 0) {
                return sprintf(
                    __('That password has appeared in %s known data breaches, so attackers already try it. Choose a different one.', 'snapshot-core'),
                    number_format_i18n($count)
                );
            }
        }

        return null;
    }

    public function breachCount(string $password): int
    {
        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        $response = wp_remote_get(self::RANGE_URL.$prefix, [
            'timeout' => 4,
            'headers' => ['Add-Padding' => 'true', 'User-Agent' => 'Snapshot-Security/1.0'],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return 0;
        }

        foreach (preg_split('/\r?\n/', wp_remote_retrieve_body($response)) as $line) {
            [$candidate, $count] = array_pad(explode(':', trim($line), 2), 2, '0');

            if (hash_equals($suffix, strtoupper($candidate))) {
                return (int) $count;
            }
        }

        return 0;
    }

    private function apply($errors, string $password, array $inputs, int $userId): void
    {
        if (! $errors instanceof WP_Error) {
            return;
        }

        $problem = $this->problem($password, $inputs);

        if ($problem !== null) {
            $errors->add('snapshot_weak_password', $problem);
            $this->log->log('user.password_rejected', '', [], $userId ?: null);
        }
    }
}

<?php

namespace Snapshot\Security;

use WP_Error;

class Turnstile
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    private ?bool $verified = null;

    public function __construct(
        private Settings $settings,
        private Ip $ip,
        private Throttle $throttle,
        private EventLog $log,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->bool('cloudflare_enabled')
            && $this->settings->has('turnstile_site_key')
            && $this->settings->has('turnstile_secret');
    }

    public function boot(): void
    {
        if (! $this->enabled()) {
            return;
        }

        add_action('login_enqueue_scripts', function () {
            wp_enqueue_script('cloudflare-turnstile', self::SCRIPT_URL, [], null, ['strategy' => 'defer', 'in_footer' => false]);
        });

        add_action('login_form', [$this, 'widget']);
        add_action('lostpassword_form', [$this, 'widget']);
        add_filter('authenticate', [$this, 'checkLogin'], 99, 1);
        add_action('lostpassword_post', [$this, 'checkReset'], 0, 1);
    }

    public function widget(): void
    {
        printf(
            '<div class="cf-turnstile snap-turnstile" data-sitekey="%s" data-theme="light" data-size="flexible"></div>',
            esc_attr($this->settings->string('turnstile_site_key'))
        );
    }

    public function checkLogin($user)
    {
        if (($GLOBALS['pagenow'] ?? '') !== 'wp-login.php' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ! isset($_POST['log'])) {
            return $user;
        }

        if (is_wp_error($user) && in_array($user->get_error_code(), ['empty_username', 'empty_password'], true)) {
            return $user;
        }

        return $this->verify() ? $user : new WP_Error('snapshot_turnstile', __('Please complete the security check and try again.', 'snapshot-core'));
    }

    public function checkReset($errors): void
    {
        if (($GLOBALS['pagenow'] ?? '') === 'wp-login.php' && $errors instanceof WP_Error && ! $this->verify()) {
            $errors->add('snapshot_turnstile', __('Please complete the security check and try again.', 'snapshot-core'));
        }
    }

    public function verify(): bool
    {
        if ($this->verified !== null) {
            return $this->verified;
        }

        $token = sanitize_text_field(wp_unslash((string) ($_POST['cf-turnstile-response'] ?? '')));

        if ($token === '' || strlen($token) > 2048) {
            return $this->verified = false;
        }

        $response = wp_remote_post(self::VERIFY_URL, [
            'timeout' => 6,
            'body' => [
                'secret' => $this->settings->string('turnstile_secret'),
                'response' => $token,
                'remoteip' => $this->ip->client(),
            ],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            if ($this->throttle->once('turnstile-down', 900)) {
                $this->log->log('turnstile.unavailable', 'Turnstile could not be reached, sign in was allowed without it', [
                    'error' => is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response),
                ]);
            }

            return $this->verified = true;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return $this->verified = is_array($body) && ! empty($body['success']);
    }
}

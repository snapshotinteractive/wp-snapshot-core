<?php

namespace Snapshot\Security;

use WP_Error;

class Hardening
{
    public function __construct(
        private Settings $settings,
        private Throttle $throttle,
        private EventLog $log,
        private Ip $ip,
    ) {}

    public function boot(bool $harden = true, bool $headers = true): void
    {
        if ($headers) {
            add_action('send_headers', [$this, 'sendHeaders']);
            add_action('login_init', [$this, 'sendHeaders']);
            add_action('admin_init', [$this, 'sendHeaders']);
        }

        if (! $harden) {
            return;
        }

        if (! defined('DISALLOW_FILE_EDIT')) {
            define('DISALLOW_FILE_EDIT', true);
        }

        add_filter('xmlrpc_enabled', '__return_false');
        add_filter('xmlrpc_methods', fn () => []);
        add_filter('pings_open', '__return_false');
        add_filter('wp_is_application_passwords_available', '__return_false');
        add_filter('the_generator', '__return_empty_string');
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'rsd_link');

        add_filter('wp_headers', function ($headers) {
            unset($headers['X-Pingback']);

            return $headers;
        });

        add_filter('request', [$this, 'blockAuthorQueries']);
        add_filter('redirect_canonical', [$this, 'stopAuthorRedirect'], 10, 2);
        add_filter('rest_pre_dispatch', [$this, 'guardUserEndpoints'], 10, 3);
        add_filter('wp_sitemaps_add_provider', fn ($provider, $name) => $name === 'users' ? false : $provider, 10, 2);

        add_filter('oembed_response_data', function ($data) {
            unset($data['author_name'], $data['author_url']);

            return $data;
        });
    }

    public function sendHeaders($wp = null): void
    {
        if (headers_sent()) {
            return;
        }

        $embed = is_object($wp) && ! empty($wp->query_vars['embed']);

        foreach (snap_security_headers() as $name => $value) {
            if ($value !== '' && $value !== null && ! ($embed && strtolower($name) === 'x-frame-options')) {
                header("{$name}: {$value}", true);
            }
        }

        $csp = $this->settings->string('headers_csp');

        if ($embed) {
            $csp = trim((string) preg_replace('/(?:^|;)\s*frame-ancestors[^;]*/i', '', $csp), ' ;');
        }

        if ($csp !== '') {
            header('Content-Security-Policy: '.str_replace(["\r", "\n"], '', $csp), true);
        }

        if (is_ssl() && $this->settings->bool('headers_hsts')) {
            header('Strict-Transport-Security: '.snap_filter('snap/security/hsts', 'max-age=31536000'), true);
        }
    }

    public function blockAuthorQueries(array $vars): array
    {
        if (is_admin() || is_user_logged_in()) {
            return $vars;
        }

        if (isset($vars['author']) || isset($vars['author_name'])) {
            $this->noteEnumeration('author query');

            return ['error' => '404'];
        }

        return $vars;
    }

    public function stopAuthorRedirect($redirect, $requested)
    {
        if (! is_user_logged_in() && preg_match('/[?&]author=/i', (string) $requested)) {
            return false;
        }

        return $redirect;
    }

    public function guardUserEndpoints($result, $server, $request)
    {
        if ($result !== null || is_user_logged_in()) {
            return $result;
        }

        if (preg_match('#^/+wp/v2/users(?:/|$)#i', (string) $request->get_route())) {
            $this->noteEnumeration('REST users endpoint');

            return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.', 'snapshot-core'), ['status' => 401]);
        }

        return $result;
    }

    private function noteEnumeration(string $how): void
    {
        $ip = $this->ip->client();

        if ($this->throttle->once('enum:'.Ip::bucket($ip), 3600)) {
            $this->log->log('hardening.enumeration', "User enumeration through the {$how}", [
                'uri' => mb_substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 300),
            ]);
        }
    }
}

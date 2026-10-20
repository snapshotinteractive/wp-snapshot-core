<?php

namespace Snapshot\Security;

use Throwable;

use function Roots\view;

class Responder
{
    public function deny(string $kind, string $reference, ?int $retryAfter = null): never
    {
        $status = $kind === 'lockout' ? 429 : 403;
        $copy = $this->copy($kind, $retryAfter);

        if (! headers_sent()) {
            nocache_headers();
            status_header($status);
            header('X-Robots-Tag: noindex, nofollow', true);

            if ($retryAfter) {
                header('Retry-After: '.max(1, $retryAfter), true);
            }
        }

        if ($this->wantsJson()) {
            if (! headers_sent()) {
                header('Content-Type: application/json; charset='.get_option('blog_charset'), true);
            }

            echo wp_json_encode([
                'code' => 'snapshot_security_'.$kind,
                'message' => $copy['message'],
                'data' => ['status' => $status, 'reference' => $reference],
            ]);

            exit;
        }

        if (! headers_sent()) {
            header('Content-Type: text/html; charset='.get_option('blog_charset'), true);
        }

        echo $this->render('snapshot::security.blocked', [
            'status' => $status,
            'title' => $copy['title'],
            'message' => $copy['message'],
            'reference' => $reference,
            'retryAt' => $retryAfter ? wp_date(get_option('time_format'), time() + $retryAfter) : null,
            'homeUrl' => home_url('/'),
            'siteName' => get_bloginfo('name'),
        ]);

        exit;
    }

    public function render(string $view, array $data): string
    {
        try {
            return view($view, $data)->render();
        } catch (Throwable $e) {
            error_log(sprintf('Snapshot security could not render %s: %s in %s:%d', $view, $e->getMessage(), $e->getFile(), $e->getLine()));

            return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>'
                .esc_html($data['title'] ?? '').'</title></head><body><main><h1>'
                .esc_html($data['title'] ?? '').'</h1><p>'
                .esc_html($data['message'] ?? '').'</p><p>'
                .esc_html($data['reference'] ?? '').'</p></main></body></html>';
        }
    }

    public function reference(): string
    {
        return strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
    }

    private function copy(string $kind, ?int $retryAfter): array
    {
        return snap_filter('snap/security/block_copy', match ($kind) {
            'lockout' => [
                'title' => __('Sign in is paused', 'snapshot-core'),
                'message' => __('There have been too many sign in attempts from your network. You can try again after the time below. If you have forgotten your password, reset it once the pause ends.', 'snapshot-core'),
            ],
            'rule' => [
                'title' => __('This request was stopped', 'snapshot-core'),
                'message' => __('Something in the request matched a pattern used in attacks, so it was not processed. If you were doing something ordinary, contact us and quote the reference below.', 'snapshot-core'),
            ],
            default => [
                'title' => __('Access from your network is blocked', 'snapshot-core'),
                'message' => __('Your address is on a list of networks associated with attacks, or it tripped our protections repeatedly. If you think this is a mistake, contact us and quote the reference below.', 'snapshot-core'),
            ],
        });
    }

    private function wantsJson(): bool
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');

        return str_contains($uri, '/'.rest_get_url_prefix().'/')
            || isset($_GET['rest_route'])
            || (str_contains($accept, 'application/json') && ! str_contains($accept, 'text/html'))
            || (defined('DOING_AJAX') && DOING_AJAX);
    }
}

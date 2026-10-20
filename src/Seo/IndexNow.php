<?php

namespace Snapshot\Seo;

use WP_Post;

class IndexNow
{
    public const ENDPOINT = 'https://api.indexnow.org/indexnow';

    public const LOG = 'snapshot_seo_indexnow_log';

    private array $queue = [];

    public function __construct(private Engine $engine) {}

    public function boot(): void
    {
        add_action('parse_request', [$this, 'serveKey'], 0);
        add_action('transition_post_status', [$this, 'changed'], 20, 3);
        add_action('snap/seo/url_changed', fn ($old, $new) => $this->queue([$old, $new]), 10, 2);
        add_action('shutdown', [$this, 'flush']);
    }

    public function key(): string
    {
        $key = $this->engine->settings()->string('indexnow_key');

        if (! preg_match('/^[a-zA-Z0-9-]{8,128}$/', $key)) {
            $key = Settings::newKey();
            $this->engine->settings()->set('indexnow_key', $key);
        }

        return $key;
    }

    public function keyUrl(): string
    {
        return home_url('/'.$this->key().'.txt');
    }

    public function serveKey(): void
    {
        $path = $this->engine->requestPath();

        if (! preg_match('#^/([a-zA-Z0-9-]{8,128})\.txt$#', $path, $match) || ! hash_equals($this->key(), $match[1])) {
            return;
        }

        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo $this->key();
        exit;
    }

    public function changed(string $new, string $old, WP_Post $post): void
    {
        if (! in_array('publish', [$new, $old], true) || wp_is_post_revision($post) || wp_is_post_autosave($post) || ! is_post_type_viewable($post->post_type)) {
            return;
        }

        if ($new === 'publish' && ! $this->engine->resolver()->resolve(Context::forPost($post))['robots']['index'] && $old !== 'publish') {
            return;
        }

        $this->queue([(string) get_permalink($post)]);
    }

    public function queue(array $urls): void
    {
        foreach ($urls as $url) {
            if ($url !== '' && str_starts_with($url, home_url())) {
                $this->queue[$url] = true;
            }
        }
    }

    public function flush(): void
    {
        if ($this->queue === [] || ! $this->allowed()) {
            return;
        }

        $urls = array_values(array_filter(array_keys($this->queue), function ($url) {
            $key = 'snapshot_seo_inow_'.md5($url);

            if (get_transient($key)) {
                return false;
            }

            set_transient($key, 1, 10 * MINUTE_IN_SECONDS);

            return true;
        }));

        $this->queue = [];

        if ($urls) {
            $this->submit($urls, false);
        }
    }

    public function submit(array $urls, bool $blocking = true): array
    {
        $response = wp_remote_post(self::ENDPOINT, [
            'blocking' => $blocking,
            'timeout' => $blocking ? 15 : 3,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body' => wp_json_encode([
                'host' => (string) wp_parse_url(home_url(), PHP_URL_HOST),
                'key' => $this->key(),
                'keyLocation' => $this->keyUrl(),
                'urlList' => array_slice(array_values(array_unique($urls)), 0, 10000),
            ]),
        ]);

        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $entry = [
            'at' => time(),
            'count' => count($urls),
            'first' => $urls[0] ?? '',
            'code' => $blocking ? $code : null,
            'error' => is_wp_error($response) ? $response->get_error_message() : '',
        ];
        $log = array_slice([$entry, ...(array) get_option(self::LOG, [])], 0, 20);
        update_option(self::LOG, $log, false);

        return $entry;
    }

    public function allowed(): bool
    {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        return (bool) get_option('blog_public')
            && wp_get_environment_type() === 'production'
            && ! preg_match('/(^localhost$|\.test$|\.local$|^127\.|staging|wpengine\.com$|wpenginepowered\.com$)/i', $host);
    }
}

<?php

namespace Snapshot\Seo;

class Cleanup
{
    public function __construct(private Settings $settings) {}

    public function boot(): void
    {
        $on = fn (string $key) => $this->settings->bool("cleanup_{$key}");

        if ($on('generator')) {
            remove_action('wp_head', 'wp_generator');
            add_filter('the_generator', '__return_empty_string');
        }

        if ($on('shortlink')) {
            remove_action('wp_head', 'wp_shortlink_wp_head', 10);
            remove_action('template_redirect', 'wp_shortlink_header', 11);
        }

        if ($on('rsd')) {
            remove_action('wp_head', 'rsd_link');
            remove_action('wp_head', 'wlwmanifest_link');
        }

        if ($on('emoji')) {
            remove_action('wp_head', 'print_emoji_detection_script', 7);
            remove_action('wp_print_styles', 'print_emoji_styles');
            remove_action('wp_enqueue_scripts', 'wp_enqueue_emoji_styles');
            remove_filter('the_content_feed', 'wp_staticize_emoji');
            remove_filter('comment_text_rss', 'wp_staticize_emoji');
            remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
            add_filter('emoji_svg_url', '__return_false');
        }

        if ($on('comment_feeds')) {
            add_filter('feed_links_show_comments_feed', '__return_false');
            remove_action('wp_head', 'feed_links_extra', 3);
            add_action('template_redirect', function () {
                if (is_comment_feed()) {
                    wp_safe_redirect(home_url('/'), 301, 'Snapshot SEO');
                    exit;
                }
            }, 1);
        }

        if ($on('replytocom')) {
            add_action('template_redirect', function () {
                if (isset($_GET['replytocom']) && is_singular()) {
                    wp_safe_redirect(remove_query_arg('replytocom'), 301, 'Snapshot SEO');
                    exit;
                }
            }, 1);
        }

        if ($on('spam_search')) {
            add_action('template_redirect', function () {
                if (! is_search()) {
                    return;
                }

                $query = (string) get_search_query(false);

                if (mb_strlen($query) > 100 || preg_match('~https?://|www\.|\.(?:com|net|ru|cn|xyz|top)\b|[\x{4e00}-\x{9fff}]{6,}~iu', $query)) {
                    global $wp_query;
                    $wp_query->set_404();
                    status_header(404);
                    nocache_headers();
                }
            }, 1);
        }
    }

    public function attachments(): void
    {
        add_action('template_redirect', function () {
            if (! is_attachment()) {
                return;
            }

            $url = wp_get_attachment_url((int) get_queried_object_id());

            if ($url) {
                wp_redirect($url, 301, 'Snapshot SEO');
                exit;
            }
        }, 1);
    }
}

<?php

namespace Snapshot\Seo;

class Head
{
    private const VERIFICATION = [
        'verify_google' => 'google-site-verification',
        'verify_bing' => 'msvalidate.01',
        'verify_yandex' => 'yandex-verification',
        'verify_baidu' => 'baidu-site-verification',
        'verify_pinterest' => 'p:domain_verify',
        'verify_facebook' => 'facebook-domain-verification',
    ];

    private ?array $current = null;

    private ?Context $context = null;

    public function __construct(private Engine $engine) {}

    public function boot(bool $meta, bool $social): void
    {
        if ($meta) {
            add_filter('pre_get_document_title', [$this, 'title'], 15);
            add_filter('wp_robots', [$this, 'robots'], 20);
            remove_action('wp_head', 'rel_canonical');
            add_action('wp_head', [$this, 'meta'], 1);
            add_filter('get_canonical_url', [$this, 'canonicalFilter'], 20, 2);
        }

        if ($social) {
            add_action('wp_head', [$this, 'social'], 2);
        }
    }

    public function context(): Context
    {
        return $this->context ??= Context::current();
    }

    public function current(): array
    {
        return $this->current ??= $this->engine->resolver()->resolve($this->context());
    }

    public function title($title)
    {
        if (is_feed() || is_admin()) {
            return $title;
        }

        return esc_html($this->current()['title']);
    }

    public function robots(array $robots): array
    {
        if (! get_option('blog_public')) {
            return $robots;
        }

        $current = $this->current()['robots'];

        if (! $current['index']) {
            $robots['noindex'] = true;
            unset($robots['max-image-preview'], $robots['max-snippet'], $robots['max-video-preview']);
        } elseif ($this->engine->settings()->bool('max_image_preview')) {
            $robots['max-image-preview'] = 'large';
            $robots['max-snippet'] = '-1';
            $robots['max-video-preview'] = '-1';
        }

        if (! $current['follow']) {
            $robots['nofollow'] = true;
            unset($robots['follow']);
        } elseif (! $current['index']) {
            $robots['follow'] = true;
        }

        return $robots;
    }

    public function canonicalFilter($url, $post)
    {
        if (! $post instanceof \WP_Post) {
            return $url;
        }

        $custom = (string) $this->engine->meta()->post($post->ID, 'canonical');

        return $custom !== '' && wp_http_validate_url($custom) ? $custom : $url;
    }

    public function meta(): void
    {
        $current = $this->current();
        $tags = [];

        if ($current['description'] !== '') {
            $tags[] = sprintf('<meta name="description" content="%s">', esc_attr($current['description']));
        }

        if ($current['canonical'] !== '') {
            $tags[] = sprintf('<link rel="canonical" href="%s">', esc_url($current['canonical']));
        }

        if (is_front_page()) {
            foreach (self::VERIFICATION as $setting => $name) {
                $value = $this->engine->settings()->string($setting);

                if ($value !== '' && preg_match('/^[\w\-.=+\/]{4,200}$/', $value)) {
                    $tags[] = sprintf('<meta name="%s" content="%s">', esc_attr($name), esc_attr($value));
                }
            }
        }

        echo implode("\n", $tags)."\n";
    }

    public function social(): void
    {
        if (is_404()) {
            return;
        }

        $current = $this->current();
        $context = $this->context();
        $social = $current['social'];
        $identity = $this->engine->identity();
        $post = $context->post;
        $url = $current['canonical'] ?: ($post ? get_permalink($post) : home_url(add_query_arg([])));
        $article = in_array($current['schema_type'], ['Article', 'NewsArticle'], true) && $context->kind === 'post';

        $properties = [
            'og:locale' => str_replace('-', '_', get_bloginfo('language')),
            'og:type' => $article ? 'article' : ($current['schema_type'] === 'Person' && $context->kind === 'post' ? 'profile' : 'website'),
            'og:title' => $social['title'],
            'og:description' => $social['description'],
            'og:url' => $url,
            'og:site_name' => $identity->name(),
        ];

        if ($image = $social['image']) {
            $properties['og:image'] = $image['url'];
            $properties['og:image:width'] = $image['width'] ?: '';
            $properties['og:image:height'] = $image['height'] ?: '';
            $properties['og:image:alt'] = $image['alt'];
            $properties['og:image:type'] = $image['type'];
        }

        if ($article && $post) {
            $properties['article:published_time'] = get_post_time('c', true, $post);
            $properties['article:modified_time'] = get_post_modified_time('c', true, $post);
        }

        if ($appId = $this->engine->settings()->string('facebook_app_id')) {
            $properties['fb:app_id'] = $appId;
        }

        $names = [
            'twitter:card' => ! empty($social['image']) ? 'summary_large_image' : 'summary',
            'twitter:site' => $identity->xHandle(),
        ];

        foreach (array_slice($this->labels($context), 0, 2) as $index => [$label, $data]) {
            $names['twitter:label'.($index + 1)] = $label;
            $names['twitter:data'.($index + 1)] = $data;
        }

        $tags = [];

        foreach ($properties as $property => $content) {
            if ((string) $content !== '') {
                $tags[] = sprintf('<meta property="%s" content="%s">', esc_attr($property), esc_attr((string) $content));
            }
        }

        foreach ($names as $name => $content) {
            if ((string) $content !== '') {
                $tags[] = sprintf('<meta name="%s" content="%s">', esc_attr($name), esc_attr((string) $content));
            }
        }

        echo implode("\n", $tags)."\n";
    }

    public function labels(Context $context): array
    {
        $variables = $this->engine->variables();
        $labels = [];

        if ($context->post?->post_type === 'location') {
            $labels[] = [__('Where', 'snapshot-core'), $variables->value('location_city', $context)];
            $labels[] = [__('Phone', 'snapshot-core'), $variables->field('phone_display', $context)];
        } elseif ($context->post?->post_type === 'event') {
            $labels[] = [__('When', 'snapshot-core'), $variables->value('event_when', $context)];
        } elseif ($context->post?->post_type === 'staff') {
            $labels[] = [__('Role', 'snapshot-core'), $variables->field('job_title', $context)];
            $labels[] = [__('Based at', 'snapshot-core'), $variables->value('location_city', $context)];
        } elseif ($context->post?->post_type === 'post') {
            $labels[] = [__('Published', 'snapshot-core'), $variables->value('date', $context)];
        }

        return array_values(array_filter($labels, fn ($label) => trim((string) $label[1]) !== ''));
    }
}

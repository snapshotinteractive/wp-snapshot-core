<?php

namespace Snapshot\Seo\Admin;

use Snapshot\Seo\Context;
use Snapshot\Seo\Engine;
use Snapshot\Seo\Resolver;
use Snapshot\Seo\Text;
use WP_Post;
use WP_REST_Request;
use WP_Term;

class Editor
{
    private const LEGACY_FIELDS = [
        'seo_title' => 'title',
        'seo_description' => 'description',
        'seo_focus_keyphrase' => 'focuskw',
        'seo_robots' => 'robots',
        'seo_nofollow' => 'nofollow',
        'seo_canonical' => 'canonical',
        'seo_social_title' => 'social_title',
        'seo_social_description' => 'social_description',
        'seo_social_image' => 'social_image',
        'seo_breadcrumb_title' => 'breadcrumb_title',
        'seo_key_page' => 'key_page',
    ];

    public function __construct(private Engine $engine) {}

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
        add_action('save_post', fn ($id) => Text::forget((int) $id));

        foreach (array_keys(self::LEGACY_FIELDS) as $name) {
            add_filter("acf/load_value/name={$name}", [$this, 'legacyValue'], 10, 3);
        }

        foreach (['field_seo_title', 'field_seo_description', 'field_seo_term_title', 'field_seo_term_description'] as $key) {
            add_filter("acf/prepare_field/key={$key}", [$this, 'placeholder']);
        }

        if (is_admin()) {
            add_action('admin_init', [$this, 'columns']);
        }
    }

    public function legacyValue($value, $objectId, $field)
    {
        if ($value !== null && $value !== false && $value !== '') {
            return $value;
        }

        $key = self::LEGACY_FIELDS[$field['name'] ?? ''] ?? null;

        if (! $key) {
            return $value;
        }

        if (is_numeric($objectId) && ! metadata_exists('post', (int) $objectId, $field['name'])) {
            $legacy = $this->engine->meta()->legacyPost((int) $objectId, $key);
        } elseif (is_string($objectId) && str_starts_with($objectId, 'term_') && ! metadata_exists('term', (int) substr($objectId, 5), $field['name'])) {
            $legacy = $this->engine->meta()->legacyTerm((int) substr($objectId, 5), $key);
        } else {
            return $value;
        }

        if (is_bool($legacy)) {
            return $legacy ? 1 : $value;
        }

        return $legacy !== '' && $legacy !== 0 ? $legacy : $value;
    }

    public function placeholder($field)
    {
        if (! $field) {
            return $field;
        }

        $context = $this->screenContext();

        if ($context) {
            $kind = str_ends_with($field['key'], 'title') ? 'title' : 'description';
            $template = $this->engine->resolver()->template($context, $kind);
            $resolved = $context->post || $context->term ? $this->engine->variables()->replace($template, $context) : '';

            if ($kind === 'description' && $resolved !== '') {
                $resolved = $this->engine->variables()->trim($resolved, Resolver::DESCRIPTION_LENGTH);
            }

            $field['placeholder'] = $resolved !== '' ? $resolved : $template;
        }

        return $field;
    }

    public function routes(): void
    {
        register_rest_route('snapshot/v1', '/seo/preview', [
            'methods' => 'POST',
            'callback' => [$this, 'preview'],
            'permission_callback' => function (WP_REST_Request $request) {
                $id = (int) $request->get_param('id');

                return $request->get_param('kind') === 'term'
                    ? current_user_can('edit_term', $id)
                    : current_user_can('edit_post', $id);
            },
            'args' => [
                'id' => ['type' => 'integer', 'required' => true],
                'kind' => ['type' => 'string', 'enum' => ['post', 'term'], 'default' => 'post'],
                'fields' => ['type' => 'object', 'default' => []],
            ],
        ]);

        register_rest_route('snapshot/v1', '/seo/template', [
            'methods' => 'POST',
            'callback' => [$this, 'template'],
            'permission_callback' => fn () => current_user_can('manage_options'),
            'args' => [
                'template' => ['type' => 'string', 'required' => true],
                'scope' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    public function preview(WP_REST_Request $request): array
    {
        $fields = array_map(fn ($value) => is_scalar($value) ? sanitize_text_field((string) $value) : '', (array) $request->get_param('fields'));
        $overrides = [];

        foreach (['seo_title' => 'title', 'seo_description' => 'description', 'seo_focus_keyphrase' => 'focuskw', 'seo_social_title' => 'social_title', 'seo_social_description' => 'social_description', 'seo_social_image' => 'social_image', 'seo_robots' => 'robots', 'seo_nofollow' => 'nofollow', 'seo_schema_type' => 'schema_type', 'seo_canonical' => 'canonical'] as $name => $key) {
            if (array_key_exists($name, $fields)) {
                $overrides[$key] = $fields[$name];
            }
        }

        if ($request->get_param('kind') === 'term') {
            $term = get_term((int) $request->get_param('id'));

            if (! $term instanceof WP_Term) {
                return ['error' => 'not_found'];
            }

            $context = Context::forTerm($term, $overrides);
            $resolved = $this->engine->resolver()->resolve($context);

            return $this->payload($resolved, (string) get_term_link($term)) + ['checks' => []];
        }

        $post = get_post((int) $request->get_param('id'));

        if (! $post instanceof WP_Post) {
            return ['error' => 'not_found'];
        }

        $draft = clone $post;

        if (($fields['post_title'] ?? '') !== '') {
            $draft->post_title = $fields['post_title'];
        }

        if (array_key_exists('post_excerpt', $fields)) {
            $draft->post_excerpt = $fields['post_excerpt'];
        }

        require_once ABSPATH.'wp-admin/includes/post.php';

        $context = Context::forPost($draft, $overrides);
        $resolved = $this->engine->resolver()->resolve($context);
        $url = $post->post_status === 'publish' ? (string) get_permalink($post) : (string) (get_sample_permalink($post)[0] ?? get_permalink($post));
        $url = str_replace(['%postname%', '%pagename%'], $fields['post_name'] ?? $post->post_name, $url);
        $analysis = $this->engine->analysis()->run($draft, $resolved, (string) ($overrides['focuskw'] ?? $this->engine->meta()->post($post->ID, 'focuskw')));

        return $this->payload($resolved, $url) + $analysis;
    }

    public function template(WP_REST_Request $request): array
    {
        $template = sanitize_text_field((string) $request->get_param('template'));
        [$scope, $object] = array_pad(explode(':', (string) $request->get_param('scope'), 2), 2, '');
        $context = $this->sampleContext($scope, $object);

        if (! $context) {
            return ['output' => '', 'sample' => ''];
        }

        $output = $this->engine->variables()->replace($template, $context);

        return [
            'output' => str_contains((string) $request->get_param('field'), 'description') ? $this->engine->variables()->trim($output, Resolver::DESCRIPTION_LENGTH) : $output,
            'sample' => $context->post ? snap_plain_title($context->post) : ($context->term?->name ?? ''),
        ];
    }

    public function sampleContext(string $scope, string $object): ?Context
    {
        return match ($scope) {
            'pt' => ($post = $this->sample($object)) ? Context::forPost($post) : null,
            'pta' => new Context('archive', type: $object),
            'tax' => ($term = $this->sampleTerm($object)) ? Context::forTerm($term) : null,
            'home' => ($front = (int) get_option('page_on_front')) && get_post($front) ? Context::forPost(get_post($front)) : new Context('front', type: 'post'),
            'search' => new Context('search', search: __('services', 'snapshot-core')),
            '404' => new Context('404'),
            default => null,
        };
    }

    public function sample(string $type): ?WP_Post
    {
        $found = get_posts(['post_type' => $type, 'post_status' => 'publish', 'posts_per_page' => 1, 'no_found_rows' => true, 'orderby' => 'modified']);

        return $found[0] ?? null;
    }

    public function sampleTerm(string $taxonomy): ?WP_Term
    {
        $terms = get_terms(['taxonomy' => $taxonomy, 'number' => 1, 'orderby' => 'count', 'order' => 'DESC', 'hide_empty' => false]);

        return is_array($terms) && $terms ? $terms[0] : null;
    }

    public function columns(): void
    {
        foreach (snap_seo_editor_types() as $type) {
            add_filter("manage_{$type}_posts_columns", fn ($columns) => $columns + ['snap_seo' => __('SEO', 'snapshot-core')], 30);
            add_action("manage_{$type}_posts_custom_column", [$this, 'column'], 10, 2);
        }
    }

    public function column(string $column, int $postId): void
    {
        if ($column !== 'snap_seo') {
            return;
        }

        $post = get_post($postId);

        if (! $post) {
            return;
        }

        $resolved = $this->engine->resolver()->resolve(Context::forPost($post));
        $pills = [];

        if (! $resolved['robots']['index']) {
            $pills[] = ['is-warning', __('Hidden', 'snapshot-core'), $resolved['robots']['reason']];
        } else {
            $pills[] = ['is-ok', __('Indexed', 'snapshot-core'), ''];
        }

        if ($resolved['description'] === '') {
            $pills[] = ['is-critical', __('No description', 'snapshot-core'), ''];
        } elseif ($resolved['description_source'] === 'custom') {
            $pills[] = ['is-info', __('Custom', 'snapshot-core'), __('Custom description', 'snapshot-core')];
        }

        if ($this->engine->meta()->post($postId, 'key_page')) {
            $pills[] = ['is-notice', __('Key page', 'snapshot-core'), ''];
        }

        foreach ($pills as [$class, $label, $title]) {
            printf('<span class="snap-pill %s"%s>%s</span> ', esc_attr($class), $title !== '' ? ' title="'.esc_attr($title).'"' : '', esc_html($label));
        }
    }

    private function screenContext(): ?Context
    {
        global $post;

        if ($post instanceof WP_Post) {
            return Context::forPost($post);
        }

        $termId = (int) ($_GET['tag_ID'] ?? 0);

        if ($termId && ($term = get_term($termId)) instanceof WP_Term) {
            return Context::forTerm($term);
        }

        $taxonomy = sanitize_key((string) ($_GET['taxonomy'] ?? ''));

        if ($taxonomy !== '') {
            return new Context('term', type: $taxonomy);
        }

        return null;
    }

    private function payload(array $resolved, string $url): array
    {
        $image = $resolved['social']['image'];

        return [
            'title' => $resolved['title'],
            'title_source' => $resolved['title_source'],
            'title_template' => $resolved['title_template'],
            'description' => $resolved['description'],
            'description_source' => $resolved['description_source'],
            'url' => $url,
            'display_url' => $this->displayUrl($url),
            'robots' => $resolved['robots'],
            'schema_type' => $resolved['schema_type'],
            'social' => [
                'title' => $resolved['social']['title'],
                'description' => $resolved['social']['description'],
                'image' => $image ? (wp_get_attachment_image_url($image['id'], 'large') ?: $image['url']) : '',
                'host' => strtoupper((string) wp_parse_url(home_url(), PHP_URL_HOST)),
            ],
        ];
    }

    private function displayUrl(string $url): string
    {
        $parts = wp_parse_url($url);
        $segments = array_filter(explode('/', trim((string) ($parts['path'] ?? ''), '/')));

        return implode(' › ', [($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? ''), ...array_map('urldecode', $segments)]);
    }
}

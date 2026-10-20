<?php

namespace Snapshot\Seo;

class Resolver
{
    public const DESCRIPTION_LENGTH = 158;

    private array $cache = [];

    public function __construct(private Settings $settings, private Meta $meta, private Variables $variables, private Identity $identity) {}

    public function resolve(Context $context): array
    {
        if ($context->overrides === [] && isset($this->cache[$context->key()])) {
            return $this->cache[$context->key()];
        }

        [$titleTemplate, $titleSource] = $this->pick($context, 'title');
        [$descriptionTemplate, $descriptionSource] = $this->pick($context, 'description');
        $title = $this->variables->replace($titleTemplate, $context);
        $description = $this->variables->replace($descriptionTemplate, $context);

        if ($descriptionSource !== 'custom') {
            $description = $this->variables->trim($description, self::DESCRIPTION_LENGTH);
        }

        if ($title === '') {
            $title = $this->variables->replace('%%title%% %%sep%% %%sitename%%', $context);
        }

        $robots = $this->robots($context);

        $result = [
            'title' => $title,
            'title_template' => $titleTemplate,
            'title_source' => $titleSource,
            'description' => $description,
            'description_template' => $descriptionTemplate,
            'description_source' => $descriptionSource,
            'robots' => $robots,
            'canonical' => $robots['index'] ? $this->canonical($context) : '',
            'social' => $this->social($context, $title, $description),
            'schema_type' => $this->schemaType($context),
        ];

        if ($context->overrides === []) {
            $this->cache[$context->key()] = $result;
        }

        return $result;
    }

    public function template(Context $context, string $field): string
    {
        $settings = $this->settings;

        return match ($context->kind) {
            'front' => $settings->string("home_{$field}"),
            'post' => $settings->get("pt_{$context->type}_{$field}") ?? snap_seo_type_preset($context->type, $field),
            'archive' => $settings->get("pta_{$context->type}_{$field}") ?? snap_seo_type_preset($context->type, "archive_{$field}"),
            'term' => $settings->get("tax_{$context->type}_{$field}") ?? ($field === 'title' ? '%%term_title%% %%sep%% %%sitename%%' : '%%term_description%%'),
            'search' => $field === 'title' ? $settings->string('search_title') : '',
            '404' => $field === 'title' ? $settings->string('notfound_title') : '',
            default => $field === 'title' ? '%%archive_title%%[ %%sep%% %%page%%] %%sep%% %%sitename%%' : '',
        };
    }

    public function custom(Context $context, string $field): string
    {
        if (array_key_exists($field, $context->overrides)) {
            return trim((string) $context->overrides[$field]);
        }

        if ($context->post) {
            return (string) $this->meta->post($context->post->ID, $field);
        }

        if ($context->term) {
            return (string) $this->meta->term($context->term->term_id, $field);
        }

        return '';
    }

    public function robots(Context $context): array
    {
        $index = true;
        $reason = '';
        $settings = $this->settings;
        $post = $context->post;
        $override = $post ? $this->meta->post($post->ID, 'robots') : ($context->term ? $this->meta->term($context->term->term_id, 'robots') : '');

        if (array_key_exists('robots', $context->overrides)) {
            $override = (string) $context->overrides['robots'];
        }

        switch ($context->kind) {
            case 'post':
            case 'front':
                if ($post && ($post->post_status !== 'publish' || post_password_required($post))) {
                    [$index, $reason] = [false, __('not published', 'snapshot-core')];
                } elseif ($context->kind === 'post' && ! $settings->bool("pt_{$context->type}_index")) {
                    [$index, $reason] = [false, __('content type hidden', 'snapshot-core')];
                }
                break;
            case 'archive':
                if (! $settings->bool("pt_{$context->type}_index")) {
                    [$index, $reason] = [false, __('content type hidden', 'snapshot-core')];
                }
                break;
            case 'term':
                if ($context->term && ! $settings->bool("tax_{$context->type}_index")) {
                    [$index, $reason] = [false, __('taxonomy hidden', 'snapshot-core')];
                } elseif ($context->term && $settings->bool('noindex_empty_terms') && (int) $context->term->count === 0) {
                    [$index, $reason] = [false, __('empty archive', 'snapshot-core')];
                }
                break;
            case 'search':
                if ($settings->bool('noindex_search')) {
                    [$index, $reason] = [false, __('search results', 'snapshot-core')];
                }
                break;
            case 'date':
                if ($settings->bool('noindex_date_archives')) {
                    [$index, $reason] = [false, __('date archive', 'snapshot-core')];
                }
                break;
            case 'author':
            case '404':
                [$index, $reason] = [false, $context->kind === '404' ? __('page not found', 'snapshot-core') : __('author archive', 'snapshot-core')];
                break;
        }

        if ($override === 'noindex') {
            [$index, $reason] = [false, __('set on this entry', 'snapshot-core')];
        } elseif ($override === 'index' && ($post === null || $post->post_status === 'publish')) {
            [$index, $reason] = [true, ''];
        }

        $nofollow = $post ? (bool) $this->meta->post($post->ID, 'nofollow') : false;

        if (array_key_exists('nofollow', $context->overrides)) {
            $nofollow = (bool) $context->overrides['nofollow'];
        }

        return ['index' => $index, 'follow' => ! $nofollow, 'reason' => $reason];
    }

    public function canonical(Context $context): string
    {
        $custom = $this->custom($context, 'canonical');

        if ($custom !== '' && wp_http_validate_url($custom)) {
            return $custom;
        }

        if (in_array($context->kind, ['search', '404'], true)) {
            return '';
        }

        if ($context->kind === 'post' && $context->post) {
            $url = (string) get_permalink($context->post);

            return $context->paged > 1 ? trailingslashit($url).user_trailingslashit((string) $context->paged, 'single_paged') : $url;
        }

        if ($context->kind === 'front') {
            $url = home_url('/');
        } elseif ($context->kind === 'term' && $context->term) {
            $url = (string) get_term_link($context->term);
        } elseif ($context->kind === 'archive' && $context->post) {
            $url = (string) get_permalink($context->post);
        } elseif ($context->kind === 'archive') {
            $url = (string) get_post_type_archive_link($context->type);
        } else {
            global $wp;
            $url = home_url(user_trailingslashit((string) ($wp->request ?? '')));

            return $this->withParams(preg_replace('#/page/\d+/?$#', '/', $url), $context);
        }

        if ($context->paged > 1) {
            $url = trailingslashit($url).user_trailingslashit('page/'.$context->paged, 'paged');
        }

        return $this->withParams($url, $context);
    }

    public function social(Context $context, string $title, string $description): array
    {
        $customTitle = $this->custom($context, 'social_title');
        $customDescription = $this->custom($context, 'social_description');
        $sitename = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $sep = $this->settings->string('separator') ?: '-';
        $plainTitle = $title;

        if ($context->kind !== 'front' && str_ends_with($title, " {$sep} {$sitename}")) {
            $plainTitle = substr($title, 0, -strlen(" {$sep} {$sitename}"));
        }

        return [
            'title' => $customTitle !== '' ? $this->variables->replace($customTitle, $context) : $plainTitle,
            'description' => $customDescription !== '' ? $this->variables->replace($customDescription, $context) : $description,
            'image' => $this->image($context),
        ];
    }

    public function image(Context $context): ?array
    {
        $candidates = [];

        if (array_key_exists('social_image', $context->overrides)) {
            $candidates[] = (int) $context->overrides['social_image'];
        } elseif ($context->post) {
            $candidates[] = (int) $this->meta->post($context->post->ID, 'social_image');
        } elseif ($context->term) {
            $candidates[] = (int) $this->meta->term($context->term->term_id, 'social_image');
        }

        if ($context->post) {
            $candidates[] = (int) get_post_thumbnail_id($context->post);
        }

        $candidates[] = $this->identity->shareImageId();

        foreach (array_filter($candidates) as $id) {
            $source = wp_get_attachment_image_src($id, 'full');

            if ($source) {
                return [
                    'id' => $id,
                    'url' => $source[0],
                    'width' => (int) $source[1],
                    'height' => (int) $source[2],
                    'alt' => trim((string) get_post_meta($id, '_wp_attachment_image_alt', true)),
                    'type' => (string) get_post_mime_type($id),
                ];
            }
        }

        return null;
    }

    public function schemaType(Context $context): string
    {
        if ($context->post) {
            $custom = (string) ($context->overrides['schema_type'] ?? $this->meta->post($context->post->ID, 'schema_type'));

            if ($custom !== '' && array_key_exists($custom, snap_seo_schema_types())) {
                return $custom;
            }
        }

        return match ($context->kind) {
            'post' => (string) ($this->settings->get("pt_{$context->type}_schema") ?: 'WebPage'),
            'front' => 'WebPage',
            'archive', 'term', 'date' => 'CollectionPage',
            'search' => 'SearchResultsPage',
            default => 'WebPage',
        };
    }

    private function pick(Context $context, string $field): array
    {
        $custom = in_array($context->kind, ['post', 'front', 'term', 'archive'], true) ? $this->custom($context, $field) : '';

        if ($custom !== '') {
            return [$custom, 'custom'];
        }

        return [(string) $this->template($context, $field), 'template'];
    }

    private function withParams(string $url, Context $context): string
    {
        $keep = array_filter($this->settings->lines('canonical_params'), fn ($param) => isset($_GET[$param]) && is_scalar($_GET[$param]));

        foreach ($keep as $param) {
            $url = add_query_arg($param, rawurlencode(sanitize_text_field(wp_unslash((string) $_GET[$param]))), $url);
        }

        return $url;
    }
}

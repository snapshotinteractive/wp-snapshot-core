<?php

namespace Snapshot\Seo;

use WP_Post;
use WP_Term;

class Context
{
    public function __construct(
        public string $kind,
        public ?WP_Post $post = null,
        public ?WP_Term $term = null,
        public string $type = '',
        public int $paged = 1,
        public int $pages = 1,
        public string $search = '',
        public array $overrides = [],
    ) {}

    public static function current(): self
    {
        global $wp_query;

        $paged = max(1, (int) get_query_var('paged'), (int) get_query_var('page'));
        $pages = max(1, (int) ($wp_query->max_num_pages ?? 1));

        if (is_404()) {
            return new self('404');
        }

        if (is_search()) {
            return new self('search', paged: $paged, pages: $pages, search: (string) get_search_query(false));
        }

        if (is_front_page() && ! is_home()) {
            $post = get_queried_object();

            return new self('front', $post instanceof WP_Post ? $post : null, type: 'page', paged: $paged);
        }

        if (is_front_page() && is_home()) {
            return new self('front', type: 'post', paged: $paged, pages: $pages);
        }

        if (is_home()) {
            $post = get_queried_object();

            return new self('archive', $post instanceof WP_Post ? $post : null, type: 'post', paged: $paged, pages: $pages);
        }

        if (is_singular()) {
            $post = get_queried_object();

            return new self('post', $post instanceof WP_Post ? $post : null, type: $post instanceof WP_Post ? $post->post_type : '', paged: $paged);
        }

        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();

            return new self('term', term: $term instanceof WP_Term ? $term : null, type: $term instanceof WP_Term ? $term->taxonomy : '', paged: $paged, pages: $pages);
        }

        if (is_post_type_archive()) {
            $type = get_query_var('post_type');

            return new self('archive', type: is_array($type) ? (string) reset($type) : (string) $type, paged: $paged, pages: $pages);
        }

        if (is_date()) {
            return new self('date', type: 'post', paged: $paged, pages: $pages);
        }

        if (is_author()) {
            return new self('author', paged: $paged, pages: $pages);
        }

        return new self('other', paged: $paged, pages: $pages);
    }

    public static function forPost(WP_Post $post, array $overrides = []): self
    {
        $front = (int) get_option('page_on_front');
        $blog = (int) get_option('page_for_posts');

        if ($front && $post->ID === $front && get_option('show_on_front') === 'page') {
            return new self('front', $post, type: 'page', overrides: $overrides);
        }

        if ($blog && $post->ID === $blog) {
            return new self('archive', $post, type: 'post', overrides: $overrides);
        }

        return new self('post', $post, type: $post->post_type, overrides: $overrides);
    }

    public static function forTerm(WP_Term $term, array $overrides = []): self
    {
        return new self('term', term: $term, type: $term->taxonomy, overrides: $overrides);
    }

    public function isPost(): bool
    {
        return $this->post instanceof WP_Post && in_array($this->kind, ['post', 'front'], true);
    }

    public function objectId(): int
    {
        return $this->post?->ID ?? $this->term?->term_id ?? 0;
    }

    public function key(): string
    {
        return $this->kind.':'.$this->type.':'.$this->objectId().':'.$this->paged;
    }
}

<?php

namespace Snapshot\Seo;

class Meta
{
    public const KEYS = [
        'title' => 'seo_title',
        'description' => 'seo_description',
        'focuskw' => 'seo_focus_keyphrase',
        'robots' => 'seo_robots',
        'nofollow' => 'seo_nofollow',
        'canonical' => 'seo_canonical',
        'social_title' => 'seo_social_title',
        'social_description' => 'seo_social_description',
        'social_image' => 'seo_social_image',
        'schema_type' => 'seo_schema_type',
        'breadcrumb_title' => 'seo_breadcrumb_title',
        'key_page' => 'seo_key_page',
        'sitemap' => 'seo_sitemap',
    ];

    private const YOAST_POST = [
        'title' => '_yoast_wpseo_title',
        'description' => '_yoast_wpseo_metadesc',
        'focuskw' => '_yoast_wpseo_focuskw',
        'robots' => '_yoast_wpseo_meta-robots-noindex',
        'nofollow' => '_yoast_wpseo_meta-robots-nofollow',
        'canonical' => '_yoast_wpseo_canonical',
        'social_title' => '_yoast_wpseo_opengraph-title',
        'social_description' => '_yoast_wpseo_opengraph-description',
        'social_image' => '_yoast_wpseo_opengraph-image-id',
        'breadcrumb_title' => '_yoast_wpseo_bctitle',
        'key_page' => '_yoast_wpseo_is_cornerstone',
    ];

    private const YOAST_TERM = [
        'title' => 'wpseo_title',
        'description' => 'wpseo_desc',
        'robots' => 'wpseo_noindex',
        'canonical' => 'wpseo_canonical',
        'social_title' => 'wpseo_opengraph-title',
        'social_description' => 'wpseo_opengraph-description',
        'social_image' => 'wpseo_opengraph-image-id',
        'breadcrumb_title' => 'wpseo_bctitle',
    ];

    private const RANK_MATH = [
        'title' => 'rank_math_title',
        'description' => 'rank_math_description',
        'focuskw' => 'rank_math_focus_keyword',
        'robots' => 'rank_math_robots',
        'nofollow' => 'rank_math_robots',
        'canonical' => 'rank_math_canonical_url',
        'social_title' => 'rank_math_facebook_title',
        'social_description' => 'rank_math_facebook_description',
        'social_image' => 'rank_math_facebook_image_id',
        'breadcrumb_title' => 'rank_math_breadcrumb_title',
        'key_page' => 'rank_math_pillar_content',
    ];

    private ?array $yoastTerms = null;

    public function post(int $id, string $key)
    {
        $meta = self::KEYS[$key] ?? null;

        if (! $meta || ! $id) {
            return '';
        }

        if (metadata_exists('post', $id, $meta)) {
            return $this->normalize($key, get_post_meta($id, $meta, true));
        }

        return $this->legacyPost($id, $key);
    }

    public function term(int $id, string $key)
    {
        $meta = self::KEYS[$key] ?? null;

        if (! $meta || ! $id) {
            return '';
        }

        if (metadata_exists('term', $id, $meta)) {
            return $this->normalize($key, get_term_meta($id, $meta, true));
        }

        return $this->legacyTerm($id, $key);
    }

    public function legacyPost(int $id, string $key)
    {
        if (isset(self::YOAST_POST[$key])) {
            $value = get_post_meta($id, self::YOAST_POST[$key], true);

            if ($value !== '' && $value !== false && $value !== null) {
                return $this->fromYoast($key, $value);
            }
        }

        if (isset(self::RANK_MATH[$key])) {
            $value = get_post_meta($id, self::RANK_MATH[$key], true);

            if ($value !== '' && $value !== false && $value !== null) {
                return $this->fromRankMath($key, $value);
            }
        }

        return $this->normalize($key, '');
    }

    public function legacyTerm(int $id, string $key)
    {
        $term = get_term($id);

        if (isset(self::YOAST_TERM[$key]) && $term && ! is_wp_error($term)) {
            $value = $this->yoastTermData()[$term->taxonomy][$id][self::YOAST_TERM[$key]] ?? '';

            if ($value !== '' && $value !== null) {
                return $this->fromYoast($key, $value);
            }
        }

        if (isset(self::RANK_MATH[$key])) {
            $value = get_term_meta($id, self::RANK_MATH[$key], true);

            if ($value !== '' && $value !== false && $value !== null) {
                return $this->fromRankMath($key, $value);
            }
        }

        return $this->normalize($key, '');
    }

    public function hasLegacy(): array
    {
        global $wpdb;

        return [
            'yoast' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN ('_yoast_wpseo_title','_yoast_wpseo_metadesc','_yoast_wpseo_meta-robots-noindex') AND meta_value <> ''"),
            'rankmath' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN ('rank_math_title','rank_math_description','rank_math_robots') AND meta_value <> ''"),
            'yoast_terms' => array_sum(array_map('count', $this->yoastTermData())),
        ];
    }

    private function yoastTermData(): array
    {
        if ($this->yoastTerms === null) {
            $data = get_option('wpseo_taxonomy_meta', []);
            $this->yoastTerms = is_array($data) ? $data : [];
        }

        return $this->yoastTerms;
    }

    private function fromYoast(string $key, $value)
    {
        return match ($key) {
            'robots' => match ((string) $value) {
                '1', 'noindex' => 'noindex',
                '2', 'index' => 'index',
                default => '',
            },
            'nofollow', 'key_page' => (string) $value === '1',
            'social_image' => (int) $value,
            default => trim((string) $value),
        };
    }

    private function fromRankMath(string $key, $value)
    {
        $robots = is_array($value) ? $value : [];

        return match ($key) {
            'robots' => in_array('noindex', $robots, true) ? 'noindex' : '',
            'nofollow' => in_array('nofollow', $robots, true),
            'key_page' => $value === 'on',
            'social_image' => (int) $value,
            'focuskw' => trim(explode(',', (string) $value)[0]),
            default => trim((string) $value),
        };
    }

    private function normalize(string $key, $value)
    {
        return match ($key) {
            'nofollow', 'key_page' => (bool) $value,
            'social_image' => (int) $value,
            'robots' => in_array($value, ['noindex', 'index'], true) ? $value : '',
            'sitemap' => $value === 'exclude' ? 'exclude' : '',
            default => trim((string) $value),
        };
    }
}

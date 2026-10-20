<?php

namespace Snapshot\Seo;

use WP_Post;

class Text
{
    private static array $html = [];

    public static function html(WP_Post $post): string
    {
        if (isset(self::$html[$post->ID])) {
            return self::$html[$post->ID];
        }

        $parts = [(string) $post->post_content];
        $sections = [];
        $fields = [];
        $skip = snap_seo_summary_skip_fields();

        foreach (get_post_meta($post->ID) as $key => $values) {
            $value = $values[0] ?? '';

            if (! is_string($value) || $key[0] === '_' || str_starts_with($key, 'seo_') || self::skipped($key, $skip) || ! self::isProse($value)) {
                continue;
            }

            if (preg_match('/^sections_(\d+)_/', $key, $match)) {
                $sections[(int) $match[1]][] = [$key, $value];
            } else {
                $fields[$key] = $value;
            }
        }

        ksort($sections);

        foreach ($sections as $rows) {
            usort($rows, fn ($a, $b) => self::rank($a[0]) <=> self::rank($b[0]));

            foreach ($rows as [$key, $value]) {
                $parts[] = str_ends_with($key, '_heading') ? '<h2>'.esc_html($value).'</h2>' : $value;
            }
        }

        ksort($fields);

        return self::$html[$post->ID] = implode("\n\n", [...$parts, ...array_values($fields)]);
    }

    public static function forPost(WP_Post $post): string
    {
        $text = wp_strip_all_tags(strip_shortcodes(self::html($post)));

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    public static function forget(int $postId): void
    {
        unset(self::$html[$postId]);
    }

    private static function skipped(string $key, array $skip): bool
    {
        foreach ($skip as $field) {
            if ($key === $field || preg_match('/^'.preg_quote($field, '/').'_\d+_/', $key)) {
                return true;
            }
        }

        return false;
    }

    private static function isProse(string $value): bool
    {
        $value = trim($value);

        return mb_strlen($value) >= 20
            && ! is_serialized($value)
            && ! preg_match('#^(https?://|mailto:|tel:|field_)\S*$#i', $value)
            && ! preg_match('/^\d{4}-?\d{2}-?\d{2}/', $value)
            && preg_match('/\p{L}{2,}\s+\p{L}{2,}/u', wp_strip_all_tags($value));
    }

    private static function rank(string $key): int
    {
        foreach (['_eyebrow' => 0, '_heading' => 1, '_subheading' => 2, '_content' => 3] as $suffix => $rank) {
            if (str_ends_with($key, $suffix)) {
                return $rank;
            }
        }

        return 4;
    }
}

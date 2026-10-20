<?php

namespace Snapshot\Mcp\Tools;

use Snapshot\Mcp\Fields;
use WP_Error;
use WP_Term;

class Terms
{
    public function __construct(private Fields $fields) {}

    public static function taxonomies(): array
    {
        return array_values(snap_filter('snap/mcp/taxonomies', array_diff(get_taxonomies(['show_ui' => true]), ['nav_menu', 'link_category', 'wp_pattern_category'])));
    }

    public static function resolve(string $taxonomy, array $terms)
    {
        $ids = [];

        foreach ($terms as $term) {
            $found = is_numeric($term) ? get_term((int) $term, $taxonomy) : (get_term_by('slug', sanitize_title((string) $term), $taxonomy) ?: get_term_by('name', (string) $term, $taxonomy));

            if (! $found instanceof WP_Term) {
                return new WP_Error('snapshot_mcp_term', sprintf('No term "%s" in %s. Create it with save-term first.', $term, $taxonomy));
            }

            $ids[] = $found->term_id;
        }

        return $ids;
    }

    public static function summary(WP_Term $term): array
    {
        return [
            'id' => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'taxonomy' => $term->taxonomy,
            'parent' => $term->parent,
            'count' => $term->count,
            'description' => $term->description,
            'link' => is_taxonomy_viewable($term->taxonomy) ? (string) get_term_link($term) : '',
        ];
    }

    public function list(array $input)
    {
        $taxonomy = (string) $input['taxonomy'];

        if (! in_array($taxonomy, self::taxonomies(), true)) {
            return $this->unknown($taxonomy);
        }

        $args = [
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'search' => (string) ($input['search'] ?? ''),
            'number' => max(1, min(200, (int) ($input['per_page'] ?? 100))),
            'offset' => max(0, ((int) ($input['page'] ?? 1) - 1)) * max(1, min(200, (int) ($input['per_page'] ?? 100))),
        ];

        if (isset($input['parent'])) {
            $args['parent'] = (int) $input['parent'];
        }

        $terms = get_terms($args);

        if (is_wp_error($terms)) {
            return $terms;
        }

        return [
            'taxonomy' => $taxonomy,
            'items' => array_map(fn ($term) => self::summary($term) + ['fields' => $this->fields->read('term_'.$term->term_id, $this->fields->forTerm($taxonomy))], $terms),
            'total' => (int) wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]),
        ];
    }

    public function save(array $input)
    {
        $taxonomy = (string) $input['taxonomy'];

        if (! in_array($taxonomy, self::taxonomies(), true)) {
            return $this->unknown($taxonomy);
        }

        $object = get_taxonomy($taxonomy);
        $id = (int) ($input['id'] ?? 0);
        $args = array_filter([
            'slug' => isset($input['slug']) ? sanitize_title((string) $input['slug']) : null,
            'description' => isset($input['description']) ? wp_kses_post((string) $input['description']) : null,
            'parent' => isset($input['parent']) ? (int) $input['parent'] : null,
        ], fn ($value) => $value !== null);

        if ($id) {
            if (! current_user_can('edit_term', $id)) {
                return new WP_Error('snapshot_mcp_denied', 'This account cannot edit that term.');
            }

            if (isset($input['name'])) {
                $args['name'] = wp_kses_data((string) $input['name']);
            }

            $result = Content::stored(fn () => wp_update_term($id, $taxonomy, wp_slash($args)));
        } else {
            if (! current_user_can($object->cap->edit_terms) || empty($input['name'])) {
                return new WP_Error('snapshot_mcp_denied', empty($input['name']) ? 'A new term needs a name.' : 'This account cannot add terms here.');
            }

            $result = Content::stored(fn () => wp_insert_term(wp_slash(wp_kses_data((string) $input['name'])), $taxonomy, wp_slash($args)));
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $termId = (int) $result['term_id'];

        if (! empty($input['fields'])) {
            $written = $this->fields->write('term_'.$termId, (array) $input['fields'], $this->fields->forTerm($taxonomy));

            if (is_wp_error($written)) {
                return $written;
            }
        }

        return ($id ? ['updated' => true] : ['created' => true]) + self::summary(get_term($termId, $taxonomy));
    }

    private function unknown(string $taxonomy): WP_Error
    {
        return new WP_Error('snapshot_mcp_taxonomy', sprintf('Unknown taxonomy "%s". Known taxonomies: %s.', $taxonomy, implode(', ', self::taxonomies())));
    }
}

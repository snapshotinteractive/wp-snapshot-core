<?php

namespace Snapshot\Seo;

use WP_Post;

class Variables
{
    private const TOKEN = '/%%([a-z0-9_]+(?::[a-z0-9_\-]+)?)%%|%([a-z][a-z0-9_]*)(?:\(([^)%]*)\))?%/i';

    private array $summaries = [];

    public function __construct(private Settings $settings, private Identity $identity, private Meta $meta) {}

    public function replace(string $template, Context $context): string
    {
        $template = preg_replace_callback('/\[([^\[\]]*)\]/', function ($group) use ($context) {
            if (! preg_match(self::TOKEN, $group[1])) {
                return $group[0];
            }

            $empty = false;
            $resolved = preg_replace_callback(self::TOKEN, function ($match) use ($context, &$empty) {
                $value = $this->token($match, $context);

                if ($value === null || trim($value) === '') {
                    $empty = true;
                }

                return (string) $value;
            }, $group[1]);

            return $empty ? '' : $resolved;
        }, $template);

        $output = preg_replace_callback(self::TOKEN, fn ($match) => (string) ($this->token($match, $context) ?? $match[0]), (string) $template);

        return $this->tidy((string) $output);
    }

    public function value(string $name, Context $context, string $argument = ''): ?string
    {
        $name = strtolower($name);
        $name = snap_seo_variable_aliases()[$name] ?? $name;
        $post = $context->post;
        $id = $post?->ID ?? 0;

        if (str_starts_with($name, 'field:')) {
            return $this->field(substr($name, 6), $context);
        }

        if (str_starts_with($name, 'cf_')) {
            return $this->field(substr($name, 3), $context);
        }

        if (str_starts_with($name, 'ct_desc_')) {
            return $id ? $this->terms($id, substr($name, 8), true) : '';
        }

        if (str_starts_with($name, 'ct_')) {
            return $id ? $this->terms($id, substr($name, 3)) : '';
        }

        $custom = apply_filters('snap/seo/variable', null, $name, $context, $argument);

        if ($custom !== null) {
            return (string) $custom;
        }

        return match ($name) {
            'sitename' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'sitedesc' => wp_specialchars_decode(get_bloginfo('description'), ENT_QUOTES),
            'sep' => $this->settings->string('separator') ?: '-',
            'org_name' => $this->identity->name(),
            'org_description' => $this->identity->description(),
            'title' => $this->title($context),
            'parent_title' => $post && $post->post_parent ? snap_plain_title($post->post_parent) : '',
            'excerpt' => $post ? $this->summary($post) : ($context->term ? $this->plain(term_description($context->term)) : ''),
            'excerpt_only' => $post ? $this->plain($post->post_excerpt) : '',
            'pt_single' => $this->typeLabel($context, 'singular'),
            'pt_plural' => $this->typeLabel($context, 'plural'),
            'date' => $post ? (string) get_the_date($argument ?: '', $post) : wp_date($argument ?: get_option('date_format')),
            'modified' => $post ? (string) get_the_modified_date($argument ?: '', $post) : '',
            'id' => $id ? (string) $id : '',
            'url' => $post ? (string) get_permalink($post) : ($context->term ? (string) get_term_link($context->term) : home_url('/')),
            'primary_term' => $id ? $this->primaryTerm($post) : ($context->term ? $context->term->name : ''),
            'focuskw' => (string) ($context->overrides['focuskw'] ?? ($id ? $this->meta->post($id, 'focuskw') : '')),
            'term_title' => $context->term ? wp_specialchars_decode($context->term->name, ENT_QUOTES) : '',
            'term_description' => $context->term ? $this->plain(term_description($context->term)) : '',
            'archive_title' => $this->archiveTitle($context),
            'archive_intro' => $this->plain((string) snap_archive_setting($context->type, 'intro', '')),
            'searchphrase' => $context->search,
            'page' => $context->paged > 1 ? sprintf(__('Page %1$d of %2$d', 'snapshot-core'), $context->paged, max($context->paged, $context->pages)) : '',
            'pagenumber' => (string) $context->paged,
            'pagetotal' => (string) max(1, $context->pages),
            'currentyear' => wp_date('Y'),
            'currentmonth' => wp_date('F'),
            'currentday' => wp_date('j'),
            'customfield' => $this->field($argument, $context),
            'customterm' => $id ? $this->terms($id, $argument) : '',
            'customterm_desc' => $id ? $this->terms($id, $argument, true) : '',
            'location_address', 'location_city' => $this->location($name, $context),
            'event_when' => $post && $post->post_type === 'event' ? self::eventWhen($post->ID) : '',
            'name' => $post ? (string) get_the_author_meta('display_name', (int) $post->post_author) : '',
            default => null,
        };
    }

    public function summary(WP_Post $post, int $length = 300): string
    {
        $key = $post->ID.':'.$length;

        if (isset($this->summaries[$key])) {
            return $this->summaries[$key];
        }

        $text = $this->plain($post->post_excerpt);

        if ($text === '') {
            $text = $this->trim(Text::forPost($post), $length);
        }

        return $this->summaries[$key] = $text;
    }

    public function trim(string $text, int $length): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length + 1);
        $sentence = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, '? '), (int) mb_strrpos($cut, '! '));

        if ($sentence > $length * 0.6) {
            return mb_substr($cut, 0, $sentence + 1);
        }

        $space = mb_strrpos($cut, ' ');

        return rtrim(mb_substr($cut, 0, $space ?: $length), ' ,;:.-').'…';
    }

    public function catalog(): array
    {
        $groups = [];

        foreach (snap_seo_variables() as $name => $variable) {
            $groups[$variable['group']][$name] = $variable;
        }

        return $groups;
    }

    private function token(array $match, Context $context): ?string
    {
        $name = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
        $argument = $match[3] ?? '';

        if ($match[1] === '' && $name === 'customfield' && $argument === '') {
            return null;
        }

        return $this->value($name, $context, $argument);
    }

    private function title(Context $context): string
    {
        if ($context->post) {
            return snap_plain_title($context->post);
        }

        if ($context->term) {
            return wp_specialchars_decode($context->term->name, ENT_QUOTES);
        }

        return $this->archiveTitle($context);
    }

    private function archiveTitle(Context $context): string
    {
        return match ($context->kind) {
            'archive' => $context->post ? snap_plain_title($context->post) : $this->typeLabel($context, 'plural'),
            'term' => $context->term ? wp_specialchars_decode($context->term->name, ENT_QUOTES) : '',
            'date', 'author' => $this->plain(get_the_archive_title()),
            'search' => sprintf(__('Search results for %s', 'snapshot-core'), $context->search),
            default => '',
        };
    }

    private function typeLabel(Context $context, string $form): string
    {
        $type = $context->kind === 'term' && $context->term
            ? (string) (get_taxonomy($context->term->taxonomy)->object_type[0] ?? '')
            : $context->type;

        if ($form === 'plural' && snap_post_type_config($type)) {
            return snap_archive_label($type);
        }

        return (string) (snap_seo_post_types()[$type][$form] ?? '');
    }

    private function primaryTerm(WP_Post $post): string
    {
        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            $terms = get_the_terms($post, $taxonomy);

            if (is_array($terms) && $terms) {
                return wp_specialchars_decode($terms[0]->name, ENT_QUOTES);
            }
        }

        return '';
    }

    private function terms(int $id, string $taxonomy, bool $description = false): string
    {
        $terms = get_the_terms($id, sanitize_key($taxonomy));

        if (! is_array($terms) || ! $terms) {
            return '';
        }

        return $description
            ? $this->plain($terms[0]->description)
            : implode(', ', array_map(fn ($term) => wp_specialchars_decode($term->name, ENT_QUOTES), array_slice($terms, 0, 3)));
    }

    public function field(string $name, Context $context): string
    {
        $name = sanitize_key($name);
        $target = $context->post?->ID ?? ($context->term ? $context->term : null);

        if ($name === '' || $target === null) {
            return '';
        }

        $object = function_exists('get_field_object') ? get_field_object($name, $target, false, true) : null;
        $raw = is_array($object) ? ($object['value'] ?? null) : (is_int($target) ? get_post_meta($target, $name, true) : get_term_meta($target->term_id, $name, true));
        $type = is_array($object) ? ($object['type'] ?? '') : '';

        if ($raw === null || $raw === '' || $raw === false || $raw === []) {
            return '';
        }

        return $this->plain(match ($type) {
            'date_picker', 'date_time_picker' => $this->formatDate((string) $raw),
            'time_picker' => ($ts = strtotime('1970-01-01 '.$raw.' UTC')) ? gmdate(get_option('time_format'), $ts) : '',
            'post_object', 'relationship', 'page_link' => implode(', ', array_map(fn ($related) => snap_plain_title((int) $related), array_slice(array_filter((array) $raw, 'is_numeric'), 0, 3))),
            'taxonomy' => implode(', ', array_filter(array_map(fn ($termId) => get_term((int) $termId)?->name ?? '', (array) $raw))),
            'true_false' => $raw ? __('Yes', 'snapshot-core') : __('No', 'snapshot-core'),
            'select', 'radio', 'button_group', 'checkbox' => implode(', ', array_map(fn ($choice) => (string) ($object['choices'][$choice] ?? $choice), (array) $raw)),
            'image', 'gallery', 'file', 'repeater', 'flexible_content', 'group' => '',
            'number' => is_numeric($raw) ? number_format_i18n((float) $raw) : (string) $raw,
            default => is_scalar($raw) ? (string) $raw : '',
        });
    }

    private function location(string $name, Context $context): string
    {
        $post = $context->post;

        if (! $post) {
            return '';
        }

        $locationId = $post->post_type === 'location' ? $post->ID : (int) get_post_meta($post->ID, 'location', true);

        if (! $locationId || get_post_type($locationId) !== 'location') {
            return '';
        }

        $field = fn (string $key) => trim((string) get_post_meta($locationId, $key, true));
        $city = implode(', ', array_filter([$field('city'), trim($field('region').($name === 'location_address' ? ' '.$field('postal_code') : ''))]));

        return $name === 'location_city'
            ? $city
            : implode(', ', array_filter([$field('street_address'), $field('address_line_2'), $city]));
    }

    public static function eventStart(int $postId): ?\DateTimeImmutable
    {
        $date = (string) get_post_meta($postId, 'event_date', true);
        $time = (string) get_post_meta($postId, 'event_time', true);
        $day = null;

        foreach (['!Ymd', '!Y-m-d', '!F j, Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, trim($date), wp_timezone());

            if ($parsed && $parsed->format(ltrim($format, '!')) === trim($date)) {
                $day = $parsed;

                break;
            }
        }

        if (! $day) {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?\s*([ap]\.?m\.?)?/i', trim($time), $parts)) {
            $hour = (int) $parts[1] % (isset($parts[3]) ? 12 : 24);
            $hour += isset($parts[3]) && stripos($parts[3], 'p') === 0 ? 12 : 0;
            $day = $day->setTime($hour, (int) $parts[2]);
        }

        return $day;
    }

    public static function eventWhen(int $postId): string
    {
        $start = self::eventStart($postId);

        if (! $start) {
            return '';
        }

        $hasTime = (string) get_post_meta($postId, 'event_time', true) !== '';

        return wp_date((string) get_option('date_format').($hasTime ? ', '.get_option('time_format') : ''), $start->getTimestamp());
    }

    private function formatDate(string $raw): string
    {
        $moment = \DateTimeImmutable::createFromFormat('!Ymd', $raw, wp_timezone())
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, wp_timezone())
            ?: \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, wp_timezone());

        return $moment ? wp_date((string) get_option('date_format'), $moment->getTimestamp()) : '';
    }

    public function plain($value): string
    {
        $text = wp_strip_all_tags(strip_shortcodes((string) $value));

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function tidy(string $text): string
    {
        $sep = preg_quote($this->settings->string('separator') ?: '-', '/');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = preg_replace("/(\s{$sep})+(\s{$sep})/u", '$2', $text);
        $text = preg_replace("/^\s*{$sep}\s+|\s+{$sep}\s*$/u", '', $text);
        $text = preg_replace('/\s+([,.;:!?])/u', '$1', $text);
        $text = preg_replace('/([,.;:])(?:\s*[,.;:])+/u', '$1', $text);
        $text = preg_replace('/([!?…])\./u', '$1', $text);
        $text = preg_replace('/^[\s,.;:]+/u', '', $text);

        return trim((string) $text);
    }
}

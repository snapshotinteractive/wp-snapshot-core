<?php

namespace Snapshot\Seo;

use WP_Post;

class Analysis
{
    public function __construct(private Engine $engine) {}

    public function run(WP_Post $post, array $resolved, string $keyphrase): array
    {
        $html = Text::html($post);
        $text = Text::forPost($post);
        $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text));
        $checks = [];
        $add = function (string $status, string $text, string $topic = '') use (&$checks) {
            $checks[] = ['status' => $status, 'text' => $text, 'topic' => $topic];
        };

        if (! $resolved['robots']['index']) {
            $add('bad', sprintf(__('Hidden from search results (%s). Nothing else below matters until this changes.', 'snapshot-core'), $resolved['robots']['reason']), 'noindex');
        }

        $titleLength = mb_strlen($resolved['title']);

        if ($titleLength > 65) {
            $add('warn', sprintf(__('The title is %d characters. Google usually shows about 60, so the end may be cut off.', 'snapshot-core'), $titleLength), 'title_template');
        } elseif ($titleLength < 30) {
            $add('warn', sprintf(__('The title is only %d characters. A few more descriptive words help it stand out.', 'snapshot-core'), $titleLength), 'title_template');
        } else {
            $add('good', sprintf(__('The title is %d characters, a good length.', 'snapshot-core'), $titleLength), 'title_template');
        }

        $descriptionLength = mb_strlen($resolved['description']);

        if ($descriptionLength === 0) {
            $add('bad', __('There is no description. Write one, add an excerpt, or add text to the entry so the template has something to use.', 'snapshot-core'), 'check_descriptions');
        } elseif ($descriptionLength < 70) {
            $add('warn', sprintf(__('The description is %d characters. Aim for 120 to 155 so it fills the space in search results.', 'snapshot-core'), $descriptionLength), 'description_template');
        } elseif ($descriptionLength > 160) {
            $add('warn', sprintf(__('The description is %d characters. Google cuts it at about 155.', 'snapshot-core'), $descriptionLength), 'description_template');
        } else {
            $add('good', sprintf(__('The description is %d characters, a good length.', 'snapshot-core'), $descriptionLength), 'description_template');
        }

        $duplicates = $this->duplicateTitles($post, $resolved['title']);

        if ($duplicates) {
            $add('warn', sprintf(__('Another entry has the same title: %s.', 'snapshot-core'), implode(', ', $duplicates)), 'check_duplicates');
        }

        if ($keyphrase !== '') {
            $needle = mb_strtolower($keyphrase);
            $in = fn (string $haystack) => str_contains(mb_strtolower(html_entity_decode($haystack)), $needle);
            $first = mb_substr($text, 0, 400);
            $slug = str_replace('-', ' ', $post->post_name);

            $in($resolved['title'])
                ? $add('good', __('The keyphrase is in the title.', 'snapshot-core'))
                : $add('warn', __('Put the keyphrase in the title, ideally near the start.', 'snapshot-core'));

            $in($resolved['description'])
                ? $add('good', __('The keyphrase is in the description.', 'snapshot-core'))
                : $add('warn', __('Use the keyphrase in the description. Google highlights the words that were searched.', 'snapshot-core'));

            $in($slug) || $in($post->post_name)
                ? $add('good', __('The keyphrase is in the address.', 'snapshot-core'))
                : $add('info', __('The keyphrase is not in the address. Only change a published address if the page is new, a redirect is added automatically.', 'snapshot-core'));

            $in($first)
                ? $add('good', __('The keyphrase appears in the opening text.', 'snapshot-core'))
                : $add('warn', __('Use the keyphrase in the first paragraph so readers and search engines see the topic straight away.', 'snapshot-core'));

            preg_match_all('#<h[2-4][^>]*>(.*?)</h[2-4]>#is', $html, $headings);
            $headingHit = array_filter($headings[1] ?? [], fn ($heading) => $in(wp_strip_all_tags($heading)));

            if (($headings[1] ?? []) !== []) {
                $headingHit
                    ? $add('good', __('A subheading uses the keyphrase.', 'snapshot-core'))
                    : $add('info', __('No subheading uses the keyphrase. Use it where it reads naturally.', 'snapshot-core'));
            }

            $count = $words > 0 ? substr_count(mb_strtolower($text), $needle) : 0;
            $density = $words > 0 ? $count * max(1, count(explode(' ', $needle))) / $words * 100 : 0;

            if ($density > 3) {
                $add('warn', sprintf(__('The keyphrase makes up %.1f%% of the text. That reads as repetitive, use synonyms.', 'snapshot-core'), $density));
            }

            if ($other = $this->keyphraseElsewhere($post, $keyphrase)) {
                $add('warn', sprintf(__('%s already targets this keyphrase. Two pages competing for one phrase usually both rank lower.', 'snapshot-core'), $other));
            }
        } else {
            $add('info', __('No focus keyphrase set. Optional, but it turns on checks for the title, description, address and text.', 'snapshot-core'));
        }

        $people = in_array($resolved['schema_type'], ['Person', 'Organization'], true);

        if (! $people && in_array($post->post_type, ['page', 'post', 'survey', 'emerging_tech', 'aim_resource'], true)) {
            $words < 300
                ? $add('warn', sprintf(__('The text is %d words. Pages under 300 words rarely rank for anything competitive.', 'snapshot-core'), $words))
                : $add('good', sprintf(__('The text is %d words.', 'snapshot-core'), $words));
        }

        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        preg_match_all('#<a\s[^>]*href=["\']([^"\']+)["\']#i', $html, $links);
        $internal = array_filter($links[1] ?? [], fn ($href) => str_starts_with($href, '/') || wp_parse_url($href, PHP_URL_HOST) === $host);

        if (! $people && $words >= 150) {
            $internal
                ? $add('good', sprintf(_n('%d link to another page on the site.', '%d links to other pages on the site.', count($internal), 'snapshot-core'), count($internal)))
                : $add('warn', __('No links to other pages on the site. Link to related services, locations or articles.', 'snapshot-core'));
        }

        preg_match_all('#<img\s[^>]*>#i', $html, $images);
        $noAlt = array_filter($images[0] ?? [], fn ($tag) => ! preg_match('#\salt=["\'][^"\']+["\']#i', $tag));

        if ($noAlt) {
            $add('warn', sprintf(_n('%d image in the text has no alt text.', '%d images in the text have no alt text.', count($noAlt), 'snapshot-core'), count($noAlt)), 'check_alt');
        }

        if (preg_match('#<h1[\s>]#i', (string) $post->post_content)) {
            $add('warn', __('The text contains a Heading 1. The page title is already the H1, so use Heading 2 for sections.', 'snapshot-core'));
        }

        if (empty($resolved['social']['image'])) {
            $add('warn', __('No share image, featured image or default share image. Shared links will have no picture.', 'snapshot-core'), 'og_image');
        } elseif ($resolved['social']['image']['width'] && $resolved['social']['image']['width'] < 1200) {
            $add('info', sprintf(__('The share image is %d pixels wide. 1200 looks sharp on every network.', 'snapshot-core'), $resolved['social']['image']['width']), 'og_image');
        }

        $order = ['bad' => 0, 'warn' => 1, 'info' => 2, 'good' => 3];
        usort($checks, fn ($a, $b) => $order[$a['status']] <=> $order[$b['status']]);

        return ['checks' => $checks, 'words' => $words];
    }

    private function duplicateTitles(WP_Post $post, string $title): array
    {
        $report = $this->engine->audit()->report();

        if (! $report || ! in_array('duplicate_title', $report['entries'][$post->ID] ?? [], true)) {
            return [];
        }

        $resolver = $this->engine->resolver();
        $matches = [];

        foreach ($report['entries'] as $id => $flags) {
            if ($id !== $post->ID && in_array('duplicate_title', $flags, true) && ($other = get_post($id))) {
                if (mb_strtolower($resolver->resolve(Context::forPost($other))['title']) === mb_strtolower($title)) {
                    $matches[] = snap_plain_title($other);
                }
            }

            if (count($matches) >= 3) {
                break;
            }
        }

        return $matches;
    }

    private function keyphraseElsewhere(WP_Post $post, string $keyphrase): string
    {
        $found = get_posts([
            'post_type' => snap_seo_editor_types(),
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'post__not_in' => [$post->ID],
            'no_found_rows' => true,
            'meta_query' => [['key' => Meta::KEYS['focuskw'], 'value' => $keyphrase]],
        ]);

        return $found ? snap_plain_title($found[0]) : '';
    }
}

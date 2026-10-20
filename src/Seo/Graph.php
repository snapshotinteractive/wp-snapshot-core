<?php

namespace Snapshot\Seo;

use WP_Post;

class Graph
{
    private const PAGE_TYPES = ['WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'FAQPage', 'SearchResultsPage'];

    private const DAYS = ['monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday', 'thursday' => 'Thursday', 'friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday'];

    public function __construct(private Engine $engine) {}

    public function boot(): void
    {
        add_action('wp_head', [$this, 'print'], 3);
    }

    public function print(): void
    {
        if (is_404()) {
            return;
        }

        $graph = $this->build($this->engine->head()->context());

        if ($graph === []) {
            return;
        }

        echo '<script type="application/ld+json">'.wp_json_encode(
            ['@context' => 'https://schema.org', '@graph' => $graph],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        ).'</script>'."\n";
    }

    public function build(Context $context): array
    {
        $resolved = $this->engine->resolver()->resolve($context);
        $type = $resolved['schema_type'];

        if ($type === 'none') {
            return [];
        }

        $home = home_url('/');
        $url = $resolved['canonical'] ?: ($context->post ? (string) get_permalink($context->post) : home_url(add_query_arg([])));
        $pageId = $url.'#webpage';
        $nodes = [$this->organization(), $this->website()];
        $image = $resolved['social']['image'];
        $entity = null;

        if ($image) {
            $nodes[] = $this->image($image, $url.'#primaryimage');
        }

        $trail = $this->trail($context);

        if (count($trail) > 1) {
            $nodes[] = [
                '@type' => 'BreadcrumbList',
                '@id' => $url.'#breadcrumb',
                'itemListElement' => array_map(fn ($crumb, $index) => array_filter([
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $crumb['name'],
                    'item' => $index < count($trail) - 1 ? $crumb['url'] : null,
                ]), $trail, array_keys($trail)),
            ];
        }

        $post = $context->kind === 'post' ? $context->post : null;

        if ($post) {
            $entity = match ($type) {
                'LocalBusiness' => $this->localBusiness($post, $pageId, $image ? $url.'#primaryimage' : null),
                'Person' => $this->person($post, $pageId),
                'Service' => $this->service($post, $pageId, $image ? $url.'#primaryimage' : null),
                'Event' => $this->event($post, $pageId, $image ? $url.'#primaryimage' : null),
                'Organization' => $this->entityOrganization($post, $pageId),
                'Article', 'NewsArticle' => $this->article($post, $type, $pageId, $resolved, $image ? $url.'#primaryimage' : null),
                default => null,
            };
        }

        $pageType = match (true) {
            in_array($type, self::PAGE_TYPES, true) => $type,
            $type === 'Person' && $entity => 'ProfilePage',
            $type === 'Question' && $post => 'FAQPage',
            default => 'WebPage',
        };
        $page = array_filter([
            '@type' => $pageType,
            '@id' => $pageId,
            'url' => $url,
            'name' => $resolved['title'],
            'description' => $resolved['description'] ?: null,
            'isPartOf' => ['@id' => $home.'#website'],
            'about' => $context->kind === 'front' ? ['@id' => $home.'#organization'] : null,
            'primaryImageOfPage' => $image ? ['@id' => $url.'#primaryimage'] : null,
            'breadcrumb' => count($trail) > 1 ? ['@id' => $url.'#breadcrumb'] : null,
            'datePublished' => $context->post ? get_post_time('c', true, $context->post) : null,
            'dateModified' => $context->post ? get_post_modified_time('c', true, $context->post) : null,
            'inLanguage' => get_bloginfo('language'),
            'mainEntity' => $entity ? ['@id' => $entity[0]['@id']] : null,
        ]);

        if ($type === 'FAQPage' && $context->post) {
            $page['mainEntity'] = $this->questions($context->post);

            if ($page['mainEntity'] === []) {
                $page['@type'] = 'WebPage';
                unset($page['mainEntity']);
            }
        }

        if ($type === 'Question' && $post) {
            $question = $this->question($post);

            if ($question) {
                $page['mainEntity'] = [$question];
            } else {
                $page['@type'] = 'WebPage';
            }
        }

        $nodes[] = $page;

        foreach ($entity ?? [] as $node) {
            $nodes[] = $node;
        }

        return array_values(apply_filters('snap/seo/graph', $nodes, $context, $this->engine));
    }

    public function trail(Context $context): array
    {
        $trail = [['name' => __('Home', 'snapshot-core'), 'url' => home_url('/')]];
        $meta = $this->engine->meta();

        if ($context->kind === 'front') {
            return $trail;
        }

        if ($context->post && $context->kind === 'post') {
            $post = $context->post;

            if ($archive = $this->archiveCrumb($post->post_type)) {
                $trail[] = $archive;
            }

            foreach (array_reverse(get_post_ancestors($post)) as $ancestor) {
                $trail[] = ['name' => (string) $meta->post($ancestor, 'breadcrumb_title') ?: snap_plain_title($ancestor), 'url' => (string) get_permalink($ancestor)];
            }

            $trail[] = ['name' => (string) $meta->post($post->ID, 'breadcrumb_title') ?: snap_plain_title($post), 'url' => (string) get_permalink($post)];

            return $trail;
        }

        if ($context->kind === 'term' && $context->term) {
            $object = get_taxonomy($context->term->taxonomy)->object_type[0] ?? '';

            if ($object && ($archive = $this->archiveCrumb($object))) {
                $trail[] = $archive;
            }

            foreach (array_reverse(get_ancestors($context->term->term_id, $context->term->taxonomy, 'taxonomy')) as $ancestor) {
                $term = get_term($ancestor);

                if ($term && ! is_wp_error($term)) {
                    $trail[] = ['name' => wp_specialchars_decode($term->name, ENT_QUOTES), 'url' => (string) get_term_link($term)];
                }
            }

            $trail[] = ['name' => (string) $meta->term($context->term->term_id, 'breadcrumb_title') ?: wp_specialchars_decode($context->term->name, ENT_QUOTES), 'url' => (string) get_term_link($context->term)];

            return $trail;
        }

        if ($context->kind === 'archive' && ($archive = $this->archiveCrumb($context->type))) {
            $trail[] = $archive;
        }

        return $trail;
    }

    public function organization(): array
    {
        $identity = $this->engine->identity();
        $home = home_url('/');
        $logo = $identity->logo();
        $address = $identity->address();

        return array_filter([
            '@type' => $this->engine->settings()->string('org_type') ?: 'Organization',
            '@id' => $home.'#organization',
            'name' => $identity->name(),
            'alternateName' => $identity->alternate() ?: null,
            'url' => $home,
            'description' => $identity->description() ?: null,
            'logo' => $logo['url'] ? array_filter([
                '@type' => 'ImageObject',
                '@id' => $home.'#logo',
                'url' => $logo['url'],
                'contentUrl' => $logo['url'],
                'width' => $logo['width'] ?: null,
                'height' => $logo['height'] ?: null,
                'caption' => $identity->name(),
            ]) : null,
            'image' => $logo['url'] ? ['@id' => $home.'#logo'] : null,
            'email' => $identity->email() ?: null,
            'telephone' => $identity->phone() ?: null,
            'address' => $address !== '' ? ['@type' => 'PostalAddress', 'streetAddress' => $address] : null,
            'foundingDate' => preg_match('/^\d{4}$/', $this->engine->settings()->string('org_founded')) ? $this->engine->settings()->string('org_founded') : null,
            'sameAs' => $identity->sameAs() ?: null,
        ]);
    }

    public function website(): array
    {
        $home = home_url('/');

        return array_filter([
            '@type' => 'WebSite',
            '@id' => $home.'#website',
            'url' => $home,
            'name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'alternateName' => $this->engine->identity()->alternate() ?: null,
            'description' => wp_specialchars_decode(get_bloginfo('description'), ENT_QUOTES) ?: null,
            'publisher' => ['@id' => $home.'#organization'],
            'inLanguage' => get_bloginfo('language'),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => home_url('/?s={search_term_string}')],
                'query-input' => 'required name=search_term_string',
            ],
        ]);
    }

    private function image(array $image, string $id): array
    {
        return array_filter([
            '@type' => 'ImageObject',
            '@id' => $id,
            'url' => $image['url'],
            'contentUrl' => $image['url'],
            'width' => $image['width'] ?: null,
            'height' => $image['height'] ?: null,
            'caption' => $image['alt'] ?: null,
        ]);
    }

    private function archiveCrumb(string $type): ?array
    {
        if ($type === 'post') {
            $blog = (int) get_option('page_for_posts');

            return $blog ? ['name' => snap_plain_title($blog), 'url' => (string) get_permalink($blog)] : null;
        }

        $object = get_post_type_object($type);

        if (! $object || ! $object->has_archive) {
            return null;
        }

        return ['name' => snap_archive_label($type), 'url' => (string) get_post_type_archive_link($type)];
    }

    private function localBusiness(WP_Post $post, string $pageId, ?string $imageId): array
    {
        $home = home_url('/');
        $field = fn (string $name) => trim(wp_strip_all_tags((string) get_post_meta($post->ID, $name, true)));
        $latitude = $field('latitude');
        $longitude = $field('longitude');
        $orgType = $this->engine->settings()->string('org_type');

        return [array_filter([
            '@type' => in_array($orgType, ['LocalBusiness', 'ProfessionalService'], true) ? $orgType : 'LocalBusiness',
            '@id' => get_permalink($post).'#place',
            'name' => snap_plain_title($post),
            'url' => get_permalink($post),
            'description' => $this->engine->variables()->summary($post) ?: null,
            'image' => $imageId ? ['@id' => $imageId] : null,
            'address' => $this->postalAddress($post->ID),
            'geo' => is_numeric($latitude) && is_numeric($longitude) ? ['@type' => 'GeoCoordinates', 'latitude' => (float) $latitude, 'longitude' => (float) $longitude] : null,
            'telephone' => $field('phone_display') ?: null,
            'email' => sanitize_email($field('email')) ?: null,
            'hasMap' => esc_url_raw($field('map_url')) ?: null,
            'openingHoursSpecification' => $this->openingHours($post->ID) ?: null,
            'parentOrganization' => ['@id' => $home.'#organization'],
            'mainEntityOfPage' => ['@id' => $pageId],
        ])];
    }

    private function postalAddress(int $postId): ?array
    {
        $field = fn (string $name) => trim(wp_strip_all_tags((string) get_post_meta($postId, $name, true)));
        $street = trim(implode(', ', array_filter([$field('street_address'), $field('address_line_2')])));
        $address = array_filter([
            'streetAddress' => $street ?: null,
            'addressLocality' => $field('city') ?: null,
            'addressRegion' => $field('region') ?: null,
            'postalCode' => $field('postal_code') ?: null,
            'addressCountry' => $field('country') ?: null,
        ]);

        return $address ? ['@type' => 'PostalAddress'] + $address : null;
    }

    private function openingHours(int $postId): array
    {
        $rows = (int) get_post_meta($postId, 'hours', true);
        $specs = [];

        for ($row = 0; $row < $rows; $row++) {
            $days = $this->parseDays((string) get_post_meta($postId, "hours_{$row}_days", true));
            $time = strtolower(trim((string) get_post_meta($postId, "hours_{$row}_time", true)));

            if (! $days) {
                continue;
            }

            if (str_contains($time, 'closed')) {
                $specs[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $days, 'opens' => '00:00', 'closes' => '00:00'];

                continue;
            }

            if (preg_match_all('/(\d{1,2})(?::(\d{2}))?\s*(am|pm|a\.m\.|p\.m\.)?/', $time, $times, PREG_SET_ORDER) < 2) {
                continue;
            }

            $opens = $this->clock($times[0]);
            $closes = $this->clock($times[1], $times[0]);

            if ($opens !== null && $closes !== null) {
                $specs[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $days, 'opens' => $opens, 'closes' => $closes];
            }
        }

        return $specs;
    }

    private function parseDays(string $text): array
    {
        $text = strtolower(trim($text));
        $keys = array_keys(self::DAYS);
        $find = function (string $word) use ($keys): ?int {
            foreach ($keys as $index => $day) {
                if (strlen($word) >= 3 && str_starts_with($day, $word)) {
                    return $index;
                }
            }

            return null;
        };

        if (in_array($text, ['daily', 'every day', 'everyday', 'all week', '7 days'], true)) {
            return array_values(self::DAYS);
        }

        if (in_array($text, ['weekdays', 'week days'], true)) {
            return array_slice(array_values(self::DAYS), 0, 5);
        }

        if (in_array($text, ['weekends', 'weekend'], true)) {
            return array_slice(array_values(self::DAYS), 5, 2);
        }

        $days = [];

        foreach (preg_split('/\s*(?:,|&|\band\b)\s*/', $text) as $part) {
            $range = preg_split('/\s*(?:-|–|—|\bto\b|\bthrough\b|\bthru\b)\s*/u', trim($part));
            $words = array_map(fn ($word) => preg_replace('/s$/', '', preg_replace('/[^a-z]/', '', $word)), $range);
            $start = $find($words[0] ?? '');
            $end = count($words) > 1 ? $find($words[count($words) - 1]) : $start;

            if ($start === null || $end === null) {
                return [];
            }

            for ($index = $start; ; $index = ($index + 1) % 7) {
                $days[] = self::DAYS[$keys[$index]];

                if ($index === $end) {
                    break;
                }
            }
        }

        return array_values(array_unique($days));
    }

    private function clock(array $match, ?array $opens = null): ?string
    {
        $hour = (int) $match[1];
        $minute = (int) ($match[2] ?? 0);
        $meridiem = str_replace('.', '', $match[3] ?? '');

        if ($meridiem === '' && $opens !== null && $hour < 12 && $hour <= (int) $opens[1]) {
            $meridiem = 'pm';
        }

        if ($meridiem === 'pm' && $hour < 12) {
            $hour += 12;
        } elseif ($meridiem === 'am' && $hour === 12) {
            $hour = 0;
        }

        $valid = $minute < 60 && ($hour < 24 || ($hour === 24 && $minute === 0));

        return $valid ? sprintf('%02d:%02d', $hour, $minute) : null;
    }

    private function person(WP_Post $post, ?string $pageId): array
    {
        $field = fn (string $name) => trim(wp_strip_all_tags((string) get_post_meta($post->ID, $name, true)));
        $home = home_url('/');
        $image = get_post_thumbnail_id($post);
        $location = (int) get_post_meta($post->ID, 'location', true);
        $sameAs = [];
        $links = (int) get_post_meta($post->ID, 'social_links', true);

        for ($row = 0; $row < $links; $row++) {
            if ($url = esc_url_raw(trim((string) get_post_meta($post->ID, "social_links_{$row}_url", true)))) {
                $sameAs[] = $url;
            }
        }

        return [array_filter([
            '@type' => 'Person',
            '@id' => get_permalink($post).'#person',
            'name' => snap_plain_title($post),
            'honorificSuffix' => $field('credentials') ?: null,
            'url' => get_permalink($post),
            'jobTitle' => $field('job_title') ?: null,
            'worksFor' => ['@id' => $home.'#organization'],
            'workLocation' => $location && get_post_status($location) === 'publish' && $this->engine->resolver()->resolve(Context::forPost(get_post($location)))['schema_type'] === 'LocalBusiness' ? ['@id' => get_permalink($location).'#place'] : null,
            'email' => sanitize_email($field('email')) ?: null,
            'description' => $pageId ? ($this->engine->variables()->summary($post) ?: null) : null,
            'image' => $image ? wp_get_attachment_image_url($image, 'large') : null,
            'sameAs' => array_values(array_unique($sameAs)) ?: null,
            'mainEntityOfPage' => $pageId ? ['@id' => $pageId] : null,
        ])];
    }

    private function event(WP_Post $post, string $pageId, ?string $imageId): array
    {
        $start = Variables::eventStart($post->ID);
        $identity = $this->engine->identity();
        $address = $identity->address();

        if (! $start || $address === '') {
            return [];
        }

        $home = home_url('/');
        $hasTime = (string) get_post_meta($post->ID, 'event_time', true) !== '';

        return [array_filter([
            '@type' => 'Event',
            '@id' => get_permalink($post).'#event',
            'name' => snap_plain_title($post),
            'description' => $this->engine->variables()->summary($post) ?: null,
            'url' => get_permalink($post),
            'startDate' => $hasTime ? $start->format('c') : $start->format('Y-m-d'),
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'location' => ['@type' => 'Place', 'name' => $identity->name(), 'address' => ['@type' => 'PostalAddress', 'streetAddress' => $address]],
            'image' => $imageId ? ['@id' => $imageId] : null,
            'organizer' => ['@id' => $home.'#organization'],
            'mainEntityOfPage' => ['@id' => $pageId],
        ])];
    }

    private function service(WP_Post $post, string $pageId, ?string $imageId): array
    {
        $home = home_url('/');
        $terms = get_the_terms($post, 'service_category');

        return [array_filter([
            '@type' => 'Service',
            '@id' => get_permalink($post).'#service',
            'name' => snap_plain_title($post),
            'url' => get_permalink($post),
            'description' => $this->engine->variables()->summary($post) ?: null,
            'image' => $imageId ? ['@id' => $imageId] : null,
            'serviceType' => is_array($terms) && $terms ? wp_specialchars_decode($terms[0]->name, ENT_QUOTES) : null,
            'provider' => ['@id' => $home.'#organization'],
            'mainEntityOfPage' => ['@id' => $pageId],
        ])];
    }

    private function entityOrganization(WP_Post $post, string $pageId): array
    {
        $logo = get_post_thumbnail_id($post);

        return [array_filter([
            '@type' => 'Organization',
            '@id' => get_permalink($post).'#organization',
            'name' => snap_plain_title($post),
            'url' => get_permalink($post),
            'logo' => $logo ? wp_get_attachment_image_url($logo, 'medium') : null,
            'description' => $this->engine->variables()->summary($post) ?: null,
            'mainEntityOfPage' => ['@id' => $pageId],
        ])];
    }

    private function question(WP_Post $post): ?array
    {
        $answer = trim(wp_kses(wpautop(do_blocks($post->post_content)), ['a' => ['href' => true], 'p' => [], 'br' => [], 'ul' => [], 'ol' => [], 'li' => [], 'strong' => [], 'em' => []]));

        return $answer !== '' ? ['@type' => 'Question', 'name' => snap_plain_title($post), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer]] : null;
    }

    private function article(WP_Post $post, string $type, string $pageId, array $resolved, ?string $imageId): array
    {
        $home = home_url('/');
        $terms = get_the_terms($post, 'category');

        return [array_filter([
            '@type' => $type,
            '@id' => get_permalink($post).'#article',
            'headline' => mb_substr(snap_plain_title($post), 0, 110),
            'description' => $resolved['description'] ?: null,
            'url' => get_permalink($post),
            'image' => $imageId ? ['@id' => $imageId] : null,
            'datePublished' => get_post_time('c', true, $post),
            'dateModified' => get_post_modified_time('c', true, $post),
            'author' => ['@id' => $home.'#organization'],
            'publisher' => ['@id' => $home.'#organization'],
            'isPartOf' => ['@id' => $pageId],
            'mainEntityOfPage' => ['@id' => $pageId],
            'articleSection' => is_array($terms) && $terms ? wp_specialchars_decode($terms[0]->name, ENT_QUOTES) : null,
            'wordCount' => str_word_count(Text::forPost($post)) ?: null,
            'inLanguage' => get_bloginfo('language'),
        ])];
    }

    public function questions(WP_Post $post): array
    {
        $layouts = get_post_meta($post->ID, 'sections', true);
        $questions = [];

        foreach (is_array($layouts) ? $layouts : [] as $index => $layout) {
            if (! in_array($layout, ['accordion', 'accordion_image'], true)) {
                continue;
            }

            $count = (int) get_post_meta($post->ID, "sections_{$index}_items", true);

            for ($item = 0; $item < $count; $item++) {
                $question = trim(wp_strip_all_tags((string) get_post_meta($post->ID, "sections_{$index}_items_{$item}_title", true)));
                $answer = trim(wp_kses((string) get_post_meta($post->ID, "sections_{$index}_items_{$item}_content", true), ['a' => ['href' => true], 'p' => [], 'br' => [], 'ul' => [], 'ol' => [], 'li' => [], 'strong' => [], 'em' => []]));

                if ($question !== '' && $answer !== '') {
                    $questions[] = ['@type' => 'Question', 'name' => $question, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer]];
                }
            }
        }

        return $questions;
    }
}

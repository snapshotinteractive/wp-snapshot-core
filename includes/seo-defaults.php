<?php

function snap_seo_core_types(): array
{
    return snap_filter('snap/seo/core_types', [
        'page' => ['singular' => 'Page', 'plural' => 'Pages', 'has_archive' => false],
        'post' => ['singular' => 'News article', 'plural' => 'News', 'has_archive' => true],
    ]);
}

function snap_seo_post_types(): array
{
    $types = snap_seo_core_types();

    foreach (snap_post_types() as $type => $config) {
        if (! empty($config['public'])) {
            $types[$type] = [
                'singular' => $config['singular'] ?? $type,
                'plural' => $config['plural'] ?? $type,
                'has_archive' => ! empty($config['has_archive']),
            ];
        }
    }

    return snap_filter('snap/seo/post_types', $types);
}

function snap_seo_taxonomies(): array
{
    $taxonomies = [
        'category' => ['singular' => 'News category', 'plural' => 'News categories'],
        'post_tag' => ['singular' => 'News tag', 'plural' => 'News tags'],
    ];

    foreach (snap_taxonomies() as $taxonomy => $config) {
        $taxonomies[$taxonomy] = ['singular' => $config['singular'], 'plural' => $config['plural']];
    }

    return snap_filter('snap/seo/taxonomies', $taxonomies);
}

function snap_seo_schema_types(): array
{
    return snap_filter('snap/seo/schema_types', [
        'WebPage' => 'Web page',
        'AboutPage' => 'About page',
        'ContactPage' => 'Contact page',
        'CollectionPage' => 'Collection page',
        'FAQPage' => 'FAQ page, from accordion sections',
        'Question' => 'Single question and answer',
        'Article' => 'Article',
        'NewsArticle' => 'News article',
        'LocalBusiness' => 'Location, from the address fields',
        'Event' => 'Event, from the date and time fields',
        'Service' => 'Service',
        'Person' => 'Person profile',
        'Organization' => 'Organization profile',
        'none' => 'None, no structured data',
    ]);
}

function snap_seo_type_presets(): array
{
    $site = '%%title%% %%sep%% %%sitename%%';

    return snap_filter('snap/seo/type_presets', [
        '_default' => ['title' => $site, 'description' => '%%excerpt%%', 'schema' => 'WebPage', 'archive_title' => '%%archive_title%%[ %%sep%% %%page%%] %%sep%% %%sitename%%', 'archive_description' => '%%archive_intro%%'],
        'page' => ['schema' => 'WebPage'],
        'post' => ['schema' => 'NewsArticle', 'archive_title' => '%%pt_plural%%[ %%sep%% %%page%%] %%sep%% %%sitename%%'],
        'location' => [
            'title' => '%%title%%[, %%field:city%%] %%sep%% %%sitename%%',
            'description' => '[%%location_address%%. ]%%excerpt%%',
            'schema' => 'LocalBusiness',
        ],
        'staff' => [
            'title' => '%%title%%[, %%field:job_title%%] %%sep%% %%sitename%%',
            'description' => '%%title%%[, %%field:job_title%%] at %%sitename%%. %%excerpt%%',
            'schema' => 'Person',
        ],
        'event' => [
            'title' => '%%title%%[, %%event_when%%] %%sep%% %%sitename%%',
            'description' => '[%%event_when%%. ]%%excerpt%%',
            'schema' => 'Event',
        ],
        'service' => ['schema' => 'Service'],
        'faq' => ['schema' => 'Question'],
    ]);
}

function snap_seo_type_preset(string $type, string $key): string
{
    $presets = snap_seo_type_presets();

    return (string) ($presets[$type][$key] ?? $presets['_default'][$key] ?? '');
}

function snap_seo_separators(): array
{
    return snap_filter('snap/seo/separators', [
        '-' => 'Hyphen: -',
        '|' => 'Pipe: |',
        '·' => 'Middle dot: ·',
        '•' => 'Bullet: •',
        '/' => 'Slash: /',
        '›' => 'Angle: ›',
        '~' => 'Tilde: ~',
    ]);
}

function snap_seo_canonical_params(): array
{
    return snap_filter('snap/seo/canonical_params', []);
}

function snap_seo_summary_skip_fields(): array
{
    return snap_filter('snap/seo/summary_skip_fields', [
        'street_address', 'address_line_2', 'city', 'region', 'postal_code', 'country',
        'phone_display', 'phone_link', 'email', 'latitude', 'longitude', 'map_url',
        'hours', 'hours_note', 'job_title', 'credentials', 'social_links',
        'author_name', 'author_role', 'author_company', 'source_url', 'event_date', 'event_time',
    ]);
}

function snap_seo_ai_crawlers(): array
{
    return snap_filter('snap/seo/ai_crawlers', [
        'GPTBot' => ['owner' => 'OpenAI', 'purpose' => 'training'],
        'OAI-SearchBot' => ['owner' => 'OpenAI', 'purpose' => 'search'],
        'ChatGPT-User' => ['owner' => 'OpenAI', 'purpose' => 'user'],
        'ClaudeBot' => ['owner' => 'Anthropic', 'purpose' => 'training'],
        'Claude-SearchBot' => ['owner' => 'Anthropic', 'purpose' => 'search'],
        'Claude-User' => ['owner' => 'Anthropic', 'purpose' => 'user'],
        'Google-Extended' => ['owner' => 'Google', 'purpose' => 'training'],
        'Applebot-Extended' => ['owner' => 'Apple', 'purpose' => 'training'],
        'PerplexityBot' => ['owner' => 'Perplexity', 'purpose' => 'search'],
        'Perplexity-User' => ['owner' => 'Perplexity', 'purpose' => 'user'],
        'CCBot' => ['owner' => 'Common Crawl', 'purpose' => 'training'],
        'Meta-ExternalAgent' => ['owner' => 'Meta', 'purpose' => 'training'],
        'Bytespider' => ['owner' => 'ByteDance', 'purpose' => 'training'],
        'Amazonbot' => ['owner' => 'Amazon', 'purpose' => 'search'],
        'cohere-ai' => ['owner' => 'Cohere', 'purpose' => 'training'],
    ]);
}

function snap_seo_monitor_ignore_defaults(): string
{
    return implode("\n", ['/wp-content/', '/wp-includes/', '/wp-admin/', '/favicon.ico', '/apple-touch-icon', '.map', '/xmlrpc.php', '/.well-known/']);
}

function snap_seo_settings_tabs(): array
{
    return snap_filter('snap/seo/settings_tabs', [
        'identity' => 'Site identity',
        'social' => 'Social sharing',
        'indexing' => 'Indexing rules',
        'crawlers' => 'Crawlers and AI',
        'redirects' => 'Redirects and 404s',
        'verification' => 'Webmaster verification',
        'cleanup' => 'Crawl cleanup',
        'advanced' => 'Advanced',
    ]);
}

function snap_seo_settings_schema(): array
{
    $schema = [
        'seo_enabled' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => 'Built-in SEO', 'help' => 'Master switch. Off stops every SEO module at once.', 'topic' => 'master'],
        'module_head' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => 'Titles and meta', 'help' => 'Title tags, meta descriptions, robots and canonical tags.'],
        'module_social' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => 'Social sharing', 'help' => 'Open Graph and X card tags.'],
        'module_schema' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => 'Structured data', 'help' => 'JSON-LD graph built from the content fields.'],
        'module_sitemaps' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => 'XML sitemaps', 'help' => 'Tuned WordPress sitemaps.'],
        'module_redirects' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => 'Redirects', 'help' => 'Redirect manager and automatic redirects.'],
        'module_monitor' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => '404 monitor', 'help' => 'Records addresses that returned page not found.'],
        'module_robots' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => 'robots.txt and AI crawlers', 'help' => 'Virtual robots.txt with AI crawler rules.'],
        'module_llms' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => 'llms.txt', 'help' => 'A guide to the site for AI assistants.'],
        'module_cleanup' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => 'Crawl cleanup', 'help' => 'Removes clutter WordPress adds to every page.'],
        'module_indexnow' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => 'IndexNow', 'help' => 'Notifies Bing and other engines when content changes.'],
        'import' => [
            'title' => 'Import data',
            'what' => 'Copies titles, descriptions, noindex, canonicals, share settings and redirects saved by Yoast SEO, Rank Math and the Redirection plugin into this module.',
            'why' => 'Until it runs, those values are read live from the other plugins\' data, so pages look right either way. Importing makes them permanent, so the old plugins and their database tables can be removed without losing anything.',
            'do' => 'Tick what was found and press Import data, then deactivate the old plugins. It is safe to run again: values already set here are kept unless Replace is ticked.',
        ],
        'module_editor' => ['tab' => 'modules', 'type' => 'bool', 'default' => true, 'label' => 'Editor panel', 'help' => 'Search and sharing panel on every entry and term.'],

        'org_name' => ['tab' => 'identity', 'type' => 'text', 'default' => '', 'label' => 'Organization name', 'help' => 'The full legal or public name. Leave blank to use the site title from Settings, General.', 'topic' => 'org_name', 'inherit' => 'blogname'],
        'org_alternate' => ['tab' => 'identity', 'type' => 'text', 'default' => '', 'label' => 'Short name or acronym', 'help' => 'Optional. The name people also search for, for example the brand people know you by. Leave blank if there is none.', 'topic' => 'org_name'],
        'org_type' => ['tab' => 'identity', 'type' => 'select', 'default' => 'Organization', 'choices' => ['Organization' => 'Organization', 'LocalBusiness' => 'Local business', 'ProfessionalService' => 'Professional service', 'Corporation' => 'Company', 'NGO' => 'Non-profit organization', 'EducationalOrganization' => 'Educational organization'], 'label' => 'Organization type', 'help' => 'The kind of organization described to search engines. Local business suits a company with storefronts or offices people visit.', 'topic' => 'org_type'],
        'org_logo' => ['tab' => 'identity', 'type' => 'image', 'default' => 0, 'label' => 'Logo for search results', 'help' => 'Square or wide logo, at least 112 by 112 pixels, PNG or JPG. Leave blank to use the header logo from Site Options.', 'topic' => 'org_logo', 'inherit' => 'theme_logo'],
        'org_description' => ['tab' => 'identity', 'type' => 'textarea', 'default' => '', 'label' => 'Organization description', 'help' => 'One or two sentences about the organization. Leave blank to use the footer blurb from Site Options.', 'topic' => 'org_description', 'inherit' => 'footer_text'],
        'org_email' => ['tab' => 'identity', 'type' => 'text', 'default' => '', 'label' => 'Public email', 'help' => 'Leave blank to use the email from Site Options, General.', 'topic' => 'org_contact', 'inherit' => 'email'],
        'org_phone' => ['tab' => 'identity', 'type' => 'text', 'default' => '', 'label' => 'Public phone', 'help' => 'Leave blank to use the phone number from Site Options, General.', 'topic' => 'org_contact', 'inherit' => 'phone_display'],
        'org_address' => ['tab' => 'identity', 'type' => 'textarea', 'default' => '', 'label' => 'Postal address', 'help' => 'Leave blank to use the address from Site Options, General.', 'topic' => 'org_contact', 'inherit' => 'address'],
        'org_founded' => ['tab' => 'identity', 'type' => 'text', 'default' => '', 'label' => 'Founding year', 'help' => 'Optional. Four digits, for example 1984.', 'topic' => 'org_name'],
        'org_same_as' => ['tab' => 'identity', 'type' => 'lines', 'default' => '', 'label' => 'Other official profiles', 'help' => 'One URL per line, for example a Wikipedia or Crunchbase page. The social profiles in Site Options are always included, so do not repeat them here.', 'topic' => 'org_same_as'],
        'separator' => ['tab' => 'identity', 'type' => 'select', 'default' => '-', 'choices' => snap_seo_separators(), 'label' => 'Title separator', 'help' => 'The character the %%sep%% variable prints between parts of a title.', 'topic' => 'separator'],

        'og_default_image' => ['tab' => 'social', 'type' => 'image', 'default' => 0, 'label' => 'Default share image', 'help' => '1200 by 630 pixels. Used when an entry has no share image and no featured image. Leave blank to use the share image from Site Options.', 'topic' => 'og_image', 'inherit' => 'og_image'],
        'x_handle' => ['tab' => 'social', 'type' => 'text', 'default' => '', 'label' => 'X username', 'help' => 'Optional, with or without the @. Leave blank to read it from the X profile in Site Options.', 'topic' => 'social_cards', 'inherit' => 'x_twitter'],
        'facebook_app_id' => ['tab' => 'social', 'type' => 'text', 'default' => '', 'label' => 'Facebook app ID', 'help' => 'Optional. Only needed for Facebook Insights on shared links.', 'topic' => 'social_cards'],

        'noindex_search' => ['tab' => 'indexing', 'type' => 'bool', 'default' => true, 'label' => 'Keep search results out of search engines', 'help' => 'Adds noindex to the site search page.', 'topic' => 'noindex_search'],
        'noindex_empty_terms' => ['tab' => 'indexing', 'type' => 'bool', 'default' => true, 'label' => 'Keep empty categories and tags out', 'help' => 'Adds noindex to term archives with no published entries.', 'topic' => 'noindex_empty_terms'],
        'noindex_date_archives' => ['tab' => 'indexing', 'type' => 'bool', 'default' => true, 'label' => 'Keep date archives out', 'help' => 'Adds noindex to year, month and day archives, which repeat the news archive.', 'topic' => 'thin_archives'],
        'max_image_preview' => ['tab' => 'indexing', 'type' => 'bool', 'default' => true, 'label' => 'Allow large image previews', 'help' => 'Adds max-image-preview:large so Google can show large images in Discover and results.', 'topic' => 'max_image_preview'],
        'attachment_redirect' => ['tab' => 'indexing', 'type' => 'bool', 'default' => true, 'label' => 'Redirect attachment pages', 'help' => 'Sends media attachment pages to the file itself.', 'topic' => 'attachment_redirect'],
        'canonical_params' => ['tab' => 'indexing', 'type' => 'lines', 'default' => implode("\n", snap_seo_canonical_params()), 'label' => 'Query parameters kept in canonical URLs', 'help' => 'One per line. Every other query parameter, such as utm_source, is dropped from canonical URLs. Add one only when it changes what the page shows, for example a filter with its own landing page.', 'topic' => 'canonical'],
        'sitemap_legacy_urls' => ['tab' => 'sitemap', 'type' => 'bool', 'default' => true, 'label' => 'Answer old sitemap addresses', 'help' => 'Redirects /sitemap.xml and /sitemap_index.xml, the addresses Yoast and Rank Math used, to the WordPress sitemap.', 'topic' => 'sitemap_legacy'],
        'sitemap_max_urls' => ['tab' => 'sitemap', 'type' => 'int', 'default' => 2000, 'min' => 100, 'max' => 50000, 'label' => 'Addresses per sitemap page', 'help' => 'Lower numbers make each sitemap page faster to build on large sites. Search engines accept up to 50,000.', 'topic' => 'sitemap_max_urls'],
        'sitemap_lastmod' => ['tab' => 'sitemap', 'type' => 'select', 'default' => 'modified', 'choices' => ['modified' => 'Last updated date', 'published' => 'Published date', 'none' => 'Leave it out'], 'label' => 'Last modified date', 'help' => 'The date search engines use to decide what to crawl again. Last updated is right for almost every site.', 'topic' => 'sitemap_lastmod'],
        'sitemap_exclude_rules' => ['tab' => 'sitemap', 'type' => 'lines', 'default' => '', 'label' => 'Leave out these addresses', 'help' => 'One per line. A path such as /thank-you/ leaves out that address. End with * to leave out everything under it, for example /landing/*. The pages stay live and searchable; they are only left out of the sitemap.', 'topic' => 'sitemap_rules'],
        'sitemap_extra_urls' => ['tab' => 'sitemap', 'type' => 'lines', 'default' => '', 'label' => 'Add these addresses', 'help' => 'One per line, on this site only: a path such as /tools/calculator/ or a full address. Add a date after a space to set its last modified date, for example /pricing/ 2026-10-01. For pages WordPress does not know about, such as a static app or a file.', 'topic' => 'sitemap_extra'],

        'ai_policy' => ['tab' => 'crawlers', 'type' => 'select', 'default' => 'allow', 'choices' => ['allow' => 'Allow every AI crawler', 'search' => 'Allow AI search and assistants, block model training', 'block' => 'Block every AI crawler'], 'label' => 'AI crawler policy', 'help' => 'Written to robots.txt as rules per crawler. Search and assistant crawlers are the ones that cite and link to the site in AI answers.', 'topic' => 'ai_policy'],
        'robots_extra' => ['tab' => 'crawlers', 'type' => 'textarea', 'default' => '', 'label' => 'Extra robots.txt rules', 'help' => 'Optional. Lines without their own User-agent line apply to every crawler. The sitemap line is added automatically.', 'topic' => 'robots_txt'],
        'llms_intro' => ['tab' => 'crawlers', 'type' => 'textarea', 'default' => '', 'label' => 'llms.txt introduction', 'help' => 'Optional. A short paragraph telling AI assistants what the site offers. Leave blank to use the organization description.', 'topic' => 'llms', 'inherit' => 'org_description'],
        'llms_entries' => ['tab' => 'crawlers', 'type' => 'int', 'default' => 15, 'min' => 0, 'max' => 200, 'label' => 'Entries per content type in llms.txt', 'help' => 'The most recent entries listed under each content type switched on for llms.txt. 0 lists only the archive.', 'topic' => 'llms'],

        'redirect_on_slug_change' => ['tab' => 'redirects', 'type' => 'bool', 'default' => true, 'label' => 'Redirect when an address changes', 'help' => 'Adds a 301 redirect automatically when a published entry, or a parent page, changes its address.', 'topic' => 'auto_redirects'],
        'redirect_trashed' => ['tab' => 'redirects', 'type' => 'select', 'default' => 'archive', 'choices' => ['archive' => 'Redirect to the content type archive, or the home page', '410' => 'Answer 410 Gone', 'none' => 'Do nothing, answer 404'], 'label' => 'When a published entry is deleted', 'help' => 'Restoring the entry removes the automatic rule again.', 'topic' => 'trashed_redirects'],
        'monitor_retention_days' => ['tab' => 'redirects', 'type' => 'int', 'default' => 60, 'min' => 7, 'max' => 365, 'label' => 'Keep 404 records for (days)', 'help' => 'Addresses not seen again within this many days are removed nightly.', 'topic' => 'monitor'],
        'monitor_ignore' => ['tab' => 'redirects', 'type' => 'lines', 'default' => snap_seo_monitor_ignore_defaults(), 'label' => 'Ignore 404s containing', 'help' => 'One fragment per line. Requests containing any of them are never recorded, which keeps scanner noise out of the log.', 'topic' => 'monitor'],

        'verify_google' => ['tab' => 'verification', 'type' => 'text', 'default' => '', 'label' => 'Google Search Console', 'help' => 'The content value of the HTML tag method, not the whole tag. Leave blank if the site is verified by DNS.', 'topic' => 'verification'],
        'verify_bing' => ['tab' => 'verification', 'type' => 'text', 'default' => '', 'label' => 'Bing Webmaster Tools', 'help' => 'The content value of the msvalidate.01 tag. Bing can also import the Google verification.', 'topic' => 'verification'],
        'verify_yandex' => ['tab' => 'verification', 'type' => 'text', 'default' => '', 'label' => 'Yandex Webmaster', 'help' => 'Optional. The content value of the yandex-verification tag.', 'topic' => 'verification'],
        'verify_baidu' => ['tab' => 'verification', 'type' => 'text', 'default' => '', 'label' => 'Baidu', 'help' => 'Optional. The content value of the baidu-site-verification tag.', 'topic' => 'verification'],
        'verify_pinterest' => ['tab' => 'verification', 'type' => 'text', 'default' => '', 'label' => 'Pinterest', 'help' => 'Optional. The content value of the p:domain_verify tag.', 'topic' => 'verification'],
        'verify_facebook' => ['tab' => 'verification', 'type' => 'text', 'default' => '', 'label' => 'Facebook domain', 'help' => 'Optional. The content value of the facebook-domain-verification tag.', 'topic' => 'verification'],

        'cleanup_generator' => ['tab' => 'cleanup', 'type' => 'bool', 'default' => true, 'label' => 'Remove the WordPress version tag', 'help' => 'Drops meta generator, which advertises the WordPress version.', 'topic' => 'cleanup'],
        'cleanup_shortlink' => ['tab' => 'cleanup', 'type' => 'bool', 'default' => true, 'label' => 'Remove shortlinks', 'help' => 'Drops the ?p=123 shortlink tag and header, a second address for every entry.', 'topic' => 'cleanup'],
        'cleanup_rsd' => ['tab' => 'cleanup', 'type' => 'bool', 'default' => true, 'label' => 'Remove RSD and Windows Live Writer links', 'help' => 'Discovery links for desktop blogging tools nobody uses with this site.', 'topic' => 'cleanup'],
        'cleanup_emoji' => ['tab' => 'cleanup', 'type' => 'bool', 'default' => true, 'label' => 'Remove the emoji script', 'help' => 'Every current browser draws emoji itself. Saves a script and a style on every page.', 'topic' => 'cleanup'],
        'cleanup_comment_feeds' => ['tab' => 'cleanup', 'type' => 'bool', 'default' => true, 'label' => 'Remove comment feeds', 'help' => 'Comments are closed, so their feeds are empty pages for crawlers.', 'topic' => 'cleanup'],
        'cleanup_replytocom' => ['tab' => 'cleanup', 'type' => 'bool', 'default' => true, 'label' => 'Redirect ?replytocom links', 'help' => 'Sends comment reply addresses to the entry, so crawlers do not index duplicates.', 'topic' => 'cleanup'],
        'cleanup_spam_search' => ['tab' => 'cleanup', 'type' => 'bool', 'default' => true, 'label' => 'Refuse spam searches', 'help' => 'Answers 404 to site searches made of links or very long strings, a common way of planting spam in search results.', 'topic' => 'cleanup'],

        'defer_to_plugins' => ['tab' => 'advanced', 'type' => 'bool', 'default' => true, 'label' => 'Pause modules that an active SEO plugin also provides', 'help' => 'While Yoast, Rank Math or a similar plugin is active, the overlapping modules stay quiet so a page never carries two titles or two canonicals. Turn off only after deactivating the plugin.', 'topic' => 'defer'],
        'indexnow_key' => ['tab' => 'advanced', 'type' => 'key', 'default' => '', 'label' => 'IndexNow key', 'help' => 'Created automatically the first time IndexNow runs and served at the site root as a text file. Generate a new one if it ever leaks.', 'topic' => 'indexnow'],
        'home_title' => ['tab' => 'appearance', 'type' => 'template', 'default' => '%%sitename%% %%sep%% %%sitedesc%%', 'label' => 'Home page title', 'help' => 'Used for the front page.', 'topic' => 'title_template'],
        'home_description' => ['tab' => 'appearance', 'type' => 'template', 'default' => '%%org_description%%', 'label' => 'Home page description', 'help' => 'Used for the front page.', 'topic' => 'description_template'],
        'search_title' => ['tab' => 'appearance', 'type' => 'template', 'default' => 'Search results for %%searchphrase%% %%sep%% %%sitename%%', 'label' => 'Search results title', 'help' => 'Used for the site search page.', 'topic' => 'title_template'],
        'notfound_title' => ['tab' => 'appearance', 'type' => 'template', 'default' => 'Page not found %%sep%% %%sitename%%', 'label' => 'Page not found title', 'help' => 'Used for the 404 page.', 'topic' => 'title_template'],
    ];

    foreach (snap_seo_post_types() as $type => $config) {
        $schema["pt_{$type}_title"] = ['tab' => 'appearance', 'group' => "pt:{$type}", 'type' => 'template', 'default' => snap_seo_type_preset($type, 'title'), 'label' => 'Title', 'help' => "Title template for every {$config['singular']} without its own title.", 'topic' => 'title_template'];
        $schema["pt_{$type}_description"] = ['tab' => 'appearance', 'group' => "pt:{$type}", 'type' => 'template', 'default' => snap_seo_type_preset($type, 'description'), 'label' => 'Description', 'help' => "Description template for every {$config['singular']} without its own description.", 'topic' => 'description_template'];
        $schema["pt_{$type}_schema"] = ['tab' => 'appearance', 'group' => "pt:{$type}", 'type' => 'select', 'default' => snap_seo_type_preset($type, 'schema'), 'choices' => snap_seo_schema_types(), 'label' => 'Structured data', 'help' => 'The schema.org type each entry is described as.', 'topic' => 'schema_type'];
        $schema["pt_{$type}_index"] = ['tab' => 'appearance', 'group' => "pt:{$type}", 'type' => 'bool', 'default' => true, 'label' => 'Show in search results', 'help' => 'Off adds noindex to every entry of this type and leaves it out of the sitemap.', 'topic' => 'noindex'];
        $schema["pt_{$type}_sitemap"] = ['tab' => 'sitemap', 'group' => "pt:{$type}", 'type' => 'bool', 'default' => true, 'label' => 'Include in the sitemap', 'help' => 'Off leaves every entry of this type out of the sitemap. The entries stay live and searchable.', 'topic' => 'sitemap_sections'];
        $schema["pt_{$type}_llms"] = ['tab' => 'appearance', 'group' => "pt:{$type}", 'type' => 'bool', 'default' => $type !== 'page', 'label' => 'List in llms.txt', 'help' => 'Lists the archive and recent entries in llms.txt.', 'topic' => 'llms'];

        if ($config['has_archive']) {
            $schema["pta_{$type}_title"] = ['tab' => 'appearance', 'group' => "pt:{$type}", 'type' => 'template', 'default' => snap_seo_type_preset($type, 'archive_title'), 'label' => 'Archive title', 'help' => 'Title of the list of all entries.', 'topic' => 'title_template'];
            $schema["pta_{$type}_description"] = ['tab' => 'appearance', 'group' => "pt:{$type}", 'type' => 'template', 'default' => snap_seo_type_preset($type, 'archive_description'), 'label' => 'Archive description', 'help' => 'Description of the list of all entries. %%archive_intro%% is the intro set for the archive in the content type registry.', 'topic' => 'description_template'];
        }
    }

    foreach (snap_seo_taxonomies() as $taxonomy => $config) {
        $schema["tax_{$taxonomy}_title"] = ['tab' => 'appearance', 'group' => "tax:{$taxonomy}", 'type' => 'template', 'default' => '%%term_title%%[ %%sep%% %%page%%] %%sep%% %%sitename%%', 'label' => 'Title', 'help' => "Title template for every {$config['singular']} archive.", 'topic' => 'title_template'];
        $schema["tax_{$taxonomy}_description"] = ['tab' => 'appearance', 'group' => "tax:{$taxonomy}", 'type' => 'template', 'default' => '%%term_description%%', 'label' => 'Description', 'help' => "Description template for every {$config['singular']} archive.", 'topic' => 'description_template'];
        $schema["tax_{$taxonomy}_index"] = ['tab' => 'appearance', 'group' => "tax:{$taxonomy}", 'type' => 'bool', 'default' => ! in_array($taxonomy, ['post_tag'], true), 'label' => 'Show in search results', 'help' => 'Off adds noindex to these archives and leaves them out of the sitemap.', 'topic' => 'noindex'];
        $schema["tax_{$taxonomy}_sitemap"] = ['tab' => 'sitemap', 'group' => "tax:{$taxonomy}", 'type' => 'bool', 'default' => true, 'label' => 'Include in the sitemap', 'help' => 'Off leaves these archives out of the sitemap. They stay live and searchable.', 'topic' => 'sitemap_sections'];
    }

    return snap_filter('snap/seo/settings', $schema);
}

function snap_seo_modules(): array
{
    return snap_filter('snap/seo/modules', [
        'head' => ['setting' => 'module_head', 'label' => 'Titles and meta', 'summary' => 'Title tags, meta descriptions, robots directives, canonical URLs and webmaster verification tags, from templates that fill themselves from the content fields.', 'section' => 'appearance', 'topic' => 'module_head'],
        'social' => ['setting' => 'module_social', 'label' => 'Social sharing', 'summary' => 'Open Graph and X card tags so shared links show the right title, text and image.', 'section' => 'social', 'topic' => 'module_social'],
        'schema' => ['setting' => 'module_schema', 'label' => 'Structured data', 'summary' => 'A linked JSON-LD graph: organization, website, breadcrumbs, and locations, people, services and questions built from their fields.', 'section' => 'identity', 'topic' => 'module_schema'],
        'sitemaps' => ['setting' => 'module_sitemaps', 'label' => 'XML sitemaps', 'summary' => 'WordPress sitemaps you curate: choose the content types and taxonomies, leave out single entries or whole address ranges, add extra addresses, and check the result.', 'section' => 'sitemaps', 'topic' => 'sitemaps'],
        'redirects' => ['setting' => 'module_redirects', 'label' => 'Redirects', 'summary' => 'Redirect manager with 301, 302, 307, 410 and 451 rules, automatic redirects when addresses change, and import from the Redirection plugin.', 'section' => 'redirects', 'topic' => 'module_redirects'],
        'monitor' => ['setting' => 'module_monitor', 'label' => '404 monitor', 'summary' => 'Counts addresses that answered page not found, so broken links can be redirected in one click.', 'section' => 'redirects', 'topic' => 'monitor'],
        'robots' => ['setting' => 'module_robots', 'label' => 'robots.txt and AI crawlers', 'summary' => 'Virtual robots.txt with the sitemap address, per crawler AI rules and extra lines.', 'section' => 'crawlers', 'topic' => 'robots_txt'],
        'llms' => ['setting' => 'module_llms', 'label' => 'llms.txt', 'summary' => 'A Markdown guide to the site at /llms.txt, built from key pages, menus, archives and recent entries.', 'section' => 'crawlers', 'topic' => 'llms'],
        'cleanup' => ['setting' => 'module_cleanup', 'label' => 'Crawl cleanup', 'summary' => 'Removes generator tags, shortlinks, emoji scripts, comment feeds and spam searches that waste crawl budget.', 'section' => 'cleanup', 'topic' => 'cleanup'],
        'indexnow' => ['setting' => 'module_indexnow', 'label' => 'IndexNow', 'summary' => 'Tells Bing, Yandex, Seznam and Naver within minutes when an entry is published, updated or deleted.', 'section' => 'advanced', 'topic' => 'indexnow'],
        'editor' => ['setting' => 'module_editor', 'label' => 'Editor panel', 'summary' => 'Search and sharing panel on every entry and term, with a live search preview, variables and content checks.', 'section' => null, 'topic' => 'module_editor'],
    ]);
}

function snap_seo_overlapping_plugins(): array
{
    $all = ['head', 'social', 'schema', 'sitemaps', 'robots', 'llms', 'editor'];

    return snap_filter('snap/seo/overlapping_plugins', [
        'wordpress-seo/wp-seo.php' => ['label' => 'Yoast SEO', 'modules' => $all],
        'wordpress-seo-premium/wp-seo-premium.php' => ['label' => 'Yoast SEO Premium', 'modules' => [...$all, 'redirects', 'cleanup', 'indexnow']],
        'seo-by-rank-math/rank-math.php' => ['label' => 'Rank Math', 'modules' => [...$all, 'redirects', 'monitor', 'indexnow']],
        'seo-by-rank-math-pro/rank-math-pro.php' => ['label' => 'Rank Math Pro', 'modules' => [...$all, 'redirects', 'monitor', 'indexnow']],
        'all-in-one-seo-pack/all_in_one_seo_pack.php' => ['label' => 'All in One SEO', 'modules' => [...$all, 'indexnow']],
        'wp-seopress/seopress.php' => ['label' => 'SEOPress', 'modules' => [...$all, 'redirects', 'monitor']],
        'autodescription/autodescription.php' => ['label' => 'The SEO Framework', 'modules' => $all],
        'slim-seo/slim-seo.php' => ['label' => 'Slim SEO', 'modules' => [...$all, 'redirects']],
        'squirrly-seo/squirrly.php' => ['label' => 'Squirrly SEO', 'modules' => $all],
        'redirection/redirection.php' => ['label' => 'Redirection', 'modules' => ['redirects', 'monitor']],
        'safe-redirect-manager/safe-redirect-manager.php' => ['label' => 'Safe Redirect Manager', 'modules' => ['redirects']],
        'indexnow/indexnow-url-submission.php' => ['label' => 'IndexNow', 'modules' => ['indexnow']],
        'google-sitemap-generator/sitemap.php' => ['label' => 'XML Sitemap Generator for Google', 'modules' => ['sitemaps']],
        'website-llms-txt/website-llms-txt.php' => ['label' => 'Website LLMs.txt', 'modules' => ['llms']],
    ]);
}

function snap_seo_variables(): array
{
    return snap_filter('snap/seo/variables', [
        'title' => ['group' => 'Entry', 'label' => 'Entry title', 'help' => 'The title of the entry, page or term.'],
        'excerpt' => ['group' => 'Entry', 'label' => 'Summary', 'help' => 'The excerpt if one is written, otherwise the first sentences of the content and fields, about 155 characters.'],
        'excerpt_only' => ['group' => 'Entry', 'label' => 'Written excerpt only', 'help' => 'The excerpt field only. Empty when no excerpt is written.'],
        'parent_title' => ['group' => 'Entry', 'label' => 'Parent page title', 'help' => 'Title of the parent page, for child pages.'],
        'pt_single' => ['group' => 'Entry', 'label' => 'Content type, singular', 'help' => 'For example Location or Staff Member.'],
        'pt_plural' => ['group' => 'Entry', 'label' => 'Content type, plural', 'help' => 'For example Locations or Staff.'],
        'date' => ['group' => 'Entry', 'label' => 'Published date', 'help' => 'In the site date format.'],
        'modified' => ['group' => 'Entry', 'label' => 'Last updated date', 'help' => 'In the site date format.'],
        'primary_term' => ['group' => 'Entry', 'label' => 'Main category', 'help' => 'The first category, type or other term attached to the entry.'],
        'focuskw' => ['group' => 'Entry', 'label' => 'Focus keyphrase', 'help' => 'The keyphrase set in the editor panel.'],
        'id' => ['group' => 'Entry', 'label' => 'Entry ID', 'help' => 'The numeric ID.'],
        'url' => ['group' => 'Entry', 'label' => 'Address', 'help' => 'The canonical address of the current page.'],
        'field:NAME' => ['group' => 'Fields', 'label' => 'Any field', 'help' => 'Any field of the entry by its name, for example %%field:job_title%%. Dates print in the site format, linked entries print their titles and yes or no fields print Yes or No. Yoast %%cf_NAME%% and Rank Math %customfield(NAME)% also work.'],
        'location_address' => ['group' => 'Locations', 'label' => 'Location address', 'help' => 'The address on one line, for example 100 Main Street, Portland, OR 97204. On a staff member, the address of the location they are based at.'],
        'event_when' => ['group' => 'Events', 'label' => 'Event date and time', 'help' => 'For example January 15, 2027, 6:00 pm. Empty when the entry is not an event.'],
        'location_city' => ['group' => 'Locations', 'label' => 'Location city', 'help' => 'City and region, for example Portland, OR. On a staff member, the city of the location they are based at.'],
        'term_title' => ['group' => 'Archives', 'label' => 'Term name', 'help' => 'The category, tag or other term being viewed.'],
        'term_description' => ['group' => 'Archives', 'label' => 'Term description', 'help' => 'The description written for the term.'],
        'archive_title' => ['group' => 'Archives', 'label' => 'Archive title', 'help' => 'The heading of the list being viewed, for example Locations.'],
        'archive_intro' => ['group' => 'Archives', 'label' => 'Archive intro', 'help' => 'The intro sentence set for the content type archive.'],
        'searchphrase' => ['group' => 'Archives', 'label' => 'Search phrase', 'help' => 'What was searched for.'],
        'page' => ['group' => 'Archives', 'label' => 'Page number text', 'help' => 'Page 2 of 5 on later pages of a list. Empty on the first page.'],
        'pagenumber' => ['group' => 'Archives', 'label' => 'Page number', 'help' => 'The current page number.'],
        'pagetotal' => ['group' => 'Archives', 'label' => 'Page count', 'help' => 'The number of pages in the list.'],
        'sitename' => ['group' => 'Site', 'label' => 'Site title', 'help' => 'From Settings, General.'],
        'sitedesc' => ['group' => 'Site', 'label' => 'Tagline', 'help' => 'From Settings, General.'],
        'sep' => ['group' => 'Site', 'label' => 'Separator', 'help' => 'The title separator from Site identity.'],
        'org_name' => ['group' => 'Site', 'label' => 'Organization name', 'help' => 'From Site identity.'],
        'org_description' => ['group' => 'Site', 'label' => 'Organization description', 'help' => 'From Site identity, or the footer blurb.'],
        'currentyear' => ['group' => 'Site', 'label' => 'Current year', 'help' => 'For example 2026.'],
        'currentmonth' => ['group' => 'Site', 'label' => 'Current month', 'help' => 'For example October.'],
    ]);
}

function snap_seo_variable_aliases(): array
{
    return snap_filter('snap/seo/variable_aliases', [
        'search_query' => 'searchphrase',
        'primary_category' => 'primary_term',
        'category' => 'primary_term',
        'term' => 'term_title',
        'tag' => 'term_title',
        'category_description' => 'term_description',
        'tag_description' => 'term_description',
        'focus_keyword' => 'focuskw',
        'keywords' => 'focuskw',
        'currentdate' => 'date',
    ]);
}

function snap_seo_editor_types(): array
{
    return array_keys(snap_seo_post_types());
}

function snap_seo_field_groups(): array
{
    $postLocations = array_map(
        fn (string $type) => [['param' => 'post_type', 'operator' => '==', 'value' => $type]],
        snap_seo_editor_types()
    );
    $schemaChoices = ['' => 'Automatic, from the content type'] + snap_seo_schema_types();
    $robots = ['' => 'Default for this content type', 'noindex' => 'Hide from search results (noindex)', 'index' => 'Always show in search results'];

    return snap_filter('snap/seo/field_groups', [
        [
            'key' => 'group_snapshot_seo',
            'title' => 'Search and sharing',
            'menu_order' => 90,
            'position' => 'normal',
            'style' => 'default',
            'location' => $postLocations,
            'fields' => [
                ['key' => 'field_seo_tab_search', 'label' => 'Search', 'name' => '', 'type' => 'tab', 'placement' => 'top'],
                ['key' => 'field_seo_preview', 'label' => 'Search preview', 'name' => '', 'type' => 'message', 'message' => '<div data-snap-seo-preview data-context="post"></div>', 'esc_html' => 0, 'instructions' => 'How this entry is likely to look in Google. Updates as the fields below change.'],
                ['key' => 'field_seo_title', 'label' => 'Search title', 'name' => 'seo_title', 'type' => 'text', 'instructions' => 'Optional. Aim for 50 to 60 characters. Variables such as %%title%% and %%sep%% fill in automatically. Leave blank to use the title template for this content type, shown as the placeholder.', 'wrapper' => ['class' => 'snap-seo-field snap-seo-title']],
                ['key' => 'field_seo_description', 'label' => 'Search description', 'name' => 'seo_description', 'type' => 'textarea', 'rows' => 3, 'new_lines' => '', 'instructions' => 'Optional. Aim for 120 to 155 characters that make a searcher want to click. Leave blank to use the description template, which writes one from the content and fields.', 'wrapper' => ['class' => 'snap-seo-field snap-seo-description']],
                ['key' => 'field_seo_focus_keyphrase', 'label' => 'Focus keyphrase', 'name' => 'seo_focus_keyphrase', 'type' => 'text', 'instructions' => 'Optional. The two to four word phrase someone would search to find this entry. Used only by the content checks below and never printed on the page.'],
                ['key' => 'field_seo_checks', 'label' => 'Content checks', 'name' => '', 'type' => 'message', 'message' => '<div data-snap-seo-checks></div>', 'esc_html' => 0, 'instructions' => 'Checks run against the last saved version and the fields above.'],

                ['key' => 'field_seo_tab_social', 'label' => 'Sharing', 'name' => '', 'type' => 'tab', 'placement' => 'top'],
                ['key' => 'field_seo_social_preview', 'label' => 'Share preview', 'name' => '', 'type' => 'message', 'message' => '<div data-snap-seo-social></div>', 'esc_html' => 0, 'instructions' => 'How a link to this entry looks on LinkedIn, Facebook and X.'],
                ['key' => 'field_seo_social_title', 'label' => 'Share title', 'name' => 'seo_social_title', 'type' => 'text', 'instructions' => 'Optional. Leave blank to use the search title without the site name.'],
                ['key' => 'field_seo_social_description', 'label' => 'Share description', 'name' => 'seo_social_description', 'type' => 'textarea', 'rows' => 2, 'new_lines' => '', 'instructions' => 'Optional. Leave blank to use the search description.'],
                ['key' => 'field_seo_social_image', 'label' => 'Share image', 'name' => 'seo_social_image', 'type' => 'image', 'return_format' => 'id', 'preview_size' => 'medium', 'instructions' => 'Optional. 1200 by 630 pixels, with any text kept away from the edges. Leave blank to use the featured image, then the default share image.'],

                ['key' => 'field_seo_tab_advanced', 'label' => 'Advanced', 'name' => '', 'type' => 'tab', 'placement' => 'top'],
                ['key' => 'field_seo_robots', 'label' => 'Search visibility', 'name' => 'seo_robots', 'type' => 'select', 'choices' => $robots, 'default_value' => '', 'allow_null' => 0, 'wrapper' => ['width' => '50'], 'instructions' => 'Default follows the content type setting under SEO, Search appearance. Hidden entries also leave the sitemap and llms.txt.'],
                ['key' => 'field_seo_nofollow', 'label' => 'Do not follow links', 'name' => 'seo_nofollow', 'type' => 'true_false', 'ui' => 1, 'default_value' => 0, 'wrapper' => ['width' => '50'], 'instructions' => 'Rarely needed. Asks search engines not to follow any link on this page. Default is off.'],
                ['key' => 'field_seo_canonical', 'label' => 'Canonical URL', 'name' => 'seo_canonical', 'type' => 'url', 'instructions' => 'Optional. Only when this page copies another page, enter the original address. Leave blank to use this page\'s own address.'],
                ['key' => 'field_seo_schema_type', 'label' => 'Structured data type', 'name' => 'seo_schema_type', 'type' => 'select', 'choices' => $schemaChoices, 'default_value' => '', 'allow_null' => 0, 'wrapper' => ['width' => '50'], 'instructions' => 'Automatic follows the content type setting. FAQ page turns accordion sections into questions and answers.'],
                ['key' => 'field_seo_breadcrumb_title', 'label' => 'Breadcrumb title', 'name' => 'seo_breadcrumb_title', 'type' => 'text', 'wrapper' => ['width' => '50'], 'instructions' => 'Optional. A shorter name for this page in breadcrumb trails. Leave blank to use the title.'],
                ['key' => 'field_seo_sitemap', 'label' => 'Sitemap', 'name' => 'seo_sitemap', 'type' => 'select', 'choices' => ['' => 'Default', 'exclude' => 'Leave out of the sitemap'], 'default_value' => '', 'allow_null' => 0, 'wrapper' => ['width' => '50'], 'instructions' => 'Default lists this entry when its content type is in the sitemap and it shows in search results. Leave out keeps the page live and searchable but drops it from the sitemap.'],
                ['key' => 'field_seo_key_page', 'label' => 'Key page', 'name' => 'seo_key_page', 'type' => 'true_false', 'ui' => 1, 'default_value' => 0, 'instructions' => 'Marks one of the most important pages on the site. Key pages are listed first in llms.txt and checked more strictly in the SEO overview. Default is off.'],
            ],
        ],
        [
            'key' => 'group_snapshot_seo_term',
            'title' => 'Search and sharing',
            'menu_order' => 90,
            'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'all']]],
            'fields' => [
                ['key' => 'field_seo_term_preview', 'label' => 'Search preview', 'name' => '', 'type' => 'message', 'message' => '<div data-snap-seo-preview data-context="term"></div>', 'esc_html' => 0, 'instructions' => 'How this archive is likely to look in Google.'],
                ['key' => 'field_seo_term_title', 'label' => 'Search title', 'name' => 'seo_title', 'type' => 'text', 'instructions' => 'Optional. Aim for 50 to 60 characters. Leave blank to use the title template for this taxonomy.', 'wrapper' => ['class' => 'snap-seo-field snap-seo-title']],
                ['key' => 'field_seo_term_description', 'label' => 'Search description', 'name' => 'seo_description', 'type' => 'textarea', 'rows' => 3, 'new_lines' => '', 'instructions' => 'Optional. Aim for 120 to 155 characters. Leave blank to use the term description.', 'wrapper' => ['class' => 'snap-seo-field snap-seo-description']],
                ['key' => 'field_seo_term_robots', 'label' => 'Search visibility', 'name' => 'seo_robots', 'type' => 'select', 'choices' => $robots, 'default_value' => '', 'allow_null' => 0, 'instructions' => 'Default follows the taxonomy setting under SEO, Search appearance.'],
                ['key' => 'field_seo_term_sitemap', 'label' => 'Sitemap', 'name' => 'seo_sitemap', 'type' => 'select', 'choices' => ['' => 'Default', 'exclude' => 'Leave out of the sitemap'], 'default_value' => '', 'allow_null' => 0, 'instructions' => 'Default lists this archive when its taxonomy is in the sitemap. Leave out keeps it live and searchable but drops it from the sitemap.'],
                ['key' => 'field_seo_term_social_image', 'label' => 'Share image', 'name' => 'seo_social_image', 'type' => 'image', 'return_format' => 'id', 'preview_size' => 'medium', 'instructions' => 'Optional. 1200 by 630 pixels. Leave blank to use the default share image.'],
            ],
        ],
    ]);
}

function snap_seo_help(): array
{
    return snap_filter('snap/seo/help', [
        'master' => [
            'title' => 'Built-in SEO',
            'what' => 'Everything search engines, social networks and AI assistants read about the site: titles, descriptions, structured data, sitemaps, redirects and crawler rules.',
            'why' => 'This replaces Yoast SEO and Rank Math. It reads the content model directly, so locations, people and services describe themselves without anyone filling in SEO fields.',
            'do' => 'Leave it on. Turn it off only if another SEO plugin takes over every module, otherwise pages lose their titles, descriptions and sitemaps.',
        ],
        'module_head' => [
            'title' => 'Titles and meta',
            'what' => 'The title shown as the blue link in search results, the description under it, the robots directive and the canonical address.',
            'why' => 'The title is the strongest on page ranking signal and the description decides whether people click. Without templates every page title is just its name, and descriptions are left for Google to guess.',
            'do' => 'Check the templates under Search appearance once. After that every new entry fills in on its own. Write a custom title or description only for important pages.',
            'auto' => 'Each content type has a template built from its own fields, so a location description includes its address, and a staff title includes their job title.',
        ],
        'module_social' => [
            'title' => 'Social sharing',
            'what' => 'Open Graph tags, read by LinkedIn, Facebook, Slack, Teams and iMessage, and X card tags.',
            'why' => 'A link shared without them shows a random image or none, and a title cut from the page. For a business whose links travel on LinkedIn and in messages, the share card is often the first impression.',
            'do' => 'Set a default share image under Social sharing. Featured images are used automatically, and the Sharing tab on each entry overrides both.',
        ],
        'module_schema' => [
            'title' => 'Structured data',
            'what' => 'Machine readable JSON-LD that says what a page is about: this is a location of this business, at this address, with these opening hours, and this person works there.',
            'why' => 'It powers rich results such as business details and FAQs in Google, the knowledge panel for the organization, and how AI assistants understand and cite the site.',
            'do' => 'Fill in Site identity once. Everything else is built from fields editors already complete, so a staff member linked to a location appears as working there.',
        ],
        'sitemaps' => [
            'title' => 'XML sitemaps',
            'what' => 'A list of every public address for search engines, at /wp-sitemap.xml, split into one sitemap per content type and taxonomy.',
            'why' => 'Search engines find new and updated content faster, especially entries that few pages link to. A curated sitemap also tells them which addresses matter.',
            'do' => 'Choose what goes in under SEO, Sitemaps, then submit /wp-sitemap.xml once in Google Search Console and Bing Webmaster Tools. Hidden content, noindexed entries and private types are left out automatically.',
        ],
        'sitemap_sections' => [
            'title' => 'What goes in the sitemap',
            'what' => 'One switch per content type and taxonomy. Off leaves every address of that kind out of the sitemap.',
            'why' => 'A sitemap should list the pages you want found. Thin archives, tag pages or utility types dilute it and waste crawl time.',
            'do' => 'Keep pages, news and the main content types in. Leave out taxonomies whose archives add little, such as tags. To hide a type from search results entirely, use Search appearance instead: hidden types leave the sitemap automatically.',
        ],
        'sitemap_entry' => [
            'title' => 'Curating entries',
            'what' => 'The list of entries in one sitemap, with the reason any entry is missing, and buttons to leave entries out or put them back.',
            'why' => 'Thank you pages, campaign landing pages and near duplicates are often live on purpose but should not be promoted to search engines.',
            'do' => 'Pick a content type, tick the entries to leave out and apply. The same choice is on each entry, under Search and sharing, Advanced, Sitemap.',
        ],
        'sitemap_rules' => [
            'title' => 'Address rules',
            'what' => 'Paths to leave out of the sitemap, exactly or with everything under them when they end in *.',
            'why' => 'Rules cover whole sections at once, including entries added later, without editing each one.',
            'do' => 'Add one path per line, for example /landing/*. Run Check the sitemap afterwards: it reports rules that match nothing, usually a typo.',
        ],
        'sitemap_extra' => [
            'title' => 'Extra addresses',
            'what' => 'Addresses on this site that WordPress does not manage, listed in their own sitemap at /wp-sitemap-extra-1.xml.',
            'why' => 'A static tool, a microsite folder or a file served by the web server is otherwise invisible to the sitemap.',
            'do' => 'One address per line, optionally followed by a date such as 2026-10-01. Addresses on other sites are ignored. Check the sitemap afterwards: it requests each one and reports any that do not answer 200.',
        ],
        'sitemap_lastmod' => [
            'title' => 'Last modified date',
            'what' => 'The lastmod date written next to each address.',
            'why' => 'Google and Bing use it to decide what to crawl again. It only helps while it is honest: a date that changes without the content changing is ignored.',
            'do' => 'Keep Last updated date unless editors routinely resave entries without changing them.',
        ],
        'sitemap_max_urls' => [
            'title' => 'Addresses per sitemap page',
            'what' => 'How many addresses go in each page of a sitemap before it splits into page 2, 3 and so on.',
            'why' => 'Search engines accept up to 50,000, but a smaller page builds faster and is less likely to time out on a large site.',
            'do' => 'Leave 2000 unless a content type has tens of thousands of entries.',
        ],
        'sitemap_check' => [
            'title' => 'Check the sitemap',
            'what' => 'Walks every address in the sitemap and looks for problems: addresses that redirect, entries whose canonical points elsewhere, duplicates, extra addresses that do not answer 200, and rules that match nothing.',
            'why' => 'Search Console reports these as sitemap errors weeks later. Fixing them first keeps the sitemap trusted.',
            'do' => 'Run it after changing what goes in, and after large content changes. It also runs nightly.',
        ],
        'sitemap_legacy' => [
            'title' => 'Old sitemap addresses',
            'what' => 'Redirects /sitemap.xml and /sitemap_index.xml to /wp-sitemap.xml.',
            'why' => 'Sites that ran Yoast submitted /sitemap_index.xml to Search Console. Without this redirect that submission starts failing after the switch.',
            'do' => 'Leave it on. After a few weeks, submit /wp-sitemap.xml in Search Console as well.',
        ],
        'module_redirects' => [
            'title' => 'Redirects',
            'what' => 'Rules that send an old address to a new one.',
            'why' => 'Every link from another site, bookmark and search result points at an address. When that address breaks, the visitor sees an error and the page loses the ranking it earned.',
            'do' => 'Import the Redirection plugin map once from Tools. After that, redirects are added automatically when an entry changes address, and the 404 monitor shows what is still broken.',
        ],
        'auto_redirects' => [
            'title' => 'Redirect when an address changes',
            'what' => 'When a published entry\'s slug, parent or content type changes, a 301 from the old address to the new one is added.',
            'why' => 'Editors fix typos in slugs and reorganize pages. Without this, each change silently breaks every existing link.',
            'do' => 'Leave it on. Automatic rules appear under Redirects marked Automatic and can be edited or removed like any other.',
        ],
        'trashed_redirects' => [
            'title' => 'Deleted entries',
            'what' => 'What visitors and search engines get when a published entry is moved to the bin.',
            'why' => 'A redirect to the archive keeps visitors on the site and passes on links. 410 Gone tells search engines to drop the address quickly.',
            'do' => 'Redirect to the archive suits locations and people. Restoring the entry removes the rule.',
        ],
        'monitor' => [
            'title' => '404 monitor',
            'what' => 'A count of every address that answered page not found, with the last referring page.',
            'why' => 'It is the only way to find links broken by the migration, by other sites, or by typos in menus, and fix them before visitors give up.',
            'do' => 'Check it weekly after launch. Sort by hits and add a redirect to the most visited ones in one click. Scanner noise such as /wp-admin probes is ignored.',
        ],
        'robots_txt' => [
            'title' => 'robots.txt',
            'what' => 'The file at /robots.txt that tells crawlers which parts of the site they may visit.',
            'why' => 'It is the first file every crawler reads. It points them at the sitemap and is where AI crawlers are allowed or refused.',
            'do' => 'Nothing for most sites. If a physical robots.txt file exists at the site root it wins over this one, and the overview flags it.',
        ],
        'ai_policy' => [
            'title' => 'AI crawler policy',
            'what' => 'robots.txt rules for the named crawlers of OpenAI, Anthropic, Google, Apple, Perplexity, Meta and others, grouped as training, search and user crawlers.',
            'why' => 'Search and user crawlers fetch pages to answer questions and link back to the source, which brings visitors. Training crawlers collect text to build models and send none.',
            'do' => 'Allow everything for the widest reach. Allow AI search and assistants, block model training keeps the site citable in AI answers without contributing to training data. Block every AI crawler removes the site from AI answers too.',
        ],
        'llms' => [
            'title' => 'llms.txt',
            'what' => 'A Markdown summary of the site at /llms.txt: what the organization is, its key pages, each content type and the latest entries.',
            'why' => 'AI assistants read it to understand a site quickly and pick the right pages to cite, instead of guessing from the navigation.',
            'do' => 'Mark the most important pages as Key page in their editor panel, and choose which content types to list under Search appearance. The file updates itself.',
        ],
        'cleanup' => [
            'title' => 'Crawl cleanup',
            'what' => 'Removes tags, links and feeds WordPress adds to every page that no visitor uses.',
            'why' => 'Each extra address is one more thing for crawlers to fetch and possibly index as a duplicate. Less clutter means more crawl attention for real content.',
            'do' => 'Leave the defaults on. Each item can be switched off on its own if an integration needs it.',
        ],
        'indexnow' => [
            'title' => 'IndexNow',
            'what' => 'A ping sent to Bing, Yandex, Seznam and Naver each time an entry is published, updated or deleted.',
            'why' => 'Those engines then crawl the change within minutes instead of days. Bing also feeds ChatGPT search and Copilot. Google does not use IndexNow and reads the sitemap instead.',
            'do' => 'Nothing. The key file is created and served automatically.',
        ],
        'module_editor' => [
            'title' => 'Editor panel',
            'what' => 'The Search and sharing panel under each entry and term, with a live preview, character guides, variable buttons and content checks.',
            'why' => 'Editors see exactly what searchers and sharers will see before publishing, and get specific suggestions rather than a score.',
            'do' => 'Every field is optional. Leave them blank and the templates fill them in.',
        ],
        'defer' => [
            'title' => 'Pausing for SEO plugins',
            'what' => 'If Yoast, Rank Math or another SEO plugin is switched on, the built-in modules it also provides pause.',
            'why' => 'Two systems printing titles, canonicals or structured data on the same page confuse search engines and can drop pages from results.',
            'do' => 'On a new site, nothing: no other SEO plugin is needed. If one is active, deactivate it and the paused modules start on their own. On a site that already used one, import its data from Tools first.',
        ],
        'org_name' => [
            'title' => 'Organization name',
            'what' => 'The name search engines show in the knowledge panel and beside the site in results.',
            'why' => 'Google uses it for the site name shown above every result. A short name helps people who search for it find the full name.',
            'do' => 'Leave blank to use the site title, or type the full public name.',
            'auto' => 'Falls back to the site title in Settings, General.',
        ],
        'org_type' => [
            'title' => 'Organization type',
            'what' => 'The schema.org type of the organization.',
            'why' => 'A more specific type helps search engines and AI assistants describe the organization correctly.',
            'do' => 'Choose Local business or Professional service when people visit your locations, Company otherwise.',
        ],
        'org_logo' => [
            'title' => 'Logo for search results',
            'what' => 'The logo search engines may show beside the organization in results and the knowledge panel.',
            'why' => 'Google needs a logo it can display on a white background, at least 112 pixels square.',
            'do' => 'Leave blank to use the header logo. Upload a version on a solid background if the header logo is white.',
            'auto' => 'Falls back to the header logo in Site Options, then the bundled logo.',
        ],
        'org_description' => [
            'title' => 'Organization description',
            'what' => 'A short description of the organization used in structured data, the home page description and llms.txt.',
            'why' => 'It is how AI assistants and the knowledge panel summarize what the organization does.',
            'do' => 'Leave blank to reuse the footer blurb, which already says it well.',
            'auto' => 'Falls back to the footer blurb in Site Options, then the tagline.',
        ],
        'org_contact' => [
            'title' => 'Contact details',
            'what' => 'Email, phone and address published in structured data.',
            'why' => 'They let search engines show contact details in the knowledge panel and confirm the organization is real.',
            'do' => 'Leave blank. The details in Site Options, General are used, so they are only ever entered once.',
            'auto' => 'Read from Site Options, General.',
        ],
        'org_same_as' => [
            'title' => 'Other official profiles',
            'what' => 'Links to other places that describe the organization.',
            'why' => 'They connect the site to the same organization elsewhere, which builds the knowledge panel.',
            'do' => 'Add Wikipedia, Wikidata or similar pages. Social profiles from Site Options are already included.',
            'auto' => 'LinkedIn, X, Facebook, Instagram, YouTube and TikTok from Site Options are always included.',
        ],
        'separator' => [
            'title' => 'Title separator',
            'what' => 'The character between parts of a title, such as the page name and the site name.',
            'why' => 'Purely visual. A hyphen is the most common separator and reads cleanly in results.',
            'do' => 'Keep the hyphen unless the brand prefers another character.',
        ],
        'og_image' => [
            'title' => 'Default share image',
            'what' => 'The image shown when a link is shared and the entry has no share or featured image.',
            'why' => 'Without one, networks pick a random image from the page or show a blank card.',
            'do' => 'Upload a 1200 by 630 branded image.',
            'auto' => 'Falls back to the share image in Site Options, General.',
        ],
        'social_cards' => [
            'title' => 'Social accounts',
            'what' => 'The X account credited on cards, and the Facebook app for insights.',
            'why' => 'Shared cards then link back to the official account.',
            'do' => 'Leave blank to read the X account from Site Options.',
            'auto' => 'The username is read from the X profile address in Site Options, Social profiles.',
        ],
        'noindex' => [
            'title' => 'Show in search results',
            'what' => 'Whether search engines may list these pages. Off adds a noindex robots tag and removes them from the sitemap.',
            'why' => 'Thin or utility pages, such as tag archives or internal lists, compete with the important pages and make the site look lower quality.',
            'do' => 'Keep it on for content people search for. Turn it off for types that only exist to support other pages.',
        ],
        'noindex_search' => [
            'title' => 'Search results pages',
            'what' => 'Adds noindex to the site search results page.',
            'why' => 'Search engines treat indexed internal search results as low quality, and spammers use them to plant links.',
            'do' => 'Leave it on.',
        ],
        'noindex_empty_terms' => [
            'title' => 'Empty archives',
            'what' => 'Adds noindex to category and tag archives with no published entries.',
            'why' => 'An empty list is a page with nothing on it, which search engines count as a soft 404.',
            'do' => 'Leave it on.',
        ],
        'thin_archives' => [
            'title' => 'Date archives',
            'what' => 'Year, month and day archives of news.',
            'why' => 'They repeat the news archive in another order and add nothing searchers want.',
            'do' => 'Leave it on.',
        ],
        'max_image_preview' => [
            'title' => 'Large image previews',
            'what' => 'The max-image-preview:large robots directive.',
            'why' => 'Google only shows large thumbnails in Discover and image rich results when allowed to.',
            'do' => 'Leave it on.',
        ],
        'attachment_redirect' => [
            'title' => 'Attachment pages',
            'what' => 'WordPress creates a page for every uploaded file. This sends those pages to the file itself.',
            'why' => 'Attachment pages are empty pages that search engines index by the thousand.',
            'do' => 'Leave it on.',
        ],
        'canonical' => [
            'title' => 'Canonical URLs',
            'what' => 'The one official address for a page, given in a link rel=canonical tag.',
            'why' => 'The same page is often reachable at several addresses, for example with tracking parameters. The canonical tells search engines which one to rank, so links are not split.',
            'do' => 'Keep the defaults. Set a canonical on an entry only when it republishes content that lives elsewhere.',
        ],
        'title_template' => [
            'title' => 'Title templates',
            'what' => 'A pattern for titles, made of text and variables such as %%title%%, %%sep%% and %%sitename%%.',
            'why' => 'Consistent, descriptive titles on hundreds of entries without anyone typing them.',
            'do' => 'Click a variable to insert it. Wrap optional parts in square brackets: in [ at %%field:company%%] the whole part disappears when the company is empty, so no stray words are left. Clear a template to restore the default.',
        ],
        'description_template' => [
            'title' => 'Description templates',
            'what' => 'A pattern for meta descriptions, built from fields and the summary.',
            'why' => 'Google shows the description under the title. One written from the facts, such as the address of a location, earns more clicks than a cut off first paragraph.',
            'do' => 'Lead with the facts searchers want, then %%excerpt%%. Descriptions are trimmed to about 155 characters at a word boundary.',
        ],
        'schema_type' => [
            'title' => 'Structured data type',
            'what' => 'What kind of thing each entry is described as in structured data.',
            'why' => 'The type decides which rich results the page can earn, such as business details or FAQs.',
            'do' => 'The defaults match the content model. Change them only for a type that is used differently.',
        ],
        'verification' => [
            'title' => 'Webmaster verification',
            'what' => 'Tags that prove to Google, Bing and others that the site belongs to the organization.',
            'why' => 'Verification unlocks Search Console and Bing Webmaster Tools: indexing reports, search queries and sitemap submission.',
            'do' => 'In Search Console choose the HTML tag method and paste only the content value. Skip it if the domain is verified through DNS.',
        ],
        'check_visibility' => [
            'title' => 'Search engine visibility',
            'what' => 'The Discourage search engines setting under Settings, Reading.',
            'why' => 'When ticked, the whole site asks search engines not to index it. It is right for staging and disastrous on production.',
            'do' => 'Untick it on production right after launch.',
        ],
        'check_permalinks' => [
            'title' => 'Permalinks',
            'what' => 'The address format under Settings, Permalinks.',
            'why' => 'Plain addresses such as ?p=123 break every pretty URL and describe nothing.',
            'do' => 'Use Post name.',
        ],
        'check_https' => [
            'title' => 'HTTPS',
            'what' => 'Whether the site address starts with https.',
            'why' => 'HTTPS is a ranking signal, and browsers warn visitors on plain HTTP.',
            'do' => 'Set both addresses in Settings, General to https.',
        ],
        'check_descriptions' => [
            'title' => 'Missing descriptions',
            'what' => 'Indexable entries whose description would come out empty.',
            'why' => 'Google then picks any text from the page, often navigation or a cookie notice.',
            'do' => 'Open Bulk editor, filter by Missing description and write one, or add an excerpt to the entry.',
        ],
        'check_duplicates' => [
            'title' => 'Duplicate titles',
            'what' => 'Indexable entries that share the same title.',
            'why' => 'Search engines cannot tell which one to rank, so often neither ranks well.',
            'do' => 'Open Bulk editor, filter by Duplicate title and make each one distinct.',
        ],
        'check_physical_files' => [
            'title' => 'Physical files at the site root',
            'what' => 'A robots.txt, llms.txt or sitemap.xml file on the server.',
            'why' => 'A real file wins over the generated one, so changes here would have no effect.',
            'do' => 'Delete the file on the server, or turn off the matching module.',
        ],
        'check_404s' => [
            'title' => 'Unresolved 404s',
            'what' => 'Addresses visitors hit in the last week that answered page not found and have no redirect.',
            'why' => 'Each one is a visitor who left, and often a link from another site whose value is lost.',
            'do' => 'Open Redirects, 404 log and add a redirect for the busiest ones.',
        ],
        'check_alt' => [
            'title' => 'Images without alt text',
            'what' => 'Images in the media library with no alternative text.',
            'why' => 'Alt text is read aloud to people using screen readers, and it is how search engines understand images.',
            'do' => 'Open the media library in list view and fill in Alternative text, starting with recent uploads.',
        ],
    ]);
}

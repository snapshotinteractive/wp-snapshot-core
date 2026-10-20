<?php

namespace Snapshot\Mcp;

use Snapshot\Mcp\Tools\Content;
use Snapshot\Mcp\Tools\Media;
use Snapshot\Mcp\Tools\Menus;
use Snapshot\Mcp\Tools\Site;
use Snapshot\Mcp\Tools\Terms;
use stdClass;

class Abilities
{
    public const CATEGORY = 'snapshot';

    private const PREFIX = 'snapshot/';

    private ?array $definitions = null;

    private array $handlers = [];

    public function __construct(private Fields $fields) {}

    public function boot(): void
    {
        add_action('wp_abilities_api_categories_init', function () {
            wp_register_ability_category(self::CATEGORY, [
                'label' => __('Snapshot site editing', 'snapshot-core'),
                'description' => __('Read and edit the content of this site over MCP.', 'snapshot-core'),
            ]);
        });

        add_action('wp_abilities_api_init', [$this, 'register']);
    }

    public function register(): void
    {
        foreach ($this->definitions() as $tool => $definition) {
            wp_register_ability(self::PREFIX.$tool, [
                'label' => $definition['label'],
                'description' => $definition['description'],
                'category' => self::CATEGORY,
                'input_schema' => $this->schema($definition),
                'execute_callback' => $definition['run'],
                'permission_callback' => fn () => current_user_can(Mcp::capability()) && Mcp::allows($definition['level']),
                'meta' => [
                    'annotations' => [
                        'readonly' => $definition['level'] === 'read',
                        'destructive' => $definition['destructive'] ?? false,
                        'idempotent' => $definition['idempotent'] ?? $definition['level'] === 'read',
                    ],
                    'show_in_rest' => false,
                ],
            ]);
        }
    }

    public function tools(): array
    {
        $tools = [];

        foreach ($this->definitions() as $tool => $definition) {
            if (! Mcp::allows($definition['level']) || ! wp_get_ability(self::PREFIX.$tool)) {
                continue;
            }

            $tools[] = [
                'name' => $tool,
                'title' => $definition['label'],
                'description' => $definition['description'],
                'inputSchema' => $this->json($this->schema($definition)),
                'annotations' => [
                    'title' => $definition['label'],
                    'readOnlyHint' => $definition['level'] === 'read',
                    'destructiveHint' => $definition['destructive'] ?? false,
                    'idempotentHint' => $definition['idempotent'] ?? $definition['level'] === 'read',
                    'openWorldHint' => $tool === 'upload-media',
                ],
            ];
        }

        return $tools;
    }

    public function has(string $tool): bool
    {
        $definition = $this->definitions()[$tool] ?? null;

        return $definition !== null && Mcp::allows($definition['level']) && wp_get_ability(self::PREFIX.$tool) !== null;
    }

    public function run(string $tool, array $arguments)
    {
        $definition = $this->definitions()[$tool];
        $result = wp_get_ability(self::PREFIX.$tool)->execute($arguments);

        if ($definition['level'] !== 'read' && function_exists('snap_security_log')) {
            snap_security_log('mcp.tool_called', $this->describeCall($tool, $arguments, $result), [
                'tool' => $tool,
                'ok' => ! is_wp_error($result),
                'target' => array_intersect_key($arguments, array_flip(['id', 'type', 'slug', 'status', 'taxonomy', 'menu', 'item_id', 'revision_id', 'options_page'])),
                'fields' => array_keys((array) ($arguments['fields'] ?? [])),
                'severity' => is_wp_error($result) ? 'notice' : 'info',
            ]);
        }

        return $result;
    }

    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $content = $this->handler(Content::class, fn () => new Content($this->fields));
        $media = $this->handler(Media::class, fn () => new Media);
        $terms = $this->handler(Terms::class, fn () => new Terms($this->fields));
        $menus = $this->handler(Menus::class, fn () => new Menus);
        $site = $this->handler(Site::class, fn () => new Site($this->fields));

        $id = ['type' => 'integer', 'minimum' => 1, 'description' => 'Entry ID.'];
        $type = ['type' => 'string', 'description' => 'Content type, such as page, post or location. site-info lists them.'];
        $paging = [
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
        ];
        $writable = [
            'title' => ['type' => 'string'],
            'content' => ['type' => 'string', 'description' => 'Body HTML or block markup. Types built with the page builder keep their content in fields.sections instead.'],
            'excerpt' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'parent' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Parent entry ID, 0 for none.'],
            'menu_order' => ['type' => 'integer'],
            'template' => ['type' => 'string', 'description' => 'Page template file, or "" for the default. site-info lists them per type.'],
            'featured_image' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Attachment ID, 0 to remove.'],
            'terms' => ['type' => 'object', 'description' => 'Taxonomy name to a list of term IDs, slugs or names. Replaces the terms of each taxonomy given.', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => ['integer', 'string']]]],
            'fields' => ['type' => 'object', 'description' => 'ACF field name to value. Each field given is replaced whole. See describe-fields.', 'additionalProperties' => true],
        ];

        return $this->definitions = snap_filter('snap/mcp/tools', [
            'site-info' => [
                'level' => 'read',
                'label' => 'Site overview',
                'description' => 'The site, its content types with their fields and counts, taxonomies, menus, options pages, and what this connection may do. Call this first.',
                'properties' => [],
                'run' => fn ($input) => $site->info($input),
            ],
            'list-content' => [
                'level' => 'read',
                'label' => 'List content',
                'description' => 'Find entries of a content type by status, text, parent or term. Returns IDs, titles, status and addresses.',
                'properties' => [
                    'type' => $type + ['default' => 'page', 'description' => 'Content type, or "any" for every type.'],
                    'status' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => [...Content::STATUSES, 'trash']], 'description' => 'Defaults to everything but the bin.'],
                    'search' => ['type' => 'string'],
                    'parent' => ['type' => 'integer', 'minimum' => 0],
                    'taxonomy' => ['type' => 'string'],
                    'term' => ['type' => 'string', 'description' => 'Term slug or ID, with taxonomy.'],
                    'orderby' => ['type' => 'string', 'enum' => ['modified', 'date', 'title', 'menu_order'], 'default' => 'modified'],
                    'order' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
                ] + $paging,
                'run' => fn ($input) => $content->list($input),
            ],
            'get-content' => [
                'level' => 'read',
                'label' => 'Read an entry',
                'description' => 'Everything about one entry: title, body, excerpt, status, terms, featured image, every ACF field including the page builder sections, and its revision count.',
                'properties' => ['id' => $id, 'slug' => ['type' => 'string'], 'type' => $type],
                'run' => fn ($input) => $content->get($input),
            ],
            'describe-fields' => [
                'level' => 'read',
                'label' => 'Describe fields',
                'description' => 'The ACF fields an entry, content type, taxonomy or options page accepts: names, types, choices, defaults, instructions, and for the page builder every layout with its fields.',
                'properties' => ['id' => $id, 'type' => $type, 'taxonomy' => ['type' => 'string'], 'options_page' => ['type' => 'string']],
                'run' => fn ($input) => $site->describe($input),
            ],
            'list-revisions' => [
                'level' => 'read',
                'label' => 'List revisions',
                'description' => 'Saved revisions of an entry, newest first, for undoing a change with restore-revision.',
                'properties' => ['id' => $id] + ['per_page' => $paging['per_page']],
                'required' => ['id'],
                'run' => fn ($input) => $content->revisions($input),
            ],
            'list-terms' => [
                'level' => 'read',
                'label' => 'List terms',
                'description' => 'Terms of a taxonomy (categories, tags, departments, regions and so on) with their IDs, counts and fields.',
                'properties' => ['taxonomy' => ['type' => 'string'], 'search' => ['type' => 'string'], 'parent' => ['type' => 'integer', 'minimum' => 0], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100], 'page' => $paging['page']],
                'required' => ['taxonomy'],
                'run' => fn ($input) => $terms->list($input),
            ],
            'list-media' => [
                'level' => 'read',
                'label' => 'List media',
                'description' => 'Media library items with IDs, addresses, alt text and sizes.',
                'properties' => ['search' => ['type' => 'string'], 'mime' => ['type' => 'string', 'description' => 'For example image, or application/pdf.']] + $paging,
                'run' => fn ($input) => $media->list($input),
            ],
            'get-menus' => [
                'level' => 'read',
                'label' => 'Read menus',
                'description' => 'Every navigation menu with its locations and items.',
                'properties' => [],
                'run' => fn ($input) => $menus->list($input),
            ],
            'get-options' => [
                'level' => 'read',
                'label' => 'Read site options',
                'description' => 'The site title, tagline and home page settings, and every ACF options page (such as Site Options) with its values.',
                'properties' => [],
                'run' => fn ($input) => $site->options($input),
            ],
            'list-users' => [
                'level' => 'read',
                'label' => 'List users',
                'description' => 'User accounts with names and roles, for authors and linked staff. Read only; email addresses are not shared.',
                'properties' => ['search' => ['type' => 'string'], 'roles' => ['type' => 'array', 'items' => ['type' => 'string']], 'per_page' => $paging['per_page']],
                'run' => fn ($input) => $site->users($input),
            ],
            'create-content' => [
                'level' => 'edit',
                'label' => 'Create a draft',
                'description' => 'Create a new entry of any content type. It is always saved as a draft; publishing is a separate step.',
                'properties' => ['type' => $type + ['default' => 'page']] + $writable,
                'required' => ['title'],
                'run' => fn ($input) => $content->create($input),
            ],
            'update-content' => [
                'level' => 'edit',
                'label' => 'Edit an entry',
                'description' => 'Change an existing entry. Only the properties given change; each field in fields is replaced whole. A revision is saved before and after, so restore-revision can undo it. Published entries change on the live site at once.',
                'properties' => ['id' => $id] + $writable,
                'required' => ['id'],
                'idempotent' => true,
                'run' => fn ($input) => $content->update($input),
            ],
            'restore-revision' => [
                'level' => 'edit',
                'label' => 'Restore a revision',
                'description' => 'Put an entry back to a saved revision, including its fields. The current state is saved as a revision first.',
                'properties' => ['revision_id' => ['type' => 'integer', 'minimum' => 1]],
                'required' => ['revision_id'],
                'run' => fn ($input) => $content->restore($input),
            ],
            'upload-media' => [
                'level' => 'edit',
                'label' => 'Upload media',
                'description' => 'Add a file to the media library from a public url, or from base64 data with a filename. Returns its attachment ID for image fields.',
                'properties' => [
                    'url' => ['type' => 'string', 'format' => 'uri'],
                    'data' => ['type' => 'string', 'description' => 'Base64 file contents, as an alternative to url.'],
                    'filename' => ['type' => 'string', 'description' => 'Required with data, for example team-photo.jpg.'],
                    'title' => ['type' => 'string'],
                    'alt' => ['type' => 'string', 'description' => 'Describe the image for people who cannot see it.'],
                    'caption' => ['type' => 'string'],
                    'attach_to' => ['type' => 'integer', 'minimum' => 0],
                ],
                'run' => fn ($input) => $media->upload($input),
            ],
            'update-media' => [
                'level' => 'edit',
                'label' => 'Edit media details',
                'description' => 'Change the title, alt text, caption or description of a media item.',
                'properties' => ['id' => $id, 'title' => ['type' => 'string'], 'alt' => ['type' => 'string'], 'caption' => ['type' => 'string'], 'description' => ['type' => 'string']],
                'required' => ['id'],
                'idempotent' => true,
                'run' => fn ($input) => $media->update($input),
            ],
            'save-term' => [
                'level' => 'edit',
                'label' => 'Add or edit a term',
                'description' => 'Create a term in a taxonomy, or change one when id is given.',
                'properties' => ['taxonomy' => ['type' => 'string'], 'id' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'description' => ['type' => 'string'], 'parent' => ['type' => 'integer', 'minimum' => 0], 'fields' => $writable['fields']],
                'required' => ['taxonomy'],
                'run' => fn ($input) => $terms->save($input),
            ],
            'save-menu-item' => [
                'level' => 'edit',
                'label' => 'Add or edit a menu item',
                'description' => 'Add an item to a menu, or change one when item_id is given. Link to an entry or term with object_id, or anywhere with url. Menus change on the live site at once.',
                'properties' => [
                    'menu' => ['type' => 'integer', 'minimum' => 1],
                    'item_id' => ['type' => 'integer', 'minimum' => 1],
                    'title' => ['type' => 'string', 'description' => 'Leave out to use the linked entry\'s title.'],
                    'object_id' => ['type' => 'integer', 'minimum' => 1],
                    'object_type' => ['type' => 'string', 'enum' => ['post', 'term'], 'default' => 'post', 'description' => 'What object_id is: an entry (post) or a term.'],
                    'url' => ['type' => 'string'],
                    'parent' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Parent menu item ID.'],
                    'position' => ['type' => 'integer', 'minimum' => 0],
                    'new_tab' => ['type' => 'boolean'],
                    'classes' => ['type' => 'string'],
                ],
                'required' => ['menu'],
                'run' => fn ($input) => $menus->saveItem($input),
            ],
            'update-options' => [
                'level' => 'edit',
                'label' => 'Edit site options',
                'description' => 'Change fields on an ACF options page such as Site Options (logo, contact details, social links). Changes show on the live site at once.',
                'properties' => ['options_page' => ['type' => 'string'], 'fields' => $writable['fields']],
                'required' => ['options_page', 'fields'],
                'idempotent' => true,
                'run' => fn ($input) => $site->updateOptions($input),
            ],
            'set-status' => [
                'level' => 'publish',
                'label' => 'Publish or unpublish',
                'description' => 'Publish, schedule (with date), make private, or return an entry to draft or pending. Also restores an entry from the bin.',
                'properties' => ['id' => $id, 'status' => ['type' => 'string', 'enum' => Content::STATUSES], 'date' => ['type' => 'string', 'description' => 'For future: when to publish, in the site time zone, such as 2026-12-01T09:00:00.']],
                'required' => ['id', 'status'],
                'idempotent' => true,
                'run' => fn ($input) => $content->setStatus($input),
            ],
            'trash-content' => [
                'level' => 'publish',
                'label' => 'Move to the bin',
                'description' => 'Move an entry to the bin. It can be restored with set-status for 30 days.',
                'properties' => ['id' => $id],
                'required' => ['id'],
                'destructive' => true,
                'run' => fn ($input) => $content->trash($input),
            ],
            'remove-menu-item' => [
                'level' => 'publish',
                'label' => 'Remove a menu item',
                'description' => 'Remove an item from a menu. This cannot be undone; get-menus first to note what it was.',
                'properties' => ['item_id' => ['type' => 'integer', 'minimum' => 1]],
                'required' => ['item_id'],
                'destructive' => true,
                'run' => fn ($input) => $menus->removeItem($input),
            ],
            'update-site-settings' => [
                'level' => 'publish',
                'label' => 'Edit site settings',
                'description' => 'Change the site title, tagline, or which pages are the home page and the posts page.',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'tagline' => ['type' => 'string'],
                    'show_on_front' => ['type' => 'string', 'enum' => ['posts', 'page']],
                    'page_on_front' => ['type' => 'integer', 'minimum' => 0],
                    'page_for_posts' => ['type' => 'integer', 'minimum' => 0],
                ],
                'idempotent' => true,
                'run' => fn ($input) => $site->updateSettings($input),
            ],
        ]);
    }

    private function schema(array $definition): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $definition['properties'],
            'additionalProperties' => false,
            'default' => [],
        ];

        if (! empty($definition['required'])) {
            $schema['required'] = $definition['required'];
        }

        return $schema;
    }

    private function json(array $schema)
    {
        unset($schema['default']);

        return $this->objects($schema);
    }

    private function objects($node)
    {
        if (! is_array($node)) {
            return $node;
        }

        foreach ($node as $key => $value) {
            $node[$key] = $key === 'properties' && $value === [] ? new stdClass : $this->objects($value);
        }

        return $node;
    }

    private function describeCall(string $tool, array $arguments, $result): string
    {
        $label = $this->definitions()[$tool]['label'];
        $target = $arguments['id'] ?? $arguments['item_id'] ?? $arguments['revision_id'] ?? $arguments['options_page'] ?? $arguments['taxonomy'] ?? null;
        $title = is_array($result) && isset($result['title']) && is_string($result['title']) ? ' "'.$result['title'].'"' : '';
        $outcome = is_wp_error($result) ? ': refused, '.$result->get_error_message() : '';

        return sprintf('MCP: %s%s%s%s', $label, $target !== null ? ' '.(is_scalar($target) ? $target : '') : '', $title, $outcome);
    }

    private function handler(string $class, callable $make): object
    {
        return $this->handlers[$class] ??= $make();
    }
}

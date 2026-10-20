<?php

function snap_mcp_access_levels(): array
{
    return snap_filter('snap/mcp/access_levels', [
        'read' => 'Read only',
        'edit' => 'Read, create drafts and edit existing content',
        'publish' => 'Edit, plus publish, unpublish, the bin, menu removals and site settings',
    ]);
}

function snap_mcp_token_lifetimes(): array
{
    return snap_filter('snap/mcp/token_lifetimes', [
        7 => '7 days',
        30 => '30 days',
        90 => '90 days',
    ]);
}

function snap_mcp_excluded_post_types(): array
{
    return snap_filter('snap/mcp/excluded_post_types', [
        'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request',
        'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face',
        'acf-field', 'acf-field-group', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page',
    ]);
}

function snap_mcp_instructions(): string
{
    return snap_filter('snap/mcp/instructions', implode("\n", [
        'This server edits a WordPress site built on the Snapshot starter theme, acting as the signed in administrator who created the token.',
        'Start with site-info to see the content types, taxonomies, menus and what this connection is allowed to do.',
        'Pages are built from an ACF Flexible Content field named "sections". Call describe-fields before writing fields, and send field names, never field keys.',
        'Field values replace the whole field: to change one section, read the page with get-content, change that row, and send the complete "sections" list back.',
        'Images, files and posts in fields are attachment or post IDs. Upload an image with upload-media first, then use its ID.',
        'New content is always created as a draft. Publishing, unpublishing, the bin and changing the address of published content need the publish access level.',
        'Every change is saved as a revision first, so list-revisions and restore-revision can undo it, and every change is recorded in the site activity log under the administrator\'s name.',
        'Ask the person before changing published content or site wide settings.',
    ]));
}

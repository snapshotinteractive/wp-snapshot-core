<?php

function snap_system_tabs(): array
{
    return snap_filter('snap/system/tabs', [
        'status' => 'Status',
        'tools' => 'Tools',
        'experience' => 'Admin experience',
        'activity' => 'Activity log',
        'mcp' => 'AI access',
    ]);
}

function snap_system_sections(): array
{
    return snap_filter('snap/system/sections', [
        'login' => ['label' => 'Sign in screen', 'intro' => 'The screen at wp-login.php. Colours left blank follow tokens.css, so the screen matches the site brand until you change it here.'],
        'notices' => ['label' => 'Notices', 'intro' => 'Update nags and plugin promotions crowd every admin screen. Error notices always stay visible, and so do notices on Snapshot screens.'],
        'clean' => ['label' => 'Clean admin', 'intro' => 'Removes WordPress furniture editors do not need. Every switch is reversible.'],
        'brand' => ['label' => 'Branding and support', 'intro' => 'The admin colour scheme, footer credit and the support widget on the dashboard.'],
    ]);
}

function snap_system_settings_schema(): array
{
    return snap_filter('snap/system/settings', [
        'login_logo' => ['section' => 'login', 'type' => 'image', 'default' => 0, 'label' => 'Logo', 'help' => 'Shown above the form. Leave blank to use the header logo from Site Options, then the Snapshot wordmark.'],
        'login_logo_link' => ['section' => 'login', 'type' => 'select', 'default' => 'home', 'choices' => ['home' => 'This site\'s home page', 'agency' => 'snapshot.agency', 'none' => 'Not a link'], 'label' => 'Logo links to', 'help' => 'Where the logo above the form goes when clicked.'],
        'login_message' => ['section' => 'login', 'type' => 'textarea', 'default' => '', 'label' => 'Message above the form', 'help' => 'Optional. One or two lines, for example who to contact for an account. Plain text, line breaks kept.'],
        'login_background' => ['section' => 'login', 'type' => 'color', 'default' => '', 'label' => 'Page background', 'help' => 'Leave blank to use the dark brand colour.'],
        'login_panel' => ['section' => 'login', 'type' => 'color', 'default' => '', 'label' => 'Form background', 'help' => 'Leave blank to use the paper colour.'],
        'login_text' => ['section' => 'login', 'type' => 'color', 'default' => '', 'label' => 'Form text', 'help' => 'Leave blank to use the ink colour. Keep it readable on the form background.'],
        'login_button' => ['section' => 'login', 'type' => 'color', 'default' => '', 'label' => 'Button', 'help' => 'Leave blank to use the primary colour.'],
        'login_button_text' => ['section' => 'login', 'type' => 'color', 'default' => '', 'label' => 'Button text', 'help' => 'Leave blank to use the text colour paired with the primary colour.'],
        'login_accent' => ['section' => 'login', 'type' => 'color', 'default' => '', 'label' => 'Accent line and focus ring', 'help' => 'The rule above the form and the outline around a focused field. Leave blank to use the accent colour.'],
        'login_hide_back_link' => ['section' => 'login', 'type' => 'bool', 'default' => false, 'label' => 'Hide the link back to the site', 'help' => 'Off shows the Go to site link under the form.'],
        'login_hide_language' => ['section' => 'login', 'type' => 'bool', 'default' => true, 'label' => 'Hide the language switcher', 'help' => 'The switcher only matters on sites with more than one language installed.'],

        'notices_mode' => ['section' => 'notices', 'type' => 'select', 'default' => 'nags', 'choices' => ['all' => 'Show every notice', 'nags' => 'Hide update nags from everyone but administrators', 'quiet' => 'Hide update nags, and plugin notices except errors, for everyone but administrators', 'silent' => 'Hide update nags, and plugin notices except errors, for everyone'], 'label' => 'Admin notices', 'help' => 'Notices hidden here are still in the page, just not shown, so nothing a plugin needs breaks.'],

        'clean_dashboard' => ['section' => 'clean', 'type' => 'bool', 'default' => true, 'label' => 'Tidy the dashboard', 'help' => 'Removes the welcome panel, WordPress Events and News, and Quick Draft. At a Glance, Site Health and the Snapshot widgets stay.'],
        'hide_help_tabs' => ['section' => 'clean', 'type' => 'bool', 'default' => false, 'label' => 'Hide the Help tab', 'help' => 'The Help drop down at the top right of most screens.'],
        'hide_footer_version' => ['section' => 'clean', 'type' => 'bool', 'default' => true, 'label' => 'Hide the WordPress version in the footer', 'help' => 'Version numbers help nobody signed in and help attackers who are.'],
        'replace_howdy' => ['section' => 'clean', 'type' => 'bool', 'default' => true, 'label' => 'Replace Howdy with the person\'s name', 'help' => 'The greeting in the top right of the admin bar.'],
        'hide_editor_welcome' => ['section' => 'clean', 'type' => 'bool', 'default' => true, 'label' => 'Skip the block editor welcome guide', 'help' => 'The tour that opens the first time each person edits a page.'],
        'admin_bar_front' => ['section' => 'clean', 'type' => 'select', 'default' => 'all', 'choices' => ['all' => 'Everyone signed in', 'editors' => 'Editors and administrators only', 'none' => 'Nobody'], 'label' => 'Admin bar on the front end', 'help' => 'Who sees the black bar at the top of the site while signed in.'],

        'force_admin_color' => ['section' => 'brand', 'type' => 'bool', 'default' => false, 'label' => 'Use the Snapshot colour scheme for everyone', 'help' => 'Off makes Snapshot the default for new accounts and lets people choose another in their profile.'],
        'footer_text' => ['section' => 'brand', 'type' => 'text', 'default' => '', 'label' => 'Admin footer credit', 'help' => 'Leave blank for Built and supported by Snapshot.'],
        'support_widget' => ['section' => 'brand', 'type' => 'bool', 'default' => true, 'label' => 'Snapshot support widget', 'help' => 'A dashboard box with who to contact for help with the site.'],
        'support_email' => ['section' => 'brand', 'type' => 'text', 'default' => '', 'label' => 'Support email', 'help' => 'Optional. Shown in the support widget.'],
        'support_url' => ['section' => 'brand', 'type' => 'text', 'default' => 'https://snapshot.agency/', 'label' => 'Support link', 'help' => 'Shown in the support widget and the admin bar menu.'],
    ]);
}

function snap_system_noisy_options(): array
{
    return snap_filter('snap/system/noisy_options', [
        'cron', 'rewrite_rules', 'recently_activated', 'active_plugins', 'uninstall_plugins', 'auto_updater.lock',
        'core_updater.lock', 'can_compress_scripts', 'db_upgraded', 'recovery_keys', 'recovery_mode_email_last_sent',
        'theme_mods_', '_transient_', '_site_transient_', 'snapshot_', 'snap_', 'acf_', 'widget_', 'sidebars_widgets',
        'wp_user_roles', 'finished_splitting_shared_terms', 'fresh_site', 'jetpack_', 'action_scheduler_', 'wp_force_deactivated_plugins',
        'https_detection_errors', 'wp_attachment_pages_enabled', 'auto_plugin_theme_update_emails', 'user_count', 'dismissed_update_core',
        'admin_email_lifespan', 'nav_menu_options', 'theme_switched', 'category_children', '_children',
    ]);
}

function snap_activity_categories(): array
{
    return snap_filter('snap/activity/categories', [
        'content' => 'Content',
        'media' => 'Media',
        'term' => 'Categories and tags',
        'menu' => 'Menus',
        'comment' => 'Comments',
        'user' => 'Users',
        'login' => 'Sign in',
        'twofactor' => 'Two-factor',
        'option' => 'Settings',
        'plugin' => 'Plugins',
        'theme' => 'Themes',
        'software' => 'Updates',
        'system' => 'System tools',
        'seo' => 'SEO',
        'security' => 'Security',
        'firewall' => 'Firewall',
        'scan' => 'Scans',
        'data' => 'Import and export',
        'mcp' => 'AI access (MCP)',
    ]);
}

function snap_activity_event_types(): array
{
    return snap_filter('snap/activity/event_types', [
        'content.created' => ['severity' => 'info', 'label' => 'Content created'],
        'content.updated' => ['severity' => 'info', 'label' => 'Content updated'],
        'content.published' => ['severity' => 'info', 'label' => 'Content published'],
        'content.unpublished' => ['severity' => 'notice', 'label' => 'Content unpublished'],
        'content.scheduled' => ['severity' => 'info', 'label' => 'Content scheduled'],
        'content.trashed' => ['severity' => 'notice', 'label' => 'Content moved to the bin'],
        'content.restored' => ['severity' => 'info', 'label' => 'Content restored from the bin'],
        'content.fields_updated' => ['severity' => 'info', 'label' => 'Fields updated'],
        'media.uploaded' => ['severity' => 'info', 'label' => 'Media uploaded'],
        'media.updated' => ['severity' => 'info', 'label' => 'Media details updated'],
        'media.deleted' => ['severity' => 'notice', 'label' => 'Media deleted'],
        'term.created' => ['severity' => 'info', 'label' => 'Term created'],
        'term.updated' => ['severity' => 'info', 'label' => 'Term updated'],
        'term.deleted' => ['severity' => 'notice', 'label' => 'Term deleted'],
        'menu.updated' => ['severity' => 'info', 'label' => 'Menu updated'],
        'menu.deleted' => ['severity' => 'notice', 'label' => 'Menu deleted'],
        'menu.locations' => ['severity' => 'info', 'label' => 'Menu locations changed'],
        'comment.status' => ['severity' => 'info', 'label' => 'Comment moderated'],
        'comment.updated' => ['severity' => 'info', 'label' => 'Comment edited'],
        'comment.deleted' => ['severity' => 'notice', 'label' => 'Comment deleted'],
        'user.updated' => ['severity' => 'info', 'label' => 'Profile updated'],
        'option.updated' => ['severity' => 'info', 'label' => 'Setting changed'],
        'option.site_options' => ['severity' => 'info', 'label' => 'Site Options saved'],
        'theme.customized' => ['severity' => 'info', 'label' => 'Customizer changes published'],
        'theme.widgets' => ['severity' => 'info', 'label' => 'Widgets changed'],
        'software.core_updated' => ['severity' => 'notice', 'label' => 'WordPress updated'],
        'data.exported' => ['severity' => 'notice', 'label' => 'Content exported'],
        'data.imported' => ['severity' => 'notice', 'label' => 'Content imported'],
        'data.personal_export' => ['severity' => 'notice', 'label' => 'Personal data export created'],
        'data.personal_erased' => ['severity' => 'warning', 'label' => 'Personal data erased'],
        'system.tool_run' => ['severity' => 'notice', 'label' => 'System tool run'],
        'system.settings_changed' => ['severity' => 'notice', 'label' => 'Admin experience changed'],
        'mcp.token_created' => ['severity' => 'notice', 'label' => 'MCP token created'],
        'mcp.token_revoked' => ['severity' => 'notice', 'label' => 'MCP token revoked'],
        'mcp.token_rejected' => ['severity' => 'warning', 'label' => 'MCP token refused'],
        'mcp.tool_called' => ['severity' => 'info', 'label' => 'Change made over MCP'],
    ]);
}

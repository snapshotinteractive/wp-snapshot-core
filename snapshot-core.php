<?php

use Snapshot\Plugin;

/**
 * Plugin Name:       Snapshot Core
 * Plugin URI:        https://github.com/snapshotinteractive/wp-snapshot-core
 * Description:       Built-in security, SEO, the Snapshot System panel, the activity log and the Snapshot admin for themes built from the Snapshot starter.
 * Version:           2.0.0-dev
 * Requires at least: 6.9
 * Requires PHP:      8.3
 * Author:            Snapshot Interactive
 * Author URI:        https://snapshot.agency/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       snapshot-core
 * Update URI:        https://github.com/snapshotinteractive/wp-snapshot-core
 */
defined('ABSPATH') || exit;

if (defined('SNAPSHOT_CORE_FILE')) {
    return;
}

define('SNAPSHOT_CORE_FILE', __FILE__);
define('SNAPSHOT_CORE_VERSION', '2.0.0-dev');

if (is_file(__DIR__.'/vendor-prefixed/autoload.php')) {
    require_once __DIR__.'/vendor-prefixed/autoload.php';
}

spl_autoload_register(function (string $class) {
    if (! str_starts_with($class, 'Snapshot\\') || str_starts_with($class, 'Snapshot\\Vendor\\')) {
        return;
    }

    $path = __DIR__.'/src/'.str_replace('\\', '/', substr($class, 9)).'.php';

    if (is_file($path)) {
        require $path;
    }
});

Plugin::register();

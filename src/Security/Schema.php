<?php

namespace Snapshot\Security;

class Schema
{
    public const VERSION = '2026.10.2';

    public const OPTION = 'snapshot_security_schema';

    public const FAILED = 'snapshot_security_schema_failed';

    private const RETRY_AFTER = 600;

    private static bool $ready = false;

    public static function ready(): bool
    {
        return self::$ready;
    }

    public static function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix.'snapshot_sec_'.$name;
    }

    public function ensure(): bool
    {
        if (get_option(self::OPTION) === self::VERSION) {
            return self::$ready = true;
        }

        if ((int) get_transient(self::FAILED) > time() - self::RETRY_AFTER) {
            return false;
        }

        return $this->install();
    }

    public function install(): bool
    {
        global $wpdb;

        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $events = self::table('events');
        $blocks = self::table('blocks');
        $attempts = self::table('attempts');
        $files = self::table('files');

        dbDelta("CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  event varchar(64) NOT NULL,
  severity varchar(16) NOT NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  username varchar(60) NOT NULL DEFAULT '',
  message varchar(255) NOT NULL DEFAULT '',
  context longtext NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY event (event),
  KEY ip (ip),
  KEY severity_created (severity,created_at),
  KEY user_created (user_id,created_at)
) {$charset};");

        dbDelta("CREATE TABLE {$blocks} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ip_start char(32) NOT NULL,
  ip_end char(32) NOT NULL,
  is_range tinyint(1) NOT NULL DEFAULT 0,
  label varchar(64) NOT NULL DEFAULT '',
  scope varchar(16) NOT NULL DEFAULT 'site',
  action varchar(16) NOT NULL DEFAULT 'block',
  source varchar(32) NOT NULL DEFAULT 'manual',
  reason varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  expires_at datetime NULL,
  PRIMARY KEY  (id),
  KEY lookup (is_range,ip_start),
  KEY source (source),
  KEY expires_at (expires_at)
) {$charset};");

        dbDelta("CREATE TABLE {$attempts} (
  bucket char(64) NOT NULL,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  window_start int(10) unsigned NOT NULL DEFAULT 0,
  expires_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (bucket),
  KEY expires_at (expires_at)
) {$charset};");

        dbDelta("CREATE TABLE {$files} (
  path_hash char(64) NOT NULL,
  origin varchar(191) NOT NULL DEFAULT '',
  path text NOT NULL,
  sha256 char(64) NOT NULL,
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  checked_at datetime NOT NULL,
  PRIMARY KEY  (path_hash),
  KEY origin (origin)
) {$charset};");

        $ready = true;

        foreach ([$events, $blocks, $attempts, $files] as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                $ready = false;
            }
        }

        if ($ready) {
            update_option(self::OPTION, self::VERSION, true);
            delete_transient(self::FAILED);
        } else {
            set_transient(self::FAILED, time(), self::RETRY_AFTER);
        }

        return self::$ready = $ready;
    }

    public function drop(): void
    {
        global $wpdb;

        foreach (['events', 'blocks', 'attempts', 'files'] as $name) {
            $wpdb->query('DROP TABLE IF EXISTS '.self::table($name));
        }

        delete_option(self::OPTION);
        self::$ready = false;
    }
}

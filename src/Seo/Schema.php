<?php

namespace Snapshot\Seo;

class Schema
{
    public const VERSION = '2026.10.1';

    public const OPTION = 'snapshot_seo_schema';

    public const FAILED = 'snapshot_seo_schema_failed';

    private const RETRY_AFTER = 600;

    private static bool $ready = false;

    public static function ready(): bool
    {
        return self::$ready;
    }

    public static function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix.'snapshot_seo_'.$name;
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
        $redirects = self::table('redirects');
        $missing = self::table('404');

        dbDelta("CREATE TABLE {$redirects} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source varchar(191) NOT NULL,
  match_type varchar(10) NOT NULL DEFAULT 'exact',
  target text NOT NULL,
  code smallint(3) unsigned NOT NULL DEFAULT 301,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  origin varchar(32) NOT NULL DEFAULT 'manual',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  note varchar(255) NOT NULL DEFAULT '',
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  last_hit datetime NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY lookup (match_type,enabled,source),
  KEY origin (origin),
  KEY object_id (object_id)
) {$charset};");

        dbDelta("CREATE TABLE {$missing} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  path varchar(191) NOT NULL,
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  referrer varchar(255) NOT NULL DEFAULT '',
  bot tinyint(1) NOT NULL DEFAULT 0,
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY path (path),
  KEY last_seen (last_seen)
) {$charset};");

        $ready = true;

        foreach ([$redirects, $missing] as $table) {
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

        foreach (['redirects', '404'] as $name) {
            $wpdb->query('DROP TABLE IF EXISTS '.self::table($name));
        }

        delete_option(self::OPTION);
        self::$ready = false;
    }
}

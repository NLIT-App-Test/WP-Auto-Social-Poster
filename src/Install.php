<?php

namespace Admun\GrowthEngine;

defined('ABSPATH') || exit;

class Install
{
    const DB_VERSION = '2';

    public static function activate(): void
    {
        self::migrate();
        Plugin::schedule_cron();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(Plugin::CRON_HOOK);
    }

    public static function maybe_migrate(): void
    {
        if (get_option('age_db_version') !== self::DB_VERSION) {
            self::migrate();
        }
    }

    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'age_' . $name;
    }

    private static function migrate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();

        // A campaign is the user's goal: a topic to keep posting about for a period of time.
        dbDelta("CREATE TABLE " . self::table('campaigns') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            topic text NOT NULL,
            goal text NOT NULL,
            audience text NULL,
            notes text NULL,
            start_at datetime NOT NULL,
            end_at datetime NOT NULL,
            per_week tinyint unsigned NOT NULL DEFAULT 3,
            post_time varchar(5) NOT NULL DEFAULT '09:00',
            account_ids text NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'planning',
            plan longtext NULL,
            error text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) $c;");

        // One checklist item = one article + its social posts, going live on one date.
        dbDelta("CREATE TABLE " . self::table('items') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            campaign_id bigint unsigned NOT NULL,
            seq smallint unsigned NOT NULL,
            publish_at datetime NOT NULL,
            title varchar(255) NOT NULL,
            keyword varchar(191) NOT NULL DEFAULT '',
            objective text NULL,
            angle text NULL,
            status varchar(20) NOT NULL DEFAULT 'planned',
            post_id bigint unsigned NULL,
            image_id bigint unsigned NULL,
            social longtext NULL,
            review longtext NULL,
            feedback text NULL,
            rounds smallint unsigned NOT NULL DEFAULT 0,
            steps longtext NULL,
            error text NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY campaign (campaign_id, seq),
            KEY due (status, publish_at)
        ) $c;");

        dbDelta("CREATE TABLE " . self::table('accounts') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            platform varchar(32) NOT NULL,
            label varchar(191) NOT NULL DEFAULT '',
            external_id varchar(191) NOT NULL DEFAULT '',
            credentials longtext NOT NULL,
            meta longtext NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            status_note text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY platform (platform)
        ) $c;");

        dbDelta("CREATE TABLE " . self::table('jobs') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            type varchar(32) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            input longtext NOT NULL,
            output longtext NULL,
            error text NULL,
            run_after datetime NOT NULL,
            attempts smallint NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY due (status, run_after)
        ) $c;");

        dbDelta("CREATE TABLE " . self::table('log') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            level varchar(10) NOT NULL,
            channel varchar(32) NOT NULL,
            message text NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at)
        ) $c;");

        // Tables from the 1.0 prototype that the campaign flow replaced.
        $wpdb->query('DROP TABLE IF EXISTS ' . self::table('social'));

        update_option('age_db_version', self::DB_VERSION, false);
    }
}

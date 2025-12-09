<?php
/**
 * Handles plugin installation tasks like database tables and cron schedules.
 *
 * @package VersaAISEOEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Versa_AI_Installer {
    /**
     * Run on plugin activation.
     */
    public static function activate(): void {
        self::create_tables();
        self::schedule_events();
    }

    /**
     * Run on plugin deactivation.
     */
    public static function deactivate(): void {
        self::clear_scheduled_events();
    }

    /**
     * Create or update required custom tables.
     */
    private static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $queue_table     = $wpdb->prefix . 'versa_ai_content_queue';
        $tasks_table     = $wpdb->prefix . 'versa_ai_seo_tasks';
        $logs_table      = $wpdb->prefix . 'versa_ai_logs';

        $queue_sql = "CREATE TABLE {$queue_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_title varchar(255) NOT NULL,
            target_keyword varchar(255) DEFAULT '' NOT NULL,
            outline_json longtext NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            scheduled_for_date date NULL,
            post_id bigint(20) unsigned NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY scheduled_for_date (scheduled_for_date)
        ) {$charset_collate};";

        $tasks_sql = "CREATE TABLE {$tasks_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            task_type varchar(50) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            payload longtext NULL,
            result longtext NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY post_id (post_id)
        ) {$charset_collate};";

        $logs_sql = "CREATE TABLE {$logs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            context varchar(50) NOT NULL,
            message text NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY context (context)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( [ $queue_sql, $tasks_sql, $logs_sql ] );
    }

    /**
     * Ensure cron events are scheduled.
     */
    private static function schedule_events(): void {
        if ( ! class_exists( 'Versa_AI_Cron' ) ) {
            require_once VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'cron/class-versa-ai-cron.php';
        }

        $cron = new Versa_AI_Cron();
        $cron->maybe_schedule_events();
    }

    /**
     * Clear scheduled cron events on deactivation.
     */
    private static function clear_scheduled_events(): void {
        $events = [
            'versa_ai_weekly_planner',
            'versa_ai_daily_writer',
            'versa_ai_daily_seo_scan',
            'versa_ai_seo_worker',
        ];

        foreach ( $events as $event ) {
            $timestamp = wp_next_scheduled( $event );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, $event );
            }
        }
    }
}

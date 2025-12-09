<?php
/**
 * Uninstall cleanup for Versa AI SEO Engine.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop custom tables.
$tables = [
    $wpdb->prefix . 'versa_ai_content_queue',
    $wpdb->prefix . 'versa_ai_seo_tasks',
    $wpdb->prefix . 'versa_ai_logs',
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

// Delete options.
$option_keys = [
    'versa_ai_business_profile',
    'versa_ai_service_urls',
];

foreach ( $option_keys as $option_key ) {
    delete_option( $option_key );
}

// Delete transients.
delete_transient( 'versa_ai_site_crawl_ran' );

// Delete plugin-specific post meta.
$meta_keys = [
    'versa_ai_faq_schema',
    '_versa_ai_seo_snapshot',
    '_versa_ai_content_backup',
];

foreach ( $meta_keys as $meta_key ) {
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

// Unschedule cron events if any remain.
$events = [
    'versa_ai_weekly_planner',
    'versa_ai_daily_writer',
    'versa_ai_daily_seo_scan',
    'versa_ai_seo_worker',
];

foreach ( $events as $event ) {
    while ( $timestamp = wp_next_scheduled( $event ) ) {
        wp_unschedule_event( $timestamp, $event );
    }
}

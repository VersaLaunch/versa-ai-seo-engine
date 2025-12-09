<?php
/**
 * Simple logger utility for Versa AI.
 *
 * @package VersaAISEOEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Versa_AI_Logger {
    /**
     * Write a message to error log and optionally to custom table if available.
     * When debug logging is disabled, this is a no-op unless explicitly forced.
     */
    public static function log( string $context, string $message, bool $force = false ): void {
        if ( ! $force && ! self::is_enabled() ) {
            return;
        }

        error_log( '[Versa AI SEO Engine][' . $context . '] ' . $message );

        global $wpdb;
        $table = $wpdb->prefix . 'versa_ai_logs';

        // Only attempt DB log if table exists.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            $wpdb->insert(
                $table,
                [
                    'context'    => sanitize_text_field( $context ),
                    'message'    => $message,
                    'created_at' => current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%s' ]
            );
        }
    }

    /**
     * Retrieve recent log rows from the custom table, newest first.
     *
     * @return array<int,array<string,string|int>>
     */
    public static function get_recent( int $limit = 100 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'versa_ai_logs';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return array();
        }

        $limit       = max( 1, min( $limit, 500 ) );
        $prepared_sql = $wpdb->prepare( 'SELECT id, context, message, created_at FROM ' . $table . ' ORDER BY id DESC LIMIT %d', $limit );

        return $wpdb->get_results( $prepared_sql, ARRAY_A );
    }

    /**
     * Determine if debug logging is enabled in settings.
     */
    private static function is_enabled(): bool {
        $settings = get_option( Versa_AI_Settings_Page::OPTION_KEY, array() );

        return ! empty( $settings['enable_debug_logging'] );
    }
}

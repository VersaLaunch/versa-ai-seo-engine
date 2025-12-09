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
     */
    public static function log( string $context, string $message ): void {
        error_log( '[Versa AI SEO Engine][' . $context . '] ' . $message );

        global $wpdb;
        $table = $wpdb->prefix . 'versa_ai_logs';

        // Only attempt DB log if table exists.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            $wpdb->insert(
                $table,
                [
                    'context' => sanitize_text_field( $context ),
                    'message' => $message,
                    'created_at' => current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%s' ]
            );
        }
    }
}

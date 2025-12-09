<?php
/**
 * Helper for interacting with SEO task table.
 *
 * @package VersaAISEOEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Versa_AI_SEO_Tasks {
    /**
     * Insert a new task.
     */
    public static function insert_task( int $post_id, string $task_type, array $payload = [], string $status = 'pending' ): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'versa_ai_seo_tasks';

        $inserted = $wpdb->insert(
            $table,
            [
                'post_id'    => $post_id,
                'task_type'  => sanitize_text_field( $task_type ),
                'status'     => sanitize_text_field( $status ),
                'payload'    => wp_json_encode( $payload ),
                'created_at' => current_time( 'mysql' ),
                'updated_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return null;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Fetch pending tasks.
     */
    public static function get_pending_tasks( int $limit = 5 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'versa_ai_seo_tasks';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at ASC LIMIT %d",
                'pending',
                $limit
            ),
            ARRAY_A
        );
    }

    /**
     * Fetch tasks by status (single or list).
     */
    public static function get_tasks_by_status( $status, int $limit = 50 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'versa_ai_seo_tasks';

        if ( is_array( $status ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $status ), '%s' ) );
            $query        = $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status IN ($placeholders) ORDER BY created_at DESC LIMIT %d",
                ...$status,
                $limit
            );
        } else {
            $query = $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d",
                $status,
                $limit
            );
        }

        return $wpdb->get_results( $query, ARRAY_A );
    }

    /**
     * Update task status and result.
     */
    public static function update_task( int $task_id, string $status, array $result = [] ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'versa_ai_seo_tasks';

        $wpdb->update(
            $table,
            [
                'status'     => sanitize_text_field( $status ),
                'result'     => $result ? wp_json_encode( $result ) : null,
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $task_id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Update only status (no result change).
     */
    public static function update_task_status_only( int $task_id, string $status ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'versa_ai_seo_tasks';

        $wpdb->update(
            $table,
            [
                'status'     => sanitize_text_field( $status ),
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $task_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }
}

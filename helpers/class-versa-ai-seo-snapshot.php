<?php
/**
 * Helper for storing and retrieving SEO snapshots.
 *
 * @package VersaAISEOEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Versa_AI_SEO_Snapshot {
    public const META_KEY = '_versa_ai_seo_snapshot';

    /**
     * Save snapshot array to post meta.
     */
    public static function save( int $post_id, array $snapshot ): void {
        update_post_meta( $post_id, self::META_KEY, wp_json_encode( $snapshot ) );
    }

    /**
     * Fetch snapshot array.
     */
    public static function get( int $post_id ): array {
        $raw = get_post_meta( $post_id, self::META_KEY, true );
        if ( empty( $raw ) ) {
            return [];
        }

        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }
}

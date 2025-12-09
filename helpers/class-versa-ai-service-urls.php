<?php
/**
 * Helper for storing and retrieving known service URLs for internal linking.
 *
 * @package VersaAISEOEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Versa_AI_Service_URLs {
    public const OPTION_KEY = 'versa_ai_service_urls';

    /**
     * Get stored service URLs.
     */
    public static function get_urls(): array {
        $urls = get_option( self::OPTION_KEY, [] );
        return is_array( $urls ) ? array_filter( array_map( 'esc_url_raw', $urls ) ) : [];
    }

    /**
     * Save service URLs array.
     */
    public static function save_urls( array $urls ): void {
        $clean = array_filter( array_map( 'esc_url_raw', $urls ) );
        update_option( self::OPTION_KEY, $clean );
    }
}

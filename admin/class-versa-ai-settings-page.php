<?php
/**
 * Settings page for Versa AI SEO Engine business profile.
 *
 * @package VersaAISEOEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Versa_AI_Settings_Page {
    public const OPTION_KEY = 'versa_ai_business_profile';
    public const MENU_SLUG  = 'versa-ai-seo-engine';

    /**
     * Register hooks.
     */
    public function __construct() {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    /**
     * Register the option and sanitization callback.
     */
    public function register_settings(): void {
        // Simple form: option group = MENU_SLUG, option name = OPTION_KEY.
        register_setting(
            self::MENU_SLUG,
            self::OPTION_KEY,
            array( $this, 'sanitize_settings' )
        );
    }

    /**
     * Render the settings page.
     */
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $profile = $this->get_profile();

        // Prepare textarea text versions for list fields.
        $services_text  = implode( "\n", $profile['services'] );
        $locations_text = implode( "\n", $profile['locations'] );

        // View should use $profile, $services_text, $locations_text.
        include VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    /**
     * Retrieve option with defaults merged.
     */
    public function get_profile(): array {
        $defaults = array(
            'business_name'         => '',
            'services'              => array(),
            'locations'             => array(),
            'target_audience'       => '',
            'tone_of_voice'         => '',
            'posts_per_week'        => 1,
            'max_words_per_post'    => 1300,
            'auto_publish_posts'    => false,
            'require_task_approval' => false,
            'enable_debug_logging'  => false,
            'require_apply_after_edits' => false,
            'openai_api_key'        => '',
            'openai_model'          => 'gpt-4.1-mini',
            'crawl_limit'           => 120,
            'crawl_cooldown_hours'  => 4,
        );

        $stored = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        return wp_parse_args( $stored, $defaults );
    }

    /**
     * Sanitize settings before saving.
     */
    public function sanitize_settings( $input ): array {
        $input = is_array( $input ) ? $input : array();

        $stored_option = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $stored_option ) ) {
            $stored_option = array();
        }

        $services  = $this->split_lines( $input['services'] ?? '' );
        $locations = $this->split_lines( $input['locations'] ?? '' );

        $posts_per_week = isset( $input['posts_per_week'] ) ? (int) $input['posts_per_week'] : 0;
        $posts_per_week = max( 0, min( 7, $posts_per_week ) );

        $max_words = isset( $input['max_words_per_post'] ) ? (int) $input['max_words_per_post'] : 1300;
        $max_words = max( 300, min( 5000, $max_words ) );

        $crawl_limit = isset( $input['crawl_limit'] ) ? (int) $input['crawl_limit'] : 120;
        if ( $crawl_limit < 0 ) {
            $crawl_limit = 0; // 0 means unlimited crawl.
        }

        $crawl_cooldown_hours = isset( $input['crawl_cooldown_hours'] ) ? (int) $input['crawl_cooldown_hours'] : 4;
        $crawl_cooldown_hours = max( 1, min( 24, $crawl_cooldown_hours ) );

        $new_api_key = isset( $input['openai_api_key'] ) ? trim( $input['openai_api_key'] ) : '';
        if ( '' === $new_api_key && isset( $stored_option['openai_api_key'] ) ) {
            $new_api_key = $stored_option['openai_api_key']; // keep existing if left blank.
        }

        return array(
            'business_name'         => sanitize_text_field( isset( $input['business_name'] ) ? $input['business_name'] : '' ),
            'services'              => $services,
            'locations'             => $locations,
            'target_audience'       => sanitize_text_field( isset( $input['target_audience'] ) ? $input['target_audience'] : '' ),
            'tone_of_voice'         => sanitize_textarea_field( isset( $input['tone_of_voice'] ) ? $input['tone_of_voice'] : '' ),
            'posts_per_week'        => $posts_per_week,
            'max_words_per_post'    => $max_words,
            'auto_publish_posts'    => ! empty( $input['auto_publish_posts'] ),
            'require_task_approval' => ! empty( $input['require_task_approval'] ),
            'enable_debug_logging'  => ! empty( $input['enable_debug_logging'] ),
            'require_apply_after_edits' => ! empty( $input['require_apply_after_edits'] ),
            'openai_api_key'        => sanitize_text_field( $new_api_key ),
            'openai_model'          => sanitize_text_field( isset( $input['openai_model'] ) ? $input['openai_model'] : 'gpt-4.1-mini' ),
            'crawl_limit'           => $crawl_limit,
            'crawl_cooldown_hours'  => $crawl_cooldown_hours,
        );
    }

    /**
     * Split newline-separated strings into arrays.
     */
    private function split_lines( $text ): array {
        if ( is_array( $text ) ) {
            $lines = $text;
        } else {
            $lines = preg_split( '/[\r\n]+/', (string) $text );
            if ( ! is_array( $lines ) ) {
                $lines = array();
            }
        }

        $trimmed = array_filter( array_map( 'trim', $lines ) );
        return array_values( $trimmed );
    }
}

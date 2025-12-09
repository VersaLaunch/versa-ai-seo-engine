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
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    /**
     * Register the option and sanitization callback.
     */
    public function register_settings(): void {
        register_setting( self::MENU_SLUG, self::OPTION_KEY, [ $this, 'sanitize_settings' ] );
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

        include VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    /**
     * Retrieve option with defaults merged.
     */
    public function get_profile(): array {
        $defaults = [
            'business_name'       => '',
            'services'            => [],
            'locations'           => [],
            'target_audience'     => '',
            'tone_of_voice'       => '',
            'posts_per_week'      => 1,
            'max_words_per_post'  => 1300,
            'auto_publish_posts'  => false,
            'require_task_approval' => false,
            'openai_api_key'      => '',
            'openai_model'        => 'gpt-4.1-mini',
        ];

        $stored = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        return wp_parse_args( $stored, $defaults );
    }

    /**
     * Sanitize settings before saving.
     */
    public function sanitize_settings( $input ): array {
        $input = is_array( $input ) ? $input : [];

        $services  = $this->split_lines( $input['services'] ?? '' );
        $locations = $this->split_lines( $input['locations'] ?? '' );

        $posts_per_week = isset( $input['posts_per_week'] ) ? (int) $input['posts_per_week'] : 0;
        $posts_per_week = max( 0, min( 7, $posts_per_week ) );

        $max_words = isset( $input['max_words_per_post'] ) ? (int) $input['max_words_per_post'] : 1300;
        $max_words = max( 300, min( 5000, $max_words ) );

        return [
            'business_name'       => sanitize_text_field( $input['business_name'] ?? '' ),
            'services'            => $services,
            'locations'           => $locations,
            'target_audience'     => sanitize_text_field( $input['target_audience'] ?? '' ),
            'tone_of_voice'       => sanitize_textarea_field( $input['tone_of_voice'] ?? '' ),
            'posts_per_week'      => $posts_per_week,
            'max_words_per_post'  => $max_words,
            'auto_publish_posts'  => ! empty( $input['auto_publish_posts'] ),
            'require_task_approval' => ! empty( $input['require_task_approval'] ),
            'openai_api_key'      => sanitize_text_field( $input['openai_api_key'] ?? '' ),
            'openai_model'        => sanitize_text_field( $input['openai_model'] ?? 'gpt-4.1-mini' ),
        };
    }

    /**
     * Split newline-separated strings into arrays.
     */
    private function split_lines( string $text ): array {
        $lines = preg_split( '/[\r\n]+/', $text );
        $lines = is_array( $lines ) ? $lines : [];

        $trimmed = array_filter( array_map( 'trim', $lines ) );
        return array_values( $trimmed );
    }
}

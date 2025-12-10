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
            'business_address'      => '',
            'business_city'         => '',
            'business_state'        => '',
            'business_postcode'     => '',
            'business_country'      => '',
            'business_phone'        => '',
            'business_lat'          => '',
            'business_lng'          => '',
            'business_category'     => '',
            'same_as'               => array(),
            'opening_hours'         => '',
            'price_range'           => '',
            'payment_methods'       => array(),
            'currencies_accepted'   => array(),
            'contact_type'          => '',
            'contact_phone'         => '',
            'default_product_currency'   => 'USD',
            'default_product_availability' => 'InStock',
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
            'enable_faq_tasks'      => true,
            'faq_min_word_count'    => 600,
            'faq_allowed_post_types'=> array( 'post', 'page' ),
            'enable_article_schema'     => true,
            'enable_breadcrumb_schema'  => true,
            'enable_howto_schema'       => true,
            'enable_video_schema'       => true,
            'enable_product_schema'     => true,
            'enable_service_schema'     => true,
            'enable_event_schema'       => true,
            'enable_website_schema'     => true,
            'enable_org_schema'         => true,
            'enable_localbusiness_schema' => true,
            'schema_tasks_per_run'      => 12,
            'auto_create_service_pages' => false,
            'auto_service_post_type'    => 'page',
            'auto_service_auto_publish' => false,
            'auto_service_max_per_run'  => 3,
            'writer_include_images'     => false,
            'writer_image_count'        => 2,
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
        $same_as  = $this->split_lines( $input['same_as'] ?? '' );
        $payment_methods = $this->split_list( $input['payment_methods'] ?? '' );
        $currencies_accepted = $this->split_list( $input['currencies_accepted'] ?? '' );

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

        $faq_min_word_count = isset( $input['faq_min_word_count'] ) ? (int) $input['faq_min_word_count'] : 600;
        $faq_min_word_count = max( 0, min( 5000, $faq_min_word_count ) );

        $faq_allowed_post_types = $this->split_list( $input['faq_allowed_post_types'] ?? array( 'post', 'page' ) );
        if ( empty( $faq_allowed_post_types ) ) {
            $faq_allowed_post_types = array( 'post', 'page' );
        }

        $schema_tasks_per_run = isset( $input['schema_tasks_per_run'] ) ? (int) $input['schema_tasks_per_run'] : 12;
        $schema_tasks_per_run = max( 0, min( 50, $schema_tasks_per_run ) );

        $auto_service_post_type = isset( $input['auto_service_post_type'] ) ? sanitize_key( $input['auto_service_post_type'] ) : 'page';
        if ( empty( $auto_service_post_type ) ) {
            $auto_service_post_type = 'page';
        }

        $auto_service_max_per_run = isset( $input['auto_service_max_per_run'] ) ? (int) $input['auto_service_max_per_run'] : 3;
        $auto_service_max_per_run = max( 0, min( 20, $auto_service_max_per_run ) );

        $writer_image_count = isset( $input['writer_image_count'] ) ? (int) $input['writer_image_count'] : 2;
        $writer_image_count = max( 0, min( 6, $writer_image_count ) );

        $new_api_key = isset( $input['openai_api_key'] ) ? trim( $input['openai_api_key'] ) : '';
        if ( '' === $new_api_key && isset( $stored_option['openai_api_key'] ) ) {
            $new_api_key = $stored_option['openai_api_key']; // keep existing if left blank.
        }

        return array(
            'business_name'         => sanitize_text_field( isset( $input['business_name'] ) ? $input['business_name'] : '' ),
            'business_address'      => sanitize_text_field( $input['business_address'] ?? '' ),
            'business_city'         => sanitize_text_field( $input['business_city'] ?? '' ),
            'business_state'        => sanitize_text_field( $input['business_state'] ?? '' ),
            'business_postcode'     => sanitize_text_field( $input['business_postcode'] ?? '' ),
            'business_country'      => sanitize_text_field( $input['business_country'] ?? '' ),
            'business_phone'        => sanitize_text_field( $input['business_phone'] ?? '' ),
            'business_lat'          => sanitize_text_field( $input['business_lat'] ?? '' ),
            'business_lng'          => sanitize_text_field( $input['business_lng'] ?? '' ),
            'business_category'     => sanitize_text_field( $input['business_category'] ?? '' ),
            'same_as'               => array_map( 'esc_url_raw', $same_as ),
            'opening_hours'         => sanitize_textarea_field( $input['opening_hours'] ?? '' ),
            'price_range'           => sanitize_text_field( $input['price_range'] ?? '' ),
            'payment_methods'       => $payment_methods,
            'currencies_accepted'   => $currencies_accepted,
            'contact_type'          => sanitize_text_field( $input['contact_type'] ?? '' ),
            'contact_phone'         => sanitize_text_field( $input['contact_phone'] ?? '' ),
            'default_product_currency'   => sanitize_text_field( $input['default_product_currency'] ?? 'USD' ),
            'default_product_availability' => sanitize_text_field( $input['default_product_availability'] ?? 'InStock' ),
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
            'enable_faq_tasks'      => ! empty( $input['enable_faq_tasks'] ),
            'faq_min_word_count'    => $faq_min_word_count,
            'faq_allowed_post_types'=> $faq_allowed_post_types,
            'enable_article_schema'     => ! empty( $input['enable_article_schema'] ),
            'enable_breadcrumb_schema'  => ! empty( $input['enable_breadcrumb_schema'] ),
            'enable_howto_schema'       => ! empty( $input['enable_howto_schema'] ),
            'enable_video_schema'       => ! empty( $input['enable_video_schema'] ),
            'enable_product_schema'     => ! empty( $input['enable_product_schema'] ),
            'enable_service_schema'     => ! empty( $input['enable_service_schema'] ),
            'enable_event_schema'       => ! empty( $input['enable_event_schema'] ),
            'enable_website_schema'     => ! empty( $input['enable_website_schema'] ),
            'enable_org_schema'         => ! empty( $input['enable_org_schema'] ),
            'enable_localbusiness_schema' => ! empty( $input['enable_localbusiness_schema'] ),
            'schema_tasks_per_run'      => $schema_tasks_per_run,
            'auto_create_service_pages' => ! empty( $input['auto_create_service_pages'] ),
            'auto_service_post_type'    => $auto_service_post_type,
            'auto_service_auto_publish' => ! empty( $input['auto_service_auto_publish'] ),
            'auto_service_max_per_run'  => $auto_service_max_per_run,
            'writer_include_images'     => ! empty( $input['writer_include_images'] ),
            'writer_image_count'        => $writer_image_count,
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

    private function split_list( $text ): array {
        if ( is_array( $text ) ) {
            $parts = $text;
        } else {
            $parts = preg_split( '/[,\r\n]+/', (string) $text );
            if ( ! is_array( $parts ) ) {
                $parts = array();
            }
        }

        $trimmed = array_filter( array_map( 'trim', $parts ) );
        return array_values( $trimmed );
    }
}

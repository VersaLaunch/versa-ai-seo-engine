<?php
/**
 * Debug page for Versa AI SEO Engine.
 *
 * @package VersaAISEOEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Versa_AI_Debug_Page {
    public const MENU_SLUG = 'versa-ai-debug';

    /**
     * Register hooks.
     */
    public function __construct() {
        add_action( 'admin_init', [ $this, 'register_page' ] );
    }

    /**
     * Placeholder for settings API compliance; no-op but keeps structure consistent.
     */
    public function register_page(): void {
        // No settings sections required; page renders directly.
    }

    /**
     * Render the debug screen showing recent logs and basic info.
     */
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $logs     = Versa_AI_Logger::get_recent( 100 );
        $settings = get_option( Versa_AI_Settings_Page::OPTION_KEY, array() );

        include VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'admin/views/debug-page.php';
    }
}

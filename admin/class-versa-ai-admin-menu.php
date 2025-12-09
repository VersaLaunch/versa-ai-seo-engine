<?php
/**
 * Admin menu registration for Versa AI SEO Engine.
 *
 * @package VersaAISEOEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Versa_AI_Admin_Menu {
    /** @var Versa_AI_Settings_Page */
    private $settings_page;

    /** @var Versa_AI_Tasks_Page */
    private $tasks_page;

    /** @var Versa_AI_Debug_Page */
    private $debug_page;

    /**
     * @param Versa_AI_Settings_Page $settings_page Settings page renderer.
     * @param Versa_AI_Tasks_Page    $tasks_page    Tasks page renderer.
     * @param Versa_AI_Debug_Page    $debug_page    Debug page renderer.
     */
    public function __construct( Versa_AI_Settings_Page $settings_page, Versa_AI_Tasks_Page $tasks_page, Versa_AI_Debug_Page $debug_page ) {
        $this->settings_page = $settings_page;
        $this->tasks_page    = $tasks_page;
        $this->debug_page    = $debug_page;

        add_action( 'admin_menu', [ $this, 'register_menu' ] );
    }

    /**
     * Register top-level Versa AI menu.
     */
    public function register_menu(): void {
        add_menu_page(
            __( 'Versa AI', 'versa-ai-seo-engine' ),
            __( 'Versa AI', 'versa-ai-seo-engine' ),
            'manage_options',
            Versa_AI_Settings_Page::MENU_SLUG,
            [ $this->settings_page, 'render' ],
            'dashicons-analytics',
            65
        );

        add_submenu_page(
            Versa_AI_Settings_Page::MENU_SLUG,
            __( 'Tasks', 'versa-ai-seo-engine' ),
            __( 'Tasks', 'versa-ai-seo-engine' ),
            'manage_options',
            Versa_AI_Tasks_Page::MENU_SLUG,
            [ $this->tasks_page, 'render' ]
        );

        add_submenu_page(
            Versa_AI_Settings_Page::MENU_SLUG,
            __( 'Debug Log', 'versa-ai-seo-engine' ),
            __( 'Debug Log', 'versa-ai-seo-engine' ),
            'manage_options',
            Versa_AI_Debug_Page::MENU_SLUG,
            [ $this->debug_page, 'render' ]
        );
    }
}

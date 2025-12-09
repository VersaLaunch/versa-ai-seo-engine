<?php
/**
 * Plugin Name: Versa AI SEO Engine
 * Description: AI-powered SEO assistant for automated planning, writing, and optimizing WordPress content.
 * Version: 0.1.0
 * Author: Versa AI
 * License: GPL-2.0-or-later
 * Text Domain: versa-ai-seo-engine
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'VERSA_AI_SEO_ENGINE_VERSION', '0.1.0' );
define( 'VERSA_AI_SEO_ENGINE_PLUGIN_FILE', __FILE__ );
define( 'VERSA_AI_SEO_ENGINE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VERSA_AI_SEO_ENGINE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( ! class_exists( 'Versa_AI_SEO_Engine' ) ) {
    class Versa_AI_SEO_Engine {
        private static $instance = null;

        /** @var Versa_AI_Cron */
        private $cron;

        /** @var Versa_AI_Admin_Menu */
        private $admin_menu;

        /**
         * Get singleton instance.
         */
        public static function instance(): self {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            $this->load_dependencies();
            $this->init_components();
        }

        /**
         * Include required class files.
         */
        private function load_dependencies(): void {
            require_once VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'database/class-versa-ai-installer.php';
            require_once VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'helpers/class-versa-ai-logger.php';
            require_once VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'helpers/class-versa-ai-seo-tasks.php';
            require_once VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'helpers/class-versa-ai-seo-snapshot.php';
            require_once VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'helpers/class-versa-ai-service-urls.php';
            require_once VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'helpers/class-versa-ai-crawler.php';

            require_once VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'ai/class-versa-ai-openai-client.php';
            require_once VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'ai/class-versa-ai-planner.php';
            require_once VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'ai/class-versa-ai-writer.php';
            require_once VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'ai/class-versa-ai-optimizer.php';

            require_once VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'cron/class-versa-ai-cron.php';

            if ( is_admin() ) {
                require_once VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'admin/class-versa-ai-admin-menu.php';
                require_once VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'admin/class-versa-ai-settings-page.php';
                require_once VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'admin/class-versa-ai-tasks-page.php';
            }
        }

        /**
         * Bootstrap plugin pieces.
         */
        private function init_components(): void {
            $this->cron = new Versa_AI_Cron();

            if ( is_admin() ) {
                $settings_page = new Versa_AI_Settings_Page();
                $tasks_page    = new Versa_AI_Tasks_Page();
                $this->admin_menu = new Versa_AI_Admin_Menu( $settings_page, $tasks_page );
            }
        }
    }
}

/**
 * Initialize plugin after plugins are loaded.
 */
function versa_ai_seo_engine_bootstrap(): void {
    Versa_AI_SEO_Engine::instance();
}
add_action( 'plugins_loaded', 'versa_ai_seo_engine_bootstrap' );

// Activation / deactivation hooks.
register_activation_hook( __FILE__, [ 'Versa_AI_Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Versa_AI_Installer', 'deactivate' ] );

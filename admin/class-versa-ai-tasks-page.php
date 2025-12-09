<?php
/**
 * Admin tasks review/approval page.
 *
 * @package VersaAISEOEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Versa_AI_Tasks_Page {
    public const MENU_SLUG = 'versa-ai-tasks';

    public function __construct() {
        add_action( 'admin_post_versa_ai_approve_task', [ $this, 'handle_approve' ] );
        add_action( 'admin_post_versa_ai_decline_task', [ $this, 'handle_decline' ] );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $awaiting = Versa_AI_SEO_Tasks::get_tasks_by_status( 'awaiting_approval', 50 );
        $recent   = Versa_AI_SEO_Tasks::get_tasks_by_status( [ 'done', 'failed' ], 20 );

        include VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'admin/views/tasks-page.php';
    }

    public function handle_approve(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'versa-ai-seo-engine' ) );
        }
        $task_id = isset( $_GET['task_id'] ) ? (int) $_GET['task_id'] : 0;
        check_admin_referer( 'versa_ai_task_action_' . $task_id );
        if ( $task_id ) {
            Versa_AI_SEO_Tasks::update_task_status_only( $task_id, 'pending' );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
        exit;
    }

    public function handle_decline(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'versa-ai-seo-engine' ) );
        }
        $task_id = isset( $_GET['task_id'] ) ? (int) $_GET['task_id'] : 0;
        check_admin_referer( 'versa_ai_task_action_' . $task_id );
        if ( $task_id ) {
            Versa_AI_SEO_Tasks::update_task( $task_id, 'failed', [ 'message' => 'Declined by admin' ] );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
        exit;
    }
}

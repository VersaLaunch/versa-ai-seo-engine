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
        add_action( 'admin_post_versa_ai_bulk_tasks', [ $this, 'handle_bulk' ] );
        add_action( 'admin_post_versa_ai_apply_task', [ $this, 'handle_apply' ] );
        add_action( 'admin_post_versa_ai_discard_task', [ $this, 'handle_discard' ] );
        add_action( 'admin_post_versa_ai_run_cron', [ $this, 'handle_run_cron' ] );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $awaiting = Versa_AI_SEO_Tasks::get_tasks_by_status( 'awaiting_approval', 50 );
        $awaiting_apply = Versa_AI_SEO_Tasks::get_tasks_by_status( 'awaiting_apply', 50 );
        $recent   = Versa_AI_SEO_Tasks::get_tasks_by_status( [ 'done', 'failed' ], 20 );

        $cron_actions = [
            'versa_ai_weekly_planner'   => __( 'Run Weekly Planner', 'versa-ai-seo-engine' ),
            'versa_ai_daily_writer'     => __( 'Run Daily Writer', 'versa-ai-seo-engine' ),
            'versa_ai_daily_seo_scan'   => __( 'Run Daily SEO Scan', 'versa-ai-seo-engine' ),
            'versa_ai_seo_worker'       => __( 'Run SEO Worker', 'versa-ai-seo-engine' ),
        ];

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

    /**
     * Run a specific plugin cron hook immediately.
     */
    public function handle_run_cron(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'versa-ai-seo-engine' ) );
        }

        check_admin_referer( 'versa_ai_run_cron' );

        $hook = isset( $_POST['cron_hook'] ) ? sanitize_text_field( wp_unslash( $_POST['cron_hook'] ) ) : '';
        $allowed = [ 'versa_ai_weekly_planner', 'versa_ai_daily_writer', 'versa_ai_daily_seo_scan', 'versa_ai_seo_worker' ];

        if ( in_array( $hook, $allowed, true ) ) {
            do_action( $hook );
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

    /**
     * Handle bulk approve/decline requests.
     */
    public function handle_bulk(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'versa-ai-seo-engine' ) );
        }

        check_admin_referer( 'versa_ai_bulk_tasks' );

        $action   = isset( $_POST['bulk_action'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) : '';
        $task_ids = isset( $_POST['task_ids'] ) && is_array( $_POST['task_ids'] ) ? array_map( 'intval', $_POST['task_ids'] ) : [];

        if ( ! empty( $task_ids ) ) {
            if ( 'approve' === $action ) {
                Versa_AI_SEO_Tasks::bulk_update_tasks( $task_ids, 'pending' );
            } elseif ( 'decline' === $action ) {
                Versa_AI_SEO_Tasks::bulk_update_tasks( $task_ids, 'failed', [ 'message' => 'Declined by admin (bulk)' ] );
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
        exit;
    }

    /**
     * Apply stored AI changes to content/meta.
     */
    public function handle_apply(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'versa-ai-seo-engine' ) );
        }

        $task_id = isset( $_GET['task_id'] ) ? (int) $_GET['task_id'] : 0;
        check_admin_referer( 'versa_ai_task_apply_' . $task_id );

        $task = Versa_AI_SEO_Tasks::get_task( $task_id );
        if ( $task && 'awaiting_apply' === $task['status'] ) {
            $result = json_decode( $task['result'] ?? '', true ) ?: [];
            $apply  = $this->apply_task_changes( $task, $result );

            if ( $apply['success'] ) {
                Versa_AI_SEO_Tasks::update_task( $task_id, 'done', [ 'message' => $apply['message'] ] );
            } else {
                Versa_AI_SEO_Tasks::update_task( $task_id, 'failed', [ 'message' => $apply['message'] ] );
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
        exit;
    }

    /**
     * Discard stored AI changes.
     */
    public function handle_discard(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'versa-ai-seo-engine' ) );
        }

        $task_id = isset( $_GET['task_id'] ) ? (int) $_GET['task_id'] : 0;
        check_admin_referer( 'versa_ai_task_discard_' . $task_id );

        $task = Versa_AI_SEO_Tasks::get_task( $task_id );
        if ( $task && 'awaiting_apply' === $task['status'] ) {
            Versa_AI_SEO_Tasks::update_task( $task_id, 'failed', [ 'message' => 'Discarded by admin' ] );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
        exit;
    }

    /**
     * Apply task changes based on stored result payload.
     */
    private function apply_task_changes( array $task, array $result ): array {
        $post_id = (int) $task['post_id'];

        switch ( $task['task_type'] ) {
            case 'expand_content':
            case 'internal_linking':
                if ( empty( $result['content_html'] ) ) {
                    return [ 'success' => false, 'message' => __( 'No content_html to apply.', 'versa-ai-seo-engine' ) ];
                }
                $current = get_post_field( 'post_content', $post_id );
                update_post_meta( $post_id, '_versa_ai_content_backup', $current );
                wp_update_post( [ 'ID' => $post_id, 'post_content' => $result['content_html'] ] );
                return [ 'success' => true, 'message' => __( 'Content applied.', 'versa-ai-seo-engine' ) ];

            case 'write_snippet':
                $changed = false;
                if ( ! empty( $result['seo_title'] ) ) {
                    update_post_meta( $post_id, 'rank_math_title', wp_strip_all_tags( $result['seo_title'] ) );
                    update_post_meta( $post_id, '_yoast_wpseo_title', wp_strip_all_tags( $result['seo_title'] ) );
                    $changed = true;
                }
                if ( ! empty( $result['seo_description'] ) ) {
                    update_post_meta( $post_id, 'rank_math_description', wp_strip_all_tags( $result['seo_description'] ) );
                    update_post_meta( $post_id, '_yoast_wpseo_metadesc', wp_strip_all_tags( $result['seo_description'] ) );
                    $changed = true;
                }
                if ( ! $changed ) {
                    return [ 'success' => false, 'message' => __( 'No snippet fields to apply.', 'versa-ai-seo-engine' ) ];
                }
                return [ 'success' => true, 'message' => __( 'SEO snippet applied.', 'versa-ai-seo-engine' ) ];

            case 'faq_schema':
                if ( empty( $result['faq_schema_json'] ) ) {
                    return [ 'success' => false, 'message' => __( 'No FAQ schema to apply.', 'versa-ai-seo-engine' ) ];
                }
                update_post_meta( $post_id, 'versa_ai_faq_schema', wp_json_encode( $result['faq_schema_json'] ) );
                return [ 'success' => true, 'message' => __( 'FAQ schema applied.', 'versa-ai-seo-engine' ) ];

            case 'site_audit':
                // Convert site audit suggestions into concrete tasks for the mapped post.
                $profile          = get_option( Versa_AI_Settings_Page::OPTION_KEY, [] );
                $require_approval = ! empty( $profile['require_task_approval'] );
                $new_status       = $require_approval ? 'awaiting_approval' : 'pending';

                $issue = $task['task_type'];
                $payload_issue = $task['payload'] ?? '';

                $payload_decoded = json_decode( $payload_issue, true );
                $issue_type      = $payload_decoded['issue'] ?? '';

                if ( 'missing_meta_description' === $issue_type || 'missing_title' === $issue_type ) {
                    Versa_AI_SEO_Tasks::insert_task( $post_id, 'write_snippet', [], $new_status );
                    return [ 'success' => true, 'message' => __( 'Created snippet task from site audit.', 'versa-ai-seo-engine' ) ];
                }

                if ( 'thin_content' === $issue_type ) {
                    Versa_AI_SEO_Tasks::insert_task( $post_id, 'expand_content', [], $new_status );
                    return [ 'success' => true, 'message' => __( 'Created expand content task from site audit.', 'versa-ai-seo-engine' ) ];
                }

                return [ 'success' => false, 'message' => __( 'Nothing to apply for this site audit item.', 'versa-ai-seo-engine' ) ];

            default:
                return [ 'success' => false, 'message' => __( 'Nothing to apply for this task type.', 'versa-ai-seo-engine' ) ];
        }
    }
}

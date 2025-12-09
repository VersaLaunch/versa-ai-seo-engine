<?php
/**
 * Debug page view.
 *
 * @var array $logs     Recent log rows.
 * @var array $settings Current settings array.
 *
 * @package VersaAISEOEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Versa AI Debug Log', 'versa-ai-seo-engine' ); ?></h1>

    <?php if ( empty( $settings['enable_debug_logging'] ) ) : ?>
        <div class="notice notice-warning">
            <p><?php esc_html_e( 'Debug logging is currently disabled. Enable it in the Versa AI settings to capture events.', 'versa-ai-seo-engine' ); ?></p>
        </div>
    <?php endif; ?>

    <p class="description"><?php esc_html_e( 'Shows the most recent plugin log entries stored in the versa_ai_logs table.', 'versa-ai-seo-engine' ); ?></p>

    <table class="widefat fixed striped">
        <thead>
            <tr>
                <th scope="col">#</th>
                <th scope="col"><?php esc_html_e( 'Timestamp', 'versa-ai-seo-engine' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Context', 'versa-ai-seo-engine' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Message', 'versa-ai-seo-engine' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $logs ) ) : ?>
                <tr>
                    <td colspan="4"><?php esc_html_e( 'No log entries found.', 'versa-ai-seo-engine' ); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ( $logs as $log ) : ?>
                    <tr>
                        <td><?php echo esc_html( $log['id'] ); ?></td>
                        <td><?php echo esc_html( $log['created_at'] ); ?></td>
                        <td><?php echo esc_html( $log['context'] ); ?></td>
                        <td><code><?php echo esc_html( $log['message'] ); ?></code></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

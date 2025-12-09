<?php
/**
 * Tasks admin view.
 *
 * @var array $awaiting tasks waiting approval.
 * @var array $recent   recent done/failed tasks.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Versa AI Tasks', 'versa-ai-seo-engine' ); ?></h1>

    <h2><?php esc_html_e( 'Awaiting Approval', 'versa-ai-seo-engine' ); ?></h2>
    <?php if ( empty( $awaiting ) ) : ?>
        <p><?php esc_html_e( 'No tasks are awaiting approval.', 'versa-ai-seo-engine' ); ?></p>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ID', 'versa-ai-seo-engine' ); ?></th>
                    <th><?php esc_html_e( 'Post', 'versa-ai-seo-engine' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'versa-ai-seo-engine' ); ?></th>
                    <th><?php esc_html_e( 'Payload', 'versa-ai-seo-engine' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'versa-ai-seo-engine' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $awaiting as $task ) :
                    $approve_url = wp_nonce_url( admin_url( 'admin-post.php?action=versa_ai_approve_task&task_id=' . (int) $task['id'] ), 'versa_ai_task_action_' . (int) $task['id'] );
                    $decline_url = wp_nonce_url( admin_url( 'admin-post.php?action=versa_ai_decline_task&task_id=' . (int) $task['id'] ), 'versa_ai_task_action_' . (int) $task['id'] );
                    $post_link   = $task['post_id'] ? get_edit_post_link( (int) $task['post_id'] ) : '';
                    ?>
                    <tr>
                        <td><?php echo esc_html( $task['id'] ); ?></td>
                        <td>
                            <?php if ( $task['post_id'] && $post_link ) : ?>
                                <a href="<?php echo esc_url( $post_link ); ?>">#<?php echo esc_html( $task['post_id'] ); ?></a>
                            <?php else : ?>
                                <?php esc_html_e( 'Site-wide', 'versa-ai-seo-engine' ); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $task['task_type'] ); ?></td>
                        <td><code style="white-space:pre-wrap;"><?php echo esc_html( $task['payload'] ); ?></code></td>
                        <td>
                            <a class="button button-primary" href="<?php echo esc_url( $approve_url ); ?>"><?php esc_html_e( 'Approve', 'versa-ai-seo-engine' ); ?></a>
                            <a class="button" href="<?php echo esc_url( $decline_url ); ?>"><?php esc_html_e( 'Decline', 'versa-ai-seo-engine' ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2><?php esc_html_e( 'Recent Activity', 'versa-ai-seo-engine' ); ?></h2>
    <?php if ( empty( $recent ) ) : ?>
        <p><?php esc_html_e( 'No recent tasks.', 'versa-ai-seo-engine' ); ?></p>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ID', 'versa-ai-seo-engine' ); ?></th>
                    <th><?php esc_html_e( 'Post', 'versa-ai-seo-engine' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'versa-ai-seo-engine' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'versa-ai-seo-engine' ); ?></th>
                    <th><?php esc_html_e( 'Result', 'versa-ai-seo-engine' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $recent as $task ) :
                    $post_link = $task['post_id'] ? get_edit_post_link( (int) $task['post_id'] ) : '';
                    ?>
                    <tr>
                        <td><?php echo esc_html( $task['id'] ); ?></td>
                        <td>
                            <?php if ( $task['post_id'] && $post_link ) : ?>
                                <a href="<?php echo esc_url( $post_link ); ?>">#<?php echo esc_html( $task['post_id'] ); ?></a>
                            <?php else : ?>
                                <?php esc_html_e( 'Site-wide', 'versa-ai-seo-engine' ); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $task['task_type'] ); ?></td>
                        <td><?php echo esc_html( $task['status'] ); ?></td>
                        <td><code style="white-space:pre-wrap;"><?php echo esc_html( $task['result'] ); ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

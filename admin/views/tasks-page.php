<?php
/**
 * Tasks admin view.
 *
 * @var array $awaiting       tasks waiting approval.
 * @var array $recent         recent done/failed tasks.
 * @var array $awaiting_apply tasks waiting manual apply.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Versa AI Tasks', 'versa-ai-seo-engine' ); ?></h1>

    <?php if ( ! empty( $cron_actions ) ) : ?>
        <h2><?php esc_html_e( 'Run Now', 'versa-ai-seo-engine' ); ?></h2>
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px;">
            <?php foreach ( $cron_actions as $hook => $label ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
                    <input type="hidden" name="action" value="versa_ai_run_cron" />
                    <input type="hidden" name="cron_hook" value="<?php echo esc_attr( $hook ); ?>" />
                    <?php wp_nonce_field( 'versa_ai_run_cron' ); ?>
                    <button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2><?php esc_html_e( 'Awaiting Approval', 'versa-ai-seo-engine' ); ?></h2>
    <?php if ( empty( $awaiting ) ) : ?>
        <p><?php esc_html_e( 'No tasks are awaiting approval.', 'versa-ai-seo-engine' ); ?></p>
    <?php else : ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="versa_ai_bulk_tasks" />
            <?php wp_nonce_field( 'versa_ai_bulk_tasks' ); ?>

            <div style="margin: 0 0 12px;">
                <label for="versa_ai_bulk_action" class="screen-reader-text"><?php esc_html_e( 'Bulk action', 'versa-ai-seo-engine' ); ?></label>
                <select name="bulk_action" id="versa_ai_bulk_action">
                    <option value="">— <?php esc_html_e( 'Bulk actions', 'versa-ai-seo-engine' ); ?> —</option>
                    <option value="approve"><?php esc_html_e( 'Approve selected', 'versa-ai-seo-engine' ); ?></option>
                    <option value="decline"><?php esc_html_e( 'Decline selected', 'versa-ai-seo-engine' ); ?></option>
                </select>
                <button type="submit" class="button action"><?php esc_html_e( 'Apply', 'versa-ai-seo-engine' ); ?></button>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td class="manage-column column-cb check-column"><input type="checkbox" id="versa_ai_tasks_select_all" /></td>
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
                        $slug        = $task['post_id'] ? get_post_field( 'post_name', (int) $task['post_id'] ) : '';
                        ?>
                        <tr>
                            <th scope="row" class="check-column"><input type="checkbox" name="task_ids[]" value="<?php echo esc_attr( $task['id'] ); ?>" /></th>
                            <td><?php echo esc_html( $task['id'] ); ?></td>
                            <td>
                                <?php if ( $task['post_id'] && $post_link ) : ?>
                                    <a href="<?php echo esc_url( $post_link ); ?>">#<?php echo esc_html( $task['post_id'] ); ?></a><?php if ( $slug ) : ?> (<?php echo esc_html( $slug ); ?>)<?php endif; ?>
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
            <script>
                ( function () {
                    const toggle = document.getElementById('versa_ai_tasks_select_all');
                    if (!toggle) return;
                    toggle.addEventListener('change', function () {
                        const checkboxes = document.querySelectorAll('input[name="task_ids[]"]');
                        checkboxes.forEach(cb => cb.checked = toggle.checked);
                    });
                } )();
            </script>
        </form>
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
                    $slug      = $task['post_id'] ? get_post_field( 'post_name', (int) $task['post_id'] ) : '';
                    ?>
                    <tr>
                        <td><?php echo esc_html( $task['id'] ); ?></td>
                        <td>
                            <?php if ( $task['post_id'] && $post_link ) : ?>
                                <a href="<?php echo esc_url( $post_link ); ?>">#<?php echo esc_html( $task['post_id'] ); ?></a><?php if ( $slug ) : ?> (<?php echo esc_html( $slug ); ?>)<?php endif; ?>
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

    <h2><?php esc_html_e( 'Awaiting Apply', 'versa-ai-seo-engine' ); ?></h2>
    <?php if ( empty( $awaiting_apply ) ) : ?>
        <p><?php esc_html_e( 'No tasks are awaiting apply.', 'versa-ai-seo-engine' ); ?></p>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ID', 'versa-ai-seo-engine' ); ?></th>
                    <th><?php esc_html_e( 'Post', 'versa-ai-seo-engine' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'versa-ai-seo-engine' ); ?></th>
                    <th><?php esc_html_e( 'Details', 'versa-ai-seo-engine' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'versa-ai-seo-engine' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $awaiting_apply as $task ) :
                    $apply_url   = wp_nonce_url( admin_url( 'admin-post.php?action=versa_ai_apply_task&task_id=' . (int) $task['id'] ), 'versa_ai_task_apply_' . (int) $task['id'] );
                    $discard_url = wp_nonce_url( admin_url( 'admin-post.php?action=versa_ai_discard_task&task_id=' . (int) $task['id'] ), 'versa_ai_task_discard_' . (int) $task['id'] );
                    $post_link   = $task['post_id'] ? get_edit_post_link( (int) $task['post_id'] ) : '';
                    $slug        = $task['post_id'] ? get_post_field( 'post_name', (int) $task['post_id'] ) : '';
                    $result      = json_decode( $task['result'] ?? '', true ) ?: [];
                    $payload     = json_decode( $task['payload'] ?? '', true ) ?: [];
                    ?>
                    <tr>
                        <td><?php echo esc_html( $task['id'] ); ?></td>
                        <td>
                            <?php if ( $task['post_id'] && $post_link ) : ?>
                                <a href="<?php echo esc_url( $post_link ); ?>">#<?php echo esc_html( $task['post_id'] ); ?></a><?php if ( $slug ) : ?> (<?php echo esc_html( $slug ); ?>)<?php endif; ?>
                            <?php else : ?>
                                <?php esc_html_e( 'Site-wide', 'versa-ai-seo-engine' ); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $task['task_type'] ); ?></td>
                        <td>
                            <?php if ( 'site_audit' === $task['task_type'] ) : ?>
                                <div><strong><?php esc_html_e( 'Summary:', 'versa-ai-seo-engine' ); ?></strong> <?php echo esc_html( $payload['summary'] ?? '' ); ?></div>
                                <div><strong><?php esc_html_e( 'Recommended:', 'versa-ai-seo-engine' ); ?></strong> <?php echo esc_html( $payload['recommended_action'] ?? '' ); ?></div>
                                <div><strong><?php esc_html_e( 'URL:', 'versa-ai-seo-engine' ); ?></strong> <?php echo esc_html( $payload['url'] ?? '' ); ?></div>
                                <div><strong><?php esc_html_e( 'Post:', 'versa-ai-seo-engine' ); ?></strong> #<?php echo esc_html( $task['post_id'] ); ?><?php if ( ! empty( $payload['post_slug'] ) ) : ?> (<?php echo esc_html( $payload['post_slug'] ); ?>)<?php endif; ?></div>
                                <div><strong><?php esc_html_e( 'Words:', 'versa-ai-seo-engine' ); ?></strong> <?php echo esc_html( $payload['word_count'] ?? '' ); ?></div>
                            <?php else : ?>
                                <code style="white-space:pre-wrap;"><?php echo esc_html( $task['result'] ); ?></code>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="button button-primary" href="<?php echo esc_url( $apply_url ); ?>"><?php esc_html_e( 'Apply', 'versa-ai-seo-engine' ); ?></a>
                            <a class="button" href="<?php echo esc_url( $discard_url ); ?>"><?php esc_html_e( 'Discard', 'versa-ai-seo-engine' ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

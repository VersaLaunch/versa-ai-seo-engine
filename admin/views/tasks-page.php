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

$awaiting_by_type = isset( $awaiting_by_type ) && is_array( $awaiting_by_type ) ? $awaiting_by_type : [];
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

    <div style="display:flex; align-items:center; gap:12px; margin:12px 0 18px; flex-wrap:wrap;">
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <button class="button versa-ai-tab-button" data-target="versa-ai-tab-awaiting" aria-pressed="true"><?php esc_html_e( 'Awaiting Approval', 'versa-ai-seo-engine' ); ?></button>
            <button class="button versa-ai-tab-button" data-target="versa-ai-tab-awaiting-apply" aria-pressed="false"><?php esc_html_e( 'Awaiting Apply', 'versa-ai-seo-engine' ); ?></button>
            <button class="button versa-ai-tab-button" data-target="versa-ai-tab-recent" aria-pressed="false"><?php esc_html_e( 'Recent Activity', 'versa-ai-seo-engine' ); ?></button>
        </div>
        <label for="versa_ai_task_filter" style="display:flex; align-items:center; gap:6px;">
            <span class="screen-reader-text"><?php esc_html_e( 'Filter tasks', 'versa-ai-seo-engine' ); ?></span>
            <input id="versa_ai_task_filter" type="search" placeholder="<?php esc_attr_e( 'Filter by post, type, summary...', 'versa-ai-seo-engine' ); ?>" style="min-width:220px;" />
        </label>
    </div>

    <div id="versa-ai-tab-awaiting" class="versa-ai-tab-panel" aria-hidden="false">
    <h2 style="margin-top:0;"><?php esc_html_e( 'Awaiting Approval', 'versa-ai-seo-engine' ); ?></h2>
    <?php if ( empty( $awaiting ) ) : ?>
        <p><?php esc_html_e( 'No tasks are awaiting approval.', 'versa-ai-seo-engine' ); ?></p>
    <?php else : ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="versa_ai_bulk_tasks" />
            <?php wp_nonce_field( 'versa_ai_bulk_tasks' ); ?>

            <div style="margin: 0 0 12px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <label for="versa_ai_bulk_action" class="screen-reader-text"><?php esc_html_e( 'Bulk action', 'versa-ai-seo-engine' ); ?></label>
                <select name="bulk_action" id="versa_ai_bulk_action">
                    <option value="">— <?php esc_html_e( 'Bulk actions', 'versa-ai-seo-engine' ); ?> —</option>
                    <option value="approve"><?php esc_html_e( 'Approve selected', 'versa-ai-seo-engine' ); ?></option>
                    <option value="decline"><?php esc_html_e( 'Decline selected', 'versa-ai-seo-engine' ); ?></option>
                </select>
                <button type="submit" class="button action"><?php esc_html_e( 'Apply', 'versa-ai-seo-engine' ); ?></button>
            </div>

            <?php if ( $awaiting_by_type ) : ?>
                <div class="versa-ai-task-groups-nav" style="margin:0 0 12px; display:flex; gap:12px; flex-wrap:wrap;">
                    <?php foreach ( $awaiting_by_type as $type => $tasks ) :
                        $anchor = 'task-group-' . sanitize_title( $type );
                        $label  = ucfirst( str_replace( '_', ' ', $type ) );
                        ?>
                        <a href="#<?php echo esc_attr( $anchor ); ?>" class="button button-secondary">
                            <?php echo esc_html( $label ); ?> (<?php echo count( $tasks ); ?>)
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php foreach ( $awaiting_by_type as $type => $tasks ) :
                $anchor = 'task-group-' . sanitize_title( $type );
                $label  = ucfirst( str_replace( '_', ' ', $type ) );
                ?>
                <div id="<?php echo esc_attr( $anchor ); ?>" class="versa-ai-task-group" style="margin-bottom:24px;">
                    <h3 style="margin:8px 0 6px; display:flex; align-items:center; gap:8px;">
                        <span><?php echo esc_html( $label ); ?></span>
                        <span class="versa-ai-badge"><?php echo count( $tasks ); ?></span>
                    </h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <td class="manage-column column-cb check-column"><input type="checkbox" class="versa_ai_tasks_select_group" /></td>
                                <th><?php esc_html_e( 'ID', 'versa-ai-seo-engine' ); ?></th>
                                <th><?php esc_html_e( 'Post', 'versa-ai-seo-engine' ); ?></th>
                                <th><?php esc_html_e( 'Priority', 'versa-ai-seo-engine' ); ?></th>
                                <th><?php esc_html_e( 'Details', 'versa-ai-seo-engine' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'versa-ai-seo-engine' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $tasks as $task ) :
                                $approve_url = wp_nonce_url( admin_url( 'admin-post.php?action=versa_ai_approve_task&task_id=' . (int) $task['id'] ), 'versa_ai_task_action_' . (int) $task['id'] );
                                $decline_url = wp_nonce_url( admin_url( 'admin-post.php?action=versa_ai_decline_task&task_id=' . (int) $task['id'] ), 'versa_ai_task_action_' . (int) $task['id'] );
                                $post_link   = $task['post_id'] ? get_edit_post_link( (int) $task['post_id'] ) : '';
                                $slug        = $task['post_id'] ? get_post_field( 'post_name', (int) $task['post_id'] ) : '';
                                $payload_decoded = json_decode( $task['payload'] ?? '', true );
                                $priority = is_array( $payload_decoded ) ? ( $payload_decoded['priority'] ?? '' ) : '';
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
                                    <td>
                                        <span class="versa-ai-priority versa-ai-priority-<?php echo esc_attr( strtolower( $priority ?: 'medium' ) ); ?>"><?php echo esc_html( $priority ?: __( 'medium', 'versa-ai-seo-engine' ) ); ?></span>
                                    </td>
                                    <td>
                                        <?php if ( is_array( $payload_decoded ) ) : ?>
                                            <?php if ( ! empty( $payload_decoded['summary'] ) ) : ?>
                                                <div><strong><?php esc_html_e( 'Summary:', 'versa-ai-seo-engine' ); ?></strong> <?php echo esc_html( $payload_decoded['summary'] ); ?></div>
                                            <?php endif; ?>
                                            <?php if ( ! empty( $payload_decoded['recommended_action'] ) ) : ?>
                                                <div><strong><?php esc_html_e( 'Why / Action:', 'versa-ai-seo-engine' ); ?></strong> <?php echo esc_html( $payload_decoded['recommended_action'] ); ?></div>
                                            <?php endif; ?>
                                            <?php if ( ! empty( $payload_decoded['warnings'] ) && is_array( $payload_decoded['warnings'] ) ) : ?>
                                                <div><strong><?php esc_html_e( 'Warnings:', 'versa-ai-seo-engine' ); ?></strong>
                                                    <ul style="margin:4px 0 0 18px; list-style:disc;">
                                                        <?php foreach ( $payload_decoded['warnings'] as $warn ) : ?>
                                                            <li><?php echo esc_html( $warn ); ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                            <details style="margin-top:6px;">
                                                <summary><?php esc_html_e( 'Raw payload', 'versa-ai-seo-engine' ); ?></summary>
                                                <code style="white-space:pre-wrap; display:block; margin-top:4px;"><?php echo esc_html( $task['payload'] ); ?></code>
                                            </details>
                                        <?php else : ?>
                                            <code style="white-space:pre-wrap; display:block; margin-top:4px;"><?php echo esc_html( $task['payload'] ); ?></code>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a class="button button-primary" href="<?php echo esc_url( $approve_url ); ?>"><?php esc_html_e( 'Approve', 'versa-ai-seo-engine' ); ?></a>
                                        <a class="button" href="<?php echo esc_url( $decline_url ); ?>"><?php esc_html_e( 'Decline', 'versa-ai-seo-engine' ); ?></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>

            <?php if ( count( $awaiting ) > 400 ) : ?>
                <p style="color:#9c6b00; margin-top:8px;"><?php esc_html_e( 'Showing up to 500 awaiting-approval tasks. Consider approving or declining to reduce the queue.', 'versa-ai-seo-engine' ); ?></p>
            <?php endif; ?>
        </form>
    <?php endif; ?>
    </div>

    <div id="versa-ai-tab-recent" class="versa-ai-tab-panel" aria-hidden="true" style="display:none;">
    <h2 style="margin-top:0;"><?php esc_html_e( 'Recent Activity', 'versa-ai-seo-engine' ); ?></h2>
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
                    $post_link     = $task['post_id'] ? get_edit_post_link( (int) $task['post_id'] ) : '';
                    $slug          = $task['post_id'] ? get_post_field( 'post_name', (int) $task['post_id'] ) : '';
                    $result_decoded = json_decode( $task['result'] ?? '', true );
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
                        <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $task['task_type'] ) ) ); ?></td>
                        <td><span class="versa-ai-status versa-ai-status-<?php echo esc_attr( strtolower( $task['status'] ) ); ?>"><?php echo esc_html( ucfirst( $task['status'] ) ); ?></span></td>
                        <td>
                            <?php if ( is_array( $result_decoded ) && ! empty( $result_decoded['summary'] ) ) : ?>
                                <div><strong><?php esc_html_e( 'Summary:', 'versa-ai-seo-engine' ); ?></strong> <?php echo esc_html( $result_decoded['summary'] ); ?></div>
                                <?php if ( ! empty( $result_decoded['warnings'] ) && is_array( $result_decoded['warnings'] ) ) : ?>
                                    <div><strong><?php esc_html_e( 'Warnings:', 'versa-ai-seo-engine' ); ?></strong>
                                        <ul style="margin:4px 0 0 18px; list-style:disc;">
                                            <?php foreach ( $result_decoded['warnings'] as $warn ) : ?>
                                                <li><?php echo esc_html( $warn ); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                                <details style="margin-top:6px;">
                                    <summary><?php esc_html_e( 'Raw result', 'versa-ai-seo-engine' ); ?></summary>
                                    <code style="white-space:pre-wrap; display:block; margin-top:4px;"><?php echo esc_html( $task['result'] ); ?></code>
                                </details>
                            <?php else : ?>
                                <code style="white-space:pre-wrap;"><?php echo esc_html( $task['result'] ); ?></code>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>

    <div id="versa-ai-tab-awaiting-apply" class="versa-ai-tab-panel" aria-hidden="true" style="display:none;">
    <h2 style="margin-top:0;"><?php esc_html_e( 'Awaiting Apply', 'versa-ai-seo-engine' ); ?></h2>
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
                                <?php if ( isset( $payload['status'] ) ) : ?><div><strong><?php esc_html_e( 'HTTP:', 'versa-ai-seo-engine' ); ?></strong> <?php echo esc_html( $payload['status'] ); ?></div><?php endif; ?>
                                <?php if ( ! empty( $payload['canonical'] ) ) : ?><div><strong><?php esc_html_e( 'Canonical:', 'versa-ai-seo-engine' ); ?></strong> <?php echo esc_html( $payload['canonical'] ); ?></div><?php endif; ?>
                                <?php if ( isset( $payload['meta_robots'] ) ) : ?><div><strong><?php esc_html_e( 'Robots:', 'versa-ai-seo-engine' ); ?></strong> <?php echo esc_html( $payload['meta_robots'] ); ?></div><?php endif; ?>
                                <?php if ( isset( $payload['has_h1'] ) ) : ?><div><strong><?php esc_html_e( 'H1 Present:', 'versa-ai-seo-engine' ); ?></strong> <?php echo $payload['has_h1'] ? esc_html__( 'Yes', 'versa-ai-seo-engine' ) : esc_html__( 'No', 'versa-ai-seo-engine' ); ?></div><?php endif; ?>
                                <?php if ( isset( $payload['status'] ) && (int) $payload['status'] >= 400 ) : ?><div style="color:#b32d2e;"><strong><?php esc_html_e( 'Warning:', 'versa-ai-seo-engine' ); ?></strong> <?php esc_html_e( 'Page returns an error response.', 'versa-ai-seo-engine' ); ?></div><?php endif; ?>
                                <?php if ( isset( $payload['noindex'] ) && $payload['noindex'] ) : ?><div style="color:#b32d2e;"><strong><?php esc_html_e( 'Warning:', 'versa-ai-seo-engine' ); ?></strong> <?php esc_html_e( 'Page is blocked by noindex.', 'versa-ai-seo-engine' ); ?></div><?php endif; ?>
                            <?php else : ?>
                                <?php if ( ! empty( $result['summary'] ) ) : ?>
                                    <div><strong><?php esc_html_e( 'Summary:', 'versa-ai-seo-engine' ); ?></strong> <?php echo esc_html( $result['summary'] ); ?></div>
                                <?php endif; ?>
                                <?php if ( ! empty( $result['warnings'] ) && is_array( $result['warnings'] ) ) : ?>
                                    <div><strong><?php esc_html_e( 'Warnings:', 'versa-ai-seo-engine' ); ?></strong>
                                        <ul style="margin:4px 0 0 18px; list-style:disc;">
                                            <?php foreach ( $result['warnings'] as $warn ) : ?>
                                                <li><?php echo esc_html( $warn ); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                                <details style="margin-top:6px;">
                                    <summary><?php esc_html_e( 'Raw result', 'versa-ai-seo-engine' ); ?></summary>
                                    <code style="white-space:pre-wrap; display:block; margin-top:4px;"><?php echo esc_html( $task['result'] ); ?></code>
                                </details>
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
</div>

<style>
.versa-ai-tab-button[aria-pressed="true"] { background:#2271b1; color:#fff; }
.versa-ai-tab-panel { margin-top:0; }
.versa-ai-priority { display:inline-block; padding:2px 8px; border-radius:12px; text-transform:capitalize; background:#f0f0f1; color:#444; }
.versa-ai-priority-high { background:#fde2e1; color:#a30000; }
.versa-ai-priority-medium { background:#fff4d8; color:#9c6b00; }
.versa-ai-priority-low { background:#e7f5ff; color:#0b5aa2; }
.versa-ai-status { display:inline-block; padding:2px 8px; border-radius:12px; text-transform:capitalize; background:#f0f0f1; color:#444; }
.versa-ai-status-done { background:#e7f7ef; color:#1b7f3b; }
.versa-ai-status-failed { background:#fde2e1; color:#a30000; }
.versa-ai-status-running { background:#e7f2ff; color:#0b5aa2; }
.versa-ai-status-awaiting_apply { background:#fff4d8; color:#9c6b00; }
.versa-ai-status-awaiting_approval { background:#fff4d8; color:#9c6b00; }
.versa-ai-status-pending { background:#f0f0f1; color:#444; }
.versa-ai-badge { display:inline-block; padding:2px 8px; border-radius:12px; background:#f0f0f1; color:#444; font-size:11px; }
</style>

<script>
( function () {
    const buttons = Array.from( document.querySelectorAll( '.versa-ai-tab-button' ) );
    const panels = Array.from( document.querySelectorAll( '.versa-ai-tab-panel' ) );
    const filter = document.getElementById( 'versa_ai_task_filter' );

    const showPanel = ( id ) => {
        panels.forEach( ( panel ) => {
            const active = panel.id === id;
            panel.style.display = active ? '' : 'none';
            panel.setAttribute( 'aria-hidden', active ? 'false' : 'true' );
        } );
        buttons.forEach( ( btn ) => {
            const active = btn.dataset.target === id;
            btn.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
        } );
    };

    buttons.forEach( ( btn ) => {
        btn.addEventListener( 'click', () => showPanel( btn.dataset.target ) );
    } );

    // Per-group select-all checkboxes
    document.querySelectorAll( '.versa_ai_tasks_select_group' ).forEach( ( toggle ) => {
        toggle.addEventListener( 'change', () => {
            const table = toggle.closest( 'table' );
            if ( ! table ) return;
            table.querySelectorAll( 'tbody input[name="task_ids[]"]' ).forEach( ( cb ) => {
                cb.checked = toggle.checked;
            } );
        } );
    } );

    const filterRows = () => {
        const term = ( filter?.value || '' ).toLowerCase();
        document.querySelectorAll( '.wp-list-table tbody tr' ).forEach( ( row ) => {
            const text = row.innerText.toLowerCase();
            row.style.display = text.includes( term ) ? '' : 'none';
        } );
    };

    if ( filter ) {
        filter.addEventListener( 'input', filterRows );
    }
} )();
</script>

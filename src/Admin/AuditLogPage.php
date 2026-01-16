<?php
/**
 * Audit Log Admin Page
 *
 * Displays audit trail and event logs for plugin operations.
 * Part of Phase 4: Polish & Observability.
 *
 * @package SBI\Admin
 * @since 1.0.36
 * 
 * Changelog:
 * - v1.0.36: Initial implementation
 */

namespace SBI\Admin;

use SBI\Services\AuditTrailService;
use SBI\Services\LoggingService;

/**
 * AuditLogPage class for rendering audit logs UI.
 */
class AuditLogPage {

    /**
     * AuditTrailService instance.
     */
    private AuditTrailService $audit_trail;

    /**
     * LoggingService instance.
     */
    private LoggingService $logger;

    /**
     * Constructor.
     */
    public function __construct( AuditTrailService $audit_trail, LoggingService $logger ) {
        $this->audit_trail = $audit_trail;
        $this->logger = $logger;
    }

    /**
     * Render the audit log page.
     */
    public function render(): void {
        if ( ! current_user_can( 'install_plugins' ) ) {
            wp_die( __( 'You do not have sufficient permissions to access this page.', 'kiss-smart-batch-installer' ) );
        }

        // Handle clear actions
        $this->handle_clear_actions();

        // Get filter parameters
        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'audit';
        $event_filter = isset( $_GET['event_type'] ) ? sanitize_text_field( $_GET['event_type'] ) : null;
        $level_filter = isset( $_GET['level'] ) ? sanitize_text_field( $_GET['level'] ) : null;

        $this->render_page_html( $active_tab, $event_filter, $level_filter );
    }

    /**
     * Handle clear log actions.
     */
    private function handle_clear_actions(): void {
        if ( ! isset( $_POST['sbi_clear_action'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'sbi_clear_logs' ) ) {
            return;
        }

        $action = sanitize_text_field( $_POST['sbi_clear_action'] );
        
        if ( $action === 'clear_audit' ) {
            $this->audit_trail->clear_audit_trail();
            add_settings_error( 'sbi_audit_messages', 'cleared', __( 'Audit trail cleared.', 'kiss-smart-batch-installer' ), 'updated' );
        } elseif ( $action === 'clear_logs' ) {
            $this->logger->clear_logs();
            add_settings_error( 'sbi_audit_messages', 'cleared', __( 'Event logs cleared.', 'kiss-smart-batch-installer' ), 'updated' );
        }
    }

    /**
     * Render the page HTML.
     */
    private function render_page_html( string $active_tab, ?string $event_filter, ?string $level_filter ): void {
        $audit_entries = $this->audit_trail->get_audit_trail( 100, $event_filter );
        $log_entries = $this->logger->get_logs( 100, $level_filter );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'SBI Audit Log', 'kiss-smart-batch-installer' ); ?></h1>
            
            <p>
                <a href="<?php echo esc_url( admin_url( 'plugins.php?page=kiss-smart-batch-installer' ) ); ?>" class="button">
                    <?php esc_html_e( '← Back to Repository Manager', 'kiss-smart-batch-installer' ); ?>
                </a>
            </p>

            <?php settings_errors( 'sbi_audit_messages' ); ?>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'audit' ) ); ?>" 
                   class="nav-tab <?php echo $active_tab === 'audit' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Audit Trail', 'kiss-smart-batch-installer' ); ?>
                    <span class="count">(<?php echo count( $audit_entries ); ?>)</span>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'logs' ) ); ?>" 
                   class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Event Logs', 'kiss-smart-batch-installer' ); ?>
                    <span class="count">(<?php echo count( $log_entries ); ?>)</span>
                </a>
            </nav>

            <div class="tab-content" style="background: #fff; border: 1px solid #c3c4c7; border-top: none; padding: 20px;">
                <?php if ( $active_tab === 'audit' ): ?>
                    <?php $this->render_audit_tab( $audit_entries, $event_filter ); ?>
                <?php else: ?>
                    <?php $this->render_logs_tab( $log_entries, $level_filter ); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the audit trail tab.
     */
    private function render_audit_tab( array $entries, ?string $event_filter ): void {
        ?>
        <div class="audit-controls" style="margin-bottom: 15px; display: flex; gap: 10px; align-items: center;">
            <form method="get" style="display: inline-flex; gap: 10px; align-items: center;">
                <input type="hidden" name="page" value="sbi-audit-log">
                <input type="hidden" name="tab" value="audit">
                <label for="event_type"><?php esc_html_e( 'Filter by event:', 'kiss-smart-batch-installer' ); ?></label>
                <select name="event_type" id="event_type" onchange="this.form.submit()">
                    <option value=""><?php esc_html_e( 'All Events', 'kiss-smart-batch-installer' ); ?></option>
                    <option value="install" <?php selected( $event_filter, 'install' ); ?>><?php esc_html_e( 'Install', 'kiss-smart-batch-installer' ); ?></option>
                    <option value="activate" <?php selected( $event_filter, 'activate' ); ?>><?php esc_html_e( 'Activate', 'kiss-smart-batch-installer' ); ?></option>
                    <option value="deactivate" <?php selected( $event_filter, 'deactivate' ); ?>><?php esc_html_e( 'Deactivate', 'kiss-smart-batch-installer' ); ?></option>
                    <option value="uninstall" <?php selected( $event_filter, 'uninstall' ); ?>><?php esc_html_e( 'Uninstall', 'kiss-smart-batch-installer' ); ?></option>
                </select>
            </form>
            
            <form method="post" style="margin-left: auto;">
                <?php wp_nonce_field( 'sbi_clear_logs' ); ?>
                <input type="hidden" name="sbi_clear_action" value="clear_audit">
                <button type="submit" class="button" onclick="return confirm('<?php esc_attr_e( 'Clear all audit entries?', 'kiss-smart-batch-installer' ); ?>');">
                    <?php esc_html_e( 'Clear Audit Trail', 'kiss-smart-batch-installer' ); ?>
                </button>
            </form>
        </div>
        <?php $this->render_audit_table( $entries ); ?>
        <?php
    }

    /**
     * Render audit trail table.
     */
    private function render_audit_table( array $entries ): void {
        if ( empty( $entries ) ) {
            echo '<p>' . esc_html__( 'No audit entries found.', 'kiss-smart-batch-installer' ) . '</p>';
            return;
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 140px;"><?php esc_html_e( 'Timestamp', 'kiss-smart-batch-installer' ); ?></th>
                    <th style="width: 90px;"><?php esc_html_e( 'Event', 'kiss-smart-batch-installer' ); ?></th>
                    <th style="width: 200px;"><?php esc_html_e( 'Repository', 'kiss-smart-batch-installer' ); ?></th>
                    <th style="width: 80px;"><?php esc_html_e( 'Result', 'kiss-smart-batch-installer' ); ?></th>
                    <th style="width: 100px;"><?php esc_html_e( 'User', 'kiss-smart-batch-installer' ); ?></th>
                    <th><?php esc_html_e( 'Details', 'kiss-smart-batch-installer' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $entries as $entry ): ?>
                <tr>
                    <td><code style="font-size: 11px;"><?php echo esc_html( $entry['timestamp'] ?? '' ); ?></code></td>
                    <td><?php echo $this->render_event_badge( $entry['event_type'] ?? '' ); ?></td>
                    <td><strong><?php echo esc_html( $entry['repository'] ?? '' ); ?></strong></td>
                    <td><?php echo $this->render_result_badge( $entry['result'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $entry['user_login'] ?? 'anonymous' ); ?></td>
                    <td><code style="font-size: 10px; word-break: break-all;"><?php echo esc_html( wp_json_encode( $entry['details'] ?? [] ) ); ?></code></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render event type badge.
     */
    private function render_event_badge( string $event_type ): string {
        $colors = [
            'install'    => '#0073aa',
            'activate'   => '#46b450',
            'deactivate' => '#ffb900',
            'uninstall'  => '#d63638',
            'update'     => '#826eb4',
            'state_change' => '#999',
        ];
        $color = $colors[ $event_type ] ?? '#999';
        return sprintf(
            '<span style="background: %s; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; text-transform: uppercase;">%s</span>',
            esc_attr( $color ),
            esc_html( $event_type )
        );
    }

    /**
     * Render result badge.
     */
    private function render_result_badge( string $result ): string {
        $styles = [
            'success' => 'background: #46b450; color: #fff;',
            'failure' => 'background: #d63638; color: #fff;',
            'pending' => 'background: #ffb900; color: #000;',
        ];
        $style = $styles[ $result ] ?? 'background: #999; color: #fff;';
        $icon = $result === 'success' ? '✓' : ( $result === 'failure' ? '✗' : '⏳' );
        return sprintf(
            '<span style="%s padding: 2px 8px; border-radius: 3px; font-size: 11px;">%s %s</span>',
            esc_attr( $style ),
            $icon,
            esc_html( ucfirst( $result ) )
        );
    }

    /**
     * Render the event logs tab.
     */
    private function render_logs_tab( array $entries, ?string $level_filter ): void {
        ?>
        <div class="logs-controls" style="margin-bottom: 15px; display: flex; gap: 10px; align-items: center;">
            <form method="get" style="display: inline-flex; gap: 10px; align-items: center;">
                <input type="hidden" name="page" value="sbi-audit-log">
                <input type="hidden" name="tab" value="logs">
                <label for="level"><?php esc_html_e( 'Filter by level:', 'kiss-smart-batch-installer' ); ?></label>
                <select name="level" id="level" onchange="this.form.submit()">
                    <option value=""><?php esc_html_e( 'All Levels', 'kiss-smart-batch-installer' ); ?></option>
                    <option value="debug" <?php selected( $level_filter, 'debug' ); ?>>Debug</option>
                    <option value="info" <?php selected( $level_filter, 'info' ); ?>>Info</option>
                    <option value="notice" <?php selected( $level_filter, 'notice' ); ?>>Notice</option>
                    <option value="warning" <?php selected( $level_filter, 'warning' ); ?>>Warning</option>
                    <option value="error" <?php selected( $level_filter, 'error' ); ?>>Error</option>
                    <option value="critical" <?php selected( $level_filter, 'critical' ); ?>>Critical</option>
                </select>
            </form>

            <form method="post" style="margin-left: auto;">
                <?php wp_nonce_field( 'sbi_clear_logs' ); ?>
                <input type="hidden" name="sbi_clear_action" value="clear_logs">
                <button type="submit" class="button" onclick="return confirm('<?php esc_attr_e( 'Clear all event logs?', 'kiss-smart-batch-installer' ); ?>');">
                    <?php esc_html_e( 'Clear Event Logs', 'kiss-smart-batch-installer' ); ?>
                </button>
            </form>
        </div>
        <?php $this->render_logs_table( $entries ); ?>
        <?php
    }

    /**
     * Render event logs table.
     */
    private function render_logs_table( array $entries ): void {
        if ( empty( $entries ) ) {
            echo '<p>' . esc_html__( 'No log entries found.', 'kiss-smart-batch-installer' ) . '</p>';
            return;
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 140px;"><?php esc_html_e( 'Timestamp', 'kiss-smart-batch-installer' ); ?></th>
                    <th style="width: 80px;"><?php esc_html_e( 'Level', 'kiss-smart-batch-installer' ); ?></th>
                    <th style="width: 250px;"><?php esc_html_e( 'Message', 'kiss-smart-batch-installer' ); ?></th>
                    <th><?php esc_html_e( 'Context', 'kiss-smart-batch-installer' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $entries as $entry ): ?>
                <tr>
                    <td><code style="font-size: 11px;"><?php echo esc_html( $entry['timestamp'] ?? '' ); ?></code></td>
                    <td><?php echo $this->render_level_badge( $entry['level'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $entry['message'] ?? '' ); ?></td>
                    <td><code style="font-size: 10px; word-break: break-all;"><?php echo esc_html( wp_json_encode( $entry['context'] ?? [] ) ); ?></code></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render log level badge.
     */
    private function render_level_badge( string $level ): string {
        $colors = [
            'debug'    => '#999',
            'info'     => '#0073aa',
            'notice'   => '#826eb4',
            'warning'  => '#ffb900',
            'error'    => '#d63638',
            'critical' => '#8b0000',
        ];
        $color = $colors[ $level ] ?? '#999';
        $text_color = in_array( $level, [ 'warning' ], true ) ? '#000' : '#fff';
        return sprintf(
            '<span style="background: %s; color: %s; padding: 2px 8px; border-radius: 3px; font-size: 11px; text-transform: uppercase;">%s</span>',
            esc_attr( $color ),
            esc_attr( $text_color ),
            esc_html( $level )
        );
    }
}

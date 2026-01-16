<?php
/**
 * Installation Audit Trail Service
 *
 * Logs all install/activate/deactivate/uninstall actions with user, timestamp, and result.
 * Part of Phase 4: Polish & Observability.
 *
 * @package SBI\Services
 * @since 1.0.35
 * 
 * Changelog:
 * - v1.0.35: Initial implementation
 */

namespace SBI\Services;

use SBI\Enums\PluginState;

/**
 * AuditTrailService for tracking plugin operations.
 */
class AuditTrailService {

    /**
     * Audit event types.
     */
    public const EVENT_INSTALL     = 'install';
    public const EVENT_ACTIVATE    = 'activate';
    public const EVENT_DEACTIVATE  = 'deactivate';
    public const EVENT_UNINSTALL   = 'uninstall';
    public const EVENT_UPDATE      = 'update';
    public const EVENT_STATE_CHANGE = 'state_change';

    /**
     * Result statuses.
     */
    public const RESULT_SUCCESS = 'success';
    public const RESULT_FAILURE = 'failure';
    public const RESULT_PENDING = 'pending';

    /**
     * Option key for audit log storage.
     */
    private const AUDIT_OPTION_KEY = 'sbi_audit_trail';

    /**
     * Maximum number of audit entries to keep.
     */
    private const MAX_AUDIT_ENTRIES = 1000;

    /**
     * LoggingService instance.
     */
    private LoggingService $logger;

    /**
     * Constructor.
     */
    public function __construct( LoggingService $logger ) {
        $this->logger = $logger;
    }

    /**
     * Record an audit event.
     *
     * @param string $event_type Event type (install, activate, etc.).
     * @param string $repository Repository identifier (owner/repo).
     * @param string $result Result status (success, failure, pending).
     * @param array $details Additional details about the event.
     * @return bool Success status.
     */
    public function record( string $event_type, string $repository, string $result, array $details = [] ): bool {
        $entry = $this->create_audit_entry( $event_type, $repository, $result, $details );
        
        // Also log to structured logger
        $log_level = $result === self::RESULT_FAILURE 
            ? LoggingService::LEVEL_ERROR 
            : LoggingService::LEVEL_INFO;
        
        $this->logger->log( $log_level, "Audit: {$event_type} {$repository}", [
            'event_type'  => $event_type,
            'repository'  => $repository,
            'result'      => $result,
            'details'     => $details,
        ] );

        return $this->store_audit_entry( $entry );
    }

    /**
     * Record installation event.
     */
    public function record_install( string $repository, string $result, array $details = [] ): bool {
        return $this->record( self::EVENT_INSTALL, $repository, $result, $details );
    }

    /**
     * Record activation event.
     */
    public function record_activate( string $repository, string $result, array $details = [] ): bool {
        return $this->record( self::EVENT_ACTIVATE, $repository, $result, $details );
    }

    /**
     * Record deactivation event.
     */
    public function record_deactivate( string $repository, string $result, array $details = [] ): bool {
        return $this->record( self::EVENT_DEACTIVATE, $repository, $result, $details );
    }

    /**
     * Record uninstallation event.
     */
    public function record_uninstall( string $repository, string $result, array $details = [] ): bool {
        return $this->record( self::EVENT_UNINSTALL, $repository, $result, $details );
    }

    /**
     * Record state change event.
     */
    public function record_state_change( string $repository, PluginState $from_state, PluginState $to_state, array $details = [] ): bool {
        return $this->record( self::EVENT_STATE_CHANGE, $repository, self::RESULT_SUCCESS, array_merge( $details, [
            'from_state' => $from_state->value,
            'to_state'   => $to_state->value,
        ] ) );
    }

    /**
     * Create an audit entry.
     */
    private function create_audit_entry( string $event_type, string $repository, string $result, array $details ): array {
        return [
            'id'              => wp_generate_uuid4(),
            'timestamp'       => current_time( 'mysql' ),
            'timestamp_utc'   => gmdate( 'Y-m-d H:i:s' ),
            'event_type'      => $event_type,
            'repository'      => $repository,
            'result'          => $result,
            'details'         => $details,
            'user_id'         => get_current_user_id(),
            'user_login'      => wp_get_current_user()->user_login ?? 'anonymous',
            'ip_address'      => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'plugin_version'  => defined( 'GBI_VERSION' ) ? GBI_VERSION : 'unknown',
        ];
    }

    /**
     * Store audit entry in WordPress options.
     */
    private function store_audit_entry( array $entry ): bool {
        $audit_log = get_option( self::AUDIT_OPTION_KEY, [] );
        array_unshift( $audit_log, $entry );
        
        if ( count( $audit_log ) > self::MAX_AUDIT_ENTRIES ) {
            $audit_log = array_slice( $audit_log, 0, self::MAX_AUDIT_ENTRIES );
        }
        
        return update_option( self::AUDIT_OPTION_KEY, $audit_log, false );
    }

    /**
     * Get audit trail entries.
     *
     * @param int $limit Number of entries.
     * @param string|null $event_type Filter by event type.
     * @param string|null $repository Filter by repository.
     * @return array Audit entries.
     */
    public function get_audit_trail( int $limit = 100, ?string $event_type = null, ?string $repository = null ): array {
        $audit_log = get_option( self::AUDIT_OPTION_KEY, [] );
        
        if ( $event_type !== null ) {
            $audit_log = array_filter( $audit_log, fn( $e ) => $e['event_type'] === $event_type );
        }
        
        if ( $repository !== null ) {
            $audit_log = array_filter( $audit_log, fn( $e ) => $e['repository'] === $repository );
        }
        
        return array_slice( array_values( $audit_log ), 0, $limit );
    }

    /**
     * Get audit trail for a specific repository.
     */
    public function get_repository_history( string $repository, int $limit = 50 ): array {
        return $this->get_audit_trail( $limit, null, $repository );
    }

    /**
     * Clear audit trail.
     */
    public function clear_audit_trail(): bool {
        return delete_option( self::AUDIT_OPTION_KEY );
    }
}


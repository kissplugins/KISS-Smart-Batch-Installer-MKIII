<?php
/**
 * Structured Event Logging Service
 *
 * Provides structured JSON logging with context (repo, state, user, timestamp) and log levels.
 * Part of Phase 4: Polish & Observability.
 *
 * @package SBI\Services
 * @since 1.0.35
 * 
 * Changelog:
 * - v1.0.35: Initial implementation
 */

namespace SBI\Services;

/**
 * LoggingService for structured event logging.
 */
class LoggingService {

    /**
     * Log levels (PSR-3 compatible).
     */
    public const LEVEL_DEBUG     = 'debug';
    public const LEVEL_INFO      = 'info';
    public const LEVEL_NOTICE    = 'notice';
    public const LEVEL_WARNING   = 'warning';
    public const LEVEL_ERROR     = 'error';
    public const LEVEL_CRITICAL  = 'critical';

    /**
     * Option key for log storage.
     */
    private const LOG_OPTION_KEY = 'sbi_event_log';

    /**
     * Maximum number of log entries to keep.
     */
    private const MAX_LOG_ENTRIES = 500;

    /**
     * Log an event with structured context.
     *
     * @param string $level Log level.
     * @param string $message Log message.
     * @param array $context Additional context data.
     * @return bool Success status.
     */
    public function log( string $level, string $message, array $context = [] ): bool {
        $entry = $this->create_log_entry( $level, $message, $context );
        
        // Write to WordPress debug log if enabled
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( '[SBI] ' . wp_json_encode( $entry, JSON_UNESCAPED_SLASHES ) );
        }

        // Store in transient-based log for UI display
        return $this->store_log_entry( $entry );
    }

    /**
     * Log debug message.
     */
    public function debug( string $message, array $context = [] ): bool {
        return $this->log( self::LEVEL_DEBUG, $message, $context );
    }

    /**
     * Log info message.
     */
    public function info( string $message, array $context = [] ): bool {
        return $this->log( self::LEVEL_INFO, $message, $context );
    }

    /**
     * Log notice message.
     */
    public function notice( string $message, array $context = [] ): bool {
        return $this->log( self::LEVEL_NOTICE, $message, $context );
    }

    /**
     * Log warning message.
     */
    public function warning( string $message, array $context = [] ): bool {
        return $this->log( self::LEVEL_WARNING, $message, $context );
    }

    /**
     * Log error message.
     */
    public function error( string $message, array $context = [] ): bool {
        return $this->log( self::LEVEL_ERROR, $message, $context );
    }

    /**
     * Log critical message.
     */
    public function critical( string $message, array $context = [] ): bool {
        return $this->log( self::LEVEL_CRITICAL, $message, $context );
    }

    /**
     * Create a structured log entry.
     *
     * @param string $level Log level.
     * @param string $message Log message.
     * @param array $context Additional context.
     * @return array Structured log entry.
     */
    private function create_log_entry( string $level, string $message, array $context ): array {
        return [
            'timestamp'   => current_time( 'mysql' ),
            'timestamp_utc' => gmdate( 'Y-m-d H:i:s' ),
            'level'       => $level,
            'message'     => $message,
            'context'     => array_merge( $context, [
                'user_id'     => get_current_user_id(),
                'user_login'  => wp_get_current_user()->user_login ?? 'anonymous',
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'cli',
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ] ),
            'plugin_version' => defined( 'GBI_VERSION' ) ? GBI_VERSION : 'unknown',
            'php_version'    => PHP_VERSION,
            'wp_version'     => get_bloginfo( 'version' ),
        ];
    }

    /**
     * Store log entry in WordPress options.
     *
     * @param array $entry Log entry.
     * @return bool Success status.
     */
    private function store_log_entry( array $entry ): bool {
        $logs = get_option( self::LOG_OPTION_KEY, [] );
        
        // Add new entry at the beginning
        array_unshift( $logs, $entry );
        
        // Trim to max entries
        if ( count( $logs ) > self::MAX_LOG_ENTRIES ) {
            $logs = array_slice( $logs, 0, self::MAX_LOG_ENTRIES );
        }
        
        return update_option( self::LOG_OPTION_KEY, $logs, false );
    }

    /**
     * Get recent log entries.
     *
     * @param int $limit Number of entries to retrieve.
     * @param string|null $level Filter by log level.
     * @return array Log entries.
     */
    public function get_logs( int $limit = 100, ?string $level = null ): array {
        $logs = get_option( self::LOG_OPTION_KEY, [] );
        
        if ( $level !== null ) {
            $logs = array_filter( $logs, fn( $entry ) => $entry['level'] === $level );
        }
        
        return array_slice( $logs, 0, $limit );
    }

    /**
     * Clear all log entries.
     *
     * @return bool Success status.
     */
    public function clear_logs(): bool {
        return delete_option( self::LOG_OPTION_KEY );
    }
}


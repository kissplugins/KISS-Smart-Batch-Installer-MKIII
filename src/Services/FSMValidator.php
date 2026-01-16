<?php
/**
 * FSM State Validator - Zod-style runtime validation for PHP
 *
 * Provides runtime validation to prevent FSM bypasses and ensure state integrity.
 *
 * @package SBI\Services
 * @version 1.0.54
 */

namespace SBI\Services;

use SBI\Enums\PluginState;

/**
 * ⚠️ FSM BYPASS DETECTION AND VALIDATION ⚠️
 *
 * This class provides Zod-style runtime validation for FSM operations.
 * It helps detect and prevent FSM bypasses during development.
 *
 * USAGE:
 * - Enable in development mode to catch FSM bypasses
 * - Validates state transitions
 * - Detects parallel state checks
 * - Logs FSM bypass attempts
 */
class FSMValidator {
    /**
     * Whether validation is enabled (development mode only)
     */
    private static bool $enabled = false;

    /**
     * Track FSM state reads for bypass detection
     */
    private static array $state_reads = [];

    /**
     * Track direct WordPress core calls (potential bypasses)
     */
    private static array $core_calls = [];

    /**
     * Enable FSM validation (development mode)
     */
    public static function enable(): void {
        self::$enabled = true;
        
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '🔍 FSM Validator enabled - Monitoring for FSM bypasses' );
        }
    }

    /**
     * Disable FSM validation (production mode)
     */
    public static function disable(): void {
        self::$enabled = false;
    }

    /**
     * Validate that a state is a valid PluginState enum
     *
     * @param mixed $state State to validate
     * @param string $context Context for error messages
     * @return bool True if valid
     * @throws \InvalidArgumentException If invalid and validation enabled
     */
    public static function validateState( $state, string $context = 'unknown' ): bool {
        if ( ! self::$enabled ) {
            return true;
        }

        if ( ! $state instanceof PluginState ) {
            $error = sprintf(
                '❌ FSM Validation Error [%s]: Expected PluginState enum, got %s',
                $context,
                gettype( $state )
            );
            
            error_log( $error );
            
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                throw new \InvalidArgumentException( $error );
            }
            
            return false;
        }

        return true;
    }

    /**
     * Track FSM state read (for bypass detection)
     *
     * @param string $repository Repository name
     * @param PluginState $state State being read
     * @param string $source Source of the read (class::method)
     */
    public static function trackStateRead( string $repository, PluginState $state, string $source ): void {
        if ( ! self::$enabled ) {
            return;
        }

        self::$state_reads[] = [
            'repository' => $repository,
            'state'      => $state->value,
            'source'     => $source,
            'timestamp'  => microtime( true ),
            'backtrace'  => debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 3 ),
        ];
    }

    /**
     * Detect potential FSM bypass (direct WordPress core call)
     *
     * Call this from code that should NOT be calling WordPress core directly.
     *
     * @param string $function Function being called (get_plugins, is_plugin_active, etc.)
     * @param string $caller Caller context
     */
    public static function detectBypass( string $function, string $caller ): void {
        if ( ! self::$enabled ) {
            return;
        }

        // Check if caller is StateManager (allowed to call WordPress core)
        if ( strpos( $caller, 'StateManager' ) !== false ) {
            return; // ✅ Allowed: StateManager can call WordPress core
        }

        // ❌ Potential bypass detected
        $warning = sprintf(
            '⚠️ POTENTIAL FSM BYPASS DETECTED: %s() called from %s (should use StateManager instead)',
            $function,
            $caller
        );

        error_log( $warning );

        self::$core_calls[] = [
            'function'  => $function,
            'caller'    => $caller,
            'timestamp' => microtime( true ),
            'backtrace' => debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 5 ),
        ];

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) {
            trigger_error( $warning, E_USER_WARNING );
        }
    }

    /**
     * Get validation report (for debugging)
     *
     * @return array Validation statistics and detected bypasses
     */
    public static function getReport(): array {
        return [
            'enabled'       => self::$enabled,
            'state_reads'   => count( self::$state_reads ),
            'core_calls'    => count( self::$core_calls ),
            'bypasses'      => self::$core_calls,
            'recent_reads'  => array_slice( self::$state_reads, -10 ),
        ];
    }
}


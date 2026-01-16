<?php
/**
 * ⚠️ ⚠️ ⚠️ CRITICAL FSM STATE ENUMERATION - DO NOT MODIFY ⚠️ ⚠️ ⚠️
 *
 * This enum defines ALL possible states in the Smart Batch Installer FSM.
 * These states are used throughout the entire system:
 * - Frontend TypeScript FSM
 * - Backend PHP StateManager
 * - Database storage
 * - SSE real-time updates
 * - UI state rendering
 *
 * BEFORE MODIFYING:
 * 1. Understand that changing these values will break existing data
 * 2. Consider database migration for stored states
 * 3. Update frontend TypeScript types to match
 * 4. Update all state transition logic
 * 5. Update UI rendering logic
 * 6. Run comprehensive testing
 *
 * CRITICAL WARNINGS:
 * - DO NOT change existing state values (breaks stored data)
 * - DO NOT remove states (breaks existing transitions)
 * - Adding states requires updating transition logic
 * - State names are used in CSS classes and data attributes
 *
 * @package SBI\Enums
 */

namespace SBI\Enums;

/**
 * ⚠️ CRITICAL FSM STATES - EACH STATE IS USED SYSTEM-WIDE ⚠️
 *
 * State Flow:
 * UNKNOWN → CHECKING → (AVAILABLE|NOT_PLUGIN|ERROR)
 * AVAILABLE → INSTALLED_INACTIVE → INSTALLED_ACTIVE
 * Any state → ERROR (for error conditions)
 * ERROR → CHECKING (for retry operations)
 */
enum PluginState: string {
    // ⚠️ DO NOT CHANGE THESE VALUES - STORED IN DATABASE ⚠️
    case UNKNOWN = 'unknown';            // Haven't checked yet - initial state
    case CHECKING = 'checking';          // Currently being analyzed - scanning state
    case AVAILABLE = 'available';        // Is a WP plugin, can install - ready for install
    case NOT_PLUGIN = 'not_plugin';      // Repository exists but not a WP plugin - final state
    case INSTALLED_INACTIVE = 'installed_inactive'; // Installed but not active - ready for activate
    case INSTALLED_ACTIVE = 'installed_active';     // Installed and active - final success state
    case ERROR = 'error';                // Error occurred during processing - error state with retry
}

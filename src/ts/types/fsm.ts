/**
 * ⚠️ ⚠️ ⚠️ CRITICAL FSM STATE ENUM - MUST MATCH PHP ENUM EXACTLY ⚠️ ⚠️ ⚠️
 *
 * This TypeScript enum MUST stay synchronized with the PHP PluginState enum.
 * Any mismatch will break frontend-backend state synchronization.
 *
 * CRITICAL REQUIREMENTS:
 * - Values must match src/Enums/PluginState.php exactly
 * - DO NOT change existing state values
 * - DO NOT remove states without updating PHP side
 * - Adding states requires updating both PHP and TypeScript
 *
 * BREAKING THIS WILL:
 * - Break frontend-backend state synchronization
 * - Break SSE real-time updates
 * - Cause UI state corruption
 * - Break state transition validation
 *
 * @see src/Enums/PluginState.php - PHP counterpart that MUST match
 */
export enum PluginState {
  // ⚠️ THESE VALUES MUST MATCH PHP ENUM EXACTLY ⚠️
  UNKNOWN = 'unknown',                    // Initial state - haven't checked yet
  CHECKING = 'checking',                  // Scanning/analyzing repository
  AVAILABLE = 'available',                // Valid WP plugin, ready to install
  NOT_PLUGIN = 'not_plugin',             // Repository exists but not a WP plugin
  INSTALLING = 'installing',              // Currently installing (frontend-only state)
  INSTALLED_INACTIVE = 'installed_inactive', // Plugin installed but not activated
  INSTALLED_ACTIVE = 'installed_active',     // Plugin installed and activated
  ERROR = 'error',                        // Error state with retry capability
}

/**
 * ⚠️ CRITICAL STATE UTILITY FUNCTIONS - USED THROUGHOUT UI ⚠️
 *
 * These functions are used for state-dependent UI logic.
 * Changing them affects button states, styling, and user interactions.
 */

// Check if plugin is installed (any installation state)
export const isInstalled = (s: PluginState) =>
  s === PluginState.INSTALLED_ACTIVE || s === PluginState.INSTALLED_INACTIVE;

// Check if repository contains a valid WordPress plugin
export const isPluginByState = (s: PluginState) =>
  s === PluginState.AVAILABLE || isInstalled(s);


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
export var PluginState;
(function (PluginState) {
    // ⚠️ THESE VALUES MUST MATCH PHP ENUM EXACTLY ⚠️
    PluginState["UNKNOWN"] = "unknown";
    PluginState["CHECKING"] = "checking";
    PluginState["AVAILABLE"] = "available";
    PluginState["NOT_PLUGIN"] = "not_plugin";
    PluginState["INSTALLING"] = "installing";
    PluginState["INSTALLED_INACTIVE"] = "installed_inactive";
    PluginState["INSTALLED_ACTIVE"] = "installed_active";
    PluginState["ERROR"] = "error";
})(PluginState || (PluginState = {}));
/**
 * ⚠️ CRITICAL STATE UTILITY FUNCTIONS - USED THROUGHOUT UI ⚠️
 *
 * These functions are used for state-dependent UI logic.
 * Changing them affects button states, styling, and user interactions.
 */
// Check if plugin is installed (any installation state)
export const isInstalled = (s) => s === PluginState.INSTALLED_ACTIVE || s === PluginState.INSTALLED_INACTIVE;
// Check if repository contains a valid WordPress plugin
export const isPluginByState = (s) => s === PluginState.AVAILABLE || isInstalled(s);

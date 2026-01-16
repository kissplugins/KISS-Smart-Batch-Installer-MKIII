/**
 * KISS Smart Batch Installer MKII - PQS Cache Integration
 *
 * ⚠️ ⚠️ ⚠️ FSM-FIRST PRINCIPLE: PQS IS READ-ONLY AND SEEDS FSM ONLY ⚠️ ⚠️ ⚠️
 *
 * CRITICAL: This integration provides READ-ONLY access to PQS cache.
 * It is used ONLY to SEED the FSM (repositoryFSM), NOT to provide parallel status checks.
 *
 * DO NOT BYPASS FSM:
 * - DO NOT use PQS cache to determine plugin status in UI
 * - DO NOT use PQS cache to make installation decisions
 * - DO NOT use PQS cache as a parallel source of truth
 * - USE repositoryFSM.get(repo) for ALL status checks
 *
 * CORRECT USAGE:
 * - ✅ Use PQS to seed FSM state on page load (performance optimization)
 * - ✅ Use PQS to detect when cache updates (trigger FSM refresh)
 * - ❌ DO NOT use PQS to display status (use FSM instead)
 * - ❌ DO NOT use PQS to check if plugin is installed (use FSM instead)
 *
 * WHY THIS MATTERS:
 * - PQS cache can be stale or out of sync
 * - FSM is the single source of truth for repository states
 * - Using PQS directly creates parallel state pipelines
 * - Parallel pipelines cause inconsistent status displays
 *
 * @package SBI
 * @version 1.0.54
 */

(function($) {
    'use strict';

    // Namespace for PQS integration
    window.SBI = window.SBI || {};
    window.SBI.PQS = window.SBI.PQS || {};

    /**
     * Check if PQS cache is available and fresh
     * @returns {boolean}
     */
    SBI.PQS.isCacheAvailable = function() {
        try {
            // Check if PQS global status function exists
            if (typeof window.pqsCacheStatus === 'function') {
                const status = window.pqsCacheStatus();
                return status === 'fresh';
            }

            // Fallback: check sessionStorage directly (PQS v1.2.2+ uses sessionStorage)
            const cacheKey = 'pqs_plugin_cache';
            const metaKey = 'pqs_plugin_cache_meta';
            
            const rawCache = sessionStorage.getItem(cacheKey);
            const rawMeta = sessionStorage.getItem(metaKey);
            
            if (!rawCache || !rawMeta) {
                return false;
            }

            // Check if cache is still valid (not expired)
            const meta = JSON.parse(rawMeta);
            const now = Date.now();
            const cacheDuration = 30 * 60 * 1000; // 30 minutes default
            const isExpired = (now - meta.timestamp) > cacheDuration;

            return !isExpired;
        } catch (error) {
            console.warn('SBI: Failed to check PQS cache availability:', error);
            return false;
        }
    };

    /**
     * Get plugin data from PQS cache
     * @returns {Array|null} Array of plugin objects or null if unavailable
     */
    SBI.PQS.getCachedPlugins = function() {
        try {
            // Try sessionStorage first (PQS v1.2.2+)
            const cacheKey = 'pqs_plugin_cache';
            const rawCache = sessionStorage.getItem(cacheKey);
            
            if (rawCache) {
                const plugins = JSON.parse(rawCache);
                if (Array.isArray(plugins) && plugins.length > 0) {
                    console.log('SBI: Loaded ' + plugins.length + ' plugins from PQS cache (sessionStorage)');
                    return plugins;
                }
            }

            // Fallback to localStorage (older PQS versions)
            const rawCacheLegacy = localStorage.getItem(cacheKey);
            if (rawCacheLegacy) {
                const plugins = JSON.parse(rawCacheLegacy);
                if (Array.isArray(plugins) && plugins.length > 0) {
                    console.log('SBI: Loaded ' + plugins.length + ' plugins from PQS cache (localStorage fallback)');
                    return plugins;
                }
            }

            return null;
        } catch (error) {
            console.warn('SBI: Failed to read PQS cache:', error);
            return null;
        }
    };

    /**
     * ⚠️ READ-ONLY: Find a plugin in PQS cache by folder name
     *
     * DO NOT USE FOR STATUS CHECKS - Use repositoryFSM.get(repo) instead
     *
     * This is for internal PQS operations only, not for determining plugin status.
     *
     * @param {string} folder - Plugin folder name (e.g., 'akismet')
     * @returns {Object|null} Plugin object or null if not found
     */
    SBI.PQS.findPluginByFolder = function(folder) {
        const plugins = SBI.PQS.getCachedPlugins();
        if (!plugins) {
            return null;
        }

        return plugins.find(function(plugin) {
            return plugin.folder === folder;
        }) || null;
    };

    /**
     * ⚠️ DEPRECATED: Check if a plugin is installed (exists in PQS cache)
     *
     * DO NOT USE THIS METHOD FOR STATUS CHECKS!
     * Use repositoryFSM.get(repo) instead to check installation status.
     *
     * This method bypasses the FSM and can return stale data.
     *
     * @deprecated 1.0.54 Use repositoryFSM.get(repo) instead
     * @param {string} folder - Plugin folder name
     * @returns {boolean}
     */
    SBI.PQS.isPluginInstalled = function(folder) {
        console.warn('⚠️ PQS.isPluginInstalled() is deprecated. Use repositoryFSM.get(repo) instead.');
        return SBI.PQS.findPluginByFolder(folder) !== null;
    };

    /**
     * ⚠️ DEPRECATED: Get plugin status from PQS cache
     *
     * DO NOT USE THIS METHOD FOR STATUS CHECKS!
     * Use repositoryFSM.get(repo) instead to check plugin status.
     *
     * This method bypasses the FSM and can return stale/inconsistent data.
     * PQS cache is for SEEDING FSM only, not for parallel status checks.
     *
     * @deprecated 1.0.54 Use repositoryFSM.get(repo) instead
     * @param {string} folder - Plugin folder name
     * @returns {Object|null} Status object with {installed, active, folder} or null
     */
    SBI.PQS.getPluginStatus = function(folder) {
        console.warn('⚠️ PQS.getPluginStatus() is deprecated. Use repositoryFSM.get(repo) instead.');
        const plugin = SBI.PQS.findPluginByFolder(folder);
        if (!plugin) {
            return {
                installed: false,
                active: false,
                folder: folder
            };
        }

        return {
            installed: true,
            active: plugin.active || false,
            folder: folder,
            name: plugin.name || '',
            version: plugin.version || '',
            description: plugin.description || ''
        };
    };

    /**
     * Listen for PQS cache updates
     */
    SBI.PQS.setupCacheListeners = function() {
        // Listen for cache rebuilds
        document.addEventListener('pqs-cache-rebuilt', function(event) {
            console.log('SBI: PQS cache rebuilt with ' + event.detail.pluginCount + ' plugins');
            
            // Trigger SBI refresh if needed
            if (typeof SBI.refreshPluginStatus === 'function') {
                SBI.refreshPluginStatus();
            }
        });

        // Listen for cache status changes
        document.addEventListener('pqs-cache-status-changed', function(event) {
            console.log('SBI: PQS cache status changed to: ' + event.detail.status);
        });

        console.log('SBI: PQS cache listeners registered');
    };

    /**
     * Initialize PQS integration
     */
    SBI.PQS.init = function() {
        console.log('SBI: Initializing PQS integration...');

        if (SBI.PQS.isCacheAvailable()) {
            console.log('SBI: PQS cache is available and fresh');
            SBI.PQS.setupCacheListeners();
        } else {
            console.log('SBI: PQS cache not available. Visit plugins page to build cache.');
            // Still set up listeners in case cache becomes available later
            SBI.PQS.setupCacheListeners();
        }
    };

    // Auto-initialize when DOM is ready
    $(document).ready(function() {
        SBI.PQS.init();
    });

})(jQuery);

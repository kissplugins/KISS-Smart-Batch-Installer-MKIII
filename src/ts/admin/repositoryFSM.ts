import { PluginState } from '../types/fsm';

export type RepoId = string; // owner/repo

type Listener = (repo: RepoId, state: PluginState) => void;

type StateMap = Map<RepoId, PluginState>;

// Error context for enhanced error handling
interface ErrorContext {
  timestamp: number;
  message: string;
  source: string;
  retryCount: number;
  lastRetryAt?: number;
  recoverable: boolean;
  // Enhanced fields from PHP backend
  type?: string;
  severity?: string;
  retry_delay?: number;
  guidance?: {
    title?: string;
    description?: string;
    actions?: string[];
    auto_retry?: boolean;
    retry_in?: number;
    links?: Record<string, string>;
    required_capability?: string;
  };
}

// Filter state for FSM-first repository filtering
interface FilterState {
  searchTerm: string;
  isActive: boolean;
  matchedRepositories: Set<RepoId>;
  appliedAt: number;
  sessionKey: string;
}

/**
 * ⚠️  CRITICAL FSM CORE CLASS - DO NOT REFACTOR WITHOUT CAREFUL CONSIDERATION ⚠️
 *
 * This is the heart of the Smart Batch Installer's state management system.
 * Any changes to this class can break the entire plugin functionality.
 *
 * BEFORE MODIFYING:
 * 1. Run Self Tests (Test Suite 3: State Management System)
 * 2. Test all state transitions manually
 * 3. Verify SSE integration still works
 * 4. Check error handling doesn't break
 * 5. Validate with multiple repositories
 *
 * PROTECTED AREAS:
 * - State storage and retrieval (states Map)
 * - Listener notification system
 * - SSE event handling
 * - Error context management
 * - State transition validation
 */
export class RepositoryFSM {
  // ⚠️ CORE STATE STORAGE - DO NOT MODIFY WITHOUT TESTING ⚠️
  // This Map stores the current state of each repository
  // Key: RepoId (owner/repo), Value: PluginState enum
  private states: StateMap = new Map();

  // ⚠️ LISTENER SYSTEM - CRITICAL FOR UI UPDATES ⚠️
  // These listeners notify the UI when states change
  // Breaking this breaks all UI state synchronization
  private listeners: Set<Listener> = new Set();

  // ⚠️ SSE CONNECTION - HANDLES REAL-TIME UPDATES ⚠️
  // EventSource for Server-Sent Events from backend
  // Modifying this can break real-time state updates
  private eventSource: EventSource | null = null;
  private sseEnabled = false;

  // ⚠️ ERROR HANDLING SYSTEM - ENHANCED IN v1.0.32 ⚠️
  // Stores error context for each repository
  // Contains enhanced error messages and recovery information
  private errorContexts: Map<RepoId, ErrorContext> = new Map();

  // ⚠️ RETRY CONFIGURATION - AFFECTS AUTO-RECOVERY ⚠️
  // These values control automatic error recovery behavior
  // Changes affect user experience during transient errors
  private maxRetries = 3;
  private retryDelayMs = 5000;

  // 🔍 FILTER STATE - FSM-FIRST REPOSITORY FILTERING ⚠️
  // Manages client-side filtering while maintaining FSM state integrity
  // All repositories maintain their FSM states regardless of filter visibility
  private filterState: FilterState = {
    searchTerm: '',
    isActive: false,
    matchedRepositories: new Set(),
    appliedAt: 0,
    sessionKey: 'sbi_repository_filter'
  };

  /**
   * ⚠️ CRITICAL LISTENER REGISTRATION - DO NOT MODIFY ⚠️
   *
   * This method is the foundation of the FSM's observer pattern.
   * UI components use this to receive state change notifications.
   *
   * BREAKING THIS WILL:
   * - Stop UI updates when states change
   * - Break real-time repository status display
   * - Cause stale UI states
   *
   * @param listener Function called when any repository state changes
   * @returns Cleanup function to remove the listener
   */
  onChange(listener: Listener): () => void {
    // ⚠️ DO NOT MODIFY - Core listener registration
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  /**
   * ⚠️ CORE STATE RETRIEVAL - FOUNDATION METHOD ⚠️
   *
   * This is the primary way to get a repository's current state.
   * Used throughout the codebase for state-dependent logic.
   *
   * BREAKING THIS WILL:
   * - Break all state-dependent UI rendering
   * - Cause incorrect button states (Install/Activate/etc.)
   * - Break bulk operations
   *
   * @param repo Repository identifier (owner/repo)
   * @returns Current state or undefined if not tracked
   */
  get(repo: RepoId): PluginState | undefined {
    // ⚠️ CORE RETRIEVAL: FSM is the single source of truth.
    // v1.0.68: If a repo has not yet been tracked (no SSE/refresh has populated it),
    // hydrate the missing state from the authoritative server-rendered DOM attribute
    // `data-repo-state` (set by RepositoryListTable). This keeps state interpretation
    // inside the FSM instead of ad-hoc fallbacks in UI code.
    const existing = this.states.get(repo);
    if (existing !== undefined) return existing;

    const hydrated = this.hydrateStateFromDom(repo);
    if (hydrated !== undefined) {
      // Cache without emitting listeners: hydration is an initial sync, not a transition.
      this.states.set(repo, hydrated);
      this.debugLog(`DOM hydration (missing state): ${repo} -> ${hydrated}`);
      return hydrated;
    }

    return undefined;
  }

  /**
   * v1.0.68: DOM hydration fallback used only when a repo has no tracked state.
   * Reads `data-repo-state` from the row and converts it via stringToPluginState.
   */
  private hydrateStateFromDom(repo: RepoId): PluginState | undefined {
    if (typeof document === 'undefined') return undefined;

    try {
      const safeRepo = this.escapeAttrForSelector(repo);
      const row = document.querySelector(`[data-repository="${safeRepo}"]`) as HTMLElement | null;
      if (!row) return undefined;

      const raw = row.getAttribute('data-repo-state') || '';
      if (!raw) return undefined;

      const state = this.stringToPluginState(raw);
      return state || undefined;
    } catch {
      return undefined;
    }
  }

  // Escape attribute values for use in a [data-foo="..."] CSS selector.
  private escapeAttrForSelector(value: string): string {
    return value.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  }

  /**
   * ⚠️ CRITICAL STATE SETTER - CORE FSM OPERATION ⚠️
   *
   * This method is the heart of the FSM. It updates state and notifies listeners.
   * Every state change in the system goes through this method.
   *
   * BREAKING THIS WILL:
   * - Stop all state updates
   * - Break UI synchronization
   * - Cause system-wide state corruption
   * - Break SSE integration
   *
   * @param repo Repository identifier
   * @param state New state to set
   */
  set(repo: RepoId, state: PluginState): void {
    // ⚠️ CRITICAL STATE UPDATE SEQUENCE - DO NOT MODIFY ORDER ⚠️

    // 1. Get previous state for debugging (safe to modify)
    const prev = this.states.get(repo);

    // 2. ⚠️ CORE STATE UPDATE - DO NOT MODIFY ⚠️
    // This is the fundamental state storage operation
    this.states.set(repo, state);

    // 3. Debug logging (safe to modify, but preserve functionality)
    // Always enable debug logging in development (browser environment)
    if (true) {
      try {
        // Preserve/improve debug output
        const msg = `[RepositoryFSM] ${repo}: ${prev ?? '∅'} -> ${state}`;
        if (typeof window !== 'undefined' && (window as any).sbiDebug) {
          (window as any).sbiDebug.addEntry('info', 'FSM Transition', msg);
        } else {
          // eslint-disable-next-line no-console
          console.log(msg);
        }
      } catch {}
    }

    // 4. ⚠️ CRITICAL LISTENER NOTIFICATION - DO NOT MODIFY ⚠️
    // This notifies all UI components of the state change
    // Breaking this breaks all UI synchronization
    this.listeners.forEach((fn) => fn(repo, state));
  }

  // Apply state to DOM row: minimal façade that can evolve later
  applyToRow(repo: RepoId, state: PluginState): void {
    // Use data-repository attribute for more resilient DOM targeting
    const row = document.querySelector(`[data-repository="${repo}"]`) as HTMLTableRowElement | null;
    if (!row) {
      this.debugLog(`Row not found for repository: ${repo}`, 'error');
      return;
    }

    // Enhanced UX with error handling and recovery options
    const isInstalled = state === PluginState.INSTALLED_ACTIVE || state === PluginState.INSTALLED_INACTIVE;
    const isError = state === PluginState.ERROR;

    const installBtn = row.querySelector('.sbi-install-plugin') as HTMLButtonElement | null;
    const activateBtn = row.querySelector('.sbi-activate-plugin') as HTMLButtonElement | null;
    const deactivateBtn = row.querySelector('.sbi-deactivate-plugin') as HTMLButtonElement | null;

    // Standard button state management
    if (installBtn) installBtn.disabled = isInstalled || isError;
    if (activateBtn) activateBtn.disabled = state !== PluginState.INSTALLED_INACTIVE;
    if (deactivateBtn) deactivateBtn.disabled = state !== PluginState.INSTALLED_ACTIVE;

    // Note: Self-protection is handled FSM-centrically by the backend StateManager
    // The backend renders protected buttons directly based on FSM metadata
    // Frontend respects the pre-rendered disabled state without additional logic

    // Enhanced error state handling
    if (isError) {
      this.handleErrorState(row, repo);
    } else {
      this.clearErrorDisplay(row);
    }
  }

  // ⚠️ ⚠️ ⚠️ CRITICAL SSE INTEGRATION METHODS - HANDLE WITH EXTREME CARE ⚠️ ⚠️ ⚠️

  // ┌─────────────────────────────────────────────────────────────────────────┐
  // │ SSE CONNECTION STATE TRACKING - v1.0.33                                 │
  // │ Tracks connection status for auto-reconnect with exponential backoff   │
  // └─────────────────────────────────────────────────────────────────────────┘
  private sseReconnectAttempts: number = 0;
  private sseMaxReconnectAttempts: number = 10;
  private sseReconnectTimer: ReturnType<typeof setTimeout> | null = null;
  private sseLastEventTime: number = 0;
  private sseHeartbeatTimer: ReturnType<typeof setInterval> | null = null;
  private sseConnectionStatus: 'disconnected' | 'connecting' | 'connected' = 'disconnected';
  private sseWindowRef: any = null;

  /**
   * ⚠️ CRITICAL SSE INITIALIZATION - DO NOT MODIFY WITHOUT EXTENSIVE TESTING ⚠️
   *
   * This method establishes the real-time connection between frontend and backend.
   * It enables live state updates without page refreshes.
   *
   * BREAKING THIS WILL:
   * - Stop all real-time state updates
   * - Force users to manually refresh to see changes
   * - Break bulk operation progress tracking
   * - Cause state synchronization issues
   *
   * TESTING REQUIREMENTS BEFORE CHANGES:
   * 1. Test SSE connection establishment
   * 2. Test state_changed event handling
   * 3. Test error recovery and reconnection
   * 4. Test with multiple browser tabs
   * 5. Test with network interruptions
   *
   * @since 1.0.33 - Added auto-reconnect with exponential backoff, heartbeat monitoring
   * @param windowObj Window object containing sbiAjax configuration
   */
  initSSE(windowObj: Window): void {
    const w = windowObj as any;
    if (!w.sbiAjax) return;

    // Store window reference for reconnection
    this.sseWindowRef = w;

    // Check if SSE is enabled
    this.sseEnabled = !!w.sbiAjax.sseEnabled;
    if (!this.sseEnabled) {
      this.debugLog('SSE disabled in configuration');
      return;
    }

    // If already connected, don't reinitialize
    if (this.eventSource && this.eventSource.readyState !== EventSource.CLOSED) {
      this.debugLog('SSE already connected, skipping init');
      return;
    }

    this.connectSSE(w);
  }

  /**
   * Establish SSE connection with auto-reconnect support.
   * @since 1.0.33
   */
  private connectSSE(w: any): void {
    this.sseConnectionStatus = 'connecting';
    this.updateSSEStatusIndicator();

    try {
      const sseUrl = w.sbiAjax.ajaxurl + '?action=sbi_state_stream';
      this.eventSource = new EventSource(sseUrl);

      this.eventSource.addEventListener('open', () => {
        this.debugLog('SSE connection opened');
        this.sseConnectionStatus = 'connected';
        this.sseReconnectAttempts = 0; // Reset on successful connection
        this.sseLastEventTime = Date.now();
        this.updateSSEStatusIndicator();
        this.startHeartbeatMonitor();
      });

      this.eventSource.addEventListener('error', (e) => {
        this.debugLog('SSE connection error', 'error');
        this.sseConnectionStatus = 'disconnected';
        this.updateSSEStatusIndicator();
        this.stopHeartbeatMonitor();

        // Close the errored connection
        if (this.eventSource) {
          this.eventSource.close();
          this.eventSource = null;
        }

        // Attempt reconnect with exponential backoff
        this.scheduleReconnect();
      });

      // Listen for heartbeat events from server
      this.eventSource.addEventListener('heartbeat', () => {
        this.sseLastEventTime = Date.now();
        this.debugLog('SSE heartbeat received');
      });

      // ⚠️ CRITICAL SSE EVENT HANDLER - DO NOT MODIFY ⚠️
      // This handles real-time state updates from the backend
      this.eventSource.addEventListener('state_changed', (e) => {
        try {
          // Track last event time for heartbeat monitoring
          this.sseLastEventTime = Date.now();

          // ⚠️ CRITICAL PAYLOAD PARSING - DO NOT CHANGE FORMAT ⚠️
          const payload = JSON.parse(e.data || '{}');
          const repo = payload.repository;
          const toState = payload.to;

          if (repo && toState) {
            this.debugLog(`SSE state update: ${repo} -> ${toState}`);

            // ⚠️ CRITICAL STATE CONVERSION - DO NOT MODIFY ⚠️
            // Convert string state to PluginState enum
            const state = this.stringToPluginState(toState);
            if (state) {
              // ⚠️ CRITICAL STATE UPDATE - USES CORE FSM METHOD ⚠️
              // This triggers the full state update and notification chain
              this.set(repo, state);
              this.applyToRow(repo, state);
            }
          }
        } catch (err) {
          this.debugLog(`SSE event parsing error: ${err}`, 'error');
        }
      });

    } catch (err) {
      this.debugLog(`SSE initialization error: ${err}`, 'error');
    }
  }

  // ┌─────────────────────────────────────────────────────────────────────────┐
  // │ SSE AUTO-RECONNECT & HEARTBEAT METHODS - v1.0.33                        │
  // │ DO NOT REMOVE: Provides network resilience for real-time updates       │
  // └─────────────────────────────────────────────────────────────────────────┘

  /**
   * Schedule a reconnection attempt with exponential backoff.
   * Backoff formula: min(30s, 1s * 2^attempts)
   * @since 1.0.33
   */
  private scheduleReconnect(): void {
    if (this.sseReconnectAttempts >= this.sseMaxReconnectAttempts) {
      this.debugLog(`SSE max reconnect attempts (${this.sseMaxReconnectAttempts}) reached, giving up`, 'error');
      this.updateSSEStatusIndicator('failed');
      return;
    }

    // Clear any existing timer
    if (this.sseReconnectTimer) {
      clearTimeout(this.sseReconnectTimer);
    }

    // Exponential backoff: 1s, 2s, 4s, 8s, 16s, 30s (capped)
    const delayMs = Math.min(30000, 1000 * Math.pow(2, this.sseReconnectAttempts));
    this.sseReconnectAttempts++;

    this.debugLog(`SSE reconnecting in ${delayMs}ms (attempt ${this.sseReconnectAttempts}/${this.sseMaxReconnectAttempts})`);

    this.sseReconnectTimer = setTimeout(() => {
      if (this.sseWindowRef) {
        this.connectSSE(this.sseWindowRef);
      }
    }, delayMs);
  }

  /**
   * Start monitoring for heartbeat events.
   * If no events received for 35 seconds, assume connection is stale and reconnect.
   * @since 1.0.33
   */
  private startHeartbeatMonitor(): void {
    this.stopHeartbeatMonitor(); // Clear any existing

    const heartbeatIntervalMs = 10000; // Check every 10 seconds
    const staleThresholdMs = 35000; // Consider stale if no events for 35s (server sends every 25s)

    this.sseHeartbeatTimer = setInterval(() => {
      const timeSinceLastEvent = Date.now() - this.sseLastEventTime;

      if (timeSinceLastEvent > staleThresholdMs) {
        this.debugLog(`SSE connection stale (no events for ${Math.round(timeSinceLastEvent/1000)}s), reconnecting`, 'error');

        // Close stale connection
        if (this.eventSource) {
          this.eventSource.close();
          this.eventSource = null;
        }

        this.sseConnectionStatus = 'disconnected';
        this.updateSSEStatusIndicator();
        this.stopHeartbeatMonitor();
        this.scheduleReconnect();
      }
    }, heartbeatIntervalMs);
  }

  /**
   * Stop the heartbeat monitor.
   * @since 1.0.33
   */
  private stopHeartbeatMonitor(): void {
    if (this.sseHeartbeatTimer) {
      clearInterval(this.sseHeartbeatTimer);
      this.sseHeartbeatTimer = null;
    }
  }

  /**
   * Update the SSE status indicator in the UI.
   * Shows connection status as a badge in the debug panel header.
   * @since 1.0.33
   */
  private updateSSEStatusIndicator(status?: 'failed'): void {
    try {
      // Find or create the SSE status badge
      let badge = document.getElementById('sbi-sse-status-badge');

      if (!badge) {
        // Try to find the debug panel header to append the badge
        const debugHeader = document.querySelector('.sbi-debug-panel-header, .sbi-header-actions');
        if (debugHeader) {
          badge = document.createElement('span');
          badge.id = 'sbi-sse-status-badge';
          badge.style.cssText = 'margin-left: 8px; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 500;';
          debugHeader.appendChild(badge);
        }
      }

      if (badge) {
        const displayStatus = status === 'failed' ? 'failed' : this.sseConnectionStatus;

        switch (displayStatus) {
          case 'connected':
            badge.textContent = '● SSE Connected';
            badge.style.backgroundColor = '#d4edda';
            badge.style.color = '#155724';
            break;
          case 'connecting':
            badge.textContent = '◐ SSE Connecting...';
            badge.style.backgroundColor = '#fff3cd';
            badge.style.color = '#856404';
            break;
          case 'disconnected':
            badge.textContent = '○ SSE Disconnected';
            badge.style.backgroundColor = '#f8d7da';
            badge.style.color = '#721c24';
            break;
          case 'failed':
            badge.textContent = '✕ SSE Failed';
            badge.style.backgroundColor = '#f8d7da';
            badge.style.color = '#721c24';
            break;
        }
      }
    } catch (err) {
      // Silently fail - UI indicator is non-critical
    }
  }

  /**
   * Get current SSE connection status.
   * @since 1.0.33
   */
  getSSEStatus(): { status: string; reconnectAttempts: number; lastEventTime: number } {
    return {
      status: this.sseConnectionStatus,
      reconnectAttempts: this.sseReconnectAttempts,
      lastEventTime: this.sseLastEventTime
    };
  }

  closeSSE(): void {
    // Stop reconnect attempts
    if (this.sseReconnectTimer) {
      clearTimeout(this.sseReconnectTimer);
      this.sseReconnectTimer = null;
    }
    this.stopHeartbeatMonitor();

    if (this.eventSource) {
      this.eventSource.close();
      this.eventSource = null;
      this.debugLog('SSE connection closed');
    }

    this.sseConnectionStatus = 'disconnected';
    this.sseReconnectAttempts = 0;
  }

  private stringToPluginState(stateStr: string): PluginState | null {
    const stateMap: Record<string, PluginState> = {
      'unknown': PluginState.UNKNOWN,
      'checking': PluginState.CHECKING,
      'available': PluginState.AVAILABLE,
      'not_plugin': PluginState.NOT_PLUGIN,
      'installed_inactive': PluginState.INSTALLED_INACTIVE,
      'installed_active': PluginState.INSTALLED_ACTIVE,
      'installing': PluginState.INSTALLING,
      'error': PluginState.ERROR,
    };
    return stateMap[stateStr] || null;
  }

  private debugLog(message: string, level: 'info' | 'error' = 'info'): void {
    try {
      const fullMessage = `[RepositoryFSM] ${message}`;
      if (typeof window !== 'undefined' && (window as any).sbiDebug) {
        (window as any).sbiDebug.addEntry(level, 'FSM SSE', fullMessage);
      } else {
        // eslint-disable-next-line no-console
        console.log(fullMessage);
      }
    } catch {}
  }



  // Helper method to check if a repository is in a specific state
  isInState(repo: RepoId, state: PluginState): boolean {
    return this.get(repo) === state;
  }

  // ⚠️ ⚠️ ⚠️ ENHANCED ERROR HANDLING METHODS - v1.0.32 CRITICAL FEATURES ⚠️ ⚠️ ⚠️

  /**
   * ⚠️ CRITICAL ERROR MESSAGE ENHANCEMENT - DO NOT MODIFY PATTERNS ⚠️
   *
   * This method transforms raw error messages into user-friendly, actionable guidance.
   * It's a core part of the Enhanced Error Messages system implemented in v1.0.32.
   *
   * BREAKING THIS WILL:
   * - Return users to cryptic, unhelpful error messages
   * - Break auto-retry suggestions and links
   * - Remove actionable recovery guidance
   * - Cause user confusion and support tickets
   *
   * TESTING REQUIREMENTS:
   * 1. Test all error patterns (rate limit, 404, network, permission, etc.)
   * 2. Verify HTML formatting doesn't break UI
   * 3. Test auto-refresh links work correctly
   * 4. Validate GitHub repository links
   * 5. Run Self Tests (Test Suite 8: Error Handling System)
   *
   * @param errorMessage Raw error message from backend/API
   * @param source Error source (github_api, install, activate, etc.)
   * @param repo Repository identifier for contextual links
   * @returns Enhanced HTML error message with recovery guidance
   */
  private getActionableErrorMessage(errorMessage: string, source: string, repo: RepoId): string {
    const lowerMessage = errorMessage.toLowerCase();

    // GitHub API Rate Limit
    if (lowerMessage.includes('rate limit') || lowerMessage.includes('403')) {
      return `<strong>GitHub API Rate Limit</strong><br>
              <small>Please wait 5-10 minutes before trying again. GitHub limits API requests to prevent abuse.<br>
              <a href="#" onclick="setTimeout(() => location.reload(), 300000); return false;" style="color: #0073aa;">Auto-refresh in 5 minutes</a></small>`;
    }

    // Repository Not Found
    if (lowerMessage.includes('404') || lowerMessage.includes('not found')) {
      return `<strong>Repository Not Found</strong><br>
              <small>The repository may be private, renamed, or deleted.<br>
              <a href="https://github.com/${repo}" target="_blank" style="color: #0073aa;">View on GitHub</a> to verify it exists.</small>`;
    }

    // Network/Connection Errors
    if (lowerMessage.includes('network') || lowerMessage.includes('timeout') || lowerMessage.includes('connection')) {
      return `<strong>Network Error</strong><br>
              <small>Check your internet connection and try again. This is usually temporary.<br>
              <em>Auto-retry will attempt in a few seconds...</em></small>`;
    }

    // Permission Errors
    if (source === 'install' && (lowerMessage.includes('permission') || lowerMessage.includes('unauthorized'))) {
      return `<strong>Permission Error</strong><br>
              <small>You may not have permission to install plugins. Contact your WordPress administrator.<br>
              Required capability: <code>install_plugins</code></small>`;
    }

    // Activation/Deactivation Errors
    if (source === 'activate' && lowerMessage.includes('activation')) {
      return `<strong>Plugin Activation Failed</strong><br>
              <small>The plugin may have compatibility issues or missing dependencies.<br>
              Check the WordPress error log for more details.</small>`;
    }

    if (source === 'deactivate' && lowerMessage.includes('deactivation')) {
      return `<strong>Plugin Deactivation Failed</strong><br>
              <small>The plugin may be required by other plugins or themes.<br>
              Try deactivating dependent plugins first.</small>`;
    }

    // Download/Package Errors
    if (lowerMessage.includes('download') || lowerMessage.includes('package') || lowerMessage.includes('zip')) {
      return `<strong>Download Error</strong><br>
              <small>Failed to download or extract the plugin package.<br>
              The repository may not contain a valid WordPress plugin.</small>`;
    }

    // GitHub API Errors (general)
    if (source === 'github_api' || lowerMessage.includes('github')) {
      return `<strong>GitHub API Error</strong><br>
              <small>Unable to communicate with GitHub. This may be temporary.<br>
              <a href="https://www.githubstatus.com/" target="_blank" style="color: #0073aa;">Check GitHub Status</a></small>`;
    }

    // WordPress Core Errors
    if (lowerMessage.includes('wordpress') || lowerMessage.includes('wp-admin')) {
      return `<strong>WordPress Error</strong><br>
              <small>A WordPress core error occurred. Check your site's error logs.<br>
              Ensure WordPress is up to date and functioning properly.</small>`;
    }

    // Memory/Resource Errors
    if (lowerMessage.includes('memory') || lowerMessage.includes('fatal error')) {
      return `<strong>Server Resource Error</strong><br>
              <small>Insufficient server memory or resources.<br>
              Contact your hosting provider to increase PHP memory limit.</small>`;
    }

    // Fallback: Generic error with helpful context
    return `<strong>Error:</strong> ${errorMessage}<br>
            <small>Source: ${source} • Try refreshing the repository status or contact support if the issue persists.<br>
            <em>Use the Retry button below if available.</em></small>`;
  }

  /**
   * ⚠️ CRITICAL ERROR STATE SETTER - CORE ERROR HANDLING ⚠️
   *
   * This method is the primary way to set error states in the FSM.
   * It integrates with the Enhanced Error Messages system and auto-retry logic.
   *
   * BREAKING THIS WILL:
   * - Stop error state tracking
   * - Break enhanced error message display
   * - Disable auto-retry functionality
   * - Cause error state corruption
   *
   * INTEGRATION POINTS:
   * - Uses getActionableErrorMessage() for user-friendly messages
   * - Triggers auto-retry for transient errors
   * - Updates FSM state to ERROR
   * - Stores error context for UI display
   *
   * @param repo Repository identifier
   * @param message Raw error message
   * @param source Error source identifier
   * @param recoverable Whether error can be retried
   */
  setError(repo: RepoId, message: string, source: string, recoverable: boolean = true): void {
    // Enhanced: Convert raw error message to actionable user-friendly message
    const actionableMessage = this.getActionableErrorMessage(message, source, repo);

    const errorContext: ErrorContext = {
      timestamp: Date.now(),
      message: actionableMessage,
      source,
      retryCount: 0,
      recoverable,
    };

    this.errorContexts.set(repo, errorContext);
    this.set(repo, PluginState.ERROR);
    this.debugLog(`Error set for ${repo}: ${message} (source: ${source}, recoverable: ${recoverable})`, 'error');

    // Enhanced: Auto-retry for certain types of errors
    if (this.shouldAutoRetry(message) && recoverable) {
      const retryDelay = this.getRetryDelay(message, 0);
      this.debugLog(`Auto-retry scheduled for ${repo} in ${retryDelay}ms`);
      setTimeout(() => this.retryRepository(repo), retryDelay);
    }
  }

  /**
   * Set error from enhanced backend response with structured data
   */
  setErrorFromResponse(repo: RepoId, errorResponse: any, source: string): void {
    // Use backend-provided message or fall back to generic
    const message = errorResponse.message || 'An error occurred';

    // Create enhanced error context with backend data
    const errorContext: ErrorContext = {
      timestamp: Date.now(),
      message: this.getActionableErrorMessage(message, source, repo),
      source,
      retryCount: 0,
      recoverable: errorResponse.recoverable !== false, // Default to true unless explicitly false
      type: errorResponse.type,
      severity: errorResponse.severity,
      retry_delay: errorResponse.retry_delay,
      guidance: errorResponse.guidance
    };

    this.errorContexts.set(repo, errorContext);
    this.set(repo, PluginState.ERROR);
    this.debugLog(`Enhanced error set for ${repo}: ${message} (type: ${errorResponse.type}, severity: ${errorResponse.severity})`, 'error');

    // Log detailed validation information if available
    if (errorResponse.error_code === 'validation_failed' || errorResponse.error_code === 'activation_validation_failed') {
      this.logValidationFailureDetails(repo, errorResponse);
    }

    // Use backend-suggested retry delay or auto-retry logic
    if (errorContext.recoverable && (errorResponse.guidance?.auto_retry || this.shouldAutoRetry(message))) {
      const retryDelay = (errorResponse.retry_delay || this.getRetryDelay(message, 0)) * 1000; // Convert to milliseconds
      this.debugLog(`Auto-retry scheduled for ${repo} in ${retryDelay}ms (backend suggested: ${errorResponse.retry_delay}s)`);
      setTimeout(() => this.retryRepository(repo), retryDelay);
    }
  }

  getErrorContext(repo: RepoId): ErrorContext | null {
    return this.errorContexts.get(repo) || null;
  }

  /**
   * Log detailed validation failure information to debug console
   */
  private logValidationFailureDetails(repo: RepoId, errorResponse: any): void {
    this.debugLog(`🔍 Validation Failure Details for ${repo}:`, 'error');

    // Log failed validation categories
    if (errorResponse.failed_validations && errorResponse.failed_validations.length > 0) {
      this.debugLog(`❌ Failed Validations: ${errorResponse.failed_validations.join(', ')}`, 'error');
    }

    // Log specific error details for each category
    if (errorResponse.error_details) {
      for (const [category, errors] of Object.entries(errorResponse.error_details)) {
        if (Array.isArray(errors) && errors.length > 0) {
          this.debugLog(`📋 ${category.charAt(0).toUpperCase() + category.slice(1)} Errors:`, 'error');
          (errors as string[]).forEach((error, index) => {
            this.debugLog(`   ${index + 1}. ${error}`, 'error');
          });
        }
      }
    }

    // Log validation summary if available
    if (errorResponse.validation_results?.summary) {
      const summary = errorResponse.validation_results.summary;
      this.debugLog(
        `📊 Validation Summary: ${summary.passed_checks}/${summary.total_checks} checks passed (${summary.success_rate}% success rate)`,
        'info'
      );
    }

    // Log recommendations if available
    if (errorResponse.validation_results?.recommendations && errorResponse.validation_results.recommendations.length > 0) {
      this.debugLog(`💡 Recommendations:`, 'info');
      errorResponse.validation_results.recommendations.forEach((rec: string, index: number) => {
        this.debugLog(`   ${index + 1}. ${rec}`, 'info');
      });
    }

    // Log debug steps if available
    if (errorResponse.debug_steps) {
      this.debugLog(`🔧 Debug Steps:`, 'info');
      errorResponse.debug_steps.forEach((step: any, index: number) => {
        if (step.status === 'failed') {
          this.debugLog(`   ${index + 1}. ${step.step}: ${step.message || 'Failed'}`, 'error');

          // Log additional step details
          if (step.failed_categories) {
            this.debugLog(`      Failed Categories: ${step.failed_categories.join(', ')}`, 'error');
          }
          if (step.error_details) {
            this.debugLog(`      Error Details: ${JSON.stringify(step.error_details, null, 2)}`, 'error');
          }
        }
      });
    }

    // Add separator for readability
    this.debugLog(`${'='.repeat(60)}`, 'info');
  }

  /**
   * Extract validation details from error context for UI display
   */
  private getValidationDetailsFromContext(errorContext: ErrorContext): string | null {
    // Check if this is a validation error by looking at the message or source
    const isValidationError = errorContext.message.includes('prerequisites not met') ||
                             errorContext.source.includes('validation');

    if (!isValidationError) {
      return null;
    }

    // Try to extract validation details from the error message
    let details = '';

    // Look for failed validation categories in the message
    const failedValidationsMatch = errorContext.message.match(/Failed validations: ([^.]+)/);
    if (failedValidationsMatch) {
      const failedCategories = failedValidationsMatch[1].split(', ');
      details += `<span style="color: #d63638;">❌ Failed: ${failedCategories.join(', ')}</span><br>`;
    }

    // Add common validation failure explanations
    if (errorContext.message.includes('Input')) {
      details += `<small>• Check repository name format and spelling</small><br>`;
    }
    if (errorContext.message.includes('Permission')) {
      details += `<small>• Contact administrator for plugin installation permissions</small><br>`;
    }
    if (errorContext.message.includes('Resource')) {
      details += `<small>• Server may need more memory or disk space</small><br>`;
    }
    if (errorContext.message.includes('Network')) {
      details += `<small>• Check internet connection and GitHub accessibility</small><br>`;
    }
    if (errorContext.message.includes('State')) {
      details += `<small>• Plugin may already be installed or another operation is in progress</small><br>`;
    }
    if (errorContext.message.includes('WordPress')) {
      details += `<small>• WordPress environment may need updates or configuration</small><br>`;
    }
    if (errorContext.message.includes('Concurrency')) {
      details += `<small>• Another operation is in progress, please wait and try again</small><br>`;
    }

    return details || null;
  }

  /**
   * Determine if an error should trigger automatic retry
   */
  private shouldAutoRetry(message: string): boolean {
    const lowerMessage = message.toLowerCase();
    return lowerMessage.includes('network') ||
           lowerMessage.includes('timeout') ||
           lowerMessage.includes('connection') ||
           lowerMessage.includes('temporary') ||
           lowerMessage.includes('503') || // Service unavailable
           lowerMessage.includes('502'); // Bad gateway
  }

  /**
   * Get appropriate retry delay based on error type and retry count
   */
  private getRetryDelay(message: string, retryCount: number): number {
    const lowerMessage = message.toLowerCase();

    // Rate limit errors need longer delays
    if (lowerMessage.includes('rate limit') || lowerMessage.includes('403')) {
      return 60000; // 1 minute for rate limits
    }

    // Network errors use exponential backoff
    if (lowerMessage.includes('network') || lowerMessage.includes('timeout')) {
      return Math.min(5000 * Math.pow(2, retryCount), 30000); // Max 30 seconds
    }

    // Default retry delay
    return 2000; // 2 seconds
  }

  canRetry(repo: RepoId): boolean {
    const errorContext = this.errorContexts.get(repo);
    if (!errorContext || !errorContext.recoverable) return false;

    return errorContext.retryCount < this.maxRetries;
  }

  async retryRepository(repo: RepoId): Promise<boolean> {
    const errorContext = this.errorContexts.get(repo);
    if (!errorContext || !this.canRetry(repo)) {
      this.debugLog(`Cannot retry ${repo}: ${!errorContext ? 'no error context' : 'max retries exceeded'}`, 'error');
      return false;
    }

    // Update retry context with enhanced delay calculation
    errorContext.retryCount++;
    errorContext.lastRetryAt = Date.now();
    this.errorContexts.set(repo, errorContext);

    this.debugLog(`Retrying ${repo} (attempt ${errorContext.retryCount}/${this.maxRetries})`);

    try {
      // Transition back to CHECKING state to restart the process
      this.set(repo, PluginState.CHECKING);

      // Trigger a refresh via the backend
      if (typeof window !== 'undefined' && (window as any).sbiAjax) {
        const response = await fetch((window as any).sbiAjax.ajaxurl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            action: 'sbi_refresh_repository',
            repository: repo,
            nonce: (window as any).sbiAjax.nonce,
          }),
        });

        if (response.ok) {
          this.debugLog(`Retry initiated for ${repo}`);
          return true;
        } else {
          throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
      }
    } catch (error) {
      this.debugLog(`Retry failed for ${repo}: ${error}`, 'error');
      this.setError(repo, `Retry failed: ${error}`, 'retry_mechanism', errorContext.recoverable);
    }

    return false;
  }

  clearError(repo: RepoId): void {
    this.errorContexts.delete(repo);
    this.debugLog(`Error context cleared for ${repo}`);
  }

  getErrorStatistics(): { total: number; recoverable: number; maxRetriesReached: number } {
    let total = 0;
    let recoverable = 0;
    let maxRetriesReached = 0;

    for (const [repo, context] of this.errorContexts) {
      total++;
      if (context.recoverable) recoverable++;
      if (context.retryCount >= this.maxRetries) maxRetriesReached++;
    }

    return { total, recoverable, maxRetriesReached };
  }

  // Helper method to check if a repository is in any of the given states
  isInAnyState(repo: RepoId, states: PluginState[]): boolean {
    const currentState = this.get(repo);
    return currentState ? states.includes(currentState) : false;
  }

  // Error Display Management
  private handleErrorState(row: HTMLTableRowElement, repo: RepoId): void {
    const errorContext = this.getErrorContext(repo);
    if (!errorContext) return;

    // Find or create error display container
    let errorContainer = row.querySelector('.sbi-error-display') as HTMLElement;
    if (!errorContainer) {
      errorContainer = document.createElement('div');
      errorContainer.className = 'sbi-error-display';
      errorContainer.style.cssText = 'background: #ffeaea; border: 1px solid #d63638; padding: 8px; margin: 4px 0; border-radius: 3px; font-size: 12px;';

      // Insert after the first cell
      const firstCell = row.querySelector('td');
      if (firstCell) {
        firstCell.appendChild(errorContainer);
      }
    }

    // Enhanced error display with actionable messages
    const timeAgo = this.formatTimeAgo(errorContext.timestamp);

    // Use backend guidance if available, otherwise use enhanced message
    let errorHtml = '';

    if (errorContext.guidance) {
      // Display backend-provided guidance
      errorHtml = `<strong>${errorContext.guidance.title || 'Error'}</strong><br>`;
      if (errorContext.guidance.description) {
        errorHtml += `<small>${errorContext.guidance.description}</small><br>`;
      }

      // Add action items
      if (errorContext.guidance.actions && errorContext.guidance.actions.length > 0) {
        errorHtml += `<small><strong>What you can do:</strong><br>`;
        errorContext.guidance.actions.forEach(action => {
          errorHtml += `• ${action}<br>`;
        });
        errorHtml += `</small>`;
      }

      // Add helpful links
      if (errorContext.guidance.links) {
        Object.entries(errorContext.guidance.links).forEach(([key, url]) => {
          if (key === 'github_url') {
            errorHtml += `<br><small><a href="${url}" target="_blank" style="color: #0073aa;">View on GitHub</a></small>`;
          }
        });
      }

      // Show auto-retry information
      if (errorContext.guidance.auto_retry && errorContext.guidance.retry_in) {
        const retryMinutes = Math.ceil(errorContext.guidance.retry_in / 60);
        errorHtml += `<br><small><em>Auto-retry in ${retryMinutes} minute${retryMinutes > 1 ? 's' : ''}</em></small>`;
      }
    } else {
      // Fall back to enhanced message from getActionableErrorMessage
      errorHtml = errorContext.message;
    }

    // Add technical details in a collapsible section for debugging
    errorHtml += `<br><details style="margin-top: 8px;">
                    <summary style="cursor: pointer; font-size: 11px; color: #666;">Technical Details</summary>
                    <div style="margin-top: 4px; font-size: 11px; color: #666;">
                      <strong>Source:</strong> ${errorContext.source}<br>
                      <strong>Time:</strong> ${timeAgo}`;

    if (errorContext.type) {
      errorHtml += `<br><strong>Type:</strong> ${errorContext.type}`;
    }

    if (errorContext.severity) {
      errorHtml += `<br><strong>Severity:</strong> ${errorContext.severity}`;
    }

    if (errorContext.retryCount > 0) {
      errorHtml += `<br><strong>Retries:</strong> ${errorContext.retryCount}/${this.maxRetries}`;
    }

    // Add validation failure details if available
    const validationDetails = this.getValidationDetailsFromContext(errorContext);
    if (validationDetails) {
      errorHtml += `<br><br><strong>Validation Failures:</strong><br>`;
      errorHtml += validationDetails;
    }

    errorHtml += `</div></details>`;

    // Enhanced retry button with better styling and context
    if (errorContext.recoverable && this.canRetry(repo)) {
      const retryText = errorContext.retryCount > 0 ? `Retry (${this.maxRetries - errorContext.retryCount} left)` : 'Retry';
      errorHtml += `<br><button class="button button-small sbi-retry-btn" data-repo="${repo}"
                      style="margin-top: 8px; background: #0073aa; color: white; border-color: #0073aa;">
                      <span class="dashicons dashicons-update" style="font-size: 12px; margin-right: 4px;"></span>${retryText}
                    </button>`;
    } else if (!errorContext.recoverable) {
      errorHtml += `<br><div style="margin-top: 8px; padding: 4px 8px; background: #fcf2f2; border-left: 3px solid #d63638; font-size: 12px;">
                      <strong>Non-recoverable error</strong> - Manual intervention required
                    </div>`;
    } else {
      errorHtml += `<br><div style="margin-top: 8px; padding: 4px 8px; background: #fcf8e3; border-left: 3px solid #dba617; font-size: 12px;">
                      <strong>Max retries reached</strong> - Try refreshing the page or contact support
                    </div>`;
    }

    errorContainer.innerHTML = errorHtml;

    // Attach retry handler
    const retryBtn = errorContainer.querySelector('.sbi-retry-btn') as HTMLButtonElement;
    if (retryBtn) {
      retryBtn.onclick = () => this.handleRetryClick(repo, retryBtn);
    }
  }

  private clearErrorDisplay(row: HTMLTableRowElement): void {
    const errorContainer = row.querySelector('.sbi-error-display');
    if (errorContainer) {
      errorContainer.remove();
    }
  }

  private async handleRetryClick(repo: RepoId, button: HTMLButtonElement): Promise<void> {
    button.disabled = true;
    button.textContent = 'Retrying...';

    const success = await this.retryRepository(repo);

    if (!success) {
      button.disabled = false;
      button.textContent = 'Retry Failed';
      setTimeout(() => {
        if (this.canRetry(repo)) {
          button.textContent = 'Retry';
          button.disabled = false;
        }
      }, 3000);
    }
    // If successful, the error display will be cleared by state change
  }

  private formatTimeAgo(timestamp: number): string {
    const seconds = Math.floor((Date.now() - timestamp) / 1000);
    if (seconds < 60) return `${seconds}s ago`;
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    return `${hours}h ago`;
  }

  // 🔍 FSM-FIRST REPOSITORY FILTERING METHODS

  /**
   * Set repository filter (FSM-first approach)
   * Maintains all repository states while filtering display
   */
  setFilter(searchTerm: string): void {
    const normalizedTerm = searchTerm.trim().toLowerCase();

    this.filterState = {
      searchTerm: normalizedTerm,
      isActive: normalizedTerm.length > 0,
      matchedRepositories: new Set(),
      appliedAt: Date.now(),
      sessionKey: this.filterState.sessionKey
    };

    if (this.filterState.isActive) {
      // Find matching repositories from current FSM state
      for (const [repo] of this.states) {
        if (this.matchesFilter(repo, normalizedTerm)) {
          this.filterState.matchedRepositories.add(repo);
        }
      }
    }

    // Save to session storage for persistence
    this.saveFilterToSession();

    // Apply filter to UI
    this.applyFilterToUI();
  }

  /**
   * Clear repository filter
   */
  clearFilter(): void {
    this.filterState = {
      searchTerm: '',
      isActive: false,
      matchedRepositories: new Set(),
      appliedAt: Date.now(),
      sessionKey: this.filterState.sessionKey
    };

    // Clear session storage
    this.clearFilterFromSession();

    // Show all repositories
    this.applyFilterToUI();
  }

  /**
   * Get current filter state
   */
  getFilterState(): Readonly<FilterState> {
    return { ...this.filterState };
  }

  /**
   * Check if repository matches filter criteria
   */
  private matchesFilter(repo: RepoId, searchTerm: string): boolean {
    if (!searchTerm) return true;

    const repoLower = repo.toLowerCase();
    const [owner, name] = repo.split('/');

    // Match against repository name, owner, or full name
    return repoLower.includes(searchTerm) ||
           name?.toLowerCase().includes(searchTerm) ||
           owner?.toLowerCase().includes(searchTerm);
  }

  /**
   * Apply filter to UI (show/hide repository rows)
   */
  private applyFilterToUI(): void {
    const rows = document.querySelectorAll('[data-repository]') as NodeListOf<HTMLTableRowElement>;

    rows.forEach(row => {
      const repo = row.dataset.repository;
      if (!repo) return;

      const shouldShow = !this.filterState.isActive || this.filterState.matchedRepositories.has(repo);
      row.style.display = shouldShow ? '' : 'none';
    });

    // Update filter status display
    this.updateFilterStatusDisplay();
  }

  /**
   * Update filter status display in UI
   */
  private updateFilterStatusDisplay(): void {
    const statusElement = document.querySelector('.sbi-filter-status') as HTMLElement;
    if (!statusElement) return;

    if (this.filterState.isActive) {
      const matchCount = this.filterState.matchedRepositories.size;
      const totalCount = this.states.size;
      statusElement.textContent = `Showing ${matchCount} of ${totalCount} repositories`;
      statusElement.style.display = 'block';
    } else {
      statusElement.style.display = 'none';
    }
  }

  /**
   * Save filter state to session storage
   */
  private saveFilterToSession(): void {
    try {
      const filterData = {
        searchTerm: this.filterState.searchTerm,
        appliedAt: this.filterState.appliedAt
      };
      sessionStorage.setItem(this.filterState.sessionKey, JSON.stringify(filterData));
    } catch (error) {
      console.warn('Failed to save filter to session storage:', error);
    }
  }

  /**
   * Load filter state from session storage
   */
  loadFilterFromSession(): void {
    try {
      const stored = sessionStorage.getItem(this.filterState.sessionKey);
      if (stored) {
        const filterData = JSON.parse(stored);
        if (filterData.searchTerm) {
          this.setFilter(filterData.searchTerm);
        }
      }
    } catch (error) {
      console.warn('Failed to load filter from session storage:', error);
    }
  }

  /**
   * Clear filter from session storage
   */
  private clearFilterFromSession(): void {
    try {
      sessionStorage.removeItem(this.filterState.sessionKey);
    } catch (error) {
      console.warn('Failed to clear filter from session storage:', error);
    }
  }

  /**
   * Re-apply filter when new repositories are added to FSM
   * Called after repository list updates
   */
  refreshFilter(): void {
    if (this.filterState.isActive) {
      this.setFilter(this.filterState.searchTerm);
    }
  }
}

export const repositoryFSM = new RepositoryFSM();


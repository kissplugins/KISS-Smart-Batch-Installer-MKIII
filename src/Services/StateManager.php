<?php
/**
 * State manager handling plugin states for repositories.
 *
 * @package SBI\Services
 */

namespace SBI\Services;

use SBI\Enums\PluginState;

/**
 * ⚠️ ⚠️ ⚠️ CRITICAL FSM STATE MANAGER - HANDLE WITH EXTREME CARE ⚠️ ⚠️ ⚠️
 *
 * This is the backend heart of the Smart Batch Installer's state management system.
 * It manages state transitions, validation, and persistence for all repositories.
 *
 * BEFORE MODIFYING THIS CLASS:
 * 1. Run Self Tests (Test Suite 3: State Management System)
 * 2. Test all state transitions manually
 * 3. Verify SSE integration still works
 * 4. Check frontend FSM synchronization
 * 5. Test with bulk operations
 * 6. Validate state persistence across requests
 *
 * CRITICAL AREAS - DO NOT MODIFY WITHOUT EXTENSIVE TESTING:
 * - State transition validation logic
 * - State persistence and caching
 * - SSE event emission
 * - State refresh mechanisms
 * - Error state handling
 *
 * INTEGRATION POINTS:
 * - Frontend RepositoryFSM (TypeScript)
 * - SSE real-time updates
 * - AJAX handlers
 * - Plugin installation pipeline
 * - Error handling system
 */
class StateManager {
    /**
     * Repository states cache.
     *
     * @var array<string, PluginState>
     */
    protected array $states = [];

    /**
     * State metadata storage for additional FSM context.
     * Stores metadata like self-protection flags, error context, etc.
     *
     * @var array<string, array>
     */
    private array $state_metadata = [];

    /**
     * Filter metadata for FSM-first repository filtering.
     * Tracks filter state and application history.
     *
     * @var array
     */
    private array $filter_metadata = [];

    /**
     * Allowed transitions cache.
     *
     * @var array<string, array<string>>
     */
    private array $allowed_transitions = [];

    /**
     * Cache expiration time (5 minutes).
     */
    private const CACHE_EXPIRATION = 5 * 60; // 5 minutes in seconds

    /**
     * Event log transient TTL (1 day) and max entries per repo.
     */
    private const EVENT_LOG_TTL = 24 * 60 * 60; // 1 day in seconds
    private const EVENT_LOG_LIMIT = 30;

    /**
     * Allowed state transitions map.
     * NOTE: Keep conservative; refresh_state() uses force to avoid breaking flows.
     * UNKNOWN -> CHECKING/AVAILABLE/NOT_PLUGIN/ERROR/INSTALLED_INACTIVE/INSTALLED_ACTIVE
     * CHECKING -> AVAILABLE/NOT_PLUGIN/ERROR
     * AVAILABLE -> INSTALLED_INACTIVE/ERROR
     * INSTALLED_INACTIVE -> INSTALLED_ACTIVE/ERROR
     * INSTALLED_ACTIVE -> INSTALLED_INACTIVE/ERROR
     * NOT_PLUGIN -> CHECKING/AVAILABLE
     * ERROR -> CHECKING/AVAILABLE/NOT_PLUGIN
     */

    /**
     * Initialize allowed transitions.
     */
    private function init_transitions(): void {
        $this->allowed_transitions = [
            PluginState::UNKNOWN->value => [ PluginState::CHECKING->value, PluginState::AVAILABLE->value, PluginState::NOT_PLUGIN->value, PluginState::ERROR->value, PluginState::INSTALLED_INACTIVE->value, PluginState::INSTALLED_ACTIVE->value ],
            PluginState::CHECKING->value => [ PluginState::AVAILABLE->value, PluginState::NOT_PLUGIN->value, PluginState::ERROR->value ],
            PluginState::AVAILABLE->value => [ PluginState::INSTALLED_INACTIVE->value, PluginState::ERROR->value ],
            PluginState::INSTALLED_INACTIVE->value => [ PluginState::INSTALLED_ACTIVE->value, PluginState::ERROR->value ],
            PluginState::INSTALLED_ACTIVE->value => [ PluginState::INSTALLED_INACTIVE->value, PluginState::ERROR->value ],
            PluginState::NOT_PLUGIN->value => [ PluginState::CHECKING->value, PluginState::AVAILABLE->value ],
            PluginState::ERROR->value => [ PluginState::CHECKING->value, PluginState::AVAILABLE->value, PluginState::NOT_PLUGIN->value ],
        ];
    }

    /**
     * ⚠️ ⚠️ ⚠️ CRITICAL STATE TRANSITION METHOD - CORE FSM OPERATION ⚠️ ⚠️ ⚠️
     *
     * This is the most critical method in the entire FSM system.
     * ALL state changes flow through this method.
     *
     * BREAKING THIS WILL:
     * - Stop all state transitions system-wide
     * - Break frontend-backend state synchronization
     * - Cause state corruption and inconsistencies
     * - Break SSE real-time updates
     * - Break bulk operations
     * - Break plugin installation pipeline
     *
     * CRITICAL FEATURES:
     * - State transition validation
     * - SSE event emission for real-time updates
     * - State persistence and caching
     * - Event logging for debugging
     * - Force mode for refresh operations
     *
     * TESTING REQUIREMENTS BEFORE ANY CHANGES:
     * 1. Run Self Tests (Test Suite 3: State Management System)
     * 2. Test all valid state transitions manually
     * 3. Test invalid transition rejection
     * 4. Test SSE event emission
     * 5. Test force mode functionality
     * 6. Test with bulk operations
     * 7. Test frontend synchronization
     *
     * @param string $repository Repository identifier (owner/repo)
     * @param PluginState $to_state Target state to transition to
     * @param array $context Optional context (source, message, etc.)
     * @param bool $force When true, bypass transition validation (used by refresh_state)
     */
    public function transition( string $repository, PluginState $to_state, array $context = [], bool $force = false ): void {
        $from_state = $this->states[$repository]->value ?? PluginState::UNKNOWN->value;
        $to_value = $to_state->value;

        // Initialize transition map on first use
        if (empty($this->allowed_transitions)) {
            $this->init_transitions();
        }

        if (! $force) {
            $allowed = $this->allowed_transitions[$from_state] ?? [];
            if (! in_array($to_value, $allowed, true)) {
                // Log and ignore invalid transition to keep system robust
                $this->log_event($repository, 'transition_blocked', [
                    'from' => $from_state,
                    'to' => $to_value,
                    'reason' => 'invalid_transition',
                    'context' => $context,
                ]);
                return;
            }
        }

        // Enhanced error state handling
        if ($to_state === PluginState::ERROR) {
            $this->handle_error_transition($repository, $context);
        } elseif ($from_state === PluginState::ERROR->value && $to_state !== PluginState::ERROR) {
            $this->handle_error_recovery($repository, $to_state, $context);
        }

        $this->set_state($repository, $to_state);
        $this->log_event($repository, 'transition', [
            'from' => $from_state,
            'to' => $to_value,
            'context' => $context,
        ]);
        // Broadcast hook (stub)
        $this->broadcast('state_changed', [
            'repository' => $repository,
            'from' => $from_state,
            'to' => $to_value,
            'context' => $context,
            'ts' => time(),
        ]);
    }

    /**
     * Handle transition to ERROR state with enhanced context tracking.
     */
    private function handle_error_transition(string $repository, array $context): void {
        $error_data = [
            'timestamp' => time(),
            'message' => $context['error'] ?? $context['message'] ?? 'Unknown error',
            'source' => $context['source'] ?? 'unknown',
            'recoverable' => $context['recoverable'] ?? true,
            'retry_count' => $this->get_retry_count($repository),
        ];

        // Store error context for recovery
        $this->store_error_context($repository, $error_data);

        // Log detailed error information
        $this->log_event($repository, 'error_occurred', $error_data);

        error_log(sprintf(
            'SBI FSM ERROR: %s - %s (source: %s, recoverable: %s)',
            $repository,
            $error_data['message'],
            $error_data['source'],
            $error_data['recoverable'] ? 'yes' : 'no'
        ));
    }

    /**
     * Handle recovery from ERROR state.
     */
    private function handle_error_recovery(string $repository, PluginState $to_state, array $context): void {
        $error_context = $this->get_error_context($repository);

        $recovery_data = [
            'timestamp' => time(),
            'recovered_to' => $to_state->value,
            'recovery_source' => $context['source'] ?? 'unknown',
            'previous_error' => $error_context['message'] ?? 'unknown',
            'retry_count' => $error_context['retry_count'] ?? 0,
        ];

        // Log successful recovery
        $this->log_event($repository, 'error_recovered', $recovery_data);

        // Clear error context on successful recovery
        $this->clear_error_context($repository);

        error_log(sprintf(
            'SBI FSM RECOVERY: %s recovered to %s (was: %s)',
            $repository,
            $to_state->value,
            $error_context['message'] ?? 'unknown error'
        ));
    }

    /**
     * Store error context for a repository.
     */
    private function store_error_context(string $repository, array $error_data): void {
        $key = 'sbi_error_context_' . md5($repository);
        set_transient($key, $error_data, self::EVENT_LOG_TTL);
    }

    /**
     * Get error context for a repository.
     */
    private function get_error_context(string $repository): array {
        $key = 'sbi_error_context_' . md5($repository);
        $context = get_transient($key);
        return is_array($context) ? $context : [];
    }

    /**
     * Clear error context for a repository.
     */
    private function clear_error_context(string $repository): void {
        $key = 'sbi_error_context_' . md5($repository);
        delete_transient($key);
    }

    /**
     * Set metadata for a repository state (FSM-centric approach).
     *
     * @param string $repository Repository identifier.
     * @param array $metadata Metadata to store.
     */
    public function set_state_metadata(string $repository, array $metadata): void {
        $this->state_metadata[$repository] = array_merge(
            $this->state_metadata[$repository] ?? [],
            $metadata
        );
    }

    /**
     * Get metadata for a repository state (FSM-centric approach).
     *
     * @param string $repository Repository identifier.
     * @param string|null $key Specific metadata key to retrieve.
     * @return mixed Metadata value or array of all metadata.
     */
    public function get_state_metadata(string $repository, ?string $key = null) {
        $metadata = $this->state_metadata[$repository] ?? [];

        if ($key !== null) {
            return $metadata[$key] ?? null;
        }

        return $metadata;
    }

    /**
     * Check if repository is self-protected (FSM-centric self-detection).
     *
     * @param string $repository Repository identifier.
     * @return bool True if repository should be protected from deactivation.
     */
    public function is_self_protected(string $repository): bool {
        return $this->get_state_metadata($repository, 'self_protected') === true;
    }

    /**
     * Detect and mark self-protection for repositories (FSM-centric approach).
     *
     * @param string $repository Repository identifier.
     * @param string|null $plugin_file Plugin file path for additional verification.
     */
    public function detect_and_mark_self_protection(string $repository, ?string $plugin_file = null): void {
        $is_self = $this->detect_self_plugin($repository, $plugin_file);

        if ($is_self) {
            $this->set_state_metadata($repository, [
                'self_protected' => true,
                'protection_reason' => 'Smart Batch Installer self-protection',
                'detected_at' => time()
            ]);
        }
    }

    /**
     * Detect if repository is the Smart Batch Installer itself (FSM-centric detection).
     *
     * @param string $repository Repository identifier.
     * @param string|null $plugin_file Plugin file path for additional verification.
     * @return bool True if this is the Smart Batch Installer plugin.
     */
    private function detect_self_plugin(string $repository, ?string $plugin_file = null): bool {
        // Method 1: Plugin file path comparison (most reliable when installed)
        if (!empty($plugin_file)) {
            // Get current plugin's directory for comparison
            $current_plugin_file = \plugin_basename(__FILE__);
            $current_plugin_dir = dirname(dirname($current_plugin_file)); // Go up from Services to plugin root
            $plugin_dir = dirname($plugin_file);

            if ($plugin_dir === $current_plugin_dir) {
                return true;
            }
        }

        // Method 2: Repository name pattern matching
        $repo_lower = strtolower($repository);
        $self_patterns = [
            'kiss-smart-batch-installer',
            'smart-batch-installer',
            'batch-installer',
            'sbi',
            'kiss-sbi'
        ];

        foreach ($self_patterns as $pattern) {
            if (strpos($repo_lower, $pattern) !== false) {
                return true;
            }
        }

        // Method 3: MKII/MKIII variant detection (enhanced)
        if ((strpos($repo_lower, 'mkii') !== false || strpos($repo_lower, 'mkiii') !== false || strpos($repo_lower, 'mk3') !== false) &&
            (strpos($repo_lower, 'installer') !== false || strpos($repo_lower, 'batch') !== false)) {
            return true;
        }

        // Method 4: Exact repository name matching (fallback for edge cases)
        $exact_matches = [
            'kiss-smart-batch-installer-mkiii',
            'KISS-Smart-Batch-Installer-MKIII',
            'kiss-smart-batch-installer-mkii',
            'KISS-Smart-Batch-Installer-MKII',
            'kiss-smart-batch-installer',
            'KISS-Smart-Batch-Installer'
        ];

        foreach ($exact_matches as $exact_match) {
            if ($repository === $exact_match || $repo_lower === strtolower($exact_match)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get retry count for a repository.
     */
    private function get_retry_count(string $repository): int {
        $error_context = $this->get_error_context($repository);
        return $error_context['retry_count'] ?? 0;
    }

    /**
     * Increment retry count for a repository.
     */
    public function increment_retry_count(string $repository): int {
        $error_context = $this->get_error_context($repository);
        $retry_count = ($error_context['retry_count'] ?? 0) + 1;
        $error_context['retry_count'] = $retry_count;
        $error_context['last_retry_at'] = time();
        $this->store_error_context($repository, $error_context);
        return $retry_count;
    }

    /**
     * Append event to per-repo transient-backed ring buffer.
     */
    private function log_event(string $repository, string $event, array $data = []): void {
        $key = 'sbi_state_events_' . md5($repository);
        $events = \get_transient($key);
        if (!is_array($events)) { $events = []; }
        $events[] = [
            't' => time(),
            'event' => $event,
            'data' => $data,
        ];
        // Cap size
        if (count($events) > self::EVENT_LOG_LIMIT) {
            $events = array_slice($events, -self::EVENT_LOG_LIMIT);
        }
        \set_transient($key, $events, self::EVENT_LOG_TTL);
    }

    /**
     * ┌─────────────────────────────────────────────────────────────────────────┐
     * │ ⚠️  CRITICAL: ATOMIC PROCESSING LOCK MECHANISM - DO NOT MODIFY ⚠️       │
     * ├─────────────────────────────────────────────────────────────────────────┤
     * │ This lock prevents concurrent operations on the same repository.        │
     * │                                                                         │
     * │ WHY IT'S ATOMIC:                                                        │
     * │ - Uses wp_options with INSERT IGNORE (add_option) for true atomicity    │
     * │ - Database-level uniqueness prevents race conditions                    │
     * │ - TTL enforced via stored timestamp, not transient expiration           │
     * │                                                                         │
     * │ REGRESSION RISK: Removing atomicity causes duplicate installs,          │
     * │                  corrupted plugin states, and filesystem conflicts.     │
     * │                                                                         │
     * │ @since 1.0.33 - Upgraded from transient-based to atomic locking         │
     * │ @see Test 3.7 in NewSelfTestsPage.php for validation                    │
     * └─────────────────────────────────────────────────────────────────────────┘
     *
     * Acquire an atomic processing lock for a repository.
     * Uses wp_options INSERT IGNORE for true database-level atomicity.
     * Returns true if acquired, false if already locked by another process.
     *
     * @param string $repository Repository full name (e.g., "owner/repo")
     * @param int    $ttl_seconds Lock timeout in seconds (default 60)
     * @return bool True if lock acquired, false if already locked
     */
    public function acquire_processing_lock(string $repository, int $ttl_seconds = 60): bool {
        global $wpdb;

        $key = 'sbi_lock_' . md5($repository);
        $lock_value = [
            'locked_at' => time(),
            'ttl' => $ttl_seconds,
            'pid' => getmypid(),
            'repository' => $repository,
        ];

        // First, check if an existing lock has expired and clean it up
        $existing = \get_option($key);
        if ($existing !== false && is_array($existing)) {
            $locked_at = $existing['locked_at'] ?? 0;
            $existing_ttl = $existing['ttl'] ?? $ttl_seconds;

            if (time() > ($locked_at + $existing_ttl)) {
                // Lock has expired, remove it
                \delete_option($key);
                $this->log_event($repository, 'lock_expired_cleanup', [
                    'expired_at' => $locked_at + $existing_ttl,
                    'cleaned_at' => time(),
                ]);
            } else {
                // Lock is still valid and held by another process
                $this->log_event($repository, 'lock_contention', [
                    'held_by_pid' => $existing['pid'] ?? 'unknown',
                    'locked_at' => $locked_at,
                    'expires_at' => $locked_at + $existing_ttl,
                    'time_remaining' => ($locked_at + $existing_ttl) - time(),
                ]);
                return false;
            }
        }

        // Attempt atomic insert using add_option (uses INSERT IGNORE internally)
        // This is atomic: only one process can succeed if multiple try simultaneously
        $inserted = \add_option($key, $lock_value, '', 'no');

        if ($inserted) {
            $this->log_event($repository, 'lock_acquired', [
                'ttl' => $ttl_seconds,
                'pid' => getmypid(),
                'expires_at' => time() + $ttl_seconds,
            ]);
            return true;
        }

        // add_option returned false - either race condition or already locked
        // Double-check if we lost a race
        $this->log_event($repository, 'lock_acquisition_failed', [
            'reason' => 'add_option_returned_false',
            'pid' => getmypid(),
        ]);
        return false;
    }

    /**
     * Release a processing lock for a repository.
     * Only releases if the current process owns the lock (by PID).
     *
     * @param string $repository Repository full name
     * @param bool   $force Force release even if not owner (use with caution)
     */
    public function release_processing_lock(string $repository, bool $force = false): void {
        $key = 'sbi_lock_' . md5($repository);

        if (!$force) {
            // Verify we own the lock before releasing
            $existing = \get_option($key);
            if ($existing !== false && is_array($existing)) {
                $owner_pid = $existing['pid'] ?? null;
                if ($owner_pid !== null && $owner_pid !== getmypid()) {
                    $this->log_event($repository, 'lock_release_denied', [
                        'owner_pid' => $owner_pid,
                        'requester_pid' => getmypid(),
                        'reason' => 'not_owner',
                    ]);
                    return; // Don't release a lock we don't own
                }
            }
        }

        \delete_option($key);
        $this->log_event($repository, 'lock_released', [
            'pid' => getmypid(),
            'forced' => $force,
        ]);
    }

    /**
     * Check if a repository is currently locked.
     *
     * @param string $repository Repository full name
     * @return array|false Lock info array if locked, false if not locked
     */
    public function is_locked(string $repository) {
        $key = 'sbi_lock_' . md5($repository);
        $existing = \get_option($key);

        if ($existing === false || !is_array($existing)) {
            return false;
        }

        $locked_at = $existing['locked_at'] ?? 0;
        $ttl = $existing['ttl'] ?? 60;

        // Check if expired
        if (time() > ($locked_at + $ttl)) {
            return false; // Expired, not really locked
        }

        return $existing;
    }

    /**
     * Wait for a lock to become available, with timeout.
     * Useful for queueing operations instead of failing immediately.
     *
     * @param string $repository Repository full name
     * @param int    $max_wait_seconds Maximum time to wait
     * @param int    $poll_interval_ms Polling interval in milliseconds
     * @return bool True if lock acquired within timeout, false if timed out
     */
    public function wait_for_lock(string $repository, int $max_wait_seconds = 30, int $poll_interval_ms = 500): bool {
        $start = time();
        $ttl = 60; // Standard TTL for acquired locks

        while ((time() - $start) < $max_wait_seconds) {
            if ($this->acquire_processing_lock($repository, $ttl)) {
                $this->log_event($repository, 'lock_acquired_after_wait', [
                    'wait_time' => time() - $start,
                ]);
                return true;
            }

            // Wait before trying again
            usleep($poll_interval_ms * 1000);
        }

        $this->log_event($repository, 'lock_wait_timeout', [
            'max_wait' => $max_wait_seconds,
            'waited' => time() - $start,
        ]);
        return false;
    }



    /**
     * Read recent events for a repo (for Self Tests/UI).
     */
    public function get_events(string $repository, int $limit = 10): array {
        $key = 'sbi_state_events_' . md5($repository);
        $events = \get_transient($key);
        if (!is_array($events)) { return []; }
        return array_slice($events, -$limit);
    }

    /**
     * Broadcast a state-related event to listeners (stub + queue).
     * - Logs to per-repo event buffer
     * - Appends to a global broadcast ring buffer for SSE consumers
     */
    public function broadcast(string $event, array $payload = []): void {
        $repo = $payload['repository'] ?? 'unknown';
        $this->log_event($repo, $event, $payload);

        // Append to global broadcast queue (ring buffer)
        $last_id = (int) \get_option('sbi_broadcast_last_id', 0);
        $id = $last_id + 1;
        \update_option('sbi_broadcast_last_id', $id, false);

        $queue = \get_transient('sbi_broadcast_events');
        if (!is_array($queue)) { $queue = []; }
        $queue[] = [
            'id' => $id,
            'event' => $event,
            'payload' => $payload,
            'ts' => time(),
        ];
        // Cap at 100 events
        if (count($queue) > 100) {
            $queue = array_slice($queue, -100);
        }
        \set_transient('sbi_broadcast_events', $queue, self::EVENT_LOG_TTL);
    }

    /**
     * Get broadcast events with id greater than $last_id
     * for use by SSE endpoint.
     *
     * @return array<int, array{ id:int, event:string, payload:array, ts:int }>
     */
    public function get_broadcast_events_since(int $last_id): array {
        $queue = \get_transient('sbi_broadcast_events');
        if (!is_array($queue)) { return []; }
        return array_values(array_filter($queue, static function($e) use ($last_id) {
            return isset($e['id']) && (int)$e['id'] > $last_id;
        }));
    }

    /**
     * PQS Integration service.
     *
     * @var PQSIntegration
     */
    protected PQSIntegration $pqs_integration;
    protected PluginDetectionService $detection_service;

    /**
     * Constructor.
     *
     * @param PQSIntegration $pqs_integration PQS integration service.
     * @param PluginDetectionService $detection_service Plugin detection service.
     */
    public function __construct( PQSIntegration $pqs_integration, PluginDetectionService $detection_service ) {
        $this->pqs_integration = $pqs_integration;
        $this->detection_service = $detection_service;
        $this->load_cached_states();
    }

    /**
     * Get repository state.
     *
     * @param string $repository Repository full name (owner/repo).
     * @param bool   $force_refresh Whether to force refresh the state.
     * @return PluginState Current state of the repository.
     */
    public function get_state( string $repository, bool $force_refresh = false ): PluginState {
        if ( $force_refresh || ! isset( $this->states[ $repository ] ) ) {
            $this->refresh_state( $repository );
        }

        return $this->states[ $repository ] ?? PluginState::UNKNOWN;
    }

    /**
     * Set repository state.
     *
     * @param string      $repository Repository full name.
     * @param PluginState $state      New state.
     */
    public function set_state( string $repository, PluginState $state ): void {
        $this->states[ $repository ] = $state;
        $this->save_cached_states();
    }

    /**
     * Refresh state for a specific repository.
     *
     * @param string $repository Repository full name.
     */
    public function refresh_state( string $repository ): void {
        // Move through CHECKING to determined state; bypass transition validation for refresh
        $this->transition( $repository, PluginState::CHECKING, [ 'source' => 'refresh_state' ], true );

        // Consolidated detection + cache path
        $state = $this->determine_plugin_state( $repository );
        // If still unknown and not installed, try consolidated detect_plugin_state()
        if ( $state === PluginState::UNKNOWN ) {
            $state = $this->detect_plugin_state( $repository );
        }

        // FSM-centric self-protection detection - check for ALL states, not just installed
        $plugin_file = null;
        if (in_array($state, [PluginState::INSTALLED_ACTIVE, PluginState::INSTALLED_INACTIVE], true)) {
            $plugin_file = $this->getInstalledPluginFile($repository);
        }
        // Always run self-protection detection regardless of state
        $this->detect_and_mark_self_protection($repository, $plugin_file);

        $this->transition( $repository, $state, [ 'source' => 'refresh_state' ], true );
    }

    /**
     * Batch refresh states for multiple repositories.
     *
     * @param array $repositories Array of repository names.
     */
    public function batch_refresh_states( array $repositories ): void {
        foreach ( $repositories as $repository ) {
            $this->refresh_state( $repository );
        }
    }

    /**
     * Get states for multiple repositories.
     *
     * @param array $repositories Array of repository names.
     * @param bool  $force_refresh Whether to force refresh all states.
     * @return array<string, PluginState> Array of states keyed by repository name.
     */
    public function get_batch_states( array $repositories, bool $force_refresh = false ): array {
        $states = [];

        foreach ( $repositories as $repository ) {
            $states[ $repository ] = $this->get_state( $repository, $force_refresh );
        }

        return $states;
    }

    /**
     * ✅ FSM INTERNAL: Determine the actual plugin state for a repository.
     *
     * ⚠️ THIS IS AN INTERNAL FSM METHOD - DO NOT CALL DIRECTLY FROM OUTSIDE CODE ⚠️
     *
     * This method is ALLOWED to call WordPress core functions (get_plugins, is_plugin_active)
     * because it's INSIDE the FSM and is DETERMINING state, not reading it.
     *
     * EXTERNAL CODE MUST USE:
     * - get_state($repository) - Get current FSM state
     * - isInstalled($repository) - Check if installed
     * - isActive($repository) - Check if active
     * - getInstalledPluginFile($repository) - Get plugin file
     *
     * DO NOT BYPASS FSM:
     * - DO NOT call get_plugins() from outside StateManager
     * - DO NOT call is_plugin_active() from outside StateManager
     * - DO NOT call this method directly - use get_state() instead
     *
     * @param string $repository Repository full name.
     * @return PluginState Determined state.
     */
    private function determine_plugin_state( string $repository ): PluginState {
        // Extract plugin slug from repository name
        $plugin_slug = $this->extract_plugin_slug( $repository );

        if ( empty( $plugin_slug ) ) {
            return PluginState::UNKNOWN;
        }

        // ✅ ALLOWED: FSM internally calls WordPress core to DETERMINE state
        // This is NOT a bypass - this IS the FSM determining authoritative state
        $plugin_file = $this->find_plugin_file( $plugin_slug );

        if ( empty( $plugin_file ) ) {
            // Plugin not installed. Use detection service (scan up to 3 root PHP files) to decide.
            $repo = [ 'full_name' => $repository, 'name' => $plugin_slug ];
            $det = $this->detection_service->detect_plugin( $repo );

            if ( is_wp_error( $det ) ) {
                return PluginState::UNKNOWN; // conservatively unknown on error
            }

            if ( ! empty( $det['is_plugin'] ) ) {
                return PluginState::AVAILABLE;
            }

            // If detection explicitly concluded it's not a plugin
            if ( isset($det['is_plugin']) && $det['is_plugin'] === false ) {
                return PluginState::NOT_PLUGIN;
            }

            // For all other cases (scan failed, etc.), default to UNKNOWN
            return PluginState::UNKNOWN;
        }

        // ✅ ALLOWED: FSM internally calls is_plugin_active() to DETERMINE state
        // Plugin is installed, check if it's active
        if ( is_plugin_active( $plugin_file ) ) {
            return PluginState::INSTALLED_ACTIVE;
        }

        return PluginState::INSTALLED_INACTIVE;
    }

    /**
     * ✅ FSM PUBLIC API: Get installed plugin file for a repository.
     *
     * USE THIS METHOD instead of calling get_plugins() directly.
     *
     * This is the FSM-approved way to get plugin file paths.
     * It uses FSM state first, then falls back to WordPress core if needed.
     *
     * @param string $repository Repository full name (owner/repo).
     * @return string Plugin file path or empty string if not found.
     */
    public function getInstalledPluginFile(string $repository): string {
        $slug = $this->extract_plugin_slug($repository);
        return $this->find_plugin_file($slug);
    }

    /**
     * ✅ FSM PUBLIC API: Check if a repository's plugin is installed.
     *
     * USE THIS METHOD instead of calling get_plugins() directly.
     *
     * This is the FSM-approved way to check installation status.
     * It uses FSM state first, then falls back to WordPress core if needed.
     *
     * @param string $repository Repository full name (owner/repo).
     * @return bool True if installed, false otherwise.
     */
    public function isInstalled(string $repository): bool {
        $state = $this->get_state($repository);
        if (in_array($state, [PluginState::INSTALLED_ACTIVE, PluginState::INSTALLED_INACTIVE], true)) {
            return true;
        }
        // Fallback to runtime file discovery
        return $this->getInstalledPluginFile($repository) !== '';
    }

    /**
     * ✅ FSM PUBLIC API: Check if a repository's plugin is active.
     *
     * USE THIS METHOD instead of calling is_plugin_active() directly.
     *
     * This is the FSM-approved way to check activation status.
     * It uses FSM state first, then falls back to WordPress core if needed.
     *
     * @param string $repository Repository full name (owner/repo).
     * @return bool True if active, false otherwise.
     */
    public function isActive(string $repository): bool {
        $state = $this->get_state($repository);
        if ($state === PluginState::INSTALLED_ACTIVE) return true;
        if ($state === PluginState::INSTALLED_INACTIVE) return false;
        $file = $this->getInstalledPluginFile($repository);
        if ($file && function_exists('is_plugin_active')) {
            return is_plugin_active($file);
        }
        return false;
    }

    /**
     * Extract plugin slug from repository name.
     *
     * @param string $repository Repository full name (owner/repo).
     * @return string Plugin slug.
     */
    private function extract_plugin_slug( string $repository ): string {
        $parts = explode( '/', $repository );
        return end( $parts );
    }

    /**
     * Normalize a slug or file/directory name for robust comparisons.
     */
    private function normalize_slug_str(string $s): string {
        $s = strtolower($s);
        // Remove non-alphanumeric to ignore separators like -, _, space
        return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
    }

    /**
     * Find plugin file for a given slug.
     *
     * Robust to case, separator differences, and minor prefix/suffix variations.
     *
     * ┌─────────────────────────────────────────────────────────────────────────────┐
     * │ ⚠️  CRITICAL FSM COMPONENT - DO NOT REFACTOR WITHOUT EXPLICIT APPROVAL ⚠️   │
     * ├─────────────────────────────────────────────────────────────────────────────┤
     * │ This method is essential for FSM state reconciliation and plugin matching. │
     * │ The fuzzy matching logic handles various naming conventions:                │
     * │   - KISS-woo-order-spc (repo) ↔ kiss-woo-order-spc (folder)               │
     * │   - Plugin_Name (repo) ↔ plugin-name (folder)                              │
     * │   - my-plugin (repo) ↔ MyPlugin (folder)                                   │
     * │                                                                             │
     * │ REGRESSION RISK: Simplifying this matching will cause plugins to show as   │
     * │ "Available" when they're actually installed, breaking the FSM state sync.  │
     * │                                                                             │
     * │ VALIDATED BY: Self-test "Plugin File Matching (Fuzzy)" in test suite 3     │
     * │ FIXED IN: v1.0.33 - GitHub issue with FSM state not updating after install │
     * └─────────────────────────────────────────────────────────────────────────────┘
     *
     * @param string $plugin_slug Plugin slug.
     * @return string Plugin file path or empty string if not found.
     */
    private function find_plugin_file( string $plugin_slug ): string {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $norm_slug = $this->normalize_slug_str($plugin_slug);
        $slug_lower = strtolower($plugin_slug);

        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            $plugin_dir = dirname( $plugin_file );
            $base_file = basename( $plugin_file, '.php' );

            $norm_dir = $this->normalize_slug_str($plugin_dir);
            $norm_base = $this->normalize_slug_str($base_file);

            // Match Type 1: Strong normalized match (removes all non-alphanumeric)
            // Example: "KISS-Plugin" normalized to "kissplugin" matches "kiss_plugin" folder
            if ( $norm_dir === $norm_slug || $norm_base === $norm_slug ) {
                return $plugin_file;
            }

            // Match Type 2: Prefix/suffix tolerance for minor variations
            // Example: "KISS-Plugin-Quick-Search" matches "kisspluginquicksearch" folder
            if ( str_starts_with( $norm_dir, $norm_slug ) || str_starts_with( $norm_slug, $norm_dir ) ) {
                return $plugin_file;
            }

            // Match Type 3: Case-insensitive with separator normalization (dash/underscore)
            // Example: "my_plugin" repo matches "my-plugin" folder
            $dir_lower = strtolower($plugin_dir);
            $slug_dash = str_replace( '_', '-', $slug_lower );
            $slug_underscore = str_replace( '-', '_', $slug_lower );
            $dir_dash = str_replace( '_', '-', $dir_lower );
            $dir_underscore = str_replace( '-', '_', $dir_lower );

            if ( $dir_lower === $slug_lower ||
                 $dir_dash === $slug_dash ||
                 $dir_underscore === $slug_underscore ) {
                return $plugin_file;
            }

            // Match Type 4: Substring containment (either direction)
            // Example: "woo-order-spc" matches "KISS-woo-order-spc" folder
            if ( stripos( $dir_lower, $slug_lower ) !== false ||
                 stripos( $slug_lower, $dir_lower ) !== false ) {
                return $plugin_file;
            }
        }

        return '';
    }

    /**
     * Fast cache-based heuristic for plugin presence using PQS cache only.
     * @param string $repository owner/repo
     * @return bool
     */
    private function check_cache_state( string $repository ): bool {
        $pqs_cache = $this->pqs_integration->get_cache();
        $plugin_slug = $this->extract_plugin_slug( $repository );
        return isset( $pqs_cache[ $plugin_slug ] );
    }

    /**
     * Detect plugin state when not installed.
     * Combines PQS cache and header scan to return a conservative PluginState.
     * @param string $repository owner/repo
     * @return PluginState
     */
    private function detect_plugin_state( string $repository ): PluginState {
        // 1) PQS cache (fast path)
        if ( $this->check_cache_state( $repository ) ) {
            return PluginState::AVAILABLE;
        }
        // 2) Authoritative detection path via wrapped detection service
        $slug = $this->extract_plugin_slug( $repository );
        $repo = [ 'full_name' => $repository, 'name' => $slug ];
        $det = $this->detect_plugin_info( $repo );
        if ( is_wp_error( $det ) ) {
            return PluginState::UNKNOWN;
        }
        if ( ! empty( $det['is_plugin'] ) ) {
            return PluginState::AVAILABLE;
        }
        // Conservative default: UNKNOWN when headers not found
        return PluginState::UNKNOWN;
    }

    /**
     * Wrapper for plugin detection to centralize calls and logging.
     * Preserves existing detailed debug behavior inside PluginDetectionService.
     *
     * @param array $repository Minimal repo array with keys: full_name, name, description?
     * @param bool $force_refresh Bypass detection cache
     * @return array|\WP_Error
     */
    public function detect_plugin_info( array $repository, bool $force_refresh = false ) {
        try {
            $res = $this->detection_service->detect_plugin( $repository, $force_refresh );
            // Log a compact breadcrumb for diagnostics without spamming logs
            $this->log_event(
                $repository['full_name'] ?? 'unknown',
                'detect_plugin',
                [
                    'result' => is_wp_error($res) ? 'error' : (( $res['is_plugin'] ?? false ) ? 'is_plugin' : 'not_plugin'),
                    'scan_method' => is_wp_error($res) ? 'wp_error' : ($res['scan_method'] ?? ''),
                ]
            );
            return $res;
        } catch ( \Throwable $e ) {
            $this->log_event( $repository['full_name'] ?? 'unknown', 'detect_plugin_error', [ 'message' => $e->getMessage() ] );
            return new \WP_Error( 'detection_failed', $e->getMessage() );
        }
    }


    /**
     * Load cached states from WordPress transients.
     * Also validates and reconciles states against actual WordPress installation status.
     *
     * ┌─────────────────────────────────────────────────────────────────────────────┐
     * │ ⚠️  CRITICAL FSM COMPONENT - DO NOT REFACTOR WITHOUT EXPLICIT APPROVAL ⚠️   │
     * ├─────────────────────────────────────────────────────────────────────────────┤
     * │ This method includes STATE RECONCILIATION which auto-heals stale FSM       │
     * │ states on load. This is essential for keeping FSM in sync with WordPress   │
     * │ when plugins are installed/activated/deleted outside our system.           │
     * │                                                                             │
     * │ VALIDATED BY: Self-test "FSM State Reconciliation" in test suite 3         │
     * │ FIXED IN: v1.0.33 - FSM state not syncing with WordPress reality           │
     * └─────────────────────────────────────────────────────────────────────────────┘
     */
    private function load_cached_states(): void {
        $cached_states = \get_transient( 'sbi_plugin_states' );
        $needs_save = false;

        if ( is_array( $cached_states ) ) {
            foreach ( $cached_states as $repository => $state_value ) {
                if ( is_string( $state_value ) ) {
                    $state = PluginState::tryFrom( $state_value );
                    if ( $state ) {
                        // Validate and reconcile state against WordPress reality
                        $reconciled_state = $this->reconcile_state_with_reality( $repository, $state );
                        if ( $reconciled_state !== $state ) {
                            $needs_save = true;
                        }
                        $this->states[ $repository ] = $reconciled_state;
                    }
                }
            }
        }

        // Save reconciled states if any changed
        if ( $needs_save ) {
            $this->save_cached_states();
        }
    }

    /**
     * Reconcile a cached FSM state with actual WordPress installation reality.
     * This ensures the FSM stays in sync when plugins are installed/activated outside our system.
     *
     * ┌─────────────────────────────────────────────────────────────────────────────┐
     * │ ⚠️  CRITICAL FSM COMPONENT - DO NOT REFACTOR WITHOUT EXPLICIT APPROVAL ⚠️   │
     * ├─────────────────────────────────────────────────────────────────────────────┤
     * │ This reconciliation handles 4 critical scenarios:                          │
     * │   Case 1: AVAILABLE → INSTALLED (plugin installed outside our system)      │
     * │   Case 2: INSTALLED → AVAILABLE (plugin deleted outside our system)        │
     * │   Case 3: INACTIVE → ACTIVE (plugin activated outside our system)          │
     * │   Case 4: ACTIVE → INACTIVE (plugin deactivated outside our system)        │
     * │                                                                             │
     * │ REGRESSION RISK: Removing any case will cause FSM to show wrong status     │
     * │ for plugins modified outside the batch installer UI.                       │
     * │                                                                             │
     * │ DEPENDS ON: find_plugin_file() fuzzy matching for accurate detection       │
     * │ VALIDATED BY: Self-test "FSM State Reconciliation" in test suite 3         │
     * │ FIXED IN: v1.0.33 - FSM state not syncing with WordPress reality           │
     * └─────────────────────────────────────────────────────────────────────────────┘
     *
     * @param string $repository Repository identifier (owner/repo).
     * @param PluginState $cached_state The state loaded from cache.
     * @return PluginState The reconciled state.
     */
    private function reconcile_state_with_reality( string $repository, PluginState $cached_state ): PluginState {
        // Only reconcile states that might be stale
        // UNKNOWN, CHECKING, NOT_PLUGIN, ERROR states don't need reconciliation with WP
        if ( in_array( $cached_state, [ PluginState::UNKNOWN, PluginState::CHECKING, PluginState::NOT_PLUGIN, PluginState::ERROR ], true ) ) {
            return $cached_state;
        }

        $plugin_slug = $this->extract_plugin_slug( $repository );
        $plugin_file = $this->find_plugin_file( $plugin_slug );

        // Case 1: Cached as AVAILABLE but plugin is actually installed
        if ( $cached_state === PluginState::AVAILABLE && ! empty( $plugin_file ) ) {
            if ( is_plugin_active( $plugin_file ) ) {
                return PluginState::INSTALLED_ACTIVE;
            }
            return PluginState::INSTALLED_INACTIVE;
        }

        // Case 2: Cached as INSTALLED but plugin is not actually installed (was deleted outside our system)
        if ( in_array( $cached_state, [ PluginState::INSTALLED_ACTIVE, PluginState::INSTALLED_INACTIVE ], true ) && empty( $plugin_file ) ) {
            return PluginState::AVAILABLE;
        }

        // Case 3: Cached as INSTALLED_INACTIVE but plugin is actually active
        if ( $cached_state === PluginState::INSTALLED_INACTIVE && ! empty( $plugin_file ) && is_plugin_active( $plugin_file ) ) {
            return PluginState::INSTALLED_ACTIVE;
        }

        // Case 4: Cached as INSTALLED_ACTIVE but plugin is actually inactive
        if ( $cached_state === PluginState::INSTALLED_ACTIVE && ! empty( $plugin_file ) && ! is_plugin_active( $plugin_file ) ) {
            return PluginState::INSTALLED_INACTIVE;
        }

        return $cached_state;
    }

    /**
     * Save current states to WordPress transients.
     */
    private function save_cached_states(): void {
        $states_for_cache = [];

        foreach ( $this->states as $repository => $state ) {
            $states_for_cache[ $repository ] = $state->value;
        }

        \set_transient( 'sbi_plugin_states', $states_for_cache, self::CACHE_EXPIRATION );
    }

    /**
     * Clear all cached states.
     */
    public function clear_cache(): void {
        $this->states = [];
        \delete_transient( 'sbi_plugin_states' );
    }

    /**
     * Clear cache for a specific repository.
     *
     * @param string $repository_full_name Repository full name (owner/repo).
     */
    public function clear_repository_cache( string $repository_full_name ): void {
        // Remove from memory cache
        unset( $this->states[ $repository_full_name ] );

        // Update persistent cache
        $cached_states = \get_transient( 'sbi_plugin_states' );
        if ( is_array( $cached_states ) && isset( $cached_states[ $repository_full_name ] ) ) {
            unset( $cached_states[ $repository_full_name ] );
            \set_transient( 'sbi_plugin_states', $cached_states, self::CACHE_EXPIRATION );
        }
    }

    /**
     * Get plugin file for a repository.
     *
     * @param string $repository_full_name Repository full name (owner/repo).
     * @return string|null Plugin file path or null if not found.
     */
    public function get_plugin_file( string $repository_full_name ): ?string {
        if ($this->isInstalled($repository_full_name)) {
            return $this->getInstalledPluginFile($repository_full_name);
        }
        return null;
    }

    /**
     * Get statistics about current states.
     *
     * @return array Statistics array.
     */
    public function get_statistics(): array {
        $stats = [
            'total' => count( $this->states ),
            'available' => 0,
            'installed_active' => 0,
            'installed_inactive' => 0,
            'not_plugin' => 0,
            'unknown' => 0,
            'error' => 0,
        ];

        foreach ( $this->states as $state ) {
            switch ( $state ) {
                case PluginState::AVAILABLE:
                    $stats['available']++;
                    break;
                case PluginState::INSTALLED_ACTIVE:
                    $stats['installed_active']++;
                    break;
                case PluginState::INSTALLED_INACTIVE:
                    $stats['installed_inactive']++;
                    break;
                case PluginState::NOT_PLUGIN:
                    $stats['not_plugin']++;
                    break;
                case PluginState::UNKNOWN:
                    $stats['unknown']++;
                    break;
                case PluginState::ERROR:
                    $stats['error']++;
                    break;
            }
        }

        return $stats;
    }

    // 🔍 FSM-FIRST REPOSITORY FILTERING METHODS

    /**
     * Set filter metadata for FSM-first filtering approach.
     *
     * @param array $filter_data Filter metadata to store.
     */
    public function set_filter_metadata(array $filter_data): void {
        $this->filter_metadata = array_merge($this->filter_metadata, $filter_data);
    }

    /**
     * Get current filter metadata.
     *
     * @param string|null $key Specific metadata key to retrieve.
     * @return mixed Filter metadata value or array of all metadata.
     */
    public function get_filter_metadata(?string $key = null) {
        if ($key !== null) {
            return $this->filter_metadata[$key] ?? null;
        }
        return $this->filter_metadata;
    }

    /**
     * Clear filter metadata.
     */
    public function clear_filter_metadata(): void {
        $this->filter_metadata = [];
    }

    /**
     * Check if a repository matches the current filter criteria.
     * Used by frontend FSM for consistent filtering logic.
     *
     * @param string $repository Repository identifier.
     * @param string $search_term Search term to match against.
     * @return bool True if repository matches filter.
     */
    public function repository_matches_filter(string $repository, string $search_term): bool {
        if (empty($search_term)) {
            return true;
        }

        $search_lower = strtolower(trim($search_term));
        $repo_lower = strtolower($repository);

        // Split repository into owner/name parts
        $parts = explode('/', $repository);
        $owner = $parts[0] ?? '';
        $name = $parts[1] ?? '';

        // Match against repository name, owner, or full name
        return strpos($repo_lower, $search_lower) !== false ||
               strpos(strtolower($name), $search_lower) !== false ||
               strpos(strtolower($owner), $search_lower) !== false;
    }

    /**
     * Get filtered repository list based on current filter metadata.
     * Maintains FSM state integrity while filtering display.
     *
     * @return array Array of repository identifiers that match current filter.
     */
    public function get_filtered_repositories(): array {
        $search_term = $this->get_filter_metadata('search_term');

        if (empty($search_term)) {
            return array_keys($this->states);
        }

        $filtered = [];
        foreach (array_keys($this->states) as $repository) {
            if ($this->repository_matches_filter($repository, $search_term)) {
                $filtered[] = $repository;
            }
        }

        return $filtered;
    }
}

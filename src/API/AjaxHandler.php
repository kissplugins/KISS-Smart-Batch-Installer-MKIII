<?php
/**
 * AJAX API handler for frontend interactions.
 *
 * @package SBI\API
 */

namespace SBI\API;

use WP_Error;
use SBI\Services\GitHubService;
use SBI\Services\PluginDetectionService;
use SBI\Services\PluginInstallationService;
use SBI\Services\StateManager;
use SBI\Services\ValidationGuardService;
use SBI\Services\AuditTrailService;
	use SBI\Services\LoggingService;
use SBI\Enums\PluginState;

/**
 * AJAX handler class.
 */
class AjaxHandler {

    /**
     * GitHub service.
     *
     * @var GitHubService
     */
    private GitHubService $github_service;

    /**
     * Plugin detection service.
     *
     * @var PluginDetectionService
     */
    private PluginDetectionService $detection_service;

    /**
     * Plugin installation service.
     *
     * @var PluginInstallationService
     */
    private PluginInstallationService $installation_service;

    /**
     * State manager.
     *
     * @var StateManager
     */
    private StateManager $state_manager;

    /**
     * Validation guard service.
     *
     * @var ValidationGuardService
     */
    private ValidationGuardService $validation_guard;

	/**
	 * Structured event logger (Phase 4 - Observability).
	 *
	 * @var LoggingService
	 */
	private LoggingService $logging_service;

    /**
     * Audit trail service (v1.0.35).
     *
     * @var AuditTrailService|null
     */
    private ?AuditTrailService $audit_trail = null;

    /**
     * Constructor.
     *
     * @param GitHubService              $github_service       GitHub service.
     * @param PluginDetectionService     $detection_service    Plugin detection service.
     * @param PluginInstallationService  $installation_service Plugin installation service.
     * @param StateManager              $state_manager        State manager.
     * @param ValidationGuardService     $validation_guard     Validation guard service.
	 * @param LoggingService            $logging_service      Structured logging service.
	 * @param AuditTrailService|null    $audit_trail          Audit trail service (optional).
     */
    public function __construct(
        GitHubService $github_service,
        PluginDetectionService $detection_service,
        PluginInstallationService $installation_service,
        StateManager $state_manager,
        ValidationGuardService $validation_guard,
	    LoggingService $logging_service,
        ?AuditTrailService $audit_trail = null
    ) {
        $this->github_service = $github_service;
        $this->detection_service = $detection_service;
        $this->installation_service = $installation_service;
        $this->state_manager = $state_manager;
        $this->validation_guard = $validation_guard;
	    $this->logging_service = $logging_service;
        $this->audit_trail = $audit_trail;
    }

    /**
     * Register AJAX hooks.
     */
    public function register_hooks(): void {
        // Repository actions
        add_action( 'wp_ajax_sbi_fetch_repositories', [ $this, 'fetch_repositories' ] );
        add_action( 'wp_ajax_sbi_fetch_repository_list', [ $this, 'fetch_repository_list' ] );
        add_action( 'wp_ajax_sbi_process_repository', [ $this, 'process_repository' ] );
        add_action( 'wp_ajax_sbi_render_repository_row', [ $this, 'render_repository_row' ] );
        add_action( 'wp_ajax_sbi_refresh_repository', [ $this, 'refresh_repository' ] );

        // Plugin actions
        add_action( 'wp_ajax_sbi_install_plugin', [ $this, 'install_plugin' ] );
        add_action( 'wp_ajax_sbi_activate_plugin', [ $this, 'activate_plugin' ] );
        add_action( 'wp_ajax_sbi_deactivate_plugin', [ $this, 'deactivate_plugin' ] );

        // Batch actions
        add_action( 'wp_ajax_sbi_batch_install', [ $this, 'batch_install' ] );
        add_action( 'wp_ajax_sbi_batch_activate', [ $this, 'batch_activate' ] );
        add_action( 'wp_ajax_sbi_batch_deactivate', [ $this, 'batch_deactivate' ] );

        // Debug action (temporary)
        add_action( 'wp_ajax_sbi_debug_detection', [ $this, 'debug_detection' ] );

        // Test actions
        add_action( 'wp_ajax_sbi_test_repository', [ $this, 'test_repository' ] );

        // Status actions
        add_action( 'wp_ajax_sbi_refresh_status', [ $this, 'refresh_status' ] );
        add_action( 'wp_ajax_sbi_get_installation_progress', [ $this, 'get_installation_progress' ] );
        add_action( 'wp_ajax_sbi_force_refresh_all', [ $this, 'force_refresh_all' ] );
        add_action( 'wp_ajax_sbi_view_cache', [ $this, 'view_cache' ] );
        add_action( 'wp_ajax_sbi_batch_load_cached', [ $this, 'batch_load_cached' ] );
        add_action( 'wp_ajax_sbi_batch_render_rows', [ $this, 'batch_render_rows' ] );

        // Experimental SSE stream for state changes (admin-only)
        add_action( 'wp_ajax_sbi_state_stream', [ $this, 'state_stream' ] );

        add_action( 'wp_ajax_sbi_test_sse', [ $this, 'test_sse' ] );
        // UI tips
        add_action( 'wp_ajax_sbi_dismiss_webonly_tip', [ $this, 'dismiss_webonly_tip' ] );
    }

    /**
     * Fetch repositories for GitHub account (organization or user).
     */
    public function fetch_repositories(): void {
        $this->verify_nonce_and_capability();

        $account_name = sanitize_text_field( $_POST['organization'] ?? '' );
        $force_refresh = (bool) ( $_POST['force_refresh'] ?? false );
        $limit = (int) ( $_POST['limit'] ?? 0 ); // 0 = no limit

        if ( empty( $account_name ) ) {
            wp_send_json_error( [
                'message' => __( 'Account name is required.', 'kiss-smart-batch-installer' )
            ] );
        }

        $repositories = $this->github_service->fetch_repositories_for_account( $account_name, $force_refresh, $limit );

        if ( is_wp_error( $repositories ) ) {
            wp_send_json_error( [
                'message' => $repositories->get_error_message()
            ] );
        }

        // Process repositories with detection enrichment; FSM is Single Source of Truth (SSoT)
        $processed_repos = [];
        foreach ( $repositories as $repo ) {
            $detection_result = $this->state_manager->detect_plugin_info( $repo );
            $state = $this->state_manager->get_state( $repo['full_name'] );

            // Derive canonical plugin flag from FSM state only
            $is_plugin_ssot = in_array( $state, [ PluginState::AVAILABLE, PluginState::INSTALLED_INACTIVE, PluginState::INSTALLED_ACTIVE ], true );

            $processed_repos[] = [
                'repository' => $repo,
                'is_plugin' => $is_plugin_ssot,
                'plugin_data' => ! is_wp_error( $detection_result ) ? ( $detection_result['plugin_data'] ?? [] ) : [],
                'state' => $state->value,
            ];
        }

        wp_send_json_success( [
            'repositories' => $processed_repos,
            'total' => count( $processed_repos ),
        ] );
    }

    /**
     * Fetch repository list without processing (for progressive loading).
     * Uses graceful degradation (v1.0.33) - returns stale cache when GitHub is unavailable.
     */
    public function fetch_repository_list(): void {
        $this->verify_nonce_and_capability();

        $account_name = sanitize_text_field( $_POST['organization'] ?? '' );
        $force_refresh = (bool) ( $_POST['force_refresh'] ?? false );
        $limit = (int) ( $_POST['limit'] ?? 0 ); // 0 = no limit

        // Debug logging
        error_log( sprintf( 'SBI AJAX: fetch_repository_list called for %s (limit: %d)', $account_name, $limit ) );

        if ( empty( $account_name ) ) {
            error_log( 'SBI AJAX: fetch_repository_list failed - account name is empty' );
            $this->send_enhanced_error(
                __( 'Account name is required.', 'kiss-smart-batch-installer' ),
                [ 'error_code' => 'missing_account_name' ]
            );
        }

        // Use graceful degradation - returns stale cache if fresh fetch fails (v1.0.33)
        // Now also returns data_source and cache_info (v1.0.34)
        $result = $this->github_service->fetch_repositories_graceful( $account_name, $force_refresh, $limit );
        $repositories = $result['repositories'];
        $is_stale = $result['is_stale'];
        $stale_age = $result['stale_age'];
        $fetch_error = $result['error'];
        $data_source = $result['data_source'] ?? 'unknown';
        $cache_info = $result['cache_info'] ?? [];

        // If no repositories at all and there was an error, report it
        if ( empty( $repositories ) && ! empty( $fetch_error ) ) {
            error_log( sprintf( 'SBI AJAX: fetch_repository_list failed for %s: %s (no cache available)', $account_name, $fetch_error ) );
            $this->send_enhanced_error(
                $fetch_error,
                [
                    'error_code' => 'github_api_error',
                    'account' => $account_name,
                    'no_cache' => true,
                    'cache_info' => $cache_info,
                ]
            );
        }

        // Best-effort: fetch total available public repos for checksum/visibility
        $total_available = $this->github_service->get_total_public_repos( $account_name );
        if ( is_wp_error( $total_available ) ) {
            error_log( sprintf( 'SBI AJAX: total public repos unavailable for %s: %s', $account_name, $total_available->get_error_message() ) );
            $total_available = null;
        }

        // Log data source and cache info (v1.0.34)
        error_log( sprintf( 'SBI AJAX: fetch_repository_list for %s - source: %s, is_stale: %s, cache_exists: %s',
            $account_name,
            $data_source,
            $is_stale ? 'yes' : 'no',
            ( $cache_info['transient_exists'] ?? false ) ? 'yes (' . ( $cache_info['cache_age_human'] ?? 'unknown age' ) . ')' : 'no'
        ) );

        if ( ! $is_stale ) {
            error_log( sprintf( 'SBI AJAX: fetch_repository_list success for %s - found %d repositories (limit %d, total_available %s)',
                $account_name, count( $repositories ), $limit, (null === $total_available ? 'n/a' : (string) $total_available) ) );
        }

        // Return repository data with staleness and source metadata (v1.0.33, v1.0.34)
        wp_send_json_success( [
            'repositories' => $repositories,
            'total' => count( $repositories ),
            'account' => $account_name,
            'total_available' => $total_available,
            'limit_used' => $limit,
            // Graceful degradation metadata (v1.0.33)
            'is_stale' => $is_stale,
            'stale_age' => $stale_age,
            'stale_age_human' => $stale_age ? human_time_diff( time() - $stale_age, time() ) . ' ago' : null,
            'fetch_error' => $fetch_error,
            // Data source tracking (v1.0.34)
            'data_source' => $data_source,
            'cache_info' => $cache_info,
        ] );
    }

    /**
     * Process a single repository (plugin detection and state management).
     *
     * v1.0.34: Added processing cache based on repo updated_at timestamp.
     * Cache hit returns instantly without GitHub API calls or delays.
     */
    public function process_repository(): void {
        $this->verify_nonce_and_capability();

        $repository = $_POST['repository'] ?? [];
        $repo_name = $repository['full_name'] ?? 'unknown';
        $repo_updated_at = $repository['updated_at'] ?? '';

        // Debug logging
        error_log( sprintf( 'SBI AJAX: process_repository called for %s', $repo_name ) );

        if ( empty( $repository ) || ! is_array( $repository ) ) {
            error_log( 'SBI AJAX: process_repository failed - repository data is empty or invalid' );
            wp_send_json_error( [
                'message' => __( 'Repository data is required.', 'kiss-smart-batch-installer' )
            ] );
        }

        // Sanitize repository data
        $repo = [
            'id' => intval( $repository['id'] ?? 0 ),
            'name' => sanitize_text_field( $repository['name'] ?? '' ),
            'full_name' => sanitize_text_field( $repository['full_name'] ?? '' ),
            'description' => sanitize_textarea_field( $repository['description'] ?? '' ),
            'html_url' => esc_url_raw( $repository['html_url'] ?? '' ),
            'clone_url' => esc_url_raw( $repository['clone_url'] ?? '' ),
            'updated_at' => sanitize_text_field( $repository['updated_at'] ?? '' ),
            'language' => sanitize_text_field( $repository['language'] ?? '' ),
        ];

        if ( empty( $repo['full_name'] ) ) {
            error_log( 'SBI AJAX: process_repository failed - repository full_name is empty' );
            wp_send_json_error( [
                'message' => __( 'Repository full name is required.', 'kiss-smart-batch-installer' )
            ] );
        }

        // v1.0.34: Check processing cache (based on updated_at timestamp)
        $cache_result = $this->get_processing_cache( $repo['full_name'], $repo['updated_at'] );
        if ( false !== $cache_result ) {
            error_log( sprintf( 'SBI AJAX: process_repository CACHE HIT for %s', $repo['full_name'] ) );
            wp_send_json_success( [
                'repository' => $cache_result,
                'from_cache' => true,
            ] );
            return;
        }

        // Cache miss - add delay to prevent overwhelming GitHub API
        error_log( sprintf( 'SBI AJAX: process_repository CACHE MISS for %s - fetching fresh', $repo['full_name'] ) );
        sleep( 1 ); // 1 second delay on server side only for fresh fetches

        error_log( sprintf( 'SBI AJAX: Starting plugin detection for %s', $repo['full_name'] ) );

        // Process repository with plugin detection (through StateManager wrapper)
        try {
            $detection_result = $this->state_manager->detect_plugin_info( $repo );
            $is_plugin = ! is_wp_error( $detection_result ) && $detection_result['is_plugin'];

            // FSM-first: refresh and read canonical state
            $this->state_manager->refresh_state( $repo['full_name'] );
            $state = $this->state_manager->get_state( $repo['full_name'] );

            // Compute plugin file information
            $plugin_slug = basename( $repo['full_name'] );
            $detected_plugin_file = ! is_wp_error( $detection_result ) ? ( $detection_result['plugin_file'] ?? '' ) : '';
            $installed_plugin_file = $this->find_installed_plugin( $plugin_slug );

            if ( ! empty( $installed_plugin_file ) ) {
                // Installed: align state with runtime activation to be extra safe
                // Use FSM as source of truth for installed state
                $state = $this->state_manager->get_state( $repo['full_name'], true );
                $plugin_file = $installed_plugin_file;
            } else {
                // Not installed: SAFEGUARD — if detection says plugin but FSM says NOT_PLUGIN, treat as AVAILABLE
                if ( ! is_wp_error( $detection_result ) && ( $detection_result['is_plugin'] ?? false ) && $state === PluginState::NOT_PLUGIN ) {
                    $state = PluginState::AVAILABLE;
                }
                $plugin_file = $detected_plugin_file;
            }

            // Derive is_plugin from FSM state (SSoT)
            $is_plugin_ssot = in_array( $state, [ PluginState::AVAILABLE, PluginState::INSTALLED_INACTIVE, PluginState::INSTALLED_ACTIVE ], true );

            $processed_repo = [
                'repository' => $repo,
                'is_plugin' => $is_plugin_ssot,
                'plugin_data' => ! is_wp_error( $detection_result ) ? ( $detection_result['plugin_data'] ?? [] ) : [],
                'plugin_file' => $plugin_file ?? '',  // Make sure plugin_file is always set
                'state' => $state->value,
                'scan_method' => ! is_wp_error( $detection_result ) ? ( $detection_result['scan_method'] ?? '' ) : '',
                'error' => is_wp_error( $detection_result ) ? $detection_result->get_error_message() : null,
                'detection_details' => ! is_wp_error( $detection_result ) ? [
                    'files_considered' => $detection_result['files_considered'] ?? [],
                    'files_scanned' => $detection_result['files_scanned'] ?? [],
                    'header_found' => $detection_result['header_found'] ?? false,
                ] : null,
            ];

            // v1.0.34: Save to processing cache
            $this->set_processing_cache( $repo['full_name'], $repo['updated_at'], $processed_repo );

            // Log successful processing for debugging
            error_log( sprintf( 'SBI: Successfully processed repository %s (cached)', $repo['full_name'] ) );

            wp_send_json_success( [
                'repository' => $processed_repo,
                'from_cache' => false,
            ] );
        } catch ( Exception $e ) {
            // Log the error for debugging
            error_log( sprintf( 'SBI: Error processing repository %s: %s', $repo['full_name'], $e->getMessage() ) );

            wp_send_json_error( [
                'message' => sprintf(
                    __( 'Failed to process repository %s: %s', 'kiss-smart-batch-installer' ),
                    $repo['name'],
                    $e->getMessage()
                )
            ] );
        }
    }

    /**
     * Get cached processing result for a repository.
     * Cache is invalidated when repo's updated_at changes.
     *
     * @since 1.0.34
     * @param string $full_name Repository full name (owner/repo).
     * @param string $updated_at Repository updated_at timestamp from GitHub.
     * @return array|false Cached result or false if not found/stale.
     */
    private function get_processing_cache( string $full_name, string $updated_at ) {
        if ( empty( $full_name ) ) {
            error_log( 'SBI: Processing cache - empty full_name' );
            return false;
        }

        $cache_key = 'sbi_repo_proc_' . sanitize_key( $full_name );
        $cached = get_transient( $cache_key );

        error_log( sprintf( 'SBI: Processing cache check for %s (key: %s) - found: %s',
            $full_name, $cache_key, $cached !== false ? 'YES' : 'NO' ) );

        if ( false === $cached || ! is_array( $cached ) ) {
            error_log( sprintf( 'SBI: Processing cache MISS for %s - no cached data', $full_name ) );
            return false;
        }

        // Check if repo has been updated since cache was created
        $cached_updated_at = $cached['_updated_at'] ?? '';
        error_log( sprintf( 'SBI: Processing cache for %s - cached_updated_at: "%s", current: "%s"',
            $full_name, $cached_updated_at, $updated_at ) );

        if ( ! empty( $updated_at ) && $cached_updated_at !== $updated_at ) {
            // Repo was updated - cache is stale
            error_log( sprintf( 'SBI: Processing cache STALE for %s (cached: %s, current: %s)',
                $full_name, $cached_updated_at, $updated_at ) );
            return false;
        }

        error_log( sprintf( 'SBI: Processing cache HIT for %s', $full_name ) );

        // Remove internal metadata before returning
        unset( $cached['_updated_at'], $cached['_cached_at'] );

        // CRITICAL FIX: Convert installation_state string back to PluginState enum
        // When saved to transient, enum is serialized to string value
        // NOTE: This conversion happens here, but when sent via JSON to frontend,
        // it will be serialized back to string. The batch_render_rows endpoint
        // will need to convert it back to enum again.
        if ( isset( $cached['installation_state'] ) && is_string( $cached['installation_state'] ) ) {
            $cached['installation_state'] = \SBI\Enums\PluginState::from( $cached['installation_state'] );
        }
        // Also fix in nested repository array if present
        if ( isset( $cached['repository']['installation_state'] ) && is_string( $cached['repository']['installation_state'] ) ) {
            $cached['repository']['installation_state'] = \SBI\Enums\PluginState::from( $cached['repository']['installation_state'] );
        }

        return $cached;
    }

    /**
     * Save processing result to cache.
     *
     * @since 1.0.34
     * @param string $full_name Repository full name (owner/repo).
     * @param string $updated_at Repository updated_at timestamp from GitHub.
     * @param array $result Processing result to cache.
     */
    private function set_processing_cache( string $full_name, string $updated_at, array $result ): void {
        if ( empty( $full_name ) ) {
            return;
        }

        $cache_key = 'sbi_repo_proc_' . sanitize_key( $full_name );

        // Convert PluginState enum to string value for serialization
        // WordPress transients serialize enums to strings, so we do it explicitly
        if ( isset( $result['installation_state'] ) && $result['installation_state'] instanceof \SBI\Enums\PluginState ) {
            $result['installation_state'] = $result['installation_state']->value;
        }
        if ( isset( $result['repository']['installation_state'] ) && $result['repository']['installation_state'] instanceof \SBI\Enums\PluginState ) {
            $result['repository']['installation_state'] = $result['repository']['installation_state']->value;
        }

        // Add metadata for cache validation
        $result['_updated_at'] = $updated_at;
        $result['_cached_at'] = time();

        // Cache for 7 days - will be invalidated by updated_at change anyway
        $saved = set_transient( $cache_key, $result, 7 * DAY_IN_SECONDS );
        error_log( sprintf( 'SBI: Processing cache SAVE for %s (key: %s, updated_at: "%s") - success: %s',
            $full_name, $cache_key, $updated_at, $saved ? 'YES' : 'NO' ) );
    }

    /**
     * v1.0.35: Invalidate the processing cache for a repository.
     * Called after install/activate/deactivate operations to ensure fresh state.
     *
     * @param string $full_name Repository full name (owner/repo).
     * @return bool True if cache was deleted, false otherwise.
     */
    public function invalidate_processing_cache( string $full_name ): bool {
        if ( empty( $full_name ) ) {
            return false;
        }

        $cache_key = 'sbi_repo_proc_' . sanitize_key( $full_name );
        $deleted = delete_transient( $cache_key );

        if ( $deleted ) {
            error_log( sprintf( 'SBI: Processing cache INVALIDATED for %s (key: %s)', $full_name, $cache_key ) );
        }

        return $deleted;
    }

    /**
     * Render a repository row HTML for progressive loading.
     */
    public function render_repository_row(): void {
        $this->verify_nonce_and_capability();

        $repository_data = $_POST['repository'] ?? [];
        $repo_name = $repository_data['repository']['full_name'] ?? 'unknown';

        // Debug logging
        error_log( sprintf( 'SBI AJAX: render_repository_row called for %s', $repo_name ) );

        if ( empty( $repository_data ) || ! is_array( $repository_data ) ) {
            error_log( 'SBI AJAX: render_repository_row failed - repository data is empty or invalid' );
            wp_send_json_error( [
                'message' => __( 'Repository data is required.', 'kiss-smart-batch-installer' )
            ] );
        }

        try {
            // Flatten the data structure to match what RepositoryListTable expects
            $repo_data = $repository_data['repository'] ?? [];
            $flattened_data = array_merge(
                $repo_data,
                [
                    'is_plugin' => $repository_data['is_plugin'] ?? false,
                    'plugin_data' => $repository_data['plugin_data'] ?? [],
                    'plugin_file' => $repository_data['plugin_file'] ?? '',
                    'installation_state' => \SBI\Enums\PluginState::from( $repository_data['state'] ?? 'unknown' ),
                    'full_name' => $repo_data['full_name'] ?? '',  // Ensure full_name is preserved
                    'name' => $repo_data['name'] ?? '',  // Ensure name is preserved
                ]
            );

            error_log( sprintf( 'SBI AJAX: Flattened data for %s: %s', $repo_name, json_encode( array_keys( $flattened_data ) ) ) );

            // Get the list table instance with proper dependencies
            $list_table = new \SBI\Admin\RepositoryListTable(
                $this->github_service,
                $this->detection_service,
                $this->state_manager
            );

            // Render the row HTML
            $row_html = $list_table->render_single_row( $flattened_data );

            error_log( sprintf( 'SBI AJAX: render_repository_row success for %s - HTML length: %d', $repo_name, strlen( $row_html ) ) );

            // Optional checksum echo-through if caller provided context
            $checksum = null;
            $is_last = isset( $_POST['is_last'] ) ? (bool) $_POST['is_last'] : false;
            $list_total = isset( $_POST['list_total'] ) ? intval( $_POST['list_total'] ) : 0;
            $limit_used = isset( $_POST['limit_used'] ) ? intval( $_POST['limit_used'] ) : 0;
            if ( $is_last ) {
                $account = explode( '/', $repo_name )[0] ?? '';
                $total_available = $this->github_service->get_total_public_repos( $account );
                if ( is_wp_error( $total_available ) ) {
                    $total_available = null;
                }
                $checksum = [
                    'account' => $account,
                    'list_total' => $list_total,
                    'limit_used' => $limit_used,
                    'total_available' => $total_available,
                ];
            }

            wp_send_json_success( [
                'row_html' => $row_html,
                'repository_id' => $repository_data['repository']['full_name'] ?? '',
                'checksum' => $checksum,
            ] );
        } catch ( Exception $e ) {
            error_log( sprintf( 'SBI AJAX: render_repository_row failed for %s: %s', $repo_name, $e->getMessage() ) );
            wp_send_json_error( [
                'message' => sprintf( 'Failed to render row: %s', $e->getMessage() )
            ] );
        }
    }

	/**
	 * Canonical row-authoritative refresh implementation.
	 *
	 * This is the single source of truth for "refresh" payload generation and is
	 * shared by:
	 * - sbi_refresh_repository (single)
	 * - sbi_refresh_status (batch wrapper)
	 *
	 * IMPORTANT: This method must remain FSM-first (StateManager is authoritative).
	 * v1.0.70
	 *
	 * @return array{repository:string,state:string,row_html:string}
	 */
	private function build_refresh_repository_payload( string $repo_name ): array {
	        $repo_name = sanitize_text_field( $repo_name );
	        $repo_owner = '';
	        $repo_slug  = '';
	        if ( strpos( $repo_name, '/' ) !== false ) {
	            [ $repo_owner, $repo_slug ] = explode( '/', $repo_name, 2 );
	        }

	        // Refresh state: use StateManager FSM
	        $this->state_manager->refresh_state( $repo_name );
	        $new_state = $this->state_manager->get_state( $repo_name );

	        // Build minimal repo structure to render row
	        $repo = [
	            'full_name'    => $repo_name,
	            'name'         => $repo_slug ?: $repo_name,
	            'owner'        => [ 'login' => $repo_owner ],
	            'description'  => '',
	        ];

	        // v1.0.36: Force refresh detection when state indicates plugin is not installed
	        // This prevents stale detection cache from showing "Active" when plugin was deleted
	        $force_detection_refresh = ! in_array( $new_state, [ PluginState::INSTALLED_ACTIVE, PluginState::INSTALLED_INACTIVE ], true );

	        // Enrich with detection metadata (best-effort; do not block on errors)
	        $det       = $this->state_manager->detect_plugin_info( $repo, $force_detection_refresh );
	        $is_plugin = ! is_wp_error( $det ) && ( $det['is_plugin'] ?? false );
	        $plugin_file = '';
	        $plugin_data = [];
	        if ( $is_plugin ) {
	            $plugin_file = $det['plugin_file'] ?? '';
	            $plugin_data = $det['plugin_data'] ?? [];
	        }

	        // v1.0.36: Invalidate processing cache on manual refresh to ensure fresh data
	        $this->invalidate_processing_cache( $repo_name );

	        // Render row via list table
	        $list_table = new \SBI\Admin\RepositoryListTable(
	            $this->github_service,
	            $this->detection_service,
	            $this->state_manager
	        );
	        $row_html = $list_table->render_single_row( array_merge( $repo, [
	            'is_plugin'           => $is_plugin,
	            'plugin_file'         => $plugin_file,
	            'plugin_data'         => $plugin_data,
	            'installation_state'  => $new_state,
	        ] ) );

	        return [
	            'repository' => $repo_name,
	            'state'      => $new_state->value,
	            'row_html'   => $row_html,
	        ];
	    }

	/**
	 * Refresh single repository status and return updated row HTML.
	 */
	public function refresh_repository(): void {
        $this->verify_nonce_and_capability();

        $repo_name = sanitize_text_field( $_POST['repository'] ?? '' );

        if ( empty( $repo_name ) ) {
            wp_send_json_error( [
                'message' => __( 'Repository name is required.', 'kiss-smart-batch-installer' )
            ] );
        }

	    try {
	        $payload = $this->build_refresh_repository_payload( $repo_name );
	        wp_send_json_success( $payload );
	    } catch ( Exception $e ) {
	        wp_send_json_error( [
	            'message' => sprintf( __( 'Refresh failed: %s', 'kiss-smart-batch-installer' ), $e->getMessage() ),
	        ] );
	    }
    }


    /**
     * Force refresh all repositories - clears all caches and triggers re-fetch.
     * v1.0.45: Added to provide a clean way to get fresh data from GitHub.
     */
    public function force_refresh_all(): void {
        $this->verify_nonce_and_capability();

        $account_name = get_option( 'sbi_github_organization', '' );
        if ( empty( $account_name ) ) {
            wp_send_json_error( [
                'message' => __( 'No GitHub organization configured.', 'kiss-smart-batch-installer' ),
            ] );
        }

        // Clear all caches
        $this->github_service->clear_cache( $account_name );
        $this->detection_service->clear_cache();
        $this->state_manager->clear_cache();

        // Also clear the stale cache option
        delete_option( 'sbi_github_repos_stale_' . sanitize_key( $account_name ) );
        delete_option( 'sbi_github_repos_permanent_' . sanitize_key( $account_name ) );

        // Clear all processing caches
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sbi_repo_proc_%'" );
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_sbi_repo_proc_%'" );

        wp_send_json_success( [
            'message' => __( 'All caches cleared. Page will reload with fresh data.', 'kiss-smart-batch-installer' ),
        ] );
    }

    /**
     * Batch load all cached repository data in one request.
     * v1.0.46: Cache-first architecture - loads ALL cached data instantly.
     *
     * This endpoint returns fully processed repository data from cache,
     * allowing the frontend to render the complete table immediately.
     */
    public function batch_load_cached(): void {
        $this->verify_nonce_and_capability();

        $account_name = sanitize_text_field( $_POST['organization'] ?? '' );
        $limit = (int) ( $_POST['limit'] ?? 0 );

        if ( empty( $account_name ) ) {
            wp_send_json_error( [
                'message' => __( 'Account name is required.', 'kiss-smart-batch-installer' ),
            ] );
        }

        error_log( sprintf( 'SBI AJAX: batch_load_cached called for %s (limit: %d)', $account_name, $limit ) );

        // Get repository list from cache (graceful degradation)
        $result = $this->github_service->fetch_repositories_graceful( $account_name, false, $limit );
        $repositories = $result['repositories'];
        $is_stale = $result['is_stale'];
        $data_source = $result['data_source'] ?? 'cache';
        $cache_info = $result['cache_info'] ?? [];

        if ( empty( $repositories ) ) {
            error_log( 'SBI AJAX: batch_load_cached - no repositories found in cache' );
            wp_send_json_success( [
                'repositories' => [],
                'total' => 0,
                'account' => $account_name,
                'data_source' => 'empty',
                'cache_info' => $cache_info,
            ] );
            return;
        }

        error_log( sprintf( 'SBI AJAX: batch_load_cached - processing %d repositories from %s', count( $repositories ), $data_source ) );

        // Process all repositories in batch using cached data
        $processed_repos = [];
        $cache_hits = 0;
        $cache_misses = 0;

        foreach ( $repositories as $repo ) {
            $repo_full_name = $repo['full_name'] ?? '';
            $repo_updated_at = $repo['updated_at'] ?? '';

            if ( empty( $repo_full_name ) ) {
                continue;
            }

            // Try to get from processing cache first
            $cached_result = $this->get_processing_cache( $repo_full_name, $repo_updated_at );

            if ( false !== $cached_result ) {
                // Cache hit - use cached processed data
                $processed_repos[] = $cached_result;
                $cache_hits++;
                continue;
            }

            // Cache miss - process now (but don't add delay since we're batch loading)
            $cache_misses++;

            try {
                $detection_result = $this->state_manager->detect_plugin_info( $repo );
                $is_plugin = ! is_wp_error( $detection_result ) && $detection_result['is_plugin'];

                // Refresh state
                $this->state_manager->refresh_state( $repo_full_name );
                $state = $this->state_manager->get_state( $repo_full_name );

                // PHASE 1 LOGGING: Log if state is still UNKNOWN/CHECKING after refresh
                // This helps us understand why detection might be failing
                if ( in_array( $state, [ PluginState::UNKNOWN, PluginState::CHECKING ], true ) ) {
                    error_log( sprintf(
                        'SBI Phase 1: State is %s for %s after refresh_state (is_plugin: %s, detection_error: %s)',
                        $state->value ?? 'unknown',
                        $repo_full_name,
                        $is_plugin ? 'true' : 'false',
                        is_wp_error( $detection_result ) ? $detection_result->get_error_message() : 'none'
                    ) );
                }

                $plugin_slug = basename( $repo_full_name );
                $plugin_file = '';
                $plugin_data = [];

                if ( $is_plugin && ! is_wp_error( $detection_result ) ) {
                    $plugin_file = $detection_result['plugin_file'] ?? '';
                    $plugin_data = $detection_result['plugin_data'] ?? [];
                }

                $processed_repo = [
                    'repository' => array_merge( $repo, [
                        'is_plugin' => $is_plugin,
                        'plugin_file' => $plugin_file,
                        'plugin_data' => $plugin_data,
                        'installation_state' => $state,
                    ] ),
                    'is_plugin' => $is_plugin,
                    'plugin_file' => $plugin_file,
                    'plugin_data' => $plugin_data,
                    'installation_state' => $state,
                ];

                // Save to processing cache for next time
                $this->set_processing_cache( $repo_full_name, $repo_updated_at, $processed_repo );

                $processed_repos[] = $processed_repo;

            } catch ( Exception $e ) {
                error_log( sprintf( 'SBI: Error batch processing repository %s: %s', $repo_full_name, $e->getMessage() ) );
                // Skip this repo and continue with others
                continue;
            }
        }

        error_log( sprintf( 'SBI AJAX: batch_load_cached complete - %d repos processed (%d cache hits, %d cache misses)',
            count( $processed_repos ), $cache_hits, $cache_misses ) );

        wp_send_json_success( [
            'repositories' => $processed_repos,
            'total' => count( $processed_repos ),
            'account' => $account_name,
            'data_source' => $data_source,
            'is_stale' => $is_stale,
            'cache_info' => $cache_info,
            'cache_stats' => [
                'hits' => $cache_hits,
                'misses' => $cache_misses,
                'hit_rate' => count( $repositories ) > 0 ? round( ( $cache_hits / count( $repositories ) ) * 100, 1 ) : 0,
            ],
        ] );
    }

    /**
     * Batch render all repository rows HTML in one request.
     * v1.0.50: Optimized rendering - returns ALL row HTML at once instead of 50+ individual requests.
     */
    public function batch_render_rows(): void {
        $this->verify_nonce_and_capability();

        $repositories = $_POST['repositories'] ?? [];

        if ( empty( $repositories ) || ! is_array( $repositories ) ) {
            wp_send_json_error( [
                'message' => __( 'Repositories data is required.', 'kiss-smart-batch-installer' ),
            ] );
        }

        error_log( sprintf( 'SBI AJAX: batch_render_rows called for %d repositories', count( $repositories ) ) );

        try {
            // Get the list table instance
            $list_table = new \SBI\Admin\RepositoryListTable(
                $this->github_service,
                $this->detection_service,
                $this->state_manager
            );

            $rows_html = [];
            $total = count( $repositories );

            foreach ( $repositories as $index => $item ) {
                $repo = $item['repository'] ?? [];
                $is_last = ( $index === $total - 1 );

                // Flatten the data structure to match what RepositoryListTable expects
                // CRITICAL FIX: When data comes from JavaScript (via JSON), enums are serialized to strings
                // We need to convert them back to PluginState enums
                $installation_state = $item['installation_state'] ?? \SBI\Enums\PluginState::UNKNOWN;

                // Convert string to enum if needed
                if ( is_string( $installation_state ) ) {
                    try {
                        $installation_state = \SBI\Enums\PluginState::from( $installation_state );
                    } catch ( \ValueError $e ) {
                        error_log( sprintf(
                            'SBI: Invalid installation_state value "%s" for %s - using UNKNOWN',
                            $installation_state,
                            $repo['full_name'] ?? 'unknown'
                        ) );
                        $installation_state = \SBI\Enums\PluginState::UNKNOWN;
                    }
                }

                // PHASE 1 LOGGING: Log type validation issues
                if ( ! ( $installation_state instanceof \SBI\Enums\PluginState ) ) {
                    error_log( sprintf(
                        'SBI Phase 1: Invalid installation_state type for %s: %s (expected PluginState enum) - using UNKNOWN',
                        $repo['full_name'] ?? 'unknown',
                        gettype( $installation_state )
                    ) );
                    $installation_state = \SBI\Enums\PluginState::UNKNOWN;
                }

                // PHASE 1 LOGGING: Log if rendering UNKNOWN or CHECKING states
                if ( in_array( $installation_state, [ \SBI\Enums\PluginState::UNKNOWN, \SBI\Enums\PluginState::CHECKING ], true ) ) {
                    error_log( sprintf(
                        'SBI Phase 1: Rendering %s state for %s (is_plugin: %s)',
                        $installation_state->value ?? 'unknown',
                        $repo['full_name'] ?? 'unknown',
                        isset( $item['is_plugin'] ) ? ( $item['is_plugin'] ? 'true' : 'false' ) : 'not set'
                    ) );
                }

                // Also convert installation_state in nested repository array if present
                if ( isset( $repo['installation_state'] ) && is_string( $repo['installation_state'] ) ) {
                    try {
                        $repo['installation_state'] = \SBI\Enums\PluginState::from( $repo['installation_state'] );
                    } catch ( \ValueError $e ) {
                        $repo['installation_state'] = \SBI\Enums\PluginState::UNKNOWN;
                    }
                }

                $flattened_data = array_merge(
                    $repo,
                    [
                        'is_plugin' => $item['is_plugin'] ?? false,
                        'plugin_data' => $item['plugin_data'] ?? [],
                        'plugin_file' => $item['plugin_file'] ?? '',
                        'installation_state' => $installation_state,
                        'full_name' => $repo['full_name'] ?? '',
                        'name' => $repo['name'] ?? '',
                    ]
                );

                // Render the row HTML
                $row_html = $list_table->render_single_row( $flattened_data );
                $rows_html[] = $row_html;
            }

            error_log( sprintf( 'SBI AJAX: batch_render_rows success - rendered %d rows', count( $rows_html ) ) );

            wp_send_json_success( [
                'rows_html' => $rows_html,
                'total' => $total,
            ] );

        } catch ( Exception $e ) {
            error_log( sprintf( 'SBI: Error batch rendering rows: %s', $e->getMessage() ) );
            wp_send_json_error( [
                'message' => sprintf( __( 'Failed to render rows: %s', 'kiss-smart-batch-installer' ), $e->getMessage() ),
            ] );
        }
    }

    /**
     * View cached repository data for debugging.
     */
    public function view_cache(): void {
        $this->verify_nonce_and_capability();

        $account_name = isset( $_POST['account_name'] ) ? sanitize_text_field( wp_unslash( $_POST['account_name'] ) ) : '';
        $filter_repo = isset( $_POST['filter_repo'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_repo'] ) ) : '';

	        if ( empty( $account_name ) ) {
	            // v1.0.61: Use primary organization option with legacy fallback
	            $account_name = get_option( 'sbi_github_organization', '' );
	
	            if ( empty( $account_name ) ) {
	                // Backward compatibility for older installs
	                $account_name = get_option( 'sbi_github_account', '' );
	            }
	        }

        $cache_key = 'sbi_github_repos_v2_' . sanitize_key( $account_name );
        $cached_data = get_transient( $cache_key );

        $cache_info = [
            'cache_key' => $cache_key,
            'account_name' => $account_name,
            'cache_exists' => $cached_data !== false,
            'total_repos' => 0,
            'repos' => [],
        ];

	        if ( $cached_data !== false ) {
	            // v1.0.61: Support both legacy (['repos' => [...]]) and v2 flat array caches
	            $repos_for_display = [];
	
	            if ( isset( $cached_data['repos'] ) && is_array( $cached_data['repos'] ) ) {
	                // Legacy format used before v2 cache key rollout
	                $repos_for_display = $cached_data['repos'];
	                $cache_info['cached_at'] = isset( $cached_data['cached_at'] ) ? $cached_data['cached_at'] : 'unknown';
	                $cache_info['source'] = isset( $cached_data['source'] ) ? $cached_data['source'] : 'unknown';
	            } elseif ( is_array( $cached_data ) ) {
	                // v2 format: transient stores the processed repository list directly
	                $repos_for_display = $cached_data;
	                if ( ! isset( $cache_info['cached_at'] ) ) {
	                    $cache_info['cached_at'] = 'unknown';
	                }
	                if ( ! isset( $cache_info['source'] ) ) {
	                    $cache_info['source'] = 'unknown';
	                }
	            }
	
	            $cache_info['total_repos'] = count( $repos_for_display );
	
	            // Filter or limit repos for display
	            foreach ( $repos_for_display as $repo ) {
	                // If filter provided, only include matching repos
	                if ( ! empty( $filter_repo ) ) {
	                    if ( empty( $repo['name'] ) || stripos( $repo['name'], $filter_repo ) === false ) {
	                        continue;
	                    }
	                }
	
	                // Include key fields for debugging timestamp issues
	                $cache_info['repos'][] = [
	                    'name' => $repo['name'] ?? '',
	                    'full_name' => $repo['full_name'] ?? '',
	                    'updated_at' => $repo['updated_at'] ?? 'NOT SET',
	                    'updated_at_raw' => isset( $repo['updated_at'] ) ? $repo['updated_at'] : 'NOT SET',
	                    'description' => mb_substr( $repo['description'] ?? '', 0, 80 ),
	                    'language' => $repo['language'] ?? '',
	                    'default_branch' => $repo['default_branch'] ?? '',
	                ];
	            }
	        }

        wp_send_json_success( $cache_info );
    }

    /**
     * Persist dismissal of the web-only DNS tip.
     */
    public function dismiss_webonly_tip(): void {
        $this->verify_nonce_and_capability();
        update_option( 'sbi_web_only_tip_dismissed', 1 );
        wp_send_json_success();
    }

    /**
     * Install plugin from repository.
     */
    public function install_plugin(): void {
        // Increase memory limit for installation
        $original_memory_limit = ini_get( 'memory_limit' );
        if ( function_exists( 'ini_set' ) ) {
            ini_set( 'memory_limit', '512M' );
        }

        // Clean output buffer to prevent issues
        if ( ob_get_level() ) {
            ob_clean();
        }

        $debug_steps = [];
        $start_time = microtime( true );

        try {
            // Step 1: Security verification
            $debug_steps[] = [
                'step' => 'Security Verification',
                'status' => 'starting',
                'time' => round( ( microtime( true ) - $start_time ) * 1000, 2 )
            ];

            $this->send_progress_update( 'Security Verification', 'info', 'Verifying nonce and user permissions...' );
            $this->verify_nonce_and_capability();

            $debug_steps[] = [
                'step' => 'Security Verification',
                'status' => 'completed',
                'message' => 'Nonce and capability checks passed',
                'time' => round( ( microtime( true ) - $start_time ) * 1000, 2 )
            ];

            // Step 2: Pre-Installation Validation Guards
            $debug_steps[] = [
                'step' => 'Pre-Installation Validation',
                'status' => 'starting',
                'time' => round( ( microtime( true ) - $start_time ) * 1000, 2 )
            ];

            $this->send_progress_update( 'Pre-Installation Validation', 'info', 'Running comprehensive validation checks...' );

            // Extract parameters for validation
            $owner = sanitize_text_field( $_POST['owner'] ?? '' );
            $repo = sanitize_text_field( $_POST['repository'] ?? '' );

            // Run comprehensive pre-validation
            $validation_result = $this->validation_guard->validate_installation_prerequisites( $owner, $repo );

            if ( ! $validation_result['success'] ) {
                $debug_steps[] = [
                    'step' => 'Pre-Installation Validation',
                    'status' => 'failed',
                    'message' => 'Validation checks failed',
                    'validation_summary' => $validation_result['summary'],
                    'time' => round( ( microtime( true ) - $start_time ) * 1000, 2 )
                ];

                // Generate detailed validation failure message
                $failed_validations = [];
                $error_details = [];

                foreach ( $validation_result['validations'] as $category => $result ) {
                    if ( ! $result['success'] ) {
                        $failed_validations[] = ucfirst( str_replace( '_', ' ', $category ) );

                        // Collect specific errors for each category
                        if ( ! empty( $result['errors'] ) ) {
                            $error_details[ $category ] = $result['errors'];
                        }
                    }
                }

                // Build detailed error message with specific failures
                $error_details_text = [];
                foreach ( $error_details as $category => $errors ) {
                    if ( ! empty( $errors ) ) {
                        $error_details_text[] = sprintf( '%s: %s', ucfirst( $category ), implode( '; ', $errors ) );
                    }
                }

                $detailed_message = sprintf(
                    'Installation prerequisites not met. Failed validations: %s. Details: %s',
                    implode( ', ', $failed_validations ),
                    implode( ' | ', $error_details_text )
                );

                // Add specific error details to debug steps
                $debug_steps[] = [
                    'step' => 'Validation Failure Details',
                    'status' => 'failed',
                    'message' => sprintf(
                        '%d/%d validation checks failed',
                        $validation_result['summary']['failed_checks'],
                        $validation_result['summary']['total_checks']
                    ),
                    'failed_categories' => $failed_validations,
                    'error_details' => $error_details,
                    'recommendations' => $validation_result['recommendations'],
                    'time' => round( ( microtime( true ) - $start_time ) * 1000, 2 )
                ];

                // Send detailed validation error
                $this->send_enhanced_error(
                    $detailed_message,
                    [
                        'error_code' => 'validation_failed',
                        'validation_results' => $validation_result,
                        'debug_steps' => $debug_steps,
                        'failed_validations' => $failed_validations,
                        'error_details' => $error_details
                    ]
                );
            }

            $debug_steps[] = [
                'step' => 'Pre-Installation Validation',
                'status' => 'completed',
                'message' => sprintf(
                    'All validation checks passed (%d/%d checks successful)',
                    $validation_result['summary']['passed_checks'],
                    $validation_result['summary']['total_checks']
                ),
                'validation_summary' => $validation_result['summary'],
                'time' => round( ( microtime( true ) - $start_time ) * 1000, 2 )
            ];

            $this->send_progress_update( 'Security Verification', 'success', 'Security checks passed' );

            // Step 2: Parameter validation
            $debug_steps[] = [
                'step' => 'Parameter Validation',
                'status' => 'starting',
                'time' => round( ( microtime( true ) - $start_time ) * 1000, 2 )
            ];

            $this->send_progress_update( 'Parameter Validation', 'info', 'Validating installation parameters...' );

            $repo_name = sanitize_text_field( $_POST['repository'] ?? '' );
            $owner = sanitize_text_field( $_POST['owner'] ?? '' );
            $activate = (bool) ( $_POST['activate'] ?? false );

            error_log( sprintf( 'SBI INSTALL: Starting installation for %s/%s (activate: %s)',
                $owner, $repo_name, $activate ? 'yes' : 'no' ) );

            if ( empty( $repo_name ) ) {
                $debug_steps[] = [
                    'step' => 'Parameter Validation',
                    'status' => 'failed',
                    'error' => 'Repository name is required',
                    'time' => round( ( microtime( true ) - $start_time ) * 1000, 2 )
                ];

                $this->send_progress_update( 'Parameter Validation', 'error', 'Repository name is required' );

                wp_send_json_error( [
                    'message' => __( 'Repository name is required.', 'kiss-smart-batch-installer' ),
                    'debug_steps' => $debug_steps,
                    'progress_updates' => $this->progress_updates
                ] );
            }

            if ( empty( $owner ) ) {
                $debug_steps[] = [
                    'step' => 'Parameter Validation',
                    'status' => 'failed',
                    'error' => 'Repository owner is required',
                    'time' => round( ( microtime( true ) - $start_time ) * 1000, 2 )
                ];

                $this->send_progress_update( 'Parameter Validation', 'error', 'Repository owner is required' );

                wp_send_json_error( [
                    'message' => __( 'Repository owner is required.', 'kiss-smart-batch-installer' ),
                    'debug_steps' => $debug_steps,
                    'progress_updates' => $this->progress_updates
                ] );
            }

            $debug_steps[] = [
                'step' => 'Parameter Validation',
                'status' => 'completed',
                'message' => sprintf( 'Repository: %s/%s, Activate: %s', $owner, $repo_name, $activate ? 'yes' : 'no' ),
                'time' => round( ( microtime( true ) - $start_time ) * 1000, 2 )
            ];

            $this->send_progress_update( 'Parameter Validation', 'success', "Validated parameters for {$owner}/{$repo_name}" );

            // Step 3: Plugin installation (with processing lock)
            $debug_steps[] = [
                'step' => 'Plugin Installation',
                'status' => 'starting',
                'message' => 'Calling installation service',
                'time' => round( ( microtime( true ) - $start_time ) * 1000, 2 )
            ];

            $this->send_progress_update( 'Plugin Installation', 'info', "Starting installation of {$owner}/{$repo_name}..." );

            error_log( sprintf( 'SBI INSTALL: Calling installation service for %s/%s', $owner, $repo_name ) );

            $repo_full = sprintf('%s/%s', $owner, $repo_name);
            if (! $this->state_manager->acquire_processing_lock($repo_full)) {
                $debug_steps[] = [
                    'step' => 'Plugin Installation',
                    'status' => 'failed',
                    'error' => 'Another operation is in progress for this repository',
                    'time' => round( ( microtime( true ) - $start_time ) * 1000, 2 )
                ];
                wp_send_json_error([
                    'message' => __( 'Another operation is in progress for this repository. Please try again shortly.', 'kiss-smart-batch-installer' ),
                    'repository' => $repo_name,
                    'debug_steps' => $debug_steps,
                    'progress_updates' => $this->progress_updates,
                ]);
            }

            // Ensure lock release
            try {
                // Set progress callback for the installation service using a closure.
                $this->installation_service->set_progress_callback( function( string $step, string $status, string $message = '' ): void {
                    $this->send_progress_update( $step, $status, $message );
                } );

                // NOTE TO FUTURE CONTRIBUTORS AND LLMs:
                // The debug_steps/progress_updates and enhanced error mapping below are
                // critical for field debugging. Do NOT remove or simplify. If you must
                // refactor, preserve equivalent structured debug output.
                // Buffer any stray output from WordPress upgrader/skin to keep JSON clean
                ob_start();
                $result = $this->installation_service->install_and_activate( $owner, $repo_name, $activate );
                $suppressed_output = ob_get_clean();
                if ( ! empty( $suppressed_output ) ) {
                    error_log( 'SBI INSTALL: Suppressed output during install: ' . substr( $suppressed_output, 0, 2000 ) );
                }
            } finally {
                $this->state_manager->release_processing_lock($repo_full);
            }

            if ( is_wp_error( $result ) ) {
                $error_code = $result->get_error_code();
                $error_message = $result->get_error_message();

                // Enhanced error message for 404 errors
                if ( $error_code === 'github_api_error' && strpos( $error_message, '404' ) !== false ) {
                    $enhanced_message = sprintf(
                        'Repository %s/%s not found. This could mean: 1) Repository doesn\'t exist, 2) Repository is private, 3) Repository name is incorrect, or 4) GitHub API is temporarily unavailable.',
                        $owner,
                        $repo_name
                    );
                } else {
                    $enhanced_message = $error_message;
                }

                $debug_steps[] = [
                    'step' => 'Plugin Installation',
                    'status' => 'failed',
                    'error' => $enhanced_message,
                    'original_error' => $error_message,
                    'error_code' => $error_code,
                    'repository_url' => sprintf( 'https://github.com/%s/%s', $owner, $repo_name ),
                    'api_url' => sprintf( 'https://api.github.com/repos/%s/%s', $owner, $repo_name ),
                    'time' => round( ( microtime( true ) - $start_time ) * 1000, 2 )
                ];

                // FSM: mark repository as error
                $this->state_manager->transition( sprintf('%s/%s', $owner, $repo_name), PluginState::ERROR, [ 'source' => 'ajax_install', 'error_code' => $error_code ] );

                $this->send_progress_update( 'Plugin Installation', 'error', 'Installation failed: ' . $enhanced_message );

                error_log( sprintf( 'SBI INSTALL: Installation failed for %s/%s: %s (Code: %s)',
                    $owner, $repo_name, $error_message, $error_code ) );

                // Get error data BEFORE audit trail to include upgrader messages
                $error_data = $result->get_error_data();
                $upgrader_messages = is_array( $error_data ) && isset( $error_data['messages'] ) ? $error_data['messages'] : [];

                // v1.0.35/v1.0.47: Record failed installation in audit trail with full details
                if ( $this->audit_trail ) {
                    $this->audit_trail->record_install( "{$owner}/{$repo_name}", AuditTrailService::RESULT_FAILURE, [
                        'error_code' => $error_code,
                        'error_message' => $error_message,
                        'upgrader_messages' => ! empty( $upgrader_messages ) ? implode( '; ', $upgrader_messages ) : '',
                        'download_url' => is_array( $error_data ) && isset( $error_data['download_url'] ) ? $error_data['download_url'] : '',
                    ] );
                }
                wp_send_json_error( [
                    'message' => $enhanced_message,
                    'repository' => $repo_name,
                    'debug_steps' => $debug_steps,
                    'progress_updates' => $this->progress_updates,
                    'upgrader_messages' => $upgrader_messages,
                    'download_url' => is_array( $error_data ) && isset( $error_data['download_url'] ) ? $error_data['download_url'] : null,
                    'troubleshooting' => [
                        'check_repository_exists' => sprintf( 'https://github.com/%s/%s', $owner, $repo_name ),
                        'verify_repository_public' => 'Make sure the repository is public',
                        'check_spelling' => 'Verify owner and repository names are correct'
                    ]
                ] );
            }

            $debug_steps[] = [
                'step' => 'Plugin Installation',
                'status' => 'completed',
                'message' => 'Installation completed successfully',
                'result_data' => $result,
                'time' => round( ( microtime( true ) - $start_time ) * 1000, 2 )
            ];

            $this->send_progress_update( 'Plugin Installation', 'success', "Successfully installed {$owner}/{$repo_name}" );

            // Note: FSM transitions are now handled directly by PluginInstallationService

            error_log( sprintf( 'SBI INSTALL: Installation successful for %s/%s', $owner, $repo_name ) );

            // Step 4: Success response
            $total_time = round( ( microtime( true ) - $start_time ) * 1000, 2 );

            // Clean up memory before sending response
            if ( function_exists( 'gc_collect_cycles' ) ) {
                gc_collect_cycles();
            }

            // Restore original memory limit
            if ( function_exists( 'ini_set' ) && isset( $original_memory_limit ) ) {
                ini_set( 'memory_limit', $original_memory_limit );
            }

            // v1.0.35: Record successful installation in audit trail
            if ( $this->audit_trail ) {
                $this->audit_trail->record_install( "{$owner}/{$repo_name}", AuditTrailService::RESULT_SUCCESS, [
                    'activated' => $activate,
                    'plugin_file' => $result['plugin_file'] ?? null,
                    'total_time_ms' => $total_time,
                ] );
            }

            // v1.0.35: Invalidate processing cache for this repository (state changed)
            $this->invalidate_processing_cache( "{$owner}/{$repo_name}" );

            wp_send_json_success( array_merge( $result, [
                'message' => sprintf(
                    __( 'Plugin %s installed successfully.', 'kiss-smart-batch-installer' ),
                    $repo_name
                ),
                'repository' => $repo_name,
                'debug_steps' => $debug_steps,
                'progress_updates' => $this->progress_updates,
                'total_time' => $total_time
            ] ) );

        } catch ( Exception $e ) {
            $debug_steps[] = [
                'step' => 'Exception Handler',
                'status' => 'failed',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'time' => round( ( microtime( true ) - $start_time ) * 1000, 2 )
            ];

            error_log( sprintf( 'SBI INSTALL: Exception during installation of %s/%s: %s',
                $owner ?? 'unknown', $repo_name ?? 'unknown', $e->getMessage() ) );

            // Clean up memory before sending error response
            if ( function_exists( 'gc_collect_cycles' ) ) {
                gc_collect_cycles();
            }

            // Restore original memory limit
            if ( function_exists( 'ini_set' ) && isset( $original_memory_limit ) ) {
                ini_set( 'memory_limit', $original_memory_limit );
            }

            wp_send_json_error( [
                'message' => sprintf( 'Installation failed: %s', $e->getMessage() ),
                'repository' => $repo_name ?? 'unknown',
                'debug_steps' => $debug_steps,
                'progress_updates' => $this->progress_updates
            ] );
        }
    }

    /**
     * Activate plugin.
     */
    public function activate_plugin(): void {
        $this->verify_nonce_and_capability();

        $plugin_file = sanitize_text_field( $_POST['plugin_file'] ?? '' );
        $repo_name = sanitize_text_field( $_POST['repository'] ?? '' );

        if ( empty( $plugin_file ) ) {
            $this->send_enhanced_error(
                __( 'Plugin file is required.', 'kiss-smart-batch-installer' ),
                [ 'error_code' => 'missing_plugin_file' ]
            );
        }

        // Pre-Activation Validation Guards
        $validation_result = $this->validation_guard->validate_activation_prerequisites( $plugin_file, $repo_name );

        if ( ! $validation_result['success'] ) {
            // Generate detailed activation failure message
            $failed_validations = [];
            $error_details = [];

            foreach ( $validation_result['validations'] as $category => $result ) {
                if ( ! $result['success'] ) {
                    $failed_validations[] = ucfirst( str_replace( '_', ' ', $category ) );

                    // Collect specific errors for each category
                    if ( ! empty( $result['errors'] ) ) {
                        $error_details[ $category ] = $result['errors'];
                    }
                }
            }

            $detailed_message = sprintf(
                'Activation prerequisites not met. Failed validations: %s',
                implode( ', ', $failed_validations )
            );

            $this->send_enhanced_error(
                $detailed_message,
                [
                    'error_code' => 'activation_validation_failed',
                    'validation_results' => $validation_result,
                    'plugin_file' => $plugin_file,
                    'repository' => $repo_name,
                    'failed_validations' => $failed_validations,
                    'error_details' => $error_details
                ]
            );
        }

        $repo_full = $repo_name;
        if (! empty($repo_full) && ! $this->state_manager->acquire_processing_lock($repo_full)) {
            wp_send_json_error([
                'message' => __( 'Another operation is in progress for this repository. Please try again shortly.', 'kiss-smart-batch-installer' ),
                'repository' => $repo_name,
            ]);
        }

        try {
            // Activate the plugin
            $result = $this->installation_service->activate_plugin( $plugin_file );

            if ( is_wp_error( $result ) ) {
                // FSM: mark error state for this repo
                if ( ! empty( $repo_name ) ) {
                    $this->state_manager->transition( $repo_name, PluginState::ERROR, [ 'source' => 'ajax_activate' ] );
                }

                // v1.0.35: Record failed activation in audit trail
                if ( $this->audit_trail && ! empty( $repo_name ) ) {
                    $this->audit_trail->record_activate( $repo_name, AuditTrailService::RESULT_FAILURE, [
                        'plugin_file' => $plugin_file,
                        'error_message' => $result->get_error_message(),
                    ] );
                }

                $this->send_enhanced_error(
                    $result->get_error_message(),
                    [ 'error_code' => 'activation_failed', 'repository' => $repo_name, 'plugin_file' => $plugin_file ]
                );
            }

            // FSM: set repo active state
            if ( ! empty( $repo_name ) ) {
                $this->state_manager->transition( $repo_name, PluginState::INSTALLED_ACTIVE, [ 'source' => 'ajax_activate' ] );
            }

            // v1.0.35: Record successful activation in audit trail
            if ( $this->audit_trail && ! empty( $repo_name ) ) {
                $this->audit_trail->record_activate( $repo_name, AuditTrailService::RESULT_SUCCESS, [
                    'plugin_file' => $plugin_file,
                ] );
            }

            // v1.0.35: Invalidate processing cache for this repository (state changed)
            if ( ! empty( $repo_name ) ) {
                $this->invalidate_processing_cache( $repo_name );
            }

            wp_send_json_success( array_merge( $result, [
                'repository' => $repo_name,
            ] ) );
        } finally {
            if (! empty($repo_full)) { $this->state_manager->release_processing_lock($repo_full); }
        }
    }

    /**
     * Deactivate plugin.
     */
    public function deactivate_plugin(): void {
        $this->verify_nonce_and_capability();

        $plugin_file = sanitize_text_field( $_POST['plugin_file'] ?? '' );
        $repo_name = sanitize_text_field( $_POST['repository'] ?? '' );

        if ( empty( $plugin_file ) ) {
            $this->send_enhanced_error(
                __( 'Plugin file is required.', 'kiss-smart-batch-installer' ),
                [ 'error_code' => 'missing_plugin_file' ]
            );
        }

        $repo_full = $repo_name;
        if (! empty($repo_full) && ! $this->state_manager->acquire_processing_lock($repo_full)) {
            wp_send_json_error([
                'message' => __( 'Another operation is in progress for this repository. Please try again shortly.', 'kiss-smart-batch-installer' ),
                'repository' => $repo_name,
            ]);
        }

        try {
            // Deactivate the plugin
            $result = $this->installation_service->deactivate_plugin( $plugin_file );

            if ( is_wp_error( $result ) ) {
                // FSM: mark error state for this repo
                if ( ! empty( $repo_name ) ) {
                    $this->state_manager->transition( $repo_name, PluginState::ERROR, [ 'source' => 'ajax_deactivate' ] );
                }

                // v1.0.35: Record failed deactivation in audit trail
                if ( $this->audit_trail && ! empty( $repo_name ) ) {
                    $this->audit_trail->record_deactivate( $repo_name, AuditTrailService::RESULT_FAILURE, [
                        'plugin_file' => $plugin_file,
                        'error_message' => $result->get_error_message(),
                    ] );
                }

                $this->send_enhanced_error(
                    $result->get_error_message(),
                    [ 'error_code' => 'deactivation_failed', 'repository' => $repo_name, 'plugin_file' => $plugin_file ]
                );
            }

            // FSM: set repo inactive state
            if ( ! empty( $repo_name ) ) {
                $this->state_manager->transition( $repo_name, PluginState::INSTALLED_INACTIVE, [ 'source' => 'ajax_deactivate' ] );
            }

            // v1.0.35: Record successful deactivation in audit trail
            if ( $this->audit_trail && ! empty( $repo_name ) ) {
                $this->audit_trail->record_deactivate( $repo_name, AuditTrailService::RESULT_SUCCESS, [
                    'plugin_file' => $plugin_file,
                ] );
            }

            // v1.0.35: Invalidate processing cache for this repository (state changed)
            if ( ! empty( $repo_name ) ) {
                $this->invalidate_processing_cache( $repo_name );
            }

            wp_send_json_success( array_merge( $result, [
                'repository' => $repo_name,
            ] ) );
        } finally {
            if (! empty($repo_full)) { $this->state_manager->release_processing_lock($repo_full); }
        }
    }

    /**
     * Batch install plugins.
     */
    public function batch_install(): void {
        $this->verify_nonce_and_capability();

        $repositories = $_POST['repositories'] ?? [];
        $activate = (bool) ( $_POST['activate'] ?? false );

        if ( empty( $repositories ) || ! is_array( $repositories ) ) {
            wp_send_json_error( [
                'message' => __( 'No repositories selected.', 'kiss-smart-batch-installer' )
            ] );
        }

        // Validate and sanitize repository data
        $repo_data = [];
        foreach ( $repositories as $repo ) {
            if ( ! is_array( $repo ) || empty( $repo['owner'] ) || empty( $repo['repo'] ) ) {
                continue;
            }

            $repo_data[] = [
                'owner' => sanitize_text_field( $repo['owner'] ),
                'repo' => sanitize_text_field( $repo['repo'] ),
                'branch' => sanitize_text_field( $repo['branch'] ?? 'main' ),
            ];
        }

        if ( empty( $repo_data ) ) {
            wp_send_json_error( [
                'message' => __( 'No valid repositories provided.', 'kiss-smart-batch-installer' )
            ] );
        }

        // Perform batch installation
        $results = $this->installation_service->batch_install( $repo_data, $activate );

        // Count successful installations
        $success_count = count( array_filter( $results, function( $result ) {
            return $result['success'] ?? false;
        } ) );

        wp_send_json_success( [
            'message' => sprintf(
                __( 'Successfully processed %d of %d plugins.', 'kiss-smart-batch-installer' ),
                $success_count,
                count( $results )
            ),
            'results' => $results,
            'success_count' => $success_count,
            'total_count' => count( $results ),
        ] );
    }

    /**
     * Batch activate plugins.
     * Each plugin is processed sequentially with proper locking.
     *
     * @since 1.0.33 - Added per-repository locking for concurrency safety
     */
    public function batch_activate(): void {
        $this->verify_nonce_and_capability();

        $plugin_files = $_POST['plugin_files'] ?? [];

        if ( empty( $plugin_files ) || ! is_array( $plugin_files ) ) {
            wp_send_json_error( [
                'message' => __( 'No plugin files provided.', 'kiss-smart-batch-installer' )
            ] );
        }

        $results = [];
        $locks_held = []; // Track locks we acquire for cleanup

        try {
            foreach ( $plugin_files as $plugin_data ) {
                if ( ! is_array( $plugin_data ) || empty( $plugin_data['plugin_file'] ) ) {
                    continue;
                }

                $plugin_file = sanitize_text_field( $plugin_data['plugin_file'] );
                $repo_name = sanitize_text_field( $plugin_data['repository'] ?? '' );

                // Acquire lock for this repository (wait up to 10s if another operation is in progress)
                if ( ! empty( $repo_name ) ) {
                    if ( ! $this->state_manager->wait_for_lock( $repo_name, 10, 200 ) ) {
                        $results[] = [
                            'repository' => $repo_name,
                            'plugin_file' => $plugin_file,
                            'success' => false,
                            'error' => __( 'Could not acquire lock - another operation in progress.', 'kiss-smart-batch-installer' ),
                            'skipped' => true,
                        ];
                        continue;
                    }
                    $locks_held[] = $repo_name;
                }

                $result = $this->installation_service->activate_plugin( $plugin_file );

                // Release lock immediately after operation
                if ( ! empty( $repo_name ) ) {
                    $this->state_manager->release_processing_lock( $repo_name );
                    $locks_held = array_diff( $locks_held, [ $repo_name ] );
                }

                if ( is_wp_error( $result ) ) {
                    // FSM: mark error state
                    if ( ! empty( $repo_name ) ) {
                        $this->state_manager->transition( $repo_name, PluginState::ERROR, [ 'source' => 'batch_activate' ] );
                    }
                    $results[] = [
                        'repository' => $repo_name,
                        'plugin_file' => $plugin_file,
                        'success' => false,
                        'error' => $result->get_error_message(),
                    ];
                } else {
                    // FSM: mark active state
                    if ( ! empty( $repo_name ) ) {
                        $this->state_manager->transition( $repo_name, PluginState::INSTALLED_ACTIVE, [ 'source' => 'batch_activate' ] );
                    }
                    $results[] = array_merge( $result, [
                        'repository' => $repo_name,
                        'success' => true,
                    ] );
                }
            }
        } finally {
            // Ensure all locks are released on any exit path
            foreach ( $locks_held as $repo ) {
                $this->state_manager->release_processing_lock( $repo );
            }
        }

        wp_send_json_success( [
            'results' => $results,
            'total' => count( $results ),
            'successful' => count( array_filter( $results, fn( $r ) => $r['success'] ) ),
        ] );
    }

    /**
     * Batch deactivate plugins.
     * Each plugin is processed sequentially with proper locking.
     *
     * @since 1.0.33 - Added per-repository locking for concurrency safety
     */
    public function batch_deactivate(): void {
        $this->verify_nonce_and_capability();

        $plugin_files = $_POST['plugin_files'] ?? [];

        if ( empty( $plugin_files ) || ! is_array( $plugin_files ) ) {
            wp_send_json_error( [
                'message' => __( 'No plugin files provided.', 'kiss-smart-batch-installer' )
            ] );
        }

        $results = [];
        $locks_held = []; // Track locks we acquire for cleanup

        try {
            foreach ( $plugin_files as $plugin_data ) {
                if ( ! is_array( $plugin_data ) || empty( $plugin_data['plugin_file'] ) ) {
                    continue;
                }

                $plugin_file = sanitize_text_field( $plugin_data['plugin_file'] );
                $repo_name = sanitize_text_field( $plugin_data['repository'] ?? '' );

                // Acquire lock for this repository (wait up to 10s if another operation is in progress)
                if ( ! empty( $repo_name ) ) {
                    if ( ! $this->state_manager->wait_for_lock( $repo_name, 10, 200 ) ) {
                        $results[] = [
                            'repository' => $repo_name,
                            'plugin_file' => $plugin_file,
                            'success' => false,
                            'error' => __( 'Could not acquire lock - another operation in progress.', 'kiss-smart-batch-installer' ),
                            'skipped' => true,
                        ];
                        continue;
                    }
                    $locks_held[] = $repo_name;
                }

                $result = $this->installation_service->deactivate_plugin( $plugin_file );

                // Release lock immediately after operation
                if ( ! empty( $repo_name ) ) {
                    $this->state_manager->release_processing_lock( $repo_name );
                    $locks_held = array_diff( $locks_held, [ $repo_name ] );
                }

                if ( is_wp_error( $result ) ) {
                    // FSM: mark error state
                    if ( ! empty( $repo_name ) ) {
                        $this->state_manager->transition( $repo_name, PluginState::ERROR, [ 'source' => 'batch_deactivate' ] );
                    }
                    $results[] = [
                        'repository' => $repo_name,
                        'plugin_file' => $plugin_file,
                        'success' => false,
                        'error' => $result->get_error_message(),
                    ];
                } else {
                    // FSM: mark inactive state
                    if ( ! empty( $repo_name ) ) {
                        $this->state_manager->transition( $repo_name, PluginState::INSTALLED_INACTIVE, [ 'source' => 'batch_deactivate' ] );
                    }
                    $results[] = array_merge( $result, [
                        'repository' => $repo_name,
                        'success' => true,
                    ] );
                }
            }
        } finally {
            // Ensure all locks are released on any exit path
            foreach ( $locks_held as $repo ) {
                $this->state_manager->release_processing_lock( $repo );
            }
        }

        // Count successful deactivations
        $success_count = count( array_filter( $results, function( $result ) {
            return $result['success'] ?? false;
        } ) );

        wp_send_json_success( [
            'message' => sprintf(
                __( 'Successfully processed %d of %d plugins.', 'kiss-smart-batch-installer' ),
                $success_count,
                count( $results )
            ),
            'results' => $results,
            'success_count' => $success_count,
            'total_count' => count( $results ),
        ] );
    }

    /**
     * Refresh status for multiple repositories.
     */
    public function refresh_status(): void {
        $this->verify_nonce_and_capability();

        $repositories = $_POST['repositories'] ?? [];

        if ( empty( $repositories ) || ! is_array( $repositories ) ) {
            wp_send_json_error( [
                'message' => __( 'No repositories specified.', 'kiss-smart-batch-installer' )
            ] );
        }

	        // v1.0.70: Batch wrapper uses the same canonical row-authoritative refresh payload
	        // as sbi_refresh_repository, to prevent divergent refresh behavior.
	        $results = [];
	        foreach ( $repositories as $repo_name ) {
	            $repo_name = sanitize_text_field( $repo_name );
	            if ( empty( $repo_name ) ) {
	                continue;
	            }
	
	            try {
	                $results[] = $this->build_refresh_repository_payload( $repo_name );
	            } catch ( Exception $e ) {
	                // Keep other repositories refreshing even if one fails.
	                $results[] = [
	                    'repository' => $repo_name,
	                    'state'      => 'unknown',
	                    'row_html'   => '',
	                    'error'      => $e->getMessage(),
	                ];
	            }
	        }

        wp_send_json_success( [
            'results' => $results,
        ] );
    }

    /**
     * Get installation progress.
     */
    public function get_installation_progress(): void {
        $this->verify_nonce_and_capability();
        // For now, return mock progress data
        wp_send_json_success( [
            'progress' => 75,
            'current_step' => __( 'Installing plugin dependencies...', 'kiss-smart-batch-installer' ),
            'completed' => 3,
            'total' => 4,
        ] );
    }

    /**
     * Server-Sent Events stream for state broadcasts (experimental).
     * This returns text/event-stream with incremental state_changed events.
     * Note: Do not enable for unauthenticated users without review.
     *
     * ┌─────────────────────────────────────────────────────────────────────────┐
     * │ NETWORK RESILIENCE - v1.0.33                                            │
     * │ - Added heartbeat events every 15 seconds for connection health         │
     * │ - Frontend detects stale connections and auto-reconnects                │
     * └─────────────────────────────────────────────────────────────────────────┘
     */
    public function state_stream(): void {
        // Basic permission check; can be relaxed later as needed
        if ( ! current_user_can( 'install_plugins' ) ) {
            status_header(403);
            exit;
        }
        // Feature toggle: require SSE diagnostics to be enabled
        if ( ! get_option( 'sbi_sse_diagnostics', false ) ) {
            status_header(403);
            echo 'SSE diagnostics disabled';
            exit;
        }

        // Headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no'); // for Nginx

        @set_time_limit(0);
        @ignore_user_abort(true);

        $last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;
        $start = time();
        $max_seconds = 25; // keep short; client should reconnect
        $last_heartbeat = time();
        $heartbeat_interval = 15; // Send heartbeat every 15 seconds

        // Send initial comment to open the stream
        echo ":ok\n\n";
        @flush();

        while ( ( time() - $start ) < $max_seconds ) {
            $events = $this->state_manager->get_broadcast_events_since($last_id);
            foreach ($events as $evt) {
                $last_id = (int) $evt['id'];
                echo 'id: ' . $last_id . "\n";
                echo 'event: ' . $evt['event'] . "\n";
                echo 'data: ' . wp_json_encode($evt['payload']) . "\n\n";
                @flush();
                $last_heartbeat = time(); // Reset heartbeat timer when we send an event
            }

            // ┌─────────────────────────────────────────────────────────────────┐
            // │ SSE HEARTBEAT - v1.0.33                                         │
            // │ Send periodic heartbeat to keep connection alive and allow      │
            // │ frontend to detect stale connections                            │
            // └─────────────────────────────────────────────────────────────────┘
            if ( ( time() - $last_heartbeat ) >= $heartbeat_interval ) {
                echo "event: heartbeat\n";
                echo "data: " . wp_json_encode(['timestamp' => time()]) . "\n\n";
                @flush();
                $last_heartbeat = time();
            }

            if ( connection_aborted() ) { break; }
            // Sleep briefly to avoid tight loop
            usleep(300000); // 300ms
        }
        // end of stream cycle; client reconnects automatically
        exit;
    }

    /**
     * Trigger a harmless transition to validate SSE pipeline.
     */
    public function test_sse(): void {
        $this->verify_nonce_and_capability();
        if ( ! get_option( 'sbi_sse_diagnostics', false ) ) {
            wp_send_json_error([ 'message' => __( 'SSE diagnostics disabled.', 'kiss-smart-batch-installer' ) ]);
        }
        $repo = sanitize_text_field( $_POST['repository'] ?? 'kissplugins/SSE-Test' );
        $from = $this->state_manager->get_state($repo);
        // Flip to CHECKING then back
        $this->state_manager->transition($repo, PluginState::CHECKING, [ 'source' => 'sse_test' ]);
        $this->state_manager->transition($repo, $from, [ 'source' => 'sse_test_restore' ]);
        wp_send_json_success([ 'repository' => $repo, 'message' => 'SSE test transitions emitted' ]);
    }

    /**
     * Debug plugin detection for specific repositories.
     */
    public function debug_detection(): void {
        $this->verify_nonce_and_capability();

        $repositories = [
            'kissplugins/KISS-Plugin-Quick-Search',
            'kissplugins/KISS-Projects-Tasks',
            'kissplugins/KISS-Smart-Batch-Installer',
        ];

        $results = $this->detection_service->debug_detection( $repositories );

        wp_send_json_success( [
            'message' => 'Debug detection completed',
            'results' => $results,
        ] );
    }

    /**
     * Progress updates storage.
     *
     * @var array
     */
    private array $progress_updates = [];

    /**
     * Send progress update to frontend debugger.
     */
    private function send_progress_update( string $step, string $status, string $message = '' ): void {
        // Only send progress updates if debug is enabled
        if ( ! get_option( 'sbi_debug_ajax', false ) ) {
            return;
        }

	    $correlation_id = sbi_api_get_request_correlation_id();

        // Store progress update for later inclusion in response
        $this->progress_updates[] = [
            'step' => $step,
            'status' => $status,
            'message' => $message,
            'timestamp' => microtime( true )
        ];

        // Also log to error log for server-side debugging
	    error_log( sprintf( 'SBI PROGRESS(cid=%s): [%s] %s - %s', $correlation_id, $status, $step, $message ) );

	    // Structured log entry for unified viewer
	    $this->logging_service->log( LoggingService::LEVEL_DEBUG, 'AJAX progress update', [
	        'correlation_id' => $correlation_id,
	        'step' => $step,
	        'status' => $status,
	        'message' => $message,
	        'action' => $_POST['action'] ?? null,
	    ] );
    }

    /**
     * Verify nonce and user capability with detailed debugging.
     */
    private function verify_nonce_and_capability(): void {
	    $correlation_id = sbi_api_get_request_correlation_id();

        // Enhanced nonce validation with debugging
        $nonce_value = $_POST['nonce'] ?? '';
        $nonce_valid = check_ajax_referer( 'sbi_ajax_nonce', 'nonce', false );

        if ( ! $nonce_valid ) {
            // Log detailed nonce failure information
            error_log( sprintf(
	            'SBI Security(cid=%s): Nonce validation failed. Nonce: %s, Action: %s, User ID: %d, Referer: %s',
	            $correlation_id,
                $nonce_value ? substr($nonce_value, 0, 8) . '...' : 'empty',
                $_POST['action'] ?? 'unknown',
                get_current_user_id(),
                $_SERVER['HTTP_REFERER'] ?? 'unknown'
            ) );

	        $this->logging_service->log( LoggingService::LEVEL_WARNING, 'AJAX security nonce validation failed', [
	            'correlation_id' => $correlation_id,
	            'action' => $_POST['action'] ?? 'unknown',
	            'user_id' => get_current_user_id(),
	            'referer' => $_SERVER['HTTP_REFERER'] ?? 'unknown',
	            'nonce_provided' => ! empty( $nonce_value ),
	        ] );

            $this->send_enhanced_error(
                __( 'Security check failed: Invalid security token (nonce). Please refresh the page and try again.', 'kiss-smart-batch-installer' ),
                [
                    'error_code' => 'nonce_verification_failed',
                    'security_issue' => 'nonce',
                    'nonce_provided' => !empty($nonce_value),
                    'action' => $_POST['action'] ?? 'unknown',
                    'user_id' => get_current_user_id()
                ]
            );
        }

        // Enhanced capability check with debugging
        $user_id = get_current_user_id();
        $can_install = current_user_can( 'install_plugins' );
        $user_roles = wp_get_current_user()->roles ?? [];

        if ( ! $can_install ) {
            // Log detailed capability failure information
            error_log( sprintf(
	            'SBI Security(cid=%s): Capability check failed. User ID: %d, Roles: %s, Required: install_plugins',
	            $correlation_id,
                $user_id,
                implode(', ', $user_roles)
            ) );

	        $this->logging_service->log( LoggingService::LEVEL_WARNING, 'AJAX security capability check failed', [
	            'correlation_id' => $correlation_id,
	            'action' => $_POST['action'] ?? 'unknown',
	            'user_id' => $user_id,
	            'user_roles' => $user_roles,
	            'required_capability' => 'install_plugins',
	        ] );

            $this->send_enhanced_error(
                __( 'Security check failed: Insufficient permissions to install plugins. Contact your administrator.', 'kiss-smart-batch-installer' ),
                [
                    'error_code' => 'insufficient_permissions',
                    'security_issue' => 'capability',
                    'required_capability' => 'install_plugins',
                    'user_id' => $user_id,
                    'user_roles' => $user_roles,
                    'has_capability' => $can_install
                ]
            );
        }

        // Log successful security validation
        error_log( sprintf(
	        'SBI Security(cid=%s): Validation passed. User ID: %d, Roles: %s, Action: %s',
	        $correlation_id,
            $user_id,
            implode(', ', $user_roles),
            $_POST['action'] ?? 'unknown'
        ) );

	    if ( get_option( 'sbi_debug_ajax', false ) ) {
	        $this->logging_service->log( LoggingService::LEVEL_DEBUG, 'AJAX security validation passed', [
	            'correlation_id' => $correlation_id,
	            'action' => $_POST['action'] ?? 'unknown',
	            'user_id' => $user_id,
	            'user_roles' => $user_roles,
	        ] );
	    }
    }

    /**
     * Enhanced error response with structured data for better frontend handling.
     *
     * @param string $message Error message.
     * @param array  $context Additional context data.
     */
    private function send_enhanced_error( string $message, array $context = [] ): void {
        // Detect error type from message for better frontend handling
        $error_type = $this->detect_error_type( $message );

        $error_response = [
            'message' => $message,
            'type' => $error_type,
            'context' => $context,
            'timestamp' => time(),
            'recoverable' => $this->is_recoverable( $error_type ),
            'retry_delay' => $this->get_retry_delay( $error_type ),
            'severity' => $this->get_error_severity( $error_type ),
        ];

        // Add specific guidance based on error type
        $error_response['guidance'] = $this->get_error_guidance( $error_type, $message, $context );

        wp_send_json_error( $error_response );
    }

    /**
     * Detect error type from message content.
     *
     * @param string $message Error message.
     * @return string Error type.
     */
    private function detect_error_type( string $message ): string {
        $lower_message = strtolower( $message );

        // GitHub API errors
        if ( strpos( $lower_message, 'rate limit' ) !== false ) return 'rate_limit';
        if ( strpos( $lower_message, '404' ) !== false ) return 'not_found';
        if ( strpos( $lower_message, '403' ) !== false ) return 'forbidden';
        if ( strpos( $lower_message, '401' ) !== false ) return 'unauthorized';
        if ( strpos( $lower_message, 'github' ) !== false ) return 'github_api';

        // Network errors
        if ( strpos( $lower_message, 'network' ) !== false ) return 'network';
        if ( strpos( $lower_message, 'timeout' ) !== false ) return 'timeout';
        if ( strpos( $lower_message, 'connection' ) !== false ) return 'connection';
        if ( strpos( $lower_message, 'curl' ) !== false ) return 'network';

        // WordPress errors
        if ( strpos( $lower_message, 'permission' ) !== false ) return 'permission';
        if ( strpos( $lower_message, 'activation' ) !== false ) return 'activation';
        if ( strpos( $lower_message, 'deactivation' ) !== false ) return 'deactivation';
        if ( strpos( $lower_message, 'installation' ) !== false ) return 'installation';
        if ( strpos( $lower_message, 'download' ) !== false ) return 'download';
        if ( strpos( $lower_message, 'package' ) !== false ) return 'package';
        if ( strpos( $lower_message, 'memory' ) !== false ) return 'memory';
        if ( strpos( $lower_message, 'fatal' ) !== false ) return 'fatal';

        // Security errors (specific types for better guidance)
        if ( strpos( $lower_message, 'security token' ) !== false || strpos( $lower_message, 'invalid security token' ) !== false ) return 'nonce_verification_failed';
        if ( strpos( $lower_message, 'insufficient permissions' ) !== false ) return 'insufficient_permissions';
        if ( strpos( $lower_message, 'nonce' ) !== false ) return 'nonce_verification_failed';
        if ( strpos( $lower_message, 'security' ) !== false ) return 'security';

        return 'generic';
    }

    /**
     * Determine if an error type is recoverable.
     *
     * @param string $error_type Error type.
     * @return bool Whether the error is recoverable.
     */
    private function is_recoverable( string $error_type ): bool {
        $recoverable_types = [
            'rate_limit', 'network', 'timeout', 'connection', 'github_api', 'generic'
        ];
        return in_array( $error_type, $recoverable_types, true );
    }

    /**
     * Get retry delay for error type.
     *
     * @param string $error_type Error type.
     * @return int Retry delay in seconds.
     */
    private function get_retry_delay( string $error_type ): int {
        switch ( $error_type ) {
            case 'rate_limit':
                return 60; // 1 minute for rate limits
            case 'network':
            case 'timeout':
            case 'connection':
                return 5; // 5 seconds for network issues
            case 'github_api':
                return 10; // 10 seconds for GitHub API issues
            default:
                return 2; // 2 seconds default
        }
    }

    /**
     * Get error severity level.
     *
     * @param string $error_type Error type.
     * @return string Severity level.
     */
    private function get_error_severity( string $error_type ): string {
        switch ( $error_type ) {
            case 'security':
            case 'fatal':
            case 'memory':
                return 'critical';
            case 'permission':
            case 'forbidden':
            case 'unauthorized':
                return 'error';
            case 'rate_limit':
            case 'not_found':
                return 'warning';
            default:
                return 'info';
        }
    }

    /**
     * Get error-specific guidance for users.
     *
     * @param string $error_type Error type.
     * @param string $message Original error message.
     * @param array  $context Error context.
     * @return array Guidance information.
     */
    private function get_error_guidance( string $error_type, string $message, array $context ): array {
        switch ( $error_type ) {
            case 'rate_limit':
                return [
                    'title' => 'GitHub API Rate Limit',
                    'description' => 'GitHub limits API requests to prevent abuse.',
                    'actions' => [
                        'Wait 5-10 minutes before trying again',
                        'Consider using a GitHub personal access token for higher limits'
                    ],
                    'auto_retry' => true,
                    'retry_in' => 300 // 5 minutes
                ];

            case 'not_found':
                $repo = $context['repository'] ?? 'unknown';
                return [
                    'title' => 'Repository Not Found',
                    'description' => 'The repository may be private, renamed, or deleted.',
                    'actions' => [
                        'Verify the repository exists and is public',
                        'Check the spelling of owner and repository names',
                        'Ensure you have access if the repository is private'
                    ],
                    'links' => [
                        'github_url' => "https://github.com/{$repo}"
                    ]
                ];

            case 'permission':
                return [
                    'title' => 'Permission Error',
                    'description' => 'You do not have sufficient permissions for this action.',
                    'actions' => [
                        'Contact your WordPress administrator',
                        'Ensure you have the required capabilities'
                    ],
                    'required_capability' => $context['required_capability'] ?? 'install_plugins'
                ];

            case 'security':
            case 'nonce_verification_failed':
                return [
                    'title' => 'Security Token Error',
                    'description' => 'The security token (nonce) is invalid or expired.',
                    'actions' => [
                        'Refresh the page and try again',
                        'Clear your browser cache if the problem persists',
                        'Log out and log back in if refreshing doesn\'t help'
                    ],
                    'technical_details' => [
                        'nonce_provided' => $context['nonce_provided'] ?? false,
                        'action' => $context['action'] ?? 'unknown',
                        'user_id' => $context['user_id'] ?? 0
                    ]
                ];

            case 'insufficient_permissions':
                $user_roles = $context['user_roles'] ?? [];
                return [
                    'title' => 'Insufficient Permissions',
                    'description' => 'Your user account does not have permission to install plugins.',
                    'actions' => [
                        'Contact your WordPress administrator to grant plugin installation permissions',
                        'Ensure your user role includes the "install_plugins" capability',
                        'Administrator or Super Admin roles are typically required'
                    ],
                    'technical_details' => [
                        'required_capability' => $context['required_capability'] ?? 'install_plugins',
                        'user_roles' => $user_roles,
                        'user_id' => $context['user_id'] ?? 0
                    ]
                ];

            case 'network':
            case 'timeout':
            case 'connection':
                return [
                    'title' => 'Network Error',
                    'description' => 'Unable to connect to the required service.',
                    'actions' => [
                        'Check your internet connection',
                        'Try again in a few moments',
                        'Contact your hosting provider if the issue persists'
                    ],
                    'auto_retry' => true
                ];

            case 'activation':
                return [
                    'title' => 'Plugin Activation Failed',
                    'description' => 'The plugin could not be activated.',
                    'actions' => [
                        'Check for plugin compatibility issues',
                        'Review WordPress error logs',
                        'Ensure all plugin dependencies are met'
                    ]
                ];

            case 'installation':
                return [
                    'title' => 'Installation Failed',
                    'description' => 'The plugin could not be installed.',
                    'actions' => [
                        'Verify the repository contains a valid WordPress plugin',
                        'Check available disk space',
                        'Ensure proper file permissions'
                    ]
                ];

            default:
                return [
                    'title' => 'Error Occurred',
                    'description' => 'An unexpected error occurred.',
                    'actions' => [
                        'Try refreshing the repository status',
                        'Contact support if the issue persists'
                    ]
                ];
        }
    }

    /**
     * ⚠️ FSM-FIRST: Find installed plugin file for a given slug.
     *
     * CRITICAL: This method now uses StateManager (FSM) instead of direct WordPress core calls.
     *
     * DO NOT BYPASS FSM:
     * - DO NOT call get_plugins() directly
     * - DO NOT call is_plugin_active() directly
     * - USE StateManager::getInstalledPluginFile() instead
     *
     * WHY: Direct WordPress core calls bypass the FSM and create parallel state pipelines.
     *
     * @deprecated 1.0.54 Use StateManager::getInstalledPluginFile() directly instead.
     * @param string $plugin_slug Plugin slug.
     * @return string Plugin file path or empty string if not found.
     */
    private function find_installed_plugin( string $plugin_slug ): string {
        // ⚠️ FSM-FIRST: Use StateManager instead of direct WordPress core calls
        // Construct repository name (best effort - may need organization prefix)
        $repository = $plugin_slug; // Simple case: just the slug

        // Try to get plugin file from FSM
        $plugin_file = $this->state_manager->getInstalledPluginFile( $repository );

        if ( ! empty( $plugin_file ) ) {
            return $plugin_file;
        }

        // If we have an organization context, try with organization prefix
        // This is a fallback for cases where we don't have the full repository name
        // In the future, callers should pass the full repository name instead of just the slug
        if ( ! empty( $this->organization ) ) {
            $repository_with_org = $this->organization . '/' . $plugin_slug;
            return $this->state_manager->getInstalledPluginFile( $repository_with_org );
        }

        return '';
    }

    /**
     * Test repository access for debugging.
     */
    public function test_repository(): void {
        // Verify nonce
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'sbi_test_repository' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }

        $owner = sanitize_text_field( $_POST['owner'] ?? '' );
        $repo = sanitize_text_field( $_POST['repo'] ?? '' );

        if ( empty( $owner ) || empty( $repo ) ) {
            wp_send_json_error( [ 'message' => 'Owner and repository name are required' ] );
        }

        // Test repository access
        $repository_info = $this->github_service->get_repository_info( $owner, $repo );

        if ( is_wp_error( $repository_info ) ) {
            $error_data = $repository_info->get_error_data();
            $response_data = [
                'message' => $repository_info->get_error_message(),
                'troubleshooting' => [
                    'check_repository_exists' => sprintf( 'https://github.com/%s/%s', $owner, $repo ),
                    'verify_repository_public' => 'Make sure the repository is public',
                    'check_spelling' => 'Verify owner and repository names are correct'
                ]
            ];

            if ( is_array( $error_data ) ) {
                $response_data['debug_info'] = $error_data;
            }

            wp_send_json_error( $response_data );
        }

        // Success - return repository information
        wp_send_json_success( [
            'name' => $repository_info['name'] ?? $repo,
            'description' => $repository_info['description'] ?? '',
            'html_url' => $repository_info['html_url'] ?? sprintf( 'https://github.com/%s/%s', $owner, $repo ),
            'private' => $repository_info['private'] ?? false,
            'fork' => $repository_info['fork'] ?? false,
            'language' => $repository_info['language'] ?? 'Unknown',
            'stargazers_count' => $repository_info['stargazers_count'] ?? 0,
            'forks_count' => $repository_info['forks_count'] ?? 0
        ] );
    }
}

/**
 * Return the request correlation id (client-provided) or generate one.
 *
 * NOTE: This function is used by the SBI\API namespace-local JSON response wrappers.
 */
function sbi_api_get_request_correlation_id(): string {
	static $cached = null;
	if ( is_string( $cached ) && $cached !== '' ) {
		return $cached;
	}

	$raw = $_REQUEST['correlation_id'] ?? '';
	if ( is_array( $raw ) ) {
		$raw = '';
	}

	$cid = sanitize_text_field( wp_unslash( (string) $raw ) );
	$cid = substr( $cid, 0, 128 );

	if ( $cid === '' ) {
		$cid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'sbi-', true );
	}

	$cached = $cid;
	return $cid;
}

/**
 * Namespace-local wrapper: ensure correlation_id is present in all success responses.
 *
 * AjaxHandler calls wp_send_json_success() without a leading slash, so in this namespace
 * we can safely inject a correlation id without touching every call site.
 */
function wp_send_json_success( $data = null, $status_code = null, $flags = 0 ): void {
	$cid = sbi_api_get_request_correlation_id();

	if ( $data === null ) {
		$data = [ 'correlation_id' => $cid ];
	} elseif ( is_array( $data ) ) {
		if ( ! array_key_exists( 'correlation_id', $data ) ) {
			$data['correlation_id'] = $cid;
		}
	} else {
		// Fallback for unexpected scalar/object payloads: preserve value while still surfacing correlation_id.
		$data = [
			'correlation_id' => $cid,
			'value' => $data,
		];
	}

	\wp_send_json_success( $data, $status_code, $flags );
}

/**
 * Namespace-local wrapper: ensure correlation_id is present in all error responses,
 * and emit a structured error log entry via LoggingService when available.
 */
function wp_send_json_error( $data = null, $status_code = null, $flags = 0 ): void {
	$cid = sbi_api_get_request_correlation_id();

	if ( $data === null ) {
		$data = [ 'correlation_id' => $cid ];
	} elseif ( is_array( $data ) ) {
		if ( ! array_key_exists( 'correlation_id', $data ) ) {
			$data['correlation_id'] = $cid;
		}
	} else {
		$data = [
			'correlation_id' => $cid,
			'value' => $data,
		];
	}

	// Structured error log (best-effort) for unified diagnostics.
	try {
		$logger = ( function_exists( '\\sbi_service' ) ) ? \sbi_service( \SBI\Services\LoggingService::class ) : null;
		if ( $logger instanceof \SBI\Services\LoggingService ) {
			$context = [
				'correlation_id' => $cid,
				'action' => $_REQUEST['action'] ?? null,
				'status_code' => $status_code,
			];
			if ( is_array( $data ) ) {
				if ( isset( $data['message'] ) ) $context['message'] = $data['message'];
				if ( isset( $data['type'] ) ) $context['type'] = $data['type'];
				if ( isset( $data['context'] ) && is_array( $data['context'] ) ) {
					foreach ( [ 'repository', 'repo', 'repo_name', 'owner', 'slug', 'error_code', 'http_status' ] as $k ) {
						if ( isset( $data['context'][ $k ] ) ) {
							$context[ $k ] = $data['context'][ $k ];
						}
					}
				}
			}
			$logger->log( \SBI\Services\LoggingService::LEVEL_ERROR, 'AJAX error response', $context );
		}
	} catch ( \Throwable $e ) {
		// Never block the response due to logging failures.
	}

	\wp_send_json_error( $data, $status_code, $flags );
}

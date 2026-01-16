<?php
/**
 * Validation Guard Service - Error Prevention System
 *
 * Provides comprehensive pre-validation before operations to prevent errors
 * rather than handling them after they occur.
 *
 * @package SBI\Services
 */

namespace SBI\Services;

use SBI\Enums\PluginState;

// Ensure WordPress functions are available globally
if ( ! function_exists( 'current_user_can' ) ) {
    require_once \ABSPATH . 'wp-includes/capabilities.php';
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
    require_once \ABSPATH . 'wp-includes/user.php';
}

/**
 * Validation Guard Service for Error Prevention
 */
class ValidationGuardService {

    /**
     * Network timeout for connectivity checks (seconds).
     */
    private const NETWORK_TIMEOUT = 10;

    /**
     * Required disk space for plugin installation (MB).
     */
    private const REQUIRED_DISK_SPACE_MB = 50;

    /**
     * Required memory for plugin installation (MB).
     */
    private const REQUIRED_MEMORY_MB = 128;

    /**
     * StateManager instance.
     */
    private StateManager $state_manager;

    /**
     * Constructor.
     */
    public function __construct( StateManager $state_manager ) {
        $this->state_manager = $state_manager;
    }

    /**
     * Comprehensive pre-installation validation.
     *
     * @param string $owner Repository owner.
     * @param string $repo Repository name.
     * @param array $context Additional context for validation.
     * @return array Validation result with success status and details.
     */
    public function validate_installation_prerequisites( string $owner, string $repo, array $context = [] ): array {
        $repository = "{$owner}/{$repo}";
        $validation_results = [];
        $overall_success = true;

        // 1. Input Validation
        $input_validation = $this->validate_input_parameters( $owner, $repo );
        $validation_results['input'] = $input_validation;
        if ( ! $input_validation['success'] ) {
            $overall_success = false;
        }

        // 2. Permission Validation
        $permission_validation = $this->validate_user_permissions();
        $validation_results['permissions'] = $permission_validation;
        if ( ! $permission_validation['success'] ) {
            $overall_success = false;
        }

        // 3. System Resource Validation
        $resource_validation = $this->validate_system_resources();
        $validation_results['resources'] = $resource_validation;
        if ( ! $resource_validation['success'] ) {
            $overall_success = false;
        }

        // 4. Network Connectivity Validation
        $network_validation = $this->validate_network_connectivity();
        $validation_results['network'] = $network_validation;
        if ( ! $network_validation['success'] ) {
            $overall_success = false;
        }

        // 5. Repository State Validation
        $state_validation = $this->validate_repository_state( $repository );
        $validation_results['state'] = $state_validation;
        if ( ! $state_validation['success'] ) {
            $overall_success = false;
        }

        // 6. WordPress Environment Validation
        $wp_validation = $this->validate_wordpress_environment();
        $validation_results['wordpress'] = $wp_validation;
        if ( ! $wp_validation['success'] ) {
            $overall_success = false;
        }

        // 7. Concurrent Operation Validation
        $concurrency_validation = $this->validate_no_concurrent_operations( $repository );
        $validation_results['concurrency'] = $concurrency_validation;
        if ( ! $concurrency_validation['success'] ) {
            $overall_success = false;
        }

        return [
            'success' => $overall_success,
            'repository' => $repository,
            'validations' => $validation_results,
            'summary' => $this->generate_validation_summary( $validation_results ),
            'recommendations' => $this->generate_recommendations( $validation_results )
        ];
    }

    /**
     * Validate input parameters.
     *
     * @param string $owner Repository owner.
     * @param string $repo Repository name.
     * @return array Validation result.
     */
    private function validate_input_parameters( string $owner, string $repo ): array {
        $errors = [];

        // Check for empty values
        if ( empty( $owner ) ) {
            $errors[] = 'Repository owner cannot be empty';
        }

        if ( empty( $repo ) ) {
            $errors[] = 'Repository name cannot be empty';
        }

        // Check for valid characters (GitHub username/repo rules)
        if ( ! empty( $owner ) && ! preg_match( '/^[a-zA-Z0-9._-]+$/', $owner ) ) {
            $errors[] = 'Repository owner contains invalid characters';
        }

        if ( ! empty( $repo ) && ! preg_match( '/^[a-zA-Z0-9._-]+$/', $repo ) ) {
            $errors[] = 'Repository name contains invalid characters';
        }

        // Check length limits
        if ( strlen( $owner ) > 39 ) {
            $errors[] = 'Repository owner exceeds maximum length (39 characters)';
        }

        if ( strlen( $repo ) > 100 ) {
            $errors[] = 'Repository name exceeds maximum length (100 characters)';
        }

        return [
            'success' => empty( $errors ),
            'errors' => $errors,
            'details' => [
                'owner' => $owner,
                'repo' => $repo,
                'owner_length' => strlen( $owner ),
                'repo_length' => strlen( $repo )
            ]
        ];
    }

    /**
     * Validate user permissions.
     *
     * @return array Validation result.
     */
    private function validate_user_permissions(): array {
        $errors = [];
        $capabilities = [];

        // Check required capabilities
        $required_caps = [
            'install_plugins' => 'Install plugins',
            'activate_plugins' => 'Activate plugins',
            'upload_files' => 'Upload files'
        ];

        foreach ( $required_caps as $cap => $description ) {
            $has_cap = \current_user_can( $cap );
            $capabilities[ $cap ] = $has_cap;

            if ( ! $has_cap ) {
                $errors[] = "Missing required capability: {$description} ({$cap})";
            }
        }

        // Check if user is logged in
        if ( ! \is_user_logged_in() ) {
            $errors[] = 'User must be logged in';
        }

        return [
            'success' => empty( $errors ),
            'errors' => $errors,
            'details' => [
                'user_id' => \get_current_user_id(),
                'capabilities' => $capabilities,
                'is_logged_in' => \is_user_logged_in()
            ]
        ];
    }

    /**
     * Validate system resources.
     *
     * @return array Validation result.
     */
    private function validate_system_resources(): array {
        $errors = [];
        $warnings = [];

        // Check memory limit
        $memory_limit = $this->parse_memory_limit( \ini_get( 'memory_limit' ) );
        $required_memory = self::REQUIRED_MEMORY_MB * 1024 * 1024; // Convert to bytes

        if ( $memory_limit > 0 && $memory_limit < $required_memory ) {
            $errors[] = sprintf(
                'Insufficient memory limit: %dMB required, %dMB available',
                self::REQUIRED_MEMORY_MB,
                round( $memory_limit / 1024 / 1024 )
            );
        }

        // Check disk space
        $upload_dir = \wp_upload_dir();
        if ( ! $upload_dir['error'] ) {
            $free_space = \disk_free_space( $upload_dir['basedir'] );
            $required_space = self::REQUIRED_DISK_SPACE_MB * 1024 * 1024; // Convert to bytes

            if ( $free_space !== false && $free_space < $required_space ) {
                $errors[] = sprintf(
                    'Insufficient disk space: %dMB required, %dMB available',
                    self::REQUIRED_DISK_SPACE_MB,
                    round( $free_space / 1024 / 1024 )
                );
            }
        }

        // Check execution time limit
        $max_execution_time = \ini_get( 'max_execution_time' );
        if ( $max_execution_time > 0 && $max_execution_time < 60 ) {
            $warnings[] = sprintf(
                'Low execution time limit: %d seconds (recommended: 60+ seconds)',
                $max_execution_time
            );
        }

        return [
            'success' => empty( $errors ),
            'errors' => $errors,
            'warnings' => $warnings,
            'details' => [
                'memory_limit' => \ini_get( 'memory_limit' ),
                'memory_limit_bytes' => $memory_limit,
                'disk_free_space' => $free_space ?? 'unknown',
                'max_execution_time' => $max_execution_time
            ]
        ];
    }

    /**
     * Validate network connectivity.
     *
     * @return array Validation result.
     */
    private function validate_network_connectivity(): array {
        $errors = [];
        $connectivity_tests = [];

        // Test GitHub API connectivity
        $github_test = $this->test_url_connectivity( 'https://api.github.com' );
        $connectivity_tests['github_api'] = $github_test;
        if ( ! $github_test['success'] ) {
            $errors[] = 'Cannot connect to GitHub API';
        }

        // Test GitHub raw content connectivity
        $github_raw_test = $this->test_url_connectivity( 'https://raw.githubusercontent.com' );
        $connectivity_tests['github_raw'] = $github_raw_test;
        if ( ! $github_raw_test['success'] ) {
            $errors[] = 'Cannot connect to GitHub raw content';
        }

        return [
            'success' => empty( $errors ),
            'errors' => $errors,
            'details' => [
                'connectivity_tests' => $connectivity_tests
            ]
        ];
    }

    /**
     * Validate repository state.
     *
     * @param string $repository Repository identifier (owner/repo).
     * @return array Validation result.
     */
    private function validate_repository_state( string $repository ): array {
        $errors = [];
        $warnings = [];

        // Get current repository state
        $current_state = $this->state_manager->get_state( $repository );

        // Check if repository is in a valid state for installation
        $invalid_states = [
            PluginState::INSTALLED_ACTIVE,
            PluginState::INSTALLED_INACTIVE
        ];

        if ( in_array( $current_state, $invalid_states, true ) ) {
            $errors[] = sprintf(
                'Repository is already installed (state: %s)',
                $current_state->value
            );
        }

        // Check if repository is in error state
        if ( $current_state === PluginState::ERROR ) {
            $warnings[] = 'Repository is in error state - installation may fail';
        }

        // Check if repository is currently being processed
        if ( $current_state === PluginState::CHECKING ) {
            $errors[] = 'Repository is currently being processed';
        }

        return [
            'success' => empty( $errors ),
            'errors' => $errors,
            'warnings' => $warnings,
            'details' => [
                'current_state' => $current_state->value,
                'repository' => $repository
            ]
        ];
    }

    /**
     * Validate WordPress environment.
     *
     * @return array Validation result.
     */
    private function validate_wordpress_environment(): array {
        $errors = [];
        $warnings = [];

        // Check WordPress version
        global $wp_version;
        $min_wp_version = '5.0';
        if ( version_compare( $wp_version, $min_wp_version, '<' ) ) {
            $errors[] = sprintf(
                'WordPress version %s or higher required (current: %s)',
                $min_wp_version,
                $wp_version
            );
        }

        // Ensure WordPress admin functions are loaded before checking
        if ( ! \function_exists( 'activate_plugin' ) ) {
            require_once \ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( ! \function_exists( 'download_url' ) ) {
            require_once \ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! \class_exists( 'WP_Upgrader' ) ) {
            require_once \ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        // Check if required WordPress functions exist
        $required_functions = [
            'wp_remote_get',
            'wp_remote_post',
            'download_url',
            'unzip_file',
            'activate_plugin',
            'deactivate_plugins' // Note: WordPress uses plural 'deactivate_plugins'
        ];

        foreach ( $required_functions as $function ) {
            if ( ! \function_exists( $function ) ) {
                $errors[] = "Required WordPress function missing: {$function}";
            }
        }

        // Check if WordPress is in maintenance mode
        if ( \file_exists( \ABSPATH . '.maintenance' ) ) {
            $errors[] = 'WordPress is in maintenance mode';
        }

        // Check if plugins directory is writable
        $plugins_dir = \WP_PLUGIN_DIR;
        if ( ! \is_writable( $plugins_dir ) ) {
            $errors[] = 'Plugins directory is not writable';
        }

        // Log WordPress validation details for debugging
        if ( ! empty( $errors ) ) {
            \error_log( sprintf(
                'SBI WordPress Validation Failed: %s. Details: WP Version: %s, Plugins Dir: %s, Writable: %s, Maintenance: %s',
                implode( '; ', $errors ),
                $wp_version,
                $plugins_dir,
                \is_writable( $plugins_dir ) ? 'yes' : 'no',
                \file_exists( \ABSPATH . '.maintenance' ) ? 'yes' : 'no'
            ) );
        }

        return [
            'success' => empty( $errors ),
            'errors' => $errors,
            'warnings' => $warnings,
            'details' => [
                'wp_version' => $wp_version,
                'plugins_dir' => $plugins_dir,
                'plugins_dir_writable' => \is_writable( $plugins_dir ),
                'maintenance_mode' => \file_exists( \ABSPATH . '.maintenance' )
            ]
        ];
    }

    /**
     * Validate no concurrent operations.
     *
     * @param string $repository Repository identifier.
     * @return array Validation result.
     */
    private function validate_no_concurrent_operations( string $repository ): array {
        $errors = [];

        // Check if repository has an active processing lock
        if ( ! $this->state_manager->acquire_processing_lock( $repository ) ) {
            $errors[] = 'Another operation is in progress for this repository';

            // Release the lock we just tried to acquire (if any)
            $this->state_manager->release_processing_lock( $repository );
        } else {
            // Release the lock since this is just a validation check
            $this->state_manager->release_processing_lock( $repository );
        }

        return [
            'success' => empty( $errors ),
            'errors' => $errors,
            'details' => [
                'repository' => $repository,
                'lock_available' => empty( $errors )
            ]
        ];
    }

    /**
     * Test URL connectivity.
     *
     * @param string $url URL to test.
     * @return array Test result.
     */
    private function test_url_connectivity( string $url ): array {
        $start_time = microtime( true );

        $response = wp_remote_get( $url, [
            'timeout' => self::NETWORK_TIMEOUT,
            'user-agent' => 'SBI-ValidationGuard/1.0'
        ] );

        $duration = round( ( microtime( true ) - $start_time ) * 1000, 2 );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'error' => $response->get_error_message(),
                'duration_ms' => $duration,
                'url' => $url
            ];
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $success = $response_code >= 200 && $response_code < 400;

        return [
            'success' => $success,
            'response_code' => $response_code,
            'duration_ms' => $duration,
            'url' => $url
        ];
    }

    /**
     * Parse memory limit string to bytes.
     *
     * @param string $memory_limit Memory limit string (e.g., "128M", "1G").
     * @return int Memory limit in bytes.
     */
    private function parse_memory_limit( string $memory_limit ): int {
        $memory_limit = trim( $memory_limit );

        if ( $memory_limit === '-1' ) {
            return -1; // Unlimited
        }

        $unit = strtoupper( substr( $memory_limit, -1 ) );
        $value = (int) substr( $memory_limit, 0, -1 );

        switch ( $unit ) {
            case 'G':
                return $value * 1024 * 1024 * 1024;
            case 'M':
                return $value * 1024 * 1024;
            case 'K':
                return $value * 1024;
            default:
                return (int) $memory_limit;
        }
    }

    /**
     * Generate validation summary.
     *
     * @param array $validation_results All validation results.
     * @return array Summary information.
     */
    private function generate_validation_summary( array $validation_results ): array {
        $total_checks = count( $validation_results );
        $passed_checks = 0;
        $failed_checks = 0;
        $warnings_count = 0;
        $errors_count = 0;

        foreach ( $validation_results as $result ) {
            if ( $result['success'] ) {
                $passed_checks++;
            } else {
                $failed_checks++;
            }

            $errors_count += count( $result['errors'] ?? [] );
            $warnings_count += count( $result['warnings'] ?? [] );
        }

        return [
            'total_checks' => $total_checks,
            'passed_checks' => $passed_checks,
            'failed_checks' => $failed_checks,
            'errors_count' => $errors_count,
            'warnings_count' => $warnings_count,
            'success_rate' => $total_checks > 0 ? round( ( $passed_checks / $total_checks ) * 100, 1 ) : 0
        ];
    }

    /**
     * Generate recommendations based on validation results.
     *
     * @param array $validation_results All validation results.
     * @return array Recommendations for fixing issues.
     */
    private function generate_recommendations( array $validation_results ): array {
        $recommendations = [];

        foreach ( $validation_results as $category => $result ) {
            if ( ! $result['success'] ) {
                switch ( $category ) {
                    case 'input':
                        $recommendations[] = 'Verify repository owner and name are correct and contain only valid characters';
                        break;
                    case 'permissions':
                        $recommendations[] = 'Contact your WordPress administrator to grant required plugin installation permissions';
                        break;
                    case 'resources':
                        $recommendations[] = 'Increase server memory limit and ensure sufficient disk space';
                        break;
                    case 'network':
                        $recommendations[] = 'Check internet connection and firewall settings for GitHub access';
                        break;
                    case 'state':
                        $recommendations[] = 'Wait for current operations to complete or refresh repository status';
                        break;
                    case 'wordpress':
                        $recommendations[] = 'Update WordPress and ensure plugins directory is writable';
                        break;
                    case 'concurrency':
                        $recommendations[] = 'Wait for the current operation to complete before starting a new one';
                        break;
                }
            }
        }

        return $recommendations;
    }

    /**
     * Quick validation for activation operations.
     *
     * @param string $plugin_file Plugin file path.
     * @param string $repository Repository identifier.
     * @return array Validation result.
     */
    public function validate_activation_prerequisites( string $plugin_file, string $repository ): array {
        $validation_results = [];
        $overall_success = true;

        // 1. Plugin File Validation
        $file_validation = $this->validate_plugin_file( $plugin_file );
        $validation_results['plugin_file'] = $file_validation;
        if ( ! $file_validation['success'] ) {
            $overall_success = false;
        }

        // 2. Permission Validation
        $permission_validation = $this->validate_user_permissions();
        $validation_results['permissions'] = $permission_validation;
        if ( ! $permission_validation['success'] ) {
            $overall_success = false;
        }

        // 3. Concurrent Operation Validation
        $concurrency_validation = $this->validate_no_concurrent_operations( $repository );
        $validation_results['concurrency'] = $concurrency_validation;
        if ( ! $concurrency_validation['success'] ) {
            $overall_success = false;
        }

        return [
            'success' => $overall_success,
            'repository' => $repository,
            'plugin_file' => $plugin_file,
            'validations' => $validation_results,
            'summary' => $this->generate_validation_summary( $validation_results )
        ];
    }

    /**
     * Validate plugin file for activation.
     *
     * @param string $plugin_file Plugin file path.
     * @return array Validation result.
     */
    private function validate_plugin_file( string $plugin_file ): array {
        $errors = [];

        if ( empty( $plugin_file ) ) {
            $errors[] = 'Plugin file path cannot be empty';
        }

        if ( ! empty( $plugin_file ) ) {
            $full_path = \WP_PLUGIN_DIR . '/' . $plugin_file;

            if ( ! \file_exists( $full_path ) ) {
                $errors[] = 'Plugin file does not exist';
            }

            if ( ! \is_readable( $full_path ) ) {
                $errors[] = 'Plugin file is not readable';
            }

            // Check if already active
            if ( \function_exists( 'is_plugin_active' ) && \is_plugin_active( $plugin_file ) ) {
                $errors[] = 'Plugin is already active';
            }
        }

        return [
            'success' => empty( $errors ),
            'errors' => $errors,
            'details' => [
                'plugin_file' => $plugin_file,
                'full_path' => $full_path ?? null,
                'exists' => isset( $full_path ) ? \file_exists( $full_path ) : false,
                'readable' => isset( $full_path ) ? \is_readable( $full_path ) : false
            ]
        ];
    }
}

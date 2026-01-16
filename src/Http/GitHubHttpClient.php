<?php
/**
 * Resilient HTTP Client for GitHub requests.
 *
 * Provides a centralized, configurable HTTP client with retry logic,
 * circuit breaker, and structured error handling for GitHub requests.
 *
 * @package SBI\Http
 * @since 1.0.40
 *
 * ========================================================================
 * CHANGELOG
 * ========================================================================
 * v1.0.40 (2024-12-14) - Phase 1: Initial implementation
 *   - Created centralized HTTP client wrapper
 *   - Added typed helper methods for GET/HEAD requests
 *   - Implemented configurable request settings
 *   - Added web scraping and API preset configurations
 * ========================================================================
 */

namespace SBI\Http;

use WP_Error;

/**
 * Centralized HTTP client for all GitHub requests.
 *
 * This class provides a single point of control for GitHub HTTP requests,
 * with support for retries, circuit breaker, and structured error handling.
 */
class GitHubHttpClient {

    /**
     * Plugin version for User-Agent header.
     */
    private const PLUGIN_VERSION = '1.0.40';

    /**
     * Default request configuration.
     */
    private const DEFAULT_CONFIG = [
        // Timeouts
        'connect_timeout' => 5,      // Connection timeout in seconds
        'timeout'         => 10,     // Overall timeout in seconds

        // Retry settings (Phase 2)
        'max_retries'     => 3,      // Maximum retry attempts
        'base_delay_ms'   => 250,    // Base delay for backoff in milliseconds
        'max_delay_ms'    => 3000,   // Maximum delay cap in milliseconds

        // Circuit breaker settings (Phase 3)
        'circuit_threshold'   => 5,  // Failures before opening circuit
        'circuit_window_sec'  => 600, // Time window for counting failures (10 min)
        'circuit_cooldown_sec' => 60, // Cooldown period when circuit is open

        // Request settings
        'redirection'     => 3,      // Maximum redirects to follow
        'sslverify'       => true,   // Verify SSL certificates
    ];

    /**
     * Preset configuration for web scraping requests.
     */
    private const WEB_SCRAPE_PRESET = [
        'timeout'    => 30,
        'user_agent' => 'web',
        'accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'headers'    => [
            'Accept-Language'           => 'en-US,en;q=0.9',
            'Accept-Encoding'           => 'gzip, deflate, br',
            'DNT'                       => '1',
            'Connection'                => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest'            => 'document',
            'Sec-Fetch-Mode'            => 'navigate',
            'Sec-Fetch-Site'            => 'none',
            'Cache-Control'             => 'max-age=0',
        ],
    ];

    /**
     * Preset configuration for GitHub API requests.
     */
    private const API_PRESET = [
        'timeout'    => 15,
        'user_agent' => 'api',
        'accept'     => 'application/vnd.github.v3+json',
        'headers'    => [],
    ];

    /**
     * Perform a GET request to GitHub.
     *
     * @param string $url    The URL to request.
     * @param array  $config Optional configuration overrides.
     * @return array|WP_Error Response array or WP_Error on failure.
     */
    public function get( string $url, array $config = [] ) {
        $args = $this->build_request_args( $config );

        /**
         * Filter the request arguments before making the request.
         *
         * @since 1.0.40
         * @param array  $args   The request arguments.
         * @param string $url    The request URL.
         * @param string $method The request method (GET).
         */
        $args = apply_filters( 'kiss_sbi/github_http_request_args', $args, $url, 'GET' );

        $response = wp_remote_get( $url, $args );

        /**
         * Filter the response after the request completes.
         *
         * @since 1.0.40
         * @param array|WP_Error $response The response or error.
         * @param string         $url      The request URL.
         * @param array          $args     The request arguments used.
         * @param string         $method   The request method (GET).
         */
        return apply_filters( 'kiss_sbi/github_http_response', $response, $url, $args, 'GET' );
    }

    /**
     * Perform a HEAD request to GitHub.
     *
     * @param string $url    The URL to request.
     * @param array  $config Optional configuration overrides.
     * @return array|WP_Error Response array or WP_Error on failure.
     */
    public function head( string $url, array $config = [] ) {
        $args = $this->build_request_args( $config );

        /** This filter is documented in src/Http/GitHubHttpClient.php */
        $args = apply_filters( 'kiss_sbi/github_http_request_args', $args, $url, 'HEAD' );

        $response = wp_remote_head( $url, $args );

        /** This filter is documented in src/Http/GitHubHttpClient.php */
        return apply_filters( 'kiss_sbi/github_http_response', $response, $url, $args, 'HEAD' );
    }

    /**
     * Perform a GET request using web scraping preset.
     *
     * @param string $url    The URL to request.
     * @param array  $config Optional additional configuration.
     * @return array|WP_Error Response array or WP_Error on failure.
     */
    public function get_web( string $url, array $config = [] ) {
        return $this->get( $url, array_merge( [ 'preset' => 'web' ], $config ) );
    }

    /**
     * Perform a GET request using GitHub API preset.
     *
     * @param string $url    The URL to request.
     * @param array  $config Optional additional configuration.
     * @return array|WP_Error Response array or WP_Error on failure.
     */
    public function get_api( string $url, array $config = [] ) {
        return $this->get( $url, array_merge( [ 'preset' => 'api' ], $config ) );
    }

    /**
     * Build WordPress HTTP API request arguments from configuration.
     *
     * @param array $config Configuration options.
     * @return array WordPress HTTP API compatible arguments.
     */
    private function build_request_args( array $config ): array {
        // Start with defaults
        $merged_config = self::DEFAULT_CONFIG;

        // Apply preset if specified
        if ( isset( $config['preset'] ) ) {
            $preset = $this->get_preset( $config['preset'] );
            $merged_config = array_merge( $merged_config, $preset );
            unset( $config['preset'] );
        }

        // Apply user overrides
        $merged_config = array_merge( $merged_config, $config );

        /**
         * Filter the merged configuration before building request args.
         *
         * @since 1.0.40
         * @param array $merged_config The merged configuration.
         */
        $merged_config = apply_filters( 'kiss_sbi/github_http_config', $merged_config );

        // Build headers
        $headers = $merged_config['headers'] ?? [];
        $headers['User-Agent'] = $this->build_user_agent( $merged_config['user_agent'] ?? 'default' );
        $headers['Accept'] = $merged_config['accept'] ?? 'text/html';

        // Build WordPress HTTP API args
        return [
            'timeout'     => $merged_config['timeout'],
            'redirection' => $merged_config['redirection'],
            'sslverify'   => $merged_config['sslverify'],
            'headers'     => $headers,
        ];
    }

    /**
     * Get a preset configuration by name.
     *
     * @param string $preset_name The preset name ('web' or 'api').
     * @return array The preset configuration.
     */
    private function get_preset( string $preset_name ): array {
        return match ( $preset_name ) {
            'web'   => self::WEB_SCRAPE_PRESET,
            'api'   => self::API_PRESET,
            default => [],
        };
    }

    /**
     * Build a User-Agent string based on the type.
     *
     * @param string $type The type of user agent ('web', 'api', or 'default').
     * @return string The User-Agent string.
     */
    private function build_user_agent( string $type ): string {
        $site_url = get_bloginfo( 'url' );
        $wp_version = get_bloginfo( 'version' );

        return match ( $type ) {
            // Browser-like UA for web scraping
            'web' => sprintf(
                'Mozilla/5.0 (compatible; WordPress/%s; +%s) KISS-SBI/%s',
                $wp_version,
                $site_url,
                self::PLUGIN_VERSION
            ),
            // Simple UA for API requests
            'api' => sprintf(
                'KISS-Smart-Batch-Installer/%s WordPress/%s (+%s)',
                self::PLUGIN_VERSION,
                $wp_version,
                $site_url
            ),
            // Default fallback
            default => sprintf(
                'KISS-Smart-Batch-Installer/%s',
                self::PLUGIN_VERSION
            ),
        };
    }

    /**
     * Get the default configuration.
     *
     * Useful for inspection and testing.
     *
     * @return array The default configuration.
     */
    public function get_default_config(): array {
        return self::DEFAULT_CONFIG;
    }

    /**
     * Check if a response indicates a successful request.
     *
     * @param array|WP_Error $response The response to check.
     * @return bool True if successful (2xx status), false otherwise.
     */
    public function is_success( $response ): bool {
        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        return $code >= 200 && $code < 300;
    }

    /**
     * Check if a response indicates a retriable error.
     *
     * Retriable errors are transient failures that may succeed on retry:
     * - WP_Error (network issues)
     * - 5xx server errors
     * - 429 Too Many Requests
     * - 408 Request Timeout
     *
     * @param array|WP_Error $response The response to check.
     * @return bool True if the error is retriable.
     */
    public function is_retriable( $response ): bool {
        if ( is_wp_error( $response ) ) {
            return true;
        }

        $code = wp_remote_retrieve_response_code( $response );

        // 5xx server errors are retriable
        if ( $code >= 500 && $code < 600 ) {
            return true;
        }

        // Specific retriable status codes
        return in_array( $code, [ 429, 408 ], true );
    }

    /**
     * Extract a structured error from a response.
     *
     * @param array|WP_Error $response The response to extract error from.
     * @param string         $context  Context for the error message.
     * @return WP_Error The extracted or generated error.
     */
    public function extract_error( $response, string $context = 'GitHub request' ): WP_Error {
        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'github_request_failed',
                sprintf(
                    /* translators: 1: context, 2: error message */
                    __( '%1$s failed: %2$s', 'kiss-smart-batch-installer' ),
                    $context,
                    $response->get_error_message()
                ),
                [ 'original_error' => $response ]
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        // Try to extract message from JSON response
        $message = '';
        $decoded = json_decode( $body, true );
        if ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
            $message = $decoded['message'];
        }

        $error_code = match ( true ) {
            $code === 404 => 'github_not_found',
            $code === 403 => 'github_forbidden',
            $code === 429 => 'github_rate_limited',
            $code >= 500  => 'github_server_error',
            default       => 'github_error',
        };

        $error_message = match ( $code ) {
            404 => __( 'Resource not found on GitHub.', 'kiss-smart-batch-installer' ),
            403 => $message ?: __( 'Access forbidden. May be rate limited or private.', 'kiss-smart-batch-installer' ),
            429 => __( 'Rate limited by GitHub. Please try again later.', 'kiss-smart-batch-installer' ),
            default => sprintf(
                /* translators: 1: context, 2: HTTP status code */
                __( '%1$s returned HTTP %2$d', 'kiss-smart-batch-installer' ),
                $context,
                $code
            ),
        };

        return new WP_Error(
            $error_code,
            $error_message,
            [
                'status_code' => $code,
                'body'        => substr( $body, 0, 500 ),
            ]
        );
    }
}


<?php
/**
 * GitHub Repository Service for fetching public repositories.
 *
 * @package SBI\Services
 */

namespace SBI\Services;

use WP_Error;

/**
 * Handles GitHub API interactions for public repositories.
 */
class GitHubService {

    /**
     * GitHub API base URL.
     */
    private const API_BASE = 'https://api.github.com';

    /**
     * GitHub web base URL for fallback.
     */
    private const WEB_BASE = 'https://github.com';

    /**
     * Cache expiration time (1 hour).
     */
    private const CACHE_EXPIRATION = HOUR_IN_SECONDS;

    /**
     * User agent for API requests.
     */
    private const USER_AGENT = 'KISS-Smart-Batch-Installer/1.0.0';

    /**
     * Get current GitHub configuration.
     *
     * @return array Configuration array with username and repositories.
     */
    public function get_configuration(): array {
        $organization = get_option( 'sbi_github_organization', '' );
        $repositories = [];

        // If organization is configured, fetch repositories
        if ( ! empty( $organization ) ) {
            $repos = $this->fetch_repositories_for_account( $organization );
            if ( ! is_wp_error( $repos ) && is_array( $repos ) ) {
                $repositories = array_column( $repos, 'full_name' );
            }
        }

        return [
            'username' => $organization, // For backward compatibility with tests
            'organization' => $organization,
            'repositories' => $repositories,
            'fetch_method' => get_option( 'sbi_fetch_method', 'web_only' ),
            'repository_limit' => get_option( 'sbi_repository_limit', 50 ),
            'skip_plugin_detection' => get_option( 'sbi_skip_plugin_detection', 0 ),
            'debug_ajax' => get_option( 'sbi_debug_ajax', 0 )
        ];
    }

    /**
     * Determine if a GitHub account is a user or organization
     *
     * @param string $account_name GitHub account name
     * @return string|WP_Error 'User' or 'Organization' on success, WP_Error on failure
     */
    private function get_account_type( string $account_name ) {
        // Try organization first
        $org_url = sprintf( '%s/orgs/%s', self::API_BASE, urlencode( $account_name ) );
        $args = [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/vnd.github.v3+json',
            ],
        ];

        $response = wp_remote_get( $org_url, $args );

        if ( ! is_wp_error( $response ) ) {
            $response_code = wp_remote_retrieve_response_code( $response );
            if ( 200 === $response_code ) {
                return 'Organization';
            } elseif ( 403 === $response_code ) {
                // Check if it's a rate limit issue
                $response_body = wp_remote_retrieve_body( $response );
                if ( strpos( $response_body, 'rate limit' ) !== false ) {
                    return new WP_Error(
                        'rate_limit_exceeded',
                        __( 'GitHub API rate limit exceeded. Please try again later or consider using a GitHub token for higher limits.', 'kiss-smart-batch-installer' )
                    );
                }
            }
        }

        // Try user
        $user_url = sprintf( '%s/users/%s', self::API_BASE, urlencode( $account_name ) );
        $response = wp_remote_get( $user_url, $args );

        if ( ! is_wp_error( $response ) ) {
            $response_code = wp_remote_retrieve_response_code( $response );
            if ( 200 === $response_code ) {
                return 'User';
            } elseif ( 403 === $response_code ) {
                // Check if it's a rate limit issue
                $response_body = wp_remote_retrieve_body( $response );
                if ( strpos( $response_body, 'rate limit' ) !== false ) {
                    return new WP_Error(
                        'rate_limit_exceeded',
                        __( 'GitHub API rate limit exceeded. Please try again later or consider using a GitHub token for higher limits.', 'kiss-smart-batch-installer' )
                    );
                }
            }
        }

        return new WP_Error(
            'account_not_found',
            sprintf( __( 'GitHub account "%s" not found or API limit exceeded.', 'kiss-smart-batch-installer' ), $account_name )
        );
    }

    /**
     * Fetch repositories from configured GitHub organization.
     * This is a convenience method for tests and internal use.
     *
     * @param bool $force_refresh Whether to bypass cache
     * @param int  $limit Maximum number of repositories to return (0 = no limit)
     * @return array|WP_Error Array of repositories or WP_Error on failure
     */
    public function fetch_repositories( bool $force_refresh = false, int $limit = 0 ) {
        $organization = get_option( 'sbi_github_organization', '' );

        if ( empty( $organization ) ) {
            return new WP_Error( 'no_organization', __( 'No GitHub organization configured.', 'kiss-smart-batch-installer' ) );
        }

        return $this->fetch_repositories_for_account( $organization, $force_refresh, $limit );
    }

    /**
     * Fetch repositories from GitHub account (user or organization)
     *
     * @param string $account_name GitHub account name
     * @param bool   $force_refresh Whether to bypass cache
     * @param int    $limit Maximum number of repositories to return (0 = no limit)
     * @return array|WP_Error Array of repositories or WP_Error on failure
     */
    public function fetch_repositories_for_account( string $account_name, bool $force_refresh = false, int $limit = 0 ) {
        if ( empty( $account_name ) ) {
            return new WP_Error( 'invalid_account', __( 'Account name cannot be empty.', 'kiss-smart-batch-installer' ) );
        }

        // v2 cache key to avoid old caches that stored limited results
        $cache_key = 'sbi_github_repos_v2_' . sanitize_key( $account_name );

        // Check cache first unless force refresh
        if ( ! $force_refresh ) {
            $cached_data = get_transient( $cache_key );
            if ( false !== $cached_data && is_array( $cached_data ) ) {
                // Always slice per-request; cache stores full list
                return ( $limit > 0 && count( $cached_data ) > $limit )
                    ? array_slice( $cached_data, 0, $limit )
                    : $cached_data;
            }
        }

        // Check user preference for fetch method
        $fetch_method = get_option( 'sbi_fetch_method', 'web_only' ); // Default to web-only due to API reliability issues

        // If user prefers web-only, skip API entirely
        if ( 'web_only' === $fetch_method ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'KISS Smart Batch Installer: Using web-only method per user preference' );
            }

            $web_result = $this->fetch_repositories_via_web( $account_name );
            if ( ! is_wp_error( $web_result ) ) {
                $processed_repos = $this->process_repositories( $web_result );

                // Cache the full list, not the limited slice
                set_transient( $cache_key, $processed_repos, self::CACHE_EXPIRATION );

                // Save permanent cache for graceful degradation (v1.0.33)
                $this->save_permanent_cache( $account_name, $processed_repos );

                // Return per-request limited view
                return ( $limit > 0 && count( $processed_repos ) > $limit )
                    ? array_slice( $processed_repos, 0, $limit )
                    : $processed_repos;
            }

            return $web_result; // Return the web error
        }

        // Try users endpoint first (more common and efficient)
        $base_url = sprintf( '%s/users/%s/repos', self::API_BASE, urlencode( $account_name ) );
        $query_params = [
            'type' => 'public',
            'sort' => 'updated',
            'per_page' => 50,
        ];
        $url = add_query_arg( $query_params, $base_url );

        $args = [
            'timeout' => 15,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/vnd.github.v3+json',
            ],
        ];

        // Debug logging
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'KISS Smart Batch Installer: Fetching from URL: ' . $url );
        }

        $response = $this->fetch_with_retry( $url, $args );

        if ( is_wp_error( $response ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'KISS Smart Batch Installer: GitHub API error: ' . $response->get_error_message() );
            }
            return new WP_Error(
                'github_request_failed',
                sprintf( __( 'Failed to fetch repositories: %s', 'kiss-smart-batch-installer' ), $response->get_error_message() )
            );
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'KISS Smart Batch Installer: GitHub API response status: ' . $response_code );
        }

        // If user endpoint fails with 404, try organization endpoint
        if ( 404 === $response_code ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'KISS Smart Batch Installer: User endpoint failed, trying organization endpoint' );
            }

            // Try organization endpoint
            $org_base_url = sprintf( '%s/orgs/%s/repos', self::API_BASE, urlencode( $account_name ) );
            $org_url = add_query_arg( $query_params, $org_base_url );

            $response = $this->fetch_with_retry( $org_url, $args );

            if ( is_wp_error( $response ) ) {
                return new WP_Error(
                    'github_request_failed',
                    sprintf( __( 'Failed to fetch repositories: %s', 'kiss-smart-batch-installer' ), $response->get_error_message() )
                );
            }

            $response_code = wp_remote_retrieve_response_code( $response );
        }

        if ( 200 !== $response_code ) {
            $response_body = wp_remote_retrieve_body( $response );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'KISS Smart Batch Installer: GitHub API error response: ' . $response_body );
            }

            // Handle rate limiting specifically - try web fallback if allowed
            if ( 403 === $response_code && strpos( $response_body, 'rate limit' ) !== false ) {
                // Only try web fallback if user allows it
                if ( 'api_only' !== $fetch_method ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( 'KISS Smart Batch Installer: API rate limited, trying web fallback' );
                    }

                    $web_result = $this->fetch_repositories_via_web( $account_name );
                    if ( ! is_wp_error( $web_result ) ) {
                        // Process the web-scraped repositories
                        $processed_repos = $this->process_repositories( $web_result );

                        // Cache the full results
                        set_transient( $cache_key, $processed_repos, self::CACHE_EXPIRATION );

                        // Return per-request limited view
                        return ( $limit > 0 && count( $processed_repos ) > $limit )
                            ? array_slice( $processed_repos, 0, $limit )
                            : $processed_repos;
                    }

                    // If web fallback also fails, return the original rate limit error
                    return new WP_Error(
                        'rate_limit_exceeded',
                        __( 'GitHub API rate limit exceeded and web fallback failed. Please try again later.', 'kiss-smart-batch-installer' )
                    );
                } else {
                    // User prefers API-only, don't try web fallback
                    return new WP_Error(
                        'rate_limit_exceeded',
                        __( 'GitHub API rate limit exceeded. Please try again later or change fetch method to allow web fallback.', 'kiss-smart-batch-installer' )
                    );
                }
            }

            // Handle account not found - try web fallback if allowed
            if ( 404 === $response_code ) {
                // Only try web fallback if user allows it
                if ( 'api_only' !== $fetch_method ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( 'KISS Smart Batch Installer: API returned 404, trying web fallback' );
                    }

                    $web_result = $this->fetch_repositories_via_web( $account_name );
                    if ( ! is_wp_error( $web_result ) ) {
                        // Process the web-scraped repositories
                        $processed_repos = $this->process_repositories( $web_result );

                        // Cache the full results
                        set_transient( $cache_key, $processed_repos, self::CACHE_EXPIRATION );

                        // Return per-request limited view
                        return ( $limit > 0 && count( $processed_repos ) > $limit )
                            ? array_slice( $processed_repos, 0, $limit )
                            : $processed_repos;
                    }
                }

                // If web fallback also fails or not allowed, return account not found
                return new WP_Error(
                    'account_not_found',
                    sprintf( __( 'GitHub account "%s" not found.', 'kiss-smart-batch-installer' ), $account_name )
                );
            }

            return new WP_Error(
                'github_api_error',
                sprintf( __( 'GitHub API returned error code: %d', 'kiss-smart-batch-installer' ), $response_code )
            );
        }

        $body = wp_remote_retrieve_body( $response );
        $repositories = json_decode( $body, true );

        if ( null === $repositories ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'KISS Smart Batch Installer: Invalid JSON response: ' . substr( $body, 0, 500 ) );
            }
            return new WP_Error( 'invalid_json', __( 'Invalid JSON response from GitHub API.', 'kiss-smart-batch-installer' ) );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'KISS Smart Batch Installer: Found ' . count( $repositories ) . ' repositories from GitHub API' );
        }

        // Process and filter repositories
        $processed_repos = $this->process_repositories( $repositories );

        // Cache the full results for 1 hour
        set_transient( $cache_key, $processed_repos, HOUR_IN_SECONDS );

        // Save permanent cache for graceful degradation (v1.0.33)
        $this->save_permanent_cache( $account_name, $processed_repos );

        // Return per-request limited view
        return ( $limit > 0 && count( $processed_repos ) > $limit )
            ? array_slice( $processed_repos, 0, $limit )
            : $processed_repos;
    }
    /**
     * ┌─────────────────────────────────────────────────────────────────────────┐
     * │ GRACEFUL DEGRADATION - v1.0.33                                          │
     * │ - Returns stale cache when GitHub is unavailable                        │
     * │ - Indicates data freshness to UI                                        │
     * │ - Prevents full failure when GitHub is down                             │
     * │ DO NOT REMOVE: Critical for reliability when GitHub has issues          │
     * └─────────────────────────────────────────────────────────────────────────┘
     */

    /**
     * Fetch repositories with graceful degradation and data source tracking.
     * If fresh data cannot be fetched, returns stale cached data with a flag.
     *
     * @since 1.0.33
     * @since 1.0.34 Added data_source and cache_info tracking
     * @param string $account_name GitHub account name.
     * @param bool   $force_refresh Whether to force refresh (will still fallback to stale).
     * @param int    $limit Maximum repos to return (0 = no limit).
     * @return array{repositories: array, is_stale: bool, stale_age: int|null, error: string|null, data_source: string, cache_info: array}
     */
    public function fetch_repositories_graceful( string $account_name, bool $force_refresh = false, int $limit = 0 ): array {
        $cache_key = 'sbi_github_repos_v2_' . sanitize_key( $account_name );
        $stale_cache_key = 'sbi_github_repos_stale_' . sanitize_key( $account_name );

        // Check what caches exist (for info display)
        $transient_cache = get_transient( $cache_key );
        $stale_metadata = get_option( $stale_cache_key, [] );
        $permanent_cache = get_option( 'sbi_github_repos_permanent_' . sanitize_key( $account_name ), [] );

        // Build cache info for display
        $cache_info = [
            'transient_exists' => false !== $transient_cache && is_array( $transient_cache ),
            'transient_count' => is_array( $transient_cache ) ? count( $transient_cache ) : 0,
            'permanent_exists' => ! empty( $permanent_cache ) && is_array( $permanent_cache ),
            'permanent_count' => is_array( $permanent_cache ) ? count( $permanent_cache ) : 0,
            'cached_at' => $stale_metadata['cached_at'] ?? null,
            'cache_age_human' => isset( $stale_metadata['cached_at'] ) && $stale_metadata['cached_at'] > 0
                ? human_time_diff( $stale_metadata['cached_at'], time() ) . ' ago'
                : null,
        ];

        // Check if we're getting from transient cache (not force refresh)
        if ( ! $force_refresh && false !== $transient_cache && is_array( $transient_cache ) ) {
            // Data from transient cache
            $repos = ( $limit > 0 && count( $transient_cache ) > $limit )
                ? array_slice( $transient_cache, 0, $limit )
                : $transient_cache;

            return [
                'repositories' => $repos,
                'is_stale' => false,
                'stale_age' => null,
                'error' => null,
                'data_source' => 'cache',
                'cache_info' => $cache_info,
            ];
        }

        // Try to get fresh data
        $result = $this->fetch_repositories_for_account( $account_name, $force_refresh, $limit );

        // Determine the source based on fetch method setting
        $fetch_method = get_option( 'sbi_fetch_method', 'web_only' );
        $fresh_source = ( 'web_only' === $fetch_method ) ? 'web_scraper' : 'api';

        if ( ! is_wp_error( $result ) ) {
            // Fresh data retrieved successfully - update cache info
            $cache_info['transient_exists'] = true;
            $cache_info['transient_count'] = count( $result );
            $cache_info['cached_at'] = time();
            $cache_info['cache_age_human'] = 'just now';

            return [
                'repositories' => $result,
                'is_stale' => false,
                'stale_age' => null,
                'error' => null,
                'data_source' => $fresh_source,
                'cache_info' => $cache_info,
            ];
        }

        // Fresh fetch failed - try to return stale transient cache
        if ( false !== $transient_cache && is_array( $transient_cache ) ) {
            $cached_at = $stale_metadata['cached_at'] ?? 0;
            $stale_age = $cached_at > 0 ? ( time() - $cached_at ) : null;

            error_log( sprintf( 'SBI GITHUB SERVICE: Graceful degradation - returning stale transient cache for %s (age: %s)',
                $account_name,
                $stale_age ? human_time_diff( time() - $stale_age, time() ) : 'unknown'
            ) );

            $repos = ( $limit > 0 && count( $transient_cache ) > $limit )
                ? array_slice( $transient_cache, 0, $limit )
                : $transient_cache;

            return [
                'repositories' => $repos,
                'is_stale' => true,
                'stale_age' => $stale_age,
                'error' => $result->get_error_message(),
                'data_source' => 'stale_cache',
                'cache_info' => $cache_info,
            ];
        }

        // Check for permanent stale cache (stored in options, never expires)
        if ( ! empty( $permanent_cache ) && is_array( $permanent_cache ) ) {
            $cached_at = $stale_metadata['cached_at'] ?? 0;
            $stale_age = $cached_at > 0 ? ( time() - $cached_at ) : null;

            error_log( sprintf( 'SBI GITHUB SERVICE: Graceful degradation - returning permanent stale cache for %s', $account_name ) );

            $repos = ( $limit > 0 && count( $permanent_cache ) > $limit )
                ? array_slice( $permanent_cache, 0, $limit )
                : $permanent_cache;

            return [
                'repositories' => $repos,
                'is_stale' => true,
                'stale_age' => $stale_age,
                'error' => $result->get_error_message(),
                'data_source' => 'permanent_cache',
                'cache_info' => $cache_info,
            ];
        }

        // No cache available at all - return empty with error
        error_log( sprintf( 'SBI GITHUB SERVICE: Graceful degradation - no cache available for %s', $account_name ) );

        return [
            'repositories' => [],
            'is_stale' => false,
            'stale_age' => null,
            'error' => $result->get_error_message(),
            'data_source' => 'none',
            'cache_info' => $cache_info,
        ];
    }

    /**
     * Save a permanent copy of repository data for graceful degradation.
     * Called after successful fresh fetch.
     *
     * @since 1.0.33
     * @param string $account_name GitHub account name.
     * @param array  $repositories Repository data to cache permanently.
     */
    public function save_permanent_cache( string $account_name, array $repositories ): void {
        if ( empty( $repositories ) ) {
            return;
        }

        $cache_key = 'sbi_github_repos_permanent_' . sanitize_key( $account_name );
        $metadata_key = 'sbi_github_repos_stale_' . sanitize_key( $account_name );

        update_option( $cache_key, $repositories, false ); // Don't autoload
        update_option( $metadata_key, [
            'cached_at' => time(),
            'count' => count( $repositories ),
        ], false );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( 'SBI GITHUB SERVICE: Saved permanent cache for %s (%d repos)', $account_name, count( $repositories ) ) );
        }
    }

    /**
     * Get total number of public repositories for a GitHub account.
     * Tries organization endpoint first, then user endpoint. Caches for 10 minutes.
     *
     * @param string $account_name
     * @return int|WP_Error Total public repos or WP_Error on failure
     */
    public function get_total_public_repos( string $account_name ) {
        if ( empty( $account_name ) ) {
            return new WP_Error( 'invalid_account', __( 'Account name cannot be empty.', 'kiss-smart-batch-installer' ) );
        }

        $cache_key = 'sbi_github_total_repos_' . sanitize_key( $account_name );
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) {
            return (int) $cached;
        }

        $args = [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/vnd.github.v3+json',
            ],
        ];

        // Try organization endpoint first
        $org_url = sprintf( '%s/orgs/%s', self::API_BASE, urlencode( $account_name ) );
        $response = wp_remote_get( $org_url, $args );
        if ( ! is_wp_error( $response ) ) {
            $code = wp_remote_retrieve_response_code( $response );
            if ( 200 === $code ) {
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( is_array( $body ) && isset( $body['public_repos'] ) ) {
                    set_transient( $cache_key, (int) $body['public_repos'], 10 * MINUTE_IN_SECONDS );
                    return (int) $body['public_repos'];
                }
            }
        }

        // Fall back to user endpoint
        $user_url = sprintf( '%s/users/%s', self::API_BASE, urlencode( $account_name ) );
        $response = wp_remote_get( $user_url, $args );
        if ( ! is_wp_error( $response ) ) {
            $code = wp_remote_retrieve_response_code( $response );
            if ( 200 === $code ) {
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( is_array( $body ) && isset( $body['public_repos'] ) ) {
                    set_transient( $cache_key, (int) $body['public_repos'], 10 * MINUTE_IN_SECONDS );
                    return (int) $body['public_repos'];
                }
            }
        }

        return new WP_Error( 'total_repos_unavailable', __( 'Unable to retrieve total repositories for account.', 'kiss-smart-batch-installer' ) );
    }



    /**
     * Fetch repositories for a GitHub organization.
     *
     * @param string $organization GitHub organization name.
     * @param bool   $force_refresh Whether to bypass cache.
     * @return array|WP_Error Array of repositories or WP_Error on failure.
     */
    public function get_organization_repositories( string $organization, bool $force_refresh = false ) {
        if ( empty( $organization ) ) {
            return new WP_Error( 'invalid_organization', __( 'Organization name cannot be empty.', 'kiss-smart-batch-installer' ) );
        }

        $cache_key = 'sbi_github_repos_' . sanitize_key( $organization );

        // Check cache first unless force refresh
        if ( ! $force_refresh ) {
            $cached_data = get_transient( $cache_key );
            if ( false !== $cached_data ) {
                return $cached_data;
            }
        }

        // Fetch from GitHub API
        $base_url = sprintf( '%s/orgs/%s/repos', self::API_BASE, urlencode( $organization ) );
        $query_params = [
            'type' => 'public',
            'sort' => 'updated',
            'per_page' => 50,
        ];
        $url = add_query_arg( $query_params, $base_url );

        $args = [
            'timeout' => 15,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/vnd.github.v3+json',
            ],
        ];

        // Debug logging
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'KISS Smart Batch Installer: Fetching from URL: ' . $url );
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'KISS Smart Batch Installer: GitHub API error: ' . $response->get_error_message() );
            }
            return new WP_Error(
                'github_request_failed',
                sprintf( __( 'Failed to fetch repositories: %s', 'kiss-smart-batch-installer' ), $response->get_error_message() )
            );
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'KISS Smart Batch Installer: GitHub API response status: ' . $response_code );
        }

        if ( 200 !== $response_code ) {
            $response_body = wp_remote_retrieve_body( $response );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'KISS Smart Batch Installer: GitHub API error response: ' . $response_body );
            }
            return new WP_Error(
                'github_api_error',
                sprintf( __( 'GitHub API returned error code: %d', 'kiss-smart-batch-installer' ), $response_code )
            );
        }

        $body = wp_remote_retrieve_body( $response );
        $repositories = json_decode( $body, true );

        if ( null === $repositories ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'KISS Smart Batch Installer: Invalid JSON response: ' . substr( $body, 0, 500 ) );
            }
            return new WP_Error( 'invalid_json', __( 'Invalid JSON response from GitHub API.', 'kiss-smart-batch-installer' ) );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'KISS Smart Batch Installer: Found ' . count( $repositories ) . ' repositories from GitHub API' );
        }

        // Process and filter repositories
        $processed_repos = $this->process_repositories( $repositories );

        // Apply limit if specified
        if ( $limit > 0 && count( $processed_repos ) > $limit ) {
            $processed_repos = array_slice( $processed_repos, 0, $limit );
        }

        // Cache the results
        set_transient( $cache_key, $processed_repos, self::CACHE_EXPIRATION );

        return $processed_repos;
    }

    /**
     * Get a specific repository.
     *
     * @param string $owner Repository owner.
     * @param string $repo  Repository name.
     * @return array|WP_Error Repository data or WP_Error on failure.
     */
    public function get_repository( string $owner, string $repo ) {
        error_log( sprintf( 'SBI GITHUB SERVICE: Getting repository %s/%s', $owner, $repo ) );

        if ( empty( $owner ) || empty( $repo ) ) {
            error_log( 'SBI GITHUB SERVICE: Invalid parameters - owner or repo empty' );
            return new WP_Error( 'invalid_params', __( 'Owner and repository name are required.', 'kiss-smart-batch-installer' ) );
        }

        $cache_key = 'sbi_github_repo_' . sanitize_key( $owner . '_' . $repo );

        // Check cache first
        $cached_data = get_transient( $cache_key );
        if ( false !== $cached_data ) {
            error_log( sprintf( 'SBI GITHUB SERVICE: Using cached data for %s/%s', $owner, $repo ) );
            return $cached_data;
        }

        $url = sprintf( '%s/repos/%s/%s', self::API_BASE, urlencode( $owner ), urlencode( $repo ) );
        error_log( sprintf( 'SBI GITHUB SERVICE: Making API request to %s', $url ) );

        $args = [
            'timeout' => 15,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/vnd.github.v3+json',
            ],
        ];

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            error_log( sprintf( 'SBI GITHUB SERVICE: API request failed for %s/%s: %s', $owner, $repo, $response->get_error_message() ) );
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        error_log( sprintf( 'SBI GITHUB SERVICE: API response code: %d for %s/%s', $response_code, $owner, $repo ) );

        if ( 200 !== $response_code ) {
            $error_message = sprintf( 'Repository not found or API error: %d', $response_code );

            // Try to get more specific error from response body
            $decoded_body = json_decode( $response_body, true );
            if ( $decoded_body && isset( $decoded_body['message'] ) ) {
                $error_message .= ' - ' . $decoded_body['message'];
            }

            // Add specific handling for common error codes
            switch ( $response_code ) {
                case 404:
                    $error_message .= ' (Repository not found or private)';
                    break;
                case 403:
                    $error_message .= ' (Access forbidden - may be rate limited or private repository)';
                    break;
                case 401:
                    $error_message .= ' (Unauthorized - authentication required)';
                    break;
            }

            error_log( sprintf( 'SBI GITHUB SERVICE: API error for %s/%s - code: %d, message: %s', $owner, $repo, $response_code, $error_message ) );
            error_log( sprintf( 'SBI GITHUB SERVICE: Response body: %s', $response_body ) );

            return new WP_Error(
                'github_api_error',
                $error_message,
                [
                    'status_code' => $response_code,
                    'url' => $url,
                    'response_body' => $response_body,
                    'owner' => $owner,
                    'repo' => $repo
                ]
            );
        }

        $body = wp_remote_retrieve_body( $response );
        $repository = json_decode( $body, true );

        if ( null === $repository ) {
            error_log( sprintf( 'SBI GITHUB SERVICE: Invalid JSON response for %s/%s', $owner, $repo ) );
            return new WP_Error( 'invalid_json', __( 'Invalid JSON response from GitHub API.', 'kiss-smart-batch-installer' ) );
        }

        error_log( sprintf( 'SBI GITHUB SERVICE: Successfully retrieved repository data for %s/%s', $owner, $repo ) );

        $processed_repo = $this->process_repository( $repository );

        // Cache for shorter time for individual repos
        set_transient( $cache_key, $processed_repo, HOUR_IN_SECONDS / 2 );

        error_log( sprintf( 'SBI GITHUB SERVICE: Repository %s/%s processed and cached', $owner, $repo ) );

        return $processed_repo;
    }

    /**
     * Get GitHub API rate limit status.
     *
     * @return array|WP_Error Rate limit data or WP_Error on failure.
     */
    public function get_rate_limit() {
        $url = self::API_BASE . '/rate_limit';
        $args = [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/vnd.github.v3+json',
            ],
        ];

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $rate_limit = json_decode( $body, true );

        return $rate_limit ?: new WP_Error( 'invalid_json', __( 'Invalid rate limit response.', 'kiss-smart-batch-installer' ) );
    }

    /**
     * Process array of repositories from GitHub API.
     *
     * @param array $repositories Raw repository data from GitHub.
     * @return array Processed repository data.
     */
    private function process_repositories( array $repositories ): array {
        $processed = [];

        foreach ( $repositories as $repo ) {
            $processed[] = $this->process_repository( $repo );
        }

        return $processed;
    }

    /**
     * Process a single repository from GitHub API.
     *
     * @param array $repo Raw repository data from GitHub.
     * @return array Processed repository data.
     */
    private function process_repository( array $repo ): array {
        return [
            'id' => $repo['id'] ?? 0,
            'name' => $repo['name'] ?? '',
            'full_name' => $repo['full_name'] ?? '',
            'description' => $repo['description'] ?? '',
            'html_url' => $repo['html_url'] ?? '',
            'clone_url' => $repo['clone_url'] ?? '',
            'default_branch' => $repo['default_branch'] ?? 'main',
            'updated_at' => $repo['updated_at'] ?? '',
            'language' => $repo['language'] ?? '',
            'size' => $repo['size'] ?? 0,
            'stargazers_count' => $repo['stargazers_count'] ?? 0,
            'archived' => $repo['archived'] ?? false,
            'disabled' => $repo['disabled'] ?? false,
            'private' => $repo['private'] ?? false,
        ];
    }

    /**
     * Clear cached repository data.
     *
     * @param string $organization Organization name (optional).
     * @return bool True on success.
     */
    public function clear_cache( string $organization = '' ): bool {
        if ( ! empty( $organization ) ) {
            $cache_key = 'sbi_github_repos_' . sanitize_key( $organization );
            return delete_transient( $cache_key );
        }

        // Clear all GitHub-related transients
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sbi_github_%'" );
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_sbi_github_%'" );

        return true;
    }

    /**
     * Fetch repositories using GitHub web interface (fallback method).
     *
     * @param string $account_name GitHub account name.
     * @return array|WP_Error Array of repositories or WP_Error on failure.
     */
    private function fetch_repositories_via_web( string $account_name ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'KISS Smart Batch Installer: Fetching repositories via web scraping for ' . $account_name );
        }

        $all_repositories = [];
        $page = 1;
        $max_pages = 20; // Handle accounts with many repositories
        $consecutive_empty_pages = 0;
        $max_consecutive_empty = 3;

        while ( $page <= $max_pages && $consecutive_empty_pages < $max_consecutive_empty ) {
            // Build URL with pagination
            $url = sprintf( '%s/%s?tab=repositories&page=%d', self::WEB_BASE, urlencode( $account_name ), $page );

            $args = [
                'timeout' => 30, // Increased timeout
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; WordPress/' . get_bloginfo( 'version' ) . '; +' . get_bloginfo( 'url' ) . ')',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'DNT' => '1',
                    'Connection' => 'keep-alive',
                    'Upgrade-Insecure-Requests' => '1',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'none',
                    'Cache-Control' => 'max-age=0',
                ],
                'sslverify' => true,
                'redirection' => 5,
            ];

            $response = wp_remote_get( $url, $args );

            if ( is_wp_error( $response ) ) {
                if ( $page === 1 ) {
                    return new WP_Error(
                        'web_request_failed',
                        sprintf( __( 'Failed to fetch repositories via web: %s', 'kiss-smart-batch-installer' ), $response->get_error_message() )
                    );
                } else {
                    // For subsequent pages, just break and return what we have
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( 'KISS Smart Batch Installer: Web request failed on page ' . $page . ', returning partial results' );
                    }
                    break;
                }
            }

            $response_code = wp_remote_retrieve_response_code( $response );
            if ( 200 !== $response_code ) {
                if ( 404 === $response_code ) {
                    if ( $page === 1 ) {
                        return new WP_Error(
                            'account_not_found',
                            sprintf( __( 'GitHub account "%s" not found.', 'kiss-smart-batch-installer' ), $account_name )
                        );
                    } else {
                        // No more pages
                        break;
                    }
                } elseif ( 429 === $response_code ) {
                    return new WP_Error(
                        'rate_limited',
                        __( 'Rate limited by GitHub. Please try again later.', 'kiss-smart-batch-installer' )
                    );
                } elseif ( $page === 1 ) {
                    return new WP_Error(
                        'web_fetch_error',
                        sprintf( __( 'GitHub web page returned error code: %d', 'kiss-smart-batch-installer' ), $response_code )
                    );
                } else {
                    // For subsequent pages, just break
                    break;
                }
            }

	            $body = wp_remote_retrieve_body( $response );
	            if ( empty( $body ) ) {
	                if ( $page === 1 ) {
	                    return new WP_Error(
	                        'empty_response',
	                        __( 'Empty response from GitHub.', 'kiss-smart-batch-installer' )
	                    );
	                } else {
	                    break;
	                }
	            }

	            // Parse repositories from HTML first
	            $page_repositories = $this->parse_repositories_from_html( $body, $account_name );

	            // Detect GitHub web UI error placeholder pages that say:
	            // "There was an error while loading. Please reload this page."
	            $normalized_body = strtolower( preg_replace( '/\s+/', ' ', $body ) );
	            $has_error_placeholder = (
	                false !== strpos( $normalized_body, 'there was an error while loading' )
	                && false !== strpos( $normalized_body, 'please reload this page' )
	            );

	            // v1.0.61: Only treat placeholder as fatal when no repositories were parsed
	            if ( $has_error_placeholder && empty( $page_repositories ) ) {

	                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	                    error_log( sprintf(
	                        'KISS Smart Batch Installer: GitHub web error placeholder detected for account "%s" on page %d.',
	                        $account_name,
	                        $page
	                    ) );
	                }

	                if ( 1 === $page ) {
	                    return new WP_Error(
	                        'github_web_error_page',
	                        __( 'GitHub returned an error page instead of the repository list. This is usually temporary or due to GitHub UI changes.', 'kiss-smart-batch-installer' )
	                    );
	                }

	                // For subsequent pages, stop paginating and return what we have so far.
	                break;
	            }

            if ( empty( $page_repositories ) ) {
                $consecutive_empty_pages++;
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'KISS Smart Batch Installer: No repositories found on page ' . $page . ' (consecutive empty: ' . $consecutive_empty_pages . ')' );
                }
            } else {
                $consecutive_empty_pages = 0; // Reset counter
                $all_repositories = array_merge( $all_repositories, $page_repositories );
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'KISS Smart Batch Installer: Found ' . count( $page_repositories ) . ' repositories on page ' . $page );
                }
            }

            $page++;

            // Add a respectful delay between requests
            if ( $page <= $max_pages && $consecutive_empty_pages < $max_consecutive_empty ) {
                usleep( 750000 ); // 0.75 seconds
            }
        }

        if ( empty( $all_repositories ) ) {
            return new WP_Error(
                'no_repositories_found',
                sprintf( __( 'No repositories found for account "%s".', 'kiss-smart-batch-installer' ), $account_name )
            );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'KISS Smart Batch Installer: Total repositories found via web scraping: ' . count( $all_repositories ) );
        }

        return $all_repositories;
    }

    /**
     * Parse repository data from GitHub HTML page.
     *
     * @param string $html HTML content from GitHub page.
     * @param string $account_name GitHub account name.
     * @return array Array of repository data.
     */
    private function parse_repositories_from_html( string $html, string $account_name ): array {
        $repositories = [];

        // Use DOMDocument to parse HTML safely
        $dom = new \DOMDocument();

        // Suppress warnings for malformed HTML
        libxml_use_internal_errors( true );
        $dom->loadHTML( $html );
        libxml_clear_errors();

        $xpath = new \DOMXPath( $dom );

        $seen_repos = [];

        // Try multiple selectors to handle different GitHub layouts.
        // IMPORTANT: Aggregate results across ALL selectors and de-duplicate.
        $selectors = [
            // v1.0.46: Primary selector - main repository list with itemprop (NOT pinned repos)
            '//a[@itemprop="name codeRepository"][contains(@href, "/' . $account_name . '/")]',
            // Box-row list items (main repo list)
            '//li[contains(@class, "Box-row")]//a[contains(@href, "/' . $account_name . '/")][not(contains(@href, "/issues"))][not(contains(@href, "/pulls"))][not(contains(@href, "/wiki"))][not(contains(@href, "/actions"))][not(contains(@href, "/security"))][not(contains(@href, "/settings"))]',
            // Current GitHub layout (2025) - repository list with h3 links
            '//h3/a[contains(@href, "/' . $account_name . '/") and not(contains(@href, "/issues")) and not(contains(@href, "/pulls")) and not(contains(@href, "/wiki")) and not(contains(@href, "/actions")) and not(contains(@href, "/security")) and not(contains(@href, "/settings"))]',
            // Modern GitHub layout - repository list items with data-testid
            '//div[@data-testid="results-list"]//h3/a[contains(@href, "/' . $account_name . '/")]',
            // Alternative layout - repository cards
            '//article//h3/a[contains(@href, "/' . $account_name . '/")]',
            // Fallback - any link that looks like a repository
            '//a[contains(@href, "/' . $account_name . '/") and not(contains(@href, "/issues")) and not(contains(@href, "/pulls")) and not(contains(@href, "/wiki")) and not(contains(@href, "/actions")) and not(contains(@href, "/security")) and not(contains(@href, "/settings"))]',
        ];

        $total_links_found = 0;

        foreach ( $selectors as $selector_index => $selector ) {
            $repo_links = $xpath->query( $selector );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'KISS Smart Batch Installer: Selector ' . ( $selector_index + 1 ) . ' found ' . $repo_links->length . ' links' );
            }

            if ( ! $repo_links || $repo_links->length === 0 ) {
                continue;
            }

            $total_links_found += $repo_links->length;

            foreach ( $repo_links as $link ) {
                $href = $link->getAttribute( 'href' );

                // Extract repository name from href like "/account/repo" or "/account/repo/"
                if ( preg_match( '#^/' . preg_quote( $account_name, '#' ) . '/([^/\?\#]+)/?$#', $href, $matches ) ) {
                    $repo_name = $matches[1];

                    // Skip if we've already seen this repo
                    if ( isset( $seen_repos[ $repo_name ] ) ) {
                        continue;
                    }

                    $seen_repos[ $repo_name ] = true;

                    // Get repository description from nearby elements
                    $description = '';
                    $language = '';
                    $updated_at = '';
                    // Try to find description in various ways
                    $parent = $link->parentNode;
                    $attempts = 0;
                    while ( $parent && $parent->nodeType === XML_ELEMENT_NODE && $attempts < 8 ) {
                        // Look for description - try multiple modern GitHub selectors (2025)
                        if ( empty( $description ) ) {
                            // Modern GitHub layout - description in various containers
                            $desc_selectors = [
                                // Standard description classes
                                './/p[contains(@class, "description") or contains(@class, "repo-description")]',
                                // Modern GitHub uses these patterns
                                './/p[contains(@class, "pinned-item-desc") or contains(@class, "color-fg-muted")]',
                                // Description span/div with color classes
                                './/span[contains(@class, "color-fg-muted") and not(contains(@class, "language"))]',
                                // Inline description text (generic paragraph after repo name)
                                './/p[not(@class) or @class=""]',
                                // Try itemprop attribute for SEO-friendly markup
                                './/*[@itemprop="description"]',
                                // Look for any element with mb-1 class that contains text (common pattern)
                                './/p[contains(@class, "mb-1")]',
                                // Try div with text content as fallback
                                './/div[contains(@class, "pinned-item-desc")]',
                            ];
                            
                            foreach ( $desc_selectors as $desc_selector ) {
                                $desc_elements = $xpath->query( $desc_selector, $parent );
                                if ( $desc_elements && $desc_elements->length > 0 ) {
	                                    $potential_desc = trim( $desc_elements->item( 0 )->textContent );
	
	                                    // Detect GitHub error placeholder text that can appear when the web UI fails
	                                    $normalized_desc      = preg_replace( '/\s+/', ' ', $potential_desc );
	                                    $lower_desc           = strtolower( $normalized_desc );
	                                    $is_error_placeholder = (
	                                        false !== strpos( $lower_desc, 'there was an error while loading' )
	                                        && false !== strpos( $lower_desc, 'please reload this page' )
	                                    );
	
	                                    if ( $is_error_placeholder ) {
	                                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	                                            error_log(
	                                                sprintf(
	                                                    'KISS Smart Batch Installer: Ignoring GitHub error placeholder description for %s/%s: %s',
	                                                    $account_name,
	                                                    $repo_name,
	                                                    mb_substr( $normalized_desc, 0, 200 )
	                                                )
	                                            );
	                                        }

	                                        // Provide a clear, plugin-specific description so the user understands the issue
	                                        $description = __( 'Repo Description not available or possibly an error', 'kiss-smart-batch-installer' );
	                                        break;
	                                    }
	
	                                    // Filter out non-description content (dates, language names, etc.)
	                                    if ( ! empty( $potential_desc ) && 
	                                         strlen( $potential_desc ) > 10 && 
	                                         ! preg_match( '/^(Updated|PHP|JavaScript|CSS|HTML|TypeScript|Python|Ruby|Go|Rust|Java|C\+\+|Shell)\s/i', $potential_desc ) &&
	                                         ! preg_match( '/^\d+\s+(stars?|forks?|issues?)/i', $potential_desc ) &&
	                                         strpos( $potential_desc, 'ago' ) === false ) {
	                                        $description = $potential_desc;
	                                        break;
	                                    }
                                }
                            }
                        }

                        // Look for language information - try multiple selectors
                        if ( empty( $language ) ) {
                            $lang_selectors = [
                                './/span[contains(@class, "language")]',
                                './/span[@itemprop="programmingLanguage"]',
                                './/span[contains(@class, "repo-language-color")]/following-sibling::span',
                                './/span[contains(@class, "d-inline-block") and contains(@class, "ml-0")]',
                            ];
                            
                            foreach ( $lang_selectors as $lang_selector ) {
                                $lang_elements = $xpath->query( $lang_selector, $parent );
                                if ( $lang_elements && $lang_elements->length > 0 ) {
                                    $potential_lang = trim( $lang_elements->item( 0 )->textContent );
                                    // Validate it looks like a programming language
                                    if ( ! empty( $potential_lang ) && 
                                         preg_match( '/^(PHP|JavaScript|CSS|HTML|TypeScript|Python|Ruby|Go|Rust|Java|C\+\+|C#|Shell|Zig|Swift|Kotlin|Scala|R|MATLAB|Perl|Lua|Dart|Elixir|Clojure|Haskell|Vue|Svelte|SCSS|Less|Sass)$/i', $potential_lang ) ) {
                                        $language = $potential_lang;
                                        break;
                                    }
                                }
                            }
                        }

                        // Look for updated time
                        if ( empty( $updated_at ) ) {
                            $time_elements = $xpath->query( './/relative-time', $parent );
                            if ( $time_elements && $time_elements->length > 0 ) {
                                $updated_at = $time_elements->item( 0 )->getAttribute( 'datetime' );
                            }
                        }

                        if ( $description && $language && $updated_at ) {
                            break; // Found some info, stop looking
                        }

                        $parent = $parent->parentNode;
                        $attempts++;
                    }

                    // Create repository data structure similar to API response
                    $repositories[] = [
                        'id' => crc32( $account_name . '/' . $repo_name ), // Generate a pseudo-ID
                        'name' => $repo_name,
                        'full_name' => $account_name . '/' . $repo_name,
                        'description' => $description ?: null,
                        'html_url' => self::WEB_BASE . '/' . $account_name . '/' . $repo_name,
                        'clone_url' => self::WEB_BASE . '/' . $account_name . '/' . $repo_name . '.git',
                        'default_branch' => 'main', // Default assumption
                        'updated_at' => $updated_at ?: null,
                        'language' => $language ?: null,
                        'size' => 0, // Not available via web scraping
                        'stargazers_count' => 0, // Not available via web scraping
                        'archived' => false, // Default assumption
                        'disabled' => false, // Default assumption
                        'private' => false, // We're only looking at public repos
                        'source' => 'web', // Mark as web-scraped
                    ];
                }
            }
        }

        if ( empty( $repositories ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'KISS Smart Batch Installer: No repository links found across selectors' );
                // Log a sample of the HTML to help debug
                $sample_html = substr( $html, 0, 2000 );
                error_log( 'KISS Smart Batch Installer: HTML sample: ' . $sample_html );
            }
            return $repositories;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'KISS Smart Batch Installer: Parsed ' . count( $repositories ) . ' repositories from HTML (total links scanned: ' . $total_links_found . ')' );
        }

        return $repositories;
    }

    /**
     * Fetch with retry logic using exponential backoff for better error recovery.
     *
     * ┌─────────────────────────────────────────────────────────────────────────┐
     * │ NETWORK RESILIENCE - v1.0.33                                            │
     * │ - Exponential backoff: 1s, 2s, 4s with jitter                           │
     * │ - Tracks rate limits from response headers                              │
     * │ - Logs retry attempts for debugging                                     │
     * │ DO NOT REMOVE: Handles transient network failures gracefully            │
     * └─────────────────────────────────────────────────────────────────────────┘
     *
     * @param string $url URL to fetch.
     * @param array  $args Request arguments.
     * @param int    $max_retries Maximum number of retries.
     * @return array|WP_Error Response or WP_Error on failure.
     */
    private function fetch_with_retry( $url, $args, $max_retries = 3 ) {
        $attempts = 0;
        $last_error = null;

        while ( $attempts < $max_retries ) {
            $response = wp_remote_get( $url, $args );

            if ( ! is_wp_error( $response ) ) {
                $code = wp_remote_retrieve_response_code( $response );

                // Track rate limit info from headers (v1.0.33)
                $this->track_rate_limit_headers( $response );

                if ( $code === 200 ) {
                    return $response;
                }

                // If rate limited, don't retry - wait for rate limit reset
                if ( $code === 403 || $code === 429 ) {
                    $this->log_rate_limit_hit( $response );
                    return $response;
                }

                // 5xx server errors are retryable
                if ( $code >= 500 && $code < 600 ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( sprintf(
                            'KISS SBI: Server error %d on attempt %d/%d for %s',
                            $code, $attempts + 1, $max_retries, $url
                        ) );
                    }
                }
            } else {
                // Network error
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( sprintf(
                        'KISS SBI: Network error on attempt %d/%d: %s',
                        $attempts + 1, $max_retries, $response->get_error_message()
                    ) );
                }
            }

            $last_error = $response;
            $attempts++;

            if ( $attempts < $max_retries ) {
                // Exponential backoff with jitter: base * 2^attempts + random(0-500ms)
                $base_delay = 1000; // 1 second base
                $delay_ms = $base_delay * pow( 2, $attempts - 1 ) + wp_rand( 0, 500 );
                $delay_seconds = $delay_ms / 1000;

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( sprintf( 'KISS SBI: Retrying in %.2f seconds...', $delay_seconds ) );
                }

                usleep( (int) ( $delay_ms * 1000 ) ); // Convert to microseconds
            }
        }

        return $last_error;
    }

    /**
     * Track rate limit information from GitHub response headers.
     * Stores remaining requests and reset time for proactive handling.
     *
     * @since 1.0.33
     * @param array $response WordPress HTTP response.
     */
    private function track_rate_limit_headers( $response ): void {
        $remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
        $limit = wp_remote_retrieve_header( $response, 'x-ratelimit-limit' );
        $reset = wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );

        if ( $remaining !== '' && $reset !== '' ) {
            $rate_limit_data = [
                'remaining' => (int) $remaining,
                'limit' => (int) $limit,
                'reset' => (int) $reset,
                'reset_in' => (int) $reset - time(),
                'updated_at' => time(),
            ];

            set_transient( 'sbi_github_rate_limit', $rate_limit_data, 60 );

            // Warn if getting low on requests
            if ( (int) $remaining < 10 && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    'KISS SBI: GitHub API rate limit low: %d/%d remaining, resets in %d seconds',
                    $remaining, $limit, (int) $reset - time()
                ) );
            }
        }
    }

    /**
     * Log when rate limit is hit for debugging.
     *
     * @since 1.0.33
     * @param array $response WordPress HTTP response.
     */
    private function log_rate_limit_hit( $response ): void {
        $reset = wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
        $reset_in = $reset ? (int) $reset - time() : 0;

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                'KISS SBI: GitHub API rate limit hit! Resets in %d seconds (%s)',
                $reset_in,
                $reset ? gmdate( 'Y-m-d H:i:s', (int) $reset ) : 'unknown'
            ) );
        }
    }

    /**
     * Get cached rate limit status.
     * Returns current rate limit info without making an API call.
     *
     * @since 1.0.33
     * @return array|false Rate limit data or false if not cached.
     */
    public function get_cached_rate_limit() {
        return get_transient( 'sbi_github_rate_limit' );
    }

    /**
     * Check if we should avoid API calls due to rate limiting.
     * Returns true if rate limit is nearly exhausted.
     *
     * @since 1.0.33
     * @param int $threshold Minimum remaining requests before returning true.
     * @return bool True if we should avoid API calls.
     */
    public function is_rate_limited( int $threshold = 5 ): bool {
        $rate_limit = $this->get_cached_rate_limit();

        if ( ! $rate_limit ) {
            return false; // No data, assume OK
        }

        // Check if data is stale (older than 2 minutes)
        if ( ( time() - $rate_limit['updated_at'] ) > 120 ) {
            return false;
        }

        return $rate_limit['remaining'] < $threshold;
    }
}

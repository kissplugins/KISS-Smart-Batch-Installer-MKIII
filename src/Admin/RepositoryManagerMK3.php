<?php
/**
 * SBI MK III - Clean Rebuild of Repository Manager
 *
 * Philosophy:
 * - No FSM complexity
 * - No TypeScript
 * - No progressive loading
 * - Single data flow: PHP → HTML → jQuery → AJAX → PHP
 * - Action buttons rendered server-side with state
 *
 * @package SmartBatchInstaller
 * @version 1.0.80
 * @since 1.0.80
 */

namespace SBI\Admin;

use SBI\Services\GitHubService;
use SBI\Services\PluginInstallationService;
use SBI\Enums\PluginState;

class RepositoryManagerMK3 {

    private GitHubService $github_service;
    private PluginInstallationService $plugin_service;

    public function __construct(
        GitHubService $github_service,
        PluginInstallationService $plugin_service
    ) {
        $this->github_service = $github_service;
        $this->plugin_service = $plugin_service;
    }
    
    /**
     * Register admin page
     */
    public function register() {
        \add_action('admin_menu', [$this, 'add_menu_page']);
        \add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // AJAX handlers
        \add_action('wp_ajax_sbi_mk3_get_repositories', [$this, 'ajax_get_repositories']);
        \add_action('wp_ajax_sbi_mk3_install_plugin', [$this, 'ajax_install_plugin']);
        \add_action('wp_ajax_sbi_mk3_activate_plugin', [$this, 'ajax_activate_plugin']);
        \add_action('wp_ajax_sbi_mk3_deactivate_plugin', [$this, 'ajax_deactivate_plugin']);
        \add_action('wp_ajax_sbi_mk3_switch_organization', [$this, 'ajax_switch_organization']);
        \add_action('wp_ajax_sbi_mk3_update_per_page', [$this, 'ajax_update_per_page']);
    }
    
    /**
     * Add menu page
     */
    public function add_menu_page() {
        \add_submenu_page(
            'plugins.php',
            'KISS Smart Batch Installer MKIII',
            'SBI MKIII',
            'install_plugins',
            'sbi-mk3',
            [$this, 'render_page']
        );
    }

    /**
     * Enqueue assets
     */
    public function enqueue_assets($hook) {
        // Hook format: plugins_page_sbi-mk3
        if ($hook !== 'plugins_page_sbi-mk3') {
            return;
        }

        $plugin_url = \plugin_dir_url(GBI_FILE);

        \wp_enqueue_style(
            'sbi-mk3-admin',
            $plugin_url . 'assets/mk3-admin.css',
            [],
            GBI_VERSION
        );

        \wp_enqueue_script(
            'sbi-mk3-admin',
            $plugin_url . 'assets/mk3-admin.js',
            ['jquery'],
            GBI_VERSION,
            true
        );

        \wp_localize_script('sbi-mk3-admin', 'sbiMK3', [
            'ajaxUrl' => \admin_url('admin-ajax.php'),
            'nonce' => \wp_create_nonce('sbi_mk3_nonce')
        ]);
    }
    
    /**
     * Render admin page
     */
    public function render_page() {
        $current_org = \get_option('sbi_github_organization', 'kissplugins');
        $per_page = \get_option('sbi_repos_per_page', 15);
        ?>
        <div class="wrap">
            <h1>KISS Smart Batch Installer MKIII</h1>
            <p class="description">Simple, sequential, single-path architecture. No FSM complexity.</p>

            <div style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px; margin: 20px 0;">
                <div style="margin-bottom: 10px;">
                    <label for="sbi-github-org" style="font-weight: 600; margin-right: 10px;">
                        GitHub Organization:
                    </label>
                    <input
                        type="text"
                        id="sbi-github-org"
                        value="<?php echo \esc_attr($current_org); ?>"
                        class="regular-text"
                        placeholder="e.g., kissplugins"
                    />
                    <button type="button" id="sbi-switch-org" class="button button-primary" style="margin-left: 10px;">
                        Switch Organization
                    </button>
                    <span id="sbi-org-status" style="margin-left: 10px; color: #46b450;"></span>
                </div>

                <div>
                    <label for="sbi-per-page" style="font-weight: 600; margin-right: 10px;">
                        Repositories per page:
                    </label>
                    <input
                        type="number"
                        id="sbi-per-page"
                        value="<?php echo \esc_attr($per_page); ?>"
                        min="5"
                        max="100"
                        step="5"
                        style="width: 80px;"
                    />
                    <button type="button" id="sbi-update-per-page" class="button" style="margin-left: 10px;">
                        Update
                    </button>
                    <span id="sbi-per-page-status" style="margin-left: 10px; color: #46b450;"></span>
                </div>
            </div>

            <div id="sbi-mk3-loading">
                <p>Loading repositories...</p>
            </div>

            <div id="sbi-mk3-error" style="display: none; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin: 20px 0;">
                <strong>Error:</strong> <span id="sbi-mk3-error-message"></span>
            </div>

            <div id="sbi-mk3-pagination-top" style="display: none; margin: 15px 0; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div id="sbi-mk3-showing-info"></div>
                    <div id="sbi-mk3-pagination-controls"></div>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped" id="sbi-mk3-table" style="display: none;">
                <thead>
                    <tr>
                        <th>Repository</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="sbi-mk3-tbody">
                    <!-- Populated via AJAX -->
                </tbody>
            </table>

            <div id="sbi-mk3-pagination-bottom" style="display: none; margin: 15px 0; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                <div style="display: flex; justify-content: center;">
                    <div id="sbi-mk3-pagination-controls-bottom"></div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX: Get repositories with current state
     */
    public function ajax_get_repositories() {
        error_log('[SBI MK3] AJAX: ajax_get_repositories called');

        \check_ajax_referer('sbi_mk3_nonce', 'nonce');

        try {
            error_log('[SBI MK3] Fetching repositories from GitHub...');

            // Get repositories from GitHub
            $repositories = $this->github_service->fetch_repositories();

            error_log('[SBI MK3] Fetch result type: ' . gettype($repositories));

            // Check for WP_Error
            if (\is_wp_error($repositories)) {
                $error_msg = $repositories->get_error_message();
                error_log('[SBI MK3] WP_Error: ' . $error_msg);
                \wp_send_json_error(['message' => $error_msg]);
                return;
            }

            error_log('[SBI MK3] Repository count: ' . count($repositories));

            // Get current plugin states
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $installed_plugins = \get_plugins();
            $active_plugins = \get_option('active_plugins', []);

            error_log('[SBI MK3] Installed plugins: ' . count($installed_plugins));
            error_log('[SBI MK3] Active plugins: ' . count($active_plugins));

            $rows = [];
            foreach ($repositories as $repo) {
                $full_name = $repo['full_name'];
                $state = $this->get_repository_state($repo, $installed_plugins, $active_plugins);

                $rows[] = [
                    'full_name' => $full_name,
                    'name' => $repo['name'],
                    'description' => $repo['description'] ?? '',
                    'state' => $state->value,
                    'html' => $this->render_row($repo, $state)
                ];
            }

            error_log('[SBI MK3] Sending success response with ' . count($rows) . ' rows');
            \wp_send_json_success(['rows' => $rows]);

        } catch (\Exception $e) {
            error_log('[SBI MK3] Exception: ' . $e->getMessage());
            error_log('[SBI MK3] Stack trace: ' . $e->getTraceAsString());
            \wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Get repository state (simple, no FSM)
     */
    private function get_repository_state($repo, $installed_plugins, $active_plugins): PluginState {
        $full_name = $repo['full_name'];

        // Find plugin file
        $plugin_file = null;
        foreach ($installed_plugins as $file => $data) {
            if (strpos($file, $repo['name']) !== false) {
                $plugin_file = $file;
                break;
            }
        }

        if (!$plugin_file) {
            return PluginState::AVAILABLE;
        }

        if (in_array($plugin_file, $active_plugins)) {
            return PluginState::INSTALLED_ACTIVE;
        }

        return PluginState::INSTALLED_INACTIVE;
    }

    /**
     * Render table row HTML
     */
    private function render_row($repo, PluginState $state): string {
        $full_name = esc_attr($repo['full_name']);
        $name = esc_html($repo['name']);
        $description = esc_html($repo['description'] ?? '');

        ob_start();
        ?>
        <tr data-repo="<?php echo $full_name; ?>" data-state="<?php echo $state->value; ?>">
            <td><strong><?php echo $name; ?></strong></td>
            <td><?php echo $description; ?></td>
            <td><?php echo $this->render_status_badge($state); ?></td>
            <td><?php echo $this->render_action_buttons($full_name, $state); ?></td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Render status badge
     */
    private function render_status_badge(PluginState $state): string {
        $badges = [
            'available' => '<span class="sbi-badge sbi-badge-gray">Available</span>',
            'installed_inactive' => '<span class="sbi-badge sbi-badge-yellow">Installed</span>',
            'installed_active' => '<span class="sbi-badge sbi-badge-green">Active</span>',
            'installing' => '<span class="sbi-badge sbi-badge-blue">Installing...</span>',
            'error' => '<span class="sbi-badge sbi-badge-red">Error</span>',
        ];

        return $badges[$state->value] ?? '<span class="sbi-badge sbi-badge-gray">Unknown</span>';
    }

    /**
     * Render action buttons (SERVER-SIDE, simple and clear)
     */
    private function render_action_buttons(string $full_name, PluginState $state): string {
        switch ($state) {
            case PluginState::AVAILABLE:
                return sprintf(
                    '<button class="button button-primary sbi-mk3-install" data-repo="%s">Install</button>',
                    esc_attr($full_name)
                );

            case PluginState::INSTALLED_INACTIVE:
                return sprintf(
                    '<button class="button button-primary sbi-mk3-activate" data-repo="%s">Activate</button>',
                    esc_attr($full_name)
                );

            case PluginState::INSTALLED_ACTIVE:
                return sprintf(
                    '<button class="button sbi-mk3-deactivate" data-repo="%s">Deactivate</button>',
                    esc_attr($full_name)
                );

            case PluginState::INSTALLING:
                return '<button class="button" disabled>Installing...</button>';

            default:
                return '<span style="color: #999;">—</span>';
        }
    }

    /**
     * AJAX: Install plugin
     */
    public function ajax_install_plugin() {
        error_log('[SBI MK3] AJAX: ajax_install_plugin called');

        \check_ajax_referer('sbi_mk3_nonce', 'nonce');

        $full_name = \sanitize_text_field($_POST['repo'] ?? '');
        error_log('[SBI MK3] Installing repo: ' . $full_name);

        if (empty($full_name)) {
            error_log('[SBI MK3] Error: Repository name required');
            \wp_send_json_error(['message' => 'Repository name required']);
            return;
        }

        // Split owner/repo
        $parts = explode('/', $full_name);
        if (count($parts) !== 2) {
            error_log('[SBI MK3] Error: Invalid repository format: ' . $full_name);
            \wp_send_json_error(['message' => 'Invalid repository format. Expected: owner/repo']);
            return;
        }

        list($owner, $repo) = $parts;
        error_log('[SBI MK3] Owner: ' . $owner . ', Repo: ' . $repo);

        try {
            $result = $this->plugin_service->install_plugin($owner, $repo);

            // Check for WP_Error
            if (\is_wp_error($result)) {
                error_log('[SBI MK3] Install WP_Error: ' . $result->get_error_message());
                \wp_send_json_error(['message' => $result->get_error_message()]);
                return;
            }

            error_log('[SBI MK3] Install success: ' . print_r($result, true));
            \wp_send_json_success($result);
        } catch (\Exception $e) {
            error_log('[SBI MK3] Install error: ' . $e->getMessage());
            \wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Activate plugin
     */
    public function ajax_activate_plugin() {
        error_log('[SBI MK3] AJAX: ajax_activate_plugin called');

        \check_ajax_referer('sbi_mk3_nonce', 'nonce');

        $full_name = \sanitize_text_field($_POST['repo'] ?? '');
        error_log('[SBI MK3] Activating repo: ' . $full_name);

        if (empty($full_name)) {
            error_log('[SBI MK3] Error: Repository name required');
            \wp_send_json_error(['message' => 'Repository name required']);
            return;
        }

        // Find plugin file
        $plugin_file = $this->find_plugin_file($full_name);
        if (!$plugin_file) {
            error_log('[SBI MK3] Error: Plugin not found for: ' . $full_name);
            \wp_send_json_error(['message' => 'Plugin not found. Please install it first.']);
            return;
        }

        error_log('[SBI MK3] Found plugin file: ' . $plugin_file);

        try {
            $result = $this->plugin_service->activate_plugin($plugin_file);

            // Check for WP_Error
            if (\is_wp_error($result)) {
                error_log('[SBI MK3] Activate WP_Error: ' . $result->get_error_message());
                \wp_send_json_error(['message' => $result->get_error_message()]);
                return;
            }

            error_log('[SBI MK3] Activate success: ' . print_r($result, true));
            \wp_send_json_success($result);
        } catch (\Exception $e) {
            error_log('[SBI MK3] Activate error: ' . $e->getMessage());
            \wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Deactivate plugin
     */
    public function ajax_deactivate_plugin() {
        error_log('[SBI MK3] AJAX: ajax_deactivate_plugin called');

        \check_ajax_referer('sbi_mk3_nonce', 'nonce');

        $full_name = \sanitize_text_field($_POST['repo'] ?? '');
        error_log('[SBI MK3] Deactivating repo: ' . $full_name);

        if (empty($full_name)) {
            error_log('[SBI MK3] Error: Repository name required');
            \wp_send_json_error(['message' => 'Repository name required']);
            return;
        }

        // Find plugin file
        $plugin_file = $this->find_plugin_file($full_name);
        if (!$plugin_file) {
            error_log('[SBI MK3] Error: Plugin not found for: ' . $full_name);
            \wp_send_json_error(['message' => 'Plugin not found.']);
            return;
        }

        error_log('[SBI MK3] Found plugin file: ' . $plugin_file);

        try {
            $result = $this->plugin_service->deactivate_plugin($plugin_file);

            // Check for WP_Error
            if (\is_wp_error($result)) {
                error_log('[SBI MK3] Deactivate WP_Error: ' . $result->get_error_message());
                \wp_send_json_error(['message' => $result->get_error_message()]);
                return;
            }

            error_log('[SBI MK3] Deactivate success: ' . print_r($result, true));
            \wp_send_json_success($result);
        } catch (\Exception $e) {
            error_log('[SBI MK3] Deactivate error: ' . $e->getMessage());
            \wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Switch GitHub organization
     */
    public function ajax_switch_organization() {
        \check_ajax_referer('sbi_mk3_nonce', 'nonce');

        $organization = isset($_POST['organization']) ? \sanitize_text_field($_POST['organization']) : '';

        if (empty($organization)) {
            \wp_send_json_error(['message' => 'Organization name cannot be empty']);
            return;
        }

        // Update the option
        \update_option('sbi_github_organization', $organization);

        error_log('[SBI MK3] Switched organization to: ' . $organization);

        \wp_send_json_success([
            'message' => 'Organization switched to: ' . $organization,
            'organization' => $organization
        ]);
    }

    /**
     * AJAX: Update repositories per page
     */
    public function ajax_update_per_page() {
        \check_ajax_referer('sbi_mk3_nonce', 'nonce');

        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 15;

        // Validate range
        if ($per_page < 5) {
            $per_page = 5;
        } elseif ($per_page > 100) {
            $per_page = 100;
        }

        // Update the option
        \update_option('sbi_repos_per_page', $per_page);

        error_log('[SBI MK3] Updated per page to: ' . $per_page);

        \wp_send_json_success([
            'message' => 'Updated to ' . $per_page . ' repositories per page',
            'per_page' => $per_page
        ]);
    }

    /**
     * Find plugin file by repository full name
     */
    private function find_plugin_file(string $full_name): ?string {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $installed_plugins = \get_plugins();

        // Extract repo name from full_name (owner/repo)
        $parts = explode('/', $full_name);
        $repo_name = end($parts);

        error_log('[SBI MK3] Looking for plugin file matching: ' . $repo_name);

        foreach ($installed_plugins as $file => $data) {
            error_log('[SBI MK3] Checking plugin file: ' . $file);
            if (strpos($file, $repo_name) !== false) {
                error_log('[SBI MK3] Match found: ' . $file);
                return $file;
            }
        }

        error_log('[SBI MK3] No match found for: ' . $repo_name);
        return null;
    }
}


<?php
/**
 * Plugin Name: Amazon Price Tracker
 * Plugin URI: https://github.com/ramodesigns/amazon-price-tracker
 * Description: A WordPress REST API plugin for tracking Amazon product prices across multiple international marketplaces.
 * Version: 1.0.0
 * Author: Ramo Designs
 * Author URI: https://github.com/ramodesigns
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: amazon-price-tracker
 * Domain Path: /languages
 * Requires at least: 5.9
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('APT_VERSION', '1.0.0');
define('APT_PLUGIN_FILE', __FILE__);
define('APT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('APT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('APT_API_NAMESPACE', 'amazon-price-tracker/v1');

// Daily creation limit for non-admin users (default value)
define('APT_DAILY_CREATION_LIMIT', 50);

/**
 * Get the configured daily creation limit for non-admin users
 *
 * @return int Daily limit (configurable via apt_daily_creation_limit option)
 */
function apt_get_daily_limit(): int {
    return (int) get_option('apt_daily_creation_limit', APT_DAILY_CREATION_LIMIT);
}

// Autoloader for plugin classes
spl_autoload_register(function ($class) {
    $prefix = 'APT\\';
    $base_dir = APT_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Load a local .env file (if present) into the process environment, before
// anything reads PA-API credentials. Absent in every real deployment - see
// APT\Helpers\Env_File for what this enables and why it's safe.
\APT\Helpers\Env_File::load();

/**
 * Main plugin class
 */
final class Amazon_Price_Tracker {

    /**
     * Plugin instance
     */
    private static $instance = null;

    /**
     * Get plugin instance
     */
    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        // Activation/Deactivation hooks
        register_activation_hook(APT_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(APT_PLUGIN_FILE, [$this, 'deactivate']);

        // Initialize plugin after WordPress is loaded
        add_action('plugins_loaded', [$this, 'init']);

        // Register REST API routes
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Initialize scheduled refresh
        add_action('init', [$this, 'init_scheduled_refresh']);

        // Initialize price history maintenance
        add_action('init', [$this, 'init_history_maintenance']);

        // Add admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Initialize dashboard widget
        APT\Admin\Dashboard_Widget::init();
    }

    /**
     * Plugin activation
     */
    public function activate(): void {
        // Create database tables
        require_once APT_PLUGIN_DIR . 'includes/Database/Installer.php';
        $installer = new APT\Database\Installer();
        $installer->install();

        // Set default options
        add_option('apt_version', APT_VERSION);
        add_option('apt_installed_at', current_time('mysql', true));
        add_option('apt_refresh_batch_size', 50);

        // Schedule price refresh (twice daily by default)
        APT\Services\Scheduled_Refresh::schedule('twicedaily');

        // Schedule price history maintenance (weekly)
        APT\Services\Price_History_Maintenance::schedule();

        // Clear any cached data
        wp_cache_flush();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate(): void {
        // Clear scheduled events
        APT\Services\Scheduled_Refresh::unschedule();
        APT\Services\Price_History_Maintenance::unschedule();

        // Clear transients
        delete_transient('apt_stats_cache');
        delete_transient('apt_dashboard_widget_data');
    }

    /**
     * Initialize scheduled refresh service
     */
    public function init_scheduled_refresh(): void {
        APT\Services\Scheduled_Refresh::init();
    }

    /**
     * Initialize price history maintenance service
     */
    public function init_history_maintenance(): void {
        APT\Services\Price_History_Maintenance::init();
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu(): void {
        add_options_page(
            __('Amazon Price Tracker', 'amazon-price-tracker'),
            __('Amazon Price Tracker', 'amazon-price-tracker'),
            'manage_options',
            'amazon-price-tracker',
            [$this, 'render_admin_page']
        );
    }

    /**
     * Render admin settings page
     */
    public function render_admin_page(): void {
        // Handle form submission
        if (isset($_POST['apt_save_settings']) && check_admin_referer('apt_settings_nonce')) {
            $schedule = sanitize_text_field($_POST['apt_schedule'] ?? 'twicedaily');
            $batch_size = absint($_POST['apt_batch_size'] ?? 50);
            $daily_limit = absint($_POST['apt_daily_limit'] ?? APT_DAILY_CREATION_LIMIT);

            // Retention settings
            $full_retention = absint($_POST['apt_full_retention'] ?? 30);
            $daily_retention = absint($_POST['apt_daily_retention'] ?? 90);
            $weekly_retention = absint($_POST['apt_weekly_retention'] ?? 365);

            update_option('apt_refresh_batch_size', min(max($batch_size, 10), 500));
            update_option('apt_daily_creation_limit', min(max($daily_limit, 1), 1000));

            // Save retention settings with sensible limits
            update_option('apt_history_full_retention', min(max($full_retention, 7), 90));
            update_option('apt_history_daily_retention', min(max($daily_retention, 30), 180));
            update_option('apt_history_weekly_retention', min(max($weekly_retention, 90), 730));

            if ($schedule === 'disabled') {
                APT\Services\Scheduled_Refresh::unschedule();
            } else {
                APT\Services\Scheduled_Refresh::schedule($schedule);
            }

            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'amazon-price-tracker') . '</p></div>';
        }

        // Trigger manual refresh
        if (isset($_POST['apt_manual_refresh']) && check_admin_referer('apt_settings_nonce')) {
            APT\Services\Scheduled_Refresh::run_scheduled_refresh();
            echo '<div class="notice notice-success"><p>' . esc_html__('Manual refresh completed.', 'amazon-price-tracker') . '</p></div>';
        }

        // Trigger manual history maintenance
        if (isset($_POST['apt_run_maintenance']) && check_admin_referer('apt_settings_nonce')) {
            $maintenance = new APT\Services\Price_History_Maintenance();
            $result = $maintenance->run_manual();
            echo '<div class="notice notice-success"><p>' . sprintf(
                esc_html__('History maintenance completed: %d records pruned from %d products. %d milestone records preserved.', 'amazon-price-tracker'),
                $result['total_pruned'],
                $result['products_processed'],
                $result['milestones_preserved']
            ) . '</p></div>';
        }

        $status = APT\Services\Scheduled_Refresh::get_status();
        $batch_size = get_option('apt_refresh_batch_size', 50);
        $daily_limit = apt_get_daily_limit();
        $current_schedule = $status['schedule'] ?? 'twicedaily';

        // History maintenance settings
        $full_retention = (int) get_option('apt_history_full_retention', 30);
        $daily_retention = (int) get_option('apt_history_daily_retention', 90);
        $weekly_retention = (int) get_option('apt_history_weekly_retention', 365);
        $maintenance_status = APT\Services\Price_History_Maintenance::get_last_run();
        $maintenance_next = APT\Services\Price_History_Maintenance::get_next_run();

        // Get storage stats
        $maintenance_service = new APT\Services\Price_History_Maintenance();
        $storage_stats = $maintenance_service->get_storage_stats();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Amazon Price Tracker Settings', 'amazon-price-tracker'); ?></h1>

            <h2><?php esc_html_e('API Information', 'amazon-price-tracker'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Base URL', 'amazon-price-tracker'); ?></th>
                    <td><code><?php echo esc_html(rest_url(APT_API_NAMESPACE)); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Authentication', 'amazon-price-tracker'); ?></th>
                    <td><?php esc_html_e('WordPress Application Passwords (HTTP Basic Auth)', 'amazon-price-tracker'); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('API Endpoints', 'amazon-price-tracker'); ?></th>
                    <td>
                        <button type="button" class="button apt-accordion-toggle" id="apt-endpoints-toggle">
                            <?php esc_html_e('Show All Endpoints', 'amazon-price-tracker'); ?> ▼
                        </button>
                    </td>
                </tr>
            </table>

            <div id="apt-endpoints-accordion" class="apt-accordion-content" style="display: none; margin-top: 15px;">
                <?php $base = rest_url(APT_API_NAMESPACE); ?>

                <!-- Health Endpoints -->
                <div class="apt-endpoint-group">
                    <h4 class="apt-endpoint-group-title"><?php esc_html_e('Health & Status', 'amazon-price-tracker'); ?></h4>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th style="width: 70px;"><?php esc_html_e('Method', 'amazon-price-tracker'); ?></th>
                                <th style="width: 28%;"><?php esc_html_e('Endpoint', 'amazon-price-tracker'); ?></th>
                                <th><?php esc_html_e('Description', 'amazon-price-tracker'); ?></th>
                                <th style="width: 80px;"><?php esc_html_e('Auth', 'amazon-price-tracker'); ?></th>
                                <th style="width: 120px;"><?php esc_html_e('Examples', 'amazon-price-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="apt-method apt-method-get">GET</span></td>
                                <td><code>/health</code></td>
                                <td><?php esc_html_e('API health check - returns version and status info', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-public"><?php esc_html_e('Public', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="health">Response</button></td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-get">GET</span></td>
                                <td><code>/health/amazon</code></td>
                                <td><?php esc_html_e('Test Amazon PA-API connectivity with your credentials', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-user"><?php esc_html_e('User', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="health-amazon">Response</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Stats Endpoints -->
                <div class="apt-endpoint-group">
                    <h4 class="apt-endpoint-group-title"><?php esc_html_e('Statistics', 'amazon-price-tracker'); ?></h4>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th style="width: 70px;"><?php esc_html_e('Method', 'amazon-price-tracker'); ?></th>
                                <th style="width: 28%;"><?php esc_html_e('Endpoint', 'amazon-price-tracker'); ?></th>
                                <th><?php esc_html_e('Description', 'amazon-price-tracker'); ?></th>
                                <th style="width: 80px;"><?php esc_html_e('Auth', 'amazon-price-tracker'); ?></th>
                                <th style="width: 120px;"><?php esc_html_e('Examples', 'amazon-price-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="apt-method apt-method-get">GET</span></td>
                                <td><code>/stats</code></td>
                                <td><?php esc_html_e('Overall API statistics - product counts, regions, categories', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-user"><?php esc_html_e('User', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="stats">Response</button></td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-get">GET</span></td>
                                <td><code>/stats/user</code></td>
                                <td><?php esc_html_e('Current user statistics - daily limits, configured regions', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-user"><?php esc_html_e('User', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="stats-user">Response</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Products Endpoints -->
                <div class="apt-endpoint-group">
                    <h4 class="apt-endpoint-group-title"><?php esc_html_e('Products', 'amazon-price-tracker'); ?></h4>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th style="width: 70px;"><?php esc_html_e('Method', 'amazon-price-tracker'); ?></th>
                                <th style="width: 28%;"><?php esc_html_e('Endpoint', 'amazon-price-tracker'); ?></th>
                                <th><?php esc_html_e('Description', 'amazon-price-tracker'); ?></th>
                                <th style="width: 80px;"><?php esc_html_e('Auth', 'amazon-price-tracker'); ?></th>
                                <th style="width: 120px;"><?php esc_html_e('Examples', 'amazon-price-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="apt-method apt-method-get">GET</span></td>
                                <td><code>/products</code></td>
                                <td><?php esc_html_e('List products with filtering, sorting, and pagination', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-user"><?php esc_html_e('User', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="products-list">Response</button></td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-post">POST</span></td>
                                <td><code>/products</code></td>
                                <td><?php esc_html_e('Add a new product to track (requires ASIN and region)', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-user"><?php esc_html_e('User', 'amazon-price-tracker'); ?></span></td>
                                <td>
                                    <button type="button" class="button button-small apt-example-btn" data-endpoint="products-create" data-type="request">Request</button>
                                    <button type="button" class="button button-small apt-example-btn" data-endpoint="products-create" data-type="response">Response</button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-post">POST</span></td>
                                <td><code>/products/bulk</code></td>
                                <td><?php esc_html_e('Bulk create multiple products at once (max 50)', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-user"><?php esc_html_e('User', 'amazon-price-tracker'); ?></span></td>
                                <td>
                                    <button type="button" class="button button-small apt-example-btn" data-endpoint="products-bulk" data-type="request">Request</button>
                                    <button type="button" class="button button-small apt-example-btn" data-endpoint="products-bulk" data-type="response">Response</button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-post">POST</span></td>
                                <td><code>/products/refresh</code></td>
                                <td><?php esc_html_e('Bulk refresh prices for multiple products', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-admin"><?php esc_html_e('Admin', 'amazon-price-tracker'); ?></span></td>
                                <td>
                                    <button type="button" class="button button-small apt-example-btn" data-endpoint="products-refresh" data-type="request">Request</button>
                                    <button type="button" class="button button-small apt-example-btn" data-endpoint="products-refresh" data-type="response">Response</button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-get">GET</span></td>
                                <td><code>/products/{id}</code></td>
                                <td><?php esc_html_e('Get details for a single product by ID', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-user"><?php esc_html_e('User', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="products-single">Response</button></td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-delete">DELETE</span></td>
                                <td><code>/products/{id}</code></td>
                                <td><?php esc_html_e('Soft-delete a product (use ?force=true for hard delete)', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-admin"><?php esc_html_e('Admin', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="products-delete">Response</button></td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-put">PUT</span></td>
                                <td><code>/products/{id}/category</code></td>
                                <td><?php esc_html_e('Update product custom category', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-admin"><?php esc_html_e('Admin', 'amazon-price-tracker'); ?></span></td>
                                <td>
                                    <button type="button" class="button button-small apt-example-btn" data-endpoint="products-category" data-type="request">Request</button>
                                    <button type="button" class="button button-small apt-example-btn" data-endpoint="products-category" data-type="response">Response</button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-get">GET</span></td>
                                <td><code>/products/{id}/prices</code></td>
                                <td><?php esc_html_e('Get price history for a product with optional date range', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-user"><?php esc_html_e('User', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="products-prices">Response</button></td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-post">POST</span></td>
                                <td><code>/products/{id}/refresh</code></td>
                                <td><?php esc_html_e('Refresh price for a single product from Amazon', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-admin"><?php esc_html_e('Admin', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="products-refresh-single">Response</button></td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-get">GET</span></td>
                                <td><code>/products/by-asin/{asin}</code></td>
                                <td><?php esc_html_e('Find all products by ASIN (across all regions)', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-user"><?php esc_html_e('User', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="products-by-asin">Response</button></td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-get">GET</span></td>
                                <td><code>/products/by-asin/{asin}/{region}</code></td>
                                <td><?php esc_html_e('Find product by ASIN and specific region', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-user"><?php esc_html_e('User', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="products-by-asin-region">Response</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Settings Endpoints -->
                <div class="apt-endpoint-group">
                    <h4 class="apt-endpoint-group-title"><?php esc_html_e('User Settings', 'amazon-price-tracker'); ?></h4>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th style="width: 70px;"><?php esc_html_e('Method', 'amazon-price-tracker'); ?></th>
                                <th style="width: 28%;"><?php esc_html_e('Endpoint', 'amazon-price-tracker'); ?></th>
                                <th><?php esc_html_e('Description', 'amazon-price-tracker'); ?></th>
                                <th style="width: 80px;"><?php esc_html_e('Auth', 'amazon-price-tracker'); ?></th>
                                <th style="width: 120px;"><?php esc_html_e('Examples', 'amazon-price-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="apt-method apt-method-get">GET</span></td>
                                <td><code>/settings</code></td>
                                <td><?php esc_html_e('Get your Amazon PA-API settings and partner tags', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-user"><?php esc_html_e('User', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="settings-get">Response</button></td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-put">PUT</span></td>
                                <td><code>/settings</code></td>
                                <td><?php esc_html_e('Update Amazon PA-API credentials and partner tags', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-user"><?php esc_html_e('User', 'amazon-price-tracker'); ?></span></td>
                                <td>
                                    <button type="button" class="button button-small apt-example-btn" data-endpoint="settings-update" data-type="request">Request</button>
                                    <button type="button" class="button button-small apt-example-btn" data-endpoint="settings-update" data-type="response">Response</button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-delete">DELETE</span></td>
                                <td><code>/settings/partner-tags/{region}</code></td>
                                <td><?php esc_html_e('Remove partner tag for a specific region', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-user"><?php esc_html_e('User', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="settings-delete-tag">Response</button></td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-post">POST</span></td>
                                <td><code>/settings/validate</code></td>
                                <td><?php esc_html_e('Validate your Amazon PA-API credentials', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-user"><?php esc_html_e('User', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="settings-validate">Response</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Reference Data Endpoints -->
                <div class="apt-endpoint-group">
                    <h4 class="apt-endpoint-group-title"><?php esc_html_e('Reference Data', 'amazon-price-tracker'); ?></h4>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th style="width: 70px;"><?php esc_html_e('Method', 'amazon-price-tracker'); ?></th>
                                <th style="width: 28%;"><?php esc_html_e('Endpoint', 'amazon-price-tracker'); ?></th>
                                <th><?php esc_html_e('Description', 'amazon-price-tracker'); ?></th>
                                <th style="width: 80px;"><?php esc_html_e('Auth', 'amazon-price-tracker'); ?></th>
                                <th style="width: 120px;"><?php esc_html_e('Examples', 'amazon-price-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="apt-method apt-method-get">GET</span></td>
                                <td><code>/regions</code></td>
                                <td><?php esc_html_e('List all supported Amazon marketplace regions', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-user"><?php esc_html_e('User', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="regions">Response</button></td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-get">GET</span></td>
                                <td><code>/categories</code></td>
                                <td><?php esc_html_e('List all custom categories in use with product counts', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-admin"><?php esc_html_e('Admin', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="categories">Response</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Blacklist Endpoints -->
                <div class="apt-endpoint-group">
                    <h4 class="apt-endpoint-group-title"><?php esc_html_e('Blacklist Management', 'amazon-price-tracker'); ?></h4>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th style="width: 70px;"><?php esc_html_e('Method', 'amazon-price-tracker'); ?></th>
                                <th style="width: 28%;"><?php esc_html_e('Endpoint', 'amazon-price-tracker'); ?></th>
                                <th><?php esc_html_e('Description', 'amazon-price-tracker'); ?></th>
                                <th style="width: 80px;"><?php esc_html_e('Auth', 'amazon-price-tracker'); ?></th>
                                <th style="width: 120px;"><?php esc_html_e('Examples', 'amazon-price-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="apt-method apt-method-get">GET</span></td>
                                <td><code>/blacklist</code></td>
                                <td><?php esc_html_e('List blacklisted ASINs with pagination and filtering', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-admin"><?php esc_html_e('Admin', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="blacklist-list">Response</button></td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-post">POST</span></td>
                                <td><code>/blacklist</code></td>
                                <td><?php esc_html_e('Add an ASIN to the blacklist (prevents tracking)', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-admin"><?php esc_html_e('Admin', 'amazon-price-tracker'); ?></span></td>
                                <td>
                                    <button type="button" class="button button-small apt-example-btn" data-endpoint="blacklist-create" data-type="request">Request</button>
                                    <button type="button" class="button button-small apt-example-btn" data-endpoint="blacklist-create" data-type="response">Response</button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-get">GET</span></td>
                                <td><code>/blacklist/check</code></td>
                                <td><?php esc_html_e('Check if a specific ASIN/region is blacklisted', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-admin"><?php esc_html_e('Admin', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="blacklist-check">Response</button></td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-get">GET</span></td>
                                <td><code>/blacklist/{id}</code></td>
                                <td><?php esc_html_e('Get details of a blacklist entry', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-admin"><?php esc_html_e('Admin', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="blacklist-single">Response</button></td>
                            </tr>
                            <tr>
                                <td><span class="apt-method apt-method-delete">DELETE</span></td>
                                <td><code>/blacklist/{id}</code></td>
                                <td><?php esc_html_e('Remove an entry from the blacklist', 'amazon-price-tracker'); ?></td>
                                <td><span class="apt-auth-admin"><?php esc_html_e('Admin', 'amazon-price-tracker'); ?></span></td>
                                <td><button type="button" class="button button-small apt-example-btn" data-endpoint="blacklist-delete">Response</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="description" style="margin-top: 15px;">
                    <strong><?php esc_html_e('Authentication Levels:', 'amazon-price-tracker'); ?></strong><br>
                    <span class="apt-auth-public"><?php esc_html_e('Public', 'amazon-price-tracker'); ?></span> - <?php esc_html_e('No authentication required', 'amazon-price-tracker'); ?><br>
                    <span class="apt-auth-user"><?php esc_html_e('User', 'amazon-price-tracker'); ?></span> - <?php esc_html_e('Requires WordPress Application Password', 'amazon-price-tracker'); ?><br>
                    <span class="apt-auth-admin"><?php esc_html_e('Admin', 'amazon-price-tracker'); ?></span> - <?php esc_html_e('Requires admin-level Application Password', 'amazon-price-tracker'); ?>
                </p>
            </div>

            <!-- Example Modal -->
            <div id="apt-example-modal" class="apt-modal" style="display: none;">
                <div class="apt-modal-content">
                    <div class="apt-modal-header">
                        <h3 id="apt-modal-title"><?php esc_html_e('Example', 'amazon-price-tracker'); ?></h3>
                        <button type="button" class="apt-modal-close">&times;</button>
                    </div>
                    <div class="apt-modal-body">
                        <pre id="apt-modal-code"><code></code></pre>
                    </div>
                    <div class="apt-modal-footer">
                        <button type="button" class="button apt-copy-btn"><?php esc_html_e('Copy to Clipboard', 'amazon-price-tracker'); ?></button>
                        <button type="button" class="button apt-modal-close-btn"><?php esc_html_e('Close', 'amazon-price-tracker'); ?></button>
                    </div>
                </div>
            </div>

            <style>
                .apt-endpoint-group { margin-bottom: 20px; }
                .apt-endpoint-group-title {
                    margin: 15px 0 10px 0;
                    padding: 8px 12px;
                    background: #f0f0f1;
                    border-left: 4px solid #2271b1;
                    font-size: 14px;
                }
                .apt-endpoint-group table code {
                    background: #f6f7f7;
                    padding: 2px 6px;
                    font-size: 12px;
                }
                .apt-method {
                    display: inline-block;
                    padding: 2px 8px;
                    border-radius: 3px;
                    font-size: 11px;
                    font-weight: 600;
                    text-transform: uppercase;
                }
                .apt-method-get { background: #d1e7dd; color: #0f5132; }
                .apt-method-post { background: #cff4fc; color: #055160; }
                .apt-method-put { background: #fff3cd; color: #664d03; }
                .apt-method-delete { background: #f8d7da; color: #842029; }
                .apt-auth-public, .apt-auth-user, .apt-auth-admin {
                    display: inline-block;
                    padding: 2px 8px;
                    border-radius: 3px;
                    font-size: 11px;
                    font-weight: 500;
                }
                .apt-auth-public { background: #d1e7dd; color: #0f5132; }
                .apt-auth-user { background: #cff4fc; color: #055160; }
                .apt-auth-admin { background: #fff3cd; color: #664d03; }
                .apt-accordion-toggle { cursor: pointer; }
                .apt-example-btn { margin: 1px !important; font-size: 11px !important; }

                /* Modal Styles */
                .apt-modal {
                    position: fixed;
                    z-index: 100000;
                    left: 0;
                    top: 0;
                    width: 100%;
                    height: 100%;
                    background-color: rgba(0,0,0,0.6);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                .apt-modal-content {
                    background: #fff;
                    border-radius: 8px;
                    width: 90%;
                    max-width: 700px;
                    max-height: 80vh;
                    display: flex;
                    flex-direction: column;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                }
                .apt-modal-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 15px 20px;
                    border-bottom: 1px solid #ddd;
                    background: #f6f7f7;
                    border-radius: 8px 8px 0 0;
                }
                .apt-modal-header h3 {
                    margin: 0;
                    font-size: 16px;
                }
                .apt-modal-close {
                    background: none;
                    border: none;
                    font-size: 24px;
                    cursor: pointer;
                    color: #666;
                    padding: 0;
                    line-height: 1;
                }
                .apt-modal-close:hover { color: #d63638; }
                .apt-modal-body {
                    padding: 20px;
                    overflow-y: auto;
                    flex: 1;
                }
                .apt-modal-body pre {
                    margin: 0;
                    background: #23282d;
                    color: #eee;
                    padding: 15px;
                    border-radius: 4px;
                    overflow-x: auto;
                    font-size: 13px;
                    line-height: 1.5;
                }
                .apt-modal-body pre code {
                    background: none;
                    padding: 0;
                    color: inherit;
                }
                .apt-modal-footer {
                    padding: 15px 20px;
                    border-top: 1px solid #ddd;
                    text-align: right;
                    background: #f6f7f7;
                    border-radius: 0 0 8px 8px;
                }
                .apt-modal-footer .button { margin-left: 10px; }
                .apt-copy-btn.copied { background: #00a32a; color: #fff; border-color: #00a32a; }
            </style>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Accordion toggle
                var toggle = document.getElementById('apt-endpoints-toggle');
                var content = document.getElementById('apt-endpoints-accordion');

                if (toggle && content) {
                    toggle.addEventListener('click', function() {
                        if (content.style.display === 'none') {
                            content.style.display = 'block';
                            toggle.innerHTML = '<?php echo esc_js(__('Hide Endpoints', 'amazon-price-tracker')); ?> ▲';
                        } else {
                            content.style.display = 'none';
                            toggle.innerHTML = '<?php echo esc_js(__('Show All Endpoints', 'amazon-price-tracker')); ?> ▼';
                        }
                    });
                }

                // Example data
                var examples = {
                    'health': {
                        response: {
                            "success": true,
                            "data": {
                                "status": "healthy",
                                "version": "1.0.0",
                                "wordpress_version": "6.4.2",
                                "php_version": "8.2.13",
                                "timestamp": "2024-12-06T10:30:00+00:00"
                            }
                        }
                    },
                    'health-amazon': {
                        response: {
                            "success": true,
                            "data": {
                                "status": "connected",
                                "message": "Successfully connected to Amazon PA-API",
                                "region_tested": "US",
                                "response_time_ms": 245
                            }
                        }
                    },
                    'stats': {
                        response: {
                            "success": true,
                            "data": {
                                "total_products": 156,
                                "active_products": 142,
                                "total_price_records": 8934,
                                "products_by_region": [
                                    {"region": "US", "count": 89},
                                    {"region": "UK", "count": 34},
                                    {"region": "DE", "count": 19}
                                ],
                                "categories_count": 12,
                                "user_stats": {
                                    "products_created_today": 5,
                                    "daily_limit": 50,
                                    "remaining_today": 45
                                }
                            }
                        }
                    },
                    'stats-user': {
                        response: {
                            "success": true,
                            "data": {
                                "products_created_today": 5,
                                "products_created_total": 89,
                                "daily_limit": 50,
                                "remaining_today": 45,
                                "is_admin": false,
                                "configured_regions": ["US", "UK", "DE"]
                            }
                        }
                    },
                    'products-list': {
                        response: {
                            "success": true,
                            "data": [
                                {
                                    "id": 1,
                                    "asin": "B09V3KXJPB",
                                    "region": "US",
                                    "title": "Apple AirPods Pro (2nd Generation)",
                                    "current_price": 189.99,
                                    "currency": "USD",
                                    "lowest_price": 179.99,
                                    "highest_price": 249.99,
                                    "is_available": true,
                                    "custom_category": "Electronics",
                                    "amazon_url": "https://www.amazon.com/dp/B09V3KXJPB",
                                    "last_checked": "2024-12-06T10:00:00+00:00"
                                }
                            ],
                            "pagination": {
                                "current_page": 1,
                                "per_page": 20,
                                "total_items": 142,
                                "total_pages": 8
                            }
                        }
                    },
                    'products-create': {
                        request: {
                            "asin": "B09V3KXJPB",
                            "region": "US"
                        },
                        response: {
                            "success": true,
                            "data": {
                                "id": 157,
                                "asin": "B09V3KXJPB",
                                "region": "US",
                                "title": "Apple AirPods Pro (2nd Generation)",
                                "current_price": 189.99,
                                "currency": "USD",
                                "is_available": true,
                                "amazon_url": "https://www.amazon.com/dp/B09V3KXJPB",
                                "created_at": "2024-12-06T10:30:00+00:00"
                            }
                        }
                    },
                    'products-bulk': {
                        request: {
                            "products": [
                                {"asin": "B09V3KXJPB", "region": "US"},
                                {"asin": "B0BDHWDR12", "region": "US"},
                                {"asin": "B09V3KXJPB", "region": "UK"}
                            ]
                        },
                        response: {
                            "success": true,
                            "data": {
                                "created": 3,
                                "failed": 0,
                                "skipped": 0,
                                "products": [
                                    {"id": 158, "asin": "B09V3KXJPB", "region": "US", "status": "created"},
                                    {"id": 159, "asin": "B0BDHWDR12", "region": "US", "status": "created"},
                                    {"id": 160, "asin": "B09V3KXJPB", "region": "UK", "status": "created"}
                                ]
                            }
                        }
                    },
                    'products-refresh': {
                        request: {
                            "product_ids": [1, 2, 3, 4, 5],
                            "batch_size": 10
                        },
                        response: {
                            "success": true,
                            "data": {
                                "refreshed": 5,
                                "failed": 0,
                                "duration_seconds": 2.34,
                                "results": [
                                    {"id": 1, "status": "success", "price_changed": true, "old_price": 199.99, "new_price": 189.99},
                                    {"id": 2, "status": "success", "price_changed": false}
                                ]
                            }
                        }
                    },
                    'products-single': {
                        response: {
                            "success": true,
                            "data": {
                                "id": 1,
                                "asin": "B09V3KXJPB",
                                "region": "US",
                                "title": "Apple AirPods Pro (2nd Generation)",
                                "current_price": 189.99,
                                "currency": "USD",
                                "lowest_price": 179.99,
                                "highest_price": 249.99,
                                "is_available": true,
                                "custom_category": "Electronics",
                                "image_url": "https://m.media-amazon.com/images/I/61SUj2aKoEL._AC_SL1500_.jpg",
                                "amazon_url": "https://www.amazon.com/dp/B09V3KXJPB?tag=yourpartner-20",
                                "created_at": "2024-11-01T08:00:00+00:00",
                                "last_checked": "2024-12-06T10:00:00+00:00",
                                "created_by": 1
                            }
                        }
                    },
                    'products-delete': {
                        response: {
                            "success": true,
                            "data": null
                        }
                    },
                    'products-category': {
                        request: {
                            "category": "Audio Equipment"
                        },
                        response: {
                            "success": true,
                            "data": {
                                "id": 1,
                                "asin": "B09V3KXJPB",
                                "custom_category": "Audio Equipment",
                                "updated_at": "2024-12-06T10:30:00+00:00"
                            }
                        }
                    },
                    'products-prices': {
                        response: {
                            "success": true,
                            "data": [
                                {
                                    "id": 8934,
                                    "price": 189.99,
                                    "currency": "USD",
                                    "is_available": true,
                                    "recorded_at": "2024-12-06T10:00:00+00:00"
                                },
                                {
                                    "id": 8890,
                                    "price": 199.99,
                                    "currency": "USD",
                                    "is_available": true,
                                    "recorded_at": "2024-12-05T10:00:00+00:00"
                                },
                                {
                                    "id": 8845,
                                    "price": 199.99,
                                    "currency": "USD",
                                    "is_available": true,
                                    "recorded_at": "2024-12-04T10:00:00+00:00"
                                }
                            ],
                            "pagination": {
                                "current_page": 1,
                                "per_page": 100,
                                "total_items": 45,
                                "total_pages": 1
                            }
                        }
                    },
                    'products-refresh-single': {
                        response: {
                            "success": true,
                            "data": {
                                "id": 1,
                                "asin": "B09V3KXJPB",
                                "previous_price": 199.99,
                                "current_price": 189.99,
                                "price_changed": true,
                                "is_available": true,
                                "refreshed_at": "2024-12-06T10:30:00+00:00"
                            }
                        }
                    },
                    'products-by-asin': {
                        response: {
                            "success": true,
                            "data": [
                                {"id": 1, "asin": "B09V3KXJPB", "region": "US", "current_price": 189.99, "currency": "USD"},
                                {"id": 45, "asin": "B09V3KXJPB", "region": "UK", "current_price": 169.99, "currency": "GBP"},
                                {"id": 89, "asin": "B09V3KXJPB", "region": "DE", "current_price": 199.99, "currency": "EUR"}
                            ]
                        }
                    },
                    'products-by-asin-region': {
                        response: {
                            "success": true,
                            "data": {
                                "id": 1,
                                "asin": "B09V3KXJPB",
                                "region": "US",
                                "title": "Apple AirPods Pro (2nd Generation)",
                                "current_price": 189.99,
                                "currency": "USD",
                                "is_available": true
                            }
                        }
                    },
                    'settings-get': {
                        response: {
                            "success": true,
                            "data": {
                                "id": 1,
                                "user_id": 1,
                                "access_key": "AKIA****XXXX",
                                "partner_tags": {
                                    "US": "mysite-20",
                                    "UK": "mysite-21",
                                    "DE": "mysite-21"
                                },
                                "created_at": "2024-11-01T08:00:00+00:00",
                                "updated_at": "2024-12-01T10:00:00+00:00"
                            }
                        }
                    },
                    'settings-update': {
                        request: {
                            "access_key": "AKIAIOSFODNN7EXAMPLE",
                            "secret_key": "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY",
                            "partner_tags": {
                                "US": "mysite-20",
                                "UK": "mysite-21"
                            }
                        },
                        response: {
                            "success": true,
                            "data": {
                                "id": 1,
                                "user_id": 1,
                                "access_key": "AKIA****MPLE",
                                "partner_tags": {
                                    "US": "mysite-20",
                                    "UK": "mysite-21"
                                },
                                "updated_at": "2024-12-06T10:30:00+00:00"
                            }
                        }
                    },
                    'settings-delete-tag': {
                        response: {
                            "success": true,
                            "data": null
                        }
                    },
                    'settings-validate': {
                        response: {
                            "success": true,
                            "data": {
                                "valid": true,
                                "message": "Amazon PA-API credentials are valid and working"
                            }
                        }
                    },
                    'regions': {
                        response: {
                            "success": true,
                            "data": [
                                {"code": "US", "name": "United States", "marketplace": "amazon.com", "currency": "USD"},
                                {"code": "UK", "name": "United Kingdom", "marketplace": "amazon.co.uk", "currency": "GBP"},
                                {"code": "DE", "name": "Germany", "marketplace": "amazon.de", "currency": "EUR"},
                                {"code": "FR", "name": "France", "marketplace": "amazon.fr", "currency": "EUR"},
                                {"code": "JP", "name": "Japan", "marketplace": "amazon.co.jp", "currency": "JPY"},
                                {"code": "CA", "name": "Canada", "marketplace": "amazon.ca", "currency": "CAD"},
                                {"code": "AU", "name": "Australia", "marketplace": "amazon.com.au", "currency": "AUD"}
                            ]
                        }
                    },
                    'categories': {
                        response: {
                            "success": true,
                            "data": [
                                {"name": "Electronics", "count": 45},
                                {"name": "Home & Kitchen", "count": 32},
                                {"name": "Books", "count": 28},
                                {"name": "Clothing", "count": 19},
                                {"name": "Sports & Outdoors", "count": 12}
                            ]
                        }
                    },
                    'blacklist-list': {
                        response: {
                            "success": true,
                            "data": [
                                {
                                    "id": 1,
                                    "asin": "B000EXAMPLE",
                                    "region": "US",
                                    "reason": "Counterfeit product",
                                    "created_at": "2024-12-01T08:00:00+00:00",
                                    "created_by": 1
                                },
                                {
                                    "id": 2,
                                    "asin": "B001EXAMPLE",
                                    "region": "US",
                                    "reason": "Adult content",
                                    "created_at": "2024-12-02T09:00:00+00:00",
                                    "created_by": 1
                                }
                            ],
                            "pagination": {
                                "current_page": 1,
                                "per_page": 20,
                                "total_items": 2,
                                "total_pages": 1
                            }
                        }
                    },
                    'blacklist-create': {
                        request: {
                            "asin": "B000EXAMPLE",
                            "region": "US",
                            "reason": "Counterfeit product"
                        },
                        response: {
                            "success": true,
                            "data": {
                                "id": 3,
                                "asin": "B000EXAMPLE",
                                "region": "US",
                                "reason": "Counterfeit product",
                                "created_at": "2024-12-06T10:30:00+00:00",
                                "created_by": 1
                            }
                        }
                    },
                    'blacklist-check': {
                        response: {
                            "success": true,
                            "data": {
                                "blacklisted": true,
                                "entry": {
                                    "id": 1,
                                    "asin": "B000EXAMPLE",
                                    "region": "US",
                                    "reason": "Counterfeit product",
                                    "created_at": "2024-12-01T08:00:00+00:00"
                                }
                            }
                        }
                    },
                    'blacklist-single': {
                        response: {
                            "success": true,
                            "data": {
                                "id": 1,
                                "asin": "B000EXAMPLE",
                                "region": "US",
                                "reason": "Counterfeit product",
                                "created_at": "2024-12-01T08:00:00+00:00",
                                "created_by": 1
                            }
                        }
                    },
                    'blacklist-delete': {
                        response: {
                            "success": true,
                            "data": null
                        }
                    }
                };

                // Modal elements
                var modal = document.getElementById('apt-example-modal');
                var modalTitle = document.getElementById('apt-modal-title');
                var modalCode = document.querySelector('#apt-modal-code code');
                var copyBtn = document.querySelector('.apt-copy-btn');

                // Open modal
                document.querySelectorAll('.apt-example-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var endpoint = this.getAttribute('data-endpoint');
                        var type = this.getAttribute('data-type') || 'response';
                        var example = examples[endpoint];

                        if (example) {
                            var data = type === 'request' ? example.request : example.response;
                            var title = type === 'request' ? 'Example Request' : 'Example Response';
                            title += ' - ' + endpoint.replace(/-/g, '/');

                            modalTitle.textContent = title;
                            modalCode.textContent = JSON.stringify(data, null, 2);
                            modal.style.display = 'flex';
                        }
                    });
                });

                // Close modal
                document.querySelectorAll('.apt-modal-close, .apt-modal-close-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        modal.style.display = 'none';
                    });
                });

                // Close on backdrop click
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        modal.style.display = 'none';
                    }
                });

                // Close on Escape key
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && modal.style.display === 'flex') {
                        modal.style.display = 'none';
                    }
                });

                // Copy to clipboard
                copyBtn.addEventListener('click', function() {
                    var text = modalCode.textContent;
                    navigator.clipboard.writeText(text).then(function() {
                        copyBtn.textContent = '<?php echo esc_js(__('Copied!', 'amazon-price-tracker')); ?>';
                        copyBtn.classList.add('copied');
                        setTimeout(function() {
                            copyBtn.textContent = '<?php echo esc_js(__('Copy to Clipboard', 'amazon-price-tracker')); ?>';
                            copyBtn.classList.remove('copied');
                        }, 2000);
                    });
                });
            });
            </script>

            <h2><?php esc_html_e('Scheduled Price Refresh', 'amazon-price-tracker'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('apt_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="apt_schedule"><?php esc_html_e('Refresh Schedule', 'amazon-price-tracker'); ?></label></th>
                        <td>
                            <select name="apt_schedule" id="apt_schedule">
                                <option value="disabled" <?php selected($current_schedule, 'disabled'); ?>><?php esc_html_e('Disabled', 'amazon-price-tracker'); ?></option>
                                <option value="hourly" <?php selected($current_schedule, 'hourly'); ?>><?php esc_html_e('Hourly', 'amazon-price-tracker'); ?></option>
                                <option value="apt_six_hours" <?php selected($current_schedule, 'apt_six_hours'); ?>><?php esc_html_e('Every 6 Hours', 'amazon-price-tracker'); ?></option>
                                <option value="apt_twelve_hours" <?php selected($current_schedule, 'apt_twelve_hours'); ?>><?php esc_html_e('Every 12 Hours', 'amazon-price-tracker'); ?></option>
                                <option value="twicedaily" <?php selected($current_schedule, 'twicedaily'); ?>><?php esc_html_e('Twice Daily', 'amazon-price-tracker'); ?></option>
                                <option value="daily" <?php selected($current_schedule, 'daily'); ?>><?php esc_html_e('Daily', 'amazon-price-tracker'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="apt_batch_size"><?php esc_html_e('Batch Size', 'amazon-price-tracker'); ?></label></th>
                        <td>
                            <input type="number" name="apt_batch_size" id="apt_batch_size" value="<?php echo esc_attr($batch_size); ?>" min="10" max="500" class="small-text">
                            <p class="description"><?php esc_html_e('Number of products to refresh per scheduled run (10-500).', 'amazon-price-tracker'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="apt_daily_limit"><?php esc_html_e('Daily Creation Limit', 'amazon-price-tracker'); ?></label></th>
                        <td>
                            <input type="number" name="apt_daily_limit" id="apt_daily_limit" value="<?php echo esc_attr($daily_limit); ?>" min="1" max="1000" class="small-text">
                            <p class="description"><?php esc_html_e('Maximum products non-admin users can create per day (1-1000).', 'amazon-price-tracker'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Status', 'amazon-price-tracker'); ?></th>
                        <td>
                            <?php if ($status['is_scheduled']): ?>
                                <span style="color: green;">&#10003; <?php esc_html_e('Scheduled', 'amazon-price-tracker'); ?></span><br>
                                <?php if ($status['next_run']): ?>
                                    <?php printf(esc_html__('Next run: %s', 'amazon-price-tracker'), esc_html($status['next_run'])); ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color: gray;"><?php esc_html_e('Not scheduled', 'amazon-price-tracker'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($status['last_refresh']): ?>
                    <tr>
                        <th><?php esc_html_e('Last Refresh', 'amazon-price-tracker'); ?></th>
                        <td>
                            <?php
                            $last = $status['last_refresh'];
                            printf(
                                esc_html__('%s - %d success, %d failed (%.2fs)', 'amazon-price-tracker'),
                                esc_html($last['timestamp']),
                                (int) $last['success_count'],
                                (int) $last['failure_count'],
                                (float) $last['duration_seconds']
                            );
                            ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
                <p class="submit">
                    <input type="submit" name="apt_save_settings" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'amazon-price-tracker'); ?>">
                    <input type="submit" name="apt_manual_refresh" class="button" value="<?php esc_attr_e('Run Manual Refresh', 'amazon-price-tracker'); ?>">
                </p>
            </form>

            <h2><?php esc_html_e('Quick Stats', 'amazon-price-tracker'); ?></h2>
            <?php
            global $wpdb;
            $products_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}apt_products WHERE is_active = 1");
            $prices_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}apt_price_history");
            ?>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Active Products', 'amazon-price-tracker'); ?></th>
                    <td><?php echo esc_html(number_format_i18n($products_count)); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Price Records', 'amazon-price-tracker'); ?></th>
                    <td><?php echo esc_html(number_format_i18n($prices_count)); ?></td>
                </tr>
            </table>

            <h2><?php esc_html_e('Price History Maintenance', 'amazon-price-tracker'); ?></h2>
            <p class="description">
                <?php esc_html_e('Configure how long price history is retained. Older records are consolidated to save storage while preserving trends.', 'amazon-price-tracker'); ?>
            </p>

            <form method="post">
                <?php wp_nonce_field('apt_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="apt_full_retention"><?php esc_html_e('Full Granularity', 'amazon-price-tracker'); ?></label></th>
                        <td>
                            <input type="number" name="apt_full_retention" id="apt_full_retention" value="<?php echo esc_attr($full_retention); ?>" min="7" max="90" class="small-text"> <?php esc_html_e('days', 'amazon-price-tracker'); ?>
                            <p class="description"><?php esc_html_e('Keep all price records for this period (7-90 days).', 'amazon-price-tracker'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="apt_daily_retention"><?php esc_html_e('Daily Snapshots', 'amazon-price-tracker'); ?></label></th>
                        <td>
                            <input type="number" name="apt_daily_retention" id="apt_daily_retention" value="<?php echo esc_attr($daily_retention); ?>" min="30" max="180" class="small-text"> <?php esc_html_e('days', 'amazon-price-tracker'); ?>
                            <p class="description"><?php esc_html_e('Keep 1 record per day for this period (30-180 days).', 'amazon-price-tracker'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="apt_weekly_retention"><?php esc_html_e('Weekly Snapshots', 'amazon-price-tracker'); ?></label></th>
                        <td>
                            <input type="number" name="apt_weekly_retention" id="apt_weekly_retention" value="<?php echo esc_attr($weekly_retention); ?>" min="90" max="730" class="small-text"> <?php esc_html_e('days', 'amazon-price-tracker'); ?>
                            <p class="description"><?php esc_html_e('Keep 1 record per week for this period (90-730 days). Records older than this keep 1 per month.', 'amazon-price-tracker'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Storage Usage', 'amazon-price-tracker'); ?></th>
                        <td>
                            <ul style="margin: 0;">
                                <li><?php printf(esc_html__('Last 30 days: %s records', 'amazon-price-tracker'), '<strong>' . esc_html(number_format_i18n($storage_stats['records_0_30_days'])) . '</strong>'); ?></li>
                                <li><?php printf(esc_html__('30-90 days: %s records', 'amazon-price-tracker'), '<strong>' . esc_html(number_format_i18n($storage_stats['records_30_90_days'])) . '</strong>'); ?></li>
                                <li><?php printf(esc_html__('90-365 days: %s records', 'amazon-price-tracker'), '<strong>' . esc_html(number_format_i18n($storage_stats['records_90_365_days'])) . '</strong>'); ?></li>
                                <li><?php printf(esc_html__('Over 1 year: %s records', 'amazon-price-tracker'), '<strong>' . esc_html(number_format_i18n($storage_stats['records_over_1_year'])) . '</strong>'); ?></li>
                                <li><?php printf(esc_html__('Estimated size: %s MB', 'amazon-price-tracker'), '<strong>' . esc_html($storage_stats['estimated_size_mb']) . '</strong>'); ?></li>
                            </ul>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Maintenance Status', 'amazon-price-tracker'); ?></th>
                        <td>
                            <?php if ($maintenance_status): ?>
                                <?php
                                printf(
                                    esc_html__('Last run: %s (%d records pruned, %d milestones preserved)', 'amazon-price-tracker'),
                                    esc_html($maintenance_status['timestamp']),
                                    (int) $maintenance_status['records_pruned'],
                                    (int) $maintenance_status['milestones_preserved']
                                );
                                ?>
                            <?php else: ?>
                                <span style="color: gray;"><?php esc_html_e('Never run', 'amazon-price-tracker'); ?></span>
                            <?php endif; ?>
                            <br>
                            <?php if ($maintenance_next): ?>
                                <?php printf(esc_html__('Next scheduled: %s', 'amazon-price-tracker'), esc_html(wp_date('Y-m-d H:i:s', $maintenance_next))); ?>
                            <?php else: ?>
                                <span style="color: orange;"><?php esc_html_e('Not scheduled', 'amazon-price-tracker'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Preserved Records', 'amazon-price-tracker'); ?></th>
                        <td>
                            <p class="description" style="margin-top: 0;">
                                <?php esc_html_e('The following records are never deleted:', 'amazon-price-tracker'); ?>
                            </p>
                            <ul style="margin: 5px 0 0 0; list-style: disc; padding-left: 20px;">
                                <li><?php esc_html_e('All-time lowest price (best deal reference)', 'amazon-price-tracker'); ?></li>
                                <li><?php esc_html_e('All-time highest price (price range reference)', 'amazon-price-tracker'); ?></li>
                                <li><?php esc_html_e('First recorded price (baseline)', 'amazon-price-tracker'); ?></li>
                                <li><?php esc_html_e('Records where availability changed', 'amazon-price-tracker'); ?></li>
                            </ul>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="apt_save_settings" class="button button-primary" value="<?php esc_attr_e('Save Retention Settings', 'amazon-price-tracker'); ?>">
                    <input type="submit" name="apt_run_maintenance" class="button" value="<?php esc_attr_e('Run Maintenance Now', 'amazon-price-tracker'); ?>" onclick="return confirm('<?php esc_attr_e('This will prune old price history records according to retention settings. Continue?', 'amazon-price-tracker'); ?>');">
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Initialize plugin
     */
    public function init(): void {
        // Load text domain for translations
        load_plugin_textdomain(
            'amazon-price-tracker',
            false,
            dirname(plugin_basename(APT_PLUGIN_FILE)) . '/languages'
        );

        // Check for database updates
        $this->maybe_update_database();
    }

    /**
     * Check and run database updates if needed
     */
    private function maybe_update_database(): void {
        $installed_version = get_option('apt_version', '0.0.0');

        if (version_compare($installed_version, APT_VERSION, '<')) {
            require_once APT_PLUGIN_DIR . 'includes/Database/Installer.php';
            $installer = new APT\Database\Installer();
            $installer->install();

            update_option('apt_version', APT_VERSION);
        }
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void {
        // Load and register all REST controllers
        $controllers = [
            'API\\Controllers\\Regions_Controller',
            'API\\Controllers\\Settings_Controller',
            'API\\Controllers\\Products_Controller',
            'API\\Controllers\\Categories_Controller',
            'API\\Controllers\\Blacklist_Controller',
            'API\\Controllers\\Stats_Controller',
            'API\\Controllers\\Health_Controller',
        ];

        foreach ($controllers as $controller_class) {
            $full_class = 'APT\\' . $controller_class;
            if (class_exists($full_class)) {
                $controller = new $full_class();
                $controller->register_routes();
            }
        }
    }
}

// Initialize the plugin
function apt_init(): Amazon_Price_Tracker {
    return Amazon_Price_Tracker::get_instance();
}

// Start the plugin
apt_init();

<?php
/**
 * Plugin Name: Amazon Price Tracker
 * Plugin URI: https://example.com/amazon-price-tracker
 * Description: A WordPress REST API plugin for tracking Amazon product prices across multiple international marketplaces.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
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

// Daily creation limit for non-admin users
define('APT_DAILY_CREATION_LIMIT', 50);

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

        // Add admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
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

        // Clear any cached data
        wp_cache_flush();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate(): void {
        // Clear scheduled events
        APT\Services\Scheduled_Refresh::unschedule();

        // Clear transients
        delete_transient('apt_stats_cache');
    }

    /**
     * Initialize scheduled refresh service
     */
    public function init_scheduled_refresh(): void {
        APT\Services\Scheduled_Refresh::init();
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

            update_option('apt_refresh_batch_size', min(max($batch_size, 10), 500));

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

        $status = APT\Services\Scheduled_Refresh::get_status();
        $batch_size = get_option('apt_refresh_batch_size', 50);
        $current_schedule = $status['schedule'] ?? 'twicedaily';

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Amazon Price Tracker Settings', 'amazon-price-tracker'); ?></h1>

            <h2><?php esc_html_e('API Information', 'amazon-price-tracker'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('API Endpoint', 'amazon-price-tracker'); ?></th>
                    <td><code><?php echo esc_html(rest_url(APT_API_NAMESPACE)); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Authentication', 'amazon-price-tracker'); ?></th>
                    <td><?php esc_html_e('WordPress Application Passwords (HTTP Basic Auth)', 'amazon-price-tracker'); ?></td>
                </tr>
            </table>

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

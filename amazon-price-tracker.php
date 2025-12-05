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

        // Clear any cached data
        wp_cache_flush();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate(): void {
        // Clear scheduled events if any
        wp_clear_scheduled_hook('apt_price_refresh_cron');

        // Clear transients
        delete_transient('apt_stats_cache');
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

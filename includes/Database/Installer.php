<?php
/**
 * Database Installer
 *
 * Handles database table creation and updates.
 *
 * @package AmazonPriceTracker
 */

namespace APT\Database;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Installer
 */
class Installer {

    /**
     * WordPress database instance
     *
     * @var \wpdb
     */
    private $wpdb;

    /**
     * Table prefix
     *
     * @var string
     */
    private $prefix;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->prefix = $wpdb->prefix . 'apt_';
    }

    /**
     * Run database installation
     */
    public function install(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $this->create_products_table();
        $this->create_price_history_table();
        $this->create_user_settings_table();
        $this->create_blacklist_table();
    }

    /**
     * Get table name with prefix
     */
    public function get_table_name(string $table): string {
        return $this->prefix . $table;
    }

    /**
     * Create products table
     */
    private function create_products_table(): void {
        $table_name = $this->get_table_name('products');
        $charset_collate = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            asin VARCHAR(10) NOT NULL,
            region VARCHAR(2) NOT NULL,
            custom_category VARCHAR(255) DEFAULT NULL,
            images LONGTEXT DEFAULT NULL,
            facts LONGTEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by BIGINT(20) UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY asin_region (asin, region),
            KEY region (region),
            KEY is_active (is_active),
            KEY custom_category (custom_category),
            KEY created_by (created_by),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * Create price history table
     */
    private function create_price_history_table(): void {
        $table_name = $this->get_table_name('price_history');
        $products_table = $this->get_table_name('products');
        $charset_collate = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            rrp DECIMAL(10,2) DEFAULT NULL,
            current_price DECIMAL(10,2) DEFAULT NULL,
            is_prime_price TINYINT(1) NOT NULL DEFAULT 0,
            availability ENUM('in_stock', 'out_of_stock', 'limited_stock', 'preorder', 'unknown') NOT NULL DEFAULT 'unknown',
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY recorded_at (recorded_at),
            KEY availability (availability)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * Create user settings table
     */
    private function create_user_settings_table(): void {
        $table_name = $this->get_table_name('user_settings');
        $charset_collate = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            access_key VARCHAR(255) NOT NULL,
            secret_key VARCHAR(255) NOT NULL,
            partner_tags LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * Create blacklist table
     */
    private function create_blacklist_table(): void {
        $table_name = $this->get_table_name('blacklist');
        $charset_collate = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            asin VARCHAR(10) NOT NULL,
            region VARCHAR(2) NOT NULL,
            reason VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by BIGINT(20) UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY asin_region (asin, region),
            KEY region (region),
            KEY created_by (created_by)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * Drop all plugin tables (for uninstall)
     */
    public function uninstall(): void {
        $tables = ['blacklist', 'user_settings', 'price_history', 'products'];

        foreach ($tables as $table) {
            $table_name = $this->get_table_name($table);
            $this->wpdb->query("DROP TABLE IF EXISTS {$table_name}");
        }

        // Remove plugin options
        delete_option('apt_version');
        delete_option('apt_installed_at');
    }
}

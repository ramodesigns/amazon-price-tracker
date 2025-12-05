<?php
/**
 * Uninstall Amazon Price Tracker
 *
 * Removes all plugin data when the plugin is deleted through the WordPress admin.
 *
 * @package AmazonPriceTracker
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Table names
$tables = [
    $wpdb->prefix . 'apt_blacklist',
    $wpdb->prefix . 'apt_user_settings',
    $wpdb->prefix . 'apt_price_history',
    $wpdb->prefix . 'apt_products',
];

// Drop all plugin tables
foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// Delete plugin options
$options = [
    'apt_version',
    'apt_installed_at',
    'apt_encryption_key',
    'apt_refresh_schedule',
    'apt_refresh_batch_size',
    'apt_last_scheduled_refresh',
    'apt_scheduled_refresh_admin_id',
];

foreach ($options as $option) {
    delete_option($option);
}

// Clear any scheduled cron events
wp_clear_scheduled_hook('apt_scheduled_price_refresh');

// Clear any transients
delete_transient('apt_stats_cache');

// Clean up any user meta if we added any (future-proofing)
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'apt_%'");

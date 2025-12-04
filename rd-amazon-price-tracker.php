<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://www.paulramotowski.com
 * @since             1.0.0
 * @package           Rd_Amazon_Price_Tracker
 *
 * @wordpress-plugin
 * Plugin Name:       RD - Amazon Price Tracker
 * Plugin URI:        https://www.ramodesigns.co.uk
 * Description:       An Amazon Price Tracking API
 * Version:           1.0.0
 * Author:            Paul Ramotowski
 * Author URI:        https://www.paulramotowski.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       rd-amazon-price-tracker
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'RD_AMAZON_PRICE_TRACKER_VERSION', '1.0.0' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-rd-amazon-price-tracker-activator.php
 */
function activate_rd_amazon_price_tracker() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-rd-amazon-price-tracker-activator.php';
	Rd_Amazon_Price_Tracker_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-rd-amazon-price-tracker-deactivator.php
 */
function deactivate_rd_amazon_price_tracker() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-rd-amazon-price-tracker-deactivator.php';
	Rd_Amazon_Price_Tracker_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_rd_amazon_price_tracker' );
register_deactivation_hook( __FILE__, 'deactivate_rd_amazon_price_tracker' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-rd-amazon-price-tracker.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_rd_amazon_price_tracker() {

	$plugin = new Rd_Amazon_Price_Tracker();
	$plugin->run();

}
run_rd_amazon_price_tracker();

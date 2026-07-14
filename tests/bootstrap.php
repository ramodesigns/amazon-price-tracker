<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Amazon_Price_Tracker
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	// Deliberately NOT sys_get_temp_dir(): on macOS that resolves to a
	// per-login-session path under /var/folders/.../T/ that the OS purges
	// (on reboot, or periodic housekeeping), silently breaking this bootstrap
	// with "Could not find .../includes/functions.php" until someone re-runs
	// bin/install-wp-tests.sh. $HOME persists across reboots and isn't
	// touched by OS temp-file cleanup.
	$_tests_dir = rtrim( getenv( 'HOME' ), '/\\' ) . '/.wp-tests/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/amazon-price-tracker.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";

/**
 * register_activation_hook() never fires here since the plugin is required
 * directly above rather than activated through WordPress, so the plugin's
 * custom tables (apt_products, apt_user_settings, etc.) would otherwise
 * never exist for integration tests. Create them once, up front.
 */
( new \APT\Database\Installer() )->install();

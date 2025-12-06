<?php
/**
 * PHPUnit Bootstrap for Amazon Price Tracker
 *
 * This file sets up the WordPress testing environment for plugin unit tests.
 *
 * @package AmazonPriceTracker\Tests
 */

// Prevent direct access
if (php_sapi_name() !== 'cli') {
    exit;
}

/**
 * Determine the WordPress tests directory.
 *
 * First check for a WP_TESTS_DIR environment variable, then look for the
 * standard location for the WordPress test suite.
 */
$_tests_dir = getenv('WP_TESTS_DIR');

if (! $_tests_dir) {
    $_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 *
 * This function is hooked into 'muplugins_loaded' to ensure it loads
 * before WordPress initializes plugins.
 */
function _manually_load_plugin() {
    // Define constants that the plugin expects
    if (! defined('APT_VERSION')) {
        define('APT_VERSION', '1.0.0');
    }
    if (! defined('APT_DAILY_CREATION_LIMIT')) {
        define('APT_DAILY_CREATION_LIMIT', 50);
    }

    // Load the main plugin file
    require dirname(dirname(__FILE__)) . '/amazon-price-tracker.php';
}

tests_add_filter('muplugins_loaded', '_manually_load_plugin');

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

/**
 * Helper function to get a private/protected property value.
 *
 * @param object $object   The object containing the property.
 * @param string $property The name of the property to access.
 * @return mixed The property value.
 */
function get_private_property($object, $property) {
    $reflection = new ReflectionClass(get_class($object));
    $property = $reflection->getProperty($property);
    $property->setAccessible(true);
    return $property->getValue($object);
}

/**
 * Helper function to call a private/protected method.
 *
 * @param object $object     The object containing the method.
 * @param string $method     The name of the method to call.
 * @param array  $parameters Optional parameters to pass to the method.
 * @return mixed The method return value.
 */
function call_private_method($object, $method, array $parameters = []) {
    $reflection = new ReflectionClass(get_class($object));
    $method = $reflection->getMethod($method);
    $method->setAccessible(true);
    return $method->invokeArgs($object, $parameters);
}

/**
 * Helper function to create a test user with specific role.
 *
 * @param string $role The WordPress role for the user.
 * @return WP_User The created user object.
 */
function create_test_user($role = 'subscriber') {
    $user_id = wp_create_user(
        'test_' . $role . '_' . wp_generate_password(6, false),
        wp_generate_password(12),
        'test_' . $role . '@example.com'
    );
    $user = get_user_by('id', $user_id);
    $user->set_role($role);
    return $user;
}

/**
 * Helper function to create an admin user.
 *
 * @return WP_User The created admin user object.
 */
function create_admin_user() {
    return create_test_user('administrator');
}

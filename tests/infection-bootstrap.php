<?php
/**
 * Bootstrap for Infection's OWN process (see "bootstrap" in infection.json5).
 *
 * Not loaded by PHPUnit. Infection reflectively inspects mutated classes
 * (e.g. resolving parent hierarchies), which requires them to be loadable
 * inside Infection's process - where WordPress doesn't exist and the
 * plugin's own autoloader (registered in amazon-price-tracker.php, which
 * only loads inside WP) never runs. Without this file, the first mutation
 * that triggers class reflection aborts the entire run with
 * 'Class "APT\..." does not exist'.
 *
 * Three things make the classes loadable here:
 *  - ABSPATH, so the files' direct-access guards (`if (!defined('ABSPATH')) exit;`)
 *    don't kill the process the moment a file is required.
 *  - A stub for WP_REST_Controller, the one WordPress core class any plugin
 *    class extends (Base_Controller). Reflection needs the parent to exist;
 *    its behavior is irrelevant here because nothing is ever instantiated.
 *  - The same PSR-4-ish autoloader amazon-price-tracker.php registers.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}

if (!defined('APT_API_NAMESPACE')) {
    define('APT_API_NAMESPACE', 'amazon-price-tracker/v1');
}

if (!class_exists('WP_REST_Controller')) {
    class WP_REST_Controller {}
}

spl_autoload_register(function ($class) {
    $prefix = 'APT\\';
    $base_dir = __DIR__ . '/../includes/';

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

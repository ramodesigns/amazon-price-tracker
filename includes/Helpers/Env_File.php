<?php
/**
 * .env File Loader
 *
 * Loads simple KEY=VALUE pairs from a .env file at the plugin root into the
 * process environment, if one exists. Used to make locally-supplied test
 * credentials (see Product_Service's PA-API settings fallback) available via
 * getenv() regardless of how PHP was invoked (CLI/PHPUnit or a real WP
 * request), without adding a Composer dependency for something this small.
 *
 * @package AmazonPriceTracker
 */

namespace APT\Helpers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Env_File
 */
class Env_File {

    /**
     * Whether load() has already run this request.
     *
     * @var bool
     */
    private static bool $loaded = false;

    /**
     * Parse APT_PLUGIN_DIR . '.env' (if present) and populate getenv() for
     * any key not already set in the real environment - actual environment
     * variables always take precedence over the file. No-ops silently (and
     * permanently, for the rest of the request) if the file doesn't exist,
     * which is the normal case for every real deployment.
     */
    public static function load(): void {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;

        $path = APT_PLUGIN_DIR . '.env';

        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip a single layer of matching quotes, if present.
            if (strlen($value) >= 2 && (
                ($value[0] === '"' && str_ends_with($value, '"')) ||
                ($value[0] === "'" && str_ends_with($value, "'"))
            )) {
                $value = substr($value, 1, -1);
            }

            if ($key === '' || getenv($key) !== false) {
                continue;
            }

            putenv("{$key}={$value}");
        }
    }
}

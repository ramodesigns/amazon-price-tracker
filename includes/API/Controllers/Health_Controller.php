<?php
/**
 * Health REST Controller
 *
 * Handles the /health endpoints for API health checks.
 *
 * @package AmazonPriceTracker
 */

namespace APT\API\Controllers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use APT\Helpers\Response;
use APT\Helpers\Encryption;

/**
 * Class Health_Controller
 */
class Health_Controller extends Base_Controller {

    /**
     * Route base
     *
     * @var string
     */
    protected $rest_base = 'health';

    /**
     * Register routes
     */
    public function register_routes(): void {
        // GET /health - Public health check (no auth required)
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_health'],
                'permission_callback' => '__return_true', // Public endpoint
            ],
        ]);

        // GET /health/amazon - Check Amazon PA-API connectivity
        register_rest_route($this->namespace, '/' . $this->rest_base . '/amazon', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_amazon_health'],
                'permission_callback' => [$this, 'check_authenticated'],
            ],
        ]);
    }

    /**
     * Get API health status
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_health(WP_REST_Request $request): WP_REST_Response {
        global $wp_version;

        return Response::success([
            'status' => 'healthy',
            'version' => APT_VERSION,
            'wordpress_version' => $wp_version,
            'php_version' => PHP_VERSION,
            'timestamp' => gmdate('c'),
        ]);
    }

    /**
     * Get Amazon PA-API connectivity status
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_amazon_health(WP_REST_Request $request) {
        $user_id = $this->get_current_user_id();
        $settings = $this->get_user_settings($user_id);

        if (!$settings) {
            return Response::success([
                'status' => 'not_configured',
                'message' => __('Amazon PA-API credentials not configured', 'amazon-price-tracker'),
            ]);
        }

        // Get decrypted credentials
        $access_key = Encryption::decrypt($settings->access_key);
        $secret_key = Encryption::decrypt($settings->secret_key);

        if (empty($access_key) || empty($secret_key)) {
            return Response::success([
                'status' => 'not_configured',
                'message' => __('Amazon PA-API credentials incomplete', 'amazon-price-tracker'),
            ]);
        }

        // TODO: Implement actual Amazon PA-API connectivity test
        // For now, return a placeholder response
        // The actual implementation will be added with the Amazon API service

        $start_time = microtime(true);

        // Simulated connectivity check
        // In production, this would make a test request to Amazon PA-API
        $connected = true; // Placeholder
        $response_time = (int) ((microtime(true) - $start_time) * 1000);

        if ($connected) {
            return Response::success([
                'status' => 'connected',
                'message' => __('Amazon PA-API credentials configured (connection test pending full integration)', 'amazon-price-tracker'),
                'response_time_ms' => $response_time,
            ]);
        }

        return Response::success([
            'status' => 'error',
            'message' => __('Failed to connect to Amazon PA-API', 'amazon-price-tracker'),
            'response_time_ms' => $response_time,
        ]);
    }

    /**
     * Get user settings from database
     *
     * @param int $user_id User ID
     * @return object|null
     */
    private function get_user_settings(int $user_id): ?object {
        $db = $this->get_db();
        $table = $this->get_table('user_settings');

        return $db->get_row($db->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d",
            $user_id
        ));
    }
}

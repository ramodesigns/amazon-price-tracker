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
use APT\Services\Product_Service;

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

        // GET /health/amazon - Check Amazon Creators API connectivity
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
     * Get Amazon Creators API connectivity status
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_amazon_health(WP_REST_Request $request) {
        $user_id = $this->get_current_user_id();

        $service = new Product_Service();
        $result = $service->test_connection($user_id);

        return Response::success($result);
    }
}

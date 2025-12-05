<?php
/**
 * Regions REST Controller
 *
 * Handles the /regions endpoint.
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
use APT\Helpers\Regions;
use APT\Helpers\Response;

/**
 * Class Regions_Controller
 */
class Regions_Controller extends Base_Controller {

    /**
     * Route base
     *
     * @var string
     */
    protected $rest_base = 'regions';

    /**
     * Register routes
     */
    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_items'],
                'permission_callback' => [$this, 'check_authenticated'],
            ],
        ]);
    }

    /**
     * Get all supported regions
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_items($request): WP_REST_Response {
        $regions = Regions::get_for_api();
        return Response::success($regions);
    }
}

<?php
/**
 * Categories REST Controller
 *
 * Handles the /categories endpoint.
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
use APT\Helpers\Response;

/**
 * Class Categories_Controller
 */
class Categories_Controller extends Base_Controller {

    /**
     * Route base
     *
     * @var string
     */
    protected $rest_base = 'categories';

    /**
     * Register routes
     */
    public function register_routes(): void {
        // GET /categories - List all categories (Admin only)
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_items'],
                'permission_callback' => [$this, 'check_admin'],
            ],
        ]);
    }

    /**
     * Get all unique custom categories in use
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_items($request): WP_REST_Response {
        $db = $this->get_db();
        $table = $this->get_table('products');

        $results = $db->get_results(
            "SELECT custom_category as name, COUNT(*) as count
             FROM {$table}
             WHERE custom_category IS NOT NULL AND custom_category != '' AND is_active = 1
             GROUP BY custom_category
             ORDER BY custom_category ASC"
        );

        $categories = array_map(function($row) {
            return [
                'name' => $row->name,
                'count' => (int) $row->count,
            ];
        }, $results);

        return Response::success($categories);
    }
}

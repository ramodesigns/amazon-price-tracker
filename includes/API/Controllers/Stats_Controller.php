<?php
/**
 * Stats REST Controller
 *
 * Handles the /stats endpoints.
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
use APT\Helpers\Regions;

/**
 * Class Stats_Controller
 */
class Stats_Controller extends Base_Controller {

    /**
     * Route base
     *
     * @var string
     */
    protected $rest_base = 'stats';

    /**
     * Register routes
     */
    public function register_routes(): void {
        // GET /stats - Overall API statistics
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_stats'],
                'permission_callback' => [$this, 'check_authenticated'],
            ],
        ]);

        // GET /stats/user - Current user's statistics
        register_rest_route($this->namespace, '/' . $this->rest_base . '/user', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_user_stats'],
                'permission_callback' => [$this, 'check_authenticated'],
            ],
        ]);
    }

    /**
     * Get overall API statistics
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_stats($request): WP_REST_Response {
        $db = $this->get_db();
        $products_table = $this->get_table('products');
        $prices_table = $this->get_table('price_history');

        // Total products
        $total_products = (int) $db->get_var("SELECT COUNT(*) FROM {$products_table}");

        // Active products
        $active_products = (int) $db->get_var(
            "SELECT COUNT(*) FROM {$products_table} WHERE is_active = 1"
        );

        // Total price records
        $total_price_records = (int) $db->get_var("SELECT COUNT(*) FROM {$prices_table}");

        // Products by region
        $region_counts = $db->get_results(
            "SELECT region, COUNT(*) as count
             FROM {$products_table}
             WHERE is_active = 1
             GROUP BY region
             ORDER BY count DESC"
        );

        $products_by_region = array_map(function($row) {
            return [
                'region' => $row->region,
                'count' => (int) $row->count,
            ];
        }, $region_counts);

        // Categories count
        $categories_count = (int) $db->get_var(
            "SELECT COUNT(DISTINCT custom_category)
             FROM {$products_table}
             WHERE custom_category IS NOT NULL AND custom_category != '' AND is_active = 1"
        );

        // User daily stats
        $user_stats = $this->get_user_daily_stats();

        $response = [
            'total_products' => $total_products,
            'active_products' => $active_products,
            'total_price_records' => $total_price_records,
            'products_by_region' => $products_by_region,
            'categories_count' => $categories_count,
            'user_stats' => $user_stats,
        ];

        // Admin-only fields
        if ($this->is_admin()) {
            $last_refresh = $db->get_var(
                "SELECT MAX(recorded_at) FROM {$prices_table}"
            );
            $response['last_refresh'] = $last_refresh ? $this->format_datetime($last_refresh) : null;
        }

        return Response::success($response);
    }

    /**
     * Get current user's statistics
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_user_stats($request): WP_REST_Response {
        $user_id = $this->get_current_user_id();
        $is_admin = $this->is_admin();

        $db = $this->get_db();
        $products_table = $this->get_table('products');
        $settings_table = $this->get_table('user_settings');

        $today = gmdate('Y-m-d');

        // Products created today
        $created_today = (int) $db->get_var($db->prepare(
            "SELECT COUNT(*) FROM {$products_table}
             WHERE created_by = %d AND DATE(created_at) = %s",
            $user_id,
            $today
        ));

        // Total products created
        $created_total = (int) $db->get_var($db->prepare(
            "SELECT COUNT(*) FROM {$products_table} WHERE created_by = %d",
            $user_id
        ));

        // Daily limit
        $daily_limit = $is_admin ? null : APT_DAILY_CREATION_LIMIT;
        $remaining = $is_admin ? null : max(0, APT_DAILY_CREATION_LIMIT - $created_today);

        // Configured regions
        $settings = $db->get_row($db->prepare(
            "SELECT partner_tags FROM {$settings_table} WHERE user_id = %d",
            $user_id
        ));

        $configured_regions = [];
        if ($settings && $settings->partner_tags) {
            $partner_tags = json_decode($settings->partner_tags, true) ?: [];
            $configured_regions = array_keys($partner_tags);
        }

        return Response::success([
            'products_created_today' => $created_today,
            'products_created_total' => $created_total,
            'daily_limit' => $daily_limit,
            'remaining_today' => $remaining,
            'is_admin' => $is_admin,
            'configured_regions' => $configured_regions,
        ]);
    }

    /**
     * Get user daily stats for general stats response
     *
     * @return array
     */
    private function get_user_daily_stats(): array {
        $user_id = $this->get_current_user_id();
        $is_admin = $this->is_admin();

        $db = $this->get_db();
        $products_table = $this->get_table('products');
        $today = gmdate('Y-m-d');

        $created_today = (int) $db->get_var($db->prepare(
            "SELECT COUNT(*) FROM {$products_table}
             WHERE created_by = %d AND DATE(created_at) = %s",
            $user_id,
            $today
        ));

        return [
            'products_created_today' => $created_today,
            'daily_limit' => $is_admin ? null : APT_DAILY_CREATION_LIMIT,
            'remaining_today' => $is_admin ? null : max(0, APT_DAILY_CREATION_LIMIT - $created_today),
        ];
    }
}

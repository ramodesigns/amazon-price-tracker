<?php
/**
 * Base REST Controller
 *
 * Abstract base class for all REST API controllers.
 *
 * @package AmazonPriceTracker
 */

namespace APT\API\Controllers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use WP_REST_Controller;
use WP_REST_Request;
use WP_Error;
use APT\Helpers\Response;
use APT\Helpers\Validation;

/**
 * Class Base_Controller
 */
abstract class Base_Controller extends WP_REST_Controller {

    /**
     * API namespace
     *
     * @var string
     */
    protected $namespace = APT_API_NAMESPACE;

    /**
     * Current user ID
     *
     * @var int
     */
    protected $current_user_id = 0;

    /**
     * Check if user is authenticated
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error
     */
    public function check_authenticated(WP_REST_Request $request) {
        $user_id = get_current_user_id();

        if (!$user_id) {
            return new WP_Error(
                'rest_forbidden',
                __('Authentication required.', 'amazon-price-tracker'),
                ['status' => 401]
            );
        }

        $this->current_user_id = $user_id;
        return true;
    }

    /**
     * Check if user is an administrator
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error
     */
    public function check_admin(WP_REST_Request $request) {
        $auth_check = $this->check_authenticated($request);

        if (is_wp_error($auth_check)) {
            return $auth_check;
        }

        if (!$this->is_admin()) {
            return Response::forbidden(__('Administrator access required.', 'amazon-price-tracker'));
        }

        return true;
    }

    /**
     * Check if current user is an administrator
     *
     * @return bool
     */
    protected function is_admin(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Get current user ID
     *
     * Always fetches fresh from WordPress to ensure we have the
     * correctly authenticated user, with fallback to cached value.
     *
     * @return int
     */
    protected function get_current_user_id(): int {
        // Always get fresh from WordPress - Application Passwords may
        // authenticate after controller instantiation
        $wp_user_id = \get_current_user_id();

        if ($wp_user_id > 0) {
            $this->current_user_id = $wp_user_id;
            return $wp_user_id;
        }

        // Fallback to cached value from permission callback
        return $this->current_user_id;
    }

    /**
     * Get pagination parameters from request
     *
     * @param WP_REST_Request $request Request object
     * @param int $default_per_page Default items per page
     * @param int $max_per_page Maximum items per page
     * @return array
     */
    protected function get_pagination_params(WP_REST_Request $request, int $default_per_page = 20, int $max_per_page = 100): array {
        $page = (int) $request->get_param('page') ?: 1;
        $per_page = (int) $request->get_param('per_page') ?: $default_per_page;

        return Validation::validate_pagination($page, $per_page, $max_per_page);
    }

    /**
     * Get sort parameters from request
     *
     * @param WP_REST_Request $request Request object
     * @param string $default_field Default sort field
     * @param string $default_order Default sort order
     * @return array
     */
    protected function get_sort_params(WP_REST_Request $request, string $default_field = 'created_at', string $default_order = 'desc'): array {
        $sort_by = $request->get_param('sort_by') ?: $default_field;
        $sort_order = $request->get_param('sort_order') ?: $default_order;

        return [
            'sort_by' => Validation::validate_product_sort_field($sort_by),
            'sort_order' => Validation::validate_sort_order($sort_order),
        ];
    }

    /**
     * Calculate offset for pagination
     *
     * @param int $page Page number
     * @param int $per_page Items per page
     * @return int
     */
    protected function calculate_offset(int $page, int $per_page): int {
        return ($page - 1) * $per_page;
    }

    /**
     * Format datetime for API response
     *
     * @param string $datetime MySQL datetime
     * @return string ISO 8601 formatted datetime
     */
    protected function format_datetime(string $datetime): string {
        return gmdate('c', strtotime($datetime));
    }

    /**
     * Get WordPress database instance
     *
     * @return \wpdb
     */
    protected function get_db(): \wpdb {
        global $wpdb;
        return $wpdb;
    }

    /**
     * Get table name with prefix
     *
     * @param string $table Table name without prefix
     * @return string Full table name
     */
    protected function get_table(string $table): string {
        return $this->get_db()->prefix . 'apt_' . $table;
    }
}

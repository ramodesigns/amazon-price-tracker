<?php
/**
 * Blacklist REST Controller
 *
 * Handles the /blacklist endpoints.
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
use APT\Helpers\Validation;

/**
 * Class Blacklist_Controller
 */
class Blacklist_Controller extends Base_Controller {

    /**
     * Route base
     *
     * @var string
     */
    protected $rest_base = 'blacklist';

    /**
     * Register routes
     */
    public function register_routes(): void {
        // GET /blacklist - List blacklist entries (Admin only)
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_items'],
                'permission_callback' => [$this, 'check_admin'],
                'args' => [
                    'page' => [
                        'type' => 'integer',
                        'default' => 1,
                    ],
                    'per_page' => [
                        'type' => 'integer',
                        'default' => 20,
                    ],
                    'region' => [
                        'type' => 'string',
                    ],
                    'search' => [
                        'type' => 'string',
                    ],
                ],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create_item'],
                'permission_callback' => [$this, 'check_admin'],
                'args' => [
                    'asin' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                    'region' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                    'reason' => [
                        'type' => 'string',
                    ],
                ],
            ],
        ]);

        // GET /blacklist/check - Check if ASIN/region is blacklisted
        register_rest_route($this->namespace, '/' . $this->rest_base . '/check', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'check_blacklist'],
                'permission_callback' => [$this, 'check_admin'],
                'args' => [
                    'asin' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                    'region' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                ],
            ],
        ]);

        // GET/DELETE /blacklist/{id}
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_item'],
                'permission_callback' => [$this, 'check_admin'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'delete_item'],
                'permission_callback' => [$this, 'check_admin'],
            ],
        ]);
    }

    /**
     * Get paginated blacklist entries
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_items($request): WP_REST_Response {
        $db = $this->get_db();
        $table = $this->get_table('blacklist');

        $pagination = $this->get_pagination_params($request);
        $offset = $this->calculate_offset($pagination['page'], $pagination['per_page']);

        // Build WHERE clause
        $where_clauses = [];
        $where_values = [];

        $region = $request->get_param('region');
        if ($region) {
            $region = Validation::normalize_region($region);
            if (Validation::is_valid_region($region)) {
                $where_clauses[] = 'region = %s';
                $where_values[] = $region;
            }
        }

        $search = $request->get_param('search');
        if ($search) {
            $where_clauses[] = 'asin LIKE %s';
            $where_values[] = '%' . $db->esc_like(Validation::normalize_asin($search)) . '%';
        }

        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }

        // Count total
        $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
        if (!empty($where_values)) {
            $count_sql = $db->prepare($count_sql, ...$where_values);
        }
        $total = (int) $db->get_var($count_sql);

        // Get entries
        $sql = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $query_values = array_merge($where_values, [$pagination['per_page'], $offset]);
        $entries = $db->get_results($db->prepare($sql, ...$query_values));

        $items = array_map([$this, 'format_entry'], $entries);
        $pagination_meta = Response::build_pagination($pagination['page'], $pagination['per_page'], $total);

        return Response::paginated($items, $pagination_meta);
    }

    /**
     * Create blacklist entry
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function create_item($request) {
        $asin = Validation::normalize_asin($request->get_param('asin'));
        $region = Validation::normalize_region($request->get_param('region'));
        $reason = $request->get_param('reason');

        // Validate
        $errors = [];

        if (!Validation::is_valid_asin($asin)) {
            Validation::add_field_error($errors, 'asin', 'ASIN must be 10 alphanumeric characters');
        }

        if (!Validation::is_valid_region($region)) {
            Validation::add_field_error($errors, 'region', 'Invalid region code');
        }

        if (!empty($errors)) {
            return Response::validation_error($errors);
        }

        $db = $this->get_db();
        $table = $this->get_table('blacklist');

        // Check if already exists
        $existing = $db->get_row($db->prepare(
            "SELECT * FROM {$table} WHERE asin = %s AND region = %s",
            $asin,
            $region
        ));

        if ($existing) {
            return Response::conflict('This ASIN/Region is already blacklisted');
        }

        // Soft-delete existing product if tracked
        $products_table = $this->get_table('products');
        $db->update(
            $products_table,
            [
                'is_active' => 0,
                'updated_at' => current_time('mysql', true),
            ],
            [
                'asin' => $asin,
                'region' => $region,
            ]
        );

        // Create blacklist entry
        $now = current_time('mysql', true);
        $db->insert($table, [
            'asin' => $asin,
            'region' => $region,
            'reason' => $reason ? sanitize_text_field(substr($reason, 0, 500)) : null,
            'created_at' => $now,
            'created_by' => $this->get_current_user_id(),
        ]);

        $entry = $db->get_row($db->prepare("SELECT * FROM {$table} WHERE id = %d", $db->insert_id));

        return Response::created($this->format_entry($entry));
    }

    /**
     * Check if ASIN/region is blacklisted
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function check_blacklist($request): WP_REST_Response {
        $asin = Validation::normalize_asin($request->get_param('asin'));
        $region = Validation::normalize_region($request->get_param('region'));

        $db = $this->get_db();
        $table = $this->get_table('blacklist');

        $entry = $db->get_row($db->prepare(
            "SELECT * FROM {$table} WHERE asin = %s AND region = %s",
            $asin,
            $region
        ));

        $response = ['blacklisted' => (bool) $entry];

        if ($entry) {
            $response['entry'] = $this->format_entry($entry);
        }

        return Response::success($response);
    }

    /**
     * Get single blacklist entry
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_item($request) {
        $id = (int) $request->get_param('id');

        $db = $this->get_db();
        $table = $this->get_table('blacklist');

        $entry = $db->get_row($db->prepare("SELECT * FROM {$table} WHERE id = %d", $id));

        if (!$entry) {
            return Response::not_found('Blacklist entry not found');
        }

        return Response::success($this->format_entry($entry));
    }

    /**
     * Delete blacklist entry
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function delete_item($request) {
        $id = (int) $request->get_param('id');

        $db = $this->get_db();
        $table = $this->get_table('blacklist');

        $entry = $db->get_row($db->prepare("SELECT * FROM {$table} WHERE id = %d", $id));

        if (!$entry) {
            return Response::not_found('Blacklist entry not found');
        }

        $db->delete($table, ['id' => $id]);

        return Response::no_content();
    }

    /**
     * Format blacklist entry for response
     *
     * @param object $entry Blacklist entry
     * @return array
     */
    private function format_entry(object $entry): array {
        return [
            'id' => (int) $entry->id,
            'asin' => $entry->asin,
            'region' => $entry->region,
            'reason' => $entry->reason,
            'created_at' => $this->format_datetime($entry->created_at),
            'created_by' => (int) $entry->created_by,
        ];
    }
}

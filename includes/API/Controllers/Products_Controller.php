<?php
/**
 * Products REST Controller
 *
 * Handles the /products endpoints.
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
use APT\Helpers\Regions;
use APT\Services\Product_Service;

/**
 * Class Products_Controller
 */
class Products_Controller extends Base_Controller {

    /**
     * Route base
     *
     * @var string
     */
    protected $rest_base = 'products';

    /**
     * Register routes
     */
    public function register_routes(): void {
        // GET /products - List all products
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_items'],
                'permission_callback' => [$this, 'check_authenticated'],
                'args' => $this->get_collection_params(),
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create_item'],
                'permission_callback' => [$this, 'check_authenticated'],
                'args' => [
                    'asin' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => function($value) {
                            return Validation::normalize_asin($value);
                        },
                    ],
                    'region' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => function($value) {
                            return Validation::normalize_region($value);
                        },
                    ],
                ],
            ],
        ]);

        // POST /products/bulk - Bulk create products
        register_rest_route($this->namespace, '/' . $this->rest_base . '/bulk', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'bulk_create'],
                'permission_callback' => [$this, 'check_authenticated'],
            ],
        ]);

        // POST /products/refresh - Bulk refresh prices (Admin only)
        register_rest_route($this->namespace, '/' . $this->rest_base . '/refresh', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'bulk_refresh'],
                'permission_callback' => [$this, 'check_admin'],
            ],
        ]);

        // GET /products/{id} - Get single product
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_item'],
                'permission_callback' => [$this, 'check_authenticated'],
                'args' => [
                    'id' => [
                        'required' => true,
                        'type' => 'integer',
                    ],
                ],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'delete_item'],
                'permission_callback' => [$this, 'check_admin'],
                'args' => [
                    'id' => [
                        'required' => true,
                        'type' => 'integer',
                    ],
                    'force' => [
                        'type' => 'boolean',
                        'default' => false,
                    ],
                ],
            ],
        ]);

        // PUT /products/{id}/category - Update product category (Admin only)
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/category', [
            [
                'methods' => 'PUT',
                'callback' => [$this, 'update_category'],
                'permission_callback' => [$this, 'check_admin'],
            ],
        ]);

        // GET /products/{id}/prices - Get price history
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/prices', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_prices'],
                'permission_callback' => [$this, 'check_authenticated'],
            ],
        ]);

        // POST /products/{id}/refresh - Refresh single product (Admin only)
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/refresh', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'refresh_item'],
                'permission_callback' => [$this, 'check_admin'],
            ],
        ]);

        // GET /products/by-asin/{asin} - Get products by ASIN
        register_rest_route($this->namespace, '/' . $this->rest_base . '/by-asin/(?P<asin>[A-Za-z0-9]{10})', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_by_asin'],
                'permission_callback' => [$this, 'check_authenticated'],
            ],
        ]);

        // GET /products/by-asin/{asin}/{region} - Get product by ASIN and region
        register_rest_route($this->namespace, '/' . $this->rest_base . '/by-asin/(?P<asin>[A-Za-z0-9]{10})/(?P<region>[A-Z]{2})', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_by_asin_region'],
                'permission_callback' => [$this, 'check_authenticated'],
            ],
        ]);

        // GET /products/by-asin/{asin}/{region}/prices - Get price history by ASIN/region
        register_rest_route($this->namespace, '/' . $this->rest_base . '/by-asin/(?P<asin>[A-Za-z0-9]{10})/(?P<region>[A-Z]{2})/prices', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_prices_by_asin_region'],
                'permission_callback' => [$this, 'check_authenticated'],
            ],
        ]);
    }

    /**
     * Get collection params for products list
     *
     * @return array
     */
    public function get_collection_params(): array {
        return [
            'page' => [
                'type' => 'integer',
                'default' => 1,
                'minimum' => 1,
            ],
            'per_page' => [
                'type' => 'integer',
                'default' => 20,
                'minimum' => 1,
                'maximum' => 100,
            ],
            'region' => [
                'type' => 'string',
            ],
            'regions' => [
                'type' => 'string',
            ],
            'custom_category' => [
                'type' => 'string',
            ],
            'search' => [
                'type' => 'string',
                'minLength' => 2,
            ],
            'min_price' => [
                'type' => 'number',
            ],
            'max_price' => [
                'type' => 'number',
            ],
            'availability' => [
                'type' => 'string',
                'enum' => ['in_stock', 'out_of_stock', 'all'],
            ],
            'is_active' => [
                'type' => 'boolean',
                'default' => true,
            ],
            'sort_by' => [
                'type' => 'string',
                'enum' => ['created_at', 'updated_at', 'current_price', 'title', 'asin'],
                'default' => 'created_at',
            ],
            'sort_order' => [
                'type' => 'string',
                'enum' => ['asc', 'desc'],
                'default' => 'desc',
            ],
        ];
    }

    /**
     * Get list of products
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_items($request) {
        $db = $this->get_db();
        $products_table = $this->get_table('products');
        $prices_table = $this->get_table('price_history');

        // Get pagination
        $pagination = $this->get_pagination_params($request);
        $offset = $this->calculate_offset($pagination['page'], $pagination['per_page']);

        // Build WHERE clause
        $where_clauses = [];
        $where_values = [];

        // Filter by is_active
        $is_active = $request->get_param('is_active');
        if ($is_active !== null) {
            $where_clauses[] = 'p.is_active = %d';
            $where_values[] = Validation::validate_bool($is_active) ? 1 : 0;
        } else {
            $where_clauses[] = 'p.is_active = %d';
            $where_values[] = 1;
        }

        // Filter by single region
        $region = $request->get_param('region');
        if ($region) {
            $region = Validation::normalize_region($region);
            if (Validation::is_valid_region($region)) {
                $where_clauses[] = 'p.region = %s';
                $where_values[] = $region;
            }
        }

        // Filter by multiple regions
        $regions = $request->get_param('regions');
        if ($regions && !$region) {
            $valid_regions = Validation::validate_region_list($regions);
            if (!empty($valid_regions)) {
                $placeholders = implode(', ', array_fill(0, count($valid_regions), '%s'));
                $where_clauses[] = "p.region IN ({$placeholders})";
                $where_values = array_merge($where_values, $valid_regions);
            }
        }

        // Filter by custom category
        $category = $request->get_param('custom_category');
        if ($category !== null) {
            $where_clauses[] = 'p.custom_category = %s';
            $where_values[] = sanitize_text_field($category);
        }

        // Search in title and brand
        $search = $request->get_param('search');
        if ($search && strlen($search) >= 2) {
            $search_term = '%' . $db->esc_like(sanitize_text_field($search)) . '%';
            $where_clauses[] = "(JSON_UNQUOTE(JSON_EXTRACT(p.facts, '$.title')) LIKE %s OR JSON_UNQUOTE(JSON_EXTRACT(p.facts, '$.brand')) LIKE %s)";
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }

        // Build WHERE string
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }

        // Get sort parameters
        $sort = $this->get_sort_params($request);
        $sort_field = $sort['sort_by'];
        $sort_order = strtoupper($sort['sort_order']);

        // Map sort field to actual column
        $sort_column = match($sort_field) {
            'title' => "JSON_UNQUOTE(JSON_EXTRACT(p.facts, '$.title'))",
            'current_price' => 'latest_price.current_price',
            default => "p.{$sort_field}",
        };

        // Count total items
        $count_sql = "SELECT COUNT(*) FROM {$products_table} p {$where_sql}";
        if (!empty($where_values)) {
            $count_sql = $db->prepare($count_sql, ...$where_values);
        }
        $total_items = (int) $db->get_var($count_sql);

        // Get products with latest price
        $sql = "
            SELECT
                p.*,
                latest_price.current_price,
                latest_price.availability
            FROM {$products_table} p
            LEFT JOIN (
                SELECT ph1.product_id, ph1.current_price, ph1.availability
                FROM {$prices_table} ph1
                INNER JOIN (
                    SELECT product_id, MAX(recorded_at) as max_recorded
                    FROM {$prices_table}
                    GROUP BY product_id
                ) ph2 ON ph1.product_id = ph2.product_id AND ph1.recorded_at = ph2.max_recorded
            ) latest_price ON p.id = latest_price.product_id
            {$where_sql}
            ORDER BY {$sort_column} {$sort_order}
            LIMIT %d OFFSET %d
        ";

        $query_values = array_merge($where_values, [$pagination['per_page'], $offset]);
        $products = $db->get_results($db->prepare($sql, ...$query_values));

        // Format products for response
        $items = array_map([$this, 'format_product_summary'], $products);

        // Build pagination meta
        $pagination_meta = Response::build_pagination(
            $pagination['page'],
            $pagination['per_page'],
            $total_items
        );

        return Response::paginated($items, $pagination_meta);
    }

    /**
     * Create a new product
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function create_item($request) {
        $asin = $request->get_param('asin');
        $region = $request->get_param('region');

        // Validate ASIN format
        if (!Validation::is_valid_asin($asin)) {
            return Response::validation_error([
                ['field' => 'asin', 'message' => 'ASIN must be 10 alphanumeric characters'],
            ]);
        }

        // Validate region
        if (!Validation::is_valid_region($region)) {
            return Response::validation_error([
                ['field' => 'region', 'message' => 'Invalid region code'],
            ]);
        }

        // Check rate limit for non-admin users
        if (!$this->is_admin()) {
            $rate_check = $this->check_rate_limit();
            if (is_wp_error($rate_check)) {
                return $rate_check;
            }
        }

        // Check blacklist
        $blacklist_check = $this->check_blacklist($asin, $region);
        if (is_wp_error($blacklist_check)) {
            return $blacklist_check;
        }

        // Check if already exists
        $existing = $this->get_product_by_asin_region($asin, $region);
        if ($existing) {
            return Response::conflict(
                'Product with this ASIN/Region already exists',
                ['id' => (int) $existing->id]
            );
        }

        // Use Product Service to create the product (fetches from Amazon PA-API)
        $service = new Product_Service();
        $result = $service->create_product($asin, $region, $this->get_current_user_id());

        if (!$result['success']) {
            $error_code = $result['error_code'] ?? 'UNKNOWN_ERROR';
            $error_message = $result['error'] ?? 'Unknown error occurred';

            return match($error_code) {
                'ASIN_NOT_FOUND' => Response::asin_not_found(),
                'MISSING_PARTNER_TAG' => Response::missing_partner_tag($region),
                'AMAZON_API_ERROR' => Response::amazon_api_error($error_message),
                'NOT_CONFIGURED' => Response::not_configured($error_message),
                default => Response::error($error_code, $error_message, 500),
            };
        }

        return Response::created($this->format_product($result['product']));
    }

    /**
     * Bulk create products
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function bulk_create($request) {
        $products = $request->get_param('products');

        if (!is_array($products) || empty($products)) {
            return Response::validation_error([
                ['field' => 'products', 'message' => 'Products array is required'],
            ]);
        }

        if (count($products) > 100) {
            return Response::validation_error([
                ['field' => 'products', 'message' => 'Maximum 100 products per request'],
            ]);
        }

        $success_count = 0;
        $failure_count = 0;
        $results = [];

        foreach ($products as $product) {
            $asin = isset($product['asin']) ? Validation::normalize_asin($product['asin']) : '';
            $region = isset($product['region']) ? Validation::normalize_region($product['region']) : '';

            // Create a mock request for each product
            $single_request = new WP_REST_Request('POST');
            $single_request->set_param('asin', $asin);
            $single_request->set_param('region', $region);

            $result = $this->create_item($single_request);

            if (is_wp_error($result)) {
                $failure_count++;
                $results[] = [
                    'asin' => $asin,
                    'region' => $region,
                    'success' => false,
                    'error' => [
                        'code' => $result->get_error_code(),
                        'message' => $result->get_error_message(),
                    ],
                ];
            } else {
                $success_count++;
                $results[] = [
                    'asin' => $asin,
                    'region' => $region,
                    'success' => true,
                    'product' => $result->get_data(),
                ];
            }
        }

        return Response::bulk_result($success_count, $failure_count, $results);
    }

    /**
     * Bulk refresh prices (Admin only)
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function bulk_refresh($request) {
        $product_ids = $request->get_param('product_ids') ?: [];
        $regions = $request->get_param('regions') ?: [];
        $limit = (int) ($request->get_param('limit') ?: 100);

        // Validate and normalize regions
        if (!empty($regions)) {
            $regions = array_filter(array_map(function($r) {
                $r = Validation::normalize_region($r);
                return Validation::is_valid_region($r) ? $r : null;
            }, $regions));
        }

        $service = new Product_Service();
        $result = $service->bulk_refresh($product_ids, $regions, $limit, $this->get_current_user_id());

        return Response::bulk_result($result['success_count'], $result['failure_count'], $result['results']);
    }

    /**
     * Get single product
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_item($request) {
        $id = (int) $request->get_param('id');
        $product = $this->get_product_by_id($id);

        if (!$product) {
            return Response::not_found('Product not found');
        }

        return Response::success($this->format_product($product));
    }

    /**
     * Delete product (Admin only)
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function delete_item($request) {
        $id = (int) $request->get_param('id');
        $force = Validation::validate_bool($request->get_param('force'));

        $product = $this->get_product_by_id($id);

        if (!$product) {
            return Response::not_found('Product not found');
        }

        $db = $this->get_db();

        if ($force) {
            // Hard delete - remove product and all price history
            $db->delete($this->get_table('price_history'), ['product_id' => $id]);
            $db->delete($this->get_table('products'), ['id' => $id]);
        } else {
            // Soft delete - set is_active to false
            $db->update(
                $this->get_table('products'),
                [
                    'is_active' => 0,
                    'updated_at' => current_time('mysql', true),
                ],
                ['id' => $id]
            );
        }

        return Response::no_content();
    }

    /**
     * Update product category (Admin only)
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function update_category($request) {
        $id = (int) $request->get_param('id');
        $category = $request->get_param('custom_category');

        $product = $this->get_product_by_id($id);

        if (!$product) {
            return Response::not_found('Product not found');
        }

        $db = $this->get_db();
        $db->update(
            $this->get_table('products'),
            [
                'custom_category' => Validation::validate_category($category),
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => $id]
        );

        $product = $this->get_product_by_id($id);
        return Response::success($this->format_product($product));
    }

    /**
     * Get price history for product
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_prices($request) {
        $id = (int) $request->get_param('id');
        $product = $this->get_product_by_id($id);

        if (!$product) {
            return Response::not_found('Product not found');
        }

        return $this->get_price_history($product, $request);
    }

    /**
     * Refresh single product (Admin only)
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function refresh_item($request) {
        $id = (int) $request->get_param('id');

        $service = new Product_Service();
        $result = $service->refresh_product($id, $this->get_current_user_id());

        if (!$result['success']) {
            $error_code = $result['error_code'] ?? 'UNKNOWN_ERROR';
            $error_message = $result['error'] ?? 'Unknown error occurred';

            return match($error_code) {
                'NOT_FOUND' => Response::not_found($error_message),
                'AMAZON_API_ERROR' => Response::amazon_api_error($error_message),
                'MISSING_PARTNER_TAG' => Response::error($error_code, $error_message, 400),
                'NOT_CONFIGURED' => Response::not_configured($error_message),
                default => Response::error($error_code, $error_message, 502),
            };
        }

        return Response::success($this->format_product($result['product']));
    }

    /**
     * Get products by ASIN
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_by_asin($request) {
        $asin = Validation::normalize_asin($request->get_param('asin'));

        $db = $this->get_db();
        $products = $db->get_results($db->prepare(
            "SELECT * FROM {$this->get_table('products')} WHERE asin = %s AND is_active = 1",
            $asin
        ));

        if (empty($products)) {
            return Response::not_found('No products found for this ASIN');
        }

        return Response::success(array_map([$this, 'format_product'], $products));
    }

    /**
     * Get product by ASIN and region
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_by_asin_region($request) {
        $asin = Validation::normalize_asin($request->get_param('asin'));
        $region = Validation::normalize_region($request->get_param('region'));

        $product = $this->get_product_by_asin_region($asin, $region);

        if (!$product) {
            return Response::not_found('Product not found');
        }

        return Response::success($this->format_product($product));
    }

    /**
     * Get price history by ASIN/region
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_prices_by_asin_region($request) {
        $asin = Validation::normalize_asin($request->get_param('asin'));
        $region = Validation::normalize_region($request->get_param('region'));

        $product = $this->get_product_by_asin_region($asin, $region);

        if (!$product) {
            return Response::not_found('Product not found');
        }

        return $this->get_price_history($product, $request);
    }

    // ========== Helper Methods ==========

    /**
     * Get product by ID
     */
    private function get_product_by_id(int $id): ?object {
        $db = $this->get_db();
        return $db->get_row($db->prepare(
            "SELECT * FROM {$this->get_table('products')} WHERE id = %d",
            $id
        ));
    }

    /**
     * Get product by ASIN and region
     */
    private function get_product_by_asin_region(string $asin, string $region): ?object {
        $db = $this->get_db();
        return $db->get_row($db->prepare(
            "SELECT * FROM {$this->get_table('products')} WHERE asin = %s AND region = %s",
            $asin,
            $region
        ));
    }

    /**
     * Check rate limit for current user
     */
    private function check_rate_limit() {
        $user_id = $this->get_current_user_id();
        $today = gmdate('Y-m-d');

        $db = $this->get_db();
        $count = (int) $db->get_var($db->prepare(
            "SELECT COUNT(*) FROM {$this->get_table('products')}
             WHERE created_by = %d AND DATE(created_at) = %s",
            $user_id,
            $today
        ));

        if ($count >= APT_DAILY_CREATION_LIMIT) {
            $tomorrow = gmdate('c', strtotime('tomorrow midnight'));
            return Response::rate_limit_exceeded(APT_DAILY_CREATION_LIMIT, $count, $tomorrow);
        }

        return true;
    }

    /**
     * Check if ASIN/region is blacklisted
     */
    private function check_blacklist(string $asin, string $region) {
        $db = $this->get_db();
        $entry = $db->get_row($db->prepare(
            "SELECT * FROM {$this->get_table('blacklist')} WHERE asin = %s AND region = %s",
            $asin,
            $region
        ));

        if ($entry) {
            return Response::blacklisted($entry->reason);
        }

        return true;
    }

    /**
     * Check if user has partner tag for region
     */
    private function check_partner_tag(string $region) {
        $user_id = $this->get_current_user_id();
        $db = $this->get_db();

        $settings = $db->get_row($db->prepare(
            "SELECT partner_tags FROM {$this->get_table('user_settings')} WHERE user_id = %d",
            $user_id
        ));

        if (!$settings) {
            return Response::missing_partner_tag($region);
        }

        $partner_tags = json_decode($settings->partner_tags, true) ?: [];

        if (!isset($partner_tags[$region])) {
            return Response::missing_partner_tag($region);
        }

        return true;
    }

    /**
     * Get price history for a product
     */
    private function get_price_history(object $product, WP_REST_Request $request): WP_REST_Response {
        $db = $this->get_db();

        // Pagination
        $pagination = $this->get_pagination_params($request);
        $offset = $this->calculate_offset($pagination['page'], $pagination['per_page']);

        // Date filters
        $where_clauses = ['product_id = %d'];
        $where_values = [(int) $product->id];

        $from = $request->get_param('from');
        if ($from) {
            $from_date = Validation::validate_datetime($from);
            if ($from_date) {
                $where_clauses[] = 'recorded_at >= %s';
                $where_values[] = $from_date;
            }
        }

        $to = $request->get_param('to');
        if ($to) {
            $to_date = Validation::validate_datetime($to);
            if ($to_date) {
                $where_clauses[] = 'recorded_at <= %s';
                $where_values[] = $to_date;
            }
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Sort order
        $sort_order = Validation::validate_sort_order($request->get_param('sort_order') ?: 'desc');

        // Count total
        $count_sql = "SELECT COUNT(*) FROM {$this->get_table('price_history')} WHERE {$where_sql}";
        $total = (int) $db->get_var($db->prepare($count_sql, ...$where_values));

        // Get records
        $sql = "SELECT * FROM {$this->get_table('price_history')}
                WHERE {$where_sql}
                ORDER BY recorded_at {$sort_order}
                LIMIT %d OFFSET %d";

        $query_values = array_merge($where_values, [$pagination['per_page'], $offset]);
        $records = $db->get_results($db->prepare($sql, ...$query_values));

        // Format records
        $data = array_map(function($record) {
            return [
                'id' => (int) $record->id,
                'product_id' => (int) $record->product_id,
                'rrp' => $record->rrp !== null ? (float) $record->rrp : null,
                'current_price' => $record->current_price !== null ? (float) $record->current_price : null,
                'is_prime_price' => (bool) $record->is_prime_price,
                'availability' => $record->availability,
                'recorded_at' => $this->format_datetime($record->recorded_at),
            ];
        }, $records);

        // Get facts for title
        $facts = json_decode($product->facts, true) ?: [];

        // Build response
        $response = [
            'product' => [
                'id' => (int) $product->id,
                'asin' => $product->asin,
                'region' => $product->region,
                'title' => $facts['title'] ?? null,
            ],
            'currency' => Regions::get_currency($product->region),
            'data' => $data,
            'meta' => [
                'pagination' => Response::build_pagination($pagination['page'], $pagination['per_page'], $total),
            ],
        ];

        // Handle aggregations
        $aggregate = Validation::validate_aggregation($request->get_param('aggregate') ?: 'none');
        if ($aggregate !== 'none') {
            $response['aggregations'] = $this->calculate_aggregations($product->id, $aggregate, $from, $to);
        }

        return Response::success($response);
    }

    /**
     * Calculate price aggregations
     */
    private function calculate_aggregations(int $product_id, string $period, ?string $from, ?string $to): array {
        $db = $this->get_db();

        $date_format = match($period) {
            'daily' => '%Y-%m-%d',
            'weekly' => '%Y-%u',
            'monthly' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        $where_clauses = ['product_id = %d'];
        $where_values = [$product_id];

        if ($from) {
            $from_date = Validation::validate_datetime($from);
            if ($from_date) {
                $where_clauses[] = 'recorded_at >= %s';
                $where_values[] = $from_date;
            }
        }

        if ($to) {
            $to_date = Validation::validate_datetime($to);
            if ($to_date) {
                $where_clauses[] = 'recorded_at <= %s';
                $where_values[] = $to_date;
            }
        }

        $where_sql = implode(' AND ', $where_clauses);

        $sql = "SELECT
                    DATE_FORMAT(recorded_at, '{$date_format}') as period,
                    MIN(recorded_at) as period_start,
                    MAX(recorded_at) as period_end,
                    MIN(current_price) as min_price,
                    MAX(current_price) as max_price,
                    AVG(current_price) as avg_price,
                    MIN(rrp) as min_rrp,
                    MAX(rrp) as max_rrp,
                    AVG(rrp) as avg_rrp,
                    COUNT(*) as record_count
                FROM {$this->get_table('price_history')}
                WHERE {$where_sql}
                GROUP BY period
                ORDER BY period ASC";

        $results = $db->get_results($db->prepare($sql, ...$where_values));

        return array_map(function($row) {
            return [
                'period_start' => $this->format_datetime($row->period_start),
                'period_end' => $this->format_datetime($row->period_end),
                'min_price' => $row->min_price !== null ? round((float) $row->min_price, 2) : null,
                'max_price' => $row->max_price !== null ? round((float) $row->max_price, 2) : null,
                'avg_price' => $row->avg_price !== null ? round((float) $row->avg_price, 2) : null,
                'min_rrp' => $row->min_rrp !== null ? round((float) $row->min_rrp, 2) : null,
                'max_rrp' => $row->max_rrp !== null ? round((float) $row->max_rrp, 2) : null,
                'avg_rrp' => $row->avg_rrp !== null ? round((float) $row->avg_rrp, 2) : null,
                'record_count' => (int) $row->record_count,
            ];
        }, $results);
    }

    /**
     * Format product for full response
     */
    private function format_product(object $product): array {
        return [
            'id' => (int) $product->id,
            'asin' => $product->asin,
            'region' => $product->region,
            'custom_category' => $product->custom_category,
            'images' => json_decode($product->images, true) ?: [],
            'facts' => json_decode($product->facts, true) ?: [],
            'is_active' => (bool) $product->is_active,
            'created_at' => $this->format_datetime($product->created_at),
            'updated_at' => $this->format_datetime($product->updated_at),
            'created_by' => (int) $product->created_by,
        ];
    }

    /**
     * Format product summary for list response
     */
    private function format_product_summary(object $product): array {
        $facts = json_decode($product->facts, true) ?: [];

        return [
            'id' => (int) $product->id,
            'asin' => $product->asin,
            'region' => $product->region,
            'title' => $facts['title'] ?? null,
            'custom_category' => $product->custom_category,
            'is_active' => (bool) $product->is_active,
            'current_price' => isset($product->current_price) ? (float) $product->current_price : null,
            'currency' => Regions::get_currency($product->region),
            'availability' => $product->availability ?? 'unknown',
            'created_at' => $this->format_datetime($product->created_at),
            'updated_at' => $this->format_datetime($product->updated_at),
        ];
    }
}

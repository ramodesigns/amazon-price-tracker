<?php
/**
 * Product Service
 *
 * Handles product creation, updates, and price refresh operations.
 *
 * @package AmazonPriceTracker
 */

namespace APT\Services;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use APT\Helpers\Regions;
use APT\Helpers\Encryption;

/**
 * Class Product_Service
 */
class Product_Service {

    /**
     * WordPress database instance
     *
     * @var \wpdb
     */
    private \wpdb $db;

    /**
     * Products table name
     *
     * @var string
     */
    private string $products_table;

    /**
     * Price history table name
     *
     * @var string
     */
    private string $prices_table;

    /**
     * User settings table name
     *
     * @var string
     */
    private string $settings_table;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->products_table = $wpdb->prefix . 'apt_products';
        $this->prices_table = $wpdb->prefix . 'apt_price_history';
        $this->settings_table = $wpdb->prefix . 'apt_user_settings';
    }

    /**
     * Create a new product by fetching data from Amazon
     *
     * @param string $asin Product ASIN
     * @param string $region Region code
     * @param int $user_id User ID creating the product
     * @return array Result with 'success', 'product' or 'error'
     */
    public function create_product(string $asin, string $region, int $user_id): array {
        // Get user settings
        $settings = $this->get_user_settings($user_id);

        if (!$settings) {
            return [
                'success' => false,
                'error_code' => 'NOT_CONFIGURED',
                'error' => 'Amazon PA-API credentials not configured',
            ];
        }

        // Create Amazon API client
        $amazon = Amazon_API::from_settings($settings, $region);

        if (!$amazon) {
            return [
                'success' => false,
                'error_code' => 'MISSING_PARTNER_TAG',
                'error' => "No partner tag configured for region {$region}",
            ];
        }

        // Fetch product from Amazon
        $product_data = $amazon->get_item($asin);

        if (!$product_data) {
            $error = $amazon->get_last_error();

            // Check if it's a "not found" error
            if (str_contains(strtolower($error ?? ''), 'itemnotfound') ||
                str_contains(strtolower($error ?? ''), 'invalid') ||
                str_contains(strtolower($error ?? ''), 'not found')) {
                return [
                    'success' => false,
                    'error_code' => 'ASIN_NOT_FOUND',
                    'error' => 'Product not found on Amazon',
                ];
            }

            return [
                'success' => false,
                'error_code' => 'AMAZON_API_ERROR',
                'error' => $error ?: 'Failed to fetch product from Amazon',
            ];
        }

        // Insert product into database
        $now = current_time('mysql', true);

        $insert_data = [
            'asin' => $asin,
            'region' => $region,
            'custom_category' => null,
            'images' => wp_json_encode($product_data['images'] ?? []),
            'facts' => wp_json_encode($product_data['facts'] ?? []),
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $user_id,
        ];

        $this->db->insert($this->products_table, $insert_data);
        $product_id = $this->db->insert_id;

        if (!$product_id) {
            return [
                'success' => false,
                'error_code' => 'DATABASE_ERROR',
                'error' => 'Failed to save product to database',
            ];
        }

        // Insert initial price record
        $pricing = $product_data['pricing'] ?? [];

        $price_data = [
            'product_id' => $product_id,
            'rrp' => $pricing['rrp'] ?? null,
            'current_price' => $pricing['current_price'] ?? null,
            'is_prime_price' => ($pricing['is_prime_price'] ?? false) ? 1 : 0,
            'availability' => $pricing['availability'] ?? 'unknown',
            'recorded_at' => $now,
        ];

        $this->db->insert($this->prices_table, $price_data);

        // Fetch and return the complete product
        $product = $this->get_product_by_id($product_id);

        return [
            'success' => true,
            'product' => $product,
        ];
    }

    /**
     * Refresh price for a single product
     *
     * @param int $product_id Product ID
     * @param int $user_id User ID performing the refresh
     * @return array Result with 'success', 'product' or 'error'
     */
    public function refresh_product(int $product_id, int $user_id): array {
        $product = $this->get_product_by_id($product_id);

        if (!$product) {
            return [
                'success' => false,
                'error_code' => 'NOT_FOUND',
                'error' => 'Product not found',
            ];
        }

        // Get user settings
        $settings = $this->get_user_settings($user_id);

        if (!$settings) {
            return [
                'success' => false,
                'error_code' => 'NOT_CONFIGURED',
                'error' => 'Amazon PA-API credentials not configured',
            ];
        }

        // Create Amazon API client
        $amazon = Amazon_API::from_settings($settings, $product->region);

        if (!$amazon) {
            return [
                'success' => false,
                'error_code' => 'MISSING_PARTNER_TAG',
                'error' => "No partner tag configured for region {$product->region}",
            ];
        }

        // Fetch updated data from Amazon
        $product_data = $amazon->get_item($product->asin);

        if (!$product_data) {
            // On refresh failure, keep existing price unchanged
            return [
                'success' => false,
                'error_code' => 'AMAZON_API_ERROR',
                'error' => $amazon->get_last_error() ?: 'Failed to fetch price from Amazon',
            ];
        }

        $now = current_time('mysql', true);

        // Update product data (images and facts)
        $this->db->update(
            $this->products_table,
            [
                'images' => wp_json_encode($product_data['images'] ?? []),
                'facts' => wp_json_encode($product_data['facts'] ?? []),
                'updated_at' => $now,
            ],
            ['id' => $product_id]
        );

        // Insert new price record
        $pricing = $product_data['pricing'] ?? [];

        $price_data = [
            'product_id' => $product_id,
            'rrp' => $pricing['rrp'] ?? null,
            'current_price' => $pricing['current_price'] ?? null,
            'is_prime_price' => ($pricing['is_prime_price'] ?? false) ? 1 : 0,
            'availability' => $pricing['availability'] ?? 'unknown',
            'recorded_at' => $now,
        ];

        $this->db->insert($this->prices_table, $price_data);

        // Return updated product
        $product = $this->get_product_by_id($product_id);

        return [
            'success' => true,
            'product' => $product,
        ];
    }

    /**
     * Bulk refresh products
     *
     * @param array $product_ids Optional specific product IDs
     * @param array $regions Optional region filter
     * @param int $limit Maximum products to refresh
     * @param int $user_id User ID performing the refresh
     * @return array Bulk result with success/failure counts
     */
    public function bulk_refresh(array $product_ids = [], array $regions = [], int $limit = 100, int $user_id = 0): array {
        // Build query to get products to refresh
        $where_clauses = ['is_active = 1'];
        $where_values = [];

        if (!empty($product_ids)) {
            $placeholders = implode(', ', array_fill(0, count($product_ids), '%d'));
            $where_clauses[] = "id IN ({$placeholders})";
            $where_values = array_merge($where_values, $product_ids);
        }

        if (!empty($regions)) {
            $placeholders = implode(', ', array_fill(0, count($regions), '%s'));
            $where_clauses[] = "region IN ({$placeholders})";
            $where_values = array_merge($where_values, $regions);
        }

        $where_sql = implode(' AND ', $where_clauses);

        $sql = "SELECT id, asin, region FROM {$this->products_table}
                WHERE {$where_sql}
                ORDER BY updated_at ASC
                LIMIT %d";

        $where_values[] = min($limit, 1000);

        $products = $this->db->get_results($this->db->prepare($sql, ...$where_values));

        // Get user settings
        $settings = $this->get_user_settings($user_id);

        if (!$settings) {
            return [
                'success_count' => 0,
                'failure_count' => count($products),
                'results' => array_map(function($p) {
                    return [
                        'product_id' => (int) $p->id,
                        'asin' => $p->asin,
                        'region' => $p->region,
                        'success' => false,
                        'error' => 'Amazon PA-API credentials not configured',
                    ];
                }, $products),
            ];
        }

        // Group products by region for efficient API calls
        $by_region = [];
        foreach ($products as $product) {
            $by_region[$product->region][] = $product;
        }

        $results = [];
        $success_count = 0;
        $failure_count = 0;

        foreach ($by_region as $region => $region_products) {
            // Create API client for this region
            $amazon = Amazon_API::from_settings($settings, $region);

            if (!$amazon) {
                // No partner tag for this region
                foreach ($region_products as $product) {
                    $failure_count++;
                    $results[] = [
                        'product_id' => (int) $product->id,
                        'asin' => $product->asin,
                        'region' => $region,
                        'success' => false,
                        'error' => "No partner tag configured for region {$region}",
                    ];
                }
                continue;
            }

            // Process in batches of 10 (PA-API limit)
            $batches = array_chunk($region_products, 10);

            foreach ($batches as $batch) {
                $asins = array_map(function($p) { return $p->asin; }, $batch);
                $asin_to_product = [];
                foreach ($batch as $product) {
                    $asin_to_product[$product->asin] = $product;
                }

                // Fetch from Amazon
                $amazon_data = $amazon->get_items($asins);
                $now = current_time('mysql', true);

                foreach ($batch as $product) {
                    $data = $amazon_data[$product->asin] ?? null;

                    if (!$data) {
                        $failure_count++;
                        $results[] = [
                            'product_id' => (int) $product->id,
                            'asin' => $product->asin,
                            'region' => $region,
                            'success' => false,
                            'error' => 'Product not found or API error',
                        ];
                        continue;
                    }

                    // Update product
                    $this->db->update(
                        $this->products_table,
                        [
                            'images' => wp_json_encode($data['images'] ?? []),
                            'facts' => wp_json_encode($data['facts'] ?? []),
                            'updated_at' => $now,
                        ],
                        ['id' => $product->id]
                    );

                    // Insert new price record
                    $pricing = $data['pricing'] ?? [];

                    $this->db->insert($this->prices_table, [
                        'product_id' => $product->id,
                        'rrp' => $pricing['rrp'] ?? null,
                        'current_price' => $pricing['current_price'] ?? null,
                        'is_prime_price' => ($pricing['is_prime_price'] ?? false) ? 1 : 0,
                        'availability' => $pricing['availability'] ?? 'unknown',
                        'recorded_at' => $now,
                    ]);

                    $success_count++;
                    $results[] = [
                        'product_id' => (int) $product->id,
                        'asin' => $product->asin,
                        'region' => $region,
                        'success' => true,
                    ];
                }

                // Small delay between batches to respect rate limits
                if (count($batches) > 1) {
                    usleep(100000); // 100ms
                }
            }
        }

        return [
            'success_count' => $success_count,
            'failure_count' => $failure_count,
            'results' => $results,
        ];
    }

    /**
     * Test Amazon API connectivity for a user
     *
     * @param int $user_id User ID
     * @param string|null $region Optional specific region to test
     * @return array Connection test result
     */
    public function test_connection(int $user_id, ?string $region = null): array {
        $settings = $this->get_user_settings($user_id);

        if (!$settings) {
            return [
                'status' => 'not_configured',
                'message' => 'Amazon PA-API credentials not configured',
            ];
        }

        // Use US region by default, or find the first configured region
        $partner_tags = json_decode($settings->partner_tags, true) ?: [];

        if (empty($partner_tags)) {
            return [
                'status' => 'not_configured',
                'message' => 'No partner tags configured for any region',
            ];
        }

        $test_region = $region ?: array_keys($partner_tags)[0];

        if (!isset($partner_tags[$test_region])) {
            return [
                'status' => 'not_configured',
                'message' => "No partner tag configured for region {$test_region}",
            ];
        }

        $amazon = Amazon_API::from_settings($settings, $test_region);

        if (!$amazon) {
            return [
                'status' => 'error',
                'message' => 'Failed to initialize Amazon API client',
            ];
        }

        $connected = $amazon->test_connection();

        if ($connected) {
            return [
                'status' => 'connected',
                'message' => 'Successfully connected to Amazon PA-API',
                'response_time_ms' => $amazon->get_last_response_time(),
            ];
        }

        return [
            'status' => 'error',
            'message' => $amazon->get_last_error() ?: 'Failed to connect to Amazon PA-API',
            'response_time_ms' => $amazon->get_last_response_time(),
        ];
    }

    /**
     * Get product by ID
     *
     * @param int $id Product ID
     * @return object|null
     */
    private function get_product_by_id(int $id): ?object {
        return $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->products_table} WHERE id = %d",
            $id
        ));
    }

    /**
     * Get user settings
     *
     * @param int $user_id User ID
     * @return object|null
     */
    private function get_user_settings(int $user_id): ?object {
        return $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->settings_table} WHERE user_id = %d",
            $user_id
        ));
    }
}

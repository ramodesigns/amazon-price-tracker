<?php
/**
 * Products Controller Component Test
 *
 * Exercises POST /products end-to-end through the real WP REST dispatcher -
 * real routing/permission callbacks, a real authenticated user, real
 * database writes - but with Amazon's Creators API faked via the
 * pre_http_request filter (see creators-api-mock.php). This is the plugin's own logic under test
 * (routing, validation, error mapping, DB row shape, response shape); the
 * canned fixtures let every branch (success, not-found, malformed response,
 * timeout) be hit deterministically, which live Amazon won't reliably
 * reproduce. See tests/integration/test-products-controller.php for the
 * one real-network "does the wire format work" check for this endpoint.
 *
 * @package AmazonPriceTracker\Tests\Component
 */

use APT\Helpers\Encryption;

require_once __DIR__ . '/creators-api-mock.php';

/**
 * Test case for the Products REST controller's create path, against a
 * faked Amazon Creators API.
 */
class Test_Products_Controller_Component extends WP_UnitTestCase {

    /**
     * @var WP_REST_Server
     */
    protected $server;

    /**
     * @var int|null
     */
    protected $created_user_id;

    /**
     * Product IDs inserted directly via insert_product() (bypassing
     * create_item), tracked so tearDown() can also clean up their
     * price_history rows - unlike products created through create_item(),
     * these have no COMMIT-driven side effect that already leaks past
     * WP_UnitTestCase's rollback, so they'd otherwise just accumulate.
     *
     * @var int[]
     */
    protected $direct_product_ids = [];

    /**
     * User IDs (beyond $created_user_id) that had their own apt_user_settings
     * row inserted directly by a test - e.g. an admin used to test the
     * daily-limit bypass. Tracked here (rather than cleaned up inline at
     * the end of the test method) so tearDown() still cleans them up even
     * if an assertion earlier in the method fails and throws - otherwise
     * the row leaks past create_product()'s internal COMMIT (see tearDown()
     * docblock) and permanently orphans, breaking a later test that
     * happens to reuse the same auto-incremented user_id.
     *
     * @var int[]
     */
    protected $extra_settings_user_ids = [];

    /**
     * Boot a real REST server with the plugin's routes registered, and set
     * up a user with fake (but well-formed) Creators API credentials - the
     * mock intercepts the request before those credentials are ever sent
     * anywhere, so their actual values don't matter.
     */
    public function setUp(): void {
        parent::setUp();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        $this->server = $wp_rest_server;
        do_action('rest_api_init', $this->server);

        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $this->created_user_id = $user_id;
        wp_set_current_user($user_id);

        $wpdb->insert($wpdb->prefix . 'apt_user_settings', [
            'user_id' => $user_id,
            'creators_credential_id' => Encryption::encrypt('test-credential-id'),
            'creators_credential_secret' => Encryption::encrypt('test-credential-secret'),
            'creators_credential_version' => '3.2',
            'partner_tags' => wp_json_encode(['UK' => 'test-partner-tag']),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);
    }

    /**
     * Explicitly clean up rows this test creates, and reset the mock queue.
     *
     * Product_Service::create_product() issues its own internal
     * START TRANSACTION/COMMIT around the insert. Since MySQL has no true
     * nested transactions, that COMMIT also commits WP_UnitTestCase's own
     * test-wrapping transaction, so anything created here would otherwise
     * permanently leak into the database instead of being rolled back.
     */
    public function tearDown(): void {
        global $wpdb;

        apt_test_reset_creators_api_responses();

        foreach ($this->direct_product_ids as $product_id) {
            $wpdb->delete($wpdb->prefix . 'apt_price_history', ['product_id' => $product_id]);
        }
        $wpdb->delete($wpdb->prefix . 'apt_products', ['created_by' => $this->created_user_id]);
        $wpdb->delete($wpdb->prefix . 'apt_user_settings', ['user_id' => $this->created_user_id]);

        foreach ($this->extra_settings_user_ids as $user_id) {
            $wpdb->delete($wpdb->prefix . 'apt_products', ['created_by' => $user_id]);
            $wpdb->delete($wpdb->prefix . 'apt_user_settings', ['user_id' => $user_id]);
        }

        delete_option('apt_daily_creation_limit');

        // Product_Service's internal COMMIT above leaves autocommit off but
        // no transaction open, so WP_UnitTestCase's own ROLLBACK (called via
        // parent::tearDown() below) would otherwise silently undo these
        // cleanup deletes too. Commit them explicitly first.
        $wpdb->query('COMMIT');

        parent::tearDown();
    }

    /**
     * Switch the current user to a fresh administrator, for endpoints
     * gated by check_admin(). Leaves $this->created_user_id (and any
     * products already created_by it) untouched, since ownership and
     * "who's calling the endpoint right now" are independent here.
     */
    private function login_as_admin(): int {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        return $admin_id;
    }

    /**
     * Log in as a fresh administrator with a real Creators API settings row
     * of their own - refresh_item()/bulk_refresh() look up the *currently
     * authenticated* user's settings (not the product's original creator),
     * so refresh tests need this rather than login_as_admin() alone.
     * Tracked via $extra_settings_user_ids so tearDown() always cleans it up.
     */
    private function login_as_admin_with_creators_settings(): int {
        global $wpdb;

        $admin_id = $this->login_as_admin();
        $this->extra_settings_user_ids[] = $admin_id;

        $wpdb->insert($wpdb->prefix . 'apt_user_settings', [
            'user_id' => $admin_id,
            'creators_credential_id' => Encryption::encrypt('test-credential-id'),
            'creators_credential_secret' => Encryption::encrypt('test-credential-secret'),
            'creators_credential_version' => '3.2',
            'partner_tags' => wp_json_encode(['UK' => 'test-partner-tag']),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);

        return $admin_id;
    }

    /**
     * Insert a product row directly (bypassing create_item/Amazon), for
     * tests that only care about read/update/delete behavior against
     * existing data.
     *
     * @param array $overrides Column overrides.
     * @return int Inserted product ID.
     */
    private function insert_product(array $overrides = []): int {
        global $wpdb;

        $data = array_merge([
            'asin' => 'B0LISTTES1',
            'region' => 'UK',
            'custom_category' => null,
            'images' => wp_json_encode([]),
            'facts' => wp_json_encode(['title' => 'Test Product']),
            'is_active' => 1,
            'created_by' => $this->created_user_id,
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], $overrides);

        $wpdb->insert($wpdb->prefix . 'apt_products', $data);
        $product_id = $wpdb->insert_id;
        $this->direct_product_ids[] = $product_id;

        return $product_id;
    }

    /**
     * Insert a price_history row directly for a product.
     *
     * @param int $product_id
     * @param array $overrides Column overrides.
     * @return int Inserted price_history ID.
     */
    private function insert_price_history(int $product_id, array $overrides = []): int {
        global $wpdb;

        $data = array_merge([
            'product_id' => $product_id,
            'rrp' => null,
            'current_price' => 9.99,
            'is_prime_price' => 0,
            'availability' => 'in_stock',
            'recorded_at' => current_time('mysql', true),
        ], $overrides);

        $wpdb->insert($wpdb->prefix . 'apt_price_history', $data);

        return $wpdb->insert_id;
    }

    /**
     * Dispatch a GET request with query params.
     *
     * @param string $route
     * @param array $query
     * @return WP_REST_Response
     */
    private function dispatch_get(string $route, array $query = []): WP_REST_Response {
        $request = new WP_REST_Request('GET', '/' . APT_API_NAMESPACE . $route);
        foreach ($query as $key => $value) {
            $request->set_param($key, $value);
        }
        return $this->server->dispatch($request);
    }

    /**
     * Dispatch a DELETE request with query params.
     *
     * @param string $route
     * @param array $query
     * @return WP_REST_Response
     */
    private function dispatch_delete(string $route, array $query = []): WP_REST_Response {
        $request = new WP_REST_Request('DELETE', '/' . APT_API_NAMESPACE . $route);
        foreach ($query as $key => $value) {
            $request->set_param($key, $value);
        }
        return $this->server->dispatch($request);
    }

    /**
     * Dispatch a PUT request with a JSON body.
     *
     * @param string $route
     * @param array $body
     * @return WP_REST_Response
     */
    private function dispatch_put(string $route, array $body): WP_REST_Response {
        $request = new WP_REST_Request('PUT', '/' . APT_API_NAMESPACE . $route);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
        return $this->server->dispatch($request);
    }

    /**
     * Dispatch a POST request with a JSON body.
     *
     * @param string $route
     * @param array $body
     * @return WP_REST_Response
     */
    private function dispatch_post_json(string $route, array $body): WP_REST_Response {
        $request = new WP_REST_Request('POST', '/' . APT_API_NAMESPACE . $route);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
        return $this->server->dispatch($request);
    }

    /**
     * Dispatch a POST /products request for the given ASIN/region.
     *
     * @param string $asin
     * @param string $region
     * @return WP_REST_Response
     */
    private function create_product_request(string $asin, string $region): WP_REST_Response {
        $request = new WP_REST_Request('POST', '/' . APT_API_NAMESPACE . '/products');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'asin' => $asin,
            'region' => $region,
        ]));

        return $this->server->dispatch($request);
    }

    /**
     * Dispatch a POST /products/reactivate request for the given ASIN/region.
     *
     * @param string $asin
     * @param string $region
     * @return WP_REST_Response
     */
    private function reactivate_product_request(string $asin, string $region): WP_REST_Response {
        $request = new WP_REST_Request('POST', '/' . APT_API_NAMESPACE . '/products/reactivate');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'asin' => $asin,
            'region' => $region,
        ]));

        return $this->server->dispatch($request);
    }

    /**
     * A canned success response shaped like a real Creators API getItems
     * result.
     *
     * @param string $asin
     * @return array
     */
    private function canned_success_body(string $asin): array {
        return [
            'itemsResult' => [
                'items' => [
                    [
                        'asin' => $asin,
                        'itemInfo' => [
                            'title' => ['displayValue' => 'Component Test Product'],
                        ],
                        'images' => [
                            'primary' => [
                                'large' => ['url' => 'https://example.com/image.jpg', 'height' => 500, 'width' => 500],
                            ],
                        ],
                        'offersV2' => [
                            'listings' => [
                                [
                                    'price' => ['money' => ['amount' => 19.99]],
                                    'availability' => ['type' => 'IN_STOCK', 'message' => 'In stock'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * A canned success response covering multiple ASINs in one getItems
     * batch - what Product_Service::bulk_refresh() actually receives per
     * region (it groups products by region, then batches up to 10 ASINs
     * per real getItems call).
     *
     * @param string[] $asins
     * @return array
     */
    private function canned_multi_item_body(array $asins): array {
        $items = [];
        foreach ($asins as $asin) {
            $items[] = $this->canned_success_body($asin)['itemsResult']['items'][0];
        }
        return ['itemsResult' => ['items' => $items]];
    }

    public function test_create_product_succeeds_with_canned_amazon_response() {
        $asin = 'B0SUCCESS1';
        apt_test_queue_creators_api_response(200, $this->canned_success_body($asin));

        global $wpdb;
        $response = $this->create_product_request($asin, 'UK');
        $data = $response->get_data();

        $this->assertSame(201, $response->get_status());
        $this->assertSame($asin, $data['asin']);
        $this->assertSame('UK', $data['region']);
        $this->assertSame('Component Test Product', $data['facts']['title'] ?? null);
        $this->assertNotEmpty($data['images']);
        $this->assertTrue($data['is_active']);

        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apt_products WHERE asin = %s AND region = %s",
            $asin,
            'UK'
        ));
        $this->assertNotNull($product, 'Expected the created product to be persisted in the database.');

        $price_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apt_price_history WHERE product_id = %d",
            $product->id
        ));
        $this->assertNotNull($price_record, 'Expected an initial price history record to be created.');
        $this->assertEquals(19.99, $price_record->current_price);
    }

    public function test_create_product_returns_asin_not_found_for_amazon_not_found_error() {
        $asin = 'B0NOTFOUND';
        // Creators API has no per-item "not found" error - an unmatched ASIN
        // is simply absent from itemsResult.items in an otherwise-200
        // response (see Amazon_Creators_API::request()'s docblock).
        apt_test_queue_creators_api_response(200, ['itemsResult' => ['items' => []]]);

        $response = $this->create_product_request($asin, 'UK');

        $this->assertSame(400, $response->get_status());
        $this->assertSame('ASIN_NOT_FOUND', $response->as_error()->get_error_code());
    }

    public function test_create_product_returns_amazon_api_error_for_malformed_response() {
        $asin = 'B0MALFORM1';
        // Well-formed JSON, but missing the itemsResult.items shape entirely -
        // Amazon_Creators_API::get_items() treats this as a distinct,
        // genuine parse failure (sets last_error) rather than the
        // not-found case above (which leaves last_error null).
        apt_test_queue_creators_api_response(200, ['UnexpectedShape' => true]);

        $response = $this->create_product_request($asin, 'UK');

        $this->assertSame(502, $response->get_status());
        $this->assertSame('AMAZON_API_ERROR', $response->as_error()->get_error_code());
    }

    public function test_create_product_returns_amazon_api_error_on_timeout() {
        $asin = 'B0TIMEOUT1';
        apt_test_queue_creators_api_error('http_request_failed', 'Operation timed out after 30001 milliseconds.');

        $response = $this->create_product_request($asin, 'UK');

        $this->assertSame(502, $response->get_status());
        $this->assertSame('AMAZON_API_ERROR', $response->as_error()->get_error_code());
    }

    public function test_create_product_conflict_includes_title_and_active_status_for_active_product() {
        $asin = 'B0EXISTING';
        apt_test_queue_creators_api_response(200, $this->canned_success_body($asin));
        $this->create_product_request($asin, 'UK');

        // No second canned response queued - the conflict check must short-circuit
        // before ever calling Amazon again.
        $response = $this->create_product_request($asin, 'UK');
        $error = $response->as_error();

        $this->assertSame(409, $response->get_status());
        $this->assertSame('ALREADY_EXISTS', $error->get_error_code());
        $data = $error->get_error_data();
        $this->assertTrue($data['details']['is_active']);
        $this->assertSame('Component Test Product', $data['details']['title']);
    }

    public function test_create_product_conflict_includes_title_and_active_status_for_inactive_product() {
        global $wpdb;

        $asin = 'B0INACTIVE';
        apt_test_queue_creators_api_response(200, $this->canned_success_body($asin));
        $this->create_product_request($asin, 'UK');

        $wpdb->update(
            $wpdb->prefix . 'apt_products',
            ['is_active' => 0],
            ['asin' => $asin, 'region' => 'UK']
        );

        $response = $this->create_product_request($asin, 'UK');
        $data = $response->as_error()->get_error_data();

        $this->assertSame(409, $response->get_status());
        $this->assertFalse($data['details']['is_active']);
        $this->assertSame('Component Test Product', $data['details']['title']);
    }

    public function test_reactivate_product_succeeds_and_preserves_original_row() {
        global $wpdb;

        $asin = 'B0REACTIV1';
        apt_test_queue_creators_api_response(200, $this->canned_success_body($asin));
        $create_response = $this->create_product_request($asin, 'UK');
        $original_id = $create_response->get_data()['id'];
        $original_created_at = $create_response->get_data()['created_at'];

        $wpdb->update(
            $wpdb->prefix . 'apt_products',
            ['is_active' => 0],
            ['id' => $original_id]
        );

        $reactivated_body = $this->canned_success_body($asin);
        $reactivated_body['itemsResult']['items'][0]['offersV2']['listings'][0]['price']['money']['amount'] = 24.99;
        apt_test_queue_creators_api_response(200, $reactivated_body);

        $response = $this->reactivate_product_request($asin, 'UK');
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['is_active']);
        $this->assertSame($original_id, $data['id'], 'Reactivating should update the existing row, not create a new one.');
        $this->assertSame($original_created_at, $data['created_at'], 'created_at should be untouched by reactivation.');

        $price_records = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apt_price_history WHERE product_id = %d ORDER BY id ASC",
            $original_id
        ));
        $this->assertCount(2, $price_records, 'Expected the original creation record plus a new one from reactivation.');
        $this->assertEquals(24.99, $price_records[1]->current_price);
    }

    public function test_reactivate_returns_not_found_when_no_existing_product() {
        $response = $this->reactivate_product_request('B0NEVERADD', 'UK');

        $this->assertSame(404, $response->get_status());
        $this->assertSame('NOT_FOUND', $response->as_error()->get_error_code());
    }

    public function test_reactivate_returns_forbidden_when_blacklisted() {
        global $wpdb;

        $asin = 'B0BLACKLST';
        apt_test_queue_creators_api_response(200, $this->canned_success_body($asin));
        $this->create_product_request($asin, 'UK');
        $wpdb->update($wpdb->prefix . 'apt_products', ['is_active' => 0], ['asin' => $asin, 'region' => 'UK']);

        $wpdb->insert($wpdb->prefix . 'apt_blacklist', [
            'asin' => $asin,
            'region' => 'UK',
            'reason' => 'Component test blacklist',
            'created_at' => current_time('mysql', true),
            'created_by' => $this->created_user_id,
        ]);

        $response = $this->reactivate_product_request($asin, 'UK');

        $this->assertSame(403, $response->get_status());
        $this->assertSame('BLACKLISTED', $response->as_error()->get_error_code());

        $wpdb->delete($wpdb->prefix . 'apt_blacklist', ['asin' => $asin, 'region' => 'UK']);
    }

    // ========== GET /products (list/filter/sort) ==========

    public function test_get_items_defaults_to_active_products_only() {
        $this->insert_product(['asin' => 'B0ACTIVETS', 'is_active' => 1]);
        $this->insert_product(['asin' => 'B0INACTVTS', 'is_active' => 0]);

        $response = $this->dispatch_get('/products');
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertCount(1, $data['data']);
        $this->assertSame('B0ACTIVETS', $data['data'][0]['asin']);
    }

    public function test_get_items_can_include_inactive_products_explicitly() {
        $this->insert_product(['asin' => 'B0ACTIVETS', 'is_active' => 1]);
        $this->insert_product(['asin' => 'B0INACTVTS', 'is_active' => 0]);

        $response = $this->dispatch_get('/products', ['is_active' => 'false']);
        $data = $response->get_data();

        $this->assertCount(1, $data['data']);
        $this->assertSame('B0INACTVTS', $data['data'][0]['asin']);
    }

    public function test_get_items_filters_by_single_region() {
        $this->insert_product(['asin' => 'B0REGIONUK', 'region' => 'UK']);
        $this->insert_product(['asin' => 'B0REGIONDE', 'region' => 'DE']);

        $response = $this->dispatch_get('/products', ['region' => 'DE']);
        $data = $response->get_data();

        $this->assertCount(1, $data['data']);
        $this->assertSame('DE', $data['data'][0]['region']);
    }

    public function test_get_items_region_takes_precedence_over_regions_when_both_given() {
        // Real branch in the controller: `if ($regions && !$region)` - the
        // multi-region filter is only consulted when no single `region` was
        // also supplied.
        $this->insert_product(['asin' => 'B0REGIONUK', 'region' => 'UK']);
        $this->insert_product(['asin' => 'B0REGIONDE', 'region' => 'DE']);
        $this->insert_product(['asin' => 'B0REGIONFR', 'region' => 'FR']);

        $response = $this->dispatch_get('/products', ['region' => 'UK', 'regions' => 'DE,FR']);
        $data = $response->get_data();

        $this->assertCount(1, $data['data']);
        $this->assertSame('UK', $data['data'][0]['region']);
    }

    public function test_get_items_filters_by_multiple_regions() {
        $this->insert_product(['asin' => 'B0REGIONUK', 'region' => 'UK']);
        $this->insert_product(['asin' => 'B0REGIONDE', 'region' => 'DE']);
        $this->insert_product(['asin' => 'B0REGIONFR', 'region' => 'FR']);

        $response = $this->dispatch_get('/products', ['regions' => 'DE,FR']);
        $data = $response->get_data();

        $regions = array_column($data['data'], 'region');
        sort($regions);
        $this->assertSame(['DE', 'FR'], $regions);
    }

    public function test_get_items_filters_by_custom_category() {
        $this->insert_product(['asin' => 'B0CATELECT', 'custom_category' => 'Electronics']);
        $this->insert_product(['asin' => 'B0CATBOOKS', 'custom_category' => 'Books']);

        $response = $this->dispatch_get('/products', ['custom_category' => 'Books']);
        $data = $response->get_data();

        $this->assertCount(1, $data['data']);
        $this->assertSame('B0CATBOOKS', $data['data'][0]['asin']);
    }

    public function test_get_items_searches_title_from_facts_json_case_insensitively() {
        // Regression test: JSON_EXTRACT()/JSON_UNQUOTE() return utf8mb4_bin
        // (case-sensitive) in MySQL/MariaDB regardless of the source column's
        // collation, so a naive LIKE against the extracted JSON value would
        // silently only match exact case - "wireless" would never find
        // "Wireless Mouse". Products_Controller::get_items() now wraps both
        // sides in LOWER() specifically to guard against this.
        $this->insert_product(['asin' => 'B0WIRELESS', 'facts' => wp_json_encode(['title' => 'Wireless Mouse'])]);
        $this->insert_product(['asin' => 'B0KEYBOARD', 'facts' => wp_json_encode(['title' => 'Mechanical Keyboard'])]);

        $response = $this->dispatch_get('/products', ['search' => 'wireless']);
        $data = $response->get_data();

        $this->assertCount(1, $data['data']);
        $this->assertSame('B0WIRELESS', $data['data'][0]['asin']);
    }

    public function test_get_items_sorts_by_current_price_via_joined_latest_price() {
        // Exercises the special-cased sort_column mapping for 'current_price'
        // (ph.current_price from the joined latest-price subquery) rather
        // than a plain products-table column.
        $cheap_id = $this->insert_product(['asin' => 'B0CHEAPITM']);
        $this->insert_price_history($cheap_id, ['current_price' => 5.00]);

        $expensive_id = $this->insert_product(['asin' => 'B0EXPENSIV']);
        $this->insert_price_history($expensive_id, ['current_price' => 50.00]);

        $response = $this->dispatch_get('/products', ['sort_by' => 'current_price', 'sort_order' => 'asc']);
        $data = $response->get_data();

        $this->assertSame(['B0CHEAPITM', 'B0EXPENSIV'], array_column($data['data'], 'asin'));
    }

    public function test_get_items_uses_latest_price_when_product_has_price_history() {
        $product_id = $this->insert_product(['asin' => 'B0PRICEHIS']);
        $this->insert_price_history($product_id, [
            'current_price' => 10.00,
            'recorded_at' => gmdate('Y-m-d H:i:s', strtotime('-1 day')),
        ]);
        $this->insert_price_history($product_id, [
            'current_price' => 8.00,
            'recorded_at' => current_time('mysql', true),
        ]);

        $response = $this->dispatch_get('/products', ['search' => 'test']);
        $data = $response->get_data();

        $item = current(array_filter($data['data'], fn($p) => $p['asin'] === 'B0PRICEHIS'));
        $this->assertNotFalse($item);
        $this->assertSame(8.00, $item['current_price'], 'Should reflect the most recently recorded price, not the first one.');
    }

    public function test_get_items_pagination_meta_reflects_total_and_page() {
        for ($i = 0; $i < 3; $i++) {
            $this->insert_product(['asin' => 'B0PAGE' . $i . 'TST']);
        }

        $response = $this->dispatch_get('/products', ['per_page' => 2, 'page' => 2]);
        $data = $response->get_data();

        $this->assertCount(1, $data['data'], 'Second page of a 3-item set at 2 per page should have 1 remaining item.');
        $this->assertSame(3, $data['meta']['pagination']['total_items']);
        $this->assertSame(2, $data['meta']['pagination']['total_pages']);
    }

    // ========== GET /products/{id} ==========

    public function test_get_item_not_found() {
        $response = $this->dispatch_get('/products/999999');

        $this->assertSame(404, $response->get_status());
    }

    public function test_get_item_returns_full_product_shape() {
        $product_id = $this->insert_product([
            'asin' => 'B0GETITEM1',
            'custom_category' => 'Electronics',
            'facts' => wp_json_encode(['title' => 'A Product']),
        ]);

        $response = $this->dispatch_get("/products/{$product_id}");
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('B0GETITEM1', $data['asin']);
        $this->assertSame('Electronics', $data['custom_category']);
        $this->assertSame('A Product', $data['facts']['title']);
    }

    // ========== GET /products/by-asin/{asin}(/{region}) ==========

    public function test_get_by_asin_returns_all_regions_for_that_asin() {
        $this->insert_product(['asin' => 'B0MULTIREG', 'region' => 'UK']);
        $this->insert_product(['asin' => 'B0MULTIREG', 'region' => 'DE']);

        $response = $this->dispatch_get('/products/by-asin/B0MULTIREG');
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertCount(2, $data);
        $regions = array_column($data, 'region');
        sort($regions);
        $this->assertSame(['DE', 'UK'], $regions);
    }

    public function test_get_by_asin_excludes_inactive_products() {
        $this->insert_product(['asin' => 'B0INACTBYA', 'is_active' => 0]);

        $response = $this->dispatch_get('/products/by-asin/B0INACTBYA');

        $this->assertSame(404, $response->get_status());
    }

    public function test_get_by_asin_not_found() {
        $response = $this->dispatch_get('/products/by-asin/B0NEVERSAW');

        $this->assertSame(404, $response->get_status());
    }

    public function test_get_by_asin_region_returns_single_matching_product() {
        $this->insert_product(['asin' => 'B0MULTIREG', 'region' => 'UK']);
        $this->insert_product(['asin' => 'B0MULTIREG', 'region' => 'DE']);

        $response = $this->dispatch_get('/products/by-asin/B0MULTIREG/DE');
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('DE', $data['region']);
    }

    public function test_get_by_asin_region_not_found() {
        $response = $this->dispatch_get('/products/by-asin/B0NEVERSAW/UK');

        $this->assertSame(404, $response->get_status());
    }

    // ========== DELETE /products/{id} ==========

    public function test_delete_item_not_found() {
        $this->login_as_admin();

        $response = $this->dispatch_delete('/products/999999');

        $this->assertSame(404, $response->get_status());
    }

    public function test_delete_item_forbidden_for_non_admin() {
        // setUp() already authenticates as a subscriber by default.
        $product_id = $this->insert_product(['asin' => 'B0DELNADMN']);

        $response = $this->dispatch_delete("/products/{$product_id}");

        $this->assertSame(403, $response->get_status());
    }

    public function test_delete_item_soft_deletes_by_default() {
        global $wpdb;

        $product_id = $this->insert_product(['asin' => 'B0SOFTDEL1', 'is_active' => 1]);
        $this->insert_price_history($product_id);
        $this->login_as_admin();

        $response = $this->dispatch_delete("/products/{$product_id}");

        $this->assertSame(204, $response->get_status());

        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apt_products WHERE id = %d",
            $product_id
        ));
        $this->assertNotNull($product, 'Soft delete must not remove the product row.');
        $this->assertEquals(0, $product->is_active);

        $price_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}apt_price_history WHERE product_id = %d",
            $product_id
        ));
        $this->assertEquals(1, $price_count, 'Soft delete must not remove price history.');
    }

    public function test_delete_item_force_hard_deletes_product_and_price_history() {
        global $wpdb;

        $product_id = $this->insert_product(['asin' => 'B0FORCEDEL', 'is_active' => 1]);
        $this->insert_price_history($product_id);
        $this->login_as_admin();

        $response = $this->dispatch_delete("/products/{$product_id}", ['force' => 'true']);

        $this->assertSame(204, $response->get_status());

        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apt_products WHERE id = %d",
            $product_id
        ));
        $this->assertNull($product, 'force=true must actually remove the product row.');

        $price_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}apt_price_history WHERE product_id = %d",
            $product_id
        ));
        $this->assertEquals(0, $price_count, 'force=true must cascade-remove price history (no FK cascade exists at the DB level).');
    }

    // ========== PUT /products/{id}/category ==========

    public function test_update_category_not_found() {
        $this->login_as_admin();

        $response = $this->dispatch_put('/products/999999/category', ['custom_category' => 'Electronics']);

        $this->assertSame(404, $response->get_status());
    }

    public function test_update_category_forbidden_for_non_admin() {
        // setUp() already authenticates as a subscriber by default.
        $product_id = $this->insert_product(['asin' => 'B0CATNADMN']);

        $response = $this->dispatch_put("/products/{$product_id}/category", ['custom_category' => 'Electronics']);

        $this->assertSame(403, $response->get_status());
    }

    public function test_update_category_sets_new_category() {
        $product_id = $this->insert_product(['asin' => 'B0CATUPDT1', 'custom_category' => null]);
        $this->login_as_admin();

        $response = $this->dispatch_put("/products/{$product_id}/category", ['custom_category' => 'Home & Garden']);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('Home & Garden', $data['custom_category']);
    }

    public function test_update_category_truncates_overly_long_value() {
        // Real Validation::validate_category() behavior: truncate to 255
        // characters rather than reject outright.
        $product_id = $this->insert_product(['asin' => 'B0CATLONG1']);
        $long_category = str_repeat('a', 300);
        $this->login_as_admin();

        $response = $this->dispatch_put("/products/{$product_id}/category", ['custom_category' => $long_category]);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(255, strlen($data['custom_category']));
    }

    // ========== GET /products/{id}/prices (+ by-asin variant, + aggregation) ==========

    public function test_get_prices_not_found() {
        $response = $this->dispatch_get('/products/999999/prices');

        $this->assertSame(404, $response->get_status());
    }

    public function test_get_prices_returns_history_ordered_newest_first_by_default() {
        $product_id = $this->insert_product(['asin' => 'B0PRICEORD']);
        $this->insert_price_history($product_id, ['current_price' => 10.00, 'recorded_at' => '2026-01-01 00:00:00']);
        $this->insert_price_history($product_id, ['current_price' => 20.00, 'recorded_at' => '2026-01-03 00:00:00']);
        $this->insert_price_history($product_id, ['current_price' => 15.00, 'recorded_at' => '2026-01-02 00:00:00']);

        $response = $this->dispatch_get("/products/{$product_id}/prices");
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame([20.00, 15.00, 10.00], array_column($data['data'], 'current_price'));
        $this->assertSame($product_id, $data['product']['id']);
        $this->assertSame(3, $data['meta']['pagination']['total_items']);
    }

    public function test_get_prices_honors_ascending_sort_order() {
        $product_id = $this->insert_product(['asin' => 'B0PRICEASC']);
        $this->insert_price_history($product_id, ['current_price' => 10.00, 'recorded_at' => '2026-01-01 00:00:00']);
        $this->insert_price_history($product_id, ['current_price' => 20.00, 'recorded_at' => '2026-01-02 00:00:00']);

        $response = $this->dispatch_get("/products/{$product_id}/prices", ['sort_order' => 'asc']);
        $data = $response->get_data();

        $this->assertSame([10.00, 20.00], array_column($data['data'], 'current_price'));
    }

    public function test_get_prices_filters_by_date_range() {
        $product_id = $this->insert_product(['asin' => 'B0PRICEDAT']);
        $this->insert_price_history($product_id, ['current_price' => 10.00, 'recorded_at' => '2026-01-01 00:00:00']);
        $this->insert_price_history($product_id, ['current_price' => 20.00, 'recorded_at' => '2026-02-15 00:00:00']);
        $this->insert_price_history($product_id, ['current_price' => 30.00, 'recorded_at' => '2026-03-30 00:00:00']);

        $response = $this->dispatch_get("/products/{$product_id}/prices", [
            'from' => '2026-02-01',
            'to' => '2026-03-01',
        ]);
        $data = $response->get_data();

        $this->assertSame([20.00], array_column($data['data'], 'current_price'));
    }

    public function test_get_prices_ignores_unparseable_date_filters() {
        // Validation::validate_datetime() returns null for anything
        // strtotime() can't parse, and the controller only adds a WHERE
        // clause when that succeeds - an invalid `from`/`to` should be
        // silently ignored rather than erroring or excluding everything.
        $product_id = $this->insert_product(['asin' => 'B0PRICEBAD']);
        $this->insert_price_history($product_id, ['current_price' => 10.00]);

        $response = $this->dispatch_get("/products/{$product_id}/prices", ['from' => 'not-a-date']);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertCount(1, $data['data']);
    }

    public function test_get_prices_includes_currency_and_title() {
        $product_id = $this->insert_product([
            'asin' => 'B0PRICEMET',
            'region' => 'DE',
            'facts' => wp_json_encode(['title' => 'Metadata Product']),
        ]);
        $this->insert_price_history($product_id);

        $response = $this->dispatch_get("/products/{$product_id}/prices");
        $data = $response->get_data();

        $this->assertSame('EUR', $data['currency']);
        $this->assertSame('Metadata Product', $data['product']['title']);
    }

    public function test_get_prices_without_aggregate_param_omits_aggregations_key() {
        $product_id = $this->insert_product(['asin' => 'B0NOAGGRE1']);
        $this->insert_price_history($product_id);

        $response = $this->dispatch_get("/products/{$product_id}/prices");
        $data = $response->get_data();

        $this->assertArrayNotHasKey('aggregations', $data);
    }

    public function test_get_prices_daily_aggregation_computes_min_max_avg_per_day() {
        $product_id = $this->insert_product(['asin' => 'B0AGGDAILY']);
        // Two records on day 1 (avg should be 15), one on day 2.
        $this->insert_price_history($product_id, ['current_price' => 10.00, 'recorded_at' => '2026-01-01 08:00:00']);
        $this->insert_price_history($product_id, ['current_price' => 20.00, 'recorded_at' => '2026-01-01 20:00:00']);
        $this->insert_price_history($product_id, ['current_price' => 30.00, 'recorded_at' => '2026-01-02 08:00:00']);

        $response = $this->dispatch_get("/products/{$product_id}/prices", ['aggregate' => 'daily']);
        $data = $response->get_data();

        $this->assertArrayHasKey('aggregations', $data);
        $this->assertCount(2, $data['aggregations']);

        $day1 = $data['aggregations'][0];
        $this->assertSame(10.00, $day1['min_price']);
        $this->assertSame(20.00, $day1['max_price']);
        $this->assertSame(15.00, $day1['avg_price']);
        $this->assertSame(2, $day1['record_count']);

        $day2 = $data['aggregations'][1];
        $this->assertSame(30.00, $day2['min_price']);
        $this->assertSame(1, $day2['record_count']);
    }

    public function test_get_prices_by_asin_region_resolves_product_and_returns_same_shape_as_get_prices() {
        $product_id = $this->insert_product(['asin' => 'B0ASINPRIC', 'region' => 'UK']);
        $this->insert_price_history($product_id, ['current_price' => 42.00]);

        $response = $this->dispatch_get('/products/by-asin/B0ASINPRIC/UK/prices');
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame($product_id, $data['product']['id']);
        $this->assertSame([42.00], array_column($data['data'], 'current_price'));
    }

    public function test_get_prices_by_asin_region_not_found() {
        $response = $this->dispatch_get('/products/by-asin/B0NEVERSAW/UK/prices');

        $this->assertSame(404, $response->get_status());
    }

    // ========== POST /products/bulk ==========

    public function test_bulk_create_requires_products_array() {
        $response = $this->dispatch_post_json('/products/bulk', []);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('VALIDATION_ERROR', $response->as_error()->get_error_code());
    }

    public function test_bulk_create_rejects_more_than_100_products() {
        $products = array_fill(0, 101, ['asin' => 'B0BULKTOO1', 'region' => 'UK']);

        $response = $this->dispatch_post_json('/products/bulk', ['products' => $products]);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('VALIDATION_ERROR', $response->as_error()->get_error_code());
    }

    public function test_bulk_create_skips_duplicate_asin_region_pairs_within_the_request() {
        apt_test_queue_creators_api_response(200, $this->canned_success_body('B0BULKDUP1'));

        $response = $this->dispatch_post_json('/products/bulk', [
            'products' => [
                ['asin' => 'b0bulkdup1', 'region' => 'uk'],
                ['asin' => 'B0BULKDUP1', 'region' => 'UK'],
            ],
        ]);
        $data = $response->get_data();

        $this->assertSame(1, $data['success_count']);
        $this->assertSame(1, $data['failure_count']);
        $duplicate = current(array_filter($data['results'], fn($r) => !$r['success']));
        $this->assertSame('DUPLICATE_IN_REQUEST', $duplicate['error']['code']);
    }

    public function test_bulk_create_reports_mixed_success_and_failure() {
        apt_test_queue_creators_api_response(200, $this->canned_success_body('B0BULKOK01'));
        // Creators API has no per-item "not found" error - an unmatched
        // ASIN is simply absent from itemsResult.items in a 200 response.
        apt_test_queue_creators_api_response(200, ['itemsResult' => ['items' => []]]);

        $response = $this->dispatch_post_json('/products/bulk', [
            'products' => [
                ['asin' => 'B0BULKOK01', 'region' => 'UK'],
                ['asin' => 'B0BULKFAIL', 'region' => 'UK'],
            ],
        ]);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(1, $data['success_count']);
        $this->assertSame(1, $data['failure_count']);

        $ok = current(array_filter($data['results'], fn($r) => $r['asin'] === 'B0BULKOK01'));
        $this->assertTrue($ok['success']);

        $failed = current(array_filter($data['results'], fn($r) => $r['asin'] === 'B0BULKFAIL'));
        $this->assertFalse($failed['success']);
        $this->assertSame('ASIN_NOT_FOUND', $failed['error']['code']);
    }

    // ========== Daily creation rate limit (create_item / reactivate / bulk_create) ==========

    public function test_create_item_returns_429_once_daily_limit_reached() {
        update_option('apt_daily_creation_limit', 1);

        apt_test_queue_creators_api_response(200, $this->canned_success_body('B0RATEONE1'));
        $first = $this->create_product_request('B0RATEONE1', 'UK');
        $this->assertSame(201, $first->get_status(), 'First product should succeed under the limit.');

        $second = $this->create_product_request('B0RATETWO1', 'UK');

        $this->assertSame(429, $second->get_status());
        $error_data = $second->as_error()->get_error_data();
        $this->assertSame('RATE_LIMIT_EXCEEDED', $second->as_error()->get_error_code());
        $this->assertSame(1, $error_data['limit']);
        $this->assertSame(1, $error_data['used']);
    }

    public function test_create_item_admin_users_bypass_the_daily_limit() {
        global $wpdb;

        update_option('apt_daily_creation_limit', 1);

        $admin_id = $this->login_as_admin();
        $this->extra_settings_user_ids[] = $admin_id;
        $wpdb->insert($wpdb->prefix . 'apt_user_settings', [
            'user_id' => $admin_id,
            'creators_credential_id' => Encryption::encrypt('test-credential-id'),
            'creators_credential_secret' => Encryption::encrypt('test-credential-secret'),
            'creators_credential_version' => '3.2',
            'partner_tags' => wp_json_encode(['UK' => 'test-partner-tag']),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);

        apt_test_queue_creators_api_response(200, $this->canned_success_body('B0ADMRATE1'));
        $first = $this->create_product_request('B0ADMRATE1', 'UK');
        $this->assertSame(201, $first->get_status());

        apt_test_queue_creators_api_response(200, $this->canned_success_body('B0ADMRATE2'));
        $second = $this->create_product_request('B0ADMRATE2', 'UK');

        $this->assertSame(201, $second->get_status(), 'Admins should not be subject to the daily creation limit.');
    }

    public function test_create_item_includes_rate_limit_headers_reflecting_remaining_quota() {
        update_option('apt_daily_creation_limit', 5);

        apt_test_queue_creators_api_response(200, $this->canned_success_body('B0HEADERS1'));
        $response = $this->create_product_request('B0HEADERS1', 'UK');
        $headers = $response->get_headers();

        $this->assertSame(201, $response->get_status());
        $this->assertSame('5', $headers['X-RateLimit-Limit']);
        $this->assertSame('4', $headers['X-RateLimit-Remaining'], 'One creation used, four of five should remain.');
    }

    // ========== POST /products/{id}/refresh (single refresh) ==========

    public function test_refresh_item_succeeds_and_records_a_new_price() {
        $this->login_as_admin_with_creators_settings();
        $product_id = $this->insert_product(['asin' => 'B0REFRESH1', 'region' => 'UK']);
        $this->insert_price_history($product_id, ['current_price' => 9.99]);

        $updated_body = $this->canned_success_body('B0REFRESH1');
        $updated_body['itemsResult']['items'][0]['offersV2']['listings'][0]['price']['money']['amount'] = 14.99;
        apt_test_queue_creators_api_response(200, $updated_body);

        $response = $this->dispatch_post_json("/products/{$product_id}/refresh", []);

        $this->assertSame(200, $response->get_status());

        // format_product() (this endpoint's response shape) doesn't include
        // current_price at all - that only lives in price_history - so
        // verify the refresh actually happened by querying the DB directly.
        global $wpdb;
        $price_records = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apt_price_history WHERE product_id = %d ORDER BY id ASC",
            $product_id
        ));
        $this->assertCount(2, $price_records, 'Expected the original seed record plus a new one from the refresh.');
        $this->assertEquals(14.99, $price_records[1]->current_price);

        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apt_products WHERE id = %d",
            $product_id
        ));
        $facts = json_decode($product->facts, true);
        $this->assertSame('Component Test Product', $facts['title']);
    }

    public function test_refresh_item_not_found() {
        $this->login_as_admin_with_creators_settings();

        $response = $this->dispatch_post_json('/products/999999/refresh', []);

        $this->assertSame(404, $response->get_status());
        $this->assertSame('NOT_FOUND', $response->as_error()->get_error_code());
    }

    public function test_refresh_item_forbidden_for_non_admin() {
        // setUp() already authenticates as a subscriber by default.
        $product_id = $this->insert_product(['asin' => 'B0REFNADMN', 'region' => 'UK']);

        $response = $this->dispatch_post_json("/products/{$product_id}/refresh", []);

        $this->assertSame(403, $response->get_status());
    }

    public function test_refresh_item_returns_amazon_api_error_and_leaves_existing_price_unchanged() {
        $this->login_as_admin_with_creators_settings();
        $product_id = $this->insert_product(['asin' => 'B0REFFAIL1', 'region' => 'UK']);
        $this->insert_price_history($product_id, ['current_price' => 9.99]);

        // Malformed response (missing itemsResult.items entirely) - Amazon_Creators_API::get_items()
        // sets last_error for this case, distinct from a genuine not-found ASIN.
        apt_test_queue_creators_api_response(200, ['UnexpectedShape' => true]);

        $response = $this->dispatch_post_json("/products/{$product_id}/refresh", []);

        $this->assertSame(502, $response->get_status());
        $this->assertSame('AMAZON_API_ERROR', $response->as_error()->get_error_code());

        global $wpdb;
        $price_records = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apt_price_history WHERE product_id = %d",
            $product_id
        ));
        $this->assertCount(1, $price_records, 'A failed refresh must not insert a new price record.');
        $this->assertEquals(9.99, $price_records[0]->current_price, 'The existing stored price must be left unchanged on refresh failure.');
    }

    public function test_refresh_item_returns_not_configured_when_no_credentials() {
        $this->login_as_admin();
        $product_id = $this->insert_product(['asin' => 'B0REFNOCFG', 'region' => 'UK']);

        $saved_env = apt_test_suppress_credential_env_fallback();
        try {
            $response = $this->dispatch_post_json("/products/{$product_id}/refresh", []);
        } finally {
            apt_test_restore_credential_env_fallback($saved_env);
        }

        $this->assertSame(400, $response->get_status());
        $this->assertSame('NOT_CONFIGURED', $response->as_error()->get_error_code());
    }

    public function test_refresh_item_returns_missing_partner_tag_for_unconfigured_region() {
        // login_as_admin_with_creators_settings() only configures a UK partner tag.
        $this->login_as_admin_with_creators_settings();
        $product_id = $this->insert_product(['asin' => 'B0REFNOTAG', 'region' => 'DE']);

        $response = $this->dispatch_post_json("/products/{$product_id}/refresh", []);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('MISSING_PARTNER_TAG', $response->as_error()->get_error_code());
    }

    // ========== POST /products/refresh (bulk refresh) ==========

    public function test_bulk_refresh_updates_active_products_and_reports_success() {
        $this->login_as_admin_with_creators_settings();
        $id1 = $this->insert_product(['asin' => 'B0BULKREF1', 'region' => 'UK']);
        $id2 = $this->insert_product(['asin' => 'B0BULKREF2', 'region' => 'UK']);

        apt_test_queue_creators_api_response(200, $this->canned_multi_item_body(['B0BULKREF1', 'B0BULKREF2']));

        $response = $this->dispatch_post_json('/products/refresh', []);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(2, $data['success_count']);
        $this->assertSame(0, $data['failure_count']);
        $product_ids = array_column($data['results'], 'product_id');
        sort($product_ids);
        $this->assertSame([$id1, $id2], $product_ids);
        foreach ($data['results'] as $result) {
            $this->assertTrue($result['success']);
        }

        global $wpdb;
        foreach ([$id1, $id2] as $id) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}apt_price_history WHERE product_id = %d",
                $id
            ));
            $this->assertEquals(1, $count, "Expected a new price record for product {$id}.");
        }
    }

    public function test_bulk_refresh_excludes_inactive_products() {
        $this->login_as_admin_with_creators_settings();
        $active_id = $this->insert_product(['asin' => 'B0BULKACTV', 'region' => 'UK', 'is_active' => 1]);
        $inactive_id = $this->insert_product(['asin' => 'B0BULKINAC', 'region' => 'UK', 'is_active' => 0]);

        apt_test_queue_creators_api_response(200, $this->canned_multi_item_body(['B0BULKACTV']));

        $response = $this->dispatch_post_json('/products/refresh', []);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(1, $data['success_count']);
        $this->assertCount(1, $data['results'], 'The inactive product should not appear in the results at all.');
        $this->assertSame($active_id, $data['results'][0]['product_id']);

        global $wpdb;
        $inactive_price_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}apt_price_history WHERE product_id = %d",
            $inactive_id
        ));
        $this->assertEquals(0, $inactive_price_count, 'The inactive product must not be refreshed.');
    }

    public function test_bulk_refresh_filters_by_product_ids() {
        $this->login_as_admin_with_creators_settings();
        $id1 = $this->insert_product(['asin' => 'B0BULKPID1', 'region' => 'UK']);
        $this->insert_product(['asin' => 'B0BULKPID2', 'region' => 'UK']);

        apt_test_queue_creators_api_response(200, $this->canned_multi_item_body(['B0BULKPID1']));

        $response = $this->dispatch_post_json('/products/refresh', ['product_ids' => [$id1]]);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertCount(1, $data['results']);
        $this->assertSame($id1, $data['results'][0]['product_id']);
    }

    public function test_bulk_refresh_reports_mixed_success_and_failure() {
        $this->login_as_admin_with_creators_settings();
        $ok_id = $this->insert_product(['asin' => 'B0BMIXOK01', 'region' => 'UK']);
        $fail_id = $this->insert_product(['asin' => 'B0BMIXFAIL', 'region' => 'UK']);

        // Only B0BMIXOK01 comes back - Creators API silently omits an
        // unmatched ASIN from a 200 response rather than erroring on it.
        apt_test_queue_creators_api_response(200, $this->canned_multi_item_body(['B0BMIXOK01']));

        $response = $this->dispatch_post_json('/products/refresh', []);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(1, $data['success_count']);
        $this->assertSame(1, $data['failure_count']);

        $ok_result = current(array_filter($data['results'], fn($r) => $r['product_id'] === $ok_id));
        $this->assertTrue($ok_result['success']);

        $fail_result = current(array_filter($data['results'], fn($r) => $r['product_id'] === $fail_id));
        $this->assertFalse($fail_result['success']);
        $this->assertSame('Product not found or API error', $fail_result['error']);
    }

    public function test_bulk_refresh_forbidden_for_non_admin() {
        // setUp() already authenticates as a subscriber by default.
        $response = $this->dispatch_post_json('/products/refresh', []);

        $this->assertSame(403, $response->get_status());
    }

    public function test_bulk_refresh_returns_all_failures_when_not_configured() {
        $this->login_as_admin();
        $this->insert_product(['asin' => 'B0BNOCFG01', 'region' => 'UK']);
        $this->insert_product(['asin' => 'B0BNOCFG02', 'region' => 'UK']);

        $saved_env = apt_test_suppress_credential_env_fallback();
        try {
            $response = $this->dispatch_post_json('/products/refresh', []);
        } finally {
            apt_test_restore_credential_env_fallback($saved_env);
        }
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status(), 'Bulk refresh always returns 200, reporting failures per-item inside the body.');
        $this->assertSame(0, $data['success_count']);
        $this->assertSame(2, $data['failure_count']);
        foreach ($data['results'] as $result) {
            $this->assertFalse($result['success']);
            $this->assertSame('Amazon Creators API credentials not configured', $result['error']);
        }
    }
}

<?php
/**
 * Products Controller Component Test
 *
 * Exercises POST /products end-to-end through the real WP REST dispatcher -
 * real routing/permission callbacks, a real authenticated user, real
 * database writes - but with Amazon's PA-API faked via the pre_http_request
 * filter (see pa-api-mock.php). This is the plugin's own logic under test
 * (routing, validation, error mapping, DB row shape, response shape); the
 * canned fixtures let every branch (success, not-found, malformed response,
 * timeout) be hit deterministically, which live Amazon won't reliably
 * reproduce. See tests/integration/test-products-controller.php for the
 * one real-network "does the wire format work" check for this endpoint.
 *
 * @package AmazonPriceTracker\Tests\Component
 */

use APT\Helpers\Encryption;

require_once __DIR__ . '/pa-api-mock.php';

/**
 * Test case for the Products REST controller's create path, against a
 * faked Amazon PA-API.
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
     * Boot a real REST server with the plugin's routes registered, and set
     * up a user with fake (but well-formed) PA-API credentials - the mock
     * intercepts the request before those credentials are ever sent
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
            'access_key' => Encryption::encrypt('test-access-key'),
            'secret_key' => Encryption::encrypt('test-secret-key'),
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

        apt_test_reset_pa_api_responses();

        $wpdb->delete($wpdb->prefix . 'apt_products', ['created_by' => $this->created_user_id]);
        $wpdb->delete($wpdb->prefix . 'apt_user_settings', ['user_id' => $this->created_user_id]);

        // Product_Service's internal COMMIT above leaves autocommit off but
        // no transaction open, so WP_UnitTestCase's own ROLLBACK (called via
        // parent::tearDown() below) would otherwise silently undo these
        // cleanup deletes too. Commit them explicitly first.
        $wpdb->query('COMMIT');

        parent::tearDown();
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
     * A canned success response shaped like a real PA-API GetItems result.
     *
     * @param string $asin
     * @return array
     */
    private function canned_success_body(string $asin): array {
        return [
            'ItemsResult' => [
                'Items' => [
                    [
                        'ASIN' => $asin,
                        'ItemInfo' => [
                            'Title' => ['DisplayValue' => 'Component Test Product'],
                        ],
                        'Images' => [
                            'Primary' => [
                                'Large' => ['URL' => 'https://example.com/image.jpg', 'Height' => 500, 'Width' => 500],
                            ],
                        ],
                        'Offers' => [
                            'Listings' => [
                                [
                                    'Price' => ['Amount' => 19.99],
                                    'Availability' => ['Type' => 'Now', 'Message' => 'In Stock.'],
                                    'DeliveryInfo' => ['IsPrimeEligible' => true],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_create_product_succeeds_with_canned_amazon_response() {
        $asin = 'B0SUCCESS1';
        apt_test_queue_pa_api_response(200, $this->canned_success_body($asin));

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
        apt_test_queue_pa_api_response(404, [
            'Errors' => [
                ['Code' => 'ItemNotAccessible', 'Message' => 'The item you requested was not found.'],
            ],
        ]);

        $response = $this->create_product_request($asin, 'UK');

        $this->assertSame(400, $response->get_status());
        $this->assertSame('ASIN_NOT_FOUND', $response->as_error()->get_error_code());
    }

    public function test_create_product_returns_amazon_api_error_for_malformed_response() {
        $asin = 'B0MALFORM1';
        // Well-formed JSON, but missing the ItemsResult.Items shape entirely.
        apt_test_queue_pa_api_response(200, ['UnexpectedShape' => true]);

        $response = $this->create_product_request($asin, 'UK');

        $this->assertSame(502, $response->get_status());
        $this->assertSame('AMAZON_API_ERROR', $response->as_error()->get_error_code());
    }

    public function test_create_product_returns_amazon_api_error_on_timeout() {
        $asin = 'B0TIMEOUT1';
        apt_test_queue_pa_api_error('http_request_failed', 'Operation timed out after 30001 milliseconds.');

        $response = $this->create_product_request($asin, 'UK');

        $this->assertSame(502, $response->get_status());
        $this->assertSame('AMAZON_API_ERROR', $response->as_error()->get_error_code());
    }

    public function test_create_product_conflict_includes_title_and_active_status_for_active_product() {
        $asin = 'B0EXISTING';
        apt_test_queue_pa_api_response(200, $this->canned_success_body($asin));
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
        apt_test_queue_pa_api_response(200, $this->canned_success_body($asin));
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
        apt_test_queue_pa_api_response(200, $this->canned_success_body($asin));
        $create_response = $this->create_product_request($asin, 'UK');
        $original_id = $create_response->get_data()['id'];
        $original_created_at = $create_response->get_data()['created_at'];

        $wpdb->update(
            $wpdb->prefix . 'apt_products',
            ['is_active' => 0],
            ['id' => $original_id]
        );

        $reactivated_body = $this->canned_success_body($asin);
        $reactivated_body['ItemsResult']['Items'][0]['Offers']['Listings'][0]['Price']['Amount'] = 24.99;
        apt_test_queue_pa_api_response(200, $reactivated_body);

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
        apt_test_queue_pa_api_response(200, $this->canned_success_body($asin));
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
}

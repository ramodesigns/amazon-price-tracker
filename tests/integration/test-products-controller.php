<?php
/**
 * Products Controller Integration Test
 *
 * Exercises POST /products end-to-end through the real WP REST dispatcher:
 * real routing/permission callbacks, a real authenticated user, a real
 * database row insert/read, and a real network call to Amazon's Creators
 * API (Product_Service now builds Amazon_Creators_API exclusively - see
 * Product_Service::fetch_amazon_product_data()).
 *
 * This uses real Creators API credentials to verify the full happy path,
 * so it needs real Associates credentials supplied via environment
 * variables (never hardcoded - they'd otherwise end up committed to git
 * history):
 *
 *   APT_TEST_CREATORS_API_CREDENTIAL_ID
 *   APT_TEST_CREATORS_API_CREDENTIAL_SECRET
 *   APT_TEST_CREATORS_API_VERSION
 *   APT_TEST_CREATORS_API_PARTNER_TAG
 *   APT_TEST_CREATORS_API_REGION      (defaults to UK)
 *   APT_TEST_ASIN                     (shared with the other integration
 *                                       tests, defaults to a known-good UK ASIN)
 *
 * Without them set, this test is skipped rather than failed, since a fresh
 * checkout has no way to supply real Amazon credentials.
 *
 * @package AmazonPriceTracker\Tests\Integration
 */

use APT\Helpers\Encryption;

/**
 * Test case for the Products REST controller.
 */
class Test_Products_Controller extends WP_UnitTestCase {

    /**
     * @var WP_REST_Server
     */
    protected $server;

    /**
     * @var int|null
     */
    protected $created_user_id;

    /**
     * Boot a real REST server with the plugin's routes registered, the
     * same way WordPress core's own REST API test suite does.
     */
    public function setUp(): void {
        parent::setUp();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        $this->server = $wp_rest_server;
        do_action('rest_api_init', $this->server);
    }

    /**
     * Explicitly clean up rows this test creates.
     *
     * Product_Service::create_product() issues its own internal
     * START TRANSACTION/COMMIT around the insert. Since MySQL has no true
     * nested transactions, that COMMIT also commits WP_UnitTestCase's own
     * test-wrapping transaction, so anything created here would otherwise
     * permanently leak into the database instead of being rolled back.
     */
    public function tearDown(): void {
        global $wpdb;

        $asin = getenv('APT_TEST_ASIN') ?: 'B0GWGVC46T';
        $region = getenv('APT_TEST_CREATORS_API_REGION') ?: 'UK';

        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}apt_products WHERE asin = %s AND region = %s",
            $asin,
            $region
        ));

        if ($product) {
            $wpdb->delete($wpdb->prefix . 'apt_price_history', ['product_id' => $product->id]);
            $wpdb->delete($wpdb->prefix . 'apt_products', ['id' => $product->id]);
        }

        if (!empty($this->created_user_id)) {
            $wpdb->delete($wpdb->prefix . 'apt_user_settings', ['user_id' => $this->created_user_id]);
        }

        // Product_Service's internal COMMIT above leaves autocommit off but
        // no transaction open, so WP_UnitTestCase's own ROLLBACK (called via
        // parent::tearDown() below) would otherwise silently undo these
        // cleanup deletes too. Commit them explicitly first.
        $wpdb->query('COMMIT');

        parent::tearDown();
    }

    /**
     * Test that POST /products, given a real (authenticated, JSON) request
     * and real Creators API credentials, creates a product from live Amazon
     * data.
     */
    public function test_create_product_succeeds_with_real_amazon_api() {
        $credential_id = getenv('APT_TEST_CREATORS_API_CREDENTIAL_ID');
        $credential_secret = getenv('APT_TEST_CREATORS_API_CREDENTIAL_SECRET');
        $version = getenv('APT_TEST_CREATORS_API_VERSION');
        $partner_tag = getenv('APT_TEST_CREATORS_API_PARTNER_TAG');
        $region = getenv('APT_TEST_CREATORS_API_REGION') ?: 'UK';
        $asin = getenv('APT_TEST_ASIN') ?: 'B0GWGVC46T';

        if (!$credential_id || !$credential_secret || !$version || !$partner_tag) {
            $this->markTestSkipped(
                'Set APT_TEST_CREATORS_API_CREDENTIAL_ID, APT_TEST_CREATORS_API_CREDENTIAL_SECRET, ' .
                'APT_TEST_CREATORS_API_VERSION and APT_TEST_CREATORS_API_PARTNER_TAG to run this test ' .
                'against the real Creators API.'
            );
        }

        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $this->created_user_id = $user_id;
        wp_set_current_user($user_id);

        $wpdb->insert($wpdb->prefix . 'apt_user_settings', [
            'user_id' => $user_id,
            'creators_credential_id' => Encryption::encrypt($credential_id),
            'creators_credential_secret' => Encryption::encrypt($credential_secret),
            'creators_credential_version' => $version,
            'partner_tags' => wp_json_encode([$region => $partner_tag]),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);

        $request = new WP_REST_Request('POST', '/' . APT_API_NAMESPACE . '/products');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'asin' => $asin,
            'region' => $region,
        ]));

        $response = $this->server->dispatch($request);
        $data = $response->get_data();

        fwrite(STDERR, sprintf(
            "\n[Products Controller integration test] real Amazon Creators API round trip -> HTTP %d\nBody: %s\n",
            $response->get_status(),
            wp_json_encode($data)
        ));

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(201, $response->get_status(), 'Expected the real Amazon Creators API call to succeed.');

        $this->assertSame($asin, $data['asin']);
        $this->assertSame($region, $data['region']);
        $this->assertNotEmpty($data['facts']['title'] ?? null, 'Expected Amazon to return a product title.');
        $this->assertNotEmpty($data['images'], 'Expected Amazon to return at least one product image.');
        $this->assertTrue($data['is_active']);

        // Confirm the row actually landed in the database, not just the response.
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apt_products WHERE asin = %s AND region = %s",
            $asin,
            $region
        ));
        $this->assertNotNull($product, 'Expected the created product to be persisted in the database.');

        // Confirm an initial price history record was recorded alongside it.
        $price_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apt_price_history WHERE product_id = %d",
            $product->id
        ));
        $this->assertNotNull($price_record, 'Expected an initial price history record to be created.');
    }
}

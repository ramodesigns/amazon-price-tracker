<?php
/**
 * Bulk Operations Integration Test
 *
 * POST /products/bulk and POST /products/refresh against the real Amazon
 * Creators API. An important asymmetry decides what earns live testing
 * here: bulk CREATE fans out to one single-ASIN getItems call per item
 * (Products_Controller::bulk_create() loops create_item()), so its wire
 * format is identical to single create - what it adds live is the per-item
 * result mapping over real responses. Bulk REFRESH is the only operation
 * in the plugin that sends a true batch payload (multiple itemIds in one
 * getItems call, via Product_Service::bulk_refresh()'s 10-per-batch
 * chunking) - THAT wire shape is what no mock can prove Amazon accepts,
 * and it's also exactly what WP-Cron's scheduled refresh sends unattended.
 *
 * Chained with @depends; tearDownAfterClass() cleans up once at the end.
 * Credentials via the APT_TEST_CREATORS_API_* env vars (see
 * test-products-controller.php); skipped without them. ASINs via
 * APT_TEST_ASIN_2 / APT_TEST_ASIN_3 (defaults distinct from APT_TEST_ASIN
 * so this file can't collide with the lifecycle/create tests' rows).
 *
 * @package AmazonPriceTracker\Tests\Integration
 */

use APT\Helpers\Encryption;

/**
 * Test case for bulk product operations against live Amazon.
 */
class Test_Bulk_Operations_Integration extends WP_UnitTestCase {

    /**
     * @var WP_REST_Server
     */
    protected $server;

    protected static ?int $user_id = null;
    protected static string $asin_a = '';
    protected static string $asin_b = '';
    protected static string $region = '';

    public function setUp(): void {
        parent::setUp();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        $this->server = $wp_rest_server;
        do_action('rest_api_init', $this->server);

        self::$asin_a = getenv('APT_TEST_ASIN_2') ?: 'B08WCQ4TG7';
        self::$asin_b = getenv('APT_TEST_ASIN_3') ?: 'B019JIACIS';
        self::$region = getenv('APT_TEST_CREATORS_API_REGION') ?: 'UK';

        if (self::$user_id) {
            wp_set_current_user(self::$user_id);
        }
    }

    public static function tearDownAfterClass(): void {
        global $wpdb;

        $region = getenv('APT_TEST_CREATORS_API_REGION') ?: 'UK';
        $asins = [
            getenv('APT_TEST_ASIN_2') ?: 'B08WCQ4TG7',
            getenv('APT_TEST_ASIN_3') ?: 'B019JIACIS',
        ];

        foreach ($asins as $asin) {
            $product = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}apt_products WHERE asin = %s AND region = %s",
                $asin,
                $region
            ));
            if ($product) {
                $wpdb->delete($wpdb->prefix . 'apt_price_history', ['product_id' => $product->id]);
                $wpdb->delete($wpdb->prefix . 'apt_products', ['id' => $product->id]);
            }
        }

        if (self::$user_id) {
            $wpdb->delete($wpdb->prefix . 'apt_user_settings', ['user_id' => self::$user_id]);
        }

        $wpdb->query('COMMIT');

        parent::tearDownAfterClass();
    }

    private function dispatch(string $method, string $route, ?array $body = null): WP_REST_Response {
        $request = new WP_REST_Request($method, '/' . APT_API_NAMESPACE . $route);
        if ($body !== null) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(wp_json_encode($body));
        }

        return $this->server->dispatch($request);
    }

    private function price_record_count(string $asin): int {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}apt_price_history ph
             INNER JOIN {$wpdb->prefix}apt_products p ON p.id = ph.product_id
             WHERE p.asin = %s AND p.region = %s",
            $asin,
            self::$region
        ));
    }

    // ------------------------------------------------------------------
    // 1. Bulk create: per-item mapping over real single-ASIN calls
    // ------------------------------------------------------------------

    public function test_bulk_create_creates_multiple_products(): void {
        global $wpdb;

        $credential_id = getenv('APT_TEST_CREATORS_API_CREDENTIAL_ID');
        $credential_secret = getenv('APT_TEST_CREATORS_API_CREDENTIAL_SECRET');
        $version = getenv('APT_TEST_CREATORS_API_VERSION');
        $partner_tag = getenv('APT_TEST_CREATORS_API_PARTNER_TAG');

        if (!$credential_id || !$credential_secret || !$version || !$partner_tag) {
            $this->markTestSkipped(
                'Set the APT_TEST_CREATORS_API_* env vars to run the bulk operations tests against the real Creators API.'
            );
        }

        // Admin, because the bulk refresh step below is admin-gated.
        self::$user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user(self::$user_id);

        $wpdb->insert($wpdb->prefix . 'apt_user_settings', [
            'user_id' => self::$user_id,
            'creators_credential_id' => Encryption::encrypt($credential_id),
            'creators_credential_secret' => Encryption::encrypt($credential_secret),
            'creators_credential_version' => $version,
            'partner_tags' => wp_json_encode([self::$region => $partner_tag]),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);

        $response = $this->dispatch('POST', '/products/bulk', [
            'products' => [
                ['asin' => self::$asin_a, 'region' => self::$region],
                ['asin' => self::$asin_b, 'region' => self::$region],
            ],
        ]);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(2, $data['success_count'], 'Both real ASINs should create: ' . wp_json_encode($data['results']));
        $this->assertSame(0, $data['failure_count']);

        // Both rows landed and are retrievable through the DB-only read
        // endpoint, each mapped to its own real title.
        foreach ([self::$asin_a, self::$asin_b] as $asin) {
            $got = $this->dispatch('GET', '/products/by-asin/' . $asin . '/' . self::$region);
            $this->assertSame(200, $got->get_status());
            $this->assertSame($asin, $got->get_data()['asin']);
            $this->assertNotEmpty($got->get_data()['facts']['title'], "Bulk item {$asin} must carry its own real title.");
        }
    }

    // ------------------------------------------------------------------
    // 2. Bulk refresh: the real multi-itemIds batch payload
    // ------------------------------------------------------------------

    /**
     * @depends test_bulk_create_creates_multiple_products
     */
    public function test_bulk_refresh_updates_both_products_in_one_real_batch(): void {
        global $wpdb;

        $before_a = $this->price_record_count(self::$asin_a);
        $before_b = $this->price_record_count(self::$asin_b);

        // Both products share a region, so Product_Service::bulk_refresh()
        // sends them to Amazon as ONE getItems call with two itemIds - the
        // only place the plugin's true batch wire format exists.
        $response = $this->dispatch('POST', '/products/refresh', [
            'regions' => [self::$region],
        ]);
        $data = $response->get_data();

        fwrite(STDERR, sprintf(
            "\n[Bulk operations integration test] real batch refresh -> HTTP %d, %d ok / %d failed\n",
            $response->get_status(),
            $data['success_count'] ?? -1,
            $data['failure_count'] ?? -1
        ));

        $this->assertSame(200, $response->get_status());
        $this->assertGreaterThanOrEqual(2, $data['success_count'], 'Both batch items should refresh: ' . wp_json_encode($data['results']));

        // bulk_refresh() runs no transaction of its own - commit so the
        // count checks (and any later steps) see the new rows.
        $wpdb->query('COMMIT');

        $this->assertSame($before_a + 1, $this->price_record_count(self::$asin_a), 'Batch refresh must append a price record to the first item.');
        $this->assertSame($before_b + 1, $this->price_record_count(self::$asin_b), 'Batch refresh must append a price record to the second item.');
    }

    // ------------------------------------------------------------------
    // 3. Bulk create's duplicate handling: zero Amazon spend
    // ------------------------------------------------------------------

    /**
     * @depends test_bulk_create_creates_multiple_products
     */
    public function test_bulk_create_reports_duplicates_without_amazon_calls(): void {
        // Same ASIN twice: the in-request duplicate is rejected before any
        // network call, and the survivor conflicts with the row test 1
        // created - the whole request costs zero Amazon quota.
        $response = $this->dispatch('POST', '/products/bulk', [
            'products' => [
                ['asin' => self::$asin_a, 'region' => self::$region],
                ['asin' => self::$asin_a, 'region' => self::$region],
            ],
        ]);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(0, $data['success_count']);
        $this->assertSame(2, $data['failure_count']);

        $codes = array_column(array_column($data['results'], 'error'), 'code');
        sort($codes);
        $this->assertSame(['ALREADY_EXISTS', 'DUPLICATE_IN_REQUEST'], $codes);
    }
}

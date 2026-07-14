<?php
/**
 * Product Lifecycle Integration Test
 *
 * One chained journey through a tracked product's full life, against the
 * real Amazon Creators API and real database: create -> appears in list ->
 * details retrievable -> initial price history -> refresh appends a record
 * -> soft delete hides but preserves -> reactivate restores (same row,
 * history intact) -> force delete removes everything.
 *
 * The Amazon-touching steps (create, refresh, reactivate) are the reason
 * this runs live; the DB-only endpoints (list, get, by-asin, prices,
 * delete) are exercised as steps *between* them - real-data verification
 * of the read side at zero extra Amazon spend. Steps are chained with
 * @depends, so a failure (or missing credentials) skips the rest instead
 * of cascading into confusing wreckage.
 *
 * State deliberately persists across the chained tests:
 * Product_Service::create_product()'s internal COMMIT leaks past
 * WP_UnitTestCase's per-test rollback (MySQL has no true nested
 * transactions), and the DB-only mutation steps COMMIT explicitly for the
 * same effect. tearDownAfterClass() cleans everything up once, at the end.
 *
 * Credentials via env vars, never hardcoded (see test-products-controller.php
 * for the full list). Without them, every test here is skipped.
 *
 * @package AmazonPriceTracker\Tests\Integration
 */

use APT\Helpers\Encryption;

/**
 * Test case for the full product lifecycle against live Amazon.
 */
class Test_Product_Lifecycle_Integration extends WP_UnitTestCase {

    /**
     * @var WP_REST_Server
     */
    protected $server;

    /**
     * Chain state, shared across the @depends-ordered tests.
     */
    protected static ?int $admin_id = null;
    protected static ?int $product_id = null;
    protected static ?string $created_at = null;
    protected static string $asin = '';
    protected static string $region = '';

    public function setUp(): void {
        parent::setUp();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        $this->server = $wp_rest_server;
        do_action('rest_api_init', $this->server);

        self::$asin = getenv('APT_TEST_ASIN') ?: 'B0GWGVC46T';
        self::$region = getenv('APT_TEST_CREATORS_API_REGION') ?: 'UK';

        if (self::$admin_id) {
            wp_set_current_user(self::$admin_id);
        }
    }

    public static function tearDownAfterClass(): void {
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

        if (self::$admin_id) {
            $wpdb->delete($wpdb->prefix . 'apt_user_settings', ['user_id' => self::$admin_id]);
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

    private function price_record_count(): int {
        $response = $this->dispatch('GET', '/products/' . self::$product_id . '/prices');

        return count($response->get_data()['data']);
    }

    // ------------------------------------------------------------------
    // 1. Create (real Amazon)
    // ------------------------------------------------------------------

    public function test_create_product_with_real_amazon_data(): void {
        global $wpdb;

        $credential_id = getenv('APT_TEST_CREATORS_API_CREDENTIAL_ID');
        $credential_secret = getenv('APT_TEST_CREATORS_API_CREDENTIAL_SECRET');
        $version = getenv('APT_TEST_CREATORS_API_VERSION');
        $partner_tag = getenv('APT_TEST_CREATORS_API_PARTNER_TAG');

        if (!$credential_id || !$credential_secret || !$version || !$partner_tag) {
            $this->markTestSkipped(
                'Set the APT_TEST_CREATORS_API_* env vars to run the lifecycle journey against the real Creators API.'
            );
        }

        // Admin, because the later refresh/delete steps are admin-gated.
        self::$admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user(self::$admin_id);

        $wpdb->insert($wpdb->prefix . 'apt_user_settings', [
            'user_id' => self::$admin_id,
            'creators_credential_id' => Encryption::encrypt($credential_id),
            'creators_credential_secret' => Encryption::encrypt($credential_secret),
            'creators_credential_version' => $version,
            'partner_tags' => wp_json_encode([self::$region => $partner_tag]),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);

        $response = $this->dispatch('POST', '/products', ['asin' => self::$asin, 'region' => self::$region]);
        $data = $response->get_data();

        $this->assertSame(201, $response->get_status(), 'Real Amazon create failed: ' . wp_json_encode($data));
        $this->assertNotEmpty($data['facts']['title'] ?? null);

        self::$product_id = $data['id'];
        self::$created_at = $data['created_at'];
    }

    // ------------------------------------------------------------------
    // 2-4. Read side (DB-only endpoints, real created data)
    // ------------------------------------------------------------------

    /**
     * @depends test_create_product_with_real_amazon_data
     */
    public function test_product_appears_in_the_list(): void {
        $response = $this->dispatch('GET', '/products');
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());

        $listed = array_column($data['data'], null, 'id')[self::$product_id] ?? null;
        $this->assertNotNull($listed, 'Created product missing from GET /products.');
        $this->assertSame(self::$asin, $listed['asin']);
        $this->assertNotEmpty($listed['title'], 'The list summary must carry the real Amazon title.');
        $this->assertNotEmpty($listed['currency']);
    }

    /**
     * @depends test_create_product_with_real_amazon_data
     */
    public function test_product_details_are_retrievable_by_id_and_by_asin(): void {
        $by_id = $this->dispatch('GET', '/products/' . self::$product_id);
        $this->assertSame(200, $by_id->get_status());
        $this->assertNotEmpty($by_id->get_data()['facts']['title']);
        $this->assertNotEmpty($by_id->get_data()['images'], 'Expected real Amazon images to be stored.');

        $by_asin_region = $this->dispatch('GET', '/products/by-asin/' . self::$asin . '/' . self::$region);
        $this->assertSame(200, $by_asin_region->get_status());
        $this->assertSame(self::$product_id, $by_asin_region->get_data()['id'], 'ASIN/region lookup must resolve to the same row.');
    }

    /**
     * @depends test_create_product_with_real_amazon_data
     */
    public function test_price_history_has_the_initial_record(): void {
        $response = $this->dispatch('GET', '/products/' . self::$product_id . '/prices');
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(self::$asin, $data['product']['asin']);
        $this->assertNotEmpty($data['currency']);
        $this->assertCount(1, $data['data'], 'Exactly one price record should exist right after creation.');
    }

    // ------------------------------------------------------------------
    // 5. Refresh (real Amazon)
    // ------------------------------------------------------------------

    /**
     * @depends test_price_history_has_the_initial_record
     */
    public function test_refresh_appends_a_price_record(): void {
        global $wpdb;

        $response = $this->dispatch('POST', '/products/' . self::$product_id . '/refresh');

        $this->assertSame(200, $response->get_status(), 'Real Amazon refresh failed: ' . wp_json_encode($response->get_data()));

        // refresh_product() runs no transaction of its own, so its writes
        // live inside this test's WP transaction - commit them so the later
        // chain steps (running in fresh transactions) can see them.
        $wpdb->query('COMMIT');

        $this->assertSame(2, $this->price_record_count(), 'Refresh must append a second price record.');
    }

    // ------------------------------------------------------------------
    // 6. Soft delete (DB-only)
    // ------------------------------------------------------------------

    /**
     * @depends test_refresh_appends_a_price_record
     */
    public function test_soft_delete_hides_but_preserves_the_product(): void {
        global $wpdb;

        $response = $this->dispatch('DELETE', '/products/' . self::$product_id);
        $this->assertSame(204, $response->get_status());
        $wpdb->query('COMMIT');

        // Hidden from the default (active-only) list...
        $list = $this->dispatch('GET', '/products')->get_data();
        $this->assertArrayNotHasKey(self::$product_id, array_column($list['data'], null, 'id'));

        // ...and from the active-only by-asin lookup...
        $this->assertSame(404, $this->dispatch('GET', '/products/by-asin/' . self::$asin)->get_status());

        // ...but the row and its full history survive.
        $by_id = $this->dispatch('GET', '/products/' . self::$product_id);
        $this->assertSame(200, $by_id->get_status());
        $this->assertFalse($by_id->get_data()['is_active']);
        $this->assertSame(2, $this->price_record_count());
    }

    // ------------------------------------------------------------------
    // 7. Reactivate (real Amazon)
    // ------------------------------------------------------------------

    /**
     * @depends test_soft_delete_hides_but_preserves_the_product
     */
    public function test_reactivate_restores_the_same_row_with_history_intact(): void {
        $response = $this->dispatch('POST', '/products/reactivate', ['asin' => self::$asin, 'region' => self::$region]);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status(), 'Real Amazon reactivate failed: ' . wp_json_encode($data));
        $this->assertSame(self::$product_id, $data['id'], 'Reactivation must revive the existing row, not create a new one.');
        $this->assertTrue($data['is_active']);
        $this->assertSame(self::$created_at, $data['created_at'], 'created_at must survive the delete/reactivate round trip.');

        // Initial + refresh + the fresh record reactivation just fetched.
        $this->assertSame(3, $this->price_record_count());
    }

    // ------------------------------------------------------------------
    // 8. Force delete (DB-only)
    // ------------------------------------------------------------------

    /**
     * @depends test_reactivate_restores_the_same_row_with_history_intact
     */
    public function test_force_delete_removes_the_product_and_its_history(): void {
        global $wpdb;

        // Query params go through set_query_params - a query string embedded
        // in the route would break WP's route matching outright.
        $request = new WP_REST_Request('DELETE', '/' . APT_API_NAMESPACE . '/products/' . self::$product_id);
        $request->set_query_params(['force' => 'true']);
        $response = $this->server->dispatch($request);
        $this->assertSame(204, $response->get_status());
        $wpdb->query('COMMIT');

        $this->assertSame(404, $this->dispatch('GET', '/products/' . self::$product_id)->get_status());

        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}apt_price_history WHERE product_id = %d",
            self::$product_id
        ));
        $this->assertSame(0, $remaining, 'Force delete must cascade to price history (there is no DB-level FK).');
    }
}

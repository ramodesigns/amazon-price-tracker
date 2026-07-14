<?php
/**
 * Settings Journey Integration Test
 *
 * The real user flow, end to end: store credentials through the plugin's
 * own encrypted settings storage (PUT /settings), prove Amazon accepts
 * them (POST /settings/validate), then create a product using the *stored*
 * credentials (POST /products) - not the env-var fallback every other test
 * leans on. This is the only place the full
 * encrypt -> persist -> decrypt -> OAuth -> signed-request chain gets
 * exercised against live Amazon; a regression anywhere in that chain
 * (Encryption key derivation, column handling, from_settings()) surfaces
 * here even when the shortcut paths still pass.
 *
 * validate also makes a separate live GET /health/amazon test redundant -
 * both are thin wrappers over the same Product_Service::test_connection().
 *
 * Credentials via the APT_TEST_CREATORS_API_* env vars; skipped without
 * them. Create step uses APT_TEST_ASIN_2 (default distinct from the other
 * files' ASINs; safe to share with test-bulk-create.php because PHPUnit
 * finishes one class - including its tearDownAfterClass cleanup - before
 * starting the next).
 *
 * @package AmazonPriceTracker\Tests\Integration
 */

/**
 * Test case for the stored-credentials journey against live Amazon.
 */
class Test_Settings_Journey_Integration extends WP_UnitTestCase {

    /**
     * @var WP_REST_Server
     */
    protected $server;

    protected static ?int $user_id = null;
    protected static string $asin = '';
    protected static string $region = '';

    public function setUp(): void {
        parent::setUp();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        $this->server = $wp_rest_server;
        do_action('rest_api_init', $this->server);

        self::$asin = getenv('APT_TEST_ASIN_2') ?: 'B08WCQ4TG7';
        self::$region = getenv('APT_TEST_CREATORS_API_REGION') ?: 'UK';

        if (self::$user_id) {
            wp_set_current_user(self::$user_id);
        }
    }

    public static function tearDownAfterClass(): void {
        global $wpdb;

        $asin = getenv('APT_TEST_ASIN_2') ?: 'B08WCQ4TG7';
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

    // ------------------------------------------------------------------
    // 1. Store real credentials through the API (no Amazon call yet)
    // ------------------------------------------------------------------

    public function test_put_settings_stores_real_credentials_encrypted(): void {
        global $wpdb;

        $credential_id = getenv('APT_TEST_CREATORS_API_CREDENTIAL_ID');
        $credential_secret = getenv('APT_TEST_CREATORS_API_CREDENTIAL_SECRET');
        $version = getenv('APT_TEST_CREATORS_API_VERSION');
        $partner_tag = getenv('APT_TEST_CREATORS_API_PARTNER_TAG');

        if (!$credential_id || !$credential_secret || !$version || !$partner_tag) {
            $this->markTestSkipped(
                'Set the APT_TEST_CREATORS_API_* env vars to run the settings journey against the real Creators API.'
            );
        }

        self::$user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user(self::$user_id);

        $response = $this->dispatch('PUT', '/settings', [
            'creators_credential_id' => $credential_id,
            'creators_credential_secret' => $credential_secret,
            'creators_credential_version' => $version,
            'partner_tags' => [self::$region => $partner_tag],
        ]);

        $this->assertSame(201, $response->get_status(), 'First-time settings setup should 201: ' . wp_json_encode($response->get_data()));

        // The secret must never be echoed back...
        $this->assertStringNotContainsString($credential_secret, wp_json_encode($response->get_data()));

        // ...and must be stored encrypted, not as plaintext.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apt_user_settings WHERE user_id = %d",
            self::$user_id
        ));
        $this->assertNotNull($row);
        $this->assertNotSame($credential_secret, $row->creators_credential_secret);
        $this->assertNotSame($credential_id, $row->creators_credential_id);
        $this->assertSame([self::$region => $partner_tag], json_decode($row->partner_tags, true));

        // The PUT ran inside this test's transaction - persist it for the
        // chained steps (each runs in its own fresh transaction).
        $wpdb->query('COMMIT');
    }

    // ------------------------------------------------------------------
    // 2. Amazon accepts the stored credentials (real OAuth + searchItems)
    // ------------------------------------------------------------------

    /**
     * @depends test_put_settings_stores_real_credentials_encrypted
     */
    public function test_validate_confirms_stored_credentials_against_amazon(): void {
        $response = $this->dispatch('POST', '/settings/validate');
        $data = $response->get_data();

        fwrite(STDERR, sprintf(
            "\n[Settings journey integration test] validate -> HTTP %d: %s\n",
            $response->get_status(),
            wp_json_encode($data)
        ));

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['valid'], 'Amazon rejected credentials that came through the stored-settings path: ' . wp_json_encode($data));
    }

    // ------------------------------------------------------------------
    // 3. A real create using the stored (not env) credentials
    // ------------------------------------------------------------------

    /**
     * @depends test_validate_confirms_stored_credentials_against_amazon
     */
    public function test_create_product_using_stored_credentials(): void {
        // Unset the env credentials for the duration of the dispatch:
        // Product_Service::get_user_settings() falls back to them when a
        // settings row is missing, and this test's entire point is that the
        // STORED row carries the request - a broken storage path must fail
        // here, not silently succeed via the fallback.
        $env_keys = [
            'APT_TEST_CREATORS_API_CREDENTIAL_ID', 'APT_TEST_CREATORS_API_CREDENTIAL_SECRET',
            'APT_TEST_CREATORS_API_VERSION', 'APT_TEST_CREATORS_API_PARTNER_TAG', 'APT_TEST_CREATORS_API_REGION',
        ];
        $previous = [];
        foreach ($env_keys as $key) {
            $previous[$key] = getenv($key);
            putenv($key);
        }

        try {
            $response = $this->dispatch('POST', '/products', ['asin' => self::$asin, 'region' => self::$region]);
        } finally {
            foreach ($previous as $key => $value) {
                putenv($value === false ? $key : "{$key}={$value}");
            }
        }

        $data = $response->get_data();

        $this->assertSame(201, $response->get_status(), 'Create via stored credentials failed: ' . wp_json_encode($data));
        $this->assertSame(self::$asin, $data['asin']);
        $this->assertNotEmpty($data['facts']['title'] ?? null);
    }
}

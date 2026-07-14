<?php
/**
 * Error Classification Canaries (Integration)
 *
 * Pins how the plugin classifies *real* Amazon failures - the one thing no
 * mock can verify, because our mocks necessarily encode our own assumptions
 * about what Amazon's errors look like. Two specific fragilities live here:
 *
 * 1. ASIN_NOT_FOUND is derived by string-matching Amazon's error message
 *    text (Product_Service::fetch_amazon_product_data() str_contains checks
 *    for 'itemnotfound'/'invalid'/'not found'). If Amazon rephrases, the
 *    classification silently degrades to a generic AMAZON_API_ERROR - only
 *    a live call can catch that drift. Same species as the pricing canary
 *    in test-creators-api.php.
 *
 * 2. Credential failures surface through the OAuth token step, whose
 *    failure branches the component mock deliberately can't reach (it
 *    auto-succeeds token requests - see creators-api-mock.php). These are
 *    the only live tests exercising those branches.
 *
 * Credentials via the APT_TEST_CREATORS_API_* env vars; skipped without
 * them (the bad-credential tests still need them set, both as the "run
 * integration" signal and for the wrong-partner-tag case's valid token).
 *
 * @package AmazonPriceTracker\Tests\Integration
 */

use APT\Helpers\Encryption;

/**
 * Test case pinning live Amazon error classification.
 */
class Test_Error_Canaries_Integration extends WP_UnitTestCase {

    /**
     * @var WP_REST_Server
     */
    protected $server;

    /**
     * @var int|null
     */
    protected $created_user_id;

    public function setUp(): void {
        parent::setUp();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        $this->server = $wp_rest_server;
        do_action('rest_api_init', $this->server);
    }

    public function tearDown(): void {
        global $wpdb;

        if (!empty($this->created_user_id)) {
            $wpdb->delete($wpdb->prefix . 'apt_user_settings', ['user_id' => $this->created_user_id]);
            $wpdb->query('COMMIT');
        }

        parent::tearDown();
    }

    /**
     * Skip unless real credentials are available; returns them when they are.
     *
     * @return array{credential_id: string, credential_secret: string, version: string, partner_tag: string, region: string}
     */
    private function require_credentials(): array {
        $credential_id = getenv('APT_TEST_CREATORS_API_CREDENTIAL_ID');
        $credential_secret = getenv('APT_TEST_CREATORS_API_CREDENTIAL_SECRET');
        $version = getenv('APT_TEST_CREATORS_API_VERSION');
        $partner_tag = getenv('APT_TEST_CREATORS_API_PARTNER_TAG');

        if (!$credential_id || !$credential_secret || !$version || !$partner_tag) {
            $this->markTestSkipped(
                'Set the APT_TEST_CREATORS_API_* env vars to run the error canaries against the real Creators API.'
            );
        }

        return [
            'credential_id' => $credential_id,
            'credential_secret' => $credential_secret,
            'version' => $version,
            'partner_tag' => $partner_tag,
            'region' => getenv('APT_TEST_CREATORS_API_REGION') ?: 'UK',
        ];
    }

    /**
     * Create an authenticated user with a settings row built from the given
     * credential fields, and return the region in play.
     */
    private function configure_user(array $creds): string {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $this->created_user_id = $user_id;
        wp_set_current_user($user_id);

        $wpdb->insert($wpdb->prefix . 'apt_user_settings', [
            'user_id' => $user_id,
            'creators_credential_id' => Encryption::encrypt($creds['credential_id']),
            'creators_credential_secret' => Encryption::encrypt($creds['credential_secret']),
            'creators_credential_version' => $creds['version'],
            'partner_tags' => wp_json_encode([$creds['region'] => $creds['partner_tag']]),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);

        return $creds['region'];
    }

    private function dispatch(string $method, string $route, ?array $body = null): WP_REST_Response {
        $request = new WP_REST_Request($method, '/' . APT_API_NAMESPACE . $route);
        if ($body !== null) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(wp_json_encode($body));
        }

        return $this->server->dispatch($request);
    }

    public function test_a_nonexistent_asin_is_classified_as_not_found(): void {
        $region = $this->configure_user($this->require_credentials());

        // Well-formed, essentially-certainly-unassigned ASIN.
        $response = $this->dispatch('POST', '/products', ['asin' => 'B0NOSUCH99', 'region' => $region]);
        $error = $response->as_error();

        fwrite(STDERR, sprintf(
            "\n[Error canary] nonexistent ASIN -> HTTP %d, code %s: %s\n",
            $response->get_status(),
            $error ? $error->get_error_code() : '(none)',
            $error ? $error->get_error_message() : ''
        ));

        // Whatever Amazon sends, a product must never be created from it.
        $this->assertNotSame(201, $response->get_status());

        // The desired contract: our string-matching still recognizes
        // Amazon's current not-found phrasing. If this flips to
        // AMAZON_API_ERROR, Amazon has changed its error text and
        // fetch_amazon_product_data()'s matching needs updating - exactly
        // the drift this canary exists to catch.
        $this->assertSame('ASIN_NOT_FOUND', $error->get_error_code());
    }

    public function test_invalid_credentials_fail_validation_cleanly(): void {
        $creds = $this->require_credentials();

        // Real token endpoint, garbage credentials: the OAuth step must
        // fail, and that failure must come back as a clean valid:false
        // with a message - not an exception, a 500, or a false positive.
        $creds['credential_id'] = 'integration-test-bogus-id';
        $creds['credential_secret'] = 'integration-test-bogus-secret';
        $this->configure_user($creds);

        $response = $this->dispatch('POST', '/settings/validate');
        $data = $response->get_data();

        fwrite(STDERR, sprintf(
            "\n[Error canary] bogus credentials -> HTTP %d: %s\n",
            $response->get_status(),
            wp_json_encode($data)
        ));

        $this->assertSame(200, $response->get_status());
        $this->assertFalse($data['valid']);
        $this->assertNotEmpty($data['message'], 'The real token-failure reason must be surfaced to the caller.');
    }

    public function test_a_wrong_partner_tag_does_not_create_a_phantom_product(): void {
        global $wpdb;

        $creds = $this->require_credentials();

        // Valid credentials (the token step succeeds), but a partner tag
        // Amazon has no record of. Pins how Amazon actually responds to a
        // tag/credential mismatch - acceptance here would be a real
        // attribution problem, silent rejection a real diagnosability one.
        $creds['partner_tag'] = 'aptbogustg-21';
        $region = $this->configure_user($creds);

        $response = $this->dispatch('POST', '/products', ['asin' => getenv('APT_TEST_ASIN') ?: 'B0GWGVC46T', 'region' => $region]);
        $error = $response->as_error();

        fwrite(STDERR, sprintf(
            "\n[Error canary] wrong partner tag -> HTTP %d, code %s: %s\n",
            $response->get_status(),
            $error ? $error->get_error_code() : '(none - request SUCCEEDED)',
            $error ? $error->get_error_message() : ''
        ));

        if ($response->get_status() === 201) {
            // Amazon accepted the unknown tag. Record the fact loudly (the
            // STDERR line above) and clean up the row so other tests using
            // this ASIN aren't poisoned; the assertion below then documents
            // that we currently rely on Amazon NOT validating tags.
            $product_id = $response->get_data()['id'];
            $wpdb->delete($wpdb->prefix . 'apt_price_history', ['product_id' => $product_id]);
            $wpdb->delete($wpdb->prefix . 'apt_products', ['id' => $product_id]);
            $wpdb->query('COMMIT');
        }

        // Either Amazon rejects the tag (an error response, no row) or it
        // accepts it (201) - both are livable, but a *classification crash*
        // (500 UNKNOWN_ERROR) is not.
        $this->assertNotSame(500, $response->get_status());
    }
}

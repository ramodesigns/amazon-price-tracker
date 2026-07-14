<?php
/**
 * Health Controller Component Test
 *
 * Exercises GET /health and GET /health/amazon end-to-end through the real
 * WP REST dispatcher - real routing/permission callbacks, a real
 * authenticated user - but with Amazon's Creators API faked via the
 * pre_http_request filter (see creators-api-mock.php) for the connectivity
 * check.
 *
 * @package AmazonPriceTracker\Tests\Component
 */

use APT\Helpers\Encryption;

require_once __DIR__ . '/creators-api-mock.php';

/**
 * Test case for the Health REST controller.
 */
class Test_Health_Controller_Component extends WP_UnitTestCase {

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

        apt_test_reset_creators_api_responses();

        if ($this->created_user_id) {
            $wpdb->delete($wpdb->prefix . 'apt_user_settings', ['user_id' => $this->created_user_id]);
        }

        parent::tearDown();
    }

    /**
     * @param string $route
     * @return WP_REST_Response
     */
    private function dispatch_get(string $route): WP_REST_Response {
        $request = new WP_REST_Request('GET', '/' . APT_API_NAMESPACE . $route);
        return $this->server->dispatch($request);
    }

    public function test_get_health_is_publicly_accessible_without_authentication() {
        $response = $this->dispatch_get('/health');

        $this->assertSame(200, $response->get_status());
    }

    public function test_get_health_response_shape() {
        $response = $this->dispatch_get('/health');
        $data = $response->get_data();

        $this->assertSame('healthy', $data['status']);
        $this->assertSame(APT_VERSION, $data['version']);
        $this->assertArrayHasKey('wordpress_version', $data);
        $this->assertArrayHasKey('php_version', $data);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function test_get_amazon_health_requires_authentication() {
        $response = $this->dispatch_get('/health/amazon');

        $this->assertSame(401, $response->get_status());
        $this->assertSame('rest_forbidden', $response->as_error()->get_error_code());
    }

    public function test_get_amazon_health_reports_not_configured_without_settings() {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $this->created_user_id = $user_id;
        wp_set_current_user($user_id);

        // Guard against a local .env supplying fallback credentials - this
        // test specifically asserts the *unconfigured* path.
        $saved_env = apt_test_suppress_credential_env_fallback();
        try {
            $response = $this->dispatch_get('/health/amazon');
            $data = $response->get_data();
        } finally {
            apt_test_restore_credential_env_fallback($saved_env);
        }

        $this->assertSame(200, $response->get_status());
        $this->assertSame('not_configured', $data['status']);
    }

    public function test_get_amazon_health_reports_connected_when_amazon_reachable() {
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

        apt_test_queue_creators_api_response(200, [
            'searchResult' => [
                'items' => [
                    ['asin' => 'B0TESTASIN'],
                ],
            ],
        ]);

        $response = $this->dispatch_get('/health/amazon');
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('connected', $data['status']);
    }

    public function test_get_amazon_health_reports_error_when_amazon_unreachable() {
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

        apt_test_queue_creators_api_error('http_request_failed', 'Operation timed out after 30001 milliseconds.');

        $response = $this->dispatch_get('/health/amazon');
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('error', $data['status']);
    }
}

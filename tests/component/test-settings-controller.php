<?php
/**
 * Settings Controller Component Test
 *
 * Exercises GET/PUT /settings, DELETE /settings/partner-tags/{region}, and
 * POST /settings/validate end-to-end through the real WP REST dispatcher -
 * real routing/permission callbacks, real database writes, real
 * encrypt/decrypt round trip - with Amazon's Creators API faked via the
 * pre_http_request filter for the credential validation endpoint.
 *
 * @package AmazonPriceTracker\Tests\Component
 */

use APT\Helpers\Encryption;

require_once __DIR__ . '/creators-api-mock.php';

/**
 * Test case for the Settings REST controller.
 */
class Test_Settings_Controller_Component extends WP_UnitTestCase {

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

    private function authenticate_as_new_user(): int {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $this->created_user_id = $user_id;
        wp_set_current_user($user_id);
        return $user_id;
    }

    private function insert_settings_row(int $user_id, string $credential_id = 'test-credential-id', string $credential_secret = 'test-credential-secret', array $partner_tags = ['UK' => 'uk-tag']): void {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'apt_user_settings', [
            'user_id' => $user_id,
            'creators_credential_id' => Encryption::encrypt($credential_id),
            'creators_credential_secret' => Encryption::encrypt($credential_secret),
            'creators_credential_version' => '3.2',
            'partner_tags' => wp_json_encode($partner_tags),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);
    }

    private function dispatch_get_settings(): WP_REST_Response {
        $request = new WP_REST_Request('GET', '/' . APT_API_NAMESPACE . '/settings');
        return $this->server->dispatch($request);
    }

    private function dispatch_put_settings(array $body): WP_REST_Response {
        $request = new WP_REST_Request('PUT', '/' . APT_API_NAMESPACE . '/settings');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
        return $this->server->dispatch($request);
    }

    private function dispatch_delete_partner_tag(string $region): WP_REST_Response {
        $request = new WP_REST_Request('DELETE', '/' . APT_API_NAMESPACE . '/settings/partner-tags/' . $region);
        return $this->server->dispatch($request);
    }

    private function dispatch_validate_credentials(): WP_REST_Response {
        $request = new WP_REST_Request('POST', '/' . APT_API_NAMESPACE . '/settings/validate');
        return $this->server->dispatch($request);
    }

    // -- GET /settings --

    public function test_get_settings_requires_authentication() {
        $response = $this->dispatch_get_settings();

        $this->assertSame(401, $response->get_status());
    }

    public function test_get_settings_returns_not_found_when_unconfigured() {
        $this->authenticate_as_new_user();

        $response = $this->dispatch_get_settings();

        $this->assertSame(404, $response->get_status());
        $this->assertSame('NOT_FOUND', $response->as_error()->get_error_code());
    }

    public function test_get_settings_returns_masked_creators_credential_and_version_but_never_the_secret() {
        $user_id = $this->authenticate_as_new_user();
        $this->insert_settings_row($user_id, 'amzn1.application-oa2-client.example', 'shh-creators-secret', ['UK' => 'uk-tag']);

        $response = $this->dispatch_get_settings();
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame($user_id, $data['user_id']);
        $this->assertNotSame(
            'amzn1.application-oa2-client.example',
            $data['creators_credential_id'],
            'The raw credential ID must never be exposed in the API response.'
        );
        $this->assertStringContainsString('*', $data['creators_credential_id']);
        $this->assertSame('3.2', $data['creators_credential_version']);
        $this->assertSame(['UK' => 'uk-tag'], $data['partner_tags']);
        $this->assertArrayNotHasKey('creators_credential_secret', $data, 'The credential secret must never be exposed in the API response.');
    }

    // -- PUT /settings (create) --

    public function test_put_settings_creates_new_settings_row() {
        $user_id = $this->authenticate_as_new_user();

        $response = $this->dispatch_put_settings([
            'creators_credential_id' => 'amzn1.application-oa2-client.example',
            'creators_credential_secret' => 'creators-secret',
            'creators_credential_version' => 'v3.2',
            'partner_tags' => ['UK' => 'uk-tag'],
        ]);
        $data = $response->get_data();

        $this->assertSame(201, $response->get_status());
        $this->assertSame($user_id, $data['user_id']);
        $this->assertSame(['UK' => 'uk-tag'], $data['partner_tags']);
        $this->assertSame('3.2', $data['creators_credential_version'], 'A leading "v" should be stripped, same as Amazon_Creators_API does.');

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apt_user_settings WHERE user_id = %d",
            $user_id
        ));
        $this->assertSame('amzn1.application-oa2-client.example', Encryption::decrypt($row->creators_credential_id));
        $this->assertSame('creators-secret', Encryption::decrypt($row->creators_credential_secret));
        $this->assertSame('3.2', $row->creators_credential_version);
    }

    public function test_put_settings_initial_setup_requires_full_creators_credential_set() {
        $this->authenticate_as_new_user();

        $response = $this->dispatch_put_settings(['partner_tags' => ['UK' => 'uk-tag']]);
        $data = $response->as_error()->get_error_data();

        $this->assertSame(400, $response->get_status());
        $fields = array_column($data['errors'], 'field');
        $this->assertContains('creators_credential_id', $fields);
        $this->assertContains('creators_credential_secret', $fields);
        $this->assertContains('creators_credential_version', $fields);
    }

    public function test_put_settings_rejects_invalid_creators_credential_version() {
        $this->authenticate_as_new_user();

        $response = $this->dispatch_put_settings([
            'creators_credential_id' => 'amzn1.application-oa2-client.example',
            'creators_credential_secret' => 'creators-secret',
            'creators_credential_version' => '9.9',
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('VALIDATION_ERROR', $response->as_error()->get_error_code());
    }

    public function test_put_settings_rejects_non_object_partner_tags() {
        // A malformed string value never reaches the controller's own
        // is_array() check - WP's REST arg schema (type => 'object') rejects
        // it first. This still exercises real routing/validation wiring.
        // partner_tags validation runs before the credential-completeness
        // check, so no credentials need to be supplied here.
        $this->authenticate_as_new_user();

        $request = new WP_REST_Request('PUT', '/' . APT_API_NAMESPACE . '/settings');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'partner_tags' => 'not-an-object',
        ]));
        $response = $this->server->dispatch($request);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('rest_invalid_param', $response->as_error()->get_error_code());
    }

    public function test_put_settings_rejects_invalid_region_code_in_partner_tags() {
        $this->authenticate_as_new_user();

        $response = $this->dispatch_put_settings([
            'partner_tags' => ['ZZ' => 'bad-region-tag'],
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('VALIDATION_ERROR', $response->as_error()->get_error_code());
    }

    public function test_put_settings_rejects_empty_partner_tag_value() {
        $this->authenticate_as_new_user();

        $response = $this->dispatch_put_settings([
            'partner_tags' => ['UK' => '   '],
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('VALIDATION_ERROR', $response->as_error()->get_error_code());
    }

    // -- PUT /settings (update) --

    public function test_put_settings_partial_update_leaves_other_fields_untouched() {
        $user_id = $this->authenticate_as_new_user();
        $this->insert_settings_row($user_id, 'original-credential-id', 'original-credential-secret', ['UK' => 'uk-tag']);

        $response = $this->dispatch_put_settings(['creators_credential_id' => 'updated-credential-id']);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['UK' => 'uk-tag'], $data['partner_tags'], 'Untouched partner_tags should be preserved.');

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apt_user_settings WHERE user_id = %d",
            $user_id
        ));
        $this->assertSame('updated-credential-id', Encryption::decrypt($row->creators_credential_id));
        $this->assertSame('original-credential-secret', Encryption::decrypt($row->creators_credential_secret), 'Untouched creators_credential_secret should be preserved.');
    }

    public function test_put_settings_merges_partner_tags_rather_than_replacing() {
        $user_id = $this->authenticate_as_new_user();
        $this->insert_settings_row($user_id, 'original-credential-id', 'original-credential-secret', ['UK' => 'uk-tag']);

        $response = $this->dispatch_put_settings(['partner_tags' => ['DE' => 'de-tag']]);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['UK' => 'uk-tag', 'DE' => 'de-tag'], $data['partner_tags']);
    }

    // -- DELETE /settings/partner-tags/{region} --

    public function test_delete_partner_tag_returns_not_found_when_unconfigured() {
        $this->authenticate_as_new_user();

        $response = $this->dispatch_delete_partner_tag('UK');

        $this->assertSame(404, $response->get_status());
    }

    public function test_delete_partner_tag_returns_not_found_when_region_not_configured() {
        $user_id = $this->authenticate_as_new_user();
        $this->insert_settings_row($user_id, 'original-credential-id', 'original-credential-secret', ['UK' => 'uk-tag']);

        $response = $this->dispatch_delete_partner_tag('DE');

        $this->assertSame(404, $response->get_status());
    }

    public function test_delete_partner_tag_removes_region_and_returns_no_content() {
        $user_id = $this->authenticate_as_new_user();
        $this->insert_settings_row($user_id, 'original-credential-id', 'original-credential-secret', ['UK' => 'uk-tag', 'DE' => 'de-tag']);

        $response = $this->dispatch_delete_partner_tag('UK');

        $this->assertSame(204, $response->get_status());

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apt_user_settings WHERE user_id = %d",
            $user_id
        ));
        $this->assertSame(['DE' => 'de-tag'], json_decode($row->partner_tags, true));
    }

    // -- POST /settings/validate --

    public function test_validate_credentials_requires_authentication() {
        $response = $this->dispatch_validate_credentials();

        $this->assertSame(401, $response->get_status());
    }

    public function test_validate_credentials_not_configured() {
        $this->authenticate_as_new_user();

        // Guard against a local .env supplying fallback credentials - this
        // test specifically asserts the *unconfigured* path.
        $saved_env = apt_test_suppress_credential_env_fallback();
        try {
            $response = $this->dispatch_validate_credentials();
        } finally {
            apt_test_restore_credential_env_fallback($saved_env);
        }

        $this->assertSame(400, $response->get_status());
        $this->assertSame('NOT_CONFIGURED', $response->as_error()->get_error_code());
    }

    public function test_validate_credentials_valid_when_amazon_reachable() {
        $user_id = $this->authenticate_as_new_user();
        $this->insert_settings_row($user_id);

        apt_test_queue_creators_api_response(200, [
            'searchResult' => ['items' => [['asin' => 'B0TESTASIN']]],
        ]);

        $response = $this->dispatch_validate_credentials();
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['valid']);
    }

    public function test_validate_credentials_invalid_when_amazon_unreachable() {
        $user_id = $this->authenticate_as_new_user();
        $this->insert_settings_row($user_id);

        apt_test_queue_creators_api_error('http_request_failed', 'Operation timed out after 30001 milliseconds.');

        $response = $this->dispatch_validate_credentials();
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertFalse($data['valid']);
    }
}

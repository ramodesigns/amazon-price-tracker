<?php
/**
 * Blacklist Controller Component Test
 *
 * Exercises the /blacklist endpoints end-to-end through the real WP REST
 * dispatcher - real routing, admin-only permission callbacks, real DB
 * writes. No Amazon dependency.
 *
 * @package AmazonPriceTracker\Tests\Component
 */

/**
 * Test case for the Blacklist REST controller.
 */
class Test_Blacklist_Controller_Component extends WP_UnitTestCase {

    /**
     * @var WP_REST_Server
     */
    protected $server;

    /**
     * @var int|null
     */
    protected $admin_user_id;

    public function setUp(): void {
        parent::setUp();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        $this->server = $wp_rest_server;
        do_action('rest_api_init', $this->server);
    }

    public function tearDown(): void {
        global $wpdb;

        if ($this->admin_user_id) {
            $wpdb->delete($wpdb->prefix . 'apt_blacklist', ['created_by' => $this->admin_user_id]);
            $wpdb->delete($wpdb->prefix . 'apt_products', ['created_by' => $this->admin_user_id]);
        }

        parent::tearDown();
    }

    private function authenticate_as_admin(): int {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $this->admin_user_id = $user_id;
        wp_set_current_user($user_id);
        return $user_id;
    }

    private function insert_blacklist_entry(int $user_id, string $asin, string $region, ?string $reason = null): int {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'apt_blacklist', [
            'asin' => $asin,
            'region' => $region,
            'reason' => $reason,
            'created_at' => current_time('mysql', true),
            'created_by' => $user_id,
        ]);

        return $wpdb->insert_id;
    }

    private function insert_active_product(int $user_id, string $asin, string $region): void {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'apt_products', [
            'asin' => $asin,
            'region' => $region,
            'is_active' => 1,
            'created_by' => $user_id,
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);
    }

    private function dispatch(string $method, string $route, array $query = []): WP_REST_Response {
        $request = new WP_REST_Request($method, '/' . APT_API_NAMESPACE . $route);
        foreach ($query as $key => $value) {
            $request->set_param($key, $value);
        }
        return $this->server->dispatch($request);
    }

    // -- Permissions --

    public function test_get_items_requires_authentication() {
        $response = $this->dispatch('GET', '/blacklist');

        $this->assertSame(401, $response->get_status());
    }

    public function test_get_items_forbidden_for_non_admin() {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $response = $this->dispatch('GET', '/blacklist');

        $this->assertSame(403, $response->get_status());
    }

    // -- GET /blacklist --

    public function test_get_items_returns_paginated_entries() {
        $user_id = $this->authenticate_as_admin();
        $this->insert_blacklist_entry($user_id, 'B0BLACKLS1', 'UK', 'Restricted');
        $this->insert_blacklist_entry($user_id, 'B0BLACKLS2', 'DE');

        $response = $this->dispatch('GET', '/blacklist');
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertCount(2, $data['data']);
        $this->assertSame(2, $data['meta']['pagination']['total_items']);
    }

    public function test_get_items_filters_by_region() {
        $user_id = $this->authenticate_as_admin();
        $this->insert_blacklist_entry($user_id, 'B0BLACKLS1', 'UK');
        $this->insert_blacklist_entry($user_id, 'B0BLACKLS2', 'DE');

        $response = $this->dispatch('GET', '/blacklist', ['region' => 'UK']);
        $data = $response->get_data();

        $this->assertCount(1, $data['data']);
        $this->assertSame('UK', $data['data'][0]['region']);
    }

    public function test_get_items_filters_by_search() {
        $user_id = $this->authenticate_as_admin();
        $this->insert_blacklist_entry($user_id, 'B0BLACKLS1', 'UK');
        $this->insert_blacklist_entry($user_id, 'B0OTHERASN', 'UK');

        $response = $this->dispatch('GET', '/blacklist', ['search' => 'BLACKLS1']);
        $data = $response->get_data();

        $this->assertCount(1, $data['data']);
        $this->assertSame('B0BLACKLS1', $data['data'][0]['asin']);
    }

    // -- POST /blacklist --

    public function test_create_item_validates_asin_and_region() {
        $this->authenticate_as_admin();

        $response = $this->dispatch('POST', '/blacklist', ['asin' => 'BAD', 'region' => 'ZZ']);
        $data = $response->as_error()->get_error_data();

        $this->assertSame(400, $response->get_status());
        $fields = array_column($data['errors'], 'field');
        $this->assertContains('asin', $fields);
        $this->assertContains('region', $fields);
    }

    public function test_create_item_succeeds_and_normalizes_asin_and_region() {
        $user_id = $this->authenticate_as_admin();

        $response = $this->dispatch('POST', '/blacklist', [
            'asin' => 'b0blackls1',
            'region' => 'uk',
            'reason' => 'Restricted category',
        ]);
        $data = $response->get_data();

        $this->assertSame(201, $response->get_status());
        $this->assertSame('B0BLACKLS1', $data['asin']);
        $this->assertSame('UK', $data['region']);
        $this->assertSame('Restricted category', $data['reason']);
        $this->assertSame($user_id, $data['created_by']);
    }

    public function test_create_item_rejects_duplicate() {
        $user_id = $this->authenticate_as_admin();
        $this->insert_blacklist_entry($user_id, 'B0BLACKLS1', 'UK');

        $response = $this->dispatch('POST', '/blacklist', ['asin' => 'B0BLACKLS1', 'region' => 'UK']);

        $this->assertSame(409, $response->get_status());
        $this->assertSame('ALREADY_EXISTS', $response->as_error()->get_error_code());
    }

    public function test_create_item_soft_deletes_matching_active_product() {
        global $wpdb;

        $user_id = $this->authenticate_as_admin();
        $this->insert_active_product($user_id, 'B0BLACKLS1', 'UK');

        $response = $this->dispatch('POST', '/blacklist', ['asin' => 'B0BLACKLS1', 'region' => 'UK']);
        $this->assertSame(201, $response->get_status());

        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apt_products WHERE asin = %s AND region = %s",
            'B0BLACKLS1',
            'UK'
        ));
        $this->assertEquals(0, $product->is_active, 'Blacklisting should soft-delete a matching tracked product.');
    }

    // -- GET /blacklist/check --

    public function test_check_blacklist_returns_false_when_not_blacklisted() {
        $this->authenticate_as_admin();

        $response = $this->dispatch('GET', '/blacklist/check', ['asin' => 'B0NOTLIST1', 'region' => 'UK']);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertFalse($data['blacklisted']);
        $this->assertArrayNotHasKey('entry', $data);
    }

    public function test_check_blacklist_returns_true_with_entry_when_blacklisted() {
        $user_id = $this->authenticate_as_admin();
        $this->insert_blacklist_entry($user_id, 'B0BLACKLS1', 'UK', 'Restricted');

        $response = $this->dispatch('GET', '/blacklist/check', ['asin' => 'B0BLACKLS1', 'region' => 'UK']);
        $data = $response->get_data();

        $this->assertTrue($data['blacklisted']);
        $this->assertSame('Restricted', $data['entry']['reason']);
    }

    // -- GET/DELETE /blacklist/{id} --

    public function test_get_item_returns_not_found_for_unknown_id() {
        $this->authenticate_as_admin();

        $response = $this->dispatch('GET', '/blacklist/999999');

        $this->assertSame(404, $response->get_status());
    }

    public function test_get_item_returns_entry_by_id() {
        $user_id = $this->authenticate_as_admin();
        $id = $this->insert_blacklist_entry($user_id, 'B0BLACKLS1', 'UK');

        $response = $this->dispatch('GET', "/blacklist/{$id}");
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('B0BLACKLS1', $data['asin']);
    }

    public function test_delete_item_returns_not_found_for_unknown_id() {
        $this->authenticate_as_admin();

        $response = $this->dispatch('DELETE', '/blacklist/999999');

        $this->assertSame(404, $response->get_status());
    }

    public function test_delete_item_removes_entry() {
        global $wpdb;

        $user_id = $this->authenticate_as_admin();
        $id = $this->insert_blacklist_entry($user_id, 'B0BLACKLS1', 'UK');

        $response = $this->dispatch('DELETE', "/blacklist/{$id}");

        $this->assertSame(204, $response->get_status());
        $this->assertNull($wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apt_blacklist WHERE id = %d",
            $id
        )));
    }
}

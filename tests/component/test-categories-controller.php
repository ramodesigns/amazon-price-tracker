<?php
/**
 * Categories Controller Component Test
 *
 * Exercises GET /categories end-to-end through the real WP REST dispatcher -
 * real routing, admin-only permission callback, and real DB rows. No Amazon
 * dependency.
 *
 * @package AmazonPriceTracker\Tests\Component
 */

/**
 * Test case for the Categories REST controller.
 */
class Test_Categories_Controller_Component extends WP_UnitTestCase {

    /**
     * @var WP_REST_Server
     */
    protected $server;

    /**
     * @var int[]
     */
    protected $created_user_ids = [];

    public function setUp(): void {
        parent::setUp();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        $this->server = $wp_rest_server;
        do_action('rest_api_init', $this->server);
    }

    public function tearDown(): void {
        global $wpdb;

        if ($this->created_user_ids) {
            $wpdb->delete($wpdb->prefix . 'apt_products', ['created_by' => $this->created_user_ids[0]]);
        }

        parent::tearDown();
    }

    private function dispatch_get_categories(): WP_REST_Response {
        $request = new WP_REST_Request('GET', '/' . APT_API_NAMESPACE . '/categories');
        return $this->server->dispatch($request);
    }

    private function insert_product(int $user_id, string $asin, ?string $category, bool $is_active = true): void {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'apt_products', [
            'asin' => $asin,
            'region' => 'UK',
            'custom_category' => $category,
            'is_active' => $is_active ? 1 : 0,
            'created_by' => $user_id,
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);
    }

    public function test_get_categories_requires_authentication() {
        $response = $this->dispatch_get_categories();

        $this->assertSame(401, $response->get_status());
        $this->assertSame('rest_forbidden', $response->as_error()->get_error_code());
    }

    public function test_get_categories_forbidden_for_non_admin() {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $response = $this->dispatch_get_categories();

        $this->assertSame(403, $response->get_status());
        $this->assertSame('FORBIDDEN', $response->as_error()->get_error_code());
    }

    public function test_get_categories_returns_distinct_categories_with_counts_for_admin() {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $this->created_user_ids[] = $user_id;
        wp_set_current_user($user_id);

        $this->insert_product($user_id, 'B0CATEGOR1', 'Electronics');
        $this->insert_product($user_id, 'B0CATEGOR2', 'Electronics');
        $this->insert_product($user_id, 'B0CATEGOR3', 'Books');

        $response = $this->dispatch_get_categories();
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(
            [
                ['name' => 'Books', 'count' => 1],
                ['name' => 'Electronics', 'count' => 2],
            ],
            $data
        );
    }

    public function test_get_categories_excludes_null_empty_and_inactive_products() {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $this->created_user_ids[] = $user_id;
        wp_set_current_user($user_id);

        $this->insert_product($user_id, 'B0NOCAT001', null);
        $this->insert_product($user_id, 'B0EMPTYCAT', '');
        $this->insert_product($user_id, 'B0INACTIV1', 'Garden', false);
        $this->insert_product($user_id, 'B0ACTIVECA', 'Garden', true);

        $response = $this->dispatch_get_categories();
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame([['name' => 'Garden', 'count' => 1]], $data);
    }
}

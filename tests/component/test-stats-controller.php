<?php
/**
 * Stats Controller Component Test
 *
 * Exercises GET /stats and GET /stats/user end-to-end through the real WP
 * REST dispatcher - real routing, permission callbacks, and real DB rows.
 * No Amazon dependency: both endpoints only aggregate what's already in the
 * plugin's tables.
 *
 * Global stats are cached for 5 minutes in the apt_stats_cache transient;
 * the caching behavior itself is under test here, so tests that assert
 * absolute counts clear the transient (and wipe the products/price tables -
 * these two endpoints aggregate over the whole table, not per-user) inside
 * the test's own transaction, which WP_UnitTestCase rolls back afterwards.
 *
 * @package AmazonPriceTracker\Tests\Component
 */

use APT\API\Controllers\Stats_Controller;

/**
 * Test case for the Stats REST controller.
 */
class Test_Stats_Controller_Component extends WP_UnitTestCase {

    /**
     * @var WP_REST_Server
     */
    protected $server;

    public function setUp(): void {
        parent::setUp();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        $this->server = $wp_rest_server;
        do_action('rest_api_init', $this->server);

        delete_transient('apt_stats_cache');
    }

    public function tearDown(): void {
        delete_transient('apt_stats_cache');
        delete_option('apt_daily_creation_limit');

        parent::tearDown();
    }

    private function dispatch_get_stats(): WP_REST_Response {
        $request = new WP_REST_Request('GET', '/' . APT_API_NAMESPACE . '/stats');
        return $this->server->dispatch($request);
    }

    private function dispatch_get_user_stats(): WP_REST_Response {
        $request = new WP_REST_Request('GET', '/' . APT_API_NAMESPACE . '/stats/user');
        return $this->server->dispatch($request);
    }

    /**
     * Remove all product/price rows so tests asserting absolute counts
     * aren't coupled to leftovers from other test files. Runs inside this
     * test's transaction, so WP_UnitTestCase's rollback restores everything.
     */
    private function wipe_product_tables(): void {
        global $wpdb;

        $wpdb->query("DELETE FROM {$wpdb->prefix}apt_price_history");
        $wpdb->query("DELETE FROM {$wpdb->prefix}apt_products");
    }

    private function insert_product(int $user_id, string $asin, array $overrides = []): int {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'apt_products', array_merge([
            'asin' => $asin,
            'region' => 'UK',
            'custom_category' => null,
            'is_active' => 1,
            'created_by' => $user_id,
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], $overrides));

        return (int) $wpdb->insert_id;
    }

    private function insert_price_record(int $product_id, string $recorded_at): void {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'apt_price_history', [
            'product_id' => $product_id,
            'rrp' => null,
            'current_price' => 9.99,
            'is_prime_price' => 0,
            'availability' => 'in_stock',
            'recorded_at' => $recorded_at,
        ]);
    }

    public function test_get_stats_requires_authentication() {
        $response = $this->dispatch_get_stats();

        $this->assertSame(401, $response->get_status());
        $this->assertSame('rest_forbidden', $response->as_error()->get_error_code());
    }

    public function test_get_user_stats_requires_authentication() {
        $response = $this->dispatch_get_user_stats();

        $this->assertSame(401, $response->get_status());
        $this->assertSame('rest_forbidden', $response->as_error()->get_error_code());
    }

    public function test_get_stats_returns_global_counts() {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);
        $this->wipe_product_tables();

        $uk_product = $this->insert_product($user_id, 'B0STATSUK1', ['custom_category' => 'Electronics']);
        $this->insert_product($user_id, 'B0STATSUK2', ['custom_category' => 'Electronics']);
        $this->insert_product($user_id, 'B0STATSUS1', ['region' => 'US']);
        // Inactive: counted in total_products, excluded from active_products,
        // products_by_region, and categories_count.
        $this->insert_product($user_id, 'B0STATSDEA', ['region' => 'DE', 'custom_category' => 'Garden', 'is_active' => 0]);

        $this->insert_price_record($uk_product, '2026-07-10 08:00:00');
        $this->insert_price_record($uk_product, '2026-07-11 08:00:00');

        $response = $this->dispatch_get_stats();
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(4, $data['total_products']);
        $this->assertSame(3, $data['active_products']);
        $this->assertSame(2, $data['total_price_records']);
        $this->assertSame(
            [
                ['region' => 'UK', 'count' => 2],
                ['region' => 'US', 'count' => 1],
            ],
            $data['products_by_region']
        );
        $this->assertSame(1, $data['categories_count']);
    }

    public function test_get_stats_includes_fresh_user_stats_for_subscriber() {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);
        $this->wipe_product_tables();

        update_option('apt_daily_creation_limit', 10);
        $this->insert_product($user_id, 'B0TODAYST1');
        $this->insert_product($user_id, 'B0TODAYST2');

        $data = $this->dispatch_get_stats()->get_data();

        $this->assertSame(
            [
                'products_created_today' => 2,
                'daily_limit' => 10,
                'remaining_today' => 8,
            ],
            $data['user_stats']
        );
    }

    public function test_get_stats_omits_last_refresh_for_non_admin() {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $data = $this->dispatch_get_stats()->get_data();

        $this->assertArrayNotHasKey('last_refresh', $data);
        $this->assertIsInt($data['user_stats']['daily_limit']);
    }

    public function test_get_stats_includes_last_refresh_for_admin() {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $this->wipe_product_tables();

        $product_id = $this->insert_product($admin_id, 'B0REFRESHT');
        $this->insert_price_record($product_id, '2026-07-10 08:00:00');
        $this->insert_price_record($product_id, '2026-07-12 14:30:00');

        $data = $this->dispatch_get_stats()->get_data();

        $this->assertSame('2026-07-12T14:30:00+00:00', $data['last_refresh']);
        // Admins have no daily limit.
        $this->assertNull($data['user_stats']['daily_limit']);
        $this->assertNull($data['user_stats']['remaining_today']);
    }

    public function test_get_stats_last_refresh_is_null_for_admin_with_no_price_records() {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $this->wipe_product_tables();

        $data = $this->dispatch_get_stats()->get_data();

        $this->assertArrayHasKey('last_refresh', $data);
        $this->assertNull($data['last_refresh']);
    }

    public function test_get_stats_global_counts_are_cached_but_user_stats_are_not() {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);
        $this->wipe_product_tables();

        $this->insert_product($user_id, 'B0CACHEST1');
        $first = $this->dispatch_get_stats()->get_data();
        $this->assertSame(1, $first['total_products']);

        // A direct insert doesn't clear the transient (unlike the create
        // endpoint, which calls clear_caches()), so the global counts must
        // come back stale while the per-user counts recompute every call.
        $this->insert_product($user_id, 'B0CACHEST2');
        $second = $this->dispatch_get_stats()->get_data();

        $this->assertSame(1, $second['total_products'], 'Global counts should be served from the transient cache.');
        $this->assertSame(2, $second['user_stats']['products_created_today'], 'User stats must be computed fresh on every call.');
    }

    public function test_clear_cache_forces_global_recompute() {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);
        $this->wipe_product_tables();

        $this->insert_product($user_id, 'B0CLEARCA1');
        $this->assertSame(1, $this->dispatch_get_stats()->get_data()['total_products']);

        $this->insert_product($user_id, 'B0CLEARCA2');
        Stats_Controller::clear_cache();

        $this->assertSame(2, $this->dispatch_get_stats()->get_data()['total_products']);
    }

    public function test_get_user_stats_counts_and_limits_for_subscriber() {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $other_user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        update_option('apt_daily_creation_limit', 5);

        $this->insert_product($user_id, 'B0USERST01');
        $this->insert_product($user_id, 'B0USERST02');
        // Created yesterday: counts towards the total but not today's usage.
        $this->insert_product($user_id, 'B0USERST03', [
            'created_at' => gmdate('Y-m-d H:i:s', strtotime('-1 day')),
        ]);
        // Another user's product: counts towards neither.
        $this->insert_product($other_user_id, 'B0USERST04');

        $response = $this->dispatch_get_user_stats();
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(2, $data['products_created_today']);
        $this->assertSame(3, $data['products_created_total']);
        $this->assertSame(5, $data['daily_limit']);
        $this->assertSame(3, $data['remaining_today']);
        $this->assertFalse($data['is_admin']);
    }

    public function test_get_user_stats_remaining_today_is_clamped_at_zero() {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        update_option('apt_daily_creation_limit', 1);
        $this->insert_product($user_id, 'B0OVERLIM1');
        $this->insert_product($user_id, 'B0OVERLIM2');

        $data = $this->dispatch_get_user_stats()->get_data();

        $this->assertSame(0, $data['remaining_today'], 'remaining_today must not go negative when usage exceeds the limit.');
    }

    public function test_get_user_stats_admin_has_no_limit() {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $data = $this->dispatch_get_user_stats()->get_data();

        $this->assertNull($data['daily_limit']);
        $this->assertNull($data['remaining_today']);
        $this->assertTrue($data['is_admin']);
    }

    public function test_get_user_stats_configured_regions_from_partner_tags() {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $wpdb->insert($wpdb->prefix . 'apt_user_settings', [
            'user_id' => $user_id,
            'partner_tags' => wp_json_encode(['UK' => 'tag-uk-21', 'US' => 'tag-us-20']),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);

        $data = $this->dispatch_get_user_stats()->get_data();

        $this->assertSame(['UK', 'US'], $data['configured_regions']);
    }

    public function test_get_user_stats_configured_regions_empty_without_settings() {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $data = $this->dispatch_get_user_stats()->get_data();

        $this->assertSame([], $data['configured_regions']);
    }
}

<?php
/**
 * Dashboard Widget Component Test
 *
 * Exercises the wp-admin dashboard widget end-to-end: registration gating,
 * style enqueueing, the rendered HTML for both the empty and fully-populated
 * states (stats, price drops with the 5% threshold, out-of-stock/stale
 * attention items, region breakdown), the 5-minute transient cache, and the
 * two admin-ajax handlers (in a separate WP_Ajax_UnitTestCase class below,
 * which is what lets wp_send_json_*'s wp_die() be caught as an exception).
 *
 * @package AmazonPriceTracker\Tests\Component
 */

use APT\Admin\Dashboard_Widget;

require_once __DIR__ . '/creators-api-mock.php';

/**
 * Test case for the dashboard widget's registration, rendering, and cache.
 */
class Test_Dashboard_Widget_Component extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();

        delete_transient('apt_dashboard_widget_data');
    }

    public function tearDown(): void {
        delete_transient('apt_dashboard_widget_data');

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Fixture helpers
    // ------------------------------------------------------------------

    /**
     * The widget aggregates over the whole products/price tables, so tests
     * asserting rendered counts wipe them first - inside this test's
     * transaction, which WP_UnitTestCase rolls back afterwards.
     */
    private function wipe_product_tables(): void {
        global $wpdb;

        $wpdb->query("DELETE FROM {$wpdb->prefix}apt_price_history");
        $wpdb->query("DELETE FROM {$wpdb->prefix}apt_products");
    }

    private function insert_product(string $asin, array $overrides = []): int {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'apt_products', array_merge([
            'asin' => $asin,
            'region' => 'UK',
            'is_active' => 1,
            'created_by' => 1,
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], $overrides));

        return (int) $wpdb->insert_id;
    }

    private function insert_price_record(int $product_id, ?float $price, string $recorded_at, string $availability = 'in_stock'): void {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'apt_price_history', [
            'product_id' => $product_id,
            'current_price' => $price,
            'availability' => $availability,
            'recorded_at' => $recorded_at,
        ]);
    }

    private function render_widget(): string {
        ob_start();
        Dashboard_Widget::render_widget();
        return ob_get_clean();
    }

    // ------------------------------------------------------------------
    // Registration and styles
    // ------------------------------------------------------------------

    public function test_widget_is_registered_for_admins_only(): void {
        require_once ABSPATH . 'wp-admin/includes/template.php';
        require_once ABSPATH . 'wp-admin/includes/dashboard.php';

        global $wp_meta_boxes;

        // wp_add_dashboard_widget() places the widget on the *current*
        // screen; without this it lands under a null screen id. Reset by
        // WP_UnitTestCase::tearDown().
        set_current_screen('dashboard');

        $wp_meta_boxes = [];
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        Dashboard_Widget::register_widget();
        $this->assertArrayNotHasKey('dashboard', (array) $wp_meta_boxes);

        $wp_meta_boxes = [];
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        Dashboard_Widget::register_widget();
        $this->assertArrayHasKey('apt_dashboard_widget', $wp_meta_boxes['dashboard']['normal']['core']);
    }

    public function test_styles_are_only_added_on_the_dashboard_screen(): void {
        wp_register_style('dashboard', false);

        Dashboard_Widget::enqueue_styles('edit.php');
        $this->assertFalse(wp_styles()->get_data('dashboard', 'after'));

        Dashboard_Widget::enqueue_styles('index.php');
        $after = wp_styles()->get_data('dashboard', 'after');
        $this->assertNotEmpty($after);
        $this->assertStringContainsString('.apt-dashboard-widget', implode('', $after));
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    public function test_empty_state_renders_zeros_and_omits_optional_sections(): void {
        $this->wipe_product_tables();

        $html = $this->render_widget();

        $this->assertStringContainsString('Products Tracked', $html);
        $this->assertStringContainsString('<span class="apt-stat-value">0</span>', $html);
        $this->assertStringContainsString('Never', $html);
        // Assert on section-specific markup, not the section headings - the
        // template's unconditional HTML comments contain similar text.
        $this->assertStringNotContainsString('Recent Price Drops', $html);
        $this->assertStringNotContainsString('apt-attention-section', $html);
        $this->assertStringNotContainsString('apt-regions-grid', $html);
    }

    public function test_populated_state_renders_stats_drops_attention_and_regions(): void {
        $this->wipe_product_tables();
        $now = current_time('mysql', true);
        $yesterday = gmdate('Y-m-d H:i:s', strtotime('-23 hours'));

        // Product A (UK): 30.00 -> 19.99, a 33% drop within 24h - qualifies.
        $a = $this->insert_product('B0WIDGDRP1');
        $this->insert_price_record($a, 30.00, $yesterday);
        $this->insert_price_record($a, 19.99, $now);

        // Product C (UK): 20.00 -> 19.50, only 2.5% - below the 5% threshold.
        $c = $this->insert_product('B0WIDGSML1');
        $this->insert_price_record($c, 20.00, $yesterday);
        $this->insert_price_record($c, 19.50, $now);

        // Product B (US): stale (updated 8 days ago) and now out of stock.
        $b = $this->insert_product('B0WIDGOOS1', [
            'region' => 'US',
            'updated_at' => gmdate('Y-m-d H:i:s', strtotime('-8 days')),
        ]);
        $this->insert_price_record($b, 15.00, $yesterday);
        $this->insert_price_record($b, null, $now, 'out_of_stock');

        $html = $this->render_widget();

        // Stats: 3 products, 6 price records, refreshed just now.
        $this->assertStringContainsString('<span class="apt-stat-value">3</span>', $html);
        $this->assertStringContainsString('<span class="apt-stat-value">6</span>', $html);
        $this->assertStringContainsString('Just now', $html);

        // Price drops: A with UK currency and rounded percent; C excluded.
        $this->assertStringContainsString('Recent Price Drops', $html);
        $this->assertStringContainsString('B0WIDGDRP1', $html);
        $this->assertStringContainsString('£30.00', $html);
        $this->assertStringContainsString('£19.99', $html);
        $this->assertStringContainsString('-33%', $html);
        $this->assertStringNotContainsString('B0WIDGSML1', $html);

        // Attention: one out-of-stock, one stale (both singular).
        $this->assertStringContainsString('apt-attention-section', $html);
        $this->assertStringContainsString('1 product out of stock', $html);
        $this->assertStringContainsString('1 product with stale data', $html);

        // Regions: UK has 2 active products, US has 1.
        $this->assertStringContainsString('apt-regions-grid', $html);
        $this->assertStringContainsString('<strong>UK</strong>', $html);
        $this->assertStringContainsString('<strong>US</strong>', $html);
    }

    public function test_price_increases_are_not_reported_as_drops(): void {
        $this->wipe_product_tables();

        $product_id = $this->insert_product('B0WIDGRISE');
        $this->insert_price_record($product_id, 10.00, gmdate('Y-m-d H:i:s', strtotime('-23 hours')));
        $this->insert_price_record($product_id, 15.00, current_time('mysql', true));

        $this->assertStringNotContainsString('Recent Price Drops', $this->render_widget());
    }

    // ------------------------------------------------------------------
    // Caching
    // ------------------------------------------------------------------

    public function test_widget_data_is_cached_until_cleared(): void {
        $this->wipe_product_tables();
        $this->insert_product('B0WIDGCCH1');

        $this->assertStringContainsString('<span class="apt-stat-value">1</span>', $this->render_widget());

        // A direct insert doesn't invalidate the transient - the second
        // render must serve the cached count.
        $this->insert_product('B0WIDGCCH2');
        $this->assertStringContainsString('<span class="apt-stat-value">1</span>', $this->render_widget());

        Dashboard_Widget::clear_cache();
        $this->assertStringContainsString('<span class="apt-stat-value">2</span>', $this->render_widget());
    }
}

/**
 * Test case for the widget's admin-ajax handlers. Separate class because
 * WP_Ajax_UnitTestCase installs the wp_die() handler that turns
 * wp_send_json_*'s die() into a catchable WPAjaxDieContinueException.
 */
class Test_Dashboard_Widget_Ajax extends WP_Ajax_UnitTestCase {

    /**
     * @var array<string, string|false>
     */
    private array $previous_env = [];

    public function setUp(): void {
        parent::setUp();

        $this->previous_env = apt_test_suppress_credential_env_fallback();
        delete_transient('apt_dashboard_widget_data');
    }

    public function tearDown(): void {
        global $wpdb;

        apt_test_reset_creators_api_responses();
        apt_test_restore_credential_env_fallback($this->previous_env);
        delete_transient('apt_dashboard_widget_data');
        $wpdb->delete($wpdb->prefix . 'apt_user_settings', ['user_id' => get_current_user_id()]);

        parent::tearDown();
    }

    /**
     * Dispatch an admin-ajax action and return the decoded JSON response.
     */
    private function dispatch(string $action): array {
        try {
            $this->_handleAjax($action);
        } catch (WPAjaxDieContinueException $e) {
            // wp_send_json_* ends with wp_die('') - expected.
        }

        return json_decode($this->_last_response, true);
    }

    public function test_refresh_widget_returns_the_widget_data(): void {
        $this->_setRole('administrator');
        $_POST['nonce'] = wp_create_nonce('apt_widget_nonce');

        $response = $this->dispatch('apt_refresh_widget');

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('total_products', $response['data']);
        $this->assertArrayHasKey('price_drops', $response['data']);
    }

    public function test_refresh_widget_denies_non_admins(): void {
        $this->_setRole('subscriber');
        $_POST['nonce'] = wp_create_nonce('apt_widget_nonce');

        $response = $this->dispatch('apt_refresh_widget');

        $this->assertFalse($response['success']);
        $this->assertSame('Permission denied', $response['data']['message']);
    }

    public function test_run_refresh_refreshes_products_and_clears_caches(): void {
        global $wpdb;

        $this->_setRole('administrator');
        $_POST['nonce'] = wp_create_nonce('apt_refresh_nonce');
        $user_id = get_current_user_id();

        // The handler runs bulk_refresh as the *calling* user, so the
        // settings row must belong to them.
        $wpdb->insert($wpdb->prefix . 'apt_user_settings', [
            'user_id' => $user_id,
            'creators_credential_id' => \APT\Helpers\Encryption::encrypt('test-credential-id'),
            'creators_credential_secret' => \APT\Helpers\Encryption::encrypt('test-credential-secret'),
            'creators_credential_version' => '3.2',
            'partner_tags' => wp_json_encode(['UK' => 'test-partner-tag']),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);

        $wpdb->query("DELETE FROM {$wpdb->prefix}apt_price_history");
        $wpdb->query("DELETE FROM {$wpdb->prefix}apt_products");
        $wpdb->insert($wpdb->prefix . 'apt_products', [
            'asin' => 'B0WIDGAJX1',
            'region' => 'UK',
            'is_active' => 1,
            'created_by' => $user_id,
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);
        apt_test_queue_creators_api_response(200, [
            'itemsResult' => ['items' => [[
                'asin' => 'B0WIDGAJX1',
                'itemInfo' => ['title' => ['displayValue' => 'Ajax Refresh Product']],
                'offersV2' => ['listings' => [[
                    'price' => ['money' => ['amount' => 12.34]],
                    'availability' => ['type' => 'IN_STOCK', 'message' => 'In stock'],
                ]]],
            ]]],
        ]);

        // Both caches must be dropped by the handler.
        set_transient('apt_dashboard_widget_data', ['sentinel' => true], 300);
        set_transient('apt_stats_cache', ['sentinel' => true], 300);

        $response = $this->dispatch('apt_run_manual_refresh');

        $this->assertTrue($response['success']);
        $this->assertSame('Refresh complete. 1 products updated.', $response['data']['message']);
        $this->assertFalse(get_transient('apt_dashboard_widget_data'));
        $this->assertFalse(get_transient('apt_stats_cache'));
    }

    public function test_run_refresh_denies_non_admins(): void {
        $this->_setRole('subscriber');
        $_POST['nonce'] = wp_create_nonce('apt_refresh_nonce');

        $response = $this->dispatch('apt_run_manual_refresh');

        $this->assertFalse($response['success']);
        $this->assertSame('Permission denied', $response['data']['message']);
    }
}

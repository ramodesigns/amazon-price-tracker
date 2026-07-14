<?php
/**
 * Product Service Component Test
 *
 * Exercises Product_Service directly (no REST layer - the controllers'
 * behavior is covered in test-products-controller.php) against the real
 * test database, with the Creators API faked via creators-api-mock.php.
 * Focus: the methods the controller tests leave untouched or only graze -
 * refresh_product(), test_connection(), and bulk_refresh()'s error and
 * batching branches.
 *
 * The env-var credential fallback (Product_Service::get_user_settings() via
 * Env_File) is suppressed for every test: several tests assert the
 * "not configured" outcomes, which a developer's local .env would otherwise
 * silently turn into configured ones.
 *
 * @package AmazonPriceTracker\Tests\Component
 */

use APT\Helpers\Encryption;
use APT\Services\Product_Service;

require_once __DIR__ . '/creators-api-mock.php';

/**
 * Test case for the product service layer.
 */
class Test_Product_Service_Component extends WP_UnitTestCase {

    /**
     * @var Product_Service
     */
    private Product_Service $service;

    /**
     * @var int
     */
    private int $user_id;

    /**
     * @var array<string, string|false>
     */
    private array $previous_env = [];

    public function setUp(): void {
        parent::setUp();

        $this->previous_env = apt_test_suppress_credential_env_fallback();
        $this->service = new Product_Service();
        $this->user_id = self::factory()->user->create(['role' => 'subscriber']);
    }

    /**
     * Explicit cleanup + COMMIT, matching test-products-controller.php:
     * create_product()'s internal START TRANSACTION implicitly commits the
     * WP_UnitTestCase test-wrapping transaction (MySQL has no true nesting),
     * so fixtures from tests that call it would otherwise leak permanently.
     * The trailing COMMIT keeps these cleanup deletes from being undone by
     * the parent rollback.
     */
    public function tearDown(): void {
        global $wpdb;

        apt_test_reset_creators_api_responses();
        apt_test_restore_credential_env_fallback($this->previous_env);

        $product_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}apt_products WHERE created_by = %d",
            $this->user_id
        ));
        foreach ($product_ids as $product_id) {
            $wpdb->delete($wpdb->prefix . 'apt_price_history', ['product_id' => $product_id]);
        }
        $wpdb->delete($wpdb->prefix . 'apt_products', ['created_by' => $this->user_id]);
        $wpdb->delete($wpdb->prefix . 'apt_user_settings', ['user_id' => $this->user_id]);
        $wpdb->query('COMMIT');

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Fixture helpers
    // ------------------------------------------------------------------

    private function insert_settings(array $partner_tags = ['UK' => 'test-partner-tag'], string $credential_id = 'test-credential-id'): void {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'apt_user_settings', [
            'user_id' => $this->user_id,
            'creators_credential_id' => Encryption::encrypt($credential_id),
            'creators_credential_secret' => Encryption::encrypt('test-credential-secret'),
            'creators_credential_version' => '3.2',
            'partner_tags' => wp_json_encode($partner_tags),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);
    }

    private function insert_product(string $asin, array $overrides = []): int {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'apt_products', array_merge([
            'asin' => $asin,
            'region' => 'UK',
            'facts' => wp_json_encode(['title' => 'Stale Title']),
            'is_active' => 1,
            'created_by' => $this->user_id,
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], $overrides));

        return (int) $wpdb->insert_id;
    }

    private function insert_price_record(int $product_id, float $price): void {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'apt_price_history', [
            'product_id' => $product_id,
            'current_price' => $price,
            'availability' => 'in_stock',
            'recorded_at' => gmdate('Y-m-d H:i:s', strtotime('-1 day')),
        ]);
    }

    private function price_record_count(int $product_id): int {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}apt_price_history WHERE product_id = %d",
            $product_id
        ));
    }

    /**
     * A canned Creators API getItems body covering the given ASINs.
     *
     * @param string[] $asins
     */
    private function canned_items_body(array $asins, float $price = 24.99): array {
        $items = [];
        foreach ($asins as $asin) {
            $items[] = [
                'asin' => $asin,
                'itemInfo' => [
                    'title' => ['displayValue' => 'Fresh Title'],
                ],
                'offersV2' => [
                    'listings' => [
                        [
                            'price' => ['money' => ['amount' => $price]],
                            'availability' => ['type' => 'IN_STOCK', 'message' => 'In stock'],
                        ],
                    ],
                ],
            ];
        }

        return ['itemsResult' => ['items' => $items]];
    }

    // ------------------------------------------------------------------
    // refresh_product()
    // ------------------------------------------------------------------

    public function test_refresh_product_returns_not_found_for_unknown_id(): void {
        $result = $this->service->refresh_product(999999999, $this->user_id);

        $this->assertFalse($result['success']);
        $this->assertSame('NOT_FOUND', $result['error_code']);
    }

    public function test_refresh_product_returns_not_configured_without_settings(): void {
        $product_id = $this->insert_product('B0PSREFNC1');

        $result = $this->service->refresh_product($product_id, $this->user_id);

        $this->assertFalse($result['success']);
        $this->assertSame('NOT_CONFIGURED', $result['error_code']);
    }

    public function test_refresh_product_returns_missing_partner_tag_for_unconfigured_region(): void {
        $this->insert_settings(['UK' => 'test-partner-tag']);
        $product_id = $this->insert_product('B0PSREFMT1', ['region' => 'US']);

        $result = $this->service->refresh_product($product_id, $this->user_id);

        $this->assertFalse($result['success']);
        $this->assertSame('MISSING_PARTNER_TAG', $result['error_code']);
        $this->assertStringContainsString('US', $result['error']);
    }

    public function test_refresh_product_keeps_existing_data_on_api_error(): void {
        global $wpdb;

        $this->insert_settings();
        $product_id = $this->insert_product('B0PSREFER1');
        $this->insert_price_record($product_id, 19.99);
        apt_test_queue_creators_api_error('http_request_failed', 'Operation timed out.');

        $result = $this->service->refresh_product($product_id, $this->user_id);

        $this->assertFalse($result['success']);
        $this->assertSame('AMAZON_API_ERROR', $result['error_code']);
        $this->assertSame('Operation timed out.', $result['error']);

        // A failed refresh must be a strict no-op on stored data: no new
        // price record, facts untouched.
        $this->assertSame(1, $this->price_record_count($product_id));
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apt_products WHERE id = %d",
            $product_id
        ));
        $this->assertSame('Stale Title', json_decode($product->facts, true)['title']);
    }

    public function test_refresh_product_updates_facts_and_appends_a_price_record(): void {
        $this->insert_settings();
        $asin = 'B0PSREFOK1';
        $product_id = $this->insert_product($asin);
        $this->insert_price_record($product_id, 19.99);
        apt_test_queue_creators_api_response(200, $this->canned_items_body([$asin], 24.99));

        $result = $this->service->refresh_product($product_id, $this->user_id);

        $this->assertTrue($result['success']);
        $this->assertSame('Fresh Title', json_decode($result['product']->facts, true)['title']);

        // Appends - the prior price record must survive alongside the new one.
        $this->assertSame(2, $this->price_record_count($product_id));
    }

    // ------------------------------------------------------------------
    // bulk_refresh()
    // ------------------------------------------------------------------

    public function test_bulk_refresh_without_settings_fails_every_selected_product(): void {
        $id_1 = $this->insert_product('B0PSBLKNC1');
        $id_2 = $this->insert_product('B0PSBLKNC2');

        $result = $this->service->bulk_refresh([$id_1, $id_2], [], 100, $this->user_id);

        $this->assertSame(0, $result['success_count']);
        $this->assertSame(2, $result['failure_count']);
        $this->assertCount(2, $result['results']);
        foreach ($result['results'] as $item) {
            $this->assertFalse($item['success']);
            $this->assertSame('Amazon Creators API credentials not configured', $item['error']);
        }
    }

    public function test_bulk_refresh_fails_regions_without_a_partner_tag_and_refreshes_the_rest(): void {
        $this->insert_settings(['UK' => 'test-partner-tag']);
        $uk_asin = 'B0PSBLKUK1';
        $uk_id = $this->insert_product($uk_asin);
        $us_id = $this->insert_product('B0PSBLKUS1', ['region' => 'US']);
        // Only the UK batch ever reaches the network - the US region is
        // rejected before any request is built.
        apt_test_queue_creators_api_response(200, $this->canned_items_body([$uk_asin]));

        $result = $this->service->bulk_refresh([$uk_id, $us_id], [], 100, $this->user_id);

        $this->assertSame(1, $result['success_count']);
        $this->assertSame(1, $result['failure_count']);

        $by_id = array_column($result['results'], null, 'product_id');
        $this->assertTrue($by_id[$uk_id]['success']);
        $this->assertFalse($by_id[$us_id]['success']);
        $this->assertSame('No partner tag configured for region US', $by_id[$us_id]['error']);
    }

    public function test_bulk_refresh_fails_items_missing_from_the_amazon_response(): void {
        $this->insert_settings();
        $found_asin = 'B0PSBLKFND';
        $found_id = $this->insert_product($found_asin);
        $missing_id = $this->insert_product('B0PSBLKMIS');
        // Both ASINs go out in one batch; Amazon only returns one of them
        // (exactly what a delisted/invalid ASIN produces on a live call).
        apt_test_queue_creators_api_response(200, $this->canned_items_body([$found_asin]));

        $result = $this->service->bulk_refresh([$found_id, $missing_id], [], 100, $this->user_id);

        $this->assertSame(1, $result['success_count']);
        $this->assertSame(1, $result['failure_count']);

        $by_id = array_column($result['results'], null, 'product_id');
        $this->assertTrue($by_id[$found_id]['success']);
        $this->assertSame('Product not found or API error', $by_id[$missing_id]['error']);
        $this->assertSame(0, $this->price_record_count($missing_id), 'A failed item must not get a price record.');
    }

    public function test_bulk_refresh_scopes_to_the_regions_filter(): void {
        $this->insert_settings(['UK' => 'test-partner-tag', 'US' => 'test-partner-tag-us']);
        $uk_asin = 'B0PSBLKRG1';
        $uk_id = $this->insert_product($uk_asin);
        $this->insert_product('B0PSBLKRG2', ['region' => 'US']);
        apt_test_queue_creators_api_response(200, $this->canned_items_body([$uk_asin]));

        $result = $this->service->bulk_refresh([], ['UK'], 100, $this->user_id);

        // The US product is filtered out of the candidate query entirely -
        // not attempted-and-failed, simply absent from the results.
        $this->assertSame(1, $result['success_count']);
        $this->assertSame(0, $result['failure_count']);
        $this->assertCount(1, $result['results']);
        $this->assertSame($uk_id, $result['results'][0]['product_id']);
    }

    public function test_bulk_refresh_splits_a_region_into_batches_of_ten(): void {
        $this->insert_settings();

        $asins = [];
        for ($i = 1; $i <= 11; $i++) {
            $asin = sprintf('B0PSBATC%02d', $i);
            $asins[] = $asin;
            // Strictly increasing updated_at so the stalest-first ordering
            // (and therefore the batch split) is deterministic.
            $this->insert_product($asin, [
                'updated_at' => gmdate('Y-m-d H:i:s', strtotime("-2 days +{$i} minutes")),
            ]);
        }

        // Two getItems calls: the 10 stalest, then the remaining 1. The mock
        // consumes queued responses in order, so a wrong batch split would
        // mismatch ASINs and surface as failures.
        apt_test_queue_creators_api_response(200, $this->canned_items_body(array_slice($asins, 0, 10)));
        apt_test_queue_creators_api_response(200, $this->canned_items_body(array_slice($asins, 10)));

        $result = $this->service->bulk_refresh([], [], 100, $this->user_id);

        $this->assertSame(11, $result['success_count']);
        $this->assertSame(0, $result['failure_count']);
    }

    public function test_bulk_refresh_clears_the_stats_cache_only_on_success(): void {
        $this->insert_settings();
        $asin = 'B0PSBLKCC1';
        $product_id = $this->insert_product($asin);

        // Failure path: cache must survive.
        set_transient('apt_stats_cache', ['sentinel' => true], 300);
        apt_test_queue_creators_api_error('http_request_failed', 'Timed out.');
        $this->service->bulk_refresh([$product_id], [], 100, $this->user_id);
        $this->assertNotFalse(get_transient('apt_stats_cache'));

        // Success path: cache must be dropped.
        apt_test_queue_creators_api_response(200, $this->canned_items_body([$asin]));
        $this->service->bulk_refresh([$product_id], [], 100, $this->user_id);
        $this->assertFalse(get_transient('apt_stats_cache'));
    }

    // ------------------------------------------------------------------
    // test_connection()
    // ------------------------------------------------------------------

    public function test_connection_reports_not_configured_without_settings(): void {
        $result = $this->service->test_connection($this->user_id);

        $this->assertSame('not_configured', $result['status']);
        $this->assertSame('Amazon Creators API credentials not configured', $result['message']);
    }

    public function test_connection_reports_not_configured_with_empty_partner_tags(): void {
        $this->insert_settings([]);

        $result = $this->service->test_connection($this->user_id);

        $this->assertSame('not_configured', $result['status']);
        $this->assertSame('No partner tags configured for any region', $result['message']);
    }

    public function test_connection_reports_not_configured_for_an_unconfigured_explicit_region(): void {
        $this->insert_settings(['UK' => 'test-partner-tag']);

        $result = $this->service->test_connection($this->user_id, 'US');

        $this->assertSame('not_configured', $result['status']);
        $this->assertSame('No partner tag configured for region US', $result['message']);
    }

    public function test_connection_succeeds_via_the_first_configured_region_by_default(): void {
        $this->insert_settings(['UK' => 'test-partner-tag']);
        // test_connection() issues a searchItems call; any 200 body will do.
        apt_test_queue_creators_api_response(200, ['searchResult' => ['items' => []]]);

        $result = $this->service->test_connection($this->user_id);

        $this->assertSame('connected', $result['status']);
        $this->assertSame('Successfully connected to Amazon Creators API', $result['message']);
        $this->assertArrayHasKey('response_time_ms', $result);
    }

    public function test_connection_reports_error_when_the_api_call_fails(): void {
        $this->insert_settings(['UK' => 'test-partner-tag']);
        apt_test_queue_creators_api_error('http_request_failed', 'Connection refused.');

        $result = $this->service->test_connection($this->user_id);

        $this->assertSame('error', $result['status']);
        $this->assertSame('Connection refused.', $result['message']);
        $this->assertArrayHasKey('response_time_ms', $result);
    }

    public function test_connection_reports_error_when_credentials_are_empty(): void {
        // A settings row whose credential decrypts to '' - from_settings()
        // refuses to build a client from empty credentials, which is the
        // only reachable path to the 'Failed to initialize' branch (the
        // missing-partner-tag case is caught by the earlier check).
        $this->insert_settings(['UK' => 'test-partner-tag'], '');

        $result = $this->service->test_connection($this->user_id);

        $this->assertSame('error', $result['status']);
        $this->assertSame('Failed to initialize Amazon API client', $result['message']);
    }

    // ------------------------------------------------------------------
    // Env-file credential fallback
    // ------------------------------------------------------------------

    public function test_env_credentials_stand_in_for_a_missing_settings_row(): void {
        // No DB settings row for this user; a complete env credential set
        // (normally suppressed in setUp) must be assembled into a working
        // settings object instead - proven by the connection test reaching
        // the (mocked) API and succeeding. Region deliberately left unset to
        // cover its UK default.
        putenv('APT_TEST_CREATORS_API_CREDENTIAL_ID=env-credential-id');
        putenv('APT_TEST_CREATORS_API_CREDENTIAL_SECRET=env-credential-secret');
        putenv('APT_TEST_CREATORS_API_VERSION=3.2');
        putenv('APT_TEST_CREATORS_API_PARTNER_TAG=env-partner-tag');
        apt_test_queue_creators_api_response(200, ['searchResult' => ['items' => []]]);

        $result = $this->service->test_connection($this->user_id);

        $this->assertSame('connected', $result['status']);
    }

    public function test_env_fallback_requires_the_complete_credential_set(): void {
        // Three of the four required vars - the fallback must refuse a
        // partial set rather than build half-configured settings.
        putenv('APT_TEST_CREATORS_API_CREDENTIAL_ID=env-credential-id');
        putenv('APT_TEST_CREATORS_API_CREDENTIAL_SECRET=env-credential-secret');
        putenv('APT_TEST_CREATORS_API_VERSION=3.2');

        $result = $this->service->test_connection($this->user_id);

        $this->assertSame('not_configured', $result['status']);
    }

    // ------------------------------------------------------------------
    // create_product() / reactivate_product() service-level error paths
    // ------------------------------------------------------------------

    public function test_create_product_rolls_back_on_a_duplicate_row(): void {
        // The controller pre-checks duplicates, but the service must survive
        // a direct call racing past that check: the asin_region unique key
        // rejects the insert, and the DATABASE_ERROR path rolls back rather
        // than leaving a transaction open or a half-created product.
        $this->insert_settings();
        $asin = 'B0PSCRDUP1';
        $this->insert_product($asin);
        apt_test_queue_creators_api_response(200, $this->canned_items_body([$asin]));

        $result = $this->service->create_product($asin, 'UK', $this->user_id);

        $this->assertFalse($result['success']);
        $this->assertSame('DATABASE_ERROR', $result['error_code']);

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}apt_products WHERE asin = %s AND region = %s",
            $asin,
            'UK'
        ));
        $this->assertSame(1, $count, 'Only the original row may exist after the rolled-back duplicate.');
    }

    public function test_create_product_returns_not_configured_without_settings(): void {
        $result = $this->service->create_product('B0PSCRNC01', 'UK', $this->user_id);

        $this->assertFalse($result['success']);
        $this->assertSame('NOT_CONFIGURED', $result['error_code']);
    }

    public function test_create_product_returns_missing_partner_tag_for_unconfigured_region(): void {
        $this->insert_settings(['UK' => 'test-partner-tag']);

        $result = $this->service->create_product('B0PSCRMT01', 'US', $this->user_id);

        $this->assertFalse($result['success']);
        $this->assertSame('MISSING_PARTNER_TAG', $result['error_code']);
    }

    public function test_reactivate_product_is_a_no_op_when_the_amazon_fetch_fails(): void {
        global $wpdb;

        $this->insert_settings();
        $asin = 'B0PSREACT1';
        $product_id = $this->insert_product($asin, ['is_active' => 0]);
        apt_test_queue_creators_api_error('http_request_failed', 'Timed out.');

        $result = $this->service->reactivate_product($asin, 'UK', $this->user_id);

        $this->assertFalse($result['success']);
        $this->assertSame('AMAZON_API_ERROR', $result['error_code']);

        // The product must still be inactive with no new price record - a
        // failed reactivation changes nothing.
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apt_products WHERE id = %d",
            $product_id
        ));
        $this->assertSame('0', (string) $product->is_active);
        $this->assertSame(0, $this->price_record_count($product_id));
    }
}

<?php
/**
 * Scheduled Refresh Component Test
 *
 * Exercises the WP-Cron-driven price refresh loop end-to-end: custom cron
 * schedules, schedule/unschedule/status bookkeeping, admin-user resolution
 * (configured admin option, settings-aware fallback, no-admin bail-out),
 * and the actual refresh run - real Product_Service::bulk_refresh() against
 * real DB rows, with the Creators API faked via creators-api-mock.php.
 *
 * The env-var credential fallback (Product_Service::get_user_settings() via
 * Env_File) is suppressed for every test here: admin-resolution tests below
 * distinguish "picked the admin WITH settings" from "picked one without" by
 * whether the refresh succeeds, and a developer's local .env would otherwise
 * make the without-settings outcome succeed too.
 *
 * @package AmazonPriceTracker\Tests\Component
 */

use APT\Helpers\Encryption;
use APT\Services\Scheduled_Refresh;

require_once __DIR__ . '/creators-api-mock.php';

/**
 * Test case for the scheduled refresh service.
 */
class Test_Scheduled_Refresh_Component extends WP_UnitTestCase {

    /**
     * @var string|false
     */
    private $previous_error_log;

    /**
     * @var array<string, string|false>
     */
    private array $previous_env = [];

    public function setUp(): void {
        parent::setUp();

        // The service's log() calls error_log() whenever WP_DEBUG is on
        // (always true in the test env) - keep it off the PHPUnit output.
        $this->previous_error_log = ini_set('error_log', '/dev/null');
        $this->previous_env = apt_test_suppress_credential_env_fallback();
    }

    public function tearDown(): void {
        apt_test_reset_creators_api_responses();
        apt_test_restore_credential_env_fallback($this->previous_env);

        Scheduled_Refresh::unschedule();
        delete_option('apt_refresh_schedule');
        delete_option('apt_refresh_batch_size');
        delete_option('apt_scheduled_refresh_admin_id');
        delete_option(Scheduled_Refresh::LAST_REFRESH_OPTION);

        if ($this->previous_error_log !== false) {
            ini_set('error_log', $this->previous_error_log);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Fixture helpers
    // ------------------------------------------------------------------

    /**
     * Remove all product/price rows so run counts aren't coupled to leftovers
     * from other test files - a scheduled run with no filters processes every
     * active product in the table. Runs inside this test's transaction, which
     * WP_UnitTestCase rolls back afterwards (bulk_refresh, unlike
     * create_product, opens no transaction of its own, so nothing here leaks
     * past that rollback).
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

    private function insert_settings_for(int $user_id): void {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'apt_user_settings', [
            'user_id' => $user_id,
            'creators_credential_id' => Encryption::encrypt('test-credential-id'),
            'creators_credential_secret' => Encryption::encrypt('test-credential-secret'),
            'creators_credential_version' => '3.2',
            'partner_tags' => wp_json_encode(['UK' => 'test-partner-tag']),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);
    }

    /**
     * A canned single-item Creators API getItems body, shaped like what
     * bulk_refresh receives for a one-product region batch.
     */
    private function canned_item_body(string $asin, float $price = 24.99): array {
        return [
            'itemsResult' => [
                'items' => [
                    [
                        'asin' => $asin,
                        'itemInfo' => [
                            'title' => ['displayValue' => 'Scheduled Refresh Test Product'],
                        ],
                        'offersV2' => [
                            'listings' => [
                                [
                                    'price' => ['money' => ['amount' => $price]],
                                    'availability' => ['type' => 'IN_STOCK', 'message' => 'In stock'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Demote every administrator (including the test env's built-in user 1)
     * so get_admin_user_id() has nothing to find. Role changes are usermeta
     * writes, rolled back with the rest of the test transaction.
     */
    private function demote_all_admins(): void {
        foreach (get_users(['role' => 'administrator', 'fields' => 'ID']) as $admin_id) {
            (new WP_User($admin_id))->set_role('subscriber');
        }
    }

    // ------------------------------------------------------------------
    // Cron schedules and bookkeeping
    // ------------------------------------------------------------------

    public function test_custom_cron_intervals_are_registered(): void {
        // init() is wired during plugin bootstrap, so the live filter output
        // must contain both custom intervals.
        $schedules = wp_get_schedules();

        $this->assertSame(6 * HOUR_IN_SECONDS, $schedules['apt_six_hours']['interval']);
        $this->assertSame(12 * HOUR_IN_SECONDS, $schedules['apt_twelve_hours']['interval']);
    }

    public function test_schedule_registers_the_cron_event_and_stores_the_recurrence(): void {
        $this->assertFalse(Scheduled_Refresh::is_scheduled());
        $this->assertFalse(Scheduled_Refresh::get_next_run());
        $this->assertNull(Scheduled_Refresh::get_schedule());

        Scheduled_Refresh::schedule('apt_six_hours');

        $this->assertTrue(Scheduled_Refresh::is_scheduled());
        $this->assertIsInt(Scheduled_Refresh::get_next_run());
        $this->assertSame('apt_six_hours', wp_get_schedule(Scheduled_Refresh::CRON_HOOK));
        $this->assertSame('apt_six_hours', Scheduled_Refresh::get_schedule());
    }

    public function test_rescheduling_replaces_the_existing_event_instead_of_stacking(): void {
        Scheduled_Refresh::schedule('daily');
        Scheduled_Refresh::schedule('hourly');

        $this->assertSame('hourly', wp_get_schedule(Scheduled_Refresh::CRON_HOOK));
        $this->assertSame('hourly', Scheduled_Refresh::get_schedule());

        $occurrences = 0;
        foreach (_get_cron_array() as $hooks) {
            if (isset($hooks[Scheduled_Refresh::CRON_HOOK])) {
                $occurrences += count($hooks[Scheduled_Refresh::CRON_HOOK]);
            }
        }
        $this->assertSame(1, $occurrences);
    }

    public function test_unschedule_clears_the_cron_event(): void {
        Scheduled_Refresh::schedule();
        $this->assertTrue(Scheduled_Refresh::is_scheduled());

        Scheduled_Refresh::unschedule();

        $this->assertFalse(Scheduled_Refresh::is_scheduled());
        $this->assertFalse(Scheduled_Refresh::get_next_run());
    }

    public function test_get_status_when_unscheduled(): void {
        $this->assertSame(
            [
                'is_scheduled' => false,
                'schedule' => null,
                'next_run' => null,
                'next_run_human' => null,
                'last_refresh' => null,
            ],
            Scheduled_Refresh::get_status()
        );
    }

    public function test_get_status_when_scheduled(): void {
        Scheduled_Refresh::schedule('twicedaily');

        $status = Scheduled_Refresh::get_status();

        $this->assertTrue($status['is_scheduled']);
        $this->assertSame('twicedaily', $status['schedule']);
        $this->assertSame(gmdate('c', Scheduled_Refresh::get_next_run()), $status['next_run']);
        $this->assertNotEmpty($status['next_run_human']);
    }

    // ------------------------------------------------------------------
    // The refresh run
    // ------------------------------------------------------------------

    public function test_run_refreshes_products_and_records_the_summary(): void {
        global $wpdb;

        $this->wipe_product_tables();
        $this->insert_settings_for(1); // Built-in admin user.
        $asin = 'B0SCHEDRF1';
        $product_id = $this->insert_product($asin);
        apt_test_queue_creators_api_response(200, $this->canned_item_body($asin));

        $this->assertNull(Scheduled_Refresh::get_last_refresh());

        Scheduled_Refresh::run_scheduled_refresh();

        $last = Scheduled_Refresh::get_last_refresh();
        $this->assertSame(1, $last['success_count']);
        $this->assertSame(0, $last['failure_count']);
        $this->assertSame(Scheduled_Refresh::DEFAULT_BATCH_SIZE, $last['batch_size']);
        $this->assertNotEmpty($last['timestamp']);
        $this->assertIsNumeric($last['duration_seconds']);

        // The run must have written a fresh price record, not just bookkeeping.
        $price = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apt_price_history WHERE product_id = %d",
            $product_id
        ));
        $this->assertNotNull($price);
        $this->assertEquals(24.99, $price->current_price);
        $this->assertSame('in_stock', $price->availability);
    }

    public function test_cron_hook_triggers_the_run(): void {
        $this->wipe_product_tables();
        $this->insert_settings_for(1);

        // No products to refresh - the run still executes and records an
        // all-zero summary, which is exactly what proves the hook wiring.
        do_action(Scheduled_Refresh::CRON_HOOK);

        $last = Scheduled_Refresh::get_last_refresh();
        $this->assertIsArray($last);
        $this->assertSame(0, $last['success_count']);
        $this->assertSame(0, $last['failure_count']);
    }

    public function test_run_respects_the_batch_size_option(): void {
        $this->wipe_product_tables();
        $this->insert_settings_for(1);
        update_option('apt_refresh_batch_size', 1);

        // The stalest product (oldest updated_at) wins the single batch slot.
        $stale_asin = 'B0SCHSTALE';
        $this->insert_product($stale_asin, ['updated_at' => gmdate('Y-m-d H:i:s', strtotime('-2 days'))]);
        $this->insert_product('B0SCHFRESH');
        apt_test_queue_creators_api_response(200, $this->canned_item_body($stale_asin));

        Scheduled_Refresh::run_scheduled_refresh();

        $last = Scheduled_Refresh::get_last_refresh();
        $this->assertSame(1, $last['batch_size']);
        $this->assertSame(1, $last['success_count']);
        $this->assertSame(0, $last['failure_count'], 'Only one product should have been selected for the batch at all.');
    }

    // ------------------------------------------------------------------
    // Admin user resolution
    // ------------------------------------------------------------------

    public function test_run_bails_out_when_no_admin_user_exists(): void {
        $this->wipe_product_tables();
        $this->insert_product('B0SCHNOADM');
        $this->demote_all_admins();

        Scheduled_Refresh::run_scheduled_refresh();

        $this->assertNull(
            Scheduled_Refresh::get_last_refresh(),
            'The run must bail before refreshing anything when no admin exists.'
        );
    }

    public function test_run_prefers_the_configured_admin_over_the_settings_fallback(): void {
        $this->wipe_product_tables();
        $asin = 'B0SCHCONF1';
        $this->insert_product($asin);

        // An admin WITH settings exists, but the configured option points at
        // one WITHOUT settings. If the configured admin wins (as it must),
        // the refresh runs unconfigured and fails; if the fallback were used
        // instead, the queued response would make it succeed.
        $admin_with_settings = self::factory()->user->create(['role' => 'administrator']);
        $this->insert_settings_for($admin_with_settings);
        $configured_admin = self::factory()->user->create(['role' => 'administrator']);
        update_option('apt_scheduled_refresh_admin_id', $configured_admin);
        apt_test_queue_creators_api_response(200, $this->canned_item_body($asin));

        Scheduled_Refresh::run_scheduled_refresh();

        $last = Scheduled_Refresh::get_last_refresh();
        $this->assertSame(0, $last['success_count']);
        $this->assertSame(1, $last['failure_count']);
    }

    public function test_run_ignores_a_configured_user_who_is_not_an_admin(): void {
        $this->wipe_product_tables();
        $asin = 'B0SCHNONAD';
        $this->insert_product($asin);

        // The configured option points at a subscriber, so it must be
        // rejected and the settings-aware fallback used instead - which
        // finds the admin with settings and succeeds via the queued mock.
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        update_option('apt_scheduled_refresh_admin_id', $subscriber);
        $admin_with_settings = self::factory()->user->create(['role' => 'administrator']);
        $this->insert_settings_for($admin_with_settings);
        apt_test_queue_creators_api_response(200, $this->canned_item_body($asin));

        Scheduled_Refresh::run_scheduled_refresh();

        $last = Scheduled_Refresh::get_last_refresh();
        $this->assertSame(1, $last['success_count']);
        $this->assertSame(0, $last['failure_count']);
    }

    public function test_run_falls_back_to_an_admin_with_settings_skipping_those_without(): void {
        $this->wipe_product_tables();
        $asin = 'B0SCHFALLB';
        $this->insert_product($asin);

        // The built-in admin (user 1, no settings) sorts first in the
        // fallback's admin list; the loop must skip past it to the admin
        // that actually has a settings row - proven by the run succeeding.
        $admin_with_settings = self::factory()->user->create(['role' => 'administrator']);
        $this->insert_settings_for($admin_with_settings);
        apt_test_queue_creators_api_response(200, $this->canned_item_body($asin));

        Scheduled_Refresh::run_scheduled_refresh();

        $last = Scheduled_Refresh::get_last_refresh();
        $this->assertSame(1, $last['success_count']);
        $this->assertSame(0, $last['failure_count']);
    }

    public function test_run_uses_the_first_admin_when_none_have_settings(): void {
        $this->wipe_product_tables();
        $this->insert_product('B0SCHNOSET');

        // No admin has a settings row (and the env fallback is suppressed),
        // so the run proceeds with the first admin and fails gracefully:
        // a recorded summary with failures, not a bail-out or a crash.
        Scheduled_Refresh::run_scheduled_refresh();

        $last = Scheduled_Refresh::get_last_refresh();
        $this->assertIsArray($last);
        $this->assertSame(0, $last['success_count']);
        $this->assertSame(1, $last['failure_count']);
    }
}

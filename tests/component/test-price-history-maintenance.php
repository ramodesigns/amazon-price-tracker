<?php
/**
 * Price History Maintenance Component Test
 *
 * Exercises the tiered price-history retention logic against the real test
 * database: the untouched full-granularity window, daily/weekly/monthly
 * collapsing, milestone preservation (baseline record, all-time low/high,
 * availability changes), inactive-product exclusion, cron scheduling, and
 * storage statistics. No HTTP involved - this service is pure DB logic.
 *
 * Fixture rows are inserted directly via $wpdb (no Product_Service, so no
 * leaked transactions), but tearDown still deletes tracked rows explicitly
 * to stay robust against anything that escapes the test rollback.
 *
 * @package AmazonPriceTracker\Tests\Component
 */

use APT\Services\Price_History_Maintenance;

/**
 * Test case for the price history retention service.
 */
class Test_Price_History_Maintenance_Component extends WP_UnitTestCase {

    /**
     * Product rows created by this test, cleaned up in tearDown.
     *
     * @var int[]
     */
    private array $product_ids = [];

    /**
     * @var string|false
     */
    private $previous_error_log;

    public function setUp(): void {
        parent::setUp();

        // WP_DEBUG is on in the test env, so the service's log() calls would
        // otherwise splatter error_log output across the PHPUnit progress bar.
        $this->previous_error_log = ini_set('error_log', '/dev/null');
    }

    public function tearDown(): void {
        global $wpdb;

        foreach ($this->product_ids as $product_id) {
            $wpdb->delete($wpdb->prefix . 'apt_price_history', ['product_id' => $product_id]);
            $wpdb->delete($wpdb->prefix . 'apt_products', ['id' => $product_id]);
        }
        $this->product_ids = [];

        delete_option('apt_history_full_retention');
        delete_option('apt_history_daily_retention');
        delete_option('apt_history_weekly_retention');
        delete_option(Price_History_Maintenance::LAST_RUN_OPTION);

        Price_History_Maintenance::unschedule();

        if ($this->previous_error_log !== false) {
            ini_set('error_log', $this->previous_error_log);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Fixture helpers
    // ------------------------------------------------------------------

    private function insert_product(int $is_active = 1): int {
        global $wpdb;

        // 10-char ASIN (VARCHAR(10) column), random to dodge the unique
        // asin_region key across tests.
        $asin = 'B0PH' . strtoupper(substr(md5(uniqid('', true)), 0, 6));

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'apt_products',
            [
                'asin' => $asin,
                'region' => 'UK',
                'is_active' => $is_active,
                'created_by' => 1,
            ],
            ['%s', '%s', '%d', '%d']
        );
        $this->assertNotFalse($inserted, 'Product fixture insert failed: ' . $wpdb->last_error);

        $product_id = (int) $wpdb->insert_id;
        $this->product_ids[] = $product_id;

        return $product_id;
    }

    private function insert_price_record(int $product_id, string $recorded_at, float $price, string $availability = 'in_stock'): int {
        global $wpdb;

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'apt_price_history',
            [
                'product_id' => $product_id,
                'current_price' => $price,
                'availability' => $availability,
                'recorded_at' => $recorded_at,
            ],
            ['%d', '%f', '%s', '%s']
        );
        $this->assertNotFalse($inserted, 'Price record fixture insert failed: ' . $wpdb->last_error);

        return (int) $wpdb->insert_id;
    }

    /**
     * A UTC datetime N days ago at the given time, matching the gmdate()
     * cutoffs the service computes.
     */
    private function days_ago(int $days, string $time = '12:00:00'): string {
        return gmdate('Y-m-d', strtotime("-{$days} days")) . ' ' . $time;
    }

    /**
     * A UTC datetime on a specific ISO weekday (1=Mon..7=Sun) of the week
     * containing the day N days ago. Avoids strtotime's ambiguous
     * "monday this week" semantics so two calls with the same anchor are
     * guaranteed to land in the same YEARWEEK(..., 1) bucket.
     */
    private function iso_week_day(int $days_ago_anchor, int $iso_day, string $time = '09:00:00'): string {
        $anchor = strtotime("-{$days_ago_anchor} days");
        $current_dow = (int) gmdate('N', $anchor);
        $target = $anchor + (($iso_day - $current_dow) * DAY_IN_SECONDS);

        return gmdate('Y-m-d', $target) . ' ' . $time;
    }

    /**
     * A UTC datetime on a specific day-of-month in the month containing the
     * day N days ago.
     */
    private function same_month_day(int $days_ago_anchor, int $day_of_month, string $time = '09:00:00'): string {
        $month = gmdate('Y-m', strtotime("-{$days_ago_anchor} days"));

        return sprintf('%s-%02d %s', $month, $day_of_month, $time);
    }

    /**
     * Surviving price-history record IDs for a product, oldest first.
     *
     * @return int[]
     */
    private function remaining_ids(int $product_id): array {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}apt_price_history WHERE product_id = %d ORDER BY recorded_at ASC, id ASC",
            $product_id
        )));
    }

    /**
     * Recent records holding the all-time lowest and highest prices, parked
     * inside the always-protected 0-30 day window so those two milestones
     * never interfere with the collapsing behaviour under test.
     *
     * @return int[] [lowest_id, highest_id]
     */
    private function insert_price_extreme_anchors(int $product_id): array {
        return [
            $this->insert_price_record($product_id, $this->days_ago(2), 1.00),
            $this->insert_price_record($product_id, $this->days_ago(1), 999.99),
        ];
    }

    // ------------------------------------------------------------------
    // Cron scheduling
    // ------------------------------------------------------------------

    public function test_schedule_registers_a_weekly_cron_event(): void {
        $this->assertFalse(Price_History_Maintenance::is_scheduled());
        $this->assertFalse(Price_History_Maintenance::get_next_run());

        Price_History_Maintenance::schedule();

        $this->assertTrue(Price_History_Maintenance::is_scheduled());
        $this->assertIsInt(Price_History_Maintenance::get_next_run());
        $this->assertSame('weekly', wp_get_schedule(Price_History_Maintenance::CRON_HOOK));
    }

    public function test_schedule_is_idempotent(): void {
        Price_History_Maintenance::schedule();
        $first_run = Price_History_Maintenance::get_next_run();

        Price_History_Maintenance::schedule();

        $this->assertSame($first_run, Price_History_Maintenance::get_next_run());

        $occurrences = 0;
        foreach (_get_cron_array() as $hooks) {
            if (isset($hooks[Price_History_Maintenance::CRON_HOOK])) {
                $occurrences += count($hooks[Price_History_Maintenance::CRON_HOOK]);
            }
        }
        $this->assertSame(1, $occurrences);
    }

    public function test_unschedule_clears_the_cron_event(): void {
        Price_History_Maintenance::schedule();
        $this->assertTrue(Price_History_Maintenance::is_scheduled());

        Price_History_Maintenance::unschedule();

        $this->assertFalse(Price_History_Maintenance::is_scheduled());
        $this->assertFalse(Price_History_Maintenance::get_next_run());
    }

    // ------------------------------------------------------------------
    // Retention tiers
    // ------------------------------------------------------------------

    public function test_records_within_the_full_retention_window_are_never_pruned(): void {
        $product_id = $this->insert_product();

        // Three records on the same recent day - these would collapse to one
        // if the daily tier ever reached inside the full-granularity window.
        $ids = [
            $this->insert_price_record($product_id, $this->days_ago(5, '08:00:00'), 10.00),
            $this->insert_price_record($product_id, $this->days_ago(5, '12:00:00'), 11.00),
            $this->insert_price_record($product_id, $this->days_ago(5, '16:00:00'), 12.00),
        ];

        (new Price_History_Maintenance())->prune_history();

        $this->assertSame($ids, $this->remaining_ids($product_id));
    }

    public function test_daily_retention_keeps_only_the_first_record_per_day(): void {
        $product_id = $this->insert_product();

        // Three records on one day in the 30-90 day tier. The 08:00 record is
        // also the product's baseline (first-ever) milestone, which coincides
        // with the record the daily tier would keep anyway.
        $keeper = $this->insert_price_record($product_id, $this->days_ago(40, '08:00:00'), 10.00);
        $this->insert_price_record($product_id, $this->days_ago(40, '12:00:00'), 11.00);
        $this->insert_price_record($product_id, $this->days_ago(40, '16:00:00'), 12.00);
        [$lowest, $highest] = $this->insert_price_extreme_anchors($product_id);

        $maintenance = new Price_History_Maintenance();
        $maintenance->prune_history();

        $this->assertSame([$keeper, $lowest, $highest], $this->remaining_ids($product_id));

        // Maintenance is idempotent: a second pass finds nothing to delete.
        $second = $maintenance->prune_history();
        $this->assertSame(0, $second['total_pruned']);
        $this->assertSame([$keeper, $lowest, $highest], $this->remaining_ids($product_id));
    }

    public function test_weekly_retention_keeps_only_the_first_record_per_week(): void {
        $product_id = $this->insert_product();

        // Tuesday and Thursday of the same ISO week in the 90-365 day tier.
        $keeper = $this->insert_price_record($product_id, $this->iso_week_day(120, 2), 10.00);
        $this->insert_price_record($product_id, $this->iso_week_day(120, 4), 11.00);
        [$lowest, $highest] = $this->insert_price_extreme_anchors($product_id);

        (new Price_History_Maintenance())->prune_history();

        $this->assertSame([$keeper, $lowest, $highest], $this->remaining_ids($product_id));
    }

    public function test_monthly_retention_keeps_only_the_first_record_per_month(): void {
        $product_id = $this->insert_product();

        // The 5th and 20th of the same calendar month, over a year ago.
        $keeper = $this->insert_price_record($product_id, $this->same_month_day(400, 5), 10.00);
        $this->insert_price_record($product_id, $this->same_month_day(400, 20), 11.00);
        [$lowest, $highest] = $this->insert_price_extreme_anchors($product_id);

        (new Price_History_Maintenance())->prune_history();

        $this->assertSame([$keeper, $lowest, $highest], $this->remaining_ids($product_id));
    }

    public function test_custom_retention_options_override_the_defaults(): void {
        update_option('apt_history_full_retention', 10);

        $product_id = $this->insert_product();

        // A 15-day-old trio sits safely inside the default 30-day full window,
        // but with the window shortened to 10 days it collapses to one per day.
        $keeper = $this->insert_price_record($product_id, $this->days_ago(15, '08:00:00'), 10.00);
        $this->insert_price_record($product_id, $this->days_ago(15, '12:00:00'), 11.00);
        [$lowest, $highest] = $this->insert_price_extreme_anchors($product_id);

        (new Price_History_Maintenance())->prune_history();

        $this->assertSame([$keeper, $lowest, $highest], $this->remaining_ids($product_id));
    }

    // ------------------------------------------------------------------
    // Milestone preservation
    // ------------------------------------------------------------------

    public function test_all_time_price_milestones_survive_pruning(): void {
        $product_id = $this->insert_product();

        // All three records share a prunable day. The all-time lowest and
        // highest both survive even though the highest is NOT the first
        // record of its day - milestone protection beats the one-per-day rule.
        $lowest = $this->insert_price_record($product_id, $this->days_ago(40, '08:00:00'), 1.00);
        $middle = $this->insert_price_record($product_id, $this->days_ago(40, '12:00:00'), 10.00);
        $highest = $this->insert_price_record($product_id, $this->days_ago(40, '16:00:00'), 999.99);

        (new Price_History_Maintenance())->prune_history();

        $this->assertSame([$lowest, $highest], $this->remaining_ids($product_id));
        $this->assertNotContains($middle, $this->remaining_ids($product_id));
    }

    public function test_availability_change_records_survive_pruning(): void {
        $product_id = $this->insert_product();

        // Same ISO week in the weekly tier, all at the same price so no
        // low/high milestone lands on the later records. Wednesday flips
        // availability, so it must survive; Friday repeats Wednesday's
        // availability, so it is an ordinary duplicate and gets pruned.
        $tuesday = $this->insert_price_record($product_id, $this->iso_week_day(120, 2), 10.00, 'in_stock');
        $wednesday = $this->insert_price_record($product_id, $this->iso_week_day(120, 3), 10.00, 'out_of_stock');
        $friday = $this->insert_price_record($product_id, $this->iso_week_day(120, 5), 10.00, 'out_of_stock');

        (new Price_History_Maintenance())->prune_history();

        $remaining = $this->remaining_ids($product_id);
        $this->assertSame([$tuesday, $wednesday], $remaining);
        $this->assertNotContains($friday, $remaining);
    }

    // ------------------------------------------------------------------
    // Scope and reporting
    // ------------------------------------------------------------------

    public function test_inactive_products_are_not_pruned(): void {
        $product_id = $this->insert_product(0);

        $ids = [
            $this->insert_price_record($product_id, $this->days_ago(40, '08:00:00'), 10.00),
            $this->insert_price_record($product_id, $this->days_ago(40, '12:00:00'), 11.00),
            $this->insert_price_record($product_id, $this->days_ago(40, '16:00:00'), 12.00),
        ];

        $result = (new Price_History_Maintenance())->prune_history();

        $this->assertSame($ids, $this->remaining_ids($product_id));
        $this->assertSame(0, $result['products_processed']);
    }

    public function test_prune_history_reports_accurate_counts(): void {
        $product_id = $this->insert_product();

        $this->insert_price_record($product_id, $this->days_ago(40, '08:00:00'), 10.00);
        $this->insert_price_record($product_id, $this->days_ago(40, '12:00:00'), 11.00);
        $this->insert_price_record($product_id, $this->days_ago(40, '16:00:00'), 12.00);
        $this->insert_price_extreme_anchors($product_id);

        $result = (new Price_History_Maintenance())->prune_history();

        $this->assertSame(2, $result['total_pruned']);
        $this->assertSame(1, $result['products_processed']);
        // Baseline (40-day 08:00), all-time lowest and all-time highest.
        $this->assertSame(3, $result['milestones_preserved']);
    }

    // ------------------------------------------------------------------
    // Entry points and last-run bookkeeping
    // ------------------------------------------------------------------

    public function test_run_maintenance_records_the_last_run_summary(): void {
        $this->assertNull(Price_History_Maintenance::get_last_run());

        $product_id = $this->insert_product();
        $this->insert_price_record($product_id, $this->days_ago(40, '08:00:00'), 10.00);
        $this->insert_price_record($product_id, $this->days_ago(40, '12:00:00'), 11.00);
        $this->insert_price_extreme_anchors($product_id);

        Price_History_Maintenance::run_maintenance();

        $last_run = Price_History_Maintenance::get_last_run();
        $this->assertIsArray($last_run);
        $this->assertSame(1, $last_run['records_pruned']);
        $this->assertSame(1, $last_run['products_processed']);
        $this->assertSame(3, $last_run['milestones_preserved']);
        $this->assertNotEmpty($last_run['timestamp']);
        $this->assertArrayNotHasKey('manual', $last_run);
    }

    public function test_cron_hook_triggers_maintenance(): void {
        // init() is wired during plugin bootstrap, so firing the cron hook
        // must run maintenance exactly as WP-Cron would.
        $this->assertNull(Price_History_Maintenance::get_last_run());

        do_action(Price_History_Maintenance::CRON_HOOK);

        $this->assertIsArray(Price_History_Maintenance::get_last_run());
    }

    public function test_run_manual_flags_the_last_run_as_manual(): void {
        $product_id = $this->insert_product();
        $this->insert_price_record($product_id, $this->days_ago(40, '08:00:00'), 10.00);
        $this->insert_price_record($product_id, $this->days_ago(40, '12:00:00'), 11.00);
        $this->insert_price_extreme_anchors($product_id);

        $result = (new Price_History_Maintenance())->run_manual();

        $this->assertSame(1, $result['total_pruned']);

        $last_run = Price_History_Maintenance::get_last_run();
        $this->assertTrue($last_run['manual']);
    }

    // ------------------------------------------------------------------
    // Storage statistics
    // ------------------------------------------------------------------

    public function test_get_storage_stats_buckets_records_by_age(): void {
        $maintenance = new Price_History_Maintenance();
        // Delta-based so pre-existing rows in the shared test DB can't skew
        // the assertions.
        $before = $maintenance->get_storage_stats();

        $product_id = $this->insert_product();
        $this->insert_price_record($product_id, $this->days_ago(5), 10.00);
        $this->insert_price_record($product_id, $this->days_ago(50), 10.00);
        $this->insert_price_record($product_id, $this->days_ago(200), 10.00);
        $this->insert_price_record($product_id, $this->days_ago(400), 10.00);

        $after = $maintenance->get_storage_stats();

        $this->assertSame($before['total_records'] + 4, $after['total_records']);
        $this->assertSame($before['records_0_30_days'] + 1, $after['records_0_30_days']);
        $this->assertSame($before['records_30_90_days'] + 1, $after['records_30_90_days']);
        $this->assertSame($before['records_90_365_days'] + 1, $after['records_90_365_days']);
        $this->assertSame($before['records_over_1_year'] + 1, $after['records_over_1_year']);
    }
}

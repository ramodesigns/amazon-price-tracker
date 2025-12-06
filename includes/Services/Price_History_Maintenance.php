<?php
/**
 * Price History Maintenance Service
 *
 * Handles tiered retention of price history data to balance
 * storage efficiency with historical trend analysis.
 *
 * Retention Strategy:
 * - 0-30 days: Keep ALL records (full granularity)
 * - 30-90 days: Keep 1 record per day (daily snapshot)
 * - 90-365 days: Keep 1 record per week (weekly snapshot)
 * - 1+ years: Keep 1 record per month (monthly snapshot)
 *
 * Always preserved (never deleted):
 * - All-time lowest price record
 * - All-time highest price record
 * - First recorded price (baseline)
 * - Records where availability changed
 *
 * @package AmazonPriceTracker
 */

namespace APT\Services;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Price_History_Maintenance
 */
class Price_History_Maintenance {

    /**
     * Cron hook name
     */
    public const CRON_HOOK = 'apt_price_history_maintenance';

    /**
     * Option name for last maintenance run
     */
    public const LAST_RUN_OPTION = 'apt_last_history_maintenance';

    /**
     * Default retention periods (days)
     */
    public const DEFAULT_FULL_RETENTION = 30;
    public const DEFAULT_DAILY_RETENTION = 90;
    public const DEFAULT_WEEKLY_RETENTION = 365;

    /**
     * WordPress database instance
     *
     * @var \wpdb
     */
    private \wpdb $db;

    /**
     * Price history table name
     *
     * @var string
     */
    private string $prices_table;

    /**
     * Products table name
     *
     * @var string
     */
    private string $products_table;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->prices_table = $wpdb->prefix . 'apt_price_history';
        $this->products_table = $wpdb->prefix . 'apt_products';
    }

    /**
     * Initialize the maintenance service
     */
    public static function init(): void {
        add_action(self::CRON_HOOK, [self::class, 'run_maintenance']);
    }

    /**
     * Schedule the maintenance cron job (weekly)
     */
    public static function schedule(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'weekly', self::CRON_HOOK);
        }
    }

    /**
     * Unschedule the maintenance cron job
     */
    public static function unschedule(): void {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Check if maintenance is scheduled
     *
     * @return bool
     */
    public static function is_scheduled(): bool {
        return (bool) wp_next_scheduled(self::CRON_HOOK);
    }

    /**
     * Get next scheduled run time
     *
     * @return int|false
     */
    public static function get_next_run() {
        return wp_next_scheduled(self::CRON_HOOK);
    }

    /**
     * Run the maintenance task (called by WP-Cron)
     */
    public static function run_maintenance(): void {
        $instance = new self();
        $result = $instance->prune_history();

        update_option(self::LAST_RUN_OPTION, [
            'timestamp' => current_time('mysql', true),
            'records_pruned' => $result['total_pruned'],
            'products_processed' => $result['products_processed'],
            'milestones_preserved' => $result['milestones_preserved'],
        ]);

        self::log(sprintf(
            'Price history maintenance completed: %d records pruned from %d products, %d milestones preserved',
            $result['total_pruned'],
            $result['products_processed'],
            $result['milestones_preserved']
        ));
    }

    /**
     * Get last maintenance run info
     *
     * @return array|null
     */
    public static function get_last_run(): ?array {
        return get_option(self::LAST_RUN_OPTION, null);
    }

    /**
     * Prune price history according to retention policy
     *
     * @return array Results with counts
     */
    public function prune_history(): array {
        $full_retention = (int) get_option('apt_history_full_retention', self::DEFAULT_FULL_RETENTION);
        $daily_retention = (int) get_option('apt_history_daily_retention', self::DEFAULT_DAILY_RETENTION);
        $weekly_retention = (int) get_option('apt_history_weekly_retention', self::DEFAULT_WEEKLY_RETENTION);

        // Get all active products
        $products = $this->db->get_results(
            "SELECT id FROM {$this->products_table} WHERE is_active = 1"
        );

        $total_pruned = 0;
        $products_processed = 0;
        $milestones_preserved = 0;

        foreach ($products as $product) {
            $result = $this->prune_product_history(
                (int) $product->id,
                $full_retention,
                $daily_retention,
                $weekly_retention
            );

            $total_pruned += $result['pruned'];
            $milestones_preserved += $result['milestones'];
            $products_processed++;
        }

        return [
            'total_pruned' => $total_pruned,
            'products_processed' => $products_processed,
            'milestones_preserved' => $milestones_preserved,
        ];
    }

    /**
     * Prune history for a single product
     *
     * @param int $product_id Product ID
     * @param int $full_days Days to keep full granularity
     * @param int $daily_days Days to keep daily snapshots
     * @param int $weekly_days Days to keep weekly snapshots
     * @return array Results
     */
    private function prune_product_history(int $product_id, int $full_days, int $daily_days, int $weekly_days): array {
        // Get milestone record IDs that must be preserved
        $milestone_ids = $this->get_milestone_record_ids($product_id);
        $milestones_count = count($milestone_ids);

        $pruned = 0;

        // Phase 1: Daily retention (keep 1 per day for records older than $full_days but newer than $daily_days)
        $pruned += $this->apply_daily_retention($product_id, $full_days, $daily_days, $milestone_ids);

        // Phase 2: Weekly retention (keep 1 per week for records older than $daily_days but newer than $weekly_days)
        $pruned += $this->apply_weekly_retention($product_id, $daily_days, $weekly_days, $milestone_ids);

        // Phase 3: Monthly retention (keep 1 per month for records older than $weekly_days)
        $pruned += $this->apply_monthly_retention($product_id, $weekly_days, $milestone_ids);

        return [
            'pruned' => $pruned,
            'milestones' => $milestones_count,
        ];
    }

    /**
     * Get milestone record IDs that must never be deleted
     *
     * @param int $product_id Product ID
     * @return array Record IDs to preserve
     */
    private function get_milestone_record_ids(int $product_id): array {
        $ids = [];

        // First record (baseline)
        $first = $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->prices_table}
             WHERE product_id = %d
             ORDER BY recorded_at ASC
             LIMIT 1",
            $product_id
        ));
        if ($first) {
            $ids[] = (int) $first;
        }

        // All-time lowest price (non-null)
        $lowest = $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->prices_table}
             WHERE product_id = %d AND current_price IS NOT NULL
             ORDER BY current_price ASC, recorded_at ASC
             LIMIT 1",
            $product_id
        ));
        if ($lowest) {
            $ids[] = (int) $lowest;
        }

        // All-time highest price (non-null)
        $highest = $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->prices_table}
             WHERE product_id = %d AND current_price IS NOT NULL
             ORDER BY current_price DESC, recorded_at ASC
             LIMIT 1",
            $product_id
        ));
        if ($highest) {
            $ids[] = (int) $highest;
        }

        // Records where availability changed (keep the "after" record)
        $availability_changes = $this->db->get_col($this->db->prepare(
            "SELECT ph1.id FROM {$this->prices_table} ph1
             INNER JOIN {$this->prices_table} ph2 ON ph1.product_id = ph2.product_id
             WHERE ph1.product_id = %d
             AND ph2.recorded_at = (
                 SELECT MAX(ph3.recorded_at)
                 FROM {$this->prices_table} ph3
                 WHERE ph3.product_id = ph1.product_id
                 AND ph3.recorded_at < ph1.recorded_at
             )
             AND ph1.availability != ph2.availability",
            $product_id
        ));

        foreach ($availability_changes as $id) {
            $ids[] = (int) $id;
        }

        return array_unique($ids);
    }

    /**
     * Apply daily retention policy
     *
     * @param int $product_id Product ID
     * @param int $start_days Start of daily retention period (days ago)
     * @param int $end_days End of daily retention period (days ago)
     * @param array $protected_ids IDs to never delete
     * @return int Number of records deleted
     */
    private function apply_daily_retention(int $product_id, int $start_days, int $end_days, array $protected_ids): int {
        $start_date = gmdate('Y-m-d H:i:s', strtotime("-{$start_days} days"));
        $end_date = gmdate('Y-m-d H:i:s', strtotime("-{$end_days} days"));

        // Get records in this date range, grouped by day
        $records = $this->db->get_results($this->db->prepare(
            "SELECT id, DATE(recorded_at) as record_date, recorded_at
             FROM {$this->prices_table}
             WHERE product_id = %d
             AND recorded_at < %s
             AND recorded_at >= %s
             ORDER BY recorded_at ASC",
            $product_id,
            $start_date,
            $end_date
        ));

        return $this->delete_duplicates_keeping_first_per_period($records, 'record_date', $protected_ids);
    }

    /**
     * Apply weekly retention policy
     *
     * @param int $product_id Product ID
     * @param int $start_days Start of weekly retention period (days ago)
     * @param int $end_days End of weekly retention period (days ago)
     * @param array $protected_ids IDs to never delete
     * @return int Number of records deleted
     */
    private function apply_weekly_retention(int $product_id, int $start_days, int $end_days, array $protected_ids): int {
        $start_date = gmdate('Y-m-d H:i:s', strtotime("-{$start_days} days"));
        $end_date = gmdate('Y-m-d H:i:s', strtotime("-{$end_days} days"));

        // Get records in this date range, grouped by year-week
        $records = $this->db->get_results($this->db->prepare(
            "SELECT id, YEARWEEK(recorded_at, 1) as record_week, recorded_at
             FROM {$this->prices_table}
             WHERE product_id = %d
             AND recorded_at < %s
             AND recorded_at >= %s
             ORDER BY recorded_at ASC",
            $product_id,
            $start_date,
            $end_date
        ));

        return $this->delete_duplicates_keeping_first_per_period($records, 'record_week', $protected_ids);
    }

    /**
     * Apply monthly retention policy
     *
     * @param int $product_id Product ID
     * @param int $start_days Start of monthly retention period (days ago)
     * @param array $protected_ids IDs to never delete
     * @return int Number of records deleted
     */
    private function apply_monthly_retention(int $product_id, int $start_days, array $protected_ids): int {
        $start_date = gmdate('Y-m-d H:i:s', strtotime("-{$start_days} days"));

        // Get records older than start_date, grouped by year-month
        $records = $this->db->get_results($this->db->prepare(
            "SELECT id, DATE_FORMAT(recorded_at, '%%Y-%%m') as record_month, recorded_at
             FROM {$this->prices_table}
             WHERE product_id = %d
             AND recorded_at < %s
             ORDER BY recorded_at ASC",
            $product_id,
            $start_date
        ));

        return $this->delete_duplicates_keeping_first_per_period($records, 'record_month', $protected_ids);
    }

    /**
     * Delete duplicate records, keeping the first record per period
     *
     * @param array $records Records with id and period column
     * @param string $period_column Column name for the period grouping
     * @param array $protected_ids IDs to never delete
     * @return int Number of records deleted
     */
    private function delete_duplicates_keeping_first_per_period(array $records, string $period_column, array $protected_ids): int {
        if (empty($records)) {
            return 0;
        }

        $seen_periods = [];
        $ids_to_delete = [];

        foreach ($records as $record) {
            $period = $record->$period_column;

            if (in_array((int) $record->id, $protected_ids, true)) {
                // This is a protected milestone - keep it and mark period as seen
                $seen_periods[$period] = true;
                continue;
            }

            if (isset($seen_periods[$period])) {
                // Already have a record for this period, mark for deletion
                $ids_to_delete[] = (int) $record->id;
            } else {
                // First record for this period, keep it
                $seen_periods[$period] = true;
            }
        }

        if (empty($ids_to_delete)) {
            return 0;
        }

        // Delete in batches to avoid extremely long queries
        $batches = array_chunk($ids_to_delete, 500);
        $deleted = 0;

        foreach ($batches as $batch) {
            $placeholders = implode(', ', array_fill(0, count($batch), '%d'));
            $this->db->query($this->db->prepare(
                "DELETE FROM {$this->prices_table} WHERE id IN ({$placeholders})",
                ...$batch
            ));
            $deleted += $this->db->rows_affected;
        }

        return $deleted;
    }

    /**
     * Get statistics about current price history storage
     *
     * @return array Storage statistics
     */
    public function get_storage_stats(): array {
        // Total records
        $total_records = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prices_table}"
        );

        // Records by age
        $stats = [
            'total_records' => $total_records,
            'records_0_30_days' => 0,
            'records_30_90_days' => 0,
            'records_90_365_days' => 0,
            'records_over_1_year' => 0,
            'estimated_size_mb' => 0,
        ];

        $age_stats = $this->db->get_results(
            "SELECT
                SUM(CASE WHEN recorded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as last_30,
                SUM(CASE WHEN recorded_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) AND recorded_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as last_30_90,
                SUM(CASE WHEN recorded_at >= DATE_SUB(NOW(), INTERVAL 365 DAY) AND recorded_at < DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) as last_90_365,
                SUM(CASE WHEN recorded_at < DATE_SUB(NOW(), INTERVAL 365 DAY) THEN 1 ELSE 0 END) as over_1_year
             FROM {$this->prices_table}"
        );

        if ($age_stats && isset($age_stats[0])) {
            $stats['records_0_30_days'] = (int) $age_stats[0]->last_30;
            $stats['records_30_90_days'] = (int) $age_stats[0]->last_30_90;
            $stats['records_90_365_days'] = (int) $age_stats[0]->last_90_365;
            $stats['records_over_1_year'] = (int) $age_stats[0]->over_1_year;
        }

        // Estimate size (approximately 100 bytes per record)
        $stats['estimated_size_mb'] = round($total_records * 100 / 1024 / 1024, 2);

        return $stats;
    }

    /**
     * Run maintenance manually (for admin use)
     *
     * @return array Results
     */
    public function run_manual(): array {
        $result = $this->prune_history();

        update_option(self::LAST_RUN_OPTION, [
            'timestamp' => current_time('mysql', true),
            'records_pruned' => $result['total_pruned'],
            'products_processed' => $result['products_processed'],
            'milestones_preserved' => $result['milestones_preserved'],
            'manual' => true,
        ]);

        return $result;
    }

    /**
     * Log a message
     *
     * @param string $message Message to log
     */
    private static function log(string $message): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Amazon Price Tracker] ' . $message);
        }
    }
}

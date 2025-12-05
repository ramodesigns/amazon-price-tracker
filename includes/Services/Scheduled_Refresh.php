<?php
/**
 * Scheduled Refresh Service
 *
 * Handles automated price refresh via WP-Cron.
 *
 * @package AmazonPriceTracker
 */

namespace APT\Services;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Scheduled_Refresh
 */
class Scheduled_Refresh {

    /**
     * Cron hook name
     */
    public const CRON_HOOK = 'apt_scheduled_price_refresh';

    /**
     * Option name for storing last refresh info
     */
    public const LAST_REFRESH_OPTION = 'apt_last_scheduled_refresh';

    /**
     * Default batch size for scheduled refresh
     */
    public const DEFAULT_BATCH_SIZE = 50;

    /**
     * Initialize scheduled refresh
     */
    public static function init(): void {
        // Register the cron action
        add_action(self::CRON_HOOK, [self::class, 'run_scheduled_refresh']);

        // Register custom cron schedules
        add_filter('cron_schedules', [self::class, 'add_cron_schedules']);
    }

    /**
     * Add custom cron schedules
     *
     * @param array $schedules Existing schedules
     * @return array Modified schedules
     */
    public static function add_cron_schedules(array $schedules): array {
        // Every 6 hours
        $schedules['apt_six_hours'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display' => __('Every 6 Hours', 'amazon-price-tracker'),
        ];

        // Every 12 hours
        $schedules['apt_twelve_hours'] = [
            'interval' => 12 * HOUR_IN_SECONDS,
            'display' => __('Every 12 Hours', 'amazon-price-tracker'),
        ];

        return $schedules;
    }

    /**
     * Schedule the price refresh cron job
     *
     * @param string $recurrence Recurrence interval (hourly, twicedaily, daily, apt_six_hours, apt_twelve_hours)
     */
    public static function schedule(string $recurrence = 'twicedaily'): void {
        // Clear any existing schedule
        self::unschedule();

        // Schedule new cron job
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), $recurrence, self::CRON_HOOK);
        }

        // Store the schedule setting
        update_option('apt_refresh_schedule', $recurrence);
    }

    /**
     * Unschedule the price refresh cron job
     */
    public static function unschedule(): void {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }

        // Clear all events with this hook
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Check if scheduled refresh is active
     *
     * @return bool
     */
    public static function is_scheduled(): bool {
        return (bool) wp_next_scheduled(self::CRON_HOOK);
    }

    /**
     * Get next scheduled run time
     *
     * @return int|false Timestamp or false if not scheduled
     */
    public static function get_next_run() {
        return wp_next_scheduled(self::CRON_HOOK);
    }

    /**
     * Get current schedule recurrence
     *
     * @return string|null
     */
    public static function get_schedule(): ?string {
        return get_option('apt_refresh_schedule', null);
    }

    /**
     * Run the scheduled price refresh
     *
     * This is called by WP-Cron
     */
    public static function run_scheduled_refresh(): void {
        // Get admin user ID for API calls (use the first admin)
        $admin_user_id = self::get_admin_user_id();

        if (!$admin_user_id) {
            self::log('No admin user found for scheduled refresh');
            return;
        }

        $batch_size = (int) get_option('apt_refresh_batch_size', self::DEFAULT_BATCH_SIZE);
        $start_time = microtime(true);

        // Use the Product Service to refresh products
        $service = new Product_Service();
        $result = $service->bulk_refresh([], [], $batch_size, $admin_user_id);

        $duration = round(microtime(true) - $start_time, 2);

        // Store refresh info
        $refresh_info = [
            'timestamp' => current_time('mysql', true),
            'success_count' => $result['success_count'],
            'failure_count' => $result['failure_count'],
            'duration_seconds' => $duration,
            'batch_size' => $batch_size,
        ];

        update_option(self::LAST_REFRESH_OPTION, $refresh_info);

        self::log(sprintf(
            'Scheduled refresh completed: %d success, %d failed in %.2f seconds',
            $result['success_count'],
            $result['failure_count'],
            $duration
        ));
    }

    /**
     * Get last refresh info
     *
     * @return array|null
     */
    public static function get_last_refresh(): ?array {
        return get_option(self::LAST_REFRESH_OPTION, null);
    }

    /**
     * Get an admin user ID for API operations
     *
     * @return int|null
     */
    private static function get_admin_user_id(): ?int {
        // First check if there's a configured admin for scheduled tasks
        $configured_admin = get_option('apt_scheduled_refresh_admin_id');
        if ($configured_admin && user_can($configured_admin, 'manage_options')) {
            return (int) $configured_admin;
        }

        // Fall back to finding any admin with configured API settings
        global $wpdb;
        $settings_table = $wpdb->prefix . 'apt_user_settings';

        $admin_ids = get_users([
            'role' => 'administrator',
            'fields' => 'ID',
            'number' => 10,
        ]);

        foreach ($admin_ids as $admin_id) {
            // Check if this admin has API settings configured
            $has_settings = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$settings_table} WHERE user_id = %d",
                $admin_id
            ));

            if ($has_settings) {
                return (int) $admin_id;
            }
        }

        // Return first admin if none have settings (will fail gracefully)
        return !empty($admin_ids) ? (int) $admin_ids[0] : null;
    }

    /**
     * Log a message (uses error_log in development)
     *
     * @param string $message Message to log
     */
    private static function log(string $message): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Amazon Price Tracker] ' . $message);
        }
    }

    /**
     * Get refresh status for admin display
     *
     * @return array
     */
    public static function get_status(): array {
        $next_run = self::get_next_run();
        $last_refresh = self::get_last_refresh();
        $schedule = self::get_schedule();

        return [
            'is_scheduled' => self::is_scheduled(),
            'schedule' => $schedule,
            'next_run' => $next_run ? gmdate('c', $next_run) : null,
            'next_run_human' => $next_run ? human_time_diff($next_run) : null,
            'last_refresh' => $last_refresh,
        ];
    }
}

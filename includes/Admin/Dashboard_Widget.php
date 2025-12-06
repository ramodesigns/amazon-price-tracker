<?php
/**
 * Dashboard Widget
 *
 * Displays Amazon Price Tracker statistics and alerts on the WordPress admin dashboard.
 *
 * @package AmazonPriceTracker
 */

namespace APT\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Dashboard_Widget
 */
class Dashboard_Widget {

    /**
     * Widget ID
     *
     * @var string
     */
    private const WIDGET_ID = 'apt_dashboard_widget';

    /**
     * Cache key for widget data
     *
     * @var string
     */
    private const CACHE_KEY = 'apt_dashboard_widget_data';

    /**
     * Cache duration in seconds (5 minutes)
     *
     * @var int
     */
    private const CACHE_DURATION = 300;

    /**
     * Initialize the dashboard widget
     */
    public static function init(): void {
        add_action('wp_dashboard_setup', [self::class, 'register_widget']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_styles']);
        add_action('wp_ajax_apt_refresh_widget', [self::class, 'ajax_refresh_widget']);
        add_action('wp_ajax_apt_run_manual_refresh', [self::class, 'ajax_run_refresh']);
    }

    /**
     * Register the dashboard widget
     */
    public static function register_widget(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_add_dashboard_widget(
            self::WIDGET_ID,
            __('Amazon Price Tracker', 'amazon-price-tracker'),
            [self::class, 'render_widget'],
            [self::class, 'render_widget_config']
        );
    }

    /**
     * Enqueue widget styles
     *
     * @param string $hook The current admin page hook.
     */
    public static function enqueue_styles(string $hook): void {
        if ('index.php' !== $hook) {
            return;
        }

        wp_add_inline_style('dashboard', self::get_widget_css());
    }

    /**
     * Render the dashboard widget
     */
    public static function render_widget(): void {
        $data = self::get_widget_data();
        ?>
        <div class="apt-dashboard-widget">
            <!-- Quick Stats -->
            <div class="apt-stats-grid">
                <div class="apt-stat-item">
                    <span class="apt-stat-value"><?php echo esc_html(number_format($data['total_products'])); ?></span>
                    <span class="apt-stat-label"><?php esc_html_e('Products Tracked', 'amazon-price-tracker'); ?></span>
                </div>
                <div class="apt-stat-item">
                    <span class="apt-stat-value"><?php echo esc_html(number_format($data['total_price_records'])); ?></span>
                    <span class="apt-stat-label"><?php esc_html_e('Price Records', 'amazon-price-tracker'); ?></span>
                </div>
                <div class="apt-stat-item">
                    <span class="apt-stat-value"><?php echo esc_html($data['last_refresh_display']); ?></span>
                    <span class="apt-stat-label"><?php esc_html_e('Last Refresh', 'amazon-price-tracker'); ?></span>
                </div>
            </div>

            <!-- Price Drops Section -->
            <?php if (!empty($data['price_drops'])): ?>
            <div class="apt-section">
                <h4 class="apt-section-title">
                    <span class="dashicons dashicons-arrow-down-alt apt-icon-drop"></span>
                    <?php esc_html_e('Recent Price Drops (24h)', 'amazon-price-tracker'); ?>
                </h4>
                <ul class="apt-price-drops-list">
                    <?php foreach ($data['price_drops'] as $drop): ?>
                    <li class="apt-price-drop-item">
                        <span class="apt-product-info">
                            <strong><?php echo esc_html($drop['asin']); ?></strong>
                            <span class="apt-region-badge"><?php echo esc_html($drop['region']); ?></span>
                        </span>
                        <span class="apt-price-change">
                            <span class="apt-old-price"><?php echo esc_html($drop['old_price_display']); ?></span>
                            <span class="dashicons dashicons-arrow-right-alt"></span>
                            <span class="apt-new-price"><?php echo esc_html($drop['new_price_display']); ?></span>
                            <span class="apt-drop-percent">(<?php echo esc_html($drop['percent_change']); ?>)</span>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Attention Needed Section -->
            <?php if ($data['out_of_stock_count'] > 0 || $data['stale_products_count'] > 0): ?>
            <div class="apt-section apt-attention-section">
                <h4 class="apt-section-title">
                    <span class="dashicons dashicons-warning apt-icon-warning"></span>
                    <?php esc_html_e('Attention Needed', 'amazon-price-tracker'); ?>
                </h4>
                <ul class="apt-attention-list">
                    <?php if ($data['out_of_stock_count'] > 0): ?>
                    <li>
                        <span class="dashicons dashicons-dismiss"></span>
                        <?php
                        printf(
                            /* translators: %d: number of products */
                            esc_html(_n('%d product out of stock', '%d products out of stock', $data['out_of_stock_count'], 'amazon-price-tracker')),
                            $data['out_of_stock_count']
                        );
                        ?>
                    </li>
                    <?php endif; ?>
                    <?php if ($data['stale_products_count'] > 0): ?>
                    <li>
                        <span class="dashicons dashicons-clock"></span>
                        <?php
                        printf(
                            /* translators: %d: number of products */
                            esc_html(_n('%d product with stale data (>7 days)', '%d products with stale data (>7 days)', $data['stale_products_count'], 'amazon-price-tracker')),
                            $data['stale_products_count']
                        );
                        ?>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Products by Region -->
            <?php if (!empty($data['products_by_region'])): ?>
            <div class="apt-section">
                <h4 class="apt-section-title">
                    <span class="dashicons dashicons-admin-site-alt3"></span>
                    <?php esc_html_e('Products by Region', 'amazon-price-tracker'); ?>
                </h4>
                <div class="apt-regions-grid">
                    <?php foreach ($data['products_by_region'] as $region): ?>
                    <span class="apt-region-item">
                        <strong><?php echo esc_html($region['region']); ?></strong>
                        <span><?php echo esc_html(number_format($region['count'])); ?></span>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div class="apt-actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=amazon-price-tracker')); ?>" class="button">
                    <?php esc_html_e('View All Products', 'amazon-price-tracker'); ?>
                </a>
                <button type="button" class="button button-primary apt-refresh-btn" id="apt-run-refresh">
                    <span class="dashicons dashicons-update"></span>
                    <?php esc_html_e('Run Refresh', 'amazon-price-tracker'); ?>
                </button>
            </div>

            <!-- Refresh Status -->
            <div id="apt-refresh-status" class="apt-refresh-status hidden"></div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#apt-run-refresh').on('click', function() {
                var $btn = $(this);
                var $status = $('#apt-refresh-status');

                $btn.prop('disabled', true);
                $btn.find('.dashicons').addClass('apt-spin');
                $status.removeClass('hidden apt-success apt-error').text('<?php esc_html_e('Running refresh...', 'amazon-price-tracker'); ?>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'apt_run_manual_refresh',
                        nonce: '<?php echo esc_js(wp_create_nonce('apt_refresh_nonce')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $status.addClass('apt-success').text(response.data.message);
                        } else {
                            $status.addClass('apt-error').text(response.data.message || '<?php esc_html_e('Refresh failed', 'amazon-price-tracker'); ?>');
                        }
                    },
                    error: function() {
                        $status.addClass('apt-error').text('<?php esc_html_e('Connection error', 'amazon-price-tracker'); ?>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                        $btn.find('.dashicons').removeClass('apt-spin');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render widget configuration
     */
    public static function render_widget_config(): void {
        // Widget configuration options (future enhancement)
        ?>
        <p><?php esc_html_e('Widget displays price tracking statistics and recent price drops.', 'amazon-price-tracker'); ?></p>
        <?php
    }

    /**
     * Get widget data with caching
     *
     * @return array
     */
    private static function get_widget_data(): array {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false) {
            return $cached;
        }

        $data = self::compute_widget_data();
        set_transient(self::CACHE_KEY, $data, self::CACHE_DURATION);

        return $data;
    }

    /**
     * Compute widget data from database
     *
     * @return array
     */
    private static function compute_widget_data(): array {
        global $wpdb;

        $products_table = $wpdb->prefix . 'apt_products';
        $prices_table = $wpdb->prefix . 'apt_price_history';

        // Get basic stats
        $total_products = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$products_table} WHERE is_active = 1");
        $total_price_records = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prices_table}");

        // Get last refresh time
        $last_refresh = $wpdb->get_var("SELECT MAX(recorded_at) FROM {$prices_table}");
        $last_refresh_display = $last_refresh ? self::human_time_diff($last_refresh) : __('Never', 'amazon-price-tracker');

        // Get products by region
        $products_by_region = $wpdb->get_results(
            "SELECT region, COUNT(*) as count
             FROM {$products_table}
             WHERE is_active = 1
             GROUP BY region
             ORDER BY count DESC",
            ARRAY_A
        );

        // Get out of stock count
        $out_of_stock_count = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.id) FROM {$products_table} p
             INNER JOIN {$prices_table} ph ON p.id = ph.product_id
             WHERE p.is_active = 1
             AND ph.availability = 'out_of_stock'
             AND ph.recorded_at = (
                 SELECT MAX(ph2.recorded_at) FROM {$prices_table} ph2 WHERE ph2.product_id = p.id
             )"
        );

        // Get stale products (no update in 7 days)
        $stale_products_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$products_table} p
                 WHERE p.is_active = 1
                 AND p.updated_at < %s",
                gmdate('Y-m-d H:i:s', strtotime('-7 days'))
            )
        );

        // Get price drops in last 24 hours
        $price_drops = self::get_recent_price_drops();

        return [
            'total_products' => $total_products,
            'total_price_records' => $total_price_records,
            'last_refresh' => $last_refresh,
            'last_refresh_display' => $last_refresh_display,
            'products_by_region' => $products_by_region ?: [],
            'out_of_stock_count' => $out_of_stock_count,
            'stale_products_count' => $stale_products_count,
            'price_drops' => $price_drops,
        ];
    }

    /**
     * Get recent price drops (last 24 hours)
     *
     * @return array
     */
    private static function get_recent_price_drops(): array {
        global $wpdb;

        $products_table = $wpdb->prefix . 'apt_products';
        $prices_table = $wpdb->prefix . 'apt_price_history';

        // Find products where the latest price is lower than the previous price
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    p.asin,
                    p.region,
                    latest.current_price as new_price,
                    previous.current_price as old_price
                FROM {$products_table} p
                INNER JOIN {$prices_table} latest ON p.id = latest.product_id
                INNER JOIN {$prices_table} previous ON p.id = previous.product_id
                WHERE p.is_active = 1
                AND latest.recorded_at >= %s
                AND latest.current_price IS NOT NULL
                AND previous.current_price IS NOT NULL
                AND latest.recorded_at = (
                    SELECT MAX(ph1.recorded_at) FROM {$prices_table} ph1 WHERE ph1.product_id = p.id
                )
                AND previous.recorded_at = (
                    SELECT MAX(ph2.recorded_at) FROM {$prices_table} ph2
                    WHERE ph2.product_id = p.id AND ph2.recorded_at < latest.recorded_at
                )
                AND latest.current_price < previous.current_price
                AND ((previous.current_price - latest.current_price) / previous.current_price) >= 0.05
                ORDER BY ((previous.current_price - latest.current_price) / previous.current_price) DESC
                LIMIT 5",
                gmdate('Y-m-d H:i:s', strtotime('-24 hours'))
            ),
            ARRAY_A
        );

        if (empty($results)) {
            return [];
        }

        $price_drops = [];
        foreach ($results as $row) {
            $old_price = (float) $row['old_price'];
            $new_price = (float) $row['new_price'];
            $percent = round((($old_price - $new_price) / $old_price) * 100);
            $currency = self::get_currency_symbol($row['region']);

            $price_drops[] = [
                'asin' => $row['asin'],
                'region' => $row['region'],
                'old_price' => $old_price,
                'new_price' => $new_price,
                'old_price_display' => $currency . number_format($old_price, 2),
                'new_price_display' => $currency . number_format($new_price, 2),
                'percent_change' => '-' . $percent . '%',
            ];
        }

        return $price_drops;
    }

    /**
     * Get currency symbol for region
     *
     * @param string $region Region code.
     * @return string
     */
    private static function get_currency_symbol(string $region): string {
        $symbols = [
            'US' => '$',
            'CA' => 'CA$',
            'UK' => '£',
            'DE' => '€',
            'FR' => '€',
            'ES' => '€',
            'IT' => '€',
            'AU' => 'A$',
            'JP' => '¥',
            'IN' => '₹',
            'MX' => 'MX$',
            'BR' => 'R$',
        ];

        return $symbols[$region] ?? '$';
    }

    /**
     * Human-readable time difference
     *
     * @param string $datetime MySQL datetime string.
     * @return string
     */
    private static function human_time_diff(string $datetime): string {
        $timestamp = strtotime($datetime);
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return __('Just now', 'amazon-price-tracker');
        } elseif ($diff < 3600) {
            $mins = round($diff / 60);
            /* translators: %d: number of minutes */
            return sprintf(_n('%d min ago', '%d mins ago', $mins, 'amazon-price-tracker'), $mins);
        } elseif ($diff < 86400) {
            $hours = round($diff / 3600);
            /* translators: %d: number of hours */
            return sprintf(_n('%d hour ago', '%d hours ago', $hours, 'amazon-price-tracker'), $hours);
        } else {
            $days = round($diff / 86400);
            /* translators: %d: number of days */
            return sprintf(_n('%d day ago', '%d days ago', $days, 'amazon-price-tracker'), $days);
        }
    }

    /**
     * AJAX handler for manual refresh
     */
    public static function ajax_run_refresh(): void {
        check_ajax_referer('apt_refresh_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'amazon-price-tracker')]);
        }

        // Trigger the scheduled refresh using Product_Service directly
        if (class_exists('APT\\Services\\Product_Service')) {
            $user_id = get_current_user_id();
            $batch_size = (int) get_option('apt_refresh_batch_size', 50);

            $service = new \APT\Services\Product_Service();
            $result = $service->bulk_refresh([], [], $batch_size, $user_id);

            // Clear widget cache
            delete_transient(self::CACHE_KEY);

            // Also clear stats cache
            delete_transient('apt_stats_cache');

            wp_send_json_success([
                'message' => sprintf(
                    /* translators: %d: number of products refreshed */
                    __('Refresh complete. %d products updated.', 'amazon-price-tracker'),
                    $result['success_count'] ?? 0
                ),
            ]);
        } else {
            wp_send_json_error(['message' => __('Refresh service not available', 'amazon-price-tracker')]);
        }
    }

    /**
     * AJAX handler for widget refresh
     */
    public static function ajax_refresh_widget(): void {
        check_ajax_referer('apt_widget_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'amazon-price-tracker')]);
        }

        delete_transient(self::CACHE_KEY);
        $data = self::get_widget_data();

        wp_send_json_success($data);
    }

    /**
     * Clear widget cache (called when data changes)
     */
    public static function clear_cache(): void {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * Get widget CSS
     *
     * @return string
     */
    private static function get_widget_css(): string {
        return '
            .apt-dashboard-widget {
                margin: -12px;
            }
            .apt-stats-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 12px;
                padding: 16px;
                background: #f6f7f7;
                border-bottom: 1px solid #dcdcde;
            }
            .apt-stat-item {
                text-align: center;
            }
            .apt-stat-value {
                display: block;
                font-size: 24px;
                font-weight: 600;
                color: #1d2327;
                line-height: 1.2;
            }
            .apt-stat-label {
                display: block;
                font-size: 12px;
                color: #646970;
                margin-top: 4px;
            }
            .apt-section {
                padding: 12px 16px;
                border-bottom: 1px solid #dcdcde;
            }
            .apt-section-title {
                margin: 0 0 10px;
                font-size: 13px;
                font-weight: 600;
                color: #1d2327;
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .apt-section-title .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
            }
            .apt-icon-drop {
                color: #00a32a;
            }
            .apt-icon-warning {
                color: #dba617;
            }
            .apt-price-drops-list {
                margin: 0;
                list-style: none;
                padding: 0;
            }
            .apt-price-drop-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 6px 0;
                font-size: 12px;
                border-bottom: 1px solid #f0f0f1;
            }
            .apt-price-drop-item:last-child {
                border-bottom: none;
            }
            .apt-product-info {
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .apt-region-badge {
                background: #dcdcde;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 10px;
                font-weight: 600;
            }
            .apt-price-change {
                display: flex;
                align-items: center;
                gap: 4px;
            }
            .apt-old-price {
                color: #646970;
                text-decoration: line-through;
            }
            .apt-new-price {
                color: #00a32a;
                font-weight: 600;
            }
            .apt-drop-percent {
                color: #00a32a;
                font-size: 11px;
            }
            .apt-price-change .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
                color: #646970;
            }
            .apt-attention-section {
                background: #fcf9e8;
            }
            .apt-attention-list {
                margin: 0;
                list-style: none;
                padding: 0;
            }
            .apt-attention-list li {
                display: flex;
                align-items: center;
                gap: 6px;
                padding: 4px 0;
                font-size: 12px;
                color: #1d2327;
            }
            .apt-attention-list .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
                color: #996800;
            }
            .apt-regions-grid {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }
            .apt-region-item {
                background: #f0f0f1;
                padding: 4px 8px;
                border-radius: 3px;
                font-size: 11px;
                display: flex;
                gap: 6px;
            }
            .apt-region-item strong {
                color: #1d2327;
            }
            .apt-region-item span {
                color: #646970;
            }
            .apt-actions {
                padding: 12px 16px;
                display: flex;
                gap: 8px;
                justify-content: space-between;
            }
            .apt-refresh-btn .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
                margin-right: 4px;
                vertical-align: middle;
                margin-top: -2px;
            }
            .apt-spin {
                animation: apt-spin 1s linear infinite;
            }
            @keyframes apt-spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            .apt-refresh-status {
                padding: 8px 16px;
                font-size: 12px;
                text-align: center;
                background: #f0f0f1;
            }
            .apt-refresh-status.apt-success {
                background: #edfaef;
                color: #00a32a;
            }
            .apt-refresh-status.apt-error {
                background: #fcf0f1;
                color: #d63638;
            }
            .apt-refresh-status.hidden {
                display: none;
            }
        ';
    }
}

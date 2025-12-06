<?php
/**
 * Test Data Seeder for Amazon Price Tracker
 *
 * Creates sample data for testing purposes.
 *
 * Usage:
 *   WP-CLI: wp eval-file tests/seed-test-data.php
 *   Direct: Include from WordPress context (requires ABSPATH defined)
 *
 * @package AmazonPriceTracker\Tests
 */

// Check if running in WordPress context
if (!defined('ABSPATH')) {
    // If running via WP-CLI, ABSPATH will be defined
    // If running directly, we need to load WordPress
    $wp_load = dirname(__FILE__, 5) . '/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        die("Error: Must be run in WordPress context. Use: wp eval-file tests/seed-test-data.php\n");
    }
}

/**
 * Test Data Seeder Class
 */
class APT_Test_Data_Seeder {

    /**
     * WordPress database instance
     */
    private $wpdb;

    /**
     * Table prefix
     */
    private $prefix;

    /**
     * Admin user ID for test data
     */
    private $admin_user_id;

    /**
     * Sample products data
     */
    private $sample_products = [
        ['asin' => 'B08N5WRWNW', 'region' => 'US', 'title' => 'Sony WH-1000XM4 Wireless Headphones', 'brand' => 'Sony', 'category' => 'Electronics'],
        ['asin' => 'B09V3KXJPB', 'region' => 'US', 'title' => 'Apple AirPods Pro (2nd Gen)', 'brand' => 'Apple', 'category' => 'Electronics'],
        ['asin' => 'B0BDJDKMGH', 'region' => 'UK', 'title' => 'Kindle Paperwhite (11th Gen)', 'brand' => 'Amazon', 'category' => 'Electronics'],
        ['asin' => 'B0BT9CXXXX', 'region' => 'DE', 'title' => 'Samsung Galaxy S23 Ultra', 'brand' => 'Samsung', 'category' => 'Electronics'],
        ['asin' => 'B09JQMJHXY', 'region' => 'US', 'title' => 'Instant Pot Duo 7-in-1', 'brand' => 'Instant Pot', 'category' => 'Home & Kitchen'],
        ['asin' => 'B08J5F3G18', 'region' => 'UK', 'title' => 'Ninja Foodi MAX Dual Zone Air Fryer', 'brand' => 'Ninja', 'category' => 'Home & Kitchen'],
        ['asin' => 'B07XJ8C8F5', 'region' => 'FR', 'title' => 'Dyson V15 Detect Vacuum', 'brand' => 'Dyson', 'category' => 'Home & Kitchen'],
        ['asin' => 'B09B8DQ26F', 'region' => 'US', 'title' => 'Fitbit Charge 5 Fitness Tracker', 'brand' => 'Fitbit', 'category' => 'Sports'],
        ['asin' => 'B0BCPKKZ91', 'region' => 'JP', 'title' => 'Nintendo Switch OLED Model', 'brand' => 'Nintendo', 'category' => 'Gaming'],
        ['asin' => 'B0B3PSRHHN', 'region' => 'AU', 'title' => 'LEGO Star Wars Millennium Falcon', 'brand' => 'LEGO', 'category' => 'Toys'],
    ];

    /**
     * Sample blacklist entries
     */
    private $sample_blacklist = [
        ['asin' => 'B000000001', 'region' => 'US', 'reason' => 'Test blacklist entry - prohibited category'],
        ['asin' => 'B000000002', 'region' => 'UK', 'reason' => 'Test blacklist entry - counterfeit concerns'],
        ['asin' => 'B000000003', 'region' => 'DE', 'reason' => 'Test blacklist entry - discontinued product'],
    ];

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->prefix = $wpdb->prefix . 'apt_';
        $this->admin_user_id = $this->get_admin_user_id();
    }

    /**
     * Get an admin user ID
     */
    private function get_admin_user_id(): int {
        $admins = get_users(['role' => 'administrator', 'number' => 1]);
        if (!empty($admins)) {
            return $admins[0]->ID;
        }
        return 1; // Fallback to user ID 1
    }

    /**
     * Run the seeder
     */
    public function run(): void {
        $this->log("Starting Amazon Price Tracker Test Data Seeder...\n");

        // Check if tables exist
        if (!$this->tables_exist()) {
            $this->log("Error: Plugin tables do not exist. Please activate the plugin first.\n");
            return;
        }

        // Seed data
        $this->seed_products();
        $this->seed_blacklist();

        $this->log("\nTest data seeding complete!\n");
        $this->print_summary();
    }

    /**
     * Check if plugin tables exist
     */
    private function tables_exist(): bool {
        $table = $this->prefix . 'products';
        $result = $this->wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        return $result === $table;
    }

    /**
     * Seed sample products with price history
     */
    private function seed_products(): void {
        $this->log("\nSeeding products...\n");

        $products_table = $this->prefix . 'products';
        $prices_table = $this->prefix . 'price_history';

        foreach ($this->sample_products as $product) {
            // Check if product already exists
            $existing = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$products_table} WHERE asin = %s AND region = %s",
                $product['asin'],
                $product['region']
            ));

            if ($existing) {
                $this->log("  - Skipped {$product['asin']} ({$product['region']}): Already exists\n");
                continue;
            }

            // Generate sample data
            $images = $this->generate_sample_images($product['asin']);
            $facts = $this->generate_sample_facts($product);
            $base_price = $this->generate_random_price(29.99, 499.99);
            $rrp = round($base_price * 1.2, 2);

            // Insert product
            $this->wpdb->insert($products_table, [
                'asin' => $product['asin'],
                'region' => $product['region'],
                'custom_category' => $product['category'],
                'images' => wp_json_encode($images),
                'facts' => wp_json_encode($facts),
                'is_active' => 1,
                'created_at' => $this->random_date_in_past(30),
                'updated_at' => current_time('mysql', true),
                'created_by' => $this->admin_user_id,
            ]);

            $product_id = $this->wpdb->insert_id;

            // Generate price history (10-20 records over past 30 days)
            $num_records = rand(10, 20);
            $this->generate_price_history($product_id, $base_price, $rrp, $num_records);

            $this->log("  + Created {$product['asin']} ({$product['region']}): {$product['title']}\n");
        }
    }

    /**
     * Generate sample images array
     */
    private function generate_sample_images(string $asin): array {
        return [
            [
                'url' => "https://m.media-amazon.com/images/I/{$asin}_main.jpg",
                'height' => 500,
                'width' => 500,
                'type' => 'main',
            ],
            [
                'url' => "https://m.media-amazon.com/images/I/{$asin}_variant1.jpg",
                'height' => 500,
                'width' => 500,
                'type' => 'variant',
            ],
        ];
    }

    /**
     * Generate sample facts object
     */
    private function generate_sample_facts(array $product): array {
        return [
            'title' => $product['title'],
            'brand' => $product['brand'],
            'manufacturer' => $product['brand'],
            'features' => [
                'High-quality construction',
                'Premium materials',
                'Excellent performance',
            ],
            'description' => "This is a sample product description for {$product['title']}.",
            'amazon_category' => $product['category'],
            'rating' => round(rand(35, 50) / 10, 1),
            'review_count' => rand(100, 10000),
            'is_prime_eligible' => (bool) rand(0, 1),
        ];
    }

    /**
     * Generate random price
     */
    private function generate_random_price(float $min, float $max): float {
        return round($min + mt_rand() / mt_getrandmax() * ($max - $min), 2);
    }

    /**
     * Generate price history records
     */
    private function generate_price_history(int $product_id, float $base_price, float $rrp, int $num_records): void {
        $prices_table = $this->prefix . 'price_history';
        $availabilities = ['in_stock', 'in_stock', 'in_stock', 'limited_stock', 'out_of_stock'];

        for ($i = 0; $i < $num_records; $i++) {
            // Vary price by ±15%
            $variation = (mt_rand(-15, 15) / 100);
            $current_price = round($base_price * (1 + $variation), 2);

            // Occasionally set out of stock (null price)
            $is_available = rand(0, 10) > 1;
            $availability = $availabilities[array_rand($availabilities)];

            $this->wpdb->insert($prices_table, [
                'product_id' => $product_id,
                'rrp' => $rrp,
                'current_price' => $is_available ? $current_price : null,
                'is_prime_price' => rand(0, 1),
                'availability' => $availability,
                'recorded_at' => $this->random_date_in_past(30),
            ]);
        }
    }

    /**
     * Seed sample blacklist entries
     */
    private function seed_blacklist(): void {
        $this->log("\nSeeding blacklist entries...\n");

        $blacklist_table = $this->prefix . 'blacklist';

        foreach ($this->sample_blacklist as $entry) {
            // Check if already exists
            $existing = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$blacklist_table} WHERE asin = %s AND region = %s",
                $entry['asin'],
                $entry['region']
            ));

            if ($existing) {
                $this->log("  - Skipped {$entry['asin']} ({$entry['region']}): Already blacklisted\n");
                continue;
            }

            $this->wpdb->insert($blacklist_table, [
                'asin' => $entry['asin'],
                'region' => $entry['region'],
                'reason' => $entry['reason'],
                'created_at' => current_time('mysql', true),
                'created_by' => $this->admin_user_id,
            ]);

            $this->log("  + Blacklisted {$entry['asin']} ({$entry['region']})\n");
        }
    }

    /**
     * Generate a random date in the past N days
     */
    private function random_date_in_past(int $days): string {
        $timestamp = time() - rand(0, $days * 24 * 60 * 60);
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Print summary of seeded data
     */
    private function print_summary(): void {
        $products_count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->prefix}products");
        $prices_count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->prefix}price_history");
        $blacklist_count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->prefix}blacklist");

        $this->log("\n========================================\n");
        $this->log("Summary:\n");
        $this->log("  Products:       {$products_count}\n");
        $this->log("  Price Records:  {$prices_count}\n");
        $this->log("  Blacklist:      {$blacklist_count}\n");
        $this->log("========================================\n");
    }

    /**
     * Clear all test data
     */
    public function clear(): void {
        $this->log("Clearing all test data...\n");

        $this->wpdb->query("DELETE FROM {$this->prefix}price_history");
        $this->wpdb->query("DELETE FROM {$this->prefix}products");
        $this->wpdb->query("DELETE FROM {$this->prefix}blacklist");
        $this->wpdb->query("DELETE FROM {$this->prefix}user_settings");

        // Clear stats cache
        delete_transient('apt_stats_cache');

        $this->log("All test data cleared.\n");
    }

    /**
     * Log message
     */
    private function log(string $message): void {
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::log($message);
        } else {
            echo $message;
        }
    }
}

// Run the seeder
$seeder = new APT_Test_Data_Seeder();

// Check for --clear flag
if (defined('WP_CLI') && WP_CLI) {
    $args = WP_CLI::get_runner()->arguments;
    if (in_array('--clear', $args, true)) {
        $seeder->clear();
        exit;
    }
}

// Check for clear argument in GET params (for web access if needed)
if (isset($_GET['action']) && $_GET['action'] === 'clear') {
    $seeder->clear();
    exit;
}

// Run seeder
$seeder->run();

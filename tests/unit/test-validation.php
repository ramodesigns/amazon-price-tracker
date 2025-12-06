<?php
/**
 * Validation Helper Tests
 *
 * @package AmazonPriceTracker\Tests\Unit
 */

use APT\Helpers\Validation;

/**
 * Test case for the Validation helper class.
 */
class Test_Validation extends WP_UnitTestCase {

    /**
     * Test valid ASIN format detection.
     */
    public function test_is_valid_asin_with_valid_asins() {
        $valid_asins = [
            'B08N5WRWNW',
            'B000000001',
            'ABCDEFGH12',
            '1234567890',
        ];

        foreach ($valid_asins as $asin) {
            $this->assertTrue(
                Validation::is_valid_asin($asin),
                "Expected '{$asin}' to be a valid ASIN"
            );
        }
    }

    /**
     * Test invalid ASIN format detection.
     */
    public function test_is_valid_asin_with_invalid_asins() {
        $invalid_asins = [
            '',
            'B08N5WRW',       // Too short
            'B08N5WRWNWX',    // Too long
            'B08N5WRW-W',     // Contains hyphen
            'b08n5wrwnw',     // Lowercase (should be uppercase)
            'B08N5WRW W',     // Contains space
        ];

        foreach ($invalid_asins as $asin) {
            $this->assertFalse(
                Validation::is_valid_asin($asin),
                "Expected '{$asin}' to be an invalid ASIN"
            );
        }
    }

    /**
     * Test ASIN normalization.
     */
    public function test_normalize_asin() {
        $this->assertEquals('B08N5WRWNW', Validation::normalize_asin('b08n5wrwnw'));
        $this->assertEquals('B08N5WRWNW', Validation::normalize_asin(' B08N5WRWNW '));
        $this->assertEquals('B08N5WRWNW', Validation::normalize_asin('  b08n5wrwnw  '));
    }

    /**
     * Test valid region detection.
     */
    public function test_is_valid_region_with_valid_regions() {
        $valid_regions = ['US', 'CA', 'UK', 'DE', 'FR', 'ES', 'IT', 'AU', 'JP', 'IN', 'MX', 'BR'];

        foreach ($valid_regions as $region) {
            $this->assertTrue(
                Validation::is_valid_region($region),
                "Expected '{$region}' to be a valid region"
            );
        }
    }

    /**
     * Test invalid region detection.
     */
    public function test_is_valid_region_with_invalid_regions() {
        $invalid_regions = ['', 'XX', 'USA', 'us', 'United States', 'GB'];

        foreach ($invalid_regions as $region) {
            $this->assertFalse(
                Validation::is_valid_region($region),
                "Expected '{$region}' to be an invalid region"
            );
        }
    }

    /**
     * Test region normalization.
     */
    public function test_normalize_region() {
        $this->assertEquals('US', Validation::normalize_region('us'));
        $this->assertEquals('UK', Validation::normalize_region('uk'));
        $this->assertEquals('DE', Validation::normalize_region(' de '));
    }

    /**
     * Test region list validation.
     */
    public function test_validate_region_list() {
        $result = Validation::validate_region_list('US,UK,DE');
        $this->assertEquals(['US', 'UK', 'DE'], $result);

        $result = Validation::validate_region_list('us, uk, invalid, de');
        $this->assertEquals(['US', 'UK', 'DE'], $result);

        $result = Validation::validate_region_list('');
        $this->assertEquals([], $result);
    }

    /**
     * Test boolean validation.
     */
    public function test_validate_bool() {
        // Truthy values
        $this->assertTrue(Validation::validate_bool(true));
        $this->assertTrue(Validation::validate_bool('true'));
        $this->assertTrue(Validation::validate_bool('1'));
        $this->assertTrue(Validation::validate_bool(1));
        $this->assertTrue(Validation::validate_bool('yes'));

        // Falsy values
        $this->assertFalse(Validation::validate_bool(false));
        $this->assertFalse(Validation::validate_bool('false'));
        $this->assertFalse(Validation::validate_bool('0'));
        $this->assertFalse(Validation::validate_bool(0));
        $this->assertFalse(Validation::validate_bool('no'));
        $this->assertFalse(Validation::validate_bool(null));
    }

    /**
     * Test sort order validation.
     */
    public function test_validate_sort_order() {
        $this->assertEquals('ASC', Validation::validate_sort_order('asc'));
        $this->assertEquals('ASC', Validation::validate_sort_order('ASC'));
        $this->assertEquals('DESC', Validation::validate_sort_order('desc'));
        $this->assertEquals('DESC', Validation::validate_sort_order('DESC'));
        $this->assertEquals('DESC', Validation::validate_sort_order('invalid'));
        $this->assertEquals('DESC', Validation::validate_sort_order(''));
    }

    /**
     * Test aggregation validation.
     */
    public function test_validate_aggregation() {
        $this->assertEquals('none', Validation::validate_aggregation('none'));
        $this->assertEquals('daily', Validation::validate_aggregation('daily'));
        $this->assertEquals('weekly', Validation::validate_aggregation('weekly'));
        $this->assertEquals('monthly', Validation::validate_aggregation('monthly'));
        $this->assertEquals('none', Validation::validate_aggregation('invalid'));
        $this->assertEquals('none', Validation::validate_aggregation(''));
    }

    /**
     * Test category validation.
     */
    public function test_validate_category() {
        $this->assertEquals('Electronics', Validation::validate_category('Electronics'));
        $this->assertEquals('Home & Garden', Validation::validate_category('  Home & Garden  '));
        $this->assertNull(Validation::validate_category(''));
        $this->assertNull(Validation::validate_category(null));
    }

    /**
     * Test datetime validation.
     */
    public function test_validate_datetime() {
        // Valid ISO 8601 formats
        $this->assertNotNull(Validation::validate_datetime('2024-01-15T10:30:00Z'));
        $this->assertNotNull(Validation::validate_datetime('2024-01-15'));

        // Invalid formats should return null
        $this->assertNull(Validation::validate_datetime('invalid'));
        $this->assertNull(Validation::validate_datetime(''));
    }
}

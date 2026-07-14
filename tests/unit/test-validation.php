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
     * Test that ASIN validation is case-insensitive.
     */
    public function test_is_valid_asin_is_case_insensitive() {
        $this->assertTrue(
            Validation::is_valid_asin('b08n5wrwnw'),
            "Expected lowercase ASIN to be accepted as valid"
        );
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
        $invalid_regions = ['', 'XX', 'USA', 'United States', 'GB'];

        foreach ($invalid_regions as $region) {
            $this->assertFalse(
                Validation::is_valid_region($region),
                "Expected '{$region}' to be an invalid region"
            );
        }
    }

    /**
     * Test that region validation is case-insensitive.
     */
    public function test_is_valid_region_is_case_insensitive() {
        $this->assertTrue(
            Validation::is_valid_region('us'),
            "Expected lowercase region code to be accepted as valid"
        );
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
     * Test boolean validation trims whitespace and lowercases string
     * values before comparing against the allow-list.
     */
    public function test_validate_bool_trims_and_lowercases_strings() {
        $this->assertTrue(Validation::validate_bool(' TRUE '));
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
     * Test sort order validation trims surrounding whitespace before
     * comparing against the allow-list.
     */
    public function test_validate_sort_order_trims_whitespace() {
        $this->assertEquals('ASC', Validation::validate_sort_order(' asc '));
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
     * Test aggregation validation trims whitespace and lowercases before
     * comparing against the allow-list.
     */
    public function test_validate_aggregation_trims_and_lowercases() {
        $this->assertEquals('daily', Validation::validate_aggregation(' DAILY '));
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
     * Test category validation truncates values longer than 255
     * characters to exactly the first 255 characters.
     */
    public function test_validate_category_truncates_long_values() {
        // Non-uniform content is essential here: repeated identical characters
        // would make an off-by-one truncation offset produce the same output.
        $long_category = substr(str_repeat('0123456789', 30), 0, 300);

        $result = Validation::validate_category($long_category);

        $this->assertSame(255, strlen($result));
        $this->assertSame(substr($long_category, 0, 255), $result);
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

    /**
     * Test pagination validation within bounds.
     */
    public function test_validate_pagination_within_bounds() {
        $result = Validation::validate_pagination(2, 25, 100);

        $this->assertSame(['page' => 2, 'per_page' => 25], $result);
    }

    /**
     * Test pagination validation uses default values.
     */
    public function test_validate_pagination_defaults() {
        $result = Validation::validate_pagination();

        $this->assertSame(['page' => 1, 'per_page' => 20], $result);
    }

    /**
     * Test pagination validation clamps page numbers below 1.
     */
    public function test_validate_pagination_clamps_page_below_one() {
        $this->assertSame(1, Validation::validate_pagination(0, 20, 100)['page']);
        $this->assertSame(1, Validation::validate_pagination(-5, 20, 100)['page']);
    }

    /**
     * Test pagination validation clamps per_page below 1.
     */
    public function test_validate_pagination_clamps_per_page_below_one() {
        $this->assertSame(1, Validation::validate_pagination(1, 0, 100)['per_page']);
    }

    /**
     * Test pagination validation clamps per_page above the maximum.
     */
    public function test_validate_pagination_clamps_per_page_above_max() {
        $this->assertSame(100, Validation::validate_pagination(1, 500, 100)['per_page']);
    }

    /**
     * Test pagination validation clamps per_page against the default
     * max_per_page (100) when the caller relies on that default rather
     * than passing it explicitly.
     */
    public function test_validate_pagination_clamps_per_page_against_default_max() {
        $this->assertSame(100, Validation::validate_pagination(1, 150)['per_page']);
    }

    /**
     * Test availability validation with valid statuses.
     */
    public function test_validate_availability_with_valid_statuses() {
        $valid_statuses = ['in_stock', 'out_of_stock', 'limited_stock', 'preorder', 'unknown'];

        foreach ($valid_statuses as $status) {
            $this->assertSame($status, Validation::validate_availability($status));
        }
    }

    /**
     * Test availability validation is case-insensitive and trims whitespace.
     */
    public function test_validate_availability_is_case_insensitive() {
        $this->assertSame('in_stock', Validation::validate_availability(' IN_STOCK '));
    }

    /**
     * Test availability validation with an invalid status returns null.
     */
    public function test_validate_availability_with_invalid_status_returns_null() {
        $this->assertNull(Validation::validate_availability('shipped'));
        $this->assertNull(Validation::validate_availability(''));
    }

    /**
     * Test product sort field validation with valid fields.
     */
    public function test_validate_product_sort_field_with_valid_fields() {
        $valid_fields = ['created_at', 'updated_at', 'current_price', 'title', 'asin'];

        foreach ($valid_fields as $field) {
            $this->assertSame($field, Validation::validate_product_sort_field($field));
        }
    }

    /**
     * Test product sort field validation is case-insensitive and trims whitespace.
     */
    public function test_validate_product_sort_field_is_case_insensitive() {
        $this->assertSame('current_price', Validation::validate_product_sort_field(' CURRENT_PRICE '));
    }

    /**
     * Test product sort field validation defaults to created_at when invalid.
     */
    public function test_validate_product_sort_field_defaults_to_created_at() {
        $this->assertSame('created_at', Validation::validate_product_sort_field('invalid_field'));
        $this->assertSame('created_at', Validation::validate_product_sort_field(''));
    }

    /**
     * Test search query validation with a valid query.
     */
    public function test_validate_search_query_with_valid_query() {
        $this->assertSame('wireless mouse', Validation::validate_search_query('wireless mouse'));
    }

    /**
     * Test search query validation trims whitespace.
     */
    public function test_validate_search_query_trims_whitespace() {
        $this->assertSame('mouse', Validation::validate_search_query('  mouse  '));
    }

    /**
     * Test search query validation rejects queries shorter than the minimum length.
     */
    public function test_validate_search_query_too_short_returns_null() {
        $this->assertNull(Validation::validate_search_query('a'));
    }

    /**
     * Test search query validation accepts a query exactly at the default
     * minimum length (2).
     */
    public function test_validate_search_query_at_default_min_length() {
        $this->assertSame('ab', Validation::validate_search_query('ab'));
    }

    /**
     * Test search query validation respects a custom minimum length.
     */
    public function test_validate_search_query_respects_custom_min_length() {
        $this->assertNull(Validation::validate_search_query('ab', 3));
        $this->assertSame('abc', Validation::validate_search_query('abc', 3));
    }

    /**
     * Test price validation with valid numeric values.
     */
    public function test_validate_price_with_valid_values() {
        $this->assertSame(19.99, Validation::validate_price(19.99));
        $this->assertSame(20.0, Validation::validate_price('20'));
        $this->assertSame(0.0, Validation::validate_price(0));
    }

    /**
     * Test price validation rounds to two decimal places.
     */
    public function test_validate_price_rounds_to_two_decimals() {
        $this->assertSame(20.0, Validation::validate_price(19.999));
        $this->assertSame(5.0, Validation::validate_price(5.004));
    }

    /**
     * Test price validation rejects negative values.
     */
    public function test_validate_price_with_negative_value_returns_null() {
        $this->assertNull(Validation::validate_price(-5));
        $this->assertNull(Validation::validate_price('-0.01'));
    }

    /**
     * Test price validation rejects non-numeric values.
     */
    public function test_validate_price_with_non_numeric_value_returns_null() {
        $this->assertNull(Validation::validate_price('abc'));
        $this->assertNull(Validation::validate_price(null));
        $this->assertNull(Validation::validate_price([]));
    }

    /**
     * Test building a validation error response.
     */
    public function test_build_validation_error() {
        $errors = [['field' => 'asin', 'message' => 'Required']];
        $result = Validation::build_validation_error($errors);

        $this->assertSame('VALIDATION_ERROR', $result['code']);
        $this->assertSame('Request validation failed', $result['message']);
        $this->assertSame($errors, $result['errors']);
    }

    /**
     * Test appending a field error to an empty errors array.
     */
    public function test_add_field_error_appends_to_empty_array() {
        $errors = [];
        Validation::add_field_error($errors, 'asin', 'ASIN is required');

        $this->assertCount(1, $errors);
        $this->assertSame(['field' => 'asin', 'message' => 'ASIN is required'], $errors[0]);
    }

    /**
     * Test appending a field error preserves existing errors.
     */
    public function test_add_field_error_appends_to_existing_array() {
        $errors = [['field' => 'region', 'message' => 'Invalid region']];
        Validation::add_field_error($errors, 'asin', 'ASIN is required');

        $this->assertCount(2, $errors);
        $this->assertSame('asin', $errors[1]['field']);
    }
}

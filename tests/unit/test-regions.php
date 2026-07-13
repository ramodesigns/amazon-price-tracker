<?php
/**
 * Regions Helper Tests
 *
 * @package AmazonPriceTracker\Tests\Unit
 */

use APT\Helpers\Regions;

/**
 * Test case for the Regions helper class.
 */
class Test_Regions extends WP_UnitTestCase {

    /**
     * Test that all 12 supported marketplaces are present.
     */
    public function test_get_all_returns_all_regions() {
        $regions = Regions::get_all();

        $this->assertCount(12, $regions);
        $this->assertArrayHasKey('US', $regions);
        $this->assertArrayHasKey('UK', $regions);
        $this->assertArrayHasKey('BR', $regions);
    }

    /**
     * Test that each region entry has the expected shape.
     */
    public function test_get_all_region_entries_have_expected_fields() {
        $regions = Regions::get_all();

        foreach ($regions as $code => $region) {
            $this->assertSame($code, $region['code']);
            $this->assertArrayHasKey('name', $region);
            $this->assertArrayHasKey('marketplace_domain', $region);
            $this->assertArrayHasKey('currency', $region);
            $this->assertArrayHasKey('host', $region);
            $this->assertArrayHasKey('region', $region);
        }
    }

    /**
     * Test getting a region by valid code.
     */
    public function test_get_with_valid_code() {
        $region = Regions::get('US');

        $this->assertNotNull($region);
        $this->assertSame('United States', $region['name']);
        $this->assertSame('amazon.com', $region['marketplace_domain']);
        $this->assertSame('USD', $region['currency']);
    }

    /**
     * Test that get() is case-insensitive.
     */
    public function test_get_is_case_insensitive() {
        $region = Regions::get('uk');

        $this->assertNotNull($region);
        $this->assertSame('UK', $region['code']);
    }

    /**
     * Test getting a region by invalid code returns null.
     */
    public function test_get_with_invalid_code_returns_null() {
        $this->assertNull(Regions::get('XX'));
    }

    /**
     * Test is_valid with valid and invalid codes.
     */
    public function test_is_valid() {
        $this->assertTrue(Regions::is_valid('US'));
        $this->assertTrue(Regions::is_valid('us'));
        $this->assertFalse(Regions::is_valid('XX'));
        $this->assertFalse(Regions::is_valid(''));
    }

    /**
     * Test get_codes returns all 12 codes.
     */
    public function test_get_codes() {
        $codes = Regions::get_codes();

        $this->assertCount(12, $codes);
        $this->assertContains('US', $codes);
        $this->assertContains('JP', $codes);
    }

    /**
     * Test get_currency for valid and invalid regions.
     */
    public function test_get_currency() {
        $this->assertSame('GBP', Regions::get_currency('UK'));
        $this->assertSame('EUR', Regions::get_currency('DE'));
        $this->assertNull(Regions::get_currency('XX'));
    }

    /**
     * Test get_api_host for valid and invalid regions.
     */
    public function test_get_api_host() {
        $this->assertSame('webservices.amazon.com', Regions::get_api_host('US'));
        $this->assertSame('webservices.amazon.co.uk', Regions::get_api_host('UK'));
        $this->assertNull(Regions::get_api_host('XX'));
    }

    /**
     * Test get_aws_region for valid and invalid regions.
     */
    public function test_get_aws_region() {
        $this->assertSame('us-east-1', Regions::get_aws_region('US'));
        $this->assertSame('eu-west-1', Regions::get_aws_region('DE'));
        $this->assertNull(Regions::get_aws_region('XX'));
    }

    /**
     * Test get_marketplace_domain for valid and invalid regions.
     */
    public function test_get_marketplace_domain() {
        $this->assertSame('amazon.com.au', Regions::get_marketplace_domain('AU'));
        $this->assertNull(Regions::get_marketplace_domain('XX'));
    }

    /**
     * Test product URL generation without a partner tag.
     */
    public function test_get_product_url_without_partner_tag() {
        $url = Regions::get_product_url('B08N5WRWNW', 'US');

        $this->assertSame('https://www.amazon.com/dp/B08N5WRWNW', $url);
    }

    /**
     * Test product URL generation with a partner tag.
     */
    public function test_get_product_url_with_partner_tag() {
        $url = Regions::get_product_url('B08N5WRWNW', 'UK', 'mytag-21');

        $this->assertSame('https://www.amazon.co.uk/dp/B08N5WRWNW?tag=mytag-21', $url);
    }

    /**
     * Test product URL generation for an invalid region returns null.
     */
    public function test_get_product_url_with_invalid_region_returns_null() {
        $this->assertNull(Regions::get_product_url('B08N5WRWNW', 'XX'));
    }

    /**
     * Test API-formatted region list only exposes public fields.
     */
    public function test_get_for_api() {
        $result = Regions::get_for_api();

        $this->assertCount(12, $result);

        $us = current(array_filter($result, function ($region) {
            return $region['code'] === 'US';
        }));

        $this->assertSame([
            'code' => 'US',
            'name' => 'United States',
            'marketplace_domain' => 'amazon.com',
            'currency' => 'USD',
        ], $us);
        $this->assertArrayNotHasKey('host', $us);
        $this->assertArrayNotHasKey('region', $us);
    }
}

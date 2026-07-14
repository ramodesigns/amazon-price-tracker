<?php
/**
 * Amazon Creators API Integration Test
 *
 * Real-network smoke test for Amazon_Creators_API - the OAuth 2.0-based
 * Creators API client that's replacing Amazon_API (PA-API 5.0). Exercises
 * the full live round trip: token request/caching against the real OAuth2
 * endpoint, then a real getItems catalog call, proving the client actually
 * gets item data back from Amazon rather than just asserting against a
 * canned fixture (see tests/component/ for the mocked equivalents).
 *
 * Needs real Creators API credentials, supplied via environment variables
 * (never hardcoded - they'd otherwise end up committed to git history):
 *
 *   APT_TEST_CREATORS_API_CREDENTIAL_ID
 *   APT_TEST_CREATORS_API_CREDENTIAL_SECRET
 *   APT_TEST_CREATORS_API_VERSION
 *   APT_TEST_CREATORS_API_PARTNER_TAG
 *   APT_TEST_CREATORS_API_REGION      (defaults to UK)
 *   APT_TEST_ASIN                     (shared with the other integration
 *                                       tests, defaults to a known-good UK ASIN)
 *
 * Without them set, these tests are skipped rather than failed.
 *
 * @package AmazonPriceTracker\Tests\Integration
 */

use APT\Services\Amazon_Creators_API;

/**
 * Test case proving Amazon_Creators_API returns real item data.
 */
class Test_Creators_API extends WP_UnitTestCase {

    /**
     * Build a client from env-supplied credentials, or skip the calling
     * test if any are missing.
     *
     * @return Amazon_Creators_API
     */
    private function make_client(): Amazon_Creators_API {
        $credential_id = getenv('APT_TEST_CREATORS_API_CREDENTIAL_ID');
        $credential_secret = getenv('APT_TEST_CREATORS_API_CREDENTIAL_SECRET');
        $version = getenv('APT_TEST_CREATORS_API_VERSION');
        $partner_tag = getenv('APT_TEST_CREATORS_API_PARTNER_TAG');
        $region = getenv('APT_TEST_CREATORS_API_REGION') ?: 'UK';

        if (!$credential_id || !$credential_secret || !$version || !$partner_tag) {
            $this->markTestSkipped(
                'Set APT_TEST_CREATORS_API_CREDENTIAL_ID, APT_TEST_CREATORS_API_CREDENTIAL_SECRET, ' .
                'APT_TEST_CREATORS_API_VERSION and APT_TEST_CREATORS_API_PARTNER_TAG to run this test ' .
                'against the real Creators API.'
            );
        }

        return new Amazon_Creators_API($credential_id, $credential_secret, $version, $partner_tag, $region);
    }

    /**
     * Test that a real getItems call via get_item() returns actual product
     * data - title, images, and a pricing block.
     */
    public function test_get_item_returns_real_product_data_from_creators_api() {
        $amazon = $this->make_client();
        $asin = getenv('APT_TEST_ASIN') ?: 'B0GWGVC46T';

        $product_data = $amazon->get_item($asin);

        fwrite(STDERR, sprintf(
            "\n[Creators API integration test] getItems(%s) -> %s\n",
            $asin,
            wp_json_encode($product_data)
        ));

        $this->assertNotNull($product_data, 'Expected the item to be found: ' . $amazon->get_last_error());
        $this->assertNull($amazon->get_last_error());

        $this->assertSame($asin, $product_data['asin']);
        $this->assertNotEmpty($product_data['facts']['title'] ?? null, 'Expected the Creators API to return a product title.');
        $this->assertNotEmpty($product_data['images'], 'Expected the Creators API to return at least one product image.');

        $this->assertArrayHasKey('pricing', $product_data);
        $this->assertArrayHasKey('current_price', $product_data['pricing']);
        $this->assertArrayHasKey('rrp', $product_data['pricing']);
        $this->assertArrayHasKey('is_prime_price', $product_data['pricing']);
        $this->assertArrayHasKey('availability', $product_data['pricing']);
    }

    /**
     * Test that get_items() - the batch method get_item() wraps - returns
     * results keyed by ASIN, proving the underlying getItems request/response
     * parsing works independently of the single-item convenience wrapper.
     */
    public function test_get_items_returns_asin_keyed_results() {
        $amazon = $this->make_client();
        $asin = getenv('APT_TEST_ASIN') ?: 'B0GWGVC46T';

        $results = $amazon->get_items([$asin]);

        fwrite(STDERR, sprintf(
            "\n[Creators API integration test] getItems batch([%s]) -> %s\n",
            $asin,
            wp_json_encode($results)
        ));

        $this->assertNull($amazon->get_last_error());
        $this->assertArrayHasKey($asin, $results, 'Expected the batch result to be keyed by ASIN.');
        $this->assertNotEmpty($results[$asin]['facts']['title'] ?? null, 'Expected the Creators API to return a product title.');
    }
}

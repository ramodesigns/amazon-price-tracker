<?php
/**
 * Amazon API Pricing/Offers Integration Test
 *
 * Canary for a known Amazon PA-API 5.0 account-level restriction: Associates
 * accounts that haven't referred at least 3 qualifying sales in the trailing
 * 180 days get item metadata (title, images, etc.) back from GetItems, but
 * Amazon silently omits the Offers resource entirely - no error, just a
 * 200 OK response with no pricing/availability data. See Amazon_API::get_items()
 * for the WP_DEBUG log line that surfaces this per-item when it happens.
 *
 * This test asserts the *current* (offers-less) state on purpose. Once the
 * PA-API account clears that sales threshold, Amazon will start including
 * Offers again, this test will start failing, and that failure is the
 * signal to know pricing is available and to update/remove this test.
 *
 * Needs the same real Associates credentials as
 * tests/integration/test-products-controller.php, supplied via env vars
 * (never hardcoded):
 *
 *   APT_TEST_PA_API_ACCESS_KEY
 *   APT_TEST_PA_API_SECRET_KEY
 *   APT_TEST_PA_API_PARTNER_TAG
 *   APT_TEST_PA_API_REGION      (defaults to UK)
 *   APT_TEST_PA_API_ASIN        (defaults to a known-good UK ASIN)
 *
 * Without them set, this test is skipped rather than failed.
 *
 * @package AmazonPriceTracker\Tests\Integration
 */

use APT\Services\Amazon_API;

/**
 * Test case asserting the current no-Offers PA-API account restriction.
 */
class Test_Amazon_API_Pricing extends WP_UnitTestCase {

    /**
     * Test that a real GetItems call currently comes back without pricing,
     * because the configured Associates account has no qualifying sales.
     *
     * If this test starts failing, it means Amazon has started returning
     * the Offers resource for this account - a good thing! Update/remove
     * this test and re-enable price tracking expectations elsewhere.
     */
    public function test_amazon_currently_omits_offers_for_this_account() {
        $access_key = getenv('APT_TEST_PA_API_ACCESS_KEY');
        $secret_key = getenv('APT_TEST_PA_API_SECRET_KEY');
        $partner_tag = getenv('APT_TEST_PA_API_PARTNER_TAG');
        $region = getenv('APT_TEST_PA_API_REGION') ?: 'UK';
        $asin = getenv('APT_TEST_PA_API_ASIN') ?: 'B0GWGVC46T';

        if (!$access_key || !$secret_key || !$partner_tag) {
            $this->markTestSkipped(
                'Set APT_TEST_PA_API_ACCESS_KEY, APT_TEST_PA_API_SECRET_KEY and ' .
                'APT_TEST_PA_API_PARTNER_TAG to run this test against the real PA-API.'
            );
        }

        $amazon = new Amazon_API($access_key, $secret_key, $partner_tag, $region);
        $product_data = $amazon->get_item($asin);

        fwrite(STDERR, sprintf(
            "\n[Amazon API pricing canary] GetItems(%s, %s) -> %s\n",
            $asin,
            $region,
            wp_json_encode($product_data)
        ));

        $this->assertNotNull($product_data, 'Expected the item itself to still be found: ' . $amazon->get_last_error());
        $this->assertNull($amazon->get_last_error());

        $this->assertArrayHasKey('pricing', $product_data);
        $pricing = $product_data['pricing'];

        $this->assertNull(
            $pricing['current_price'],
            'Amazon appears to have started returning pricing for this account - the 3-qualifying-sales-in-180-days ' .
            'restriction may have cleared. This is good news: update Product_Service/tests to expect real prices, ' .
            'then remove or repurpose this canary test.'
        );
        $this->assertSame('unknown', $pricing['availability']);
    }
}

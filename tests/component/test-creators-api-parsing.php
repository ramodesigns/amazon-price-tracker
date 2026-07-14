<?php
/**
 * Creators API Response Parsing Component Test
 *
 * Drives Amazon_Creators_API::get_item() directly (no Product_Service, no
 * REST) against rich canned catalog responses via creators-api-mock.php,
 * pinning down parse_item()/parse_pricing() - the response-parsing layer the
 * endpoint-level tests only graze with minimal fixtures. The parsed
 * 'asin'/'images'/'facts'/'pricing' shape is the contract everything above
 * this class stores and serves, so a silently dropped field here corrupts
 * every product created or refreshed afterwards.
 *
 * @package AmazonPriceTracker\Tests\Component
 */

use APT\Services\Amazon_Creators_API;

require_once __DIR__ . '/creators-api-mock.php';

/**
 * Test case for Creators API response parsing.
 */
class Test_Creators_API_Parsing_Component extends WP_UnitTestCase {

    public function tearDown(): void {
        apt_test_reset_creators_api_responses();

        parent::tearDown();
    }

    private function api(): Amazon_Creators_API {
        return new Amazon_Creators_API('test-credential-id', 'test-credential-secret', '3.2', 'test-partner-tag', 'UK');
    }

    /**
     * Wrap a single raw catalog item in the getItems response envelope and
     * queue it.
     */
    private function queue_item(array $item): void {
        apt_test_queue_creators_api_response(200, [
            'itemsResult' => ['items' => [$item]],
        ]);
    }

    public function test_parse_item_maps_every_iteminfo_section_into_facts(): void {
        $this->queue_item([
            'asin' => 'B0PARSEFUL',
            'parentASIN' => 'B0PARENT01',
            'itemInfo' => [
                'title' => ['displayValue' => 'Full Fixture Product'],
                'byLineInfo' => [
                    'brand' => ['displayValue' => 'ACME'],
                    'manufacturer' => ['displayValue' => 'ACME Manufacturing'],
                ],
                'features' => ['displayValues' => ['Feature one', 'Feature two']],
                'productInfo' => [
                    'color' => ['displayValue' => 'Red'],
                    'size' => ['displayValue' => 'Large'],
                    'itemDimensions' => [
                        'height' => ['displayValue' => '10', 'unit' => 'inches'],
                        'length' => ['displayValue' => '20', 'unit' => 'inches'],
                        'width' => ['displayValue' => '30', 'unit' => 'inches'],
                    ],
                    'unitCount' => ['displayValue' => 2],
                ],
                'technicalInfo' => [
                    'formats' => ['displayValues' => ['Blu-ray']],
                ],
                'manufactureInfo' => [
                    'model' => ['displayValue' => 'MODEL-123'],
                    'itemPartNumber' => ['displayValue' => 'PART-456'],
                ],
                'classifications' => [
                    'binding' => ['displayValue' => 'Hardcover'],
                    'productGroup' => ['displayValue' => 'Book'],
                ],
                'contentInfo' => [
                    'edition' => ['displayValue' => '2nd'],
                    'languages' => ['displayValues' => [
                        ['displayValue' => 'English'],
                        ['displayValue' => 'German'],
                    ]],
                    'publicationDate' => ['displayValue' => '2024-01-15'],
                ],
                'externalIds' => [
                    'eans' => ['displayValues' => ['5012345678900']],
                    'upcs' => ['displayValues' => ['012345678905']],
                    'isbns' => ['displayValues' => ['978-3-16-148410-0']],
                ],
            ],
            'images' => [
                'primary' => [
                    'large' => ['url' => 'https://example.com/main.jpg', 'height' => 500, 'width' => 500],
                ],
                'variants' => [
                    ['large' => ['url' => 'https://example.com/variant.jpg', 'height' => 400, 'width' => 400]],
                    // A variant without a 'large' rendition must be skipped, not crash.
                    ['medium' => ['url' => 'https://example.com/medium-only.jpg']],
                ],
            ],
            'browseNodeInfo' => [
                'browseNodes' => [
                    [
                        'id' => '12345',
                        'displayName' => 'Collectible Figures',
                        'ancestor' => [
                            'displayName' => 'Hobbies',
                            'ancestor' => ['displayName' => 'Toys & Games'],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $this->api()->get_item('B0PARSEFUL');

        $this->assertSame(
            [
                'title' => 'Full Fixture Product',
                'brand' => 'ACME',
                'manufacturer' => 'ACME Manufacturing',
                'features' => ['Feature one', 'Feature two'],
                'color' => 'Red',
                'size' => 'Large',
                'dimensions' => '10 inches x 20 inches x 30 inches',
                'unit_count' => 2,
                'formats' => ['Blu-ray'],
                'model_number' => 'MODEL-123',
                'part_number' => 'PART-456',
                'binding' => 'Hardcover',
                'product_group' => 'Book',
                'edition' => '2nd',
                'languages' => ['English', 'German'],
                'release_date' => '2024-01-15',
                'ean' => '5012345678900',
                'upc' => '012345678905',
                'isbn' => '978-3-16-148410-0',
                'amazon_category' => 'Toys & Games > Hobbies > Collectible Figures',
                'amazon_category_id' => '12345',
                'parent_asin' => 'B0PARENT01',
            ],
            $result['facts']
        );

        $this->assertSame(
            [
                ['url' => 'https://example.com/main.jpg', 'height' => 500, 'width' => 500, 'type' => 'main'],
                ['url' => 'https://example.com/variant.jpg', 'height' => 400, 'width' => 400, 'type' => 'variant'],
            ],
            $result['images']
        );
    }

    public function test_parse_item_of_a_minimal_item_drops_null_facts(): void {
        // Real responses for restricted accounts can be this bare (title
        // only, no offers) - nothing optional may leak in as null entries.
        $this->queue_item([
            'asin' => 'B0PARSEMIN',
            'itemInfo' => ['title' => ['displayValue' => 'Bare Product']],
        ]);

        $result = $this->api()->get_item('B0PARSEMIN');

        $this->assertSame(['title' => 'Bare Product'], $result['facts']);
        $this->assertSame([], $result['images']);
        $this->assertSame(
            ['current_price' => null, 'rrp' => null, 'is_prime_price' => false, 'availability' => 'unknown'],
            $result['pricing']
        );
    }

    public function test_parse_pricing_reads_price_rrp_and_in_stock_availability(): void {
        $this->queue_item([
            'asin' => 'B0PRICEFUL',
            'itemInfo' => ['title' => ['displayValue' => 'Priced Product']],
            'offersV2' => [
                'listings' => [
                    [
                        'price' => [
                            'money' => ['amount' => 19.99],
                            'savingBasis' => ['money' => ['amount' => 29.99]],
                        ],
                        'availability' => ['type' => 'IN_STOCK', 'message' => 'In stock'],
                    ],
                ],
            ],
        ]);

        $pricing = $this->api()->get_item('B0PRICEFUL')['pricing'];

        $this->assertSame(19.99, $pricing['current_price']);
        $this->assertSame(29.99, $pricing['rrp']);
        $this->assertSame('in_stock', $pricing['availability']);
    }

    public function test_parse_pricing_nulls_the_price_when_out_of_stock(): void {
        $this->queue_item([
            'asin' => 'B0PRICEOOS',
            'itemInfo' => ['title' => ['displayValue' => 'Gone Product']],
            'offersV2' => [
                'listings' => [
                    [
                        // A price can still be present on an out-of-stock
                        // listing; storing it would fake a purchasable price.
                        'price' => ['money' => ['amount' => 9.99]],
                        'availability' => ['type' => 'OUT_OF_STOCK', 'message' => 'Currently out of stock.'],
                    ],
                ],
            ],
        ]);

        $pricing = $this->api()->get_item('B0PRICEOOS')['pricing'];

        $this->assertSame('out_of_stock', $pricing['availability']);
        $this->assertNull($pricing['current_price']);
    }

    public function test_parse_pricing_detects_limited_stock_from_the_message(): void {
        $this->queue_item([
            'asin' => 'B0PRICELTD',
            'itemInfo' => ['title' => ['displayValue' => 'Scarce Product']],
            'offersV2' => [
                'listings' => [
                    [
                        'price' => ['money' => ['amount' => 14.99]],
                        'availability' => ['type' => 'IN_STOCK', 'message' => 'Only 3 left in stock.'],
                    ],
                ],
            ],
        ]);

        $pricing = $this->api()->get_item('B0PRICELTD')['pricing'];

        // "Only N left" must win over the IN_STOCK type...
        $this->assertSame('limited_stock', $pricing['availability']);
        // ...but the item is still purchasable, so the price stays.
        $this->assertSame(14.99, $pricing['current_price']);
    }

    public function test_parse_pricing_maps_preorder_and_unknown_types(): void {
        $this->queue_item([
            'asin' => 'B0PRICEPRE',
            'itemInfo' => ['title' => ['displayValue' => 'Upcoming Product']],
            'offersV2' => [
                'listings' => [
                    [
                        'price' => ['money' => ['amount' => 49.99]],
                        'availability' => ['type' => 'PREORDER'],
                    ],
                ],
            ],
        ]);
        $this->assertSame('preorder', $this->api()->get_item('B0PRICEPRE')['pricing']['availability']);

        $this->queue_item([
            'asin' => 'B0PRICEUNK',
            'itemInfo' => ['title' => ['displayValue' => 'Odd Product']],
            'offersV2' => [
                'listings' => [
                    [
                        'price' => ['money' => ['amount' => 5.00]],
                        'availability' => ['type' => 'SOME_FUTURE_VOCABULARY'],
                    ],
                ],
            ],
        ]);
        $this->assertSame('unknown', $this->api()->get_item('B0PRICEUNK')['pricing']['availability']);
    }
}

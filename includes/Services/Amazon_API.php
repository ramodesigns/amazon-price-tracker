<?php
/**
 * Amazon Product Advertising API 5.0 Client
 *
 * Handles communication with Amazon PA-API using AWS Signature v4.
 *
 * @package AmazonPriceTracker
 */

namespace APT\Services;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use APT\Helpers\Regions;
use APT\Helpers\Encryption;

/**
 * Class Amazon_API
 */
class Amazon_API {

    /**
     * PA-API Service name
     */
    private const SERVICE = 'ProductAdvertisingAPI';

    /**
     * PA-API version
     */
    private const API_VERSION = 'v1';

    /**
     * Access key
     *
     * @var string
     */
    private string $access_key;

    /**
     * Secret key
     *
     * @var string
     */
    private string $secret_key;

    /**
     * Partner tag
     *
     * @var string
     */
    private string $partner_tag;

    /**
     * Region code
     *
     * @var string
     */
    private string $region_code;

    /**
     * AWS region
     *
     * @var string
     */
    private string $aws_region;

    /**
     * API host
     *
     * @var string
     */
    private string $host;

    /**
     * Last error message
     *
     * @var string|null
     */
    private ?string $last_error = null;

    /**
     * Last response time in milliseconds
     *
     * @var int
     */
    private int $last_response_time = 0;

    /**
     * Constructor
     *
     * @param string $access_key Amazon PA-API Access Key
     * @param string $secret_key Amazon PA-API Secret Key
     * @param string $partner_tag Partner/Associate tag
     * @param string $region_code Region code (US, UK, etc.)
     */
    public function __construct(string $access_key, string $secret_key, string $partner_tag, string $region_code) {
        $this->access_key = $access_key;
        $this->secret_key = $secret_key;
        $this->partner_tag = $partner_tag;
        $this->region_code = strtoupper($region_code);

        $this->host = Regions::get_api_host($this->region_code) ?: 'webservices.amazon.com';
        $this->aws_region = Regions::get_aws_region($this->region_code) ?: 'us-east-1';
    }

    /**
     * Create instance from user settings
     *
     * @param object $settings User settings record
     * @param string $region_code Region code
     * @return self|null Returns null if settings are incomplete
     */
    public static function from_settings(object $settings, string $region_code): ?self {
        // A settings object may now carry only Creators API credentials
        // (see Product_Service::get_env_fallback_settings()), so
        // access_key/secret_key are no longer guaranteed to be set here.
        if (empty($settings->access_key) || empty($settings->secret_key)) {
            return null;
        }

        $access_key = Encryption::decrypt($settings->access_key);
        $secret_key = Encryption::decrypt($settings->secret_key);

        if (empty($access_key) || empty($secret_key)) {
            return null;
        }

        $partner_tags = json_decode($settings->partner_tags, true) ?: [];
        $partner_tag = $partner_tags[strtoupper($region_code)] ?? null;

        if (empty($partner_tag)) {
            return null;
        }

        return new self($access_key, $secret_key, $partner_tag, $region_code);
    }

    /**
     * Get product information by ASIN
     *
     * @param string $asin Product ASIN
     * @return array|null Product data or null on failure
     */
    public function get_item(string $asin): ?array {
        return $this->get_items([$asin])[$asin] ?? null;
    }

    /**
     * Get multiple products by ASINs
     *
     * @param array $asins Array of ASINs (max 10)
     * @return array Associative array of ASIN => product data
     */
    public function get_items(array $asins): array {
        $asins = array_slice($asins, 0, 10); // PA-API limit

        $payload = [
            'ItemIds' => $asins,
            'PartnerTag' => $this->partner_tag,
            'PartnerType' => 'Associates',
            'Marketplace' => 'www.' . Regions::get_marketplace_domain($this->region_code),
            'Resources' => $this->get_item_resources(),
        ];

        $response = $this->request('GetItems', $payload);

        if (!$response || !isset($response['ItemsResult']['Items'])) {
            return [];
        }

        $results = [];
        foreach ($response['ItemsResult']['Items'] as $item) {
            $asin = $item['ASIN'] ?? null;
            if ($asin) {
                if (!isset($item['Offers']) && defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(
                        "Amazon PA-API: response for {$asin} had no Offers resource despite a successful " .
                        'request - Amazon omits pricing/availability (rather than erroring) for Associates ' .
                        'accounts that have not referred at least 3 qualifying sales in the trailing 180 ' .
                        'days. current_price/availability will be saved as null/unknown for this item.'
                    );
                }
                $results[$asin] = $this->parse_item($item);
            }
        }

        return $results;
    }

    /**
     * Test API connectivity
     *
     * @return bool True if connection successful
     */
    public function test_connection(): bool {
        // Use a simple search to test connectivity
        $payload = [
            'Keywords' => 'test',
            'PartnerTag' => $this->partner_tag,
            'PartnerType' => 'Associates',
            'Marketplace' => 'www.' . Regions::get_marketplace_domain($this->region_code),
            'Resources' => ['SearchResult.Items.ASIN'],
            'ItemCount' => 1,
            'SearchIndex' => 'All',
        ];

        $response = $this->request('SearchItems', $payload);

        return $response !== null;
    }

    /**
     * Get last error message
     *
     * @return string|null
     */
    public function get_last_error(): ?string {
        return $this->last_error;
    }

    /**
     * Get last response time in milliseconds
     *
     * @return int
     */
    public function get_last_response_time(): int {
        return $this->last_response_time;
    }

    /**
     * Make API request
     *
     * @param string $operation API operation name
     * @param array $payload Request payload
     * @return array|null Response data or null on failure
     */
    private function request(string $operation, array $payload): ?array {
        $this->last_error = null;
        $path = '/paapi5/' . strtolower($operation);
        $target = 'com.amazon.paapi5.v1.ProductAdvertisingAPIv1.' . $operation;

        $payload_json = wp_json_encode($payload);
        $timestamp = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');

        // Build headers
        $headers = [
            'content-encoding' => 'amz-1.0',
            'content-type' => 'application/json; charset=utf-8',
            'host' => $this->host,
            'x-amz-date' => $timestamp,
            'x-amz-target' => $target,
        ];

        // Create canonical request
        $canonical_headers = $this->build_canonical_headers($headers);
        $signed_headers = $this->build_signed_headers($headers);
        $payload_hash = hash('sha256', $payload_json);

        $canonical_request = implode("\n", [
            'POST',
            $path,
            '', // Query string (empty)
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ]);

        // Create string to sign
        $credential_scope = "{$date}/{$this->aws_region}/" . self::SERVICE . '/aws4_request';
        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $timestamp,
            $credential_scope,
            hash('sha256', $canonical_request),
        ]);

        // Calculate signature
        $signature = $this->calculate_signature($date, $string_to_sign);

        // Build authorization header
        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->access_key,
            $credential_scope,
            $signed_headers,
            $signature
        );

        // Prepare request headers for WordPress HTTP API
        $request_headers = [
            'Authorization' => $authorization,
            'Content-Encoding' => 'amz-1.0',
            'Content-Type' => 'application/json; charset=utf-8',
            'Host' => $this->host,
            'X-Amz-Date' => $timestamp,
            'X-Amz-Target' => $target,
        ];

        $url = 'https://' . $this->host . $path;

        // Debug: Log request details if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Amazon PA-API Request: ' . $operation . ' to ' . $url);
            error_log('Amazon PA-API Payload: ' . $payload_json);
        }

        $start_time = microtime(true);

        $response = wp_remote_post($url, [
            'headers' => $request_headers,
            'body' => $payload_json,
            'timeout' => 30,
        ]);

        $this->last_response_time = (int) ((microtime(true) - $start_time) * 1000);

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status_code !== 200) {
            // Try to extract detailed error message from Amazon's response
            $error_message = "HTTP {$status_code} error";

            if (is_array($data)) {
                // PA-API 5.0 error format
                if (isset($data['Errors'][0]['Message'])) {
                    $error_message = $data['Errors'][0]['Message'];
                } elseif (isset($data['__type']) && isset($data['message'])) {
                    // Alternative error format
                    $error_message = $data['message'];
                } elseif (isset($data['Message'])) {
                    // Simple message format
                    $error_message = $data['Message'];
                }
            }

            // Log the full error response for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Amazon PA-API Error: Status ' . $status_code . ', Response: ' . $body);
            }

            $this->last_error = $error_message;
            return null;
        }

        return $data;
    }

    /**
     * Build canonical headers string
     *
     * @param array $headers Headers array
     * @return string
     */
    private function build_canonical_headers(array $headers): string {
        ksort($headers);
        $canonical = '';
        foreach ($headers as $key => $value) {
            $canonical .= strtolower($key) . ':' . trim($value) . "\n";
        }
        return $canonical;
    }

    /**
     * Build signed headers string
     *
     * @param array $headers Headers array
     * @return string
     */
    private function build_signed_headers(array $headers): string {
        $keys = array_keys($headers);
        sort($keys);
        return implode(';', array_map('strtolower', $keys));
    }

    /**
     * Calculate AWS Signature v4
     *
     * @param string $date Date in Ymd format
     * @param string $string_to_sign String to sign
     * @return string Hex-encoded signature
     */
    private function calculate_signature(string $date, string $string_to_sign): string {
        $k_date = hash_hmac('sha256', $date, 'AWS4' . $this->secret_key, true);
        $k_region = hash_hmac('sha256', $this->aws_region, $k_date, true);
        $k_service = hash_hmac('sha256', self::SERVICE, $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);

        return hash_hmac('sha256', $string_to_sign, $k_signing);
    }

    /**
     * Get resources to request for items
     *
     * @return array
     */
    private function get_item_resources(): array {
        return [
            // Item info
            'ItemInfo.Title',
            'ItemInfo.ByLineInfo',
            'ItemInfo.ContentInfo',
            'ItemInfo.ContentRating',
            'ItemInfo.Classifications',
            'ItemInfo.ExternalIds',
            'ItemInfo.Features',
            'ItemInfo.ManufactureInfo',
            'ItemInfo.ProductInfo',
            'ItemInfo.TechnicalInfo',
            'ItemInfo.TradeInInfo',
            // Images
            'Images.Primary.Large',
            'Images.Variants.Large',
            // Offers (pricing)
            'Offers.Listings.Price',
            'Offers.Listings.SavingBasis',
            'Offers.Listings.Promotions',
            'Offers.Listings.Condition',
            'Offers.Listings.Availability.Type',
            'Offers.Listings.Availability.Message',
            'Offers.Listings.DeliveryInfo.IsPrimeEligible',
            'Offers.Listings.MerchantInfo',
            'Offers.Summaries.LowestPrice',
            'Offers.Summaries.HighestPrice',
            // Browse node (category)
            'BrowseNodeInfo.BrowseNodes',
            'BrowseNodeInfo.BrowseNodes.Ancestor',
            // Parent ASIN
            'ParentASIN',
        ];
    }

    /**
     * Parse item response into standardized format
     *
     * @param array $item Raw item data from API
     * @return array Parsed product data
     */
    private function parse_item(array $item): array {
        $info = $item['ItemInfo'] ?? [];
        $offers = $item['Offers'] ?? [];
        $images = $item['Images'] ?? [];
        $browse_nodes = $item['BrowseNodeInfo']['BrowseNodes'] ?? [];

        // Parse images
        $parsed_images = [];
        if (isset($images['Primary']['Large'])) {
            $parsed_images[] = [
                'url' => $images['Primary']['Large']['URL'],
                'height' => $images['Primary']['Large']['Height'] ?? null,
                'width' => $images['Primary']['Large']['Width'] ?? null,
                'type' => 'main',
            ];
        }
        if (isset($images['Variants'])) {
            foreach ($images['Variants'] as $variant) {
                if (isset($variant['Large'])) {
                    $parsed_images[] = [
                        'url' => $variant['Large']['URL'],
                        'height' => $variant['Large']['Height'] ?? null,
                        'width' => $variant['Large']['Width'] ?? null,
                        'type' => 'variant',
                    ];
                }
            }
        }

        // Parse facts
        $facts = [
            'title' => $info['Title']['DisplayValue'] ?? null,
        ];

        // Brand/Manufacturer
        if (isset($info['ByLineInfo']['Brand']['DisplayValue'])) {
            $facts['brand'] = $info['ByLineInfo']['Brand']['DisplayValue'];
        }
        if (isset($info['ByLineInfo']['Manufacturer']['DisplayValue'])) {
            $facts['manufacturer'] = $info['ByLineInfo']['Manufacturer']['DisplayValue'];
        }

        // Features
        if (isset($info['Features']['DisplayValues'])) {
            $facts['features'] = $info['Features']['DisplayValues'];
        }

        // Product info
        if (isset($info['ProductInfo'])) {
            $product_info = $info['ProductInfo'];
            if (isset($product_info['Color']['DisplayValue'])) {
                $facts['color'] = $product_info['Color']['DisplayValue'];
            }
            if (isset($product_info['Size']['DisplayValue'])) {
                $facts['size'] = $product_info['Size']['DisplayValue'];
            }
            if (isset($product_info['ItemDimensions'])) {
                $dims = $product_info['ItemDimensions'];
                $parts = [];
                foreach (['Height', 'Length', 'Width'] as $dim) {
                    if (isset($dims[$dim])) {
                        $parts[] = $dims[$dim]['DisplayValue'] . ' ' . ($dims[$dim]['Unit'] ?? '');
                    }
                }
                if (!empty($parts)) {
                    $facts['dimensions'] = implode(' x ', $parts);
                }
            }
            if (isset($product_info['UnitCount']['DisplayValue'])) {
                $facts['unit_count'] = $product_info['UnitCount']['DisplayValue'];
            }
        }

        // Technical info
        if (isset($info['TechnicalInfo'])) {
            $tech_info = $info['TechnicalInfo'];
            if (isset($tech_info['Formats']['DisplayValues'])) {
                $facts['formats'] = $tech_info['Formats']['DisplayValues'];
            }
        }

        // Manufacture info
        if (isset($info['ManufactureInfo'])) {
            $mfg_info = $info['ManufactureInfo'];
            if (isset($mfg_info['Model']['DisplayValue'])) {
                $facts['model_number'] = $mfg_info['Model']['DisplayValue'];
            }
            if (isset($mfg_info['PartNumber']['DisplayValue'])) {
                $facts['part_number'] = $mfg_info['PartNumber']['DisplayValue'];
            }
        }

        // Classifications
        if (isset($info['Classifications'])) {
            $class = $info['Classifications'];
            if (isset($class['Binding']['DisplayValue'])) {
                $facts['binding'] = $class['Binding']['DisplayValue'];
            }
            if (isset($class['ProductGroup']['DisplayValue'])) {
                $facts['product_group'] = $class['ProductGroup']['DisplayValue'];
            }
        }

        // Content info
        if (isset($info['ContentInfo'])) {
            $content = $info['ContentInfo'];
            if (isset($content['Edition']['DisplayValue'])) {
                $facts['edition'] = $content['Edition']['DisplayValue'];
            }
            if (isset($content['Languages']['DisplayValues'])) {
                $facts['languages'] = array_map(function($lang) {
                    return $lang['DisplayValue'] ?? '';
                }, $content['Languages']['DisplayValues']);
            }
            if (isset($content['PublicationDate']['DisplayValue'])) {
                $facts['release_date'] = $content['PublicationDate']['DisplayValue'];
            }
        }

        // External IDs
        if (isset($info['ExternalIds'])) {
            $external = $info['ExternalIds'];
            if (isset($external['EANs']['DisplayValues'])) {
                $facts['ean'] = $external['EANs']['DisplayValues'][0] ?? null;
            }
            if (isset($external['UPCs']['DisplayValues'])) {
                $facts['upc'] = $external['UPCs']['DisplayValues'][0] ?? null;
            }
            if (isset($external['ISBNs']['DisplayValues'])) {
                $facts['isbn'] = $external['ISBNs']['DisplayValues'][0] ?? null;
            }
        }

        // Category from browse nodes
        if (!empty($browse_nodes)) {
            $category_parts = [];
            $browse_node = $browse_nodes[0];

            // Build category path from ancestors
            if (isset($browse_node['Ancestor'])) {
                $ancestor = $browse_node['Ancestor'];
                while ($ancestor) {
                    if (isset($ancestor['DisplayName'])) {
                        array_unshift($category_parts, $ancestor['DisplayName']);
                    }
                    $ancestor = $ancestor['Ancestor'] ?? null;
                }
            }

            if (isset($browse_node['DisplayName'])) {
                $category_parts[] = $browse_node['DisplayName'];
            }

            if (!empty($category_parts)) {
                $facts['amazon_category'] = implode(' > ', $category_parts);
            }

            if (isset($browse_node['Id'])) {
                $facts['amazon_category_id'] = $browse_node['Id'];
            }
        }

        // Parent ASIN
        if (isset($item['ParentASIN'])) {
            $facts['parent_asin'] = $item['ParentASIN'];
        }

        // Parse pricing
        $pricing = $this->parse_pricing($offers);

        return [
            'asin' => $item['ASIN'],
            'images' => $parsed_images,
            'facts' => array_filter($facts, function($v) { return $v !== null; }),
            'pricing' => $pricing,
        ];
    }

    /**
     * Parse pricing information from offers
     *
     * @param array $offers Offers data from API
     * @return array Pricing data
     */
    private function parse_pricing(array $offers): array {
        $pricing = [
            'current_price' => null,
            'rrp' => null,
            'is_prime_price' => false,
            'availability' => 'unknown',
        ];

        $listings = $offers['Listings'] ?? [];

        if (empty($listings)) {
            // Check summaries for pricing
            $summaries = $offers['Summaries'] ?? [];
            foreach ($summaries as $summary) {
                if (isset($summary['LowestPrice']['Amount'])) {
                    $pricing['current_price'] = (float) $summary['LowestPrice']['Amount'];
                    break;
                }
            }
            return $pricing;
        }

        // Get the first (best) listing
        $listing = $listings[0];

        // Current price
        if (isset($listing['Price']['Amount'])) {
            $pricing['current_price'] = (float) $listing['Price']['Amount'];
        }

        // RRP (Saving Basis is the original price)
        if (isset($listing['SavingBasis']['Amount'])) {
            $pricing['rrp'] = (float) $listing['SavingBasis']['Amount'];
        }

        // Prime eligibility
        if (isset($listing['DeliveryInfo']['IsPrimeEligible'])) {
            $pricing['is_prime_price'] = (bool) $listing['DeliveryInfo']['IsPrimeEligible'];
        }

        // Availability
        if (isset($listing['Availability']['Type'])) {
            $availability_type = strtolower($listing['Availability']['Type']);
            $pricing['availability'] = match($availability_type) {
                'now' => 'in_stock',
                'out of stock' => 'out_of_stock',
                'pre-order', 'preorder' => 'preorder',
                default => 'unknown',
            };

            // Check availability message for more details
            if (isset($listing['Availability']['Message'])) {
                $message = strtolower($listing['Availability']['Message']);
                if (str_contains($message, 'in stock')) {
                    $pricing['availability'] = 'in_stock';
                } elseif (str_contains($message, 'out of stock') || str_contains($message, 'unavailable')) {
                    $pricing['availability'] = 'out_of_stock';
                    $pricing['current_price'] = null;
                } elseif (str_contains($message, 'only') && str_contains($message, 'left')) {
                    $pricing['availability'] = 'limited_stock';
                }
            }
        }

        return $pricing;
    }
}

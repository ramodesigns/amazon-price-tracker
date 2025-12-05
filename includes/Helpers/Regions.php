<?php
/**
 * Regions Helper
 *
 * Manages Amazon marketplace region data.
 *
 * @package AmazonPriceTracker
 */

namespace APT\Helpers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Regions
 */
class Regions {

    /**
     * Supported Amazon marketplace regions
     *
     * @var array
     */
    private static $regions = [
        'US' => [
            'code' => 'US',
            'name' => 'United States',
            'marketplace_domain' => 'amazon.com',
            'currency' => 'USD',
            'host' => 'webservices.amazon.com',
            'region' => 'us-east-1',
        ],
        'CA' => [
            'code' => 'CA',
            'name' => 'Canada',
            'marketplace_domain' => 'amazon.ca',
            'currency' => 'CAD',
            'host' => 'webservices.amazon.ca',
            'region' => 'us-east-1',
        ],
        'UK' => [
            'code' => 'UK',
            'name' => 'United Kingdom',
            'marketplace_domain' => 'amazon.co.uk',
            'currency' => 'GBP',
            'host' => 'webservices.amazon.co.uk',
            'region' => 'eu-west-1',
        ],
        'DE' => [
            'code' => 'DE',
            'name' => 'Germany',
            'marketplace_domain' => 'amazon.de',
            'currency' => 'EUR',
            'host' => 'webservices.amazon.de',
            'region' => 'eu-west-1',
        ],
        'FR' => [
            'code' => 'FR',
            'name' => 'France',
            'marketplace_domain' => 'amazon.fr',
            'currency' => 'EUR',
            'host' => 'webservices.amazon.fr',
            'region' => 'eu-west-1',
        ],
        'ES' => [
            'code' => 'ES',
            'name' => 'Spain',
            'marketplace_domain' => 'amazon.es',
            'currency' => 'EUR',
            'host' => 'webservices.amazon.es',
            'region' => 'eu-west-1',
        ],
        'IT' => [
            'code' => 'IT',
            'name' => 'Italy',
            'marketplace_domain' => 'amazon.it',
            'currency' => 'EUR',
            'host' => 'webservices.amazon.it',
            'region' => 'eu-west-1',
        ],
        'AU' => [
            'code' => 'AU',
            'name' => 'Australia',
            'marketplace_domain' => 'amazon.com.au',
            'currency' => 'AUD',
            'host' => 'webservices.amazon.com.au',
            'region' => 'us-west-2',
        ],
        'JP' => [
            'code' => 'JP',
            'name' => 'Japan',
            'marketplace_domain' => 'amazon.co.jp',
            'currency' => 'JPY',
            'host' => 'webservices.amazon.co.jp',
            'region' => 'us-west-2',
        ],
        'IN' => [
            'code' => 'IN',
            'name' => 'India',
            'marketplace_domain' => 'amazon.in',
            'currency' => 'INR',
            'host' => 'webservices.amazon.in',
            'region' => 'eu-west-1',
        ],
        'MX' => [
            'code' => 'MX',
            'name' => 'Mexico',
            'marketplace_domain' => 'amazon.com.mx',
            'currency' => 'MXN',
            'host' => 'webservices.amazon.com.mx',
            'region' => 'us-east-1',
        ],
        'BR' => [
            'code' => 'BR',
            'name' => 'Brazil',
            'marketplace_domain' => 'amazon.com.br',
            'currency' => 'BRL',
            'host' => 'webservices.amazon.com.br',
            'region' => 'us-east-1',
        ],
    ];

    /**
     * Get all regions
     *
     * @return array
     */
    public static function get_all(): array {
        return self::$regions;
    }

    /**
     * Get region by code
     *
     * @param string $code Region code (e.g., 'US', 'UK')
     * @return array|null Region data or null if not found
     */
    public static function get(string $code): ?array {
        $code = strtoupper($code);
        return self::$regions[$code] ?? null;
    }

    /**
     * Check if region code is valid
     *
     * @param string $code Region code
     * @return bool
     */
    public static function is_valid(string $code): bool {
        return isset(self::$regions[strtoupper($code)]);
    }

    /**
     * Get all region codes
     *
     * @return array
     */
    public static function get_codes(): array {
        return array_keys(self::$regions);
    }

    /**
     * Get currency for region
     *
     * @param string $code Region code
     * @return string|null Currency code or null if region not found
     */
    public static function get_currency(string $code): ?string {
        $region = self::get($code);
        return $region ? $region['currency'] : null;
    }

    /**
     * Get Amazon PA-API host for region
     *
     * @param string $code Region code
     * @return string|null Host or null if region not found
     */
    public static function get_api_host(string $code): ?string {
        $region = self::get($code);
        return $region ? $region['host'] : null;
    }

    /**
     * Get AWS region for Amazon PA-API
     *
     * @param string $code Region code
     * @return string|null AWS region or null if region not found
     */
    public static function get_aws_region(string $code): ?string {
        $region = self::get($code);
        return $region ? $region['region'] : null;
    }

    /**
     * Get marketplace domain for region
     *
     * @param string $code Region code
     * @return string|null Domain or null if region not found
     */
    public static function get_marketplace_domain(string $code): ?string {
        $region = self::get($code);
        return $region ? $region['marketplace_domain'] : null;
    }

    /**
     * Get product URL for ASIN and region
     *
     * @param string $asin Product ASIN
     * @param string $region_code Region code
     * @param string|null $partner_tag Optional partner tag
     * @return string|null Product URL or null if region not found
     */
    public static function get_product_url(string $asin, string $region_code, ?string $partner_tag = null): ?string {
        $domain = self::get_marketplace_domain($region_code);

        if (!$domain) {
            return null;
        }

        $url = "https://www.{$domain}/dp/{$asin}";

        if ($partner_tag) {
            $url .= "?tag={$partner_tag}";
        }

        return $url;
    }

    /**
     * Get regions formatted for API response
     *
     * @return array
     */
    public static function get_for_api(): array {
        $result = [];

        foreach (self::$regions as $region) {
            $result[] = [
                'code' => $region['code'],
                'name' => $region['name'],
                'marketplace_domain' => $region['marketplace_domain'],
                'currency' => $region['currency'],
            ];
        }

        return $result;
    }
}

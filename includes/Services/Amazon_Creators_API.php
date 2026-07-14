<?php
/**
 * Amazon Creators API Client
 *
 * Handles communication with Amazon's Creators API - the OAuth 2.0-based
 * replacement for the Product Advertising API (PA-API 5.0) that
 * Amazon_API.php talks to. Amazon is retiring PA-API 5.0, so this exists to
 * let Product_Service swap between the two without its call sites caring
 * which one they're talking to - see the class docblock below for the
 * contract this deliberately mirrors.
 *
 * Endpoint/field/auth details below are taken from Amazon's own
 * creatorsapi-php-sdk (docs/creatorsapi-php-sdk/), not secondhand write-ups -
 * specifically src/com/amazon/creators/auth/{OAuth2Config,OAuth2TokenManager}.php
 * for the OAuth flow, src/com/amazon/creators/api/DefaultApi.php for the
 * endpoint paths/headers, and the src/com/amazon/creators/model/*.php request
 * and response models for field names. This class does not depend on that
 * SDK package itself (it requires PHP 8.1+ and bundles Guzzle, which would
 * both force a PHP version bump for this plugin and risk dependency
 * conflicts in a WordPress install) - it reimplements the same wire
 * behavior using wp_remote_post(), matching Amazon_API.php's existing
 * convention and this plugin's pre_http_request-based test mocking.
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
 * Class Amazon_Creators_API
 *
 * Deliberately mirrors Amazon_API's public contract - constructor shape,
 * get_item()/get_items()'s input and return types, test_connection(),
 * get_last_error(), get_last_response_time(), and the from_settings()
 * static factory pattern - so Product_Service can construct and call
 * whichever implementation it chooses identically. This only holds if
 * get_items()'s return shape (ASIN => parsed product data, same 'images'/
 * 'facts'/'pricing' structure Amazon_API::parse_item() produces) actually
 * matches; that's the whole premise this class is built on.
 *
 * One known, real gap: the Creators API's offer listing model has no
 * equivalent to PA-API's Offers.Listings.DeliveryInfo.IsPrimeEligible -
 * 'is_prime_price' can't be populated from anything this API exposes, so it
 * always comes back false here. See parse_pricing().
 */
class Amazon_Creators_API {

    /**
     * Catalog API host
     */
    private const API_HOST = 'https://creatorsapi.amazon';

    /**
     * OAuth2 scope for Cognito (v2.x) credentials
     */
    private const COGNITO_SCOPE = 'creatorsapi/default';

    /**
     * OAuth2 scope for Login-with-Amazon (v3.x) credentials
     */
    private const LWA_SCOPE = 'creatorsapi::default';

    /**
     * Credential ID (from Associates Central, Tools > Creators API)
     *
     * @var string
     */
    private string $credential_id;

    /**
     * Credential secret
     *
     * @var string
     */
    private string $credential_secret;

    /**
     * Credential version - selects the auth flavor (Cognito v2.x vs.
     * Login-with-Amazon v3.x) and regional token endpoint cluster.
     * One of: 2.1, 2.2, 2.3, 3.1, 3.2, 3.3.
     *
     * @var string
     */
    private string $version;

    /**
     * Partner tag
     *
     * @var string
     */
    private string $partner_tag;

    /**
     * Region code - marketplace (the x-marketplace header value) is
     * derived from this via Regions::get_marketplace_domain(), same as
     * Amazon_API. Amazon's own SDK treats marketplace as a per-call
     * parameter (DefaultApi::getItems($marketplace, ...)), not part of the
     * stored credential (Configuration only holds credentialId/
     * credentialSecret/version) - so deriving it per request, rather than
     * storing one fixed marketplace on the credential, is what actually
     * supports a single credential covering multiple regions/marketplaces.
     *
     * @var string
     */
    private string $region_code;

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
     * @param string $credential_id Creators API credential ID
     * @param string $credential_secret Creators API credential secret
     * @param string $version Credential version (e.g. "3.1")
     * @param string $partner_tag Partner/Associate tag
     * @param string $region_code Region code (US, UK, etc.)
     */
    public function __construct(string $credential_id, string $credential_secret, string $version, string $partner_tag, string $region_code) {
        $this->credential_id = $credential_id;
        $this->credential_secret = $credential_secret;
        // Accept both "3.2" and "v3.2" - Amazon's Associates Central UI
        // displays the version with a "v" prefix, but the SDK's own
        // internal token-endpoint switch (OAuth2Config::getTokenEndpoint())
        // matches bare digits with no prefix.
        $this->version = ltrim($version, 'vV');
        $this->partner_tag = $partner_tag;
        $this->region_code = strtoupper($region_code);
    }

    /**
     * Create instance from user settings
     *
     * Reads the creators_credential_id/creators_credential_secret/
     * creators_credential_version columns (or a local .env, see
     * Product_Service::get_env_fallback_settings()).
     *
     * @param object $settings User settings record
     * @param string $region_code Region code
     * @return self|null Returns null if settings are incomplete
     */
    public static function from_settings(object $settings, string $region_code): ?self {
        if (empty($settings->creators_credential_id) || empty($settings->creators_credential_secret) || empty($settings->creators_credential_version)) {
            return null;
        }

        $credential_id = Encryption::decrypt($settings->creators_credential_id);
        $credential_secret = Encryption::decrypt($settings->creators_credential_secret);

        if (empty($credential_id) || empty($credential_secret)) {
            return null;
        }

        $partner_tags = json_decode($settings->partner_tags ?? '', true) ?: [];
        $partner_tag = $partner_tags[strtoupper($region_code)] ?? null;

        if (empty($partner_tag)) {
            return null;
        }

        return new self($credential_id, $credential_secret, $settings->creators_credential_version, $partner_tag, $region_code);
    }

    /**
     * Get product information by ASIN
     *
     * Same contract as Amazon_API::get_item(): a single ASIN in, that
     * ASIN's parsed product data (or null if not found/failed) out.
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
     * Same contract as Amazon_API::get_items(): up to 10 ASINs in
     * (Creators API's getItems cap matches PA-API's), an associative
     * ASIN => parsed product data array out - same shape as
     * Amazon_API::parse_item() produces, so callers (Product_Service) don't
     * need to know which implementation they're talking to.
     *
     * @param array $asins Array of ASINs (max 10)
     * @return array Associative array of ASIN => product data
     */
    public function get_items(array $asins): array {
        $asins = array_slice($asins, 0, 10);

        // marketplace and partnerType go via header/are implicit, not part
        // of the body - unlike PA-API's payload shape (GetItemsRequestContent
        // has no marketplace/partnerType field; the SDK sends marketplace as
        // the x-marketplace header instead, see request()).
        $payload = [
            'partnerTag' => $this->partner_tag,
            'itemIds' => $asins,
            'resources' => $this->get_item_resources(),
        ];

        $response = $this->request('getItems', $payload);

        if (!$response) {
            // last_error already set by request().
            return [];
        }

        if (!isset($response['itemsResult']['items'])) {
            // Distinct from a genuinely-not-found ASIN (see below): a 200
            // response missing itemsResult.items entirely means something
            // about the response shape itself is wrong, not that the
            // requested item doesn't exist. Setting last_error here lets
            // Product_Service::fetch_amazon_product_data() tell the two
            // apart, since neither produces a parsed item either way.
            $this->last_error = 'Unexpected response shape: missing itemsResult.items';
            return [];
        }

        // itemsResult.items is present but may simply not contain the
        // requested ASIN - Creators API has no per-item "not found" error,
        // an unmatched ASIN is just absent here. last_error stays null in
        // that case, which is how the caller distinguishes it from the
        // malformed-response case above.
        $results = [];

        foreach ($response['itemsResult']['items'] as $item) {
            $asin = $item['asin'] ?? null;

            if ($asin) {
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
        $payload = [
            'partnerTag' => $this->partner_tag,
            'keywords' => 'test',
            'itemCount' => 1,
            'resources' => ['itemInfo.title'],
        ];

        $response = $this->request('searchItems', $payload);

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
     * Get a valid OAuth 2.0 access token, using a cached one if not yet
     * expired.
     *
     * @return string|null
     */
    private function get_access_token(): ?string {
        $cache_key = 'apt_creators_api_token_' . md5($this->credential_id . '|' . $this->version);
        $cached = get_transient($cache_key);

        if ($cached) {
            return $cached;
        }

        $token_data = $this->request_access_token();

        if (!$token_data || empty($token_data['access_token'])) {
            return null;
        }

        // 30-second buffer, matching OAuth2TokenManager::refreshToken() in
        // the reference SDK, so a token doesn't expire mid-flight.
        $ttl = max(0, $token_data['expires_in'] - 30);
        set_transient($cache_key, $token_data['access_token'], $ttl);

        return $token_data['access_token'];
    }

    /**
     * Get the OAuth2 token endpoint for the configured credential version.
     *
     * Cognito (v2.x) and Login-with-Amazon (v3.x) each have three
     * regional-cluster endpoints. See OAuth2Config::getTokenEndpoint() in
     * the reference SDK.
     *
     * @return string|null Null if the version is unrecognized.
     */
    private function get_token_endpoint(): ?string {
        return match ($this->version) {
            '2.1' => 'https://creatorsapi.auth.us-east-1.amazoncognito.com/oauth2/token',
            '2.2' => 'https://creatorsapi.auth.eu-south-2.amazoncognito.com/oauth2/token',
            '2.3' => 'https://creatorsapi.auth.us-west-2.amazoncognito.com/oauth2/token',
            '3.1' => 'https://api.amazon.com/auth/o2/token',
            '3.2' => 'https://api.amazon.co.uk/auth/o2/token',
            '3.3' => 'https://api.amazon.co.jp/auth/o2/token',
            default => null,
        };
    }

    /**
     * Whether the configured credential version uses Login-with-Amazon
     * (v3.x) rather than Cognito (v2.x) - the two use different request
     * encodings, OAuth scopes, and Authorization header formats.
     *
     * @return bool
     */
    private function is_lwa(): bool {
        return str_starts_with($this->version, '3.');
    }

    /**
     * Request a fresh OAuth 2.0 access token via the client-credentials
     * grant.
     *
     * LWA (v3.x) sends a JSON body; Cognito (v2.x) sends form-encoded -
     * see OAuth2TokenManager::refreshToken() in the reference SDK.
     *
     * @return array{access_token: string, expires_in: int}|null
     */
    private function request_access_token(): ?array {
        $this->last_error = null;

        $endpoint = $this->get_token_endpoint();

        if (!$endpoint) {
            $this->last_error = "Unsupported credential version: {$this->version}";
            return null;
        }

        $fields = [
            'grant_type' => 'client_credentials',
            // These two keys are the OAuth2 client-credentials grant's own
            // field names (RFC 6749) - not to be confused with this
            // plugin's credential_id/credential_secret property names.
            'client_id' => $this->credential_id,
            'client_secret' => $this->credential_secret,
            'scope' => $this->is_lwa() ? self::LWA_SCOPE : self::COGNITO_SCOPE,
        ];

        $args = [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => $this->is_lwa() ? 'application/json' : 'application/x-www-form-urlencoded',
            ],
            'body' => $this->is_lwa() ? wp_json_encode($fields) : $fields,
        ];

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Amazon Creators API: requesting access token from ' . $endpoint);
        }

        $response = wp_remote_post($endpoint, $args);

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status_code < 200 || $status_code >= 300 || !isset($data['access_token'])) {
            $this->last_error = is_array($data) && isset($data['message'])
                ? $data['message']
                : "Token request failed with HTTP {$status_code}";

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Amazon Creators API: token request failed, status ' . $status_code . ', response: ' . $body);
            }

            return null;
        }

        return [
            'access_token' => $data['access_token'],
            'expires_in' => isset($data['expires_in']) ? (int) $data['expires_in'] : 3600,
        ];
    }

    /**
     * Make an authenticated catalog API request.
     *
     * @param string $operation Operation name (e.g. 'getItems', 'searchItems') - also the resourcePath segment.
     * @param array $payload Request payload (already in the API's lowerCamelCase shape)
     * @return array|null Response data or null on failure
     */
    private function request(string $operation, array $payload): ?array {
        $this->last_error = null;

        $token = $this->get_access_token();

        if (!$token) {
            // last_error already set by request_access_token().
            return null;
        }

        // Header format differs by credential version - Cognito (v2.x)
        // needs the version suffix, LWA (v3.x) doesn't. See
        // DefaultApi::buildAuthenticatedHeaders() in the reference SDK.
        $authorization = $this->is_lwa()
            ? "Bearer {$token}"
            : "Bearer {$token}, Version {$this->version}";

        $marketplace = 'www.' . Regions::get_marketplace_domain($this->region_code);
        $url = self::API_HOST . '/catalog/v1/' . $operation;
        $payload_json = wp_json_encode($payload);

        $headers = [
            'Authorization' => $authorization,
            'Content-Type' => 'application/json',
            'x-marketplace' => $marketplace,
        ];

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Amazon Creators API Request: ' . $operation . ' to ' . $url);
            error_log('Amazon Creators API Payload: ' . $payload_json);
        }

        $start_time = microtime(true);

        $response = wp_remote_post($url, [
            'headers' => $headers,
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
            // Whole-request failures (ValidationException, AccessDenied,
            // Throttle, etc.) share a {type, message, reason} shape - see
            // e.g. ValidationExceptionResponseContent/
            // AccessDeniedExceptionResponseContent in the reference SDK.
            // This differs from the {errors: [{code, message}]} shape used
            // for per-item errors inside an otherwise-200 response, which
            // get_items() doesn't need to surface via last_error since
            // individual missing ASINs just come back absent from results.
            $error_message = "HTTP {$status_code} error";

            if (is_array($data) && isset($data['message'])) {
                $error_message = $data['message'];
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Amazon Creators API Error: Status ' . $status_code . ', Response: ' . $body);
            }

            $this->last_error = $error_message;
            return null;
        }

        return $data;
    }

    /**
     * Get the resources to request for item lookups.
     *
     * Mapped from PA-API's equivalent resource list (the now-deleted
     * Amazon_API::get_item_resources()) to GetItemsResource's equivalents (see
     * src/com/amazon/creators/model/GetItemsResource.php in the reference
     * SDK). Two PA-API resources this plugin used have no Creators API
     * equivalent at all and are dropped rather than guessed at:
     * Offers.Listings.SavingBasis's sibling Offers.Listings.Promotions, and
     * Offers.Listings.DeliveryInfo.IsPrimeEligible (see the class docblock).
     * Offers.Summaries.LowestPrice/HighestPrice also has no equivalent -
     * offersV2 only exposes per-listing prices, no aggregate summary.
     *
     * @return array
     */
    private function get_item_resources(): array {
        return [
            // Item info
            'itemInfo.title',
            'itemInfo.byLineInfo',
            'itemInfo.contentInfo',
            'itemInfo.contentRating',
            'itemInfo.classifications',
            'itemInfo.externalIds',
            'itemInfo.features',
            'itemInfo.manufactureInfo',
            'itemInfo.productInfo',
            'itemInfo.technicalInfo',
            // Images
            'images.primary.large',
            'images.variants.large',
            // Offers (pricing)
            'offersV2.listings.price',
            'offersV2.listings.condition',
            'offersV2.listings.availability',
            'offersV2.listings.merchantInfo',
            // Browse node (category)
            'browseNodeInfo.browseNodes',
            'browseNodeInfo.browseNodes.ancestor',
            // Parent ASIN
            'parentASIN',
        ];
    }

    /**
     * Parse a raw catalog item into the plugin's internal shape.
     *
     * Mirrors Amazon_API::parse_item() field-for-field against the
     * lowerCamelCase/offersV2-restructured equivalents (see Item/ItemInfo/
     * Images/OffersV2 etc. in the reference SDK's model/ directory).
     * Produces the same 'asin'/'images'/'facts'/'pricing' shape that
     * Amazon_API::parse_item() produces - that parity is the entire premise
     * Product_Service's implementation-swapping relies on.
     *
     * @param array $item Raw item data
     * @return array
     */
    private function parse_item(array $item): array {
        $info = $item['itemInfo'] ?? [];
        $offers = $item['offersV2'] ?? [];
        $images = $item['images'] ?? [];
        $browse_nodes = $item['browseNodeInfo']['browseNodes'] ?? [];

        // Parse images
        $parsed_images = [];
        if (isset($images['primary']['large'])) {
            $parsed_images[] = [
                'url' => $images['primary']['large']['url'] ?? null,
                'height' => $images['primary']['large']['height'] ?? null,
                'width' => $images['primary']['large']['width'] ?? null,
                'type' => 'main',
            ];
        }
        if (isset($images['variants'])) {
            foreach ($images['variants'] as $variant) {
                if (isset($variant['large'])) {
                    $parsed_images[] = [
                        'url' => $variant['large']['url'] ?? null,
                        'height' => $variant['large']['height'] ?? null,
                        'width' => $variant['large']['width'] ?? null,
                        'type' => 'variant',
                    ];
                }
            }
        }

        // Parse facts
        $facts = [
            'title' => $info['title']['displayValue'] ?? null,
        ];

        // Brand/Manufacturer
        if (isset($info['byLineInfo']['brand']['displayValue'])) {
            $facts['brand'] = $info['byLineInfo']['brand']['displayValue'];
        }
        if (isset($info['byLineInfo']['manufacturer']['displayValue'])) {
            $facts['manufacturer'] = $info['byLineInfo']['manufacturer']['displayValue'];
        }

        // Features
        if (isset($info['features']['displayValues'])) {
            $facts['features'] = $info['features']['displayValues'];
        }

        // Product info
        if (isset($info['productInfo'])) {
            $product_info = $info['productInfo'];
            if (isset($product_info['color']['displayValue'])) {
                $facts['color'] = $product_info['color']['displayValue'];
            }
            if (isset($product_info['size']['displayValue'])) {
                $facts['size'] = $product_info['size']['displayValue'];
            }
            if (isset($product_info['itemDimensions'])) {
                $dims = $product_info['itemDimensions'];
                $parts = [];
                foreach (['height', 'length', 'width'] as $dim) {
                    if (isset($dims[$dim]['displayValue'])) {
                        $parts[] = $dims[$dim]['displayValue'] . ' ' . ($dims[$dim]['unit'] ?? '');
                    }
                }
                if (!empty($parts)) {
                    $facts['dimensions'] = implode(' x ', $parts);
                }
            }
            if (isset($product_info['unitCount']['displayValue'])) {
                $facts['unit_count'] = $product_info['unitCount']['displayValue'];
            }
        }

        // Technical info
        if (isset($info['technicalInfo']['formats']['displayValues'])) {
            $facts['formats'] = $info['technicalInfo']['formats']['displayValues'];
        }

        // Manufacture info
        if (isset($info['manufactureInfo'])) {
            $mfg_info = $info['manufactureInfo'];
            if (isset($mfg_info['model']['displayValue'])) {
                $facts['model_number'] = $mfg_info['model']['displayValue'];
            }
            // Creators API names this itemPartNumber (PA-API: PartNumber).
            if (isset($mfg_info['itemPartNumber']['displayValue'])) {
                $facts['part_number'] = $mfg_info['itemPartNumber']['displayValue'];
            }
        }

        // Classifications
        if (isset($info['classifications'])) {
            $class = $info['classifications'];
            if (isset($class['binding']['displayValue'])) {
                $facts['binding'] = $class['binding']['displayValue'];
            }
            if (isset($class['productGroup']['displayValue'])) {
                $facts['product_group'] = $class['productGroup']['displayValue'];
            }
        }

        // Content info
        if (isset($info['contentInfo'])) {
            $content = $info['contentInfo'];
            if (isset($content['edition']['displayValue'])) {
                $facts['edition'] = $content['edition']['displayValue'];
            }
            if (isset($content['languages']['displayValues'])) {
                $facts['languages'] = array_map(function ($lang) {
                    return $lang['displayValue'] ?? '';
                }, $content['languages']['displayValues']);
            }
            if (isset($content['publicationDate']['displayValue'])) {
                $facts['release_date'] = $content['publicationDate']['displayValue'];
            }
        }

        // External IDs
        if (isset($info['externalIds'])) {
            $external = $info['externalIds'];
            if (isset($external['eans']['displayValues'])) {
                $facts['ean'] = $external['eans']['displayValues'][0] ?? null;
            }
            if (isset($external['upcs']['displayValues'])) {
                $facts['upc'] = $external['upcs']['displayValues'][0] ?? null;
            }
            if (isset($external['isbns']['displayValues'])) {
                $facts['isbn'] = $external['isbns']['displayValues'][0] ?? null;
            }
        }

        // Category from browse nodes
        if (!empty($browse_nodes)) {
            $category_parts = [];
            $browse_node = $browse_nodes[0];

            // Build category path from ancestors
            if (isset($browse_node['ancestor'])) {
                $ancestor = $browse_node['ancestor'];
                while ($ancestor) {
                    if (isset($ancestor['displayName'])) {
                        array_unshift($category_parts, $ancestor['displayName']);
                    }
                    $ancestor = $ancestor['ancestor'] ?? null;
                }
            }

            if (isset($browse_node['displayName'])) {
                $category_parts[] = $browse_node['displayName'];
            }

            if (!empty($category_parts)) {
                $facts['amazon_category'] = implode(' > ', $category_parts);
            }

            if (isset($browse_node['id'])) {
                $facts['amazon_category_id'] = $browse_node['id'];
            }
        }

        // Parent ASIN
        if (isset($item['parentASIN'])) {
            $facts['parent_asin'] = $item['parentASIN'];
        }

        // Parse pricing
        $pricing = $this->parse_pricing($offers);

        return [
            'asin' => $item['asin'],
            'images' => $parsed_images,
            'facts' => array_filter($facts, function ($v) {
                return $v !== null;
            }),
            'pricing' => $pricing,
        ];
    }

    /**
     * Parse pricing information from offersV2.
     *
     * Mirrors Amazon_API::parse_pricing() against OfferListingV2's shape.
     * 'is_prime_price' can never be populated - see the class docblock.
     *
     * @param array $offers offersV2 data from API
     * @return array Pricing data
     */
    private function parse_pricing(array $offers): array {
        $pricing = [
            'current_price' => null,
            'rrp' => null,
            'is_prime_price' => false,
            'availability' => 'unknown',
        ];

        $listings = $offers['listings'] ?? [];

        if (empty($listings)) {
            return $pricing;
        }

        // Get the first (best) listing
        $listing = $listings[0];

        // Current price
        if (isset($listing['price']['money']['amount'])) {
            $pricing['current_price'] = (float) $listing['price']['money']['amount'];
        }

        // RRP (saving basis is the original price)
        if (isset($listing['price']['savingBasis']['money']['amount'])) {
            $pricing['rrp'] = (float) $listing['price']['savingBasis']['money']['amount'];
        }

        // Availability
        if (isset($listing['availability']['type'])) {
            // Creators API uses its own SCREAMING_SNAKE_CASE vocabulary
            // (confirmed live: 'IN_STOCK'), not PA-API's Title Case one
            // ('Now'/'Out of Stock'/'Pre-order') - normalizing underscores/
            // hyphens to spaces before matching means both APIs' styles
            // collapse to the same key set. 'OUT_OF_STOCK'/'PREORDER' are
            // inferred from the same naming convention, not yet confirmed
            // against a live out-of-stock/preorder ASIN - the message-text
            // fallback below is the safety net for exactly that case.
            $availability_type = strtolower(str_replace(['_', '-'], ' ', $listing['availability']['type']));
            $pricing['availability'] = match ($availability_type) {
                'in stock' => 'in_stock',
                'out of stock' => 'out_of_stock',
                'preorder', 'pre order' => 'preorder',
                default => 'unknown',
            };

            // Check availability message for more details
            if (isset($listing['availability']['message'])) {
                $message = strtolower($listing['availability']['message']);
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

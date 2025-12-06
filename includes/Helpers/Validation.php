<?php
/**
 * Validation Helper
 *
 * Handles input validation for API requests.
 *
 * @package AmazonPriceTracker
 */

namespace APT\Helpers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Validation
 */
class Validation {

    /**
     * Validate ASIN format
     *
     * ASIN must be exactly 10 alphanumeric characters.
     *
     * @param string $asin ASIN to validate
     * @return bool
     */
    public static function is_valid_asin(string $asin): bool {
        return (bool) preg_match('/^[A-Z0-9]{10}$/i', $asin);
    }

    /**
     * Normalize ASIN to uppercase
     *
     * @param string $asin ASIN to normalize
     * @return string
     */
    public static function normalize_asin(string $asin): string {
        return strtoupper(trim($asin));
    }

    /**
     * Validate region code
     *
     * @param string $region Region code to validate
     * @return bool
     */
    public static function is_valid_region(string $region): bool {
        return Regions::is_valid($region);
    }

    /**
     * Normalize region code to uppercase
     *
     * @param string $region Region code to normalize
     * @return string
     */
    public static function normalize_region(string $region): string {
        return strtoupper(trim($region));
    }

    /**
     * Validate pagination parameters
     *
     * @param int $page Page number
     * @param int $per_page Items per page
     * @param int $max_per_page Maximum items per page (default: 100)
     * @return array Validated pagination parameters
     */
    public static function validate_pagination(int $page = 1, int $per_page = 20, int $max_per_page = 100): array {
        return [
            'page' => max(1, $page),
            'per_page' => min(max(1, $per_page), $max_per_page),
        ];
    }

    /**
     * Validate sort order
     *
     * @param string $order Sort order
     * @return string Validated sort order ('ASC' or 'DESC')
     */
    public static function validate_sort_order(string $order): string {
        $order = strtoupper(trim($order));
        return in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';
    }

    /**
     * Validate availability status
     *
     * @param string $status Availability status
     * @return string|null Validated status or null if invalid
     */
    public static function validate_availability(string $status): ?string {
        $valid_statuses = ['in_stock', 'out_of_stock', 'limited_stock', 'preorder', 'unknown'];
        $status = strtolower(trim($status));
        return in_array($status, $valid_statuses, true) ? $status : null;
    }

    /**
     * Validate aggregation type
     *
     * @param string $type Aggregation type
     * @return string Validated aggregation type
     */
    public static function validate_aggregation(string $type): string {
        $valid_types = ['none', 'daily', 'weekly', 'monthly'];
        $type = strtolower(trim($type));
        return in_array($type, $valid_types, true) ? $type : 'none';
    }

    /**
     * Validate product sort field
     *
     * @param string $field Sort field
     * @return string Validated sort field
     */
    public static function validate_product_sort_field(string $field): string {
        $valid_fields = ['created_at', 'updated_at', 'current_price', 'title', 'asin'];
        $field = strtolower(trim($field));
        return in_array($field, $valid_fields, true) ? $field : 'created_at';
    }

    /**
     * Validate and sanitize search query
     *
     * @param string $query Search query
     * @param int $min_length Minimum length required
     * @return string|null Sanitized query or null if too short
     */
    public static function validate_search_query(string $query, int $min_length = 2): ?string {
        $query = sanitize_text_field(trim($query));
        return strlen($query) >= $min_length ? $query : null;
    }

    /**
     * Validate datetime string
     *
     * @param string $datetime Datetime string
     * @return string|null Validated datetime in MySQL format or null if invalid
     */
    public static function validate_datetime(string $datetime): ?string {
        $timestamp = strtotime($datetime);

        if ($timestamp === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Validate price value
     *
     * @param mixed $price Price value
     * @return float|null Validated price or null if invalid
     */
    public static function validate_price($price): ?float {
        if (!is_numeric($price)) {
            return null;
        }

        $price = (float) $price;
        return $price >= 0 ? round($price, 2) : null;
    }

    /**
     * Validate boolean value
     *
     * @param mixed $value Value to validate
     * @return bool
     */
    public static function validate_bool($value): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['true', '1', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * Validate and parse comma-separated region codes
     *
     * @param string $regions Comma-separated region codes
     * @return array Valid region codes
     */
    public static function validate_region_list(string $regions): array {
        $region_codes = array_map('trim', explode(',', $regions));
        $valid_regions = [];

        foreach ($region_codes as $code) {
            $code = self::normalize_region($code);
            if (self::is_valid_region($code) && !in_array($code, $valid_regions, true)) {
                $valid_regions[] = $code;
            }
        }

        return $valid_regions;
    }

    /**
     * Validate custom category
     *
     * @param string|null $category Category name
     * @return string|null Sanitized category or null
     */
    public static function validate_category(?string $category): ?string {
        if ($category === null || $category === '') {
            return null;
        }

        $category = sanitize_text_field(trim($category));
        return strlen($category) <= 255 ? $category : substr($category, 0, 255);
    }

    /**
     * Build validation error response
     *
     * @param array $errors Array of field errors
     * @return array
     */
    public static function build_validation_error(array $errors): array {
        return [
            'code' => 'VALIDATION_ERROR',
            'message' => 'Request validation failed',
            'errors' => $errors,
        ];
    }

    /**
     * Add a field error to errors array
     *
     * @param array $errors Errors array (passed by reference)
     * @param string $field Field name
     * @param string $message Error message
     */
    public static function add_field_error(array &$errors, string $field, string $message): void {
        $errors[] = [
            'field' => $field,
            'message' => $message,
        ];
    }
}

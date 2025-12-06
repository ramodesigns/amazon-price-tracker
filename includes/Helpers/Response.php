<?php
/**
 * Response Helper
 *
 * Handles standardized API response formatting.
 *
 * @package AmazonPriceTracker
 */

namespace APT\Helpers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use WP_REST_Response;
use WP_Error;

/**
 * Class Response
 */
class Response {

    /**
     * Create a success response with data
     *
     * @param mixed $data Response data
     * @param int $status HTTP status code
     * @return WP_REST_Response
     */
    public static function success($data, int $status = 200): WP_REST_Response {
        return new WP_REST_Response($data, $status);
    }

    /**
     * Create a paginated list response
     *
     * @param array $items List items
     * @param array $pagination Pagination info
     * @return WP_REST_Response
     */
    public static function paginated(array $items, array $pagination): WP_REST_Response {
        return new WP_REST_Response([
            'data' => $items,
            'meta' => [
                'pagination' => $pagination,
            ],
        ], 200);
    }

    /**
     * Build pagination metadata
     *
     * @param int $current_page Current page number
     * @param int $per_page Items per page
     * @param int $total_items Total number of items
     * @return array
     */
    public static function build_pagination(int $current_page, int $per_page, int $total_items): array {
        $total_pages = $per_page > 0 ? (int) ceil($total_items / $per_page) : 0;

        return [
            'current_page' => $current_page,
            'per_page' => $per_page,
            'total_items' => $total_items,
            'total_pages' => $total_pages,
            'has_next' => $current_page < $total_pages,
            'has_previous' => $current_page > 1,
        ];
    }

    /**
     * Create an error response
     *
     * @param string $code Error code
     * @param string $message Error message
     * @param int $status HTTP status code
     * @param array $details Additional error details
     * @return WP_Error
     */
    public static function error(string $code, string $message, int $status = 400, array $details = []): WP_Error {
        $data = [
            'status' => $status,
        ];

        if (!empty($details)) {
            $data['details'] = $details;
        }

        return new WP_Error($code, $message, $data);
    }

    /**
     * Create a validation error response
     *
     * @param array $errors Array of field errors
     * @return WP_Error
     */
    public static function validation_error(array $errors): WP_Error {
        return new WP_Error(
            'VALIDATION_ERROR',
            'Request validation failed',
            [
                'status' => 400,
                'errors' => $errors,
            ]
        );
    }

    /**
     * Create a not found error response
     *
     * @param string $message Error message
     * @return WP_Error
     */
    public static function not_found(string $message = 'Resource not found'): WP_Error {
        return self::error('NOT_FOUND', $message, 404);
    }

    /**
     * Create a forbidden error response
     *
     * @param string $message Error message
     * @param array $details Additional details
     * @return WP_Error
     */
    public static function forbidden(string $message = 'Access denied', array $details = []): WP_Error {
        return self::error('FORBIDDEN', $message, 403, $details);
    }

    /**
     * Create a conflict error response
     *
     * @param string $message Error message
     * @param array $details Additional details
     * @return WP_Error
     */
    public static function conflict(string $message, array $details = []): WP_Error {
        return self::error('ALREADY_EXISTS', $message, 409, $details);
    }

    /**
     * Create a rate limit exceeded error response
     *
     * @param int $limit Daily limit
     * @param int $used Number used today
     * @param string $resets_at Reset time (ISO 8601)
     * @return WP_Error
     */
    public static function rate_limit_exceeded(int $limit, int $used, string $resets_at): WP_Error {
        return new WP_Error(
            'RATE_LIMIT_EXCEEDED',
            'Daily creation limit exceeded',
            [
                'status' => 429,
                'limit' => $limit,
                'used' => $used,
                'resets_at' => $resets_at,
            ]
        );
    }

    /**
     * Create a blacklisted error response
     *
     * @param string|null $reason Blacklist reason
     * @return WP_Error
     */
    public static function blacklisted(?string $reason = null): WP_Error {
        $details = [];
        if ($reason) {
            $details['reason'] = $reason;
        }

        return self::error('BLACKLISTED', 'This ASIN/Region combination is blacklisted', 403, $details);
    }

    /**
     * Create an Amazon API error response
     *
     * @param string $message Error message
     * @return WP_Error
     */
    public static function amazon_api_error(string $message = 'Failed to connect to Amazon PA-API'): WP_Error {
        return self::error('AMAZON_API_ERROR', $message, 502);
    }

    /**
     * Create an ASIN not found error response
     *
     * @return WP_Error
     */
    public static function asin_not_found(): WP_Error {
        return self::error('ASIN_NOT_FOUND', 'Product not found on Amazon', 400);
    }

    /**
     * Create a missing partner tag error response
     *
     * @param string $region Region code
     * @return WP_Error
     */
    public static function missing_partner_tag(string $region): WP_Error {
        return self::error(
            'MISSING_PARTNER_TAG',
            "No partner tag configured for region {$region}",
            400
        );
    }

    /**
     * Create a not configured error response
     *
     * @param string $message Error message
     * @return WP_Error
     */
    public static function not_configured(string $message = 'Settings not configured'): WP_Error {
        return self::error('NOT_CONFIGURED', $message, 400);
    }

    /**
     * Create a bulk operation response
     *
     * @param int $success_count Number of successful operations
     * @param int $failure_count Number of failed operations
     * @param array $results Individual results
     * @return WP_REST_Response
     */
    public static function bulk_result(int $success_count, int $failure_count, array $results): WP_REST_Response {
        return new WP_REST_Response([
            'success_count' => $success_count,
            'failure_count' => $failure_count,
            'results' => $results,
        ], 200);
    }

    /**
     * Create a no content response (204)
     *
     * @return WP_REST_Response
     */
    public static function no_content(): WP_REST_Response {
        return new WP_REST_Response(null, 204);
    }

    /**
     * Create a created response (201)
     *
     * @param mixed $data Created resource data
     * @return WP_REST_Response
     */
    public static function created($data): WP_REST_Response {
        return new WP_REST_Response($data, 201);
    }

    /**
     * Create a created response with rate limit headers (201)
     *
     * @param mixed $data Created resource data
     * @param int $limit Daily limit
     * @param int $remaining Remaining today
     * @param string $reset Reset time (ISO 8601)
     * @return WP_REST_Response
     */
    public static function created_with_rate_limit($data, int $limit, int $remaining, string $reset): WP_REST_Response {
        $response = new WP_REST_Response($data, 201);
        self::add_rate_limit_headers($response, $limit, $remaining, $reset);
        return $response;
    }

    /**
     * Add rate limit headers to a response
     *
     * @param WP_REST_Response $response Response object
     * @param int $limit Daily limit
     * @param int $remaining Remaining today
     * @param string $reset Reset time (ISO 8601)
     */
    public static function add_rate_limit_headers(WP_REST_Response $response, int $limit, int $remaining, string $reset): void {
        $response->header('X-RateLimit-Limit', (string) $limit);
        $response->header('X-RateLimit-Remaining', (string) max(0, $remaining));
        $response->header('X-RateLimit-Reset', $reset);
    }
}

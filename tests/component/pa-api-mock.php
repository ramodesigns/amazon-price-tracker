<?php
/**
 * pre_http_request mocking helper for component tests.
 *
 * Intercepts wp_remote_post() via the pre_http_request filter, which
 * WP_Http::request() checks before making a real socket call. Only requests
 * to a PA-API path (/paapi5/...) are intercepted, and only when a canned
 * response has been queued - everything else passes through untouched.
 * Amazon_API::request() still builds and signs the real request and parses
 * whatever comes back, so component tests still catch bugs in URL building,
 * header construction, and response parsing - just not "does Amazon's
 * server accept our signature."
 *
 * @package AmazonPriceTracker\Tests\Component
 */

$GLOBALS['apt_test_pa_api_responses'] = [];

/**
 * Queue a canned PA-API HTTP response for the next intercepted request.
 *
 * @param int $status_code HTTP status code to return.
 * @param array $body Decoded response body, JSON-encoded before returning.
 */
function apt_test_queue_pa_api_response(int $status_code, array $body): void {
    $GLOBALS['apt_test_pa_api_responses'][] = [
        'status_code' => $status_code,
        'body' => $body,
    ];
}

/**
 * Queue a WP_Error to simulate a network-level failure (e.g. timeout).
 *
 * @param string $code Error code.
 * @param string $message Error message.
 */
function apt_test_queue_pa_api_error(string $code, string $message): void {
    $GLOBALS['apt_test_pa_api_responses'][] = [
        'error' => new WP_Error($code, $message),
    ];
}

/**
 * Clear queued responses. Call from tearDown() so nothing leaks between tests.
 */
function apt_test_reset_pa_api_responses(): void {
    $GLOBALS['apt_test_pa_api_responses'] = [];
}

add_filter('pre_http_request', function ($preempt, $parsed_args, $url) {
    if (!str_contains($url, '/paapi5/') || empty($GLOBALS['apt_test_pa_api_responses'])) {
        return $preempt;
    }

    $next = array_shift($GLOBALS['apt_test_pa_api_responses']);

    if (isset($next['error'])) {
        return $next['error'];
    }

    return [
        'headers' => [],
        'body' => wp_json_encode($next['body']),
        'response' => [
            'code' => $next['status_code'],
            'message' => '',
        ],
        'cookies' => [],
        'filename' => null,
    ];
}, 10, 3);

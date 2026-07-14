<?php
/**
 * pre_http_request mocking helper for component tests, for the Creators API.
 *
 * Intercepts wp_remote_post() via the pre_http_request filter, which
 * WP_Http::request() already checks before making a real socket call. Two
 * request shapes get intercepted:
 *
 * 1. The OAuth2 token endpoint (any of the six regional-cluster URLs
 *    Amazon_Creators_API::get_token_endpoint() can return) - auto-succeeds
 *    with a fake token whenever a catalog response is queued (see below),
 *    so tests don't need to queue the token step separately.
 * 2. The catalog API (creatorsapi.amazon/catalog/v1/...) - only intercepted
 *    once a canned response has been queued (queue-and-consume), so
 *    Amazon_Creators_API::request() still builds the real request and
 *    parses whatever comes back - this still catches bugs in URL building,
 *    header construction, and response parsing, just not "does Amazon's
 *    server accept our token."
 *
 * Both intercepts are gated on the same queue being non-empty, not just the
 * catalog one - this file's require_once registers the pre_http_request
 * filter for the rest of the PHPUnit process, including any real-network
 * integration tests that run afterward in the same run. An unconditional
 * token intercept would silently hijack THEIR token requests too (a real
 * getItems call authenticated with this file's fake token gets a genuine
 * "invalid or malformed token" rejection from Amazon) - gating on the queue
 * means this mock is a no-op passthrough whenever nothing has been queued.
 *
 * @package AmazonPriceTracker\Tests\Component
 */

$GLOBALS['apt_test_creators_api_responses'] = [];

/**
 * Temporarily unset the APT_TEST_CREATORS_API_* env vars for the duration
 * of a test. Product_Service::get_user_settings() falls back to these (via
 * a gitignored .env file - see Env_File) when a user has no settings row,
 * so a developer with real credentials in their local .env would otherwise
 * make "not configured" assertions flaky - and worse, since this mock only
 * intercepts requests once a response is queued, an un-queued test hitting
 * the fallback would fall through to a real, unmocked network call. Call
 * apt_test_restore_credential_env_fallback() with the return value (ideally
 * in a finally block, so it still runs if the test itself fails) to put
 * things back.
 *
 * @return array<string, string|false> Previous values, keyed by var name.
 */
function apt_test_suppress_credential_env_fallback(): array {
    $keys = [
        'APT_TEST_CREATORS_API_CREDENTIAL_ID', 'APT_TEST_CREATORS_API_CREDENTIAL_SECRET',
        'APT_TEST_CREATORS_API_VERSION', 'APT_TEST_CREATORS_API_PARTNER_TAG', 'APT_TEST_CREATORS_API_REGION',
    ];
    $previous = [];

    foreach ($keys as $key) {
        $previous[$key] = getenv($key);
        putenv($key);
    }

    return $previous;
}

/**
 * Restore env vars saved by apt_test_suppress_credential_env_fallback().
 *
 * @param array<string, string|false> $previous
 */
function apt_test_restore_credential_env_fallback(array $previous): void {
    foreach ($previous as $key => $value) {
        putenv($value === false ? $key : "{$key}={$value}");
    }
}

/**
 * Queue a canned Creators API catalog response for the next intercepted
 * getItems/searchItems request.
 *
 * @param int $status_code HTTP status code to return.
 * @param array $body Decoded response body, JSON-encoded before returning.
 */
function apt_test_queue_creators_api_response(int $status_code, array $body): void {
    $GLOBALS['apt_test_creators_api_responses'][] = [
        'status_code' => $status_code,
        'body' => $body,
    ];
}

/**
 * Queue a WP_Error to simulate a network-level failure (e.g. timeout) on
 * the next catalog request. The token request itself always succeeds -
 * see the class docblock above.
 *
 * @param string $code Error code.
 * @param string $message Error message.
 */
function apt_test_queue_creators_api_error(string $code, string $message): void {
    $GLOBALS['apt_test_creators_api_responses'][] = [
        'error' => new WP_Error($code, $message),
    ];
}

/**
 * Clear queued responses. Call from tearDown() so nothing leaks between tests.
 */
function apt_test_reset_creators_api_responses(): void {
    $GLOBALS['apt_test_creators_api_responses'] = [];
}

add_filter('pre_http_request', function ($preempt, $parsed_args, $url) {
    // Nothing queued - pass every request through untouched, including to
    // the token endpoint. This is what keeps this mock from hijacking real
    // integration tests' token requests when they run later in the same
    // PHPUnit process (see the file docblock above).
    if (empty($GLOBALS['apt_test_creators_api_responses'])) {
        return $preempt;
    }

    // OAuth2 token endpoint - all six regional-cluster URLs end in one of
    // these two suffixes (see Amazon_Creators_API::get_token_endpoint()).
    // Auto-succeeds so callers reach the catalog request without needing
    // real credentials or a queued response of their own for this step.
    if (str_contains($url, '/auth/o2/token') || str_contains($url, '.amazoncognito.com/oauth2/token')) {
        return [
            'headers' => [],
            'body' => wp_json_encode(['access_token' => 'test-fake-token', 'expires_in' => 3600]),
            'response' => [
                'code' => 200,
                'message' => '',
            ],
            'cookies' => [],
            'filename' => null,
        ];
    }

    if (!str_contains($url, 'creatorsapi.amazon/catalog/')) {
        return $preempt;
    }

    $next = array_shift($GLOBALS['apt_test_creators_api_responses']);

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

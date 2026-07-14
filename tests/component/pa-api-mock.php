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

/**
 * Temporarily unset the APT_TEST_PA_API_* and APT_TEST_CREATORS_API_* env
 * vars for the duration of a test. Product_Service::get_user_settings()
 * falls back to these (via a gitignored .env file - see Env_File) when a
 * user has no settings row, so a developer with real credentials in their
 * local .env would otherwise make "not configured" assertions flaky - and
 * worse, since this mock only intercepts requests once a response is
 * queued, an un-queued test hitting the fallback would fall through to a
 * real, unmocked network call. Both credential sets need suppressing
 * together: either one alone being present is enough for
 * get_user_settings() to return a non-null (if partial) settings object,
 * which is enough to break a test asserting the fully-unconfigured path.
 * Call apt_test_restore_pa_api_env_fallback() with the return value
 * (ideally in a finally block, so it still runs if the test itself fails)
 * to put things back.
 *
 * @return array<string, string|false> Previous values, keyed by var name.
 */
function apt_test_suppress_pa_api_env_fallback(): array {
    $keys = [
        'APT_TEST_PA_API_ACCESS_KEY', 'APT_TEST_PA_API_SECRET_KEY', 'APT_TEST_PA_API_PARTNER_TAG', 'APT_TEST_PA_API_REGION',
        'APT_TEST_CREATORS_API_CREDENTIAL_ID', 'APT_TEST_CREATORS_API_CREDENTIAL_SECRET', 'APT_TEST_CREATORS_API_VERSION', 'APT_TEST_CREATORS_API_PARTNER_TAG', 'APT_TEST_CREATORS_API_REGION',
    ];
    $previous = [];

    foreach ($keys as $key) {
        $previous[$key] = getenv($key);
        putenv($key);
    }

    return $previous;
}

/**
 * Restore env vars saved by apt_test_suppress_pa_api_env_fallback().
 *
 * @param array<string, string|false> $previous
 */
function apt_test_restore_pa_api_env_fallback(array $previous): void {
    foreach ($previous as $key => $value) {
        putenv($value === false ? $key : "{$key}={$value}");
    }
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

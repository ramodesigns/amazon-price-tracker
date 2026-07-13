<?php
/**
 * Response Helper Tests
 *
 * @package AmazonPriceTracker\Tests\Unit
 */

use APT\Helpers\Response;

/**
 * Test case for the Response helper class.
 */
class Test_Response extends WP_UnitTestCase {

    /**
     * Test success response defaults to HTTP 200.
     */
    public function test_success_defaults_to_200() {
        $response = Response::success(['foo' => 'bar']);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());
        $this->assertSame(['foo' => 'bar'], $response->get_data());
    }

    /**
     * Test success response honors a custom status code.
     */
    public function test_success_with_custom_status() {
        $response = Response::success(['foo' => 'bar'], 202);

        $this->assertSame(202, $response->get_status());
    }

    /**
     * Test paginated response structure.
     */
    public function test_paginated() {
        $items = [['id' => 1], ['id' => 2]];
        $pagination = Response::build_pagination(1, 20, 2);

        $response = Response::paginated($items, $pagination);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame($items, $data['data']);
        $this->assertSame($pagination, $data['meta']['pagination']);
    }

    /**
     * Test pagination calculation on a middle page.
     */
    public function test_build_pagination_middle_page() {
        $pagination = Response::build_pagination(2, 10, 25);

        $this->assertSame(2, $pagination['current_page']);
        $this->assertSame(10, $pagination['per_page']);
        $this->assertSame(25, $pagination['total_items']);
        $this->assertSame(3, $pagination['total_pages']);
        $this->assertTrue($pagination['has_next']);
        $this->assertTrue($pagination['has_previous']);
    }

    /**
     * Test pagination calculation on the first page.
     */
    public function test_build_pagination_first_page() {
        $pagination = Response::build_pagination(1, 10, 25);

        $this->assertFalse($pagination['has_previous']);
        $this->assertTrue($pagination['has_next']);
    }

    /**
     * Test pagination calculation on the last page.
     */
    public function test_build_pagination_last_page() {
        $pagination = Response::build_pagination(3, 10, 25);

        $this->assertFalse($pagination['has_next']);
        $this->assertTrue($pagination['has_previous']);
    }

    /**
     * Test pagination with zero total items.
     */
    public function test_build_pagination_with_zero_items() {
        $pagination = Response::build_pagination(1, 10, 0);

        $this->assertSame(0, $pagination['total_pages']);
        $this->assertFalse($pagination['has_next']);
        $this->assertFalse($pagination['has_previous']);
    }

    /**
     * Test pagination with a zero per_page value avoids division by zero.
     */
    public function test_build_pagination_with_zero_per_page() {
        $pagination = Response::build_pagination(1, 0, 10);

        $this->assertSame(0, $pagination['total_pages']);
    }

    /**
     * Test basic error response shape.
     */
    public function test_error() {
        $error = Response::error('SOME_ERROR', 'Something went wrong', 418);

        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('SOME_ERROR', $error->get_error_code());
        $this->assertSame('Something went wrong', $error->get_error_message());
        $this->assertSame(418, $error->get_error_data()['status']);
        $this->assertArrayNotHasKey('details', $error->get_error_data());
    }

    /**
     * Test error response includes details when provided.
     */
    public function test_error_with_details() {
        $error = Response::error('SOME_ERROR', 'Something went wrong', 400, ['field' => 'asin']);

        $this->assertSame(['field' => 'asin'], $error->get_error_data()['details']);
    }

    /**
     * Test validation error response shape.
     */
    public function test_validation_error() {
        $errors = [['field' => 'asin', 'message' => 'Required']];
        $error = Response::validation_error($errors);

        $this->assertSame('VALIDATION_ERROR', $error->get_error_code());
        $this->assertSame(400, $error->get_error_data()['status']);
        $this->assertSame($errors, $error->get_error_data()['errors']);
    }

    /**
     * Test not_found default and custom messages.
     */
    public function test_not_found() {
        $error = Response::not_found();

        $this->assertSame('NOT_FOUND', $error->get_error_code());
        $this->assertSame('Resource not found', $error->get_error_message());
        $this->assertSame(404, $error->get_error_data()['status']);

        $custom = Response::not_found('Product not found');
        $this->assertSame('Product not found', $custom->get_error_message());
    }

    /**
     * Test forbidden default message and status.
     */
    public function test_forbidden() {
        $error = Response::forbidden();

        $this->assertSame('FORBIDDEN', $error->get_error_code());
        $this->assertSame('Access denied', $error->get_error_message());
        $this->assertSame(403, $error->get_error_data()['status']);
    }

    /**
     * Test conflict response shape.
     */
    public function test_conflict() {
        $error = Response::conflict('Already tracked', ['asin' => 'B08N5WRWNW']);

        $this->assertSame('ALREADY_EXISTS', $error->get_error_code());
        $this->assertSame(409, $error->get_error_data()['status']);
        $this->assertSame(['asin' => 'B08N5WRWNW'], $error->get_error_data()['details']);
    }

    /**
     * Test rate limit exceeded response shape.
     */
    public function test_rate_limit_exceeded() {
        $error = Response::rate_limit_exceeded(50, 50, '2026-07-12T00:00:00Z');

        $this->assertSame('RATE_LIMIT_EXCEEDED', $error->get_error_code());
        $data = $error->get_error_data();
        $this->assertSame(429, $data['status']);
        $this->assertSame(50, $data['limit']);
        $this->assertSame(50, $data['used']);
        $this->assertSame('2026-07-12T00:00:00Z', $data['resets_at']);
    }

    /**
     * Test blacklisted response without a reason.
     */
    public function test_blacklisted_without_reason() {
        $error = Response::blacklisted();

        $this->assertSame('BLACKLISTED', $error->get_error_code());
        $this->assertArrayNotHasKey('details', $error->get_error_data());
    }

    /**
     * Test blacklisted response with a reason.
     */
    public function test_blacklisted_with_reason() {
        $error = Response::blacklisted('Restricted category');

        $this->assertSame('Restricted category', $error->get_error_data()['details']['reason']);
    }

    /**
     * Test amazon_api_error default message.
     */
    public function test_amazon_api_error() {
        $error = Response::amazon_api_error();

        $this->assertSame('AMAZON_API_ERROR', $error->get_error_code());
        $this->assertSame(502, $error->get_error_data()['status']);
    }

    /**
     * Test asin_not_found response shape.
     */
    public function test_asin_not_found() {
        $error = Response::asin_not_found();

        $this->assertSame('ASIN_NOT_FOUND', $error->get_error_code());
        $this->assertSame(400, $error->get_error_data()['status']);
    }

    /**
     * Test missing_partner_tag includes the region in the message.
     */
    public function test_missing_partner_tag() {
        $error = Response::missing_partner_tag('DE');

        $this->assertSame('MISSING_PARTNER_TAG', $error->get_error_code());
        $this->assertStringContainsString('DE', $error->get_error_message());
    }

    /**
     * Test not_configured default message.
     */
    public function test_not_configured() {
        $error = Response::not_configured();

        $this->assertSame('NOT_CONFIGURED', $error->get_error_code());
        $this->assertSame('Settings not configured', $error->get_error_message());
    }

    /**
     * Test bulk_result response shape.
     */
    public function test_bulk_result() {
        $results = [['asin' => 'B08N5WRWNW', 'status' => 'created']];
        $response = Response::bulk_result(1, 0, $results);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(1, $data['success_count']);
        $this->assertSame(0, $data['failure_count']);
        $this->assertSame($results, $data['results']);
    }

    /**
     * Test no_content response shape.
     */
    public function test_no_content() {
        $response = Response::no_content();

        $this->assertSame(204, $response->get_status());
        $this->assertNull($response->get_data());
    }

    /**
     * Test created response shape.
     */
    public function test_created() {
        $response = Response::created(['id' => 1]);

        $this->assertSame(201, $response->get_status());
        $this->assertSame(['id' => 1], $response->get_data());
    }

    /**
     * Test created_with_rate_limit sets both the payload and headers.
     */
    public function test_created_with_rate_limit() {
        $response = Response::created_with_rate_limit(['id' => 1], 50, 49, '2026-07-12T00:00:00Z');
        $headers = $response->get_headers();

        $this->assertSame(201, $response->get_status());
        $this->assertSame(['id' => 1], $response->get_data());
        $this->assertSame('50', $headers['X-RateLimit-Limit']);
        $this->assertSame('49', $headers['X-RateLimit-Remaining']);
        $this->assertSame('2026-07-12T00:00:00Z', $headers['X-RateLimit-Reset']);
    }

    /**
     * Test add_rate_limit_headers clamps remaining to zero.
     */
    public function test_add_rate_limit_headers_clamps_negative_remaining() {
        $response = new WP_REST_Response([], 200);
        Response::add_rate_limit_headers($response, 50, -5, '2026-07-12T00:00:00Z');

        $this->assertSame('0', $response->get_headers()['X-RateLimit-Remaining']);
    }
}

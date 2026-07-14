<?php
/**
 * Regions Controller Component Test
 *
 * Exercises GET /regions end-to-end through the real WP REST dispatcher -
 * real routing and permission callback, no Amazon dependency.
 *
 * @package AmazonPriceTracker\Tests\Component
 */

use APT\Helpers\Regions;

/**
 * Test case for the Regions REST controller.
 */
class Test_Regions_Controller_Component extends WP_UnitTestCase {

    /**
     * @var WP_REST_Server
     */
    protected $server;

    public function setUp(): void {
        parent::setUp();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        $this->server = $wp_rest_server;
        do_action('rest_api_init', $this->server);
    }

    private function dispatch_get_regions(): WP_REST_Response {
        $request = new WP_REST_Request('GET', '/' . APT_API_NAMESPACE . '/regions');
        return $this->server->dispatch($request);
    }

    public function test_get_regions_requires_authentication() {
        $response = $this->dispatch_get_regions();

        $this->assertSame(401, $response->get_status());
        $this->assertSame('rest_forbidden', $response->as_error()->get_error_code());
    }

    public function test_get_regions_returns_all_supported_regions_when_authenticated() {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $response = $this->dispatch_get_regions();
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(Regions::get_for_api(), $data);
        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('code', $data[0]);
        $this->assertArrayHasKey('name', $data[0]);
        $this->assertArrayHasKey('marketplace_domain', $data[0]);
        $this->assertArrayHasKey('currency', $data[0]);
    }
}

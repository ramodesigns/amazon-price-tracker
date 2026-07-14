<?php
/**
 * Settings REST Controller
 *
 * Handles the /settings endpoints for the current user's Amazon Creators API
 * credentials (creators_credential_id/secret/version) and partner tags.
 *
 * @package AmazonPriceTracker
 */

namespace APT\API\Controllers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use APT\Helpers\Response;
use APT\Helpers\Validation;
use APT\Helpers\Encryption;
use APT\Helpers\Regions;
use APT\Services\Product_Service;

/**
 * Class Settings_Controller
 */
class Settings_Controller extends Base_Controller {

    /**
     * Route base
     *
     * @var string
     */
    protected $rest_base = 'settings';

    /**
     * Register routes
     */
    public function register_routes(): void {
        // GET /settings - Get current user's settings
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_settings'],
                'permission_callback' => [$this, 'check_authenticated'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [$this, 'update_settings'],
                'permission_callback' => [$this, 'check_authenticated'],
                'args' => $this->get_update_args(),
            ],
        ]);

        // DELETE /settings/partner-tags/{region} - Remove partner tag for region
        register_rest_route($this->namespace, '/' . $this->rest_base . '/partner-tags/(?P<region>[A-Z]{2})', [
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'delete_partner_tag'],
                'permission_callback' => [$this, 'check_authenticated'],
                'args' => [
                    'region' => [
                        'required' => true,
                        'validate_callback' => function($value) {
                            return Validation::is_valid_region($value);
                        },
                    ],
                ],
            ],
        ]);

        // POST /settings/validate - Validate Amazon Creators API credentials
        register_rest_route($this->namespace, '/' . $this->rest_base . '/validate', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'validate_credentials'],
                'permission_callback' => [$this, 'check_authenticated'],
            ],
        ]);
    }

    /**
     * Get update endpoint arguments
     *
     * @return array
     */
    private function get_update_args(): array {
        return [
            'creators_credential_id' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'creators_credential_secret' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'creators_credential_version' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'partner_tags' => [
                'type' => 'object',
            ],
        ];
    }

    /**
     * Get current user's settings
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_settings(WP_REST_Request $request) {
        $user_id = $this->get_current_user_id();
        $settings = $this->get_user_settings($user_id);

        if (!$settings) {
            return Response::not_found(__('User settings not configured', 'amazon-price-tracker'));
        }

        return Response::success($this->format_settings($settings));
    }

    /**
     * Update current user's settings
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function update_settings(WP_REST_Request $request) {
        $user_id = $this->get_current_user_id();
        $existing = $this->get_user_settings($user_id);

        $creators_credential_id = $request->get_param('creators_credential_id');
        $creators_credential_secret = $request->get_param('creators_credential_secret');
        $creators_credential_version = $request->get_param('creators_credential_version');
        $partner_tags = $request->get_param('partner_tags');

        // Validate partner tags if provided
        if ($partner_tags !== null) {
            $validation_result = $this->validate_partner_tags($partner_tags);
            if (is_wp_error($validation_result)) {
                return $validation_result;
            }
        }

        if ($creators_credential_version !== null) {
            $validation_result = $this->validate_creators_version($creators_credential_version);
            if (is_wp_error($validation_result)) {
                return $validation_result;
            }
        }

        $db = $this->get_db();
        $table = $this->get_table('user_settings');
        $now = current_time('mysql', true);

        if ($existing) {
            // Update existing settings
            $update_data = [
                'updated_at' => $now,
            ];

            if ($creators_credential_id !== null) {
                $update_data['creators_credential_id'] = Encryption::encrypt($creators_credential_id);
            }

            if ($creators_credential_secret !== null) {
                $update_data['creators_credential_secret'] = Encryption::encrypt($creators_credential_secret);
            }

            if ($creators_credential_version !== null) {
                $update_data['creators_credential_version'] = ltrim($creators_credential_version, 'vV');
            }

            if ($partner_tags !== null) {
                // Merge with existing partner tags
                $existing_tags = json_decode($existing->partner_tags, true) ?: [];
                $merged_tags = array_merge($existing_tags, $partner_tags);
                $update_data['partner_tags'] = wp_json_encode($merged_tags);
            }

            $db->update($table, $update_data, ['user_id' => $user_id]);

            $settings = $this->get_user_settings($user_id);
            return Response::success($this->format_settings($settings));
        } else {
            // Create new settings
            $errors = [];

            if (empty($creators_credential_id)) {
                Validation::add_field_error($errors, 'creators_credential_id', 'Creators API credential ID is required for initial setup');
            }

            if (empty($creators_credential_secret)) {
                Validation::add_field_error($errors, 'creators_credential_secret', 'Creators API credential secret is required for initial setup');
            }

            if (empty($creators_credential_version)) {
                Validation::add_field_error($errors, 'creators_credential_version', 'Creators API credential version is required for initial setup');
            }

            if (!empty($errors)) {
                return Response::validation_error($errors);
            }

            $insert_data = [
                'user_id' => $user_id,
                'creators_credential_id' => Encryption::encrypt($creators_credential_id),
                'creators_credential_secret' => Encryption::encrypt($creators_credential_secret),
                'creators_credential_version' => ltrim($creators_credential_version, 'vV'),
                'partner_tags' => wp_json_encode($partner_tags ?: []),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $db->insert($table, $insert_data);

            $settings = $this->get_user_settings($user_id);
            return Response::created($this->format_settings($settings));
        }
    }

    /**
     * Delete partner tag for a specific region
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function delete_partner_tag(WP_REST_Request $request) {
        $user_id = $this->get_current_user_id();
        $region = Validation::normalize_region($request->get_param('region'));

        $settings = $this->get_user_settings($user_id);

        if (!$settings) {
            return Response::not_found(__('User settings not configured', 'amazon-price-tracker'));
        }

        $partner_tags = json_decode($settings->partner_tags, true) ?: [];

        if (!isset($partner_tags[$region])) {
            return Response::not_found(
                sprintf(__('No partner tag configured for region %s', 'amazon-price-tracker'), $region)
            );
        }

        unset($partner_tags[$region]);

        $db = $this->get_db();
        $table = $this->get_table('user_settings');

        $db->update(
            $table,
            [
                'partner_tags' => wp_json_encode($partner_tags),
                'updated_at' => current_time('mysql', true),
            ],
            ['user_id' => $user_id]
        );

        return Response::no_content();
    }

    /**
     * Validate Amazon Creators API credentials
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function validate_credentials(WP_REST_Request $request) {
        $user_id = $this->get_current_user_id();

        $service = new Product_Service();
        $result = $service->test_connection($user_id);

        if ($result['status'] === 'connected') {
            return Response::success([
                'valid' => true,
                'message' => $result['message'],
            ]);
        }

        if ($result['status'] === 'not_configured') {
            return Response::not_configured($result['message']);
        }

        return Response::success([
            'valid' => false,
            'message' => $result['message'],
        ]);
    }

    /**
     * Get user settings from database
     *
     * @param int $user_id User ID
     * @return object|null
     */
    private function get_user_settings(int $user_id): ?object {
        $db = $this->get_db();
        $table = $this->get_table('user_settings');

        return $db->get_row($db->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Format settings for API response
     *
     * @param object $settings Settings record
     * @return array
     */
    private function format_settings(object $settings): array {
        $creators_credential_id = !empty($settings->creators_credential_id) ? Encryption::decrypt($settings->creators_credential_id) : '';

        return [
            'id' => (int) $settings->id,
            'user_id' => (int) $settings->user_id,
            'creators_credential_id' => $creators_credential_id ? Encryption::mask($creators_credential_id) : '',
            'creators_credential_version' => $settings->creators_credential_version ?? '',
            'partner_tags' => json_decode($settings->partner_tags, true) ?: [],
            'created_at' => $this->format_datetime($settings->created_at),
            'updated_at' => $this->format_datetime($settings->updated_at),
        ];
    }

    /**
     * Validate partner tags structure
     *
     * @param mixed $partner_tags Partner tags to validate
     * @return true|WP_Error
     */
    private function validate_partner_tags($partner_tags) {
        if (!is_array($partner_tags)) {
            return Response::validation_error([
                ['field' => 'partner_tags', 'message' => 'Partner tags must be an object'],
            ]);
        }

        $errors = [];
        $valid_regions = Regions::get_codes();

        foreach ($partner_tags as $region => $tag) {
            if (!in_array(strtoupper($region), $valid_regions, true)) {
                Validation::add_field_error(
                    $errors,
                    "partner_tags.{$region}",
                    "Invalid region code: {$region}"
                );
            }

            if (!is_string($tag) || empty(trim($tag))) {
                Validation::add_field_error(
                    $errors,
                    "partner_tags.{$region}",
                    "Partner tag must be a non-empty string"
                );
            }
        }

        if (!empty($errors)) {
            return Response::validation_error($errors);
        }

        return true;
    }

    /**
     * Validate a Creators API credential version string.
     *
     * Accepts an optional leading "v"/"V" (Associates Central displays it
     * that way) against the six values Amazon_Creators_API::get_token_endpoint()
     * recognizes - Cognito 2.1/2.2/2.3, Login-with-Amazon 3.1/3.2/3.3.
     *
     * @param mixed $version
     * @return true|WP_Error
     */
    private function validate_creators_version($version) {
        if (!is_string($version) || !in_array(ltrim($version, 'vV'), ['2.1', '2.2', '2.3', '3.1', '3.2', '3.3'], true)) {
            return Response::validation_error([
                ['field' => 'creators_credential_version', 'message' => 'Must be one of 2.1, 2.2, 2.3, 3.1, 3.2, 3.3 (optionally prefixed with "v")'],
            ]);
        }

        return true;
    }
}

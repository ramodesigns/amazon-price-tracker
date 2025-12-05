<?php
/**
 * Settings REST Controller
 *
 * Handles the /settings endpoints for user Amazon PA-API settings.
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

        // POST /settings/validate - Validate Amazon PA-API credentials
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
            'access_key' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'secret_key' => [
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

        $access_key = $request->get_param('access_key');
        $secret_key = $request->get_param('secret_key');
        $partner_tags = $request->get_param('partner_tags');

        // Validate partner tags if provided
        if ($partner_tags !== null) {
            $validation_result = $this->validate_partner_tags($partner_tags);
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

            if ($access_key !== null) {
                $update_data['access_key'] = Encryption::encrypt($access_key);
            }

            if ($secret_key !== null) {
                $update_data['secret_key'] = Encryption::encrypt($secret_key);
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

            if (empty($access_key)) {
                Validation::add_field_error($errors, 'access_key', 'Access key is required for initial setup');
            }

            if (empty($secret_key)) {
                Validation::add_field_error($errors, 'secret_key', 'Secret key is required for initial setup');
            }

            if (!empty($errors)) {
                return Response::validation_error($errors);
            }

            $insert_data = [
                'user_id' => $user_id,
                'access_key' => Encryption::encrypt($access_key),
                'secret_key' => Encryption::encrypt($secret_key),
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
     * Validate Amazon PA-API credentials
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function validate_credentials(WP_REST_Request $request) {
        $user_id = $this->get_current_user_id();
        $settings = $this->get_user_settings($user_id);

        if (!$settings) {
            return Response::not_configured(__('Amazon PA-API credentials not configured', 'amazon-price-tracker'));
        }

        // Get decrypted credentials
        $access_key = Encryption::decrypt($settings->access_key);
        $secret_key = Encryption::decrypt($settings->secret_key);

        if (empty($access_key) || empty($secret_key)) {
            return Response::not_configured(__('Amazon PA-API credentials not configured', 'amazon-price-tracker'));
        }

        // TODO: Make actual test request to Amazon PA-API
        // For now, we'll just validate that credentials exist
        // The actual validation will be implemented in the Amazon API service

        return Response::success([
            'valid' => true,
            'message' => __('Credentials configured (validation pending Amazon API integration)', 'amazon-price-tracker'),
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
        $access_key = Encryption::decrypt($settings->access_key);

        return [
            'id' => (int) $settings->id,
            'user_id' => (int) $settings->user_id,
            'access_key' => $access_key ? Encryption::mask($access_key) : '',
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
}

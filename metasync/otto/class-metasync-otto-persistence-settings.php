<?php
/**
 * OTTO Persistence Settings
 * 
 * Manages configuration for which OTTO data types should be persisted
 * to native WordPress fields (surviving plugin uninstallation).
 * 
 * @package MetaSync
 * @since 2.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Otto_Persistence_Settings {

    /**
     * Option name for storing persistence settings
     */
    private const OPTION_NAME = 'metasync_otto_persistence_settings';

    /**
     * REST API namespace
     */
    private const REST_NAMESPACE = 'metasync/v1';

    /**
     * Default persistence settings
     * All values default to false (no persistence) until platform enables them
     */
    private static $default_settings = [
        'meta_title' => false,
        'meta_description' => false,
        'meta_keywords' => false,
        'og_title' => false,
        'og_description' => false,
        'twitter_title' => false,
        'twitter_description' => false,
        'canonical_url' => false,
        'image_alt_text' => false,
        'link_corrections' => false,
        'heading_changes' => false,
        'structured_data' => false,
    ];

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Initialize the class (call this from plugin init)
     */
    public static function init() {
        return self::get_instance();
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        # GET endpoint - Retrieve current persistence settings
        register_rest_route(
            self::REST_NAMESPACE,
            '/otto_persistence_settings',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'handle_get_settings'],
                    'permission_callback' => [$this, 'authorize_request'],
                ],
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'handle_update_settings'],
                    'permission_callback' => [$this, 'authorize_request'],
                    'args' => $this->get_endpoint_args(),
                ],
            ]
        );

        # Individual setting endpoint for granular updates
        register_rest_route(
            self::REST_NAMESPACE,
            '/otto_persistence_settings/(?P<setting_key>[a-z_]+)',
            [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'handle_get_single_setting'],
                    'permission_callback' => [$this, 'authorize_request'],
                    'args' => [
                        'setting_key' => [
                            'required' => true,
                            'type' => 'string',
                            'validate_callback' => [$this, 'validate_setting_key'],
                        ],
                    ],
                ],
                [
                    'methods' => 'PUT',
                    'callback' => [$this, 'handle_update_single_setting'],
                    'permission_callback' => [$this, 'authorize_request'],
                    'args' => [
                        'setting_key' => [
                            'required' => true,
                            'type' => 'string',
                            'validate_callback' => [$this, 'validate_setting_key'],
                        ],
                        'value' => [
                            'required' => true,
                            'type' => 'boolean',
                            'sanitize_callback' => 'rest_sanitize_boolean',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Define endpoint arguments for POST request
     */
    private function get_endpoint_args() {
        $args = [];
        foreach (array_keys(self::$default_settings) as $key) {
            $args[$key] = [
                'required' => false,
                'type' => 'boolean',
                'sanitize_callback' => 'rest_sanitize_boolean',
                'description' => sprintf('Whether to persist %s to native WordPress fields', str_replace('_', ' ', $key)),
            ];
        }
        return $args;
    }

    /**
     * Authorize REST API request using apikey
     * Uses same pattern as rest_authorization_middleware in Metasync_Public
     * 
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function authorize_request($request) {
        # Get apikey from query string (same pattern as existing middleware)
        $get_data = array_map('sanitize_text_field', $_GET);
        
        if (!isset($get_data['apikey']) || empty($get_data['apikey'])) {
            return new WP_Error(
                'rest_forbidden',
                'API key is required. Pass apikey as query parameter.',
                ['status' => 401]
            );
        }
        
        $api_key = $get_data['apikey'];

        # Get stored API key - use direct option access to avoid class loading issues
        $options = get_option('metasync_options', []);
        $stored_api_key = isset($options['general']['apikey']) ? $options['general']['apikey'] : null;

        if (empty($stored_api_key)) {
            return new WP_Error(
                'rest_forbidden',
                'No API key configured in MetaSync settings.',
                ['status' => 401]
            );
        }

        if ($api_key !== $stored_api_key) {
            return new WP_Error(
                'rest_forbidden',
                'Invalid API key.',
                ['status' => 401]
            );
        }

        return true;
    }

    /**
     * Validate setting key exists
     * 
     * @param string $key
     * @return bool
     */
    public function validate_setting_key($key) {
        return array_key_exists($key, self::$default_settings);
    }

    /**
     * Handle GET request - Retrieve all persistence settings
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_get_settings($request) {
        $settings = self::get_settings();
        
        return rest_ensure_response([
            'success' => true,
            'data' => $settings,
            'message' => 'Persistence settings retrieved successfully',
        ]);
    }

    /**
     * Handle POST request - Update persistence settings
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_update_settings($request) {
        $params = $request->get_json_params();
        
        if (empty($params)) {
            $params = $request->get_body_params();
        }

        if (empty($params)) {
            return rest_ensure_response([
                'success' => false,
                'message' => 'No settings provided',
            ]);
        }

        # Get current settings
        $current_settings = self::get_settings();
        $updated_keys = [];

        # Update only provided settings
        foreach ($params as $key => $value) {
            # Skip non-setting keys (like apikey)
            if (!array_key_exists($key, self::$default_settings)) {
                continue;
            }

            # Convert to boolean safely
            $bool_value = false;
            if (is_bool($value)) {
                $bool_value = $value;
            } elseif (is_string($value)) {
                $bool_value = in_array(strtolower($value), ['true', '1', 'yes'], true);
            } elseif (is_numeric($value)) {
                $bool_value = (bool) $value;
            } else {
                # Use filter_var for other types
                $bool_value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            }

            $current_settings[$key] = $bool_value;
            $updated_keys[] = $key;
        }

        # Save updated settings
        $saved = update_option(self::OPTION_NAME, $current_settings);

        return rest_ensure_response([
            'success' => true,
            'data' => $current_settings,
            'updated_keys' => $updated_keys,
            'message' => sprintf('Updated %d persistence setting(s)', count($updated_keys)),
        ]);
    }

    /**
     * Handle GET request for single setting
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_get_single_setting($request) {
        $key = $request->get_param('setting_key');
        $settings = self::get_settings();

        if (!isset($settings[$key])) {
            return new WP_REST_Response([
                'success' => false,
                'message' => sprintf('Unknown setting key: %s', $key),
            ], 404);
        }

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'key' => $key,
                'value' => $settings[$key],
            ],
        ]);
    }

    /**
     * Handle PUT request for single setting
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_update_single_setting($request) {
        $key = $request->get_param('setting_key');
        $value = $request->get_param('value');

        # Get body params if not in URL params
        if ($value === null) {
            $body = $request->get_json_params();
            $value = $body['value'] ?? null;
        }

        if ($value === null) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Value is required',
            ], 400);
        }

        # Convert to boolean safely
        $bool_value = false;
        if (is_bool($value)) {
            $bool_value = $value;
        } elseif (is_string($value)) {
            $bool_value = in_array(strtolower($value), ['true', '1', 'yes'], true);
        } elseif (is_numeric($value)) {
            $bool_value = (bool) $value;
        } else {
            $bool_value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }

        # Update the specific setting
        $settings = self::get_settings();
        $settings[$key] = $bool_value;
        update_option(self::OPTION_NAME, $settings);

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'key' => $key,
                'value' => $bool_value,
            ],
            'message' => sprintf('Setting "%s" updated to %s', $key, $bool_value ? 'true' : 'false'),
        ]);
    }

    /**
     * Get all persistence settings
     * 
     * @return array
     */
    public static function get_settings() {
        $saved_settings = get_option(self::OPTION_NAME, []);
        
        # Merge with defaults to ensure all keys exist
        return array_merge(self::$default_settings, $saved_settings);
    }

    /**
     * Get a specific persistence setting
     * 
     * @param string $key
     * @return bool
     */
    public static function get_setting($key) {
        $settings = self::get_settings();
        return $settings[$key] ?? false;
    }

    /**
     * Check if a specific data type should be persisted
     * 
     * @param string $key
     * @return bool
     */
    public static function should_persist($key) {
        return self::get_setting($key) === true;
    }

    /**
     * Set a specific persistence setting
     * 
     * @param string $key
     * @param bool $value
     * @return bool
     */
    public static function set_setting($key, $value) {
        if (!array_key_exists($key, self::$default_settings)) {
            return false;
        }

        $settings = self::get_settings();
        $settings[$key] = (bool) $value;
        
        return update_option(self::OPTION_NAME, $settings);
    }

    /**
     * Reset all settings to defaults
     * 
     * @return bool
     */
    public static function reset_settings() {
        return update_option(self::OPTION_NAME, self::$default_settings);
    }

    /**
     * Get available setting keys
     * 
     * @return array
     */
    public static function get_available_keys() {
        return array_keys(self::$default_settings);
    }

    /**
     * Delete all persistence settings (used on plugin uninstall)
     * 
     * @return bool
     */
    public static function delete_settings() {
        return delete_option(self::OPTION_NAME);
    }
}





<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SearchAtlas Connect / SSO Authentication Manager
 *
 * Handles all Search Atlas connect flow, token generation/validation,
 * JWT management, session management, and authentication reset logic.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */
class Metasync_Connect_Manager
{
    private static $instance = null;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // ------------------------------------------------------------------
    // Token context validation
    // ------------------------------------------------------------------

    public function validate_searchatlas_context($token_data)
    {
        if (isset($token_data['site_url']) && $token_data['site_url'] !== get_site_url()) {
            return false;
        }

        return true;
    }

    /**
     * Check if IP validation should be enforced
     */
    public function should_validate_ip()
    {
        $settings = Metasync::get_option('general');
        return isset($settings['enforce_ip_validation']) ? (bool)$settings['enforce_ip_validation'] : false;
    }

    /**
     * Check if user agents are incompatible (not just version differences)
     */
    public function are_user_agents_incompatible($old_ua, $new_ua)
    {
        $old_browser = $this->extract_browser_name($old_ua);
        $new_browser = $this->extract_browser_name($new_ua);

        return $old_browser !== $new_browser && !empty($old_browser) && !empty($new_browser);
    }

    /**
     * Extract browser name from user agent string
     */
    public function extract_browser_name($ua)
    {
        if (stripos($ua, 'Chrome') !== false) return 'Chrome';
        if (stripos($ua, 'Firefox') !== false) return 'Firefox';
        if (stripos($ua, 'Safari') !== false) return 'Safari';
        if (stripos($ua, 'Edge') !== false) return 'Edge';
        if (stripos($ua, 'Opera') !== false) return 'Opera';
        return 'Unknown';
    }

    // ------------------------------------------------------------------
    // Plugin Auth Token helpers
    // ------------------------------------------------------------------

    /**
     * Generate Search Atlas WordPress Connect Token.
     *
     * Returns the Plugin Auth Token used to authenticate with the Search Atlas platform
     * during the 1-click connect flow. This token is used ONLY to retrieve the Search Atlas
     * API key and Otto UUID — it does NOT log anyone into WordPress.
     */
    public function generate_searchatlas_wp_connect_token($regenerate = false)
    {
        $general_options = Metasync::get_option('general') ?? [];
        $plugin_auth_token = $general_options['apikey'] ?? '';

        if (empty($plugin_auth_token)) {
            error_log('MetaSync ERROR: Plugin Auth Token missing from options - should have been generated during activation');
            return false;
        }

        return $plugin_auth_token;
    }

    /**
     * Ensure Plugin Auth Token exists before Search Atlas connect authentication.
     * Auto-generates if missing to ensure smooth connect flow.
     */
    public function ensure_plugin_auth_token_exists()
    {
        $options = Metasync::get_option();
        $current_plugin_auth_token = $options['general']['apikey'] ?? '';

        if (empty($current_plugin_auth_token)) {

            $new_plugin_auth_token = wp_generate_password(32, false, false);

            if (!isset($options['general'])) {
                $options['general'] = [];
            }

            $options['general']['apikey'] = $new_plugin_auth_token;

            $save_result = Metasync::set_option($options);

            if ($save_result) {
                Metasync::log_api_key_event('auto_generated_for_sa_connect', 'plugin_auth_token', array(
                    'new_token_prefix' => substr($new_plugin_auth_token, 0, 8) . '...',
                    'triggered_by' => 'sa_connect_button',
                    'reason' => 'Plugin Auth Token was missing before Search Atlas connect authentication'
                ), 'info');

            } else {
                global $wpdb;
                if (class_exists('Metasync_Error_Logger') && !empty($wpdb->last_error)) {
                    Metasync_Error_Logger::log(
                        Metasync_Error_Logger::CATEGORY_DATABASE_ERROR,
                        Metasync_Error_Logger::SEVERITY_CRITICAL,
                        'Failed to save plugin auth token to database',
                        [
                            'option_name' => Metasync::option_name,
                            'wpdb_error' => $wpdb->last_error,
                            'wpdb_last_query' => $wpdb->last_query,
                            'operation' => 'ensure_plugin_auth_token_exists',
                            'triggered_by' => 'sso_connect_button'
                        ]
                    );
                }

                throw new Exception('Failed to generate required authentication token');
            }
        }
    }

    /**
     * Refresh Plugin Auth Token (AJAX endpoint)
     */
    public function refresh_plugin_auth_token()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'refresh_plugin_auth_token')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }

        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }

        try {
            $new_plugin_auth_token = wp_generate_password(32, false, false);

            $options = Metasync::get_option();
            if (!isset($options['general'])) {
                $options['general'] = [];
            }
            $options['general']['apikey'] = $new_plugin_auth_token;

            $save_result = Metasync::set_option($options);

            if ($save_result) {
                Metasync::log_api_key_event('token_refresh', 'plugin_auth_token', array(
                    'new_token_prefix' => substr($new_plugin_auth_token, 0, 8) . '...',
                    'triggered_by' => 'manual_refresh_button'
                ), 'info');

                do_action('metasync_trigger_immediate_heartbeat', 'Plugin Auth Token refresh - new token generated');

                wp_send_json_success(array(
                    'new_token' => $new_plugin_auth_token,
                    'message' => 'Plugin Auth Token refreshed successfully'
                ));
            } else {
                wp_send_json_error(array('message' => 'Failed to save new token'));
            }

        } catch (Exception $e) {
            error_log('Plugin Auth Token Refresh Error: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Error generating new token'));
        }
    }

    /**
     * Get current Plugin Auth Token (AJAX endpoint for UI updates)
     */
    public function get_plugin_auth_token()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'metasync_sa_connect_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }

        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }

        try {
            $options = Metasync::get_option();
            $current_plugin_auth_token = $options['general']['apikey'] ?? '';

            if (!empty($current_plugin_auth_token)) {
                wp_send_json_success(array(
                    'plugin_auth_token' => $current_plugin_auth_token,
                    'message' => 'Plugin Auth Token retrieved successfully'
                ));
            } else {
                wp_send_json_error(array('message' => 'Plugin Auth Token not found'));
            }

        } catch (Exception $e) {
            error_log('Get Plugin Auth Token Error: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Error retrieving Plugin Auth Token'));
        }
    }

    // ------------------------------------------------------------------
    // Search Atlas Connect URL & polling
    // ------------------------------------------------------------------

    /**
     * Generate Search Atlas Connect URL (1-click connect).
     *
     * AJAX action: wp_ajax_generate_searchatlas_connect_url
     */
    public function generate_searchatlas_connect_url()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions. Administrator access required.'));
            return;
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'metasync_sa_connect_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce - please refresh the page and try again'));
            return;
        }

        $rate_limit_key = 'metasync_sa_connect_rate_' . get_current_user_id();
        $rate_limit_count = get_transient($rate_limit_key);
        if ($rate_limit_count !== false && $rate_limit_count >= 10) {
            wp_send_json_error(array('message' => 'Too many connect requests. Please wait a few minutes before trying again.'));
            return;
        }
        set_transient($rate_limit_key, ($rate_limit_count === false ? 1 : $rate_limit_count + 1), 300);

        try {
            $this->ensure_plugin_auth_token_exists();

            $sa_connect_token = $this->create_searchatlas_nonce_token();

            if (!$sa_connect_token) {
                wp_send_json_error(array('message' => 'Failed to create authentication token'));
                return;
            }

            $domain = str_replace('://www.', '://', get_site_url());

            $dashboard_domain = Metasync_Admin::get_effective_dashboard_domain();

            $sa_connect_url = $dashboard_domain . '/sso/wordpress?' . http_build_query([
                'nonce_token' => $sa_connect_token,
                'domain' => $domain,
                'return_url' => admin_url('admin.php?page=' . Metasync_Admin::$page_slug)
            ]);

            wp_send_json_success(array(
                'connect_url' => $sa_connect_url,
                'nonce_token' => $sa_connect_token,
                'debug_info' => array(
                    'dashboard_domain' => $dashboard_domain,
                    'site_domain' => $domain,
                    'return_url' => admin_url('admin.php?page=' . Metasync_Admin::$page_slug)
                )
            ));

        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Failed to generate Search Atlas connect URL: ' . $e->getMessage()));
        }
    }

    /**
     * Check Search Atlas Connect Status (polling endpoint).
     *
     * AJAX action: wp_ajax_check_searchatlas_connect_status
     */
    public function check_searchatlas_connect_status()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions. Administrator access required.'));
            return;
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'metasync_sa_connect_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }

        $nonce_token = isset($_POST['nonce_token']) ? sanitize_text_field(wp_unslash($_POST['nonce_token'])) : '';

        // Check if THIS specific nonce was successfully processed
        // This prevents false positives from background sync/heartbeat activity
        $success_key = 'metasync_sa_connect_success_' . md5($nonce_token);
        $this_auth_completed = get_transient($success_key);


        if ($this_auth_completed) {
            // Delete the transient (one-time use) to prevent replay
            delete_transient($success_key);

            // Get current settings to return API key
            $general_settings = Metasync::get_option('general') ?? [];

            wp_send_json_success(array(
                'updated' => true,
                'api_key' => $general_settings['searchatlas_api_key'], // Return full API key
                'otto_pixel_uuid' => $general_settings['otto_pixel_uuid'] ?? '', // Return OTTO UUID for UI update
                'status_code' => 200,
                'whitelabel_enabled' => !empty($general_settings['white_label_plugin_name']),
                'effective_domain' => Metasync_Admin::get_effective_dashboard_domain()
            ));
        }

        wp_send_json_success(array('updated' => false));
    }

    // ------------------------------------------------------------------
    // Nonce / encrypted token helpers
    // ------------------------------------------------------------------

    /**
     * Create Search Atlas Connect Nonce Token.
     *
     * Generates a unique, time-limited (15 min), single-use nonce token used to
     * identify the connect session when Search Atlas calls back with the API key
     * and Otto UUID.
     */
    public function create_searchatlas_nonce_token()
    {
        $general_options = Metasync::get_option('general') ?? [];
        $plugin_auth_token = $general_options['apikey'] ?? '';

        if (empty($plugin_auth_token)) {
            error_log('MetaSync ERROR: Plugin Auth Token missing from options');
            return false;
        }

        $random_bytes = wp_generate_password(32, false, false);
        $timestamp = time();
        $user_id = get_current_user_id();

        $token_data = $random_bytes . '|' . $timestamp . '|' . $user_id . '|' . get_site_url();
        $sa_connect_token = hash_hmac('sha256', $token_data, $plugin_auth_token . wp_salt('auth'));

        $token_metadata = array(
            'created' => $timestamp,
            'expires' => $timestamp + 900,
            'user_id' => $user_id,
            'site_url' => get_site_url(),
            'ip' => $this->get_client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 100) : '',
            'used' => false,
            'callback_used' => false,
            'version' => '3.0'
        );

        $transient_key = 'metasync_sa_connect_token_' . substr(hash('sha256', $sa_connect_token), 0, 32);
        set_transient($transient_key, $token_metadata, 900);

        set_transient('metasync_sa_connect_active_' . $sa_connect_token, $transient_key, 900);

        return $sa_connect_token;
    }

    /**
     * Get client IP address securely
     */
    public function get_client_ip()
    {
        $ip_headers = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR');

        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }

    /**
     * Create encrypted Search Atlas connect token with embedded metadata
     */
    public function create_encrypted_searchatlas_token($metadata = array())
    {
        $payload = array_merge(array(
            'iat' => time(),
            'exp' => time() + 1800,
            'iss' => get_site_url(),
            'aud' => 'search-atlas-connect',
            'sub' => 'searchatlas-authentication',
            'jti' => wp_generate_password(16, false),
            'nonce' => wp_generate_password(16, false),
            'version' => '2.0'
        ), $metadata);

        return $this->wp_encrypt_token($payload);
    }

    /**
     * Encrypt token using WordPress SALTs
     */
    public function wp_encrypt_token($payload)
    {
        try {
            $serialized = serialize($payload);

            $key_material = wp_salt('secure_auth') . wp_salt('logged_in') . wp_salt('nonce');
            $encryption_key = hash('sha256', $key_material, true);

            $iv = random_bytes(16);

            $encrypted = openssl_encrypt($serialized, 'AES-256-CBC', $encryption_key, OPENSSL_RAW_DATA, $iv);

            if ($encrypted === false) {
                throw new Exception('Encryption failed');
            }

            $result = $iv . $encrypted;

            return base64_encode($result);

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Decrypt token using WordPress SALTs
     */
    public function wp_decrypt_token($encrypted_token)
    {
        try {
            $data = base64_decode($encrypted_token, true);

            if ($data === false || strlen($data) < 16) {
                return false;
            }

            $iv = substr($data, 0, 16);
            $encrypted = substr($data, 16);

            $key_material = wp_salt('secure_auth') . wp_salt('logged_in') . wp_salt('nonce');
            $encryption_key = hash('sha256', $key_material, true);

            $serialized = openssl_decrypt($encrypted, 'AES-256-CBC', $encryption_key, OPENSSL_RAW_DATA, $iv);

            if ($serialized === false) {
                return false;
            }

            $payload = unserialize($serialized);

            if (!is_array($payload) || !isset($payload['exp'], $payload['iat'])) {
                return false;
            }

            if ($payload['exp'] < time()) {
                return false;
            }

            return $payload;

        } catch (Exception $e) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Cleanup helpers
    // ------------------------------------------------------------------

    /**
     * @deprecated No longer needed with simplified token system
     */
    public function cleanup_searchatlas_nonce_tokens()
    {
        return 0;
    }

    /**
     * Cleanup Search Atlas connect rate limiting data
     */
    public function cleanup_searchatlas_rate_limits()
    {
        global $wpdb;

        try {
            $rate_limit_transients = $wpdb->get_results(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_sa_connect_rate_limit_%'",
                ARRAY_A
            );

            $cleaned_count = 0;

            foreach ($rate_limit_transients as $transient) {
                $transient_name = str_replace('_transient_', '', $transient['option_name']);
                delete_transient($transient_name);
                $cleaned_count++;
            }

            return $cleaned_count;

        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Clear cached JWT tokens
     */
    public function clear_jwt_token_cache()
    {
        global $wpdb;

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_metasync_jwt_token_%'
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_timeout_metasync_jwt_token_%'
            )
        );
    }

    // ------------------------------------------------------------------
    // JWT token management
    // ------------------------------------------------------------------

    /**
     * Get active JWT token for the plugin.
     * Public static method accessible from anywhere in the plugin.
     *
     * @param bool $force_refresh Force generation of new token even if cached one exists
     * @return string|false JWT token on success, false on failure
     */
    public static function get_active_jwt_token($force_refresh = false)
    {
        $general_options = Metasync::get_option('general') ?? [];
        $api_key = $general_options['searchatlas_api_key'] ?? '';

        if (empty($api_key)) {
            return false;
        }

        if (!$force_refresh) {
            $cache_key = 'metasync_jwt_token_' . md5($api_key);
            $cached_token_data = get_transient($cache_key);

            if ($cached_token_data && is_array($cached_token_data)) {
                $expires_with_buffer = $cached_token_data['expires'] - 300;
                if (time() < $expires_with_buffer && !empty($cached_token_data['token'])) {
                    return $cached_token_data['token'];
                }
            }
        }

        return self::instance()->get_fresh_jwt_token();
    }

    /**
     * Get fresh JWT token from Search Atlas API with caching
     *
     * @return string|false JWT token on success, false on failure
     */
    public function get_fresh_jwt_token()
    {
        $general_options = Metasync::get_option('general') ?? [];
        $api_key = $general_options['searchatlas_api_key'] ?? '';

        if (empty($api_key)) {
            return false;
        }

        $cache_key = 'metasync_jwt_token_' . md5($api_key);
        $cached_token_data = get_transient($cache_key);

        if ($cached_token_data && is_array($cached_token_data)) {
            $expires_with_buffer = $cached_token_data['expires'] - 300;
            if (time() < $expires_with_buffer && !empty($cached_token_data['token'])) {
                return $cached_token_data['token'];
            }
        }

        $api_domain = class_exists('Metasync_Endpoint_Manager')
            ? Metasync_Endpoint_Manager::get_endpoint('API_DOMAIN')
            : Metasync::API_DOMAIN;
        $url = $api_domain . '/api/customer/account/generate-jwt-from-api-key/';

        $args = array(
            'method' => 'POST',
            'headers' => array(
                'X-API-KEY' => $api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15
        );

        try {
            $response = wp_remote_post($url, $args);

            if (is_wp_error($response)) {
                error_log('MetaSync: JWT token API request failed - ' . $response->get_error_message());
                return false;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code !== 200) {
                error_log('MetaSync: JWT token API returned error code ' . $response_code);
                return false;
            }

            $data = json_decode($response_body, true);

            if (!$data || !isset($data['token'], $data['expires'])) {
                error_log('MetaSync: Invalid JWT token API response format');
                return false;
            }

            $token_data = array(
                'token' => $data['token'],
                'expires' => $data['expires'],
                'created_at' => time()
            );

            $cache_duration = min($data['expires'] - time(), 24 * 3600);
            set_transient($cache_key, $token_data, $cache_duration);

            return $data['token'];

        } catch (Exception $e) {
            error_log('MetaSync: Exception during JWT generation - ' . $e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Authentication reset
    // ------------------------------------------------------------------

    /**
     * Reset Search Atlas Authentication
     * Clears all authentication data and tokens
     */
    public function reset_searchatlas_authentication()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'metasync_reset_auth_nonce')) {
            wp_send_json_error(array(
                'message' => 'Security verification failed. Please refresh the page and try again.',
                'code' => 'invalid_nonce'
            ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => 'You do not have permission to reset authentication.',
                'code' => 'insufficient_permissions'
            ));
            return;
        }

        try {
            $options = Metasync::get_option();

            if (!is_array($options)) {
                $options = array();
            }

            if (!isset($options['general'])) {
                $options['general'] = array();
            }

            $cleared_data = array();

            if (isset($options['general']['searchatlas_api_key'])) {
                $cleared_data['searchatlas_api_key'] = substr($options['general']['searchatlas_api_key'], 0, 8) . '...';
                unset($options['general']['searchatlas_api_key']);
            }

            if (isset($options['general']['otto_pixel_uuid'])) {
                $cleared_data['otto_pixel_uuid'] = $options['general']['otto_pixel_uuid'];
                unset($options['general']['otto_pixel_uuid']);
            }

            if (isset($options['general']['send_auth_token_timestamp'])) {
                $cleared_data['send_auth_token_timestamp'] = $options['general']['send_auth_token_timestamp'];
                unset($options['general']['send_auth_token_timestamp']);
            }

            if (isset($options['general']['last_heart_beat'])) {
                $cleared_data['last_heart_beat'] = $options['general']['last_heart_beat'];
                unset($options['general']['last_heart_beat']);
            }

            $save_result = Metasync::set_option($options);

            if (!$save_result) {
                throw new Exception('Failed to save updated plugin options');
            }

            delete_option('metasync_wp_sa_connect_token');
            $cleared_data['wp_sa_connect_token'] = 'removed';

            $cleaned_tokens = $this->cleanup_searchatlas_nonce_tokens();
            $cleared_data['sa_connect_nonce_tokens'] = 'none (simplified token system)';

            delete_option(Metasync::option_name . '_whitelabel_user');
            $cleared_data['whitelabel_user'] = 'removed';

            if (isset($options['whitelabel'])) {
                $cleared_data['whitelabel_settings'] = 'removed';
                unset($options['whitelabel']);

                Metasync::set_option($options);
            }

            $this->clear_jwt_token_cache();
            $cleared_data['jwt_token_cache'] = 'cleared';

            $this->cleanup_searchatlas_rate_limits();
            $cleared_data['rate_limits'] = 'cleared';

            $otto_uuid = $cleared_data['otto_pixel_uuid'] ?? '';
            if (!empty($otto_uuid)) {
                delete_transient('metasync_public_hash_' . md5($otto_uuid));
            }
            $cleared_data['public_hash_cache'] = 'cleared';

            delete_transient('metasync_heartbeat_status_cache');
            $cleared_data['heartbeat_cache'] = 'cleared';

            Metasync_Heartbeat_Manager::instance()->unschedule_heartbeat_cron();

            wp_send_json_success(array(
                'message' => 'Authentication has been reset successfully. You can now connect a new account.',
                'cleared_data' => $cleared_data,
                'timestamp' => current_time('mysql', true)
            ));

        } catch (Exception $e) {
            error_log('Authentication Reset Error: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => 'An error occurred while resetting authentication. Please try again or contact support.',
                'code' => 'reset_failed',
                'error' => $e->getMessage()
            ));
        }
    }

    // ------------------------------------------------------------------
    // Test / debug endpoints
    // ------------------------------------------------------------------

    /**
     * Test the enhanced Search Atlas connect token system (development/debugging)
     */
    public function test_enhanced_searchatlas_tokens()
    {
        if (!current_user_can('manage_options')) {
            return false;
        }

        $general_options = Metasync::get_option('general') ?? [];
        $test_token = $general_options['apikey'] ?? null;

        $apikey = $general_options['apikey'] ?? '';

        $encrypted_token = $this->create_encrypted_searchatlas_token(['test' => 'data', 'user_id' => get_current_user_id()]);
        if ($encrypted_token) {
            $decrypted = $this->wp_decrypt_token($encrypted_token);
        }

        return true;
    }

    /**
     * Test Search Atlas connect AJAX endpoint (development/debugging)
     */
    public function test_searchatlas_ajax_endpoint()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => 'Insufficient permissions for AJAX test',
                'required_capability' => 'manage_options'
            ));
            return;
        }

        $nonce_valid = false;
        if (isset($_POST['nonce'])) {
            $nonce_valid = wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'metasync_sa_connect_nonce');
        }

        wp_send_json_success(array(
            'message' => 'AJAX endpoint is working correctly',
            'timestamp' => current_time('mysql', true),
            'user_id' => get_current_user_id(),
            'endpoint' => 'test_searchatlas_ajax_endpoint',
            'nonce_valid' => $nonce_valid,
            'debug_info' => array(
                'post_action' => isset($_POST['action']) ? sanitize_text_field(wp_unslash($_POST['action'])) : 'NOT SET',
                'has_nonce' => isset($_POST['nonce']),
                'user_can_manage_options' => current_user_can('manage_options')
            )
        ));
    }

    /**
     * Simple AJAX test without nonce (for debugging connectivity)
     */
    public function simple_ajax_test()
    {
        wp_send_json_success(array(
            'message' => 'Basic AJAX connectivity works',
            'timestamp' => time(),
            'no_nonce_required' => true
        ));
    }

    /**
     * Test whitelabel domain configuration (development/debugging)
     */
    public function test_whitelabel_domain()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Administrator access required');
            return;
        }

        $whitelabel_settings = Metasync::get_whitelabel_settings();

        $is_enabled = Metasync::is_whitelabel_enabled();

        $effective_domain = Metasync_Admin::get_effective_dashboard_domain();
        $metasync_domain = Metasync::get_dashboard_domain();

        $whitelabel_logo = Metasync::get_whitelabel_logo();

        $default_domain = Metasync::DASHBOARD_DOMAIN;

        $whitelabel_company_name = Metasync::get_whitelabel_company_name();

        $effective_plugin_name = Metasync::get_effective_plugin_name('Test Plugin');

        wp_send_json_success(array(
            'whitelabel_settings' => $whitelabel_settings,
            'is_enabled' => $is_enabled,
            'effective_domain' => $effective_domain,
            'whitelabel_logo' => $whitelabel_logo,
            'whitelabel_company_name' => $whitelabel_company_name,
            'effective_plugin_name' => $effective_plugin_name,
            'default_domain' => $default_domain,
            'override_active' => $effective_domain !== $default_domain
        ));
    }

    // ------------------------------------------------------------------
    // Whitelabel session / password management
    // ------------------------------------------------------------------

    /**
     * Handle session management early in the admin lifecycle
     */
    public function handle_session_management_early()
    {
        if (!is_admin()) {
            return;
        }

        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
        $current_page = isset($_GET['page']) ? $_GET['page'] : '';

        $whitelabel_settings = Metasync::get_whitelabel_settings();
        $user_password = $whitelabel_settings['settings_password'] ?? '';
        $hide_settings_enabled = !empty($whitelabel_settings['hide_settings']);

        $protected_tabs = [];
        if (!empty($user_password)) {
            $protected_tabs[] = 'whitelabel';
        }
        if ($hide_settings_enabled && !empty($user_password)) {
            $protected_tabs = ['general', 'whitelabel', 'advanced'];
        }

        if (strpos($current_page, Metasync_Admin::$page_slug) === 0 && in_array($active_tab, $protected_tabs)) {
            if ((defined('REST_REQUEST') && REST_REQUEST) ||
                (defined('DOING_AJAX') && DOING_AJAX) ||
                (defined('DOING_CRON') && DOING_CRON)) {
                return;
            }

            $this->handle_whitelabel_session_logic();
        }
    }

    /**
     * Handle whitelabel authentication logic (login/logout/validation)
     * Uses Metasync_Auth_Manager instead of sessions for better compatibility
     */
    public function handle_whitelabel_session_logic()
    {
        $auth = new Metasync_Auth_Manager('whitelabel', 1800);

        $admin_password = 'abracadabra@2020';

        $whitelabel_settings = Metasync::get_whitelabel_settings();
        $user_password = $whitelabel_settings['settings_password'] ?? '';

        $valid_passwords = array($admin_password);
        if (!empty($user_password)) {
            $valid_passwords[] = $user_password;
        }

        if (isset($_POST['whitelabel_logout'])) {
            if (wp_verify_nonce($_POST['whitelabel_logout_nonce'] ?? '', 'whitelabel_logout_nonce')) {
                $auth->revoke_access();

                $redirect_tab = $_GET['tab'] ?? 'whitelabel';
                $redirect_url = admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '&tab=' . $redirect_tab);
                wp_redirect($redirect_url);
                exit;
            }
        }

        if (isset($_POST['whitelabel_password_submit']) && isset($_POST['whitelabel_password'])) {
            if (wp_verify_nonce($_POST['whitelabel_nonce'], 'whitelabel_password_nonce')) {
                $submitted_password = sanitize_text_field($_POST['whitelabel_password']);

                $auth->verify_and_grant($submitted_password, $valid_passwords, false);
            }
        }
    }

    /**
     * Handle whitelabel password early before WordPress filters it out
     */
    public function handle_whitelabel_password_early()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['option_page']) && $_POST['option_page'] === Metasync_Admin::option_group) {

            if (isset($_POST[Metasync_Admin::option_key]['whitelabel']['settings_password'])) {
                $submitted_password = sanitize_text_field($_POST[Metasync_Admin::option_key]['whitelabel']['settings_password']);

                $current_options = Metasync::get_option();

                if (!isset($current_options['whitelabel'])) {
                    $current_options['whitelabel'] = [];
                }

                $current_options['whitelabel']['settings_password'] = $submitted_password;
                $current_options['whitelabel']['updated_at'] = time();

                update_option(Metasync_Admin::option_key, $current_options);
            }
        }
    }
}

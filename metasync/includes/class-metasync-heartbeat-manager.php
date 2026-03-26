<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Heartbeat / connection-monitoring manager.
 *
 * Extracted from Metasync_Admin to keep the admin class focused on UI concerns.
 * All cron scheduling, heartbeat API connectivity checks, public-hash fetching,
 * burst-mode logic, and related logging live here.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */
class Metasync_Heartbeat_Manager
{
    /** @var self|null */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return self
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // ------------------------------------------------------------------
    //  Heartbeat connectivity (cache-only for frontend)
    // ------------------------------------------------------------------

    /**
     * Check if heartbeat API is properly connected (frontend – cache only).
     * Frontend should NEVER trigger API calls – only use cached results from cron job.
     * Returns false immediately if plugin API key is not configured.
     * Uses graceful fallback to last known state when cache is missing.
     */
    public function is_heartbeat_connected($general_settings = null)
    {
        if ($general_settings === null) {
            $general_settings = Metasync::get_option('general') ?? [];
        }

        $searchatlas_api_key = $general_settings['searchatlas_api_key'] ?? '';

        if (empty($searchatlas_api_key)) {
            return false;
        }

        $cache_key = 'metasync_heartbeat_status_cache';
        $cached_result = get_transient($cache_key);

        if ($cached_result !== false) {
            $this->log_heartbeat('info', 'Cache hit - using cached heartbeat status', array(
                'status' => $cached_result['status'] ? 'CONNECTED' : 'DISCONNECTED',
                'cached_at' => date('Y-m-d H:i:s T', $cached_result['timestamp']),
                'expires_at' => date('Y-m-d H:i:s T', $cached_result['cached_until']),
                'cache_age_seconds' => time() - $cached_result['timestamp']
            ));
            return $cached_result['status'];
        }

        $last_known_state = $this->get_last_known_connection_state();

        if ($last_known_state !== null) {
            $this->log_heartbeat('info', 'Cache miss - using last known heartbeat status', array(
                'status' => $last_known_state ? 'CONNECTED' : 'DISCONNECTED',
                'note' => 'Graceful fallback until next cron job updates cache',
                'fallback_reason' => 'cache_expired_or_missing'
            ));
            return $last_known_state;
        }

        $this->log_heartbeat('info', 'No cached or last known heartbeat status found - returning default DISCONNECTED', array(
            'note' => 'Cron job will establish initial connection state',
            'status' => 'DISCONNECTED'
        ));

        return false;
    }

    // ------------------------------------------------------------------
    //  Public-hash fetching (OTTO API)
    // ------------------------------------------------------------------

    /**
     * Fetch public hash from OTTO API for the given pixel UUID.
     *
     * @param string $otto_pixel_uuid The OTTO pixel UUID to fetch hash for.
     * @param string $jwt_token       The JWT authentication token.
     * @return string|false           The public hash on success, false on failure.
     */
    public function fetch_public_hash($otto_pixel_uuid, $jwt_token)
    {
        $cache_duration = 3600;
        $api_timeout = 15;
        $max_retries = 3;
        $base_retry_delay = 1;

        if (!$this->validate_fetch_hash_inputs($otto_pixel_uuid, $jwt_token)) {
            $this->log_fetch_hash_error('error', 'Invalid input parameters provided', [
                'uuid_provided' => !empty($otto_pixel_uuid),
                'token_provided' => !empty($jwt_token)
            ]);
            return false;
        }

        $otto_pixel_uuid = sanitize_text_field(trim($otto_pixel_uuid));
        $jwt_token = sanitize_text_field(trim($jwt_token));

        $cached_hash = $this->get_cached_public_hash($otto_pixel_uuid);
        if ($cached_hash !== false) {
            $this->log_fetch_hash_error('info', 'Public hash retrieved from cache', [
                'uuid' => substr($otto_pixel_uuid, 0, 8) . '...'
            ]);
            return $cached_hash;
        }

        $api_url = $this->build_otto_api_url($otto_pixel_uuid);
        $headers = $this->prepare_api_headers($jwt_token);

        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $this->log_fetch_hash_error('info', 'Attempting to fetch public hash from API', [
                'attempt' => $attempt,
                'max_retries' => $max_retries,
                'uuid' => substr($otto_pixel_uuid, 0, 8) . '...'
            ]);

            $response = wp_remote_get($api_url, [
                'headers' => $headers,
                'timeout' => $api_timeout,
                'sslverify' => true,
                'redirection' => 2,
                'user-agent' => 'WordPress MetaSync Plugin/' . (defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0')
            ]);

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $error_code = $response->get_error_code();

                if (class_exists('Metasync_Error_Logger')) {
                    Metasync_Error_Logger::log(
                        Metasync_Error_Logger::CATEGORY_NETWORK_ERROR,
                        Metasync_Error_Logger::SEVERITY_ERROR,
                        'OTTO API network request failed',
                        [
                            'attempt' => $attempt,
                            'error_code' => $error_code,
                            'error_message' => $error_message,
                            'api_endpoint' => 'OTTO Projects API',
                            'operation' => 'fetch_public_hash',
                            'will_retry' => $attempt < $max_retries,
                            'max_retries' => $max_retries
                        ]
                    );
                }

                $this->log_fetch_hash_error('error', 'HTTP request failed', [
                    'attempt' => $attempt,
                    'error_code' => $error_code,
                    'error_message' => $error_message,
                    'will_retry' => $attempt < $max_retries
                ]);

                if ($attempt < $max_retries) {
                    $this->apply_exponential_backoff($attempt, $base_retry_delay);
                    continue;
                }

                return false;
            }

            $result = $this->process_api_response($response, $attempt, $max_retries);

            if ($result === 'retry' && $attempt < $max_retries) {
                $this->apply_exponential_backoff($attempt, $base_retry_delay);
                continue;
            }

            if ($result !== false && $result !== 'retry') {
                $this->cache_public_hash($otto_pixel_uuid, $result, $cache_duration);
                $this->log_fetch_hash_error('info', 'Public hash successfully retrieved and cached', [
                    'uuid' => substr($otto_pixel_uuid, 0, 8) . '...',
                    'attempt' => $attempt
                ]);
                return $result;
            }

            break;
        }

        $this->log_fetch_hash_error('error', 'Failed to fetch public hash after all retry attempts', [
            'uuid' => substr($otto_pixel_uuid, 0, 8) . '...',
            'total_attempts' => $max_retries
        ]);

        return false;
    }

    // ------------------------------------------------------------------
    //  Public-hash helpers (private)
    // ------------------------------------------------------------------

    private function validate_fetch_hash_inputs($uuid, $token)
    {
        if (empty($uuid) || empty($token)) {
            return false;
        }
        if (!is_string($uuid) || strlen($uuid) < 10) {
            return false;
        }
        if (!is_string($token) || substr_count($token, '.') < 2) {
            return false;
        }
        return true;
    }

    private function get_cached_public_hash($uuid)
    {
        $cache_key = 'metasync_public_hash_' . hash('sha256', $uuid . get_current_blog_id());
        return get_transient($cache_key);
    }

    private function cache_public_hash($uuid, $hash, $duration)
    {
        $cache_key = 'metasync_public_hash_' . hash('sha256', $uuid . get_current_blog_id());
        set_transient($cache_key, sanitize_text_field($hash), $duration);
    }

    private function build_otto_api_url($uuid)
    {
        $base_url = class_exists('Metasync_Endpoint_Manager')
            ? Metasync_Endpoint_Manager::get_endpoint('OTTO_PROJECTS')
            : 'https://sa.searchatlas.com/api/v2/otto-projects';

        $base_url = rtrim($base_url, '/') . '/';

        return $base_url . urlencode($uuid) . '/';
    }

    private function prepare_api_headers($jwt_token)
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $jwt_token,
            'Content-Type' => 'application/json',
            'User-Agent' => 'WordPress MetaSync Plugin/' . (defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0'),
            'Cache-Control' => 'no-cache',
            'X-Requested-With' => 'XMLHttpRequest'
        ];
    }

    private function process_api_response($response, $attempt, $max_retries)
    {
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        switch ($status_code) {
            case 200:
                return $this->extract_public_hash_from_response($body);

            case 401:
            case 403:
                if (class_exists('Metasync_Error_Logger')) {
                    Metasync_Error_Logger::log(
                        Metasync_Error_Logger::CATEGORY_AUTHENTICATION_FAILURE,
                        Metasync_Error_Logger::SEVERITY_ERROR,
                        'OTTO API authentication failed',
                        [
                            'status_code' => $status_code,
                            'attempt' => $attempt,
                            'api_endpoint' => 'OTTO Projects API',
                            'operation' => 'fetch_public_hash',
                            'http_status' => $status_code === 401 ? 'Unauthorized' : 'Forbidden'
                        ]
                    );
                }

                $this->log_fetch_hash_error('error', 'Authentication failed', [
                    'status_code' => $status_code,
                    'attempt' => $attempt
                ]);
                return false;

            case 404:
                $this->log_fetch_hash_error('error', 'OTTO project not found', [
                    'status_code' => $status_code,
                    'attempt' => $attempt
                ]);
                return false;

            case 429:
                if (class_exists('Metasync_Error_Logger')) {
                    Metasync_Error_Logger::log(
                        Metasync_Error_Logger::CATEGORY_API_RATE_LIMIT,
                        Metasync_Error_Logger::SEVERITY_WARNING,
                        'OTTO API rate limit exceeded',
                        [
                            'status_code' => $status_code,
                            'attempt' => $attempt,
                            'will_retry' => $attempt < $max_retries,
                            'api_endpoint' => 'OTTO Projects API',
                            'operation' => 'fetch_public_hash',
                            'max_retries' => $max_retries
                        ]
                    );
                }

                $this->log_fetch_hash_error('warning', 'API rate limit exceeded', [
                    'status_code' => $status_code,
                    'attempt' => $attempt,
                    'will_retry' => $attempt < $max_retries
                ]);
                return 'retry';

            case 500:
            case 502:
            case 503:
            case 504:
                $this->log_fetch_hash_error('warning', 'Server error encountered', [
                    'status_code' => $status_code,
                    'attempt' => $attempt,
                    'will_retry' => $attempt < $max_retries
                ]);
                return 'retry';

            default:
                $this->log_fetch_hash_error('error', 'Unexpected HTTP status code', [
                    'status_code' => $status_code,
                    'attempt' => $attempt,
                    'response_body' => substr($body, 0, 200)
                ]);
                return false;
        }
    }

    private function extract_public_hash_from_response($body)
    {
        if (empty($body)) {
            $this->log_fetch_hash_error('error', 'Empty response body received');
            return false;
        }

        $data = json_decode($body, true);
        $json_error = json_last_error();

        if ($json_error !== JSON_ERROR_NONE) {
            $this->log_fetch_hash_error('error', 'Invalid JSON response', [
                'json_error' => $json_error,
                'json_error_msg' => json_last_error_msg(),
                'body_preview' => substr($body, 0, 200)
            ]);
            return false;
        }

        if (!is_array($data)) {
            $this->log_fetch_hash_error('error', 'Response data is not an array', [
                'data_type' => gettype($data)
            ]);
            return false;
        }

        $possible_hash_fields = [
            'public_share_hash',
            'public_hash',
            'publicHash',
            'hash',
            'public_key',
            'publicKey'
        ];

        foreach ($possible_hash_fields as $field) {
            if (isset($data[$field]) && !empty($data[$field]) && is_string($data[$field])) {
                $hash = sanitize_text_field(trim($data[$field]));

                if (preg_match('/^[a-zA-Z0-9_-]{10,}$/', $hash)) {
                    $this->log_fetch_hash_error('info', 'Public hash extracted successfully', [
                        'field_name' => $field,
                        'hash_length' => strlen($hash)
                    ]);
                    return $hash;
                }
            }
        }

        $this->log_fetch_hash_error('error', 'Public hash not found in response', [
            'available_fields' => array_keys($data),
            'searched_fields' => $possible_hash_fields
        ]);

        return false;
    }

    private function apply_exponential_backoff($attempt, $base_delay)
    {
        $delay = $base_delay * pow(2, $attempt - 1);
        $max_delay = 30;
        $delay = min($delay, $max_delay);

        if (class_exists('Metasync_Error_Logger')) {
            Metasync_Error_Logger::log(
                Metasync_Error_Logger::CATEGORY_API_BACKOFF,
                Metasync_Error_Logger::SEVERITY_INFO,
                'API backoff active - applying exponential retry delay',
                [
                    'attempt' => $attempt,
                    'delay_seconds' => $delay,
                    'base_delay' => $base_delay,
                    'max_delay' => $max_delay,
                    'api_endpoint' => 'OTTO Projects API',
                    'operation' => 'fetch_public_hash'
                ]
            );
        }

        $this->log_fetch_hash_error('info', 'Applying retry delay', [
            'attempt' => $attempt,
            'delay_seconds' => $delay
        ]);

        sleep($delay);
    }

    private function log_fetch_hash_error($level, $message, $context = [])
    {
        if ($level === 'info') {
            return;
        }

        $full_context = array_merge([
            'operation' => 'fetch_public_hash',
            'timestamp' => current_time('mysql'),
            'site_url' => get_site_url()
        ], $context);

        $log_message = sprintf(
            'OTTO_API_%s: %s',
            strtoupper($level),
            $message
        );

        if (!empty($full_context)) {
            $context_parts = [];
            foreach ($full_context as $key => $value) {
                if (is_array($value)) {
                    $value = json_encode($value);
                } elseif (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                } elseif (is_string($value) && strlen($value) > 100) {
                    $value = substr($value, 0, 100) . '...';
                }
                $context_parts[] = "{$key}={$value}";
            }
            $log_message .= ' | ' . implode(', ', $context_parts);
        }

        error_log($log_message);
    }

    public function clear_public_hash_cache($otto_pixel_uuid = '')
    {
        if (empty($otto_pixel_uuid)) {
            $general_options = Metasync::get_option('general');
            $otto_pixel_uuid = isset($general_options['otto_pixel_uuid']) ? $general_options['otto_pixel_uuid'] : '';
        }

        if (!empty($otto_pixel_uuid)) {
            $cache_key = 'metasync_public_hash_' . md5($otto_pixel_uuid);
            delete_transient($cache_key);
        }
    }

    // ------------------------------------------------------------------
    //  Connection state persistence
    // ------------------------------------------------------------------

    private function get_last_known_connection_state()
    {
        return get_option('metasync_last_known_connection_state', null);
    }

    private function set_last_known_connection_state($is_connected)
    {
        $success = update_option('metasync_last_known_connection_state', (bool) $is_connected);
        return $success;
    }

    // ------------------------------------------------------------------
    //  Logging helpers
    // ------------------------------------------------------------------

    private function log_heartbeat($level, $event, $details = array())
    {
        if ($level == 'info') {
            return;
        }
        if ($this->should_throttle_log($level, $event, $details)) {
            return;
        }

        $context = array(
            'event' => $event,
            'level' => strtoupper($level),
            'plugin_version' => defined('METASYNC_VERSION') ? METASYNC_VERSION : 'unknown',
            'site_url' => get_site_url(),
        );

        $context = array_merge($context, $details);

        $message = sprintf(
            'HEARTBEAT_%s: %s',
            strtoupper($level),
            $event
        );

        if (!empty($details)) {
            $details_formatted = array();
            foreach ($details as $key => $value) {
                if (is_array($value)) {
                    $value = json_encode($value);
                } elseif (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                } elseif (is_string($value) && strlen($value) > 200) {
                    $value = $this->smart_truncate($value, 200);
                }
                $details_formatted[] = "{$key}={$value}";
            }
            $message .= ' | ' . implode(', ', $details_formatted);
        }

        error_log($message);

        if ($level === 'error' || $level === 'critical') {
            $this->store_heartbeat_error_log($event, $details);
        }
    }

    private function smart_truncate($string, $length = 200)
    {
        if (strlen($string) <= $length) {
            return $string;
        }

        $truncated = substr($string, 0, $length);
        $last_space = strrpos($truncated, ' ');

        if ($last_space !== false && $last_space > $length * 0.75) {
            $truncated = substr($truncated, 0, $last_space);
        }

        $truncated = strip_tags($truncated);
        return $truncated . '... [truncated]';
    }

    private function should_throttle_log($level, $event, $details = array())
    {
        if ($level === 'error' || $level === 'critical') {
            return false;
        }

        if (strpos($event, 'Cache hit') !== false || strpos($event, 'No cached heartbeat status found') !== false) {
            static $last_cache_log_time = 0;
            static $last_cache_status = '';
            $current_time = time();

            $current_status = isset($details['status']) ? $details['status'] : 'UNKNOWN';

            if ($current_status !== $last_cache_status ||
                ($current_time - $last_cache_log_time) > 300 ||
                $last_cache_log_time === 0) {

                $last_cache_log_time = $current_time;
                $last_cache_status = $current_status;
                return false;
            }

            return true;
        }

        return false;
    }

    private function store_heartbeat_error_log($event, $details)
    {
        try {
            if (class_exists('Metasync_HeartBeat_Error_Monitor_Database')) {
                $error_db = new Metasync_HeartBeat_Error_Monitor_Database();
                $error_db->add(array(
                    'attribute_name' => 'heartbeat_connectivity',
                    'object_count' => 1,
                    'error_description' => json_encode(array(
                        'event' => $event,
                        'details' => $details,
                        'timestamp' => current_time('mysql')
                    )),
                    'created_at' => current_time('mysql')
                ));
            }
        } catch (Exception $e) {
            error_log('Failed to store heartbeat error log: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    //  Heartbeat API connectivity test
    // ------------------------------------------------------------------

    private function test_heartbeat_api_connection($general_settings)
    {
        $searchatlas_api_key = $general_settings['searchatlas_api_key'] ?? '';
        $apikey = $general_settings['apikey'] ?? '';

        $start_time = microtime(true);
        $api_key_type = strpos($searchatlas_api_key, 'pub-') === 0 ? 'publisher' : 'regular';

        $this->log_heartbeat('info', 'Initiating heartbeat API test using SyncCustomerParams', array(
            'api_key_type' => $api_key_type,
            'api_key_prefix' => substr($searchatlas_api_key, 0, 8) . '...',
            'url' => get_home_url(),
            'method' => 'reuse_existing_sync_class'
        ));

        $sync_request = new Metasync_Sync_Requests();
        $response = $sync_request->SyncCustomerParams($apikey);

        $request_duration = round((microtime(true) - $start_time) * 1000, 2);

        if (is_wp_error($response)) {
            $this->log_heartbeat('error', 'Heartbeat test via SyncCustomerParams failed', array(
                'error_code' => $response->get_error_code(),
                'error_message' => $response->get_error_message(),
                'request_duration_ms' => $request_duration,
                'error_type' => 'wp_error'
            ));
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            $this->log_heartbeat('error', 'Heartbeat test returned non-200 status', array(
                'status_code' => $status_code,
                'response_body' => $this->smart_truncate($body, 300),
                'request_duration_ms' => $request_duration,
                'error_type' => 'http_status_error'
            ));
            return false;
        }

        $this->log_heartbeat('info', 'Heartbeat API test successful via SyncCustomerParams', array(
            'status_code' => $status_code,
            'request_duration_ms' => $request_duration,
            'response_size_bytes' => strlen($body),
            'method' => 'sync_customer_params'
        ));

        $general_settings['send_auth_token_timestamp'] = current_time('mysql');
        $general_settings['last_heartbeat_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $options = Metasync::get_option();
        $options['general'] = $general_settings;
        Metasync::set_option($options);

        return true;
    }

    // ------------------------------------------------------------------
    //  Cron scheduling
    // ------------------------------------------------------------------

    public function schedule_heartbeat_cron()
    {
        $this->unschedule_heartbeat_cron();

        if (!wp_next_scheduled('metasync_heartbeat_cron_check')) {
            $scheduled = wp_schedule_event(time(), 'metasync_every_2_hours', 'metasync_heartbeat_cron_check');

            if ($scheduled) {
                $this->log_heartbeat('info', 'Heartbeat cron job scheduled successfully', array(
                    'interval' => '2 hours',
                    'next_run' => date('Y-m-d H:i:s T', wp_next_scheduled('metasync_heartbeat_cron_check'))
                ));
            } else {
                $this->log_heartbeat('error', 'Failed to schedule heartbeat cron job');
            }
        }
    }

    /**
     * Build the heartbeat API URL for backoff check (same host as SyncCustomerParams uses).
     *
     * @param array $general_settings Plugin general options.
     * @return string|null Heartbeat URL or null if not determinable.
     */
    private function get_heartbeat_api_url_for_backoff_check($general_settings) {
        $api_key = $general_settings['searchatlas_api_key'] ?? '';
        if (empty($api_key)) {
            return null;
        }
        if (strpos($api_key, 'pub-') === 0) {
            $domain = class_exists('Metasync_Endpoint_Manager')
                ? Metasync_Endpoint_Manager::get_endpoint('API_DOMAIN')
                : Metasync::API_DOMAIN;
            return $domain . '/api/publisher/one-click-publishing/wp-website-heartbeat/';
        }
        $domain = class_exists('Metasync_Endpoint_Manager')
            ? Metasync_Endpoint_Manager::get_endpoint('CA_API_DOMAIN')
            : Metasync::CA_API_DOMAIN;
        return $domain . '/api/wp-website-heartbeat/';
    }

    public function unschedule_heartbeat_cron()
    {
        $timestamp = wp_next_scheduled('metasync_heartbeat_cron_check');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'metasync_heartbeat_cron_check');
            $this->log_heartbeat('info', 'Heartbeat cron job unscheduled', array(
                'was_scheduled_for' => date('Y-m-d H:i:s T', $timestamp)
            ));
        }
    }

    /**
     * Background cron job execution – performs actual heartbeat check.
     * This method should ONLY be called by the cron job, never by frontend.
     */
    public function execute_heartbeat_cron_check()
    {
        $this->log_heartbeat('info', 'Background heartbeat cron check starting');

        $general_settings = Metasync::get_option('general') ?? [];

        $searchatlas_api_key = $general_settings['searchatlas_api_key'] ?? '';

        if (empty($searchatlas_api_key)) {
            $this->log_heartbeat('info', 'Skipping heartbeat API call - ' . Metasync::get_effective_plugin_name() . ' API key not configured', array(
                'has_searchatlas_api_key' => false,
                'reason' => 'User has not provided ' . Metasync::get_effective_plugin_name() . ' API key yet'
            ));

            $cache_data = array(
                'status' => false,
                'timestamp' => time(),
                'cached_until' => time() + 300,
                'updated_by' => 'cron_job_no_api_key'
            );

            set_transient('metasync_heartbeat_status_cache', $cache_data, 300);

            $this->set_last_known_connection_state(false);

            $this->log_heartbeat('info', 'Background heartbeat check completed without API call', array(
                'status' => 'DISCONNECTED',
                'reason' => 'API key not configured',
                'cached_until' => date('Y-m-d H:i:s T', $cache_data['cached_until']),
                'next_cron_run' => wp_next_scheduled('metasync_heartbeat_cron_check') ?
                                  date('Y-m-d H:i:s T', wp_next_scheduled('metasync_heartbeat_cron_check')) : 'N/A'
            ));

            return false;
        }

        $is_connected = $this->test_heartbeat_api_connection($general_settings);

        // When heartbeat failed due to api_backoff_active, do not overwrite cache or last_known.
        // This preserves the optimistic CONNECTED state set after callback so the dashboard iframe stays visible.
        if (!$is_connected && class_exists('Metasync_API_Backoff_Manager')) {
            $heartbeat_url = $this->get_heartbeat_api_url_for_backoff_check($general_settings);
            $backoff_manager = Metasync_API_Backoff_Manager::get_instance();
            if ($heartbeat_url && $backoff_manager->is_endpoint_in_backoff($heartbeat_url)) {
                $this->log_heartbeat('info', 'Heartbeat check skipped cache update - endpoint in backoff', array(
                    'reason' => 'api_backoff_active',
                    'next_cron_run' => wp_next_scheduled('metasync_heartbeat_cron_check') ?
                        date('Y-m-d H:i:s T', wp_next_scheduled('metasync_heartbeat_cron_check')) : 'N/A',
                ));
                return false;
            }
        }

        $cache_data = array(
            'status' => $is_connected,
            'timestamp' => time(),
            'cached_until' => time() + 300,
            'updated_by' => 'cron_job'
        );

        set_transient('metasync_heartbeat_status_cache', $cache_data, 300);

        $this->set_last_known_connection_state($is_connected);

        $this->log_heartbeat('info', 'Background heartbeat check completed', array(
            'status' => $is_connected ? 'CONNECTED' : 'DISCONNECTED',
            'cached_until' => date('Y-m-d H:i:s T', $cache_data['cached_until']),
            'next_cron_run' => wp_next_scheduled('metasync_heartbeat_cron_check') ?
                              date('Y-m-d H:i:s T', wp_next_scheduled('metasync_heartbeat_cron_check')) : 'N/A'
        ));

        return $is_connected;
    }

    /**
     * Add custom cron schedule for 2-hour intervals and daily cleanup.
     */
    public function add_heartbeat_cron_schedule($schedules)
    {
        $schedules['metasync_every_2_hours'] = array(
            'interval' => 2 * HOUR_IN_SECONDS,
            'display' => esc_html__('Every 2 Hours (MetaSync)', 'metasync')
        );

        $schedules['metasync_every_2_minutes'] = array(
            'interval' => 2 * MINUTE_IN_SECONDS,
            'display' => esc_html__('Every 2 Minutes (MetaSync Burst)', 'metasync')
        );
        $schedules['metasync_every_5_minutes'] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => esc_html__('Every 5 Minutes (MetaSync)', 'metasync')
        );
        // 10-minute heartbeat cadence used for UNREGISTERED + KEY_PENDING short-interval behavior
        $schedules['metasync_every_10_minutes'] = array(
            'interval' => 10 * MINUTE_IN_SECONDS,
            'display' => esc_html__('Every 10 Minutes (MetaSync)', 'metasync')
        );

        $schedules['metasync_daily_cleanup'] = array(
            'interval' => DAY_IN_SECONDS,
            'display' => esc_html__('Daily (MetaSync Cleanup)', 'metasync')
        );

        $schedules['metasync_weekly'] = array(
            'interval' => 7 * DAY_IN_SECONDS,
            'display' => esc_html__('Weekly (MetaSync)', 'metasync')
        );

        return $schedules;
    }

    // ------------------------------------------------------------------
    //  Heartbeat state machine (PR3)
    // ------------------------------------------------------------------

    public function get_heartbeat_state()
    {
        $general = Metasync::get_option('general') ?? [];
        $api_key = $general['searchatlas_api_key'] ?? '';
        if (empty($api_key)) {
            return 'UNREGISTERED';
        }
        $state = $general['heartbeat_state'] ?? '';
        return ($state === 'CONNECTED') ? 'CONNECTED' : 'KEY_PENDING';
    }

    public function set_heartbeat_state_key_pending()
    {
        $options = Metasync::get_option();
        if (!isset($options['general'])) {
            $options['general'] = [];
        }
        $options['general']['heartbeat_state'] = 'KEY_PENDING';
        $options['general']['heartbeat_state_changed_at'] = time();
        Metasync::set_option($options);
        delete_option('metasync_burst_attempt_count');
        $this->maybe_schedule_heartbeat_cron();
    }

    /**
     * PR3: Burst cron — run heartbeat when state is KEY_PENDING.
     * After 5 attempts with no confirmation, stop burst and fall back to 2-hour cron.
     */
    public function execute_burst_heartbeat()
    {
        if ($this->get_heartbeat_state() !== 'KEY_PENDING') {
            return;
        }
        $count = (int) get_option('metasync_burst_attempt_count', 0);
        $count++;
        update_option('metasync_burst_attempt_count', $count);

        $this->execute_heartbeat_cron_check();

        if ($this->get_heartbeat_state() === 'CONNECTED') {
            delete_option('metasync_burst_attempt_count');
            return;
        }
        if ($count >= 5) {
            $this->unschedule_burst_heartbeat_cron();
            update_option('metasync_burst_gave_up', true);
            if (!wp_next_scheduled('metasync_heartbeat_cron_check')) {
                wp_schedule_event(time(), 'metasync_every_2_hours', 'metasync_heartbeat_cron_check');
            }
        }
    }

    /**
     * Announce cron — send pre-SSO announce when state is UNREGISTERED.
     * Hard cap of 5 total pings per activation lifecycle (ping 1 on activation, pings 2-5 here).
     */
    public function execute_announce_cron()
    {
        $general = Metasync::get_option('general') ?? [];
        if (!empty($general['searchatlas_api_key'] ?? '')) {
            $this->unschedule_announce_cron();
            return;
        }

        $count = (int) get_option('metasync_announce_attempt_count', 0);
        if ($count >= 5) {
            $this->unschedule_announce_cron();
            return;
        }

        $count++;
        update_option('metasync_announce_attempt_count', $count);

        if (!class_exists('Metasync_Activator')) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-activator.php';
        }
        Metasync_Activator::send_announce_ping();

        if ($count >= 5) {
            $this->unschedule_announce_cron();
        }
    }

    public function unschedule_burst_heartbeat_cron()
    {
        $timestamp = wp_next_scheduled('metasync_burst_heartbeat');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'metasync_burst_heartbeat');
        }
    }

    public function unschedule_announce_cron()
    {
        $timestamp = wp_next_scheduled('metasync_announce_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'metasync_announce_cron');
        }
    }

    /**
     * Maybe schedule heartbeat cron job based on PR3 state.
     * UNREGISTERED: announce cron every 10 min. KEY_PENDING: burst every 10 min. CONNECTED: 2-hour only.
     *
     * Throttled to run at most once per 5 minutes to prevent concurrent page loads
     * from racing on the wp_cron option and producing `could_not_set` errors.
     */
    public function maybe_schedule_heartbeat_cron()
    {
        // Skip if already evaluated recently — avoids cron-option race conditions on busy sites
        if (get_transient('metasync_cron_schedule_checked')) {
            return;
        }
        set_transient('metasync_cron_schedule_checked', 1, 5 * MINUTE_IN_SECONDS);

        $state = $this->get_heartbeat_state();

        if ($state === 'UNREGISTERED') {
            $this->unschedule_heartbeat_cron();
            $this->unschedule_burst_heartbeat_cron();
            if (!wp_next_scheduled('metasync_announce_cron') && (int) get_option('metasync_announce_attempt_count', 0) < 5) {
                wp_schedule_event(time(), 'metasync_every_10_minutes', 'metasync_announce_cron');
            }
            return;
        }

        $this->unschedule_announce_cron();

        if ($state === 'KEY_PENDING') {
            if (get_option('metasync_burst_gave_up')) {
                $this->unschedule_burst_heartbeat_cron();
                if (!wp_next_scheduled('metasync_heartbeat_cron_check')) {
                    wp_schedule_event(time(), 'metasync_every_2_hours', 'metasync_heartbeat_cron_check');
                }
                return;
            }
            $this->unschedule_heartbeat_cron();
            $this->unschedule_burst_heartbeat_cron();
            if (!wp_next_scheduled('metasync_burst_heartbeat')) {
                delete_option('metasync_burst_attempt_count');
                wp_schedule_event(time(), 'metasync_every_10_minutes', 'metasync_burst_heartbeat');
            }
            return;
        }

        if ($state === 'CONNECTED') {
            $this->unschedule_burst_heartbeat_cron();
            delete_option('metasync_burst_attempt_count');
            delete_option('metasync_burst_gave_up');
            $this->schedule_heartbeat_cron();
        }
    }


    // ------------------------------------------------------------------
    //  Immediate heartbeat trigger
    // ------------------------------------------------------------------

    public function trigger_immediate_heartbeat_check($context = 'Manual trigger')
    {
        static $last_immediate_check = 0;
        $current_time = time();

        if (($current_time - $last_immediate_check) < 10) {
            $this->log_heartbeat('info', 'Skipping immediate heartbeat check - too recent', array(
                'context' => $context,
                'seconds_since_last' => $current_time - $last_immediate_check,
                'protection' => 'race_condition_prevention'
            ));
            return true;
        }

        $last_immediate_check = $current_time;

        $this->log_heartbeat('info', 'Immediate heartbeat check triggered', array(
            'context' => $context,
            'triggered_by' => 'authentication_change'
        ));

        $general_settings = Metasync::get_option('general') ?? [];
        $searchatlas_api_key = $general_settings['searchatlas_api_key'] ?? '';

        if (empty($searchatlas_api_key)) {
            $this->log_heartbeat('info', 'Skipping immediate heartbeat check - ' . Metasync::get_effective_plugin_name() . ' API key not configured', array(
                'context' => $context,
                'has_searchatlas_api_key' => false,
                'reason' => 'User has not provided API key yet'
            ));

            delete_transient('metasync_heartbeat_status_cache');

            $cache_data = array(
                'status' => false,
                'timestamp' => time(),
                'cached_until' => time() + 300,
                'updated_by' => 'immediate_check_no_api_key'
            );

            set_transient('metasync_heartbeat_status_cache', $cache_data, 300);

            $this->set_last_known_connection_state(false);

            $this->log_heartbeat('info', 'Immediate heartbeat check completed without API call', array(
                'context' => $context,
                'result' => 'DISCONNECTED',
                'reason' => 'API key not configured',
                'cache_updated' => true
            ));

            return false;
        }

        delete_transient('metasync_heartbeat_status_cache');

        $result = $this->execute_heartbeat_cron_check();

        $this->log_heartbeat('info', 'Immediate heartbeat check completed', array(
            'context' => $context,
            'result' => $result ? 'CONNECTED' : 'DISCONNECTED',
            'cache_updated' => true
        ));

        return $result;
    }

    public function handle_immediate_heartbeat_trigger($context = 'WordPress action trigger')
    {
        $this->trigger_immediate_heartbeat_check($context);
    }

    // ------------------------------------------------------------------
    //  AJAX burst ping (PR3)
    // ------------------------------------------------------------------

    public function ajax_burst_ping()
    {
        if (!Metasync::current_user_has_plugin_access() || empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'metasync_burst_ping')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }
        $state = $this->get_heartbeat_state();
        if ($state === 'KEY_PENDING') {
            $_POST['is_heart_beat'] = true;
            $_POST['is_burst'] = true;
            $general = Metasync::get_option('general') ?? [];
            $apikey = $general['apikey'] ?? '';
            $sync = new Metasync_Sync_Requests();
            $sync->SyncCustomerParams($apikey);
            $state = $this->get_heartbeat_state();
            if ($state === 'CONNECTED') {
                $this->maybe_schedule_heartbeat_cron();
            }
            wp_send_json_success(array('state' => $state, 'heartbeat_confirmed' => ($state === 'CONNECTED')));
            return;
        }
        wp_send_json_success(array('state' => $state, 'heartbeat_confirmed' => true));
    }

    // ------------------------------------------------------------------
    //  Heartbeat cache update after sync
    // ------------------------------------------------------------------

    public function update_heartbeat_cache_after_sync($is_connected, $context = 'Sync operation')
    {
        $cache_data = array(
            'status' => $is_connected,
            'timestamp' => time(),
            'cached_until' => time() + 300,
            'updated_by' => 'sync_operation'
        );

        set_transient('metasync_heartbeat_status_cache', $cache_data, 300);

        $this->log_heartbeat('info', 'Heartbeat cache updated after sync operation', array(
            'context' => $context,
            'status' => $is_connected ? 'CONNECTED' : 'DISCONNECTED',
            'updated_by' => 'sync_operation',
            'cached_until' => date('Y-m-d H:i:s T', $cache_data['cached_until'])
        ));

        return $is_connected;
    }
}

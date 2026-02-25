<?php
/**
 * API Backoff Manager
 *
 * Handles exponential backoff for HTTP 429/503 responses from SearchAtlas APIs.
 * Implements adaptive backoff strategy with persistent state management.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 * @since      2.5.15
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Metasync_API_Backoff_Manager
 *
 * Manages exponential backoff for API rate limiting and service unavailability.
 * Features:
 * - Per-endpoint backoff tracking
 * - Exponential backoff strategy (5, 10, 15 minutes)
 * - Automatic counter reset after 1 hour of successful requests
 * - Persistent state using WordPress transients
 * - Multi-site support
 */
class Metasync_API_Backoff_Manager {

    /**
     * Singleton instance
     *
     * @var Metasync_API_Backoff_Manager|null
     */
    private static $instance = null;

    /**
     * Backoff transient prefix
     */
    private const BACKOFF_PREFIX = 'metasync_api_backoff_';

    /**
     * Counter transient prefix
     */
    private const COUNTER_PREFIX = 'metasync_api_counter_';

    /**
     * Last success transient prefix
     */
    private const LAST_SUCCESS_PREFIX = 'metasync_api_last_success_';

    /**
     * Backoff durations in seconds
     */
    private const BACKOFF_DURATIONS = [
        1 => 300,  // 5 minutes
        2 => 600,  // 10 minutes
        3 => 900,  // 15 minutes
    ];

    /**
     * Counter reset window (1 hour in seconds)
     */
    private const RESET_WINDOW = 3600;

    /**
     * HTTP codes that trigger backoff
     */
    private const TRIGGER_CODES = [429, 503];

    /**
     * Monitored endpoints (domain patterns)
     */
    private const MONITORED_ENDPOINTS = [
        'sa.searchatlas.com',
        'api.searchatlas.com',
        'ca.searchatlas.com',
        'sa.staging.searchatlas.com',
        'api.staging.searchatlas.com',
        'ca.staging.searchatlas.com',
    ];

    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        // Initialize hooks
        $this->init_hooks();
    }

    /**
     * Get singleton instance
     *
     * @return Metasync_API_Backoff_Manager
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Hook into HTTP API responses
        add_filter('http_response', [$this, 'intercept_http_response'], 10, 3);

        // Hook to check backoff before making requests
        add_filter('pre_http_request', [$this, 'check_backoff_before_request'], 10, 3);
    }

    /**
     * Intercept HTTP responses to detect 429/503 errors
     *
     * @param array|WP_Error $response HTTP response or WP_Error.
     * @param array          $args     HTTP request arguments.
     * @param string         $url      The request URL.
     * @return array|WP_Error
     */
    public function intercept_http_response($response, $args, $url) {
        // Skip if response is WP_Error
        if (is_wp_error($response)) {
            return $response;
        }

        // Check if URL is from monitored endpoints
        if (!$this->is_monitored_endpoint($url)) {
            return $response;
        }

        // Get response code
        $response_code = wp_remote_retrieve_response_code($response);

        // Check if response code triggers backoff
        if (in_array($response_code, self::TRIGGER_CODES, true)) {
            $this->handle_rate_limit_response($url, $response_code);
        } else if ($response_code >= 200 && $response_code < 300) {
            // Successful response - update last success timestamp
            $this->record_successful_request($url);
        }

        return $response;
    }

    /**
     * Check if endpoint is in backoff before making request
     *
     * @param false|array|WP_Error $preempt Whether to preempt an HTTP request's return value.
     * @param array                $args    HTTP request arguments.
     * @param string               $url     The request URL.
     * @return false|array|WP_Error
     */
    public function check_backoff_before_request($preempt, $args, $url) {
        // Skip if not a monitored endpoint
        if (!$this->is_monitored_endpoint($url)) {
            return $preempt;
        }

        // Check if endpoint is in backoff
        if ($this->is_endpoint_in_backoff($url)) {
            $endpoint_hash = $this->get_endpoint_hash($url);
            $backoff_data = $this->get_backoff_state($endpoint_hash);

            // Log the blocked request
            $this->log_backoff_event(
                'API_BACKOFF_BLOCKED',
                sprintf(
                    'Request blocked due to active backoff. Endpoint: %s, Time remaining: %d seconds',
                    $this->extract_endpoint($url),
                    $backoff_data['time_remaining']
                )
            );

            // Return WP_Error to prevent the request
            return new WP_Error(
                'api_backoff_active',
                sprintf(
                    'API endpoint is in backoff mode. Please wait %d seconds before retrying.',
                    $backoff_data['time_remaining']
                ),
                [
                    'endpoint' => $this->extract_endpoint($url),
                    'time_remaining' => $backoff_data['time_remaining'],
                    'occurrence_count' => $backoff_data['occurrence_count'],
                ]
            );
        }

        return $preempt;
    }

    /**
     * Handle rate limit response (429/503)
     *
     * @param string $url           The request URL.
     * @param int    $response_code HTTP response code.
     */
    private function handle_rate_limit_response($url, $response_code) {
        $endpoint_hash = $this->get_endpoint_hash($url);
        $endpoint = $this->extract_endpoint($url);

        // Check if we should reset counter based on last success
        $this->maybe_reset_counter($endpoint_hash);

        // Increment occurrence counter
        $occurrence_count = $this->increment_occurrence_counter($endpoint_hash);

        // Cap at 3 occurrences
        $occurrence_count = min($occurrence_count, 3);

        // Get backoff duration
        $backoff_duration = self::BACKOFF_DURATIONS[$occurrence_count];

        // Store backoff state
        $this->set_backoff_state($endpoint_hash, [
            'endpoint' => $endpoint,
            'occurrence_count' => $occurrence_count,
            'backoff_duration' => $backoff_duration,
            'response_code' => $response_code,
            'triggered_at' => current_time('timestamp'),
            'expires_at' => current_time('timestamp') + $backoff_duration,
        ]); 

        // Log the backoff event
        $this->log_backoff_event(
            'API_BACKOFF_TRIGGERED',
            sprintf(
                'Backoff triggered for endpoint: %s | Response Code: %d | Occurrence: %d/3 | Duration: %d seconds',
                $endpoint,
                $response_code,
                $occurrence_count,
                $backoff_duration
            )
        );

        // Trigger action for other components (e.g., admin notices)
        do_action('metasync_api_backoff_triggered', [
            'endpoint' => $endpoint,
            'endpoint_hash' => $endpoint_hash,
            'occurrence_count' => $occurrence_count,
            'backoff_duration' => $backoff_duration,
            'response_code' => $response_code,
        ]);
    }

    /**
     * Record successful request timestamp
     *
     * @param string $url The request URL.
     */
    private function record_successful_request($url) {
        $endpoint_hash = $this->get_endpoint_hash($url);
        $timestamp = current_time('timestamp');

        set_transient(
            self::LAST_SUCCESS_PREFIX . $endpoint_hash,
            $timestamp,
            self::RESET_WINDOW * 2 // Keep for 2 hours
        );

        // Check if we should reset counter
        $this->maybe_reset_counter($endpoint_hash);
    }

    /**
     * Maybe reset occurrence counter based on last success
     *
     * @param string $endpoint_hash The endpoint hash.
     */
    private function maybe_reset_counter($endpoint_hash) {
        $last_success = get_transient(self::LAST_SUCCESS_PREFIX . $endpoint_hash);
        $current_time = current_time('timestamp');

        // Reset counter if last success was more than 1 hour ago
        if ($last_success !== false && ($current_time - $last_success) >= self::RESET_WINDOW) {
            $this->reset_occurrence_counter($endpoint_hash);

            $this->log_backoff_event(
                'API_BACKOFF_COUNTER_RESET',
                sprintf(
                    'Counter reset for endpoint hash: %s (1 hour of successful requests)',
                    $endpoint_hash
                )
            );
        }
    }

    /**
     * Increment occurrence counter
     *
     * @param string $endpoint_hash The endpoint hash.
     * @return int New counter value.
     */
    private function increment_occurrence_counter($endpoint_hash) {
        $counter_key = self::COUNTER_PREFIX . $endpoint_hash;
        $count = (int) get_transient($counter_key);
        $count++;

        // Store with 2-hour expiry (longer than reset window)
        set_transient($counter_key, $count, self::RESET_WINDOW * 2);

        return $count;
    }

    /**
     * Reset occurrence counter
     *
     * @param string $endpoint_hash The endpoint hash.
     */
    private function reset_occurrence_counter($endpoint_hash) {
        $counter_key = self::COUNTER_PREFIX . $endpoint_hash;
        delete_transient($counter_key);
    }

    /**
     * Set backoff state
     *
     * @param string $endpoint_hash The endpoint hash.
     * @param array  $state         Backoff state data.
     */
    private function set_backoff_state($endpoint_hash, array $state) {
        $backoff_key = self::BACKOFF_PREFIX . $endpoint_hash;
        set_transient($backoff_key, $state, $state['backoff_duration']);
    }

    /**
     * Get backoff state
     *
     * @param string $endpoint_hash The endpoint hash.
     * @return array|false Backoff state or false if not in backoff.
     */
    public function get_backoff_state($endpoint_hash) {
        $backoff_key = self::BACKOFF_PREFIX . $endpoint_hash;
        $state = get_transient($backoff_key);

        if ($state === false) {
            return false;
        }

        // Calculate time remaining
        $state['time_remaining'] = max(0, $state['expires_at'] - current_time('timestamp'));

        return $state;
    }

    /**
     * Check if endpoint is currently in backoff
     *
     * @param string $url The request URL.
     * @return bool True if in backoff, false otherwise.
     */
    public function is_endpoint_in_backoff($url) {
        $endpoint_hash = $this->get_endpoint_hash($url);
        $state = $this->get_backoff_state($endpoint_hash);

        return $state !== false && $state['time_remaining'] > 0;
    }

    /**
     * Get all active backoffs
     *
     * @return array Array of active backoff states.
     */
    public function get_all_active_backoffs() {
        global $wpdb;

        $backoffs = [];

        // Query all backoff transients
        $transient_keys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options}
                 WHERE option_name LIKE %s",
                '_transient_' . self::BACKOFF_PREFIX . '%'
            )
        );

        foreach ($transient_keys as $key) {
            $endpoint_hash = str_replace('_transient_' . self::BACKOFF_PREFIX, '', $key);
            $state = $this->get_backoff_state($endpoint_hash);

            if ($state !== false && $state['time_remaining'] > 0) {
                $state['endpoint_hash'] = $endpoint_hash;
                $backoffs[] = $state;
            }
        }

        return $backoffs;
    }

    /**
     * Clear backoff for specific endpoint
     *
     * @param string $endpoint_hash The endpoint hash.
     * @return bool Success status.
     */
    public function clear_backoff($endpoint_hash) {
        $backoff_key = self::BACKOFF_PREFIX . $endpoint_hash;
        $deleted = delete_transient($backoff_key);

        if ($deleted) {
            $this->log_backoff_event(
                'API_BACKOFF_CLEARED',
                sprintf('Backoff manually cleared for endpoint hash: %s', $endpoint_hash)
            );
        }

        return $deleted;
    }

    /**
     * Clear all backoffs
     *
     * @return int Number of backoffs cleared.
     */
    public function clear_all_backoffs() {
        global $wpdb;

        $cleared_count = 0;
        $prefixes = [
            self::BACKOFF_PREFIX,
            self::COUNTER_PREFIX,
            self::LAST_SUCCESS_PREFIX,
        ];

        foreach ($prefixes as $prefix) {
            $transient_keys = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options}
                     WHERE option_name LIKE %s
                     OR option_name LIKE %s",
                    '_transient_' . $prefix . '%',
                    '_transient_timeout_' . $prefix . '%'
                )
            );

            foreach ($transient_keys as $key) {
                $transient_name = str_replace(['_transient_', '_transient_timeout_'], '', $key);
                delete_transient($transient_name);
                $cleared_count++;
            }
        }

        $this->log_backoff_event(
            'API_BACKOFF_ALL_CLEARED',
            sprintf('All backoffs cleared. Count: %d', $cleared_count)
        );

        return $cleared_count;
    }

    /**
     * Get endpoint hash for URL
     *
     * @param string $url The request URL.
     * @return string Endpoint hash.
     */
    private function get_endpoint_hash($url) {
        $endpoint = $this->extract_endpoint($url);
        return md5($endpoint);
    }

    /**
     * Extract endpoint domain from URL
     *
     * @param string $url The request URL.
     * @return string Endpoint domain.
     */
    private function extract_endpoint($url) {
        $parsed = wp_parse_url($url);
        return $parsed['host'] ?? '';
    }

    /**
     * Check if URL is from monitored endpoint
     *
     * @param string $url The request URL.
     * @return bool True if monitored, false otherwise.
     */
    private function is_monitored_endpoint($url) {
        $endpoint = $this->extract_endpoint($url);

        foreach (self::MONITORED_ENDPOINTS as $monitored) {
            if (strpos($endpoint, $monitored) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Log backoff event
     *
     * @param string $event_type Event type identifier.
     * @param string $message    Log message.
     */
    private function log_backoff_event($event_type, $message) {
        error_log(sprintf('[MetaSync %s] %s', $event_type, $message));
    }

    /**
     * Get formatted time remaining
     *
     * @param int $seconds Seconds remaining.
     * @return string Formatted time string.
     */
    public static function format_time_remaining($seconds) {
        if ($seconds < 60) {
            return sprintf('%d seconds', $seconds);
        }

        $minutes = floor($seconds / 60);
        $remaining_seconds = $seconds % 60;

        if ($remaining_seconds > 0) {
            return sprintf('%d minutes %d seconds', $minutes, $remaining_seconds);
        }

        return sprintf('%d minutes', $minutes);
    }

    /**
     * Get statistics
     *
     * @return array Statistics data.
     */
    public function get_statistics() {
        $active_backoffs = $this->get_all_active_backoffs();

        return [
            'active_backoffs_count' => count($active_backoffs),
            'active_backoffs' => $active_backoffs,
            'monitored_endpoints' => self::MONITORED_ENDPOINTS,
            'backoff_durations' => self::BACKOFF_DURATIONS,
            'reset_window_seconds' => self::RESET_WINDOW,
        ];
    }
}

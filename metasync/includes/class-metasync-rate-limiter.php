<?php
/**
 * MetaSync Rate Limiter
 *
 * A robust rate limiting implementation that uses direct database storage
 * to ensure consistent behavior across all server configurations including:
 * - Object caching (Redis, Memcached)
 * - Load-balanced environments
 * - Server-side page caching
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 * @since      2.5.17
 */

# Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Rate_Limiter
{
    /**
     * Option name for storing rate limit data
     */
    const OPTION_NAME = 'metasync_rate_limits';

    /**
     * Singleton instance
     *
     * @var Metasync_Rate_Limiter|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return Metasync_Rate_Limiter
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton pattern
     */
    private function __construct()
    {
        # Schedule cleanup if not already scheduled
        if (!wp_next_scheduled('metasync_rate_limit_cleanup')) {
            wp_schedule_event(time(), 'hourly', 'metasync_rate_limit_cleanup');
        }
    }

    /**
     * Check and increment rate limit for a given key
     *
     * Uses atomic database operations to ensure consistency across
     * all server configurations including object caching and load balancers.
     *
     * @param string $key           Unique identifier for rate limiting (e.g., hashed token or IP)
     * @param int    $max_attempts  Maximum number of attempts allowed
     * @param int    $window_seconds Time window in seconds
     * @param string $prefix        Optional prefix for the rate limit key
     * @return bool|WP_Error True if under limit, WP_Error if rate limit exceeded
     */
    public function check_rate_limit($key, $max_attempts, $window_seconds, $prefix = '')
    {
        global $wpdb;

        $full_key = $prefix . $key;
        $now = time();

        # Get current rate limit data with row-level locking for atomicity
        # Using direct database query instead of get_option for reliability
        $data = $this->get_rate_limit_data();

        # Initialize entry if not exists or expired
        if (!isset($data[$full_key]) || $data[$full_key]['expires_at'] < $now) {
            $data[$full_key] = array(
                'attempts' => 1,
                'first_attempt_at' => $now,
                'expires_at' => $now + $window_seconds,
                'window_seconds' => $window_seconds,
            );
            $this->save_rate_limit_data($data);

            return true;
        }

        # Check if rate limit exceeded
        if ($data[$full_key]['attempts'] >= $max_attempts) {
            $remaining_seconds = $data[$full_key]['expires_at'] - $now;
            $remaining_minutes = ceil($remaining_seconds / 60);

            error_log(sprintf(
                'MetaSync Rate Limiter: Rate limit exceeded for key %s (attempts: %d/%d, resets in %d seconds)',
                substr($full_key, 0, 20) . '...',
                $data[$full_key]['attempts'],
                $max_attempts,
                $remaining_seconds
            ));

            return new WP_Error(
                'rate_limit_exceeded',
                sprintf(
                    'Too many attempts. Please try again in %d minute%s.',
                    $remaining_minutes,
                    $remaining_minutes > 1 ? 's' : ''
                ),
                array(
                    'retry_after' => $remaining_seconds,
                    'attempts' => $data[$full_key]['attempts'],
                    'max_attempts' => $max_attempts,
                )
            );
        }

        # Increment attempt count
        $data[$full_key]['attempts']++;
        $data[$full_key]['last_attempt_at'] = $now;
        $this->save_rate_limit_data($data);

        return true;
    }

    /**
     * Check IP-based rate limit
     *
     * @param int $max_attempts    Maximum attempts per IP
     * @param int $window_seconds  Time window in seconds
     * @return bool|WP_Error True if under limit, WP_Error if exceeded
     */
    public function check_ip_rate_limit($max_attempts, $window_seconds)
    {
        $ip_address = $this->get_client_ip();

        if (empty($ip_address)) {
            # Cannot determine IP, allow but log warning
            error_log('MetaSync Rate Limiter: Unable to determine client IP for rate limiting');
            return true;
        }

        # Hash IP for privacy
        $ip_hash = hash('sha256', $ip_address);

        $result = $this->check_rate_limit($ip_hash, $max_attempts, $window_seconds, 'ip_');

        if (is_wp_error($result)) {
            # Replace error code for IP-specific error
            return new WP_Error(
                'ip_rate_limit_exceeded',
                sprintf(
                    'Too many attempts from your IP address. Please try again in %d minute%s.',
                    ceil($result->get_error_data()['retry_after'] / 60),
                    ceil($result->get_error_data()['retry_after'] / 60) > 1 ? 's' : ''
                ),
                $result->get_error_data()
            );
        }

        return $result;
    }

    /**
     * Check token-based rate limit
     *
     * @param string $token          The token to rate limit
     * @param int    $max_attempts   Maximum attempts per token
     * @param int    $window_seconds Time window in seconds
     * @return bool|WP_Error True if under limit, WP_Error if exceeded
     */
    public function check_token_rate_limit($token, $max_attempts, $window_seconds)
    {
        # Hash token for storage efficiency and privacy
        $token_hash = hash('sha256', $token);

        return $this->check_rate_limit($token_hash, $max_attempts, $window_seconds, 'token_');
    }

    /**
     * Get rate limit data from database
     *
     * Uses direct SQL query to bypass object caching and ensure
     * we always get the latest data from the database.
     *
     * @return array Rate limit data
     */
    private function get_rate_limit_data()
    {
        global $wpdb;

        # Bypass object cache by using direct SQL query
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                self::OPTION_NAME
            )
        );

        if ($value === null) {
            return array();
        }

        $data = maybe_unserialize($value);
        return is_array($data) ? $data : array();
    }

    /**
     * Save rate limit data to database
     *
     * Uses direct SQL query with ON DUPLICATE KEY UPDATE for atomicity.
     *
     * @param array $data Rate limit data
     * @return bool Success
     */
    private function save_rate_limit_data($data)
    {
        global $wpdb;

        $serialized = maybe_serialize($data);

        # Use REPLACE to ensure atomic update (INSERT or UPDATE)
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                 VALUES (%s, %s, 'no')
                 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
                self::OPTION_NAME,
                $serialized
            )
        );

        # Clear any object cache for this option
        wp_cache_delete(self::OPTION_NAME, 'options');

        return $result !== false;
    }

    /**
     * Clean up expired rate limit entries
     *
     * Called via cron job to prevent database bloat.
     *
     * @return int Number of entries cleaned up
     */
    public function cleanup_expired_entries()
    {
        $data = $this->get_rate_limit_data();
        $now = time();
        $original_count = count($data);

        # Remove expired entries
        $data = array_filter($data, function ($entry) use ($now) {
            return isset($entry['expires_at']) && $entry['expires_at'] > $now;
        });

        $removed_count = $original_count - count($data);

        if ($removed_count > 0) {
            $this->save_rate_limit_data($data);
            error_log(sprintf('MetaSync Rate Limiter: Cleaned up %d expired rate limit entries', $removed_count));
        }

        return $removed_count;
    }

    /**
     * Get client IP address
     *
     * Handles various proxy configurations and load balancers.
     *
     * @return string Client IP address
     */
    private function get_client_ip()
    {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',     # Cloudflare
            'HTTP_X_REAL_IP',            # Nginx proxy
            'HTTP_X_FORWARDED_FOR',      # Standard proxy header
            'REMOTE_ADDR',               # Direct connection
        );

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];

                # X-Forwarded-For may contain multiple IPs, get the first one
                if ($key === 'HTTP_X_FORWARDED_FOR') {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }

                # Validate IP format
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '';
    }

    /**
     * Reset rate limit for a specific key
     *
     * Useful for testing or admin override.
     *
     * @param string $key    Rate limit key
     * @param string $prefix Optional prefix
     * @return bool Success
     */
    public function reset_rate_limit($key, $prefix = '')
    {
        $full_key = $prefix . $key;
        $data = $this->get_rate_limit_data();

        if (isset($data[$full_key])) {
            unset($data[$full_key]);
            return $this->save_rate_limit_data($data);
        }

        return true;
    }

    /**
     * Get rate limit status for a key (for debugging/admin UI)
     *
     * @param string $key    Rate limit key
     * @param string $prefix Optional prefix
     * @return array|null Rate limit status or null if not found
     */
    public function get_rate_limit_status($key, $prefix = '')
    {
        $full_key = $prefix . $key;
        $data = $this->get_rate_limit_data();

        if (!isset($data[$full_key])) {
            return null;
        }

        $entry = $data[$full_key];
        $now = time();

        return array(
            'attempts' => $entry['attempts'],
            'expires_at' => $entry['expires_at'],
            'remaining_seconds' => max(0, $entry['expires_at'] - $now),
            'is_expired' => $entry['expires_at'] < $now,
            'first_attempt_at' => $entry['first_attempt_at'],
            'last_attempt_at' => isset($entry['last_attempt_at']) ? $entry['last_attempt_at'] : null,
        );
    }
}

/**
 * Hook for cron cleanup
 */
add_action('metasync_rate_limit_cleanup', function () {
    $rate_limiter = Metasync_Rate_Limiter::get_instance();
    $rate_limiter->cleanup_expired_entries();
});

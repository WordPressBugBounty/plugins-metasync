<?php
/**
 * MetaSync Rate Limiter
 *
 * A robust rate limiting implementation that uses per-key WordPress
 * transients so each IP/token hash is stored as its own row with a
 * TTL matching the rate-limit window. This avoids the contention,
 * race conditions, and unbounded growth of a single serialized blob.
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
     * Prefix for per-key rate limit transients.
     */
    const TRANSIENT_PREFIX = 'metasync_rl_';

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
        # Cancel any previously scheduled hourly cleanup job — transient
        # expiry handles cleanup automatically now.
        wp_clear_scheduled_hook('metasync_rate_limit_cleanup');
    }

    /**
     * Build the transient key for a given full rate-limit key.
     *
     * Hashing keeps the resulting transient name comfortably under
     * WordPress's 172-char limit regardless of the input length.
     *
     * @param string $full_key Full rate-limit key (prefix + key).
     * @return string Transient name.
     */
    private function get_transient_key($full_key)
    {
        return self::TRANSIENT_PREFIX . substr(hash('sha256', $full_key), 0, 40);
    }

    /**
     * Check and increment rate limit for a given key.
     *
     * Each key is tracked in its own transient, so concurrent
     * requests for different keys never share a write lock.
     *
     * @param string $key            Unique identifier for rate limiting (e.g., hashed token or IP)
     * @param int    $max_attempts   Maximum number of attempts allowed
     * @param int    $window_seconds Time window in seconds
     * @param string $prefix         Optional prefix for the rate limit key
     * @return bool|WP_Error True if under limit, WP_Error if rate limit exceeded
     */
    public function check_rate_limit($key, $max_attempts, $window_seconds, $prefix = '')
    {
        $full_key       = $prefix . $key;
        $transient_key  = $this->get_transient_key($full_key);
        $now            = time();

        $data = get_transient($transient_key);

        # Initialize entry if not present or expired
        if ($data === false || !is_array($data) || !isset($data['expires_at']) || $data['expires_at'] < $now) {
            $data = array(
                'attempts'         => 1,
                'first_attempt_at' => $now,
                'expires_at'       => $now + $window_seconds,
                'window_seconds'   => $window_seconds,
            );
            set_transient($transient_key, $data, $window_seconds);

            return true;
        }

        # Check if rate limit exceeded
        if ($data['attempts'] >= $max_attempts) {
            $remaining_seconds = $data['expires_at'] - $now;
            $remaining_minutes = ceil($remaining_seconds / 60);

            return new WP_Error(
                'rate_limit_exceeded',
                sprintf(
                    'Too many attempts. Please try again in %d minute%s.',
                    $remaining_minutes,
                    $remaining_minutes > 1 ? 's' : ''
                ),
                array(
                    'retry_after' => $remaining_seconds,
                    'attempts'    => $data['attempts'],
                    'max_attempts' => $max_attempts,
                )
            );
        }

        # Increment attempt count, preserving the original window end
        $data['attempts']++;
        $data['last_attempt_at'] = $now;
        set_transient($transient_key, $data, max(1, $data['expires_at'] - $now));

        return true;
    }

    /**
     * Check IP-based rate limit
     *
     * @param int $max_attempts    Maximum attempts per IP
     * @param int $window_seconds  Time window in seconds
     * @return bool|WP_Error True if under limit, WP_Error if exceeded
     */

    /**
     * Check token-based rate limit
     *
     * @param string $token          The token to rate limit
     * @param int    $max_attempts   Maximum attempts per token
     * @param int    $window_seconds Time window in seconds
     * @return bool|WP_Error True if under limit, WP_Error if exceeded
     */

    /**
     * Get client IP address
     *
     * Handles various proxy configurations and load balancers.
     *
     * @return string Client IP address
     */

    /**
     * Reset rate limit for a specific key
     *
     * Useful for testing or admin override.
     *
     * @param string $key    Rate limit key
     * @param string $prefix Optional prefix
     * @return bool Success
     */

    /**
     * Get rate limit status for a key (for debugging/admin UI)
     *
     * @param string $key    Rate limit key
     * @param string $prefix Optional prefix
     * @return array|null Rate limit status or null if not found
     */
}

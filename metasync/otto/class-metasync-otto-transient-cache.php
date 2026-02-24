<?php
/**
 * OTTO Transient Cache Manager
 * Implements Option 1: On-Demand Transient Caching with mitigations
 * 
 * Features:
 * - Transient-based caching (30 min TTL)
 * - Rate limiting (max 10 API calls per minute)
 * - Request locking (prevents thundering herd)
 * - API timeout with fallback
 * - Stale cache fallback on API failure
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Otto_Transient_Cache {

    /**
     * Transient prefix
     */
    private const TRANSIENT_PREFIX = 'otto_suggestions_';
    
    /**
     * Lock transient prefix
     */
    private const LOCK_PREFIX = 'otto_lock_';
    
    /**
     * Rate limit transient prefix
     */
    private const RATE_LIMIT_PREFIX = 'otto_api_rate_';
    
    /**
     * Stale cache prefix (for fallback)
     */
    private const STALE_PREFIX = 'otto_stale_';
    
    /**
     * Default TTL for suggestions (30 minutes)
     */
    private const SUGGESTIONS_TTL = 30 * MINUTE_IN_SECONDS;
    
    /**
     * TTL for "no suggestions" cache (5 minutes)
     */
    private const NO_SUGGESTIONS_TTL = 5 * MINUTE_IN_SECONDS;
    
    /**
     * Lock timeout (5 seconds)
     */
    private const LOCK_TIMEOUT = 5;
    
    /**
     * Max API calls per minute
     */
    private const MAX_API_CALLS_PER_MINUTE = 10;
    
    /**
     * API timeout (2 seconds)
     */
    private const API_TIMEOUT = 2;
    
    /**
     * OTTO UUID
     */
    private $otto_uuid;
    
    /**
     * OTTO API endpoint
     */
    private $api_endpoint;

    /**
     * Constructor
     */
    public function __construct($otto_uuid) {
        $this->otto_uuid = $otto_uuid;

        # Use endpoint manager if available, otherwise fallback to production
        if (class_exists('Metasync_Endpoint_Manager')) {
            $this->api_endpoint = Metasync_Endpoint_Manager::get_endpoint('OTTO_URL_DETAILS');
        } else {
            $this->api_endpoint = 'https://sa.searchatlas.com/api/v2/otto-url-details';
        }
    }
    
    /**
     * Cache status tracker (for header)
     */
    private static $cache_status = [];
    
    /**
     * Get OTTO suggestions for a URL (with transient caching)
     * 
     * @param string $url The page URL
     * @param string $track_key Optional key to track cache status for this request
     * @return array|false OTTO suggestions data or false if none
     */
    public function get_suggestions($url, $track_key = null) {
        if (empty($url) || empty($this->otto_uuid)) {
            return false;
        }

        # PERFORMANCE OPTIMIZATION: Generate all cache keys once
        $keys = $this->get_cache_keys($url);
        $cache_status_key = $track_key ?: $keys['track'];

        # Step 1: Check transient cache first
        $cached = get_transient($keys['transient']);
        if ($cached !== false) {
            # Cache hit - return cached data
            self::$cache_status[$cache_status_key] = 'HIT';
            return $cached;
        }

        # Step 2: Check if another process is fetching (lock)
        if (get_transient($keys['lock']) !== false) {
            # Another process is fetching - wait briefly and retry
            usleep(500000); // 0.5 seconds
            $cached = get_transient($keys['transient']);
            if ($cached !== false) {
                self::$cache_status[$cache_status_key] = 'HIT'; // Got it from other process
                return $cached;
            }
            # Still no cache - proceed with our own fetch
        }

        # Step 3: Check rate limit
        if (!$this->can_make_api_call()) {
            # Rate limited - try to use stale cache
            $stale = get_transient($keys['stale']);
            if ($stale !== false) {
                self::$cache_status[$cache_status_key] = 'STALE';
                return $stale;
            }
            # No stale cache available - return false
            self::$cache_status[$cache_status_key] = 'RATE_LIMITED';
            return false;
        }

        # Step 4: Acquire lock
        set_transient($keys['lock'], true, self::LOCK_TIMEOUT);

        # Step 5: Fetch from API (with timeout and error handling)
        $suggestions = $this->fetch_from_api($url);

        # This ensures NitroPack will regenerate cache with fresh suggestions (e.g., after 30min transient expiry)
        if ($suggestions !== false) {
            $this->purge_nitropack_cache($url);
        }

        # Step 6: Store result in transient
        if ($suggestions && $this->has_payload($suggestions)) {
            # Has suggestions - cache for 30 minutes
            set_transient($keys['transient'], $suggestions, self::SUGGESTIONS_TTL);
            # Also store as stale cache for fallback
            set_transient($keys['stale'], $suggestions, self::SUGGESTIONS_TTL * 2);
            self::$cache_status[$cache_status_key] = 'MISS'; // Cache miss, fetched from API
        } else {
            # No suggestions - cache negative result for shorter time
            set_transient($keys['transient'], false, self::NO_SUGGESTIONS_TTL);
            self::$cache_status[$cache_status_key] = 'NO_SUGGESTIONS';
        }

        # Step 7: Release lock
        delete_transient($keys['lock']);

        return $suggestions;
    }
    
    /**
     * Get cache status for a request
     * 
     * @param string $key The tracking key
     * @return string Cache status (HIT, MISS, STALE, RATE_LIMITED, NO_SUGGESTIONS)
     */
    public static function get_cache_status($key) {
        return self::$cache_status[$key] ?? 'UNKNOWN';
    }
    
    /**
     * Check if URL has OTTO suggestions (quick check)
     * 
     * @param string $url The page URL
     * @return bool True if URL has suggestions
     */
    public function has_suggestions($url) {
        $suggestions = $this->get_suggestions($url);
        return $suggestions !== false && $this->has_payload($suggestions);
    }
    
    /**
     * Invalidate cache for a URL
     *
     * @param string $url The page URL
     * @return bool Success
     */
    public function invalidate($url) {
        # PERFORMANCE OPTIMIZATION: Generate all cache keys once
        $keys = $this->get_cache_keys($url);

        delete_transient($keys['transient']);
        delete_transient($keys['stale']);
        delete_transient($keys['lock']);

        return true;
    }
    
    /**
     * Warm cache for a URL (pre-fetch and cache)
     * Useful when notification is received
     * 
     * @param string $url The page URL
     * @return array|false Suggestions or false
     */
    public function warm_cache($url) {
        # Invalidate existing cache first
        $this->invalidate($url);
        
        # Fetch and cache
        return $this->get_suggestions($url);
    }
    
    /**
     * Fetch suggestions from OTTO API
     * 
     * @param string $url The page URL
     * @return array|false API response or false on failure
     */
    private function fetch_from_api($url) {
        # Construct API URL
        $api_url = add_query_arg(
            [
                'url' => $url,
                'uuid' => $this->otto_uuid,
            ],
            $this->api_endpoint
        );
        
        # Make API request with short timeout
        $response = wp_remote_get($api_url, [
            'timeout' => self::API_TIMEOUT,
            'redirection' => 5,
            'user-agent' => 'MetaSync-OTTO-Transient-Cache/1.0',
            'sslverify' => true,
        ]);
        
        # Check for errors
        if (is_wp_error($response)) {
            return false;
        }
        
        # Check response code
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return false;
        }
        
        # Parse response body
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return false;
        }
        
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        
        return $data;
    }
    
    /**
    * Purge NitroPack cache for a specific URL
    * Called when OTTO API returns new suggestions to ensure NitroPack regenerates cache with fresh content 
    * @param string $url The page URL to purge from NitroPack cache
    * @return bool True if purge was attempted, false if NitroPack not available
    */
    private function purge_nitropack_cache($url) {
        # Check if NitroPack functions are available
        if (!function_exists('nitropack_sdk_purge')) {
            return false;
        }
        
        try {
            # Purge NitroPack's cache (both local and remote) for this URL
            # Using nitropack_sdk_purge() ensures remote API cache is also cleared
            return nitropack_sdk_purge($url, NULL, 'MetaSync OTTO API call - fresh suggestions fetched');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if API response has payload (suggestions)
     * Public method for external use
     * 
     * @param array $data API response data
     * @return bool True if has suggestions
     */
    public function has_payload($data) {
        if (empty($data) || !is_array($data)) {
            return false;
        }
        
        return !empty($data['header_replacements']) ||
               !empty($data['header_html_insertion']) ||
               !empty($data['body_substitutions']) ||
               !empty($data['body_top_html_insertion']) ||
               !empty($data['body_bottom_html_insertion']) ||
               !empty($data['footer_html_insertion']);
    }
    
    /**
     * Check if we can make an API call (rate limiting)
     * 
     * @return bool True if under rate limit
     */
    private function can_make_api_call() {
        # Create rate limit key (per minute)
        $rate_key = self::RATE_LIMIT_PREFIX . date('Y-m-d-H-i');
        
        # Get current count
        $count = (int) get_transient($rate_key);
        
        # Check if under limit
        if ($count >= self::MAX_API_CALLS_PER_MINUTE) {
            return false;
        }
        
        # Increment counter
        set_transient($rate_key, $count + 1, MINUTE_IN_SECONDS);
        
        return true;
    }
    
    /**
     * PERFORMANCE OPTIMIZATION: Generate all cache keys at once
     * Reduces redundant URL normalization and MD5 hashing
     *
     * @param string $url The page URL
     * @return array All cache keys ['transient', 'lock', 'stale', 'track']
     */
    private function get_cache_keys($url) {
        # Normalize URL once (remove trailing slash, lowercase)
        $normalized = rtrim(strtolower($url), '/');
        # Compute MD5 hash once
        $hash = md5($normalized);
        # Get site ID once
        $site_id = is_multisite() ? get_current_blog_id() : 0;

        return [
            'transient' => self::TRANSIENT_PREFIX . $site_id . '_' . $hash,
            'lock'      => self::LOCK_PREFIX . $hash,
            'stale'     => self::STALE_PREFIX . $hash,
            'track'     => 'otto_' . $hash,
        ];
    }

    /**
     * Get transient key for URL
     *
     * @param string $url The page URL
     * @return string Transient key
     */
    private function get_transient_key($url) {
        $keys = $this->get_cache_keys($url);
        return $keys['transient'];
    }

    /**
     * Get lock key for URL
     *
     * @param string $url The page URL
     * @return string Lock key
     */
    private function get_lock_key($url) {
        $keys = $this->get_cache_keys($url);
        return $keys['lock'];
    }

    /**
     * Get stale cache key for URL
     *
     * @param string $url The page URL
     * @return string Stale cache key
     */
    private function get_stale_key($url) {
        $keys = $this->get_cache_keys($url);
        return $keys['stale'];
    }
    
    /**
     * Get cache statistics for debugging
     * 
     * @param string $url The page URL
     * @return array Statistics
     */
    public function get_stats($url) {
        $transient_key = $this->get_transient_key($url);
        $cached = get_transient($transient_key);
        
        return [
            'url' => $url,
            'has_cache' => $cached !== false,
            'has_suggestions' => $cached !== false && $this->has_payload($cached),
            'cache_key' => $transient_key,
            'rate_limit_key' => self::RATE_LIMIT_PREFIX . date('Y-m-d-H-i'),
            'current_rate_count' => (int) get_transient(self::RATE_LIMIT_PREFIX . date('Y-m-d-H-i')),
        ];
    }
    
    /**
     * Clear all OTTO transient caches
     * 
     * @return array Results with count of cleared items
     */
    public static function clear_all_transients() {
        global $wpdb;

        # PERFORMANCE OPTIMIZATION: Batch delete in single query instead of N+1 loop
        # Build the LIKE conditions for all prefixes
        $prefixes = [
            self::TRANSIENT_PREFIX,
            self::LOCK_PREFIX,
            self::STALE_PREFIX,
            self::RATE_LIMIT_PREFIX,
        ];

        # Build WHERE clause for all transient and timeout variants
        $where_parts = [];
        foreach ($prefixes as $prefix) {
            $where_parts[] = $wpdb->prepare("option_name LIKE %s", '_transient_' . $prefix . '%');
            $where_parts[] = $wpdb->prepare("option_name LIKE %s", '_transient_timeout_' . $prefix . '%');
        }
        $where_clause = implode(' OR ', $where_parts);

        # Count entries before deletion
        $count_query = "SELECT COUNT(*) FROM {$wpdb->options} WHERE " . $where_clause;
        $cleared_count = (int) $wpdb->get_var($count_query);

        # Batch delete all matching transients in single query
        $delete_query = "DELETE FROM {$wpdb->options} WHERE " . $where_clause;
        $wpdb->query($delete_query);

        # Also clear object cache if available
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('transient');
        }

        return [
            'success' => true,
            'cleared_count' => $cleared_count,
            'message' => sprintf('Cleared %d OTTO transient cache entries', $cleared_count)
        ];
    }
    
    /**
     * Clear transient cache for a specific URL
     * 
     * @param string $url The page URL
     * @return array Results
     */
    public static function clear_url_transient($url) {
        if (empty($url)) {
            return [
                'success' => false,
                'message' => 'URL is required'
            ];
        }
        
        # Normalize URL for transient key (matches get_transient_key)
        $normalized = rtrim(strtolower($url), '/');
        $site_id = is_multisite() ? get_current_blog_id() : 0;
        
        # Generate keys matching the actual key generation methods
        # Note: lock and stale keys use md5($url) directly (no normalization)
        # while transient key uses normalized URL
        $transient_key = self::TRANSIENT_PREFIX . $site_id . '_' . md5($normalized);
        $lock_key = self::LOCK_PREFIX . md5($url); // No normalization for lock
        $stale_key = self::STALE_PREFIX . md5($url); // No normalization for stale
        
        $keys_to_clear = [$transient_key, $lock_key, $stale_key];
        
        $cleared_count = 0;
        foreach ($keys_to_clear as $key) {
            if (delete_transient($key)) {
                $cleared_count++;
            }
        }
        
        return [
            'success' => true,
            'cleared_count' => $cleared_count,
            'url' => $url,
            'message' => sprintf('Cleared cache for URL: %s (%d entries)', $url, $cleared_count)
        ];
    }
    
    /**
     * Get count of cached transients
     * 
     * @return int Number of cached transients
     */
    public static function get_cache_count() {
        global $wpdb;
        
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} 
                 WHERE option_name LIKE %s",
                '_transient_' . self::TRANSIENT_PREFIX . '%'
            )
        );
        
        return (int) $count;
    }
}


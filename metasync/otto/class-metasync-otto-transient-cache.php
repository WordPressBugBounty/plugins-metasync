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
    public const SUGGESTIONS_TTL = 30 * MINUTE_IN_SECONDS;
    
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
    public const MAX_API_CALLS_PER_MINUTE = 10;
    
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
     * API fetch timeout for the current operation.
     * Page-load requests use the short API_TIMEOUT (2s).
     * warm_cache() uses a longer timeout since it runs in a webhook handler.
     */
    private $fetch_timeout = self::API_TIMEOUT;

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

        # Step 2: Check rate limit
        if (!$this->can_make_api_call()) {
            # Rate limited - try to use stale cache
            $stale = get_transient($keys['stale']);
            if ($stale !== false) {
                # NEW: Structured error logging with category and code
                if (class_exists('Metasync_Error_Logger')) {
                    Metasync_Error_Logger::log(
                        Metasync_Error_Logger::CATEGORY_API_RATE_LIMIT,
                        Metasync_Error_Logger::SEVERITY_INFO,
                        'OTTO API rate limited - using stale cache',
                        [
                            'url' => $url,
                            'fallback' => 'stale_cache',
                            'api_endpoint' => 'OTTO Suggestions API',
                            'operation' => 'get_suggestions'
                        ]
                    );
                }
                
                error_log('MetaSync OTTO: Rate limited, using stale cache for ' . $url);
                self::$cache_status[$cache_status_key] = 'STALE';
                return $stale;
            }
            # No stale cache available - return false
            self::$cache_status[$cache_status_key] = 'RATE_LIMITED';
            return false;
        }

        # Step 3: Acquire lock atomically (test-and-set).
        # If another worker already holds the lock, wait briefly for them to populate
        # the cache, then either return their result or bail out — DO NOT fall through
        # to fetch_from_api() (that was the original bug: duplicate concurrent API calls).
        if (!$this->acquire_lock_atomic($keys['lock'], self::LOCK_TIMEOUT)) {
            usleep(500000); // 0.5 seconds
            $cached = get_transient($keys['transient']);
            if ($cached !== false) {
                self::$cache_status[$cache_status_key] = 'HIT';
                return $cached;
            }
            self::$cache_status[$cache_status_key] = 'LOCKED';
            return false;
        }

        $suggestions = false;
        try {
            # Step 4: Fetch from API (with timeout and error handling)
            $suggestions = $this->fetch_from_api($url);

            # NitroPack purge removed from page-load path to prevent feedback loop:
            # get_suggestions() runs on every visitor request when the transient expires,
            # and purging NitroPack here causes it to flush its cache (and potentially the
            # object cache holding our transients), triggering another API fetch → purge cycle.
            # The webhook handler (otto_pixel.php → purge_single_url) already covers the
            # legitimate case where suggestions change after an OTTO crawl.

            # Step 5: Store result in transient
            if ($suggestions && $this->has_payload($suggestions)) {
                # Has suggestions - cache for 30 minutes
                set_transient($keys['transient'], $suggestions, self::SUGGESTIONS_TTL);
                # Also store as stale cache for fallback
                set_transient($keys['stale'], $suggestions, self::SUGGESTIONS_TTL * 2);
                self::$cache_status[$cache_status_key] = 'MISS'; // Cache miss, fetched from API
            } elseif ($suggestions !== false) {
                # API responded 200 OK but genuinely no OTTO suggestions for this URL.
                # Cache the negative result for a short time to prevent hammering the API.
                set_transient($keys['transient'], false, self::NO_SUGGESTIONS_TTL);
                # WP-361: Wipe the stale fallback too. Without this, a rate-limit
                # event within the next 60 min serves the previously-deployed (now
                # undeployed) suggestion, keeping the wrong title alive on the page.
                delete_transient($keys['stale']);
                self::$cache_status[$cache_status_key] = 'NO_SUGGESTIONS';
            } else {
                # fetch_from_api() returned false — network error, timeout, or non-200 response.
                # Do NOT cache the failure: a stale false would poison subsequent MISS requests
                # (Kinsta sees MISS → PHP runs → transient HIT = false → OTTO skips → old title cached).
                # Leave the transient empty so the very next page load retries the API call.
                self::$cache_status[$cache_status_key] = 'API_ERROR';
            }
        } finally {
            # Step 6: Release lock — guaranteed to run even if fetch_from_api() throws
            # or the storage block errors, so we never leak the lock for the full TTL.
            # Note: acquire_lock_atomic() uses raw SQL (INSERT IGNORE / CAS UPDATE) on
            # the MySQL path, but delete_transient() via the WP API is safe here because
            # the rows use autoload='no' and are not in the alloptions cache.
            delete_transient($keys['lock']);
        }

        return $suggestions;
    }
    
    /**
     * Get cache status for a request
     * 
     * @param string $key The tracking key
     * @return string Cache status (HIT, MISS, STALE, RATE_LIMITED, NO_SUGGESTIONS, LOCKED, UNKNOWN)
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
     * Called from the OTTO webhook handler — NOT from a page load.
     * Uses a longer API timeout so transients are reliably populated before
     * the Kinsta/WP Engine cache purge fires, preventing the "false transient
     * poisoning" race condition (MISS → false transient HIT → OTTO skips →
     * old Yoast title cached).
     *
     * @param string $url The page URL
     * @return array|false Suggestions or false
     */
    public function warm_cache($url) {
        # Invalidate existing cache first
        $this->invalidate($url);

        # Use a longer timeout for this pre-warm request (webhook context, not page load).
        $saved_timeout = $this->fetch_timeout;
        $this->fetch_timeout = 8; // 8 seconds — enough for cross-region API calls

        # Fetch and cache (with the longer timeout)
        $result = $this->get_suggestions($url);

        # Restore original timeout
        $this->fetch_timeout = $saved_timeout;

        return $result;
    }
    
    /**
     * Fetch suggestions from OTTO API
     *
     * @param string $url The page URL
     * @return array|false API response or false on failure
     */
    private function fetch_from_api($url) {
        # Check if endpoint is in backoff mode (explicit check for better error handling)
        if (class_exists('Metasync_API_Backoff_Manager')) {
            $backoff_manager = Metasync_API_Backoff_Manager::get_instance();
            if ($backoff_manager->is_endpoint_in_backoff($this->api_endpoint)) {
                error_log('MetaSync OTTO: API call skipped - endpoint in backoff mode');
                # Try to use stale cache if available
                $stale = get_transient($this->get_stale_key($url));
                if ($stale !== false) {
                    error_log('MetaSync OTTO: Using stale cache due to backoff');
                    return $stale;
                }
                return false;
            }
        }

        # Construct API URL
        $api_url = add_query_arg(
            [
                'url' => $url,
                'uuid' => $this->otto_uuid,
            ],
            $this->api_endpoint
        );

        # Make API request — timeout comes from $this->fetch_timeout so warm_cache()
        # can override it without affecting page-load requests.
        $response = wp_remote_get($api_url, [
            'timeout' => $this->fetch_timeout,
            'redirection' => 5,
            'user-agent' => 'MetaSync-OTTO-Transient-Cache/1.0',
            'sslverify' => true,
        ]);

        # Check for errors
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();

            # Check if error is due to backoff
            if ($response->get_error_code() === 'api_backoff_active') {
                error_log('MetaSync OTTO: API call blocked by backoff - ' . $error_message);
                # Try to use stale cache
                $stale = get_transient($this->get_stale_key($url));
                if ($stale !== false) {
                    error_log('MetaSync OTTO: Using stale cache due to backoff');
                    return $stale;
                }
            } else {
                error_log('MetaSync OTTO: API call failed for ' . $url . ' - ' . $error_message);
            }
            return false;
        }

        # Check response code
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            # For 429/503, the backoff manager will handle it automatically
            # Try to use stale cache for these errors
            if (in_array($response_code, [429, 503], true)) {
                $stale = get_transient($this->get_stale_key($url));
                if ($stale !== false) {
                    error_log('MetaSync OTTO: Using stale cache due to rate limit/service unavailable');
                    return $stale;
                }
            }
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

        # WP-361: Undeployed OTTO responses ship harmless whitespace ("\n") and
        # empty wrappers ({"images": {}}) that PHP's empty() reads as non-empty.
        # Without these guards, has_payload() flagged undeployed pages as "live"
        # and downstream code (otto_has_live_suggestions(), title filter) kept
        # applying the stale _metasync_otto_title meta from the previous deploy.

        $has_string = static function ($value) {
            return is_string($value) && trim($value) !== '';
        };

        $has_container = static function ($value) {
            if (!is_array($value) || empty($value)) {
                return false;
            }
            foreach ($value as $child) {
                if (!empty($child)) {
                    return true;
                }
            }
            return false;
        };

        if (!empty($data['header_replacements']) && is_array($data['header_replacements'])) {
            foreach ($data['header_replacements'] as $replacement) {
                if (is_array($replacement) && !empty($replacement['recommended_value'])) {
                    return true;
                }
            }
        }

        return $has_string($data['header_html_insertion'] ?? '')
            || $has_container($data['body_substitutions'] ?? [])
            || $has_string($data['body_top_html_insertion'] ?? '')
            || $has_string($data['body_bottom_html_insertion'] ?? '')
            || $has_string($data['footer_html_insertion'] ?? '')
            || $has_container($data['image_missing_alt'] ?? []);
    }
    
    /**
     * Get OTTO API rate limit from execution settings
     * Falls back to default constant if setting not configured
     * 
     * @return int Rate limit (calls per minute)
     */
    private function get_rate_limit() {
        $execution_settings = get_option('metasync_execution_settings', array());
        if (isset($execution_settings['otto_rate_limit'])) {
            return (int) $execution_settings['otto_rate_limit'];
        }
        // Fallback to default constant
        return self::MAX_API_CALLS_PER_MINUTE;
    }
    
    /**
     * Check if we can make an API call (rate limiting)
     *
     * @return bool True if under rate limit
     * @note With a persistent object cache (Redis/Memcached) the increment is atomic and race-free.
     *       Without one, falls back to transients (minor TOCTOU race under concurrency, but the counter persists across requests).
     */
    private function can_make_api_call(): bool {
        # Scope rate-limit key per site so each multisite subsite has its own
        # 10 req/min budget. Without this, one subsite's traffic spike
        # exhausts the network-wide bucket and silently rate-limits every
        # other subsite for the rest of the minute. WP-293.
        $site_id = is_multisite() ? get_current_blog_id() : 0;
        # Create rate limit key (per site, per minute)
        $rate_key = self::RATE_LIMIT_PREFIX . $site_id . '_' . date('Y-m-d-H-i');

        # Get rate limit from execution settings
        $rate_limit = $this->get_rate_limit();

        if (wp_using_ext_object_cache()) {
            # Atomic path — race-free on Redis/Memcached
            wp_cache_add($rate_key, 0, 'otto_rate', MINUTE_IN_SECONDS);
            $new_count = wp_cache_incr($rate_key, 1, 'otto_rate');
            return $new_count <= $rate_limit;
        }

        # Transient fallback — persists across requests, minor race under concurrency
        $count = (int) get_transient($rate_key);
        if ($count >= $rate_limit) {
            return false;
        }
        set_transient($rate_key, $count + 1, MINUTE_IN_SECONDS);
        return true;
    }

    /**
     * Atomically acquire a lock (test-and-set).
     *
     * On Redis/Memcached object cache backends, wp_cache_add() maps to SET NX —
     * the kernel guarantees only one caller succeeds. On the MySQL transient
     * backend we INSERT IGNORE the timeout and value rows; the unique key on
     * option_name makes that atomic. For an expired-but-not-yet-deleted lock
     * we use a compare-and-swap UPDATE so only one racing worker can claim it.
     *
     * @param string $lock_key Transient key for the lock (without _transient_ prefix)
     * @param int    $ttl      Lock timeout in seconds
     * @return bool True if this caller acquired the lock, false if it was already held
     */
    private function acquire_lock_atomic($lock_key, $ttl) {
        # Fast path: external object cache (Redis/Memcached) — wp_cache_add is atomic.
        if (wp_using_ext_object_cache()) {
            return (bool) wp_cache_add($lock_key, '1', 'transient', $ttl);
        }

        # MySQL transient backend — emulate compare-and-set with INSERT IGNORE on the
        # _transient_timeout_ row (which carries the unique option_name constraint).
        global $wpdb;
        $now             = time();
        $new_expires     = $now + (int) $ttl;
        $timeout_option  = '_transient_timeout_' . $lock_key;
        $value_option    = '_transient_' . $lock_key;

        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $timeout_option,
                (string) $new_expires
            )
        );

        if ($inserted === 1) {
            # We won the insert race — now write the value row. INSERT IGNORE keeps it
            # safe if a stale value row from a prior expired lock is still around.
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                    $value_option,
                    '1'
                )
            );
            return true;
        }

        # Insert was ignored — a timeout row already exists. Check whether the
        # existing lock has expired so we can attempt to take it over.
        $existing_expires = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $timeout_option
            )
        );

        if ($existing_expires > $now) {
            # Lock is still active — we did not acquire.
            return false;
        }

        # Stale lock — try to claim it via compare-and-swap on the timeout column.
        # Only the worker whose UPDATE actually changes a row wins the race.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) = %d",
                (string) $new_expires,
                $timeout_option,
                $existing_expires
            )
        );

        if ($updated === 1) {
            # We won the takeover. Make sure the value row exists.
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                    $value_option,
                    '1'
                )
            );
            return true;
        }

        return false;
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

        # All keys scoped by $site_id so multisite subsites don't collide on a
        # shared Redis/Memcached object cache. WP-293.
        return [
            'transient' => self::TRANSIENT_PREFIX . $site_id . '_' . $hash,
            'lock'      => self::LOCK_PREFIX . $site_id . '_' . $hash,
            'stale'     => self::STALE_PREFIX . $site_id . '_' . $hash,
            'track'     => 'otto_' . $site_id . '_' . $hash,
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

        # Match the scoped rate-limit key produced by can_make_api_call(). WP-293.
        $site_id = is_multisite() ? get_current_blog_id() : 0;
        $rate_limit_key = self::RATE_LIMIT_PREFIX . $site_id . '_' . date('Y-m-d-H-i');

        return [
            'url' => $url,
            'has_cache' => $cached !== false,
            'has_suggestions' => $cached !== false && $this->has_payload($cached),
            'cache_key' => $transient_key,
            'rate_limit_key' => $rate_limit_key,
            'current_rate_count' => wp_using_ext_object_cache()
                ? (int) wp_cache_get($rate_limit_key, 'otto_rate')
                : (int) get_transient($rate_limit_key),
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
            wp_cache_flush_group('otto_rate');
        }

        delete_transient('metasync_otto_js_detected');

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
        
        # Use the same normalization as get_cache_keys() for all three keys.
        # Previously lock/stale used raw $url (no normalization), which generated
        # different hashes than the actual keys — they were never actually deleted.
        $hash = md5($normalized);
        # All three keys must include $site_id to match get_cache_keys(). WP-293.
        $transient_key = self::TRANSIENT_PREFIX . $site_id . '_' . $hash;
        $lock_key      = self::LOCK_PREFIX . $site_id . '_' . $hash;
        $stale_key     = self::STALE_PREFIX . $site_id . '_' . $hash;
        
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


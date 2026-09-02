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
     * Stale fallback retention (12 hours), independent of the live cache TTL.
     */
    private const STALE_TTL = 12 * HOUR_IN_SECONDS;
    
    /**
     * TTL for "no suggestions" cache (5 minutes)
     */
    private const NO_SUGGESTIONS_TTL = 5 * MINUTE_IN_SECONDS;
    
    /**
     * Lock timeout (5 seconds)
     */
    private const LOCK_TIMEOUT = 5;

    /**
     * Recovery probe lock timeout (10 seconds).
     * Must exceed warm_cache()'s longest API timeout (8 seconds), so a slow
     * recovery probe cannot overlap with a second probe.
     */
    private const BREAKER_PROBE_LOCK_TIMEOUT = 10;
    
    /**
     * Circuit breaker prefix (per API host, not per URL — the failure being
     * tracked is "the OTTO API is unreachable", which is host-wide)
     */
    private const BREAKER_PREFIX = 'otto_breaker_';

    /**
     * Circuit breaker consecutive-failure tally prefix
     */
    private const BREAKER_FAIL_PREFIX = 'otto_breaker_fail_';

    /**
     * Circuit breaker probe-lock prefix (half-open state)
     */
    private const BREAKER_PROBE_PREFIX = 'otto_breaker_probe_';

    /**
     * Object-cache group for atomic counters (rate limiter, breaker tally).
     * These are NOT stored in the transient namespace when an external object
     * cache is present, so they must always be read and deleted via
     * counter_get() / counter_delete().
     */
    private const COUNTER_GROUP = 'otto_counters';

    /**
     * Consecutive hard failures before the breaker opens.
     */
    private const BREAKER_FAILURE_THRESHOLD = 3;

    /**
     * How long the breaker stays open before allowing a single probe (seconds).
     * Deliberately short: long enough to shed load off the PHP workers, short
     * enough that a brief upstream blip does not visibly de-OTTO the site.
     */
    private const BREAKER_COOLOFF = 60;

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
     * Whether the last fetch_from_api() call was answered with HTTP 404.
     *
     * Tracked explicitly rather than inferred from the returned value: a 404 is
     * represented as an empty array, but json_decode() also yields an empty array
     * for a 200 body of `{}` or `[]`. Distinguishing the two by emptiness would
     * silently misclassify such a response as "no data held" and stop clearing the
     * stale copy — resurfacing a withdrawn title, which is the bug the stale wipe
     * exists to prevent.
     *
     * @var bool
     */
    private $last_fetch_was_404 = false;

    /**
     * Whether the last fetch_from_api() failure was permanent (a 4xx refusal
     * such as 400/401/403, i.e. an answer retrying cannot change).
     *
     * Like the 404 flag above, this is tracked explicitly because the return
     * value cannot carry it: warm failures return plain false whether the API
     * timed out (retryable) or rejected the request outright (permanent).
     * Callers that own a retry policy — the crawl-notify job — read this to
     * fail fast instead of burning the full attempt budget on rejections.
     *
     * @var bool
     */
    private $last_failure_permanent = false;

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
            # Cache hit. Distinguish a real payload from the "checked, nothing
            # here" marker so the X-MetaSync-OTTO-Cache header stays truthful —
            # reporting HIT for a URL OTTO holds no data for would be misleading
            # when debugging, and the two cases are handled differently upstream.
            self::$cache_status[$cache_status_key] = $this->has_payload($cached)
                ? 'HIT'
                : 'NO_SUGGESTIONS';
            return $cached;
        }

        # Step 1.5: circuit breaker.
        #
        # Checked BEFORE the rate limiter on purpose. can_make_api_call() increments
        # a per-minute counter, so running it first would spend the site's API budget
        # on requests the breaker is about to block anyway — leaving OTTO throttled
        # for the rest of the minute even after the upstream recovers.
        if ($this->is_breaker_open()) {
            $stale = get_transient($keys['stale']);
            if ($stale !== false) {
                self::$cache_status[$cache_status_key] = 'STALE';
                return $stale;
            }
            self::$cache_status[$cache_status_key] = 'BREAKER_OPEN';
            return false;
        }

        # Step 2: Acquire lock atomically (test-and-set).
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
            # The lock holder did not finish in time. Before giving up and letting
            # the caller serve un-optimized HTML, reuse the stale copy — it is the
            # same data one TTL older, which is far better for a crawler than the
            # original title. Previously only the rate-limit path consulted it.
            $stale = get_transient($keys['stale']);
            if ($stale !== false) {
                self::$cache_status[$cache_status_key] = 'STALE';
                return $stale;
            }
            self::$cache_status[$cache_status_key] = 'LOCKED';
            return false;
        }

        try {
            # Another request may have populated the cache between our initial
            # miss and this lock acquisition. Recheck before spending API budget.
            $cached = get_transient($keys['transient']);
            if ($cached !== false) {
                self::$cache_status[$cache_status_key] = $this->has_payload($cached)
                    ? 'HIT'
                    : 'NO_SUGGESTIONS';
                return $cached;
            }

            # Step 3: Count only the lock winner that can make a real API call.
            if (!$this->can_make_api_call()) {
                # Rate limited - try to use stale cache
                $stale = get_transient($keys['stale']);
                if ($stale !== false) {
                    # NEW: Structured error logging with category and code
                    if (class_exists('Metasync_Error_Logger')) {
                        Metasync_Error_Logger::log(
                            Metasync_Error_Logger::CATEGORY_API_RATE_LIMIT,
                            Metasync_Error_Logger::SEVERITY_INFO,
                            'OTTO suggestions throttled - served from cache',
                            [
                                'url' => $url,
                                'fallback' => 'stale_cache',
                                'api_endpoint' => 'OTTO Suggestions API',
                                'operation' => 'get_suggestions'
                            ]
                        );
                    }

                    error_log('MetaSync OTTO: Throttled, serving suggestions from cache for ' . $url);
                    self::$cache_status[$cache_status_key] = 'STALE';
                    return $stale;
                }
                # No stale cache available - return false
                self::$cache_status[$cache_status_key] = 'RATE_LIMITED';
                return false;
            }

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
                # Has suggestions - cache for the admin-configured TTL (default 30 min)
                $ttl = Metasync_Otto_Config::get_otto_cache_ttl_seconds();
                set_transient($keys['transient'], $suggestions, $ttl);
                # Keep a longer fallback without reducing live suggestion freshness.
                set_transient($keys['stale'], $suggestions, self::STALE_TTL);
                self::$cache_status[$cache_status_key] = 'MISS'; // Cache miss, fetched from API
            } elseif ($suggestions !== false) {
                # API responded 200 OK but genuinely no OTTO suggestions for this URL,
                # or answered 404 (no data held for this URL at all).
                #
                # Cache the negative result so we stop asking. This MUST be stored as
                # an empty array rather than `false`: WordPress cannot distinguish a
                # stored `false` from an absent key, so a `false` marker never reads
                # back as a hit and every request re-calls the API — which then
                # exhausts the per-minute budget and pushes these URLs into
                # RATE_LIMITED, a status treated as a transient failure. An empty
                # array reads back cleanly, and has_payload([]) is false, so every
                # consumer still takes the "no suggestions" path.
                set_transient($keys['transient'], [], self::NO_SUGGESTIONS_TTL);

                # Wipe the stale fallback for any empty answer that was NOT a 404 —
                # a 200 whose body is empty or hollow, and a 204. Both mean the URL is
                # known to OTTO but currently holds no suggestions, and without the
                # wipe a rate-limit event within the next hour would serve the
                # previously-deployed (now undeployed) suggestion, keeping the wrong
                # title alive on the page.
                #
                # A 404 is different, and the difference is who answered. A 204 or an
                # empty 200 came from the application itself: it was asked about this
                # URL and deliberately said "nothing here". A 404 may never have
                # reached the application at all — a changed endpoint path, a gateway,
                # or a CDN in front of the API can produce it for every URL on every
                # site at once, for reasons that say nothing about whether suggestions
                # exist. Wiping stale copies on that would destroy the fallback
                # everywhere simultaneously, precisely when it is the only thing
                # keeping correct SEO on the page. So a 404 leaves it alone.
                #
                # Keyed off the recorded status code rather than the emptiness of the
                # decoded body: json_decode() also returns an empty array for a 200
                # body of `{}` or `[]`, which would otherwise be mistaken for a 404.
                if (!$this->last_fetch_was_404) {
                    delete_transient($keys['stale']);
                }

                self::$cache_status[$cache_status_key] = 'NO_SUGGESTIONS';
            } else {
                # fetch_from_api() returned false — network error, timeout, or a
                # non-200 response other than 404 and 204 (both of those return []
                # and are handled by the "no suggestions" branch above).
                # Do NOT cache the failure: a stale false would poison subsequent MISS requests
                # (Kinsta sees MISS → PHP runs → transient HIT = false → OTTO skips → old title cached).
                # Leave the transient empty so the very next page load retries the API call.
                #
                # Before reporting the failure upward, reuse the stale copy if we
                # have one — same data, one TTL older, which still renders correct
                # SEO instead of falling through to the original markup.
                $stale = get_transient($keys['stale']);
                if ($stale !== false) {
                    self::$cache_status[$cache_status_key] = 'STALE';
                    $suggestions = $stale;
                } else {
                    self::$cache_status[$cache_status_key] = 'API_ERROR';
                }
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
     * Whether the last warm/fetch failure was a permanent 4xx refusal.
     *
     * Meaningful only after warm_cache() returned false: a true here means the
     * API answered and refused the request, so retrying is futile. Transport
     * failures, 5xx, rate limits, lock contention, and an open circuit breaker
     * all leave this false.
     *
     * @return bool
     */
    public function last_failure_is_permanent() {
        return $this->last_failure_permanent;
    }

    /**
     * Fetch suggestions from OTTO API
     *
     * @param string $url The page URL
     * @return array|false API response or false on failure
     */
    private function fetch_from_api($url) {
        # Reset per call so a previous 404 cannot influence this one's handling.
        $this->last_fetch_was_404 = false;
        $this->last_failure_permanent = false;

        # Check if endpoint is in backoff mode (explicit check for better error handling)
        if (class_exists('Metasync_API_Backoff_Manager')) {
            $backoff_manager = Metasync_API_Backoff_Manager::get_instance();
            if ($backoff_manager->is_endpoint_in_backoff($this->api_endpoint)) {
                error_log('MetaSync OTTO: Request deferred - retry scheduled for this endpoint');
                # Try to use stale cache if available
                $stale = get_transient($this->get_stale_key($url));
                if ($stale !== false) {
                    error_log('MetaSync OTTO: Serving suggestions from cache while retry is pending');
                    return $stale;
                }
                return false;
            }
        }

        # NOTE: the circuit breaker gate lives in get_suggestions(), which is
        # this method's only caller — placed there so an open breaker also skips the
        # rate-limit increment. Failure recording stays here, at the point where the
        # transport result is known.

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
                error_log('MetaSync OTTO: Request deferred by retry schedule - ' . $error_message);
                # Try to use stale cache
                $stale = get_transient($this->get_stale_key($url));
                if ($stale !== false) {
                    error_log('MetaSync OTTO: Serving suggestions from cache while retry is pending');
                    return $stale;
                }
            } else {
                # A WP_Error that is not a backoff block is a hard transport
                # failure (cURL 28 timeout, connection refused, DNS failure). These are
                # the failures that cost a full API_TIMEOUT of PHP-FPM worker time.
                $this->record_breaker_failure();
                error_log('MetaSync OTTO: API call failed for ' . $url . ' - ' . $error_message);
            }
            return false;
        }

        # Check response code
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            # 404 and 204 are ANSWERS, not failures: the API is telling us it
            # holds no OTTO data for this URL (never crawled, outside the project,
            # or successfully answered with no content).
            # Returning false here would classify it as API_ERROR, which is never
            # cached and therefore re-requested on every uncached page load —
            # burning the per-minute API budget on URLs that will keep answering
            # 404 or 204, and (once fail-open emits no-cache headers) making every
            # not-yet-crawled page uncacheable. Newly published posts are in this
            # state until OTTO crawls them, so this is the common case, not an edge
            # case. Return an empty array instead: get_suggestions() then takes the
            # "no suggestions" branch and negative-caches it like any other
            # legitimately empty result.
            #
            # These also close the circuit breaker, for the same reason a 200 does:
            # the host answered, so it is reachable. Without that, a project whose
            # URLs all answer 404 (not yet crawled) would leave a previously-opened
            # breaker stuck half-open, re-probing every LOCK_TIMEOUT seconds against
            # an API that is in fact perfectly healthy.
            if ($response_code === 404) {
                $this->last_fetch_was_404 = true;
                $this->reset_breaker();
                return [];
            }
            if ($response_code === 204) {
                $this->reset_breaker();
                return [];
            }

            # 5xx means the upstream is unhealthy — count it toward the breaker.
            # Checked after the 404/204 answers above so they are never miscounted
            # as failures. 429/503 are left to the backoff manager as well (503 is
            # counted by both, which is correct: it is simultaneously "slow down"
            # and "upstream unhealthy").
            if ($response_code >= 500) {
                $this->record_breaker_failure();
            }

            # For 429/503, the backoff manager will handle it automatically
            # Try to use stale cache for these errors
            if (in_array($response_code, [429, 503], true)) {
                $stale = get_transient($this->get_stale_key($url));
                if ($stale !== false) {
                    error_log('MetaSync OTTO: Serving suggestions from cache - source temporarily unavailable');
                    return $stale;
                }
            }

            # Any other non-200 is a 4xx refusal (400/401/403/410 …): the API
            # rejected the request itself — bad key, revoked project, malformed
            # target. Retrying cannot change that answer, so flag it for
            # callers that own a retry policy; they fail fast instead of
            # spending the full attempt budget on guaranteed rejections.
            if ($response_code < 500 && $response_code !== 429) {
                $this->last_failure_permanent = true;
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

        # A clean 200 means the upstream is healthy again — close the breaker.
        $this->reset_breaker();

        return $data;
    }

    // ------------------------------------------------------------------
    //  Circuit breaker
    //
    //  Scoped to the API *host*, not the URL: when sa.searchatlas.com is
    //  unreachable it is unreachable for every URL, so a per-URL breaker would
    //  need BREAKER_FAILURE_THRESHOLD failures per URL before it helped — which
    //  on a site with thousands of URLs is never.
    // ------------------------------------------------------------------

    /**
     * Transient key for the breaker state (per API host, per site).
     *
     * @return string
     */
    private function get_breaker_key() {
        return self::breaker_key_for_host(wp_parse_url($this->api_endpoint, PHP_URL_HOST) ?: 'unknown');
    }

    /**
     * Single derivation of the breaker key, shared by the instance and static
     * paths so the two cannot drift apart.
     *
     * @param string $host API host.
     * @return string
     */
    private static function breaker_key_for_host($host) {
        $site_id = is_multisite() ? get_current_blog_id() : 0;
        return self::BREAKER_PREFIX . $site_id . '_' . md5($host);
    }

    /**
     * Is the circuit breaker currently open (i.e. should we skip the API call)?
     *
     * Three states:
     *  - closed    : fewer than BREAKER_FAILURE_THRESHOLD consecutive failures. Allow.
     *  - open      : threshold reached, still inside the cool-off. Block.
     *  - half-open : cool-off elapsed. Allow exactly ONE request through to probe
     *                the upstream; block the rest. Without the probe lock every
     *                concurrent request would retry at once and we would recreate
     *                the stampede the breaker exists to prevent.
     *
     * @return bool True if the API call should be skipped.
     */
    private function is_breaker_open() {
        # Escape hatch: lets support disable the breaker on a single site without
        # shipping a release, mirroring how other OTTO behaviour is gated.
        if (!apply_filters('metasync_otto_circuit_breaker_enabled', true)) {
            return false;
        }

        $open_until = (int) get_transient($this->get_breaker_key());

        if ($open_until <= 0) {
            # Closed (or counting failures but not yet tripped).
            return false;
        }

        if (time() < $open_until) {
            return true;
        }

        # Half-open: the first caller to win the probe lock gets to test the
        # upstream. Without this every concurrent request would retry at once and
        # recreate the stampede the breaker exists to prevent.
        # RETRY-LOOP-SAFETY: bounded recovery; do not add a naive retry handler here.
        # Concurrency: one host-level lock lasting beyond the 8-second transport timeout.
        # Attempts/backoff: one transport attempt; failure re-arms the 60-second cool-off.
        # Blocked callers return immediately; inline retries would hold PHP workers.
        $probe_key = self::BREAKER_PROBE_PREFIX . md5($this->get_breaker_key());
        if ($this->acquire_lock_atomic($probe_key, self::BREAKER_PROBE_LOCK_TIMEOUT)) {
            return false;
        }

        return true;
    }

    /**
     * Record a hard upstream failure and open the breaker once the threshold is hit.
     *
     * The failure tally is incremented atomically: a read-modify-write here loses
     * increments under exactly the concurrency the breaker is meant to protect
     * against, which delays the trip and lets the damage continue. Verified in
     * load testing — the naive version opened several seconds late.
     *
     * The open-until timestamp is a plain write because every racing worker
     * computes the same value, so a lost update is harmless.
     */
    private function record_breaker_failure() {
        $open_key = $this->get_breaker_key();

        $failures = $this->atomic_increment(
            $this->get_breaker_fail_key(),
            self::BREAKER_COOLOFF * 10
        );

        # Re-arm when the tally crosses the threshold, OR when the breaker is
        # already tripped.
        #
        # The second condition is not redundant. The failure tally's expiry is
        # written once, on first insert, and is never refreshed — so during an
        # outage longer than its lifetime the tally silently resets to 1. Without
        # this check a failed half-open probe would then fail to extend the
        # cool-off, and the breaker would decay into one probe every LOCK_TIMEOUT
        # seconds instead of one every BREAKER_COOLOFF.
        #
        # Short-circuits, so the extra read only happens on the sub-threshold
        # failure path, which is rare.
        if ($failures >= self::BREAKER_FAILURE_THRESHOLD || get_transient($open_key) !== false) {
            # Re-writing this also refreshes its TTL, so the open marker cannot
            # expire underneath a sustained outage.
            set_transient($open_key, time() + self::BREAKER_COOLOFF, self::BREAKER_COOLOFF * 10);
        }
    }

    /**
     * Close the breaker after a successful call.
     */
    private function reset_breaker() {
        $open_key = $this->get_breaker_key();
        $fail_key = $this->get_breaker_fail_key();

        # Guarded so a healthy site is not issuing deletes on every cache MISS.
        # counter_get()/counter_delete() for the tally because with an object
        # cache it does not live in the transient namespace.
        if (get_transient($open_key) !== false || $this->counter_get($fail_key) > 0) {
            delete_transient($open_key);
            $this->counter_delete($fail_key);
        }
    }

    /**
     * Transient key for the breaker's consecutive-failure tally.
     *
     * @return string
     */
    private function get_breaker_fail_key() {
        return self::BREAKER_FAIL_PREFIX . substr($this->get_breaker_key(), strlen(self::BREAKER_PREFIX));
    }

    /**
     * Is the breaker open for the host serving the given endpoint?
     *
     * Static so other SearchAtlas callers on the same host (the bot crawl-log
     * push in particular) can cheaply avoid an outbound call we already know
     * will hang. Read-only: never opens, closes or probes the breaker, so a
     * caller using this cannot disturb the suggestions path's state machine.
     *
     * @param string $endpoint_url Any URL on the host in question.
     * @return bool
     */
    public static function is_host_breaker_open($endpoint_url) {
        if (!apply_filters('metasync_otto_circuit_breaker_enabled', true)) {
            return false;
        }

        $host = wp_parse_url($endpoint_url, PHP_URL_HOST);
        if (empty($host)) {
            return false;
        }

        $open_until = (int) get_transient(self::breaker_key_for_host($host));

        return $open_until > 0 && time() < $open_until;
    }

    /**
     * Circuit breaker state, for Site Health / debug surfaces.
     *
     * @return array{open:bool, failures:int, seconds_remaining:int}
     */
    public function get_breaker_status() {
        $remaining = max(0, (int) get_transient($this->get_breaker_key()) - time());

        return [
            'open'              => $remaining > 0,
            'failures'          => $this->counter_get($this->get_breaker_fail_key()),
            'seconds_remaining' => $remaining,
        ];
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

        # Undeployed OTTO responses ship harmless whitespace ("\n") and
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
        # other subsite for the rest of the minute.
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

        # MySQL transient fallback, atomic. The previous
        # read-then-write lost increments whenever two PHP workers interleaved,
        # which is precisely the traffic pattern the limiter exists for.
        # Measured effect: ~22 calls/min against a configured cap of 10.
        return $this->atomic_increment($rate_key, MINUTE_IN_SECONDS) <= $rate_limit;
    }

    /**
     * Atomically increment a transient-backed counter and return its new value.
     *
     * Shared by the rate limiter and the circuit breaker. Both are counters that
     * are only ever consulted while multiple PHP workers are racing, so a
     * read-modify-write (get_transient + set_transient) silently undercounts
     * exactly when accuracy matters.
     *
     * On MySQL, INSERT ... ON DUPLICATE KEY UPDATE is atomic under the unique
     * key on option_name, and the LAST_INSERT_ID(expr) idiom hands back the
     * post-increment value on the same connection without a second, racy SELECT.
     *
     * Caveat: the raw INSERT bypasses WordPress's in-process option cache, so a
     * get_transient() call for the same key later in the SAME request may return
     * a stale value. Nothing reads these counters that way — both callers use the
     * return value here — but diagnostic surfaces should re-read from the DB.
     *
     * @param string $key Transient key (without the _transient_ prefix).
     * @param int    $ttl Lifetime in seconds.
     * @return int New counter value.
     */
    private function atomic_increment($key, $ttl) {
        if (wp_using_ext_object_cache()) {
            # Redis/Memcached INCR is already atomic.
            wp_cache_add($key, 0, self::COUNTER_GROUP, $ttl);
            return (int) wp_cache_incr($key, 1, self::COUNTER_GROUP);
        }

        global $wpdb;
        $value_option   = '_transient_' . $key;
        $timeout_option = '_transient_timeout_' . $key;

        # Let WordPress reap an expired window first. get_transient() deletes both
        # rows when the timeout has passed; without this the raw INSERT below would
        # happily increment a counter left over from a previous, long-finished
        # window — so a single fresh failure could inherit an old tally and trip
        # the breaker immediately.
        get_transient($key);

        # Timeout row first so WP's transient GC can reap the counter.
        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $timeout_option,
                (string) (time() + (int) $ttl)
            )
        );

        # MySQL reports 1 affected row for an insert and 2 for an update, which is
        # how we distinguish "first increment" from "incremented again".
        $affected = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')
                 ON DUPLICATE KEY UPDATE option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1)",
                $value_option
            )
        );

        # Fresh insert — the counter started at 1.
        if ((int) $affected === 1) {
            return 1;
        }

        # Increment — LAST_INSERT_ID() carries the new value on this connection.
        $new_value = (int) $wpdb->insert_id;
        if ((int) $affected > 1 && $new_value > 0) {
            return $new_value;
        }

        # Anything else means the storage layer did not behave as expected;
        # wpdb::query() returns false on any SQL error. Do NOT trust insert_id
        # here — it may hold a stale value from an unrelated statement, and a
        # large one reads as "budget exhausted", silently switching OTTO off for
        # the whole site until the minute rolls over. Fall back to a plain
        # read-modify-write: less precise under concurrency, but it fails open
        # rather than closed.
        $count = (int) get_transient($key) + 1;
        set_transient($key, $count, $ttl);

        return $count;
    }

    /**
     * Read a counter written by atomic_increment().
     *
     * Must branch on the same backend as the writer. With an external object
     * cache the counter lives in COUNTER_GROUP, NOT in the transient namespace,
     * so reading it with get_transient() would always report zero.
     *
     * Reads zero if atomic_increment() already ran for this key in the SAME
     * request, because that writes via raw SQL and WordPress's in-process option
     * cache still holds the pre-write miss. Harmless for both callers: a request
     * that recorded a failure does not also reset the breaker, and the diagnostic
     * surface reads on a separate request.
     *
     * @param string $key Counter key.
     * @return int
     */
    private function counter_get($key) {
        if (wp_using_ext_object_cache()) {
            return (int) wp_cache_get($key, self::COUNTER_GROUP);
        }

        return (int) get_transient($key);
    }

    /**
     * Delete a counter written by atomic_increment().
     *
     * Backend-matched for the same reason as counter_get(). Getting this wrong
     * leaves the tally growing forever on object-cached sites, so every later
     * incident would trip the breaker on its first failure.
     *
     * @param string $key Counter key.
     */
    private function counter_delete($key) {
        if (wp_using_ext_object_cache()) {
            wp_cache_delete($key, self::COUNTER_GROUP);
            return;
        }

        delete_transient($key);
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
        # shared Redis/Memcached object cache.
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

        # Match the scoped rate-limit key produced by can_make_api_call().
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
            # Render-failure report throttles. Without this they survive a full
            # OTTO cache clear, so a support-led "clear the cache" would leave
            # reporting suppressed for up to 5 more minutes per URL.
            'metasync_otto_fail_',
            # Circuit breaker state, for the same reason: clearing the cache is
            # what support does to make a site retry immediately, so it must also
            # un-stick the breaker rather than leaving OTTO off for another
            # cool-off. This one prefix covers the open marker, the failure tally
            # and the half-open probe lock.
            self::BREAKER_PREFIX,
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
            # Counters live in their own group when an external object cache is
            # present, so the raw SQL above cannot reach them. Covers both the
            # rate limiter and the breaker's failure tally.
            wp_cache_flush_group(self::COUNTER_GROUP);
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
        # All three keys must include $site_id to match get_cache_keys().
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


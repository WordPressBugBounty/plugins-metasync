<?php
/**
 * OTTO Render Strategy Manager
 * Implements hybrid approach: Output Buffer (fast) with wp_remote_get fallback
 * 
 * Features:
 * - Automatic method selection based on environment
 * - Output buffer approach (eliminates internal HTTP request)
 * - Fallback to wp_remote_get for problematic environments
 * - Response headers indicating method used
 * - Detection of caching plugins and managed hosts
 * 
 * @since 2.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Otto_Render_Strategy {

    /**
     * Render method constants
     */
    const METHOD_BUFFER    = 'buffer';
    const METHOD_HTTP      = 'http';
    const METHOD_NONE      = 'none';
    const METHOD_WP_ROCKET = 'rocket_buffer';

    /**
     * Memory amplification factor for SimpleHtmlDom.
     * Parsing a page into a SimpleHtmlDom node tree costs ~18x the raw HTML
     * size, and the save()/load() reload cycle adds another ~40% transient
     * peak. We budget 25x to keep a safety margin before the PHP memory limit.
     */
    const DOM_MEMORY_AMPLIFICATION = 25;

    /**
     * Absolute hard cap (bytes) on documents OTTO will DOM-parse,
     * regardless of available memory. Backstop against pathological pages.
     * Default 16 MB; override with the METASYNC_OTTO_MAX_HTML_BYTES constant
     * or the 'metasync_otto_max_html_bytes' filter.
     */
    const ABSOLUTE_MAX_DOCUMENT_BYTES = 16777216;

    /**
     * Current render method being used
     */
    private static $current_method = null;

    /**
     * Buffer level when OTTO started
     */
    private static $buffer_start_level = null;

    /**
     * Flag to track if buffer is active
     */
    private static $buffer_active = false;

    /**
     * OTTO suggestions data (stored for buffer approach)
     */
    private static $pending_suggestions = null;

    /**
     * Current route (stored for buffer approach)
     */
    private static $pending_route = null;

    /**
     * OTTO HTML processor instance
     */
    private static $otto_html = null;

    /**
     * Blocking flags for SEO plugins
     */
    private static $blocking_flags = null;

    /**
     * Whether this request's document was declined as unprocessable (oversized /
     * over the memory budget) rather than attempted and failed. Set by
     * log_oversized_skip(); read via document_was_unprocessable().
     */
    private static $document_unprocessable = false;

    /**
     * Determine the best render method for current environment
     * 
     * @return string METHOD_BUFFER or METHOD_HTTP
     */
    public static function determine_method() {
        # Skip buffer for internal fetch requests (avoid infinite loop)
        if (!empty($_GET['is_otto_page_fetch'])) {
            return self::METHOD_NONE;
        }

        # Skip for admin, AJAX, REST API
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return self::METHOD_NONE;
        }

        # Check for known problematic configurations that need HTTP method
        if (self::should_use_http_method()) {
            return self::METHOD_HTTP;
        }

        # Check if output buffering is available and safe to use
        if (self::is_buffer_available()) {
            return self::METHOD_BUFFER;
        }

        # Fallback to HTTP method
        return self::METHOD_HTTP;
    }

    /**
     * Check if output buffering is available and safe
     * 
     * @return bool
     */
    private static function is_buffer_available() {
        # Check if output buffering is enabled
        if (!function_exists('ob_start') || !function_exists('ob_get_clean')) {
            return false;
        }

        # Check buffer nesting level (too many nested buffers = risky)
        $current_level = ob_get_level();
        if ($current_level > 5) {
            return false;
        }

        # Check if headers already sent (can't use buffer properly)
        if (headers_sent($file, $line)) {
            return false;
        }

        # Check memory limit (need enough memory to buffer page)
        $memory_limit = self::get_memory_limit_bytes();
        $memory_used = memory_get_usage(true);
        $memory_available = $memory_limit - $memory_used;
        
        # Need at least 16MB available for buffering
        if ($memory_available < 16 * 1024 * 1024) {
            return false;
        }

        return true;
    }

    /**
     * Check if HTTP method should be forced
     * 
     * @return bool True if HTTP method should be used
     */
    private static function should_use_http_method() {
        # Force HTTP method via constant (for debugging/testing)
        if (defined('METASYNC_OTTO_FORCE_HTTP') && METASYNC_OTTO_FORCE_HTTP) {
            return true;
        }

        # Check for known caching plugins that may conflict with output buffering
        $conflicting_plugins = [
            # Heavy output buffering plugins
            'WP_Rocket' => class_exists('WP_Rocket'),
            'W3TC' => defined('W3TC'),
            'LiteSpeed_Cache' => defined('LSCWP_V'),

            # SiteGround Optimizer — its Parser starts a nested ob_start() with callback
            # that flushes before wp_footer completes, causing Divi's late-injected
            # module-design CSS (<style id="et-builder-module-design-*">) to be lost.
            # Affects all themes that defer inline CSS output to wp_footer (Divi 5, etc.).
            'SG_CachePress' => defined('SiteGround_Optimizer\VERSION') || class_exists('SiteGround_Optimizer\Parser\Parser'),

            # Page builders with aggressive buffering
            'Brizy_Editor' => class_exists('Brizy_Editor'),
            'Oxygen_Builder' => defined('CT_VERSION'), # Oxygen manipulates wp_current_filter causing CSS loading issues in PHP 8.3+

            # Optimization plugins
            'Autoptimize' => class_exists('autoptimizeMain'),
        ];

        foreach ($conflicting_plugins as $name => $is_active) {
            if ($is_active) {
                # OXYGEN BUILDER: Always use HTTP method due to wp_current_filter manipulation
                # Oxygen manipulates $wp_current_filter during wp_head which breaks CSS loading in PHP 8.3+
                # HTTP method bypasses the buffer and avoids the conflict entirely
                if ($name === 'Oxygen_Builder') {
                    return true;
                }

                # SITEGROUND OPTIMIZER: Use HTTP method — buffer method + SimpleHtmlDom
                # corrupts Divi module numbering (et_pb_blog_0 → et_pb_blog_1), breaking
                # AJAX pagination. HTTP method preserves numbering; Divi CSS re-injection
                # is handled in render_via_http() via et_core_page_resource_get API.
                if ($name === 'SG_CachePress') {
                    return true;
                }

                # For logged-in users, buffer is usually safe even with these plugins
                if (is_user_logged_in()) {
                    continue;
                }

                # For non-logged-in users with caching plugins, check if page caching is active
                if ($name === 'WP_Rocket') {
                    # Get WP Rocket compatibility configuration
                    $metasync_options = get_option('metasync_options', []);
                    $wp_rocket_compat_mode = $metasync_options['general']['otto_wp_rocket_compat'] ?? 'auto';

                    # Force HTTP if user selected it
                    if ($wp_rocket_compat_mode === 'http') {
                        return true; # Use HTTP method for maximum compatibility
                    }

                    # Force buffer if user selected it
                    if ($wp_rocket_compat_mode === 'buffer') {
                        continue; # Use buffer method for speed
                    }

                    # For auto mode, use buffer so WP Rocket can continue optimizing the page
                    if ($wp_rocket_compat_mode === 'auto') {
                        continue;
                    }
                }

                # Default: use HTTP method for safety with caching plugins
                // Removed logging to reduce noise - uncomment for debugging

                # Actually, let's try buffer first for most cases
                # Only force HTTP for specific known issues
            }
        }

        # Check for managed hosts that may have issues
        # WP Engine detection
        if (defined('WPE_APIKEY') || getenv('IS_WPE')) {
            # WP Engine serves cached pages at edge, but for uncached requests, buffer works
            if (!is_user_logged_in() && !defined('DONOTCACHEPAGE')) {
                # This might be a cached request that WP Engine serves from edge
                # However, if we're here, PHP is executing, so buffer should work
            }
        }

        # Kinsta detection
        if (defined('KINSTAMU_VERSION') || getenv('KINSTA_CACHE_ZONE')) {
            # Similar to WP Engine - if PHP is executing, buffer should work
        }

        # Cloudways Varnish
        if (isset($_SERVER['HTTP_X_VARNISH'])) {
            # Varnish is in front, but if we're here, request reached PHP
        }

        # Flywheel detection
        if (defined('FLYWHEEL_CONFIG_DIR')) {
            # Flywheel caching similar to WP Engine
        }

        # No known conflicts detected
        return false;
    }

    /**
     * Get memory limit in bytes
     * 
     * @return int Memory limit in bytes
     */
    private static function get_memory_limit_bytes() {
        $limit = ini_get('memory_limit');
        
        if ($limit === '-1') {
            return PHP_INT_MAX; # Unlimited
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }

    /**
     * Resolve the configurable absolute document-size cap (bytes).
     *
     * @return int Maximum document size in bytes (0 disables the absolute cap)
     */
    public static function get_max_document_bytes() {
        $cap = defined('METASYNC_OTTO_MAX_HTML_BYTES')
            ? (int) METASYNC_OTTO_MAX_HTML_BYTES
            : self::ABSOLUTE_MAX_DOCUMENT_BYTES;

        # Allow per-site tuning via filter (e.g. higher cap on large-memory hosts)
        if (function_exists('apply_filters')) {
            $cap = (int) apply_filters('metasync_otto_max_html_bytes', $cap);
        }

        return max(0, $cap);
    }

    /**
     * Decide whether a document is safe to DOM-parse without risking
     * a fatal out-of-memory error.
     *
     * Combines a configurable absolute byte cap with a dynamic check against
     * the actual memory headroom (memory_limit minus current usage), budgeting
     * DOM_MEMORY_AMPLIFICATION times the HTML size for the parse + reload cycle.
     *
     * @param int $html_length Length of the raw HTML in bytes
     * @return bool True if processing is safe, false if it should be skipped
     */
    public static function is_document_processable($html_length) {
        $html_length = (int) $html_length;

        if ($html_length <= 0) {
            return false;
        }

        # 1. Absolute configurable cap (backstop against pathological pages)
        $max = self::get_max_document_bytes();
        if ($max > 0 && $html_length > $max) {
            return false;
        }

        # 2. Dynamic memory budget — adapts to the host's memory_limit
        $limit = self::get_memory_limit_bytes();
        if ($limit === PHP_INT_MAX) {
            return true; # Unlimited memory_limit (-1)
        }

        $available = $limit - memory_get_usage(true);
        $needed    = $html_length * self::DOM_MEMORY_AMPLIFICATION;

        return $needed < $available;
    }

    /**
     * Log a skipped oversized document once per request (warning, not fatal).
     *
     * @param string $context Where the skip happened (for the log line)
     * @param int    $html_length Raw HTML length in bytes
     */
    public static function log_oversized_skip($context, $html_length) {
        # Record that this request's document could not be processed at all, as
        # opposed to a rewrite that was attempted and failed. The two are
        # indistinguishable to callers otherwise — both surface as
        # process_html_directly() returning false — but they need opposite
        # cache handling: a rewrite failure may succeed on the next request, while
        # an oversized document will exceed the budget every single time. Treating
        # the latter as a transient failure would cap that page's cache lifetime
        # permanently, and it is the most expensive page on the site to render.
        self::$document_unprocessable = true;

        $route = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'unknown';
        error_log(sprintf(
            '[MetaSync OTTO] Skipped DOM processing on %s (%s): document %s exceeds safe memory budget (limit %s, used %s, cap %s).',
            $route,
            $context,
            size_format($html_length),
            ini_get('memory_limit'),
            size_format(memory_get_usage(true)),
            size_format(self::get_max_document_bytes())
        ));
    }

    /**
     * Build the response headers that cap how long an un-optimized response may
     * be cached.
     *
     * A pure function so the header set can be asserted directly: header() is a
     * no-op under the CLI SAPI and headers_list() is always empty there, so
     * emitting and then inspecting is not testable. It lives on this class rather
     * than beside its caller in otto_pixel.php because that file cannot be loaded
     * in a unit-test context (it pulls in simplehtmldom and the whole OTTO stack).
     *
     * Each header addresses a different layer:
     *
     *  - Cache-Control      CDNs, host edge caches, browsers. max-age=0 keeps
     *                       browsers revalidating while s-maxage lets shared
     *                       caches absorb a burst for the ceiling's duration.
     *  - X-Accel-Expires    Nginx's own directive, evaluated with higher
     *                       precedence than Cache-Control and not covered by the
     *                       `fastcgi_ignore_headers Cache-Control Expires` recipe
     *                       common on managed hosts, so it can survive a config
     *                       that discards Cache-Control. Nginx strips X-Accel-*
     *                       before the client sees it.
     *  - Surrogate-Control  Varnish and Fastly. On stock Varnish the mere presence
     *                       of this header suppresses its Cache-Control check, so
     *                       it must carry a real lifetime.
     *
     * Cache-tagging headers (Surrogate-Key, Cache-Tag, Edge-Cache-Tag) are
     * deliberately absent from this list and must not be removed elsewhere: they
     * are purge-targeting labels, not cacheability directives, and on a layer that
     * stores this response anyway they are the only handle a later purge has on it.
     *
     * @param int    $ttl    Cache ceiling in seconds.
     * @param string $reason Optional machine-readable reason for the debug header.
     * @param int|null $cache_control_ttl Shared lifetime for Cache-Control only,
     *                                    when it must stay stricter than the
     *                                    lifetime given to the other layers.
     *                                    Defaults to $ttl.
     * @return array Header name => value.
     */
    public static function fallback_cache_headers($ttl, $reason = '', $cache_control_ttl = null) {
        $ttl = max(0, (int) $ttl);
        $cache_control_ttl = $cache_control_ttl === null ? $ttl : max(0, (int) $cache_control_ttl);

        $headers = [
            'Cache-Control'     => 'max-age=0, s-maxage=' . $cache_control_ttl . ', must-revalidate',
            'X-Accel-Expires'   => (string) $ttl,
            'Surrogate-Control' => 'max-age=' . $ttl,
        ];

        if ($reason !== '' && self::diagnostics_enabled()) {
            $headers['X-MetaSync-OTTO-Fallback'] = $reason;
        }

        return $headers;
    }

    /**
     * Headers that forbid storage outright.
     *
     * Used instead of the capped set when the response must not be shared at all —
     * currently for logged-in users, whose page may carry session-specific or
     * membership-gated content. Capping such a response at 60 seconds would still
     * permit a shared cache to store and re-serve it.
     *
     * @param string $reason Optional machine-readable reason for the debug header.
     * @return array Header name => value.
     */
    public static function no_store_headers($reason = '') {
        $headers = [
            'Cache-Control'     => 'no-store, no-cache, private, must-revalidate, max-age=0',
            'X-Accel-Expires'   => '0',
            'Surrogate-Control' => 'no-store',
        ];

        if ($reason !== '' && self::diagnostics_enabled()) {
            $headers['X-MetaSync-OTTO-Fallback'] = $reason;
        }

        return $headers;
    }

    /**
     * Build cache headers for a failed render without loosening an earlier host
     * cache decision.
     *
     * The headers that matter here are interpreted by different cache layers,
     * so the smallest known lifetime must be applied consistently to all of
     * them. A no-store directive anywhere wins outright. If a relevant existing
     * directive is not one we understand, leave it untouched rather than risk
     * replacing a stricter host policy.
     *
     * Cache-Control and Surrogate-Control carry a comma-separated list, so every
     * directive is judged on its own. Testing the recognised-directive list
     * against the whole header value instead rejects the multi-directive headers
     * hosts actually send — `public, must-revalidate` is two safe directives, not
     * one unknown one — and skips the cap the caller asked for.
     *
     * The recognised list covers the directives that do not extend how long a
     * response stays fresh: the cacheability and transform flags, plus
     * stale-while-revalidate and stale-if-error. Those two do widen the window in
     * which a stale copy may be served, but the set we emit replaces the host's
     * Cache-Control outright and carries must-revalidate, so neither survives to
     * act on the capped response. Anything genuinely unrecognised still backs off,
     * because it may be stricter than the cap and there is no safe way to tell.
     *
     * Surrogate-Control `content="..."` is deliberately NOT on the list. It
     * selects a processing mode (ESI) rather than a lifetime, and the header we
     * emit would replace it — disabling page assembly on sites that rely on it.
     * Backing off costs those sites the cap; allowlisting it could break how their
     * pages are built, which is the worse trade.
     *
     * A bail-out still returns the diagnostic reason header when Debug Mode is
     * on. Without it the response is indistinguishable from one where the
     * failure path never ran at all, which makes a missing cap invisible to
     * exactly the person trying to confirm it.
     *
     * One asymmetry is deliberate. A bare Cache-Control `max-age=0`, with no
     * s-maxage beside it, is honoured in Cache-Control — a host asking for
     * revalidation gets it — but is NOT copied into X-Accel-Expires or
     * Surrogate-Control. Writing 0 into those converts "revalidate before reuse"
     * into "never store", and every request lands on the origin for as long as
     * the render keeps failing. Writing nothing is worse still: those layers fall
     * back to their own default, often 24 hours, which is the exact pinning this
     * method exists to prevent. They therefore get the ordinary ceiling, which is
     * stricter than any default they would otherwise apply.
     *
     * The two headers do not stand on equal ground here, and the difference
     * matters when reading the header list documented on fallback_cache_headers():
     *
     *  - X-Accel-Expires  nginx configured to discard Cache-Control never saw the
     *                     host's max-age at all, so nothing is being overridden.
     *  - Surrogate-Control  Varnish and Fastly CAN read Cache-Control; they simply
     *                     prefer Surrogate-Control when it is present. So this one
     *                     IS a deliberate, bounded exception: on those layers we
     *                     grant 60 seconds the host did not ask for. It is capped,
     *                     it only applies while a render is failing, and our set
     *                     carries must-revalidate — whereas honouring the 0 there
     *                     reinstates the origin-load problem this asymmetry exists
     *                     to solve. Chosen knowingly; not an oversight.
     *
     * An explicit X-Accel-Expires or Surrogate-Control from the host is a
     * different matter and is always honoured, 0 included: there the host did
     * speak in that layer's own channel.
     *
     * Cache-Control `no-cache` is handled on the same principle: it permits
     * storage and demands revalidation before reuse, so it is passed through
     * intact rather than flattened into no-store. `no-store` and `private` do
     * refuse a shared copy and still produce the no-store set.
     *
     * @param int        $ttl     Maximum fallback cache lifetime in seconds.
     * @param string     $reason  Optional machine-readable fallback reason.
     * @param array|null $headers Header lines to inspect; defaults to headers_list().
     * @return array Header name => value. Carries no cache directives when an
     *               existing one cannot be safely reconciled.
     */
    public static function fallback_cache_headers_for_response($ttl, $reason = '', $headers = null) {
        if ($headers === null) {
            $headers = function_exists('headers_list') ? headers_list() : [];
        }

        $ttl = max(0, (int) $ttl);

        # Lifetimes the host declared through a channel a shared cache reads as a
        # shared lifetime. These bind every layer we emit.
        $shared_ttls = [];

        # A bare Cache-Control max-age=0 means "revalidate before reuse", not
        # "never store". It binds Cache-Control, but must not be copied into
        # X-Accel-Expires or Surrogate-Control: those exist to reach layers that
        # ignore Cache-Control, and a 0 there converts the host's revalidate into a
        # storage ban those layers were never given. See the note below.
        $revalidate_only = [];

        $revalidate_required = false;

        foreach ((array) $headers as $line) {
            if (!is_string($line) || strpos($line, ':') === false) {
                continue;
            }

            list($name, $value) = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $value = trim($value);

            if ($name === 'cache-control' || $name === 'surrogate-control') {
                # no-store and private forbid a shared copy outright. no-cache does
                # not: it permits storage and requires revalidation before reuse,
                # which is the same distinction the bare max-age=0 handling rests
                # on. Flattening it to no-store would be stricter than the host
                # asked and would contradict that decision.
                if (preg_match('/\b(no-store|private)\b/i', $value)) {
                    return self::no_store_headers($reason);
                }

                if (preg_match('/\bno-cache\b/i', $value)) {
                    if ($name === 'cache-control') {
                        $revalidate_required = true;
                        continue;
                    }

                    # Surrogate-Control defines no-store, not no-cache. A surrogate
                    # emitting it non-standardly is not something to reinterpret
                    # generously, so it keeps the strict reading.
                    return self::no_store_headers($reason);
                }

                $shared_ttl = null;
                $any_ttl    = null;

                foreach (preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY) as $directive) {
                    if (preg_match('/^s-maxage\s*=\s*(\d+)$/i', $directive, $matches)) {
                        $shared_ttl = (int) $matches[1];
                    } elseif (preg_match('/^max-age\s*=\s*(\d+)$/i', $directive, $matches)) {
                        $any_ttl = (int) $matches[1];
                    } elseif (!preg_match('/^(?:public|must-revalidate|proxy-revalidate|immutable|no-transform|stale-while-revalidate\s*=\s*\d+|stale-if-error\s*=\s*\d+)$/i', $directive)) {
                        return self::diagnostics_only_headers($reason);
                    }
                }

                # s-maxage governs shared caches and overrides max-age there, so it
                # wins when a header carries both. Surrogate-Control is only ever
                # read by a surrogate, so its max-age is a shared lifetime too.
                if ($shared_ttl !== null) {
                    $shared_ttls[] = $shared_ttl;
                } elseif ($any_ttl !== null) {
                    if ($name === 'surrogate-control' || $any_ttl > 0) {
                        $shared_ttls[] = $any_ttl;
                    } else {
                        $revalidate_only[] = $any_ttl;
                    }
                }
                continue;
            }

            if ($name === 'x-accel-expires') {
                if (preg_match('/^\d+$/', $value)) {
                    $shared_ttls[] = (int) $value;
                } else {
                    return self::diagnostics_only_headers($reason);
                }
            }
        }

        $shared = $shared_ttls ? min(array_merge([$ttl], $shared_ttls)) : $ttl;

        if ($revalidate_required) {
            return self::revalidate_headers($shared, $reason);
        }

        $cache_control = $revalidate_only
            ? min(array_merge([$shared], $revalidate_only))
            : $shared;

        return self::fallback_cache_headers($shared, $reason, $cache_control);
    }

    /**
     * Headers for a host that asked for revalidation rather than for storage to
     * be refused.
     *
     * A host sending Cache-Control: no-cache is saying a cache may keep this
     * response but must check with the origin before reusing it. That instruction
     * is passed through untouched, so any cache reading Cache-Control behaves
     * exactly as the host asked. The other two headers carry the ordinary ceiling
     * for the same reason a bare max-age=0 does not zero them: writing 0 there
     * turns a revalidation request into a storage ban for layers that were never
     * given one, and writing nothing hands those layers their own multi-hour
     * default back.
     *
     * @param int    $ttl    Cache ceiling in seconds for the non-Cache-Control layers.
     * @param string $reason Optional machine-readable reason for the debug header.
     * @return array Header name => value.
     */
    public static function revalidate_headers($ttl, $reason = '') {
        $ttl = max(0, (int) $ttl);

        $headers = [
            'Cache-Control'     => 'no-cache, must-revalidate',
            'X-Accel-Expires'   => (string) $ttl,
            'Surrogate-Control' => 'max-age=' . $ttl,
        ];

        if ($reason !== '' && self::diagnostics_enabled()) {
            $headers['X-MetaSync-OTTO-Fallback'] = $reason;
        }

        return $headers;
    }

    /**
     * The diagnostic reason header on its own, with no cache directives.
     *
     * Used by the paths that deliberately emit nothing that affects caching, so
     * that "we ran and declined to touch the headers" is still distinguishable
     * from "we never ran". Debug Mode gates it, so a production response is
     * unchanged.
     *
     * @param string $reason Machine-readable fallback reason.
     * @return array Header name => value; empty unless Debug Mode is on.
     */
    private static function diagnostics_only_headers($reason = '') {
        if ($reason === '' || !self::diagnostics_enabled()) {
            return [];
        }

        return ['X-MetaSync-OTTO-Fallback' => $reason];
    }

    /**
     * Clear per-request state held in statics.
     *
     * Under mod_php/PHP-FPM the process ends with the request, so these reset
     * themselves. Under a persistent-worker SAPI (FrankenPHP or Swoole worker mode,
     * `wp server`) they do not — and a latched $document_unprocessable would
     * suppress cache capping and failure reporting for every subsequent request in
     * that worker, which is the hardest kind of fault to notice because it makes
     * things go quiet rather than break.
     *
     * Called once per request from the single entry point all three render paths
     * pass through.
     */
    public static function reset_request_state() {
        self::$document_unprocessable = false;
    }

    /**
     * Whether this request's document was skipped as unprocessable.
     *
     * True means OTTO declined to parse the document (oversized / memory budget),
     * which is a permanent property of that page rather than a failure that might
     * clear on a retry.
     *
     * @return bool
     */
    public static function document_was_unprocessable() {
        return self::$document_unprocessable;
    }

    /**
     * Start output buffer for OTTO processing
     * Called early in WordPress lifecycle (template_redirect)
     * 
     * @param array $suggestions OTTO suggestions data
     * @param string $route Current page route
     * @param Metasync_otto_html $otto_html OTTO HTML processor
     * @param array $blocking_flags SEO plugin blocking flags
     * @return bool True if buffer started successfully
     */
    public static function start_buffer($suggestions, $route, $otto_html, $blocking_flags = []) {
        if (self::$buffer_active) {
            return false; # Already buffering
        }

        # Store data for later processing
        self::$pending_suggestions = $suggestions;
        self::$pending_route = $route;
        self::$otto_html = $otto_html;
        self::$blocking_flags = $blocking_flags;
        self::$buffer_start_level = ob_get_level();
        self::$current_method = self::METHOD_BUFFER;

        # Start output buffering with our callback
        $started = ob_start([__CLASS__, 'process_buffer']);

        if ($started) {
            self::$buffer_active = true;
            
            # Register shutdown handler to ensure buffer is processed
            register_shutdown_function([__CLASS__, 'shutdown_handler']);
            
            return true;
        }

        # Buffer failed to start, mark for HTTP fallback
        self::$current_method = self::METHOD_HTTP;
        return false;
    }

    /**
     * Process the captured output buffer
     * This is the callback for ob_start()
     * 
     * CRITICAL: This callback MUST return the HTML string (original or modified)
     * If this fails, WordPress will show a blank page. Always return $html as fallback.
     * 
     * @param string $html The captured HTML
     * @return string Modified HTML
     */
    public static function process_buffer($html) {
        # SAFETY FIRST: If anything goes wrong, always return original HTML
        # Never throw or cause errors - this would break the page
        
        
        try {
            # If HTML is empty or too short, WordPress likely crashed before generating output.
            # Override the cache headers OTTO already sent so downstream caches (WP Engine,
            # Kinsta, Cloudflare, etc.) don't store and serve this broken response.
            if (empty($html) || strlen($html) < 100) {
                if (!headers_sent()) {
                    header('Cache-Control: no-store, no-cache, private');
                    header_remove('Surrogate-Key');
                    header_remove('Cache-Tag');
                    header_remove('Edge-Cache-Tag');
                }
                return $html;
            }

            # Skip processing if no suggestions (page is fine, just no OTTO modifications needed)
            if (empty(self::$pending_suggestions)) {
                return $html;
            }

            # Skip if this doesn't look like HTML (error page, JSON response, etc.)
            if (stripos($html, '<html') === false && stripos($html, '<!DOCTYPE') === false) {
                return $html;
            }

            # Skip if this looks like an error page (WordPress fatal error)
            if (stripos($html, 'Fatal error') !== false || stripos($html, 'Parse error') !== false) {
                if (!headers_sent()) {
                    header('Cache-Control: no-store, no-cache, private');
                    header_remove('Surrogate-Key');
                    header_remove('Cache-Tag');
                    header_remove('Edge-Cache-Tag');
                }
                return $html;
            }


            # Process the HTML with OTTO modifications
            $modified_html = self::apply_otto_modifications($html);


            if ($modified_html !== false && !empty($modified_html)) {
                # Validate the modified HTML isn't corrupted
                if (strlen($modified_html) > strlen($html) * 0.5) {
                    # Modified HTML is at least 50% of original size - seems valid

                    # DEBUG: Check if title was actually changed in the HTML
                    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $modified_html, $matches)) {
                    }

                    return $modified_html;
                } else {
                    # The rewrite ran but the result is less than half the size of
                    # the input, so it is assumed to be mangled and discarded. This
                    # guard is deliberate, but it means a correct-looking request
                    # still serves un-optimized markup — and until now it did so
                    # silently, with normal cache headers, so a caching layer could
                    # store it. Report it and keep it out of any cache.
                    self::handle_render_failure(
                        'RENDER_DISCARDED',
                        'OTTO rewrite output failed the 50% size check and was discarded',
                        [
                            'original_bytes' => strlen($html),
                            'result_bytes'   => strlen($modified_html),
                        ],
                        'warning'
                    );
                }
            } else {
                # apply_otto_modifications() returned false: either its
                # prerequisites were missing or it caught an exception internally.
                self::handle_render_failure(
                    'RENDER_NO_OUTPUT',
                    'OTTO rewrite produced no output',
                    ['original_bytes' => strlen($html)],
                    'warning'
                );
            }
        } catch (Exception $e) {
            error_log('[MetaSync OTTO] Render exception on ' . ($_SERVER['REQUEST_URI'] ?? 'unknown') . ': ' . $e->getMessage());
            self::handle_render_failure(
                'RENDER_EXCEPTION',
                'OTTO rewrite threw: ' . $e->getMessage(),
                ['exception' => get_class($e)],
                'error'
            );
        } catch (Error $e) {
            error_log('[MetaSync OTTO] Render error on ' . ($_SERVER['REQUEST_URI'] ?? 'unknown') . ': ' . $e->getMessage());
            self::handle_render_failure(
                'RENDER_EXCEPTION',
                'OTTO rewrite errored: ' . $e->getMessage(),
                ['error' => get_class($e)],
                'error'
            );
        }

        # Return original HTML on any failure - NEVER return empty string
        return $html;
    }

    /**
     * Handle a render failure that is about to return un-optimized HTML.
     *
     * Marks the response uncacheable and reports the failure. Split out so the
     * several exit points in process_buffer() behave identically.
     *
     * Both helpers are looked up with function_exists() because this is a static
     * output-buffer callback and may run in contexts where otto_pixel.php's
     * function definitions are not loaded.
     *
     * Note on timing: this runs inside the output-buffer callback, so the response
     * body has been captured but not yet flushed — headers are still settable, and
     * headers_sent() is still false. The one case this cannot cover is a hard fatal
     * or OOM kill, where PHP dies without running any cleanup code at all.
     *
     * @param string $reason  Machine-readable failure reason.
     * @param string $message Human-readable description.
     * @param array  $context Extra context for the report.
     * @param string $level   'error' or 'warning'.
     */
    private static function handle_render_failure($reason, $message, $context = [], $level = 'error') {
        # An unprocessable document is not a failure to retry — it will exceed the
        # budget on every request. Capping its cache lifetime would make the most
        # expensive page on the site permanently uncacheable and re-report it every
        # throttle window forever, so leave it fully cacheable. log_oversized_skip()
        # has already written a line explaining the skip.
        if (self::document_was_unprocessable()) {
            return;
        }

        if (function_exists('metasync_otto_limit_response_cache')) {
            metasync_otto_limit_response_cache($reason);
        }

        if (function_exists('metasync_otto_report_render_failure')) {
            $context['render_method'] = self::$current_method ?? 'buffer';
            metasync_otto_report_render_failure($reason, $message, $context, $level);
        }
    }

    /**
     * Apply OTTO modifications to HTML
     * 
     * @param string $html Original HTML
     * @return string|false Modified HTML or false on failure
     */
    private static function apply_otto_modifications($html) {
        # Validate prerequisites
        if (!self::$otto_html || !self::$pending_suggestions) {
            return false;
        }

        # Verify the OTTO HTML processor has the required method
        if (!method_exists(self::$otto_html, 'process_html_directly')) {
            return false;
        }

        try {
            # Add blocking flags to suggestions
            $suggestions = self::$pending_suggestions;
            if (!empty(self::$blocking_flags)) {
                $suggestions['_otto_blocking'] = self::$blocking_flags;
            }

            # Process HTML directly (no HTTP request needed!)
            $result = self::$otto_html->process_html_directly($html, $suggestions);

            if ($result) {
                # Result is now an HTML string (not DOM object)
                # Check if it's a string or still a DOM object for backwards compatibility
                $result_string = is_string($result) ? $result : $result->__toString();

                # Apply any post-processing fixes
                $result_string = self::apply_post_processing($result_string);

                return $result_string;
            }
        } catch (Exception $e) {
            error_log('[MetaSync OTTO] Modification exception on ' . ($_SERVER['REQUEST_URI'] ?? 'unknown') . ': ' . $e->getMessage());
        } catch (Error $e) {
            error_log('[MetaSync OTTO] Modification error on ' . ($_SERVER['REQUEST_URI'] ?? 'unknown') . ': ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Apply post-processing fixes to modified HTML
     * 
     * @param string $html Modified HTML
     * @return string Processed HTML
     */
    private static function apply_post_processing($html) {
        # Fix for sliding headline layouts
        if (strpos($html, 'pix-sliding-headline-2') !== false || strpos($html, 'pix-intro-sliding-text') !== false) {
            $html = preg_replace(
                '#(</span></span>)(<span\s+class=["\'][^"\']*slide-in-container[^"\']*["\'][^>]*>)#i',
                '$1 $2',
                $html
            );
        }
        
        # Fix for divi-pixel Timeline compatibility
        # jQuery's .data() method auto-parses JSON, but Timeline script tries to JSON.parse it again
        # This causes "[object Object]" is not valid JSON errors
        # Solution: Inject a script that patches jQuery's .data() to return strings for data-config
        if (strpos($html, 'dipi_timeline_item_custom_classes') !== false && strpos($html, 'Timeline.min.js') !== false) {
            # Create a script that ensures data-config is read as a string, not auto-parsed object
            $fix_script = '<script type="text/javascript">' . "\n" .
                '(function() {' . "\n" .
                '    if (typeof jQuery !== "undefined") {' . "\n" .
                '        // Patch jQuery.data() to return string for data-config on timeline items' . "\n" .
                '        var originalData = jQuery.fn.data;' . "\n" .
                '        jQuery.fn.data = function(key, value) {' . "\n" .
                '            // If reading "config" from timeline items, return raw string from attribute' . "\n" .
                '            if (key === "config" && value === undefined && this.length > 0) {' . "\n" .
                '                var $first = jQuery(this[0]);' . "\n" .
                '                if ($first.hasClass("dipi_timeline_item_custom_classes")) {' . "\n" .
                '                    var attrValue = $first.attr("data-config");' . "\n" .
                '                    if (attrValue) {' . "\n" .
                '                        // Decode HTML entities and return as string' . "\n" .
                '                        var tempDiv = document.createElement("div");' . "\n" .
                '                        tempDiv.innerHTML = attrValue;' . "\n" .
                '                        return tempDiv.textContent || tempDiv.innerText || attrValue;' . "\n" .
                '                    }' . "\n" .
                '                }' . "\n" .
                '            }' . "\n" .
                '            // For all other cases, use original jQuery.data()' . "\n" .
                '            return originalData.apply(this, arguments);' . "\n" .
                '        };' . "\n" .
                '    }' . "\n" .
                '})();' . "\n" .
                '</script>';
            
            # Insert before closing body tag (before Timeline script runs)
            $html = preg_replace(
                '/(<\/body>)/i',
                $fix_script . "\n" . '$1',
                $html,
                1
            );
        }

        # Re-inject Divi's module-design CSS if missing from buffer output.
        # SG Optimizer's parser strips late-injected CSS from the buffer. We read it
        # from Divi's page resource manager (available in the current WP context) and
        # inject it before </body>. This preserves correct module numbering (buffer
        # approach) while ensuring the CSS is present.
        if (strpos($html, 'et-builder-module-design') === false
            && function_exists('et_core_page_resource_get')
        ) {
            $post_id = get_the_ID();
            if ($post_id) {
                $resource_slug = et_theme_builder_decorate_page_resource_slug($post_id, 'module-design');
                $manager = et_core_page_resource_get('builder', $resource_slug, $post_id, 40);
                if ($manager && method_exists($manager, 'get_data')) {
                    $css_data = $manager->get_data('inline');
                    if (!empty($css_data)) {
                        $style_tag = '<style id="et-builder-' . esc_attr($resource_slug) . '-cached-inline-styles">'
                            . wp_strip_all_tags($css_data) . '</style>';
                        $html = str_replace('</body>', $style_tag . "\n" . '</body>', $html);
                    }
                }
            }
        }

        return $html;
    }

    /**
     * Shutdown handler to ensure buffer is properly processed
     */
    public static function shutdown_handler() {
        if (!self::$buffer_active) {
            return;
        }

        # Get any remaining buffered content
        $remaining_levels = ob_get_level() - self::$buffer_start_level;
        
        while ($remaining_levels > 0) {
            ob_end_flush();
            $remaining_levels--;
        }

        self::$buffer_active = false;
    }

    /**
     * Get the current render method being used
     * 
     * @return string Current method (buffer, http, none)
     */
    public static function get_current_method() {
        return self::$current_method ?: self::METHOD_NONE;
    }

    /**
     * Set the current render method (for HTTP fallback)
     * 
     * @param string $method The method being used
     */
    public static function set_current_method($method) {
        self::$current_method = $method;
    }

    /**
     * Non-alarming cache-status wording for the diagnostic header.
     *
     * RATE_LIMITED and API_ERROR both describe normal operation on a healthy
     * site — the plugin's own call budget was reached, or OTTO has nothing for
     * this URL — but they read as site failures and prompt false-alarm reports.
     * Softened here, at emission only: the internal status strings that the
     * cache and its tests rely on are never rewritten.
     *
     * @param string $cache_status Internal cache status
     * @return string Wording to put on the wire
     */
    public static function display_cache_status($cache_status) {
        $soft_wording = array(
            'RATE_LIMITED' => 'THROTTLED',
            'API_ERROR'    => 'SOURCE_UNAVAILABLE',
        );

        return $soft_wording[$cache_status] ?? $cache_status;
    }

    /**
     * Whether the X-MetaSync-OTTO-* diagnostic headers should be emitted
     *
     * These headers are observability-only — nothing in the plugin or the
     * SearchAtlas backend reads them — so they stay off on public responses and
     * are only sent while an operator has Debug Mode on. That keeps internal
     * render/cache state (including values like RATE_LIMITED and API_ERROR,
     * which read as failures on a perfectly healthy site) and the hardcoded
     * product name on whitelabel installs off every front-end response.
     *
     * @return bool True when the diagnostic headers should be sent
     */
    public static function diagnostics_enabled() {
        return class_exists('Metasync_Debug_Mode_Manager')
            && Metasync_Debug_Mode_Manager::is_enabled();
    }

    /**
     * Send response headers indicating render method and status
     *
     * @param string $cache_status Cache status (HIT, MISS, etc.)
     */
    public static function send_headers($cache_status = '') {
        if (headers_sent()) {
            return;
        }

        # Check if WP Rocket is active — drives both the diagnostic header below
        # and the Cache-Control decision further down
        $wp_rocket_active = class_exists('WP_Rocket');

        # Diagnostic headers: observability only, so gated behind Debug Mode.
        # Everything after this block is functional (browser/CDN caching) and
        # must keep running whether or not Debug Mode is on.
        if (self::diagnostics_enabled()) {
            # OTTO Render Method header
            $method = self::get_current_method();
            if ($method === self::METHOD_BUFFER) {
                $method_label = 'BUFFER';
            } elseif ($method === self::METHOD_WP_ROCKET) {
                $method_label = 'WP_ROCKET_BUFFER'; // WP Rocket caches OTTO-modified HTML
            } else {
                $method_label = 'HTTP';
            }
            header('X-MetaSync-OTTO-Method: ' . $method_label);

            # OTTO Cache Status header
            if (!empty($cache_status)) {
                header('X-MetaSync-OTTO-Cache: ' . self::display_cache_status($cache_status));
            }

            # OTTO Processed indicator
            header('X-MetaSync-OTTO-Processed: true');

            # Add compatibility indicator
            if ($wp_rocket_active) {
                header('X-MetaSync-OTTO-WPRocket: Compatible');
            }
        }

        # Browser cache header only — let hosting (Kinsta, WP Engine, etc.) manage CDN TTL natively.
        # Do NOT set s-maxage: it overrides hosting-level CDN purges (kinsta_cache_purge_full,
        # WpeCommon::purge_varnish_cache) and prevents OTTO updates from appearing after a cache clear.
        # Only set if WP Rocket is NOT active (let WP Rocket control cache headers)
        if (!is_user_logged_in() && !$wp_rocket_active) {
            $cache_duration = 3600; // 1 hour for browsers

            # only the HTTP fallback path must force `private`. That path serves an
            # internally-fetched copy of the page (handle_route_html) and keeps ONLY the body —
            # the response `Set-Cookie` (e.g. PHPSESSID from Formidable etc.) is discarded — so the
            # host/CDN cannot auto-skip caching form/session pages, and a shared copy would re-leak
            # nonces across visitors (the regression).
            #
            # On the BUFFER path the visitor's own request renders the page, so its real `Set-Cookie`
            # IS on the response; the host/CDN already auto-skips caching any response that sets a
            # cookie, so form/session pages stay protected without us forcing `private`. Forcing it
            # there blocked host/CDN caching site-wide → uncached PHP render on every hit → CPU spikes
            # and slow first-load. So we let those pages be cached normally (no `private`).
            if (self::get_current_method() === self::METHOD_HTTP) {
                header('Cache-Control: private, max-age=' . $cache_duration);
            }

            # Do NOT emit `Vary: Accept-Encoding` here. Compression is a transport
            # concern owned by the web server / compression module (mod_deflate,
            # nginx `gzip_vary`, Cloudflare), which already appends this header
            # whenever it gzip/brotli-encodes the response. OTTO's HTML does not
            # vary by Accept-Encoding at the application level, so emitting it from
            # PHP was purely redundant — and on hosts where the server ALSO sets it
            # (the common case) the response carried a duplicate/merged
            # `Vary: Accept-Encoding`, which makes Cloudflare APO and similar edge
            # caches treat the page as non-cacheable and send every visitor to
            # origin PHP. Leaving it to the compression layer yields exactly one,
            # correct Vary. If a stricter Vary is ever required, add it through a
            # single deduplicated writer, never a blind second `header()` call.
        }

        # Cache-tag headers for CDN purge-by-tag (Phase 4).
        # Only emitted on OTTO-processed singular pages when cache_tags_enabled is on.
        if (is_singular()) {
            $post_id = get_queried_object_id();
            if ($post_id > 0) {
                $edge_options = class_exists('Metasync_Edge_Cache_Settings')
                    ? Metasync_Edge_Cache_Settings::get_settings()
                    : get_option('metasync_edge_cache_options', array());
                if (!empty($edge_options['cache_tags_enabled'])) {
                    $tag = 'metasync-post-' . $post_id;
                    header('Cache-Tag: ' . $tag);           // Cloudflare
                    header('Surrogate-Key: ' . $tag);       // Fastly / Pantheon
                    header('Edge-Cache-Tag: ' . $tag);      // Akamai
                }
            }
        }

    }

    /**
     * Check if buffer method is currently active
     * 
     * @return bool
     */
    public static function is_buffer_active() {
        return self::$buffer_active;
    }

    /**
     * Cancel buffer processing (for fallback to HTTP)
     */
    public static function cancel_buffer() {
        if (self::$buffer_active) {
            # End our buffer without processing
            if (ob_get_level() > self::$buffer_start_level) {
                ob_end_flush();
            }
            self::$buffer_active = false;
            self::$current_method = self::METHOD_HTTP;
        }
    }

    /**
     * Get diagnostic information about current environment
     * 
     * @return array Diagnostic data
     */
    public static function get_diagnostics() {
        return [
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'memory_limit' => ini_get('memory_limit'),
            'memory_used' => size_format(memory_get_usage(true)),
            'buffer_level' => ob_get_level(),
            'headers_sent' => headers_sent(),
            'is_admin' => is_admin(),
            'is_ajax' => wp_doing_ajax(),
            'is_logged_in' => is_user_logged_in(),
            'current_method' => self::get_current_method(),
            'buffer_active' => self::$buffer_active,
            'detected_plugins' => [
                'wp_rocket' => class_exists('WP_Rocket'),
                'w3tc' => defined('W3TC'),
                'litespeed' => defined('LSCWP_V'),
                'autoptimize' => class_exists('autoptimizeMain'),
                'brizy' => class_exists('Brizy_Editor'),
            ],
            'detected_hosts' => [
                'wp_engine' => defined('WPE_APIKEY') || getenv('IS_WPE'),
                'kinsta' => defined('KINSTAMU_VERSION') || getenv('KINSTA_CACHE_ZONE'),
                'cloudways' => isset($_SERVER['HTTP_X_VARNISH']),
                'flywheel' => defined('FLYWHEEL_CONFIG_DIR'),
            ],
        ];
    }

    /**
     * Is this request path something OTTO can never have suggestions for?
     *
     * Covers two families:
     *
     *  - Protocol paths under /.well-known/ (ACME challenges, Cloudflare
     *    custom-hostname challenges). Such a challenge path was observed in
     *    production triggering a live, blocking OTTO API call.
     *  - Static assets. These normally never reach PHP, but they do on sites
     *    whose rewrites send missing files through WordPress.
     *
     * Both patterns are deliberately bounded. The /.well-known/ match requires a
     * separator or end-of-string so a page slug that merely shares the prefix
     * (/.well-knownsecrets/) is not swallowed, and the asset match is anchored
     * at the end so a directory URL (/style.css/) or a non-final extension
     * (/a.css.backup) stays OTTO-able.
     *
     * Deliberately does NOT cover robots.txt or favicon.ico: WordPress has exact
     * conditionals for those, and a path pattern would misfire on real content
     * such as /robots.txt-explained/.
     *
     * Deliberately typed loosely: the caller passes strtok($uri, '?'), which
     * returns string|false, and the underlying $_SERVER value is not guaranteed
     * to be a string at all. The guard below is therefore load-bearing, not
     * defensive noise — preg_match() on a non-string would raise a TypeError.
     *
     * @param mixed $request_path Request path, query string already stripped.
     * @return bool True when OTTO should be skipped for this request.
     */
    public static function is_non_content_path($request_path) {
        if (empty($request_path) || !is_string($request_path)) {
            return false;
        }

        if (preg_match('#^/\\.well-known(?:/|$)#i', $request_path)) {
            return true;
        }

        return (bool) preg_match(
            '#\\.(?:css|js|json|txt|map|ico|png|jpe?g|gif|svg|webp|avif|woff2?|ttf|eot|pdf|zip|mp4|webm)$#i',
            $request_path
        );
    }
}


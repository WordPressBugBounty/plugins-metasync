<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * This class handles the Otto Pixel Functions
 */
Class Metasync_otto_pixel{
    
    #otto html class
    public $o_html;

    # crawl data option
    public $option_name = 'metasync_otto_crawldata';

	# no cache wp pages 
	public $no_cache_pages = ['wp-login.php'];

    # OTTO UUID
    private $otto_uuid;

    # 
    function __construct($otto_uuid){
        
        # store the UUID
        $this->otto_uuid = $otto_uuid;
        
        # load the html class
        $this->o_html = new Metasync_otto_html($otto_uuid);

    }

    # method to handle cache refresh
    # NOTE: Cache system removed - always process in real-time
    function refresh_cache($route){
        # No-op: Cache system has been removed
        # All pages are processed in real-time from OTTO API
        return true;
    }


    # method to save crawl data into
    function save_crawl_data($data){

        # get the option name 
        $option_name = $this->option_name;

        # handle data
        $saved = get_option($option_name);

        # log saved

        # if saved false save
        if(empty($saved['urls'])){
            
            # save the option
            update_option($option_name, $data);

            return;
        }

        # get new unique list of urls
        $new_list = array_unique(array_merge($data['urls'], $saved['urls']));

        # set data
        $data['urls'] = $new_list;

        # log saved

        # save the option 
        update_option($option_name, $data);
    }

    /**
     * Check if a URL has been crawled by Otto
     * - Validates domain against saved domain
     * - Ignores query strings
     * - Does exact path matching as Otto stores paths exactly as they appear
     * @param string $url - Full URL to check
     * @return bool - True if URL was crawled, false otherwise
     */
     function is_url_crawled($url) {
        # Ensure valid input
        if (empty($url) || !is_string($url)) {
            return false;
        }

        # Get saved crawl data
        $saved = get_option($this->option_name);

        if (
            empty($saved) ||
            !is_array($saved) ||
            empty($saved['domain']) ||
            empty($saved['urls']) ||
            !is_array($saved['urls'])
        ) {
            return false;
        }

        # Parse saved domain + incoming URL
        $saved_domain   = parse_url($saved['domain'], PHP_URL_HOST);
        $incoming_domain = parse_url($url, PHP_URL_HOST);

        # Domain mismatch - not crawled
        if (empty($saved_domain) || empty($incoming_domain) || strcasecmp($saved_domain, $incoming_domain) !== 0) {
            return false;
        }

        # Parse incoming path (ignore query string)
        $parsed_url = parse_url($url);
        $url_path   = $parsed_url['path'] ?? '/';

        # Ensure path starts with / but don't modify trailing slashes
        # Otto stores paths exactly as they appear in URLs
        if (substr($url_path, 0, 1) !== '/') {
            $url_path = '/' . $url_path;
        }

        # Compare against crawled URLs - exact match
        foreach ($saved['urls'] as $crawled_url) {
            if (!is_string($crawled_url)) {
                continue;
            }

            # Direct comparison - Otto stores paths exactly as they are
            if (strcasecmp($crawled_url, $url_path) === 0) {
                return true;
            }
        }

        return false;
    }

    # get the current route
    function get_route(){

         # check if we're in an HTTP context
        if(empty($_SERVER['HTTP_HOST']) || empty($_SERVER['REQUEST_URI'])){
            # not in HTTP context (CLI, cron, etc.), return false
            return false;
        }

        # get req scheme
        $scheme = ( is_ssl() ? 'https' : 'http' );

        # get req host
        $host = $_SERVER['HTTP_HOST'];

        # get the uri — strip query string before building the route.
        # OTTO suggestions are canonical-URL-based; query parameters (Kinsta cache-bypass
        # tokens, UTM params, tracking params, etc.) never produce different OTTO content.
        # Stripping them ensures that:
        #  - The transient populated via a Kinsta BYPASS request (always has ?params) is
        #    shared with the clean-URL MISS request (no params).
        #  - The OTTO API is called with the canonical URL, not a noisy variant.
        $request_uri = strtok($_SERVER['REQUEST_URI'], '?') ?: $_SERVER['REQUEST_URI'];

        # return the formatted url
        return $scheme . '://' . $host . $request_uri;
    }

    # get the html for a route 
    # OPTION 1 IMPLEMENTATION: Use transient cache for suggestions
    function get_route_html($route, $cache_track_key = null, $suggestions = null){
        # If suggestions already provided (from render_route_html), use them directly
        if ($suggestions !== null && is_array($suggestions)) {
            # Process route with provided suggestions data
            return $this->o_html->process_route_with_data($route, $suggestions, '');
        }
        
        # Get OTTO UUID from options
        global $metasync_options;
        $otto_uuid = $metasync_options['general']['otto_pixel_uuid'] ?? '';
        
        if (empty($otto_uuid)) {
            return false;
        }
        
        # Get suggestions from transient cache (with API fallback)
        $transient_cache = new Metasync_Otto_Transient_Cache($otto_uuid);
        $track_key = $cache_track_key ?: md5($route);
        $suggestions = $transient_cache->get_suggestions($route, $track_key);
        
        if (!$suggestions || !$transient_cache->has_payload($suggestions)) {
            # No suggestions available
            return false;
        }
        
        # Process route with cached suggestions data
        return $this->o_html->process_route_with_data($route, $suggestions, '');
    }


    # render route html
    function render_route_html(){

        # Clear per-request statics before any path runs. Harmless under PHP-FPM
        # (the process ends with the request) but required under persistent-worker
        # SAPIs, where a latched flag would silently disable cache capping and
        # failure reporting for every later request in that worker.
        #
        # method_exists() is redundant to static analysis — this MR adds the method,
        # so PHPStan proves the call always true. It is kept for the upgrade window:
        # during a plugin update an opcache can still hold the previous
        # Metasync_Otto_Render_Strategy, where class_exists() passes but the method
        # is absent, and an unguarded static call would fatal the page.
        # @phpstan-ignore-next-line function.alreadyNarrowedType
        if (class_exists('Metasync_Otto_Render_Strategy') && method_exists('Metasync_Otto_Render_Strategy', 'reset_request_state')) {
            Metasync_Otto_Render_Strategy::reset_request_state();
        }

        # Disable SG Cache for Brizy pages FIRST - before any other processing
        # Using global function defined in otto_pixel.php
        if (function_exists('metasync_otto_disable_sg_cache_for_brizy')) {
            metasync_otto_disable_sg_cache_for_brizy();
        }

        # get the route
        $route = $this->get_route();

        # get the current page from globas
        # this is to help us exclude the login page

        $page_now = $GLOBALS['pagenow'] ?? false;

        # check whether page now in excluded pages
        if(in_array($page_now , $this->no_cache_pages)){

            # stop otto
            return;
        }

        /*
        #uncomment to test on local hosts
        if(!empty($_GET['otto_test'])){

            # @dev
            $route = 'https://staging-perm.wp65.qa.internal.searchatlas.com/';
        }
        */

        # OPTION 1 IMPLEMENTATION: Check transient cache instead of notification data
        # This makes the system self-healing - works even if notifications fail
        
        # Get OTTO UUID from options
        global $metasync_options;
        $otto_uuid = $metasync_options['general']['otto_pixel_uuid'] ?? '';
        
        if (empty($otto_uuid)) {
            return;
        }
        
        $transient_cache = new Metasync_Otto_Transient_Cache($otto_uuid);
        
        # Create tracking key for cache status
        $cache_track_key = 'otto_' . md5($route);
        
        # Check if URL has OTTO suggestions (checks transient, calls API if needed)
        # Use get_suggestions directly to track cache status
        $suggestions = $transient_cache->get_suggestions($route, $cache_track_key);
        
        if (!$suggestions || !$transient_cache->has_payload($suggestions)) {
            # No suggestions available for this URL.
            $cache_status = Metasync_Otto_Transient_Cache::get_cache_status($cache_track_key);

            # Two very different situations reach this point, and they must be
            # treated differently.
            #
            # A transient failure means the suggestions exist upstream but could
            # not be retrieved on this request — the API errored or timed out, the
            # per-minute budget was spent, or another worker held the lock. The
            # next request will probably succeed. Serving the un-optimized page
            # with normal cache headers lets a caching layer store it and hand it
            # to everyone until its TTL expires, so cap how long it may be kept.
            #
            # Everything else here is a legitimate answer: OTTO simply has nothing
            # for this URL (NO_SUGGESTIONS, including the 404 case). Most URLs on
            # most sites are in that state permanently, so capping those would
            # strip caching from the bulk of the site and move that load onto the
            # origin. Those stay fully cacheable.
            $transient_failures = ['RATE_LIMITED', 'LOCKED', 'API_ERROR'];

            if (in_array($cache_status, $transient_failures, true)
                && function_exists('metasync_otto_limit_response_cache')) {
                metasync_otto_limit_response_cache($cache_status);
            }

            # Set diagnostic headers only while Debug Mode is enabled.
            if (!headers_sent() && Metasync_Otto_Render_Strategy::diagnostics_enabled()) {
                header('X-MetaSync-OTTO-Cache: ' . Metasync_Otto_Render_Strategy::display_cache_status($cache_status ?: 'NO_SUGGESTIONS'));
                header('X-MetaSync-OTTO-Method: NONE');
            }
            return;
        }

        # comment out for fixing pagination issues
        # $route = rtrim($route, '/');

        # Now that OTTO has confirmed suggestions to apply to this URL,
        # disable SiteGround SG Optimizer page caching for THIS request only.
        # Doing it here (instead of unconditionally on the `wp` hook) ensures
        # pages without OTTO suggestions keep being served from SG's page cache.
        if (function_exists('metasync_otto_disable_sg_page_cache')) {
            metasync_otto_disable_sg_page_cache();
        }

        # Analyze what OTTO is providing for SEO plugin blocking
        $blocking_flags = $this->analyze_otto_blocking($suggestions);

        # Get cache status for headers
        $cache_status = Metasync_Otto_Transient_Cache::get_cache_status($cache_track_key);

        # RENDER PRIORITY:
        # 1. WP Rocket rocket_buffer filter — WP Rocket caches OTTO-modified HTML,
        #    so Kinsta also caches the correct version. No exit(), no cache bypass.
        # 2. Output Buffer (fast) — for environments without WP Rocket.
        # 3. HTTP Request (last resort) — exits early, bypasses all caching.
        try {
            # Safety check: ensure render strategy class exists
            if (!class_exists('Metasync_Otto_Render_Strategy')) {
                # Class not loaded - use HTTP method as fallback
                $this->render_via_http($route, $suggestions, $cache_track_key, $cache_status);
                return;
            }

            # WP ROCKET PATH: highest priority when WP Rocket is active.
            # rocket_buffer fires inside WP Rocket's own cache pipeline, so the
            # cache file WP Rocket writes (and that Kinsta stores) is already
            # OTTO-modified. Eliminates the race where WP Rocket saved pre-OTTO HTML.
            if (class_exists('WP_Rocket')) {
                $wp_rocket_compat_mode = Metasync_Otto_Config::get_wp_rocket_compat_mode();
                if ($wp_rocket_compat_mode !== 'http') {
                    # disable_otto exits in metasync_start_otto() before we get here, so
                    # only 'http' compat mode needs to skip the rocket_buffer path.
                    $this->render_via_rocket_buffer($suggestions, $blocking_flags, $cache_status);
                    return;
                }
            }

            $render_method = Metasync_Otto_Render_Strategy::determine_method();

            if ($render_method === Metasync_Otto_Render_Strategy::METHOD_BUFFER) {
                # FAST PATH: Use output buffer approach
                $result = $this->render_via_buffer($route, $suggestions, $blocking_flags, $cache_status);

                if ($result === true) {
                    # Buffer is now active, WordPress will continue rendering
                    # OTTO modifications will be applied when buffer flushes
                    return;
                }

                # Buffer approach failed, fall back to HTTP method
                $render_method = Metasync_Otto_Render_Strategy::METHOD_HTTP;
            }

            if ($render_method === Metasync_Otto_Render_Strategy::METHOD_HTTP) {
                # FALLBACK PATH: Use traditional HTTP request approach
                $this->render_via_http($route, $suggestions, $cache_track_key, $cache_status);
            }
        } catch (Exception $e) {
            # Any exception in the render strategy - fall back to HTTP method
            $this->render_via_http($route, $suggestions, $cache_track_key, $cache_status);
        } catch (Error $e) {
            # PHP 7+ Error (like TypeError) - fall back to HTTP method
            $this->render_via_http($route, $suggestions, $cache_track_key, $cache_status);
        }
    }

    /**
     * Stop WP Rocket writing the current response to its disk cache.
     *
     * DONOTCACHEPAGE is not sufficient from inside the rocket_buffer filter: WP
     * Rocket reads that constant when it decides whether to buffer the request,
     * which has already happened by the time the filter runs. It evaluates
     * do_rocket_generate_caching_files immediately before writing the file, so
     * that is the only lever still effective at this point.
     *
     * This matters more than the response headers do. A page served from WP
     * Rocket's disk cache is delivered by rewrite rules without invoking PHP, so
     * once a bad file is on disk no header can override it — only deleting the
     * file or waiting out Rocket's own expiry will clear it.
     *
     * PHP_INT_MAX priority so a site's own filters cannot re-enable the write.
     */
    private static function block_rocket_cache_write() {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        add_filter('do_rocket_generate_caching_files', '__return_false', PHP_INT_MAX);
    }

    /**
     * Render page using WP Rocket's rocket_buffer filter (PREFERRED for WP Rocket sites)
     *
     * When WP Rocket is active, hooking into rocket_buffer ensures WP Rocket
     * caches the OTTO-modified HTML. This means:
     *  - WP Rocket's cache file  = OTTO-modified HTML ✓
     *  - Kinsta FastCGI cache    = WP Rocket output  = OTTO-modified HTML ✓
     *  - No exit()               = proper cache lifecycle ✓
     *
     * The filter fires on every uncached PHP request. Once WP Rocket has cached
     * the result, subsequent requests are served directly from cache with zero PHP.
     *
     * @param array  $suggestions    OTTO suggestions data
     * @param array  $blocking_flags SEO plugin blocking flags
     * @param string $cache_status   Cache status for X-MetaSync-* headers
     */
    private function render_via_rocket_buffer($suggestions, $blocking_flags, $cache_status) {
        $o_html = $this->o_html;

        # Hook into WP Rocket's HTML buffer at priority 1 so other rocket_buffer
        # filters (minifiers, CDN rewriters, etc.) run on already-OTTO-modified HTML.
        add_filter('rocket_buffer', function($html) use ($o_html, $suggestions, $blocking_flags) {
            if (empty($html) || strlen($html) < 100) {
                // Tell WP Rocket not to cache this empty/broken response. The
                // constant alone is too late here (see block_rocket_cache_write),
                // so the write-time filter is applied as well.
                self::block_rocket_cache_write();
                return $html;
            }

            # Only process full HTML documents — skip partials, JSON, error responses
            if (stripos($html, '<html') === false && stripos($html, '<!DOCTYPE') === false) {
                return $html;
            }

            # Pass blocking context to the HTML processor (suppresses Yoast/Rank Math tags
            # that OTTO is replacing, preventing duplicates in the final HTML)
            $data = $suggestions;
            if (!empty($blocking_flags)) {
                $data['_otto_blocking'] = $blocking_flags;
            }

            # From here on we hold usable suggestions, so any exit that returns
            # $html unmodified is a genuine failure: WP Rocket would write the
            # un-optimized markup to its cache file, and whatever sits in front of
            # it would then serve that copy. This is worse than the plain buffer
            # path, because the bad page is persisted on disk as well as at the
            # edge — and this route exists specifically to make the cached copy the
            # optimized one.
            #
            # Note that DONOTCACHEPAGE alone does NOT prevent that write. WP Rocket
            # evaluates the constant when it decides whether to buffer the page,
            # which has already happened by the time this filter runs. The
            # do_rocket_generate_caching_files filter is evaluated immediately
            # before the file is written, so it is the only lever still available
            # from here. A disk-cached page is served by rewrite rules without
            # invoking PHP, so no response header can undo it afterwards.
            try {
                $modified = $o_html->process_html_directly($html, $data);
                # Sanity check: modified HTML must be at least 50% the size of original
                if ($modified && strlen($modified) > strlen($html) * 0.5) {
                    return $modified;
                }

                # An unprocessable document (oversized / over the memory budget) is
                # a permanent property of the page, not a failure to retry. Leave it
                # fully cacheable — capping it would make the most expensive page on
                # the site uncacheable forever for no gain.
                if (class_exists('Metasync_Otto_Render_Strategy')
                    && Metasync_Otto_Render_Strategy::document_was_unprocessable()) {
                    return $html;
                }

                # Either the rewrite returned nothing usable, or the result was so
                # much smaller than the input that it is assumed to be mangled.
                self::block_rocket_cache_write();
                if (function_exists('metasync_otto_limit_response_cache')) {
                    metasync_otto_limit_response_cache('RENDER_DISCARDED_ROCKET');
                }
                if (function_exists('metasync_otto_report_render_failure')) {
                    metasync_otto_report_render_failure(
                        'RENDER_DISCARDED_ROCKET',
                        'OTTO rewrite produced no usable output on the WP Rocket path',
                        [
                            'original_bytes' => strlen($html),
                            'result_bytes'   => is_string($modified) ? strlen($modified) : 0,
                            'render_method'  => 'wp_rocket',
                        ],
                        'warning'
                    );
                }
            } catch (Exception $e) {
                // Fall through — return original HTML on any failure
                self::block_rocket_cache_write();
                if (function_exists('metasync_otto_limit_response_cache')) {
                    metasync_otto_limit_response_cache('RENDER_EXCEPTION_ROCKET');
                }
                if (function_exists('metasync_otto_report_render_failure')) {
                    metasync_otto_report_render_failure(
                        'RENDER_EXCEPTION_ROCKET',
                        'OTTO rewrite threw on the WP Rocket path: ' . $e->getMessage(),
                        ['render_method' => 'wp_rocket', 'exception' => get_class($e)],
                        'error'
                    );
                }
            } catch (Error $e) {
                // Fall through — return original HTML on any failure
                self::block_rocket_cache_write();
                if (function_exists('metasync_otto_limit_response_cache')) {
                    metasync_otto_limit_response_cache('RENDER_EXCEPTION_ROCKET');
                }
                if (function_exists('metasync_otto_report_render_failure')) {
                    metasync_otto_report_render_failure(
                        'RENDER_EXCEPTION_ROCKET',
                        'OTTO rewrite errored on the WP Rocket path: ' . $e->getMessage(),
                        ['render_method' => 'wp_rocket', 'error' => get_class($e)],
                        'error'
                    );
                }
            }

            return $html;
        }, 1);

        # Block SEO plugins before wp_head fires so duplicate tags aren't output
        $description_tags = $blocking_flags['block_description_tags'] ?? [];
        $has_description_tags = !empty($description_tags);
        if ($blocking_flags['block_title'] || $has_description_tags) {
            if (function_exists('metasync_otto_block_seo_plugins')) {
                metasync_otto_block_seo_plugins(
                    $blocking_flags['block_title'],
                    $has_description_tags,
                    $description_tags
                );
            }
        }

        # Send X-MetaSync-* diagnostic headers before WordPress outputs anything
        if (!headers_sent()) {
            Metasync_Otto_Render_Strategy::set_current_method(Metasync_Otto_Render_Strategy::METHOD_WP_ROCKET);
            Metasync_Otto_Render_Strategy::send_headers($cache_status);
        }

        # Return — WordPress continues its normal render lifecycle.
        # WP Rocket's buffer captures the full HTML, fires rocket_buffer,
        # our callback modifies it, and WP Rocket caches the OTTO version.
    }

    /**
     * Analyze OTTO suggestions to determine what to block from SEO plugins
     * 
     * @param array $suggestions OTTO suggestions data
     * @return array Blocking flags
     */
    private function analyze_otto_blocking($suggestions) {
        $has_otto_title = false;
        $otto_description_tags = []; // Track specific description tags Otto provides

        if (!empty($suggestions['header_replacements']) && is_array($suggestions['header_replacements'])) {
            foreach ($suggestions['header_replacements'] as $item) {
                if (!empty($item['type'])) {
                    # Check if OTTO has title
                    if ($item['type'] == 'title' && !empty($item['recommended_value'])) {
                        $has_otto_title = true;
                    }
                    # Check if OTTO has description - track specific tag types
                    if ($item['type'] == 'meta') {
                        # Check for meta[name=description]
                        if (!empty($item['name']) && $item['name'] == 'description' && !empty($item['recommended_value'])) {
                            $otto_description_tags[] = 'meta[name=description]';
                        }
                        # Check for meta[property=og:description]
                        if (!empty($item['property']) && $item['property'] == 'og:description' && !empty($item['recommended_value'])) {
                            $otto_description_tags[] = 'meta[property=og:description]';
                        }
                        # Check for meta[name=twitter:description]
                        if (!empty($item['name']) && $item['name'] == 'twitter:description' && !empty($item['recommended_value'])) {
                            $otto_description_tags[] = 'meta[name=twitter:description]';
                        }
                    }
                }
            }
        }

        # Check header_html_insertion for description
        # Must have a non-empty content value, otherwise Yoast would be blocked with nothing to replace it
        if (!empty($suggestions['header_html_insertion'])) {
            if (preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i', $suggestions['header_html_insertion'])) {
                $otto_description_tags[] = 'meta[name=description]';
            }
        }

        # Remove duplicates
        $otto_description_tags = array_unique($otto_description_tags);

        return [
            'block_title' => $has_otto_title,
            'block_description_tags' => $otto_description_tags, // Pass array of specific tags
        ];
    }

    /**
     * Render page using output buffer approach (FAST)
     * Eliminates the internal HTTP request by capturing WordPress output directly
     * 
     * @param string $route Current page route
     * @param array $suggestions OTTO suggestions data
     * @param array $blocking_flags SEO plugin blocking flags
     * @param string $cache_status Cache status for headers
     * @return bool True if buffer started successfully, false to fall back to HTTP
     */
    private function render_via_buffer($route, $suggestions, $blocking_flags, $cache_status) {
        # Try to start output buffer
        $buffer_started = Metasync_Otto_Render_Strategy::start_buffer(
            $suggestions,
            $route,
            $this->o_html,
            $blocking_flags
        );

        if (!$buffer_started) {
            # Buffer failed to start
            return false;
        }

        # Buffer is active - send headers now (before any output)
        if (!headers_sent()) {
            Metasync_Otto_Render_Strategy::send_headers($cache_status);
        }

        # Block SEO plugins if needed (for the buffered output)
        $description_tags = $blocking_flags['block_description_tags'] ?? [];
        $has_description_tags = !empty($description_tags);
        if ($blocking_flags['block_title'] || $has_description_tags) {
            if (function_exists('metasync_otto_block_seo_plugins')) {
                metasync_otto_block_seo_plugins(
                    $blocking_flags['block_title'],
                    $has_description_tags,
                    $description_tags
                );
            }
        }

        # Return true - WordPress will continue rendering, buffer will capture and process
        return true;
    }

    /**
     * Render page using HTTP request approach (FALLBACK)
     * Makes internal wp_remote_get request to fetch page HTML
     * 
     * @param string $route Current page route
     * @param array $suggestions OTTO suggestions data
     * @param string $cache_track_key Cache tracking key
     * @param string $cache_status Cache status for headers
     */
    private function render_via_http($route, $suggestions, $cache_track_key, $cache_status) {
        # Set method indicator
        Metasync_Otto_Render_Strategy::set_current_method(Metasync_Otto_Render_Strategy::METHOD_HTTP);

        # On Divi sites, skip OTTO for the first HTTP render per page
        # per 24h. OTTO's internal wp_remote_get creates corrupted CSS cache
        # files in et-cache/{post_id}/ (wrong server context). By returning
        # false once, WordPress renders the page normally — building correct
        # Divi CSS caches. Subsequent OTTO renders within 24h use those caches.
        # The transient stores the activation timestamp so it auto-invalidates
        # when the plugin is deactivated/reactivated.
        if (defined('ET_CORE_VERSION')) {
            $cache_key = 'otto_divi_css_fix_' . md5($route);
            $activated = get_option('metasync_activated_at', '');
            $cached = get_transient($cache_key);
            if ($cached === false || $cached !== $activated) {
                set_transient($cache_key, $activated, DAY_IN_SECONDS);
                # Deliberate one-off skip, and the next request renders correctly —
                # so cap how long this un-optimized copy may be cached rather than
                # letting a caching layer pin it for its full TTL.
                if (function_exists('metasync_otto_limit_response_cache')) {
                    metasync_otto_limit_response_cache('DIVI_FIRST_RENDER_SKIP');
                }
                return false;
            }
        }

        # check if we have the route html (pass suggestions to avoid duplicate API call)
        $route_html = $this->get_route_html($route, $cache_track_key, $suggestions);

        # check that route html is valid
        if(empty($route_html)){
            return false;
        }

        $route_html_string = is_string($route_html) ? $route_html : $route_html->__toString();

        # Detect incomplete Divi rendering in OTTO's HTTP render output.
        # OTTO's internal wp_remote_get runs in a different server context than the
        # normal page load. This can cause Divi to produce incomplete output:
        #
        # 1. Cold CSS cache: Divi outputs inline <style> blocks instead of external
        #    <link> tags (TB CSS files not yet generated)
        # 2. Missing Google Fonts: Divi's font enqueue depends on request context
        #    (cookies, headers) that differ in the internal fetch
        #
        # If OTTO serves this incomplete HTML via exit(), SG Optimizer caches it —
        # breaking CSS for all visitors until manual cache purge.
        #
        # Fix: skip OTTO for this request (return false), letting WordPress render
        # normally. This builds Divi's caches so the next OTTO request works correctly.
        #
        # Both checks below are self-resolving — the next render finds warm caches —
        # so the un-optimized copy served now gets a capped cache lifetime rather
        # than being pinned for a caching layer's full TTL.
        #
        # The remaining fail-open exits on this path are deliberately NOT capped.
        # They mean the site could not fetch its own page (blocked loopback request,
        # HTTP Basic Auth, a firewall rejecting the internal user-agent) or that the
        # document cannot be processed at all. Those are whole-site, permanent
        # conditions: OTTO cannot work on any page, so capping cache lifetime would
        # rescue nothing while moving the entire site's traffic to the origin.
        if (defined('ET_CORE_VERSION')) {
            # Check 1: Cold TB CSS cache (inline styles instead of external link)
            if (preg_match('/<style\s[^>]*id=["\']et-core-unified-tb-[^"\']*-cached-inline-styles["\']/', $route_html_string)) {
                if (function_exists('metasync_otto_limit_response_cache')) {
                    metasync_otto_limit_response_cache('DIVI_COLD_CSS_CACHE');
                }
                return false;
            }
            # Check 2: Missing Google Fonts CSS (Divi enqueues this on every page)
            # Normal render has: <link ... id='et-builder-googlefonts-cached-css' ...>
            # or <link ... id='et-builder-googlefonts-css' ...>
            # If neither is present, the internal fetch didn't load fonts properly.
            if (strpos($route_html_string, 'et-builder-googlefonts') === false
                && strpos($route_html_string, 'fonts.googleapis.com') === false
            ) {
                if (function_exists('metasync_otto_limit_response_cache')) {
                    metasync_otto_limit_response_cache('DIVI_MISSING_FONTS');
                }
                return false;
            }
        }

        if (strpos($route_html_string, 'pix-sliding-headline-2') !== false || strpos($route_html_string, 'pix-intro-sliding-text') !== false) {
            # Only apply fix within sliding text contexts to avoid breaking other layouts
            $route_html_string = preg_replace('#(</span></span>)(<span\s+class=["\'][^"\']*slide-in-container[^"\']*["\'][^>]*>)#i', '$1 $2', $route_html_string);
        }

        # Fix for Elementor widgets - preserve whitespace between inline spans
        # This prevents text/elements from appearing merged when HTML is minified
        
        # Fix for Elementor social icons (elementor-grid-item)
        if (strpos($route_html_string, 'elementor-social-icons-wrapper') !== false) {
            # Add whitespace between closing and opening span tags within elementor-grid-item
            $route_html_string = preg_replace(
                '#(</span>)(<span\s+class=["\'][^"\']*elementor-grid-item[^"\']*["\'][^>]*>)#i',
                '$1 $2',
                $route_html_string
            );
        }
        
        # Fix for Elementor animated headline (elementor-headline-text-wrapper)
        if (strpos($route_html_string, 'elementor-headline') !== false) {
            # Add whitespace between closing and opening span tags with elementor-headline-text-wrapper
            $route_html_string = preg_replace(
                '#(</span>)(<span\s+class=["\'][^"\']*elementor-headline-text-wrapper[^"\']*["\'][^>]*>)#i',
                '$1 $2',
                $route_html_string
            );
        }
        
        # Check for Revolution Slider to determine if special handling is needed
        # Check for both Revolution Slider 6 (<rs-module-wrap>) and Revolution Slider 7 (<sr7-module>)
        $has_revslider = (strpos($route_html_string, '<rs-module-wrap') !== false || strpos($route_html_string, '<sr7-module') !== false);
        
        if($has_revslider){
            # Revolution Slider detected - fire WordPress hooks to ensure proper initialization
            # Use output buffering to prevent hooks from corrupting Otto's processed HTML
            ob_start();
            do_action('wp_enqueue_scripts');
            $discarded_output = ob_get_clean();
           
        }
        
        # If Divi's module-design CSS is missing from OTTO output,
        # fetch it directly and inject it. This handles the case where the
        # internal wp_remote_get response was truncated by SG Optimizer's parser.
        if (strpos($route_html_string, 'et-builder-module-design') === false
            && function_exists('et_theme_builder_decorate_page_resource_slug')
            && function_exists('et_core_page_resource_get')
        ) {
            # Try to get Divi's inline CSS from its page resource manager
            $post_id = get_the_ID();
            if ($post_id) {
                $resource_slug = et_theme_builder_decorate_page_resource_slug($post_id, 'module-design');
                $manager = et_core_page_resource_get('builder', $resource_slug, $post_id, 40);
                if ($manager && method_exists($manager, 'get_data')) {
                    $css_data = $manager->get_data('inline');
                    if (!empty($css_data)) {
                        $style_tag = '<style id="et-builder-' . esc_attr($resource_slug) . '-cached-inline-styles">'
                            . wp_strip_all_tags($css_data) . '</style>';
                        $route_html_string = str_replace('</body>', $style_tag . "\n" . '</body>', $route_html_string);
                    }
                }
            }
        }

        # Send response headers
        Metasync_Otto_Render_Strategy::send_headers($cache_status);

        # continue to render the html
        echo $route_html_string;

        # prevent further wp execution
        exit();
    }

}

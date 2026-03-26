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
            # No suggestions available for this URL
            # Set header to indicate cache status
            if (!headers_sent()) {
                $cache_status = Metasync_Otto_Transient_Cache::get_cache_status($cache_track_key);
                header('X-MetaSync-OTTO-Cache: ' . ($cache_status ?: 'NO_SUGGESTIONS'));
                header('X-MetaSync-OTTO-Method: NONE');
            }
            return;
        }

        # comment out for fixing pagination issues
        # $route = rtrim($route, '/');
        
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

            try {
                $modified = $o_html->process_html_directly($html, $data);
                # Sanity check: modified HTML must be at least 50% the size of original
                if ($modified && strlen($modified) > strlen($html) * 0.5) {
                    return $modified;
                }
            } catch (Exception $e) {
                // Fall through — return original HTML on any failure
            } catch (Error $e) {
                // Fall through — return original HTML on any failure
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

        # check if we have the route html (pass suggestions to avoid duplicate API call)
        $route_html = $this->get_route_html($route, $cache_track_key, $suggestions);

        # check that route html is valid
        if(empty($route_html)){
            return false;
        }

        $route_html_string = is_string($route_html) ? $route_html : $route_html->__toString();
        
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
        
        # Send response headers
        Metasync_Otto_Render_Strategy::send_headers($cache_status);

        # continue to render the html
        echo $route_html_string;

        # prevent further wp execution
        exit();
    }

}
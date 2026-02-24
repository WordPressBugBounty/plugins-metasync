<?php 
/**
 * This handles the OTTO SSR
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

# uses the simple html dom library
use simplehtmldom\HtmlDocument;

# include the otto class file
require_once plugin_dir_path( __FILE__ ) . '/vendor/autoload.php';
require_once plugin_dir_path( __FILE__ ) . '/Otto_html_class.php';
require_once plugin_dir_path( __FILE__ ) . '/Otto_pixel_class.php';
require_once plugin_dir_path( __FILE__ ) . '/metasync-otto-seo-functions.php';
require_once plugin_dir_path( __FILE__ ) . '/class-metasync-otto-transient-cache.php';
require_once plugin_dir_path( __FILE__ ) . '/class-metasync-otto-render-strategy.php';
require_once plugin_dir_path( __FILE__ ) . '/class-metasync-otto-config.php';

# OPTIMIZED: get the metasync options (cached in static class)
$metasync_options = Metasync_Otto_Config::get_options();

# OTTO SSR is always enabled by default
$otto_enabled = true;

# add tag to wp head
add_action('wp_head', function(){
    # load globals
    global $metasync_options, $otto_enabled;

    # OTTO SSR is always enabled
    $string_enabled = 'true';

    # OPTIMIZED: check uuid set using cached config
    if(!Metasync_Otto_Config::is_otto_enabled()){
        return;
    }

    # Performance optimization: Add DNS prefetch and preconnect for OTTO API
    # This improves connection speed by resolving DNS and establishing connections early
    # Use endpoint manager to get the correct domain
    $otto_domain = 'sa.searchatlas.com'; # default
    if (class_exists('Metasync_Endpoint_Manager')) {
        $otto_api_domain = Metasync_Endpoint_Manager::get_endpoint('OTTO_API_DOMAIN');
        $parsed = parse_url($otto_api_domain);
        if (!empty($parsed['host'])) {
            $otto_domain = $parsed['host'];
        }
    }
    echo '<link rel="dns-prefetch" href="//' . esc_attr($otto_domain) . '">' . "\n";
    echo '<link rel="preconnect" href="https://' . esc_attr($otto_domain) . '" crossorigin>' . "\n";

    # adding the otto tag to pages
    $plugin_version = defined('METASYNC_VERSION') ? METASYNC_VERSION : 'unknown';
    # OPTIMIZED: use cached uuid
    $otto_tag = '<meta name="otto" content="uuid='.esc_attr(Metasync_Otto_Config::get_otto_uuid()).'; type=wordpress; enabled='.esc_attr($string_enabled).'; version='.esc_attr($plugin_version).'">';

    # out the otto tag
    echo $otto_tag;
}, 1); # Priority 1 to output early in head

/**
 * Start end point to handle requests on page updates
 * The Otto Crawler will call this end point once a page is updated
 **/

# function to register the route
function metasync_otto_crawl_notify($request){
    
    # get request data params
    $data = $request->get_json_params();

    # fields
    $fields = ['domain', 'urls'];

    # validate the json request
    foreach ($fields as $key => $value) {

        # if the value is empty stop tehre
        if(empty($data[$value])){

            # Handle the POST request
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid Field : '. $value,
            ), 400);
        }

    }

    # load otto pixel
    $otto_pixel = new Metasync_otto_pixel(false);

    # save the otto data first
    $otto_pixel->save_crawl_data($data);

    # now we have the data sets
    foreach($data['urls'] AS $key => $url){
        # prepare the route
        $route = $data['domain'] . $url;

        # validate the route
        $route = rtrim($route, '/');

        # Skip excluded URLs - don't process OTTO data for them
        if (metasync_is_otto_url_excluded($route)) {
            // error_log('MetaSync OTTO: Skipping excluded URL: ' . $route);
            continue;
        }

        # OPTION 1 IMPLEMENTATION: Invalidate and warm transient cache when notification received
        # This ensures fresh suggestions are cached immediately when OTTO sends updates
        # OPTIMIZED: Use cached options
        $otto_uuid = Metasync_Otto_Config::get_otto_uuid();
        if (!empty($otto_uuid)) {
            $transient_cache = new Metasync_Otto_Transient_Cache($otto_uuid);
            # Invalidate old cache and fetch fresh suggestions
            $transient_cache->warm_cache($route);
        }

        # ENHANCED: Try to schedule async SEO data processing, fallback to immediate processing
        # Use immediate scheduling with 1-second delay to allow current request to complete
        $scheduled = wp_schedule_single_event(time() + 1, 'metasync_process_seo_job', array($route));
        
        # If scheduling fails (due to database permissions), process immediately
        if ($scheduled === false) {
            # Process SEO data immediately as fallback
            metasync_process_otto_seo_data($route);
        }

        # do the delete
        $otto_pixel->refresh_cache($route);
        
        # Clear cache plugins for this specific URL
        try {
            $cache_purge = Metasync_Cache_Purge::get_instance();
            $cache_purge->clear_url_cache($route);
        } catch (Exception $e) {
            // Cache purge failed, continue
        }
    }

    # Clear all cache plugins after OTTO updates
    try {
        $cache_purge = Metasync_Cache_Purge::get_instance();
        $results = $cache_purge->clear_all_caches('otto_update');
    } catch (Exception $e) {
        // Cache purge failed, continue
    }

    # Track OTTO optimization event in Mixpanel
    try {
        $mixpanel = Metasync_Mixpanel::get_instance();
        $mixpanel->track_otto_optimization($data);
    } catch (Exception $e) {
        // Mixpanel tracking failed, continue
    }

    # Handle the POST request
    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'OTTO crawl notification received',
    ), 200);
}

# NOTE: Cache system removed - these functions are no longer needed
# Kept for backward compatibility in case old cache directories need cleanup
function metasync_deleteDir($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        $filePath = $dir . DIRECTORY_SEPARATOR . $file;
        if (is_dir($filePath)) {
            metasync_deleteDir($filePath);
        } else {
            unlink($filePath);
        }
    }
    return rmdir($dir);
}

# Cleanup function for removing old cache directories (if they exist)
function metasync_invalidate_all_caches($folder = ''){
    # Cache system removed - this function only exists to clean up old cache directories
    if(!defined('WP_CONTENT_DIR')){
        return false;
    }
    $wp_content_dir = WP_CONTENT_DIR;
    $cache_dir = $wp_content_dir . '/metasync_caches';
    if(in_array($folder, ['posts', 'pages'])){
        $cache_dir = $cache_dir . '/' . $folder;
    }
    if(is_dir($cache_dir)){
        metasync_deleteDir($cache_dir);
    }
}

function metasync_start_otto(){

    # PERFORMANCE FIX: Cache is now enabled for speed
    # Skip initial cache cleanup to preserve existing cache
    # Cache files are valuable for performance - only clear on OTTO updates
    # Periodic cache clearing can be configured in plugin settings if needed

    # exclude AJAX request and all woocommerce pages from OTTO
    if (
        # disable ajax calls
        isset($_GET['ucfrontajaxaction']) ||
        # OTTO Preview mode - skip OTTO when previewing original content
        (isset($_GET['otto_preview']) && $_GET['otto_preview'] === '1') ||
        # WooCommerce main pages
        (function_exists('is_woocommerce') && is_woocommerce()) ||
        # Cart page
        (function_exists('is_cart') && is_cart()) ||
        # Checkout page
        (function_exists('is_checkout') && is_checkout()) ||
        # My Account page
        (function_exists('is_account_page') && is_account_page()) ||
        # Standard WordPress AJAX
        (function_exists('wp_doing_ajax') && wp_doing_ajax()) ||
        # check by constant
        (defined('DOING_AJAX') && DOING_AJAX) ||
        # WooCommerce AJAX endpoint (e.g., ?wc-ajax=update_cart)
        (isset($_REQUEST['wc-ajax']) && !empty($_REQUEST['wc-ajax'])) ||
        # AJAX requests via X-Requested-With header
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        # Gravity Forms submission detection - skip OTTO to allow form processing
        (isset($_POST['is_submit_' . (isset($_POST['gform_submit']) ? $_POST['gform_submit'] : '')]) && !empty($_POST['gform_submit'])) ||
        # Gravity Forms AJAX submission
        (isset($_POST['gform_ajax']) && !empty($_POST['gform_submit'])) ||
        # Gravity Forms file upload
        (isset($_POST['gform_uploaded_files'])) ||
        # Any Gravity Forms POST parameter
        (isset($_POST['gform_submit']) || isset($_POST['gform_unique_id']) || isset($_POST['gform_field_values'])) ||
        # Formidable Forms AJAX submission detection - skip OTTO to allow form processing
        (isset($_POST['action']) && $_POST['action'] === 'frm_entries_create') ||
        # Formidable Forms POST parameters
        (isset($_POST['form_id']) && !empty($_POST['form_id'])) ||
        # Formidable Forms action parameter
        (isset($_POST['frm_action']) && !empty($_POST['frm_action'])) ||
        # Formidable Forms item_key (used in form submissions)
        (isset($_POST['item_key']) && !empty($_POST['item_key']))
    ) {
        return;
    }

    # fetch globals
    global $metasync_options, $otto_enabled;

    # OPTIMIZED: check for the disable otto for logged in users option using cached config
    if(Metasync_Otto_Config::is_disabled_for_loggedin()){

        # get user
        $current_user = wp_get_current_user();

        # check if user is logged in
        if( !empty($current_user->ID)){

            return;
        }
    }

    # check if current URL is excluded from OTTO
    $current_url = home_url($_SERVER['REQUEST_URI']);
    if (metasync_is_otto_url_excluded($current_url)) {
        return;
    }

    # OPTIMIZED: Check if Otto should be disabled for WP Rocket compatibility
    if (class_exists('WP_Rocket')) {
        $wp_rocket_compat_mode = Metasync_Otto_Config::get_wp_rocket_compat_mode();

        if ($wp_rocket_compat_mode === 'disable_otto') {
            return; # Exit early, Otto is disabled when WP Rocket is active
        }
    }

    # Handle cache plugin compatibility early - before any caching happens
    metasync_otto_handle_cache_compatibility();

    # check if OTTO is disabled for this specific page/post
    $post_id = get_the_ID();
    if ($post_id && class_exists('Metasync_Otto_Frontend_Toolbar')) {
        if (Metasync_Otto_Frontend_Toolbar::is_otto_disabled($post_id)) {
            return;
        }
    }

    # check if we are having an otto request
    if(!empty($_GET['is_otto_page_fetch'])){

        # Block SEO plugins NOW for this internal fetch request
       # metasync_otto_block_seo_plugins();
       # $_SERVER['REQUEST_URI'] = remove_query_arg('is_otto_page_fetch', $_SERVER['REQUEST_URI']);
       $block_title = !empty($_GET['otto_block_title']) && $_GET['otto_block_title'] === '1';
        $block_description = !empty($_GET['otto_block_desc']) && $_GET['otto_block_desc'] === '1';
        
        # Block SEO plugins conditionally based on what Otto has
        if ($block_title || $block_description) {
            metasync_otto_block_seo_plugins($block_title, $block_description);
        }
        
        # Remove ALL Otto parameters from REQUEST_URI to prevent them from appearing in pagination, etc.
        $_SERVER['REQUEST_URI'] = remove_query_arg(
            ['is_otto_page_fetch', 'otto_block_title', 'otto_block_desc'], 
            $_SERVER['REQUEST_URI']
        );
        
        # Also remove from $_GET to prevent WordPress from using them
        unset($_GET['is_otto_page_fetch']);
        unset($_GET['otto_block_title']);
        unset($_GET['otto_block_desc']);
        return;
    }

    # to avoid unnecessary processese
    # OPTIMIZED: check that otto is configured
    # And the UUID is properly set before running OTT

    # check that we have the option
    if(!Metasync_Otto_Config::is_otto_enabled()){
        return;
    }

    # check that otto is enabled
    if(!$otto_enabled){
        return;
    }

    # get the otto uuid
    $otto_uuid = Metasync_Otto_Config::get_otto_uuid();

    # start the class
    $otto = new Metasync_otto_pixel($otto_uuid);

    # call render
    $otto->render_route_html();
}

/**
 * Handle cache plugin compatibility with Otto
 * Controls DONOTCACHEPAGE constant based on active plugins and configuration
 * This function is called early in the WordPress lifecycle
 */
function metasync_otto_handle_cache_compatibility() {
    # Detect active plugins
    $brizy_active = class_exists('Brizy_Editor') || defined('BRIZY_VERSION');
    $wp_rocket_active = class_exists('WP_Rocket');
    $sg_optimizer_active = is_plugin_active('sg-cachepress/sg-cachepress.php');

    # OPTIMIZED: Get configuration option using cached config
    $wp_rocket_compat_mode = Metasync_Otto_Config::get_wp_rocket_compat_mode();

    # Check for Brizy posts in database
    global $wpdb;
    $has_brizy_posts = false;

    if ($brizy_active) {
        # OPTIMIZED: Check cache first (1-hour TTL) to avoid querying on every page load
        $cached = get_transient('metasync_has_brizy_posts');
        if ($cached !== false) {
            $has_brizy_posts = ($cached === 'yes');
        } else {
            # Query database only if cache missed
            $has_brizy_posts = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->postmeta}
                 WHERE meta_key = 'brizy_post_uid'
                 AND meta_value != ''
                 LIMIT 1"
            );

            # Cache result for 1 hour
            $result = !empty($has_brizy_posts) ? 'yes' : 'no';
            set_transient('metasync_has_brizy_posts', $result, HOUR_IN_SECONDS);
        }
    }

    # Determine if DONOTCACHEPAGE should be set
    $should_set_donotcachepage = false;

    # Case 1: Brizy is active with posts - always needed
    if ($brizy_active && !empty($has_brizy_posts)) {
        $should_set_donotcachepage = true;
    }

    # Case 2: SG Optimizer without WP Rocket - prevent conflicts
    elseif ($sg_optimizer_active && !$wp_rocket_active) {
        $should_set_donotcachepage = true;
    }

    # Case 3: User explicitly disabled Otto for WP Rocket compatibility
    elseif ($wp_rocket_active && $wp_rocket_compat_mode === 'disable_otto') {
        $should_set_donotcachepage = true;
        return; # Exit early, Otto won't run
    }

    # Case 4: WP Rocket active with auto/buffer mode - DON'T set DONOTCACHEPAGE
    # This allows WP Rocket optimizations to continue working

    # Only set DONOTCACHEPAGE if needed
    if ($should_set_donotcachepage && !defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }

    # Apply SG Optimizer specific filters only if SG is active and no WP Rocket
    if ($sg_optimizer_active && !$wp_rocket_active && $should_set_donotcachepage) {
        if (!defined('SG_CachePress_SUPERCACHER')) {
            define('SG_CachePress_SUPERCACHER', false);
        }

        add_filter('sgo_html_cache_disable', '__return_true', 999);
        add_filter('sgo_css_combine_exclude', '__return_true', 999);
        add_filter('sgo_js_combine_exclude', '__return_true', 999);
        add_filter('sgo_cache_this_page', '__return_false', 999);

        if (!headers_sent()) {
            header('Cache-Control: no-cache, must-revalidate, max-age=0');
            header('X-Accel-Expires: 0');
        }
    }
}

/**
 * Block SEO plugins conditionally based on what Otto is providing
 * Only blocks title if Otto has title, only blocks description if Otto has description
 * This prevents duplicate SEO tags while allowing fallback to SEO plugins when Otto has no data
 *
 * @param bool $block_title Whether to block title tags
 * @param bool $block_description Whether to block description tags
 */
function metasync_otto_block_seo_plugins($block_title = false, $block_description = false) {
    # Disable Yoast SEO
    if (is_plugin_active('wordpress-seo/wp-seo.php')) {
        
        # Block title only if Otto has title
        if ($block_title) {
            add_filter('wpseo_title', '__return_false', 999);
            add_filter('pre_get_document_title', '__return_empty_string', 999);
        }
        
        # Block description only if Otto has description
        if ($block_description) {
            add_filter('wpseo_metadesc', '__return_false', 999);
            add_filter('wpseo_meta_description', '__return_false', 999);
            add_filter('wpseo_metakeywords', '__return_false', 999);
        }
        
        # Block Yoast's modern presenters
        add_filter('wpseo_frontend_presenters', function($presenters) use ($block_title, $block_description) {
            if (!is_array($presenters)) return $presenters;
            
            $presenters_to_remove = [];
            
            # Add title presenters to block if Otto has title
            if ($block_title) {
                $presenters_to_remove[] = 'Yoast\WP\SEO\Presenters\Title_Presenter';
                $presenters_to_remove[] = 'Yoast\WP\SEO\Presenters\Open_Graph\Title_Presenter';
                $presenters_to_remove[] = 'Yoast\WP\SEO\Presenters\Twitter\Title_Presenter';
            }
            
            # Add description presenters to block if Otto has description
            if ($block_description) {
                $presenters_to_remove[] = 'Yoast\WP\SEO\Presenters\Meta_Description_Presenter';
                $presenters_to_remove[] = 'Yoast\WP\SEO\Presenters\Open_Graph\Description_Presenter';
                $presenters_to_remove[] = 'Yoast\WP\SEO\Presenters\Twitter\Description_Presenter';
            }
            
            foreach ($presenters as $key => $presenter) {
                # $class_name = is_object($presenter) ? get_class($presenter) : '';
                # if (in_array($class_name, $presenters_to_remove)) {
                // Safely get class name, suppressing autoload errors
                // This prevents warnings when Composer autoloader tries to load deprecated Yoast files
                $class_name = is_object($presenter) ? @get_class($presenter) : '';
                
                if (!empty($class_name) && in_array($class_name, $presenters_to_remove)) {
                    unset($presenters[$key]);
                }
            }
            return $presenters;
        }, 999);
    }
    
    # Disable Rank Math
    if (is_plugin_active('seo-by-rank-math/rank-math.php') ||
        is_plugin_active('seo-by-rankmath/rank-math.php')) {

        if ($block_title) {
            add_filter('rank_math/frontend/title', '__return_empty_string', 999);
        }

        if ($block_description) {
            add_filter('rank_math/frontend/description', '__return_false', 999);
            add_filter('rank_math/frontend/show_keywords', '__return_false', 999);
        }
    }
}
# check that otto is not added via js to the site
function metasync_check_otto_js(){

    # get the site url
    $site_url = site_url() . '?is_otto_page_fetch=1';

    # get the html
    $page_data = wp_remote_get($site_url);

    # now get the html body
    $body = wp_remote_retrieve_body($page_data);

    # now load the body into html
    $dom = new HtmlDocument($body);

    # now check the dom for a meta tag with 
    $script = $dom->find('script#sa-dynamic-optimization', 0);

    # check script
    if($script AND !empty($script->getAttribute('data-uuid'))){

        # return 
        return true;
    }

    # 
    return false;
};

# Handle AJAX Clear Cache request
# NOTE: Cache system removed - this is now a no-op
function metasync_clear_otto_cache_handler() {
    if (!empty($_GET['clear_otto_cache'])) {
        # Cache system has been removed - no cache to clear
        wp_send_json_success(['message' => 'Cache system removed - all pages processed in real-time']);
    } 
    else {
        wp_send_json_error(['message' => 'Missing parameter']);
    }
}

# Clear cache hook
add_action('wp_ajax_clear_otto_cache', 'metasync_clear_otto_cache_handler');

# add admin action to check script
function metasync_show_otto_ssr_notice() {
    if (!Metasync::current_user_has_plugin_access()) {
        return; // Only show to admins
    }

    # Get the plugin name using centralized method
    $plugin_name = Metasync::get_effective_plugin_name();
    $whitelabel_otto_name = Metasync::get_whitelabel_otto_name();
    if (metasync_check_otto_js()) {

        # Show admin notice with plugin name included in the message
        echo '<div class="notice notice-error">
                 <p><b>Warning from ' . esc_html($plugin_name) . '</b>
                    <br>
                    ' . esc_html($whitelabel_otto_name) . ' JavaScript has been detected on your site. Please remove it and configure ' . esc_html($whitelabel_otto_name) . ' for Wordpress. Contact support for help
                </p>
         </div>';
    }
}

add_action('admin_notices', 'metasync_show_otto_ssr_notice');

# staging dummy change
# load otto in the wp hook 
add_action('wp', 'metasync_start_otto');

  
  # ENHANCED OTTO SEO INTEGRATION
  # Register async SEO processing hook
  add_action('metasync_process_seo_job', 'metasync_process_otto_seo_data');
  
  # Process OTTO SEO data and update WordPress meta fields for SEO plugins
  # This function now runs asynchronously via WordPress cron system
  
function metasync_process_otto_seo_data($route) {
    try {
        # Validate input
        if (empty($route) || !is_string($route)) {
            return false;
        }

        # Skip excluded URLs - don't process SEO data for them
        if (metasync_is_otto_url_excluded($route)) {
            //error_log('MetaSync OTTO: Skipping SEO processing for excluded URL: ' . $route);
            return false;
        }

        # Get OTTO UUID from settings
        # OPTIMIZED: Use cached options
        $otto_uuid = Metasync_Otto_Config::get_otto_uuid();

        if (empty($otto_uuid)) {
            return false;
        }

        # Meta descriptions are always enabled by default - no check needed

        # Fetch SEO data from OTTO API
        $seo_data = metasync_fetch_otto_seo_data($route, $otto_uuid);

        if (!$seo_data) {
            return false;
        }

        # Mark this URL as crawled by OTTO for SSR
        # Extract domain and path from route
        $parsed_url = parse_url($route);
        $domain_with_scheme = ($parsed_url['scheme'] ?? 'https') . '://' . ($parsed_url['host'] ?? '');
        $url_path = ($parsed_url['path'] ?? '/');

        # Create crawl data structure
        $crawl_data = array(
            'domain' => $domain_with_scheme,
            'urls' => array($url_path)
        );

        # Load Otto pixel class and save crawl data
        $otto_pixel = new Metasync_otto_pixel($otto_uuid);
        $otto_pixel->save_crawl_data($crawl_data);

        # Get WordPress post ID from URL
        $post_id = url_to_postid($route);

        # Special handling for WooCommerce shop page (url_to_postid doesn't work for it)
        if ((!$post_id || $post_id <= 0) && function_exists('wc_get_page_id')) {
            # Check if this URL is the WooCommerce shop page
            $shop_page_id = wc_get_page_id('shop');
            if ($shop_page_id > 0) {
                $shop_url = get_permalink($shop_page_id);
                $route_normalized = rtrim($route, '/');
                $shop_url_normalized = rtrim($shop_url, '/');

                if ($route_normalized === $shop_url_normalized) {
                    $post_id = $shop_page_id;
                }
            }
        }

        # Try to find WooCommerce product by URL if url_to_postid failed
        if ((!$post_id || $post_id <= 0) && strpos($route, '/product/') !== false && function_exists('wc_get_products')) {
            # Extract product slug from URL
            $product_slug = basename(parse_url($route, PHP_URL_PATH));

            # Try to get product by slug
            $products = wc_get_products(array(
                'name' => $product_slug,
                'limit' => 1,
                'status' => 'publish',
            ));

            if (empty($products)) {
                # Fallback: try by slug using WP_Query
                $args = array(
                    'post_type' => 'product',
                    'name' => $product_slug,
                    'posts_per_page' => 1,
                    'post_status' => 'publish',
                );
                $query = new WP_Query($args);

                if ($query->have_posts()) {
                    $product_post = $query->posts[0];
                    $post_id = $product_post->ID;
                }
            } else {
                $product = $products[0];
                $post_id = $product->get_id();
            }
        }

        if (!$post_id || $post_id <= 0) {
            # Check if this is a category page
            if (strpos($route, '/category/') !== false) {
                # Extract category slug from URL
                $category_slug = basename(parse_url($route, PHP_URL_PATH));
                $category = get_category_by_slug($category_slug);

                if ($category) {
                    # Update comprehensive category SEO meta fields
                    $update_result = metasync_update_comprehensive_category_seo_fields($category->term_id, $seo_data);

                    if ($update_result['updated']) {
                        # Prepare trimmed values to 30 characters
                        $trim = function($value) {
                            if ($value === null) { return ''; }
                            $value = (string) $value;
                            $value = trim($value);
                            if (mb_strlen($value) > 30) {
                                return mb_substr($value, 0, 30);
                            }
                            return $value;
                        };

                        # Log individual field updates for category
                        foreach ($update_result['fields_updated'] as $field_type => $field_value) {
                            $short = '';
                            $title = '';

                            switch ($field_type) {
                                case 'meta_title':
                                    $short = $trim($field_value);
                                    $title = "Category Meta Title Update ({$short}...)";
                                    break;
                                case 'meta_description':
                                    $short = $trim($field_value);
                                    $title = "Category Meta Description Update ({$short}...)";
                                    break;
                                case 'meta_keywords':
                                    $short = $trim($field_value);
                                    $title = "Category Meta Keywords Update ({$short}...)";
                                    break;
                                case 'og_title':
                                    $short = $trim($field_value);
                                    $title = "Category Open Graph Title Update ({$short}...)";
                                    break;
                                case 'og_description':
                                    $short = $trim($field_value);
                                    $title = "Category Open Graph Description Update ({$short}...)";
                                    break;
                                case 'twitter_title':
                                    $short = $trim($field_value);
                                    $title = "Category Twitter Title Update ({$short}...)";
                                    break;
                                case 'twitter_description':
                                    $short = $trim($field_value);
                                    $title = "Category Twitter Description Update ({$short}...)";
                                    break;
                                case 'image_alt_data':
                                    $image_count = count($field_value);
                                    $title = "Category Image Alt Text Update ({$image_count} images)";
                                    break;
                                case 'headings_data':
                                    $heading_count = count($field_value);
                                    $title = "Category Headings Update ({$heading_count} headings)";
                                    break;
                                case 'structured_data':
                                    $title = 'Category Structured Data Update';
                                    break;
                            }

                            if (!empty($title)) {
                                metasync_log_sync_history([
                                    'title' => $title,
                                    'source' => 'OTTO SEO',
                                    'status' => 'published',
                                    'content_type' => 'Category SEO',
                                    'url' => $route,
                                    'meta_data' => json_encode([
                                        'field' => $field_type,
                                        'field_value' => $field_value,
                                        'category_id' => $category->term_id,
                                        'category_name' => $category->name
                                    ])
                                ]);
                            }
                        }

                        return true;
                    }

                    return false;
                }
            }

            # Check if this is a WooCommerce product category
            if (strpos($route, '/product-category/') !== false) {
                # Extract product category slug from URL
                $category_slug = basename(parse_url($route, PHP_URL_PATH));
                $term = get_term_by('slug', $category_slug, 'product_cat');

                if ($term && !is_wp_error($term)) {
                    # Update comprehensive taxonomy SEO meta fields
                    $update_result = metasync_update_comprehensive_taxonomy_seo_fields($term->term_id, 'product_cat', $seo_data);
                    
                    if ($update_result['updated']) {
                        # Prepare trimmed values to 30 characters
                        $trim = function($value) {
                            if ($value === null) { return ''; }
                            $value = (string) $value;
                            $value = trim($value);
                            if (mb_strlen($value) > 30) {
                                return mb_substr($value, 0, 30);
                            }
                            return $value;
                        };

                        # Log individual field updates for product category
                        foreach ($update_result['fields_updated'] as $field_type => $field_value) {
                            $short = '';
                            $title = '';

                            switch ($field_type) {
                                case 'meta_title':
                                    $short = $trim($field_value);
                                    $title = "Product Category Meta Title Update ({$short}...)";
                                    break;
                                case 'meta_description':
                                    $short = $trim($field_value);
                                    $title = "Product Category Meta Description Update ({$short}...)";
                                    break;
                                case 'meta_keywords':
                                    $short = $trim($field_value);
                                    $title = "Product Category Meta Keywords Update ({$short}...)";
                                    break;
                                case 'og_title':
                                    $short = $trim($field_value);
                                    $title = "Product Category Open Graph Title Update ({$short}...)";
                                    break;
                                case 'og_description':
                                    $short = $trim($field_value);
                                    $title = "Product Category Open Graph Description Update ({$short}...)";
                                    break;
                                case 'twitter_title':
                                    $short = $trim($field_value);
                                    $title = "Product Category Twitter Title Update ({$short}...)";
                                    break;
                                case 'twitter_description':
                                    $short = $trim($field_value);
                                    $title = "Product Category Twitter Description Update ({$short}...)";
                                    break;
                                case 'image_alt_data':
                                    $image_count = count($field_value);
                                    $title = "Product Category Image Alt Text Update ({$image_count} images)";
                                    break;
                                case 'headings_data':
                                    $heading_count = count($field_value);
                                    $title = "Product Category Headings Update ({$heading_count} headings)";
                                    break;
                                case 'structured_data':
                                    $title = 'Product Category Structured Data Update';
                                    break;
                            }

                            if (!empty($title)) {
                                metasync_log_sync_history([
                                    'title' => $title,
                                    'source' => 'OTTO SEO',
                                    'status' => 'published',
                                    'content_type' => 'WooCommerce Product Category SEO',
                                    'url' => $route,
                                    'meta_data' => json_encode([
                                        'field' => $field_type,
                                        'field_value' => $field_value,
                                        'term_id' => $term->term_id,
                                        'term_name' => $term->name,
                                        'taxonomy' => 'product_cat'
                                    ])
                                ]);
                            }
                        }
                        
                        return true;
                    }
                    
                    return false;
                }
            }
            
            # Check if this is the home page (landing page)
            $site_url = rtrim(site_url(), '/');
            $route_clean = rtrim($route, '/');
            
            if ($route_clean === $site_url) {
                # Get the home page (front page)
                $front_page_id = get_option('page_on_front');
                $home_page = null;
                
                if ($front_page_id && $front_page_id > 0) {
                    $home_page = get_post($front_page_id);
                } else {
                    # If no static front page is set, get the latest post
                    $home_page = get_posts(['numberposts' => 1, 'post_status' => 'publish'])[0] ?? null;
                }
                
                if ($home_page) {
                    # Update comprehensive home page SEO meta fields
                    $update_result = metasync_update_comprehensive_seo_fields($home_page->ID, $seo_data);
                    
                    if ($update_result['updated']) {
                        # Clear relevant caches
                        metasync_clear_post_seo_caches($home_page->ID);
                        
                        # Prepare trimmed values to 30 characters
                        $trim = function($value) {
                            if ($value === null) { return ''; }
                            $value = (string) $value;
                            $value = trim($value);
                            if (mb_strlen($value) > 30) {
                                return mb_substr($value, 0, 30);
                            }
                            return $value;
                        };

                        # Log individual field updates for home page
                        foreach ($update_result['fields_updated'] as $field_type => $field_value) {
                            $short = '';
                            $title = '';
                            
                            switch ($field_type) {
                                case 'meta_title':
                                    $short = $trim($field_value);
                                    $title = "Home Page Meta Title Update ({$short}...)";
                                    break;
                                case 'meta_description':
                                    $short = $trim($field_value);
                                    $title = "Home Page Meta Description Update ({$short}...)";
                                    break;
                                case 'meta_keywords':
                                    $short = $trim($field_value);
                                    $title = "Home Page Meta Keywords Update ({$short}...)";
                                    break;
                                case 'og_title':
                                    $short = $trim($field_value);
                                    $title = "Home Page Open Graph Title Update ({$short}...)";
                                    break;
                                case 'og_description':
                                    $short = $trim($field_value);
                                    $title = "Home Page Open Graph Description Update ({$short}...)";
                                    break;
                                case 'twitter_title':
                                    $short = $trim($field_value);
                                    $title = "Home Page Twitter Title Update ({$short}...)";
                                    break;
                                case 'twitter_description':
                                    $short = $trim($field_value);
                                    $title = "Home Page Twitter Description Update ({$short}...)";
                                    break;
                                case 'image_alt_data':
                                    $image_count = count($field_value);
                                    $title = "Home Page Image Alt Text Update ({$image_count} images)";
                                    break;
                                case 'headings_data':
                                    $heading_count = count($field_value);
                                    $title = "Home Page Headings Update ({$heading_count} headings)";
                                    break;
                                case 'structured_data':
                                    $title = 'Home Page Structured Data Update';
                                    break;
                            }

                            if (!empty($title)) {
                                metasync_log_sync_history([
                                    'title' => $title,
                                    'source' => 'OTTO SEO',
                                    'status' => 'published',
                                    'content_type' => 'Home Page SEO',
                                    'url' => $route,
                                    'meta_data' => json_encode([
                                        'field' => $field_type,
                                        'field_value' => $field_value,
                                        'post_id' => $home_page->ID
                                    ])
                                ]);
                            }
                        }
                        
                        return true;
                    }
                    
                    return false;
                }
            }
            
            # Skip non-post/page/category/home page URLs
            return false;
        }
        
        # Verify this is actually a post, page, or WooCommerce product
        $post = get_post($post_id);

        # Get supported post types dynamically
        $supported_post_types = metasync_get_supported_post_types();

        if (!$post || !in_array($post->post_type, $supported_post_types)) {
            # Skip unsupported post types
            return false;
        }

        # Update comprehensive SEO meta fields
        $update_result = metasync_update_comprehensive_seo_fields($post_id, $seo_data);

        if ($update_result['updated']) {
            # Clear relevant caches
            metasync_clear_post_seo_caches($post_id);

            # Prepare trimmed values to 30 characters
            $trim = function($value) {
                if ($value === null) { return ''; }
                $value = (string) $value;
                $value = trim($value);
                if (mb_strlen($value) > 30) {
                    return mb_substr($value, 0, 30);
                }
                return $value;
            };

            # Log individual field updates
            foreach ($update_result['fields_updated'] as $field_type => $field_value) {
                $short = '';
                $title = '';
                
                switch ($field_type) {
                    case 'meta_title':
                        $short = $trim($field_value);
                        $title = 'Meta Title Update (' . $short . '...)';
                        break;
                    case 'meta_description':
                        $short = $trim($field_value);
                        $title = 'Meta Description Update (' . $short . '...)';
                        break;
                    case 'meta_keywords':
                        $short = $trim($field_value);
                        $title = 'Meta Keywords Update (' . $short . '...)';
                        break;
                    case 'og_title':
                        $short = $trim($field_value);
                        $title = 'Open Graph Title Update (' . $short . '...)';
                        break;
                    case 'og_description':
                        $short = $trim($field_value);
                        $title = 'Open Graph Description Update (' . $short . '...)';
                        break;
                    case 'twitter_title':
                        $short = $trim($field_value);
                        $title = 'Twitter Title Update (' . $short . '...)';
                        break;
                    case 'twitter_description':
                        $short = $trim($field_value);
                        $title = 'Twitter Description Update (' . $short . '...)';
                        break;
                    case 'image_alt_data':
                        $image_count = count($field_value);
                        $title = "Image Alt Text Update ({$image_count} images)";
                        break;
                    case 'headings_data':
                        $heading_count = count($field_value);
                        $title = "Headings Update ({$heading_count} headings)";
                        break;
                    case 'structured_data':
                        $title = 'Structured Data Update';
                        break;
                }

                if (!empty($title)) {
                    metasync_log_sync_history([
                        'title' => $title,
                        'source' => 'OTTO SEO',
                        'status' => 'published',
                        'content_type' => 'SEO Meta',
                        'url' => $route,
                        'meta_data' => json_encode([
                            'field' => $field_type,
                            'field_value' => $field_value,
                            'post_id' => $post_id
                        ])
                    ]);
                }
            }

            return true;
        }
        
        return false;

    } catch (Exception $e) {
        return false;
    }
}

/**
 * Log sync history entry
 * @param array $data Sync data to log
 */
function metasync_log_sync_history($data) {
    try {
        // Classes are now autoloaded, no need for manual require
        $sync_db = new Metasync_Sync_History_Database();

        // Minimal duplicate prevention within short time window
        if (!empty($data['title']) && !empty($data['source'])) {
            global $wpdb;
            $table = $wpdb->prefix . Metasync_Sync_History_Database::$table_name;
            $recent = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `$table` WHERE title = %s AND source = %s AND created_at >= %s",
                $data['title'],
                $data['source'],
                gmdate('Y-m-d H:i:s', time() - 60)
            ));
            if ((int)$recent > 0) {
                return; // skip duplicate log within 60 seconds
            }
        }

        $sync_db->add($data);

    } catch (Exception $e) {
        error_log("MetaSync: Failed to log sync history: " . $e->getMessage());
    }
}

/**
 * Get supported post types for OTTO SEO optimization
 * Includes WooCommerce products if WooCommerce is active
 *
 * @return array List of supported post types
 */
function metasync_get_supported_post_types() {
    # Start with default post types
    $post_types = ['post', 'page'];

    # Add WooCommerce product post type if WooCommerce is active
    if (class_exists('WooCommerce') || function_exists('is_woocommerce')) {
        $post_types[] = 'product';
    }

    # Allow developers to filter supported post types
    $post_types = apply_filters('metasync_otto_supported_post_types', $post_types);

    return $post_types;
}

/**
 * Get supported taxonomies for OTTO SEO optimization
 * Includes WooCommerce product categories and tags if WooCommerce is active
 *
 * @return array List of supported taxonomies
 */
function metasync_get_supported_taxonomies() {
    # Start with default taxonomies
    $taxonomies = ['category'];

    # Add WooCommerce taxonomies if WooCommerce is active
    if (class_exists('WooCommerce') || function_exists('is_woocommerce')) {
        $taxonomies[] = 'product_cat';  # WooCommerce product categories
        $taxonomies[] = 'product_tag';  # WooCommerce product tags
    }

    # Allow developers to filter supported taxonomies
    $taxonomies = apply_filters('metasync_otto_supported_taxonomies', $taxonomies);

    return $taxonomies;
}

/**
 * Check if a URL is excluded from OTTO
 * @param string $url URL to check
 * @return bool True if URL is excluded, false otherwise
 */
function metasync_is_otto_url_excluded($url)
{
    try {
        // Load database class
        require_once plugin_dir_path(__FILE__) . 'class-metasync-otto-excluded-urls-database.php';
        $db = new Metasync_Otto_Excluded_URLs_Database();

        // Check if URL is excluded
        return $db->is_url_excluded($url);

    } catch (Exception $e) {
        return false;
    }
}

/**
 * Invalidate Brizy posts cache when posts are saved
 * OPTIMIZATION: Clears transient cache to ensure accurate detection
 */
add_action('save_post', function($post_id) {
    # Check if this post has Brizy metadata
    if (get_post_meta($post_id, 'brizy_post_uid', true)) {
        delete_transient('metasync_has_brizy_posts');
    }
}, 10, 1);


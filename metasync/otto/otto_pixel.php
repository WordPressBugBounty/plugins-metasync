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

# get the metasync options
$metasync_options = get_option('metasync_options');

# check otto enabled 
$otto_enabled = $metasync_options['general']['otto_enable'] ?? false;

# add tag to wp head
add_action('wp_head', function(){
    # load globals
    global $metasync_options, $otto_enabled;

    # string value otto status
    $string_enabled = $otto_enabled ? 'true' : 'false';

    # check uuid set
    if(empty($metasync_options['general']['otto_pixel_uuid'])){
        return;
    }

    # adding the otto tag to pages
    $plugin_version = defined('METASYNC_VERSION') ? METASYNC_VERSION : 'unknown';
    $otto_tag = '<meta name="otto" content="uuid='.esc_attr($metasync_options['general']['otto_pixel_uuid']).'; type=wordpress; enabled='.esc_attr($string_enabled).'; version='.esc_attr($plugin_version).'">';

    # out the otto tag
    echo $otto_tag;
});

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

        # ENHANCED: Schedule async SEO data processing to avoid blocking the webhook
        # Use immediate scheduling with 1-second delay to allow current request to complete
        wp_schedule_single_event(time() + 1, 'metasync_process_seo_job', array($route));

        # do the delete
        $otto_pixel->refresh_cache($route);
    }


    # Handle the POST request
    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'OTTO crawl notification received',
    ), 200);
}

# delete a directory
function metasync_deleteDir($dir) {

    # check that it is a dir
    if (!is_dir($dir)) {
        return false;
    }

    # get all files in the dir
    $files = array_diff(scandir($dir), array('.', '..'));

    # loops all files delete each if is dir run delete
    foreach ($files as $file) {

        # create path
        $filePath = $dir . DIRECTORY_SEPARATOR . $file;
        
        # delete
        if (is_dir($filePath)) {
            metasync_deleteDir($filePath);
        } 
        else {
            unlink($filePath);
        }
    }

    # return result of removing dir
    return rmdir($dir);
}

function metasync_invalidate_all_caches($folder = ''){
    # check that we have the WP_CONTENT_DIR defined
    if(!defined('WP_CONTENT_DIR')){
        return false;
    }

    # Get the path to the wp-content directory
    $wp_content_dir = WP_CONTENT_DIR;

    # Define the path for the metasync_caches directory
    $cache_dir = $wp_content_dir . '/metasync_caches';

    # set cache dir based on type
    if(in_array($folder, ['posts', 'pages'])){

        # set teh cache dir
        $cache_dir = $cache_dir . '/' . $folder;
    }

    # if is dir remove it
    if(is_dir($cache_dir)){

        # delete it
        metasync_deleteDir($cache_dir);
    }

}

function metasync_clear_existing_metasync_caches(){

    # get the metasync
    global $metasync_options;

    # option name
    $option_name = 'metasync_refresh_all_caches';

    # set a duration
    $duration = 0;

    # get the option
    $last_change_time = get_option($option_name);

    # ensure last change time has values
    $last_change_time = array(
        'general' => $last_change_time['general'] ?? 0,
        'posts' => $last_change_time['posts'] ?? 0,
        'pages' => $last_change_time['pages'] ?? 0,
    );

    # check if we have periodic cache clearance configured
    if(!empty($metasync_options['general']['periodic_clear_otto_cache']) AND intval($metasync_options['general']['periodic_clear_otto_cache']) > 0){

        # check that the time is not passed
        $duration = 3600 * intval($metasync_options['general']['periodic_clear_otto_cache']);

        # check time and duration
        if(($last_change_time['general'] + $duration) <= time()){
            # last change general
            $last_change_time['general'] = time();

            # call the clean caches function
            metasync_invalidate_all_caches('general');
        }

    }
    # check if we have periodic cache clearance configured
    elseif(is_page() AND !empty($metasync_options['general']['periodic_clear_ottopage_cache']) AND intval($metasync_options['general']['periodic_clear_ottopage_cache']) > 0){

        # check that the time is not passed
        $duration = 3600 * intval($metasync_options['general']['periodic_clear_ottopage_cache']);

        # check time and duration
        if(($last_change_time['pages'] + $duration) <= time()){
            # last change pages
            $last_change_time['pages'] = time();

            # call the clean caches function
            metasync_invalidate_all_caches('pages');
        }

    }
    # check if we have periodic cache clearance configured
    elseif(is_singular('post') AND !empty($metasync_options['general']['periodic_clear_ottopost_cache']) AND intval($metasync_options['general']['periodic_clear_ottopost_cache']) > 0){

        # check that the time is not passed
        $duration = 3600 * intval($metasync_options['general']['periodic_clear_ottopost_cache']);

        # check time and duration
        if(($last_change_time['posts'] + $duration) <= time()){
            # last change posts
            $last_change_time['posts'] = time();

            # call the clean caches function
            metasync_invalidate_all_caches('posts');
        }

    }
    # running under default settings
    else{

        # set duration for all other pages to 4 weeks
        $duration = 3600 * 24 * 7 *4;

        # check time and duration
        if(($last_change_time['general'] + $duration) <= time()){
            # last change general
            $last_change_time['general'] = time();

            # call the clean caches function
            metasync_invalidate_all_caches('general');
        }
    }

    
    # create the option
    update_option($option_name, $last_change_time);

    return true;
}

function metasync_start_otto(){

    # IMMEDIATE CACHE CLEANUP: Delete all existing cache files once but keep main folder
    $cleanup_option = 'metasync_cache_cleanup_done';
    if(!get_option($cleanup_option)){
        # Delete cache contents but keep main metasync_caches folder
        if(defined('WP_CONTENT_DIR')){
            $main_cache_dir = WP_CONTENT_DIR . '/metasync_caches';
            if(is_dir($main_cache_dir)){
                # Delete only the contents of subfolders, not the folders themselves
                $subfolders = ['pages', 'posts', 'others'];
                foreach($subfolders as $subfolder){
                    $subfolder_path = $main_cache_dir . '/' . $subfolder;
                    if(is_dir($subfolder_path)){
                        # Delete all files in the subfolder but keep the folder
                        $files = glob($subfolder_path . '/*.html');
                        foreach($files as $file){
                            if(is_file($file)){
                                unlink($file);
                            }
                        }
                    }
                }
            }
        }
        # Mark cleanup as done so it only runs once
        update_option($cleanup_option, true);
    }

    # DISABLED: No need for periodic cache clearing since cache is disabled
    # metasync_clear_existing_metasync_caches();

    # exclude AJAX request and all woocommerce pages from OTTO
    if (
        # disable ajax calls
        isset($_GET['ucfrontajaxaction']) ||
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
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    ) {
        return;
    }

    # fetch globals
    global $metasync_options, $otto_enabled;

    # check for the disable otto for logged in users option
    if(!empty($metasync_options['general']['otto_disable_on_loggedin']) AND $metasync_options['general']['otto_disable_on_loggedin'] == 'true'){

        # get user 
        $current_user = wp_get_current_user();

        # check if user is logged in
        if( !empty($current_user->ID)){

            return;
        }
    }



    # check if we are having an otto request
    if(!empty($_GET['is_otto_page_fetch'])){
        $_SERVER['REQUEST_URI'] = remove_query_arg('is_otto_page_fetch', $_SERVER['REQUEST_URI']);
        return;
    }

    # to avoid unnecessary processese
    # check that otto is configured 
    # And the UUID is properly set before running OTT

    # check that we have the option
    if(empty($metasync_options['general']['otto_pixel_uuid'])){
        return;
    }

    # check that otto is enabled 
    if(!$otto_enabled){
        return;
    }  

    # get the otto uuid
    $otto_uuid = $metasync_options['general']['otto_pixel_uuid'];

    # start the class
    $otto = new Metasync_otto_pixel($otto_uuid);

    # call render
    $otto->render_route_html();
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
function metasync_clear_otto_cache_handler() {
    if (!empty($_GET['clear_otto_cache'])) {

        # call function to invalidate all cached
        metasync_invalidate_all_caches();
        
        # clear the otto cache
        wp_send_json_success(['message' => 'OTTO cache cleared']);
    } 
    else {

        # 
        wp_send_json_error(['message' => 'Missing parameter']);
    }
}

# Clear cache hook
add_action('wp_ajax_clear_otto_cache', 'metasync_clear_otto_cache_handler');

# add admin action to check script
function metasync_show_otto_ssr_notice() {
    if (!current_user_can('manage_options')) {
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

        # Get OTTO UUID from settings
        $metasync_options = get_option('metasync_options');
        $otto_uuid = $metasync_options['general']['otto_pixel_uuid'] ?? '';
        
        if (empty($otto_uuid)) {
            return false;
        }

        # Fetch SEO data from OTTO API
        $seo_data = metasync_fetch_otto_seo_data($route, $otto_uuid);
        
        if (!$seo_data) {
            return false;
        }

        # Get WordPress post ID from URL
        $post_id = url_to_postid($route);
        
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
        
        # Verify this is actually a post or page
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, ['post', 'page'])) {
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
        error_log("MetaSync OTTO: Exception in SEO processing: " . $e->getMessage());
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



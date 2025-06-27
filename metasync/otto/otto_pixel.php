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
    $otto_tag = '<meta name="otto" content="uuid='.$metasync_options['general']['otto_pixel_uuid'].'; type=wordpress; enabled='.$string_enabled.'">';

    # out the otto tag
    echo $otto_tag;
});

/**
 * Start end point to handle requests on page updates
 * The Otto Crawler will call this end point once a page is updated
 **/

# function to register the route
function otto_crawl_notify($request){
    
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

        # do the delete
        $otto_pixel->refresh_cache($route);
    }


    # Handle the POST request
    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'Otto crawl notification received',
    ), 200);
}

# delete a directory
function deleteDir($dir) {

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
            deleteDir($filePath);
        } 
        else {
            unlink($filePath);
        }
    }

    # return result of removing dir
    return rmdir($dir);
}

function invalidate_all_caches($folder = ''){
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
        deleteDir($cache_dir);
    }

}

function clear_existing_metasync_caches(){

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
            invalidate_all_caches('general');
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
            invalidate_all_caches('pages');
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
            invalidate_all_caches('posts');
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
            invalidate_all_caches('general');
        }
    }

    
    # create the option
    update_option($option_name, $last_change_time);

    return true;
}

function start_otto(){

    # function to clear the caches
    # this is to support resolving the bug shipped in the earlier version
    clear_existing_metasync_caches();

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
        # AJAX request
        (function_exists('wp_doing_ajax') && wp_doing_ajax())
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
        #error_log('Metasync :: Skipping Otto Route, user agent => ' . $user_agent);
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
function check_otto_js(){

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
function clear_otto_cache_handler() {
    if (!empty($_GET['clear_otto_cache'])) {

        # call function to invalidate all cached
        invalidate_all_caches();
        
        # clear the otto cache
        wp_send_json_success(['message' => 'Otto cache cleared']);
    } 
    else {

        # 
        wp_send_json_error(['message' => 'Missing parameter']);
    }
}

# Clear cache hook
add_action('wp_ajax_clear_otto_cache', 'clear_otto_cache_handler');

# add admin action to check script
function show_otto_ssr_notice() {
    if (!current_user_can('manage_options')) {
        return; // Only show to admins
    }

    if (check_otto_js()) {
        echo '<div class="notice notice-error"><p><strong>Warning:</strong> Otto JavaScript has been detected on your site. Please remove it and configure Otto for Wordpress. Contact support for help</p></div>';
    }
}

add_action('admin_notices', 'show_otto_ssr_notice');

# staging dummy change
# load otto in the wp hook 
add_action('wp', 'start_otto');



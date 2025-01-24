<?php 
/**
 * This handles the OTTO SSR
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

# include the otto class file
require_once plugin_dir_path( __FILE__ ) . '/vendor/autoload.php';
require_once plugin_dir_path( __FILE__ ) . '/Otto_html_class.php';
require_once plugin_dir_path( __FILE__ ) . '/Otto_pixel_class.php';

# get the metasync options
$options = get_option('metasync_options');

# check otto enabled 
$otto_enabled = $options['general']['otto_enable'] ?? false;

# add tag to wp head
add_action('wp_head', function(){
    # load globals
    global $options, $otto_enabled;

    # string value otto status
    $string_enabled = $otto_enabled ? 'true' : 'false';

    # adding the otto tag to pages
    $otto_tag = '<meta name="otto" content="uuid='.$options['general']['otto_pixel_uuid'].'; type=wordpress; enabled='.$string_enabled.'">';

    # out the otto tag
    echo $otto_tag;
});


function start_otto(){
    # fetch globals
    global $options, $otto_enabled;

    # check the user agent
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? false;

    # check if we are having an otto request
    if(!empty($_GET['is_otto_page_fetch']) || trim($user_agent) == 'SearchAtlas Bot (https://www.searchatlas.com)'){
        error_log('Metasync :: Skipping Otto Route, user agent => ' . $user_agent);
        return;
    }

    # to avoid unnecessary processese
    # check that otto is configured 
    # And the UUID is properly set before running OTT

    # check that we have the option
    if(empty($options['general']['otto_pixel_uuid'])){
        return;
    }

    # check that otto is enabled 
    if(!$otto_enabled){
        return;
    }  

    # get the otto uuid
    $otto_uuid = $options['general']['otto_pixel_uuid'];

    # start the class
    $otto = new Metasync_otto_pixel($otto_uuid);

    # call render
    $otto->render_route_html();
}

# 
start_otto();
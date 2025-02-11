<?php
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

    # 
    function __construct($otto_uuid){
        
        # load the html class
        $this->o_html = new Metasync_otto_html($otto_uuid);

    }

    # method to handle cache refresh
    # mech : deletes the cache files and cals the file to referesh it
    function refresh_cache($route){
        
        #enforce dirs
        $cache_dir = $this->enforce_dirs();
        
        # create file path from route
        $file_name = md5($route).'.html';

        # create file path
        $file_path = $cache_dir . '/' . $file_name;

        # check if the file exists
        if(!is_file($file_path)){

            return false;
        }

        # now we do have the file 
        # step 1 : remove the file
        if( unlink($file_path) ) {

            # revisit the address to recreate the file
            #error_log('Revisiting the address ' . $route);
            return;
        }

    }


    # method to save crawl data into
    function save_crawl_data($data){

        # get the option name 
        $option_name = $this->option_name;

        # handle data
        $saved = get_option($option_name);

        # log saved
        #error_log('Saved : ' . print_r($saved, true));

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
        #error_log('New Data : ' . print_r($data, true));

        # save the option 
        update_option($option_name, $data);
    }

    # get the current route
    function get_route(){
        # get req scheme
        $scheme = ( is_ssl() ? 'https' : 'http' );
       
        # get req host
        $host = $_SERVER['HTTP_HOST'];
        
        # get the uri
        $request_uri = $_SERVER['REQUEST_URI'];
    
        # return the formatted url
        return $scheme . '://' . $host . $request_uri;
    }

    # get the html for a route 
    function get_route_html($route){

        # enforce cache dir
        $cache_dir = $this->enforce_dirs();

        # get md5 hash of route
        $file_name = md5($route).'.html';

        # get file path
        $file_path = $cache_dir . '/'. $file_name;

        # if file exists show content
        if(!is_file($file_path)){

            # call the html class
            $route_html = $this->o_html->process_route($route, $file_path);
        }
        else{

            # show the file contents
            $route_html = file_get_contents($file_path);
        }


        # exit to prevent further excecution
        return $route_html;
    }

    # enforce caching dir
    function enforce_dirs(){
        
        # check that we have the WP_CONTENT_DIR defined
        if(!defined('WP_CONTENT_DIR')){
            return false;
        }

        # Get the path to the wp-content directory
        $wp_content_dir = WP_CONTENT_DIR;

        # Define the path for the metasync_caches directory
        $cache_dir = $wp_content_dir . '/metasync_caches';

        # Check if the directory exists
        if (!is_dir($cache_dir)) {
            
            # Try to create the directory
            if (!mkdir($cache_dir, 0755, true)) {
                
                # Log an error if directory creation fails
                error_log('Failed to create directory: ' . $cache_dir);
                
                # Return false on failure
                return false;
            }
        }

        # Return true if the directory exists or was successfully created
        return $cache_dir;
    }

    # render route html
    function render_route_html(){
        
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
        # 
        $route = rtrim($route, '/');
        

        # check if we have the route html
        $route_html = $this->get_route_html($route);

        # check that route html is valid
        if(empty($route_html)){
            return false;
        }

        # continue to render the html
        echo $route_html;

        # prevent further wp execution
        exit();
    }
}
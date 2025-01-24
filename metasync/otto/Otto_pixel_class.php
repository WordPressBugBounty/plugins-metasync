<?php
/**
 * This class handles the Otto Pixel Functions
 */
Class Metasync_otto_pixel{
    
    #otto html class
    public $o_html;

    # 
    function __construct($otto_uuid){
        
        # load the html class
        $this->o_html = new Metasync_otto_html($otto_uuid);

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
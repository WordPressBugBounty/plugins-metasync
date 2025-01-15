<?php
/**
 * @see Logging Architecture
 * 
 * The Site Data class
 * This will help fetch relevant info 
 * about the client site
 * 
 * @param site_data = Json{
 *  
 * }
 */
class Site_data extends Log_Manager {

    #site data file
    private $site_data_file = 'site_data.json';
    
    #collect site data
    function fetch_site_data(){

        # Include the plugin.php file to use get_plugins()
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        #get the plugins
        $all_plugins = get_plugins();

        #get the active plugins
        $active_plugins = get_option('active_plugins', []);

        #create the plugin data array
        $plugins_data = array(
            'active' => [],
            'inactive' => []
        );

        #loop all to sort
        foreach($all_plugins AS $plugin_file => $plugin_data){

            #check if is in active
            if(in_array($plugin_file, $active_plugins)){

                #add it to the active group
                $plugins_data['active'] = $plugin_data;
                
                #
                continue;
            }

            #otherwise add it to inactive
            $plugins_data['inactive'] = $plugin_data;
            
        }

        # Fetch active theme data
        $theme = wp_get_theme();
        
        # get theme data
        $theme_data = [
            'name' => $theme->get('Name'),
            'version' => $theme->get('Version')
        ];

        #now get other site data
        $site_data = [
            'wp_version' => get_bloginfo('version'),
            'site_url' => site_url(),
            'php_version' => phpversion(),
            'server_type' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'plugins' => $plugins_data, 
            'themes' => $theme_data
        ];


        return $site_data;
    }


    # save site data to function
    function save_site_data(){

        # get the site site data
        $site_data = $this->fetch_site_data();

        # log path
        $site_data_file = $this->metasync_logs_path . '/' .$this->site_data_file;

        # now save the file 
        if(file_put_contents($site_data_file, json_encode($site_data, JSON_PRETTY_PRINT))){
            return true;
        }

        # otherwise
        return false;
    }
}
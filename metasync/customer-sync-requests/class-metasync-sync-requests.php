<?php

/**
 * The header and footer code snippets functionality of the plugin.
 *
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/customer-sync-requests
 * @author     Engineering Team <support@searchatlas.com>
 */

// Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Sync_Requests
{
    /**
     * Data or Response received from HeartBeat API for admin area.
     */
    public function SyncCustomerParams()
    {
        $categories_sync_limit = 1000;
        $users_sync_limit = 1000;

        # get the metasync options array
        $metasync_options = Metasync::get_option();

        # set the general option
        $general_options = $metasync_options['general'] ?? [];

        if (!isset($general_options['apikey'], $general_options['searchatlas_api_key'])) {
            return;
        }


        # From Feature Issue #132
        # We need to alter this url based on API key
        # so let's fethc the api key first
        
        $api_key = $general_options['searchatlas_api_key'] ?? null;

        #check that the api key is not empty

        if ($api_key == null){
            return false;
        }

        # last hb request 
        $last_hb_request_time = $metasync_options['general']['last_heart_beat'] ?? 0;

        # introducing a last call time
        # making sure requests are at least 5 min apart if hb
        if(isset($_POST['is_heart_beat']) AND $_POST['is_heart_beat'] == true){
            
            # chec that we have a 5 min gap
            if(($last_hb_request_time + (60*5)) > time()){

                error_log('Metasync Enforcing 5 min gap');
                return false;
            }

            # set the last heart beat request time
            $metasync_options['general']['last_heart_beat'] = time();
        }


        #the native api url
        $apiUrl = 'https://ca.searchatlas.com/api/wp-website-heartbeat/';
        
        #check if the api key starts with pub
        if(strpos($api_key, 'pub-') === 0){

            #set the heart beat url to the new one
            $apiUrl = 'https://api.searchatlas.com/api/publisher/one-click-publishing/wp-website-heartbeat/';
        }

        $new_categories = $this->post_categories();
        $this->saveHeartBeatError('categories', 'The limit of categories is exceeded', $new_categories, $categories_sync_limit);

        $users = get_users();
        $new_users = [];
        $user_count = 1;
        # Get the default user role from WordPress settings
        $default_role = get_option('default_role');
        foreach ($users as $user) {
            if ($user_count <= $users_sync_limit) {
                # Check if roles are not empty
                $user_role = (!empty($user->roles) && isset($user->roles[0])) ? $user->roles[0] : $default_role;
                # Get User Data 
                $user_data = get_userdata($user->ID);
                $new_users[] = [
                    'id'            => $user->ID,
                    'user_login'    => $user_data->user_login, # Ensure user_login is fetched
                    'user_email'    => $user_data ? $user_data->user_email : $user->user_email, # Ensure email is fetched
                    'role'          => $user_role   # User role

                ];
            }
            $user_count++;
        }

        $this->saveHeartBeatError('users', 'The limit of users is exceeded', $new_users, $users_sync_limit);
        $current_permalink_structure = get_option('permalink_structure');
        $current_rewrite_rules = get_option('rewrite_rules');
        $enabled_plugin_editor = Metasync::get_option('general')['enabled_plugin_editor'] ?? '';                
        // Check if Elementor is active
      //  $elementor_active = is_plugin_active('elementor/elementor.php');

        $payload = [
            'url' => get_home_url(),
            'api_key' => $general_options['apikey'],
            'categories' => $new_categories,
            'users' => $new_users,
            'version'=>constant('METASYNC_VERSION'),
            'permalink_structure'=>((($current_permalink_structure == '/%post_id%/' || $current_permalink_structure == '') && $current_rewrite_rules == '')?false:true),
         //   'page_builder'=> (($enabled_plugin_editor == 'elementor' && $elementor_active && $enabled_plugin_editor!='' )?'elementor':'gutenberg') 

        ];

        $data = [
            'body'          => $payload,
            'headers'       => [
                'x-api-key' =>  $general_options['searchatlas_api_key'],
               
            ],
        ];

        $response           = wp_remote_post($apiUrl, $data);
        $response_code      = wp_remote_retrieve_response_code( $response );
        $response_message   = wp_remote_retrieve_response_message( $response );

        if ( 200 != $response_code && ! empty( $response_message ) ) {
            $this->saveHeartBeatError('heartbeat', $response_code . ": " . $response_message, array(1), 0);
            return; //new WP_Error( $response_code, $response_message );
        } elseif ( 200 != $response_code ) {
            $this->saveHeartBeatError('heartbeat', $response_code . ': Unknown error occurred', array(1), 0);
            return; //new WP_Error( $response_code, 'Unknown error occurred' );
        } else {

            # update the metasync options
            Metasync::set_option($metasync_options);

            return $response;//json_decode( wp_remote_retrieve_body( $response ), true );
        }
    }
    public function post_categories() {
		$categories = get_categories(array(
			'hide_empty' => false,
		));
	
		$categories = array_map(function($category) {
			return [
				'id' => $category->term_id,
				'name' => $category->name,
				'parent' => $category->parent,
			];
		}, $categories);
	
		$hierarchy = $this->build_category_hierarchy($categories);
	
		return $hierarchy;
	}
	
	public function build_category_hierarchy($categories, $parentId = 0) {
		$result = [];
		foreach ($categories as $category) {
			if ($category['parent'] == $parentId) {
				$children =  $this->build_category_hierarchy($categories, $category['id']);
				if ($children) {
					$category['children'] = $children;
				}
				$result[] = $category;
			}
		}
		return $result;
	}

    public function saveHeartBeatError($attribute, $description, $records, $limit)
    {
        $records_count = count($records);
        if ($records_count > $limit) {
            $HeartBeatDatabase = new Metasync_HeartBeat_Error_Monitor_Database();
            $args = [
                'attribute_name'    => $attribute,
                'object_count'      => $records_count,
                'error_description' => $description,
            ];
            $HeartBeatDatabase->add($args);
        }
    }

    /**
     * Data or Response received from HeartBeat API for admin area.
     */
    public function SyncWhiteLabelUserHttp()
    {
        $general_options = Metasync::get_option('general') ?? [];

        if (!isset($general_options['apikey'], $general_options['searchatlas_api_key'])) {
            return;
        }

        $url = "https://api.searchatlas.com/api/customer/account/user/"; // the URL to request

        delete_option(Metasync::option_name . '_whitelabel_user');

        $headers = array(
            'x-api-key'=>$general_options['searchatlas_api_key'] // this should be associative array not a array of string
        );
        $args = array(
            'headers' => $headers
        ); 
        
        $response = wp_remote_get($url, $args);

        $result = wp_remote_retrieve_body($response);

        if ($result && !empty($result['company_name'])) {
            update_option(Metasync::option_name . '_whitelabel_user', $result['company_name']);
        }
    }
}

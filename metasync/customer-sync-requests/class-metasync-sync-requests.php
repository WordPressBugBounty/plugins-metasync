<?php

/**
 * The header and footer code snippets functionality of the plugin.
 *
 *
 * @link       http://linkgraph.io
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/customer-sync-requests
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
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

        $general_options = Metasync::get_option('general') ?? [];
        $apiUrl = 'https://ca.searchatlas.com/api/wp-website-heartbeat/';

        if (!isset($general_options['apikey'], $general_options['searchatlas_api_key'])) {
            return;
        }

        $new_categories = $this->post_categories();
        $this->saveHeartBeatError('categories', 'The limit of categories is exceeded', $new_categories, $categories_sync_limit);

        $users = get_users();
        $new_users = [];
        $user_count = 1;
        foreach ($users as $user) {
            if ($user_count <= $users_sync_limit) {
                $new_users[] = [
                    'id'            => $user->ID,
                    'user_login'    => $user->user_login,
                    'user_email'    => $user->user_email,
                    'role'          => $user->roles[0]
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
            'x-api-key: ' . $general_options['searchatlas_api_key']
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

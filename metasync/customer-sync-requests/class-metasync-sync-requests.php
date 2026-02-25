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
    public function SyncCustomerParams($token = null)
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

        # PR3: Throttle depends on state — burst (KEY_PENDING within 30 min) allows 30s; else 5 min
        $is_heartbeat = isset($_POST['is_heart_beat']) && $_POST['is_heart_beat'] == true;
        $is_burst = !empty($_POST['is_burst']);
        $heartbeat_state = $metasync_options['general']['heartbeat_state'] ?? '';
        $state_changed_at = (int) ($metasync_options['general']['heartbeat_state_changed_at'] ?? 0);
        $burst_window_end = $state_changed_at + (30 * 60); // 30 min cap
        $in_burst_window = ($heartbeat_state === 'KEY_PENDING' && $burst_window_end > time());
        $min_interval_sec = ($in_burst_window || $is_burst) ? 30 : (60 * 5);

        if ($is_heartbeat) {
            if (($last_hb_request_time + $min_interval_sec) > time()) {
                $remaining_time = ($last_hb_request_time + $min_interval_sec) - time();
                $remaining_minutes = max(1, ceil($remaining_time / 60));
                return (object) [
                    'error' => 'throttled',
                    'message' => 'Please make another request after ' . $remaining_minutes . ' minute(s)',
                    'remaining_minutes' => $remaining_minutes,
                    'last_request_time' => $last_hb_request_time,
                    'throttled' => true
                ];
            }
            $metasync_options['general']['last_heart_beat'] = time();
        }


        #the native api url - use endpoint manager for dynamic environment support
        $ca_api_domain = class_exists('Metasync_Endpoint_Manager')
            ? Metasync_Endpoint_Manager::get_endpoint('CA_API_DOMAIN')
            : Metasync::CA_API_DOMAIN;
        $apiUrl = $ca_api_domain . '/api/wp-website-heartbeat/';

        #check if the api key starts with pub
        if(strpos($api_key, 'pub-') === 0){

            #set the heart beat url to the new one
            $api_domain = class_exists('Metasync_Endpoint_Manager')
                ? Metasync_Endpoint_Manager::get_endpoint('API_DOMAIN')
                : Metasync::API_DOMAIN;
            $apiUrl = $api_domain . '/api/publisher/one-click-publishing/wp-website-heartbeat/';
        }

        $new_categories = $this->post_categories();
        $this->saveHeartBeatError('categories', 'The limit of categories is exceeded', $new_categories, $categories_sync_limit);

       # $users = get_users();
       # $new_users = [];
        # Get selected roles for Content Genius sync with safety checks
        $selected_roles = isset($general_options['content_genius_sync_roles']) && is_array($general_options['content_genius_sync_roles']) 
            ? $general_options['content_genius_sync_roles'] 
            : array();
        
        # If it's a string (single role from old version), convert to array
        if (!is_array($selected_roles)) {
            $selected_roles = !empty($selected_roles) ? array($selected_roles) : array();
        }
        
        # Sanitize role values to prevent injection
        $selected_roles = array_map('sanitize_key', $selected_roles);
        
        # Prepare optimized user query arguments - only fetch required fields
        $user_query_args = array(
            'number' => $users_sync_limit,
            'fields' => array('ID', 'user_login', 'user_email'), // Only fetch needed fields for performance
            'orderby' => 'ID',
            'order' => 'ASC'
        );
        
        # If specific roles are selected and "all" is not selected, filter by those roles
        if (!empty($selected_roles) && !in_array('all', $selected_roles, true)) {
            # Only add role filter if we have valid roles
            $valid_roles = array_filter($selected_roles, function($role) {
                return !empty($role) && $role !== 'all';
            });
            
            if (!empty($valid_roles)) {
                $user_query_args['role__in'] = $valid_roles;
            }
        }
        
        # Fetch users based on the selected roles with error handling
        $users = get_users($user_query_args);
        
        # Safety check: ensure $users is an array
        if (!is_array($users)) {
            $users = array();
        }
        
        $new_users = array();
        $user_count = 1;
        # Get the default user role from WordPress settings
       # $default_role = get_option('default_role');

       # Get the default user role from WordPress settings (fallback to administrator)
        $default_role = get_option('default_role', 'administrator');

        foreach ($users as $user) {
            if ($user_count <= $users_sync_limit) {
               
                $user_data = get_userdata($user->ID);
                # Skip if user data is invalid
                if (!$user_data || !is_object($user_data)) {
                    continue;
                }
                
                # Get user role with proper safety checks
                $user_role = $default_role;
                if (is_array($user_data->roles) && !empty($user_data->roles)) {
                    $user_role = isset($user_data->roles[0]) ? $user_data->roles[0] : $default_role;
                }
                
                # Prepare user data with proper sanitization
                # Using data from optimized query (ID, user_login, user_email already fetched)
                $new_users[] = array(
                    'id'            => absint($user->ID),
                    'user_login'    => isset($user->user_login) ? sanitize_user($user->user_login) : '',
                    'user_email'    => isset($user->user_email) ? sanitize_email($user->user_email) : '',
                    'role'          => sanitize_key($user_role)
                );
            }
            $user_count++;
        }

        $this->saveHeartBeatError('users', 'The limit of users is exceeded', $new_users, $users_sync_limit);
        $current_permalink_structure = get_option('permalink_structure');
        $current_rewrite_rules = get_option('rewrite_rules');

        $payload = [
            'url' => get_home_url(),
            'api_key' => $general_options['apikey'],
            'categories' => $new_categories,
            'users' => $new_users,
            'version'=>constant('METASYNC_VERSION'),
            'permalink_structure'=>((($current_permalink_structure == '/%post_id%/' || $current_permalink_structure == '') && $current_rewrite_rules == '')?false:true),
            'otto_pixel_uuid' => $general_options['otto_pixel_uuid'] ?? '',
        ];

        # append login auth token to payload
        if(!empty($token)){
            $payload['login_auth_token'] = $token;
        }

        $data = [
            'body'          => $payload,
            'headers'       => [
                'x-api-key' =>  $general_options['searchatlas_api_key'],

            ],
            # PERFORMANCE OPTIMIZATION: Add timeout for sync operations
            'timeout' => 15,
        ];

        $response           = wp_remote_post($apiUrl, $data);

        # PERFORMANCE OPTIMIZATION: Handle timeout and connection errors
        if (is_wp_error($response)) {
            error_log('MetaSync: Heartbeat sync failed: ' . $response->get_error_message());
            $this->saveHeartBeatError('heartbeat', 'Connection error: ' . $response->get_error_message(), array(1), 0);
            return;
        }

        $response_code      = wp_remote_retrieve_response_code( $response );
        $response_message   = wp_remote_retrieve_response_message( $response );

        if ( 200 != $response_code && ! empty( $response_message ) ) {
            $this->saveHeartBeatError('heartbeat', $response_code . ": " . $response_message, array(1), 0);
            return; //new WP_Error( $response_code, $response_message );
        } elseif ( 200 != $response_code ) {
            $this->saveHeartBeatError('heartbeat', $response_code . ': Unknown error occurred', array(1), 0);
            return; //new WP_Error( $response_code, 'Unknown error occurred' );
        } else {

            # PR2: Parse heartbeat response for UUID self-healing and clone detection
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            $current_uuid = $metasync_options['general']['otto_pixel_uuid'] ?? '';
            if (is_array($response_body) && !empty($response_body['otto_pixel_uuid'])) {
                $response_uuid = sanitize_text_field($response_body['otto_pixel_uuid']);
                if (!empty($response_body['uuid_mismatch'])) {
                    # Domain clone: backend says local UUID is wrong for this domain
                    $metasync_options['general']['otto_pixel_uuid'] = $response_uuid;
                    error_log('MetaSync: UUID corrected from ' . $current_uuid . ' to ' . $response_uuid . ' (domain clone detected)');
                } elseif (empty($current_uuid)) {
                    # Self-heal: SSO callback failed but API key was saved; recover UUID from heartbeat
                    $metasync_options['general']['otto_pixel_uuid'] = $response_uuid;
                    error_log('MetaSync: UUID set from heartbeat response (self-heal after SSO callback missed)');
                }
            }

            # PR3: Server confirmation → transition to CONNECTED (backend sends registered: true; accept both for compatibility)
            if (is_array($response_body) && (!empty($response_body['registered']) || !empty($response_body['heartbeat_confirmed']))) {
                $metasync_options['general']['heartbeat_state'] = 'CONNECTED';
                $metasync_options['general']['heartbeat_state_changed_at'] = time();
            }

            # Granular otto_config_status: record last successful heartbeat (ISO 8601 UTC)
            $metasync_options['general']['last_heartbeat_at'] = gmdate('Y-m-d\TH:i:s\Z');
            Metasync::set_option($metasync_options);

            return $response;
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

        # Use endpoint manager for dynamic environment support
        $api_domain = class_exists('Metasync_Endpoint_Manager')
            ? Metasync_Endpoint_Manager::get_endpoint('API_DOMAIN')
            : Metasync::API_DOMAIN;
        $url = $api_domain . "/api/customer/account/user/"; // the URL to request

        delete_option(Metasync::option_name . '_whitelabel_user');

        $headers = array(
            'x-api-key'=>$general_options['searchatlas_api_key'] // this should be associative array not a array of string
        );
        $args = array(
            'headers' => $headers,
            # PERFORMANCE OPTIMIZATION: Add timeout to prevent hung requests
            'timeout' => 10,
        );

        $response = wp_remote_get($url, $args);

        # PERFORMANCE OPTIMIZATION: Handle timeout and connection errors
        if (is_wp_error($response)) {
            error_log('MetaSync: White label user sync failed: ' . $response->get_error_message());
            return;
        }

        $result = wp_remote_retrieve_body($response);

        if ($result && !empty($result['company_name'])) {
            update_option(Metasync::option_name . '_whitelabel_user', $result['company_name']);
        }
    }
}

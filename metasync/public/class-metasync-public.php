<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}


/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 *
 * @package    Metasync
 * @subpackage Metasync/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    Metasync
 * @subpackage Metasync/public
 * @author     Engineering Team <support@searchatlas.com>
 */

class Metasync_Public
{

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since	1.0.0
	 * @param	string	$plugin_name	The name of the plugin.
	 * @param	string	$version		The version of this plugin.
	 */

	private const namespace = "metasync/v1";

	private $escapers;
	private $replacements;
	private $common;
	private $allowed_attributes;
	private $schema;
	private $metasync_option_data;

	public function __construct($plugin_name, $version)
	{
		$this->plugin_name = $plugin_name;
		$this->version = $version;
		$this->allowed_attributes = array(
			'ID',
			'meta_description',
			'meta_robots',
			'meta_canonical',
			'permalink',
			'post_id',
			'post_title',
			'post_type',
			'post_content',
			'post_author',
			'post_date',
			'post_modified',
			'post_name',
			'post_parent',
			'post_status',
		);
		$this->escapers = array("\\", "/", "\"");
		$this->replacements = array("", "", "");
		$this->common = new Metasync_Common();
		// get all options
		$this->metasync_option_data = Metasync::get_option('general');
		add_action('wp_ajax_metasyn_otto_ajax_action', array($this,'metasyn_otto_ajax'));
	}

	private function filter_post_attributes($posts)
	{
		$pi = -1;
		foreach ($posts as $post) {
			$pi++;
			if ($post == null)
				return false; // post not found

			foreach ($post as $key => $value) {
				if (!in_array($key, $this->allowed_attributes)) {
					unset($posts[$pi]->{$key});
				}
			}
			$posts[$pi]->post_id = $posts[$pi]->ID;
			$posts[$pi]->permalink = get_permalink($posts[$pi]->ID);
		}
		return $posts;
	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles()
	{

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Metasync_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Metasync_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */
		$enabled_plugin_css = Metasync::get_option('general')['enabled_plugin_css'] ?? '';                
		if($enabled_plugin_css!=="default" && $enabled_plugin_css!=='' ){
			wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/metasync-public.css', array(), $this->version, 'all');
		}
	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts()
	{

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Metasync_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Metasync_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		 # Enqueue the JavaScript file 'otto-tracker.js' with defer for better performance
		# Load in footer (true) and add defer attribute to prevent render blocking
		wp_enqueue_script($this->plugin_name . '-tracker', plugin_dir_url(__FILE__) . 'js/otto-tracker.min.js', array('jquery'), $this->version, true);
		wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/metasync-public.js', array('jquery'), $this->version, true);

		
		# Get the Otto Pixel UUID from plugin settings.
		$otto_uuid = Metasync::get_option('general')['otto_pixel_uuid'] ?? '';

		# Get the current full page URL safely.
		$page_url = esc_url(home_url(add_query_arg([], $_SERVER['REQUEST_URI'])));

		# Pass PHP data to the JavaScript file using wp_localize_script.
		# OTTO SSR is always enabled by default when UUID is set
		if (!empty($otto_uuid)) {
			wp_localize_script($this->plugin_name . '-tracker', 'saOttoData', [
				'otto_uuid' => $otto_uuid,
				'page_url'  => $page_url,
				'context'   => null,
				'enable_metadesc' => true, // Always enabled by default
			]);
		}

		# Add defer attribute to OTTO scripts for performance optimization
		add_filter('script_loader_tag', array($this, 'add_defer_attribute'), 10, 2);
	}

	/**
	 * Add defer attribute to OTTO scripts to prevent render blocking
	 * This improves PageSpeed scores by allowing scripts to load asynchronously
	 *
	 * @since    1.0.0
	 * @param    string    $tag     The script tag
	 * @param    string    $handle  The script handle
	 * @return   string    Modified script tag with defer attribute
	 */
	public function add_defer_attribute($tag, $handle) {
		# Only add defer to our OTTO scripts
		if ($handle === $this->plugin_name . '-tracker' || $handle === $this->plugin_name) {
			# Check if defer is not already present
			if (strpos($tag, 'defer') === false) {
				# Add defer attribute before the closing >
				$tag = str_replace(' src', ' defer src', $tag);
			}
		}
		return $tag;
	}
	public function metasyn_otto_ajax() {
		// Check nonce for security
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'otto_nonce')) {
			wp_send_json_error('Invalid nonce');
			wp_die();
		}
		$post_id = sanitize_text_field($_POST['post_id']);
		$current_url = get_permalink($post_id);
		
		$header_html = get_post_meta($post_id, '_otto_header_html_json', true);

		// Example API call using wp_remote_get()
		
	
		if (is_wp_error($header_html)) {
			wp_send_json_error('API call failed');
		} else {
			wp_send_json_success(json_decode($header_html, true));
		}
	
		wp_die(); // Always terminate after an AJAX call
	}

	public function otto_header_data() {
		global $post;
	
		// Get the current post ID
		$post_id = $post->ID;
	
		// Get the current time and the last update time from post meta
		$current_time = current_time('timestamp');
		$last_update_time = get_post_meta($post_id, '_otto_last_update_time', true);
	
		// Set the interval for 24 hours (in seconds)
		$interval = 24 * 60 * 60;
	
		// Check if the last update time is set or if 24 hours have passed
		if (!$last_update_time || ($current_time - $last_update_time) >= $interval) {
			// Get the current URL
			$current_url = get_permalink($post_id);

			// Use endpoint manager to get the correct API URL
			$api_endpoint = class_exists('Metasync_Endpoint_Manager')
				? Metasync_Endpoint_Manager::get_endpoint('OTTO_URL_DETAILS')
				: 'https://sa.searchatlas.com/api/v2/otto-url-details';

			// Call the API
			$response = wp_remote_get($api_endpoint . '/?url=' . urlencode($current_url));

			// Check if the API call was successful
			if (!is_wp_error($response)) {
				$body = wp_remote_retrieve_body($response);
				$data = json_decode($body, true);  // Decode JSON into an associative array
	
				// Update the post meta with the new data and timestamp
				update_post_meta($post_id, '_otto_header_html', $data['header_html_insertion']);
				update_post_meta($post_id, '_otto_last_update_time', $current_time);
			}
		}
	
	// Get the saved HTML from post meta
	$header_html = get_post_meta($post_id, '_otto_header_html', true);

	// Display the HTML with security measures
	if ($header_html) {
		echo "<!-- Otto Start -->";
		// SECURITY FIX: Sanitize HTML to prevent XSS while allowing safe HTML
		echo wp_kses($header_html, array(
			'style' => array(),
			'link' => array('rel' => array(), 'href' => array(), 'type' => array()),
			'meta' => array('name' => array(), 'content' => array(), 'property' => array()),
			'script' => array('type' => array(), 'src' => array()),
			// Add other safe tags as needed
		));
		echo "<!-- Otto End -->";
	}
	}

	public function metasync_plugin_init()
	{
		$this->rest_authorization_middleware();
		$this->shortcodes_init();
	}

	public function shortcodes_init()
	{
		# add_shortcode('accordion', 'metasync_accordion');

		# name changed from accordion to accordion_metasync to avoid conflict with other plugins
		add_shortcode('accordion_metasync', 'metasync_accordion');
		// add_shortcode('markdown', 'metasync_markdown');


	function metasync_accordion($atts, $content = "")
	{
		// SECURITY FIX: Properly escape shortcode attributes
		$title = isset($atts['title']) ? esc_html($atts['title']) : '';
		$safe_content = wp_kses_post($content);
		
		$block = "<div class=\"metasync-accordion-block\">
			<button class=\"metasync-accordion\">{$title}</button>
			<div class=\"metasync-panel\">{$safe_content}</div>
			</div>";
		return $block;
	}
	}

	public function rest_authorization_middleware()
	{
		# $get_data = sanitize_post($_GET);

		# mailchamp causing fatal error if sanitize_post used
		$get_data = array_map('sanitize_text_field', $_GET);
		if (!isset($get_data['apikey']))
			return false;
		# $apiKey = sanitize_text_field($get_data['apikey']) ?? null;
		$apiKey = $get_data['apikey'] ?? null;

		$getOptions = Metasync::get_option('general');
		$getApiKeyFromSettings = $getOptions['apikey'] ?? null;

		if ($apiKey === $getApiKeyFromSettings)
			return true;
		return false;
	}

	public function metasync_register_rest_routes()
	{
		// Critical Routes
		/*
				  createItem
				  createPage
				  updateItems
				  updatePage
				  deleteItem
				  getPagesList
				  getPostByURL
			  */
		register_rest_route(
				$this::namespace ,
			'getItems',
			array(
				array(
					'methods' => 'GET',
					'callback' => array($this, 'get_items'),
					'permission_callback' => array($this, 'rest_authorization_middleware')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);


		
			register_rest_route($this::namespace , 'postCategories',array(
					array(
						'methods' => 'GET',
						'callback' => array($this, 'post_categories'),
						'permission_callback' => array($this, 'rest_authorization_middleware')
					),
					'schema' => array($this, 'get_item_schema'),
				)
			);
		

		register_rest_route(
				$this::namespace ,
			'updateItems',
			array(
				array(
					'methods' => 'POST',
					'callback' => array($this, 'update_items'),
					'permission_callback' => array($this, 'rest_authorization_middleware')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		# add otto pixel rest route
		register_rest_route(
			$this::namespace ,
			'otto_crawl_notify',
			array(
				array(
					'methods' => 'POST',
					'callback' => 'metasync_otto_crawl_notify',
					'permission_callback' => array($this, 'rest_authorization_middleware')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		register_rest_route(
				$this::namespace ,
			'createItem',
			array(
				array(
					'methods' => 'POST',
					'callback' => array($this, 'create_item'),
					'permission_callback' => array($this, 'rest_authorization_middleware')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		register_rest_route(
				$this::namespace ,
			'setLandingPage',
			array(
				array(
					'methods' => 'POST',
					'callback' => array($this, 'set_landing_page'),
					'permission_callback' => array($this, 'rest_authorization_middleware')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		register_rest_route(
				$this::namespace ,
			'deleteItem',
			array(
				array(
					'methods' => 'DELETE',
					'callback' => array($this, 'delete_item'),
					'permission_callback' => array($this, 'rest_authorization_middleware')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		register_rest_route(
				$this::namespace ,
			'getPagesList',
			array(
				array(
					'methods' => 'GET',
					'callback' => function () {
						$pagesList = array();
						$pages = get_posts([
							'post_type' => 'page',
							'post_status' => array('publish'),
							'nopaging' => true
						]);
						foreach ($pages as $page) {
							array_push($pagesList, array(
								'post_id' => $page->ID,
								'post_title' => $page->post_title,
								'post_url' => get_permalink($page->ID), //$page->guid
							)
							);
						}
						return rest_ensure_response($pagesList);
					},
					'permission_callback' => array($this, 'rest_authorization_middleware')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		register_rest_route(
				$this::namespace ,
			'getPostByURL',
			array(
				array(
					'methods' => 'GET',
					'callback' => function () {
						$getPostID = url_to_postid(sanitize_url($_GET['url']));
						
						if ($getPostID==0) {
							$response = false;
						}else{
							$response = $this->filter_post_attributes([
							get_post($getPostID)
						]);
						}
						
						if ($response == false) {
							$response = ['post_id' => -1];
						} else {
							$response = $response[0];
						}
						return rest_ensure_response($response);
					},
					'permission_callback' => array($this, 'rest_authorization_middleware')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		register_rest_route(
				$this::namespace ,
			'createPage',
			array(
				array(
					'methods' => 'POST',
					'callback' => array($this, 'create_page'),
					'permission_callback' => array($this, 'rest_authorization_middleware')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		register_rest_route(
				$this::namespace ,
			'updatePage',
			array(
				array(
					'methods' => 'POST',
					'callback' => array($this, 'update_page'),
					'permission_callback' => array($this, 'rest_authorization_middleware')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		register_rest_route(
				$this::namespace ,
			'deletePage',
			array(
				array(
					'methods' => 'DELETE',
					'callback' => array($this, 'delete_page'),
					'permission_callback' => array($this, 'rest_authorization_middleware')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		register_rest_route(
				$this::namespace ,
			'posts',
			array(
				array(
					'methods' => 'GET',
					'callback' => function () {
						$query = new WP_Query(
							array(
								'nopaging' => true,
								'post_type' => array('post', 'page')
							)
						);
						return rest_ensure_response(
							$this->filter_post_attributes(
								$query->posts
							)
						);
					},
					'permission_callback' => array($this, 'rest_authorization_middleware')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);



		register_rest_route(
				$this::namespace ,
			'lglogin',
			array(
				array(
					'methods' => 'POST',
					'callback' => array($this, 'linkgraph_login'),
					'permission_callback' => array($this, 'rest_authorization_middleware')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		register_rest_route(
				$this::namespace ,
			'syncHeartbeatData',
			array(
				array(
					'methods' => 'POST',
					'callback' => array($this, 'sync_heartbeat_data'),
					'permission_callback' => array($this, 'rest_authorization_middleware')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		register_rest_route(
				$this::namespace ,
			'getHeartbeatErrorLogs',
			array(
				array(
					'methods' => 'GET',
					'callback' => array($this, 'get_heartbeat_errorlogs'),
					'permission_callback' => array($this, 'rest_authorization_middleware')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		register_rest_route(
				$this::namespace ,
			'getErrorLogs',
			array(
				array(
					'methods' => 'GET',
					'callback' => array($this, 'get_errorlogs'),
					'permission_callback' => array($this, 'rest_authorization_middleware')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		register_rest_route(
			$this::namespace ,
		'getPostByID',
		array(
				array(
					'methods' => 'GET',
					'callback' => function ($request) {
						$getPostID = $request->get_param('ID');
						if (is_null($getPostID) || !is_numeric($getPostID)) {
							wp_send_json_error(array('message' => 'ID is missing or invalid'), 400);
						}
						$post = get_post($getPostID);
						if ($post === null) {
							wp_send_json_error(array('message' => 'Post not found'), 400);
							
						}else{
							wp_send_json_success(array('message' => 'ID is valid'),200);
						}

							
						},
						'permission_callback' => array($this, 'rest_authorization_middleware')
					),
					'schema' => array($this, 'get_item_schema'),
				)
		);
		
		register_rest_route($this::namespace, 'pageList', array(
			'methods' => 'POST',
			'callback' => array($this, 'get_pages_list'),
			'args' => array(
				'post_type' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_string($param) && ($param === 'page' || $param === 'post');
					}
				)
			),
			'permission_callback' => array($this, 'rest_authorization_middleware'),
			'schema' => array($this, 'get_item_schema'),
		));

		# Add the new getPostData endpoint
		register_rest_route(
			$this::namespace,
			'getPostData',
			array(
				array(
					'methods' => 'GET',
					'callback' => array($this, 'get_post_data'),
					'permission_callback' => array($this, 'rest_authorization_middleware')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		# Search Atlas Connect callback endpoint - SA platform calls this with API key and Otto UUID
		register_rest_route(
			$this::namespace,
			'searchatlas/connect/callback',
			array(
				array(
					'methods' => 'POST',
					'callback' => array($this, 'handle_searchatlas_api_callback'),
					'permission_callback' => array($this, 'validate_searchatlas_callback_permission')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		# Key file creation endpoint
		register_rest_route(
			$this::namespace,
			'createKeyFile',
			array(
				array(
					'methods' => 'POST',
					'callback' => array($this, 'create_key_file'),
					'permission_callback' => array($this, 'rest_authorization_middleware')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		# OTTO SSR Status endpoint - Public endpoint to check if OTTO SSR is enabled
		register_rest_route(
			$this::namespace,
			'otto_ssr_status',
			array(
				array(
					'methods' => 'GET',
					'callback' => array($this, 'get_otto_ssr_status'),
					'permission_callback' => '__return_true' // Public access
				),
				array(
					'methods' => 'POST',
					'callback' => array($this, 'set_otto_ssr_status'),
					'permission_callback' => array($this, 'rest_authorization_middleware')
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		# OTTO Configuration Status endpoint - Check if OTTO is active and properly configured
		register_rest_route(
			$this::namespace,
			'otto_config_status',
			array(
				array(
					'methods' => 'GET',
					'callback' => array($this, 'get_otto_config_status'),
					'permission_callback' => '__return_true' // Public access
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		# Plugin Version endpoint - Returns the active plugin version
		register_rest_route(
			$this::namespace,
			'version',
			array(
				array(
					'methods' => 'GET',
					'callback' => array($this, 'get_plugin_version'),
					'permission_callback' => '__return_true' // Public access
				),
				'schema' => array($this, 'get_item_schema'),
			)
		);

		# Support Token Authentication endpoint

	}

	/**
	 * Get post data endpoint
	 * Accepts post_id or post_url and returns post details
	 * Only returns data for post type 'post'
	 */
	public function get_post_data($request) {
		$get_data = $request->get_params();
		$post_id = null;
		$post = null;

		# Fetch post
		if (!empty($get_data['post_id'])) {
			$post_id = intval($get_data['post_id']);
			$post = get_post($post_id);
		} elseif (!empty($get_data['post_url'])) {
			$post_id = url_to_postid(sanitize_url($get_data['post_url']));
			if ($post_id > 0) {
				$post = get_post($post_id);
			}
		}

		# Check if post exists
		if (!$post || $post_id <= 0) {
			return rest_ensure_response(array('error' => 'no blog post found'), 404);
		}

		# Check if post type is 'post', return error if not
		if ($post->post_type !== 'post') {
			return rest_ensure_response(array('error' => 'only post type is supported'), 400);
		}

		# Render content
  		$post_content = $post->post_content;
		if (strpos($post_content, '[et_pb_') !== false) {
			# Load Divi modules if available
			if (function_exists('et_builder_add_main_elements')) {
				et_builder_add_main_elements();
			}
			# Render Divi content to HTML
			if (function_exists('et_builder_render_layout')) {
				$post_content = et_builder_render_layout($post_content);
			} else {
				$post_content = 'Divi render function not found';
			}
		} else {
			# Standard WP content filter
			$post_content = apply_filters('the_content', $post_content);
		}

		# Get featured image URL (full size, or null)
        $featured_image = null;
        if (has_post_thumbnail($post_id)) {
            $featured_image = [
                'url'  => get_the_post_thumbnail_url($post_id, 'full'),
                'id'   => get_post_thumbnail_id($post_id),
                'alt'  => get_post_meta(get_post_thumbnail_id($post_id), '_wp_attachment_image_alt', true) ?: ''
            ];
        }

		# Get post categories
		$categories = get_the_category($post_id);
		$category_names = array();
		if (!empty($categories)) {
			foreach ($categories as $category) {
				$category_names[] = $category->name;
			}
		}

		# Prepare response data
		$response_data = array(
			'post_content' => $post_content,
			'post_title' => $post->post_title,
			'post_status' => $post->post_status,
			'otto_ai_page' => false,
			'comment_status' => $post->comment_status,
			'permalink' => $post->post_name,
			'is_landing_page' => false,
			'post_categories' => $category_names,
			'post_id' => $post_id,
			'post_type' => $post->post_type,
			'post_parent' => $post->post_parent,
			'featured_image'   => $featured_image
		);

		return rest_ensure_response($response_data);
	}

	/**
	 * Get OTTO SSR status endpoint
	 * Public endpoint to check if OTTO Server Side Rendering is enabled
	 * 
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response Response with active status
	 */
	public function get_otto_ssr_status($request) {
		# OTTO SSR is always enabled by default
		# Prepare response - always return active as true
		$response_data = array(
			'active' => 'true'
		);
		
		return rest_ensure_response($response_data);
	}

	/**
	 * Set OTTO SSR status endpoint
	 * Authenticated endpoint to activate or deactivate OTTO Server Side Rendering
	 * 
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response Response with updated active status
	 */
	public function set_otto_ssr_status($request) {
		# OTTO SSR is always enabled by default - this endpoint is kept for backwards compatibility
		# Prepare response - SSR is always active
		$response_data = array(
			'active' => 'true',
			'message' => 'OTTO SSR is always enabled by default.',
			'success' => true
		);
		
		return rest_ensure_response($response_data);
	}

	/**
	 * Get OTTO Configuration Status endpoint
	 * Public endpoint to check if OTTO is active and API Key/UUID are correctly set
	 * 
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response Response with configuration status
	 */
	public function get_otto_config_status($request) {
		# Get MetaSync options
		$metasync_options = get_option('metasync_options');
		$general_options = $metasync_options['general'] ?? array();
		
		# OTTO SSR is always enabled by default
		$is_otto_active = true;
		
		# Check if API Key is set (not empty)
		$api_key = $general_options['searchatlas_api_key'] ?? '';
		$has_api_key = !empty($api_key);
		
		# Check if UUID is set (not empty)
		$otto_uuid = $general_options['otto_pixel_uuid'] ?? '';
		$has_uuid = !empty($otto_uuid);
		
		# Determine if fully configured (API key and UUID set - SSR always active)
		$is_configured = $has_api_key && $has_uuid;
		
		# Granular status for backend messaging (PR1: heartbeat reliability)
		if ($has_api_key && $has_uuid) {
			$status = 'configured';
		} elseif ($has_api_key && !$has_uuid) {
			$status = 'plugin_active_sso_partial';
		} else {
			$status = 'plugin_active_no_sso';
		}
		
		# Plugin version and timestamps (ISO 8601 UTC where set)
		$plugin_version = defined('METASYNC_VERSION') ? METASYNC_VERSION : 'unknown';
		$last_heartbeat_at = $general_options['last_heartbeat_at'] ?? null;
		$sso_completed_at = $general_options['sso_completed_at'] ?? null;
		
		# Prepare response (backward compatible + new fields)
		$response_data = array(
			'status' => $status,
			'configured' => $is_configured,
			'otto_active' => $is_otto_active,
			'has_api_key' => $has_api_key,
			'has_uuid' => $has_uuid,
			'plugin_version' => $plugin_version,
			'last_heartbeat_at' => $last_heartbeat_at,
			'sso_completed_at' => $sso_completed_at,
		);
		
		return rest_ensure_response($response_data);
	}

	/**
	 * Get Plugin Version endpoint
	 * Public endpoint that returns the active plugin version
	 * 
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response Response with version information
	 * @since 2.5.15
	 */
	public function get_plugin_version($request) {
		# Get the plugin version from the constant
		$plugin_version = defined('METASYNC_VERSION') ? METASYNC_VERSION : 'unknown';
		
		# Get plugin name and other metadata
		$plugin_name = Metasync::get_effective_plugin_name();
		$plugin_slug = 'metasync';
		
		# Get plugin file path to retrieve additional metadata if needed
		$plugin_file = plugin_dir_path(dirname(__FILE__)) . 'metasync.php';
		$plugin_data = array();
		
		if (file_exists($plugin_file) && function_exists('get_plugin_data')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$plugin_data = get_plugin_data($plugin_file, false, false);
		}
		
		# Prepare response
		$response_data = array(
			'version' => $plugin_version,
			'plugin_name' => $plugin_name,
			'plugin_slug' => $plugin_slug,
			'wordpress_version' => get_bloginfo('version'),
			'php_version' => PHP_VERSION,
			'plugin_uri' => !empty($plugin_data['PluginURI']) ? $plugin_data['PluginURI'] : '',
			'author' => !empty($plugin_data['Author']) ? $plugin_data['Author'] : 'Search Atlas',
			'author_uri' => !empty($plugin_data['AuthorURI']) ? $plugin_data['AuthorURI'] : 'https://searchatlas.com',
		);
		
		return rest_ensure_response($response_data);
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
	
		return new WP_REST_Response($hierarchy, 200);
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
	
	public function get_errorlogs()
	{
		$get_data = sanitize_post($_GET);
		if (!isset($get_data['limit']))
			return false;
		$limit = sanitize_text_field($get_data['limit']) ?? null;

		require_once plugin_dir_path(__DIR__) . 'includes/class-metasync-errorlogs.php';

		$errorLogClass = new ErrorLog();
		$response = $errorLogClass->getParsedLogFile();

		if (!empty($response)) {
			// If no limit is specified, return all logs, otherwise, return the last $limit entries.
			$logsToReturn = ($limit === -1) ? $response : array_slice($response, -$limit);

			// Reverse the order of the logs
			$logsToReturn = array_reverse($logsToReturn);

			return rest_ensure_response($logsToReturn);
		}
	}




	private function get_post_author_id($post)
	{
		$post_author = isset($post['post_author']) ? sanitize_text_field($post['post_author']) : 1;
		wp_set_current_user($post_author);

		$current_user = 1;
		if (get_current_user_id()) {
			return wp_get_current_user()->ID;
		}

		return $current_user;
	}

	private function get_random_user_id_by_roles(?array $roles = [])
	{
		$users = get_users(array('role__in' => $roles, 'fields' => 'ids'));
		$post_author = 1;
		if (!empty($users)) {
			$key = array_rand($users);
			$post_author = $users[$key];
		}

		return $post_author;
	}

	private function htmlToElementorBlock($node) {
		$result = [];

		if ($node->nodeType === XML_TEXT_NODE) {
			// Text node
			return $node->nodeValue;
		} else{
			// Element node
			$result['id'] = uniqid(); // Generate unique ID for the element
			$result['elType'] = 'widget'; // Assume all elements are widgets					
			if (in_array(strtolower($node->nodeName), array('h1', 'h2', 'h3', 'h4', 'h5','h6'))) {
				// Handle heading elements			
				$result['settings']['title'] = $node->nodeValue;
				$result['settings']['header_size'] = $node->nodeName;

				# Check if the heading already has an ID
				$existing_id = $node->getAttribute('id');

				# If the ID exists, assign it as _element_id so Elementor renders it in HTML
				if (!empty($existing_id)) {
					$result['settings']['_element_id'] = $existing_id;
				}
				if(isset($this->metasync_option_data['enabled_elementor_plugin_css']) && isset($this->metasync_option_data['enabled_elementor_plugin_css_color']) && $this->metasync_option_data['enabled_elementor_plugin_css']!=="default"){
					$result['settings']['title_color'] = $this->metasync_option_data['enabled_elementor_plugin_css_color']; // Set default title color
				}
				$result['settings']['typography_typography'] = 'custom';
				$result['settings']['typography_font_family'] = 'Roboto';
				$result['settings']['typography_font_weight'] = '600';
				$result['widgetType'] = 'heading';
			}elseif($node->nodeName==='iframe'){  // Correction in the name 
				$result["settings"]= array('html'=> $node->ownerDocument->saveHTML($node));
				$result['widgetType'] = 'html';
			}elseif ($node->nodeName === 'img') {
				// Handle image elements source, title and alternative text
				$src_url = $node->getAttribute('src');
				$alt_text = $node->getAttribute('alt');
				$title_text = $node->getAttribute('title');
				// upload the image to wordpress and the id of the image and url
				$attachment_id = $this->common->upload_image_by_url($src_url,$alt_text,$title_text);
				$new_src_url = wp_get_attachment_url($attachment_id);
				// use the new image url to elementor
				$result['settings']['image']['url'] = $new_src_url;
				
				$result['settings']['image']['id'] =$attachment_id; // Generate unique ID for the image
				$result['settings']['image']['size'] = '';
				$result['settings']['image']['alt'] = $alt_text; // Set default alt text
				$result['settings']['image']['source'] = 'library';
				// check if the title is empty or not
				if($title_text !== ""){
					$result['settings']['image']['title'] = $title_text;
				}				
				$result['widgetType'] = 'image';
			} elseif ($node->nodeName === 'p') {
				// Handle paragraph elements
				$node->setAttribute('class', 'metasyncPara');
				$result["settings"]= array('editor'=> $node->ownerDocument->saveHTML($node));
				$result["elements"]= array(); 
				$result['widgetType'] = 'text-editor';
			} elseif($node->nodeName === 'table'|| $node->nodeName === 'ul' || $node->nodeName === 'ol') {
				if($node->nodeName === 'table'){
					$node->setAttribute('class', 'metasyncTable');
				}				
				$result["settings"]= array('editor'=> $node->ownerDocument->saveHTML($node));
				$result["elements"]= array();        
				$result['widgetType'] = 'text-editor';		
			}elseif ($node->nodeName === 'blockquote') {
				# Add a class 
				$node->setAttribute('class', 'metasyncBlockquote');
				# Set the HTML content inside the Elementor "text-editor" widget
				$html = $node->ownerDocument->saveHTML($node);
				# Insert blockquote into editor content
				$result["settings"] = array('editor' => $html); 
				# No child widgets or inner elements inside this block
				$result["elements"] = array();
				# Specify that this content should use the "text-editor" widget type
				$result['widgetType'] = 'text-editor';
			} 

			if(isset($result['widgetType'])){
				return $result;
			}
			
		}
	}
	private function elementorBlockData($content){
		$dom = new DOMDocument();
		// Load HTML string
		# @$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));	
		
		# Fix deprecated issue in PHP 8.2
		# Check if the php version is below 8.2.0
		if (version_compare(PHP_VERSION, '8.2.0', '<')) {
			# Use mb_convert_encoding for PHP < 8.2
			@$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
		} else {
			# Use mb_encode_numericentity for PHP >= 8.2
			$encoded_content = mb_encode_numericentity($content, [0x80, 0xFFFF, 0, 0xFFFF], 'UTF-8');
			@$dom->loadHTML($encoded_content);
		}	

		$outputArray = [];
		foreach ( $dom->getElementsByTagName('*') as $rootElement) {	
			if($rootElement->nodeName!=='html' && $rootElement->nodeName!=='body' &&
			$rootElement->nodeName!=='tbody'&& $rootElement->nodeName!=='tfoot' && $rootElement->nodeName!=='tr' && $rootElement->nodeName!=='th' && $rootElement->nodeName!=='td'){
			$htmlArray = $this->htmlToElementorBlock($rootElement);
			# $outputArray[] = $htmlArray;
			# Only add non-null values to the output array
			# non-null changed to not-empty
            if (!empty($htmlArray)) {
                $outputArray[] = $htmlArray;
            }
			}		
		}
		return $outputArray;
	}

	private function gutenbergBlockData($content) {
		// Validate and sanitize content before parsing
		if (empty($content) || !is_string($content)) {
			return [];
		}

		// Trim and ensure content is valid
		$content = trim($content);
		if (empty($content)) {
			return [];
		}

		// Clean content: remove any leading/trailing whitespace and ensure it starts with a valid HTML tag
		// If content starts with an entity or invalid character, wrap it
		$content = preg_replace('/^[\s\x{200B}-\x{200D}\x{FEFF}]+/u', '', $content);
		$content = preg_replace('/[\s\x{200B}-\x{200D}\x{FEFF}]+$/u', '', $content);

		// Use modern Dom\HTMLDocument for PHP 8.4+ which natively supports HTML5
		// Otherwise fall back to DOMDocument with error suppression
		$dom = null;
		if (class_exists('Dom\HTMLDocument')) {
			// PHP 8.4+ with native HTML5 support
			// Wrap content in HTML structure if it's not already a complete document
			$wrapped_content = trim($content);
			$isCompleteDocument = (stripos($wrapped_content, '<!DOCTYPE') === 0) || (stripos($wrapped_content, '<html') === 0);
			
			if (!$isCompleteDocument) {
				// Ensure content is properly formatted before wrapping
				$wrapped_content = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $wrapped_content . '</body></html>';
			}
			
			try {
				$dom = @Dom\HTMLDocument::createFromString($wrapped_content);
				if ($dom === null) {
					throw new Exception('Dom\HTMLDocument::createFromString returned null');
				}
			} catch (Throwable $e) {
				// Fallback to DOMDocument if Dom\HTMLDocument fails
				if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
					error_log('MetaSync: Dom\HTMLDocument error, falling back to DOMDocument: ' . $e->getMessage());
				}
				$dom = new DOMDocument();
				libxml_use_internal_errors(true);
				$encoded_content = mb_encode_numericentity($content, [0x80, 0xFFFF, 0, 0xFFFF], 'UTF-8');
				@$dom->loadHTML($encoded_content);
				libxml_clear_errors();
				libxml_use_internal_errors(false);
			}
		} else {
			// Fallback for older PHP versions
			$dom = new DOMDocument();

			// Suppress libxml errors for HTML5 tags that aren't recognized in older libxml
			libxml_use_internal_errors(true);

			// Ensure content is wrapped in a proper HTML structure
			$htmlContent = $content;
			if (!preg_match('/^\s*<(!DOCTYPE|html|body)/i', $content)) {
				$htmlContent = '<!DOCTYPE html><html><body>' . $content . '</body></html>';
			}

			if (version_compare(PHP_VERSION, '8.2.0', '<')) {
				// Use mb_convert_encoding for PHP < 8.2
				$dom->loadHTML(mb_convert_encoding($htmlContent, 'HTML-ENTITIES', 'UTF-8'));
			} else {
				// Use mb_encode_numericentity for PHP >= 8.2
				$encoded_content = mb_encode_numericentity($htmlContent, [0x80, 0xFFFF, 0, 0xFFFF], 'UTF-8');
				$dom->loadHTML($encoded_content);
			}

			// Clear any libxml errors and restore error handling
			libxml_clear_errors();
			libxml_use_internal_errors(false);
		}

		$outputArray = [];
	
		// Iterate through each element in the HTML
		foreach ($dom->getElementsByTagName('*') as $rootElement) {
			// If the element is not one of the specified HTML tags, convert it to a Gutenberg block
			if (!in_array($rootElement->nodeName, ['html', 'body', 'tr', 'th', 'td'])) {
				$htmlArray = $this->htmlToGutenbergBlock($rootElement);
				if(!is_null($htmlArray)){
					$outputArray[] = $htmlArray;
				}				
			}
		}
	
		return $outputArray;
	}
	
	private function htmlToGutenbergBlock($node) {
		$nodeName = strtolower($node->nodeName);
	
		$result = [];

		if ($node->nodeType === XML_TEXT_NODE) {
			// Text node
			return $node->nodeValue;
		} else{
			if (in_array($nodeName, array('h1', 'h2', 'h3', 'h4', 'h5','h6'))) {
				$level = intval(substr($nodeName, 1));
				return [
					"blockName" => "core/heading",
					"attrs" => [
						"level" =>  $level
					],
					"innerBlocks" => [],
					"innerHTML" => $node->ownerDocument->saveHTML($node),
					"innerContent" => [
						$node->ownerDocument->saveHTML($node)
					]
				];
			} elseif ($nodeName === 'img') {			
				$src_url = $node->getAttribute('src');
				// get alt text from the image tag
				$alt_text = $node->getAttribute('alt');
				//get title text from the image tag
				$title_text = $node->getAttribute('title');
				// upload the image to wordpress and the id of the image and url
				$attachment_id = $this->common->upload_image_by_url($src_url,$alt_text,$title_text);
				// get new source url after upload
				$new_src_url = wp_get_attachment_url($attachment_id);

				
				$alt_attr = $node->getAttribute('alt');
				$node->setAttribute('alt', $alt_attr !== null ? $alt_attr : '');
				$node->setAttribute('src', $src_url);
				$node->setAttribute('class', "wp-image-".$attachment_id);
				//format the inner content for the image tag
				return [
					"blockName" => "core/image",
					"attrs" => [
						"id" => $attachment_id ,
						"sizeSlug" => "large",
						"linkDestination" => "none"
					],
					"innerBlocks" => [],
					"innerHTML" => '' ,
					"innerContent" => [
						sprintf('<figure class="wp-block-image size-large"><img src="%s" alt="%s" class="wp-image-%d" /></figure>', 
						esc_url($new_src_url), 
						esc_attr($node->getAttribute('alt')), 
						$attachment_id
            		),					
					]
				];
			}elseif ($nodeName === 'iframe') {
				return [
					"blockName" => "core/html",
					"attrs" => [],
					"innerBlocks" => [],
					"innerHTML" => $node->ownerDocument->saveHTML($node) ,
					"innerContent" => [
						$node->ownerDocument->saveHTML($node) 
					]
				];
			}elseif ($nodeName === 'p') {
				return [
					"blockName" => "core/paragraph",
					"attrs" => [],
					"innerBlocks" => [],
					"innerHTML" => $node->ownerDocument->saveHTML($node) ,
					"innerContent" => [
						$node->ownerDocument->saveHTML($node) 
					]
				];
			} elseif ($nodeName === 'table') {
				$tableHtml = $node->ownerDocument->saveHTML($node);
				if($nodeName === 'table'){
					$node->setAttribute('class', 'metasyncTable-block');
				}	
				return [
					"blockName" => "core/table",
					"attrs" => [],
					"innerBlocks" => [],
					"innerHTML" =>'<figure class="wp-block-table meta-block-tabel">'.$tableHtml.'</figure>',
					"innerContent" => [
						'<figure class="wp-block-table meta-block-tabel">'.$tableHtml.'</figure>'
					]
				];
					
			}elseif($nodeName === 'ol'||$nodeName === 'ul'){
				//list-item
				return [
					"blockName" => "core/list",
					"attrs" => [
						'ordered'=> ($nodeName === 'ol'?true:false)
					],
					"innerBlocks" => [],
					"innerHTML" => $node->ownerDocument->saveHTML($node) ,
					"innerContent" => [
						$node->ownerDocument->saveHTML($node) 
					]
				];
			}elseif ($nodeName === 'blockquote') {
				# Add the standard Gutenberg quote class to ensure proper styling
				$node->setAttribute('class', 'wp-block-quote');
				# Convert the full blockquote HTML
				$quote_html = $node->ownerDocument->saveHTML($node);
				# Return a properly structured Gutenberg "core/quote" block
				return [
					"blockName" => "core/quote", 
					"attrs" => [],
					"innerBlocks" => [],
					"innerHTML" => $quote_html,
					"innerContent" => [
						$quote_html
					]
				];
			}
		}
		
	}

	
	private function htmlToDiviBlock($node) {
		$result = [];

		if ($node->nodeType === XML_TEXT_NODE) {
			// Text node
			return $node->nodeValue;
		} else{
			// Element node
			$result['id'] = uniqid(); // Generate unique ID for the element
			$result['elType'] = 'widget'; // Assume all elements are widgets					
			if (in_array(strtolower($node->nodeName), array('h1', 'h2', 'h3', 'h4', 'h5','h6'))) {

				# Fetch the existing ID from the heading element (if any)
				$existing_id = $node->getAttribute('id');

				# If ID exists, prepare it as a valid Divi module attribute; otherwise, leave it out
				$extra_id_attr = !empty($existing_id) ? ' module_id="' . $existing_id . '"' : '';

				# Handle heading elements embedding the ID only when it's available
				$result =' [et_pb_heading title="'.$node->nodeValue.'" _builder_version="'.ET_BUILDER_VERSION.'" _module_preset="default" title_level="'.$node->nodeName.'" hover_enabled="0" sticky_enabled="0"'. $extra_id_attr .'][/et_pb_heading]';
			} elseif ($node->nodeName === 'img') {
				// Handle image elements			
				try{
					$image_id = attachment_url_to_postid($node->getAttribute('src') );
					$image_alt = get_post_meta($image_id, '_wp_attachment_image_alt', TRUE);
					$result ='[et_pb_image src="'.$node->getAttribute('src') .'" url="'.$node->getAttribute('src'). '" _builder_version="'.ET_BUILDER_VERSION.'" _module_preset="default" hover_enabled="0" global_colors_info="{}" sticky_enabled="0"][/et_pb_image]';
				
				}catch(Error $e){
					$image_id = attachment_url_to_postid($node->getAttribute('src') );
					$image_alt = get_post_meta($image_id, '_wp_attachment_image_alt', TRUE);
					$result ='[et_pb_image src="'.$node->getAttribute('src') .'" url="'.$node->getAttribute('src'). '" _builder_version="'.ET_BUILDER_VERSION.'" _module_preset="default" hover_enabled="0" global_colors_info="{}" sticky_enabled="0"][/et_pb_image]';

					error_log(json_encode($e));
				
				}
			}elseif($node->nodeName === 'iframe'){
				$result= '[et_pb_code _builder_version="'.ET_BUILDER_VERSION.'" _module_preset="default" global_colors_info="{}"]'.$node->ownerDocument->saveHTML($node).'[/et_pb_code]' ;	
			}elseif ($node->nodeName === 'p') {
				// Handle paragraph elements
				$node->setAttribute('class', 'metasyncPara');
				$result = '[et_pb_text _builder_version="'.ET_BUILDER_VERSION.'" _module_preset="default" global_colors_info="{}"]'. $node->ownerDocument->saveHTML($node).'[/et_pb_text]';
			} elseif ($node->nodeName === 'table'||$node->nodeName === 'ul'  || $node->nodeName === 'ol') {
				if($node->nodeName === 'table'){
					$node->setAttribute('class', 'metasyncTable');
				}
				$result= '[et_pb_code _builder_version="'.ET_BUILDER_VERSION.'" _module_preset="default" global_colors_info="{}"]'.$node->ownerDocument->saveHTML($node).'[/et_pb_code]' ;	
			}elseif ($node->nodeName === 'blockquote') {
                # Add class 
                $node->setAttribute('class', 'metasyncQuote');
                # Convert the <blockquote> node and its contents (including tags like <em>) to HTML
                $quote_html = $node->ownerDocument->saveHTML($node);
				# Wrap the blockquote content inside a Divi Text Module since Divi has no native quote module
                $result = '[et_pb_text _builder_version="'.ET_BUILDER_VERSION.'" _module_preset="default" global_colors_info="{}"]'.$quote_html.'[/et_pb_text]';
            }				
			return $result;		
		}
	}
	private function diviBlockData($content){
		$dom = new DOMDocument();
		// Load HTML string
		# @$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));	
		# Fix deprecated issue in PHP 8.2
		# Check if the php version is below 8.2.0
		if (version_compare(PHP_VERSION, '8.2.0', '<')) {
			# Use mb_convert_encoding for PHP < 8.2
			@$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
		} else {
			# Use mb_encode_numericentity for PHP >= 8.2
			$encoded_content = mb_encode_numericentity($content, [0x80, 0xFFFF, 0, 0xFFFF], 'UTF-8');
			@$dom->loadHTML($encoded_content);
		}

		$outputArray = '[et_pb_section fb_built="1" _builder_version="'.ET_BUILDER_VERSION.'" _module_preset="default" global_colors_info="{}"][et_pb_row _builder_version="'.ET_BUILDER_VERSION.'" _module_preset="default" global_colors_info="{}"][et_pb_column type="4_4" _builder_version="'.ET_BUILDER_VERSION.'" _module_preset="default" global_colors_info="{}"]';
		foreach ( $dom->getElementsByTagName('*') as $rootElement) {	
			if($rootElement->nodeName!=='html' && $rootElement->nodeName!=='body' &&
			$rootElement->nodeName!=='tbody'&& $rootElement->nodeName!=='tfoot' && $rootElement->nodeName!=='tr' && $rootElement->nodeName!=='th' && $rootElement->nodeName!=='td'){
			$htmlArray = $this->htmlToDiviBlock($rootElement);
			if(gettype($htmlArray)!=='array'){
				$outputArray .= $htmlArray;
			}
			}		
		}
		$outputArray .='[/et_pb_column][/et_pb_row][/et_pb_section]';
		return $outputArray;
	}
	/*
		Added $landing_page_option variable and set default value false to check 
		if metasync_upload_post_content is called for set_landing_page by following function that are below
		# create_item
		# update_items
		Added $otto_enable variable and set default value false to check 
		if metasync_upload_post_content is called otto AI landing page by following function that are below
		# create_page
		# update_page
	*/
	/**
	 * Upload post content and convert to builder format
	 *
	 * This method now delegates to the new HTML to Builder Converter class
	 * for improved maintainability and CSS preservation.
	 *
	 * @param array $item Item data with 'post_content' key
	 * @param bool $landing_page_option Whether this is for a landing page
	 * @param bool $otto_enable Whether this is for Otto AI
	 * @return array Result with 'content' and optional builder meta data
	 */
	public function metasync_upload_post_content($item,$landing_page_option=false,$otto_enable=false)
	{
		// Load the new converter class
		require_once plugin_dir_path(dirname(__FILE__)) . 'custom-pages/class-metasync-html-to-builder-converter.php';
		$converter = new Metasync_HTML_To_Builder_Converter();

		// Delegate to converter's legacy method for backward compatibility
		return $converter->convert_legacy($item, $landing_page_option, $otto_enable);
	}

	public function metasync_handle_post_category($post_id, $post_categories, $append)
	{
		$post_categories = array_map('sanitize_text_field', $post_categories);
		$post_categories = wp_create_categories($post_categories, $post_id);
		wp_set_post_categories($post_id, $post_categories, $append);

		$categories = get_the_category($post_id);
		$fine_categories = array();
		foreach ($categories as $category) {
			$fine_categories[] = [
				"id" => $category->cat_ID,
				"name" => $category->name
			];
		}
		return $fine_categories;
	}

	public function metasync_set_post_tags($post_id, $post_tags, $append_tags)
	{
		$post_tags = array_map('sanitize_text_field', $post_tags);
		wp_set_post_tags($post_id, $post_tags, $append_tags);

		$tags = wp_get_post_tags(
			$post_id,
			array(
				'orderby' => 'name'
			)
		);

		$parse_tags = array();
		foreach ($tags as $tag) {
			$parse_tags[] = [
				"id" => $tag->term_id,
				"name" => $tag->name
			];
		}
		return $parse_tags;
	}

	public function metasync_handle_hero_image($post_id, $hero_image_url, $hero_image_alt_text)
	{
		$attachment_id = '';
		$hero_image_url = sanitize_url($hero_image_url);
		if (filter_var($hero_image_url, FILTER_VALIDATE_URL)) {
			$attachment_id = $this->common->upload_image_by_url($hero_image_url);
			if ($attachment_id) {
				set_post_thumbnail($post_id, $attachment_id);
			}
		}
		if (has_post_thumbnail($post_id) && isset($hero_image_alt_text) && !empty($hero_image_alt_text)) {
			$hero_image_id = get_post_thumbnail_id($post_id);
			update_post_meta($hero_image_id, '_wp_attachment_image_alt', $hero_image_alt_text);
		}
		return $attachment_id;
	}

	/**
	 * Index a post with Google Indexing API
	 * 
	 * @param int $post_id WordPress post ID
	 * @param string $post_type WordPress post type (post, page, etc.)
	 * @param string $post_status WordPress post status (publish, draft, etc.)
	 */
	public function metasync_google_index_post($post_id, $post_type, $post_status)
	{
		// Only index published posts/pages
		if ($post_status !== 'publish') {
			return;
		}

		// Only index posts and pages (can be extended as needed)
		$allowed_post_types = array('post', 'page');
		if (!in_array($post_type, $allowed_post_types)) {
			return;
		}

		try {
			// Load Google Index functionality if not already loaded
			if (!function_exists('google_index_post')) {
				$google_index_path = plugin_dir_path(dirname(__FILE__)) . 'google-index/google-index-init.php';
				if (file_exists($google_index_path)) {
					require_once $google_index_path;
				} else {
					error_log('MetaSync Google Index: google-index-init.php not found at ' . $google_index_path);
					return;
				}
			}

			// Attempt to index the post with Google
			if (function_exists('google_index_post')) {
				$result = google_index_post($post_id, $post_type, 'update');
				
				if (isset($result['success']) && $result['success']) {
					error_log(sprintf(
						'MetaSync Google Index: Successfully indexed %s (ID: %d, Type: %s)',
						get_the_title($post_id),
						$post_id,
						$post_type
					));
				} else {
					error_log(sprintf(
						'MetaSync Google Index: Failed to index %s (ID: %d, Type: %s) - %s',
						get_the_title($post_id),
						$post_id,
						$post_type,
						isset($result['error']['message']) ? $result['error']['message'] : 'Unknown error'
					));
				}
			}
		} catch (Exception $e) {
			// Log any exceptions but don't break the main functionality
			error_log('MetaSync Google Index Exception: ' . $e->getMessage());
		}
	}

	public function create_item($request)
	{
		// Checking for type of object for response type
		$array_response = true;
		if (gettype($request) == "object")
			$array_response = false;

		// Getting JSON Params
		$request_data = array($request);
		if ($array_response == false)
			$request_data = $request->get_json_params();

		// Looping for payload for posts
		$respCreatePosts = array();
		foreach ($request_data as $index => $item) {
			$post_author = isset($item['post_author']) ? sanitize_text_field($item['post_author']) : '1';
			wp_set_current_user($post_author);
			$current_user = wp_get_current_user();
			$current_user_id = '1';
			if ($current_user->ID > 0) {
				$current_user_id = $current_user->ID;
			}

			$users = get_users(array('role__in' => array('author'), 'fields' => 'ids'));
			$post_author = $current_user_id;
			if (!empty($users)) {
				$key = array_rand($users);
				$post_author = $users[$key];
			}
			/* 
			check if the create_item is called by set_landing_page function or not 
			by doing this we will prevent html from going into builder page option
			*/
			if(!isset($item['is_landing_page']) && empty($item['otto_ai_page']) && empty($item['style_data']) ){

				# Get Current Post type
				$current_post_type = isset($item['post_type']) ? sanitize_text_field($item['post_type']) : 'post';
				/**
				 * Check if current theme is Flatsome using SAVED theme info (not wp_get_theme())
				 * Theme info is saved by admin hooks, so no security triggers during REST API
				 */
				$metasync_general = Metasync::get_option('general');
				$theme_name = $metasync_general['current_theme_name'] ?? '';
				$theme_template = $metasync_general['current_theme_template'] ?? '';
				
				$is_flatsome_theme = false;
				if (!empty($theme_name) && stripos($theme_name, 'Flatsome') !== false) {
					$is_flatsome_theme = true;
				} elseif (!empty($theme_template) && stripos($theme_template, 'flatsome') !== false) {
					$is_flatsome_theme = true;
				}

				# Skip title/image prepending for Flatsome (it displays them by default)
				if (!$is_flatsome_theme) {
					# Get the setting for the post template
					$title_and_feature_image = $this->append_content_if_missing_elements($current_post_type);

					# Check if the post title is there in the template or not
					if(!$title_and_feature_image['image_in_content'] && !empty($item['hero_image_url'])){
						
						# Prepend the feature image
						$item['post_content'] = '<img src="'.$item['hero_image_url'].'" />'.$item['post_content'] ;
					}

					# Check if the post title is there in the template or not
					if(!$title_and_feature_image['title_in_headings']){

						# Prepend the post title
						$item['post_content'] = '<h1>'.$item['post_title'].'</h1>'.$item['post_content'] ;
					}
					
				}	
				#  This will be used by create_page function
				$content = $this->metasync_upload_post_content($item,false,false); 
            }elseif(isset($item['is_landing_page']) && $item['is_landing_page'] == true){
				$content = $this->metasync_upload_post_content($item,true); // This will be used by set_landing_page function
			}
			/*
			Check if the otto_ai_page is payload is set in the api or not.
			If it is please set the third parameter to true.
			*/
			if(isset($item['otto_ai_page']) && $item['otto_ai_page']==true && !empty($item['style_data'])){
				$content = $this->metasync_upload_post_content($item,true,true);
			}

			$new_post = array(
				'post_author' => $post_author,
				'post_title' => sanitize_text_field($item['post_title']),
				'post_content' => $content['content'] ?  $content['content'] : $item['post_content'],
				'post_excerpt' => isset($item['meta_description']) ? sanitize_text_field($item['meta_description']) : '',
				'post_type' => isset($item['post_type']) ? sanitize_text_field($item['post_type']) : 'post',
				'post_status' => isset($item['post_status']) ? sanitize_text_field($item['post_status']) : 'publish',
				'comment_status' => isset($item['comment_status']) ? sanitize_text_field($item['comment_status']) : 'open',
				'post_parent' =>  isset($item['post_parent']) ?$item['post_parent'] : 0
			);

			if (isset($item['post_author']) && !empty($item['post_author'])) {
				$new_post['post_author'] = sanitize_text_field($item['post_author']);
			}

			// adding custom permalink
			if (isset($item['permalink']) && !empty($item['permalink'])) {
				$new_post['post_name'] = sanitize_text_field($item['permalink']);
			}

			if (isset($item['post_date']) && !empty($item['post_date'])) {
				$is_valid_date = date('Y-m-d', strtotime($item['post_date'])) === $item['post_date'];
				if (!$is_valid_date) {
					return new WP_Error(
						'rest_post_invalid_date',
						esc_html__('Post date is not valid'),
						array('status' => 400)
					);
				}

				// $date_limit_str = strtotime(date('Y-m-d') . '-2 month');
				// $post_date_str = strtotime($item['post_date']);
				// if ($date_limit_str >= $post_date_str) {
				// 	$newDate = date('Y-m-d', strtotime('-2 month'));
				// 	return new WP_Error(
				// 		'rest_post_greater_date',
				// 		esc_html__("Post date should be greater then " . $newDate),
				// 		array('status' => 400)
				// 	);
				// }

				// if ($post_date_str > strtotime(date('Y-m-d'))) {
				// 	return new WP_Error(
				// 		'rest_post_less_date',
				// 		esc_html__("Post date should be less then Today"),
				// 		array('status' => 400)
				// 	);
				// }

				// $new_post['post_date'] = sanitize_text_field($item['post_date'] . date(' h:i:s'));
			}

			// Adding condition to check if the post is already exist
			$post_status_new = isset($item['post_status']) ? sanitize_text_field($item['post_status']) : 'publish';
			$post_permalink = $item['permalink'] = isset($item['permalink']) ? $item['permalink'] : sanitize_title($new_post['post_title']);

			# $getPostID_byURL = @get_page_by_path($item['permalink'], OBJECT, $new_post['post_type'])->ID;
			# Fix to avoid PHP error if the get_page_by_path returns null
			$getPostID_byURL = @get_page_by_path($item['permalink'], OBJECT, $new_post['post_type']);
			$getPostID_byURL = $getPostID_byURL ? $getPostID_byURL->ID : null;
			if ($getPostID_byURL == NULL) {
				// check if the post_title is set and not empty when called by otto_ai_page
				if(isset($new_post['post_title']) && $new_post['post_title']!==''){
				$getPostID_byURL = new WP_Query(
					array(
						'post_type' => $new_post['post_type'],
						'title' => $new_post['post_title']
					)
				);
				$getPostID_byURL = $getPostID_byURL->posts[0]->ID ?? null;
			}
			}

			// Allow HTML code for landing page
			if (isset($item['is_landing_page']) && $item['is_landing_page'] == true) {
				kses_remove_filters();
			}

			if (isset($item['post_parent']) && !empty($item['post_parent']) && $item['post_parent'] != 0) {
				if ($new_post['post_type'] == 'page') {					
					$new_post['post_parent'] = isset($item['post_parent']) ? $item['post_parent'] : 0;
				} else {
					$item['post_parent'] = isset($item['post_parent']) ? $item['post_parent'] : 0;
				}
			}

			if ($getPostID_byURL === NULL) {
				$post_id = wp_insert_post($new_post);
				$permalink = get_permalink($post_id);
				
				# If the post was successfully created (no WP error)
				if (!is_wp_error($post_id)) {

					# Add a custom meta field
					update_post_meta($post_id, 'metasync_post', 'yes');
				}

			} else {
				$new_post['ID'] = $post_id = $getPostID_byURL;
				wp_update_post($new_post);
				unset($new_post['ID']);
				$permalink = get_permalink($post_id);
			}

			if (isset($item['is_landing_page']) && $item['is_landing_page'] == true) {
				kses_remove_filters();
			}
			
			$post_meta = array();
			if(isset($content['elementor_meta_data'])){
				$post_meta = array_merge($post_meta,$content['elementor_meta_data']);
			}else if(isset($content['divi_meta_data'])){
				$post_meta = array_merge($post_meta,$content['divi_meta_data']);
				$post_meta['_et_pb_ab_current_shortcode']='[et_pb_split_track id="'.$post_id.'" /]';
				$post_meta['_et_pb_use_builder']='on';
				$post_meta['_et_pb_built_for_post_type']=isset($item['post_type']) ? sanitize_text_field($item['post_type']) : 'post';
			}
			
			if (isset($item['meta_description']) && !empty($item['meta_description'])) {
				$post_meta['meta_description'] = sanitize_text_field($item['meta_description']);
			}
			if (isset($item['meta_robots']) && !empty($item['meta_robots'])) {
				$post_meta['meta_robots'] = sanitize_text_field($item['meta_robots']);
			}

			// Add custom field for post header section
			if (isset($item['custom_post_header'])) { //  && !empty($item['custom_post_header'])
				$post_meta['custom_post_header'] = $item['custom_post_header'];
			}
			// Add custom field for post footer section
			if (isset($item['custom_post_footer'])) { //  && !empty($item['custom_post_footer'])
				$post_meta['custom_post_footer'] = $item['custom_post_footer'];
			}

			// Add custom field for searchatlas top
			if (isset($item['searchatlas_embed_top'])) { //  && !empty($item['searchatlas_embed_top'])
				$post_meta['searchatlas_embed_top'] = $item['searchatlas_embed_top'];
			}
			// Add custom field for searchatlas bottom
			if (isset($item['searchatlas_embed_bottom'])) { //  && !empty($item['searchatlas_embed_bottom'])
				$post_meta['searchatlas_embed_bottom'] = $item['searchatlas_embed_bottom'];
			}

			// Add custom fields to posts and pages
			foreach ($post_meta as $key => $value) {
				// if (!empty($value) && !is_null($value)) {
					add_post_meta($post_id, $key, $value, true);
				// }
			}
			

			$attachment_id = '';
			if (isset($item['hero_image_url']) && !empty($item['hero_image_url'])) {
				$attachment_id = $this->metasync_handle_hero_image($post_id, $item['hero_image_url'], $item['hero_image_alt_text']);
			}

			$redirection = array();
			if (isset($item['redirection_enable']) && !empty($item['redirection_enable'])) {
				$redirection['enable'] = sanitize_text_field($item['redirection_enable']);
			}
			if (isset($item['redirection_type']) && !empty($item['redirection_type'])) {
				$redirection['type'] = sanitize_text_field($item['redirection_type']);
			}
			if (isset($item['redirection_url']) && !empty($item['redirection_url'])) {
				$redirection['url'] = sanitize_url($item['redirection_url']);
			}
			if (!empty($redirection)) {
				update_post_meta($post_id, 'metasync_post_redirection_meta', $redirection);
			}

			$post_cattegories = [];
			# if ($new_post['post_type'] === 'post' && is_array(@$item['post_categories'])) {

			# fixed Undefined array key issue
			if ($new_post['post_type'] === 'post' && isset($item['post_categories']) && is_array($item['post_categories'])) {
				$append_categories = isset($item['append_categories']) && $item['append_categories'] == true ? true : false;
				$post_cattegories = $this->metasync_handle_post_category($post_id, $item['post_categories'], $append_categories);
			}
			if (isset($content['elementor_meta_data']) && did_action( 'elementor/loaded' )) {
				// Clear Elementor cache for the specified post ID
				\Elementor\Plugin::instance()->files_manager->clear_cache();

			}

			$post_tags = [];
			# if ($new_post['post_type'] === 'post' && is_array(@$item['post_tags'])) {

			# fixed Undefined array key 'post_tags issue
			if ($new_post['post_type'] === 'post' && isset($item['post_tags']) && is_array($item['post_tags'])) {
				$append_tags = isset($item['append_tags']) && $item['append_tags'] == true ? true : false;
				$post_tags = $this->metasync_set_post_tags($post_id, $item['post_tags'], $append_tags);
			}

			$new_post['post_categories'] = $post_cattegories;
			$new_post['post_tags'] = $post_tags;
			unset($new_post['post_name']);
			$new_post['post_id'] = $post_id;
			$new_post['permalink'] = $permalink;
			$new_post['hero_image_url'] = wp_get_attachment_url($attachment_id);
			$new_post['hero_image_alt_text'] = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

			# Log sync history for Content Genius post creation/update
			if (!is_wp_error($post_id) && $post_id > 0) {
				$action = ($getPostID_byURL === NULL) ? 'Created' : 'Updated';
				$title_preview = mb_strlen($new_post['post_title']) > 30 ? mb_substr($new_post['post_title'], 0, 30) . '...' : $new_post['post_title'];

				# Use appropriate title based on post type
				$content_type_label = ($new_post['post_type'] === 'page') ? 'Page' : 'Post';
				$sync_status = ($new_post['post_status'] === 'publish') ? 'published' : $new_post['post_status'];

				metasync_log_sync_history([
					'title' => "{$content_type_label} {$action} ({$title_preview})",
					'source' => 'Content Genius',
					'status' => $sync_status,
					'content_type' => ucfirst($new_post['post_type']),
					'url' => $permalink,
					'meta_data' => json_encode([
						'post_id' => $post_id,
						'post_title' => $new_post['post_title'],
						'post_type' => $new_post['post_type'],
						'post_status' => $new_post['post_status'],
						'action' => strtolower($action)
					])
				]);

				# Track Content Genius event in Mixpanel
				try {
					$mixpanel = Metasync_Mixpanel::get_instance();
					$mixpanel->track_content_genius_event($post_id, strtolower($action));
				} catch (Exception $e) {
					error_log('MetaSync: Mixpanel tracking failed for Content Genius - ' . $e->getMessage());
				}

			// Google Indexing Integration
			$this->metasync_google_index_post($post_id, $new_post['post_type'], $new_post['post_status']);
			}

			$respCreatePosts[$index] = array_merge($new_post, $post_meta);
			ksort($respCreatePosts[$index]);
		}

		if ($array_response == false)
			return rest_ensure_response($respCreatePosts);
		return $respCreatePosts;
	}

	public function set_landing_page($request)
	{
		$payload = $request->get_json_params()[0];
		$payload['permalink'] = "metasync-landing-page"; // hardcoding to avoid duplicates
		$payload['post_type'] = "page";
		$payload['post_status'] = "publish";
		$payload['is_landing_page'] = true;
		$createPages = $this->create_item($payload); // creating landing page

		$post_id = $createPages[0]['post_id'];
		update_option('page_on_front', $post_id);
		update_option('show_on_front', 'page');	

		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-template.php';
		update_post_meta($post_id, '_wp_page_template', Metasync_Template::TEMPLATE_NAME);
		return rest_ensure_response($createPages);
	}

	public function delete_item()
	{
		$get_data = sanitize_post($_GET);
		if (!isset($get_data['ID']))
			return false;

		$post_id = sanitize_text_field($get_data['ID']) ?? null;
		$post = get_post($post_id);
		if ($post) {
			wp_delete_post($post_id);
			return new WP_Error(
				'rest_post_delete_success',
				esc_html__(''),
				// HTTP 204 requires no body for response
				array('status' => 204)
			);
		}
		return new WP_Error(
			'rest_post_delete_fail',
			esc_html__('No post found in the database with requested ID.'),
			array('status' => 400)
		);
	}

	public function get_items($request)
	{
		$get_data = sanitize_post($_GET);

		# let us check if request send post id and is valid int
		if(isset($get_data['post_id']) AND intval($get_data['post_id']) > 0){

			#get the post id
			$post_id = $get_data['post_id'];

			#get the elementor items of the post
			$elementor_items = $this->elementor_getItems($post_id);
			
			#return the elementor items in response
			return rest_ensure_response($elementor_items);
		}

		return rest_ensure_response(
			array(
				'posts' => $this->filter_post_attributes(
					get_posts(
						array(
							'numberposts' => -1
						)
					)
				),
				'pages' => $this->filter_post_attributes(
					get_pages(
						array(
							'numberposts' => -1
						)
					)
				)
			)
		);
	}

	private function elementor_getItems($post_id)
	{
		$data_array = array();
		$elementorData = get_post_meta($post_id, '_elementor_data', true);
		if (!empty($elementorData)) {
			$elementorData = json_decode($elementorData);
			$this->elementor_getElement($elementorData, $data_array);
		}
		// echo $this->elementor_convertToXML($data_array);
		return $this->elementor_convertToDraftJS($data_array);
	}

	private function elementor_getElement($elements, &$data_array)
	{
		$elements_allowedWidgetTypes = ['heading', 'text-editor', 'image'];
		$elements_groupItems = ['section', 'column'];
		foreach ($elements as $element) {
			if (in_array($element->elType, $elements_groupItems)) {
				$this->elementor_getElement($element->elements, $data_array);
				continue;
			}

			#check that we process only widgets
			if($element->elType !== 'widget'){

				#go to next
				continue;
			}

			switch ($element->widgetType) {
				case 'heading':
					$data_array[$element->id] = ['value' => trim($element->settings->title), 'type' => 'heading'];
					break;
				case 'image':
					$data_array[$element->id] = ['value' => $element->settings->image->url, 'type' => 'url'];
					break;
				case 'text-editor':
					$data_array[$element->id] = ['value' => trim($element->settings->editor), 'type' => 'text-editor'];
					break;

				default:
			}
		}
	}	

	private function elementor_convertToDraftJS($data_array)
	{
		$response = array(
			"blocks" => [],
		);

		foreach ($data_array as $id => $item) {
			// array_push($response['blocks'], 
				// array(
				// 	"key" => "$id",
				// "text" => $item['value'],
				// 	"type" => "unstyled",
				// 	"depth" => 0,
				// 	"inlineStyleRanges" => [],
				// 	"entityRanges" => [],
				// 	"data" => []
				// )
			// );
			$this->convertFromHTMLToContentBlocks($id, $item['value'], $response['blocks']);
		}
		return $response;
	}

	private function convertFromHTMLToContentBlocks($key, $html, &$contentBlocks)
	{
		$dom = new DOMDocument();
		libxml_use_internal_errors(true); // Disable error reporting for HTML5 tags
		$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_use_internal_errors(false); // Enable error reporting again

		$blockLevel = [];
		// Iterate through each node in the body
		foreach ($dom->getElementsByTagName('*')->item(0)->childNodes as $node) {
			// Process each node and convert it to a content block
			$block = $this->convertNodeToContentBlock($key, $node, $blockLevel);
			if ($block) {
				$contentBlocks[] = $block;
			}
		}
		// return $contentBlocks;
	}

	private function convertNodeToContentBlock($id, $node, &$blockLevel)
	{
		if($blockLevel[$id] == null) {
			$blockLevel[$id] = 0;
		}
		$blockLevel[$id]++;
		$id = $id . '-' . $blockLevel[$id];
		

		// Check node type
		switch ($node->nodeType) {
			case XML_TEXT_NODE:
				// Text node
				$text = trim($node->nodeValue);
				if ($text !== '') {
					return [
						'key' => $id,
						'type' => 'unstyled',
						'text' => $text,
						'depth' => 0,
						'inlineStyleRanges' => [],
						'entityRanges' => [],
						'data' => [],
					];
				}
				break;

			case XML_ELEMENT_NODE:
				// Element node
				$tagName = strtolower($node->tagName);

				// Map HTML tags to Draft.js block types
				$blockTypeMap = [
					'p' => 'unstyled',
					'h1' => 'header-one',
					'h2' => 'header-two',
					'h3' => 'header-three',
					// Add more block types as needed
				];

				$blockType = isset($blockTypeMap[$tagName]) ? $blockTypeMap[$tagName] : $tagName; //'unstyled';

				$block = [
					'key' => $id,
					'type' => $blockType,
					'text' => '',
					'depth' => 0,
					'inlineStyleRanges' => [],
					'entityRanges' => [],
					'data' => [],
				];

				// Process child nodes recursively
				foreach ($node->childNodes as $childNode) {
					$childBlock = $this->convertNodeToContentBlock($id, $childNode, $blockLevel);
					if ($childBlock) {
						// Append child block's text and inline styles
						$block['text'] .= $childBlock['text'];
						$block['inlineStyleRanges'] = array_merge(
							$block['inlineStyleRanges'],
							$childBlock['inlineStyleRanges']
						);
					}
				}

				// Handle inline styles
				$inlineStyleMap = [
					'strong' => 'BOLD',
					'em' => 'ITALIC',
					// Add more inline styles as needed
				];

				if (isset($inlineStyleMap[$tagName])) {
					$inlineStyle = $inlineStyleMap[$tagName];
					$startIndex = strlen($block['text']);
					$endIndex = $startIndex + strlen($node->textContent);

					$block['inlineStyleRanges'][] = [
						'offset' => $startIndex,
						'length' => $endIndex - $startIndex,
						'style' => $inlineStyle,
					];
				}

				return $block;
		}

		return null;
	}



	private function update_object($object_id, $update_params)
	{
		$post_params = ['ID' => $object_id];

		if (!empty($update_params['post_title']) && !is_null($update_params['post_title'])) {
			$post_params['post_title'] = $update_params['post_title'];
			unset($update_params['post_title']);
		}
		if (!empty($update_params['post_excerpt']) && !is_null($update_params['post_excerpt'])) {
			$post_params['post_excerpt'] = $update_params['post_excerpt'];
			unset($update_params['post_excerpt']);
		}
		if (!empty($update_params['post_content']) && !is_null($update_params['post_content'])) {
			$post_params['post_content'] = $update_params['post_content'];
			unset($update_params['post_content']);
		}
		if (!empty($update_params['post_status']) && !is_null($update_params['post_status'])) {
			$post_params['post_status'] = $update_params['post_status'];
			unset($update_params['post_status']);
		}
		if (!empty($update_params['post_name']) && !is_null($update_params['post_name'])) {
			$post_params['post_name'] = $update_params['post_name'];
			unset($update_params['post_name']);
		}
		if (!empty($update_params['post_category']) && !is_null($update_params['post_category'])) {
			$post_params['post_category'] = $update_params['post_category'];
			unset($update_params['post_category']);
		}
		if (!empty($update_params['post_author']) && !is_null($update_params['post_author'])) {
			$post_params['post_author'] = $update_params['post_author'];
			unset($update_params['post_author']);
		}
		if (!empty($update_params['comment_status']) && !is_null($update_params['comment_status'])) {
			$post_params['comment_status'] = $update_params['comment_status'];
			unset($update_params['comment_status']);
		}
		if (!empty($update_params['post_date']) && !is_null($update_params['post_date'])) {
			$post_params['post_date'] = $update_params['post_date'];
			unset($update_params['post_date']);
		}
		if (!empty($update_params['post_parent']) && !is_null($update_params['post_parent'])) {			
			$update_params['post_parent'] = isset($update_params['post_parent']) ? $update_params['post_parent']: 0;
			$post_params['post_parent'] = $update_params['post_parent'];
			unset($update_params['post_parent']);
		}
		// Update Post and Page content
		
		$tryUpdatePost = wp_update_post($post_params);
		// Update Elementor post content
		// $this->elementor_update_content($object_id, $post_params['post_content']);
		// Update Post and Page meta data
		$resp_meta = array(
			'post_content' => false,
			'post_meta' => array()
		);

		if ($tryUpdatePost !== 0 && $tryUpdatePost !== false)
			$resp_meta['post_content'] = true;

		foreach ($update_params as $key => $value) {
			// var_dump($object_id, $key, $value);
			// if (!empty($value) && !is_null($value)) {
				$response = update_post_meta($object_id, $key, $value);
				if ($response == false) {
					$resp_meta['post_meta'][$object_id][$key] = 'NO_CHANGE';
				} else {
					$resp_meta['post_meta'][$object_id][$key] = 'UPDATED'; //$response;
				}
			// }
		}
		return $resp_meta;
	}

	public function update_items($request)
	{
		$data = array();

		$array_response = true;
		if (gettype($request) == "object")
			$array_response = false;

		$request_data = array($request);
		if ($array_response == false)
			$request_data = $request->get_json_params();

		foreach ($request_data as $post) {
			$update_params = array();
			$post_id = 0;

			// Gettin post id from payload
			if ($post_id == 0 && isset($post['post_id']) && !empty($post['post_id'])) {
				$post_id = sanitize_text_field($post['post_id']);
			} else {
				// Getting post id via URL
				if (isset($post['post_url']) && !empty($post['post_url'])) {
					$safe_url = sanitize_url($post['post_url']);
					$post_id = url_to_postid($safe_url);

					if ($post_id == 0) {
						// try to get post_id by permalink
						# $post_id = @get_page_by_path(sanitize_text_field($post['permalink']), OBJECT, 'post')->ID;
						# Fix to avoid PHP error if the get_page_by_path returns null
						$post_by_path = @get_page_by_path(sanitize_text_field($post['permalink']), OBJECT, 'post');
						$post_id = $post_by_path ? $post_by_path->ID : 0;
					}

					if ($post_id == 0) {
						// try to get permalink from URL
						$url_to_permalink = $this->common->get_permalink_from_url($safe_url);
						# $post_id = @get_page_by_path($url_to_permalink, OBJECT, 'post')->ID;
						# Fix to avoid PHP error if the get_page_by_path returns null
						$post_by_path = @get_page_by_path($url_to_permalink, OBJECT, 'post');
						$post_id = $post_by_path ? $post_by_path->ID : 0;
					}
				}
			}
			if(!$array_response){				
				$post_data = get_post($post['post_id']);						
				if ($post_data->post_type !== $post['post_type']) {	
					if ($post_data) {					
						$post_data->post_type = 'post';
						wp_update_post($post_data);
					}						
				}
			}
			$post_data = get_post($post_id);
			if (!$post_data) {
				return new WP_Error(
					'rest_post_update_fail',
					esc_html__('No post found in the database with requested ID.'),
					array('status' => 400)
				);
			}

			$post_author = isset($post['post_author']) && !empty($post['post_author']) ?
				$update_params['post_author'] = sanitize_text_field($post['post_author']) :
				$update_params['post_author'] = $post_data->post_author;
			wp_set_current_user($post_author);

			if (isset($post['post_title']) && !empty($post['post_title'])) {
				$update_params['post_title'] = sanitize_text_field($post['post_title']);
			}
			if (isset($post['post_parent']) && !empty($post['post_parent'])) {
				$update_params['post_parent'] = (int) $post['post_parent'];
			}
			if (isset($post['meta_description']) && !empty($post['meta_description'])) {
				$get_desc_meta = get_post_meta($post['post_id'], 'meta_description', true);
				$update_params['meta_description'] = $post['meta_description'] ?
					sanitize_text_field($post['meta_description']) : $get_desc_meta;
			}
			if (isset($post['meta_robots']) && !empty($post['meta_robots'])) {
				$get_robots_meta = get_post_meta($post['post_id'], 'meta_robots', true);
				$update_params['meta_robots'] = $post['meta_robots'] ?
					sanitize_text_field($post['meta_robots']) : $get_robots_meta;
			}
			if (isset($post['meta_canonical']) && !empty($post['meta_canonical'])) {
				$update_params['meta_canonical'] = sanitize_text_field($post['meta_canonical']);
			}

			if (isset($post['post_content']) && !empty($post['post_content']) && empty($post['otto_ai_page'])) {

				# Above we are updating the post_type so we have to get latest value that has been change on the server 
				$post_fresh_data = get_post($post['post_id']);

				/**
				 * Check if current theme is Flatsome using SAVED theme info (not wp_get_theme())
				 * Theme info is saved by admin hooks, so no security triggers during REST API
				 */
				$metasync_general = Metasync::get_option('general');
				$theme_name = $metasync_general['current_theme_name'] ?? '';
				$theme_template = $metasync_general['current_theme_template'] ?? '';
				
				$is_flatsome_theme = false;
				if (!empty($theme_name) && stripos($theme_name, 'Flatsome') !== false) {
					$is_flatsome_theme = true;
				} elseif (!empty($theme_template) && stripos($theme_template, 'flatsome') !== false) {
					$is_flatsome_theme = true;
				}

				# Skip title/image prepending for Flatsome (it displays them by default)
				if (!$is_flatsome_theme) {
					# Get the setting for the post template
					$title_and_feature_image = $this->append_content_if_missing_elements($post_fresh_data->post_type);

					# Check if the post title is there in the template or not
					if(!$title_and_feature_image['image_in_content'] && !empty($post['hero_image_url'])){
						
						# Prepend the feature image
						$post['post_content'] = '<img src="'.$post['hero_image_url'].'" />'.$post['post_content'] ;
					}

					# Check if the post title is there in the template or not
					if(!$title_and_feature_image['title_in_headings']){

						# Prepend the post title
						$post['post_content'] = '<h1>'.$post['post_title'].'</h1>'.$post['post_content'] ;
					}
				}
				// This will be used by update_page function
				$content = $this->metasync_upload_post_content($post,false,false); 
				$update_params['post_content'] = $content['content'];
			}
			/* 
			check if the create_item is called by set_landing_page function or not 
			by doing this we will prevent html from going into builder page option
			*/
			if(isset($post['otto_ai_page']) && $post['otto_ai_page']==true){
				$content = $this->metasync_upload_post_content($post,true,true);
				$update_params['post_content'] = $content['content'];
				// delete the elementor related meta data so that it won't get proccess by elementor
				delete_post_meta( $post_id, '_elementor_data' );
				delete_post_meta( $post_id, '_elementor_version' );
				delete_post_meta( $post_id, '_elementor_css' );
				delete_post_meta( $post_id, '_elementor_page_assets' );
			}

			// Add custom field for post header section
			if (isset($post['custom_post_header'])) { //  && !empty($post['custom_post_header'])
				$update_params['custom_post_header'] = $post['custom_post_header'];
			}
			
			if (isset($post['custom_post_footer'])) { //  && !empty($post['custom_post_footer'])
				$update_params['custom_post_footer'] = $post['custom_post_footer'];
			}
			
			if (isset($post['searchatlas_embed_top'])) { //  && !empty($post['searchatlas_embed_top'])
				$update_params['searchatlas_embed_top'] = $post['searchatlas_embed_top'];
			}

			if (isset($post['searchatlas_embed_bottom'])) { //  && !empty($post['searchatlas_embed_bottom'])
				$update_params['searchatlas_embed_bottom'] = $post['searchatlas_embed_bottom'];
			}

			if (isset($post['meta_description']) && !empty($post['meta_description'])) {
				$update_params['post_excerpt'] = sanitize_text_field($post['meta_description']);			

			}

			if (isset($post['post_status']) && !empty($post['post_status'])) {
				$update_params['post_status'] = $post['post_status'] ? sanitize_text_field($post['post_status']) : 'publish';
				$permalink = get_permalink($post_id);
			}
			if (isset($post['permalink']) || !empty($post['permalink'])) {
				$update_params['post_name'] = sanitize_text_field($post['permalink']);
			}
			if (isset($post['post_parent']) ) {				
				$update_params['post_parent'] = isset($post['post_parent']) ? sanitize_text_field($post['post_parent']) : 0;
				
				wp_update_post(
					array(
						'ID' =>$post_id, 
						'post_parent' => $update_params['post_parent']
					)
				);
			}
			

			if (isset($post['post_date']) && !empty($post['post_date']) && false) {
				$is_valid_date = date('Y-m-d', strtotime($post['post_date'])) == $post['post_date'];
				if (!$is_valid_date) {
					return new WP_Error(
						'rest_post_invalid_date',
						esc_html__('Post date is not valid'),
						array('status' => 400)
					);
				}

				$date_limit_str = strtotime(date('Y-m-d') . '-2 month');
				$post_date_str = strtotime($post['post_date']);

				if ($date_limit_str >= $post_date_str) {
					$newDate = date('Y-m-d', strtotime('-2 month'));
					return new WP_Error(
						'rest_post_greater_date',
						esc_html__("Post date should be greater then " . $newDate),
						array('status' => 400)
					);
				}

				if ($post_date_str > strtotime(date('Y-m-d'))) {
					return new WP_Error(
						'rest_post_greater_date',
						esc_html__('Post date should be less then Today'),
						array('status' => 400)
					);
				}
				$update_params['post_date'] = sanitize_text_field($post['post_date'] . date(' h:i:s'));
			}

			$post_cattegories = [];
			# if ($post_data && $post_data->post_type === 'post' && is_array(@$post['post_categories'])) {

			# fixed Undefined array key issue
			if ($post_data && $post_data->post_type === 'post' && isset($post['post_categories']) && is_array($post['post_categories'])) {
				$append_categories = isset($post['append_categories']) && $post['append_categories'] == true ? true : false;
				$post_cattegories = $this->metasync_handle_post_category($post_id, $post['post_categories'], $append_categories);
			}
			
			$post_tags = [];
			# if ($post_data && $post_data->post_type === 'post' && is_array(@$post['post_tags'])) {

			# fixed Undefined array key 'post_tags' issue
			if ($post_data && $post_data->post_type === 'post' && isset($post['post_tags']) && is_array($post['post_tags'])) {
				$append_tags = isset($post['append_tags']) && $post['append_tags'] == true ? true : false;
				$post_tags = $this->metasync_set_post_tags($post_id, $post['post_tags'], $append_tags);
			}

			$attachment_id = '';
			if (isset($post['hero_image_url']) && !empty($post['hero_image_url'])) {
				$attachment_id = $this->metasync_handle_hero_image($post_id, $post['hero_image_url'], $post['hero_image_alt_text']);
			}

			$resp_update = $this->update_object($post_id, $update_params);
			if(isset($content['elementor_meta_data'])){				
				foreach ($content['elementor_meta_data'] as $key => $value) {
					update_post_meta($post_id, $key, $value);
				}
				if ( did_action( 'elementor/loaded' ) ) {
					// Clear Elementor cache for the specified post ID
					\Elementor\Plugin::instance()->files_manager->clear_cache();

				}				
			}

			$redirection = array();
			if (!empty($post['redirection_enable']) && !is_null($post['redirection_enable'])) {
				$redirection['enable'] = sanitize_text_field($post['redirection_enable']);
			}
			if (!empty($post['redirection_type']) && !is_null($post['redirection_type'])) {
				$redirection['type'] = sanitize_text_field($post['redirection_type']);
			}
			if (!empty($post['redirection_url']) && !is_null($post['redirection_url'])) {
				$redirection['url'] = sanitize_url($post['redirection_url']);
			}
			if (!empty($redirection)) {
				update_post_meta($post_id, 'metasync_post_redirection_meta', $redirection);
			}


			$post_revisions = wp_get_post_revisions($post_id);
			// Sync post categories to customer dashboard
			$this->lgSendCustomerPostParams();

			unset($update_params['post_name']);
			unset($update_params['post_category']);

			$update_params['post_categories'] = $post_cattegories;
			$update_params['post_tags'] = $post_tags;
			$update_params['post_id'] = (int) $post_id;
			$update_params['permalink'] = $permalink;

			$update_params['hero_image_url'] = wp_get_attachment_url($attachment_id);
			$update_params['hero_image_alt_text'] = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
			$update_params['post_revisions'] = gettype($post_revisions) == 'array' ? count($post_revisions) : (int)$post_revisions;
			$update_params['post_updated'] = $resp_update;

			
			// check if the content is added or not 
			if(empty($post['is_landing_page']) ){
				 
				# update the content
				$postContent = array(
					'ID' =>  $post_id,
					'post_content' => ($content['content'] ?  $content['content'] : $post['post_content']),
				);
				 wp_update_post($postContent );
				 #rename the variable to avoide confusion
				 $post_meta_data = array();
					if(isset($content['elementor_meta_data'])){
						$post_meta_data = array_merge($post_meta_data,$content['elementor_meta_data']);
					}else if(isset($content['divi_meta_data'])){
						$post_meta_data = array_merge($post_meta_data,$content['divi_meta_data']);
						
					}
				# update the content
					// add and update the post meta
				 foreach ($post_meta_data as $key => $value) {
					// if (!empty($value) && !is_null($value)) {
						
					update_post_meta($post_id, $key, $value);
					// 
				}
				#check if the elementor plugin is active
				if ( did_action( 'elementor/loaded' ) ) {
					# Clear Elementor cache for the specified post ID
					\Elementor\Plugin::instance()->files_manager->clear_cache();

				}
            }
			# Log sync history for Content Genius post update
			if ($post_id > 0 && !empty($update_params)) {
				$post_title = $update_params['post_title'] ?? $post_data->post_title ?? 'Untitled';
				$title_preview = mb_strlen($post_title) > 30 ? mb_substr($post_title, 0, 30) . '...' : $post_title;
				$post_status = $update_params['post_status'] ?? $post_data->post_status ?? 'draft';
				$post_type = $post_data->post_type ?? 'post';

				# Use appropriate title based on post type
				$content_type_label = ($post_type === 'page') ? 'Page' : 'Post';
				$sync_status = ($post_status === 'publish') ? 'published' : $post_status;

				metasync_log_sync_history([
					'title' => "{$content_type_label} Updated ({$title_preview})",
					'source' => 'Content Genius',
					'status' => $sync_status,
					'content_type' => ucfirst($post_type),
					'url' => $permalink ?? get_permalink($post_id),
					'meta_data' => json_encode([
						'post_id' => $post_id,
						'post_title' => $post_title,
						'post_type' => $post_type,
						'post_status' => $post_status,
						'action' => 'updated',
						'updated_fields' => array_keys($update_params)
					])
				]);

				# Track Content Genius event in Mixpanel
				try {
					$mixpanel = Metasync_Mixpanel::get_instance();
					$mixpanel->track_content_genius_event($post_id, 'updated');
				} catch (Exception $e) {
					error_log('MetaSync: Mixpanel tracking failed for Content Genius - ' . $e->getMessage());
				}
			}

			ksort($update_params);
			$data[] = $update_params;
		}

		return rest_ensure_response($data);
	}
	/*
		Populate the style data into post meta or update the data
	*/
	private function style_meta_data($styleData,$post_id,$update = false){
		// check if $styleData is an array
		if(is_array($styleData)){
			//loop through every key present in the $styleData
			foreach($styleData as $key=> $styleItem){
				// store the post meta on the basis of the key check if it comes from page_update or page_create function
				if($update){
					update_post_meta((int)$post_id, $key, json_encode($styleItem)); // update the style data
				}else{
					add_post_meta((int)$post_id, $key, json_encode($styleItem), true ); // store the style data					
				}
				
			}
		}
	}

	public function create_page($request)
	{
		$payload = $request->get_json_params();
	
		#check if we have the params set
		if (!isset($payload[0]) || empty($payload[0])) {
			# Return an error response for invalid request data
			return new WP_Error(
				'validation_error',
				'Invalid request data. Empty Payload Provided',
				array('status' => 400)
			);
		}
	
		#set the payload 
		$payload = $payload[0];
	
		$payload['post_type'] = "page";
		$createPages = $this->create_item($payload); // creating page

		$post_ids = array();

		if (is_array($createPages) !== true) {
			$createPages = $createPages->data;
		}
		foreach ($createPages as $item) {
			array_push($post_ids, $item['post_id']);
		}

		$payloadIndex = 0;
		$pageTemplate = 'default';
		foreach ($post_ids as $post_id) {
			/*
			check if the payload for style_data and otto_ai_page is set or not 
			Also Check if the otto_ai_page is true or not if set true set Metasync Template for the page
			*/
			if(isset($payload['style_data'])  && isset($payload['otto_ai_page']) && $payload['otto_ai_page']==true){
			// Change the page template from default to  Metasync Template
			$pageTemplate = Metasync_Template::TEMPLATE_NAME;
			// store the style_date in a variable to ease the process
			$styleData = $payload['style_data'];
			// check if $styleData is an array
			if(is_array($styleData)){
				// add the post meta by calling the style_meta_data function
				$this->style_meta_data($styleData,$post_id);
			}
			// delete the elementor data so that it won't create problem in rendering 
			delete_post_meta( $post_id, '_elementor_data' );
			delete_post_meta( $post_id, '_elementor_version' );
			delete_post_meta( $post_id, '_elementor_css' );
			delete_post_meta( $post_id, '_elementor_page_assets' );
			}
			if (
				isset($payload[$payloadIndex]['is_blank']) &&
				!empty($payload[$payloadIndex]['is_blank']) &&
				$payload[$payloadIndex]['is_blank'] != 'false'
			) {
				require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-template.php';
				$pageTemplate = Metasync_Template::TEMPLATE_NAME;
			}
			if(isset($payload['otto_ai_page']) && $payload['otto_ai_page']){
				// Change the page template from default to  Metasync Template
				$pageTemplate = Metasync_Template::TEMPLATE_NAME;
			}
			update_post_meta($post_id, '_wp_page_template', $pageTemplate);
		}

		return rest_ensure_response($createPages);
	}

	public function update_page($request)
	{
		$payload = $request->get_json_params()[0];
		$payload['post_type'] = "page";
		
		$post_data = get_post($payload['post_id']);
		if(!isset($post_data->post_type)){				
			return new WP_Error(
				'rest_page_type_fail',
				esc_html__('No page found in the database with requested ID.'),
				array('status' => 400)
			);
		}
		if ($post_data->post_type !== 'page') {				
			// Verify if the post exists
			if ($post_data) {
				// Update the post type
				$post_data->post_type = 'page';		
				// Save the changes
				wp_update_post($post_data);
			}				
		}

		$updatePages = $this->update_items($payload); // updating page
		$post_ids = array();
		foreach ($updatePages->data as $item) {
			array_push($post_ids, $item['post_id']);
		}

		$payloadIndex = 0;
		$pageTemplate = 'default';
		foreach ($post_ids as $post_id) {
			/*
			check if the payload for style_data and otto_ai_page is set or not 
			Also Check if the otto_ai_page is true or not if set true 
			Update the  Metasync Template for the page with css and js
			*/
			if(isset($payload['style_data']) && $payload['otto_ai_page']==true){
				// Change the page template from default to  Metasync Template				
				$pageTemplate = Metasync_Template::TEMPLATE_NAME;
				// store the style_date in a variable to ease the process
				$styleData = $payload['style_data'];
				// check if $styleData is an array
				if(is_array($styleData)){
					// update the post meta by calling the style_meta_data function
					$this->style_meta_data($styleData,$post_id,true); 
				}
				
					
				}
			if (
				isset($payload[$payloadIndex]['is_blank']) &&
				!empty($payload[$payloadIndex]['is_blank']) &&
				$payload[$payloadIndex]['is_blank'] != 'false'
			) {
				require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-template.php';
				$pageTemplate = Metasync_Template::TEMPLATE_NAME;
			}
			if(isset($payload['otto_ai_page']) && $payload['otto_ai_page']){
				// Change the page template from default to  Metasync Template
				$pageTemplate = Metasync_Template::TEMPLATE_NAME;
			}
			update_post_meta($post_id, '_wp_page_template', $pageTemplate);
		}
		return rest_ensure_response($updatePages->data);
	}

	public function delete_page()
	{
		$deletePage = $this->delete_item(); // deleting page
		return rest_ensure_response($deletePage);
	}

	/**
	 * Data or Response received from HeartBeat API for admin area.
	 */
	public function lgSendCustomerPostParams()
	{
		$sync_request = new Metasync_Sync_Requests();
		$response = $sync_request->SyncCustomerParams();

		$responseCode = wp_remote_retrieve_response_code($response);
		if ($responseCode == 200) {
			$dt = new DateTime();
			$send_auth_token_timestamp = Metasync::get_option();
			$send_auth_token_timestamp['general']['send_auth_token_timestamp'] = $dt->format('M d, Y  h:i:s A');
			Metasync::set_option($send_auth_token_timestamp);
		}
	}

	public function linkgraph_login()
	{
		$post_data = sanitize_post($_POST);
		$payload = array(
			'username' => wp_unslash(sanitize_email($post_data['username'])),
			'password' => wp_unslash(sanitize_text_field($post_data['password']))
		);

		$api_domain = class_exists('Metasync_Endpoint_Manager')
			? Metasync_Endpoint_Manager::get_endpoint('API_DOMAIN')
			: Metasync::API_DOMAIN;

		# PERFORMANCE OPTIMIZATION: Add timeout to prevent hung requests
		$response = wp_remote_post($api_domain . '/api/token/', array(
			'body' => $payload,
			'timeout' => 10,
		));

		# Error handling for timeout or connection failures
		if (is_wp_error($response)) {
			error_log('MetaSync: Token API failed: ' . $response->get_error_message());
			wp_send_json_error(array('message' => 'Token API request failed'));
			wp_die();
		}

		$get_object = isset($response['body']) ? json_decode($response['body']) : array();
		if (!empty($get_object)) {
			wp_send_json($get_object);
		}
		wp_die();
	}

	public function sync_heartbeat_data()
	{
		$sync_heartbeat_data = new Metasync_Sync_Requests();
		$response = $sync_heartbeat_data->SyncCustomerParams();

		$responseCode = wp_remote_retrieve_response_code($response);
		if ($responseCode == 200) {
			return rest_ensure_response($response);
		}
		return rest_ensure_response($response);
	}

	public function get_heartbeat_errorlogs()
	{
		$heartbeat_error_db = new Metasync_HeartBeat_Error_Monitor_Database();
		$response = $heartbeat_error_db->getAllRecords();

		if (!empty($response)) {
			return rest_ensure_response($response);
		}
		return rest_ensure_response(['Error logs not found']);
	}

	/**
	 * Search Atlas Connect Callback Permission Validation
	 *
	 * Validates the nonce token before Search Atlas delivers the API key and Otto UUID.
	 * Does NOT create a WordPress login session.
	 * Validates the nonce token in the x-api-key header
	 */
	public function validate_searchatlas_callback_permission($request)
	{
		try {
			error_log('MetaSync SA Connect: validate_searchatlas_callback_permission() called');
			// Step 1: Validate nonce token format from header
			$nonce_token = $request->get_header('x-api-key');
			$format_validation = $this->validate_searchatlas_nonce_format($nonce_token);

			if (is_wp_error($format_validation)) {
				return $format_validation;
			}

			// Step 2: Validate token exists and is not expired (READ-ONLY check)
			// BUGFIX: Don't call validate_deterministic_searchatlas_token() here because it marks token as used!
			// Just check if token exists and hasn't expired
			if (empty($nonce_token) || strlen($nonce_token) < 32) {
				return new WP_Error(
					'invalid_nonce_token',
					'Invalid nonce token format',
					array('status' => 401)
				);
			}

			// Check token exists in transients (read-only - don't modify)
			$transient_key = get_transient('metasync_sa_connect_active_' . $nonce_token);
			if (empty($transient_key)) {
				error_log('MetaSync SA Connect Permission: Token not found in transients');
				return new WP_Error(
					'invalid_nonce_token',
					'Invalid or expired nonce token',
					array('status' => 401)
				);
			}

			// Check token metadata exists (read-only)
			$token_metadata = get_transient($transient_key);
			if (empty($token_metadata) || !is_array($token_metadata)) {
				error_log('MetaSync SA Connect Permission: Token metadata not found');
				return new WP_Error(
					'invalid_nonce_token',
					'Invalid nonce token',
					array('status' => 401)
				);
			}

			// Check not expired (read-only)
			if (isset($token_metadata['expires']) && time() > $token_metadata['expires']) {
				error_log('MetaSync SA Connect Permission: Token expired');
				return new WP_Error(
					'invalid_nonce_token',
					'Nonce token expired',
					array('status' => 401)
				);
			}

			error_log('MetaSync SA Connect Permission: Token validation passed (read-only check)');
			// Permission granted - but don't mark token as used yet!
			// The main handler will do that after successful processing
			return true;
		} catch (Exception $e) {
			return new WP_Error(
				'permission_validation_error',
				'Internal error during permission validation',
				array('status' => 500)
			);
		}
	}

    /**
     * Validate Search Atlas connect token for callback
     * SECURITY FIX (CVE-2025-14386): Only validates against time-limited transient tokens
     * Tokens must be created by generate_searchatlas_connect_url() and stored in transients
     */
    private function validate_deterministic_searchatlas_token($token)
    {
        if (empty($token) || strlen($token) < 32) {
            error_log('MetaSync SA Connect Callback: Token validation failed - invalid token format');
            return false;
        }

        // SECURITY FIX: Token MUST exist in transients (created by generate_searchatlas_connect_url)
        // We do NOT fall back to apikey - this was the vulnerability!
        $transient_key = get_transient('metasync_sa_connect_active_' . $token);
        
        if (empty($transient_key)) {
            error_log('MetaSync SA Connect Callback: Token not found in active tokens - rejected');
            return false;
        }

        // Token found in transients - validate metadata
        $token_metadata = get_transient($transient_key);

        if (empty($token_metadata) || !is_array($token_metadata)) {
            error_log('MetaSync SA Connect Callback: Token metadata not found');
            delete_transient('metasync_sa_connect_active_' . $token);
            return false;
        }

        error_log('MetaSync SA Connect Callback: Token metadata retrieved, callback_used = ' .
            (isset($token_metadata['callback_used']) ? ($token_metadata['callback_used'] ? 'true' : 'false') : 'not set'));
        error_log('MetaSync SA Connect Callback: Token metadata used = ' .
            (isset($token_metadata['used']) ? ($token_metadata['used'] ? 'true' : 'false') : 'not set'));

        // Check expiration
        if (isset($token_metadata['expires']) && time() > $token_metadata['expires']) {
            error_log('MetaSync SA Connect Callback: Token expired');
            delete_transient($transient_key);
            delete_transient('metasync_sa_connect_active_' . $token);
            return false;
        }

        // Check if already used for callback (single-use enforcement)
        if (isset($token_metadata['callback_used']) && $token_metadata['callback_used'] === true) {
            error_log('MetaSync SA Connect Callback: Token already used for callback - rejected');
            return false;
        }
        
        // Mark token as used for callback (single-use)
        $token_metadata['callback_used'] = true;
        $token_metadata['callback_at'] = time();
        // BUGFIX: Keep metadata for 5 minutes to allow user login to complete
        set_transient($transient_key, $token_metadata, 300); // Keep for 5 minutes for user login

        // BUGFIX: Only delete the active token mapping if BOTH operations are complete
        // This allows user login and API callback to happen in any order without race conditions
        if (isset($token_metadata['used']) && $token_metadata['used'] === true) {
            // Both callback and login are done - safe to delete mapping
            delete_transient('metasync_sa_connect_active_' . $token);
        }
        // Otherwise, keep the mapping so user login can still find the token

        return true;
    }

    /**
     * Check if token is an enhanced SALT-based token
     */
    private function is_enhanced_token($token)
    {
        // Enhanced tokens are exactly 64 characters (SHA256 hash)
        // and include SALT-based entropy
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return false;
        }
        
        // Check if token data indicates enhanced version
        $nonce_data = get_option('metasync_sa_connect_nonce_' . $token);
        if ($nonce_data) {
            $data = json_decode($nonce_data, true);
            return isset($data['enhanced']) && $data['enhanced'] === true;
        }
        
        return false;
    }

    /**
     * Validate enhanced SALT-based Search Atlas connect token
     */
    private function validate_enhanced_searchatlas_token($token)
    {
        $nonce_data = get_option('metasync_sa_connect_nonce_' . $token);
        
        if (!$nonce_data) {
            return false;
        }

        $nonce_data = json_decode($nonce_data, true);
        
        if (!is_array($nonce_data)) {
            return false;
        }

        // Check if token has expired
        if (isset($nonce_data['expires']) && $nonce_data['expires'] < time()) {
            delete_option('metasync_sa_connect_nonce_' . $token);
            return false;
        }

        // Check if token has already been used
        if (isset($nonce_data['used']) && $nonce_data['used']) {
            return false;
        }

        // Additional validation for enhanced tokens
        if (isset($nonce_data['enhanced']) && $nonce_data['enhanced']) {
            // Perform additional security checks for enhanced tokens
            if (!$this->validate_enhanced_token_security($token, $nonce_data)) {
                return false;
            }
        }

        return $nonce_data;
    }

    /**
     * Validate legacy Search Atlas connect token (backward compatibility)
     */
    private function validate_legacy_searchatlas_token($token)
    {
        $nonce_data = get_option('metasync_sa_connect_nonce_' . $token);
        
        if (!$nonce_data) {
            return false;
        }

        $nonce_data = json_decode($nonce_data, true);

        // Check if token has expired
        if (isset($nonce_data['expires']) && $nonce_data['expires'] < time()) {
            delete_option('metasync_sa_connect_nonce_' . $token);
            return false;
        }

        // Check if token has already been used
        if (isset($nonce_data['used']) && $nonce_data['used']) {
            return false;
        }

        return $nonce_data;
    }

    /**
     * Additional security validation for enhanced tokens
     */
    private function validate_enhanced_token_security($token, $nonce_data)
    {
        // Rate limiting check (optional)
        if ($this->is_token_rate_limited($token)) {
            return false;
        }

        // Time-based validation (ensure token is not too old for creation time)
        if (isset($nonce_data['created'])) {
            $creation_time = $nonce_data['created'];
            $current_time = time();
            
            // Token shouldn't be older than 35 minutes (5 min buffer)
            if (($current_time - $creation_time) > 2100) {
                return false;
            }
        }

        return true;
    }

    /**
     * Simple rate limiting for token validation attempts
     */
    private function is_token_rate_limited($token)
    {
        $rate_limit_key = 'sa_connect_rate_limit_' . substr($token, 0, 8);
        $attempts = get_transient($rate_limit_key);
        
        if ($attempts === false) {
            set_transient($rate_limit_key, 1, 300); // 5 minutes
            return false;
        }
        
        if ($attempts >= 10) { // Max 10 attempts per 5 minutes
            return true;
        }
        
        set_transient($rate_limit_key, $attempts + 1, 300);
        return false;
    }


	/**
	 * Handle Search Atlas Connect Callback (REST API)
	 *
	 * Called by the Search Atlas platform after admin authenticates on their dashboard.
	 * Receives the Search Atlas API key and Otto UUID and stores them in WordPress options.
	 * Does NOT create a WordPress login session.
	 * Processes the callback from Search Atlas platform with new API key
	 */
	public function handle_searchatlas_api_callback($request)
	{
		try {
			error_log('MetaSync SA Connect: handle_searchatlas_api_callback() called');
			// Step 1: Validate nonce token from header
			$nonce_token = $request->get_header('x-api-key');
			$nonce_validation = $this->validate_searchatlas_nonce_format($nonce_token);
			
			if (is_wp_error($nonce_validation)) {
				return $nonce_validation;
			}
			
			// Step 2: Validate request body structure
			$body_params = $request->get_json_params();
			$body_validation = $this->validate_searchatlas_request_body($body_params);
			
			if (is_wp_error($body_validation)) {
				return $body_validation;
			}
			
			// Step 3: Extract and validate individual parameters
			$validated_params = $this->extract_and_validate_searchatlas_params($body_params);
			
			if (is_wp_error($validated_params)) {
				return $validated_params;
			}
			
			// Step 4: Validate nonce token by regenerating it
			if (!$this->validate_deterministic_searchatlas_token($nonce_token)) {
				return new WP_Error(
					'invalid_nonce', 
					'Invalid nonce token',
					array('status' => 401)
				);
			}
			
			// Step 5: Process the callback and update settings
			$success = $this->mark_searchatlas_nonce_used(
				$nonce_token,
				$validated_params['api_key'],
				$validated_params['uuid'],
				$validated_params['status_code'],
				$validated_params['is_whitelabel'],
				$validated_params['whitelabel_domain'],
				$validated_params['whitelabel_logo'],
				$validated_params['whitelabel_company_name'],
				$validated_params['whitelabel_otto']
			);
			
			if (!$success) {
				return new WP_Error(
					'update_failed',
					'Failed to update plugin settings',
					array('status' => 500)
				);
			}
			
			// Step 6: Return success response
			return rest_ensure_response(array(
				'success' => true,
				'message' => 'Search Atlas connect callback processed successfully',
				'data' => array(
					'status_code' => $validated_params['status_code'],
					'api_key_updated' => $validated_params['status_code'] === 200,
					'whitelabel_enabled' => $validated_params['is_whitelabel'],
					'effective_domain' => Metasync::get_dashboard_domain()
				)
			));

		} catch (Exception $e) {
			return new WP_Error(
				'internal_error',
				'Internal server error occurred while processing Search Atlas connect callback',
				array('status' => 500)
			);
		}
	}

	/**
	 * Validate Search Atlas connect nonce token format
	 * 
	 * @param string $nonce_token The nonce token to validate
	 * @return true|WP_Error True if valid, WP_Error if invalid
	 */
	private function validate_searchatlas_nonce_format($nonce_token)
	{
		// Check if nonce token is provided
		if (empty($nonce_token)) {
			return new WP_Error(
				'missing_nonce_token',
				'Missing x-api-key header with nonce token',
				array('status' => 401, 'field' => 'x-api-key')
			);
		}
		
		// Check nonce token format (should be Plugin Auth Token - at least 8 characters)
		if (strlen($nonce_token) < 8) {
			return new WP_Error(
				'invalid_nonce_format',
				'Invalid nonce token format. Token too short',
				array('status' => 400, 'field' => 'x-api-key')
			);
		}
		
		return true;
	}
	
	/**
	 * Validate Search Atlas connect request body structure
	 * 
	 * @param mixed $body_params Request body parameters
	 * @return true|WP_Error True if valid, WP_Error if invalid
	 */
	private function validate_searchatlas_request_body($body_params)
	{
		// Check if body exists and is valid JSON
		if (empty($body_params)) {
			return new WP_Error(
				'empty_request_body',
				'Request body is empty or invalid JSON',
				array('status' => 400)
			);
		}
		
		// Check if body is an array (parsed JSON object)
		if (!is_array($body_params)) {
			return new WP_Error(
				'invalid_request_body',
				'Request body must be a valid JSON object',
				array('status' => 400)
			);
		}
		
		return true;
	}
	
	/**
	 * Extract and validate individual Search Atlas connect parameters
	 * 
	 * @param array $body_params Request body parameters
	 * @return array|WP_Error Validated parameters array or WP_Error
	 */
	private function extract_and_validate_searchatlas_params($body_params)
	{
		$validation_errors = array();
		
		// Extract parameters
		$api_key = isset($body_params['api_key']) ? trim($body_params['api_key']) : '';
		$uuid = isset($body_params['uuid']) ? trim($body_params['uuid']) : '';
		$status_code = isset($body_params['status_code']) ? $body_params['status_code'] : 200;
		
		// Validate api_key
		if (empty($api_key)) {
			$validation_errors['api_key'] = 'API key is required';
		} elseif (!is_string($api_key)) {
			$validation_errors['api_key'] = 'API key must be a string';
		} elseif (strlen($api_key) < 10) {
			$validation_errors['api_key'] = 'API key must be at least 10 characters long';
		} elseif (strlen($api_key) > 255) {
			$validation_errors['api_key'] = 'API key must not exceed 255 characters';
		} elseif (!preg_match('/^[a-zA-Z0-9\-_\.]+$/', $api_key)) {
			$validation_errors['api_key'] = 'API key contains invalid characters. Only alphanumeric, dash, underscore, and dot allowed';
		}
		
		// Validate uuid
		if (empty($uuid)) {
			$validation_errors['uuid'] = 'UUID is required';
		} elseif (!is_string($uuid)) {
			$validation_errors['uuid'] = 'UUID must be a string';
		} elseif (strlen($uuid) > 100) {
			$validation_errors['uuid'] = 'UUID must not exceed 100 characters';
		}
		
		// Validate status_code
		if (!is_numeric($status_code)) {
			$validation_errors['status_code'] = 'Status code must be a number';
		} else {
			$status_code = intval($status_code);
			if ($status_code < 100 || $status_code >= 600) {
				$validation_errors['status_code'] = 'Status code must be between 100 and 599';
			}
		}
		
		// Extract and validate whitelabel fields
		$is_whitelabel = isset($body_params['is_whitelabel']) ? $body_params['is_whitelabel'] : false;
		
		// Validate is_whitelabel
		if (isset($body_params['is_whitelabel']) && !is_bool($body_params['is_whitelabel'])) {
			// Handle string representations of boolean
			if (is_string($body_params['is_whitelabel'])) {
				$whitelabel_string = strtolower($body_params['is_whitelabel']);
				if (in_array($whitelabel_string, ['true', '1', 'yes', 'on'])) {
					$is_whitelabel = true;
				} elseif (in_array($whitelabel_string, ['false', '0', 'no', 'off', ''])) {
					$is_whitelabel = false;
				} else {
					$validation_errors['is_whitelabel'] = 'is_whitelabel must be a boolean value (true/false)';
				}
			} else {
				$validation_errors['is_whitelabel'] = 'is_whitelabel must be a boolean value';
			}
		}
		
		// BUSINESS RULE: If is_whitelabel is false/null, disregard all other whitelabel fields
		if (!$is_whitelabel) {
			// Force all whitelabel fields to empty when is_whitelabel is false
			$whitelabel_domain = '';
			$whitelabel_logo = '';
			$whitelabel_company_name = '';
			$whitelabel_otto = '';
		} else {
			// Only extract and validate whitelabel fields when is_whitelabel is true
			$whitelabel_domain = isset($body_params['whitelabel_domain']) ? trim($body_params['whitelabel_domain']) : '';
			$whitelabel_logo = isset($body_params['whitelabel_logo']) ? trim($body_params['whitelabel_logo']) : '';
			$whitelabel_company_name = isset($body_params['whitelabel_company_name']) ? trim($body_params['whitelabel_company_name']) : '';
			$whitelabel_otto = isset($body_params['whitelabel_otto']) ? trim($body_params['whitelabel_otto']) : '';
			
			// Validate whitelabel_domain (optional but must be valid URL if provided)
			if (!empty($whitelabel_domain)) {
				if (!is_string($whitelabel_domain)) {
					$validation_errors['whitelabel_domain'] = 'Whitelabel domain must be a string';
				} elseif (strlen($whitelabel_domain) > 255) {
					$validation_errors['whitelabel_domain'] = 'Whitelabel domain must not exceed 255 characters';
				} elseif (!filter_var($whitelabel_domain, FILTER_VALIDATE_URL)) {
					$validation_errors['whitelabel_domain'] = 'Whitelabel domain must be a valid URL';
				} elseif (!in_array(parse_url($whitelabel_domain, PHP_URL_SCHEME), ['http', 'https'])) {
					$validation_errors['whitelabel_domain'] = 'Whitelabel domain must use http or https protocol';
				}
			}
			
			// Validate whitelabel_logo (permissive validation - invalid URLs won't fail POST)
			if (!empty($whitelabel_logo)) {
				// Basic validation - only fail POST for serious issues
				if (!is_string($whitelabel_logo)) {
					$validation_errors['whitelabel_logo'] = 'Whitelabel logo must be a string';
				} elseif (strlen($whitelabel_logo) > 1000) {
					$validation_errors['whitelabel_logo'] = 'Whitelabel logo URL must not exceed 500 characters';
				} else {
					// If URL is invalid, we'll clear it later but not fail the POST
					if (!filter_var($whitelabel_logo, FILTER_VALIDATE_URL) || 
						!in_array(parse_url($whitelabel_logo, PHP_URL_SCHEME), ['http', 'https'])) {
						// Don't add to validation_errors - let POST succeed but clear the field
					}
				}
			}
			
			// Validate whitelabel_company_name (maps to Plugin Name)
			if (!empty($whitelabel_company_name)) {
				if (!is_string($whitelabel_company_name)) {
					$validation_errors['whitelabel_company_name'] = 'Whitelabel company name must be a string';
				} elseif (strlen($whitelabel_company_name) > 100) {
					$validation_errors['whitelabel_company_name'] = 'Whitelabel company name must not exceed 100 characters';
				} elseif (!preg_match('/^[a-zA-Z0-9\s\-\.\&\(\)\,\'\"]+$/', $whitelabel_company_name)) {
					$validation_errors['whitelabel_company_name'] = 'Whitelabel company name contains invalid characters. Only letters, numbers, spaces, and common punctuation allowed';
				}
			}
		}
		
		// Return validation errors if any
		if (!empty($validation_errors)) {
			return new WP_Error(
				'validation_failed',
				'Request validation failed',
				array(
					'status' => 422,
					'validation_errors' => $validation_errors
				)
			);
		}
		
		// Return sanitized and validated parameters
		return array(
			'api_key' => sanitize_text_field($api_key),
			'uuid' => sanitize_text_field($uuid),
			'status_code' => $status_code,
			'is_whitelabel' => $is_whitelabel,
			'whitelabel_domain' => !empty($whitelabel_domain) ? esc_url_raw($whitelabel_domain) : '',
			// Only store logo if it's a valid URL, otherwise store empty string
			'whitelabel_logo' => (!empty($whitelabel_logo) && filter_var($whitelabel_logo, FILTER_VALIDATE_URL)) ? esc_url_raw($whitelabel_logo) : '',
			'whitelabel_company_name' => !empty($whitelabel_company_name) ? sanitize_text_field($whitelabel_company_name) : '',
			'whitelabel_otto' => !empty($whitelabel_otto) ? sanitize_text_field($whitelabel_otto) : ''
		);
	}

	/**
	 * Create standardized error response for Search Atlas connect endpoints
	 * 
	 * @param string $error_code Error code identifier
	 * @param string $message Human-readable error message
	 * @param int $status_code HTTP status code
	 * @param array $additional_data Additional error context
	 * @return WP_Error Formatted error response
	 */
	private function create_sso_error_response($error_code, $message, $status_code = 400, $additional_data = array())
	{
		$error_data = array_merge(array(
			'status' => $status_code,
			'timestamp' => current_time('mysql', true),
			'endpoint' => 'searchatlas/connect/callback'
		), $additional_data);
		
		return new WP_Error($error_code, $message, $error_data);
	}

    /**
     * Mark Search Atlas connect nonce as used and store the API key and Otto UUID
     * Enhanced with whitelabel support including logo and company name
     */
    public function mark_searchatlas_nonce_used($token, $new_api_key, $new_otto_uuid, $status_code = 200, $is_whitelabel = false, $whitelabel_domain = '', $whitelabel_logo = '', $whitelabel_company_name = '', $whitelabel_otto = '')
    {
        try {
            // DEBUG: Log that callback processing started
            error_log('MetaSync SA Connect: mark_searchatlas_nonce_used() called with status_code=' . $status_code);

            // Validate token parameter
            if (empty($token)) {
                error_log('MetaSync SA Connect: mark_searchatlas_nonce_used() failed - empty token');
                return false;
            }

            // No need to validate stored token data - token is deterministic
            // Simply proceed with updating plugin settings
            
            // Update plugin settings
            $options = Metasync::get_option();
            
            if (!is_array($options)) {
                $options = array();
            }
            
            if (!isset($options['general'])) {
                $options['general'] = array();
            }
            // Only update the API key in settings if status_code is 200 (success)
            if ($status_code === 200) {
                error_log('MetaSync SA Connect: Status code is 200, updating API key and setting success transient');
                $options['general']['searchatlas_api_key'] = $new_api_key;
                $options['general']['otto_pixel_uuid'] = $new_otto_uuid;
                // Note: OTTO SSR is always enabled by default, no need to set

                // Granular otto_config_status: record when SSO completed (ISO 8601 UTC)
                $options['general']['sso_completed_at'] = gmdate('Y-m-d\TH:i:s\Z');

                // Update authentication timestamp for polling detection (legacy - keeping for compatibility)
                $options['general']['send_auth_token_timestamp'] = current_time('mysql');

                // ✅ NEW: Set nonce-specific success flag for polling detection
                // This ensures only the specific nonce that was authenticated reports success
                $success_key = 'metasync_sa_connect_success_' . md5($token);
                set_transient($success_key, true, 300); // 5 minutes expiry
                error_log('MetaSync SA Connect: Success transient set - key: ' . $success_key);

                // Clear JWT token cache when API key is updated to ensure fresh tokens
                $this->clear_jwt_token_cache();

            } else {
                error_log('MetaSync SA Connect: Status code is NOT 200 (got ' . $status_code . '), NOT setting success transient');
            }
            
            // Map whitelabel fields consistently (regardless of status_code)
            if ($is_whitelabel) {
                // whitelabel_company_name → Plugin Name (general plugin branding)
                if (!empty($whitelabel_company_name)) {
                    $options['general']['white_label_plugin_name'] = $whitelabel_company_name;
                }
                
                // whitelabel_otto → OTTO Features naming (separate from plugin name)
                if (!empty($whitelabel_otto)) {
                    $options['general']['whitelabel_otto_name'] = $whitelabel_otto;
                }
            } else {
                // Clear whitelabel fields when not whitelabel
                unset($options['general']['white_label_plugin_name']);
                unset($options['general']['whitelabel_otto_name']);
            }
            
            // Store whitelabel settings (hidden from UI but accessible to plugin logic)
            if (!isset($options['whitelabel'])) {
                $options['whitelabel'] = array();
            }
            
            $options['whitelabel']['is_whitelabel'] = $is_whitelabel;
            $options['whitelabel']['domain'] = $whitelabel_domain;
            $options['whitelabel']['logo'] = $whitelabel_logo;
            $options['whitelabel']['updated_at'] = time();
            
            // Log whitelabel configuration
            if ($is_whitelabel) {
                $log_parts = array('Whitelabel mode enabled');
                if (!empty($whitelabel_domain)) {
                    $log_parts[] = 'domain: ' . $whitelabel_domain;
                }
                if (!empty($whitelabel_logo)) {
                    $log_parts[] = 'logo: ' . $whitelabel_logo;
                }
                if (!empty($whitelabel_company_name)) {
                    $log_parts[] = 'company: ' . $whitelabel_company_name;
                }
                if (!empty($whitelabel_otto)) {
                    $log_parts[] = 'otto: ' . $whitelabel_otto;
                }
                
            }
            
            $save_result = Metasync::set_option($options);

            if (!$save_result) {
                error_log('MetaSync SA Connect: mark_searchatlas_nonce_used - Failed to save plugin options');
            } else {
                error_log('MetaSync SA Connect: mark_searchatlas_nonce_used - Options saved successfully');
                if ($status_code === 200) {
                    do_action('metasync_heartbeat_state_key_pending'); // PR3: burst mode
                    $this->trigger_immediate_heartbeat_after_sa_connect();
                }
            }

            error_log('MetaSync SA Connect: mark_searchatlas_nonce_used() completed successfully, returning true');
            return true;
            
        } catch (Exception $e) {
            error_log('MetaSync SA Connect: mark_searchatlas_nonce_used Error - ' . $e->getMessage());
        return false;
        }
    }
    
    /**
     * Clear cached JWT tokens
     * Useful when authentication is reset or API key changes
     */
    private function clear_jwt_token_cache()
    {
        global $wpdb;
        
        // Clear all JWT token transients
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_metasync_jwt_token_%'
            )
        );
        
        // Also clear timeout transients
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_timeout_metasync_jwt_token_%'
            )
        );
        

    }
    
    /**
     * Trigger immediate heartbeat check after successful Search Atlas connect authentication
     * This provides immediate feedback to the user about connection status
     */
    private function trigger_immediate_heartbeat_after_sa_connect()
    {
        try {
            // Use WordPress action system to trigger immediate heartbeat check
            // This is more reliable than trying to access admin class directly
            do_action('metasync_trigger_immediate_heartbeat', 'Search Atlas Connect - API key and UUID retrieved');
            
            // Also ensure heartbeat cron is scheduled now that we have an API key
            do_action('metasync_ensure_heartbeat_cron_scheduled');
            
        } catch (Exception $e) {
            error_log('MetaSync SA Connect: Error triggering immediate heartbeat check - ' . $e->getMessage());
        }
    }

	public function get_item_schema()
	{
		if (isset($this->schema)) {
			// Since WordPress 5.3, the schema can be cached in the $schema property.
			return $this->schema;
		}

		$this->schema = array(
			// This tells the spec of JSON Schema we are using which is draft 4.
			'$schema' => 'http://json-schema.org/draft-04/schema#',
			// The title property marks the identity of the resource.
			'title' => 'post',
			'type' => 'object',
			// In JSON Schema you can specify object properties in the properties attribute.
			'properties' => array(
				'id' => array(
					'description' => esc_html__('Unique identifier for the object.', 'my-textdomain'),
					'type' => 'integer',
					'context' => array('view', 'edit', 'embed'),
					'readonly' => true,
				),
				'content' => array(
					'description' => esc_html__('The content for the object.', 'my-textdomain'),
					'type' => 'string',
				),
			),
		);

		return $this->schema;
	}

	function metasync_wp_robots_meta($robots)
	{
		foreach ($robots as $key => $value) {
			$robots[$key] = false;
		}
		return $robots;
	}

	public function print_metatag($name, $value, $valueAttrib = "content", $nameAttrib = "name", $tagName = "meta")
	{
		if (empty($value))
			return false;

		printf(
			"\t<%s %s=\"%s\" %s=\"%s\" />\n",
			esc_attr($tagName),
			esc_attr($nameAttrib),
			esc_attr($name),
			esc_attr($valueAttrib),
			esc_attr($value)
		);
	}

	/**
	 * Check if the current page is an AMP page
	 * 
	 * @return bool True if AMP page, false otherwise
	 */
	public function is_amp_page()
	{
		// Check if URL path contains /amp/
		$current_url = $_SERVER['REQUEST_URI'] ?? '';
		if (strpos($current_url, '/amp/') !== false) {
			return true;
		}
		
		// Check if URL ends with /amp
		if (preg_match('/\/amp\/?$/', $current_url)) {
			return true;
		}
		
		// Check if amp=1 query parameter is present
		if (isset($_GET['amp']) && $_GET['amp'] == '1') {
			return true;
		}
		
		// Check for other common AMP query parameters
		if (isset($_GET['amp']) && !empty($_GET['amp'])) {
			return true;
		}
		
		return false;
	}

	/**
	 * Remove metasync_optimized attribute from head tag on AMP pages
	 * This function uses output buffering to clean the head content
	 */
	public function cleanup_amp_head_attribute()
	{
		// Only run on AMP pages
		if (!$this->is_amp_page()) {
			return;
		}

		// Start output buffering to capture and modify the head content
		ob_start(function($buffer) {
			// Remove metasync_optimized attribute from head tag
			$cleaned_buffer = preg_replace('/(<head[^>]*)\s*metasync_optimized(?:="[^"]*")?([^>]*>)/i', '$1$2', $buffer);
			
			return $cleaned_buffer;
		});
	}

	/**
	 * End output buffering for AMP cleanup
	 */
	public function end_amp_head_cleanup()
	{
		// Only run on AMP pages
		if (!$this->is_amp_page()) {
			return;
		}

		// End output buffering
		if (ob_get_level()) {
			ob_end_flush();
		}
	}

	public function hook_metasync_metatags()
	{
		$get_page_meta = get_post_meta(get_the_ID());
		$list_page_meta = array(
			'description' => $get_page_meta['meta_description'][0] ?? '',
			'robots' => $get_page_meta['meta_robots'][0] ?? 'index',
		);
		// Note: enable_metadesc is always enabled by default - no check needed

		$getSearchEngineOptions = Metasync::get_option('searchengines');
		$keysSearchEngines = [
			'bing_site_verification' => 'msvalidate.01',
			'baidu_site_verification' => 'baidu-site-verification',
			'alexa_site_verification' => 'alexaVerifyID',
			'yandex_site_verification' => 'yandex-verification',
			'google_site_verification' => 'google-site-verification',
			'pinterest_site_verification' => 'p:domain_verify',
			'norton_save_site_verification' => 'norton-safeweb-site-verification',
		];

		$post = get_post(get_the_ID());
		if (empty($post))
			return;

		# $post_text = wp_trim_words(get_the_content(), 30, '');

		# Check if the post has content, then apply WordPress content filters
		# $post_content = !empty($post->post_content) ? apply_filters('the_content', $post->post_content) : '';
		# $post_text = wp_trim_words($post_content, 30, '');

		/**
		 * Extract post content safely for meta descriptions
		 * This solution addresses three critical issues:
		 * 1. Timber compatibility - works without WordPress loop
		 * 2. Plugin conflicts - prevents shortcode execution (e.g., Hostify-booking function redeclaration)
		 * 3. Performance - lightweight content extraction for meta tags
		 */

		$post_text = '';
		
		# Get content safely without executing shortcodes
		$content = get_the_content(null, false, $post);
		
		# Validate content exists
		if (!empty($content)) {
			# Clean content for meta description - multi-layer approach for reliability
			$content = strip_shortcodes($content);           # Remove shortcode tags (e.g., [hostify-booking])
			$content = wp_strip_all_tags($content);          # Remove HTML tags
			$content = preg_replace('/\s+/', ' ', $content); # Normalize whitespace
			$post_text = wp_trim_words(trim($content), 30, '');
		}

		$site_info = Metasync::get_option('optimal_settings')['site_info'] ?? [];

		$facebook_page_url = Metasync::get_option('social_meta')['facebook_page_url'] ?? '';
		$facebook_authorship = Metasync::get_option('social_meta')['facebook_authorship'] ?? '';
		$facebook_admin = Metasync::get_option('social_meta')['facebook_admin'] ?? '';

		$twitter_username = Metasync::get_option('social_meta')['twitter_username'] ?? '';

		$image = [];
		$image_mime_type = '';

		// SAFE IMAGE HANDLING: Prevents timeout when images are deleted from filesystem
		// Constructs URLs directly from metadata without triggering WordPress HTTP validation
		if ($post) {
			$image_id = get_post_thumbnail_id($post->ID);

			if ($image_id) {
				// Verify attachment exists in database
				$attachment = get_post($image_id);

				if ($attachment && $attachment->post_type === 'attachment') {
					// Check if physical file exists before constructing URL
					$file_path = get_attached_file($image_id);

					if ($file_path && file_exists($file_path)) {
						// Get metadata to construct URL directly
						$metadata = wp_get_attachment_metadata($image_id);

						if ($metadata && !empty($metadata['file'])) {
							$upload_dir = wp_upload_dir();
							$image_url = $upload_dir['baseurl'] . '/' . $metadata['file'];

							// Get image dimensions from metadata (not from file)
							$width = $metadata['width'] ?? 0;
							$height = $metadata['height'] ?? 0;

							// Determine MIME type from file extension (safe, no HTTP calls)
							$file_ext = strtolower(pathinfo($metadata['file'], PATHINFO_EXTENSION));
							$mime_types = [
								'jpg' => 'image/jpeg',
								'jpeg' => 'image/jpeg',
								'png' => 'image/png',
								'gif' => 'image/gif',
								'webp' => 'image/webp',
								'svg' => 'image/svg+xml'
							];
							$image_mime_type = $mime_types[$file_ext] ?? 'image/jpeg';

							// Build image array in same format as wp_get_attachment_image_src
							$image = [$image_url, $width, $height];
						}
					} else {
						// File doesn't exist - clean up orphaned thumbnail reference
						delete_post_meta($post->ID, '_thumbnail_id');
					}
				} else {
					// Attachment doesn't exist - clean up orphaned reference
					delete_post_meta($post->ID, '_thumbnail_id');
				}
			}
		}

		// Fallback to site default image if post has no featured image
		if (empty($image) && $site_info && isset($site_info['social_share_image'])) {
			$fallback_id = $site_info['social_share_image'];
			$attachment = get_post($fallback_id);

			if ($attachment && $attachment->post_type === 'attachment') {
				$file_path = get_attached_file($fallback_id);

				if ($file_path && file_exists($file_path)) {
					$metadata = wp_get_attachment_metadata($fallback_id);

					if ($metadata && !empty($metadata['file'])) {
						$upload_dir = wp_upload_dir();
						$image_url = $upload_dir['baseurl'] . '/' . $metadata['file'];
						$width = $metadata['width'] ?? 0;
						$height = $metadata['height'] ?? 0;

						$file_ext = strtolower(pathinfo($metadata['file'], PATHINFO_EXTENSION));
						$mime_types = [
							'jpg' => 'image/jpeg',
							'jpeg' => 'image/jpeg',
							'png' => 'image/png',
							'gif' => 'image/gif',
							'webp' => 'image/webp',
							'svg' => 'image/svg+xml'
						];
						$image_mime_type = $mime_types[$file_ext] ?? 'image/jpeg';

						$image = [$image_url, $width, $height];
					}
				}
			}
		}


		$ogMetaKeys = [
			'og:locale' => get_locale(),
			'og:type' => 'article',
			'og:title' => $post->post_title . ' - ' . get_bloginfo('name'),
			'og:description' => $post_text ?? '',
			'og:url' => $this->get_canonical_url($post),
			'og:site_name' => get_bloginfo('name'),
			'og:updated_time' => $post->post_modified,
			'og:image' => $image ? $image[0] : '',
			'og:image:width' => $image ? $image[1] : '',
			'og:image:height' => $image ? $image[2] : '',
			'og:image:type' => $image ? $image_mime_type : '',
			'og:image:alt' => $image ? $post->post_title : '',
		];

		$facebookMetaKeys = [
			'article:publisher' => $facebook_page_url && !filter_var($facebook_page_url, FILTER_VALIDATE_URL) ? 'https://' . $facebook_page_url : $facebook_page_url,
			'article:author' => $facebook_authorship && !filter_var($facebook_authorship, FILTER_VALIDATE_URL) ? 'https://' . $facebook_authorship : $facebook_authorship,
			'fb:admins' => $facebook_admin,
		];

		$twitter_card_type = Metasync::get_option('twitter_card_type') ?? [];

		$twitterMetaKeys = [
			'twitter:card' => $twitter_card_type ? $twitter_card_type : 'summary_large_image',
			'twitter:title' => $post->post_title . ' - ' . get_bloginfo('name'),
			'twitter:site' => $twitter_username ? '@' . $twitter_username : '',
			'twitter:creator' => $twitter_username ? '@' . $twitter_username : '',
			'twitter:description' => $post_text ?? '',
			'twitter:image' => $image ? $image[0] : '',
		];

		// echo "\t<!-- MetaSync metadata -->\n";

		foreach ($list_page_meta as $item => $value) {
			if ($item == 'canonical') {
				$this->print_metatag($item, $value, 'href', 'rel', 'link');
				continue;
			}
			$this->print_metatag($item, $value);
		}

		if ($getSearchEngineOptions !== null) { // check if searchengine verification options are set
			foreach ($keysSearchEngines as $optionKey => $metaKey) {
				$this->print_metatag($metaKey, $getSearchEngineOptions[$optionKey]);
			}
		}

		if ($post) {

			$common_meta_settings = Metasync::get_option('common_meta_settings') ?? [];

			if (isset($common_meta_settings['facebook_meta_tags'])) {
				foreach ($facebookMetaKeys as $metaKey => $metaValue) {
					$this->print_metatag($metaKey, $metaValue, 'content', 'property');
				}
			}

			if (isset($common_meta_settings['open_graph_meta_tags'])) {
				foreach ($ogMetaKeys as $metaKey => $metaValue) {
					$this->print_metatag($metaKey, $metaValue, 'content', 'property');
				}
			}
			if (isset($common_meta_settings['twitter_meta_tags'])) {
				foreach ($twitterMetaKeys as $metaKey => $metaValue) {
					$this->print_metatag($metaKey, $metaValue, 'content', 'name');
				}
			}
		}

		$this->facebook_graph_cache();





	}

	public function metasync_plugin_links($links)
	{
		# Removed Sync link as requested
		
		# Solving Issue 103
		# get the neneral options into a var
		$general_options = Metasync::get_option('general');

		# set the menu slug to the default 
		$menu_slug = 'searchatlas';

		#now check if the menu slug is in the general opts
		if (is_array($general_options) && !empty($general_options['white_label_plugin_menu_slug'])) {
			
			#set the slug to the modified
			$menu_slug = $general_options['white_label_plugin_menu_slug'];
		}

		$links[] = '<a href="' . get_admin_url(null, 'admin.php?page=' .$menu_slug) . '">' . esc_html__('Settings', 'metasync') . '</a>';
		return $links;
	}

	public function add_ld_json()
	{
		$post = get_post(get_the_ID());
		if (empty($post))
			return;

		$site_info = Metasync::get_option('optimal_settings')['site_info'] ?? '';

		$site_logo_id = $site_info['google_logo'] ?? '';
		$custom_logo_id = get_theme_mod('custom_logo');
		$logo_id = $custom_logo_id != '' ? $custom_logo_id : $site_logo_id;

		$site_image_id = $site_info['social_share_image'] ?? '';
		$post_thumbnail_id = get_post_thumbnail_id($post->ID);
		$thumbnail_id = $post_thumbnail_id > 0 ? $post_thumbnail_id : $site_image_id;

		$schema = array(
			'@context' => "http://schema.org",
			'@type' => "Article",
			'headline' => str_replace($this->escapers, $this->replacements, $post->post_title ?? ''),
			'image' => wp_get_attachment_image_url($thumbnail_id, 'full'),
			'url' => get_permalink(),
			'datePublished' => $post->post_modified,
			'author' => array(
				'@type' => "Person",
				'name' => get_the_author_meta('display_name', $post->post_author),
				'url' => get_author_posts_url($post->post_author),
			),
			'publisher' => array(
				'@type' => "Organization",
				'name' => str_replace($this->escapers, $this->replacements, get_bloginfo('name') ?? ''),
				'url' => get_site_url(),
				'logo' => array(
					'@type' => "ImageObject",
					'url' => wp_get_attachment_image_url($logo_id, 'full'),
				)
			)
		);

		return $schema;
	}

	public function facebook_graph_cache()
	{
		$facebook_app = Metasync::get_option('social_meta')['facebook_app'] ?? '';
		$facebook_secret = Metasync::get_option('social_meta')['facebook_secret'] ?? '';

		// Early bail!
		if (!$facebook_app || !$facebook_secret) {
			return;
		}

		# PERFORMANCE OPTIMIZATION: Add timeout and error handling
		$response = wp_remote_post(
			'https://graph.facebook.com/',
			[
				'body' => [
					'id' => $facebook_app,
					'access_token' => $facebook_secret,
				],
				'timeout' => 5,
			]
		);

		# Error handling (fail silently, not critical)
		if (is_wp_error($response)) {
			error_log('MetaSync: Facebook Graph API failed: ' . $response->get_error_message());
		}
	}
	// Callback function to retrieve pages tree
	public function get_pages_list($data) {
		$post_type = $data['post_type'];
	
		// Fetch the top-level posts or pages
		$query = new WP_Query(array(
			'post_type' => $post_type,				
			'post_status' => array('publish', 'draft'),	
			'order' => 'ASC',
			'posts_per_page' => -1,
		));
	
		$posts_array = array();
		
		// Build the array of posts
		while ($query->have_posts()) {
			$query->the_post();
			$posts_array[] = array(
				'id' => get_the_ID(),
				'title' => get_the_title(),
				'parent' => wp_get_post_parent_id(get_the_ID()),
			);
		}
		
		// Reset post data
		wp_reset_postdata();		
		return new WP_REST_Response($posts_array, 200);
	}

	/*
	* Get post title and post feature image setting
	* Add a New key to return value on the basis of post type
	*/
	public function append_content_if_missing_elements($post_type) {

		# Run the MetaSyncHiddenPostManager folder
		# apply_filters('metasync_hidden_post_manager', '');
		# Get Latest Metasync Option
		$metasyncData = Metasync::get_option();

		# Default value for post title setting
		$title_in_headings = true;	

		# Default value for post feature image setting
		$image_in_content = true;

		# Check if the title setting is added in the setting
		if(isset($metasyncData['general']['title_in_headings'])){

			# Change the default value from the setting
			$title_in_headings = $metasyncData['general']['title_in_headings'][$post_type];
				
		}

		# Check if the post feature setting is added in the setting
		if (isset($metasyncData['general']['image_in_content'])) {

			# Change the default value from the setting
			$image_in_content = $metasyncData['general']['image_in_content'][$post_type];;

		}
			# Return the array of setting
			return array(
				'title_in_headings'=>$title_in_headings,
				'image_in_content'=>$image_in_content
			);

		
	}

	/*
	* This will hide the title on single posts and pages
	* if they were created with the "metasync" system.
	* Passes default values for $title and $id to avoid errors.
	*/
	public function hide_title_on_otto_pages($title = '', $id = null){
		
		# Return title immediately if $id or $title is not provided
		if (empty($id) || empty($title)) {
			return $title;
		}

		# Check if it's a single post or page
		if ((is_single() || is_page()) && in_the_loop() && is_main_query()) {

			# Check if the post was created with the "metasync" system
			$metasync_post = get_post_meta($id, 'metasync_post', true);
			if ($metasync_post === 'yes') {
				return '';
			}
		}
		return $title;
	}

	/**
	 * Get the canonical URL for a post
	 */
	private function get_canonical_url($post) {
		# Try to get the permalink using WordPress function
		$permalink = get_permalink($post->ID);

		# If permalink is not available or is the default query URL, try alternative methods
		if (!$permalink || strpos($permalink, '?p=') !== false || strpos($permalink, '?page_id=') !== false) {
			# Force WordPress to generate the proper permalink by temporarily setting post status
			$original_status = $post->post_status;
			if ($post->post_status === 'auto-draft') {
				$post->post_status = 'publish';
			}

			# Try get_permalink again with the updated status
			$permalink = get_permalink($post->ID);

			# Restore original status
			$post->post_status = $original_status;
		}

		# If still not working, use WordPress core functions to build proper permalink
		if (!$permalink || strpos($permalink, '?p=') !== false || strpos($permalink, '?page_id=') !== false) {
			# Use WordPress core function that respects permalink structure
			# This properly handles custom structures, hierarchies, and post types
			# Load admin function if not already available, Without this it is causing error on post the preview page
			if (!function_exists('get_sample_permalink')) {
				require_once ABSPATH . 'wp-admin/includes/post.php';
			}
			$permalink = get_sample_permalink($post->ID);

			if (is_array($permalink)) {
				# get_sample_permalink returns array with template and slug
				# Replace %postname% or %pagename% with actual slug
				$permalink = str_replace(
					array('%pagename%', '%postname%'),
					$post->post_name,
					$permalink[0]
				);
			}

			# Final fallback: if still problematic, construct URL respecting post type structure
			if (!$permalink || strpos($permalink, '?p=') !== false || strpos($permalink, '?page_id=') !== false) {
				if (!empty($post->post_name)) {
					# For pages, check if there's a parent hierarchy
					if ($post->post_type === 'page' && $post->post_parent) {
						# Get parent page path for proper hierarchy
						$parent = get_post($post->post_parent);
						$parent_path = '';

						# Build full path including all parent pages
						while ($parent) {
							$parent_path = $parent->post_name . '/' . $parent_path;
							$parent = $parent->post_parent ? get_post($parent->post_parent) : null;
						}

						$permalink = home_url('/' . $parent_path . $post->post_name . '/');
					} else {
						# For posts and pages without parents, use post type archive base
						$post_type_obj = get_post_type_object($post->post_type);
						$slug = $post_type_obj->rewrite['slug'] ?? '';

						if ($slug && $post->post_type !== 'page') {
							$permalink = home_url('/' . $slug . '/' . $post->post_name . '/');
						} else {
							$permalink = home_url('/' . $post->post_name . '/');
						}
					}
				} else {
					# Fallback to post ID format if no slug available
					$permalink = home_url('/?p=' . $post->ID);
				}
			}
		}

		return $permalink;
	}

	/**
	 * Create key file endpoint for Bing Webmaster Tools. It's called by OTTO/UCMS.
	 * Creates a .txt file in WordPress root with the provided key as filename and content
	 * 
	 * @param WP_REST_Request $request The REST request object
	 * @return WP_REST_Response|WP_Error Response object
	 */
	public function create_key_file($request) {
		# Get the JSON data from the request
		$data = $request->get_json_params();
		
		# Try alternative parameter methods
		$body_params = $request->get_body_params();
		$key_param = $request->get_param('key');
		$post_key = $_POST['key'] ?? null;
		$request_key = $_REQUEST['key'] ?? null;
		$get_key = $_GET['key'] ?? null;
		
		# Try to get key from multiple sources
		$key_value = null;
		
		# Try JSON first
		if (!empty($data['key'])) {
			$key_value = $data['key'];
		}
		# Try body params (form data)
		elseif (!empty($body_params['key'])) {
			$key_value = $body_params['key'];
		}
		# Try direct parameter
		elseif (!empty($key_param)) {
			$key_value = $key_param;
		}
		# Try $_POST (for multipart/form-data)
		elseif (!empty($post_key)) {
			$key_value = $post_key;
		}
		# Try $_REQUEST (fallback)
		elseif (!empty($request_key)) {
			$key_value = $request_key;
		}
		# Try $_GET (query parameters)
		elseif (!empty($get_key)) {
			$key_value = $get_key;
		}
		
		# Validate that key is provided
		if (empty($key_value)) {
			return rest_ensure_response(array(
				'error' => 'Key parameter is required',
				'code' => 'missing_key'
			), 400);
		}
		
		# Use the found key value
		$data['key'] = $key_value;
		
		# Sanitize the key to ensure it's safe for filename
		$key = sanitize_file_name($data['key']);
		
		# Validate key is not empty after sanitization
		if (empty($key)) {
			return rest_ensure_response(array(
				'error' => 'Invalid key provided',
				'code' => 'invalid_key'
			), 400);
		}
		
		# Get WordPress root directory
		$wp_root = ABSPATH;
		
		# Construct the file path
		$file_path = $wp_root . $key . '.txt';
		
		# Check if file already exists
		if (file_exists($file_path)) {
			return rest_ensure_response(array(
				'error' => 'File already exists',
				'code' => 'file_exists',
				'file_path' => $file_path
			), 409);
		}
		
		# Attempt to create the file
		$result = file_put_contents($file_path, $key);
		
		# Check if file creation was successful
		if ($result === false) {
			return rest_ensure_response(array(
				'error' => 'Failed to create file',
				'code' => 'file_creation_failed',
				'file_path' => $file_path
			), 500);
		}
		
		# Return success response
		return rest_ensure_response(array(
			'success' => true,
			'message' => 'Key file created successfully',
			'file_path' => $file_path,
			'key' => $key,
			'file_size' => $result
		), 200);
	}

	/**
	 * Inject noindex meta tag for archive pages based on indexation control settings
	 * 
	 * @since 1.0.0
	 */
	/**
	 * Set up indexation controls for archive pages
	 * 
	 * Called via template_redirect hook to set up early before any output.
	 * This ensures we can capture and clean robots tags from other plugins.
	 * 
	 * Logic:
	 * 1. If user wants to add noindex - always do it (override other plugins)
	 * 2. If user wants to allow indexing - only remove other plugins' tags if override setting is enabled
	 * 
	 * @since 1.0.0
	 */
	public function inject_archive_seo_controls() {
		// Check if we're on a managed archive type
		if (!$this->is_managed_archive()) {
			return;
		}
		
		// Get settings
		$seo_controls = Metasync::get_option('seo_controls', array());
		$should_noindex = $this->should_noindex_archive();
		$override_enabled = ($seo_controls['override_robots_tags'] ?? 'false') === 'true' || ($seo_controls['override_robots_tags'] ?? false) === true;
		
		// Run buffer if either:
		// 1. User wants to add noindex (always override other plugins), OR
		// 2. User wants to allow indexing AND override setting is enabled
		if ($should_noindex || $override_enabled) {
			// Override WordPress core robots tag
			add_filter('wp_robots', array($this, 'override_wp_robots'), 999);
			
			// Start buffering to remove other plugins' robots tags
			add_action('wp_head', array($this, 'start_robots_buffer'), 0);
			add_action('wp_head', array($this, 'end_robots_buffer'), PHP_INT_MAX);
		}
		// Otherwise: User wants to allow indexing AND override is disabled - don't interfere
	}
	
	/**
	 * Check if current page is a managed archive type
	 * 
	 * Returns true if we're on any archive type that has indexation controls,
	 * regardless of whether noindex is enabled or not.
	 * 
	 * @since 1.0.0
	 * @return bool True if on a managed archive type, false otherwise
	 */
	private function is_managed_archive() {
		return is_date() || is_tag() || is_author() || is_category() || is_tax('post_format');
	}
	
	/**
	 * Check if current archive is empty (has no posts)
	 * 
	 * Applies to category, tag, author, and post format archives.
	 * Date archives are excluded as they're typically either indexed
	 * or not entirely (less meaningful to check for empty state).
	 * 
	 * @since 1.0.0
	 * @return bool True if archive is empty (0 posts)
	 */
	private function is_empty_archive() {
		global $wp_query;
		
		// Check taxonomy and author archives (excluding date archives)
		if (!is_category() && !is_tag() && !is_author() && !is_tax('post_format')) {
			return false;
		}
		
		// Check if the archive has no posts
		return $wp_query->post_count === 0;
	}
	
	/**
	 * Check if current archive should be noindexed
	 * 
	 * @since 1.0.0
	 * @return bool True if should add noindex, false otherwise
	 */
	private function should_noindex_archive() {
		// Get indexation control settings
		$seo_controls = Metasync::get_option('seo_controls', array());
		
		// Check empty archives setting first (applies to categories/tags only)
		$noindex_empty_archives = $seo_controls['noindex_empty_archives'] ?? false;
		if (($noindex_empty_archives === 'true' || $noindex_empty_archives === true) && $this->is_empty_archive()) {
			return true;
		}
		
		// Date Archives
		if (is_date()) {
			$index_date_archives = $seo_controls['index_date_archives'] ?? false;
			// Handle both string and boolean values for compatibility
			if ($index_date_archives === 'true' || $index_date_archives === true) {
				return true;
			}
		}
		
		// Tag Archives
		elseif (is_tag()) {
			$index_tag_archives = $seo_controls['index_tag_archives'] ?? false;
			// Handle both string and boolean values for compatibility
			if ($index_tag_archives === 'true' || $index_tag_archives === true) {
				return true;
			}
		}
	
		// Author Archives
		elseif (is_author()) {
			$index_author_archives = $seo_controls['index_author_archives'] ?? false;
			// Handle both string and boolean values for compatibility
			if ($index_author_archives === 'true' || $index_author_archives === true) {
				return true;
			}
		}
		
		// Category Archives
		elseif (is_category()) {
			$index_category_archives = $seo_controls['index_category_archives'] ?? false;
			// Handle both string and boolean values for compatibility
			if ($index_category_archives === 'true' || $index_category_archives === true) {
				return true;
			}
		}
		
		// Format Archives (post format taxonomy)
		elseif (is_tax('post_format')) {
			$index_format_archives = $seo_controls['index_format_archives'] ?? false;
			if ($index_format_archives === 'true' || $index_format_archives === true) {
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * Override WordPress core robots directives
	 * 
	 * Handles both cases:
	 * 1. When noindex is enabled: Add noindex directive
	 * 2. When indexing is allowed: Return empty array (removes WP core robots tag)
	 * 
	 * @since 1.0.0
	 * @param array $robots Associative array of robots directives
	 * @return array Modified robots directives
	 */
	public function override_wp_robots($robots) {
		if ($this->should_noindex_archive()) {
			// Case 1: Add noindex directive
			return array(
				'noindex' => true,
				'follow' => true,
				'max-image-preview' => 'large', // Maintain good image preview for social sharing
			);
		} else {
			// Case 2: Allow indexing - return empty array to remove WP core robots tag
			// Default behavior without robots tag is to index
			return array();
		}
	}
	
	/**
	 * Start output buffering to capture robots meta tags from other plugins
	 * 
	 * @since 1.0.0
	 */
	public function start_robots_buffer() {
		ob_start();
	}
	
	/**
	 * End output buffering, remove existing robots tags, and optionally add ours
	 * 
	 * This handles BOTH cases:
	 * 1. When noindex is enabled: Remove other tags and add noindex tag
	 * 2. When indexing is allowed: Remove other plugins' noindex tags
	 * 
	 * This ensures our indexation settings always take precedence over other plugins.
	 * 
	 * @since 1.0.0
	 */
	public function end_robots_buffer() {
		// Only proceed if we're on a managed archive
		if (!$this->is_managed_archive()) {
			ob_end_flush();
			return;
		}
		
		// Get buffered content
		$content = ob_get_clean();
		
		// Remove all existing robots meta tags (handles various formats)
		// This is done for BOTH noindex and index cases to ensure clean slate
		
		// Pattern 1: name="robots" content="..."
		$content = preg_replace(
			'/<meta\s+name=["\']robots["\']\s+content=["\'][^"\']*["\']\s*\/?>\s*/i',
			'',
			$content
		);
		
		// Pattern 2: content="..." name="robots"
		$content = preg_replace(
			'/<meta\s+content=["\'][^"\']*["\']\s+name=["\']robots["\']\s*\/?>\s*/i',
			'',
			$content
		);
		
		// Pattern 3: Single quotes or no quotes (rare but possible)
		$content = preg_replace(
			"/<meta\s+name='robots'\s+content='[^']*'\s*\/?>\s*/i",
			'',
			$content
		);
		
		// Pattern 4: property="robots" (some plugins use property attribute)
		$content = preg_replace(
			'/<meta\s+property=["\']robots["\']\s+content=["\'][^"\']*["\']\s*\/?>\s*/i',
			'',
			$content
		);
		
		// Pattern 5: Search engine specific directives (googlebot, bingbot, etc.)
		$content = preg_replace(
			'/<meta\s+name=["\'](?:googlebot|bingbot|googlebot-news|slurp)["\']\s+content=["\'][^"\']*["\']\s*\/?>\s*/i',
			'',
			$content
		);
		
		// Output cleaned content
		echo $content;
		
		// Add our robots tag or comment based on settings
		if ($this->should_noindex_archive()) {
			// Case 1: User wants to disallow indexing - add noindex tag
			echo '<!-- MetaSync Indexation Control: Noindex Applied (Overriding other plugins) -->' . "\n";
			echo '<meta name="robots" content="noindex, follow">' . "\n";
		} else {
			// Case 2: User wants to allow indexing AND override is enabled
			// Just remove other plugins' tags, don't add our own
			// Default behavior without robots tag is to allow indexing
			echo '<!-- MetaSync Indexation Control: Index Allowed (Override enabled - Other noindex tags removed) -->' . "\n";
		}
	}

	/**
	 * Filter taxonomy sitemap entries to exclude disabled archive types
	 * 
	 * @since 1.0.0
	 * @param array $taxonomies Array of taxonomy objects
	 * @return array Modified array of taxonomy objects
	 */
	public function filter_sitemap_taxonomies($taxonomies) {
		// Get indexation control settings
		$seo_controls = Metasync::get_option('seo_controls', array());
		
		// Check if tag archives are disabled
		$index_tag_archives = $seo_controls['index_tag_archives'] ?? false;
		if ($index_tag_archives === 'true' || $index_tag_archives === true) {
			// Remove post_tag taxonomy from sitemap
			unset($taxonomies['post_tag']);
		}
		
		// Check if category archives are disabled
		$index_category_archives = $seo_controls['index_category_archives'] ?? false;
		if ($index_category_archives === 'true' || $index_category_archives === true) {
			// Remove category taxonomy from sitemap
			unset($taxonomies['category']);
		}
		
		// Check if format archives are disabled
		$index_format_archives = $seo_controls['index_format_archives'] ?? false;
		if ($index_format_archives === 'true' || $index_format_archives === true) {
			// Remove post_format taxonomy from sitemap
			unset($taxonomies['post_format']);
		}
		
		return $taxonomies;
	}

	/**
	 * Filter user sitemap entries to exclude disabled author archives
	 * 
	 * @since 1.0.0
	 * @param array $entry Sitemap entry for user
	 * @param WP_User $user User object
	 * @return array|false Modified sitemap entry or false to exclude
	 */
	public function filter_sitemap_users($entry, $user) {
		// Get indexation control settings
		$seo_controls = Metasync::get_option('seo_controls', array());
		
		// Check if author archives are disabled
		$index_author_archives = $seo_controls['index_author_archives'] ?? false;
		if ($index_author_archives === 'true' || $index_author_archives === true) {
			// Exclude this user from sitemap
			return false;
		}
		
		return $entry;
	}

	/**
	 * Filter sitemap providers to exclude disabled archive types
	 * 
	 * @since 1.0.0
	 * @param bool $provider Whether to add the provider
	 * @param string $name Provider name
	 * @return bool Whether to add the provider
	 */
	public function filter_sitemap_providers($provider, $name) {
		// Get indexation control settings
		$seo_controls = Metasync::get_option('seo_controls', array());
		
		// Check if users/authors sitemap provider should be disabled
		if ($name === 'users') {
			$index_author_archives = $seo_controls['index_author_archives'] ?? false;
			if ($index_author_archives === 'true' || $index_author_archives === true) {
				return false;  // Exclude users sitemap provider entirely
			}
		}
		
		return $provider;
	}

	/**
	 * Filter sitemap index entries to exclude disabled archive types
	 * 
	 * @since 1.0.0
	 * @param array $sitemap_entry Sitemap entry array
	 * @param string $object_type Object type (posts, taxonomies, users)
	 * @param string $subtype Subtype (post type or taxonomy name)
	 * @param int $page Page number
	 * @return array|false Modified sitemap entry or false to exclude
	 */
	public function filter_sitemap_index_entries($sitemap_entry, $object_type, $subtype, $page) {
		// Get indexation control settings
		$seo_controls = Metasync::get_option('seo_controls', array());
		
		// Handle taxonomy sitemaps
		if ($object_type === 'taxonomies') {
			// Check if tag archives are disabled
			if ($subtype === 'post_tag') {
				$index_tag_archives = $seo_controls['index_tag_archives'] ?? false;
				if ($index_tag_archives === 'true' || $index_tag_archives === true) {
					return false; // Exclude from sitemap index
				}
			}
			
			// Check if category archives are disabled
			if ($subtype === 'category') {
				$index_category_archives = $seo_controls['index_category_archives'] ?? false;
				if ($index_category_archives === 'true' || $index_category_archives === true) {
					return false; // Exclude from sitemap index
				}
			}
			
			// Check if format archives are disabled
			if ($subtype === 'post_format') {
				$index_format_archives = $seo_controls['index_format_archives'] ?? false;
				if ($index_format_archives === 'true' || $index_format_archives === true) {
					return false; // Exclude from sitemap index
				}
			}
		}
		
		// Handle user/author sitemaps
		if ($object_type === 'users') {
			$index_author_archives = $seo_controls['index_author_archives'] ?? false;
			if ($index_author_archives === 'true' || $index_author_archives === true) {
				return false; // Exclude from sitemap index
			}
		}
		
		return $sitemap_entry;
	}

	/**
	 * Enqueue custom CSS stored in post meta for converted pages.
	 * Runs at priority 999 (after theme CSS) so custom styles win.
	 */
	public function enqueue_page_custom_css() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$custom_css = get_post_meta( $post_id, '_metasync_custom_css', true );
		if ( empty( $custom_css ) ) {
			return;
		}

		$scoped_css = $this->scope_css_to_body_class( $custom_css, $post_id );

		wp_register_style( 'metasync-page-css-' . $post_id, false );
		wp_enqueue_style( 'metasync-page-css-' . $post_id );
		wp_add_inline_style( 'metasync-page-css-' . $post_id, $scoped_css );
	}

	/**
	 * Enqueue custom CSS into Elementor editor preview iframe.
	 */
	public function enqueue_elementor_editor_css() {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$custom_css = get_post_meta( $post_id, '_metasync_custom_css', true );
		if ( empty( $custom_css ) ) {
			return;
		}

		$scoped_css = $this->scope_css_to_body_class( $custom_css, $post_id );

		wp_register_style( 'metasync-elementor-css-' . $post_id, false );
		wp_enqueue_style( 'metasync-elementor-css-' . $post_id );
		wp_add_inline_style( 'metasync-elementor-css-' . $post_id, $scoped_css );
	}

	/**
	 * Enqueue custom CSS when Divi Visual Builder is active.
	 */
	public function enqueue_divi_builder_css() {
		if ( empty( $_GET['et_fb'] ) ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$custom_css = get_post_meta( $post_id, '_metasync_custom_css', true );
		if ( empty( $custom_css ) ) {
			return;
		}

		$scoped_css = $this->scope_css_to_body_class( $custom_css, $post_id );

		wp_register_style( 'metasync-divi-css-' . $post_id, false );
		wp_enqueue_style( 'metasync-divi-css-' . $post_id );
		wp_add_inline_style( 'metasync-divi-css-' . $post_id, $scoped_css );
	}

	/**
	 * Scope all CSS selectors to body.postid-{ID} for higher specificity.
	 *
	 * @param string $css     Raw CSS.
	 * @param int    $post_id Post ID.
	 * @return string Scoped CSS.
	 */
	private function scope_css_to_body_class( $css, $post_id ) {
		$prefix  = 'body.postid-' . intval( $post_id );
		$scoped  = '';
		$pattern = '/([^{]+)\{([^}]*)\}/s';

		preg_match_all( $pattern, $css, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$selectors        = array_map( 'trim', explode( ',', $match[1] ) );
			$scoped_selectors = array_map( function( $sel ) use ( $prefix ) {
				if ( empty( $sel ) || strpos( $sel, '@' ) === 0 ) {
					return $sel;
				}
				return $prefix . ' ' . $sel;
			}, $selectors );
			$scoped .= implode( ', ', $scoped_selectors ) . ' {' . $match[2] . "}\n";
		}

		return $scoped ?: $css;
	}

}


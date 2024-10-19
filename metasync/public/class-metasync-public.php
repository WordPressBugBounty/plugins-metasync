<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @link       http://linkgraph.io
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
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
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
		add_action('wp_ajax_metasyn_otto_ajax_action', array($this,'metasyn_otto_ajax'));
		//add_action('wp_ajax_nopriv_metasyn_otto_ajax_action', array($this,'metasyn_otto_ajax'));
		//add_action('wp_head', array($this,'otto_header_data'));
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

		wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/metasync-public.js', array('jquery'), $this->version, false);
		// wp_enqueue_script($this->plugin_name.'-otto', plugin_dir_url(__FILE__) . 'js/metasync-otto.js', array('jquery'), $this->version, false);
		// wp_localize_script($this->plugin_name.'-otto', 'otto_ajax_object', array(
		// 	'ajax_url' => admin_url('admin-ajax.php'),
		// 	'nonce'    => wp_create_nonce('otto_nonce'),
		// 	'post_id' => get_the_ID()
		// ));
	}
	public function metasyn_otto_ajax() {
		// Check nonce for security
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'otto_nonce')) {
			wp_send_json_error('Invalid nonce');
			wp_die();
		}
		$current_url = get_permalink($_POST['post_id']);
		
		$header_html = get_post_meta($_POST['post_id'], '_otto_header_html_json', true);

		// Example API call using wp_remote_get()
		
	
		if (is_wp_error($response)) {
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
	
			// Call the API
			$response = wp_remote_get('https://sa.searchatlas.com/api/v2/otto-url-details/?url=' . $current_url);

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
	
		// Display the HTML
		if ($header_html) {
			echo "<!-- Otto Start -->";
			echo $header_html;
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
		add_shortcode('accordion', 'metasync_accordion');
		// add_shortcode('markdown', 'metasync_markdown');

		// function metasync_markdown($atts, $content = "")
		// {
		// 	$parsedown = new Parsedown();
		// 	$strip_tags_content = wp_strip_all_tags($content);
		// 	$markdown_content = $parsedown->text($strip_tags_content);
		// 	return $markdown_content;
		// }

		function metasync_accordion($atts, $content = "")
		{
			$block = "<div class=\"metasync-accordion-block\">
				<button class=\"metasync-accordion\">{$atts['title']}</button>
				<div class=\"metasync-panel\">$content</div>
				</div>";
			return $block;
		}
	}

	public function rest_authorization_middleware()
	{
		$get_data = sanitize_post($_GET);
		if (!isset($get_data['apikey']))
			return false;
		$apiKey = sanitize_text_field($get_data['apikey']) ?? null;

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
						$getPostID = url_to_postid($_GET['url']);
						
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
			'addSchema',
			array(
				array(
					'methods' => 'POST',
					'callback' => array($this, 'add_schema'),
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
		
		register_rest_route($this::namespace, '/pageList', array(
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


	// public function get_item($request, $type = 'post')
	// {
	// 	$id = (int) $request['id'];
	// 	$post = get_post($id);

	// 	if (empty($post)) {
	// 		return new WP_Error(
	// 			'rest_post_not_found',
	// 			__("Post not found"),
	// 			array('status' => 400)
	// 		);
	// 	}

	// 	$response = $this->filter_post_attributes([$post]);
	// 	return $response;
	// }

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
				$result['settings']['title_color'] = '#000000'; // Set default title color
				$result['settings']['typography_typography'] = 'custom';
				$result['settings']['typography_font_family'] = 'Roboto';
				$result['settings']['typography_font_weight'] = '600';
				$result['widgetType'] = 'heading';
			}elseif($node->nodeName==='iframe'){  // Correction in the name 
				$result["settings"]= array('html'=> $node->ownerDocument->saveHTML($node));
				$result['widgetType'] = 'html';
			}elseif ($node->nodeName === 'img') {
				// Handle image elements
				$result['settings']['image']['url'] = $node->getAttribute('src');
				$image_id = attachment_url_to_postid($node->getAttribute('src') );
				$image_alt = get_post_meta($image_id, '_wp_attachment_image_alt', TRUE);
				$result['settings']['image']['id'] =$image_id; // Generate unique ID for the image
				$result['settings']['image']['size'] = '';
				$result['settings']['image']['alt'] = $image_alt; // Set default alt text
				$result['settings']['image']['source'] = 'library';
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
			} 

			if(isset($result['widgetType'])){
				return $result;
			}
			
		}
	}
	private function elementorBlockData($content){
		$dom = new DOMDocument();
		// Load HTML string
		@$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));		
		$outputArray = [];
		foreach ( $dom->getElementsByTagName('*') as $rootElement) {	
			if($rootElement->nodeName!=='html' && $rootElement->nodeName!=='body' &&
			$rootElement->nodeName!=='tbody'&& $rootElement->nodeName!=='tfoot' && $rootElement->nodeName!=='tr' && $rootElement->nodeName!=='th' && $rootElement->nodeName!=='td'){
			$htmlArray = $this->htmlToElementorBlock($rootElement);
			$outputArray[] = $htmlArray;
			}		
		}
		return $outputArray;
	}

	private function gutenbergBlockData($content) {
	
		$dom = new DOMDocument();
		// Load HTML string
		@$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
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
		$nodeName = $node->nodeName;
	
		$result = [];

		if ($node->nodeType === XML_TEXT_NODE) {
			// Text node
			return $node->nodeValue;
		} else{
			if (in_array(strtolower($node->nodeName), array('h1', 'h2', 'h3', 'h4', 'h5','h6'))) {
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
			} elseif ($node->nodeName === 'img') {			
				$src_url = $node->getAttribute('src');
			
					$attachment_id = $this->common->upload_image_by_url($node->getAttribute('src'),$node->getAttribute('alt'));
					$src_url = wp_get_attachment_url($attachment_id);
				
				$node->setAttribute('alt', $node->getAttribute('alt'));
				$node->setAttribute('src', $src_url);
				$node->setAttribute('class', "wp-image-".$attachment_id);
				
				return [
					"blockName" => "core/image",
					"attrs" => [
						"id" => $attachment_id ,
						"sizeSlug" => "large",
						"linkDestination" => "none"
					],
					"innerBlocks" => [],
					"innerHTML" => '<figure class="wp-block-image size-large">'.$node->ownerDocument->saveHTML($node) .'</figure>' ,
					"innerContent" => [
						'<figure class="wp-block-image size-large">'.$node->ownerDocument->saveHTML($node) .'</figure>'
					]
				];
			}elseif ($node->nodeName === 'iframe') {
				return [
					"blockName" => "core/html",
					"attrs" => [],
					"innerBlocks" => [],
					"innerHTML" => $node->ownerDocument->saveHTML($node) ,
					"innerContent" => [
						$node->ownerDocument->saveHTML($node) 
					]
				];
			}elseif ($node->nodeName === 'p') {
				return [
					"blockName" => "core/paragraph",
					"attrs" => [],
					"innerBlocks" => [],
					"innerHTML" => $node->ownerDocument->saveHTML($node) ,
					"innerContent" => [
						$node->ownerDocument->saveHTML($node) 
					]
				];
			} elseif ($node->nodeName === 'table') {
				$tableHtml = $node->ownerDocument->saveHTML($node);
				if($node->nodeName === 'table'){
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
					
			}elseif($node->nodeName === 'ol'||$node->nodeName === 'ul'){
				//list-item
				return [
					"blockName" => "core/list",
					"attrs" => [
						'ordered'=> ($node->nodeName === 'ol'?true:false)
					],
					"innerBlocks" => [],
					"innerHTML" => $node->ownerDocument->saveHTML($node) ,
					"innerContent" => [
						$node->ownerDocument->saveHTML($node) 
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
				// Handle heading elements	
				$result =' [et_pb_heading title="'.$node->nodeValue.'" _builder_version="'.ET_BUILDER_VERSION.'" _module_preset="default" title_level="'.$node->nodeName.'" hover_enabled="0" sticky_enabled="0"][/et_pb_heading]';
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
			} 			
			return $result;		
		}
	}
	private function diviBlockData($content){
		$dom = new DOMDocument();
		// Load HTML string
		@$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));	
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
	
	private function metasync_upload_post_content($item)
	{
		$post_content = new DOMDocument();
		$internalErrors = libxml_use_internal_errors(true);
		@$post_content->loadHTML(mb_convert_encoding($item['post_content'], 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NODEFDTD);
		libxml_use_internal_errors($internalErrors);
		$images = $post_content->getElementsByTagName('img');
		$enabled_plugin_editor = Metasync::get_option('general')['enabled_plugin_editor'] ?? '';
		$elementor_active = did_action( 'elementor/loaded' );
        $divi_active = (wp_get_theme()->name == 'Divi'?true:false);
		if(!$elementor_active && $enabled_plugin_editor=='elementor'){
			$enabled_plugin_editor='';
		}
		if(!$divi_active && $enabled_plugin_editor=='divi'){
			$enabled_plugin_editor='';
		}
		if($enabled_plugin_editor!=='elementor' || $enabled_plugin_editor!=='elementor'){
			foreach ($images as $image) {
				$src_url = $image->getAttribute('src');
				// if ($this->common->allowedDownloadSources($src_url) === true) {
					$attachment_id = $this->common->upload_image_by_url($image->getAttribute('src'),$image->getAttribute('alt'));
					$src_url = wp_get_attachment_url($attachment_id);
				// }
				$image->setAttribute('src', $src_url);
			}
		}

		$content = $post_content->saveHTML();
		$content = trim(str_replace([
			'<html>',
			'</html>',
			'<head>',
			'</head>',
			'<body>',
			'</body>'
		], '', $content));
		if($enabled_plugin_editor=='elementor'){
			// Load HTML string into DOMDocument
			$dom = new DOMDocument();
			libxml_use_internal_errors(true); // Suppress errors
			@$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
			libxml_clear_errors(); // Clear any errors
			// Find all <figure> elements and remove them
			// $figureElements = $dom->getElementsByTagName('figure');
			// foreach ($figureElements as $figureElement) {
			// 	$figureElement->parentNode->removeChild($figureElement);
			// }
			$modifiedHtml = $dom->saveHTML();
			$outputArrayData = [
				[
					'id' => uniqid(), 
					'elType' => 'container',
					'settings' => [
						'flex_direction' => 'column',
						'presetTitle' => 'Container',
						'presetIcon' => 'eicon-container'
					],
					'elements' =>$this->elementorBlockData(html_entity_decode($modifiedHtml)),
					'isInner' => false
				]
			];
			//error_log(json_encode([$item['post_content'],$outputArrayData,html_entity_decode($content)]));
		
			$jsonOutput = wp_slash( wp_json_encode( $outputArrayData ) );

			return array(
				'content'=>$content,
				'elementor_meta_data'=>array(
					'_elementor_data'=>$jsonOutput,
					'_elementor_edit_mode'=>'builder',
					'_elementor_version'=>ELEMENTOR_VERSION
				)
			);			
		}else if($enabled_plugin_editor=='gutenberg'){
			$blockGutenberg = $this->gutenbergBlockData($content);
			$serializedBlocks = serialize_blocks($blockGutenberg);
			return array('content'=>$serializedBlocks);
		}else if($enabled_plugin_editor=='divi'){
			$dom = new DOMDocument();
			libxml_use_internal_errors(true); // Suppress errors
			@$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
			libxml_clear_errors(); // Clear any errors
			// Find all <figure> elements and remove them
			// $figureElements = $dom->getElementsByTagName('figure');
			// foreach ($figureElements as $figureElement) {
			// 	$figureElement->parentNode->removeChild($figureElement);
			// }
			$modifiedHtml = $dom->saveHTML();
			return array(
				'content'=>$this->diviBlockData(html_entity_decode($modifiedHtml)),
					'divi_meta_data'=>array(
						'_et_pb_use_builder'=>'on',				
						'_et_builder_version'=>ET_BUILDER_VERSION
					)
				);
		}else{
			return array('content'=>$content);
		}

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

			$content = $this->metasync_upload_post_content($item);

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
						__('Post date is not valid'),
						array('status' => 400)
					);
				}

				// $date_limit_str = strtotime(date('Y-m-d') . '-2 month');
				// $post_date_str = strtotime($item['post_date']);
				// if ($date_limit_str >= $post_date_str) {
				// 	$newDate = date('Y-m-d', strtotime('-2 month'));
				// 	return new WP_Error(
				// 		'rest_post_greater_date',
				// 		__("Post date should be greater then " . $newDate),
				// 		array('status' => 400)
				// 	);
				// }

				// if ($post_date_str > strtotime(date('Y-m-d'))) {
				// 	return new WP_Error(
				// 		'rest_post_less_date',
				// 		__("Post date should be less then Today"),
				// 		array('status' => 400)
				// 	);
				// }

				// $new_post['post_date'] = sanitize_text_field($item['post_date'] . date(' h:i:s'));
			}

			// Adding condition to check if the post is already exist
			$post_status_new = isset($item['post_status']) ? sanitize_text_field($item['post_status']) : 'publish';
			$post_permalink = $item['permalink'] = isset($item['permalink']) ? $item['permalink'] : sanitize_title($new_post['post_title']);

			$getPostID_byURL = @get_page_by_path($item['permalink'], OBJECT, $new_post['post_type'])->ID;
			if ($getPostID_byURL == NULL) {
				$getPostID_byURL = new WP_Query(
					array(
						'post_type' => $new_post['post_type'],
						'title' => $new_post['post_title']
					)
				);
				$getPostID_byURL = $getPostID_byURL->posts[0]->ID ?? null;
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
			if ($new_post['post_type'] === 'post' && is_array(@$item['post_categories'])) {
				$append_categories = isset($item['append_categories']) && $item['append_categories'] == true ? true : false;
				$post_cattegories = $this->metasync_handle_post_category($post_id, $item['post_categories'], $append_categories);
			}
			if (isset($content['elementor_meta_data']) && did_action( 'elementor/loaded' )) {
				// Clear Elementor cache for the specified post ID
				\Elementor\Plugin::instance()->files_manager->clear_cache();

			}

			$post_tags = [];
			if ($new_post['post_type'] === 'post' && is_array(@$item['post_tags'])) {
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
				__(''),
				// HTTP 204 requires no body for response
				array('status' => 204)
			);
		}
		return new WP_Error(
			'rest_post_delete_fail',
			__('No post found in the database with requested ID.'),
			array('status' => 400)
		);
	}

	public function get_items($request)
	{
		$get_data = sanitize_post($_GET);
		$post_id = (int) $get_data['post_id'];

		if ($post_id !== 0) {
			return rest_ensure_response($this->elementor_getItems($post_id));
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

	// private function elementor_update_content($post_id, $content)
	// {
	// 	// Check for Elementor plugin to update post
	// 	$metaElementorData = get_post_meta($post_id, '_elementor_data', true);
	// 	if (!empty($metaElementorData)) {
	// 		$metaElementorData = json_decode($metaElementorData);
	// 		$index_container = 0;
	// 		$index_elements = 0;
	// 		$_L = [0, 0, 0];

	// 		foreach ($metaElementorData as $container) {
	// 			foreach ($container->elements as $element) {
	// 				// if there is only one element and that is empty
	// 				if (gettype($element->settings) == 'array' && count($element->settings) == 0) {
	// 					// add new element and trigger go
	// 					$metaElementorData[$_L[0]]->elements[$_L[1]]->settings = ["editor" => $content];
	// 					goto update;
	// 				}
	// 				if ($_L[2] < strlen($element->settings->editor)) {
	// 					$_L[0] = $index_container;
	// 					$_L[1] = $index_elements;
	// 					$_L[2] = strlen($element->settings->editor);
	// 				}
	// 				$index_elements++;
	// 			}
	// 			$index_container++;
	// 		}
	// 		// update the element with largest content
	// 		if ($_L[2] !== 0) {
	// 			$metaElementorData[$_L[0]]->elements[$_L[1]]->settings->editor = $content;
	// 		}
	// 		update:
	// 		return update_post_meta($post_id, '_elementor_data', wp_slash(json_encode($metaElementorData)));
	// 	}
	// 	// null if elementor post meta doesn't found
	// 	return null;
	// }

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
						$post_id = @get_page_by_path(sanitize_text_field($post['permalink']), OBJECT, 'post')->ID;
					}

					if ($post_id == 0) {
						// try to get permalink from URL
						$url_to_permalink = $this->common->get_permalink_from_url($safe_url);
						$post_id = @get_page_by_path($url_to_permalink, OBJECT, 'post')->ID;
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
					__('No post found in the database with requested ID.'),
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

			if (isset($post['post_content']) && !empty($post['post_content'])) {
				$content = $this->metasync_upload_post_content($post);
				$update_params['post_content'] = $content['content'];
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
						__('Post date is not valid'),
						array('status' => 400)
					);
				}

				$date_limit_str = strtotime(date('Y-m-d') . '-2 month');
				$post_date_str = strtotime($post['post_date']);

				if ($date_limit_str >= $post_date_str) {
					$newDate = date('Y-m-d', strtotime('-2 month'));
					return new WP_Error(
						'rest_post_greater_date',
						__("Post date should be greater then " . $newDate),
						array('status' => 400)
					);
				}

				if ($post_date_str > strtotime(date('Y-m-d'))) {
					return new WP_Error(
						'rest_post_greater_date',
						__('Post date should be less then Today'),
						array('status' => 400)
					);
				}
				$update_params['post_date'] = sanitize_text_field($post['post_date'] . date(' h:i:s'));
			}

			$post_cattegories = [];
			if ($post_data && $post_data->post_type === 'post' && is_array(@$post['post_categories'])) {
				$append_categories = isset($post['append_categories']) && $post['append_categories'] == true ? true : false;
				$post_cattegories = $this->metasync_handle_post_category($post_id, $post['post_categories'], $append_categories);
			}
			
			$post_tags = [];
			if ($post_data && $post_data->post_type === 'post' && is_array(@$post['post_tags'])) {
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

			ksort($update_params);
			$data[] = $update_params;
		}

		return rest_ensure_response($data);
	}

	public function create_page($request)
	{
		$payload = $request->get_json_params()[0];
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
			if (
				isset($payload[$payloadIndex]['is_blank']) &&
				!empty($payload[$payloadIndex]['is_blank']) &&
				$payload[$payloadIndex]['is_blank'] != 'false'
			) {
				require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-template.php';
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
				__('No page found in the database with requested ID.'),
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
			if (
				isset($payload[$payloadIndex]['is_blank']) &&
				!empty($payload[$payloadIndex]['is_blank']) &&
				$payload[$payloadIndex]['is_blank'] != 'false'
			) {
				require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-template.php';
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
		$response = wp_remote_post('https://api.searchatlas.com/api/token/', array('body' => $payload));
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

	function wp_robots_meta($robots)
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

	public function hook_metasync_metatags()
	{
		$get_page_meta = get_post_meta(get_the_ID());
		$list_page_meta = array(
			'description' => $get_page_meta['meta_description'][0] ?? '',
			'robots' => $get_page_meta['meta_robots'][0] ?? 'index',
		);
		$metasync_option = Metasync::get_option('general');
		/*
		#Remove Error Suppressants
		#Check if the "enable_metadesc" key is set
		*/
		if (isset($metasync_option['enable_metadesc']) && $metasync_option['enable_metadesc'] !== 'true') {
			unset($list_page_meta['description']);
			unset($list_page_meta['robots']);
		}

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

		$post_text = wp_trim_words(get_the_content(), 30, '');

		$site_info = Metasync::get_option('optimal_settings')['site_info'] ?? [];

		$facebook_page_url = Metasync::get_option('social_meta')['facebook_page_url'] ?? '';
		$facebook_authorship = Metasync::get_option('social_meta')['facebook_authorship'] ?? '';
		$facebook_admin = Metasync::get_option('social_meta')['facebook_admin'] ?? '';

		$twitter_username = Metasync::get_option('social_meta')['twitter_username'] ?? '';

		$image = [];
		$image_mime_type = '';

		if ($post && get_the_post_thumbnail_url($post->ID)) {

			$image_id = get_post_thumbnail_id($post->ID);
			$image = wp_get_attachment_image_src($image_id, '');
			if (!empty($image)) {
				$image_mime_type = wp_get_image_mime($image[0]);
			}
		} else if ($site_info && isset($site_info['social_share_image'])) {
			$image = wp_get_attachment_image_src($site_info['social_share_image'], '');
			if (!empty($image)) {
				$image_mime_type = wp_get_image_mime($image[0]);
			}
		}


		$ogMetaKeys = [
			'og:locale' => get_locale(),
			'og:type' => 'article',
			'og:title' => $post->post_title . ' - ' . get_bloginfo('name'),
			'og:description' => $post_text ?? '',
			'og:url' => get_permalink($post->ID),
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

		// // Enable/Disable Schema for posts and pages
		// $general_enable_schema = @Metasync::get_option('general')['enable_schema'] == 'true' ? true : false;
		// if ($general_enable_schema == true) {
		// 	// Schema for every post and page
		// 	$article_json_ld = $this->add_ld_json();

		// 	// Schema for specific post and page
		// 	$local_business = new Metasync_Local_SEO();
		// 	$business_json_ld = $local_business->local_business_ld_json();

		// 	if ($business_json_ld) {
		// 		$json_ld = $business_json_ld;
		// 	} else {
		// 		$json_ld = $article_json_ld;
		// 	}

		// 	$wp_json_encode = wp_json_encode($json_ld, JSON_UNESCAPED_SLASHES);

		// 	if ($json_ld !== NULL) {
		// 		echo "\t<script type='application/ld+json'>\n\t" .
		// 			stripslashes(htmlspecialchars_decode(esc_js($wp_json_encode))) .
		// 			"\n\t</script>";
		// 	}
		// }

		// echo "\n\t<!-- MetaSync metadata -->\n";
	}

	public function metasync_plugin_links($links)
	{
		$links[] = '<a href="#" id="wp_metasync_sync">' . __('Sync') . '</a>';
		// $links[] = '<a href="' . get_site_url(null, 'wp-sitemap.xml') . '" target="_blank">' . __('Sitemap') . '</a>';
		$menu_slug = Metasync::get_option('general')['white_label_plugin_menu_slug']==""  ?  'searchatlas' : Metasync::get_option('general')['white_label_plugin_menu_slug'];
		$links[] = '<a href="' . get_admin_url(null, 'admin.php?page=' .$menu_slug) . '">' . __('Settings') . '</a>';
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
			'headline' => str_replace($this->escapers, $this->replacements, $post->post_title),
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
				'name' => str_replace($this->escapers, $this->replacements, get_bloginfo('name')),
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

		wp_remote_post(
			'https://graph.facebook.com/',
			[
				'body' => [
					'id' => $facebook_app,
					'access_token' => $facebook_secret,
				],
			]
		);
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
	
	
}

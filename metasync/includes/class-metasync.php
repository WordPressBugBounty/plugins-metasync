<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/includes
 * @author     Engineering Team <support@searchatlas.com>
 */
class Metasync
{

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Metasync_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	protected $database;

	protected $db_redirection;

	protected $db_heartbeat_errors;



	public const option_name = "metasync_options";
	
	/**
	 * Search Atlas Domain Constants
	 * Centralized constants for all Search Atlas service endpoints
	 */
	public const HOMEPAGE_DOMAIN = "https://searchatlas.com";
	public const DASHBOARD_DOMAIN = "https://dashboard.searchatlas.com";
	public const API_DOMAIN = "https://api.searchatlas.com";
	public const CA_API_DOMAIN = "https://ca.searchatlas.com";	
	public const LOGGER_API_DOMAIN = "https://wp-logger.api.searchatlas.com";
	public const SUPPORT_EMAIL = "support@searchatlas.com";
	public const DOCUMENTATION_DOMAIN = "https://help.searchatlas.com";

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct()
	{
		if (defined('METASYNC_VERSION')) {
			$this->version = METASYNC_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'metasync';

		$this->load_dependencies();
		$this->set_locale();
		$this->init_api_key_monitor();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Metasync_Loader. Orchestrates the hooks of the plugin.
	 * - Metasync_i18n. Defines internationalization functionality.
	 * - Metasync_Admin. Defines all hooks for the admin area.
	 * - Metasync_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies()
	{

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-i18n.php';

		/**
		 * The class responsible for monitoring API key changes and triggering heartbeat updates
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-api-key-monitor.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-metasync-admin.php';
		require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-metasync-post-meta-setting.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'public/class-metasync-public.php';

		/**
		 * The class responsible for defining template crawling and check feture image and post_title
		 * side of the site.
		 */
		require plugin_dir_path(dirname(__FILE__)) .	 'public/class-metasync-hidden-post.php';





		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'code-snippets/class-metasync-code-snippets.php';



		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'optimal-settings/class-metasync-optimal-settings.php';




		require_once ABSPATH . 'wp-admin/includes/taxonomy.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'customer-sync-requests/class-metasync-sync-requests.php';

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-common.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'heartbeat-error-monitor/class-metasync-heartbeat-error-monitor-database.php';
		require_once plugin_dir_path(dirname(__FILE__)) . 'heartbeat-error-monitor/class-metasync-heartbeat-error-monitor.php';



		/**
		 * The class responsible for defining all actions that occur in the template.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-template.php';

		$this->loader = new Metasync_Loader();
		$this->db_heartbeat_errors = new Metasync_HeartBeat_Error_Monitor_Database();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Metasync_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale()
	{
		$plugin_i18n = new Metasync_i18n();

		$this->loader->add_action('plugins_loaded', $plugin_i18n, 'load_plugin_textdomain');
	}

	/**
	 * Initialize the API Key Monitor for comprehensive API key change detection
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function init_api_key_monitor()
	{
		// Initialize the singleton instance of the API Key Monitor
		// This will automatically set up hooks to monitor all API key changes
		Metasync_API_Key_Monitor::get_instance();
		
		// Log successful initialization
		#commented out to stop appending this to error.php 
		# error_log('MetaSync: API Key Monitor initialized successfully');
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks()
	{

		$plugin_admin = new Metasync_Admin($this->get_plugin_name(), $this->get_version(), $this->database, $this->db_redirection, $this->db_heartbeat_errors); // , $this->data_error_log_list

		# add sso validation - only when token is present
		#$this->loader->add_action('wp', $plugin_admin, 'conditional_sso_validation');
		# wp hook changed to init 
		$this->loader->add_action('init', $plugin_admin, 'conditional_sso_validation');

		$this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_styles');
		$this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts');

		// HeartBeat API Receive Respond and Settings.
		$this->loader->add_action('heartbeat_settings', $plugin_admin, 'metasync_heartbeat_settings');
		$this->loader->add_action('heartbeat_received', $plugin_admin, 'metasync_received_data', 10, 2);
		$this->loader->add_action('wp_ajax_lgSendCustomerParams', $plugin_admin, 'lgSendCustomerParams');
		
		// SSO Authentication endpoints
		$this->loader->add_action('wp_ajax_generate_sso_url', $plugin_admin, 'generate_sso_url');
		$this->loader->add_action('wp_ajax_check_sso_status', $plugin_admin, 'check_sso_status');
		$this->loader->add_action('wp_ajax_reset_searchatlas_authentication', $plugin_admin, 'reset_searchatlas_authentication');
		
		// Enhanced SSO Development/Testing endpoints
		$this->loader->add_action('wp_ajax_test_enhanced_sso_tokens', $plugin_admin, 'test_enhanced_sso_tokens');
		$this->loader->add_action('wp_ajax_test_whitelabel_domain', $plugin_admin, 'test_whitelabel_domain');
		$this->loader->add_action('wp_ajax_test_sso_ajax_endpoint', $plugin_admin, 'test_sso_ajax_endpoint');
		$this->loader->add_action('wp_ajax_simple_ajax_test', $plugin_admin, 'simple_ajax_test');


		$post_meta_setting = new Metasync_Post_Meta_Settings();
		$this->loader->add_action('admin_init', $post_meta_setting, 'add_post_mata_data', 2);
		$this->loader->add_action('admin_init', $post_meta_setting, 'show_top_admin_bar', 9);
		$this->loader->add_action('wp', $post_meta_setting, 'show_top_admin_bar', 9);


	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks()
	{
		// Header and Footer code snippets
		$code_snippets = new Metasync_Code_Snippets();

		$this->loader->add_action('wp_head', $code_snippets, 'get_header_snippet');
		$this->loader->add_action('wp_footer', $code_snippets, 'get_footer_snippet');



		$optimal_settings = new Metasync_Optimal_Settings();
		$this->loader->add_filter('wp_robots', $optimal_settings, 'add_robots_meta');
		$this->loader->add_action('the_content', $optimal_settings, 'add_attributes_external_links');

		$plugin_public = new Metasync_Public($this->get_plugin_name(), $this->get_version());
		$get_plugin_basename = sprintf('%1$s/%1$s.php', $this->plugin_name);

		$this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_styles');
		$this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_scripts');
		$this->loader->add_action('wp_head', $plugin_public, 'hook_metasync_metatags', 1, 1);
		
		// AMP cleanup functionality - remove metasync_optimized attribute from head on AMP pages
		$this->loader->add_action('template_redirect', $plugin_public, 'cleanup_amp_head_attribute', 1);
		$this->loader->add_action('wp_footer', $plugin_public, 'end_amp_head_cleanup', 999);
		$this->loader->add_action('plugin_action_links_' . $get_plugin_basename, $plugin_public, 'metasync_plugin_links');
		$this->loader->add_action('rest_api_init', $plugin_public, 'metasync_register_rest_routes');
		$this->loader->add_action('init', $plugin_public, 'metasync_plugin_init', 5);
		$this->loader->add_action('wp_ajax_metasync', $plugin_public, 'sync_items');
		$this->loader->add_action('wp_ajax_lglogin', $plugin_public, 'linkgraph_login');
		$this->loader->add_filter('wp_robots', $plugin_public, 'metasync_wp_robots_meta');


		
		$metasyncTemplateClass = new Metasync_Template();
		$this->loader->add_filter('theme_page_templates', $metasyncTemplateClass, 'metasync_template_landing_page', 10, 3);
		$this->loader->add_filter('template_include', $metasyncTemplateClass, 'metasync_template_landing_page_load', 99 );
		$templateCrawler = new MetaSyncHiddenPostManager(); # initialize the crawler class

		$this->loader->add_action('wp_trash_post', $templateCrawler , 'prevent_post_deletion'); # Prevent post deletion when moved to trash
        $this->loader->add_action('before_delete_post', $templateCrawler , 'prevent_post_deletion'); # Prevent permanent deletion
		$this->loader->add_filter('metasync_hidden_post_manager', $templateCrawler , 'init'); # run the crawler

	}

	public static function get_option($key = null, $default = null)
	{
		$options = get_option(Metasync::option_name);
		if (empty($options)) $options = [];
		if ($key === null) return $options;
		return $options[$key] ?? ($default !== null ? $default : null);
	}

	public static function set_option($data)
	{
		return update_option(Metasync::option_name, $data);
	}

	/**
	 * Get whitelabel settings
	 * Helper method to retrieve whitelabel configuration
	 */
	public static function get_whitelabel_settings()
	{
		$whitelabel = self::get_option('whitelabel');
		return is_array($whitelabel) ? $whitelabel : array(
			'is_whitelabel' => false,
			'domain' => '',
			'logo' => '',
			'company_name' => '',
			'updated_at' => 0
		);
	}

	/**
	 * Check if whitelabel mode is enabled
	 */
	public static function is_whitelabel_enabled()
	{
		$whitelabel = self::get_whitelabel_settings();
		return isset($whitelabel['is_whitelabel']) && $whitelabel['is_whitelabel'] === true;
	}

	/**
	 * Get effective dashboard domain for the plugin
	 * Returns whitelabel domain if set (regardless of is_whitelabel flag), otherwise returns production default
	 */
	public static function get_dashboard_domain()
	{
		$whitelabel = self::get_whitelabel_settings();
		
		// Priority 1: Use whitelabel domain if it's not empty (regardless of is_whitelabel flag)
		if (!empty($whitelabel['domain'])) {
			return $whitelabel['domain'];
		}
		
		// Priority 2: Use production default domain when whitelabel_domain is empty
		return self::DASHBOARD_DOMAIN;
	}

	/**
	 * Get whitelabel logo URL
	 * Returns the whitelabel logo URL if logo is set
	 */
	public static function get_whitelabel_logo()
	{
		$whitelabel = self::get_whitelabel_settings();
		
		// Return logo if it's set and is a valid URL
		// Users should be able to set a custom logo without requiring a custom domain
		if (!empty($whitelabel['logo'])) {
			return $whitelabel['logo'];
		}
		
		return null;
	}

	/**
	 * Get whitelabel company name
	 * Returns the whitelabel company name if whitelabel is active and company name is set
	 */
	public static function get_whitelabel_company_name()
	{
		$whitelabel = self::get_whitelabel_settings();
		
		// Return company name only if whitelabel is active and company name is set
		if ($whitelabel['is_whitelabel'] === true && !empty($whitelabel['company_name'])) {
			return $whitelabel['company_name'];
		}
		
		return null;
	}

	/**
	 * Get whitelabel OTTO name
	 * Returns the custom OTTO name if set, otherwise returns 'OTTO'
	 */
	public static function get_whitelabel_otto_name()
	{
		$general_settings = self::get_option('general');
		
		// Return custom OTTO name if set, otherwise fallback to 'OTTO'
		if (!empty($general_settings['whitelabel_otto_name'])) {
			return $general_settings['whitelabel_otto_name'];
		}
		
		return 'OTTO';
	}

	/**
	 * Get active JWT token for Search Atlas API authentication
	 * Convenience method accessible from anywhere in the plugin
	 * 
	 * @param bool $force_refresh Force generation of new token even if cached one exists
	 * @return string|false JWT token on success, false on failure
	 */
	public static function get_jwt_token($force_refresh = false)
	{
		// Delegate to admin class method
		return Metasync_Admin::get_active_jwt_token($force_refresh);
	}

	/**
	 * Get effective plugin name
	 * Returns plugin name respecting white label settings
	 * Priority: 1) white_label_plugin_name 2) company branding + base_name 3) base_name
	 */
	public static function get_effective_plugin_name($base_name = 'Search Atlas')
	{
		$general_settings = self::get_option('general');
		
		// Priority 1: Use white_label_plugin_name if set and not empty
		if (!empty($general_settings['white_label_plugin_name'])) {
			return $general_settings['white_label_plugin_name'];
		}
		
		$whitelabel = self::get_whitelabel_settings();
		
		// Priority 2: If whitelabel is enabled and company name is provided, enhance the plugin name
		if ($whitelabel['is_whitelabel'] === true && !empty($whitelabel['company_name'])) {
			return $whitelabel['company_name'] . ' ' . $base_name;
		}
		
		// Priority 3: Return base_name as fallback
		return $base_name;
	}
	
	/**
	 * Centralized API Key Event Logging
	 * Provides structured logging for all API key related events with consistent formatting
	 *
	 * @since    1.0.0
	 * @param    string    $event_type     Type of event (change, refresh, reset, etc.)
	 * @param    string    $api_key_type   Type of API key (plugin_auth_token, searchatlas_api_key)
	 * @param    array     $details        Additional details about the event
	 * @param    string    $level          Log level (info, warning, error)
	 */
	public static function log_api_key_event($event_type, $api_key_type, $details = array(), $level = 'info')
	{
		try {
			// Build structured log entry
			$log_data = array(
				'timestamp' => current_time('mysql'),
				'event_type' => $event_type,
				'api_key_type' => $api_key_type,
				'level' => $level
			);
			
			// Add details if provided
			if (!empty($details)) {
				$log_data['details'] = $details;
			}
			
			// Format log message with consistent structure
			$log_prefix = strtoupper($level) . ' - MetaSync API Key Event';
			$log_message = sprintf('[%s] %s: %s (%s)', 
				$log_data['timestamp'],
				$log_prefix,
				$event_type,
				$api_key_type
			);
			
			// Add details to log message if present
			if (!empty($details)) {
				$formatted_details = array();
				foreach ($details as $key => $value) {
					$formatted_details[] = $key . ': ' . (is_string($value) ? $value : json_encode($value));
				}
				$log_message .= ' - ' . implode(', ', $formatted_details);
			}
			
			// Log to WordPress error log
			error_log($log_message);
			
			// Optionally store in database for admin dashboard (future enhancement)
			// This could be extended to store in a dedicated log table
			
		} catch (Exception $e) {
			// Fallback logging if structured logging fails
			error_log('MetaSync API Key Event Logging Error: ' . $e->getMessage());
		}
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run()
	{
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name()
	{
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Metasync_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader()
	{
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version()
	{
		return $this->version;
	}
}

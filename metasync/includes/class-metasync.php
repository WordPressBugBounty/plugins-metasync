<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}


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
		// $this->set_locale(); // Language support removed - using default only
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
		// require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-i18n.php'; // Language support removed

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
		 * The class responsible for the setup wizard functionality.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-setup-wizard.php';

		/**
		 * The class responsible for displaying and managing error logs in admin settings.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'site-error-logs/class-metasync-error-logs.php';

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
		 * The class responsible for Open Graph and Twitter Card meta tags functionality
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-opengraph.php';



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
		 * The class responsible for access control functionality
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-access-control.php';
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-access-control-ui.php';

		/**
		 * The class responsible for authentication management
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-auth-manager.php';

		/**
		 * The class responsible for endpoint management (production/staging switching)
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-endpoint-manager.php';

		/**
		 * The class responsible for developer panel functionality
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-metasync-dev-panel.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'heartbeat-error-monitor/class-metasync-heartbeat-error-monitor-database.php';
		require_once plugin_dir_path(dirname(__FILE__)) . 'heartbeat-error-monitor/class-metasync-heartbeat-error-monitor.php';

		/**
		 * The class responsible for redirection functionality.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'redirections/class-metasync-redirection-database.php';
		require_once plugin_dir_path(dirname(__FILE__)) . 'redirections/class-metasync-redirection.php';
		require_once plugin_dir_path(dirname(__FILE__)) . 'redirections/class-metasync-auto-redirect.php';

		/**
		 * The class responsible for custom HTML pages functionality.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'custom-pages/class-metasync-custom-pages.php';

		/**
		 * The class responsible for defining all actions that occur in the template.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-template.php';

		/**
		 * The class responsible for Google Index API functionality
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'google-index/google-index-init.php';

		/**
		 * The class responsible for Schema Markup functionality.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'schema-markup/class-metasync-schema-markup.php';

		/**
		 * The class responsible for OTTO Frontend Toolbar functionality.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'otto-frontend-toolbar/class-metasync-otto-frontend-toolbar.php';

		/**
		 * The class responsible for SEO Sidebar in Gutenberg Block Editor.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-metasync-seo-sidebar.php';

		/**
		 * The class responsible for enhanced error logging with categories.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-error-logger.php';

		$this->loader = new Metasync_Loader();
		$this->db_heartbeat_errors = new Metasync_HeartBeat_Error_Monitor_Database();
		$this->db_redirection = new Metasync_Redirection_Database();
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
	// Language support removed - using default only
	/*
	private function set_locale()
	{
		$plugin_i18n = new Metasync_i18n();

		$this->loader->add_action('plugins_loaded', $plugin_i18n, 'load_plugin_textdomain');
	}
	*/

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

		// Initialize HTML Visual Editor
		require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-metasync-html-visual-editor.php';
		$html_visual_editor = new Metasync_HTML_Visual_Editor($this->get_plugin_name(), $this->get_version());
		$html_visual_editor->init();

		// Initialize OTTO Debug class for developers
		if (class_exists('Metasync_Otto_Debug')) {
			$otto_debug = new Metasync_Otto_Debug($this->get_plugin_name(), $this->get_version());
		}

		// Initialize SEO Sidebar for Gutenberg Block Editor
		if (class_exists('Metasync_SEO_Sidebar')) {
			new Metasync_SEO_Sidebar($this->get_version());
		}

		$this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_styles');
		$this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts');

		# Redirection import AJAX handler
		$redirection_handler = new Metasync_Redirection($this->db_redirection);
		$this->loader->add_action('wp_ajax_metasync_import_redirections', $redirection_handler, 'handle_import_ajax');
		
		// HeartBeat API Receive Respond and Settings.
		$this->loader->add_action('heartbeat_settings', $plugin_admin, 'metasync_heartbeat_settings');
		$this->loader->add_action('heartbeat_received', $plugin_admin, 'metasync_received_data', 10, 2);
		$this->loader->add_action('wp_ajax_lgSendCustomerParams', $plugin_admin, 'lgSendCustomerParams');
		
		// Search Atlas Connect endpoints - authenticates with Search Atlas platform to retrieve SA API key and Otto UUID
		$this->loader->add_action('wp_ajax_generate_searchatlas_connect_url', $plugin_admin, 'generate_searchatlas_connect_url');
		$this->loader->add_action('wp_ajax_check_searchatlas_connect_status', $plugin_admin, 'check_searchatlas_connect_status');
		$this->loader->add_action('wp_ajax_reset_searchatlas_authentication', $plugin_admin, 'reset_searchatlas_authentication');

		// Auto-update filter
		$this->loader->add_filter('auto_update_plugin', $plugin_admin, 'control_plugin_auto_updates', 10, 2);

		// Search Atlas Connect development/testing endpoints
		$this->loader->add_action('wp_ajax_test_enhanced_searchatlas_tokens', $plugin_admin, 'test_enhanced_searchatlas_tokens');
		$this->loader->add_action('wp_ajax_test_whitelabel_domain', $plugin_admin, 'test_whitelabel_domain');
		$this->loader->add_action('wp_ajax_test_searchatlas_ajax_endpoint', $plugin_admin, 'test_searchatlas_ajax_endpoint');
		$this->loader->add_action('wp_ajax_simple_ajax_test', $plugin_admin, 'simple_ajax_test');


		$post_meta_setting = new Metasync_Post_Meta_Settings();
		$this->loader->add_action('admin_init', $post_meta_setting, 'add_post_meta_data', 2);
		$this->loader->add_action('admin_init', $post_meta_setting, 'show_top_admin_bar', 9);
		$this->loader->add_action('wp', $post_meta_setting, 'show_top_admin_bar', 9);

		// Initialize XML Sitemap auto-update hooks if enabled (admin only)
		if (is_admin() && get_option('metasync_sitemap_auto_update', false)) {
			require_once plugin_dir_path(dirname(__FILE__)) . 'sitemap/class-metasync-sitemap-generator.php';
			$sitemap_generator = new Metasync_Sitemap_Generator();
			$sitemap_generator->setup_auto_update_hooks();
		}
		// Initialize Schema Markup functionality
		$schema_markup = new Metasync_Schema_Markup($this->get_plugin_name(), $this->get_version());
		$this->loader->add_action('wp_ajax_metasync_get_schema_fields', $schema_markup, 'ajax_get_schema_fields');
		$this->loader->add_action('wp_ajax_metasync_preview_schema', $schema_markup, 'ajax_preview_schema');

		// Initialize Developer Panel (for endpoint switching)
		if (class_exists('Metasync_Dev_Panel')) {
			$dev_panel = new Metasync_Dev_Panel($this->get_plugin_name(), $this->get_version());
		}

		// Initialize endpoint URL filtering for staging mode
		$this->init_endpoint_filtering();

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
		$this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_page_custom_css', 999);

		// Elementor editor CSS injection
		if (class_exists('\Elementor\Plugin')) {
			$this->loader->add_action('elementor/preview/enqueue_styles', $plugin_public, 'enqueue_elementor_editor_css', 999);
		}

		// Divi builder CSS injection
		if (function_exists('et_setup_theme')) {
			$this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_divi_builder_css', 999);
		}

		$this->loader->add_action('wp_head', $plugin_public, 'hook_metasync_metatags', 1, 1);
		$this->loader->add_action('template_redirect', $plugin_public, 'inject_archive_seo_controls');
		
		// Sitemap exclusions for disabled archive types
		$this->loader->add_filter('wp_sitemaps_taxonomies', $plugin_public, 'filter_sitemap_taxonomies');
		$this->loader->add_filter('wp_sitemaps_users_entry', $plugin_public, 'filter_sitemap_users', 10, 2);
		$this->loader->add_filter('wp_sitemaps_add_provider', $plugin_public, 'filter_sitemap_providers', 10, 2);
		$this->loader->add_filter('wp_sitemaps_index_entry', $plugin_public, 'filter_sitemap_index_entries', 10, 4);
		
		// AMP cleanup functionality - remove metasync_optimized attribute from head on AMP pages
		$this->loader->add_action('template_redirect', $plugin_public, 'cleanup_amp_head_attribute', 1);
		$this->loader->add_action('wp_footer', $plugin_public, 'end_amp_head_cleanup', 999);
		
		// Redirection functionality
		$redirection = new Metasync_Redirection($this->db_redirection);
		$this->loader->add_action('template_redirect', $redirection, 'handle_template_redirect', 5);
		
		# Prevent WordPress from redirecting to draft posts via redirect_canonical
		$this->loader->add_filter('redirect_canonical', $redirection, 'prevent_draft_post_redirects', 10, 2);
		
		# Prevent WordPress old slug redirects to unpublished posts only
		$this->loader->add_filter('old_slug_redirect_post_id', $redirection, 'prevent_old_slug_redirect_to_drafts', 10, 1);
		
		// Auto-redirect on slug change - creates 301 redirect when post/page slug is changed
		$auto_redirect = new Metasync_Auto_Redirect($this->db_redirection);
		$auto_redirect->init();
		
		# Custom HTML Pages functionality
		# No additional loader hooks needed - class registers its own hooks
		$custom_pages = new Metasync_Custom_Pages();

		// 404 Error monitoring
		$this->loader->add_action('template_redirect', $this, 'handle_404_monitoring', 10);
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
		# $this->loader->add_filter('metasync_hidden_post_manager', $templateCrawler , 'init'); # run the crawler
		# Hidden post manager now runs via cron instead of filter (to avoid interfering with post create/update)
		$this->loader->add_action('metasync_hidden_post_check', $templateCrawler , 'init'); # run the crawler via cron

		// Open Graph and Social Media Tags
		$opengraph = new Metasync_OpenGraph($this->get_plugin_name(), $this->get_version());
		$opengraph->init();

		# Save current theme info to database (safe context - admin/init hooks)
		$this->loader->add_action('after_switch_theme', $this, 'save_current_theme_info');
		$this->loader->add_action('admin_init', $this, 'ensure_theme_info_saved');

		// OTTO Frontend Toolbar
		$otto_toolbar = new Metasync_Otto_Frontend_Toolbar($this->get_plugin_name(), $this->get_version());
		$this->loader->add_action('wp_enqueue_scripts', $otto_toolbar, 'enqueue_styles');
		$this->loader->add_action('wp_enqueue_scripts', $otto_toolbar, 'enqueue_scripts');
		$this->loader->add_action('admin_bar_menu', $otto_toolbar, 'add_admin_bar_menu', 100);
		$this->loader->add_action('wp_footer', $otto_toolbar, 'render_debug_bar', 999);

		// Initialize Sitemap Generator on frontend (for virtual sitemap serving)
		require_once plugin_dir_path(dirname(__FILE__)) . 'sitemap/class-metasync-sitemap-generator.php';
		$sitemap_generator = new Metasync_Sitemap_Generator();
	}

	/**
	 * Initialize endpoint URL filtering for staging mode
	 * Intercepts HTTP requests and replaces production URLs with staging URLs
	 */
	private function init_endpoint_filtering() {
		// Only add filter if Endpoint Manager is available and staging mode is active
		if (!class_exists('Metasync_Endpoint_Manager') || !Metasync_Endpoint_Manager::is_staging_mode()) {
			return;
		}

		// Add filter to intercept HTTP requests before they're sent
		add_filter('pre_http_request', array($this, 'filter_http_request_urls'), 10, 3);
	}

	/**
	 * Filter HTTP request URLs to replace production endpoints with staging
	 *
	 * @param false|array|WP_Error $preempt Whether to preempt an HTTP request's return value.
	 * @param array $args HTTP request arguments.
	 * @param string $url The request URL.
	 * @return false|array|WP_Error
	 */
	public function filter_http_request_urls($preempt, $args, $url) {
		// Only process if we're not preempting the request
		if ($preempt !== false) {
			return $preempt;
		}

		// Only process if staging mode is active
		if (!class_exists('Metasync_Endpoint_Manager') || !Metasync_Endpoint_Manager::is_staging_mode()) {
			return $preempt;
		}

		// Define URL replacements (production => staging)
		$url_replacements = array(
			'https://dashboard.searchatlas.com' => 'https://dashboard.staging.searchatlas.com',
			'https://api.searchatlas.com' => 'https://api.staging.searchatlas.com',
			'https://ca.searchatlas.com' => 'https://ca.staging.searchatlas.com',
			'https://sa.searchatlas.com' => 'https://sa.staging.searchatlas.com',
		);

		// Check if URL needs to be replaced
		$original_url = $url;
		foreach ($url_replacements as $production => $staging) {
			if (strpos($url, $production) === 0) {
				$url = str_replace($production, $staging, $url);
				error_log("MetaSync Endpoint Filter: Replaced {$production} with {$staging} in URL: {$original_url}");
				break;
			}
		}

		// If URL was changed, modify the args and make the request ourselves
		if ($url !== $original_url) {
			// Make the request with the modified URL
			return wp_remote_request($url, $args);
		}

		return $preempt;
	}

	/**
	 * Save current theme information to MetaSync options
	 * This runs in WordPress admin context, not during REST API requests
	 * Safe to use wp_get_theme() here
	 */
	public function save_current_theme_info() {
		$theme = wp_get_theme();
		$metasync_data = self::get_option();
		
		if (!isset($metasync_data['general'])) {
			$metasync_data['general'] = array();
		}
		
		$metasync_data['general']['current_theme_name'] = $theme->get('Name');
		$metasync_data['general']['current_theme_template'] = $theme->get_template();
		$metasync_data['general']['theme_info_updated'] = time();
		
		self::set_option($metasync_data);
	}
	
	/**
	 * Ensure theme info is saved on admin_init if not already saved
	 * This ensures theme info is available even if theme wasn't switched
	 */
	public function ensure_theme_info_saved() {
		$metasync_data = self::get_option('general');
		
		# Only run once per day to avoid overhead
		if (empty($metasync_data['theme_info_updated']) || 
		    (time() - $metasync_data['theme_info_updated']) > 86400) {
			$this->save_current_theme_info();
		}
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
		#return update_option(Metasync::option_name, $data);
		$result = update_option(Metasync::option_name, $data);
		
		// NEW: Structured error logging for database errors (only log if it's a real DB error)
		global $wpdb;
		if ($result === false && class_exists('Metasync_Error_Logger') && !empty($wpdb->last_error)) {
			// Check if it's actually a database error (not just same value)
			$saved_data = get_option(Metasync::option_name);
			if ($saved_data !== $data) {
				// Value is different but save failed - this is a real database error
				Metasync_Error_Logger::log(
					Metasync_Error_Logger::CATEGORY_DATABASE_ERROR,
					Metasync_Error_Logger::SEVERITY_ERROR,
					'Failed to save plugin main options to database',
					[
						'option_name' => Metasync::option_name,
						'wpdb_error' => $wpdb->last_error,
						'wpdb_last_query' => $wpdb->last_query,
						'operation' => 'set_option',
						'has_api_key' => !empty($data['general']['searchatlas_api_key'] ?? null),
						'has_auth_token' => !empty($data['general']['apikey'] ?? null)
					]
				);
			}
		}
		
		return $result;
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
	 * Returns whitelabel domain if set (regardless of is_whitelabel flag), otherwise respects staging/production mode
	 */
	public static function get_dashboard_domain()
	{
		$whitelabel = self::get_whitelabel_settings();

		// Priority 1: Use whitelabel domain if it's not empty (regardless of is_whitelabel flag)
		if (!empty($whitelabel['domain'])) {
			return $whitelabel['domain'];
		}

		// Priority 2: Use endpoint manager to respect staging/production mode
		if (class_exists('Metasync_Endpoint_Manager')) {
			return Metasync_Endpoint_Manager::get_endpoint('DASHBOARD_DOMAIN');
		}

		// Priority 3: Fallback to production default domain
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
		if (isset($whitelabel['is_whitelabel']) && $whitelabel['is_whitelabel'] === true && !empty($whitelabel['company_name'])) {
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
	 * Check if the current user has access to the plugin based on role settings
	 * 
	 * @return bool True if user has access, false otherwise
	 */
	public static function current_user_has_plugin_access()
	{
		$user = wp_get_current_user();
		if (!$user || !$user->exists()) {
			return false;
		}

		// Administrators always have access
		if (in_array('administrator', (array) $user->roles)) {
			return true;
		}

		// Get the plugin access roles setting
		$general_options = self::get_option('general');
		
		// If setting not configured, default to admin-only access
		if (!isset($general_options['plugin_access_roles'])) {
			return false;
		}
		
		$allowed_roles = $general_options['plugin_access_roles'];
		
		// If it's a string (single role), convert to array
		if (!is_array($allowed_roles)) {
			$allowed_roles = array($allowed_roles);
		}
		
		// If "all" is selected, allow access
		if (in_array('all', $allowed_roles)) {
			return true;
		}
		
		// If array is empty, deny access (only admins allowed)
		if (empty($allowed_roles)) {
			return false;
		}
		
		// Check if user has any of the allowed roles
		$user_roles = (array) $user->roles;
		return !empty(array_intersect($user_roles, $allowed_roles));
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
		if (isset($whitelabel['is_whitelabel']) && $whitelabel['is_whitelabel'] === true && !empty($whitelabel['company_name'])) {
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
			
			
			// Optionally store in database for admin dashboard (future enhancement)
			// This could be extended to store in a dedicated log table
			
		} catch (Exception $e) {
			// Fallback logging if structured logging fails
			error_log('MetaSync API Key Event Logging Error: ' . $e->getMessage());
		}
	}

	/**
	 * Handle 404 error monitoring
	 */
	public function handle_404_monitoring()
	{
		// Only process on frontend
		if (is_admin()) {
			return;
		}

		// Check if this is a 404 error
		if (!is_404()) {
			return;
		}

		// PROTECTION 1: Exclude static assets to reduce noise
		$request_uri = $_SERVER['REQUEST_URI'] ?? '';
		$static_extensions = ['.css', '.js', '.jpg', '.jpeg', '.png', '.gif', '.ico', '.svg', '.woff', '.woff2', '.ttf', '.eot', '.map','.webp'];
		foreach ($static_extensions as $ext) {
			if (stripos($request_uri, $ext) !== false) {
				return; // Skip logging static asset 404s
			}
		}

		// PROTECTION 2: Bot detection - Block known bot patterns
		$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
		$bot_patterns = ['bot', 'crawler', 'spider', 'scraper', 'curl', 'wget', 'python', 'java'];
		foreach ($bot_patterns as $pattern) {
			if (stripos($user_agent, $pattern) !== false) {
				// Rate limit bot 404s more aggressively
				$bot_rate_key = 'metasync_404_bot_rate';
				$bot_hits = get_transient($bot_rate_key);
				if ($bot_hits !== false && $bot_hits >= 10) {
					// Bot has hit 10+ 404s in last minute - stop logging
					return;
				}
				set_transient($bot_rate_key, $bot_hits === false ? 1 : $bot_hits + 1, 60);
				break;
			}
		}

		// PROTECTION 3: Global rate limiting - Prevent 404 logging storms
		$global_rate_key = 'metasync_404_global_rate';
		$global_hits = get_transient($global_rate_key);
		if ($global_hits !== false && $global_hits >= 50) {
			// More than 50 404s per minute - stop logging to protect database
			if ($global_hits === 50) {
				error_log('MetaSync 404 Monitor: Rate limit exceeded - 50+ 404s per minute. Pausing logging.');
			}
			set_transient($global_rate_key, $global_hits + 1, 60);
			return;
		}
		set_transient($global_rate_key, $global_hits === false ? 1 : $global_hits + 1, 60);

		// Get current URL
		$current_url = $this->get_current_url();

		// PROTECTION 4: Per-URL caching - Prevent same URL from being logged repeatedly
		$url_cache_key = 'metasync_404_cached_' . md5($current_url);
		if (get_transient($url_cache_key)) {
			// This URL was already logged in last 5 minutes - skip DB write
			return;
		}

		// PROTECTION 5: URL validation - Skip obviously malicious URLs
		if (strlen($current_url) > 500 || preg_match('/[<>{}\\\\|]/', $current_url)) {
			return; // Skip potentially malicious or malformed URLs
		}

		// Initialize 404 monitor database
		require_once plugin_dir_path(dirname(__FILE__)) . '404-monitor/class-metasync-404-monitor-database.php';
		$db_404 = new Metasync_Error_Monitor_Database();

		// Get user agent (sanitized)
		$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';

		// Log the 404 error
		$result = $db_404->update([
			'uri' => $current_url,
			'user_agent' => $user_agent,
			'date_time' => current_time('mysql'),
			'hits_count' => 1
		]);

		// Cache this URL for 5 minutes to prevent repeated DB writes
		set_transient($url_cache_key, true, 300);
	}

	/**
	 * Get current URL
	 */
	private function get_current_url()
	{
		$protocol = is_ssl() ? 'https://' : 'http://';
		
		// Safely get HTTP_HOST with fallback
		$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
		if (empty($host) && isset($_SERVER['SERVER_NAME'])) {
			$host = $_SERVER['SERVER_NAME'];
		}
		if (empty($host)) {
			// Fallback to WordPress site URL if available
			$host = parse_url(home_url(), PHP_URL_HOST);
		}
		
		// Safely get REQUEST_URI with fallback
		$uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
		
		// Decode URL-encoded characters
		$uri = urldecode($uri);
		
		// Ensure URI starts with /
		if (!str_starts_with($uri, '/')) {
			$uri = '/' . $uri;
		}
		
		return $protocol . $host . $uri;
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

<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       http://linkgraph.io
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
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
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

	// protected $data_error_log_list;

	public const option_name = "metasync_options";

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
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		// require_once plugin_dir_path(dirname(__FILE__)) . '404-monitor/class-metasync-404-monitor-database.php';
		// require_once plugin_dir_path(dirname(__FILE__)) . '404-monitor/class-metasync-404-monitor.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		// require_once plugin_dir_path(dirname(__FILE__)) . 'local-seo/class-metasync-local-seo.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'code-snippets/class-metasync-code-snippets.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		// require_once plugin_dir_path(dirname(__FILE__)) . 'instant-index/class-metasync-instant-index.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		// require_once plugin_dir_path(dirname(__FILE__)) . 'redirections/class-metasync-redirection-database.php';
		// require_once plugin_dir_path(dirname(__FILE__)) . 'redirections/class-metasync-redirection.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'optimal-settings/class-metasync-optimal-settings.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		// require_once plugin_dir_path(dirname(__FILE__)) . 'site-error-logs/class-metasync-error-logs.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		// require_once plugin_dir_path(dirname(__FILE__)) . 'error_logs_table_list/class-metasync-error-log-list-data.php';
		// require_once plugin_dir_path(dirname(__FILE__)) . 'error_logs_table_list/class-metasync-error-log-list.php';


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
		 * The class responsible for defining all actions that occur in the markdown.
		 */
		// require_once plugin_dir_path(dirname(__FILE__)) . 'markdown/Parsedown.php';

		/**
		 * The class responsible for defining all actions that occur in the template.
		 */
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-template.php';

		$this->loader = new Metasync_Loader();
		// $this->database = new Metasync_Error_Monitor_Database();
		// $this->db_redirection = new Metasync_Redirection_Database();
		$this->db_heartbeat_errors = new Metasync_HeartBeat_Error_Monitor_Database();
		// $this->data_error_log_list = new Metasync_Error_Logs_Data();
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
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks()
	{

		$plugin_admin = new Metasync_Admin($this->get_plugin_name(), $this->get_version(), $this->database, $this->db_redirection, $this->db_heartbeat_errors); // , $this->data_error_log_list

		$this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_styles');
		$this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts');

		// HeartBeat API Receive Respond and Settings.
		$this->loader->add_action('heartbeat_settings', $plugin_admin, 'metasync_heartbeat_settings');
		$this->loader->add_action('heartbeat_received', $plugin_admin, 'metasync_received_data', 10, 2);
		$this->loader->add_action('wp_ajax_lgSendCustomerParams', $plugin_admin, 'lgSendCustomerParams');

		$sync_request = new Metasync_Sync_Requests();
		$this->loader->add_action('admin_init', $sync_request, 'SyncWhiteLabelUserHttp', 2);

		$post_meta_setting = new Metasync_Post_Meta_Settings();
		$this->loader->add_action('admin_init', $post_meta_setting, 'add_post_mata_data', 2);
		$this->loader->add_action('admin_init', $post_meta_setting, 'show_top_admin_bar', 9);
		$this->loader->add_action('wp', $post_meta_setting, 'show_top_admin_bar', 9);

		// $error_log_list_data = new Metasync_Error_Logs_Data();
		// $this->loader->add_action('admin_head', $error_log_list_data, 'style_my_table', 2);
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
		// 404 Monitor hook
		// $Metasync404Monitor = new Metasync_Error_Monitor($this->database);

		// Redirection hook
		// $MetasyncRedirection = new Metasync_Redirection($this->db_redirection);

		// Header and Footer code snippets
		$code_snippets = new Metasync_Code_Snippets();

		$this->loader->add_action('wp_head', $code_snippets, 'get_header_snippet');
		$this->loader->add_action('wp_footer', $code_snippets, 'get_footer_snippet');

		// $instant_index = new Metasync_Instant_Index();

		// $this->loader->add_action('wp_ajax_send_giapi', $instant_index, 'send');
		// $this->loader->add_action('admin_init', $instant_index, 'save_settings');
		// $this->loader->add_filter('post_row_actions', $instant_index, 'google_instant_index_post_link', 10, 2);
		// $this->loader->add_filter('page_row_actions', $instant_index, 'google_instant_index_post_link', 10, 2);
		// $this->loader->add_filter('media_row_actions', $instant_index, 'google_instant_index_post_link', 10, 2);

		$optimal_settings = new Metasync_Optimal_Settings();
		$this->loader->add_filter('wp_robots', $optimal_settings, 'add_robots_meta');
		$this->loader->add_action('the_content', $optimal_settings, 'add_attributes_external_links');

		$plugin_public = new Metasync_Public($this->get_plugin_name(), $this->get_version());
		$get_plugin_basename = sprintf('%1$s/%1$s.php', $this->plugin_name);

		$this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_styles');
		$this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_scripts');
		$this->loader->add_action('wp_head', $plugin_public, 'hook_metasync_metatags', 1, 1);
		$this->loader->add_action('plugin_action_links_' . $get_plugin_basename, $plugin_public, 'metasync_plugin_links');
		$this->loader->add_action('rest_api_init', $plugin_public, 'metasync_register_rest_routes');
		$this->loader->add_action('init', $plugin_public, 'metasync_plugin_init', 5);
		$this->loader->add_action('wp_ajax_metasync', $plugin_public, 'sync_items');
		$this->loader->add_action('wp_ajax_lglogin', $plugin_public, 'linkgraph_login');
		$this->loader->add_filter('wp_robots', $plugin_public, 'wp_robots_meta');

		$metasyncTemplateClass = new Metasync_Template();
		$this->loader->add_filter('theme_page_templates', $metasyncTemplateClass, 'metasync_template_landing_page', 10, 3);
		$this->loader->add_filter('template_include', $metasyncTemplateClass, 'metasync_template_landing_page_load', 99 );
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

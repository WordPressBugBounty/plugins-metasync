<?php

/**
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @link		https://searchatlas.com/
 * @since		1.0.0
 *
 * @wordpress-plugin
 * Plugin Name:       Search Atlas: The Premier AI SEO Plugin for Instant Optimization
 * Plugin URI:        https://searchatlas.com/
 * Description:       Search Atlas SEO is an intuitive WordPress Plugin that transforms the most complicated, most labor-intensive SEO tasks into streamlined, straightforward processes. With a few clicks, the meta-bulk update feature automates the re-optimization of meta tags using AI to increase clicks. Stay up-to-date with the freshest Google Search data for your entire site or targeted URLs within the Meta Sync plug-in page.
 * Version:           2.6.5 
 * Author:            Search Atlas
 * Author URI:        https://searchatlas.com
 * License:           GPL v3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain:       metasync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

// Composer classmap autoloader — loads all plugin classes on demand.
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
$metasync_version = '2.6.5';
define('METASYNC_VERSION', preg_match('/^\d+\.\d+/', $metasync_version) ? $metasync_version : '9.9.9');
/**
 * Define the current required php version 
 * This will be used to validate whether the user can install the plugin or not
 */
define('METASYNC_MIN_PHP', '8.2');

/**
 * Define the current required php version 
 * This will be used to validate whether the user can install the plugin or not
 */
define('METASYNC_MIN_WP', '5.2');

/**
 * Telemetry Configuration Constants
 * These replace the old database options for better security and consistency
 */
define('METASYNC_SENTRY_PROJECT_ID', '4509950439849985');
define('METASYNC_SENTRY_ENVIRONMENT', 'production');
define('METASYNC_SENTRY_RELEASE', METASYNC_VERSION);
define('METASYNC_SENTRY_SAMPLE_RATE', 1.0);

/**
 * GA4 Analytics Configuration
 * Measurement ID for Google Analytics 4 event tracking (format: G-XXXXXXXX)
 *
 * IMPORTANT: This constant is REQUIRED for GA4 tracking to function.
 * If not defined or empty, all GA4 analytics tracking will be disabled.
 *
 * GA4_API_SECRET is required for server-side Measurement Protocol events
 * (Content Genius, OTTO optimization). Generate it in GA4:
 * Admin → Data Streams → (stream) → Measurement Protocol → Create
 */
define('METASYNC_GA4_MEASUREMENT_ID', 'G-SBLWW1EMTJ');
define('METASYNC_GA4_API_SECRET', 'nMGs22mxQ3qVUy-aInqfZA');

/**
 * Define whether to show the plugin status in WordPress admin top navigation bar
 * Set to false to hide the status indicator
 */
define('METASYNC_SHOW_ADMIN_BAR_STATUS', true);

/**
 * Sanitize POST/GET/REQUEST data recursively
 * 
 * @param array $data Data to sanitize
 * @return array Sanitized data
 */
if (!function_exists('metasync_sanitize_input_array')) {
	function metasync_sanitize_input_array($data) {
		if (!is_array($data)) {
			return sanitize_text_field($data);
		}

		$sanitized = [];
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$sanitized[$key] = metasync_sanitize_input_array($value);
			} else {
				// Check if it's a URL
				if (filter_var($value, FILTER_VALIDATE_URL)) {
					$sanitized[$key] = esc_url_raw($value);
				} else {
					$sanitized[$key] = sanitize_text_field($value);
				}
			}
		}
		return $sanitized;
	}
}

// Skip heavy MetaSync init on admin-ajax requests that don't target our own actions (Sentry issue 7441226449 / WP-227).
if (!function_exists('metasync_is_non_metasync_admin_ajax')) {
	function metasync_is_non_metasync_admin_ajax() {
		static $result = null;
		if ($result !== null) {
			return $result;
		}
		if (!defined('DOING_AJAX') || !DOING_AJAX) {
			return ($result = false);
		}
		$action = isset($_POST['action']) ? sanitize_text_field(wp_unslash($_POST['action']))
				: (isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '');
		if ($action === '') {
			return ($result = true);
		}
		if (strpos($action, 'metasync_') === 0 || strpos($action, 'meta_sync_') === 0 || $action === 'sample-permalink') {
			return ($result = false);
		}
		return ($result = true);
	}
}

// Phase 2 — these files have file-level side effects (add_action outside class body)
// and must remain as explicit require_once until refactored.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-metasync-api-backoff-rest.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-metasync-error-logger.php';

/**
 * Include the Otto Pixel Php Code
 */
if (!metasync_is_non_metasync_admin_ajax()) {
    require_once plugin_dir_path( __FILE__ ) . '/otto/otto_pixel.php';
}


/**
 * Initialize OTTO Persistence Settings (registers REST API endpoints)
 */
add_action('init', function() {
    if (metasync_is_non_metasync_admin_ajax()) {
        return;
    }
    Metasync_Otto_Persistence_Settings::init();
}, 5);

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-metasync-activator.php
 */
function activate_metasync()
{
    # Include WordPress plugin functions to access plugin metadata
    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    # Get plugin data
    $plugin_data = get_plugin_data(__FILE__);
	
	# Get the plugin name
    $plugin_name = $plugin_data['Name']; 

    # Get the current WordPress
    global $wp_version;

	# Get the current php version
    $php_version = PHP_VERSION;

    # Check for incompatible WordPress version
    if (version_compare($wp_version, METASYNC_MIN_WP, '<')) {
		
		#show error
		wp_die(

			#craft the message
            sprintf(
                '%s requires WordPress version %s or later. You are currently using version %s. Please update WordPress to activate this plugin.',
                esc_html($plugin_name),
                METASYNC_MIN_WP,
                esc_html($wp_version)
            ),

			#the plugin title as page title
            esc_html($plugin_name).'Plugin Activation Error',

			#include the back link
            ['back_link' => true]
        );
    }

    # Check for incompatible PHP version
    if (version_compare($php_version, METASYNC_MIN_PHP, '<')) {
        
		#show error message
		wp_die(

			#craft the message
            sprintf(
                '%s requires PHP version %s or later. You are currently using version %s. Please update PHP to activate this plugin.',
                esc_html($plugin_name),
                METASYNC_MIN_PHP,
                esc_html($php_version)
            ),

			#the plugin title as page title
            esc_html($plugin_name).'Plugin Activation Error',

			#include the back link
            ['back_link' => true]
        );
    }

	Metasync_Activator::activate();
    // class name is changed at class-db-migrations.php
	MetaSync_DBMigration::activation();
	
	// Set initial version
	update_option('metasync_version', METASYNC_VERSION);
	
	// Clear all cache plugins to ensure fresh start
	Metasync_Cache_Purge::purge_all('plugin_activation');
}

// Log-sync removed - error monitoring now handled by Sentry
// require_once plugin_dir_path(__FILE__) . 'log-sync/log-sync.php';

/**
 * Initialize telemetry system for error monitoring and Sentry integration
 */
if (!metasync_is_non_metasync_admin_ajax()) {
    require_once plugin_dir_path(__FILE__) . 'telemetry/telemetry-init.php';
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-metasync-deactivator.php
 */
function deactivate_metasync()
{
	Metasync_Deactivator::deactivate();
    // class name is changed at class-db-migrations.php
	MetaSync_DBMigration::deactivation();

	// Unschedule the sync log cleanup cron to avoid orphaned events.
	$timestamp = wp_next_scheduled('metasync_sync_log_daily_cleanup');
	if ($timestamp) {
		wp_unschedule_event($timestamp, 'metasync_sync_log_daily_cleanup');
	}

	// Clear news/video sitemap caches
	delete_transient('metasync_vsm_' . md5('news-sitemap.xml'));
	delete_transient('metasync_vsm_' . md5('video-sitemap.xml'));
	delete_option('metasync_sitemap_virtual_index');
}

register_activation_hook(__FILE__, 'activate_metasync');
register_deactivation_hook(__FILE__, 'deactivate_metasync');

/**
 * Check for plugin updates and run migrations if needed
 */
function check_metasync_updates()
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $current_version = get_option('metasync_version', '0.0.0');
    $plugin_version = METASYNC_VERSION;
    
    // If versions don't match, run migration
    if (version_compare($current_version, $plugin_version, '<')) {
        // Import whitelabel settings only if the JSON file is new or changed
        // (prevents overwriting admin UI changes on every version check)
        Metasync_Activator::check_whitelabel_settings_update();

        // Run version-specific migrations first
        MetaSync_DBMigration::run_version_migrations($current_version, $plugin_version);

        // Migration for v2.7.0+: Remove AI Agent, switch to plugin auth token, make MCP always-on
        if (version_compare($current_version, '2.7.0', '<')) {
            // Remove old MCP API key option
            delete_option('metasync_mcp_api_key');

            // Remove MCP enabled/disabled toggle option (MCP is now always enabled)
            delete_option('metasync_mcp_enabled');

            // Remove AI Agent settings (AI Agent has been removed)
            delete_option('metasync_ai_agent_mcp_config');
            delete_option('metasync_ai_agent_ai_config');
            delete_option('metasync_ai_agent_enabled');

            // Ensure plugin auth token exists
            $options = get_option('metasync_options', []);
            if (empty($options['general']['apikey'])) {
                $options['general']['apikey'] = wp_generate_password(32, false, false);
                update_option('metasync_options', $options);
            }
        }

        // Run full migration to ensure all tables are up to date
        MetaSync_DBMigration::run_migrations();
        
        // Update stored version
        update_option('metasync_version', $plugin_version);
        
        // Clear all cache plugins after update
        Metasync_Cache_Purge::purge_all('plugin_update');
        
        // Log the update
        //error_log("MetaSync: Plugin updated from {$current_version} to {$plugin_version}. Database migration completed.");
    }
}

// Hook into WordPress init to check for updates
add_action('init', 'check_metasync_updates', 1);

/**
 * Handle whitelabel settings import after plugin is updated via WordPress admin
 * This hook fires when plugins are installed/updated through the WordPress updater
 *
 * @param WP_Upgrader $upgrader WP_Upgrader instance
 * @param array $hook_extra Extra arguments passed to hooked filters
 */
function metasync_handle_plugin_upgrade($upgrader, $hook_extra)
{
    // Only process plugin updates/installs
    if (!isset($hook_extra['type']) || $hook_extra['type'] !== 'plugin') {
        return;
    }

    // Only process install and update actions
    if (!isset($hook_extra['action']) || !in_array($hook_extra['action'], ['install', 'update'], true)) {
        return;
    }

    $this_plugin = plugin_basename(__FILE__);
    $this_plugin_slug = dirname($this_plugin); // Get 'metasync' from 'metasync/metasync.php'
    $should_import = false;

    // Handle bulk updates
    if (isset($hook_extra['bulk']) && $hook_extra['bulk'] === true && isset($hook_extra['plugins'])) {
        foreach ($hook_extra['plugins'] as $plugin) {
            // Match by exact path OR by plugin slug/directory
            if ($plugin === $this_plugin || dirname($plugin) === $this_plugin_slug) {
                $should_import = true;
                break;
            }
        }
    }

    // Handle single plugin update/install
    if (isset($hook_extra['plugin'])) {
        $plugin = $hook_extra['plugin'];
        // Match by exact path OR by plugin slug/directory
        if ($plugin === $this_plugin || dirname($plugin) === $this_plugin_slug) {
            $should_import = true;
        }
    }

    // SPECIAL CASE: When uploading plugin via "Add New > Upload Plugin",
    // WordPress doesn't set the 'plugin' parameter during 'install' action.
    // Check if we can get the plugin info from the upgrader result or whitelabel file exists.
    if (!$should_import && $hook_extra['action'] === 'install') {
        // Check upgrader result for destination
        if (isset($upgrader->result) && isset($upgrader->result['destination'])) {
            $destination = $upgrader->result['destination'];
            // Check if destination contains our plugin slug
            if (strpos($destination, $this_plugin_slug) !== false) {
                $should_import = true;
            }
        }

        // Fallback: Check if whitelabel file exists in our plugin directory
        // This means our plugin was just installed/updated with whitelabel settings
        if (!$should_import) {
            $whitelabel_file = Metasync_Activator::get_whitelabel_settings_file();
            if ($whitelabel_file !== false) {
                $should_import = true;
            }
        }
    }

    if ($should_import) {
        Metasync_Activator::check_whitelabel_settings_update();
    }
}

// Hook into WordPress upgrader to detect plugin updates
add_action('upgrader_process_complete', 'metasync_handle_plugin_upgrade', 10, 2);

/**
 * Fallback: Check for whitelabel file changes on admin pages
 * This handles edge cases where upgrader_process_complete doesn't fire
 * (e.g., FTP uploads, manual file replacements)
 * Only checks once per admin session to minimize performance impact
 */
// function metasync_check_whitelabel_on_admin()
// {
//     // Only check once per admin session to avoid overhead
//     static $checked = false;
//     if ($checked) {
//         return;
//     }
//     $checked = true;

//     require_once plugin_dir_path(__FILE__) . 'includes/class-metasync-activator.php';
//     Metasync_Activator::check_whitelabel_settings_update();
// }

// Check for whitelabel changes on admin pages (fallback for edge cases)
// add_action('admin_init', 'metasync_check_whitelabel_on_admin', 1);

// Include Media Optimization Module
if (!metasync_is_non_metasync_admin_ajax()) {
    require_once plugin_dir_path(__FILE__) . 'media-optimization/media-optimization-loader.php';
}

// Include Code Minification & Delivery Module
if (!metasync_is_non_metasync_admin_ajax()) {
    require_once plugin_dir_path(__FILE__) . 'code-minification/code-minification-loader.php';
}


function run_metasync()
{
	$plugin = new Metasync();
	$plugin->run();
}
run_metasync();

/**
 * Initialize WordPress MCP Server
 *
 * Creates and configures the MCP server instance,
 * registers all available MCP tools for WordPress operations.
 * Hooked to 'init' for proper WordPress lifecycle integration.
 */
function metasync_init_mcp_server() {
	if (metasync_is_non_metasync_admin_ajax()) {
		return;
	}
	// Initialize MCP server
	global $metasync_mcp_server;

	try {
		$metasync_mcp_server = new Metasync_MCP_Server();

		// Attach MCP sync logger so all write tool calls are recorded in Sync History.
		new Metasync_MCP_Sync_Logger();

		// SEO Inventory: shared builder + standalone REST endpoint (WP-135)
		new Metasync_REST_SEO_Inventory();
	} catch (Exception $e) {
		error_log('MCP Server Initialization Error: ' . $e->getMessage());
		return;
	}

	// Helper: register a single tool, logging failures without aborting subsequent registrations
	$safe_register = function($tool) use ($metasync_mcp_server) {
		try {
			$metasync_mcp_server->register_tool($tool);
		} catch (Exception $e) {
			error_log('MetaSync MCP: Failed to register tool ' . get_class($tool) . ': ' . $e->getMessage());
		}
	};

	// Register MCP Tools (Total: 92 existing + 8 new = 100 tools total!)
	// NEW in v2.8.0: +4 Taxonomy Meta tools, +4 Bulk Alt Text tools

	// Post Meta Operations (4 tools)
	$safe_register(new MCP_Tool_Update_Post_Meta());
	$safe_register(new MCP_Tool_Get_Post_Meta());
	$safe_register(new MCP_Tool_Get_SEO_Meta());
	$safe_register(new MCP_Tool_Get_Hreflang_Links());

	// Post Operations (4 tools)
	$safe_register(new MCP_Tool_Get_Post());
	$safe_register(new MCP_Tool_Get_Post_By_URL());
	$safe_register(new MCP_Tool_List_Posts());
	$safe_register(new MCP_Tool_Update_Post());
	$safe_register(new MCP_Tool_Get_Post_Types());

	// SEO Analysis (3 tools)
	$safe_register(new MCP_Tool_Analyze_SEO());
	$safe_register(new MCP_Tool_Check_Indexability());
	$safe_register(new MCP_Tool_SEO_Health_Report());

	// Search Operations (3 tools)
	$safe_register(new MCP_Tool_Search_Posts());
	$safe_register(new MCP_Tool_Search_By_Keyword());
	$safe_register(new MCP_Tool_Find_Missing_Meta());

	// Redirect Management (5 tools)
	$safe_register(new MCP_Tool_Create_Redirect());
	$safe_register(new MCP_Tool_List_Redirects());
	$safe_register(new MCP_Tool_Delete_Redirect());
	$safe_register(new MCP_Tool_Update_Redirect());
	$safe_register(new MCP_Tool_Check_Redirects_Health());

	// 404 Error Monitoring (5 tools)
	$safe_register(new MCP_Tool_List_404_Errors());
	$safe_register(new MCP_Tool_Get_404_Stats());
	$safe_register(new MCP_Tool_Delete_404_Error());
	$safe_register(new MCP_Tool_Clear_404_Errors());
	$safe_register(new MCP_Tool_Create_Redirect_From_404());

	// Robots.txt & Sitemap Management (11 tools - 7 existing + 4 new)
	$safe_register(new MCP_Tool_Get_Robots_Txt());
	$safe_register(new MCP_Tool_Update_Robots_Txt());
	$safe_register(new MCP_Tool_Get_Sitemap_Status());
	$safe_register(new MCP_Tool_Regenerate_Sitemap());
	$safe_register(new MCP_Tool_Exclude_From_Sitemap());
	$safe_register(new MCP_Tool_Add_Robots_Rule());
	$safe_register(new MCP_Tool_Remove_Robots_Rule());
	$safe_register(new MCP_Tool_Parse_Robots_Txt());
	$safe_register(new MCP_Tool_Validate_Robots_Txt());
	$safe_register(new MCP_Tool_Get_News_Sitemap());
	$safe_register(new MCP_Tool_Get_Video_Sitemap());

	// Plugin Settings Management (4 tools)
	$safe_register(new MCP_Tool_Get_Plugin_Settings());
	$safe_register(new MCP_Tool_Update_Plugin_Settings());
	$safe_register(new MCP_Tool_List_Plugin_Settings_Schema());
	$safe_register(new MCP_Tool_Get_MCP_Settings());

	// Schema Markup Management (7 tools)
	$safe_register(new MCP_Tool_Get_Schema_Markup());
	$safe_register(new MCP_Tool_Update_Schema_Markup());
	$safe_register(new MCP_Tool_Add_Schema_Type());
	$safe_register(new MCP_Tool_Remove_Schema_Type());
	$safe_register(new MCP_Tool_Validate_Schema());
	$safe_register(new MCP_Tool_Get_Schema_Content());
	$safe_register(new MCP_Tool_Set_Schema_Content());

	// Google Instant Index (6 tools)
	$safe_register(new MCP_Tool_Instant_Index_Update());
	$safe_register(new MCP_Tool_Instant_Index_Delete());
	$safe_register(new MCP_Tool_Instant_Index_Status());
	$safe_register(new MCP_Tool_Instant_Index_Bulk_Update());
	$safe_register(new MCP_Tool_Get_Instant_Index_Settings());
	$safe_register(new MCP_Tool_Update_Instant_Index_Settings());

	// Custom HTML Pages (5 tools)
	$safe_register(new MCP_Tool_Create_Custom_Page());
	$safe_register(new MCP_Tool_Get_Custom_Page());
	$safe_register(new MCP_Tool_List_Custom_Pages());
	$safe_register(new MCP_Tool_Update_Custom_Page());
	$safe_register(new MCP_Tool_Delete_Custom_Page());

	// HTML to Builder Converter (3 tools)
	$safe_register(new MCP_Tool_Convert_HTML_To_Builder());
	$safe_register(new MCP_Tool_Create_Builder_Page_From_HTML());
	$safe_register(new MCP_Tool_Convert_Custom_Page_To_Builder());

	// Code Snippets (6 tools)
	$safe_register(new MCP_Tool_Get_Header_Snippet());
	$safe_register(new MCP_Tool_Update_Header_Snippet());
	$safe_register(new MCP_Tool_Get_Footer_Snippet());
	$safe_register(new MCP_Tool_Update_Footer_Snippet());
	$safe_register(new MCP_Tool_Get_Post_Snippets());
	$safe_register(new MCP_Tool_Update_Post_Snippets());

	// Categories & Taxonomies (15 tools)
	$safe_register(new MCP_Tool_List_Categories());
	$safe_register(new MCP_Tool_Get_Category());
	$safe_register(new MCP_Tool_Create_Category());
	$safe_register(new MCP_Tool_Update_Category());
	$safe_register(new MCP_Tool_Delete_Category());
	$safe_register(new MCP_Tool_Get_Post_Categories());
	$safe_register(new MCP_Tool_Set_Post_Categories());

	// Tags (8 tools)
	$safe_register(new MCP_Tool_List_Tags());
	$safe_register(new MCP_Tool_Get_Tag());
	$safe_register(new MCP_Tool_Create_Tag());
	$safe_register(new MCP_Tool_Update_Tag());
	$safe_register(new MCP_Tool_Delete_Tag());
	$safe_register(new MCP_Tool_Get_Post_Tags());
	$safe_register(new MCP_Tool_Set_Post_Tags());

	// Featured Images & Media (6 tools)
	$safe_register(new MCP_Tool_Get_Featured_Image());
	$safe_register(new MCP_Tool_Set_Featured_Image());
	$safe_register(new MCP_Tool_Upload_Featured_Image());
	$safe_register(new MCP_Tool_Remove_Featured_Image());
	$safe_register(new MCP_Tool_List_Media());
	$safe_register(new MCP_Tool_Get_Media_Details());

	// Post CRUD Operations (1 tool - delete/restore disabled for safety)
	$safe_register(new MCP_Tool_Create_Post());
	// $safe_register(new MCP_Tool_Delete_Post()); // DISABLED - safety
	// $safe_register(new MCP_Tool_Restore_Post()); // DISABLED - safety

	// Bulk Operations (3 tools - bulk delete disabled for safety)
	$safe_register(new MCP_Tool_Bulk_Update_Meta());
	$safe_register(new MCP_Tool_Bulk_Set_Categories());
	$safe_register(new MCP_Tool_Bulk_Change_Status());
	// $safe_register(new MCP_Tool_Bulk_Delete_Posts()); // DISABLED - safety

	// WordPress Core SEO Settings (10 tools)
	$safe_register(new MCP_Tool_Get_Site_Info());
	$safe_register(new MCP_Tool_Update_Site_Info());
	$safe_register(new MCP_Tool_Get_Permalink_Structure());
	$safe_register(new MCP_Tool_Update_Permalink_Structure());
	$safe_register(new MCP_Tool_Get_Reading_Settings());
	$safe_register(new MCP_Tool_Update_Reading_Settings());
	$safe_register(new MCP_Tool_Get_Search_Visibility());
	$safe_register(new MCP_Tool_Update_Search_Visibility());
	$safe_register(new MCP_Tool_Get_Date_Format());
	$safe_register(new MCP_Tool_Get_Discussion_Settings());

	// Taxonomy Meta Operations (4 tools - NEW in v2.8.0)
	$safe_register(new MCP_Tool_Get_Term_Meta());
	$safe_register(new MCP_Tool_Update_Term_Meta());
	$safe_register(new MCP_Tool_Bulk_Update_Term_Meta());
	$safe_register(new MCP_Tool_List_Terms_With_Meta());

	// Bulk Alt Text Operations (4 tools - NEW in v2.8.0)
	$safe_register(new MCP_Tool_Audit_Alt_Text());
	$safe_register(new MCP_Tool_Bulk_Update_Alt_Text());
	$safe_register(new MCP_Tool_Generate_Alt_Text());
	$safe_register(new MCP_Tool_Validate_Alt_Text());

	// OTTO Persistence Settings (2 tools)
	if (class_exists('MCP_Tool_Get_Otto_Persistence_Settings')) {
		$safe_register(new MCP_Tool_Get_Otto_Persistence_Settings());
	}
	if (class_exists('MCP_Tool_Update_Otto_Persistence_Settings')) {
		$safe_register(new MCP_Tool_Update_Otto_Persistence_Settings());
	}

	// OTTO Pipeline Tools (3 tools)
	if (class_exists('MCP_Tool_Trigger_Otto_Optimization')) {
		$safe_register(new MCP_Tool_Trigger_Otto_Optimization());
	}
	if (class_exists('MCP_Tool_Get_Otto_Status')) {
		$safe_register(new MCP_Tool_Get_Otto_Status());
	}
	if (class_exists('MCP_Tool_Verify_SEO_Output')) {
		$safe_register(new MCP_Tool_Verify_SEO_Output());
	}

	// System Diagnostics & Plugin Info (4 tools)
	$safe_register(new MCP_Tool_System_Diagnostics());
	$safe_register(new MCP_Tool_List_All_Plugins());
	$safe_register(new MCP_Tool_Get_Cron_Jobs());
	$safe_register(new MCP_Tool_Get_WP_Option());

	// SEO Inventory (1 tool — WP-135)
	$safe_register(new MCP_Tool_List_Posts_SEO_Inventory());

	// Read-Only Database Access (3 tools)
	$safe_register(new MCP_Tool_DB_Tables());
	$safe_register(new MCP_Tool_DB_Describe());
	$safe_register(new MCP_Tool_DB_Select());

	// Breadcrumb Tools (1 tool)
	$safe_register(new MCP_Tool_Get_Breadcrumb_Path());

	// Cache Purge (2 tools)
	if (class_exists('MCP_Tool_Cache_Purge_All')) {
		$safe_register(new MCP_Tool_Cache_Purge_All());
	} else {
		error_log('MetaSync MCP: MCP_Tool_Cache_Purge_All class not found — skipping registration. Run composer dump-autoload to regenerate the classmap.');
	}
	if (class_exists('MCP_Tool_Cache_Purge_URL')) {
		$safe_register(new MCP_Tool_Cache_Purge_URL());
	} else {
		error_log('MetaSync MCP: MCP_Tool_Cache_Purge_URL class not found — skipping registration. Run composer dump-autoload to regenerate the classmap.');
	}

	// LLMs.txt Tools (5 tools)
	$safe_register(new MCP_Tool_Get_LLMs_Txt());
	$safe_register(new MCP_Tool_Regenerate_LLMs_Txt());
	$safe_register(new MCP_Tool_Get_LLMs_Txt_Settings());
	$safe_register(new MCP_Tool_Update_LLMs_Txt_Settings());
	$safe_register(new MCP_Tool_Get_Post_Markdown());

	// SEO Plugin Audit (4 tools — WP-202)
	$safe_register(new MCP_Tool_Read_SEO_Plugin_Data());
	$safe_register(new MCP_Tool_SEO_Plugin_Diff());
	if (class_exists('Metasync_Plugin_Sync')) {
		$safe_register(new MCP_Tool_Sync_To_Active_Plugins());
	}
	$safe_register(new MCP_Tool_Detect_SEO_Conflicts());

	// Allow other plugins/themes to register tools
	do_action('metasync_mcp_register_tools', $metasync_mcp_server);
}
add_action('init', 'metasync_init_mcp_server', 5);

/**
 * Schedule a daily cron event to auto-purge Sync History records older than 90 days.
 */
function metasync_schedule_sync_log_cleanup() {
	if (!wp_next_scheduled('metasync_sync_log_daily_cleanup')) {
		wp_schedule_event(time(), 'daily', 'metasync_sync_log_daily_cleanup');
	}
}
add_action('wp', 'metasync_schedule_sync_log_cleanup');

/**
 * Cron callback: delete Sync History records older than 90 days.
 */
function metasync_sync_log_cleanup_handler() {
	$sync_db = new Metasync_Sync_History_Database();
	$sync_db->delete_older_than_days(90);
}
add_action('metasync_sync_log_daily_cleanup', 'metasync_sync_log_cleanup_handler');

/**
 * Output DYO initialization flag to the frontend
 * Makes window.__SA_DYO_INITIALIZED__ = true available in the DOM
 * This indicates the Search Atlas plugin is active and initialized
 */
function metasync_output_dyo_init_flag() {
	echo '<script>window.__SA_DYO_INITIALIZED__=true;</script>' . "\n";
}
add_action('wp_head', 'metasync_output_dyo_init_flag', 1);

/**
 * Initialize GA4 Analytics for Admin Area
 * Hooked to admin_init for proper WordPress lifecycle integration
 * Only loads after WordPress, plugins, and themes are fully loaded
 */
function metasync_init_analytics() {
	// Only initialize in admin area (excludes AJAX and REST API requests)
	if (is_admin() && !wp_doing_ajax() && !defined('REST_REQUEST')) {
		Metasync_GA4::get_instance();
	}
}
add_action('admin_init', 'metasync_init_analytics', 10);

/**
 * Initialize API Backoff System
 * Hooked to init for proper WordPress lifecycle integration
 * Monitors API responses and manages exponential backoff for rate limiting
 * @since 2.7.1
 */
function metasync_init_api_backoff() {
	if (metasync_is_non_metasync_admin_ajax()) {
		return;
	}
	// Initialize backoff manager (registers HTTP response filters)
	Metasync_API_Backoff_Manager::get_instance();

	// Initialize admin notices (only in admin area)
	if (is_admin()) {
		Metasync_API_Backoff_Notices::get_instance();
	}
}
add_action('init', 'metasync_init_api_backoff', 5);

/**
 * Initialize Review Notice
 * Shows a dismissible notice asking users to rate the plugin after a usage period
 * @since 2.8.0
 */
function metasync_init_review_notice() {
	if (metasync_is_non_metasync_admin_ajax()) {
		return;
	}
	if (is_admin()) {
		Metasync_Review_Notice::get_instance();
	}
}
add_action('init', 'metasync_init_review_notice');

/**
 * Global convenience function to get active JWT token
 * Can be called from anywhere in WordPress (themes, other plugins, etc.)
 * 
 * @param bool $force_refresh Force generation of new token even if cached one exists
 * @return string|false JWT token on success, false on failure
 */
if (!function_exists('metasync_get_jwt_token')) {
	function metasync_get_jwt_token($force_refresh = false)
	{
		return Metasync::get_jwt_token($force_refresh);
	}
}


/**
 * Initialize Debug Mode Manager
 * Hooked to 'init' for proper WordPress lifecycle integration
 */
function metasync_init_debug_mode_manager() {
	if (metasync_is_non_metasync_admin_ajax()) {
		return;
	}
	// Initialize the Debug Mode Manager singleton
	Metasync_Debug_Mode_Manager::get_instance();
}
add_action('init', 'metasync_init_debug_mode_manager', 10);

/**
 * Oxygen Builder Compatibility
 * Auto re-signs [oxygen] dynamic-data shortcodes when their HMAC signatures
 * are invalid (e.g. after design-set import or site migration).
 * Runs once on admin_init; skips entirely when Oxygen is inactive.
 */
if (is_admin()) {
	add_action('admin_init', ['Metasync_Oxygen_Compat', 'maybe_resign_shortcodes'], 20);
}

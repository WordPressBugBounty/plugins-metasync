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
 * Version:           2.5.20 
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

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
$metasync_version = '2.5.20';
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
 * Mixpanel Analytics Configuration
 * Project token for usage tracking 
 *
 * IMPORTANT: This constant is REQUIRED for Mixpanel tracking to function.
 * If not defined or empty, all analytics tracking will be disabled.
 *
 * To disable tracking: Comment out this line or set to empty string
 */

define('METASYNC_MIXPANEL_TOKEN', '90374a20c197bd2eb8312e0706e3b458');

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
if (!function_exists('sanitize_post')) {
	function sanitize_post($data) {
		if (!is_array($data)) {
			return sanitize_text_field($data);
		}
		
		$sanitized = [];
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$sanitized[$key] = sanitize_post($value);
			} else {
				// Check if it's a URL
				if (filter_var($value, FILTER_VALIDATE_URL)) {
					$sanitized[$key] = sanitize_url($value);
				} else {
					$sanitized[$key] = sanitize_text_field($value);
				}
			}
		}
		return $sanitized;
	}
}

/**
 * Include the Redirection class early (provides regex pattern utilities used across the plugin)
 */
require_once plugin_dir_path( __FILE__ ) . 'redirections/class-metasync-redirection.php';

/**
 * Centralized class loading function
 */
function metasync_load_class($class_name) {
    $class_map = [
        'Metasync_Sync_History_Database' => 'sync-history/class-metasync-sync-history-database.php',
        //'Metasync_Admin' => 'admin/class-metasync-admin.php',
        //'Metasync_Public' => 'public/class-metasync-public.php',
        //'Metasync_Activator' => 'includes/class-metasync-activator.php',
        //'MetaSync_DBMigration' => 'database/class-db-migrations.php',
        // Add more classes as needed
    ];
    
    if (isset($class_map[$class_name])) {
        $file_path = plugin_dir_path(__FILE__) . $class_map[$class_name];
        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
}

// Register the autoloader
spl_autoload_register('metasync_load_class');

/**
 * Include the Session Helper (must load before other classes that use it)
 * @deprecated 2.5.12 - Kept for backward compatibility only
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-metasync-session-helper.php';

/**
 * Include the Auth Manager (WordPress-native authentication without sessions)
 * @since 2.5.12
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-metasync-auth-manager.php';

/**
 * Include the Cache Purge Handler
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-metasync-cache-purge.php';

/**
 * Include the API Backoff Manager
 * Handles exponential backoff for HTTP 429/503 responses
 * @since 2.7.1
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-metasync-api-backoff-manager.php';

/**
 * Include the API Backoff Admin Notices
 * Displays admin notices when API endpoints are in backoff mode
 * @since 2.7.1
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-metasync-api-backoff-notices.php';

/**
 * Include the API Backoff REST API
 * Provides REST endpoints for backoff management and monitoring
 * @since 2.7.1
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-metasync-api-backoff-rest.php';

/**
 * Include the Otto Pixel Php Code
 */
require_once plugin_dir_path( __FILE__ ) . '/otto/otto_pixel.php';

/**
 * Include the Otto Persistence Settings
 * Handles configuration for which OTTO data should be saved to native WordPress fields
 */
require_once plugin_dir_path( __FILE__ ) . '/otto/class-metasync-otto-persistence-settings.php';

/**
 * Include the Otto Persistence Handler
 * Handles actual persistence of OTTO data to native WordPress fields
 */
require_once plugin_dir_path( __FILE__ ) . '/otto/class-metasync-otto-persistence-handler.php';

/**
 * Initialize OTTO Persistence Settings (registers REST API endpoints)
 */
add_action('init', function() {
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

	require_once plugin_dir_path(__FILE__) . 'includes/class-metasync-activator.php';
	require_once plugin_dir_path(__FILE__) . 'database/class-db-migrations.php';
	Metasync_Activator::activate();
    // class name is changed at class-db-migrations.php
	MetaSync_DBMigration::activation();
	
	// Set initial version
	update_option('metasync_version', METASYNC_VERSION);
	
	// Clear all cache plugins to ensure fresh start
	try {
		$cache_purge = Metasync_Cache_Purge::get_instance();
		$cache_purge->clear_all_caches('plugin_activation');
	} catch (Exception $e) {
		error_log('MetaSync: Cache purge failed on activation - ' . $e->getMessage());
	}
}

// Log-sync removed - error monitoring now handled by Sentry
// require_once plugin_dir_path(__FILE__) . 'log-sync/log-sync.php';

/**
 * Initialize telemetry system for error monitoring and Sentry integration
 */
require_once plugin_dir_path(__FILE__) . 'telemetry/telemetry-init.php';

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-metasync-deactivator.php
 */
function deactivate_metasync()
{
	require_once plugin_dir_path(__FILE__) . 'includes/class-metasync-deactivator.php';
	require_once plugin_dir_path(__FILE__) . 'database/class-db-migrations.php';
	Metasync_Deactivator::deactivate();
    // class name is changed at class-db-migrations.php
	MetaSync_DBMigration::deactivation();
}

register_activation_hook(__FILE__, 'activate_metasync');
register_deactivation_hook(__FILE__, 'deactivate_metasync');

/**
 * Check for plugin updates and run migrations if needed
 */
function check_metasync_updates()
{
    $current_version = get_option('metasync_version', '0.0.0');
    $plugin_version = METASYNC_VERSION;
    
    // If versions don't match, run migration
    if (version_compare($current_version, $plugin_version, '<')) {
        require_once plugin_dir_path(__FILE__) . 'includes/class-metasync-activator.php';
        require_once plugin_dir_path(__FILE__) . 'database/class-db-migrations.php';

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
        try {
            $cache_purge = Metasync_Cache_Purge::get_instance();
            $cache_purge->clear_all_caches('plugin_update');
        } catch (Exception $e) {
            error_log('MetaSync: Cache purge failed on update - ' . $e->getMessage());
        }
        
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
            require_once plugin_dir_path(__FILE__) . 'includes/class-metasync-activator.php';
            $whitelabel_file = Metasync_Activator::get_whitelabel_settings_file();
            if ($whitelabel_file !== false) {
                $should_import = true;
            }
        }
    }

    if ($should_import) {
        require_once plugin_dir_path(__FILE__) . 'includes/class-metasync-activator.php';
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

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path(__FILE__) . 'admin/class-metasync-admin.php';

// Include OTTO Debug class for developers
require plugin_dir_path(__FILE__) . 'admin/class-metasync-otto-debug.php';

// Include OTTO MCP Integration class for direct MCP tool access
require plugin_dir_path(__FILE__) . 'otto/class-metasync-otto-mcp-integration.php';

// Include WordPress MCP Server for Model Context Protocol support
require plugin_dir_path(__FILE__) . 'wp-mcp-server/class-metasync-mcp-server.php';

// Include Mixpanel Analytics Integration
require plugin_dir_path(__FILE__) . 'includes/class-metasync-mixpanel.php';

try {
	require plugin_dir_path(__FILE__) . 'includes/class-metasync.php';
} catch (Exception $e) {
	
    # Log into the default PHP error log and trigger error
    error_log($e->getMessage());
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
	// Initialize MCP server
	global $metasync_mcp_server;
	$metasync_mcp_server = new Metasync_MCP_Server();

	// Load tool classes
	$tool_path = plugin_dir_path(__FILE__) . 'wp-mcp-server/tools/';
	require_once $tool_path . 'class-mcp-tool-post-meta.php';
	require_once $tool_path . 'class-mcp-tool-posts.php';
	require_once $tool_path . 'class-mcp-tool-seo-analysis.php';
	require_once $tool_path . 'class-mcp-tool-search.php';
	require_once $tool_path . 'class-mcp-tool-redirects.php';
	require_once $tool_path . 'class-mcp-tool-404-monitor.php';
	require_once $tool_path . 'class-mcp-tool-robots-sitemap.php';
	require_once $tool_path . 'class-mcp-tool-plugin-settings.php';
	require_once $tool_path . 'class-mcp-tool-schema-markup.php';
	require_once $tool_path . 'class-mcp-tool-instant-index.php';
	require_once $tool_path . 'class-mcp-tool-custom-pages.php';
	require_once $tool_path . 'class-mcp-tool-html-converter.php';
	require_once $tool_path . 'class-mcp-tool-code-snippets.php';
	require_once $tool_path . 'class-mcp-tool-taxonomies.php';
	require_once $tool_path . 'class-mcp-tool-taxonomy-meta.php';
	require_once $tool_path . 'class-mcp-tool-media.php';
	require_once $tool_path . 'class-mcp-tool-bulk-alt-text.php';
	require_once $tool_path . 'class-mcp-tool-post-crud.php';
	require_once $tool_path . 'class-mcp-tool-bulk-operations.php';
	require_once $tool_path . 'class-mcp-tool-wordpress-settings.php';

	try {
		// Register MCP Tools (Total: 92 existing + 8 new = 100 tools total!)
		// NEW in v2.8.0: +4 Taxonomy Meta tools, +4 Bulk Alt Text tools

		// Post Meta Operations (3 tools)
		$metasync_mcp_server->register_tool(new MCP_Tool_Update_Post_Meta());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Post_Meta());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_SEO_Meta());

		// Post Operations (4 tools)
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Post());
		$metasync_mcp_server->register_tool(new MCP_Tool_List_Posts());
		$metasync_mcp_server->register_tool(new MCP_Tool_Update_Post());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Post_Types());

		// SEO Analysis (2 tools)
		$metasync_mcp_server->register_tool(new MCP_Tool_Analyze_SEO());
		$metasync_mcp_server->register_tool(new MCP_Tool_Check_Indexability());

		// Search Operations (3 tools)
		$metasync_mcp_server->register_tool(new MCP_Tool_Search_Posts());
		$metasync_mcp_server->register_tool(new MCP_Tool_Search_By_Keyword());
		$metasync_mcp_server->register_tool(new MCP_Tool_Find_Missing_Meta());

		// Redirect Management (4 tools)
		$metasync_mcp_server->register_tool(new MCP_Tool_Create_Redirect());
		$metasync_mcp_server->register_tool(new MCP_Tool_List_Redirects());
		$metasync_mcp_server->register_tool(new MCP_Tool_Delete_Redirect());
		$metasync_mcp_server->register_tool(new MCP_Tool_Update_Redirect());

		// 404 Error Monitoring (5 tools)
		$metasync_mcp_server->register_tool(new MCP_Tool_List_404_Errors());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_404_Stats());
		$metasync_mcp_server->register_tool(new MCP_Tool_Delete_404_Error());
		$metasync_mcp_server->register_tool(new MCP_Tool_Clear_404_Errors());
		$metasync_mcp_server->register_tool(new MCP_Tool_Create_Redirect_From_404());

		// Robots.txt & Sitemap Management (9 tools - 5 existing + 4 new)
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Robots_Txt());
		$metasync_mcp_server->register_tool(new MCP_Tool_Update_Robots_Txt());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Sitemap_Status());
		$metasync_mcp_server->register_tool(new MCP_Tool_Regenerate_Sitemap());
		$metasync_mcp_server->register_tool(new MCP_Tool_Exclude_From_Sitemap());
		$metasync_mcp_server->register_tool(new MCP_Tool_Add_Robots_Rule());
		$metasync_mcp_server->register_tool(new MCP_Tool_Remove_Robots_Rule());
		$metasync_mcp_server->register_tool(new MCP_Tool_Parse_Robots_Txt());
		$metasync_mcp_server->register_tool(new MCP_Tool_Validate_Robots_Txt());

		// Plugin Settings Management (4 tools)
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Plugin_Settings());
		$metasync_mcp_server->register_tool(new MCP_Tool_Update_Plugin_Settings());
		$metasync_mcp_server->register_tool(new MCP_Tool_List_Plugin_Settings_Schema());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_MCP_Settings());

		// Schema Markup Management (5 tools)
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Schema_Markup());
		$metasync_mcp_server->register_tool(new MCP_Tool_Update_Schema_Markup());
		$metasync_mcp_server->register_tool(new MCP_Tool_Add_Schema_Type());
		$metasync_mcp_server->register_tool(new MCP_Tool_Remove_Schema_Type());
		$metasync_mcp_server->register_tool(new MCP_Tool_Validate_Schema());

		// Google Instant Index (6 tools)
		$metasync_mcp_server->register_tool(new MCP_Tool_Instant_Index_Update());
		$metasync_mcp_server->register_tool(new MCP_Tool_Instant_Index_Delete());
		$metasync_mcp_server->register_tool(new MCP_Tool_Instant_Index_Status());
		$metasync_mcp_server->register_tool(new MCP_Tool_Instant_Index_Bulk_Update());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Instant_Index_Settings());
		$metasync_mcp_server->register_tool(new MCP_Tool_Update_Instant_Index_Settings());

		// Custom HTML Pages (5 tools)
		$metasync_mcp_server->register_tool(new MCP_Tool_Create_Custom_Page());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Custom_Page());
		$metasync_mcp_server->register_tool(new MCP_Tool_List_Custom_Pages());
		$metasync_mcp_server->register_tool(new MCP_Tool_Update_Custom_Page());
		$metasync_mcp_server->register_tool(new MCP_Tool_Delete_Custom_Page());

		// HTML to Builder Converter (3 tools)
		$metasync_mcp_server->register_tool(new MCP_Tool_Convert_HTML_To_Builder());
		$metasync_mcp_server->register_tool(new MCP_Tool_Create_Builder_Page_From_HTML());
		$metasync_mcp_server->register_tool(new MCP_Tool_Convert_Custom_Page_To_Builder());

		// Code Snippets (6 tools)
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Header_Snippet());
		$metasync_mcp_server->register_tool(new MCP_Tool_Update_Header_Snippet());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Footer_Snippet());
		$metasync_mcp_server->register_tool(new MCP_Tool_Update_Footer_Snippet());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Post_Snippets());
		$metasync_mcp_server->register_tool(new MCP_Tool_Update_Post_Snippets());

		// Categories & Taxonomies (15 tools)
		$metasync_mcp_server->register_tool(new MCP_Tool_List_Categories());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Category());
		$metasync_mcp_server->register_tool(new MCP_Tool_Create_Category());
		$metasync_mcp_server->register_tool(new MCP_Tool_Update_Category());
		$metasync_mcp_server->register_tool(new MCP_Tool_Delete_Category());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Post_Categories());
		$metasync_mcp_server->register_tool(new MCP_Tool_Set_Post_Categories());

		// Tags (8 tools)
		$metasync_mcp_server->register_tool(new MCP_Tool_List_Tags());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Tag());
		$metasync_mcp_server->register_tool(new MCP_Tool_Create_Tag());
		$metasync_mcp_server->register_tool(new MCP_Tool_Update_Tag());
		$metasync_mcp_server->register_tool(new MCP_Tool_Delete_Tag());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Post_Tags());
		$metasync_mcp_server->register_tool(new MCP_Tool_Set_Post_Tags());

		// Featured Images & Media (6 tools)
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Featured_Image());
		$metasync_mcp_server->register_tool(new MCP_Tool_Set_Featured_Image());
		$metasync_mcp_server->register_tool(new MCP_Tool_Upload_Featured_Image());
		$metasync_mcp_server->register_tool(new MCP_Tool_Remove_Featured_Image());
		$metasync_mcp_server->register_tool(new MCP_Tool_List_Media());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Media_Details());

		// Post CRUD Operations (1 tool - delete/restore disabled for safety)
		$metasync_mcp_server->register_tool(new MCP_Tool_Create_Post());
		// $metasync_mcp_server->register_tool(new MCP_Tool_Delete_Post()); // DISABLED - safety
		// $metasync_mcp_server->register_tool(new MCP_Tool_Restore_Post()); // DISABLED - safety

		// Bulk Operations (3 tools - bulk delete disabled for safety)
		$metasync_mcp_server->register_tool(new MCP_Tool_Bulk_Update_Meta());
		$metasync_mcp_server->register_tool(new MCP_Tool_Bulk_Set_Categories());
		$metasync_mcp_server->register_tool(new MCP_Tool_Bulk_Change_Status());
		// $metasync_mcp_server->register_tool(new MCP_Tool_Bulk_Delete_Posts()); // DISABLED - safety

		// WordPress Core SEO Settings (10 tools)
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Site_Info());
		$metasync_mcp_server->register_tool(new MCP_Tool_Update_Site_Info());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Permalink_Structure());
		$metasync_mcp_server->register_tool(new MCP_Tool_Update_Permalink_Structure());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Reading_Settings());
		$metasync_mcp_server->register_tool(new MCP_Tool_Update_Reading_Settings());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Search_Visibility());
		$metasync_mcp_server->register_tool(new MCP_Tool_Update_Search_Visibility());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Date_Format());
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Discussion_Settings());

		// Taxonomy Meta Operations (4 tools - NEW in v2.8.0)
		$metasync_mcp_server->register_tool(new MCP_Tool_Get_Term_Meta());
		$metasync_mcp_server->register_tool(new MCP_Tool_Update_Term_Meta());
		$metasync_mcp_server->register_tool(new MCP_Tool_Bulk_Update_Term_Meta());
		$metasync_mcp_server->register_tool(new MCP_Tool_List_Terms_With_Meta());

		// Bulk Alt Text Operations (4 tools - NEW in v2.8.0)
		$metasync_mcp_server->register_tool(new MCP_Tool_Audit_Alt_Text());
		$metasync_mcp_server->register_tool(new MCP_Tool_Bulk_Update_Alt_Text());
		$metasync_mcp_server->register_tool(new MCP_Tool_Generate_Alt_Text());
		$metasync_mcp_server->register_tool(new MCP_Tool_Validate_Alt_Text());

		// Allow other plugins/themes to register tools
		do_action('metasync_mcp_register_tools', $metasync_mcp_server);

	} catch (Exception $e) {
		error_log('MCP Server Initialization Error: ' . $e->getMessage());
	}
}
add_action('init', 'metasync_init_mcp_server', 5);



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
 * Initialize Mixpanel Analytics for Admin Area
 * Hooked to admin_init for proper WordPress lifecycle integration
 * Only loads after WordPress, plugins, and themes are fully loaded
 */
function metasync_init_analytics() {
	// Only initialize in admin area (excludes AJAX and REST API requests)
	if (is_admin() && !wp_doing_ajax() && !defined('REST_REQUEST')) {
		Metasync_Mixpanel::get_instance();
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
	// Initialize backoff manager (registers HTTP response filters)
	Metasync_API_Backoff_Manager::get_instance();

	// Initialize admin notices (only in admin area)
	if (is_admin()) {
		Metasync_API_Backoff_Notices::get_instance();
	}
}
add_action('init', 'metasync_init_api_backoff', 5);

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

require plugin_dir_path(__FILE__) . 'MetaSyncDebug.php';

/**
 * Include Debug Mode Manager
 * Handles automatic disable and safety limits for debug mode
 * UI integrated into Advanced Settings tab in class-metasync-admin.php
 * @since 2.5.15
 */
require_once plugin_dir_path(__FILE__) . 'includes/class-metasync-debug-mode-manager.php';

/**
 * Initialize Debug Mode Manager
 * Hooked to 'init' for proper WordPress lifecycle integration
 */
function metasync_init_debug_mode_manager() {
	// Initialize the Debug Mode Manager singleton
	Metasync_Debug_Mode_Manager::get_instance();
}
add_action('init', 'metasync_init_debug_mode_manager', 10);

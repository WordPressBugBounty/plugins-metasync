<?php
/**
 * MetaSync — MCP Server Bootstrap
 *
 * Creates the MCP server instance and registers all MCP tools. Extracted from
 * metasync.php to keep the plugin entry file a lean bootstrap. Loaded
 * via require_once from metasync.php; registers metasync_init_mcp_server() on
 * the WordPress 'init' hook. Function names and hook timing are unchanged.
 *
 * @package Search Atlas SEO
 */

if (!defined('WPINC')) {
	die;
}

/**
 * Initialize WordPress MCP Server
 *
 * Safely register a single MCP tool class, logging failures without aborting
 * subsequent registrations. Extracted as a named function so both production
 * code and unit tests exercise the same logic.
 *
 * @param object $server  The MCP server instance (must have register_tool()).
 * @param string $class_name  Fully-qualified tool class name.
 */
function metasync_safe_register_mcp_tool($server, $class_name) {
	static $logged_missing = [];
	if (!class_exists($class_name)) {
		if (empty($logged_missing[$class_name])) {
			error_log('MetaSync MCP: ' . $class_name . ' class not found — skipping registration. Run composer dump-autoload to regenerate the classmap.');
			$logged_missing[$class_name] = true;
		}
		return;
	}
	try {
		$server->register_tool(new $class_name());
	} catch (\Throwable $e) {
		error_log('MetaSync MCP: Failed to register tool ' . $class_name . ': ' . $e->getMessage());
	}
}

/**
 * Creates and configures the MCP server instance,
 * registers all available MCP tools for WordPress operations.
 * Hooked to 'init' for proper WordPress lifecycle integration.
 */
function metasync_init_mcp_server() {
	if (metasync_is_non_metasync_admin_ajax()) {
		return;
	}
	// Skip the heavy MCP boot (server + sync logger + REST inventory + 100+ tool objects)
	// unless this request is actually targeting the MCP REST route or was loaded via
	// the stdio/HTTP bridge.
	if (!metasync_is_mcp_rest_request() && !defined('METASYNC_MCP_BRIDGE')) {
		return;
	}
	// Initialize MCP server
	global $metasync_mcp_server;

	try {
		$metasync_mcp_server = new Metasync_MCP_Server();

		// Attach MCP sync logger so all write tool calls are recorded in Sync History.
		new Metasync_MCP_Sync_Logger();

		// SEO Inventory: shared builder + standalone REST endpoint
		new Metasync_REST_SEO_Inventory();
	} catch (\Throwable $e) {
		error_log('MCP Server Initialization Error: ' . $e->getMessage());
		return;
	}

	// Helper: register a single tool, logging failures without aborting subsequent registrations
	$safe_register = function($class_name) use ($metasync_mcp_server) {
		metasync_safe_register_mcp_tool($metasync_mcp_server, $class_name);
	};

	// Register MCP Tools — each safe_register() call below adds one tool.
	// NEW in v2.8.0: +4 Taxonomy Meta tools, +4 Bulk Alt Text tools

	// Post Meta Operations (4 tools)
	$safe_register('MCP_Tool_Update_Post_Meta');
	$safe_register('MCP_Tool_Get_Post_Meta');
	$safe_register('MCP_Tool_Get_SEO_Meta');
	$safe_register('MCP_Tool_Get_Hreflang_Links');

	// Post Operations (4 tools)
	$safe_register('MCP_Tool_Get_Post');
	$safe_register('MCP_Tool_Get_Post_By_URL');
	$safe_register('MCP_Tool_List_Posts');
	$safe_register('MCP_Tool_Update_Post');
	$safe_register('MCP_Tool_Get_Post_Types');

	// SEO Analysis (3 tools)
	$safe_register('MCP_Tool_Analyze_SEO');
	$safe_register('MCP_Tool_Check_Indexability');
	$safe_register('MCP_Tool_SEO_Health_Report');

	// Search Operations (3 tools)
	$safe_register('MCP_Tool_Search_Posts');
	$safe_register('MCP_Tool_Search_By_Keyword');
	$safe_register('MCP_Tool_Find_Missing_Meta');

	// Redirect Management (5 tools)
	$safe_register('MCP_Tool_Create_Redirect');
	$safe_register('MCP_Tool_List_Redirects');
	$safe_register('MCP_Tool_Delete_Redirect');
	$safe_register('MCP_Tool_Update_Redirect');
	$safe_register('MCP_Tool_Check_Redirects_Health');

	// 404 Error Monitoring (5 tools)
	$safe_register('MCP_Tool_List_404_Errors');
	$safe_register('MCP_Tool_Get_404_Stats');
	$safe_register('MCP_Tool_Delete_404_Error');
	$safe_register('MCP_Tool_Clear_404_Errors');
	$safe_register('MCP_Tool_Create_Redirect_From_404');

	// Robots.txt & Sitemap Management (11 tools - 7 existing + 4 new)
	$safe_register('MCP_Tool_Get_Robots_Txt');
	$safe_register('MCP_Tool_Update_Robots_Txt');
	$safe_register('MCP_Tool_Get_Sitemap_Status');
	$safe_register('MCP_Tool_Regenerate_Sitemap');
	$safe_register('MCP_Tool_Exclude_From_Sitemap');
	$safe_register('MCP_Tool_Add_Robots_Rule');
	$safe_register('MCP_Tool_Remove_Robots_Rule');
	$safe_register('MCP_Tool_Parse_Robots_Txt');
	$safe_register('MCP_Tool_Validate_Robots_Txt');
	$safe_register('MCP_Tool_Get_News_Sitemap');
	$safe_register('MCP_Tool_Get_Video_Sitemap');

	// Plugin Settings Management (4 tools)
	$safe_register('MCP_Tool_Get_Plugin_Settings');
	$safe_register('MCP_Tool_Update_Plugin_Settings');
	$safe_register('MCP_Tool_List_Plugin_Settings_Schema');
	$safe_register('MCP_Tool_Get_MCP_Settings');

	// Schema Markup Management (7 tools)
	$safe_register('MCP_Tool_Get_Schema_Markup');
	$safe_register('MCP_Tool_Update_Schema_Markup');
	$safe_register('MCP_Tool_Add_Schema_Type');
	$safe_register('MCP_Tool_Remove_Schema_Type');
	$safe_register('MCP_Tool_Validate_Schema');
	$safe_register('MCP_Tool_Get_Schema_Content');
	$safe_register('MCP_Tool_Set_Schema_Content');

	// Google Instant Index (6 tools)
	$safe_register('MCP_Tool_Instant_Index_Update');
	$safe_register('MCP_Tool_Instant_Index_Delete');
	$safe_register('MCP_Tool_Instant_Index_Status');
	$safe_register('MCP_Tool_Instant_Index_Bulk_Update');
	$safe_register('MCP_Tool_Get_Instant_Index_Settings');
	$safe_register('MCP_Tool_Update_Instant_Index_Settings');

	// Custom HTML Pages (6 tools)
	$safe_register('MCP_Tool_Create_Custom_Page');
	$safe_register('MCP_Tool_Get_Custom_Page');
	$safe_register('MCP_Tool_List_Custom_Pages');
	$safe_register('MCP_Tool_Update_Custom_Page');
	$safe_register('MCP_Tool_Delete_Custom_Page');
	$safe_register('MCP_Tool_Import_LPS_Page');

	// HTML to Builder Converter (3 tools)
	$safe_register('MCP_Tool_Convert_HTML_To_Builder');
	$safe_register('MCP_Tool_Create_Builder_Page_From_HTML');
	$safe_register('MCP_Tool_Convert_Custom_Page_To_Builder');

	// Code Snippets (6 tools)
	$safe_register('MCP_Tool_Get_Header_Snippet');
	$safe_register('MCP_Tool_Update_Header_Snippet');
	$safe_register('MCP_Tool_Get_Footer_Snippet');
	$safe_register('MCP_Tool_Update_Footer_Snippet');
	$safe_register('MCP_Tool_Get_Post_Snippets');
	$safe_register('MCP_Tool_Update_Post_Snippets');

	// Categories & Taxonomies (15 tools)
	$safe_register('MCP_Tool_List_Categories');
	$safe_register('MCP_Tool_Get_Category');
	$safe_register('MCP_Tool_Create_Category');
	$safe_register('MCP_Tool_Update_Category');
	$safe_register('MCP_Tool_Delete_Category');
	$safe_register('MCP_Tool_Get_Post_Categories');
	$safe_register('MCP_Tool_Set_Post_Categories');

	// Tags (8 tools)
	$safe_register('MCP_Tool_List_Tags');
	$safe_register('MCP_Tool_Get_Tag');
	$safe_register('MCP_Tool_Create_Tag');
	$safe_register('MCP_Tool_Update_Tag');
	$safe_register('MCP_Tool_Delete_Tag');
	$safe_register('MCP_Tool_Get_Post_Tags');
	$safe_register('MCP_Tool_Set_Post_Tags');

	// Featured Images & Media (6 tools)
	$safe_register('MCP_Tool_Get_Featured_Image');
	$safe_register('MCP_Tool_Set_Featured_Image');
	$safe_register('MCP_Tool_Upload_Featured_Image');
	$safe_register('MCP_Tool_Remove_Featured_Image');
	$safe_register('MCP_Tool_List_Media');
	$safe_register('MCP_Tool_Get_Media_Details');

	// Post CRUD Operations (1 tool - delete/restore disabled for safety)
	$safe_register('MCP_Tool_Create_Post');
	// $safe_register('MCP_Tool_Delete_Post'); // DISABLED - safety
	// $safe_register('MCP_Tool_Restore_Post'); // DISABLED - safety

	// Bulk Operations (3 tools - bulk delete disabled for safety)
	$safe_register('MCP_Tool_Bulk_Update_Meta');
	$safe_register('MCP_Tool_Bulk_Set_Categories');
	$safe_register('MCP_Tool_Bulk_Change_Status');
	// $safe_register('MCP_Tool_Bulk_Delete_Posts'); // DISABLED - safety

	// WordPress Core SEO Settings (10 tools)
	$safe_register('MCP_Tool_Get_Site_Info');
	$safe_register('MCP_Tool_Update_Site_Info');
	$safe_register('MCP_Tool_Get_Permalink_Structure');
	$safe_register('MCP_Tool_Update_Permalink_Structure');
	$safe_register('MCP_Tool_Get_Reading_Settings');
	$safe_register('MCP_Tool_Update_Reading_Settings');
	$safe_register('MCP_Tool_Get_Search_Visibility');
	$safe_register('MCP_Tool_Update_Search_Visibility');
	$safe_register('MCP_Tool_Get_Date_Format');
	$safe_register('MCP_Tool_Get_Discussion_Settings');

	// Taxonomy Meta Operations (4 tools - NEW in v2.8.0)
	$safe_register('MCP_Tool_Get_Term_Meta');
	$safe_register('MCP_Tool_Update_Term_Meta');
	$safe_register('MCP_Tool_Bulk_Update_Term_Meta');
	$safe_register('MCP_Tool_List_Terms_With_Meta');

	// Bulk Alt Text Operations (4 tools - NEW in v2.8.0)
	$safe_register('MCP_Tool_Audit_Alt_Text');
	$safe_register('MCP_Tool_Bulk_Update_Alt_Text');
	$safe_register('MCP_Tool_Generate_Alt_Text');
	$safe_register('MCP_Tool_Validate_Alt_Text');

	// OTTO Persistence Settings (2 tools)
	$safe_register('MCP_Tool_Get_Otto_Persistence_Settings');
	$safe_register('MCP_Tool_Update_Otto_Persistence_Settings');

	// OTTO Pipeline Tools (3 tools)
	$safe_register('MCP_Tool_Trigger_Otto_Optimization');
	$safe_register('MCP_Tool_Get_Otto_Status');
	$safe_register('MCP_Tool_Verify_SEO_Output');

	// System Diagnostics & Plugin Info (4 tools)
	$safe_register('MCP_Tool_System_Diagnostics');
	$safe_register('MCP_Tool_List_All_Plugins');
	$safe_register('MCP_Tool_Get_Cron_Jobs');
	// $safe_register('MCP_Tool_Get_WP_Option'); // DISABLED - may expose protected option values.

	// SEO Inventory (1 tool)
	$safe_register('MCP_Tool_List_Posts_SEO_Inventory');

	// Read-Only Database Access (2 tools - direct SQL queries disabled for safety)
	$safe_register('MCP_Tool_DB_Tables');
	$safe_register('MCP_Tool_DB_Describe');
	// $safe_register('MCP_Tool_DB_Select'); // DISABLED - safety

	// Breadcrumb Tools (1 tool)
	$safe_register('MCP_Tool_Get_Breadcrumb_Path');

	// Cache Purge (2 tools)
	$safe_register('MCP_Tool_Cache_Purge_All');
	$safe_register('MCP_Tool_Cache_Purge_URL');

	// LLMs.txt Tools (5 tools)
	$safe_register('MCP_Tool_Get_LLMs_Txt');
	$safe_register('MCP_Tool_Regenerate_LLMs_Txt');
	$safe_register('MCP_Tool_Get_LLMs_Txt_Settings');
	$safe_register('MCP_Tool_Update_LLMs_Txt_Settings');
	$safe_register('MCP_Tool_Get_Post_Markdown');

	// SEO Plugin Audit (4 tools)
	$safe_register('MCP_Tool_Read_SEO_Plugin_Data');
	$safe_register('MCP_Tool_SEO_Plugin_Diff');
	$safe_register('MCP_Tool_Sync_To_Active_Plugins');
	$safe_register('MCP_Tool_Detect_SEO_Conflicts');

	// Allow other plugins/themes to register tools
	do_action('metasync_mcp_register_tools', $metasync_mcp_server);
}
add_action('init', 'metasync_init_mcp_server', 5);

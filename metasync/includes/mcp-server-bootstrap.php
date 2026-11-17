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
			error_log('MetaSync MCP: ' . $class_name . ' class not found — skipping registration. Run composer dump-autoload to regenerate the classmap.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- genuine failure path, bounded, no secrets
			$logged_missing[$class_name] = true;
		}
		return;
	}
	try {
		$server->register_tool(new $class_name());
	} catch (\Throwable $e) {
		error_log('MetaSync MCP: Failed to register tool ' . $class_name . ': ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- genuine failure path, bounded, no secrets
	}
}

/**
 * The MCP tool catalogue: every registered tool class, in registration order,
 * grouped by the section it belongs to.
 *
 * Kept as data so the REST server (metasync_init_mcp_server) and the admin
 * tool inventory (Metasync_MCP_Tool_Inventory) always expose the exact same
 * tool set — the admin page passes a lightweight collector through
 * metasync_register_mcp_tools() instead of booting the server.
 *
 * Tools disabled for safety stay commented beside the group they would belong
 * to; they must never appear as live entries.
 *
 * @return array<string, string[]> Map of group label => tool class names.
 */
function metasync_mcp_tool_catalogue() {
	return [
		'Post Meta Operations' => [
			'MCP_Tool_Update_Post_Meta',
			'MCP_Tool_Get_Post_Meta',
			'MCP_Tool_Get_SEO_Meta',
			'MCP_Tool_Get_Hreflang_Links',
		],
		'Post Operations' => [
			'MCP_Tool_Get_Post',
			'MCP_Tool_Get_Post_By_URL',
			'MCP_Tool_List_Posts',
			'MCP_Tool_Update_Post',
			'MCP_Tool_Get_Post_Types',
		],
		'SEO Analysis' => [
			'MCP_Tool_Analyze_SEO',
			'MCP_Tool_Check_Indexability',
			'MCP_Tool_SEO_Health_Report',
		],
		'Search Operations' => [
			'MCP_Tool_Search_Posts',
			'MCP_Tool_Search_By_Keyword',
			'MCP_Tool_Find_Missing_Meta',
		],
		'Redirect Management' => [
			'MCP_Tool_Create_Redirect',
			'MCP_Tool_List_Redirects',
			'MCP_Tool_Delete_Redirect',
			'MCP_Tool_Update_Redirect',
			'MCP_Tool_Check_Redirects_Health',
		],
		'404 Error Monitoring' => [
			'MCP_Tool_List_404_Errors',
			'MCP_Tool_Get_404_Stats',
			'MCP_Tool_Delete_404_Error',
			'MCP_Tool_Clear_404_Errors',
			'MCP_Tool_Create_Redirect_From_404',
		],
		'Robots.txt & Sitemap Management' => [
			'MCP_Tool_Get_Robots_Txt',
			'MCP_Tool_Update_Robots_Txt',
			'MCP_Tool_Get_Sitemap_Status',
			'MCP_Tool_Regenerate_Sitemap',
			'MCP_Tool_Exclude_From_Sitemap',
			'MCP_Tool_Add_Robots_Rule',
			'MCP_Tool_Remove_Robots_Rule',
			'MCP_Tool_Parse_Robots_Txt',
			'MCP_Tool_Validate_Robots_Txt',
			'MCP_Tool_Get_News_Sitemap',
			'MCP_Tool_Get_Video_Sitemap',
		],
		'Plugin Settings Management' => [
			'MCP_Tool_Get_Plugin_Settings',
			'MCP_Tool_Update_Plugin_Settings',
			'MCP_Tool_List_Plugin_Settings_Schema',
			'MCP_Tool_Get_MCP_Settings',
		],
		'Schema Markup Management' => [
			'MCP_Tool_Get_Schema_Markup',
			'MCP_Tool_Update_Schema_Markup',
			'MCP_Tool_Add_Schema_Type',
			'MCP_Tool_Remove_Schema_Type',
			'MCP_Tool_Validate_Schema',
			'MCP_Tool_Get_Schema_Content',
			'MCP_Tool_Set_Schema_Content',
		],
		'Google Instant Index' => [
			'MCP_Tool_Instant_Index_Update',
			'MCP_Tool_Instant_Index_Delete',
			'MCP_Tool_Instant_Index_Status',
			'MCP_Tool_Instant_Index_Bulk_Update',
			'MCP_Tool_Get_Instant_Index_Settings',
			'MCP_Tool_Update_Instant_Index_Settings',
		],
		'Custom HTML Pages' => [
			'MCP_Tool_Create_Custom_Page',
			'MCP_Tool_Get_Custom_Page',
			'MCP_Tool_List_Custom_Pages',
			'MCP_Tool_Update_Custom_Page',
			'MCP_Tool_Delete_Custom_Page',
			'MCP_Tool_Import_LPS_Page',
		],
		'HTML to Builder Converter' => [
			'MCP_Tool_Convert_HTML_To_Builder',
			'MCP_Tool_Create_Builder_Page_From_HTML',
			'MCP_Tool_Convert_Custom_Page_To_Builder',
		],
		'Code Snippets' => [
			'MCP_Tool_Get_Header_Snippet',
			'MCP_Tool_Update_Header_Snippet',
			'MCP_Tool_Get_Footer_Snippet',
			'MCP_Tool_Update_Footer_Snippet',
			'MCP_Tool_Get_Post_Snippets',
			'MCP_Tool_Update_Post_Snippets',
		],
		'Categories & Taxonomies' => [
			'MCP_Tool_List_Categories',
			'MCP_Tool_Get_Category',
			'MCP_Tool_Create_Category',
			'MCP_Tool_Update_Category',
			'MCP_Tool_Delete_Category',
			'MCP_Tool_Get_Post_Categories',
			'MCP_Tool_Set_Post_Categories',
		],
		'Tags' => [
			'MCP_Tool_List_Tags',
			'MCP_Tool_Get_Tag',
			'MCP_Tool_Create_Tag',
			'MCP_Tool_Update_Tag',
			'MCP_Tool_Delete_Tag',
			'MCP_Tool_Get_Post_Tags',
			'MCP_Tool_Set_Post_Tags',
		],
		'Featured Images & Media' => [
			'MCP_Tool_Get_Featured_Image',
			'MCP_Tool_Set_Featured_Image',
			'MCP_Tool_Upload_Featured_Image',
			'MCP_Tool_Remove_Featured_Image',
			'MCP_Tool_List_Media',
			'MCP_Tool_Get_Media_Details',
		],
		'Post CRUD Operations' => [
			'MCP_Tool_Create_Post',
			// 'MCP_Tool_Delete_Post', // DISABLED - safety
			// 'MCP_Tool_Restore_Post', // DISABLED - safety
		],
		'Bulk Operations' => [
			'MCP_Tool_Bulk_Update_Meta',
			'MCP_Tool_Bulk_Set_Categories',
			'MCP_Tool_Bulk_Change_Status',
			// 'MCP_Tool_Bulk_Delete_Posts', // DISABLED - safety
		],
		'WordPress Core SEO Settings' => [
			'MCP_Tool_Get_Site_Info',
			'MCP_Tool_Update_Site_Info',
			'MCP_Tool_Get_Permalink_Structure',
			'MCP_Tool_Update_Permalink_Structure',
			'MCP_Tool_Get_Reading_Settings',
			'MCP_Tool_Update_Reading_Settings',
			'MCP_Tool_Get_Search_Visibility',
			'MCP_Tool_Update_Search_Visibility',
			'MCP_Tool_Get_Date_Format',
			'MCP_Tool_Get_Discussion_Settings',
		],
		'Taxonomy Meta Operations' => [
			'MCP_Tool_Get_Term_Meta',
			'MCP_Tool_Update_Term_Meta',
			'MCP_Tool_Bulk_Update_Term_Meta',
			'MCP_Tool_List_Terms_With_Meta',
		],
		'Bulk Alt Text Operations' => [
			'MCP_Tool_Audit_Alt_Text',
			'MCP_Tool_Bulk_Update_Alt_Text',
			'MCP_Tool_Generate_Alt_Text',
			'MCP_Tool_Validate_Alt_Text',
		],
		'OTTO Persistence Settings' => [
			'MCP_Tool_Get_Otto_Persistence_Settings',
			'MCP_Tool_Update_Otto_Persistence_Settings',
		],
		'OTTO Pipeline Tools' => [
			// 'MCP_Tool_Trigger_Otto_Optimization', // Temporarily disabled pending response-contract review.
			'MCP_Tool_Get_Otto_Status',
			'MCP_Tool_Verify_SEO_Output',
		],
		'System Diagnostics & Plugin Info' => [
			'MCP_Tool_System_Diagnostics',
			'MCP_Tool_List_All_Plugins',
			'MCP_Tool_Get_Cron_Jobs',
			// 'MCP_Tool_Get_WP_Option', // DISABLED - may expose protected option values.
		],
		'SEO Inventory' => [
			'MCP_Tool_List_Posts_SEO_Inventory',
		],
		'Read-Only Database Access' => [
			'MCP_Tool_DB_Tables',
			'MCP_Tool_DB_Describe',
			// 'MCP_Tool_DB_Select', // DISABLED - safety
		],
		'Breadcrumb Tools' => [
			'MCP_Tool_Get_Breadcrumb_Path',
		],
		'Cache Purge' => [
			'MCP_Tool_Cache_Purge_All',
			'MCP_Tool_Cache_Purge_URL',
		],
		'LLMs.txt Tools' => [
			'MCP_Tool_Get_LLMs_Txt',
			'MCP_Tool_Regenerate_LLMs_Txt',
			'MCP_Tool_Get_LLMs_Txt_Settings',
			'MCP_Tool_Update_LLMs_Txt_Settings',
			'MCP_Tool_Get_Post_Markdown',
		],
		'SEO Plugin Audit' => [
			'MCP_Tool_Read_SEO_Plugin_Data',
			'MCP_Tool_SEO_Plugin_Diff',
			'MCP_Tool_Sync_To_Active_Plugins',
			'MCP_Tool_Detect_SEO_Conflicts',
		],
	];
}

/**
 * Register every tool in the catalogue on a server-like object.
 *
 * The receiver only needs a register_tool() method: the real MCP server, or
 * the lightweight collector the admin tool inventory passes in to describe
 * tools without booting the server's auth machinery.
 *
 * @param object $server MCP server (or collector) exposing register_tool().
 * @param bool   $include_external Whether to run third-party registration hooks.
 */
function metasync_register_mcp_tools($server, $include_external = true) {
	foreach (metasync_mcp_tool_catalogue() as $tool_classes) {
		foreach ($tool_classes as $class_name) {
			metasync_safe_register_mcp_tool($server, $class_name);
		}
	}

	// Allow other plugins/themes to register tools only on the real MCP server.
	if ($include_external) {
		do_action('metasync_mcp_register_tools', $server);
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
		error_log('MCP Server Initialization Error: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- genuine failure path, bounded, no secrets
		return;
	}

	metasync_register_mcp_tools($metasync_mcp_server);
}
add_action('init', 'metasync_init_mcp_server', 5);

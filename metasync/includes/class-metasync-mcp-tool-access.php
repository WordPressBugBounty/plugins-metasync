<?php
/**
 * MCP Tool Access
 *
 * Central, explicit classification of every registered MCP tool as read or
 * write, and the mapping from white-labelled features to the tools they own.
 *
 * Read-only MCP keys may only see and call tools classified 'read'. Anything
 * not listed here (including third-party tools registered through the
 * metasync_mcp_register_tools action) is treated as a write tool, so a
 * read-only key can never reach an unclassified operation.
 *
 * The classification is deliberate and manual: deriving it from name prefixes
 * is provably wrong (wordpress_generate_alt_text only returns suggestions,
 * while wordpress_validate_schema persists validation results).
 *
 * @package    MetaSync
 * @subpackage MCP_Server
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_MCP_Tool_Access {

	/**
	 * Read access level.
	 */
	const ACCESS_READ  = 'read';

	/**
	 * Write access level.
	 */
	const ACCESS_WRITE = 'write';

	/**
	 * Explicit per-tool access classification for all registered tools.
	 *
	 * Every value was verified against the tool's execute() method; see the
	 * MR description for the full rationale table. Unlisted tools default
	 * to write.
	 *
	 * @var array<string, string>
	 */
	private static $tool_access = [
		'wordpress_add_robots_rule' => 'write',
		'wordpress_add_schema_type' => 'write',
		'wordpress_analyze_seo' => 'read',
		'wordpress_audit_alt_text' => 'read',
		'wordpress_bulk_change_status' => 'write',
		'wordpress_bulk_set_categories' => 'write',
		'wordpress_bulk_update_alt_text' => 'write',
		'wordpress_bulk_update_meta' => 'write',
		'wordpress_bulk_update_term_meta' => 'write',
		'wordpress_cache_purge_all' => 'write',
		'wordpress_cache_purge_url' => 'write',
		'wordpress_check_indexability' => 'read',
		'wordpress_check_redirects_health' => 'read',
		'wordpress_clear_404_errors' => 'write',
		'wordpress_convert_custom_page_to_builder' => 'write',
		'wordpress_convert_html_to_builder' => 'write',
		'wordpress_create_builder_page_from_html' => 'write',
		'wordpress_create_category' => 'write',
		'wordpress_create_custom_page' => 'write',
		'wordpress_create_post' => 'write',
		'wordpress_create_redirect' => 'write',
		'wordpress_create_redirect_from_404' => 'write',
		'wordpress_create_tag' => 'write',
		'wordpress_db_describe' => 'read',
		'wordpress_db_tables' => 'read',
		'wordpress_delete_404_error' => 'write',
		'wordpress_delete_category' => 'write',
		'wordpress_delete_custom_page' => 'write',
		'wordpress_delete_redirect' => 'write',
		'wordpress_delete_tag' => 'write',
		'wordpress_detect_seo_conflicts' => 'read',
		'wordpress_exclude_from_sitemap' => 'write',
		'wordpress_find_missing_meta' => 'read',
		'wordpress_generate_alt_text' => 'read',
		'wordpress_get_404_stats' => 'read',
		'wordpress_get_breadcrumb_path' => 'read',
		'wordpress_get_category' => 'read',
		'wordpress_get_cron_jobs' => 'read',
		'wordpress_get_custom_page' => 'read',
		'wordpress_get_date_format' => 'read',
		'wordpress_get_discussion_settings' => 'read',
		'wordpress_get_featured_image' => 'read',
		'wordpress_get_footer_snippet' => 'read',
		'wordpress_get_header_snippet' => 'read',
		'wordpress_get_hreflang_links' => 'read',
		'wordpress_get_instant_index_settings' => 'read',
		'wordpress_get_llms_txt' => 'read',
		'wordpress_get_llms_txt_settings' => 'read',
		'wordpress_get_mcp_settings' => 'read',
		'wordpress_get_media_details' => 'read',
		'wordpress_get_news_sitemap' => 'read',
		'wordpress_get_otto_persistence_settings' => 'read',
		'wordpress_get_otto_status' => 'read',
		'wordpress_get_permalink_structure' => 'read',
		'wordpress_get_plugin_settings' => 'read',
		'wordpress_get_post' => 'read',
		'wordpress_get_post_by_url' => 'read',
		'wordpress_get_post_categories' => 'read',
		'wordpress_get_post_markdown' => 'read',
		'wordpress_get_post_meta' => 'read',
		'wordpress_get_post_snippets' => 'read',
		'wordpress_get_post_tags' => 'read',
		'wordpress_get_post_types' => 'read',
		'wordpress_get_reading_settings' => 'read',
		'wordpress_get_robots_txt' => 'read',
		'wordpress_get_schema_content' => 'read',
		'wordpress_get_schema_markup' => 'read',
		'wordpress_get_search_visibility' => 'read',
		'wordpress_get_seo_health_report' => 'read',
		'wordpress_get_seo_meta' => 'read',
		'wordpress_get_site_info' => 'read',
		'wordpress_get_sitemap_status' => 'read',
		'wordpress_get_tag' => 'read',
		'wordpress_get_term_meta' => 'read',
		'wordpress_get_video_sitemap' => 'read',
		'wordpress_import_lps_page' => 'write',
		'wordpress_instant_index_bulk_update' => 'write',
		'wordpress_instant_index_delete' => 'write',
		'wordpress_instant_index_status' => 'read',
		'wordpress_instant_index_update' => 'write',
		'wordpress_list_404_errors' => 'read',
		'wordpress_list_categories' => 'read',
		'wordpress_list_custom_pages' => 'read',
		'wordpress_list_media' => 'read',
		'wordpress_list_plugin_settings_schema' => 'read',
		'wordpress_list_plugins' => 'read',
		'wordpress_list_posts' => 'read',
		'wordpress_list_posts_seo_inventory' => 'read',
		'wordpress_list_redirects' => 'read',
		'wordpress_list_tags' => 'read',
		'wordpress_list_terms_with_meta' => 'read',
		'wordpress_parse_robots_txt' => 'read',
		'wordpress_read_seo_plugin_data' => 'read',
		'wordpress_regenerate_llms_txt' => 'write',
		'wordpress_regenerate_sitemap' => 'write',
		'wordpress_remove_featured_image' => 'write',
		'wordpress_remove_robots_rule' => 'write',
		'wordpress_remove_schema_type' => 'write',
		'wordpress_search_by_keyword' => 'read',
		'wordpress_search_posts' => 'read',
		'wordpress_seo_plugin_diff' => 'read',
		'wordpress_set_featured_image' => 'write',
		'wordpress_set_post_categories' => 'write',
		'wordpress_set_post_tags' => 'write',
		'wordpress_set_schema_content' => 'write',
		'wordpress_sync_to_active_plugins' => 'write',
		'wordpress_system_diagnostics' => 'read',
		// 'wordpress_trigger_otto_optimization' => 'write', // Temporarily disabled pending response-contract review.
		'wordpress_update_category' => 'write',
		'wordpress_update_custom_page' => 'write',
		'wordpress_update_footer_snippet' => 'write',
		'wordpress_update_header_snippet' => 'write',
		'wordpress_update_instant_index_settings' => 'write',
		'wordpress_update_llms_txt_settings' => 'write',
		'wordpress_update_otto_persistence_settings' => 'write',
		'wordpress_update_permalink_structure' => 'write',
		'wordpress_update_plugin_settings' => 'write',
		'wordpress_update_post' => 'write',
		'wordpress_update_post_meta' => 'write',
		'wordpress_update_post_snippets' => 'write',
		'wordpress_update_reading_settings' => 'write',
		'wordpress_update_redirect' => 'write',
		'wordpress_update_robots_txt' => 'write',
		'wordpress_update_schema_markup' => 'write',
		'wordpress_update_search_visibility' => 'write',
		'wordpress_update_site_info' => 'write',
		'wordpress_update_tag' => 'write',
		'wordpress_update_term_meta' => 'write',
		'wordpress_upload_featured_image' => 'write',
		'wordpress_validate_alt_text' => 'read',
		'wordpress_validate_robots_txt' => 'read',
		'wordpress_validate_schema' => 'write',
		'wordpress_verify_seo_output' => 'read',
	];

	/**
	 * White-labelled feature keys mapped to the tools they own.
	 *
	 * Tools without an entry here belong to features that have no
	 * access-control toggle and stay available to every key owner.
	 *
	 * @var array<string, string[]>
	 */
	private static $feature_tools = [
		'hide_redirections' => [
			'wordpress_create_redirect',
			'wordpress_update_redirect',
			'wordpress_delete_redirect',
			'wordpress_list_redirects',
			'wordpress_create_redirect_from_404',
			'wordpress_check_redirects_health',
		],
		'hide_robots' => [
			'wordpress_get_robots_txt',
			'wordpress_update_robots_txt',
			'wordpress_parse_robots_txt',
			'wordpress_validate_robots_txt',
			'wordpress_add_robots_rule',
			'wordpress_remove_robots_rule',
		],
		'hide_xml_sitemap' => [
			'wordpress_get_sitemap_status',
			'wordpress_regenerate_sitemap',
			'wordpress_exclude_from_sitemap',
			'wordpress_get_news_sitemap',
			'wordpress_get_video_sitemap',
			'wordpress_get_hreflang_links',
		],
		'hide_custom_pages' => [
			'wordpress_list_custom_pages',
			'wordpress_create_custom_page',
			'wordpress_get_custom_page',
			'wordpress_update_custom_page',
			'wordpress_delete_custom_page',
			'wordpress_convert_custom_page_to_builder',
			'wordpress_convert_html_to_builder',
			'wordpress_create_builder_page_from_html',
			'wordpress_import_lps_page',
		],
		'hide_settings' => [
			'wordpress_get_plugin_settings',
			'wordpress_update_plugin_settings',
			'wordpress_list_plugin_settings_schema',
			'wordpress_get_mcp_settings',
			'wordpress_get_site_info',
			'wordpress_update_site_info',
			'wordpress_get_reading_settings',
			'wordpress_update_reading_settings',
			'wordpress_get_discussion_settings',
			'wordpress_get_date_format',
			'wordpress_get_permalink_structure',
			'wordpress_update_permalink_structure',
		],
		'hide_import_seo' => [
			'wordpress_read_seo_plugin_data',
			'wordpress_seo_plugin_diff',
			'wordpress_sync_to_active_plugins',
		],
		'hide_indexation_control' => [
			'wordpress_get_instant_index_settings',
			'wordpress_update_instant_index_settings',
			'wordpress_instant_index_update',
			'wordpress_instant_index_delete',
			'wordpress_instant_index_bulk_update',
			'wordpress_instant_index_status',
			'wordpress_get_search_visibility',
			'wordpress_update_search_visibility',
		],
		'hide_advanced' => [
			'wordpress_system_diagnostics',
			'wordpress_get_cron_jobs',
			'wordpress_db_tables',
			'wordpress_db_describe',
			'wordpress_get_header_snippet',
			'wordpress_update_header_snippet',
			'wordpress_get_footer_snippet',
			'wordpress_update_footer_snippet',
		],
	];

	/**
	 * Tool name to owning feature key, built from $feature_tools.
	 *
	 * @var array<string, string>|null
	 */
	private static $tool_feature_index = null;

	/**
	 * Whether a tool is a pure read for access-level gating purposes.
	 *
	 * Unlisted tools (third-party registrations included) are writes.
	 *
	 * @param string $tool_name Tool name.
	 * @return bool
	 */
	public static function is_read_only_tool($tool_name) {
		$is_read = isset(self::$tool_access[$tool_name])
			&& self::$tool_access[$tool_name] === self::ACCESS_READ;

		/**
		 * Filter the read/write classification of a tool.
		 *
		 * @param bool   $is_read  Whether the tool is read-only.
		 * @param string $tool_name Tool name.
		 */
		return (bool) apply_filters('metasync_mcp_tool_is_read_only', $is_read, $tool_name);
	}

	/**
	 * The white-labelled feature key that owns a tool, if any.
	 *
	 * @param string $tool_name Tool name.
	 * @return string|null Feature key such as 'hide_redirections', or null.
	 */
	public static function get_tool_feature($tool_name) {
		if (self::$tool_feature_index === null) {
			self::$tool_feature_index = [];
			foreach (self::$feature_tools as $feature => $tools) {
				foreach ($tools as $tool) {
					self::$tool_feature_index[$tool] = $feature;
				}
			}
		}

		return isset(self::$tool_feature_index[$tool_name]) ? self::$tool_feature_index[$tool_name] : null;
	}

	/**
	 * Filter a tools/list payload for a client-key identity.
	 *
	 * Read-only keys keep only read tools; every key drops tools whose
	 * owning feature its owner cannot access. The current user is the key
	 * owner at this point, so user_can_access() evaluates them.
	 *
	 * @param array $tools    Tools list entries (name/description/inputSchema).
	 * @param array $identity Authenticated identity ('mcp_key').
	 * @return array
	 */
	public static function filter_tools_list(array $tools, array $identity) {
		$filtered = [];
		foreach ($tools as $tool) {
			if (!is_array($tool) || !isset($tool['name'])) {
				continue;
			}
			if (!is_wp_error(self::tool_denied_reason($tool['name'], $identity))) {
				$filtered[] = $tool;
			}
		}
		return $filtered;
	}

	/**
	 * Why a client key may not call a tool, if it may not.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $identity  Authenticated identity ('mcp_key').
	 * @return true|WP_Error True when allowed; WP_Error describing the denial.
	 */
	public static function tool_denied_reason($tool_name, array $identity) {
		$access = isset($identity['access']) ? $identity['access'] : Metasync_MCP_Keys::ACCESS_READ;

		if ($access !== Metasync_MCP_Keys::ACCESS_FULL && !self::is_read_only_tool($tool_name)) {
			return new WP_Error(
				'mcp_key_read_only',
				sprintf('This MCP key is read-only and cannot call %s.', $tool_name) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-RPC error payload, JSON-encoded on output, never rendered as HTML
			);
		}

		$feature = self::get_tool_feature($tool_name);
		if ($feature !== null && !Metasync_Access_Control::user_can_access($feature)) {
			return new WP_Error(
				'mcp_key_feature_hidden',
				sprintf('The owner of this MCP key does not have access to the feature that %s belongs to.', $tool_name) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-RPC error payload, JSON-encoded on output, never rendered as HTML
			);
		}

		return true;
	}

	/**
	 * Count of classified tools, for tests and diagnostics.
	 *
	 * @return int
	 */
	public static function classified_tool_count() {
		return count(self::$tool_access);
	}
}

<?php
/**
 * OTTO MCP Integration
 *
 * Provides direct access to MCP tools for OTTO functionality.
 * Replaces the AI Agent intermediary layer with direct MCP tool calls.
 *
 * @package    MetaSync
 * @subpackage OTTO
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Otto_MCP_Integration {

    /**
     * Singleton instance
     *
     * @var Metasync_Otto_MCP_Integration
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return Metasync_Otto_MCP_Integration
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Private constructor for singleton
    }

    /**
     * Execute MCP tool directly
     *
     * @param string $tool_name MCP tool name
     * @param array $params Tool parameters
     * @return array Result
     * @throws Exception If MCP server not initialized or tool execution fails
     */
    public function execute_mcp_tool($tool_name, $params = []) {
        global $metasync_mcp_server;

        if (!$metasync_mcp_server) {
            throw new Exception('MCP Server not initialized');
        }

        $tool_registry = $metasync_mcp_server->get_tool_registry();
        return $tool_registry->execute_tool($tool_name, $params);
    }

    /**
     * Get available MCP tools list
     *
     * @return array List of available tools
     */
    public function get_available_tools() {
        global $metasync_mcp_server;

        if (!$metasync_mcp_server) {
            return [];
        }

        return $metasync_mcp_server->get_tool_registry()->get_tools_list();
    }

    /**
     * Map OTTO action to MCP tool call
     *
     * Maps legacy OTTO action names to MCP tool names and parameters.
     *
     * @param string $action_name OTTO action name
     * @param array $params Action parameters
     * @return array MCP tool result
     * @throws Exception If action not found or execution fails
     */
    public function map_otto_action($action_name, $params = []) {
        // Action to MCP tool mapping
        $tool_mapping = [
            // Meta Operations
            'update_meta_title' => ['wordpress_update_post_meta', ['meta_key' => '_metasync_meta_title']],
            'update_meta_description' => ['wordpress_update_post_meta', ['meta_key' => '_metasync_meta_description']],
            'set_focus_keyword' => ['wordpress_update_post_meta', ['meta_key' => '_metasync_focus_keyword']],
            'toggle_indexing' => ['wordpress_update_post_meta', ['meta_key' => '_metasync_robots']],
            'get_seo_data' => ['wordpress_get_seo_meta', []],

            // Post Operations
            'list_posts' => ['wordpress_list_posts', []],
            'get_post' => ['wordpress_get_post', []],
            'update_post' => ['wordpress_update_post', []],
            'analyze_seo' => ['wordpress_analyze_seo', []],
            'search_posts' => ['wordpress_search_posts', []],

            // Redirect Management
            'create_redirect' => ['wordpress_create_redirect', []],
            'list_redirects' => ['wordpress_list_redirects', []],
            'delete_redirect' => ['wordpress_delete_redirect', []],
            'update_redirect' => ['wordpress_update_redirect', []],

            // 404 Monitor
            'list_404_errors' => ['wordpress_list_404_errors', []],
            'get_404_stats' => ['wordpress_get_404_stats', []],
            'delete_404_error' => ['wordpress_delete_404_error', []],
            'clear_404_errors' => ['wordpress_clear_404_errors', []],
            'create_redirect_from_404' => ['wordpress_create_redirect_from_404', []],

            // Robots & Sitemap
            'get_robots_txt' => ['wordpress_get_robots_txt', []],
            'update_robots_txt' => ['wordpress_update_robots_txt', []],
            'regenerate_sitemap' => ['wordpress_regenerate_sitemap', []],
            'get_sitemap_status' => ['wordpress_get_sitemap_status', []],
            'exclude_from_sitemap' => ['wordpress_exclude_from_sitemap', []],

            // Plugin Settings
            'get_plugin_settings' => ['wordpress_get_plugin_settings', []],
            'update_plugin_settings' => ['wordpress_update_plugin_settings', []],

            // Schema Markup
            'get_schema_markup' => ['wordpress_get_schema_markup', []],
            'update_schema_markup' => ['wordpress_update_schema_markup', []],
            'add_schema_type' => ['wordpress_add_schema_type', []],
            'remove_schema_type' => ['wordpress_remove_schema_type', []],
            'validate_schema' => ['wordpress_validate_schema', []],

            // Instant Index
            'instant_index_update' => ['wordpress_instant_index_update', []],
            'instant_index_delete' => ['wordpress_instant_index_delete', []],
            'instant_index_status' => ['wordpress_instant_index_status', []],
            'instant_index_bulk_update' => ['wordpress_instant_index_bulk_update', []],
            'get_instant_index_settings' => ['wordpress_get_instant_index_settings', []],
            'update_instant_index_settings' => ['wordpress_update_instant_index_settings', []],

            // Custom Pages
            'create_custom_page' => ['wordpress_create_custom_page', []],
            'get_custom_page' => ['wordpress_get_custom_page', []],
            'list_custom_pages' => ['wordpress_list_custom_pages', []],
            'update_custom_page' => ['wordpress_update_custom_page', []],
            'delete_custom_page' => ['wordpress_delete_custom_page', []],

            // Code Snippets
            'get_header_snippet' => ['wordpress_get_header_snippet', []],
            'update_header_snippet' => ['wordpress_update_header_snippet', []],
            'get_footer_snippet' => ['wordpress_get_footer_snippet', []],
            'update_footer_snippet' => ['wordpress_update_footer_snippet', []],
            'get_post_snippets' => ['wordpress_get_post_snippets', []],
            'update_post_snippets' => ['wordpress_update_post_snippets', []],

            // Categories & Taxonomies
            'list_categories' => ['wordpress_list_categories', []],
            'get_category' => ['wordpress_get_category', []],
            'create_category' => ['wordpress_create_category', []],
            'update_category' => ['wordpress_update_category', []],
            'delete_category' => ['wordpress_delete_category', []],
            'get_post_categories' => ['wordpress_get_post_categories', []],
            'set_post_categories' => ['wordpress_set_post_categories', []],

            // Media & Featured Images
            'get_featured_image' => ['wordpress_get_featured_image', []],
            'set_featured_image' => ['wordpress_set_featured_image', []],
            'upload_featured_image' => ['wordpress_upload_featured_image', []],
            'remove_featured_image' => ['wordpress_remove_featured_image', []],
            'list_media' => ['wordpress_list_media', []],
            'get_media_details' => ['wordpress_get_media_details', []],

            // Post CRUD
            'create_post' => ['wordpress_create_post', []],
            'delete_post' => ['wordpress_delete_post', []],
            'restore_post' => ['wordpress_restore_post', []],

            // Enhanced Robots
            'add_robots_rule' => ['wordpress_add_robots_rule', []],
            'remove_robots_rule' => ['wordpress_remove_robots_rule', []],
            'parse_robots_txt' => ['wordpress_parse_robots_txt', []],
            'validate_robots_txt' => ['wordpress_validate_robots_txt', []],

            // Bulk Operations
            'bulk_update_meta' => ['wordpress_bulk_update_meta', []],
            'bulk_set_categories' => ['wordpress_bulk_set_categories', []],
            'bulk_change_status' => ['wordpress_bulk_change_status', []],
            'bulk_delete_posts' => ['wordpress_bulk_delete_posts', []],

            // WordPress Core SEO Settings
            'get_site_info' => ['wordpress_get_site_info', []],
            'update_site_info' => ['wordpress_update_site_info', []],
            'get_permalink_structure' => ['wordpress_get_permalink_structure', []],
            'update_permalink_structure' => ['wordpress_update_permalink_structure', []],
            'get_reading_settings' => ['wordpress_get_reading_settings', []],
            'update_reading_settings' => ['wordpress_update_reading_settings', []],
            'get_search_visibility' => ['wordpress_get_search_visibility', []],
            'update_search_visibility' => ['wordpress_update_search_visibility', []],
            'get_date_format' => ['wordpress_get_date_format', []],
            'get_discussion_settings' => ['wordpress_get_discussion_settings', []],
        ];

        if (!isset($tool_mapping[$action_name])) {
            throw new Exception("Unknown OTTO action: {$action_name}");
        }

        [$tool_name, $default_params] = $tool_mapping[$action_name];
        $merged_params = array_merge($default_params, $params);

        return $this->execute_mcp_tool($tool_name, $merged_params);
    }

    /**
     * Check if MCP tool exists
     *
     * @param string $tool_name Tool name to check
     * @return bool True if tool exists
     */
    public function has_tool($tool_name) {
        global $metasync_mcp_server;

        if (!$metasync_mcp_server) {
            return false;
        }

        return $metasync_mcp_server->get_tool_registry()->has_tool($tool_name);
    }

    /**
     * Get tool count
     *
     * @return int Number of registered tools
     */
    public function get_tool_count() {
        global $metasync_mcp_server;

        if (!$metasync_mcp_server) {
            return 0;
        }

        return $metasync_mcp_server->get_tool_registry()->get_tool_count();
    }
}

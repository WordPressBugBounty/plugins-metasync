<?php
/**
 * MCP Tool: HTML to Builder Converter
 *
 * Provides MCP tools for converting HTML to theme builder formats
 * (Elementor, Divi, Gutenberg) with CSS preservation.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
	exit;
}

require_once plugin_dir_path(dirname(__FILE__)) . 'class-mcp-tool-base.php';

/**
 * Tool 1: Convert HTML to Builder Format
 */
class MCP_Tool_Convert_HTML_To_Builder extends MCP_Tool_Base
{
	public function get_name()
	{
		return 'wordpress_convert_html_to_builder';
	}

	public function get_description()
	{
		return 'Convert HTML content to a theme builder format (Elementor, Divi, or Gutenberg) with CSS preservation. Returns converted data and metadata without creating a page.';
	}

	public function get_input_schema()
	{
		return [
			'type' => 'object',
			'properties' => [
				'html_content' => [
					'type' => 'string',
					'description' => 'HTML content to convert (required)'
				],
				'builder' => [
					'type' => 'string',
					'description' => 'Target builder: "auto" (detect), "elementor", "divi", or "gutenberg" (default: auto)',
					'enum' => ['auto', 'elementor', 'divi', 'gutenberg'],
					'default' => 'auto'
				],
				'preserve_css' => [
					'type' => 'boolean',
					'description' => 'Extract CSS from <style> tags and apply as inline styles (default: true)',
					'default' => true
				],
				'upload_images' => [
					'type' => 'boolean',
					'description' => 'Upload images to WordPress media library (default: true)',
					'default' => true
				],
				'extract_header_footer' => [
					'type' => 'boolean',
					'description' => 'Extract header and footer elements (default: true)',
					'default' => true
				]
			],
			'required' => ['html_content']
		];
	}

	public function execute($params)
	{
		$this->validate_params($params);

		// Load converter
		require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'custom-pages/class-metasync-html-to-builder-converter.php';
		$converter = new Metasync_HTML_To_Builder_Converter();

		// Set options
		$options = [
			'builder' => isset($params['builder']) ? $params['builder'] : 'auto',
			'preserve_css' => isset($params['preserve_css']) ? $params['preserve_css'] : true,
			'upload_images' => isset($params['upload_images']) ? $params['upload_images'] : true,
			'extract_header_footer' => isset($params['extract_header_footer']) ? $params['extract_header_footer'] : true,
		];

		// Convert HTML
		$result = $converter->convert($params['html_content'], $options);

		// Check for errors
		if (isset($result['error'])) {
			return [
				'success' => false,
				'error' => $result['error']
			];
		}

		// Return conversion result
		return [
			'success' => true,
			'builder' => $result['builder'],
			'content' => $result['content'],
			'meta_data' => $result['meta_data'],
			'header' => $result['header'],
			'footer' => $result['footer'],
			'message' => 'HTML successfully converted to ' . $result['builder'] . ' format'
		];
	}
}

/**
 * Tool 2: Create Builder Page from HTML
 */
class MCP_Tool_Create_Builder_Page_From_HTML extends MCP_Tool_Base
{
	public function get_name()
	{
		return 'wordpress_create_builder_page_from_html';
	}

	public function get_description()
	{
		return 'Convert HTML to a builder format and create a WordPress page in one step. The page will be fully editable in the theme builder.';
	}

	public function get_input_schema()
	{
		return [
			'type' => 'object',
			'properties' => [
				'title' => [
					'type' => 'string',
					'description' => 'Page title (required)'
				],
				'html_content' => [
					'type' => 'string',
					'description' => 'HTML content to convert (required)'
				],
				'slug' => [
					'type' => 'string',
					'description' => 'Page slug/URL (optional, auto-generated from title if not provided)'
				],
				'builder' => [
					'type' => 'string',
					'description' => 'Target builder: "auto" (detect), "elementor", "divi", or "gutenberg" (default: auto)',
					'enum' => ['auto', 'elementor', 'divi', 'gutenberg'],
					'default' => 'auto'
				],
				'preserve_css' => [
					'type' => 'boolean',
					'description' => 'Extract CSS from <style> tags and apply as inline styles (default: true)',
					'default' => true
				],
				'status' => [
					'type' => 'string',
					'description' => 'Post status (default: draft)',
					'enum' => ['draft', 'publish', 'pending', 'private'],
					'default' => 'draft'
				],
				'upload_images' => [
					'type' => 'boolean',
					'description' => 'Upload images to WordPress media library (default: true)',
					'default' => true
				],
				'extract_header_footer' => [
					'type' => 'boolean',
					'description' => 'Extract and apply header/footer (default: true)',
					'default' => true
				]
			],
			'required' => ['title', 'html_content']
		];
	}

	public function execute($params)
	{
		$this->validate_params($params);

		// Load converter
		require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'custom-pages/class-metasync-html-to-builder-converter.php';
		$converter = new Metasync_HTML_To_Builder_Converter();

		// Set conversion options
		$options = [
			'builder' => isset($params['builder']) ? $params['builder'] : 'auto',
			'preserve_css' => isset($params['preserve_css']) ? $params['preserve_css'] : true,
			'upload_images' => isset($params['upload_images']) ? $params['upload_images'] : true,
			'extract_header_footer' => isset($params['extract_header_footer']) ? $params['extract_header_footer'] : true,
		];

		// Convert HTML
		$result = $converter->convert($params['html_content'], $options);

		// Check for errors
		if (isset($result['error'])) {
			return [
				'success' => false,
				'error' => $result['error']
			];
		}

		// Create page
		$post_data = [
			'post_title' => sanitize_text_field($params['title']),
			'post_content' => $result['content'],
			'post_status' => isset($params['status']) ? $params['status'] : 'draft',
			'post_type' => 'page',
		];

		// Add slug if provided
		if (isset($params['slug']) && !empty($params['slug'])) {
			$post_data['post_name'] = sanitize_title($params['slug']);
		}

		// Insert page
		$page_id = wp_insert_post($post_data, true);

		if (is_wp_error($page_id)) {
			return [
				'success' => false,
				'error' => 'Failed to create page: ' . $page_id->get_error_message()
			];
		}

		// Apply builder meta data
		if (!empty($result['meta_data'])) {
			foreach ($result['meta_data'] as $meta_key => $meta_value) {
				update_post_meta($page_id, $meta_key, $meta_value);
			}
		}

		// Store header and footer if extracted
		if (!empty($result['header'])) {
			update_post_meta($page_id, '_metasync_custom_header', $result['header']);
		}
		if (!empty($result['footer'])) {
			update_post_meta($page_id, '_metasync_custom_footer', $result['footer']);
		}

		// Mark as builder page
		update_post_meta($page_id, '_metasync_is_builder_page', true);
		update_post_meta($page_id, '_metasync_builder_type', $result['builder']);

		// Store custom CSS for late loading (ensures it overrides theme CSS)
		if (!empty($result['css_content'])) {
			update_post_meta($page_id, '_metasync_custom_css', $result['css_content']);
		}

		// Get URLs
		$page_url = get_permalink($page_id);
		$edit_url = '';

		if ($result['builder'] === 'elementor' && class_exists('\Elementor\Plugin')) {
			$edit_url = admin_url('post.php?post=' . $page_id . '&action=elementor');
		} elseif ($result['builder'] === 'divi') {
			$edit_url = admin_url('post.php?post=' . $page_id . '&action=edit&et_fb=1');
		} else {
			$edit_url = admin_url('post.php?post=' . $page_id . '&action=edit');
		}

		return [
			'success' => true,
			'page_id' => $page_id,
			'title' => $params['title'],
			'url' => $page_url,
			'edit_url' => $edit_url,
			'builder_used' => $result['builder'],
			'status' => $post_data['post_status'],
			'has_header' => !empty($result['header']),
			'has_footer' => !empty($result['footer']),
			'message' => 'Page created successfully with ' . $result['builder'] . ' builder'
		];
	}
}

/**
 * Tool 3: Convert Existing Custom Page to Builder
 */
class MCP_Tool_Convert_Custom_Page_To_Builder extends MCP_Tool_Base
{
	public function get_name()
	{
		return 'wordpress_convert_custom_page_to_builder';
	}

	public function get_description()
	{
		return 'Convert an existing custom HTML page (created with raw HTML mode) to a theme builder format. The page will become editable in the builder while preserving the design.';
	}

	public function get_input_schema()
	{
		return [
			'type' => 'object',
			'properties' => [
				'page_id' => [
					'type' => 'integer',
					'description' => 'WordPress page ID to convert (required)'
				],
				'builder' => [
					'type' => 'string',
					'description' => 'Target builder: "auto" (detect), "elementor", "divi", or "gutenberg" (default: auto)',
					'enum' => ['auto', 'elementor', 'divi', 'gutenberg'],
					'default' => 'auto'
				],
				'preserve_css' => [
					'type' => 'boolean',
					'description' => 'Extract CSS from <style> tags and apply as inline styles (default: true)',
					'default' => true
				],
				'disable_raw_html' => [
					'type' => 'boolean',
					'description' => 'Disable raw HTML mode after conversion (default: true)',
					'default' => true
				],
				'extract_header_footer' => [
					'type' => 'boolean',
					'description' => 'Extract and apply header/footer (default: true)',
					'default' => true
				]
			],
			'required' => ['page_id']
		];
	}

	public function execute($params)
	{
		$this->validate_params($params);

		$page_id = intval($params['page_id']);

		// Check if page exists
		$page = get_post($page_id);
		if (!$page || $page->post_type !== 'page') {
			return [
				'success' => false,
				'error' => 'Page not found or is not a valid page'
			];
		}

		// Get raw HTML content
		$raw_html = get_post_meta($page_id, '_metasync_raw_html_content', true);
		if (empty($raw_html)) {
			return [
				'success' => false,
				'error' => 'No raw HTML content found for this page'
			];
		}

		// Load converter
		require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'custom-pages/class-metasync-html-to-builder-converter.php';
		$converter = new Metasync_HTML_To_Builder_Converter();

		// Set conversion options
		$options = [
			'builder' => isset($params['builder']) ? $params['builder'] : 'auto',
			'preserve_css' => isset($params['preserve_css']) ? $params['preserve_css'] : true,
			'upload_images' => true,
			'extract_header_footer' => isset($params['extract_header_footer']) ? $params['extract_header_footer'] : true,
		];

		// Convert HTML
		$result = $converter->convert($raw_html, $options);

		// Check for errors
		if (isset($result['error'])) {
			return [
				'success' => false,
				'error' => $result['error']
			];
		}

		// Update page content
		wp_update_post([
			'ID' => $page_id,
			'post_content' => $result['content']
		]);

		// Apply builder meta data
		if (!empty($result['meta_data'])) {
			foreach ($result['meta_data'] as $meta_key => $meta_value) {
				update_post_meta($page_id, $meta_key, $meta_value);
			}
		}

		// Store header and footer if extracted
		if (!empty($result['header'])) {
			update_post_meta($page_id, '_metasync_custom_header', $result['header']);
		}
		if (!empty($result['footer'])) {
			update_post_meta($page_id, '_metasync_custom_footer', $result['footer']);
		}

		// Mark as builder page
		update_post_meta($page_id, '_metasync_is_builder_page', true);
		update_post_meta($page_id, '_metasync_builder_type', $result['builder']);

		// Disable raw HTML mode if requested
		if (isset($params['disable_raw_html']) && $params['disable_raw_html']) {
			update_post_meta($page_id, '_metasync_raw_html_enabled', false);
		}

		// Get URLs
		$page_url = get_permalink($page_id);
		$edit_url = '';

		if ($result['builder'] === 'elementor' && class_exists('\Elementor\Plugin')) {
			$edit_url = admin_url('post.php?post=' . $page_id . '&action=elementor');
		} elseif ($result['builder'] === 'divi') {
			$edit_url = admin_url('post.php?post=' . $page_id . '&action=edit&et_fb=1');
		} else {
			$edit_url = admin_url('post.php?post=' . $page_id . '&action=edit');
		}

		return [
			'success' => true,
			'page_id' => $page_id,
			'title' => $page->post_title,
			'url' => $page_url,
			'edit_url' => $edit_url,
			'builder_used' => $result['builder'],
			'raw_html_disabled' => isset($params['disable_raw_html']) ? $params['disable_raw_html'] : true,
			'has_header' => !empty($result['header']),
			'has_footer' => !empty($result['footer']),
			'message' => 'Page successfully converted to ' . $result['builder'] . ' builder format'
		];
	}
}

<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}


/**
 * Custom HTML Pages REST API Handler
 *
 * Provides REST API endpoints for creating and managing custom HTML pages
 *
 * @package    Metasync
 * @subpackage Metasync/custom-pages
 * @since      2.5.10
 */

class Metasync_Custom_Pages_API
{
	/**
	 * REST API namespace
	 */
	private const NAMESPACE = 'metasync/v1';

	/**
	 * Initialize the API handler
	 */
	public function __construct()
	{
		add_action('rest_api_init', array($this, 'register_routes'));
	}

	/**
	 * Register REST API routes
	 */
	public function register_routes()
	{
		// Create custom HTML page
		register_rest_route(
			self::NAMESPACE,
			'/custom-pages/create',
			array(
				'methods' => 'POST',
				'callback' => array($this, 'create_custom_html_page'),
				'permission_callback' => array($this, 'validate_api_key'),
				'args' => $this->get_create_page_args()
			)
		);

		// Update custom HTML page
		register_rest_route(
			self::NAMESPACE,
			'/custom-pages/update',
			array(
				'methods' => 'POST',
				'callback' => array($this, 'update_custom_html_page'),
				'permission_callback' => array($this, 'validate_api_key'),
				'args' => $this->get_update_page_args()
			)
		);

		// Get custom HTML page
		register_rest_route(
			self::NAMESPACE,
			'/custom-pages/get',
			array(
				'methods' => 'GET',
				'callback' => array($this, 'get_custom_html_page'),
				'permission_callback' => array($this, 'validate_api_key'),
				'args' => array(
					'page_id' => array(
						'required' => false,
						'type' => 'integer',
						'description' => 'Page ID'
					),
					'slug' => array(
						'required' => false,
						'type' => 'string',
						'description' => 'Page slug'
					)
				)
			)
		);

		// List all custom HTML pages
		register_rest_route(
			self::NAMESPACE,
			'/custom-pages/list',
			array(
				'methods' => 'GET',
				'callback' => array($this, 'list_custom_html_pages'),
				'permission_callback' => array($this, 'validate_api_key')
			)
		);

		// Delete custom HTML page
		register_rest_route(
			self::NAMESPACE,
			'/custom-pages/delete',
			array(
				'methods' => 'DELETE',
				'callback' => array($this, 'delete_custom_html_page'),
				'permission_callback' => array($this, 'validate_api_key'),
				'args' => array(
					'page_id' => array(
						'required' => true,
						'type' => 'integer',
						'description' => 'Page ID to delete'
					)
				)
			)
		);
	}

	/**
	 * Validate API key from request
	 * Uses the same authentication as otto_crawl_notify endpoint
	 */
	public function validate_api_key($request)
	{
		// Get API key from header or query parameter
		$api_key = $request->get_header('x-api-key');

		if (empty($api_key)) {
			$api_key = $request->get_param('apikey');
		}

		if (empty($api_key)) {
			return new WP_Error(
				'missing_api_key',
				'API key is required. Provide it via x-api-key header or apikey query parameter.',
				array('status' => 401)
			);
		}

		// Get stored API key from settings
		$settings = Metasync::get_option('general');
		$stored_api_key = $settings['apikey'] ?? null;

		if (empty($stored_api_key)) {
			return new WP_Error(
				'api_key_not_configured',
				'API key is not configured in MetaSync settings.',
				array('status' => 500)
			);
		}

		// Validate API key
		if ($api_key !== $stored_api_key) {
			return new WP_Error(
				'invalid_api_key',
				'Invalid API key provided.',
				array('status' => 403)
			);
		}

		return true;
	}

	/**
	 * Get arguments for create page endpoint
	 */
	private function get_create_page_args()
	{
		return array(
			'title' => array(
				'required' => true,
				'type' => 'string',
				'description' => 'Page title',
				'sanitize_callback' => 'sanitize_text_field'
			),
			'slug' => array(
				'required' => false,
				'type' => 'string',
				'description' => 'Page slug (URL-friendly name)',
				'sanitize_callback' => 'sanitize_title'
			),
			'html_content' => array(
				'required' => true,
				'type' => 'string',
				'description' => 'Raw HTML content'
			),
			'status' => array(
				'required' => false,
				'type' => 'string',
				'default' => 'publish',
				'enum' => array('publish', 'draft', 'pending'),
				'description' => 'Page status'
			),
			'enable_raw_html' => array(
				'required' => false,
				'type' => 'boolean',
				'default' => true,
				'description' => 'Enable raw HTML mode (bypass theme)'
			),
			'filename' => array(
				'required' => false,
				'type' => 'string',
				'description' => 'Original HTML filename (for reference)',
				'sanitize_callback' => 'sanitize_file_name'
			)
		);
	}

	/**
	 * Get arguments for update page endpoint
	 */
	private function get_update_page_args()
	{
		$args = $this->get_create_page_args();

		// Add page_id as required for updates
		$args['page_id'] = array(
			'required' => true,
			'type' => 'integer',
			'description' => 'Page ID to update'
		);

		// Make other fields optional for updates
		$args['title']['required'] = false;
		$args['html_content']['required'] = false;

		return $args;
	}

	/**
	 * Create a new custom HTML page
	 */
	public function create_custom_html_page($request)
	{
		$params = $request->get_params();

		// Prepare page data
		$page_data = array(
			'post_title' => sanitize_text_field($params['title']),
			'post_type' => 'page',
			'post_status' => sanitize_text_field($params['status'] ?? 'publish'),
			'post_content' => '', // We'll store HTML in meta
		);

		// Set slug if provided
		if (!empty($params['slug'])) {
			$page_data['post_name'] = sanitize_title($params['slug']);
		}

		// Check if page with same slug already exists
		if (!empty($page_data['post_name'])) {
			$existing_page = get_page_by_path($page_data['post_name'], OBJECT, 'page');
			if ($existing_page) {
				return new WP_Error(
					'page_exists',
					'A page with this slug already exists. Use the update endpoint instead.',
					array('status' => 409)
				);
			}
		}

		// Insert the page
		$page_id = wp_insert_post($page_data, true);

		if (is_wp_error($page_id)) {
			return new WP_Error(
				'page_creation_failed',
				'Failed to create page: ' . $page_id->get_error_message(),
				array('status' => 500)
			);
		}

		// Mark as custom HTML page
		update_post_meta($page_id, Metasync_Custom_Pages::META_IS_CUSTOM_HTML_PAGE, '1');

		// Mark as created via API
		update_post_meta($page_id, Metasync_Custom_Pages::META_CREATED_VIA_API, '1');

		// Enable raw HTML mode
		$enable_raw_html = isset($params['enable_raw_html']) ? (bool) $params['enable_raw_html'] : true;
		update_post_meta($page_id, Metasync_Custom_Pages::META_HTML_ENABLED, $enable_raw_html ? '1' : '0');

		// Store HTML content
		if (!empty($params['html_content'])) {
			// No sanitization for HTML content - admin users are trusted
			update_post_meta($page_id, Metasync_Custom_Pages::META_HTML_CONTENT, wp_unslash($params['html_content']));
		}

		// Store filename if provided
		if (!empty($params['filename'])) {
			update_post_meta($page_id, Metasync_Custom_Pages::META_HTML_FILENAME, sanitize_file_name($params['filename']));
		}

		// Clear cache
		wp_cache_delete($page_id, 'posts');
		wp_cache_delete($page_id, 'post_meta');

		// Return success response
		return rest_ensure_response(array(
			'success' => true,
			'message' => 'Custom HTML page created successfully',
			'data' => array(
				'page_id' => $page_id,
				'title' => get_the_title($page_id),
				'slug' => get_post_field('post_name', $page_id),
				'url' => get_permalink($page_id),
				'edit_url' => get_edit_post_link($page_id, 'raw'),
				'status' => get_post_status($page_id),
				'raw_html_enabled' => $enable_raw_html,
				'html_length' => strlen($params['html_content'] ?? ''),
				'created_at' => get_post_field('post_date', $page_id)
			)
		));
	}

	/**
	 * Update an existing custom HTML page
	 */
	public function update_custom_html_page($request)
	{
		$params = $request->get_params();
		$page_id = intval($params['page_id']);

		// Verify page exists
		$page = get_post($page_id);
		if (!$page || $page->post_type !== 'page') {
			return new WP_Error(
				'page_not_found',
				'Page not found with ID: ' . $page_id,
				array('status' => 404)
			);
		}

		// Verify it's a custom HTML page
		$is_custom_html_page = get_post_meta($page_id, Metasync_Custom_Pages::META_IS_CUSTOM_HTML_PAGE, true);
		if ($is_custom_html_page !== '1') {
			return new WP_Error(
				'not_custom_html_page',
				'This page is not a custom HTML page.',
				array('status' => 400)
			);
		}

		// Update page data if provided
		$update_data = array('ID' => $page_id);

		if (isset($params['title'])) {
			$update_data['post_title'] = sanitize_text_field($params['title']);
		}

		if (isset($params['slug'])) {
			$update_data['post_name'] = sanitize_title($params['slug']);
		}

		if (isset($params['status'])) {
			$update_data['post_status'] = sanitize_text_field($params['status']);
		}

		// Update page if there are changes
		if (count($update_data) > 1) {
			$result = wp_update_post($update_data, true);
			if (is_wp_error($result)) {
				return new WP_Error(
					'page_update_failed',
					'Failed to update page: ' . $result->get_error_message(),
					array('status' => 500)
				);
			}
		}

		// Update HTML content if provided
		if (isset($params['html_content'])) {
			update_post_meta($page_id, Metasync_Custom_Pages::META_HTML_CONTENT, wp_unslash($params['html_content']));
		}

		// Update raw HTML mode if provided
		if (isset($params['enable_raw_html'])) {
			$enable_raw_html = (bool) $params['enable_raw_html'];
			update_post_meta($page_id, Metasync_Custom_Pages::META_HTML_ENABLED, $enable_raw_html ? '1' : '0');
		}

		// Update filename if provided
		if (isset($params['filename'])) {
			update_post_meta($page_id, Metasync_Custom_Pages::META_HTML_FILENAME, sanitize_file_name($params['filename']));
		}

		// Clear cache
		wp_cache_delete($page_id, 'posts');
		wp_cache_delete($page_id, 'post_meta');

		// Return success response
		return rest_ensure_response(array(
			'success' => true,
			'message' => 'Custom HTML page updated successfully',
			'data' => array(
				'page_id' => $page_id,
				'title' => get_the_title($page_id),
				'slug' => get_post_field('post_name', $page_id),
				'url' => get_permalink($page_id),
				'edit_url' => get_edit_post_link($page_id, 'raw'),
				'status' => get_post_status($page_id),
				'raw_html_enabled' => get_post_meta($page_id, Metasync_Custom_Pages::META_HTML_ENABLED, true) === '1',
				'html_length' => strlen(get_post_meta($page_id, Metasync_Custom_Pages::META_HTML_CONTENT, true)),
				'updated_at' => get_post_field('post_modified', $page_id)
			)
		));
	}

	/**
	 * Get a custom HTML page
	 */
	public function get_custom_html_page($request)
	{
		$page_id = $request->get_param('page_id');
		$slug = $request->get_param('slug');

		if (empty($page_id) && empty($slug)) {
			return new WP_Error(
				'missing_identifier',
				'Either page_id or slug must be provided.',
				array('status' => 400)
			);
		}

		// Get page by ID or slug
		if (!empty($page_id)) {
			$page = get_post(intval($page_id));
		} else {
			$page = get_page_by_path($slug, OBJECT, 'page');
		}

		if (!$page || $page->post_type !== 'page') {
			return new WP_Error(
				'page_not_found',
				'Page not found.',
				array('status' => 404)
			);
		}

		// Verify it's a custom HTML page
		$is_custom_html_page = get_post_meta($page->ID, Metasync_Custom_Pages::META_IS_CUSTOM_HTML_PAGE, true);
		if ($is_custom_html_page !== '1') {
			return new WP_Error(
				'not_custom_html_page',
				'This page is not a custom HTML page.',
				array('status' => 400)
			);
		}

		// Get HTML content
		$html_content = get_post_meta($page->ID, Metasync_Custom_Pages::META_HTML_CONTENT, true);
		$html_enabled = get_post_meta($page->ID, Metasync_Custom_Pages::META_HTML_ENABLED, true);
		$html_filename = get_post_meta($page->ID, Metasync_Custom_Pages::META_HTML_FILENAME, true);

		return rest_ensure_response(array(
			'success' => true,
			'data' => array(
				'page_id' => $page->ID,
				'title' => $page->post_title,
				'slug' => $page->post_name,
				'url' => get_permalink($page->ID),
				'edit_url' => get_edit_post_link($page->ID, 'raw'),
				'status' => $page->post_status,
				'raw_html_enabled' => $html_enabled === '1',
				'html_content' => $html_content,
				'html_filename' => $html_filename,
				'html_length' => strlen($html_content),
				'created_at' => $page->post_date,
				'updated_at' => $page->post_modified
			)
		));
	}

	/**
	 * List all custom HTML pages
	 */
	public function list_custom_html_pages($request)
	{
		$pages = Metasync_Custom_Pages::get_custom_pages();

		$pages_data = array();
		foreach ($pages as $page) {
			$html_enabled = get_post_meta($page->ID, Metasync_Custom_Pages::META_HTML_ENABLED, true);
			$html_content = get_post_meta($page->ID, Metasync_Custom_Pages::META_HTML_CONTENT, true);
			$html_filename = get_post_meta($page->ID, Metasync_Custom_Pages::META_HTML_FILENAME, true);

			$pages_data[] = array(
				'page_id' => $page->ID,
				'title' => $page->post_title,
				'slug' => $page->post_name,
				'url' => get_permalink($page->ID),
				'edit_url' => get_edit_post_link($page->ID, 'raw'),
				'status' => $page->post_status,
				'raw_html_enabled' => $html_enabled === '1',
				'html_filename' => $html_filename,
				'html_length' => strlen($html_content),
				'created_at' => $page->post_date,
				'updated_at' => $page->post_modified
			);
		}

		return rest_ensure_response(array(
			'success' => true,
			'count' => count($pages_data),
			'data' => $pages_data
		));
	}

	/**
	 * Delete a custom HTML page
	 */
	public function delete_custom_html_page($request)
	{
		$page_id = intval($request->get_param('page_id'));

		// Verify page exists
		$page = get_post($page_id);
		if (!$page || $page->post_type !== 'page') {
			return new WP_Error(
				'page_not_found',
				'Page not found with ID: ' . $page_id,
				array('status' => 404)
			);
		}

		// Verify it's a custom HTML page
		$is_custom_html_page = get_post_meta($page_id, Metasync_Custom_Pages::META_IS_CUSTOM_HTML_PAGE, true);
		if ($is_custom_html_page !== '1') {
			return new WP_Error(
				'not_custom_html_page',
				'This page is not a custom HTML page.',
				array('status' => 400)
			);
		}

		// Delete the page permanently
		$result = wp_delete_post($page_id, true);

		if (!$result) {
			return new WP_Error(
				'delete_failed',
				'Failed to delete page.',
				array('status' => 500)
			);
		}

		return rest_ensure_response(array(
			'success' => true,
			'message' => 'Custom HTML page deleted successfully',
			'data' => array(
				'page_id' => $page_id,
				'title' => $page->post_title
			)
		));
	}
}

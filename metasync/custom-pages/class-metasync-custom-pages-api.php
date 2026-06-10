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

		// Import LPS ZIP as custom HTML page
		register_rest_route(
			self::NAMESPACE,
			'/custom-pages/import-zip',
			array(
				'methods' => 'POST',
				'callback' => array($this, 'import_lps_page'),
				'permission_callback' => array($this, 'validate_api_key'),
				'args' => $this->get_import_zip_args()
			)
		);
	}

	/**
	 * Validate API key from request
	 * Uses the same authentication as otto_crawl_notify endpoint
	 */
	public function validate_api_key($request)
	{
		// Get API key from Authorization: Bearer header (preferred), x-api-key header, or apikey query parameter
		$api_key = '';

		$auth_header = $request->get_header('authorization');
		if (!empty($auth_header) && preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
			$api_key = sanitize_text_field($matches[1]);
		}

		if (empty($api_key)) {
			$header_key = $request->get_header('x-api-key');
			if (!empty($header_key)) {
				$api_key = sanitize_text_field($header_key);
			}
		}

		if (empty($api_key)) {
			$param_key = $request->get_param('apikey');
			if (!empty($param_key)) {
				$api_key = sanitize_text_field($param_key);
				// Deprecated: ?apikey= query-param auth — switch callers to Authorization: Bearer
			}
		}

		if (empty($api_key)) {
			return new WP_Error(
				'missing_api_key',
				'API key is required. Provide it via Authorization: Bearer header, x-api-key header, or apikey query parameter.',
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
		if (!hash_equals($stored_api_key, $api_key)) {
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

		// Capture the on-disk assets folder BEFORE deleting the post — its meta is
		// gone afterwards. Prefer the recorded folder (it can differ from the slug);
		// fall back to the slug for pages imported before this was tracked.
		$assets_folder = get_post_meta($page_id, Metasync_Custom_Pages::META_ASSETS_FOLDER, true);
		if (empty($assets_folder)) {
			$assets_folder = $page->post_name;
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

		// Remove the LPS asset folder associated with this page — but only if no
		// other page still references it. Multi-page imports share one folder across
		// many pages, so deleting one page must not wipe assets the remaining pages
		// still need (reference counting).
		$assets_removed = false;
		$assets_still_referenced = false;
		if (!empty($assets_folder) && strpos($assets_folder, '..') === false && strpos($assets_folder, '/') === false && strpos($assets_folder, '\\') === false) {
			$others = get_posts(array(
				'post_type'        => 'page',
				// 'any' + 'trash': bare 'any' excludes trashed posts, so a trashed
				// sibling sharing this folder would be missed and its assets wiped
				// (breaking it on restore). Listing 'trash' alongside 'any' removes it
				// from the exclusion set while still matching every other status —
				// including custom plugin statuses, which a hardcoded list would drop.
				'post_status'      => array('any', 'trash'),
				'numberposts'      => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'exclude'          => array($page_id),
				'meta_key'         => Metasync_Custom_Pages::META_ASSETS_FOLDER,
				'meta_value'       => $assets_folder,
			));
			if (!empty($others)) {
				// Other pages still use this folder — keep it.
				$assets_still_referenced = true;
			} else {
				$upload_dir = wp_upload_dir();
				if (empty($upload_dir['error'])) {
					$base_dir = trailingslashit($upload_dir['basedir']) . 'metasync-pages';
					$target_dir = trailingslashit($base_dir) . $assets_folder;
					$base_real = realpath($base_dir);
					$target_real = realpath($target_dir);
					if ($base_real !== false && $target_real !== false && strpos($target_real, $base_real) === 0) {
						$this->recursive_rmdir($target_real);
						$assets_removed = !file_exists($target_real);
					}
				}
			}
		}

		return rest_ensure_response(array(
			'success' => true,
			'message' => 'Custom HTML page deleted successfully',
			'data' => array(
				'page_id' => $page_id,
				'title' => $page->post_title,
				'assets_removed' => $assets_removed,
				'assets_folder_still_referenced' => $assets_still_referenced
			)
		));
	}

	/**
	 * Get arguments for the import-zip endpoint
	 */
	private function get_import_zip_args()
	{
		return array(
			'slug' => array(
				'required' => false,
				'type' => 'string',
				'description' => 'Page slug (single-page imports only). Required when the ZIP has no pages.manifest.json; ignored for multi-page bundles, which take slugs from the manifest.'
			),
			'title' => array(
				'required' => false,
				'type' => 'string',
				'description' => 'Page title (single-page imports only). Ignored for multi-page bundles, which take titles from the manifest.',
				'sanitize_callback' => 'sanitize_text_field'
			),
			'status' => array(
				'required' => false,
				'type' => 'string',
				'default' => 'publish',
				'enum' => array('publish', 'draft', 'pending'),
				'description' => 'Page status'
			),
			'overwrite' => array(
				'required' => false,
				'type' => 'boolean',
				'default' => true,
				'description' => 'When true, an existing page with the same slug is overwritten.'
			),
			'download_url' => array(
				'required' => false,
				'type' => 'string',
				'description' => 'URL to download the LPS ZIP from. If provided, the ZIP is fetched server-side instead of requiring a multipart upload.',
				'sanitize_callback' => 'esc_url_raw'
			),
			'assets_folder' => array(
				'required' => false,
				'type' => 'string',
				'description' => 'On-disk folder name the bundle is extracted to (under uploads/metasync-pages/). This is the path LPS bakes into the asset URLs at build time and is intentionally separate from the page slug. Defaults to the slug when omitted.'
			)
		);
	}

	/**
	 * REST callback: import LPS ZIP as a custom HTML page.
	 *
	 * Accepts either a JSON body with download_url (preferred) or a multipart zip_file upload.
	 */
	public function import_lps_page($request)
	{
		$raw_slug = $request->get_param('slug');
		if (is_string($raw_slug) && (strpos($raw_slug, '..') !== false || strpos($raw_slug, '/') !== false || strpos($raw_slug, '\\') !== false)) {
			return new WP_Error(
				'invalid_slug_path',
				'Slug must not contain path separators or parent references.',
				array('status' => 400)
			);
		}

		$params = $request->get_params();
		$slug = isset($params['slug']) ? sanitize_title($params['slug']) : '';
		$title = isset($params['title']) ? sanitize_text_field($params['title']) : '';
		$status = isset($params['status']) ? sanitize_text_field($params['status']) : 'publish';
		$overwrite = isset($params['overwrite']) ? (bool) $params['overwrite'] : true;
		$download_url = isset($params['download_url']) ? $params['download_url'] : '';
		$assets_folder = isset($params['assets_folder']) ? $params['assets_folder'] : '';

		$zip_path = '';
		$tmp_file = null;

		$max_zip_bytes = 50 * 1024 * 1024;

		try {
			if (!empty($download_url)) {
				$tmp_file = wp_tempnam('lps_import_');
				if (!$tmp_file) {
					return new WP_Error('tmp_file_failed', 'Failed to create temporary file.', array('status' => 500));
				}

				$response = wp_safe_remote_get($download_url, array(
					'timeout' => 60,
					'stream' => true,
					'filename' => $tmp_file,
				));

				if (is_wp_error($response)) {
					return new WP_Error('zip_download_failed', 'Failed to download ZIP: ' . $response->get_error_message(), array('status' => 400));
				}

				$code = wp_remote_retrieve_response_code($response);
				if ((int) $code !== 200) {
					return new WP_Error('zip_download_http_error', 'ZIP download returned HTTP ' . intval($code), array('status' => 400));
				}

				$content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
				if ($content_type !== '' && strpos($content_type, 'zip') === false && strpos($content_type, 'octet-stream') === false) {
					return new WP_Error('invalid_zip_content_type', 'Downloaded file is not a ZIP (Content-Type: ' . sanitize_text_field($content_type) . ').', array('status' => 400));
				}

				$downloaded_size = file_exists($tmp_file) ? filesize($tmp_file) : 0;
				if ($downloaded_size === 0) {
					return new WP_Error('zip_download_empty', 'Downloaded ZIP file is empty.', array('status' => 400));
				}
				if ($downloaded_size > $max_zip_bytes) {
					return new WP_Error('zip_too_large', 'Downloaded ZIP exceeds the maximum allowed size (' . intval($max_zip_bytes / 1024 / 1024) . ' MB).', array('status' => 413));
				}

				$zip_path = $tmp_file;

			} elseif (!empty($_FILES['zip_file']) && is_array($_FILES['zip_file'])) {
				$file = $_FILES['zip_file'];

				if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
					return new WP_Error('zip_upload_error', 'Uploaded ZIP file has an error code: ' . (isset($file['error']) ? intval($file['error']) : 'unknown'), array('status' => 400));
				}

				if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
					return new WP_Error('invalid_zip_upload', 'Invalid ZIP upload.', array('status' => 400));
				}

				$allowed_mime_types = array('application/zip', 'application/octet-stream', 'application/x-zip-compressed', 'multipart/x-zip');
				if (!empty($file['type']) && !in_array($file['type'], $allowed_mime_types, true)) {
					return new WP_Error('invalid_zip_mime', 'Uploaded file is not a ZIP archive (type: ' . sanitize_text_field($file['type']) . ').', array('status' => 400));
				}

				$uploaded_size = isset($file['size']) ? (int) $file['size'] : 0;
				if ($uploaded_size > $max_zip_bytes) {
					return new WP_Error('zip_too_large', 'Uploaded ZIP exceeds the maximum allowed size (' . intval($max_zip_bytes / 1024 / 1024) . ' MB).', array('status' => 413));
				}

				$zip_path = $file['tmp_name'];

			} else {
				return new WP_Error('missing_zip_source', 'Provide either a download_url or a multipart zip_file upload.', array('status' => 400));
			}

			$result = $this->extract_and_create_lps_page($zip_path, $slug, $title, $status, $overwrite, $assets_folder);

			if (is_wp_error($result)) {
				return $result;
			}

			// Map the import outcome to an HTTP status:
			//   200 — all pages created/updated
			//   207 — partial success (some pages failed)
			//   422 — every page failed
			// (Single-page slug conflicts already return 409 as a WP_Error above.)
			$status_code = 200;
			$data = (isset($result['data']) && is_array($result['data'])) ? $result['data'] : array();
			if (isset($data['mode']) && $data['mode'] === 'multi') {
				$succeeded = count(isset($data['created']) ? $data['created'] : array())
					+ count(isset($data['updated']) ? $data['updated'] : array());
				$failed = count(isset($data['failed']) ? $data['failed'] : array());
				if ($failed > 0 && $succeeded > 0) {
					$status_code = 207;
				} elseif ($failed > 0 && $succeeded === 0) {
					$status_code = 422;
					$result['success'] = false;
				}
			}

			$response = rest_ensure_response($result);
			$response->set_status($status_code);
			return $response;

		} finally {
			if (!empty($tmp_file) && file_exists($tmp_file)) {
				@unlink($tmp_file);
			}
		}
	}

	/**
	 * Shared helper: extract a ZIP archive and create or update a custom HTML page.
	 *
	 * Made public so the MCP tool (in a different class) can invoke it directly.
	 *
	 * @param string $zip_path  Filesystem path to the ZIP file.
	 * @param string $slug      Page slug (will be re-sanitized).
	 * @param string $title     Page title.
	 * @param string $status    Page status (publish|draft|pending).
	 * @param bool   $overwrite     If true, existing page+assets with same slug are overwritten.
	 * @param string $assets_folder On-disk folder name to extract into (under metasync-pages/).
	 *                              Defaults to the slug when empty. Must match the path LPS baked
	 *                              into the bundle's asset URLs at build time.
	 * @return array|WP_Error   Response array on success, WP_Error on failure.
	 */
	public function extract_and_create_lps_page($zip_path, $slug, $title, $status = 'publish', $overwrite = true, $assets_folder = '')
	{
		if (!class_exists('ZipArchive')) {
			return new WP_Error(
				'zip_unavailable',
				'ZipArchive PHP extension is not available on this server.',
				array('status' => 500)
			);
		}

		// Basic slug sanity (single-page slugs never contain path separators). Empty
		// is allowed here because multi-page imports take their slugs from the manifest.
		if (is_string($slug) && $slug !== '' && (strpos($slug, '..') !== false || strpos($slug, '/') !== false || strpos($slug, '\\') !== false)) {
			return new WP_Error(
				'invalid_slug_path',
				'Slug must not contain path separators or parent references.',
				array('status' => 400)
			);
		}
		$slug = is_string($slug) ? sanitize_title($slug) : '';

		$upload_dir = wp_upload_dir();
		if (!empty($upload_dir['error'])) {
			return new WP_Error(
				'upload_dir_error',
				'WordPress uploads directory error: ' . $upload_dir['error'],
				array('status' => 500)
			);
		}

		$base_dir = trailingslashit($upload_dir['basedir']) . 'metasync-pages';

		// Resolve the on-disk assets folder. LPS bakes this path into the bundle's
		// asset URLs (Vite `base`) at build time, so it is intentionally separate
		// from the page slug. Fall back to the slug for single-page imports that do
		// not send one. Do NOT transform the value (no sanitize_title) — it must
		// match exactly what LPS baked in — only reject anything unsafe.
		$assets_folder = is_string($assets_folder) ? trim($assets_folder) : '';
		if ($assets_folder === '') {
			$assets_folder = $slug;
		}
		if ($assets_folder === '' || !preg_match('#^[A-Za-z0-9._-]+$#', $assets_folder) || strpos($assets_folder, '..') !== false || $assets_folder[0] === '.') {
			return new WP_Error(
				'invalid_assets_folder',
				'A valid assets_folder (or a slug to derive it from) is required; it may contain only letters, numbers, dot, underscore and hyphen, must not start with a dot, and must not contain "..".',
				array('status' => 400)
			);
		}

		$target_dir = trailingslashit($base_dir) . $assets_folder;

		// Ensure the parent metasync-pages directory exists, then resolve it for
		// containment checks against the staging and target directories.
		if (!file_exists($base_dir)) {
			wp_mkdir_p($base_dir);
		}
		$base_real = realpath($base_dir);
		if ($base_real === false) {
			return new WP_Error(
				'uploads_dir_unwritable',
				'Could not create or resolve the metasync-pages uploads directory.',
				array('status' => 500)
			);
		}

		// Concurrency guard: serialize imports that target the same assets_folder so
		// two simultaneous requests cannot race on the same extract/swap. The lock is
		// an flock() on a per-folder lock file, explicitly released in the finally
		// block below; the OS also drops it if the request dies, so a crash can never
		// leave a stale lock behind.
		$lps_import_lock = $this->acquire_import_lock($assets_folder, $base_dir);
		if (is_wp_error($lps_import_lock)) {
			return $lps_import_lock;
		}

		// Everything below runs under the import lock; the finally block guarantees
		// the lock is explicitly released no matter which return path is taken.
		try {

		// Extract into a staging directory first. The live assets are only removed
		// and replaced once the new bundle is fully extracted AND validated, so a
		// failed or partial extraction can never destroy the currently-served page.
		$staging_dir = trailingslashit($base_dir) . '.import-tmp-' . $assets_folder . '-' . uniqid();
		if (!wp_mkdir_p($staging_dir)) {
			return new WP_Error(
				'staging_mkdir_failed',
				'Failed to create a staging directory for extraction.',
				array('status' => 500)
			);
		}
		$staging_real = realpath($staging_dir);
		if ($staging_real === false || strpos($staging_real, $base_real) !== 0) {
			$this->recursive_rmdir($staging_dir);
			return new WP_Error(
				'path_traversal_detected',
				'Resolved staging directory escapes the metasync-pages base.',
				array('status' => 400)
			);
		}

		$zip = new ZipArchive();
		$open_result = $zip->open($zip_path);
		if ($open_result !== true) {
			$this->recursive_rmdir($staging_dir);
			return new WP_Error(
				'zip_open_failed',
				'Failed to open ZIP archive (code: ' . intval($open_result) . ').',
				array('status' => 400)
			);
		}

		// ZIP Slip guard: validate every entry name before extraction.
		// Reject absolute paths, parent traversal, and disallow .php files entirely.
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$entry = $zip->getNameIndex($i);
			if ($entry === false || $entry === '') {
				$zip->close();
				$this->recursive_rmdir($staging_dir);
				return new WP_Error('zip_invalid_entry', 'ZIP contains an invalid entry name.', array('status' => 400));
			}
			$normalized = str_replace('\\', '/', $entry);
			if ($normalized[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $normalized)) {
				$zip->close();
				$this->recursive_rmdir($staging_dir);
				return new WP_Error('zip_slip_detected', 'ZIP contains an entry with an unsafe path: ' . sanitize_text_field($entry), array('status' => 400));
			}
			$ext = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
			$blocked_exts = array('php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'pl', 'py', 'jsp', 'asp', 'aspx', 'cgi', 'sh', 'htaccess');
			if (in_array($ext, $blocked_exts, true) || basename($normalized) === '.htaccess') {
				$zip->close();
				$this->recursive_rmdir($staging_dir);
				return new WP_Error('zip_unsafe_entry', 'ZIP contains a disallowed file type: ' . sanitize_text_field($entry), array('status' => 400));
			}
		}

		$extracted = $zip->extractTo($staging_dir);
		$zip->close();

		if (!$extracted) {
			$this->recursive_rmdir($staging_dir);
			return new WP_Error(
				'zip_extract_failed',
				'Failed to extract ZIP archive contents.',
				array('status' => 500)
			);
		}

		// Drop a hardening .htaccess to block any script execution from the directory.
		// (Apache-only; on Nginx the pre-extraction extension blocklist above is the
		// real guard.) Written into staging so it travels with the swap.
		$htaccess_content = "# Auto-generated by MetaSync LPS import — do not edit.\n"
			. "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phar|pl|py|jsp|asp|aspx|cgi|sh)$\">\n"
			. "    Require all denied\n"
			. "    Deny from all\n"
			. "</FilesMatch>\n"
			. "Options -ExecCGI\n"
			. "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar\n"
			. "RemoveType .php .phtml .php3 .php4 .php5 .php7 .phar\n";
		@file_put_contents(trailingslashit($staging_dir) . '.htaccess', $htaccess_content);

		$assets_dir_url = trailingslashit($upload_dir['baseurl']) . 'metasync-pages/' . $assets_folder;

		// Decide mode: a pages.manifest.json at the bundle root means multi-page.
		$has_manifest = file_exists(trailingslashit($staging_dir) . 'pages.manifest.json');

		// ---- Multi-page import (manifest-driven) --------------------------------
		if ($has_manifest) {
			// Parse and validate the manifest while it is still only in staging, so a
			// broken manifest can neither leave an orphan folder nor destroy a prior
			// import — the live assets stay untouched until we know the bundle is good.
			$manifest_raw = file_get_contents(trailingslashit($staging_dir) . 'pages.manifest.json');
			if ($manifest_raw === false) {
				$this->recursive_rmdir($staging_dir);
				return new WP_Error('manifest_unreadable', 'Could not read pages.manifest.json.', array('status' => 500));
			}
			$manifest = json_decode($manifest_raw, true);
			if (!is_array($manifest) || empty($manifest['pages']) || !is_array($manifest['pages'])) {
				$this->recursive_rmdir($staging_dir);
				return new WP_Error('invalid_manifest', 'pages.manifest.json is missing a non-empty "pages" array.', array('status' => 422));
			}

			// Verify every page's pre-rendered HTML is present in staging BEFORE the
			// swap. A structurally incomplete bundle must be rejected here — otherwise
			// the swap would wipe a prior import's live assets and only then discover
			// the missing files, leaving the existing pages broken. (Slug conflicts are
			// different: they are reported per-page; a missing HTML file is a bad bundle.)
			foreach ($manifest['pages'] as $manifest_page) {
				if (!is_array($manifest_page)) {
					continue;
				}
				$mp_slug = isset($manifest_page['slug']) ? trim((string) $manifest_page['slug'], '/') : '';
				$mp_rel = ($mp_slug === '') ? 'index.html' : $mp_slug . '/index.html';
				if (!file_exists(trailingslashit($staging_dir) . $mp_rel)) {
					$this->recursive_rmdir($staging_dir);
					return new WP_Error(
						'incomplete_bundle',
						'Bundle is missing index.html for page "' . sanitize_text_field($mp_slug === '' ? '(home)' : $mp_slug) . '"; aborting before touching live assets.',
						array('status' => 422)
					);
				}
			}

			$swap = $this->swap_staging_into_place($staging_dir, $target_dir, $base_real);
			if (is_wp_error($swap)) {
				return $swap;
			}
			return $this->create_pages_from_manifest($manifest['pages'], $target_dir, $assets_folder, $assets_dir_url, $status, $overwrite);
		}

		// ---- Single-page import -------------------------------------------------
		if (empty($slug)) {
			$this->recursive_rmdir($staging_dir);
			return new WP_Error('invalid_slug', 'A non-empty slug is required for a single-page import.', array('status' => 400));
		}
		if (empty($title)) {
			$this->recursive_rmdir($staging_dir);
			return new WP_Error('missing_title', 'A non-empty title is required for a single-page import.', array('status' => 400));
		}

		// Validate the bundle in staging BEFORE touching the live directory.
		$index_path = trailingslashit($staging_dir) . 'index.html';
		if (!file_exists($index_path)) {
			$this->recursive_rmdir($staging_dir);
			return new WP_Error('missing_index_html', 'index.html was not found at the root of the extracted ZIP.', array('status' => 422));
		}
		$html = file_get_contents($index_path);
		if ($html === false) {
			$this->recursive_rmdir($staging_dir);
			return new WP_Error('index_html_unreadable', 'Could not read index.html from the extracted ZIP.', array('status' => 500));
		}

		// Ownership gate — checked while the new bundle is still only in staging, so
		// rejecting here leaves the live page and its assets completely untouched.
		// Only pages we previously created via LPS import may be overwritten.
		$existing_page = get_page_by_path($slug, OBJECT, 'page');
		if ($existing_page) {
			$is_lps_page = get_post_meta($existing_page->ID, Metasync_Custom_Pages::META_LPS_IMPORT, true) === '1';
			if (!$is_lps_page) {
				$this->recursive_rmdir($staging_dir);
				return new WP_Error(
					'slug_taken_by_existing_content',
					'A page already exists at this slug and was not created by LPS, so it will not be overwritten.',
					array(
						'status'         => 409,
						'conflict_with'  => array(
							'post_id'   => (int) $existing_page->ID,
							'post_type' => $existing_page->post_type,
							'title'     => $existing_page->post_title,
						),
						'suggested_slug' => $this->suggest_available_slug($slug),
					)
				);
			}
			if (!$overwrite) {
				$this->recursive_rmdir($staging_dir);
				return new WP_Error(
					'page_slug_exists',
					'An LPS page already exists at this slug. Pass overwrite=true to replace it.',
					array('status' => 409, 'suggested_slug' => $this->suggest_available_slug($slug))
				);
			}
		}

		// Bundle validated and ownership confirmed — swap into place.
		$swap = $this->swap_staging_into_place($staging_dir, $target_dir, $base_real);
		if (is_wp_error($swap)) {
			return $swap;
		}

		if ($existing_page) {
			$update_result = wp_update_post(array(
				'ID' => $existing_page->ID,
				'post_title' => $title,
				'post_status' => $status
			), true);
			if (is_wp_error($update_result)) {
				return new WP_Error('page_update_failed', 'Failed to update existing page: ' . $update_result->get_error_message(), array('status' => 500));
			}
			$page_id = $existing_page->ID;
		} else {
			$page_id = wp_insert_post(array(
				'post_title' => $title,
				'post_name' => $slug,
				'post_type' => 'page',
				'post_status' => $status,
				'post_content' => ''
			), true);
			if (is_wp_error($page_id)) {
				return new WP_Error('page_insert_failed', 'Failed to create page: ' . $page_id->get_error_message(), array('status' => 500));
			}
		}

		update_post_meta($page_id, Metasync_Custom_Pages::META_IS_CUSTOM_HTML_PAGE, '1');
		update_post_meta($page_id, Metasync_Custom_Pages::META_CREATED_VIA_API, '1');
		update_post_meta($page_id, Metasync_Custom_Pages::META_LPS_IMPORT, '1');
		update_post_meta($page_id, Metasync_Custom_Pages::META_ASSETS_FOLDER, $assets_folder);
		update_post_meta($page_id, Metasync_Custom_Pages::META_HTML_ENABLED, '1');
		update_post_meta($page_id, Metasync_Custom_Pages::META_HTML_CONTENT, wp_unslash($html));
		update_post_meta($page_id, Metasync_Custom_Pages::META_HTML_FILENAME, 'index.html');

		wp_cache_delete($page_id, 'posts');
		wp_cache_delete($page_id, 'post_meta');

		return array(
			'success' => true,
			'message' => 'LPS ZIP imported successfully',
			'data' => array(
				'mode' => 'single',
				'page_id' => $page_id,
				'slug' => get_post_field('post_name', $page_id),
				'url' => get_permalink($page_id),
				'edit_url' => get_edit_post_link($page_id, ''),
				'status' => get_post_status($page_id),
				'html_length' => strlen($html),
				'assets_folder' => $assets_folder,
				'assets_dir_url' => $assets_dir_url
			)
		);

		} finally {
			if (is_resource($lps_import_lock)) {
				@flock($lps_import_lock, LOCK_UN);
				@fclose($lps_import_lock);
			}
		}
	}

	/**
	 * Acquire an exclusive, non-blocking lock for an assets_folder import.
	 *
	 * Uses flock() on a per-folder lock file under metasync-pages/.locks/. The
	 * returned handle must stay referenced for the duration of the import; the lock
	 * releases when the handle closes (on return / request end), so a crash cannot
	 * leave a stale lock. Returns a 423 WP_Error if another import for the same
	 * folder is already running.
	 *
	 * @param string $assets_folder Folder key to lock on.
	 * @param string $base_dir      The metasync-pages base directory.
	 * @return resource|WP_Error    Open file handle holding the lock, or WP_Error.
	 */
	private function acquire_import_lock($assets_folder, $base_dir)
	{
		$lock_dir = trailingslashit($base_dir) . '.locks';
		if (!file_exists($lock_dir)) {
			wp_mkdir_p($lock_dir);
		}
		$lock_path = trailingslashit($lock_dir) . md5($assets_folder) . '.lock';

		$handle = @fopen($lock_path, 'c');
		if ($handle === false) {
			return new WP_Error('lock_unavailable', 'Could not open the import lock file.', array('status' => 500));
		}
		if (!flock($handle, LOCK_EX | LOCK_NB)) {
			fclose($handle);
			return new WP_Error(
				'import_in_progress',
				'Another import for this assets_folder is already in progress. Retry shortly.',
				array('status' => 423)
			);
		}
		return $handle;
	}

	/**
	 * Move a validated staging directory into its final place atomically.
	 *
	 * Removes any existing target dir, renames staging over it, then re-checks
	 * containment under the metasync-pages base.
	 *
	 * @return true|WP_Error
	 */
	private function swap_staging_into_place($staging_dir, $target_dir, $base_real)
	{
		if (file_exists($target_dir)) {
			$this->recursive_rmdir($target_dir);
		}
		if (!@rename($staging_dir, $target_dir)) {
			$this->recursive_rmdir($staging_dir);
			return new WP_Error(
				'assets_swap_failed',
				'Failed to move the extracted assets into place.',
				array('status' => 500)
			);
		}
		$target_real = realpath($target_dir);
		if ($target_real === false || strpos($target_real, $base_real) !== 0) {
			return new WP_Error(
				'path_traversal_detected',
				'Resolved target directory escapes the metasync-pages base.',
				array('status' => 400)
			);
		}
		return true;
	}

	/**
	 * Create or update one WordPress page per entry in pages.manifest.json.
	 *
	 * All pages share the single $assets_folder. Slugs are created shallowest-first
	 * so parent pages exist before their children (e.g. "departments" before
	 * "departments/cardiology"); missing ancestors are auto-created as LPS-owned
	 * placeholder pages. Per-page conflicts with non-LPS content are skipped and
	 * reported with a suggested_slug rather than overwriting the user's content.
	 *
	 * @param array $pages Validated manifest "pages" array (parsed by the caller
	 *                     before the staging→live swap).
	 * @return array Response with created/updated/failed arrays.
	 */
	private function create_pages_from_manifest($pages, $target_dir, $assets_folder, $assets_dir_url, $status, $overwrite)
	{
		// Shallowest slugs first so parents are created before their children.
		usort($pages, function ($a, $b) {
			$da = isset($a['slug']) ? substr_count(trim((string) $a['slug'], '/'), '/') : 0;
			$db = isset($b['slug']) ? substr_count(trim((string) $b['slug'], '/'), '/') : 0;
			if ($da === $db) {
				return 0;
			}
			return ($da < $db) ? -1 : 1;
		});

		$created = array();
		$updated = array();
		$failed  = array();
		$slug_to_id = array(); // full slug path => page ID, for parent resolution

		foreach ($pages as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$is_home  = !empty($entry['isHome']);
			$raw_slug = isset($entry['slug']) ? trim((string) $entry['slug'], '/') : '';

			// Home page (empty slug) maps to a dedicated 'home' slug. We deliberately
			// do NOT change the site's front page — that stays a manual decision.
			$effective_slug = ($is_home && $raw_slug === '') ? 'home' : $raw_slug;

			// Normalize each segment exactly as WordPress will persist it, so the
			// get_page_by_path() lookups and stored records match what wp_insert_post
			// creates (a raw mixed-case slug would desync the object cache). The raw
			// slug is still used for the on-disk HTML path, which mirrors the bundle.
			$effective_slug = implode('/', array_map('sanitize_title', explode('/', $effective_slug)));

			if ($effective_slug === '') {
				$failed[] = array('slug' => '(home)', 'code' => 'invalid_slug', 'message' => 'Page has an empty slug.');
				continue;
			}

			// Per-page title from the manifest (name, then seo.title).
			$seo = (isset($entry['seo']) && is_array($entry['seo'])) ? $entry['seo'] : array();
			$title = '';
			if (!empty($entry['name'])) {
				$title = sanitize_text_field($entry['name']);
			} elseif (!empty($seo['title'])) {
				$title = sanitize_text_field($seo['title']);
			}
			if ($title === '') {
				$title = $effective_slug;
			}

			// Locate this page's pre-rendered HTML inside the bundle.
			$rel = ($raw_slug === '') ? 'index.html' : $raw_slug . '/index.html';
			$html_file = trailingslashit($target_dir) . $rel;
			if (!file_exists($html_file)) {
				$failed[] = array('slug' => $effective_slug, 'code' => 'missing_html', 'message' => 'No index.html for this page in the bundle.');
				continue;
			}
			$html = file_get_contents($html_file);
			if ($html === false) {
				$failed[] = array('slug' => $effective_slug, 'code' => 'html_unreadable', 'message' => 'Could not read this page\'s index.html.');
				continue;
			}

			// Split slug into ancestors + leaf; resolve/create the parent chain.
			$segments = explode('/', $effective_slug);
			$leaf = sanitize_title(array_pop($segments));
			if ($leaf === '') {
				$failed[] = array('slug' => $effective_slug, 'code' => 'invalid_slug', 'message' => 'Slug sanitized to empty.');
				continue;
			}

			$parent_id = 0;
			$parent_ok = true;
			$ancestor_path = '';
			foreach ($segments as $seg) {
				$seg = sanitize_title($seg);
				if ($seg === '') {
					$parent_ok = false;
					break;
				}
				$ancestor_path = ($ancestor_path === '') ? $seg : $ancestor_path . '/' . $seg;
				if (isset($slug_to_id[$ancestor_path])) {
					$parent_id = $slug_to_id[$ancestor_path];
					continue;
				}
				$ancestor_page = get_page_by_path($ancestor_path, OBJECT, 'page');
				if ($ancestor_page) {
					$parent_id = $ancestor_page->ID;
				} else {
					// Auto-create a minimal LPS-owned placeholder so the URL nests.
					$new_parent = wp_insert_post(array(
						'post_title'  => $seg,
						'post_name'   => $seg,
						'post_type'   => 'page',
						'post_status' => $status,
						'post_parent' => $parent_id,
						'post_content' => ''
					), true);
					if (is_wp_error($new_parent)) {
						$parent_ok = false;
						break;
					}
					update_post_meta($new_parent, Metasync_Custom_Pages::META_LPS_IMPORT, '1');
					update_post_meta($new_parent, Metasync_Custom_Pages::META_ASSETS_FOLDER, $assets_folder);
					$parent_id = $new_parent;
				}
				$slug_to_id[$ancestor_path] = $parent_id;
			}
			if (!$parent_ok) {
				$failed[] = array('slug' => $effective_slug, 'code' => 'parent_failed', 'message' => 'Could not resolve the parent page hierarchy.');
				continue;
			}

			// Ownership check for the leaf page.
			$existing = get_page_by_path($effective_slug, OBJECT, 'page');
			if ($existing) {
				$is_lps = get_post_meta($existing->ID, Metasync_Custom_Pages::META_LPS_IMPORT, true) === '1';
				if (!$is_lps) {
					$failed[] = array(
						'slug'           => $effective_slug,
						'code'           => 'slug_taken_by_existing_content',
						'conflict_with'  => array('post_id' => (int) $existing->ID, 'post_type' => $existing->post_type, 'title' => $existing->post_title),
						'suggested_slug' => $this->suggest_available_slug($effective_slug),
					);
					continue;
				}
				if (!$overwrite) {
					$failed[] = array('slug' => $effective_slug, 'code' => 'page_slug_exists', 'suggested_slug' => $this->suggest_available_slug($effective_slug));
					continue;
				}
			}

			// Create or update.
			if ($existing) {
				$res = wp_update_post(array(
					'ID' => $existing->ID,
					'post_title' => $title,
					'post_status' => $status,
					'post_parent' => $parent_id
				), true);
				if (is_wp_error($res)) {
					$failed[] = array('slug' => $effective_slug, 'code' => 'page_update_failed', 'message' => $res->get_error_message());
					continue;
				}
				$page_id = $existing->ID;
				$was_update = true;
			} else {
				$page_id = wp_insert_post(array(
					'post_title'  => $title,
					'post_name'   => $leaf,
					'post_type'   => 'page',
					'post_status' => $status,
					'post_parent' => $parent_id,
					'post_content' => ''
				), true);
				if (is_wp_error($page_id)) {
					$failed[] = array('slug' => $effective_slug, 'code' => 'page_insert_failed', 'message' => $page_id->get_error_message());
					continue;
				}
				$was_update = false;
			}

			update_post_meta($page_id, Metasync_Custom_Pages::META_IS_CUSTOM_HTML_PAGE, '1');
			update_post_meta($page_id, Metasync_Custom_Pages::META_CREATED_VIA_API, '1');
			update_post_meta($page_id, Metasync_Custom_Pages::META_LPS_IMPORT, '1');
			update_post_meta($page_id, Metasync_Custom_Pages::META_ASSETS_FOLDER, $assets_folder);
			update_post_meta($page_id, Metasync_Custom_Pages::META_HTML_ENABLED, '1');
			update_post_meta($page_id, Metasync_Custom_Pages::META_HTML_CONTENT, wp_unslash($html));
			update_post_meta($page_id, Metasync_Custom_Pages::META_HTML_FILENAME, $rel);
			wp_cache_delete($page_id, 'posts');
			wp_cache_delete($page_id, 'post_meta');

			$slug_to_id[$effective_slug] = $page_id;
			$record = array('slug' => $effective_slug, 'page_id' => $page_id, 'url' => get_permalink($page_id));
			if ($was_update) {
				$updated[] = $record;
			} else {
				$created[] = $record;
			}
		}

		return array(
			'success' => true,
			'message' => sprintf('LPS multi-page import complete: %d created, %d updated, %d failed.', count($created), count($updated), count($failed)),
			'data' => array(
				'mode'           => 'multi',
				'assets_folder'  => $assets_folder,
				'assets_dir_url' => $assets_dir_url,
				'pages_total'    => count($pages),
				'created'        => $created,
				'updated'        => $updated,
				'failed'         => $failed,
			)
		);
	}

	/**
	 * Find the next available page path by appending -2, -3, ... to the LEAF
	 * segment, when the requested slug is taken by content we may not overwrite.
	 *
	 * Accepts a full slug path. For a nested slug the suffix is applied to the
	 * last segment and the candidate is checked at its real position under the
	 * parent (e.g. "departments/cardiology" -> "departments/cardiology-2"), not
	 * at the site root.
	 *
	 * @param string $slug The desired (already sanitized) slug, possibly nested.
	 * @return string A full slug path that no existing page currently uses.
	 */
	private function suggest_available_slug($slug)
	{
		$slug = trim((string) $slug, '/');
		$pos = strrpos($slug, '/');
		$parent = ($pos === false) ? '' : substr($slug, 0, $pos + 1); // keeps trailing slash
		$leaf   = ($pos === false) ? $slug : substr($slug, $pos + 1);

		for ($i = 2; $i <= 100; $i++) {
			$candidate = $parent . $leaf . '-' . $i;
			if (!get_page_by_path($candidate, OBJECT, 'page')) {
				return $candidate;
			}
		}

		// Extremely unlikely fallback: append a random suffix.
		return $parent . $leaf . '-' . wp_rand(1000, 9999);
	}

	/**
	 * Recursively delete a directory and its contents.
	 *
	 * Safe to call only on paths under wp-content/uploads/metasync-pages/ —
	 * callers are responsible for that containment check.
	 */
	private function recursive_rmdir($dir)
	{
		if (!file_exists($dir)) {
			return true;
		}

		if (!is_dir($dir)) {
			return @unlink($dir);
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $item) {
			if ($item->isDir()) {
				@rmdir($item->getPathname());
			} else {
				@unlink($item->getPathname());
			}
		}

		return @rmdir($dir);
	}
}

<?php
/**
 * MCP Tools for Custom HTML Pages Operations
 *
 * Provides MCP tools for managing custom HTML pages.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(dirname(__FILE__)) . 'class-mcp-tool-base.php';

/**
 * Create Custom Page Tool
 *
 * Creates a new custom HTML page
 */
class MCP_Tool_Create_Custom_Page extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_create_custom_page';
    }

    public function get_description() {
        return 'Create a new custom HTML page with raw HTML content';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Page title',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Page slug (URL-friendly name, optional)',
                ],
                'html_content' => [
                    'type' => 'string',
                    'description' => 'Raw HTML content for the page',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['publish', 'draft', 'pending'],
                    'description' => 'Page status (default: publish)',
                ],
                'enable_raw_html' => [
                    'type' => 'boolean',
                    'description' => 'Enable raw HTML mode to bypass theme (default: true)',
                ],
                'filename' => [
                    'type' => 'string',
                    'description' => 'Original HTML filename for reference (optional)',
                ],
            ],
            'required' => ['title', 'html_content'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('edit_pages');

        // Load custom pages class constants
        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'custom-pages/class-metasync-custom-pages.php';

        // Prepare page data
        $page_data = [
            'post_title' => sanitize_text_field($params['title']),
            'post_type' => 'page',
            'post_status' => isset($params['status']) ? sanitize_text_field($params['status']) : 'publish',
            'post_content' => '', // HTML stored in meta
        ];

        // Set slug if provided
        if (!empty($params['slug'])) {
            $page_data['post_name'] = sanitize_title($params['slug']);

            // Check if page with same slug already exists
            $existing_page = get_page_by_path($page_data['post_name'], OBJECT, 'page');
            if ($existing_page) {
                throw new Exception('A page with this slug already exists');
            }
        }

        // Insert the page
        $page_id = wp_insert_post($page_data, true);

        if (is_wp_error($page_id)) {
            throw new Exception('Failed to create page: ' . $page_id->get_error_message());
        }

        // Mark as custom HTML page
        update_post_meta($page_id, Metasync_Custom_Pages::META_IS_CUSTOM_HTML_PAGE, '1');

        // Mark as created via API
        update_post_meta($page_id, Metasync_Custom_Pages::META_CREATED_VIA_API, '1');

        // Enable raw HTML mode
        $enable_raw_html = isset($params['enable_raw_html']) ? (bool)$params['enable_raw_html'] : true;
        update_post_meta($page_id, Metasync_Custom_Pages::META_HTML_ENABLED, $enable_raw_html ? '1' : '0');

        // Store HTML content (no sanitization - admin users are trusted)
        update_post_meta($page_id, Metasync_Custom_Pages::META_HTML_CONTENT, wp_unslash($params['html_content']));

        // Store filename if provided
        if (!empty($params['filename'])) {
            update_post_meta($page_id, Metasync_Custom_Pages::META_HTML_FILENAME, sanitize_file_name($params['filename']));
        }

        // Clear cache
        wp_cache_delete($page_id, 'posts');
        wp_cache_delete($page_id, 'post_meta');

        return $this->success([
            'page_id' => $page_id,
            'title' => get_the_title($page_id),
            'slug' => get_post_field('post_name', $page_id),
            'url' => get_permalink($page_id),
            'edit_url' => get_edit_post_link($page_id, 'raw'),
            'status' => get_post_status($page_id),
            'raw_html_enabled' => $enable_raw_html,
            'html_length' => strlen($params['html_content']),
            'created_at' => get_post_field('post_date', $page_id),
            'message' => 'Custom HTML page created successfully',
        ]);
    }
}

/**
 * Get Custom Page Tool
 *
 * Retrieves a custom HTML page
 */
class MCP_Tool_Get_Custom_Page extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_custom_page';
    }

    public function get_description() {
        return 'Get a custom HTML page by ID or slug';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'page_id' => [
                    'type' => 'integer',
                    'description' => 'Page ID',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Page slug',
                ],
            ],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('edit_pages');

        // Load custom pages class constants
        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'custom-pages/class-metasync-custom-pages.php';

        $page_id = isset($params['page_id']) ? intval($params['page_id']) : null;
        $slug = isset($params['slug']) ? sanitize_text_field($params['slug']) : null;

        if (empty($page_id) && empty($slug)) {
            throw new Exception('Either page_id or slug must be provided');
        }

        // Get page by ID or slug
        if (!empty($page_id)) {
            $page = get_post($page_id);
        } else {
            $page = get_page_by_path($slug, OBJECT, 'page');
        }

        if (!$page || $page->post_type !== 'page') {
            throw new Exception('Page not found');
        }

        // Verify it's a custom HTML page
        $is_custom_html_page = get_post_meta($page->ID, Metasync_Custom_Pages::META_IS_CUSTOM_HTML_PAGE, true);
        if ($is_custom_html_page !== '1') {
            throw new Exception('This page is not a custom HTML page');
        }

        // Get HTML content and metadata
        $html_content = get_post_meta($page->ID, Metasync_Custom_Pages::META_HTML_CONTENT, true);
        $html_enabled = get_post_meta($page->ID, Metasync_Custom_Pages::META_HTML_ENABLED, true);
        $html_filename = get_post_meta($page->ID, Metasync_Custom_Pages::META_HTML_FILENAME, true);

        return $this->success([
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
            'updated_at' => $page->post_modified,
        ]);
    }
}

/**
 * List Custom Pages Tool
 *
 * Lists all custom HTML pages
 */
class MCP_Tool_List_Custom_Pages extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_list_custom_pages';
    }

    public function get_description() {
        return 'List all custom HTML pages';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['publish', 'draft', 'pending', 'all'],
                    'description' => 'Filter by page status (default: all)',
                ],
            ],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('edit_pages');

        // Load custom pages class
        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'custom-pages/class-metasync-custom-pages.php';

        // Build query args
        $args = [];
        if (isset($params['status']) && $params['status'] !== 'all') {
            $args['post_status'] = sanitize_text_field($params['status']);
        }

        $pages = Metasync_Custom_Pages::get_custom_pages($args);

        $pages_data = [];
        foreach ($pages as $page) {
            $html_enabled = get_post_meta($page->ID, Metasync_Custom_Pages::META_HTML_ENABLED, true);
            $html_content = get_post_meta($page->ID, Metasync_Custom_Pages::META_HTML_CONTENT, true);
            $html_filename = get_post_meta($page->ID, Metasync_Custom_Pages::META_HTML_FILENAME, true);

            $pages_data[] = [
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
                'updated_at' => $page->post_modified,
            ];
        }

        return $this->success([
            'count' => count($pages_data),
            'pages' => $pages_data,
        ]);
    }
}

/**
 * Update Custom Page Tool
 *
 * Updates an existing custom HTML page
 */
class MCP_Tool_Update_Custom_Page extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_custom_page';
    }

    public function get_description() {
        return 'Update an existing custom HTML page';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'page_id' => [
                    'type' => 'integer',
                    'description' => 'Page ID to update',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Page title (optional)',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Page slug (optional)',
                ],
                'html_content' => [
                    'type' => 'string',
                    'description' => 'Raw HTML content (optional)',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['publish', 'draft', 'pending'],
                    'description' => 'Page status (optional)',
                ],
                'enable_raw_html' => [
                    'type' => 'boolean',
                    'description' => 'Enable raw HTML mode (optional)',
                ],
                'filename' => [
                    'type' => 'string',
                    'description' => 'HTML filename (optional)',
                ],
            ],
            'required' => ['page_id'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('edit_pages');

        // Load custom pages class constants
        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'custom-pages/class-metasync-custom-pages.php';

        $page_id = intval($params['page_id']);

        // Verify page exists
        $page = get_post($page_id);
        if (!$page || $page->post_type !== 'page') {
            throw new Exception(sprintf("Page not found with ID: %d", absint($page_id)));
        }

        // Verify it's a custom HTML page
        $is_custom_html_page = get_post_meta($page_id, Metasync_Custom_Pages::META_IS_CUSTOM_HTML_PAGE, true);
        if ($is_custom_html_page !== '1') {
            throw new Exception('This page is not a custom HTML page');
        }

        // Update page data if provided
        $update_data = ['ID' => $page_id];

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
                throw new Exception('Failed to update page: ' . $result->get_error_message());
            }
        }

        // Update HTML content if provided
        if (isset($params['html_content'])) {
            update_post_meta($page_id, Metasync_Custom_Pages::META_HTML_CONTENT, wp_unslash($params['html_content']));
        }

        // Update raw HTML mode if provided
        if (isset($params['enable_raw_html'])) {
            $enable_raw_html = (bool)$params['enable_raw_html'];
            update_post_meta($page_id, Metasync_Custom_Pages::META_HTML_ENABLED, $enable_raw_html ? '1' : '0');
        }

        // Update filename if provided
        if (isset($params['filename'])) {
            update_post_meta($page_id, Metasync_Custom_Pages::META_HTML_FILENAME, sanitize_file_name($params['filename']));
        }

        // Clear cache
        wp_cache_delete($page_id, 'posts');
        wp_cache_delete($page_id, 'post_meta');

        return $this->success([
            'page_id' => $page_id,
            'title' => get_the_title($page_id),
            'slug' => get_post_field('post_name', $page_id),
            'url' => get_permalink($page_id),
            'edit_url' => get_edit_post_link($page_id, 'raw'),
            'status' => get_post_status($page_id),
            'raw_html_enabled' => get_post_meta($page_id, Metasync_Custom_Pages::META_HTML_ENABLED, true) === '1',
            'html_length' => strlen(get_post_meta($page_id, Metasync_Custom_Pages::META_HTML_CONTENT, true)),
            'updated_at' => get_post_field('post_modified', $page_id),
            'message' => 'Custom HTML page updated successfully',
        ]);
    }
}

/**
 * Delete Custom Page Tool
 *
 * Deletes a custom HTML page
 */
class MCP_Tool_Delete_Custom_Page extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_delete_custom_page';
    }

    public function get_description() {
        return 'Delete a custom HTML page permanently';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'page_id' => [
                    'type' => 'integer',
                    'description' => 'Page ID to delete',
                ],
            ],
            'required' => ['page_id'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('delete_pages');

        // Delegate to the shared REST/MCP delete implementation so both paths
        // perform identical asset cleanup and front-page reset.
        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'custom-pages/class-metasync-custom-pages.php';
        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'custom-pages/class-metasync-custom-pages-api.php';

        $page_id = intval($params['page_id']);

        $api = new Metasync_Custom_Pages_API();
        $result = $api->delete_custom_page_with_cleanup($page_id);

        if (is_wp_error($result)) {
            throw new Exception($result->get_error_message());
        }

        return $this->success(array_merge($result, [
            'message' => 'Custom HTML page deleted successfully',
        ]));
    }
}

/**
 * Import LPS Page Tool
 *
 * Imports an LPS (Landing Page Studio) ZIP export into WordPress as a custom
 * HTML page, extracting bundled assets to wp-content/uploads/metasync-pages/{slug}/.
 * Re-importing with the same slug overwrites the previous page and assets.
 */
class MCP_Tool_Import_LPS_Page extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_import_lps_page';
    }

    public function get_description() {
        return 'Import an LPS (Landing Page Studio) ZIP export into WordPress as a custom HTML page, extracting assets to uploads/metasync-pages/{slug}/';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'download_url' => [
                    'type' => 'string',
                    'description' => 'URL to download the LPS ZIP from.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Page title (single-page imports only). Ignored for multi-page bundles, which take titles from pages.manifest.json.',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'URL slug (single-page imports only). Required when the ZIP has no pages.manifest.json; ignored for multi-page bundles. Re-importing with the same slug overwrites the previous LPS page.',
                ],
                'assets_folder' => [
                    'type' => 'string',
                    'description' => 'On-disk folder name the bundle is extracted to (under uploads/metasync-pages/). This is the path LPS bakes into the asset URLs at build time and is intentionally separate from the slug. Defaults to the slug when omitted.',
                ],
                'external_ref' => [
                    'type' => 'string',
                    'description' => 'LPS project UUID (stable per-project identifier). When provided, used as the primary home-page dedup key instead of assets_folder.',
                ],
                'overwrite' => [
                    'type' => 'boolean',
                    'description' => 'When true (default), an existing page with the same slug is overwritten.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['publish', 'draft', 'pending'],
                    'description' => 'Page status (default: publish)',
                ],
            ],
            'required' => ['download_url'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('edit_pages');

        if (empty($params['download_url'])) {
            throw new Exception('download_url is required.');
        }

        $raw_slug = isset($params['slug']) ? $params['slug'] : '';
        if (is_string($raw_slug) && (strpos($raw_slug, '..') !== false || strpos($raw_slug, '/') !== false || strpos($raw_slug, '\\') !== false)) {
            throw new Exception('Slug must not contain path separators or parent references.');
        }

        $tmp_file = null;
        $max_zip_bytes = 50 * 1024 * 1024;

        // --- Audit tracking (one persistent record on success and failure paths) ---
        $_lps_start_ms    = (int) round(microtime(true) * 1000);
        $_lps_success     = false;
        $_lps_result_data = null;
        $_lps_exc         = null;
        $_lps_err_status  = 0;
        $_lps_downloaded  = 0;
        $_lps_af          = isset($params['assets_folder']) ? $params['assets_folder'] : (isset($params['slug']) ? $params['slug'] : '');
        $_lps_external_ref = isset($params['external_ref']) ? sanitize_text_field($params['external_ref']) : '';

        try {
            // wp_tempnam() lives in wp-admin/includes/file.php, which is NOT loaded
            // during a normal REST/MCP request — load it so this works in any context.
            if (!function_exists('wp_tempnam')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $tmp_file = wp_tempnam('lps_import_');
            if (!$tmp_file) {
                throw new Exception('Failed to allocate a temporary file for the ZIP.');
            }

            $response = wp_safe_remote_get($params['download_url'], [
                'timeout' => 60,
                'stream' => true,
                'filename' => $tmp_file,
            ]);
            if (is_wp_error($response)) {
                throw new Exception('Failed to download ZIP: ' . $response->get_error_message());
            }
            $code = wp_remote_retrieve_response_code($response);
            if ((int) $code !== 200) {
                throw new Exception('ZIP download returned HTTP ' . intval($code));
            }
            $content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
            if ($content_type !== '' && strpos($content_type, 'zip') === false && strpos($content_type, 'octet-stream') === false) {
                throw new Exception('Downloaded ZIP has unexpected Content-Type: ' . $content_type);
            }
            $downloaded_size = file_exists($tmp_file) ? filesize($tmp_file) : 0;
            $_lps_downloaded = $downloaded_size;
            if ($downloaded_size === 0) {
                throw new Exception('Downloaded ZIP file is empty.');
            }
            if ($downloaded_size > $max_zip_bytes) {
                throw new Exception('Downloaded ZIP exceeds the maximum allowed size (' . intval($max_zip_bytes / 1024 / 1024) . ' MB).');
            }

            require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'custom-pages/class-metasync-custom-pages.php';
            require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'custom-pages/class-metasync-custom-pages-api.php';

            $slug = isset($params['slug']) ? sanitize_title($params['slug']) : '';
            $title = isset($params['title']) ? sanitize_text_field($params['title']) : '';
            $status = isset($params['status']) ? sanitize_text_field($params['status']) : 'publish';
            $overwrite = isset($params['overwrite']) ? (bool) $params['overwrite'] : true;
            $assets_folder = isset($params['assets_folder']) ? $params['assets_folder'] : '';
            $external_ref = isset($params['external_ref']) ? sanitize_text_field($params['external_ref']) : '';

            $api = new Metasync_Custom_Pages_API();
            $result = $api->extract_and_create_lps_page($tmp_file, $slug, $title, $status, $overwrite, $assets_folder, $external_ref);

            if (is_wp_error($result)) {
                $_lps_err_data   = $result->get_error_data();
                $_lps_err_status = (is_array($_lps_err_data) && isset($_lps_err_data['status'])) ? (int) $_lps_err_data['status'] : 0;
                throw new Exception($result->get_error_message());
            }

            // Surface the helper's full data payload — the shape differs for single
            // (page_id/url/status) vs multi (created/updated/failed) imports.
            $data = isset($result['data']) && is_array($result['data']) ? $result['data'] : [];
            $data['message'] = isset($result['message']) ? $result['message'] : 'LPS ZIP imported successfully';

            // Capture for the audit record now so the per-page breakdown survives
            // even when the all-failed multi-page case throws below.
            $_lps_result_data = $data;

            // For a multi-page import where EVERY page failed, the helper still
            // returns a non-WP_Error array. Surface that as a failure to the agent
            // (mirrors the REST endpoint's 422) instead of reporting false success.
            if (isset($data['mode']) && $data['mode'] === 'multi') {
                $succeeded = count(isset($data['created']) ? $data['created'] : [])
                    + count(isset($data['updated']) ? $data['updated'] : []);
                $failed = isset($data['failed']) ? $data['failed'] : [];
                if ($succeeded === 0 && count($failed) > 0) {
                    $reasons = array();
                    foreach ($failed as $f) {
                        $reasons[] = (isset($f['slug']) ? $f['slug'] : '?') . ': ' . (isset($f['code']) ? $f['code'] : 'failed');
                    }
                    throw new Exception('LPS import failed for all pages — ' . implode('; ', $reasons));
                }
            }

            $_lps_success     = true;
            return $this->success($data);
        } catch (Exception $e) {
            $_lps_exc = $e;
            throw $e;
        } finally {
            if (!empty($tmp_file) && file_exists($tmp_file)) {
                @unlink($tmp_file);
            }

            // Write exactly one persistent audit record (success or failure),
            // regardless of WP_DEBUG. Load the API class if an early exception
            // fired before the require_once above ran.
            if (!class_exists('Metasync_Custom_Pages_API')) {
                $api_class_path = plugin_dir_path(dirname(dirname(__FILE__))) . 'custom-pages/class-metasync-custom-pages-api.php';
                if (file_exists($api_class_path)) {
                    require_once $api_class_path;
                }
            }
            if (class_exists('Metasync_Custom_Pages_API')) {
                // Derive HTTP status from the captured data when available (covers the
                // success path AND the all-failed multi-page case that throws → 422).
                // A null payload means an early exception (download/extract) → 500.
                if (is_array($_lps_result_data)) {
                    $_s = count(isset($_lps_result_data['created']) ? $_lps_result_data['created'] : array())
                        + count(isset($_lps_result_data['updated']) ? $_lps_result_data['updated'] : array());
                    $_f = count(isset($_lps_result_data['failed']) ? $_lps_result_data['failed'] : array());
                    $_lps_http = ($_f > 0 && $_s > 0) ? 207 : (($_f > 0 && $_s === 0) ? 422 : 200);
                } else {
                    $_lps_http = $_lps_err_status > 0 ? $_lps_err_status : 500;
                }

                Metasync_Custom_Pages_API::write_lps_import_audit(array(
                    'result'         => is_array($_lps_result_data) ? array('success' => $_lps_success, 'data' => $_lps_result_data) : null,
                    'http_status'    => $_lps_http,
                    'input'          => array(
                        'source_type'       => 'download_url',
                        'zip_size_bytes'    => $_lps_downloaded,
                        'assets_folder'     => $_lps_af,
                        'external_ref'      => $_lps_external_ref,
                        'pages_in_manifest' => ($_lps_success && isset($_lps_result_data['pages_total'])) ? (int) $_lps_result_data['pages_total'] : null,
                    ),
                    'start_ms'       => $_lps_start_ms,
                    'auth_method'    => 'mcp-capability',
                    'api_key_prefix' => '',
                    'error_message'  => $_lps_exc ? $_lps_exc->getMessage() : '',
                ));
            }
        }
    }
}

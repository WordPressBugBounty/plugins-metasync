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

        $title = $page->post_title;

        // Delete the page permanently
        $result = wp_delete_post($page_id, true);

        if (!$result) {
            throw new Exception('Failed to delete page');
        }

        return $this->success([
            'page_id' => $page_id,
            'title' => $title,
            'message' => 'Custom HTML page deleted successfully',
        ]);
    }
}

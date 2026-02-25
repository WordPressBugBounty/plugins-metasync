<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}


/**
 * Custom HTML Pages Handler
 *
 * Allows users to upload HTML files and serve them directly on WordPress
 * without theme templating.
 *
 * @package    Metasync
 * @subpackage Metasync/custom-pages
 * @since      1.0.0
 */

class Metasync_Custom_Pages
{
    /**
     * Post type for custom HTML pages (using regular WordPress pages)
     */
    const POST_TYPE = 'page';
    
    /**
     * Meta key for storing raw HTML content
     */
    const META_HTML_CONTENT = '_metasync_raw_html_content';
    
    /**
     * Meta key for enabling raw HTML mode
     */
    const META_HTML_ENABLED = '_metasync_raw_html_enabled';
    
    /**
     * Meta key for storing original filename
     */
    const META_HTML_FILENAME = '_metasync_raw_html_filename';
    
    /**
     * Meta key to mark pages as custom HTML pages (to show meta box)
     */
    const META_IS_CUSTOM_HTML_PAGE = '_metasync_is_custom_html_page';

    /**
     * Meta key to mark pages as created via API
     */
    const META_CREATED_VIA_API = '_metasync_created_via_api';

    /**
     * Initialize the custom pages functionality
     */
    public function __construct()
    {
        // Add meta boxes for page editor (only on marked custom HTML pages)
        add_action('add_meta_boxes', array($this, 'add_custom_html_meta_box'));

        // Mark new pages as custom HTML pages when created from dashboard
        add_action('load-post-new.php', array($this, 'mark_new_custom_html_page'));

        // Save post meta
        add_action('save_post', array($this, 'save_custom_html_meta'), 10, 2);

        // Template redirect to serve raw HTML
        add_action('template_redirect', array($this, 'serve_raw_html'), 5);

        // Handle file uploads
        add_action('wp_ajax_metasync_upload_html', array($this, 'handle_html_upload'));

        // Add admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Clear cache on save
        add_action('save_post', array($this, 'clear_page_cache'), 20, 2);

        // Add custom column to pages list to identify HTML pages
        add_filter('manage_pages_columns', array($this, 'add_custom_html_column'));
        add_action('manage_pages_custom_column', array($this, 'render_custom_html_column'), 10, 2);

        // Initialize REST API endpoints
        $this->init_api();
    }

    /**
     * Initialize REST API endpoints
     */
    private function init_api()
    {
        require_once plugin_dir_path(__FILE__) . 'class-metasync-custom-pages-api.php';
        new Metasync_Custom_Pages_API();
    }

    /**
     * Mark new pages as custom HTML pages when created from dashboard
     */
    public function mark_new_custom_html_page()
    {
        // Check if this is a page being created
        if (isset($_GET['post_type']) && $_GET['post_type'] === 'page') {
            // Check if the metasync_html_page parameter is set
            if (isset($_GET['metasync_html_page']) && $_GET['metasync_html_page'] === '1') {
                // Set a flag in session or add a filter to mark this page on first save
                add_filter('wp_insert_post_data', array($this, 'flag_custom_html_page_on_insert'), 10, 2);
            }
        }
    }
    
    /**
     * Flag to mark the page as custom HTML page on first insert
     */
    public function flag_custom_html_page_on_insert($data, $postarr)
    {
        // Only mark if this is a new page (no ID yet)
        if (empty($postarr['ID'])) {
            // We'll use a transient to mark this page needs the flag
            // The transient will be checked in save_post
            set_transient('metasync_mark_as_html_page_' . get_current_user_id(), true, 60);
        }
        
        return $data;
    }
    
    /**
     * Add meta box for custom HTML upload/edit
     * Only shows on pages marked as custom HTML pages or new pages being created
     */
    public function add_custom_html_meta_box()
    {
        // Don't show meta box if user's role doesn't have plugin access
        if (!Metasync::current_user_has_plugin_access()) {
            return;
        }

        // Get all pages
        global $post;
        
        // Check if this is a marked custom HTML page
        $is_custom_html_page = get_post_meta($post->ID, self::META_IS_CUSTOM_HTML_PAGE, true) === '1';
        
        // OR check if this is a new page being created from dashboard (transient set)
        $transient_key = 'metasync_mark_as_html_page_' . get_current_user_id();
        $is_new_custom_page = get_transient($transient_key) ? true : false;
        
        // Show meta box if either condition is true
        if ($post && ($is_custom_html_page || $is_new_custom_page)) {
            add_meta_box(
                'metasync_custom_html',
                'Custom HTML Settings',
                array($this, 'render_custom_html_meta_box'),
                'page',
                'normal',
                'high'
            );
        }
    }

    /**
     * Render the custom HTML meta box
     */
    public function render_custom_html_meta_box($post)
    {
        // Add nonce for security
        wp_nonce_field('metasync_custom_html_nonce', 'metasync_custom_html_nonce');

        // Get current values
        $html_enabled = get_post_meta($post->ID, self::META_HTML_ENABLED, true);
        $html_content = get_post_meta($post->ID, self::META_HTML_CONTENT, true);
        $html_filename = get_post_meta($post->ID, self::META_HTML_FILENAME, true);
        
        // Debug: Check if content exists
        $content_length = strlen($html_content);
        $has_content = !empty($html_content);

        ?>
        <div class="metasync-custom-html-container">
            <div class="metasync-html-mode">
                <label>
                    <input type="checkbox" 
                           name="metasync_html_enabled" 
                           id="metasync_html_enabled" 
                           value="1" 
                           <?php checked($html_enabled, '1'); ?> />
                    <strong>Enable Raw HTML Mode</strong>
                    <span class="description">(When enabled, the page will display your custom HTML without theme styling)</span>
                </label>
            </div>

            <div id="metasync-html-controls" style="<?php echo $html_enabled ? '' : 'display:none;'; ?>">
                <hr style="margin: 20px 0;">
                
                <div class="metasync-html-upload">
                    <h3>Upload HTML File</h3>
                    <p class="description">Upload a complete HTML file (including &lt;html&gt;, &lt;head&gt;, and &lt;body&gt; tags)</p>
                    
                    <input type="file" 
                           name="metasync_html_file" 
                           id="metasync_html_file" 
                           accept=".html,.htm" 
                           class="metasync-file-input" />
                    
                    <?php if ($html_filename): ?>
                        <p class="current-file">
                            <strong>Current file:</strong> <?php echo esc_html($html_filename); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="metasync-html-editor" style="margin-top: 20px;">
                    <h3>Or Edit HTML Directly</h3>
                    <p class="description">Edit your HTML code directly in the editor below</p>
                    
                    <?php if ($has_content): ?>
                        <p style="padding: 10px; background: #d7f1e0; border-left: 4px solid #00a32a; margin-bottom: 10px;">
                            ✅ <strong>HTML Content Loaded:</strong> <?php echo number_format($content_length); ?> characters
                        </p>
                    <?php else: ?>
                        <p style="padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; margin-bottom: 10px;">
                            ℹ️ <strong>Tip:</strong> Upload an HTML file above or paste your HTML code in the editor below.
                        </p>
                    <?php endif; ?>
                    
                    <textarea name="metasync_html_content" 
                              id="metasync_html_content" 
                              class="large-text code" 
                              rows="20"
                              style="font-family: Consolas, Monaco, monospace; width: 100%;"><?php 
                              // Output HTML content - don't use esc_textarea as it might break large HTML
                              echo htmlspecialchars($html_content, ENT_QUOTES, 'UTF-8'); 
                    ?></textarea>
                </div>

                <div class="metasync-html-preview" style="margin-top: 15px;">
                    <button type="button" class="button button-secondary" id="metasync-preview-html">
                        Preview HTML
                    </button>
                    <span class="description">Preview your custom HTML page in a new window</span>
                </div>
            </div>

        </div>
        <?php
    }

    /**
     * Save custom HTML meta data
     */
    public function save_custom_html_meta($post_id, $post)
    {
        // Check if post object is valid and has post_type property
        if (!is_object($post) || !isset($post->post_type)) {
            return;
        }
        
        // Check if this is a page
        if ('page' !== $post->post_type) {
            return;
        }
        
        // Check if this is a new page created from Custom Pages dashboard
        $transient_key = 'metasync_mark_as_html_page_' . get_current_user_id();
        if (get_transient($transient_key)) {
            // Mark this page as a custom HTML page
            update_post_meta($post_id, self::META_IS_CUSTOM_HTML_PAGE, '1');
            // Delete the transient
            delete_transient($transient_key);
            // Auto-enable HTML mode for new custom HTML pages
            update_post_meta($post_id, self::META_HTML_ENABLED, '1');
        }
        
        // Only process HTML settings if this is a marked custom HTML page
        if (get_post_meta($post_id, self::META_IS_CUSTOM_HTML_PAGE, true) !== '1') {
            return;
        }

        // Verify nonce
        if (!isset($_POST['metasync_custom_html_nonce']) || 
            !wp_verify_nonce($_POST['metasync_custom_html_nonce'], 'metasync_custom_html_nonce')) {
            return;
        }

        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save HTML enabled status
        $html_enabled = isset($_POST['metasync_html_enabled']) ? '1' : '0';
        update_post_meta($post_id, self::META_HTML_ENABLED, $html_enabled);

        // Save HTML content
        if (isset($_POST['metasync_html_content'])) {
            // Don't sanitize HTML content - we trust admin users
            $html_content = wp_unslash($_POST['metasync_html_content']);
            update_post_meta($post_id, self::META_HTML_CONTENT, $html_content);
        }

        // Handle file upload
        if (isset($_FILES['metasync_html_file']) && $_FILES['metasync_html_file']['size'] > 0) {
            $file = $_FILES['metasync_html_file'];
            
            // Validate file type
            $allowed_extensions = array('html', 'htm');
            $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (in_array($file_extension, $allowed_extensions)) {
                $html_content = file_get_contents($file['tmp_name']);
                
                if ($html_content !== false && !empty($html_content)) {
                    update_post_meta($post_id, self::META_HTML_CONTENT, $html_content);
                    update_post_meta($post_id, self::META_HTML_FILENAME, sanitize_file_name($file['name']));
                    
                    // Set admin notice
                    add_action('admin_notices', function() use ($file) {
                        echo '<div class="notice notice-success is-dismissible">';
                        echo '<p><strong>Success!</strong> HTML file "' . esc_html($file['name']) . '" uploaded and saved.</p>';
                        echo '</div>';
                    });
                } else {
                    // File was empty or couldn't be read
                    add_action('admin_notices', function() use ($file) {
                        echo '<div class="notice notice-error is-dismissible">';
                        echo '<p><strong>Error:</strong> Could not read HTML file "' . esc_html($file['name']) . '". Please try again.</p>';
                        echo '</div>';
                    });
                }
            } else {
                // Invalid file type
                add_action('admin_notices', function() use ($file) {
                    echo '<div class="notice notice-error is-dismissible">';
                    echo '<p><strong>Error:</strong> Invalid file type. Only .html and .htm files are allowed.</p>';
                    echo '</div>';
                });
            }
        }
    }

    /**
     * Serve raw HTML content instead of WordPress template
     * 
     * This function intercepts the WordPress template loading process and serves
     * raw HTML content directly. It's fully compatible with OTTO, which fetches
     * the raw HTML, enhances it with SEO metadata, and serves the optimized version.
     * 
     * @since 1.0.0
     */
    public function serve_raw_html()
    {
        // Only process single page requests
        if (!is_page()) {
            return;
        }

        global $post;
        
        // Verify post object exists
        if (!$post || !isset($post->ID)) {
            return;
        }
        
        // Check if raw HTML mode is enabled for this page
        $html_enabled = get_post_meta($post->ID, self::META_HTML_ENABLED, true);
        
        if ($html_enabled !== '1') {
            return; // Use normal WordPress template
        }
        
        /**
         * OTTO COMPATIBILITY:
         * 
         * When OTTO is enabled and processing a page, it:
         * 1. Makes an internal wp_remote_get() request with ?is_otto_page_fetch=1
         * 2. Receives the raw HTML from this function
         * 3. Processes it (adds meta tags, schema, AI enhancements)
         * 4. Serves the enhanced HTML to the end-user
         * 
         * This ensures both custom HTML design AND OTTO's SEO features work together.
         */
        $is_otto_fetch = isset($_GET['is_otto_page_fetch']) && $_GET['is_otto_page_fetch'] === '1';

        // Permission check for draft/pending pages
        if ($post->post_status !== 'publish') {
            // Allow preview for users with edit permissions
            if (!current_user_can('edit_post', $post->ID)) {
                wp_die(
                    esc_html__('You do not have permission to view this page.', 'metasync'),
                    403
                );
            }
        }

        // Retrieve HTML content
        $html_content = get_post_meta($post->ID, self::META_HTML_CONTENT, true);

        // Handle empty content gracefully
        if (empty($html_content)) {
            $message = current_user_can('edit_post', $post->ID)
                ? 'No HTML content available for this page. Please edit the page and add HTML content.'
                : 'This page is not yet available.';
                
            wp_die(
                esc_html($message),
                404
            );
        }

        // Set proper headers for HTML content
        header('Content-Type: text/html; charset=utf-8');
        
        // Cache control headers (prevent caching of preview/draft pages)
        if ($post->post_status !== 'publish' || $is_otto_fetch) {
            header('Cache-Control: no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        } else {
            // Allow caching for published pages (CDN friendly)
            header('Cache-Control: public, max-age=3600');
        }
        
        // Output raw HTML and stop WordPress processing
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw HTML is intentional
        echo $html_content;
        exit;
    }

    /**
     * Handle AJAX HTML file upload
     * 
     * Validates and processes HTML file uploads via AJAX
     * 
     * @since 1.0.0
     */
    public function handle_html_upload()
    {
        // Verify nonce for security
        check_ajax_referer('metasync_upload_html', 'nonce');

        // Check user permissions
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array(
                'message' => esc_html__('Insufficient permissions to upload files.', 'metasync')
            ));
        }

        // Verify file was uploaded
        if (!isset($_FILES['html_file']) || empty($_FILES['html_file']['tmp_name'])) {
            wp_send_json_error(array(
                'message' => esc_html__('No file uploaded. Please select an HTML file.', 'metasync')
            ));
        }

        $file = $_FILES['html_file'];
        
        // Validate file type
        $allowed_extensions = array('html', 'htm');
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_ext, $allowed_extensions, true)) {
            wp_send_json_error(array(
                'message' => sprintf(
                    esc_html__('Invalid file type. Only %s files are allowed.', 'metasync'),
                    implode(', ', $allowed_extensions)
                )
            ));
        }
        
        // Check file size (max 10MB)
        $max_size = 10 * 1024 * 1024; // 10MB in bytes
        if ($file['size'] > $max_size) {
            wp_send_json_error(array(
                'message' => esc_html__('File is too large. Maximum size is 10MB.', 'metasync')
            ));
        }

        // Validate upload error
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(array(
                'message' => esc_html__('File upload failed. Please try again.', 'metasync')
            ));
        }

        // Read file contents
        $html_content = @file_get_contents($file['tmp_name']);
        
        if ($html_content === false || empty($html_content)) {
            wp_send_json_error(array(
                'message' => esc_html__('Could not read file contents. Please verify the file is valid.', 'metasync')
            ));
        }

        // Clean up temp file
        @unlink($file['tmp_name']);

        // Return success with content
        wp_send_json_success(array(
            'content' => $html_content,
            'filename' => sanitize_file_name($file['name']),
            'size' => size_format($file['size'])
        ));
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook)
    {
        global $post_type, $post;
        
        if ('page' !== $post_type) {
            return;
        }
        
        // Only enqueue on custom HTML pages
        if ($post) {
            $is_custom_html_page = get_post_meta($post->ID, self::META_IS_CUSTOM_HTML_PAGE, true) === '1';
            $transient_key = 'metasync_mark_as_html_page_' . get_current_user_id();
            $is_new_custom_page = get_transient($transient_key) ? true : false;
            
            if (!$is_custom_html_page && !$is_new_custom_page) {
                return; // Not a custom HTML page, don't load assets
            }
        }

        // Enqueue CodeMirror for syntax highlighting
        $cm_settings = wp_enqueue_code_editor(array('type' => 'text/html'));
        
        // Check if CodeMirror loaded successfully
        if ($cm_settings === false) {
            error_log('MetaSync Custom Pages: CodeMirror failed to load');
            return;
        }
        
        // Enqueue custom scripts for enhanced editor
        wp_enqueue_script(
            'metasync-custom-pages-editor',
            plugin_dir_url(__FILE__) . 'js/custom-pages-editor.js',
            array('jquery', 'code-editor'),
            '1.0.2', // Fixed: CodeMirror now syncs content to textarea before save
            true
        );
        
        // Pass CodeMirror settings to our script
        wp_localize_script(
            'metasync-custom-pages-editor',
            'metasyncCustomPagesSettings',
            array(
                'codeEditorSettings' => $cm_settings,
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('metasync_upload_html')
            )
        );
        
        // Enqueue custom styles
        wp_enqueue_style(
            'metasync-custom-pages-editor',
            plugin_dir_url(__FILE__) . 'css/custom-pages-editor.css',
            array(),
            '1.0.0'
        );
    }

    /**
     * Clear page cache when saving
     */
    public function clear_page_cache($post_id, $post)
    {
        if ('page' !== $post->post_type) {
            return;
        }

        // Clear WordPress object cache
        wp_cache_delete($post_id, 'posts');
        wp_cache_delete($post_id, 'post_meta');

        // Try to clear popular caching plugins
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }

        if (function_exists('w3tc_flush_post')) {
            w3tc_flush_post($post_id);
        }

        if (function_exists('wp_super_cache_post_delete')) {
            wp_super_cache_post_delete($post_id);
        }

        // Use MetaSync cache purge if available
        if (class_exists('Metasync_Cache_Purge')) {
            try {
                $cache_purge = Metasync_Cache_Purge::get_instance();
                $cache_purge->clear_all_caches('custom_page_update');
            } catch (Exception $e) {
                error_log('MetaSync Custom Pages: Cache purge failed - ' . $e->getMessage());
            }
        }
    }

    /**
     * Get all custom HTML pages (pages marked as custom HTML pages)
     */
    public static function get_custom_pages($args = array())
    {
        $defaults = array(
            'post_type'      => 'page',
            'post_status'    => array('publish', 'draft', 'pending'),
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_query'     => array(
                array(
                    'key'     => self::META_IS_CUSTOM_HTML_PAGE,
                    'value'   => '1',
                    'compare' => '='
                )
            )
        );

        $args = wp_parse_args($args, $defaults);
        return get_posts($args);
    }
    
    /**
     * Add custom column to pages list
     */
    public function add_custom_html_column($columns)
    {
        $new_columns = array();
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            // Add our column after the title
            if ($key === 'title') {
                $new_columns['metasync_html'] = '🌐 HTML Mode';
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Render custom column content
     */
    public function render_custom_html_column($column, $post_id)
    {
        if ($column === 'metasync_html') {
            $is_custom_html_page = get_post_meta($post_id, self::META_IS_CUSTOM_HTML_PAGE, true);
            $html_enabled = get_post_meta($post_id, self::META_HTML_ENABLED, true);
            
            if ($is_custom_html_page === '1' && $html_enabled === '1') {
                echo '<span style="display:inline-block;padding:3px 8px;background:#2271b1;color:#fff;border-radius:3px;font-size:11px;font-weight:600;">RAW HTML</span>';
            } elseif ($is_custom_html_page === '1') {
                echo '<span style="display:inline-block;padding:3px 8px;background:#999;color:#fff;border-radius:3px;font-size:11px;font-weight:600;">HTML PAGE</span>';
            } else {
                echo '<span style="color:#999;">—</span>';
            }
        }
    }

    /**
     * Get custom page URL
     */
    public static function get_page_url($post_id)
    {
        return get_permalink($post_id);
    }
}


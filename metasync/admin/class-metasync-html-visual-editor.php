<?php
/**
 * MetaSync HTML Visual Editor
 *
 * Provides a visual editing interface for raw HTML pages with:
 * - Click to edit text
 * - Color picker for backgrounds/colors
 * - Image uploader
 * - Drag and drop reordering
 * - Live preview
 *
 * @package    Metasync
 * @subpackage Metasync/admin
 * @since      2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_HTML_Visual_Editor
{
    /**
     * Plugin name
     *
     * @var string
     */
    private $plugin_name;

    /**
     * Plugin version
     *
     * @var string
     */
    private $version;

    /**
     * Initialize the class
     *
     * @param string $plugin_name Plugin name
     * @param string $version Plugin version
     */
    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    /**
     * Register hooks
     */
    public function init()
    {
        // Add "Edit HTML" button to post row actions
        add_filter('post_row_actions', array($this, 'add_edit_html_button'), 10, 2);
        add_filter('page_row_actions', array($this, 'add_edit_html_button'), 10, 2);

        // Add admin menu page for the editor
        add_action('admin_menu', array($this, 'add_editor_page'));

        // Register AJAX handlers
        add_action('wp_ajax_metasync_save_html', array($this, 'ajax_save_html'));
        add_action('wp_ajax_metasync_upload_image', array($this, 'ajax_upload_image'));
    }

    /**
     * Add "Edit HTML" button to row actions
     *
     * @param array $actions Row actions
     * @param WP_Post $post Post object
     * @return array Modified actions
     */
    public function add_edit_html_button($actions, $post)
    {
        // Check if this is a raw HTML page
        $has_raw_html = get_post_meta($post->ID, '_metasync_raw_html_enabled', true);

        if ($has_raw_html) {
            $edit_url = admin_url('admin.php?page=metasync-html-editor&post_id=' . $post->ID);
            $label = Metasync::get_whitelabel_company_name() ?: 'SearchAtlas';

            $actions['edit_html'] = sprintf(
                '<a href="%s" title="%s">%s</a>',
                esc_url($edit_url),
                esc_attr(sprintf(__('Edit with %s Visual Editor', 'metasync'), $label)),
                __('Edit HTML', 'metasync')
            );
        }

        return $actions;
    }

    /**
     * Add editor admin page
     */
    public function add_editor_page()
    {
        $label = Metasync::get_whitelabel_company_name() ?: 'SearchAtlas';
        $page_title = sprintf(__('%s HTML Editor', 'metasync'), $label);

        add_submenu_page(
            null, // Hidden from menu
            $page_title,
            $page_title,
            'edit_pages',
            'metasync-html-editor',
            array($this, 'render_editor_page')
        );
    }

    /**
     * Render the visual editor page
     */
    public function render_editor_page()
    {
        // Check permissions
        if (!current_user_can('edit_pages')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Get post ID
        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

        if (!$post_id) {
            wp_die(__('Invalid page ID.'));
        }

        // Get post
        $post = get_post($post_id);

        if (!$post) {
            wp_die(__('Page not found.'));
        }

        // Check if raw HTML is enabled
        $has_raw_html = get_post_meta($post_id, '_metasync_raw_html_enabled', true);

        if (!$has_raw_html) {
            wp_die(__('This page is not a raw HTML page.'));
        }

        // Get HTML content
        $html_content = get_post_meta($post_id, '_metasync_raw_html_content', true);

        if (empty($html_content)) {
            $html_content = '<html><body><h1>Start editing...</h1></body></html>';
        }

        // Get label for branding
        $label = Metasync::get_whitelabel_company_name() ?: 'SearchAtlas AI';

        // Enqueue editor assets
        $this->enqueue_editor_assets();

        // Render editor UI
        include plugin_dir_path(__FILE__) . 'partials/metasync-html-editor-page.php';
    }

    /**
     * Enqueue editor assets (GrapesJS + custom scripts)
     */
    private function enqueue_editor_assets()
    {
        // Font Awesome for icons
        wp_enqueue_style(
            'font-awesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
            array(),
            '6.4.0'
        );

        // GrapesJS core
        wp_enqueue_style(
            'grapesjs',
            'https://unpkg.com/grapesjs@0.21.7/dist/css/grapes.min.css',
            array(),
            '0.21.7'
        );

        wp_enqueue_script(
            'grapesjs',
            'https://unpkg.com/grapesjs@0.21.7/dist/grapes.min.js',
            array(),
            '0.21.7',
            true
        );

        // GrapesJS Plugins
        wp_enqueue_script(
            'grapesjs-blocks-basic',
            'https://unpkg.com/grapesjs-blocks-basic',
            array('grapesjs'),
            null,
            true
        );

        // Custom editor JS (with timestamp for cache busting)
        wp_enqueue_script(
            'metasync-html-editor',
            plugins_url('js/metasync-html-editor.js', __FILE__),
            array('jquery', 'grapesjs'),
            $this->version . '.' . time(),
            true
        );

        // Custom editor CSS (with timestamp for cache busting)
        wp_enqueue_style(
            'metasync-html-editor',
            plugins_url('css/metasync-html-editor.css', __FILE__),
            array('grapesjs'),
            $this->version . '.' . time()
        );

        // Localize script with data
        wp_localize_script('metasync-html-editor', 'metasyncEditor', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('metasync_html_editor'),
            'post_id' => isset($_GET['post_id']) ? intval($_GET['post_id']) : 0,
            'preview_url' => get_permalink(isset($_GET['post_id']) ? intval($_GET['post_id']) : 0),
            'back_url' => admin_url('edit.php?post_type=page'),
            'i18n' => array(
                'saving' => __('Saving...', 'metasync'),
                'saved' => __('Saved!', 'metasync'),
                'error' => __('Error saving', 'metasync'),
                'confirm_exit' => __('You have unsaved changes. Are you sure you want to leave?', 'metasync'),
            )
        ));
    }

    /**
     * AJAX handler for saving HTML
     */
    public function ajax_save_html()
    {
        // Check nonce
        check_ajax_referer('metasync_html_editor', 'nonce');

        // Check permissions
        if (!current_user_can('edit_pages')) {
            wp_send_json_error(array('message' => __('Permission denied', 'metasync')));
        }

        // Get data
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $html_content = isset($_POST['html']) ? wp_kses_post($_POST['html']) : '';

        if (!$post_id || empty($html_content)) {
            wp_send_json_error(array('message' => __('Invalid data', 'metasync')));
        }

        // Save HTML content
        update_post_meta($post_id, '_metasync_raw_html_content', $html_content);

        // Update modified date
        wp_update_post(array(
            'ID' => $post_id,
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1)
        ));

        wp_send_json_success(array(
            'message' => __('Page saved successfully', 'metasync'),
            'preview_url' => get_permalink($post_id)
        ));
    }

    /**
     * AJAX handler for uploading images
     */
    public function ajax_upload_image()
    {
        // Check nonce
        check_ajax_referer('metasync_html_editor', 'nonce');

        // Check permissions
        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => __('Permission denied', 'metasync')));
        }

        // Handle file upload
        if (!isset($_FILES['file'])) {
            wp_send_json_error(array('message' => __('No file uploaded', 'metasync')));
        }

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $attachment_id = media_handle_upload('file', 0);

        if (is_wp_error($attachment_id)) {
            wp_send_json_error(array('message' => $attachment_id->get_error_message()));
        }

        $image_url = wp_get_attachment_url($attachment_id);

        wp_send_json_success(array(
            'url' => $image_url,
            'id' => $attachment_id
        ));
    }
}

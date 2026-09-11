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
     * Pinned version of the bundled GrapesJS core.
     *
     * @var string
     */
    const GRAPESJS_VERSION = '0.21.7';

    /**
     * Pinned version of the bundled grapesjs-blocks-basic plugin.
     *
     * Releases before 1.0.0 registered themselves by calling
     * grapesjs.plugins.add('gjs-blocks-basic'); 1.0.x only exposes a UMD
     * export, so the editor passes the plugin by reference rather than by
     * that legacy global name.
     *
     * @var string
     */
    const GRAPESJS_BLOCKS_BASIC_VERSION = '1.0.2';

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

        // Enqueue editor assets during the normal asset phase so stylesheets
        // land in <head> rather than being flushed late from the page body.
        add_action('admin_enqueue_scripts', array($this, 'maybe_enqueue_editor_assets'));

        // Register AJAX handlers
        add_action('wp_ajax_metasync_save_html', array($this, 'ajax_save_html'));
        add_action('wp_ajax_metasync_upload_image', array($this, 'ajax_upload_image'));
    }

    /**
     * Admin page slug for the visual editor.
     *
     * @return string
     */
    private function get_editor_page_slug()
    {
        return Metasync_Admin::$page_slug . '-html-editor';
    }

    /**
     * Enqueue the editor assets when the current request is the editor page.
     *
     * Keyed on the request rather than on the hook suffix because the editor is
     * registered as a hidden submenu page, so its generated suffix is not
     * stable to match against.
     */
    public function maybe_enqueue_editor_assets()
    {
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';

        if ($page !== $this->get_editor_page_slug()) {
            return;
        }

        if (!current_user_can('edit_pages')) {
            return;
        }

        $this->enqueue_editor_assets();
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
            $edit_url = admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '-html-editor&post_id=' . $post->ID);
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
            '', // Hidden from menu
            $page_title,
            $page_title,
            'edit_pages',
            Metasync_Admin::$page_slug . '-html-editor',
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
            wp_die(__('You do not have sufficient permissions to access this page.', 'metasync'));
        }

        // Get post ID
        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

        if (!$post_id) {
            wp_die(__('Invalid page ID.', 'metasync'));
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'metasync'));
        }

        // Get post
        $post = get_post($post_id);

        if (!$post) {
            wp_die(__('Page not found.', 'metasync'));
        }

        // Check if raw HTML is enabled
        $has_raw_html = get_post_meta($post_id, '_metasync_raw_html_enabled', true);

        if (!$has_raw_html) {
            wp_die(__('This page is not a raw HTML page.', 'metasync'));
        }

        // Get HTML content
        $html_content = get_post_meta($post_id, '_metasync_raw_html_content', true);

        if (empty($html_content)) {
            $html_content = '<html><body><h1>Start editing...</h1></body></html>';
        }

        // Get label for branding
        $label = Metasync::get_whitelabel_company_name() ?: 'SearchAtlas AI';

        // Render editor UI. Assets are enqueued on admin_enqueue_scripts.
        include plugin_dir_path(__FILE__) . 'partials/metasync-html-editor-page.php';
    }

    /**
     * Enqueue editor assets (GrapesJS + custom scripts)
     *
     * The editor runtime is served from the plugin's own bundled copies at
     * pinned versions. It used to be pulled from public CDNs, which made any
     * blocked, throttled or offline request render the editor as an empty
     * canvas with no explanation.
     */
    private function enqueue_editor_assets()
    {
        $lib_url  = plugin_dir_url(__FILE__) . 'lib/';
        $lib_path = plugin_dir_path(__FILE__) . 'lib/';

        // Bundled libraries, in load order. Anything missing from disk is
        // reported to the client so the editor can explain itself instead of
        // rendering blank. Handles are namespaced so a theme or plugin
        // registering a bare "grapesjs" handle cannot collide with ours.
        $libraries = array(
            'metasync-grapesjs' => array(
                'version' => self::GRAPESJS_VERSION,
                'style'   => 'grapesjs/grapes.min.css',
                'script'  => 'grapesjs/grapes.min.js',
            ),
            'metasync-grapesjs-blocks-basic' => array(
                'version' => self::GRAPESJS_BLOCKS_BASIC_VERSION,
                'script'  => 'grapesjs-blocks-basic/grapesjs-blocks-basic.min.js',
                'deps'    => array('metasync-grapesjs'),
            ),
        );

        $missing = array();

        foreach ($libraries as $handle => $library) {
            if (file_exists($lib_path . $library['script'])) {
                wp_enqueue_script(
                    $handle,
                    $lib_url . $library['script'],
                    isset($library['deps']) ? $library['deps'] : array(),
                    $library['version'],
                    true
                );
            } else {
                $missing[] = $handle;
            }

            if (!isset($library['style'])) {
                continue;
            }

            if (file_exists($lib_path . $library['style'])) {
                wp_enqueue_style(
                    $handle,
                    $lib_url . $library['style'],
                    array(),
                    $library['version']
                );
            } else {
                $missing[] = $handle;
            }
        }

        $missing = array_values(array_unique($missing));

        // The editor bundle depends on the bundled core only when that core
        // was actually enqueued. When a library file is absent from disk it is
        // never registered, and a dependency on an unregistered handle makes
        // WordPress suppress the editor bundle itself — including the localized
        // `missing` list, so the failure would go back to being a blank canvas
        // with no explanation.
        $editor_script_deps = array('jquery');
        // The editor chrome and the sidebar's panel switcher label their
        // buttons with dashicons glyphs, so the stylesheet is a real
        // dependency rather than something to inherit from the admin page.
        $editor_style_deps = array('dashicons');
        if (wp_script_is('metasync-grapesjs', 'registered')) {
            $editor_script_deps[] = 'metasync-grapesjs';
            $editor_style_deps[] = 'metasync-grapesjs';
        }

        wp_enqueue_script(
            'metasync-html-editor',
            plugins_url('js/metasync-html-editor.js', __FILE__),
            $editor_script_deps,
            $this->version,
            true
        );

        wp_enqueue_style(
            'metasync-html-editor',
            plugins_url('css/metasync-html-editor.css', __FILE__),
            $editor_style_deps,
            $this->version
        );

        // Localize script with data
        wp_localize_script('metasync-html-editor', 'metasyncEditor', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('metasync_html_editor'),
            'post_id' => isset($_GET['post_id']) ? intval($_GET['post_id']) : 0,
            'preview_url' => get_permalink(isset($_GET['post_id']) ? intval($_GET['post_id']) : 0),
            // Names of bundled libraries that are absent from disk, so the
            // client can name the failing dependency without exposing paths
            // or other sensitive detail.
            'missing' => $missing,
            'i18n' => array(
                'saving' => __('Saving...', 'metasync'),
                'saved' => __('Saved!', 'metasync'),
                'error' => __('Error saving', 'metasync'),
                'session_expired' => __('Your session has expired. Copy your work before reloading the page.', 'metasync'),
                'ready' => __('Ready', 'metasync'),
                'unsaved_changes' => __('Unsaved changes', 'metasync'),
                'confirm_exit' => __('You have unsaved changes. Are you sure you want to leave?', 'metasync'),
                'confirm_preview' => __('You have unsaved changes. Preview will show the last saved version. Continue?', 'metasync'),
                'panel_styles' => __('Styles', 'metasync'),
                'panel_settings' => __('Settings', 'metasync'),
                'panel_layers' => __('Layers', 'metasync'),
                'panel_blocks' => __('Blocks', 'metasync'),
                'load_failed_title' => __('The visual editor could not start', 'metasync'),
                'load_failed_core' => __('The visual editor library could not be loaded, so this page cannot be edited visually. Reload the page, and if the problem continues check whether a browser extension, proxy or content security policy is blocking plugin scripts.', 'metasync'),
                'load_failed_blocks' => __('The editor loaded, but its extra block library is unavailable, so the Blocks panel only offers the built-in blocks. Existing page content can still be edited and saved normally.', 'metasync'),
                'load_failed_init' => __('The visual editor failed to start while loading this page. Reload to try again; the saved page content has not been changed.', 'metasync'),
                'load_failed_detail' => __('Missing component: %s', 'metasync'),
                'reload' => __('Reload page', 'metasync'),
                'dismiss' => __('Dismiss', 'metasync'),
                'save_disabled' => __('Saving is disabled because the editor did not load', 'metasync'),
                'upload_failed' => __('Image upload failed', 'metasync'),
            )
        ));
    }

    /**
     * AJAX handler for saving HTML
     *
     * The payload is stored verbatim, matching the contract of every other
     * writer of this meta key: the Custom Pages metabox stores the raw value
     * for users who can edit the page, and the front-end renderer echoes it
     * as authored. Filtering here with kses would silently strip the very
     * elements raw HTML pages exist to carry (doctype, head assets, forms,
     * iframes, inline SVG), and re-filtering an already-filtered value is
     * what let entity-encoded markup re-materialize as live tags.
     */
    public function ajax_save_html()
    {
        // Check nonce
        check_ajax_referer('metasync_html_editor', 'nonce');

        // Check permissions
        if (!current_user_can('edit_pages')) {
            wp_send_json_error(array('message' => __('Permission denied', 'metasync')), 403);
        }

        // Get data
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        if (!$post_id) {
            wp_send_json_error(array('message' => __('No page selected', 'metasync')));
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array('message' => __('Permission denied', 'metasync')), 403);
        }

        $html_content = isset($_POST['html']) ? wp_unslash($_POST['html']) : '';

        if (empty($html_content)) {
            wp_send_json_error(array('message' => __('Nothing to save', 'metasync')));
        }

        // Keep the value being replaced so a save that mangles the page can
        // be undone; postmeta is not revisioned, so this is the only undo.
        $previous = get_post_meta($post_id, '_metasync_raw_html_content', true);
        if ('' !== $previous) {
            update_post_meta($post_id, '_metasync_raw_html_content_previous', $previous);
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
     *
     * File validation is delegated entirely to the WordPress media pipeline
     * (wp_handle_upload + wp_check_filetype_and_ext via media_handle_upload),
     * the same chain the core media uploader uses; the capability gate
     * matches core's async-upload endpoint (upload_files).
     */
    public function ajax_upload_image()
    {
        // Check nonce
        check_ajax_referer('metasync_html_editor', 'nonce');

        // Check permissions
        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => __('Permission denied', 'metasync')), 403);
        }

        // Tie the upload to the page being edited, when the editor names one.
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if ($post_id && !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array('message' => __('Permission denied', 'metasync')), 403);
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

        // The editor's asset manager adds response.data to the asset list
        // directly, and an asset's source attribute is called `src`. The
        // attachment is exposed as `attachment_id` rather than `id` — `id` is
        // the Backbone collection's identity key, so reusing it would make
        // repeated uploads of the same attachment silently dedupe.
        wp_send_json_success(array(
            'src' => $image_url,
            'attachment_id' => $attachment_id
        ));
    }
}

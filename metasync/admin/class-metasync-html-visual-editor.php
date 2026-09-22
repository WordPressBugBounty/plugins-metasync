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
     * Meta key marking a page as a Landing Page Studio (LPS) ZIP import.
     *
     * Mirrors Metasync_Custom_Pages::META_LPS_IMPORT. The literal is used here
     * for the same reason includes/class-metasync-seo-suite.php does: this
     * class is loaded by the admin bootstrap, which does not guarantee the
     * Custom Pages class is present, and a guard that reads the marker must
     * never be skipped just because a class failed to load.
     *
     * @var string
     */
    const META_LPS_IMPORT = '_metasync_lps_import';

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
     * Whether a page is a Landing Page Studio import.
     *
     * LPS pages are owned by the importer: a re-import replaces their stored
     * HTML wholesale, and they are always complete documents. Visual editing
     * them would both destroy the document and put the page out of sync with
     * the project it was imported from, so they are excluded from this editor
     * entirely rather than merely blocked at save.
     *
     * @param int $post_id Page being inspected.
     * @return bool
     */
    public static function is_lps_page($post_id)
    {
        return get_post_meta($post_id, self::META_LPS_IMPORT, true) === '1';
    }

    /**
     * Whether stored HTML carries content the visual canvas cannot round-trip.
     *
     * GrapesJS builds body fragments. Handing it anything document-level makes
     * it parse what it can and discard the rest, and the editor then saves that
     * reduced result over the original — so the loss happens when the page is
     * parsed into the canvas, before the user edits anything.
     *
     * Two classes of content are unsafe:
     *
     * - Document structure (doctype, <html>, <head>): dropped outright,
     *   because a fragment builder has nowhere to put it.
     * - Scripts: dropped even inside an otherwise ordinary body fragment, so a
     *   check limited to document structure would still let a scripted page
     *   lose its behaviour silently.
     *
     * <body> is the exception that has to be judged on its attributes. The
     * canvas emits a bare <body> wrapper around everything it serializes, so
     * treating the element itself as unsafe would make every page this editor
     * saves lock itself out of the editor on the next visit. What the canvas
     * does destroy is the element's attributes — a class, inline style, a data
     * attribute a script or stylesheet depends on — so an opening <body> tag
     * is unsafe when it carries any.
     *
     * With one exception of its own: styling the body in the Styles panel
     * makes GrapesJS mint an id for it (`<body id="ir4h">`) purely so its
     * generated stylesheet has something to target. That id is the editor's
     * own bookkeeping, not authored content, and refusing it would lock a page
     * out of the editor the first time anyone edited a style — the very thing
     * the bare-wrapper allowance exists to prevent.
     *
     * Detection is deliberately a scan for opening tags rather than a parse:
     * the question is only whether unsupported constructs are present, and a
     * malformed document must answer yes as readily as a well-formed one.
     *
     * @param mixed $html Stored page HTML. Typed loosely because post meta can
     *                    come back as false when absent, or as an array from a
     *                    corrupted row, and neither is an unsafe document.
     * @return bool True when the document must not be edited visually.
     */
    public static function is_unsafe_for_visual_editor($html)
    {
        if (!is_string($html) || '' === trim($html)) {
            return false;
        }

        // Opening tags only: a stray "</head>" without its opening tag is not
        // evidence of a document, and matching bare words would misfire on
        // ordinary prose ("the html spec").
        $patterns = array(
            '/<!doctype\b/i',
            '/<html[\s>]/i',
            '/<head[\s>]/i',
            '/<script[\s>]/i',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html)) {
                return true;
            }
        }

        if (!preg_match('/<body(\s[^>]*)?>/i', $html, $match)) {
            return false;
        }

        $attributes = isset($match[1]) ? trim($match[1]) : '';

        // A bare <body> is the canvas's own wrapper and round-trips safely.
        if ('' === $attributes) {
            return false;
        }

        // So is one carrying nothing but the id GrapesJS generates for itself.
        // Its ids are an "i" prefix followed by a short base-36 token — `ir4h`,
        // `igcb`, `ip75` — a shape sampled from the bundled runtime rather
        // than assumed, because a mismatch here silently locks pages out of
        // the editor. The digits are incidental: better than a third of
        // generated ids contain none, so only the prefix, charset and length
        // are relied on. An author writing a hook worth preserving writes a
        // word — `top`, `main-content` — which this does not match.
        return !preg_match('/^id=(["\'])i[a-z0-9]{2,7}\1$/', $attributes);
    }

    /**
     * Whether a page may be edited through the visual canvas.
     *
     * @param int $post_id Page being inspected.
     * @return bool
     */
    public static function can_edit_visually($post_id)
    {
        if (self::is_lps_page($post_id)) {
            return false;
        }

        $html = get_post_meta($post_id, '_metasync_raw_html_content', true);

        return !self::is_unsafe_for_visual_editor($html);
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

        if (!$has_raw_html) {
            return $actions;
        }

        // LPS pages are never editable here; the importer owns their content.
        // Non-LPS pages keep the action even when their HTML is a complete
        // document: the editor page explains why it cannot save and points at
        // the lossless editor, which is more useful than a button that simply
        // vanishes with no explanation.
        if (self::is_lps_page($post->ID)) {
            return $actions;
        }

        $edit_url = admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '-html-editor&post_id=' . $post->ID);
        $label = Metasync::get_whitelabel_company_name() ?: 'SearchAtlas';

        $actions['edit_html'] = sprintf(
            '<a href="%s" title="%s">%s</a>',
            esc_url($edit_url),
            esc_attr(sprintf(__('Edit with %s Visual Editor', 'metasync'), $label)),
            __('Edit HTML', 'metasync')
        );

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

        // LPS pages are owned by the importer and are always complete
        // documents, so the canvas is refused outright rather than opened in a
        // state where nothing can be saved.
        if (self::is_lps_page($post_id)) {
            wp_die(
                esc_html__('This page was imported from Website Studio and cannot be edited with the visual editor. Edit it from the page editor, or re-publish it from Website Studio.', 'metasync')
            );
        }

        // Get HTML content
        $html_content = get_post_meta($post_id, '_metasync_raw_html_content', true);

        if (empty($html_content)) {
            // A body fragment, not a document: the canvas builds fragments, so
            // seeding a page with <html>/<body> would make a brand-new empty
            // page register as unsafe and lock the editor against itself.
            $html_content = '<h1>Start editing...</h1>';
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

        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

        // Localize script with data
        wp_localize_script('metasync-html-editor', 'metasyncEditor', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('metasync_html_editor'),
            'post_id' => $post_id,
            'preview_url' => get_permalink($post_id),
            // Names of bundled libraries that are absent from disk, so the
            // client can name the failing dependency without exposing paths
            // or other sensitive detail.
            'missing' => $missing,
            // Non-empty when the page's stored HTML cannot survive the canvas,
            // so the client can disable saving before the document is parsed
            // into it rather than after the user has edited.
            'blocked_reason' => $post_id ? $this->get_blocked_reason($post_id) : '',
            // Where the user can edit this page without loss.
            'direct_edit_url' => $post_id ? get_edit_post_link($post_id, 'raw') : '',
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
                'blocked_title' => __('This page cannot be saved from the visual editor', 'metasync'),
                'blocked_full_document' => __('This page is a complete HTML document. The visual editor rebuilds pages from their body content, so saving here would discard the doctype, head and scripts. Use Edit HTML Directly on the page editor to change it without losing anything.', 'metasync'),
                'blocked_lps' => __('This page was imported from Website Studio, which owns its content. Re-publish it from Website Studio, or use Edit HTML Directly on the page editor.', 'metasync'),
                'blocked_save_disabled' => __('Saving is disabled to protect this page from being rewritten', 'metasync'),
                'open_direct_editor' => __('Edit HTML Directly', 'metasync'),
                'upload_failed' => __('Image upload failed', 'metasync'),
            )
        ));
    }

    /**
     * Why a page cannot be saved through the visual canvas, if it cannot.
     *
     * Returned to the client so the canvas can refuse before it parses the
     * document, rather than letting the user edit a reduced copy and discover
     * at save time that the original is unrecoverable.
     *
     * @param int $post_id Page being opened.
     * @return string Machine-readable reason, or '' when the page is editable.
     */
    private function get_blocked_reason($post_id)
    {
        if (self::is_lps_page($post_id)) {
            return 'lps';
        }

        $html = get_post_meta($post_id, '_metasync_raw_html_content', true);

        // An empty page is seeded with a body fragment, so there is nothing
        // to lose and the canvas may open normally.
        if ('' === (string) $html) {
            return '';
        }

        return self::is_unsafe_for_visual_editor($html) ? 'full_document' : '';
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
     *
     * Storing verbatim is also why the guards below refuse whole saves rather
     * than trying to repair a payload: by the time it arrives, the canvas has
     * already discarded whatever it could not represent, and nothing here can
     * tell a deliberate deletion from a parser casualty.
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

        // The client disables saving for these pages before the canvas parses
        // them, but the endpoint is reachable on its own, and the decision is
        // about what the stored document can survive rather than about what
        // the browser did. Both checks read the value already in the database,
        // not the payload: what matters is whether the page being overwritten
        // is one the canvas could have represented faithfully.
        if (self::is_lps_page($post_id)) {
            wp_send_json_error(
                array('message' => __('This page was imported from Website Studio and cannot be saved from the visual editor.', 'metasync')),
                409
            );
        }

        $stored = get_post_meta($post_id, '_metasync_raw_html_content', true);

        if (self::is_unsafe_for_visual_editor($stored)) {
            wp_send_json_error(
                array('message' => __('This page is a complete HTML document, so saving it from the visual editor would discard its doctype, head and scripts. Use Edit HTML Directly on the page editor instead.', 'metasync')),
                409
            );
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

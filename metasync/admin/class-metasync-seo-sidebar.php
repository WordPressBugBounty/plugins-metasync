<?php
/**
 * MetaSync SEO Sidebar for Gutenberg Block Editor
 *
 * Provides a sidebar panel in the WordPress Block Editor for editing
 * SEO metadata (title and description) directly within the post editor.
 *
 * @package    Metasync
 * @subpackage Metasync/admin
 * @since      2.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_SEO_Sidebar {

    /**
     * Plugin version
     *
     * @var string
     */
    private $version;

    /**
     * Meta key for SEO title (manual edits via sidebar)
     */
    const META_SEO_TITLE = '_metasync_seo_title';

    /**
     * Meta key for meta description (manual edits via sidebar)
     */
    const META_DESCRIPTION = '_metasync_seo_desc';

    /**
     * Meta key for primary category (used in breadcrumbs and canonical URL)
     */
    const META_PRIMARY_CATEGORY = '_metasync_primary_category';

    /**
     * Meta key for primary product category (WooCommerce)
     */
    const META_PRIMARY_PRODUCT_CAT = '_metasync_primary_product_cat';

    /**
     * Constructor
     *
     * @param string $version Plugin version
     */
    public function __construct($version = '1.0.0') {
        $this->version = $version;
        $this->init();
    }

    /**
     * Initialize hooks
     */
    private function init() {
        // Register meta fields for REST API
        add_action('init', array($this, 'register_meta_fields'));
        
        // Enqueue block editor assets
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_block_editor_assets'));

        // Track meta state before REST API save, then clean up after
        add_filter('rest_pre_insert_post', array($this, 'track_meta_before_save'), 10, 2);
        add_filter('rest_pre_insert_page', array($this, 'track_meta_before_save'), 10, 2);
        add_action('rest_after_insert_post', array($this, 'cleanup_empty_seo_meta'), 10, 3);
        add_action('rest_after_insert_page', array($this, 'cleanup_empty_seo_meta'), 10, 3);

        // Clean up primary category meta when category is unchecked (all post types)
        add_action('save_post', array($this, 'cleanup_primary_category_meta'), 10, 1);

        // Frontend hooks for outputting SEO meta
        // Custom values ALWAYS take priority over OTTO suggestions
        add_action('wp_head', array($this, 'output_seo_meta_description'), 2);
        add_filter('pre_get_document_title', array($this, 'filter_document_title'), 100);
        add_filter('document_title_parts', array($this, 'filter_document_title_parts'), 100);
    }

    /**
     * Check if OTTO SSR is globally enabled
     *
     * @return bool True if OTTO is enabled globally
     */
    public static function is_otto_enabled_globally() {
        $metasync_options = get_option('metasync_options');
        $otto_enabled = $metasync_options['general']['otto_enable'] ?? false;
        return ($otto_enabled === 'true' || $otto_enabled === true);
    }

    /**
     * Track meta state before REST API save
     * Stores which SEO meta keys existed before the save
     * 
     * @param stdClass $prepared_post Prepared post object
     * @param WP_REST_Request $request Request object
     * @return stdClass Unmodified prepared post object
     */
    public function track_meta_before_save($prepared_post, $request) {
        $post_id = $request->get_param('id');
        if (!$post_id) {
            return $prepared_post;
        }

        // Track which meta keys existed before this save
        $meta_existed = array(
            self::META_SEO_TITLE => metadata_exists('post', $post_id, self::META_SEO_TITLE),
            self::META_DESCRIPTION => metadata_exists('post', $post_id, self::META_DESCRIPTION),
        );

        // Store in transient for use in cleanup
        set_transient('metasync_seo_meta_existed_' . $post_id, $meta_existed, 60);

        return $prepared_post;
    }

    /**
     * Clean up empty SEO meta values after REST API save
     * This prevents blanking fields that are showing OTTO fallback values
     * 
     * Deletes meta keys that are empty AND didn't exist before the save
     * (meaning they were showing OTTO fallback, not user-edited values)
     * 
     * @param WP_Post $post Inserted or updated post object
     * @param WP_REST_Request $_request Request object (unused)
     * @param bool $_creating True when creating a post, false when updating (unused)
     */
    public function cleanup_empty_seo_meta($post, $_request, $_creating) {
        $post_id = $post->ID;

        // Get the before-save state
        $meta_existed = get_transient('metasync_seo_meta_existed_' . $post_id);
        if ($meta_existed === false) {
            // Transient expired or not set, can't make safe decision
            return;
        }

        // Delete transient
        delete_transient('metasync_seo_meta_existed_' . $post_id);

        // Check each of our SEO meta keys
        foreach ($meta_existed as $meta_key => $existed_before) {
            // Get the current value
            $meta_value = get_post_meta($post_id, $meta_key, true);

            // Only delete if:
            // 1. Value is now empty
            // 2. It didn't exist before the save (was showing OTTO fallback)
            if (empty($meta_value) && !$existed_before) {
                delete_post_meta($post_id, $meta_key);
            }
        }
    }

    /**
     * Output meta description tags from sidebar field
     * Custom values ALWAYS take priority over OTTO
     * Outputs: meta description, og:description, and twitter:description
     */
    public function output_seo_meta_description() {
        // Skip for admin, feeds, etc.
        if (is_admin() || is_feed() || is_robots()) {
            return;
        }

        // Only run on singular pages (posts, pages, custom post types)
        if (!is_singular()) {
            return;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return;
        }

        // Get the custom SEO description (takes priority)
        $description = get_post_meta($post_id, self::META_DESCRIPTION, true);

        // Output meta description tags if custom value exists
        // This will be output BEFORE OTTO processes the page, and since we're using
        // a high priority filter, it will override OTTO's meta description
        if (!empty($description)) {
            $description_escaped = esc_attr($description);
            // Standard meta description
            echo '<meta name="description" content="' . $description_escaped . '" data-metasync-seo="custom" />' . "\n";
            // Open Graph description
            echo '<meta property="og:description" content="' . $description_escaped . '" data-metasync-seo="custom" />' . "\n";
            // Twitter description
            echo '<meta name="twitter:description" content="' . $description_escaped . '" data-metasync-seo="custom" />' . "\n";
        }
    }

    /**
     * Filter document title (pre_get_document_title filter)
     * Custom values ALWAYS take priority over OTTO
     *
     * @param string $title Current title
     * @return string Modified title or original
     */
    public function filter_document_title($title) {
        // Skip for admin
        if (is_admin()) {
            return $title;
        }

        // Only run on singular pages
        if (!is_singular()) {
            return $title;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return $title;
        }

        // Get the custom SEO title (takes priority)
        $seo_title = get_post_meta($post_id, self::META_SEO_TITLE, true);

        // Return custom SEO title if set
        return !empty($seo_title) ? $seo_title : $title;
    }

    /**
     * Filter document title parts (for themes using wp_get_document_title)
     * Custom values ALWAYS take priority over OTTO
     *
     * @param array $title_parts Title parts array
     * @return array Modified title parts
     */
    public function filter_document_title_parts($title_parts) {
        // Skip for admin
        if (is_admin()) {
            return $title_parts;
        }

        // Only run on singular pages
        if (!is_singular()) {
            return $title_parts;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return $title_parts;
        }

        // Get the custom SEO title (takes priority)
        $seo_title = get_post_meta($post_id, self::META_SEO_TITLE, true);

        // Replace title part if custom SEO title is set
        if (!empty($seo_title)) {
            $title_parts['title'] = $seo_title;
            // Remove tagline and site for clean SEO title
            unset($title_parts['tagline']);
            unset($title_parts['site']);
        }

        return $title_parts;
    }

    /**
     * Register meta fields for REST API access
     */
    public function register_meta_fields() {
        // Get all public post types
        $post_types = get_post_types(array('public' => true), 'names');

        foreach ($post_types as $post_type) {
            // Register SEO title meta
            register_post_meta($post_type, self::META_SEO_TITLE, array(
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback' => function() {
                    return current_user_can('edit_posts');
                },
            ));

            // Register meta description
            register_post_meta($post_type, self::META_DESCRIPTION, array(
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
                'auth_callback' => function() {
                    return current_user_can('edit_posts');
                },
            ));

            // Note: _metasync_primary_category is registered by Metasync_Breadcrumbs::register_meta_fields() — no duplicate here.

            // Register primary product category meta (WooCommerce-specific, not handled by breadcrumbs module)
            register_post_meta($post_type, self::META_PRIMARY_PRODUCT_CAT, array(
                'show_in_rest' => true,
                'single' => true,
                'type' => 'integer',
                'sanitize_callback' => 'absint',
                'auth_callback' => function() {
                    return current_user_can('edit_posts');
                },
            ));

            // Register OTTO meta keys for REST API (read-only for fallback/prefill)
            register_post_meta($post_type, '_metasync_otto_title', array(
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => function() {
                    return current_user_can('edit_posts');
                },
            ));

            register_post_meta($post_type, '_metasync_otto_description', array(
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => function() {
                    return current_user_can('edit_posts');
                },
            ));

            // Register OTTO disabled flag for REST API (to check if OTTO is disabled per-post)
            register_post_meta($post_type, '_metasync_otto_disabled', array(
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => function() {
                    return current_user_can('edit_posts');
                },
            ));
        }
    }

    /**
     * Enqueue block editor assets
     */
    public function enqueue_block_editor_assets() {
        // Get current screen
        $screen = get_current_screen();
        
        // Only load on post edit screens
        if (!$screen || $screen->base !== 'post') {
            return;
        }

        // Enqueue the sidebar script
        wp_enqueue_script(
            'metasync-seo-sidebar',
            plugin_dir_url(__FILE__) . 'js/metasync-seo-sidebar.js',
            array(
                'wp-plugins',
                'wp-edit-post',
                'wp-element',
                'wp-components',
                'wp-data',
                'wp-compose',
                'wp-i18n',
                'wp-api-fetch',
            ),
            $this->version,
            true
        );

        // Enqueue sidebar styles
        wp_enqueue_style(
            'metasync-seo-sidebar',
            plugin_dir_url(__FILE__) . 'css/metasync-seo-sidebar.css',
            array('wp-components'),
            $this->version
        );

        // Get whitelabel plugin name
        $plugin_name = 'MetaSync';
        if (class_exists('Metasync') && method_exists('Metasync', 'get_effective_plugin_name')) {
            $plugin_name = Metasync::get_effective_plugin_name('MetaSync');
        }

        // Get whitelabel icon URL for the block editor sidebar.
        // Priority: white_label_plugin_menu_icon (the icon set in WL Settings → Branding)
        // because that's the same field driving the admin menu icon.
        // Falls back to whitelabel['logo'], then the bundled default SVG.
        $icon_url = plugin_dir_url(__FILE__) . 'images/icon-256x256.svg';
        $menu_icon = Metasync::get_option('general')['white_label_plugin_menu_icon'] ?? '';
        if (!empty($menu_icon) && filter_var($menu_icon, FILTER_VALIDATE_URL)) {
            $icon_url = $menu_icon;
        } elseif (!empty(Metasync::get_whitelabel_logo())) {
            $icon_url = Metasync::get_whitelabel_logo();
        }

        // Get OTTO whitelabel name
        $otto_name = 'OTTO';
        if (class_exists('Metasync') && method_exists('Metasync', 'get_whitelabel_otto_name')) {
            $otto_name = Metasync::get_whitelabel_otto_name();
        }

        // Get current post ID and check if meta keys exist in database
        $post_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
        $has_seo_title = false;
        $has_seo_description = false;
        
        if ($post_id > 0) {
            // Check if meta keys exist in database (even if value is empty)
            $has_seo_title = metadata_exists('post', $post_id, self::META_SEO_TITLE);
            $has_seo_description = metadata_exists('post', $post_id, self::META_DESCRIPTION);
        }

        // Link Suggestions configuration
        $link_suggestions_config = array(
            'restUrl'             => rest_url('metasync/v1/link-suggestions'),
            'yoastPremiumActive'  => class_exists('WPSEO_Premium'),
            'rankMathActive'      => defined('RANK_MATH_VERSION'),
            'i18n'                => array(
                'panelTitle'      => __('Internal Link Suggestions', 'metasync'),
                'insertButton'    => __('Insert', 'metasync'),
                'refreshButton'   => __('Refresh', 'metasync'),
                'noSuggestions'   => __('No suggestions found.', 'metasync'),
                'loading'         => __('Analyzing content…', 'metasync'),
                'matchedPhrase'   => __('Matched phrase:', 'metasync'),
                'yoastNotice'     => __('Yoast Premium detected. Their internal linking tool may overlap with this feature.', 'metasync'),
                'rankMathNotice'  => __('Rank Math detected. Their internal linking tool may overlap with this feature.', 'metasync'),
            ),
        );

        // Localize script with meta keys and settings
        wp_localize_script('metasync-seo-sidebar', 'metasyncSeoSidebar', array(
            'iconUrl' => $icon_url,
            'metaKeys' => array(
                'seoTitle' => self::META_SEO_TITLE,
                'metaDescription' => self::META_DESCRIPTION,
                // Breadcrumb meta keys
                'breadcrumbTitle' => '_metasync_breadcrumb_title',
                'primaryCategory' => '_metasync_primary_category',
                // OTTO keys for fallback (read-only, used to prefill if manual fields are empty)
                'ottoTitle' => '_metasync_otto_title',
                'ottoDescription' => '_metasync_otto_description',
                // OTTO disabled per-post flag
                'ottoDisabled' => '_metasync_otto_disabled',
                // Primary category
                'primaryCategory' => self::META_PRIMARY_CATEGORY,
                'primaryProductCat' => self::META_PRIMARY_PRODUCT_CAT,
            ),
            'hasMetaKeys' => array(
                // Whether the manual meta keys exist in database (PHP check)
                'seoTitle' => $has_seo_title,
                'metaDescription' => $has_seo_description,
            ),
            'otto' => array(
                'globalEnabled' => self::is_otto_enabled_globally(),
                'name' => $otto_name,
            ),
            'limits' => array(
                'seoTitle' => array(
                    'min' => 50,
                    'max' => 60,
                    'absolute' => 70,
                ),
                'metaDescription' => array(
                    'min' => 120,
                    'max' => 160,
                    'absolute' => 200,
                ),
            ),
            'linkSuggestions' => $link_suggestions_config,
            'i18n' => array(
                /* translators: %s: Plugin name (whitelabel-aware) */
                'panelTitle' => sprintf(__('%s SEO', 'metasync'), $plugin_name),
                'seoTitleLabel' => __('SEO Title', 'metasync'),
                'seoTitleHelp' => __('The title that appears in search engine results. Optimal length: 50-60 characters.', 'metasync'),
                'metaDescriptionLabel' => __('Meta Description', 'metasync'),
                'metaDescriptionHelp' => __('A brief description for search engine results. Optimal length: 120-160 characters.', 'metasync'),
                'urlSlugLabel' => __('URL Slug', 'metasync'),
                'urlSlugHelp' => __('The URL-friendly version of the post name. Use lowercase letters, numbers, and hyphens only.', 'metasync'),
                'serpPreviewTitle' => __('SERP Preview', 'metasync'),
                'serpPreviewHelp' => __('Preview how your page will appear in Google search results.', 'metasync'),
                'serpDesktop' => __('Desktop', 'metasync'),
                'serpMobile' => __('Mobile', 'metasync'),
                'characters' => __('characters', 'metasync'),
                'primaryCategoryLabel' => __('Primary Category', 'metasync'),
                'primaryCategoryHelp' => __('Used in breadcrumbs and canonical URL when multiple categories are assigned.', 'metasync'),
                'primaryCategoryNote' => __('Assign 2+ categories to enable this option.', 'metasync'),
                'ottoPrefillHelp' => sprintf(
                    __('Pre-filled from %s. Edit to customize.', 'metasync'),
                    $otto_name
                ),
                'breadcrumbPanelTitle' => __('Breadcrumbs', 'metasync'),
                'breadcrumbTitleLabel' => __('Breadcrumb Title Override', 'metasync'),
                'breadcrumbTitleHelp' => __('Custom label for this page in breadcrumb trails. Leave empty to use the post title.', 'metasync'),
                'primaryCategoryLabel' => __('Primary Category', 'metasync'),
                'primaryCategoryHelp' => __('Select which category appears in the breadcrumb path when this post belongs to multiple categories.', 'metasync'),
                /* translators: %s: OTTO name (whitelabel) */
                'ottoOverrideNotice' => sprintf(
                    __('%s is enabled. Any SEO title and description changes from %s will be overwritten by your custom values entered here.', 'metasync'),
                    $otto_name,
                    $otto_name
                ),
            ),
        ));
    }

    /**
     * Clean up primary category meta if the selected category was unchecked from the post.
     * Runs on save_post to cover all post types, not just 'post' and 'page'.
     *
     * @param int $post_id Post ID
     */
    public function cleanup_primary_category_meta($post_id) {
        // Clean up primary category
        $primary_cat = (int) get_post_meta($post_id, self::META_PRIMARY_CATEGORY, true);
        if ($primary_cat > 0) {
            $post_categories = wp_get_post_categories($post_id);
            if (!in_array($primary_cat, $post_categories)) {
                delete_post_meta($post_id, self::META_PRIMARY_CATEGORY);
            }
        }

        // Clean up primary product category (WooCommerce)
        $primary_product_cat = (int) get_post_meta($post_id, self::META_PRIMARY_PRODUCT_CAT, true);
        if ($primary_product_cat > 0) {
            $product_cat_ids = wp_get_object_terms($post_id, 'product_cat', array('fields' => 'ids'));
            if (!is_wp_error($product_cat_ids) && !in_array($primary_product_cat, $product_cat_ids)) {
                delete_post_meta($post_id, self::META_PRIMARY_PRODUCT_CAT);
            }
        }
    }

}


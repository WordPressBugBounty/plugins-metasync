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
     * Meta keys for values brought in by the external SEO importer.
     *
     * Deliberately separate from META_SEO_TITLE / META_DESCRIPTION. Those two
     * mean "the customer typed this" and therefore outrank OTTO — the Classic
     * meta box tells the user as much ("Leave the field blank to use OTTO's
     * suggestion"). A bulk import is not a per-post customer decision, so
     * writing it there silently opted every imported post out of OTTO.
     *
     * These keys render only where OTTO has no suggestion. See
     * filter_document_title_imported() and output_seo_meta_description().
     */
    const META_IMPORTED_SEO_TITLE = Metasync_Seo_Precedence::KEY_IMPORTED_TITLE;
    const META_IMPORTED_DESCRIPTION = Metasync_Seo_Precedence::KEY_IMPORTED_DESC;

    /**
     * Meta key for the OTTO focus keyword (read-only, written by OTTO)
     */
    const META_OTTO_KEYWORDS = '_metasync_otto_keywords';

    /**
     * Meta key for primary category (used in breadcrumbs and canonical URL)
     */
    const META_PRIMARY_CATEGORY = '_metasync_primary_category';

    /**
     * Meta key for primary product category (WooCommerce)
     */
    const META_PRIMARY_PRODUCT_CAT = '_metasync_primary_product_cat';

    /**
     * Meta key for hreflang / language alternates (JSON-encoded array)
     */
    const META_HREFLANG = '_metasync_hreflang';

    /**
     * Meta key for plugin sync timestamps (JSON-encoded object keyed by plugin slug)
     */
    const META_PLUGIN_SYNC_TS = '_metasync_plugin_sync_ts';

    /**
     * Meta key for advanced robots directives (JSON-encoded object)
     */
    const META_ROBOTS_ADVANCED = '_metasync_robots_advanced';

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

        // Clean up emptied SEO meta rows after REST saves (all public post types)
        add_action('rest_api_init', array($this, 'register_rest_save_hooks'));

        // Clean up primary category meta when category is unchecked (all post types)
        add_action('save_post', array($this, 'cleanup_primary_category_meta'), 10, 1);

        // Frontend hooks for outputting SEO meta
        // Custom values ALWAYS take priority over OTTO suggestions
        add_action('wp_head', array($this, 'output_seo_meta_description'), 2);
        add_filter('pre_get_document_title', array($this, 'filter_document_title'), 100);
        add_filter('document_title_parts', array($this, 'filter_document_title_parts'), 100);

        // Imported values sit BELOW OTTO. Priority 98 runs before OTTO's own
        // pre_get_document_title callback (priority 99, see
        // otto/metasync-otto-seo-functions.php), so OTTO overwrites this value
        // whenever it has a suggestion and leaves it alone when it does not.
        // The customer's own title at priority 100 still beats both.
        add_filter('pre_get_document_title', array($this, 'filter_document_title_imported'), 98);
    }

    /**
     * Register REST API cleanup hooks for all public post types
     */
    public function register_rest_save_hooks() {
        $post_types = get_post_types(array('public' => true), 'names');
        unset($post_types['attachment']);
        foreach ($post_types as $post_type) {
            add_action("rest_after_insert_{$post_type}", array($this, 'cleanup_empty_seo_meta'), 10, 3);
        }
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
     * Clean up empty SEO meta values after a REST save
     *
     * The REST API cannot delete a meta key — clearing a sidebar field
     * persists an empty string, and that empty row would keep outranking
     * OTTO/imported suggestions forever. Mirror the classic metabox save
     * path instead: an empty value loses its row so the post falls back to
     * the suggestion tiers again.
     *
     * @param WP_Post|int $post Inserted or updated post object (or its ID)
     * @param WP_REST_Request $_request Request object (unused)
     * @param bool $_creating True when creating a post, false when updating (unused)
     */
    public function cleanup_empty_seo_meta($post, $_request, $_creating) {
        $post_id = is_object($post) ? (int) $post->ID : (int) $post;
        if (!$post_id) {
            return;
        }

        foreach (array(self::META_SEO_TITLE, self::META_DESCRIPTION) as $meta_key) {
            $meta_value = get_post_meta($post_id, $meta_key, true);

            // Strict '' check: get_post_meta() returns '' for a missing row
            // too, where delete_post_meta() is a harmless no-op. A real
            // (non-empty) value the user typed is never dropped.
            if ($meta_value === '') {
                delete_post_meta($post_id, $meta_key);
            }
        }
    }

    /**
     * Output meta description tags from sidebar field
     * Custom values ALWAYS take priority over OTTO
     * Outputs: meta description, og:description, and twitter:description,
     * plus og:title / twitter:title when a suppressed third-party tag needs replacing
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

        $conflict_handler = Metasync_SEO_Conflict_Handler::get_instance();

        // og:title / twitter:title replacement (WP-609).
        //
        // Still needed, but for a narrower case than when it was written. The
        // conflict-handler filters now hand a third-party plugin MetaSync's OG
        // title whenever one is set, so that plugin renders it and nothing is
        // required here. What the filters cannot cover is a post holding a page
        // title and no OG-specific value: there they pass the plugin's own tag
        // through, and without this block the page carries the plugin's og:title
        // while <title> carries ours. og_title_needs_replacement() returns false
        // once the filters are supplying the tag, so the two never both emit.
        if ($conflict_handler->og_title_needs_replacement()) {
            $title = $conflict_handler->get_metasync_title();
            if (!empty($title)) {
                $title_escaped = esc_attr($title);
                echo '<meta property="og:title" content="' . $title_escaped . '" data-metasync-seo="custom" />' . "\n";
                echo '<meta name="twitter:title" content="' . $title_escaped . '" data-metasync-seo="custom" />' . "\n";
            }
        }

        // With an active third-party SEO plugin, that plugin emits the description
        // tag and the conflict handler hands it MetaSync's value when MetaSync
        // holds one. Emitting here as well would put two identical description
        // tags on the page.
        //
        // The condition is "will that plugin render a description", not "has a
        // sync run": a timestamp only records that a sync happened, and the
        // mirrored copy can be edited or emptied in the plugin afterwards. When
        // neither side holds anything we fall through, so a stale or partial sync
        // cannot drop the description entirely.
        $defer_meta_description = false;
        if ($conflict_handler->has_active_seo_plugin()) {
            if ($conflict_handler->metasync_has_description()
                || $conflict_handler->active_plugin_has_description($post_id)) {
                $defer_meta_description = true;
            }
        }

        // With a plugin active the conflict handler now supplies og:/twitter:
        // description too — falling back to the page description when no
        // OG-specific value is set — so that plugin renders exactly one. Emitting
        // here as well is always a second tag, never a replacement.
        $defer_og_description = $conflict_handler->has_active_seo_plugin()
            || $conflict_handler->third_party_owns_og_description();

        if ($defer_meta_description && $defer_og_description) {
            return;
        }

        // Which stored value wins is decided in one place, so the global
        // "SEO Title & Description Priority" setting reaches this emitter
        // without a second copy of the rule. Under the default this is still
        // the customer's description; under OTTO priority the chain hands back
        // OTTO's value and falls back to the custom one where OTTO has none.
        $resolved    = Metasync_Seo_Precedence::resolve($post_id, Metasync_Seo_Precedence::FIELD_DESCRIPTION);
        $description = $resolved['value'];

        // The marker has to describe what actually won, not which code path
        // emitted it: Otto_html_class treats data-metasync-seo="custom" as an
        // explicit override that must survive the buffer pass, and a tag
        // carrying OTTO's own value must not claim to be one. The resolver
        // already names the tier it took the value from, so the marker follows
        // that rather than being inferred a second time here.
        $is_imported_fallback = $resolved['source'] === Metasync_Seo_Precedence::SOURCE_IMPORTED;
        $is_otto_value        = Metasync_Seo_Precedence::is_otto_value(
            $post_id,
            Metasync_Seo_Precedence::FIELD_DESCRIPTION,
            $resolved
        );

        // Exactly one printer owns each description value. When OTTO's stored
        // suggestion is the resolved winner, this emitter stands down and
        // metasync_output_otto_meta_description() (wp_head priority 1) prints
        // it — that emitter already covers every delivery path this one runs
        // on, and both firing ships two identical description tags on pages
        // the OTTO SSR buffer dedup never reaches (cold/served-from-cache,
        // rate-limited, or Cloudflare-pixel mode). Every other tier — the
        // customer's value, the persisted pair, imported fallbacks — is still
        // printed here, and the og:title replacement above is unaffected.
        // Mirrors the ownership rule in that emitter's stand-down guard.
        if ($resolved['key'] === Metasync_Seo_Precedence::KEY_OTTO_DESC) {
            return;
        }

        // An imported value is migration data rather than a per-post customer
        // decision, so it only renders where MetaSync owns the output.
        if ($is_imported_fallback && $this->third_party_owns_output()) {
            $description          = '';
            $is_imported_fallback = false;
        }

        // Output meta description tags if custom value exists
        // This will be output BEFORE OTTO processes the page, and since we're using
        // a high priority filter, it will override OTTO's meta description
        if (!empty($description)) {
            $description_escaped = esc_attr($description);
            if ($is_imported_fallback) {
                $marker = ' data-metasync-seo="imported"';
            } elseif ($is_otto_value) {
                $marker = ' data-metasync-otto="true"';
            } else {
                $marker = ' data-metasync-seo="custom"';
            }
            // Standard meta description
            if (!$defer_meta_description) {
                echo '<meta name="description" content="' . $description_escaped . '"' . $marker . ' />' . "\n";
            }

            // og:description / twitter:description are Open Graph & social tags, so they
            // answer to the site-wide Social Media & Open Graph switch as well as the
            // per-post "Enable Open Graph & Social Media Tags" toggle. For the per-post
            // toggle only an explicit '0' opt-out disables them (unset/empty counts as
            // enabled), mirroring Metasync_OpenGraph::will_emit(). When either says no,
            // MetaSync emits no OG/Twitter description, so it neither overrides a
            // third-party plugin's tag nor leaves one sourced from our database.
            //
            // The plain <meta name="description"> above is deliberately NOT gated here:
            // it belongs to the SEO title/description feature, which has its own setting.
            if (!$defer_og_description
                && !Metasync_OpenGraph::is_social_output_disabled($post_id)) {
                // Open Graph description
                echo '<meta property="og:description" content="' . $description_escaped . '"' . $marker . ' />' . "\n";
                // Twitter description
                echo '<meta name="twitter:description" content="' . $description_escaped . '"' . $marker . ' />' . "\n";
            }
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

        // Which stored value wins is decided in one place, so the global
        // "SEO Title & Description Priority" setting reaches this filter
        // without a second copy of the rule. Under the default the customer's
        // title still wins; under OTTO priority the chain hands back OTTO's
        // value and falls back to the custom one where OTTO has none.
        //
        // The resolver reads the whole chain, so it also answers for a post
        // whose title lives on the persisted or imported tier — cases this
        // filter used to miss entirely by reading only the sidebar key.
        $seo_title = Metasync_Seo_Precedence::value($post_id, Metasync_Seo_Precedence::FIELD_TITLE);

        return !empty($seo_title) ? $seo_title : $title;
    }

    /**
     * Whether a third-party SEO plugin is the one actually rendering this page.
     *
     * Mirrors the guard OTTO's own filters use
     * (otto/metasync-otto-seo-functions.php): when an active plugin owns output
     * and OTTO has nothing live to say, leave that plugin's tags alone.
     *
     * Gates the *imported* value only. Imported values are migration data with
     * no per-post customer intent behind them, so they must never compete with a
     * plugin the customer is still using. The description emitter is an echo on
     * wp_head rather than a filter return, so without this it would add a second
     * <meta name="description"> next to the third-party plugin's own.
     *
     * @return bool True when MetaSync should stand down for imported values.
     */
    private function third_party_owns_output() {
        if (!class_exists('Metasync_SEO_Conflict_Handler')) {
            return false;
        }

        $handler = Metasync_SEO_Conflict_Handler::get_instance();

        return $handler->has_active_seo_plugin() && !$handler->otto_has_live_suggestions();
    }

    /**
     * Supply an imported SEO title as a fallback BELOW OTTO.
     *
     * Registered at priority 98 so OTTO's own callback (priority 99) runs
     * afterwards: OTTO overwrites this whenever it has a suggestion, and
     * returns the title untouched when it does not — which is exactly when the
     * imported value should show. The customer's own title (priority 100) still
     * wins over both.
     *
     * @param string $title Current title.
     * @return string       Imported title when it should apply, else unchanged.
     */
    public function filter_document_title_imported($title) {
        if (is_admin() || !is_singular()) {
            return $title;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return $title;
        }

        // Never outrank a third-party SEO plugin that is the one actually
        // rendering this page.
        if ($this->third_party_owns_output()) {
            return $title;
        }

        $imported = get_post_meta($post_id, self::META_IMPORTED_SEO_TITLE, true);

        return (!empty($imported) && is_string($imported)) ? $imported : $title;
    }

    /**
     * Filter document title parts (for themes using wp_get_document_title)
     *
     * Which stored value wins is decided by Metasync_Seo_Precedence, so this
     * filter follows the global "SEO Title & Description Priority" setting
     * without restating the order.
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

        $seo_title = Metasync_Seo_Precedence::value($post_id, Metasync_Seo_Precedence::FIELD_TITLE);

        // Replace title part if MetaSync holds a title for this post
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
                'auth_callback' => function($allowed, $meta_key, $object_id) use ($post_type) {
                    if (empty($object_id)) {
                        $pt_obj = get_post_type_object($post_type);
                        return $pt_obj ? current_user_can($pt_obj->cap->edit_posts) : current_user_can('edit_posts');
                    }
                    return current_user_can('edit_post', $object_id);
                },
            ));

            // Register meta description
            register_post_meta($post_type, self::META_DESCRIPTION, array(
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
                'auth_callback' => function($allowed, $meta_key, $object_id) use ($post_type) {
                    if (empty($object_id)) {
                        $pt_obj = get_post_type_object($post_type);
                        return $pt_obj ? current_user_can($pt_obj->cap->edit_posts) : current_user_can('edit_posts');
                    }
                    return current_user_can('edit_post', $object_id);
                },
            ));

            // Register breadcrumb title override
            register_post_meta($post_type, '_metasync_breadcrumb_title', array(
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback' => function($allowed, $meta_key, $object_id) use ($post_type) {
                    if (empty($object_id)) {
                        $pt_obj = get_post_type_object($post_type);
                        return $pt_obj ? current_user_can($pt_obj->cap->edit_posts) : current_user_can('edit_posts');
                    }
                    return current_user_can('edit_post', $object_id);
                },
            ));

            // Register primary category for breadcrumbs
            register_post_meta($post_type, '_metasync_primary_category', array(
                'show_in_rest' => true,
                'single' => true,
                'type' => 'integer',
                'sanitize_callback' => 'absint',
                'auth_callback' => function($allowed, $meta_key, $object_id) use ($post_type) {
                    if (empty($object_id)) {
                        $pt_obj = get_post_type_object($post_type);
                        return $pt_obj ? current_user_can($pt_obj->cap->edit_posts) : current_user_can('edit_posts');
                    }
                    return current_user_can('edit_post', $object_id);
                },
            ));

            // Register primary product category meta (WooCommerce-specific)
            register_post_meta($post_type, self::META_PRIMARY_PRODUCT_CAT, array(
                'show_in_rest' => true,
                'single' => true,
                'type' => 'integer',
                'sanitize_callback' => 'absint',
                'auth_callback' => function($allowed, $meta_key, $object_id) use ($post_type) {
                    if (empty($object_id)) {
                        $pt_obj = get_post_type_object($post_type);
                        return $pt_obj ? current_user_can($pt_obj->cap->edit_posts) : current_user_can('edit_posts');
                    }
                    return current_user_can('edit_post', $object_id);
                },
            ));

            // Register OTTO meta keys for REST API (read-only for fallback/prefill)
            register_post_meta($post_type, '_metasync_otto_title', array(
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => function($allowed, $meta_key, $object_id) use ($post_type) {
                    if (empty($object_id)) {
                        $pt_obj = get_post_type_object($post_type);
                        return $pt_obj ? current_user_can($pt_obj->cap->edit_posts) : current_user_can('edit_posts');
                    }
                    return current_user_can('edit_post', $object_id);
                },
            ));

            register_post_meta($post_type, '_metasync_otto_description', array(
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => function($allowed, $meta_key, $object_id) use ($post_type) {
                    if (empty($object_id)) {
                        $pt_obj = get_post_type_object($post_type);
                        return $pt_obj ? current_user_can($pt_obj->cap->edit_posts) : current_user_can('edit_posts');
                    }
                    return current_user_can('edit_post', $object_id);
                },
            ));

            // Register imported SEO meta for REST API (read-only fallback shown
            // as a placeholder only when OTTO has no suggestion)
            foreach (array(self::META_IMPORTED_SEO_TITLE, self::META_IMPORTED_DESCRIPTION) as $imported_key) {
                register_post_meta($post_type, $imported_key, array(
                    'show_in_rest' => true,
                    'single' => true,
                    'type' => 'string',
                    'auth_callback' => function($allowed, $meta_key, $object_id) use ($post_type) {
                        if (empty($object_id)) {
                            $pt_obj = get_post_type_object($post_type);
                            return $pt_obj ? current_user_can($pt_obj->cap->edit_posts) : current_user_can('edit_posts');
                        }
                        return current_user_can('edit_post', $object_id);
                    },
                ));
            }

            // Register OTTO focus keyword for REST API (read-only display in sidebar)
            register_post_meta($post_type, self::META_OTTO_KEYWORDS, array(
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => function($allowed, $meta_key, $object_id) use ($post_type) {
                    if (empty($object_id)) {
                        $pt_obj = get_post_type_object($post_type);
                        return $pt_obj ? current_user_can($pt_obj->cap->edit_posts) : current_user_can('edit_posts');
                    }
                    return current_user_can('edit_post', $object_id);
                },
            ));

            // Register OTTO disabled flag for REST API (to check if OTTO is disabled per-post)
            register_post_meta($post_type, '_metasync_otto_disabled', array(
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => function($allowed, $meta_key, $object_id) use ($post_type) {
                    if (empty($object_id)) {
                        $pt_obj = get_post_type_object($post_type);
                        return $pt_obj ? current_user_can($pt_obj->cap->edit_posts) : current_user_can('edit_posts');
                    }
                    return current_user_can('edit_post', $object_id);
                },
            ));

            // Register hreflang / language alternates meta (JSON-encoded array).
            // Gated on the Language Alternates feature flag: when the feature is
            // disabled the meta is not registered for REST, so the Gutenberg panel
            // has nothing to bind to. Saved values are kept regardless.
            if (class_exists('Metasync_Feature_Flags') && Metasync_Feature_Flags::is_enabled(Metasync_Feature_Flags::LANGUAGE_ALTERNATES)) {
                register_post_meta($post_type, self::META_HREFLANG, array(
                    'show_in_rest' => true,
                    'single' => true,
                    'type' => 'string',
                    'sanitize_callback' => array(__CLASS__, 'sanitize_hreflang_meta'),
                    'auth_callback' => function($allowed, $meta_key, $object_id) use ($post_type) {
                        if (empty($object_id)) {
                            $pt_obj = get_post_type_object($post_type);
                            return $pt_obj ? current_user_can($pt_obj->cap->edit_posts) : current_user_can('edit_posts');
                        }
                        return current_user_can('edit_post', $object_id);
                    },
                ));
            }

            // Register plugin sync timestamp meta
            register_post_meta($post_type, self::META_PLUGIN_SYNC_TS, array(
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => function($allowed, $meta_key, $object_id) use ($post_type) {
                    if (empty($object_id)) {
                        $pt_obj = get_post_type_object($post_type);
                        return $pt_obj ? current_user_can($pt_obj->cap->edit_posts) : current_user_can('edit_posts');
                    }
                    return current_user_can('edit_post', $object_id);
                },
            ));

            // Register advanced robots directives meta
            register_post_meta($post_type, self::META_ROBOTS_ADVANCED, array(
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'sanitize_callback' => function($value) {
                    $decoded = json_decode($value, true);
                    if (!is_array($decoded)) {
                        return '{}';
                    }
                    $allowed_bool = ['nofollow', 'noarchive', 'nosnippet', 'noimageindex'];
                    $sanitized = [];
                    foreach ($allowed_bool as $key) {
                        if (isset($decoded[$key])) {
                            $sanitized[$key] = (bool) $decoded[$key];
                        }
                    }
                    if (isset($decoded['max_snippet'])) {
                        $sanitized['max_snippet'] = (int) $decoded['max_snippet'];
                    }
                    if (isset($decoded['max_image_preview'])) {
                        $allowed = ['none', 'standard', 'large'];
                        $sanitized['max_image_preview'] = in_array($decoded['max_image_preview'], $allowed, true)
                            ? $decoded['max_image_preview'] : 'large';
                    }
                    if (isset($decoded['max_video_preview'])) {
                        $sanitized['max_video_preview'] = (int) $decoded['max_video_preview'];
                    }
                    return wp_json_encode($sanitized);
                },
                'auth_callback' => function($allowed, $meta_key, $object_id) use ($post_type) {
                    if (empty($object_id)) {
                        $pt_obj = get_post_type_object($post_type);
                        return $pt_obj ? current_user_can($pt_obj->cap->edit_posts) : current_user_can('edit_posts');
                    }
                    return current_user_can('edit_post', $object_id);
                },
            ));
        }
    }

    /**
     * Sanitize the `_metasync_hreflang` meta value (register_post_meta
     * sanitize_callback; static so it is directly testable).
     *
     * The stored value is a JSON array of {lang, region, url} rows. Rows are
     * normalised through Metasync_Hreflang_Output::normalize_entry() (so
     * `en_US` becomes `en`+`US`, casing is folded, `x-default` drops its
     * region), fully blank rows are dropped, and malformed input degrades to
     * an empty array. Rows whose language code still fails validation after
     * normalisation are KEPT — the emitter skips them, and the editor panel
     * warns — so saving never silently erases what the user typed.
     *
     * @param  mixed $value Incoming JSON string.
     * @return string JSON array string.
     */
    public static function sanitize_hreflang_meta($value) {
        // A non-string (e.g. a future importer passing an array straight to
        // update_post_meta) must degrade to "no alternates" rather than
        // fatal inside sanitize_meta on PHP 8.
        if (!is_string($value)) {
            return '[]';
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return '[]';
        }

        $clean = array();
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $normalized = Metasync_Hreflang_Output::normalize_entry($entry);
            if ($normalized['lang'] === '' && $normalized['url'] === '') {
                // A stray blank row ("Add alternate" clicked, never filled
                // in) must not flip the post into MetaSync-emits mode on
                // WPML sites.
                continue;
            }
            $clean[] = array(
                'lang'   => $normalized['lang'],
                'region' => $normalized['region'],
                'url'    => $normalized['url'],
            );
        }

        // UNESCAPED_* keeps non-ASCII URLs readable in storage and free of
        // \uXXXX escapes that could be corrupted by a later unslash.
        return wp_json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build a read-only list of WPML translation entries for a post.
     *
     * Delegates to the shared implementation in
     * Metasync_Hreflang_Output::get_wpml_entries() so the editor panel shows
     * exactly what the front end emits — including the synthesised
     * x-default row and the region part — instead of a divergent copy of
     * the query.
     *
     * @param int $post_id Post ID.
     * @return array
     */
    private function get_wpml_entries_for_post($post_id) {
        if (!defined('ICL_SITEPRESS_VERSION') || $post_id <= 0) {
            return array();
        }

        $hreflang = new Metasync_Hreflang_Output();
        $entries  = $hreflang->get_wpml_entries($post_id);

        $out = array();
        foreach ($entries as $entry) {
            $out[] = array(
                'lang'   => isset($entry['lang']) ? $entry['lang'] : '',
                'region' => isset($entry['region']) ? $entry['region'] : '',
                'url'    => isset($entry['url']) ? $entry['url'] : '',
            );
        }
        return $out;
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

        // Get current post ID (used for the LPS/custom-page check and WPML
        // entries below)
        $post_id = isset($_GET['post']) ? intval($_GET['post']) : 0;

        // LPS / custom-HTML pages bake their own SEO — suppress the editable sidebar
        // panels and surface a read-only notice instead.
        // otto_pixel.php (where metasync_is_custom_or_lps_page lives) is only
        // required conditionally, so the function_exists guard is needed at runtime.
        $is_lps_page = function_exists('metasync_is_custom_or_lps_page') && $post_id > 0 && metasync_is_custom_or_lps_page($post_id);

        // Auto-detected WPML entries for the "Language Alternates" panel.
        // Gated on the feature flag: when disabled the panel is hidden, so the
        // entries are zeroed to avoid seeding data the UI cannot bind to.
        $language_alternates_enabled = class_exists('Metasync_Feature_Flags')
            && Metasync_Feature_Flags::is_enabled(Metasync_Feature_Flags::LANGUAGE_ALTERNATES);
        $wpml_entries = $language_alternates_enabled
            ? $this->get_wpml_entries_for_post($post_id)
            : array();

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

        // Detect active SEO plugins that provide their own primary category selector
        $other_seo_primary = array(
            'yoastActive'    => defined('WPSEO_VERSION'),
            'rankMathActive' => defined('RANK_MATH_VERSION'),
            'aioseoActive'   => defined('AIOSEO_VERSION') || class_exists('AIOSEO\\Plugin\\AIOSEO'),
        );

        // Schema Content configuration
        $schema_content_config = array(
            'restUrl'             => rest_url('metasync/v1/schema-content'),
            'woocommerceActive'   => class_exists('WooCommerce'),
            'woocommerceData'     => $this->get_woocommerce_data_for_post($post_id),
            'i18n'                => array(
                'panelTitle'          => __('Schema Markup Content', 'metasync'),
                'saveButton'          => __('Save Schema Content', 'metasync'),
                'autoPopulateWC'      => __('Auto-populate from WooCommerce', 'metasync'),
                'noSchemaTypes'       => __('No schema types configured. Add schema types in the classic editor or via the Schema Markup metabox.', 'metasync'),
                'addQuestion'         => __('Add Question', 'metasync'),
                'removeQuestion'      => __('Remove', 'metasync'),
                'addStep'             => __('Add Step', 'metasync'),
                'removeStep'          => __('Remove', 'metasync'),
                'addIngredient'       => __('Add Ingredient', 'metasync'),
                'removeIngredient'    => __('Remove', 'metasync'),
                'addInstruction'      => __('Add Instruction', 'metasync'),
                'removeInstruction'   => __('Remove', 'metasync'),
                'saving'              => __('Saving...', 'metasync'),
                'saved'               => __('Schema content saved.', 'metasync'),
                'saveError'           => __('Failed to save schema content.', 'metasync'),
            ),
        );

        // Localize script with meta keys and settings
        wp_localize_script('metasync-seo-sidebar', 'metasyncSeoSidebar', array(
            'iconUrl' => $icon_url,
            'isCustomOrLpsPage' => $is_lps_page,
            'otherSeoPrimary' => $other_seo_primary,
            'metaKeys' => array(
                'seoTitle' => self::META_SEO_TITLE,
                'metaDescription' => self::META_DESCRIPTION,
                // Breadcrumb meta keys
                'breadcrumbTitle' => '_metasync_breadcrumb_title',
                // OTTO keys for fallback (read-only, used to prefill if manual fields are empty)
                'ottoTitle' => '_metasync_otto_title',
                'ottoDescription' => '_metasync_otto_description',
                // Imported keys (read-only). Rank below OTTO — used as the
                // placeholder only when OTTO has no suggestion for the post.
                'importedTitle' => self::META_IMPORTED_SEO_TITLE,
                'importedDescription' => self::META_IMPORTED_DESCRIPTION,
                // OTTO focus keyword (read-only display only)
                'ottoKeywords' => self::META_OTTO_KEYWORDS,
                // OTTO disabled per-post flag
                'ottoDisabled' => '_metasync_otto_disabled',
                // Primary category
                'primaryCategory' => self::META_PRIMARY_CATEGORY,
                'primaryProductCat' => self::META_PRIMARY_PRODUCT_CAT,
                // Language alternates (hreflang) — JSON-encoded array
                'hreflang' => self::META_HREFLANG,
                // Plugin sync timestamp
                'pluginSyncTs' => self::META_PLUGIN_SYNC_TS,
                // Advanced robots directives
                'robotsAdvanced' => self::META_ROBOTS_ADVANCED,
            ),
            'activeSeoPlugins' => array(
                'yoast'    => defined('WPSEO_VERSION'),
                'rankmath' => defined('RANK_MATH_VERSION'),
                'aioseo'   => defined('AIOSEO_VERSION') || class_exists('AIOSEO\\Plugin\\AIOSEO'),
            ),
            'wpmlEntries' => $wpml_entries,
            // '1' when enabled, '' when disabled. wp_localize_script() casts
            // top-level scalars with (string), so a raw boolean false would
            // arrive as "" and could not be distinguished from a missing key
            // by a strict JS check. Follows the isCustomOrLpsPage pattern.
            'languageAlternatesEnabled' => $language_alternates_enabled ? '1' : '',
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
            'schemaContent' => $schema_content_config,
            'linkSuggestions' => $link_suggestions_config,
            'i18n' => array(
                /* translators: %s: Plugin name (whitelabel-aware) */
                'panelTitle' => sprintf(__('%s SEO', 'metasync'), $plugin_name),
                'seoTitleLabel' => __('SEO Title', 'metasync'),
                'seoTitleHelp' => __('The title that appears in search engine results. Optimal length: 50-60 characters.', 'metasync'),
                'metaDescriptionLabel' => __('Meta Description', 'metasync'),
                'metaDescriptionHelp' => __('A brief description for search engine results. Optimal length: 120-160 characters.', 'metasync'),
                'serpPreviewTitle' => __('SERP Preview', 'metasync'),
                'serpPreviewHelp' => __('Preview how your page will appear in Google search results.', 'metasync'),
                'serpDesktop' => __('Desktop', 'metasync'),
                'serpMobile' => __('Mobile', 'metasync'),
                'characters' => __('characters', 'metasync'),
                'primaryCategoryNote' => __('Assign 2+ categories to enable this option.', 'metasync'),
                'ottoPrefillHelp' => sprintf(
                    __('Pre-filled from %s. Edit to customize.', 'metasync'),
                    $otto_name
                ),
                'importedPrefillHelp' => sprintf(
                    /* translators: %s: OTTO name (whitelabel) */
                    __('Suggestion imported from another SEO plugin. Used only until %s has its own suggestion for this page.', 'metasync'),
                    $otto_name
                ),
                // Focus Keyword: read-only field surfacing the OTTO keyword
                'focusKeywordLabel' => __('Focus Keyword', 'metasync'),
                'focusKeywordHelp' => sprintf(
                    /* translators: %s: OTTO name (whitelabel) */
                    __('Managed by %s. Set in Search Atlas — read-only here.', 'metasync'),
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
                // Language Alternates (hreflang) panel strings
                'languageAlternatesTitle' => __('Language Alternates', 'metasync'),
                'addAlternate' => __('Add alternate', 'metasync'),
                'langLabel' => __('Language', 'metasync'),
                'regionLabel' => __('Region', 'metasync'),
                'urlLabel' => __('URL', 'metasync'),
                'editManually' => __('Edit Manually', 'metasync'),
                'wpmlAutoPopulated' => __('Auto-populated from WPML. Click Edit Manually to override.', 'metasync'),
                'hreflangFormatHelp' => __('Use ISO codes (e.g. en, en-US, x-default — hyphen, not underscore) and absolute URLs (https://…). A self-reference for this page is added automatically when missing.', 'metasync'),
                'hreflangInvalidRow' => __('This row will not be emitted: the language code must look like "en" or "en-US", and the URL must be absolute (https://…).', 'metasync'),
                // Advanced Robots Directives
                'robotsAdvancedTitle' => __('Advanced Robots Directives', 'metasync'),
                'nofollowLabel' => __('Nofollow', 'metasync'),
                'nofollowHelp' => __('Prevent search engines from following links on this page.', 'metasync'),
                'noarchiveLabel' => __('Noarchive', 'metasync'),
                'noarchiveHelp' => __('Prevent search engines from showing cached versions.', 'metasync'),
                'nosnippetLabel' => __('Nosnippet', 'metasync'),
                'nosnippetHelp' => __('Prevent search engines from showing text snippets.', 'metasync'),
                'noimageindexLabel' => __('No Image Index', 'metasync'),
                'noimageindexHelp' => __('Prevent this page from appearing as image search referrer.', 'metasync'),
                'maxSnippetLabel' => __('Max Snippet Length', 'metasync'),
                'maxSnippetHelp' => __('Maximum character length for text snippets. -1 for unlimited.', 'metasync'),
                'maxImagePreviewLabel' => __('Max Image Preview', 'metasync'),
                'maxImagePreviewHelp' => __('Maximum size of image preview in search results.', 'metasync'),
                // Plugin Sync Status
                'syncStatusTitle'  => __('Plugin Sync Status', 'metasync'),
                'syncedTo'         => __('Synced to:', 'metasync'),
                'syncNever'        => __('Never synced', 'metasync'),
                'syncAgo'          => __('ago', 'metasync'),
                // LPS / custom-HTML read-only notice
                'lpsNotice'        => __('The SEO for this page is managed by WebStudio.', 'metasync'),
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
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        // Clean up primary category
        $primary_cat = (int) get_post_meta($post_id, self::META_PRIMARY_CATEGORY, true);
        if ($primary_cat > 0) {
            $taxonomies = get_object_taxonomies(get_post_type($post_id), 'names');
            if (empty($taxonomies)) {
                delete_post_meta($post_id, self::META_PRIMARY_CATEGORY);
                return;
            }
            $term_ids = wp_get_object_terms($post_id, $taxonomies, array('fields' => 'ids'));
            if (!is_wp_error($term_ids) && !in_array($primary_cat, $term_ids)) {
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

    /**
     * Get WooCommerce product data for a post (if WC is active and post is a product)
     *
     * @param int $post_id Post ID
     * @return array|null Product data or null
     */
    private function get_woocommerce_data_for_post($post_id) {
        if (!class_exists('WooCommerce') || $post_id <= 0) {
            return null;
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'product') {
            return null;
        }

        $product = wc_get_product($post_id);
        if (!$product) {
            return null;
        }

        return array(
            'price'        => $product->get_price(),
            'currency'     => get_woocommerce_currency(),
            'availability' => $product->is_in_stock() ? 'InStock' : 'OutOfStock',
            'sku'          => $product->get_sku(),
        );
    }

}


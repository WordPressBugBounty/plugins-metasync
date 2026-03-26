<?php
/**
 * Centralized SEO Plugin Conflict Handler
 *
 * Prevents duplicate meta descriptions when MetaSync coexists with
 * third-party SEO plugins (AIOSEO, Yoast, RankMath, etc.).
 *
 * Strategy:
 *   - When MetaSync (OTTO or sidebar) has a value → suppress the third-party plugin.
 *   - When MetaSync has NO value → let the third-party plugin output its own.
 *   - When NEITHER has a value → let MetaSync's legacy auto-generated description through.
 *
 * @package    MetaSync
 * @subpackage MetaSync/includes
 * @since      2.8.23
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_SEO_Conflict_Handler {

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * Cached result for whether MetaSync has a description for the current page.
     *
     * @var bool|null
     */
    private $has_description_cache = null;

    /**
     * Cached result for whether AIOSEO provides a description for the current page.
     *
     * @var bool|null
     */
    private $aioseo_has_description_cache = null;

    /**
     * Get singleton instance.
     *
     * @return self
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor — use get_instance().
     */
    private function __construct() {
        // Only hook on the frontend
        if (is_admin()) {
            return;
        }

        add_action('wp', [$this, 'register_filters'], 0);
    }

    /**
     * Register filters after the query is parsed (so is_singular() etc. work).
     */
    public function register_filters() {
        if ($this->is_aioseo_active()) {
            $this->register_aioseo_filters();
        }
    }

    // ------------------------------------------------------------------
    // Third-party SEO plugin detection
    // ------------------------------------------------------------------

    /**
     * Check whether AIOSEO (free or pro) is active.
     *
     * @return bool
     */
    public function is_aioseo_active() {
        // Ensure is_plugin_active() is available on the frontend
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php')
            || is_plugin_active('all-in-one-seo-pack-pro/all_in_one_seo_pack.php');
    }

    /**
     * Check whether any supported third-party SEO plugin is active.
     *
     * @return bool
     */
    public function has_active_seo_plugin() {
        // is_plugin_active() availability ensured by is_aioseo_active() call
        return $this->is_aioseo_active()
            || is_plugin_active('wordpress-seo/wp-seo.php')
            || is_plugin_active('seo-by-rank-math/rank-math.php')
            || is_plugin_active('seo-by-rankmath/rank-math.php');
    }

    // ------------------------------------------------------------------
    // MetaSync description resolution
    // ------------------------------------------------------------------

    /**
     * Determine whether MetaSync holds an intentional meta description
     * for the current request.
     *
     * Only considers explicitly set values:
     *   1. SEO sidebar custom value  (_metasync_seo_desc)
     *   2. OTTO persisted description (_metasync_otto_description)
     *
     * Auto-generated excerpts (legacy `meta_description` key) are NOT
     * counted — they should not suppress a third-party plugin.
     *
     * @return bool
     */
    public function metasync_has_description() {
        if ($this->has_description_cache !== null) {
            return $this->has_description_cache;
        }

        $this->has_description_cache = !empty($this->get_metasync_description());
        return $this->has_description_cache;
    }

    /**
     * Return MetaSync's intentional meta description for the current request.
     *
     * Only returns values that were explicitly set (sidebar or OTTO), NOT
     * auto-generated excerpts from the legacy `meta_description` key.
     * This ensures we only suppress third-party plugins when MetaSync has
     * a deliberate SEO value.
     *
     * @return string
     */
    public function get_metasync_description() {
        $post_id = $this->get_current_object_id();

        if (!$post_id) {
            return '';
        }

        // 1. SEO sidebar (highest priority — user-edited)
        $desc = get_post_meta($post_id, '_metasync_seo_desc', true);
        if (!empty($desc)) {
            return $desc;
        }

        // 2. OTTO description
        $desc = get_post_meta($post_id, '_metasync_otto_description', true);
        if (!empty($desc)) {
            return $desc;
        }

        return '';
    }

    /**
     * Reset the cached description flag (useful when the queried object changes).
     */
    public function reset_cache() {
        $this->has_description_cache = null;
        $this->aioseo_has_description_cache = null;
    }

    // ------------------------------------------------------------------
    // AIOSEO integration
    // ------------------------------------------------------------------

    /**
     * Register AIOSEO-specific filters to suppress its output
     * when MetaSync/OTTO already provides the same tags.
     */
    private function register_aioseo_filters() {
        // Suppress AIOSEO meta description
        add_filter('aioseo_description', [$this, 'filter_aioseo_description'], 999);

        // Suppress AIOSEO title
        add_filter('aioseo_title', [$this, 'filter_aioseo_title'], 999);

        // Suppress AIOSEO OG/Twitter tags that OTTO already provides
        add_filter('aioseo_facebook_tags', [$this, 'filter_aioseo_facebook_tags'], 999);
        add_filter('aioseo_twitter_tags', [$this, 'filter_aioseo_twitter_tags'], 999);
    }

    /**
     * Filter AIOSEO description output.
     * Returns empty string when MetaSync has a description, letting MetaSync output it.
     *
     * @param  string $description AIOSEO's computed description.
     * @return string
     */
    public function filter_aioseo_description($description) {
        // Cache whether AIOSEO actually has a description (before we suppress it).
        // This is used later by should_output_legacy_description().
        if ($this->aioseo_has_description_cache === null) {
            $this->aioseo_has_description_cache = !empty($description);
        }

        if ($this->metasync_has_description()) {
            return '';
        }
        return $description;
    }

    /**
     * Filter AIOSEO title output.
     * Returns original unless MetaSync/OTTO has a title.
     *
     * @param  string $title AIOSEO's computed title.
     * @return string
     */
    public function filter_aioseo_title($title) {
        $post_id = $this->get_current_object_id();
        if (!$post_id) {
            return $title;
        }

        if ($this->metasync_has_title($post_id)) {
            return '';
        }

        return $title;
    }

    /**
     * Filter AIOSEO Facebook/OG tags.
     * Removes og:description and og:title when MetaSync/OTTO provides them.
     *
     * @param  array $meta AIOSEO's OG meta array.
     * @return array
     */
    public function filter_aioseo_facebook_tags($meta) {
        if (!is_array($meta)) {
            return $meta;
        }

        $post_id = $this->get_current_object_id();

        if ($this->metasync_has_description()) {
            unset($meta['og:description']);
        }

        if ($post_id && $this->metasync_has_title($post_id)) {
            unset($meta['og:title']);
        }

        return $meta;
    }

    /**
     * Filter AIOSEO Twitter tags.
     * Removes twitter:description and twitter:title when MetaSync/OTTO provides them.
     *
     * @param  array $meta AIOSEO's Twitter meta array.
     * @return array
     */
    public function filter_aioseo_twitter_tags($meta) {
        if (!is_array($meta)) {
            return $meta;
        }

        $post_id = $this->get_current_object_id();

        if ($this->metasync_has_description()) {
            unset($meta['twitter:description']);
        }

        if ($post_id && $this->metasync_has_title($post_id)) {
            unset($meta['twitter:title']);
        }

        return $meta;
    }

    /**
     * Check whether MetaSync/OTTO has a title for a given post.
     *
     * @param  int $post_id Post ID.
     * @return bool
     */
    private function metasync_has_title($post_id) {
        $seo_title = get_post_meta($post_id, '_metasync_seo_title', true);
        if (!empty($seo_title)) {
            return true;
        }

        $otto_title = get_post_meta($post_id, '_metasync_otto_title', true);
        return !empty($otto_title);
    }

    // ------------------------------------------------------------------
    // MetaSync output gating
    // ------------------------------------------------------------------

    /**
     * Whether the legacy `hook_metasync_metatags()` should output a description tag.
     *
     * Decision matrix (when a third-party SEO plugin is active):
     *   MetaSync has value  → true  (MetaSync outputs, AIOSEO suppressed via filter)
     *   AIOSEO has value    → false (let AIOSEO handle it)
     *   Neither has value   → true  (fallback: legacy auto-generated description)
     *
     * When no third-party SEO plugin is active → always true.
     *
     * @return bool True if the legacy output should include a description tag.
     */
    public function should_output_legacy_description() {
        if (!$this->has_active_seo_plugin()) {
            return true;
        }

        // MetaSync has an intentional value — always output it
        if ($this->metasync_has_description()) {
            return true;
        }

        // Check if AIOSEO actually provides a description for this page.
        // If it does, suppress our legacy output to avoid duplicates.
        // If it doesn't, let our legacy auto-generated description through
        // so the page isn't left with zero descriptions.
        if ($this->is_aioseo_active() && $this->aioseo_provides_description()) {
            return false;
        }

        // For Yoast/RankMath: they always auto-generate a description,
        // so suppress our legacy output when they're active.
        if (is_plugin_active('wordpress-seo/wp-seo.php')) {
            return false;
        }
        if (is_plugin_active('seo-by-rank-math/rank-math.php') ||
            is_plugin_active('seo-by-rankmath/rank-math.php')) {
            return false;
        }

        // No third-party plugin will provide a description — output ours
        return true;
    }

    /**
     * Check whether AIOSEO will actually output a description for the current page.
     *
     * Uses the cached value captured in filter_aioseo_description() if available.
     * Falls back to calling AIOSEO's API directly if the filter hasn't fired yet.
     *
     * @return bool
     */
    private function aioseo_provides_description() {
        // Use cached value if available (set when our filter fires)
        if ($this->aioseo_has_description_cache !== null) {
            return $this->aioseo_has_description_cache;
        }

        // Filter hasn't fired yet — query AIOSEO directly
        if (function_exists('aioseo') && isset(aioseo()->meta->description)) {
            $desc = aioseo()->meta->description->getDescription();
            $this->aioseo_has_description_cache = !empty($desc);
            return $this->aioseo_has_description_cache;
        }

        // Can't determine — assume AIOSEO has one to avoid duplicates
        $this->aioseo_has_description_cache = true;
        return true;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Get the current queried object ID.
     *
     * Uses get_queried_object_id() as the universal fallback so every
     * public page type (singular, front page, static blog page, CPT
     * archives, WooCommerce shop, etc.) is covered without enumerating
     * each one individually.
     *
     * @return int 0 when unknown.
     */
    private function get_current_object_id() {
        // Singular pages (posts, pages, CPTs, attachments)
        if (is_singular()) {
            return (int) get_the_ID();
        }

        // WooCommerce shop page (virtual archive backed by a real page)
        if (function_exists('is_shop') && is_shop()) {
            return function_exists('wc_get_page_id') ? (int) wc_get_page_id('shop') : 0;
        }

        // Universal fallback: static front page, static posts page,
        // or any other page type WordPress assigns a queried object to.
        $id = get_queried_object_id();
        if ($id > 0) {
            return (int) $id;
        }

        return 0;
    }
}

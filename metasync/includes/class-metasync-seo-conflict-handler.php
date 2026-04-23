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

        // Ensure is_plugin_active() is available
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (is_plugin_active('wordpress-seo/wp-seo.php') ||
            is_plugin_active('wordpress-seo-premium/wp-seo-premium.php')) {
            $this->register_yoast_filters();
        }

        if (is_plugin_active('seo-by-rank-math/rank-math.php') || is_plugin_active('seo-by-rankmath/rank-math.php')) {
            $this->register_rankmath_filters();
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
            || is_plugin_active('wordpress-seo-premium/wp-seo-premium.php')
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

        if (!empty($this->get_metasync_description())) {
            $this->has_description_cache = true;
            return true;
        }

        // Term-level: on taxonomy archives MetaSync may have term meta
        // (`_metasync_metadesc`) set via MCP, OTTO, or the importer.
        $term = $this->get_current_term();
        if ($term) {
            $term_desc = get_term_meta($term->term_id, '_metasync_metadesc', true);
            if (!empty($term_desc)) {
                $this->has_description_cache = true;
                return true;
            }
        }

        $this->has_description_cache = false;
        return false;
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

        // Suppress AIOSEO robots when MetaSync has an intentional robots value
        add_filter('aioseo_robots_meta', [$this, 'filter_aioseo_robots'], 999);

        // Suppress AIOSEO schema/JSON-LD when OTTO has structured data
        add_filter('aioseo_schema_output', [$this, 'filter_aioseo_schema'], 999);
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

        // Term archives: AIOSEO free doesn't read per-term custom descriptions
        // from its `wp_aioseo_terms` table, so return the MetaSync value
        // directly so AIOSEO renders it.
        $term = $this->get_current_term();
        if ($term) {
            $term_desc = get_term_meta($term->term_id, '_metasync_metadesc', true);
            if (!empty($term_desc)) {
                return $term_desc;
            }
        }

        // Suppress when: OTTO active + has description, OR MetaSync sidebar has description
        if ($this->otto_has_tag('description') || $this->metasync_has_description()) {
            return '';
        }
        return $description;
    }

    /**
     * Filter AIOSEO title output.
     *
     * On term archives: AIOSEO free doesn't read custom per-term titles from
     * its `wp_aioseo_terms` table, so we replace AIOSEO's template-based title
     * with the MetaSync term title directly.
     *
     * @param  string $title AIOSEO's computed title.
     * @return string
     */
    public function filter_aioseo_title($title) {
        // Term archives: return MetaSync term title directly.
        $term = $this->get_current_term();
        if ($term) {
            $term_title = get_term_meta($term->term_id, '_metasync_metatitle', true);
            if (!empty($term_title)) {
                return $term_title;
            }
        }

        if ($this->should_suppress_third_party_title()) {
            return '';
        }

        return $title;
    }

    /**
     * Filter AIOSEO Facebook/OG tags.
     *
     * Per-tag suppression: only remove a tag when OTTO is active AND has
     * a persisted value for that specific tag, OR when MetaSync sidebar
     * provides the equivalent value.
     *
     * @param  array $meta AIOSEO's OG meta array.
     * @return array
     */
    public function filter_aioseo_facebook_tags($meta) {
        if (!is_array($meta)) {
            return $meta;
        }

        // og:title — suppress when OTTO has og:title OR MetaSync has title
        $post_id = $this->get_current_object_id();
        if ($this->otto_has_tag('og:title') || ($post_id && $this->metasync_has_title($post_id))) {
            unset($meta['og:title']);
        }

        // og:description — suppress when OTTO has og:description OR MetaSync has description
        if ($this->otto_has_tag('og:description') || $this->metasync_has_description()) {
            unset($meta['og:description']);
        }

        // og:url, og:type, og:locale, og:site_name — suppress when OTTO has og:title
        // (OTTO injects these structural OG tags alongside og:title in its block)
        if ($this->otto_has_tag('og:title')) {
            unset($meta['og:url'], $meta['og:type'], $meta['og:locale'], $meta['og:site_name']);
        }

        return $meta;
    }

    /**
     * Filter AIOSEO Twitter tags.
     *
     * Per-tag suppression: only remove a tag when OTTO is active AND has
     * a persisted value for that specific tag, OR when MetaSync sidebar
     * provides the equivalent value.
     *
     * @param  array $meta AIOSEO's Twitter meta array.
     * @return array
     */
    public function filter_aioseo_twitter_tags($meta) {
        if (!is_array($meta)) {
            return $meta;
        }

        $post_id = $this->get_current_object_id();

        if ($this->otto_has_tag('twitter:title') || ($post_id && $this->metasync_has_title($post_id))) {
            unset($meta['twitter:title']);
        }

        if ($this->otto_has_tag('twitter:description') || $this->metasync_has_description()) {
            unset($meta['twitter:description']);
        }

        // twitter:card — suppress when OTTO has any twitter tag
        if ($this->otto_has_tag('twitter:title') || $this->otto_has_tag('twitter:description')) {
            unset($meta['twitter:card']);
        }

        return $meta;
    }

    /**
     * Filter AIOSEO robots meta output.
     *
     * When MetaSync has an intentional robots value (admin checkbox or REST API),
     * suppress AIOSEO's robots tag to avoid duplicates. MetaSync's own output in
     * hook_metasync_metatags() will output the MetaSync value instead.
     *
     * AIOSEO passes an array like ['noindex' => 'noindex', 'nofollow' => 'nofollow'].
     * Returning an empty array suppresses AIOSEO's robots tag entirely.
     *
     * @param  array $robots AIOSEO's computed robots attributes array.
     * @return array
     */
    public function filter_aioseo_robots($robots) {
        $post_id = $this->get_current_object_id();
        if (!$post_id) {
            return $robots;
        }

        if ($this->metasync_has_robots($post_id)) {
            // MetaSync has robots — suppress AIOSEO's tag.
            return [];
        }

        return $robots;
    }

    /**
     * Filter AIOSEO schema/JSON-LD output.
     * Suppress when OTTO has structured data for the current page.
     * Also strip BreadcrumbList entries when MetaSync breadcrumbs are enabled,
     * so MetaSync's own BreadcrumbList is the only one on the page.
     *
     * @param  array $output AIOSEO's @graph array.
     * @return array
     */
    public function filter_aioseo_schema($output) {
        if ($this->otto_has_schema_for_current_page()) {
            return [];
        }

        if ($this->metasync_breadcrumb_enabled() && is_array($output)) {
            foreach ($output as $key => $entry) {
                if (is_array($entry) && isset($entry['@type']) && $entry['@type'] === 'BreadcrumbList') {
                    unset($output[$key]);
                }
            }
            $output = array_values($output);
        }

        return $output;
    }

    /**
     * Check whether MetaSync holds an intentional robots directive for a post.
     *
     * Checks both storage formats:
     *   - meta_robots              (string from REST API)
     *   - metasync_common_robots   (array from admin checkbox)
     *
     * @param  int $post_id Post ID.
     * @return bool
     */
    public function metasync_has_robots($post_id) {
        $meta_robots = get_post_meta($post_id, 'meta_robots', true);
        if (!empty($meta_robots)) {
            return true;
        }

        $common_robots = get_post_meta($post_id, 'metasync_common_robots', true);
        if (is_array($common_robots) && !empty(array_filter($common_robots))) {
            return true;
        }

        return false;
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
        if (!empty($otto_title)) {
            return true;
        }

        // Term-level fallback: on taxonomy archives the "object" is a term,
        // so read `_metasync_metatitle` from term meta when we're rendering one.
        $term = $this->get_current_term();
        if ($term) {
            $term_title = get_term_meta($term->term_id, '_metasync_metatitle', true);
            if (!empty($term_title)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a third-party SEO plugin's title should be suppressed.
     *
     * Suppress when either condition is met:
     *   1. OTTO is active AND has a persisted title for this page
     *   2. MetaSync sidebar has an explicit title for this page
     *
     * @return bool True if the third-party title should be suppressed.
     */
    private function should_suppress_third_party_title() {
        // Condition 1: OTTO active + has title for this page
        if ($this->otto_has_tag('title')) {
            return true;
        }

        // Condition 2: MetaSync sidebar has explicit title
        $post_id = $this->get_current_object_id();
        if ($post_id) {
            return $this->metasync_has_title($post_id);
        }

        return false;
    }

    /**
     * Check whether the OTTO pixel is active.
     *
     * @return bool
     */
    private function is_otto_active() {
        if (class_exists('Metasync_Otto_Config')) {
            return Metasync_Otto_Config::is_otto_enabled();
        }

        return false;
    }

    /**
     * Check whether OTTO has a persisted value for a specific meta tag.
     *
     * Two conditions must be true to suppress a third-party tag:
     *   1. OTTO is active (globally enabled)
     *   2. OTTO has a value for this specific tag on the current page
     *
     * For OG/Twitter tags where OTTO's pixel injects dynamically (the
     * specific _metasync_otto_og_* key may be empty), the buffer-level
     * dedup in Otto_html_class::deduplicate_og_twitter_tags() handles
     * removal after all sources have output. This method only does the
     * direct per-tag check.
     *
     * @param  string $tag Tag identifier (e.g. 'title', 'og:title', 'twitter:description').
     * @return bool True when OTTO is active AND has a persisted value for this tag.
     */
    private function otto_has_tag($tag) {
        if (!$this->is_otto_active()) {
            return false;
        }

        $post_id = $this->get_current_object_id();
        if (!$post_id) {
            return false;
        }

        $meta_key_map = [
            'title'                => '_metasync_otto_title',
            'description'          => '_metasync_otto_description',
            'og:title'             => '_metasync_otto_og_title',
            'og:description'       => '_metasync_otto_og_description',
            'twitter:title'        => '_metasync_otto_twitter_title',
            'twitter:description'  => '_metasync_otto_twitter_description',
        ];

        if (!isset($meta_key_map[$tag])) {
            return false;
        }

        return !empty(get_post_meta($post_id, $meta_key_map[$tag], true));
    }

    // ------------------------------------------------------------------
    // Yoast SEO integration
    // ------------------------------------------------------------------

    /**
     * Register Yoast SEO-specific filters to suppress its title,
     * description, and OG/Twitter output when MetaSync/OTTO provides them.
     */
    private function register_yoast_filters() {
        add_filter('wpseo_title', [$this, 'filter_yoast_title'], 999);
        add_filter('wpseo_metadesc', [$this, 'filter_yoast_description'], 999);

        // OG tags — per-tag suppression
        add_filter('wpseo_opengraph_title', [$this, 'filter_yoast_og_title'], 999);
        add_filter('wpseo_opengraph_desc', [$this, 'filter_yoast_og_description'], 999);
        add_filter('wpseo_opengraph_url', [$this, 'filter_yoast_og_structural'], 999);
        add_filter('wpseo_opengraph_type', [$this, 'filter_yoast_og_structural'], 999);
        add_filter('wpseo_opengraph_site_name', [$this, 'filter_yoast_og_structural'], 999);
        add_filter('wpseo_og_locale', [$this, 'filter_yoast_og_structural'], 999);
        add_filter('wpseo_opengraph_image', [$this, 'filter_yoast_og_structural'], 999);

        // Twitter tags — per-tag suppression
        add_filter('wpseo_twitter_title', [$this, 'filter_yoast_twitter_title'], 999);
        add_filter('wpseo_twitter_description', [$this, 'filter_yoast_twitter_description'], 999);
        add_filter('wpseo_twitter_image', [$this, 'filter_yoast_twitter_structural'], 999);
        add_filter('wpseo_twitter_card_type', [$this, 'filter_yoast_twitter_structural'], 999);

        // Suppress Yoast schema/JSON-LD when OTTO has structured data
        add_filter('wpseo_schema_graph', [$this, 'filter_yoast_schema'], 999);
    }

    /**
     * Filter Yoast SEO title output.
     *
     * When the MetaSync sidebar has an explicit SEO title, return that title so
     * Yoast's Title_Presenter renders it inside the <title> tag it controls.
     * Returning '' would cause Title_Presenter to emit NO <title> tag at all,
     * because Yoast has already removed WordPress's native _wp_render_title_tag
     * action and is the sole renderer of the title element.
     *
     * When only OTTO has a title (no sidebar override), we let Yoast output its
     * own title normally — OTTO's buffer post-processing replaces it in the final
     * HTML. Returning '' here would again leave the page with no <title> tag.
     */
    public function filter_yoast_title($title) {
        // Term-level archives (category/tag/custom taxonomy): when MetaSync has
        // an explicit `_metasync_metatitle`, return it so Yoast renders the
        // MetaSync-managed archive title inside <title>. This mirrors the
        // post-level sidebar-title precedence below.
        $term = $this->get_current_term();
        if ($term) {
            $term_title = get_term_meta($term->term_id, '_metasync_metatitle', true);
            if (!empty($term_title)) {
                return $term_title;
            }
        }

        $post_id = $this->get_current_object_id();

        // Case 1: MetaSync sidebar has an explicit title — return it so Yoast
        // renders it inside <title>. The sidebar's pre_get_document_title filter
        // (priority 100) already handles this for block themes; this wpseo_title
        // filter covers classic-theme paths where Yoast builds the title differently.
        if ($post_id) {
            $sidebar_title = get_post_meta($post_id, '_metasync_seo_title', true);
            if (!empty($sidebar_title)) {
                return $sidebar_title;
            }
        }

        // Case 2: OTTO has a persisted title — do NOT suppress Yoast here.
        // OTTO's output-buffer post-processing (Otto_html_class) replaces the
        // <title> tag in the final HTML after WordPress renders. Returning '' would
        // remove the <title> tag entirely before OTTO can inject its replacement.

        return $title;
    }

    /**
     * Filter Yoast SEO description output.
     *
     * On term archives: the term-level sync writes MetaSync's description
     * into Yoast's native storage, so Yoast already computes the correct
     * value — let it through.
     *
     * On singular pages: suppress when OTTO or MetaSync sidebar provides
     * the description (MetaSync outputs its own tag).
     */
    public function filter_yoast_description($description) {
        // Term archives: MetaSync syncs to Yoast storage — let Yoast render it.
        $term = $this->get_current_term();
        if ($term) {
            $term_desc = get_term_meta($term->term_id, '_metasync_metadesc', true);
            if (!empty($term_desc)) {
                return $description;
            }
        }

        if ($this->otto_has_tag('description') || $this->metasync_has_description()) {
            return '';
        }
        return $description;
    }

    /**
     * Filter Yoast og:title output.
     * Suppress when: OTTO active + has og:title, OR MetaSync has title.
     */
    public function filter_yoast_og_title($value) {
        $post_id = $this->get_current_object_id();
        if ($this->otto_has_tag('og:title') || ($post_id && $this->metasync_has_title($post_id))) {
            return '';
        }
        return $value;
    }

    /**
     * Filter Yoast og:description output.
     * Suppress when: OTTO active + has og:description, OR MetaSync has description.
     */
    public function filter_yoast_og_description($value) {
        if ($this->otto_has_tag('og:description') || $this->metasync_has_description()) {
            return '';
        }
        return $value;
    }

    /**
     * Filter Yoast OG structural tags (og:url, og:type, og:locale, og:site_name, og:image).
     * Suppress when: OTTO active + has og:title (OTTO provides these alongside og:title).
     */
    public function filter_yoast_og_structural($value) {
        if ($this->otto_has_tag('og:title')) {
            return '';
        }
        return $value;
    }

    /**
     * Filter Yoast twitter:title output.
     * Suppress when: OTTO active + has twitter:title, OR MetaSync has title.
     */
    public function filter_yoast_twitter_title($value) {
        $post_id = $this->get_current_object_id();
        if ($this->otto_has_tag('twitter:title') || ($post_id && $this->metasync_has_title($post_id))) {
            return '';
        }
        return $value;
    }

    /**
     * Filter Yoast twitter:description output.
     * Suppress when: OTTO active + has twitter:description, OR MetaSync has description.
     */
    public function filter_yoast_twitter_description($value) {
        if ($this->otto_has_tag('twitter:description') || $this->metasync_has_description()) {
            return '';
        }
        return $value;
    }

    /**
     * Filter Yoast Twitter structural tags (twitter:image, twitter:card).
     * Suppress when: OTTO active + has any twitter tag.
     */
    public function filter_yoast_twitter_structural($value) {
        if ($this->otto_has_tag('twitter:title') || $this->otto_has_tag('twitter:description')) {
            return '';
        }
        return $value;
    }

    /**
     * Filter Yoast SEO schema/JSON-LD output.
     * Suppress when OTTO has structured data for the current page.
     * Also strip BreadcrumbList entries when MetaSync breadcrumbs are enabled,
     * so MetaSync's own BreadcrumbList is the only one on the page.
     *
     * @param  array|false $data Yoast's JSON-LD data.
     * @return array|false
     */
    public function filter_yoast_schema($data) {
        if ($this->otto_has_schema_for_current_page()) {
            return false;
        }

        if ($this->metasync_breadcrumb_enabled() && is_array($data)) {
            foreach ($data as $key => $entry) {
                if (is_array($entry) && isset($entry['@type']) && $entry['@type'] === 'BreadcrumbList') {
                    unset($data[$key]);
                }
            }
            $data = array_values($data);
        }

        return $data;
    }

    // ------------------------------------------------------------------
    // RankMath integration
    // ------------------------------------------------------------------

    /**
     * Register RankMath-specific filters to suppress its title and
     * description output when MetaSync/OTTO already provides them.
     */
    private function register_rankmath_filters() {
        add_filter('rank_math/frontend/title', [$this, 'filter_rankmath_title'], 999);
        add_filter('rank_math/frontend/description', [$this, 'filter_rankmath_description'], 999);

        // Suppress RankMath schema/JSON-LD when OTTO has structured data
        add_filter('rank_math/json_ld', [$this, 'filter_rankmath_schema'], 999);
    }

    /**
     * Filter RankMath title output.
     *
     * On taxonomy archive pages: when MetaSync has an explicit `_metasync_metatitle`
     * term meta value, return it so Rank Math renders the MetaSync-managed archive
     * title inside <title>. This mirrors the Yoast term-level title override in
     * filter_yoast_title().
     *
     * On singular pages: return empty string when MetaSync/OTTO has a title (Rank
     * Math controls the sole <title> renderer on classic themes, so OTTO's buffer
     * post-processing will replace it — returning '' would leave no <title> at all).
     *
     * @param  string $title RankMath's computed title.
     * @return string
     */
    public function filter_rankmath_title($title) {
        // Term-level archives (category/tag/custom taxonomy): when MetaSync has
        // an explicit `_metasync_metatitle`, return it so Rank Math renders the
        // MetaSync-managed archive title inside <title>.
        $term = $this->get_current_term();
        if ($term) {
            $term_title = get_term_meta($term->term_id, '_metasync_metatitle', true);
            if (!empty($term_title)) {
                return $term_title;
            }
        }

        $post_id = $this->get_current_object_id();

        // MetaSync sidebar has an explicit title — return it so Rank Math renders it.
        if ($post_id) {
            $sidebar_title = get_post_meta($post_id, '_metasync_seo_title', true);
            if (!empty($sidebar_title)) {
                return $sidebar_title;
            }
        }

        // OTTO has a persisted title — do NOT suppress Rank Math here.
        // OTTO's output-buffer post-processing replaces the <title> tag in the
        // final HTML. Returning '' would remove the tag before OTTO can inject.

        return $title;
    }

    /**
     * Filter RankMath description output.
     *
     * On term archives: the term-level sync writes MetaSync's description
     * into Rank Math's native term meta, so Rank Math already computes the
     * correct value — let it through.
     *
     * On singular pages: suppress when OTTO or MetaSync sidebar provides
     * the description.
     *
     * @param  string $description RankMath's computed description.
     * @return string
     */
    public function filter_rankmath_description($description) {
        // Term archives: MetaSync syncs to Rank Math storage — let it render.
        $term = $this->get_current_term();
        if ($term) {
            $term_desc = get_term_meta($term->term_id, '_metasync_metadesc', true);
            if (!empty($term_desc)) {
                return $description;
            }
        }

        if ($this->otto_has_tag('description') || $this->metasync_has_description()) {
            return '';
        }

        return $description;
    }

    /**
     * Filter RankMath schema/JSON-LD output.
     * Suppress when OTTO has structured data for the current page.
     * Also strip BreadcrumbList entries when MetaSync breadcrumbs are enabled,
     * so MetaSync's own BreadcrumbList is the only one on the page.
     *
     * @param  array $data RankMath's JSON-LD data array.
     * @return array
     */
    public function filter_rankmath_schema($data) {
        if ($this->otto_has_schema_for_current_page()) {
            return [];
        }

        if ($this->metasync_breadcrumb_enabled() && is_array($data)) {
            foreach ($data as $key => $entry) {
                if (is_array($entry) && isset($entry['@type']) && $entry['@type'] === 'BreadcrumbList') {
                    unset($data[$key]);
                }
            }
            $data = array_values($data);
        }

        return $data;
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
        // OTTO active + has description → suppress legacy auto-generated description.
        if ($this->otto_has_tag('description')) {
            return false;
        }

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
     * Determine whether MetaSync's own BreadcrumbList output is enabled.
     *
     * Mirrors the gate logic in Metasync_Breadcrumbs_Schema::output_breadcrumb_schema():
     * enabled by default, disabled only when the `enabled` setting is explicitly falsy.
     * Used by the Yoast / RankMath / AIOSEO schema filters so we only strip their
     * BreadcrumbList entries when MetaSync will emit one itself.
     *
     * @return bool
     */
    private function metasync_breadcrumb_enabled() {
        $settings = Metasync::get_option('breadcrumbs', array());
        if (!is_array($settings)) {
            return true;
        }

        if (array_key_exists('enabled', $settings) && empty($settings['enabled'])) {
            return false;
        }

        return true;
    }

    /**
     * Check whether OTTO has structured data (schema/JSON-LD) for the current page.
     *
     * @return bool
     */
    private function otto_has_schema_for_current_page() {
        if (!$this->is_otto_active()) {
            return false;
        }

        $post_id = $this->get_current_object_id();
        if (!$post_id) {
            return false;
        }

        return !empty(get_post_meta($post_id, '_metasync_otto_structured_data', true));
    }

    /**
     * Get the current queried object ID.
     *
     * Uses get_queried_object_id() as the universal fallback so every
     * public page type (singular, front page, static blog page, CPT
     * archives, WooCommerce shop, etc.) is covered without enumerating
     * each one individually.
     *
     * For blog-style homepages (show_on_front=posts) there is no backing
     * page, so this returns 0.
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

    /**
     * Return the WP_Term being rendered on taxonomy archive pages.
     *
     * Only returns a term when the current query is a category, tag, or
     * custom taxonomy archive — i.e. when MetaSync term meta could be
     * driving the rendered output. Returns null in every other context
     * (singular, blog home, search, 404, etc.) so callers don't have to
     * double-check the page type.
     *
     * @return \WP_Term|null
     */
    private function get_current_term() {
        if (!(is_category() || is_tag() || is_tax())) {
            return null;
        }

        $queried = get_queried_object();
        if ($queried instanceof \WP_Term) {
            return $queried;
        }

        return null;
    }
}

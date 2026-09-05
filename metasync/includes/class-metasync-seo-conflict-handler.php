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
     * Resolved MetaSync title per post id, for this request only.
     *
     * @var array<int,string>
     */
    private $title_value_cache = [];

    /**
     * Cached result for whether AIOSEO provides a description for the current page.
     *
     * @var bool|null
     */
    private $aioseo_has_description_cache = null;

    /**
     * Cached per-plugin robots-suppression decisions for the current
     * request (the Rank Math main + advanced robots filters both invoke
     * the check during wp_head).
     *
     * @var array<string, bool>
     */
    private $robots_suppress_cache = [];

    /**
     * Cached result: whether the current post has been synced via.
     *
     * @var array Keyed by post_id => bool
     */
    private $sync_cache = [];

    /**
     * Cached result for whether OTTO has live transient-cached suggestions
     * for the current request URL.
     *
     * @var bool|null
     */
    private $live_suggestions_cache = null;

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

    /**
     * Whether an active third-party SEO plugin actually holds a
     * non-empty meta description for this post in its OWN storage.
     *
     * The native-first output guards previously stood down whenever a
     * sync timestamp (_metasync_plugin_sync_ts) existed, assuming the plugin
     * would render the description. But a stale or partial sync leaves the
     * plugin's field empty — MetaSync suppresses its own tag, the plugin has
     * nothing to emit, and the description is dropped entirely. Callers use this
     * to only defer when the plugin can genuinely output a description.
     *
     * @param int $post_id Post ID.
     * @return bool True if the active/primary SEO plugin has a description.
     */
    public function active_plugin_has_description($post_id) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return false;
        }

        $this->ensure_plugin_api();

        // Yoast (free or premium)
        if (is_plugin_active('wordpress-seo/wp-seo.php') || is_plugin_active('wordpress-seo-premium/wp-seo-premium.php')) {
            if (!empty(get_post_meta($post_id, '_yoast_wpseo_metadesc', true))) {
                return true;
            }
        }

        // Rank Math
        if (is_plugin_active('seo-by-rank-math/rank-math.php') || is_plugin_active('seo-by-rankmath/rank-math.php')) {
            if (!empty(get_post_meta($post_id, 'rank_math_description', true))) {
                return true;
            }
        }

        // AIOSEO stores its description in a custom table, not post meta.
        if ($this->is_aioseo_active()) {
            global $wpdb;
            // Defensive: $wpdb is always present on a booted frontend, but never
            // assume — a method call on a null/!object $wpdb would be fatal.
            if (isset($wpdb) && is_object($wpdb)) {
                $table = $wpdb->prefix . 'aioseo_posts';
                $desc = $wpdb->get_var($wpdb->prepare("SELECT description FROM {$table} WHERE post_id = %d", $post_id));
                if (!empty($desc)) {
                    return true;
                }
            }
        }

        return false;
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
            $term_desc = $this->get_metasync_term_description($term);
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

        // The full chain, from Metasync_Seo_Precedence. It has to be the same
        // chain the description filters hand to a third-party plugin: if this
        // reported a narrower set, MetaSync's own emitter would think it held
        // nothing, emit anyway, and the page would carry two identical
        // description tags.
        return $this->metasync_description_value($post_id);
    }

    /**
     * Return MetaSync's intentional SEO title for the current request.
     *
     * Mirrors get_metasync_description(): only values that were explicitly set
     * (sidebar or OTTO), never an auto-generated fallback such as the post title.
     * This is the value the og:title / twitter:title suppression keys off, so the
     * sidebar emitter can render the same title back as a replacement.
     *
     * @return string
     */
    public function get_metasync_title() {
        $post_id = $this->get_current_object_id();

        if (!$post_id) {
            return '';
        }

        // Post-scoped values only. On an archive $post_id is a term id and every
        // read below would answer for an unrelated post. get_metasync_description()
        // gates the same way through metasync_description_value().
        if (!$this->current_object_is_post()) {
            return '';
        }

        // 1. Social Media & Open Graph meta box (most specific — a social-only title)
        $og_title = $this->get_customized_og_title($post_id);
        if ($og_title !== '') {
            return $og_title;
        }

        // 2. SEO sidebar (user-edited)
        $title = get_post_meta($post_id, '_metasync_seo_title', true);
        if (!empty($title)) {
            return $title;
        }

        // 3. OTTO title
        $title = get_post_meta($post_id, '_metasync_otto_title', true);
        if (!empty($title)) {
            return $title;
        }

        return '';
    }

    /**
     * The per-post OG meta box title, but only when the user genuinely set it.
     *
     * The meta box pre-fills its Title from the post title and PERSISTS that
     * default on save, so a non-empty `_metasync_og_title` alone does not prove
     * intent — treating it as one would let an auto-filled post title override a
     * deliberately-set SEO title on every ordinary edit. A value counts as the
     * user's only when it differs from that default, the same comparison
     * Otto_html_class::apply_metabox_og_precedence() makes.
     *
     * @param  int $post_id
     * @return string The customized OG title, or '' when unset or auto-filled.
     */
    private function get_customized_og_title($post_id) {
        // Per-post OG meta box values only exist on singular views. On archives
        // get_current_object_id() returns a TERM id, and reading post meta with it
        // would consult an unrelated post — suppressing the third-party tag on a
        // page where the singular-only replacement emitter never runs.
        if (!is_singular()) {
            return '';
        }

        $og_title = (string) get_post_meta($post_id, '_metasync_og_title', true);
        if ($og_title === '') {
            return '';
        }

        $post    = get_post($post_id);
        $default = ($post instanceof WP_Post) ? (string) $post->post_title : '';

        return $og_title === $default ? '' : $og_title;
    }

    /**
     * Whether this request suppressed a third-party plugin's og:title /
     * twitter:title and therefore owes a replacement tag.
     *
     * Only true in the narrow case where the suppression leaves a gap:
     *   - Yoast or AIOSEO is active. Those are the only plugins whose og:title
     *     we filter; Rank Math / SEOPress / TSF render their own untouched, and
     *     on a MetaSync-only site Metasync_OpenGraph::output_opengraph_tags()
     *     still emits og:title itself — emitting here would duplicate it.
     *   - That plugin is not the primary output owner for the post (a synced
     *     post renders the plugin's own tags, so we must not double up).
     *   - The per-post OG toggle is not explicitly off (toggle off suppresses
     *     nothing, so nothing is owed).
     *   - MetaSync has an intentional title — the value that triggered the
     *     suppression in filter_yoast_og_title() / filter_aioseo_facebook_tags().
     *
     * Pages where OTTO owns og:title are deliberately excluded: OTTO injects its
     * own tag through the output buffer, so there is no gap to fill.
     *
     * @return bool
     */
    public function og_title_needs_replacement() {
        if (!$this->suppresses_third_party_og_title()) {
            return false;
        }

        // Nothing to replace once the social filters are writing MetaSync's own
        // og:title into the plugin's tag — emitting here too would duplicate it
        // rather than fill a gap. This is only reached for a post that holds a
        // page title and no OG-specific one.
        $post_id_for_og = $this->get_current_object_id();
        if ($post_id_for_og
            && $this->metasync_social_value(Metasync_Seo_Precedence::FIELD_OG_TITLE, $post_id_for_og) !== '') {
            return false;
        }

        $post_id = $this->get_current_object_id();
        if (!$post_id) {
            return false;
        }

        if ($this->og_output_disabled($post_id)) {
            return false;
        }

        foreach (['yoast', 'rankmath', 'aioseo'] as $slug) {
            if ($this->is_primary_output_plugin($post_id, $slug)) {
                return false;
            }
        }

        return $this->metasync_has_title($post_id);
    }

    /**
     * Whether an active third-party plugin is one whose og:title we filter
     * (Yoast or AIOSEO). Rank Math, SEOPress and The SEO Framework emit their
     * own og:title untouched, so they never leave a gap to fill.
     *
     * @return bool
     */
    private function suppresses_third_party_og_title() {
        $this->ensure_plugin_api();

        return is_plugin_active('wordpress-seo/wp-seo.php')
            || is_plugin_active('wordpress-seo-premium/wp-seo-premium.php')
            || $this->is_aioseo_active();
    }

    /**
     * Whether an active third-party SEO plugin renders the og:description /
     * twitter:description for this request, so MetaSync must not add its own.
     *
     * Mirrors the condition the suppression filters use: when that plugin is the
     * primary output owner, filter_yoast_og_description() (and the AIOSEO/Rank
     * Math equivalents) pass its tag through untouched, so a second tag from the
     * sidebar emitter would duplicate it.
     *
     * Deliberately does NOT ask whether the plugin's OG description field is
     * populated. Yoast falls back to its meta description, and then to the
     * excerpt, so it emits an og:description either way — an "is the field set"
     * test would miss those fallbacks and let the duplicate through.
     *
     * @return bool
     */
    public function third_party_owns_og_description() {
        if (!$this->has_active_seo_plugin()) {
            return false;
        }

        // Per-post ownership only means anything on singular views. On archives
        // get_current_object_id() returns a TERM id, and the sync timestamp would
        // be read from an unrelated post of the same numeric id.
        if (!is_singular()) {
            return false;
        }

        $post_id = $this->get_current_object_id();
        if (!$post_id) {
            return false;
        }

        foreach (['yoast', 'rankmath', 'aioseo'] as $slug) {
            if ($this->is_primary_output_plugin($post_id, $slug)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the MetaSync-managed canonical URL for a post, if any.
     *
     * Priority: OTTO persisted canonical (_metasync_canonical_url) → Canonical meta
     * box value (meta_canonical). Restricted to singular views so term/archive
     * queried-object ids are never misread as post ids. Mirrors the fallback in
     * Metasync_Seo_Output::get_canonical_url() so both the no-SEO-plugin path and
     * the third-party-plugin path honor the same value.
     *
     * @param int $post_id Current object id.
     * @return string Escaped canonical URL, or '' when none is set.
     */
    private function get_metasync_canonical($post_id) {
        if (!$post_id || !is_singular()) {
            return '';
        }

        // Canonical feature switched off. Returning empty makes every canonical
        // filter fall back to the third-party plugin's own value rather than
        // overriding it with a MetaSync canonical the user has disabled.
        if (Metasync_Feature_Flags::is_disabled(Metasync_Feature_Flags::CANONICAL)) {
            return '';
        }

        // Validate both sources: legacy rows corrupted to the literal "Array"
        // (or stored as arrays) must never be emitted as a canonical.
        $canonical = Metasync_Canonical_Sanitizer::sanitize(
            get_post_meta($post_id, '_metasync_canonical_url', true)
        );
        if ($canonical === '') {
            $canonical = Metasync_Canonical_Sanitizer::sanitize(
                get_post_meta($post_id, 'meta_canonical', true)
            );
        }

        return $canonical !== '' ? esc_url($canonical) : '';
    }

    /**
     * Reset the cached description flag (useful when the queried object changes).
     */
    public function reset_cache() {
        $this->has_description_cache = null;
        $this->aioseo_has_description_cache = null;
        $this->sync_cache = [];
        $this->robots_suppress_cache = [];
        $this->live_suggestions_cache = null;
    }

    /**
     * Check whether a post has been synced to third-party plugins via.
     *
     * When native-first sync is active, each plugin reads MetaSync values from
     * its own storage — no filter suppression needed. This method returns true
     * when _metasync_plugin_sync_ts exists and contains a timestamp for the
     * given plugin slug.
     *
     * @param int    $post_id     Post ID.
     * @param string $plugin_slug Plugin slug: 'yoast', 'rankmath', or 'aioseo'.
     * @return bool True if the post has been synced to this plugin.
     */
    private function is_post_synced($post_id, $plugin_slug) {
        if ($post_id <= 0) {
            return false;
        }

        if (!isset($this->sync_cache[$post_id])) {
            $ts_raw = get_post_meta($post_id, '_metasync_plugin_sync_ts', true);
            $this->sync_cache[$post_id] = !empty($ts_raw) ? json_decode($ts_raw, true) : [];
            if (!is_array($this->sync_cache[$post_id])) {
                $this->sync_cache[$post_id] = [];
            }
        }

        return !empty($this->sync_cache[$post_id][$plugin_slug]);
    }

    /**
     * For synced posts with multiple SEO plugins active, determine if a
     * specific plugin is the designated output owner.
     *
     * Only the first active plugin in priority order (Yoast > Rank Math > AIOSEO)
     * is allowed to output — the others are suppressed to prevent duplicate tags.
     *
     * @param int    $post_id     Post ID.
     * @param string $plugin_slug Plugin slug to check.
     * @return bool True if this plugin should output its tags.
     */
    private function is_primary_output_plugin($post_id, $plugin_slug) {
        // For synced posts: only the primary synced plugin passes through.
        // For unsynced posts with multiple plugins: only the highest-priority
        // active plugin outputs to prevent duplicate tags.
        $this->ensure_plugin_api();
        $priority = ['yoast', 'rankmath', 'aioseo'];
        $active_check = [
            'yoast'    => is_plugin_active('wordpress-seo/wp-seo.php') || is_plugin_active('wordpress-seo-premium/wp-seo-premium.php'),
            'rankmath' => is_plugin_active('seo-by-rank-math/rank-math.php') || is_plugin_active('seo-by-rankmath/rank-math.php'),
            'aioseo'   => is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php') || is_plugin_active('all-in-one-seo-pack-pro/all_in_one_seo_pack.php'),
        ];

        // The sync stamp is post meta. On an archive $post_id is a term id, so
        // consulting it would adopt an unrelated post's sync state and hand this
        // archive's output to the wrong plugin. Archives fall through to the
        // unsynced branch below, which is what they are.
        $has_any_sync = $post_id > 0
            && $this->current_object_is_post()
            && ($this->is_post_synced($post_id, 'yoast') || $this->is_post_synced($post_id, 'rankmath') || $this->is_post_synced($post_id, 'aioseo'));

        if ($has_any_sync) {
            // Synced: primary = first active + synced plugin
            foreach ($priority as $slug) {
                if ($active_check[$slug] && $this->is_post_synced($post_id, $slug)) {
                    return $slug === $plugin_slug;
                }
            }
            return false;
        }

        // Unsynced / multiple plugins active: pick the first active plugin
        // as the sole outputter to prevent duplicate tags.
        $active_count = count(array_filter($active_check));
        if ($active_count > 1) {
            foreach ($priority as $slug) {
                if ($active_check[$slug]) {
                    return $slug === $plugin_slug;
                }
            }
        }

        // Single plugin active or no plugins — don't interfere
        return false;
    }

    /**
     * Ensure is_plugin_active() is loaded on the frontend.
     */
    private function ensure_plugin_api() {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
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

        // Canonical — override AIOSEO's canonical with the MetaSync/OTTO value when set
        add_filter('aioseo_canonical_url', [$this, 'filter_aioseo_canonical'], 999);
    }

    /**
     * Filter AIOSEO's canonical URL.
     *
     * Mirrors filter_yoast_canonical(): return the MetaSync-managed canonical
     * (OTTO or the Canonical meta box) when set, else pass AIOSEO's through.
     */
    public function filter_aioseo_canonical($canonical) {
        $custom = $this->get_metasync_canonical($this->get_current_object_id());
        return $custom !== '' ? $custom : $canonical;
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

        $post_id = $this->get_current_object_id();

        // If we hold a description, we write it. The sync stamp records that a
        // sync happened; it does not decide who renders. The mirrored copy lives
        // in the plugin's own storage and can be edited or overwritten there —
        // deferring to it hands our tag over with nothing saying so.
        $ours = $this->metasync_description_value($post_id);
        if ($ours !== '') {
            return $ours;
        }

        // Primary plugin check — only the designated plugin outputs.
        if ($post_id && $this->has_active_seo_plugin()) {
            if ($this->is_primary_output_plugin($post_id, 'aioseo')) {
                return $description;
            }
            if ($this->is_primary_output_plugin($post_id, 'yoast') || $this->is_primary_output_plugin($post_id, 'rankmath')) {
                return '';
            }
        }

        // Term archives: AIOSEO free doesn't read per-term custom descriptions
        // from its `wp_aioseo_terms` table, so return the MetaSync value
        // directly so AIOSEO renders it.
        $term = $this->get_current_term();
        if ($term) {
            $term_desc = $this->get_metasync_term_description($term);
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
        // Primary plugin check — only the designated plugin outputs.
        $post_id = $this->get_current_object_id();

        // If we hold a title, we write it — the same rule the description path
        // follows. The sync stamp records that a sync happened; it does not decide
        // who renders. The mirrored copy lives in the plugin's own storage and can
        // be edited there, and deferring to it hands our tag over silently.
        //
        // A value, never '': on classic themes the plugin owns the sole <title>
        // renderer and OTTO's buffer replaces the tag afterwards, so blanking it
        // would leave nothing for OTTO to replace.
        $ours = $this->metasync_title_value($post_id);
        if ($ours !== '') {
            return $ours;
        }

        // Taxonomy archives resolve against term meta, not post meta. Resolve
        // the term before plugin ownership checks so a multi-plugin archive is
        // not suppressed by an unrelated primary-plugin decision.
        $term = $this->get_current_term();
        if ($term) {
            $term_title = $this->get_metasync_term_title($term);
            if (!empty($term_title)) {
                return $term_title;
            }
        }

        if ($post_id && $this->has_active_seo_plugin()) {
            if ($this->is_primary_output_plugin($post_id, 'aioseo')) {
                return $title;
            }
            if ($this->is_primary_output_plugin($post_id, 'yoast') || $this->is_primary_output_plugin($post_id, 'rankmath')) {
                return '';
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

        $post_id = $this->get_current_object_id();

        // Per-post OG toggle off — MetaSync emits no OG, so leave AIOSEO's tags intact.
        if ($this->og_output_disabled($post_id)) {
            return $meta;
        }

        // og:title — ours when we hold one, otherwise drop it only if OTTO is
        // injecting its own through the buffer. The sync stamp no longer decides
        // this: AIOSEO's own copy can be edited there and would silently win.
        $ours = $this->metasync_social_value(Metasync_Seo_Precedence::FIELD_OG_TITLE, $post_id);
        if ($ours !== '') {
            $meta['og:title'] = $ours;
        } elseif ($this->otto_has_tag('og:title')) {
            unset($meta['og:title']);
        }

        // og:description — same rule.
        $ours = $this->metasync_social_value(Metasync_Seo_Precedence::FIELD_OG_DESCRIPTION, $post_id);
        if ($ours !== '') {
            $meta['og:description'] = $ours;
        } elseif ($this->otto_has_tag('og:description')) {
            unset($meta['og:description']);
        }

        // og:url, og:type, og:locale, og:site_name — suppress when OTTO has og:title
        // (OTTO injects these structural OG tags alongside og:title in its block).
        //
        // Still skipped for a post synced to AIOSEO. Only the two tags above stopped
        // deferring to the sync stamp; these structural tags carry no MetaSync value
        // to put in their place, so changing when they are dropped is not this
        // change's business.
        if (!($post_id && $this->is_primary_output_plugin($post_id, 'aioseo'))
            && $this->otto_has_tag('og:title')) {
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

        // Per-post OG toggle off — MetaSync emits no Twitter tags, so leave AIOSEO's intact.
        if ($this->og_output_disabled($post_id)) {
            return $meta;
        }

        // See filter_aioseo_facebook_tags() for why the sync stamp no longer
        // decides who renders these.
        $ours = $this->metasync_social_value(Metasync_Seo_Precedence::FIELD_TWITTER_TITLE, $post_id);
        if ($ours !== '') {
            $meta['twitter:title'] = $ours;
        } elseif ($this->otto_has_tag('twitter:title')) {
            unset($meta['twitter:title']);
        }

        $ours = $this->metasync_social_value(Metasync_Seo_Precedence::FIELD_TWITTER_DESCRIPTION, $post_id);
        if ($ours !== '') {
            $meta['twitter:description'] = $ours;
        } elseif ($this->otto_has_tag('twitter:description')) {
            unset($meta['twitter:description']);
        }

        // twitter:card — suppress when OTTO has any twitter tag. See the
        // structural block in filter_aioseo_facebook_tags() for why this one
        // still defers to the sync stamp.
        if (!($post_id && $this->is_primary_output_plugin($post_id, 'aioseo'))
            && ($this->otto_has_tag('twitter:title') || $this->otto_has_tag('twitter:description'))) {
            unset($meta['twitter:card']);
        }

        return $meta;
    }

    /**
     * Shared suppression decision for a third-party plugin's robots meta tag.
     *
     * Both the AIOSEO and Rank Math robots filters consult this so their
     * behaviour stays identical by construction: MetaSync holds an
     * intentional robots value for the current post, the post is not
     * synced to that plugin as its primary output owner, and the current
     * view is one where MetaSync actually emits its own robots tag.
     *
     * The singular guard matters on taxonomy archives: there
     * get_current_object_id() returns a TERM id, and reading post meta
     * with it would consult an unrelated post — suppressing the
     * third-party tag on a page where MetaSync's replacement emitter
     * never runs (hook_metasync_metatags() returns early off-singular),
     * leaving the archive with no robots meta at all.
     *
     * @param  string $plugin_slug Plugin slug ('aioseo' or 'rankmath').
     * @return bool True when the third-party robots tag should be removed.
     */
    private function should_suppress_third_party_robots($plugin_slug) {
        if (!isset($this->robots_suppress_cache[$plugin_slug])) {
            $this->robots_suppress_cache[$plugin_slug] = $this->compute_should_suppress_third_party_robots($plugin_slug);
        }
        return $this->robots_suppress_cache[$plugin_slug];
    }

    /**
     * Uncached suppression decision; see should_suppress_third_party_robots().
     *
     * @param  string $plugin_slug Plugin slug ('aioseo' or 'rankmath').
     * @return bool True when the third-party robots tag should be removed.
     */
    private function compute_should_suppress_third_party_robots($plugin_slug) {
        $post_id = $this->get_current_object_id();
        if (!$post_id || !is_singular()) {
            return false;
        }

        // Post synced to this plugin — let it read from its own storage.
        if ($this->is_primary_output_plugin($post_id, $plugin_slug)) {
            return false;
        }

        return $this->metasync_has_robots($post_id);
    }

    /**
     * Filter AIOSEO robots meta output.
     *
     * When MetaSync has an intentional robots value (admin checkbox or REST API),
     * suppress AIOSEO's robots tag to avoid duplicates. MetaSync's own output in
     * hook_metasync_metatags() will output the MetaSync value instead.
     *
     * AIOSEO passes an array like ['noindex' => 'noindex', 'nofollow' => 'nofollow'].
     * Returning an empty array suppresses AIOSEO's robots tag entirely. Unlike
     * the Rank Math path this keeps no noindex floor on non-public sites —
     * AIOSEO's array shape for that case is not yet verified, so the floor is
     * deliberately Rank Math-only until it can be confirmed against AIOSEO.
     *
     * @param  array $robots AIOSEO's computed robots attributes array.
     * @return array
     */
    public function filter_aioseo_robots($robots) {
        if ($this->should_suppress_third_party_robots('aioseo')) {
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
            $output = $this->strip_breadcrumb_from_graph($output);
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
        // Both robots features switched off: MetaSync has no robots value to
        // offer, so it must not claim ownership of the tag. Reporting false here
        // also stops the third-party robots suppression filters, leaving the
        // other plugin's directives in place instead of blanking them.
        if (Metasync_Feature_Flags::robots_fully_disabled()) {
            return false;
        }

        // Both storage formats below hold common-half directives (noindex,
        // nofollow, noarchive, nosnippet, noimageindex). With that half switched
        // off the emitter drops every one of them, so claiming the tag here
        // would suppress the third party's robots tag and then emit nothing —
        // leaving a page that asked to be noindex fully indexable. The advanced
        // half being on does not help: it owns only the max-* directives, which
        // neither of these keys carries.
        if (Metasync_Feature_Flags::is_disabled(Metasync_Feature_Flags::COMMON_ROBOTS)) {
            return false;
        }

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
        // Term archives answer from term meta, and answer FIRST: $post_id is the
        // term's id here, so the post-meta reads below would consult whatever
        // post shares that number and report its title as this archive's.
        $term = $this->get_current_term();
        if ($term) {
            return !empty($this->get_metasync_term_title($term));
        }

        // Social Media & Open Graph meta box, when genuinely customized. Counted
        // here so a post whose ONLY title is the OG one still suppresses the
        // third-party tag — otherwise the plugin keeps rendering its own and the
        // replacement below never gets the chance to emit.
        if ($this->get_customized_og_title($post_id) !== '') {
            return true;
        }

        $seo_title = get_post_meta($post_id, '_metasync_seo_title', true);
        if (!empty($seo_title)) {
            return true;
        }

        $otto_title = get_post_meta($post_id, '_metasync_otto_title', true);
        if (!empty($otto_title)) {
            return true;
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
     * Check whether the OTTO transient cache has live suggestions for the
     * current request URL.
     *
     * Passive get_transient() lookup only — no OTTO API call. Mirrors the
     * cache-key format used by Metasync_Otto_Transient_Cache and the URL
     * construction from Otto_pixel_class::get_route().
     *
     * @return bool
     */
    public function otto_has_live_suggestions() {
        if ($this->live_suggestions_cache !== null) {
            return $this->live_suggestions_cache;
        }

        if (!$this->is_otto_active()) {
            $this->live_suggestions_cache = false;
            return false;
        }

        if (empty($_SERVER['HTTP_HOST']) || empty($_SERVER['REQUEST_URI'])) {
            $this->live_suggestions_cache = false;
            return false;
        }

        $scheme      = is_ssl() ? 'https' : 'http';
        $host        = $_SERVER['HTTP_HOST'];
        $request_uri = strtok($_SERVER['REQUEST_URI'], '?') ?: $_SERVER['REQUEST_URI'];
        $url         = $scheme . '://' . $host . $request_uri;

        $hash    = md5(rtrim(strtolower($url), '/'));
        $site_id = is_multisite() ? get_current_blog_id() : 0;
        $cached  = get_transient('otto_suggestions_' . $site_id . '_' . $hash);

        $this->live_suggestions_cache = ($cached !== false && !empty($cached));
        return $this->live_suggestions_cache;
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

        // OTTO stores nothing in term meta — on an archive it arrives through the
        // output-buffer pass instead. Without this the post-meta read below would
        // report an unrelated post's OTTO tag as this archive's.
        if (!$this->current_object_is_post()) {
            return false;
        }

        if ($this->has_active_seo_plugin() && !$this->otto_has_live_suggestions()) {
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

    /**
     * Whether the per-post "Enable Open Graph & Social Media Tags" toggle is
     * explicitly turned off for the given object.
     *
     * Mirrors the guard in Metasync_OpenGraph::will_emit()/output_opengraph_tags():
     * only an explicit '0' opt-out disables MetaSync's OG/Twitter output; an
     * unset/empty value counts as enabled. When disabled, MetaSync emits no
     * OG/Twitter tags of its own, so it must NOT strip a third-party SEO
     * plugin's OG/Twitter tags either — otherwise the page is left with none.
     *
     * @param  int $post_id
     * @return bool
     */
    private function og_output_disabled($post_id) {
        // Feature switched off site-wide. Checked before the per-post opt-out so
        // it applies even when no post is in scope: MetaSync emits no social tags,
        // so it must leave the third-party plugin's alone.
        if (Metasync_Feature_Flags::is_disabled(Metasync_Feature_Flags::SOCIAL_OG)) {
            return true;
        }

        return $post_id && get_post_meta($post_id, '_metasync_og_enabled', true) === '0';
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

        // Canonical — override Yoast's canonical with the MetaSync/OTTO value when set
        add_filter('wpseo_canonical', [$this, 'filter_yoast_canonical'], 999);
    }

    /**
     * Filter Yoast's canonical URL.
     *
     * When a MetaSync-managed canonical exists (OTTO or the Canonical meta box),
     * return it so Yoast emits our value instead of its own — avoiding a duplicate
     * <link rel="canonical"> while still honoring the per-post override.
     * Otherwise let Yoast's canonical through unchanged.
     */
    public function filter_yoast_canonical($canonical) {
        $custom = $this->get_metasync_canonical($this->get_current_object_id());
        return $custom !== '' ? $custom : $canonical;
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
        $post_id = $this->get_current_object_id();

        // If we hold a title, we write it — the same rule the description path
        // follows. The sync stamp records that a sync happened; it does not decide
        // who renders. The mirrored copy lives in the plugin's own storage and can
        // be edited there, and deferring to it hands our tag over silently.
        //
        // A value, never '': on classic themes the plugin owns the sole <title>
        // renderer and OTTO's buffer replaces the tag afterwards, so blanking it
        // would leave nothing for OTTO to replace.
        $ours = $this->metasync_title_value($post_id);
        if ($ours !== '') {
            return $ours;
        }


        // Taxonomy archives resolve against term meta, not post meta. Resolve
        // the term before plugin ownership checks so a multi-plugin archive is
        // not suppressed by an unrelated primary-plugin decision.
        $term = $this->get_current_term();
        if ($term) {
            $term_title = $this->get_metasync_term_title($term);
            if (!empty($term_title)) {
                return $term_title;
            }
        }

        // When Yoast is the primary output plugin, let it through.
        // For synced posts, Yoast reads from its own storage (already has the value).
        // For unsynced posts as primary, still check for MetaSync sidebar override.
        if ($post_id && $this->is_primary_output_plugin($post_id, 'yoast')) {
            // Even in passthrough, a MetaSync title takes precedence. Resolved
            // through metasync_title_value() rather than read straight off
            // `_metasync_seo_title`: that skipped the rest of the chain, and on
            // an archive it read post meta with a term id, answering with
            // whatever post happened to share that number.
            $sidebar_title = $this->metasync_title_value($post_id);
            if ($sidebar_title !== '') {
                return $sidebar_title;
            }
            return $title;
        }

        // Another plugin is primary — suppress Yoast.
        if ($post_id && $this->has_active_seo_plugin()) {
            if ($this->is_primary_output_plugin($post_id, 'rankmath') || $this->is_primary_output_plugin($post_id, 'aioseo')) {
                return '';
            }
        }

        // MetaSync sidebar has an explicit title — return it so Yoast renders it.
        if ($post_id) {
            $sidebar_title = $this->metasync_title_value($post_id);
            if ($sidebar_title !== '') {
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
     * On term archives: return MetaSync's resolved term value directly. The
     * native plugin field may be empty even when the precedence chain has a
     * value, and MetaSync's own archive emitter does not provide a fallback.
     *
     * On singular pages: suppress when OTTO or MetaSync sidebar provides
     * the description (MetaSync outputs its own tag).
     */
    /**
     * Replace a mirrored OTTO description that is dead data for this post.
     *
     * MetaSync syncs its resolved description into the active plugin's own
     * storage, so that plugin renders it. When the per-post "Disable OTTO"
     * toggle is set, OTTO stands down everywhere else — but the copy already
     * written to the third-party plugin keeps rendering, and OTTO reads as
     * "disabled but still leaking SEO".
     *
     * Only acts when the incoming value is byte-identical to OTTO's stored
     * description: that proves the sync wrote it, so a description the customer
     * typed into Rank Math or Yoast themselves is never touched. Falls through
     * the same precedence the rest of the plugin uses — customer-typed, then
     * imported — and suppresses if neither exists.
     *
     * @param string $description Description the third-party plugin is about to emit.
     * @param int    $post_id     Post ID.
     * @return string|null        Replacement, or null to leave the value alone.
     */
    private function replace_dead_otto_description($description, $post_id) {
        if (!$post_id || !class_exists('Metasync_Otto_Frontend_Toolbar')) {
            return null;
        }

        // The per-post OTTO toggle and OTTO's stored description are post-scoped.
        // On an archive $post_id is a term id, and the byte-comparison below would
        // match an unrelated post's OTTO description and return that post's value
        // as this archive's.
        if (!$this->current_object_is_post()) {
            return null;
        }

        if (!Metasync_Otto_Frontend_Toolbar::is_otto_disabled($post_id)) {
            return null;
        }

        $otto_desc = get_post_meta($post_id, '_metasync_otto_description', true);
        if (empty($otto_desc) || (string) $description !== (string) $otto_desc) {
            return null;
        }

        // Falls through the same chain as everywhere else, with the OTTO tiers
        // dropped because OTTO has stood down for this post. Suppresses when
        // nothing is left.
        if (!self::precedence_available()) {
            return '';
        }

        return Metasync_Seo_Precedence::value(
            $post_id,
            Metasync_Seo_Precedence::FIELD_DESCRIPTION,
            Metasync_Seo_Precedence::TYPE_POST,
            array('include_otto' => false)
        );
    }

    public function filter_yoast_description($description) {
        $post_id = $this->get_current_object_id();

        // A synced OTTO description is dead data once OTTO is switched off
        // for this post; serving it would leak SEO OTTO has stood down from.
        $replacement = $this->replace_dead_otto_description($description, $post_id);
        if ($replacement !== null) {
            return $replacement;
        }

        // If we hold a description, we write it. The sync stamp records that a
        // sync happened; it does not decide who renders. The mirrored copy lives
        // in the plugin's own storage and can be edited or overwritten there —
        // deferring to it hands our tag over with nothing saying so.
        $ours = $this->metasync_description_value($post_id);
        if ($ours !== '') {
            return $ours;
        }

        // On taxonomy archives the queried-object id is a term id, not a post
        // id. Yoast may have no native term description to return (the value
        // can exist only in MetaSync's precedence tiers), so hand it the
        // resolved term value directly. MetaSync's own wp_head emitter exits
        // early for archives and cannot provide a fallback tag.
        $term = $this->get_current_term();
        if ($term) {
            $term_desc = $this->get_metasync_term_description($term);
            if (!empty($term_desc)) {
                return $term_desc;
            }
        }

        if ($post_id && $this->has_active_seo_plugin()) {
            if ($this->is_primary_output_plugin($post_id, 'yoast')) {
                return $description;
            }
            if ($this->is_primary_output_plugin($post_id, 'rankmath') || $this->is_primary_output_plugin($post_id, 'aioseo')) {
                return '';
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
        if ($this->og_output_disabled($post_id)) {
            return $value;
        }

        // If we hold a value, we write it. The sync stamp records that a sync
        // happened; it does not decide who renders. The mirrored copy in the
        // plugin's own storage can be edited or overwritten there, and deferring
        // to it hands our tag over without anything saying so.
        $ours = $this->metasync_social_value(Metasync_Seo_Precedence::FIELD_OG_TITLE, $post_id);
        if ($ours !== '') {
            return $ours;
        }

        // OTTO injects some social tags through the output buffer without ever
        // persisting them, so its own tag check still has to run.
        if ($this->otto_has_tag('og:title')) {
            return '';
        }

        return $value;
    }

    /**
     * Filter Yoast og:description output.
     * Suppress when: OTTO active + has og:description, OR MetaSync has description.
     */
    public function filter_yoast_og_description($value) {
        $post_id = $this->get_current_object_id();
        if ($this->og_output_disabled($post_id)) {
            return $value;
        }

        // If we hold a value, we write it. The sync stamp records that a sync
        // happened; it does not decide who renders. The mirrored copy in the
        // plugin's own storage can be edited or overwritten there, and deferring
        // to it hands our tag over without anything saying so.
        $ours = $this->metasync_social_value(Metasync_Seo_Precedence::FIELD_OG_DESCRIPTION, $post_id);
        if ($ours !== '') {
            return $ours;
        }

        // OTTO injects some social tags through the output buffer without ever
        // persisting them, so its own tag check still has to run.
        if ($this->otto_has_tag('og:description')) {
            return '';
        }

        return $value;
    }

    /**
     * Filter Yoast OG structural tags (og:url, og:type, og:locale, og:site_name, og:image).
     * Suppress when: OTTO active + has og:title (OTTO provides these alongside og:title).
     */
    public function filter_yoast_og_structural($value) {
        $post_id = $this->get_current_object_id();
        if ($this->og_output_disabled($post_id)) {
            return $value;
        }
        if ($post_id && $this->is_primary_output_plugin($post_id, 'yoast')) {
            return $value;
        }
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
        if ($this->og_output_disabled($post_id)) {
            return $value;
        }

        // If we hold a value, we write it. The sync stamp records that a sync
        // happened; it does not decide who renders. The mirrored copy in the
        // plugin's own storage can be edited or overwritten there, and deferring
        // to it hands our tag over without anything saying so.
        $ours = $this->metasync_social_value(Metasync_Seo_Precedence::FIELD_TWITTER_TITLE, $post_id);
        if ($ours !== '') {
            return $ours;
        }

        // OTTO injects some social tags through the output buffer without ever
        // persisting them, so its own tag check still has to run.
        if ($this->otto_has_tag('twitter:title')) {
            return '';
        }

        return $value;
    }

    /**
     * Filter Yoast twitter:description output.
     * Suppress when: OTTO active + has twitter:description, OR MetaSync has description.
     */
    public function filter_yoast_twitter_description($value) {
        $post_id = $this->get_current_object_id();
        if ($this->og_output_disabled($post_id)) {
            return $value;
        }

        // If we hold a value, we write it. The sync stamp records that a sync
        // happened; it does not decide who renders. The mirrored copy in the
        // plugin's own storage can be edited or overwritten there, and deferring
        // to it hands our tag over without anything saying so.
        $ours = $this->metasync_social_value(Metasync_Seo_Precedence::FIELD_TWITTER_DESCRIPTION, $post_id);
        if ($ours !== '') {
            return $ours;
        }

        // OTTO injects some social tags through the output buffer without ever
        // persisting them, so its own tag check still has to run.
        if ($this->otto_has_tag('twitter:description')) {
            return '';
        }

        return $value;
    }

    /**
     * Filter Yoast Twitter structural tags (twitter:image, twitter:card).
     * Suppress when: OTTO active + has any twitter tag.
     */
    public function filter_yoast_twitter_structural($value) {
        $post_id = $this->get_current_object_id();
        if ($this->og_output_disabled($post_id)) {
            return $value;
        }
        if ($post_id && $this->is_primary_output_plugin($post_id, 'yoast')) {
            return $value;
        }
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
            $data = $this->strip_breadcrumb_from_graph($data);
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

        // Canonical — override RankMath's canonical with the MetaSync/OTTO value when set
        add_filter('rank_math/frontend/canonical', [$this, 'filter_rankmath_canonical'], 999);

        // Social — Rank Math renders these from its own storage, so without these
        // filters MetaSync has no way to reach og:/twitter: on a Rank Math page.
        add_filter('rank_math/opengraph/facebook/og_title', [$this, 'filter_rankmath_og_title'], 999);
        add_filter('rank_math/opengraph/facebook/og_description', [$this, 'filter_rankmath_og_description'], 999);
        add_filter('rank_math/opengraph/twitter/twitter_title', [$this, 'filter_rankmath_twitter_title'], 999);
        add_filter('rank_math/opengraph/twitter/twitter_description', [$this, 'filter_rankmath_twitter_description'], 999);

        // Robots — suppress Rank Math's tag when MetaSync holds an intentional
        // robots value for the post, mirroring the AIOSEO path. The advanced
        // directives (max-snippet, max-video-preview, max-image-preview) run
        // through a separate filter and must be suppressed alongside the main
        // tag, or they survive as an orphaned meta tag.
        add_filter('rank_math/frontend/robots', [$this, 'filter_rankmath_robots'], 999);
        add_filter('rank_math/frontend/advanced_robots', [$this, 'filter_rankmath_advanced_robots'], 999);
    }

    /**
     * Shared body for the Rank Math social filters.
     *
     * Same rule as the Yoast and AIOSEO paths: when MetaSync holds a value for
     * this specific tag we write it, and when it holds nothing we leave Rank
     * Math's own tag alone. Each tag consults its OWN chain — twitter:description
     * resolves to _metasync_twitter_description, not the og one — because sharing
     * a chain would decide one tag on the strength of another's value and leave
     * either a duplicate or a blank.
     *
     * @param  string $value    Rank Math's computed value.
     * @param  string $field    A Metasync_Seo_Precedence social field constant.
     * @param  string $otto_tag Matching OTTO tag name.
     * @return string
     */
    private function filter_rankmath_social($value, $field, $otto_tag) {
        $post_id = $this->get_current_object_id();

        if ($this->og_output_disabled($post_id)) {
            return $value;
        }

        $ours = $this->metasync_social_value($field, $post_id);
        if ($ours !== '') {
            return $ours;
        }

        // OTTO injects some social tags through the output buffer without ever
        // persisting them, so its own tag check still has to run.
        if ($this->otto_has_tag($otto_tag)) {
            return '';
        }

        return $value;
    }

    /**
     * Filter Rank Math's og:title output.
     *
     * @param  string $value Rank Math's computed og:title.
     * @return string
     */
    public function filter_rankmath_og_title($value) {
        return $this->filter_rankmath_social($value, Metasync_Seo_Precedence::FIELD_OG_TITLE, 'og:title');
    }

    /**
     * Filter Rank Math's og:description output.
     *
     * @param  string $value Rank Math's computed og:description.
     * @return string
     */
    public function filter_rankmath_og_description($value) {
        return $this->filter_rankmath_social($value, Metasync_Seo_Precedence::FIELD_OG_DESCRIPTION, 'og:description');
    }

    /**
     * Filter Rank Math's twitter:title output.
     *
     * @param  string $value Rank Math's computed twitter:title.
     * @return string
     */
    public function filter_rankmath_twitter_title($value) {
        return $this->filter_rankmath_social($value, Metasync_Seo_Precedence::FIELD_TWITTER_TITLE, 'twitter:title');
    }

    /**
     * Filter Rank Math's twitter:description output.
     *
     * @param  string $value Rank Math's computed twitter:description.
     * @return string
     */
    public function filter_rankmath_twitter_description($value) {
        return $this->filter_rankmath_social($value, Metasync_Seo_Precedence::FIELD_TWITTER_DESCRIPTION, 'twitter:description');
    }

    /**
     * Filter Rank Math robots meta output.
     *
     * When MetaSync holds an intentional robots value (admin checkbox or REST
     * API), suppress Rank Math's robots tag to avoid duplicates. MetaSync's
     * own output in hook_metasync_metatags() will output the MetaSync value
     * instead. The suppression decision is shared with the AIOSEO path so the
     * two plugins behave identically.
     *
     * Registered against rank_math/frontend/robots (the main directives);
     * the max-* directives are handled separately in
     * filter_rankmath_advanced_robots(). Returning an empty array suppresses
     * the tag entirely, except when Rank Math forces a noindex (see
     * site_forces_noindex()), in which case a bare noindex/nofollow floor
     * survives so the page stays deindexed even though MetaSync replaces
     * every other directive.
     *
     * @param  array $robots Rank Math's computed robots attributes array.
     * @return array
     */
    public function filter_rankmath_robots($robots) {
        if ($this->should_suppress_third_party_robots('rankmath')) {
            // MetaSync has robots — suppress Rank Math's tag. Keep only the
            // noindex Rank Math forces on non-public sites and replytocom
            // URLs alive: MetaSync's replacement value carries no such
            // directive, so a full suppression would leave those pages
            // indexable even though the site asked not to be indexed.
            if ($this->site_forces_noindex()) {
                return ['index' => 'noindex', 'follow' => 'nofollow'];
            }
            return [];
        }

        return $robots;
    }

    /**
     * Filter Rank Math's advanced (max-*) robots directives.
     *
     * Shares the suppression decision with the main robots filter; Rank Math
     * merges the advanced payload into the same <meta name="robots"> tag, so
     * when MetaSync replaces the tag the max-* directives must vanish with
     * it. No noindex floor here — the main filter already carries it, and a
     * second copy would just collide in Rank Math's array merge.
     *
     * @param  array $robots Rank Math's advanced robots attributes array.
     * @return array
     */
    public function filter_rankmath_advanced_robots($robots) {
        if ($this->should_suppress_third_party_robots('rankmath')) {
            return [];
        }

        return $robots;
    }

    /**
     * Whether the current request carries a noindex that Rank Math forces
     * independently of any per-post setting — a non-public site
     * (blog_public = 0, "Discourage search engines") or a ?replytocom=
     * comment-reply URL. Mirrors Rank Math's respect_settings_for_robots().
     *
     * @return bool
     */
    private function site_forces_noindex() {
        if (0 === absint(get_option('blog_public')) || isset($_GET['replytocom'])) {
            return true;
        }

        return false;
    }

    /**
     * Filter RankMath's canonical URL.
     *
     * Mirrors filter_yoast_canonical(): return the MetaSync-managed canonical
     * (OTTO or the Canonical meta box) when set, else pass RankMath's through.
     */
    public function filter_rankmath_canonical($canonical) {
        $custom = $this->get_metasync_canonical($this->get_current_object_id());
        return $custom !== '' ? $custom : $canonical;
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
        $post_id = $this->get_current_object_id();

        // If we hold a title, we write it — the same rule the description path
        // follows. The sync stamp records that a sync happened; it does not decide
        // who renders. The mirrored copy lives in the plugin's own storage and can
        // be edited there, and deferring to it hands our tag over silently.
        //
        // A value, never '': on classic themes the plugin owns the sole <title>
        // renderer and OTTO's buffer replaces the tag afterwards, so blanking it
        // would leave nothing for OTTO to replace.
        $ours = $this->metasync_title_value($post_id);
        if ($ours !== '') {
            return $ours;
        }

        // Taxonomy archives resolve against term meta, not post meta. Resolve
        // the term before plugin ownership checks so a multi-plugin archive is
        // not suppressed by an unrelated primary-plugin decision.
        $term = $this->get_current_term();
        if ($term) {
            $term_title = $this->get_metasync_term_title($term);
            if (!empty($term_title)) {
                return $term_title;
            }
        }

        // If another plugin is the primary output owner, suppress Rank Math.
        if ($post_id && $this->has_active_seo_plugin()) {
            if ($this->is_primary_output_plugin($post_id, 'rankmath')) {
                return $title;
            }
            // Another plugin is primary — suppress this one
            if ($this->is_primary_output_plugin($post_id, 'yoast') || $this->is_primary_output_plugin($post_id, 'aioseo')) {
                return '';
            }
        }

        $post_id = $this->get_current_object_id();

        // MetaSync sidebar has an explicit title — return it so Rank Math renders it.
        if ($post_id) {
            $sidebar_title = $this->metasync_title_value($post_id);
            if ($sidebar_title !== '') {
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
     * On term archives: return MetaSync's resolved term value directly. Rank
     * Math may compute an empty native value even when the precedence chain has
     * a value, and MetaSync's own archive emitter does not provide a fallback.
     *
     * On singular pages: suppress when OTTO or MetaSync sidebar provides
     * the description.
     *
     * @param  string $description RankMath's computed description.
     * @return string
     */
    public function filter_rankmath_description($description) {
        $post_id = $this->get_current_object_id();

        // A synced OTTO description is dead data once OTTO is switched off
        // for this post; serving it would leak SEO OTTO has stood down from.
        $replacement = $this->replace_dead_otto_description($description, $post_id);
        if ($replacement !== null) {
            return $replacement;
        }

        // If we hold a description, we write it. The sync stamp records that a
        // sync happened; it does not decide who renders. The mirrored copy lives
        // in the plugin's own storage and can be edited or overwritten there —
        // deferring to it hands our tag over with nothing saying so.
        $ours = $this->metasync_description_value($post_id);
        if ($ours !== '') {
            return $ours;
        }

        // On taxonomy archives the queried-object id is a term id, not a post
        // id. Rank Math may have no native term description to return (the
        // value can exist only in MetaSync's precedence tiers), so hand it the
        // resolved term value directly. MetaSync's own wp_head emitter exits
        // early for archives and cannot provide a fallback tag.
        $term = $this->get_current_term();
        if ($term) {
            $term_desc = $this->get_metasync_term_description($term);
            if (!empty($term_desc)) {
                return $term_desc;
            }
        }

        if ($post_id && $this->has_active_seo_plugin()) {
            if ($this->is_primary_output_plugin($post_id, 'rankmath')) {
                return $description;
            }
            if ($this->is_primary_output_plugin($post_id, 'yoast') || $this->is_primary_output_plugin($post_id, 'aioseo')) {
                return '';
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
            $data = $this->strip_breadcrumb_from_graph($data);
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
        // Post synced to any active plugin — that plugin now owns the
        // description output from its native storage. Suppress MetaSync's own tag.
        $post_id = $this->get_current_object_id();
        if ($post_id && $this->has_active_seo_plugin()) {
            // If synced to any plugin, a primary output plugin exists — suppress MetaSync's own tag.
            if ($this->is_post_synced($post_id, 'yoast') || $this->is_post_synced($post_id, 'rankmath') || $this->is_post_synced($post_id, 'aioseo')) {
                return false;
            }
        }

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
     * Strip BreadcrumbList nodes from a JSON-LD @graph array and remove
     * dangling breadcrumb references from WebPage-type nodes.
     *
     * Previously we only removed the BreadcrumbList entry but left
     * the WebPage's `breadcrumb: { @id: "...#breadcrumb" }` property intact.
     * Google follows that dangling @id, finds no matching node, and reports
     * "Missing field itemListElement".
     *
     * @param  array $graph The @graph array from a third-party SEO plugin.
     * @return array
     */
    private function strip_breadcrumb_from_graph($graph) {
        // Rank Math's graph is keyed by entity name (e.g. 'WebPage', 'ProfilePage')
        // and later filters/consumers look entries up by those keys (see
        // RankMath\Schema\Frontend::remove_person_entity(), hooked on
        // rank_math/schema/validated_data, which reads $data['ProfilePage']).
        // AIOSEO/Yoast pass a plain numeric list. Re-index with array_values()
        // unless the graph carries string keys — a string-keyed graph is Rank
        // Math's shape and must be preserved untouched. This is deliberately
        // broader than array_is_list(): a numeric-keyed AIOSEO/Yoast graph
        // that a third-party filter left with a gap (e.g. an earlier unset())
        // still needs forcing back into a gap-free list, or json_encode()
        // would serialize it as a JSON object instead of an array.
        $has_string_keys = false;
        foreach (array_keys($graph) as $key) {
            if (is_string($key)) {
                $has_string_keys = true;
                break;
            }
        }

        // Collect @ids of BreadcrumbList nodes being removed. Track removal
        // separately from the id list: a BreadcrumbList may carry no @id, in
        // which case it is removed but contributes nothing to $removed_ids.
        $removed_ids = [];
        $removed_any = false;

        foreach ($graph as $key => $entry) {
            if (is_array($entry) && isset($entry['@type']) && $entry['@type'] === 'BreadcrumbList') {
                if (!empty($entry['@id'])) {
                    $removed_ids[] = $entry['@id'];
                }
                $removed_any = true;
                unset($graph[$key]);
            }
        }

        // Remove dangling breadcrumb references from any node that carries one.
        // Gating this on @type === 'WebPage' missed every WebPage subtype the
        // SEO plugins actually emit — Rank Math uses 'CollectionPage' for
        // archives and 'ItemPage' for products — so the BreadcrumbList was
        // removed while the reference to it survived. The subtype set is
        // open-ended, so match on the reference itself rather than the type.
        //
        // Only run when this method actually removed a BreadcrumbList. If it
        // removed nothing, no reference in this graph was invalidated here, and
        // stripping one would break a relationship that is still intact — the
        // list may simply live in another JSON-LD block on the page.
        if ($removed_any) {
            foreach ($graph as $key => &$entry) {
                if (!is_array($entry)) {
                    continue;
                }

                if (isset($entry['breadcrumb'])) {
                    // Remove if the reference points to a stripped node, or if
                    // the removed list carried no @id to match against.
                    $ref_id = is_array($entry['breadcrumb']) ? ($entry['breadcrumb']['@id'] ?? '') : '';
                    if (empty($removed_ids) || empty($ref_id) || in_array($ref_id, $removed_ids, true)) {
                        unset($entry['breadcrumb']);
                    }
                }
            }
            unset($entry);
        }

        return $has_string_keys ? $graph : array_values($graph);
    }

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

        // When schema output is explicitly disabled, don't strip
        // third-party breadcrumbs — MetaSync won't emit its own.
        if (!empty($settings['disable_schema'])) {
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
        // Schema feature switched off: MetaSync contributes no JSON-LD, so the
        // third-party plugin's graph must be left intact rather than emptied.
        if (Metasync_Feature_Flags::is_disabled(Metasync_Feature_Flags::SCHEMA)) {
            return false;
        }

        if (!$this->is_otto_active()) {
            return false;
        }

        $post_id = $this->get_current_object_id();
        if (!$post_id) {
            return false;
        }

        if ($this->has_active_seo_plugin() && !$this->otto_has_live_suggestions()) {
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
    /**
     * Whether get_current_object_id() is currently answering with a post id.
     *
     * On a taxonomy archive the queried object is a term, so that method returns
     * a TERM id. Reading post meta with it answers with whatever post happens to
     * share that number — a collision that is ordinary rather than exotic on any
     * site with a few dozen terms. Every post-meta read keyed on
     * get_current_object_id() has to gate on this.
     *
     * A term is the only case: on the static posts page and the front page the
     * queried object is a real page, and its id is a genuine post id.
     *
     * @return bool
     */
    private function current_object_is_post() {
        return !$this->get_current_term();
    }

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
     * MetaSync's title for a taxonomy archive, highest tier first.
     *
     * The order comes from Metasync_Seo_Precedence: on a term
     * `_metasync_metatitle` means "someone set this deliberately" — the MCP
     * taxonomy tool is its only writer — and an import sits one tier below on
     * `_metasync_imported_seo_title`, so a bulk import can no longer take the
     * slot a deliberate value occupies.
     *
     * Terms carry no OTTO meta at all, which is why that chain is shorter than
     * the post one and why the same key can mean something different on a post
     * without ambiguity. OTTO reaches an archive through the output-buffer pass,
     * which runs after these filters and still overrides what we return.
     *
     * @param  \WP_Term $term Term being rendered.
     * @return string         Title, or '' when MetaSync has none.
     */
    private function get_metasync_term_title($term) {
        if (empty($term->term_id) || !self::precedence_available()) {
            return '';
        }

        return Metasync_Seo_Precedence::value(
            $term->term_id,
            Metasync_Seo_Precedence::FIELD_TITLE,
            Metasync_Seo_Precedence::TYPE_TERM
        );
    }

    /**
     * MetaSync's description for a taxonomy archive. See get_metasync_term_title().
     *
     * @param  \WP_Term $term Term being rendered.
     * @return string         Description, or '' when MetaSync has none.
     */
    private function get_metasync_term_description($term) {
        if (empty($term->term_id) || !self::precedence_available()) {
            return '';
        }

        return Metasync_Seo_Precedence::value(
            $term->term_id,
            Metasync_Seo_Precedence::FIELD_DESCRIPTION,
            Metasync_Seo_Precedence::TYPE_TERM
        );
    }

    /**
     * The social value MetaSync holds for a tag, if any.
     *
     * Social fields carry their own values rather than deriving from the page
     * title and description, so they are asked about on their own chain: a post
     * can have an OG title and no SEO title, and the page-title chain would
     * answer "we hold nothing" for a value we do hold. That mismatch is what let
     * a third-party plugin's own OG tag outrank ours.
     *
     * @param  string $field   A Metasync_Seo_Precedence social field constant.
     * @param  int    $post_id Post being rendered.
     * @return string          '' when MetaSync holds nothing for this tag.
     */
    private function metasync_social_value($field, $post_id) {
        if (!$post_id || !self::precedence_available()) {
            return '';
        }

        // Social values are per-post. On an archive the queried-object id is a
        // term id, and reading post meta with it would answer with whatever post
        // happens to share that number — another page's og:title.
        if (!is_singular()) {
            return '';
        }

        // The OG meta box pre-fills every field from the post and PERSISTS those
        // defaults on save, so a non-empty value does not prove the customer chose
        // it. Only a value that differs from the default counts as theirs; an
        // auto-filled one must not outrank OTTO. Same comparison
        // Otto_html_class::apply_metabox_og_precedence() makes — without it the two
        // disagree, and a page can lose the tag entirely because each side expects
        // the other to supply it.
        $customized = $this->customized_metabox_value($post_id, $field);
        if ($customized !== '') {
            return $customized;
        }

        // Auto-filled or unset: skip MetaSync's own tier and take the rest of the
        // chain — OTTO, then whatever an import brought in.
        $value = Metasync_Seo_Precedence::value(
            $post_id,
            $field,
            Metasync_Seo_Precedence::TYPE_POST,
            array('include_metasync' => false)
        );
        if ($value !== '') {
            return $value;
        }

        // A social description with no OG-specific value of its own falls back to
        // the page description, which is what MetaSync's own emitter has always
        // done. Without this the plugin renders its own og:description while the
        // sidebar emits ours, and the page carries both.
        if ($field === Metasync_Seo_Precedence::FIELD_OG_DESCRIPTION
            || $field === Metasync_Seo_Precedence::FIELD_TWITTER_DESCRIPTION) {
            return $this->metasync_description_value($post_id);
        }

        // Social titles fall back to the page title for the same reason, and it
        // is the fallback Metasync_OpenGraph already uses. It also keeps the
        // WP-609 replacement path from firing: with the plugin rendering our
        // title there is no suppressed tag left to replace.
        if ($field === Metasync_Seo_Precedence::FIELD_OG_TITLE
            || $field === Metasync_Seo_Precedence::FIELD_TWITTER_TITLE) {
            return Metasync_Seo_Precedence::value($post_id, Metasync_Seo_Precedence::FIELD_TITLE);
        }

        return '';
    }

    /**
     * A social meta box value, but only when the customer genuinely set it.
     *
     * The box pre-fills Title from the post title, Description from the excerpt
     * and Image from the featured image, and persists whatever is in those fields
     * on save. So "non-empty" is not intent — a field is the customer's only when
     * it differs from the default it was pre-filled with.
     *
     * Compared per field rather than letting the Twitter fields inherit the OG
     * verdict, so a customized og:title cannot silently promote an auto-filled
     * twitter:title.
     *
     * @param  int    $post_id
     * @param  string $field   A Metasync_Seo_Precedence social field constant.
     * @return string          '' when unset, auto-filled, or not determinable.
     */
    private function customized_metabox_value($post_id, $field) {
        $map = array(
            Metasync_Seo_Precedence::FIELD_OG_TITLE            => array('_metasync_og_title', 'title'),
            Metasync_Seo_Precedence::FIELD_OG_DESCRIPTION      => array('_metasync_og_description', 'description'),
            Metasync_Seo_Precedence::FIELD_OG_IMAGE            => array('_metasync_og_image', 'image'),
            Metasync_Seo_Precedence::FIELD_TWITTER_TITLE       => array('_metasync_twitter_title', 'title'),
            Metasync_Seo_Precedence::FIELD_TWITTER_DESCRIPTION => array('_metasync_twitter_description', 'description'),
            Metasync_Seo_Precedence::FIELD_TWITTER_IMAGE       => array('_metasync_twitter_image', 'image'),
        );

        if (!isset($map[$field])) {
            return '';
        }

        list($meta_key, $default_key) = $map[$field];

        $stored = (string) get_post_meta($post_id, $meta_key, true);
        if ($stored === '') {
            return '';
        }

        // A stored "Auto Draft" placeholder was never something the customer typed —
        // it is the meta box's own pre-fill from a still-untitled post, persisted
        // verbatim. Comparing only against the post's *current* title (below) misses
        // this once the post is later given a real title: the placeholder no longer
        // matches the default, so it reads as a deliberate customization and gets
        // handed to a third-party SEO plugin as-is (WP-640). Collapse it here too,
        // the same way the render-time resolver already does for MetaSync's own tag.
        if (class_exists('Metasync_OpenGraph')
            && in_array($meta_key, Metasync_OpenGraph::AUTO_DRAFT_PRONE_KEYS, true)
            && Metasync_OpenGraph::is_auto_draft_title($stored)
        ) {
            return '';
        }

        // The title default is the post title, which we can read without help. The
        // description and image defaults come from the emitter's own resolver so
        // they match it exactly.
        if ($default_key === 'title') {
            $post    = get_post($post_id);
            $default = ($post instanceof WP_Post) ? (string) $post->post_title : '';

            return $stored === $default ? '' : $stored;
        }

        if (!class_exists('Metasync_OpenGraph') || !Metasync_OpenGraph::get_instance()) {
            // Cannot prove it was auto-filled, so do not demote a value the
            // customer may well have typed. Erring the other way would silently
            // drop real values whenever the emitter is unavailable.
            return $stored;
        }

        $defaults = Metasync_OpenGraph::get_instance()->get_default_og_values($post_id);
        $default  = (string) $defaults[$default_key];

        return $stored === $default ? '' : $stored;
    }

    /**
     * The page title MetaSync holds for a post, if any.
     *
     * The full chain — what the customer typed, then OTTO, then a value brought
     * in from another SEO plugin — so a third-party plugin can never render its
     * own title over one of ours. OTTO's tiers drop out on their own when the
     * per-post toggle is off.
     *
     * Cached for the request: three plugins' title filters can each ask, and on
     * a page where nothing is set that is otherwise four meta reads apiece.
     *
     * @param  int $post_id Post being rendered.
     * @return string       '' when MetaSync holds nothing.
     */
    private function metasync_title_value($post_id) {
        if (!$post_id || !self::precedence_available()) {
            return '';
        }

        if ($this->get_current_term()) {
            return '';
        }

        if (array_key_exists($post_id, $this->title_value_cache)) {
            return $this->title_value_cache[$post_id];
        }

        $this->title_value_cache[$post_id] = Metasync_Seo_Precedence::value(
            $post_id,
            Metasync_Seo_Precedence::FIELD_TITLE
        );

        return $this->title_value_cache[$post_id];
    }

    /**
     * The meta description MetaSync holds for a post, if any.
     *
     * The full chain — what the customer typed, then OTTO, then a value brought
     * in from another SEO plugin — so a third-party plugin can never render its
     * own description over one of ours. OTTO's tiers drop out on their own when
     * the per-post toggle is off, so a stood-down suggestion is not served.
     *
     * @param  int $post_id Post being rendered.
     * @return string       '' when MetaSync holds nothing.
     */
    private function metasync_description_value($post_id) {
        if (!$post_id || !self::precedence_available()) {
            return '';
        }

        // On a taxonomy archive the queried-object id is a term id; reading post
        // meta with it would answer with whatever post shares that number. The
        // term branch in each filter handles archives.
        if ($this->get_current_term()) {
            return '';
        }

        return Metasync_Seo_Precedence::value(
            $post_id,
            Metasync_Seo_Precedence::FIELD_DESCRIPTION
        );
    }

    /**
     * Make sure the precedence resolver is loaded before calling it.
     *
     * These filters run on wp_head, so an unresolvable class here takes the
     * front end down rather than degrading. A partial update can leave newer
     * PHP files beside an older committed autoload classmap that has never
     * heard of this class, so fall back to requiring the file directly and
     * report honestly if it is genuinely absent. Same reasoning as the post-meta
     * sync bridges in class-metasync.php.
     *
     * @return bool
     */
    private static function precedence_available() {
        if (class_exists('Metasync_Seo_Precedence')) {
            return true;
        }

        $file = plugin_dir_path(__FILE__) . 'class-metasync-seo-precedence.php';
        if (is_readable($file)) {
            require_once $file;
        }

        return class_exists('Metasync_Seo_Precedence');
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

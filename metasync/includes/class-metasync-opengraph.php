<?php

/**
 * The Open Graph functionality of the plugin.
 *
 * @package    MetaSync
 * @subpackage MetaSync/includes
 * @since      1.0.0
 */

# Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Open Graph Tags Generator Class
 * 
 * This class handles the generation and management of Open Graph and Twitter Card tags
 * for WordPress posts and pages.
 */
class Metasync_OpenGraph {

    /**
     * The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     */
    private $version;

    /**
     * Most recently constructed instance, for cross-class reuse of the value
     * resolvers (e.g. the OTTO buffer needs the same default OG values the meta
     * box pre-fills, to tell an auto-filled default from a user-customized value).
     *
     * @var Metasync_OpenGraph|null
     */
    private static $instance;

    /**
     * Per-request memo for get_default_og_values(), keyed on post ID. See that
     * method for why the defaults are worth memoizing and where the memo is
     * dropped.
     *
     * @var array<int, array{title:string,description:string,image:string}>
     */
    private static $default_og_values_memo = [];

    /**
     * Meta box ID
     */
    const META_BOX_ID = 'metasync_opengraph_meta_box';

    /**
     * The social title/description keys the meta box pre-fills from the post
     * title/excerpt and persists on save. These are the keys that can capture the
     * "Auto Draft" placeholder WordPress gives a brand-new, still-untitled post.
     */
    const AUTO_DRAFT_PRONE_KEYS = [
        '_metasync_og_title',
        '_metasync_og_description',
        '_metasync_twitter_title',
        '_metasync_twitter_description',
    ];

    /**
     * The social title keys whose meta box default is the post title itself.
     * Unlike the description keys — whose pre-fill (the excerpt) is content the
     * editor curates separately — these mirror the title verbatim, so a stored
     * value equal to it carries no information of its own: it is the pre-fill
     * snapshot, not a customization. Reads collapse such rows to '' so the live
     * title (which a rename keeps fresh) applies instead.
     */
    const TITLE_DEFAULTED_KEYS = [
        '_metasync_og_title',
        '_metasync_twitter_title',
    ];

    /**
     * The social description keys whose meta box default is the resolved
     * description (the excerpt the emitter would derive). The pre-fill persisted
     * that default verbatim on save, so rows exist where these keys hold a copy
     * of the excerpt as it was on save day — stale the moment the excerpt or the
     * content it is generated from changes. Reads collapse such rows to '' so
     * the live default applies instead. A value that differs from the default is
     * untouched: only text the editor genuinely typed survives.
     */
    const DESCRIPTION_DEFAULTED_KEYS = [
        '_metasync_og_description',
        '_metasync_twitter_description',
    ];

    /**
     * Site option holding every "Auto Draft" placeholder variant this install
     * has actually seen, keyed by nothing, de-duplicated, capped. Core fills a
     * new post's title with __( 'Auto Draft' ) in the *creating admin user's*
     * locale, and that translation is not loaded on front-end requests, so no
     * render-time literal/translation list can be complete. The registry is the
     * locale-free source of truth: captured verbatim at creation time in admin,
     * where the translation does resolve, and re-read anywhere.
     */
    const AUTO_DRAFT_PLACEHOLDER_OPTION = 'metasync_auto_draft_placeholders';

    /**
     * Upper bound on registered placeholder variants. Locales change rarely;
     * the cap keeps the site option tiny no matter how many editors come and
     * go with different locales.
     */
    const AUTO_DRAFT_PLACEHOLDER_CAP = 20;

    /**
     * Upper bound on posts held in the get_default_og_values() memo. One
     * front-end render touches one or two posts; a back-end loop (an importer,
     * a bulk action, a resync walking every post) would otherwise grow the
     * memo — and the excerpt-derivation results it caches — without end for
     * the life of the request. When full the oldest entry is dropped first:
     * insertion order is the only order the memo has, and a dropped post
     * simply re-derives on its next read.
     */
    const DEFAULT_OG_VALUES_MEMO_CAP = 10;

    /**
     * Per-post flag marking "this post was saved while its title was still the
     * untitled placeholder". Stores the exact placeholder string that was
     * recognized at save time, so the render path can suppress by state (flag
     * present + title still equals it) rather than by string matching alone —
     * a genuinely-titled post never carries the flag.
     */
    const UNTITLED_FLAG_META = '_metasync_untitled_placeholder';

    /**
     * Initialize the class and set its properties.
     */
    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        self::$instance = $this;
    }

    /**
     * Get the most recently constructed instance (null if none yet).
     *
     * @return Metasync_OpenGraph|null
     */
    public static function get_instance() {
        return self::$instance;
    }

    /**
     * Whether a value is the placeholder title WordPress assigns to a brand-new,
     * still-untitled post.
     *
     * Core creates the row with a *translated* title —
     * wp-admin/includes/post.php: 'post_title' => __( 'Auto Draft' ) — so matching
     * only the English literal misses every localized site. Compare against the
     * literal and the current locale's translation, so both an English install and
     * a translated one are covered, as is a value stored before a locale switch.
     * A third arm checks the captured-variant registry, because the translation
     * only resolves in admin: on front-end requests __( 'Auto Draft' ) stays
     * English even on a localized site, and a stored de_DE placeholder would
     * otherwise pass every check above.
     *
     * Exact match after trim, never fuzzy: an editor who legitimately titles a post
     * "Auto Draft" on purpose is a caller-side concern, and only the four social
     * keys in AUTO_DRAFT_PRONE_KEYS are ever routed through here.
     *
     * @param mixed $value
     * @return bool
     */
    public static function is_auto_draft_title($value) {
        if (!is_string($value)) {
            return false;
        }
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        # 'default' text domain, matching the __() call core itself uses.
        if ($value === 'Auto Draft' || $value === trim(__('Auto Draft'))) {
            return true;
        }
        # Registry arm: a variant captured in another locale's admin request.
        # Without it the guard above is English-only on every non-admin request,
        # because core never loads the 'default' MO files out there.
        return in_array($value, self::known_auto_draft_placeholders(), true);
    }

    /**
     * Every placeholder variant known to this install: the English literal plus
     * whatever has been captured into the site option so far.
     *
     * @return string[]
     */
    public static function known_auto_draft_placeholders() {
        $stored = get_option(self::AUTO_DRAFT_PLACEHOLDER_OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $variants = ['Auto Draft'];
        foreach ($stored as $variant) {
            if (is_string($variant) && trim($variant) !== '' && !in_array(trim($variant), $variants, true)) {
                $variants[] = trim($variant);
            }
        }
        return $variants;
    }

    /**
     * Capture a placeholder variant into the site option. Called from the
     * admin save path, where the creating request's own __( 'Auto Draft' )
     * has already verified the title *is* core's placeholder — so no
     * translation is needed here, only de-duplication and capping.
     *
     * @param mixed $title Verbatim post title recognized as the placeholder.
     * @return void
     */
    public static function remember_auto_draft_placeholder($title) {
        $title = is_string($title) ? trim($title) : '';
        if ($title === '' || $title === 'Auto Draft') {
            return;
        }
        $known = self::known_auto_draft_placeholders();
        if (in_array($title, $known, true)) {
            return;
        }
        # Persist captured variants only: the English literal is seeded
        # code-side by known_auto_draft_placeholders(), so storing it would be
        # redundant state.
        $captured = array_values(array_diff($known, ['Auto Draft']));
        $captured[] = $title;
        update_option(self::AUTO_DRAFT_PLACEHOLDER_OPTION, array_slice($captured, -self::AUTO_DRAFT_PLACEHOLDER_CAP), false);
    }

    /**
     * admin_init callback: register the placeholder variant the current admin
     * request's translation resolves to. remember_auto_draft_placeholder()
     * de-duplicates, so repeated requests only cost one cached option read.
     *
     * @return void
     */
    public static function seed_auto_draft_placeholder() {
        self::remember_auto_draft_placeholder(__('Auto Draft'));
    }

    /**
     * Read a persisted social meta value, collapsing the "Auto Draft" placeholder
     * to an empty string.
     *
     * Every consumer resolves these fields through a "first non-empty wins" chain
     * (persisted key -> OTTO staging key -> real post title). A stored placeholder
     * is truthy, so the chain would keep it; returning '' lets the chain fall
     * through to the real title. That fixes rows already polluted before this fix
     * shipped, at render time, without a DB migration.
     *
     * Public and static because the three consumers that actually emit or forward
     * these values live in different classes: this emitter,
     * Otto_html_class::apply_metabox_og_precedence(), and Metasync_Plugin_Sync's
     * mirror into Yoast/RankMath/AIOSEO storage. Follows the same
     * sanitize-at-the-source pattern as Metasync_Canonical_Sanitizer::sanitize().
     *
     * @param mixed $value Raw stored meta value.
     * @return string
     */
    public static function strip_auto_draft_title($value) {
        if (self::is_auto_draft_title($value)) {
            return '';
        }
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Read one of the AUTO_DRAFT_PRONE_KEYS for a post with the placeholder
     * collapsed to '' — and, for the title keys, a stored snapshot of the
     * title default the pre-fill showed (the post title, or the og title for
     * the twitter twin), and for the description keys a stored snapshot of
     * the resolved description, collapsed too.
     *
     * @param int    $post_id
     * @param string $key
     * @return string
     */
    public static function get_social_meta($post_id, $key) {
        return self::strip_description_snapshot(
            $post_id,
            $key,
            self::strip_title_snapshot(
                $post_id,
                $key,
                self::strip_auto_draft_title(get_post_meta($post_id, $key, true))
            )
        );
    }

    /**
     * Collapse a stored social title that merely mirrors the post's own title.
     *
     * The meta box used to pre-fill Title from the post title as a real value
     * and persist it on save, so rows exist where `_metasync_og_title` (and the
     * twitter twin) hold a verbatim copy of the title as it was on save day.
     * Renaming the post then left that snapshot stale with nothing to tell it
     * apart from a typed title. Collapsing it here — at the read, the same
     * place the "Auto Draft" placeholder collapses — makes such a row read as
     * unset, and the render-time fallback to the *live* title keeps the social
     * title in step with every rename. A value that differs from the title is
     * untouched: only text the editor genuinely typed survives.
     *
     * The twitter twin compares against what the og title field showed when
     * the pre-fill was rendered — a stored og title if one is set, otherwise
     * the same live post title — since that is exactly what the pre-fill
     * echoed into the twitter field. Without that chain, a twitter row echoed
     * from a typed og title would read as a deliberate override and stop
     * following the og title it always rendered.
     *
     * Compared after trim on both sides: stored values pass through
     * sanitize_text_field, which trims, so only the post title side can carry
     * surrounding whitespace.
     *
     * Public and static for the same reason strip_auto_draft_title() is: the
     * consumers live in different classes (this emitter, the precedence
     * resolver, the plugin sync mirror, the SEO suite).
     *
     * @param int    $post_id
     * @param string $key     One of TITLE_DEFAULTED_KEYS; others pass through.
     * @param mixed  $value   Stored meta value, already placeholder-collapsed.
     * @return string
     */
    public static function strip_title_snapshot($post_id, $key, $value) {
        $value = is_scalar($value) ? (string) $value : '';
        if ($value === '' || !in_array($key, self::TITLE_DEFAULTED_KEYS, true)) {
            return $value;
        }

        $default = self::default_social_title($post_id, $key);
        if ($default === '') {
            return $value;
        }

        return trim($value) === trim($default) ? '' : $value;
    }

    /**
     * The default a title key renders when its own field is blank: the live
     * post title for the og key, and for the twitter key whatever the og field
     * would show (a stored og title if one is set, otherwise the same live
     * title) — the exact chain the meta box fallback used to pre-fill as a
     * value and still shows as the twitter field's placeholder.
     *
     * social_post_title() — not the raw post_title — is what the pre-fill ever
     * stored, and it returns '' for a still-untitled post, where there is no
     * meaningful default to compare against anyway (such rows are the
     * placeholder case strip_auto_draft_title() already handles).
     *
     * @param int    $post_id
     * @param string $key
     * @return string
     */
    private static function default_social_title($post_id, $key) {
        $title = self::social_post_title(get_post($post_id));
        if ($key === '_metasync_og_title') {
            return $title;
        }

        $og = self::get_social_meta($post_id, '_metasync_og_title');
        return $og !== '' ? $og : $title;
    }

    /**
     * Collapse a stored social description that merely mirrors the default the
     * meta box pre-filled.
     *
     * The meta box pre-filled Description from the resolved description (the
     * manual excerpt, or one generated from the content) as a real value and
     * persisted it on save, so rows exist where `_metasync_og_description` (and
     * the twitter twin) hold a copy of the excerpt as it was on save day.
     * Changing the excerpt — or the content the excerpt is generated from — then
     * left that snapshot stale with nothing to tell it apart from a typed
     * description. Collapsing it here — at the read, the same place the "Auto
     * Draft" placeholder and title snapshots collapse — makes such a row read as
     * unset, and the render-time fallback to the *live* excerpt keeps the social
     * description in step. A value that differs from the default is untouched:
     * only text the editor genuinely typed survives.
     *
     * The twitter twin compares against what the og description field showed
     * when the pre-fill was rendered — a stored og description if one is set,
     * otherwise the same resolved default — since that is exactly what the
     * pre-fill echoed into the twitter field.
     *
     * Compared after whitespace normalization on both sides: descriptions pass
     * through sanitize_textarea_field, which preserves line breaks, and a manual
     * excerpt can carry them while the generated default cannot, so a snapshot
     * and its default must be compared with runs of whitespace treated as one
     * space rather than byte-for-byte.
     *
     * Public and static for the same reason strip_title_snapshot() is: the
     * consumers live in different classes (this emitter, the precedence
     * resolver, the plugin sync mirror, the SEO suite).
     *
     * @param int    $post_id
     * @param string $key     One of DESCRIPTION_DEFAULTED_KEYS; others pass through.
     * @param mixed  $value   Stored meta value, already placeholder-collapsed.
     * @return string
     */
    public static function strip_description_snapshot($post_id, $key, $value) {
        $value = is_scalar($value) ? (string) $value : '';
        if ($value === '' || !in_array($key, self::DESCRIPTION_DEFAULTED_KEYS, true)) {
            return $value;
        }

        $default = self::default_social_description($post_id, $key);
        if ($default === '') {
            return $value;
        }

        return self::normalize_description_compare($value) === self::normalize_description_compare($default)
            ? ''
            : $value;
    }

    /**
     * The default a description key renders when its own field is blank: the
     * resolved description for the og key, and for the twitter key whatever the
     * og field would show (a stored og description if one is set, otherwise the
     * same resolved default — even when that default is empty) — the exact
     * chain the meta box fallback used to pre-fill as a value.
     *
     * Resolved through the emitter's own instance so it matches what actually
     * renders; when no instance is available the default cannot be proven, so ''
     * is returned and the caller keeps the stored value rather than risk
     * discarding text the editor typed.
     *
     * @param int    $post_id
     * @param string $key
     * @return string
     */
    private static function default_social_description($post_id, $key) {
        $instance = self::get_instance();
        if (!$instance) {
            return '';
        }

        $default = (string) $instance->get_default_og_values($post_id)['description'];
        if ($key === '_metasync_og_description') {
            return $default;
        }

        # The og chain runs for the twitter twin even when the resolved default
        # is empty: the pre-fill echoed the og description into the twitter
        # field whenever the og field carried a value, so that — not the empty
        # excerpt — is the default a stored twitter echo must match to collapse.
        $og = self::get_social_meta($post_id, '_metasync_og_description');
        return $og !== '' ? $og : $default;
    }

    /**
     * Normalize a description for equality comparison: trimmed, with runs of
     * whitespace collapsed to a single space. Falls back to a plain trim when
     * the value is not valid UTF-8 and the /u pattern therefore fails.
     *
     * @param string $value
     * @return string
     */
    private static function normalize_description_compare($value) {
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $value));
        return is_string($normalized) ? $normalized : trim((string) $value);
    }

    /**
     * A post's title for social use, suppressing only WordPress's own placeholder.
     *
     * Distinct from strip_auto_draft_title(), which is for *stored* meta values:
     * there the string is all we have, since a legacy row carries no clue about the
     * status it was written under. A live post gives us both signals, so require
     * both — the post is still an auto-draft AND its title is the placeholder. An
     * editor who genuinely titles a published post "Auto Draft" (an article about
     * the placeholder itself, say) keeps it as their og:title.
     *
     * @param WP_Post|mixed $post
     * @return string
     */
    public static function social_post_title($post) {
        if (!$post instanceof WP_Post) {
            return '';
        }
        if ($post->post_status === 'auto-draft' && self::is_auto_draft_title($post->post_title)) {
            return '';
        }
        # State-based arm: the post was saved while still untitled (flag set at
        # save time, where translations resolve) and its title is still that
        # flagged placeholder. This is what catches a placeholder-titled post
        # that went on to be published — the status check above no longer
        # matches it, and on a localized site the string checks cannot either.
        $flagged = get_post_meta($post->ID, self::UNTITLED_FLAG_META, true);
        if (is_string($flagged) && trim($flagged) !== '' && trim((string) $post->post_title) === trim($flagged)) {
            return '';
        }
        return (string) $post->post_title;
    }

    /**
     * Whether MetaSync social output is disabled for a post.
     *
     * The site-wide switch and the per-post switch both disable only MetaSync's
     * social output; callers must leave third-party SEO output untouched.
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    public static function is_social_output_disabled($post_id = 0) {
        if (Metasync_Feature_Flags::is_disabled(Metasync_Feature_Flags::SOCIAL_OG)) {
            return true;
        }

        return $post_id > 0 && get_post_meta($post_id, '_metasync_og_enabled', true) === '0';
    }

    /**
     * The default OG values the meta box pre-fills for a post: post title,
     * generated excerpt, and featured image. Computed with the same helpers the
     * meta box/emitter use, so a caller can compare a stored _metasync_og_* value
     * against the default and tell whether the user genuinely customized it (the
     * meta box persists these defaults on save, so a non-empty value alone does
     * not prove user intent).
     *
     * The title is returned empty on a still-untitled post rather than as the
     * "Auto Draft" placeholder, so the meta box pre-fills nothing and callers
     * comparing a stored value against this default don't read the placeholder as
     * a deliberate override.
     *
     * Memoized per request: the values are pure functions of the post, and one
     * front-end render resolves them several times (every collapsed description
     * read asks for the default, and the OTTO precedence walk asks again) — on
     * page-builder content the excerpt pass alone re-runs the whole
     * shortcode-strip pipeline each time. The save handler drops the memo
     * before its comparisons, since save_post fires after the post row write
     * and the comparison must see the new title/excerpt. Capped at
     * DEFAULT_OG_VALUES_MEMO_CAP posts for loops that walk many posts in one
     * request.
     *
     * @param int $post_id
     * @return array{title:string,description:string,image:string}
     */
    public function get_default_og_values($post_id) {
        $post_id = (int) $post_id;
        // No real post: get_post(0) answers with the global post, which would
        // then be memoized under key 0 and served for every later bogus id.
        if ($post_id <= 0) {
            return ['title' => '', 'description' => '', 'image' => ''];
        }
        if (isset(self::$default_og_values_memo[$post_id])) {
            return self::$default_og_values_memo[$post_id];
        }

        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            return ['title' => '', 'description' => '', 'image' => ''];
        }

        if (count(self::$default_og_values_memo) >= self::DEFAULT_OG_VALUES_MEMO_CAP) {
            // unset() on the first key, NOT array_shift(): the memo is keyed by
            // post id, and array_shift() reindexes integer keys from zero —
            // after one eviction the survivors would live under 0..8 and any
            // post with a low id would be served another post's defaults.
            unset(self::$default_og_values_memo[array_key_first(self::$default_og_values_memo)]);
        }
        return self::$default_og_values_memo[$post_id] = [
            'title'       => self::social_post_title($post),
            'description' => (string) $this->get_post_excerpt($post),
            'image'       => (string) $this->get_featured_image_url($post->ID),
        ];
    }

    /**
     * Drop the get_default_og_values() memo. Called on the save path, where the
     * just-written post row must be re-read rather than served from a memo
     * populated earlier in the request; also the seam long-lived processes
     * (tests, CLI) use to simulate a fresh request. Mirrors
     * Metasync_Otto_Config::clear_cache().
     */
    public static function clear_default_og_values_memo() {
        self::$default_og_values_memo = [];
    }

    /**
     * Register all hooks for this class
     */
    public function init() {
        # Admin hooks
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        # Runs before save_meta_box_data so the untitled flag it maintains is
        # already current when the meta box save guard consults it.
        add_action('save_post', [$this, 'track_untitled_post'], 5, 2);
        add_action('save_post', [$this, 'save_meta_box_data']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        # Alternative script loading for post edit screens
        add_action('admin_print_scripts-post.php', [$this, 'force_enqueue_scripts']);
        add_action('admin_print_scripts-post-new.php', [$this, 'force_enqueue_scripts']);

        # Frontend hooks
        add_action('wp_head', [$this, 'output_opengraph_tags'], 5);
        add_action('wp_head', [$this, 'output_article_tags'], 6);

        # Update OpenGraph URL when post is published/updated
        add_action('save_post', [$this, 'update_opengraph_url'], 20);

        # Update OpenGraph URL when post permalink changes
        add_action('post_updated', [$this, 'check_permalink_change'], 10, 3);

        # Also check on transition_post_status for status changes
        add_action('transition_post_status', [$this, 'check_status_change'], 10, 3);

        # Check when post slug is updated via edit slug functionality
        add_action('wp_ajax_sample-permalink', [$this, 'check_slug_change'], 5);

        # AJAX hooks for preview
        add_action('wp_ajax_metasync_og_preview', [$this, 'ajax_generate_preview']);

        # Register cross-plugin dedup filters (Yoast / Rank Math) when their plugins are active
        $this->register_dedup_filters();

        # Keep the localized "Auto Draft" placeholder out of SEO-plugin titles
        # for posts published while still untitled.
        $this->register_untitled_title_filters();

        # Seed the placeholder registry with this admin user's locale variant,
        # so localized values are recognized everywhere even before any new
        # post is created on a localized install.
        if (is_admin()) {
            add_action('admin_init', [self::class, 'seed_auto_draft_placeholder']);
        }

        # Shared predicate so the legacy emitter (Metasync_Seo_Output::hook_metasync_metatags)
        # can suppress its own OG/Twitter blocks whenever this class will emit for the post
        add_filter('metasync_opengraph_will_emit', [$this, 'will_emit']);
    }

    /**
     * Shared predicate: returns true when output_opengraph_tags() will emit
     * the consolidated OG/Twitter block for the current request.
     *
     * Mirrors the early-return guards in output_opengraph_tags() so this
     * canonical emitter and the legacy emitter stay mutually exclusive —
     * exactly one fires per page, and neither stays silent on a
     * MetaSync-only site.
     *
     * @param bool $default Filter default (ignored; the real answer is computed).
     * @return bool
     */
    public function will_emit($default = false) {
        # Feature switched off, so this emitter stays silent. The legacy emitter
        # checks the same switch independently, so reporting false here cannot
        # hand it the work.
        if (Metasync_Feature_Flags::is_disabled(Metasync_Feature_Flags::SOCIAL_OG)) {
            return false;
        }

        if (!is_singular($this->get_supported_post_types())) {
            return false;
        }

        global $post;
        if (!$post instanceof WP_Post) {
            return false;
        }

        # Only an explicit '0' opt-out disables output; unset/empty counts as enabled
        $og_enabled = get_post_meta($post->ID, '_metasync_og_enabled', true);
        if ($og_enabled === '0') {
            return false;
        }

        # OTTO active with persisted OG data owns the page (legacy emitter suppresses too)
        if ($this->otto_owns_og($post->ID)) {
            return false;
        }

        # Third-party SEO plugin active: yield entirely, legacy emitter keeps its original behavior
        if (apply_filters('metasync_opengraph_check_conflicts', true) && $this->has_seo_plugin_conflicts()) {
            return false;
        }

        return true;
    }

    /**
     * Whether OTTO owns this page's Open Graph / Twitter output.
     *
     * When OTTO is enabled and has persisted OG data for the post, OTTO's
     * dynamically-injected tags (and the buffer-level dedup) take precedence, so
     * the per-post OG meta box values are not emitted on the frontend. Shared by
     * the frontend emitter (to suppress duplicate output) and the admin meta box
     * (to warn the user their values won't apply on an OTTO-managed page).
     *
     * @param int $post_id
     * @return bool
     */
    private function otto_owns_og($post_id) {
        if (!class_exists('Metasync_Otto_Config') || !Metasync_Otto_Config::is_otto_enabled()) {
            return false;
        }
        $otto_og_title = get_post_meta($post_id, '_metasync_otto_og_title', true);
        $otto_og_desc  = get_post_meta($post_id, '_metasync_otto_og_description', true);
        return !empty($otto_og_title) || !empty($otto_og_desc);
    }

    /**
     * Add the Open Graph meta box to post and page editors
     */
    public function add_meta_box() {
        # Don't show meta box if user's role doesn't have plugin access
        if (!Metasync::current_user_has_plugin_access()) {
            return;
        }

        # Check if user has permission to edit posts
        if (!current_user_can('edit_posts')) {
            return;
        }

        # Meta title and description are always enabled by default
        $general_settings = Metasync::get_option('general', []);

        # Check if Social Media & Open Graph meta box is disabled
        if (!empty($general_settings['disable_social_opengraph_metabox'])) {
            return;
        }

        # LPS / custom-HTML pages bake their own OG/social tags into their HTML bundle,
        # served before wp_head — so this box does nothing on them. Hide it; the SEO
        # read-only notice covers the messaging.
        $lps_post_id = isset($_GET['post']) ? intval($_GET['post']) : (isset($_POST['post_ID']) ? intval($_POST['post_ID']) : 0);
        if (function_exists('metasync_is_custom_or_lps_page') && $lps_post_id > 0 && metasync_is_custom_or_lps_page($lps_post_id)) {
            return;
        }

        # Get supported post types (allow filtering)
        $post_types = $this->get_supported_post_types();
        $plugin_name = Metasync::get_effective_plugin_name();

        foreach ($post_types as $post_type) {
            add_meta_box(
                self::META_BOX_ID,
                sprintf(esc_html__('Social Media & Open Graph by %s', 'metasync'), $plugin_name),
                [$this, 'render_meta_box'],
                $post_type,
                'normal',
                'high'
            );
        }
    }

    /**
     * Render the meta box content
     */
    public function render_meta_box($post) {
        # Add nonce for security
        wp_nonce_field('metasync_opengraph_nonce', 'metasync_opengraph_nonce');

        # Get existing values. The four social title/description keys are read through
        # get_social_meta(), which collapses the "Auto Draft" placeholder, a stored
        # snapshot of the post title, and a stored snapshot of the resolved
        # description to '' — so a polluted legacy row shows an empty field (the
        # default lives in the placeholder) and re-saving clears it.
        $og_enabled = get_post_meta($post->ID, '_metasync_og_enabled', true);
        $og_title = self::get_social_meta($post->ID, '_metasync_og_title');
        $og_description = self::get_social_meta($post->ID, '_metasync_og_description');
        $og_image = get_post_meta($post->ID, '_metasync_og_image', true);
        $og_url = get_post_meta($post->ID, '_metasync_og_url', true);
        $og_type = get_post_meta($post->ID, '_metasync_og_type', true);
        
        # Twitter Card fields
        $twitter_card = get_post_meta($post->ID, '_metasync_twitter_card', true);
        $twitter_site = get_post_meta($post->ID, '_metasync_twitter_site', true);
        $twitter_title = self::get_social_meta($post->ID, '_metasync_twitter_title');
        $twitter_description = self::get_social_meta($post->ID, '_metasync_twitter_description');
        $twitter_image = get_post_meta($post->ID, '_metasync_twitter_image', true);
        $twitter_image_alt = get_post_meta($post->ID, '_metasync_twitter_image_alt', true);
        
        # Twitter App Card fields
        $twitter_app_id_iphone = get_post_meta($post->ID, '_metasync_twitter_app_id_iphone', true);
        $twitter_app_id_ipad = get_post_meta($post->ID, '_metasync_twitter_app_id_ipad', true);
        $twitter_app_id_googleplay = get_post_meta($post->ID, '_metasync_twitter_app_id_googleplay', true);
        $twitter_app_url_iphone = get_post_meta($post->ID, '_metasync_twitter_app_url_iphone', true);
        $twitter_app_url_ipad = get_post_meta($post->ID, '_metasync_twitter_app_url_ipad', true);
        $twitter_app_url_googleplay = get_post_meta($post->ID, '_metasync_twitter_app_url_googleplay', true);
        $twitter_app_country = get_post_meta($post->ID, '_metasync_twitter_app_country', true);
        
        # Twitter Player Card fields
        $twitter_player = get_post_meta($post->ID, '_metasync_twitter_player', true);
        $twitter_player_width = get_post_meta($post->ID, '_metasync_twitter_player_width', true);
        $twitter_player_height = get_post_meta($post->ID, '_metasync_twitter_player_height', true);

        # Set default values
        # Note: Check for empty string specifically, not just empty(), since '0' is a valid value
        if ($og_enabled === '') {
            # For new posts, default to enabled
            $og_enabled = '1';
        }
        # The post title is the default social title, but it is shown as a
        # placeholder, never as the field's value. Submitting a page carries
        # every value attribute to the save handler, so pre-filling the title
        # persisted a verbatim snapshot of it — one a later rename left stale
        # (and indistinguishable from a typed title). An empty field plus a
        # placeholder keeps the default visible while storing nothing, and the
        # render-time fallback to the live title tracks renames on its own.
        # social_post_title() suppresses WordPress's own "Auto Draft"
        # placeholder, so a brand-new post shows no misleading hint either.
        $title_placeholder = self::social_post_title($post);
        # What twitter:title actually renders when its own field is blank: the
        # OG title if one is set, otherwise the same live post title. Shown as
        # the twitter field's placeholder for the same reason as above.
        $twitter_title_placeholder = ($og_title !== '') ? $og_title : $title_placeholder;
        # The resolved description (the manual excerpt, or one generated from
        # the content) is the default social description, and like the title it
        # is shown as a placeholder, never as the field's value. Pre-filling it
        # persisted a snapshot of the excerpt as of save day — one a later
        # excerpt or content edit left stale, and indistinguishable from a
        # typed description. An empty field plus a placeholder keeps the
        # default visible while storing nothing, and the render-time fallback
        # to the live excerpt tracks edits on its own.
        $description_placeholder = $this->get_post_excerpt($post);
        # What twitter:description actually renders when its own field is
        # blank: the OG description if one is set, otherwise the same resolved
        # default. Shown as the twitter field's placeholder for the same
        # reason as above.
        $twitter_description_placeholder = ($og_description !== '') ? $og_description : $description_placeholder;
        if (empty($og_url)) {
            $og_url = $this->get_canonical_url($post);
        }
        if (empty($og_type)) {
            $og_type = 'article';
        }
        if (empty($og_image)) {
            $og_image = $this->get_featured_image_url($post->ID);
        }

        # Twitter defaults
        if (empty($twitter_card)) {
            $twitter_card = 'summary_large_image';
        }
        if (empty($twitter_image)) {
            $twitter_image = $og_image;
        }

        # Whether OTTO is managing this page's OG output (values below won't apply on the frontend)
        $otto_owns_og = $this->otto_owns_og($post->ID);

        # Include the meta box template
        include plugin_dir_path(__FILE__) . '../admin/partials/metasync-opengraph-meta-box.php';
    }

    /**
     * Save meta box data
     */
    public function save_meta_box_data($post_id) {
        # Drop the defaults memo before anything below can bail. This handler is
        # hooked to save_post, so it runs for EVERY save of the post — including
        # ones initiated by another meta box, a bulk action, or a sync flow that
        # carries no OG nonce and would have returned early above the old flush
        # position. Without this clear, a memo seeded earlier in the same request
        # (a metabox render, a sync read) survives the post-row write and serves
        # the pre-save title/excerpt to the snapshot comparisons in later
        # save_post subscribers — the Yoast re-sync on shutdown among them.
        self::clear_default_og_values_memo();

        # Check if nonce is valid
        if (!isset($_POST['metasync_opengraph_nonce']) ||
            !wp_verify_nonce($_POST['metasync_opengraph_nonce'], 'metasync_opengraph_nonce')) {
            return;
        }

        # Check if user has permission to edit
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        # Check if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        # Handle the checkbox field separately (unchecked checkboxes don't send POST data)
        # Meta title and description are always enabled by default
        if (isset($_POST['_metasync_og_enabled'])) {
            # User checked the box
            update_post_meta($post_id, '_metasync_og_enabled', '1');
        } else {
            # User unchecked the box
            update_post_meta($post_id, '_metasync_og_enabled', '0');
        }

        # Save Open Graph data (excluding the enabled field which is handled above)
        $og_fields = [
            '_metasync_og_title' => 'sanitize_text_field',
            '_metasync_og_description' => 'sanitize_textarea_field',
            '_metasync_og_image' => 'esc_url_raw',
            '_metasync_og_url' => 'esc_url_raw',
            '_metasync_og_type' => 'sanitize_text_field',
        ];

        # Save Twitter Card data
        $twitter_fields = [
            '_metasync_twitter_card' => 'sanitize_text_field',
            '_metasync_twitter_site' => 'sanitize_text_field',
            '_metasync_twitter_title' => 'sanitize_text_field',
            '_metasync_twitter_description' => 'sanitize_textarea_field',
            '_metasync_twitter_image' => 'esc_url_raw',
            '_metasync_twitter_image_alt' => 'sanitize_text_field',
        ];

        # Save Twitter App Card data
        $twitter_app_fields = [
            '_metasync_twitter_app_id_iphone' => 'sanitize_text_field',
            '_metasync_twitter_app_id_ipad' => 'sanitize_text_field',
            '_metasync_twitter_app_id_googleplay' => 'sanitize_text_field',
            '_metasync_twitter_app_url_iphone' => 'esc_url_raw',
            '_metasync_twitter_app_url_ipad' => 'esc_url_raw',
            '_metasync_twitter_app_url_googleplay' => 'esc_url_raw',
            '_metasync_twitter_app_country' => 'sanitize_text_field',
        ];

        # Save Twitter Player Card data
        $twitter_player_fields = [
            '_metasync_twitter_player' => 'esc_url_raw',
            '_metasync_twitter_player_width' => 'absint',
            '_metasync_twitter_player_height' => 'absint',
        ];

        $all_fields = array_merge($og_fields, $twitter_fields, $twitter_app_fields, $twitter_player_fields);

        foreach ($all_fields as $field => $sanitize_callback) {
            if (isset($_POST[$field])) {
                $value = call_user_func($sanitize_callback, $_POST[$field]);

                # The meta box pre-fills empty social title/description fields from the
                # post title, and on a brand-new post that title is the "Auto Draft"
                # placeholder. Persisting it ships "Auto Draft" as the post's social
                # title and description forever, so store empty instead and let the
                # render-time fallback chain resolve the real title once one is set.
                if (in_array($field, self::AUTO_DRAFT_PRONE_KEYS, true)
                    && $this->is_auto_draft_prefill_echo($post_id, $value)
                ) {
                    $value = '';
                }

                # A social title identical to the post's title is the default, not a
                # customization — whether the editor left an older pre-filled form
                # untouched or typed the title back verbatim. Store empty so the
                # render-time fallback to the live title applies, which is what keeps
                # a rename from leaving a stale snapshot behind. Text that differs
                # from the title passes through untouched. save_post fires after the
                # post row is written, so the comparison is against the *new* title.
                if (in_array($field, self::TITLE_DEFAULTED_KEYS, true)) {
                    $value = self::strip_title_snapshot($post_id, $field, $value);
                }

                # A social description identical to its rendered default — the
                # resolved excerpt the older pre-filled form carried, or the
                # same text typed back verbatim — is the default, not a
                # customization. Store empty so the render-time fallback to the
                # live excerpt applies, which is what keeps an excerpt or
                # content edit from leaving a stale snapshot behind. Text that
                # differs from the default passes through untouched. save_post
                # fires after the post row is written, so the comparison is
                # against the *new* excerpt and content.
                if (in_array($field, self::DESCRIPTION_DEFAULTED_KEYS, true)) {
                    $value = self::strip_description_snapshot($post_id, $field, $value);
                }

                # An empty value means "no value" for every field in this box,
                # and must not be stored as one: update_post_meta(..., '') keeps
                # a row with an empty meta_value in the table (visible only to
                # metadata_exists, EXISTS queries and exports, which would then
                # report a customization where the field is merely blank). The
                # plain read answers '' for a missing row too, so deleting is
                # behaviour-preserving for every reader. Same pattern the
                # untitled-flag write below has always used.
                if ($value === '') {
                    delete_post_meta($post_id, $field);
                } else {
                    update_post_meta($post_id, $field, $value);
                }
            }
        }
    }

    /**
     * Whether a submitted social field value is the meta box echoing back a
     * placeholder pre-fill rather than something the editor typed.
     *
     * Two cases, both narrow on purpose so genuinely typed text is never discarded:
     *   - the value is the "Auto Draft" placeholder itself; or
     *   - the post is still an auto-draft and the value is just its title echoed
     *     back, which is what the pre-fill submits when the editor never touched
     *     the field.
     *
     * @param int   $post_id
     * @param mixed $value Sanitized submitted value.
     * @return bool
     */
    /**
     * save_post tracker maintaining the untitled state this class suppresses by.
     *
     * Two jobs, both anchored at save time — the only place the "Auto Draft"
     * translation reliably resolves — so the render path never has to guess:
     *
     * 1. Capture: when core creates an auto-draft, its title *is*
     *    __( 'Auto Draft' ) in the creating request's locale (admin requests
     *    carry the creating user's locale). Verified against that same __()
     *    call here, then remembered verbatim, so every locale variant this
     *    install ever uses is known to all contexts.
     * 2. Flag: mark posts saved while still placeholder-titled (recognized via
     *    literal + translation + the registry above, so REST/CLI saves — where
     *    the translation stays English — still recognize a registry hit), and
     *    clear the mark as soon as a real title arrives.
     *
     * @param int          $post_id
     * @param WP_Post|mixed $post
     * @return void
     */
    public function track_untitled_post($post_id, $post) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!$post instanceof WP_Post) {
            $post = get_post($post_id);
        }
        if (!$post instanceof WP_Post || $post->post_type === 'revision' || $post->post_status === 'trash') {
            return;
        }
        if (!in_array($post->post_type, $this->get_supported_post_types(), true)) {
            return;
        }

        $title = trim((string) $post->post_title);

        # Capture: an auto-draft's title is core's placeholder in whatever
        # locale resolved for the saving request — admin requests carry the
        # creating user's locale; on untranslated requests (front end, REST,
        # CLI) the equality below degenerates to the English literal, which
        # remember_auto_draft_placeholder() already skips. Verified against
        # __( 'Auto Draft' ), never trusted as a bare string, so a builder's
        # custom-seeded auto-draft title is never captured.
        if ($post->post_status === 'auto-draft'
            && $title !== '' && $title === trim(__('Auto Draft'))) {
            self::remember_auto_draft_placeholder($title);
        }

        # Flag maintenance: recognition is context-independent thanks to the
        # registry (an untranslated REST/CLI save still matches a captured
        # variant), so the flag can never go stale in either direction.
        if (self::is_auto_draft_title($title)) {
            update_post_meta($post_id, self::UNTITLED_FLAG_META, $title);
        } else {
            delete_post_meta($post_id, self::UNTITLED_FLAG_META);
        }
    }

    private function is_auto_draft_prefill_echo($post_id, $value) {
        if (self::is_auto_draft_title($value)) {
            return true;
        }
        if (!is_string($value) || trim($value) === '') {
            return false;
        }
        $post = get_post($post_id);
        if (!$post instanceof WP_Post || trim($value) !== trim((string) $post->post_title)) {
            return false;
        }
        if (get_post_status($post_id) === 'auto-draft') {
            return true;
        }
        # State arm: the submitted value echoes a title that was flagged as the
        # placeholder at save time. Covers a value the string checks above
        # missed — e.g. a localized placeholder stored under an admin user
        # whose locale differs from the one that created the post.
        $flagged = get_post_meta($post_id, self::UNTITLED_FLAG_META, true);
        return is_string($flagged) && trim($flagged) !== '' && trim($flagged) === trim($value);
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        global $post_type;

        # Only load on post edit screens for supported post types
        if (!in_array($hook, ['post.php', 'post-new.php']) || 
            !in_array($post_type, $this->get_supported_post_types())) {
            return;
        }

        wp_enqueue_media();
        
        wp_enqueue_script(
            'metasync-opengraph-admin',
            plugin_dir_url(__FILE__) . '../admin/js/metasync-opengraph.js',
            ['jquery', 'wp-util'],
            $this->version,
            true
        );

        wp_enqueue_style(
            'metasync-opengraph-admin',
            plugin_dir_url(__FILE__) . '../admin/css/metasync-opengraph.css',
            [],
            $this->version
        );

        # Get the current post permalink for preview
        global $post;
        $current_permalink = '';
        if ($post && $post->ID) {
            $current_permalink = $this->get_canonical_url($post);
        }
        
        # Localize script for AJAX
        wp_localize_script('metasync-opengraph-admin', 'metasync_og', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('metasync_og_preview_nonce'),
            'current_permalink' => $current_permalink,
            'strings' => [
                'select_image' => esc_html__('Select Image', 'metasync'),
                'use_image' => esc_html__('Use This Image', 'metasync'),
                'remove_image' => esc_html__('Remove Image', 'metasync'),
            ]
        ]);
    }
    
    /**
     * Force enqueue scripts for post edit screens (backup method)
     */
    public function force_enqueue_scripts() {
        global $post_type;
        
        if (!in_array($post_type, $this->get_supported_post_types())) {
            return;
        }
        
        # Check if already enqueued
        if (wp_script_is('metasync-opengraph-admin', 'enqueued')) {
            return;
        }
        
        wp_enqueue_media();
        wp_enqueue_script(
            'metasync-opengraph-admin',
            plugin_dir_url(__FILE__) . '../admin/js/metasync-opengraph.js',
            ['jquery', 'wp-util'],
            $this->version,
            true
        );
        
        wp_enqueue_style(
            'metasync-opengraph-admin',
            plugin_dir_url(__FILE__) . '../admin/css/metasync-opengraph.css',
            [],
            $this->version
        );
        
        # Get the current post permalink for preview
        global $post;
        $current_permalink = '';
        if ($post && $post->ID) {
            $current_permalink = $this->get_canonical_url($post);
        }
        
        wp_localize_script('metasync-opengraph-admin', 'metasync_og', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('metasync_og_preview_nonce'),
            'current_permalink' => $current_permalink,
            'strings' => [
                'select_image' => esc_html__('Select Image', 'metasync'),
                'use_image' => esc_html__('Use This Image', 'metasync'),
                'remove_image' => esc_html__('Remove Image', 'metasync'),
            ]
        ]);
    }

    /**
     * Output Open Graph and Twitter Card tags in wp_head
     */
    public function output_opengraph_tags() {
        # Social Media & Open Graph switched off — emit nothing.
        if (Metasync_Feature_Flags::is_disabled(Metasync_Feature_Flags::SOCIAL_OG)) {
            return;
        }

        if (!is_singular($this->get_supported_post_types())) {
            return;
        }

        global $post;

        # Ensure post is a valid object
        if (!$post instanceof WP_Post) {
            return;
        }

        # Check if Open Graph is enabled for this post.
        # Only an explicit '0' opt-out suppresses output; unset/empty counts as enabled
        # so a MetaSync-only site gets one consolidated set whether or not the meta
        # box was ever saved. Must stay in sync with will_emit().
        if (self::is_social_output_disabled($post->ID)) {
            return;
        }

        # When OTTO owns this page's OG (enabled + persisted OG data), skip legacy
        # OG output. For cases where OTTO's pixel injects OG tags dynamically
        # (without persisting to _metasync_otto_og_* meta), the buffer-level dedup
        # in Otto_html_class::deduplicate_og_twitter_tags() handles cleanup.
        if ($this->otto_owns_og($post->ID)) {
            return;
        }

        # Check for conflicts with other SEO plugins (allow override via filter)
        if (apply_filters('metasync_opengraph_check_conflicts', true) && $this->has_seo_plugin_conflicts()) {
            return;
        }

        # Open Graph data. The tier order — what the customer set, then OTTO, then
        # a value brought in from another SEO plugin — comes from
        # Metasync_Seo_Precedence so this emitter and the conflict handler cannot
        # disagree about which value the page should carry. The resolver collapses a
        # stored "Auto Draft" placeholder, a snapshot of the post title, and a
        # snapshot of the resolved description to '' as it walks the chain, so a
        # row polluted by the meta box pre-fill falls through to the live
        # fallback (or OTTO's tier) rather than outranking it. The literal
        # fallbacks below the chain (post title, excerpt, featured image) stay
        # here: they are derived at render time, not stored.
        $og_title = Metasync_Seo_Precedence::value($post->ID, Metasync_Seo_Precedence::FIELD_OG_TITLE)
            ?: self::social_post_title($post);
        $og_description = Metasync_Seo_Precedence::value($post->ID, Metasync_Seo_Precedence::FIELD_OG_DESCRIPTION)
            ?: $this->get_post_excerpt($post);
        $og_image = Metasync_Seo_Precedence::value($post->ID, Metasync_Seo_Precedence::FIELD_OG_IMAGE)
            ?: $this->get_featured_image_url($post->ID);
        $canonical_override = '';
        if (class_exists('Metasync_Headless_Config') && Metasync_Headless_Config::is_active()) {
            # The same two-key chain the canonical tag itself resolves, so the
            # two cannot disagree. meta_canonical is not a legacy alias: the
            # REST sync and the classic-editor Canonical box both still write
            # it, and the GraphQL surface reads both keys as well.
            foreach (array('_metasync_canonical_url', 'meta_canonical') as $canonical_key) {
                $canonical_override = Metasync_Canonical_Sanitizer::sanitize(
                    get_post_meta($post->ID, $canonical_key, true)
                );
                if ($canonical_override !== '') {
                    break;
                }
            }
        }

        # A validated canonical override is the author's final answer. It may
        # deliberately name another host, so it bypasses headless rehosting.
        if ($canonical_override !== '') {
            $og_url = esc_url($canonical_override);
        } else {
            $og_url = get_post_meta($post->ID, '_metasync_og_url', true) ?: $this->get_canonical_url($post);

            # On a headless site the rendered page is not the public page, so
            # an own-host og:url must name the frontend. Applied at the single
            # emission source so update_opengraph_url() continues to persist
            # the WordPress URL used outside headless mode.
            $og_url = $this->maybe_build_headless_url($og_url, $post->post_type);
        }
        $og_type = get_post_meta($post->ID, '_metasync_og_type', true) ?: 'article';

        # Get Twitter Card data — check persisted key first, fall back to OTTO staging key
        $twitter_card = get_post_meta($post->ID, '_metasync_twitter_card', true) ?: 'summary_large_image';
        $twitter_site = get_post_meta($post->ID, '_metasync_twitter_site', true);

        # Fall back to the site-wide Twitter username (Social Meta settings) so the
        # twitter:site / twitter:creator tags the legacy emitter produced are not lost
        # now that this emitter is the single canonical OG/Twitter source
        $twitter_username = Metasync::get_option('social_meta')['twitter_username'] ?? '';
        if (empty($twitter_site) && !empty($twitter_username)) {
            $twitter_site = '@' . $twitter_username;
        }
        $twitter_creator = !empty($twitter_username) ? '@' . $twitter_username : '';
        $twitter_title = Metasync_Seo_Precedence::value($post->ID, Metasync_Seo_Precedence::FIELD_TWITTER_TITLE)
            ?: $og_title;
        $twitter_description = Metasync_Seo_Precedence::value($post->ID, Metasync_Seo_Precedence::FIELD_TWITTER_DESCRIPTION)
            ?: $og_description;
        $twitter_image = Metasync_Seo_Precedence::value($post->ID, Metasync_Seo_Precedence::FIELD_TWITTER_IMAGE)
            ?: $og_image;
        $twitter_image_alt = get_post_meta($post->ID, '_metasync_twitter_image_alt', true);

        # Resolve OG image attachment ID once for reuse (twitter:image:alt fallback + og:image dimensions)
        $og_image_attachment_id = 0;
        if (!empty($og_image)) {
            $og_image_attachment_id = attachment_url_to_postid($og_image);
        }

        # Fall back to the OG image's WP attachment alt text when no explicit twitter:image:alt is set
        if (empty($twitter_image_alt) && $og_image_attachment_id > 0) {
            $attachment_alt = get_post_meta($og_image_attachment_id, '_wp_attachment_image_alt', true);
            if (!empty($attachment_alt)) {
                $twitter_image_alt = $attachment_alt;
            }
        }

        # Per-field toggles from common_meta_settings (default enabled when unset)
        $common_meta_settings = Metasync::get_option('common_meta_settings');
        if (!is_array($common_meta_settings)) {
            $common_meta_settings = [];
        }
        $og_image_dimensions_enabled = ($common_meta_settings['og_image_dimensions'] ?? 'true') !== 'false';
        $twitter_image_alt_enabled   = ($common_meta_settings['twitter_image_alt'] ?? 'true') !== 'false';
        
        # Get Twitter App Card data
        $twitter_app_id_iphone = get_post_meta($post->ID, '_metasync_twitter_app_id_iphone', true);
        $twitter_app_id_ipad = get_post_meta($post->ID, '_metasync_twitter_app_id_ipad', true);
        $twitter_app_id_googleplay = get_post_meta($post->ID, '_metasync_twitter_app_id_googleplay', true);
        $twitter_app_url_iphone = get_post_meta($post->ID, '_metasync_twitter_app_url_iphone', true);
        $twitter_app_url_ipad = get_post_meta($post->ID, '_metasync_twitter_app_url_ipad', true);
        $twitter_app_url_googleplay = get_post_meta($post->ID, '_metasync_twitter_app_url_googleplay', true);
        $twitter_app_country = get_post_meta($post->ID, '_metasync_twitter_app_country', true);
        
        # Get Twitter Player Card data
        $twitter_player = get_post_meta($post->ID, '_metasync_twitter_player', true);
        $twitter_player_width = get_post_meta($post->ID, '_metasync_twitter_player_width', true);
        $twitter_player_height = get_post_meta($post->ID, '_metasync_twitter_player_height', true);

        # Output Open Graph tags
        echo "\n<!-- MetaSync Open Graph Tags -->\n";
        echo '<meta property="og:locale" content="' . esc_attr(get_locale()) . '">' . "\n";
        if ($og_title) {
            echo '<meta property="og:title" content="' . esc_attr($og_title) . '">' . "\n";
        }
        if ($og_description) {
            echo '<meta property="og:description" content="' . esc_attr($og_description) . '">' . "\n";
        }
        if ($og_image) {
            echo '<meta property="og:image" content="' . esc_url($og_image) . '">' . "\n";

            if ($og_image_dimensions_enabled) {
                $og_image_dims = $this->get_og_image_dimensions($og_image, $og_image_attachment_id);
                if (is_array($og_image_dims)) {
                    if (!empty($og_image_dims['width'])) {
                        echo '<meta property="og:image:width" content="' . esc_attr((string) $og_image_dims['width']) . '">' . "\n";
                    }
                    if (!empty($og_image_dims['height'])) {
                        echo '<meta property="og:image:height" content="' . esc_attr((string) $og_image_dims['height']) . '">' . "\n";
                    }
                    if (!empty($og_image_dims['mime'])) {
                        echo '<meta property="og:image:type" content="' . esc_attr($og_image_dims['mime']) . '">' . "\n";
                    }
                }
            }
        }
        if ($og_url) {
            echo '<meta property="og:url" content="' . esc_url($og_url) . '">' . "\n";
        }
        if ($og_type) {
            echo '<meta property="og:type" content="' . esc_attr($og_type) . '">' . "\n";
        }
        $site_name = get_bloginfo('name');
        if ($site_name) {
            echo '<meta property="og:site_name" content="' . esc_attr($site_name) . '">' . "\n";
        }
        if (!empty($post->post_modified)) {
            echo '<meta property="og:updated_time" content="' . esc_attr($post->post_modified) . '">' . "\n";
        }

        # Output Twitter Card tags
        echo "<!-- MetaSync Twitter Card Tags -->\n";
        if ($twitter_card) {
            echo '<meta name="twitter:card" content="' . esc_attr($twitter_card) . '">' . "\n";
        }
        if ($twitter_site) {
            echo '<meta name="twitter:site" content="' . esc_attr($twitter_site) . '">' . "\n";
        }
        if ($twitter_creator) {
            echo '<meta name="twitter:creator" content="' . esc_attr($twitter_creator) . '">' . "\n";
        }
        if ($twitter_title) {
            echo '<meta name="twitter:title" content="' . esc_attr($twitter_title) . '">' . "\n";
        }
        if ($twitter_description) {
            echo '<meta name="twitter:description" content="' . esc_attr($twitter_description) . '">' . "\n";
        }
        if ($twitter_image) {
            echo '<meta name="twitter:image" content="' . esc_url($twitter_image) . '">' . "\n";
        }
        if ($twitter_image_alt && $twitter_image_alt_enabled) {
            echo '<meta name="twitter:image:alt" content="' . esc_attr($twitter_image_alt) . '">' . "\n";
        }
        
        # Output Twitter App Card tags (only if card type is 'app')
        if ($twitter_card === 'app') {
            if ($twitter_app_id_iphone) {
                echo '<meta name="twitter:app:id:iphone" content="' . esc_attr($twitter_app_id_iphone) . '">' . "\n";
            }
            if ($twitter_app_id_ipad) {
                echo '<meta name="twitter:app:id:ipad" content="' . esc_attr($twitter_app_id_ipad) . '">' . "\n";
            }
            if ($twitter_app_id_googleplay) {
                echo '<meta name="twitter:app:id:googleplay" content="' . esc_attr($twitter_app_id_googleplay) . '">' . "\n";
            }
            if ($twitter_app_url_iphone) {
                echo '<meta name="twitter:app:url:iphone" content="' . esc_url($twitter_app_url_iphone) . '">' . "\n";
            }
            if ($twitter_app_url_ipad) {
                echo '<meta name="twitter:app:url:ipad" content="' . esc_url($twitter_app_url_ipad) . '">' . "\n";
            }
            if ($twitter_app_url_googleplay) {
                echo '<meta name="twitter:app:url:googleplay" content="' . esc_url($twitter_app_url_googleplay) . '">' . "\n";
            }
            if ($twitter_app_country) {
                echo '<meta name="twitter:app:country" content="' . esc_attr($twitter_app_country) . '">' . "\n";
            }
        }
        
        # Output Twitter Player Card tags (only if card type is 'player')
        if ($twitter_card === 'player') {
            if ($twitter_player) {
                echo '<meta name="twitter:player" content="' . esc_url($twitter_player) . '">' . "\n";
            }
            if ($twitter_player_width) {
                echo '<meta name="twitter:player:width" content="' . esc_attr($twitter_player_width) . '">' . "\n";
            }
            if ($twitter_player_height) {
                echo '<meta name="twitter:player:height" content="' . esc_attr($twitter_player_height) . '">' . "\n";
            }
        }
        
        echo "<!-- End MetaSync Social Media Tags -->\n\n";
    }

    /**
     * Resolve OG image dimensions + MIME without making remote HTTP calls.
     *
     * Returns an array with 'width', 'height', and 'mime' when available,
     * or null when no dimensions are known. For WP-hosted attachments the
     * data comes from attachment metadata. For external URLs we only read
     * a pre-seeded transient (metasync_og_img_dims_{md5(url)}).
     *
     * @param string $url
     * @param int    $attachment_id Pre-resolved attachment ID (0 = auto-detect).
     * @return array|null
     */
    private function get_og_image_dimensions($url, $attachment_id = 0) {
        if (empty($url) || !is_string($url)) {
            return null;
        }

        if ($attachment_id <= 0) {
            $attachment_id = attachment_url_to_postid($url);
        }
        if ($attachment_id > 0) {
            $meta = wp_get_attachment_metadata($attachment_id);
            $width = isset($meta['width']) ? (int) $meta['width'] : 0;
            $height = isset($meta['height']) ? (int) $meta['height'] : 0;
            $mime = get_post_mime_type($attachment_id) ?: '';
            if ($width > 0 || $height > 0 || $mime !== '') {
                return [
                    'width'  => $width,
                    'height' => $height,
                    'mime'   => $mime,
                ];
            }
            return null;
        }

        # External URLs: only read the pre-seeded transient, never make remote HTTP calls here.
        $cached = get_transient('metasync_og_img_dims_' . md5($url));
        if (is_array($cached)) {
            return [
                'width'  => isset($cached['width']) ? (int) $cached['width'] : 0,
                'height' => isset($cached['height']) ? (int) $cached['height'] : 0,
                'mime'   => isset($cached['mime']) ? (string) $cached['mime'] : '',
            ];
        }

        return null;
    }

    /**
     * Output article:* Open Graph tags for article-type singular views.
     *
     * Runs independently of has_seo_plugin_conflicts() so we can still emit
     * complete article metadata while other SEO plugins handle og:title/description.
     * Cross-plugin dedup is handled via register_dedup_filters() instead.
     */
    public function output_article_tags() {
        # article:* tags are part of the same Open Graph block, so they follow
        # the same switch as output_opengraph_tags().
        if (Metasync_Feature_Flags::is_disabled(Metasync_Feature_Flags::SOCIAL_OG)) {
            return;
        }

        if (!is_singular()) {
            return;
        }

        global $post;
        if (!$post instanceof WP_Post) {
            return;
        }

        if (self::is_social_output_disabled($post->ID)) {
            return;
        }

        $og_type = get_post_meta($post->ID, '_metasync_og_type', true) ?: 'article';
        if ($og_type !== 'article') {
            return;
        }

        $article_post_types = apply_filters('metasync_og_article_post_types', ['post']);
        if (!is_array($article_post_types) || !in_array($post->post_type, $article_post_types, true)) {
            return;
        }

        $settings = Metasync::get_option('common_meta_settings');
        if (!is_array($settings)) {
            $settings = [];
        }

        $article_timestamps_enabled = ($settings['article_timestamps'] ?? 'true') !== 'false';
        $article_author_enabled     = ($settings['article_author']     ?? 'true') !== 'false';
        $article_section_enabled    = ($settings['article_section']    ?? 'true') !== 'false';
        $article_tags_enabled       = ($settings['article_tags']       ?? 'true') !== 'false';

        echo "<!-- MetaSync Article Tags -->\n";

        # article:published_time / article:modified_time
        if ($article_timestamps_enabled) {
            if (!empty($post->post_date_gmt) && $post->post_date_gmt !== '0000-00-00 00:00:00') {
                $published_ts = strtotime($post->post_date_gmt);
                if ($published_ts) {
                    echo '<meta property="article:published_time" content="' . esc_attr(gmdate('c', $published_ts)) . '">' . "\n";
                }
            }
            if (!empty($post->post_modified_gmt) && $post->post_modified_gmt !== '0000-00-00 00:00:00') {
                $modified_ts = strtotime($post->post_modified_gmt);
                if ($modified_ts) {
                    echo '<meta property="article:modified_time" content="' . esc_attr(gmdate('c', $modified_ts)) . '">' . "\n";
                }
            }
        }

        # article:author
        if ($article_author_enabled) {
            $author_url = get_post_meta($post->ID, '_metasync_og_article_author', true);
            if (empty($author_url)) {
                $author_url = get_the_author_meta('url', $post->post_author);
            }
            if (empty($author_url)) {
                $author_url = get_author_posts_url($post->post_author);
            }
            if (!empty($author_url)) {
                echo '<meta property="article:author" content="' . esc_url($author_url) . '">' . "\n";
            }
        }

        # article:section – prefer explicit primary category, fall back to first category
        if ($article_section_enabled) {
            $section_name = '';
            $primary_category_id = (int) get_post_meta($post->ID, '_metasync_primary_category', true);
            if ($primary_category_id > 0) {
                $category = get_category($primary_category_id);
                if ($category && !is_wp_error($category) && !empty($category->name)) {
                    $section_name = $category->name;
                }
            }
            if (empty($section_name)) {
                $categories = get_the_category($post->ID);
                if (!empty($categories) && isset($categories[0]->name)) {
                    $section_name = $categories[0]->name;
                }
            }
            if (!empty($section_name)) {
                echo '<meta property="article:section" content="' . esc_attr($section_name) . '">' . "\n";
            }
        }

        # article:tag – one tag per WP post tag
        if ($article_tags_enabled) {
            $post_tags = get_the_tags($post->ID);
            if (!empty($post_tags) && !is_wp_error($post_tags)) {
                foreach ($post_tags as $tag) {
                    if (!empty($tag->name)) {
                        echo '<meta property="article:tag" content="' . esc_attr($tag->name) . '">' . "\n";
                    }
                }
            }
        }

        echo "<!-- End MetaSync Article Tags -->\n";
    }

    /**
     * Register cross-plugin dedup filters so Yoast / Rank Math don't double-emit
     * article:* tags alongside our own output.
     */
    private function register_dedup_filters() {
        # Ensure is_plugin_active() is available on the frontend too.
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $settings = Metasync::get_option('common_meta_settings');
        if (!is_array($settings)) {
            $settings = [];
        }

        $yoast_active = is_plugin_active('wordpress-seo/wp-seo.php')
            || is_plugin_active('wordpress-seo-premium/wp-seo-premium.php');
        $rank_math_active = is_plugin_active('seo-by-rank-math/rank-math.php');

        # The article:* tags these filters suppress are emitted by
        # output_article_tags(), which stands down when the Social Media & Open
        # Graph feature is switched off. Treat every article feature as disabled
        # in that case so the third party keeps rendering its own tags — pulling
        # ours without releasing theirs would leave the page with none.
        $social_enabled = Metasync_Feature_Flags::is_enabled(Metasync_Feature_Flags::SOCIAL_OG);

        $article_timestamps_enabled = $social_enabled && ($settings['article_timestamps'] ?? 'true') !== 'false';
        $article_author_enabled     = $social_enabled && ($settings['article_author']     ?? 'true') !== 'false';
        $article_section_enabled    = $social_enabled && ($settings['article_section']    ?? 'true') !== 'false';
        $article_tags_enabled       = $social_enabled && ($settings['article_tags']       ?? 'true') !== 'false';

        # Yoast: remove individual presenters based on which MetaSync features are enabled
        if ($yoast_active && ($article_timestamps_enabled || $article_author_enabled)) {
            add_filter('wpseo_frontend_presenters', function( $presenters ) use ( $article_timestamps_enabled, $article_author_enabled ) {
                $post_id = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
                if (self::is_social_output_disabled($post_id)) {
                    return $presenters;
                }
                foreach ( $presenters as $key => $presenter ) {
                    if ( $article_timestamps_enabled && (
                        $presenter instanceof \Yoast\WP\SEO\Presenters\Open_Graph\Article_Published_Time_Presenter ||
                        $presenter instanceof \Yoast\WP\SEO\Presenters\Open_Graph\Article_Modified_Time_Presenter
                    )) {
                        unset( $presenters[ $key ] );
                    }
                    if ( $article_author_enabled &&
                        $presenter instanceof \Yoast\WP\SEO\Presenters\Open_Graph\Article_Author_Presenter ) {
                        unset( $presenters[ $key ] );
                    }
                }
                return array_values( $presenters );
            }, 999 );
        }

        # Rank Math: suppress individual article:* tags via content filters.
        # Rank Math's tag() method passes content through rank_math/opengraph/facebook/{property}
        # where {property} is the OG property with colons replaced by underscores.
        # Returning false causes tag() to skip output (empty($content) check).
        if ($rank_math_active) {
            $keep_third_party = function ($value) {
                $post_id = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
                return self::is_social_output_disabled($post_id) ? $value : false;
            };
            if ($article_timestamps_enabled) {
                add_filter('rank_math/opengraph/facebook/article_published_time', $keep_third_party, 999);
                add_filter('rank_math/opengraph/facebook/article_modified_time', $keep_third_party, 999);
            }
            if ($article_tags_enabled) {
                add_filter('rank_math/opengraph/facebook/article_tag', $keep_third_party, 999);
            }
            if ($article_author_enabled) {
                add_filter('rank_math/opengraph/facebook/article_author', $keep_third_party, 999);
            }
            if ($article_section_enabled) {
                add_filter('rank_math/opengraph/facebook/article_section', $keep_third_party, 999);
            }
        }
    }

    /**
     * Hook the untitled-placeholder suppression into the title pipelines of
     * the SEO plugins that can own the <title>/og:title/twitter:title output
     * when MetaSync's own emitter stands down due to a conflict.
     *
     * Rank Math funnels <title>, og:title and twitter:title through one filter
     * (rank_math/frontend/title wraps Paper::get_title()), so one hook covers
     * all three. Yoast builds them from separate presenters, so it needs the
     * title filter plus the og/twitter title filters.
     *
     * The hooks are registered unconditionally: each only fires while its
     * plugin is actually generating a title, and the callback no-ops unless
     * the current post carries the untitled flag.
     *
     * @return void
     */
    private function register_untitled_title_filters() {
        add_filter('rank_math/frontend/title', [$this, 'strip_untitled_from_seo_title'], 99);
        add_filter('wpseo_title', [$this, 'strip_untitled_from_seo_title'], 99);
        add_filter('wpseo_opengraph_title', [$this, 'strip_untitled_from_seo_title'], 99);
        add_filter('wpseo_twitter_title', [$this, 'strip_untitled_from_seo_title'], 99);
    }

    /**
     * Remove the flagged placeholder from an SEO plugin's assembled title.
     *
     * The incoming value is the finished template output ("placeholder -
     * Site name"), so the placeholder segment is cut out and any dangling
     * separator tidied. An empty result (title was the placeholder alone)
     * cannot be returned as-is: both plugins treat an empty value as "do
     * nothing" and re-use their own generated title, so the site name is the
     * replacement of last resort.
     *
     * @param mixed $title Assembled title from the SEO plugin.
     * @return string
     */
    public function strip_untitled_from_seo_title($title) {
        if (!is_string($title) || trim($title) === '') {
            return $title;
        }
        $post = get_post();
        if (!$post instanceof WP_Post) {
            return $title;
        }
        $flagged = get_post_meta($post->ID, self::UNTITLED_FLAG_META, true);
        if (!is_string($flagged) || trim($flagged) === '') {
            return $title;
        }
        # Same state check as social_post_title(): only while the live title
        # still *is* the flagged placeholder.
        if (trim((string) $post->post_title) !== trim($flagged)) {
            return $title;
        }
        if (strpos($title, $flagged) === false) {
            return $title;
        }
        $remainder = trim(str_replace($flagged, '', $title));
        # Tidy the separator(s) that flanked the removed segment.
        $remainder = trim((string) preg_replace('/^[\s\-–—|·»•]+|[\s\-–—|·»•]+$/u', '', $remainder));
        if ($remainder === '') {
            $remainder = trim((string) get_bloginfo('name'));
        }
        if ($remainder === '') {
            # A space, not '': an empty return is a no-op for these filters.
            $remainder = ' ';
        }
        return $remainder;
    }

    /**
     * AJAX handler for generating social media preview
     */
    public function ajax_generate_preview() {
        
        try {
            # Check nonce
            if (!check_ajax_referer('metasync_og_preview_nonce', 'nonce', false)) {
                wp_send_json_error(['message' => 'Security check failed']);
                return;
            }

            # Get and sanitize data
            $title = sanitize_text_field($_POST['title'] ?? '');
            $description = sanitize_textarea_field($_POST['description'] ?? '');
            $image = esc_url_raw($_POST['image'] ?? '');
            $url = esc_url_raw($_POST['url'] ?? '');
            
            # Get Twitter Card data
            $twitter_title = sanitize_text_field($_POST['twitter_title'] ?? '');
            $twitter_description = sanitize_textarea_field($_POST['twitter_description'] ?? '');
            $twitter_image = esc_url_raw($_POST['twitter_image'] ?? '');

            # Generate preview HTML
            $preview_html = $this->generate_preview_html($title, $description, $image, $url, $twitter_title, $twitter_description, $twitter_image);
            
            if (empty($preview_html)) {
                wp_send_json_error(['message' => 'Failed to generate preview HTML']);
                return;
            }

            wp_send_json_success(['preview' => $preview_html]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Server error: ' . $e->getMessage()]);
        }
    }

    /**
     * Generate HTML for social media preview
     */
    private function generate_preview_html($title, $description, $image, $url, $twitter_title = '', $twitter_description = '', $twitter_image = '') {
        # Parse domain from URL
        $domain = '';
        if (!empty($url)) {
            $parsed = parse_url($url);
            $domain = $parsed['host'] ?? '';
        }
        
        # Fallback to site URL if no domain found
        if (empty($domain)) {
            $site_url = get_site_url();
            $parsed = parse_url($site_url);
            $domain = $parsed['host'] ?? 'your-site.com';
        }
        
        # Provide fallbacks for empty values
        if (empty($title)) {
            $title = 'Your Post Title';
        }
        if (empty($description)) {
            $description = 'Your post description will appear here when shared on social media platforms.';
        }
        
        # Use Twitter Card data for Twitter preview, fallback to Open Graph
        $twitter_display_title = !empty($twitter_title) ? $twitter_title : $title;
        $twitter_display_description = !empty($twitter_description) ? $twitter_description : $description;
        $twitter_display_image = !empty($twitter_image) ? $twitter_image : $image;

        # Get site name for avatars
        $site_name = get_bloginfo('name') ?: 'Your Site';
        $site_initial = strtoupper(substr($site_name, 0, 1));
        
        ob_start();
        ?>
        <div class="metasync-preview-tabs">
            <button class="metasync-preview-tab facebook active" data-platform="facebook">
                Facebook
            </button>
            <button class="metasync-preview-tab twitter" data-platform="twitter">
                Twitter/X
            </button>
            <button class="metasync-preview-tab linkedin" data-platform="linkedin">
                LinkedIn
            </button>
        </div>

        <div class="metasync-preview-content">
            <!-- Facebook Preview -->
            <div class="metasync-preview-panel facebook active" data-platform="facebook">
                <div class="facebook-preview">
                    <div class="facebook-post-header">
                        <div class="facebook-avatar"><?php echo esc_html($site_initial); ?></div>
                        <div class="facebook-post-info">
                            <h4><?php echo esc_html($site_name); ?></h4>
                            <p>2 hours ago • 🌍</p>
                        </div>
                    </div>
                    <div class="facebook-link-preview">
                        <?php if (!empty($image)): ?>
                            <div class="facebook-preview-image">
                                <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($title); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                <div class="preview-placeholder" style="display: none;">
                                    <span>📷</span>
                                    <p>Image failed to load</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="facebook-preview-image preview-no-image">
                                <div class="preview-placeholder">
                                    <span>📷</span>
                                    <p>No image selected</p>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="facebook-preview-content">
                            <div class="facebook-preview-domain"><?php echo esc_html(strtoupper($domain)); ?></div>
                            <div class="facebook-preview-title"><?php echo esc_html($title); ?></div>
                            <div class="facebook-preview-description"><?php echo esc_html($description); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Twitter Preview -->
            <div class="metasync-preview-panel twitter" data-platform="twitter">
                <div class="twitter-preview">
                    <div class="twitter-post-header">
                        <div class="twitter-avatar"><?php echo esc_html($site_initial); ?></div>
                        <div class="twitter-user-info">
                            <h4><?php echo esc_html($site_name); ?></h4>
                            <p>@<?php echo esc_html(strtolower(str_replace(' ', '', $site_name ?? ''))); ?> • 2h</p>
                        </div>
                    </div>
                    <div class="twitter-post-text">
                        Check out this amazing content! 🚀
                    </div>
                    <div class="twitter-card">
                        <?php if (!empty($twitter_display_image)): ?>
                            <div class="twitter-card-image">
                                <img src="<?php echo esc_url($twitter_display_image); ?>" alt="<?php echo esc_attr($twitter_display_title); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                <div class="preview-placeholder" style="display: none;">
                                    <span>📷</span>
                                    <p>Image failed to load</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="twitter-card-image preview-no-image">
                                <div class="preview-placeholder">
                                    <span>📷</span>
                                    <p>No image selected</p>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="twitter-card-content">
                            <div class="twitter-card-domain"><?php echo esc_html($domain); ?></div>
                            <div class="twitter-card-title"><?php echo esc_html($twitter_display_title); ?></div>
                            <div class="twitter-card-description"><?php echo esc_html($twitter_display_description); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- LinkedIn Preview -->
            <div class="metasync-preview-panel linkedin" data-platform="linkedin">
                <div class="linkedin-preview">
                    <div class="linkedin-post-header">
                        <div class="linkedin-avatar"><?php echo esc_html($site_initial); ?></div>
                        <div class="linkedin-user-info">
                            <h4><?php echo esc_html($site_name); ?></h4>
                            <p>2 hours ago</p>
                        </div>
                    </div>
                    <div class="linkedin-link-preview">
                        <?php if (!empty($image)): ?>
                            <div class="linkedin-preview-image">
                                <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($title); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                <div class="preview-placeholder" style="display: none;">
                                    <span>📷</span>
                                    <p>Image failed to load</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="linkedin-preview-image preview-no-image">
                                <div class="preview-placeholder">
                                    <span>📷</span>
                                    <p>No image selected</p>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="linkedin-preview-content">
                            <div class="linkedin-preview-title"><?php echo esc_html($title); ?></div>
                            <div class="linkedin-preview-description"><?php echo esc_html($description); ?></div>
                            <div class="linkedin-preview-domain"><?php echo esc_html($domain); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get post excerpt for Open Graph description
     */
    private function get_post_excerpt($post) {
        if (!empty($post->post_excerpt)) {
            return $post->post_excerpt;
        }

        # Generate excerpt from content
        $content = $post->post_content;

        # Do NOT run do_shortcode()/apply_filters('the_content') here.
        # This method runs on wp_head (priority 5, before the body renders) to build
        # og:description. On page-builder pages (Elementor, etc.) the_content fully
        # renders the page — including widgets like Elementor Loop Grid — which makes
        # the builder mark those widgets' per-request inline CSS as "already printed".
        # When the real widget renders later in the body, the builder's dedup then
        # OMITS its inline <style> (e.g. <style id="loop-NNNN"> carrying the loop
        # card's flex/width vars), collapsing the layout (stacked cards, full-width
        # images). We only need plain text for a meta description, so strip instead of
        # render — matching how Metasync_Seo_Output builds its description safely.
        $content = strip_shortcodes($content);

        # Page builders (Divi, Elementor, WPBakery) store content as shortcodes.
        # do_shortcode() only renders shortcodes whose handlers are registered, and when
        # this runs server-side (REST/cron/CLI) or before the builder loads the [et_pb_*]
        # tags are never expanded. strip_shortcodes() only removes *registered* shortcodes
        # too, so any leftover shortcode-style tags are removed by pattern below — otherwise
        # raw builder markup leaks into the og:description.
        $content = $this->strip_shortcode_markup($content);

        # Remove HTML tags to get clean text
        $content = wp_strip_all_tags($content);

        # Remove extra whitespace, line breaks, and special characters
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);

        # If content is still empty or too short, fallback to post title. A brand-new
        # post has no content and its title is the "Auto Draft" placeholder, which
        # must not become the og:/twitter:description — strip it so the caller's
        # fallback chain resolves to something real instead.
        if (empty($content) || strlen($content) < 20) {
            $content = self::social_post_title($post);
        }

        if ($content === '') {
            return '';
        }

        # Generate excerpt
        $excerpt = wp_trim_words($content, 30, '...');

        return $excerpt;
    }

    /**
     * Strip shortcode markup from a string.
     *
     * Removes registered shortcodes via strip_shortcodes(), then strips any
     * leftover shortcode-style tags (e.g. unregistered page-builder tags such
     * as [et_pb_section ...] / [/et_pb_section]) by pattern. The pattern is
     * anchored to a leading letter so legitimate bracketed prose like
     * "[2026 Guide]" is preserved.
     *
     * @param string $content
     * @return string
     */
    private function strip_shortcode_markup($content) {
        if (empty($content) || !is_string($content)) {
            return (string) $content;
        }

        $content = strip_shortcodes($content);
        $content = preg_replace('/\[\/?[a-zA-Z][^\]]*\]/', '', $content);

        return $content;
    }

    /**
     * Get featured image URL
     */
    private function get_featured_image_url($post_id) {
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $image_url = wp_get_attachment_image_url($thumbnail_id, 'large');
            return $image_url;
        }
        return '';
    }

    /**
     * Get supported post types
     */
    public function get_supported_post_types() {
        $post_types = array_values(get_post_types(['public' => true], 'names'));
        $post_types = array_diff($post_types, ['attachment']);
        return apply_filters('metasync_opengraph_post_types', $post_types);
    }

    /**
     * Add debug menu for testing
     */
    private function has_seo_plugin_conflicts() {
        // Ensure is_plugin_active() is available on the frontend
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        # List of SEO plugins that might output Open Graph tags
        $seo_plugins = [
            'wordpress-seo/wp-seo.php', # Yoast SEO
            'seo-by-rank-math/rank-math.php', # RankMath
            'all-in-one-seo-pack/all_in_one_seo_pack.php', # AIOSEO Free
            'all-in-one-seo-pack-pro/all_in_one_seo_pack.php', # AIOSEO Pro
            'seopress/seopress.php', # SEOPress
            'the-seo-framework/autodescription.php', # The SEO Framework
        ];

        foreach ($seo_plugins as $plugin) {
            if (is_plugin_active($plugin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a specific SEO plugin is handling Open Graph for current post
     */
    private function seo_plugin_has_og_data($post_id) {
        # Check if Yoast SEO has Open Graph data
        if (is_plugin_active('wordpress-seo/wp-seo.php')) {
            $yoast_title = get_post_meta($post_id, '_yoast_wpseo_title', true);
            $yoast_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
            if (!empty($yoast_title) || !empty($yoast_desc)) {
                return true;
            }
        }

        # Check if RankMath has Open Graph data
        if (is_plugin_active('seo-by-rank-math/rank-math.php')) {
            $rm_title = get_post_meta($post_id, 'rank_math_title', true);
            $rm_desc = get_post_meta($post_id, 'rank_math_description', true);
            if (!empty($rm_title) || !empty($rm_desc)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the canonical URL for a post
     */
    public function get_canonical_url($post) {
        # Try to get the permalink using WordPress function
        $permalink = get_permalink($post->ID);

        # If permalink is not available or is the default query URL, try alternative methods
        if (!$permalink || strpos($permalink, '?p=') !== false || strpos($permalink, '?page_id=') !== false) {
            # Force WordPress to generate the proper permalink by temporarily setting post status
            $original_status = $post->post_status;
            if ($post->post_status === 'auto-draft') {
                $post->post_status = 'publish';
            }

            # Try get_permalink again with the updated status
            $permalink = get_permalink($post->ID);

            # Restore original status
            $post->post_status = $original_status;
        }

        # If still not working, use WordPress core functions to build proper permalink
        if (!$permalink || strpos($permalink, '?p=') !== false || strpos($permalink, '?page_id=') !== false) {
            # Use WordPress core function that respects permalink structure
            # This properly handles custom structures, hierarchies, and post types
            # Load admin function if not already available
            if (!function_exists('get_sample_permalink')) {
                require_once ABSPATH . 'wp-admin/includes/post.php';
            }
            $permalink = get_sample_permalink($post->ID);

            if (is_array($permalink)) {
                # get_sample_permalink returns array with template and slug
                # Replace %postname% or %pagename% with actual slug
                $permalink = str_replace(
                    array('%pagename%', '%postname%'),
                    $post->post_name,
                    $permalink[0]
                );
            }

            # Final fallback: if still problematic, construct URL respecting post type structure
            if (!$permalink || strpos($permalink, '?p=') !== false || strpos($permalink, '?page_id=') !== false) {
                if (!empty($post->post_name)) {
                    # For pages, check if there's a parent hierarchy
                    if ($post->post_type === 'page' && $post->post_parent) {
                        # Get parent page path for proper hierarchy
                        $parent = get_post($post->post_parent);
                        $parent_path = '';

                        # Build full path including all parent pages
                        while ($parent) {
                            $parent_path = $parent->post_name . '/' . $parent_path;
                            $parent = $parent->post_parent ? get_post($parent->post_parent) : null;
                        }

                        $permalink = home_url('/' . $parent_path . $post->post_name . '/');
                    } else {
                        # For posts and pages without parents, use post type archive base
                        $post_type_obj = get_post_type_object($post->post_type);
                        $slug = $post_type_obj->rewrite['slug'] ?? '';

                        if ($slug && $post->post_type !== 'page') {
                            $permalink = home_url('/' . $slug . '/' . $post->post_name . '/');
                        } else {
                            $permalink = home_url('/' . $post->post_name . '/');
                        }
                    }
                } else {
                    # Fallback to post ID format if no slug available
                    $permalink = home_url('/?p=' . $post->ID);
                }
            }
        }

        return $permalink;
    }

    /**
     * Rehost a URL onto the public frontend when headless mode is active.
     *
     * Unlike the derived-URL callers elsewhere, the value handed here can be a
     * stored one an editor typed, which may legitimately point at a third-party
     * host. Only a URL on this site's own host is rewritten; anything else is
     * returned untouched. A builder failure is returned as an empty string so
     * the emitter cannot advertise the WordPress host on an active headless site.
     *
     * @param mixed  $url       URL to consider rehosting.
     * @param string $post_type Post type, for the frontend path prefix.
     * @return mixed Original URL when inactive or foreign; public URL or empty
     *               string for an own-host URL while active.
     */
    private function maybe_build_headless_url($url, $post_type = '') {
        if (!class_exists('Metasync_Headless_Config') || !Metasync_Headless_Config::is_active() || !$this->is_own_host_url($url)) {
            return $url;
        }

        return Metasync_Headless_Url_Builder::from_wp_url($url, $post_type);
    }

    /**
     * Whether a URL addresses this WordPress site rather than a foreign host.
     *
     * Accepts only HTTP(S) URLs on the exact site host, protocol-relative URLs
     * on that host, and genuine site-relative paths beginning with one slash.
     *
     * @param mixed $url URL to test.
     * @return bool
     */
    private function is_own_host_url($url) {
        if (!is_string($url) || $url === '' || preg_match('/\s/', $url)) {
            return false;
        }

        if ($url[0] === '/' && (!isset($url[1]) || $url[1] !== '/')) {
            return true;
        }

        $is_protocol_relative = strpos($url, '//') === 0;
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        if (!$is_protocol_relative && !in_array($scheme, array('http', 'https'), true)) {
            return false;
        }

        $url_host = wp_parse_url($url, PHP_URL_HOST);
        $site_host = wp_parse_url(home_url('/'), PHP_URL_HOST);

        return !empty($url_host)
            && !empty($site_host)
            && strcasecmp((string) $url_host, (string) $site_host) === 0;
    }

    /**
     * Update OpenGraph URL when post is saved
     */
    public function update_opengraph_url($post_id) {
        # Only update for supported post types
        if (!in_array(get_post_type($post_id), $this->get_supported_post_types())) {
            return;
        }

        # Skip autosaves and revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        # Get the post object
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        # Check if OpenGraph is enabled
        $og_enabled = get_post_meta($post_id, '_metasync_og_enabled', true);
        if (empty($og_enabled) || $og_enabled !== '1') {
            return;
        }

        # Get the current OpenGraph URL
        $current_og_url = get_post_meta($post_id, '_metasync_og_url', true);
        
        # Generate the proper canonical URL
        $canonical_url = $this->get_canonical_url($post);
        
        # Update the OpenGraph URL for new posts or if it's empty/incorrect
        # This ensures the URL is populated after first save (even as draft)
        if (empty($current_og_url) || 
            strpos($current_og_url, '?p=') !== false) {
            
            update_post_meta($post_id, '_metasync_og_url', $canonical_url);
        }
    }

    /**
     * Check if post permalink changed and update og:url if needed
     */
    public function check_permalink_change($post_id, $post_after, $post_before) {
        # Only check for supported post types
        if (!in_array(get_post_type($post_id), $this->get_supported_post_types())) {
            return;
        }

        # Skip autosaves and revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        # Check if OpenGraph is enabled
        $og_enabled = get_post_meta($post_id, '_metasync_og_enabled', true);
        if (empty($og_enabled) || $og_enabled !== '1') {
            return;
        }

        # Get current og:url
        $current_og_url = get_post_meta($post_id, '_metasync_og_url', true);
        if (empty($current_og_url)) {
            return;
        }

        # Check if the permalink actually changed by comparing post_name (slug)
        if ($post_before->post_name === $post_after->post_name) {
            return;
        }

        # Generate the old and new permalinks
        $old_permalink = $this->get_canonical_url($post_before);
        $new_permalink = $this->get_canonical_url($post_after);
        
        # If permalinks are the same, no need to update
        if ($old_permalink === $new_permalink) {
            return;
        }

        # Check if the current og:url matches the old permalink
        # This means the og:url was set to the post permalink (not a custom URL)
        if ($current_og_url === $old_permalink) {
            # Update og:url to the new permalink
            update_post_meta($post_id, '_metasync_og_url', $new_permalink);
        }
    }

    /**
     * Check if post status changed and update og:url if needed
     */
    public function check_status_change($new_status, $old_status, $post) {
        # Only check for supported post types
        if (!in_array(get_post_type($post->ID), $this->get_supported_post_types())) {
            return;
        }

        # Skip autosaves and revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post->ID)) {
            return;
        }

        # Only check when transitioning to published status
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }

        # Check if OpenGraph is enabled
        $og_enabled = get_post_meta($post->ID, '_metasync_og_enabled', true);
        if (empty($og_enabled) || $og_enabled !== '1') {
            return;
        }

        # Get current og:url
        $current_og_url = get_post_meta($post->ID, '_metasync_og_url', true);
        
        # Generate the current permalink
        $current_permalink = $this->get_canonical_url($post);
        
        # If og:url is empty or matches the old format, update it
        if (empty($current_og_url) || strpos($current_og_url, '?p=') !== false) {
            update_post_meta($post->ID, '_metasync_og_url', $current_permalink);
        }
    }

    /**
     * Check if post slug changed via edit slug functionality
     */
    public function check_slug_change() {
        # Get the post ID from the request
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id) {
            return;
        }

        # Only check for supported post types
        if (!in_array(get_post_type($post_id), $this->get_supported_post_types())) {
            return;
        }

        # Check if OpenGraph is enabled
        $og_enabled = get_post_meta($post_id, '_metasync_og_enabled', true);
        if (empty($og_enabled) || $og_enabled !== '1') {
            return;
        }

        # Get current og:url
        $current_og_url = get_post_meta($post_id, '_metasync_og_url', true);
        if (empty($current_og_url)) {
            return;
        }

        # Get the post object
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        # Generate the current permalink
        $current_permalink = $this->get_canonical_url($post);
        
        # Check if the current og:url matches the old permalink format
        # This means the og:url was set to the post permalink (not a custom URL)
        if ($current_og_url !== $current_permalink && strpos($current_og_url, '?p=') === false) {
            # Check if the og:url was the old permalink by comparing with a generated old permalink
            $old_post = clone $post;
            $old_slug = isset($_POST['new_slug']) ? sanitize_title($_POST['new_slug']) : $post->post_name;
            
            # If the og:url doesn't match the current permalink, it might be the old one
            # We'll update it to the new permalink
            if ($current_og_url !== $current_permalink) {
                update_post_meta($post_id, '_metasync_og_url', $current_permalink);
            }
        }
    }
}

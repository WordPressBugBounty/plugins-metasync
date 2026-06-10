<?php
/**
 * MetaSync Clone Meta Cleaner
 *
 * Strips all OTTO-prefixed AND social (Open Graph / Twitter Card) post meta
 * from a WordPress post when it has been cloned/duplicated by Duplicate
 * Post, Yoast Duplicate Post, or any `wp_insert_post` flow that carries
 * clone-context signals. This prevents stale OTTO data from overriding fresh
 * SEO values on the new page before OTTO has crawled the new URL, and
 * ensures social preview meta starts blank on each duplicate (WP-406 /
 * WP-407).
 *
 * The class name is preserved for backwards compatibility with existing
 * autoload paths even though it now covers more than OTTO.
 *
 * @package    Metasync
 * @subpackage Otto
 */

if (!defined('ABSPATH')) {
    exit; # Exit if accessed directly
}

class Metasync_Otto_Clone_Meta_Cleaner {

    /**
     * The complete list of OTTO-prefixed meta keys that must be removed
     * from a cloned post. Kept as a single source of truth so future OTTO
     * keys can be added in one place.
     */
    const OTTO_META_KEYS = [
        '_metasync_otto_title',
        '_metasync_otto_description',
        '_metasync_otto_keywords',
        '_metasync_otto_og_title',
        '_metasync_otto_og_description',
        '_metasync_otto_twitter_title',
        '_metasync_otto_twitter_description',
        '_metasync_otto_structured_data',
        '_metasync_otto_image_alt_data',
        '_metasync_otto_headings_data',
        '_metasync_otto_last_update',
    ];

    /**
     * The complete list of per-post social (Open Graph + Twitter Card) meta
     * keys that must also be removed from a cloned post. These are unique
     * per post (titles, descriptions, image URLs, media-specific player
     * URLs, etc.) so they MUST NOT inherit from the source post on
     * duplication. Mirrors every per-post `_metasync_og_*` /
     * `_metasync_twitter_*` key persisted by
     * `includes/class-metasync-opengraph.php`.
     *
     * If a new per-post OG/Twitter meta key is added there, mirror it here
     * to keep the clone-cleanup honest.
     */
    const SOCIAL_META_KEYS = [
        # Open Graph (per-post toggle + core fields + article author)
        '_metasync_og_enabled',
        '_metasync_og_title',
        '_metasync_og_description',
        '_metasync_og_image',
        '_metasync_og_url',
        '_metasync_og_type',
        '_metasync_og_article_author',

        # Twitter Card — core
        '_metasync_twitter_card',
        '_metasync_twitter_site',
        '_metasync_twitter_title',
        '_metasync_twitter_description',
        '_metasync_twitter_image',
        '_metasync_twitter_image_alt',

        # Twitter App Card
        '_metasync_twitter_app_id_iphone',
        '_metasync_twitter_app_id_ipad',
        '_metasync_twitter_app_id_googleplay',
        '_metasync_twitter_app_url_iphone',
        '_metasync_twitter_app_url_ipad',
        '_metasync_twitter_app_url_googleplay',
        '_metasync_twitter_app_country',

        # Twitter Player Card (per-post media player URL & dimensions)
        '_metasync_twitter_player',
        '_metasync_twitter_player_width',
        '_metasync_twitter_player_height',
    ];

    /**
     * Remove every OTTO and social meta key from a duplicated post.
     *
     * @param int $new_post_id The duplicated post's ID.
     */
    public function strip_clone_meta($new_post_id) {
        if (empty($new_post_id)) {
            return;
        }
        foreach (self::OTTO_META_KEYS as $key) {
            delete_post_meta($new_post_id, $key);
        }
        foreach (self::SOCIAL_META_KEYS as $key) {
            delete_post_meta($new_post_id, $key);
        }
    }

    /**
     * Backwards-compatible alias. Earlier versions of the plugin only
     * stripped OTTO meta; external callers that hooked this method name
     * directly continue to work. Internal hooks now register
     * `strip_clone_meta()` instead so the name reflects what actually runs.
     *
     * @param int $new_post_id The duplicated post's ID.
     */
    public function strip_otto_meta($new_post_id) {
        $this->strip_clone_meta($new_post_id);
    }

    /**
     * Fallback for plugins that don't fire the dedicated duplicate hooks.
     * Only strips meta when the post carries a clone-context marker — the
     * `_dp_original` meta written by Duplicate Post or the
     * `_yoast_wpseo_duplicate_source` meta written by Yoast Duplicate Post.
     *
     * Also handles "Duplicate Page" by mndpsingh287, which fires NO custom
     * action hook AND sets no marker meta — it just calls wp_insert_post()
     * and then copies meta in a loop afterwards. The only reliable signal
     * is its admin URL action `dt_duplicate_post_as_draft`. Since the
     * meta-copy loop runs AFTER wp_insert_post() returns, we defer the
     * cleanup to `shutdown` (which still fires after wp_redirect+exit), by
     * which point the plugin's meta-copy loop has completed.
     *
     * Skipped for normal updates so regular post saves are unaffected.
     *
     * @param int     $post_id Inserted post ID.
     * @param WP_Post $post    Inserted post object.
     * @param bool    $update  True when updating an existing post.
     */
    public function maybe_strip_on_insert($post_id, $post, $update) {
        if ($update) {
            return;
        }

        # Duplicate Post / Yoast Duplicate Post — marker meta is already set
        # by the time wp_insert_post() fires (they pass meta_input or set it
        # via the duplicator routine before the hook fires).
        $dp_original   = get_post_meta($post_id, '_dp_original', true);
        $yoast_source  = get_post_meta($post_id, '_yoast_wpseo_duplicate_source', true);
        if (!empty($dp_original) || !empty($yoast_source)) {
            $this->strip_clone_meta($post_id);
            return;
        }

        # Duplicate Page (mndpsingh287) — detect via the admin URL action.
        # Defer cleanup to shutdown so the plugin's post-insert meta-copy
        # loop has completed by the time we delete.
        $action = isset($_GET['action'])
            ? sanitize_text_field(wp_unslash($_GET['action']))
            : '';
        if ($action === 'dt_duplicate_post_as_draft') {
            $cleaner = $this;
            add_action('shutdown', function () use ($cleaner, $post_id) {
                $cleaner->strip_clone_meta($post_id);
            });
        }
    }

    /**
     * Register the duplicate-post hooks. Called once during plugin bootstrap.
     *
     * NOTE: Hooks are deliberately registered against `strip_otto_meta`
     * (the backwards-compatible alias) rather than `strip_clone_meta` so
     * that any external code which previously did:
     *
     *     remove_action('dp_duplicate_post', [$instance, 'strip_otto_meta'], 10);
     *
     * continues to match WordPress's callback registry. The alias just
     * delegates to `strip_clone_meta()`, so behaviour is identical.
     */
    public static function register() {
        $instance = new self();
        add_action('dp_duplicate_post', [$instance, 'strip_otto_meta'], 10, 1);
        add_action('dp_duplicate_page', [$instance, 'strip_otto_meta'], 10, 1);
        add_action('wpseo_duplicate_post', [$instance, 'strip_otto_meta'], 10, 1);
        add_action('wp_insert_post', [$instance, 'maybe_strip_on_insert'], 99, 3);
    }
}

<?php
/**
 * MetaSync OTTO Clone Meta Cleaner
 *
 * Strips all OTTO-prefixed post meta from a WordPress post when it has been
 * cloned/duplicated by Duplicate Post, Yoast Duplicate Post, or any
 * `wp_insert_post` flow that carries clone-context signals. This prevents
 * stale OTTO data from overriding fresh SEO values on the new page before
 * OTTO has crawled the new URL. See WP-283.
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
     * Remove every OTTO meta key from a post.
     *
     * @param int $new_post_id The duplicated post's ID.
     */
    public function strip_otto_meta($new_post_id) {
        if (empty($new_post_id)) {
            return;
        }
        foreach (self::OTTO_META_KEYS as $key) {
            delete_post_meta($new_post_id, $key);
        }
    }

    /**
     * Fallback for plugins that don't fire the dedicated duplicate hooks.
     * Only strips meta when the post carries a clone-context marker — the
     * `_dp_original` meta written by Duplicate Post or the
     * `_yoast_wpseo_duplicate_source` meta written by Yoast Duplicate Post.
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
        $dp_original   = get_post_meta($post_id, '_dp_original', true);
        $yoast_source  = get_post_meta($post_id, '_yoast_wpseo_duplicate_source', true);
        if (!empty($dp_original) || !empty($yoast_source)) {
            $this->strip_otto_meta($post_id);
        }
    }

    /**
     * Register the duplicate-post hooks. Called once during plugin bootstrap.
     */
    public static function register() {
        $instance = new self();
        add_action('dp_duplicate_post', [$instance, 'strip_otto_meta'], 10, 1);
        add_action('dp_duplicate_page', [$instance, 'strip_otto_meta'], 10, 1);
        add_action('wpseo_duplicate_post', [$instance, 'strip_otto_meta'], 10, 1);
        add_action('wp_insert_post', [$instance, 'maybe_strip_on_insert'], 99, 3);
    }
}

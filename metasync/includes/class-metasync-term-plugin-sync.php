<?php
/**
 * Term-Level SEO Plugin Sync
 *
 * Mirrors MetaSync term meta (`_metasync_*`) into the active third-party
 * SEO plugins' term storage (Yoast, Rank Math, AIOSEO) so that category,
 * tag, and custom-taxonomy archive pages render MetaSync-managed values
 * regardless of which plugin is actually rendering the archive.
 *
 * @package    MetaSync
 * @subpackage MetaSync/includes
 * @since      2.8.24
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Term_Plugin_Sync {

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static $instance = null;

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
     * Propagate MetaSync term meta to every active SEO plugin.
     *
     * Canonical $data keys recognised by this method:
     *   title, desc, og_title, og_desc, og_image,
     *   twitter_title, twitter_desc, canonical, noindex
     *
     * Callers may include any subset; empty values are skipped by each
     * plugin-specific sync method so a partial update does not clobber
     * fields that were not passed in.
     *
     * @param int    $term_id  Term ID.
     * @param string $taxonomy Taxonomy slug (unused by the plugins but kept
     *                         in the signature for future use / logging).
     * @param array  $data     Canonical key/value pairs.
     * @return array Results keyed by plugin: ['yoast'=>bool,'rankmath'=>bool,'aioseo'=>bool].
     */
    public function sync_term($term_id, $taxonomy, array $data) {
        // Explicit static recursion guard — prevents re-entrant calls for the same
        // term (e.g. if a term_meta hook triggers another sync_term() call).
        static $syncing = [];
        if (!empty($syncing[$term_id])) {
            return [];
        }
        $syncing[$term_id] = true;

        try {
            $results = [];

            if ($term_id <= 0 || empty($data)) {
                return $results;
            }

            if ($this->is_yoast_active()) {
                $results['yoast'] = $this->sync_yoast((int) $term_id, $taxonomy, $data);
            }

            if ($this->is_rankmath_active()) {
                $results['rankmath'] = $this->sync_rankmath((int) $term_id, $data);
            }

            if ($this->is_aioseo_active()) {
                $results['aioseo'] = $this->sync_aioseo((int) $term_id, $data);
            }

            return $results;
        } finally {
            unset($syncing[$term_id]);
        }
    }

    // ------------------------------------------------------------------
    // Plugin detectors
    // ------------------------------------------------------------------

    /**
     * Check whether Yoast SEO (free or premium) is active.
     *
     * @return bool
     */
    private function is_yoast_active() {
        $this->ensure_plugin_api();
        return is_plugin_active('wordpress-seo/wp-seo.php')
            || is_plugin_active('wordpress-seo-premium/wp-seo-premium.php');
    }

    /**
     * Check whether Rank Math SEO is active.
     *
     * @return bool
     */
    private function is_rankmath_active() {
        $this->ensure_plugin_api();
        return is_plugin_active('seo-by-rank-math/rank-math.php')
            || is_plugin_active('seo-by-rankmath/rank-math.php');
    }

    /**
     * Check whether AIOSEO (free or pro) is active.
     *
     * @return bool
     */
    private function is_aioseo_active() {
        $this->ensure_plugin_api();
        return is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php')
            || is_plugin_active('all-in-one-seo-pack-pro/all_in_one_seo_pack.php');
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
    // Per-plugin sync
    // ------------------------------------------------------------------

    /**
     * Mirror canonical data into Yoast term storage.
     *
     * Yoast stores taxonomy term SEO data in the `wpseo_taxonomy_meta` option
     * (wp_options), NOT in wp_termmeta. The `WPSEO_Taxonomy_Meta::set_value()`
     * API is the correct way to write to this storage.
     *
     * @param int    $term_id  Term ID.
     * @param string $taxonomy Taxonomy slug (required by Yoast API).
     * @param array  $data     Canonical key/value pairs.
     * @return bool Always true once dispatch completes.
     */
    private function sync_yoast($term_id, $taxonomy, array $data) {
        if (!class_exists('WPSEO_Taxonomy_Meta')) {
            return false;
        }

        $field_map = [
            'title'         => 'wpseo_title',
            'desc'          => 'wpseo_desc',
            'og_title'      => 'wpseo_opengraph-title',
            'og_desc'       => 'wpseo_opengraph-description',
            'og_image'      => 'wpseo_opengraph-image',
            'twitter_title' => 'wpseo_twitter-title',
            'twitter_desc'  => 'wpseo_twitter-description',
            'canonical'     => 'wpseo_canonical',
        ];

        $meta_values = [];

        foreach ($field_map as $canonical_key => $yoast_key) {
            if (array_key_exists($canonical_key, $data) && $data[$canonical_key] !== '') {
                $meta_values[$yoast_key] = (string) $data[$canonical_key];
            }
        }

        if (array_key_exists('noindex', $data)) {
            $is_noindex = ($data['noindex'] === 'noindex' || $data['noindex'] === true || $data['noindex'] === 1 || $data['noindex'] === '1');
            $meta_values['wpseo_noindex'] = $is_noindex ? 'noindex' : 'default';
        }

        if (!empty($meta_values)) {
            // Yoast's set_values() replaces the entire term entry.  Merge our
            // new values with the existing stored values so we don't clobber
            // previously synced fields.
            $existing = WPSEO_Taxonomy_Meta::get_term_meta($term_id, $taxonomy);
            if (is_array($existing)) {
                $meta_values = array_merge($existing, $meta_values);
            }
            WPSEO_Taxonomy_Meta::set_values($term_id, $taxonomy, $meta_values);

            // Rebuild the Yoast indexable so the frontend and sitemaps render
            // the updated values.  Yoast's Indexable_Term_Watcher listens on
            // `edited_term`; we fire it to trigger the rebuild.
            $term_obj = get_term($term_id, $taxonomy);
            $tt_id = ($term_obj && !is_wp_error($term_obj)) ? (int) $term_obj->term_taxonomy_id : 0;
            do_action('edited_term', $term_id, $tt_id, $taxonomy);
        }

        return true;
    }

    /**
     * Mirror canonical data into Rank Math term meta (wp_termmeta).
     *
     * @param int   $term_id Term ID.
     * @param array $data    Canonical key/value pairs.
     * @return bool Always true once dispatch completes.
     */
    private function sync_rankmath($term_id, array $data) {
        if (array_key_exists('title', $data) && $data['title'] !== '') {
            update_term_meta($term_id, 'rank_math_title', (string) $data['title']);
        }
        if (array_key_exists('desc', $data) && $data['desc'] !== '') {
            update_term_meta($term_id, 'rank_math_description', (string) $data['desc']);
        }
        if (array_key_exists('og_title', $data) && $data['og_title'] !== '') {
            update_term_meta($term_id, 'rank_math_facebook_title', (string) $data['og_title']);
        }
        if (array_key_exists('og_desc', $data) && $data['og_desc'] !== '') {
            update_term_meta($term_id, 'rank_math_facebook_description', (string) $data['og_desc']);
        }
        if (array_key_exists('canonical', $data) && $data['canonical'] !== '') {
            update_term_meta($term_id, 'rank_math_canonical_url', (string) $data['canonical']);
        }
        if (array_key_exists('noindex', $data)) {
            // Rank Math stores robots directives as a serialized PHP array (e.g. ['noindex']).
            $is_noindex = ($data['noindex'] === 'noindex' || $data['noindex'] === true || $data['noindex'] === 1 || $data['noindex'] === '1');
            $robots = $is_noindex ? ['noindex'] : [];
            update_term_meta($term_id, 'rank_math_robots', $robots);
        }

        return true;
    }

    /**
     * Mirror canonical data into the AIOSEO `wp_aioseo_terms` custom table.
     *
     * @param int   $term_id Term ID.
     * @param array $data    Canonical key/value pairs.
     * @return bool True when the row was written, false when the table is
     *              missing or the write failed.
     */
    private function sync_aioseo($term_id, array $data) {
        global $wpdb;

        $table = $wpdb->prefix . 'aioseo_terms';

        // Bail if the AIOSEO term table does not exist (plugin not initialised).
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_exists !== $table) {
            return false;
        }

        $row = [];
        if (array_key_exists('title', $data) && $data['title'] !== '') {
            $row['title'] = (string) $data['title'];
        }
        if (array_key_exists('desc', $data) && $data['desc'] !== '') {
            $row['description'] = (string) $data['desc'];
        }
        if (array_key_exists('og_title', $data) && $data['og_title'] !== '') {
            $row['og_title'] = (string) $data['og_title'];
        }
        if (array_key_exists('og_desc', $data) && $data['og_desc'] !== '') {
            $row['og_description'] = (string) $data['og_desc'];
        }
        if (array_key_exists('canonical', $data) && $data['canonical'] !== '') {
            $row['canonical_url'] = (string) $data['canonical'];
        }
        if (array_key_exists('noindex', $data)) {
            $is_noindex = ($data['noindex'] === 'noindex' || $data['noindex'] === true || $data['noindex'] === 1 || $data['noindex'] === '1');
            $row['robots_noindex'] = $is_noindex ? 1 : 0;
            // When explicitly setting noindex, disable AIOSEO's global-defaults
            // fallback so the explicit value takes effect.
            $row['robots_default'] = 0;
        }

        if (empty($row)) {
            return false;
        }

        $row['updated'] = current_time('mysql');

        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE term_id = %d",
            $term_id
        ));

        if ($existing_id) {
            $updated = $wpdb->update($table, $row, ['term_id' => $term_id]);
            return $updated !== false;
        }

        // New row: include term_id, timestamps, and NOT NULL robot defaults.
        $row['term_id'] = $term_id;
        $row['created'] = current_time('mysql');

        // AIOSEO's robots_* columns are NOT NULL with no DB default.
        // Use robots_default=1 so AIOSEO falls back to global settings,
        // then only override robots_noindex when explicitly set above.
        $robot_defaults = [
            'robots_default'      => isset($row['robots_noindex']) ? 0 : 1,
            'robots_noindex'      => 0,
            'robots_noarchive'    => 0,
            'robots_nosnippet'    => 0,
            'robots_nofollow'     => 0,
            'robots_noimageindex' => 0,
            'robots_noodp'        => 0,
            'robots_notranslate'  => 0,
        ];
        // Merge defaults first, then $row on top so our noindex value wins.
        $row = array_merge($robot_defaults, $row);

        $inserted = $wpdb->insert($table, $row);
        return $inserted !== false;
    }
}

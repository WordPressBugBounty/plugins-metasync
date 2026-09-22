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
     * OTTO-generated term fields and their matching Persistence settings.
     *
     * Manually entered term fields are deliberately not gated; callers mark
     * only the comprehensive OTTO sync as generated below.
     *
     * @var array<string,string>
     */
    private const OTTO_PERSISTENCE_KEYS = [
        'title'         => 'meta_title',
        'desc'          => 'meta_description',
        'og_title'      => 'og_title',
        'og_desc'       => 'og_description',
        'twitter_title' => 'twitter_title',
        'twitter_desc'  => 'twitter_description',
        'canonical'     => 'canonical_url',
    ];

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
     * @param bool   $otto_generated Whether the values came from OTTO. A single
     *                         call must not mix OTTO-generated and manually
     *                         entered fields: the flag applies to every entry
     *                         in $data, so a mixed call could only gate all of
     *                         them or none. Split the call instead.
     * @return array Results keyed by plugin: ['yoast'=>bool,'rankmath'=>bool,'aioseo'=>bool].
     */
    public function sync_term($term_id, $taxonomy, array $data, $otto_generated = false) {
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

            // OTTO-generated values may reach third-party storage only while
            // their matching Persistence setting is enabled. The class_exists
            // guard is fail-closed so a partial install cannot authorise a
            // permanent write. Manual term updates use the default false flag
            // and remain ungated, matching the post-side bridge semantics.
            if ($otto_generated) {
                foreach (self::OTTO_PERSISTENCE_KEYS as $field => $setting) {
                    if (array_key_exists($field, $data)
                        && (!class_exists('Metasync_Otto_Persistence_Settings')
                            || !Metasync_Otto_Persistence_Settings::should_persist($setting))) {
                        unset($data[$field]);
                    }
                }

                if (empty($data)) {
                    return $results;
                }
            }

            // Never mirror a corrupted or non-URL canonical into third-party
            // storage — a nested-array value cast with (string) becomes the
            // literal "Array" and propagates into Yoast/RankMath/AIOSEO.
            if (array_key_exists('canonical', $data)) {
                $data['canonical'] = Metasync_Canonical_Sanitizer::sanitize($data['canonical']);
                if ($data['canonical'] === '') {
                    unset($data['canonical']);
                }
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
     * Whether third-party SEO storage may be written at all.
     *
     * The site owner's consent switch. Terms are covered by it on exactly the
     * same terms as posts — a category's Rank Math title is another plugin's
     * data just as much as a page's is.
     *
     * @return bool
     */
    private function third_party_writes_allowed() {
        return class_exists('Metasync_Seo_Backup') && Metasync_Seo_Backup::is_enabled();
    }

    /**
     * Write one third-party term-meta field, preserving what it held before.
     *
     * The backup row lives in term meta, under the same key naming posts use.
     * Post meta and term meta are separate tables, so the two cannot collide
     * and the helper stays object-type agnostic.
     *
     * @param int    $term_id Term ID.
     * @param string $key     Third-party term meta key.
     * @param mixed  $value   Value to write.
     * @return bool True when the write happened.
     */
    private function write_term_field($term_id, $key, $value) {
        return class_exists('Metasync_Seo_Backup')
            && Metasync_Seo_Backup::write_term_meta($term_id, $key, $value);
    }

    /**
     * Backup field name for one Yoast taxonomy-meta entry.
     *
     * Yoast's `wpseo_taxonomy_meta` option nests taxonomy → term ID → field, so
     * the same term ID can hold a different original under each taxonomy it
     * belongs to. The backup rows all live on the term, which has no such
     * nesting, so the taxonomy has to be carried in the field name or the two
     * originals collide and write-once keeps only the first.
     *
     * @param  string $taxonomy  Taxonomy slug.
     * @param  string $yoast_key Yoast field name, e.g. 'wpseo_title'.
     * @return string
     */
    private static function yoast_tax_backup_field($taxonomy, $yoast_key) {
        return 'yoast_tax_' . $taxonomy . '_' . $yoast_key;
    }

    /**
     * Carry a backup written under the old, taxonomy-less key onto the new one.
     *
     * Earlier builds stored these as `yoast_tax_{field}`, with no taxonomy in
     * the key. Sites that ran one of those hold real customer originals there.
     * Left alone, the next sync finds nothing at the new key and backs up
     * whatever is in the option now — which is the value the previous sync
     * wrote. The original would still exist, under a key nothing reads, while
     * the backup that a restore trusts would hold this plugin's own output.
     *
     * Copying it across first makes the new key win on its own merits: the
     * legacy value is the older, truer one, and backups are write-once, so a
     * later call cannot displace it.
     *
     * @param int    $term_id  Term ID.
     * @param string $taxonomy Taxonomy slug.
     * @param string $yoast_key Yoast field name.
     */
    private static function adopt_legacy_yoast_tax_backup($term_id, $taxonomy, $yoast_key) {
        $scoped = self::yoast_tax_backup_field($taxonomy, $yoast_key);
        $legacy = 'yoast_tax_' . $yoast_key;

        $existing_scoped = Metasync_Seo_Backup::read_backup('term', $term_id, $scoped);
        if (!empty($existing_scoped['exists'])) {
            return;
        }

        $legacy_backup = Metasync_Seo_Backup::read_backup('term', $term_id, $legacy);
        if (empty($legacy_backup['exists'])) {
            return;
        }

        Metasync_Seo_Backup::record_marker('term', $term_id, $scoped, $legacy_backup['value']);
    }

    /**
     * The Yoast taxonomy-meta entry as it is actually stored, without defaults.
     *
     * `WPSEO_Taxonomy_Meta::get_term_meta()` returns
     * `array_merge($defaults_per_term, $stored)`, so all twenty Yoast fields
     * are always present and `array_key_exists()` can never tell a field the
     * customer set from one they never touched. Yoast only ever stores the
     * non-default values -- `validate_term_meta_data()` ends in
     * `array_diff_assoc($clean, $defaults_per_term)` -- so the raw option is
     * the only place that distinction survives, and it is exactly the
     * distinction a restore needs to choose between deleting a key and
     * blanking it.
     *
     * The merged view is still the right thing to build the write from, so
     * this is used only to decide what to record as the original.
     *
     * @param int    $term_id  Term ID.
     * @param string $taxonomy Taxonomy slug.
     * @return array Stored entry, empty when the term has none.
     */
    private static function yoast_stored_tax_meta($term_id, $taxonomy) {
        $option = get_option('wpseo_taxonomy_meta', []);

        if (!is_array($option)
            || !isset($option[$taxonomy][$term_id])
            || !is_array($option[$taxonomy][$term_id])) {
            return [];
        }

        return $option[$taxonomy][$term_id];
    }

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
     * @return bool True when the write happened; false when consent is withheld
     *              or an original could not be preserved.
     */
    private function sync_yoast($term_id, $taxonomy, array $data) {
        if (!$this->third_party_writes_allowed()) {
            return false;
        }

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

            // What Yoast really has on disk, for the backup only. The merged
            // view above is the right basis for the write and the wrong one for
            // deciding what was there before.
            $stored = self::yoast_stored_tax_meta($term_id, $taxonomy);

            // Yoast keeps term SEO in the `wpseo_taxonomy_meta` option, not in
            // term meta, so there is no term-meta row to preserve. Save each
            // entry we are about to change onto the term itself, keyed by the
            // taxonomy and the Yoast field name, so a restore can put the
            // option entry back.
            //
            // The taxonomy belongs in the key because the option is keyed by
            // taxonomy first and term ID second: one term ID can hold a
            // separate entry under each taxonomy it appears in. Leave it out
            // and the first taxonomy synced claims the write-once row, and the
            // second taxonomy's original is overwritten with nothing saved.
            //
            // set_values() rewrites the whole option entry at once, so a single
            // unsaved field cannot be skipped in isolation -- if any original
            // fails to record, the write is abandoned entirely rather than
            // destroying a value with nothing to restore it from.
            $originals_saved = class_exists('Metasync_Seo_Backup');
            if ($originals_saved) {
                foreach ($meta_values as $yoast_key => $yoast_value) {
                    self::adopt_legacy_yoast_tax_backup($term_id, $taxonomy, $yoast_key);

                    $originals_saved = Metasync_Seo_Backup::backup_before_overwrite(
                        'term',
                        $term_id,
                        self::yoast_tax_backup_field($taxonomy, $yoast_key),
                        $yoast_value,
                        array_key_exists($yoast_key, $stored) ? $stored[$yoast_key] : null
                    ) && $originals_saved;
                }
            }

            if (!$originals_saved) {
                return false;
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
        if (!$this->third_party_writes_allowed()) {
            return false;
        }

        if (array_key_exists('title', $data) && $data['title'] !== '') {
            $this->write_term_field($term_id, 'rank_math_title', (string) $data['title']);
        }
        if (array_key_exists('desc', $data) && $data['desc'] !== '') {
            $this->write_term_field($term_id, 'rank_math_description', (string) $data['desc']);
        }
        if (array_key_exists('og_title', $data) && $data['og_title'] !== '') {
            $this->write_term_field($term_id, 'rank_math_facebook_title', (string) $data['og_title']);
        }
        if (array_key_exists('og_desc', $data) && $data['og_desc'] !== '') {
            $this->write_term_field($term_id, 'rank_math_facebook_description', (string) $data['og_desc']);
        }
        if (array_key_exists('canonical', $data) && $data['canonical'] !== '') {
            $this->write_term_field($term_id, 'rank_math_canonical_url', (string) $data['canonical']);
        }
        if (array_key_exists('noindex', $data)) {
            // Rank Math stores robots directives as a serialized PHP array (e.g. ['noindex']).
            $is_noindex = ($data['noindex'] === 'noindex' || $data['noindex'] === true || $data['noindex'] === 1 || $data['noindex'] === '1');
            $robots = $is_noindex ? ['noindex'] : [];
            $this->write_term_field($term_id, 'rank_math_robots', $robots);
        }

        return true;
    }

    /**
     * Preserve the AIOSEO term columns a sync is about to overwrite.
     *
     * AIOSEO keeps term SEO data in its own `aioseo_terms` table, so there is
     * no term-meta row to save and no field a restore could delete. The
     * per-column originals and whether the row pre-existed are recorded as term
     * meta on the term itself, so a restore can put the original columns back
     * without touching the rest of the user's row, and can delete outright a
     * row that only exists because we created it.
     *
     * @param int    $term_id     Term ID.
     * @param string $table       Fully prefixed AIOSEO term table name.
     * @param array  $row         Columns and values about to be written.
     * @param bool   $row_existed Whether AIOSEO already had a row for this term.
     * @param array  $created     Out-param, filled with the backup fields this
     *                           call created, so a failed write can withdraw
     *                           exactly its own rows and no one else's.
     * @return bool True when every original was preserved and the caller may write.
     */
    private function backup_aioseo_columns($term_id, $table, array $row, $row_existed, array &$created) {
        $created = [];

        if (!class_exists('Metasync_Seo_Backup')) {
            return false;
        }

        global $wpdb;

        // Whether the row pre-existed is what a restore uses to choose between
        // putting the original columns back and deleting a row that only exists
        // because we made it. A marker that will not record is as disqualifying
        // as a column that will not.
        if (!Metasync_Seo_Backup::record_marker(
            'term',
            $term_id,
            'aioseo_row_existed',
            $row_existed ? '1' : '0',
            $marker_created
        )) {
            return false;
        }

        if ($marker_created) {
            $created[] = 'aioseo_row_existed';
        }

        $columns = array_diff(array_keys($row), ['updated', 'created', 'term_id']);
        if (empty($columns)) {
            return true;
        }

        $current = null;
        if ($row_existed) {
            $select = '`' . implode('`, `', array_map('esc_sql', $columns)) . '`';
            $current = $wpdb->get_row(
                $wpdb->prepare("SELECT {$select} FROM {$table} WHERE term_id = %d", $term_id),
                ARRAY_A
            );

            // The row was there a moment ago, so a null answer now is a failed
            // read, not an empty row. Recording it as "every column was NULL"
            // would tell a later restore to delete values it should put back,
            // which is the exact loss this layer exists to prevent. No write is
            // happening, so the marker recorded above has to go too — a marker
            // left describing a write that never ran is a stale story.
            if ($current === null || !Metasync_Seo_Backup::db_read_succeeded()) {
                Metasync_Seo_Backup::discard_backups('term', $term_id, $created);
                return false;
            }
        }

        foreach ($columns as $column) {
            // A missing row and a NULL column mean the same thing to a restore:
            // there was no value here, so put nothing back.
            $current_value = ($current !== null && isset($current[$column])) ? $current[$column] : null;

            $field = 'aioseo_' . $column;

            // One unsaved column is enough to refuse the whole write: a half-original,
            // half-OTTO row is something no restore can unpick. The refusal also
            // means the caller writes nothing, so withdraw the marker and the
            // columns recorded so far — the same rule as the failed-insert path
            // in the caller. Left behind, a row_existed='0' marker would let a
            // restore delete a row the customer creates later, and a NULL
            // column backup would blank a real value in it.
            if (!Metasync_Seo_Backup::backup_before_overwrite(
                'term',
                $term_id,
                $field,
                $row[$column],
                $current_value,
                $column_created
            )) {
                Metasync_Seo_Backup::discard_backups('term', $term_id, $created);
                return false;
            }

            if ($column_created) {
                $created[] = $field;
            }
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

        if (!$this->third_party_writes_allowed()) {
            return false;
        }

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

        // An unreadable probe cannot be treated as "no row". It would commit a
        // write-once row_existed='0' for a row AIOSEO really has -- which a
        // restore reads as licence to delete it -- and send an INSERT at a row
        // that already exists. Leave AIOSEO's row alone and let the next sync
        // record the truth.
        if (!Metasync_Seo_Backup::db_read_succeeded()) {
            return false;
        }

        // No original saved means no write. Overwriting anyway is the data loss
        // this whole layer exists to prevent.
        if ($existing_id) {
            $backups_created = [];
            if (!$this->backup_aioseo_columns($term_id, $table, $row, true, $backups_created)) {
                return false;
            }

            $updated = $wpdb->update($table, $row, ['term_id' => $term_id]);
            return $updated !== false;
        }

        $backups_created = [];
        if (!$this->backup_aioseo_columns($term_id, $table, $row, false, $backups_created)) {
            return false;
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

        // The row_existed='0' marker was recorded before the insert, because a
        // marker that will not save has to be able to veto the write. A failed
        // insert leaves no row of ours, and a marker still claiming one would
        // let a restore delete a row the customer creates later. The per-column
        // backups beside it go too, or a column captured as NULL for a row that
        // never existed would blank a real value the customer later puts in one.
        if ($inserted === false) {
            Metasync_Seo_Backup::discard_backups('term', $term_id, $backups_created);
            return false;
        }

        return true;
    }
}

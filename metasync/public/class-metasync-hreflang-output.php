<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hreflang / language alternates output for the public-facing side of the site.
 *
 * Outputs <link rel="alternate" hreflang="…" href="…"> tags in <head> at
 * wp_head priority 2. Entries come from two sources:
 *
 *  1. Manual entries stored in post meta `_metasync_hreflang` (JSON array).
 *  2. Auto-detected entries from WPML (when ICL_SITEPRESS_VERSION is defined).
 *
 * Manual entries take precedence over auto-detected entries on lang+region
 * collision. When Yoast SEO + WPML are both active and MetaSync has no manual
 * entries, output is deferred to Yoast+WPML's native hreflang tags to avoid
 * duplication. Standalone Yoast SEO (without WPML) never outputs hreflang
 * tags on its own, so no suppression filter is required in that case.
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 *
 * @package    Metasync
 * @subpackage Metasync/public
 */
class Metasync_Hreflang_Output
{
    /**
     * Output <link rel="alternate" hreflang="…" href="…"> tags for the
     * current post in <head>.
     */
    public function output_hreflang_tags()
    {
        if (is_admin() || is_feed() || is_robots() || !is_singular()) {
            return;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return;
        }

        $manual_entries = $this->get_manual_entries($post_id);
        $wpml_entries   = [];

        if (defined('ICL_SITEPRESS_VERSION')) {
            $wpml_entries = $this->get_wpml_entries($post_id);
        }

        // Yoast+WPML native output handles hreflang when MetaSync has no
        // manual entries — defer to avoid duplicate tags.
        if (defined('WPSEO_VERSION') && defined('ICL_SITEPRESS_VERSION') && empty($manual_entries)) {
            return;
        }

        $entries = $this->merge_entries($wpml_entries, $manual_entries);

        if (empty($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if (empty($entry['lang']) || empty($entry['url'])) {
                continue;
            }
            $region = isset($entry['region']) ? $entry['region'] : '';
            $lang   = $entry['lang'];
            if ($lang !== 'x-default' && !empty($region)) {
                $lang = $lang . '-' . $region;
            }
            echo '<link rel="alternate" hreflang="' . esc_attr($lang) . '" href="' . esc_url($entry['url']) . '" />' . "\n";
        }
    }

    /**
     * Read manual hreflang entries from the `_metasync_hreflang` post meta.
     *
     * @param int $post_id Post ID.
     * @return array List of {lang, region, url} entries.
     */
    private function get_manual_entries($post_id)
    {
        $raw = get_post_meta($post_id, '_metasync_hreflang', true);
        if (empty($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $entries = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entries[] = [
                'lang'   => isset($entry['lang']) ? (string) $entry['lang'] : '',
                'region' => isset($entry['region']) ? (string) $entry['region'] : '',
                'url'    => isset($entry['url']) ? (string) $entry['url'] : '',
            ];
        }
        return $entries;
    }

    /**
     * Build hreflang entries from WPML translations of the current post.
     *
     * Queries the `icl_translations` table to resolve the translation group
     * (`trid`) the current post belongs to, then returns one entry per
     * language in the group. The row matching the WPML default language also
     * gets an `x-default` entry.
     *
     * @param int $post_id Post ID.
     * @return array List of {lang, region, url} entries.
     */
    public function get_wpml_entries($post_id)
    {
        global $wpdb;
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        $element_type = 'post_' . $post->post_type;
        $table = $wpdb->prefix . 'icl_translations';

        $trid = $wpdb->get_var($wpdb->prepare(
            "SELECT trid FROM {$table} WHERE element_id = %d AND element_type = %s LIMIT 1",
            $post_id,
            $element_type
        ));

        if (empty($trid)) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT language_code, element_id FROM {$table} WHERE trid = %d",
            $trid
        ));

        if (empty($rows)) {
            return [];
        }

        $default_lang = apply_filters('wpml_default_language', null);
        if (empty($default_lang)) {
            $default_lang = get_option('wpml_default_language');
        }

        $entries = [];
        foreach ($rows as $row) {
            $permalink = get_permalink((int) $row->element_id);
            if (empty($permalink)) {
                continue;
            }
            $entries[] = [
                'lang'   => (string) $row->language_code,
                'region' => '',
                'url'    => $permalink,
            ];
            if (!empty($default_lang) && $row->language_code === $default_lang) {
                $entries[] = [
                    'lang'   => 'x-default',
                    'region' => '',
                    'url'    => $permalink,
                ];
            }
        }

        return $entries;
    }

    /**
     * Merge auto-detected and manual entries. Manual entries take precedence
     * on `lang-region` collisions.
     *
     * @param array $auto    Entries from auto-detection (e.g. WPML).
     * @param array $manual  Entries from post meta.
     * @return array Merged list of entries.
     */
    private function merge_entries(array $auto, array $manual)
    {
        $by_key = [];
        foreach ($auto as $entry) {
            $key = $this->collision_key($entry);
            $by_key[$key] = $entry;
        }
        foreach ($manual as $entry) {
            $key = $this->collision_key($entry);
            $by_key[$key] = $entry;
        }
        return array_values($by_key);
    }

    /**
     * Produce a collision key in the form `lang-region` (or just `lang` when
     * region is empty) used for de-duplication.
     */
    private function collision_key(array $entry)
    {
        $lang = isset($entry['lang']) ? (string) $entry['lang'] : '';
        $region = isset($entry['region']) ? (string) $entry['region'] : '';
        return $region !== '' ? $lang . '-' . $region : $lang;
    }
}

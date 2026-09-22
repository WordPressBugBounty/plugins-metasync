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
 *  2. Auto-detected entries from WPML (when ICL_SITEPRESS_VERSION is defined),
 *     limited to published translations.
 *
 * Manual entries take precedence over auto-detected entries on lang+region
 * collision. WPML itself prints a competing set from
 * SitePress::head_langs() at wp_head priority 1, so when MetaSync has manual
 * rows and therefore takes over the cluster, the `wpml_hreflangs` filter is
 * emptied from template_redirect (before either callback runs) to prevent two
 * complete sets of tags. Conversely, with no usable manual rows MetaSync
 * stands down and WPML — with or without an SEO plugin integration — emits
 * natively. Standalone Yoast SEO (without WPML) never outputs hreflang tags
 * on its own, so no suppression is required in that case.
 *
 * Language codes and URLs are normalised and validated before emission:
 * `en_US` becomes `en-US`, case is folded, `x-default` ignores any region,
 * and rows with an invalid code or a non-absolute URL are skipped rather
 * than emitted verbatim. On manual-only clusters a self-referencing entry
 * for the current page (derived from the site locale) is injected when
 * missing, because Google requires every cluster member to list itself.
 *
 * This class is the single shared implementation of the WPML translation
 * lookup: the editor sidebar and the MCP hreflang tools reuse
 * get_wpml_entries() instead of carrying divergent copies of the query.
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
     * SEO output instance, used for the shared noindex check.
     *
     * Optional for BC with callers that construct this class bare; when
     * absent the indexability check is skipped rather than fatal.
     *
     * @var Metasync_Seo_Output|null
     */
    private $seo_output;

    /**
     * @param Metasync_Seo_Output|null $seo_output Shared emitter, when available.
     */
    public function __construct(?Metasync_Seo_Output $seo_output = null)
    {
        $this->seo_output = $seo_output;
    }

    /**
     * Output <link rel="alternate" hreflang="…" href="…"> tags for the
     * current post in <head>.
     */
    public function output_hreflang_tags()
    {
        // The Post/Page Editor Settings "Disable Language Alternates Meta Box"
        // switch turns the whole feature off: no panel, no meta registration, and
        // no front-end hreflang emission — manual entries or WPML-derived alike.
        // WPML's own hreflang output is left untouched either way.
        if (class_exists('Metasync_Feature_Flags')
            && Metasync_Feature_Flags::is_disabled(Metasync_Feature_Flags::LANGUAGE_ALTERNATES)) {
            return;
        }

        if (is_admin() || is_feed() || is_robots() || !is_singular()) {
            return;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return;
        }

        $manual_entries = $this->get_manual_entries($post_id);
        $wpml_active    = defined('ICL_SITEPRESS_VERSION');

        // WPML already emits a complete set for the translation group (and a
        // SEO-plugin + WPML integration does the same through WPML's data).
        // With no usable manual rows there is nothing to override — defer
        // before running any WPML queries and let the native output stand.
        // Usable means "would actually be emitted": a stray blank or invalid
        // row must not flip the post into MetaSync-emits mode.
        if ($wpml_active && empty($manual_entries)) {
            return;
        }

        // Google discards a hreflang cluster whose member page is noindex;
        // annotating such a page only invites "alternate page with improper
        // canonical/noindex" noise.
        if ($this->is_noindex($post_id)) {
            return;
        }

        $entries = $wpml_active ? $this->get_wpml_entries($post_id) : [];
        $entries = $this->merge_entries($entries, $manual_entries);

        if (!$wpml_active) {
            // The WPML path covers the self-reference incidentally (the post
            // is a member of its own trid group); the manual path never does.
            $entries = $this->ensure_self_reference($post_id, $entries);
        }

        foreach ($entries as $entry) {
            $code = self::lang_code($entry);
            if ($code === '' || empty($entry['url']) || !self::is_absolute_http_url($entry['url'])) {
                continue;
            }
            echo '<link rel="alternate" hreflang="' . esc_attr($code) . '" href="' . esc_url($entry['url']) . '" />' . "\n";
        }
    }

    /**
     * Register the WPML hreflang suppression, hooked on template_redirect.
     *
     * WPML prints its own hreflang set from SitePress::head_langs() at
     * wp_head priority 1 — ahead of this plugin's output at priority 2 —
     * so the competing set has to be neutralised before wp_head starts.
     * The decision logic lives in should_suppress_wpml() so it can be
     * exercised directly.
     */
    public function register_wpml_suppression()
    {
        if (!$this->should_suppress_wpml()) {
            return;
        }
        add_filter('wpml_hreflangs', array($this, 'suppress_wpml_hreflangs'), PHP_INT_MAX);
    }

    /**
     * True when MetaSync is taking over the cluster on a WPML site and
     * WPML's own hreflang output must be suppressed.
     */
    public function should_suppress_wpml()
    {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return false;
        }

        if (class_exists('Metasync_Feature_Flags')
            && Metasync_Feature_Flags::is_disabled(Metasync_Feature_Flags::LANGUAGE_ALTERNATES)) {
            return false;
        }

        if (is_admin() || is_feed() || is_robots() || !is_singular()) {
            return false;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return false;
        }

        return !empty($this->get_manual_entries($post_id));
    }

    /**
     * Empty WPML's hreflang set on the `wpml_hreflangs` filter.
     *
     * MetaSync is emitting the cluster itself (manual rows present); WPML's
     * own set would duplicate every tag. Returning an empty array makes
     * SitePress::head_langs() print nothing.
     */
    public function suppress_wpml_hreflangs($hreflangs)
    {
        return array();
    }

    /**
     * Read manual hreflang entries from the `_metasync_hreflang` post meta,
     * normalised and reduced to rows that would actually be emitted.
     *
     * Blank rows (an "Add alternate" click that was never filled in) and
     * rows failing language-code or URL validation are dropped here, so the
     * WPML deferral decision and the emission loop agree on what counts.
     *
     * @param int $post_id Post ID.
     * @return array List of normalised {lang, region, url} entries.
     */
    public function get_manual_entries($post_id)
    {
        $raw = get_post_meta($post_id, '_metasync_hreflang', true);
        if (empty($raw) || !is_string($raw)) {
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
            $normalized = self::normalize_entry($entry);
            if (!$normalized['valid'] || $normalized['lang_code'] === '' || $normalized['url'] === '') {
                continue;
            }
            if (!self::is_absolute_http_url($normalized['url'])) {
                continue;
            }
            $entries[] = [
                'lang'   => $normalized['lang'],
                'region' => $normalized['region'],
                'url'    => $normalized['url'],
            ];
        }
        return $entries;
    }

    /**
     * Build hreflang entries from WPML translations of the current post.
     *
     * Queries the `icl_translations` table to resolve the translation group
     * (`trid`) the current post belongs to, then returns one entry per
     * published translation in the group (drafts, pending, scheduled and
     * trashed rows are excluded — their permalinks resolve to `?p=123`
     * URLs a crawler cannot fetch). The row matching the WPML default
     * language also gets an `x-default` entry.
     *
     * Shared implementation: the editor sidebar and the MCP hreflang tools
     * call this instead of carrying their own copy of the query.
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

        // Only published translations: icl_translations holds rows for
        // drafts, pending, scheduled and (until cleanup) trashed posts too.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.language_code, t.element_id
               FROM {$table} t
               INNER JOIN {$wpdb->posts} p ON p.ID = t.element_id
              WHERE t.trid = %d AND p.post_status = 'publish'",
            $trid
        ));

        if (empty($rows)) {
            return [];
        }

        $default_lang = apply_filters('wpml_default_language', null);
        if (empty($default_lang)) {
            // Real WPML keeps its settings in the icl_sitepress_settings
            // array option; a top-level wpml_default_language option has
            // never existed.
            $settings = get_option('icl_sitepress_settings', []);
            $default_lang = is_array($settings) && !empty($settings['default_language'])
                ? $settings['default_language']
                : '';
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
     * on language-code collisions; keys are the normalised (case-folded)
     * codes so `EN` and `en` collide as Google reads them.
     *
     * @param array $auto    Entries from auto-detection (e.g. WPML).
     * @param array $manual  Entries from post meta.
     * @return array Merged list of entries.
     */
    private function merge_entries(array $auto, array $manual)
    {
        $by_key = [];
        foreach ($auto as $entry) {
            $key = self::lang_code($entry);
            if ($key !== '') {
                $by_key[$key] = $entry;
            }
        }
        foreach ($manual as $entry) {
            $key = self::lang_code($entry);
            if ($key !== '') {
                $by_key[$key] = $entry;
            }
        }
        return array_values($by_key);
    }

    /**
     * Inject a self-referencing entry for the current page when missing.
     *
     * Google requires every URL in a cluster to reference itself. Skipped
     * when an entry already lists this page's URL, or already maps the
     * site's own language code to a different URL — in both cases the user
     * has spoken and injecting a row would create a conflicting duplicate.
     *
     * @param int   $post_id Current post ID.
     * @param array $entries Merged cluster entries.
     * @return array Entries with the self-reference appended when needed.
     */
    private function ensure_self_reference($post_id, array $entries)
    {
        $self_url = get_permalink($post_id);
        if (empty($self_url)) {
            return $entries;
        }

        $self_code = self::locale_lang_code();
        if ($self_code === '') {
            return $entries;
        }

        foreach ($entries as $entry) {
            if (isset($entry['url']) && $entry['url'] === $self_url) {
                return $entries;
            }
            if (self::lang_code($entry) === $self_code) {
                return $entries;
            }
        }

        $entries[] = [
            'lang'   => $self_code,
            'region' => '',
            'url'    => $self_url,
        ];
        return $entries;
    }

    /**
     * Normalise one hreflang row into canonical parts plus a validity flag.
     *
     * Folding rules:
     *  - the lang field is trimmed, lowercased, and split on `-`/`_`, so a
     *    code typed as `en_US` or as `en-US` in the lang field with a blank
     *    region both end up as lang `en` + region `US`;
     *  - the region is uppercased;
     *  - `x-default` (any casing/separator) keeps its region stripped, in
     *    the key and the output alike;
     *  - `valid` is false for codes that are not an ISO language optionally
     *    followed by a region (`AB`, `ABCD`) or numeric region (`123`) —
     *    e.g. `English` or `en-United States`.
     *
     * @param array $entry Raw row with optional lang/region/url keys.
     * @return array{lang:string, region:string, url:string, lang_code:string, valid:bool}
     */
    public static function normalize_entry(array $entry)
    {
        $lang   = strtolower(trim(isset($entry['lang']) ? (string) $entry['lang'] : ''));
        $region = trim(isset($entry['region']) ? (string) $entry['region'] : '');
        $url    = trim(isset($entry['url']) ? (string) $entry['url'] : '');

        if ($lang === 'x-default' || $lang === 'x_default' || $lang === 'xdefault') {
            return [
                'lang'      => 'x-default',
                'region'    => '',
                'url'       => $url,
                'lang_code' => 'x-default',
                'valid'     => true,
            ];
        }

        if (strpos($lang, '_') !== false || strpos($lang, '-') !== false) {
            $parts = preg_split('/[-_]/', $lang);
            $lang  = $parts[0];
            if ($region === '' && isset($parts[1]) && $parts[1] !== '') {
                $region = $parts[1];
            }
        }
        $region = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $region));

        $code = $region !== '' ? $lang . '-' . $region : $lang;
        $valid = (bool) preg_match('/^[a-z]{2,3}(-([A-Z]{2}|[A-Z]{4}|[0-9]{3}))?$/', $code);

        return [
            'lang'      => $lang,
            'region'    => $region,
            'url'       => $url,
            'lang_code' => $valid ? $code : '',
            'valid'     => $valid,
        ];
    }

    /**
     * Final hreflang value for an entry, or '' when the code is invalid.
     */
    public static function lang_code(array $entry)
    {
        $normalized = self::normalize_entry($entry);
        return $normalized['lang_code'];
    }

    /**
     * True when the URL is absolute with an http/https scheme — hreflang
     * requires absolute URLs; relative hrefs are not emitted.
     */
    public static function is_absolute_http_url($url)
    {
        $scheme = wp_parse_url((string) $url, PHP_URL_SCHEME);
        $host   = wp_parse_url((string) $url, PHP_URL_HOST);

        return in_array(strtolower((string) $scheme), array('http', 'https'), true)
            && !empty($host);
    }

    /**
     * The site locale as a hreflang language code, e.g. `en_US` → `en-US`.
     *
     * Returns '' when the locale cannot be mapped (used to skip the
     * self-reference injection rather than guess).
     */
    public static function locale_lang_code()
    {
        $locale = strtolower((string) get_locale());
        $parts  = explode('_', $locale);
        $lang   = $parts[0];

        if (!preg_match('/^[a-z]{2,3}$/', $lang)) {
            return '';
        }

        if (isset($parts[1]) && preg_match('/^[a-z]{2}$/', $parts[1])) {
            return $lang . '-' . strtoupper($parts[1]);
        }

        return $lang;
    }

    /**
     * True when MetaSync's own robots resolution marks the post noindex.
     *
     * Only MetaSync's own directives count: when the robots features are
     * switched off — or the noindex comes from another plugin — the cluster
     * is not MetaSync's to withhold.
     */
    private function is_noindex($post_id)
    {
        if ($this->seo_output === null) {
            return false;
        }

        return (bool) $this->seo_output->is_noindex($post_id);
    }
}

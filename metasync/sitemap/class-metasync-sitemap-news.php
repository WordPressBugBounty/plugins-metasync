<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google News Sitemap Generator
 *
 * Generates a news sitemap following the Google News Sitemap protocol.
 * Only includes articles published within the last 2 days, max 1000 URLs.
 *
 * @package    Metasync
 * @subpackage Metasync/sitemap
 */
class Metasync_Sitemap_News
{
    /**
     * News sitemap settings
     *
     * @var array
     */
    private $settings;

    /**
     * Initialize the class and load settings.
     */
    public function __construct()
    {
        $defaults = [
            'enabled'              => false,
            'post_types'           => ['post'],
            'categories'           => [],
            'tags'                 => [],
            'taxonomies'           => [],
            'excluded_urls'        => '',
            'publication_name'     => '',
            'publication_language' => '',
        ];

        $this->settings = wp_parse_args(
            get_option('metasync_news_sitemap_settings', []),
            $defaults
        );
    }

    /**
     * Generate the news sitemap XML.
     *
     * @return string|false XML string on success, false if disabled or conflict detected.
     */
    public function generate()
    {
        if (empty($this->settings['enabled'])) {
            return false;
        }

        if ($this->has_conflicts()) {
            return false;
        }

        $post_types = !empty($this->settings['post_types']) ? (array) $this->settings['post_types'] : ['post'];

        $args = [
            'post_type'              => $post_types,
            'post_status'            => 'publish',
            'posts_per_page'         => 1000,
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
            'date_query'             => [
                [
                    'after' => '2 days ago',
                ],
            ],
        ];

        // Add taxonomy filters if set
        $tax_query = [];

        if (!empty($this->settings['categories'])) {
            $tax_query[] = [
                'taxonomy' => 'category',
                'field'    => 'term_id',
                'terms'    => array_map('absint', (array) $this->settings['categories']),
            ];
        }

        if (!empty($this->settings['tags'])) {
            $tax_query[] = [
                'taxonomy' => 'post_tag',
                'field'    => 'term_id',
                'terms'    => array_map('absint', (array) $this->settings['tags']),
            ];
        }

        // Generic taxonomy filters (custom taxonomies)
        if (!empty($this->settings['taxonomies']) && is_array($this->settings['taxonomies'])) {
            foreach ($this->settings['taxonomies'] as $taxonomy => $term_ids) {
                if (!empty($term_ids) && is_array($term_ids)) {
                    $tax_query[] = [
                        'taxonomy' => sanitize_key($taxonomy),
                        'field'    => 'term_id',
                        'terms'    => array_map('absint', $term_ids),
                    ];
                }
            }
        }

        if (!empty($tax_query)) {
            $args['tax_query'] = $tax_query;
        }

        $posts = get_posts($args);

        // Batch-resolve noindex status in a single query so posts the site
        // owner marked noindex never enter the news sitemap (mirrors the main
        // sitemap generator, which already excludes them).
        $noindex_set = array_flip($this->get_noindex_post_ids(array_map('intval', wp_list_pluck($posts, 'ID'))));

        // Build excluded URLs set
        $excluded_urls = [];
        if (!empty($this->settings['excluded_urls'])) {
            $raw_lines = explode("\n", $this->settings['excluded_urls']);
            foreach ($raw_lines as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $excluded_urls[$line] = true;
                }
            }
        }

        if (empty($posts)) {
            // Return a valid but empty sitemap
            $xml = new DOMDocument('1.0', 'UTF-8');
            $xml->formatOutput = true;

            $urlset = $xml->createElement('urlset');
            $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            $urlset->setAttribute('xmlns:news', 'http://www.google.com/schemas/sitemap-news/0.9');
            $xml->appendChild($urlset);

            return $xml->saveXML();
        }

        $publication_name = !empty($this->settings['publication_name'])
            ? $this->settings['publication_name']
            : get_bloginfo('name');

        $publication_language = !empty($this->settings['publication_language'])
            ? $this->settings['publication_language']
            : substr(get_locale(), 0, 2);

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $urlset = $xml->createElement('urlset');
        $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urlset->setAttribute('xmlns:news', 'http://www.google.com/schemas/sitemap-news/0.9');
        $xml->appendChild($urlset);

        foreach ($posts as $post) {
            // Posts marked noindex never belong in the news sitemap.
            if (isset($noindex_set[(int) $post->ID])) {
                continue;
            }

            $permalink = get_permalink($post->ID);

            // Skip excluded URLs
            if (!empty($excluded_urls) && isset($excluded_urls[$permalink])) {
                continue;
            }

            $url_element = $xml->createElement('url');

            $loc = $xml->createElement('loc', esc_url($permalink));
            $url_element->appendChild($loc);

            $news = $xml->createElement('news:news');

            $publication = $xml->createElement('news:publication');
            $pub_name = $xml->createElement('news:name');
            $pub_name->appendChild($xml->createTextNode($this->sanitize_xml_text($publication_name)));
            $publication->appendChild($pub_name);
            $pub_lang = $xml->createElement('news:language');
            $pub_lang->appendChild($xml->createTextNode($this->sanitize_xml_text($publication_language)));
            $publication->appendChild($pub_lang);
            $news->appendChild($publication);

            // A valid publication date is required for news entries. Legacy
            // rows with a zero GMT date ("0000-00-00 00:00:00") make
            // strtotime() resolve before the epoch, which would serialise as
            // a year -0001 date; fall back to the local post date and skip
            // the entry entirely when both are unusable.
            $pub_timestamp = strtotime($post->post_date_gmt);
            if ($pub_timestamp === false || $pub_timestamp < 0) {
                $pub_timestamp = strtotime($post->post_date);
            }
            if ($pub_timestamp === false || $pub_timestamp < 0) {
                continue;
            }

            $pub_date = $xml->createElement(
                'news:publication_date',
                gmdate('c', $pub_timestamp)
            );
            $news->appendChild($pub_date);

            $title = $xml->createElement('news:title');
            $title->appendChild($xml->createTextNode($this->sanitize_xml_text($post->post_title)));
            $news->appendChild($title);

            $url_element->appendChild($news);
            $urlset->appendChild($url_element);
        }

        // Release the object-cache accumulation (post objects, post-meta, and
        // term caches) for the queried posts so memory is reclaimed before
        // the main sitemap pass runs in the same request. Mirrors the proven
        // WP-577/WP-579 pattern on the main sitemap generator. Runs AFTER
        // the foreach above has finished using each $post.
        foreach ($posts as $cleaned_post) {
            clean_post_cache((int) $cleaned_post->ID);
        }
        unset($posts);

        return $xml->saveXML();
    }

    /**
     * Resolve which of the given post IDs are marked noindex.
     *
     * Mirrors the main sitemap generator's batch check: the robots metabox
     * stores metasync_common_robots as a serialized array, and a post is
     * noindex when that array contains 'noindex' => 'noindex'. One query per
     * batch instead of a get_post_meta() call per post.
     *
     * @param int[] $post_ids Post IDs to inspect.
     * @return int[] IDs that are noindex.
     */
    private function get_noindex_post_ids($post_ids)
    {
        global $wpdb;

        if (empty($post_ids)) {
            return [];
        }

        $id_placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        return array_map('intval', (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'metasync_common_robots' AND post_id IN ({$id_placeholders}) AND meta_value LIKE %s",
                array_merge($post_ids, ['%"noindex";s:7:"noindex"%'])
            )
        ));
    }

    /**
     * Sanitize free-form text so it is safe to emit into an XML text node.
     *
     * Raw post titles and publication names can carry named HTML entities
     * (&nbsp;, &hellip;, …) and leftover shortcodes. XML only defines the
     * five predefined entities (&amp; &lt; &gt; &quot; &apos;), so an
     * unrecognised named entity like &nbsp; makes the sitemap invalid
     * ("Entity 'nbsp' not defined").
     *
     * The chain strips shortcodes, removes HTML tags, decodes every HTML
     * entity (including named ones) into its literal character, and
     * collapses leftover whitespace. The result is plain UTF-8 text that
     * DOMDocument::createTextNode() can safely escape when serialising.
     *
     * @param mixed $text Raw text to sanitize.
     * @return string Sanitized plain text.
     */
    private function sanitize_xml_text($text)
    {
        $text = is_string($text) ? $text : '';

        // Remove leftover shortcode-style tags (page builders, [block id="..."]).
        $text = strip_shortcodes($text);
        // Strip HTML tags (and script/style blocks).
        $text = wp_strip_all_tags($text);
        // Decode every HTML entity — named (&nbsp;) and numeric (&#13;) — so
        // the output only ever contains literal characters that createTextNode
        // can re-escape, never raw entity references.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse runs of whitespace (including CR/LF left by decoded entities)
        // into a single space and trim the ends.
        $text = preg_replace('/[\s\r\n\t]+/', ' ', $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Ping Google with the news sitemap URL.
     *
     * @param string $sitemap_url The full URL to the news sitemap.
     */
    public function ping_google($sitemap_url)
    {
        wp_remote_get(
            'https://www.google.com/ping?sitemap=' . urlencode($sitemap_url),
            [
                'timeout'  => 5,
                'blocking' => false,
            ]
        );
    }

    /**
     * Check if conflicting plugins are active.
     *
     * @return bool True if a conflicting plugin is detected.
     */
    public function has_conflicts()
    {
        // Yoast News SEO
        if (class_exists('WPSEO_News')) {
            return true;
        }

        // Rank Math News Sitemap
        if (class_exists('RankMath\\Sitemap\\News\\News')) {
            return true;
        }

        // All in One SEO — only conflicts when its sitemap is enabled and the news addon is loaded
        if ($this->is_aioseo_news_active()) {
            return true;
        }

        return false;
    }

    /**
     * Check if AIOSEO is actively serving a news sitemap.
     *
     * @return bool
     */
    private function is_aioseo_news_active()
    {
        if (!function_exists('aioseo')) {
            return false;
        }

        // AIOSEO needs its general sitemap enabled AND a news addon loaded
        $sitemap_enabled = aioseo()->options->sitemap->general->enable;
        if (!$sitemap_enabled) {
            return false;
        }

        $loaded_addons = aioseo()->addons->getLoadedAddons();
        if (!empty($loaded_addons)) {
            foreach ($loaded_addons as $addon) {
                $slug = is_object($addon) ? ($addon->slug ?? '') : '';
                if (stripos($slug, 'news') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get a list of detected conflicting plugins with human-readable names.
     *
     * @return array Array of conflict messages.
     */
    public function get_conflict_notices()
    {
        $notices = [];

        if (class_exists('WPSEO_News')) {
            $notices[] = __('Yoast News SEO is active. Disable it to use MetaSync\'s news sitemap.', 'metasync');
        }

        if (class_exists('RankMath\\Sitemap\\News\\News')) {
            $notices[] = __('Rank Math News Sitemap is active. Disable it to use MetaSync\'s news sitemap.', 'metasync');
        }

        if ($this->is_aioseo_news_active()) {
            $notices[] = __('All in One SEO News Sitemap is active. Disable it to use MetaSync\'s news sitemap.', 'metasync');
        }

        if (class_exists('GoogleSitemapGenerator')) {
            $notices[] = __('Google XML Sitemaps is active. It uses different sitemap files, so both can coexist.', 'metasync');
        }

        return $notices;
    }

    /**
     * Display name of the third-party plugin currently owning the news sitemap,
     * or an empty string when there is no blocking conflict.
     *
     * Mirrors has_conflicts() so the UI can name the specific plugin. Google XML
     * Sitemaps is intentionally excluded here because it coexists (separate files).
     *
     * @return string
     */
    public function get_conflict_plugin_name()
    {
        if (class_exists('WPSEO_News')) {
            return 'Yoast News SEO';
        }

        if (class_exists('RankMath\\Sitemap\\News\\News')) {
            return 'Rank Math';
        }

        if ($this->is_aioseo_news_active()) {
            return 'All in One SEO';
        }

        return '';
    }
}

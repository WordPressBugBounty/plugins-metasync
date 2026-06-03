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
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => 1000,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'date_query'     => [
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
            $pub_name = $xml->createElement('news:name', esc_html($publication_name));
            $publication->appendChild($pub_name);
            $pub_lang = $xml->createElement('news:language', esc_html($publication_language));
            $publication->appendChild($pub_lang);
            $news->appendChild($publication);

            $pub_date = $xml->createElement(
                'news:publication_date',
                gmdate('c', strtotime($post->post_date_gmt))
            );
            $news->appendChild($pub_date);

            $title = $xml->createElement('news:title', esc_html($post->post_title));
            $news->appendChild($title);

            $url_element->appendChild($news);
            $urlset->appendChild($url_element);
        }

        return $xml->saveXML();
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
}

<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Video Sitemap Generator
 *
 * Generates a video sitemap following the Google Video Sitemap protocol.
 * Auto-detects embedded videos from YouTube, Vimeo, self-hosted, and VideoPress.
 *
 * @package    Metasync
 * @subpackage Metasync/sitemap
 */
class Metasync_Sitemap_Video
{
    /**
     * Video sitemap settings
     *
     * @var array
     */
    private $settings;

    /**
     * Maximum number of video entries per sitemap (Google limit: 50,000)
     *
     * @var int
     */
    private $max_video_entries = 50000;

    /**
     * Initialize the class and load settings.
     */
    public function __construct()
    {
        $defaults = [
            'enabled'       => false,
            'post_types'    => ['post', 'page'],
            'auto_detect'   => true,
            'taxonomies'    => [],
            'excluded_urls' => '',
        ];

        $this->settings = wp_parse_args(
            get_option('metasync_video_sitemap_settings', []),
            $defaults
        );
    }

    /**
     * Generate the video sitemap XML.
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

        $post_types = !empty($this->settings['post_types']) ? (array) $this->settings['post_types'] : ['post', 'page'];

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $urlset = $xml->createElement('urlset');
        $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urlset->setAttribute('xmlns:video', 'http://www.google.com/schemas/sitemap-video/1.1');
        $xml->appendChild($urlset);

        // Build taxonomy query if configured
        $tax_query = [];
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

        $total_video_entries = 0;
        $page = 1;
        $posts_per_page = 500;

        while ($total_video_entries < $this->max_video_entries) {
            $query_args = [
                'post_type'      => $post_types,
                'post_status'    => 'publish',
                'posts_per_page' => $posts_per_page,
                'paged'          => $page,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ];

            if (!empty($tax_query)) {
                $query_args['tax_query'] = $tax_query;
            }

            $posts = get_posts($query_args);

            if (empty($posts)) {
                break;
            }

            foreach ($posts as $post) {
                if ($total_video_entries >= $this->max_video_entries) {
                    break 2;
                }

                $permalink = get_permalink($post->ID);

                // Skip excluded URLs
                if (!empty($excluded_urls) && isset($excluded_urls[$permalink])) {
                    continue;
                }

                $videos = $this->get_videos_for_post($post);

                if (empty($videos)) {
                    continue;
                }

                $url_element = $xml->createElement('url');
                $loc = $xml->createElement('loc', esc_url($permalink));
                $url_element->appendChild($loc);

                $has_valid_video = false;

                foreach ($videos as $video) {
                    if ($total_video_entries >= $this->max_video_entries) {
                        break;
                    }

                    // Skip videos with empty thumbnail — thumbnail_loc is required by Google
                    if (empty($video['thumbnail'])) {
                        continue;
                    }

                    $video_element = $xml->createElement('video:video');

                    $thumbnail = $xml->createElement('video:thumbnail_loc', esc_url($video['thumbnail']));
                    $video_element->appendChild($thumbnail);

                    $title = $xml->createElement('video:title', esc_html($video['title']));
                    $video_element->appendChild($title);

                    $description = $xml->createElement('video:description', esc_html($video['description']));
                    $video_element->appendChild($description);

                    if (!empty($video['url'])) {
                        $content_loc = $xml->createElement('video:content_loc', esc_url($video['url']));
                        $video_element->appendChild($content_loc);
                    }

                    if (!empty($video['duration'])) {
                        $duration = $xml->createElement('video:duration', intval($video['duration']));
                        $video_element->appendChild($duration);
                    }

                    $pub_date = $xml->createElement(
                        'video:publication_date',
                        gmdate('c', strtotime($post->post_date_gmt))
                    );
                    $video_element->appendChild($pub_date);

                    $url_element->appendChild($video_element);
                    $has_valid_video = true;
                    $total_video_entries++;
                }

                if ($has_valid_video) {
                    $urlset->appendChild($url_element);
                }
            }

            $page++;
        }

        return $xml->saveXML();
    }

    /**
     * Get all videos for a given post.
     *
     * @param WP_Post $post The post object.
     * @return array Array of video data arrays with keys: url, thumbnail, title, description, duration.
     */
    private function get_videos_for_post($post)
    {
        $videos = [];

        // Check manual override
        $manual_url = get_post_meta($post->ID, '_metasync_video_url', true);
        if (!empty($manual_url)) {
            if (!filter_var($manual_url, FILTER_VALIDATE_URL)) {
                $manual_url = '';
            }
        }

        if (!empty($manual_url)) {
            $manual_thumbnail = get_post_meta($post->ID, '_metasync_video_thumbnail', true);
            $manual_title     = get_post_meta($post->ID, '_metasync_video_title', true);
            $manual_desc      = get_post_meta($post->ID, '_metasync_video_description', true);
            $manual_duration  = get_post_meta($post->ID, '_metasync_video_duration', true);

            if (empty($manual_thumbnail)) {
                $manual_thumbnail = $this->resolve_thumbnail($manual_url, $post);
            }

            $videos[] = [
                'url'         => $manual_url,
                'thumbnail'   => $manual_thumbnail,
                'title'       => !empty($manual_title) ? $manual_title : $post->post_title,
                'description' => !empty($manual_desc) ? $manual_desc : $this->get_post_description($post),
                'duration'    => $manual_duration,
            ];
        }

        // Auto-detect from content (also runs when manual override is set)
        if (empty($this->settings['auto_detect'])) {
            return $this->deduplicate_videos($videos);
        }

        $content = $post->post_content;

        // YouTube detection (includes youtube-nocookie.com)
        $youtube_patterns = [
            '/youtube(?:-nocookie)?\.com\/watch\?v=([\w-]+)/i',
            '/youtu\.be\/([\w-]+)/i',
            '/youtube(?:-nocookie)?\.com\/embed\/([\w-]+)/i',
        ];

        foreach ($youtube_patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $video_id) {
                    $videos[] = [
                        'url'         => 'https://www.youtube.com/watch?v=' . $video_id,
                        'thumbnail'   => 'https://img.youtube.com/vi/' . $video_id . '/hqdefault.jpg',
                        'title'       => $post->post_title,
                        'description' => $this->get_post_description($post),
                        'duration'    => get_post_meta($post->ID, '_metasync_video_duration', true),
                        '_provider'   => 'youtube',
                        '_video_id'   => $video_id,
                    ];
                }
            }
        }

        // Vimeo detection
        $vimeo_patterns = [
            '/vimeo\.com\/(\d+)/i',
            '/player\.vimeo\.com\/video\/(\d+)/i',
        ];

        foreach ($vimeo_patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $video_id) {
                    $vimeo_url = 'https://vimeo.com/' . $video_id;
                    $thumbnail = $this->get_vimeo_thumbnail($video_id, $vimeo_url);

                    $videos[] = [
                        'url'         => $vimeo_url,
                        'thumbnail'   => $thumbnail,
                        'title'       => $post->post_title,
                        'description' => $this->get_post_description($post),
                        'duration'    => get_post_meta($post->ID, '_metasync_video_duration', true),
                        '_provider'   => 'vimeo',
                        '_video_id'   => $video_id,
                    ];
                }
            }
        }

        // Self-hosted <video> tag detection (supports both src attribute and <source> children)
        // Match <video> tags with src attribute
        if (preg_match_all('/<video[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', $content, $matches)) {
            foreach ($matches[1] as $video_src) {
                if (!filter_var($video_src, FILTER_VALIDATE_URL)) {
                    continue; // skip invalid URLs
                }
                $thumbnail = $this->get_self_hosted_thumbnail($post);

                $videos[] = [
                    'url'         => $video_src,
                    'thumbnail'   => $thumbnail,
                    'title'       => $post->post_title,
                    'description' => $this->get_post_description($post),
                    'duration'    => get_post_meta($post->ID, '_metasync_video_duration', true),
                    '_provider'   => 'self_hosted',
                    '_video_id'   => md5($video_src),
                ];
            }
        }

        // Match <video> tags with <source> children
        if (preg_match_all('/<video[^>]*>.*?<source[^>]+\bsrc=["\']([^"\']+)["\'][^>]*>.*?<\/video>/is', $content, $matches)) {
            foreach ($matches[1] as $video_src) {
                if (!filter_var($video_src, FILTER_VALIDATE_URL)) {
                    continue; // skip invalid URLs
                }
                $thumbnail = $this->get_self_hosted_thumbnail($post);

                $videos[] = [
                    'url'         => $video_src,
                    'thumbnail'   => $thumbnail,
                    'title'       => $post->post_title,
                    'description' => $this->get_post_description($post),
                    'duration'    => get_post_meta($post->ID, '_metasync_video_duration', true),
                    '_provider'   => 'self_hosted',
                    '_video_id'   => md5($video_src),
                ];
            }
        }

        // VideoPress detection
        if (preg_match_all('/videopress\.com\/(?:v|embed)\/([a-zA-Z0-9]+)/i', $content, $matches)) {
            foreach ($matches[1] as $video_id) {
                $videos[] = [
                    'url'         => 'https://videopress.com/v/' . $video_id,
                    'thumbnail'   => $this->get_self_hosted_thumbnail($post),
                    'title'       => $post->post_title,
                    'description' => $this->get_post_description($post),
                    'duration'    => get_post_meta($post->ID, '_metasync_video_duration', true),
                    '_provider'   => 'videopress',
                    '_video_id'   => $video_id,
                ];
            }
        }

        return $this->deduplicate_videos($videos);
    }

    /**
     * Deduplicate videos by provider+video_id (normalizes different URL formats).
     *
     * @param array $videos Array of video data.
     * @return array Deduplicated videos with internal keys stripped.
     */
    private function deduplicate_videos($videos)
    {
        $seen = [];
        $unique_videos = [];
        foreach ($videos as $video) {
            // Normalize key: use provider+id when available, fall back to URL
            if (!empty($video['_provider']) && !empty($video['_video_id'])) {
                $key = $video['_provider'] . ':' . $video['_video_id'];
            } else {
                $key = $video['url'];
            }

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                // Strip internal keys before returning
                unset($video['_provider'], $video['_video_id']);
                $unique_videos[] = $video;
            }
        }

        return $unique_videos;
    }

    /**
     * Get post description (excerpt or trimmed content).
     *
     * @param WP_Post $post The post object.
     * @return string Description text.
     */
    private function get_post_description($post)
    {
        if (!empty($post->post_excerpt)) {
            return wp_strip_all_tags($post->post_excerpt);
        }

        return wp_trim_words(wp_strip_all_tags($post->post_content), 50, '...');
    }

    /**
     * Resolve thumbnail URL for a given video URL.
     *
     * @param string  $video_url The video URL.
     * @param WP_Post $post      The post object.
     * @return string Thumbnail URL.
     */
    private function resolve_thumbnail($video_url, $post)
    {
        // YouTube (includes youtube-nocookie.com)
        if (preg_match('/youtube(?:-nocookie)?\.com\/watch\?v=([\w-]+)/i', $video_url, $m)
            || preg_match('/youtu\.be\/([\w-]+)/i', $video_url, $m)
            || preg_match('/youtube(?:-nocookie)?\.com\/embed\/([\w-]+)/i', $video_url, $m)
        ) {
            return 'https://img.youtube.com/vi/' . $m[1] . '/hqdefault.jpg';
        }

        // Vimeo
        if (preg_match('/vimeo\.com\/(\d+)/i', $video_url, $m)
            || preg_match('/player\.vimeo\.com\/video\/(\d+)/i', $video_url, $m)
        ) {
            return $this->get_vimeo_thumbnail($m[1], $video_url);
        }

        return $this->get_self_hosted_thumbnail($post);
    }

    /**
     * Get Vimeo thumbnail via oEmbed API with global transient caching.
     *
     * @param string $video_id  The Vimeo video ID.
     * @param string $video_url The full Vimeo URL.
     * @return string Thumbnail URL.
     */
    private function get_vimeo_thumbnail($video_id, $video_url)
    {
        $cache_key = 'metasync_vimeo_thumb_' . $video_id;
        $cached = get_transient($cache_key);

        if (false !== $cached) {
            return $cached;
        }

        $response = wp_remote_get(
            'https://vimeo.com/api/oembed.json?url=' . urlencode($video_url),
            ['timeout' => 5]
        );

        $thumbnail = '';
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = wp_remote_retrieve_body($response);
            if (strlen($body) <= 100000) {
                $data = json_decode($body, true);
                if (!empty($data['thumbnail_url'])) {
                    $thumbnail = $data['thumbnail_url'];
                }
            }
        }

        // Cache successful results for 7 days, failures for 1 hour (allows retry)
        $ttl = !empty($thumbnail) ? WEEK_IN_SECONDS : HOUR_IN_SECONDS;
        set_transient($cache_key, $thumbnail, $ttl);

        return $thumbnail;
    }

    /**
     * Get thumbnail for self-hosted videos.
     *
     * @param WP_Post $post The post object.
     * @return string Thumbnail URL.
     */
    private function get_self_hosted_thumbnail($post)
    {
        // Try featured image
        $thumbnail_id = get_post_thumbnail_id($post->ID);
        if ($thumbnail_id) {
            $thumb_url = wp_get_attachment_url($thumbnail_id);
            if ($thumb_url) {
                return $thumb_url;
            }
        }

        // Try first <img> in post content
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $post->post_content, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Check if conflicting plugins are active.
     *
     * @return bool True if a conflicting plugin is detected.
     */
    public function has_conflicts()
    {
        // Yoast Video SEO
        if (class_exists('WPSEO_Video_Sitemap')) {
            return true;
        }

        // Rank Math Video Sitemap
        if (class_exists('RankMath\\Sitemap\\Video\\Video')) {
            return true;
        }

        // All in One SEO — only conflicts when its sitemap is enabled and the video addon is loaded
        if ($this->is_aioseo_video_active()) {
            return true;
        }

        return false;
    }

    /**
     * Check if AIOSEO is actively serving a video sitemap.
     *
     * @return bool
     */
    private function is_aioseo_video_active()
    {
        if (!function_exists('aioseo')) {
            return false;
        }

        $sitemap_enabled = aioseo()->options->sitemap->general->enable;
        if (!$sitemap_enabled) {
            return false;
        }

        $loaded_addons = aioseo()->addons->getLoadedAddons();
        if (!empty($loaded_addons)) {
            foreach ($loaded_addons as $addon) {
                $slug = is_object($addon) ? ($addon->slug ?? '') : '';
                if (stripos($slug, 'video') !== false) {
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

        if (class_exists('WPSEO_Video_Sitemap')) {
            $notices[] = __('Yoast Video SEO is active. Disable it to use MetaSync\'s video sitemap.', 'metasync');
        }

        if (class_exists('RankMath\\Sitemap\\Video\\Video')) {
            $notices[] = __('Rank Math Video Sitemap is active. Disable it to use MetaSync\'s video sitemap.', 'metasync');
        }

        if ($this->is_aioseo_video_active()) {
            $notices[] = __('All in One SEO Video Sitemap is active. Disable it to use MetaSync\'s video sitemap.', 'metasync');
        }

        if (class_exists('GoogleSitemapGenerator')) {
            $notices[] = __('Google XML Sitemaps is active. It uses different sitemap files, so both can coexist.', 'metasync');
        }

        return $notices;
    }
}

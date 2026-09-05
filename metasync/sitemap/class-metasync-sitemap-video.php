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
     * Vimeo OTT / VHX video URL.
     *
     * The leading boundary matters twice over: without it the pattern matches
     * inside an unrelated domain (myvhx.tv) or a path segment
     * (example.com/redir/vhx.tv/videos/42), and because the match is
     * canonicalised to embed.vhx.tv the result is a fabricated URL the site
     * never referenced rather than a harmless false positive.
     */
    const VHX_URL_PATTERN = '~(?:\A|//|[\s"\'>(\[])(?:[\w-]+\.)*vhx\.tv/videos/(\d+)~i';

    /**
     * Maximum number of skipped post IDs recorded for the admin diagnostics.
     *
     * The count is always exact; only the sample of IDs is capped so the
     * stored option cannot grow without bound on a large catalog.
     *
     * @var int
     */
    private $max_skipped_samples = 20;

    /**
     * How many posts had a detected video that could not be emitted because
     * no thumbnail could be resolved.
     *
     * @var int
     */
    private $skipped_no_thumbnail = 0;

    /**
     * Sample of post IDs counted in $skipped_no_thumbnail.
     *
     * @var int[]
     */
    private $skipped_no_thumbnail_ids = [];

    /**
     * How many videos were dropped because the only thumbnail available was a
     * post-level image already spent on an earlier video of the same post.
     *
     * @var int
     */
    private $skipped_duplicate_thumbnail = 0;

    /**
     * How many individual videos were dropped for want of a thumbnail.
     *
     * Counted per VIDEO, unlike $skipped_no_thumbnail which counts posts that
     * emitted nothing at all. A post that emits one working video and drops
     * another is invisible to the per-post counter, and that partial case is
     * more common on a real catalog than an all-or-nothing post.
     *
     * @var int
     */
    private $skipped_no_thumbnail_videos = 0;

    /**
     * Memoized post-level thumbnail for the post currently being processed.
     *
     * Only ever holds one post, so memory stays flat across paged chunks. Null
     * means "not yet computed for $post_thumbnail_post_id".
     *
     * @var string|null
     */
    private $post_thumbnail_cache = null;

    /**
     * Post ID that $post_thumbnail_cache belongs to.
     *
     * @var int
     */
    private $post_thumbnail_post_id = 0;

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
            // Newline/comma separated lists of post-meta keys that hold a video
            // URL / thumbnail URL. Empty by default: sites that do not configure
            // them pay no extra get_post_meta() calls during generation.
            'video_url_meta_keys'       => '',
            'video_thumbnail_meta_keys' => '',
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

        // Reset the diagnostics counters so a second generate() call on the
        // same instance reports this run only.
        $this->skipped_no_thumbnail        = 0;
        $this->skipped_no_thumbnail_ids    = [];
        $this->skipped_duplicate_thumbnail = 0;
        $this->skipped_no_thumbnail_videos = 0;

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
                'post_type'              => $post_types,
                'post_status'            => 'publish',
                'posts_per_page'         => $posts_per_page,
                'paged'                  => $page,
                'orderby'                => ['date' => 'DESC', 'ID' => 'DESC'],
                'no_found_rows'          => true,
                'update_post_term_cache' => false,
                'update_post_meta_cache' => false,
            ];

            if (!empty($tax_query)) {
                $query_args['tax_query'] = $tax_query;
            }

            $posts = get_posts($query_args);

            if (empty($posts)) {
                break;
            }

            // Batch-resolve noindex status for the page in a single query so
            // posts the site owner marked noindex never enter the video
            // sitemap (mirrors the main sitemap generator).
            $noindex_set = array_flip($this->get_noindex_post_ids(array_map('intval', wp_list_pluck($posts, 'ID'))));

            foreach ($posts as $post) {
                if ($total_video_entries >= $this->max_video_entries) {
                    break 2;
                }

                // Posts marked noindex never belong in the video sitemap.
                if (isset($noindex_set[(int) $post->ID])) {
                    continue;
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

                // publication_date is optional; legacy zero-GMT rows
                // ("0000-00-00 00:00:00") would otherwise serialise as a
                // pre-epoch (year -0001) timestamp. Fall back to the local
                // post date, then omit the element if both are unusable.
                $pub_timestamp = strtotime($post->post_date_gmt);
                if ($pub_timestamp === false || $pub_timestamp < 0) {
                    $pub_timestamp = strtotime($post->post_date);
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
                        // Record WHY, so the admin screen can name the reason
                        // instead of showing a blanket "0 URLs is normal".
                        if (isset($video['_skip_reason']) && $video['_skip_reason'] === 'duplicate_post_thumbnail') {
                            $this->skipped_duplicate_thumbnail++;
                        } else {
                            $this->skipped_no_thumbnail_videos++;
                        }
                        continue;
                    }

                    $video_element = $xml->createElement('video:video');

                    // esc_url_raw() (not esc_url()) so ampersands stay as "&":
                    // esc_url() encodes "&" to "&#038;" for display, which
                    // createTextNode() would then re-escape to "&amp;#038;",
                    // corrupting thumbnail URLs that carry query strings.
                    // createTextNode() handles the XML escaping itself.
                    $thumbnail = $xml->createElement('video:thumbnail_loc');
                    $thumbnail->appendChild($xml->createTextNode(esc_url_raw($video['thumbnail'])));
                    $video_element->appendChild($thumbnail);

                    $title = $xml->createElement('video:title');
                    $title->appendChild($xml->createTextNode($this->sanitize_xml_text($video['title'])));
                    $video_element->appendChild($title);

                    $description = $xml->createElement('video:description');
                    $description->appendChild($xml->createTextNode($this->sanitize_xml_text($video['description'])));
                    $video_element->appendChild($description);

                    // Direct media files (self-hosted <video>/<source> src)
                    // are the only entries that may use content_loc. YouTube,
                    // Vimeo, and VideoPress URLs point at a player page, not a
                    // media file, so they are emitted as player_loc — sending a
                    // watch page as content_loc is invalid under the Google
                    // video sitemap protocol.
                    if (!empty($video['url'])) {
                        if (!empty($video['direct_media'])) {
                            $content_loc = $xml->createElement('video:content_loc', esc_url($video['url']));
                            $video_element->appendChild($content_loc);
                        } else {
                            $player_loc = $xml->createElement('video:player_loc', esc_url($video['url']));
                            $video_element->appendChild($player_loc);
                        }
                    }

                    if (!empty($video['duration'])) {
                        $duration = $xml->createElement('video:duration', intval($video['duration']));
                        $video_element->appendChild($duration);
                    }

                    if ($pub_timestamp !== false && $pub_timestamp >= 0) {
                        $pub_date = $xml->createElement(
                            'video:publication_date',
                            gmdate('c', $pub_timestamp)
                        );
                        $video_element->appendChild($pub_date);
                    }

                    $url_element->appendChild($video_element);
                    $has_valid_video = true;
                    $total_video_entries++;
                }

                if ($has_valid_video) {
                    $urlset->appendChild($url_element);
                } else {
                    // The post had at least one detected video but none of them
                    // could be emitted, which in practice always means no
                    // thumbnail could be resolved (thumbnail_loc is required by
                    // Google — see the skip above). Record it so the admin
                    // screen can name the real reason instead of reporting a
                    // bare "0 URLs", which reads as "this is normal".
                    $this->skipped_no_thumbnail++;
                    if (count($this->skipped_no_thumbnail_ids) < $this->max_skipped_samples) {
                        $this->skipped_no_thumbnail_ids[] = (int) $post->ID;
                    }
                }
            }

            // Release the per-chunk object-cache accumulation (post objects,
            // post-meta, and term caches) so memory stays flat across pages
            // regardless of catalog size. Mirrors the proven WP-577/WP-579
            // pattern on the main sitemap generator. Runs AFTER the foreach
            // above has finished using each $post and BEFORE the next page
            // loads its post objects.
            foreach (wp_list_pluck($posts, 'ID') as $chunk_post_id) {
                clean_post_cache((int) $chunk_post_id);
            }

            unset($posts);
            $page++;
        }

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
     * Whether a URL points at a media file rather than a player page.
     *
     * Used for the manual video override, where the stored URL may be either
     * a self-hosted file or an embed/watch page on a video platform.
     *
     * @param string $url Candidate video URL.
     * @return bool True when the URL path ends in a media-file extension.
     */
    private function is_direct_media_url($url)
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            return false;
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $media_extensions = [
            'mp4', 'm4v', 'webm', 'ogv', 'ogg', 'mov', 'avi', 'mkv', 'wmv', 'flv', 'mpg', 'mpeg', 'm3u8', 'mpd',
        ];
        return in_array($extension, $media_extensions, true);
    }

    /**
     * Identify the provider and id behind a video URL.
     *
     * The manual override and the configured-meta path both accept whatever
     * URL shape a site happens to store. Without resolving it to the same
     * provider+id namespace that content detection uses, the same video
     * arriving from two sources lands in two different de-duplication key
     * spaces — `youtu.be/X` in a custom field against `watch?v=X` in the
     * content emitted two entries for one video, with an identical
     * thumbnail_loc on both.
     *
     * @param string $url Candidate video URL.
     * @return array{provider: string, video_id: string}|null Null when the
     *         URL belongs to no provider we recognise.
     */
    private function identify_provider($url)
    {
        if (preg_match('/youtube(?:-nocookie)?\\.com\\/watch\\?v=([\\w-]+)/i', $url, $m)
            || preg_match('/youtu\\.be\\/([\\w-]+)/i', $url, $m)
            || preg_match('/youtube(?:-nocookie)?\\.com\\/embed\\/([\\w-]+)/i', $url, $m)
        ) {
            return ['provider' => 'youtube', 'video_id' => $m[1]];
        }

        if (preg_match('/player\\.vimeo\\.com\\/video\\/(\\d+)/i', $url, $m)
            || preg_match('/vimeo\\.com\\/(\\d+)/i', $url, $m)
        ) {
            return ['provider' => 'vimeo', 'video_id' => $m[1]];
        }

        if (preg_match(self::VHX_URL_PATTERN, $url, $m)) {
            return ['provider' => 'vhx', 'video_id' => $m[1]];
        }

        if (preg_match('/videopress\\.com\\/(?:v|embed)\\/([a-zA-Z0-9]+)/i', $url, $m)) {
            return ['provider' => 'videopress', 'video_id' => $m[1]];
        }

        return null;
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

        // One read per post, not per detected video: generate() runs with
        // update_post_meta_cache => false, so this is an uncached query and
        // every branch below wants the same value.
        $duration = get_post_meta($post->ID, '_metasync_video_duration', true);

        // Check manual override
        $manual_url = get_post_meta($post->ID, '_metasync_video_url', true);
        if (!empty($manual_url)) {
            if (!filter_var($manual_url, FILTER_VALIDATE_URL)) {
                $manual_url = '';
            }
        }

        if (!empty($manual_url)) {
            $manual_thumbnail = $this->extract_meta_url(get_post_meta($post->ID, '_metasync_video_thumbnail', true));
            $manual_title     = get_post_meta($post->ID, '_metasync_video_title', true);
            $manual_desc      = get_post_meta($post->ID, '_metasync_video_description', true);

            if (empty($manual_thumbnail)) {
                $manual_thumbnail = $this->resolve_thumbnail($manual_url, $post);
            }

            $videos[] = [
                'url'          => $manual_url,
                'direct_media' => $this->is_direct_media_url($manual_url),
                'thumbnail'    => $manual_thumbnail,
                'title'        => !empty($manual_title) ? $manual_title : $post->post_title,
                'description'  => !empty($manual_desc) ? $manual_desc : $this->get_post_description($post),
                'duration'     => $duration,
            ] + $this->provider_keys($manual_url);
        }

        // Explicitly configured post-meta keys (ACF and other custom fields).
        // This runs regardless of auto_detect: it is site configuration, not
        // content detection. Page-builder and CPT-heavy themes commonly build
        // their player from a custom field, leaving post_content with no trace
        // of the video at all.
        foreach ($this->get_videos_from_configured_meta($post) as $configured_video) {
            $videos[] = $configured_video;
        }

        // Auto-detect from content (also runs when manual override is set)
        if (empty($this->settings['auto_detect'])) {
            return $this->finalize_videos($videos, $post);
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
                        'duration'    => $duration,
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
                    // Through the resolver, so a failed or rate-limited
                    // oEmbed lookup falls back to the post-level chain
                    // instead of dropping the entry. Failures are cached for
                    // an hour, so without this one outage during generation
                    // silently empties a site's whole Vimeo section while its
                    // featured images sat available.
                    $thumbnail = $this->resolve_thumbnail($vimeo_url, $post);

                    $videos[] = [
                        'url'         => $vimeo_url,
                        'thumbnail'   => $thumbnail,
                        'title'       => $post->post_title,
                        'description' => $this->get_post_description($post),
                        'duration'    => $duration,
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
                    'url'          => $video_src,
                    'direct_media' => true,
                    'thumbnail'    => $thumbnail,
                    'title'        => $post->post_title,
                    'description'  => $this->get_post_description($post),
                    'duration'     => $duration,
                    '_provider'    => 'self_hosted',
                    '_video_id'    => md5($video_src),
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
                    'url'          => $video_src,
                    'direct_media' => true,
                    'thumbnail'    => $thumbnail,
                    'title'        => $post->post_title,
                    'description'  => $this->get_post_description($post),
                    'duration'     => $duration,
                    '_provider'    => 'self_hosted',
                    '_video_id'    => md5($video_src),
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
                    'duration'    => $duration,
                    '_provider'   => 'videopress',
                    '_video_id'   => $video_id,
                ];
            }
        }

        // Vimeo OTT / VHX detection. Matches both the embed host and the bare
        // one, so `embed.vhx.tv/videos/123` and `vhx.tv/videos/123` collapse to
        // a single entry via _provider/_video_id.
        //
        // Emitted as player_loc (direct_media stays false): a VHX URL is a
        // player page, not a media file, so content_loc would be invalid under
        // the Google video sitemap protocol — same reasoning as YouTube/Vimeo.
        //
        // Slug-based VHX watch pages (watch.<site>.com/videos/<slug>) are
        // deliberately NOT matched: the host is site-specific and the slug
        // cannot be attributed to a video id.
        if (preg_match_all(self::VHX_URL_PATTERN, $content, $matches)) {
            foreach ($matches[1] as $video_id) {
                $videos[] = [
                    'url'         => 'https://embed.vhx.tv/videos/' . $video_id,
                    'thumbnail'   => $this->get_self_hosted_thumbnail($post),
                    'title'       => $post->post_title,
                    'description' => $this->get_post_description($post),
                    'duration'    => $duration,
                    '_provider'   => 'vhx',
                    '_video_id'   => $video_id,
                ];
            }
        }

        return $this->finalize_videos($videos, $post);
    }

    /**
     * Read videos out of explicitly configured post-meta keys.
     *
     * Sites whose theme or page builder stores the video in a custom field
     * (ACF and friends) leave nothing in post_content to detect, so the only
     * cheap way to reach that data is to be told which key holds it.
     *
     * Costs nothing when unconfigured: generate() runs with
     * update_post_meta_cache => false, so every key checked here is an
     * uncached meta read. Bailing out on an empty setting keeps existing
     * sites at exactly their current query count.
     *
     * @param WP_Post $post The post object.
     * @return array<int, array<string, mixed>> Video entries (possibly empty).
     */
    private function get_videos_from_configured_meta($post)
    {
        $url_keys = $this->parse_meta_key_list($this->settings['video_url_meta_keys'] ?? '');

        if (empty($url_keys)) {
            return [];
        }

        $videos = [];

        // Read once for the whole method: this runs under
        // update_post_meta_cache => false, so it is an uncached query.
        $duration = get_post_meta($post->ID, '_metasync_video_duration', true);

        // Treat the configured keys as fallbacks, matching the settings text:
        // the first key containing a valid URL wins. Emitting every populated
        // key turns alternate/fallback fields into duplicate sitemap entries.
        $raw_url = '';
        foreach ($url_keys as $url_key) {
            $candidate = $this->extract_meta_url(get_post_meta($post->ID, $url_key, true));
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_URL)) {
                $raw_url = $candidate;
                break;
            }
        }

        if ($raw_url !== '') {
            $videos[] = [
                'url'          => $raw_url,
                'direct_media' => $this->is_direct_media_url($raw_url),
                // resolve_thumbnail() owns the whole source chain, including
                // the configured thumbnail keys, so the precedence stays in
                // one place for every caller.
                'thumbnail'    => $this->resolve_thumbnail($raw_url, $post),
                'title'        => $post->post_title,
                'description'  => $this->get_post_description($post),
                'duration'     => $duration,
            ] + $this->provider_keys($raw_url);
        }

        return $videos;
    }

    /**
     * Extract a URL from scalar and common ACF-style meta return values.
     *
     * ACF URL/image fields may return a scalar URL, an array containing
     * `url`/`src`, or an attachment ID. Reading only reset($value) drops the
     * URL when the array is associative (the usual image-array format).
     *
     * @param mixed $value Raw post-meta value.
     * @return string A trimmed URL, or an empty string when none is present.
     */
    private function extract_meta_url($value)
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_numeric($value) && (int) $value > 0 && function_exists('wp_get_attachment_url')) {
            $attachment_url = wp_get_attachment_url((int) $value);
            return is_string($attachment_url) ? trim($attachment_url) : '';
        }

        if (!is_array($value)) {
            return '';
        }

        foreach (['url', 'src'] as $url_key) {
            if (array_key_exists($url_key, $value)) {
                $url = $this->extract_meta_url($value[$url_key]);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        foreach (['ID', 'id', 'attachment_id'] as $id_key) {
            if (array_key_exists($id_key, $value)) {
                $url = $this->extract_meta_url($value[$id_key]);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        // Support a numerically indexed array such as ['https://...'] while
        // avoiding arbitrary nested values that are not URL-like.
        foreach ($value as $item) {
            $url = $this->extract_meta_url($item);
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                return $url;
            }
        }

        return '';
    }

    /**
     * De-duplication keys for a URL of unknown shape.
     *
     * Falls back to the URL hash only when no provider matches, so an
     * unrecognised player still de-duplicates against itself.
     *
     * @param string $url Video URL.
     * @return array{_provider: string, _video_id: string}
     */
    private function provider_keys($url)
    {
        $identified = $this->identify_provider($url);

        if ($identified !== null) {
            return ['_provider' => $identified['provider'], '_video_id' => $identified['video_id']];
        }

        return ['_provider' => 'url', '_video_id' => md5($url)];
    }

    /**
     * Split a configured meta-key list into sanitized key names.
     *
     * Accepts newline and/or comma separated input so the settings field is
     * forgiving about how a site owner pastes their field names.
     *
     * Deliberately does NOT use sanitize_key(): that lowercases, and custom
     * field names are case-sensitive — ACF and hand-rolled meta boxes both
     * allow mixed case (`episodeVideoURL`), which lowercasing would silently
     * turn into a key that matches nothing. Only characters that cannot
     * appear in a meta key are stripped.
     *
     * Capped so a pasted wall of text cannot turn into one uncached
     * get_post_meta() per line per post during generation.
     *
     * @param mixed $raw Raw setting value.
     * @return string[] Sanitized, de-duplicated meta keys (max 10).
     */
    private function parse_meta_key_list($raw)
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[\r\n,]+/', $raw);
        $keys  = [];

        foreach ((array) $parts as $part) {
            $key = preg_replace('/[^A-Za-z0-9_\-.:]/', '', trim((string) $part));
            if ($key !== '' && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
            if (count($keys) >= 10) {
                break;
            }
        }

        return $keys;
    }

    /**
     * Apply the extension filter and de-duplicate.
     *
     * The filter runs BEFORE de-duplication so entries contributed by a third
     * party are normalised on the same terms as detected ones.
     *
     * @param array   $videos Detected video entries.
     * @param WP_Post $post   The post object.
     * @return array De-duplicated video entries.
     */
    private function finalize_videos($videos, $post)
    {
        /**
         * Filter the videos discovered for a post before they are emitted.
         *
         * Lets a site supply videos the built-in detection cannot see — most
         * usefully when the player is injected by a theme template and so
         * never appears in post_content.
         *
         * Each entry should provide at least 'url' and 'thumbnail'; an entry
         * with no resolvable thumbnail is dropped, because thumbnail_loc is
         * required by the Google video sitemap protocol. Set '_provider' and
         * '_video_id' to participate in de-duplication.
         *
         * @param array   $videos Video entries for this post.
         * @param WP_Post $post   The post being processed.
         */
        /** @var mixed $filtered */
        $filtered = apply_filters('metasync_video_sitemap_videos_for_post', $videos, $post);

        $videos = $this->deduplicate_videos(is_array($filtered) ? $filtered : $videos);

        $videos = $this->apply_thumbnail_filter($videos, $post);

        return $this->enforce_single_use_post_thumbnail($videos);
    }

    /**
     * Stop one image standing in as the thumbnail for several videos on the
     * same post.
     *
     * Google treats video:thumbnail_loc as a de-duplication signal and
     * documents that the same thumbnail must not be used for different
     * videos — reusing one makes distinct videos read as the same video, and
     * Google may then mix metadata between them. The featured image, the
     * Open Graph image, a configured thumbnail field, the manual thumbnail
     * and the first content image are all per-POST, so on a post with several
     * videos only the first of them may consume that image. Provider-derived
     * thumbnails (YouTube's id-based URL, Vimeo's oEmbed result) are
     * per-VIDEO and are never restricted.
     *
     * Enforced directly on the thumbnail URL rather than on where the
     * thumbnail came from. An earlier version tracked a per-entry "scope"
     * (video-level vs post-level) and allowed a single post-level entry; that
     * mislabelled several sources — a manual thumbnail belongs to the one
     * manual video and can never be reused, and entries supplied through the
     * videos filter carried no scope at all, so a filter written to the
     * documented contract had every entry after the first discarded.
     * Comparing the URLs tests the property Google actually cares about and
     * is correct regardless of source.
     *
     * Videos left without a thumbnail are dropped by generate() and counted,
     * so the admin screen can explain the omission rather than leaving it
     * silent.
     *
     * Runs last, after de-duplication and the thumbnail filter, so a
     * thumbnail is neither spent on an entry about to be discarded nor able
     * to slip past this check by arriving late.
     *
     * @param array $videos De-duplicated video entries, in emit order.
     * @return array Entries whose thumbnail repeats an earlier one, cleared.
     */
    private function enforce_single_use_post_thumbnail($videos)
    {
        $used = [];

        foreach ($videos as $index => $video) {
            $thumbnail = isset($video['thumbnail']) ? (string) $video['thumbnail'] : '';

            if ($thumbnail === '') {
                continue;
            }

            if (!isset($used[$thumbnail])) {
                $used[$thumbnail] = true;
                continue;
            }

            $videos[$index]['thumbnail']    = '';
            $videos[$index]['_skip_reason'] = 'duplicate_post_thumbnail';
        }

        return $videos;
    }

    /**
     * Deduplicate videos by provider+video_id (normalizes different URL formats).
     *
     * @param array $videos Array of video data.
     * @return array Deduplicated videos with internal keys stripped.
     */
    private function deduplicate_videos($videos)
    {
        $seen      = [];
        $seen_urls = [];
        $unique_videos = [];
        foreach ($videos as $video) {
            $url = isset($video['url']) ? (string) $video['url'] : '';

            // De-duplicate on the URL as well as on provider+id. The two axes
            // catch different cases and neither is sufficient alone:
            //
            //  - provider+id collapses the same video reached through
            //    different URL shapes (youtu.be/X vs watch?v=X,
            //    embed.vhx.tv/videos/N vs site.vhx.tv/videos/N);
            //  - the URL catches the same video arriving from sources that
            //    namespace their ids differently. The manual override sets no
            //    provider at all and the configured-meta path namespaces by
            //    meta key, so a video present BOTH there and in the content
            //    produced two identical entries — precisely the duplicate
            //    Google is asked not to see.
            if ($url !== '' && isset($seen_urls[$url])) {
                continue;
            }

            // Normalize key: use provider+id when available, fall back to URL
            if (!empty($video['_provider']) && !empty($video['_video_id'])) {
                $key = $video['_provider'] . ':' . $video['_video_id'];
            } else {
                $key = $url;
            }

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                if ($url !== '') {
                    $seen_urls[$url] = true;
                }
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
            return $this->sanitize_xml_text($post->post_excerpt);
        }

        return $this->sanitize_xml_text(wp_trim_words(wp_strip_all_tags($post->post_content), 50, '...'));
    }

    /**
     * Sanitize free-form text so it is safe to emit into an XML text node.
     *
     * Raw post content/excerpt/titles can carry named HTML entities
     * (&nbsp;, &hellip;, …) and leftover page-builder shortcodes
     * ([block id="..."], [et_pb_*]). XML only defines the five predefined
     * entities (&amp; &lt; &gt; &quot; &apos;), so an unrecognised named
     * entity like &nbsp; makes the sitemap invalid ("Entity 'nbsp' not
     * defined"). Shortcodes are not meaningful in a sitemap either.
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
     * Resolve a thumbnail for a video URL.
     *
     * Provider-derived values first (unique per video by construction), then
     * the post-level chain.
     *
     * @param string  $video_url The video URL.
     * @param WP_Post $post      The post object.
     * @return string Thumbnail URL, or '' when none could be resolved.
     */
    private function resolve_thumbnail($video_url, $post)
    {
        $thumbnail = '';

        // YouTube (includes youtube-nocookie.com)
        if (preg_match('/youtube(?:-nocookie)?\.com\/watch\?v=([\w-]+)/i', $video_url, $m)
            || preg_match('/youtu\.be\/([\w-]+)/i', $video_url, $m)
            || preg_match('/youtube(?:-nocookie)?\.com\/embed\/([\w-]+)/i', $video_url, $m)
        ) {
            $thumbnail = 'https://img.youtube.com/vi/' . $m[1] . '/hqdefault.jpg';
        } elseif (preg_match('/vimeo\.com\/(\d+)/i', $video_url, $m)
            || preg_match('/player\.vimeo\.com\/video\/(\d+)/i', $video_url, $m)
        ) {
            // Vimeo
            $thumbnail = $this->get_vimeo_thumbnail($m[1], $video_url);
        }

        if ($thumbnail === '') {
            $thumbnail = $this->get_post_level_thumbnail($post);
        }

        return $thumbnail;
    }

    /**
     * Give integrations the last word on every entry's thumbnail.
     *
     * Applied here rather than inside resolve_thumbnail() so it reaches
     * EVERY entry: the content-detection branches assign their thumbnails
     * inline and never call the resolver, so filtering there would silently
     * skip the providers most likely to need it.
     *
     * @param array   $videos Video entries.
     * @param WP_Post $post   The post being processed.
     * @return array Entries with filtered thumbnails.
     */
    private function apply_thumbnail_filter($videos, $post)
    {
        foreach ($videos as $index => $video) {
            $current = isset($video['thumbnail']) ? $video['thumbnail'] : '';
            $url     = isset($video['url']) ? $video['url'] : '';

            /**
             * Filter the resolved thumbnail for a single video entry.
             *
             * Last chance to supply a thumbnail for a provider the plugin
             * cannot derive one from — Vimeo OTT (VHX), Wistia, and other
             * players have no zero-cost thumbnail lookup. Returning an empty
             * string leaves the entry to be skipped, because thumbnail_loc is
             * required by Google.
             *
             * Must be unique per video: Google treats the thumbnail as a
             * de-duplication signal and explicitly documents that the same
             * thumbnail must not be reused across different videos — a value
             * returned here is still compared against the other entries on
             * the post, so returning one image for every video drops all but
             * the first rather than emitting duplicates.
             *
             * @param string  $thumbnail Resolved thumbnail URL ('' if none).
             * @param string  $url       The video URL being resolved.
             * @param WP_Post $post      The post being processed.
             */
            /** @var mixed $filtered */
            $filtered = apply_filters('metasync_video_sitemap_thumbnail', $current, $url, $post);

            if (is_string($filtered) && $filtered !== $current) {
                $videos[$index]['thumbnail'] = $filtered;
            }
        }

        return $videos;
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
     * Get thumbnail for videos whose provider cannot derive one.
     *
     * Retained as the name used by the content-detection branches. Delegates
     * to the memoized post-level resolver.
     *
     * @param WP_Post $post The post object.
     * @return string Thumbnail URL, or '' when none could be resolved.
     */
    private function get_self_hosted_thumbnail($post)
    {
        return $this->get_post_level_thumbnail($post);
    }

    /**
     * Resolve the post-level fallback thumbnail, memoized per post.
     *
     * The chain is walked at most once per post even when the post holds
     * several videos: generate() runs with update_post_meta_cache => false,
     * so every candidate is an uncached meta read and repeating them per
     * video would multiply queries on video-heavy posts.
     *
     * Only one post is ever held, so memory stays flat across paged chunks.
     *
     * @param WP_Post $post The post object.
     * @return string Thumbnail URL, or '' when none could be resolved.
     */
    private function get_post_level_thumbnail($post)
    {
        $post_id = (int) $post->ID;

        if ($this->post_thumbnail_post_id === $post_id && $this->post_thumbnail_cache !== null) {
            return $this->post_thumbnail_cache;
        }

        $this->post_thumbnail_post_id = $post_id;
        $this->post_thumbnail_cache   = $this->compute_post_level_thumbnail($post);

        return $this->post_thumbnail_cache;
    }

    /**
     * Walk the post-level thumbnail candidates.
     *
     * Every candidate here is per-post. A site-wide default, logo, or
     * placeholder must never be returned: Google uses the thumbnail as a
     * de-duplication signal and documents that the same thumbnail must not be
     * used for different videos, so a shared image would make distinct videos
     * collapse into one. An empty return is the correct outcome — the entry is
     * then skipped and reported in the admin diagnostics.
     *
     * @param WP_Post $post The post object.
     * @return string Thumbnail URL, or '' when none could be resolved.
     */
    private function compute_post_level_thumbnail($post)
    {
        // Explicitly configured thumbnail meta keys (ACF and other custom
        // fields). Skipped entirely when unconfigured so existing sites pay
        // no extra uncached meta reads.
        foreach ($this->parse_meta_key_list($this->settings['video_thumbnail_meta_keys'] ?? '') as $thumbnail_key) {
            $candidate = $this->extract_meta_url(get_post_meta($post->ID, $thumbnail_key, true));
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_URL)) {
                return $candidate;
            }
        }

        // Per-post Open Graph image. Mirrors the precedence in
        // Metasync_OpenGraph, which reads _metasync_og_image before falling
        // back to the featured image.
        $og_image = get_post_meta($post->ID, '_metasync_og_image', true);
        if (is_string($og_image)) {
            $og_image = trim($og_image);
            if ($og_image !== '' && filter_var($og_image, FILTER_VALIDATE_URL)) {
                return $og_image;
            }
        }

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
     * Number of posts in the last generate() run that had a detected video but
     * could not be emitted because no thumbnail resolved.
     *
     * @return int
     */
    public function get_skipped_no_thumbnail_count()
    {
        return $this->skipped_no_thumbnail;
    }

    /**
     * Sample of post IDs counted by get_skipped_no_thumbnail_count().
     *
     * Capped, so this is a sample for surfacing in the admin UI rather than a
     * complete list.
     *
     * @return int[]
     */
    public function get_skipped_no_thumbnail_post_ids()
    {
        return $this->skipped_no_thumbnail_ids;
    }

    /**
     * Number of videos in the last generate() run that were dropped because
     * the only thumbnail available was a post-level image already used by an
     * earlier video on the same post.
     *
     * Counted separately from get_skipped_no_thumbnail_count() so the admin
     * screen can distinguish "no image at all" from "would have duplicated
     * another video's thumbnail", which need different fixes.
     *
     * @return int
     */
    public function get_skipped_duplicate_thumbnail_count()
    {
        return $this->skipped_duplicate_thumbnail;
    }

    /**
     * Number of individual videos dropped for want of a thumbnail in the last
     * generate() run, including those on posts that still emitted other
     * videos.
     *
     * @return int
     */
    public function get_skipped_no_thumbnail_video_count()
    {
        return $this->skipped_no_thumbnail_videos;
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

    /**
     * Display name of the third-party plugin currently owning the video sitemap,
     * or an empty string when there is no blocking conflict.
     *
     * Mirrors has_conflicts() so the UI can name the specific plugin. Google XML
     * Sitemaps is intentionally excluded here because it coexists (separate files).
     *
     * @return string
     */
    public function get_conflict_plugin_name()
    {
        if (class_exists('WPSEO_Video_Sitemap')) {
            return 'Yoast Video SEO';
        }

        if (class_exists('RankMath\\Sitemap\\Video\\Video')) {
            return 'Rank Math';
        }

        if ($this->is_aioseo_video_active()) {
            return 'All in One SEO';
        }

        return '';
    }
}

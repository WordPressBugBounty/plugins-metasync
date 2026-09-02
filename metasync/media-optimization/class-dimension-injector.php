<?php
/**
 * Metasync_Dimension_Injector
 * Scans HTML for <img> tags missing width/height and injects them
 * based on file metadata to prevent Cumulative Layout Shift (CLS).
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Dimension_Injector {

    /** In-memory cache to avoid repeated file lookups within a request. */
    private array $cache = [];

    /** Transient key prefix for persisted image dimensions. */
    private const CACHE_PREFIX = 'ms_dim_';

    /** How long resolved dimensions stay cached, in seconds (7 days). */
    private const CACHE_TTL = 604800;

    /** Transient key prefix for failed remote lookups (negative cache). */
    private const FAIL_PREFIX = 'ms_dimfail_';

    /** How long a failed remote lookup stays cached, in seconds (1 hour). */
    private const FAIL_TTL = 3600;

    /** Cap on bytes fetched from a remote image header sniff. */
    private const REMOTE_MAX_BYTES = 65536;

    public function __construct() {
        add_filter('the_content', [$this, 'inject_dimensions'], 30);
        add_filter('post_thumbnail_html', [$this, 'inject_dimensions'], 30);
        add_filter('widget_text', [$this, 'inject_dimensions'], 30);
    }

    /**
     * Find <img> tags missing width or height and inject dimensions.
     */
    public function inject_dimensions(string $content): string {
        if (empty($content) || is_admin() || is_feed()) {
            return $content;
        }

        return preg_replace_callback('/<img\s[^>]+>/i', function ($matches) {
            $tag = $matches[0];

            $has_width  = preg_match('/\swidth\s*=/i', $tag);
            $has_height = preg_match('/\sheight\s*=/i', $tag);

            // Both already present - nothing to do
            if ($has_width && $has_height) {
                return $tag;
            }

            $dims = $this->get_dimensions($tag);
            if (!$dims) {
                return $tag;
            }

            if (!$has_width) {
                $tag = $this->add_attribute($tag, 'width', (string) $dims['width']);
            }
            if (!$has_height) {
                $tag = $this->add_attribute($tag, 'height', (string) $dims['height']);
            }

            return $tag;
        }, $content);
    }

    /**
     * Try to determine image dimensions from multiple sources.
     */
    private function get_dimensions(string $img_tag): ?array {
        if (!preg_match('/src=["\']([^"\']+)["\']/i', $img_tag, $m)) {
            return null;
        }
        $src = $m[1];

        // Request-scoped cache: avoids repeated work within a single render.
        if (isset($this->cache[$src])) {
            return $this->cache[$src];
        }

        // Persistent cache: skips disk I/O for images resolved on a prior request.
        $cached = $this->get_cached_dimensions($src);
        if ($cached !== null) {
            $this->cache[$src] = $cached;
            return $cached;
        }

        $dims = null;

        // Strategy 1: Try to find WordPress attachment by URL
        $dims = $this->get_dims_from_attachment($src);

        // Strategy 2: Try to read the local file directly
        if (!$dims) {
            $dims = $this->get_dims_from_local_file($src);
        }

        // Strategy 3: For external images, try getimagesize with URL (slower)
        if (!$dims) {
            $dims = $this->get_dims_from_remote($src);
        }

        if ($dims) {
            $this->cache[$src] = $dims;
            $this->set_cached_dimensions($src, $dims);
        }

        return $dims;
    }

    /**
     * Build the transient key for a given image src.
     */
    private static function cache_key(string $src): string {
        return self::CACHE_PREFIX . md5($src);
    }

    /**
     * Read previously resolved dimensions from the persistent transient cache.
     * Returns null on a cache miss or a malformed/partial cached value.
     */
    private function get_cached_dimensions(string $src): ?array {
        $cached = get_transient(self::cache_key($src));

        if (is_array($cached)
            && isset($cached['width'], $cached['height'])
            && (int) $cached['width'] > 0
            && (int) $cached['height'] > 0
        ) {
            return [
                'width'  => (int) $cached['width'],
                'height' => (int) $cached['height'],
            ];
        }

        return null;
    }

    /**
     * Persist resolved dimensions so subsequent requests avoid file I/O.
     */
    private function set_cached_dimensions(string $src, array $dims): void {
        set_transient(
            self::cache_key($src),
            [
                'width'  => (int) $dims['width'],
                'height' => (int) $dims['height'],
            ],
            self::CACHE_TTL
        );
    }

    /**
     * Purge cached dimensions for every size URL of an attachment.
     *
     * Hooked on `delete_attachment` and `wp_update_attachment_metadata` so the
     * cache can never outlive (or contradict) the image: deleting an image and
     * re-uploading a different one under the same filename, or regenerating
     * thumbnails to new dimensions, would otherwise serve stale width/height
     * for up to CACHE_TTL. Registered unconditionally (even when dimension
     * injection is disabled) so stale entries are always cleaned up.
     *
     * @param int $attachment_id Attachment whose cached dimensions to clear.
     */
    public static function purge_attachment_cache($attachment_id): void {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return;
        }

        foreach (self::collect_attachment_urls($attachment_id) as $url) {
            delete_transient(self::cache_key($url));
        }
    }

    /**
     * `wp_update_attachment_metadata` filter wrapper: purge the cache, then
     * return the metadata untouched so the filter chain is unaffected.
     *
     * @param mixed $data          Attachment metadata being saved.
     * @param int   $attachment_id Attachment ID.
     * @return mixed The unmodified $data.
     */
    public static function purge_on_metadata_update($data, $attachment_id) {
        self::purge_attachment_cache($attachment_id);
        return $data;
    }

    /**
     * Collect the full-size URL plus every registered sub-size URL for an
     * attachment. Sub-size files live in the same directory as the full-size
     * file, so each is the full URL with its basename swapped for the size
     * filename.
     *
     * @param int $attachment_id Attachment ID.
     * @return string[] Image URLs (possibly empty).
     */
    private static function collect_attachment_urls(int $attachment_id): array {
        $full = wp_get_attachment_url($attachment_id);
        if (!is_string($full) || $full === '') {
            return [];
        }

        $urls = [$full];

        $meta = wp_get_attachment_metadata($attachment_id);
        if (is_array($meta) && !empty($meta['sizes'])) {
            $slash = strrpos($full, '/');
            $base  = $slash === false ? '' : substr($full, 0, $slash + 1);
            foreach ($meta['sizes'] as $size_data) {
                if (!empty($size_data['file'])) {
                    $urls[] = $base . $size_data['file'];
                }
            }
        }

        return $urls;
    }

    /**
     * Look up dimensions from WP attachment metadata.
     */
    private function get_dims_from_attachment(string $url): ?array {
        $attachment_id = attachment_url_to_postid($url);

        if (!$attachment_id) {
            // Try without size suffix (e.g., image-300x200.jpg -> image.jpg)
            $clean_url = preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $url);
            $attachment_id = attachment_url_to_postid($clean_url);
        }

        if (!$attachment_id) {
            return null;
        }

        $meta = wp_get_attachment_metadata($attachment_id);
        if (!$meta) {
            return null;
        }

        // Check sub-sizes first
        if (!empty($meta['sizes'])) {
            $filename = wp_basename($url);
            foreach ($meta['sizes'] as $size_data) {
                if ($size_data['file'] === $filename) {
                    return [
                        'width'  => (int) $size_data['width'],
                        'height' => (int) $size_data['height'],
                    ];
                }
            }
        }

        // A sized variant with no registered sub-size (crop added after
        // upload, third-party sizes) must not inherit the FULL-size
        // dimensions — wrong width/height re-introduces the very layout
        // shift this module exists to prevent. Leaving the tag alone is
        // safer than injecting wrong values.
        if (preg_match('/-\d+x\d+(?=\.\w+$)/', wp_basename($url))) {
            return null;
        }

        // Fall back to full size
        if (!empty($meta['width']) && !empty($meta['height'])) {
            return [
                'width'  => (int) $meta['width'],
                'height' => (int) $meta['height'],
            ];
        }

        return null;
    }

    /**
     * Convert URL to local path and use getimagesize().
     */
    private function get_dims_from_local_file(string $url): ?array {
        $upload_dir = wp_get_upload_dir();

        if (strpos($url, $upload_dir['baseurl']) === false) {
            return null;
        }

        $relative = str_replace($upload_dir['baseurl'], '', $url);
        $file     = $upload_dir['basedir'] . $relative;

        if (!file_exists($file)) {
            return null;
        }

        $info = @getimagesize($file);
        if ($info && $info[0] > 0 && $info[1] > 0) {
            return ['width' => $info[0], 'height' => $info[1]];
        }

        return null;
    }

    /**
     * Fetch dimensions from a remote URL.
     * Downloads just enough bytes to read image headers.
     *
     * Server-side fetching of arbitrary <img src> values is an SSRF vector:
     * content authors can plant internal, localhost, or cloud-metadata URLs
     * that this server would otherwise probe on every pageview. Remote
     * fetching is therefore opt-in — it only runs for hosts allowlisted via
     * the 'metasync_dimension_remote_hosts' filter — and failures are
     * negatively cached so they are not re-fetched on every request.
     */
    private function get_dims_from_remote(string $url): ?array {
        if (!$this->is_remote_fetch_allowed($url)) {
            return null;
        }

        $fail_key = self::FAIL_PREFIX . md5($url);
        if (get_transient($fail_key) !== false) {
            return null;
        }

        $response = wp_remote_get($url, [
            'timeout'             => 3,
            'headers'             => ['Range' => 'bytes=0-' . (self::REMOTE_MAX_BYTES - 1)],
            'limit_response_size' => self::REMOTE_MAX_BYTES,
        ]);

        $body = null;
        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
        }

        if (empty($body)) {
            set_transient($fail_key, 1, self::FAIL_TTL);
            return null;
        }

        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tmp = wp_tempnam($url);
        file_put_contents($tmp, $body);
        $info = @getimagesize($tmp);
        @unlink($tmp);

        if ($info && $info[0] > 0 && $info[1] > 0) {
            return ['width' => $info[0], 'height' => $info[1]];
        }

        set_transient($fail_key, 1, self::FAIL_TTL);
        return null;
    }

    /**
     * A remote URL may only be fetched when explicitly allowed.
     *
     * @param string $url Absolute image URL.
     * @return bool True when the scheme is http(s), the URL passes core's
     *              internal-address validation, and its host is allowlisted.
     */
    private function is_remote_fetch_allowed(string $url): bool {
        /**
         * Hosts whose images may be fetched server-side for dimension
         * sniffing. Empty by default — remote dimension fetching is opt-in,
         * which closes the SSRF surface until a site owner explicitly
         * trusts specific hosts. Checked before any URL parsing so the
         * default (fetch-disabled) path stays cheap.
         *
         * @param string[] $allowed_hosts List of hostnames.
         */
        $allowed_hosts = apply_filters('metasync_dimension_remote_hosts', []);
        if (empty($allowed_hosts)) {
            return false;
        }

        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        if (!wp_http_validate_url($url)) {
            return false;
        }

        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        foreach ($allowed_hosts as $allowed) {
            if (strtolower((string) $allowed) === $host) {
                return true;
            }
        }

        return false;
    }

    /**
     * Insert an attribute into an <img> tag.
     */
    private function add_attribute(string $tag, string $name, string $value): string {
        return preg_replace(
            '/(<img\s)/i',
            sprintf('$1%s="%s" ', esc_attr($name), esc_attr($value)),
            $tag,
            1
        );
    }
}

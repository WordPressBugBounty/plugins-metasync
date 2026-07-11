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

    public function __construct() {
        add_filter('the_content', [$this, 'inject_dimensions'], 30);
        add_filter('post_thumbnail_html', [$this, 'inject_dimensions'], 30);
        add_filter('widget_text', [$this, 'inject_dimensions'], 30);
    }

    /**
     * Find <img> tags missing width or height and inject dimensions.
     */
    public function inject_dimensions(string $content): string {
        if (empty($content) || is_admin()) {
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
     */
    private function get_dims_from_remote(string $url): ?array {
        if (strpos($url, 'http') !== 0) {
            return null;
        }

        $response = wp_remote_get($url, [
            'timeout' => 3,
            'headers' => ['Range' => 'bytes=0-32767'],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
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

        return null;
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

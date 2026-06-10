<?php
/**
 * Metasync_Image_Converter
 * Converts uploaded JPEG/PNG images to WebP or AVIF on upload.
 * Supports "replace" and "alongside" strategies.
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Image_Converter {

    private array $settings;

    private const SUPPORTED_MIMES = [
        'image/jpeg',
        'image/png',
    ];

    private const EXT_AVIF = '.avif';
    private const EXT_WEBP = '.webp';
    private const ORIGINAL_EXT_PATTERN = '/\.(jpe?g|png)$/i';
    private const MAX_CONVERT_BYTES = 10 * 1024 * 1024;

    /**
     * Minimum available memory (bytes) required before attempting a sub-size conversion.
     * Remaining sub-sizes are skipped when available memory drops below this threshold.
     */
    private const MIN_MEMORY_FOR_SUBSIZE = 8 * 1024 * 1024;

    /**
     * Get file extension for a given format.
     */
    private static function get_format_extension(string $format): string {
        return $format === 'avif' ? self::EXT_AVIF : self::EXT_WEBP;
    }

    public function __construct(array $settings) {
        $this->settings = $settings;

        // Fires after WP generates all thumbnail sizes
        add_filter('wp_generate_attachment_metadata', [$this, 'convert_on_upload'], 10, 2);

        // If "alongside" strategy, rewrite <img> tags to <picture>
        if ($settings['conversion_strategy'] === 'alongside') {
            // Core WordPress
            add_filter('the_content', [$this, 'rewrite_to_picture_tags'], 20);
            add_filter('post_thumbnail_html', [$this, 'rewrite_to_picture_tags'], 20);
            add_filter('widget_text', [$this, 'rewrite_to_picture_tags'], 20);

            // WooCommerce frontend images
            add_filter('woocommerce_single_product_image_thumbnail_html', [$this, 'rewrite_to_picture_tags'], 20);
            add_filter('woocommerce_product_get_image', [$this, 'rewrite_to_picture_tags'], 20);
            add_filter('woocommerce_cart_item_thumbnail', [$this, 'rewrite_to_picture_tags'], 20);
            add_filter('woocommerce_placeholder_img', [$this, 'rewrite_to_picture_tags'], 20);

            // Output buffer catch-all for themes/builders bypassing WP filters (Divi, Elementor, etc.)
            add_action('template_redirect', [$this, 'start_output_buffer'], 1);
        }
    }

    /**
     * Hook into metadata generation to convert the main file and all sub-sizes.
     */
    public function convert_on_upload(array $metadata, int $attachment_id): array {
        $file = get_attached_file($attachment_id);
        $mime = get_post_mime_type($attachment_id);

        if (!$file || !in_array($mime, self::SUPPORTED_MIMES, true)) {
            return $metadata;
        }

        // Check exclusions
        if ($this->is_excluded($file)) {
            return $metadata;
        }

        $format   = $this->settings['conversion_format'];
        $quality  = (int) $this->settings['conversion_quality'];
        $strategy = $this->settings['conversion_strategy'];

        // Convert main/full file
        $converted = $this->convert_file($file, $format, $quality);

        if ($converted && $strategy === 'replace') {
            $this->replace_original($attachment_id, $file, $converted, $metadata, $format);
            update_post_meta($attachment_id, '_metasync_replaced_original', '1');
        }

        // Convert sub-sizes (thumbnails, medium, large, etc.) with memory management
        if (!empty($this->settings['convert_existing_sizes']) && !empty($metadata['sizes'])) {
            static::convert_subsizes($metadata['sizes'], dirname($file), $format, $quality, $strategy);
        }

        // Store converted format in meta for later use by picture tag rewriter
        if ($converted && $strategy === 'alongside') {
            update_post_meta($attachment_id, '_metasync_converted_format', $format);
        }

        return $metadata;
    }

    // ── Static Methods for External Use (Batch Optimizer, AJAX) ──

    /**
     * Convert an existing attachment to next-gen format.
     * Used by batch optimizer and single-image AJAX actions.
     */
    public static function convert_attachment(int $attachment_id, array $settings): bool {
        $file = get_attached_file($attachment_id);
        $mime = get_post_mime_type($attachment_id);

        if (!$file || !in_array($mime, self::SUPPORTED_MIMES, true)) {
            return false;
        }

        $format  = $settings['conversion_format'] ?? 'webp';
        $quality = (int) ($settings['conversion_quality'] ?? 82);
        $strategy = $settings['conversion_strategy'] ?? 'alongside';

        // Store original file size before conversion for savings display
        $original_size = filesize($file);

        $converted = self::do_convert_file($file, $format, $quality);
        if (!$converted) {
            return false;
        }

        // Store original size meta for savings calculation
        if ($original_size) {
            update_post_meta($attachment_id, '_metasync_original_filesize', $original_size);
        }

        if ($strategy === 'replace') {
            $metadata = wp_get_attachment_metadata($attachment_id);
            if ($metadata) {
                self::do_replace_original($attachment_id, $file, $converted, $metadata, $format);
                wp_update_attachment_metadata($attachment_id, $metadata);
            }
        }

        // Convert sub-sizes with memory management
        if (!empty($settings['convert_existing_sizes'])) {
            $metadata = wp_get_attachment_metadata($attachment_id);
            if ($metadata && !empty($metadata['sizes'])) {
                static::convert_subsizes($metadata['sizes'], dirname($file), $format, $quality, $strategy);
                if ($strategy === 'replace') {
                    wp_update_attachment_metadata($attachment_id, $metadata);
                }
            }
        }

        if ($strategy === 'replace') {
            update_post_meta($attachment_id, '_metasync_replaced_original', '1');
        }

        update_post_meta($attachment_id, '_metasync_converted_format', $format);
        return true;
    }

    /**
     * Check whether an optimized attachment can be reverted.
     * Returns false when the original was replaced (no backup exists).
     */
    public static function can_revert(int $attachment_id): bool {
        $format = get_post_meta($attachment_id, '_metasync_converted_format', true);
        if (!$format) {
            return false; // Not optimized
        }

        // If the original was replaced, revert is impossible
        if (get_post_meta($attachment_id, '_metasync_replaced_original', true)) {
            return false;
        }

        // Verify original file still exists on disk (alongside strategy)
        $file = get_attached_file($attachment_id);
        return $file && file_exists($file);
    }

    /**
     * Revert an attachment's conversion (alongside strategy only).
     * Deletes the converted files and removes the meta marker.
     */
    public static function revert_attachment(int $attachment_id): bool {
        $format = get_post_meta($attachment_id, '_metasync_converted_format', true);
        if (!$format) {
            return false;
        }

        $file = get_attached_file($attachment_id);
        if (!$file) {
            return false;
        }

        // Check if original still exists (alongside strategy)
        if (!file_exists($file)) {
            return false; // Cannot revert replace strategy
        }

        $ext = self::get_format_extension($format);

        // Delete converted full-size file
        $converted_path = preg_replace(self::ORIGINAL_EXT_PATTERN, $ext, $file);
        if ($converted_path && file_exists($converted_path)) {
            @unlink($converted_path);
        }

        // Delete converted sub-sizes
        $metadata = wp_get_attachment_metadata($attachment_id);
        if ($metadata && !empty($metadata['sizes'])) {
            $upload_dir = dirname($file);
            foreach ($metadata['sizes'] as $size_data) {
                $size_converted = preg_replace(self::ORIGINAL_EXT_PATTERN, $ext, $upload_dir . '/' . $size_data['file']);
                if ($size_converted && file_exists($size_converted)) {
                    @unlink($size_converted);
                }
            }
        }

        delete_post_meta($attachment_id, '_metasync_converted_format');
        delete_post_meta($attachment_id, '_metasync_original_filesize');
        delete_post_meta($attachment_id, '_metasync_replaced_original');
        return true;
    }

    // ── Core Conversion (static, reusable) ──

    /**
     * Get available PHP memory in bytes.
     * Returns PHP_INT_MAX when no limit is set (-1) or unreadable.
     * Returns 0 when memory_limit is "0" or empty.
     */
    protected static function get_available_memory(): int {
        $limit = ini_get('memory_limit');

        if ($limit === false || $limit === '-1') {
            return PHP_INT_MAX;
        }

        $limit = trim($limit);
        if ($limit === '' || $limit === '0') {
            return 0;
        }

        $value = (int) $limit;
        $unit  = strtolower(substr($limit, -1));

        $value = match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };

        return max(0, $value - memory_get_usage(true));
    }

    /**
     * Convert sub-sizes with memory management.
     *
     * Runs gc_collect_cycles() between each sub-size to release memory and
     * checks available memory before each iteration, skipping remaining
     * sub-sizes when memory drops below MIN_MEMORY_FOR_SUBSIZE.
     *
     * @param array  $sizes      Reference to $metadata['sizes'].
     * @param string $upload_dir Directory containing the sub-size files.
     * @param string $format     Target format (webp|avif).
     * @param int    $quality    Compression quality.
     * @param string $strategy   Conversion strategy (replace|alongside).
     */
    protected static function convert_subsizes(array &$sizes, string $upload_dir, string $format, int $quality, string $strategy): void {
        foreach ($sizes as $size_name => &$size_data) {
            // Check available memory before each sub-size conversion
            $available = static::get_available_memory();
            if ($available < self::MIN_MEMORY_FOR_SUBSIZE) {
                error_log('[MetaSync Media Opt] Low memory (' . size_format($available) . '), skipping remaining sub-sizes from: ' . $size_name);
                break;
            }

            $size_file = $upload_dir . '/' . $size_data['file'];
            $size_converted = static::do_convert_file($size_file, $format, $quality);

            if ($size_converted && $strategy === 'replace' && file_exists($size_converted) && filesize($size_converted) > 0) {
                @unlink($size_file);
                $size_data['file']     = basename($size_converted);
                $size_data['mime-type'] = "image/{$format}";
            } elseif ($size_converted && $strategy === 'replace') {
                error_log('[MetaSync Media Opt] Sub-size conversion produced invalid output, original preserved: ' . $size_file);
            }

            // Release cyclic references between sub-size conversions
            gc_collect_cycles();
        }
        unset($size_data);
    }

    /**
     * Convert a single image file. Returns path to converted file or null on failure.
     */
    protected static function do_convert_file(string $source, string $format, int $quality): ?string {
        if (!file_exists($source)) {
            return null;
        }

        if (filesize($source) > self::MAX_CONVERT_BYTES) {
            error_log('[MetaSync Media Opt] Source file exceeds MAX_CONVERT_BYTES limit, skipping: ' . $source);
            return null;
        }

        // Request WordPress's image processing memory limit
        wp_raise_memory_limit('image');

        // Pre-flight memory check using pixel dimensions when available
        $info = getimagesize($source);
        if ($info && $info[0] > 0 && $info[1] > 0) {
            $bpp = ($info['mime'] === 'image/png') ? 4 : 3;
            $estimated = (int) ($info[0] * $info[1] * $bpp * 1.8);
        } else {
            $estimated = filesize($source) * 3;
        }
        $available = self::get_available_memory();
        if ($estimated > $available * 0.8) {
            error_log('[MetaSync Media Opt] Skipping ' . basename($source) . ': estimated memory (' . size_format($estimated) . ') exceeds 80% of available (' . size_format($available) . ')');
            return null;
        }

        $ext = self::get_format_extension($format);
        $dest = preg_replace(self::ORIGINAL_EXT_PATTERN, $ext, $source);

        // Try Imagick first, fall back to GD if it fails (e.g. missing encode delegate)
        if (extension_loaded('imagick')) {
            try {
                $result = self::do_convert_with_imagick($source, $dest, $format, $quality);
                if ($result) {
                    return $result;
                }
            } catch (\Exception $e) {
                error_log('[MetaSync Media Opt] Imagick conversion failed, trying GD: ' . $e->getMessage());
            }
        }

        if (extension_loaded('gd')) {
            try {
                return self::do_convert_with_gd($source, $dest, $format, $quality);
            } catch (\Exception $e) {
                error_log('[MetaSync Media Opt] GD conversion failed: ' . $e->getMessage());
            }
        }

        return null;
    }

    private static function do_convert_with_imagick(string $src, string $dest, string $fmt, int $q): ?string {
        $img = new \Imagick($src);
        $img->setImageFormat($fmt === 'avif' ? 'avif' : 'webp');
        $img->setImageCompressionQuality($q);
        $img->stripImage();

        if ($img->writeImage($dest)) {
            if (!file_exists($dest) || !filesize($dest)) {
                @unlink($dest);
                error_log('[MetaSync Media Opt] Imagick wrote 0-byte or missing output, discarding: ' . $dest);
                $img->destroy();
                return null;
            }
            $img->destroy();
            return $dest;
        }

        $img->destroy();
        return null;
    }

    private static function do_convert_with_gd(string $src, string $dest, string $fmt, int $q): ?string {
        $info = getimagesize($src);
        if (!$info) {
            return null;
        }

        $gd_img = match ($info['mime']) {
            'image/jpeg' => imagecreatefromjpeg($src),
            'image/png'  => imagecreatefrompng($src),
            default      => null,
        };

        if (!$gd_img) {
            return null;
        }

        if ($info['mime'] === 'image/png') {
            imagepalettetotruecolor($gd_img);
            imagealphablending($gd_img, true);
            imagesavealpha($gd_img, true);
        }

        $success = match ($fmt) {
            'webp' => imagewebp($gd_img, $dest, $q),
            'avif' => function_exists('imageavif') ? imageavif($gd_img, $dest, $q) : false,
            default => false,
        };

        imagedestroy($gd_img);

        if (!$success || !file_exists($dest) || !filesize($dest)) {
            @unlink($dest);
            error_log('[MetaSync Media Opt] GD produced empty or missing output, discarding: ' . $dest);
            return null;
        }

        return $dest;
    }

    /**
     * Replace original file with converted version.
     */
    private static function do_replace_original(int $id, string $old_path, string $new_path, array &$meta, string $fmt): void {
        if (!file_exists($new_path) || !filesize($new_path)) {
            error_log('[MetaSync Media Opt] Converted file is missing or empty, original preserved: ' . $old_path);
            return;
        }

        // Capture old URL before deleting so we can rewrite post content references
        $old_url = wp_get_attachment_url($id);

        @unlink($old_path);

        wp_update_post([
            'ID'             => $id,
            'post_mime_type' => "image/{$fmt}",
        ]);

        update_attached_file($id, $new_path);
        $meta['file'] = _wp_relative_upload_path($new_path);

        // Rewrite hardcoded image URLs in post content to point to the new file
        $new_url = wp_get_attachment_url($id);
        if ($old_url && $new_url && $old_url !== $new_url) {
            self::rewrite_content_urls($old_url, $new_url);
        }
    }

    /**
     * Rewrite image URLs in all post content that references the old file path.
     * Uses the path portion (e.g. /wp-content/uploads/…) so it works regardless
     * of hostname changes (e.g. Cloudflare tunnel rotations).
     */
    private static function rewrite_content_urls(string $old_url, string $new_url): void {
        global $wpdb;

        // Extract path portions to be hostname-agnostic
        $old_path = wp_parse_url($old_url, PHP_URL_PATH);
        $new_path = wp_parse_url($new_url, PHP_URL_PATH);

        if (!$old_path || !$new_path || $old_path === $new_path) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
            $old_path,
            $new_path,
            '%' . $wpdb->esc_like($old_path) . '%'
        ));
    }

    // ── Instance Wrappers (Upload Hook) ──

    /**
     * Instance wrapper around static conversion method.
     */
    private function convert_file(string $source, string $format, int $quality): ?string {
        return self::do_convert_file($source, $format, $quality);
    }

    /**
     * Instance wrapper around static replace method.
     */
    private function replace_original(int $id, string $old_path, string $new_path, array &$meta, string $fmt): void {
        self::do_replace_original($id, $old_path, $new_path, $meta, $fmt);
    }

    /**
     * Start output buffering on frontend to catch images from themes/builders
     * that bypass standard WordPress image filters (e.g. Divi, Elementor).
     */
    public function start_output_buffer(): void {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }

        if (is_feed() || is_robots() || is_trackback()) {
            return;
        }

        ob_start([$this, 'rewrite_full_html']);
    }

    /**
     * Output buffer callback: rewrite remaining <img> tags to <picture>.
     * Protects existing <picture>, <script>, and <noscript> blocks from rewriting.
     */
    public function rewrite_full_html(string $html): string {
        if (empty($html) || stripos($html, '</html>') === false) {
            return $html;
        }

        // Protect blocks that must not be rewritten
        $protected = [];
        $counter = 0;

        // Existing <picture> blocks (already wrapped by filter hooks)
        $html = preg_replace_callback('/<picture\b[^>]*>.*?<\/picture>/is', function ($m) use (&$protected, &$counter) {
            $key = '<!--METASYNC_PROTECTED_' . $counter++ . '-->';
            $protected[$key] = $m[0];
            return $key;
        }, $html);

        // <script> blocks (JSON-LD contains image URLs)
        $html = preg_replace_callback('/<script\b[^>]*>.*?<\/script>/is', function ($m) use (&$protected, &$counter) {
            $key = '<!--METASYNC_PROTECTED_' . $counter++ . '-->';
            $protected[$key] = $m[0];
            return $key;
        }, $html);

        // <noscript> blocks (lazy-loading fallbacks)
        $html = preg_replace_callback('/<noscript\b[^>]*>.*?<\/noscript>/is', function ($m) use (&$protected, &$counter) {
            $key = '<!--METASYNC_PROTECTED_' . $counter++ . '-->';
            $protected[$key] = $m[0];
            return $key;
        }, $html);

        // Rewrite remaining <img> tags
        $html = preg_replace_callback('/<img\s[^>]+>/i', function ($matches) {
            return $this->maybe_wrap_img_tag($matches[0]);
        }, $html);

        // Restore protected blocks
        if (!empty($protected)) {
            $html = strtr($html, $protected);
        }

        return $html;
    }

    /**
     * Rewrite <img> tags to <picture> with next-gen source.
     * Used by WordPress filter hooks for content fragments.
     */
    public function rewrite_to_picture_tags(string $content): string {
        if (empty($content)) {
            return $content;
        }

        // Skip if already wrapped in <picture> (avoid double-wrapping from multiple filters)
        if (strpos($content, '<picture>') !== false) {
            return $content;
        }

        return preg_replace_callback('/<img\s[^>]+>/i', function ($matches) {
            return $this->maybe_wrap_img_tag($matches[0]);
        }, $content);
    }

    /**
     * Wrap a single <img> tag in <picture> with next-gen <source>.
     * Returns the original tag unchanged if conversion is not applicable.
     */
    private function maybe_wrap_img_tag(string $img_tag): string {
        if ($this->is_tag_excluded($img_tag)) {
            return $img_tag;
        }

        if (!preg_match('/src=["\']([^"\']+)["\']/i', $img_tag, $src_match)) {
            return $img_tag;
        }

        $original_src = $src_match[1];
        $format = $this->settings['conversion_format'];
        $ext    = self::get_format_extension($format);

        $converted_url = preg_replace(self::ORIGINAL_EXT_PATTERN, $ext, $original_src);

        if ($converted_url === $original_src) {
            return $img_tag;
        }

        $converted_path = $this->url_to_path($converted_url);
        if (!$converted_path || !file_exists($converted_path)) {
            return $img_tag;
        }

        $mime = $format === 'avif' ? 'image/avif' : 'image/webp';

        $source_srcset = '';
        if (preg_match('/srcset=["\']([^"\']+)["\']/i', $img_tag, $srcset_match)) {
            $converted_srcset = preg_replace('/\.(jpe?g|png)/i', $ext, $srcset_match[1]);
            $source_srcset = sprintf(' srcset="%s"', esc_attr($converted_srcset));
        }

        $sizes_attr = '';
        if (preg_match('/sizes=["\']([^"\']+)["\']/i', $img_tag, $sizes_match)) {
            $sizes_attr = sprintf(' sizes="%s"', esc_attr($sizes_match[1]));
        }

        return sprintf(
            '<picture><source type="%s"%s%s>%s</picture>',
            esc_attr($mime),
            $source_srcset ?: sprintf(' srcset="%s"', esc_attr($converted_url)),
            $sizes_attr,
            $img_tag
        );
    }

    /**
     * Convert a URL to a local file path. Returns null if URL is external.
     * Falls back to path-portion matching when hostnames differ (e.g. Cloudflare tunnel rotation).
     */
    private function url_to_path(string $url): ?string {
        $upload_dir = wp_get_upload_dir();
        $base_url   = $upload_dir['baseurl'];
        $base_path  = $upload_dir['basedir'];

        // Direct match (same hostname)
        if (strpos($url, $base_url) === 0) {
            return str_replace($base_url, $base_path, $url);
        }

        // Path-based fallback: match uploads path regardless of hostname
        $base_url_path = wp_parse_url($base_url, PHP_URL_PATH);
        $url_path      = wp_parse_url($url, PHP_URL_PATH);

        if ($base_url_path && $url_path && strpos($url_path, $base_url_path) === 0) {
            $relative = substr($url_path, strlen($base_url_path));
            return $base_path . $relative;
        }

        return null;
    }

    /**
     * Check if a file path matches exclusion patterns.
     */
    private function is_excluded(string $file): bool {
        $exclude_urls = array_filter(array_map('trim', explode(',', $this->settings['exclude_urls'] ?? '')));
        if (empty($exclude_urls)) {
            return false;
        }

        foreach ($exclude_urls as $pattern) {
            if (stripos($file, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if an img tag has an excluded CSS class.
     */
    private function is_tag_excluded(string $tag): bool {
        $exclude_classes = array_filter(array_map('trim', explode(',', $this->settings['exclude_classes'] ?? '')));
        if (empty($exclude_classes)) {
            return false;
        }

        if (preg_match('/class=["\']([^"\']+)["\']/i', $tag, $class_match)) {
            $classes = explode(' ', $class_match[1]);
            foreach ($exclude_classes as $excluded) {
                if (in_array($excluded, $classes, true)) {
                    return true;
                }
            }
        }
        return false;
    }

}

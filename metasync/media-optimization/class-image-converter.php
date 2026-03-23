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
        }

        // Convert sub-sizes (thumbnails, medium, large, etc.)
        if (!empty($this->settings['convert_existing_sizes']) && !empty($metadata['sizes'])) {
            $upload_dir = dirname($file);
            foreach ($metadata['sizes'] as $size_name => &$size_data) {
                $size_file = $upload_dir . '/' . $size_data['file'];
                $size_converted = $this->convert_file($size_file, $format, $quality);

                if ($size_converted && $strategy === 'replace') {
                    @unlink($size_file);
                    $size_data['file']     = basename($size_converted);
                    $size_data['mime-type'] = "image/{$format}";
                }
            }
            unset($size_data);
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

        // Convert sub-sizes
        if (!empty($settings['convert_existing_sizes'])) {
            $metadata = wp_get_attachment_metadata($attachment_id);
            if ($metadata && !empty($metadata['sizes'])) {
                $upload_dir = dirname($file);
                foreach ($metadata['sizes'] as $size_name => &$size_data) {
                    $size_file = $upload_dir . '/' . $size_data['file'];
                    $size_converted = self::do_convert_file($size_file, $format, $quality);

                    if ($size_converted && $strategy === 'replace') {
                        @unlink($size_file);
                        $size_data['file']     = basename($size_converted);
                        $size_data['mime-type'] = "image/{$format}";
                    }
                }
                unset($size_data);
                if ($strategy === 'replace') {
                    wp_update_attachment_metadata($attachment_id, $metadata);
                }
            }
        }

        update_post_meta($attachment_id, '_metasync_converted_format', $format);
        return true;
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
        return true;
    }

    // ── Core Conversion (static, reusable) ──

    /**
     * Convert a single image file. Returns path to converted file or null on failure.
     */
    protected static function do_convert_file(string $source, string $format, int $quality): ?string {
        if (!file_exists($source)) {
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
        return $success ? $dest : null;
    }

    /**
     * Replace original file with converted version.
     */
    private static function do_replace_original(int $id, string $old_path, string $new_path, array &$meta, string $fmt): void {
        @unlink($old_path);

        wp_update_post([
            'ID'             => $id,
            'post_mime_type' => "image/{$fmt}",
        ]);

        update_attached_file($id, $new_path);
        $meta['file'] = _wp_relative_upload_path($new_path);
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
     * Rewrite <img> tags to <picture> with next-gen source.
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
            $img_tag = $matches[0];

            // Check if this image should be excluded
            if ($this->is_tag_excluded($img_tag)) {
                return $img_tag;
            }

            // Extract src
            if (!preg_match('/src=["\']([^"\']+)["\']/i', $img_tag, $src_match)) {
                return $img_tag;
            }

            $original_src = $src_match[1];
            $format = $this->settings['conversion_format'];
            $ext    = self::get_format_extension($format);

            // Build the converted file URL
            $converted_url = preg_replace(self::ORIGINAL_EXT_PATTERN, $ext, $original_src);

            // Only wrap if the converted URL is different
            if ($converted_url === $original_src) {
                return $img_tag;
            }

            // Verify the converted file actually exists on disk before rewriting
            $converted_path = $this->url_to_path($converted_url);
            if (!$converted_path || !file_exists($converted_path)) {
                return $img_tag;
            }

            $mime = $format === 'avif' ? 'image/avif' : 'image/webp';

            // Also handle srcset if present
            $source_srcset = '';
            if (preg_match('/srcset=["\']([^"\']+)["\']/i', $img_tag, $srcset_match)) {
                $converted_srcset = preg_replace('/\.(jpe?g|png)/i', $ext, $srcset_match[1]);
                $source_srcset = sprintf(' srcset="%s"', esc_attr($converted_srcset));
            }

            // Extract sizes attribute to pass to <source>
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
        }, $content);
    }

    /**
     * Convert a URL to a local file path. Returns null if URL is external.
     */
    private function url_to_path(string $url): ?string {
        $upload_dir = wp_get_upload_dir();
        $base_url   = $upload_dir['baseurl'];
        $base_path  = $upload_dir['basedir'];

        if (strpos($url, $base_url) === 0) {
            return str_replace($base_url, $base_path, $url);
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

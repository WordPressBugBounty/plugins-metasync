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
     * Fresh execution budget (seconds) granted before heavy conversion work,
     * and the cap on a single synchronous sub-size pass.
     */
    private const UPLOAD_TIME_LIMIT = 300;

    /**
     * Seconds reserved before PHP's hard limit, mirroring class-media-batch-optimizer.php.
     */
    private const TIME_SAFETY_MARGIN = 5;

    /**
     * When the PHP execution timer last (re)started, as a microtime(true)
     * timestamp. 0.0 means the timer has never been reset, so it has been
     * running since the request started ($_SERVER['REQUEST_TIME_FLOAT']).
     */
    private static float $timer_started_at = 0.0;

    /**
     * While a batch run is in flight, per-image cache purges are suppressed
     * (a thousand-image batch would otherwise trigger a thousand full-site
     * purges) and a single purge fires once the batch finishes. See
     * set_cache_purge_suppressed().
     */
    private static bool $cache_purge_suppressed = false;

    /**
     * Strip server-identifying absolute paths from log messages, keeping the
     * upload-relative portion that actually identifies the file.
     */
    private static function redact_path(string $path): string {
        $replacements = [];

        $upload_dir = wp_get_upload_dir();
        if (!empty($upload_dir['basedir'])) {
            $replacements[] = [$upload_dir['basedir'] . '/', 'uploads/'];
        }
        if (defined('ABSPATH')) {
            $replacements[] = [ABSPATH, ''];
        }

        foreach ($replacements as [$needle, $replacement]) {
            if (strpos($path, $needle) === 0) {
                return $replacement . substr($path, strlen($needle));
            }
        }

        return basename($path);
    }

    /**
     * Get file extension for a given format.
     */
    private static function get_format_extension(string $format): string {
        return $format === 'avif' ? self::EXT_AVIF : self::EXT_WEBP;
    }

    /**
     * Suppress (or re-enable) the per-image page-cache purge. The batch
     * optimizer suppresses around its ticks and purges once at completion.
     */
    public static function set_cache_purge_suppressed(bool $suppressed): void {
        self::$cache_purge_suppressed = $suppressed;
    }

    /**
     * Purge page caches after an image's on-disk filenames changed (replace
     * conversion or revert), so cached/edge pages cannot keep serving
     * references to deleted files.
     */
    public static function purge_page_caches(): void {
        if (self::$cache_purge_suppressed) {
            return;
        }

        // Indirect guard so static analysis cannot narrow the class name and
        // flag the check as redundant — the purger is plugin-loaded, but the
        // converter can be exercised standalone (tests, tooling).
        if (!self::class_is_available('Metasync_Cache_Purge')) {
            return;
        }

        Metasync_Cache_Purge::purge_all('media_optimization');
    }

    /**
     * @param string $class Class name to check.
     */
    private static function class_is_available(string $class): bool {
        return class_exists($class);
    }

    public function __construct(array $settings) {
        $this->settings = $settings;

        // Fires after WP generates all thumbnail sizes
        add_filter('wp_generate_attachment_metadata', [$this, 'convert_on_upload'], 10, 2);

        // If "alongside" strategy, rewrite <img> tags to <picture>
        if (($settings['conversion_strategy'] ?? '') === 'alongside') {
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
        $max_dim  = (int) ($this->settings['max_image_dimensions'] ?? 0);

        // Capture original size before any replacement deletes the source file.
        $original_size = file_exists($file) ? (int) filesize($file) : 0;

        // Give a heavy multi-size upload a fresh execution budget instead of
        // inheriting what WP's own thumbnail generation left of the request's
        // default cap.
        static::reset_time_limit();

        // Convert main/full file (downscaled to $max_dim if it exceeds the limit)
        $converted = $this->convert_file($file, $format, $quality, $max_dim);

        if ($converted && $strategy === 'replace') {
            $this->replace_original($attachment_id, $file, $converted, $metadata, $format);
            update_post_meta($attachment_id, '_metasync_replaced_original', '1');
        }

        // Convert sub-sizes (thumbnails, medium, large, etc.) with memory management
        if (!empty($this->settings['convert_existing_sizes']) && !empty($metadata['sizes'])) {
            static::convert_subsizes($metadata['sizes'], dirname($file), $format, $quality, $strategy);
        }

        // Record the conversion for BOTH strategies so the image reports as
        // optimized in the library and is not re-queued by the batch optimizer.
        // (The picture tag rewriter also relies on this meta for "alongside".)
        if ($converted) {
            update_post_meta($attachment_id, '_metasync_converted_format', $format);
            if ($original_size) {
                update_post_meta($attachment_id, '_metasync_original_filesize', $original_size);
            }
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

        // The exclusion list must gate batch/bulk/single-image conversions
        // too, not just uploads — otherwise a bulk run converts files the
        // site owner explicitly asked the module to leave alone. Both the
        // stored path and the attachment URL are matched because exclusion
        // entries may be written as either.
        $exclusion_check = new self($settings);
        if ($exclusion_check->is_excluded($file) || $exclusion_check->is_url_excluded((string) wp_get_attachment_url($attachment_id))) {
            return false;
        }

        // Request WordPress's image processing memory limit (typically 256MB) before heavy work
        wp_raise_memory_limit('image');

        // Give a heavy multi-size conversion a fresh execution budget.
        static::reset_time_limit();

        $format  = $settings['conversion_format'] ?? 'webp';
        $quality = (int) ($settings['conversion_quality'] ?? 82);
        $strategy = $settings['conversion_strategy'] ?? 'alongside';
        $max_dim  = (int) ($settings['max_image_dimensions'] ?? 0);

        // Store original file size before conversion for savings display
        $original_size = filesize($file);

        $converted = self::do_convert_file($file, $format, $quality, $max_dim);
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
            // The original files are gone from disk now — cached pages still
            // referencing them would serve broken images until their TTL.
            self::purge_page_caches();
        }

        update_post_meta($attachment_id, '_metasync_converted_format', $format);
        Metasync_Media_Batch_Optimizer::flush_stats_cache();
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

        // Replace strategy has no original to restore — the attached file IS the
        // converted file, so the extension swap below would leave the path
        // unchanged and unlink the attachment's only copy. Refuse instead.
        if (get_post_meta($attachment_id, '_metasync_replaced_original', true)) {
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
        // `$converted_path !== $file` guards against deleting the attachment's
        // own file when the extension pattern doesn't match (e.g. the attached file is
        // already .webp/.avif and preg_replace returns the path unchanged).
        $converted_path = preg_replace(self::ORIGINAL_EXT_PATTERN, $ext, $file);
        if ($converted_path && $converted_path !== $file && file_exists($converted_path)) {
            @unlink($converted_path);
        }

        // Delete converted sub-sizes
        $metadata = wp_get_attachment_metadata($attachment_id);
        if ($metadata && !empty($metadata['sizes'])) {
            $upload_dir = dirname($file);
            foreach ($metadata['sizes'] as $size_data) {
                $size_path      = $upload_dir . '/' . $size_data['file'];
                $size_converted = preg_replace(self::ORIGINAL_EXT_PATTERN, $ext, $size_path);
                if ($size_converted && $size_converted !== $size_path && file_exists($size_converted)) {
                    @unlink($size_converted);
                }
            }
        }

        delete_post_meta($attachment_id, '_metasync_converted_format');
        delete_post_meta($attachment_id, '_metasync_original_filesize');
        delete_post_meta($attachment_id, '_metasync_replaced_original');

        // Cached pages may still contain <picture> markup referencing the
        // converted files just deleted — flush them.
        self::purge_page_caches();
        Metasync_Media_Batch_Optimizer::flush_stats_cache();
        return true;
    }

    /**
     * Delete converted sibling files when an attachment is deleted.
     *
     * Hooked on `delete_attachment` (fires before WordPress removes the
     * attachment's own files). The "alongside" strategy writes .webp/.avif
     * files next to the originals that are NOT tracked in attachment metadata,
     * so WordPress core never deletes them and they leak on disk. The "replace"
     * strategy's converted files ARE the attachment files and are removed by
     * core, so nothing extra is needed there.
     *
     * @param int $attachment_id Attachment being deleted.
     */
    public static function cleanup_on_delete(int $attachment_id): void {
        // Replace-strategy converted files are the attachment files themselves
        // (deleted by core). Only "alongside" siblings need manual cleanup.
        if (get_post_meta($attachment_id, '_metasync_replaced_original', true)) {
            return;
        }

        $file = get_attached_file($attachment_id);
        if (!$file) {
            return;
        }

        // Prefer the recorded format; fall back to both next-gen extensions
        // when the format is unknown (e.g. created by an older plugin version).
        $format = get_post_meta($attachment_id, '_metasync_converted_format', true);
        $exts   = $format ? [self::get_format_extension($format)] : [self::EXT_WEBP, self::EXT_AVIF];

        // Collect the full-size file plus every registered sub-size.
        $paths    = [$file];
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (is_array($metadata) && !empty($metadata['sizes'])) {
            $dir = dirname($file);
            foreach ($metadata['sizes'] as $size_data) {
                if (!empty($size_data['file'])) {
                    $paths[] = $dir . '/' . $size_data['file'];
                }
            }
        }

        foreach ($paths as $path) {
            foreach ($exts as $ext) {
                $converted = preg_replace(self::ORIGINAL_EXT_PATTERN, $ext, $path);
                if ($converted && $converted !== $path && file_exists($converted)) {
                    @unlink($converted);
                }
            }
        }
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
     * Grant the current request a fresh execution budget before heavy
     * conversion work. Returns true when the timer was actually
     * reset; false on hosts where set_time_limit() is disabled, in which
     * case callers must rely on get_remaining_time() to bail out early.
     */
    protected static function reset_time_limit(): bool {
        if (function_exists('set_time_limit') && @set_time_limit(self::UPLOAD_TIME_LIMIT)) {
            self::$timer_started_at = microtime(true);
            return true;
        }
        return false;
    }

    /**
     * Seconds left before PHP's execution limit kills the request, minus
     * TIME_SAFETY_MARGIN.
     *
     * Defense-in-depth for hosts where set_time_limit() is disabled: the
     * elapsed time is measured from the request start
     * ($_SERVER['REQUEST_TIME_FLOAT']) — not from when conversion began — so
     * time WordPress already spent generating thumbnails counts against the
     * budget. Once reset_time_limit() succeeds, the baseline moves to the
     * moment of the reset, matching PHP's own restarted timer.
     *
     * On Linux max_execution_time counts CPU time while this measures wall
     * clock, so the estimate only errs on the early-bail (safe) side.
     *
     * @param int $max_execution_time PHP max_execution_time (0 = unlimited). Accepts parameter for testability.
     * @return float Remaining seconds; PHP_INT_MAX when no limit applies.
     */
    protected static function get_remaining_time(int $max_execution_time = -1): float {
        if ($max_execution_time < 0) {
            $max_execution_time = (int) ini_get('max_execution_time');
        }

        // No execution limit (CLI, unlimited hosts): a timeout fatal is
        // impossible, so never cut conversions short.
        if ($max_execution_time <= 0) {
            return (float) PHP_INT_MAX;
        }

        $started = self::$timer_started_at > 0.0
            ? self::$timer_started_at
            : (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

        return $max_execution_time - (microtime(true) - $started) - self::TIME_SAFETY_MARGIN;
    }

    /**
     * Convert sub-sizes with memory and execution-time management.
     *
     * Runs gc_collect_cycles() between each sub-size to release memory and
     * checks available memory before each iteration, skipping remaining
     * sub-sizes when memory drops below MIN_MEMORY_FOR_SUBSIZE. Each
     * iteration also refreshes the execution timer (or, where that is
     * disabled, bails out before PHP's limit is hit) so a heavy multi-size
     * image cannot trigger a max_execution_time fatal.
     *
     * @param array  $sizes      Reference to $metadata['sizes'].
     * @param string $upload_dir Directory containing the sub-size files.
     * @param string $format     Target format (webp|avif).
     * @param int    $quality    Compression quality.
     * @param string $strategy   Conversion strategy (replace|alongside).
     */
    protected static function convert_subsizes(array &$sizes, string $upload_dir, string $format, int $quality, string $strategy): void {
        $pass_started = microtime(true);

        foreach ($sizes as $size_name => &$size_data) {
            // Cap the total synchronous sub-size pass so the
            // per-iteration timer resets below cannot keep one request busy
            // indefinitely.
            if ((microtime(true) - $pass_started) >= self::UPLOAD_TIME_LIMIT) {
                error_log('[MetaSync Media Opt] Sub-size time budget exceeded, skipping remaining sub-sizes from: ' . $size_name);
                break;
            }

            // Refresh the execution timer between encodes; when the host
            // disables set_time_limit(), bail out before PHP's limit kills
            // the request.
            if (!static::reset_time_limit() && static::get_remaining_time() <= 0) {
                error_log('[MetaSync Media Opt] Execution time nearly exhausted, skipping remaining sub-sizes from: ' . $size_name);
                break;
            }

            // Check available memory before each sub-size conversion
            $available = static::get_available_memory();
            if ($available < self::MIN_MEMORY_FOR_SUBSIZE) {
                error_log('[MetaSync Media Opt] Low memory (' . size_format($available) . '), skipping remaining sub-sizes from: ' . $size_name);
                break;
            }

            $size_file = $upload_dir . '/' . $size_data['file'];

            // A sub-size that is already in the target format (e.g. a prior
            // replace run) maps onto itself: converting would rewrite the file
            // in place and the unlink below would then delete it. Skip.
            $size_ext    = self::get_format_extension($format);
            $size_dest   = preg_replace(self::ORIGINAL_EXT_PATTERN, $size_ext, $size_file);
            if ($size_dest === $size_file) {
                continue;
            }

            $size_converted = static::do_convert_file($size_file, $format, $quality);

            if ($size_converted && $size_converted !== $size_file && $strategy === 'replace' && file_exists($size_converted) && filesize($size_converted) > 0) {
                // Rewrite content references BEFORE unlinking — galleries,
                // widgets and hardcoded <img> tags point at sub-size URLs
                // (photo-1024x768.jpg), and the original is unrecoverable
                // once deleted.
                self::rewrite_content_paths($size_file, $size_converted);
                @unlink($size_file);
                $size_data['file']     = basename($size_converted);
                $size_data['mime-type'] = "image/{$format}";
            } elseif ($size_converted && $strategy === 'replace') {
                error_log('[MetaSync Media Opt] Sub-size conversion produced invalid output, original preserved: ' . self::redact_path($size_file));
            }

            // Release cyclic references between sub-size conversions
            gc_collect_cycles();
        }
        unset($size_data);
    }

    /**
     * Calculate downscaled dimensions that fit within a square bound while
     * preserving aspect ratio.
     *
     * Returns [width, height] when the image exceeds $max on either axis, or
     * null when no downscale is needed (already within bounds, downscaling
     * disabled, or invalid dimensions). The result is never upscaled.
     *
     * @param int $width  Original width in pixels.
     * @param int $height Original height in pixels.
     * @param int $max    Maximum allowed width/height; 0 disables downscaling.
     * @return array{0:int,1:int}|null
     */
    protected static function calc_scaled_dimensions(int $width, int $height, int $max): ?array {
        if ($max <= 0 || $width <= 0 || $height <= 0) {
            return null;
        }

        if ($width <= $max && $height <= $max) {
            return null;
        }

        $ratio = min($max / $width, $max / $height);
        $new_w = max(1, (int) round($width * $ratio));
        $new_h = max(1, (int) round($height * $ratio));

        return [$new_w, $new_h];
    }

    /**
     * Convert a single image file. Returns path to converted file or null on failure.
     *
     * When $max_dimensions is greater than zero and the source exceeds it on
     * either axis, the image is downscaled (aspect ratio preserved) before
     * encoding. This lowers the encoder's pixel buffer and shrinks output.
     */
    protected static function do_convert_file(string $source, string $format, int $quality, int $max_dimensions = 0): ?string {
        if (!file_exists($source)) {
            return null;
        }

        if (filesize($source) > self::MAX_CONVERT_BYTES) {
            error_log('[MetaSync Media Opt] Source file exceeds MAX_CONVERT_BYTES limit, skipping: ' . self::redact_path($source));
            return null;
        }

        // Bail out instead of starting an encode PHP may kill mid-write
        // On hosts where set_time_limit() is disabled the request
        // keeps its original cap, and the fatal reported by Sentry fired here.
        if (static::get_remaining_time() <= 0) {
            error_log('[MetaSync Media Opt] Execution time nearly exhausted, skipping conversion: ' . basename($source));
            return null;
        }

        // Request WordPress's image processing memory limit
        wp_raise_memory_limit('image');

        // Pre-flight memory check using pixel dimensions when available.
        // Note: the estimate intentionally uses the ORIGINAL dimensions because
        // both encoders must decode the full-resolution source into memory
        // before any downscale can be applied.
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

        // Determine target dimensions when the source exceeds the configured cap.
        $target_dimensions = ($info && $info[0] > 0 && $info[1] > 0)
            ? self::calc_scaled_dimensions((int) $info[0], (int) $info[1], $max_dimensions)
            : null;

        $ext = self::get_format_extension($format);
        $dest = preg_replace(self::ORIGINAL_EXT_PATTERN, $ext, $source);

        // Try Imagick first, fall back to GD if it fails (e.g. missing encode delegate)
        if (extension_loaded('imagick')) {
            try {
                $result = self::do_convert_with_imagick($source, $dest, $format, $quality, $target_dimensions);
                if ($result) {
                    return $result;
                }
            } catch (\Exception $e) {
                error_log('[MetaSync Media Opt] Imagick conversion failed, trying GD: ' . $e->getMessage());
            }
        }

        if (extension_loaded('gd')) {
            try {
                return self::do_convert_with_gd($source, $dest, $format, $quality, $target_dimensions);
            } catch (\Exception $e) {
                error_log('[MetaSync Media Opt] GD conversion failed: ' . $e->getMessage());
            }
        }

        return null;
    }

    private static function do_convert_with_imagick(string $src, string $dest, string $fmt, int $q, ?array $target_dimensions = null): ?string {
        $img = new \Imagick();
        $img->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 64 * 1024 * 1024);
        $img->setResourceLimit(\Imagick::RESOURCETYPE_MAP, 128 * 1024 * 1024);
        $img->readImage($src);

        // Downscale before encoding when the image exceeds the configured cap.
        if ($target_dimensions !== null) {
            [$new_w, $new_h] = $target_dimensions;
            $img->scaleImage($new_w, $new_h);
        }

        $img->setImageFormat($fmt === 'avif' ? 'avif' : 'webp');
        $img->setImageCompressionQuality($q);
        $img->stripImage();

        if ($img->writeImage($dest)) {
            if (!file_exists($dest) || !filesize($dest)) {
                @unlink($dest);
                error_log('[MetaSync Media Opt] Imagick wrote 0-byte or missing output, discarding: ' . self::redact_path($dest));
                $img->destroy();
                return null;
            }
            $img->destroy();
            return $dest;
        }

        $img->destroy();
        return null;
    }

    private static function do_convert_with_gd(string $src, string $dest, string $fmt, int $q, ?array $target_dimensions = null): ?string {
        $info = getimagesize($src);
        if (!$info) {
            return null;
        }

        $is_png = ($info['mime'] === 'image/png');

        $gd_img = match ($info['mime']) {
            'image/jpeg' => imagecreatefromjpeg($src),
            'image/png'  => imagecreatefrompng($src),
            default      => null,
        };

        if (!$gd_img) {
            return null;
        }

        if ($is_png) {
            imagepalettetotruecolor($gd_img);
            imagealphablending($gd_img, true);
            imagesavealpha($gd_img, true);
        }

        // Downscale before encoding when the image exceeds the configured cap.
        if ($target_dimensions !== null) {
            [$new_w, $new_h] = $target_dimensions;
            $resized = imagecreatetruecolor($new_w, $new_h);
            if ($resized !== false) {
                if ($is_png) {
                    // Preserve transparency on the resized canvas.
                    imagealphablending($resized, false);
                    imagesavealpha($resized, true);
                    $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                    imagefilledrectangle($resized, 0, 0, $new_w, $new_h, $transparent);
                }
                imagecopyresampled(
                    $resized, $gd_img,
                    0, 0, 0, 0,
                    $new_w, $new_h,
                    imagesx($gd_img), imagesy($gd_img)
                );
                imagedestroy($gd_img);
                $gd_img = $resized;
            }
        }

        $success = match ($fmt) {
            'webp' => imagewebp($gd_img, $dest, $q),
            'avif' => function_exists('imageavif') ? imageavif($gd_img, $dest, $q) : false,
            default => false,
        };

        imagedestroy($gd_img);

        if (!$success || !file_exists($dest) || !filesize($dest)) {
            @unlink($dest);
            error_log('[MetaSync Media Opt] GD produced empty or missing output, discarding: ' . self::redact_path($dest));
            return null;
        }

        return $dest;
    }

    /**
     * Replace original file with converted version.
     */
    private static function do_replace_original(int $id, string $old_path, string $new_path, array &$meta, string $fmt): void {
        if (!file_exists($new_path) || !filesize($new_path)) {
            error_log('[MetaSync Media Opt] Converted file is missing or empty, original preserved: ' . self::redact_path($old_path));
            return;
        }

        // Update the DB pointers and rewrite post content FIRST — only once
        // every content reference points at the new file is it safe to unlink
        // the original. Deleting first left a window where a failed rewrite
        // meant permanent 404s with no original left to fall back to.
        wp_update_post([
            'ID'             => $id,
            'post_mime_type' => "image/{$fmt}",
        ]);

        update_attached_file($id, $new_path);
        $meta['file'] = _wp_relative_upload_path($new_path);

        // Sync the full-size dimensions to the converted file. When a pre-conversion
        // downscale shrank the image, the original width/height in metadata are now
        // stale; otherwise this is a harmless no-op.
        $new_dims = @getimagesize($new_path);
        if ($new_dims && $new_dims[0] > 0 && $new_dims[1] > 0) {
            $meta['width']  = (int) $new_dims[0];
            $meta['height'] = (int) $new_dims[1];
        }

        // Rewrite hardcoded image URLs in post content to point to the new file.
        // Path-based, so it matches regardless of the hostname in the stored URL.
        self::rewrite_content_paths($old_path, $new_path);

        // Content now points at the converted file — the original is redundant.
        @unlink($old_path);
    }

    /**
     * Rewrite image references in all post content from one upload file to
     * another.
     *
     * Works on the URL path portion (e.g. /wp-content/uploads/2026/08/photo.jpg)
     * derived from the absolute file paths, so rewrites are hostname-agnostic
     * (surviving e.g. Cloudflare tunnel rotations) and can be driven by the
     * file paths the converter already has — no need for the attachment URL,
     * which is only trustworthy before the DB pointer flips.
     *
     * References with URL-encoded special characters (e.g. "my%20photo.jpg")
     * never match the raw path, so the encoded variant is rewritten too when
     * it differs.
     *
     * @param string $old_abspath Absolute path of the file being replaced.
     * @param string $new_abspath Absolute path of its replacement.
     */
    private static function rewrite_content_paths(string $old_abspath, string $new_abspath): void {
        $old_path = self::abspath_to_url_path($old_abspath);
        $new_path = self::abspath_to_url_path($new_abspath);

        if (!$old_path || !$new_path || $old_path === $new_path) {
            return;
        }

        self::rewrite_content_path_pair($old_path, $new_path);

        // Also rewrite URL-encoded references ("photo%20name-300x200.jpg")
        // when encoding changes the string.
        $old_encoded = implode('/', array_map('rawurlencode', explode('/', $old_path)));
        $new_encoded = implode('/', array_map('rawurlencode', explode('/', $new_path)));
        if ($old_encoded !== $old_path) {
            self::rewrite_content_path_pair($old_encoded, $new_encoded);
        }
    }

    /**
     * Map an absolute upload file path to its URL path portion
     * (e.g. /wp-content/uploads/2026/08/photo.jpg). Returns null when the
     * file lives outside the uploads directory.
     */
    private static function abspath_to_url_path(string $abspath): ?string {
        $upload_dir = wp_get_upload_dir();
        $base_path  = $base_url_path = null;

        if (strpos($abspath, $upload_dir['basedir']) === 0) {
            $base_path     = $upload_dir['basedir'];
            $base_url_path = wp_parse_url($upload_dir['baseurl'], PHP_URL_PATH);
        }

        if (!$base_path || !is_string($base_url_path) || $base_url_path === '') {
            return null;
        }

        $relative = substr($abspath, strlen($base_path));
        return rtrim($base_url_path, '/') . '/' . ltrim($relative, '/');
    }

    /**
     * Batched REPLACE of one URL path for another across wp_posts.
     *
     * Batching keeps a large wp_posts table from being locked by a single
     * unbounded REPLACE. Each batch only rewrites rows that still contain the
     * old path; once replaced they no longer match the LIKE, so the loop
     * converges. The batch ceiling is a safety net against an unexpected
     * non-converging loop (e.g. a DB-level error returning false).
     */
    private static function rewrite_content_path_pair(string $old_path, string $new_path): void {
        global $wpdb;

        $batch_size  = 500;
        $like        = '%' . $wpdb->esc_like($old_path) . '%';
        $max_batches = 100000;

        for ($batch = 0; $batch < $max_batches; $batch++) {
            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s)
                 WHERE post_content LIKE %s ORDER BY ID LIMIT %d",
                $old_path,
                $new_path,
                $like,
                $batch_size
            ));

            // false → query error; a short batch (< batch_size) means the last
            // matching rows were just rewritten. Either way there is no more work.
            if ($affected === false || $affected < $batch_size) {
                break;
            }
        }
    }

    // ── Instance Wrappers (Upload Hook) ──

    /**
     * Instance wrapper around static conversion method.
     */
    private function convert_file(string $source, string $format, int $quality, int $max_dimensions = 0): ?string {
        return self::do_convert_file($source, $format, $quality, $max_dimensions);
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
        if (empty($html) || stripos($html, '</html>') === false || $this->is_amp_request()) {
            return $html;
        }

        // Protect blocks that must not be rewritten (shared with the
        // content-filter path): existing <picture>, <script> (JSON-LD holds
        // image URLs), and <noscript> (lazy-loading fallbacks).
        [$html, $protected] = $this->protect_rewritable_blocks($html);

        // Rewrite remaining <img> tags
        $html = preg_replace_callback('/<img\s[^>]+>/i', function ($matches) {
            return $this->maybe_wrap_img_tag($matches[0]);
        }, $html);

        return $this->restore_protected_blocks($html, $protected);
    }

    /**
     * Rewrite <img> tags to <picture> with next-gen source.
     * Used by WordPress filter hooks for content fragments.
     */
    public function rewrite_to_picture_tags(string $content): string {
        if (empty($content) || is_feed() || $this->is_amp_request()) {
            return $content;
        }

        // Existing <picture> blocks (attributed or not) are protected per
        // block below; a single earlier wrap no longer disables rewriting
        // for the whole fragment, and <img>s inside script/noscript blocks
        // are never touched.
        [$content, $protected] = $this->protect_rewritable_blocks($content);

        $content = preg_replace_callback('/<img\s[^>]+>/i', function ($matches) {
            return $this->maybe_wrap_img_tag($matches[0]);
        }, $content);

        return $this->restore_protected_blocks($content, $protected);
    }

    /**
     * Extract blocks that must never be rewritten (existing <picture>,
     * <script>, <noscript>) into placeholders so the <img> rewrite cannot
     * reach inside them.
     *
     * @return array{0:string, 1:array<string, string>} Rewritten HTML and placeholder map.
     */
    private function protect_rewritable_blocks(string $html): array {
        $protected = [];
        $counter   = 0;
        $extract   = function ($m) use (&$protected, &$counter) {
            $key = '<!--METASYNC_PROTECTED_' . $counter++ . '-->';
            $protected[$key] = $m[0];
            return $key;
        };

        $html = preg_replace_callback('/<picture\b[^>]*>.*?<\/picture>/is', $extract, $html);
        $html = preg_replace_callback('/<script\b[^>]*>.*?<\/script>/is', $extract, $html);
        $html = preg_replace_callback('/<noscript\b[^>]*>.*?<\/noscript>/is', $extract, $html);

        return [$html, $protected];
    }

    /**
     * Restore blocks extracted by protect_rewritable_blocks().
     */
    private function restore_protected_blocks(string $html, array $protected): string {
        if (empty($protected)) {
            return $html;
        }
        return strtr($html, $protected);
    }

    /**
     * True when the current request renders an AMP page. The AMP runtime
     * validates markup, so the <picture> wrapper is skipped there. The
     * helper indirection keeps static analysis from assuming the AMP
     * plugin's functions always exist.
     */
    private function is_amp_request(): bool {
        if (self::func_is_available('amp_is_request')) {
            return (bool) amp_is_request();
        }
        if (self::func_is_available('is_amp_endpoint')) {
            return (bool) is_amp_endpoint();
        }
        return false;
    }

    private static function func_is_available(string $function): bool {
        return function_exists($function);
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

        // Exclusion entries may target the URL (full URL or path fragment).
        if ($this->is_url_excluded($original_src)) {
            return $img_tag;
        }

        // Serve whichever converted file actually exists: the current format
        // first, then the other one. This keeps legacy conversions alive when
        // the configured format flips (webp → avif or back) instead of
        // silently orphaning every previously converted image.
        $resolved = $this->resolve_converted_url($original_src);
        if (!$resolved) {
            return $img_tag;
        }

        [$converted_url, $format] = $resolved;
        $mime = $format === 'avif' ? 'image/avif' : 'image/webp';

        $source_srcset = '';
        if (preg_match('/srcset=["\']([^"\']+)["\']/i', $img_tag, $srcset_match)) {
            $source_srcset = sprintf(' srcset="%s"', esc_attr($this->resolve_srcset_candidates($srcset_match[1])));
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
     * Resolve the converted counterpart of an image URL.
     *
     * Tries the currently configured format first, then the other format, and
     * only returns a URL whose file provably exists on disk. Null means "no
     * converted file for this URL" — the caller leaves the tag untouched.
     *
     * @return array{0:string,1:string}|null [converted URL, format] or null.
     */
    private function resolve_converted_url(string $url): ?array {
        $current = $this->settings['conversion_format'] ?? 'webp';
        $formats = [$current];
        $other   = $current === 'webp' ? 'avif' : 'webp';
        $formats[] = $other;

        foreach ($formats as $format) {
            $candidate = preg_replace(self::ORIGINAL_EXT_PATTERN, self::get_format_extension($format), $url);

            if ($candidate === null || $candidate === $url) {
                continue;
            }

            $candidate_path = $this->url_to_path($candidate);
            if ($candidate_path && file_exists($candidate_path)) {
                return [$candidate, $format];
            }
        }

        return null;
    }

    /**
     * Build the <source> srcset with per-candidate file-existence checks.
     *
     * A srcset whose candidates were blindly extension-swapped serves 404s
     * whenever a sub-size was never converted (conversion of sub-sizes off, a
     * size added after conversion, a format switch) — and browsers do not fall
     * back to the <img> when a <source> candidate fails to fetch. Candidates
     * without a converted file on disk keep their ORIGINAL URL instead, which
     * still loads, just unoptimized. When no candidate has a converted file
     * the original srcset is returned unchanged.
     */
    private function resolve_srcset_candidates(string $srcset): string {
        $candidates = preg_split('/\s*,\s*/', trim($srcset));
        if (!$candidates) {
            return $srcset;
        }

        $any_converted = false;
        $out = [];

        foreach ($candidates as $candidate) {
            // Candidate is "URL [descriptor]" — rewrite only the URL part.
            if (!preg_match('/^(\S+)(.*)$/', $candidate, $parts)) {
                $out[] = $candidate;
                continue;
            }

            $resolved = $this->resolve_converted_url($parts[1]);
            if ($resolved) {
                $any_converted = true;
                $out[] = $resolved[0] . $parts[2];
            } else {
                // Keep the original URL so this descriptor still resolves.
                $out[] = $candidate;
            }
        }

        return $any_converted ? implode(', ', $out) : $srcset;
    }

    /**
     * Convert a URL to a local file path. Returns null if URL is external.
     * Falls back to path-portion matching when hostnames differ (e.g. Cloudflare tunnel rotation).
     */
    private function url_to_path(string $url): ?string {
        $upload_dir = wp_get_upload_dir();
        $base_url   = $upload_dir['baseurl'];
        $base_path  = $upload_dir['basedir'];

        // Filenames with URL-encoded characters (spaces, accents, UTF-8)
        // must be decoded before mapping to a disk path — the existence
        // checks that gate serving silently reject them otherwise and a
        // successfully converted file is never served.
        $url = rawurldecode($url);

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
     * Check if a URL matches the URL-based exclusion patterns.
     *
     * Entries may be full URLs (scheme + host) or bare path fragments; the
     * full-URL form never matches the path-only check in is_excluded(), so
     * both the raw URL and its path portion are tested here.
     */
    private function is_url_excluded(string $url): bool {
        $exclude_urls = array_filter(array_map('trim', explode(',', $this->settings['exclude_urls'] ?? '')));
        if (empty($exclude_urls)) {
            return false;
        }

        $candidates = [$url, (string) wp_parse_url($url, PHP_URL_PATH)];

        foreach ($exclude_urls as $pattern) {
            foreach ($candidates as $candidate) {
                if ($candidate !== '' && stripos($candidate, $pattern) !== false) {
                    return true;
                }
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

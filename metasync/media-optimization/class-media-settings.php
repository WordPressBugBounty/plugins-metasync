<?php
/**
 * Metasync_Media_Settings
 * Manages settings for the Media Optimization module.
 * Settings are stored in the plugin's unified option system.
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Media_Settings {

    const OPTION_KEY = 'metasync_media_optimization';

    private static $defaults = [
        // Image Conversion
        'enable_conversion'          => false,
        'conversion_format'          => 'webp',       // 'webp' or 'avif'
        'conversion_quality'         => 82,
        'conversion_strategy'        => 'alongside',  // 'replace' or 'alongside'
        'convert_existing_sizes'     => true,
        'max_image_dimensions'       => 2560,         // Max width/height (px) before conversion; 0 disables downscaling
        // Lazy Loading
        'enable_lazy_loading'        => false,
        'lazy_load_iframes'          => true,
        'lcp_skip_count'             => 2,
        // Dimension Injection
        'enable_dimension_injection' => false,
        // Exclusions
        'exclude_classes'            => '',            // Comma-separated CSS classes to exclude
        'exclude_urls'               => '',            // Comma-separated URL patterns to exclude
    ];

    /**
     * Get merged settings with defaults.
     *
     * AVIF is coerced back to WebP when the runtime cannot support it. A value of
     * 'avif' can already be persisted (saved on a capable server, then the site
     * moved or downgraded), so the coercion has to happen on read as well as on
     * save — otherwise stored settings would keep driving AVIF conversion on a
     * runtime that mis-measures the result. See supports_avif().
     */
    public static function get_settings(): array {
        $saved    = get_option(self::OPTION_KEY, []);
        $settings = wp_parse_args($saved, self::$defaults);

        if (($settings['conversion_format'] ?? '') === 'avif' && !self::supports_avif()) {
            $settings['conversion_format'] = 'webp';
        }

        return $settings;
    }

    /**
     * Get default settings.
     */
    public static function get_defaults(): array {
        return self::$defaults;
    }

    /**
     * Save settings with sanitization.
     */
    public static function save_settings(array $input): bool {
        $sanitized = self::sanitize($input);
        return update_option(self::OPTION_KEY, $sanitized);
    }

    /**
     * Sanitize and validate settings input.
     */
    public static function sanitize(array $input): array {
        return [
            'enable_conversion'          => !empty($input['enable_conversion']),
            'conversion_format'          => self::sanitize_conversion_format($input['conversion_format'] ?? ''),
            'conversion_quality'         => max(1, min(100, (int) ($input['conversion_quality'] ?? 82))),
            'conversion_strategy'        => in_array($input['conversion_strategy'] ?? '', ['replace', 'alongside'], true) ? $input['conversion_strategy'] : 'alongside',
            'convert_existing_sizes'     => !empty($input['convert_existing_sizes']),
            'max_image_dimensions'       => max(0, min(10000, (int) ($input['max_image_dimensions'] ?? 2560))),
            'enable_lazy_loading'        => !empty($input['enable_lazy_loading']),
            'lazy_load_iframes'          => !empty($input['lazy_load_iframes']),
            'lcp_skip_count'             => max(0, min(10, (int) ($input['lcp_skip_count'] ?? 2))),
            'enable_dimension_injection' => !empty($input['enable_dimension_injection']),
            'exclude_classes'            => sanitize_text_field($input['exclude_classes'] ?? ''),
            'exclude_urls'               => sanitize_text_field($input['exclude_urls'] ?? ''),
        ];
    }

    /**
     * Resolve the target conversion format, refusing AVIF the runtime can't measure.
     *
     * Anything unrecognised falls back to WebP, as does AVIF on a runtime or GD/
     * Imagick build that cannot support it. This keeps an unsupported value from
     * ever reaching the database.
     */
    private static function sanitize_conversion_format(string $format): string {
        if ($format === 'avif' && self::supports_avif()) {
            return 'avif';
        }

        return 'webp';
    }

    /**
     * Minimum PHP version at which AVIF output can be measured correctly.
     *
     * getimagesize() only learned to read real AVIF dimensions in PHP 8.2. On
     * 8.1 it returns 0x0 for a valid AVIF file (and a truthy [0,0] for a
     * truncated header, where 8.2 returns false). Every dimension read in this
     * module guards with `$info && $info[0] > 0 && $info[1] > 0`, so on 8.1 the
     * guard fails and:
     *   - the converter cannot sync post-downscale dimensions onto the
     *     attachment, leaving stale oversized width/height in metadata, and
     *   - the dimension injector then emits those stale values into the
     *     rendered HTML, which is worse than emitting none because the browser
     *     trusts them (a Core Web Vitals / CLS regression).
     *
     * imageavif() exists from 8.1 onward, so capability detection alone would
     * pass and let an 8.1 site select AVIF. WebP is unaffected and is the
     * default, so 8.1 sites are held to WebP until the runtime can measure AVIF.
     */
    const AVIF_MIN_PHP_VERSION_ID = 80200;

    /**
     * Check if the server supports AVIF conversion.
     */
    public static function supports_avif(): bool {
        // Encoding is not enough — the result also has to be measurable.
        if (PHP_VERSION_ID < self::AVIF_MIN_PHP_VERSION_ID) {
            return false;
        }
        if (extension_loaded('imagick')) {
            try {
                $formats = \Imagick::queryFormats('AVIF');
                return !empty($formats);
            } catch (\Exception $e) {
                return false;
            }
        }
        if (function_exists('gd_info')) {
            $info = gd_info();
            return !empty($info['AVIF Support']);
        }
        return false;
    }

    /**
     * Check if the server supports WebP conversion.
     */
    public static function supports_webp(): bool {
        if (extension_loaded('imagick')) {
            try {
                $formats = \Imagick::queryFormats('WEBP');
                return !empty($formats);
            } catch (\Exception $e) {
                return false;
            }
        }
        if (function_exists('gd_info')) {
            $info = gd_info();
            return !empty($info['WebP Support']);
        }
        return false;
    }

    /**
     * Get server capability info for display on admin page.
     */
    public static function get_server_capabilities(): array {
        return [
            'imagick'      => extension_loaded('imagick'),
            'gd'           => extension_loaded('gd'),
            'webp_support' => self::supports_webp(),
            'avif_support' => self::supports_avif(),
            'has_library'  => extension_loaded('imagick') || extension_loaded('gd'),
        ];
    }
}

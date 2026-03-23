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
     */
    public static function get_settings(): array {
        $saved = get_option(self::OPTION_KEY, []);
        return wp_parse_args($saved, self::$defaults);
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
            'conversion_format'          => in_array($input['conversion_format'] ?? '', ['webp', 'avif'], true) ? $input['conversion_format'] : 'webp',
            'conversion_quality'         => max(1, min(100, (int) ($input['conversion_quality'] ?? 82))),
            'conversion_strategy'        => in_array($input['conversion_strategy'] ?? '', ['replace', 'alongside'], true) ? $input['conversion_strategy'] : 'alongside',
            'convert_existing_sizes'     => !empty($input['convert_existing_sizes']),
            'enable_lazy_loading'        => !empty($input['enable_lazy_loading']),
            'lazy_load_iframes'          => !empty($input['lazy_load_iframes']),
            'lcp_skip_count'             => max(0, min(10, (int) ($input['lcp_skip_count'] ?? 2))),
            'enable_dimension_injection' => !empty($input['enable_dimension_injection']),
            'exclude_classes'            => sanitize_text_field($input['exclude_classes'] ?? ''),
            'exclude_urls'               => sanitize_text_field($input['exclude_urls'] ?? ''),
        ];
    }

    /**
     * Check if the server supports AVIF conversion.
     */
    public static function supports_avif(): bool {
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

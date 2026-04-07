<?php
/**
 * Metasync_Minification_Settings
 * Manages settings for the Code Minification & Delivery module.
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.5.23
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Minification_Settings {

    const OPTION_KEY = 'metasync_code_minification';

    private static $defaults = [
        // CSS Minification
        'enable_css_minify'         => false,
        'css_exclude_handles'       => '',

        // JS Minification
        'enable_js_minify'          => false,
        'js_exclude_handles'        => '',

        // JS Defer
        'enable_js_defer'           => false,
        'js_defer_exclude_handles'  => 'jquery,jquery-core,jquery-migrate',

        // JS Delay
        'enable_js_delay'           => false,
        'js_delay_handles'          => '',
        'js_delay_patterns'         => 'facebook.net,google-analytics.com,googletagmanager.com,hotjar.com,connect.facebook.net',

        // Cache
        'cache_ttl_days'            => 30,
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
            // CSS Minification
            'enable_css_minify'         => !empty($input['enable_css_minify']),
            'css_exclude_handles'       => sanitize_text_field($input['css_exclude_handles'] ?? ''),

            // JS Minification
            'enable_js_minify'          => !empty($input['enable_js_minify']),
            'js_exclude_handles'        => sanitize_text_field($input['js_exclude_handles'] ?? ''),

            // JS Defer
            'enable_js_defer'           => !empty($input['enable_js_defer']),
            'js_defer_exclude_handles'  => sanitize_text_field($input['js_defer_exclude_handles'] ?? 'jquery,jquery-core,jquery-migrate'),

            // JS Delay
            'enable_js_delay'           => !empty($input['enable_js_delay']),
            'js_delay_handles'          => sanitize_text_field($input['js_delay_handles'] ?? ''),
            'js_delay_patterns'         => sanitize_text_field($input['js_delay_patterns'] ?? ''),

            // Cache
            'cache_ttl_days'            => max(1, min(365, (int) ($input['cache_ttl_days'] ?? 30))),
        ];
    }
}

<?php
/**
 * Metasync_Compatibility_Guard
 * Detects conflicting minification/optimization features in other plugins
 * to prevent double-processing and conflicts.
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.5.23
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Compatibility_Guard {

    /**
     * Check if CSS minification should be skipped due to another plugin.
     *
     * @return string|false Name of conflicting plugin, or false if no conflict.
     */
    public static function should_skip_css_minify() {
        // WP Rocket
        if (function_exists('get_rocket_option') && get_rocket_option('minify_css')) {
            return 'WP Rocket';
        }

        // LiteSpeed Cache
        if (defined('LSCWP_V') && self::get_litespeed_option('css_minify')) {
            return 'LiteSpeed Cache';
        }

        // Autoptimize
        if (class_exists('autoptimizeCache') && get_option('autoptimize_css') === 'on') {
            return 'Autoptimize';
        }

        // W3 Total Cache
        if (function_exists('w3tc_minify_flush')) {
            return 'W3 Total Cache';
        }

        // SG Optimizer
        if (class_exists('SiteGround_Optimizer\\Options\\Options')) {
            if (\SiteGround_Optimizer\Options\Options::is_enabled('siteground_optimizer_combine_css')) {
                return 'SG Optimizer';
            }
        }

        return false;
    }

    /**
     * Check if JS minification should be skipped due to another plugin.
     *
     * @return string|false Name of conflicting plugin, or false if no conflict.
     */
    public static function should_skip_js_minify() {
        // WP Rocket
        if (function_exists('get_rocket_option') && get_rocket_option('minify_js')) {
            return 'WP Rocket';
        }

        // LiteSpeed Cache
        if (defined('LSCWP_V') && self::get_litespeed_option('js_minify')) {
            return 'LiteSpeed Cache';
        }

        // Autoptimize
        if (class_exists('autoptimizeCache') && get_option('autoptimize_js') === 'on') {
            return 'Autoptimize';
        }

        // W3 Total Cache
        if (function_exists('w3tc_minify_flush')) {
            return 'W3 Total Cache';
        }

        // SG Optimizer
        if (class_exists('SiteGround_Optimizer\\Options\\Options')) {
            if (\SiteGround_Optimizer\Options\Options::is_enabled('siteground_optimizer_combine_javascript')) {
                return 'SG Optimizer';
            }
        }

        return false;
    }

    /**
     * Check if JS defer should be skipped.
     *
     * @return string|false Name of conflicting plugin, or false if no conflict.
     */
    public static function should_skip_js_defer() {
        // WP Rocket
        if (function_exists('get_rocket_option') && get_rocket_option('defer_all_js')) {
            return 'WP Rocket';
        }

        // LiteSpeed Cache
        if (defined('LSCWP_V') && self::get_litespeed_option('optm-js_defer')) {
            return 'LiteSpeed Cache';
        }

        return false;
    }

    /**
     * Get all active conflicts for display in admin UI.
     *
     * @return array ['css_minify' => 'Plugin Name', 'js_minify' => 'Plugin Name', ...]
     */
    public static function get_active_conflicts(): array {
        $conflicts = [];

        $css_conflict = self::should_skip_css_minify();
        if ($css_conflict) {
            $conflicts['css_minify'] = $css_conflict;
        }

        $js_conflict = self::should_skip_js_minify();
        if ($js_conflict) {
            $conflicts['js_minify'] = $js_conflict;
        }

        $defer_conflict = self::should_skip_js_defer();
        if ($defer_conflict) {
            $conflicts['js_defer'] = $defer_conflict;
        }

        return $conflicts;
    }

    /**
     * Helper to get LiteSpeed Cache option.
     *
     * @param string $key Option key without prefix.
     * @return bool
     */
    private static function get_litespeed_option(string $key): bool {
        if (!defined('LSCWP_V')) {
            return false;
        }

        $conf = get_option('litespeed.conf.' . $key);
        return !empty($conf);
    }
}

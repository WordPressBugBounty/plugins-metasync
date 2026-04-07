<?php
/**
 * MetaSync - Code Minification & Delivery Module Loader
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.5.23
 */

if (!defined('ABSPATH')) {
    exit;
}

define('METASYNC_CODE_MIN_PATH', plugin_dir_path(__FILE__));
define('METASYNC_CODE_MIN_URL', plugin_dir_url(__FILE__));

// Load classes
require_once METASYNC_CODE_MIN_PATH . 'class-minification-settings.php';
require_once METASYNC_CODE_MIN_PATH . 'class-minification-cache.php';
require_once METASYNC_CODE_MIN_PATH . 'class-compatibility-guard.php';
require_once METASYNC_CODE_MIN_PATH . 'class-css-minifier.php';
require_once METASYNC_CODE_MIN_PATH . 'class-js-minifier.php';
require_once METASYNC_CODE_MIN_PATH . 'class-js-defer-delay.php';

/**
 * Initialize code minification features based on settings.
 * Only activates when the plugin is active and settings are enabled.
 */
function metasync_code_minification_init() {
    $settings = Metasync_Minification_Settings::get_settings();
    $conflicts = Metasync_Compatibility_Guard::get_active_conflicts();

    // CSS Minification
    if (!empty($settings['enable_css_minify']) && !isset($conflicts['css_minify'])) {
        new Metasync_CSS_Minifier($settings);
    }

    // JS Minification
    if (!empty($settings['enable_js_minify']) && !isset($conflicts['js_minify'])) {
        new Metasync_JS_Minifier($settings);
    }

    // JS Defer/Delay (defer check covers both defer and delay)
    $defer_enabled = !empty($settings['enable_js_defer']) || !empty($settings['enable_js_delay']);
    if ($defer_enabled && !isset($conflicts['js_defer'])) {
        new Metasync_JS_Defer_Delay($settings);
    }

    // Schedule cache cleanup cron
    Metasync_Minification_Cache::schedule_cleanup();
}
add_action('init', 'metasync_code_minification_init');

/**
 * Cron handler for cache cleanup.
 */
add_action(Metasync_Minification_Cache::CRON_HOOK, ['Metasync_Minification_Cache', 'cron_cleanup']);

/**
 * Hook into MetaSync cache purge system.
 */
add_action('metasync_cache_purge_all', function () {
    Metasync_Minification_Cache::purge_all();
});

/**
 * Purge minification cache on theme switch.
 */
add_action('switch_theme', function () {
    Metasync_Minification_Cache::purge_all();
});

/**
 * Purge minification cache on plugin activation/deactivation.
 */
add_action('activated_plugin', function () {
    Metasync_Minification_Cache::purge_all();
});
add_action('deactivated_plugin', function () {
    Metasync_Minification_Cache::purge_all();
});

/**
 * Enqueue code minification admin assets on the correct page only.
 *
 * @param string $hook_suffix The current admin page hook suffix.
 */
function metasync_code_minification_enqueue_assets($hook_suffix) {
    if (strpos($hook_suffix, 'code-minification') === false) {
        return;
    }

    $version = defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0';
    $assets_url = METASYNC_CODE_MIN_URL . 'assets/';

    wp_enqueue_style(
        'metasync-code-minification',
        $assets_url . 'css/code-minification.css',
        [],
        $version
    );

    wp_enqueue_script(
        'metasync-code-min-settings',
        $assets_url . 'js/code-minification-settings.js',
        [],
        $version,
        true
    );

    wp_localize_script('metasync-code-min-settings', 'metasyncCodeMin', [
        'ajaxUrl'      => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('metasync_code_minification_nonce'),
        'purgeConfirm' => __('Are you sure you want to purge the minification cache? This cannot be undone.', 'metasync'),
        'resetConfirm' => __('Are you sure you want to reset all code minification settings to their defaults?', 'metasync'),
    ]);
}
add_action('admin_enqueue_scripts', 'metasync_code_minification_enqueue_assets');

/**
 * AJAX handler: Purge minification cache.
 */
add_action('wp_ajax_metasync_purge_minification_cache', function () {
    check_ajax_referer('metasync_code_minification_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'metasync'));
    }

    Metasync_Minification_Cache::purge_all();
    $stats = Metasync_Minification_Cache::get_cache_stats();

    wp_send_json_success([
        'message' => __('Minification cache purged successfully.', 'metasync'),
        'stats'   => $stats,
    ]);
});

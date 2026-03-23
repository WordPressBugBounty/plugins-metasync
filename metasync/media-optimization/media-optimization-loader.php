<?php
/**
 * MetaSync - Media Optimization Module Loader
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('METASYNC_MEDIA_OPT_PATH', plugin_dir_path(__FILE__));
define('METASYNC_MEDIA_OPT_URL', plugin_dir_url(__FILE__));

// Load classes
require_once METASYNC_MEDIA_OPT_PATH . 'class-media-settings.php';
require_once METASYNC_MEDIA_OPT_PATH . 'class-image-converter.php';
require_once METASYNC_MEDIA_OPT_PATH . 'class-smart-lazy-loader.php';
require_once METASYNC_MEDIA_OPT_PATH . 'class-dimension-injector.php';
require_once METASYNC_MEDIA_OPT_PATH . 'class-media-batch-optimizer.php';

/**
 * Initialize media optimization features based on settings.
 * Only activates when the plugin is active and settings are enabled.
 */
function metasync_media_optimization_init() {
    $settings = Metasync_Media_Settings::get_settings();

    // Frontend features - only load when enabled
    if (!empty($settings['enable_conversion'])) {
        new Metasync_Image_Converter($settings);
    }

    if (!empty($settings['enable_lazy_loading'])) {
        new Metasync_Smart_Lazy_Loader($settings);
    }

    if (!empty($settings['enable_dimension_injection'])) {
        new Metasync_Dimension_Injector();
    }
}
add_action('init', 'metasync_media_optimization_init');

/**
 * Enqueue media optimization admin assets on the correct page only.
 *
 * @param string $hook_suffix The current admin page hook suffix.
 */
function metasync_media_optimization_enqueue_assets($hook_suffix) {
    // Only load on the media optimization admin page.
    // The hook suffix varies based on the parent menu slug (which may be white-labeled).
    if (strpos($hook_suffix, 'media-optimization') === false) {
        return;
    }

    $version = defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0';
    $assets_url = METASYNC_MEDIA_OPT_URL . 'assets/';

    // ── CSS ──
    wp_enqueue_style(
        'metasync-media-optimization',
        $assets_url . 'css/media-optimization.css',
        array(),
        $version
    );

    // ── Settings JS ──
    wp_enqueue_script(
        'metasync-media-opt-settings',
        $assets_url . 'js/media-optimization-settings.js',
        array(),
        $version,
        true
    );

    wp_localize_script('metasync-media-opt-settings', 'metasyncMediaOpt', array(
        'resetConfirm' => __('Are you sure you want to reset all media optimization settings to their defaults? This cannot be undone.', 'metasync'),
    ));

    // ── Image Library JS ──
    wp_enqueue_script(
        'metasync-media-opt-library',
        $assets_url . 'js/media-optimization-library.js',
        array(),
        $version,
        true
    );

    $batch_progress = Metasync_Media_Batch_Optimizer::get_progress();

    wp_localize_script('metasync-media-opt-library', 'metasyncMediaLib', array(
        'ajaxUrl'      => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('metasync_media_opt_nonce'),
        'batchRunning' => ($batch_progress['status'] === 'running'),
        'i18n'         => array(
            'optimizing'     => __('Optimizing...', 'metasync'),
            'optimize'       => __('Optimize', 'metasync'),
            'revert'         => __('Revert', 'metasync'),
            'revertConfirm'  => __('Revert this image to its original format?', 'metasync'),
            'optimizeFailed' => __('Optimization failed.', 'metasync'),
            'revertFailed'   => __('Revert failed.', 'metasync'),
            'startBatch'     => __('Start optimizing all unoptimized images?', 'metasync'),
            'batchFailed'    => __('Failed to start batch optimization.', 'metasync'),
            'batchComplete'  => __('Batch optimization complete!', 'metasync'),
            'imagesProcessed' => __('images processed', 'metasync'),
            'failed'         => __('failed', 'metasync'),
            'optimizingOf'   => __('Optimizing', 'metasync'),
            'of'             => __('of', 'metasync'),
            'images'         => __('images...', 'metasync'),
            'unoptimized'    => __('Unoptimized', 'metasync'),
            'selectImages'   => __('Please select at least one image.', 'metasync'),
            'bulkFailed'     => __('Bulk optimization failed.', 'metasync'),
            'apply'          => __('Apply', 'metasync'),
        ),
    ));
}
add_action('admin_enqueue_scripts', 'metasync_media_optimization_enqueue_assets');

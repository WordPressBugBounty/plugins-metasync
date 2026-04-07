<?php
/**
 * Code Minification Cache Tab View
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.5.23
 */

if (!defined('WPINC')) {
    die;
}

$cache_stats = Metasync_Minification_Cache::get_cache_stats();
?>
<div class="metasync-code-min-container">
    <div class="metasync-code-min-main">

        <!-- Cache Statistics -->
        <div class="metasync-card">
            <div class="metasync-card-header">
                <div class="metasync-card-title-group">
                    <h2><?php esc_html_e('Cache Statistics', 'metasync'); ?></h2>
                    <span class="metasync-card-subtitle"><?php esc_html_e('Overview of minified assets stored in cache', 'metasync'); ?></span>
                </div>
            </div>
            <div class="metasync-card-body">
                <div class="metasync-cache-stats-grid">
                    <div class="metasync-cache-stat">
                        <span class="metasync-cache-stat-value" id="cache-total-files"><?php echo esc_html($cache_stats['total_files']); ?></span>
                        <span class="metasync-cache-stat-label"><?php esc_html_e('Total Files', 'metasync'); ?></span>
                    </div>
                    <div class="metasync-cache-stat">
                        <span class="metasync-cache-stat-value" id="cache-total-size"><?php echo esc_html(size_format($cache_stats['total_size'])); ?></span>
                        <span class="metasync-cache-stat-label"><?php esc_html_e('Total Size', 'metasync'); ?></span>
                    </div>
                    <div class="metasync-cache-stat">
                        <span class="metasync-cache-stat-value" id="cache-css-files"><?php echo esc_html($cache_stats['css_files']); ?></span>
                        <span class="metasync-cache-stat-label"><?php esc_html_e('CSS Files', 'metasync'); ?></span>
                    </div>
                    <div class="metasync-cache-stat">
                        <span class="metasync-cache-stat-value" id="cache-js-files"><?php echo esc_html($cache_stats['js_files']); ?></span>
                        <span class="metasync-cache-stat-label"><?php esc_html_e('JS Files', 'metasync'); ?></span>
                    </div>
                </div>

                <div class="metasync-cache-actions">
                    <button type="button" class="button button-secondary" id="metasync-purge-minification-cache">
                        <span class="dashicons dashicons-trash" style="margin-top: 4px;"></span>
                        <?php esc_html_e('Purge Minification Cache', 'metasync'); ?>
                    </button>
                    <span class="metasync-cache-purge-status" id="cache-purge-status"></span>
                </div>

                <p class="metasync-field-description" style="margin-top: 12px;">
                    <?php printf(
                        esc_html__('Cache directory: %s', 'metasync'),
                        '<code>' . esc_html(Metasync_Minification_Cache::get_cache_dir()) . '</code>'
                    ); ?>
                </p>
            </div>
        </div>

    </div>
</div>

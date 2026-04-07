<?php
/**
 * Code Minification Settings Tab View
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.5.23
 */

if (!defined('WPINC')) {
    die;
}
?>
<div class="metasync-code-min-container">
    <!-- Main Settings Area -->
    <div class="metasync-code-min-main">
        <form method="post" action="" id="metasync-code-minification-form">
            <?php wp_nonce_field('metasync_save_code_minification', 'metasync_code_minification_nonce'); ?>

            <!-- ═══ Section: Asset Minification ═══ -->
            <div class="metasync-section-header">
                <span class="dashicons dashicons-editor-code"></span>
                <h3><?php esc_html_e('Asset Minification', 'metasync'); ?></h3>
            </div>

            <!-- CSS Minification -->
            <div class="metasync-card">
                <div class="metasync-card-header">
                    <div class="metasync-card-title-group">
                        <h2><?php esc_html_e('CSS Minification', 'metasync'); ?></h2>
                        <span class="metasync-card-subtitle"><?php esc_html_e('Strip whitespace and comments from enqueued stylesheets', 'metasync'); ?></span>
                    </div>
                    <label class="metasync-toggle <?php echo isset($conflicts['css_minify']) ? 'metasync-toggle-overridden' : ''; ?>">
                        <input type="checkbox" name="metasync_code_min[enable_css_minify]" value="1"
                            <?php checked(!empty($settings['enable_css_minify'])); ?>
                            <?php disabled(isset($conflicts['css_minify'])); ?>>
                        <span class="metasync-toggle-slider"></span>
                    </label>
                </div>

                <div class="metasync-card-body metasync-toggle-section" data-toggle="enable_css_minify"
                    <?php echo empty($settings['enable_css_minify']) ? 'style="display:none;"' : ''; ?>>
                    <div class="metasync-field">
                        <label for="css_exclude_handles"><?php esc_html_e('Excluded Handles', 'metasync'); ?></label>
                        <input type="text" name="metasync_code_min[css_exclude_handles]" id="css_exclude_handles"
                               value="<?php echo esc_attr($settings['css_exclude_handles']); ?>"
                               placeholder="handle1, handle2, handle3">
                        <p class="metasync-field-description"><?php esc_html_e('Comma-separated list of wp_enqueue_style handles to exclude from minification.', 'metasync'); ?></p>
                    </div>
                </div>
            </div>

            <!-- JS Minification -->
            <div class="metasync-card">
                <div class="metasync-card-header">
                    <div class="metasync-card-title-group">
                        <h2><?php esc_html_e('JS Minification', 'metasync'); ?></h2>
                        <span class="metasync-card-subtitle"><?php esc_html_e('Strip whitespace and comments from enqueued scripts', 'metasync'); ?></span>
                    </div>
                    <label class="metasync-toggle <?php echo isset($conflicts['js_minify']) ? 'metasync-toggle-overridden' : ''; ?>">
                        <input type="checkbox" name="metasync_code_min[enable_js_minify]" value="1"
                            <?php checked(!empty($settings['enable_js_minify'])); ?>
                            <?php disabled(isset($conflicts['js_minify'])); ?>>
                        <span class="metasync-toggle-slider"></span>
                    </label>
                </div>

                <div class="metasync-card-body metasync-toggle-section" data-toggle="enable_js_minify"
                    <?php echo empty($settings['enable_js_minify']) ? 'style="display:none;"' : ''; ?>>
                    <div class="metasync-field">
                        <label for="js_exclude_handles"><?php esc_html_e('Excluded Handles', 'metasync'); ?></label>
                        <input type="text" name="metasync_code_min[js_exclude_handles]" id="js_exclude_handles"
                               value="<?php echo esc_attr($settings['js_exclude_handles']); ?>"
                               placeholder="handle1, handle2, handle3">
                        <p class="metasync-field-description"><?php esc_html_e('Comma-separated list of wp_enqueue_script handles to exclude from minification.', 'metasync'); ?></p>
                    </div>
                </div>
            </div>

            <!-- ═══ Section: Script Loading ═══ -->
            <div class="metasync-section-header">
                <span class="dashicons dashicons-performance"></span>
                <h3><?php esc_html_e('Script Loading', 'metasync'); ?></h3>
            </div>

            <!-- JS Defer -->
            <div class="metasync-card">
                <div class="metasync-card-header">
                    <div class="metasync-card-title-group">
                        <h2><?php esc_html_e('JS Defer', 'metasync'); ?></h2>
                        <span class="metasync-card-subtitle"><?php esc_html_e('Add defer attribute to non-essential scripts to prevent render blocking', 'metasync'); ?></span>
                    </div>
                    <label class="metasync-toggle <?php echo isset($conflicts['js_defer']) ? 'metasync-toggle-overridden' : ''; ?>">
                        <input type="checkbox" name="metasync_code_min[enable_js_defer]" value="1"
                            <?php checked(!empty($settings['enable_js_defer'])); ?>
                            <?php disabled(isset($conflicts['js_defer'])); ?>>
                        <span class="metasync-toggle-slider"></span>
                    </label>
                </div>

                <div class="metasync-card-body metasync-toggle-section" data-toggle="enable_js_defer"
                    <?php echo empty($settings['enable_js_defer']) ? 'style="display:none;"' : ''; ?>>
                    <div class="metasync-field">
                        <label for="js_defer_exclude_handles"><?php esc_html_e('Excluded Handles', 'metasync'); ?></label>
                        <input type="text" name="metasync_code_min[js_defer_exclude_handles]" id="js_defer_exclude_handles"
                               value="<?php echo esc_attr($settings['js_defer_exclude_handles']); ?>"
                               placeholder="jquery, jquery-core, jquery-migrate">
                        <p class="metasync-field-description"><?php esc_html_e('Comma-separated list of script handles that should never be deferred. jQuery and core WP scripts are always excluded.', 'metasync'); ?></p>
                    </div>
                </div>
            </div>

            <!-- JS Delay -->
            <div class="metasync-card">
                <div class="metasync-card-header">
                    <div class="metasync-card-title-group">
                        <h2><?php esc_html_e('JS Delay', 'metasync'); ?></h2>
                        <span class="metasync-card-subtitle"><?php esc_html_e('Delay heavy third-party scripts until user interaction (improves INP)', 'metasync'); ?></span>
                    </div>
                    <label class="metasync-toggle <?php echo isset($conflicts['js_defer']) ? 'metasync-toggle-overridden' : ''; ?>">
                        <input type="checkbox" name="metasync_code_min[enable_js_delay]" value="1"
                            <?php checked(!empty($settings['enable_js_delay'])); ?>
                            <?php disabled(isset($conflicts['js_defer'])); ?>>
                        <span class="metasync-toggle-slider"></span>
                    </label>
                </div>

                <div class="metasync-card-body metasync-toggle-section" data-toggle="enable_js_delay"
                    <?php echo empty($settings['enable_js_delay']) ? 'style="display:none;"' : ''; ?>>
                    <div class="metasync-field">
                        <label for="js_delay_handles"><?php esc_html_e('Delay Handles', 'metasync'); ?></label>
                        <input type="text" name="metasync_code_min[js_delay_handles]" id="js_delay_handles"
                               value="<?php echo esc_attr($settings['js_delay_handles']); ?>"
                               placeholder="analytics-script, facebook-pixel">
                        <p class="metasync-field-description"><?php esc_html_e('Comma-separated list of script handles to delay until user interaction.', 'metasync'); ?></p>
                    </div>
                    <div class="metasync-field">
                        <label for="js_delay_patterns"><?php esc_html_e('Delay URL Patterns', 'metasync'); ?></label>
                        <textarea name="metasync_code_min[js_delay_patterns]" id="js_delay_patterns"
                                  rows="3"
                                  placeholder="facebook.net, google-analytics.com, googletagmanager.com"><?php echo esc_textarea($settings['js_delay_patterns']); ?></textarea>
                        <p class="metasync-field-description"><?php esc_html_e('Comma-separated URL substrings. Scripts whose src matches any of these patterns will be delayed. Delayed scripts auto-load after 5 seconds if no interaction occurs.', 'metasync'); ?></p>
                    </div>
                </div>
            </div>

            <!-- ═══ Section: Cache ═══ -->
            <div class="metasync-section-header">
                <span class="dashicons dashicons-database"></span>
                <h3><?php esc_html_e('Cache', 'metasync'); ?></h3>
            </div>

            <!-- Cache Settings -->
            <div class="metasync-card">
                <div class="metasync-card-header">
                    <div class="metasync-card-title-group">
                        <h2><?php esc_html_e('Cache Settings', 'metasync'); ?></h2>
                        <span class="metasync-card-subtitle"><?php esc_html_e('Configure cache lifetime for minified assets', 'metasync'); ?></span>
                    </div>
                </div>
                <div class="metasync-card-body">
                    <div class="metasync-field">
                        <label for="cache_ttl_days"><?php esc_html_e('Cache Lifetime (Days)', 'metasync'); ?></label>
                        <input type="number" name="metasync_code_min[cache_ttl_days]" id="cache_ttl_days"
                               value="<?php echo esc_attr((int) $settings['cache_ttl_days']); ?>"
                               min="1" max="365" step="1" style="max-width: 120px;">
                        <p class="metasync-field-description"><?php esc_html_e('Number of days before cached minified files are cleaned up. Files are automatically re-created on next request. Default: 30 days.', 'metasync'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="metasync-form-actions">
                <button type="submit" class="button button-primary metasync-btn-save">
                    <span class="dashicons dashicons-saved" style="margin-top: 4px;"></span>
                    <?php esc_html_e('Save Settings', 'metasync'); ?>
                </button>
                <button type="submit" name="metasync_code_min_reset" value="1" class="button metasync-btn-reset"
                        onclick="return confirm(window.metasyncCodeMin?.resetConfirm || 'Reset settings?');">
                    <span class="dashicons dashicons-image-rotate" style="margin-top: 4px;"></span>
                    <?php esc_html_e('Reset to Defaults', 'metasync'); ?>
                </button>
            </div>

        </form>
    </div>

    <!-- Sidebar -->
    <div class="metasync-code-min-sidebar">
        <!-- Active Features -->
        <div class="metasync-card">
            <div class="metasync-card-header">
                <h3><?php esc_html_e('Active Features', 'metasync'); ?></h3>
            </div>
            <div class="metasync-feature-status-list">
                <div class="metasync-feature-status-item">
                    <span class="metasync-feature-dot <?php echo !empty($settings['enable_css_minify']) ? 'active' : 'inactive'; ?>"></span>
                    <span><?php esc_html_e('CSS Minification', 'metasync'); ?></span>
                </div>
                <div class="metasync-feature-status-item">
                    <span class="metasync-feature-dot <?php echo !empty($settings['enable_js_minify']) ? 'active' : 'inactive'; ?>"></span>
                    <span><?php esc_html_e('JS Minification', 'metasync'); ?></span>
                </div>
                <div class="metasync-feature-status-item">
                    <span class="metasync-feature-dot <?php echo !empty($settings['enable_js_defer']) ? 'active' : 'inactive'; ?>"></span>
                    <span><?php esc_html_e('JS Defer', 'metasync'); ?></span>
                </div>
                <div class="metasync-feature-status-item">
                    <span class="metasync-feature-dot <?php echo !empty($settings['enable_js_delay']) ? 'active' : 'inactive'; ?>"></span>
                    <span><?php esc_html_e('JS Delay', 'metasync'); ?></span>
                    <?php if (!empty($settings['enable_js_delay'])): ?>
                        <span class="metasync-feature-format"><?php esc_html_e('5s fallback', 'metasync'); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Performance Tips -->
        <div class="metasync-card">
            <div class="metasync-card-header">
                <h3><?php esc_html_e('Performance Tips', 'metasync'); ?></h3>
            </div>
            <ul class="metasync-tips-list">
                <li>
                    <span class="dashicons dashicons-performance" style="color: #10b981;"></span>
                    <?php esc_html_e('Enable CSS + JS minification together for maximum file size reduction.', 'metasync'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-clock" style="color: #3b82f6;"></span>
                    <?php esc_html_e('JS Defer improves First Contentful Paint by loading scripts after HTML parsing.', 'metasync'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-visibility" style="color: #f59e0b;"></span>
                    <?php esc_html_e('JS Delay is ideal for analytics, chat widgets, and social pixels that are not needed immediately.', 'metasync'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-info" style="color: #667eea;"></span>
                    <?php esc_html_e('jQuery and core WP scripts are always excluded from defer to prevent breakage.', 'metasync'); ?>
                </li>
            </ul>
        </div>
    </div>
</div>

<?php
/**
 * Media Optimization Settings Tab View
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.6.0
 */

if (!defined('WPINC')) {
    die;
}
?>
<div class="metasync-media-container">
    <!-- Main Settings Area -->
    <div class="metasync-media-main">
        <form method="post" action="" id="metasync-media-optimization-form">
            <?php wp_nonce_field('metasync_save_media_optimization', 'metasync_media_optimization_nonce'); ?>

            <!-- Section: Next-Gen Image Conversion -->
            <div class="metasync-card">
                <div class="metasync-card-header">
                    <div class="metasync-card-title-group">
                        <h2><?php esc_html_e('Next-Gen Image Conversion', 'metasync'); ?></h2>
                        <span class="metasync-card-subtitle"><?php esc_html_e('Automatically convert JPEG/PNG to WebP or AVIF', 'metasync'); ?></span>
                    </div>
                    <label class="metasync-toggle">
                        <input type="checkbox" name="metasync_media[enable_conversion]" value="1" <?php checked(!empty($settings['enable_conversion'])); ?>>
                        <span class="metasync-toggle-slider"></span>
                    </label>
                </div>

                <div class="metasync-card-body metasync-toggle-section" data-toggle="enable_conversion" <?php echo empty($settings['enable_conversion']) ? 'style="display:none;"' : ''; ?>>
                    <div class="metasync-field-row">
                        <div class="metasync-field">
                            <label for="conversion_format"><?php esc_html_e('Target Format', 'metasync'); ?></label>
                            <select name="metasync_media[conversion_format]" id="conversion_format">
                                <option value="webp" <?php selected($settings['conversion_format'], 'webp'); ?>
                                    <?php disabled(!$capabilities['webp_support']); ?>>
                                    WebP <?php echo !$capabilities['webp_support'] ? esc_html__('(not supported)', 'metasync') : ''; ?>
                                </option>
                                <option value="avif" <?php selected($settings['conversion_format'], 'avif'); ?>
                                    <?php disabled(!$capabilities['avif_support']); ?>>
                                    AVIF <?php echo !$capabilities['avif_support'] ? esc_html__('(not supported)', 'metasync') : ''; ?>
                                </option>
                            </select>
                            <p class="metasync-field-description"><?php esc_html_e('WebP has broader browser support. AVIF offers better compression but requires newer browsers.', 'metasync'); ?></p>
                        </div>

                        <div class="metasync-field">
                            <label for="conversion_quality"><?php esc_html_e('Quality', 'metasync'); ?></label>
                            <div class="metasync-range-group">
                                <input type="range" name="metasync_media[conversion_quality]" id="conversion_quality"
                                       value="<?php echo esc_attr((int) $settings['conversion_quality']); ?>"
                                       min="1" max="100" step="1">
                                <span class="metasync-range-value" id="quality-value"><?php echo esc_html((int) $settings['conversion_quality']); ?></span>
                            </div>
                            <p class="metasync-field-description"><?php esc_html_e('Lower values = smaller files. Recommended: 75-85 for best balance.', 'metasync'); ?></p>
                        </div>
                    </div>

                    <div class="metasync-field-row">
                        <div class="metasync-field">
                            <label for="conversion_strategy"><?php esc_html_e('Conversion Strategy', 'metasync'); ?></label>
                            <select name="metasync_media[conversion_strategy]" id="conversion_strategy">
                                <option value="alongside" <?php selected($settings['conversion_strategy'], 'alongside'); ?>>
                                    <?php esc_html_e('Store alongside original (serve via <picture>)', 'metasync'); ?>
                                </option>
                                <option value="replace" <?php selected($settings['conversion_strategy'], 'replace'); ?>>
                                    <?php esc_html_e('Replace original file (Not Recommended)', 'metasync'); ?>
                                </option>
                            </select>
                            <p class="metasync-field-description"><?php esc_html_e('"Alongside" keeps the original and creates a converted copy with automatic <picture> tag wrapping.', 'metasync'); ?></p>
                            <div class="metasync-strategy-warning" id="replace-strategy-warning" <?php echo $settings['conversion_strategy'] !== 'replace' ? 'style="display:none;"' : ''; ?>>
                                <span class="dashicons dashicons-warning"></span>
                                <p><?php esc_html_e('Warning: "Replace" permanently overwrites the original file. This action is not reversible — you will lose the original image. Use "Alongside" to keep a safe fallback.', 'metasync'); ?></p>
                            </div>
                        </div>

                        <div class="metasync-field">
                            <label class="metasync-checkbox-label">
                                <input type="checkbox" name="metasync_media[convert_existing_sizes]" value="1"
                                    <?php checked(!empty($settings['convert_existing_sizes'])); ?>>
                                <span><?php esc_html_e('Convert all thumbnail sizes', 'metasync'); ?></span>
                            </label>
                            <p class="metasync-field-description"><?php esc_html_e('Also convert WordPress-generated thumbnail sizes (medium, large, etc.) in addition to the full-size image.', 'metasync'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section: Smart Lazy Loading -->
            <div class="metasync-card">
                <div class="metasync-card-header">
                    <div class="metasync-card-title-group">
                        <h2><?php esc_html_e('Smart Lazy Loading', 'metasync'); ?></h2>
                        <span class="metasync-card-subtitle"><?php esc_html_e('Defer off-screen images and iframes for faster page loads', 'metasync'); ?></span>
                    </div>
                    <label class="metasync-toggle">
                        <input type="checkbox" name="metasync_media[enable_lazy_loading]" value="1" <?php checked(!empty($settings['enable_lazy_loading'])); ?>>
                        <span class="metasync-toggle-slider"></span>
                    </label>
                </div>

                <div class="metasync-card-body metasync-toggle-section" data-toggle="enable_lazy_loading" <?php echo empty($settings['enable_lazy_loading']) ? 'style="display:none;"' : ''; ?>>
                    <div class="metasync-field-row">
                        <div class="metasync-field">
                            <label for="lcp_skip_count"><?php esc_html_e('LCP Protection - Skip Count', 'metasync'); ?></label>
                            <div class="metasync-number-group">
                                <input type="number" name="metasync_media[lcp_skip_count]" id="lcp_skip_count"
                                       value="<?php echo esc_attr((int) $settings['lcp_skip_count']); ?>"
                                       min="0" max="10" step="1">
                                <span class="metasync-number-suffix"><?php esc_html_e('images', 'metasync'); ?></span>
                            </div>
                            <p class="metasync-field-description"><?php esc_html_e('Number of leading images to exclude from lazy loading. These will get loading="eager" to protect your Largest Contentful Paint (LCP) score. Recommended: 2-3 for hero images and logos.', 'metasync'); ?></p>
                        </div>

                        <div class="metasync-field">
                            <label class="metasync-checkbox-label">
                                <input type="checkbox" name="metasync_media[lazy_load_iframes]" value="1"
                                    <?php checked(!empty($settings['lazy_load_iframes'])); ?>>
                                <span><?php esc_html_e('Lazy load iframes', 'metasync'); ?></span>
                            </label>
                            <p class="metasync-field-description"><?php esc_html_e('Also apply lazy loading to iframe elements (YouTube embeds, Google Maps, etc.).', 'metasync'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section: Automatic Dimension Injection -->
            <div class="metasync-card">
                <div class="metasync-card-header">
                    <div class="metasync-card-title-group">
                        <h2><?php esc_html_e('Automatic Dimension Injection', 'metasync'); ?></h2>
                        <span class="metasync-card-subtitle"><?php esc_html_e('Prevent layout shifts by adding missing width/height attributes', 'metasync'); ?></span>
                    </div>
                    <label class="metasync-toggle">
                        <input type="checkbox" name="metasync_media[enable_dimension_injection]" value="1" <?php checked(!empty($settings['enable_dimension_injection'])); ?>>
                        <span class="metasync-toggle-slider"></span>
                    </label>
                </div>

                <div class="metasync-card-body metasync-toggle-section" data-toggle="enable_dimension_injection" <?php echo empty($settings['enable_dimension_injection']) ? 'style="display:none;"' : ''; ?>>
                    <div class="metasync-dimension-info">
                        <div class="metasync-info-box">
                            <span class="dashicons dashicons-info"></span>
                            <div>
                                <strong><?php esc_html_e('How it works', 'metasync'); ?></strong>
                                <p><?php esc_html_e('Scans your page HTML for <img> tags missing width and/or height attributes. Dimensions are resolved from WordPress attachment metadata, local file headers, or remote image headers. This prevents Cumulative Layout Shift (CLS) - a Core Web Vital metric.', 'metasync'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section: Exclusions -->
            <div class="metasync-card">
                <div class="metasync-card-header">
                    <div class="metasync-card-title-group">
                        <h2><?php esc_html_e('Exclusions', 'metasync'); ?></h2>
                        <span class="metasync-card-subtitle"><?php esc_html_e('Skip specific images from optimization', 'metasync'); ?></span>
                    </div>
                </div>

                <div class="metasync-card-body">
                    <div class="metasync-field-row">
                        <div class="metasync-field">
                            <label for="exclude_classes"><?php esc_html_e('Exclude by CSS Class', 'metasync'); ?></label>
                            <input type="text" name="metasync_media[exclude_classes]" id="exclude_classes"
                                   value="<?php echo esc_attr($settings['exclude_classes']); ?>"
                                   placeholder="no-lazy, skip-convert, custom-class"
                                   class="metasync-text-input">
                            <p class="metasync-field-description"><?php esc_html_e('Comma-separated CSS class names. Images with these classes will be excluded from conversion, lazy loading, and dimension injection.', 'metasync'); ?></p>
                        </div>

                        <div class="metasync-field">
                            <label for="exclude_urls"><?php esc_html_e('Exclude by URL Pattern', 'metasync'); ?></label>
                            <input type="text" name="metasync_media[exclude_urls]" id="exclude_urls"
                                   value="<?php echo esc_attr($settings['exclude_urls']); ?>"
                                   placeholder="/logo, /brand, external-cdn.com"
                                   class="metasync-text-input">
                            <p class="metasync-field-description"><?php esc_html_e('Comma-separated URL patterns. Images with URLs containing these patterns will be excluded from conversion.', 'metasync'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Save Button -->
            <div class="metasync-form-actions">
                <button type="submit" class="button button-primary button-large" id="save-media-settings">
                    <span class="dashicons dashicons-saved" style="margin-top: 4px;"></span>
                    <?php esc_html_e('Save Settings', 'metasync'); ?>
                </button>
                <button type="button" class="button button-secondary" id="reset-media-settings">
                    <span class="dashicons dashicons-image-rotate" style="margin-top: 4px;"></span>
                    <?php esc_html_e('Reset to Defaults', 'metasync'); ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Sidebar -->
    <div class="metasync-media-sidebar">
        <!-- Server Capabilities -->
        <div class="metasync-card">
            <div class="metasync-card-header">
                <h3><?php esc_html_e('Server Capabilities', 'metasync'); ?></h3>
            </div>
            <div class="metasync-capabilities-list">
                <div class="metasync-capability-item">
                    <span class="metasync-capability-label"><?php esc_html_e('Imagick Extension', 'metasync'); ?></span>
                    <?php if ($capabilities['imagick']): ?>
                        <span class="metasync-capability-badge metasync-badge-success">
                            <span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Available', 'metasync'); ?>
                        </span>
                    <?php else: ?>
                        <span class="metasync-capability-badge metasync-badge-warning">
                            <span class="dashicons dashicons-warning"></span> <?php esc_html_e('Not Available', 'metasync'); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="metasync-capability-item">
                    <span class="metasync-capability-label"><?php esc_html_e('GD Extension', 'metasync'); ?></span>
                    <?php if ($capabilities['gd']): ?>
                        <span class="metasync-capability-badge metasync-badge-success">
                            <span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Available', 'metasync'); ?>
                        </span>
                    <?php else: ?>
                        <span class="metasync-capability-badge metasync-badge-warning">
                            <span class="dashicons dashicons-warning"></span> <?php esc_html_e('Not Available', 'metasync'); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="metasync-capability-item">
                    <span class="metasync-capability-label"><?php esc_html_e('WebP Support', 'metasync'); ?></span>
                    <?php if ($capabilities['webp_support']): ?>
                        <span class="metasync-capability-badge metasync-badge-success">
                            <span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Supported', 'metasync'); ?>
                        </span>
                    <?php else: ?>
                        <span class="metasync-capability-badge metasync-badge-error">
                            <span class="dashicons dashicons-no"></span> <?php esc_html_e('Not Supported', 'metasync'); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="metasync-capability-item">
                    <span class="metasync-capability-label"><?php esc_html_e('AVIF Support', 'metasync'); ?></span>
                    <?php if ($capabilities['avif_support']): ?>
                        <span class="metasync-capability-badge metasync-badge-success">
                            <span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Supported', 'metasync'); ?>
                        </span>
                    <?php else: ?>
                        <span class="metasync-capability-badge metasync-badge-warning">
                            <span class="dashicons dashicons-warning"></span> <?php esc_html_e('Not Supported', 'metasync'); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Feature Status -->
        <div class="metasync-card">
            <div class="metasync-card-header">
                <h3><?php esc_html_e('Active Features', 'metasync'); ?></h3>
            </div>
            <div class="metasync-feature-status-list">
                <div class="metasync-feature-status-item">
                    <span class="metasync-feature-dot <?php echo !empty($settings['enable_conversion']) ? 'active' : 'inactive'; ?>"></span>
                    <span><?php esc_html_e('Image Conversion', 'metasync'); ?></span>
                    <span class="metasync-feature-format"><?php echo !empty($settings['enable_conversion']) ? esc_html(strtoupper($settings['conversion_format'])) : ''; ?></span>
                </div>
                <div class="metasync-feature-status-item">
                    <span class="metasync-feature-dot <?php echo !empty($settings['enable_lazy_loading']) ? 'active' : 'inactive'; ?>"></span>
                    <span><?php esc_html_e('Lazy Loading', 'metasync'); ?></span>
                    <span class="metasync-feature-format"><?php echo !empty($settings['enable_lazy_loading']) ? sprintf(esc_html__('Skip %d', 'metasync'), (int) $settings['lcp_skip_count']) : ''; ?></span>
                </div>
                <div class="metasync-feature-status-item">
                    <span class="metasync-feature-dot <?php echo !empty($settings['enable_dimension_injection']) ? 'active' : 'inactive'; ?>"></span>
                    <span><?php esc_html_e('Dimension Injection', 'metasync'); ?></span>
                </div>
            </div>
        </div>

        <!-- Tips -->
        <div class="metasync-card metasync-warnings-card">
            <div class="metasync-card-header">
                <h3><?php esc_html_e('Performance Tips', 'metasync'); ?></h3>
            </div>
            <ul class="metasync-warnings-list">
                <li>
                    <span class="dashicons dashicons-performance" style="color: #10b981;"></span>
                    <?php esc_html_e('WebP images are typically 25-35% smaller than JPEG with comparable quality.', 'metasync'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-visibility" style="color: #3b82f6;"></span>
                    <?php esc_html_e('Always keep LCP Skip Count at 2-3 to ensure hero images load immediately.', 'metasync'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-layout" style="color: #f59e0b;"></span>
                    <?php esc_html_e('Dimension injection prevents layout shifts (CLS) - a key Core Web Vital metric.', 'metasync'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-info" style="color: #2271b1;"></span>
                    <?php esc_html_e('Use "Alongside" strategy to keep originals as fallback for older browsers.', 'metasync'); ?>
                </li>
            </ul>
        </div>
    </div>
</div>

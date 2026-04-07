<?php
/**
 * Code Minification Admin Page View - Tab Container
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.5.23
 *
 * Variables expected from page callback:
 * @var bool   $save_success
 * @var string $current_tab
 */

if (!defined('WPINC')) {
    die;
}

$settings  = Metasync_Minification_Settings::get_settings();
$conflicts = Metasync_Compatibility_Guard::get_active_conflicts();
?>
<div class="metasync-code-minification-page">

    <?php if ($save_success): ?>
        <div class="metasync-notice metasync-notice-success">
            <span class="dashicons dashicons-yes-alt"></span>
            <?php esc_html_e('Code minification settings saved successfully.', 'metasync'); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($conflicts)): ?>
        <div class="metasync-notice metasync-notice-warning">
            <span class="dashicons dashicons-warning"></span>
            <div>
                <strong><?php esc_html_e('Compatibility Notice:', 'metasync'); ?></strong>
                <ul style="margin: 5px 0 0 20px;">
                    <?php if (isset($conflicts['css_minify'])): ?>
                        <li><?php printf(
                            esc_html__('CSS Minification is disabled because %s CSS minification is active.', 'metasync'),
                            '<strong>' . esc_html($conflicts['css_minify']) . '</strong>'
                        ); ?></li>
                    <?php endif; ?>
                    <?php if (isset($conflicts['js_minify'])): ?>
                        <li><?php printf(
                            esc_html__('JS Minification is disabled because %s JS minification is active.', 'metasync'),
                            '<strong>' . esc_html($conflicts['js_minify']) . '</strong>'
                        ); ?></li>
                    <?php endif; ?>
                    <?php if (isset($conflicts['js_defer'])): ?>
                        <li><?php printf(
                            esc_html__('JS Defer/Delay is disabled because %s JS defer is active.', 'metasync'),
                            '<strong>' . esc_html($conflicts['js_defer']) . '</strong>'
                        ); ?></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tab Navigation -->
    <div class="metasync-tabs">
        <ul class="metasync-tab-nav">
            <li>
                <a href="#" data-tab="settings" class="<?php echo $current_tab === 'settings' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <?php esc_html_e('Settings', 'metasync'); ?>
                </a>
            </li>
            <li>
                <a href="#" data-tab="cache" class="<?php echo $current_tab === 'cache' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-database"></span>
                    <?php esc_html_e('Cache', 'metasync'); ?>
                </a>
            </li>
        </ul>
    </div>

    <!-- Settings Tab -->
    <div id="settings-content" class="metasync-tab-content <?php echo $current_tab === 'settings' ? 'active' : ''; ?>">
        <?php require_once __DIR__ . '/settings-tab.php'; ?>
    </div>

    <!-- Cache Tab -->
    <div id="cache-content" class="metasync-tab-content <?php echo $current_tab === 'cache' ? 'active' : ''; ?>">
        <?php require_once __DIR__ . '/cache-tab.php'; ?>
    </div>

</div>

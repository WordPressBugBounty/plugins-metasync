<?php
/**
 * Media Optimization Admin Page View - Tab Container
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.6.0
 *
 * Variables expected from page callback:
 * @var bool  $save_success
 * @var string $current_tab
 * @var Metasync_Media_Library_List_Table|null $list_table
 * @var array|null $stats
 * @var array|null $batch_progress
 */

if (!defined('WPINC')) {
    die;
}

$settings     = Metasync_Media_Settings::get_settings();
$capabilities = Metasync_Media_Settings::get_server_capabilities();
?>
<div class="metasync-media-optimization-page">

    <?php if (!$capabilities['has_library']): ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e('Warning:', 'metasync'); ?></strong>
                <?php esc_html_e('Neither Imagick nor GD PHP extension is available. Image conversion will not work. Please contact your hosting provider to enable one of these extensions.', 'metasync'); ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($save_success): ?>
        <div class="metasync-notice metasync-notice-success">
            <span class="dashicons dashicons-yes-alt"></span>
            <?php esc_html_e('Media optimization settings saved successfully.', 'metasync'); ?>
        </div>
    <?php endif; ?>

    <!-- Tab Navigation -->
    <div class="metasync-tabs">
        <ul class="metasync-tab-nav">
            <li>
                <a href="#" data-tab="settings" class="<?php echo $current_tab === 'settings' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-admin-generic" style="font-size: 16px; margin-right: 4px; vertical-align: text-bottom;"></span>
                    <?php esc_html_e('Settings', 'metasync'); ?>
                </a>
            </li>
            <li>
                <a href="#" data-tab="image-library" class="<?php echo $current_tab === 'image-library' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-format-gallery" style="font-size: 16px; margin-right: 4px; vertical-align: text-bottom;"></span>
                    <?php esc_html_e('Image Library', 'metasync'); ?>
                </a>
            </li>
        </ul>
    </div>

    <!-- Settings Tab -->
    <div id="settings-content" class="metasync-tab-content <?php echo $current_tab === 'settings' ? 'active' : ''; ?>">
        <?php require_once __DIR__ . '/settings-tab.php'; ?>
    </div>

    <!-- Image Library Tab -->
    <div id="image-library-content" class="metasync-tab-content <?php echo $current_tab === 'image-library' ? 'active' : ''; ?>">
        <?php
        if ($list_table) {
            require_once __DIR__ . '/image-library-tab.php';
        }
        ?>
    </div>

</div>

<?php
// CSS and JS are now enqueued via wp_enqueue_script/wp_enqueue_style
// in media-optimization-loader.php on the admin_enqueue_scripts hook.
?>

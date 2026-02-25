<?php
/**
 * MetaSync HTML Visual Editor Page Template
 *
 * @package    Metasync
 * @subpackage Metasync/admin/partials
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="metasync-html-editor-wrapper">
    <!-- Editor Header -->
    <div class="metasync-editor-header">
        <div class="metasync-editor-header-left">
            <a href="<?php echo esc_url(admin_url('edit.php?post_type=page')); ?>" class="metasync-back-button">
                <span class="dashicons dashicons-arrow-left-alt2"></span>
                <?php _e('Back to Pages', 'metasync'); ?>
            </a>
            <div class="metasync-page-title">
                <strong><?php echo esc_html($post->post_title); ?></strong>
                <span class="metasync-editor-badge">⚡ <?php echo esc_html($label); ?></span>
            </div>
        </div>

        <div class="metasync-editor-header-center">
            <div class="metasync-editor-status">
                <span class="metasync-status-indicator"></span>
                <span class="metasync-status-text"><?php _e('Ready', 'metasync'); ?></span>
            </div>
        </div>

        <div class="metasync-editor-header-right">
            <button type="button" class="button metasync-preview-button" title="<?php esc_attr_e('Preview', 'metasync'); ?>">
                <span class="dashicons dashicons-visibility"></span>
                <?php _e('Preview', 'metasync'); ?>
            </button>
            <button type="button" class="button button-primary metasync-save-button" title="<?php esc_attr_e('Save Changes', 'metasync'); ?>">
                <span class="dashicons dashicons-saved"></span>
                <?php _e('Save', 'metasync'); ?>
            </button>
        </div>
    </div>

    <!-- Editor Container -->
    <div class="metasync-editor-container" id="metasync-gjs-editor">
        <!-- GrapesJS will initialize here -->
    </div>

    <!-- Hidden data -->
    <textarea id="metasync-html-content" style="display:none;"><?php echo esc_textarea($html_content); ?></textarea>
</div>

<style>
/* Inline critical styles to prevent flash */
.metasync-html-editor-wrapper {
    position: fixed;
    top: 32px;
    left: 0;
    right: 0;
    bottom: 0;
    background: #1e1e1e;
    z-index: 99999;
}

@media screen and (max-width: 782px) {
    .metasync-html-editor-wrapper {
        top: 46px;
    }
}

#wpadminbar {
    z-index: 100000;
}
</style>

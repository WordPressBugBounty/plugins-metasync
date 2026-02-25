<?php
/**
 * Robots.txt Admin Page View
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.5.6
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

$site_url = get_site_url();
$robots_url = trailingslashit($site_url) . 'robots.txt';
?>
<div class="metasync-robots-txt-page">
    <?php if (!$is_writable): ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e('Warning:', 'metasync'); ?></strong>
                <?php if ($file_exists): ?>
                    <?php esc_html_e('The robots.txt file is not writable. Please check file permissions.', 'metasync'); ?>
                <?php else: ?>
                    <?php esc_html_e('Cannot create robots.txt file. Please check that your WordPress root directory is writable.', 'metasync'); ?>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="metasync-robots-txt-container">
        <div class="metasync-robots-txt-editor">
            <div class="metasync-card">
                <div class="metasync-card-header">
                    <h2><?php esc_html_e('Edit robots.txt', 'metasync'); ?></h2>
                    <div class="metasync-robots-info">
                        <span class="metasync-robots-status">
                            <?php 
                            $robots_txt = Metasync_Robots_Txt::get_instance();
                            $is_virtual = $robots_txt->is_virtual_mode();
                            if ($file_exists): ?>
                                <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                                <?php esc_html_e('Generated', 'metasync'); ?>
                                <?php if ($is_virtual): ?>
                                    <span style="color: #2271b1; font-size: 12px; margin-left: 5px;">(Virtual)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="dashicons dashicons-warning" style="color: #f0b849;"></span>
                                <?php esc_html_e('Not Generated', 'metasync'); ?>
                            <?php endif; ?>
                        </span>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=metasync-import-external&tab=robots')); ?>" class="button button-secondary" style="margin-right: 10px;">
                            <span>📥</span> <?php esc_html_e('Import from SEO Plugins', 'metasync'); ?>
                        </a>
                        <a href="<?php echo esc_url($robots_url); ?>" target="_blank" class="button button-secondary">
                            <?php esc_html_e('View robots.txt', 'metasync'); ?>
                            <span class="dashicons dashicons-external" style="margin-top: 4px;"></span>
                        </a>
                    </div>
                </div>

                <form method="post" action="" id="robots-txt-form">
                    <?php wp_nonce_field('metasync_save_robots_txt', 'metasync_robots_txt_nonce'); ?>

                    <div class="metasync-editor-container">
                        <textarea
                            id="robots-txt-editor"
                            name="robots_content"
                            rows="20"
                            class="large-text code"
                            <?php echo !$is_writable ? 'readonly' : ''; ?>
                        ><?php echo esc_textarea($current_content); ?></textarea>
                    </div>

                    <div class="metasync-editor-actions">
                        <?php if ($is_writable): ?>
                            <button type="submit" id="save-robots-btn" class="button button-primary button-large">
                                <span class="dashicons dashicons-saved" style="margin-top: 4px;"></span>
                                <?php esc_html_e('Save Changes', 'metasync'); ?>
                            </button>
                            <button type="button" id="reset-to-default" class="button button-secondary">
                                <span class="dashicons dashicons-image-rotate" style="margin-top: 4px;"></span>
                                <?php esc_html_e('Reset to Default', 'metasync'); ?>
                            </button>
                        <?php endif; ?>
                        <button type="button" id="validate-content" class="button button-secondary">
                            <span class="dashicons dashicons-yes-alt" style="margin-top: 4px;"></span>
                            <?php esc_html_e('Validate', 'metasync'); ?>
                        </button>
                    </div>

                    <div id="validation-results" class="metasync-validation-results" style="display: none;"></div>
                </form>

                <div class="metasync-robots-help">
                    <h3><?php esc_html_e('Quick Guide', 'metasync'); ?></h3>
                    <div class="metasync-help-grid">
                        <div class="metasync-help-item">
                            <strong>User-agent:</strong>
                            <p><?php esc_html_e('Specifies which crawler the rules apply to. Use * for all crawlers.', 'metasync'); ?></p>
                            <code>User-agent: *</code>
                        </div>
                        <div class="metasync-help-item">
                            <strong>Disallow:</strong>
                            <p><?php esc_html_e('Blocks access to specific paths or files.', 'metasync'); ?></p>
                            <code>Disallow: /wp-admin/</code>
                        </div>
                        <div class="metasync-help-item">
                            <strong>Allow:</strong>
                            <p><?php esc_html_e('Explicitly allows access to specific paths (overrides Disallow).', 'metasync'); ?></p>
                            <code>Allow: /wp-admin/admin-ajax.php</code>
                        </div>
                        <div class="metasync-help-item">
                            <strong>Sitemap:</strong>
                            <p><?php esc_html_e('Points crawlers to your XML sitemap.', 'metasync'); ?></p>
                            <code>Sitemap: <?php echo esc_html($site_url); ?>/sitemap.xml</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="metasync-robots-txt-sidebar">
            <div class="metasync-card">
                <div class="metasync-card-header">
                    <h3><?php esc_html_e('Backup History', 'metasync'); ?></h3>
                </div>

                <?php if (!empty($backups)): ?>
                    <div class="metasync-backups-list">
                        <?php foreach ($backups as $backup): ?>
                            <div class="metasync-backup-item">
                                <div class="metasync-backup-info">
                                    <strong><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($backup['created_at']))); ?></strong>
                                    <?php if (!empty($backup['created_by_name'])): ?>
                                        <span class="metasync-backup-author">
                                            <?php printf(esc_html__('by %s', 'metasync'), esc_html($backup['created_by_name'])); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="metasync-backup-actions">
                                    <button type="button" 
                                            class="button button-small metasync-preview-backup" 
                                            data-backup-id="<?php echo esc_attr($backup['id']); ?>"
                                            title="<?php esc_html_e('Preview', 'metasync'); ?>">
                                        <span class="dashicons dashicons-visibility" style="margin-top: 3px;"></span>
                                        <?php esc_html_e('Preview', 'metasync'); ?>
                                    </button>
                                    <button type="button"
                                            class="button button-small metasync-restore-backup"
                                            data-backup-id="<?php echo esc_attr($backup['id']); ?>"
                                            data-nonce="<?php echo wp_create_nonce('metasync_restore_robots_backup'); ?>"
                                            title="<?php esc_html_e('Restore', 'metasync'); ?>">
                                        <span class="dashicons dashicons-backup" style="margin-top: 3px;"></span>
                                        <?php esc_html_e('Restore', 'metasync'); ?>
                                    </button>
                                    <button type="button"
                                            class="button button-small button-link-delete metasync-delete-backup"
                                            data-backup-id="<?php echo esc_attr($backup['id']); ?>"
                                            data-nonce="<?php echo wp_create_nonce('metasync_delete_robots_backup'); ?>"
                                            title="<?php esc_html_e('Delete', 'metasync'); ?>">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="description"><?php esc_html_e('No backups available yet. Backups are created automatically when you save changes.', 'metasync'); ?></p>
                <?php endif; ?>
            </div>

            <div class="metasync-card metasync-warnings-card">
                <div class="metasync-card-header">
                    <h3><?php esc_html_e('Important Notes', 'metasync'); ?></h3>
                </div>
                <ul class="metasync-warnings-list">
                    <li>
                        <span class="dashicons dashicons-warning" style="color: #f0b849;"></span>
                        <?php esc_html_e('Never block your entire site (Disallow: /) as it will prevent all search engines from indexing your content.', 'metasync'); ?>
                    </li>
                    <li>
                        <span class="dashicons dashicons-info" style="color: #2271b1;"></span>
                        <?php esc_html_e('Changes to robots.txt take effect immediately but may take time for search engines to recognize.', 'metasync'); ?>
                    </li>
                    <li>
                        <span class="dashicons dashicons-admin-plugins" style="color: #2271b1;"></span>
                        <?php esc_html_e('Some hosting providers may override robots.txt files. Check with your host if changes don\'t work.', 'metasync'); ?>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Validation Modal (Fixed Position Overlay) -->
<div id="robots-validation-modal" class="metasync-modal" style="display: none;">
    <div class="metasync-modal-overlay"></div>
    <div class="metasync-modal-content">
        <div class="metasync-modal-header">
            <h2 id="modal-title"></h2>
            <button type="button" class="metasync-modal-close">&times;</button>
        </div>
        <div class="metasync-modal-body" id="modal-body"></div>
        <div class="metasync-modal-footer" id="modal-footer">
            <div class="metasync-modal-footer-left">
                <button type="button" class="button button-primary metasync-modal-confirm" id="modal-confirm-btn"><?php esc_html_e('Save Anyway', 'metasync'); ?></button>
            </div>
            <div class="metasync-modal-footer-right">
                <button type="button" class="button button-secondary metasync-modal-cancel"><?php esc_html_e('Cancel', 'metasync'); ?></button>
            </div>
        </div>
    </div>
</div>

<?php
// Load separate CSS and JS files
require_once __DIR__ . '/styles.php';
require_once __DIR__ . '/scripts.php';

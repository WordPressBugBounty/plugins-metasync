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
                    <h2><?php esc_html_e('Edit robots.txt', 'metasync'); ?><?php Metasync::render_tooltip_icon('robots_txt_editor', 'This file tells search engines which pages they may crawl. A wrong rule (e.g. Disallow: /) can remove your entire site from Google — change it only if you know the effect.'); ?></h2>
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
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '-import-external&tab=robots')); ?>" class="button button-secondary" style="margin-right: 10px;">
                            <span class="dashicons dashicons-download" style="margin-top:3px;font-size:15px;width:15px;height:15px;"></span> <?php esc_html_e('Import from SEO Plugins', 'metasync'); ?>
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

                <?php
                // Backup History is paginated so the list always reflects the
                // full stored state (and its true total), rather than a fixed
                // slice that makes deleted entries appear to "reappear".
                $robots_txt         = Metasync_Robots_Txt::get_instance();
                $backup_per_page    = Metasync_Robots_Txt_Database::BACKUPS_PER_PAGE;
                $backup_total       = $robots_txt->get_backup_count();
                $backup_total_pages = max(1, (int) ceil($backup_total / $backup_per_page));
                $backup_page        = 1;
                // $backups already holds the first page (from the controller).
                require __DIR__ . '/backup-history.php';
                ?>
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

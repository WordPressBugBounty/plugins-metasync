<?php
/**
 * Robots.txt Backup History — list + pagination partial
 *
 * Rendered both on initial page load (robots-txt/views/admin-page.php) and by
 * the AJAX pagination/refresh endpoint (ajax_get_robots_backups), so the markup
 * lives in a single place.
 *
 * Expected variables (set by the includer):
 *   array $backups            Backups for the current page.
 *   int   $backup_total       Total number of stored backups.
 *   int   $backup_page        Current page (1-based).
 *   int   $backup_per_page    Backups shown per page.
 *   int   $backup_total_pages Total number of pages.
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.5.6
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

$backup_total       = isset($backup_total) ? (int) $backup_total : 0;
$backup_per_page    = isset($backup_per_page) ? (int) $backup_per_page : 10;
$backup_page        = isset($backup_page) ? max(1, (int) $backup_page) : 1;
$backup_total_pages = isset($backup_total_pages) ? max(1, (int) $backup_total_pages) : 1;
$backups            = isset($backups) && is_array($backups) ? $backups : array();

// Range of records shown on this page (e.g. "1–10 of 45").
$range_start = $backup_total > 0 ? (($backup_page - 1) * $backup_per_page) + 1 : 0;
$range_end   = min($backup_page * $backup_per_page, $backup_total);
?>
<div id="metasync-backups-panel" data-page="<?php echo esc_attr((string) $backup_page); ?>" data-total-pages="<?php echo esc_attr((string) $backup_total_pages); ?>">
    <?php if ($backup_total > 0): ?>
        <div class="metasync-backups-summary">
            <?php
            printf(
                /* translators: 1: first record on page, 2: last record on page, 3: total records */
                esc_html__('Showing %1$s–%2$s of %3$s backups', 'metasync'),
                esc_html(number_format_i18n($range_start)),
                esc_html(number_format_i18n($range_end)),
                esc_html(number_format_i18n($backup_total))
            );
            ?>
        </div>

        <div class="metasync-backups-list">
            <?php foreach ($backups as $backup): ?>
                <div class="metasync-backup-item">
                    <div class="metasync-backup-info">
                        <strong><?php echo esc_html(get_date_from_gmt($backup['created_at'], get_option('date_format') . ' ' . get_option('time_format'))); ?></strong>
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
                                title="<?php esc_attr_e('Preview', 'metasync'); ?>">
                            <span class="dashicons dashicons-visibility"></span>
                            <?php esc_html_e('Preview', 'metasync'); ?>
                        </button>
                        <button type="button"
                                class="button button-small metasync-restore-backup"
                                data-backup-id="<?php echo esc_attr($backup['id']); ?>"
                                data-nonce="<?php echo esc_attr(wp_create_nonce('metasync_restore_robots_backup')); ?>"
                                title="<?php esc_attr_e('Restore', 'metasync'); ?>">
                            <span class="dashicons dashicons-backup"></span>
                            <?php esc_html_e('Restore', 'metasync'); ?>
                        </button>
                        <button type="button"
                                class="button button-small button-link-delete metasync-delete-backup"
                                data-backup-id="<?php echo esc_attr($backup['id']); ?>"
                                data-nonce="<?php echo esc_attr(wp_create_nonce('metasync_delete_robots_backup')); ?>"
                                title="<?php esc_attr_e('Delete', 'metasync'); ?>">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($backup_total_pages > 1): ?>
            <div class="metasync-backups-pagination">
                <button type="button"
                        class="button button-small metasync-backup-page"
                        data-page="<?php echo esc_attr((string) ($backup_page - 1)); ?>"
                        <?php disabled($backup_page <= 1); ?>>
                    <span class="dashicons dashicons-arrow-left-alt2"></span>
                    <?php esc_html_e('Prev', 'metasync'); ?>
                </button>
                <span class="metasync-backups-page-indicator">
                    <?php
                    printf(
                        /* translators: 1: current page, 2: total pages */
                        esc_html__('Page %1$s of %2$s', 'metasync'),
                        esc_html(number_format_i18n($backup_page)),
                        esc_html(number_format_i18n($backup_total_pages))
                    );
                    ?>
                </span>
                <button type="button"
                        class="button button-small metasync-backup-page"
                        data-page="<?php echo esc_attr((string) ($backup_page + 1)); ?>"
                        <?php disabled($backup_page >= $backup_total_pages); ?>>
                    <?php esc_html_e('Next', 'metasync'); ?>
                    <span class="dashicons dashicons-arrow-right-alt2"></span>
                </button>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <p class="description"><?php esc_html_e('No backups available yet. Backups are created automatically when you save changes.', 'metasync'); ?></p>
    <?php endif; ?>
</div>

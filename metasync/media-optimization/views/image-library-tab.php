<?php
/**
 * Media Optimization Image Library Tab View
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.6.0
 *
 * Variables expected:
 * @var Metasync_Media_Library_List_Table $list_table
 * @var array $stats
 * @var array $batch_progress
 */

if (!defined('WPINC')) {
    die;
}
?>

<!-- Stats Bar -->
<div class="metasync-card metasync-stats-card">
    <div class="metasync-stats-row">
        <div class="metasync-stat-item">
            <span class="metasync-stat-number"><?php echo esc_html(number_format_i18n($stats['total'])); ?></span>
            <span class="metasync-stat-label"><?php esc_html_e('Total Images', 'metasync'); ?></span>
        </div>
        <div class="metasync-stat-item metasync-stat-success">
            <span class="metasync-stat-number"><?php echo esc_html(number_format_i18n($stats['optimized'])); ?></span>
            <span class="metasync-stat-label"><?php esc_html_e('Optimized', 'metasync'); ?></span>
        </div>
        <div class="metasync-stat-item metasync-stat-pending">
            <span class="metasync-stat-number"><?php echo esc_html(number_format_i18n($stats['unoptimized'])); ?></span>
            <span class="metasync-stat-label"><?php esc_html_e('Unoptimized', 'metasync'); ?></span>
        </div>
        <div class="metasync-stat-item">
            <div class="metasync-stat-progress-wrap">
                <div class="metasync-stat-progress-bar">
                    <div class="metasync-stat-progress-fill" style="width: <?php echo esc_attr($stats['percentage']); ?>%;"></div>
                </div>
                <span class="metasync-stat-percentage"><?php echo esc_html($stats['percentage']); ?>%</span>
            </div>
            <span class="metasync-stat-label"><?php esc_html_e('Optimization Rate', 'metasync'); ?></span>
        </div>
    </div>
</div>

<!-- Batch Progress (visible when running) -->
<div class="metasync-card metasync-batch-card" id="metasync-batch-progress"
     style="<?php echo ($batch_progress['status'] !== 'running') ? 'display:none;' : ''; ?>">
    <div class="metasync-batch-header">
        <div class="metasync-batch-info">
            <span class="metasync-batch-spinner"></span>
            <span class="metasync-batch-text" id="metasync-batch-text">
                <?php
                if ($batch_progress['status'] === 'running') {
                    printf(
                        esc_html__('Optimizing %1$d of %2$d images...', 'metasync'),
                        $batch_progress['processed'],
                        $batch_progress['total']
                    );
                }
                ?>
            </span>
        </div>
        <button type="button" class="button button-small" id="metasync-cancel-batch">
            <?php esc_html_e('Cancel', 'metasync'); ?>
        </button>
    </div>
    <div class="metasync-batch-progress-bar">
        <div class="metasync-batch-progress-fill" id="metasync-batch-fill"
             style="width: <?php
                echo $batch_progress['total'] > 0
                    ? esc_attr(round(($batch_progress['processed'] / $batch_progress['total']) * 100))
                    : '0';
             ?>%;"></div>
    </div>
    <div class="metasync-batch-details">
        <span id="metasync-batch-processed"><?php echo esc_html($batch_progress['processed']); ?></span> /
        <span id="metasync-batch-total"><?php echo esc_html($batch_progress['total']); ?></span>
        <?php esc_html_e('images processed', 'metasync'); ?>
        <?php if ($batch_progress['failed'] > 0): ?>
            &middot; <span class="metasync-batch-failed" id="metasync-batch-failed"><?php echo esc_html($batch_progress['failed']); ?></span> <?php esc_html_e('failed', 'metasync'); ?>
        <?php endif; ?>
    </div>
</div>

<!-- Batch Completed Notice -->
<div class="metasync-card metasync-batch-complete-card" id="metasync-batch-complete" style="display:none;">
    <span class="dashicons dashicons-yes-alt" style="color: var(--dashboard-success);"></span>
    <span id="metasync-batch-complete-text"></span>
    <button type="button" class="metasync-dismiss-btn" id="metasync-dismiss-complete">
        <span class="dashicons dashicons-dismiss"></span>
    </button>
</div>

<!-- List Table -->
<form method="get" id="metasync-image-library-form">
    <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page'] ?? ''); ?>" />
    <input type="hidden" name="tab" value="image-library" />

    <!-- Unified Toolbar: Optimize All + Search -->
    <div class="metasync-table-toolbar">
        <div class="metasync-toolbar-left">
            <button type="button" class="button button-primary" id="metasync-optimize-all"
                    <?php disabled($batch_progress['status'] === 'running' || $stats['unoptimized'] === 0); ?>>
                <span class="dashicons dashicons-performance" style="margin-top: 3px;"></span>
                <?php esc_html_e('Optimize All', 'metasync'); ?>
                <?php if ($stats['unoptimized'] > 0): ?>
                    <span class="metasync-count-badge"><?php echo esc_html(number_format_i18n($stats['unoptimized'])); ?></span>
                <?php endif; ?>
            </button>
        </div>
        <div class="metasync-toolbar-right">
            <?php $list_table->search_box(__('Search', 'metasync'), 'metasync-image-search'); ?>
        </div>
    </div>

    <?php $list_table->display(); ?>
</form>

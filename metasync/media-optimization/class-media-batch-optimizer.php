<?php
/**
 * Metasync_Media_Batch_Optimizer
 * AJAX-driven batch optimizer for converting existing media library images.
 * Uses browser-driven AJAX chaining for speed, with WP Cron as fallback.
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Media_Batch_Optimizer {

    private const QUEUE_OPTION    = 'metasync_batch_optimize_queue';
    private const PROGRESS_OPTION = 'metasync_batch_optimize_progress';
    private const CRON_HOOK       = 'metasync_media_batch_optimize_cron';
    private const BATCH_SIZE      = 10;
    private const CRON_TIME_LIMIT = 30; // seconds per cron tick

    /**
     * Start a new batch optimization run.
     *
     * @param array $settings Media optimization settings.
     * @return array Progress data.
     */
    public static function start_batch(array $settings): array {
        if (self::is_running()) {
            return self::get_progress();
        }

        $ids = self::query_unoptimized_ids();

        if (empty($ids)) {
            return [
                'total'      => 0,
                'processed'  => 0,
                'failed'     => 0,
                'status'     => 'completed',
                'started_at' => current_time('mysql'),
            ];
        }

        update_option(self::QUEUE_OPTION, $ids, false);

        $progress = [
            'total'      => count($ids),
            'processed'  => 0,
            'failed'     => 0,
            'status'     => 'running',
            'started_at' => current_time('mysql'),
        ];
        update_option(self::PROGRESS_OPTION, $progress, false);

        // Store settings for cron fallback to use
        update_option('metasync_batch_optimize_settings', $settings, false);

        // Schedule cron as fallback (runs if browser tab is closed)
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 120, 'metasync_every_2_minutes', self::CRON_HOOK);
        }

        return self::get_progress();
    }

    /**
     * Cancel a running batch optimization.
     */
    public static function cancel_batch(): void {
        $progress = self::get_progress();
        $progress['status'] = 'cancelled';
        update_option(self::PROGRESS_OPTION, $progress, false);

        delete_option(self::QUEUE_OPTION);
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Get current batch progress.
     *
     * @return array Progress data.
     */
    public static function get_progress(): array {
        $default = [
            'total'      => 0,
            'processed'  => 0,
            'failed'     => 0,
            'status'     => 'idle',
            'started_at' => '',
        ];

        return wp_parse_args(get_option(self::PROGRESS_OPTION, []), $default);
    }

    /**
     * Check if a batch is currently running.
     */
    public static function is_running(): bool {
        $progress = self::get_progress();
        return $progress['status'] === 'running';
    }

    /**
     * Process one batch via AJAX (browser-driven chaining).
     * Processes BATCH_SIZE images and returns updated progress immediately.
     *
     * @return array Updated progress data.
     */
    public static function process_ajax_tick(): array {
        $progress = self::get_progress();

        if ($progress['status'] !== 'running') {
            return $progress;
        }

        $queue = get_option(self::QUEUE_OPTION, []);

        if (empty($queue)) {
            self::complete_batch();
            return self::get_progress();
        }

        $settings = get_option('metasync_batch_optimize_settings', []);
        $batch = array_splice($queue, 0, self::BATCH_SIZE);

        foreach ($batch as $attachment_id) {
            // Re-check status in case cancel was triggered mid-batch
            $current = get_option(self::PROGRESS_OPTION, []);
            if (($current['status'] ?? '') !== 'running') {
                break;
            }

            self::convert_single($attachment_id, $settings, $progress);
        }

        update_option(self::QUEUE_OPTION, $queue, false);
        update_option(self::PROGRESS_OPTION, $progress, false);

        if (empty($queue)) {
            self::complete_batch();
            return self::get_progress();
        }

        return $progress;
    }

    /**
     * Process batch tick via WP Cron (fallback when browser tab is closed).
     * Runs a time-limited loop to process as many images as possible within CRON_TIME_LIMIT seconds.
     */
    public static function process_batch_tick(): void {
        $progress = self::get_progress();

        if ($progress['status'] !== 'running') {
            self::complete_batch();
            return;
        }

        $queue = get_option(self::QUEUE_OPTION, []);

        if (empty($queue)) {
            self::complete_batch();
            return;
        }

        $settings  = get_option('metasync_batch_optimize_settings', []);
        $start     = time();

        // Process images until time limit or queue empty
        while (!empty($queue) && (time() - $start) < self::CRON_TIME_LIMIT) {
            $batch = array_splice($queue, 0, self::BATCH_SIZE);

            foreach ($batch as $attachment_id) {
                self::convert_single($attachment_id, $settings, $progress);
            }

            // Save progress after each batch (in case of crash)
            update_option(self::PROGRESS_OPTION, $progress, false);
        }

        update_option(self::QUEUE_OPTION, $queue, false);

        if (empty($queue)) {
            self::complete_batch();
        }
    }

    /**
     * Convert a single attachment with error handling.
     * Catches fatal errors (e.g. memory_limit) per-image so the batch continues.
     */
    private static function convert_single(int $attachment_id, array $settings, array &$progress): void {
        try {
            $success = Metasync_Image_Converter::convert_attachment($attachment_id, $settings);
            $progress['processed']++;
            if (!$success) {
                $progress['failed']++;
            }
        } catch (\Throwable $e) {
            $progress['processed']++;
            $progress['failed']++;
            error_log('[MetaSync Media Opt] Batch conversion error for attachment ' . $attachment_id . ': ' . $e->getMessage());
        }
    }

    /**
     * Mark batch as completed and clean up.
     */
    private static function complete_batch(): void {
        $progress = self::get_progress();
        if ($progress['status'] === 'running') {
            $progress['status'] = 'completed';
            update_option(self::PROGRESS_OPTION, $progress, false);
        }

        delete_option(self::QUEUE_OPTION);
        delete_option('metasync_batch_optimize_settings');
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Query all JPEG/PNG attachment IDs that have not been converted.
     *
     * @return int[] Attachment IDs.
     */
    private static function query_unoptimized_ids(): array {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_metasync_converted_format',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ];

        return get_posts($args);
    }
}

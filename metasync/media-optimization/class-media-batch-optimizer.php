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
    private const DEFAULT_BATCH_SIZE    = 10;
    private const MIN_BATCH_SIZE       = 2;
    private const MAX_FILTER_BATCH_SIZE = 50;
    private const CRON_TIME_LIMIT      = 30; // seconds per cron tick
    private const TIME_SAFETY_MARGIN   = 5;  // seconds reserved for saving progress
    private const QUERY_PAGE_SIZE      = 1000;

    /**
     * Memory per image estimate in bytes (30 MB).
     * Used to calculate how many images can be processed safely.
     */
    private const MEMORY_PER_IMAGE = 30 * 1024 * 1024;

    /**
     * Calculate adaptive batch size based on available memory.
     *
     * Uses PHP memory_limit and current usage to determine a safe batch size.
     * Filterable via 'metasync_batch_optimize_size' for manual override.
     *
     * @return int Batch size (clamped between MIN_BATCH_SIZE and DEFAULT_BATCH_SIZE).
     */
    public static function get_batch_size(): int {
        $filtered = apply_filters('metasync_batch_optimize_size', 0);
        if ($filtered > 0) {
            return max(self::MIN_BATCH_SIZE, min(self::MAX_FILTER_BATCH_SIZE, (int) $filtered));
        }

        return static::calculate_batch_size(
            (string) ini_get('memory_limit'),
            memory_get_usage(true)
        );
    }

    /**
     * Calculate a safe time limit for cron processing.
     *
     * Respects PHP's max_execution_time so the process can save progress
     * before being killed. Leaves TIME_SAFETY_MARGIN seconds for cleanup.
     *
     * @param int $max_execution_time PHP max_execution_time (0 = unlimited). Accepts parameter for testability.
     * @return int Safe time limit in seconds (minimum 1).
     */
    protected static function get_safe_time_limit(int $max_execution_time = -1): int {
        if ($max_execution_time < 0) {
            $max_execution_time = (int) ini_get('max_execution_time');
        }

        // 0 means no limit — use the configured constant.
        if ($max_execution_time === 0) {
            return self::CRON_TIME_LIMIT;
        }

        $safe = $max_execution_time - self::TIME_SAFETY_MARGIN;

        return max(1, min(self::CRON_TIME_LIMIT, $safe));
    }

    /**
     * Pure calculation of batch size from memory parameters.
     *
     * @param string $memory_limit PHP memory_limit value (e.g. '128M', '-1').
     * @param int    $memory_usage Current memory usage in bytes.
     * @return int Batch size (clamped between MIN_BATCH_SIZE and DEFAULT_BATCH_SIZE).
     */
    protected static function calculate_batch_size(string $memory_limit, int $memory_usage): int {
        // Unlimited or unreadable memory — use default.
        if ($memory_limit === '-1' || $memory_limit === '') {
            return self::DEFAULT_BATCH_SIZE;
        }

        $memory_limit = trim($memory_limit);
        if ($memory_limit === '0') {
            return self::MIN_BATCH_SIZE;
        }

        $limit_bytes = (int) $memory_limit;
        $unit = strtolower(substr($memory_limit, -1));
        $limit_bytes = match ($unit) {
            'g' => $limit_bytes * 1024 * 1024 * 1024,
            'm' => $limit_bytes * 1024 * 1024,
            'k' => $limit_bytes * 1024,
            default => $limit_bytes,
        };

        $available = $limit_bytes - $memory_usage;

        if ($available <= 0) {
            return self::MIN_BATCH_SIZE;
        }

        $safe_count = (int) floor($available / self::MEMORY_PER_IMAGE);

        return max(self::MIN_BATCH_SIZE, min(self::DEFAULT_BATCH_SIZE, $safe_count));
    }

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
     * Processes one adaptive batch of images and returns updated progress immediately.
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
        $batch = array_splice($queue, 0, self::get_batch_size());

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

        $settings   = get_option('metasync_batch_optimize_settings', []);
        $start      = time();
        $time_limit = self::get_safe_time_limit();

        // Process images until time limit or queue empty.
        // Batch size is re-computed each iteration so it adapts as memory fills up.
        while (!empty($queue) && (time() - $start) < $time_limit) {
            $batch = array_splice($queue, 0, self::get_batch_size());

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
     * Uses $wpdb->get_col() with keyset (cursor) pagination instead of
     * WP_Query/get_posts with posts_per_page=-1, avoiding memory spikes
     * on sites with large media libraries (50k+ images).
     *
     * Keyset pagination (WHERE p.ID > last_id) is used instead of
     * LIMIT/OFFSET to maintain constant query performance regardless
     * of page depth and immunity to concurrent inserts/deletes.
     *
     * @return int[] Attachment IDs.
     */
    private static function query_unoptimized_ids(): array {
        global $wpdb;

        $ids     = [];
        $last_id = 0;

        do {
            $batch = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT p.ID
                     FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} pm
                       ON pm.post_id = p.ID AND pm.meta_key = '_metasync_converted_format'
                     WHERE p.post_type = 'attachment'
                       AND p.post_status = 'inherit'
                       AND p.post_mime_type IN ('image/jpeg', 'image/png')
                       AND pm.meta_id IS NULL
                       AND p.ID > %d
                     ORDER BY p.ID ASC
                     LIMIT %d",
                    $last_id,
                    self::QUERY_PAGE_SIZE
                )
            );

            if (!empty($batch)) {
                $int_batch = array_map('intval', $batch);
                array_push($ids, ...$int_batch);
                $last_id = end($int_batch);
            }
        } while (!empty($batch) && count($batch) === self::QUERY_PAGE_SIZE);

        return $ids;
    }
}

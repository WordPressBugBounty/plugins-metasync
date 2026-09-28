<?php
/**
 * Bot Statistics Database Handler
 *
 * Manages database operations for bot detection statistics including
 * total detections, breakdown by bot type, API calls saved, and request logs.
 * Stores unique bot entries with hit counts instead of duplicate rows.
 *
 * @package    Metasync
 * @subpackage Metasync/otto
 * @since      1.0.0
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bot Statistics Database Class
 *
 * Handles all database operations related to bot detection statistics.
 * Uses upsert logic to maintain unique bot entries per (bot_name, ip_address)
 * and tracks hit counts for deduplication.
 *
 * @since 1.0.0
 */
class Metasync_Otto_Bot_Statistics_Database {

    /**
     * Singleton instance
     *
     * @var Metasync_Otto_Bot_Statistics_Database|null
     */
    private static $instance = null;

    /**
     * Table name for bot statistics
     *
     * @var string
     */
    public static $table_name = 'metasync_otto_bot_stats';

    /**
     * Table name for bot request logs
     *
     * @var string
     */
    public static $logs_table_name = 'metasync_otto_bot_logs';

    /**
     * Database version for migrations
     *
     * @var string
     */
    const DB_VERSION = '2.0.0';

    /**
     * Maximum number of unique bot entries to keep
     *
     * @var int
     */
    const MAX_LOG_ENTRIES = 100;

    /**
     * Get singleton instance
     *
     * @return Metasync_Otto_Bot_Statistics_Database
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {
        $this->maybe_create_tables();
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup() {}

    /**
     * Create or migrate database tables when version changes
     *
     * @return void
     */
    private function maybe_create_tables() {
        $current_version = get_option('metasync_otto_bot_stats_db_version', '0');

        if (version_compare($current_version, self::DB_VERSION, '<')) {
            $this->create_tables($current_version);
            update_option('metasync_otto_bot_stats_db_version', self::DB_VERSION);
        }
    }

    /**
     * Create or migrate database tables
     *
     * @param string $from_version Previous DB version for migration logic
     * @return void
     */
    public function create_tables($from_version = '0') {
        global $wpdb;

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $charset_collate = $wpdb->get_charset_collate();

        // Statistics summary table (unchanged)
        $stats_sql = "CREATE TABLE {$wpdb->prefix}metasync_otto_bot_stats (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            stat_key varchar(100) NOT NULL,
            stat_value bigint(20) NOT NULL DEFAULT 0,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY stat_key (stat_key),
            KEY updated_at (updated_at)
        ) $charset_collate;";

        // Logs table v2: unique entries with hit_count
        $logs_sql = "CREATE TABLE {$wpdb->prefix}metasync_otto_bot_logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            bot_name varchar(255) NOT NULL,
            bot_type varchar(50) NOT NULL,
            user_agent text NOT NULL,
            ip_address varchar(45) NOT NULL DEFAULT '',
            detection_method varchar(50) DEFAULT NULL,
            url text DEFAULT NULL,
            hit_count bigint(20) unsigned NOT NULL DEFAULT 1,
            first_seen_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_bot_ip (bot_name(100), ip_address),
            KEY bot_type (bot_type),
            KEY last_seen_at (last_seen_at),
            KEY hit_count (hit_count)
        ) $charset_collate;";

        dbDelta($stats_sql);
        dbDelta($logs_sql);

        // Migrate data from v1 schema if upgrading
        if (version_compare($from_version, '1.0.0', '>=') && version_compare($from_version, '2.0.0', '<')) {
            $this->migrate_v1_to_v2();
        }

        $this->initialize_default_stats();
    }

    /**
     * Migrate v1 per-request rows into v2 unique-entry + hit_count rows.
     * Checks if the old schema columns exist before attempting migration.
     *
     * @return void
     */
    private function migrate_v1_to_v2() {
        global $wpdb;

        // Check if old schema (has created_at but not hit_count) needs migration
        $has_hit_count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository for OTTO bot analytics (admin/cron aggregation views)
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$wpdb->prefix}metasync_otto_bot_logs'
             AND COLUMN_NAME = 'hit_count'"
        );

        if ((int)$has_hit_count > 0) {
            // hit_count column already exists, nothing to migrate
            return;
        }

        // Old rows still have created_at – aggregate them into a temp table, then swap.
        // This is best-effort; if it fails the new schema is empty but functional.
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}metasync_otto_bot_logs");
    }

    /**
     * Initialize default statistics if they don't exist
     *
     * @return void
     */
    private function initialize_default_stats() {
        global $wpdb;

        $defaults = array(
            'total_detections',
            'api_calls_saved',
            'search_engine_bots',
            'cache_warmer_bots',
            'perf_auditor_bots',
            'seo_tool_bots',
            'social_media_bots',
            'archiver_bots',
            'generic_bots',
            'other_bots',
            'unknown_bots'
        );

        foreach ($defaults as $key) {
            // Only insert if it doesn't exist yet; never reset existing counters
            $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository for OTTO bot analytics (admin/cron aggregation views)
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->prefix}metasync_otto_bot_stats (stat_key, stat_value) VALUES (%s, 0)",
                    $key
                )
            );
        }
    }

    // ------------------------------------------------------------------
    //  Write operations
    // ------------------------------------------------------------------

    /**
     * Record a bot detection event.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE so that repeated visits from
     * the same bot_name + ip_address simply increment hit_count and refresh
     * last_seen_at / user_agent / url instead of creating duplicate rows.
     *
     * @param string      $bot_name         Bot identifier (e.g. "Googlebot")
     * @param string      $bot_type         Category key (e.g. "search_engine")
     * @param string      $user_agent       Full user-agent string
     * @param string|null $ip_address       Client IP address
     * @param string|null $detection_method How the bot was detected
     * @return bool True on success
     */
    public function add_detection($bot_name, $bot_type, $user_agent, $ip_address = null, $detection_method = null) {
        global $wpdb;

        $url = isset($_SERVER['REQUEST_URI']) ? home_url($_SERVER['REQUEST_URI']) : '';
        $now = current_time('mysql');

        $bot_name_clean   = sanitize_text_field($bot_name);
        $bot_type_clean   = sanitize_text_field($bot_type);
        $user_agent_clean = sanitize_textarea_field($user_agent);
        $ip_clean         = sanitize_text_field($ip_address ?? '');
        $method_clean     = sanitize_text_field($detection_method ?? '');
        $url_clean        = esc_url_raw($url);

        // Upsert: insert new or increment existing
        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository for OTTO bot analytics (admin/cron aggregation views)
            $wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}metasync_otto_bot_logs
                    (bot_name, bot_type, user_agent, ip_address, detection_method, url, hit_count, first_seen_at, last_seen_at)
                 VALUES (%s, %s, %s, %s, %s, %s, 1, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    hit_count    = hit_count + 1,
                    last_seen_at = VALUES(last_seen_at),
                    user_agent   = VALUES(user_agent),
                    url          = VALUES(url),
                    bot_type     = VALUES(bot_type),
                    detection_method = VALUES(detection_method)",
                $bot_name_clean,
                $bot_type_clean,
                $user_agent_clean,
                $ip_clean,
                $method_clean,
                $url_clean,
                $now,
                $now
            )
        );

        if ($result === false) {
            return false;
        }

        // Update aggregate statistics
        $this->increment_stat('total_detections');

        $category_key = $this->get_category_stat_key($bot_type_clean);
        if ($category_key) {
            $this->increment_stat($category_key);
        }

        // Evict oldest entries beyond the cap
        $this->enforce_log_cap();

        return true;
    }

    /**
     * Increment API calls saved counter
     *
     * @param int $count Number of calls saved
     * @return bool
     */
    public function increment_api_calls_saved($count = 1) {
        return $this->increment_stat('api_calls_saved', $count);
    }

    /**
     * Reset all statistics and logs
     *
     * @return bool
     */
    public function reset_statistics() {
        global $wpdb;

        $a = $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}metasync_otto_bot_stats");
        $b = $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}metasync_otto_bot_logs");

        $this->initialize_default_stats();

        return $a !== false && $b !== false;
    }

    // ------------------------------------------------------------------
    //  Read operations
    // ------------------------------------------------------------------

    /**
     * Get aggregate statistics
     *
     * @return array
     */
    public function get_statistics() {
        global $wpdb;

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository for OTTO bot analytics (admin/cron aggregation views)
            "SELECT stat_key, stat_value FROM {$wpdb->prefix}metasync_otto_bot_stats",
            OBJECT_K
        );

        $result = array(
            'total_detections' => 0,
            'api_calls_saved'  => 0,
            'breakdown'        => array(
                'search_engine' => 0,
                'cache_warmer'  => 0,
                'perf_auditor'  => 0,
                'seo_tool'      => 0,
                'social_media'  => 0,
                'archiver'      => 0,
                'generic'       => 0,
                'other'         => 0,
                'unknown'       => 0
            )
        );

        if (!$rows) {
            return $result;
        }

        $val = function ($key) use ($rows) {
            return isset($rows[$key]) ? (int)$rows[$key]->stat_value : 0;
        };

        $result['total_detections'] = $val('total_detections');
        $result['api_calls_saved']  = $val('api_calls_saved');

        $result['breakdown']['search_engine'] = $val('search_engine_bots');
        $result['breakdown']['cache_warmer']  = $val('cache_warmer_bots');
        $result['breakdown']['perf_auditor']  = $val('perf_auditor_bots');
        $result['breakdown']['seo_tool']      = $val('seo_tool_bots');
        $result['breakdown']['social_media']  = $val('social_media_bots');
        $result['breakdown']['archiver']      = $val('archiver_bots');
        $result['breakdown']['generic']       = $val('generic_bots');
        $result['breakdown']['other']         = $val('other_bots');
        $result['breakdown']['unknown']       = $val('unknown_bots');

        return $result;
    }

    /**
     * Get unique bot entries ordered by most recently seen
     *
     * @param int $limit  Max rows to return
     * @param int $offset Pagination offset
     * @return array
     */
    public function get_recent_requests($limit = 100, $offset = 0) {
        global $wpdb;

        $results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository for OTTO bot analytics (admin/cron aggregation views)
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}metasync_otto_bot_logs
                 ORDER BY last_seen_at DESC, hit_count DESC
                 LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );

        return $results ?: array();
    }

    /**
     * Get total count of unique bot entries
     *
     * @return int
     */
    public function get_total_log_count() {
        global $wpdb;

        return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}metasync_otto_bot_logs"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository for OTTO bot analytics (admin/cron aggregation views)
    }

    /**
     * Get logs filtered by bot type
     *
     * @param string $bot_type Category key
     * @param int    $limit    Max rows
     * @return array
     */
    public function get_logs_by_type($bot_type, $limit = 100) {
        global $wpdb;

        return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository for OTTO bot analytics (admin/cron aggregation views)
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}metasync_otto_bot_logs
                 WHERE bot_type = %s
                 ORDER BY last_seen_at DESC, hit_count DESC
                 LIMIT %d",
                $bot_type,
                $limit
            ),
            ARRAY_A
        ) ?: array();
    }

    /**
     * Get logs within a date range (based on last_seen_at)
     *
     * @param string $start_date Y-m-d H:i:s
     * @param string $end_date   Y-m-d H:i:s
     * @param int    $limit      Max rows
     * @return array
     */
    public function get_logs_by_date_range($start_date, $end_date, $limit = 1000) {
        global $wpdb;

        return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository for OTTO bot analytics (admin/cron aggregation views)
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}metasync_otto_bot_logs
                 WHERE last_seen_at BETWEEN %s AND %s
                 ORDER BY last_seen_at DESC, hit_count DESC
                 LIMIT %d",
                $start_date,
                $end_date,
                $limit
            ),
            ARRAY_A
        ) ?: array();
    }

    /**
     * Delete a specific log entry
     *
     * @param int $log_id Row ID
     * @return bool
     */
    public function delete_log($log_id) {
        global $wpdb;

        return $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository for OTTO bot analytics (admin/cron aggregation views)
            $wpdb->prefix . self::$logs_table_name,
            array('id' => (int)$log_id),
            array('%d')
        ) !== false;
    }

    // ------------------------------------------------------------------
    //  Internal helpers
    // ------------------------------------------------------------------

    /**
     * Atomically increment a stat counter using a single query
     *
     * @param string $stat_key  The statistic key
     * @param int    $increment Amount to add
     * @return bool
     */
    private function increment_stat($stat_key, $increment = 1) {
        global $wpdb;

        // Use INSERT ... ON DUPLICATE KEY UPDATE for atomic upsert
        return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository for OTTO bot analytics (admin/cron aggregation views)
            $wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}metasync_otto_bot_stats (stat_key, stat_value)
                 VALUES (%s, %d)
                 ON DUPLICATE KEY UPDATE stat_value = stat_value + %d",
                $stat_key,
                $increment,
                $increment
            )
        ) !== false;
    }

    /**
     * Map bot_type to its aggregate stat key
     *
     * @param string $bot_type Bot category
     * @return string Stat key
     */
    private function get_category_stat_key($bot_type) {
        $map = array(
            'search_engine' => 'search_engine_bots',
            'cache_warmer'  => 'cache_warmer_bots',
            'perf_auditor'  => 'perf_auditor_bots',
            'seo_tool'      => 'seo_tool_bots',
            'social_media'  => 'social_media_bots',
            'archiver'      => 'archiver_bots',
            'generic'       => 'generic_bots',
            'other'         => 'other_bots',
            'unknown'       => 'unknown_bots',
            'ip_based'      => 'other_bots'
        );

        return isset($map[$bot_type]) ? $map[$bot_type] : 'other_bots';
    }

    /**
     * Enforce the maximum number of unique log entries.
     * Deletes the oldest entries (by last_seen_at) beyond the cap.
     *
     * @return void
     */
    private function enforce_log_cap() {
        global $wpdb;

        $count = $this->get_total_log_count();

        if ($count <= self::MAX_LOG_ENTRIES) {
            return;
        }

        // Find the ID threshold: keep the newest MAX_LOG_ENTRIES rows
        $threshold_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository for OTTO bot analytics (admin/cron aggregation views)
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}metasync_otto_bot_logs
                 ORDER BY last_seen_at DESC, id DESC
                 LIMIT 1 OFFSET %d",
                self::MAX_LOG_ENTRIES
            )
        );

        if ($threshold_id) {
            $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository for OTTO bot analytics (admin/cron aggregation views)
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}metasync_otto_bot_logs
                     WHERE id <= %d
                     AND id NOT IN (
                         SELECT id FROM (
                             SELECT id FROM {$wpdb->prefix}metasync_otto_bot_logs
                             ORDER BY last_seen_at DESC, id DESC
                             LIMIT %d
                         ) AS keep_rows
                     )",
                    $threshold_id,
                    self::MAX_LOG_ENTRIES
                )
            );
        }
    }

    // ------------------------------------------------------------------
    //  Uninstall
    // ------------------------------------------------------------------

    /**
     * Drop all tables (for plugin uninstall)
     *
     * @return void
     */
    public static function drop_tables() {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}metasync_otto_bot_stats"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall cleanup — intentional table removal when the plugin is deleted
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}metasync_otto_bot_logs"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall cleanup — intentional table removal when the plugin is deleted

        delete_option('metasync_otto_bot_stats_db_version');
    }
}

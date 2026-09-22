<?php
/**
 * Bounded history for headless GraphQL refresh decisions.
 */
class Metasync_Headless_Refresh_History
{
    public static $table_name = 'metasync_headless_refresh_history';

    const MAX_RECORDS = 1000;
    const RETENTION_DAYS = 90;

    /**
     * How often pruning may run, in seconds. Inserts happen on page views;
     * pruning does not need to.
     */
    const CLEANUP_INTERVAL = 3600;

    /**
     * Option holding the timestamp of the last pruning run.
     */
    const CLEANUP_OPTION = 'metasync_headless_refresh_history_pruned';

    /**
     * Temporary option used as an atomic cross-request pruning lock.
     */
    const CLEANUP_LOCK_OPTION = 'metasync_headless_refresh_history_pruning';

    /**
     * Option holding the next time a failed table creation may be retried.
     */
    const TABLE_RETRY_OPTION = 'metasync_headless_refresh_history_table_retry';

    const TABLE_RETRY_INTERVAL = 3600;

    /**
     * Set once the table has been confirmed to exist in this request, so the
     * existence check cannot repeat on the hot path.
     *
     * @var bool
     */
    private static $table_verified = false;

    private function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . self::$table_name;
    }

    public static function ensure_table_exists()
    {
        $instance = new self();
        $instance->maybe_create_table();
    }

    /**
     * Create the table when it is missing, throttled by TABLE_RETRY_OPTION.
     *
     * Returns whether the table is usable once this call finishes; the answer
     * always mirrors $table_verified at the moment of return.
     *
     * @return bool
     */
    public function maybe_create_table()
    {
        global $wpdb;

        if (self::$table_verified) {
            return true;
        }

        $retry_at = (int) get_option(self::TABLE_RETRY_OPTION, 0);
        if ($retry_at > time()) {
            return false;
        }

        $table = $this->table_name();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            self::$table_verified = true;

            return true;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            object_type VARCHAR(20) NOT NULL DEFAULT '',
            object_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            url_hash CHAR(64) NOT NULL DEFAULT '',
            outcome VARCHAR(32) NOT NULL DEFAULT '',
            reason VARCHAR(64) NOT NULL DEFAULT '',
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            object_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_created_at (created_at),
            KEY idx_object (object_type, object_id, created_at),
            KEY idx_outcome (outcome, created_at)
        ) {$wpdb->get_charset_collate()};");

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            self::$table_verified = true;
            delete_option(self::TABLE_RETRY_OPTION);

            return true;
        }

        update_option(self::TABLE_RETRY_OPTION, time() + self::TABLE_RETRY_INTERVAL, false);

        return false;
    }

    public function add(array $record)
    {
        global $wpdb;

        $args = wp_parse_args($record, array(
            'object_type' => '',
            'object_id' => 0,
            'url_hash' => '',
            'outcome' => '',
            'reason' => '',
            'duration_ms' => 0,
            'object_count' => 0,
            'created_at' => current_time('mysql'),
        ));

        $row = array(
            'object_type' => sanitize_key($args['object_type']),
            'object_id' => absint($args['object_id']),
            'url_hash' => preg_match('/^[a-f0-9]{64}$/', (string) $args['url_hash']) ? $args['url_hash'] : '',
            'outcome' => sanitize_key($args['outcome']),
            'reason' => sanitize_key($args['reason']),
            'duration_ms' => max(0, (int) $args['duration_ms']),
            'object_count' => max(0, (int) $args['object_count']),
            'created_at' => $args['created_at'],
        );

        $result = $wpdb->insert($this->table_name(), $row);

        if ($result === false && !self::$table_verified) {
            # The only expected cause is a table that migration never created,
            # on a site upgraded without the activation hook running. Pay the
            # existence check once, then let every later insert stay cheap.
            if ($this->maybe_create_table()) {
                $result = $wpdb->insert($this->table_name(), $row);
            }
        }

        if ($result === false) {
            return false;
        }

        $this->maybe_cleanup();

        return $result;
    }

    /**
     * Prune at most once per hour, claimed atomically so concurrent page views
     * cannot all prune at the same time.
     *
     * @return bool Whether pruning ran.
     */
    public function maybe_cleanup()
    {
        $now  = time();
        $last = (int) get_option(self::CLEANUP_OPTION, 0);

        if ($last > 0 && ($now - $last) < self::CLEANUP_INTERVAL) {
            return false;
        }

        $lock = (int) get_option(self::CLEANUP_LOCK_OPTION, 0);
        if ($lock > 0 && $lock > ($now - self::CLEANUP_INTERVAL)) {
            return false;
        }

        if ($lock > 0) {
            global $wpdb;
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) = %d",
                (string) $now,
                self::CLEANUP_LOCK_OPTION,
                $lock
            ));
            if ($claimed !== 1) {
                return false;
            }
        } elseif (!add_option(self::CLEANUP_LOCK_OPTION, $now, '', false)) {
            return false;
        }

        try {
            $this->cleanup();
            update_option(self::CLEANUP_OPTION, $now, false);
        } catch (Throwable $e) {
            return false;
        }

        return true;
    }

    public function cleanup()
    {
        global $wpdb;
        $table = $this->table_name();

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS)
        ));

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} ORDER BY id DESC LIMIT %d",
            self::MAX_RECORDS
        ));

        if (count($ids) < self::MAX_RECORDS) {
            return;
        }

        $ids = array_map('absint', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE id NOT IN ({$placeholders})",
            $ids
        ));
    }

    public static function record($type, $id, $outcome, $reason = '', $duration_ms = 0, $object_count = 0, $url = '')
    {
        try {
            $history = new self();
            return $history->add(array(
                'object_type' => $type,
                'object_id' => $id,
                'url_hash' => $url === '' ? '' : hash('sha256', strtolower(trim((string) $url))),
                'outcome' => $outcome,
                'reason' => $reason,
                'duration_ms' => $duration_ms,
                'object_count' => $object_count,
            ));
        } catch (Throwable $e) {
            return false;
        }
    }
}

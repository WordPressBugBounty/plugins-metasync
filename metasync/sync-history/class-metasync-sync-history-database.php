<?php

/**
 * The database operations for the sync history monitor.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/sync-history
 * @author     Engineering Team <support@searchatlas.com>
 */
class Metasync_Sync_History_Database
{
	public static $table_name = "metasync_sync_history";

	private static $structure_verified = false;

	private function get_table_name()
	{
		global $wpdb;
		return $wpdb->prefix . self::$table_name;
	}

	/**
	 * Check if the table exists and create it if it doesn't
	 */
	public function maybe_create_table()
	{
		// Run schema inspection at most once per request — table schema only
		// changes on activation/upgrade, handled by class-db-migrations.php.
		if (self::$structure_verified) { return; }
		self::$structure_verified = true;

		global $wpdb;
		$table_name = $this->get_table_name();
		
		// Check if table exists
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- lazy table-creation backstop, once per request via the $structure_verified gate; primary creation runs in the upgrade routine
			$this->create_table();
		}
	}

	/**
	 * Static method to ensure table exists - can be called without instantiating the class
	 */
	public static function ensure_table_exists()
	{
		$instance = new self();
		$instance->maybe_create_table();
	}

	/**
	 * Create the sync history table
	 */
	private function create_table()
	{
		global $wpdb;
		$table_name = $this->get_table_name();
		$collate = $wpdb->get_charset_collate();
		
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL DEFAULT '',
			source VARCHAR(50) NOT NULL DEFAULT '',
			status VARCHAR(25) NOT NULL DEFAULT 'draft',
			content_type VARCHAR(50) NOT NULL DEFAULT '',
			url TEXT NULL,
			meta_data TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY id (id),
			KEY source (source),
			KEY status (status),
			KEY created_at (created_at),
			KEY idx_dedup (source, created_at),
			KEY idx_search (title(50), source, created_at)
		) $collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		
		$result = dbDelta($sql);
		
		// Log result for debugging
		if (defined('WP_DEBUG') && WP_DEBUG) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r -- debug-gated, no secrets
			error_log("Metasync Sync History Table Creation Result: " . print_r($result, true));
		}
		
		// Verify table was created
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- post-create verification inside the lazy table-creation backstop, once per request
			error_log("Failed to create metasync_sync_history table: " . $wpdb->last_error); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.DB.DirectDatabaseQuery -- genuine failure path, bounded, no secrets; error_log() message text is not SQL (the word "create" trips DirectDatabaseQuery)
		}
	}

	public function getAllRecords($limit = 30, $offset = 0, $filters = [])
	{
		// Ensure table exists
		$this->maybe_create_table();

		global $wpdb;

		$where_conditions = [];
		$where_values = [];

		// Apply filters
		if (!empty($filters['source'])) {
			$where_conditions[] = "source = %s";
			$where_values[] = $filters['source'];
		}

		// Handle status filter - match both 'publish' and 'published' for published status
		if (!empty($filters['status'])) {
			if ($filters['status'] === 'published') {
				$where_conditions[] = "(status = %s OR status = %s)";
				$where_values[] = 'published';
				$where_values[] = 'publish';
			} else {
				$where_conditions[] = "status = %s";
				$where_values[] = $filters['status'];
			}
		}
		
		if (!empty($filters['date_from'])) {
			$where_conditions[] = "created_at >= %s";
			$where_values[] = $filters['date_from'];
		}
		
		if (!empty($filters['date_to'])) {
			$where_conditions[] = "created_at <= %s";
			$where_values[] = $filters['date_to'];
		}

		# PERFORMANCE OPTIMIZATION: Select specific columns instead of *
		# Reduces data transfer and memory usage by 20-30%
		// Query assembled from literal placeholder fragments only; values bind via prepare().
		$query = "SELECT id, title, source, status, content_type, url, created_at FROM `{$wpdb->prefix}metasync_sync_history`"
			. (!empty($where_conditions) ? ' WHERE ' . implode(' AND ', $where_conditions) : '')
			. ' ORDER BY created_at DESC LIMIT %d OFFSET %d';
		$where_values[] = $limit;
		$where_values[] = $offset;

		return $wpdb->get_results($wpdb->prepare($query, $where_values)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- literal fragments only, values bound via prepare()
	}

	/**
	 * Add a sync history record.
	 * @param array $args Values to insert.
	 */
	public function add($args)
	{
		// Ensure table exists
		$this->maybe_create_table();

		global $wpdb;

		$args = wp_parse_args(
			$args,
			[
				'title'         => '',
				'source'        => '',
				'status'        => 'draft',
				'content_type'  => '',
				'url'           => '',
				'meta_data'     => '',
				'created_at'    => current_time('mysql'),
			]
		);

		// Maybe delete logs if record exceed defined limit.
		$limit = 1000;
		if ($limit && $this->get_count() >= $limit) {
			$this->cleanup_old_records();
		}

		return $wpdb->insert($this->get_table_name(), $args); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository — no WordPress API exists for plugin tables; reads feed admin/cron views needing fresh rows
	}

	/**
	 * Get total number of sync history items.
	 * @return int
	 */
	public function get_count($filters = [])
	{
		// Ensure table exists
		$this->maybe_create_table();

		global $wpdb;

		$where_conditions = [];
		$where_values = [];

		// Apply filters
		if (!empty($filters['source'])) {
			$where_conditions[] = "source = %s";
			$where_values[] = $filters['source'];
		}

		// Handle status filter - match both 'publish' and 'published' for published status
		if (!empty($filters['status'])) {
			if ($filters['status'] === 'published') {
				$where_conditions[] = "(status = %s OR status = %s)";
				$where_values[] = 'published';
				$where_values[] = 'publish';
			} else {
				$where_conditions[] = "status = %s";
				$where_values[] = $filters['status'];
			}
		}

		if (!empty($filters['date_from'])) {
			$where_conditions[] = "created_at >= %s";
			$where_values[] = $filters['date_from'];
		}

		if (!empty($filters['date_to'])) {
			$where_conditions[] = "created_at <= %s";
			$where_values[] = $filters['date_to'];
		}

		// Query assembled from literal placeholder fragments only; values bind via prepare().
		$query = "SELECT COUNT(*) FROM `{$wpdb->prefix}metasync_sync_history`"
			. (!empty($where_conditions) ? ' WHERE ' . implode(' AND ', $where_conditions) : '');

		if (!empty($where_values)) {
			return (int) $wpdb->get_var($wpdb->prepare($query, $where_values)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- literal fragments only, values bound via prepare()
		}
		return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}metasync_sync_history`"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository — no WordPress API exists for plugin tables; reads feed admin/cron views needing fresh rows
	}

	/**
	 * Get a single record by ID.
	 * @param int $id
	 * @return object|null
	 */
	public function get_by_id($id)
	{
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository — no WordPress API exists for plugin tables; reads feed admin/cron views needing fresh rows
			"SELECT * FROM `{$wpdb->prefix}metasync_sync_history` WHERE id = %d LIMIT 1",
			intval($id)
		));
	}

	/**
	 * Delete records older than a given number of days.
	 * @param int $days
	 * @return int Number of rows deleted
	 */
	public function delete_older_than_days($days = 90)
	{
		global $wpdb;
		return (int) $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository — no WordPress API exists for plugin tables; reads feed admin/cron views needing fresh rows
			"DELETE FROM `{$wpdb->prefix}metasync_sync_history` WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			intval($days)
		));
	}

	/**
	 * Delete specific records.
	 * @param array $items
	 * @return int
	 */
	public function delete($items)
	{
		global $wpdb;
		if (!is_array($items) || empty($items)) return 0;
		return $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository — no WordPress API exists for plugin tables; reads feed admin/cron views needing fresh rows
			'DELETE FROM `' . $wpdb->prefix . 'metasync_sync_history` WHERE `id` IN (' . implode(',', array_fill(0, count($items), '%d')) . ')',
			$items
		));
	}

	/**
	 * Clear all sync history records.
	 */
	public function clear_logs()
	{
		global $wpdb;
		$wpdb->query("TRUNCATE TABLE {$wpdb->prefix}metasync_sync_history");
	}
	
	/**
	 * Clean up old records, keeping only the most recent ones.
	 *
	 * Uses a two-step PHP fetch-then-delete approach instead of a nested
	 * subquery DELETE. On MySQL 5.x the correlated subquery pattern caused
	 * the inner SELECT to be re-evaluated per candidate row, holding table
	 * locks during active syncing.
	 */
	public function cleanup_old_records($keep_count = 500)
	{
		global $wpdb;

		$ids = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository — no WordPress API exists for plugin tables; reads feed admin/cron views needing fresh rows
			"SELECT id FROM `{$wpdb->prefix}metasync_sync_history` ORDER BY created_at DESC LIMIT %d",
			$keep_count
		));

		if (empty($ids)) {
			return;
		}

		$ids = array_map('intval', $ids);

		// Nothing to prune — table has fewer rows than the retention limit.
		if (count($ids) < $keep_count) {
			return;
		}

		$wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository — no WordPress API exists for plugin tables; reads feed admin/cron views needing fresh rows
			'DELETE FROM `' . $wpdb->prefix . 'metasync_sync_history` WHERE id NOT IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')',
			$ids
		));
	}
	
	/**
	 * Get sync statistics.
	 */
	public function get_statistics()
	{
		global $wpdb;

		// Count both 'publish' and 'published' for published_count (handles legacy data)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository — no WordPress API exists for plugin tables; reads feed admin/cron views needing fresh rows
		$stats = $wpdb->get_row("
			SELECT
				COUNT(*) as total_records,
				SUM(CASE WHEN status IN ('published', 'publish') THEN 1 ELSE 0 END) as published_count,
				SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count,
				SUM(CASE WHEN source = 'OTTO SEO' THEN 1 ELSE 0 END) as otto_count,
				SUM(CASE WHEN source = 'Content Genius' THEN 1 ELSE 0 END) as content_genius_count
			FROM `{$wpdb->prefix}metasync_sync_history`
		");
		
		return $stats;
	}
}

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
		global $wpdb;
		$table_name = $this->get_table_name();
		
		// Check if table exists
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
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
			KEY created_at (created_at)
		) $collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		
		$result = dbDelta($sql);
		
		// Log result for debugging
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log("Metasync Sync History Table Creation Result: " . print_r($result, true));
		}
		
		// Verify table was created
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
			error_log("Failed to create metasync_sync_history table: " . $wpdb->last_error);
		}
	}

	public function getAllRecords($limit = 30, $offset = 0, $filters = [])
	{
		// Ensure table exists
		$this->maybe_create_table();
		
		global $wpdb;
		$tableName = $this->get_table_name();
		
		$where_conditions = [];
		$where_values = [];
		
		// Apply filters
		if (!empty($filters['source'])) {
			$where_conditions[] = "source = %s";
			$where_values[] = $filters['source'];
		}
		
		if (!empty($filters['status'])) {
			$where_conditions[] = "status = %s";
			$where_values[] = $filters['status'];
		}
		
		if (!empty($filters['date_from'])) {
			$where_conditions[] = "created_at >= %s";
			$where_values[] = $filters['date_from'];
		}
		
		if (!empty($filters['date_to'])) {
			$where_conditions[] = "created_at <= %s";
			$where_values[] = $filters['date_to'];
		}
		
		$where_clause = '';
		if (!empty($where_conditions)) {
			$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
		}
		
		$query = "SELECT * FROM `$tableName` $where_clause ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$where_values[] = $limit;
		$where_values[] = $offset;
		
		if (!empty($where_values)) {
			return $wpdb->get_results($wpdb->prepare($query, $where_values));
		} else {
			return $wpdb->get_results($wpdb->prepare($query, $limit, $offset));
		}
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

		return $wpdb->insert($this->get_table_name(), $args);
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
		$tableName = $this->get_table_name();
		
		$where_conditions = [];
		$where_values = [];
		
		// Apply filters
		if (!empty($filters['source'])) {
			$where_conditions[] = "source = %s";
			$where_values[] = $filters['source'];
		}
		
		if (!empty($filters['status'])) {
			$where_conditions[] = "status = %s";
			$where_values[] = $filters['status'];
		}
		
		if (!empty($filters['date_from'])) {
			$where_conditions[] = "created_at >= %s";
			$where_values[] = $filters['date_from'];
		}
		
		if (!empty($filters['date_to'])) {
			$where_conditions[] = "created_at <= %s";
			$where_values[] = $filters['date_to'];
		}
		
		$where_clause = '';
		if (!empty($where_conditions)) {
			$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
		}
		
		$query = "SELECT COUNT(*) FROM `$tableName` $where_clause";
		
		if (!empty($where_values)) {
			return (int) $wpdb->get_var($wpdb->prepare($query, $where_values));
		} else {
			return (int) $wpdb->get_var($query);
		}
	}

	/**
	 * Delete specific records.
	 * @param array $items
	 * @return int
	 */
	public function delete($items)
	{
		global $wpdb;
		$tableName = $this->get_table_name();
		if (!is_array($items) || empty($items)) return 0;
		$ids = implode(',', array_fill(0, count($items), '%d'));
		return $wpdb->query($wpdb->prepare(
			" 
			DELETE FROM `$tableName`
			WHERE `id` IN ($ids) ",
			$items
		));
	}

	/**
	 * Clear all sync history records.
	 */
	public function clear_logs()
	{
		global $wpdb;
		$tableName = $this->get_table_name();
		$wpdb->query("TRUNCATE TABLE {$tableName}");
	}
	
	/**
	 * Clean up old records, keeping only the most recent ones.
	 */
	public function cleanup_old_records($keep_count = 500)
	{
		global $wpdb;
		$tableName = $this->get_table_name();
		
		$wpdb->query($wpdb->prepare(
			"DELETE FROM `$tableName` 
			WHERE id NOT IN (
				SELECT id FROM (
					SELECT id FROM `$tableName` 
					ORDER BY created_at DESC 
					LIMIT %d
				) as keep_records
			)",
			$keep_count
		));
	}
	
	/**
	 * Get sync statistics.
	 */
	public function get_statistics()
	{
		global $wpdb;
		$tableName = $this->get_table_name();
		
		$stats = $wpdb->get_row("
			SELECT 
				COUNT(*) as total_records,
				SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published_count,
				SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count,
				SUM(CASE WHEN source = 'OTTO SEO' THEN 1 ELSE 0 END) as otto_count,
				SUM(CASE WHEN source = 'Content Genius' THEN 1 ELSE 0 END) as content_genius_count
			FROM `$tableName`
		");
		
		return $stats;
	}
}

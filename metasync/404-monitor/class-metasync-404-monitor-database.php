<?php

/**
 * The database operations for the 404 error monitor.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/404-monitor
 * @author     Engineering Team <support@searchatlas.com>
 */
class Metasync_Error_Monitor_Database
{
	public static $table_name = "metasync_404_logs";

	private function get_table_name()
	{
		global $wpdb;
		return $wpdb->prefix . self::$table_name;
	}

	public function getAllRecords()
	{
		global $wpdb;
		$tableName = $this->get_table_name();
		# PERFORMANCE OPTIMIZATION: Select specific columns instead of *
		return $wpdb->get_results(" SELECT id, uri, date_time, hits_count, user_agent FROM `$tableName` ORDER BY hits_count DESC, date_time DESC ");
	}

	/**
	 * Search 404 errors with filters
	 * @param array $filters Search filters
	 */
	public function search_404_errors($filters = [])
	{
		global $wpdb;
		$tableName = $this->get_table_name();
		
		$where_conditions = ['1=1'];
		$where_values = [];
		
		if (!empty($filters['search'])) {
			$where_conditions[] = "(uri LIKE %s OR user_agent LIKE %s)";
			$search_term = '%' . $wpdb->esc_like($filters['search']) . '%';
			$where_values[] = $search_term;
			$where_values[] = $search_term;
		}
		
		if (!empty($filters['date_from'])) {
			$where_conditions[] = "date_time >= %s";
			$where_values[] = $filters['date_from'];
		}
		
		if (!empty($filters['date_to'])) {
			$where_conditions[] = "date_time <= %s";
			$where_values[] = $filters['date_to'];
		}
		
		if (!empty($filters['min_hits'])) {
			$where_conditions[] = "hits_count >= %d";
			$where_values[] = intval($filters['min_hits']);
		}
		
		$where_clause = implode(' AND ', $where_conditions);
		$order_by = !empty($filters['order_by']) ? sanitize_sql_orderby($filters['order_by']) : 'hits_count';
		$order = !empty($filters['order']) && strtoupper($filters['order']) === 'ASC' ? 'ASC' : 'DESC';
		
		// Add pagination support
		$limit_clause = '';
		if (isset($filters['per_page']) && isset($filters['offset'])) {
			$limit_clause = " LIMIT %d OFFSET %d";
			$where_values[] = intval($filters['per_page']);
			$where_values[] = intval($filters['offset']);
		}

		# PERFORMANCE OPTIMIZATION: Select specific columns instead of *
		$query = "SELECT id, uri, date_time, hits_count, user_agent FROM `$tableName` WHERE $where_clause ORDER BY $order_by $order" . $limit_clause;
		
		if (!empty($where_values)) {
			return $wpdb->get_results($wpdb->prepare($query, $where_values));
		} else {
			return $wpdb->get_results($query);
		}
	}

	/**
	 * Count total 404 errors with filters (for pagination)
	 * @param array $filters Search filters
	 */
	public function count_404_errors($filters = [])
	{
		global $wpdb;
		$tableName = $this->get_table_name();
		
		$where_conditions = ['1=1'];
		$where_values = [];
		
		if (!empty($filters['search'])) {
			$where_conditions[] = "(uri LIKE %s OR user_agent LIKE %s)";
			$search_term = '%' . $wpdb->esc_like($filters['search']) . '%';
			$where_values[] = $search_term;
			$where_values[] = $search_term;
		}
		
		if (!empty($filters['date_from'])) {
			$where_conditions[] = "date_time >= %s";
			$where_values[] = $filters['date_from'];
		}
		
		if (!empty($filters['date_to'])) {
			$where_conditions[] = "date_time <= %s";
			$where_values[] = $filters['date_to'];
		}
		
		if (!empty($filters['min_hits'])) {
			$where_conditions[] = "hits_count >= %d";
			$where_values[] = intval($filters['min_hits']);
		}
		
		$where_clause = implode(' AND ', $where_conditions);
		$query = "SELECT COUNT(*) FROM `$tableName` WHERE $where_clause";
		
		if (!empty($where_values)) {
			return $wpdb->get_var($wpdb->prepare($query, $where_values));
		} else {
			return $wpdb->get_var($query);
		}
	}

	/**
	 * Get 404 error statistics
	 */
	public function get_404_statistics()
	{
		global $wpdb;
		$tableName = $this->get_table_name();
		
		$stats = [];
		
		// Total 404 errors
		$stats['total_errors'] = $wpdb->get_var("SELECT COUNT(*) FROM `$tableName`");
		
		// Total hits
		$stats['total_hits'] = $wpdb->get_var("SELECT SUM(hits_count) FROM `$tableName`");
		
		// Most frequent errors
		$stats['most_frequent'] = $wpdb->get_results("
			SELECT uri, hits_count, date_time 
			FROM `$tableName` 
			ORDER BY hits_count DESC 
			LIMIT 5
		");
		
		// Recent errors (last 24 hours)
		$stats['recent_errors'] = $wpdb->get_var($wpdb->prepare("
			SELECT COUNT(*) 
			FROM `$tableName` 
			WHERE date_time >= %s
		", date('Y-m-d H:i:s', strtotime('-24 hours'))));
		
		// Errors by day (last 7 days)
		$stats['errors_by_day'] = $wpdb->get_results("
			SELECT DATE(date_time) as date, COUNT(*) as count, SUM(hits_count) as hits
			FROM `$tableName` 
			WHERE date_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
			GROUP BY DATE(date_time)
			ORDER BY date DESC
		");
		
		return $stats;
	}

	/**
	 * Add a record.
	 * @param array $args Values to insert.
	 */
	public function add($args)
	{
		global $wpdb;

		$args = wp_parse_args(
			$args,
			[
				'uri'        => '',
				'date_time'  => current_time('mysql'),
				'hits_count' => '1',
				'user_agent' => '',
			]
		);
		//Maybe delete logs if record exceed defined limit.
		$limit = 100;
		if ($limit && $this->get_count() >= $limit) {
			$this->clear_logs();
		}

		return $wpdb->insert($this->get_table_name(), $args);
	}

	/**
	 * Update a record.
	 * Optimized with in-memory cache to reduce database queries
	 * @param array $args Values to update.
	 */
	public function update($args)
	{
		// In-memory cache to avoid repeated DB queries for same URL in single request
		static $lookup_cache = [];

		$uri = $args['uri'];
		$cache_key = md5($uri);

		// Check in-memory cache first
		if (isset($lookup_cache[$cache_key])) {
			if ($lookup_cache[$cache_key] === false) {
				// Previously checked - doesn't exist, add new
				return $this->add($args);
			} else {
				// Previously found - update counter
				return $this->update_counter($lookup_cache[$cache_key]);
			}
		}

		// Try to find existing record with exact match first
		$row = $this->findByUri($uri);
		if ($row) {
			$lookup_cache[$cache_key] = $row;
			return $this->update_counter($row);
		}

		// If not found, try with URL-decoded version (only if different)
		$decoded_uri = urldecode($uri);
		if ($decoded_uri !== $uri) {
			$row = $this->findByUri($decoded_uri);
			if ($row) {
				$lookup_cache[$cache_key] = $row;
				return $this->update_counter($row);
			}
		}

		// If still not found, try with URL-encoded version (only if different)
		$encoded_uri = urlencode($uri);
		if ($encoded_uri !== $uri) {
			$row = $this->findByUri($encoded_uri);
			if ($row) {
				$lookup_cache[$cache_key] = $row;
				return $this->update_counter($row);
			}
		}

		// Cache the "not found" result to avoid repeated lookups
		$lookup_cache[$cache_key] = false;

		// If no existing record found, add new one
		return $this->add($args);
	}

	/**
	 * Get total number of log items (number of rows in the DB table).
	 * @return int
	 */
	public function get_count()
	{
		return count($this->getAllRecords());
	}

	/**
	 * Clear logs completely.
	 * @param array $itemsArray
	 * @return int
	 */
	public function delete($items)
	{
		global $wpdb;
		$tableName = $this->get_table_name();
		if (!is_array($items) || empty($items)) return;
		$ids = implode(',', array_fill(0, count($items), '%d'));
		$wpdb->query($wpdb->prepare(
			" 
			DELETE FROM `$tableName`
			WHERE `id` IN ($ids) ",
			$items
		));
	}

	/**
	 * Clear logs completely.
	 */
	public function clear_logs()
	{
		global $wpdb;
		$tableName = $this->get_table_name();
		$wpdb->query("TRUNCATE TABLE {$tableName}");
	}

	/**
	 * Update if URL is matched and hit.
	 * @param object $row Record to update.
	 */
	private function update_counter($row)
	{
		global $wpdb;
		$update_data = [
			'date_time'  => current_time('mysql'),
			'hits_count' => absint($row->hits_count) + 1,
		];
		$wpdb->update($this->get_table_name(), $update_data, ['id' => $row->id]);
	}

	/**
	 * Update if URL is matched and hit.
	 * @param $value Record to update.
	 */
	public function findByUri($value)
	{
		global $wpdb;
		$tableName = $this->get_table_name();
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM `$tableName` WHERE `uri` = %s ", $value));
	}
}

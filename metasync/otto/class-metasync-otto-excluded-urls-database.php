<?php

/**
 * The database operations for OTTO excluded URLs.
 *
 * @since      2.6.0
 * @package    Metasync
 * @subpackage Metasync/otto
 * @author     Engineering Team <support@searchatlas.com>
 */
class Metasync_Otto_Excluded_URLs_Database
{
	public static $table_name = "metasync_otto_excluded_urls";

	private function get_table_name()
	{
		global $wpdb;
		return $wpdb->prefix . self::$table_name;
	}

	/**
	 * Ensure table structure is up to date
	 */
	private function ensure_table_structure()
	{
		global $wpdb;
		$table_name = $this->get_table_name();

		// Check if table exists
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
			// Table doesn't exist, run full migration
			require_once dirname(__FILE__, 2) . '/database/class-db-migrations.php';
			MetaSync_DBMigration::activation();
			return;
		}
	}

	/**
	 * Get all excluded URLs with pagination
	 *
	 * @param int $per_page Number of items per page
	 * @param int $page_number Current page number
	 * @return array Array of excluded URL records
	 */
	public function get_paginated_records($per_page = 10, $page_number = 1)
	{
		global $wpdb;

		// Ensure table exists before querying
		$this->ensure_table_structure();

		$table_name = $this->get_table_name();

		$offset = ($page_number - 1) * $per_page;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `$table_name` ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		return $results;
	}

	/**
	 * Get total count of excluded URLs
	 *
	 * @return int Total count
	 */
	public function get_total_count()
	{
		global $wpdb;

		// Ensure table exists before querying
		$this->ensure_table_structure();

		$table_name = $this->get_table_name();

		$count = $wpdb->get_var("SELECT COUNT(*) FROM `$table_name`");

		return intval($count);
	}

	/**
	 * Get all active excluded URLs (for checking during OTTO execution)
	 * Uses caching for performance
	 *
	 * @return array Array of excluded URL patterns
	 */
	public function get_all_active_urls()
	{
		global $wpdb;
		$table_name = $this->get_table_name();

		// Check cache first
		$cache_key = 'metasync_otto_excluded_urls';
		$cached_urls = wp_cache_get($cache_key, 'metasync');

		if ($cached_urls !== false) {
			return $cached_urls;
		}

		// Ensure table exists before querying
		$this->ensure_table_structure();

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT url_pattern, pattern_type FROM `$table_name` WHERE status = %s ORDER BY created_at DESC",
				'active'
			)
		);

		// Cache for 1 hour
		wp_cache_set($cache_key, $results, 'metasync', HOUR_IN_SECONDS);

		return $results;
	}

	/**
	 * Check if a URL matches any excluded URL patterns
	 *
	 * @param string $url URL to check
	 * @return bool True if URL is excluded, false otherwise
	 */
	public function is_url_excluded($url)
	{
		$excluded_urls = $this->get_all_active_urls();

		if (empty($excluded_urls)) {
			return false;
		}

		// Normalize URL for comparison
		$url = trim($url);
		$url = rtrim($url, '/');

		foreach ($excluded_urls as $excluded) {
			$pattern = trim($excluded->url_pattern);
			$pattern = rtrim($pattern, '/');
			$pattern_type = $excluded->pattern_type;

			switch ($pattern_type) {
				case 'exact':
					if ($url === $pattern) {
						return true;
					}
					break;

				case 'contain':
					if (strpos($url, $pattern) !== false) {
						return true;
					}
					break;

				case 'start':
					if (strpos($url, $pattern) === 0) {
						return true;
					}
					break;

				case 'end':
					if (substr($url, -strlen($pattern)) === $pattern) {
						return true;
					}
					break;

				case 'regex':
					if (@preg_match($pattern, $url)) {
						return true;
					}
					break;
			}
		}

		return false;
	}

	/**
	 * Add a new excluded URL
	 *
	 * @param array $args URL data to insert
	 * @return bool|string True on success, false on failure, 'duplicate' if already exists, 'reactivated' if inactive entry was reactivated
	 */
	public function add($args)
	{
		global $wpdb;

		// Ensure table structure is up to date
		$this->ensure_table_structure();

		$args = wp_parse_args(
			$args,
			[
				'url_pattern'    => '',
				'pattern_type'   => 'exact',
				'description'    => '',
				'status'         => 'active',
				'created_at'     => current_time('mysql'),
			]
		);

		// Validate URL pattern
		if (empty($args['url_pattern'])) {
			return false;
		}

		// Normalize URL pattern for comparison (trim and remove trailing slash)
		$normalized_pattern = rtrim(trim($args['url_pattern']), '/');

		// Check for duplicate URL pattern with same type
		$table_name = $this->get_table_name();
		$existing = $wpdb->get_row($wpdb->prepare(
			"SELECT id, status FROM `$table_name` WHERE TRIM(TRAILING '/' FROM url_pattern) = %s AND pattern_type = %s",
			$normalized_pattern,
			$args['pattern_type']
		));

		if ($existing) {

			// If existing entry is inactive, reactivate it instead of creating duplicate
			if ($existing->status === 'inactive') {
				$wpdb->update(
					$table_name,
					['status' => 'active', 'description' => $args['description']],
					['id' => $existing->id]
				);
				$this->clear_cache();
				return 'reactivated';
			}

			return 'duplicate';
		}

		// Validate pattern type
		$valid_types = ['exact', 'contain', 'start', 'end', 'regex'];
		if (!in_array($args['pattern_type'], $valid_types)) {
			$args['pattern_type'] = 'exact';
		}

		// Validate regex pattern if type is regex
		if ($args['pattern_type'] === 'regex') {
			if (@preg_match($args['url_pattern'], '') === false) {
				return false; // Invalid regex pattern
			}
		}

		$result = $wpdb->insert($this->get_table_name(), $args);

		// Clear cache after adding
		$this->clear_cache();

		return $result !== false;
	}

	/**
	 * Update an excluded URL record
	 *
	 * @param array $args Values to update
	 * @param int $id Record ID
	 * @return bool True on success, false on failure
	 */
	public function update($args, $id)
	{
		global $wpdb;

		// Ensure table structure is up to date
		$this->ensure_table_structure();

		$table_name = $this->get_table_name();
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table_name` WHERE `id` = %d", $id));

		if (!$row) {
			return false;
		}

		// Validate pattern type if provided
		if (isset($args['pattern_type'])) {
			$valid_types = ['exact', 'contain', 'start', 'end', 'regex'];
			if (!in_array($args['pattern_type'], $valid_types)) {
				$args['pattern_type'] = 'exact';
			}
		}

		// Validate regex pattern if type is regex
		if (isset($args['pattern_type']) && $args['pattern_type'] === 'regex') {
			if (isset($args['url_pattern']) && @preg_match($args['url_pattern'], '') === false) {
				return false; // Invalid regex pattern
			}
		}

		$result = $wpdb->update($table_name, $args, ['id' => $id]);

		// Clear cache after updating
		$this->clear_cache();

		return $result !== false;
	}

	/**
	 * Delete excluded URL records
	 *
	 * @param array $items Array of record IDs to delete
	 * @return bool True on success, false on failure
	 */
	public function delete($items)
	{
		global $wpdb;

		// Ensure table exists before querying
		$this->ensure_table_structure();

		$table_name = $this->get_table_name();

		if (!is_array($items) || empty($items)) {
			return false;
		}

		// Sanitize IDs
		$items = array_map('intval', $items);
		$ids = implode(',', array_fill(0, count($items), '%d'));

		$result = $wpdb->query($wpdb->prepare(
			"DELETE FROM `$table_name` WHERE `id` IN ($ids)",
			$items
		));

		// Clear cache after deleting
		$this->clear_cache();

		return $result !== false;
	}

	/**
	 * Update status of excluded URL records
	 *
	 * @param array $items Array of record IDs
	 * @param string $status New status (active/inactive)
	 * @return bool True on success, false on failure
	 */
	public function update_status($items, $status)
	{
		global $wpdb;

		// Ensure table exists before querying
		$this->ensure_table_structure();

		$table_name = $this->get_table_name();

		if (!is_array($items) || empty($items)) {
			return false;
		}

		// Validate status
		if (!in_array($status, ['active', 'inactive'])) {
			return false;
		}

		// Sanitize IDs
		$items = array_map('intval', $items);
		$ids = implode(', ', array_fill(0, count($items), '%d'));

		$set_status = $wpdb->prepare(
			"UPDATE `$table_name` SET `status` = %s",
			$status
		);
		$where = $wpdb->prepare(
			" WHERE `id` IN ( $ids )",
			$items
		);

		$query = "{$set_status}{$where}";
		$result = $wpdb->query($query);

		// Clear cache after updating status
		$this->clear_cache();

		return $result !== false;
	}

	/**
	 * Clear excluded URLs cache
	 */
	public function clear_cache()
	{
		wp_cache_delete('metasync_otto_excluded_urls', 'metasync');
	}

	/**
	 * Search excluded URLs with filters
	 *
	 * @param array $filters Search filters
	 * @return array Array of matching records
	 */
	public function search($filters = [])
	{
		global $wpdb;

		// Ensure table exists before querying
		$this->ensure_table_structure();

		$table_name = $this->get_table_name();

		$where_conditions = ['1=1'];
		$where_values = [];

		if (!empty($filters['search'])) {
			$where_conditions[] = "(url_pattern LIKE %s OR description LIKE %s)";
			$search_term = '%' . $wpdb->esc_like($filters['search']) . '%';
			$where_values[] = $search_term;
			$where_values[] = $search_term;
		}

		if (!empty($filters['status'])) {
			$where_conditions[] = "status = %s";
			$where_values[] = $filters['status'];
		}

		if (!empty($filters['pattern_type'])) {
			$where_conditions[] = "pattern_type = %s";
			$where_values[] = $filters['pattern_type'];
		}

		$where_clause = implode(' AND ', $where_conditions);
		$order_by = !empty($filters['order_by']) ? sanitize_sql_orderby($filters['order_by']) : 'created_at';
		$order = !empty($filters['order']) && strtoupper($filters['order']) === 'ASC' ? 'ASC' : 'DESC';

		// Add pagination support
		$limit_clause = '';
		if (isset($filters['per_page']) && isset($filters['offset'])) {
			$limit_clause = " LIMIT %d OFFSET %d";
			$where_values[] = intval($filters['per_page']);
			$where_values[] = intval($filters['offset']);
		}

		$query = "SELECT * FROM `$table_name` WHERE $where_clause ORDER BY $order_by $order" . $limit_clause;

		if (!empty($where_values)) {
			return $wpdb->get_results($wpdb->prepare($query, $where_values));
		} else {
			return $wpdb->get_results($query);
		}
	}
}

<?php

/**
 * The database operations for the redirections.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/redirections
 * @author     Engineering Team <support@searchatlas.com>
 */
class Metasync_Redirection_Database
{
	public static $table_name = "metasync_redirections";

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
		
		// Check if required columns exist
		$columns = $wpdb->get_col("DESCRIBE {$table_name}");
		
		$required_columns = [
			'pattern_type' => "ALTER TABLE {$table_name} ADD COLUMN pattern_type ENUM('exact', 'contain', 'start', 'end', 'regex') NOT NULL DEFAULT 'exact' AFTER status",
			'regex_pattern' => "ALTER TABLE {$table_name} ADD COLUMN regex_pattern TEXT NULL AFTER pattern_type",
			'description' => "ALTER TABLE {$table_name} ADD COLUMN description TEXT NULL AFTER regex_pattern",
			'created_at' => "ALTER TABLE {$table_name} ADD COLUMN created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER description",
			'updated_at' => "ALTER TABLE {$table_name} ADD COLUMN updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER created_at",
			'last_accessed_at' => "ALTER TABLE {$table_name} ADD COLUMN last_accessed_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER updated_at"
		];
		
		foreach ($required_columns as $column => $sql) {
			if (!in_array($column, $columns)) {
				$wpdb->query($sql);
			}
		}
		
		// Check and add indexes
		$indexes = $wpdb->get_results("SHOW INDEX FROM {$table_name}");
		$index_names = array_column($indexes, 'Key_name');

		$required_indexes = [
			'pattern_type' => "ALTER TABLE {$table_name} ADD KEY pattern_type (pattern_type)",
			'created_at' => "ALTER TABLE {$table_name} ADD KEY created_at (created_at)",
			'status_created' => "ALTER TABLE {$table_name} ADD KEY status_created (status, created_at)"
		];
		
		foreach ($required_indexes as $index => $sql) {
			if (!in_array($index, $index_names)) {
				$wpdb->query($sql);
			}
		}
		
		// Set default pattern_type for existing records
		$wpdb->query("UPDATE {$table_name} SET pattern_type = 'exact' WHERE pattern_type IS NULL OR pattern_type = ''");
	}

	/**
	 * Manually trigger table structure update
	 * Can be called from admin or via AJAX if needed
	 */
	public function force_table_update()
	{
		$this->ensure_table_structure();
		return true;
	}

	public function getAllRecords()
	{
		global $wpdb;
		$tableName = $this->get_table_name();
		return $wpdb->get_results(" SELECT * FROM `$tableName` ");
	}

	public function getAllActiveRecords()
	{
		global $wpdb;
		$tableName = $this->get_table_name();

		// Check cache first
		$cache_key = 'metasync_active_redirections';
		$cached_redirections = wp_cache_get($cache_key, 'metasync');

		if ($cached_redirections !== false) {
			return $cached_redirections;
		}

		$redirections = $wpdb->get_results($wpdb->prepare("SELECT * FROM `$tableName` WHERE status = %s ORDER BY created_at DESC", 'active'));

		// Cache for 1 hour
		wp_cache_set($cache_key, $redirections, 'metasync', HOUR_IN_SECONDS);

		return $redirections;
	}

	/**
	 * Find a single redirection by ID
	 * @param int $id The redirection ID
	 * @return object|null The redirection record or null if not found
	 */
	public function find($id)
	{
		global $wpdb;
		$tableName = $this->get_table_name();
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM `$tableName` WHERE id = %d", intval($id)));
	}

	/**
	 * Add a record.
	 * @param array $args Values to insert.
	 */
	public function add($args)
	{
		global $wpdb;
		
		// Ensure table structure is up to date
		$this->ensure_table_structure();
		
		$args = wp_parse_args(
			$args,
			[
				'sources_from'    	=> [],
				'url_redirect_to'   => site_url(),
				'http_code'    		=> 301,
				'hits_count'    	=> 0,
				'status'   			=> 'active',
				'pattern_type'		=> 'exact',
				'regex_pattern'		=> null,
				'description'		=> '',
				'created_at'		=> current_time('mysql'),
				'updated_at'		=> current_time('mysql'),
			]
		);
		
		$result = $wpdb->insert($this->get_table_name(), $args);
		
		// Clear cache after adding
		$this->clear_cache();
		
		return $result;
	}

	/**
	 * Update a record.
	 * @param array $args Values to update.
	 * @param string $id
	 */
	public function update($args, $id)
	{
		global $wpdb;
		
		// Ensure table structure is up to date
		$this->ensure_table_structure();
		
		$tableName = $this->get_table_name();
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$tableName` WHERE `id` = %s ", $id));
		if (!$row) return;
		
		$args['updated_at'] = current_time('mysql');
		$wpdb->update($tableName, $args, ['id' => $id]);
		
		// Clear cache after updating
		$this->clear_cache();
	}

	/**
	 * Get total number of rows in the DB table).
	 */
	public function get_count()
	{
		return count($this->getAllRecords());
	}

	/**
	 * Delete a redirection record.
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
		
		// Clear cache after deleting
		$this->clear_cache();
	}

	/**
	 * activate a redirection record.
	 */
	public function update_status($items, $status)
	{
		global $wpdb;
		$tableName = $this->get_table_name();
		if (!is_array($items) || empty($items)) return;
		$ids = implode(', ', array_fill(0, count($items), '%d'));
		$set_status = $wpdb->prepare(
			"
			UPDATE `$tableName`
			SET `status` = %s, `updated_at` = %s",
			$status,
			current_time('mysql')
		);
		$where = $wpdb->prepare(
			"
			WHERE `id` IN ( $ids )",
			$items
		);
		$query = "{$set_status}{$where}";
		$wpdb->query($query);
		
		// Clear cache after updating status
		$this->clear_cache();
	}

	/**
	 * Update if URL is matched and hit.
	 * @param object $row Record to update.
	 */
	public function update_counter($row)
	{
		global $wpdb;
		$update_data = [
			'last_accessed_at'  => current_time('mysql'),
			'hits_count' => absint($row->hits_count) + 1,
		];
		$wpdb->update($this->get_table_name(), $update_data, ['id' => $row->id]);
	}

	/**
	 * Clear redirection cache
	 */
	public function clear_cache()
	{
		wp_cache_delete('metasync_active_redirections', 'metasync');
	}

	/**
	 * Search redirections with filters
	 * @param array $filters Search filters
	 */
	public function search_redirections($filters = [])
	{
		global $wpdb;
		$tableName = $this->get_table_name();
		
		$where_conditions = ['1=1'];
		$where_values = [];
		
		if (!empty($filters['search'])) {
			$where_conditions[] = "(sources_from LIKE %s OR url_redirect_to LIKE %s OR description LIKE %s)";
			$search_term = '%' . $wpdb->esc_like($filters['search']) . '%';
			$where_values[] = $search_term;
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
		
		if (!empty($filters['http_code'])) {
			$where_conditions[] = "http_code = %d";
			$where_values[] = intval($filters['http_code']);
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
		
		$query = "SELECT * FROM `$tableName` WHERE $where_clause ORDER BY $order_by $order" . $limit_clause;
		
		if (!empty($where_values)) {
			return $wpdb->get_results($wpdb->prepare($query, $where_values));
		} else {
			return $wpdb->get_results($query);
		}
	}

	/**
	 * Count total redirections with filters (for pagination)
	 * @param array $filters Search filters
	 */
	public function count_redirections($filters = [])
	{
		global $wpdb;
		$tableName = $this->get_table_name();
		
		$where_conditions = ['1=1'];
		$where_values = [];
		
		if (!empty($filters['search'])) {
			$where_conditions[] = "(sources_from LIKE %s OR url_redirect_to LIKE %s OR description LIKE %s)";
			$search_term = '%' . $wpdb->esc_like($filters['search']) . '%';
			$where_values[] = $search_term;
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
		
		if (!empty($filters['http_code'])) {
			$where_conditions[] = "http_code = %d";
			$where_values[] = intval($filters['http_code']);
		}
		
		$where_clause = implode(' AND ', $where_conditions);
		$query = "SELECT COUNT(*) FROM `$tableName` WHERE $where_clause";
		
		if (!empty($where_values)) {
			return $wpdb->get_var($wpdb->prepare($query, $where_values));
		} else {
			return $wpdb->get_var($query);
		}
	}
}

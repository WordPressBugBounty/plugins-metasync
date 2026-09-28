<?php

/**
 * The database operations for the redirections.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/redirections
 * @author     Engineering Team <support@searchatlas.com>
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('Metasync_Redirection_Database')) {
class Metasync_Redirection_Database
{
	public static $table_name = "metasync_redirections";
	private static $structure_verified = false;

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
		// Run schema inspection at most once per request — table schema only changes on activation/upgrade, handled by class-db-migrations.php.
		if (self::$structure_verified) { return; }
		self::$structure_verified = true;

		global $wpdb;
		$table_name = $this->get_table_name();

		// Check if table exists
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- self-healing schema backstop for installs that missed a migration; admin write paths only, once per request via the $structure_verified gate
			// Table doesn't exist, run full migration
			require_once dirname(__FILE__, 2) . '/database/class-db-migrations.php';
			MetaSync_DBMigration::activation();
			return;
		}
		
		// Check if required columns exist
		$columns = $wpdb->get_col("DESCRIBE {$wpdb->prefix}metasync_redirections"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- self-healing schema backstop — column introspection, admin write paths only, once per request
		
		if (!in_array('pattern_type', $columns)) {
			$wpdb->query("ALTER TABLE {$wpdb->prefix}metasync_redirections ADD COLUMN pattern_type ENUM('exact', 'contain', 'start', 'end', 'regex', 'wildcard') NOT NULL DEFAULT 'exact' AFTER status"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- self-healing schema backstop for installs that missed a migration; admin write paths only, once per request via the $structure_verified gate
		}
		if (!in_array('regex_pattern', $columns)) {
			$wpdb->query("ALTER TABLE {$wpdb->prefix}metasync_redirections ADD COLUMN regex_pattern TEXT NULL AFTER pattern_type"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- self-healing schema backstop for installs that missed a migration; admin write paths only, once per request via the $structure_verified gate
		}
		if (!in_array('description', $columns)) {
			$wpdb->query("ALTER TABLE {$wpdb->prefix}metasync_redirections ADD COLUMN description TEXT NULL AFTER regex_pattern"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- self-healing schema backstop for installs that missed a migration; admin write paths only, once per request via the $structure_verified gate
		}
		if (!in_array('created_at', $columns)) {
			$wpdb->query("ALTER TABLE {$wpdb->prefix}metasync_redirections ADD COLUMN created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER description"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- self-healing schema backstop for installs that missed a migration; admin write paths only, once per request via the $structure_verified gate
		}
		if (!in_array('updated_at', $columns)) {
			$wpdb->query("ALTER TABLE {$wpdb->prefix}metasync_redirections ADD COLUMN updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER created_at"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- self-healing schema backstop for installs that missed a migration; admin write paths only, once per request via the $structure_verified gate
		}
		if (!in_array('last_accessed_at', $columns)) {
			$wpdb->query("ALTER TABLE {$wpdb->prefix}metasync_redirections ADD COLUMN last_accessed_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER updated_at"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- self-healing schema backstop for installs that missed a migration; admin write paths only, once per request via the $structure_verified gate
		}

		// Widen pattern_type for installs whose column predates wildcard
		// matching — column-exists checks never re-ALTER, so the enum would
		// otherwise keep silently rejecting wildcard rows.
		if (in_array('pattern_type', $columns)) {
			$column_info = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$wpdb->prefix}metasync_redirections LIKE %s", 'pattern_type')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- self-healing schema backstop — enum widening for pre-wildcard installs, admin write paths only
			if ($column_info && strpos((string) $column_info->Type, 'wildcard') === false) {
				$wpdb->query("ALTER TABLE {$wpdb->prefix}metasync_redirections MODIFY COLUMN pattern_type ENUM('exact', 'contain', 'start', 'end', 'regex', 'wildcard') NOT NULL DEFAULT 'exact'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- self-healing schema backstop — enum widening for pre-wildcard installs, admin write paths only
			}
		}

		// Check and add indexes
		$indexes = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}metasync_redirections"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- self-healing schema backstop — index introspection, admin write paths only, once per request
		$index_names = array_column($indexes, 'Key_name');

		if (!in_array('pattern_type', $index_names)) {
			$wpdb->query("ALTER TABLE {$wpdb->prefix}metasync_redirections ADD KEY pattern_type (pattern_type)"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- self-healing schema backstop for installs that missed a migration; admin write paths only, once per request via the $structure_verified gate
		}
		if (!in_array('created_at', $index_names)) {
			$wpdb->query("ALTER TABLE {$wpdb->prefix}metasync_redirections ADD KEY created_at (created_at)"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- self-healing schema backstop for installs that missed a migration; admin write paths only, once per request via the $structure_verified gate
		}
		if (!in_array('status_created', $index_names)) {
			$wpdb->query("ALTER TABLE {$wpdb->prefix}metasync_redirections ADD KEY status_created (status, created_at)"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- self-healing schema backstop for installs that missed a migration; admin write paths only, once per request via the $structure_verified gate
		}
		
		// Set default pattern_type for existing records
		$wpdb->query("UPDATE {$wpdb->prefix}metasync_redirections SET pattern_type = 'exact' WHERE pattern_type IS NULL OR pattern_type = ''"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- self-healing schema backstop — one-time backfill bound to the column additions above
	}

	/**
	 * Manually trigger table structure update
	 * Can be called from admin or via AJAX if needed
	 */
	public function force_table_update()
	{
		self::$structure_verified = false;
		$this->ensure_table_structure();
		return true;
	}

	public function getAllRecords()
	{
		global $wpdb;
		return $wpdb->get_results("SELECT * FROM `{$wpdb->prefix}metasync_redirections`"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository — no WordPress API exists for plugin tables; the hot front-end read (getAllActiveRecords) is object-cached with invalidation on every write
	}

	public function getAllActiveRecords()
	{
		global $wpdb;

		// Check cache first
		$cache_key = 'metasync_active_redirections';
		$cached_redirections = wp_cache_get($cache_key, 'metasync');

		if ($cached_redirections !== false) {
			return $cached_redirections;
		}

		// id tiebreaker: created_at has one-second resolution, so rows created in
		// the same second (bulk import, migrations) would come back in arbitrary
		// order — and the engine's first-match-wins scan would then be
		// nondeterministic between requests.
		$redirections = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$wpdb->prefix}metasync_redirections` WHERE status = %s ORDER BY created_at DESC, id DESC", 'active')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository — this read is the cache SOURCE for the object-cached active set (invalidated on every write)

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
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$wpdb->prefix}metasync_redirections` WHERE id = %d", intval($id))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository — no WordPress API exists for plugin tables; the hot front-end read (getAllActiveRecords) is object-cached with invalidation on every write
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

		// Serialize sources_from if array (rest of codebase uses serialized format)
		if ( is_array( $args['sources_from'] ) ) {
			$sources = $args['sources_from'];
			$args['sources_from'] = serialize( array_combine( array_values( $sources ), array_fill( 0, count( $sources ), 'exact' ) ) ?: [] );
		}
		
		$result = $wpdb->insert( $this->get_table_name(), $args ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository — no WordPress API exists for plugin tables; the hot front-end read (getAllActiveRecords) is object-cached with invalidation on every write
		
		// Clear cache after adding
		$this->clear_cache();
		
		return $result !== false ? (int) $wpdb->insert_id : false;
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
		
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$wpdb->prefix}metasync_redirections` WHERE `id` = %s ", $id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository — no WordPress API exists for plugin tables; the hot front-end read (getAllActiveRecords) is object-cached with invalidation on every write
		if (!$row) return false;
		
		$args['updated_at'] = current_time('mysql');
		$result = $wpdb->update($wpdb->prefix . 'metasync_redirections', $args, ['id' => $id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository — no WordPress API exists for plugin tables; the hot front-end read (getAllActiveRecords) is object-cached with invalidation on every write
		
		// Clear cache after updating
		$this->clear_cache();

		// Row count on success (0 = no-op write), false on query failure —
		// callers checking `=== false` could never fire against void.
		return $result;
	}

	/**
	 * Get total number of rows in the DB table).
	 */
	public function get_count()
	{
		global $wpdb;
		return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}metasync_redirections`"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository — no WordPress API exists for plugin tables; the hot front-end read (getAllActiveRecords) is object-cached with invalidation on every write
	}

	/**
	 * Delete a redirection record.
	 */
	public function delete($items)
	{
		global $wpdb;
		if (!is_array($items) || empty($items)) return;
		$wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository — no WordPress API exists for plugin tables; the hot front-end read (getAllActiveRecords) is object-cached with invalidation on every write
			'DELETE FROM `' . $wpdb->prefix . 'metasync_redirections` WHERE `id` IN (' . implode(',', array_fill(0, count($items), '%d')) . ')',
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
		if (!is_array($items) || empty($items)) return;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository — no WordPress API exists for plugin tables; the hot front-end read (getAllActiveRecords) is object-cached with invalidation on every write
			$wpdb->prepare(
				'UPDATE `' . $wpdb->prefix . 'metasync_redirections`
				SET `status` = %s, `updated_at` = %s
				WHERE `id` IN (' . implode(', ', array_fill(0, count($items), '%d')) . ')',
				array_merge(array($status, current_time('mysql')), array_map('intval', $items))
			)
		);
		
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
		// Atomic increment. The $row object comes from getAllActiveRecords(),
		// which can be an hour-stale cache hit, and concurrent requests that
		// both read the same hits_count would lose every hit but one. Bump in
		// SQL so the stored counter is correct regardless of what $row saw.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository — no WordPress API exists for plugin tables; the hot front-end read (getAllActiveRecords) is object-cached with invalidation on every write
			$wpdb->prepare(
				"UPDATE `{$wpdb->prefix}metasync_redirections` SET `hits_count` = `hits_count` + 1, `last_accessed_at` = %s WHERE `id` = %d",
				current_time('mysql'),
				absint($row->id)
			)
		);
	}

	/**
	 * Clear redirection cache
	 */
	public function clear_cache()
	{
		wp_cache_delete('metasync_active_redirections', 'metasync');
		// Redirect sources must leave the sitemap promptly: the sitemap
		// generator listens for this on every add/update/delete/status write
		// and busts its (30-day) caches so the next crawl regenerates.
		do_action('metasync_redirections_changed');
	}

	/**
	 * Columns that may be used for ORDER BY. Every entry maps to the literal
	 * column name, so only allowlisted identifiers can reach the query.
	 */
	private function get_sortable_columns()
	{
		return [
			'id', 'sources_from', 'url_redirect_to', 'http_code', 'hits_count',
			'status', 'pattern_type', 'created_at', 'updated_at', 'last_accessed_at', 'description',
		];
	}

	/**
	 * Build the ORDER BY clause for search()/count() from the allowlist.
	 * Falls back to the default ordering for unknown/invalid input.
	 */
	private function get_order_by_sql($filters)
	{
		$direction = (!empty($filters['order']) && strtoupper($filters['order']) === 'ASC') ? 'ASC' : 'DESC';
		$requested = isset($filters['order_by']) ? (string) $filters['order_by'] : '';
		if (!in_array($requested, $this->get_sortable_columns(), true)) {
			$requested = 'created_at';
		}
		return sanitize_sql_orderby($requested . ' ' . $direction);
	}

	/**
	 * Search redirections with filters
	 * @param array $filters Search filters
	 */
	public function search_redirections($filters = [])
	{
		global $wpdb;

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

		$order_by_sql = $this->get_order_by_sql($filters) ?: 'created_at DESC';

		// The query is assembled from literal placeholder fragments only; the
		// ORDER BY fragment comes from the sortable-column allowlist wrapped in
		// sanitize_sql_orderby(). All dynamic values bind via prepare() below.
		$query = 'SELECT * FROM `' . $wpdb->prefix . 'metasync_redirections` WHERE ' . implode(' AND ', $where_conditions)
			. ' ORDER BY ' . $order_by_sql
			. (isset($filters['per_page'], $filters['offset']) ? ' LIMIT %d OFFSET %d' : '');
		$values = isset($filters['per_page'], $filters['offset'])
			? array_merge($where_values, array(intval($filters['per_page']), intval($filters['offset'])))
			: $where_values;

		// With no filters the assembled query is literal-only (no
		// placeholders); prepare() rejects placeholder-less queries, so only
		// route it through prepare() when there are values to bind.
		if (empty($values)) {
			return $wpdb->get_results($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- literal fragments only, nothing to bind
		}
		return $wpdb->get_results($wpdb->prepare($query, $values)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- literal fragments + allowlisted ORDER BY, values bound via prepare()
	}

	/**
	 * Count total redirections with filters (for pagination)
	 * @param array $filters Search filters
	 */
	public function count_redirections($filters = [])
	{
		global $wpdb;

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

		// The query is assembled from literal placeholder fragments only; all
		// dynamic values bind via prepare() below.
		$query = 'SELECT COUNT(*) FROM `' . $wpdb->prefix . 'metasync_redirections` WHERE ' . implode(' AND ', $where_conditions);

		// Placeholder-less queries must skip prepare() (see search above).
		if (empty($where_values)) {
			return $wpdb->get_var($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- literal fragments only, nothing to bind
		}
		return $wpdb->get_var($wpdb->prepare($query, $where_values)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- literal fragments only, values bound via prepare()
	}
}
}

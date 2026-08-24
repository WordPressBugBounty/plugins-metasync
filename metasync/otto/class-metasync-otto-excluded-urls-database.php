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
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
			// Table doesn't exist, run full migration
			require_once dirname(__FILE__, 2) . '/database/class-db-migrations.php';
			MetaSync_DBMigration::activation();
			return;
		}

		// Fetch all column names once to avoid multiple SHOW COLUMNS calls
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing_columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table_name}`");

		// is_permanent — added in v2.7.x; missing on sites that skipped the version migration
		if (!in_array('is_permanent', $existing_columns, true)) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `is_permanent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`");
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query("ALTER TABLE `{$table_name}` ADD KEY `is_permanent` (`is_permanent`)");
		}

		// auto_excluded — added in v2.7.4; missing on older installs
		if (!in_array('auto_excluded', $existing_columns, true)) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `auto_excluded` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_permanent`");
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query("ALTER TABLE `{$table_name}` ADD KEY `auto_excluded` (`auto_excluded`)");
		}

		// recheck_after — added alongside auto_excluded; missing on the same older installs
		if (!in_array('recheck_after', $existing_columns, true)) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `recheck_after` DATETIME NULL DEFAULT NULL AFTER `auto_excluded`");
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query("ALTER TABLE `{$table_name}` ADD KEY `recheck_after` (`recheck_after`)");
			// Backfill: set recheck_after = created_at + 7 days for auto-excluded URLs
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query("UPDATE `{$table_name}` SET recheck_after = DATE_ADD(created_at, INTERVAL 7 DAY) WHERE auto_excluded = 1 AND (recheck_after IS NULL OR recheck_after = '0000-00-00 00:00:00')");
		}

		// status_auto_excluded composite index — speeds up the cache-miss query in metasync_is_otto_url_manually_excluded()
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_composite_index = $wpdb->get_var("SHOW INDEX FROM `{$table_name}` WHERE Key_name = 'status_auto_excluded'");
		if (empty($has_composite_index)) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query("ALTER TABLE `{$table_name}` ADD KEY `status_auto_excluded` (`status`, `auto_excluded`)");
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
	 * Get a single record by ID
	 *
	 * @param int $id Record ID
	 * @return object|null Record object or null if not found
	 */
	public function get_record_by_id($id)
	{
		global $wpdb;

		$this->ensure_table_structure();

		$table_name = $this->get_table_name();

		return $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM `$table_name` WHERE id = %d",
			$id
		));
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
			if (self::pattern_matches($url, $excluded->url_pattern, $excluded->pattern_type)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Match a URL against a single exclusion pattern.
	 *
	 * Single source of truth for exclusion matching. Both the queue/SEO-write path
	 * (self::is_url_excluded()) and the visitor render gate
	 * (metasync_is_otto_url_manually_excluded() in otto/otto_pixel.php) call this, so the
	 * set of supported pattern types cannot drift apart between the two paths again.
	 *
	 * Failure is deliberately fail-open (return false = not excluded = OTTO still renders):
	 * an unevaluable rule must not take the front end down. Regex rules that cannot be
	 * compiled are logged once per request so a broken rule is diagnosable rather than
	 * invisible.
	 *
	 * @param string $url          URL to test. Callers pass it trimmed with no trailing slash.
	 * @param string $pattern      Raw url_pattern column value. Normalized here, per type.
	 * @param string $pattern_type One of exact|contain|start|end|regex.
	 * @return bool True when the URL matches the pattern.
	 */
	public static function pattern_matches($url, $pattern, $pattern_type)
	{
		// Literal patterns are compared against a URL the caller already stripped of its
		// trailing slash, so strip the pattern the same way. A regex must NOT be stripped:
		// in a `/.../`-delimited pattern the trailing slash is the closing delimiter, and
		// removing it yields an uncompilable pattern (`/\/blog\//` becomes `/\/blog\`) that
		// silently matches nothing.
		$pattern = ($pattern_type === 'regex') ? trim($pattern) : rtrim(trim($pattern), '/');

		switch ($pattern_type) {
			case 'exact':
				return $url === $pattern;

			case 'contain':
				return strpos($url, $pattern) !== false;

			case 'start':
				return strpos($url, $pattern) === 0;

			case 'end':
				return substr($url, -strlen($pattern)) === $pattern;

			case 'regex':
				return self::regex_matches($url, $pattern);
		}

		return false;
	}

	/**
	 * Evaluate a single regex exclusion pattern against a URL.
	 *
	 * @param string $url     URL to test.
	 * @param string $pattern Regex pattern, with or without delimiters.
	 * @return bool True on a match. False on no match, or on any evaluation failure.
	 */
	private static function regex_matches($url, $pattern)
	{
		if ($pattern === '') {
			return false;
		}

		if (!self::regex_normalizer_available()) {
			return false;
		}

		// Normalize with delimiters
		$test_pattern = Metasync_Redirection::normalize_regex_pattern($pattern);

		// Cap backtracking so a pathological pattern cannot burn CPU on a visitor request.
		// ini_set() sits in disable_functions on some hardened hosts and calling a disabled
		// function raises an Error, not an Exception - which the callers' catch (Exception)
		// would not contain, white-screening the front end. Only restore the limit if it was
		// actually read and changed.
		$prev_limit = function_exists('ini_get') ? ini_get('pcre.backtrack_limit') : false;
		$capped     = ($prev_limit !== false && function_exists('ini_set')
			&& ini_set('pcre.backtrack_limit', 10000) !== false);

		$match = @preg_match($test_pattern, $url);

		if ($capped) {
			ini_set('pcre.backtrack_limit', $prev_limit);
		}

		if ($match === false) {
			self::log_unusable_regex($pattern, $test_pattern);
		}

		return $match === 1;
	}

	/**
	 * Load the class that owns the regex normalizer, once per request.
	 *
	 * normalize_regex_pattern() is a static on Metasync_Redirection. It normally resolves via
	 * the Composer classmap registered in metasync.php, but release packages have shipped with
	 * an incomplete classmap before (see docs/wiki/release-package-classmaps.md), so the file
	 * is included explicitly rather than trusting the autoloader. If it is missing the caller
	 * must not attempt the static call at all: that raises an Error, not an Exception, so the
	 * callers' try/catch would not contain it and the front end would white-screen.
	 *
	 * Memoized because this runs on every front-end request - without it there would be one
	 * filesystem stat per regex exclusion row per page load.
	 *
	 * @return bool True when the normalizer can be called.
	 */
	private static function regex_normalizer_available()
	{
		static $available = null;

		if ($available === null) {
			$class_path = dirname(__FILE__, 2) . '/redirections/class-metasync-redirection.php';
			$available  = file_exists($class_path);

			if ($available) {
				require_once $class_path;
			}
		}

		return $available;
	}

	/**
	 * Log an exclusion regex that could not be evaluated, once per pattern per request.
	 *
	 * Without this the rule is skipped in total silence: the admin sees an active exclusion
	 * and a page that is still being rewritten, with nothing to go on.
	 *
	 * @param string $pattern      The stored pattern.
	 * @param string $test_pattern The pattern as handed to preg_match().
	 * @return void
	 */
	private static function log_unusable_regex($pattern, $test_pattern)
	{
		static $logged = [];

		$key = md5($pattern);
		if (isset($logged[$key])) {
			return;
		}
		$logged[$key] = true;

		error_log(sprintf(
			'MetaSync OTTO: skipped an exclusion rule whose regex could not be evaluated - stored "%s", compiled "%s" (%s)',
			$pattern,
			$test_pattern,
			preg_last_error_msg()
		));
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

		$created_at = current_time('mysql');
		$args = wp_parse_args(
			$args,
			[
				'url_pattern'    => '',
				'pattern_type'   => 'exact',
				'description'    => '',
				'status'         => 'active',
				'is_permanent'   => 0,
				'auto_excluded'  => 0,
				'recheck_after'  => null,
				'created_at'     => $created_at,
			]
		);

		// Auto-excluded URLs: set recheck_after to 7 days from creation by default
		if (!empty($args['auto_excluded']) && $args['recheck_after'] === null) {
			$args['recheck_after'] = gmdate('Y-m-d H:i:s', strtotime('+7 days', strtotime($created_at)));
		}

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
				$update_data = ['status' => 'active', 'description' => $args['description']];
				if (!empty($args['auto_excluded'])) {
					$update_data['auto_excluded'] = 1;
					$update_data['recheck_after'] = gmdate('Y-m-d H:i:s', strtotime('+7 days'));
				}
				$wpdb->update($table_name, $update_data, ['id' => $existing->id]);
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

		// Validate regex pattern if type is regex (structural checks only; callers must validate syntax)
		if ($args['pattern_type'] === 'regex') {
			if (mb_strlen($args['url_pattern']) > 500) {
				return false; // Pattern too long
			}
			// Reject nested quantifiers that could cause ReDoS
			$raw = preg_replace('/^\S(.*)\S[a-zA-Z]*$/', '$1', $args['url_pattern']);
			if (preg_match('/(\([^)]*[+*][^)]*\))[+*?{]|(\[[^\]]*\])[+*][+*?{]/', $raw)) {
				return false; // Unsafe pattern
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

		// Validate regex pattern if type is regex (structural checks only; callers must validate syntax)
		if (isset($args['pattern_type']) && $args['pattern_type'] === 'regex') {
			if (isset($args['url_pattern'])) {
				if (mb_strlen($args['url_pattern']) > 500) {
					return false; // Pattern too long
				}
				// Reject nested quantifiers that could cause ReDoS
				$raw = preg_replace('/^\S(.*)\S[a-zA-Z]*$/', '$1', $args['url_pattern']);
				if (preg_match('/(\([^)]*[+*][^)]*\))[+*?{]|(\[[^\]]*\])[+*][+*?{]/', $raw)) {
					return false; // Unsafe pattern
				}
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
		if (defined('METASYNC_OTTO_EXCLUDED_TRANSIENT_KEY')) {
			delete_transient(METASYNC_OTTO_EXCLUDED_TRANSIENT_KEY);
		}
	}

	/**
	 * Get auto-excluded 404 URLs that are due for recheck
	 * Uses recheck_after timestamp: returns records where recheck_after <= now
	 * Excludes permanent exclusions (is_permanent = 1) - those are never rechecked
	 * Limited to max 50 URLs per run to avoid overloading
	 *
	 * @param int $limit Maximum number of URLs to return (default 50)
	 * @return array Array of records with id, url_pattern, created_at, is_permanent, recheck_after
	 */
	public function get_auto_excluded_404_urls_due_for_recheck($limit = 50)
	{
		global $wpdb;

		$this->ensure_table_structure();

		$table_name = $this->get_table_name();
		$now = current_time('mysql');
		$limit = max(1, min(100, intval($limit)));

		return $wpdb->get_results($wpdb->prepare(
			"SELECT id, url_pattern, pattern_type, created_at, is_permanent, recheck_after FROM `$table_name`
			WHERE auto_excluded = 1 AND status = %s AND pattern_type = %s
			AND (is_permanent = 0 OR is_permanent IS NULL)
			AND recheck_after IS NOT NULL AND recheck_after <= %s
			ORDER BY recheck_after ASC
			LIMIT %d",
			'active',
			'exact',
			$now,
			$limit
		));
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

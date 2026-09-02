<?php

/**
 * The database migration for the plugin.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/database
 * @author     Engineering Team <support@searchatlas.com>
 */
// Some Plugins declare class name DBMigration to avoid conflict, renamed the class
class MetaSync_DBMigration
{

	/**
	 * activation of migration.
	 */
	public static function activation()
	{
		self::run_migrations();
	}

	/**
	 * Run all database migrations
	 */
	public static function run_migrations()
	{
		global $wpdb;
		$collate = $wpdb->get_charset_collate();
		
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Create 404 Monitor Table
		require_once dirname(__FILE__, 2) . '/404-monitor/class-metasync-404-monitor-database.php';
		$tableName = esc_sql($wpdb->prefix . Metasync_Error_Monitor_Database::$table_name);

		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tableName)) != $tableName) {
			$table_sql = "CREATE TABLE {$tableName} (
				id BIGINT(20) unsigned NOT NULL AUTO_INCREMENT,
				uri VARCHAR(255) NOT NULL,
				date_time DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				hits_count BIGINT(20) unsigned NOT NULL DEFAULT 1,
				user_agent VARCHAR(255) NOT NULL DEFAULT '',
				PRIMARY KEY id (id),
				KEY uri (uri(191))
			) $collate;";

			dbDelta($table_sql);
		}

		// Create Redirections Table
		require_once dirname(__FILE__, 2) . '/redirections/class-metasync-redirection-database.php';
		$tableNameRedirection = esc_sql($wpdb->prefix . Metasync_Redirection_Database::$table_name);

		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s ", $tableNameRedirection)) != $tableNameRedirection) {
			$table_sql = "CREATE TABLE {$tableNameRedirection} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				sources_from TEXT NOT NULL,
				url_redirect_to TEXT NOT NULL,
				http_code SMALLINT(4) unsigned NOT NULL DEFAULT 301,
				hits_count BIGINT(20) unsigned NOT NULL DEFAULT '0',
				status VARCHAR(25) NOT NULL DEFAULT 'active',
				pattern_type ENUM('exact', 'contain', 'start', 'end', 'regex', 'wildcard') NOT NULL DEFAULT 'exact',
				regex_pattern TEXT NULL,
				description TEXT NULL,
				created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				last_accessed_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY id (id),
				KEY status (status),
				KEY pattern_type (pattern_type),
				KEY created_at (created_at),
				KEY idx_active_redirects (status, sources_from(191))
			) $collate;";

			dbDelta($table_sql);
		} else {
			// Check if new columns exist and add them if they don't
			$columns = $wpdb->get_col("DESCRIBE {$tableNameRedirection}");

			if (!in_array('pattern_type', $columns)) {
				$wpdb->query("ALTER TABLE {$tableNameRedirection} ADD COLUMN pattern_type ENUM('exact', 'contain', 'start', 'end', 'regex', 'wildcard') NOT NULL DEFAULT 'exact' AFTER status");
			}

			if (!in_array('regex_pattern', $columns)) {
				$wpdb->query("ALTER TABLE {$tableNameRedirection} ADD COLUMN regex_pattern TEXT NULL AFTER pattern_type");
			}

			if (!in_array('description', $columns)) {
				$wpdb->query("ALTER TABLE {$tableNameRedirection} ADD COLUMN description TEXT NULL AFTER regex_pattern");
			}

			// Add indexes if they don't exist
			$indexes = $wpdb->get_results("SHOW INDEX FROM {$tableNameRedirection}");
			$index_names = array_column($indexes, 'Key_name');

			if (!in_array('pattern_type', $index_names)) {
				$wpdb->query("ALTER TABLE {$tableNameRedirection} ADD KEY pattern_type (pattern_type)");
			}

			if (!in_array('created_at', $index_names)) {
				$wpdb->query("ALTER TABLE {$tableNameRedirection} ADD KEY created_at (created_at)");
			}

			// PERFORMANCE OPTIMIZATION: Add composite index for active redirects lookup
			if (!in_array('idx_active_redirects', $index_names)) {
				$wpdb->query("ALTER TABLE {$tableNameRedirection} ADD KEY idx_active_redirects (status, sources_from(191))");
			}

			// Set default pattern_type for existing records
			$wpdb->query("UPDATE {$tableNameRedirection} SET pattern_type = 'exact' WHERE pattern_type IS NULL OR pattern_type = ''");
		}

		// One-time migration: auto-enable external redirects if the site already has any
		if (!get_option('metasync_external_redirects_migrated')) {
			// Compare against every home-URL variant (http/https, www/non-www),
			// not just the exact home_url() string — otherwise same-site
			// destinations such as 'https://example.com/about' on a
			// www-prefixed site count as external and silently flip the setting.
			$home_host = wp_parse_url(home_url(), PHP_URL_HOST);
			$home_host = is_string($home_host) ? strtolower(preg_replace('/^www\./i', '', $home_host)) : '';
			$params = ['http%'];
			if ($home_host !== '') {
				$not_like = '';
				foreach (['http://', 'https://'] as $scheme) {
					foreach ([$home_host, 'www.' . $home_host] as $host) {
						$not_like .= ' AND url_redirect_to NOT LIKE %s';
						$params[] = $wpdb->esc_like($scheme . $host) . '%';
					}
				}
			} else {
				$not_like = ' AND url_redirect_to NOT LIKE %s';
				$params[] = $wpdb->esc_like(trailingslashit(home_url())) . '%';
			}
			$external_count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$tableNameRedirection} WHERE url_redirect_to LIKE %s{$not_like}",
					...$params
				)
			);
			if ($external_count > 0) {
				update_option('metasync_allow_external_redirects', 1, true);
			}
			update_option('metasync_external_redirects_migrated', 1, true);
		}

		// Create HeartBeat Error Monitor Table
		require_once dirname(__FILE__, 2) . '/heartbeat-error-monitor/class-metasync-heartbeat-error-monitor-database.php';
		$tableNameHeartBeatErrorMonitor = esc_sql($wpdb->prefix . Metasync_HeartBeat_Error_Monitor_Database::$table_name);

		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s ", $tableNameHeartBeatErrorMonitor)) != $tableNameHeartBeatErrorMonitor) {
			$table_sql = "CREATE TABLE {$tableNameHeartBeatErrorMonitor} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				attribute_name VARCHAR(25) NOT NULL DEFAULT '',
				object_count VARCHAR(25) NOT NULL DEFAULT '',
				error_description TEXT NULL,
				created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY id (id)
			) $collate;";

			dbDelta($table_sql);
		}

		// Create Sync History Table
		require_once dirname(__FILE__, 2) . '/sync-history/class-metasync-sync-history-database.php';
		$tableNameSyncHistory = esc_sql($wpdb->prefix . Metasync_Sync_History_Database::$table_name);

		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s ", $tableNameSyncHistory)) != $tableNameSyncHistory) {
			$table_sql = "CREATE TABLE {$tableNameSyncHistory} (
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

			dbDelta($table_sql);
		} else {
			// PERFORMANCE OPTIMIZATION: Add composite indexes to existing tables
			// Check and add indexes if they don't exist
			$indexes = $wpdb->get_results("SHOW INDEX FROM {$tableNameSyncHistory}");
			$index_names = array_column($indexes, 'Key_name');

			// Add deduplication index (source, created_at)
			if (!in_array('idx_dedup', $index_names)) {
				$wpdb->query("ALTER TABLE {$tableNameSyncHistory} ADD KEY idx_dedup (source, created_at)");
			}

			// Add search index (title(50), source, created_at)
			if (!in_array('idx_search', $index_names)) {
				$wpdb->query("ALTER TABLE {$tableNameSyncHistory} ADD KEY idx_search (title(50), source, created_at)");
			}
		}

		// Create OTTO Excluded URLs Table
		require_once dirname(__FILE__, 2) . '/otto/class-metasync-otto-excluded-urls-database.php';
		$tableNameOttoExcludedURLs = esc_sql($wpdb->prefix . Metasync_Otto_Excluded_URLs_Database::$table_name);

		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s ", $tableNameOttoExcludedURLs)) != $tableNameOttoExcludedURLs) {
			$table_sql = "CREATE TABLE {$tableNameOttoExcludedURLs} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				url_pattern TEXT NOT NULL,
				pattern_type ENUM('exact', 'contain', 'start', 'end', 'regex') NOT NULL DEFAULT 'exact',
				description TEXT NULL,
				status VARCHAR(25) NOT NULL DEFAULT 'active',
				is_permanent TINYINT(1) NOT NULL DEFAULT 0,
				auto_excluded TINYINT(1) NOT NULL DEFAULT 0,
				recheck_after DATETIME NULL DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY id (id),
				KEY status (status),
				KEY pattern_type (pattern_type),
				KEY created_at (created_at),
				KEY is_permanent (is_permanent),
				KEY auto_excluded (auto_excluded),
				KEY recheck_after (recheck_after),
				UNIQUE KEY url_pattern_type_unique (url_pattern(191), pattern_type)
			) $collate;";

			dbDelta($table_sql);
		}

		// Create Robots.txt Backups Table
		require_once dirname(__FILE__, 2) . '/robots-txt/class-metasync-robots-txt-database.php';
		$robots_db = Metasync_Robots_Txt_Database::get_instance();
		$table_name_robots = esc_sql($wpdb->prefix . 'metasync_robots_txt_backups');
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name_robots)) != $table_name_robots) {
			$robots_db->create_table();
		}

	}

	/**
	 * deactivation of migration.
	 */
	public static function deactivation()
	{
		global $wpdb;
		// require_once dirname(__FILE__, 2) . '/404-monitor/class-metasync-404-monitor-database.php';
		// $tableName = esc_sql($wpdb->prefix . Metasync_Error_Monitor_Database::$table_name);

		/* drop wp_metasync_404_logs table */
		// $sql = "DROP TABLE IF EXISTS `$tableName` ";
		// $wpdb->query($sql);

		// require_once dirname(__FILE__, 2) . '/redirections/class-metasync-redirection-database.php';
		// $tableNameRedirection = esc_sql($wpdb->prefix . Metasync_Redirection_Database::$table_name);

		/* drop wp_metasync_redirections table */
		// $sql = "DROP TABLE IF EXISTS `$tableNameRedirection` ";
		// $wpdb->query($sql);

		require_once dirname(__FILE__, 2) . '/heartbeat-error-monitor/class-metasync-heartbeat-error-monitor-database.php';
		$tableNameHeartBeatErrorMonitor = esc_sql($wpdb->prefix . Metasync_HeartBeat_Error_Monitor_Database::$table_name);
		/* drop wp_metasync_redirections table */
		$sql = "DROP TABLE IF EXISTS `$tableNameHeartBeatErrorMonitor` ";
		$wpdb->query($sql);
	}

	/**
	 * Run version-specific migrations
	 */
	public static function run_version_migrations($from_version, $to_version)
	{
		// If from_version is 9.9.9, always run all migrations
		$force_run = ($from_version === '9.9.9');

		// Migration for versions 2.5.4+ - Enhanced 404 monitor and redirections
		if ($force_run || version_compare($to_version, '2.5.4', '>=')) {
			self::migrate_enhanced_features_v2_5_4();
		}
 
		// Migration for versions 2.5.6+ - Robots.txt management
		if ($force_run || version_compare($to_version, '2.5.6', '>=')) {
			self::migrate_robots_txt_v2_5_6();
		}

		// Migration for versions 2.5.9+ - OTTO Excluded URLs
		if ($force_run || version_compare($to_version, '2.5.9', '>=')) {
			self::migrate_otto_excluded_urls_v2_5_9();
		}

		// Add more version-specific migrations here as needed
		// if (version_compare($from_version, '1.1.0', '<')) {
		//     self::migrate_something_v1_1();
		// }
	}

	/**
	 * Migrate enhanced features for version 2.5.4+
	 */
	private static function migrate_enhanced_features_v2_5_4()
	{
		global $wpdb;
		$collate = $wpdb->get_charset_collate();

		// Load WordPress upgrade functions for dbDelta
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		
		// Enhanced 404 Error Monitor Table
		require_once dirname(__FILE__, 2) . '/404-monitor/class-metasync-404-monitor-database.php';
		$tableName404Monitor = esc_sql($wpdb->prefix . Metasync_Error_Monitor_Database::$table_name);

		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s ", $tableName404Monitor)) != $tableName404Monitor) {
			// Table doesn't exist, create enhanced version
			$table_sql = "CREATE TABLE {$tableName404Monitor} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				uri TEXT NOT NULL,
				hits_count BIGINT(20) unsigned NOT NULL DEFAULT '1',
				date_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				user_agent TEXT NULL,
				referer TEXT NULL,
				ip_address VARCHAR(45) NULL,
				PRIMARY KEY id (id),
				KEY uri (uri(191)),
				KEY hits_count (hits_count),
				KEY date_time (date_time)
			) $collate;";

			dbDelta($table_sql);
		} else {
			// Table exists, check for missing columns and add them
			$columns = $wpdb->get_col("DESCRIBE {$tableName404Monitor}");
			
			// Add referer column if it doesn't exist
			if (!in_array('referer', $columns)) {
				$wpdb->query("ALTER TABLE {$tableName404Monitor} ADD COLUMN referer TEXT NULL AFTER user_agent");
			}
			
			// Add ip_address column if it doesn't exist
			if (!in_array('ip_address', $columns)) {
				$wpdb->query("ALTER TABLE {$tableName404Monitor} ADD COLUMN ip_address VARCHAR(45) NULL AFTER referer");
			}
			
			// Update uri column to TEXT if it's VARCHAR(255)
			$uri_column = $wpdb->get_row("SHOW COLUMNS FROM {$tableName404Monitor} LIKE 'uri'");
			if ($uri_column && strpos($uri_column->Type, 'varchar') !== false) {
				$wpdb->query("ALTER TABLE {$tableName404Monitor} MODIFY COLUMN uri TEXT NOT NULL");
			}
			
			// Update user_agent column to TEXT if it's VARCHAR(255)
			$ua_column = $wpdb->get_row("SHOW COLUMNS FROM {$tableName404Monitor} LIKE 'user_agent'");
			if ($ua_column && strpos($ua_column->Type, 'varchar') !== false) {
				$wpdb->query("ALTER TABLE {$tableName404Monitor} MODIFY COLUMN user_agent TEXT NULL");
			}
			
			// Add missing indexes
			$indexes = $wpdb->get_results("SHOW INDEX FROM {$tableName404Monitor}");
			$index_names = array_column($indexes, 'Key_name');
			
			if (!in_array('hits_count', $index_names)) {
				$wpdb->query("ALTER TABLE {$tableName404Monitor} ADD KEY hits_count (hits_count)");
			}
			
			if (!in_array('date_time', $index_names)) {
				$wpdb->query("ALTER TABLE {$tableName404Monitor} ADD KEY date_time (date_time)");
			}
		}

		// Enhanced Redirections Table with new columns
		require_once dirname(__FILE__, 2) . '/redirections/class-metasync-redirection-database.php';
		$tableNameRedirection = esc_sql($wpdb->prefix . Metasync_Redirection_Database::$table_name);

		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s ", $tableNameRedirection)) == $tableNameRedirection) {
			// Table exists, check for new columns
			$columns = $wpdb->get_col("DESCRIBE {$tableNameRedirection}");
			
			// Add pattern_type column if it doesn't exist
			if (!in_array('pattern_type', $columns)) {
				$wpdb->query("ALTER TABLE {$tableNameRedirection} ADD COLUMN pattern_type ENUM('exact', 'contain', 'start', 'end', 'regex', 'wildcard') NOT NULL DEFAULT 'exact' AFTER status");
			}
			
			// Add regex_pattern column if it doesn't exist
			if (!in_array('regex_pattern', $columns)) {
				$wpdb->query("ALTER TABLE {$tableNameRedirection} ADD COLUMN regex_pattern TEXT NULL AFTER pattern_type");
			}
			
			// Add description column if it doesn't exist
			if (!in_array('description', $columns)) {
				$wpdb->query("ALTER TABLE {$tableNameRedirection} ADD COLUMN description TEXT NULL AFTER regex_pattern");
			}
			
			// Add timestamp columns if they don't exist
			if (!in_array('created_at', $columns)) {
				$wpdb->query("ALTER TABLE {$tableNameRedirection} ADD COLUMN created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER description");
			}
			
			if (!in_array('updated_at', $columns)) {
				$wpdb->query("ALTER TABLE {$tableNameRedirection} ADD COLUMN updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER created_at");
			}
			
			if (!in_array('last_accessed_at', $columns)) {
				$wpdb->query("ALTER TABLE {$tableNameRedirection} ADD COLUMN last_accessed_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER updated_at");
			}
			
			// Add indexes if they don't exist
			$indexes = $wpdb->get_results("SHOW INDEX FROM {$tableNameRedirection}");
			$index_names = array_column($indexes, 'Key_name');
			
			if (!in_array('pattern_type', $index_names)) {
				$wpdb->query("ALTER TABLE {$tableNameRedirection} ADD KEY pattern_type (pattern_type)");
			}
			
			if (!in_array('created_at', $index_names)) {
				$wpdb->query("ALTER TABLE {$tableNameRedirection} ADD KEY created_at (created_at)");
			}
			
			// Set default pattern_type for existing records
			$wpdb->query("UPDATE {$tableNameRedirection} SET pattern_type = 'exact' WHERE pattern_type IS NULL OR pattern_type = ''");
		}
	}

	/**
	 * Migrate robots.txt management for version 2.5.6+
	 */
	private static function migrate_robots_txt_v2_5_6()
	{
		global $wpdb;

		// Create Robots.txt Backups Table
		require_once dirname(__FILE__, 2) . '/robots-txt/class-metasync-robots-txt-database.php';
		$robots_db = Metasync_Robots_Txt_Database::get_instance();
		$table_name = esc_sql($wpdb->prefix . 'metasync_robots_txt_backups');

		// Check if table already exists
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
			// Table doesn't exist, create it
			$robots_db->create_table();
		}
	}

	/**
	 * Migrate OTTO Excluded URLs for version 2.5.9+
	 */
	private static function migrate_otto_excluded_urls_v2_5_9()
	{
		global $wpdb;
		$collate = $wpdb->get_charset_collate();

		// Load WordPress upgrade functions for dbDelta
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Create OTTO Excluded URLs Table
		require_once dirname(__FILE__, 2) . '/otto/class-metasync-otto-excluded-urls-database.php';
		$tableNameOttoExcludedURLs = esc_sql($wpdb->prefix . Metasync_Otto_Excluded_URLs_Database::$table_name);

		// Check if table already exists
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tableNameOttoExcludedURLs)) != $tableNameOttoExcludedURLs) {
			// Table doesn't exist, create it
			$table_sql = "CREATE TABLE {$tableNameOttoExcludedURLs} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				url_pattern TEXT NOT NULL,
				pattern_type ENUM('exact', 'contain', 'start', 'end', 'regex') NOT NULL DEFAULT 'exact',
				description TEXT NULL,
				status VARCHAR(25) NOT NULL DEFAULT 'active',
				is_permanent TINYINT(1) NOT NULL DEFAULT 0,
				auto_excluded TINYINT(1) NOT NULL DEFAULT 0,
				recheck_after DATETIME NULL DEFAULT NULL,
				created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY id (id),
				KEY status (status),
				KEY pattern_type (pattern_type),
				KEY created_at (created_at),
				KEY is_permanent (is_permanent),
				KEY auto_excluded (auto_excluded),
				KEY status_auto_excluded (status, auto_excluded),
				KEY recheck_after (recheck_after),
				UNIQUE KEY url_pattern_type_unique (url_pattern(191), pattern_type)
			) $collate;";

			dbDelta($table_sql);

			// Log successful migration
			// error_log('MetaSync: OTTO Excluded URLs table created successfully (v2.5.9)');
		} else {
			// Table exists, verify structure and add any missing columns if needed
			$columns = $wpdb->get_col("DESCRIBE {$tableNameOttoExcludedURLs}");

			// Check for required columns and add if missing
			$missing_columns = false;

			if (!in_array('pattern_type', $columns)) {
				$wpdb->query("ALTER TABLE {$tableNameOttoExcludedURLs} ADD COLUMN pattern_type ENUM('exact', 'contain', 'start', 'end', 'regex') NOT NULL DEFAULT 'exact' AFTER url_pattern");
				$missing_columns = true;
			}

			if (!in_array('description', $columns)) {
				$wpdb->query("ALTER TABLE {$tableNameOttoExcludedURLs} ADD COLUMN description TEXT NULL AFTER pattern_type");
				$missing_columns = true;
			}

			if (!in_array('status', $columns)) {
				$wpdb->query("ALTER TABLE {$tableNameOttoExcludedURLs} ADD COLUMN status VARCHAR(25) NOT NULL DEFAULT 'active' AFTER description");
				$missing_columns = true;
			}

			if (!in_array('is_permanent', $columns)) {
				$wpdb->query("ALTER TABLE {$tableNameOttoExcludedURLs} ADD COLUMN is_permanent TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
				$wpdb->query("ALTER TABLE {$tableNameOttoExcludedURLs} ADD KEY is_permanent (is_permanent)");
			}

			if (!in_array('auto_excluded', $columns)) {
				$wpdb->query("ALTER TABLE {$tableNameOttoExcludedURLs} ADD COLUMN auto_excluded TINYINT(1) NOT NULL DEFAULT 0 AFTER is_permanent");
				$wpdb->query("ALTER TABLE {$tableNameOttoExcludedURLs} ADD KEY auto_excluded (auto_excluded)");
				// Backfill: mark existing 404 exclusions as auto_excluded
				$wpdb->query("UPDATE {$tableNameOttoExcludedURLs} SET auto_excluded = 1 WHERE description = 'Auto-excluded: 404'");
			}

			if (!in_array('recheck_after', $columns)) {
				$wpdb->query("ALTER TABLE {$tableNameOttoExcludedURLs} ADD COLUMN recheck_after DATETIME NULL DEFAULT NULL AFTER auto_excluded");
				$wpdb->query("ALTER TABLE {$tableNameOttoExcludedURLs} ADD KEY recheck_after (recheck_after)");
				// Backfill: set recheck_after = created_at + 7 days for auto-excluded URLs
				$wpdb->query("UPDATE {$tableNameOttoExcludedURLs} SET recheck_after = DATE_ADD(created_at, INTERVAL 7 DAY) WHERE auto_excluded = 1 AND (recheck_after IS NULL OR recheck_after = '0000-00-00 00:00:00')");
			}

			// Check and add indexes if they don't exist
			$indexes = $wpdb->get_results("SHOW INDEX FROM {$tableNameOttoExcludedURLs}");
			$index_names = array_column($indexes, 'Key_name');

			if (!in_array('status', $index_names)) {
				$wpdb->query("ALTER TABLE {$tableNameOttoExcludedURLs} ADD KEY status (status)");
			}

			if (!in_array('pattern_type', $index_names)) {
				$wpdb->query("ALTER TABLE {$tableNameOttoExcludedURLs} ADD KEY pattern_type (pattern_type)");
			}

			if (!in_array('created_at', $index_names)) {
				$wpdb->query("ALTER TABLE {$tableNameOttoExcludedURLs} ADD KEY created_at (created_at)");
			}

			// Add unique index on url_pattern + pattern_type to prevent duplicates at database level
			// Note: TEXT columns need a prefix length for indexing (767 is max for UTF8)
			if (!in_array('url_pattern_type_unique', $index_names)) {
				$wpdb->query("ALTER TABLE {$tableNameOttoExcludedURLs} ADD UNIQUE KEY url_pattern_type_unique (url_pattern(191), pattern_type)");
			}

			// Composite index for the cache-miss path in metasync_is_otto_url_manually_excluded()
			if (!in_array('status_auto_excluded', $index_names)) {
				$wpdb->query("ALTER TABLE {$tableNameOttoExcludedURLs} ADD KEY status_auto_excluded (status, auto_excluded)");
			}

			// if ($missing_columns) {
			// 	error_log('MetaSync: OTTO Excluded URLs table structure updated (v2.5.9)');
			// }
		}
	}

	/**
	 * One-time cleanup of canonical meta corrupted to the literal
	 * "Array" (and its esc_url'd forms "http://Array" / "https://Array").
	 *
	 * Deletions are exact-match only — a legitimate URL can never match.
	 * Also repairs legacy rows still stored as (possibly nested) serialized
	 * arrays, and clears the mirrored corruption from Yoast / RankMath /
	 * AIOSEO storage that plugin-sync propagated, so cleaned sites are not
	 * re-polluted by stale third-party caches. Idempotent by construction.
	 */
	public static function cleanup_corrupted_canonicals()
	{
		global $wpdb;

		$meta_keys  = array('meta_canonical', '_metasync_canonical_url', '_yoast_wpseo_canonical', 'rank_math_canonical_url');
		$bad_values = array('array', 'http://array', 'https://array');

		$keys_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
		$vals_placeholders = implode(',', array_fill(0, count($bad_values), '%s'));

		// Normalized comparison: trailing slashes stripped in SQL so
		// "http://Array/" and "http://Array//" both match the literals.
		$norm_meta = "LOWER(TRIM(TRAILING '/' FROM TRIM(meta_value)))";

		// 1. Post meta + term meta: delete exact-match corrupted rows.
		// Batched and deleted by primary key so huge postmeta tables aren't
		// range-locked in one statement, with per-object meta-cache
		// invalidation — raw SQL alone would leave persistent object caches
		// (Redis/Memcached) serving the deleted value to Yoast/RankMath
		// readers indefinitely.
		$meta_targets = array(
			array($wpdb->postmeta, 'post_id', 'post_meta'),
			array($wpdb->termmeta, 'term_id', 'term_meta'),
		);
		foreach ($meta_targets as $target) {
			list($table, $object_col, $cache_group) = $target;
			for ($batch = 0; $batch < 50; $batch++) {
				$rows = $wpdb->get_results($wpdb->prepare(
					"SELECT meta_id, {$object_col} AS object_id FROM {$table} WHERE meta_key IN ({$keys_placeholders}) AND {$norm_meta} IN ({$vals_placeholders}) ORDER BY meta_id LIMIT 500",
					array_merge($meta_keys, $bad_values)
				));
				if (empty($rows)) {
					break;
				}
				$meta_ids = implode(',', array_map('intval', wp_list_pluck($rows, 'meta_id')));
				$wpdb->query("DELETE FROM {$table} WHERE meta_id IN ({$meta_ids})");
				foreach ($rows as $row) {
					wp_cache_delete((int) $row->object_id, $cache_group);
				}
				if (count($rows) < 500) {
					break;
				}
			}
		}

		// 2. Rows still stored as serialized arrays (the raw material the
		// "Array" casts came from): repair MetaSync's own keys to the first
		// usable URL inside; third-party keys are delete-only (never invent
		// a value inside another plugin's storage). Written with direct SQL
		// by meta_id so the updated_post_meta plugin-sync cascade, Yoast
		// indexable rebuilds, and sitemap cache busts don't fire once per
		// row; loops until exhausted (repaired rows stop matching LIKE).
		if (class_exists('Metasync_Canonical_Sanitizer')) {
			$own_keys = array('meta_canonical', '_metasync_canonical_url');
			for ($batch = 0; $batch < 50; $batch++) {
				$rows = $wpdb->get_results($wpdb->prepare(
					"SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ({$keys_placeholders}) AND meta_value LIKE 'a:%%' ORDER BY meta_id LIMIT 500",
					$meta_keys
				));
				if (empty($rows)) {
					break;
				}
				foreach ($rows as $row) {
					$repaired = '';
					if (in_array($row->meta_key, $own_keys, true)) {
						$repaired = Metasync_Canonical_Sanitizer::sanitize(maybe_unserialize($row->meta_value));
					}
					if ($repaired !== '') {
						$wpdb->update($wpdb->postmeta, array('meta_value' => $repaired), array('meta_id' => (int) $row->meta_id));
					} else {
						$wpdb->delete($wpdb->postmeta, array('meta_id' => (int) $row->meta_id));
					}
					wp_cache_delete((int) $row->post_id, 'post_meta');
				}
				if (count($rows) < 500) {
					break;
				}
			}
		}

		// 3. Yoast indexable cache: null corrupted canonical columns so the
		// frontend and sitemaps stop serving the bad value immediately.
		$indexable_table = $wpdb->prefix . 'yoast_indexable';
		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($indexable_table))) === $indexable_table) {
			$wpdb->query($wpdb->prepare(
				"UPDATE {$indexable_table} SET canonical = NULL WHERE LOWER(TRIM(TRAILING '/' FROM TRIM(canonical))) IN ({$vals_placeholders})",
				$bad_values
			));
		}

		// 4. AIOSEO custom tables: null corrupted canonical_url columns.
		foreach (array('aioseo_posts', 'aioseo_terms') as $aioseo_table) {
			$table = $wpdb->prefix . $aioseo_table;
			if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) {
				$wpdb->query($wpdb->prepare(
					"UPDATE {$table} SET canonical_url = NULL WHERE LOWER(TRIM(TRAILING '/' FROM TRIM(canonical_url))) IN ({$vals_placeholders})",
					$bad_values
				));
			}
		}

		// 5. Yoast stores term canonicals in the wpseo_taxonomy_meta option,
		// not termmeta. Strip only exact corruption literals.
		if (class_exists('Metasync_Canonical_Sanitizer')) {
			$tax_meta = get_option('wpseo_taxonomy_meta');
			if (is_array($tax_meta)) {
				$changed = false;
				foreach ($tax_meta as $taxonomy => $terms) {
					if (!is_array($terms)) {
						continue;
					}
					foreach ($terms as $term_id => $fields) {
						if (is_array($fields) && isset($fields['wpseo_canonical'])
							&& Metasync_Canonical_Sanitizer::is_corrupted($fields['wpseo_canonical'])) {
							unset($tax_meta[$taxonomy][$term_id]['wpseo_canonical']);
							$changed = true;
						}
					}
				}
				if ($changed) {
					update_option('wpseo_taxonomy_meta', $tax_meta);
				}
			}
		}
	}


}

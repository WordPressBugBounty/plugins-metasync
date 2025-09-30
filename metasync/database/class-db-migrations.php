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

		global $wpdb;
		$collate = $wpdb->get_charset_collate();
		
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Create 404 Monitor Table
		require_once dirname(__FILE__, 2) . '/404-monitor/class-metasync-404-monitor-database.php';
		$tableName = $wpdb->prefix . Metasync_Error_Monitor_Database::$table_name;

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
		$tableNameRedirection = $wpdb->prefix . Metasync_Redirection_Database::$table_name;

		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s ", $tableNameRedirection)) != $tableNameRedirection) {
			$table_sql = "CREATE TABLE {$tableNameRedirection} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				sources_from TEXT NOT NULL,
				url_redirect_to TEXT NOT NULL,
				http_code SMALLINT(4) unsigned NOT NULL,
				hits_count BIGINT(20) unsigned NOT NULL DEFAULT '0',
				status VARCHAR(25) NOT NULL DEFAULT 'active',
				created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				last_accessed_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY id (id),
				KEY status (status)
			) $collate;";

			dbDelta($table_sql);
		}

		// Create HeartBeat Error Monitor Table
		require_once dirname(__FILE__, 2) . '/heartbeat-error-monitor/class-metasync-heartbeat-error-monitor-database.php';
		$tableNameHeartBeatErrorMonitor = $wpdb->prefix . Metasync_HeartBeat_Error_Monitor_Database::$table_name;

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
		$tableNameSyncHistory = $wpdb->prefix . Metasync_Sync_History_Database::$table_name;

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
				KEY created_at (created_at)
			) $collate;";

			dbDelta($table_sql);
		}
	}

	/**
	 * deactivation of migration.
	 */
	public static function deactivation()
	{
		global $wpdb;
		// require_once dirname(__FILE__, 2) . '/404-monitor/class-metasync-404-monitor-database.php';
		// $tableName = $wpdb->prefix . Metasync_Error_Monitor_Database::$table_name;

		/* drop wp_metasync_404_logs table */
		// $sql = "DROP TABLE IF EXISTS `$tableName` ";
		// $wpdb->query($sql);

		// require_once dirname(__FILE__, 2) . '/redirections/class-metasync-redirection-database.php';
		// $tableNameRedirection = $wpdb->prefix . Metasync_Redirection_Database::$table_name;

		/* drop wp_metasync_redirections table */
		// $sql = "DROP TABLE IF EXISTS `$tableNameRedirection` ";
		// $wpdb->query($sql);

		require_once dirname(__FILE__, 2) . '/heartbeat-error-monitor/class-metasync-heartbeat-error-monitor-database.php';
		$tableNameHeartBeatErrorMonitor = $wpdb->prefix . Metasync_HeartBeat_Error_Monitor_Database::$table_name;
		/* drop wp_metasync_redirections table */
		$sql = "DROP TABLE IF EXISTS `$tableNameHeartBeatErrorMonitor` ";
		$wpdb->query($sql);
	}
}

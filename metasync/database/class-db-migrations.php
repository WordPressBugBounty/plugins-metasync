<?php

/**
 * The database migration for the plugin.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/database
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
 */
class DBMigration
{

	/**
	 * activation of migration.
	 */
	public static function activation()
	{

		global $wpdb;
		$collate      = $wpdb->get_charset_collate();
		$table_schema = [];

		require_once dirname(__FILE__, 2) . '/404-monitor/class-metasync-404-monitor-database.php';
		$tableName = $wpdb->prefix . Metasync_Error_Monitor_Database::$table_name;

		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tableName)) != $tableName) {
			$table_schema[] = "CREATE TABLE {$tableName} (
				id BIGINT(20) unsigned NOT NULL AUTO_INCREMENT,
				uri VARCHAR(255) NOT NULL,
				date_time DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				hits_count BIGINT(20) unsigned NOT NULL DEFAULT 1,
				user_agent VARCHAR(255) NOT NULL DEFAULT '',
				PRIMARY KEY id (id),
				KEY uri (uri(191))
			) $collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			foreach ($table_schema as $table) {
				dbDelta($table);
			}
		}

		require_once dirname(__FILE__, 2) . '/redirections/class-metasync-redirection-database.php';
		$tableNameRedirection = $wpdb->prefix . Metasync_Redirection_Database::$table_name;

		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s ", $tableNameRedirection)) != $tableNameRedirection) {
			$table_schema[] = "CREATE TABLE {$tableNameRedirection} (
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

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			foreach ($table_schema as $table) {
				dbDelta($table);
			}
		}

		require_once dirname(__FILE__, 2) . '/heartbeat-error-monitor/class-metasync-heartbeat-error-monitor-database.php';
		$tableNameHeartBeatErrorMonitor = $wpdb->prefix . Metasync_HeartBeat_Error_Monitor_Database::$table_name;

		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s ", $tableNameHeartBeatErrorMonitor)) != $tableNameHeartBeatErrorMonitor) {
			$table_schema[] = "CREATE TABLE {$tableNameHeartBeatErrorMonitor} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				attribute_name VARCHAR(25) NOT NULL DEFAULT '',
				object_count VARCHAR(25) NOT NULL DEFAULT '',
				error_description TEXT NULL,
				created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY id (id)
			) $collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			foreach ($table_schema as $table) {
				dbDelta($table);
			}
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

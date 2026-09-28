<?php

/**
 * The database operations for the 404 error monitor.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/404-monitor
 * @author     Engineering Team <support@searchatlas.com>
 */
class Metasync_HeartBeat_Error_Monitor_Database
{
	public static $table_name = "metasync_heartbeat_error_logs";

	private function get_table_name()
	{
		global $wpdb;
		return $wpdb->prefix . self::$table_name;
	}

	public function getAllRecords()
	{
		global $wpdb;
		return $wpdb->get_results("SELECT * FROM `{$wpdb->prefix}metasync_heartbeat_error_logs`"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository for heartbeat error logs (admin views)
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
				'attribute_name'		=> '',
				'object_count'			=> '',
				'error_description' 	=> '',
				'created_at'  => current_time('mysql'),
			]
		);
		//Maybe delete logs if record exceed defined limit.
		$limit = 30;
		if ($limit && $this->get_count() >= $limit) {
			$this->clear_logs();
		}

		return $wpdb->insert($this->get_table_name(), $args); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository for heartbeat error logs (admin views)
	}


	/**
	 * Get total number of log items (number of rows in the DB table).
	 * @return int
	 */
	public function get_count()
	{
		global $wpdb;
		return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}metasync_heartbeat_error_logs`"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository for heartbeat error logs (admin views)
	}

	/**
	 * Clear logs completely.
	 * @param array $itemsArray
	 * @return int
	 */
	public function delete($items)
	{
		global $wpdb;
		if (!is_array($items) || empty($items)) return;
		$wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom-table repository for heartbeat error logs (admin views)
			'DELETE FROM `' . $wpdb->prefix . 'metasync_heartbeat_error_logs` WHERE `id` IN (' . implode(',', array_fill(0, count($items), '%d')) . ')',
			$items
		));
	}

	/**
	 * Clear logs completely.
	 */
	public function clear_logs()
	{
		global $wpdb;
		$wpdb->query("TRUNCATE TABLE {$wpdb->prefix}metasync_heartbeat_error_logs");
	}
}

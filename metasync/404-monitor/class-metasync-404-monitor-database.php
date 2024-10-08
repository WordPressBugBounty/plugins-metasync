<?php

/**
 * The database operations for the 404 error monitor.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/404-monitor
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
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
		return $wpdb->get_results(" SELECT * FROM `$tableName` ");
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
	 * @param array $args Values to update.
	 */
	public function update($args)
	{
		$row = $this->findByUri($args['uri']);
		if ($row) {
			return $this->update_counter($row);
		}
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

<?php

/**
 * The database operations for the redirections.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/redirections
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
 */
class Metasync_Redirection_Database
{
	public static $table_name = "metasync_redirections";

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

	public function getAllActiveRecords()
	{
		global $wpdb;
		$tableName = $this->get_table_name();
		return $wpdb->get_results($wpdb->prepare("SELECT * FROM `$tableName` WHERE status = %s ", 'active'));
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
				'sources_from'    	=> [],
				'url_redirect_to'   => site_url(),
				'http_code'    		=> '301',
				'hits_count'    	=> '0',
				'status'   			=> 'active',
				'created_at'		=> current_time('mysql'),
			]
		);
		return $wpdb->insert($this->get_table_name(), $args);
	}

	/**
	 * Update a record.
	 * @param array $args Values to update.
	 * @param string $id
	 */
	public function update($args, $id)
	{
		global $wpdb;
		$row = $this->find($id);
		if (!$row) return;
		$wpdb->update($this->get_table_name(), $args, ['id' => $id]);
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
			SET `status` = %s",
			$status
		);
		$where = $wpdb->prepare(
			"
			WHERE `id` IN ( $ids )",
			$items
		);
		$query = "{$set_status}{$where}";
		$wpdb->query($query);
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
	 * Find a record.
	 * @param $value Record to update.
	 */
	public function find($value)
	{
		global $wpdb;
		$tableName = $this->get_table_name();
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM `$tableName` WHERE `id` = %s ", $value));
	}
}

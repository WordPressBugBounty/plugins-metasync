<?php

class Metasync_HeartBeat_Error_Monitor_List_Table extends WP_List_Table
{

	private $records;
	private $db_heartbeat_errors;
	public function __construct()
	{
		// Set parent defaults.
		parent::__construct(array(
			'singular' => 'item',     // Singular name of the listed records.
			'plural'   => 'items',    // Plural name of the listed records.
			'ajax'     => false,       // Does this table support ajax?
		));
	}

	public function setDatabaseResource(&$db_heartbeat_errors)
	{
		$this->db_heartbeat_errors = $db_heartbeat_errors;
		$this->loadRecords();
	}

	private function setRecords($records)
	{
		return $this->records = json_decode(wp_json_encode($records));
	}

	private function loadRecords()
	{
		return $this->setRecords($this->db_heartbeat_errors->getAllRecords());
	}

	public function get_columns()
	{
		$columns = array(
			'cb'       			=> '<input type="checkbox" />', // Render a checkbox instead of text.
			'id'    			=> _x('ID', 'Column label', 'metasync_heartbeat_error_logs'),
			'attribute_name'    => _x('Attribute Name', 'Column label', 'metasync_heartbeat_error_logs'),
			'object_count'    	=> _x('Records', 'Column label', 'metasync_heartbeat_error_logs'),
			'error_description'	=> _x('Description', 'Column label', 'metasync_heartbeat_error_logs'),
			'created_at'		=> _x('Created At', 'Column label', 'metasync_heartbeat_error_logs'),
		);

		return $columns;
	}

	protected function get_sortable_columns()
	{
		$sortable_columns = array(
			'id'    				=> array('id', false),
			'attribute_name'    	=> array('attribute_name', false),
			'object_count'    		=> array('object_count', false),
			'error_description' 	=> array('error_description', false),
			'created_at' 			=> array('created_at', false),
		);

		return $sortable_columns;
	}

	protected function column_default($item, $column_name)
	{
		switch ($column_name) {
			case 'id':
			case 'attribute_name':
			case 'object_count':
			case 'error_description':
			case 'created_at':
				return $item[$column_name];
			default:
				return print_r($item, true); // Show the whole array for troubleshooting purposes.
		}
	}

	protected function column_cb($item)
	{
		return sprintf(
			'<input type="checkbox" name="%1$s[]" value="%2$s" />',
			$this->_args['singular'],  // Let's simply repurpose the table's singular label ("error").
			$item['id']                // The value of the checkbox should be the record's ID.
		);
	}

	protected function column_uri($item)
	{
		$request_data = sanitize_post($_REQUEST); // WPCS: Input var ok.
		if (!isset($request_data['page'])) return;

		// $uri = str_replace(home_url('/'), '', $item['uri']);

		// Build redirect row action.
		// $redirect_query_args = array(
		// 	'page'		=> 'metasync-settings-redirections',
		// 	'action'	=> 'redirect',
		// 	'uri'		=> $uri,
		// );
		// Build delete row action.
		$delete_query_args = array(
			'page'   => sanitize_text_field($request_data['page']),
			'action' => 'delete',
			'id'  => $item['id'],
		);

		// $actions['redirect'] = sprintf(
		// 	'<a href="%1$s">%2$s</a>',
		// 	esc_url(wp_nonce_url(add_query_arg($redirect_query_args, 'admin.php'), 'redirectid_' . $item['id'])),
		// 	_x('Redirect', 'List table row action', 'metasync_heartbeat_error_logs')
		// );

		$actions['delete'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url(wp_nonce_url(add_query_arg($delete_query_args, 'admin.php'), 'deleteid_' . $item['id'])),
			_x('Delete', 'List table row action', 'metasync_heartbeat_error_logs')
		);

		// Return the title contents.
		return sprintf(
			'%1$s %2$s',
			$item['uri'],
			$this->row_actions($actions)
		);
	}

	protected function get_bulk_actions()
	{
		$actions = array(
			'delete_bulk' => _x('Delete', 'List table bulk action', 'metasync_heartbeat_error_logs'),
			'empty' => _x('Empty Table', 'List table bulk action', 'metasync_heartbeat_error_logs'),
		);

		return $actions;
	}

	protected function process_bulk_action()
	{
		$post_data = sanitize_post($_POST);
		$items = isset($post_data['item']) && is_array($post_data['item']) ? array_map('sanitize_title', $post_data['item']) : [];

		if (empty($post_data['item'])) return;

		// Detect when bulk delete action is being triggered.
		if ('delete_bulk' === $this->current_action()) {
			$this->db_heartbeat_errors->delete($items);
		}
		if ('empty' === $this->current_action()) {
			$this->db_heartbeat_errors->clear_logs();
		}
	}

	protected function process_row_action()
	{
		$get_data = sanitize_post($_GET);
		$item = isset($get_data['id']) ? sanitize_text_field($get_data['id']) : '';

		// Detect when row action is being triggered.
		if ('delete' === $this->current_action()) {
			$this->db_heartbeat_errors->delete([$item]);
		}
	}

	function prepare_items()
	{
		$per_page = 20;

		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = $this->get_sortable_columns();
		$this->_column_headers = array($columns, $hidden, $sortable);

		$this->process_bulk_action();
		$this->process_row_action();

		$data = $this->loadRecords();

		usort($data, array($this, 'usort_reorder'));

		$current_page = $this->get_pagenum();
		$total_items = count($data);

		$data = array_slice($data, (($current_page - 1) * $per_page), $per_page);

		$this->items = $data;

		$this->set_pagination_args(array(
			'total_items' => $total_items,                     // WE have to calculate the total number of items.
			'per_page'    => $per_page,                        // WE have to determine how many items to show on a page.
			'total_pages' => ceil($total_items / $per_page), // WE have to calculate the total number of pages.
		));
	}

	protected function usort_reorder($a, $b)
	{
		$request_data = sanitize_post($_REQUEST);
		// If no sort, default to title.
		$orderby = !empty($request_data['orderby']) ? sanitize_sql_orderby($request_data['orderby']) : 'id';
		// If no order, default to asc.
		$order = !empty($request_data['order']) ? sanitize_post($request_data['order']) : 'asc';

		// Determine sort order.
		if ($orderby !== 'id') {
			$result = strcmp($a[$orderby], $b[$orderby]);
		} else {
			$result = strnatcmp($a[$orderby], $b[$orderby]);
		}

		return ('asc' === $order) ? $result : -$result;
	}
}

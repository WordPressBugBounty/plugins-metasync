<?php

/**
 * The Record list for the Redirections.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/redirections
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
 */
class Metasync_Redirection_List_Table extends WP_List_Table
{
	private $records;
	private $db_redirection;
	public function __construct()
	{
		// Set parent defaults.
		parent::__construct(array(
			'singular' => 'item',     // Singular name of the listed records.
			'plural'   => 'items',    // Plural name of the listed records.
			'ajax'     => false,       // Does this table support ajax?
		));
	}

	public function setDatabaseResource(&$db_redirection)
	{
		$this->db_redirection = $db_redirection;
		$this->loadRecords();
	}

	private function setRecords($records)
	{
		return $this->records = json_decode(json_encode($records), true);
	}

	private function loadRecords()
	{
		return $this->setRecords($this->db_redirection->getAllRecords());
	}

	public function get_columns()
	{
		$columns = array(
			'cb'				=> '<input type="checkbox" />', // Render a checkbox instead of text.
			'sources_from'    	=> _x('From', 'Column label', 'metasync-table-redirections'),
			'url_redirect_to'   => _x('To', 'Column label', 'metasync-table-redirections'),
			'http_code'    		=> _x('Type', 'Column label', 'metasync-table-redirections'),
			'hits_count'    	=> _x('Hits', 'Column label', 'metasync-table-redirections'),
			'status'   			=> _x('Status', 'Column label', 'metasync-table-redirections'),
			'last_accessed_at' 	=> _x('Last Accessed', 'Column label', 'metasync-table-redirections'),
		);

		return $columns;
	}

	protected function get_sortable_columns()
	{
		$sortable_columns = array(
			'sources_from'			=> array('sources_from', false),
			'url_redirect_to'    	=> array('url_redirect_to', false),
			'http_code' 			=> array('http_code', false),
			'hits_count' 			=> array('hits_count', false),
			'status' 				=> array('status', false),
			'last_accessed_at' 		=> array('last_accessed_at', false),
		);

		return $sortable_columns;
	}

	protected function column_default($item, $column_name)
	{
		switch ($column_name) {
			case 'sources_from':
			case 'url_redirect_to':
			case 'http_code':
			case 'hits_count':
			case 'status':
			case 'last_accessed_at':
				return $item[$column_name];
			default:
				return print_r($item, true); // Show the whole array for troubleshooting purposes.
		}
	}

	protected function column_cb($item)
	{
		return sprintf(
			'<input type="checkbox" name="%1$s[]" value="%2$s" />',
			$this->_args['plural'],  // Let's simply repurpose the table's singular label ("error").
			$item['id']                // The value of the checkbox should be the record's ID.
		);
	}

	protected function column_sources_from($item)
	{
		$request_data = sanitize_post($_REQUEST); // WPCS: Input var ok.
		if (!isset($request_data['page'])) return;

		// Build delete row action.
		$delete_query_args = array(
			'page'   => sanitize_text_field($request_data['page']),
			'action' => 'delete',
			'id'  => $item['id'],
		);

		// Build edit row action.
		$edit_query_args = array(
			'page'   => sanitize_text_field($request_data['page']),
			'action' => 'edit',
			'id'  => $item['id'],
		);

		// Build activate row action.
		$activate_query_args = array(
			'page'   => sanitize_text_field($request_data['page']),
			'action' => 'activate',
			'id'  => $item['id'],
		);

		// Build deactivate row action.
		$deactivate_query_args = array(
			'page'   => sanitize_text_field($request_data['page']),
			'action' => 'deactivate',
			'id'  => $item['id'],
		);

		$actions['edit'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url(add_query_arg($edit_query_args, 'admin.php')),
			_x('Edit', 'List table row action', 'metasync-table-redirections')
		);

		if ($item['status'] === 'inactive') {

			$actions['activate'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url(add_query_arg($activate_query_args, 'admin.php')),
				_x('Activate', 'List table row action', 'metasync-table-redirections')
			);
		} else {

			$actions['deactivate'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url(add_query_arg($deactivate_query_args, 'admin.php')),
				_x('Deactivate', 'List table row action', 'metasync-table-redirections')
			);
		}

		$actions['delete'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url(add_query_arg($delete_query_args, 'admin.php')),
			_x('Delete', 'List table row action', 'metasync-table-redirections')
		);

		// Return the source from contents.
		return sprintf(
			'%1$s %2$s',
			$this->show_source_from_urls($item),
			$this->row_actions($actions)
		);
	}

	protected function get_bulk_actions()
	{
		$actions = array(
			'delete_bulk' => _x('Delete', 'List table bulk action', 'metasync-table-redirections'),
			'activate_bulk' => _x('Activate', 'List table bulk action', 'metasync-table-redirections'),
			'deactivate_bulk' => _x('Deactivate', 'List table bulk action', 'metasync-table-redirections'),
		);

		return $actions;
	}

	protected function process_bulk_action()
	{
		$post_data = sanitize_post($_POST);
		$items = isset($post_data['items']) && is_array($post_data['items']) ? array_map('sanitize_title', $post_data['items']) : [];

		if (empty($post_data['items'])) return;


		// Detect when bulk delete action is being triggered.
		if ('delete_bulk' === $this->current_action()) {
			$this->db_redirection->delete($items);
		}

		// Detect when bulk activate action is being triggered.
		if ('activate_bulk' === $this->current_action()) {
			$this->db_redirection->update_status($items, 'active');
		}

		// Detect when bulk deactivate action is being triggered.
		if ('deactivate_bulk' === $this->current_action()) {
			$this->db_redirection->update_status($items, 'inactive');
		}
	}

	protected function process_row_action()
	{
		$get_data = sanitize_post($_GET);
		$item = isset($get_data['id']) ? sanitize_text_field($get_data['id']) : '';

		// Detect when a delete action is being triggered.
		if ('delete' === $this->current_action()) {
			$this->db_redirection->delete([$item]);
		}
		// Detect when a activate action is being triggered.
		if ('activate' === $this->current_action()) {
			$this->db_redirection->update_status([$item], 'active');
		}
		// Detect when a deactivate action is being triggered.
		if ('deactivate' === $this->current_action()) {
			$this->db_redirection->update_status([$item], 'inactive');
		}
	}

	function prepare_items()
	{
		$per_page = 10;

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

	private function show_source_from_urls($item)
	{
		$source_urls = unserialize($item['sources_from']);

		foreach ($source_urls as $source_name => $source_type) {
			echo sprintf(
				'<a href="%1$s" target="_blank">%2$s</a>',
				esc_url(site_url() . '/' . $source_name),
				_x($source_name, 'List table row action', 'metasync-table-redirections')
			);
			echo sprintf(
				'<span>%1$s</span>',
				' [' . $source_type . ']'
			);
			echo "<br>";
		}
	}
}

<?php

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}

class Metasync_Error_Monitor_List_Table extends WP_List_Table
{

	private $records;
	private $database;
	public function __construct()
	{
		// Set parent defaults.
		parent::__construct(array(
			'singular' => 'item',     // Singular name of the listed records.
			'plural'   => 'items',    // Plural name of the listed records.
			'ajax'     => false,       // Does this table support ajax?
		));
	}

	/**
	 * Get the current page number for 404 monitor (use separate parameter)
	 */
	public function get_pagenum()
	{
		$pagenum = isset($_REQUEST['paged_404']) ? absint($_REQUEST['paged_404']) : 0;

		if (isset($this->_pagination_args['total_pages']) && $pagenum > $this->_pagination_args['total_pages']) {
			$pagenum = $this->_pagination_args['total_pages'];
		}

		return max(1, $pagenum);
	}

	/**
	 * Override pagination args to preserve tab parameter
	 */
	protected function get_views()
	{
		return array();
	}

	/**
	 * Ensure tab parameter is preserved in pagination links
	 */
	protected function pagination($which)
	{
		if (empty($this->_pagination_args)) {
			return;
		}

		$total_items = $this->_pagination_args['total_items'];
		$total_pages = $this->_pagination_args['total_pages'];
		$infinite_scroll = false;
		if (isset($this->_pagination_args['infinite_scroll'])) {
			$infinite_scroll = $this->_pagination_args['infinite_scroll'];
		}

		if ('top' === $which && $total_pages > 1) {
			$this->screen->render_screen_reader_content('heading_pagination');
		}

		// Add tab parameter to pagination links
		add_filter('removable_query_args', array($this, 'preserve_tab_in_pagination'));

		$output = '<span class="displaying-num">' . sprintf(
			/* translators: %s: Number of items */
			_n('%s item', '%s items', $total_items, 'metasync'),
			number_format_i18n($total_items)
		) . '</span>';

		$current = $this->get_pagenum();
		$removable_query_args = wp_removable_query_args();

		$current_url = set_url_scheme('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);

		$current_url = remove_query_arg($removable_query_args, $current_url);

		// Preserve the tab parameter
		if (isset($_GET['tab'])) {
			$current_url = add_query_arg('tab', sanitize_text_field($_GET['tab']), $current_url);
		}

		$page_links = array();

		$total_pages_before = '<span class="paging-input">';
		$total_pages_after  = '</span></span>';

		$disable_first = $disable_last = $disable_prev = $disable_next = false;

		if ($current == 1) {
			$disable_first = true;
			$disable_prev  = true;
		}
		if ($current == 2) {
			$disable_first = true;
		}
		if ($current == $total_pages) {
			$disable_last = true;
			$disable_next = true;
		}
		if ($current == $total_pages - 1) {
			$disable_last = true;
		}

		if ($disable_first) {
			$page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>';
		} else {
			$page_links[] = sprintf(
				"<a class='first-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
				esc_url(remove_query_arg('paged_404', $current_url)),
				esc_html__('First page'),
				'&laquo;'
			);
		}

		if ($disable_prev) {
			$page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>';
		} else {
			$page_links[] = sprintf(
				"<a class='prev-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
				esc_url(add_query_arg('paged_404', max(1, $current - 1), $current_url)),
				esc_html__('Previous page'),
				'&lsaquo;'
			);
		}

		if ('bottom' === $which) {
			$html_current_page  = $current;
			$total_pages_before = '<span class="screen-reader-text">' . esc_html__('Current Page', 'metasync') . '</span><span id="table-paging" class="paging-input"><span class="tablenav-paging-text">';
		} else {
			$html_current_page = sprintf(
				"%s<input class='current-page' id='current-page-selector-404' type='text' name='paged_404' value='%s' size='%d' aria-describedby='table-paging' /><span class='tablenav-paging-text'>",
				'<label for="current-page-selector-404" class="screen-reader-text">' . esc_html__('Current Page', 'metasync') . '</label>',
				$current,
				strlen($total_pages)
			);
		}
		$html_total_pages = sprintf("<span class='total-pages'>%s</span>", number_format_i18n($total_pages));
		$page_links[]     = $total_pages_before . sprintf(
			_x('%1$s of %2$s', 'paging', 'metasync'),
			$html_current_page,
			$html_total_pages
		) . $total_pages_after;

		if ($disable_next) {
			$page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>';
		} else {
			$page_links[] = sprintf(
				"<a class='next-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
				esc_url(add_query_arg('paged_404', min($total_pages, $current + 1), $current_url)),
				esc_html__('Next page'),
				'&rsaquo;'
			);
		}

		if ($disable_last) {
			$page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
		} else {
			$page_links[] = sprintf(
				"<a class='last-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
				esc_url(add_query_arg('paged_404', $total_pages, $current_url)),
				esc_html__('Last page'),
				'&raquo;'
			);
		}

		$pagination_links_class = 'pagination-links';
		if (!empty($infinite_scroll)) {
			$pagination_links_class .= ' hide-if-js';
		}
		$output .= "\n<span class='$pagination_links_class'>" . join("\n", $page_links) . '</span>';

		if ($total_pages) {
			$page_class = $total_pages < 2 ? ' one-page' : '';
		} else {
			$page_class = ' no-pages';
		}
		$this->_pagination = "<div class='tablenav-pages{$page_class}'>$output</div>";

		echo $this->_pagination;

		remove_filter('removable_query_args', array($this, 'preserve_tab_in_pagination'));
	}

	/**
	 * Preserve tab parameter in pagination
	 */
	public function preserve_tab_in_pagination($args)
	{
		// Remove 'tab' from removable query args so it's preserved
		$key = array_search('tab', $args, true);
		if ($key !== false) {
			unset($args[$key]);
		}

		// Also preserve our pagination parameter
		$key = array_search('paged_404', $args, true);
		if ($key !== false) {
			unset($args[$key]);
		}

		// Remove the other pagination parameters to avoid conflicts
		$args[] = 'paged';
		$args[] = 'paged_redir';

		return array_unique($args);
	}

	public function setDatabaseResource(&$database)
	{
		$this->database = $database;
	}

	private function setRecords($records)
	{
		return $this->records = json_decode(wp_json_encode($records), true);
	}

	private function loadRecords()
	{
		$filters = $this->get_search_filters();
		return $this->setRecords($this->database->search_404_errors($filters));
	}

	private function loadRecordsWithPagination($per_page, $offset)
	{
		$filters = $this->get_search_filters();
		$filters['per_page'] = $per_page;
		$filters['offset'] = $offset;
		return $this->setRecords($this->database->search_404_errors($filters));
	}

	private function getTotalRecords()
	{
		$filters = $this->get_search_filters();
		return $this->database->count_404_errors($filters);
	}

	/**
	 * Get search filters from request
	 */
	private function get_search_filters()
	{
		$filters = [];
		
		if (!empty($_REQUEST['s'])) {
			$filters['search'] = sanitize_text_field($_REQUEST['s']);
		}
		
		if (!empty($_REQUEST['date_from'])) {
			$filters['date_from'] = sanitize_text_field($_REQUEST['date_from']);
		}
		
		if (!empty($_REQUEST['date_to'])) {
			$filters['date_to'] = sanitize_text_field($_REQUEST['date_to']);
		}
		
		if (!empty($_REQUEST['min_hits'])) {
			$filters['min_hits'] = intval($_REQUEST['min_hits']);
		}

		// Use separate orderby/order parameters for 404 monitor
		if (!empty($_REQUEST['orderby_404'])) {
			$filters['order_by'] = sanitize_sql_orderby($_REQUEST['orderby_404']);
		}

		if (!empty($_REQUEST['order_404'])) {
			$filters['order'] = sanitize_text_field($_REQUEST['order_404']);
		}
		
		return $filters;
	}

	public function get_columns()
	{
		$columns = array(
			'cb'       		=> '<input type="checkbox" />', // Render a checkbox instead of text.
			'uri'    		=> _x('URI', 'Column label', 'metasync'),
			'hits_count'    => _x('Hits', 'Column label', 'metasync'),
			'date_time'   	=> _x('Date Time', 'Column label', 'metasync'),
			'user_agent' 	=> _x('User Agent', 'Column label', 'metasync'),
		);

		return $columns;
	}

	protected function get_sortable_columns()
	{
		$sortable_columns = array(
			'uri'    		=> array('uri', false),
			'hits_count' 	=> array('hits_count', false),
			'date_time' 	=> array('date_time', false),
			'user_agent' 	=> array('user_agent', false),
		);

		return $sortable_columns;
	}

	/**
	 * Override to use custom orderby/order parameter names
	 */
	protected function get_orderby()
	{
		return isset($_REQUEST['orderby_404']) ? $_REQUEST['orderby_404'] : '';
	}

	/**
	 * Override to use custom orderby/order parameter names
	 */
	protected function get_order()
	{
		return isset($_REQUEST['order_404']) ? $_REQUEST['order_404'] : '';
	}

	/**
	 * Override column headers to use custom parameter names for sorting
	 */
	public function print_column_headers($with_id = true)
	{
		list($columns, $hidden, $sortable, $primary) = $this->get_column_info();

		$current_url = set_url_scheme('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
		$current_url = remove_query_arg(array('paged_404', 'orderby_404', 'order_404'), $current_url);

		// Preserve tab parameter - force 404-monitor tab
		$current_url = add_query_arg('tab', '404-monitor', $current_url);

		$current_orderby = $this->get_orderby();
		$current_order = $this->get_order();

		foreach ($columns as $column_key => $column_display_name) {
			$class = array('manage-column', "column-$column_key");

			if (in_array($column_key, $hidden, true)) {
				$class[] = 'hidden';
			}

			if ($column_key === $primary) {
				$class[] = 'column-primary';
			}

			// Add check-column class for checkbox column
			if ('cb' === $column_key) {
				$class[] = 'check-column';
			}

			if (isset($sortable[$column_key])) {
				list($orderby, $desc_first) = $sortable[$column_key];

				if ($current_orderby === $orderby) {
					$order = 'asc' === $current_order ? 'desc' : 'asc';
					$class[] = 'sorted';
					$class[] = $current_order;
				} else {
					$order = strtolower($desc_first);
					if (!in_array($order, array('desc', 'asc'), true)) {
						$order = $desc_first ? 'desc' : 'asc';
					}
					$class[] = 'sortable';
					$class[] = 'desc' === $order ? 'asc' : 'desc';
				}

				$column_display_name = sprintf(
					'<a href="%s"><span>%s</span><span class="sorting-indicators"></span></a>',
					esc_url(add_query_arg(array('orderby_404' => $orderby, 'order_404' => $order), $current_url)),
					$column_display_name
				);
			}

			$tag = ('cb' === $column_key) ? 'td' : 'th';
			$scope = ('th' === $tag) ? 'scope="col"' : '';
			$id = $with_id ? "id='$column_key'" : '';

			if (!empty($class)) {
				$class = "class='" . implode(' ', $class) . "'";
			}

			echo "<$tag $scope $id $class>$column_display_name</$tag>";
		}
	}

	protected function column_default($item, $column_name)
	{
		switch ($column_name) {
			case 'uri':
			case 'date_time':
			case 'hits_count':
			case 'user_agent':
				// return $item[$column_name];
				return esc_html($item[$column_name]); # Fixed: Added esc_html() to prevent XSS
			default:
			//	return print_r($item, true); // Show the whole array for troubleshooting purposes.
			return esc_html(print_r($item, true)); # Fixed: Added esc_html() to prevent XSS in debug output
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

		// Extract the path from the full URI
		$uri = $item['uri'];
		if (strpos($uri, 'http') === 0) {
			// If it's a full URL, extract just the path
			$parsed_url = parse_url($uri);
			$uri = isset($parsed_url['path']) ? $parsed_url['path'] : $uri;
		}
		
		// Ensure URI starts with /
		if (!str_starts_with($uri, '/')) {
			$uri = '/' . $uri;
		}

		// Build redirect row action.
		$redirect_query_args = array(
			'page'		=> sanitize_text_field($request_data['page']),
			'tab'		=> 'redirections',
			'action'	=> 'redirect',
			'uri'		=> $uri,
		);
		// Build delete row action.
		$delete_query_args = array(
			'page'   => sanitize_text_field($request_data['page']),
			'action' => 'delete',
			'id'  => $item['id'],
		);

		$actions['redirect'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url(wp_nonce_url(add_query_arg($redirect_query_args, 'admin.php'), 'redirectid_' . $item['id'])),
			_x('Create Redirect', 'List table row action', 'metasync')
		);

		$actions['delete'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url(wp_nonce_url(add_query_arg($delete_query_args, 'admin.php'), 'deleteid_' . $item['id'])),
			_x('Delete', 'List table row action', 'metasync')
		);

		// Return the title contents with better formatting
		return sprintf(
			'%1$s %2$s',
			esc_html($uri),
			$this->row_actions($actions)
		);
	}

	/**
	 * Display hits count column with better formatting
	 */
	protected function column_hits_count($item)
	{
		$hits = intval($item['hits_count']);
		$class = '';
		
		if ($hits >= 10) {
			$class = 'color: #d63638; font-weight: bold;';
		} elseif ($hits >= 5) {
			$class = 'color: #dba617;';
		}
		
		return sprintf(
			'<span style="%s">%d</span>',
			esc_attr($class),
			$hits
		);
	}

	/**
	 * Display date time column with better formatting
	 */
	protected function column_date_time($item)
	{
		$date = strtotime($item['date_time']);
		$time_diff = human_time_diff($date, current_time('timestamp'));
		
		return sprintf(
			'%s<br><small>%s ago</small>',
			esc_html(date('M j, Y g:i A', $date)),
			esc_html($time_diff)
		);
	}

	/**
	 * Display user agent column with truncation
	 */
	protected function column_user_agent($item)
	{
		$user_agent = $item['user_agent'];
		if (strlen($user_agent) > 50) {
			$user_agent = substr($user_agent, 0, 50) . '...';
		}
		
		return sprintf(
			'<span title="%s">%s</span>',
			esc_attr($item['user_agent']),
			esc_html($user_agent)
		);
	}

	protected function get_bulk_actions()
	{
		$actions = array(
			'delete_bulk' => _x('Delete', 'List table bulk action', 'metasync'),
			'empty' => _x('Empty Table', 'List table bulk action', 'metasync'),
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
			$this->database->delete($items);
		}
		if ('empty' === $this->current_action()) {
			$this->database->clear_logs();
		}
	}

	protected function process_row_action()
	{
		$get_data = sanitize_post($_GET);
		$item = isset($get_data['id']) ? sanitize_text_field($get_data['id']) : '';

		// Detect when row action is being triggered.
		if ('delete' === $this->current_action()) {
			$this->database->delete([$item]);
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

		$current_page = $this->get_pagenum();
		$offset = ($current_page - 1) * $per_page;

		// Get total count for pagination
		$total_items = $this->getTotalRecords();

		// Load only the records for the current page (already sorted by database)
		$data = $this->loadRecordsWithPagination($per_page, $offset);

		$this->items = $data;

		$this->set_pagination_args(array(
			'total_items' => $total_items,                     // Total number of items from database
			'per_page'    => $per_page,                        // Items per page
			'total_pages' => ceil($total_items / $per_page), // Total number of pages
		));
	}

}

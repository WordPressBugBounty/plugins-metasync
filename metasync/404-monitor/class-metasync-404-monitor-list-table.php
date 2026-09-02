<?php

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}

class Metasync_Error_Monitor_List_Table extends WP_List_Table
{

	private $records;
	private $database;
	/**
	 * User-resolved results-per-page value for this list (validated against
	 * Metasync_Per_Page_Helper::ALLOWED_VALUES). Cached here so pagination()
	 * can reuse it without re-resolving from the request / user meta.
	 *
	 * @var int
	 */
	private $resolved_per_page = 10;
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

		$output = '<span class="displaying-num">' . sprintf(
			/* translators: %s: Number of items */
			_n('%s item', '%s items', $total_items, 'metasync'),
			number_format_i18n($total_items)
		) . '</span>';

		$current = $this->get_pagenum();
		$current_url = set_url_scheme('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);

		// Register filter to keep `tab`/`paged_404` out of the removable args.
		add_filter('removable_query_args', array($this, 'preserve_tab_in_pagination'));
		$removable_query_args = wp_removable_query_args();
		$current_url = remove_query_arg($removable_query_args, $current_url);
		if (isset($_GET['tab'])) {
			$current_url = add_query_arg('tab', sanitize_text_field($_GET['tab']), $current_url);
		}
		// Rebuild visible list state from the allow-listed request values so
		// filters, search, sorting and page size survive every pagination link.
		$state_keys = array_merge(
			Metasync_Per_Page_Helper::state_params('404_monitor'),
			[Metasync_Per_Page_Helper::request_key('404_monitor')]
		);
		$current_url = remove_query_arg($state_keys, $current_url);
		$current_url = add_query_arg(Metasync_Per_Page_Helper::request_state('404_monitor'), $current_url);

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

		// Inline "rows per page" selector (10/20/50/100), persisted per user.
		// $which keeps the top and bottom selectors' ids distinct.
		$output .= Metasync_Per_Page_Helper::render_selector('404_monitor', $this->resolved_per_page, $which);

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

		// Preserve this list's rows-per-page value across pagination / tab changes.
		$key = array_search(Metasync_Per_Page_Helper::request_key('404_monitor'), $args, true);
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
		
		$request_state = Metasync_Per_Page_Helper::request_state('404_monitor');
		if (!empty($request_state['s_404'])) {
			$filters['search'] = $request_state['s_404'];
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
		return isset($_REQUEST['orderby_404']) ? sanitize_key($_REQUEST['orderby_404']) : '';
	}

	/**
	 * Override to use custom orderby/order parameter names
	 */
	protected function get_order()
	{
		$raw = isset($_REQUEST['order_404']) ? strtolower(sanitize_key($_REQUEST['order_404'])) : '';
		if ($raw === 'desc') {
			return 'desc';
		}
		return 'asc';
	}

	/**
	 * Override column headers to use custom parameter names for sorting
	 */
	public function print_column_headers($with_id = true)
	{
		list($columns, $hidden, $sortable, $primary) = $this->get_column_info();

		$current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : Metasync_Admin::$page_slug;
		$current_url = set_url_scheme('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
		$current_url = remove_query_arg(['_wpnonce', '_wp_http_referer', 'action', 'action2', 'id'], $current_url);
		$current_url = add_query_arg('page', $current_page, $current_url);
		$current_url = add_query_arg('tab', '404-monitor', $current_url);
		$current_url = remove_query_arg('paged_404', $current_url);
		$current_url = add_query_arg(Metasync_Per_Page_Helper::request_state('404_monitor'), $current_url);

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
					esc_html($column_display_name)
				);
			} elseif ('cb' !== $column_key) {
				$column_display_name = esc_html($column_display_name);
			}

			$tag = ('cb' === $column_key) ? 'td' : 'th';
			$scope = ('th' === $tag) ? 'scope="col"' : '';
			$id = $with_id ? "id='" . esc_attr($column_key) . "'" : '';

			if (!empty($class)) {
				$class = "class='" . esc_attr(implode(' ', $class)) . "'";
			}

			echo '<' . esc_attr($tag) . ' ' . $scope . ' ' . $id . ' ' . $class . '>' . $column_display_name . '</' . esc_attr($tag) . '>';
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
		$request_data = metasync_sanitize_input_array($_REQUEST); // WPCS: Input var ok.
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
		// Always link to the redirections page (not the standalone 404-monitor page)
		// so the add-redirection form is available.
		$redirect_query_args = array(
			'page'		=> Metasync_Admin::$page_slug . '-redirections',
			'tab'		=> 'redirections',
			'action'	=> 'redirect',
			'uri'		=> $uri,
		);
		// Build delete row action.
		$delete_query_args = array(
			'page'   => sanitize_text_field($request_data['page']),
			'action' => 'delete_404',
			'id'     => $item['id'],
		);
		if (is_string($request_data['page']) && str_ends_with($request_data['page'], '-redirections')) {
			$delete_query_args['tab'] = '404-monitor';
		}
		$per_page_key = Metasync_Per_Page_Helper::request_key('404_monitor');
		if (isset($request_data[$per_page_key])) {
			$delete_query_args[$per_page_key] = sanitize_text_field($request_data[$per_page_key]);
		}

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

	/**
	 * Override current_action() so bulk actions triggered from the bottom
	 * dropdown (which posts `action2`) are detected alongside the top one
	 * (which posts `action`). WP_List_Table::current_action() only reads
	 * `action`, so without this the bottom Apply is a silent no-op.
	 *
	 * @return string|false
	 */
	public function current_action()
	{
		// A "Filter"/"Search" submit is not a bulk action. WP core's own
		// current_action() short-circuits on filter_action for exactly this
		// reason, and dropping that check makes ticking rows, choosing a bulk
		// action and then clicking Filter silently run the action. The Search
		// submit posts no filter_action, so it needs its own named marker to
		// stop the same silent delete when it shares a form with the dropdown.
		if (!empty($_REQUEST['filter_action']) || !empty($_REQUEST['search_submit'])) {
			return false;
		}

		if (isset($_REQUEST['action']) && '-1' !== $_REQUEST['action'] && '' !== $_REQUEST['action']) {
			return sanitize_text_field(wp_unslash($_REQUEST['action']));
		}
		if (isset($_REQUEST['action2']) && '-1' !== $_REQUEST['action2'] && '' !== $_REQUEST['action2']) {
			return sanitize_text_field(wp_unslash($_REQUEST['action2']));
		}
		return parent::current_action();
	}

	protected function get_bulk_actions()
	{
		// See the matching note in the redirection list table. On the combined
		// Redirections screen both tables render onto one page, so the hidden
		// tab must not emit a second copy of the bulk controls or their
		// duplicate IDs break WP core's common.js wiring.
		//
		// The flag is null on the standalone 404 Monitor page
		// (page=...-404-monitor), where this is the only table present - so
		// bulk actions stay available there.
		if (class_exists('Metasync_Redirections_Admin')
			&& Metasync_Redirections_Admin::$combined_screen_active_tab !== null
			&& Metasync_Redirections_Admin::$combined_screen_active_tab !== '404-monitor') {
			return array();
		}

		$actions = array(
			'delete_bulk' => _x('Delete', 'List table bulk action', 'metasync'),
			'empty' => _x('Empty Table', 'List table bulk action', 'metasync'),
		);

		return $actions;
	}

	protected function process_bulk_action()
	{
		$post_data = metasync_sanitize_input_array($_POST);
		$items = isset($post_data['item']) && is_array($post_data['item']) ? array_map('sanitize_title', $post_data['item']) : [];

		if (empty($post_data['item'])) return;

		$action = $this->current_action();

		// Only the state-changing bulk actions need (and emit) a nonce. Returning
		// early for anything else keeps normal page loads from tripping the check.
		if (!in_array($action, ['delete_bulk', 'empty'], true)) {
			return;
		}

		// Verify the bulk-action nonce emitted by WP_List_Table::display_tablenav()
		// (plural === 'items' → 'bulk-items') and confirm plugin access before any
		// delete / clear-logs. Guards against CSRF on the bulk path.
		check_admin_referer('bulk-items');

		if (!Metasync::current_user_has_plugin_access()) {
			return;
		}

		// Detect when bulk delete action is being triggered.
		if ('delete_bulk' === $action) {
			$this->database->delete($items);
		}
		if ('empty' === $action) {
			$this->database->clear_logs();
		}
	}

	protected function process_row_action()
	{
		// Only the delete row action changes state; bail on anything else so a
		// normal page load never triggers a nonce failure.
		if ('delete_404' !== $this->current_action()) {
			return;
		}

		$get_data = metasync_sanitize_input_array($_GET);
		$item = isset($get_data['id']) ? sanitize_text_field($get_data['id']) : '';

		// Verify the per-item nonce already attached to the Delete link in
		// column_uri() (wp_nonce_url(..., 'deleteid_' . $item['id'])) and confirm
		// plugin access before deleting. The link runs via GET, so without this it
		// is open to CSRF.
		check_admin_referer('deleteid_' . $item);

		if (!Metasync::current_user_has_plugin_access()) {
			return;
		}

		$this->database->delete([$item]);
		$this->redirect_after_row_action();
	}

	protected function redirect_after_row_action()
	{
		$redirect_url = remove_query_arg(['action', 'action2', 'id', '_wpnonce']);

		if (!headers_sent()) {
			wp_safe_redirect($redirect_url);
			exit;
		}

		printf('<script>window.location.replace(%s);</script>', wp_json_encode(esc_url_raw($redirect_url)));
		exit;
	}

	function prepare_items()
	{
		$per_page = Metasync_Per_Page_Helper::resolve('404_monitor', 10);
		$this->resolved_per_page = $per_page;

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
			'total_pages' => (int) ceil($total_items / $per_page), // Total number of pages
		));
	}

}

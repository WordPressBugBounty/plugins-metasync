<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}


/**
 * The Record list for the Redirections.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/redirections
 * @author     Engineering Team <support@searchatlas.com>
 */
class Metasync_Redirection_List_Table extends WP_List_Table
{
	private $records;
	private $db_redirection;
	private $_displayed = false;
	private $_nav_displayed = false;
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
	 * Get the current page number for redirections (use separate parameter)
	 */
	public function get_pagenum()
	{
		$pagenum = isset($_REQUEST['paged_redir']) ? absint($_REQUEST['paged_redir']) : 0;

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
		
		// If filter action was submitted, remove paged_redir to reset to page 1
		if (isset($_POST['filter_action']) || isset($_POST['post-query-submit'])) {
			$current_url = remove_query_arg('paged_redir', $current_url);
		}
		
		// Preserve filter parameters from REQUEST (works for both GET and POST)
		// First, remove all filter parameters from URL, then add them back if they exist
		$filter_params = ['status_filter', 'pattern_filter', 'http_code_filter', 's'];
		foreach ($filter_params as $param) {
			// Always remove first to clear old values
			$current_url = remove_query_arg($param, $current_url);
			// Then add back only if it has a value in REQUEST
			if (!empty($_REQUEST[$param])) {
				$current_url = add_query_arg($param, sanitize_text_field($_REQUEST[$param]), $current_url);
			}
		}

		// Preserve sorting parameters (same approach - remove first, then add if exists)
		$sort_params = ['orderby_redir', 'order_redir'];
		foreach ($sort_params as $param) {
			// Always remove first to clear old values
			$current_url = remove_query_arg($param, $current_url);
			// Then add back only if it has a value in REQUEST
			if (!empty($_REQUEST[$param])) {
				if ($param === 'orderby_redir') {
					$current_url = add_query_arg($param, sanitize_sql_orderby($_REQUEST[$param]), $current_url);
				} else {
					$current_url = add_query_arg($param, sanitize_text_field($_REQUEST[$param]), $current_url);
				}
			}
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
				esc_url(remove_query_arg('paged_redir', $current_url)),
				esc_html__('First page'),
				'&laquo;'
			);
		}

		if ($disable_prev) {
			$page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>';
		} else {
			$page_links[] = sprintf(
				"<a class='prev-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
				esc_url(add_query_arg('paged_redir', max(1, $current - 1), $current_url)),
				esc_html__('Previous page'),
				'&lsaquo;'
			);
		}

		if ('bottom' === $which) {
			$html_current_page  = $current;
			$total_pages_before = '<span class="screen-reader-text">' . esc_html__('Current Page', 'metasync') . '</span><span id="table-paging" class="paging-input"><span class="tablenav-paging-text">';
		} else {
			$html_current_page = sprintf(
				"%s<input class='current-page' id='current-page-selector-redir' type='text' name='paged_redir' value='%s' size='%d' aria-describedby='table-paging' /><span class='tablenav-paging-text'>",
				'<label for="current-page-selector-redir" class="screen-reader-text">' . esc_html__('Current Page', 'metasync') . '</label>',
				$current,
				strlen($total_pages)
			);
		}
		$html_total_pages = sprintf("<span class='total-pages'>%s</span>", number_format_i18n($total_pages));
		$page_links[]     = $total_pages_before . sprintf(
			/* translators: 1: Current page, 2: Total pages */
			_x('%1$s of %2$s', 'paging', 'metasync'),
			$html_current_page,
			$html_total_pages
		) . $total_pages_after;

		if ($disable_next) {
			$page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>';
		} else {
			$page_links[] = sprintf(
				"<a class='next-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
				esc_url(add_query_arg('paged_redir', min($total_pages, $current + 1), $current_url)),
				esc_html__('Next page'),
				'&rsaquo;'
			);
		}

		if ($disable_last) {
			$page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
		} else {
			$page_links[] = sprintf(
				"<a class='last-page button' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
				esc_url(add_query_arg('paged_redir', $total_pages, $current_url)),
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
		$key = array_search('paged_redir', $args, true);
		if ($key !== false) {
			unset($args[$key]);
		}

		// Remove the other pagination parameters to avoid conflicts
		$args[] = 'paged';
		$args[] = 'paged_404';

		return array_unique($args);
	}

	public function setDatabaseResource(&$db_redirection)
	{
		$this->db_redirection = $db_redirection;
	}

	private function setRecords($records)
	{
		return $this->records = json_decode(json_encode($records), true);
	}

	private function loadRecords()
	{
		$filters = $this->get_search_filters();
		return $this->setRecords($this->db_redirection->search_redirections($filters));
	}

	private function loadRecordsWithPagination($per_page, $offset)
	{
		$filters = $this->get_search_filters();
		$filters['per_page'] = $per_page;
		$filters['offset'] = $offset;
		return $this->setRecords($this->db_redirection->search_redirections($filters));
	}

	private function getTotalRecords()
	{
		$filters = $this->get_search_filters();
		return $this->db_redirection->count_redirections($filters);
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
		
		if (!empty($_REQUEST['status_filter'])) {
			$filters['status'] = sanitize_text_field($_REQUEST['status_filter']);
		}
		
		if (!empty($_REQUEST['pattern_filter'])) {
			$filters['pattern_type'] = sanitize_text_field($_REQUEST['pattern_filter']);
		}
		
		if (!empty($_REQUEST['http_code_filter'])) {
			$filters['http_code'] = intval($_REQUEST['http_code_filter']);
		}

		// Use separate orderby/order parameters for redirections
		if (!empty($_REQUEST['orderby_redir'])) {
			$filters['order_by'] = sanitize_sql_orderby($_REQUEST['orderby_redir']);
		}

		if (!empty($_REQUEST['order_redir'])) {
			$filters['order'] = sanitize_text_field($_REQUEST['order_redir']);
		}
		
		return $filters;
	}

	public function get_columns()
	{
		$columns = array(
			'cb'				=> '<input type="checkbox" />', // Render a checkbox instead of text.
			'sources_from'    	=> _x('From', 'Column label', 'metasync'),
			'url_redirect_to'   => _x('To', 'Column label', 'metasync'),
			'http_code'    		=> _x('Type', 'Column label', 'metasync'),
			'pattern_type'		=> _x('Pattern', 'Column label', 'metasync'),
			'hits_count'    	=> _x('Hits', 'Column label', 'metasync'),
			'status'   			=> _x('Status', 'Column label', 'metasync'),
			'last_accessed_at' 	=> _x('Last Accessed', 'Column label', 'metasync'),
		);

		return $columns;
	}

	protected function get_sortable_columns()
	{
		$sortable_columns = array(
			'sources_from'			=> array('sources_from', false),
			'url_redirect_to'    	=> array('url_redirect_to', false),
			'http_code' 			=> array('http_code', false),
			'pattern_type'			=> array('pattern_type', false),
			'hits_count' 			=> array('hits_count', false),
			'status' 				=> array('status', false),
			'last_accessed_at' 		=> array('last_accessed_at', false),
		);

		return $sortable_columns;
	}

	/**
	 * Override to use custom orderby/order parameter names
	 */
	protected function get_orderby()
	{
		return isset($_REQUEST['orderby_redir']) ? $_REQUEST['orderby_redir'] : '';
	}

	/**
	 * Override to use custom orderby/order parameter names
	 */
	protected function get_order()
	{
		return isset($_REQUEST['order_redir']) ? $_REQUEST['order_redir'] : '';
	}

	/**
	 * Override column headers to use custom parameter names for sorting
	 */
	public function print_column_headers($with_id = true)
	{
		list($columns, $hidden, $sortable, $primary) = $this->get_column_info();

		$current_url = set_url_scheme('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
		$current_url = remove_query_arg(array('paged_redir', 'orderby_redir', 'order_redir'), $current_url);

		// Preserve tab parameter - force redirections tab
		$current_url = add_query_arg('tab', 'redirections', $current_url);

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
					esc_url(add_query_arg(array('orderby_redir' => $orderby, 'order_redir' => $order), $current_url)),
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
			case 'sources_from':
			case 'url_redirect_to':
			case 'http_code':
			case 'hits_count':
			case 'status':
			case 'last_accessed_at':
				return esc_html($item[$column_name]); # Fixed: Added esc_html() to prevent XSS
			case 'pattern_type':
				return $this->column_pattern_type($item);
			default:
				# return print_r($item, true); // Show the whole array for troubleshooting purposes.
				return esc_html(print_r($item, true)); # Fixed: Added esc_html() to prevent XSS in debug output
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
			_x('Edit', 'List table row action', 'metasync')
		);

		if ($item['status'] === 'inactive') {

			$actions['activate'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url(add_query_arg($activate_query_args, 'admin.php')),
				_x('Activate', 'List table row action', 'metasync')
			);
		} else {

			$actions['deactivate'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url(add_query_arg($deactivate_query_args, 'admin.php')),
				_x('Deactivate', 'List table row action', 'metasync')
			);
		}

		$actions['delete'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url(add_query_arg($delete_query_args, 'admin.php')),
			_x('Delete', 'List table row action', 'metasync')
		);

		// Return the source from contents.
		return sprintf(
			'%1$s %2$s',
			$this->show_source_from_urls($item),
			$this->row_actions($actions)
		);
	}

	/**
	 * Display pattern type column with user-friendly labels
	 */
	protected function column_pattern_type($item)
	{
		$pattern_type = isset($item['pattern_type']) ? $item['pattern_type'] : 'exact';
		$regex_pattern = isset($item['regex_pattern']) ? $item['regex_pattern'] : '';

		// Pattern type labels
		$labels = [
			'exact' => 'Exact Match',
			'contain' => 'Contains',
			'start' => 'Starts With',
			'end' => 'Ends With',
			'regex' => 'Regex Pattern'
		];

		$label = isset($labels[$pattern_type]) ? $labels[$pattern_type] : ucfirst($pattern_type);

		// Add regex pattern as tooltip if available
		if ($pattern_type === 'regex' && !empty($regex_pattern)) {
			// Validate regex pattern
			$is_valid = @preg_match($regex_pattern, '');

			if ($is_valid === false) {
				// Invalid regex - show red warning icon
				return sprintf(
					'<span style="display: flex; align-items: center; gap: 5px;">
						<span class="dashicons dashicons-warning" style="color: #dc3232; font-size: 18px;" title="Invalid Regex Pattern: %s"></span>
						<span title="Invalid regex pattern">%s</span>
					</span>',
					esc_attr($regex_pattern),
					esc_html($label)
				);
			}

			// Valid regex - show with tooltip
			return sprintf(
				'<span title="%s">%s</span>',
				esc_attr($regex_pattern),
				esc_html($label)
			);
		}

		return esc_html($label);
	}

	protected function get_bulk_actions()
	{
		$actions = array(
			'delete_bulk' => _x('Delete', 'List table bulk action', 'metasync'),
			'activate_bulk' => _x('Activate', 'List table bulk action', 'metasync'),
			'deactivate_bulk' => _x('Deactivate', 'List table bulk action', 'metasync'),
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

	/**
	 * Override display method to prevent duplicate table rendering
	 */
	public function display()
	{
		// Only display the table once
		if (isset($this->_displayed) && $this->_displayed) {
			return;
		}
		
		$this->_displayed = true;
		
		// Call parent display method
		parent::display();
	}

	/**
	 * Override display_tablenav to prevent duplicate navigation
	 */
	protected function display_tablenav($which)
	{
		// Only display navigation once
		if (isset($this->_nav_displayed) && $this->_nav_displayed) {
			return;
		}
		
		if ($which === 'top') {
			$this->_nav_displayed = true;
		}
		
		parent::display_tablenav($which);
	}

	private function show_source_from_urls($item)
	{
		# $source_urls = unserialize($item['sources_from']);
		# Unserialize the sources_from field
		$source_urls = @unserialize($item['sources_from']);
		
		# If unserialize failed or returned non-array, try to handle it
		if (!is_array($source_urls)) {
			# Fallback: display raw value (shouldn't happen normally)
			echo esc_html($item['sources_from']);
			return;
		}

		$pattern_type = isset($item['pattern_type']) ? $item['pattern_type'] : 'exact';
		$regex_pattern = isset($item['regex_pattern']) ? $item['regex_pattern'] : '';

		foreach ($source_urls as $source_name => $source_type) {

			# Clean the source name in case it contains serialized data
			$clean_source = $source_name;
			
			# Check if source_name itself is serialized (edge case)
			if (is_string($source_name) && strpos($source_name, 'a:') === 0) {
				$unserialized = @unserialize($source_name);
				if (is_array($unserialized) && isset($unserialized[0]['pattern'])) {
					$clean_source = $unserialized[0]['pattern'];
				}
			}

			// For regex patterns, show the regex pattern instead of the label
			if ($pattern_type === 'regex' && !empty($regex_pattern)) {
				$display_text = $regex_pattern;
				// Build the full URL for the link (use the actual pattern for regex)
				$full_url = '#'; // No direct link for regex patterns

				// Validate regex pattern
				$is_valid = @preg_match($regex_pattern, '');
				$warning_icon = '';

				if ($is_valid === false) {
					// Invalid regex - add red warning icon
					$warning_icon = '<span class="dashicons dashicons-warning" style="color: #dc3232; font-size: 16px; vertical-align: middle; margin-right: 3px;" title="Invalid Regex Pattern"></span>';
				}

				echo sprintf(
					'<span title="Label: %3$s">%1$s%2$s</span>',
					$warning_icon,
					esc_html($display_text),
					esc_attr($clean_source)
				);
			} else {
				// Build the full URL for the link
				# $full_url = $this->build_full_url($source_name);
				$full_url = $this->build_full_url($clean_source);

				echo sprintf(
					'<a href="%1$s" target="_blank">%2$s</a>',
					esc_url($full_url),
					# esc_html(_x($source_name, 'List table row action', 'metasync')) # Fixed: Added esc_html() to prevent XSS
					esc_html($clean_source)
				);
			}
			echo sprintf(
				# '<span>%1$s</span>',
				# ' [' . $source_type . ']'
				# ' [' . esc_html($source_type) . ']' # Fixed: Added esc_html() to prevent XSS
				'<span style="color: #666; font-size: 0.9em;">%1$s</span>',
				' [' . esc_html($source_type) . ']'
			);
			echo "<br>";
		}
	}

	/**
	 * Build full URL from source name, handling different formats
	 */
	private function build_full_url($source_name)
	{
		// If it's already a full URL, return as-is
		if (strpos($source_name, 'http') === 0) {
			return $source_name;
		}
		
		// If it starts with /, it's an absolute path
		if (strpos($source_name, '/') === 0) {
			return site_url() . $source_name;
		}
		
		// Otherwise, it's a relative path
		return site_url() . '/' . $source_name;
	}


	/**
	 * Display HTTP code column with better formatting
	 */
	protected function column_http_code($item)
	{
		$http_code = $item['http_code'];
		$code_labels = [
			'301' => '301 Permanent',
			'302' => '302 Temporary',
			'307' => '307 Temporary',
			'410' => '410 Gone',
			'451' => '451 Unavailable'
		];
		
		$label = isset($code_labels[$http_code]) ? $code_labels[$http_code] : $http_code;
		
		$class = '';
		if ($http_code >= 300 && $http_code < 400) {
			$class = 'color: #0073aa;';
		} elseif ($http_code >= 400) {
			$class = 'color: #d63638;';
		}
		
		return sprintf(
			'<span style="%s">%s</span>',
			esc_attr($class),
			esc_html($label)
		);
	}
}

<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * SEO Health List Table
 *
 * Displays all posts/pages with their SEO completeness indicators.
 * Uses CSS classes (not inline styles) for dark-theme compatibility.
 *
 * @since      2.6.1
 * @package    Metasync
 * @subpackage Metasync/admin
 */
class Metasync_SEO_Health_List_Table extends WP_List_Table
{
	/** Nonce action for bulk actions. */
	const NONCE_ACTION = 'metasync_seo_health_bulk';

	/** Optimal SEO title length range; outside it the cell highlights and the length filters match. */
	const SEO_TITLE_MIN = 30;
	const SEO_TITLE_MAX = 60;

	/** Optimal meta description length range; outside it the cell highlights and the length filters match. */
	const META_DESCRIPTION_MIN = 120;
	const META_DESCRIPTION_MAX = 160;

	/**
	 * Filters whose PHP predicates read post meta only. The ID scan skips
	 * post-object priming for these — meta alone decides the match, so the
	 * per-ID single-post queries the priming avoids would never run anyway.
	 */
	const META_ONLY_FILTERS = array('missing_schema');

	public function __construct()
	{
		parent::__construct(array(
			'singular' => 'seo-health-item',
			'plural'   => 'seo-health-items',
			'ajax'     => false,
		));
	}

	public function get_columns()
	{
		return array(
			'cb'                 => '<input type="checkbox" />',
			'title'              => _x('Title', 'Column label', 'metasync'),
			'post_type'          => _x('Post Type', 'Column label', 'metasync'),
			'status'             => _x('Status', 'Column label', 'metasync'),
			'seo_title'          => _x('SEO Title', 'Column label', 'metasync'),
			'meta_description'   => _x('Meta Description', 'Column label', 'metasync'),
			'schema'             => _x('Schema', 'Column label', 'metasync'),
			'last_modified'      => _x('Last Modified', 'Column label', 'metasync'),
		);
	}

	protected function get_sortable_columns()
	{
		return array(
			'title'         => array('title', false),
			'post_type'     => array('post_type', false),
			'status'        => array('post_status', false),
			'last_modified' => array('post_modified', false),
		);
	}

	protected function column_cb($item)
	{
		return sprintf(
			'<input type="checkbox" name="post_ids[]" value="%s" />',
			absint($item->ID)
		);
	}

	protected function column_default($item, $column_name)
	{
		switch ($column_name) {
			case 'title':
				return sprintf(
					'<a href="%s"><strong>%s</strong></a>',
					esc_url(get_edit_post_link($item->ID)),
					esc_html($item->post_title)
				);

			case 'post_type':
				$pt_obj = get_post_type_object($item->post_type);
				return esc_html($pt_obj ? $pt_obj->labels->singular_name : $item->post_type);

			case 'status':
				return esc_html(ucfirst($item->post_status));

			case 'seo_title':
				return $this->render_seo_meta_column($item->ID, 'title', self::SEO_TITLE_MIN, self::SEO_TITLE_MAX);

			case 'meta_description':
				return $this->render_seo_meta_column($item->ID, 'description', self::META_DESCRIPTION_MIN, self::META_DESCRIPTION_MAX);

			case 'schema':
				return Metasync_SEO_Health::has_schema($item->ID)
					? '<span class="seo-status-set optimal">&#10003; Yes</span>'
					: '<span class="seo-status-missing">&#9888; Missing</span>';
			case 'last_modified':
				return esc_html(get_the_modified_date('Y-m-d', $item));

			default:
				return '';
		}
	}

	/**
	 * Render an SEO meta column (title or description) with fallback and length indicator.
	 *
	 * The resolver still reports which tier supplied the value, but the column
	 * deliberately ignores it: the table answers whether the field is set, not
	 * which internal source filled it. Naming one source means naming them all
	 * — OTTO, OG, Yoast, Rank Math, AIOSEO — and a row of competing labels
	 * reads as noise to a customer who only owns one of them.
	 */
	private function render_seo_meta_column($post_id, $field, $min_optimal, $max_optimal)
	{
		$result = Metasync_SEO_Health::get_seo_meta_with_fallback($post_id, $field);

		if (empty($result['value'])) {
			return '<span class="seo-status-missing">&#9888; Missing</span>';
		}

		$length = mb_strlen($result['value']);
		$quality = ($length >= $min_optimal && $length <= $max_optimal) ? 'optimal' : 'suboptimal';

		return sprintf(
			'<span class="seo-status-set %s" title="%s">&#10003; %d chars</span>',
			$quality,
			esc_attr($result['value']),
			$length
		);
	}

	protected function get_bulk_actions()
	{
		return array(
			/* translators: %s: effective plugin name (whitelabel-aware). */
			'clear_metasync_cache' => sprintf(_x('Clear %s cache for selected', 'List table bulk action', 'metasync'), Metasync::get_effective_plugin_name()),
		);
	}

	protected function process_bulk_action()
	{
		if (!$this->current_action()) {
			return;
		}

		check_admin_referer(self::NONCE_ACTION, '_wpnonce_bulk');

		$post_ids = isset($_REQUEST['post_ids']) && is_array($_REQUEST['post_ids'])
			? array_map('absint', $_REQUEST['post_ids'])
			: array();

		if (empty($post_ids)) {
			return;
		}

		if ('clear_metasync_cache' === $this->current_action()) {
			if (class_exists('Metasync_Cache_Purge')) {
				foreach ($post_ids as $post_id) {
					$url = get_permalink($post_id);
					if ($url) {
						Metasync_Cache_Purge::purge_single_url($url);
					}
				}
			}
		}
	}

	/**
	 * Output the nonce field for bulk actions.
	 * Called automatically by WP_List_Table::display().
	 */
	protected function extra_tablenav($which)
	{
		if ($which === 'top') {
			wp_nonce_field(self::NONCE_ACTION, '_wpnonce_bulk');
			?>
			<div class="alignleft actions">
				<?php $this->render_filter_dropdown('post_type_filter', $this->get_post_type_options(), __('All Post Types', 'metasync')); ?>
				<?php $this->render_filter_dropdown('status_filter', $this->get_status_options(), __('All Statuses', 'metasync')); ?>
				<?php $this->render_filter_dropdown('missing_filter', $this->get_missing_options(), __('All Items', 'metasync')); ?>
				<?php submit_button(__('Filter', 'metasync'), '', 'filter_action', false); ?>
			</div>
			<?php
		}
	}

	/**
	 * Render a single filter dropdown.
	 */
	private function render_filter_dropdown($name, $options, $default_label)
	{
		$current = isset($_GET[$name]) ? sanitize_text_field(wp_unslash($_GET[$name])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, allow-listed filter and sort values for the list query
		?>
		<select name="<?php echo esc_attr($name); ?>">
			<option value=""><?php echo esc_html($default_label); ?></option>
			<?php foreach ($options as $value => $label): ?>
				<option value="<?php echo esc_attr($value); ?>" <?php selected($current, $value); ?>>
					<?php echo esc_html($label); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private function get_post_type_options()
	{
		$options = array();
		foreach (Metasync_SEO_Health::get_supported_post_types() as $pt) {
			$pt_obj = get_post_type_object($pt);
			if ($pt_obj) {
				$options[$pt] = $pt_obj->labels->singular_name;
			}
		}
		return $options;
	}

	private function get_status_options()
	{
		return array(
			'publish' => __('Published', 'metasync'),
			'draft'   => __('Draft', 'metasync'),
		);
	}

	private function get_missing_options()
	{
		return array(
			'missing_title'         => __('Missing SEO Title', 'metasync'),
			'missing_description'   => __('Missing Description', 'metasync'),
			'missing_schema'        => __('Missing Schema', 'metasync'),
			'seo_title_too_short'   => __('SEO Title Too Short', 'metasync'),
			'seo_title_too_long'    => __('SEO Title Too Long', 'metasync'),
			'description_too_short' => __('Description Too Short', 'metasync'),
			'description_too_long'  => __('Description Too Long', 'metasync'),
		);
	}

	public function prepare_items()
	{
		$per_page = 20;

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);

		$this->process_bulk_action();

		$current_page = $this->get_pagenum();
		$missing_filter = isset($_GET['missing_filter']) ? sanitize_text_field(wp_unslash($_GET['missing_filter'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, allow-listed filter and sort values for the list query

		$args = array(
			'post_status' => array('publish', 'draft'),
			'orderby'     => 'date',
			'order'       => 'DESC',
		);

		// Post type filter — validate against allowed types
		$post_type_filter = isset($_GET['post_type_filter']) ? sanitize_text_field(wp_unslash($_GET['post_type_filter'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, allow-listed filter and sort values for the list query
		$supported_types = Metasync_SEO_Health::get_supported_post_types();
		$args['post_type'] = (!empty($post_type_filter) && in_array($post_type_filter, $supported_types, true))
			? $post_type_filter
			: $supported_types;

		// Status filter — validate against allowed statuses
		$status_filter = isset($_GET['status_filter']) ? sanitize_text_field(wp_unslash($_GET['status_filter'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, allow-listed filter and sort values for the list query
		if (!empty($status_filter) && in_array($status_filter, array('publish', 'draft'), true)) {
			$args['post_status'] = $status_filter;
		}

		// Search
		$search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, allow-listed filter and sort values for the list query
		if (!empty($search)) {
			$args['s'] = $search;
		}

		// Sorting — validate orderby against allowed columns
		$allowed_orderby = array('title', 'post_type', 'post_status', 'post_modified', 'date');
		$orderby = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, allow-listed filter and sort values for the list query
		$order = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, allow-listed filter and sort values for the list query
		if (!empty($orderby) && in_array($orderby, $allowed_orderby, true)) {
			$args['orderby'] = $orderby;
			$args['order'] = (!empty($order) && in_array(strtoupper($order), array('ASC', 'DESC'), true))
				? strtoupper($order)
				: 'ASC';
		}

		// Always exclude custom/LPS pages — their SEO is self-managed inside their
		// own HTML bundle, so they would otherwise show as false "not set".
		$args['metasync_exclude_custom_pages'] = true;
		add_filter('posts_where', 'metasync_filter_exclude_custom_pages_where', 10, 2);

		if ($this->uses_optimized_missing_meta_filter($missing_filter)) {
			// Missing title/description stay a single paginated query with a
			// correlated anti-existence condition — pure key presence needs no
			// PHP evaluation.
			$args['posts_per_page'] = $per_page;
			$args['paged'] = $current_page;
			$args['metasync_seo_health_missing_filter'] = $missing_filter;

			add_filter('posts_where', array($this, 'filter_missing_meta_where'), 10, 2);
			try {
				$query = new WP_Query($args);
			} finally {
				remove_filter('posts_where', array($this, 'filter_missing_meta_where'), 10);
				remove_filter('posts_where', 'metasync_filter_exclude_custom_pages_where', 10);
			}

			$this->items = $query->posts;
			$total_items = $query->found_posts;
		} elseif ($this->uses_php_post_filter($missing_filter)) {
			try {
				// Schema presence and length checks must run the exact code the
				// row renderer runs, so they filter in PHP after the query. Scan
				// IDs in bounded chunks so a large store never loads every post
				// object at once, cache the matching IDs so pagination and
				// re-visits don't rescan, then fetch only the current page window.
				$ids_cache_key = Metasync_SEO_Health::matching_ids_transient_key(
					$missing_filter,
					$args['post_type'],
					$args['post_status'],
					isset($args['s']) ? $args['s'] : ''
				);
				$matching_ids = get_transient($ids_cache_key);

				if (!is_array($matching_ids)) {
					$matching_ids = $this->scan_matching_ids($args, $missing_filter);
					set_transient($ids_cache_key, $matching_ids, Metasync_SEO_Health::IDS_CACHE_TTL);
				}
			} finally {
				remove_filter('posts_where', 'metasync_filter_exclude_custom_pages_where', 10);
			}

			$total_items = count($matching_ids);
			$this->items = empty($matching_ids)
				? array()
				: get_posts(array(
					'post_type'              => $args['post_type'],
					'post_status'            => $args['post_status'],
					'post__in'               => $matching_ids,
					'posts_per_page'         => $per_page,
					'paged'                  => $current_page,
					// The ID tiebreaker keeps pagination stable when the sort
					// key ties: with no_found_rows MySQL may pick a
					// priority-queue sort whose tie order is undocumented,
					// which skips and duplicates rows across pages.
					'orderby'                => $args['orderby'] . ' ID',
					'order'                  => $args['order'],
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
				));
		} else {
			try {
				// No missing filter (or a stale/unrecognized value) — standard paginated query.
				$args['posts_per_page'] = $per_page;
				$args['paged'] = $current_page;

				$query = new WP_Query($args);
				$this->items = $query->posts;
				$total_items = $query->found_posts;
			} finally {
				remove_filter('posts_where', 'metasync_filter_exclude_custom_pages_where', 10);
			}
		}

		$this->set_pagination_args(array(
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => ceil($total_items / $per_page),
		));
	}

	/**
	 * Whether a missing filter can use the single-query anti-existence path.
	 *
	 * @param string $filter The missing_filter value.
	 * @return bool
	 */
	private function uses_optimized_missing_meta_filter($filter)
	{
		return in_array($filter, array('missing_title', 'missing_description'), true);
	}

	/**
	 * Whether a filter needs the shared PHP predicates the row renderer uses
	 * (schema presence, resolved-value lengths) and so must filter after the
	 * query instead of inside it.
	 *
	 * @param string $filter The missing_filter value.
	 * @return bool
	 */
	private function uses_php_post_filter($filter)
	{
		return in_array($filter, array(
			'missing_schema',
			'seo_title_too_short',
			'seo_title_too_long',
			'description_too_short',
			'description_too_long',
		), true);
	}

	/**
	 * Scan the filtered population in ascending-ID chunks and return every ID
	 * that matches the PHP predicate for the given filter.
	 *
	 * This scan is the expensive half of the PHP-filter path — one query per
	 * chunk plus priming — so it runs once per filter+population and the caller
	 * caches the result. Each chunk's primed data is evicted again once its
	 * predicates have run, keeping the scan's object-cache footprint at one
	 * chunk no matter how large the store is.
	 *
	 * @param array  $args   Population args (post types, statuses, search).
	 * @param string $filter The missing_filter value.
	 * @return int[] Matching post IDs in ascending ID order.
	 */
	private function scan_matching_ids(array $args, $filter)
	{
		$chunk_size = 500;
		$scan_args = $args;
		unset($scan_args['orderby'], $scan_args['order']);
		$scan_args['posts_per_page'] = $chunk_size;
		$scan_args['orderby'] = 'ID';
		$scan_args['order'] = 'ASC';
		$scan_args['fields'] = 'ids';
		$scan_args['no_found_rows'] = true;
		$scan_args['update_post_meta_cache'] = false;
		$scan_args['update_post_term_cache'] = false;

		$matching_ids = array();
		$last_id = 0;
		add_filter('posts_where', array('Metasync_SEO_Inventory_Builder', 'filter_where_id_gt'), 10, 2);

		try {
			while (true) {
				if ($last_id > 0) {
					$scan_args['where_id_gt'] = $last_id;
				}

				$query = new WP_Query($scan_args);
				$chunk_ids = $query->posts;

				if (empty($chunk_ids)) {
					break;
				}

				update_meta_cache('post', $chunk_ids);

				// Prime post objects for the length predicates — they resolve
				// permalinks, which would otherwise cost one single-post query
				// per ID in the chunk. Meta-only filters skip the priming.
				if (!in_array($filter, self::META_ONLY_FILTERS, true)) {
					_prime_post_caches($chunk_ids, false, false);
				}

				foreach ($chunk_ids as $post_id) {
					if ($this->post_matches_filter((int) $post_id, $filter)) {
						$matching_ids[] = (int) $post_id;
					}
					$last_id = (int) $post_id;
				}

				// Drop the chunk's posts and meta again — only the matching
				// IDs are allowed to leave the scan.
				wp_cache_delete_multiple($chunk_ids, 'posts');
				wp_cache_delete_multiple($chunk_ids, 'post_meta');

				if (count($chunk_ids) < $chunk_size) {
					break;
				}
			}
		} finally {
			remove_filter('posts_where', array('Metasync_SEO_Inventory_Builder', 'filter_where_id_gt'), 10);
		}

		return $matching_ids;
	}

	/**
	 * Add the prepared anti-existence predicate for missing filters.
	 *
	 * The key maps come from the shared precedence resolver, so the
	 * optimized query checks exactly the same active-plugin and fallback keys as
	 * the row renderer. Empty values remain missing because the resolver treats
	 * only a non-empty value as set.
	 *
	 * @param string   $where Existing WHERE clause.
	 * @param WP_Query $query Current query.
	 * @return string
	 */
	public function filter_missing_meta_where($where, $query)
	{
		$filter = $query->get('metasync_seo_health_missing_filter');
		if (!$this->uses_optimized_missing_meta_filter($filter)) {
			return $where;
		}

		return $where . self::build_missing_meta_filter_where($filter);
	}

	/**
	 * Build correlated NOT EXISTS condition for a missing filter.
	 *
	 * @param string $filter The missing_filter value.
	 * @return string Prepared SQL, or an empty string for unsupported filters.
	 */
	private static function build_missing_meta_filter_where($filter)
	{
		global $wpdb;

		$keys = self::get_missing_meta_keys($filter);
		if (empty($keys)) {
			return '';
		}

		return $wpdb->prepare(
			" AND NOT EXISTS (\n"
				. "\tSELECT 1\n"
				. "\tFROM {$wpdb->postmeta} AS metasync_seo_health_meta\n"
				. "\tWHERE metasync_seo_health_meta.post_id = {$wpdb->posts}.ID\n"
				. "\t\tAND metasync_seo_health_meta.meta_key IN (" . implode(', ', array_fill(0, count($keys), '%s')) . ")\n"
				. "\t\tAND metasync_seo_health_meta.meta_value <> ''\n"
				. ")",
			$keys
		);
	}

	/**
	 * Return the precedence keys used by a missing title/description filter.
	 *
	 * @param string $filter The missing_filter value.
	 * @return string[]
	 */
	private static function get_missing_meta_keys($filter)
	{
		if ($filter === 'missing_title') {
			return array_keys(Metasync_SEO_Health::title_meta_keys());
		}

		if ($filter === 'missing_description') {
			return array_keys(Metasync_SEO_Health::desc_meta_keys());
		}

		return array();
	}

	/**
	 * Whether a post matches a filter that needs the shared PHP predicates.
	 *
	 * The length ranges are the class constants that also drive the table's
	 * orange (suboptimal) highlighting, so a filter always returns exactly the
	 * rows highlighted for that field.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $filter  The missing_filter value.
	 * @return bool
	 */
	private function post_matches_filter($post_id, $filter)
	{
		switch ($filter) {
			case 'missing_schema':
				return !Metasync_SEO_Health::has_schema($post_id);

			case 'seo_title_too_short':
				$title = Metasync_SEO_Health::get_seo_meta_with_fallback($post_id, 'title')['value'];
				return $title !== '' && mb_strlen($title) < self::SEO_TITLE_MIN;

			case 'seo_title_too_long':
				$title = Metasync_SEO_Health::get_seo_meta_with_fallback($post_id, 'title')['value'];
				return mb_strlen($title) > self::SEO_TITLE_MAX;

			case 'description_too_short':
				$description = Metasync_SEO_Health::get_seo_meta_with_fallback($post_id, 'description')['value'];
				return $description !== '' && mb_strlen($description) < self::META_DESCRIPTION_MIN;

			case 'description_too_long':
				$description = Metasync_SEO_Health::get_seo_meta_with_fallback($post_id, 'description')['value'];
				return mb_strlen($description) > self::META_DESCRIPTION_MAX;
		}

		return false;
	}
}

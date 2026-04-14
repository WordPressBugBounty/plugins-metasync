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
 * @since      3.0.0
 * @package    Metasync
 * @subpackage Metasync/admin
 */
class Metasync_SEO_Health_List_Table extends WP_List_Table
{
	/** Nonce action for bulk actions. */
	const NONCE_ACTION = 'metasync_seo_health_bulk';

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
			'og_image'           => _x('OG Image', 'Column label', 'metasync'),
			'alt_text_coverage'  => _x('Alt Text', 'Column label', 'metasync'),
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
				return $this->render_seo_meta_column($item->ID, 'title', 30, 60);

			case 'meta_description':
				return $this->render_seo_meta_column($item->ID, 'description', 120, 160);

			case 'schema':
				return Metasync_SEO_Health::has_schema($item->ID)
					? '<span class="seo-status-set optimal">&#10003; Yes</span>'
					: '<span class="seo-status-none">&mdash; None</span>';

			case 'og_image':
				return Metasync_SEO_Health::has_og_image($item->ID)
					? '<span class="seo-status-set optimal">&#10003; Set</span>'
					: '<span class="seo-status-missing">&#9888; Missing</span>';

			case 'alt_text_coverage':
				return $this->render_alt_text_column($item);

			case 'last_modified':
				return esc_html(get_the_modified_date('Y-m-d', $item));

			default:
				return '';
		}
	}

	/**
	 * Render an SEO meta column (title or description) with fallback, length indicator, and source badge.
	 */
	private function render_seo_meta_column($post_id, $field, $min_optimal, $max_optimal)
	{
		$result = Metasync_SEO_Health::get_seo_meta_with_fallback($post_id, $field);

		if (empty($result['value'])) {
			return '<span class="seo-status-missing">&#9888; Missing</span>';
		}

		$length = mb_strlen($result['value']);
		$quality = ($length >= $min_optimal && $length <= $max_optimal) ? 'optimal' : 'suboptimal';
		$badge = '';

		if (!empty($result['source'])) {
			$badge = sprintf(
				' <span class="seo-source-badge">%s</span>',
				esc_html($result['source'])
			);
		}

		return sprintf(
			'<span class="seo-status-set %s">&#10003; %d chars</span>%s',
			$quality,
			$length,
			$badge
		);
	}

	/**
	 * Render alt text coverage column.
	 */
	private function render_alt_text_column($item)
	{
		$alt = Metasync_SEO_Health::calculate_alt_text_coverage($item->post_content);

		if ($alt['total'] === 0) {
			return '<span class="seo-status-none">No images</span>';
		}

		$quality = 'seo-status-missing';
		if ($alt['percentage'] === 100) {
			$quality = 'seo-status-set optimal';
		} elseif ($alt['percentage'] >= 50) {
			$quality = 'seo-status-set suboptimal';
		}

		return sprintf(
			'<span class="%s">%d%% (%d/%d)</span>',
			$quality,
			$alt['percentage'],
			$alt['with_alt'],
			$alt['total']
		);
	}

	protected function get_bulk_actions()
	{
		return array(
			'push_to_platform'     => _x('Push to platform for optimization', 'List table bulk action', 'metasync'),
			'clear_metasync_cache' => _x('Clear MetaSync cache for selected', 'List table bulk action', 'metasync'),
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

		if ('push_to_platform' === $this->current_action()) {
			// TODO: Implement Search Atlas API integration to queue posts for optimization.
			// The action hook is in place — needs a listener once the backend API endpoint exists.
			do_action('metasync_push_posts_to_platform', $post_ids);
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
		$current = isset($_GET[$name]) ? sanitize_text_field($_GET[$name]) : '';
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
			'missing_title'       => __('Missing SEO Title', 'metasync'),
			'missing_description' => __('Missing Description', 'metasync'),
			'missing_schema'      => __('Missing Schema', 'metasync'),
			'missing_og_image'    => __('Missing OG Image', 'metasync'),
			'missing_alt_text'    => __('Missing Alt Text', 'metasync'),
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
		$missing_filter = isset($_GET['missing_filter']) ? sanitize_text_field($_GET['missing_filter']) : '';

		$args = array(
			'post_status' => array('publish', 'draft'),
			'orderby'     => 'date',
			'order'       => 'DESC',
		);

		// Post type filter — validate against allowed types
		$post_type_filter = isset($_GET['post_type_filter']) ? sanitize_text_field($_GET['post_type_filter']) : '';
		$supported_types = Metasync_SEO_Health::get_supported_post_types();
		$args['post_type'] = (!empty($post_type_filter) && in_array($post_type_filter, $supported_types, true))
			? $post_type_filter
			: $supported_types;

		// Status filter — validate against allowed statuses
		$status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';
		if (!empty($status_filter) && in_array($status_filter, array('publish', 'draft'), true)) {
			$args['post_status'] = $status_filter;
		}

		// Search
		$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
		if (!empty($search)) {
			$args['s'] = $search;
		}

		// Sorting — validate orderby against allowed columns
		$allowed_orderby = array('title', 'post_type', 'post_status', 'post_modified', 'date');
		$orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : '';
		$order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : '';
		if (!empty($orderby) && in_array($orderby, $allowed_orderby, true)) {
			$args['orderby'] = $orderby;
			$args['order'] = (!empty($order) && in_array(strtoupper($order), array('ASC', 'DESC'), true))
				? strtoupper($order)
				: 'ASC';
		}

		// Build meta_query for missing filters that can be handled at DB level
		$meta_query = $this->build_missing_meta_query($missing_filter);
		if ($meta_query !== null) {
			$args['meta_query'] = $meta_query;
			$args['posts_per_page'] = $per_page;
			$args['paged'] = $current_page;

			$query = new WP_Query($args);
			$this->items = $query->posts;
			$total_items = $query->found_posts;
		} elseif ($missing_filter === 'missing_alt_text') {
			// Alt text requires post_content inspection — must filter post-query
			$args['posts_per_page'] = -1;
			$query = new WP_Query($args);
			$all_filtered = $this->filter_missing_alt_text($query->posts);
			$total_items = count($all_filtered);
			$this->items = array_slice($all_filtered, ($current_page - 1) * $per_page, $per_page);
		} else {
			// No missing filter — standard paginated query
			$args['posts_per_page'] = $per_page;
			$args['paged'] = $current_page;

			$query = new WP_Query($args);
			$this->items = $query->posts;
			$total_items = $query->found_posts;
		}

		$this->set_pagination_args(array(
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => ceil($total_items / $per_page),
		));
	}

	/**
	 * Build a meta_query for missing filter types that can be expressed as DB queries.
	 * Returns null for filters that require post-content inspection (alt text).
	 *
	 * @param string $filter The missing_filter value.
	 * @return array|null meta_query array or null if not applicable.
	 */
	private function build_missing_meta_query($filter)
	{
		switch ($filter) {
			case 'missing_title':
				$exists_clauses = array();
				foreach (array_keys(Metasync_SEO_Health::TITLE_META_KEYS) as $key) {
					$exists_clauses[] = array(
						'key'     => $key,
						'value'   => '',
						'compare' => '!=',
					);
				}
				// NOT EXISTS for all title keys = truly missing
				$not_exists = array('relation' => 'AND');
				foreach (array_keys(Metasync_SEO_Health::TITLE_META_KEYS) as $key) {
					$not_exists[] = array(
						'key'     => $key,
						'compare' => 'NOT EXISTS',
					);
				}
				// Also match rows where key exists but value is empty
				$empty_values = array('relation' => 'AND');
				foreach (array_keys(Metasync_SEO_Health::TITLE_META_KEYS) as $key) {
					$empty_values[] = array(
						'relation' => 'OR',
						array('key' => $key, 'compare' => 'NOT EXISTS'),
						array('key' => $key, 'value' => '', 'compare' => '='),
					);
				}
				return $empty_values;

			case 'missing_description':
				$empty_values = array('relation' => 'AND');
				foreach (array_keys(Metasync_SEO_Health::DESC_META_KEYS) as $key) {
					$empty_values[] = array(
						'relation' => 'OR',
						array('key' => $key, 'compare' => 'NOT EXISTS'),
						array('key' => $key, 'value' => '', 'compare' => '='),
					);
				}
				return $empty_values;

			case 'missing_schema':
				return array(
					'relation' => 'OR',
					array('key' => 'metasync_schema_markup', 'compare' => 'NOT EXISTS'),
					array('key' => 'metasync_schema_markup', 'value' => '', 'compare' => '='),
					array('key' => 'metasync_schema_markup', 'value' => '[]', 'compare' => '='),
				);

			case 'missing_og_image':
				return array(
					'relation' => 'AND',
					array(
						'relation' => 'OR',
						array('key' => '_metasync_og_image', 'compare' => 'NOT EXISTS'),
						array('key' => '_metasync_og_image', 'value' => '', 'compare' => '='),
					),
					array(
						'relation' => 'OR',
						array('key' => '_thumbnail_id', 'compare' => 'NOT EXISTS'),
						array('key' => '_thumbnail_id', 'value' => '', 'compare' => '='),
					),
				);

			default:
				return null;
		}
	}

	/**
	 * Filter posts that have images missing alt text.
	 *
	 * @param array $posts Array of WP_Post objects.
	 * @return array Filtered posts with missing alt text.
	 */
	private function filter_missing_alt_text($posts)
	{
		$filtered = array();
		foreach ($posts as $post) {
			if (preg_match_all('/<img[^>]*>/i', $post->post_content, $matches)) {
				foreach ($matches[0] as $img_tag) {
					if (!preg_match('/alt\s*=\s*["\']([^"\']+)["\']/i', $img_tag)) {
						$filtered[] = $post;
						break;
					}
				}
			}
		}
		return $filtered;
	}
}

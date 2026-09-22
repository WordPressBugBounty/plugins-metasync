<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * SEO Health Dashboard
 *
 * Provides a bird's-eye view of all posts/pages and their SEO completeness.
 *
 * @since      3.0.0
 * @package    Metasync
 * @subpackage Metasync/admin
 */
class Metasync_SEO_Health
{
	/** @var self|null */
	private static $instance = null;

	/**
	 * Third-party and legacy keys checked after MetaSync's own chain.
	 *
	 * MetaSync's tiers are not listed here — they come from
	 * Metasync_Seo_Precedence, the one place the order is defined. These are the
	 * cross-plugin keys SEO Health looks at once MetaSync has nothing.
	 */
	const THIRD_PARTY_TITLE_KEYS = array(
		'_yoast_wpseo_title'           => 'Yoast',
		'rank_math_title'              => 'Rank Math',
		'_aioseo_title'                => 'AIOSEO',
		'_metasync_og_title'           => 'OG',
	);

	/**
	 * @see THIRD_PARTY_TITLE_KEYS
	 */
	const THIRD_PARTY_DESC_KEYS = array(
		'_yoast_wpseo_metadesc'         => 'Yoast',
		'rank_math_description'         => 'Rank Math',
		'_aioseo_description'           => 'AIOSEO',
		'meta_description'              => '',
		'_metasync_og_description'      => 'OG',
	);

	/**
	 * Meta keys for cross-plugin SEO title detection, highest tier first.
	 *
	 * `_metasync_seo_title` leads: it is the SEO sidebar / Classic meta box
	 * field and outranks everything else. The meta box save writes ONLY that key
	 * and never mirrors into `_metasync_metatitle`, so omitting it would make
	 * the resolver report OTTO's title while the customer's own value is what
	 * actually renders.
	 *
	 * @return array<string,string> Meta key => display source label.
	 */
	public static function title_meta_keys()
	{
		return Metasync_Seo_Precedence::chain(Metasync_Seo_Precedence::FIELD_TITLE)
			+ self::third_party_title_keys();
	}

	/**
	 * Meta keys for cross-plugin SEO description detection, highest tier first.
	 *
	 * `_metasync_imported_seo_desc` sits BELOW OTTO: it holds values brought in
	 * by the external importer, which must not displace an OTTO recommendation.
	 *
	 * @return array<string,string> Meta key => display source label.
	 */
	public static function desc_meta_keys()
	{
		return Metasync_Seo_Precedence::chain(Metasync_Seo_Precedence::FIELD_DESCRIPTION)
			+ self::third_party_desc_keys();
	}

	/**
	 * Third-party title keys, limited to plugins that are actually active.
	 *
	 * A deactivated plugin leaves its meta behind; counting that as a set
	 * title reports a value nothing can render anymore. The gate keeps the
	 * table, the missing filters and the summary cards on the same key set —
	 * they all read through these maps.
	 *
	 * @return array<string,string> Meta key => display source label.
	 */
	private static function third_party_title_keys()
	{
		return self::filter_keys_by_active_plugin(self::THIRD_PARTY_TITLE_KEYS);
	}

	/**
	 * @see third_party_title_keys()
	 *
	 * @return array<string,string> Meta key => display source label.
	 */
	private static function third_party_desc_keys()
	{
		return self::filter_keys_by_active_plugin(self::THIRD_PARTY_DESC_KEYS);
	}

	/**
	 * Drop cross-plugin keys whose owning plugin is not active.
	 *
	 * MetaSync's own keys (the OG pair, the legacy description key) carry no
	 * plugin and always stay. is_plugin_active() lives in wp-admin even on
	 * frontend and REST requests, so it is required on demand the same way the
	 * conflict handler does it.
	 *
	 * @param array<string,string> $keys Meta key => plugin-derived source label.
	 * @return array<string,string>
	 */
	private static function filter_keys_by_active_plugin(array $keys)
	{
		$active = array();

		if (self::is_plugin_active_any(array(
			'wordpress-seo/wp-seo.php',
			'wordpress-seo-premium/wp-seo-premium.php',
		))) {
			$active['Yoast'] = true;
		}
		if (self::is_plugin_active_any(array(
			'seo-by-rank-math/rank-math.php',
			'seo-by-rankmath/rank-math.php',
		))) {
			$active['Rank Math'] = true;
		}
		if (self::is_plugin_active_any(array(
			'all-in-one-seo-pack/all_in_one_seo_pack.php',
			'all-in-one-seo-pack-pro/all_in_one_seo_pack.php',
		))) {
			$active['AIOSEO'] = true;
		}

		$plugin_labels = array('Yoast', 'Rank Math', 'AIOSEO');

		return array_filter(
			$keys,
			static function ($source) use ($plugin_labels, $active) {
				// Labels the plugin does not own are never gated.
				if (!in_array($source, $plugin_labels, true)) {
					return true;
				}
				return isset($active[$source]);
			}
		);
	}

	/**
	 * Whether any of the given plugin basenames is active.
	 *
	 * @param string[] $plugins Plugin basename paths.
	 * @return bool
	 */
	private static function is_plugin_active_any(array $plugins)
	{
		if (!function_exists('is_plugin_active')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ($plugins as $plugin) {
			if (is_plugin_active($plugin)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Transient key for summary stats.
	 * Versioned by a hash of the meta key constants — auto-invalidates when fallback chain changes.
	 */
	const STATS_TRANSIENT_KEY = 'metasync_seo_health_stats';

	/**
	 * Get the versioned transient key.
	 * Changes automatically whenever the resolved key chain is modified.
	 *
	 * @return string
	 */
	private static function get_stats_transient_key()
	{
		$fingerprint = md5(serialize(self::title_meta_keys()) . serialize(self::desc_meta_keys()));
		return self::STATS_TRANSIENT_KEY . '_' . substr($fingerprint, 0, 8);
	}

	/**
	 * Builder/internal post types that should never appear in SEO Health.
	 */
	private static $excluded_post_types = array(
		'elementor_library',
		'e-floating-buttons',
		'e-landing-page',
		'et_pb_layout',
		'et_header_layout',
		'et_body_layout',
		'et_footer_layout',
		'oxy_user_library',
		'ct_template',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_block',
		'custom_css',
		'customize_changeset',
		'revision',
		'nav_menu_item',
	);

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance()
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct()
	{
		add_action('save_post', array($this, 'invalidate_cache'));
	}

	/**
	 * Handle CSV export. Called from admin_init guard in class-metasync.php
	 * — only when $_GET parameters already match.
	 */
	public function handle_csv_export()
	{
		if (!wp_verify_nonce($_GET['_wpnonce'], 'metasync_seo_health_export')) {
			wp_die(__('Security check failed.', 'metasync'));
		}

		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have permission to export.', 'metasync'));
		}

		$this->export_csv();
	}

	/**
	 * Get supported post types including WooCommerce products and custom post types.
	 * Excludes builder/internal post types (Elementor templates, Divi layouts, etc.).
	 *
	 * @return array
	 */
	public static function get_supported_post_types()
	{
		$post_types = array('post', 'page');

		if (class_exists('WooCommerce')) {
			$post_types[] = 'product';
		}

		$custom_types = get_post_types(array(
			'public'             => true,
			'publicly_queryable' => true,
			'_builtin'           => false,
		), 'names');

		foreach ($custom_types as $cpt) {
			if (
				!in_array($cpt, $post_types, true) &&
				!in_array($cpt, self::$excluded_post_types, true)
			) {
				$post_types[] = $cpt;
			}
		}

		return $post_types;
	}

	/**
	 * Get the SEO meta value that applies to a post, and where it came from.
	 *
	 * Read-only. MetaSync's own tiers (customer value, OTTO, imported) are
	 * settled by Metasync_Seo_Precedence::resolve() — the one place the order
	 * lives — which also drops the OTTO tiers when OTTO is switched off for
	 * the post. This function never fetches OTTO data, compares freshness, or
	 * writes anything: it reports what is stored, and only that.
	 *
	 * Below the MetaSync chain come the cross-plugin and legacy keys, gated to
	 * plugins that are actually active — a deactivated plugin's leftover meta
	 * cannot control output, so it does not count as set here.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Either 'title' or 'description'.
	 * @return array Array with 'value' and 'source' keys.
	 */
	public static function get_seo_meta_with_fallback($post_id, $field)
	{
		$field_const = ($field === 'title')
			? Metasync_Seo_Precedence::FIELD_TITLE
			: Metasync_Seo_Precedence::FIELD_DESCRIPTION;

		$resolved = Metasync_Seo_Precedence::resolve($post_id, $field_const);

		if ($resolved['value'] !== '') {
			return array(
				'value'  => $resolved['value'],
				'source' => self::display_source($post_id, $field, $resolved),
			);
		}

		// Cross-plugin, legacy and OG keys — already limited to active plugins.
		$meta_keys = ($field === 'title') ? self::third_party_title_keys() : self::third_party_desc_keys();

		foreach ($meta_keys as $meta_key => $source) {
			$value = get_post_meta($post_id, $meta_key, true);
			if (!empty($value)) {
				return array('value' => $value, 'source' => $source);
			}
		}

		return array('value' => '', 'source' => '');
	}

	/**
	 * The badge label for a resolver result, if any.
	 *
	 * The imported tier is an internal fallback only — it reports as set
	 * without a label, the same as a value the customer owns. The persisted
	 * OTTO keys are the one case that needs care: the sync writes OTTO's text
	 * to both the native key and the OTTO staging key, and the native one sits
	 * higher in the chain, so comparing the two is what proves the value is
	 * OTTO's rather than something the customer typed into the old field. Only
	 * the persisted keys get that comparison — a customer value that happens
	 * to match OTTO's suggestion stays unbadged.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $field    Either 'title' or 'description'.
	 * @param array  $resolved Resolver result with 'key' and 'source'.
	 * @return string '' when no badge applies.
	 */
	private static function display_source($post_id, $field, array $resolved)
	{
		$source = $resolved['source'];

		if ($source === Metasync_Seo_Precedence::SOURCE_IMPORTED) {
			return '';
		}

		if ($source === '') {
			$persisted = ($field === 'title')
				? Metasync_Seo_Precedence::KEY_PERSISTED_OTTO_TITLE
				: Metasync_Seo_Precedence::KEY_PERSISTED_OTTO_DESC;
			$otto_key = ($field === 'title') ? '_metasync_otto_title' : '_metasync_otto_description';

			if ($resolved['key'] === $persisted
				&& $resolved['value'] === get_post_meta($post_id, $otto_key, true)) {
				$source = 'OTTO';
			}
		}

		// White-label the OTTO source label at render time (const values stay literal).
		if ($source === 'OTTO') {
			$source = Metasync::get_whitelabel_otto_name();
		}

		return $source;
	}

	/**
	 * Check if a post has an OG image (MetaSync or featured image fallback).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function has_og_image($post_id)
	{
		$og_image = get_post_meta($post_id, '_metasync_og_image', true);
		if (!empty($og_image)) {
			return true;
		}
		return !empty(get_post_meta($post_id, '_thumbnail_id', true));
	}

	/**
	 * Check if a post has meaningful schema markup.
	 * Treats empty arrays/objects as "no schema".
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function has_schema($post_id)
	{
		$schema = get_post_meta($post_id, 'metasync_schema_markup', true);
		if (empty($schema)) {
			return false;
		}
		if (is_string($schema)) {
			$decoded = json_decode($schema, true);
			return is_array($decoded) && !empty($decoded);
		}
		if (is_array($schema)) {
			return !empty($schema);
		}
		return false;
	}

	/**
	 * Calculate alt text coverage for images in post content.
	 *
	 * @param string $content Post content HTML.
	 * @return array Array with 'total', 'with_alt', and 'percentage' keys.
	 */
	public static function calculate_alt_text_coverage($content)
	{
		$result = array('total' => 0, 'with_alt' => 0, 'percentage' => null);

		if (!preg_match_all('/<img[^>]*>/i', $content, $matches)) {
			return $result;
		}

		$result['total'] = count($matches[0]);
		foreach ($matches[0] as $img_tag) {
			if (preg_match('/alt\s*=\s*["\']([^"\']+)["\']/i', $img_tag, $alt_match)) {
				if (!empty(trim($alt_match[1]))) {
					$result['with_alt']++;
				}
			}
		}

		$result['percentage'] = $result['total'] > 0
			? round(($result['with_alt'] / $result['total']) * 100)
			: 100;

		return $result;
	}

	/**
	 * Render the SEO Health dashboard page.
	 * Uses the 3-column sidebar layout (render_layout_open/close).
	 */
	public function render_page()
	{
		require_once plugin_dir_path(__FILE__) . 'class-metasync-seo-health-list-table.php';

		$stats = $this->get_summary_stats();
		$list_table = new Metasync_SEO_Health_List_Table();
		$list_table->prepare_items();

		$nav = Metasync_Admin_Navigation::instance();
		$nav->render_layout_open(
			'SEO Health',
			'seo_health',
			__('Bird\'s-eye view of SEO completeness across all your content.', 'metasync')
		);
		?>

		<div class="metasync-seo-health-summary">
			<?php
			$stat_cards = array(
				array('value' => $stats['total_posts'], 'label' => __('Total Posts', 'metasync'), 'type' => 'count'),
				array('value' => $stats['pct_seo_title'], 'label' => __('SEO Title Set', 'metasync'), 'type' => 'percent'),
				array('value' => $stats['pct_meta_description'], 'label' => __('Meta Description Set', 'metasync'), 'type' => 'percent'),
				array('value' => $stats['pct_schema'], 'label' => __('Schema Markup', 'metasync'), 'type' => 'percent'),
				array('value' => $stats['pct_og_image'], 'label' => __('OG Image Set', 'metasync'), 'type' => 'percent'),
			);
			foreach ($stat_cards as $card):
				$display = ($card['type'] === 'percent') ? $card['value'] . '%' : $card['value'];
				$color_class = 'stat-neutral';
				if ($card['type'] === 'percent') {
					if ($card['value'] >= 80) {
						$color_class = 'stat-success';
					} elseif ($card['value'] >= 50) {
						$color_class = 'stat-warning';
					} else {
						$color_class = 'stat-error';
					}
				}
			?>
				<div class="metasync-health-stat-card">
					<div class="stat-value <?php echo $color_class; ?>"><?php echo esc_html($display); ?></div>
					<div class="stat-label"><?php echo esc_html($card['label']); ?></div>
				</div>
			<?php endforeach; ?>
		</div>

		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr($_GET['page'] ?? ''); ?>" />

			<div class="metasync-health-toolbar">
				<a href="<?php echo esc_url(wp_nonce_url(add_query_arg('export', 'csv'), 'metasync_seo_health_export')); ?>" class="button button-secondary">
					<?php esc_html_e('Export CSV', 'metasync'); ?>
				</a>
				<?php $list_table->search_box(__('Search Posts', 'metasync'), 'seo-health-search'); ?>
			</div>

			<?php $list_table->display(); ?>
		</form>

		<?php
		$nav->render_layout_close();
	}

	/**
	 * Get summary statistics, cached in a transient.
	 * Uses WP_Query with meta_query for counting (no raw SQL).
	 * Primes the meta cache in one batch to avoid N+1 lookups.
	 *
	 * @return array
	 */
	public function get_summary_stats()
	{
		$stats = get_transient(self::get_stats_transient_key());

		if ($stats !== false) {
			return $stats;
		}

		$post_types = self::get_supported_post_types();
		$batch_size = 500;
		$last_id = 0;

		$total = 0;
		$with_title = 0;
		$with_desc = 0;
		$with_schema = 0;
		$with_og_image = 0;

		add_filter('posts_where', 'metasync_filter_exclude_custom_pages_where', 10, 2);
		add_filter('posts_where', array('Metasync_SEO_Inventory_Builder', 'filter_where_id_gt'), 10, 2);

		try {
			while (true) {
				$args = array(
					'post_type'              => $post_types,
					'post_status'            => array('publish', 'draft'),
					'posts_per_page'         => $batch_size,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'metasync_exclude_custom_pages' => true,
				);

				if ($last_id > 0) {
					$args['where_id_gt'] = $last_id;
				}

				$query = new WP_Query($args);
				$post_ids = $query->posts;

				if (empty($post_ids)) {
					break;
				}

				// Prime meta cache for this chunk of IDs
				update_meta_cache('post', $post_ids);

				foreach ($post_ids as $post_id) {
					$total++;

					if (!empty(self::get_seo_meta_with_fallback($post_id, 'title')['value'])) {
						$with_title++;
					}

					if (!empty(self::get_seo_meta_with_fallback($post_id, 'description')['value'])) {
						$with_desc++;
					}

					if (self::has_schema($post_id)) {
						$with_schema++;
					}

					if (self::has_og_image($post_id)) {
						$with_og_image++;
					}

					$last_id = (int) $post_id;
				}

				if (count($post_ids) < $batch_size) {
					break;
				}
			}
		} finally {
			remove_filter('posts_where', 'metasync_filter_exclude_custom_pages_where', 10);
			remove_filter('posts_where', array('Metasync_SEO_Inventory_Builder', 'filter_where_id_gt'), 10);
		}

		if ($total === 0) {
			$stats = array(
				'total_posts'          => 0,
				'pct_seo_title'        => 0,
				'pct_meta_description' => 0,
				'pct_schema'           => 0,
				'pct_og_image'         => 0,
			);
			set_transient(self::get_stats_transient_key(), $stats, 3600);
			return $stats;
		}

		$stats = array(
			'total_posts'          => $total,
			'pct_seo_title'        => (int) round(($with_title / $total) * 100),
			'pct_meta_description' => (int) round(($with_desc / $total) * 100),
			'pct_schema'           => (int) round(($with_schema / $total) * 100),
			'pct_og_image'         => (int) round(($with_og_image / $total) * 100),
		);

		set_transient(self::get_stats_transient_key(), $stats, 3600);

		return $stats;
	}

	/**
	 * Invalidate the summary stats transient on post save.
	 */
	public function invalidate_cache()
	{
		self::invalidate_stats();
	}

	/**
	 * Delete the summary stats transient.
	 *
	 * Public and static so writers that update SEO meta outside of save_post
	 * (OTTO crawl-notify job, MCP tools) can keep the dashboard aggregates in
	 * step with the table, which reads live post meta.
	 */
	public static function invalidate_stats()
	{
		delete_transient(self::get_stats_transient_key());
	}

	/**
	 * Export filtered results as CSV.
	 *
	 * The columns mirror the table: value and status, no source. The export is
	 * the table's own download, so a Source column here would reintroduce on
	 * paper exactly the provenance the table stopped showing.
	 */
	public function export_csv()
	{
		require_once plugin_dir_path(__FILE__) . 'class-metasync-seo-health-list-table.php';

		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=seo-health-export.csv');

		$output = fopen('php://output', 'w');

		fputcsv($output, array(
			'Post ID', 'Title', 'Post Type', 'Status',
			'SEO Title',
			'Meta Description',
			'Has Schema', 'Has OG Image',
			'Alt Text Coverage %', 'Last Modified',
		));

		$args = array(
			'post_type'              => self::get_supported_post_types(),
			'post_status'            => array('publish', 'draft'),
			'posts_per_page'         => 500,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
			'metasync_exclude_custom_pages' => true,
		);

		$post_type_filter = isset($_GET['post_type_filter']) ? sanitize_text_field($_GET['post_type_filter']) : '';
		if (!empty($post_type_filter) && in_array($post_type_filter, self::get_supported_post_types(), true)) {
			$args['post_type'] = $post_type_filter;
		}

		$status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';
		if (!empty($status_filter) && in_array($status_filter, array('publish', 'draft'), true)) {
			$args['post_status'] = $status_filter;
		}

		$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
		if (!empty($search)) {
			$args['s'] = $search;
		}

		$batch_size = 500;
		$last_id = 0;

		add_filter('posts_where', 'metasync_filter_exclude_custom_pages_where', 10, 2);
		add_filter('posts_where', array('Metasync_SEO_Inventory_Builder', 'filter_where_id_gt'), 10, 2);

		try {
			while (true) {
				if ($last_id > 0) {
					$args['where_id_gt'] = $last_id;
				}

				$query = new WP_Query($args);
				$posts = $query->posts;

				if (empty($posts)) {
					break;
				}

				foreach ($posts as $post) {
					$title_result = self::get_seo_meta_with_fallback($post->ID, 'title');
					$desc_result = self::get_seo_meta_with_fallback($post->ID, 'description');
					$alt = self::calculate_alt_text_coverage($post->post_content);

					fputcsv($output, array(
						$post->ID,
						$post->post_title,
						$post->post_type,
						$post->post_status,
						$title_result['value'],
						$desc_result['value'],
						self::has_schema($post->ID) ? 'Yes' : 'No',
						self::has_og_image($post->ID) ? 'Yes' : 'No',
						$alt['percentage'] !== null ? $alt['percentage'] : 'N/A',
						get_the_modified_date('Y-m-d', $post),
					));

					$last_id = (int) $post->ID;
				}

				if (count($posts) < $batch_size) {
					break;
				}
			}
		} finally {
			remove_filter('posts_where', 'metasync_filter_exclude_custom_pages_where', 10);
			remove_filter('posts_where', array('Metasync_SEO_Inventory_Builder', 'filter_where_id_gt'), 10);
		}

		fclose($output);
		exit;
	}
}

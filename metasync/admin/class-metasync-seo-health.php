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
	 * Meta keys for cross-plugin SEO title detection.
	 * Keys are meta_key, values are display source label (empty = MetaSync).
	 */
	const TITLE_META_KEYS = array(
		'_metasync_metatitle'       => '',
		'_metasync_otto_title'      => 'OTTO',
		'_yoast_wpseo_title'        => 'Yoast',
		'rank_math_title'           => 'Rank Math',
		'_aioseo_title'             => 'AIOSEO',
		'_metasync_og_title'        => 'OG',
	);

	/**
	 * Meta keys for cross-plugin SEO description detection.
	 */
	const DESC_META_KEYS = array(
		'_metasync_metadesc'            => '',
		'_metasync_otto_description'    => 'OTTO',
		'_yoast_wpseo_metadesc'         => 'Yoast',
		'rank_math_description'         => 'Rank Math',
		'_aioseo_description'           => 'AIOSEO',
		'meta_description'              => '',
		'_metasync_og_description'      => 'OG',
	);

	/**
	 * Transient key for summary stats.
	 * Versioned by a hash of the meta key constants — auto-invalidates when fallback chain changes.
	 */
	const STATS_TRANSIENT_KEY = 'metasync_seo_health_stats';

	/**
	 * Get the versioned transient key.
	 * Changes automatically whenever TITLE_META_KEYS or DESC_META_KEYS are modified.
	 *
	 * @return string
	 */
	private static function get_stats_transient_key()
	{
		$fingerprint = md5(serialize(self::TITLE_META_KEYS) . serialize(self::DESC_META_KEYS));
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
	 * Get the first non-empty SEO meta value across plugin fallbacks.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Either 'title' or 'description'.
	 * @return array Array with 'value' and 'source' keys.
	 */
	public static function get_seo_meta_with_fallback($post_id, $field)
	{
		$meta_keys = ($field === 'title') ? self::TITLE_META_KEYS : self::DESC_META_KEYS;

		foreach ($meta_keys as $meta_key => $source) {
			$value = get_post_meta($post_id, $meta_key, true);
			if (!empty($value)) {
				return array('value' => $value, 'source' => $source);
			}
		}

		return array('value' => '', 'source' => '');
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

		// Fetch all post IDs in one query
		$query = new WP_Query(array(
			'post_type'      => $post_types,
			'post_status'    => array('publish', 'draft'),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		));

		$post_ids = $query->posts;
		$total = count($post_ids);

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

		// Prime meta cache in one DB call — all subsequent get_post_meta() are free
		update_meta_cache('post', $post_ids);

		$with_title = 0;
		$with_desc = 0;
		$with_schema = 0;
		$with_og_image = 0;

		foreach ($post_ids as $post_id) {
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
		}

		$stats = array(
			'total_posts'          => $total,
			'pct_seo_title'        => round(($with_title / $total) * 100),
			'pct_meta_description' => round(($with_desc / $total) * 100),
			'pct_schema'           => round(($with_schema / $total) * 100),
			'pct_og_image'         => round(($with_og_image / $total) * 100),
		);

		set_transient(self::get_stats_transient_key(), $stats, 3600);

		return $stats;
	}

	/**
	 * Invalidate the summary stats transient on post save.
	 */
	public function invalidate_cache()
	{
		delete_transient(self::get_stats_transient_key());
	}

	/**
	 * Export filtered results as CSV.
	 */
	public function export_csv()
	{
		require_once plugin_dir_path(__FILE__) . 'class-metasync-seo-health-list-table.php';

		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=seo-health-export.csv');

		$output = fopen('php://output', 'w');

		fputcsv($output, array(
			'Post ID', 'Title', 'Post Type', 'Status',
			'SEO Title', 'SEO Title Source',
			'Meta Description', 'Meta Description Source',
			'Has Schema', 'Has OG Image',
			'Alt Text Coverage %', 'Last Modified',
		));

		$args = array(
			'post_type'      => self::get_supported_post_types(),
			'post_status'    => array('publish', 'draft'),
			'posts_per_page' => -1,
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

		$query = new WP_Query($args);

		foreach ($query->posts as $post) {
			$title_result = self::get_seo_meta_with_fallback($post->ID, 'title');
			$desc_result = self::get_seo_meta_with_fallback($post->ID, 'description');
			$alt = self::calculate_alt_text_coverage($post->post_content);

			fputcsv($output, array(
				$post->ID,
				$post->post_title,
				$post->post_type,
				$post->post_status,
				$title_result['value'],
				$title_result['source'],
				$desc_result['value'],
				$desc_result['source'],
				self::has_schema($post->ID) ? 'Yes' : 'No',
				self::has_og_image($post->ID) ? 'Yes' : 'No',
				$alt['percentage'] !== null ? $alt['percentage'] : 'N/A',
				get_the_modified_date('Y-m-d', $post),
			));
		}

		fclose($output);
		exit;
	}
}

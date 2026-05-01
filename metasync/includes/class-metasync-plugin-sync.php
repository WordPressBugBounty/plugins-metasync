<?php
/**
 * Post-Level SEO Plugin Sync (WP-196)
 *
 * Mirrors MetaSync post meta (`_metasync_*`) into the active third-party
 * SEO plugins' post storage (Yoast, Rank Math, AIOSEO) so that posts and
 * pages render MetaSync-managed values regardless of which plugin is
 * actually rendering the frontend.
 *
 * @package    MetaSync
 * @subpackage MetaSync/includes
 * @since      2.8.25
 */

if (!defined('ABSPATH')) {
	exit;
}

class Metasync_Plugin_Sync {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Guard: tracks post IDs currently being synced from JSON → legacy.
	 * Prevents sync_legacy_to_json from overwriting the correct JSON value.
	 *
	 * @var array
	 */
	private $syncing_json_to_legacy = [];

	/**
	 * MetaSync meta keys that trigger a sync when written.
	 *
	 * @var array
	 */
	const WATCHED_KEYS = [
		// Sidebar / persisted keys
		'_metasync_seo_title',
		'_metasync_seo_desc',
		'_metasync_metatitle',
		'_metasync_metadesc',
		'_metasync_robots_index',
		'_metasync_robots_advanced',
		'_metasync_og_title',
		'_metasync_og_description',
		'_metasync_og_image',
		'_metasync_twitter_title',
		'_metasync_twitter_description',
		'_metasync_twitter_card',
		'_metasync_canonical_url',
		'_metasync_focus_keyword',
		'_metasync_breadcrumb_title',
		// OTTO volatile keys
		'_metasync_otto_title',
		'_metasync_otto_description',
		'_metasync_otto_og_title',
		'_metasync_otto_og_description',
		'_metasync_otto_twitter_title',
		'_metasync_otto_twitter_description',
		'_metasync_otto_keywords',
	];

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Sync MetaSync post meta to every active SEO plugin.
	 *
	 * When $fields is non-empty only those canonical keys are synced.
	 * When empty a full sync of all canonical keys is performed.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $fields  Optional subset of canonical key/value pairs to sync.
	 * @return array Results keyed by plugin: ['yoast'=>bool,'rankmath'=>bool,'aioseo'=>bool].
	 */
	public function sync_post($post_id, array $fields = []) {
		static $syncing = [];

		if (!empty($syncing[$post_id])) {
			return [];
		}
		$syncing[$post_id] = true;

		try {
			$results = [];

			if ($post_id <= 0) {
				return $results;
			}

			$data = $this->collect_post_data($post_id, $fields);

			if (empty($data)) {
				return $results;
			}

			if ($this->is_yoast_active()) {
				$results['yoast'] = $this->sync_yoast((int) $post_id, $data);
			}

			if ($this->is_rankmath_active()) {
				$results['rankmath'] = $this->sync_rankmath((int) $post_id, $data);
			}

			if ($this->is_aioseo_active()) {
				$results['aioseo'] = $this->sync_aioseo((int) $post_id, $data);
			}

			// Write sync timestamp
			$ts = get_post_meta($post_id, '_metasync_plugin_sync_ts', true);
			$sync_data = !empty($ts) ? json_decode($ts, true) : [];
			if (!is_array($sync_data)) {
				$sync_data = [];
			}
			if ($results['yoast'] ?? false) {
				$sync_data['yoast'] = gmdate('c');
			}
			if ($results['rankmath'] ?? false) {
				$sync_data['rankmath'] = gmdate('c');
			}
			if ($results['aioseo'] ?? false) {
				$sync_data['aioseo'] = gmdate('c');
			}
			if (!empty($sync_data)) {
				update_post_meta($post_id, '_metasync_plugin_sync_ts', wp_json_encode($sync_data));
			}

			return $results;
		} finally {
			unset($syncing[$post_id]);
		}
	}

	/**
	 * Hook handler for updated_post_meta / added_post_meta.
	 *
	 * Fires sync_post when a watched MetaSync meta key is written.
	 *
	 * @param int    $meta_id    Meta row ID (unused).
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key being written.
	 * @param mixed  $meta_value Meta value being written.
	 */
	public function on_meta_updated($meta_id, $post_id, $meta_key, $meta_value) {
		$watched = [
			// Sidebar / persisted keys
			'_metasync_seo_title'           => 'title',
			'_metasync_seo_desc'            => 'desc',
			'_metasync_metatitle'           => 'title',
			'_metasync_metadesc'            => 'desc',
			'_metasync_robots_index'        => 'noindex',
			'_metasync_og_title'            => 'og_title',
			'_metasync_og_description'      => 'og_desc',
			'_metasync_og_image'            => 'og_image',
			'_metasync_twitter_title'       => 'twitter_title',
			'_metasync_twitter_description' => 'twitter_desc',
			'_metasync_twitter_card'        => 'twitter_card',
			'_metasync_canonical_url'       => 'canonical',
			'_metasync_focus_keyword'       => 'focus_keyword',
			'_metasync_breadcrumb_title'    => 'breadcrumb_title',
			'_metasync_robots_advanced'     => '_robots_advanced_json',
			// OTTO volatile keys (SSR writes these even without persistence)
			'_metasync_otto_title'              => 'title',
			'_metasync_otto_description'        => 'desc',
			'_metasync_otto_og_title'           => 'og_title',
			'_metasync_otto_og_description'     => 'og_desc',
			'_metasync_otto_twitter_title'      => 'twitter_title',
			'_metasync_otto_twitter_description' => 'twitter_desc',
			'_metasync_otto_keywords'           => 'focus_keyword',
			// Legacy meta box keys → rebuild JSON
			'metasync_common_robots'            => '_legacy_robots_to_json',
			'metasync_advance_robots'           => '_legacy_robots_to_json',
		];

		if (!isset($watched[$meta_key])) {
			return;
		}

		$canonical_key = $watched[$meta_key];

		// WP-197 JSON key -- do a full sync + mirror to legacy meta boxes
		if ($canonical_key === '_robots_advanced_json') {
			$this->sync_post((int) $post_id);
			$this->sync_to_legacy_meta((int) $post_id, $meta_value);
			// Re-sync at shutdown only when Yoast is active — Yoast's indexable
			// watcher can overwrite our values during the same request.
			if ($this->is_yoast_active()) {
				$sync_instance = $this;
				$sync_post_id = (int) $post_id;
				add_action('shutdown', function() use ($sync_instance, $sync_post_id) {
					$sync_instance->sync_post($sync_post_id);
				}, 0);
			}
			return;
		}

		// Legacy meta box → rebuild _metasync_robots_advanced JSON
		if ($canonical_key === '_legacy_robots_to_json') {
			$this->sync_legacy_to_json((int) $post_id);
			return;
		}

		// For noindex: convert 'noindex' string to bool
		if ($canonical_key === 'noindex') {
			$value = ($meta_value === 'noindex');
		} else {
			$value = $meta_value;
		}

		$this->sync_post(
			(int) $post_id,
			[$canonical_key => $value]
		);
	}

	// ------------------------------------------------------------------
	// Data collection
	// ------------------------------------------------------------------

	/**
	 * Collect canonical SEO data from all MetaSync post meta.
	 *
	 * Reads all meta in one get_post_custom() call for performance.
	 * When $fields is non-empty, the result is filtered to only those keys.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $fields  Optional pre-resolved canonical key/value pairs.
	 * @return array Canonical data array.
	 */
	private function collect_post_data($post_id, array $fields = []) {
		// If caller already resolved specific fields, return them directly
		if (!empty($fields)) {
			return $fields;
		}

		$all_meta = get_post_custom($post_id);

		$get = function ($key) use ($all_meta) {
			if (!isset($all_meta[$key])) {
				return '';
			}
			return is_array($all_meta[$key]) ? $all_meta[$key][0] : $all_meta[$key];
		};

		$data = [];

		// Helper: first non-empty value from a list of meta keys
		$first = function (...$keys) use ($get) {
			foreach ($keys as $key) {
				$val = $get($key);
				if (!empty($val)) {
					return $val;
				}
			}
			return '';
		};

		// title: sidebar > persisted OTTO > volatile OTTO
		$data['title'] = $first('_metasync_seo_title', '_metasync_metatitle', '_metasync_otto_title');

		// desc: sidebar > persisted OTTO > volatile OTTO
		$data['desc'] = $first('_metasync_seo_desc', '_metasync_metadesc', '_metasync_otto_description');

		// Robots directives: check _metasync_robots_advanced JSON first (WP-197),
		// then fall back to metasync_common_robots array + metasync_advance_robots array
		$robots_json_raw = $get('_metasync_robots_advanced');
		$robots_json = !empty($robots_json_raw) ? json_decode($robots_json_raw, true) : null;

		if (is_array($robots_json)) {
			$data['noindex'] = !empty($robots_json['noindex']);
			$data['nofollow'] = !empty($robots_json['nofollow']);
			$data['noarchive'] = !empty($robots_json['noarchive']);
			$data['nosnippet'] = !empty($robots_json['nosnippet']);
			$data['noimageindex'] = !empty($robots_json['noimageindex']);
			$data['max_snippet'] = isset($robots_json['max_snippet']) ? (int) $robots_json['max_snippet'] : (isset($robots_json['max-snippet']) ? (int) $robots_json['max-snippet'] : null);
			$data['max_image_preview'] = $robots_json['max_image_preview'] ?? $robots_json['max-image-preview'] ?? null;
			$data['max_video_preview'] = isset($robots_json['max_video_preview']) ? (int) $robots_json['max_video_preview'] : (isset($robots_json['max-video-preview']) ? (int) $robots_json['max-video-preview'] : null);
		} else {
			// noindex from dedicated key
			$robots_index = $get('_metasync_robots_index');
			$data['noindex'] = ($robots_index === 'noindex');

			// Common robots array (serialized)
			$common_raw = $get('metasync_common_robots');
			$common_robots = !empty($common_raw) ? maybe_unserialize($common_raw) : [];
			if (!is_array($common_robots)) {
				$common_robots = [];
			}

			$data['nofollow'] = !empty($common_robots['nofollow']);
			$data['noarchive'] = !empty($common_robots['noarchive']);
			$data['nosnippet'] = !empty($common_robots['nosnippet']);
			$data['noimageindex'] = !empty($common_robots['noimageindex']);

			// Advance robots array (serialized)
			$adv_raw = $get('metasync_advance_robots');
			$adv_robots = !empty($adv_raw) ? maybe_unserialize($adv_raw) : [];
			if (!is_array($adv_robots)) {
				$adv_robots = [];
			}

			$data['max_snippet'] = isset($adv_robots['max-snippet']) ? (int) $adv_robots['max-snippet'] : null;
			$data['max_image_preview'] = isset($adv_robots['max-image-preview']) ? $adv_robots['max-image-preview'] : null;
			$data['max_video_preview'] = isset($adv_robots['max-video-preview']) ? (int) $adv_robots['max-video-preview'] : null;
		}

		// Social / OG: persisted > volatile OTTO
		$data['og_title'] = $first('_metasync_og_title', '_metasync_otto_og_title');
		$data['og_desc'] = $first('_metasync_og_description', '_metasync_otto_og_description');
		$data['og_image'] = $get('_metasync_og_image');

		// Twitter: persisted > volatile OTTO
		$data['twitter_title'] = $first('_metasync_twitter_title', '_metasync_otto_twitter_title');
		$data['twitter_desc'] = $first('_metasync_twitter_description', '_metasync_otto_twitter_description');
		$data['twitter_card'] = $get('_metasync_twitter_card');

		// Canonical, focus keyword, breadcrumb
		$data['canonical'] = $get('_metasync_canonical_url');
		$data['focus_keyword'] = $first('_metasync_focus_keyword', '_metasync_otto_keywords');
		$data['breadcrumb_title'] = $get('_metasync_breadcrumb_title');

		return $data;
	}

	// ------------------------------------------------------------------
	// Plugin detectors
	// ------------------------------------------------------------------

	/**
	 * Check whether Yoast SEO (free or premium) is active.
	 *
	 * @return bool
	 */
	private function is_yoast_active() {
		$this->ensure_plugin_api();
		return is_plugin_active('wordpress-seo/wp-seo.php')
			|| is_plugin_active('wordpress-seo-premium/wp-seo-premium.php');
	}

	/**
	 * Check whether Rank Math SEO is active.
	 *
	 * @return bool
	 */
	private function is_rankmath_active() {
		$this->ensure_plugin_api();
		return is_plugin_active('seo-by-rank-math/rank-math.php')
			|| is_plugin_active('seo-by-rankmath/rank-math.php');
	}

	/**
	 * Check whether AIOSEO (free or pro) is active.
	 *
	 * @return bool
	 */
	private function is_aioseo_active() {
		$this->ensure_plugin_api();
		return is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php')
			|| is_plugin_active('all-in-one-seo-pack-pro/all_in_one_seo_pack.php');
	}

	/**
	 * Ensure is_plugin_active() is loaded on the frontend.
	 */
	private function ensure_plugin_api() {
		if (!function_exists('is_plugin_active')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	// ------------------------------------------------------------------
	// Per-plugin sync
	// ------------------------------------------------------------------

	/**
	 * Mirror canonical data into Yoast post meta and indexable cache.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Canonical key/value pairs.
	 * @return bool True once dispatch completes.
	 */
	private function sync_yoast($post_id, array $data) {
		// title
		if (!empty($data['title'])) {
			update_post_meta($post_id, '_yoast_wpseo_title', (string) $data['title']);
		}

		// description -- strip newlines first
		if (!empty($data['desc'])) {
			$desc = str_replace(["\n", "\r", "\t"], ' ', $data['desc']);
			update_post_meta($post_id, '_yoast_wpseo_metadesc', $desc);
		}

		// noindex: '0'=default, '1'=noindex, '2'=index
		if (array_key_exists('noindex', $data)) {
			$val = $data['noindex'] ? '1' : '2';
			update_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', $val);
		}

		// nofollow: '0'=follow, '1'=nofollow
		if (array_key_exists('nofollow', $data)) {
			update_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', $data['nofollow'] ? '1' : '0');
		}

		// advanced robots: comma-separated NO spaces
		$adv = [];
		if (!empty($data['noarchive'])) {
			$adv[] = 'noarchive';
		}
		if (!empty($data['nosnippet'])) {
			$adv[] = 'nosnippet';
		}
		if (!empty($data['noimageindex'])) {
			$adv[] = 'noimageindex';
		}
		update_post_meta($post_id, '_yoast_wpseo_meta-robots-adv', implode(',', $adv));

		// OG
		if (!empty($data['og_title'])) {
			update_post_meta($post_id, '_yoast_wpseo_opengraph-title', $data['og_title']);
		}
		if (!empty($data['og_desc'])) {
			update_post_meta($post_id, '_yoast_wpseo_opengraph-description', $data['og_desc']);
		}
		if (!empty($data['og_image'])) {
			update_post_meta($post_id, '_yoast_wpseo_opengraph-image', esc_url_raw($data['og_image']));
		}

		// Twitter
		if (!empty($data['twitter_title'])) {
			update_post_meta($post_id, '_yoast_wpseo_twitter-title', $data['twitter_title']);
		}
		if (!empty($data['twitter_desc'])) {
			update_post_meta($post_id, '_yoast_wpseo_twitter-description', $data['twitter_desc']);
		}

		// Canonical, focus keyword, breadcrumb
		if (!empty($data['canonical'])) {
			update_post_meta($post_id, '_yoast_wpseo_canonical', esc_url_raw($data['canonical']));
		}
		if (!empty($data['focus_keyword'])) {
			update_post_meta($post_id, '_yoast_wpseo_focuskw', $data['focus_keyword']);
		}
		if (!empty($data['breadcrumb_title'])) {
			update_post_meta($post_id, '_yoast_wpseo_bctitle', $data['breadcrumb_title']);
		}

		// Update wp_yoast_indexable cache row for immediate effect
		global $wpdb;
		$indexable_table = $wpdb->prefix . 'yoast_indexable';

		$updates = [];
		if (!empty($data['title'])) {
			$updates['title'] = mb_substr($data['title'], 0, 191);
		}
		if (!empty($data['desc'])) {
			$updates['description'] = str_replace(["\n", "\r", "\t"], ' ', $data['desc']);
		}
		if (array_key_exists('noindex', $data)) {
			$updates['is_robots_noindex'] = $data['noindex'] ? 1 : 0;
		}
		if (array_key_exists('nofollow', $data)) {
			$updates['is_robots_nofollow'] = $data['nofollow'] ? 1 : 0;
		}
		if (array_key_exists('noarchive', $data)) {
			$updates['is_robots_noarchive'] = !empty($data['noarchive']) ? 1 : 0;
		}
		if (array_key_exists('nosnippet', $data)) {
			$updates['is_robots_nosnippet'] = !empty($data['nosnippet']) ? 1 : 0;
		}
		if (array_key_exists('noimageindex', $data)) {
			$updates['is_robots_noimageindex'] = !empty($data['noimageindex']) ? 1 : 0;
		}
		if (!empty($data['og_title'])) {
			$updates['open_graph_title'] = mb_substr($data['og_title'], 0, 191);
		}
		if (!empty($data['og_image'])) {
			$updates['open_graph_image'] = $data['og_image'];
		}
		if (!empty($data['twitter_title'])) {
			$updates['twitter_title'] = mb_substr($data['twitter_title'], 0, 191);
		}
		if (!empty($data['twitter_card'])) {
			// twitter_card column only exists in newer Yoast versions; skip if absent
			$col_check = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'twitter_card'",
				DB_NAME,
				$indexable_table
			));
			if ($col_check) {
				$updates['twitter_card'] = $data['twitter_card'];
			}
		}
		if (!empty($data['canonical'])) {
			$updates['canonical'] = $data['canonical'];
		}
		if (!empty($data['focus_keyword'])) {
			$updates['primary_focus_keyword'] = mb_substr($data['focus_keyword'], 0, 191);
		}
		if (!empty($data['breadcrumb_title'])) {
			$updates['breadcrumb_title'] = mb_substr($data['breadcrumb_title'], 0, 191);
		}

		if (!empty($updates)) {
			$row_exists = $wpdb->get_var($wpdb->prepare(
				"SELECT id FROM {$indexable_table} WHERE object_id = %d AND object_type = 'post'",
				$post_id
			));

			if ($row_exists) {
				$wpdb->update(
					$indexable_table,
					$updates,
					['object_id' => $post_id, 'object_type' => 'post']
				);
			} else {
				$post = get_post($post_id);
				$insert = array_merge([
					'object_id'        => $post_id,
					'object_type'      => 'post',
					'object_sub_type'  => $post ? $post->post_type : 'post',
					'post_status'      => $post ? $post->post_status : 'publish',
					'author_id'        => $post ? (int) $post->post_author : 0,
					'is_robots_noindex'     => 0,
					'is_robots_nofollow'    => 0,
					'is_robots_noarchive'   => 0,
					'is_robots_nosnippet'   => 0,
					'is_robots_noimageindex' => 0,
					'is_cornerstone'   => 0,
					'created_at'       => current_time('mysql'),
					'updated_at'       => current_time('mysql'),
				], $updates);
				$wpdb->insert($indexable_table, $insert);
			}
		}

		return true;
	}

	/**
	 * Mirror canonical data into Rank Math post meta.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Canonical key/value pairs.
	 * @return bool True once dispatch completes.
	 */
	private function sync_rankmath($post_id, array $data) {
		// title, desc
		if (!empty($data['title'])) {
			update_post_meta($post_id, 'rank_math_title', $data['title']);
		}
		if (!empty($data['desc'])) {
			update_post_meta($post_id, 'rank_math_description', $data['desc']);
		}

		// robots: PHP indexed array
		if (array_key_exists('noindex', $data) || array_key_exists('nofollow', $data)) {
			$existing = get_post_meta($post_id, 'rank_math_robots', true);
			$robots = is_array($existing) ? $existing : [];
			$robots = array_values(array_diff($robots, ['index', 'noindex', 'follow', 'nofollow']));
			if (array_key_exists('noindex', $data)) {
				$robots[] = $data['noindex'] ? 'noindex' : 'index';
			}
			if (array_key_exists('nofollow', $data)) {
				$robots[] = $data['nofollow'] ? 'nofollow' : 'follow';
			}
			update_post_meta($post_id, 'rank_math_robots', array_values(array_unique($robots)));
		}

		// Advanced robots: max-* go into rank_math_advanced_robots
		$adv_keys = ['max_snippet', 'max_image_preview', 'max_video_preview'];
		$has_adv = false;
		foreach ($adv_keys as $k) {
			if (array_key_exists($k, $data)) {
				$has_adv = true;
				break;
			}
		}
		if ($has_adv) {
			$existing_adv = get_post_meta($post_id, 'rank_math_advanced_robots', true);
			$adv = is_array($existing_adv) ? $existing_adv : [];
			if (array_key_exists('max_snippet', $data) && $data['max_snippet'] !== null) {
				$val = (int) $data['max_snippet'];
				$adv['max-snippet'] = 'max-snippet:' . $val;
			}
			if (array_key_exists('max_image_preview', $data) && $data['max_image_preview'] !== null) {
				$allowed = ['none', 'standard', 'large'];
				if (in_array($data['max_image_preview'], $allowed, true)) {
					$adv['max-image-preview'] = 'max-image-preview:' . $data['max_image_preview'];
				}
			}
			if (array_key_exists('max_video_preview', $data) && $data['max_video_preview'] !== null) {
				$val = (int) $data['max_video_preview'];
				$adv['max-video-preview'] = 'max-video-preview:' . $val;
			}
			if (!empty($adv)) {
				update_post_meta($post_id, 'rank_math_advanced_robots', $adv);
			}
		}

		// Sync noarchive/nosnippet/noimageindex into rank_math_robots
		if (array_key_exists('noarchive', $data) || array_key_exists('nosnippet', $data) || array_key_exists('noimageindex', $data)) {
			$existing = get_post_meta($post_id, 'rank_math_robots', true);
			$robots = is_array($existing) ? $existing : [];
			foreach (['noarchive', 'nosnippet', 'noimageindex'] as $dir) {
				if (!array_key_exists($dir, $data)) {
					continue;
				}
				$robots = array_values(array_diff($robots, [$dir]));
				if (!empty($data[$dir])) {
					$robots[] = $dir;
				}
			}
			update_post_meta($post_id, 'rank_math_robots', array_values(array_unique($robots)));
		}

		// OG
		if (!empty($data['og_title'])) {
			update_post_meta($post_id, 'rank_math_facebook_title', $data['og_title']);
		}
		if (!empty($data['og_desc'])) {
			update_post_meta($post_id, 'rank_math_facebook_description', $data['og_desc']);
		}
		if (!empty($data['og_image'])) {
			update_post_meta($post_id, 'rank_math_facebook_image', esc_url_raw($data['og_image']));
			$img_id = attachment_url_to_postid($data['og_image']);
			if ($img_id) {
				update_post_meta($post_id, 'rank_math_facebook_image_id', $img_id);
			}
		}

		// Twitter
		if (!empty($data['twitter_title'])) {
			update_post_meta($post_id, 'rank_math_twitter_title', $data['twitter_title']);
		}
		if (!empty($data['twitter_desc'])) {
			update_post_meta($post_id, 'rank_math_twitter_description', $data['twitter_desc']);
		}
		if (!empty($data['twitter_card'])) {
			$valid_cards = ['summary', 'summary_large_image', 'app', 'player'];
			if (in_array($data['twitter_card'], $valid_cards, true)) {
				update_post_meta($post_id, 'rank_math_twitter_card_type', $data['twitter_card']);
			}
		}

		// Canonical, focus keyword, breadcrumb
		if (!empty($data['canonical'])) {
			update_post_meta($post_id, 'rank_math_canonical_url', esc_url_raw($data['canonical']));
		}
		if (!empty($data['focus_keyword'])) {
			update_post_meta($post_id, 'rank_math_focus_keyword', $data['focus_keyword']);
		}
		if (!empty($data['breadcrumb_title'])) {
			update_post_meta($post_id, 'rank_math_breadcrumb_title', $data['breadcrumb_title']);
		}

		return true;
	}

	/**
	 * Mirror canonical data into the AIOSEO wp_aioseo_posts custom table.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Canonical key/value pairs.
	 * @return bool True when the row was written, false when the table is
	 *              missing or the write failed.
	 */
	private function sync_aioseo($post_id, array $data) {
		global $wpdb;

		$table = $wpdb->prefix . 'aioseo_posts';

		// Bail if the AIOSEO post table does not exist (plugin not initialised).
		$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		if ($table_exists !== $table) {
			return false;
		}

		$row = [];

		if (!empty($data['title'])) {
			$row['title'] = sanitize_text_field($data['title']);
		}
		if (!empty($data['desc'])) {
			$row['description'] = sanitize_text_field($data['desc']);
		}
		if (!empty($data['og_title'])) {
			$row['og_title'] = sanitize_text_field($data['og_title']);
		}
		if (!empty($data['og_desc'])) {
			$row['og_description'] = sanitize_text_field($data['og_desc']);
		}
		if (!empty($data['og_image'])) {
			$row['og_image_type'] = 'custom';
			$row['og_image_custom_url'] = esc_url_raw($data['og_image']);
		}
		if (!empty($data['twitter_title'])) {
			$row['twitter_title'] = sanitize_text_field($data['twitter_title']);
		}
		if (!empty($data['twitter_desc'])) {
			$row['twitter_description'] = sanitize_text_field($data['twitter_desc']);
		}
		if (!empty($data['twitter_card'])) {
			$valid_cards = ['default', 'summary', 'summary_large_image', 'player', 'app'];
			if (in_array($data['twitter_card'], $valid_cards, true)) {
				$row['twitter_card'] = $data['twitter_card'];
			}
		}
		if (!empty($data['canonical'])) {
			$row['canonical_url'] = esc_url_raw($data['canonical']);
		}

		// focus keyword as keyphrases JSON
		if (!empty($data['focus_keyword'])) {
			$row['keyphrases'] = wp_json_encode([
				'focus' => [
					'keyphrase' => sanitize_text_field($data['focus_keyword']),
					'score'     => 0,
					'analysis'  => new \stdClass(),
				],
				'additional' => [],
			]);
		}

		// Robots
		$has_robots = false;
		foreach (['noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex', 'max_snippet', 'max_image_preview', 'max_video_preview'] as $k) {
			if (array_key_exists($k, $data)) {
				$has_robots = true;
				break;
			}
		}
		if ($has_robots) {
			$row['robots_default'] = 0;
			if (array_key_exists('noindex', $data)) {
				$row['robots_noindex'] = $data['noindex'] ? 1 : 0;
			}
			if (array_key_exists('nofollow', $data)) {
				$row['robots_nofollow'] = $data['nofollow'] ? 1 : 0;
			}
			if (array_key_exists('noarchive', $data)) {
				$row['robots_noarchive'] = !empty($data['noarchive']) ? 1 : 0;
			}
			if (array_key_exists('nosnippet', $data)) {
				$row['robots_nosnippet'] = !empty($data['nosnippet']) ? 1 : 0;
			}
			if (array_key_exists('noimageindex', $data)) {
				$row['robots_noimageindex'] = !empty($data['noimageindex']) ? 1 : 0;
			}
			if (array_key_exists('max_snippet', $data) && $data['max_snippet'] !== null) {
				$row['robots_max_snippet'] = (int) $data['max_snippet'];
			}
			if (array_key_exists('max_video_preview', $data) && $data['max_video_preview'] !== null) {
				$row['robots_max_videopreview'] = (int) $data['max_video_preview'];
			}
			if (array_key_exists('max_image_preview', $data) && $data['max_image_preview'] !== null) {
				$allowed = ['none', 'standard', 'large'];
				if (in_array($data['max_image_preview'], $allowed, true)) {
					$row['robots_max_imagepreview'] = $data['max_image_preview'];
				}
			}
		}

		if (empty($row)) {
			return false;
		}

		$row['updated'] = current_time('mysql');

		$existing_id = $wpdb->get_var($wpdb->prepare(
			"SELECT id FROM {$table} WHERE post_id = %d",
			$post_id
		));

		if ($existing_id) {
			return $wpdb->update($table, $row, ['post_id' => $post_id]) !== false;
		}

		// New row -- must include all NOT NULL columns with no defaults
		$row['post_id'] = $post_id;
		$row['created'] = current_time('mysql');
		$robot_defaults = [
			'robots_default'      => isset($row['robots_noindex']) ? 0 : 1,
			'robots_noindex'      => 0,
			'robots_nofollow'     => 0,
			'robots_noarchive'    => 0,
			'robots_nosnippet'    => 0,
			'robots_noimageindex' => 0,
			'robots_noodp'        => 0,
			'robots_notranslate'  => 0,
		];
		$row = array_merge($robot_defaults, $row);

		return $wpdb->insert($table, $row) !== false;
	}

	// ------------------------------------------------------------------
	// Two-way sync: sidebar JSON ↔ legacy meta boxes
	// ------------------------------------------------------------------

	/**
	 * Mirror _metasync_robots_advanced JSON → legacy meta box keys.
	 *
	 * Called when the sidebar writes the JSON key so the classic-editor
	 * meta boxes reflect the same values.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $json_value Raw JSON string from _metasync_robots_advanced.
	 */
	private function sync_to_legacy_meta($post_id, $json_value) {
		static $syncing_legacy = [];
		if (!empty($syncing_legacy[$post_id])) {
			return;
		}
		$syncing_legacy[$post_id] = true;
		// Block sync_legacy_to_json from running while we write legacy keys
		$this->syncing_json_to_legacy[$post_id] = true;

		try {
			$robots = is_string($json_value) ? json_decode($json_value, true) : $json_value;
			if (!is_array($robots)) {
				return;
			}

			// Build metasync_common_robots array
			$common = get_post_meta($post_id, 'metasync_common_robots', true);
			if (!is_array($common)) {
				$common = [];
			}
			foreach (['nofollow', 'noarchive', 'nosnippet', 'noimageindex'] as $dir) {
				if (!empty($robots[$dir])) {
					$common[$dir] = $dir;
				} else {
					unset($common[$dir]);
				}
			}
			if (!empty($common)) {
				update_post_meta($post_id, 'metasync_common_robots', $common);
			} else {
				delete_post_meta($post_id, 'metasync_common_robots');
			}

			// Build metasync_advance_robots array — skip default values to keep legacy clean
			$adv = [];
			if (isset($robots['max_snippet']) && $robots['max_snippet'] !== null && (int) $robots['max_snippet'] !== -1) {
				$adv['max-snippet'] = ['enable' => '1', 'length' => (string) $robots['max_snippet']];
			}
			if (isset($robots['max_image_preview']) && $robots['max_image_preview'] !== null && $robots['max_image_preview'] !== 'large') {
				$adv['max-image-preview'] = ['enable' => '1', 'length' => (string) $robots['max_image_preview']];
			}
			if (isset($robots['max_video_preview']) && $robots['max_video_preview'] !== null && (int) $robots['max_video_preview'] !== -1) {
				$adv['max-video-preview'] = ['enable' => '1', 'length' => (string) $robots['max_video_preview']];
			}
			if (!empty($adv)) {
				update_post_meta($post_id, 'metasync_advance_robots', $adv);
			} else {
				delete_post_meta($post_id, 'metasync_advance_robots');
			}
		} finally {
			unset($syncing_legacy[$post_id]);
			unset($this->syncing_json_to_legacy[$post_id]);
		}
	}

	/**
	 * Rebuild _metasync_robots_advanced JSON from legacy meta box keys.
	 *
	 * Called when the classic-editor meta boxes save metasync_common_robots
	 * or metasync_advance_robots so the sidebar JSON stays in sync.
	 *
	 * @param int $post_id Post ID.
	 */
	private function sync_legacy_to_json($post_id) {
		static $syncing_json = [];
		// If sync_to_legacy_meta is currently running, skip — it already wrote the correct JSON
		if (!empty($this->syncing_json_to_legacy[$post_id])) {
			return;
		}
		if (!empty($syncing_json[$post_id])) {
			return;
		}
		$syncing_json[$post_id] = true;

		try {
			$common = get_post_meta($post_id, 'metasync_common_robots', true);
			if (!is_array($common)) {
				$common = [];
			}
			$adv = get_post_meta($post_id, 'metasync_advance_robots', true);
			if (!is_array($adv)) {
				$adv = [];
			}

			$json = [];

			// Boolean directives from common_robots
			foreach (['nofollow', 'noarchive', 'nosnippet', 'noimageindex'] as $dir) {
				$json[$dir] = !empty($common[$dir]);
			}

			// max-* directives from advance_robots
			if (!empty($adv['max-snippet']['enable'])) {
				$json['max_snippet'] = isset($adv['max-snippet']['length']) ? (int) $adv['max-snippet']['length'] : -1;
			}
			if (!empty($adv['max-image-preview']['enable'])) {
				$json['max_image_preview'] = isset($adv['max-image-preview']['length']) ? (string) $adv['max-image-preview']['length'] : 'large';
			}
			if (!empty($adv['max-video-preview']['enable'])) {
				$json['max_video_preview'] = isset($adv['max-video-preview']['length']) ? (int) $adv['max-video-preview']['length'] : -1;
			}

			// Only write if there's something meaningful
			$has_value = false;
			foreach ($json as $v) {
				if ($v !== false && $v !== null) {
					$has_value = true;
					break;
				}
			}

			if ($has_value) {
				update_post_meta($post_id, '_metasync_robots_advanced', wp_json_encode($json));
			} else {
				delete_post_meta($post_id, '_metasync_robots_advanced');
			}
		} finally {
			unset($syncing_json[$post_id]);
		}
	}
}

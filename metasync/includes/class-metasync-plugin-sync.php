<?php
/**
 * Post-Level SEO Plugin Sync
 *
 * Mirrors MetaSync post meta (`_metasync_*`) into the active third-party
 * SEO plugins' post storage (Yoast, Rank Math, AIOSEO) so that posts and
 * pages render MetaSync-managed values regardless of which plugin is
 * actually rendering the frontend.
 *
 * Manually edited fields mirror immediately. Values that OTTO generated for
 * itself are permitted into third-party storage only while the matching OTTO
 * Persistence setting is enabled — see OTTO_PERSISTENCE_KEYS.
 *
 * @package    MetaSync
 * @subpackage MetaSync/includes
 * @since      2.6.5
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
	 * Guard: tracks post IDs currently being synced from legacy → JSON.
	 *
	 * Prevents sync_to_legacy_meta from firing in response to the JSON write
	 * that sync_legacy_to_json just made. Without this the round trip is
	 * lossy: the JSON format cannot distinguish "user explicitly chose the
	 * default" from "nothing set" (the sidebar always emits max_snippet=-1
	 * and max_image_preview=large), so the mirror back would discard the
	 * legacy meta box values it had just derived the JSON from.
	 *
	 * @var array
	 */
	private $syncing_legacy_to_json = [];

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
		'_metasync_metakeywords',
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
		'_metasync_otto_canonical',
	];

	/**
	 * Internal OTTO meta keys mapped to the OTTO Persistence setting that
	 * governs whether their value may reach third-party SEO plugin storage.
	 *
	 * OTTO writes these keys on every sync regardless of any setting, because
	 * its own render filters and the admin reporting columns read them. Copying
	 * one into Yoast / Rank Math / AIOSEO is a different act: it is a permanent
	 * write into another plugin's storage that outlives MetaSync, which is
	 * exactly what the Persistence settings exist to control.
	 *
	 * Manually edited MetaSync fields are deliberately absent from this map.
	 * Saving those in the editor is an explicit user action, so they sync
	 * immediately and never consult these settings.
	 *
	 * @var array<string,string>
	 */
	const OTTO_PERSISTENCE_KEYS = [
		'_metasync_otto_title'               => 'meta_title',
		'_metasync_otto_description'         => 'meta_description',
		'_metasync_otto_keywords'            => 'meta_keywords',
		'_metasync_metakeywords'             => 'meta_keywords',
		'_metasync_otto_og_title'            => 'og_title',
		'_metasync_otto_og_description'      => 'og_description',
		'_metasync_otto_twitter_title'       => 'twitter_title',
		'_metasync_otto_twitter_description' => 'twitter_description',
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
	 * Whether a meta key's value may be mirrored into third-party SEO storage.
	 *
	 * Returns true for every key that is not an internal OTTO key, so manually
	 * edited MetaSync fields are unaffected. For the OTTO keys the matching
	 * Persistence setting decides.
	 *
	 * The class_exists() guard mirrors the call sites in
	 * otto/metasync-otto-seo-functions.php, including their fail-closed
	 * behaviour: a partially updated install can leave this file newer than the
	 * settings class, and with the class missing there is no setting that could
	 * authorise a permanent write into another plugin's storage.
	 *
	 * @param string $meta_key Meta key being written.
	 * @return bool True when the value may be synced.
	 */
	private function otto_persistence_allows($meta_key) {
		if (!isset(self::OTTO_PERSISTENCE_KEYS[$meta_key])) {
			return true;
		}

		return class_exists('Metasync_Otto_Persistence_Settings')
			&& Metasync_Otto_Persistence_Settings::should_persist(
				self::OTTO_PERSISTENCE_KEYS[$meta_key]
			);
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
			// Internal OTTO keys. OTTO stores these on every sync regardless of
			// any setting; whether they may reach third-party storage is decided
			// by OTTO_PERSISTENCE_KEYS above.
			'_metasync_otto_title'              => 'title',
			'_metasync_otto_description'        => 'desc',
			'_metasync_otto_og_title'           => 'og_title',
			'_metasync_otto_og_description'     => 'og_desc',
			'_metasync_otto_twitter_title'      => 'twitter_title',
			'_metasync_otto_twitter_description' => 'twitter_desc',
			'_metasync_otto_keywords'           => 'focus_keyword',
			'_metasync_metakeywords'            => 'focus_keyword',
			// Legacy meta box keys → rebuild JSON
			'metasync_common_robots'            => '_legacy_robots_to_json',
			'metasync_advance_robots'           => '_legacy_robots_to_json',
		];

		if (!isset($watched[$meta_key])) {
			return;
		}

		// An internal OTTO field reaches third-party SEO storage only while its
		// OTTO Persistence setting is enabled. With the setting off the value
		// stays stored under its own key -- OTTO keeps rendering and reporting
		// on it -- but Yoast / Rank Math / AIOSEO are left untouched. Manually
		// edited MetaSync fields are not in the map and so are never gated.
		if (!$this->otto_persistence_allows($meta_key)) {
			return;
		}

		$canonical_key = $watched[$meta_key];

		// JSON key -- do a full sync + mirror to legacy meta boxes
		if ($canonical_key === '_robots_advanced_json') {
			// A JSON write made by sync_legacy_to_json() while rebuilding
			// from the legacy meta boxes: the legacy-originating call below
			// runs the third-party sync itself once the rebuild finishes, so
			// running one here as well would write (and back up) the same
			// values twice. The shutdown re-sync is still wanted — it guards
			// against Yoast's indexable watcher, which reacts to the same
			// request either way.
			$nested_legacy_rebuild = !empty($this->syncing_legacy_to_json[$post_id]);
			if (!$nested_legacy_rebuild) {
				$this->sync_post((int) $post_id);
			}
			$this->sync_to_legacy_meta((int) $post_id, $meta_value);
			// Re-sync at shutdown only when Yoast is active — Yoast's indexable
			// watcher can overwrite our values during the same request.
			if ($this->is_yoast_active()) {
				$sync_instance = $this;
				$sync_post_id = (int) $post_id;
				add_action('shutdown', function() use ($sync_instance, $sync_post_id) {
					// By shutdown this request has typically seeded the OG defaults
					// memo for the post (the sync above reads it); the row write
					// has since landed, so drop the memo or the re-sync below
					// compares against the pre-save title/excerpt.
					// @phpstan-ignore-next-line function.alreadyNarrowedType
					if (method_exists('Metasync_OpenGraph', 'clear_default_og_values_memo')) {
						Metasync_OpenGraph::clear_default_og_values_memo();
					}
					$sync_instance->sync_post($sync_post_id);
				}, 0);
			}
			return;
		}

		// Legacy meta box → rebuild _metasync_robots_advanced JSON
		if ($canonical_key === '_legacy_robots_to_json') {
			$this->sync_legacy_to_json((int) $post_id);
			// Propagate the meta-box robots to any active third-party plugin.
			// Without this the box only ever reached the JSON mirror, so a
			// noindex ticked there never reached Yoast/Rank Math/AIOSEO while
			// a later unrelated sync happily stamped their affirmative index
			// over it. The JSON write inside sync_legacy_to_json() can fire a
			// nested on_meta_updated() for _metasync_robots_advanced; that
			// nested call is short-circuited (see the branch above) so the
			// third-party sync happens exactly once, here, with the rebuilt
			// values.
			$this->sync_post((int) $post_id);
			return;
		}

		// For noindex: convert 'noindex' string to bool
		if ($canonical_key === 'noindex') {
			$value = ($meta_value === 'noindex');
			$fields = [$canonical_key => $value];
			if ($meta_value === 'index') {
				// A dedicated-key 'index' is a deliberate choice, not the
				// absence of noindex — flag it so the writers emit the
				// third-party's affirmative index encoding for it.
				$fields['robots_index_explicit'] = true;
			}
		} else {
			$value = $meta_value;
			$fields = [$canonical_key => $value];
		}

		$this->sync_post(
			(int) $post_id,
			$fields
		);
	}

	/**
	 * Hook handler for deleted_post_meta.
	 *
	 * Only the legacy meta box keys are handled here. Unticking the last
	 * checkbox in the Common Robots meta box deletes metasync_common_robots
	 * instead of updating it, so without a delete hook the mirrored
	 * _metasync_robots_advanced JSON kept the stale directive — and because the
	 * JSON is the highest-priority source in the output resolver, the page went
	 * on emitting a directive the editor had just cleared.
	 *
	 * Deletes of the JSON key itself are deliberately NOT routed into
	 * on_meta_updated: that path mirrors the value back onto the legacy meta
	 * boxes, so an empty value would wipe them.
	 *
	 * @param array  $meta_ids Meta row IDs (unused).
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key being deleted.
	 */
	public function on_meta_deleted($meta_ids, $post_id, $meta_key) {
		if ($meta_key !== 'metasync_common_robots' && $meta_key !== 'metasync_advance_robots') {
			return;
		}

		$this->sync_legacy_to_json((int) $post_id);

		// With third-party mirroring disabled there is no downstream value to
		// clear. Besides avoiding needless work, this keeps the legacy mirror
		// hook safe in lightweight non-WordPress contexts that intentionally
		// exercise only the JSON rebuild.
		if (!$this->third_party_writes_allowed()) {
			return;
		}

		// Mirror the removal into third-party storage, mirroring the
		// propagation on_meta_updated() now does for the update path. When
		// directives remain, a full sync rewrites them and clears the removed
		// axis through its default encoding. When nothing remains, the
		// collector's no-opinion gate would skip every robots write — right
		// for an unrelated sync, wrong here, where the surviving third-party
		// values are ones this plugin mirrored earlier. Send an explicit
		// all-clear so the writers reset those fields to their defaults
		// instead of leaving a stale noindex behind. Null max-* values mean
		// "remove the directive", not "leave it alone" — and this payload is
		// the only null producer left: collect_post_data() omits max-* keys
		// it has no stored value for.
		$remaining = Metasync_Seo_Output::robots_meta_snapshot($post_id);
		if (Metasync_Seo_Output::resolve_robots_value_raw($remaining, false) === ''
			&& !$this->resolve_explicit_index_intent($remaining)) {
			$this->sync_post((int) $post_id, [
				'noindex'          => false,
				'nofollow'         => false,
				'noarchive'        => false,
				'nosnippet'        => false,
				'noimageindex'     => false,
				'max_snippet'      => null,
				'max_image_preview' => null,
				'max_video_preview' => null,
			]);
			return;
		}

		$this->sync_post((int) $post_id);
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
		// If caller already resolved specific fields, return them directly.
		// Canonical still gets validated — this branch serves the
		// updated_post_meta fast-path, which would otherwise mirror a raw
		// (possibly corrupted) value into third-party storage.
		if (!empty($fields)) {
			if (array_key_exists('canonical', $fields)) {
				$fields['canonical'] = Metasync_Canonical_Sanitizer::sanitize($fields['canonical']);
				if ($fields['canonical'] === '') {
					unset($fields['canonical']);
				}
			}

			// Same reasoning for the social title/description fields: on this
			// fast-path the value arrives straight from the meta write, so a stored
			// "Auto Draft" pre-fill placeholder would be mirrored verbatim into
			// Yoast/RankMath/AIOSEO and emitted there as og:title. Drop it instead so
			// each plugin keeps whatever it already has.
			// @phpstan-ignore-next-line function.alreadyNarrowedType
			if (method_exists('Metasync_OpenGraph', 'is_auto_draft_title')) {
				foreach (['og_title', 'og_desc', 'twitter_title', 'twitter_desc'] as $social_field) {
					if (array_key_exists($social_field, $fields)
						&& Metasync_OpenGraph::is_auto_draft_title($fields[$social_field])
					) {
						unset($fields[$social_field]);
					}
				}
			}

			return $this->apply_feature_flags_to_payload($fields);
		}

		$all_meta = get_post_custom($post_id);

		$get = function ($key) use ($all_meta, $post_id) {
			if (!isset($all_meta[$key])) {
				return '';
			}
			$value = is_array($all_meta[$key]) ? $all_meta[$key][0] : $all_meta[$key];

			// The meta box pre-fills the social title/description fields from the post
			// title, which is the "Auto Draft" placeholder on a brand-new post. Collapse
			// it to '' here so the placeholder is never mirrored into Yoast/RankMath/
			// AIOSEO storage, where those plugins would emit it as og:title.
			// Same sanitize-at-the-source placement as Metasync_Canonical_Sanitizer below.
			//
			// method_exists (not just class_exists) for the same reason
			// sync_layer_handles() checks: this runs on meta writes during front-end
			// requests, and a partially updated install can leave an older
			// class-metasync-opengraph.php beside this file.
			// @phpstan-ignore-next-line function.alreadyNarrowedType
			if (method_exists('Metasync_OpenGraph', 'strip_auto_draft_title')
				&& defined('Metasync_OpenGraph::AUTO_DRAFT_PRONE_KEYS')
				&& in_array($key, Metasync_OpenGraph::AUTO_DRAFT_PRONE_KEYS, true)
			) {
				$value = Metasync_OpenGraph::strip_auto_draft_title($value);
			}

			// A social title that is a verbatim snapshot of the post title is the
			// old pre-fill, not a customization — mirroring it would hand a
			// third-party plugin a copy that a rename leaves stale (and that
			// outranks OTTO's fresh value there). Collapsed for the same reason
			// as the placeholder above; the chain in $first then falls through
			// to OTTO's staging key.
			// @phpstan-ignore-next-line function.alreadyNarrowedType
			if (method_exists('Metasync_OpenGraph', 'strip_title_snapshot')
				&& defined('Metasync_OpenGraph::TITLE_DEFAULTED_KEYS')
				&& in_array($key, Metasync_OpenGraph::TITLE_DEFAULTED_KEYS, true)
			) {
				$value = Metasync_OpenGraph::strip_title_snapshot($post_id, $key, $value);
			}

			// A social description that is a verbatim snapshot of the resolved
			// excerpt is the old pre-fill, not a customization — mirroring it
			// would hand a third-party plugin a copy that a later excerpt or
			// content edit leaves stale. Collapsed for the same reason as the
			// title snapshot above.
			// @phpstan-ignore-next-line function.alreadyNarrowedType
			if (method_exists('Metasync_OpenGraph', 'strip_description_snapshot')
				&& defined('Metasync_OpenGraph::DESCRIPTION_DEFAULTED_KEYS')
				&& in_array($key, Metasync_OpenGraph::DESCRIPTION_DEFAULTED_KEYS, true)
			) {
				$value = Metasync_OpenGraph::strip_description_snapshot($post_id, $key, $value);
			}

			return $value;
		};

		$data = [];

		// Helper: first non-empty value from a list of meta keys.
		//
		// Internal OTTO keys are skipped while their OTTO Persistence setting is
		// disabled. Gating here and not only in on_meta_updated() is what makes
		// the setting hold for the callers that re-read meta themselves rather
		// than passing a resolved field: the sync at the end of OTTO SSR
		// processing (metasync_update_comprehensive_seo_fields), the MCP OTTO
		// refresh tool, and the robots full-sync branch below.
		//
		// Each chain lists the manually edited key first and the internal OTTO
		// key last, so a customer-entered value is unaffected and still wins.
		$first = function (...$keys) use ($get) {
			foreach ($keys as $key) {
				if (!$this->otto_persistence_allows($key)) {
					continue;
				}
				$val = $get($key);
				if (!empty($val)) {
					return $val;
				}
			}
			return '';
		};

		// Order comes from Metasync_Seo_Precedence, the one place it is defined.
		//
		// Imported values come last so a sync triggered by something else still
		// reports a title rather than an empty one. They are deliberately NOT in
		// WATCHED_KEYS or the on_meta_updated map above: a sync mirrors the
		// canonical value into an active third-party plugin's own storage, and
		// that plugin renders at a higher effective precedence than OTTO — so
		// syncing on an imported write would put the imported title back in
		// front of OTTO, which is the exact behaviour this key exists to avoid.
		$data['title'] = $first(...Metasync_Seo_Precedence::keys(Metasync_Seo_Precedence::FIELD_TITLE));

		$data['desc'] = $first(...Metasync_Seo_Precedence::keys(Metasync_Seo_Precedence::FIELD_DESCRIPTION));

		// Robots directives: check _metasync_robots_advanced JSON first,
		// then fall back to metasync_common_robots array + metasync_advance_robots array
		$robots_json_raw = $get('_metasync_robots_advanced');
		$robots_json = !empty($robots_json_raw) ? json_decode($robots_json_raw, true) : null;

		// The empty-array guard mirrors the emitter's own JSON branch, which
		// falls through to meta_robots / the checkbox arrays when the JSON
		// holds nothing: an "empty" JSON must not make the collector ignore
		// storage the front end still renders.
		if (is_array($robots_json) && $robots_json !== []) {
			$data['noindex'] = $this->resolve_synced_noindex(
				$robots_json,
				$get('_metasync_robots_index'),
				$get('metasync_common_robots')
			);
			$data['nofollow'] = !empty($robots_json['nofollow']);
			$data['noarchive'] = !empty($robots_json['noarchive']);
			$data['nosnippet'] = !empty($robots_json['nosnippet']);
			$data['noimageindex'] = !empty($robots_json['noimageindex']);

			// max-* directives — each key is set only when the JSON actually
			// carries it. Null is reserved for the explicit all-clear payload
			// on_meta_deleted() sends, where it means "remove the directive";
			// emitting it here for a merely absent key made every unrelated
			// full sync delete Rank Math max-* values MetaSync never owned.
			if (isset($robots_json['max_snippet'])) {
				$data['max_snippet'] = (int) $robots_json['max_snippet'];
			} elseif (isset($robots_json['max-snippet'])) {
				$data['max_snippet'] = (int) $robots_json['max-snippet'];
			}
			if (isset($robots_json['max_image_preview'])) {
				$data['max_image_preview'] = $robots_json['max_image_preview'];
			} elseif (isset($robots_json['max-image-preview'])) {
				$data['max_image_preview'] = $robots_json['max-image-preview'];
			}
			if (isset($robots_json['max_video_preview'])) {
				$data['max_video_preview'] = (int) $robots_json['max_video_preview'];
			} elseif (isset($robots_json['max-video-preview'])) {
				$data['max_video_preview'] = (int) $robots_json['max-video-preview'];
			}
		} else {
			// noindex: dedicated key, falling back to the Common Robots checkbox
			// array — same sources the front-end emitter honours.
			$data['noindex'] = $this->resolve_synced_noindex(
				null,
				$get('_metasync_robots_index'),
				$get('metasync_common_robots')
			);

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

			// meta_robots (the REST string) renders verbatim whenever the
			// advanced JSON holds nothing — the emitter's branch 2 — so its
			// tokens are as much MetaSync's stored opinion as the checkbox
			// array. Reading only the checkboxes reported a REST noindex as
			// noindex=false, which the writers stamped into Yoast/Rank Math
			// as an affirmative index over the customer's deindex.
			$meta_robots_string = strtolower(trim((string) $get('meta_robots')));
			if ($meta_robots_string !== '') {
				$meta_robots_tokens = array_filter(array_map('trim', explode(',', $meta_robots_string)));
				foreach (['noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex'] as $dir) {
					$data[$dir] = !empty($data[$dir]) || in_array($dir, $meta_robots_tokens, true);
				}
			}

			// Advance robots array (serialized)
			$adv_raw = $get('metasync_advance_robots');
			$adv_robots = !empty($adv_raw) ? maybe_unserialize($adv_raw) : [];
			if (!is_array($adv_robots)) {
				$adv_robots = [];
			}

			// Each advance-robots directive is stored as ['enable' => .., 'length' => ..].
			// Read the length scalar (honouring the enable flag) instead of casting the
			// whole sub-array — (int) of a non-empty array is 1, which is what produced the
			// "max-snippet:1" instead of "-1" and dropped the image-preview value. Mirrors
			// sync_legacy_to_json() so both directions read the legacy format identically.
			// Same omission rule as the JSON branch above: an absent directive
			// is "no opinion", never a null all-clear.
			if (!empty($adv_robots['max-snippet']['enable'])) {
				$data['max_snippet'] = isset($adv_robots['max-snippet']['length']) ? (int) $adv_robots['max-snippet']['length'] : -1;
			}
			if (!empty($adv_robots['max-image-preview']['enable'])) {
				$data['max_image_preview'] = isset($adv_robots['max-image-preview']['length']) ? (string) $adv_robots['max-image-preview']['length'] : 'large';
			}
			if (!empty($adv_robots['max-video-preview']['enable'])) {
				$data['max_video_preview'] = isset($adv_robots['max-video-preview']['length']) ? (int) $adv_robots['max-video-preview']['length'] : -1;
			}
		}

		// "No opinion" must never be written into third-party robots storage:
		// when MetaSync holds no robots value anywhere — nothing the emitter
		// would render, and no explicit index marker — drop every robots key
		// so the writers below skip them entirely. Otherwise an unrelated
		// sync (a title edit is enough) would stamp an affirmative index over
		// whatever the third-party plugin already held. The check reuses
		// holds_robots_value() on purpose: it resolves the same storage with
		// the feature flags applied, exactly like the emitter and the
		// suppression side. A raw resolution here let a value belonging to a
		// disabled feature (an advanced max-* with the metabox switched off)
		// keep the gate open, and the flag pass then dropped the owned axis
		// while the unowned booleans were stamped as defaults.
		$explicit_index = $this->resolve_explicit_index_intent($all_meta);
		if (!Metasync_Seo_Output::holds_robots_value($all_meta) && !$explicit_index) {
			unset(
				$data['noindex'],
				$data['nofollow'],
				$data['noarchive'],
				$data['nosnippet'],
				$data['noimageindex'],
				$data['max_snippet'],
				$data['max_image_preview'],
				$data['max_video_preview']
			);
		} elseif ($explicit_index) {
			// MetaSync holds a deliberate "index" for this post. Carried so
			// the writers can distinguish a genuine index from the mere
			// absence of noindex and write the third-party's affirmative
			// encoding only for the former.
			$data['robots_index_explicit'] = true;
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
		// Canonical is validated at the source so a corrupted value ("Array")
		// never propagates into Yoast/RankMath/AIOSEO storage.
		$data['canonical'] = Metasync_Canonical_Sanitizer::sanitize($get('_metasync_canonical_url'));
		// The keyword takes its order from the resolver — customer value, then
		// OTTO's volatile tier, then OTTO's persisted tier — so the persistence
		// gating in $first() reaches the persisted copy too. Previously the
		// persisted copy sat on the customer key, outranking the live one.
		$data['focus_keyword'] = $first(...Metasync_Seo_Precedence::keys(Metasync_Seo_Precedence::FIELD_FOCUS_KEYWORD));
		$data['breadcrumb_title'] = $get('_metasync_breadcrumb_title');

		return $this->apply_feature_flags_to_payload($data);
	}

	/**
	 * Drop payload keys whose owning feature is switched off in Editor Settings.
	 *
	 * A disabled meta box means MetaSync must not interfere with that feature
	 * anywhere: it emits nothing, suppresses nothing, and — the part this
	 * guard covers — does not mirror its values into Yoast / Rank Math /
	 * AIOSEO storage. Without the drop, a sync fired while the feature is off
	 * resolves the MetaSync value with the feature already stripped and then
	 * writes that result over the third-party plugin's own value (Rank Math's
	 * stored noindex replaced by a plain 'index'). The writers are all
	 * array_key_exists-guarded, so removing a key here skips every write of it.
	 *
	 * @param  array $data Canonical payload keyed by canonical field name.
	 * @return array
	 */
	private function apply_feature_flags_to_payload(array $data) {
		if (Metasync_Feature_Flags::is_disabled(Metasync_Feature_Flags::COMMON_ROBOTS)) {
			unset(
				$data['noindex'],
				$data['nofollow'],
				$data['noarchive'],
				$data['nosnippet'],
				$data['noimageindex'],
				$data['robots_index_explicit']
			);
		}
		if (Metasync_Feature_Flags::is_disabled(Metasync_Feature_Flags::ADVANCE_ROBOTS)) {
			unset(
				$data['max_snippet'],
				$data['max_image_preview'],
				$data['max_video_preview']
			);
		}
		if (Metasync_Feature_Flags::is_disabled(Metasync_Feature_Flags::SOCIAL_OG)) {
			unset(
				$data['og_title'],
				$data['og_desc'],
				$data['og_image'],
				$data['twitter_title'],
				$data['twitter_desc'],
				$data['twitter_card']
			);
		}
		if (Metasync_Feature_Flags::is_disabled(Metasync_Feature_Flags::CANONICAL)) {
			unset($data['canonical']);
		}

		return $data;
	}

	/**
	 * Resolve the noindex directive for syncing the way the front-end emitter
	 * does: any of the three sources saying noindex wins, and none can veto
	 * another. Every source is read on purpose: the JSON rebuilt by
	 * sync_legacy_to_json() carries noindex only when the box ticked it, and
	 * the sidebar payload can never carry the key at all, so the JSON alone
	 * cannot be trusted to reflect the checkbox or the dedicated key —
	 * reading it alone reported a checkbox-only noindex as false, and the
	 * writers translated that into an explicit 'index' that overwrote a
	 * noindex the third-party plugin already held.
	 *
	 * @param  mixed $robots_json  Decoded _metasync_robots_advanced payload (or null).
	 * @param  mixed $robots_index Raw _metasync_robots_index meta value.
	 * @param  mixed $common_raw   Raw metasync_common_robots meta value.
	 * @return bool
	 */
	private function resolve_synced_noindex($robots_json, $robots_index, $common_raw) {
		if (is_array($robots_json) && !empty($robots_json['noindex'])) {
			return true;
		}
		if ($robots_index === 'noindex') {
			return true;
		}
		if (!empty($common_raw)) {
			$common = maybe_unserialize($common_raw);
			if (is_array($common) && !empty($common['noindex'])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether MetaSync holds a deliberate "index" for the post, as opposed to
	 * the mere absence of a noindex.
	 *
	 * Three storages can express it: the dedicated sidebar key set to
	 * 'index', the classic Common Robots meta box's Index checkbox, and the
	 * advanced JSON carrying an explicit noindex:false entry. A JSON rebuilt
	 * from the legacy meta boxes carries noindex only when the box ticked it,
	 * so a meta-box save that left the checkbox alone can never surface here
	 * as a deliberate index. Only a genuine choice justifies writing the
	 * third-party plugin's affirmative index encoding — '2' for Yoast,
	 * 'index' for Rank Math.
	 *
	 * @param  array $all_meta Result of get_post_custom($post_id).
	 * @return bool
	 */
	private function resolve_explicit_index_intent(array $all_meta) {
		if (($all_meta['_metasync_robots_index'][0] ?? '') === 'index') {
			return true;
		}

		$common_raw = $all_meta['metasync_common_robots'][0] ?? '';
		if (!empty($common_raw)) {
			$common = maybe_unserialize($common_raw);
			if (is_array($common) && !empty($common['index'])) {
				return true;
			}
		}

		$advanced_raw = $all_meta['_metasync_robots_advanced'][0] ?? '';
		if (!empty($advanced_raw)) {
			$advanced = json_decode($advanced_raw, true);
			if (is_array($advanced) && array_key_exists('noindex', $advanced) && !$advanced['noindex']) {
				return true;
			}
		}

		return false;
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
	 * Whether third-party SEO storage may be written at all.
	 *
	 * The site owner's consent switch. Checked once per plugin dispatch rather
	 * than per field so a sync that is not permitted does no work and, more
	 * importantly, leaves no half-written row behind.
	 *
	 * @return bool
	 */
	private function third_party_writes_allowed() {
		return class_exists('Metasync_Seo_Backup') && Metasync_Seo_Backup::is_enabled();
	}

	/**
	 * Write one third-party post-meta field, preserving what it held before.
	 *
	 * This bridge runs on `updated_post_meta`, which means it fires from inside
	 * an OTTO sync's `_metasync_otto_*` write — before the same sync reaches its
	 * own direct Rank Math / Yoast writes further down. It is therefore usually
	 * the first code to touch the customer's value, and the backup it takes here
	 * is the one that holds the true original.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Third-party meta key.
	 * @param mixed  $value   Value to write.
	 * @return bool True when the write happened.
	 */
	private function write_post_field($post_id, $key, $value) {
		return class_exists('Metasync_Seo_Backup')
			&& Metasync_Seo_Backup::write_post_meta($post_id, $key, $value);
	}

	/**
	 * Mirror canonical data into Yoast post meta and indexable cache.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Canonical key/value pairs.
	 * @return bool True when at least one value-bearing field was mirrored,
	 *              false when the payload carried nothing to write. The caller
	 *              stamps `_metasync_plugin_sync_ts` from this, so it must
	 *              describe what was written and not merely that dispatch was
	 *              reached.
	 *
	 *              Robots directives are excluded from that answer on purpose.
	 *              They are written whenever MetaSync holds a robots value for
	 *              the post (the collector drops them otherwise), so they say
	 *              nothing about whether a title or description existed to
	 *              mirror. Counting them would let a robots-only payload pass
	 *              as a content sync, which is the bug this contract fixes.
	 */
	private function sync_yoast($post_id, array $data) {
		if (!$this->third_party_writes_allowed()) {
			return false;
		}

		$wrote = false;

		// title
		if (!empty($data['title'])) {
			$wrote = $this->write_post_field($post_id, '_yoast_wpseo_title', (string) $data['title']);
		}

		// description -- strip newlines first
		if (!empty($data['desc'])) {
			$desc = str_replace(["\n", "\r", "\t"], ' ', $data['desc']);
			$wrote = $this->write_post_field($post_id, '_yoast_wpseo_metadesc', $desc) || $wrote;
		}

		// noindex: '0'=default, '1'=noindex, '2'=index. Only a deliberate
		// index (the dedicated key, the meta box checkbox, or the advanced
		// JSON's explicit noindex:false) earns '2'; a payload that merely
		// holds no noindex writes '0' so Yoast falls back to its own default
		// instead of an affirmative index MetaSync never chose.
		if (array_key_exists('noindex', $data)) {
			if ($data['noindex']) {
				$val = '1';
			} else {
				$val = !empty($data['robots_index_explicit']) ? '2' : '0';
			}
			$this->write_post_field($post_id, '_yoast_wpseo_meta-robots-noindex', $val);
		}

		// nofollow: '0'=follow, '1'=nofollow
		if (array_key_exists('nofollow', $data)) {
			$this->write_post_field($post_id, '_yoast_wpseo_meta-robots-nofollow', $data['nofollow'] ? '1' : '0');
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
		// advanced robots: comma-separated NO spaces. Guarded on the payload
		// actually carrying robots keys: a no-opinion sync (the collector
		// drops them all) must not blank a field MetaSync has no opinion
		// about. Opinion-carrying payloads — including the explicit all-clear
		// — always carry the keys, so clearing the last directive still
		// clears the field. Like the other robots writes it carries no
		// canonical value, so it does not make this a successful sync.
		if (array_intersect(
			['noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex', 'max_snippet', 'max_image_preview', 'max_video_preview'],
			array_keys($data)
		)) {
			$this->write_post_field($post_id, '_yoast_wpseo_meta-robots-adv', implode(',', $adv));
		}

		// OG
		if (!empty($data['og_title'])) {
			$wrote = $this->write_post_field($post_id, '_yoast_wpseo_opengraph-title', $data['og_title']) || $wrote;
		}
		if (!empty($data['og_desc'])) {
			$wrote = $this->write_post_field($post_id, '_yoast_wpseo_opengraph-description', $data['og_desc']) || $wrote;
		}
		if (!empty($data['og_image'])) {
			$wrote = $this->write_post_field($post_id, '_yoast_wpseo_opengraph-image', esc_url_raw($data['og_image'])) || $wrote;
		}

		// Twitter
		if (!empty($data['twitter_title'])) {
			$wrote = $this->write_post_field($post_id, '_yoast_wpseo_twitter-title', $data['twitter_title']) || $wrote;
		}
		if (!empty($data['twitter_desc'])) {
			$wrote = $this->write_post_field($post_id, '_yoast_wpseo_twitter-description', $data['twitter_desc']) || $wrote;
		}

		// Canonical, focus keyword, breadcrumb
		if (!empty($data['canonical'])) {
			$wrote = $this->write_post_field($post_id, '_yoast_wpseo_canonical', esc_url_raw($data['canonical'])) || $wrote;
		}
		if (!empty($data['focus_keyword'])) {
			$wrote = $this->write_post_field($post_id, '_yoast_wpseo_focuskw', $data['focus_keyword']) || $wrote;
		}
		if (!empty($data['breadcrumb_title'])) {
			$wrote = $this->write_post_field($post_id, '_yoast_wpseo_bctitle', $data['breadcrumb_title']) || $wrote;
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
			$col_check = $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- mirrors SEO meta into third-party plugin storage (Yoast indexables/AIOSEO tables) — no WordPress API for their schemas
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
			$row_exists = $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- mirrors SEO meta into third-party plugin storage (Yoast indexables/AIOSEO tables) — no WordPress API for their schemas
				"SELECT id FROM {$wpdb->prefix}yoast_indexable WHERE object_id = %d AND object_type = 'post'",
				$post_id
			));

			// This probe decides two things at once: the value of the write-once
			// row_existed marker, and whether the write below is an UPDATE or an
			// INSERT. A failed probe answers "no row" for a row that is really
			// there, which commits a '0' marker telling a restore to delete the
			// customer's row and fires an INSERT against a row that already
			// exists. Neither is correctable afterwards, so an unreadable probe
			// has to abandon the indexable write entirely.
			//
			// The post meta above has already landed, so the receipt still
			// reports it. Only the indexable is skipped.
			if (!Metasync_Seo_Backup::db_read_succeeded()) {
				return $wrote;
			}

			// No original saved means no write, exactly as on the AIOSEO table.
			// This row is what Yoast actually renders from, so a restore that
			// cannot reach it puts the meta back and leaves the customer's live
			// pages still showing OTTO's values.
			$backups_created = [];
			if (!$this->backup_yoast_indexable_columns($post_id, $indexable_table, $updates, (bool) $row_exists, $backups_created)) {
				return $wrote;
			}

			if ($row_exists) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- mirrors SEO meta into third-party plugin storage (Yoast indexables/AIOSEO tables) — no WordPress API for their schemas
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

				// The row_existed='0' marker was recorded before this insert,
				// because a marker that will not save has to be able to veto the
				// write. A failed insert means no row of ours exists, and a
				// marker left saying otherwise would let a restore delete a row
				// Yoast or the customer creates afterwards. The per-column
				// backups recorded beside it go too: they are write-once, so a
				// column captured as NULL because there was no row would stay
				// NULL for good and blank a real value the customer later puts
				// in that row.
				//
				// The post meta above has already been written, so the return
				// still reports what landed. Claiming nothing was written would
				// tell the conflict handler this post is unsynced to Yoast while
				// Yoast's own meta holds our values, and it would hand tag
				// ownership to the wrong plugin.
				if ($wpdb->insert($indexable_table, $insert) === false) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- mirrors SEO meta into third-party plugin storage (Yoast indexables/AIOSEO tables) — no WordPress API for their schemas
					Metasync_Seo_Backup::discard_backups('post', $post_id, $backups_created);
					return $wrote;
				}
			}
		}

		return $wrote;
	}

	/**
	 * Preserve the wp_yoast_indexable columns this sync is about to overwrite.
	 *
	 * Yoast serves the frontend from this table, not from post meta, so the
	 * meta backups taken by write_post_field() do not by themselves make the
	 * change reversible. Same shape as backup_aioseo_columns():
	 *
	 *  - one backup per column being written, so a restore can put the originals
	 *    back and leave every other column of the user's row alone;
	 *  - whether the row existed at all, so a restore can delete a row that only
	 *    exists because we created it instead of leaving an empty shell behind.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $table       Fully prefixed indexable table name.
	 * @param array  $updates     Columns and values about to be written.
	 * @param bool   $row_existed Whether Yoast already had a row for this post.
	 * @param array  $created     Out-param, filled with the backup fields this
	 *                            call created, so a failed write can withdraw
	 *                            exactly its own rows and no one else's.
	 * @return bool True when every original was preserved and the caller may write.
	 */
	private function backup_yoast_indexable_columns($post_id, $table, array $updates, $row_existed, array &$created) {
		$created = [];

		if (!class_exists('Metasync_Seo_Backup')) {
			return false;
		}

		global $wpdb;

		if (!Metasync_Seo_Backup::record_marker(
			'post',
			$post_id,
			'yoast_indexable_row_existed',
			$row_existed ? '1' : '0',
			$marker_created
		)) {
			return false;
		}

		if ($marker_created) {
			$created[] = 'yoast_indexable_row_existed';
		}

		$columns = array_keys($updates);
		if (empty($columns)) {
			return true;
		}

		$current = null;
		if ($row_existed) {
			$select = '`' . implode('`, `', array_map('esc_sql', $columns)) . '`';
			// Column list is esc_sql()'d above; the table is a fixed literal.
			$current = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- mirrors SEO meta into third-party plugin storage (Yoast indexables/AIOSEO tables) — no WordPress API for their schemas
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column list esc_sql()'d above, table is a fixed literal
					"SELECT {$select} FROM {$wpdb->prefix}yoast_indexable WHERE object_id = %d AND object_type = 'post'",
					$post_id
				),
				ARRAY_A
			);

			// The row was there a moment ago, so a null answer now is a failed
			// read, not an empty row. Recording it as "every column was NULL"
			// would tell a later restore to delete values it should put back,
			// which is the exact loss this layer exists to prevent. No write is
			// happening, so the marker recorded above has to go too — a marker
			// left describing a write that never ran is a stale story.
			if ($current === null || !Metasync_Seo_Backup::db_read_succeeded()) {
				Metasync_Seo_Backup::discard_backups('post', $post_id, $created);
				return false;
			}
		}

		foreach ($columns as $column) {
			// A missing row and a NULL column are the same thing to a restore:
			// there was no value here, so put nothing back.
			$current_value = ($current !== null && isset($current[$column])) ? $current[$column] : null;

			$field = 'yoast_indexable_' . $column;

			// One unsaved column is enough to refuse the whole write: a
			// half-original, half-OTTO row is something no restore can unpick.
			// The refusal also means the caller writes nothing, so withdraw the
			// marker and the columns recorded so far — the same rule as the
			// failed-insert path in sync_yoast(). Left behind, a row_existed='0'
			// marker would let a restore delete a row the customer creates
			// later, and a NULL column backup would blank a real value in it.
			if (!Metasync_Seo_Backup::backup_before_overwrite(
				'post',
				$post_id,
				$field,
				$updates[$column],
				$current_value,
				$column_created
			)) {
				Metasync_Seo_Backup::discard_backups('post', $post_id, $created);
				return false;
			}

			if ($column_created) {
				$created[] = $field;
			}
		}

		return true;
	}

	/**
	 * Mirror canonical data into Rank Math post meta.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Canonical key/value pairs.
	 * @return bool True when at least one value-bearing field was mirrored,
	 *              false when the payload carried nothing to write. Robots
	 *              directives are excluded for the reason given on sync_yoast().
	 */
	private function sync_rankmath($post_id, array $data) {
		if (!$this->third_party_writes_allowed()) {
			return false;
		}

		$wrote = false;

		// title, desc
		if (!empty($data['title'])) {
			$wrote = $this->write_post_field($post_id, 'rank_math_title', $data['title']);
		}
		if (!empty($data['desc'])) {
			$wrote = $this->write_post_field($post_id, 'rank_math_description', $data['desc']) || $wrote;
		}

		// robots: PHP indexed array
		if (array_key_exists('noindex', $data) || array_key_exists('nofollow', $data)) {
			$existing = get_post_meta($post_id, 'rank_math_robots', true);
			$robots = is_array($existing) ? $existing : [];
			$robots = array_values(array_diff($robots, ['index', 'noindex', 'follow', 'nofollow']));
			if (array_key_exists('noindex', $data)) {
				if ($data['noindex']) {
					$robots[] = 'noindex';
				} elseif (!empty($data['robots_index_explicit'])) {
					// A deliberate index earns Rank Math's explicit encoding;
					// otherwise the directive stays absent and Rank Math's own
					// defaults decide — MetaSync holds no opinion to impose.
					$robots[] = 'index';
				}
			}
			if (array_key_exists('nofollow', $data)) {
				$robots[] = $data['nofollow'] ? 'nofollow' : 'follow';
			}
			$this->write_post_field($post_id, 'rank_math_robots', array_values(array_unique($robots)));
		}

		// Advanced robots: max-* go into rank_math_advanced_robots. A null
		// value means "remove the directive" (the all-clear payload), not
		// "leave it alone".
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
			if (array_key_exists('max_snippet', $data)) {
				if ($data['max_snippet'] === null) {
					unset($adv['max-snippet']);
				} else {
					$adv['max-snippet'] = (string) (int) $data['max_snippet'];
				}
			}
			if (array_key_exists('max_image_preview', $data)) {
				if ($data['max_image_preview'] === null) {
					unset($adv['max-image-preview']);
				} else {
					$allowed = ['none', 'standard', 'large'];
					if (in_array($data['max_image_preview'], $allowed, true)) {
						$adv['max-image-preview'] = (string) $data['max_image_preview'];
					}
				}
			}
			if (array_key_exists('max_video_preview', $data)) {
				if ($data['max_video_preview'] === null) {
					unset($adv['max-video-preview']);
				} else {
					$adv['max-video-preview'] = (string) (int) $data['max_video_preview'];
				}
			}
			if (!empty($adv)) {
				$this->write_post_field($post_id, 'rank_math_advanced_robots', $adv);
			} else {
				// Every directive was cleared — drop the meta so Rank Math
				// falls back to its own defaults instead of an empty array.
				$this->write_post_field($post_id, 'rank_math_advanced_robots', '');
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
			$this->write_post_field($post_id, 'rank_math_robots', array_values(array_unique($robots)));
		}

		// OG
		if (!empty($data['og_title'])) {
			$wrote = $this->write_post_field($post_id, 'rank_math_facebook_title', $data['og_title']) || $wrote;
		}
		if (!empty($data['og_desc'])) {
			$wrote = $this->write_post_field($post_id, 'rank_math_facebook_description', $data['og_desc']) || $wrote;
		}
		if (!empty($data['og_image'])) {
			$wrote = $this->write_post_field($post_id, 'rank_math_facebook_image', esc_url_raw($data['og_image'])) || $wrote;
			$img_id = attachment_url_to_postid($data['og_image']);
			if ($img_id) {
				$wrote = $this->write_post_field($post_id, 'rank_math_facebook_image_id', $img_id) || $wrote;
			}
		}

		// Twitter
		if (!empty($data['twitter_title'])) {
			$wrote = $this->write_post_field($post_id, 'rank_math_twitter_title', $data['twitter_title']) || $wrote;
		}
		if (!empty($data['twitter_desc'])) {
			$wrote = $this->write_post_field($post_id, 'rank_math_twitter_description', $data['twitter_desc']) || $wrote;
		}
		if (!empty($data['twitter_card'])) {
			$valid_cards = ['summary', 'summary_large_image', 'app', 'player'];
			if (in_array($data['twitter_card'], $valid_cards, true)) {
				$wrote = $this->write_post_field($post_id, 'rank_math_twitter_card_type', $data['twitter_card']) || $wrote;
			}
		}

		// Canonical, focus keyword, breadcrumb
		if (!empty($data['canonical'])) {
			$wrote = $this->write_post_field($post_id, 'rank_math_canonical_url', esc_url_raw($data['canonical'])) || $wrote;
		}
		if (!empty($data['focus_keyword'])) {
			$wrote = $this->write_post_field($post_id, 'rank_math_focus_keyword', $data['focus_keyword']) || $wrote;
		}
		if (!empty($data['breadcrumb_title'])) {
			$wrote = $this->write_post_field($post_id, 'rank_math_breadcrumb_title', $data['breadcrumb_title']) || $wrote;
		}

		return $wrote;
	}

	/**
	 * Preserve the AIOSEO columns a sync is about to overwrite.
	 *
	 * AIOSEO keeps post SEO data in its own `aioseo_posts` table rather than in
	 * post meta, so there is no meta row to save and no field a restore could
	 * delete. Two things are recorded instead, both as post meta on the post
	 * itself:
	 *
	 *  - one backup per column being written, so a restore can put the original
	 *    values back and leave every other column of the user's row alone;
	 *  - whether the row existed at all, so a restore can delete a row that only
	 *    exists because we created it instead of leaving an empty shell behind.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $table       Fully prefixed AIOSEO table name.
	 * @param array  $row         Columns and values about to be written.
	 * @param bool   $row_existed Whether AIOSEO already had a row for this post.
	 * @param array  $created     Out-param, filled with the backup fields this
	 *                            call created, so a failed write can withdraw
	 *                            exactly its own rows and no one else's.
	 * @return bool True when every original was preserved and the caller may write.
	 */
	private function backup_aioseo_columns($post_id, $table, array $row, $row_existed, array &$created) {
		$created = [];

		if (!class_exists('Metasync_Seo_Backup')) {
			return false;
		}

		global $wpdb;

		// Whether the row pre-existed is what a restore uses to choose between
		// putting the original columns back and deleting a row that only exists
		// because we made it. A marker that will not record is as disqualifying
		// as a column that will not.
		if (!Metasync_Seo_Backup::record_marker(
			'post',
			$post_id,
			'aioseo_row_existed',
			$row_existed ? '1' : '0',
			$marker_created
		)) {
			return false;
		}

		if ($marker_created) {
			$created[] = 'aioseo_row_existed';
		}

		$columns = array_diff(array_keys($row), ['updated', 'created', 'post_id']);
		if (empty($columns)) {
			return true;
		}

		$current = null;
		if ($row_existed) {
			$select = '`' . implode('`, `', array_map('esc_sql', $columns)) . '`';
			// Column list is esc_sql()'d above; the table is a fixed literal.
			$current = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- mirrors SEO meta into third-party plugin storage (Yoast indexables/AIOSEO tables) — no WordPress API for their schemas
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column list esc_sql()'d above, table is a fixed literal
					"SELECT {$select} FROM {$wpdb->prefix}aioseo_posts WHERE post_id = %d",
					$post_id
				),
				ARRAY_A
			);

			// The row was there a moment ago, so a null answer now is a failed
			// read, not an empty row. Recording it as "every column was NULL"
			// would tell a later restore to delete values it should put back,
			// which is the exact loss this layer exists to prevent. No write is
			// happening, so the marker recorded above has to go too — a marker
			// left describing a write that never ran is a stale story.
			if ($current === null || !Metasync_Seo_Backup::db_read_succeeded()) {
				Metasync_Seo_Backup::discard_backups('post', $post_id, $created);
				return false;
			}
		}

		foreach ($columns as $column) {
			// A missing row and a NULL column are the same thing to a restore:
			// there was no value here, so put nothing back.
			$current_value = ($current !== null && isset($current[$column])) ? $current[$column] : null;

			$field = 'aioseo_' . $column;

			// One unsaved column is enough to refuse the whole write: a half-original,
			// half-OTTO row is something no restore can unpick. The refusal also
			// means the caller writes nothing, so withdraw the marker and the
			// columns recorded so far — the same rule as the failed-insert path
			// in sync_aioseo(). Left behind, a row_existed='0' marker would let
			// a restore delete a row the customer creates later, and a NULL
			// column backup would blank a real value in it.
			if (!Metasync_Seo_Backup::backup_before_overwrite(
				'post',
				$post_id,
				$field,
				$row[$column],
				$current_value,
				$column_created
			)) {
				Metasync_Seo_Backup::discard_backups('post', $post_id, $created);
				return false;
			}

			if ($column_created) {
				$created[] = $field;
			}
		}

		return true;
	}

	/**
	 * Mirror canonical data into the AIOSEO wp_aioseo_posts custom table.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Canonical key/value pairs.
	 * @return bool True when at least one value-bearing field was written,
	 *              false when the table is missing, the write failed, or the
	 *              payload carried only robots directives. Robots are excluded
	 *              for the reason given on sync_yoast(), so the receipt means
	 *              the same thing for all three plugins.
	 */
	private function sync_aioseo($post_id, array $data) {
		global $wpdb;

		if (!$this->third_party_writes_allowed()) {
			return false;
		}

		$table = $wpdb->prefix . 'aioseo_posts';

		// Bail if the AIOSEO post table does not exist (plugin not initialised).
		$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- mirrors SEO meta into third-party plugin storage (Yoast indexables/AIOSEO tables) — no WordPress API for their schemas
		if ($table_exists !== $table) {
			return false;
		}

		$row = [];
		// Robots fill $row too, so a separate flag is needed: the row is still
		// written for a robots-only payload, it just is not a content sync.
		$wrote = false;

		if (!empty($data['title'])) {
			$row['title'] = sanitize_text_field($data['title']);
			$wrote = true;
		}
		if (!empty($data['desc'])) {
			$row['description'] = sanitize_text_field($data['desc']);
			$wrote = true;
		}
		if (!empty($data['og_title'])) {
			$row['og_title'] = sanitize_text_field($data['og_title']);
			$wrote = true;
		}
		if (!empty($data['og_desc'])) {
			$row['og_description'] = sanitize_text_field($data['og_desc']);
			$wrote = true;
		}
		if (!empty($data['og_image'])) {
			$row['og_image_type'] = 'custom';
			$row['og_image_custom_url'] = esc_url_raw($data['og_image']);
			$wrote = true;
		}
		if (!empty($data['twitter_title'])) {
			$row['twitter_title'] = sanitize_text_field($data['twitter_title']);
			$wrote = true;
		}
		if (!empty($data['twitter_desc'])) {
			$row['twitter_description'] = sanitize_text_field($data['twitter_desc']);
			$wrote = true;
		}
		if (!empty($data['twitter_card'])) {
			$valid_cards = ['default', 'summary', 'summary_large_image', 'player', 'app'];
			if (in_array($data['twitter_card'], $valid_cards, true)) {
				$row['twitter_card'] = $data['twitter_card'];
				$wrote = true;
			}
		}
		if (!empty($data['canonical'])) {
			$row['canonical_url'] = esc_url_raw($data['canonical']);
			$wrote = true;
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
			$wrote = true;
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
				if ($data['noindex']) {
					$row['robots_noindex'] = 1;
				} elseif (!empty($data['robots_index_explicit'])) {
					$row['robots_noindex'] = 0;
				} else {
					// No opinion on the index axis. AIOSEO's column is a bare
					// boolean with no "unset" encoding, so an index carried by
					// other directives must still be written as 0 to keep a
					// stale noindex from surviving — but when this is the only
					// per-post directive in play, robots_default=1 hands the
					// axis back to AIOSEO's global setting instead.
					$other_directives = array_key_exists('nofollow', $data)
						|| array_key_exists('noarchive', $data)
						|| array_key_exists('nosnippet', $data)
						|| array_key_exists('noimageindex', $data)
						|| array_key_exists('max_snippet', $data)
						|| array_key_exists('max_image_preview', $data)
						|| array_key_exists('max_video_preview', $data);
					if ($other_directives) {
						$row['robots_noindex'] = 0;
					} else {
						$row['robots_default'] = 1;
						$row['robots_noindex'] = 0;
					}
				}
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

		$existing_id = $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- mirrors SEO meta into third-party plugin storage (Yoast indexables/AIOSEO tables) — no WordPress API for their schemas
			"SELECT id FROM {$wpdb->prefix}aioseo_posts WHERE post_id = %d",
			$post_id
		));

		// An unreadable probe cannot be treated as "no row". It would commit a
		// write-once row_existed='0' for a row AIOSEO really has -- which a
		// restore reads as licence to delete it -- and send an INSERT at a row
		// that already exists. Leave AIOSEO's row alone and let the next sync
		// record the truth.
		if (!Metasync_Seo_Backup::db_read_succeeded()) {
			return false;
		}

		// No original saved means no write. Overwriting anyway is the data loss
		// this whole layer exists to prevent.
		if ($existing_id) {
			$backups_created = [];
			if (!$this->backup_aioseo_columns($post_id, $table, $row, true, $backups_created)) {
				return false;
			}

			return ($wpdb->update($table, $row, ['post_id' => $post_id]) !== false) && $wrote; // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- mirrors SEO meta into third-party plugin storage (Yoast indexables/AIOSEO tables) — no WordPress API for their schemas
		}

		$backups_created = [];
		if (!$this->backup_aioseo_columns($post_id, $table, $row, false, $backups_created)) {
			return false;
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

		$inserted = $wpdb->insert($table, $row); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- mirrors SEO meta into third-party plugin storage (Yoast indexables/AIOSEO tables) — no WordPress API for their schemas

		// The row_existed='0' marker was recorded before the insert, because a
		// marker that will not save has to be able to veto the write. If the
		// insert then failed there is no row of ours, and leaving the marker
		// behind would let a restore delete a row the customer creates later.
		// The per-column backups beside it go too, or a column captured as NULL
		// for a row that never existed would blank a real value the customer
		// later puts in one.
		if ($inserted === false) {
			Metasync_Seo_Backup::discard_backups('post', $post_id, $backups_created);
			return false;
		}

		return $wrote;
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
		// Our own sync_legacy_to_json() write triggered this. The legacy meta
		// box values are the source of truth in that direction, and mirroring
		// back would drop any value this function treats as a default (-1 /
		// large), so bail out and leave the legacy keys alone.
		if (!empty($this->syncing_legacy_to_json[$post_id])) {
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
			foreach (['noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex'] as $dir) {
				// The index axis is mirrored only when the payload actually
				// carries the key. The sidebar payload and the MCP post-meta
				// tool omit noindex from the JSON entirely, and a JSON rebuilt
				// from the classic box carries it only when the box ticked it —
				// so an absent key means "no opinion", not "false". Unsetting
				// the stored axis on every sidebar edit silently dropped a
				// classic noindex, and the follow-up full sync then handed the
				// page back to the third-party plugin's default, making a
				// deliberately noindexed page indexable again.
				if ($dir === 'noindex' && !array_key_exists('noindex', $robots)) {
					continue;
				}
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
		// Block sync_to_legacy_meta from reacting to the JSON write below
		$this->syncing_legacy_to_json[$post_id] = true;

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

			// Boolean directives from common_robots. noindex is written only
			// when ticked: resolve_explicit_index_intent() reads a JSON
			// noindex:false as a deliberate index, and an unticked checkbox is
			// the absence of a choice, not that choice. Keeping the rebuilt
			// JSON free of a bare noindex:false preserves the invariant that
			// function's JSON branch relies on (see its docblock).
			foreach (['nofollow', 'noarchive', 'nosnippet', 'noimageindex'] as $dir) {
				$json[$dir] = !empty($common[$dir]);
			}
			if (!empty($common['noindex'])) {
				$json['noindex'] = true;
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
			unset($this->syncing_legacy_to_json[$post_id]);
		}
	}
}

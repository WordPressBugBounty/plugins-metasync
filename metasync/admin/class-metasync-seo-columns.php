<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * SEO meta columns for the posts list table.
 *
 * Adds four opt-in columns (SEO Title, SEO Meta Desc, Noindex, Nofollow) to the
 * Posts / Pages / public-CPT list tables so editors can audit SEO meta at a glance
 * instead of opening each post individually.
 *
 * Values are read from post meta only — never from the OTTO API. OTTO persists its
 * suggestions to `_metasync_otto_title` / `_metasync_otto_description` whenever it
 * syncs a page (see otto/metasync-otto-seo-functions.php), so the list screen needs
 * no network calls.
 *
 * @since      3.0.0
 * @package    Metasync
 * @subpackage Metasync/admin
 */
class Metasync_SEO_Columns
{
	/** @var self|null */
	private static $instance = null;

	/** Column IDs. */
	const COL_TITLE    = 'metasync_seo_title';
	const COL_DESC     = 'metasync_seo_desc';
	const COL_NOINDEX  = 'metasync_noindex';
	const COL_NOFOLLOW = 'metasync_nofollow';

	/** Recommended maximum lengths, used for the character counters. */
	const TITLE_MAX_LEN = 60;
	const DESC_MAX_LEN  = 160;

	/**
	 * Get the singleton instance.
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
	}

	/**
	 * All column IDs this class owns.
	 *
	 * @return array
	 */
	public static function get_column_ids()
	{
		return array(self::COL_TITLE, self::COL_DESC, self::COL_NOINDEX, self::COL_NOFOLLOW);
	}

	/**
	 * A companion plugin that already renders these exact columns.
	 *
	 * WebJIVE's "LNR SEO Columns" was written to fill this gap before the feature
	 * existed, and it uses byte-identical column IDs. With both active WordPress
	 * shows one column but runs both render callbacks, so every cell would print
	 * two stacked values.
	 */
	const COMPANION_PLUGIN_CLASS = 'WebJIVE_Metasync_SEO_Columns';

	/**
	 * Whether a companion plugin owns these columns already.
	 *
	 * When it does we stand down entirely rather than double-render, and surface a
	 * notice telling the site owner they can retire it. Nothing breaks either way —
	 * this only avoids duplicated cell content.
	 *
	 * @return bool
	 */
	public static function companion_plugin_active()
	{
		return class_exists(self::COMPANION_PLUGIN_CLASS);
	}

	/**
	 * Whether the columns should appear for a given post type.
	 *
	 * Reuses the SEO Health post-type list so the two features never drift: posts,
	 * pages, WooCommerce products, plus every public + publicly_queryable CPT, minus
	 * builder-internal types (Elementor library, WP templates, Oxygen, Divi layouts).
	 *
	 * @param  string $post_type Post type slug.
	 * @return bool
	 */
	public static function is_supported_post_type($post_type)
	{
		if (empty($post_type) || !class_exists('Metasync_SEO_Health')) {
			return false;
		}
		if (self::companion_plugin_active()) {
			return false;
		}
		return in_array($post_type, Metasync_SEO_Health::get_supported_post_types(), true);
	}

	/**
	 * Tell the site owner they no longer need the companion plugin.
	 *
	 * @return void
	 */
	public function companion_plugin_notice()
	{
		if (!self::companion_plugin_active() || !current_user_can('activate_plugins')) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || empty($screen->base) || $screen->base !== 'edit') {
			return;
		}

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			esc_html__(
				'SEO columns are now built into this plugin. The separate "LNR SEO Columns" plugin is still active and is providing them instead — you can deactivate it to use the built-in version.',
				'metasync'
			)
		);
	}

	/**
	 * Label prefix for the column headings.
	 *
	 * Honours white labelling so a reseller sees their own brand in the column
	 * headers rather than "SEO".
	 *
	 * @return string Company name when white labelled, otherwise an empty string.
	 */
	private function get_label_prefix()
	{
		if (!class_exists('Metasync')) {
			return '';
		}
		$company = Metasync::get_whitelabel_company_name();
		return !empty($company) ? $company . ' ' : '';
	}

	/**
	 * Add the columns to the list table.
	 *
	 * Inserted after the Title column so they read left-to-right with the post name.
	 *
	 * @param  array  $columns   Existing columns.
	 * @param  string $post_type Post type (only passed on the `manage_posts_columns` filter).
	 * @return array
	 */
	public function add_columns($columns, $post_type = '')
	{
		if (empty($post_type)) {
			$screen = function_exists('get_current_screen') ? get_current_screen() : null;
			$post_type = ($screen && !empty($screen->post_type)) ? $screen->post_type : 'page';
		}

		if (!self::is_supported_post_type($post_type)) {
			return $columns;
		}

		$prefix = $this->get_label_prefix();

		$new_columns = array();
		foreach ($columns as $key => $value) {
			$new_columns[$key] = $value;
			if ($key === 'title') {
				$new_columns[self::COL_TITLE]    = esc_html($prefix . __('SEO Title', 'metasync'));
				$new_columns[self::COL_DESC]     = esc_html($prefix . __('SEO Meta Desc', 'metasync'));
				$new_columns[self::COL_NOINDEX]  = esc_html($prefix . __('Noindex', 'metasync'));
				$new_columns[self::COL_NOFOLLOW] = esc_html($prefix . __('Nofollow', 'metasync'));
			}
		}

		// Defensive: if the table has no `title` column, still expose ours.
		foreach (self::get_column_ids() as $id) {
			if (!isset($new_columns[$id])) {
				$new_columns[$id] = esc_html($prefix . $id);
			}
		}

		return $new_columns;
	}

	/**
	 * Hide the columns by default for a user who has never set column preferences.
	 *
	 * Core only calls this filter when `manage{$screen->id}columnshidden` is absent
	 * for the user (wp-admin/includes/screen.php, get_hidden_columns()). Anyone who
	 * has already touched Screen Options on this screen skips it entirely, which is
	 * what hidden_columns() below exists to cover.
	 *
	 * @param  array          $hidden Column IDs hidden by default.
	 * @param  WP_Screen|null $screen Current screen.
	 * @return array
	 */
	public function default_hidden_columns($hidden, $screen)
	{
		if (!$this->screen_wants_columns($screen)) {
			return $hidden;
		}
		return array_values(array_unique(array_merge($hidden, self::get_column_ids())));
	}

	/**
	 * Hide the columns once for users whose saved preferences predate this feature.
	 *
	 * Core runs this filter unconditionally and passes `$use_defaults`, so it is the
	 * only place a new column can be hidden for an existing user. Without it, anyone
	 * who had ever adjusted Screen Options got all four columns switched on the
	 * moment they upgraded — the opposite of the opt-in this feature promises.
	 *
	 * A filtered value is not saved by core, so returning the merged list alone would
	 * hide the columns for a single request and then let them reappear for good. We
	 * therefore write it to the same user-meta key core reads and Screen Options
	 * saves to (`wp-admin/includes/ajax-actions.php`, wp_ajax_hidden_columns()).
	 *
	 * The marker is per screen, because posts and pages keep separate preferences.
	 * Once set we never touch that user's choice again, so a later deliberate opt-in
	 * survives.
	 *
	 * @param  array          $hidden       Hidden column IDs.
	 * @param  WP_Screen|null $screen       Current screen.
	 * @param  bool           $use_defaults Whether core fell back to the defaults.
	 * @return array
	 */
	public function hidden_columns($hidden, $screen, $use_defaults = false)
	{
		if (!$this->screen_wants_columns($screen)) {
			return $hidden;
		}

		$user_id = get_current_user_id();
		if (!$user_id) {
			return $hidden;
		}

		$flag = 'metasync_seo_columns_init_' . $screen->id;
		if (!empty(get_user_meta($user_id, $flag, true))) {
			return $hidden;
		}

		// No stored preference: default_hidden_columns() already hid our columns this
		// request. Record it so the user's first deliberate opt-in is not re-hidden.
		if ($use_defaults) {
			update_user_meta($user_id, $flag, '1');
			return $hidden;
		}

		// Stored preference predating this feature — persist the merge, then mark.
		$hidden = array_values(array_unique(array_merge($hidden, self::get_column_ids())));
		$pref_key = 'manage' . $screen->id . 'columnshidden';
		update_user_meta($user_id, $pref_key, $hidden);

		// Only seal the migration once the preference has demonstrably stored. If the
		// write failed — a transient DB error, or an update_user_metadata filter
		// rejecting this key — marking anyway would leave the columns visible from the
		// next request onward with no possibility of a retry.
		//
		// Read back rather than trust the return value: update_user_meta() also
		// returns false when the stored value was already identical.
		$stored = get_user_meta($user_id, $pref_key, true);
		if (is_array($stored) && !array_diff(self::get_column_ids(), $stored)) {
			update_user_meta($user_id, $flag, '1');
		}

		return $hidden;
	}

	/**
	 * Whether the current screen is a list table that carries our columns.
	 *
	 * Mirrors add_columns(): a screen we never add columns to must not get migration
	 * state either, or a post type that later becomes supported would find a stale
	 * marker and show all four columns immediately.
	 *
	 * @param  WP_Screen|null $screen Current screen.
	 * @return bool
	 */
	private function screen_wants_columns($screen)
	{
		if ($screen === null || empty($screen->base) || $screen->base !== 'edit') {
			return false;
		}
		if (empty($screen->post_type)) {
			return false;
		}
		return self::is_supported_post_type($screen->post_type);
	}

	/**
	 * Render a cell.
	 *
	 * Reads the whole meta array once per row. WP_Query primes the post meta cache for
	 * the list table (`update_post_meta_cache` defaults to true), so this is an object
	 * cache read rather than a query, and costs the same regardless of how many of our
	 * columns are enabled.
	 *
	 * @param string $column_name Column being rendered.
	 * @param int    $post_id     Post ID.
	 * @return void
	 */
	public function render_column($column_name, $post_id)
	{
		if (!in_array($column_name, self::get_column_ids(), true)) {
			return;
		}
		if (self::companion_plugin_active()) {
			return;
		}

		$all_meta = get_post_meta($post_id);
		if (!is_array($all_meta)) {
			$all_meta = array();
		}

		switch ($column_name) {
			case self::COL_TITLE:
				echo $this->render_text_cell($post_id, 'title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper
				break;
			case self::COL_DESC:
				echo $this->render_text_cell($post_id, 'description'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper
				break;
			case self::COL_NOINDEX:
				echo $this->render_robots_cell($all_meta, 'noindex'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper
				break;
			case self::COL_NOFOLLOW:
				echo $this->render_robots_cell($all_meta, 'nofollow'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper
				break;
		}
	}

	/**
	 * Resolve the value the front end will actually render for this post.
	 *
	 * The order below mirrors what the rendering code does, verified across every
	 * path that can set the title or description on a singular request:
	 *
	 *   1. `_metasync_seo_*`  — the sidebar / Classic meta box value. Wins everywhere:
	 *      the sidebar filter runs at `pre_get_document_title` priority 100, after
	 *      OTTO's at 99 (admin/class-metasync-seo-sidebar.php:95 vs
	 *      otto/metasync-otto-seo-functions.php:3070), and OTTO's own HTML rewrite
	 *      re-forces it at the end (otto/Otto_html_class.php:1708).
	 *   2. `_metasync_otto_*` — OTTO's persisted suggestion.
	 *   3. `meta_description` — legacy key the original emitter still prints
	 *      (public/class-metasync-seo-output.php:271). Description only.
	 *   4. A third-party SEO plugin's own key, but ONLY when that plugin is active.
	 *      A leftover `_yoast_wpseo_title` from a deactivated Yoast renders nothing,
	 *      so surfacing it would be inventing a value.
	 *
	 * `_metasync_metatitle` / `_metasync_metadesc` are deliberately absent. They are
	 * mirrors written for third-party plugins and the MCP tools; no singular render
	 * path reads them (confirmed by two independent traces). Ranking them above OTTO
	 * — as Metasync_SEO_Health still does — makes the column disagree with the page.
	 *
	 * Reads post meta only, with one exception: the OTTO disable test also consults
	 * the manual URL exclusion list, which costs a URL resolution per row plus a
	 * single cached read of the exclusion set for the whole request. On the Pages
	 * table that resolution is not free — get_permalink() walks ancestors through
	 * get_page_uri() for a nested page, which WP_Query has not primed, and it fires
	 * the post_link/page_link filters that translation and permalink plugins hook.
	 * Everything else here is free: WP_Query has already primed the post meta cache
	 * for this list table.
	 *
	 * @param  int    $post_id Post ID.
	 * @param  string $field   'title' or 'description'.
	 * @return array{value:string,source:string} Source is '' for a user-owned value.
	 */
	private function resolve_rendered_value($post_id, $field)
	{
		$is_title = ($field === 'title');

		// MetaSync's own tiers come from Metasync_Seo_Precedence, the one place
		// the order is defined.
		//
		// OTTO stands down entirely for a post with the per-post disable flag set
		// (otto/metasync-otto-seo-functions.php:2582 -> the title filter returns the
		// incoming title untouched). Its persisted suggestion is then dead data, so
		// reporting it would name a value the page does not use.
		//
		// The persisted-OTTO tier is left out here, which is what this column has
		// always done. SEO Health does include it, so the two surfaces can disagree
		// about a post whose only value is `_metasync_metatitle`; carried forward
		// unchanged rather than resolved as a side effect of consolidating.
		$chain = Metasync_Seo_Precedence::chain(
			$is_title
				? Metasync_Seo_Precedence::FIELD_TITLE
				: Metasync_Seo_Precedence::FIELD_DESCRIPTION,
			Metasync_Seo_Precedence::TYPE_POST,
			array(
				'include_otto'           => !$this->otto_disabled_for_post($post_id),
				'include_persisted_otto' => false,
			)
		);

		$third_party = $this->get_active_plugin_meta_keys($is_title);

		// The legacy `meta_description` tag is only emitted when no active SEO plugin
		// takes over: should_output_legacy_description() returns false for Yoast and
		// Rank Math, which auto-generate their own. So an active plugin outranks the
		// legacy key; with no plugin active the legacy key is what prints.
		if (!$is_title) {
			if (!empty($third_party)) {
				$chain += $third_party;
				$chain['meta_description'] = 'Synced';
			} else {
				$chain['meta_description'] = 'Synced';
			}
		} else {
			$chain += $third_party;
		}

		foreach ($chain as $meta_key => $source) {
			$value = get_post_meta($post_id, $meta_key, true);
			if (!empty($value) && is_string($value)) {
				// `source` is no longer rendered — the columns show the value only. It is
				// still returned so precedence can be asserted directly in tests.
				return array('value' => $value, 'source' => $source);
			}
		}

		return array('value' => '', 'source' => '');
	}

	/**
	 * Whether OTTO output is switched off for this specific post.
	 *
	 * Delegates to Metasync_Otto_Frontend_Toolbar::is_otto_disabled(), which reports
	 * the effective status: the `_metasync_otto_disabled` meta flag OR a manual entry
	 * on the Compatibility page's "Excluded URLs" list. Both stop OTTO rendering,
	 * so both have to stop the column naming OTTO's value as the rendered one.
	 *
	 * The fallback repeats the meta test verbatim for the case where the toolbar class
	 * is not loaded. It treats only '1' and 'true' as disabled: a looser truthiness
	 * test would read the string 'false' as disabled and wrongly hide OTTO's value
	 * from the column while the front end still serves it.
	 *
	 * @param  int $post_id Post ID.
	 * @return bool
	 */
	private function otto_disabled_for_post($post_id)
	{
		// Quick Edit saves over admin-ajax as `inline-save`, and wp_ajax_inline_save()
		// redraws this row. metasync.php skips otto/otto_pixel.php for any admin-ajax
		// action that is not ours, so the exclusion checker is absent in exactly the
		// request that re-renders the column — and the row would show OTTO's value for
		// an excluded post until the next full page load. Load it here, where the
		// column is actually being rendered, rather than widening the load for every
		// third-party ajax request: this runs only inside a column render callback,
		// so a Quick Edit save pays for it and the frequent ajax actions the guard in
		// metasync.php exists for (heartbeat, autosave) never reach this line.
		//
		// Gated on wp_doing_ajax() rather than the DOING_AJAX constant that
		// metasync_is_non_metasync_admin_ajax() reads. The two can in principle
		// disagree — wp_doing_ajax() is filterable — but it is the sanctioned API
		// and the one PHPStan's WordPress ruleset requires, and a plugin filtering
		// it to false mid-Quick-Edit would only cost this column its freshness.
		if (!function_exists('metasync_is_otto_url_manually_excluded') && wp_doing_ajax()) {
			$pixel_file = plugin_dir_path(dirname(__FILE__)) . 'otto/otto_pixel.php';
			if (file_exists($pixel_file)) {
				require_once $pixel_file;
			}
		}

		if (class_exists('Metasync_Otto_Frontend_Toolbar')) {
			return (bool) Metasync_Otto_Frontend_Toolbar::is_otto_disabled($post_id);
		}

		$disabled = get_post_meta($post_id, '_metasync_otto_disabled', true);
		return $disabled === '1' || $disabled === 'true';
	}

	/**
	 * Whether a value is a third-party template rather than final text.
	 *
	 * Yoast and Rank Math store patterns such as `%%title%% %%sep%% %%sitename%%`
	 * and expand them at render time. Displaying the raw tokens is honest, but
	 * measuring them against the 60/160 limits is not — the rendered length is
	 * entirely different. Callers suppress the counter for these.
	 *
	 * @param  string $value Stored value.
	 * @return bool
	 */
	private function is_template_value($value)
	{
		return (bool) preg_match('/%%[a-z0-9_-]+%%/i', $value);
	}

	/**
	 * Third-party meta keys worth reading, restricted to plugins that are active.
	 *
	 * The plugin paths mirror Metasync_SEO_Conflict_Handler's own detection list.
	 * They are checked directly rather than through that class: its constructor
	 * registers filters and needs a full WordPress request context, which is far
	 * more than a list-table column should pull in to ask "is Yoast switched on?".
	 *
	 * @param  bool $is_title Whether to return title keys (else description keys).
	 * @return array meta_key => source label.
	 */
	private function get_active_plugin_meta_keys($is_title)
	{
		if (!function_exists('is_plugin_active')) {
			if (!defined('ABSPATH') || !file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
				return array();
			}
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$keys = array();

		if (is_plugin_active('wordpress-seo/wp-seo.php') || is_plugin_active('wordpress-seo-premium/wp-seo-premium.php')) {
			$keys[$is_title ? '_yoast_wpseo_title' : '_yoast_wpseo_metadesc'] = 'Yoast';
		}

		if (is_plugin_active('seo-by-rank-math/rank-math.php') || is_plugin_active('seo-by-rankmath/rank-math.php')) {
			$keys[$is_title ? 'rank_math_title' : 'rank_math_description'] = 'Rank Math';
		}

		if (
			is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php')
			|| is_plugin_active('all-in-one-seo-pack-pro/all_in_one_seo_pack.php')
		) {
			$keys[$is_title ? '_aioseo_title' : '_aioseo_description'] = 'AIOSEO';
		}

		return $keys;
	}

	/**
	 * Render the SEO Title or SEO Meta Desc cell.
	 *
	 * Empty states deliberately differ between the two fields:
	 *
	 * - Title is never really empty. When no override exists the sidebar filter hands
	 *   WordPress' own generated title straight back (class-metasync-seo-sidebar.php),
	 *   so the page still has a working <title>. Showing a bare dash would imply the
	 *   page is broken when it is not — we show the post title, muted, instead.
	 * - Description genuinely can be empty: WordPress does not auto-generate meta
	 *   descriptions. That IS an actionable gap, so it gets a "Not set" warning.
	 *
	 * @param  int    $post_id Post ID.
	 * @param  string $field   'title' or 'description'.
	 * @return string HTML.
	 */
	private function render_text_cell($post_id, $field)
	{
		$resolved = $this->resolve_rendered_value($post_id, $field);

		$value = $resolved['value'];

		if ($value === '') {
			if ($field === 'title') {
				// No override: WordPress generates the title from the post title, so the
				// page still has a working one. Shown muted, with the explanation in the
				// hover title rather than an inline label.
				return sprintf(
					'<span class="metasync-seo-col metasync-seo-col--auto" title="%s">%s</span>',
					esc_attr__('No SEO title set. WordPress generates the page title from the post title.', 'metasync'),
					esc_html(get_the_title($post_id))
				);
			}

			return sprintf(
				'<span class="metasync-seo-col metasync-seo-col--missing">%s</span>',
				esc_html__('Not set', 'metasync')
			);
		}

		$max = ($field === 'title') ? self::TITLE_MAX_LEN : self::DESC_MAX_LEN;

		// A stored template is shown verbatim but not measured: the tokens expand at
		// render time, so a raw count would report a length the page never has.
		$meta_line = $this->is_template_value($value)
			? __('template — expands on render', 'metasync')
			: $this->char_count($value, $max);

		return sprintf(
			'<span class="metasync-seo-col">%s</span><br /><span class="metasync-seo-col__meta">%s</span>',
			esc_html($value),
			esc_html($meta_line)
		);
	}

	/**
	 * Character counter, e.g. "153/160".
	 *
	 * @param  string $value Value to measure.
	 * @param  int    $max   Recommended maximum.
	 * @return string
	 */
	private function char_count($value, $max)
	{
		$len = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
		return $len . '/' . $max;
	}

	/**
	 * Render the Noindex / Nofollow cell.
	 *
	 * @param  array  $all_meta  Result of get_post_meta($post_id).
	 * @param  string $directive 'noindex' or 'nofollow'.
	 * @return string HTML.
	 */
	private function render_robots_cell($all_meta, $directive)
	{
		$flags = $this->resolve_robots_flags($all_meta);

		if (empty($flags[$directive])) {
			return '<span class="metasync-seo-col__meta">&mdash;</span>';
		}

		return sprintf(
			'<span class="metasync-seo-col metasync-seo-col--flag">%s</span>',
			esc_html__('Yes', 'metasync')
		);
	}

	/**
	 * Resolve the effective noindex / nofollow state for a post.
	 *
	 * Mirrors the priority order documented on
	 * Metasync_Seo_Output::resolve_robots_value() (public/class-metasync-seo-output.php),
	 * reduced to the two booleans this screen displays:
	 *
	 *   1. _metasync_robots_index      (noindex, separate key)
	 *   2. _metasync_robots_advanced   (JSON)
	 *   3. meta_robots                 (string, set via REST)
	 *   4. metasync_common_robots      (array, admin checkboxes)
	 *
	 * There is deliberately no global-default step: the frontend resolver's global
	 * branch only contributes max-* directives, never noindex/nofollow.
	 *
	 * Returns all-false when an active third-party SEO plugin owns the robots tag.
	 * hook_metasync_metatags() only emits our robots on such a site when
	 * Metasync_SEO_Conflict_Handler::metasync_has_robots() passes, and that method
	 * inspects `meta_robots` and `metasync_common_robots` only — it does not know
	 * about `_metasync_robots_index` or `_metasync_robots_advanced`. A post carrying
	 * just those keys gets the plugin's directives on the page, so claiming ours are
	 * in force would be wrong. Showing nothing under-reports rather than misleads.
	 *
	 * @param  array $all_meta Result of get_post_meta($post_id).
	 * @return array{noindex:bool,nofollow:bool}
	 */
	private function resolve_robots_flags($all_meta)
	{
		if (!$this->metasync_robots_reach_the_page($all_meta)) {
			return array('noindex' => false, 'nofollow' => false);
		}
		$out = array('noindex' => false, 'nofollow' => false);

		// 1. noindex lives in its own key regardless of the other storage formats.
		if (($all_meta['_metasync_robots_index'][0] ?? '') === 'noindex') {
			$out['noindex'] = true;
		}

		// 2. Advanced JSON is authoritative for the advanced directives when present.
		$advanced_raw = $all_meta['_metasync_robots_advanced'][0] ?? '';
		if (!empty($advanced_raw)) {
			$advanced = json_decode($advanced_raw, true);
			if (is_array($advanced) && !empty($advanced)) {
				$out['nofollow'] = !empty($advanced['nofollow']);
				return $out;
			}
		}

		// 3. meta_robots string (REST API).
		$meta_robots = $all_meta['meta_robots'][0] ?? '';
		if (!empty($meta_robots)) {
			// Tokenised, not substring-matched. `none` is the standard shorthand for
			// "noindex, nofollow" and would otherwise be missed entirely, while a
			// substring test also accepts malformed values such as "noindexing".
			$tokens = array_map('trim', explode(',', strtolower((string) $meta_robots)));

			if (in_array('none', $tokens, true)) {
				$out['noindex']  = true;
				$out['nofollow'] = true;
				return $out;
			}
			if (in_array('noindex', $tokens, true)) {
				$out['noindex'] = true;
			}
			if (in_array('nofollow', $tokens, true)) {
				$out['nofollow'] = true;
			}
			return $out;
		}

		// 4. Admin checkbox array.
		$raw = $all_meta['metasync_common_robots'][0] ?? '';
		if (!empty($raw)) {
			$common = maybe_unserialize($raw);
			if (is_array($common) && !empty(array_filter($common))) {
				if (!empty($common['noindex'])) {
					$out['noindex'] = true;
				}
				if (!empty($common['nofollow'])) {
					$out['nofollow'] = true;
				}
				return $out;
			}
		}

		// No global fallback. The frontend resolver's global step
		// (Metasync_Seo_Output::resolve_robots_value(), step 4) reads
		// `advance_robots_meta` and only ever contributes max-snippet /
		// max-video-preview / max-image-preview — it never applies a global noindex
		// or nofollow. The global `common_robots_meta` option only pre-checks the
		// meta box UI (Metasync_Post_Meta_Settings::common_robots_meta_box_display()),
		// it does not emit a directive until the post itself is saved.
		//
		// Inheriting it here would tell an editor a page is noindexed when the page
		// actually renders as indexable, which is worse than showing nothing.
		return $out;
	}

	/**
	 * Whether our robots directives actually reach the page for this post.
	 *
	 * With no third-party SEO plugin active they always do. With one active, the
	 * emitter defers unless the conflict handler recognises a MetaSync robots value,
	 * and it only looks at `meta_robots` and `metasync_common_robots`.
	 *
	 * @param  array $all_meta Result of get_post_meta($post_id).
	 * @return bool
	 */
	private function metasync_robots_reach_the_page($all_meta)
	{
		if (empty($this->get_active_plugin_meta_keys(true))) {
			return true;
		}

		if (!empty($all_meta['meta_robots'][0] ?? '')) {
			return true;
		}

		$common = maybe_unserialize($all_meta['metasync_common_robots'][0] ?? '');
		return is_array($common) && !empty(array_filter($common));
	}

	/**
	 * Column styles, printed only on list-table screens that show our columns.
	 *
	 * @return void
	 */
	public function print_styles()
	{
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || empty($screen->base) || $screen->base !== 'edit') {
			return;
		}
		if (!self::is_supported_post_type($screen->post_type)) {
			return;
		}
		?>
<style>
.metasync-seo-col { display: inline-block; max-width: 320px; }
.metasync-seo-col--auto { color: #646970; font-style: italic; }
.metasync-seo-col--missing { color: #b32d2e; font-weight: 600; }
.metasync-seo-col__meta { color: #646970; font-size: 11px; }
</style>
		<?php
	}
}

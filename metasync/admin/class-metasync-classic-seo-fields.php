<?php
/**
 * Classic-editor save handler for the three Gutenberg-only SEO fields.
 *
 * Breadcrumb Title Override, Language Alternates (hreflang), and Primary
 * Category are persisted client-side in the block editor via the SEO sidebar's
 * `editPost` -> core REST meta. They have no `save_post` handler, so the
 * classic post form — which posts a flat form body, not a REST payload — has
 * no way to write them.
 *
 * This class adds that `save_post` path. It is deliberately separate from
 * Metasync_Seo_Suite: the Suite is presentation-only (it re-renders existing
 * fields with identical name/nonce attributes and lets the pre-existing
 * handlers persist them; it contains no save logic at all), and that invariant
 * is enforced by tests/unit/SeoSuiteParityTest.php. The Suite renders the
 * breadcrumb-title field and the Languages tab and emits this handler's nonce;
 * the primary-category select is injected by the accompanying JS under the
 * core Categories checklist, where the equivalent Gutenberg control lives.
 *
 * Guard parity with the Suite:
 *  - bails on autosave / revision (the same guard the other handlers use)
 *  - bails on block-editor requests (the `meta-box-loader=1` re-post and the
 *    `is_block_editor()` screen), mirroring
 *    Metasync_Seo_Suite::is_block_editor_request(). Without this a classic
 *    save would clobber values the Gutenberg sidebar owns.
 *  - bails on LPS / custom-HTML managed pages (no nonces, no writes), matching
 *    the Suite's managed-page lockout so this handler can never overwrite
 *    WebStudio-managed SEO.
 *  - verifies a single nonce, emitted wherever the fields render — by the
 *    Suite for the breadcrumb field and the Languages tab, and beside the
 *    primary-category select for that field alone (the same per-section
 *    conditional-nonce pattern the Suite already uses). The nonce is absent
 *    on Quick Edit, bulk edit, REST saves, and every other path that never
 *    renders these fields — so the handler is inert there.
 *
 * Sanitization matches the REST `register_post_meta` registrations in
 * class-metasync-seo-sidebar.php:
 *  - `_metasync_breadcrumb_title`  -> sanitize_text_field (string)
 *  - `_metasync_primary_category`  -> absint (int term ID; 0 deletes)
 *  - `_metasync_hreflang`         -> the canonical JSON round-trip,
 *    Metasync_SEO_Sidebar::sanitize_hreflang_meta(), so classic-saved rows go
 *    through the same normalisation (en_US -> en+US, case folding,
 *    x-default region strip) as REST-saved rows.
 *
 * Writing via update_post_meta is intentional: the Yoast / Rank Math
 * breadcrumb cross-sync in Metasync_Breadcrumbs fires on
 * `added_post_meta` / `updated_post_meta`, so the breadcrumb title mirrors
 * into the third-party plugins for free. The existing
 * Metasync_SEO_Sidebar::cleanup_primary_category_meta() handler runs at
 * `save_post` priority 10; WordPress assigns taxonomy terms inside
 * wp_insert_post before `save_post` fires, so by the time that cleanup reads
 * the post's terms this priority-9 write is already consistent with them.
 *
 * @package Metasync
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}

class Metasync_Classic_Seo_Fields
{
	/** Nonce action and field name — emitted by the Suite only when the fields render. */
	const NONCE_ACTION = 'metasync_classic_seo_fields_nonce';
	const NONCE_FIELD  = 'metasync_classic_seo_fields_nonce';

	/** Meta keys (mirrors the REST registrations in class-metasync-seo-sidebar.php). */
	const META_BREADCRUMB_TITLE = '_metasync_breadcrumb_title';
	const META_PRIMARY_CATEGORY = '_metasync_primary_category';
	const META_HREFLANG         = '_metasync_hreflang';

	/** Form key the Suite's Languages tab posts its repeatable rows under. */
	const HREFLANG_ROWS_FIELD = 'metasync_hreflang_rows';

	/** Form key the Languages tab posts to say "rendered, even with zero rows". */
	const HREFLANG_PRESENT_FIELD = 'metasync_hreflang_present';

	public function __construct()
	{
		// Priority 9 so this runs before the sidebar's
		// cleanup_primary_category_meta handler (priority 10), which reads the
		// value written here and only acts if the term is no longer attached.
		add_action('save_post', array($this, 'save'), 9, 1);
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		add_action('admin_footer-post.php', array($this, 'inject_primary_category'));
		add_action('admin_footer-post-new.php', array($this, 'inject_primary_category'));
	}

	/**
	 * Enqueue the primary-category JS on classic post-edit screens.
	 *
	 * The script builds the select under the core Categories checklist and
	 * keeps it in sync with ticking, unticking, and the "Add New Category"
	 * AJAX path. Only loads where that control can render (see
	 * primary_category_should_render()): classic editor — the block editor's
	 * sidebar owns the field there — post types with the core `category`
	 * taxonomy, and sites where no other SEO plugin provides its own
	 * primary-category control.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets($hook)
	{
		if (!in_array($hook, array('post.php', 'post-new.php'), true)) {
			return;
		}

		if (!$this->primary_category_should_render()) {
			return;
		}

		$post_id = get_the_ID();
		$stored  = $post_id ? (int) get_post_meta($post_id, self::META_PRIMARY_CATEGORY, true) : 0;

		wp_enqueue_script(
			'metasync-classic-seo-fields',
			plugin_dir_url(__FILE__) . 'js/metasync-classic-seo-fields.js',
			array(),
			defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0',
			true
		);
		wp_localize_script('metasync-classic-seo-fields', 'metasyncClassicSeoFields', array(
			'selectName'    => self::META_PRIMARY_CATEGORY,
			'storedPrimary' => $stored,
			'i18n'          => array(
				'label'    => __('Primary Category', 'metasync'),
				'help'     => __('Used for breadcrumbs when the post has multiple categories. Options come from the categories checked above.', 'metasync'),
				'none'     => __('(none)', 'metasync'),
			),
		));
	}

	/**
	 * True when another SEO plugin provides its own primary-category control.
	 *
	 * Mirrors the $other_seo_primary detection localized for the Gutenberg
	 * sidebar, so the classic select defers exactly where the sidebar's does.
	 *
	 * @return bool
	 */
	private function other_seo_primary_active()
	{
		return defined('WPSEO_VERSION')
			|| defined('RANK_MATH_VERSION')
			|| defined('AIOSEO_VERSION')
			|| class_exists('AIOSEO\\Plugin\\AIOSEO');
	}

	/**
	 * Echo the primary-category select container for the classic editor.
	 *
	 * Rendered hidden in the admin footer and relocated by the JS under the
	 * core Categories checklist (#categorydiv), where the equivalent
	 * Gutenberg control lives — beside the box the user is already
	 * interacting with, not inside the SEO Suite. The styling is hardcoded
	 * light: this lives inside a core WordPress metabox, so it must not
	 * follow the Suite's Dark/Light toggle.
	 *
	 * Gated identically to the enqueue: block editor, post types without the
	 * core `category` taxonomy, managed pages, and sites where another SEO
	 * plugin owns the primary-category control all get nothing. The save
	 * handler treats the field's absence as "leave the stored value
	 * untouched". The nonce is emitted here too — the Suite prints its own
	 * for its fields, but on a site that opts out of the Suite (or registers
	 * none of its boxes) this field still needs one or it renders as dead UI.
	 */
	public function inject_primary_category()
	{
		if (!$this->primary_category_should_render()) {
			return;
		}

		$post_id = get_the_ID();
		$stored  = $post_id ? (int) get_post_meta($post_id, self::META_PRIMARY_CATEGORY, true) : 0;
		?>
<div id="metasync-primary-category-wrap" data-stored="<?php echo esc_attr((string) $stored); ?>" style="display:none">
  <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
  <label for="metasync-primary-category-select" style="display:block;font-weight:600;margin:0 0 4px"><?php esc_html_e('Primary Category', 'metasync'); ?></label>
  <select id="metasync-primary-category-select" name="<?php echo esc_attr(self::META_PRIMARY_CATEGORY); ?>" style="width:100%;max-width:300px;padding:4px 6px">
    <option value="0"><?php esc_html_e('(none)', 'metasync'); ?></option>
  </select>
  <p style="margin:4px 0 0;color:#666;font-size:12px"><?php esc_html_e('Used for breadcrumbs when the post has multiple categories. Options come from the categories checked above.', 'metasync'); ?></p>
</div>
		<?php
	}

	/**
	 * True when the primary-category select can render on this screen.
	 *
	 * @return bool
	 */
	private function primary_category_should_render()
	{
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen && $screen->is_block_editor()) {
			return false;
		}

		// get_current_post_type() is wp-admin-only and unloaded in the
		// contexts save_post can reach; the global post is set on both
		// post.php and post-new.php, so resolve from it like the sidebar's
		// enqueue does.
		$post_id = get_the_ID();
		$post_type = $post_id ? get_post_type($post_id) : '';
		if (!$post_type) {
			return false;
		}
		$taxonomies = get_object_taxonomies($post_type, 'names');
		if (!in_array('category', $taxonomies, true)) {
			return false;
		}

		// Managed pages: the Suite locks its whole box read-only for these;
		// this field must not look editable there either.
		if ($this->is_managed_page($post_id)) {
			return false;
		}

		return !$this->other_seo_primary_active();
	}

	/**
	 * Persist the three classic-editor SEO fields.
	 *
	 * @param int $post_id Post ID being saved.
	 */
	public function save($post_id)
	{
		// Autosave and revision saves must not touch these fields.
		if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
			return;
		}

		// The nonce is emitted wherever the fields render — by the Suite for
		// the breadcrumb field and the Languages tab, and beside the primary
		// category select for that field alone. It is absent on Quick Edit,
		// bulk edit, REST, autosave and every other path — so its absence
		// means "do nothing", exactly like the other classic save handlers.
		if (!isset($_POST[self::NONCE_FIELD]) || !wp_verify_nonce($_POST[self::NONCE_FIELD], self::NONCE_ACTION)) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce IS the verification
			return;
		}

		// The block editor owns these fields there; bail on both the main
		// block-editor load and the `meta-box-loader=1` re-post.
		if ($this->is_block_editor_request()) {
			return;
		}

		// LPS / custom-HTML managed pages: no nonces, no writes.
		if ($this->is_managed_page($post_id)) {
			return;
		}

		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		$this->save_breadcrumb_title($post_id);
		$this->save_primary_category($post_id);
		$this->save_hreflang($post_id);
	}

	/**
	 * True for any request originating from the block editor.
	 *
	 * Mirrors Metasync_Seo_Suite::is_block_editor_request(): the block editor
	 * renders its meta-box area during the main page load (is_block_editor()
	 * is true there) and re-posts that same form to post.php?meta-box-loader=1
	 * when saving. Both are treated as block-editor context so this handler
	 * never engages in either — a classic save must not clobber values the
	 * Gutenberg sidebar owns. get_current_screen() does not exist outside
	 * wp-admin, and save_post also fires on REST saves, hence the guard.
	 *
	 * @return bool
	 */
	private function is_block_editor_request()
	{
		if (!empty($_REQUEST['meta-box-loader'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only request flag for editor context
			return true;
		}
		if (function_exists('get_current_screen')) {
			$screen = get_current_screen();
			if ($screen && $screen->is_block_editor()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when the page's front-end output bypasses WordPress SEO (LPS /
	 * custom-HTML). Reuses the shared helper so this stays in lockstep with
	 * the Suite's managed-page lockout.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_managed_page($post_id)
	{
		if (function_exists('metasync_is_custom_or_lps_page') && $post_id > 0 && metasync_is_custom_or_lps_page($post_id)) {
			return true;
		}
		// Fallbacks if the helper isn't loaded on this request — mirrors
		// Metasync_Seo_Suite::is_managed_page().
		if ($post_id > 0) {
			if (get_post_meta($post_id, '_metasync_lps_import', true)) {
				return true;
			}
			if (get_post_meta($post_id, '_metasync_is_custom_html_page', true) === '1'
				&& get_post_meta($post_id, '_metasync_raw_html_enabled', true) === '1') {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when the Language Alternates editor switch is on.
	 *
	 * The REST registration of the hreflang meta is gated on the same flag, so
	 * the classic write path refuses exactly where the block editor's would.
	 * The defined() guard matches the registration's upgrade-window defence
	 * against a stale opcache copy of the flags class.
	 *
	 * @return bool
	 */
	private function language_alternates_enabled()
	{
		// @phpstan-ignore-next-line function.alreadyNarrowedType
		return defined('Metasync_Feature_Flags::LANGUAGE_ALTERNATES')
			&& Metasync_Feature_Flags::is_enabled(Metasync_Feature_Flags::LANGUAGE_ALTERNATES);
	}

	/**
	 * True when the breadcrumb title override is meaningful on this site.
	 *
	 * @return bool
	 */
	private function breadcrumb_title_meaningful()
	{
		return !class_exists('Metasync_Breadcrumbs') || Metasync_Breadcrumbs::is_breadcrumb_title_meaningful();
	}

	/**
	 * Breadcrumb Title Override. sanitize_text_field matches the REST
	 * registration; an empty string is a deliberate clear. Writing via
	 * update_post_meta fires the Yoast/Rank Math cross-sync for free.
	 *
	 * @param int $post_id Post ID.
	 */
	private function save_breadcrumb_title($post_id)
	{
		if (!isset($_POST[self::META_BREADCRUMB_TITLE])) {
			return;
		}

		// Breadcrumbs globally disabled: the field did not render, so leave
		// the stored value untouched rather than honouring a crafted input.
		if (!$this->breadcrumb_title_meaningful()) {
			return;
		}

		$value = sanitize_text_field(wp_unslash($_POST[self::META_BREADCRUMB_TITLE]));
		if ($value === '') {
			delete_post_meta($post_id, self::META_BREADCRUMB_TITLE);
			return;
		}
		// update_post_meta() unslashes again, so re-slash to keep literal
		// backslashes and quotes in the stored value.
		update_post_meta($post_id, self::META_BREADCRUMB_TITLE, wp_slash($value));
	}

	/**
	 * Primary Category. absint matches the REST registration; 0 means "no
	 * primary category" and deletes rather than persisting a zero. The select
	 * is absent from the form on post types without categories (and wherever
	 * another SEO plugin owns the control), in which case the stored value is
	 * left untouched.
	 *
	 * @param int $post_id Post ID.
	 */
	private function save_primary_category($post_id)
	{
		if (!isset($_POST[self::META_PRIMARY_CATEGORY])) {
			return;
		}

		$value = absint(wp_unslash($_POST[self::META_PRIMARY_CATEGORY]));
		if ($value > 0) {
			update_post_meta($post_id, self::META_PRIMARY_CATEGORY, $value);
		} else {
			delete_post_meta($post_id, self::META_PRIMARY_CATEGORY);
		}
	}

	/**
	 * Language Alternates (hreflang).
	 *
	 * The classic form posts individual
	 * `metasync_hreflang_rows[N][lang|region|url]` inputs (it cannot post a
	 * JSON string), so assemble them here and run the result through the
	 * canonical REST sanitize callback for storage-format parity.
	 *
	 * The Languages tab also posts a `metasync_hreflang_present` marker. With
	 * every row removed the row inputs are gone from the form entirely, so
	 * without that marker the handler could not tell "user cleared all rows"
	 * (clear the stored value) from "the tab never rendered" (leave the
	 * stored value — the Gutenberg sidebar or a site with the feature
	 * disabled owns it).
	 *
	 * @param int $post_id Post ID.
	 */
	private function save_hreflang($post_id)
	{
		$rows_in = null;
		if (isset($_POST[self::HREFLANG_ROWS_FIELD])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above
			$rows_in = wp_unslash($_POST[self::HREFLANG_ROWS_FIELD]);
			if (!is_array($rows_in)) {
				$rows_in = array();
			}
		} elseif (isset($_POST[self::HREFLANG_PRESENT_FIELD])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above
			// Tab rendered, all rows removed: a deliberate clear.
			$rows_in = array();
		} else {
			// The tab did not render on this save; the stored value belongs
			// to whoever did render it.
			return;
		}

		// Flag off: the tab did not render, so a crafted submission must not
		// write what the REST path would refuse.
		if (!$this->language_alternates_enabled()) {
			return;
		}

		$rows = array();
		foreach ($rows_in as $row) {
			if (!is_array($row)) {
				continue;
			}
			$rows[] = array(
				'lang'   => isset($row['lang']) && is_string($row['lang']) ? sanitize_text_field($row['lang']) : '',
				'region' => isset($row['region']) && is_string($row['region']) ? sanitize_text_field($row['region']) : '',
				'url'    => isset($row['url']) && is_string($row['url']) ? sanitize_text_field($row['url']) : '',
			);
		}

		$encoded = wp_json_encode($rows);
		if (!is_string($encoded)) {
			// Encoding failure: never persist a corrupt value; clear instead.
			delete_post_meta($post_id, self::META_HREFLANG);
			return;
		}

		$clean = $this->sanitize_hreflang_json($encoded);
		if ($clean === '' || $clean === '[]') {
			delete_post_meta($post_id, self::META_HREFLANG);
			return;
		}
		// update_post_meta() unslashes again; re-slash for byte-exact storage.
		update_post_meta($post_id, self::META_HREFLANG, wp_slash($clean));
	}

	/**
	 * Canonical hreflang JSON sanitisation.
	 *
	 * Delegates to the REST registration's sanitize_callback so classic-saved
	 * rows are normalised identically to REST-saved rows (en_US folded to
	 * en+US, casing folded, x-default region stripped, blank rows dropped).
	 *
	 * @param string $json JSON array of raw rows.
	 * @return string Normalised JSON array string.
	 */
	private function sanitize_hreflang_json($json)
	{
		if (class_exists('Metasync_SEO_Sidebar')) {
			return (string) Metasync_SEO_Sidebar::sanitize_hreflang_meta($json);
		}

		// Autoload miss (non-standard install): degrade to the same blank-row
		// dropping the canonical callback performs, without the full
		// normalisation.
		$decoded = json_decode($json, true);
		if (!is_array($decoded)) {
			return '[]';
		}
		$clean = array();
		foreach ($decoded as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$lang = isset($entry['lang']) ? (string) $entry['lang'] : '';
			$url  = isset($entry['url']) ? (string) $entry['url'] : '';
			if ($lang === '' && $url === '') {
				continue;
			}
			$clean[] = array(
				'lang'   => $lang,
				'region' => isset($entry['region']) ? (string) $entry['region'] : '',
				'url'    => $url,
			);
		}
		return (string) wp_json_encode($clean);
	}
}

<?php
/**
 * Consent gate and write-once backup for third-party SEO plugin storage.
 *
 * Copying a MetaSync value into Yoast / Rank Math / AIOSEO storage is a
 * permanent write into another plugin's data. Two things were missing from it:
 *
 *  1. The site owner never agreed to it. `is_enabled()` is that agreement —
 *     a single opt-in switch that every third-party writer consults. It is
 *     off until someone turns it on, so an upgrade never starts writing into
 *     another plugin on its own.
 *  2. The value being overwritten was gone forever. `backup_before_overwrite()`
 *     copies the pre-existing value onto the same post or term first, so it
 *     can be put back later.
 *
 * The backup is deliberately lazy: it happens at the moment of overwrite, on
 * the one object being overwritten. A site-wide snapshot taken when the switch
 * is flipped would be thousands of writes on one click and could time out
 * half-finished.
 *
 * @package    MetaSync
 * @subpackage MetaSync/includes
 */

if (!defined('ABSPATH')) {
	exit;
}

class Metasync_Seo_Backup {

	/**
	 * Prefix for the per-field backup rows.
	 *
	 * One row per object AND field — `_metasync_seo_backup_rank_math_title`,
	 * `_metasync_seo_backup_rank_math_description`, and so on.
	 *
	 * A single JSON blob per object would be cheaper, but write-once would then
	 * mean write-once *per object*: enable title syncing today and description
	 * syncing six months from now, and the blob already exists, so the
	 * description's original could never be captured. Per-field rows keep the
	 * write-once guarantee while letting a later-enabled field back up its own
	 * original.
	 *
	 * @var string
	 */
	const BACKUP_META_PREFIX = '_metasync_seo_backup_';

	/**
	 * Key of the consent switch inside the plugin's `general` settings array.
	 *
	 * @var string
	 */
	const CONSENT_OPTION_KEY = 'sync_seo_values_to_other_plugins';

	/**
	 * Sentinel for "read the current value yourself".
	 *
	 * A plain null default cannot be used: null is a meaningful current value
	 * here (it means the field is absent), so the sentinel has to be a value no
	 * caller could pass by accident.
	 *
	 * @var string
	 */
	const CURRENT_UNKNOWN = "\0metasync_seo_backup_current_unknown\0";

	/**
	 * Whether MetaSync values may be written into third-party SEO storage.
	 *
	 * Fail-closed on every uncertainty. A partially updated install where the
	 * settings layer is missing must not be read as consent — there is no
	 * setting that could authorise a permanent write into another plugin.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if (!class_exists('Metasync')) {
			return false;
		}

		$general = Metasync::get_option('general');
		if (!is_array($general)) {
			return false;
		}

		$value = $general[self::CONSENT_OPTION_KEY] ?? false;

		// Checkboxes round-trip through the options array as the string 'true'
		// on one save path and as a real boolean on the other, so both spellings
		// have to count. Anything else — absent, '', '0', 'false' — is off.
		return $value === true || $value === 'true' || $value === '1' || $value === 1;
	}

	/**
	 * Save the value a third-party field holds right now, then say whether the
	 * caller may overwrite it.
	 *
	 * Call this immediately before every write into Yoast / Rank Math / AIOSEO
	 * storage. "Immediately" is not stylistic. Within a single OTTO sync the
	 * post-meta bridge (`Metasync_Plugin_Sync::on_meta_updated()`) fires
	 * synchronously from the `_metasync_otto_*` write and reaches Rank Math
	 * before the direct write further down the same function does. A backup
	 * taken at the later site would record OTTO's own output as the "original".
	 * Because every writer calls this first and the record is write-once,
	 * whichever writer arrives first captures the true original and the rest
	 * cannot overwrite it.
	 *
	 * @param string $object_type   'post' or 'term'.
	 * @param int    $object_id     Post or term ID.
	 * @param string $field         Third-party field being overwritten, used
	 *                              verbatim in the backup key.
	 * @param mixed  $new_value     Value about to be written.
	 * @param mixed  $current_value Current stored value, where the caller
	 *                              already knows it or the value does not live
	 *                              in meta (an AIOSEO table column, say). Pass
	 *                              null to mean "the field is absent". Omit it
	 *                              and the current value is read from meta.
	 * @param bool   $created       Out-param, set to true only when this call
	 *                              created the backup row. An existing row is a
	 *                              legitimate write-once no-op and still returns
	 *                              true, so the return value alone cannot tell a
	 *                              caller whether the row is its own to withdraw.
	 * @return bool True when the caller may proceed with the write.
	 */
	public static function backup_before_overwrite($object_type, $object_id, $field, $new_value, $current_value = self::CURRENT_UNKNOWN, &$created = null) {
		$created = false;

		if (!self::is_enabled()) {
			return false;
		}

		$object_id = (int) $object_id;
		if ($object_id <= 0 || $field === '') {
			return false;
		}

		if (!in_array($object_type, ['post', 'term'], true)) {
			return false;
		}

		if ($current_value === self::CURRENT_UNKNOWN) {
			$current_value = self::read_current_meta($object_type, $object_id, $field);
		}

		// Nothing is being overwritten, so there is nothing to preserve.
		// Restoring an identical value would be a no-op anyway.
		if (!self::values_differ($current_value, $new_value)) {
			return true;
		}

		// A failed record() must never be read as permission to overwrite.
		// add_*_meta() also returns false when the row already exists, which is a
		// legitimate write-once no-op, so an existing backup counts as success.
		// Anything else leaves the original unsaved and the write must not proceed.
		if (!self::record($object_type, $object_id, $field, $current_value)) {
			if (!self::backup_exists($object_type, $object_id, $field)) {
				return false;
			}
		} else {
			$created = true;
		}

		self::queue_change_log($object_type, $object_id, $field);

		return true;
	}

	/**
	 * Back up and then write one third-party post meta field.
	 *
	 * The only place in the plugin that writes a Yoast / Rank Math / AIOSEO post
	 * meta key. Keeping it to one place is what makes "no third-party field is
	 * ever overwritten without consent and a saved original" a property that can
	 * be checked mechanically instead of trusted.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Third-party meta key.
	 * @param mixed  $value   Value to write.
	 * @return bool True when the field holds the intended value afterwards:
	 *              written, or already in place. False when the write was
	 *              refused or failed.
	 */
	public static function write_post_meta($post_id, $key, $value) {
		// Read the current value once and pass it in: backup_before_overwrite()
		// needs it anyway, and this caller needs it too, to tell
		// update_post_meta()'s "false because nothing changed" from a failure.
		$current = self::read_current_meta('post', $post_id, $key);

		if (!self::backup_before_overwrite('post', $post_id, $key, $value, $current)) {
			return false;
		}

		// A value that is already in place is a success: update_post_meta()
		// reports its no-op as false, and callers read false as "the third-party
		// write failed" — skipping receipts and purges for a field that already
		// holds exactly what we wanted.
		if (!self::values_differ($current, $value)) {
			return true;
		}

		// update_post_meta() unslashes what it is given, exactly as add_post_meta()
		// does in record(). Slashing here rather than at each call site keeps one
		// contract for every caller: pass the literal value you want stored. With
		// the no-op handled above, a false here is a real failure, and reporting
		// it stops callers stamping a sync receipt for a write that never landed.
		return (bool) update_post_meta($post_id, $key, wp_slash($value));
	}

	/**
	 * Back up and then write one third-party term meta field.
	 *
	 * Term counterpart of write_post_meta().
	 *
	 * @param int    $term_id Term ID.
	 * @param string $key     Third-party term meta key.
	 * @param mixed  $value   Value to write.
	 * @return bool True when the field holds the intended value afterwards:
	 *              written, or already in place. False when the write was
	 *              refused or failed.
	 */
	public static function write_term_meta($term_id, $key, $value) {
		$current = self::read_current_meta('term', $term_id, $key);

		if (!self::backup_before_overwrite('term', $term_id, $key, $value, $current)) {
			return false;
		}

		// Same rule as write_post_meta(): an unchanged value is a success,
		// because update_term_meta() reports its no-op as false.
		if (!self::values_differ($current, $value)) {
			return true;
		}

		// Slashed, and the return trusted, for the same reasons as write_post_meta().
		return (bool) update_term_meta($term_id, $key, wp_slash($value));
	}

	/**
	 * Read a saved original.
	 *
	 * Returns an `exists` flag rather than just a value because absent and
	 * empty are different outcomes: a restore has to delete a field that was
	 * absent, not write an empty string into it.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Post or term ID.
	 * @param string $field       Third-party field name.
	 * @return array{exists:bool,value:mixed} `value` is null when the field was
	 *         absent at backup time.
	 */
	public static function read_backup($object_type, $object_id, $field) {
		$absent = ['exists' => false, 'value' => null];

		if (!in_array($object_type, ['post', 'term'], true)) {
			return $absent;
		}

		$key = self::backup_key($field);
		$object_id = (int) $object_id;

		if (!metadata_exists($object_type, $object_id, $key)) {
			return $absent;
		}

		$stored = ($object_type === 'post')
			? get_post_meta($object_id, $key, true)
			: get_term_meta($object_id, $key, true);

		return [
			'exists' => true,
			'value'  => self::unwrap($stored),
		];
	}

	/**
	 * The meta key a field's original is stored under.
	 *
	 * @param string $field Third-party field name.
	 * @return string
	 */
	public static function backup_key($field) {
		return self::BACKUP_META_PREFIX . $field;
	}

	/**
	 * How many posts and terms hold at least one saved original.
	 *
	 * Distinct objects, not rows — the status line counts things the user can
	 * picture, and one post can hold a dozen field backups.
	 *
	 * @return array{posts:int,terms:int}
	 */
	public static function count_objects_with_backups() {
		global $wpdb;

		$counts = ['posts' => 0, 'terms' => 0];

		if (!isset($wpdb)) {
			return $counts;
		}

		$like = $wpdb->esc_like(self::BACKUP_META_PREFIX) . '%';

		$counts['posts'] = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
			$like
		));

		$counts['terms'] = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(DISTINCT term_id) FROM {$wpdb->termmeta} WHERE meta_key LIKE %s",
			$like
		));

		return $counts;
	}

	/**
	 * Drop every saved original held by a post.
	 *
	 * Used when a post is duplicated: the copy must not inherit the original's
	 * pre-OTTO values, or restoring the copy would write another page's title
	 * onto it.
	 *
	 * The keys are dynamic, so they cannot be enumerated from a constant list
	 * the way the fixed OTTO keys are — the prefix has to be matched in SQL.
	 *
	 * @param int $post_id Post ID.
	 * @return int Number of rows removed.
	 */
	public static function delete_all_for_post($post_id) {
		global $wpdb;

		$post_id = (int) $post_id;
		if ($post_id <= 0 || !isset($wpdb)) {
			return 0;
		}

		$keys = $wpdb->get_col($wpdb->prepare(
			"SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
			$post_id,
			$wpdb->esc_like(self::BACKUP_META_PREFIX) . '%'
		));

		if (empty($keys)) {
			return 0;
		}

		foreach ($keys as $key) {
			delete_post_meta($post_id, $key);
		}

		return count($keys);
	}

	// ------------------------------------------------------------------
	// Changes Log
	// ------------------------------------------------------------------

	/**
	 * Fields overwritten during this request, grouped by object.
	 *
	 * @var array<string,array{type:string,id:int,fields:array<int,string>}>
	 */
	private static $pending_log = [];

	/**
	 * Note an overwrite for the Changes Log.
	 *
	 * Collected per request and written out once at shutdown rather than one
	 * row per field. A single sync can touch a dozen fields on a post, and the
	 * history table keeps only the most recent 1000 records — a row per field
	 * would push a day's real history out of the log by lunchtime.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Post or term ID.
	 * @param string $field       Third-party field that was overwritten.
	 */
	private static function queue_change_log($object_type, $object_id, $field) {
		$bucket = $object_type . ':' . $object_id;

		if (!isset(self::$pending_log[$bucket])) {
			self::$pending_log[$bucket] = [
				'type'   => $object_type,
				'id'     => (int) $object_id,
				'fields' => [],
			];

			if (function_exists('add_action') && count(self::$pending_log) === 1) {
				add_action('shutdown', [__CLASS__, 'flush_change_log'], 20);
			}
		}

		if (!in_array($field, self::$pending_log[$bucket]['fields'], true)) {
			self::$pending_log[$bucket]['fields'][] = $field;
		}
	}

	/**
	 * Write the queued overwrites to the Changes Log.
	 *
	 * Public because it runs on `shutdown`. Failures here are swallowed: a
	 * logging problem must never break the sync it is describing.
	 */
	public static function flush_change_log() {
		$pending = self::$pending_log;
		self::$pending_log = [];

		if (empty($pending) || !class_exists('Metasync_Sync_History_Database')) {
			return;
		}

		try {
			$history = new Metasync_Sync_History_Database();
			$product = class_exists('Metasync') ? Metasync::get_effective_plugin_name() : 'Search Atlas';

			foreach ($pending as $entry) {
				$is_post = ($entry['type'] === 'post');
				$label = $is_post ? get_the_title($entry['id']) : '';
				if (empty($label)) {
					$label = ucfirst($entry['type']) . ' #' . $entry['id'];
				}

				$url = null;
				if ($is_post) {
					$url = get_permalink($entry['id']);
				} elseif (function_exists('get_term_link')) {
					$link = get_term_link($entry['id']);
					$url = is_string($link) ? $link : null;
				}

				$history->add([
					'title'        => sprintf(
						'%s wrote %d SEO field(s) into other plugins on "%s" — originals saved',
						$product,
						count($entry['fields']),
						$label
					),
					'source'       => 'SEO Plugin Sync',
					'status'       => 'published',
					'content_type' => $entry['type'],
					'url'          => $url,
					'meta_data'    => wp_json_encode([
						'object_type' => $entry['type'],
						'object_id'   => $entry['id'],
						'fields'      => $entry['fields'],
					]),
					'created_at'   => current_time('mysql'),
				]);
			}
		} catch (Exception $e) {
			// Never let a logging failure break the sync it describes.
			return;
		}
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	/**
	 * Write the backup row, once and only once.
	 *
	 * `$unique = true` is the whole point. A read-then-update would leave a
	 * window in which two concurrent syncs both see "no backup yet" and the
	 * second one saves OTTO's value as the original.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Post or term ID.
	 * @param string $field       Third-party field name.
	 * @param mixed  $value       The value being overwritten, or null if absent.
	 * @return bool True when this call created the row.
	 */
	/**
	 * Record a write-once marker whose value IS the fact being recorded.
	 *
	 * backup_before_overwrite() preserves the value it is replacing. That is the
	 * right shape for a field and the wrong one for a marker: a marker has no
	 * prior value, so routing one through that method stores whatever the caller
	 * passed as the current value and discards the fact. Markers are write-once
	 * like backups, so a wrong one can never be corrected afterwards.
	 *
	 * @param  string $object_type 'post' or 'term'.
	 * @param  int    $object_id   Object ID.
	 * @param  string $field       Marker name.
	 * @param  mixed  $value       Value to preserve verbatim.
	 * @param  bool   $created     Out-param, true only when this call created the
	 *                             marker. This method is idempotent, so a caller
	 *                             can be told "the marker is present" about a
	 *                             marker a concurrent sync recorded. Withdrawing
	 *                             that one on a local failure would delete a
	 *                             marker that correctly describes someone else's
	 *                             successful write.
	 * @return bool   True when the marker is present after the call.
	 */
	public static function record_marker($object_type, $object_id, $field, $value, &$created = null) {
		$created = false;

		if (!self::is_enabled()) {
			return false;
		}

		$object_id = (int) $object_id;
		if ($object_id <= 0 || $field === '' || !in_array($object_type, ['post', 'term'], true)) {
			return false;
		}

		if (self::backup_exists($object_type, $object_id, $field)) {
			return true;
		}

		$created = self::record($object_type, $object_id, $field, $value);

		return $created;
	}

	/**
	 * Drop a marker recorded for a table write that then failed.
	 *
	 * A `*_row_existed` marker is recorded before the write, because a marker
	 * that will not record has to be able to veto the write. That ordering
	 * leaves a marker behind when the write itself fails, and a stale `'0'` is
	 * the dangerous kind: it says "this row exists only because we made it", so
	 * a restore reading it would delete a row the customer created by hand
	 * afterwards. Markers are write-once and cannot be corrected in place, so
	 * the only way back is to remove the row and let the next attempt record it
	 * afresh.
	 *
	 * Only ever withdraw a marker this run created. `record_marker()` is
	 * idempotent, so a concurrent sync that arrives second is told the marker is
	 * present without having written it; if that sync's own insert then fails,
	 * discarding on its behalf would delete the marker correctly describing the
	 * first sync's successful write.
	 *
	 * That ownership rule is enforced by the callers, not here: a field name
	 * only enters the list passed to `discard_backups()` when the
	 * `record_marker()` / `backup_before_overwrite()` `$created` out-param came
	 * back true, so a row this run merely found is never named for withdrawal.
	 * `$owned` is a second latch for callers that discard a single field
	 * directly and have their own ownership signal to pass.
	 *
	 * @param  string $object_type 'post' or 'term'.
	 * @param  int    $object_id   Object ID.
	 * @param  string $field       Marker name.
	 * @param  bool   $owned       Whether this caller created the marker. False
	 *                             leaves it alone.
	 * @return void
	 */
	public static function discard_marker($object_type, $object_id, $field, $owned = true) {
		if (!$owned) {
			return;
		}

		$object_id = (int) $object_id;
		if ($object_id <= 0 || $field === '' || !in_array($object_type, ['post', 'term'], true)) {
			return;
		}

		$key = self::backup_key($field);

		if ($object_type === 'post') {
			delete_post_meta($object_id, $key);
			return;
		}

		delete_term_meta($object_id, $key);
	}

	/**
	 * Withdraw the per-column backups recorded beside a marker, after the table
	 * write they describe failed.
	 *
	 * Discarding the marker alone is not enough. The per-column rows recorded
	 * next to it are write-once too, so a column captured as NULL because the
	 * row did not exist stays NULL for good. The customer then creates that row
	 * by hand, the next sync correctly marks it as pre-existing, and a restore
	 * reads the stale NULL beside the fresh marker and blanks a real value.
	 * Both halves of a failed attempt have to go, so the next sync can capture
	 * the truth.
	 *
	 * Ownership is the caller's to establish: `$fields` must name only rows this
	 * run actually created, which is what the `$created` out-params on
	 * `record_marker()` and `backup_before_overwrite()` are for. A row that
	 * already existed holds an older, truer original that write-once exists to
	 * protect, so passing it here would destroy the thing being guarded.
	 *
	 * @param  string        $object_type 'post' or 'term'.
	 * @param  int           $object_id   Object ID.
	 * @param  array<string> $fields      Backup field names this run created.
	 * @return void
	 */
	public static function discard_backups($object_type, $object_id, array $fields) {
		foreach ($fields as $field) {
			self::discard_marker($object_type, $object_id, $field);
		}
	}

	/**
	 * Whether the $wpdb read that just ran can be trusted.
	 *
	 * `get_row()` answers "no such row" and "the query failed" with the same
	 * null. Reading the second as the first is how a customer's real value gets
	 * recorded as "there was nothing here" — and a restore then deletes what it
	 * should have put back. wpdb clears `last_error` at the start of every
	 * query, so immediately after a read it describes that read and no other.
	 *
	 * An empty `last_error` is not proof of success on its own. When the server
	 * has gone away and the reconnect also fails, wpdb::query() returns false
	 * from a branch above the line that assigns `last_error`, so the read failed
	 * while the error string is still empty. The connection handle is checked
	 * too, because that is the state that branch leaves behind.
	 *
	 * @return bool True when the read completed cleanly.
	 */
	public static function db_read_succeeded() {
		global $wpdb;

		if (!isset($wpdb)) {
			return false;
		}

		if (!empty($wpdb->last_error)) {
			return false;
		}

		// A dropped connection reports no error at all, so treat a missing
		// handle as a failed read rather than an empty result.
		if (property_exists($wpdb, 'dbh') && empty($wpdb->dbh)) {
			return false;
		}

		return true;
	}

	/**
	 * Whether a backup row already exists for this object and field.
	 *
	 * @param  string $object_type 'post' or 'term'.
	 * @param  int    $object_id   Object ID.
	 * @param  string $field       Field name.
	 * @return bool
	 */
	private static function backup_exists($object_type, $object_id, $field) {
		return metadata_exists($object_type, $object_id, self::backup_key($field));
	}

	private static function record($object_type, $object_id, $field, $value) {
		$key = self::backup_key($field);

		// add_*_meta() unslashes what it is given, so a value read straight out
		// of the database has to be re-slashed on the way back in. Without this
		// a title like `C:\Users\Docs` is stored as `C:UsersDocs` and the backup
		// no longer matches the original it exists to preserve. wp_slash()
		// recurses, which is what the ['value' => ...] wrapper needs.
		$wrapped = wp_slash(self::wrap($value));

		if ($object_type === 'post') {
			return (bool) add_post_meta($object_id, $key, $wrapped, true);
		}

		return (bool) add_term_meta($object_id, $key, $wrapped, true);
	}

	/**
	 * Current stored value of a meta field, with absent distinguished from empty.
	 *
	 * `get_*_meta(..., true)` returns '' for both, which is exactly the
	 * distinction a restore needs, hence the `metadata_exists()` probe.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Post or term ID.
	 * @param string $field       Meta key.
	 * @return mixed Null when the field is absent.
	 */
	private static function read_current_meta($object_type, $object_id, $field) {
		if (!metadata_exists($object_type, $object_id, $field)) {
			return null;
		}

		return ($object_type === 'post')
			? get_post_meta($object_id, $field, true)
			: get_term_meta($object_id, $field, true);
	}

	/**
	 * Whether a write would actually change the stored value.
	 *
	 * Absent (null) is never equal to empty string: writing '' into a field
	 * that did not exist does change it, and a restore has to know to delete
	 * rather than blank it.
	 *
	 * @param mixed $current Current stored value, null when absent.
	 * @param mixed $new     Value about to be written.
	 * @return bool
	 */
	private static function values_differ($current, $new) {
		if ($current === null) {
			return true;
		}

		if (is_scalar($current) && is_scalar($new)) {
			return (string) $current !== (string) $new;
		}

		return $current != $new;
	}

	/**
	 * Wrap a value for storage.
	 *
	 * WordPress cannot store a PHP null in meta — it serialises to '', which
	 * would collapse "the field was absent" into "the field was empty" and take
	 * the restore's delete-vs-blank decision away from it. Wrapping in an array
	 * keeps a real null a real null, with no sentinel string a genuine value
	 * could ever collide with.
	 *
	 * @param mixed $value Value to wrap.
	 * @return array
	 */
	private static function wrap($value) {
		return ['value' => $value];
	}

	/**
	 * Unwrap a stored backup value.
	 *
	 * @param mixed $stored Raw meta value.
	 * @return mixed
	 */
	private static function unwrap($stored) {
		if (is_array($stored) && array_key_exists('value', $stored)) {
			return $stored['value'];
		}

		return $stored;
	}
}

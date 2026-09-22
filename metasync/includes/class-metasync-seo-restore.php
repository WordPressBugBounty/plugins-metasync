<?php
/**
 * Putting third-party SEO values back the way MetaSync found them.
 *
 * `Metasync_Seo_Backup` saved an original for every third-party field it
 * overwrote. Until now nothing read those rows back — `read_backup()` had no
 * production caller at all — so this class is the first consumer of that data,
 * and every mistake it makes is a customer value deleted or blanked.
 *
 * A backup row's key carries the whole instruction: which of six unrelated
 * storage mechanisms the original came out of, and for two of them which table
 * row or option entry it belongs to. Restore therefore begins by parsing that
 * key, and the parse has to be exact — a field routed to the wrong mechanism
 * writes into storage it does not own.
 *
 * Includes background batch execution and queue management modeled on
 * `Metasync_Media_Batch_Optimizer`.
 *
 * @package    MetaSync
 * @subpackage MetaSync/includes
 */

if (!defined('ABSPATH')) {
	exit;
}

class Metasync_Seo_Restore {

	/**
	 * Backup rows that are bookkeeping, not values.
	 *
	 * These record whether a third-party table row existed before MetaSync
	 * inserted it. They are never written back into anything — writing
	 * `aioseo_row_existed` as if it were a column would put "1" into a real
	 * AIOSEO field. They are read to decide whether to delete a row or revert
	 * its columns, and then dropped.
	 *
	 * Matched exactly, and matched *first*, because both of them also carry a
	 * mechanism prefix: `aioseo_row_existed` looks like the `aioseo_{column}`
	 * pattern and `yoast_indexable_row_existed` looks like
	 * `yoast_indexable_{column}`. Prefix order alone would misroute both.
	 *
	 * @var string[]
	 */
	const MARKER_FIELDS = [
		'aioseo_row_existed',
		'yoast_indexable_row_existed',
	];

	/**
	 * AIOSEO's pre-table term fields, which are ordinary term meta.
	 *
	 * Older AIOSEO stored term SEO in term meta under these two keys. They
	 * collide with the `aioseo_{column}` table pattern on everything but the
	 * leading underscore, so they are matched exactly and ahead of it.
	 *
	 * @var string[]
	 */
	const LEGACY_AIOSEO_TERM_META = [
		'_aioseo_title',
		'_aioseo_description',
	];

	// Batch driver options & constants (modeled on Metasync_Media_Batch_Optimizer)
	public const QUEUE_OPTION       = 'metasync_seo_restore_queue';
	public const PROGRESS_OPTION    = 'metasync_seo_restore_progress';
	public const LOCK_OPTION        = 'metasync_seo_restore_lock';
	public const CRON_HOOK          = 'metasync_seo_restore_cron';

	private const LOCK_STALE_AFTER  = 120; // seconds before lock can be stolen
	private const DEFAULT_BATCH_SIZE = 25;  // items per tick
	private const CRON_TIME_LIMIT   = 30;  // seconds per cron tick
	private const TIME_SAFETY_MARGIN = 5;  // safety buffer before PHP timeout
	private const QUERY_PAGE_SIZE   = 1000;

	/**
	 * Turn off the third-party consent toggle at the start of a restore run.
	 *
	 * Running this before any object is touched prevents any concurrent OTTO sync
	 * from immediately re-overwriting third-party storage during the restore.
	 *
	 * @return bool True if disabled or already off.
	 */
	public static function disable_consent() {
		if (!class_exists('Metasync')) {
			return false;
		}

		$options = Metasync::get_option();
		if (!is_array($options)) {
			$options = [];
		}

		if (!isset($options['general']) || !is_array($options['general'])) {
			$options['general'] = [];
		}

		// Already off: update_option() returns false for an unchanged value,
		// which must not be mistaken for a failure to disable.
		if (empty($options['general'][Metasync_Seo_Backup::CONSENT_OPTION_KEY])) {
			return true;
		}

		$options['general'][Metasync_Seo_Backup::CONSENT_OPTION_KEY] = false;
		$saved = Metasync::set_option($options);

		// False is ambiguous (unchanged vs failed); the stored state decides.
		if ($saved === false) {
			$stored = Metasync::get_option();
			return empty($stored['general'][Metasync_Seo_Backup::CONSENT_OPTION_KEY]);
		}

		return true;
	}

	/**
	 * Turn the third-party consent toggle back on.
	 *
	 * Only used to undo this class's own disable_consent() when a run turns out
	 * to have nothing to restore: an empty run must not change the user's
	 * setting, so the pre-run value is put back exactly as it was found.
	 *
	 * @return bool True if enabled or already on.
	 */
	public static function enable_consent() {
		if (!class_exists('Metasync')) {
			return false;
		}

		$options = Metasync::get_option();
		if (!is_array($options)) {
			$options = [];
		}

		if (!isset($options['general']) || !is_array($options['general'])) {
			$options['general'] = [];
		}

		if (!empty($options['general'][Metasync_Seo_Backup::CONSENT_OPTION_KEY])) {
			return true;
		}

		$options['general'][Metasync_Seo_Backup::CONSENT_OPTION_KEY] = true;
		$saved = Metasync::set_option($options);

		// False is ambiguous (unchanged vs failed); the stored state decides.
		if ($saved === false) {
			$stored = Metasync::get_option();
			return !empty($stored['general'][Metasync_Seo_Backup::CONSENT_OPTION_KEY]);
		}

		return true;
	}

	/**
	 * Work out what storage a backup row's field name refers to.
	 *
	 * The field name is the backup key with `BACKUP_META_PREFIX` already
	 * stripped. Six mechanisms are in play and they are not distinguished by
	 * anything as tidy as a single prefix, so the tests below run in a fixed
	 * order: exact matches first, then the prefixes that would otherwise
	 * swallow them.
	 *
	 * Anything unrecognised is reported as such rather than guessed at. A field
	 * this code does not understand is a field written by a newer build than
	 * this one, and the safe response is to leave it alone — a wrong guess
	 * writes into another plugin's storage.
	 *
	 * @param string $field Field name from a backup row.
	 * @return array{kind:string,key?:string,column?:string,taxonomy?:string|null,field?:string}
	 *         `kind` is one of: marker, post_meta, term_meta, aioseo_column,
	 *         yoast_indexable_column, yoast_tax, unrecognised.
	 */
	public static function classify_field($field) {
		if ($field === '') {
			return ['kind' => 'unrecognised', 'taxonomy' => null, 'field' => ''];
		}

		// Bookkeeping before values, or both markers get routed as columns.
		if (in_array($field, self::MARKER_FIELDS, true)) {
			return ['kind' => 'marker', 'field' => $field];
		}

		// Term meta before the AIOSEO table prefix, which it otherwise matches.
		if (in_array($field, self::LEGACY_AIOSEO_TERM_META, true)) {
			return ['kind' => 'term_meta', 'key' => $field];
		}

		if (strpos($field, 'yoast_tax_') === 0) {
			return self::classify_yoast_tax_field($field);
		}

		if (strpos($field, 'yoast_indexable_') === 0) {
			return [
				'kind'   => 'yoast_indexable_column',
				'column' => substr($field, strlen('yoast_indexable_')),
			];
		}

		if (strpos($field, 'aioseo_') === 0) {
			return [
				'kind'   => 'aioseo_column',
				'column' => substr($field, strlen('aioseo_')),
			];
		}

		// Rank Math and Yoast post/term fields are stored under their own names,
		// so the field name *is* the meta key.
		if (strpos($field, 'rank_math_') === 0 || strpos($field, '_yoast_wpseo_') === 0) {
			return ['kind' => 'post_meta', 'key' => $field];
		}

		return ['kind' => 'unrecognised'];
	}

	/**
	 * Split `yoast_tax_{taxonomy}_{wpseo_field}` into its two halves.
	 *
	 * Both halves can contain underscores — `product_cat` is a real taxonomy
	 * slug and every Yoast field name has an underscore of its own — so
	 * `explode('_')` cannot do this. The split point is the *last* occurrence of `_wpseo`, which is
	 * sound for a reason worth stating: every field in
	 * `WPSEO_Taxonomy_Meta::$defaults_per_term` begins with `wpseo` and none
	 * contains `_wpseo` after that first character. So the last `_wpseo` in the
	 * key can only ever be the one that starts the field name, even when the
	 * taxonomy slug happens to contain `_wpseo` itself.
	 *
	 * Deriving the boundary this way rather than matching against a copy of
	 * Yoast's field list matters twice over: the list lives in a class that is
	 * gone when Yoast is deactivated, and `enrich_defaults()` lets add-ons add
	 * fields that no hardcoded list would contain.
	 *
	 * A key with no `_wpseo` in its remainder is one of the legacy,
	 * taxonomy-less rows earlier builds wrote. Those name a field but not the
	 * taxonomy it belongs to, so `taxonomy` comes back null and the caller has
	 * to establish it from the term itself.
	 *
	 * @param string $field Field name beginning `yoast_tax_`.
	 * @return array<string, mixed>
	 */
	private static function classify_yoast_tax_field($field) {
		$remainder = substr($field, strlen('yoast_tax_'));

		if ($remainder === '') {
			return ['kind' => 'unrecognised', 'taxonomy' => null, 'field' => $remainder];
		}

		$boundary = strrpos($remainder, '_wpseo');

		// No taxonomy segment at all: the legacy `yoast_tax_{field}` shape.
		if ($boundary === false) {
			if (strpos($remainder, 'wpseo_') !== 0 || $remainder === 'wpseo_') {
				return ['kind' => 'unrecognised', 'taxonomy' => null, 'field' => $remainder];
			}

			return [
				'kind'     => 'yoast_tax',
				'taxonomy' => null,
				'field'    => $remainder,
			];
		}

		$taxonomy = substr($remainder, 0, $boundary);
		$yoast_field = substr($remainder, $boundary + 1);

		// Both halves have to be real. An empty taxonomy names no option entry,
		// and a field of exactly `wpseo` names no Yoast field -- every key in
		// `$defaults_per_term` is `wpseo_` followed by something. Checking the
		// convention rather than a copy of the list keeps this working when
		// Yoast is deactivated and when an add-on has added a field of its own.
		if ($taxonomy === '' || strpos($yoast_field, 'wpseo_') !== 0 || $yoast_field === 'wpseo_') {
			return ['kind' => 'unrecognised', 'taxonomy' => $taxonomy, 'field' => $yoast_field];
		}

		return [
			'kind'     => 'yoast_tax',
			'taxonomy' => $taxonomy,
			'field'    => $yoast_field,
		];
	}

	/**
	 * Restore all backed up third-party SEO values for one post or term.
	 *
	 * Idempotent: returns success and does nothing if no backup keys exist.
	 * Deletes the backup meta keys after a successful restoration.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 * @return array{success:bool,restored_count:int,deleted_keys:int,errors:array}
	 */
	public static function restore_object($object_type, $object_id) {
		$result = [
			'success'        => true,
			'restored_count' => 0,
			'deleted_keys'   => 0,
			'errors'         => [],
		];

		if (!in_array($object_type, ['post', 'term'], true) || (int) $object_id <= 0) {
			$result['success'] = false;
			$result['errors'][] = 'Invalid object type or ID';
			return $result;
		}

		$object_id = (int) $object_id;
		$backup_keys = self::get_backup_meta_keys($object_type, $object_id);

		if (empty($backup_keys)) {
			return $result;
		}

		$prefix_len = strlen(Metasync_Seo_Backup::BACKUP_META_PREFIX);
		$markers = [];
		$fields = [];

		foreach ($backup_keys as $meta_key) {
			$field_name = substr($meta_key, $prefix_len);
			$classified = self::classify_field($field_name);

			$backup = Metasync_Seo_Backup::read_backup($object_type, $object_id, $field_name);
			if (!$backup['exists']) {
				continue;
			}

			if ($classified['kind'] === 'marker') {
				$markers[$classified['field']] = $backup['value'];
			} else {
				$fields[] = [
					'field_name' => $field_name,
					'classified' => $classified,
					'value'      => $backup['value'],
				];
			}
		}

		// Group fields by storage mechanism for atomic / unified execution.
		// Anything that lands in no bucket is recorded in $unroutable and fails
		// the whole object: an unhandled field is one written by a newer build
		// than this one, and deleting its backup (the all-or-nothing gate runs
		// only when success stays true) would destroy the only copy of the
		// customer's original while leaving the live value in place.
		$post_or_term_meta = [];
		$legacy_term_meta  = [];
		$aioseo_cols       = [];
		$yoast_cols        = [];
		$yoast_tax_entries = [];
		$unroutable        = [];

		foreach ($fields as $item) {
			$kind = $item['classified']['kind'];
			$val  = $item['value'];

			if ($kind === 'post_meta') {
				$post_or_term_meta[$item['classified']['key']] = $val;
			} elseif ($kind === 'term_meta') {
				$legacy_term_meta[$item['classified']['key']] = $val;
			} elseif ($kind === 'aioseo_column') {
				$aioseo_cols[$item['classified']['column']] = $val;
			} elseif ($kind === 'yoast_indexable_column') {
				// Yoast indexables exist only for posts; a term backup row
				// claiming one is contamination, not a restore instruction.
				if ($object_type === 'post') {
					$yoast_cols[$item['classified']['column']] = $val;
				} else {
					$unroutable[] = $item['field_name'];
				}
			} elseif ($kind === 'yoast_tax') {
				// Taxonomy option rows exist only for terms; on a post this is
				// contamination, and on a term with no resolvable taxonomy the
				// option cannot be addressed safely.
				if ($object_type !== 'term') {
					$unroutable[] = $item['field_name'];
					continue;
				}
				$tax = $item['classified']['taxonomy'];
				if ($tax === null) {
					$term_obj = get_term($object_id);
					if ($term_obj && !is_wp_error($term_obj)) {
						$tax = $term_obj->taxonomy;
					}
				}
				if ($tax !== null) {
					$yoast_tax_entries[$tax][$item['classified']['field']] = $val;
				} else {
					$unroutable[] = $item['field_name'];
				}
			} else {
				// classify_field() refused to guess; so must the restore.
				$unroutable[] = $item['field_name'];
			}
		}

		if (!empty($unroutable)) {
			$result['success'] = false;
			$result['errors'][] = 'Unroutable backup fields (left untouched, backups kept): ' . implode(', ', $unroutable);
		}

		// 1. Table: AIOSEO
		if (!empty($aioseo_cols) || isset($markers['aioseo_row_existed'])) {
			$aioseo_marker = $markers['aioseo_row_existed'] ?? null;
			$aioseo_ok = self::restore_aioseo_table($object_type, $object_id, $aioseo_cols, $aioseo_marker);
			if (!$aioseo_ok) {
				$result['success'] = false;
				$result['errors'][] = 'Failed restoring AIOSEO table data';
			} else {
				$result['restored_count'] += count($aioseo_cols) + (isset($markers['aioseo_row_existed']) ? 1 : 0);
			}
		}

		// 2. Table: Yoast Indexable (post only)
		if ($object_type === 'post' && (!empty($yoast_cols) || isset($markers['yoast_indexable_row_existed']))) {
			$yoast_marker = $markers['yoast_indexable_row_existed'] ?? null;
			$yoast_ok = self::restore_yoast_indexable_table($object_id, $yoast_cols, $yoast_marker);
			if (!$yoast_ok) {
				$result['success'] = false;
				$result['errors'][] = 'Failed restoring Yoast indexable table data';
			} else {
				$result['restored_count'] += count($yoast_cols) + (isset($markers['yoast_indexable_row_existed']) ? 1 : 0);
			}
		}

		// 3. Post/Term meta (Rank Math & _yoast_wpseo_*)
		foreach ($post_or_term_meta as $key => $val) {
			$write_ok = self::restore_meta_field($object_type, $object_id, $key, $val);
			if ($write_ok) {
				$result['restored_count']++;
			} else {
				$result['success'] = false;
				$result['errors'][] = "Failed restoring meta field: $key";
			}
		}

		// 4. Legacy AIOSEO term meta (_aioseo_title, _aioseo_description)
		foreach ($legacy_term_meta as $key => $val) {
			$write_ok = self::restore_meta_field('term', $object_id, $key, $val);
			if ($write_ok) {
				$result['restored_count']++;
			} else {
				$result['success'] = false;
				$result['errors'][] = "Failed restoring legacy term meta field: $key";
			}
		}

		// 5. Yoast taxonomy meta option
		if ($object_type === 'term' && !empty($yoast_tax_entries)) {
			foreach ($yoast_tax_entries as $tax => $fields_map) {
				$tax_ok = self::restore_yoast_taxonomy_meta($object_id, $tax, $fields_map);
				if ($tax_ok) {
					$result['restored_count'] += count($fields_map);
				} else {
					$result['success'] = false;
					$result['errors'][] = "Failed restoring Yoast taxonomy meta for taxonomy: $tax";
				}
			}
		}

		// Delete backup keys only if all operations succeeded
		if ($result['success']) {
			$deleted = self::delete_backup_meta_keys($object_type, $object_id, $backup_keys);
			$result['deleted_keys'] = $deleted;
		}

		return $result;
	}

	/**
	 * Fetch all raw backup meta keys present on an object.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 * @return string[]
	 */
	private static function get_backup_meta_keys($object_type, $object_id) {
		global $wpdb;
		if (!isset($wpdb)) {
			return [];
		}

		$prefix_like = $wpdb->esc_like(Metasync_Seo_Backup::BACKUP_META_PREFIX) . '%';
		if ($object_type === 'post') {
			return $wpdb->get_col($wpdb->prepare(
				"SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
				$object_id,
				$prefix_like
			)) ?: [];
		}

		return $wpdb->get_col($wpdb->prepare(
			"SELECT meta_key FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key LIKE %s",
			$object_id,
			$prefix_like
		)) ?: [];
	}

	/**
	 * Remove backup keys from post or term meta after restoration.
	 *
	 * @param string   $object_type 'post' or 'term'.
	 * @param int      $object_id   Object ID.
	 * @param string[] $keys        Array of meta keys to delete.
	 * @return int Number of deleted keys.
	 */
	private static function delete_backup_meta_keys($object_type, $object_id, array $keys) {
		$deleted = 0;
		foreach ($keys as $k) {
			if ($object_type === 'post') {
				if (delete_post_meta($object_id, $k)) {
					$deleted++;
				}
			} else {
				if (delete_term_meta($object_id, $k)) {
					$deleted++;
				}
			}
		}
		return $deleted;
	}

	/**
	 * Restore a single standard post or term meta field.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Object ID.
	 * @param string $key         Meta key.
	 * @param mixed  $value       Backup value (null means delete).
	 * @return bool
	 */
	private static function restore_meta_field($object_type, $object_id, $key, $value) {
		if ($value === null) {
			// A null original means "was absent". delete_*_meta() returns false
			// when nothing matched, which is the goal state already reached —
			// treat that as success or already-correct objects wedge as failed
			// on every retry.
			if ($object_type === 'post') {
				return !metadata_exists('post', $object_id, $key) || delete_post_meta($object_id, $key);
			}
			return !metadata_exists('term', $object_id, $key) || delete_term_meta($object_id, $key);
		}

		$slashed = wp_slash($value);

		if ($object_type === 'post') {
			// update_post_meta() returns false both for real failures and for
			// identical-value no-ops, so the only trustworthy check is the
			// stored value itself.
			update_post_meta($object_id, $key, $slashed);
			$stored = get_post_meta($object_id, $key, true);
			return ($stored == $value);
		}

		update_term_meta($object_id, $key, $slashed);
		$stored = get_term_meta($object_id, $key, true);
		return ($stored == $value);
	}

	/**
	 * Restore AIOSEO custom table data for post or term.
	 *
	 * @param string      $object_type 'post' or 'term'.
	 * @param int         $object_id   Object ID.
	 * @param array       $columns     Backed up column values.
	 * @param string|null $marker      '0' (created by MetaSync), '1' (pre-existing), or null (absent marker).
	 * @return bool
	 */
	private static function restore_aioseo_table($object_type, $object_id, array $columns, $marker) {
		global $wpdb;
		if (!isset($wpdb)) {
			return false;
		}

		$table = ($object_type === 'post')
			? $wpdb->prefix . 'aioseo_posts'
			: $wpdb->prefix . 'aioseo_terms';

		$id_col = ($object_type === 'post') ? 'post_id' : 'term_id';

		// If marker is '0', the row was created by MetaSync -> delete row.
		if ($marker === '0') {
			$deleted = $wpdb->delete($table, [$id_col => $object_id]);
			return ($deleted !== false);
		}

		// Marker is '1' or absent (fail-closed to per-column update)
		if (empty($columns)) {
			return true;
		}

		$updated = $wpdb->update(
			$table,
			$columns,
			[$id_col => $object_id]
		);

		if ($updated === false) {
			return false;
		}

		// 0 affected rows is ambiguous: identical values (fine) or the row is
		// gone (the original values now have nowhere to live — fail so the
		// backup keys survive). A row-existence probe settles it.
		if ($updated === 0) {
			$exists = $wpdb->get_var($wpdb->prepare(
				"SELECT {$id_col} FROM {$table} WHERE {$id_col} = %d",
				$object_id
			));
			return ($exists !== null);
		}

		return true;
	}

	/**
	 * Restore Yoast indexable table data for a post.
	 *
	 * @param int         $post_id Post ID.
	 * @param array       $columns Backed up column values.
	 * @param string|null $marker  '0', '1', or null.
	 * @return bool
	 */
	private static function restore_yoast_indexable_table($post_id, array $columns, $marker) {
		global $wpdb;
		if (!isset($wpdb)) {
			return false;
		}

		$table = $wpdb->prefix . 'yoast_indexable';

		// If marker is '0', the row was created by MetaSync -> delete row.
		if ($marker === '0') {
			$deleted = $wpdb->delete($table, [
				'object_id'   => $post_id,
				'object_type' => 'post',
			]);
			return ($deleted !== false);
		}

		// Marker is '1' or absent -> per-column update
		if (empty($columns)) {
			return true;
		}

		$updated = $wpdb->update(
			$table,
			$columns,
			[
				'object_id'   => $post_id,
				'object_type' => 'post',
			]
		);

		if ($updated === false) {
			return false;
		}

		// 0 affected rows: identical values (fine) or the row is gone (the
		// original values now have nowhere to live — fail so the backups
		// survive). Probe for the row to tell the two apart.
		if ($updated === 0) {
			$exists = $wpdb->get_var($wpdb->prepare(
				"SELECT object_id FROM {$table} WHERE object_id = %d AND object_type = 'post'",
				$post_id
			));
			return ($exists !== null);
		}

		return true;
	}

	/**
	 * Restore Yoast taxonomy metadata in the raw wpseo_taxonomy_meta option.
	 *
	 * Writes the raw option directly, pruning null/absent fields and cleaning empty entries,
	 * then fires edited_term to trigger Yoast's indexable rebuild.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @param array  $fields   Yoast field key => backed up value.
	 * @return bool
	 */
	private static function restore_yoast_taxonomy_meta($term_id, $taxonomy, array $fields) {
		$option = get_option('wpseo_taxonomy_meta', []);
		if (!is_array($option)) {
			return false;
		}
		if (!Metasync_Seo_Backup::db_read_succeeded()) {
			return false;
		}

		$term_entry = $option[$taxonomy][$term_id] ?? [];
		if (!is_array($term_entry)) {
			$term_entry = [];
		}

		foreach ($fields as $yoast_key => $backup_val) {
			if ($backup_val === null) {
				unset($term_entry[$yoast_key]);
			} else {
				$term_entry[$yoast_key] = $backup_val;
			}
		}

		$option_before = $option;

		if (empty($term_entry)) {
			if (isset($option[$taxonomy][$term_id])) {
				unset($option[$taxonomy][$term_id]);
			}
			if (isset($option[$taxonomy]) && empty($option[$taxonomy])) {
				unset($option[$taxonomy]);
			}
		} else {
			if (!isset($option[$taxonomy]) || !is_array($option[$taxonomy])) {
				$option[$taxonomy] = [];
			}
			$option[$taxonomy][$term_id] = $term_entry;
		}

		// Already at the target state: update_option() would return false for
		// an unchanged value and wedge this object as failed on every retry,
		// so short-circuit instead — and skip the edited_term churn too.
		if ($option === $option_before) {
			return true;
		}

		$saved = update_option('wpseo_taxonomy_meta', $option);

		if ($saved === false) {
			// False also covers the unchanged-value no-op; re-read to decide.
			$after = get_option('wpseo_taxonomy_meta', []);
			if ($after !== $option) {
				return false;
			}
		}

		// Trigger Yoast indexable cache rebuild after a real write.
		$term_obj = get_term($term_id, $taxonomy);
		$tt_id = ($term_obj && !is_wp_error($term_obj)) ? (int) $term_obj->term_taxonomy_id : 0;
		do_action('edited_term', $term_id, $tt_id, $taxonomy);

		return true;
	}

	// =========================================================================
	// Batch Driver & Queue Processor (R2)
	// =========================================================================

	/**
	 * Get current batch progress data.
	 *
	 * @return array Progress status array.
	 */
	public static function get_progress(): array {
		$default = [
			'total'           => 0,
			'processed'       => 0,
			'failed'          => 0,
			'fields_restored' => 0,
			'status'          => 'idle', // 'idle', 'running', 'completed', 'cancelled'
			'started_at'      => '',
		];

		return wp_parse_args(get_option(self::PROGRESS_OPTION, []), $default);
	}

	/**
	 * Check if a batch restore is currently running.
	 *
	 * @return bool
	 */
	public static function is_running(): bool {
		$progress = self::get_progress();
		return ($progress['status'] === 'running');
	}

	/**
	 * Start a new bulk restore run.
	 *
	 * 1. Turns consent OFF immediately to prevent racing syncs from re-overwriting.
	 * 2. Builds queue of all post and term IDs holding backup meta.
	 * 3. Initializes progress option and schedules cron fallback.
	 *
	 * @return array Progress data.
	 */
	public static function start_batch(): array {
		if (self::is_running()) {
			return self::get_progress();
		}

		// Turn consent OFF before the queue is built, not after. Objects whose
		// backups are recorded between the snapshot and the disable would be
		// missing from the queue while the run still reports "completed"; with
		// consent already off when the snapshot runs, nothing new can start
		// writing its way into that gap.
		//
		// If it cannot be turned off, a concurrent OTTO sync could re-overwrite
		// just-restored objects after their backups are deleted — unrecoverable.
		// Abort instead.
		$consent_was_enabled = class_exists('Metasync_Seo_Backup')
			&& Metasync_Seo_Backup::is_enabled();
		if (!self::disable_consent()) {
			return [
				'total'           => 0,
				'processed'       => 0,
				'failed'          => 0,
				'fields_restored' => 0,
				'status'          => 'idle',
				'error'           => __('Could not disable syncing to other SEO plugins; restore aborted.', 'metasync'),
				'started_at'      => current_time('mysql'),
			];
		}

		$queue = self::query_objects_to_restore();

		// Nothing to restore: put the consent toggle back the way the user had
		// it — an empty run must not change their setting — and report
		// completion. When the toggle cannot be put back, say so instead of
		// reporting a clean finish with the setting silently left off.
		if (empty($queue)) {
			$result = [
				'total'           => 0,
				'processed'       => 0,
				'failed'          => 0,
				'fields_restored' => 0,
				'status'          => 'completed',
				'started_at'      => current_time('mysql'),
			];

			if ($consent_was_enabled && !self::enable_consent()) {
				$result['error'] = __('Nothing to restore, but syncing to other SEO plugins could not be re-enabled.', 'metasync');
			}

			return $result;
		}

		update_option(self::QUEUE_OPTION, $queue, false);

		$progress = [
			'total'           => count($queue),
			'processed'       => 0,
			'failed'          => 0,
			'fields_restored' => 0,
			'status'          => 'running',
			'started_at'      => current_time('mysql'),
		];
		update_option(self::PROGRESS_OPTION, $progress, false);

		// Schedule cron fallback
		if (!wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + 120, 'metasync_every_2_minutes', self::CRON_HOOK);
		}

		return self::get_progress();
	}

	/**
	 * Cancel a running batch restore.
	 *
	 * @param string $error Optional reason surfaced to the admin UI when the
	 *                      run was cancelled by the system rather than the user.
	 * @return array Progress data after cancellation.
	 */
	public static function cancel_batch($error = ''): array {
		$progress = self::get_progress();
		$progress['status'] = 'cancelled';
		if ($error !== '') {
			$progress['error'] = $error;
		}
		update_option(self::PROGRESS_OPTION, $progress, false);

		delete_option(self::QUEUE_OPTION);
		self::release_lock();
		wp_clear_scheduled_hook(self::CRON_HOOK);

		return $progress;
	}

	/**
	 * Process one AJAX tick of the restore batch.
	 *
	 * @return array Updated progress data.
	 */
	public static function process_ajax_tick(): array {
		$progress = self::get_progress();

		if ($progress['status'] !== 'running') {
			return $progress;
		}

		// Consent is re-asserted on every tick, not just at start: the toggle
		// can be switched back on in another tab while the run is in flight,
		// and a sync that slips through between restores can re-overwrite a
		// restored value whose backup row was already deleted — unrecoverable.
		// If it cannot be re-disabled, cancel rather than restore with syncs
		// live.
		if (!self::disable_consent()) {
			self::cancel_batch(__('Could not keep syncing to other SEO plugins disabled; restore cancelled.', 'metasync'));
			return self::get_progress();
		}

		if (!self::acquire_lock()) {
			return $progress;
		}

		try {
			$queue = get_option(self::QUEUE_OPTION, []);

			if (empty($queue)) {
				self::complete_batch();
				return self::get_progress();
			}

			$batch_size = self::DEFAULT_BATCH_SIZE;
			$batch = array_splice($queue, 0, $batch_size);

			// Same deadline the cron path honours: 25 Yoast-heavy terms can
			// each fire edited_term (an indexable rebuild), so a fixed item
			// count alone can overrun max_execution_time.
			$start = time();
			$time_limit = self::get_safe_time_limit();
			$done = 0;

			for ($i = 0, $n = count($batch); $i < $n; $i++) {
				$current = get_option(self::PROGRESS_OPTION, []);
				if (($current['status'] ?? '') !== 'running') {
					break;
				}

				self::restore_single_item($batch[$i], $progress);
				$done = $i + 1;

				if ((time() - $start) >= $time_limit) {
					break;
				}
			}

			// Items the deadline cut short go back to the front of the queue.
			if ($done < count($batch)) {
				$queue = array_merge(array_slice($batch, $done), $queue);
			}

			$status_now = get_option(self::PROGRESS_OPTION, []);
			if (($status_now['status'] ?? '') === 'running') {
				// The queue is one option row rewritten whole every tick. A
				// persist that fails silently leaves the next tick re-reading
				// the items this one just restored — as no-op "successes" that
				// never drain the queue. Cancel with the error instead.
				if (update_option(self::QUEUE_OPTION, $queue, false) === false) {
					self::cancel_batch(__('Could not save the restore queue; restore cancelled.', 'metasync'));
					return self::get_progress();
				}
				self::merge_progress_counters($progress);

				if (empty($queue)) {
					self::complete_batch();
					return self::get_progress();
				}
			}
		} finally {
			self::release_lock();
		}

		return self::get_progress();
	}

	/**
	 * Process batch tick via Cron fallback.
	 */
	public static function process_batch_tick(): void {
		$progress = self::get_progress();

		if ($progress['status'] !== 'running') {
			return;
		}

		// Same re-assertion as the AJAX tick: consent is kept off for the
		// whole run, and a run that cannot keep it off is cancelled rather
		// than allowed to restore with syncs live.
		if (!self::disable_consent()) {
			self::cancel_batch(__('Could not keep syncing to other SEO plugins disabled; restore cancelled.', 'metasync'));
			return;
		}

		if (!self::acquire_lock()) {
			return;
		}

		try {
			$queue = get_option(self::QUEUE_OPTION, []);

			if (empty($queue)) {
				self::complete_batch();
				return;
			}

			$start = time();
			$time_limit = self::get_safe_time_limit();

			while (!empty($queue) && (time() - $start) < $time_limit) {
				$current = get_option(self::PROGRESS_OPTION, []);
				if (($current['status'] ?? '') !== 'running') {
					break;
				}

				$batch = array_splice($queue, 0, self::DEFAULT_BATCH_SIZE);
				$done = 0;

				// Same per-item checks as the AJAX tick: 25 Yoast-heavy terms
				// can each fire edited_term (an indexable rebuild), so a fixed
				// item count alone can overrun max_execution_time mid-batch,
				// and a cancellation must be honoured between items.
				foreach ($batch as $item) {
					$current = get_option(self::PROGRESS_OPTION, []);
					if (($current['status'] ?? '') !== 'running') {
						break;
					}

					self::restore_single_item($item, $progress);
					$done++;

					if ((time() - $start) >= $time_limit) {
						break;
					}
				}

				// Items the deadline or a cancellation cut short go back to the
				// front of the queue.
				if ($done < count($batch)) {
					$queue = array_merge(array_slice($batch, $done), $queue);
				}

				self::merge_progress_counters($progress);
			}

			$status_now = get_option(self::PROGRESS_OPTION, []);
			if (($status_now['status'] ?? '') === 'running') {
				// Same persist check as the AJAX tick: a queue that will not
				// save would loop the next ticks over the same first items
				// forever, never finishing.
				if (update_option(self::QUEUE_OPTION, $queue, false) === false) {
					self::cancel_batch(__('Could not save the restore queue; restore cancelled.', 'metasync'));
					return;
				}

				if (empty($queue)) {
					self::complete_batch();
				}
			}
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Restore a single queued item with error handling.
	 *
	 * @param array $item     ['type' => 'post'|'term', 'id' => int]
	 * @param array &$progress Progress array by reference.
	 */
	private static function restore_single_item(array $item, array &$progress): void {
		try {
			$type = $item['type'] ?? '';
			$id   = (int) ($item['id'] ?? 0);

			$res = self::restore_object($type, $id);
			$progress['processed']++;
			if (!empty($res['success'])) {
				$progress['fields_restored'] += (int) $res['restored_count'];
			} else {
				$progress['failed']++;
			}
		} catch (\Throwable $e) {
			$progress['processed']++;
			$progress['failed']++;
			if (class_exists('Metasync_Error_Logger')) {
				Metasync_Error_Logger::log(
					Metasync_Error_Logger::CATEGORY_DATABASE_ERROR,
					Metasync_Error_Logger::SEVERITY_ERROR,
					'Failed restoring SEO backup for ' . ($item['type'] ?? '') . ' #' . ($item['id'] ?? ''),
					['error' => $e->getMessage()]
				);
			}
		}
	}

	/**
	 * Complete the batch restore run and record sync history summary.
	 */
	private static function complete_batch(): void {
		$progress = self::get_progress();
		$finalized = false;

		if ($progress['status'] === 'running') {
			$progress['status'] = 'completed';
			update_option(self::PROGRESS_OPTION, $progress, false);
			$finalized = true;
		}

		delete_option(self::QUEUE_OPTION);
		self::release_lock();
		wp_clear_scheduled_hook(self::CRON_HOOK);

		if ($finalized) {
			self::record_history_summary($progress);
		}
	}

	/**
	 * Record one summary row in metasync_sync_history for the entire restore run.
	 *
	 * @param array $progress Final progress data.
	 */
	private static function record_history_summary(array $progress): void {
		// Nothing in the boot chain loads this class — its other require sites
		// are inside method bodies that only run on unrelated pages — so
		// without this the R4 audit row silently never appears.
		if (!class_exists('Metasync_Sync_History_Database')) {
			$history_class = plugin_dir_path(dirname(__FILE__)) . 'sync-history/class-metasync-sync-history-database.php';
			if (!is_readable($history_class)) {
				return;
			}
			require_once $history_class;
		}

		try {
			$history = new Metasync_Sync_History_Database();
			$product = class_exists('Metasync') ? Metasync::get_effective_plugin_name() : 'Search Atlas';

			$history->add([
				'title'        => sprintf(
					'%s restored original SEO values for %d objects (%d field(s) restored)',
					$product,
					(int) ($progress['processed'] - $progress['failed']),
					(int) ($progress['fields_restored'] ?? 0)
				),
				'source'       => 'SEO Plugin Sync',
				'status'       => 'published',
				'content_type' => 'restore',
				'url'          => null,
				'meta_data'    => wp_json_encode([
					'total_objects'   => $progress['total'],
					'processed'       => $progress['processed'],
					'failed'          => $progress['failed'],
					'fields_restored' => $progress['fields_restored'] ?? 0,
					'started_at'      => $progress['started_at'],
					'completed_at'    => current_time('mysql'),
				]),
				'created_at'   => current_time('mysql'),
			]);
		} catch (\Throwable $e) {
			// Never let a logging failure break the operation.
		}
	}

	/**
	 * Build the list of all object IDs needing restore using keyset pagination.
	 *
	 * @return array Array of ['type' => 'post'|'term', 'id' => int]
	 */
	public static function query_objects_to_restore(): array {
		global $wpdb;
		if (!isset($wpdb)) {
			return [];
		}

		$queue = [];
		$prefix_like = $wpdb->esc_like(Metasync_Seo_Backup::BACKUP_META_PREFIX) . '%';

		// 1. Posts with backup meta (keyset paginated by post_id)
		$last_id = 0;
		do {
			$posts = $wpdb->get_col($wpdb->prepare(
				"SELECT DISTINCT post_id
				 FROM {$wpdb->postmeta}
				 WHERE meta_key LIKE %s AND post_id > %d
				 ORDER BY post_id ASC
				 LIMIT %d",
				$prefix_like,
				$last_id,
				self::QUERY_PAGE_SIZE
			));

			if (!empty($posts)) {
				$int_posts = array_map('intval', $posts);
				foreach ($int_posts as $pid) {
					$queue[] = ['type' => 'post', 'id' => $pid];
				}
				$last_id = end($int_posts);
			}
		} while (!empty($posts) && count($posts) === self::QUERY_PAGE_SIZE);

		// 2. Terms with backup meta (keyset paginated by term_id)
		$last_id = 0;
		do {
			$terms = $wpdb->get_col($wpdb->prepare(
				"SELECT DISTINCT term_id
				 FROM {$wpdb->termmeta}
				 WHERE meta_key LIKE %s AND term_id > %d
				 ORDER BY term_id ASC
				 LIMIT %d",
				$prefix_like,
				$last_id,
				self::QUERY_PAGE_SIZE
			));

			if (!empty($terms)) {
				$int_terms = array_map('intval', $terms);
				foreach ($int_terms as $tid) {
					$queue[] = ['type' => 'term', 'id' => $tid];
				}
				$last_id = end($int_terms);
			}
		} while (!empty($terms) && count($terms) === self::QUERY_PAGE_SIZE);

		return $queue;
	}

	/**
	 * Calculate safe time limit for cron processing.
	 *
	 * @param int $max_execution_time
	 * @return int
	 */
	protected static function get_safe_time_limit(int $max_execution_time = -1): int {
		if ($max_execution_time < 0) {
			$max_execution_time = (int) ini_get('max_execution_time');
		}

		if ($max_execution_time === 0) {
			return self::CRON_TIME_LIMIT;
		}

		$safe = $max_execution_time - self::TIME_SAFETY_MARGIN;
		return max(1, min(self::CRON_TIME_LIMIT, $safe));
	}

	/**
	 * Atomically acquire the batch lock.
	 */
	private static function acquire_lock(): bool {
		$existing = get_option(self::LOCK_OPTION);

		if ($existing && (time() - (int) $existing) < self::LOCK_STALE_AFTER) {
			return false;
		}

		if ($existing) {
			delete_option(self::LOCK_OPTION);
		}

		return (bool) add_option(self::LOCK_OPTION, time(), '', false);
	}

	/**
	 * Release the batch lock.
	 */
	private static function release_lock(): void {
		delete_option(self::LOCK_OPTION);
	}

	/**
	 * Persist progress counters without clobbering concurrent status changes.
	 */
	private static function merge_progress_counters(array $progress): void {
		$fresh = get_option(self::PROGRESS_OPTION, []);
		if (!is_array($fresh) || ($fresh['status'] ?? '') !== 'running') {
			return;
		}

		$fresh['processed']       = $progress['processed'];
		$fresh['failed']          = $progress['failed'];
		$fresh['fields_restored'] = $progress['fields_restored'];
		update_option(self::PROGRESS_OPTION, $fresh, false);
	}
}

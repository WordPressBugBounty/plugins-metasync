<?php
/**
 * MCP Client Keys
 *
 * Storage and verification for per-client MCP keys. Each key belongs to the
 * admin who created it, carries a read/full access level and an expiry, and
 * is stored as a SHA-256 hash plus its last four characters. The plaintext
 * token is shown exactly once, at creation time.
 *
 * @package    MetaSync
 * @subpackage MCP_Server
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_MCP_Keys {

	/**
	 * Neutral prefix for client key tokens. Never starts with "eyJ" so a
	 * client key can never be confused with a JWT Bearer token.
	 */
	const TOKEN_PREFIX = 'msk_';

	/**
	 * Custom table name (without the $wpdb->prefix), so keys are
	 * site-specific on multisite for free.
	 *
	 * @var string
	 */
	public static $table_name = 'metasync_mcp_keys';

	/**
	 * Minimum seconds between last_used_at writes for the same key.
	 */
	const LAST_USED_THROTTLE = 60;

	/**
	 * Allowed access levels.
	 */
	const ACCESS_READ  = 'read';
	const ACCESS_FULL  = 'full';

	/**
	 * Convert an expiry choice into its UTC expiration datetime.
	 *
	 * @param string      $expiry_choice Preset day count or 'custom'.
	 * @param string|null $custom_days   Positive whole days when using custom.
	 * @return string|WP_Error MySQL datetime in UTC, or an error.
	 */
	public static function expiry_to_datetime($expiry_choice, $custom_days = null) {
		$days = self::expiry_choice_to_days($expiry_choice, $custom_days);
		if (is_wp_error($days)) {
			return $days;
		}
		return gmdate('Y-m-d H:i:s', time() + ($days * DAY_IN_SECONDS));
	}

	/**
	 * Map and validate an expiry choice.
	 *
	 * Custom expiry is deliberately bounded to one year so keys cannot become
	 * effectively non-expiring through a crafted request.
	 *
	 * @param string      $expiry_choice Preset day count or 'custom'.
	 * @param string|null $custom_days   Positive whole days when using custom.
	 * @return int|WP_Error Day count, or an error.
	 */
	public static function expiry_choice_to_days($expiry_choice, $custom_days = null) {
		$presets = ['15' => 15, '30' => 30, '90' => 90, '365' => 365];
		if (isset($presets[$expiry_choice])) {
			return $presets[$expiry_choice];
		}

		if ($expiry_choice === 'custom'
			&& is_string($custom_days)
			&& preg_match('/^[1-9][0-9]{0,2}$/', $custom_days)
			&& (int) $custom_days <= 365
		) {
			return (int) $custom_days;
		}

		return new WP_Error(
			'mcp_key_invalid_expiry',
			'Expiry must be 15, 30, 90, or 365 days, or a custom value from 1 to 365 days.',
			['status' => 400]
		);
	}

	/**
	 * Full table name including the prefix.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::$table_name;
	}

	/**
	 * CREATE TABLE statement for dbDelta().
	 *
	 * @return string
	 */
	public static function create_table_sql() {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();

		return "CREATE TABLE " . self::table_name() . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			key_hash CHAR(64) NOT NULL,
			key_last4 CHAR(4) NOT NULL DEFAULT '',
			name VARCHAR(100) NOT NULL DEFAULT '',
			owner_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			access_level VARCHAR(10) NOT NULL DEFAULT 'read',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			expires_at DATETIME NULL DEFAULT NULL,
			last_used_at DATETIME NULL DEFAULT NULL,
			revoked_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY id (id),
			UNIQUE KEY key_hash (key_hash),
			KEY status (status),
			KEY owner_id (owner_id),
			KEY expires_at (expires_at)
		) $collate;";
	}

	/**
	 * Create the table if it does not exist yet.
	 *
	 * Self-heals on read so sites that never saw the activation or version
	 * migration (for example non-primary sites on a network activation) still
	 * get the table the first time a key is needed.
	 *
	 * @return void
	 */
	public static function ensure_table() {
		global $wpdb;

		$table = self::table_name();
		$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- self-heal schema creation, gated by an existence check
		if ($found === $table) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(self::create_table_sql());
	}

	/**
	 * Generate a new random client key token.
	 *
	 * @return string Token in the form msk_<48 hex chars>.
	 */
	public static function generate_token() {
		return self::TOKEN_PREFIX . bin2hex(random_bytes(24));
	}

	/**
	 * Create a key and persist only its hash, last four characters and
	 * metadata. The plaintext token is returned to the caller exactly once.
	 *
	 * @param string      $name         Human-readable key name.
	 * @param string      $access_level 'read' or 'full'.
	 * @param string      $expiry_choice Preset day count or 'custom'.
	 * @param int         $owner_id     Creating admin user ID.
	 * @param string|null $custom_days  Positive whole days when using custom.
	 * @return array|WP_Error ['token' => string, 'id' => int] on success.
	 */
	public static function create_key($name, $access_level, $expiry_choice, $owner_id, $custom_days = null) {
		global $wpdb;

		self::ensure_table();

		$name = sanitize_text_field($name);
		if ($name === '' || strlen($name) > 100) {
			return new WP_Error('mcp_key_invalid_name', 'Key name is required (max 100 characters).', ['status' => 400]);
		}

		$access_level = ($access_level === self::ACCESS_FULL) ? self::ACCESS_FULL : self::ACCESS_READ;
		$expiry = self::expiry_to_datetime($expiry_choice, $custom_days);
		if (is_wp_error($expiry)) {
			return $expiry;
		}
		$owner_id = absint($owner_id);
		if (!$owner_id || !get_userdata($owner_id)) {
			return new WP_Error('mcp_key_invalid_owner', 'Key owner must be an existing user.', ['status' => 400]);
		}

		$token = self::generate_token();
		$now   = gmdate('Y-m-d H:i:s');

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated keys table, insert via $wpdb API
			self::table_name(),
			[
				'key_hash'     => hash('sha256', $token),
				'key_last4'    => substr($token, -4),
				'name'         => $name,
				'owner_id'     => $owner_id,
				'access_level' => $access_level,
				'status'       => 'active',
				'created_at'   => $now,
				'expires_at'   => $expiry,
				'last_used_at' => null,
				'revoked_at'   => null,
			],
			['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
		);

		if ($inserted === false) {
			return new WP_Error('mcp_key_storage_failed', 'Could not store the MCP key.', ['status' => 500]);
		}

		return [
			'token' => $token,
			'id'    => (int) $wpdb->insert_id,
		];
	}

	/**
	 * Look up a key row by hashing the presented token.
	 *
	 * The deterministic SHA-256 makes the unique index usable for lookups
	 * while keeping the plaintext out of the database. A final constant-time
	 * comparison guards against any storage-level mutation.
	 *
	 * @param mixed $token Presented token (untrusted request input).
	 * @return object|null Key row, or null when no key matches.
	 */
	public static function verify_token($token) {
		global $wpdb;

		if (!is_string($token) || strpos($token, self::TOKEN_PREFIX) !== 0) {
			return null;
		}

		self::ensure_table();

		$hash = hash('sha256', $token);
		$row  = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE key_hash = %s LIMIT 1', $hash)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- dedicated keys table, read by unique index; table name cannot be a placeholder

		if ($row === null || !hash_equals($row->key_hash, $hash)) {
			return null;
		}

		return $row;
	}

	/**
	 * Cheap validity check for the REST auth-rescue path: the token must
	 * resolve to an active, unexpired key. Owner checks stay in the MCP
	 * server, which owns the request lifecycle.
	 *
	 * @param string $token Presented token.
	 * @return bool
	 */
	public static function is_valid_bearer_key($token) {
		$row = self::verify_token($token);
		if ($row === null || $row->status === 'revoked') {
			return false;
		}
		return !self::is_expired_row($row);
	}

	/**
	 * Whether a key row is past its expiry.
	 *
	 * @param object $row Key row.
	 * @return bool
	 */
	public static function is_expired_row($row) {
		if (empty($row->expires_at) || $row->expires_at === '0000-00-00 00:00:00') {
			return false;
		}
		return strtotime($row->expires_at) < time();
	}

	/**
	 * Derived status for display: Active, Expired or Revoked.
	 *
	 * @param object $row Key row.
	 * @return string
	 */
	public static function key_status($row) {
		if ($row->status === 'revoked') {
			return 'revoked';
		}
		if (self::is_expired_row($row)) {
			return 'expired';
		}
		return 'active';
	}

	/**
	 * Masked display form of a key row, for example "msk_************ab12".
	 *
	 * @param object $row Key row.
	 * @return string
	 */
	public static function key_mask($row) {
		return self::TOKEN_PREFIX . str_repeat('*', 12) . $row->key_last4;
	}

	/**
	 * Record key usage, throttled to one write per minute per key.
	 *
	 * @param object $row Key row.
	 * @return void
	 */
	public static function mark_used($row) {
		global $wpdb;

		$id = isset($row->id) ? (int) $row->id : 0;
		if (!$id) {
			return;
		}

		$cutoff = gmdate('Y-m-d H:i:s', time() - self::LAST_USED_THROTTLE);
		$last   = (isset($row->last_used_at) && $row->last_used_at && $row->last_used_at !== '0000-00-00 00:00:00')
			? $row->last_used_at
			: null;

		if ($last !== null && $last >= $cutoff) {
			return;
		}

		$wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- throttled usage stamp on a dedicated table
			'UPDATE ' . self::table_name() . ' SET last_used_at = %s WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name cannot be a placeholder
			gmdate('Y-m-d H:i:s'),
			$id
		));
	}

	/**
	 * Revoke a key immediately.
	 *
	 * @param int $id Key row ID.
	 * @return bool
	 */
	public static function revoke_key($id) {
		global $wpdb;

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated keys table, update via $wpdb API
			self::table_name(),
			[
				'status'      => 'revoked',
				'revoked_at'  => gmdate('Y-m-d H:i:s'),
			],
			['id' => absint($id)],
			['%s', '%s'],
			['%d']
		);

		return $updated !== false;
	}

	/**
	 * All keys, newest first, for the admin list.
	 *
	 * @return array
	 */
	public static function get_all_keys() {
		global $wpdb;

		self::ensure_table();

		$rows = $wpdb->get_results('SELECT * FROM ' . self::table_name() . ' ORDER BY created_at DESC, id DESC'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- dedicated keys table, admin list read; table name cannot be a placeholder
		return is_array($rows) ? $rows : [];
	}
}

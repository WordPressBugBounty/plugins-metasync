<?php

/**
 * Fired during plugin activation
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/includes
 * @author     Engineering Team <support@searchatlas.com>
 */
class Metasync_Activator
{

	/**
	 * Canonical list of MetaSync custom WP-Cron hooks.
	 * Shared with Metasync_Deactivator so deactivation cleans up every scheduled hook.
	 *
	 * @since 2.5.x
	 * @var string[]
	 */
	public static $cron_hooks = [
		'metasync_sync_log_daily_cleanup',
		'metasync_announce_cron',
		'metasync_rate_limit_cleanup',
		'metasync_heartbeat_cron_check',
		'metasync_burst_heartbeat',
		'metasync_check_debug_limits',
		'metasync_cleanup_transients',
		'metasync_hidden_post_check',
		'metasync_otto_recheck_404_exclusions',
		'metasync_db_cleanup',
		'metasync_media_batch_optimize_cron',
		'metasync_speed_cache_cleanup',
		'metasync_process_seo_job',
		'metasync_process_otto_crawl_url_job',
		'metasync_process_otto_batch_cache_job',
		'metasync_host_blocking_check',
		'metasync_host_blocking_weekly_check',
	];

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate()
	{
		// WordPress core sitemap functionality is required
		// if (wp_sitemaps_get_server()->sitemaps_enabled() == false) {
		// 	add_filter('wp_sitemaps_enabled', '__return_true');
		// }

		// Generate Plugin Auth Token on first activation
		self::ensure_plugin_auth_token();

		// Import whitelabel settings only if the JSON file is new or changed
		// (prevents overwriting admin UI changes on every deactivate/activate cycle)
		self::check_whitelabel_settings_update(true);

		// Pre-SSO announce: tell backend plugin is installed (PR4 - heartbeat reliability)
		update_option('metasync_announce_attempt_count', 0);
		self::send_announce_ping();
		update_option('metasync_announce_attempt_count', 1);

		// Schedule cron for announce pings 2-5 (every 10 minutes)
		if (!wp_next_scheduled('metasync_announce_cron')) {
			wp_schedule_event(time() + 10 * MINUTE_IN_SECONDS, 'metasync_every_10_minutes', 'metasync_announce_cron');
		}

		// Detect a host that blocks GET/POST between this site and Search Atlas.
		// Deferred to cron (~10 minutes out) rather than run here — a blocking HTTP call
		// inside the activation request is exactly what has taken slow hosts down before,
		// and a firewall-blocked request is the slowest kind there is.
		if (class_exists('Metasync_Host_Blocking_Check')) {
			Metasync_Host_Blocking_Check::schedule_initial_check();
		}

		// Set first activation flag for setup wizard
		if (!get_option('metasync_first_activation_time')) {
			update_option('metasync_first_activation_time', current_time('mysql'));
			update_option('metasync_show_wizard', true);
		}

		// Auto-disable WP core sitemap if a MetaSync sitemap already exists.
		// Skip on fresh installs where no sitemap has been generated yet so the site
		// doesn't end up with zero sitemaps.
		if (get_option('metasync_sitemap_auto_update', false)
			|| !empty(get_option('metasync_sitemap_files', []))
			|| file_exists(ABSPATH . 'sitemap_index.xml')
		) {
			update_option('metasync_disable_wp_sitemap', true);
		}

		// Soft flush only (no .htaccess rewrite). A hard flush calls
		// save_mod_rewrite_rules(), which takes an exclusive flock() on the site's
		// root .htaccess. On hosts whose cache/optimizer also holds that lock (e.g.
		// SiteGround + SG Optimizer), the activation request blocks until
		// max_execution_time and the host returns a 500. MetaSync's rewrite rules are
		// registered via add_rewrite_rule (stored in the `rewrite_rules` option, NOT
		// in .htaccess), so a soft flush is sufficient and never touches the file.
		flush_rewrite_rules(false);
	}

	/**
	 * Send pre-SSO announce ping to backend (zero-trust; backend rate-limits).
	 * POST /api/wp-plugin-announce/ with url + plugin_version; optional X-Plugin-Token for deduplication.
	 * Callable from activation and from init (rate-limited) when no API key yet.
	 *
	 * @since 2.5.x
	 */
	public static function send_announce_ping()
	{
		$base = 'https://ca.searchatlas.com';
		if (class_exists('Metasync_Endpoint_Manager')) {
			$base = Metasync_Endpoint_Manager::get_endpoint('CA_API_DOMAIN');
		} elseif (class_exists('Metasync')) {
			$base = Metasync::CA_API_DOMAIN;
		}
		$url = rtrim($base, '/') . '/api/wp-plugin-announce/';

		$body = wp_json_encode([
			'url' => get_home_url(),
			'plugin_version' => defined('METASYNC_VERSION') ? METASYNC_VERSION : 'unknown',
		]);

		$options = get_option('metasync_options', []);
		$plugin_auth_token = $options['general']['apikey'] ?? '';
		$headers = [
			'Content-Type' => 'application/json',
		];
		if (!empty($plugin_auth_token)) {
			$headers['X-Plugin-Token'] = $plugin_auth_token;
		}

		wp_remote_post($url, [
			'body'    => $body,
			'headers' => $headers,
			'timeout' => 10,
			'blocking' => false,
		]);
	}

	/**
	 * Ensure Plugin Auth Token exists
	 * Generates a unique Plugin Auth Token during plugin activation
	 */
	private static function ensure_plugin_auth_token()
	{
		$options = get_option('metasync_options', []);

		if (empty($options['general']['apikey'])) {
			// Generate unique Plugin Auth Token (alphanumeric only)
			$plugin_auth_token = wp_generate_password(32, false, false);

			// Initialize options structure if needed
			if (!isset($options['general'])) {
				$options['general'] = [];
			}

			// Store Plugin Auth Token
			$options['general']['apikey'] = $plugin_auth_token;
			update_option('metasync_options', $options);
		}
	}

	/**
	 * Get the path to the whitelabel settings JSON file
	 *
	 * @return string|false Path to the file if it exists, false otherwise
	 * @since 2.5.0
	 */
	public static function get_whitelabel_settings_file()
	{
		$plugin_dir = plugin_dir_path(dirname(__FILE__));

		// Check for whitelabel-settings.json in plugin root
		$json_file = $plugin_dir . 'whitelabel-settings.json';

		// Also check in a common extracted zip location (if zip was extracted)
		if (!file_exists($json_file)) {
			$json_file = $plugin_dir . 'metasync/whitelabel-settings.json';
		}

		if (!file_exists($json_file)) {
			return false;
		}

		return $json_file;
	}

	/**
	 * Check if whitelabel settings file has been updated and import if needed
	 * This method should be called on init to detect plugin uploads/updates
	 *
	 * @param bool $trusted_update_context True only for core activation/upgrader hooks.
	 * @return bool Whether the file was already current or imported successfully.
	 * @since 2.5.0
	 */
	public static function check_whitelabel_settings_update($trusted_update_context = false)
	{
		if (!$trusted_update_context && !current_user_can('manage_options')) {
			return false;
		}

		$json_file = self::get_whitelabel_settings_file();

		if ($json_file === false) {
			return false;
		}

		// Get current file modification time and content hash
		$file_mtime = filemtime($json_file);
		$file_hash = md5_file($json_file);
		if (!is_int($file_mtime) || !is_string($file_hash)) {
			return false;
		}

		// Get stored file info
		$stored_mtime = get_option('metasync_whitelabel_file_mtime', 0);
		$stored_hash = get_option('metasync_whitelabel_file_hash', '');

		// Check if file has changed (either modification time or content)
		if ($file_mtime > $stored_mtime || $file_hash !== $stored_hash) {
			// File has changed, import settings
			if (!self::import_whitelabel_settings()) {
				return false;
			}

			// Record the file only after the complete import succeeds.
			update_option('metasync_whitelabel_file_mtime', $file_mtime);
			update_option('metasync_whitelabel_file_hash', $file_hash);
		}

		return true;
	}

	/**
	 * Import whitelabel settings from JSON file if available
	 * Checks for whitelabel-settings.json in the plugin directory or extracted zip
	 *
	 * @return bool Whether the complete import succeeded.
	 * @since 2.5.0
	 */
	private static function import_whitelabel_settings()
	{
		$json_file = self::get_whitelabel_settings_file();

		if ($json_file === false) {
			return false;
		}

		// Read JSON file
		$json_content = file_get_contents($json_file);
		if ($json_content === false) {
			return false;
		}

		// Decode JSON
		$import_data = json_decode($json_content, true);
		if ($import_data === null || json_last_error() !== JSON_ERROR_NONE) {
			return false;
		}

		// Validate import data structure
		if (!isset($import_data['whitelabel_settings']) || !is_array($import_data['whitelabel_settings'])) {
			return false;
		}

		$plugin_file = plugin_dir_path(dirname(__FILE__)) . 'metasync.php';
		$original_plugin_content = file_get_contents($plugin_file);
		if ($original_plugin_content === false) {
			return false;
		}

		// Get current options
		$options = get_option('metasync_options', array());

		// Import whitelabel settings
		if (isset($import_data['whitelabel_settings'])) {
			$whitelabel_settings = $import_data['whitelabel_settings'];

			// Initialize whitelabel array if needed
			if (!isset($options['whitelabel'])) {
				$options['whitelabel'] = array();
			}

			// Merge imported settings with existing (imported settings take precedence)
			$options['whitelabel'] = array_merge($options['whitelabel'], $whitelabel_settings);

			// The settings password travels as plaintext in the export file so it
			// can be imported on a site with different salts — encrypt it at rest.
			if (!empty($options['whitelabel']['settings_password']) && is_string($options['whitelabel']['settings_password'])) {
				$options['whitelabel']['settings_password'] = Metasync::encrypt_secret($options['whitelabel']['settings_password']);
			}

			// Update timestamp
			$options['whitelabel']['updated_at'] = time();
			$options['whitelabel']['imported_at'] = current_time('mysql');
		}

		// Import general settings related to whitelabel
		if (isset($import_data['general_settings']) && is_array($import_data['general_settings'])) {
			if (!isset($options['general'])) {
				$options['general'] = array();
			}

			// Merge general settings
			foreach ($import_data['general_settings'] as $key => $value) {
				$options['general'][$key] = $value;
			}
		}

		// Validate and persist the public plugin headers before any other import side effect.
		$header_data = array(
			'general_settings' => $options['general'] ?? array(),
		);
		if (!self::update_plugin_file_headers($header_data, $plugin_file)) {
			return false;
		}

		// Restore bundled icon: if the icon value is a __bundled_icon__{ext} marker,
		// copy the bundled file from the plugin directory to uploads and update the URL.
		$icon_value = $options['general']['white_label_plugin_menu_icon'] ?? '';
		if (!empty($icon_value) && strpos($icon_value, '__bundled_icon__') === 0) {
			$ext             = substr($icon_value, strlen('__bundled_icon__'));
			$ext             = preg_replace('/[^a-z0-9]/', '', strtolower($ext)); // sanitize
			$bundled_file    = plugin_dir_path(dirname(__FILE__)) . 'whitelabel-icon.' . $ext;

			if (file_exists($bundled_file) && in_array($ext, ['png', 'svg'], true)) {
				$upload_dir  = wp_upload_dir();
				$dest_dir    = $upload_dir['basedir'] . '/metasync';
				if (!file_exists($dest_dir)) {
					wp_mkdir_p($dest_dir);
				}
				$dest_file = $dest_dir . '/whitelabel-icon.' . $ext;
				if (copy($bundled_file, $dest_file)) {
					$options['general']['white_label_plugin_menu_icon'] = $upload_dir['baseurl'] . '/metasync/whitelabel-icon.' . $ext;
				} else {
					// Could not copy — clear the broken marker so default icon shows
					$options['general']['white_label_plugin_menu_icon'] = '';
				}
			} else {
				// Bundled file missing or unsupported extension — clear the marker
				$options['general']['white_label_plugin_menu_icon'] = '';
			}
		}

		// Save updated options
		update_option('metasync_options', $options);
		if (get_option('metasync_options', null) !== $options) {
			if (self::atomically_replace_plugin_file($plugin_file, $original_plugin_content)) {
				wp_cache_delete('plugins', 'plugins');
			}
			return false;
		}

		// Optionally delete the JSON file after successful import (uncomment if desired)
		// unlink($json_file);

		return true;
	}

	/**
	 * Sync plugin file headers from the current saved options in the database.
	 * Call this after saving whitelabel settings via the admin UI to ensure
	 * the plugin file headers reflect the latest whitelabel values.
	 *
	 * @since 2.5.0
	 */
	public static function sync_plugin_file_headers()
	{
		if (!current_user_can('manage_options')) {
			return false;
		}

		$options = get_option('metasync_options', array());
		$general = $options['general'] ?? array();

		// Build the import_data format expected by update_plugin_file_headers
		$import_data = array(
			'general_settings' => $general,
		);

		return self::update_plugin_file_headers($import_data);
	}

	/**
	 * Update the main plugin file headers with whitelabel values
	 * WordPress reads plugin metadata directly from the file header comments,
	 * so modifying these ensures whitelabel shows even when the plugin is deactivated.
	 *
	 * @param array $import_data The imported whitelabel data
	 * @since 2.5.0
	 */
	private static function update_plugin_file_headers($import_data, $plugin_file = null)
	{
		$plugin_file = $plugin_file ?: plugin_dir_path(dirname(__FILE__)) . 'metasync.php';

		if (!file_exists($plugin_file) || !is_writable($plugin_file)) {
			return false;
		}

		$content = file_get_contents($plugin_file);
		if ($content === false) {
			return false;
		}

		$general = $import_data['general_settings'] ?? array();

		// Map of whitelabel setting keys to plugin header field names
		// with default values to restore when whitelabel is cleared
		$header_map = array(
			'white_label_plugin_name'        => array(
				'header'  => 'Plugin Name',
				'default' => 'Search Atlas: The Premier AI SEO Plugin for Instant Optimization',
			),
			'white_label_plugin_description'  => array(
				'header'  => 'Description',
				'default' => 'Search Atlas SEO is an intuitive WordPress Plugin that transforms the most complicated, most labor-intensive SEO tasks into streamlined, straightforward processes. With a few clicks, the meta-bulk update feature automates the re-optimization of meta tags using AI to increase clicks. Stay up-to-date with the freshest Google Search data for your entire site or targeted URLs within the Meta Sync plug-in page.',
			),
			'white_label_plugin_author'       => array(
				'header'  => 'Author',
				'default' => 'Search Atlas',
			),
			'white_label_plugin_author_uri'   => array(
				'header'  => 'Author URI',
				'default' => 'https://searchatlas.com',
			),
			'white_label_plugin_uri'          => array(
				'header'  => 'Plugin URI',
				'default' => 'https://searchatlas.com/',
			),
		);

		$modified = false;
		$original_content = $content;
		$url_fields = array('white_label_plugin_author_uri', 'white_label_plugin_uri');

		foreach ($header_map as $setting_key => $field_config) {
			$header_field = $field_config['header'];

			// Use whitelabel value if set, otherwise restore default
			$new_value = !empty($general[$setting_key])
				? $general[$setting_key]
				: $field_config['default'];

			if (!is_string($new_value) || !self::is_valid_plugin_header_value($new_value, in_array($setting_key, $url_fields, true))) {
				return false;
			}

			// Match the header line: " * Field Name:       any value"
			// Handles varying whitespace between field name and value
			$pattern = '/^(\s*\*\s*' . preg_quote($header_field, '/') . ':\s*)(.+)$/m';

			if (preg_match_all($pattern, $content, $matches) !== 1) {
				return false;
			}

			// Only replace if the value actually differs from what's in the file.
			if (trim($matches[2][0]) !== trim($new_value)) {
				$content = preg_replace_callback(
					$pattern,
					static function ($match) use ($new_value) {
						return $match[1] . $new_value;
					},
					$content,
					1,
					$replacement_count
				);

				if (!is_string($content) || $replacement_count !== 1) {
					return false;
				}
				$modified = true;
			}
		}

		if (!self::is_valid_transformed_plugin_file($original_content, $content, $header_map)) {
			return false;
		}

		if (!$modified) {
			return true;
		}

		if (!self::atomically_replace_plugin_file($plugin_file, $content)) {
			return false;
		}

		// Clear WordPress plugin cache so it reads the updated headers.
		wp_cache_delete('plugins', 'plugins');

		return true;
	}

	/**
	 * Validate a value before placing it inside the plugin's PHP docblock.
	 */
	private static function is_valid_plugin_header_value($value, $is_url)
	{
		if (
			$value === ''
			|| preg_match('/^[\p{L}\p{M}\p{N}\p{P}\p{S}\p{Zs}]+$/u', $value) !== 1
			|| preg_match('/\*\/|<\?php|\?>/i', $value) !== 0
		) {
			return false;
		}

		if (!$is_url) {
			return true;
		}

		if (filter_var($value, FILTER_VALIDATE_URL) === false) {
			return false;
		}

		$scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

		return in_array($scheme, array('http', 'https'), true);
	}

	/**
	 * Validate the complete transformed PHP file and its header structure.
	 */
	private static function is_valid_transformed_plugin_file($original, $transformed, $header_map)
	{
		if (!is_string($transformed) || strpos($transformed, '<?php') !== 0) {
			return false;
		}

		if (
			substr_count($original, '<?php') !== substr_count($transformed, '<?php')
			|| substr_count($original, '?>') !== substr_count($transformed, '?>')
		) {
			return false;
		}

		foreach ($header_map as $field_config) {
			$pattern = '/^\s*\*\s*' . preg_quote($field_config['header'], '/') . ':\s*.+$/m';
			if (preg_match_all($pattern, $transformed) !== 1) {
				return false;
			}
		}

		try {
			$tokens = token_get_all($transformed, TOKEN_PARSE);
		} catch (ParseError $error) {
			return false;
		}

		return true;
	}

	/**
	 * Replace a plugin file from a same-directory temporary file.
	 */
	private static function atomically_replace_plugin_file($plugin_file, $content, $rename_file = null)
	{
		$directory = dirname($plugin_file);
		$temp_file = tempnam($directory, '.metasync-header-');
		if ($temp_file === false) {
			return false;
		}

		$permissions = fileperms($plugin_file);
		$written = file_put_contents($temp_file, $content, LOCK_EX);
		if ($written !== strlen($content)) {
			@unlink($temp_file);
			return false;
		}

		if ($permissions !== false) {
			@chmod($temp_file, $permissions & 0777);
		}

		if ($rename_file === null) {
			$rename_file = static function ($source, $destination) {
				return @rename($source, $destination);
			};
		}

		// The temporary file is on the same filesystem, so a successful rename is
		// atomic. On failure the original path has not been touched.
		if ($rename_file($temp_file, $plugin_file)) {
			return true;
		}

		@unlink($temp_file);

		return false;
	}
}

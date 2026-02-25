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
		self::check_whitelabel_settings_update();

		// Pre-SSO announce: tell backend plugin is installed (PR4 - heartbeat reliability)
		self::send_announce_ping();

		// Set first activation flag for setup wizard
		if (!get_option('metasync_first_activation_time')) {
			update_option('metasync_first_activation_time', current_time('mysql'));
			update_option('metasync_show_wizard', true);
		}

		flush_rewrite_rules();
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
	 * @since 2.5.0
	 */
	public static function check_whitelabel_settings_update()
	{
		$json_file = self::get_whitelabel_settings_file();

		if ($json_file === false) {
			return;
		}

		// Get current file modification time and content hash
		$file_mtime = filemtime($json_file);
		$file_hash = md5_file($json_file);

		// Get stored file info
		$stored_mtime = get_option('metasync_whitelabel_file_mtime', 0);
		$stored_hash = get_option('metasync_whitelabel_file_hash', '');

		// Check if file has changed (either modification time or content)
		if ($file_mtime > $stored_mtime || $file_hash !== $stored_hash) {
			// File has changed, import settings
			self::import_whitelabel_settings();

			// Store new file info
			update_option('metasync_whitelabel_file_mtime', $file_mtime);
			update_option('metasync_whitelabel_file_hash', $file_hash);
		}
	}

	/**
	 * Import whitelabel settings from JSON file if available
	 * Checks for whitelabel-settings.json in the plugin directory or extracted zip
	 *
	 * This method is public to allow calling during both activation and plugin updates.
	 * @since 2.5.0
	 */
	public static function import_whitelabel_settings()
	{
		$json_file = self::get_whitelabel_settings_file();

		if ($json_file === false) {
			return;
		}

		// Read JSON file
		$json_content = file_get_contents($json_file);
		if ($json_content === false) {
			return;
		}

		// Decode JSON
		$import_data = json_decode($json_content, true);
		if ($import_data === null || json_last_error() !== JSON_ERROR_NONE) {
			return;
		}

		// Validate import data structure
		if (!isset($import_data['whitelabel_settings']) || !is_array($import_data['whitelabel_settings'])) {
			return;
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

		// Save updated options
		update_option('metasync_options', $options);

		// Update the plugin file headers so whitelabel shows even when deactivated
		self::update_plugin_file_headers($import_data);

		// Optionally delete the JSON file after successful import (uncomment if desired)
		// unlink($json_file);
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
		$options = get_option('metasync_options', array());
		$general = $options['general'] ?? array();

		// Build the import_data format expected by update_plugin_file_headers
		$import_data = array(
			'general_settings' => $general,
		);

		self::update_plugin_file_headers($import_data);
	}

	/**
	 * Update the main plugin file headers with whitelabel values
	 * WordPress reads plugin metadata directly from the file header comments,
	 * so modifying these ensures whitelabel shows even when the plugin is deactivated.
	 *
	 * @param array $import_data The imported whitelabel data
	 * @since 2.5.0
	 */
	private static function update_plugin_file_headers($import_data)
	{
		$plugin_file = plugin_dir_path(dirname(__FILE__)) . 'metasync.php';

		if (!file_exists($plugin_file) || !is_writable($plugin_file)) {
			return;
		}

		$content = file_get_contents($plugin_file);
		if ($content === false) {
			return;
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

		foreach ($header_map as $setting_key => $field_config) {
			$header_field = $field_config['header'];

			// Use whitelabel value if set, otherwise restore default
			$new_value = !empty($general[$setting_key])
				? $general[$setting_key]
				: $field_config['default'];

			// Match the header line: " * Field Name:       any value"
			// Handles varying whitespace between field name and value
			$pattern = '/^(\s*\*\s*' . preg_quote($header_field, '/') . ':\s*)(.+)$/m';

			if (preg_match($pattern, $content, $matches)) {
				// Only replace if the value actually differs from what's in the file
				if (trim($matches[2]) !== trim($new_value)) {
					// Escape both backslashes and $ signs for preg_replace replacement string
					$escaped_value = str_replace(array('\\', '$'), array('\\\\', '\\$'), $new_value);
					$content = preg_replace($pattern, '${1}' . $escaped_value, $content, 1);
					$modified = true;
				}
			}
		}

		if ($modified) {
			file_put_contents($plugin_file, $content);

			// Clear WordPress plugin cache so it reads the updated headers
			wp_cache_delete('plugins', 'plugins');
		}
	}
}

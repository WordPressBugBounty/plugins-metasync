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

		// Import whitelabel settings if available
		self::import_whitelabel_settings();

		flush_rewrite_rules();
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
	 * Import whitelabel settings from JSON file if available
	 * Checks for whitelabel-settings.json in the plugin directory or extracted zip
	 */
	private static function import_whitelabel_settings()
	{
		$plugin_dir = plugin_dir_path(dirname(__FILE__));
		
		// Check for whitelabel-settings.json in plugin root
		$json_file = $plugin_dir . 'whitelabel-settings.json';
		
		// Also check in a common extracted zip location (if zip was extracted)
		if (!file_exists($json_file)) {
			$json_file = $plugin_dir . 'metasync/whitelabel-settings.json';
		}
		
		if (!file_exists($json_file)) {
			// No whitelabel settings file found, skip import
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
		
		// Optionally delete the JSON file after successful import (uncomment if desired)
		// unlink($json_file);
	}
}

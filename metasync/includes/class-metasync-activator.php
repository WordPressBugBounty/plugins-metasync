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
			
			error_log('Plugin Activation: Generated new Plugin Auth Token: ' . substr($plugin_auth_token, 0, 8) . '...');
		} else {
			error_log('Plugin Activation: Plugin Auth Token already exists: ' . substr($options['general']['apikey'], 0, 8) . '...');
		}
	}
}

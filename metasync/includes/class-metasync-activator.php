<?php

/**
 * Fired during plugin activation
 *
 * @link       http://linkgraph.io
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
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
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
		flush_rewrite_rules();
	}
}

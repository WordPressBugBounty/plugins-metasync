<?php

/**
 * Fired during plugin deactivation
 *
 * @link       http://linkgraph.io
 * @since      1.0.0
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/includes
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
 */
class Metasync_Deactivator
{

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate()
	{
		// add_filter('wp_sitemaps_enabled', '__return_true');
		//delete_option( "metasync_options" );
		//delete_option( "metasync_options_instant_indexing" );
		flush_rewrite_rules();
	}
}

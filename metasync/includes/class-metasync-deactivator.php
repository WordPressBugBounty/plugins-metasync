<?php

/**
 * Fired during plugin deactivation
 *
 * @link       https://searchatlas.com
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
 * @author     Engineering Team <support@searchatlas.com>
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

		// Clean up announce ping counter and cron
		delete_option('metasync_announce_attempt_count');
		$timestamp = wp_next_scheduled('metasync_announce_cron');
		if ($timestamp) {
			wp_unschedule_event($timestamp, 'metasync_announce_cron');
		}

		flush_rewrite_rules();
	}
}

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

		// Clean up announce ping counter
		delete_option('metasync_announce_attempt_count');

		// Unschedule every MetaSync cron hook so no orphaned events remain after deactivation
		foreach (Metasync_Activator::$cron_hooks as $hook) {
			wp_unschedule_hook($hook);
		}

		// Soft flush only (no .htaccess rewrite). A hard flush calls
		// save_mod_rewrite_rules(), which opens the site's root .htaccess with an
		// exclusive flock(). On hosts whose cache/optimizer layer also holds that
		// lock (e.g. SiteGround + SG Optimizer), the deactivation request blocks on
		// the lock until max_execution_time and the host returns a 500 — so the
		// plugin never deactivates. MetaSync registers its rewrite rules via
		// add_rewrite_rule (stored in the `rewrite_rules` option, NOT in .htaccess),
		// so a soft flush fully clears them without ever touching the file.
		flush_rewrite_rules(false);

		// Reset WP-299 one-time cleanup flag so it re-runs on next activation/update
		delete_option('metasync_wp299_cron_cleanup_done');
	}
}

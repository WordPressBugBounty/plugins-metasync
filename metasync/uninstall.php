<?php

/**
 * Fired when the plugin is uninstalled.
 *
 * Cleanup policy:
 *
 * - Credentials (the Google Indexing service account — a plaintext private
 *   key — and the IndexNow API key) are ALWAYS deleted. They must never be
 *   left behind in wp_options by a plugin that is no longer installed.
 * - Everything else (custom tables, options, transients, per-post SEO meta,
 *   log files) is only removed when the site owner opted in via the
 *   "Delete all plugin data when the plugin is uninstalled" toggle in
 *   Advanced Settings. The default is OFF, per WordPress uninstall guidelines.
 *
 * The opt-in option is read BEFORE any options are deleted, because the
 * option itself is plugin data.
 *
 * For multisite, the cleanup runs once per site when the plugin is
 * network-activated and removed.
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 *
 * @package    Metasync
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove plugin data for the current site.
 *
 * @return void
 */
function metasync_uninstall_cleanup() {
	global $wpdb;

	// Read the opt-in first: the option itself is plugin data and is removed
	// by the prefix sweep below.
	$delete_all_data = ( 'yes' === get_option( 'metasync_delete_data_on_uninstall', 'no' ) );

	// Credentials are always removed, regardless of the opt-in.
	delete_option( 'google_index_service_account' );
	delete_option( 'metasync_options_bing_instant_indexing' );
	// Legacy instant-indexing option: holds the Google service-account JSON key.
	delete_option( 'metasync_options_instant_indexing' );

	if ( ! $delete_all_data ) {
		return;
	}

	// Scheduled events.
	$cron_hooks = array(
		'metasync_daily_cleanup',
		'metasync_otto_js_check_event',
		'metasync_process_otto_batch_cache_job',
		'metasync_process_otto_crawl_url_job',
		'metasync_sitemap_async_warmup_event',
		'metasync_media_batch_optimize_cron',
		'metasync_check_debug_limits',
		'metasync_speed_cache_cleanup',
		'metasync_bing_indexnow_submit_event',
	);
	foreach ( $cron_hooks as $cron_hook ) {
		wp_clear_scheduled_hook( $cron_hook );
	}

	// Custom tables.
	$tables = array(
		'metasync_404_logs',
		'metasync_redirections',
		'metasync_heartbeat_error_logs',
		'metasync_sync_history',
		'metasync_otto_excluded_urls',
		'metasync_robots_txt_backups',
		'metasync_otto_bot_stats',
		'metasync_otto_bot_logs',
	);
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table names, no user input.
	}

	// Plugin options and transients (transients live in the options table
	// under _transient_/_transient_timeout_ prefixes when no object cache
	// persists them; object-cache-backed transients age out on their own).
	$option_patterns = array(
		'metasync\_%',
		'\_transient\_metasync\_%',
		'\_transient\_timeout\_metasync\_%',
		'\_site\_transient\_metasync\_%',
		'\_site\_transient\_timeout\_metasync\_%',
	);
	foreach ( $option_patterns as $option_pattern ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $option_pattern ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from core.
	}

	// Per-post SEO values saved by the plugin (custom titles, meta
	// descriptions, robots directives, schema, Open Graph, canonical URLs).
	// Several metaboxes store their values without the leading underscore
	// (metasync_common_robots, metasync_schema_markup, metasync_advance_robots,
	// metasync_post, metasync_post_redirection_meta), so both prefixes are
	// swept.
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_metasync\_%' OR meta_key LIKE 'metasync\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- literal patterns, no user input.

	// On-disk data directory (error logs, zipped logs, cache artifacts).
	$metasync_data_dir = WP_CONTENT_DIR . '/metasync_data';
	if ( is_dir( $metasync_data_dir ) ) {
		metasync_uninstall_rrmdir( $metasync_data_dir );
	}
}

/**
 * Recursively delete a directory.
 *
 * @param string $dir Absolute directory path.
 * @return void
 */
function metasync_uninstall_rrmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) ) {
			metasync_uninstall_rrmdir( $path );
		} else {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup on uninstall.
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup on uninstall.
}

if ( is_multisite() ) {
	$metasync_uninstall_site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $metasync_uninstall_site_ids as $metasync_uninstall_site_id ) {
		switch_to_blog( $metasync_uninstall_site_id );
		metasync_uninstall_cleanup();
	}
	restore_current_blog();
	unset( $metasync_uninstall_site_ids, $metasync_uninstall_site_id );
} else {
	metasync_uninstall_cleanup();
}

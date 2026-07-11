<?php
/**
 * MetaSync — Runtime Feature Initialisers
 *
 * GA4 analytics, API backoff, review notice, the global metasync_get_jwt_token()
 * accessor, and the debug mode manager. Extracted from metasync.php (WP-530) to
 * keep the plugin entry file a lean bootstrap. Loaded via require_once from
 * metasync.php; each initialiser registers its own hook. Function names and hook
 * timing are unchanged.
 *
 * @package Search Atlas SEO
 */

if (!defined('WPINC')) {
	die;
}

/**
 * Initialize GA4 Analytics for Admin Area
 * Hooked to admin_init for proper WordPress lifecycle integration
 * Only loads after WordPress, plugins, and themes are fully loaded
 */
function metasync_init_analytics() {
	// Only initialize in admin area (excludes AJAX and REST API requests)
	if (is_admin() && !wp_doing_ajax() && !defined('REST_REQUEST')) {
		Metasync_GA4::get_instance();
	}
}
add_action('admin_init', 'metasync_init_analytics', 10);

/**
 * Initialize API Backoff System
 * Hooked to init for proper WordPress lifecycle integration
 * Monitors API responses and manages exponential backoff for rate limiting
 * @since 2.7.1
 */
function metasync_init_api_backoff() {
	if (metasync_is_non_metasync_admin_ajax()) {
		return;
	}
	// Initialize backoff manager (registers HTTP response filters)
	Metasync_API_Backoff_Manager::get_instance();

	// Initialize admin notices (only in admin area)
	if (is_admin()) {
		Metasync_API_Backoff_Notices::get_instance();
	}
}
add_action('init', 'metasync_init_api_backoff', 5);

/**
 * Initialize Review Notice
 * Shows a dismissible notice asking users to rate the plugin after a usage period
 * @since 2.8.0
 */
function metasync_init_review_notice() {
	if (metasync_is_non_metasync_admin_ajax()) {
		return;
	}
	if (is_admin()) {
		Metasync_Review_Notice::get_instance();
	}
}
add_action('init', 'metasync_init_review_notice');

/**
 * Global convenience function to get active JWT token
 * Can be called from anywhere in WordPress (themes, other plugins, etc.)
 * 
 * @param bool $force_refresh Force generation of new token even if cached one exists
 * @return string|false JWT token on success, false on failure
 */
if (!function_exists('metasync_get_jwt_token')) {
	function metasync_get_jwt_token($force_refresh = false)
	{
		return Metasync::get_jwt_token($force_refresh);
	}
}


/**
 * Initialize Debug Mode Manager
 * Hooked to 'init' for proper WordPress lifecycle integration
 */
function metasync_init_debug_mode_manager() {
	if (metasync_is_non_metasync_admin_ajax()) {
		return;
	}
	// Initialize the Debug Mode Manager singleton
	Metasync_Debug_Mode_Manager::get_instance();
}
add_action('init', 'metasync_init_debug_mode_manager', 10);

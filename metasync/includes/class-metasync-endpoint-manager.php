<?php
/**
 * Endpoint Manager for MetaSync Plugin
 *
 * Manages switching between production and staging endpoints for development and testing.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */

/**
 * The endpoint manager class.
 *
 * Provides centralized management of all SearchAtlas API endpoints with support
 * for switching between production and staging environments.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */
class Metasync_Endpoint_Manager {

	/**
	 * Option name for storing the current endpoint mode.
	 */
	const MODE_OPTION = 'metasync_dev_endpoint_mode';

	/**
	 * Valid endpoint modes.
	 */
	const MODE_PRODUCTION = 'production';
	const MODE_STAGING = 'staging';

	/**
	 * Endpoint mapping for production and staging environments.
	 *
	 * @var array
	 */
	private static $endpoints = array(
		'production' => array(
			'DASHBOARD_DOMAIN'    => 'https://dashboard.searchatlas.com',
			'API_DOMAIN'          => 'https://api.searchatlas.com',
			'CA_API_DOMAIN'       => 'https://ca.searchatlas.com',
			'OTTO_API_DOMAIN'     => 'https://sa.searchatlas.com',
			'OTTO_URL_DETAILS'    => 'https://sa.searchatlas.com/api/v2/otto-url-details',
			'OTTO_CRAWL_LOGS'     => 'https://sa.searchatlas.com/api/v2/otto-page-crawl-logs',
			'OTTO_PROJECTS'       => 'https://sa.searchatlas.com/api/v2/otto-projects',
		),
		'staging' => array(
			'DASHBOARD_DOMAIN'    => 'https://dashboard.staging.searchatlas.com',
			'API_DOMAIN'          => 'https://api.staging.searchatlas.com',
			'CA_API_DOMAIN'       => 'https://ca.staging.searchatlas.com',
			'OTTO_API_DOMAIN'     => 'https://sa.staging.searchatlas.com',
			'OTTO_URL_DETAILS'    => 'https://sa.staging.searchatlas.com/api/v2/otto-url-details',
			'OTTO_CRAWL_LOGS'     => 'https://sa.staging.searchatlas.com/api/v2/otto-page-crawl-logs',
			'OTTO_PROJECTS'       => 'https://sa.staging.searchatlas.com/api/v2/otto-projects',
		),
	);

	/**
	 * Get the current endpoint mode.
	 *
	 * @return string Either 'production' or 'staging'.
	 */
	public static function get_mode() {
		$mode = get_option( self::MODE_OPTION, self::MODE_PRODUCTION );

		// Validate mode
		if ( ! in_array( $mode, array( self::MODE_PRODUCTION, self::MODE_STAGING ), true ) ) {
			$mode = self::MODE_PRODUCTION;
		}

		return $mode;
	}

	/**
	 * Set the endpoint mode.
	 *
	 * @param string $mode The mode to set ('production' or 'staging').
	 * @return bool True on success, false on failure.
	 */
	public static function set_mode( $mode ) {
		// Validate mode
		if ( ! in_array( $mode, array( self::MODE_PRODUCTION, self::MODE_STAGING ), true ) ) {
			error_log( "MetaSync Endpoint Manager: Invalid mode '{$mode}' provided" );
			return false;
		}

		$result = update_option( self::MODE_OPTION, $mode );

		if ( $result ) {
			error_log( "MetaSync Endpoint Manager: Endpoints switched to {$mode} mode by user " . get_current_user_id() );
		}

		return $result;
	}

	/**
	 * Get a specific endpoint URL by key.
	 *
	 * @param string $endpoint_key The endpoint key (e.g., 'OTTO_URL_DETAILS').
	 * @return string The endpoint URL, or empty string if not found.
	 */
	public static function get_endpoint( $endpoint_key ) {
		$mode = self::get_mode();

		if ( ! isset( self::$endpoints[ $mode ][ $endpoint_key ] ) ) {
			error_log( "MetaSync Endpoint Manager: Unknown endpoint key '{$endpoint_key}'" );

			// Fallback to production if key exists there
			if ( isset( self::$endpoints[ self::MODE_PRODUCTION ][ $endpoint_key ] ) ) {
				return self::$endpoints[ self::MODE_PRODUCTION ][ $endpoint_key ];
			}

			return '';
		}

		return self::$endpoints[ $mode ][ $endpoint_key ];
	}

	/**
	 * Get all endpoints for the current mode.
	 *
	 * @return array Associative array of endpoint keys and URLs.
	 */
	public static function get_all_endpoints() {
		$mode = self::get_mode();
		return self::$endpoints[ $mode ];
	}

	/**
	 * Check if staging mode is currently active.
	 *
	 * @return bool True if staging mode is active, false otherwise.
	 */
	public static function is_staging_mode() {
		return self::get_mode() === self::MODE_STAGING;
	}

	/**
	 * Get all available modes.
	 *
	 * @return array Array of available modes.
	 */
	public static function get_available_modes() {
		return array( self::MODE_PRODUCTION, self::MODE_STAGING );
	}
}

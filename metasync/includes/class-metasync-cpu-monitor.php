<?php
/**
 * CPU Load Monitoring and Deferral Logic
 *
 * Provides utility methods to detect CPU load and defer batch processing
 * when the server is under high load, improving performance during peak traffic.
 *
 * @package Metasync
 */

class Metasync_CPU_Monitor {
	/**
	 * Option key for persistent statistics
	 */
	const STATS_OPTION_KEY = 'metasync_cpu_stats';

	/**
	 * Transient key for deferral notices
	 */
	const DEFER_NOTICE_TRANSIENT = 'metasync_cpu_deferral_notice';

	/**
	 * Default CPU load threshold (per core)
	 */
	const DEFAULT_PER_CORE = 2.0;

	/**
	 * Check if sys_getloadavg() function is available
	 *
	 * @return bool True if function exists, false on Windows or if unavailable
	 */
	public static function is_available() {
		return function_exists( 'sys_getloadavg' );
	}

	/**
	 * Get the current 1-minute load average
	 *
	 * Wraps sys_getloadavg() with a filter hook for testing purposes.
	 *
	 * @return float|false Load average or false on error
	 */
	public static function get_load_average() {
		if ( ! self::is_available() ) {
			return false;
		}

		$raw = sys_getloadavg();
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return false;
		}

		// Apply filter for testing purposes (allows mocking high load)
		$load = apply_filters( 'metasync_cpu_load_average', $raw[0] );

		return is_numeric( $load ) ? (float) $load : false;
	}

	/**
	 * Detect number of CPU cores on the system
	 *
	 * Uses multiple fallback methods to detect CPU cores across platforms:
	 * 1. Linux: Parse /proc/cpuinfo
	 * 2. Linux/macOS: shell_exec('nproc')
	 * 3. macOS: shell_exec('sysctl -n hw.ncpu')
	 * 4. Default: 1 (safe fallback)
	 *
	 * @return int Number of CPU cores (minimum 1)
	 */
	public static function get_cpu_core_count() {
		// Try Linux: count processor lines in /proc/cpuinfo
		// Use @ to suppress open_basedir warnings on restricted shared hosting
		if ( @is_readable( '/proc/cpuinfo' ) ) {
			$cpuinfo = file_get_contents( '/proc/cpuinfo' );
			if ( $cpuinfo !== false ) {
				$count = substr_count( $cpuinfo, 'processor' );
				if ( $count > 0 ) {
					return (int) $count;
				}
			}
		}

		// Try shell_exec: nproc (Linux, some Unix)
		if ( function_exists( 'shell_exec' ) && ! in_array( 'shell_exec', explode( ',', ini_get( 'disable_functions' ) ), true ) ) {
			// Try nproc
			$nproc = intval( @shell_exec( 'nproc 2>/dev/null' ) );
			if ( $nproc > 0 ) {
				return $nproc;
			}

			// Try sysctl (macOS)
			$sysctl = intval( @shell_exec( 'sysctl -n hw.ncpu 2>/dev/null' ) );
			if ( $sysctl > 0 ) {
				return $sysctl;
			}
		}

		// Safe fallback
		return 1;
	}

	/**
	 * Get the configured per-core load threshold
	 *
	 * @return float Per-core threshold (default 2.0)
	 */
	public static function get_per_core_threshold() {
		return (float) ( Metasync::get_option( 'performance' )['cpu_load_per_core_threshold'] ?? self::DEFAULT_PER_CORE );
	}

	/**
	 * Get the effective load threshold for this system
	 *
	 * Calculated as: number_of_cores × per_core_threshold
	 *
	 * @return float Effective threshold
	 */
	public static function get_effective_threshold() {
		return (float) self::get_cpu_core_count() * self::get_per_core_threshold();
	}

	/**
	 * Check if it's safe to process (CPU load is below threshold)
	 *
	 * Main guard method called before batch processing operations.
	 * Returns true to proceed, false to defer. On failure, calls record_deferral().
	 *
	 * @return bool True if safe to process, false if deferred
	 */
	public static function is_load_safe() {
		// Skip check if sys_getloadavg() is unavailable (Windows)
		if ( ! self::is_available() ) {
			return true;
		}

		$load = self::get_load_average();
		if ( $load === false ) {
			return true; // Error: assume safe
		}

		$threshold = self::get_effective_threshold();
		if ( $load > $threshold ) {
			self::record_deferral( $load );
			return false;
		}

		return true;
	}

	/**
	 * Record a deferral event and update statistics
	 *
	 * Updates persistent stats (deferrals count, max load, running average).
	 * Sets a transient notice for display to admins (5-minute TTL).
	 *
	 * @param float $load The current load average
	 */
	public static function record_deferral( $load ) {
		$stats = get_option( self::STATS_OPTION_KEY, array(
			'deferrals'   => 0,
			'max_load'    => 0.0,
			'total_load'  => 0.0,
			'sample_count' => 0,
		) );

		// Increment deferral counter
		$stats['deferrals']++;

		// Track maximum load observed
		$stats['max_load'] = max( (float) $stats['max_load'], $load );

		// Update running sum for average calculation
		$stats['total_load'] += $load;
		$stats['sample_count']++;

		// Store updated stats (autoload=false to reduce options table bloat)
		update_option( self::STATS_OPTION_KEY, $stats, false );

		// Set admin notice transient (5-minute TTL per requirements)
		set_transient( self::DEFER_NOTICE_TRANSIENT, array(
			'load'      => round( $load, 2 ),
			'threshold' => round( self::get_effective_threshold(), 2 ),
			'cores'     => self::get_cpu_core_count(),
			'time'      => time(),
		), 300 );
	}

	/**
	 * Get current CPU statistics
	 *
	 * @return array Statistics including deferrals, max_load, avg_load, sample_count
	 */
	public static function get_stats() {
		$stats = get_option( self::STATS_OPTION_KEY, array(
			'deferrals'   => 0,
			'max_load'    => 0.0,
			'total_load'  => 0.0,
			'sample_count' => 0,
		) );

		// Calculate average load
		$avg_load = $stats['sample_count'] > 0 ? $stats['total_load'] / $stats['sample_count'] : 0.0;

		return array(
			'deferrals'   => (int) $stats['deferrals'],
			'max_load'    => (float) $stats['max_load'],
			'avg_load'    => round( $avg_load, 2 ),
			'sample_count' => (int) $stats['sample_count'],
		);
	}

	/**
	 * Reset CPU statistics to default values
	 */
	public static function reset_stats() {
		delete_option( self::STATS_OPTION_KEY );
	}
}

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
	 * Cached core-detection result for the current request.
	 *
	 * int   = detected core count
	 * false = every detection method failed (restricted host)
	 * null  = not probed yet
	 *
	 * @var int|false|null
	 */
	private static $detected_cores = null;

	/**
	 * Probe the system for its CPU core count
	 *
	 * Detection methods, in order:
	 * 1. Linux: Parse /proc/cpuinfo
	 * 2. Linux/some Unix: shell_exec('nproc')
	 * 3. macOS: shell_exec('sysctl -n hw.ncpu')
	 *
	 * Returns false when every method fails — common on managed/shared
	 * hosting where open_basedir blocks /proc/cpuinfo and shell_exec is
	 * disabled. Callers must treat false as "unknown", NOT as 1 core:
	 * sys_getloadavg() still reports the WHOLE machine's load on such
	 * hosts, so pairing it with an assumed 1-core threshold wrongly flags
	 * large, healthy shared servers as overloaded (WP-547).
	 *
	 * @return int|false Core count, or false when detection failed
	 */
	public static function detect_cpu_core_count() {
		if ( self::$detected_cores !== null ) {
			return self::$detected_cores;
		}

		$detected = false;

		// Try Linux: count processor lines in /proc/cpuinfo
		// Use @ to suppress open_basedir warnings on restricted shared hosting
		if ( @is_readable( '/proc/cpuinfo' ) ) {
			$cpuinfo = @file_get_contents( '/proc/cpuinfo' );
			if ( $cpuinfo !== false ) {
				$count = substr_count( $cpuinfo, 'processor' );
				if ( $count > 0 ) {
					$detected = (int) $count;
				}
			}
		}

		// Try shell_exec: nproc (Linux, some Unix), then sysctl (macOS)
		if ( $detected === false && function_exists( 'shell_exec' ) && ! in_array( 'shell_exec', explode( ',', ini_get( 'disable_functions' ) ), true ) ) {
			$nproc = intval( @shell_exec( 'nproc 2>/dev/null' ) );
			if ( $nproc > 0 ) {
				$detected = $nproc;
			} else {
				$sysctl = intval( @shell_exec( 'sysctl -n hw.ncpu 2>/dev/null' ) );
				if ( $sysctl > 0 ) {
					$detected = $sysctl;
				}
			}
		}

		/**
		 * Filter the detected CPU core count.
		 *
		 * Lets hosts and tests override detection: return a positive int to
		 * force a core count, or false to mark detection as failed.
		 *
		 * @param int|false $detected Detected core count, or false when unknown.
		 */
		$filtered = apply_filters( 'metasync_cpu_detected_cores', $detected );

		// Defend the int|false contract: anything that is not a positive
		// number counts as failed detection. This keeps the cache from being
		// reset to null (which would defeat it and re-run shell_exec probes
		// on every call) and stops a bogus 0/negative/garbage filter return
		// from silently recreating the 1-core/2.0-threshold gate this class
		// must avoid (WP-547) while reporting detection as "reliable".
		// Round through float so numeric strings like "1e3" parse by value
		// (a direct (int) cast would stop at the "e" and yield 1).
		$normalized = is_numeric( $filtered ) ? (int) round( (float) $filtered ) : 0;
		self::$detected_cores = $normalized > 0 ? $normalized : false;

		return self::$detected_cores;
	}

	/**
	 * Reset the cached core-detection result (used by unit tests)
	 */
	public static function reset_core_detection_cache() {
		self::$detected_cores = null;
	}

	/**
	 * Whether the core count came from a real probe rather than the fallback
	 *
	 * @return bool True when a detection method succeeded
	 */
	public static function is_core_detection_reliable() {
		return self::detect_cpu_core_count() !== false;
	}

	/**
	 * Get the number of CPU cores, falling back to 1 when detection fails
	 *
	 * Note: when is_core_detection_reliable() is false this returns the
	 * 1-core fallback, which is NOT a trustworthy basis for a load
	 * threshold — is_load_safe() fails open in that case (WP-547).
	 *
	 * @return int Number of CPU cores (minimum 1)
	 */
	public static function get_cpu_core_count() {
		$detected = self::detect_cpu_core_count();
		return $detected === false ? 1 : max( 1, (int) $detected );
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

		// WP-547: without a real core count the effective threshold
		// (assumed 1 core × per-core limit) is meaningless. On managed
		// hosts that block /proc/cpuinfo and shell_exec, sys_getloadavg()
		// reports the whole shared machine's load — which normally sits
		// far above a 1-core threshold — so the gate would block every
		// job forever. A gate that cannot measure must not drop work:
		// fail open.
		if ( ! self::is_core_detection_reliable() ) {
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

		// Timestamp of the most recent deferral, so consumers can tell an
		// active load problem from a historic spike (the counter is lifetime).
		$stats['last_deferral'] = time();

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
			'last_deferral' => (int) ( $stats['last_deferral'] ?? 0 ),
		);
	}

	/**
	 * Reset CPU statistics to default values
	 */
	public static function reset_stats() {
		delete_option( self::STATS_OPTION_KEY );
	}
}

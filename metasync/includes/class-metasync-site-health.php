<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * WordPress Site Health Integration for MetaSync
 *
 * Provides comprehensive WordPress cron queue health monitoring across all plugins,
 * themes, and core functionality. Helps administrators identify queue overflow issues
 * from any source while showing MetaSync's contribution.
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */

/**
 * Site Health integration class.
 *
 * Monitors the entire WordPress cron system and provides detailed statistics
 * about pending jobs, recurring events, and plugin-specific contributions.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/includes
 * @author     Engineering Team <support@searchatlas.com>
 */
class Metasync_Site_Health
{
	/**
	 * Maximum safe cron option size (3MB)
	 * Above this size, we won't attempt to load the cron array
	 */
	const MAX_SAFE_CRON_SIZE = 3145728; // 3MB

	/**
	 * Maximum jobs to count before early exit
	 * Prevents excessive processing on sites with huge queues
	 */
	const MAX_COUNT_THRESHOLD = 5000;

	/**
	 * Threshold for recommended status warning
	 * One-time pending jobs above this trigger a warning
	 */
	const PENDING_JOBS_THRESHOLD = 1000;

	/**
	 * Memory usage threshold for critical status (90%)
	 * Memory usage above this percentage triggers critical warning
	 */
	const MEMORY_CRITICAL_THRESHOLD = 90;

	/**
	 * OTTO API rate limit hits in 24h that trigger a recommended warning
	 */
	const RATE_LIMIT_WARNING_THRESHOLD = 5;

	/**
	 * OTTO API rate limit hits in 24h that trigger a critical warning
	 */
	const RATE_LIMIT_CRITICAL_THRESHOLD = 20;

	/**
	 * Failed MetaSync cron actions in 24h that trigger a recommended warning
	 */
	const FAILED_ACTIONS_THRESHOLD = 100;

	/**
	 * Initialize the Site Health integration
	 *
	 * @since    1.0.0
	 */
	public function __construct()
	{
		// Register debug information for Site Health Info tab
		$this->register_debug_information();
	}

	/**
	 * Register MetaSync debug information for the Site Health Info tab
	 *
	 * @since    1.0.0
	 */
	private function register_debug_information()
	{
		add_filter( 'debug_information', [ $this, 'add_debug_info' ] );
	}

	/**
	 * Build an HTML anchor tag pointing to a MetaSync admin page.
	 *
	 * Uses the whitelabel-aware static page slug from Metasync_Admin so that
	 * links remain correct on white-labelled installs.
	 *
	 * @since    1.0.0
	 * @param    string    $path     URL fragment appended after the page slug (e.g. '&tab=general' or '-sync-log').
	 *                               Use an empty string to link to the plugin root page.
	 * @param    string    $label    Visible link text shown to the administrator.
	 * @return   string              HTML anchor tag, ready to embed in a description string.
	 */
	private function get_admin_link( string $path, string $label ): string
	{
		$url = admin_url( 'admin.php?page=' . Metasync_Admin::$page_slug . $path );
		return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $label ) );
	}

	/**
	 * Add MetaSync debug information section to the Site Health Info tab
	 *
	 * Registers a debug information group with the whitelabeled plugin name.
	 *
	 * @since    1.0.0
	 * @param    array    $info    Existing debug information
	 * @return   array             Updated debug information with MetaSync fields
	 */
	public function add_debug_info( $info )
	{
		$plugin_name = Metasync::get_effective_plugin_name();

		$queue_stats        = $this->get_queue_stats();
		$failed_stats       = $this->get_failed_actions_stats();
		$performance_fields = $this->get_performance_settings();

		$sync_db         = new Metasync_Sync_History_Database();
		$sync_stats      = $sync_db->get_statistics();
		$completed_count = isset( $sync_stats->published_count ) ? (int) $sync_stats->published_count : 0;

		$memory_stats = $this->get_memory_stats();
		$unlimited    = $memory_stats['limit_bytes'] === -1;

		$rate_limit_log    = get_option( 'metasync_otto_rate_limit_log', [] );
		$last_backoff_time = ! empty( $rate_limit_log ) ? max( $rate_limit_log ) : null;
		$last_backoff_value = $last_backoff_time
			? wp_date( 'Y-m-d H:i:s', $last_backoff_time ) . ' (' . human_time_diff( $last_backoff_time ) . ' ago)'
			: __( 'Never' );

		$info['metasync'] = [
			'label'  => $plugin_name,
			'fields' => [
				'version' => [
					'label' => __( 'Plugin Version' ),
					'value' => METASYNC_VERSION,
				],
				'queue_pending' => [
					'label' => __( 'Queue - Pending Jobs' ),
					'value' => isset( $queue_stats['metasync_pending'] ) ? (int) $queue_stats['metasync_pending'] : 0,
				],
				'queue_completed' => [
					'label' => __( 'Queue - Completed Jobs' ),
					'value' => $completed_count,
				],
				'queue_failed_24h' => [
					'label' => __( 'Queue - Failed Jobs (last 24h)' ),
					'value' => isset( $failed_stats['count'] ) ? (int) $failed_stats['count'] : 0,
				],
			] + $performance_fields + [
				'last_api_backoff' => [
					'label' => __( 'Last API Backoff Event' ),
					'value' => $last_backoff_value,
				],
				'memory_limit' => [
					'label' => __( 'Memory - PHP Limit' ),
					'value' => $memory_stats['limit_formatted'],
				],
				'memory_current' => [
					'label' => __( 'Memory - Current Usage' ),
					'value' => $memory_stats['current_formatted'],
				],
				'memory_peak' => [
					'label' => __( 'Memory - Peak Usage' ),
					'value' => $memory_stats['peak_formatted'],
				],
				'memory_percentage' => [
					'label' => __( 'Memory - Usage Percentage' ),
					'value' => $unlimited ? __( 'N/A (unlimited)' ) : $memory_stats['percentage'] . '%',
				],
				'memory_available' => [
					'label' => __( 'Memory - Available' ),
					'value' => $memory_stats['available_formatted'],
				],
			],
		];

		return $info;
	}

	/**
	 * Register Site Health tests with WordPress
	 *
	 * Hooks into the site_status_tests filter to add our custom tests.
	 * Uses 'direct' test type which runs immediately when the Site Health page is loaded.
	 *
	 * @since    1.0.0
	 */
	public function register_tests()
	{
		add_filter('site_status_tests', function ($tests) {
			// WordPress Cron Queue Health Check
			$tests['direct']['metasync_queue_size'] = [
				'label' => __('WordPress Cron Queue Health'),
				'test' => [$this, 'queue_size_check']
			];
			
			// Memory Usage Health Check
			$tests['direct']['metasync_memory_usage'] = [
				'label' => __('PHP Memory Usage'),
				'test' => [$this, 'memory_usage_check']
			];

			// OTTO API Rate Limit Health Check
			$tests['direct']['metasync_otto_rate_limit'] = [
				'label' => __('SearchAtlas OTTO API Rate Limit'),
				'test' => [$this, 'otto_rate_limit_check']
			];

			// Debug Mode Health Check
			$tests['direct']['metasync_debug_mode'] = [
				'label' => __('WordPress Debug Mode'),
				'test'  => [$this, 'debug_mode_check']
			];

			// Failed Actions Health Check
			$tests['direct']['metasync_failed_actions'] = [
				'label' => __('MetaSync Failed Actions'),
				'test'  => [$this, 'failed_actions_check']
			];

			return $tests;
		});
	}

	/**
	 * Get the size of the cron option in the database using direct SQL
	 *
	 * This method checks the byte size of the serialized cron data WITHOUT
	 * loading and unserializing it, providing excellent performance even
	 * on sites with bloated cron queues.
	 *
	 * @since    1.0.0
	 * @return   int    Size in bytes of the cron option_value
	 */
	private function get_cron_option_size()
	{
		global $wpdb;
		
		$size = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT LENGTH(option_value) FROM {$wpdb->options} WHERE option_name = %s",
				'cron'
			)
		);
		
		return (int) $size;
	}

	/**
	 * Get comprehensive queue statistics with bifurcated breakdown
	 *
	 * Analyzes the entire WordPress cron system and categorizes jobs:
	 * - One-time events (true pending jobs that are removed after execution)
	 * - Recurring events (permanent schedule entries that never get removed)
	 * - MetaSync-specific contribution (subset of the above)
	 *
	 * Uses performance-safe approach with early size check and count limits.
	 *
	 * @since    1.0.0
	 * @return   array    Array containing statistics or error information
	 */
	private function get_queue_stats()
	{
		// First check size for safety - don't load huge cron arrays
		$size = $this->get_cron_option_size();
		
		// If cron data is excessively large (>3MB), don't try to load it
		if ($size > self::MAX_SAFE_CRON_SIZE) {
			return [
				'error' => 'excessive_size',
				'size' => $size,
				'one_time' => -1,
				'recurring' => -1,
				'metasync_pending' => -1
			];
		}
		
		// Safe to load the cron array now
		$cron = _get_cron_array();
		
		if (empty($cron)) {
			return [
				'one_time' => 0,
				'recurring' => 0,
				'metasync_pending' => 0
			];
		}
		
		$one_time = 0;
		$recurring = 0;
		$metasync_pending = 0;
		
		// Loop through ALL WordPress cron jobs (core, plugins, themes, custom)
		foreach ($cron as $hooks) {
			foreach ($hooks as $hook => $events) {  // ALL hooks from ALL sources
				foreach ($events as $event) {
					// Distinguish by schedule property
					// schedule === false means one-time event (true pending job)
					// schedule !== false means recurring event (permanent schedule)
					if ($event['schedule'] === false) {
						// One-time event (true pending job)
						$one_time++;
						
						// Track MetaSync's contribution separately
						if (strpos($hook, 'metasync_') === 0) {
							$metasync_pending++;
						}
					} else {
						// Recurring event (permanent schedule entry)
						$recurring++;
					}
					
					// Early exit for performance - stop counting after threshold
					if ($one_time > self::MAX_COUNT_THRESHOLD) {
						return [
							'one_time' => self::MAX_COUNT_THRESHOLD,
							'recurring' => $recurring,
							'metasync_pending' => $metasync_pending,
							'truncated' => true
						];
					}
				}
			}
		}
		
		return [
			'one_time' => $one_time,
			'recurring' => $recurring,
			'metasync_pending' => $metasync_pending
		];
	}

	/**
	 * Main Site Health test for WordPress cron queue
	 *
	 * Performs comprehensive health check of the entire WordPress cron system.
	 * Returns formatted result array for WordPress Site Health UI.
	 *
	 * Status levels:
	 * - good: <=1,000 pending jobs
	 * - critical: >1,000 pending jobs or cron data excessively large (>3MB)
	 *
	 * @since    1.0.0
	 * @return   array    Site Health test result
	 */
	public function queue_size_check()
	{
		$stats = $this->get_queue_stats();
		
		// Handle excessive size error
		if (isset($stats['error']) && $stats['error'] === 'excessive_size') {
			return [
				'label' => __('WordPress cron queue is excessively large'),
				'status' => 'critical',
				'badge' => [
					'label' => __('MetaSync'),
					'color' => 'red'
				],
				'description' => sprintf(
					'<p>' . __('The WordPress cron data is excessively large (%s). This can cause severe performance issues including slow page loads, high memory usage, and potential timeouts.') . '</p>' .
					'<p>' . __('This typically occurs when cron jobs accumulate from deactivated plugins or failed operations.') . '</p>',
					size_format($stats['size'])
				),
				'actions' => sprintf(
					'<p><strong>%s</strong></p>' .
					'<ul>' .
					'<li>%s</li>' .
					'<li>%s</li>' .
					'<li>%s</li>' .
					'</ul>',
					__('Recommended Actions:'),
					__('Use WP-CLI to list and clean up old cron jobs: <code>wp cron event list</code>'),
					__('Install a cron management plugin to identify and remove orphaned jobs'),
					__('Consider clearing the cron option and letting WordPress rebuild it from active plugins')
				),
				'test' => 'metasync_queue_size'
			];
		}
		
		$one_time_count = $stats['one_time'];
		$recurring_count = $stats['recurring'];
		$metasync_count = $stats['metasync_pending'];
		$truncated = isset($stats['truncated']) && $stats['truncated'];
		
		// Determine status based on TOTAL system-wide ONE-TIME jobs from ALL sources
		// This includes WordPress core, all plugins, themes, and custom code
		if ($one_time_count <= self::PENDING_JOBS_THRESHOLD) {
			$status = 'good';
			$label = __('WordPress cron queue is healthy');
		} else {
			$status = 'critical';
			$label = __('WordPress cron queue has high pending job count');
		}
		
		// Build detailed description showing system-wide statistics
		$description = sprintf(
			'<p><strong>%s</strong></p>' .
			'<ul>' .
			'<li>%s <strong>%s</strong></li>' .
			'<li>%s %d</li>' .
			'<li>%s %d</li>' .
			'</ul>',
			__('WordPress Cron Queue Statistics (All Sources):'),
			__('Total pending jobs (one-time events):'),
			$truncated ? sprintf(__('%d+'), $one_time_count) : number_format($one_time_count),
			__('Total scheduled events (recurring):'),
			$recurring_count,
			__('MetaSync pending jobs:'),
			$metasync_count
		);
		
		// Add context about what these numbers mean
		$description .= sprintf(
			'<p><em>%s</em></p>',
			__('Pending jobs are one-time tasks awaiting execution. Recurring events are permanent schedules that run automatically.')
		);
		
		// If status is not good, provide actionable recommendations
		if ($status === 'recommended') {
			$description .= sprintf(
				'<p><strong>%s</strong></p>' .
				'<ul>' .
				'<li>%s</li>' .
				'<li>%s</li>' .
				'<li>%s</li>' .
				'</ul>',
				__('A high number of pending jobs may indicate:'),
				__('WordPress cron is not running properly (check if WP-Cron is disabled or blocked)'),
				__('High volume of scheduled tasks from plugins creating jobs faster than they can be processed'),
				__('Slow server performance or resource constraints preventing timely job execution')
			);
			
			// If MetaSync is a significant contributor, add specific guidance
			if ($metasync_count > 500) {
				$description .= sprintf(
					'<p><strong>%s</strong> %s</p>',
					__('MetaSync Contribution:'),
					sprintf(
						__('MetaSync has %s pending OTTO SEO processing jobs. This may indicate high crawl volume from OTTO or slow processing. Consider reviewing OTTO crawl settings if this number continues to grow.'),
						number_format($metasync_count)
					)
				);
			}
			
			// Add helpful actions
			$description .= sprintf(
				'<p><strong>%s</strong></p>' .
				'<ul>' .
				'<li>%s</li>' .
				'<li>%s</li>' .
				'<li>%s</li>' .
				'</ul>',
				__('Recommended Actions:'),
				__('Verify WordPress cron is functioning: Tools → Site Health → Info → WordPress Constants → DISABLE_WP_CRON should be false'),
				__('Check server logs for cron execution errors or timeouts'),
				__('Consider using a real system cron instead of WP-Cron for better reliability')
			);

			$description .= sprintf(
				'<p>%s %s</p>',
				__( 'Quick link:' ),
				sprintf(
					'<a href="%s">%s</a>',
					esc_url( admin_url( 'site-health.php?tab=debug' ) ),
					esc_html( __( 'Site Health Info (check DISABLE_WP_CRON)' ) )
				)
			);
		}
		
		return [
			'label' => $label,
			'status' => $status,
			'badge' => [
				'label' => __('MetaSync'),
				'color' => $status === 'good' ? 'green' : 'red'
			],
			'description' => $description,
			'test' => 'metasync_queue_size'
		];
	}

	/**
	 * Convert PHP memory notation (e.g., "256M", "1G") to bytes
	 *
	 * @since    1.0.0
	 * @param    string    $value    Memory value from PHP configuration
	 * @return   int                 Memory value in bytes
	 */
	private function convert_to_bytes($value)
	{
		$value = trim($value);
		$last = strtolower($value[strlen($value) - 1]);
		$value = (int) $value;
		
		switch ($last) {
			case 'g':
				$value *= 1024;
				// Fall through
			case 'm':
				$value *= 1024;
				// Fall through
			case 'k':
				$value *= 1024;
				break;
			default:
				// Value is already in bytes or has no unit suffix
				break;
		}
		
		return $value;
	}

	/**
	 * Get comprehensive memory usage statistics
	 *
	 * Retrieves PHP memory configuration and current usage, calculating
	 * percentage and providing formatted human-readable values.
	 *
	 * @since    1.0.0
	 * @return   array    Array containing memory statistics
	 */
	private function get_memory_stats()
	{
		// Get memory limit from PHP configuration
		$memory_limit = ini_get('memory_limit');
		
		// Handle unlimited memory (-1)
		if ($memory_limit === '-1') {
			return [
				'limit' => -1,
				'limit_bytes' => -1,
				'limit_formatted' => __('Unlimited'),
				'current' => memory_get_usage(true),
				'current_formatted' => size_format(memory_get_usage(true)),
				'peak' => memory_get_peak_usage(true),
				'peak_formatted' => size_format(memory_get_peak_usage(true)),
				'percentage' => 0,
				'available' => -1,
				'available_formatted' => __('Unlimited')
			];
		}
		
		// Convert limit to bytes
		$limit_bytes = $this->convert_to_bytes($memory_limit);
		
		// Get current memory usage
		$current_usage = memory_get_usage(true);
		$peak_usage = memory_get_peak_usage(true);
		
		// Calculate percentage
		$percentage = ($current_usage / $limit_bytes) * 100;
		
		// Calculate available memory
		$available = $limit_bytes - $current_usage;
		
		return [
			'limit' => $memory_limit,
			'limit_bytes' => $limit_bytes,
			'limit_formatted' => size_format($limit_bytes),
			'current' => $current_usage,
			'current_formatted' => size_format($current_usage),
			'peak' => $peak_usage,
			'peak_formatted' => size_format($peak_usage),
			'percentage' => round($percentage, 2),
			'available' => $available,
			'available_formatted' => size_format($available)
		];
	}

	/**
	 * Main Site Health test for PHP memory usage
	 *
	 * Monitors PHP memory consumption and warns when approaching the limit.
	 * Helps prevent out-of-memory errors and performance issues.
	 *
	 * Status levels:
	 * - good: <=90% memory usage
	 * - critical: >90% memory usage
	 *
	 * @since    1.0.0
	 * @return   array    Site Health test result
	 */
	public function memory_usage_check()
	{
		$stats = $this->get_memory_stats();
		
		// Handle unlimited memory
		if ($stats['limit_bytes'] === -1) {
			return [
				'label' => __('PHP memory limit is unlimited'),
				'status' => 'good',
				'badge' => [
					'label' => __('MetaSync'),
					'color' => 'green'
				],
				'description' => sprintf(
					'<p>%s</p>' .
					'<p><strong>%s</strong></p>' .
					'<ul>' .
					'<li>%s %s</li>' .
					'<li>%s %s</li>' .
					'</ul>',
					__('PHP memory limit is set to unlimited. While this prevents memory errors, it may allow poorly optimized code to consume excessive server resources.'),
					__('Current Memory Usage:'),
					__('Current usage:'),
					$stats['current_formatted'],
					__('Peak usage:'),
					$stats['peak_formatted']
				),
				'test' => 'metasync_memory_usage'
			];
		}
		
		$percentage = $stats['percentage'];
		
		// Determine status based on memory usage percentage
		if ($percentage > self::MEMORY_CRITICAL_THRESHOLD) {
			$status = 'critical';
			$label = __('PHP memory usage is critically high');
		} else {
			$status = 'good';
			$label = __('PHP memory usage is healthy');
		}
		
		// Build detailed description
		$description = sprintf(
			'<p><strong>%s</strong></p>' .
			'<ul>' .
			'<li>%s %s (%s)</li>' .
			'<li>%s %s</li>' .
			'<li>%s %s</li>' .
			'<li>%s <strong>%.2f%%</strong></li>' .
			'<li>%s %s</li>' .
			'</ul>',
			__('PHP Memory Statistics:'),
			__('Memory limit:'),
			$stats['limit_formatted'],
			$stats['limit'],
			__('Current usage:'),
			$stats['current_formatted'],
			__('Peak usage:'),
			$stats['peak_formatted'],
			__('Usage percentage:'),
			$percentage,
			__('Available memory:'),
			$stats['available_formatted']
		);
		
		// Add context about memory usage
		if ($status === 'good') {
			$description .= sprintf(
				'<p><em>%s</em></p>',
				__('Memory usage is within acceptable limits. The site has sufficient memory available for normal operations.')
			);
		} else {
			// Critical status - provide actionable recommendations
			$description .= sprintf(
				'<p><strong>%s</strong></p>' .
				'<p>%s</p>' .
				'<ul>' .
				'<li>%s</li>' .
				'<li>%s</li>' .
				'<li>%s</li>' .
				'<li>%s</li>' .
				'</ul>',
				__('Critical Memory Usage Detected:'),
				__('PHP memory usage is critically high (>90%). This may cause:'),
				__('Fatal errors: "Allowed memory size exhausted"'),
				__('Failed page loads and white screens'),
				__('Incomplete plugin/theme operations'),
				__('Performance degradation and slow response times')
			);
			
			$description .= sprintf(
				'<p><strong>%s</strong></p>' .
				'<ul>' .
				'<li>%s</li>' .
				'<li>%s</li>' .
				'<li>%s</li>' .
				'<li>%s</li>' .
				'<li>%s</li>' .
				'</ul>',
				__('Recommended Actions:'),
				sprintf(
					__('Increase PHP memory limit in wp-config.php: <code>define(\'WP_MEMORY_LIMIT\', \'256M\');</code> (current: %s)'),
					$stats['limit']
				),
				__('Contact your hosting provider to increase server memory limits'),
				__('Deactivate unnecessary plugins that consume excessive memory'),
				__('Optimize images and reduce media library size'),
				__('Consider upgrading to a hosting plan with more resources')
			);
			
			// Add warning if peak usage is also high
			if ($stats['peak'] > ($stats['limit_bytes'] * 0.95)) {
				$description .= sprintf(
					'<p><strong>%s</strong> %s</p>',
					__('Warning:'),
					sprintf(
						__('Peak memory usage (%s) is very close to the limit. Memory errors may occur during high-traffic periods or resource-intensive operations.'),
						$stats['peak_formatted']
					)
				);
			}

			$description .= sprintf(
				'<p>%s %s</p>',
				__( 'Quick link:' ),
				sprintf(
					'<a href="%s">%s</a>',
					esc_url( admin_url( 'plugins.php' ) ),
					esc_html( __( 'Manage Plugins (deactivate memory-heavy plugins)' ) )
				)
			);
		}
		
		return [
			'label' => $label,
			'status' => $status,
			'badge' => [
				'label' => __('MetaSync'),
				'color' => $status === 'critical' ? 'red' : 'green'
			],
			'description' => $description,
			'test' => 'metasync_memory_usage'
		];
	}

	/**
	 * Get OTTO API rate limit statistics for the last 24 hours
	 *
	 * Reads the rolling timestamp log written by metasync_record_otto_rate_limit_hit()
	 * and counts how many 429 responses were received in the past 24 hours.
	 *
	 * @since    1.0.0
	 * @return   array    Array with 'hits_24h' (int) and 'last_hit' (int|null)
	 */
	private function get_rate_limit_stats()
	{
		$log    = get_option('metasync_otto_rate_limit_log', []);
		$cutoff = time() - DAY_IN_SECONDS;

		$recent = array_filter($log, function($t) use ($cutoff) { return $t > $cutoff; });

		return [
			'hits_24h' => count($recent),
			'last_hit' => !empty($recent) ? max($recent) : null,
		];
	}

	/**
	 * Main Site Health test for SearchAtlas OTTO API rate limits
	 *
	 * Checks how frequently the OTTO_URL_DETAILS endpoint has returned HTTP 429
	 * (Too Many Requests) in the last 24 hours and surfaces a warning when the
	 * rate limit is being hit too often.
	 *
	 * Status levels:
	 * - good:        < 5 hits in the last 24 hours
	 * - recommended: 5–19 hits in the last 24 hours
	 * - critical:    >= 20 hits in the last 24 hours
	 *
	 * @since    1.0.0
	 * @return   array    Site Health test result
	 */
	public function otto_rate_limit_check()
	{
		$stats    = $this->get_rate_limit_stats();
		$hits     = $stats['hits_24h'];
		$last_hit = $stats['last_hit'];

		// Format the last hit timestamp if available
		$last_hit_text = $last_hit
			? sprintf(
				__('Last occurrence: %s'),
				date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_hit)
			)
			: __('No rate limit hits recorded.');

		if ($hits >= self::RATE_LIMIT_CRITICAL_THRESHOLD) {
			$status = 'critical';
			$label  = sprintf(
				__('SearchAtlas OTTO API is being rate limited frequently (%d times in 24h)'),
				$hits
			);
		} elseif ($hits >= self::RATE_LIMIT_WARNING_THRESHOLD) {
			$status = 'recommended';
			$label  = sprintf(
				__('SearchAtlas OTTO API rate limit hit %d times in the last 24 hours'),
				$hits
			);
		} else {
			$status = 'good';
			$label  = __('SearchAtlas OTTO API rate limits are within normal range');
		}

		// Build description
		$description = sprintf(
			'<p><strong>%s</strong></p>' .
			'<ul>' .
			'<li>%s <strong>%d</strong></li>' .
			'<li>%s</li>' .
			'</ul>',
			__('OTTO API Rate Limit Statistics (last 24 hours):'),
			__('HTTP 429 responses received:'),
			$hits,
			$last_hit_text
		);

		if ($status === 'good') {
			$description .= sprintf(
				'<p><em>%s</em></p>',
				__('The OTTO API is responding normally. No action required.')
			);
		} else {
			$description .= sprintf(
				'<p><strong>%s</strong></p>' .
				'<p>%s</p>' .
				'<ul>' .
				'<li>%s</li>' .
				'<li>%s</li>' .
				'<li>%s</li>' .
				'</ul>',
				__('Frequent rate limiting can degrade OTTO SEO data delivery on your site.'),
				__('Recommended Actions:'),
				__('Review your OTTO crawl frequency settings in the SearchAtlas dashboard to reduce request volume.'),
				__('Check if multiple server processes are triggering OTTO jobs simultaneously.'),
				__('Contact SearchAtlas support if rate limiting persists despite low crawl volume.')
			);

			$description .= sprintf(
				'<p>%s %s</p>',
				__( 'Quick link:' ),
				$this->get_admin_link( '&tab=general', __( 'Review API Settings' ) )
			);
		}

		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => __('MetaSync'),
				'color' => $status === 'critical' ? 'red' : ( $status === 'recommended' ? 'orange' : 'green' ),
			],
			'description' => $description,
			'test'        => 'metasync_otto_rate_limit',
		];
	}

	/**
	 * Get current debug mode status across all relevant constants.
	 *
	 * Reads live PHP constants so the result reflects what PHP is
	 * actually executing, even when wp-config.php was edited manually.
	 *
	 * @since  1.0.0
	 * @return array {
	 *     @type bool $wp_debug         True if WP_DEBUG is defined and truthy.
	 *     @type bool $wp_debug_log     True if WP_DEBUG_LOG is defined and truthy.
	 *     @type bool $wp_debug_display True if WP_DEBUG_DISPLAY is defined and truthy.
	 *     @type bool $metasync_debug   True if METASYNC_DEBUG is defined and truthy.
	 * }
	 */
	private function get_debug_stats()
	{
		return [
			'wp_debug'         => defined('WP_DEBUG') && WP_DEBUG,
			'wp_debug_log'     => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
			'wp_debug_display' => defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY,
			'metasync_debug'   => defined('METASYNC_DEBUG') && constant('METASYNC_DEBUG'),
		];
	}

	/**
	 * Main Site Health test for WordPress debug mode.
	 *
	 * Checks whether WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY, or
	 * METASYNC_DEBUG are active. Debug mode is normal in development
	 * but should be disabled on production sites to avoid exposing
	 * server internals to visitors and incurring performance overhead.
	 *
	 * Status levels:
	 * - good:        No debug constants are active.
	 * - recommended: One or more debug constants are active.
	 *
	 * The 'recommended' (not 'critical') status is intentional: debug mode
	 * is a configuration choice, not a breakage, and should not alarm users
	 * who are legitimately running a staging environment.
	 *
	 * @since  1.0.0
	 * @return array    Site Health test result
	 */
	public function debug_mode_check()
	{
		$stats        = $this->get_debug_stats();
		$any_debug_on = $stats['wp_debug'] || $stats['metasync_debug'];

		// Good state: no debug constants active
		if ( ! $any_debug_on ) {
			return [
				'label'       => __('Debug mode is disabled'),
				'status'      => 'good',
				'badge'       => [
					'label' => __('MetaSync'),
					'color' => 'green',
				],
				'description' => sprintf(
					'<p>%s</p>',
					__('WordPress debug mode is not active. This is the recommended configuration for production sites.')
				),
				'test'        => 'metasync_debug_mode',
			];
		}

		// Recommended state: one or more debug constants are active
		if ( $stats['wp_debug_display'] ) {
			$label = __('Debug mode is enabled with error display active');
		} else {
			$label = __('Debug mode is enabled on this site');
		}

		// Status table showing each flag's current state
		$description = sprintf(
			'<p><strong>%s</strong></p>' .
			'<ul>' .
			'<li>%s <strong>%s</strong></li>' .
			'<li>%s <strong>%s</strong></li>' .
			'<li>%s <strong>%s</strong></li>' .
			'</ul>',
			__('Active Debug Configuration:'),
			__('WP_DEBUG:'),
			$stats['wp_debug'] ? __('Enabled') : __('Disabled'),
			__('WP_DEBUG_LOG:'),
			$stats['wp_debug_log'] ? __('Enabled') : __('Disabled'),
			__('WP_DEBUG_DISPLAY:'),
			$stats['wp_debug_display'] ? __('Enabled') : __('Disabled')
		);

		// Call out METASYNC_DEBUG if it is separately active
		if ( $stats['metasync_debug'] ) {
			$description .= sprintf(
				'<p>%s</p>',
				__('The <code>METASYNC_DEBUG</code> constant is also active. This enables additional MetaSync diagnostic output.')
			);
		}

		// Escalated warning when WP_DEBUG_DISPLAY is on (errors visible to site visitors)
		if ( $stats['wp_debug_display'] ) {
			$description .= sprintf(
				'<p><strong>%s</strong> %s</p>',
				__('Warning:'),
				__('WP_DEBUG_DISPLAY is active. PHP errors and notices may be visible to your site visitors, potentially exposing file paths, database details, and plugin internals. This should be disabled on any publicly accessible site.')
			);
		}

		$description .= sprintf(
			'<p><em>%s</em></p>',
			__('Debug mode is intended for development environments. On a production site, disabling it prevents information leakage and removes the performance overhead of error collection.')
		);

		$description .= sprintf(
			'<p><strong>%s</strong></p>' .
			'<ul>' .
			'<li>%s</li>' .
			'<li>%s</li>' .
			'</ul>',
			__('Recommended Actions:'),
			__('Set <code>define(\'WP_DEBUG\', false);</code> in your wp-config.php if this is a live production site.'),
			__('If error logging is needed in production, avoid WP_DEBUG_LOG — it writes to <code>wp-content/debug.log</code> which is publicly accessible via browser and can expose file paths, database details, and API keys. Use a dedicated logging solution that writes outside the webroot instead.')
		);

		$description .= sprintf(
			'<p>%s %s</p>',
			__( 'Quick link:' ),
			$this->get_admin_link( '&tab=advanced', __( 'Advanced Settings' ) )
		);

		return [
			'label'       => $label,
			'status'      => 'recommended',
			'badge'       => [
				'label' => __('MetaSync'),
				'color' => 'orange',
			],
			'description' => $description,
			'test'        => 'metasync_debug_mode',
		];
	}

	/**
	 * Get current performance settings for display in the Site Health Info tab
	 *
	 * Reads PHP configuration, WordPress constants, class constants, and scheduled
	 * event timing to give administrators a single-glance view of the settings that
	 * most directly affect MetaSync's runtime performance.
	 *
	 * @since    1.0.0
	 * @return   array    Associative array of performance setting fields ready for the info tab
	 */
	private function get_performance_settings()
	{
		// WP-Cron mode
		$wp_cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$wp_cron_mode     = $wp_cron_disabled
			? __( 'System Cron (DISABLE_WP_CRON = true)' )
			: __( 'WP-Cron (default)' );

		// PHP max execution time
		$max_exec_raw = ini_get( 'max_execution_time' );
		$max_exec     = $max_exec_raw === '0' || $max_exec_raw === 0
			? __( 'Unlimited (0)' )
			: (int) $max_exec_raw . 's';

		// Next scheduled transient cleanup
		$next_cleanup_ts  = wp_next_scheduled( 'metasync_cleanup_transients' );
		$next_cleanup_val = $next_cleanup_ts
			? wp_date( 'Y-m-d H:i:s', $next_cleanup_ts ) . ' (in ' . human_time_diff( $next_cleanup_ts ) . ')'
			: __( 'Not scheduled' );

		// Telemetry flush interval label
		$flush_interval       = Metasync_Telemetry_Config::QUEUE_FLUSH_INTERVAL;
		$schedules            = wp_get_schedules();
		$flush_interval_label = isset( $schedules[ $flush_interval ]['display'] )
			? $schedules[ $flush_interval ]['display']
			: $flush_interval;

		// OTTO suggestions cache TTL (stored in seconds, display as minutes)
		$otto_cache_ttl_sec = Metasync_Otto_Transient_Cache::SUGGESTIONS_TTL;
		$otto_cache_ttl_val = ( $otto_cache_ttl_sec / MINUTE_IN_SECONDS ) . ' ' . __( 'minutes' );

		return [
			'perf_wp_cron' => [
				'label' => __( 'Performance - WP-Cron Mode' ),
				'value' => $wp_cron_mode,
			],
			'perf_php_max_exec' => [
				'label' => __( 'Performance - PHP Max Execution Time' ),
				'value' => $max_exec,
			],
			'perf_otto_request_timeout' => [
				'label' => __( 'Performance - OTTO API Request Timeout' ),
				'value' => '30s',
			],
			'perf_otto_cache_ttl' => [
				'label' => __( 'Performance - OTTO Suggestions Cache TTL' ),
				'value' => $otto_cache_ttl_val,
			],
			'perf_otto_max_calls' => [
				'label' => __( 'Performance - OTTO Max API Calls/Min' ),
				'value' => Metasync_Otto_Transient_Cache::MAX_API_CALLS_PER_MINUTE,
			],
			'perf_telemetry_queue_max' => [
				'label' => __( 'Performance - Telemetry Max Queue Size' ),
				'value' => Metasync_Telemetry_Config::MAX_QUEUE_SIZE,
			],
			'perf_telemetry_batch' => [
				'label' => __( 'Performance - Telemetry Batch Size' ),
				'value' => Metasync_Telemetry_Config::QUEUE_BATCH_SIZE,
			],
			'perf_telemetry_flush' => [
				'label' => __( 'Performance - Telemetry Queue Flush Interval' ),
				'value' => $flush_interval_label,
			],
			'perf_api_max_retries' => [
				'label' => __( 'Performance - API Max Retry Attempts' ),
				'value' => Metasync_Telemetry_Config::MAX_RETRY_ATTEMPTS,
			],
			'perf_next_cleanup' => [
				'label' => __( 'Performance - Next Transient Cleanup' ),
				'value' => $next_cleanup_val,
			],
		];
	}

	/**
	 * Get failed MetaSync cron action statistics for the last 24 hours
	 *
	 * Reads the rolling timestamp log written by metasync_record_failed_action()
	 * and counts how many failures were recorded in the past 24 hours.
	 *
	 * @since    1.0.0
	 * @return   array    Array with 'count' (int) and 'last_hit' (int|null)
	 */
	private function get_failed_actions_stats()
	{
		$log    = get_option( 'metasync_failed_actions_log', [] );
		$cutoff = time() - DAY_IN_SECONDS;

		$recent = array_filter( $log, function( $t ) use ( $cutoff ) { return $t > $cutoff; } );

		return [
			'count'    => count( $recent ),
			'last_hit' => ! empty( $recent ) ? max( $recent ) : null,
		];
	}

	/**
	 * Main Site Health test for MetaSync failed cron actions
	 *
	 * Checks how many MetaSync OTTO SEO processing jobs have failed in the last
	 * 24 hours and surfaces a warning when failures exceed the threshold.
	 * Failures are recorded by metasync_record_failed_action() in otto_pixel.php
	 * when metasync_process_otto_seo_data() encounters an API error or exception.
	 *
	 * Status levels:
	 * - good:        <= 100 failures in the last 24 hours
	 * - recommended: > 100 failures in the last 24 hours
	 *
	 * @since    1.0.0
	 * @return   array    Site Health test result
	 */
	public function failed_actions_check()
	{
		$stats    = $this->get_failed_actions_stats();
		$count    = $stats['count'];
		$last_hit = $stats['last_hit'];

		$last_hit_text = $last_hit
			? sprintf(
				__( 'Last failure: %s' ),
				date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_hit )
			  )
			: __( 'No failed actions recorded in the last 24 hours.' );

		if ( $count > self::FAILED_ACTIONS_THRESHOLD ) {
			$status = 'recommended';
			$label  = sprintf(
				__( 'MetaSync has %d failed SEO processing actions in the last 24 hours' ),
				$count
			);
		} else {
			$status = 'good';
			$label  = __( 'MetaSync SEO action queue is healthy' );
		}

		$description = sprintf(
			'<p><strong>%s</strong></p>' .
			'<ul>' .
			'<li>%s <strong>%d</strong></li>' .
			'<li>%s</li>' .
			'</ul>',
			__( 'MetaSync Failed Actions Statistics (last 24 hours):' ),
			__( 'Failed OTTO SEO processing jobs:' ),
			$count,
			$last_hit_text
		);

		if ( $status === 'good' ) {
			$description .= sprintf(
				'<p><em>%s</em></p>',
				__( 'OTTO SEO processing jobs are completing successfully. No action required.' )
			);
		} else {
			$description .= sprintf(
				'<p><strong>%s</strong></p>' .
				'<ul>' .
				'<li>%s</li>' .
				'<li>%s</li>' .
				'<li>%s</li>' .
				'</ul>',
				__( 'Recommended Actions:' ),
				__( 'Check your SearchAtlas API connection in MetaSync → Settings.' ),
				__( 'Review your PHP error log for OTTO processing exceptions.' ),
				__( 'Verify your OTTO UUID is correctly configured and the SearchAtlas API is reachable.' )
			);

			$description .= sprintf(
				'<p>%s %s &middot; %s</p>',
				__( 'Quick links:' ),
				$this->get_admin_link( '&tab=general', __( 'Review API Settings' ) ),
				$this->get_admin_link( '-sync-log', __( 'View Sync Log' ) )
			);
		}

		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => __( 'MetaSync' ),
				'color' => $status === 'recommended' ? 'orange' : 'green',
			],
			'description' => $description,
			'test'        => 'metasync_failed_actions',
		];
	}
}

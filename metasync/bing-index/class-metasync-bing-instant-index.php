<?php

/**
 * The Bing Instant Indexing functionality of the Metasync plugin.
 *
 * Uses the IndexNow API protocol which is supported by Bing and other search engines.
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/bing-index
 * @author     Engineering Team <support@searchatlas.com>
 */

// Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

class Metasync_Bing_Instant_Index
{

	/**
	 * Holds the default settings.
	 *
	 * @var array
	 */
	public $default_settings = [];

	/**
	 * URL of the IndexNow setup guide
	 *
	 * @var string
	 */
	public $indexnow_guide_url = 'https://www.indexnow.org/documentation';

	/**
	 * IndexNow API endpoint
	 *
	 * @var string
	 */
	public $indexnow_endpoint = 'https://api.indexnow.org/indexnow';

	/**
	 * Bing-specific IndexNow endpoint (alternative)
	 *
	 * @var string
	 */
	public $bing_endpoint = 'https://www.bing.com/indexnow';

	/**
	 * Constructor method.
	 */
	public function __construct()
	{
		$this->default_settings = [
			'api_key'    => '',
			'post_types' => [],
			'endpoint'   => 'indexnow', // 'indexnow' or 'bing'
			'disable_other_plugins' => true, // Prevent other plugins from submitting
		];

		// Hook to disable other IndexNow plugins if setting is enabled
		$this->maybe_disable_other_plugins();
	}

	/**
	 * Disable other IndexNow plugins to prevent duplicate submissions
	 *
	 * @return void
	 */
	private function maybe_disable_other_plugins()
	{
		// Check if API key is configured first
		$api_key = $this->get_setting('api_key');
		if (empty($api_key)) {
			return;
		}

		// Check if setting is enabled (default: true)
		if (!$this->get_setting('disable_other_plugins', true)) {
			return;
		}

		// Disable Yoast SEO IndexNow integration
		add_filter('wpseo_indexnow_integration', '__return_false', 999);
		add_filter('Yoast\WP\SEO\integrations\index_now_integration_active', '__return_false', 999);

		// Disable Rank Math IndexNow integration
		add_filter('rank_math/indexnow/enabled', '__return_false', 999);

		// Disable IndexNow plugin (official WordPress plugin)
		add_filter('indexnow_enabled', '__return_false', 999);

		// Disable Instant Indexing plugin
		add_filter('instant_indexing_enabled', '__return_false', 999);

		// Generic hook for other plugins to detect and disable their IndexNow features
		add_filter('metasync_indexnow_exclusive_mode', '__return_true', 999);
	}

	/**
	 * Get User-Agent string for IndexNow API requests
	 *
	 * @return string User-Agent header value
	 */
	private function get_user_agent()
	{
		// Use existing static method for plugin name with full whitelabel support
		$plugin_name = class_exists('Metasync') ? Metasync::get_effective_plugin_name('Search Atlas') : 'Search Atlas';
		$version = defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0';
		$site_url = get_site_url();

		// Format: PluginName/Version (+SiteURL)
		$user_agent = sprintf('%s/%s (+%s)', $plugin_name, $version, $site_url);

		return $user_agent;
	}

	/**
	 * Add links of Update and get status to posts for Bing instant indexing.
	 *
	 * @param array $actions Current actions.
	 * @param WP_Post $post Current post object.
	 * @return array Modified actions.
	 */
	public function bing_instant_index_post_link($actions, $post)
	{
		// Check if Bing Instant Indexing is enabled
		$seo_controls = Metasync::get_option('seo_controls');
		if (!($seo_controls['enable_binginstantindex'] ?? false)) {
			return $actions;
		}

		$post_types = $this->get_setting('post_types', []);

		if (in_array($post->post_type, $post_types) && $post->post_status == 'publish') {
			$link = get_permalink($post);

			// Get menu slug (support white label)
			$general_options = Metasync::get_option('general') ?? [];
			$menu_slug = !empty($general_options['white_label_plugin_menu_slug']) ? $general_options['white_label_plugin_menu_slug'] : 'searchatlas';
			$page_slug = $menu_slug . '-bing-console';

			$actions['bing-index-submit'] = '<a href="' . admin_url("admin.php?page=" . $page_slug . "&postaction=submit&posturl=" . rawurlencode($link)) . '" title="" rel="permalink">Submit to Bing IndexNow</a>';
		}
		return $actions;
	}


	/**
	 * Output Bing Indexing Console page UI.
	 *
	 * @return void
	 */
	public function show_bing_instant_indexing_console()
	{
		include_once plugin_dir_path(__FILE__) . '../views/metasync-bing-console.php';
	}

	/**
	 * Normalize textarea input URLs.
	 *
	 * @return array Input URLs.
	 */
	public function get_input_urls()
	{
		if (!isset($_POST['metasync_bing_url'])) {
			return [];
		}
		return array_values(array_filter(array_map('trim', explode("\n", sanitize_textarea_field(wp_unslash($_POST['metasync_bing_url']))))));
	}

	/**
	 * Send the URLs to Bing via IndexNow API
	 *
	 * @return void
	 */
	public function send()
	{
		if (!isset($_POST['metasync_bing_url'])) {
			wp_send_json([
				'success' => false,
				'message' => 'No URLs provided for submission.'
			]);
			wp_die();
		}

		$send_url = $this->get_input_urls();

		if (empty($send_url)) {
			wp_send_json([
				'success' => false,
				'message' => 'No valid URLs found to submit.'
			]);
			wp_die();
		}

		header('Content-type: application/json');

		$result = $this->indexnow_api($send_url);
		wp_send_json($result);
		wp_die();
	}

	/**
	 * Send one or more URLs to IndexNow API (Bing).
	 *
	 * @param array $urls URLs to submit.
	 * @param bool $skip_duplicate_check Skip duplicate submission check (default: false).
	 * @param int $retry_count Current retry attempt (default: 0).
	 * @return array Response data.
	 */
	public function indexnow_api($urls, $skip_duplicate_check = false, $retry_count = 0)
	{
		$urls = (array) $urls;
		$api_key = $this->get_setting('api_key');
		$max_retries = 3;

		if (empty($api_key)) {
			return [
				'success' => false,
				'message' => 'API key is not configured. Please configure your IndexNow API key in the settings.',
			];
		}

		// Validate API key format before sending
		if (!$this->validate_api_key_format($api_key)) {
			return [
				'success' => false,
				'message' => 'Invalid API key format. Please check your IndexNow API key configuration.',
			];
		}

		// Prevent duplicate submissions from other plugins (optional optimization)
		if (!$skip_duplicate_check && $this->is_duplicate_submission($urls)) {
			return [
				'success' => false,
				'message' => 'URLs already submitted recently. Skipping duplicate submission.',
				'skipped' => true,
			];
		}

		$endpoint_type = $this->get_setting('endpoint', 'indexnow');
		$endpoint = $endpoint_type === 'bing' ? $this->bing_endpoint : $this->indexnow_endpoint;

		$site_url = get_site_url();
		$host = parse_url($site_url, PHP_URL_HOST);

		// Prepare the request payload
		$payload = [
			'host' => $host,
			'key' => $api_key,
			'urlList' => $urls,
		];

		// Get User-Agent for source identification
		$user_agent = $this->get_user_agent();

		// Get plugin name and version for additional headers
		$plugin_name = class_exists('Metasync') ? Metasync::get_effective_plugin_name('Search Atlas') : 'Search Atlas';
		$version = defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0';

		// Prepare request arguments
		$request_args = [
			'headers' => [
				'Content-Type' => 'application/json; charset=utf-8',
				'User-Agent' => $user_agent,
				'X-Source-Plugin' => $plugin_name,
				'X-Plugin-Version' => $version,
			],
			'body' => wp_json_encode($payload),
			'timeout' => 30,
			'user-agent' => $user_agent, // WordPress also checks lowercase 'user-agent' key
		];

		// Send the request to IndexNow API with User-Agent for source identification
		$response = wp_remote_post($endpoint, $request_args);

		// Handle network errors with retry logic
		if (is_wp_error($response)) {
			$error_message = $response->get_error_message();

			// Retry on network errors (timeout, connection issues)
			if ($retry_count < $max_retries && $this->should_retry_on_error($response)) {
				sleep(pow(2, $retry_count)); // Exponential backoff: 1s, 2s, 4s
				return $this->indexnow_api($urls, $skip_duplicate_check, $retry_count + 1);
			}

			return [
				'success' => false,
				'message' => 'Error: ' . $error_message . ($retry_count > 0 ? ' (Failed after ' . $retry_count . ' retries)' : ''),
				'urls' => $urls,
			];
		}

		$response_code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);

		// IndexNow returns 200 for success, 202 for accepted
		if (in_array($response_code, [200, 202])) {
			// Record successful submission to prevent duplicates
			if (!$skip_duplicate_check) {
				$this->record_submission($urls);
			}

			$success_message = 'URLs successfully submitted to IndexNow (Bing). Response code: ' . $response_code;
			if ($retry_count > 0) {
				$success_message .= ' (Succeeded after ' . $retry_count . ' retries)';
			}

			return [
				'success' => true,
				'message' => $success_message,
				'urls' => $urls,
				'response_code' => $response_code,
			];
		} else {
			// Retry on rate limiting (429) or server errors (5xx)
			if ($retry_count < $max_retries && $this->should_retry_on_response_code($response_code)) {
				sleep(pow(2, $retry_count)); // Exponential backoff
				return $this->indexnow_api($urls, $skip_duplicate_check, $retry_count + 1);
			}

			return [
				'success' => false,
				'message' => 'Failed to submit URLs. Response code: ' . $response_code . ($retry_count > 0 ? ' (Failed after ' . $retry_count . ' retries)' : ''),
				'urls' => $urls,
				'response_code' => $response_code,
				'response_body' => $response_body,
			];
		}
	}

	/**
	 * Determine if we should retry based on WP_Error.
	 *
	 * @param WP_Error $error WordPress error object.
	 * @return bool True if error is retryable.
	 */
	private function should_retry_on_error($error)
	{
		$error_code = $error->get_error_code();

		// Retry on network-related errors
		$retryable_errors = ['http_request_failed', 'http_request_timeout', 'connect_error'];

		return in_array($error_code, $retryable_errors);
	}

	/**
	 * Determine if we should retry based on HTTP response code.
	 *
	 * @param int $response_code HTTP response code.
	 * @return bool True if response code is retryable.
	 */
	private function should_retry_on_response_code($response_code)
	{
		// Retry on rate limiting (429) or server errors (500-599)
		if ($response_code == 429) {
			return true;
		}

		if ($response_code >= 500 && $response_code < 600) {
			return true;
		}

		return false;
	}

	/**
	 * Check if URLs were recently submitted to prevent duplicates
	 *
	 * @param array $urls URLs to check.
	 * @return bool True if URLs were recently submitted.
	 */
	private function is_duplicate_submission($urls)
	{
		$recent_submissions = get_transient('metasync_bing_recent_submissions');

		if (!is_array($recent_submissions)) {
			return false;
		}

		// Check if any URL was submitted in the last 5 minutes
		foreach ($urls as $url) {
			$url_hash = md5($url);
			if (isset($recent_submissions[$url_hash])) {
				$submission_time = $recent_submissions[$url_hash];
				// Check if submitted within last 5 minutes
				if ((time() - $submission_time) < 300) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Record URLs as submitted to prevent duplicate submissions
	 *
	 * @param array $urls URLs that were submitted.
	 * @return void
	 */
	private function record_submission($urls)
	{
		$recent_submissions = get_transient('metasync_bing_recent_submissions');

		if (!is_array($recent_submissions)) {
			$recent_submissions = [];
		}

		// Clean up old entries (older than 10 minutes)
		$current_time = time();
		foreach ($recent_submissions as $hash => $timestamp) {
			if (($current_time - $timestamp) > 600) {
				unset($recent_submissions[$hash]);
			}
		}

		// Add new submissions
		foreach ($urls as $url) {
			$url_hash = md5($url);
			$recent_submissions[$url_hash] = $current_time;
		}

		// Store for 15 minutes
		set_transient('metasync_bing_recent_submissions', $recent_submissions, 900);
	}

	/**
	 * Submit URL automatically when post is published or updated.
	 *
	 * @param int $post_id Post ID.
	 * @param WP_Post $post Post object.
	 * @param bool $update Whether this is an update.
	 * @return void
	 */
	public function auto_submit_on_publish($post_id, $post, $update)
	{
		// Check if auto-submit is enabled
		$post_types = $this->get_setting('post_types', []);

		// Only submit if post type is in the enabled list and post is published
		if (!in_array($post->post_type, $post_types) || $post->post_status !== 'publish') {
			return;
		}

		// Get the permalink
		$url = get_permalink($post_id);

		// Submit to IndexNow
		$this->indexnow_api([$url]);
	}

	/**
	 * Get saved plugin setting.
	 *
	 * @param string $setting Setting key.
	 * @param mixed $default Default value if setting doesn't exist.
	 * @return mixed Settings.
	 */
	public function get_setting($setting, $default = null)
	{
		$settings = $this->get_settings();

		return (isset($settings[$setting]) ? $settings[$setting] : $default);
	}

	/**
	 * Get all settings.
	 *
	 * @return array Settings.
	 */
	private function get_settings()
	{
		$setting = get_option('metasync_options_bing_instant_indexing', []);

		$settings = array_merge($this->default_settings, $setting);

		return $settings;
	}

	/**
	 * Validate API key format.
	 *
	 * IndexNow API keys must be hexadecimal strings (8-128 characters).
	 *
	 * @param string $api_key The API key to validate.
	 * @return bool True if valid, false otherwise.
	 */
	private function validate_api_key_format($api_key)
	{
		// Must be between 8 and 128 characters
		$length = strlen($api_key);
		if ($length < 8 || $length > 128) {
			return false;
		}

		// Must be hexadecimal (0-9, a-f, A-F)
		return ctype_xdigit($api_key);
	}

	/**
	 * Verify API key file exists and is accessible.
	 *
	 * @return array Status information.
	 */
	public function verify_api_key_file()
	{
		$api_key = $this->get_setting('api_key');

		if (empty($api_key)) {
			return [
				'success' => false,
				'message' => 'No API key configured.',
			];
		}

		$file_path = ABSPATH . $api_key . '.txt';
		$file_url = home_url('/' . $api_key . '.txt');

		// Check if file exists
		if (!file_exists($file_path)) {
			return [
				'success' => false,
				'message' => 'API key file does not exist.',
				'file_path' => $file_path,
				'file_url' => $file_url,
			];
		}

		// Check if file is readable
		if (!is_readable($file_path)) {
			return [
				'success' => false,
				'message' => 'API key file exists but is not readable.',
				'file_path' => $file_path,
				'file_url' => $file_url,
			];
		}

		// Verify file content matches API key
		$file_content = file_get_contents($file_path);
		if (trim($file_content) !== $api_key) {
			return [
				'success' => false,
				'message' => 'API key file content does not match configured API key.',
				'file_path' => $file_path,
				'file_url' => $file_url,
				'expected' => $api_key,
				'actual' => trim($file_content),
			];
		}

		return [
			'success' => true,
			'message' => 'API key file is valid and accessible.',
			'file_path' => $file_path,
			'file_url' => $file_url,
		];
	}

	/**
	 * Get submission statistics.
	 *
	 * @return array Statistics about recent submissions.
	 */
	public function get_submission_stats()
	{
		$recent_submissions = get_transient('metasync_bing_recent_submissions');

		if (!is_array($recent_submissions)) {
			return [
				'total_urls' => 0,
				'last_submission' => null,
			];
		}

		$urls = array_keys($recent_submissions);
		$timestamps = array_values($recent_submissions);

		return [
			'total_urls' => count($urls),
			'last_submission' => !empty($timestamps) ? max($timestamps) : null,
			'last_submission_formatted' => !empty($timestamps) ? human_time_diff(max($timestamps)) . ' ago' : 'Never',
		];
	}

	/**
	 * Generate a random API key for IndexNow.
	 *
	 * @return string Random API key (32 characters, hex).
	 */
	public static function generate_random_api_key()
	{
		return bin2hex(random_bytes(16));
	}
}

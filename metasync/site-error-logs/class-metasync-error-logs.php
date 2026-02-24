<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}


/**
 * The site error logs for the plugin.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/site-error-logs
 * @author     Engineering Team <support@searchatlas.com>
 */
class Metasync_Error_Logs
{
	/**
	 * The log file path
	 * @var string
	 */
	private $log_file_path = null;

	/**
	 * The error message if log cannot be displayed
	 * @var string
	 */
	private $error_message = '';

	/**
	 * Maximum log file size in bytes (10MB)
	 * @var int
	 */
	private $max_log_size = 10485760; // 10MB

	/**
	 * Show copy button - integrated into preview header
	 */
	public function show_copy_button()
	{
		// Button is now shown in the preview header
		// Keeping this method for backward compatibility
	}

	/**
	 * Show information about the error log file.
	 * Info is now integrated into the preview header
	 */
	public function show_info()
	{
		// Info is now shown in the preview header
		// Keeping this method for backward compatibility
	}

	/**
	 * Show strong message.
	 */
	public function show_strong_message($message)
	{
	?>
		<strong class="error-log-cannot-display"><?php echo esc_html($message) ?></strong><br>
	<?php
	}

	/**
	 * Show strong message.
	 */
	public function show_code_html($code)
	{
	?>
		<code><?php echo esc_html($code); ?></code>
	<?php
	}

	/**
	 * Show error log preview with sitemap-style layout
	 */
	public function show_logs()
	{
		$log_file = $this->get_actual_log_path();
		$log_content = $this->get_error_logs(-1); // Get all content to count lines
		$lines = explode("\n", $log_content);
		$total_lines = count(array_filter($lines, 'strlen')); // Count non-empty lines

		// Get last 100 lines for preview
		$preview_lines = array_slice($lines, -100);
		$preview_content = implode("\n", $preview_lines);
		$hidden_lines = max(0, $total_lines - 100);
	?>
		<div class="metasync-sitemap-preview-container" style="margin-top: 20px;">
			<div class="metasync-sitemap-preview-header" style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: var(--dashboard-card-bg); border: 1px solid var(--dashboard-border); border-bottom: none; border-radius: 8px 8px 0 0;">
				<span class="metasync-preview-label" style="font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, monospace; font-size: 13px; color: var(--dashboard-text-primary); font-weight: 500;">
					📄 plugin_errors.log
					<span style="color: var(--dashboard-text-secondary); font-weight: normal; margin-left: 8px;">
						(<?php echo esc_html($this->get_human_number($this->wp_filesystem()->size($log_file))); ?> • <?php echo esc_html(number_format($total_lines)); ?> lines)
					</span>
				</span>
				<div style="display: flex; gap: 8px;">
					<button type="button" class="button button-small" id="copy-log-btn" onclick="metasyncCopyErrorLog()" style="display: flex; align-items: center; gap: 4px;">
						<span class="dashicons dashicons-clipboard" style="font-size: 16px; width: 16px; height: 16px;"></span>
						Copy to Clipboard
					</button>
					<a href="<?php echo esc_url(content_url('metasync_data/plugin_errors.log')); ?>" target="_blank" class="button button-small" style="display: flex; align-items: center; gap: 4px;">
						<span class="dashicons dashicons-external" style="font-size: 16px; width: 16px; height: 16px;"></span>
						View Full Log
					</a>
				</div>
			</div>
			<div class="metasync-sitemap-code-block" style="background: var(--dashboard-card-bg); border: 1px solid var(--dashboard-border); border-radius: 0 0 8px 8px; padding: 20px; overflow: auto; max-height: 500px;">
				<pre id="error-log-content" style="margin: 0; padding: 0; font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, monospace; font-size: 12px; line-height: 1.6; color: var(--dashboard-text-primary); white-space: pre-wrap; word-wrap: break-word;"><?php
					if ($hidden_lines > 0) {
						echo esc_html("... [" . number_format($hidden_lines) . " earlier lines hidden]\n\n");
					}
					echo esc_html($preview_content);
				?></pre>
			</div>
		</div>

		<script>
		function metasyncCopyErrorLog() {
			const content = document.getElementById('error-log-content').textContent;
			const button = document.getElementById('copy-log-btn');
			const originalText = button.innerHTML;

			navigator.clipboard.writeText(content).then(() => {
				button.innerHTML = '<span class="dashicons dashicons-yes" style="font-size: 16px; width: 16px; height: 16px;"></span> Copied!';
				button.style.background = '#00a32a';
				button.style.color = '#ffffff';
				button.style.borderColor = '#00a32a';

				setTimeout(() => {
					button.innerHTML = originalText;
					button.style.background = '';
					button.style.color = '';
					button.style.borderColor = '';
				}, 2000);
			}).catch(err => {
				console.error('Failed to copy log:', err);
				button.innerHTML = '<span class="dashicons dashicons-no" style="font-size: 16px; width: 16px; height: 16px;"></span> Failed';
				button.style.background = '#dc3232';
				button.style.color = '#ffffff';
				button.style.borderColor = '#dc3232';

				setTimeout(() => {
					button.innerHTML = originalText;
					button.style.background = '';
					button.style.color = '';
					button.style.borderColor = '';
				}, 2000);
			});
		}
		</script>
<?php
	}

	/**
	 * Show message if the log cannot be established.
	 */
	public function can_show_error_logs()
	{
		// Reset error message
		$this->error_message = '';
		
		// Create the error log file first
		$file_path = $this->createErrorLog();
		
		// Store the log file path for use in other methods
		$this->log_file_path = $file_path;
		
		// Use the created log file path instead of ini_get('error_log')
		$log_file = $file_path;

		if (empty($file_path)) {
			$this->error_message = 'Unable to create error log file.';
			return false;
		}

		$wp_filesystem = $this->wp_filesystem();
		
		if (is_null($wp_filesystem)) {
			$this->error_message = 'WordPress filesystem not available.';
			return false;
		}

		if (!$wp_filesystem->exists($log_file)) {
			$this->error_message = 'Error log file does not exist: ' . $log_file;
			return false;
		}

		if (!$wp_filesystem->is_readable($log_file)) {
			$this->error_message = 'Error log file is not readable.';
			return false;
		}

		// Error log must be smaller than 100 MB.
		$size = $wp_filesystem->size($log_file);
		if ($size > 100000000) {
			$wp_filesystem->delete($log_file);
			$this->error_message = 'The error log cannot be retrieved: Error log file is too large.';
			return false;
		}

		return true;
	}

	/**
	 * Get the error message if log cannot be displayed.
	 * @return string
	 */
	public function get_error_message()
	{
		return $this->error_message;
	}

	/**
	 * WordPress filesystem for use.
	 *
	 * @return string
	 */
	public function get_error_logs($limit = -1)
	{
		$wp_filesystem = $this->wp_filesystem();
		
		// Use the actual log file path
		$log_file = $this->get_actual_log_path();
		
		if (empty($log_file) || !$wp_filesystem->exists($log_file)) {
			return '';
		}
		
		$contents = $wp_filesystem->get_contents_array($log_file);

		if (-1 === $limit) {
			return join('', $contents);
		}

		return join('', array_slice($contents, -$limit));
	}

	/**
	 * WordPress filesystem for use.
	 *
	 * @return object
	 */
	private function wp_filesystem()
	{
		global $wp_filesystem;

		if (empty($wp_filesystem)) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return $wp_filesystem;
	}

	/**
	 * Get error log file location.
	 *
	 * @return string
	 */
	private function get_log_path()
	{
		return ini_get('error_log') != '' ? ini_get('error_log') : '';
	}

	/**
	 * Get the actual log file path (either stored or create a new one).
	 *
	 * @return string
	 */
	private function get_actual_log_path()
	{
		// If we have a stored log file path, use it
		if (!empty($this->log_file_path)) {
			return $this->log_file_path;
		}
		
		// Otherwise, try to get the default log path
		$log_file = $this->get_log_path();
		
		// If default path is empty or doesn't exist, create one
		if (empty($log_file)) {
			$log_file = $this->createErrorLog();
			$this->log_file_path = $log_file;
		}
		
		return $log_file;
	}

	/**
	 * Clear the log.
	 * @return void
	 */
	public static function clear()
	{
		if (ini_get('error_log') === '') return;
		$handle = fopen(ini_get('error_log'), 'w');
		fclose($handle);
	}

	/**
	 * Get human read number of units.
	 *
	 * @param string $bytes
	 * @return string
	 */
	public function get_human_number(string $bytes)
	{
		if ($bytes >= 1073741824) {
			$bytes = number_format($bytes / 1073741824, 2) . ' GB';
		} elseif ($bytes >= 1048576) {
			$bytes = number_format($bytes / 1048576, 2) . ' MB';
		} elseif ($bytes >= 1024) {
			$bytes = number_format($bytes / 1024, 2) . ' KB';
		} elseif ($bytes > 1) {
			$bytes = $bytes . ' bytes';
		} elseif ($bytes == 1) {
			$bytes = $bytes . ' byte';
		} else {
			$bytes = '0 bytes';
		}

		return $bytes;
	}

	/**
	 * Get or create the error log file path
	 * Points to /metasync_data/plugin_errors.log which is actively written by Log_Manager
	 *
	 * @return string|false Log file path or false on failure
	 */
	public function createErrorLog()
	{
		// Use the same directory as Log_Manager for consistency
		$log_directory = WP_CONTENT_DIR . '/metasync_data';

		// Create directory if it doesn't exist
		if (!is_dir($log_directory)) {
			if (!@mkdir($log_directory, 0755, true)) {
				error_log('Metasync_Error_Logs: Failed to create log directory: ' . $log_directory);
				return false;
			}
		}

		// Verify directory is writable
		if (!is_writable($log_directory)) {
			@chmod($log_directory, 0755);
			if (!is_writable($log_directory)) {
				error_log('Metasync_Error_Logs: Directory not writable: ' . $log_directory);
				return false;
			}
		}

		// Point to the actual log file being written by Log_Manager
		$log_file = wp_normalize_path($log_directory . '/plugin_errors.log');

		// Create file if it doesn't exist
		if (!file_exists($log_file)) {
			if (@file_put_contents($log_file, '') === false) {
				error_log('Metasync_Error_Logs: Failed to create log file: ' . $log_file);
				return false;
			}
			@chmod($log_file, 0644);
		}

		// Rotate log file if it's too large
		$this->rotate_log_if_needed($log_file);

		return $log_file;
	}

	/**
	 * Rotate log file if it exceeds maximum size
	 * Keeps the most recent entries and archives old ones
	 *
	 * @param string $log_file Path to the log file
	 * @return bool True if rotation occurred, false otherwise
	 */
	private function rotate_log_if_needed($log_file)
	{
		if (!file_exists($log_file)) {
			return false;
		}

		$file_size = filesize($log_file);

		// If file is under the limit, no rotation needed
		if ($file_size < $this->max_log_size) {
			return false;
		}

		// Create backup of current log with timestamp
		$backup_file = $log_file . '.old.' . date('Y-m-d-His');

		// Read last 50% of the file to keep recent entries
		$wp_filesystem = $this->wp_filesystem();
		if (!$wp_filesystem) {
			return false;
		}

		$content = $wp_filesystem->get_contents($log_file);
		if ($content === false) {
			return false;
		}

		// Split into lines and keep the most recent half
		$lines = explode("\n", $content);
		$total_lines = count($lines);
		$keep_lines = (int) ($total_lines / 2);

		// Keep the most recent lines
		$recent_content = implode("\n", array_slice($lines, -$keep_lines));

		// Archive old content
		@file_put_contents($backup_file, $content);
		@chmod($backup_file, 0644);

		// Write recent content back to main log
		$wp_filesystem->put_contents($log_file, $recent_content, FS_CHMOD_FILE);

		// Clean up old backup files (keep only last 3 backups)
		$this->cleanup_old_backups(dirname($log_file));

		return true;
	}

	/**
	 * Clean up old backup log files
	 * Keeps only the 3 most recent backup files
	 *
	 * @param string $directory Directory containing backup files
	 */
	private function cleanup_old_backups($directory)
	{
		$backup_files = glob($directory . '/plugin_errors.log.old.*');

		if (!$backup_files || count($backup_files) <= 3) {
			return;
		}

		// Sort by modification time (newest first)
		usort($backup_files, function($a, $b) {
			return filemtime($b) - filemtime($a);
		});

		// Delete all but the 3 newest
		$files_to_delete = array_slice($backup_files, 3);
		foreach ($files_to_delete as $file) {
			@unlink($file);
		}
	}
}

<?php

/**
 * The site error logs for the plugin.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/site-error-logs
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
 */
class Metasync_Error_Logs
{

	/**
	 * Show copy button.
	 */
	public function show_copy_button()
	{
?>
		<div>
			<button type="button" class="button" id="copy-clipboard-btn">
				Logs Copy to Clipboard
			</button>
			<span class="hide">Copied!</span>
		</div>
	<?php
	}

	/**
	 * Show information about the error log file.
	 */
	public function show_info()
	{
		$log_file      = $this->get_log_path();
		$wp_filesystem  = $this->wp_filesystem();
		$size = $wp_filesystem->size($log_file);
	?>
		<div>
			<code><?php echo esc_html(basename($this->get_log_path())); ?></code>
			<em>(<?php echo esc_html($this->get_human_number($size)); ?>)</em>
		</div>
	<?php
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
	 * Show error log in text area.
	 */
	public function show_logs()
	{
	?>
		<div>
			<textarea rows="20" class="code-box" id="error-code-box" disabled><?php echo esc_textarea($this->get_error_logs(50)); ?></textarea>
		</div>
<?php
	}

	/**
	 * Show message if the log cannot be established.
	 */
	public function can_show_error_logs()
	{
		$log_file      = $this->get_log_path();
		$wp_filesystem  = $this->wp_filesystem();
		$size = $wp_filesystem->size($log_file);
		$file_path = $this->createErrorLog();

		if (
			empty($file_path) ||
			empty($log_file) ||
			is_null($wp_filesystem) ||
			!$wp_filesystem->exists($log_file) ||
			!$wp_filesystem->is_readable($log_file)
		) {

			$this->show_strong_message('The error log of this site cannot be retrieved.');
			$this->show_strong_message('Please add below line in wp-config.php.');
			$this->show_code_html("@ini_set('error_log', '" . $file_path . "');");

			return false;
		}

		// Error log must be smaller than 100 MB.
		if ($size > 100000000) {
			$wp_filesystem->delete($log_file);
			$this->show_strong_message('The error log cannot be retrieved: Error log file is too large.');
			return false;
		}

		return true;
	}

	/**
	 * WordPress filesystem for use.
	 *
	 * @return string
	 */
	public function get_error_logs($limit = -1)
	{
		$wp_filesystem  = $this->wp_filesystem();
		$contents = $wp_filesystem->get_contents_array($this->get_log_path());

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

	public function createErrorLog()
	{
		$directories = array(
			WP_CONTENT_DIR,
		);

		$log_directory = null;
		foreach ($directories as $log_directory) {
			$dir = wp_normalize_path($log_directory . DIRECTORY_SEPARATOR . 'metasync-error-logs');
			if (is_dir($dir)) {
				$log_directory = $dir;
				break;
			} else {
				if (@mkdir($dir, 0770)) {
					$log_directory = $dir;
					break;
				}
			}
		}

		$log_file = wp_normalize_path($log_directory . DIRECTORY_SEPARATOR . 'php-errors.log');
		if (!file_exists($log_file)) {
			@file_put_contents($log_file, '');
			@chmod($log_file, 0660);
		}

		return $log_file;
	}
}

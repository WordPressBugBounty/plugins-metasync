<?php

/**
 * The database operations for the 404 error monitor.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/404-monitor
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
 */
class Metasync_Common
{
	/**
	 * Sanitize a array with urls and text field.
	 * @param $data Pass a Array.
	 */
	public function sanitize_array($data)
	{
		foreach ((array) $data as $key => $value) {
			if (is_array($value) && !empty($value)) {
				$data[$key] =  $this->sanitize_array($value);
			} else {
				if ($value) {
					if (filter_var($value, FILTER_VALIDATE_URL)) {
						$data[$key] = sanitize_url($value);
					} else {
						$data[$key] = sanitize_text_field($value);
					}
				}
			}
		}
		return $data;
	}

	/**
	 * Get post by post name.
	 * @param $attachment_name Attachment name will be string.
	 */
	public function get_attachment_by_name($attachment_name)
	{
		global $wpdb;
		$post = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $wpdb->posts WHERE `post_name` = '%s' and `post_type` = 'attachment' LIMIT 1",
				$attachment_name
			)
		);
		return get_post($post);
	}

	public function get_permalink_from_url($url)
	{
		$parse_url = wp_parse_url($url);
		return end(array_diff(explode('/', $parse_url['path']), array('')));
	}

	/**
	 * Get file name from a URL.
	 * @param $url Valid URL as string.
	 */
	public function get_file_name_by_url($url)
	{
		if (stripos($url, "https://cdn.midjourney.com/") !== false) {
			return pathinfo(str_replace("/", "_", parse_url($url, PHP_URL_PATH)), PATHINFO_FILENAME);
		} elseif (stripos($url, "https://drive.google.com/") !== false) {
			$parse_url = wp_parse_url($url);
			$args = [];
			wp_parse_str($parse_url['query'], $args);
			return $args['id'];
		} else {
			return pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
		}
	}

	public function allowedDownloadSources($url)
	{
		return false;
		$allowed_sources = array(
			"https://storage.googleapis.com",
			"https://drive.google.com"
		);
		foreach ($allowed_sources as $source) {
			if (stripos($url, $source) !== false)
				return true;
		}
		return false;
	}

	/**
	 * Upload image to media library if URL is valid.
	 * @param $url Valid URL.
	 */
	public function upload_image_by_url($url,$alt='')
	{
		require_once(ABSPATH . "/wp-load.php");
		require_once(ABSPATH . "/wp-admin/includes/image.php");
		require_once(ABSPATH . "/wp-admin/includes/file.php");
		require_once(ABSPATH . "/wp-admin/includes/media.php");

		$tmp = download_url($url);
		if (is_wp_error($tmp)) return false;

		$filename  = $this->get_file_name_by_url($url);
		// $filename = pathinfo($url, PATHINFO_FILENAME);
		// eliminating query params from file name
		$filename = explode("?", $filename)[0];
		$extension = pathinfo($url, PATHINFO_EXTENSION);

		if (!$extension || strlen($extension) > 4) {
			$mime = mime_content_type($tmp);
			$mime = is_string($mime) ? sanitize_mime_type($mime) : false;

			$mime_extensions = array(
				'image/jpe' 	=> 'jpe',
				'image/jpg'		=> 'jpg',
				'image/jpeg'	=> 'jpeg',
				'image/gif'		=> 'gif',
				'image/png'		=> 'png',
				'image/webp'	=> 'webp'
			);

			if (isset($mime_extensions[$mime])) {
				$extension = $mime_extensions[$mime];
			} else {
				@unlink($tmp);
				return false;
			}
		}

		$args = array(
			'name' => "$filename.$extension",
			'post_name' => $filename."-".$extension,
			'tmp_name' => $tmp,
		);

		$get_attachment = $this->get_attachment_by_name($args['post_name']);
		
		if($args !== null && $args['post_name'] !== $get_attachment->post_name)
			$get_attachment = null;
		
		if ($get_attachment === null && empty($get_attachment)) {
			$attachment_id 	= media_handle_sideload($args, 0, $args['name']);
			update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
			$attach_data 	= wp_generate_attachment_metadata(
				$attachment_id,
				wp_get_original_image_path($attachment_id)
			);
			wp_update_attachment_metadata($attachment_id, $attach_data);
			@unlink($tmp);

			if (is_wp_error($attachment_id)) return false;
			return $attachment_id;
		} else {
			return $get_attachment->ID;
		}
	}
	/**
     * Check if Gutenberg is enabled as the default page editor
     */
    protected function is_gutenberg_enabled() {
        $current_screen = get_current_screen();
        return method_exists($current_screen, 'is_block_editor') && $current_screen->is_block_editor();
    }
   	/**
     * Check if Elementor is active
     */
    protected function is_elementor_active() {
        return class_exists('Elementor\Plugin') && \Elementor\Plugin::$instance->preview->is_preview_mode();
    }
	/**
     * Check if Gutenberg is enabled or Elementor is active
    */
    public static function check_default_page_editor() {
        if (is_gutenberg_enabled()) {
           return 'gutenberg';
        } elseif (is_elementor_active()) {
            return 'elementor';
        } else {
            return 'neither';          
        }
    }
}

<?php

/**
 * The Instant Indexing functionality of the Metasync plugin.
 *
 *
 * @link       http://linkgraph.io
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/instant-index
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
 */

// Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

class Metasync_Instant_Index
{

	/**
	 * Holds the default settings.
	 *
	 * @var array
	 */
	public $default_settings = [];

	/**
	 * URL of the Google plugin setup guide
	 *
	 * @var string
	 */
	public $google_guide_url = 'https://developers.google.com/search/apis/indexing-api/v3/quickstart';

	/**
	 * Constructor method.
	 */
	public function __construct()
	{
		$this->default_settings = [
			'json_key'   => '',
			'post_types' => [],
		];
	}

	/**
	 * Add links of Update and get status to posts of google instant indexing.
	 *
	 * @return void
	 */
	public function google_instant_index_post_link($actions, $post)
	{
		$post_types      = $this->get_setting('post_types', []);

		if (in_array($post->post_type, $post_types) && $post->post_status == 'publish') {
			$link = get_permalink($post);

			$actions['index-update'] = '<a href="' . admin_url("admin.php?page=metasync-settings-google-console&postaction=update&posturl=" . rawurlencode($link)) . '" title="" rel="permalink">Update Google Index</a>';
			$actions['index-status'] = '<a href="' . admin_url("admin.php?page=metasync-settings-google-console&postaction=status&posturl=" . rawurlencode($link)) . '" title="" rel="permalink">Status Google Index</a>';
		}
		return $actions;
	}

	/**
	 * Output Indexing API Settings page contents.
	 *
	 * @return void
	 */
	public function show_google_instant_indexing_settings()
	{
		include_once plugin_dir_path(__FILE__) . "../views/metasync-google-instant-settings.php";
	}

	/**
	 * Output checkbox inputs for the save post types.
	 *
	 * @param string $api API provider: "google".
	 * @return void
	 */
	public function google_instant_index_post_types()
	{
		$settings   = $this->get_setting('post_types', []);
		$post_types = get_post_types(['public' => true], 'objects');
		foreach ($post_types as $post_type) {
?>
			<label class="pr"><input type="checkbox" name="metasync_post_types[<?php echo esc_attr($post_type->name); ?>]" value="<?php echo esc_attr($post_type->name); ?>" <?php checked(in_array($post_type->name, $settings, true)); ?>> <?php echo esc_html($post_type->label); ?></label>
<?php
		}
	}

	/**
	 * Output Google Indexing API Console page Ui.
	 *
	 * @return void
	 */
	public function show_google_instant_indexing_console()
	{
		include_once plugin_dir_path(__FILE__) . '../views/metasync-google-console.php';
	}

	/**
	 * Save settings of google instant index.
	 *
	 * @return void
	 */
	public function save_settings()
	{
		$post_data =  sanitize_post($_POST);
		if (!isset($post_data['metasync_google_json_key'])) return;

		$settings = [];

		$json = sanitize_textarea_field(wp_unslash($post_data['metasync_google_json_key']));
		if (isset($_FILES['metasync_google_json_file']) && !empty($_FILES['metasync_google_json_file']['tmp_name']) && file_exists($_FILES['metasync_google_json_file']['tmp_name'])) {
			$json = wp_unslash(file_get_contents($_FILES['metasync_google_json_file']['tmp_name']));
		}

		$post_types = isset($post_data['metasync_post_types']) && is_array($post_data['metasync_post_types']) && !empty($json) ? array_map('sanitize_title', $post_data['metasync_post_types']) : [];

		$settings = $this->get_settings();

		$new_settings = [
			'json_key'   => $json,
			'post_types' => array_values($post_types),
		];

		$settings =  array_merge($settings, $new_settings);

		if (!empty($settings)) {
			update_option('metasync_options_instant_indexing', $settings);
		}
	}

	/**
	 * Normalize textarea input URLs.
	 *
	 * @return array Input URLs.
	 */
	public function get_input_urls()
	{
		$post_data =  sanitize_post($_POST);
		return array_values(array_filter(array_map('trim', explode("\n", sanitize_textarea_field(wp_unslash($post_data['metasync_giapi_url']))))));
	}

	/**
	 * Send the URLs to Google Instant Indexing Account
	 * @return void
	 */
	public function send()
	{
		$post_data =  sanitize_post($_POST);
		if (!isset($post_data['metasync_giapi_url'])) {
			return;
		}
		$send_url = $this->get_input_urls();

		if (!isset($post_data['metasync_giapi_action'])) {
			return;
		}
		$action = sanitize_title($post_data['metasync_giapi_action']);

		header('Content-type: application/json');

		$result = $this->google_api($send_url, $action);
		wp_send_json($result);
		wp_die();
	}

	/**
	 * Send one or more URLs to Google Instant Index API using their API SDK.
	 *
	 * @param array  $urls URLs.
	 * @param string $action Google endpoint action.
	 * @return array  $result_data response of the Google endpoint.
	 */
	public function google_api($urls, $action)
	{
		$urls = (array) $urls;

		require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

		$google_client = new Google_Client();

		$google_client->setAuthConfig(json_decode($this->get_setting('json_key'), true));
		$google_client->setConfig('base_path', 'https://indexing.googleapis.com');
		$google_client->addScope('https://www.googleapis.com/auth/indexing');

		// Bulk URLs request.
		$google_client->setUseBatch(true);
		// set google bulk urls and set site root URL.
		$indexing = new Google_Service_Indexing($google_client);

		$bulk_client   = new Google_Http_Batch($google_client, false, 'https://indexing.googleapis.com');

		foreach ($urls as $i => $url) {

			$payload = new Google_Service_Indexing_UrlNotification();

			if ($action === 'status') {
				$request = $indexing->urlNotifications->getMetadata(['url' => $url]); // phpcs:ignore
			} else {
				$payload->setType($action === 'update' ? 'URL_UPDATED' : 'URL_DELETED');
				$payload->setUrl($url);
				$request = $indexing->urlNotifications->publish($payload); // phpcs:ignore
			}

			$bulk_client->add($request, 'url-' . $i);
		}

		$results   = $bulk_client->execute();

		$result_data      = [];
		$res_count = count($results);
		foreach ($results as $id => $result) {

			if (is_a($result, 'Google_Service_Exception')) {
				$result_data[$id] = json_decode($result->getMessage());
			} else {
				$result_data[$id] = (array) $result->toSimpleObject();
			}
			if ($res_count === 1) {
				$result_data = $result_data[$id];
			}
		}

		return $result_data;
	}

	/**
	 * Get saved plugin setting.
	 *
	 * @param  string $setting.
	 * @return mixed  Settings.
	 */
	public function get_setting($setting)
	{
		$settings = $this->get_settings();

		return (isset($settings[$setting]) ? $settings[$setting] : null);
	}

	private function get_settings()
	{
		$setting = get_option('metasync_options_instant_indexing', []);

		$settings = array_merge($this->default_settings, $setting);

		return $settings;
	}
}

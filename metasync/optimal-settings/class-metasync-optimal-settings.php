<?php

/**
 * Optimal Settings functionality of the plugin.
 *
 *
 * @link       http://linkgraph.io
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/optimal-settings
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
 */

// Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

class Metasync_Optimal_Settings
{

	/**
	 * Add no index in thin pages and posts.
	 *
	 */
	public function add_robots_meta()
	{

		$post = get_post(get_the_ID());
		if (empty($post)) {
			return wp_robots_noindex(['noindex' => true]);
		};

		$common_robots_mata = Metasync::get_option('common_robots_mata') ?? [];
		$advance_robots_mata = Metasync::get_option('advance_robots_mata') ?? [];

		$common_robots_post_meta = get_post_meta($post->ID, 'metasync_common_robots', true);
		$advance_robots_post_meta = get_post_meta($post->ID, 'metasync_advance_robots', true);

		$common_robots = $common_robots_post_meta ? $common_robots_post_meta : $common_robots_mata;
		$advance_robots = $advance_robots_post_meta ? $advance_robots_post_meta : $advance_robots_mata;

		$robots          = ['index' => true];
		$advanced_robots = [];

		$robot_meta_keys	= [
			'index',
			'noindex',
			'nofollow',
			'noarchive',
			'noimageindex',
			'nosnippet',
		];

		foreach ($common_robots as $key => $value) {
			$length = false;
			if (in_array($key, $robot_meta_keys) && $value) {
				$length = true;
			}
			$robots[$value] = $length;
		}

		if (isset($robots['noindex'])) {
			unset($robots['index']);
		}

		if (!isset($common_robots['nosnippet'])) {

			foreach ($advance_robots as $key => $value) {
				if (isset($value['enable'])) {
					if ($value['length']) {
						$advanced_robots[$key] = $value['length'];
					}
				}
			}
		}

		// Add no index in thin content post
		$no_index_posts = get_option(Metasync::option_name)['optimal_settings']['no_index_posts'] ?? '';

		if ($no_index_posts) {
			$post_text =  wp_trim_words(get_the_content(), -1, null);
			$post_word_count =  str_word_count($post_text);
			$words_limit = '300';

			if ($post_word_count <= $words_limit) {
				unset($robots['index']);
				if (!isset($robots['noindex'])) {
					$robots['noindex'] = true;
				}
			}
		}

		if ($robots && $advanced_robots) {
			$robots = array_merge($robots, $advanced_robots);
		}

		return wp_robots_noindex($robots);
	}

	/**
	 * Add properties in external links of the post content
	 *
	 */
	public function add_attributes_external_links($content)
	{

		if (isset(get_option(Metasync::option_name)['optimal_settings'])) {

			$optimal_settings = get_option(Metasync::option_name)['optimal_settings'];

			$post = get_post();

			if (!empty($post) && !empty($content)) {

				libxml_use_internal_errors(true);

				$post_content = new DOMDocument();
				$post_content->loadHTML($content);
				$anchors = $post_content->getElementsByTagName('a');
				$images = $post_content->getElementsByTagName('img');

				/**
				 * Add target as blank in external links of the post content
				 */
				if (isset($optimal_settings['open_external_links'])) {

					foreach ($anchors as $anchor) {
						$match_url = strripos($anchor->getAttribute('href'), home_url());

						if ($match_url !== 0) {
							$anchor->setAttribute('target', '_blank');
						}
					}
					$content = $post_content->saveHTML();
				}

				/**
				 * Add rel as nofollow in external links of the post content
				 */
				if (isset($optimal_settings['no_follow_links'])) {

					foreach ($anchors as $anchor) {
						$match_url = strripos($anchor->getAttribute('href'), home_url());

						if ($match_url !== 0) {
							$anchor->setAttribute('rel', 'nofollow');
						}
					}
					$content = $post_content->saveHTML();
				}

				/**
				 * Add rel as nofollow in external links of the post content
				 */
				if (isset($optimal_settings['add_alt_image_tags'])) {

					foreach ($images as $image) {
						$alt = $image->getAttribute('alt');
						$src = $image->getAttribute('src');
						$image_filename = pathinfo($src, PATHINFO_FILENAME);
						$alt_change = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $image_filename)));

						if (!$alt && $alt_change) {
							$image->setAttribute('alt', $alt_change);
						}
					}

					$content = $post_content->saveHTML();
				}

				/**
				 * Add rel as nofollow in external links of the post content
				 */
				if (isset($optimal_settings['add_title_image_tags'])) {

					foreach ($images as $image) {
						$title = $image->getAttribute('title');
						$src = $image->getAttribute('src');
						$image_filename = pathinfo($src, PATHINFO_FILENAME);
						$title_change = trim(preg_replace('/[^A-Za-z0-9-]+/', ' ', $image_filename));

						if (!$title && $title_change) {
							$image->setAttribute('title', $title_change);
						}
					}
					$content = $post_content->saveHTML();
				}
			}
		}
		return $content;
	}

	/**
	 * Site Compatibility Status of Optimal Settings page callback
	 */
	public function site_compatible_status_view()
	{
		$phpversion = phpversion();
		$phpversion = explode('.', $phpversion);
		$php_version = esc_attr($phpversion[0] . '.' . $phpversion[1]);

		global $wp_version;
		$blog_version = explode('.', $wp_version);
		$wordpress_version = esc_attr($blog_version[0] . '.' . $blog_version[1]);

		$php_extentions = get_loaded_extensions();

		printf('<h2> Site Compatibility Check </h2>');

		printf('<ul>');

		if ($php_version < 7.4) {
			printf("<li class='red-text-color bold'> You are using PHP version:  %s. Recommended PHP 7.4 </li>", esc_html($php_version));
		} else {
			printf("<li class='green-text-color'> You are using recommended PHP version: %s </li>", esc_html($php_version));
		}

		if ($wordpress_version < 5.5) {
			printf("<li class='red-text-color bold'> You are using Wordpress version: %s. Recommended Wordpress 5.5 or above </li>", esc_html($wordpress_version));
		} else {
			printf("<li class='green-text-color'> You are using the recommended WordPress version. </li>");
		}

		if (!in_array('dom', $php_extentions)) {
			printf("<li class='red-text-color bold'> Install or Enable PHP DOM extension </li>");
		} else {
			printf("<li class='green-text-color'> PHP DOM Extension installed </li>");
		}

		if (!in_array('SimpleXML', $php_extentions)) {
			printf("<li class='red-text-color bold'> Install or Enable PHP SimpleXML extension </li>");
		} else {
			printf("<li class='green-text-color'> PHP SimpleXML Extension installed </li>");
		}

		if (!in_array('gd', $php_extentions)) {
			printf("<li class='red-text-color bold'> Install or Enable PHP GD extension </li>");
		} else {
			printf("<li class='green-text-color'> PHP GD Extension installed </li>");
		}

		if (!in_array('mbstring', $php_extentions)) {
			printf("<li class='red-text-color bold'> Install or Enable PHP MBstring extension </li>");
		} else {
			printf("<li class='green-text-color'> PHP MBstring Extension installed </li>");
		}

		if (!in_array('openssl', $php_extentions)) {
			printf("<li class='red-text-color bold'> Install or Enable PHP OpenSSL extension </li>");
		} else {
			printf("<li class='green-text-color'> PHP OpenSSL Extension installed </li>");
		}

		printf("</ul>");
	}
}

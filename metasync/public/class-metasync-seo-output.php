<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * SEO/front-end output functionality of the plugin.
 *
 * Handles all SEO meta tag output, structured data, robots directives,
 * and sitemap filtering for the public-facing side of the site.
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 *
 * @package    Metasync
 * @subpackage Metasync/public
 * @author     Engineering Team <support@searchatlas.com>
 */

class Metasync_Seo_Output
{
	private $plugin_name;
	private $version;
	private $common;
	private $escapers;
	private $replacements;
	private $metasync_option_data;

	public function __construct($plugin_name, $version)
	{
		$this->plugin_name = $plugin_name;
		$this->version = $version;
		$this->escapers = array("\\", "/", "\"");
		$this->replacements = array("", "", "");
		$this->common = new Metasync_Common();
		$this->metasync_option_data = Metasync::get_option('general');
	}

	function metasync_wp_robots_meta($robots)
	{
		foreach ($robots as $key => $value) {
			$robots[$key] = false;
		}
		return $robots;
	}

	public function print_metatag($name, $value, $valueAttrib = "content", $nameAttrib = "name", $tagName = "meta")
	{
		if (empty($value))
			return false;

		printf(
			"\t<%s %s=\"%s\" %s=\"%s\" />\n",
			esc_attr($tagName),
			esc_attr($nameAttrib),
			esc_attr($name),
			esc_attr($valueAttrib),
			esc_attr($value)
		);
	}

	/**
	 * Check if the current page is an AMP page
	 *
	 * @return bool True if AMP page, false otherwise
	 */
	public function is_amp_page()
	{
		// Check if URL path contains /amp/
		$current_url = $_SERVER['REQUEST_URI'] ?? '';
		if (strpos($current_url, '/amp/') !== false) {
			return true;
		}

		// Check if URL ends with /amp
		if (preg_match('/\/amp\/?$/', $current_url)) {
			return true;
		}

		// Check if amp=1 query parameter is present
		if (isset($_GET['amp']) && $_GET['amp'] == '1') {
			return true;
		}

		// Check for other common AMP query parameters
		if (isset($_GET['amp']) && !empty($_GET['amp'])) {
			return true;
		}

		return false;
	}

	/**
	 * Remove metasync_optimized attribute from head tag on AMP pages
	 * This function uses output buffering to clean the head content
	 */
	public function cleanup_amp_head_attribute()
	{
		// Only run on AMP pages
		if (!$this->is_amp_page()) {
			return;
		}

		// Start output buffering to capture and modify the head content
		ob_start(function($buffer) {
			// Remove metasync_optimized attribute from head tag
			$cleaned_buffer = preg_replace('/(<head[^>]*)\s*metasync_optimized(?:="[^"]*")?([^>]*>)/i', '$1$2', $buffer);

			return $cleaned_buffer;
		});
	}

	/**
	 * End output buffering for AMP cleanup
	 */
	public function end_amp_head_cleanup()
	{
		// Only run on AMP pages
		if (!$this->is_amp_page()) {
			return;
		}

		// End output buffering
		if (ob_get_level()) {
			ob_end_flush();
		}
	}

	public function hook_metasync_metatags()
	{
		$get_page_meta = get_post_meta(get_the_ID());

		// When a third-party SEO plugin is active, only output our description
		// if MetaSync has an intentional value (OTTO or sidebar). Otherwise let
		// the other plugin handle it to avoid duplicates.
		$conflict_handler = Metasync_SEO_Conflict_Handler::get_instance();
		$include_description = $conflict_handler->should_output_legacy_description();

		$list_page_meta = array(
			'robots' => $get_page_meta['meta_robots'][0] ?? 'index',
		);

		if ($include_description) {
			$list_page_meta['description'] = $get_page_meta['meta_description'][0] ?? '';
		}

		// Note: enable_metadesc is always enabled by default - no check needed

		$getSearchEngineOptions = Metasync::get_option('searchengines');
		$keysSearchEngines = [
			'bing_site_verification' => 'msvalidate.01',
			'baidu_site_verification' => 'baidu-site-verification',
			'alexa_site_verification' => 'alexaVerifyID',
			'yandex_site_verification' => 'yandex-verification',
			'google_site_verification' => 'google-site-verification',
			'pinterest_site_verification' => 'p:domain_verify',
			'norton_save_site_verification' => 'norton-safeweb-site-verification',
		];

		$post = get_post(get_the_ID());
		if (empty($post))
			return;

		# $post_text = wp_trim_words(get_the_content(), 30, '');

		# Check if the post has content, then apply WordPress content filters
		# $post_content = !empty($post->post_content) ? apply_filters('the_content', $post->post_content) : '';
		# $post_text = wp_trim_words($post_content, 30, '');

		/**
		 * Extract post content safely for meta descriptions
		 * This solution addresses three critical issues:
		 * 1. Timber compatibility - works without WordPress loop
		 * 2. Plugin conflicts - prevents shortcode execution (e.g., Hostify-booking function redeclaration)
		 * 3. Performance - lightweight content extraction for meta tags
		 */

		$post_text = '';

		# Get content safely without executing shortcodes
		$content = get_the_content(null, false, $post);

		# Validate content exists
		if (!empty($content)) {
			# Clean content for meta description - multi-layer approach for reliability
			$content = strip_shortcodes($content);           # Remove shortcode tags (e.g., [hostify-booking])
			$content = wp_strip_all_tags($content);          # Remove HTML tags
			$content = preg_replace('/\s+/', ' ', $content); # Normalize whitespace
			$post_text = wp_trim_words(trim($content), 30, '');
		}

		$site_info = Metasync::get_option('optimal_settings')['site_info'] ?? [];

		$facebook_page_url = Metasync::get_option('social_meta')['facebook_page_url'] ?? '';
		$facebook_authorship = Metasync::get_option('social_meta')['facebook_authorship'] ?? '';
		$facebook_admin = Metasync::get_option('social_meta')['facebook_admin'] ?? '';

		$twitter_username = Metasync::get_option('social_meta')['twitter_username'] ?? '';

		$image = [];
		$image_mime_type = '';

		// SAFE IMAGE HANDLING: Prevents timeout when images are deleted from filesystem
		// Constructs URLs directly from metadata without triggering WordPress HTTP validation
		if ($post) {
			$image_id = get_post_thumbnail_id($post->ID);

			if ($image_id) {
				// Verify attachment exists in database
				$attachment = get_post($image_id);

				if ($attachment && $attachment->post_type === 'attachment') {
					// Check if physical file exists before constructing URL
					$file_path = get_attached_file($image_id);

					if ($file_path && file_exists($file_path)) {
						// Get metadata to construct URL directly
						$metadata = wp_get_attachment_metadata($image_id);

						if ($metadata && !empty($metadata['file'])) {
							$upload_dir = wp_upload_dir();
							$image_url = $upload_dir['baseurl'] . '/' . $metadata['file'];

							// Get image dimensions from metadata (not from file)
							$width = $metadata['width'] ?? 0;
							$height = $metadata['height'] ?? 0;

							// Determine MIME type from file extension (safe, no HTTP calls)
							$file_ext = strtolower(pathinfo($metadata['file'], PATHINFO_EXTENSION));
							$mime_types = [
								'jpg' => 'image/jpeg',
								'jpeg' => 'image/jpeg',
								'png' => 'image/png',
								'gif' => 'image/gif',
								'webp' => 'image/webp',
								'svg' => 'image/svg+xml'
							];
							$image_mime_type = $mime_types[$file_ext] ?? 'image/jpeg';

							// Build image array in same format as wp_get_attachment_image_src
							$image = [$image_url, $width, $height];
						}
					} else {
						// File doesn't exist - clean up orphaned thumbnail reference
						delete_post_meta($post->ID, '_thumbnail_id');
					}
				} else {
					// Attachment doesn't exist - clean up orphaned reference
					delete_post_meta($post->ID, '_thumbnail_id');
				}
			}
		}

		// Fallback to site default image if post has no featured image
		if (empty($image) && $site_info && isset($site_info['social_share_image'])) {
			$fallback_id = $site_info['social_share_image'];
			$attachment = get_post($fallback_id);

			if ($attachment && $attachment->post_type === 'attachment') {
				$file_path = get_attached_file($fallback_id);

				if ($file_path && file_exists($file_path)) {
					$metadata = wp_get_attachment_metadata($fallback_id);

					if ($metadata && !empty($metadata['file'])) {
						$upload_dir = wp_upload_dir();
						$image_url = $upload_dir['baseurl'] . '/' . $metadata['file'];
						$width = $metadata['width'] ?? 0;
						$height = $metadata['height'] ?? 0;

						$file_ext = strtolower(pathinfo($metadata['file'], PATHINFO_EXTENSION));
						$mime_types = [
							'jpg' => 'image/jpeg',
							'jpeg' => 'image/jpeg',
							'png' => 'image/png',
							'gif' => 'image/gif',
							'webp' => 'image/webp',
							'svg' => 'image/svg+xml'
						];
						$image_mime_type = $mime_types[$file_ext] ?? 'image/jpeg';

						$image = [$image_url, $width, $height];
					}
				}
			}
		}


		// When a third-party SEO plugin handles OG/Twitter, omit our description
		// from those tags to avoid duplicates.
		$og_description = ($include_description) ? ($post_text ?? '') : '';

		$ogMetaKeys = [
			'og:locale' => get_locale(),
			'og:type' => 'article',
			'og:title' => $post->post_title . ' - ' . get_bloginfo('name'),
			'og:description' => $og_description,
			'og:url' => $this->get_canonical_url($post),
			'og:site_name' => get_bloginfo('name'),
			'og:updated_time' => $post->post_modified,
			'og:image' => $image ? $image[0] : '',
			'og:image:width' => $image ? $image[1] : '',
			'og:image:height' => $image ? $image[2] : '',
			'og:image:type' => $image ? $image_mime_type : '',
			'og:image:alt' => $image ? $post->post_title : '',
		];

		$facebookMetaKeys = [
			'article:publisher' => $facebook_page_url && !filter_var($facebook_page_url, FILTER_VALIDATE_URL) ? 'https://' . $facebook_page_url : $facebook_page_url,
			'article:author' => $facebook_authorship && !filter_var($facebook_authorship, FILTER_VALIDATE_URL) ? 'https://' . $facebook_authorship : $facebook_authorship,
			'fb:admins' => $facebook_admin,
		];

		$twitter_card_type = Metasync::get_option('twitter_card_type') ?? [];

		$twitterMetaKeys = [
			'twitter:card' => $twitter_card_type ? $twitter_card_type : 'summary_large_image',
			'twitter:title' => $post->post_title . ' - ' . get_bloginfo('name'),
			'twitter:site' => $twitter_username ? '@' . $twitter_username : '',
			'twitter:creator' => $twitter_username ? '@' . $twitter_username : '',
			'twitter:description' => $og_description,
			'twitter:image' => $image ? $image[0] : '',
		];

		// echo "\t<!-- MetaSync metadata -->\n";

		foreach ($list_page_meta as $item => $value) {
			if ($item == 'canonical') {
				$this->print_metatag($item, $value, 'href', 'rel', 'link');
				continue;
			}
			$this->print_metatag($item, $value);
		}

		if ($getSearchEngineOptions !== null) { // check if searchengine verification options are set
			foreach ($keysSearchEngines as $optionKey => $metaKey) {
				$this->print_metatag($metaKey, $getSearchEngineOptions[$optionKey]);
			}
		}

		if ($post) {

			$common_meta_settings = Metasync::get_option('common_meta_settings') ?? [];

			if (isset($common_meta_settings['facebook_meta_tags'])) {
				foreach ($facebookMetaKeys as $metaKey => $metaValue) {
					$this->print_metatag($metaKey, $metaValue, 'content', 'property');
				}
			}

			if (isset($common_meta_settings['open_graph_meta_tags'])) {
				foreach ($ogMetaKeys as $metaKey => $metaValue) {
					$this->print_metatag($metaKey, $metaValue, 'content', 'property');
				}
			}
			if (isset($common_meta_settings['twitter_meta_tags'])) {
				foreach ($twitterMetaKeys as $metaKey => $metaValue) {
					$this->print_metatag($metaKey, $metaValue, 'content', 'name');
				}
			}
		}

		$this->facebook_graph_cache();




	}

	public function add_ld_json()
	{
		$post = get_post(get_the_ID());
		if (empty($post))
			return;

		$site_info = Metasync::get_option('optimal_settings')['site_info'] ?? '';

		$site_logo_id = $site_info['google_logo'] ?? '';
		$custom_logo_id = get_theme_mod('custom_logo');
		$logo_id = $custom_logo_id != '' ? $custom_logo_id : $site_logo_id;

		$site_image_id = $site_info['social_share_image'] ?? '';
		$post_thumbnail_id = get_post_thumbnail_id($post->ID);
		$thumbnail_id = $post_thumbnail_id > 0 ? $post_thumbnail_id : $site_image_id;

		$schema = array(
			'@context' => "http://schema.org",
			'@type' => "Article",
			'headline' => str_replace($this->escapers, $this->replacements, $post->post_title ?? ''),
			'image' => wp_get_attachment_image_url($thumbnail_id, 'full'),
			'url' => get_permalink(),
			'datePublished' => $post->post_modified,
			'author' => array(
				'@type' => "Person",
				'name' => get_the_author_meta('display_name', $post->post_author),
				'url' => get_author_posts_url($post->post_author),
			),
			'publisher' => array(
				'@type' => "Organization",
				'name' => str_replace($this->escapers, $this->replacements, get_bloginfo('name') ?? ''),
				'url' => get_site_url(),
				'logo' => array(
					'@type' => "ImageObject",
					'url' => wp_get_attachment_image_url($logo_id, 'full'),
				)
			)
		);

		return $schema;
	}

	public function facebook_graph_cache()
	{
		$facebook_app = Metasync::get_option('social_meta')['facebook_app'] ?? '';
		$facebook_secret = Metasync::get_option('social_meta')['facebook_secret'] ?? '';

		// Early bail!
		if (!$facebook_app || !$facebook_secret) {
			return;
		}

		# PERFORMANCE OPTIMIZATION: Add timeout and error handling
		$response = wp_remote_post(
			'https://graph.facebook.com/',
			[
				'body' => [
					'id' => $facebook_app,
					'access_token' => $facebook_secret,
				],
				'timeout' => 5,
			]
		);

		# Error handling (fail silently, not critical)
		if (is_wp_error($response)) {
			error_log('MetaSync: Facebook Graph API failed: ' . $response->get_error_message());
		}
	}

	/*
	* This will hide the title on single posts and pages
	* if they were created with the "metasync" system.
	* Passes default values for $title and $id to avoid errors.
	*/
	public function hide_title_on_otto_pages($title = '', $id = null){

		# Return title immediately if $id or $title is not provided
		if (empty($id) || empty($title)) {
			return $title;
		}

		# Check if it's a single post or page
		if ((is_single() || is_page()) && in_the_loop() && is_main_query()) {

			# Check if the post was created with the "metasync" system
			$metasync_post = get_post_meta($id, 'metasync_post', true);
			if ($metasync_post === 'yes') {
				return '';
			}
		}
		return $title;
	}

	/**
	 * Get the canonical URL for a post
	 */
	private function get_canonical_url($post) {
		# Try to get the permalink using WordPress function
		$permalink = get_permalink($post->ID);

		# If permalink is not available or is the default query URL, try alternative methods
		if (!$permalink || strpos($permalink, '?p=') !== false || strpos($permalink, '?page_id=') !== false) {
			# Force WordPress to generate the proper permalink by temporarily setting post status
			$original_status = $post->post_status;
			if ($post->post_status === 'auto-draft') {
				$post->post_status = 'publish';
			}

			# Try get_permalink again with the updated status
			$permalink = get_permalink($post->ID);

			# Restore original status
			$post->post_status = $original_status;
		}

		# If still not working, use WordPress core functions to build proper permalink
		if (!$permalink || strpos($permalink, '?p=') !== false || strpos($permalink, '?page_id=') !== false) {
			# Use WordPress core function that respects permalink structure
			# This properly handles custom structures, hierarchies, and post types
			# Load admin function if not already available, Without this it is causing error on post the preview page
			if (!function_exists('get_sample_permalink')) {
				require_once ABSPATH . 'wp-admin/includes/post.php';
			}
			$permalink = get_sample_permalink($post->ID);

			if (is_array($permalink)) {
				# get_sample_permalink returns array with template and slug
				# Replace %postname% or %pagename% with actual slug
				$permalink = str_replace(
					array('%pagename%', '%postname%'),
					$post->post_name,
					$permalink[0]
				);
			}

			# Final fallback: if still problematic, construct URL respecting post type structure
			if (!$permalink || strpos($permalink, '?p=') !== false || strpos($permalink, '?page_id=') !== false) {
				if (!empty($post->post_name)) {
					# For pages, check if there's a parent hierarchy
					if ($post->post_type === 'page' && $post->post_parent) {
						# Get parent page path for proper hierarchy
						$parent = get_post($post->post_parent);
						$parent_path = '';

						# Build full path including all parent pages
						while ($parent) {
							$parent_path = $parent->post_name . '/' . $parent_path;
							$parent = $parent->post_parent ? get_post($parent->post_parent) : null;
						}

						$permalink = home_url('/' . $parent_path . $post->post_name . '/');
					} else {
						# For posts and pages without parents, use post type archive base
						$post_type_obj = get_post_type_object($post->post_type);
						$slug = $post_type_obj->rewrite['slug'] ?? '';

						if ($slug && $post->post_type !== 'page') {
							$permalink = home_url('/' . $slug . '/' . $post->post_name . '/');
						} else {
							$permalink = home_url('/' . $post->post_name . '/');
						}
					}
				} else {
					# Fallback to post ID format if no slug available
					$permalink = home_url('/?p=' . $post->ID);
				}
			}
		}

		return $permalink;
	}

	/**
	 * Set up indexation controls for archive pages
	 *
	 * Called via template_redirect hook to set up early before any output.
	 * This ensures we can capture and clean robots tags from other plugins.
	 *
	 * Logic:
	 * 1. If user wants to add noindex - always do it (override other plugins)
	 * 2. If user wants to allow indexing - only remove other plugins' tags if override setting is enabled
	 *
	 * @since 1.0.0
	 */
	public function inject_archive_seo_controls() {
		// Check if we're on a managed archive type
		if (!$this->is_managed_archive()) {
			return;
		}

		// Get settings
		$seo_controls = Metasync::get_option('seo_controls', array());
		$should_noindex = $this->should_noindex_archive();
		$override_enabled = ($seo_controls['override_robots_tags'] ?? 'false') === 'true' || ($seo_controls['override_robots_tags'] ?? false) === true;

		// Run buffer if either:
		// 1. User wants to add noindex (always override other plugins), OR
		// 2. User wants to allow indexing AND override setting is enabled
		if ($should_noindex || $override_enabled) {
			// Override WordPress core robots tag
			add_filter('wp_robots', array($this, 'override_wp_robots'), 999);

			// Start buffering to remove other plugins' robots tags
			add_action('wp_head', array($this, 'start_robots_buffer'), 0);
			add_action('wp_head', array($this, 'end_robots_buffer'), PHP_INT_MAX);
		}
		// Otherwise: User wants to allow indexing AND override is disabled - don't interfere
	}

	/**
	 * Check if current page is a managed archive type
	 *
	 * Returns true if we're on any archive type that has indexation controls,
	 * regardless of whether noindex is enabled or not.
	 *
	 * @since 1.0.0
	 * @return bool True if on a managed archive type, false otherwise
	 */
	private function is_managed_archive() {
		return is_date() || is_tag() || is_author() || is_category() || is_tax('post_format');
	}

	/**
	 * Check if current archive is empty (has no posts)
	 *
	 * Applies to category, tag, author, and post format archives.
	 * Date archives are excluded as they're typically either indexed
	 * or not entirely (less meaningful to check for empty state).
	 *
	 * @since 1.0.0
	 * @return bool True if archive is empty (0 posts)
	 */
	private function is_empty_archive() {
		global $wp_query;

		// Check taxonomy and author archives (excluding date archives)
		if (!is_category() && !is_tag() && !is_author() && !is_tax('post_format')) {
			return false;
		}

		// Check if the archive has no posts
		return $wp_query->post_count === 0;
	}

	/**
	 * Check if current archive should be noindexed
	 *
	 * @since 1.0.0
	 * @return bool True if should add noindex, false otherwise
	 */
	private function should_noindex_archive() {
		// Get indexation control settings
		$seo_controls = Metasync::get_option('seo_controls', array());

		// Check empty archives setting first (applies to categories/tags only)
		$noindex_empty_archives = $seo_controls['noindex_empty_archives'] ?? false;
		if (($noindex_empty_archives === 'true' || $noindex_empty_archives === true) && $this->is_empty_archive()) {
			return true;
		}

		// Date Archives
		if (is_date()) {
			$index_date_archives = $seo_controls['index_date_archives'] ?? false;
			// Handle both string and boolean values for compatibility
			if ($index_date_archives === 'true' || $index_date_archives === true) {
				return true;
			}
		}

		// Tag Archives
		elseif (is_tag()) {
			$index_tag_archives = $seo_controls['index_tag_archives'] ?? false;
			// Handle both string and boolean values for compatibility
			if ($index_tag_archives === 'true' || $index_tag_archives === true) {
				return true;
			}
		}

		// Author Archives
		elseif (is_author()) {
			$index_author_archives = $seo_controls['index_author_archives'] ?? false;
			// Handle both string and boolean values for compatibility
			if ($index_author_archives === 'true' || $index_author_archives === true) {
				return true;
			}
		}

		// Category Archives
		elseif (is_category()) {
			$index_category_archives = $seo_controls['index_category_archives'] ?? false;
			// Handle both string and boolean values for compatibility
			if ($index_category_archives === 'true' || $index_category_archives === true) {
				return true;
			}
		}

		// Format Archives (post format taxonomy)
		elseif (is_tax('post_format')) {
			$index_format_archives = $seo_controls['index_format_archives'] ?? false;
			if ($index_format_archives === 'true' || $index_format_archives === true) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Override WordPress core robots directives
	 *
	 * Handles both cases:
	 * 1. When noindex is enabled: Add noindex directive
	 * 2. When indexing is allowed: Return empty array (removes WP core robots tag)
	 *
	 * @since 1.0.0
	 * @param array $robots Associative array of robots directives
	 * @return array Modified robots directives
	 */
	public function override_wp_robots($robots) {
		if ($this->should_noindex_archive()) {
			// Case 1: Add noindex directive
			return array(
				'noindex' => true,
				'follow' => true,
				'max-image-preview' => 'large', // Maintain good image preview for social sharing
			);
		} else {
			// Case 2: Allow indexing - return empty array to remove WP core robots tag
			// Default behavior without robots tag is to index
			return array();
		}
	}

	/**
	 * Start output buffering to capture robots meta tags from other plugins
	 *
	 * @since 1.0.0
	 */
	public function start_robots_buffer() {
		ob_start();
	}

	/**
	 * End output buffering, remove existing robots tags, and optionally add ours
	 *
	 * This handles BOTH cases:
	 * 1. When noindex is enabled: Remove other tags and add noindex tag
	 * 2. When indexing is allowed: Remove other plugins' noindex tags
	 *
	 * This ensures our indexation settings always take precedence over other plugins.
	 *
	 * @since 1.0.0
	 */
	public function end_robots_buffer() {
		// Only proceed if we're on a managed archive
		if (!$this->is_managed_archive()) {
			ob_end_flush();
			return;
		}

		// Get buffered content
		$content = ob_get_clean();

		// Remove all existing robots meta tags (handles various formats)
		// This is done for BOTH noindex and index cases to ensure clean slate

		// Pattern 1: name="robots" content="..."
		$content = preg_replace(
			'/<meta\s+name=["\']robots["\']\s+content=["\'][^"\']*["\']\s*\/?>\s*/i',
			'',
			$content
		);

		// Pattern 2: content="..." name="robots"
		$content = preg_replace(
			'/<meta\s+content=["\'][^"\']*["\']\s+name=["\']robots["\']\s*\/?>\s*/i',
			'',
			$content
		);

		// Pattern 3: Single quotes or no quotes (rare but possible)
		$content = preg_replace(
			"/<meta\s+name='robots'\s+content='[^']*'\s*\/?>\s*/i",
			'',
			$content
		);

		// Pattern 4: property="robots" (some plugins use property attribute)
		$content = preg_replace(
			'/<meta\s+property=["\']robots["\']\s+content=["\'][^"\']*["\']\s*\/?>\s*/i',
			'',
			$content
		);

		// Pattern 5: Search engine specific directives (googlebot, bingbot, etc.)
		$content = preg_replace(
			'/<meta\s+name=["\'](?:googlebot|bingbot|googlebot-news|slurp)["\']\s+content=["\'][^"\']*["\']\s*\/?>\s*/i',
			'',
			$content
		);

		// Output cleaned content
		echo $content;

		// Add our robots tag or comment based on settings
		if ($this->should_noindex_archive()) {
			// Case 1: User wants to disallow indexing - add noindex tag
			echo '<!-- MetaSync Indexation Control: Noindex Applied (Overriding other plugins) -->' . "\n";
			echo '<meta name="robots" content="noindex, follow">' . "\n";
		} else {
			// Case 2: User wants to allow indexing AND override is enabled
			// Just remove other plugins' tags, don't add our own
			// Default behavior without robots tag is to allow indexing
			echo '<!-- MetaSync Indexation Control: Index Allowed (Override enabled - Other noindex tags removed) -->' . "\n";
		}
	}

	/**
	 * Filter taxonomy sitemap entries to exclude disabled archive types
	 *
	 * @since 1.0.0
	 * @param array $taxonomies Array of taxonomy objects
	 * @return array Modified array of taxonomy objects
	 */
	public function filter_sitemap_taxonomies($taxonomies) {
		// Get indexation control settings
		$seo_controls = Metasync::get_option('seo_controls', array());

		// Check if tag archives are disabled
		$index_tag_archives = $seo_controls['index_tag_archives'] ?? false;
		if ($index_tag_archives === 'true' || $index_tag_archives === true) {
			// Remove post_tag taxonomy from sitemap
			unset($taxonomies['post_tag']);
		}

		// Check if category archives are disabled
		$index_category_archives = $seo_controls['index_category_archives'] ?? false;
		if ($index_category_archives === 'true' || $index_category_archives === true) {
			// Remove category taxonomy from sitemap
			unset($taxonomies['category']);
		}

		// Check if format archives are disabled
		$index_format_archives = $seo_controls['index_format_archives'] ?? false;
		if ($index_format_archives === 'true' || $index_format_archives === true) {
			// Remove post_format taxonomy from sitemap
			unset($taxonomies['post_format']);
		}

		return $taxonomies;
	}

	/**
	 * Filter user sitemap entries to exclude disabled author archives
	 *
	 * @since 1.0.0
	 * @param array $entry Sitemap entry for user
	 * @param WP_User $user User object
	 * @return array|false Modified sitemap entry or false to exclude
	 */
	public function filter_sitemap_users($entry, $user) {
		// Get indexation control settings
		$seo_controls = Metasync::get_option('seo_controls', array());

		// Check if author archives are disabled
		$index_author_archives = $seo_controls['index_author_archives'] ?? false;
		if ($index_author_archives === 'true' || $index_author_archives === true) {
			// Exclude this user from sitemap
			return false;
		}

		return $entry;
	}

	/**
	 * Filter sitemap providers to exclude disabled archive types
	 *
	 * @since 1.0.0
	 * @param bool $provider Whether to add the provider
	 * @param string $name Provider name
	 * @return bool Whether to add the provider
	 */
	public function filter_sitemap_providers($provider, $name) {
		// Get indexation control settings
		$seo_controls = Metasync::get_option('seo_controls', array());

		// Check if users/authors sitemap provider should be disabled
		if ($name === 'users') {
			$index_author_archives = $seo_controls['index_author_archives'] ?? false;
			if ($index_author_archives === 'true' || $index_author_archives === true) {
				return false;  // Exclude users sitemap provider entirely
			}
		}

		return $provider;
	}

	/**
	 * Filter sitemap index entries to exclude disabled archive types
	 *
	 * @since 1.0.0
	 * @param array $sitemap_entry Sitemap entry array
	 * @param string $object_type Object type (posts, taxonomies, users)
	 * @param string $subtype Subtype (post type or taxonomy name)
	 * @param int $page Page number
	 * @return array|false Modified sitemap entry or false to exclude
	 */
	public function filter_sitemap_index_entries($sitemap_entry, $object_type, $subtype, $page) {
		// Get indexation control settings
		$seo_controls = Metasync::get_option('seo_controls', array());

		// Handle taxonomy sitemaps
		if ($object_type === 'taxonomies') {
			// Check if tag archives are disabled
			if ($subtype === 'post_tag') {
				$index_tag_archives = $seo_controls['index_tag_archives'] ?? false;
				if ($index_tag_archives === 'true' || $index_tag_archives === true) {
					return false; // Exclude from sitemap index
				}
			}

			// Check if category archives are disabled
			if ($subtype === 'category') {
				$index_category_archives = $seo_controls['index_category_archives'] ?? false;
				if ($index_category_archives === 'true' || $index_category_archives === true) {
					return false; // Exclude from sitemap index
				}
			}

			// Check if format archives are disabled
			if ($subtype === 'post_format') {
				$index_format_archives = $seo_controls['index_format_archives'] ?? false;
				if ($index_format_archives === 'true' || $index_format_archives === true) {
					return false; // Exclude from sitemap index
				}
			}
		}

		// Handle user/author sitemaps
		if ($object_type === 'users') {
			$index_author_archives = $seo_controls['index_author_archives'] ?? false;
			if ($index_author_archives === 'true' || $index_author_archives === true) {
				return false; // Exclude from sitemap index
			}
		}

		return $sitemap_entry;
	}
}

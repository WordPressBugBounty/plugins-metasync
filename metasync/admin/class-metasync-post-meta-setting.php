<?php

/**
 * The header and footer code snippets functionality of the plugin.
 *
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/admin
 * @author     Engineering Team <support@searchatlas.com>
 */

// Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

class Metasync_Post_Meta_Settings
{
	private $common;

	public function __construct()
	{
		$this->common = new Metasync_Common();

		add_action('admin_init', [$this, 'add_post_meta_data'], 2);
		add_action('save_post', [$this, 'common_robots_meta_box_save']);
		add_action('save_post', [$this, 'advance_robots_meta_box_save']);
		add_action('save_post', [$this, 'redirection_meta_box_save']);
		add_action('save_post', [$this, 'canonical_meta_box_save']);
		add_action('save_post', [$this, 'video_sitemap_meta_box_save']);
		add_action('save_post', [$this, 'seo_meta_box_save']);
	}

	public function add_post_meta_data()
	{
		// Don't show meta boxes if user's role doesn't have plugin access
		if (!Metasync::current_user_has_plugin_access()) {
			return;
		}

		$plugin_name = Metasync::get_effective_plugin_name();
		$general_settings = Metasync::get_option('general', []);
		$post_types = array_values(get_post_types(['public' => true], 'names'));
		$post_types = array_diff($post_types, ['attachment']);

		// Only add meta boxes if not disabled in settings
		if (empty($general_settings['disable_common_robots_metabox'])) {
			add_meta_box('common-robots-meta', "Common Robots Meta by $plugin_name", [$this, 'common_robots_meta_box_display'], $post_types, 'normal', 'default');
		}

		if (empty($general_settings['disable_advance_robots_metabox'])) {
			add_meta_box('advance-robots-meta', "Advance Robots Meta by $plugin_name", [$this, 'advance_robots_meta_box_display'], $post_types, 'normal', 'default');
		}

		if (empty($general_settings['disable_redirection_metabox'])) {
			add_meta_box('post-redirection-meta', "Redirection by $plugin_name", [$this, 'post_redirection_display'], $post_types, 'normal', 'default');
		}

		if (empty($general_settings['disable_canonical_metabox'])) {
			add_meta_box('post-canonical-meta', "Canonical by $plugin_name", [$this, 'post_canonical_display'], $post_types, 'normal', 'default');
		}

		if (empty($general_settings['disable_seo_metabox'])) {
			add_meta_box('metasync-seo-meta', "SEO by $plugin_name", [$this, 'seo_meta_box_display'], $post_types, 'normal', 'default', ['__back_compat_meta_box' => true]);
		}

		// Video Sitemap meta box — only if video sitemap is enabled
		$video_settings = get_option('metasync_video_sitemap_settings', []);
		if (!empty($video_settings['enabled'])) {
			$video_post_types = !empty($video_settings['post_types']) ? (array) $video_settings['post_types'] : ['post', 'page'];
			add_meta_box(
				'metasync-video-sitemap-meta',
				"Video Sitemap by $plugin_name",
				[$this, 'video_sitemap_meta_box_display'],
				$video_post_types,
				'normal',
				'default'
			);
		}
	}

	public function common_robots_meta_box_display()
	{
		global $post;
		$post_meta_robots = get_post_meta($post->ID, 'metasync_common_robots', true);
		// Check new spelling first, fall back to old for backward compatibility
		$common_meta_robots = Metasync::get_option('common_robots_meta') ?? Metasync::get_option('common_robots_mata') ?? '';
		$common_robots = $post_meta_robots ? $post_meta_robots : $common_meta_robots;
		wp_nonce_field('metasync_common_robots_nonce', 'metasync_common_robots_nonce');
?>
		<ul class="checkbox-list">
			<li>
				<input type="checkbox" name="common_robots_meta[index]" id="robots_common1" value="index" <?php isset($common_robots['index']) ? checked('index', $common_robots['index']) : '' ?>>
				<label for="robots_common1">Index </br>
					<span class="description">
						<span>Search engines to index and show these pages in the search results.</span>
					</span>
				</label>
			</li>
			<li>
				<input type="checkbox" name="common_robots_meta[noindex]" id="robots_common2" value="noindex" <?php isset($common_robots['noindex']) ? checked('noindex', $common_robots['noindex']) : '' ?>>
				<label for="robots_common2">No Index </br>
					<span class="description">
						<span>Prevents search engines from indexing and displaying these pages in search results.</span>
					</span>
				</label>
			</li>
			<li>
				<input type="checkbox" name="common_robots_meta[nofollow]" id="robots_common3" value="nofollow" <?php isset($common_robots['nofollow']) ? checked('nofollow', $common_robots['nofollow']) : '' ?>>
				<label for="robots_common3">No Follow </br>
					<span class="description">
						<span>Prevents search engines from following the links on the pages.</span>
					</span>
				</label>
			</li>
			<li>
				<input type="checkbox" name="common_robots_meta[noarchive]" id="robots_common4" value="noarchive" <?php isset($common_robots['noarchive']) ? checked('noarchive', $common_robots['noarchive']) : '' ?>>
				<label for="robots_common4">No Archive </br>
					<span class="description">
						<span>Prevents search engines from showing cached links for pages.</span>
					</span>
				</label>
			</li>
			<li>
				<input type="checkbox" name="common_robots_meta[noimageindex]" id="robots_common5" value="noimageindex" <?php isset($common_robots['noimageindex']) ? checked('noimageindex', $common_robots['noimageindex']) : '' ?>>
				<label for="robots_common5">No Image Index </br>
					<span class="description">
						<span>Prevents your pages from appearing as the referring page for images in image search results.</span>
					</span>
				</label>
			</li>
			<li>
				<input type="checkbox" name="common_robots_meta[nosnippet]" id="robots_common6" value="nosnippet" <?php isset($common_robots['nosnippet']) ? checked('nosnippet', $common_robots['nosnippet']) : '' ?>>
				<label for="robots_common6">No Snippet </br>
					<span class="description">
						<span>Prevents search engines from showing a snippet in the search results.</span>
					</span>
				</label>
			</li>
		</ul>

	<?php
	}

	public function advance_robots_meta_box_display()
	{
		global $post;
		$post_meta_robots = get_post_meta($post->ID, 'metasync_advance_robots', true);
		// Check new spelling first, fall back to old for backward compatibility
		$common_meta_robots = Metasync::get_option('advance_robots_meta') ?? Metasync::get_option('advance_robots_mata') ?? '';
		$advance_robots = $post_meta_robots ? $post_meta_robots : $common_meta_robots;
		$snippet_advance_robots_enable = $advance_robots['max-snippet']['enable'] ?? '';
		$snippet_advance_robots_length = $advance_robots['max-snippet']['length'] ?? '';
		$video_advance_robots_enable = $advance_robots['max-video-preview']['enable'] ?? '';
		$video_advance_robots_length = $advance_robots['max-video-preview']['length'] ?? '';
		$image_advance_robots_enable = $advance_robots['max-image-preview']['enable'] ?? '';
		$image_advance_robots_length = $advance_robots['max-image-preview']['length'] ?? '';
		wp_nonce_field('metasync_advance_robots_nonce', 'metasync_advance_robots_nonce');
	?>
		<ul class="checkbox-list">
			<li>
				<label for="advanced_robots_snippet">
					<input type="checkbox" name="advanced_robots_meta[max-snippet][enable]" id="advanced_robots_snippet" value="1" <?php checked('1', esc_attr($snippet_advance_robots_enable)) ?>>
					Snippet </br>
					<input type="number" class="input-length" name="advanced_robots_meta[max-snippet][length]" id="advanced_robots_snippet_value" value="<?php echo esc_attr($snippet_advance_robots_length); ?>" min="-1"> </br>
					<span class="description">
						<span>Add maximum text-length, in characters, of a snippet for your page.</span>
					</span>
				</label>
			</li>
			<li>
				<label for="advanced_robots_video">
					<input type="checkbox" name="advanced_robots_meta[max-video-preview][enable]" id="advanced_robots_video" value="1" <?php checked('1', esc_attr($video_advance_robots_enable)) ?>>
					Video Preview </br>
					<input type="number" class="input-length" name="advanced_robots_meta[max-video-preview][length]" id="advanced_robots_video_value" value="<?php echo esc_attr($video_advance_robots_length); ?>" min="-1"> </br>
					<span class="description">
						<span>Add maximum duration in seconds of an animated video preview.</span>
					</span>
				</label>
			</li>
			<li>
				<label for="advanced_robots_image">
					<input type="checkbox" name="advanced_robots_meta[max-image-preview][enable]" id="advanced_robots_image" value="1" <?php checked('1', esc_attr($image_advance_robots_enable)) ?>>
					Image Preview </br>
					<select class="input-length" name="advanced_robots_meta[max-image-preview][length]' ?>" id="advanced_robots_image_value">
						<option value="large" <?php selected(esc_attr($image_advance_robots_length), 'large'); ?>>Large</option>
						<option value="standard" <?php selected(esc_attr($image_advance_robots_length), 'standard'); ?>>Standard</option>
						<option value="none" <?php selected(esc_attr($image_advance_robots_length), 'none'); ?>>None</option>
					</select>
					</br>
					<span class="description">
						<span>Add maximum size of image preview to show the images on this page.</span>
					</span>
				</label>
			</li>
		</ul>
	<?php
	}

	public function post_redirection_display()
	{
		global $post;
		$post_redirection = get_post_meta($post->ID, 'metasync_post_redirection_meta', true) ?? '';
		$enable = $post_redirection['enable'] ?? '';
		$type = $post_redirection['type'] ?? '';
		$url = $post_redirection['url'] ?? '';
		wp_nonce_field('metasync_post_redirection_nonce', 'metasync_post_redirection_nonce');
	?>
		<ul class="checkbox-list">
			<li>
				<input type="checkbox" name="post_redirect_meta[enable]" id="post_redirection" value="true" <?php checked('true', esc_attr($enable)); ?>>
				<label for="post_redirection">Redirection</label>
			</li>
			<li class="hide"> Redirection Type:
				<select class="regular-text" name="post_redirect_meta[type]" id="post_redirection_type">
					<option value="301" <?php selected(esc_attr($type), '301'); ?>>301 Permanent Move</option>
					<option value="302" <?php selected(esc_attr($type), '302'); ?>>302 Temporary Move</option>
					<option value="307" <?php selected(esc_attr($type), '307'); ?>>307 Temporary Redirect</option>
					<option value="410" <?php selected(esc_attr($type), '410'); ?>>410 Content Deleted</option>
					<option value="451" <?php selected(esc_attr($type), '451'); ?>>451 Content Unavailable</option>
				</select>
			</li>
			<li class="hide" id="post_redirect_url"> Destination URL:
				<input type="text" class="regular-text" name="post_redirect_meta[url]" id="post_redirect_url_val" value="<?php echo esc_attr($url); ?>">
			</li>
		</ul>
	<?php
	}

	public function post_canonical_display()
	{
		global $post;

		$post_canonical = get_post_meta($post->ID, 'meta_canonical', true) ?? '';
		// Fix legacy array values stored by sanitize_array()
		if (is_array($post_canonical)) {
			$post_canonical = reset($post_canonical) ?: '';
			// Repair the stored value so it won't recur
			if (!empty($post_canonical)) {
				update_post_meta($post->ID, 'meta_canonical', (string) $post_canonical);
			}
		}
		wp_nonce_field('metasync_post_canonical_nonce', 'metasync_post_canonical_nonce');
	?>
		<ul>
			<li> Canonical URL:
				<input type="text" class="regular-text" name="post_canonical_url_meta" placeholder="<?php echo get_permalink($post->ID) ?>" value="<?php echo esc_attr($post_canonical); ?>">
			</li>
		</ul>
<?php
	}

	public function common_robots_meta_box_save($post_id)
	{
		if (!current_user_can('edit_post', $post_id))
			return;

		// WP-197: When saving from Gutenberg (REST API context) and the sidebar
		// JSON exists, skip — the sidebar auto-save is the source of truth.
		// Classic editor form submits (non-REST) always proceed so classic-only
		// users can still save via the meta boxes.
		if (defined('REST_REQUEST') && REST_REQUEST && !empty(get_post_meta($post_id, '_metasync_robots_advanced', true))) {
			return;
		}

		$post_data =  metasync_sanitize_input_array($_POST);
		// Check for new field name first, then old for backward compatibility
		$field_name = isset($post_data['common_robots_meta']) ? 'common_robots_meta' : 'common_robots_mata';

		if (!isset($post_data['metasync_common_robots_nonce'], $post_data[$field_name]) || !wp_verify_nonce($post_data['metasync_common_robots_nonce'], 'metasync_common_robots_nonce'))
			return;

		$old_common_robots = get_post_meta($post_id, 'metasync_common_robots', true);

		$common_robots = [];
		if (!empty($post_data[$field_name])) {
			$common_robots = $this->common->sanitize_array($post_data[$field_name]);
		}

		if (!empty($common_robots))
			update_post_meta($post_id, 'metasync_common_robots', $common_robots);
		elseif (empty($common_robots) && $old_common_robots)
			delete_post_meta($post_id, 'metasync_common_robots', $old_common_robots);
	}

	public function advance_robots_meta_box_save($post_id)
	{
		if (!current_user_can('edit_post', $post_id))
			return;

		// WP-197: When saving from Gutenberg (REST API context) and the sidebar
		// JSON exists, skip — the sidebar auto-save is the source of truth.
		if (defined('REST_REQUEST') && REST_REQUEST && !empty(get_post_meta($post_id, '_metasync_robots_advanced', true))) {
			return;
		}

		$post_data =  metasync_sanitize_input_array($_POST);
		// Check for new field name first, then old for backward compatibility
		$field_name = isset($post_data['advanced_robots_meta']) ? 'advanced_robots_meta' : 'advanced_robots_mata';

		if (!isset($post_data['metasync_advance_robots_nonce'], $post_data[$field_name]) || !wp_verify_nonce($post_data['metasync_advance_robots_nonce'], 'metasync_advance_robots_nonce'))
			return;

		$old_advance_robots = get_post_meta($post_id, 'metasync_advance_robots', true);

		$advance_robots = $this->common->sanitize_array($post_data[$field_name]);

		if (!empty($advance_robots))
			update_post_meta($post_id, 'metasync_advance_robots', $advance_robots);
		elseif (empty($advance_robots) && $old_advance_robots)
			delete_post_meta($post_id, 'metasync_advance_robots', $old_advance_robots);
	}

	public function redirection_meta_box_save($post_id)
	{
		if (!current_user_can('edit_post', $post_id))
			return;

		$post_data =  metasync_sanitize_input_array($_POST);
		// Check for new field name first, then old for backward compatibility
		$field_name = isset($post_data['post_redirect_meta']) ? 'post_redirect_meta' : 'post_redirect_mata';

		if (!isset($post_data['metasync_post_redirection_nonce'], $post_data[$field_name]) || !wp_verify_nonce($post_data['metasync_post_redirection_nonce'], 'metasync_post_redirection_nonce'))
			return;

		$old_post_redirection_meta = get_post_meta($post_id, 'metasync_post_redirection_meta', true);

		$post_redirection_meta = $this->common->sanitize_array($post_data[$field_name]);

		if (isset($post_redirection_meta['enable']))
			update_post_meta($post_id, 'metasync_post_redirection_meta', $post_redirection_meta);
		else
			delete_post_meta($post_id, 'metasync_post_redirection_meta', $old_post_redirection_meta);
	}

	public function canonical_meta_box_save($post_id)
	{
		if (!current_user_can('edit_post', $post_id))
			return;

		$post_data =  metasync_sanitize_input_array($_POST);
		// Check for new field name first, then old for backward compatibility
		$field_name = isset($post_data['post_canonical_url_meta']) ? 'post_canonical_url_meta' : 'post_canonical_url_mata';

		if (!isset($post_data['metasync_post_canonical_nonce'], $post_data[$field_name]) || !wp_verify_nonce($post_data['metasync_post_canonical_nonce'], 'metasync_post_canonical_nonce'))
			return;

		$old_post_canonical_meta = get_post_meta($post_id, 'meta_canonical', true);

		// Canonical is a URL string — sanitize as URL, not array
		$raw_value = $post_data[$field_name];
		if (is_array($raw_value)) {
			$raw_value = reset($raw_value); // extract first element if array
		}
		$post_canonical_meta = esc_url_raw(trim((string) $raw_value));

		if (!empty($post_canonical_meta))
			update_post_meta($post_id, 'meta_canonical', $post_canonical_meta);
		else
			delete_post_meta($post_id, 'meta_canonical', $old_post_canonical_meta);
	}

	public function show_top_admin_bar() {
		if ( Metasync::current_user_has_plugin_access() ) {
			show_admin_bar( true );
		}
	}

	/**
	 * Display the Video Sitemap meta box in post editor.
	 */
	public function video_sitemap_meta_box_display()
	{
		global $post;
		$video_url       = get_post_meta($post->ID, '_metasync_video_url', true);
		$video_thumbnail = get_post_meta($post->ID, '_metasync_video_thumbnail', true);
		$video_title     = get_post_meta($post->ID, '_metasync_video_title', true);
		$video_desc      = get_post_meta($post->ID, '_metasync_video_description', true);
		$video_duration  = get_post_meta($post->ID, '_metasync_video_duration', true);

		wp_nonce_field('metasync_video_sitemap_meta_nonce', 'metasync_video_sitemap_meta_nonce');
		?>
		<p style="color: #666; margin-bottom: 12px;">
			<?php esc_html_e('Override auto-detected video data for this post. Leave fields empty to use auto-detection.', 'metasync'); ?>
		</p>
		<table class="form-table" style="margin: 0;">
			<tr>
				<th scope="row"><label for="metasync_video_url"><?php esc_html_e('Video URL', 'metasync'); ?></label></th>
				<td><input type="url" id="metasync_video_url" name="metasync_video_url" value="<?php echo esc_attr($video_url); ?>" class="large-text" placeholder="https://www.youtube.com/watch?v=..." /></td>
			</tr>
			<tr>
				<th scope="row"><label for="metasync_video_thumbnail"><?php esc_html_e('Thumbnail URL', 'metasync'); ?></label></th>
				<td><input type="url" id="metasync_video_thumbnail" name="metasync_video_thumbnail" value="<?php echo esc_attr($video_thumbnail); ?>" class="large-text" placeholder="https://img.youtube.com/vi/.../hqdefault.jpg" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="metasync_video_title"><?php esc_html_e('Video Title', 'metasync'); ?></label></th>
				<td><input type="text" id="metasync_video_title" name="metasync_video_title" value="<?php echo esc_attr($video_title); ?>" class="large-text" placeholder="<?php esc_attr_e('Defaults to post title', 'metasync'); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="metasync_video_description"><?php esc_html_e('Video Description', 'metasync'); ?></label></th>
				<td><textarea id="metasync_video_description" name="metasync_video_description" class="large-text" rows="3" placeholder="<?php esc_attr_e('Defaults to post excerpt', 'metasync'); ?>"><?php echo esc_textarea($video_desc); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="metasync_video_duration"><?php esc_html_e('Duration (seconds)', 'metasync'); ?></label></th>
				<td><input type="number" id="metasync_video_duration" name="metasync_video_duration" value="<?php echo esc_attr($video_duration); ?>" class="small-text" min="0" step="1" placeholder="300" /></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the Video Sitemap meta box data.
	 *
	 * @param int $post_id The post ID.
	 */
	public function video_sitemap_meta_box_save($post_id)
	{
		if (!isset($_POST['metasync_video_sitemap_meta_nonce']) ||
			!wp_verify_nonce($_POST['metasync_video_sitemap_meta_nonce'], 'metasync_video_sitemap_meta_nonce')) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		$fields = [
			'metasync_video_url'         => '_metasync_video_url',
			'metasync_video_thumbnail'   => '_metasync_video_thumbnail',
			'metasync_video_title'       => '_metasync_video_title',
			'metasync_video_description' => '_metasync_video_description',
			'metasync_video_duration'    => '_metasync_video_duration',
		];

		foreach ($fields as $form_key => $meta_key) {
			if (!isset($_POST[$form_key])) {
				continue;
			}

			$value = $_POST[$form_key];

			// Sanitize per field type
			if (in_array($meta_key, ['_metasync_video_url', '_metasync_video_thumbnail'], true)) {
				$value = esc_url_raw($value);
			} elseif ($meta_key === '_metasync_video_duration') {
				$value = absint($value);
				$value = $value > 0 ? $value : '';
			} elseif ($meta_key === '_metasync_video_description') {
				$value = sanitize_textarea_field($value);
			} else {
				$value = sanitize_text_field($value);
			}

			if (!empty($value)) {
				update_post_meta($post_id, $meta_key, $value);
			} else {
				delete_post_meta($post_id, $meta_key);
			}
		}
	}

	/**
	 * Display the SEO meta box (SEO Title & Meta Description) in the Classic editor.
	 *
	 * Reads/writes the same _metasync_seo_title / _metasync_seo_desc keys used by the
	 * Gutenberg sidebar. OTTO values are used only as placeholder hints so the
	 * custom-value > OTTO priority is preserved.
	 */
	public function seo_meta_box_display()
	{
		global $post;
		$seo_title = get_post_meta($post->ID, '_metasync_seo_title', true);
		$seo_desc  = get_post_meta($post->ID, '_metasync_seo_desc', true);
		$otto_title = get_post_meta($post->ID, '_metasync_otto_title', true);
		$otto_desc  = get_post_meta($post->ID, '_metasync_otto_description', true);

		wp_nonce_field('metasync_seo_meta_nonce', 'metasync_seo_meta_nonce');
		?>
		<p style="color: #666; margin-bottom: 12px;">
			<?php esc_html_e('Set the SEO Title and Meta Description for this post. Leave the field blank to use OTTO suggestion.', 'metasync'); ?>
		</p>
		<table class="form-table" style="margin: 0;">
			<tr>
				<th scope="row"><label for="metasync_seo_title"><?php esc_html_e('SEO Title', 'metasync'); ?></label></th>
				<td><input type="text" id="metasync_seo_title" name="metasync_seo_title" value="<?php echo esc_attr($seo_title); ?>" class="large-text" placeholder="<?php echo esc_attr($otto_title); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="metasync_seo_desc"><?php esc_html_e('Meta Description', 'metasync'); ?></label></th>
				<td><textarea id="metasync_seo_desc" name="metasync_seo_desc" class="large-text" rows="3" placeholder="<?php echo esc_attr($otto_desc); ?>"><?php echo esc_textarea($seo_desc); ?></textarea></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the SEO meta box data.
	 *
	 * The nonce field is never present in Gutenberg REST saves, so the nonce check
	 * alone prevents this handler from running in that context — leaving the sidebar
	 * as the source of truth there.
	 *
	 * @param int $post_id The post ID.
	 */
	public function seo_meta_box_save($post_id)
	{
		if (!isset($_POST['metasync_seo_meta_nonce']) ||
			!wp_verify_nonce($_POST['metasync_seo_meta_nonce'], 'metasync_seo_meta_nonce')) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		if (isset($_POST['metasync_seo_title'])) {
			$seo_title = sanitize_text_field($_POST['metasync_seo_title']);
			if (!empty($seo_title)) {
				update_post_meta($post_id, '_metasync_seo_title', $seo_title);
			} else {
				delete_post_meta($post_id, '_metasync_seo_title');
			}
		}

		if (isset($_POST['metasync_seo_desc'])) {
			$seo_desc = sanitize_textarea_field($_POST['metasync_seo_desc']);
			if (!empty($seo_desc)) {
				update_post_meta($post_id, '_metasync_seo_desc', $seo_desc);
			} else {
				delete_post_meta($post_id, '_metasync_seo_desc');
			}
		}
	}
}

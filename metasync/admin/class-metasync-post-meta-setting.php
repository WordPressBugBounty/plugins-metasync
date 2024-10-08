<?php

/**
 * The header and footer code snippets functionality of the plugin.
 *
 *
 * @link       http://linkgraph.io
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/admin
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
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

		add_action('admin_init', [$this, 'add_post_mata_data'], 2);
		add_action('save_post', [$this, 'common_robots_meta_box_save']);
		add_action('save_post', [$this, 'advance_robots_meta_box_save']);
		add_action('save_post', [$this, 'redirection_meta_box_save']);
		add_action('save_post', [$this, 'canonical_meta_box_save']);
	}

	public function add_post_mata_data()
	{
		add_meta_box('common-robots-mata', 'Common Robots Mata', [$this, 'common_robots_meta_box_display'], ['page', 'post'], 'normal', 'default');
		add_meta_box('advance-robots-mata', 'Advance Robots Mata', [$this, 'advance_robots_meta_box_display'], ['page', 'post'], 'normal', 'default');
		add_meta_box('post-redirection-mata', 'Redirection', [$this, 'post_redirection_display'], ['page', 'post'], 'normal', 'default');
		add_meta_box('post-canonical-mata', 'Canonical', [$this, 'post_canonical_display'], ['page', 'post'], 'normal', 'default');
	}

	public function common_robots_meta_box_display()
	{
		global $post;
		$post_meta_robots = get_post_meta($post->ID, 'metasync_common_robots', true);
		$common_meta_robots = Metasync::get_option('common_robots_mata') ?? '';
		$common_robots = $post_meta_robots ? $post_meta_robots : $common_meta_robots;
		wp_nonce_field('metasync_common_robots_nonce', 'metasync_common_robots_nonce');
?>
		<ul class="checkbox-list">
			<li>
				<input type="checkbox" name="common_robots_mata[index]" id="robots_common1" value="index" <?php isset($common_robots['index']) ? checked('index', $common_robots['index']) : '' ?>>
				<label for="robots_common1">Index </br>
					<span class="description">
						<span>Search engines to index and show these pages in the search results.</span>
					</span>
				</label>
			</li>
			<li>
				<input type="checkbox" name="common_robots_mata[noindex]" id="robots_common2" value="noindex" <?php isset($common_robots['noindex']) ? checked('noindex', $common_robots['noindex']) : '' ?>>
				<label for="robots_common2">No Index </br>
					<span class="description">
						<span>Search engines not indexed and displayed this pages in search engine results</span>
					</span>
				</label>
			</li>
			<li>
				<input type="checkbox" name="common_robots_mata[nofollow]" id="robots_common3" value="nofollow" <?php isset($common_robots['nofollow']) ? checked('nofollow', $common_robots['nofollow']) : '' ?>>
				<label for="robots_common3">No Follow </br>
					<span class="description">
						<span>Search engines not follow the links on the pages</span>
					</span>
				</label>
			</li>
			<li>
				<input type="checkbox" name="common_robots_mata[noarchive]" id="robots_common4" value="noarchive" <?php isset($common_robots['noarchive']) ? checked('noarchive', $common_robots['noarchive']) : '' ?>>
				<label for="robots_common4">No Archive </br>
					<span class="description">
						<span>Search engines not showing Cached links for pages</span>
					</span>
				</label>
			</li>
			<li>
				<input type="checkbox" name="common_robots_mata[noimageindex]" id="robots_common5" value="noimageindex" <?php isset($common_robots['noimageindex']) ? checked('noimageindex', $common_robots['noimageindex']) : '' ?>>
				<label for="robots_common5">No Image Index </br>
					<span class="description">
						<span>If you do not want to apear your pages as the referring page for images that appear in image search results</span>
					</span>
				</label>
			</li>
			<li>
				<input type="checkbox" name="common_robots_mata[nosnippet]" id="robots_common6" value="nosnippet" <?php isset($common_robots['nosnippet']) ? checked('nosnippet', $common_robots['nosnippet']) : '' ?>>
				<label for="robots_common6">No Snippet </br>
					<span class="description">
						<span>Search engines not snippet to show in the search results</span>
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
		$common_meta_robots = Metasync::get_option('advance_robots_mata') ?? '';
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
					<input type="checkbox" name="advanced_robots_mata[max-snippet][enable]" id="advanced_robots_snippet" value="1" <?php checked('1', esc_attr($snippet_advance_robots_enable)) ?>>
					Snippet </br>
					<input type="number" class="input-length" name="advanced_robots_mata[max-snippet][length]" id="advanced_robots_snippet_value" value="<?php echo esc_attr($snippet_advance_robots_length); ?>" min="-1"> </br>
					<span class="description">
						<span>Add maximum text-length, in characters, of a snippet for your page.</span>
					</span>
				</label>
			</li>
			<li>
				<label for="advanced_robots_video">
					<input type="checkbox" name="advanced_robots_mata[max-video-preview][enable]" id="advanced_robots_video" value="1" <?php checked('1', esc_attr($video_advance_robots_enable)) ?>>
					Video Preview </br>
					<input type="number" class="input-length" name="advanced_robots_mata[max-video-preview][length]" id="advanced_robots_video_value" value="<?php echo esc_attr($video_advance_robots_length); ?>" min="-1"> </br>
					<span class="description">
						<span>Add maximum duration in seconds of an animated video preview.</span>
					</span>
				</label>
			</li>
			<li>
				<label for="advanced_robots_image">
					<input type="checkbox" name="advanced_robots_mata[max-image-preview][enable]" id="advanced_robots_image" value="1" <?php checked('1', esc_attr($image_advance_robots_enable)) ?>>
					Image Preview </br>
					<select class="input-length" name="advanced_robots_mata[max-image-preview][length]' ?>" id="advanced_robots_image_value">
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
				<input type="checkbox" name="post_redirect_mata[enable]" id="post_redirection" value="true" <?php checked('true', esc_attr($enable)); ?>>
				<label for="post_redirection">Redirection</label>
			</li>
			<li class="hide"> Redirection Type:
				<select class="regular-text" name="post_redirect_mata[type]" id="post_redirection_type">
					<option value="301" <?php selected(esc_attr($type), '301'); ?>>301 Permanent Move</option>
					<option value="302" <?php selected(esc_attr($type), '302'); ?>>302 Temprary Move</option>
					<option value="307" <?php selected(esc_attr($type), '307'); ?>>307 Temprary Redirect</option>
					<option value="410" <?php selected(esc_attr($type), '410'); ?>>410 Content Deleted</option>
					<option value="451" <?php selected(esc_attr($type), '451'); ?>>451 Content Unavailabel</option>
				</select>
			</li>
			<li class="hide" id="post_redirect_url"> Destination URL:
				<input type="text" class="regular-text" name="post_redirect_mata[url]" id="post_redirect_url_val" value="<?php echo esc_attr($url); ?>">
			</li>
		</ul>
	<?php
	}

	public function post_canonical_display()
	{
		global $post;

		$post_canonical = get_post_meta($post->ID, 'meta_canonical', true) ?? '';
		wp_nonce_field('metasync_post_canonical_nonce', 'metasync_post_canonical_nonce');
	?>
		<ul>
			<li> Canonical URL:
				<input type="text" class="regular-text" name="post_canonical_url_mata" placeholder="<?php echo get_permalink($post->ID) ?>" value="<?php echo esc_attr($post_canonical); ?>">
			</li>
		</ul>
<?php
	}

	public function common_robots_meta_box_save($post_id)
	{
		if (!current_user_can('edit_post', $post_id))
			return;

		$post_data =  sanitize_post($_POST);
		if (!isset($post_data['metasync_common_robots_nonce'], $post_data['common_robots_mata']) || !wp_verify_nonce($post_data['metasync_common_robots_nonce'], 'metasync_common_robots_nonce'))
			return;

		$old_common_robots = get_post_meta($post_id, 'metasync_common_robots', true);

		$common_robots = [];
		if (!empty($post_data['common_robots_mata'])) {
			$common_robots = $this->common->sanitize_array($post_data['common_robots_mata']);
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

		$post_data =  sanitize_post($_POST);
		if (!isset($post_data['metasync_advance_robots_nonce'], $post_data['advanced_robots_mata']) || !wp_verify_nonce($post_data['metasync_advance_robots_nonce'], 'metasync_advance_robots_nonce'))
			return;

		$old_advance_robots = get_post_meta($post_id, 'metasync_advance_robots', true);

		$advance_robots = $this->common->sanitize_array($post_data['advanced_robots_mata']);

		if (!empty($advance_robots))
			update_post_meta($post_id, 'metasync_advance_robots', $advance_robots);
		elseif (empty($advance_robots) && $old_advance_robots)
			delete_post_meta($post_id, 'metasync_advance_robots', $old_advance_robots);
	}

	public function redirection_meta_box_save($post_id)
	{
		if (!current_user_can('edit_post', $post_id))
			return;

		$post_data =  sanitize_post($_POST);
		if (!isset($post_data['metasync_post_redirection_nonce'], $post_data['post_redirect_mata']) || !wp_verify_nonce($post_data['metasync_post_redirection_nonce'], 'metasync_post_redirection_nonce'))
			return;

		$old_post_redirection_meta = get_post_meta($post_id, 'metasync_post_redirection_meta', true);

		$post_redirection_meta = $this->common->sanitize_array($post_data['post_redirect_mata']);

		if (isset($post_redirection_meta['enable']))
			update_post_meta($post_id, 'metasync_post_redirection_meta', $post_redirection_meta);
		else
			delete_post_meta($post_id, 'metasync_post_redirection_meta', $old_post_redirection_meta);
	}

	public function canonical_meta_box_save($post_id)
	{
		if (!current_user_can('edit_post', $post_id))
			return;

		$post_data =  sanitize_post($_POST);
		if (!isset($post_data['metasync_post_canonical_nonce'], $post_data['post_canonical_url_mata']) || !wp_verify_nonce($post_data['metasync_post_canonical_nonce'], 'metasync_post_canonical_nonce'))
			return;

		$old_post_canonical_meta = get_post_meta($post_id, 'meta_canonical', true);

		$post_canonical_meta = $this->common->sanitize_array($post_data['post_canonical_url_mata']);

		if (!empty($post_canonical_meta))
			update_post_meta($post_id, 'meta_canonical', $post_canonical_meta);
		else
			delete_post_meta($post_id, 'meta_canonical', $old_post_canonical_meta);
	}

	public function show_top_admin_bar() {
		if ( current_user_can( 'manage_options' ) ) {
			show_admin_bar( true );
		}
	}
}

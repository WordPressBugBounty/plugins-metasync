<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Post Types selection for Google Instant Indexing auto-submit.
 *
 * Expects:
 *   $post_types_settings - array, currently selected post type slugs
 *
 * @package Google Instant Indexing
 */
?>
	<div class="dashboard-card" style="padding: 20px;">
		<h2 style="margin-top: 0;">Post Types Configuration</h2>
		<p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Select which post types should be automatically submitted to Google on publish.</p>

		<table class="form-table" style="margin-top: 0;">
			<tr>
				<th scope="row" style="width: 200px;">
					Post Types:
				</th>
				<td>
					<?php
					$all_post_types = get_post_types(['public' => true], 'objects');
					$excluded_types = ['attachment', 'elementor_library', 'e-floating-buttons', 'e-landing-page', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_font_family', 'wp_font_face'];
					foreach ($all_post_types as $pt) {
						if (in_array($pt->name, $excluded_types, true)) {
							continue;
						}
					?>
						<label class="pr"><input type="checkbox" name="metasync_post_types[<?php echo esc_attr($pt->name); ?>]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $post_types_settings, true)); ?>> <?php echo esc_html($pt->label); ?></label>
					<?php
					}
					?>
				</td>
			</tr>
		</table>
	</div>

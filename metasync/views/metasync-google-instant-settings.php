<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}


/**
 * Instant Indexing Settings page contents.
 *
 * Expects the following variables to be set before include:
 *   $json_key             - string, current JSON key value (for textarea display)
 *   $google_guide_url     - string, URL to Google API setup guide
 *   $post_types_settings  - array, currently selected post type slugs
 *
 * @package Google Instant Indexing
 */

?>
	<form enctype="multipart/form-data" method="POST" action="">
		<div class="dashboard-card">
			<h2>🔑 Google API Configuration</h2>
			<p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure your Google API settings for instant indexing functionality.</p>

			<table class="form-table">
				<tr valign="top">
					<th scope="row">
						Google Project JSON Key:
					</th>
					<td>
						<textarea name="metasync_google_json_key" class="large-text" rows="8"><?php echo esc_textarea($json_key); ?></textarea>
						<br><br>
						<label style="display: flex; align-items: center; gap: 10px; color: var(--dashboard-text-secondary);">
							Or upload JSON file:
							<input type="file" name="metasync_google_json_file" accept=".json" style="margin-left: 10px;" />
						</label>
						<br>
						<p class="description">
							Upload the JSON key file you obtained from Google API Console.
							<a href="<?php echo esc_url($google_guide_url); ?>" target="_blank" style="color: var(--dashboard-accent);"> Read API Guide 📖</a>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<div class="dashboard-card">
			<h2>📝 Post Types Configuration</h2>
			<p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Select which post types should be automatically indexed.</p>

			<table class="form-table">
				<tr>
					<th scope="row">
						Public Post Types:
					</th>
					<td>
						<?php
						$all_post_types = get_post_types(['public' => true], 'objects');
						foreach ($all_post_types as $pt) {
						?>
							<label class="pr"><input type="checkbox" name="metasync_post_types[<?php echo esc_attr($pt->name); ?>]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $post_types_settings, true)); ?>> <?php echo esc_html($pt->label); ?></label>
						<?php
						}
						?>
					</td>
				</tr>
			</table>
		</div>

		<div class="dashboard-card">
			<h2>💾 Save Configuration</h2>
			<p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Apply your instant indexing configuration changes.</p>
			<?php submit_button('Save Instant Index Settings', 'primary', 'submit', false, array('class' => 'button button-primary')); ?>
		</div>
	</form>
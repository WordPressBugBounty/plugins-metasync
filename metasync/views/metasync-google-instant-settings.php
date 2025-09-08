<?php

/**
 * Instant Indexing Settings page contents.
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
						<textarea name="metasync_google_json_key" class="large-text" rows="8"><?php echo esc_textarea($this->get_setting('json_key')); ?></textarea>
						<br><br>
						<label style="display: flex; align-items: center; gap: 10px; color: var(--dashboard-text-secondary);">
							Or upload JSON file:
							<input type="file" name="metasync_google_json_file" accept=".json" style="margin-left: 10px;" />
						</label>
						<br>
						<p class="description">
							Upload the JSON key file you obtained from Google API Console.
							<a href="<?php echo esc_url($this->google_guide_url); ?>" target="_blank" style="color: var(--dashboard-accent);"> Read API Guide 📖</a>
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
						<?php $this->google_instant_index_post_types(); ?>
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
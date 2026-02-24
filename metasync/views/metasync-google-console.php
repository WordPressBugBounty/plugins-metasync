<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}


/**
 * Instant Indexing API of Google Console page contents.
 *
 * @package Google Instant Indexing
 */
?>

	<?php if (!$this->get_setting('json_key')) { ?>
		<div class="dashboard-card">
			<h2>⚠️ Configuration Required</h2>
			<p class="description" style="color: var(--dashboard-text-secondary); font-size: 16px; line-height: 1.6;">
			<?php
			echo wp_kses_post(
				sprintf(
					'Please goto the %s page to configure the Google Instant Indexing.',
					'<a href="' . esc_url(admin_url('admin.php?page=metasync-settings-seo-controls')) . '" style="color: var(--dashboard-accent);">Indexation Control</a>'
				)
			);
			?>
			</p>
		</div>
	<?php return;
	} ?>

	<?php
	$get_data =  sanitize_post($_GET);
	$urls   = home_url('/');
	if (isset($get_data['posturl'])) {
		$urls = esc_url_raw(wp_unslash($get_data['posturl']));
	}

	$action = 'update';
	if (isset($get_data['postaction'])) {
		$action = sanitize_title(wp_unslash($get_data['postaction']));
	}

	?>
	<form id="metasync-giapi-form" class="wpform" method="post">
		<div class="dashboard-card">
			<h2>🔗 URL Configuration</h2>
			<p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Enter the URLs you want to index with Google.</p>
			
			<table class="form-table">
				<tr valign="top">
					<th scope="row">
						URL for Indexing:
					</th>
					<td>
						<textarea name="url" id="metasync-giapi-url" class="wide-text" rows="4"><?php echo esc_textarea($urls); ?></textarea>
						<br>
						<p class="description" style="color: var(--dashboard-text-secondary);">URL up to 100 for Google</p>
					</td>
				</tr>
			</table>
		</div>

		<div class="dashboard-card">
			<h2>⚡ Indexing Actions</h2>
			<p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Choose the action you want to perform on the URLs.</p>
			
			<table class="form-table">
				<tr valign="top">
					<th scope="row">
						Actions of Indexing:
					</th>
					<td>
						<div style="display: flex; flex-direction: column; gap: 12px;">
							<label style="display: flex; align-items: center; gap: 8px; color: var(--dashboard-text-primary);">
								<input type="radio" name="metasync_api_action" value="update" class="metasync-giapi-action" <?php checked($action, 'update'); ?>>
								🚀 Publish URL
							</label>
							<label style="display: flex; align-items: center; gap: 8px; color: var(--dashboard-text-primary);">
								<input type="radio" name="metasync_api_action" value="status" class="metasync-giapi-action" <?php checked($action, 'status'); ?>>
								📊 URL status
							</label>
							<label style="display: flex; align-items: center; gap: 8px; color: var(--dashboard-text-primary);">
								<input type="radio" name="metasync_api_action" value="remove" class="metasync-giapi-action" <?php checked($action, 'remove'); ?>>
								🗑️ Remove URL
							</label>
						</div>
					</td>
				</tr>
			</table>
		</div>

		<div class="dashboard-card">
			<h2>📤 Execute Action</h2>
			<p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Send your request to Google's Indexing API.</p>
			<button type="button" id="metasync-btn-send" name="metasync-btn-send" class="button button-primary">
				📤 Send URL
			</button>
		</div>
	</form>

	<div class="dashboard-card" id="metasync-giapi-response" style="display: none;">
		<h2>📋 API Response</h2>
		<div class="result-wrapper" style="background: rgba(255, 255, 255, 0.05); border-radius: 8px; padding: 20px; margin-top: 16px;">
			<code class="result-action" style="color: var(--dashboard-accent); font-weight: 600;"></code>
			<h4 class="result-status-code" style="color: var(--dashboard-text-primary); margin: 12px 0;"></h4>
			<p class="result-message" style="color: var(--dashboard-text-secondary);"></p>
		</div>
	</div>
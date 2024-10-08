<?php

/**
 * Instant Indexing API of Google Console page contents.
 *
 * @package Google Instant Indexing
 */
?>
<div>
	<h1> Google Instant Indexing Console </h1>

	<?php if (!$this->get_setting('json_key')) { ?>
		<div>
			<p class="description">
				<?php
				echo wp_kses_post(
					sprintf(
						'Please goto the %s page to configure the Google Instant Indexing.',
						'<a href="' . esc_url(admin_url('admin.php?page=metasync-settings-instant-index')) . '">Google Instant Indexing Settings</a>'
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
		<table class="form-table">
			<tr valign="top">
				<th scope="row">
					URL for Indexing:
				</th>
				<td>
					<textarea name="url" id="metasync-giapi-url" class="wide-text" rows="4"><?php echo esc_textarea($urls); ?></textarea>
					<br>
					<p class="description">URL up to 100 for Google</p>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">
					Actions of Indexing:
				</th>
				<td>
					<label class="pr">
						<input type="radio" name="metasync_api_action" value="update" class="metasync-giapi-action" <?php checked($action, 'update'); ?>>
						Publish URL
					</label>
					<label class="pr">
						<input type="radio" name="metasync_api_action" value="status" class="metasync-giapi-action" <?php checked($action, 'status'); ?>>
						URL status
					</label>
					<label class="pr">
						<input type="radio" name="metasync_api_action" value="remove" class="metasync-giapi-action" <?php checked($action, 'remove'); ?>>
						Remove URL
					</label>
				</td>
			</tr>
			<tr>
				<td>
					<button type="button" id="metasync-btn-send" name="metasync-btn-send" class="button button-primary">Send URL</button>
				</td>
			</tr>
		</table>
	</form>
	<br>
	<br>

	<div id="metasync-giapi-response">
		<hr>
		<div class="result-wrapper">
			<code class="result-action"></code>
			<h4 class="result-status-code"></h4>
			<p class="result-message"></p>
		</div>
	</div>

</div>
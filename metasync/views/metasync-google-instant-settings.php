<?php

/**
 * Instant Indexing Settings page contents.
 *
 * @package Google Instant Indexing
 */

?>

<div>
	<h1> Google API Settings of Instant Indexing </h1>
	<form enctype="multipart/form-data" method="POST" action="">

		<table class="form-table">
			<tr valign="top">
				<th scope="row">
					Google Project JSON Key:
				</th>
				<td>
					<textarea name="metasync_google_json_key" class="large-text" rows="8"><?php echo esc_textarea($this->get_setting('json_key')); ?></textarea>
					<br>
					<label>
						Or upload JSON file:
						<input type="file" name="metasync_google_json_file" accept=".json" />
					</label>
					<br>
					<p class="description">
						Upload the JSON key file you obtained from Google API Console.
						<a href="<?php echo esc_url($this->google_guide_url); ?>" target="_blank"> Read API Guide </a>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					Public Post Types:
				</th>
				<td>
					<?php $this->google_instant_index_post_types(); ?>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
</div>
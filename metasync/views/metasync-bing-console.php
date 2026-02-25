<?php

/**
 * Bing Instant Indexing Console page contents.
 *
 * @package Bing Instant Indexing
 */
?>

	<?php if (!$this->get_setting('api_key')) { ?>
		<div class="dashboard-card">
			<h2>⚠️ Configuration Required</h2>
			<p class="description" style="color: var(--dashboard-text-secondary); font-size: 16px; line-height: 1.6;">
			<?php
			echo wp_kses_post(
				sprintf(
					'Please goto the %s page to configure the Bing Instant Indexing (IndexNow API).',
					'<a href="' . esc_url(admin_url('admin.php?page=metasync-settings-seo-controls')) . '" style="color: var(--dashboard-accent);">Indexation Control</a>'
				)
			);
			?>
			</p>
		</div>
	<?php return;
	} ?>

	<?php
	$urls = home_url('/');
	if (isset($_GET['posturl'])) {
		$urls = esc_url_raw(wp_unslash($_GET['posturl']));
	}

	?>
	<form id="metasync-bing-form" class="wpform" method="post">
		<div class="dashboard-card">
			<h2>🔗 URL Configuration</h2>
			<p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Enter the URLs you want to submit to Bing and other search engines via IndexNow.</p>

			<table class="form-table">
				<tr valign="top">
					<th scope="row">
						URLs for Indexing:
					</th>
					<td>
						<textarea name="metasync_bing_url" id="metasync-bing-url" class="wide-text" rows="4"><?php echo esc_textarea($urls); ?></textarea>
						<br>
						<p class="description" style="color: var(--dashboard-text-secondary);">
							Submit up to 10,000 URLs per request (one per line).
							IndexNow supports batch submissions to notify multiple search engines at once.
						</p>
					</td>
				</tr>
			</table>

			<div style="margin-top: 20px;">
				<button type="button" id="metasync-bing-btn-send" name="metasync-bing-btn-send" class="button button-primary">
					📤 Submit to IndexNow
				</button>
			</div>
		</div>

		<div class="dashboard-card" id="metasync-bing-response" style="display: none;">
			<h2>📋 Submission Response</h2>
			<div class="result-wrapper" style="background: rgba(255, 255, 255, 0.05); border-radius: 8px; padding: 20px; margin-top: 16px;">
				<code class="result-urls" style="color: var(--dashboard-accent); font-weight: 600; display: block; margin-bottom: 12px;"></code>
				<h4 class="result-status-code" style="color: var(--dashboard-text-primary); margin: 12px 0;"></h4>
				<p class="result-message" style="color: var(--dashboard-text-secondary);"></p>
			</div>
		</div>

		<div class="dashboard-card">
			<h2>ℹ️ About IndexNow Submission</h2>
			<p style="color: var(--dashboard-text-secondary); margin-bottom: 15px;">
				IndexNow is a simple protocol that notifies search engines when your content has been created, updated, or deleted.
			</p>
			<ul style="color: var(--dashboard-text-secondary); margin-left: 20px; margin-bottom: 15px;">
				<li>✓ Submit URLs to Bing, Yandex, Naver, and other participating search engines</li>
				<li>✓ Instant notification (no waiting for crawlers)</li>
				<li>✓ No status checking required - submission is fire-and-forget</li>
				<li>✓ No quotas for typical usage</li>
			</ul>
		</div>

		<div class="dashboard-card">
			<h2>🔧 Bing Webmaster Tools</h2>
			<p style="color: var(--dashboard-text-secondary); margin-bottom: 15px;">
				Monitor your IndexNow submissions and track your site's performance in Bing search results.
			</p>
			<p style="color: var(--dashboard-text-secondary); margin-bottom: 15px;">
				After submitting URLs via IndexNow, you can view submission history, indexing status, and detailed analytics in Bing Webmaster Tools.
			</p>
			<a href="https://www.bing.com/webmasters" target="_blank" rel="noopener noreferrer" class="button button-secondary" style="margin-bottom: 10px;">
				🌐 Open Bing Webmaster Tools
			</a>
			<p class="description" style="color: var(--dashboard-text-secondary); margin-top: 15px;">
				<strong>Note:</strong> Make sure your site is verified in Bing Webmaster Tools to see submission analytics and indexing reports.
			</p>
		</div>
	</form>

	<script>
	jQuery(document).ready(function($) {
		$('#metasync-bing-response').hide();

		$('#metasync-bing-btn-send').on('click', function() {
			const $button = $(this);
			const $url = $('#metasync-bing-url');
			const $response = $('#metasync-bing-response');
			const originalText = $button.html();

			// Validate URLs
			if (!$url.val().trim()) {
				alert('Please enter at least one URL to submit.');
				return;
			}

			// Disable button and show loading state
			$button.html('🔄 Submitting...').prop('disabled', true);

			// Make AJAX request
			jQuery.ajax({
				method: 'POST',
				url: ajaxurl,
				data: {
					action: 'send_bing_indexnow',
					metasync_bing_url: $url.val()
				}
			})
			.done(function(response) {
				$response.show();

				// Parse response
				const urls = $url.val().split('\n').filter(Boolean);
				const urlsDisplay = urls.length > 1 ? urls.length + ' URLs submitted' : urls[0];

				$('.result-urls').html('<strong>Submitted:</strong> ' + urlsDisplay);

				if (response.success) {
					$('.result-status-code').text('✅ Success').css('color', '#4caf50');
					$('.result-message').html(response.message || 'URLs successfully submitted to IndexNow.');
				} else {
					$('.result-status-code').text('❌ Error').css('color', '#f44336');
					$('.result-message').html(response.message || 'Failed to submit URLs. Please check your API key configuration.');
				}

				// Show response details if available
				if (response.response_code) {
					$('.result-message').append('<br><small>Response Code: ' + response.response_code + '</small>');
				}
			})
			.fail(function(xhr, status, error) {
				$response.show();
				$('.result-urls').html('<strong>Error</strong>');
				$('.result-status-code').text('❌ Request Failed').css('color', '#f44336');
				$('.result-message').text('Network error: ' + error);
			})
			.always(function() {
				// Re-enable button
				$button.html(originalText).prop('disabled', false);
			});
		});
	});
	</script>

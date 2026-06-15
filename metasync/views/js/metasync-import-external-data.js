/* global metasyncImportData, jQuery, ajaxurl */
/**
 * MetaSync Import External Data
 *
 * Extracted for Phase 5, #887.
 * Handles importing external plugin data (redirections, SEO metadata)
 * with batch progress tracking for SEO metadata imports.
 *
 * Localized object: metasyncImportData
 *   - importNonce    (string)  Nonce for metasync_import_external_data
 *   - seoImportNonce (string)  Nonce for metasync_import_seo_metadata
 *
 * @since Phase 5
 */
jQuery(document).ready(function ($) {
	var importNonce = metasyncImportData.importNonce;
	var seoImportNonce = metasyncImportData.seoImportNonce;
	var currentImportData = null;

	// Handle import button clicks
	$('.metasync-import-btn').on('click', function () {
		var btn = $(this);
		var type = btn.data('type');
		var plugin = btn.data('plugin');
		var card = btn.closest('.metasync-plugin-card');

		// Special handling for SEO metadata - show options modal
		if (type === 'seo_metadata') {
			currentImportData = {
				btn: btn,
				type: type,
				plugin: plugin,
				card: card
			};
			$('#metasync-seo-options-modal').addClass('active');
			return;
		}

		// Indexation imports are batched to avoid PHP memory exhaustion on large sites
		if (type === 'indexation') {
			performIndexationImport(btn, type, plugin, card);
			return;
		}

		// For other import types, proceed directly
		performImport(btn, type, plugin, card);
	});

	// Modal close handlers
	$('.metasync-modal-close, #modal-cancel').on('click', function () {
		$('#metasync-seo-options-modal').removeClass('active');
		$('.metasync-progress-container').removeClass('active');
		$('.metasync-progress-fill').css('width', '0%');
		$('.metasync-progress-text').text('0%');
		$('.metasync-progress-status').text('');
		currentImportData = null;
	});

	// Click outside modal to close
	$('.metasync-modal-overlay').on('click', function (e) {
		if (e.target === this) {
			$(this).find('.metasync-modal-close').click();
		}
	});

	// Start Import button in modal
	$('#modal-start-import').on('click', function () {
		if (!currentImportData) {
			return;
		}

		var options = {
			import_titles: $('#import-titles').is(':checked'),
			import_descriptions: $('#import-descriptions').is(':checked'),
			import_social_text: $('#import-social-text').is(':checked'),
			import_social_images: $('#import-social-images').is(':checked'),
			overwrite_existing: $('#overwrite-existing').is(':checked')
		};

		// Disable form elements
		$('#modal-start-import, #modal-cancel, #import-titles, #import-descriptions, #import-social-text, #import-social-images, #overwrite-existing')
			.prop('disabled', true);

		// Show progress
		$('.metasync-progress-container').addClass('active');

		// Start batch import
		performBatchImport(currentImportData, options);
	});

	// Perform direct import (for non-SEO metadata types)
	function performImport(btn, type, plugin, card) {
		var resultDiv = card.find('.metasync-import-result');

		btn.prop('disabled', true).text('Importing...');
		resultDiv.removeClass('success error').hide();

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'metasync_import_external_data',
				nonce: importNonce,
				type: type,
				plugin: plugin
			},
			success: function (response) {
				if (response.success) {
					btn.addClass('success').text('\u2713 Imported');
					resultDiv.addClass('success').text(response.data.message).slideDown();
				} else {
					btn.prop('disabled', false).text('Import ' + type.charAt(0).toUpperCase() + type.slice(1));
					resultDiv.addClass('error').text(response.data.message || 'Import failed.').slideDown();
				}
			},
			error: function () {
				btn.prop('disabled', false).text('Retry Import');
				resultDiv.addClass('error').text('Network error. Please try again.').slideDown();
			}
		});
	}

	// Perform batched indexation import with progress tracking
	function performIndexationImport(btn, type, plugin, card) {
		var resultDiv = card.find('.metasync-import-result');
		var progressContainer = card.find('.metasync-progress-container');
		var progressFill = card.find('.metasync-progress-fill');
		var progressText = card.find('.metasync-progress-text');
		var offset = 0;
		var totalImported = 0;
		var totalSkipped = 0;

		btn.prop('disabled', true).text('Importing...');
		resultDiv.removeClass('success error').hide();
		progressFill.css('width', '0%');
		progressText.text('0%');
		progressContainer.addClass('active');

		function processBatch() {
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'metasync_import_external_data',
					nonce: importNonce,
					type: type,
					plugin: plugin,
					offset: offset
				},
				success: function (response) {
					if (response.success && response.data) {
						var data = response.data;
						totalImported += data.imported || 0;
						totalSkipped += data.skipped || 0;

						var percent = data.progress_percent || 0;
						progressFill.css('width', percent + '%');
						progressText.text(percent + '%');

						resultDiv.removeClass('error').addClass('success')
							.text('Processing... ' + (data.processed || 0).toLocaleString() + ' of ' + (data.total || 0).toLocaleString() + ' posts')
							.show();

						if (data.is_complete) {
							progressFill.css('width', '100%');
							progressText.text('100%');
							btn.addClass('success').text('✓ Imported');
							resultDiv.text('✓ Import Complete! Imported: ' + totalImported.toLocaleString() + ' | Skipped: ' + totalSkipped.toLocaleString());
						} else {
							offset = data.processed || 0;
							processBatch();
						}
					} else {
						btn.prop('disabled', false).text('Retry Import');
						progressContainer.removeClass('active');
						var errMsg = (response.data && response.data.message) ? response.data.message : 'Import failed.';
						resultDiv.removeClass('success').addClass('error').text(errMsg).show();
					}
				},
				error: function () {
					btn.prop('disabled', false).text('Retry Import');
					progressContainer.removeClass('active');
					resultDiv.removeClass('success').addClass('error').text('Network error. Please try again.').show();
				}
			});
		}

		processBatch();
	}

	// Perform batch import with progress tracking (for SEO metadata)
	function performBatchImport(importData, options) {
		var offset = 0;
		var totalImported = 0;
		var totalSkipped = 0;

		function processBatch() {
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'metasync_import_seo_metadata',
					nonce: seoImportNonce,
					plugin: importData.plugin,
					import_titles: options.import_titles ? 1 : 0,
					import_descriptions: options.import_descriptions ? 1 : 0,
					import_social_text: options.import_social_text ? 1 : 0,
					import_social_images: options.import_social_images ? 1 : 0,
					overwrite_existing: options.overwrite_existing ? 1 : 0,
					offset: offset
				},
				success: function (response) {
					if (response.success && response.data) {
						var data = response.data;
						totalImported += data.imported || 0;
						totalSkipped += data.skipped || 0;

						// Update progress bar
						var percent = data.progress_percent || 0;
						$('.metasync-progress-fill').css('width', percent + '%');
						$('.metasync-progress-text').text(percent + '%');
						$('.metasync-progress-status').text(
							'Processing... ' + (data.processed || 0) + ' of ' + (data.total || 0) + ' posts'
						);

						if (data.is_complete) {
							// Import complete
							$('.metasync-progress-status').empty()
								.append($('<strong>').css('color', 'var(--dashboard-success)').text('\u2713 Import Complete!'))
								.append(document.createTextNode(' Imported: ' + totalImported + ' posts | Skipped: ' + totalSkipped + ' posts'));

							importData.btn.addClass('success').text('\u2713 Imported');

							// Close modal after 2 seconds
							setTimeout(function () {
								$('#metasync-seo-options-modal .metasync-modal-close').click();
								// Re-enable button for future imports
								$('#modal-start-import, #modal-cancel, #import-titles, #import-descriptions, #import-social-text, #import-social-images, #overwrite-existing')
									.prop('disabled', false);
							}, 2000);
						} else {
							// Continue with next batch
							offset = data.processed || 0;
							processBatch();
						}
					} else {
						// Error occurred
						var importErrMsg = (response.data && response.data.message ? response.data.message : 'Unknown error occurred');
						var $importStatus = $('.metasync-progress-status').empty();
						$importStatus.append($('<strong>').css('color', 'var(--dashboard-error)').text('\u2717 Import Failed'));
						$importStatus[0].appendChild(document.createTextNode(' ' + importErrMsg));
						$('#modal-start-import, #modal-cancel, #import-titles, #import-descriptions, #import-social-text, #import-social-images, #overwrite-existing')
							.prop('disabled', false);
					}
				},
				error: function () {
					$('.metasync-progress-status').empty()
						.append($('<strong>').css('color', 'var(--dashboard-error)').text('\u2717 Network Error'))
						.append(document.createTextNode(' Please try again'));
					$('#modal-start-import, #modal-cancel, #import-titles, #import-descriptions, #import-social-text, #import-social-images, #overwrite-existing')
						.prop('disabled', false);
				}
			});
		}

		// Start first batch
		processBatch();
	}
});

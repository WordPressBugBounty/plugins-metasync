/**
 * OTTO Frontend Toolbar JavaScript
 *
 * Handles preview iframe functionality for OTTO control toolbar
 *
 * @package    Metasync
 * @subpackage Metasync/otto-frontend-toolbar/js
 * @since      1.0.0
 */

(function($) {
	'use strict';

	/**
	 * OTTO Toolbar Manager
	 */
	window.metasyncOttoToolbar = {

		/**
		 * Initialize the toolbar
		 */
		init: function() {
			console.log('OTTO Toolbar initialized');
			
			// Bind preview button
			$(document).on('click', '#otto-preview-btn', function(e) {
				e.preventDefault();
				window.metasyncOttoToolbar.openPreview();
			});
			
			// Bind close preview button
			$(document).on('click', '#otto-preview-close', function(e) {
				e.preventDefault();
				window.metasyncOttoToolbar.closePreview();
			});
			
			// Bind debug button
			$(document).on('click', '#otto-debug-btn', function(e) {
				e.preventDefault();
				window.metasyncOttoToolbar.openDebugTray();
			});
			
		// Bind close debug tray button
		$(document).on('click', '#otto-debug-tray-close', function(e) {
			e.preventDefault();
			window.metasyncOttoToolbar.closeDebugTray();
		});
		
		// Bind OTTO toggle switch in debug tray
		$(document).on('change', '#otto-debug-toggle', function(e) {
			window.metasyncOttoToolbar.handleOttoToggle(this);
		});
		
		// Close preview on ESC key
		$(document).on('keydown', function(e) {
				if (e.key === 'Escape') {
					if ($('#metasync-otto-preview-overlay').is(':visible')) {
						window.metasyncOttoToolbar.closePreview();
					}
					if ($('#metasync-otto-debug-tray').hasClass('active')) {
						window.metasyncOttoToolbar.closeDebugTray();
					}
				}
			});
			
			// Optional: Add loading indicator when clicking toggle links
			$('.metasync-otto-toggle').on('click', function() {
				$(this).css('opacity', '0.6');
			});
		},

		/**
		 * Open preview iframe with otto_preview parameter
		 */
	openPreview: function() {
		// Get current page URL
		let currentUrl = window.location.href;
		
		// Add otto_preview parameter
		let previewUrl = this.addParameterToUrl(currentUrl, 'otto_preview', '1');
		
		let $overlay = $('#metasync-otto-preview-overlay');
		let $iframe = $('#metasync-otto-preview-iframe');
		let $loading = $('#otto-preview-loading');
			
			// Show loading spinner
			$loading.removeClass('hidden');
			
			// Reset iframe opacity
			$iframe.css('opacity', '0');
			
			// Add active class to show overlay (display: flex)
			$overlay.addClass('active').css('opacity', '1');
			
			// Add body class
			$('body').addClass('otto-preview-active');
			
			// Prevent body scroll
			$('body').css('overflow', 'hidden');
			
			// Set iframe src and wait for load
			$iframe.attr('src', previewUrl);
			
			// Listen for iframe load event
			$iframe.off('load').on('load', function() {
				// Hide loading spinner
				$loading.addClass('hidden');
				
				// Show iframe with fade in
				$iframe.animate({ opacity: 1 }, 400);
				
				console.log('OTTO Preview loaded successfully');
			});
			
			// Fallback timeout in case load event doesn't fire
			setTimeout(function() {
				if ($loading.is(':visible')) {
					$loading.addClass('hidden');
					$iframe.animate({ opacity: 1 }, 400);
				}
			}, 3000);
			
			console.log('OTTO Preview opened:', previewUrl);
		},

		/**
		 * Close preview iframe
		 */
	closePreview: function() {
		let $overlay = $('#metasync-otto-preview-overlay');
		let $iframe = $('#metasync-otto-preview-iframe');
		let $loading = $('#otto-preview-loading');
			
			// Fade out
			$overlay.animate({ opacity: 0 }, 300, function() {
				// Remove active class
				$overlay.removeClass('active');
				
				// Clear iframe src after animation
				$iframe.attr('src', '').css('opacity', '0');
				
				// Reset loading state
				$loading.removeClass('hidden');
			});
			
			// Remove body class
			$('body').removeClass('otto-preview-active');
			
			// Restore body scroll
			$('body').css('overflow', '');
			
			console.log('OTTO Preview closed');
		},

		/**
		 * Open debug tray and load comparison data
		 */
	openDebugTray: function() {
		let $tray = $('#metasync-otto-debug-tray');
		let $content = $('#otto-debug-tray-content');
		
		// Show tray
		$tray.addClass('active');
		
		// Reset content to loading state
		$content.html('<div class="otto-debug-loading"><div class="otto-loading-spinner"></div><p>Loading comparison data...</p></div>');
		
		// Build API URL
		let apiUrl = metasyncOttoDebug.apiUrl +
			             '?url=' + encodeURIComponent(metasyncOttoDebug.currentUrl) + 
			             '&uuid=' + metasyncOttoDebug.ottoUuid;
			
			console.log('Fetching OTTO debug data from:', apiUrl);
			
			// Fetch data from API
			$.ajax({
				url: apiUrl,
				type: 'GET',
				dataType: 'json',
				success: function(response) {
					window.metasyncOttoToolbar.renderComparisonData(response);
				},
				error: function(xhr, status, error) {
					console.error('OTTO Debug API Error:', error);
					let ottoName = metasyncOttoDebug.ottoName || 'OTTO';
					$content.html(
						'<div class="otto-debug-error">' +
						'<strong>Error loading comparison data</strong>' +
						'<p>Unable to fetch data from the API. Please check your ' + ottoName + ' UUID settings and try again.</p>' +
						'<p><small>Error: ' + error + '</small></p>' +
						'</div>'
					);
				}
			});
		},

	/**
	 * Close debug tray
	 */
	closeDebugTray: function() {
		$('#metasync-otto-debug-tray').removeClass('active');
	},

	/**
	 * Handle OTTO toggle switch change
	 */
	handleOttoToggle: function(toggleElement) {
		let $toggle = $(toggleElement);
		let postId = $toggle.data('post-id');
		let isChecked = $toggle.is(':checked');
		let action = isChecked ? 'enable' : 'disable';
		
		// Disable toggle during AJAX request
		$toggle.prop('disabled', true);
		
		// Update status text immediately for better UX
		$('.otto-toggle-status-text').text(isChecked ? 'Enabling...' : 'Disabling...');
		
		console.log('OTTO Toggle - Action:', action, 'Post ID:', postId);
		
		// Make AJAX request
		$.ajax({
			url: metasyncOttoDebug.ajaxUrl,
			type: 'POST',
			data: {
				action: 'metasync_otto_toggle',
				post_id: postId,
				otto_action: action,
				nonce: metasyncOttoDebug.nonce
			},
			success: function(response) {
				console.log('OTTO Toggle Response:', response);
				
				if (response.success) {
					// Update status text in tray
					$('.otto-toggle-status-text').text(isChecked ? 'Enabled' : 'Disabled');
					
					// Update debug bar status
					let $debugBar = $('#metasync-otto-debug-bar');
					let ottoName = metasyncOttoDebug.ottoName || 'OTTO';
					if (isChecked) {
						$debugBar.removeClass('otto-disabled').addClass('otto-enabled');
						$debugBar.find('.otto-status-text').text(ottoName + ' Enabled');
						
						// Show Preview Original button when enabled
						if ($('#otto-preview-btn').length === 0) {
							let previewBtn = '<button type="button" class="otto-preview-btn" id="otto-preview-btn">' +
							                 '<span class="dashicons dashicons-visibility"></span>' +
							                 'Preview Original' +
							                 '</button>';
							$('.otto-debug-status').after(previewBtn);
						}
					} else {
						$debugBar.removeClass('otto-enabled').addClass('otto-disabled');
						$debugBar.find('.otto-status-text').text(ottoName + ' Disabled');
						
						// Hide Preview Original button when disabled
						$('#otto-preview-btn').remove();
					}
					
					// Keep debug tray open - no page reload
					console.log('OTTO status updated successfully. Debug tray remains open.');
				} else {
					// Revert toggle on error
					$toggle.prop('checked', !isChecked);
					$('.otto-toggle-status-text').text(isChecked ? 'Disabled' : 'Enabled');
					let ottoName = metasyncOttoDebug.ottoName || 'OTTO';
					alert(response.data.message || 'Failed to update ' + ottoName + ' status.');
				}
			},
			error: function(xhr, status, error) {
				console.error('OTTO Toggle Error:', error);
				// Revert toggle on error
				$toggle.prop('checked', !isChecked);
				$('.otto-toggle-status-text').text(isChecked ? 'Disabled' : 'Enabled');
				let ottoName = metasyncOttoDebug.ottoName || 'OTTO';
				alert('An error occurred while updating ' + ottoName + ' status.');
			},
			complete: function() {
				// Re-enable toggle
				$toggle.prop('disabled', false);
			}
		});
	},

		/**
		 * Render comparison data in the tray
		 */
	renderComparisonData: function(data) {
		let $content = $('#otto-debug-tray-content');
		
		// Parse header_replacements array
		let titleData = null;
		let descriptionData = null;
		let ogTitleData = null;
		let ogDescriptionData = null;
		
		if (data.header_replacements && Array.isArray(data.header_replacements)) {
			for (let i = 0; i < data.header_replacements.length; i++) {
				let item = data.header_replacements[i];
					
					if (item.type === 'title') {
						titleData = item;
					} else if (item.type === 'meta' && item.name === 'description') {
						descriptionData = item;
					} else if (item.type === 'meta' && item.property === 'og:title') {
						ogTitleData = item;
					} else if (item.type === 'meta' && item.property === 'og:description') {
						ogDescriptionData = item;
					}
				}
		}
		
		// Build comparison table HTML
		let html = '<table class="otto-comparison-table">';
		html += '<thead><tr>';
		html += '<th class="original-col">Original</th>';
		html += '<th class="otto-col">Otto Suggested</th>';
		html += '</tr></thead>';
		html += '<tbody>';
		
		// Title - only show if present
		if (titleData) {
			html += '<tr>';
			html += '<td><span class="field-label">Title:</span><div class="field-value">' + 
			        this.escapeHtml(titleData.current_value) + '</div></td>';
			html += '<td><span class="field-label">Title:</span><div class="field-value">' + 
			        this.escapeHtml(titleData.recommended_value) + '</div></td>';
			html += '</tr>';
		}
		
		// Meta Description - only show if present
		if (descriptionData) {
			html += '<tr>';
			html += '<td><span class="field-label">Meta Description</span><div class="field-value">' + 
			        this.escapeHtml(descriptionData.current_value) + '</div></td>';
			html += '<td><span class="field-label">Meta Description</span><div class="field-value">' + 
			        this.escapeHtml(descriptionData.recommended_value) + '</div></td>';
			html += '</tr>';
		}
		
		// OG Title - only show if present
		if (ogTitleData) {
			html += '<tr>';
			html += '<td><span class="field-label">OG Title</span><div class="field-value">' + 
			        this.escapeHtml(ogTitleData.current_value) + '</div></td>';
			html += '<td><span class="field-label">OG Title</span><div class="field-value">' + 
			        this.escapeHtml(ogTitleData.recommended_value) + '</div></td>';
			html += '</tr>';
		}
		
		// OG Description - only show if present
		if (ogDescriptionData) {
			html += '<tr>';
			html += '<td><span class="field-label">OG Description</span><div class="field-value">' + 
			        this.escapeHtml(ogDescriptionData.current_value) + '</div></td>';
			html += '<td><span class="field-label">OG Description</span><div class="field-value">' + 
			        this.escapeHtml(ogDescriptionData.recommended_value) + '</div></td>';
			html += '</tr>';
		}
		
		// Body Substitutions - Headings
		if (data.body_substitutions && data.body_substitutions.headings && 
		    Array.isArray(data.body_substitutions.headings) && 
		    data.body_substitutions.headings.length > 0) {
			for (let i = 0; i < data.body_substitutions.headings.length; i++) {
				let heading = data.body_substitutions.headings[i];
				let headingLabel = 'Heading';
				
				// Add heading type if available (H1, H2, etc.)
				if (heading.type) {
					headingLabel = heading.type.toUpperCase() + ' Heading';
				}
				
				html += '<tr>';
				html += '<td><span class="field-label">' + this.escapeHtml(headingLabel) + '</span><div class="field-value">' + 
				        this.escapeHtml(heading.current_value || '') + '</div></td>';
				html += '<td><span class="field-label">' + this.escapeHtml(headingLabel) + '</span><div class="field-value">' + 
				        this.escapeHtml(heading.recommended_value || '') + '</div></td>';
				html += '</tr>';
			}
		}
		
		// Parse Header HTML Insertion for Keywords and Schema
		if (data.header_html_insertion) {
			let parsedInsertions = this.parseHeaderInsertions(data.header_html_insertion);
			
			// Keywords
			if (parsedInsertions.keywords) {
				html += '<tr>';
				html += '<td><span class="field-label">Keywords</span><div class="field-value"><em>Not present</em></div></td>';
				html += '<td><span class="field-label">Keywords</span><div class="field-value">' + 
				        this.escapeHtml(parsedInsertions.keywords) + '</div></td>';
				html += '</tr>';
			}
			
			// Schema (JSON-LD)
			if (parsedInsertions.schema) {
				html += '<tr>';
				html += '<td><span class="field-label">Schema</span><div class="field-value"><em>Existing schema</em></div></td>';
				html += '<td><span class="field-label">Schema</span><div class="field-value"><pre>' + 
				        this.escapeHtml(parsedInsertions.schema) + '</pre></div></td>';
				html += '</tr>';
			}
			
			// Twitter Meta Tags
			if (parsedInsertions.twitterTitle) {
				html += '<tr>';
				html += '<td><span class="field-label">Twitter Title</span><div class="field-value"><em>Not present</em></div></td>';
				html += '<td><span class="field-label">Twitter Title</span><div class="field-value">' + 
				        this.escapeHtml(parsedInsertions.twitterTitle) + '</div></td>';
				html += '</tr>';
			}
			
			if (parsedInsertions.twitterDescription) {
				html += '<tr>';
				html += '<td><span class="field-label">Twitter Description</span><div class="field-value"><em>Not present</em></div></td>';
				html += '<td><span class="field-label">Twitter Description</span><div class="field-value">' + 
				        this.escapeHtml(parsedInsertions.twitterDescription) + '</div></td>';
				html += '</tr>';
			}
		}
			
		// Body Substitutions (Links)
		if (data.body_substitutions && data.body_substitutions.links && 
		    Object.keys(data.body_substitutions.links).length > 0) {
			html += '<tr>';
			html += '<td colspan="2"><span class="field-label">Link Updates</span>';
		html += '<div class="field-value">';
		html += '<ul style="margin: 5px 0; padding-left: 20px;">';
		for (let oldLink in data.body_substitutions.links) {
			if (data.body_substitutions.links.hasOwnProperty(oldLink)) {
					let newLink = data.body_substitutions.links[oldLink];
					html += '<li><strong>From:</strong> <a href="' + this.escapeHtml(oldLink) + '" target="_blank" rel="noopener noreferrer">' + 
					        this.escapeHtml(oldLink) + '</a>' + 
					        '<br><strong>To:</strong> <a href="' + this.escapeHtml(newLink) + '" target="_blank" rel="noopener noreferrer">' + 
					        this.escapeHtml(newLink) + '</a></li>';
				}
			}
			html += '</ul></div></td>';
			html += '</tr>';
		}
		
		// Body Substitutions (Images - Alt Tags)
		if (data.body_substitutions && data.body_substitutions.images && 
		    Object.keys(data.body_substitutions.images).length > 0) {
			html += '<tr>';
			html += '<td colspan="2"><span class="field-label">Image Alt Tag Updates</span>';
			html += '<div class="field-value">';
			html += '<ul style="margin: 5px 0; padding-left: 20px;">';
			for (let imageUrl in data.body_substitutions.images) {
				if (data.body_substitutions.images.hasOwnProperty(imageUrl)) {
					
					let altText = data.body_substitutions.images[imageUrl] || 'N/A';
					let shortenedUrl = this.shortenUrl(imageUrl);
					
					html += '<li>';
					html += '<strong>Image:</strong> <a href="' + this.escapeHtml(imageUrl) + '" target="_blank" rel="noopener noreferrer"><code>' + 
					        this.escapeHtml(shortenedUrl) + '</code></a><br>';
					html += '<strong>New Alt:</strong> ' + this.escapeHtml(altText);
					html += '</li>';
				}
			}
			html += '</ul></div></td>';
			html += '</tr>';
		}
		
		html += '</tbody></table>';
			
			$content.html(html);
		},

	/**
	 * Parse header HTML insertions to extract keywords, schema, and other meta tags
	 */
	parseHeaderInsertions: function(htmlString) {
		let result = {
			keywords: null,
			schema: null,
			twitterTitle: null,
			twitterDescription: null
		};
		
		if (!htmlString) {
			return result;
		}
		
		// Create a temporary div to parse the HTML
		let tempDiv = document.createElement('div');
		tempDiv.innerHTML = htmlString;
		
		// Extract keywords meta tag
		let keywordsMeta = tempDiv.querySelector('meta[name="keywords"]');
		if (keywordsMeta) {
			result.keywords = keywordsMeta.getAttribute('content');
		}
		
		// Extract schema (JSON-LD)
		let schemaScript = tempDiv.querySelector('script[type="application/ld+json"]');
		if (schemaScript) {
			result.schema = schemaScript.textContent || schemaScript.innerHTML;
		}
		
		// Extract Twitter meta tags
		let twitterTitleMeta = tempDiv.querySelector('meta[name="twitter:title"], meta[property="twitter:title"]');
		if (twitterTitleMeta) {
			result.twitterTitle = twitterTitleMeta.getAttribute('content');
		}
		
		let twitterDescMeta = tempDiv.querySelector('meta[name="twitter:description"], meta[property="twitter:description"]');
		if (twitterDescMeta) {
			result.twitterDescription = twitterDescMeta.getAttribute('content');
		}
		
		return result;
	},

	/**
	 * Format headings for display
	 */
	formatHeadings: function(headings) {
			if (!headings || headings.length === 0) {
				return '<em>No headings</em>';
			}
			
		if (typeof headings === 'string') {
			return this.escapeHtml(headings);
		}
		
		let html = '<ul style="margin: 0; padding-left: 20px;">';
		for (let i = 0; i < headings.length; i++) {
			html += '<li>' + this.escapeHtml(headings[i]) + '</li>';
		}
			html += '</ul>';
			return html;
		},

	/**
	 * Shorten long URLs for display
	 */
	shortenUrl: function(url) {
		if (!url || url.length <= 60) {
			return url;
		}
		
		// Extract protocol and domain
		let urlParts = url.match(/^(https?:\/\/[^\/]+)(.*)$/);
		if (!urlParts) {
			return url;
		}
		
		let domain = urlParts[1];
		let path = urlParts[2];
		
		// If path is too long, shorten it
		if (path.length > 40) {
			let pathParts = path.split('/').filter(function(part) { return part.length > 0; });
			if (pathParts.length > 2) {
				// Show first part ... last part
				let firstPart = pathParts[0];
				let lastPart = pathParts[pathParts.length - 1];
				return domain + '/' + firstPart + '/.../' + lastPart;
			}
		}
		
		return url;
	},

	/**
	 * Escape HTML to prevent XSS
	 */
	escapeHtml: function(text) {
		if (!text) return '';
		let div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	},

		/**
		 * Add or update URL parameter
		 * 
		 * @param {string} url - The URL to modify
		 * @param {string} param - Parameter name
		 * @param {string} value - Parameter value
		 * @return {string} Modified URL
		 */
	addParameterToUrl: function(url, param, value) {
		// Parse URL
		let urlObj;
		try {
			urlObj = new URL(url);
		} catch (e) {
			// Fallback for relative URLs
			urlObj = new URL(url, window.location.origin);
		}
			
			// Add or update parameter
			urlObj.searchParams.set(param, value);
			
			return urlObj.toString();
		}
	};

	// Initialize on document ready
	$(document).ready(function() {
		metasyncOttoToolbar.init();
	});

})(jQuery);

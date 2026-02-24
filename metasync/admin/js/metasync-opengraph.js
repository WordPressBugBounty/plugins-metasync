/**
 * MetaSync Open Graph Admin JavaScript
 *
 * @package    MetaSync
 * @subpackage MetaSync/admin/js
 * @since      1.0.0
 */

(function ($) {
	'use strict';

	// Check if required dependencies are available
	if (typeof $ === 'undefined') {
		return;
	}
    
	if (typeof metasync_og === 'undefined') {
		return;
	}

	/**
     * Open Graph Meta Box functionality
     */
	var MetaSyncOpenGraph = {
        
		initialized: false,
        
		/**
         * Initialize the functionality
         */
		init: function () {
			if (this.initialized) {
				return;
			}
            
			this.bindEvents();
			this.updateCharacterCounts();
			this.generateInitialPreview();
			this.handleUrlFieldState();
			this.updateUrlFieldOnStatusChange();
			this.handleUsePermalinkClick();
			this.initializeTwitterCardSections();
			this.initialized = true;
		},

		/**
         * Bind event handlers
         */
		bindEvents: function () {
			var self = this;

			// Toggle Open Graph section
			$(document).on('change', '.metasync-og-toggle', function () {
				var $content = $('.metasync-og-content');
				if ($(this).is(':checked')) {
					$content.slideDown();
					self.generatePreview();
				} else {
					$content.slideUp();
				}
			});

			// Toggle Twitter Card sections based on card type
			$(document).on('change', '#metasync_twitter_card', function () {
				var cardType = $(this).val();
				var $appSection = $('.metasync-twitter-app-section');
				var $playerSection = $('.metasync-twitter-player-section');
                
				// Hide all sections first
				//  $appSection.hide();
				//  $playerSection.hide();
				// Hide all sections first and disable their inputs
				$appSection.hide().find('input, select, textarea').prop('disabled', true);
				$playerSection.hide().find('input, select, textarea').prop('disabled', true);
				// Show relevant section based on card type
				if (cardType === 'app') {
					//  $appSection.slideDown();
					$appSection.slideDown().find('input, select, textarea').prop('disabled', false);
				} else if (cardType === 'player') {
					//  $playerSection.slideDown();
					$playerSection.slideDown().find('input, select, textarea').prop('disabled', false);
				}
			});

			// Preview tab switching
			$(document).on('click', '.metasync-preview-tab', function (e) {
				e.preventDefault();
				var platform = $(this).data('platform');
                
				// Update tabs
				$('.metasync-preview-tab').removeClass('active');
				$(this).addClass('active');
                
				// Update panels
				$('.metasync-preview-panel').removeClass('active');
				$('.metasync-preview-panel[data-platform="' + platform + '"]').addClass('active');
                
			});

			// Real-time updates as user types (shorter debounce)
			$(document).on('input keyup paste', '.metasync-og-input', function () {
				self.updateCharacterCount($(this));
				self.updatePreviewInstantly($(this));
			});

			// Image upload for Open Graph
			$(document).on('click', '.metasync-upload-image', function (e) {
				e.preventDefault();
				self.openMediaUploader($(this), '#metasync_og_image', '.metasync-image-preview', '.metasync-remove-image');
			});

			// Image upload for Twitter
			$(document).on('click', '.metasync-upload-twitter-image', function (e) {
				e.preventDefault();
				self.openMediaUploader($(this), '#metasync_twitter_image', '.metasync-twitter-image-preview', '.metasync-remove-twitter-image');
			});

			// Remove Open Graph image
			$(document).on('click', '.metasync-remove-image', function (e) {
				e.preventDefault();
				self.removeImage('#metasync_og_image', '.metasync-image-preview', '.metasync-remove-image');
			});

			// Remove Twitter image
			$(document).on('click', '.metasync-remove-twitter-image', function (e) {
				e.preventDefault();
				self.removeImage('#metasync_twitter_image', '.metasync-twitter-image-preview', '.metasync-remove-twitter-image');
			});

			// Manual preview refresh
			$(document).on('click', '.metasync-refresh-preview', function (e) {
				e.preventDefault();
				self.generatePreview();
			});

			// Auto-sync Twitter fields with Open Graph
			$(document).on('input', '#metasync_og_title', function () {
				var twitterTitle = $('#metasync_twitter_title');
				if (twitterTitle.val() === '' || twitterTitle.data('auto-synced')) {
					twitterTitle.val($(this).val()).data('auto-synced', true);
					self.updateCharacterCount(twitterTitle);
				}
			});

			$(document).on('input', '#metasync_og_description', function () {
				var twitterDesc = $('#metasync_twitter_description');
				if (twitterDesc.val() === '' || twitterDesc.data('auto-synced')) {
					twitterDesc.val($(this).val()).data('auto-synced', true);
					self.updateCharacterCount(twitterDesc);
				}
			});

			$(document).on('input', '#metasync_og_image', function () {
				var twitterImage = $('#metasync_twitter_image');
				if (twitterImage.val() === '' || twitterImage.data('auto-synced')) {
					twitterImage.val($(this).val()).data('auto-synced', true);
					self.updateTwitterImagePreview();
				}
			});

			// Manual input breaks auto-sync
			$(document).on('input', '#metasync_twitter_title, #metasync_twitter_description, #metasync_twitter_image', function () {
				$(this).data('auto-synced', false);
			});
		},

		/**
         * Update character count for an input
         */
		updateCharacterCount: function ($input) {
			var maxLength = parseInt($input.attr('maxlength')) || 0;
			var currentLength = $input.val().length;
			var $counter = $('.metasync-char-count[data-target="' + $input.attr('id') + '"]');
            
			if ($counter.length && maxLength > 0) {
				$counter.text(currentLength + '/' + maxLength);
                
				// Add warning class if approaching limit
				if (currentLength > maxLength * 0.9) {
					$counter.addClass('metasync-char-warning');
				} else {
					$counter.removeClass('metasync-char-warning');
				}
			}
		},

		/**
         * Update all character counts
         */
		updateCharacterCounts: function () {
			var self = this;
			$('.metasync-og-input[maxlength]').each(function () {
				self.updateCharacterCount($(this));
			});
		},

		/**
         * Open WordPress media uploader
         */
		openMediaUploader: function ($button, inputSelector, previewSelector, removeButtonSelector) {
			var self = this;
            
            
			// Check if wp.media is available
			if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
				alert('WordPress media library is not available. Please refresh the page.');
				return;
			}
            
			// Create media frame
			var frame = wp.media({
				title: metasync_og.strings.select_image,
				button: {
					text: metasync_og.strings.use_image
				},
				multiple: false,
				library: {
					type: 'image'
				}
			});

			// Handle image selection
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				var imageUrl = attachment.sizes.large ? attachment.sizes.large.url : attachment.url;
                
				$(inputSelector).val(imageUrl);
				$(previewSelector + ' img').attr('src', imageUrl);
				$(previewSelector).show();
				$(removeButtonSelector).show();
                
				// Update preview instantly if it's the main OG image
				if (inputSelector === '#metasync_og_image') {
					self.updatePreviewImages(imageUrl);
				}
                
				self.debouncePreview();
			});

			// Open the frame
			frame.open();
		},

		/**
         * Remove image
         */
		removeImage: function (inputSelector, previewSelector, removeButtonSelector) {
			$(inputSelector).val('');
			$(previewSelector).hide();
			$(removeButtonSelector).hide();
            
			// Update preview instantly if it's the main OG image
			if (inputSelector === '#metasync_og_image') {
				this.updatePreviewImages('');
			}
            
			this.debouncePreview();
		},

		/**
         * Update Twitter image preview
         */
		updateTwitterImagePreview: function () {
			var imageUrl = $('#metasync_twitter_image').val();
			var $preview = $('.metasync-twitter-image-preview');
			var $removeBtn = $('.metasync-remove-twitter-image');
            
			if (imageUrl) {
				$preview.find('img').attr('src', imageUrl);
				$preview.show();
				$removeBtn.show();
			} else {
				$preview.hide();
				$removeBtn.hide();
			}
		},

		/**
         * Update preview instantly without AJAX for better UX
         */
		updatePreviewInstantly: function ($input) {
			if (!$('.metasync-og-toggle').is(':checked')) {
				return;
			}

			var inputId = $input.attr('id');
			var value = $input.val();
            
			// Update preview content immediately based on input
			switch(inputId) {
				case 'metasync_og_title':
					$('.facebook-preview-title, .twitter-card-title, .linkedin-preview-title').text(value || 'Your Post Title');
					break;
				case 'metasync_og_description':
					$('.facebook-preview-description, .twitter-card-description, .linkedin-preview-description').text(value || 'Your post description will appear here when shared on social media platforms.');
					break;
				case 'metasync_og_image':
					this.updatePreviewImages(value);
					break;
				case 'metasync_og_url':
					var domain = this.extractDomain(value);
					$('.facebook-preview-domain').text(domain.toUpperCase());
					$('.twitter-card-domain, .linkedin-preview-domain').text(domain);
					break;
			}
            
			// Also debounce the full AJAX update
			this.debouncePreview();
		},

		/**
         * Extract domain from URL
         */
		extractDomain: function (url) {
			if (!url) {
				return window.location.hostname || 'your-site.com';
			}
            
			try {
				var a = document.createElement('a');
				a.href = url;
				return a.hostname || 'your-site.com';
			} catch(e) {
				return 'your-site.com';
			}
		},

		/**
         * Update preview images instantly
         */
		updatePreviewImages: function (imageUrl) {
			var $images = $('.facebook-preview-image, .twitter-card-image, .linkedin-preview-image');
            
			if (imageUrl) {
				$images.each(function () {
					var $container = $(this);
					$container.removeClass('preview-no-image');
					$container.html('<img src="' + imageUrl + '" alt="Preview" onerror="this.parentElement.classList.add(\'preview-no-image\'); this.style.display=\'none\'; this.parentElement.innerHTML=\'<div class=\\\"preview-placeholder\\\"><span>📷</span><p>Image failed to load</p></div>\';">');
				});
			} else {
				$images.each(function () {
					var $container = $(this);
					$container.addClass('preview-no-image');
					$container.html('<div class="preview-placeholder"><span>📷</span><p>No image selected</p></div>');
				});
			}
		},

		/**
         * Debounced preview generation
         */
		debouncePreview: function () {
			var self = this;
			clearTimeout(this.previewTimeout);
			this.previewTimeout = setTimeout(function () {
				self.generatePreview();
			}, 1000); // Longer timeout since we have instant updates
		},

		/**
         * Generate initial preview on page load
         */
		generateInitialPreview: function () {
			// Only generate if toggle is checked and we don't already have a preview
			if ($('.metasync-og-toggle').is(':checked')) {
				var $content = $('.metasync-preview-content-wrapper');
				if ($content.is(':empty') || $content.find('.metasync-preview-tabs').length === 0) {
					this.generatePreview();
				} 
			}
		},

		/**
         * Generate social media preview
         */
		generatePreview: function () {
			if (!$('.metasync-og-toggle').is(':checked')) {
				return;
			}

			var $container = $('.metasync-preview-content-wrapper');
			var $loading = $('.metasync-preview-loading');

			// Get current values
			var title = $('#metasync_og_title').val() || $('#title').val() || $('h1.wp-heading-inline').text() || '';
			var description = $('#metasync_og_description').val() || '';
			var image = $('#metasync_og_image').val() || '';
			var url = $('#metasync_og_url').val() || (typeof metasync_og !== 'undefined' && metasync_og.current_permalink) || window.location.href;
            
			// Get Twitter Card data
			var twitter_title = $('#metasync_twitter_title').val() || '';
			var twitter_description = $('#metasync_twitter_description').val() || '';
			var twitter_image = $('#metasync_twitter_image').val() || '';

			// Show loading state
			$loading.show();
			$container.hide();

			// Make AJAX request
			$.ajax({
				url: metasync_og.ajax_url,
				type: 'POST',
				data: {
					action: 'metasync_og_preview',
					nonce: metasync_og.nonce,
					title: title,
					description: description,
					image: image,
					url: url,
					twitter_title: twitter_title,
					twitter_description: twitter_description,
					twitter_image: twitter_image
				},
				success: function (response) {
					if (response.success) {
						$container.html(response.data.preview).show();
					} else {
						$container.html('<p class="metasync-error">Failed to generate preview: ' + (response.data || 'Unknown error') + '</p>').show();
					}
				},
				error: function (xhr, status, error) {
					$container.html('<p class="metasync-error">AJAX Error: ' + error + '</p>').show();
				},
				complete: function () {
					$loading.hide();
				}
			});
		},

		/**
         * Handle URL field state based on post status
         */
		handleUrlFieldState: function () {
			var $urlField = $('#metasync_og_url');
			var $urlNotice = $('.metasync-url-disabled-notice');
			var $usePermalinkBtn = $('.metasync-use-permalink');
            
			if ($urlField.length) {
				// Check if this is a new post (auto-draft only)
				var isNewPost = $urlField.prop('disabled');
                
				if (isNewPost) {
					// For new posts (auto-draft), disable the field and show notice
					$urlField.prop('disabled', true);
					if ($urlNotice.length === 0) {
						$urlField.after('<p class="description metasync-url-disabled-notice"><span class="dashicons dashicons-info"></span>This field will be automatically populated with the post permalink after you save the post for the first time.</p>');
					}
					$usePermalinkBtn.hide();
				} else {
					// For existing posts (draft, published, etc.), enable the field and show permalink button
					$urlField.prop('disabled', false);
					$urlNotice.remove();
					$usePermalinkBtn.show();
				}
			}
		},

		/**
         * Update URL field when post status changes
         */
		updateUrlFieldOnStatusChange: function () {
			var self = this;
            
			// Listen for post status changes (when user clicks Save Draft, Publish, etc.)
			$(document).on('click', '#publish, #save-post, #save-post-ajax', function () {
				// Small delay to allow WordPress to process the status change
				setTimeout(function () {
					self.handleUrlFieldState();
				}, 500);
			});
            
			// Also listen for form submission
			$(document).on('submit', '#post', function () {
				setTimeout(function () {
					self.handleUrlFieldState();
				}, 1000);
			});
		},

		/**
         * Handle "Use Post Permalink" button click
         */
		handleUsePermalinkClick: function () {
			var self = this;
            
			$(document).on('click', '.metasync-use-permalink', function (e) {
				e.preventDefault();
                
				// Get the current permalink from the placeholder or generate it
				var $urlField = $('#metasync_og_url');
				var currentPermalink = $urlField.attr('placeholder');
                
				// If no placeholder, try to get it from the localized data
				if (!currentPermalink && typeof metasync_og !== 'undefined' && metasync_og.current_permalink) {
					currentPermalink = metasync_og.current_permalink;
				}
                
				if (currentPermalink) {
					$urlField.val(currentPermalink);
					// Trigger change event to update preview
					$urlField.trigger('change');
				}
			});
		},

		/**
         * Initialize Twitter Card sections based on current card type
         */
		initializeTwitterCardSections: function () {
			var cardType = $('#metasync_twitter_card').val();
			var $appSection = $('.metasync-twitter-app-section');
			var $playerSection = $('.metasync-twitter-player-section');
            
			// Hide all sections first
			// $appSection.hide();
			//  $playerSection.hide();
			// Hide all sections first and disable their inputs
			$appSection.hide().find('input, select, textarea').prop('disabled', true);
			$playerSection.hide().find('input, select, textarea').prop('disabled', true);
			// Show relevant section based on current card type
			if (cardType === 'app') {
				$appSection.show();
				$appSection.show().find('input, select, textarea').prop('disabled', false);
			} else if (cardType === 'player') {
				// $playerSection.show();
				$playerSection.show().find('input, select, textarea').prop('disabled', false);
			}
		}


	};


	/**
     * Initialize when document is ready
     */
	$(document).ready(function () {
		if ($('.metasync-opengraph-meta-box').length) {
			MetaSyncOpenGraph.init();
		} else {
			// Try again after a delay in case the meta box is loaded dynamically
			setTimeout(function () {
				if ($('.metasync-opengraph-meta-box').length) {
					MetaSyncOpenGraph.init();
				} else {
				}
			}, 1000);
		}
	});

	// Also try to initialize on window load as a fallback
	$(window).on('load', function () {
		if ($('.metasync-opengraph-meta-box').length && !MetaSyncOpenGraph.initialized) {
			MetaSyncOpenGraph.init();
		}
	});

})(jQuery);

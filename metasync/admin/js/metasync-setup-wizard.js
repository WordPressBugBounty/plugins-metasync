/**
 * Setup Wizard JavaScript
 *
 * Handles wizard navigation, validation, SSO integration, and imports.
 *
 * @package    Metasync
 * @subpackage Metasync/admin/js
 */

/* global metasyncWizardData */

(function ($) {
	'use strict';

	var MetasyncWizard = {
		currentStep: 1,
		totalSteps: 6,
		state: {},
		ssoPopup: null,
		ssoPollingInterval: null,

		/**
         * Initialize wizard
         */
		init: function () {
			this.bindEvents();
			this.renderStep(this.currentStep);
		},

		/**
         * Bind all event handlers
         */
		bindEvents: function () {
			var self = this;

			// Navigation buttons
			$('.wizard-btn-next').on('click', function () {
				self.nextStep();
			});

			$('.wizard-btn-prev').on('click', function () {
				self.prevStep();
			});

			$('.wizard-btn-skip').on('click', function () {
				self.skipStep();
			});

			// Progress step indicators (click to jump to completed steps)
			$('.wizard-progress-step').on('click', function () {
				var step = parseInt($(this).data('step'));
				if (self.isStepAccessible(step)) {
					self.goToStep(step);
				}
			});

			// Connection button (reuse existing SSO)
			$('#wizard-connect-btn').on('click', function () {
				self.triggerSSO();
			});

			// Skip connection link
			$('.wizard-skip-connection').on('click', function (e) {
				e.preventDefault();
				self.nextStep();
			});

			// Import buttons
			$(document).on('click', '.wizard-import-btn', function () {
				var plugin = $(this).data('plugin');
				self.runImport(plugin, $(this));
			});

			// Import option checkboxes - enable/disable import button
			$(document).on('change', '.import-option', function () {
				var $card = $(this).closest('.wizard-import-card');
				self.updateImportButtonState($card);
			});

			// Apply recommended SEO settings
			$('.wizard-apply-recommended').on('click', function () {
				$('input[name="seo_category_archives"]').prop('checked', true);
				$('input[name="seo_tag_archives"]').prop('checked', true);
				$('input[name="seo_date_archives"]').prop('checked', false);
				$('input[name="seo_author_archives"]').prop('checked', false);
			});

			// Schema enable/disable
			$('#schema-enabled').on('change', function () {
				if ($(this).is(':checked')) {
					$('.wizard-schema-settings').slideDown();
				} else {
					$('.wizard-schema-settings').slideUp();
				}
			});

			// Complete button
			$('.wizard-complete-btn').on('click', function () {
				self.completeWizard();
			});
		},

		/**
         * Go to next step
         */
		nextStep: function () {
			if (this.validateCurrentStep()) {
				this.saveStepData();
				if (this.currentStep < this.totalSteps) {
					this.currentStep++;
					this.renderStep(this.currentStep);
				}
			}
		},

		/**
         * Go to previous step
         */
		prevStep: function () {
			if (this.currentStep > 1) {
				this.currentStep--;
				this.renderStep(this.currentStep);
			}
		},

		/**
         * Skip current step
         */
		skipStep: function () {
			// Just go to next step without validation
			if (this.currentStep < this.totalSteps) {
				this.currentStep++;
				this.renderStep(this.currentStep);
			}
		},

		/**
         * Go to specific step
         */
		goToStep: function (step) {
			if (step >= 1 && step <= this.totalSteps) {
				this.currentStep = step;
				this.renderStep(step);
			}
		},

		/**
         * Render specific step
         */
		renderStep: function (step) {
			// Hide all steps
			$('.wizard-step').removeClass('active');

			// Show current step
			$('.wizard-step[data-step="' + step + '"]').addClass('active');

			// Update progress bar
			var progress = (step / this.totalSteps) * 100;
			$('.wizard-progress-bar').css('width', progress + '%');

			// Update step indicators
			$('.wizard-progress-step').each(function () {
				var stepNum = parseInt($(this).data('step'));
				$(this).toggleClass('active', stepNum === step);
				$(this).toggleClass('completed', stepNum < step);
			});

			// Update navigation buttons
			$('.wizard-btn-prev').prop('disabled', step === 1);

			if (step === this.totalSteps) {
				$('.wizard-btn-next').hide();
				$('.wizard-btn-skip').hide();
			} else {
				$('.wizard-btn-next').show().text(step === this.totalSteps - 1 ? 'Next →' : 'Next →');
				$('.wizard-btn-skip').toggle(step > 1);
			}

			// Scroll to top
			$('html, body').scrollTop(0);

			// Initialize import button states for step 3
			if (step === 3) {
				var self = this;
				$('.wizard-import-card').each(function () {
					self.updateImportButtonState($(this));
				});
			}
		},

		/**
         * Validate current step
         */
		validateCurrentStep: function () {
			// All steps are optional, so always return true
			// This allows users to skip any step
			return true;
		},

		/**
         * Save step data to server
         */
		saveStepData: function () {
			var stepData = {};

			// Collect data based on current step
			switch(this.currentStep) {

				case 4: // SEO Settings
					stepData.seo_settings = {
						date_archives: $('input[name="seo_date_archives"]').is(':checked'),
						author_archives: $('input[name="seo_author_archives"]').is(':checked'),
						category_archives: $('input[name="seo_category_archives"]').is(':checked'),
						tag_archives: $('input[name="seo_tag_archives"]').is(':checked')
					};
					break;

				case 5: // Schema
					stepData.schema = {
						enabled: $('#schema-enabled').is(':checked'),
						default_type: $('input[name="default_schema_type"]:checked').val()
					};
					break;

				default:
					// No data to save for this step
					return;
			}

			// Save via AJAX
			$.post(ajaxurl, {
				action: 'metasync_save_wizard_progress',
				nonce: metasyncWizardData.nonce,
				step: this.currentStep,
				data: stepData
			});
		},

		/**
         * Trigger SSO authentication
         */
		triggerSSO: function () {
			var self = this;
			var $button = $('#wizard-connect-btn');
			var pluginName = metasyncWizardData.pluginName || 'Search Atlas';

			$button.prop('disabled', true).text('Opening SSO...');

			$.post(ajaxurl, {
				action: 'generate_sso_url',
				nonce: metasyncWizardData.ssoNonce
			}, function (response) {
				if (response.success) {
					// Open SSO popup and store reference
					self.ssoPopup = window.open(
						response.data.sso_url,
						pluginName.replace(/\s+/g, '') + 'SSO',
						'width=600,height=700'
					);

					if (self.ssoPopup) {
						$button.text('Waiting for authentication...');
						// Poll for completion
						self.pollSSOStatus(response.data.nonce_token);
					} else {
						$button.prop('disabled', false).text('Connect with ' + pluginName);
						alert('Popup was blocked. Please allow popups for this site and try again.');
					}
				} else {
					$button.prop('disabled', false).text('Connect with ' + pluginName);
					alert('Failed to generate SSO URL. Please try again.');
				}
			}).fail(function () {
				$button.prop('disabled', false).text('Connect with ' + pluginName);
				alert('An error occurred. Please try again.');
			});
		},

		/**
         * Poll SSO status
         */
		pollSSOStatus: function (nonceToken) {
			var self = this;
			var attempts = 0;
			var maxAttempts = 12; // 60 seconds (12 * 5 seconds)
			var pluginName = metasyncWizardData.pluginName || 'Search Atlas';
			var $button = $('#wizard-connect-btn');

			self.ssoPollingInterval = setInterval(function () {
				attempts++;

				// Update button with countdown
				var timeLeft = Math.ceil((maxAttempts - attempts) * 5);
				$button.text('Waiting for authentication (' + timeLeft + 's)...');

				$.post(ajaxurl, {
					action: 'check_sso_status',
					nonce: metasyncWizardData.ssoNonce,
					nonce_token: nonceToken
				}, function (response) {
					if (response.success && response.data.updated) {
						// Stop polling and close popup
						clearInterval(self.ssoPollingInterval);
						self.ssoPollingInterval = null;

						if (self.ssoPopup && !self.ssoPopup.closed) {
							self.ssoPopup.close();
						}
						self.ssoPopup = null;

						var statusCode = response.data.status_code || 200;

						if (statusCode === 200) {
							// Success: Update UI to show connected state
							$('.wizard-step-connection').html(
								'<div class="wizard-step-header">' +
								'<h2>🔗 Connect to ' + pluginName + '</h2>' +
								'<p>Link your WordPress site to your ' + pluginName + ' account for advanced features.</p>' +
								'</div>' +
								'<div class="wizard-step-content">' +
								'<div class="wizard-connection-success">' +
								'<span class="success-icon">✓</span>' +
								'<h3>Successfully Connected!</h3>' +
								'<p>Your site is linked to ' + pluginName + '.</p>' +
								'</div>' +
								'</div>'
							);
						} else if (statusCode === 403) {
							// Authentication failed
							self.resetSSOButton();
							alert('Authentication failed. Please check your credentials and try again.');
						} else if (statusCode === 500) {
							// Server error
							self.resetSSOButton();
							alert('Server error occurred. Please try again later or contact support.');
						} else {
							// Unknown status
							self.resetSSOButton();
							alert('Unexpected response received. Please try again.');
						}
					}
				}).fail(function () {
					// Continue polling even if individual request fails
					// Don't show error for temporary network issues
					console.log('SSO polling request failed, continuing...');
				});

				// Stop polling after max attempts (timeout)
				if (attempts >= maxAttempts) {
					clearInterval(self.ssoPollingInterval);
					self.ssoPollingInterval = null;

					if (self.ssoPopup && !self.ssoPopup.closed) {
						self.ssoPopup.close();
					}
					self.ssoPopup = null;

					self.resetSSOButton();
					alert('Authentication timed out after 60 seconds. Please try again and complete the authentication more quickly.');
				}
			}, 5000); // Poll every 5 seconds
		},

		/**
         * Reset SSO button to initial state
         */
		resetSSOButton: function () {
			var pluginName = metasyncWizardData.pluginName || 'Search Atlas';
			var $button = $('#wizard-connect-btn');

			$button.prop('disabled', false).text('Connect with ' + pluginName);

			// Clear any active polling
			if (this.ssoPollingInterval) {
				clearInterval(this.ssoPollingInterval);
				this.ssoPollingInterval = null;
			}

			// Close popup if still open
			if (this.ssoPopup && !this.ssoPopup.closed) {
				this.ssoPopup.close();
			}
			this.ssoPopup = null;
		},

		/**
         * Update import button state based on checkbox selection
         */
		updateImportButtonState: function ($card) {
			var $button = $card.find('.wizard-import-btn');
			var hasChecked = $card.find('.import-option:checked').length > 0;
			$button.prop('disabled', !hasChecked);
		},

		/**
         * Run import from selected plugin
         */
		runImport: function (plugin, $button) {
			var $card = $button.closest('.wizard-import-card');
			var $progress = $card.find('.import-progress');
			var $progressBar = $card.find('.import-progress-bar');

			// Get selected import types
			var selectedTypes = [];
			$card.find('.import-option:checked').each(function () {
				selectedTypes.push($(this).data('type'));
			});

			if (selectedTypes.length === 0) {
				alert('Please select at least one import type');
				return;
			}

			$button.prop('disabled', true).text('Importing...');
			$progress.show();

			var totalImports = selectedTypes.length;
			var completedImports = 0;

			// Import each type sequentially
			function importNext(index) {
				if (index >= selectedTypes.length) {
					// All imports complete
					$button.text('✓ Import Complete').addClass('success');
					setTimeout(function () {
						$progress.fadeOut();
					}, 1000);
					return;
				}

				var type = selectedTypes[index];

				$.post(ajaxurl, {
					action: 'metasync_import_external_data',
					nonce: metasyncWizardData.importNonce,
					type: type,
					plugin: plugin
				}, function (response) {
					completedImports++;
					var progress = (completedImports / totalImports) * 100;
					$progressBar.css('width', progress + '%');

					// Import next type
					importNext(index + 1);
				}).fail(function () {
					$button.prop('disabled', false).text('Import Failed');
					$progress.hide();
					alert('Import failed for ' + type + '. Please try again.');
				});
			}

			importNext(0);
		},

		/**
         * Complete wizard
         */
		completeWizard: function () {
			var $button = $('.wizard-complete-btn');

			$button.prop('disabled', true).text('Completing setup...');

			$.post(ajaxurl, {
				action: 'metasync_complete_wizard',
				nonce: metasyncWizardData.nonce
			}, function (response) {
				if (response.success) {
					// Redirect to dashboard
					window.location.href = metasyncWizardData.dashboardUrl;
				} else {
					$button.prop('disabled', false).text('Get Started with MetaSync');
					alert('Failed to complete wizard. Please try again.');
				}
			}).fail(function () {
				$button.prop('disabled', false).text('Get Started with MetaSync');
				alert('An error occurred. Please try again.');
			});
		},

		/**
         * Check if step is accessible
         */
		isStepAccessible: function (step) {
			// Allow jumping to any previous step or current step
			return step <= this.currentStep;
		}
	};

	// Initialize wizard on page load
	$(document).ready(function () {
		if ($('.metasync-wizard-wrap').length) {
			MetasyncWizard.init();
		}
	});

})(jQuery);

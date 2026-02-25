(function ($) {
	'use strict';

	/**
	 * All of the code for your admin-facing JavaScript source
	 * should reside in this file.
	 *
	 * Note: It has been assumed you will write jQuery code here, so the
	 * $ function reference has been prepared for usage within the scope
	 * of this function.
	 *
	 * This enables you to define handlers, for when the DOM is ready:
	 *
	 * $(function() {
	 *
	 * });
	 *
	 * When the window is loaded:
	 *
	 * $( window ).load(function() {
	 *
	 * });
	 *
	 * ...and/or other possibilities.
	 *
	 * Ideally, it is not considered best practise to attach more than a
	 * single DOM-ready or window-load handler for a particular page.
	 * Although scripts in the WordPress core, Plugins and Themes may be
	 * practising this, we should strive to set a better example in our own work.
	 */

	// ========================================
	// UTILITY FUNCTIONS (Consolidated to reduce duplication)
	// ========================================

	/**
	 * Get plugin name from config or use default
	 * @returns {string} Plugin name
	 */
	function getPluginName() {
		return window.MetasyncConfig && window.MetasyncConfig.pluginName ? window.MetasyncConfig.pluginName : 'Search Atlas';
	}

	/**
	 * Get OTTO name from config or use default
	 * @returns {string} OTTO name
	 */
	function getOttoName() {
		return window.MetasyncConfig && window.MetasyncConfig.ottoName ? window.MetasyncConfig.ottoName : 'OTTO';
	}

	/**
	 * Update integration status indicator in header
	 * @param {boolean} isIntegrated - Whether the integration is active
	 * @param {string} statusText - Status text to display
	 * @param {string} titleText - Tooltip text
	 */
	function updateHeaderStatus(isIntegrated, statusText, titleText) {
		var $statusIndicator = $('.metasync-integration-status');
		if ($statusIndicator.length > 0) {
			if (isIntegrated) {
				$statusIndicator.removeClass('not-integrated').addClass('integrated');
			} else {
				$statusIndicator.removeClass('integrated').addClass('not-integrated');
			}
			$statusIndicator.find('.status-text').text(statusText);
			$statusIndicator.attr('title', titleText);
			console.log('🔄 Updated header status to: ' + statusText);
		}
	}

	/**
	 * Collect whitelabel form fields for submission
	 * @returns {string} Serialized whitelabel field data
	 */
	function collectWhitelabelFields() {
		var whitelabelFields = [];
		
		// Text/URL fields
		var logoField = $('input[name="metasync_options[whitelabel][logo]"]');
		var domainField = $('input[name="metasync_options[whitelabel][domain]"]');
		var passwordField = $('input[name="metasync_options[whitelabel][settings_password]"]');
		
		if (logoField.length > 0 && logoField.val()) {
			whitelabelFields.push('metasync_options[whitelabel][logo]=' + encodeURIComponent(logoField.val()));
		}
		if (domainField.length > 0 && domainField.val()) {
			whitelabelFields.push('metasync_options[whitelabel][domain]=' + encodeURIComponent(domainField.val()));
		}
		if (passwordField.length > 0 && passwordField.val()) {
			whitelabelFields.push('metasync_options[whitelabel][settings_password]=' + encodeURIComponent(passwordField.val()));
		}
		
		// Checkbox fields (handle both checked and unchecked)
		var hideFields = ['hide_dashboard', 'hide_settings', 'hide_indexation_control', 
		                  'hide_redirections', 'hide_robots', 'hide_sync_log', 
		                  'hide_compatibility', 'hide_advanced'];
		
		hideFields.forEach(function (fieldName) {
			var checkbox = $('input[name="metasync_options[whitelabel][' + fieldName + ']"]');
			if (checkbox.length > 0) {
				var value = checkbox.is(':checked') ? '1' : '0';
				whitelabelFields.push('metasync_options[whitelabel][' + fieldName + ']=' + value);
			}
		});
		
		return whitelabelFields.join('&');
	}

	/**
	 * Display notice message in plugin area
	 * @param {string} type - Notice type: 'success' or 'error'
	 * @param {string} title - Notice title
	 * @param {string} message - Notice message
	 * @param {string} cssClass - Additional CSS class for the notice
	 * @param {number} autoHideDelay - Auto-hide delay in ms (0 = no auto-hide)
	 */
	function showPluginNotice(type, title, message, cssClass, autoHideDelay) {
		cssClass = cssClass || 'metasync-notice';
		autoHideDelay = autoHideDelay || 0;
		
		var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
		var noticeHTML = '<div class="notice ' + noticeClass + ' is-dismissible ' + cssClass + '" style="margin: 20px 0; padding: 12px;">' +
			'<p><strong>' + title + '</strong><br/>' + message + '</p>' +
		'</div>';
		
		// Remove existing notices of same class
		$('.' + cssClass).remove();
		
		// Insert between navigation menu and page content
		var $navWrapper = $('.metasync-nav-wrapper');
		if ($navWrapper.length > 0) {
			$navWrapper.after(noticeHTML);
		} else {
			$('.metasync-dashboard-wrap').prepend(noticeHTML);
		}
		
		// Scroll to the top to ensure visibility
		$('html, body').animate({ scrollTop: 0 }, 'slow');
		
		// Auto-hide if delay specified
		if (autoHideDelay > 0) {
			setTimeout(function () {
				$('.' + cssClass).fadeOut(300, function () { 
					$(this).remove(); 
				});
			}, autoHideDelay);
		}
	}

	/**
	 * Prevent dashboard.js interference with button
	 * @param {jQuery} $button - Button element to protect
	 */
	function preventDashboardInterference($button) {
		$button.removeClass('dashboard-loading');
		$button.addClass('no-loading metasync-sa-connect-protected');
		$button.prop('disabled', false);
	}

	// ========================================
	// ORIGINAL FUNCTIONS
	// ========================================

	function metasync_syncPostsAndPages() {
		wp.ajax.post('lgSendCustomerParams', {})
			.done(function (response) {
				console.log(response);
			});
	}

	function metasyncGenerateAPIKey() {
		return Math.random().toString(36).substring(2, 15) +
			Math.random().toString(36).substring(2, 15);
	}

	function metasyncLGLogin(user, pass) {
		jQuery.post(ajaxurl, {
			action: 'lglogin',
			username: user, password: pass
		}, function (response) {
			if (typeof response.token !== 'undefined') {
				$('#linkgraph_token').val(response.token);
				$('#linkgraph_customer_id').val(response.customer_id);
				$('.input.lguser,#lgerror').addClass('hidden');
				localStorage.setItem('token', response.token);
			} else {
				$('#lgerror').html(`${response.detail} (${response.kind})`).removeClass('hidden');
			}
		}
		);
	}

	function setToken() {
		if ($('#linkgraph_token') && $('#linkgraph_token').val()) {
			localStorage.setItem('token', $('#linkgraph_token').val());
		}
	}

	// Search Atlas Connect functions
	// Handles 1-click authentication to retrieve Search Atlas API key and Otto UUID.
	// Does NOT create WordPress login sessions.
	var saConnectPollingInterval = null;
	var saConnectWindow = null;

	function handleSearchAtlasConnect() {
		var $button = $('#connect-searchatlas-btn');	
		// Only check for button (status/progress elements are created dynamically)
		if (!$button.length) {
			return;
		}
		
		// Enhanced loading state with spinner and CSS class (prevent dashboard.js conflicts)
		$button.prop('disabled', true)
			   .addClass('connecting no-loading') // Add 'no-loading' to prevent dashboard.js interference
			   .removeClass('dashboard-loading') // Remove any existing dashboard loading
			   .html('<span class="metasync-sa-connect-loading"></span> Initializing...');
		
		// Hide any existing status/progress containers (may not exist yet)
		$('#sa-connect-status-message').hide();
		$('.metasync-sa-connect-progress').hide();

		// Initialize progress display immediately (no separate status message)
		initializeProgressDisplay();

		// Generate nonce for WordPress AJAX security
		var ajaxNonce = metaSync.sa_connect_nonce || '';
		if (!ajaxNonce) {
			return;
		}

		// Make AJAX call to generate SSO URL
		var ajaxUrl = ajaxurl || metaSync.ajax_url;
	
	
		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'generate_searchatlas_connect_url',
				nonce: ajaxNonce
			},
			timeout: 30000, // 30 second timeout
			success: function (response) {
	
				
				if (response.success) {
					
					// Update button state
					$button.removeClass('connecting dashboard-loading')
						   .addClass('authenticating no-loading') // Maintain no-loading class
						   .html('<span class="metasync-sa-connect-loading"></span> Opening Authentication...');
					
	
					
					// Small delay for better UX (let user see the message)
					setTimeout(function () {
						console.log('🔍 Opening connect popup with URL:', response.data.connect_url);
						
						// Detect mobile device for better experience
						var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
						var windowFeatures;
						
	
						
						if (isMobile) {
						// On mobile, open in same tab for better experience
							showConnectInfo('📱 Mobile Authentication', 
								'Opening ' + getPluginName() + ' authentication. You\'ll be redirected back after logging in.');
							window.location.href = response.data.connect_url;
							return;
						} else {
							// Desktop: enhanced popup window
							var screenWidth = window.screen.width;
							var screenHeight = window.screen.height;
							var windowWidth = Math.min(650, screenWidth * 0.8);
							var windowHeight = Math.min(750, screenHeight * 0.8);
							var left = (screenWidth - windowWidth) / 2;
							var top = (screenHeight - windowHeight) / 2;
							
							windowFeatures = 'width=' + windowWidth + 
											',height=' + windowHeight + 
											',left=' + left + 
											',top=' + top + 
											',scrollbars=yes,resizable=yes,toolbar=no,location=yes,status=yes';
							
	
						}
						
	
						
						// Open SSO URL in popup with enhanced window properties
						saConnectWindow = window.open(
							response.data.connect_url, 
							'searchatlas-connect',
							windowFeatures
						);
						
	
						
						// Enhanced popup blocked detection
						setTimeout(function () {
							if (!saConnectWindow || saConnectWindow.closed || typeof saConnectWindow.closed === 'undefined') {
								showConnectError('🚫 Popup Blocked', 
									'Your browser blocked the authentication popup. Please allow popups for this site and try again.',
									[{
										text: '🔄 Try Again',
										action: function () {
											handleSearchAtlasConnect(); 
										}
									}, {
										text: '📝 How to Enable Popups',
										action: function () { 
											showPopupHelp();
										}
									}, {
										text: '🖥️ Open in New Tab',
										action: function () {
											window.open(response.data.connect_url, '_blank');
											startSearchAtlasPolling(response.data.nonce_token);
										}
									}]
								);
								resetConnectButton();
								return;
							}
							
							// Add focus to popup window
							try {
								saConnectWindow.focus();
							} catch(e) {
								// Ignore focus errors
							}
							
							// Update progress display and start polling
							updateProgress(10, 1, 6); // Show initial progress
							console.log('🔍 Starting connect polling with nonce:', response.data.nonce_token);
							setTimeout(function () {
								startSearchAtlasPolling(response.data.nonce_token);
							}, 200);
							
						}, 100); // Small delay to let popup settle
						
					}, 500); // 500ms delay for better UX
					
				} else {
					var errorMessage = response.data.message || 'Failed to generate connect URL';
					showConnectError('❌ Connection Failed', 
						errorMessage,
						[{
							text: '🔄 Retry Connection',
							action: function () {
								handleSearchAtlasConnect(); 
							}
						}]
					);
					resetConnectButton();
				}
			},
			error: function (xhr, status, error) {
				console.error('🐛 DEBUG: AJAX error occurred:', {
					xhr: xhr,
					status: status,
					error: error,
					responseText: xhr.responseText,
					responseJSON: xhr.responseJSON,
					readyState: xhr.readyState,
					ajaxUrl: ajaxUrl
				});
				
				var errorMessage = 'Network error occurred while connecting to ' + getPluginName();
				
				// Provide specific error messages based on the error type
				if (status === 'timeout') {
					errorMessage = 'Request timed out. Please check your internet connection and try again.';
				} else if (status === 'error') {
					if (xhr.status === 0) {
						errorMessage = 'Unable to connect. Please check if WordPress admin-ajax.php is accessible.';
					} else if (xhr.status === 403) {
						errorMessage = 'Access denied. Please refresh the page and try again.';
					} else if (xhr.status === 500) {
						errorMessage = 'Server error occurred. Please check server logs for details.';
					} else {
						errorMessage = 'HTTP Error ' + xhr.status + ': ' + xhr.statusText;
					}
				} else if (xhr.responseJSON && xhr.responseJSON.message) {
					errorMessage = xhr.responseJSON.message;
				}
				
				showConnectError('🌐 Network Error', errorMessage,
					[{
						text: '🔄 Retry Connection',
						action: function () {
							handleSearchAtlasConnect(); 
						}
					}, {
						text: '🔧 Check Network',
						action: function () { 
							console.log('Network diagnostics:', {
								status: status,
								error: error,
								xhr: xhr
							});
						}
					}]
				);
				resetConnectButton();
			}
		});
	}

	function resetConnectButton() {
		var $button = $('#connect-searchatlas-btn');
		var $progressContainer = $('.metasync-sa-connect-progress');
		var hasApiKey = $('#searchatlas-api-key').val().trim() !== '';
		
		$button.prop('disabled', false)
			   .removeClass('connecting authenticating success dashboard-loading') // Remove all loading classes
			   .html(hasApiKey ? '🔄 Re-authenticate with ' + getPluginName() : '🔗 Connect to ' + getPluginName());
		$progressContainer.hide();
	}

	function startSearchAtlasPolling(nonceToken) {
		var pollCount = 0;
		var maxPolls = 12; // Poll for 60 seconds (12 * 5 seconds)
		var $progressContainer = $('.metasync-sa-connect-progress');
		var $progressFill = $('.metasync-sa-connect-progress-fill');
		var $progressText = $('.metasync-sa-connect-progress-text');
		var $button = $('#connect-searchatlas-btn');
		
		// Progress display should already be initialized, just update it
		updateProgress(0, 0, maxPolls);

		
		saConnectPollingInterval = setInterval(function () {
			pollCount++;
			

			// Update progress bar
			var progress = Math.min((pollCount / maxPolls) * 100, 100);
			updateProgress(progress, pollCount, maxPolls);
			
			// Check if window was closed manually - but continue polling
			if (saConnectWindow && saConnectWindow.closed) {
				// Popup closed, but continue polling to check for authentication success
				console.log('🔍 Connect popup closed, continuing to poll for authentication success...');
				saConnectWindow = null; // Clear reference to closed window
				
				// Update UI to show we're still checking
				updateProgress(75, pollCount, maxPolls); 
				$progressText.text('Popup closed - checking authentication status...');
				
				// Continue polling - don't return, let the polling continue
			}
			
			// Stop polling after max attempts
			if (pollCount >= maxPolls) {
				stopSearchAtlasPolling();
				if (saConnectWindow) {
					saConnectWindow.close();
				}
				
				// ✅ Reset the authentication flow while keeping the timeout component
				resetConnectButton();
				
				showConnectError('⏰ Authentication Timeout', 
					'The authentication process timed out after 60 seconds. Please complete the authentication more quickly or check for network issues.',
					[{
						text: '🔄 Try Again',
						action: function () {
							handleSearchAtlasConnect(); 
						}
					}, {
						text: '💬 Contact Support',
						action: function () { 
							var supportEmail = metaSync.support_email || 'support@searchatlas.com';
							window.open('mailto:' + supportEmail + '?subject=Connect Authentication Timeout (30s)', '_blank');
						}
					}]
				);
				return;
			}

			// Update button state periodically  
			if (pollCount % 2 === 0) { // Every 10 seconds
				var timeLeft = Math.ceil((maxPolls - pollCount) * 5);
				$button.html('<span class="metasync-sa-connect-loading"></span> Waiting for Authentication (' + timeLeft + 's left)');
			}

			// Check if API key was updated
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'check_searchatlas_connect_status',
					nonce: metaSync.sa_connect_nonce || '',
					nonce_token: nonceToken
				},
				success: function (response) {
					if (response.success && response.data.updated) {
						stopSearchAtlasPolling();
						if (saConnectWindow) {
							saConnectWindow.close();
						}
						
						// Show completion animation
						updateProgress(100, maxPolls, maxPolls);
						
						var statusCode = response.data.status_code || 200;
						
						// Handle different status codes with enhanced UX
						if (statusCode === 200) {
							// Success: Update all UI elements to reflect connected state
							updateUIForConnectedState(response.data.api_key, response.data.otto_pixel_uuid);
							
							// Refresh Plugin Auth Token field to show the auto-generated token
							// This will either show the existing token or the newly auto-generated one
							$.ajax({
								url: ajaxurl,
								type: 'POST',
								data: {
									action: 'get_plugin_auth_token',
									nonce: metaSync.sa_connect_nonce || ''
								},
								success: function (tokenResponse) {
									if (tokenResponse.success && tokenResponse.data.plugin_auth_token) {
										$('#apikey').val(tokenResponse.data.plugin_auth_token);
										console.log('🔑 Plugin Auth Token field updated after connect success');
									}
								},
								error: function () {
									console.log('⚠️ Could not refresh Plugin Auth Token field, but connect authentication was successful');
								}
							});
							
							$button.removeClass('connecting authenticating dashboard-loading')
								.addClass('success no-loading')
								.html('✅ Authentication Complete!');

							// Add success animation to container
							$button.closest('.metasync-sa-connect-container').addClass('metasync-sa-connect-success-animation');
							setTimeout(function () {
								$button.closest('.metasync-sa-connect-container').removeClass('metasync-sa-connect-success-animation');
							}, 600);
						
							// Track 1-click activation in Mixpanel
							var hasExistingApiKey = $('#searchatlas-api-key').val() && $('#searchatlas-api-key').val().trim() !== '';
							$.ajax({
								url: metaSync.ajax_url,
								type: 'POST',
								data: {
									action: 'metasync_track_one_click_activation',
									auth_method: 'searchatlas_connect',
									is_reconnection: hasExistingApiKey
								}
							});
							
							showConnectSuccess('🎉 Authentication Successful', 
								'Your ' + getPluginName() + ' account has been synced successfully! The page will reload to apply your new settings.',
								[{
									text: '🔄 Reload Now',
									action: function () {
										location.reload(); 
									}
								}]
							);

							// Auto-reload with countdown
							var countdown = 3;
							var countdownInterval = setInterval(function () {
								countdown--;
								if (countdown > 0) {
									$button.html('✅ Reloading in ' + countdown + '...');
								} else {
									clearInterval(countdownInterval);
									location.reload();
								}
							}, 1000);
							
						} else if (statusCode === 404) {
							// Website not registered
							var effectiveDomain = response.data.effective_domain || metaSync.dashboard_domain;
							showConnectNotRegistered(effectiveDomain);
							resetConnectButton();
							
						} else if (statusCode === 500) {
							// Server error
							showConnectError('🔧 Server Error', 
								'A server error occurred during authentication. This is usually temporary.',
								[{
									text: '🔄 Try Again',
									action: function () {
										handleSearchAtlasConnect(); 
									}
								}, {
									text: '💬 Contact Support',
									action: function () { 
										var supportEmail = metaSync.support_email || 'support@searchatlas.com';
										window.open('mailto:' + supportEmail + '?subject=Connect Server Error (Code 500)', '_blank');
									}
								}]
							);
							resetConnectButton();
							
						} else {
							// Unknown status
							showConnectError('❓ Unexpected Status', 
								'Received an unexpected status code (' + statusCode + ') during authentication.',
								[{
									text: '🔄 Try Again',
									action: function () {
										handleSearchAtlasConnect(); 
									}
								}]
							);
							resetConnectButton();
						}
					}
				},
				error: function (xhr, status, error) {
					// Continue polling even if individual request fails, but provide feedback
					if (pollCount % 6 === 0) { // Every 30 seconds, show a subtle warning
						console.log('Connect polling request failed, continuing... Error:', error);
						// Don't show error to user for temporary network issues during polling
					}

				}
			});
		}, 5000); // Poll every 5 seconds
	}

	function initializeProgressDisplay() {
		var $progressContainer = $('.metasync-sa-connect-progress');
		var $button = $('#connect-searchatlas-btn');
		
		// Hide any existing status messages to avoid duplication
		hideConnectStatus();
		
		// Create progress elements if they don't exist
		if ($progressContainer.length === 0) {
			var progressHTML = `
				<div class="metasync-sa-connect-progress">
					<div class="metasync-sa-connect-progress-header">
						<strong>🔐 Authentication in Progress</strong>
						<span class="metasync-sa-connect-progress-time">Connecting...</span>
					</div>
					<div class="metasync-sa-connect-progress-bar">
						<div class="metasync-sa-connect-progress-fill"></div>
					</div>
					<div class="metasync-sa-connect-progress-text">
						Establishing secure connection to ' + getPluginName() + '...
					</div>
				</div>
			`;
			$button.closest('.metasync-sa-connect-container').append(progressHTML);
			$progressContainer = $('.metasync-sa-connect-progress');
		}
		
		$progressContainer.show().find('.metasync-sa-connect-progress-fill').css('width', '0%');
	}

	function updateProgress(percentage, currentPoll, maxPolls) {
		var $progressFill = $('.metasync-sa-connect-progress-fill');
		var $progressTime = $('.metasync-sa-connect-progress-time');
		var $progressText = $('.metasync-sa-connect-progress-text');
		
		// Update progress bar
		$progressFill.css('width', percentage + '%');
		
		// Update time display (now in seconds)
		var timeElapsed = currentPoll * 5;
		var timeRemaining = (maxPolls - currentPoll) * 5;
		$progressTime.text(timeElapsed + 's elapsed, ' + timeRemaining + 's remaining');
		
		// Update progress text based on time elapsed (optimized for 60-second timeout)
		var progressMessages = [
			'Establishing connection and opening authentication window...',
			'Please complete authentication in the popup window...',
			'Almost done! Finalizing your authentication...'
		];
		
		var messageIndex = Math.floor((currentPoll / maxPolls) * progressMessages.length);
		messageIndex = Math.min(messageIndex, progressMessages.length - 1);
		$progressText.text(progressMessages[messageIndex]);
	}

	// Update the old function name for compatibility
	function startConnectPolling(nonceToken) {
		return startSearchAtlasPolling(nonceToken);
	}

	function stopSearchAtlasPolling() {
		if (saConnectPollingInterval) {
			clearInterval(saConnectPollingInterval);
			saConnectPollingInterval = null;
		}
	}

	// Legacy function for backward compatibility
	function stopConnectPolling() {
		return stopSearchAtlasPolling();
	}

	function showConnectSuccess(title, message, actions) {
		showConnectStatus('success', title, message, actions);
	}

	function showConnectError(title, message, actions) {
		showConnectStatus('error', title, message, actions);
	}

	function showConnectInfo(title, message, actions) {
		showConnectStatus('info', title, message, actions);
	}

	function showConnectWarning(title, message, actions) {
		showConnectStatus('warning', title, message, actions);
	}

	function showConnectStatus(type, title, message, actions) {
		var $statusContainer = $('#sa-connect-status-message');
		var $button = $('#connect-searchatlas-btn');
		
		// Create enhanced status container if it doesn't exist
		if ($statusContainer.length === 0 || !$statusContainer.hasClass('metasync-sa-connect-status')) {
			// Create new enhanced status container
			var statusHTML = '<div id="sa-connect-status-message" class="metasync-sa-connect-status"></div>';
			$button.closest('.metasync-sa-connect-container').length === 0 ? 
				$button.parent().append(statusHTML) :
				$button.closest('.metasync-sa-connect-container').append(statusHTML);
			$statusContainer = $('#sa-connect-status-message');
		}
		
		// Build status content
		var html = '<div class="metasync-sa-connect-status-content">';
		html += '<div class="metasync-sa-connect-status-title">' + title + '</div>';
		if (message) {
			html += '<div class="metasync-sa-connect-status-message">' + message + '</div>';
		}
		html += '</div>';
		
		// Add action buttons if provided
		if (actions && actions.length > 0) {
			html += '<div class="metasync-sa-connect-actions">';
			actions.forEach(function (action, index) {
				var buttonClass = action.primary ? 'primary' : 'secondary';
				html += '<button type="button" class="metasync-sa-connect-btn ' + buttonClass + '" data-action="' + index + '">';
				html += action.text;
				html += '</button>';
			});
			html += '</div>';
		}
		
		// Update status container with animation
		$statusContainer
			.removeClass('success error info warning')
			.addClass(type)
			.html(html)
			.hide()
			.slideDown(300);
		
		// Bind action handlers
		if (actions && actions.length > 0) {
			$statusContainer.find('.metasync-sa-connect-btn').off('click').on('click', function () {
				var $actionBtn = $(this);
				var actionIndex = parseInt($actionBtn.data('action'));
				if (actions[actionIndex] && typeof actions[actionIndex].action === 'function') {
					var originalText = $actionBtn.text();
					$actionBtn.prop('disabled', true)
							 .addClass('no-loading') // Prevent dashboard.js conflicts
							 .removeClass('dashboard-loading')
							 .html('<span class="metasync-sa-connect-loading"></span> ' + originalText);
					setTimeout(function () {
						actions[actionIndex].action();
					}, 100);
				}
			});
		}
		
		// Auto-scroll to status message for better visibility
		if (type === 'error' || type === 'warning' || type === 'success') {
			setTimeout(function () {
				$('html, body').animate({
					scrollTop: $statusContainer.offset().top - 100
				}, 300);
			}, 100);
		}
	}

	function showConnectNotRegistered(dashboardDomain) {
		// Use dashboard domain if provided, otherwise fallback to effective domain (includes whitelabel)
		var domain = dashboardDomain || metaSync.dashboard_domain;
		var registerUrl = domain + '/seo-automation-v3/create-project';
		
		showConnectWarning(
			'⚠️ Website Not Registered',
			'Your website hasn\'t been registered with ' + getPluginName() + ' yet. Registration is required to enable 1-click connect to retrieve your Search Atlas API key and Otto UUID.',
			[{
				text: '🌐 Register Website',
				action: function () { 
					window.open(registerUrl, '_blank');
				},
				primary: true
			}, {
				text: '📚 Learn More About Registration',
				action: function () { 
					var docDomain = metaSync.documentation_domain || 'https://searchatlas.com';
					window.open(docDomain, '_blank');
				}
			}, {
				text: '🔄 Try Authentication Again',
				action: function () { 
					setTimeout(function () {
						handleSearchAtlasConnect(); 
					}, 500);
				}
			}]
		);
	}

	function hideConnectStatus() {
		$('#sa-connect-status-message').slideUp(300);
		$('.metasync-sa-connect-progress').slideUp(300);
	}

	function showPopupHelp() {
		var helpContent = `
			<div style="max-width: 500px;">
				<h3>🔧 How to Enable Popups</h3>
				<p><strong>Chrome/Edge:</strong></p>
				<ol>
					<li>Click the popup blocked icon in the address bar</li>
					<li>Select "Always allow popups from this site"</li>
					<li>Reload the page and try again</li>
				</ol>
				<p><strong>Firefox:</strong></p>
				<ol>
					<li>Click the shield icon in the address bar</li>
					<li>Turn off "Block popup windows"</li>
					<li>Refresh and try again</li>
				</ol>
				<p><strong>Safari:</strong></p>
				<ol>
					<li>Go to Safari → Preferences → Websites</li>
					<li>Select "Pop-up Windows" on the left</li>
					<li>Set this website to "Allow"</li>
				</ol>
			</div>
		`;
		
		showConnectInfo('📝 Popup Help', helpContent, [{
			text: '✅ Got it, Try Again',
			action: function () {
				handleSearchAtlasConnect(); 
			},
			primary: true
		}]);
	}

	function enhancedErrorRecovery(error, context) {
		console.group('🔍 Connect Error Diagnostics');
		console.log('Error Context:', context);
		console.log('Error Details:', error);
		console.log('Browser Info:', {
			userAgent: navigator.userAgent,
			cookieEnabled: navigator.cookieEnabled,
			language: navigator.language,
			platform: navigator.platform
		});
		console.log('Current Time:', new Date().toISOString());
		console.groupEnd();
		
		// Provide contextual recovery suggestions
		var recoverySuggestions = [];
		
		if (context === 'network') {
			recoverySuggestions = [
				'Check your internet connection',
				'Disable VPN or proxy if enabled',
				'Try refreshing the page',
				'Clear browser cache and cookies'
			];
		} else if (context === 'popup') {
			recoverySuggestions = [
				'Allow popups for this website',
				'Disable ad blockers temporarily',
				'Try using a different browser',
				'Check if firewall is blocking the request'
			];
		} else if (context === 'timeout') {
			recoverySuggestions = [
				'Complete authentication within 60 seconds',
				'Check if the popup window needs attention',
				'Ensure you have your ' + getPluginName() + ' login ready',
				'Try the authentication process again',
				'Contact support if timeouts persist'
			];
		}
		
		return recoverySuggestions;
	}

	// Add enhanced page visibility handling
	function handlePageVisibilityChange() {
		if (document.hidden && saConnectWindow && !saConnectWindow.closed) {
			// Page became hidden while SSO is in progress
			showConnectInfo('👁️ Page Hidden', 
				'This page is now in the background. The authentication will continue, but you may want to return to this tab to see the results.');
		}
	}

	// Initialize enhanced features when document is ready
	$(document).ready(function () {
		
		// Hide Jetpack identity crisis container on plugin pages
		if ($('.metasync-dashboard-wrap').length > 0) {
			$('#jp-identity-crisis-container, .jp-identity-crisis-container').hide();
		}
		
		// Check for URL parameters and show success/error messages
		const urlParams = new URLSearchParams(window.location.search);
		
		// Show success message for cleared logs
		if (urlParams.get('log_cleared') === '1') {
			showSyncSuccess('🧹 Error Logs Cleared', 'All error log entries have been successfully cleared.');
		}
		
		// Show success message for cleared error summary
		if (urlParams.get('error_summary_cleared') === '1') {
			showSyncSuccess('📊 Error Summary Cleared', 'Error summary has been cleared successfully.');
		}
		
		// Show error message for failed clear operation
		if (urlParams.get('clear_error') === '1') {
			showSyncError('❌ Clear Failed', 'Unable to clear the error logs. Please check permissions or try again.');
		}
		
		// Show error message for failed error summary clear
		if (urlParams.get('error_summary_error') === '1') {
			showSyncError('❌ Clear Failed', 'Unable to clear the error summary. Please try again.');
		}
		
		// Check global variables are available
		
		// Test AJAX connectivity using our specific endpoint
		if (typeof ajaxurl !== 'undefined' && ajaxurl) {
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'test_searchatlas_ajax_endpoint',
					nonce: metaSync.sa_connect_nonce
				},
				timeout: 10000,
				success: function (response) {
					if (!response.success) {
						console.warn('🐛 DEBUG: AJAX endpoint reached but returned success=false:', response.data);
					}
				},
				error: function (xhr, status, error) {
					console.error('🐛 DEBUG: AJAX test failed:', {
						xhr: xhr,
						status: status,
						error: error,
						responseText: xhr.responseText,
						ajaxurl: ajaxurl
					});
					
					// Try alternative AJAX test
					$.post(ajaxurl, {
						action: 'wp_ajax_nopriv_heartbeat'
					}).done(function (response2) {
					}).fail(function (xhr2) {
						console.error('🐛 DEBUG: Alternative AJAX also failed:', xhr2);
					});
				}
			});
		}
		
		// Check if SSO button exists and is functional
		var $connectButton = $('#connect-searchatlas-btn');
		
		// Test direct click event binding and fix dashboard interference
		if ($connectButton.length > 0) {
		// Aggressively prevent dashboard loading interference
			preventDashboardInterference($connectButton);
			
			$connectButton.off('click').on('click', function (e) {
				
				// Prevent dashboard.js from interfering
				preventDashboardInterference($(this));
				
				// Call handleSearchAtlasConnect if not already disabled by another process
				if (!$(this).hasClass('connecting') && !$(this).hasClass('authenticating')) {
					handleSearchAtlasConnect();
				} else {
				}
			});
		}
		
		// Add page visibility change handler
		if (typeof document.hidden !== 'undefined') {
			document.addEventListener('visibilitychange', handlePageVisibilityChange);
		}
		
		// Add keyboard shortcuts for better accessibility
		$(document).on('keydown', function (e) {
			// Escape key to cancel ongoing SSO process
			if (e.key === 'Escape' && saConnectPollingInterval) {
				if (confirm('Cancel the ongoing authentication process?')) {
					stopSearchAtlasPolling();
					if (saConnectWindow) {
						saConnectWindow.close();
					}
					showConnectInfo('⏸️ Authentication Cancelled', 'You cancelled the authentication process.');
					resetConnectButton();
				}
			}
		});
		
		// Add connection status indicator
		function updateConnectionStatus() {
			var $button = $('#connect-searchatlas-btn');
			var $apiKeyField = $('#searchatlas-api-key');
			
			// Only update status if we're on a page with the API key field (General Settings)
			// On other pages, preserve the PHP-determined status in the header
			if ($apiKeyField.length === 0) {
				return; // Don't update status on pages without the API key field
			}
			
			var hasApiKey = $apiKeyField.val() && $apiKeyField.val().trim() !== '';
			var hasOttoUuid = metaSync.otto_pixel_uuid && metaSync.otto_pixel_uuid.trim() !== '';
			var isFullyConnected = hasApiKey && hasOttoUuid;
			
			// Update button text based on connection state
			if (!$button.prop('disabled')) {
				if (isFullyConnected) {
					$button.html('🔄 Re-authenticate with ' + getPluginName());
				} else if (hasApiKey && !hasOttoUuid) {
					$button.html('🔧 Complete Authentication Setup');
				} else {
					$button.html('🔗 Connect to ' + getPluginName());
				}
			}
			
			// Update header status indicator (only on General Settings page)
			if (isFullyConnected) {
				updateHeaderStatus(true, 'Synced', getPluginName() + ' API key and ' + getOttoName() + ' UUID are configured');
			} else {
				updateHeaderStatus(false, 'Not Synced', 'Missing ' + getPluginName() + ' API key or ' + getOttoName() + ' UUID');
			}
		}
		
		// Monitor API key field changes
		$('#searchatlas-api-key').on('input', updateConnectionStatus);
		
		// Initial status update
		updateConnectionStatus();
		
		// Initialize dashboard iframe functionality
		initializeDashboardIframe();
		
		// Settings dropdown now handled by inline script in HTML
		
		// Add debug function for connection status (accessible in console)
		window.debugConnectionStatus = function () {
			var apiKey = $('#searchatlas-api-key').val();
			var hasApiKey = apiKey.trim() !== '';
			
			console.log('🔍 Connection Status Debug:', {
				searchatlas_api_key: hasApiKey ? (apiKey.substring(0, 8) + '...') : 'EMPTY',
				otto_pixel_uuid: metaSync.otto_pixel_uuid || 'NOT SET',
				connection_state: hasApiKey && metaSync.otto_pixel_uuid ? 'CONNECTED' : 
								 hasApiKey ? 'PARTIAL (Missing ' + getOttoName() + ' UUID)' : 'NOT CONNECTED',
				dashboard_tab_visible: hasApiKey && metaSync.otto_pixel_uuid ? 'YES' : 'NO',
				status_indicator_should_show: hasApiKey && metaSync.otto_pixel_uuid ? 'Synced' : 'Not Synced'
			});
		};
		
	
	});

	// Settings dropdown is now handled by inline script in HTML for better reliability

	/**
	 * Initialize Dashboard Iframe functionality
	 * Adds loading states and error handling for the embedded dashboard
	 */
	function initializeDashboardIframe() {
		var $iframe = $('#metasync-dashboard-iframe');
		
		if ($iframe.length === 0) {
			return; // No iframe on this page
		}
		
		// Add loading indicator
		var $wrapper = $('.metasync-dashboard-iframe-wrapper');
		var loadingHTML = '<div class="metasync-dashboard-iframe-loading"><div class="spinner"></div><p>Loading dashboard...</p></div>';
		$wrapper.append(loadingHTML);
		
		// Handle iframe load events
		$iframe.on('load', function () {
			$('.metasync-dashboard-iframe-loading').fadeOut(300);
			
			// Log successful load
			console.log('Dashboard iframe loaded successfully');
		});
		
		// Handle iframe error events  
		$iframe.on('error', function () {
			$('.metasync-dashboard-iframe-loading').html(
				'<div style="text-align: center; color: #dc3232;">' +
				'<h3>❌ Dashboard Loading Error</h3>' +
				'<p>Unable to load the dashboard. Please check your connection.</p>' +
				'<button type="button" class="button button-primary" onclick="location.reload();">🔄 Reload Page</button>' +
				'</div>'
			);
			
			console.error('Dashboard iframe failed to load');
		});
		
		// Add keyboard shortcut for refreshing iframe
		$(document).on('keydown', function (e) {
			// Ctrl/Cmd + R on dashboard page refreshes iframe
			if ((e.ctrlKey || e.metaKey) && e.key === 'r' && $iframe.length > 0) {
				e.preventDefault();
				refreshDashboardIframe();
			}
		});
		
		// Handle iframe resize for better mobile experience
		function adjustIframeHeight() {
			if (window.innerWidth <= 768) {
				$iframe.height(600);
			} else {
				$iframe.height(800);
			}
		}
		
		// Adjust on window resize
		$(window).on('resize', adjustIframeHeight);
		adjustIframeHeight(); // Initial adjustment
	}
	
	/**
	 * Refresh Dashboard Iframe
	 * Reloads the iframe content with loading indicator
	 */
	function refreshDashboardIframe() {
		var $iframe = $('#metasync-dashboard-iframe');
		var $wrapper = $('.metasync-dashboard-iframe-wrapper');
		
		if ($iframe.length === 0) {
			return;
		}
		
		// Show loading indicator
		$('.metasync-dashboard-iframe-loading').remove();
		var loadingHTML = '<div class="metasync-dashboard-iframe-loading"><div class="spinner"></div><p>Refreshing dashboard...</p></div>';
		$wrapper.append(loadingHTML);
		
		// Refresh iframe
		var currentSrc = $iframe.attr('src');
		$iframe.attr('src', '');
		setTimeout(function () {
			$iframe.attr('src', currentSrc);
		}, 100);
		
		console.log('Dashboard iframe refresh initiated');
	}

	/**
	 * Handle Search Atlas Authentication Reset.
	 * Shows confirmation dialog and clears the Search Atlas API key and Otto UUID.
	 */
	function handleSearchAtlasResetAuth() {
		// Show confirmation dialog
		var confirmed = confirm(
			'⚠️ Disconnect ' + getPluginName() + ' Account\n\n' +
			'This will:\n' +
			'• Remove your ' + getPluginName() + ' API key\n' +
			'• Clear all authentication tokens\n' +
			'• Reset connection timestamps\n' +
			'• Clear cached authentication data\n\n' +
			'You will need to re-authenticate to use ' + getPluginName() + ' features.\n\n' +
			'Are you sure you want to continue?'
		);

		if (!confirmed) {
			return;
		}

		var $resetButton = $('#reset-searchatlas-auth');
		var $connectButton = $('#connect-searchatlas-btn');
		var $apiKeyField = $('#searchatlas-api-key');

		// Show loading state (prevent dashboard.js conflicts)
		$resetButton.prop('disabled', true)
			.addClass('no-loading') // Prevent dashboard.js interference
			.removeClass('dashboard-loading')
			.html('<span class="metasync-sa-connect-loading"></span> Disconnecting...');

		// Show status message
		showConnectInfo('🔄 Disconnecting', 'Clearing your ' + getPluginName() + ' authentication data...');

		// Make AJAX call to reset authentication
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'reset_searchatlas_authentication',
				nonce: metaSync.reset_auth_nonce
			},
			success: function (response) {
				if (response.success) {
					// Clear the API key field
					$apiKeyField.val('');
					
					// Update button states
					$connectButton.html('🔗 Connect to ' + getPluginName());
					$resetButton.remove(); // Remove reset button since no longer connected
					
					// Show clean success message without duplicate connect functionality
					showConnectSuccess('✅ Account Disconnected', 
						'Your ' + getPluginName() + ' authentication has been completely reset. All authentication data has been cleared.',
						[{
							text: '📄 View What Was Cleared',
							action: function () {
								showClearedDataDetails(response.data.cleared_data);
							},
							primary: true
						}, {
							text: '✅ Got it',
							action: function () {
								hideConnectStatus();
							}
						}]
					);

					// Update page elements to reflect disconnected state
					updateUIForDisconnectedState();

				} else {
					showConnectError('❌ Reset Failed', 
						response.data.message || 'Failed to reset authentication',
						[{
							text: '🔄 Try Again',
							action: function () {
								handleSearchAtlasResetAuth(); 
							}
						}, {
							text: '💬 Contact Support',
							action: function () { 
								var supportEmail = metaSync.support_email || 'support@searchatlas.com';
								window.open('mailto:' + supportEmail + '?subject=Authentication Reset Failed', '_blank');
							}
						}]
					);
				}
			},
			error: function (xhr, status, error) {
				showConnectError('🌐 Network Error', 
					'A network error occurred while trying to reset authentication.',
					[{
						text: '🔄 Try Again',
						action: function () {
							handleSearchAtlasResetAuth(); 
						}
					}]
				);
			},
			complete: function () {
				// Reset button state
				$resetButton.prop('disabled', false)
						   .removeClass('dashboard-loading no-loading')
						   .html('🔓 Disconnect Account');
			}
		});
	}

	/**
	 * Show details of what data was cleared during reset
	 */
	function showClearedDataDetails(clearedData) {
		var details = '<div style="max-width: 500px;"><h3>🗑️ Data Cleared</h3><ul style="text-align: left; margin: 15px 0;">';
		
		for (var key in clearedData) {
			var displayName = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
			details += '<li><strong>' + displayName + ':</strong> ' + clearedData[key] + '</li>';
		}
		
		details += '</ul><p style="color: #666; font-size: 13px;">This data has been permanently removed. You can safely reconnect with a new or existing ' + getPluginName() + ' account.</p></div>';
		
		showConnectInfo('📋 Reset Details', details, [{
			text: '✅ Got it',
			action: function () { 
				hideConnectStatus();
				// Highlight the main connect button briefly to guide user attention
				var $mainButton = $('#connect-searchatlas-btn');
				if ($mainButton.length > 0) {
					$mainButton.addClass('metasync-pulse');
					setTimeout(function () {
						$mainButton.removeClass('metasync-pulse');
					}, 2000);
				}
			},
			primary: true
		}]);
	}

	/**
	 * Update UI elements when account is disconnected
	 */
	function updateUIForDisconnectedState() {
		// Update API key field placeholder
		$('#searchatlas-api-key').attr('placeholder', 'Your API key will appear here after authentication');
		
		// ✅ Clear OTTO Pixel UUID field
		$('input[name="metasync_options[general][otto_pixel_uuid]"]').val('');
		
		// Note: OTTO SSR is always enabled by default, no checkbox to uncheck
		
		// Remove synced indicator from API key field
		$('.metasync-sa-connect-container').find('span:contains("✓ Synced")').remove();
		$('label[for="searchatlas-api-key"]').find('span').remove(); // Remove any status spans
		
		// Update header status indicator to "Not Synced"
		updateHeaderStatus(false, 'Not Synced', 'Missing ' + getPluginName() + ' API key or ' + getOttoName() + ' UUID');
		
		// Update metaSync object for JavaScript state tracking
		if (typeof metaSync !== 'undefined') {
			metaSync.searchatlas_api_key = false;
			metaSync.otto_pixel_uuid = '';
			metaSync.is_connected = false;
		}
		
		// Update descriptions to reflect disconnected state
		$('.metasync-sa-connect-description').html(
			'Connect your ' + getPluginName() + ' account with one click. This will automatically configure your API key below and enable all plugin features.'
		);
		
		// Clear timestamp display if it exists
		$('#sendAuthTokenTimestamp').fadeOut(300);
		
		// Header status already updated above - no need for additional connection status call
		
		console.log('🔄 UI updated to reflect disconnected state - cleared API key, OTTO UUID, and OTTO enable checkbox');
		
		// Show clean success message without duplicate connect button
		setTimeout(function () {
			showConnectSuccess('✅ Account Disconnected', 
				'Your ' + getPluginName() + ' authentication has been completely reset. Use the "Connect to ' + getPluginName() + '" button above to reconnect.',
				[{
					text: '✅ Got it',
					action: function () { 
						hideConnectStatus();
					},
					primary: true
				}]
			);
		}, 500); // Shorter delay since no action is needed
	}

	/**
	 * Update UI elements when account is connected/authenticated
	 * Complementary function to updateUIForDisconnectedState()
	 */
	function updateUIForConnectedState(apiKey, ottoPixelUuid) {
		// Update API key field
		if (apiKey) {
			$('#searchatlas-api-key').val(apiKey);
		}
		
		// ✅ Update OTTO Pixel UUID field
		if (ottoPixelUuid) {
			$('input[name="metasync_options[general][otto_pixel_uuid]"]').val(ottoPixelUuid);
		}
		
		// Note: OTTO SSR is always enabled by default, no checkbox needed
		
		// Update header status indicator to "Synced"
		updateHeaderStatus(true, 'Synced', 'Authentication completed - heartbeat sync will be validated on next page load');
		
		// Update metaSync object for JavaScript state tracking
		if (typeof metaSync !== 'undefined') {
			metaSync.searchatlas_api_key = true;
			metaSync.is_connected = true;
			if (ottoPixelUuid) {
				metaSync.otto_pixel_uuid = ottoPixelUuid;
			}
		}
		
		// Update descriptions to reflect connected state
		$('.metasync-sa-connect-description').html(
			'Your ' + getPluginName() + ' account is connected and synced successfully. All plugin features are now enabled.'
		);
		
		console.log('✅ UI updated to reflect connected state - API key set, OTTO UUID set (SSR always enabled)');
	}

	function addClassTableRowLocalSEO() {
		if (document.getElementsByClassName('form-table') && document.getElementById('local_seo_person_organization')) {
			const myElement = document.getElementsByTagName('tr');

			for (let i = 0; i < myElement.length; i++) {
				myElement[i].classList.add('metasync-seo-' + (i + 10));
			}
		}
	}

	function addClassTableRowSiteInfo() {
		if (document.getElementsByClassName('form-table') && document.getElementById('site_info_type')) {
			const myElement = document.getElementsByTagName('tr');

			for (let i = 0; i < myElement.length; i++) {
				myElement[i].classList.add('metasync-site-info-' + (i + 10));
			}
		}
	}

	function uploadMedia(title, text, input, src, closeBtn) {

		var mediaUploader;

		// If the uploader object has already been created, reopen the dialog
		if (mediaUploader) {
			mediaUploader.open();
			return;
		}
		// Extend the wp.media object
		mediaUploader = wp.media.frames.file_frame = wp.media({
			title: title,
			button: {
				text: text
			}, multiple: false
		});

		// When a file is selected, grab the URL and set it as the text field's value
		mediaUploader.on('select', function () {
			var attachment = mediaUploader.state().get('selection').first().toJSON();
			jQuery('#' + input).val(attachment.id);
			jQuery('#' + src).attr('src', attachment.url);
			jQuery('#' + src).attr('width', 300);
			jQuery('#' + closeBtn).attr('type', 'button');
			jQuery('#' + src).show();
			jQuery('#' + closeBtn).show();
		});
		// Open the uploader dialog
		mediaUploader.open();
	}

	function getLocalSeoOnLoadPage() {
		if (document.getElementsByClassName('form-table') && document.getElementById('local_seo_person_organization')) {
			var $type = $('#local_seo_person_organization').val();
			const classes = ['17', '18', '19', '20', '21', '24', '25'];
			if ($type === 'Person') {
				for (let i = 0; i < classes.length; i++) {
					$('.metasync-seo-' + classes[i]).hide();
				}
				$('.metasync-seo-15').show();
			} else {
				for (let i = 0; i < classes.length; i++) {
					$('.metasync-seo-' + classes[i]).show();
				}
				$('.metasync-seo-15').hide();
			}
		}
	}

	function siteInfoOnLoadPage() {
		if (document.getElementsByClassName('form-table') && document.getElementById('site_info_type')) {
			var $type = $('#site_info_type').val();
			const classes = ['18', '19'];
			if ($type === 'blog' || $type === 'portfolio' || $type === 'otherpersonal') {
				for (let i = 0; i < classes.length; i++) {
					$('.metasync-site-info-' + classes[i]).hide();
				}
			} else {
				for (let i = 0; i < classes.length; i++) {
					$('.metasync-site-info-' + classes[i]).show();
				}
			}
		}
	}

	function deleteTime() {
		$(this).parent().remove();
	}

	function hideElementById(id) {
		if ($('#' + id)) {
			$('#' + id).hide();
		}
	}

	function removeValueById(id) {
		if ($('#' + id)) {
			$('#' + id).val('');
		}
	}

	$(function () {
		$('#addNewTime').on('click', function () {
			$('#daysTime').append(
				'<li>' +
				'<select name="metasync_options[localseo][days][]">' +
				'<option value="Monday">Monday</option>' +
				'<option value="Tuseday">Tuseday</option>' +
				'<option value="Wednesday">Wednesday</option>' +
				'<option value="Thursday">Thursday</option>' +
				'<option value="Friday">Friday</option>' +
				'<option value="Saturday">Saturday</option>' +
				'<option value="Sunday">Sunday</option>' +
				'</select>' +
				'<input type="text" name="metasync_options[localseo][times][]">' +
				'<button id="timeDelete">Delete</button>' +
				'</li>');
			return;
		});
		$(document).on('click', '#timeDelete', deleteTime);
	});

	function deleteNumber() {
		$(this).parent().remove();
	}

	$(function () {
		$('#addNewNumber').on('click', function () {
			$('#phone-numbers').append(
				'<li>' +
				'<select name="metasync_options[localseo][phonetype][]">' +
				'<option value="Customer Service">Customer Service</option>' +
				'<option value="Technical Support">Technical Support</option>' +
				'<option value="Billing Support">Billing Support</option>' +
				'<option value="Bill Payment">Bill Payment</option>' +
				'<option value="Sales">Sales</option>' +
				'<option value="Reservations">Reservations</option>' +
				'<option value="Credit Card Support">Credit Card Support</option>' +
				'<option value="Emergency">Emergency</option>' +
				'<option value="Baggage Tracking">Baggage Tracking</option>' +
				'<option value="Roadside Assistance">Roadside Assistance</option>' +
				'<option value="Package Tracking">Package Tracking</option>' +
				'</select>' +
				'<input type="text" name="metasync_options[localseo][phonenumber][]">' +
				'<button id="number-delete">Delete</button>' +
				'</li>');
			return;
		});
		$(document).on('click', '#number-delete', deleteNumber);
	});

	function deleteSourceUrl() {
		$(this).parent().remove();
	}
	$(function () {
		$('#addNewSourceUrl').on('click', function () {
			$('#source_urls').append(
				'<li>' +
				'<input type="text" class="regular-text" name="source_url[]">' +
				'<select name="search_type[]">' +
				'<option value="exact">Exact</option>' +
				'<option value="contain">Contain</option>' +
				'<option value="start">Start With</option>' +
				'<option value="end">End With</option>' +
				'</select>' +
				'<button id="source_url_delete">Remove</button>' +
				'</li>');
			return;
		});
		$(document).on('click', '#source_url_delete', deleteSourceUrl);
	});

	$(function () {

		setToken();

		$('body').on('click', '#wp_metasync_sync', function (e) {
			e.preventDefault();
			metasync_syncPostsAndPages();
		});
		$('body').on('click', '#metasync_settings_genkey_btn', function () {
			$('#apikey').val(metasyncGenerateAPIKey());
		});
		$('body').on('click', '#lgloginbtn', function () {
			// Hide any existing error messages first
			$('#lgerror').addClass('hidden').hide();
		
			if ($('#lgusername').val() === '' || $('#lgpassword').val() === '') {
				$('.input.lguser').toggleClass('hidden');
			} else {
				metasyncLGLogin($('#lgusername').val(), $('#lgpassword').val());
			}
		});

		// Enhanced SSO Connect button event handler
		// Aggressive event binding that overrides dashboard.js interference
		$('body').off('click', '#connect-searchatlas-btn').on('click', '#connect-searchatlas-btn', function (e) {
			
			// Aggressively prevent dashboard interference
			preventDashboardInterference($(this));
			
			// Only proceed if button is not in SSO process
			if (!$(this).hasClass('connecting') && !$(this).hasClass('authenticating')) {
				e.preventDefault();
				e.stopPropagation();
				
	
				handleSearchAtlasConnect();
			} else {
			}
		});
		
		// Also add a direct event listener as backup
		setTimeout(function () {
			var $btn = $('#connect-searchatlas-btn');
			if ($btn.length > 0) {
				$btn[0].addEventListener('click', function (e) {
					e.preventDefault();
					e.stopPropagation();
					
					// Force enable the button and clean classes
					preventDashboardInterference($(this));
					
					if (!$(this).hasClass('connecting') && !$(this).hasClass('authenticating')) {
						handleSearchAtlasConnect();
					}
				}, true); // Use capture phase to get event before other handlers
			}
		}, 500);
		
		// Monitor button state changes and fix interference
		setTimeout(function () {
			var $btn = $('#connect-searchatlas-btn');
			if ($btn.length > 0) {
			// Store original button state for restoration
				var buttonState = {
					disabled: $btn.prop('disabled'),
					style: $btn.attr('style'),
					pointerEvents: $btn.css('pointer-events'),
					zIndex: $btn.css('z-index'),
					position: $btn.css('position'),
					classes: $btn.attr('class')
				};
				
				// Monitor for unwanted changes to the button
				var observer = new MutationObserver(function (mutations) {
					mutations.forEach(function (mutation) {
						if (mutation.type === 'attributes') {
							// Monitor for unwanted attribute changes
													
							// Fix dashboard interference automatically
							if (mutation.attributeName === 'class' && $btn.hasClass('dashboard-loading')) {
								preventDashboardInterference($btn);
							}
							
							if (mutation.attributeName === 'disabled' && $btn.prop('disabled') && !$btn.hasClass('connecting')) {
								preventDashboardInterference($btn);
							}
						}
					});
				});
				
				observer.observe($btn[0], {
					attributes: true,
					attributeOldValue: true,
					attributeFilter: ['class', 'disabled', 'style']
				});
			}
		}, 1000);

		// SSO Reset button event handler
		$('body').on('click', '#reset-searchatlas-auth', function (e) {
			e.preventDefault();
			handleSearchAtlasResetAuth();
		});

		$('body').on('click', '#local_seo_logo_close_btn', function () {
			removeValueById('local_seo_logo');
			hideElementById('local_seo_business_logo');
			hideElementById('local_seo_logo_close_btn');
		});

		$('body').on('click', '#site_google_logo_close_btn', function () {
			removeValueById('site_google_logo');
			hideElementById('site_google_logo_img');
			hideElementById('site_google_logo_close_btn');
		});

		$('body').on('click', '#site_social_image_close_btn', function () {
			removeValueById('site_social_share_image');
			hideElementById('site_social_share_img');
			hideElementById('site_social_image_close_btn');
		});

		$('body').on('click', '#logo_upload_button', function () {
			uploadMedia('Logo', 'Add', 'local_seo_logo', 'local_seo_business_logo', 'local_seo_logo_close_btn');
		});

		$('body').on('click', '#google_logo_btn', function () {
			uploadMedia('Site Google Logo', 'Add', 'site_google_logo', 'site_google_logo_img', 'site_google_logo_close_btn');
		});

		$('body').on('click', '#social_share_image_btn', function () {
			uploadMedia('Site Social Share Image', 'Add', 'site_social_share_image', 'site_social_share_img', 'site_social_image_close_btn');
		});

		$('body').on('click', '#robots_common1', function () {
			$('#robots_common1').prop('checked', true);
			$('#robots_common2').prop('checked', false);
		});

		$('body').on('click', '#robots_common2', function () {
			$('#robots_common1').prop('checked', false);
			$('#robots_common2').prop('checked', true);
		});

		addClassTableRowLocalSEO();

		addClassTableRowSiteInfo();

		getLocalSeoOnLoadPage();

		siteInfoOnLoadPage();

		$('#local_seo_person_organization').change(function () {
			const classes = ['17', '18', '19', '20', '21', '24', '25'];
			if (this.value === 'Person') {
				for (let i = 0; i < classes.length; i++) {
					$('.metasync-seo-' + classes[i]).hide();
				}
				$('.metasync-seo-15').show();
			} else {
				for (let i = 0; i < classes.length; i++) {
					$('.metasync-seo-' + classes[i]).show();
				}
				$('.metasync-seo-15').hide();
			}
		});

		$('#site_info_type').change(function () {
			const classes = ['18', '19'];
			if (this.value === 'blog' || this.value === 'portfolio' || this.value === 'otherpersonal') {
				for (let i = 0; i < classes.length; i++) {
					$('.metasync-site-info-' + classes[i]).hide();
				}
			} else {
				for (let i = 0; i < classes.length; i++) {
					$('.metasync-site-info-' + classes[i]).show();
				}
			}
		});

		$('#metasync-giapi-response').hide();

		$('body').on('click', '#metasync-btn-send', function () {

			var url = $('#metasync-giapi-url');
			var action = $('input[type="radio"]:checked');
			var response = $('#metasync-giapi-response');

			var urls = url.val().split('\n').filter(Boolean);

			var urls_str = urls[0];
			var is_bulk = false;
			if (urls.length > 1) {
				urls_str = urls;
				is_bulk = true;
			}

			jQuery.ajax({
				method: 'POST',
				url: 'admin-ajax.php',
				data: {
					action: 'send_giapi',
					metasync_giapi_url: url.val(),
					metasync_giapi_action: action.val()
				}
			})
				.always(function (info) {

					response.show();

					$('.result-action').html('<strong>' + action.val() + '</strong>' + ' <br> ' + urls_str);

					if (!is_bulk) {
						if (typeof info.error !== 'undefined') {
							$('.result-status-code').text(info.error.code).siblings('.result-message').text(info.error.message);
						} else {
							var d = new Date();
							$('.result-status-code').text('Success').siblings('.result-message').text(d.toString());
						}
					} else {
						$('.result-status-code').text('Success').siblings('.result-message').text('Success');
						if (typeof info.error !== 'undefined') {
							$('.result-status-code').text(info.error.code).siblings('.result-message').text(info.error.message);
						} else {
							$.each(info, function (index, val) {

								if (typeof val.error !== 'undefined') {
									var error_code = '';
									if (typeof val.error.code !== 'undefined') {
										error_code = val.error.code;
									}
									var error_message = '';
									if (typeof val.error.message !== 'undefined') {
										error_message = val.error.message;
									}
									$('.result-status-code').text(error_code).siblings('.result-message').text(val.error.message);
								}
							});
						}
					}
				});
		});

		$('body').on('click', '#cancel-redirection', function () {
			$('#add-redirection-form').hide();
			$('#add-redirection').focus();
		});

		$('body').on('click', '.redirect_type', function () {
			if ($(this).val() === '410' || $(this).val() === '451') {
				$('#destination_url').val('');
				$('#destination').hide();
			} else {
				$('#destination').show();
			}
		});

		if ($('#post_redirection').is(':checked')) {
			$('.hide').fadeIn('slow');
		}
		$('body').on('change', '#post_redirection', function () {
			if (this.checked) {
				$('.hide').fadeIn('slow');
			} else {
				$('.hide').fadeOut('slow');
			}
		});

		$(document).ready(function () {
			if ($('#post_redirection').is(':checked')
				&& ($('#post_redirection_type').val() === '410'
					|| $('#post_redirection_type').val() === '451')) {
				$('#post_redirect_url').hide();
			}
		});

		$('#post_redirection_type').change(function () {
			if ($('#post_redirection').is(':checked')
				&& ($(this).val() === '410'
					|| $(this).val() === '451')) {
				$('#post_redirect_url').hide();
			} else {
				$('#post_redirect_url').show();
			}
		});

	});

	$(function () {
		var psconsole = $('#error-code-box');
		if (psconsole.length) {
			psconsole.scrollTop(psconsole[0].scrollHeight - psconsole.height());
		}
	});

	$(function () {
		$('#copy-clipboard-btn').on('click', function () {
			var hiddenInput = document.createElement('input');
			hiddenInput.setAttribute('value', document.getElementById('error-code-box').value);
			document.body.appendChild(hiddenInput);
			hiddenInput.select();
			document.execCommand('copy');
			document.body.removeChild(hiddenInput);
		});
	});

	function dateFormat() {
		var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
		var m = new Date();
		return months[m.getMonth()] + ' ' + ('0' + m.getDate()).slice(-2) + ', ' + m.getFullYear() + '  ' + (m.getHours() > 12 ? '0' + m.getHours() % 12 : '0' + m.getHours()).slice(-2) + ':' + ('0' + m.getMinutes()).slice(-2) + ':' + ('0' + m.getSeconds()).slice(-2) + ' ' + (m.getHours() > 12 ? 'PM' : 'AM');
	}

	function sendCustomerParams(is_hb = false) {
		// Only show alerts for manual sync (not heartbeat)
		const showAlerts = !is_hb;
		
		// Remove any existing sync-related notices
		if (showAlerts) {
			$('.metasync-sync-notice, .metasync-sync-error').remove();
		}

		// DEBUG: Log request parameters
		const requestData = {
			action: 'lgSendCustomerParams',
			is_heart_beat : is_hb
		};

		jQuery.ajax({
			type: 'post',
			url: 'admin-ajax.php',
			data: requestData,
			beforeSend: function () {
				if (showAlerts) {
					// Show loading state on button
					$('#sendAuthToken').prop('disabled', true).html('🔄 Syncing...');
				}
			},
			success: function (response) {
				// Reset button state
				if (showAlerts) {
					$('#sendAuthToken').prop('disabled', false).html('🔄 Sync Now');
				}

				if ($('#searchatlas-api-key') && $('#searchatlas-api-key').val() === '') {
					$('#sendAuthTokenTimestamp').html('Please save your ' + getPluginName() + ' API key');
					$('#sendAuthTokenTimestamp').css({ color: 'red' });

					// Update header status to "Not Synced" for missing API key
					updateHeaderStatus(false, 'Not Synced', 'Not Synced - API key required');

					if (showAlerts) {
						showSyncError('⚠️ API Key Required', 'Please save your ' + getPluginName() + ' API key in the settings above before syncing.');
					}

				} else if (response && response.throttled) {
					// Handle throttling response
					var remainingMinutes = response.remaining_minutes || 5;
					//$('#sendAuthTokenTimestamp').html('Please wait ' + remainingMinutes + ' minutes before syncing again');
					//$('#sendAuthTokenTimestamp').css({ color: 'orange' });
					
					// Disable sync button and show countdown
					if (showAlerts) {
						$('#sendAuthToken').prop('disabled', true).html('⏰ Throttled (' + remainingMinutes + 'm)');
						
						// Start countdown timer
						var countdownInterval = setInterval(function () {
							remainingMinutes--;
							if (remainingMinutes <= 0) {
								clearInterval(countdownInterval);
								$('#sendAuthToken').prop('disabled', false).html('🔄 Sync Now');
								$('#sendAuthTokenTimestamp').html('Ready to sync');
								$('#sendAuthTokenTimestamp').css({ color: 'green' });
							} else {
								$('#sendAuthToken').html('⏰ Throttled (' + remainingMinutes + 'm)');
								//$('#sendAuthTokenTimestamp').html('Please wait ' + remainingMinutes + ' minutes before syncing again');
							}
						}, 60000); // Update every minute
					}
					
					// Update header status to show throttling
					updateHeaderStatus(false, 'Throttled', 'Throttled - Please wait ' + remainingMinutes + ' minutes');
					
					if (showAlerts) {
						showSyncError('⏰ Request Throttled', response.message || 'Please wait ' + remainingMinutes + ' minutes before making another sync request.');
					}

				} else if (response && response.detail) {
					$('#sendAuthTokenTimestamp').html('Please provide a valid ' + getPluginName() + ' API key');
					$('#sendAuthTokenTimestamp').css({ color: 'red' });
					
					// Update header status to "Not Synced" for invalid API key
					updateHeaderStatus(false, 'Not Synced', 'Not Synced - Invalid API key');
					
					if (showAlerts) {
						showSyncError('❌ Invalid API Key', 'Please provide a valid ' + getPluginName() + ' API key.');
					}

				} else if (response === null || !response.id) {
					// Update header status to "Not Synced" after failed sync
					updateHeaderStatus(false, 'Not Synced', 'Not Synced - Data synchronization failed');
					
					if (showAlerts) {
						showSyncError('❌ Sync Failed', 'Something went wrong during synchronization. Please check your connection and try again.');
					}
					// Keep existing commented behavior for timestamp

				} else {
					var dateString = dateFormat();
					 $('#sendAuthTokenTimestamp').html(dateString);
					$('#sendAuthTokenTimestamp').css({ color: 'green' });
					
					// Update header status to "Synced" immediately after successful sync
					updateHeaderStatus(true, 'Synced', 'Synced - Data synchronization completed successfully');
					
					if (showAlerts) {
						showSyncSuccess('✅ Sync Complete', 'Your categories and user data have been successfully synchronized with ' + getPluginName() + '.');
					}
				}
			},
			error: function (xhr, status, error) {
				// Update header status to "Not Synced" for network errors
				updateHeaderStatus(false, 'Not Synced', 'Not Synced - Network error during sync');
				
				// Reset button state
				if (showAlerts) {
					$('#sendAuthToken').prop('disabled', false).html('🔄 Sync Now');
					showSyncError('❌ Network Error', 'Failed to connect to ' + getPluginName() + '. Please check your internet connection and try again.');
				}
			}
		});
	}

	/**
	 * Show sync success notification (wrapper for consolidated function)
	 */
	function showSyncSuccess(title, message) {
		showPluginNotice('success', title, message, 'metasync-sync-notice', 4000);
	}

	/**
	 * Show sync error notification (wrapper for consolidated function)
	 */
	function showSyncError(title, message) {
		showPluginNotice('error', title, message, 'metasync-sync-error', 0);
	}

	function clear_otto_caches() {
		jQuery.ajax({
			url: ajaxurl,
			type: 'GET',
			data: {
				action: 'clear_otto_cache',
				clear_otto_cache: 1
			},
			success: function (response) {
				const now = new Date();
				$('#clear_otto_caches').text('Cache Cleared ' + now.toLocaleTimeString());
				console.log('Cleared SSR Caches');
			}
		});
	}

	jQuery(document).ready(function () {

		// sendCustomerParams();
		$('#sendAuthToken').on('click', function (e) {
			e.preventDefault();
			sendCustomerParams();
			
		});

		// handle otto clear cache button
		$('#clear_otto_caches').on('click', function (e){
			e.preventDefault();
			clear_otto_caches();
		});

		// Handle General Setting Page form Submit
		$('#metaSyncGeneralSetting').on('submit', function (e) {
			// Check if this is a "Clear Error Logs" submission
			var formData = $(this).serialize();
			if (formData.indexOf('clear_log=yes') !== -1) {
				// This is a clear error logs submission - allow normal HTML form submission
				console.log('Clear Error Logs form detected - allowing HTML submission');
				return true; // Let the form submit normally
			}
			
			// Check if this is a "Plugin Access Roles" submission - allow normal HTML form submission
			if (formData.indexOf('save_plugin_access_roles=yes') !== -1) {
				console.log('Plugin Access Roles form detected - allowing HTML submission');
				return true; // Let the form submit normally
			}
			
			e.preventDefault(); // Prevent the default form submission for regular settings
			var actionField = $(this).find('input[name="action"]');
			var optionPage= $(this).find('input[name="option_page"]');
			var wpHttpReferer= $(this).find('input[name="_wp_http_referer"]');
			var wpnonce= $(this).find('input[name="_wpnonce"]');
			if(actionField.length > 0) {
				actionField.remove(); // Remove the action field if it exists
				optionPage.remove(); // Remove the action field if it exists
				wpHttpReferer.remove(); // Remove the action field if it exists
				wpnonce.remove(); // Remove the action field if it exists
			}
			// Re-serialize the form data after removing unwanted fields
			formData = $(this).serialize(); 
			
			// Check if whitelabel data is in form data, if not add it manually
			if (formData.indexOf('whitelabel') === -1) {
				// Manually collect and add ALL whitelabel fields using helper function
				var whitelabelData = collectWhitelabelFields();
				if (whitelabelData) {
					formData += '&' + whitelabelData;
				}
			}

			// Get current tab from URL
			var urlParams = new URLSearchParams(window.location.search);
			var currentTab = urlParams.get('tab') || 'general';

			$.ajax({
				url: metaSync.ajax_url, // The AJAX URL provided by WordPress
				type: 'POST',
				data: formData + '&action=meta_sync_save_settings&active_tab=' + encodeURIComponent(currentTab), // Add the action and current tab
				success: function (response) {
					// Handle success response
					if(response.success){
					// get value of input field white_label_plugin_menu_slug
						const whiteLableUrl = $('#metaSyncGeneralSetting input[name="metasync_options[general][white_label_plugin_menu_slug]"]').val();
						// check condition if it is empty or not and redirect it
					
						// add the tag query to the window location
						let tabParam = new URLSearchParams(window.location.search).get('tab');
						let tabQuery = tabParam ? '&tab=' + encodeURIComponent(tabParam) : '';
					
						// Handle undefined or empty white label URL
						const pageSlug = (whiteLableUrl && whiteLableUrl !== '') ? whiteLableUrl : 'searchatlas';
						window.location = metaSync.admin_url + '?page=' + pageSlug + tabQuery;
					}else {
						// Handle error response
						const errors = response.data?.errors || [];

						// Create a notice element to display the errors
						let html = '<div class="notice notice-error metasync-error-wrap">';
						if (Array.isArray(errors)) {
							html += '<ul>';
							errors.forEach(function (err) {
								html += '<li>' + err + '</li>';
							});
							html += '</ul>';
						}
						html += '</div>';

						// Remove previous error notices
						$('.metasync-error-wrap').remove();
							
						// Insert the error message before the form
						$('#metaSyncGeneralSetting').before(html);

						// Scroll to the top to ensure visibility
						$('html, body').animate({ scrollTop: 0 }, 'slow');
					}
					
				},
				error: function (error) {
					// Handle error response
					alert('There was an error saving the settings.');
					console.log(error);
				}
			});
		});

		//hook into heartbeat-send: client will send the message 'marco' in the 'client' var inside the data array
		jQuery(document).on('heartbeat-send', function (e, data) {
			e.preventDefault();

			// adding heart beat label
			sendCustomerParams(true);
		});

		//hook into heartbeat-tick: client looks for a 'server' var in the data array and logs it to console
		jQuery(document).on('heartbeat-tick', function (e, data) {
			// console.log('heartbeat-tick:', data);
			// if(data['server'])
			// console.log('Server: ' + data['server']);
		});

		//hook into heartbeat-error: in case of error, let's log some stuff
		jQuery(document).on('heartbeat-error', function (e, jqXHR, textStatus, error) {
			console.log('BEGIN ERROR');
			console.log(textStatus);
			console.log(error);
			console.log('END ERROR');
		});

		// Unsaved changes warning functionality
		var hasUnsavedChanges = false;
		var initialFormData = {};
		
		// Check if we're on the Advanced tab (has its own save buttons per section)
		function isAdvancedTab() {
			return window.location.href.indexOf('tab=advanced') > -1;
		}
		
		// Initialize form change detection
		function initializeUnsavedChangesDetection() {
			// Skip unsaved changes detection on Advanced tab - it has its own section-specific save buttons
			if (isAdvancedTab()) {
				return;
			}

			var $forms = $('#metaSyncGeneralSetting, #metaSyncSeoControlsForm, form[method="post"][action*="options.php"]');

			if ($forms.length === 0) {
				return; // No forms to track
			}

			// Store initial form data
			$forms.each(function () {
				var formId = $(this).attr('id') || 'form_' + Math.random().toString(36).substr(2, 9);
				initialFormData[formId] = $(this).serialize();
			});

			// Track changes on form inputs
			$forms.on('input change', 'input, select, textarea', function () {
				checkForChanges();
			});

			// Special handling for media uploads and other dynamic changes
			$forms.on('DOMSubtreeModified', function () {
				setTimeout(checkForChanges, 100); // Small delay to allow DOM changes to complete
			});
		}
		
		// Check if form data has changed
		function checkForChanges() {
			// Skip on Advanced tab
			if (isAdvancedTab()) {
				return;
			}
			
			var $forms = $('#metaSyncGeneralSetting, form[method="post"][action*="options.php"]');
			var currentHasChanges = false;
			
			$forms.each(function () {
				var formId = $(this).attr('id') || 'form_' + Math.random().toString(36).substr(2, 9);
				var currentData = $(this).serialize();
				
				if (initialFormData[formId] && currentData !== initialFormData[formId]) {
					currentHasChanges = true;
				}
			});
			
			hasUnsavedChanges = currentHasChanges;
			updateUnsavedChangesIndicator();
		}
		
		// Update visual indicator for unsaved changes
		function updateUnsavedChangesIndicator() {
			var $saveButtons = $('input[type="submit"], button[type="submit"]').filter('[name="submit"], [value*="Save"]');
			var $forms = $('#metaSyncGeneralSetting, form[method="post"][action*="options.php"]');
			
			if (hasUnsavedChanges) {
				// Add modern visual indicator to save buttons
				$saveButtons.each(function () {
					if (!$(this).find('.unsaved-indicator').length) {
						$(this).prepend('<span class="unsaved-indicator">●</span>');
					}
				});
				
				// Add visual styling to forms
				$forms.addClass('has-unsaved-changes');
				
				// Show sticky notification
				showUnsavedChangesNotification();
			} else {
				// Remove indicators
				$saveButtons.find('.unsaved-indicator').remove();
				$forms.removeClass('has-unsaved-changes');
				
				// Hide sticky notification
				hideUnsavedChangesNotification();
			}
		}
		
		// Show sticky notification for unsaved changes
		function showUnsavedChangesNotification() {
			var $notification = $('.metasync-unsaved-notification');
			
			if ($notification.length === 0) {
				// Create notification if it doesn't exist
				var notificationHTML = 
					'<div class="metasync-unsaved-notification">' +
						'<div class="notification-content">' +
							'<div class="notification-icon">●</div>' +
							'<div class="notification-message">You have unsaved changes</div>' +
						'</div>' +
						'<div class="notification-actions">' +
							'<button class="notification-button primary" onclick="saveChanges()">Save Now</button>' +
							'<button class="notification-button" onclick="discardChanges()">Discard</button>' +
							'<button class="close-notification" onclick="hideUnsavedChangesNotification()">×</button>' +
						'</div>' +
					'</div>';
				
				$('body').append(notificationHTML);
				$notification = $('.metasync-unsaved-notification');
			}
			
			// Show with animation
			setTimeout(function () {
				$notification.addClass('show');
			}, 100);
		}
		
		// Hide sticky notification
		function hideUnsavedChangesNotification() {
			var $notification = $('.metasync-unsaved-notification');
			$notification.removeClass('show');
		}
		
		// Scroll to save button functionality
		window.scrollToSaveButton = function () {
			var $saveButton = $('input[type="submit"], button[type="submit"]').filter('[name="submit"], [value*="Save"]').first();
			if ($saveButton.length) {
				// Hide notification temporarily while scrolling
				hideUnsavedChangesNotification();
				
				$('html, body').animate({
					scrollTop: $saveButton.offset().top - 100
				}, 500, function () {
					// Add highlight animation
					$saveButton.css('animation', 'save-button-highlight 1s ease-in-out');
					
					// Remove animation after it completes
					setTimeout(function () {
						$saveButton.css('animation', '');
					}, 1000);
				});
			}
		};
		
		// Discard changes functionality
		window.discardChanges = function () {
			if (confirm('Are you sure you want to discard all unsaved changes? This action cannot be undone.')) {
				// Reload the page to discard changes
				window.location.reload();
			}
		};
		

		
		// Warning for in-page navigation (tab links)
		$('.metasync-nav-tab, .nav-tab').on('click', function (e) {
			if (hasUnsavedChanges) {
				var tabName = $(this).text().trim();
				var confirmed = confirm('⚠️ Unsaved Changes Alert\n\nYou have unsaved changes that will be lost if you navigate to "' + tabName + '".\n\nWould you like to:\n• Click "Cancel" to stay and save your changes\n• Click "OK" to discard changes and continue');
				if (!confirmed) {
					e.preventDefault();
					return false;
				} else {
					// If user confirms, clear the unsaved changes state
					hasUnsavedChanges = false;
					updateUnsavedChangesIndicator();
				}
			}
		});
		
		// Clear unsaved changes flag when form is successfully submitted
		$('#metaSyncGeneralSetting').on('submit', function () {
			// Form submission handler already exists above, so we just need to listen for successful response
			var originalAjaxHandler = $(this).data('events') && $(this).data('events').submit;
		});
		
	// Listen for successful form submission to clear the unsaved changes flag
	$(document).ajaxSuccess(function (event, xhr, settings) {
		if (settings.data && typeof settings.data === 'string' && settings.data.indexOf('action=meta_sync_save_settings') > -1) {
				try {
					var response = JSON.parse(xhr.responseText);
					if (response.success) {
						hasUnsavedChanges = false;
						updateUnsavedChangesIndicator();
						// Update initial form data after successful save
						var $forms = $('#metaSyncGeneralSetting, form[method="post"][action*="options.php"]');
						$forms.each(function () {
							var formId = $(this).attr('id') || 'form_' + Math.random().toString(36).substr(2, 9);
							initialFormData[formId] = $(this).serialize();
						});
					}
				} catch (e) {
					// Response is not JSON, ignore
				}
			}
		});

		// Save Changes function - use AJAX instead of form submission
		window.saveChanges = function () {
			isSaving = true;
			
			// Completely remove the floating notification immediately when clicked
			var $notification = $('.metasync-unsaved-notification');
			if ($notification.length > 0) {
				$notification.remove(); // Completely remove from DOM, no animations
			}
			
			var $form = $('#metaSyncGeneralSetting');
			if ($form.length > 0) {
				// Get form data and submit via AJAX
				var formData = $form.serialize();
				
				// Ensure ALL whitelabel data is included using helper function
				if (formData.indexOf('whitelabel') === -1) {
					var whitelabelData = collectWhitelabelFields();
					if (whitelabelData) {
						formData += '&' + whitelabelData;
					}
				}
				
				// Get current tab from URL
				var urlParams = new URLSearchParams(window.location.search);
				var currentTab = urlParams.get('tab') || 'general';

				formData += '&action=meta_sync_save_settings&active_tab=' + encodeURIComponent(currentTab);

				$.ajax({
					url: metaSync.ajax_url,
					type: 'POST',
					data: formData,
					success: function (response) {
						if (response.success) {
							// Clear unsaved changes flag
							hasUnsavedChanges = false;
							updateUnsavedChangesIndicator();
							
							// Show temporary success indication in plugin area
							var successNotice = '<div class="notice notice-success is-dismissible metasync-save-notice" style="margin: 20px 0; padding: 12px;"><p><strong>✅ Settings saved successfully!</strong></p></div>';
							
							// Insert between navigation menu and page content
							var $navWrapper = $('.metasync-nav-wrapper');
							if ($navWrapper.length > 0) {
								// Position after navigation menu but before first dashboard card or form
								$navWrapper.after(successNotice);
							} else {
								// Fallback: insert at top of settings page
								$('.metasync-dashboard-wrap').prepend(successNotice);
							}
							
							// Scroll to the success message for better visibility
							$('html, body').animate({ scrollTop: 0 }, 'slow');
							
							setTimeout(function () {
								$('.metasync-save-notice').fadeOut(300, function () {
									$(this).remove(); 
								});
							}, 3000);
						} else {
							// Handle validation errors
							var errors = response.data && response.data.errors ? response.data.errors : [];
							var errorHtml = '<div class="notice notice-error metasync-error-wrap" style="margin: 20px; padding: 12px;">';
							if (Array.isArray(errors)) {
								errorHtml += '<ul>';
								for (var i = 0; i < errors.length; i++) {
									errorHtml += '<li>' + errors[i] + '</li>';
								}
								errorHtml += '</ul>';
							} else {
								var message = 'An error occurred while saving settings.';
								if (response.data && response.data.message) {
									message = response.data.message;
								}
								errorHtml += '<p>' + message + '</p>';
							}
							errorHtml += '</div>';
							
							// Insert error notice in plugin area
							var $navWrapper = $('.metasync-nav-wrapper');
							if ($navWrapper.length > 0) {
								$navWrapper.after(errorHtml);
							} else {
								// Fallback: insert at top of plugin content area
								$('.metasync-dashboard-wrap').prepend(errorHtml);
							}
							
							// Scroll to the error message for better visibility
							$('html, body').animate({ scrollTop: 0 }, 'slow');
							
							setTimeout(function () {
								$('.metasync-error-wrap').fadeOut(300, function () {
									$(this).remove(); 
								});
							}, 5000);
						}
						isSaving = false;
					},
					error: function (xhr, status, error) {
						// Handle AJAX error
						var errorMessage = 'There was an error saving the settings. Please try again.';
						if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
							errorMessage = xhr.responseJSON.data.message;
						}
						
						var ajaxErrorNotice = '<div class="notice notice-error is-dismissible metasync-ajax-error" style="margin: 20px 0; padding: 12px;"><p><strong>❌ Error:</strong> ' + errorMessage + '</p></div>';
						
						// Insert between navigation menu and page content
						var $navWrapper = $('.metasync-nav-wrapper');
						if ($navWrapper.length > 0) {
							// Position after navigation menu but before first dashboard card or form
							$navWrapper.after(ajaxErrorNotice);
						} else {
							// Fallback: insert at top of settings page
							$('.metasync-dashboard-wrap').prepend(ajaxErrorNotice);
						}
						
						// Scroll to the error message for better visibility
						$('html, body').animate({ scrollTop: 0 }, 'slow');
						
						setTimeout(function () {
							$('.metasync-ajax-error').fadeOut(300, function () {
								$(this).remove(); 
							});
						}, 5000);
						
						isSaving = false;
					}
				});
			}
		};
		
		// Enhanced beforeunload message (disabled when saving)
		var isSaving = false;
		$(window).on('beforeunload', function (e) {
			if (hasUnsavedChanges && !isSaving) {
				var message = '🔄 You have unsaved changes in MetaSync settings that will be lost if you leave this page.';
				e.returnValue = message; // For older browsers
				return message;
			}
		});
		
		// Initialize the detection when page loads
		setTimeout(initializeUnsavedChangesDetection, 1000); // Small delay to ensure all elements are loaded

		// Indexation Control form AJAX save functionality
		function initializeSeoControlsSaveHandler() {
			// Only initialize if we're on the Indexation Control page
			if ($('#metaSyncSeoControlsForm').length > 0) {
				// Override the saveChanges function for Indexation Control form
				window.saveChanges = function () {
					var $form = $('#metaSyncSeoControlsForm');
					if ($form.length > 0) {
						// Get form data and submit via AJAX
						var formData = $form.serialize();
						formData += '&action=meta_sync_save_seo_controls';
						
						// Remove the notification immediately
						var $notification = $('.metasync-unsaved-notification');
						if ($notification.length > 0) {
							$notification.remove();
						}
						
						// Clear previous messages
						$('#seo-controls-messages').empty();
						
						$.ajax({
							url: ajaxurl || metaSync.ajax_url,
							type: 'POST',
							data: formData,
							dataType: 'json',
							success: function (response) {
								if (response.success) {
									// Show success message above Indexation Control section
									$('#seo-controls-messages').html(
										'<div class="notice notice-success is-dismissible" style="margin-bottom: 20px;">' +
										'<p><strong>✅ Success!</strong> ' + response.data.message + '</p>' +
										'</div>'
									);

									// Clear unsaved changes state
									hasUnsavedChanges = false;
									if (typeof updateUnsavedChangesIndicator === 'function') {
										updateUnsavedChangesIndicator();
									}

									// Update initial form data to current state after successful save
									var formId = $form.attr('id') || 'metaSyncSeoControlsForm';
									initialFormData[formId] = $form.serialize();

									// Auto-hide success notice after 5 seconds
									setTimeout(function () {
										$('#seo-controls-messages .notice-success').fadeOut();
									}, 5000);
								} else {
									// Show error message above Indexation Control section
									$('#seo-controls-messages').html(
										'<div class="notice notice-error is-dismissible" style="margin-bottom: 20px;">' +
										'<p><strong>❌ Error!</strong> ' + (response.data.message || 'Failed to save settings') + '</p>' +
										'</div>'
									);
								}
								
								// Make dismiss buttons work
								$('#seo-controls-messages .notice-dismissible').each(function () {
									var $notice = $(this);
									if (!$notice.find('.notice-dismiss').length) {
										$notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
									}
									$notice.find('.notice-dismiss').on('click', function () {
										$notice.fadeOut();
									});
								});
							},
							error: function (xhr, status, error) {
								// Show error message above Indexation Control section
								$('#seo-controls-messages').html(
									'<div class="notice notice-error is-dismissible" style="margin-bottom: 20px;">' +
									'<p><strong>❌ Error!</strong> Network error occurred while saving. Please check your connection and try again.</p>' +
									'</div>'
								);
								console.error('AJAX Error:', error);
								console.error('XHR Status:', xhr.status);
								console.error('XHR Response:', xhr.responseText);

								// Don't clear unsaved changes on network error
								// User may want to retry or fix the issue
							},
							complete: function() {
								// Re-enable save button if it was disabled
								$('.metasync-save-button').prop('disabled', false);
							}
						});
					} else {
						// Fallback to regular form submission
						$('form[method="post"]').first().submit();
					}
				};
			}
		}

		// Initialize Indexation Control functionality
		initializeSeoControlsSaveHandler();

		/**
		 * Handle "All Roles" checkbox behavior for Content Genius sync
		 * When "All Roles" is checked, uncheck all other role checkboxes
		 * When any specific role is checked, uncheck "All Roles"
		 * 
		 * @since 1.0.0
		 * @returns {void}
		 */
		function initializeContentGeniusRoleCheckboxes() {
			try {
				// Cache selectors for better performance
				var allRolesCheckbox = $('input[name="metasync_options[general][content_genius_sync_roles][]"][value="all"]');
				var roleCheckboxes = $('input[name="metasync_options[general][content_genius_sync_roles][]"]').not('[value="all"]');
				
				// Exit early if elements don't exist
				if (allRolesCheckbox.length === 0 && roleCheckboxes.length === 0) {
					return;
				}
				
				// When "All Roles" is checked, uncheck all other checkboxes
				if (allRolesCheckbox.length > 0) {
					allRolesCheckbox.off('change.metasyncRoles').on('change.metasyncRoles', function () {
						try {
							if ($(this).is(':checked')) {
								roleCheckboxes.prop('checked', false);
								// Visual feedback
								roleCheckboxes.closest('.metasync-role-option').removeClass('active');
								$(this).closest('.metasync-role-option-all').addClass('active');
							} else {
								$(this).closest('.metasync-role-option-all').removeClass('active');
							}
						} catch (e) {
							console.warn('MetaSync: Error handling "All Roles" checkbox change:', e);
						}
					});
				}
				
				// When any specific role is checked, uncheck "All Roles"
				if (roleCheckboxes.length > 0) {
					roleCheckboxes.off('change.metasyncRoles').on('change.metasyncRoles', function () {
						try {
							if ($(this).is(':checked')) {
								allRolesCheckbox.prop('checked', false);
								allRolesCheckbox.closest('.metasync-role-option-all').removeClass('active');
								// Visual feedback
								$(this).closest('.metasync-role-option').addClass('active');
							} else {
								$(this).closest('.metasync-role-option').removeClass('active');
							}
						} catch (e) {
							console.warn('MetaSync: Error handling role checkbox change:', e);
						}
					});
				}
				
				// Initialize active states on page load
				initializeRoleCheckboxStates();
				
			} catch (error) {
				console.error('MetaSync: Failed to initialize Content Genius role checkboxes:', error);
			}
		}
		
		/**
		 * Initialize active states for role checkboxes on page load
		 * 
		 * @since 1.0.0
		 * @returns {void}
		 */
		function initializeRoleCheckboxStates() {
			try {
				var allRolesCheckbox = $('input[name="metasync_options[general][content_genius_sync_roles][]"][value="all"]');
				var roleCheckboxes = $('input[name="metasync_options[general][content_genius_sync_roles][]"]').not('[value="all"]');
				
				// Set active state for "All Roles" if checked
				if (allRolesCheckbox.is(':checked')) {
					allRolesCheckbox.closest('.metasync-role-option-all').addClass('active');
				}
				
				// Set active state for checked roles
				roleCheckboxes.each(function () {
					if ($(this).is(':checked')) {
						$(this).closest('.metasync-role-option').addClass('active');
					}
				});
			} catch (e) {
				console.warn('MetaSync: Error initializing role checkbox states:', e);
			}
		}
		
		// Initialize Content Genius role checkboxes with safety wrapper
		if (typeof $ !== 'undefined' && $.fn) {
			initializeContentGeniusRoleCheckboxes();
		} else {
			console.warn('MetaSync: jQuery not available for role checkbox initialization');
		}

		// ========================================
		// SETTINGS ACCORDION FUNCTIONALITY
		// ========================================

		/**
	 * Initialize accordion sections with localStorage state persistence
	 */
		function initSettingsAccordion() {
			var $accordionSections = $('.metasync-accordion-section');

			if ($accordionSections.length === 0) {
				return; // No accordion on this page
			}

			console.log('🎨 Initializing settings accordion with ' + $accordionSections.length + ' sections');

			// Restore saved state from localStorage
			restoreAccordionState();

			// Click handler for accordion headers
			$('.metasync-accordion-header').on('click', function (e) {
				toggleAccordionSection($(this));
			});

			// Keyboard navigation (Enter/Space)
			$('.metasync-accordion-header').on('keydown', function (e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					toggleAccordionSection($(this));
				}
			});
		}

		/**
	 * Toggle accordion section open/closed
	 * @param {jQuery} $header - The clicked header element
	 */
		function toggleAccordionSection($header) {
			var $section = $header.closest('.metasync-accordion-section');
			var $content = $section.find('.metasync-accordion-content');
			var isOpen = $header.attr('aria-expanded') === 'true';
			var sectionKey = $section.data('section');

			if (isOpen) {
			// Close section
				$header.attr('aria-expanded', 'false');
				$content.attr('data-state', 'closed');
				console.log('📁 Closed accordion section: ' + sectionKey);
			} else {
			// Open section
				$header.attr('aria-expanded', 'true');
				$content.attr('data-state', 'open');
				console.log('📂 Opened accordion section: ' + sectionKey);
			}

			// Save state to localStorage
			saveAccordionState();
		}

		/**
	 * Save accordion state to localStorage
	 */
		function saveAccordionState() {
			var state = {};

			$('.metasync-accordion-section').each(function () {
				var sectionKey = $(this).data('section');
				var isOpen = $(this).find('.metasync-accordion-header').attr('aria-expanded') === 'true';
				state[sectionKey] = isOpen;
			});

			try {
				localStorage.setItem('metasync_accordion_state', JSON.stringify(state));
			} catch (e) {
				console.warn('⚠️ Could not save accordion state to localStorage:', e);
			}
		}

		/**
	 * Restore accordion state from localStorage
	 */
		function restoreAccordionState() {
			try {
				var savedState = localStorage.getItem('metasync_accordion_state');
				if (!savedState) {
					return; // No saved state, use defaults
				}

				var state = JSON.parse(savedState);

				$('.metasync-accordion-section').each(function () {
					var $section = $(this);
					var sectionKey = $section.data('section');
					var $header = $section.find('.metasync-accordion-header');
					var $content = $section.find('.metasync-accordion-content');

					if (Object.prototype.hasOwnProperty.call(state, sectionKey)) {
						var shouldBeOpen = state[sectionKey];
						$header.attr('aria-expanded', shouldBeOpen ? 'true' : 'false');
						$content.attr('data-state', shouldBeOpen ? 'open' : 'closed');
					}
				});

				console.log('💾 Restored accordion state from localStorage');
			} catch (e) {
				console.warn('⚠️ Could not restore accordion state:', e);
			}
		}

		// Initialize accordion when DOM is ready
		initSettingsAccordion();

		// ========================================
		// TOOLTIP SYSTEM
		// ========================================

		/**
	 * Initialize tooltip functionality
	 */
		function initTooltipSystem() {
			console.log('🔍 Tooltip system initialization started');

			var $tooltipTriggers = $('.metasync-tooltip-trigger');
			var currentTooltip = null;

			console.log('🔍 Found ' + $tooltipTriggers.length + ' tooltip triggers');

			if ($tooltipTriggers.length === 0) {
				console.warn('⚠️ No tooltip triggers found on this page');
				return; // No tooltips on this page
			}

			console.log('💡 Initializing tooltip system with ' + $tooltipTriggers.length + ' tooltips');

			// Click handler for tooltip triggers
			$tooltipTriggers.on('click', function (e) {
				e.preventDefault();
				e.stopPropagation();

				var $trigger = $(this);
				var tooltipId = $trigger.data('tooltip-id');
				var $tooltip = $('#tooltip-' + tooltipId);

				console.log('🖱️ Tooltip trigger clicked:', tooltipId);
				console.log('🎯 Tooltip element found:', $tooltip.length);

				// Close other tooltips
				if (currentTooltip && currentTooltip[0] !== $tooltip[0]) {
					currentTooltip.removeClass('show');
				}

				// Toggle current tooltip
				if ($tooltip.hasClass('show')) {
					$tooltip.removeClass('show');
					currentTooltip = null;
				} else {
					$tooltip.addClass('show');
					currentTooltip = $tooltip;
					positionTooltip($trigger, $tooltip);
				}
			});

			// Hover handler (desktop only)
			var hideTimeout;

			if (window.innerWidth > 768) {
			// Show tooltip on trigger hover
				$tooltipTriggers.on('mouseenter', function () {
					var $trigger = $(this);
					var tooltipId = $trigger.data('tooltip-id');
					var $tooltip = $('#tooltip-' + tooltipId);

					// Clear any pending hide timeout
					clearTimeout(hideTimeout);

					console.log('🖱️ Hover on trigger:', tooltipId);

					// Close other tooltips
					if (currentTooltip && currentTooltip[0] !== $tooltip[0]) {
						currentTooltip.removeClass('show');
					}

					$tooltip.addClass('show');
					currentTooltip = $tooltip;
					positionTooltip($trigger, $tooltip);
				});

				// Start hide timer when leaving trigger
				$tooltipTriggers.on('mouseleave', function () {
					var tooltipId = $(this).data('tooltip-id');
					var $tooltip = $('#tooltip-' + tooltipId);

					console.log('🖱️ Mouse left trigger:', tooltipId);

					// Delay hiding to allow moving to tooltip
					hideTimeout = setTimeout(function () {
					// Only hide if not hovering tooltip
						if (!$tooltip.is(':hover')) {
							console.log('⏱️ Hiding tooltip:', tooltipId);
							$tooltip.removeClass('show');
							if (currentTooltip && currentTooltip[0] === $tooltip[0]) {
								currentTooltip = null;
							}
						} else {
							console.log('✋ Mouse is over tooltip, keeping visible');
						}
					}, 200);
				});

				// Cancel hide when entering tooltip
				$('.metasync-tooltip').on('mouseenter', function () {
					console.log('🎯 Mouse entered tooltip');
					clearTimeout(hideTimeout);
					$(this).addClass('show');
				});

				// Hide when leaving tooltip
				$('.metasync-tooltip').on('mouseleave', function () {
					console.log('🎯 Mouse left tooltip');
					var $tooltip = $(this);

					hideTimeout = setTimeout(function () {
						var tooltipId = $tooltip.attr('id').replace('tooltip-', '');
						var $trigger = $('[data-tooltip-id="' + tooltipId + '"]');

						// Only hide if not hovering trigger
						if (!$trigger.is(':hover')) {
							console.log('⏱️ Hiding tooltip from tooltip leave');
							$tooltip.removeClass('show');
							if (currentTooltip && currentTooltip[0] === $tooltip[0]) {
								currentTooltip = null;
							}
						} else {
							console.log('✋ Mouse is back on trigger, keeping visible');
						}
					}, 200);
				});
			}

			// Close tooltip when clicking outside
			$(document).on('click', function (e) {
				if (!$(e.target).closest('.metasync-tooltip-trigger, .metasync-tooltip').length) {
					if (currentTooltip) {
						currentTooltip.removeClass('show');
						currentTooltip = null;
					}
				}
			});

			// Keyboard accessibility - ESC to close
			$(document).on('keydown', function (e) {
				if (e.key === 'Escape' && currentTooltip) {
					currentTooltip.removeClass('show');
					currentTooltip = null;
				}
			});

			// Reposition tooltips on window resize
			$(window).on('resize', function () {
				if (currentTooltip) {
					var tooltipId = currentTooltip.attr('id').replace('tooltip-', '');
					var $trigger = $('[data-tooltip-id="' + tooltipId + '"]');
					positionTooltip($trigger, currentTooltip);
				}
			});
		}

		/**
	 * Position tooltip relative to trigger
	 * @param {jQuery} $trigger - The trigger button
	 * @param {jQuery} $tooltip - The tooltip element
	 */
		function positionTooltip($trigger, $tooltip) {
		// Skip positioning on mobile (uses fixed positioning)
			if (window.innerWidth <= 768) {
				return;
			}

			var triggerRect = $trigger[0].getBoundingClientRect();
			var tooltipWidth = $tooltip.outerWidth();
			var viewportWidth = $(window).width();
			var spaceRight = viewportWidth - triggerRect.right;

			// Check if tooltip would overflow on the right
			if (spaceRight < tooltipWidth + 20) {
			// Position on the left side
				$tooltip.attr('data-position', 'left');
			} else {
			// Position on the right side (default)
				$tooltip.attr('data-position', 'right');
			}
		}

		// Initialize tooltip system
		initTooltipSystem();

		// PR3: Burst ping — 30s polling when UNREGISTERED or KEY_PENDING (max 30 min)
		(function () {
			if (typeof metaSync === 'undefined' || !metaSync.heartbeat_state) return;
			var state = metaSync.heartbeat_state;
			if (state !== 'UNREGISTERED' && state !== 'KEY_PENDING') return;
			var BURST_CAP_MS = 30 * 60 * 1000;
			var startedAt = Date.now();
			var intervalId = setInterval(function () {
				if (Date.now() - startedAt > BURST_CAP_MS) {
					clearInterval(intervalId);
					return;
				}
				$.post(metaSync.ajax_url, {
					action: 'metasync_burst_ping',
					nonce: metaSync.burst_ping_nonce
				})
					.done(function (res) {
						if (res.success && res.data) {
							if (res.data.heartbeat_confirmed || res.data.state === 'CONNECTED') {
								clearInterval(intervalId);
								if (res.data.state === 'CONNECTED' && typeof updateHeaderStatus === 'function') {
									updateHeaderStatus(true, 'Synced', 'Heartbeat confirmed');
								}
							}
						}
					});
			}, 30000);
		})();

	});

})(jQuery);

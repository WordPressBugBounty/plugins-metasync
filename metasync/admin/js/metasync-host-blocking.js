/* global metasyncHostBlockingData, jQuery */
/**
 * MetaSync Host Blocking Test
 *
 * Extracted for Phase 5, #887.
 * Handles GET/POST/BOTH host blocking tests on the settings page
 * and the dashboard "Test Both" variant.
 *
 * Localized object: metasyncHostBlockingData
 *   - ajaxUrl (string)
 *
 * @since Phase 5
 */
(function () {
	// Wait for jQuery to be available
	function initHostBlockingTest() {
		if (typeof jQuery === 'undefined') {
			setTimeout(initHostBlockingTest, 100);
			return;
		}

		jQuery(document).ready(function ($) {
			// Ensure ajax URL is available in this scope
			var ajaxUrl = (typeof window.ajaxurl !== 'undefined' && window.ajaxurl)
				? window.ajaxurl
				: metasyncHostBlockingData.ajaxUrl;

			// --- Settings page buttons (GET / POST / BOTH) ---
			$(document).on('click', '#test-get-request', function (e) {
				e.preventDefault();
				e.stopPropagation();
				runHostTest('GET');
				return false;
			});

			$(document).on('click', '#test-post-request', function (e) {
				e.preventDefault();
				e.stopPropagation();
				runHostTest('POST');
				return false;
			});

			// --- Shared "Test Both" button (settings + dashboard) ---
			var $btn = $('#test-both-requests');

			$btn.on('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				runHostTest('BOTH');
				return false;
			});

			$(document).on('click', '#test-both-requests', function (e) {
				e.preventDefault();
				e.stopPropagation();
				runHostTest('BOTH');
				return false;
			});

			// Expose function globally for debugging
			window.runHostBlockingTest = function () {
				runHostTest('BOTH');
			};

			function runHostTest(method) {
				var buttonId = (method === 'BOTH' ? 'test-both-requests' : 'test-' + method.toLowerCase() + '-request');
				var $button = $('#' + buttonId);

				if ($button.length === 0) {
					alert('Error: Test button not found. Please refresh the page.');
					return;
				}

				var originalText = $button.text();

				// Disable button and show loading
				$button.prop('disabled', true);
				$button.text('\uD83D\uDD04 Testing...');

				// Prepare results area
				var $resultsDiv = $('#host-test-results');
				var $resultsContent = $('#test-results-content');
				$resultsDiv.show();
				$resultsContent.html('<div class="notice notice-info"><p>Running ' + (method === 'BOTH' ? 'GET and POST' : method) + ' test(s)...</p></div>');

				var testsToRun = (method === 'BOTH') ? ['GET', 'POST'] : [method];
				var completedTests = 0;
				var allResults = [];

				testsToRun.forEach(function (testMethod) {
					var action = 'metasync_test_host_blocking_' + testMethod.toLowerCase();

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						dataType: 'json',
						data: { action: action, nonce: metasyncHostBlockingData.nonce },
						timeout: 35000,
						success: function (response, textStatus, xhr) {
							try {
								if (response && response.success && response.data) {
									allResults.push(response.data);
								} else {
									allResults.push({
										method: testMethod,
										status: 'error',
										error: (response && response.data) ? response.data : 'Unexpected response',
										blocked: true,
										details: 'Received non-success response from server.'
									});
								}
							} catch (e) {
								allResults.push({
									method: testMethod,
									status: 'error',
									error: 'Response parse error: ' + (e && e.message ? e.message : e),
									blocked: true
								});
							}
							finalizeOne();
						},
						error: function (xhr, status, error) {
							var payload = (xhr && xhr.responseText) ? xhr.responseText.substring(0, 500) : '';
							allResults.push({
								method: testMethod,
								status: 'error',
								error: 'AJAX failed: ' + error + (payload ? ' \u2014 ' + payload : ''),
								blocked: true,
								details: 'Request did not complete successfully. Status: ' + status
							});
							finalizeOne();
						}
					});
				});

				function finalizeOne() {
					completedTests++;
					if (completedTests === testsToRun.length) {
						displayResults(allResults);
						resetButtons();
						// Scroll results into view for clarity
						var $container = $('#host-test-results');
						if ($container && $container[0] && $container[0].scrollIntoView) {
							$container[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
						}
					}
				}
			}

			function resetButtons() {
				$('#test-get-request, #test-post-request, #test-both-requests').prop('disabled', false);
				$('#test-get-request').text('\uD83D\uDD0D Test GET Request');
				$('#test-post-request').text('\uD83D\uDCE4 Test POST Request');
				$('#test-both-requests').text('\uD83D\uDD04 Test Both Requests');
			}

			function makeText(str) {
				return document.createTextNode(String(str));
			}

			function makePre(cssClass, content) {
				var pre = document.createElement('pre');
				pre.className = cssClass;
				pre.appendChild(makeText(content));
				return pre;
			}

			function makeP(labelText, valueText) {
				var p = document.createElement('p');
				var strong = document.createElement('strong');
				strong.appendChild(makeText(labelText));
				p.appendChild(strong);
				p.appendChild(makeText(valueText));
				return p;
			}

			function displayResults(results) {
				var $container = $('#test-results-content').empty();

				results.forEach(function (result) {
					var statusClass = result.status === 'success' ? 'success' : 'error';
					var statusIcon = result.status === 'success' ? '\u2705' : '\u274C';
					var blockedStatus = result.blocked ? 'BLOCKED' : 'ALLOWED';
					var blockedClass = result.blocked ? 'blocked' : 'allowed';

					var $item = $('<div>').addClass('test-result-item ' + statusClass);

					// Header
					var $header = $('<div>').addClass('test-result-header');
					$('<h4>').text(statusIcon + ' ' + result.method + ' Request - ' + blockedStatus).appendTo($header);
					$('<span>').addClass('test-status ' + blockedClass).text(blockedStatus).appendTo($header);
					$item.append($header);

					// Details
					var $details = $('<div>').addClass('test-result-details');
					$details.append(makeP('Response Time: ', result.response_time));
					$details.append(makeP('Status: ', result.status));

					if (result.status_code) {
						var $p = $('<p>');
						$('<strong>').text('HTTP Status Code: ').appendTo($p);
						$('<span>').addClass('status-code').text(String(result.status_code)).appendTo($p);
						$details.append($p);
					}

					if (result.error) {
						var $pe = $('<p>');
						$('<strong>').text('Error: ').appendTo($pe);
						$('<span>').addClass('error-message').text(result.error).appendTo($pe);
						$details.append($pe);
					}

					if (result.body) {
						$details.append($('<p>').append($('<strong>').text('Response Body:')));
						$details.append(makePre('response-body', result.body));
					}

					if (result.headers && Object.keys(result.headers).length > 0) {
						$details.append($('<p>').append($('<strong>').text('Response Headers:')));
						var headersText = '';
						Object.keys(result.headers).forEach(function (key) {
							headersText += key + ': ' + String(result.headers[key]) + '\n';
						});
						$details.append(makePre('response-headers', headersText));
					}

					if (result.sent_data) {
						$details.append($('<p>').append($('<strong>').text('Sent Data:')));
						$details.append(makePre('sent-data', JSON.stringify(result.sent_data, null, 2)));
					}

					if (result.parsed_response) {
						$details.append($('<p>').append($('<strong>').text('Parsed Response:')));
						$details.append(makePre('parsed-response', JSON.stringify(result.parsed_response, null, 2)));
					}

					$details.append(makeP('Details: ', result.details));
					$item.append($details);
					$container.append($item);
				});

				$('#host-test-results').show();
			}

			function escapeHtml(text) {
				var map = {
					'&': '&amp;',
					'<': '&lt;',
					'>': '&gt;',
					'"': '&quot;',
					"'": '&#039;'
				};
				return text.replace(/[&<>"']/g, function (m) {
					return map[m]; 
				});
			}

			// Verify button exists on page load
			setTimeout(function () {
				var btnBoth = $('#test-both-requests');
			}, 500);
		});
	}

	// Start initialization
	initHostBlockingTest();
})();

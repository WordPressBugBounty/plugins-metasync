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
                        data: { action: action },
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

            function displayResults(results) {
                var html = '';

                results.forEach(function (result) {
                    var statusClass = result.status === 'success' ? 'success' : 'error';
                    var statusIcon = result.status === 'success' ? '\u2705' : '\u274C';
                    var blockedStatus = result.blocked ? 'BLOCKED' : 'ALLOWED';
                    var blockedClass = result.blocked ? 'blocked' : 'allowed';

                    html += '<div class="test-result-item ' + statusClass + '">';
                    html += '<div class="test-result-header">';
                    html += '<h4>' + statusIcon + ' ' + result.method + ' Request - ' + blockedStatus + '</h4>';
                    html += '<span class="test-status ' + blockedClass + '">' + blockedStatus + '</span>';
                    html += '</div>';

                    html += '<div class="test-result-details">';
                    html += '<p><strong>Response Time:</strong> ' + result.response_time + '</p>';
                    html += '<p><strong>Status:</strong> ' + result.status + '</p>';

                    if (result.status_code) {
                        html += '<p><strong>HTTP Status Code:</strong> <span class="status-code">' + result.status_code + '</span></p>';
                    }

                    if (result.error) {
                        html += '<p><strong>Error:</strong> <span class="error-message">' + result.error + '</span></p>';
                    }

                    if (result.body) {
                        html += '<p><strong>Response Body:</strong></p>';
                        html += '<pre class="response-body">' + escapeHtml(result.body) + '</pre>';
                    }

                    if (result.headers && Object.keys(result.headers).length > 0) {
                        html += '<p><strong>Response Headers:</strong></p>';
                        html += '<pre class="response-headers">';
                        Object.keys(result.headers).forEach(function (key) {
                            html += key + ': ' + result.headers[key] + '\n';
                        });
                        html += '</pre>';
                    }

                    if (result.sent_data) {
                        html += '<p><strong>Sent Data:</strong></p>';
                        html += '<pre class="sent-data">' + escapeHtml(JSON.stringify(result.sent_data, null, 2)) + '</pre>';
                    }

                    // Show parsed response data if available
                    if (result.parsed_response) {
                        html += '<p><strong>Parsed Response:</strong></p>';
                        html += '<pre class="parsed-response">' + escapeHtml(JSON.stringify(result.parsed_response, null, 2)) + '</pre>';
                    }

                    html += '<p><strong>Details:</strong> ' + result.details + '</p>';
                    html += '</div>';
                    html += '</div>';
                });

                $('#test-results-content').html(html);
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
                return text.replace(/[&<>"']/g, function (m) { return map[m]; });
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

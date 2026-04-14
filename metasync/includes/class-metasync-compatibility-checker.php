<?php

/**
 * Compatibility Checker – checks plugin/theme compatibility with MetaSync.
 *
 * Extracted from Metasync_Admin to keep the admin class focused on UI scaffolding.
 *
 * @package Starter_Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Compatibility_Checker
{
    private static $instance = null;

    private function __construct() {}

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Compatibility page callback.
     *
     * @param object $admin The Metasync_Admin instance (used for render_plugin_header / render_navigation_menu).
     */
    public function create_admin_compatibility_page($admin)
    {
        $admin->render_layout_open('Compatibility', 'compatibility', 'Check compatibility status with popular plugins, page builders, and caching solutions.');
        ?>
            
            <div class="dashboard-card">
                <?php $this->render_compatibility_sections(); ?>
            </div>
            
            <!-- Section: Host Blocking Test -->
            <div id="ms-comp-host-test" class="dashboard-card" style="margin-top: 20px;">
                <details open>
                    <summary style="cursor:pointer; list-style:none;">
                        <div style="display:flex; justify-content: space-between; align-items:center;">
                            <div style="flex:1;">
                                <h2 style="margin: 0;">Host Blocking Test</h2>
                                <p style="color: var(--dashboard-text-secondary); margin: 6px 0 0 0;">Verify if this site can reach <?php echo esc_html(Metasync::get_effective_plugin_name()); ?> services.</p>
                            </div>
                        </div>
                    </summary>
                    <div style="margin-top:16px; background: var(--dashboard-bg); border: 1px solid var(--dashboard-border); padding: 20px; border-radius: 8px;">
                        <h3 style="margin-top: 0; color: var(--dashboard-text-primary);">Test Configuration</h3>
                        <p style="margin-bottom: 16px; color: var(--dashboard-text-secondary);">Test connectivity by running both GET and POST requests. Results appear below.</p>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <button type="button" id="test-both-requests" class="button button-primary">
                                <span class="dashicons dashicons-controls-repeat" style="margin-top:3px;font-size:15px;width:15px;height:15px;"></span> Test Both Requests
                            </button>
                        </div>
                    </div>
                    <div id="host-test-results" style="display: none; margin-top:16px;">
                        <h3 style="color: var(--dashboard-text-primary); margin-bottom: 10px;">Test Results</h3>
                        <div id="test-results-content"></div>
                    </div>
                </details>
            </div>
            
            <?php $this->render_troubleshooting_section(); ?>

            <!-- Host Blocking Test JavaScript -->
            <script>
            (function() {
                function initHostBlockingTest() {
                    if (typeof jQuery === 'undefined') {
                        setTimeout(initHostBlockingTest, 100);
                        return;
                    }
                    
                    jQuery(document).ready(function($) {
                        var ajaxUrl = (typeof window.ajaxurl !== 'undefined' && window.ajaxurl)
                            ? window.ajaxurl
                            : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                        
                        var $btn = $('#test-both-requests');
                        
                        $btn.on('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            runHostTest('BOTH', $);
                            return false;
                        });
                        
                        $(document).on('click', '#test-both-requests', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            runHostTest('BOTH', $);
                            return false;
                        });
                        
                        window.runHostBlockingTest = function() {
                            runHostTest('BOTH', $);
                        };
                
                        function runHostTest(method, $) {
                            method = 'BOTH';
                            var buttonId = 'test-both-requests';
                            var $button = $('#' + buttonId);
                            
                            if ($button.length === 0) {
                                alert('Error: Test button not found. Please refresh the page.');
                                return;
                            }
                            
                            var originalText = $button.text();
                            
                            $button.prop('disabled', true);
                            $button.text('🔄 Testing...');
                            
                            var $resultsDiv = $('#host-test-results');
                            var $resultsContent = $('#test-results-content');
                            $resultsDiv.show();
                            $resultsContent.html('<div class="notice notice-info"><p>Running GET and POST test(s)...</p></div>');
                            
                            var testsToRun = ['GET', 'POST'];
                            var completedTests = 0;
                            var allResults = [];
                            
                            testsToRun.forEach(function(testMethod) {
                                var action = 'metasync_test_host_blocking_' + testMethod.toLowerCase();
                                
                                $.ajax({
                                    url: ajaxUrl,
                                    type: 'POST',
                                    dataType: 'json',
                                    data: { action: action },
                                    timeout: 35000,
                                    success: function(response, textStatus, xhr) {
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
                                    error: function(xhr, status, error) {
                                        var payload = (xhr && xhr.responseText) ? xhr.responseText.substring(0, 500) : '';
                                        allResults.push({
                                            method: testMethod,
                                            status: 'error',
                                            error: 'AJAX failed: ' + error + (payload ? ' — ' + payload : ''),
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
                                    var $container = $('#host-test-results');
                                    if ($container && $container[0] && $container[0].scrollIntoView) {
                                        $container[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
                                    }
                                }
                            }
                            
                            function resetButtons() {
                                $('#test-both-requests').prop('disabled', false);
                                $('#test-both-requests').text('🔄 Test Both Requests');
                            }
                            
                            function displayResults(results) {
                                var html = '';
                                
                                results.forEach(function(result) {
                                    var statusClass = result.status === 'success' ? 'success' : 'error';
                                    var statusIcon = result.status === 'success' ? '✅' : '❌';
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
                                        Object.keys(result.headers).forEach(function(key) {
                                            html += key + ': ' + result.headers[key] + '\n';
                                        });
                                        html += '</pre>';
                                    }
                                    
                                    if (result.sent_data) {
                                        html += '<p><strong>Sent Data:</strong></p>';
                                        html += '<pre class="sent-data">' + escapeHtml(JSON.stringify(result.sent_data, null, 2)) + '</pre>';
                                    }
                                    
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
                                return text.replace(/[&<>"']/g, function(m) { return map[m]; });
                            }
                            
                            setTimeout(function() {
                                var btnBoth = $('#test-both-requests');
                            }, 500);
                        }
                    });
                }
                
                initHostBlockingTest();
            })();
            </script>

            <!-- Host Blocking Test CSS -->
            <style>
            .test-result-item {
                background: var(--dashboard-card-bg);
                border: 1px solid var(--dashboard-border);
                border-radius: 8px;
                margin-bottom: 20px;
                overflow: hidden;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            
            .test-result-item.success {
                border-left: 4px solid #28a745;
            }
            
            .test-result-item.error {
                border-left: 4px solid #dc3545;
            }
            
            .test-result-header {
                background: var(--dashboard-bg);
                padding: 15px 20px;
                border-bottom: 1px solid #dee2e6;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .test-result-header h4 {
                margin: 0;
                color: var(--dashboard-text-primary);
                font-size: 16px;
            }
            
            .test-status {
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: bold;
                text-transform: uppercase;
            }
            
            .test-status.allowed {
                background: rgba(74,222,128,0.15);
                color: #4ade80;
            }
            
            .test-status.blocked {
                background: rgba(248,113,113,0.15);
                color: #f87171;
            }
            
            .test-result-details {
                padding: 20px;
            }
            
            .test-result-details p {
                margin: 8px 0;
                color: var(--dashboard-text-primary);
            }
            
            .test-result-details code {
                background: var(--dashboard-bg);
                padding: 2px 6px;
                border-radius: 3px;
                font-family: 'Courier New', monospace;
                color: #e83e8c;
            }
            
            .status-code {
                font-weight: bold;
                color: #007cba;
            }
            
            .error-message {
                color: #dc3545;
                font-weight: bold;
            }
            
            .response-body, .response-headers, .sent-data, .parsed-response {
                background: var(--dashboard-bg);
                border: 1px solid var(--dashboard-border);
                border-radius: 4px;
                padding: 12px;
                margin: 8px 0;
                font-family: 'Courier New', monospace;
                font-size: 12px;
                line-height: 1.4;
                max-height: 200px;
                overflow-y: auto;
                white-space: pre-wrap;
                word-break: break-all;
            }
            
            .response-body {
                color: var(--dashboard-text-primary);
            }
            
            .response-headers {
                color: var(--dashboard-text-secondary);
            }
            
            .sent-data {
                color: #007cba;
            }
            
            .parsed-response {
                color: #4ade80;
                background: var(--dashboard-card-bg);
                border-color: var(--dashboard-border);
            }
            
            #host-test-results {
                margin-top: 20px;
            }
            
            #host-test-results h3 {
                color: var(--dashboard-text-primary);
                margin-bottom: 15px;
                padding-bottom: 10px;
                border-bottom: 2px solid #dee2e6;
            }
            
            .button:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            </style>

            <!-- Section: OTTO Excluded URLs -->
            <div id="ms-otto-excluded-urls" class="dashboard-card" style="margin-top: 20px;">
                <details open>
                    <summary style="cursor:pointer; list-style:none;">
                        <div style="display:flex; justify-content: space-between; align-items:center;">
                            <div style="flex:1;">
                                <h2 style="margin: 0;">🚫 <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> Excluded URLs</h2>
                                <p style="color: var(--dashboard-text-secondary); margin: 6px 0 0 0;">Manage URLs where <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> should not run. Add URL patterns to exclude specific pages from <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> processing.</p>
                            </div>
                        </div>
                    </summary>

                    <!-- Add New Excluded URL Form -->
                    <div style="margin-top:16px; background: var(--dashboard-bg); border: 1px solid var(--dashboard-border); padding: 20px; border-radius: 8px;">
                        <h3 style="margin-top: 0; color: var(--dashboard-text-primary);">Add New Excluded URL</h3>
                        <div id="otto-excluded-url-form">
                            <div style="margin-bottom: 15px;">
                                <label for="otto-url-pattern" style="display: block; margin-bottom: 5px; font-weight: 600; color: var(--dashboard-text-primary);">URL Pattern *</label>
                                <input type="text" id="otto-url-pattern" class="regular-text" placeholder="e.g., https://example.com/excluded-page" style="width: 100%; max-width: 500px;" />
                                <p class="description">Enter the URL or pattern you want to exclude from <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?>.</p>
                            </div>

                            <div style="margin-bottom: 15px;">
                                <label for="otto-pattern-type" style="display: block; margin-bottom: 5px; font-weight: 600; color: var(--dashboard-text-primary);">Match Type *</label>
                                <select id="otto-pattern-type" class="regular-text" style="width: 100%; max-width: 300px;">
                                    <option value="exact">Exact Match</option>
                                    <option value="contain">Contains</option>
                                    <!-- <option value="start">Starts With</option>
                                    <option value="end">Ends With</option>
                                    <option value="regex">Regular Expression</option> -->
                                </select>
                                <p class="description">How the URL should be matched against the pattern.</p>
                            </div>

                            <div style="margin-bottom: 15px;">
                                <label for="otto-description" style="display: block; margin-bottom: 5px; font-weight: 600; color: var(--dashboard-text-primary);">Description (Optional)</label>
                                <textarea id="otto-description" rows="3" class="large-text" placeholder="Optional description for this exclusion rule" style="width: 100%; max-width: 500px;"></textarea>
                            </div>

                            <div style="display: flex; gap: 10px; align-items: center;">
                                <button type="button" id="otto-add-excluded-url-btn" class="button button-primary">
                                    ➕ Add Excluded URL
                                </button>
                                <span id="otto-add-status" style="display: none; padding: 5px 10px; border-radius: 4px;"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Excluded URLs List -->
                    <div id="otto-excluded-urls-list" style="margin-top:20px;">
                        <h3 style="color: var(--dashboard-text-primary); margin-bottom: 15px;">Excluded URLs</h3>
                        <div id="otto-excluded-urls-table-container" style="overflow-x: auto;">
                            <div style="text-align: center; padding: 40px; color: var(--dashboard-text-secondary);">
                                <p>Loading excluded URLs...</p>
                            </div>
                        </div>
                    </div>
                </details>
            </div>

            <!-- OTTO Excluded URLs JavaScript -->
            <script>
            (function() {
                if (typeof jQuery === 'undefined') {
                    setTimeout(arguments.callee, 100);
                    return;
                }

                jQuery(document).ready(function($) {
                    var currentPage = 1;
                    var perPage = 10;
                    var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                    var nonce = '<?php echo wp_create_nonce('metasync_otto_excluded_urls'); ?>';

                    loadExcludedURLs();

                    $('#otto-add-excluded-url-btn').on('click', function(e) {
                        e.preventDefault();
                        addExcludedURL();
                    });

                    $('#otto-url-pattern').on('keypress', function(e) {
                        if (e.which === 13) {
                            e.preventDefault();
                            addExcludedURL();
                        }
                    });

                    function addExcludedURL() {
                        var $button = $('#otto-add-excluded-url-btn');
                        var $status = $('#otto-add-status');
                        var urlPattern = $('#otto-url-pattern').val().trim();
                        var patternType = $('#otto-pattern-type').val();
                        var description = $('#otto-description').val().trim();

                        if (!urlPattern) {
                            showStatus('error', 'URL pattern is required');
                            return;
                        }

                        $button.prop('disabled', true).text('Adding...');
                        $status.hide();

                        $.ajax({
                            url: ajaxUrl,
                            type: 'POST',
                            data: {
                                action: 'metasync_otto_add_excluded_url',
                                nonce: nonce,
                                url_pattern: urlPattern,
                                pattern_type: patternType,
                                description: description
                            },
                            success: function(response) {
                                if (response.success) {
                                    showStatus('success', response.data.message || 'URL excluded successfully');
                                    $('#otto-url-pattern').val('');
                                    $('#otto-description').val('');
                                    $('#otto-pattern-type').val('exact');
                                    loadExcludedURLs();
                                } else {
                                    showStatus('error', response.data.message || 'Failed to add excluded URL');
                                }
                            },
                            error: function() {
                                showStatus('error', 'An error occurred. Please try again.');
                            },
                            complete: function() {
                                $button.prop('disabled', false).text('➕ Add Excluded URL');
                            }
                        });
                    }

                    function loadExcludedURLs(page) {
                        page = page || currentPage;
                        var $container = $('#otto-excluded-urls-table-container');

                        $container.html('<div style="text-align: center; padding: 40px; color: var(--dashboard-text-secondary);"><p>Loading...</p></div>');

                        $.ajax({
                            url: ajaxUrl,
                            type: 'POST',
                            data: {
                                action: 'metasync_otto_get_excluded_urls',
                                nonce: nonce,
                                page: page,
                                per_page: perPage
                            },
                            success: function(response) {
                                if (response.success) {
                                    currentPage = page;
                                    renderExcludedURLsTable(response.data.records, response.data.pagination);
                                } else {
                                    $container.html('<div style="text-align: center; padding: 40px; color: #dc3545;"><p>Error loading excluded URLs</p></div>');
                                }
                            },
                            error: function() {
                                $container.html('<div style="text-align: center; padding: 40px; color: #dc3545;"><p>Failed to load excluded URLs</p></div>');
                            }
                        });
                    }

                    function renderExcludedURLsTable(records, pagination) {
                        var $container = $('#otto-excluded-urls-table-container');

                        if (!records || records.length === 0) {
                            $container.html('<div style="text-align: center; padding: 40px; color: var(--dashboard-text-secondary);"><p>No excluded URLs found. Add one above to get started.</p></div>');
                            return;
                        }

                        var html = '<table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">';
                        html += '<thead><tr>';
                        html += '<th style="width: 30%;">URL Pattern</th>';
                        html += '<th style="width: 12%;">Match Type</th>';
                        html += '<th style="width: 25%;">Description</th>';
                        html += '<th style="width: 13%;">Recheck After</th>';
                        html += '<th style="width: 20%;">Actions</th>';
                        html += '</tr></thead><tbody>';

                        records.forEach(function(record) {
                            html += '<tr>';
                            html += '<td><code>' + escapeHtml(record.url_pattern) + '</code></td>';
                            html += '<td><span class="otto-pattern-type-badge otto-pattern-' + record.pattern_type + '">' + formatPatternType(record.pattern_type) + '</span></td>';
                            html += '<td>' + (record.description ? escapeHtml(record.description) : '<span style="color: #999;">—</span>') + '</td>';
                            html += '<td>' + formatRecheckAfter(record) + '</td>';
                            html += '<td><span class="otto-actions">';
                            html += '<button type="button" class="button button-small otto-recheck-url" data-id="' + record.id + '" style="margin-right: 5px;">Recheck</button>';
                            html += '<button type="button" class="button button-small otto-delete-url" data-id="' + record.id + '" style="color: #dc3545;">Delete</button>';
                            html += '</span></td>';
                            html += '</tr>';
                        });

                        html += '</tbody></table>';

                        if (pagination.total_pages > 1) {
                            html += '<div class="tablenav" style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">';
                            html += '<div class="tablenav-pages">';
                            html += '<span class="displaying-num">' + pagination.total_count + ' items</span>';

                            html += '<span class="pagination-links">';

                            if (pagination.current_page > 1) {
                                html += '<a class="button otto-page-nav" data-page="1" href="#">«</a> ';
                                html += '<a class="button otto-page-nav" data-page="' + (pagination.current_page - 1) + '" href="#">‹</a> ';
                            } else {
                                html += '<span class="button disabled">«</span> ';
                                html += '<span class="button disabled">‹</span> ';
                            }

                            html += '<span class="paging-input">';
                            html += '<span class="tablenav-paging-text">' + pagination.current_page + ' of <span class="total-pages">' + pagination.total_pages + '</span></span>';
                            html += '</span> ';

                            if (pagination.current_page < pagination.total_pages) {
                                html += '<a class="button otto-page-nav" data-page="' + (pagination.current_page + 1) + '" href="#">›</a> ';
                                html += '<a class="button otto-page-nav" data-page="' + pagination.total_pages + '" href="#">»</a>';
                            } else {
                                html += '<span class="button disabled">›</span> ';
                                html += '<span class="button disabled">»</span>';
                            }

                            html += '</span></div></div>';
                        }

                        $container.html(html);

                        $('.otto-delete-url').on('click', function(e) {
                            e.preventDefault();
                            var id = $(this).data('id');
                            deleteExcludedURL(id);
                        });

                        $('.otto-recheck-url').on('click', function(e) {
                            e.preventDefault();
                            var $btn = $(this);
                            var id = $btn.data('id');
                            recheckExcludedURL(id, $btn);
                        });

                        $('.otto-page-nav').on('click', function(e) {
                            e.preventDefault();
                            var page = $(this).data('page');
                            loadExcludedURLs(page);
                        });
                    }

                    function recheckExcludedURL(id, $btn) {
                        var originalText = $btn.text();
                        $btn.prop('disabled', true).text('Checking...');

                        $.ajax({
                            url: ajaxUrl,
                            type: 'POST',
                            data: {
                                action: 'metasync_otto_recheck_excluded_url',
                                nonce: nonce,
                                id: id
                            },
                            success: function(response) {
                                $btn.prop('disabled', false).text(originalText);
                                if (response.success) {
                                    if (response.data.available) {
                                        if (confirm('This URL is now available. Do you want to remove it from the excluded list?')) {
                                            deleteExcludedURL(id);
                                        }
                                    } else {
                                        alert('This URL is still returning 404.');
                                    }
                                } else {
                                    alert('Error: ' + (response.data.message || 'Recheck failed'));
                                }
                            },
                            error: function(xhr, status, err) {
                                $btn.prop('disabled', false).text(originalText);
                                alert('An error occurred while rechecking. Please try again.');
                            }
                        });
                    }

                    function deleteExcludedURL(id) {
                        if (!confirm('Are you sure you want to delete this excluded URL?')) {
                            return;
                        }

                        $.ajax({
                            url: ajaxUrl,
                            type: 'POST',
                            data: {
                                action: 'metasync_otto_delete_excluded_url',
                                nonce: nonce,
                                id: id
                            },
                            success: function(response) {
                                if (response.success) {
                                    loadExcludedURLs();
                                } else {
                                    alert('Error: ' + (response.data.message || 'Failed to delete'));
                                }
                            },
                            error: function() {
                                alert('An error occurred. Please try again.');
                            }
                        });
                    }

                    function showStatus(type, message) {
                        var $status = $('#otto-add-status');
                        $status.removeClass('otto-status-success otto-status-error');
                        $status.addClass('otto-status-' + type);
                        $status.text(message).fadeIn();

                        setTimeout(function() {
                            $status.fadeOut();
                        }, 5000);
                    }

                    function formatPatternType(type) {
                        var types = {
                            'exact': 'Exact',
                            'contain': 'Contains',
                            'start': 'Starts With',
                            'end': 'Ends With',
                            'regex': 'Regex'
                        };
                        return types[type] || type;
                    }

                    function formatRecheckAfter(record) {
                        if (!record.auto_excluded || !record.recheck_after) {
                            return '<span style="color: #999;">NA</span>';
                        }
                        var d = new Date(record.recheck_after.replace(' ', 'T'));
                        if (isNaN(d.getTime())) {
                            return escapeHtml(record.recheck_after);
                        }
                        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                        return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
                    }

                    function escapeHtml(text) {
                        if (!text) return '';
                        var map = {
                            '&': '&amp;',
                            '<': '&lt;',
                            '>': '&gt;',
                            '"': '&quot;',
                            "'": '&#039;'
                        };
                        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
                    }
                });
            })();
            </script>

            <!-- OTTO Excluded URLs CSS -->
            <style>
            /* Fix input and select field text colors */
            #otto-excluded-url-form input[type="text"],
            #otto-excluded-url-form input.regular-text,
            #otto-excluded-url-form select,
            #otto-excluded-url-form select.regular-text,
            #otto-excluded-url-form textarea,
            #otto-excluded-url-form textarea.large-text {
                color: #2c3338 !important;
                background-color: #fff !important;
                border: 1px solid #8c8f94 !important;
            }

            #otto-excluded-url-form select option {
                color: #2c3338 !important;
                background-color: #fff !important;
            }

            #otto-excluded-url-form input[type="text"]:focus,
            #otto-excluded-url-form select:focus,
            #otto-excluded-url-form textarea:focus {
                border-color: #2271b1 !important;
                box-shadow: 0 0 0 1px #2271b1 !important;
                outline: 2px solid transparent !important;
            }

            #otto-excluded-url-form input::placeholder,
            #otto-excluded-url-form textarea::placeholder {
                color: #646970 !important;
                opacity: 0.7;
            }

            .otto-pattern-type-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .otto-pattern-exact {
                background: #e3f2fd;
                color: #1976d2;
            }

            .otto-pattern-contain {
                background: #f3e5f5;
                color: #7b1fa2;
            }

            .otto-pattern-start {
                background: #e8f5e9;
                color: #388e3c;
            }

            .otto-pattern-end {
                background: var(--dashboard-card-bg)3e0;
                color: #f57c00;
            }

            .otto-pattern-regex {
                background: #fce4ec;
                color: #c2185b;
            }

            .otto-status-success {
                background: rgba(74,222,128,0.15);
                color: #4ade80;
                border: 1px solid #c3e6cb;
            }

            .otto-status-error {
                background: rgba(248,113,113,0.15);
                color: #f87171;
                border: 1px solid #f5c6cb;
            }

            #otto-excluded-urls-table-container .wp-list-table {
                border: 1px solid #c3c4c7;
                background: var(--dashboard-card-bg);
            }

            #otto-excluded-urls-table-container .wp-list-table th {
                background: #f0f0f1 !important;
                color: #1d2327 !important;
                font-weight: 600;
                border-bottom: 1px solid #c3c4c7;
            }

            #otto-excluded-urls-table-container .wp-list-table td {
                background: var(--dashboard-card-bg) !important;
                color: #2c3338 !important;
                border-bottom: 1px solid #c3c4c7;
            }

            #otto-excluded-urls-table-container .wp-list-table tbody tr {
                background: var(--dashboard-card-bg);
            }

            #otto-excluded-urls-table-container .wp-list-table tbody tr:hover {
                background: #f6f7f7;
            }

            #otto-excluded-urls-table-container .wp-list-table code {
                background: #f0f0f1 !important;
                color: #1d2327 !important;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 12px;
                border: 1px solid #dcdcde;
            }

            #otto-excluded-urls-table-container .otto-delete-url,
            #otto-excluded-urls-table-container .otto-recheck-url {
                padding: 4px 10px;
                font-size: 12px;
            }

            #otto-excluded-urls-table-container .otto-delete-url {
                background: #dc3545 !important;
                color: #fff !important;
                border-color: #dc3545 !important;
            }

            #otto-excluded-urls-table-container .otto-delete-url:hover {
                background: #c82333 !important;
                border-color: #bd2130 !important;
                color: #fff !important;
            }

            .button.disabled {
                opacity: 0.5;
                cursor: not-allowed;
                pointer-events: none;
            }

            /* Pagination styling */
            .tablenav {
                background: #f0f0f1;
                padding: 10px;
                border-radius: 4px;
            }

            .displaying-num {
                color: #646970 !important;
            }

            .pagination-links .button {
                color: #2271b1 !important;
                background: var(--dashboard-card-bg) !important;
                border-color: #2271b1 !important;
            }

            .pagination-links .button:hover {
                background: #2271b1 !important;
                color: #fff !important;
            }

            .tablenav-paging-text {
                color: #2c3338 !important;
            }
            </style>
        </div>
        <?php
        $admin->render_layout_close();
    }

    public function render_compatibility_sections()
    {
        ?>
        <div class="metasync-settings-accordion">
            <?php $this->render_compatibility_section(
                $this->get_page_builders_compatibility(),
                'admin-page',
                'Page Builders',
                'Elementor, Divi, Gutenberg, Oxygen, Bricks'
            ); ?>
            <?php $this->render_compatibility_section(
                $this->get_themes_compatibility(),
                'art',
                'Themes',
                'Astra, GeneratePress, OceanWP and more'
            ); ?>
            <?php $this->render_compatibility_section(
                $this->get_seo_plugins_compatibility(),
                'search',
                'SEO Plugins',
                'Yoast, Rank Math, AIOSEO and others'
            ); ?>
            <?php $this->render_compatibility_section(
                $this->get_cache_plugins_compatibility(),
                'performance',
                'Cache &amp; Performance',
                'WP Rocket, LiteSpeed, W3 Total Cache and more'
            ); ?>
            <?php $this->render_compatibility_section(
                $this->get_cdn_compatibility(),
                'networking',
                'CDN &amp; Hosting',
                'Cloudflare, Fastly, Bunny CDN, Kinsta, Flywheel'
            ); ?>
        </div>
        <?php
    }

    /**
     * Generic section renderer — expandable accordion row.
     */
    private function render_compatibility_section( array $items, string $dashicon, string $title, string $description = '' ): void
    {
        $section_key  = sanitize_title( $title );
        $section_id   = 'msrp-section-' . $section_key;
        $active_count = count( array_filter( $items, fn( $i ) => $i['is_installed'] && $i['is_active'] ) );
        $total        = count( $items );
        $subtitle     = $active_count > 0
            ? $active_count . ' of ' . $total . ' active'
            : ( $description ?: $total . ' checked' );
        ?>
        <div class="metasync-accordion-section" data-section="<?php echo esc_attr( $section_key ); ?>">
            <div class="metasync-accordion-header" role="button" tabindex="0" aria-expanded="false" aria-controls="<?php echo esc_attr( $section_id ); ?>">
                <div class="metasync-accordion-title">
                    <span class="metasync-accordion-icon">
                        <span class="dashicons dashicons-<?php echo esc_attr( $dashicon ); ?>"></span>
                    </span>
                    <div class="metasync-accordion-text">
                        <h3><?php echo wp_kses_post( $title ); ?></h3>
                        <p class="metasync-accordion-description"><?php echo esc_html( $subtitle ); ?></p>
                    </div>
                </div>
                <button type="button" class="metasync-accordion-toggle" aria-label="Toggle section">
                    <span class="toggle-icon">▼</span>
                </button>
            </div>
            <div class="metasync-accordion-content msrp-compat-accordion" id="<?php echo esc_attr( $section_id ); ?>" data-state="closed">
                <div class="msrp-compat-list">
                    <?php foreach ( $items as $item ):
                        $is_active    = $item['is_installed'] && $item['is_active'];
                        $is_installed = $item['is_installed'] && ! $item['is_active'];
                        $indicator    = $is_active ? 'active' : ( $is_installed ? 'installed' : 'none' );
                    ?>
                    <div class="msrp-compat-row msrp-indicator-<?php echo esc_attr( $indicator ); ?>">
                        <div class="msrp-compat-row-left">
                            <span class="msrp-compat-name"><?php echo esc_html( $item['name'] ); ?></span>
                            <?php if ( ! empty( $item['note'] ) ): ?>
                                <span class="msrp-compat-note"><?php echo esc_html( $item['note'] ); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="msrp-compat-row-right">
                            <?php if ( $is_active ): ?>
                                <span class="msrp-pill msrp-pill-active">Active</span>
                            <?php elseif ( $is_installed ): ?>
                                <span class="msrp-pill msrp-pill-installed">Installed</span>
                            <?php else: ?>
                                <span class="msrp-pill msrp-pill-none">Not installed</span>
                            <?php endif; ?>
                            <span class="msrp-pill msrp-pill-supported"><?php echo esc_html( $item['status_text'] ); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Troubleshooting section — rendered separately below the host test card.
     */
    public function render_troubleshooting_section(): void
    {
        $plugin_name = Metasync::get_effective_plugin_name();
        $otto_name   = Metasync::get_whitelabel_otto_name();
        $issues = [
            [
                'title' => 'Title tag disappears when Yoast SEO is active',
                'cause' => 'Yoast SEO takes ownership of the <title> tag. ' . $plugin_name . ' filters may conflict if both try to modify the document title at the same priority.',
                'fix'   => 'Go to ' . $plugin_name . ' → Settings → Compatibility and make sure "Yoast SEO title handoff" is enabled. This lets Yoast own the title while ' . $plugin_name . ' passes its optimised title through Yoast.',
            ],
            [
                'title' => $otto_name . ' changes not appearing after cache flush',
                'cause' => 'Some cache plugins (WP Rocket, Kinsta) serve stale HTML even after a purge if cache warm-up races with the write.',
                'fix'   => 'Increase the cache warm-up timeout in ' . $plugin_name . ' → Settings → Advanced, or disable "Immediate Warm-up" and let the cache rebuild organically.',
            ],
            [
                'title' => 'SEO meta not showing for Oxygen Builder pages',
                'cause' => 'Oxygen replaces the standard WordPress template, bypassing some meta output hooks.',
                'fix'   => $plugin_name . ' automatically handles Oxygen compatibility — no setting required. If meta is still missing, ensure ' . $plugin_name . ' is up to date and try clearing any server-side or CDN cache.',
            ],
            [
                'title' => 'Schema markup injected on every page instead of the target page only',
                'cause' => 'A known bug in versions prior to 2.5.20 where schema was registered globally.',
                'fix'   => 'Update to the latest version. Go to Settings → Schema Markup and re-save your configuration.',
            ],
            [
                'title' => 'Sitemap returns 404',
                'cause' => 'Permalink flush needed after activation or after a WordPress update.',
                'fix'   => 'Go to Settings → Permalinks in WordPress admin and click "Save Changes" (no actual change needed — this flushes rewrite rules).',
            ],
            [
                'title' => 'Cloudflare / CDN caching stale SEO meta',
                'cause' => 'CDN caches the full HTML response including meta tags. After updating SEO meta, the CDN may serve old content.',
                'fix'   => 'Purge the CDN cache for the affected URL via your CDN dashboard, or enable automatic purge integration in ' . $plugin_name . ' → Settings → Cache.',
            ],
        ];
        ?>
        <div id="ms-comp-troubleshooting" class="dashboard-card" style="margin-top: 20px;">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                <span class="dashicons dashicons-sos" style="font-size:24px;width:24px;height:24px;color:var(--dashboard-accent);"></span>
                <div>
                    <h2 style="margin:0;">Troubleshooting</h2>
                    <p style="color:var(--dashboard-text-secondary); margin:4px 0 0 0;">Common issues and how to fix them.</p>
                </div>
            </div>
            <div style="margin-top:16px; display:flex; flex-direction:column; gap:8px;">
                <?php foreach ( $issues as $i => $issue ): ?>
                    <details style="border:1px solid var(--dashboard-border); border-radius:8px; overflow:hidden;">
                        <summary style="cursor:pointer; padding:14px 16px; list-style:none; display:flex; align-items:center; justify-content:space-between; gap:12px; background:var(--dashboard-bg); font-weight:600; font-size:14px; color:var(--dashboard-text-primary);">
                            <span style="display:flex; align-items:center; gap:10px;">
                                <span class="dashicons dashicons-warning" style="font-size:16px;width:16px;height:16px;color:#f59e0b;flex-shrink:0;"></span>
                                <?php echo esc_html( $issue['title'] ); ?>
                            </span>
                            <span class="dashicons dashicons-arrow-down-alt2" style="font-size:14px;width:14px;height:14px;color:var(--dashboard-text-secondary);flex-shrink:0;transition:transform .2s;"></span>
                        </summary>
                        <div style="padding:16px; background:var(--dashboard-card-bg); border-top:1px solid var(--dashboard-border);">
                            <p style="margin:0 0 10px 0; color:var(--dashboard-text-secondary); font-size:13px;">
                                <strong style="color:var(--dashboard-text-primary);">Cause:</strong> <?php echo esc_html( $issue['cause'] ); ?>
                            </p>
                            <p style="margin:0; color:var(--dashboard-text-secondary); font-size:13px;">
                                <strong style="color:var(--dashboard-text-primary);">Fix:</strong> <?php echo esc_html( $issue['fix'] ); ?>
                            </p>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
        <script>
        document.querySelectorAll('#ms-comp-troubleshooting details').forEach(function(el) {
            el.addEventListener('toggle', function() {
                var arrow = el.querySelector('.dashicons-arrow-down-alt2');
                if (arrow) arrow.style.transform = el.open ? 'rotate(180deg)' : '';
            });
        });
        </script>
        <?php
    }

    /**
     * Render Lock Section button for protected tabs.
     *
     * @param string $tab The tab identifier (general, whitelabel, advanced)
     */
    public function render_lock_button($tab)
    {
        ?>
        <button type="button"
                class="metasync-lock-btn"
                data-tab="<?php echo esc_attr($tab); ?>"
                style="background: #dc3545; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s ease;"
                onmouseover="this.style.background='#c82333'"
                onmouseout="this.style.background='#dc3545'"
                onfocus="this.style.background='#c82333'"
                onblur="this.style.background='#dc3545'"
                aria-label="Lock this section and require password for access">
            <span class="dashicons dashicons-lock" style="font-size:16px;width:16px;height:16px;" aria-hidden="true"></span>
            Lock Section
        </button>
        <?php
    }

    public function get_page_builders_compatibility()
    {
        $builders = [
            'elementor' => [
                'name' => 'Elementor',
                'plugin_files' => [ 'elementor/elementor.php', 'elementor-pro/elementor-pro.php' ],
                'supported' => true,
                'version'   => '3.31.5',
            ],
            'gutenberg' => [
                'name'         => 'WordPress Block Editor',
                'plugin_files' => [ 'gutenberg/gutenberg.php' ],
                'supported'    => true,
                'is_core'      => true,
                'version'      => get_bloginfo( 'version' ),
            ],
            'divi' => [
                'name'         => 'Divi Builder',
                'plugin_files' => [ 'divi-builder/divi-builder.php' ],
                'theme_name'   => 'Divi',
                'supported'    => true,
                'version'      => '',
            ],
            'oxygen' => [
                'name'         => 'Oxygen Builder',
                'plugin_files' => [ 'oxygen/functions.php' ],
                'supported'    => true,
                'version'      => '4.9',
                'note'         => 'Enable Oxygen Compatibility in Settings for correct meta output.',
            ],
            'bricks' => [
                'name'         => 'Bricks Builder',
                'plugin_files' => [ 'bricks/bricks.php' ],
                'supported'    => true,
                'version'      => '1.11',
            ],
        ];

        $result = [];

        foreach ($builders as $key => $builder) {
            $is_core = isset($builder['is_core']) && $builder['is_core'];
            $theme_name = isset($builder['theme_name']) ? $builder['theme_name'] : null;
            $plugin_status = $this->get_plugin_status($builder['plugin_files'], $is_core, $theme_name);

            if ($builder['supported']) {
                $status = 'supported';
                $status_text = 'Supported';
            } else {
                $status = 'coming-soon';
                $status_text = 'Coming Soon';
            }

            $result[] = [
                'name'         => $builder['name'],
                'version'      => $builder['version'],
                'status'       => $status,
                'status_text'  => $status_text,
                'is_installed' => $plugin_status['is_installed'],
                'is_active'    => $plugin_status['is_active'],
                'note'         => $builder['note'] ?? '',
                'logo'         => $this->get_plugin_logo( $key, 'page_builder' ),
            ];
        }

        return $result;
    }

    public function get_seo_plugins_compatibility()
    {
        $plugins = [
            'yoast' => [
                'name' => 'Yoast SEO',
                'plugin_files' => [
                    'wordpress-seo/wp-seo.php',
                    'wordpress-seo-premium/wp-seo-premium.php'
                ],
                'supported' => true,
                'version' => '25.9.0'
            ],
            'rankmath' => [
                'name' => 'Rank Math',
                'plugin_files' => [
                    'seo-by-rank-math/rank-math.php',
                    'seo-by-rank-math-pro/rank-math-pro.php'
                ],
                'supported' => true,
                'version' => '1.0.253.0'
            ],
            'aioseo' => [
                'name' => 'All in One SEO',
                'plugin_files' => [
                    'all-in-one-seo-pack/all_in_one_seo_pack.php',
                    'all-in-one-seo-pack-pro/all_in_one_seo_pack.php'
                ],
                'supported' => true,
                'version' => '4.8.7'
            ]
        ];

        $result = [];

        foreach ($plugins as $key => $plugin) {
            $plugin_status = $this->get_plugin_status($plugin['plugin_files']);

            if ($plugin['supported']) {
                $status = 'supported';
                $status_text = 'Supported';
            } else {
                $status = 'coming-soon';
                $status_text = 'Coming Soon';
            }

            $result[] = [
                'name'         => $plugin['name'],
                'version'      => $plugin['version'],
                'status'       => $status,
                'status_text'  => $status_text,
                'is_installed' => $plugin_status['is_installed'],
                'is_active'    => $plugin_status['is_active'],
                'note'         => $plugin['note'] ?? '',
                'logo'         => $this->get_plugin_logo( $key, 'seo' ),
            ];
        }

        return $result;
    }

    public function get_themes_compatibility()
    {
        $themes = [
            'astra' => [
                'name'       => 'Astra',
                'theme_name' => 'Astra',
                'plugin_files' => [],
                'supported'  => true,
                'version'    => '4.8',
                'note'       => 'Full compatibility including title tag and schema support.',
            ],
            'generatepress' => [
                'name'       => 'GeneratePress',
                'theme_name' => 'GeneratePress',
                'plugin_files' => [],
                'supported'  => true,
                'version'    => '3.5',
            ],
            'oceanwp' => [
                'name'       => 'OceanWP',
                'theme_name' => 'OceanWP',
                'plugin_files' => [],
                'supported'  => true,
                'version'    => '3.5',
            ],
            'hello-elementor' => [
                'name'       => 'Hello Elementor',
                'theme_name' => 'Hello Elementor',
                'plugin_files' => [],
                'supported'  => true,
                'version'    => '3.1',
            ],
            'kadence' => [
                'name'       => 'Kadence',
                'theme_name' => 'Kadence',
                'plugin_files' => [],
                'supported'  => true,
                'version'    => '1.2',
            ],
        ];

        $result = [];
        foreach ( $themes as $key => $theme ) {
            $status = $this->get_plugin_status( $theme['plugin_files'], false, $theme['theme_name'] );
            $result[] = [
                'name'         => $theme['name'],
                'version'      => $theme['version'],
                'status'       => 'supported',
                'status_text'  => 'Supported',
                'is_installed' => $status['is_installed'],
                'is_active'    => $status['is_active'],
                'note'         => $theme['note'] ?? '',
                'logo'         => $this->get_plugin_logo( $key, 'theme' ),
            ];
        }
        return $result;
    }

    public function get_cache_plugins_compatibility()
    {
        $plugins = [
            'wp-rocket' => [
                'name'         => 'WP Rocket',
                'plugin_files' => [ 'wp-rocket/wp-rocket.php' ],
                'supported'    => true,
                'version'      => '3.16',
                'note'         => 'Automatic cache purge on OTTO update.',
            ],
            'litespeed-cache' => [
                'name'         => 'LiteSpeed Cache',
                'plugin_files' => [ 'litespeed-cache/litespeed-cache.php' ],
                'supported'    => true,
                'version'      => '7.5.0.1',
            ],
            'w3-total-cache' => [
                'name'         => 'W3 Total Cache',
                'plugin_files' => [ 'w3-total-cache/w3-total-cache.php' ],
                'supported'    => true,
                'version'      => '2.7',
            ],
            'sg-cachepress' => [
                'name'         => 'SiteGround Optimizer',
                'plugin_files' => [ 'sg-cachepress/sg-cachepress.php' ],
                'supported'    => true,
                'version'      => '7.6',
            ],
            'hummingbird-performance' => [
                'name'         => 'Hummingbird',
                'plugin_files' => [ 'hummingbird-performance/wp-hummingbird.php' ],
                'supported'    => true,
                'version'      => '3.9',
            ],
            'autoptimize' => [
                'name'         => 'Autoptimize',
                'plugin_files' => [ 'autoptimize/autoptimize.php' ],
                'supported'    => true,
                'version'      => '3.1',
            ],
            'comet-cache' => [
                'name'         => 'Comet Cache',
                'plugin_files' => [ 'comet-cache/comet-cache.php' ],
                'supported'    => true,
                'version'      => '17.6',
            ],
            'cache-enabler' => [
                'name'         => 'Cache Enabler',
                'plugin_files' => [ 'cache-enabler/cache-enabler.php' ],
                'supported'    => true,
                'version'      => '1.8',
            ],
            'breeze' => [
                'name'         => 'Breeze (Cloudways)',
                'plugin_files' => [ 'breeze/breeze.php' ],
                'supported'    => true,
                'version'      => '2.1',
            ],
        ];

        $result = [];

        foreach ($plugins as $key => $plugin) {
            $plugin_status = $this->get_plugin_status($plugin['plugin_files']);

            if ($plugin['supported']) {
                $status = 'supported';
                $status_text = 'Supported';
            } else {
                $status = 'coming-soon';
                $status_text = 'Coming Soon';
            }

            $result[] = [
                'name'         => $plugin['name'],
                'version'      => $plugin['version'],
                'status'       => $status,
                'status_text'  => $status_text,
                'is_installed' => $plugin_status['is_installed'],
                'is_active'    => $plugin_status['is_active'],
                'note'         => $plugin['note'] ?? '',
                'logo'         => $this->get_plugin_logo( $key, 'cache' ),
            ];
        }

        return $result;
    }

    public function get_cdn_compatibility()
    {
        $providers = [
            'cloudflare' => [
                'name'         => 'Cloudflare',
                'plugin_files' => [ 'cloudflare/cloudflare.php' ],
                'supported'    => true,
                'version'      => '',
                'note'         => 'Cache purge supported. Ensure HTML caching is disabled or bypass rules set for admin.',
            ],
            'fastly' => [
                'name'         => 'Fastly',
                'plugin_files' => [ 'fastly/fastly.php' ],
                'supported'    => true,
                'version'      => '',
                'note'         => 'Edge cache purge triggered on OTTO update.',
            ],
            'bunnycdn' => [
                'name'         => 'Bunny CDN',
                'plugin_files' => [ 'bunnycdn/bunnycdn.php', 'bunny-cdn/bunny-cdn.php' ],
                'supported'    => true,
                'version'      => '',
            ],
            'kinsta-mu-plugins' => [
                'name'         => 'Kinsta (Managed Hosting)',
                'plugin_files' => [ 'kinsta-mu-plugins/kinsta-mu-plugins.php' ],
                'supported'    => true,
                'version'      => '',
                'note'         => 'Kinsta Varnish/Nginx cache purged per-URL on OTTO update.',
            ],
            'flywheel' => [
                'name'         => 'Flywheel / Local',
                'plugin_files' => [ 'flywheel-common/plugin.php' ],
                'supported'    => true,
                'version'      => '',
                'note'         => 'Varnish cache purge supported.',
            ],
        ];

        $result = [];
        foreach ( $providers as $key => $provider ) {
            $status = $this->get_plugin_status( $provider['plugin_files'] );
            $result[] = [
                'name'         => $provider['name'],
                'version'      => $provider['version'],
                'status'       => 'supported',
                'status_text'  => 'Supported',
                'is_installed' => $status['is_installed'],
                'is_active'    => $status['is_active'],
                'note'         => $provider['note'] ?? '',
                'logo'         => $this->get_plugin_logo( $key, 'cdn' ),
            ];
        }
        return $result;
    }

    /**
     * @deprecated Use get_plugin_status() instead
     */
    public function is_plugin_installed($plugin_file)
    {
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        return is_plugin_active($plugin_file);
    }

    /**
     * Get detailed plugin status (installed and/or active).
     *
     * @param array  $plugin_files Array of plugin file paths to check
     * @param bool   $is_core      Whether this is a WordPress core feature
     * @param string $theme_name   Optional theme name to check
     * @return array ['is_installed' => bool, 'is_active' => bool, 'active_version' => string|null]
     */
    public function get_plugin_status($plugin_files, $is_core = false, $theme_name = null)
    {
        if ($is_core) {
            return [
                'is_installed' => true,
                'is_active' => true,
                'active_version' => 'core'
            ];
        }

        if ($theme_name) {
            $current_theme = wp_get_theme();
            $is_theme_active = (strtolower($current_theme->get('Name')) === strtolower($theme_name) ||
                               strtolower($current_theme->get_stylesheet()) === strtolower($theme_name));

            $theme_exists = wp_get_theme($theme_name)->exists();

            if ($theme_exists) {
                return [
                    'is_installed' => true,
                    'is_active' => $is_theme_active,
                    'active_version' => $is_theme_active ? 'theme' : null
                ];
            }
        }

        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        if (!function_exists('get_plugins')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        if (!is_array($plugin_files)) {
            $plugin_files = [$plugin_files];
        }

        $is_installed = false;
        $is_active = false;
        $active_version = null;

        foreach ($plugin_files as $plugin_file) {
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
            if (file_exists($plugin_path)) {
                $is_installed = true;

                if (is_plugin_active($plugin_file)) {
                    $is_active = true;
                    $active_version = strpos($plugin_file, 'pro') !== false ||
                                     strpos($plugin_file, 'premium') !== false ?
                                     'premium' : 'free';
                    break;
                }
            }
        }

        return [
            'is_installed' => $is_installed,
            'is_active' => $is_active,
            'active_version' => $active_version
        ];
    }

    public function get_plugin_logo($plugin_key, $type)
    {
        $logos = [
            'page_builder' => [
                'elementor' => 'https://ps.w.org/elementor/assets/icon-256x256.gif',
                'gutenberg' => 'https://i0.wp.com/wordpress.org/files/2023/02/wmark.png',
                'divi'      => 'https://www.elegantthemes.com/images/logo.svg',
                'oxygen'    => 'https://oxygenbuilder.com/wp-content/uploads/2021/09/oxygen-logo-icon.png',
                'bricks'    => 'https://bricksbuilder.io/wp-content/uploads/2021/05/bricks-icon.svg',
            ],
            'theme' => [
                'astra'           => 'https://ps.w.org/astra/assets/icon-128x128.png',
                'generatepress'   => 'https://ps.w.org/generatepress/assets/icon-128x128.png',
                'oceanwp'         => 'https://ps.w.org/ocean-extra/assets/icon-128x128.png',
                'hello-elementor' => 'https://ps.w.org/hello-elementor/assets/icon-128x128.png',
                'kadence'         => 'https://ps.w.org/kadence-blocks/assets/icon-128x128.png',
            ],
            'seo' => [
                'yoast'   => 'https://ps.w.org/wordpress-seo/assets/icon-128x128.gif',
                'rankmath' => 'https://ps.w.org/seo-by-rank-math/assets/icon-128x128.png',
                'aioseo'  => 'https://ps.w.org/all-in-one-seo-pack/assets/icon-128x128.png',
            ],
            'cache' => [
                'wp-rocket'               => 'https://ps.w.org/rocket-lazy-load/assets/icon-128x128.png',
                'litespeed-cache'         => 'https://ps.w.org/litespeed-cache/assets/icon-128x128.png',
                'w3-total-cache'          => 'https://ps.w.org/w3-total-cache/assets/icon-128x128.png',
                'sg-cachepress'           => 'https://ps.w.org/sg-cachepress/assets/icon-128x128.png',
                'hummingbird-performance' => 'https://ps.w.org/hummingbird-performance/assets/icon-128x128.png',
                'autoptimize'             => 'https://ps.w.org/autoptimize/assets/icon-128x128.png',
            ],
            'cdn' => [
                'cloudflare' => 'https://ps.w.org/cloudflare/assets/icon-128x128.png',
                'fastly'     => 'https://ps.w.org/purgely/assets/icon-128x128.png',
            ],
        ];

        return isset($logos[$type][$plugin_key]) ? $logos[$type][$plugin_key] : '';
    }
}

/* global metasyncExcludedUrlsData, jQuery */
/**
 * MetaSync OTTO Excluded URLs Management
 *
 * Extracted for Phase 5, #887.
 * Handles adding, listing, rechecking, and deleting excluded URLs.
 *
 * Localized object: metasyncExcludedUrlsData
 *   - ajaxUrl (string)
 *   - nonce   (string)
 *
 * @since Phase 5
 */
(function () {
    if (typeof jQuery === 'undefined') {
        setTimeout(arguments.callee, 100);
        return;
    }

    jQuery(document).ready(function ($) {
        var currentPage = 1;
        var perPage = 10;
        var ajaxUrl = metasyncExcludedUrlsData.ajaxUrl;
        var nonce = metasyncExcludedUrlsData.nonce;

        // Load excluded URLs on page load
        loadExcludedURLs();

        // Add excluded URL button click
        $('#otto-add-excluded-url-btn').on('click', function (e) {
            e.preventDefault();
            addExcludedURL();
        });

        // Handle Enter key in URL pattern input
        $('#otto-url-pattern').on('keypress', function (e) {
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

            // Validate inputs
            if (!urlPattern) {
                showStatus('error', 'URL pattern is required');
                return;
            }

            // Disable button and show loading
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
                success: function (response) {
                    if (response.success) {
                        showStatus('success', response.data.message || 'URL excluded successfully');
                        // Clear form
                        $('#otto-url-pattern').val('');
                        $('#otto-description').val('');
                        $('#otto-pattern-type').val('exact');
                        // Reload list
                        loadExcludedURLs();
                    } else {
                        showStatus('error', response.data.message || 'Failed to add excluded URL');
                    }
                },
                error: function () {
                    showStatus('error', 'An error occurred. Please try again.');
                },
                complete: function () {
                    $button.prop('disabled', false).text('\u2795 Add Excluded URL');
                }
            });
        }

        function loadExcludedURLs(page) {
            page = page || currentPage;
            var $container = $('#otto-excluded-urls-table-container');

            $container.html('<div style="text-align: center; padding: 40px; color: #6c757d;"><p>Loading...</p></div>');

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'metasync_otto_get_excluded_urls',
                    nonce: nonce,
                    page: page,
                    per_page: perPage
                },
                success: function (response) {
                    if (response.success) {
                        currentPage = page;
                        renderExcludedURLsTable(response.data.records, response.data.pagination);
                    } else {
                        $container.html('<div style="text-align: center; padding: 40px; color: #dc3545;"><p>Error loading excluded URLs</p></div>');
                    }
                },
                error: function () {
                    $container.html('<div style="text-align: center; padding: 40px; color: #dc3545;"><p>Failed to load excluded URLs</p></div>');
                }
            });
        }

        function renderExcludedURLsTable(records, pagination) {
            var $container = $('#otto-excluded-urls-table-container');

            if (!records || records.length === 0) {
                $container.html('<div style="text-align: center; padding: 40px; color: #6c757d;"><p>No excluded URLs found. Add one above to get started.</p></div>');
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

            records.forEach(function (record) {
                html += '<tr>';
                html += '<td><code>' + escapeHtml(record.url_pattern) + '</code></td>';
                html += '<td><span class="otto-pattern-type-badge otto-pattern-' + record.pattern_type + '">' + formatPatternType(record.pattern_type) + '</span></td>';
                html += '<td>' + (record.description ? escapeHtml(record.description) : '<span style="color: #999;">\u2014</span>') + '</td>';
                html += '<td>' + formatRecheckAfter(record) + '</td>';
                html += '<td><span class="otto-actions">';
                html += '<button type="button" class="button button-small otto-recheck-url" data-id="' + record.id + '" style="margin-right: 5px;">\uD83D\uDD04 Recheck</button>';
                html += '<button type="button" class="button button-small otto-delete-url" data-id="' + record.id + '" style="color: #dc3545;">\uD83D\uDDD1\uFE0F Delete</button>';
                html += '</span></td>';
                html += '</tr>';
            });

            html += '</tbody></table>';

            // Add pagination
            if (pagination.total_pages > 1) {
                html += '<div class="tablenav" style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">';
                html += '<div class="tablenav-pages">';
                html += '<span class="displaying-num">' + pagination.total_count + ' items</span>';

                html += '<span class="pagination-links">';

                // First page
                if (pagination.current_page > 1) {
                    html += '<a class="button otto-page-nav" data-page="1" href="#">\u00AB</a> ';
                    html += '<a class="button otto-page-nav" data-page="' + (pagination.current_page - 1) + '" href="#">\u2039</a> ';
                } else {
                    html += '<span class="button disabled">\u00AB</span> ';
                    html += '<span class="button disabled">\u2039</span> ';
                }

                html += '<span class="paging-input">';
                html += '<span class="tablenav-paging-text">' + pagination.current_page + ' of <span class="total-pages">' + pagination.total_pages + '</span></span>';
                html += '</span> ';

                // Next/Last page
                if (pagination.current_page < pagination.total_pages) {
                    html += '<a class="button otto-page-nav" data-page="' + (pagination.current_page + 1) + '" href="#">\u203A</a> ';
                    html += '<a class="button otto-page-nav" data-page="' + pagination.total_pages + '" href="#">\u00BB</a>';
                } else {
                    html += '<span class="button disabled">\u203A</span> ';
                    html += '<span class="button disabled">\u00BB</span>';
                }

                html += '</span></div></div>';
            }

            $container.html(html);

            // Attach delete button handlers
            $('.otto-delete-url').on('click', function (e) {
                e.preventDefault();
                var id = $(this).data('id');
                deleteExcludedURL(id);
            });

            // Attach recheck button handlers
            $('.otto-recheck-url').on('click', function (e) {
                e.preventDefault();
                var $btn = $(this);
                var id = $btn.data('id');
                recheckExcludedURL(id, $btn);
            });

            // Attach pagination handlers
            $('.otto-page-nav').on('click', function (e) {
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
                success: function (response) {
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
                error: function (xhr, status, err) {
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
                success: function (response) {
                    if (response.success) {
                        loadExcludedURLs();
                    } else {
                        alert('Error: ' + (response.data.message || 'Failed to delete'));
                    }
                },
                error: function () {
                    alert('An error occurred. Please try again.');
                }
            });
        }

        function showStatus(type, message) {
            var $status = $('#otto-add-status');
            $status.removeClass('otto-status-success otto-status-error');
            $status.addClass('otto-status-' + type);
            $status.text(message).fadeIn();

            setTimeout(function () {
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
            var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
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
            return String(text).replace(/[&<>"']/g, function (m) { return map[m]; });
        }
    });
})();

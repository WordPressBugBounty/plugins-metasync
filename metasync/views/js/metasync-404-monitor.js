/**
 * MetaSync 404 monitor — filter auto-submit and bulk action confirm.
 *
 * Extracted from views/metasync-404-monitor.php (Phase 5, #887).
 */
document.addEventListener('DOMContentLoaded', function() {
    // Auto-submit filters when changed
    var filterInputs = document.querySelectorAll('#date-from-filter, #date-to-filter, #min-hits-filter');
    filterInputs.forEach(function(input) {
        input.addEventListener('change', function() {
            document.getElementById('404-monitor-form').submit();
        });
    });

    // Add confirmation for bulk actions
    var bulkActionForm = document.getElementById('404-monitor-form');
    if (bulkActionForm) {
        bulkActionForm.addEventListener('submit', function(e) {
            var actionSelect = document.getElementById('bulk-action-selector-top');
            if (actionSelect.value === 'empty') {
                if (!confirm('Are you sure you want to empty all 404 error logs? This action cannot be undone.')) {
                    e.preventDefault();
                }
            } else if (actionSelect.value === 'delete_bulk') {
                var checkedBoxes = document.querySelectorAll('input[name="item[]"]:checked');
                if (checkedBoxes.length > 0) {
                    if (!confirm('Are you sure you want to delete the selected 404 errors?')) {
                        e.preventDefault();
                    }
                }
            }
        });
    }

    // Add tab parameter to all pagination links in 404-monitor tab
    function addTabToPaginationLinks() {
        var urlParams = new URLSearchParams(window.location.search);
        var currentTab = urlParams.get('tab') || 'redirections';

        // Find all pagination links within 404-monitor-content
        var monitorContent = document.getElementById('404-monitor-content');
        if (monitorContent) {
            var paginationLinks = monitorContent.querySelectorAll('.tablenav-pages a');
            paginationLinks.forEach(function(link) {
                var url = new URL(link.href);
                url.searchParams.set('tab', '404-monitor');
                link.href = url.toString();
            });
        }
    }

    // Run immediately and after a short delay
    addTabToPaginationLinks();
    setTimeout(addTabToPaginationLinks, 100);
    setTimeout(addTabToPaginationLinks, 500);
});

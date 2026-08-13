/**
 * MetaSync 404 monitor — bulk action confirm and pagination tab parameter.
 *
 * Extracted from views/metasync-404-monitor.php (Phase 5, #887).
 */
document.addEventListener('DOMContentLoaded', function() {
    // Add confirmation for bulk actions
    var bulkActionForm = document.getElementById('404-monitor-form');
    if (bulkActionForm) {
        bulkActionForm.addEventListener('submit', function(e) {
            var submitterId = e.submitter ? e.submitter.id : '';
            if (submitterId === 'monitor-filter-submit' || submitterId === 'monitor-search-submit') {
                if (window.metasyncNavigateListState) {
                    e.preventDefault();
                    window.metasyncNavigateListState(bulkActionForm, '404_monitor');
                }
                return;
            }

            // Mirror the PHP side: the top dropdown posts `action`, the bottom
            // one posts `action2`. Reading only the top select meant a bulk
            // action chosen from the BOTTOM bar ran with no confirmation at
            // all — including "Empty Table", which is irreversible.
            var topSelect = document.getElementById('bulk-action-selector-top');
            var bottomSelect = document.getElementById('bulk-action-selector-bottom');
            var action = '-1';

            if (topSelect && topSelect.value !== '-1' && topSelect.value !== '') {
                action = topSelect.value;
            } else if (bottomSelect && bottomSelect.value !== '-1' && bottomSelect.value !== '') {
                action = bottomSelect.value;
            }

            if (action === 'empty') {
                if (!confirm('Are you sure you want to empty all 404 error logs? This action cannot be undone.')) {
                    e.preventDefault();
                }
            } else if (action === 'delete_bulk') {
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

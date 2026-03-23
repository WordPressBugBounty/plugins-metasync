/**
 * MetaSync redirections — filter auto-submit and pagination tab fix.
 *
 * Extracted from views/metasync-redirection.php (Phase 5, #887).
 */
document.addEventListener('DOMContentLoaded', function() {
    // Auto-submit filters when changed
    var filterSelects = document.querySelectorAll('#status-filter, #pattern-filter, #http-code-filter');
    var form = document.getElementById('redirection-form');
    filterSelects.forEach(function(select) {
        select.addEventListener('change', function() {
            var formAction = form.getAttribute('action') || window.location.href;
            var url = new URL(formAction, window.location.origin);
            url.searchParams.delete('paged_redir');
            form.setAttribute('action', url.pathname + url.search);

            // Also add hidden field to ensure pagination resets
            var pagedInput = form.querySelector('input[name="paged_redir"]');
            if (!pagedInput) {
                pagedInput = document.createElement('input');
                pagedInput.type = 'hidden';
                pagedInput.name = 'paged_redir';
                form.appendChild(pagedInput);
            }
            pagedInput.value = '1';

            form.submit();
        });
    });

    // Add tab parameter to all pagination links in redirections tab
    function addTabToPaginationLinks() {
        // Find all pagination links within redirections-content
        var redirectionsContent = document.getElementById('redirections-content');
        if (redirectionsContent) {
            var paginationLinks = redirectionsContent.querySelectorAll('.tablenav-pages a');
            paginationLinks.forEach(function(link) {
                var url = new URL(link.href);
                url.searchParams.set('tab', 'redirections');
                link.href = url.toString();
            });
        }
    }

    // Run immediately and after a short delay
    addTabToPaginationLinks();
    setTimeout(addTabToPaginationLinks, 100);
    setTimeout(addTabToPaginationLinks, 500);
});

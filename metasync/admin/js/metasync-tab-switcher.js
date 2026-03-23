/**
 * MetaSync tab switcher for redirections / 404-monitor page.
 *
 * Extracted from admin/class-metasync-admin.php (Phase 5, #887).
 */
jQuery(document).ready(function($) {
    // Function to switch to a specific tab
    function switchToTab(targetTab) {
        // Update active tab
        $('.metasync-tab-nav a').removeClass('active');
        $('.metasync-tab-nav a[data-tab="' + targetTab + '"]').addClass('active');

        // Show target content
        $('.metasync-tab-content').removeClass('active');
        $('#' + targetTab + '-content').addClass('active');
    }

    // Initialize tabs immediately
    function initializeTabs() {
        // Check URL parameter on page load
        var urlParams = new URLSearchParams(window.location.search);
        var currentTab = urlParams.get('tab');

        // Always ensure a tab is active
        if (currentTab && (currentTab === 'redirections' || currentTab === '404-monitor')) {
            // Switch to the tab specified in URL
            switchToTab(currentTab);
        } else {
            // No tab parameter or invalid tab, ensure redirections is active
            switchToTab('redirections');
            currentTab = 'redirections';
        }

        // Clean up irrelevant pagination parameters based on active tab
        var needsCleanup = false;
        if (currentTab === '404-monitor') {
            if (urlParams.has('paged') || urlParams.has('paged_redir')) {
                urlParams.delete('paged');
                urlParams.delete('paged_redir');
                needsCleanup = true;
            }
        } else if (currentTab === 'redirections') {
            if (urlParams.has('paged') || urlParams.has('paged_404')) {
                urlParams.delete('paged');
                urlParams.delete('paged_404');
                needsCleanup = true;
            }
        }

        // Update URL if cleanup was needed
        if (needsCleanup) {
            var newUrl = window.location.pathname + '?' + urlParams.toString();
            window.history.replaceState({}, '', newUrl);
        }
    }

    // Initialize tabs immediately
    initializeTabs();

    // Also initialize after a short delay as backup
    setTimeout(initializeTabs, 100);

    // Handle tab switching on click
    $('.metasync-tab-nav a').on('click', function(e) {
        e.preventDefault();

        var targetTab = $(this).data('tab');
        switchToTab(targetTab);

        // Update URL without page reload and clean up pagination parameters
        var url = new URL(window.location);
        url.searchParams.set('tab', targetTab);

        // Remove all pagination parameters when switching tabs
        url.searchParams.delete('paged');
        url.searchParams.delete('paged_404');
        url.searchParams.delete('paged_redir');

        window.history.pushState({}, '', url);
    });
});

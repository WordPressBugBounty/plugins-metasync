/**
 * MetaSync iframe height auto-resize.
 *
 * Extracted from admin/class-metasync-admin.php (Phase 5, #887).
 */
function adjustIframeHeight(iframe) {
    var attempts = 0;
    var maxAttempts = 20; // Try for up to 10 seconds

    function tryAdjustHeight() {
        try {
            attempts++;

            // Try to access iframe content height
            var iframeDocument = iframe.contentDocument || iframe.contentWindow.document;
            if (iframeDocument) {
                // Wait for content to load by checking if body has meaningful content
                var body = iframeDocument.body;
                var hasContent = body && (body.children.length > 1 || body.innerText.trim().length > 100);

                if (!hasContent && attempts < maxAttempts) {
                    // Content still loading, try again
                    setTimeout(tryAdjustHeight, 500);
                    return;
                }

                var height = Math.max(
                    body ? body.scrollHeight : 0,
                    body ? body.offsetHeight : 0,
                    iframeDocument.documentElement.clientHeight,
                    iframeDocument.documentElement.scrollHeight,
                    iframeDocument.documentElement.offsetHeight
                );

                // Only apply if we got a reasonable height
                if (height > 600) {
                    iframe.style.height = height + 'px';
                } else if (attempts < maxAttempts) {
                    // Height too small, content probably still loading
                    setTimeout(tryAdjustHeight, 500);
                    return;
                }
            } else {
                // Can't access content, try again or fallback
                if (attempts < maxAttempts) {
                    setTimeout(tryAdjustHeight, 500);
                    return;
                }
            }
        } catch (e) {
            // Cross-origin restrictions - use viewport height
            iframe.style.height = '100vh';
        }
    }

    // Start the height adjustment process
    tryAdjustHeight();
}

// Bind to the dashboard iframe via addEventListener
(function() {
    var iframe = document.getElementById('metasync-dashboard-iframe');
    if (iframe) {
        iframe.addEventListener('load', function() {
            adjustIframeHeight(iframe);
        });
    }

    // Also listen for window resize
    window.addEventListener('resize', function() {
        var iframe = document.getElementById('metasync-dashboard-iframe');
        if (iframe) {
            adjustIframeHeight(iframe);
        }
    });

    // Additional attempt after 3 seconds (for very slow loading apps)
    setTimeout(function() {
        var iframe = document.getElementById('metasync-dashboard-iframe');
        if (iframe) {
            adjustIframeHeight(iframe);
        }
    }, 3000);
})();

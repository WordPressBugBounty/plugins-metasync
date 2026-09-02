/**
 * Keeps WordPress core's admin-menu pinning in sync with the real page height.
 *
 * Core measures #wpwrap once and reuses that figure to decide whether to pin
 * the admin menu. Plugin pages change height after that measurement — tab
 * panels being shown or hidden, list tables loading over AJAX, sections
 * expanding — and core is never told. It then works from a stale height,
 * concludes the page is too short to pin, and lets the menu scroll off-screen
 * on a page that is in fact tall enough.
 *
 * Watching the document for height changes and re-firing the event core
 * already listens for keeps the two in agreement, without having to patch
 * every feature that changes layout.
 *
 * @package Metasync
 */
(function ($) {
    'use strict';

    if (typeof window.ResizeObserver === 'undefined') {
        return;
    }

    $(function () {
        var lastHeight = document.documentElement.scrollHeight;
        var pending = null;

        function notifyCore() {
            var height = document.documentElement.scrollHeight;

            // Ignore sub-pixel churn so the observer cannot feed itself.
            if (Math.abs(height - lastHeight) < 2) {
                return;
            }

            lastHeight = height;
            $(document).trigger('wp-window-resized');
        }

        function schedule() {
            window.clearTimeout(pending);
            pending = window.setTimeout(notifyCore, 100);
        }

        new window.ResizeObserver(schedule).observe(document.body);

        // Late-loading fonts, images and iframes shift the page after ready.
        $(window).on('load', schedule);
    });
})(jQuery);

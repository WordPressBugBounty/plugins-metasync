/**
 * Sitemap Tabs - Client-side tab switching & scrollable checkbox lists
 *
 * @package Metasync
 * @since   1.0.0
 */
(function ($) {
    'use strict';

    var validTabs = ['general', 'news', 'video'];
    var currentActiveTab = 'general';

    /* ---- Tab switching ---- */

    function switchToTab(targetTab) {
        if (validTabs.indexOf(targetTab) === -1) {
            targetTab = 'general';
        }
        currentActiveTab = targetTab;

        $('.metasync-sitemap-tabs .metasync-tab-nav a').removeClass('active');
        $('.metasync-sitemap-tabs .metasync-tab-nav a[data-tab="' + targetTab + '"]').addClass('active');

        $('.metasync-sitemap-tab-content').removeClass('active');
        $('#metasync-sitemap-' + targetTab).addClass('active');
    }

    function getActiveTab() {
        var urlParams = new URLSearchParams(window.location.search);
        var urlTab = urlParams.get('tab');
        if (urlTab && validTabs.indexOf(urlTab) !== -1) {
            return urlTab;
        }
        if (typeof metasyncSitemapTabs !== 'undefined' && metasyncSitemapTabs.activeTab) {
            return metasyncSitemapTabs.activeTab;
        }
        return 'general';
    }

    /* ---- Scrollable checkbox search/filter ---- */

    function initCheckboxSearch() {
        $('.metasync-checkbox-search').on('input', function () {
            var query = $(this).val().toLowerCase();
            var $container = $(this).closest('td');
            var $scrollBox = $container.find('.metasync-checkbox-scroll');
            var $labels = $scrollBox.find('label');
            var $noResults = $scrollBox.find('.metasync-no-results');
            var visible = 0;

            $labels.each(function () {
                var text = $(this).text().toLowerCase();
                var match = text.indexOf(query) !== -1;
                $(this).toggle(match);
                if (match) visible++;
            });

            $noResults.toggle(visible === 0);
        });
    }

    /* ---- Init ---- */

    $(document).ready(function () {
        switchToTab(getActiveTab());

        // Tab click
        $('.metasync-sitemap-tabs .metasync-tab-nav a').on('click', function (e) {
            e.preventDefault();
            var targetTab = $(this).data('tab');
            switchToTab(targetTab);

            var url = new URL(window.location);
            url.searchParams.set('tab', targetTab);
            window.history.pushState({}, '', url);
        });

        // Form redirect tab
        $('.metasync-sitemap-tab-content form').on('submit', function () {
            var $form = $(this);
            $form.find('input[name="redirect_tab"]').remove();
            $form.append('<input type="hidden" name="redirect_tab" value="' + currentActiveTab + '">');
        });

        // Browser back/forward
        $(window).on('popstate', function () {
            var urlParams = new URLSearchParams(window.location.search);
            switchToTab(urlParams.get('tab') || 'general');
        });

        // Init search filters
        initCheckboxSearch();
    });
})(jQuery);

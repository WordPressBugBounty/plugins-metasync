/**
 * Media Optimization Settings Tab Scripts
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.6.0
 *
 * Localized data expected via metasyncMediaOpt:
 *   - resetConfirm: string
 */
(function() {
    'use strict';

    var i18n = window.metasyncMediaOpt || {};

    // Quality range slider live preview
    var qualitySlider = document.getElementById('conversion_quality');
    var qualityValue = document.getElementById('quality-value');
    if (qualitySlider && qualityValue) {
        qualitySlider.addEventListener('input', function() {
            qualityValue.textContent = this.value;
        });
    }

    // Show/hide replace strategy warning
    var strategySelect = document.getElementById('conversion_strategy');
    var replaceWarning = document.getElementById('replace-strategy-warning');
    if (strategySelect && replaceWarning) {
        strategySelect.addEventListener('change', function() {
            replaceWarning.style.display = this.value === 'replace' ? '' : 'none';
        });
    }

    // Toggle section show/hide based on master toggle
    var toggleCheckboxes = document.querySelectorAll('.metasync-toggle input[type="checkbox"]');
    toggleCheckboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            var name = this.name;
            var match = name.match(/\[([^\]]+)\]/);
            if (!match) return;

            var key = match[1];
            var section = document.querySelector('.metasync-toggle-section[data-toggle="' + key + '"]');
            if (section) {
                if (this.checked) {
                    section.style.display = '';
                    section.style.opacity = '0';
                    section.style.maxHeight = '0';
                    section.offsetHeight; // Trigger reflow
                    section.style.opacity = '1';
                    section.style.maxHeight = section.scrollHeight + 'px';
                    setTimeout(function() {
                        section.style.maxHeight = '';
                    }, 300);
                } else {
                    section.style.maxHeight = section.scrollHeight + 'px';
                    section.offsetHeight;
                    section.style.opacity = '0';
                    section.style.maxHeight = '0';
                    setTimeout(function() {
                        section.style.display = 'none';
                        section.style.opacity = '';
                        section.style.maxHeight = '';
                    }, 300);
                }
            }
        });
    });

    // Reset to defaults confirmation
    var resetBtn = document.getElementById('reset-media-settings');
    if (resetBtn) {
        resetBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm(i18n.resetConfirm || 'Are you sure you want to reset all media optimization settings to their defaults? This cannot be undone.')) {
                return;
            }

            var form = document.getElementById('metasync-media-optimization-form');
            var resetInput = document.createElement('input');
            resetInput.type = 'hidden';
            resetInput.name = 'metasync_media_reset';
            resetInput.value = '1';
            form.appendChild(resetInput);
            form.submit();
        });
    }

    // Auto-dismiss success notice
    var successNotice = document.querySelector('.metasync-notice-success');
    if (successNotice) {
        setTimeout(function() {
            successNotice.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            successNotice.style.opacity = '0';
            successNotice.style.transform = 'translateY(-10px)';
            setTimeout(function() {
                successNotice.remove();
            }, 500);
        }, 4000);
    }

    // ── Tab Switching ──
    function switchMediaTab(targetTab) {
        var tabLinks = document.querySelectorAll('.metasync-media-optimization-page .metasync-tab-nav a');
        tabLinks.forEach(function(a) { a.classList.remove('active'); });

        var activeLink = document.querySelector('.metasync-media-optimization-page .metasync-tab-nav a[data-tab="' + targetTab + '"]');
        if (activeLink) activeLink.classList.add('active');

        var contents = document.querySelectorAll('.metasync-media-optimization-page .metasync-tab-content');
        contents.forEach(function(c) { c.classList.remove('active'); });

        var targetContent = document.getElementById(targetTab + '-content');
        if (targetContent) targetContent.classList.add('active');
    }

    // Initialize tab from URL
    var urlParams = new URLSearchParams(window.location.search);
    var currentTab = urlParams.get('tab') || 'settings';
    switchMediaTab(currentTab);

    // Handle tab clicks
    var tabNav = document.querySelectorAll('.metasync-media-optimization-page .metasync-tab-nav a');
    tabNav.forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var tab = this.dataset.tab;
            switchMediaTab(tab);

            var url = new URL(window.location);
            url.searchParams.set('tab', tab);
            url.searchParams.delete('paged');
            url.searchParams.delete('paged_media');
            url.searchParams.delete('s');
            url.searchParams.delete('status_filter');
            window.history.pushState({}, '', url);
        });
    });
})();

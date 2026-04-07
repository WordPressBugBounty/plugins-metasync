/**
 * Code Minification Settings Tab Scripts
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.5.23
 *
 * Localized data expected via metasyncCodeMin:
 *   - ajaxUrl: string
 *   - nonce: string
 *   - purgeConfirm: string
 *   - resetConfirm: string
 */
(function() {
    'use strict';

    var config = window.metasyncCodeMin || {};

    // Toggle section show/hide based on master toggle
    var toggleCheckboxes = document.querySelectorAll('.metasync-code-minification-page .metasync-toggle input[type="checkbox"]');
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
                    setTimeout(function() { section.style.maxHeight = 'none'; }, 300);
                } else {
                    section.style.maxHeight = section.scrollHeight + 'px';
                    section.offsetHeight; // Trigger reflow
                    section.style.opacity = '0';
                    section.style.maxHeight = '0';
                    setTimeout(function() { section.style.display = 'none'; }, 300);
                }
            }
        });
    });

    // Tab switching
    var tabLinks = document.querySelectorAll('.metasync-code-minification-page .metasync-tab-nav a');
    tabLinks.forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var tab = this.getAttribute('data-tab');

            // Update active tab link
            tabLinks.forEach(function(l) { l.classList.remove('active'); });
            this.classList.add('active');

            // Update active tab content
            document.querySelectorAll('.metasync-code-minification-page .metasync-tab-content').forEach(function(content) {
                content.classList.remove('active');
            });
            var targetContent = document.getElementById(tab + '-content');
            if (targetContent) {
                targetContent.classList.add('active');
            }
        });
    });

    // Purge minification cache
    var purgeBtn = document.getElementById('metasync-purge-minification-cache');
    if (purgeBtn) {
        purgeBtn.addEventListener('click', function() {
            if (!confirm(config.purgeConfirm || 'Purge cache?')) return;

            var statusEl = document.getElementById('cache-purge-status');
            purgeBtn.disabled = true;
            if (statusEl) statusEl.textContent = 'Purging...';

            var formData = new FormData();
            formData.append('action', 'metasync_purge_minification_cache');
            formData.append('nonce', config.nonce);

            fetch(config.ajaxUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                purgeBtn.disabled = false;
                if (data.success) {
                    if (statusEl) statusEl.textContent = data.data.message;
                    // Update stats
                    var stats = data.data.stats;
                    if (stats) {
                        updateStatEl('cache-total-files', stats.total_files);
                        updateStatEl('cache-css-files', stats.css_files);
                        updateStatEl('cache-js-files', stats.js_files);
                        updateStatEl('cache-total-size', '0 B');
                    }
                } else {
                    if (statusEl) statusEl.textContent = 'Error: ' + (data.data || 'Unknown error');
                }
            })
            .catch(function() {
                purgeBtn.disabled = false;
                if (statusEl) statusEl.textContent = 'Network error. Please try again.';
            });
        });
    }

    function updateStatEl(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = value;
    }

})();

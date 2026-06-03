/**
 * Media Optimization Image Library Scripts
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.6.0
 *
 * Localized data expected via metasyncMediaLib:
 *   - ajaxUrl:            string
 *   - nonce:              string
 *   - batchRunning:       bool
 *   - i18n.optimizing:    string
 *   - i18n.optimize:      string
 *   - i18n.revert:        string
 *   - i18n.revertConfirm: string
 *   - i18n.optimizeFailed:string
 *   - i18n.revertFailed:  string
 *   - i18n.startBatch:    string
 *   - i18n.batchFailed:   string
 *   - i18n.batchComplete: string
 *   - i18n.imagesProcessed: string
 *   - i18n.failed:        string
 *   - i18n.optimizingOf:  string  (e.g. "Optimizing")
 *   - i18n.of:            string
 *   - i18n.images:        string  (e.g. "images...")
 *   - i18n.unoptimized:   string
 *   - i18n.selectImages:          string
 *   - i18n.bulkFailed:             string
 *   - i18n.apply:                  string
 *   - i18n.unoptimizing:           string
 *   - i18n.unoptimizeConfirm:      string
 *   - i18n.bulkUnoptimizeFailed:   string
 *   - i18n.alreadyUnoptimized:     string
 */
(function() {
    'use strict';

    var config = window.metasyncMediaLib || {};
    var nonce = config.nonce || '';
    var ajaxUrl = config.ajaxUrl || window.ajaxurl;
    var i18n = config.i18n || {};
    var batchActive = false;
    var fallbackPoll = null;

    function sanitizeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function sanitizeTrustedHtml(html) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');
        doc.querySelectorAll('script,iframe,object,embed,form,link[rel="import"]').forEach(function(el) { el.remove(); });
        return doc.body.innerHTML;
    }

    // ── Single Optimize ──
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.metasync-optimize-btn');
        if (!btn) return;

        var id = btn.dataset.id;
        btn.classList.add('loading');
        btn.disabled = true;
        btn.innerHTML = '<span class="metasync-batch-spinner" style="width:14px;height:14px;display:inline-block;"></span> ' + sanitizeHtml(i18n.optimizing || 'Optimizing...');

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=metasync_optimize_single_image&nonce=' + nonce + '&attachment_id=' + encodeURIComponent(id)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var row = btn.closest('tr');
                var statusCell = row.querySelector('.column-status');
                var parser = new DOMParser();
                var statusDoc = parser.parseFromString(data.data.status_html || '', 'text/html');
                statusDoc.querySelectorAll('script,iframe,object,embed,form').forEach(function(el) { el.remove(); });
                while (statusCell.firstChild) { statusCell.removeChild(statusCell.firstChild); }
                Array.prototype.forEach.call(statusDoc.body.childNodes, function(node) {
                    statusCell.appendChild(node.cloneNode(true));
                });

                var actionsCell = row.querySelector('.column-actions');
                if (data.data.can_revert === false) {
                    actionsCell.innerHTML = '<button type="button" class="button button-small metasync-revert-btn" data-id="' + sanitizeHtml(String(id)) + '" disabled title="' + sanitizeHtml(i18n.revertDisabled || 'Original image unavailable — revert is disabled') + '">' +
                        '<span class="dashicons dashicons-undo" style="margin-top:3px;"></span> ' + sanitizeHtml(i18n.revert || 'Revert') + '</button>';
                } else {
                    actionsCell.innerHTML = '<button type="button" class="button button-small metasync-revert-btn" data-id="' + sanitizeHtml(String(id)) + '">' +
                        '<span class="dashicons dashicons-undo" style="margin-top:3px;"></span> ' + sanitizeHtml(i18n.revert || 'Revert') + '</button>';
                }

                updateStats();
            } else {
                btn.classList.remove('loading');
                btn.disabled = false;
                btn.innerHTML = '<span class="dashicons dashicons-performance" style="margin-top:3px;"></span> ' + sanitizeHtml(i18n.optimize || 'Optimize');
                alert(data.data || (i18n.optimizeFailed || 'Optimization failed.'));
            }
        })
        .catch(function() {
            btn.classList.remove('loading');
            btn.disabled = false;
            btn.innerHTML = '<span class="dashicons dashicons-performance" style="margin-top:3px;"></span> ' + sanitizeHtml(i18n.optimize || 'Optimize');
        });
    });

    // ── Single Revert ──
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.metasync-revert-btn');
        if (!btn || btn.disabled) return;

        if (!confirm(i18n.revertConfirm || 'Revert this image to its original format?')) {
            return;
        }

        var id = btn.dataset.id;
        btn.classList.add('loading');
        btn.disabled = true;

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=metasync_revert_single_image&nonce=' + nonce + '&attachment_id=' + id
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var row = btn.closest('tr');
                var statusCell = row.querySelector('.column-status');
                statusCell.innerHTML = '<span class="metasync-status-badge metasync-status-unoptimized">' + (i18n.unoptimized || 'Unoptimized') + '</span>';

                var actionsCell = row.querySelector('.column-actions');
                actionsCell.innerHTML = '<button type="button" class="button button-small button-primary metasync-optimize-btn" data-id="' + id + '">' +
                    '<span class="dashicons dashicons-performance" style="margin-top:3px;"></span> ' + (i18n.optimize || 'Optimize') + '</button>';

                updateStats();
            } else {
                btn.classList.remove('loading');
                btn.disabled = false;
                alert(data.data || (i18n.revertFailed || 'Revert failed.'));
            }
        })
        .catch(function() {
            btn.classList.remove('loading');
            btn.disabled = false;
        });
    });

    // ── Optimize All (Start Batch) ──
    var optimizeAllBtn = document.getElementById('metasync-optimize-all');
    if (optimizeAllBtn) {
        optimizeAllBtn.addEventListener('click', function() {
            if (!confirm(i18n.startBatch || 'Start optimizing all unoptimized images?')) {
                return;
            }

            optimizeAllBtn.disabled = true;

            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=metasync_start_batch_optimize&nonce=' + nonce
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showBatchProgress(data.data);
                    startBatchProcessing();
                } else {
                    optimizeAllBtn.disabled = false;
                    alert(data.data || (i18n.batchFailed || 'Failed to start batch optimization.'));
                }
            })
            .catch(function() {
                optimizeAllBtn.disabled = false;
            });
        });
    }

    // ── Cancel Batch ──
    var cancelBtn = document.getElementById('metasync-cancel-batch');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            batchActive = false;
            stopFallbackPoll();

            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=metasync_cancel_batch_optimize&nonce=' + nonce
            })
            .then(function(r) { return r.json(); })
            .then(function() {
                document.getElementById('metasync-batch-progress').style.display = 'none';
                if (optimizeAllBtn) optimizeAllBtn.disabled = false;
                location.reload();
            });
        });
    }

    // ── Copy URL ──
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.metasync-copy-btn');
        if (!btn) return;

        var url = btn.dataset.url;
        if (!url) return;

        navigator.clipboard.writeText(url).then(function() {
            btn.classList.add('copied');
            var icon = btn.querySelector('.dashicons');
            if (icon) {
                icon.className = 'dashicons dashicons-yes';
                setTimeout(function() {
                    icon.className = 'dashicons dashicons-clipboard';
                    btn.classList.remove('copied');
                }, 1500);
            }
        });
    });

    // ── Dismiss Complete Notice ──
    var dismissBtn = document.getElementById('metasync-dismiss-complete');
    if (dismissBtn) {
        dismissBtn.addEventListener('click', function() {
            document.getElementById('metasync-batch-complete').style.display = 'none';
        });
    }

    // ── AJAX-Driven Batch Processing ──

    function startBatchProcessing() {
        batchActive = true;
        startFallbackPoll();
        processNextBatch();
    }

    function processNextBatch() {
        if (!batchActive) return;

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=metasync_process_batch_tick&nonce=' + nonce
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success || !batchActive) return;

            var progress = data.data;
            showBatchProgress(progress);
            applyStats(progress.stats);

            if (progress.status === 'running') {
                setTimeout(processNextBatch, 500);
            } else {
                onBatchFinished(progress);
            }
        })
        .catch(function() {
            if (batchActive) {
                setTimeout(processNextBatch, 5000);
            }
        });
    }

    function onBatchFinished(progress) {
        batchActive = false;
        stopFallbackPoll();
        document.getElementById('metasync-batch-progress').style.display = 'none';

        if (progress.status === 'completed') {
            var completeEl = document.getElementById('metasync-batch-complete');
            var textEl = document.getElementById('metasync-batch-complete-text');
            textEl.textContent = (i18n.batchComplete || 'Batch optimization complete!') + ' ' +
                progress.processed + ' ' + (i18n.imagesProcessed || 'images processed') +
                (progress.failed > 0 ? ', ' + progress.failed + ' ' + (i18n.failed || 'failed') : '') + '.';
            completeEl.style.display = 'flex';
        }

        if (optimizeAllBtn) optimizeAllBtn.disabled = false;
        setTimeout(function() { location.reload(); }, 1500);
    }

    function startFallbackPoll() {
        if (fallbackPoll) return;
        fallbackPoll = setInterval(function() {
            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=metasync_batch_progress&nonce=' + nonce
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) return;
                showBatchProgress(data.data);
                applyStats(data.data.stats);
                if (data.data.status !== 'running') {
                    onBatchFinished(data.data);
                }
            });
        }, 10000);
    }

    function stopFallbackPoll() {
        if (fallbackPoll) {
            clearInterval(fallbackPoll);
            fallbackPoll = null;
        }
    }

    function showBatchProgress(progress) {
        var el = document.getElementById('metasync-batch-progress');
        el.style.display = '';

        var pct = progress.total > 0 ? Math.round((progress.processed / progress.total) * 100) : 0;
        document.getElementById('metasync-batch-fill').style.width = pct + '%';
        document.getElementById('metasync-batch-text').textContent =
            (i18n.optimizingOf || 'Optimizing') + ' ' + progress.processed + ' ' + (i18n.of || 'of') + ' ' + progress.total + ' ' + (i18n.images || 'images...');
        document.getElementById('metasync-batch-processed').textContent = progress.processed;
        document.getElementById('metasync-batch-total').textContent = progress.total;
    }

    function applyStats(stats) {
        if (!stats) return;
        var statNumbers = document.querySelectorAll('.metasync-stat-number');
        if (statNumbers.length >= 3) {
            statNumbers[0].textContent = stats.total;
            statNumbers[1].textContent = stats.optimized;
            statNumbers[2].textContent = stats.unoptimized;
        }
        var pctEl = document.querySelector('.metasync-stat-percentage');
        if (pctEl) pctEl.textContent = stats.percentage + '%';
        var fillEl = document.querySelector('.metasync-stat-progress-fill');
        if (fillEl) fillEl.style.width = stats.percentage + '%';

        // Update "Optimize All" button state based on unoptimized count
        if (optimizeAllBtn && !batchActive) {
            var unoptimized = parseInt(stats.unoptimized, 10) || 0;
            optimizeAllBtn.disabled = unoptimized === 0;

            var badge = optimizeAllBtn.querySelector('.metasync-count-badge');
            if (unoptimized > 0) {
                if (badge) {
                    badge.textContent = stats.unoptimized;
                } else {
                    badge = document.createElement('span');
                    badge.className = 'metasync-count-badge';
                    badge.textContent = stats.unoptimized;
                    optimizeAllBtn.appendChild(badge);
                }
            } else if (badge) {
                badge.remove();
            }
        }
    }

    function updateStats() {
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=metasync_batch_progress&nonce=' + nonce
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) applyStats(data.data.stats);
        });
    }

    // Auto-resume AJAX chain if batch is already running (e.g. page reload)
    if (config.batchRunning) {
        startBatchProcessing();
    }

    // ── Bulk Optimize Selected ──
    var bulkForm = document.getElementById('metasync-image-library-form');
    if (bulkForm) {
        bulkForm.querySelectorAll('input[type="submit"], button[type="submit"]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                bulkForm._lastSubmitter = btn;
            });
        });

        bulkForm.addEventListener('submit', function(e) {
            var submitter = e.submitter || bulkForm._lastSubmitter;
            var applyBtnTop = bulkForm.querySelector('#doaction');
            var applyBtnBottom = bulkForm.querySelector('#doaction2');

            var isTopApply = submitter && submitter === applyBtnTop;
            var isBottomApply = submitter && submitter === applyBtnBottom;
            if (!isTopApply && !isBottomApply) return;

            var actionName = isTopApply ? 'action' : 'action2';
            var action = bulkForm.querySelector('[name="' + actionName + '"]');
            if (!action || (action.value !== 'bulk_optimize' && action.value !== 'bulk_unoptimize')) return;

            e.preventDefault();

            var checked = bulkForm.querySelectorAll('input[name="image_ids[]"]:checked');
            if (checked.length === 0) {
                alert(i18n.selectImages || 'Please select at least one image.');
                return;
            }

            var ids = [];
            checked.forEach(function(cb) { ids.push(cb.value); });

            var isBulkUnoptimize = action.value === 'bulk_unoptimize';

            if (isBulkUnoptimize && !confirm(i18n.unoptimizeConfirm || 'Revert selected images to their original format?')) {
                return;
            }

            var clickedBtn = isTopApply ? applyBtnTop : applyBtnBottom;
            clickedBtn.disabled = true;

            var ajaxAction = isBulkUnoptimize ? 'metasync_bulk_unoptimize_selected' : 'metasync_bulk_optimize_selected';
            clickedBtn.value = isBulkUnoptimize
                ? (i18n.unoptimizing || 'Unoptimizing...')
                : (i18n.optimizing || 'Optimizing...');

            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=' + ajaxAction + '&nonce=' + nonce + '&ids=' + ids.join(',')
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    var result = data.data;
                    if (isBulkUnoptimize && result.errors && result.errors.length > 0) {
                        alert(result.errors.join('\n'));
                    }
                    if (isBulkUnoptimize && result.success === 0 && result.skipped > 0) {
                        clickedBtn.disabled = false;
                        clickedBtn.value = i18n.apply || 'Apply';
                        alert(i18n.alreadyUnoptimized || 'All selected images are already unoptimized.');
                        return;
                    }
                    location.reload();
                } else {
                    clickedBtn.disabled = false;
                    clickedBtn.value = i18n.apply || 'Apply';
                    var failMsg = isBulkUnoptimize
                        ? (i18n.bulkUnoptimizeFailed || 'Bulk unoptimize failed.')
                        : (i18n.bulkFailed || 'Bulk optimization failed.');
                    alert(data.data || failMsg);
                }
            })
            .catch(function() {
                clickedBtn.disabled = false;
                clickedBtn.value = i18n.apply || 'Apply';
            });
        });
    }

    // ── Search Clear Button ──
    function initSearchClearBtn() {
        var searchInput = document.querySelector('.metasync-toolbar-right .search-box input[type="search"]');
        if (!searchInput) return;

        var wrapper = document.createElement('div');
        wrapper.className = 'metasync-search-input-wrap';
        searchInput.parentNode.insertBefore(wrapper, searchInput);
        wrapper.appendChild(searchInput);

        var clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'metasync-clear-search';
        clearBtn.innerHTML = '&times;';
        clearBtn.setAttribute('aria-label', 'Clear search');
        wrapper.appendChild(clearBtn);

        function updateVisibility() {
            if (searchInput.value.length > 0) {
                clearBtn.classList.add('visible');
            } else {
                clearBtn.classList.remove('visible');
            }
        }

        searchInput.addEventListener('input', updateVisibility);
        updateVisibility();

        clearBtn.addEventListener('click', function() {
            searchInput.value = '';
            updateVisibility();
            var form = searchInput.closest('form');
            if (form) { form.submit(); }
        });
    }

    initSearchClearBtn();
})();

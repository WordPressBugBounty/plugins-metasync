/**
 * MetaSync redirections — filter auto-submit and pagination tab fix.
 *
 * Extracted from views/metasync-redirection.php (Phase 5, #887).
 */
document.addEventListener('DOMContentLoaded', function () {
	// Auto-submit filters when changed
	var filterSelects = document.querySelectorAll('#status-filter, #pattern-filter, #http-code-filter');
	var form = document.getElementById('redirection-form');
	filterSelects.forEach(function (select) {
		select.addEventListener('change', function () {
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

	// Confirm destructive bulk actions.
	//
	// Mirrors the 404 monitor's guard. Reads BOTH dropdowns because the top one
	// posts `action` and the bottom one posts `action2`. Only Delete is
	// confirmed - Activate / Deactivate are reversible and do not warrant a
	// prompt. Skipped when Filter is the submitter, since the server ignores
	// bulk actions on a filter_action submit.
	var redirectionForm = document.getElementById('redirection-form');
	if (redirectionForm) {
		redirectionForm.addEventListener('submit', function (e) {
			var filterButton = document.getElementById('post-query-submit');
			if (e.submitter && filterButton && e.submitter === filterButton) {
				return;
			}

			var topSelect = document.getElementById('bulk-action-selector-top');
			var bottomSelect = document.getElementById('bulk-action-selector-bottom');
			var action = '-1';

			if (topSelect && topSelect.value !== '-1' && topSelect.value !== '') {
				action = topSelect.value;
			} else if (bottomSelect && bottomSelect.value !== '-1' && bottomSelect.value !== '') {
				action = bottomSelect.value;
			}

			if (action !== 'delete_bulk') {
				return;
			}

			var checked = document.querySelectorAll('#redirection-form input[name="items[]"]:checked');
			if (checked.length === 0) {
				return;
			}

			var message = checked.length > 1
				? 'Are you sure you want to delete the ' + checked.length + ' selected redirects? This cannot be undone.'
				: 'Are you sure you want to delete the selected redirect? This cannot be undone.';

			if (!confirm(message)) {
				e.preventDefault();
			}
		});
	}

	// Submit the search when Enter is pressed in the search field.
	//
	// #redirection-form also contains the (hidden until action=add/edit) "Save"
	// button from the add/edit-redirection panel, and that button is first in
	// tree order. Per the HTML spec, pressing Enter in a text field submits the
	// form via its *default* button (the first submit button in tree order),
	// even if that button is hidden — so Enter here would otherwise submit as
	// the "Save" button instead of "Search", which fails the add/edit form's
	// validation and gets silently cancelled. Explicitly clicking the actual
	// Search button reproduces exactly what a mouse click does, sidestepping
	// the browser's implicit-submission button choice.
	var searchInput = document.getElementById('post-search-input');
	var searchSubmit = document.getElementById('search-submit');
	if (searchInput && searchSubmit) {
		searchInput.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				searchSubmit.click();
			}
		});
	}

	// Add tab parameter to all pagination links in redirections tab
	function addTabToPaginationLinks() {
		// Find all pagination links within redirections-content
		var redirectionsContent = document.getElementById('redirections-content');
		if (redirectionsContent) {
			var paginationLinks = redirectionsContent.querySelectorAll('.tablenav-pages a');
			paginationLinks.forEach(function (link) {
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

	// --- Health Check ---
	var healthBtn = document.getElementById('metasync-check-health-btn');
	if (!healthBtn || typeof metasyncHealthCheck === 'undefined') {
		return;
	}

	var healthBtnLabel = healthBtn.textContent;

	// Single reusable tooltip appended to body — escapes all stacking contexts
	var floatingTip = document.createElement('div');
	floatingTip.className = 'health-tooltip-floating';
	floatingTip.style.display = 'none';
	document.body.appendChild(floatingTip);

	// Hide tooltip on scroll to prevent detachment from icon
	window.addEventListener('scroll', function () {
		floatingTip.style.display = 'none';
	}, true);

	// Delegated hover handlers — work for any health cells added now or later
	document.addEventListener('mouseover', function (e) {
		var cell = e.target.closest('.health-status-cell[data-health-tip]');
		if (!cell) {
			return;
		}
		var rect = cell.getBoundingClientRect();
		floatingTip.textContent = cell.getAttribute('data-health-tip');
		floatingTip.style.display = 'block';
		var tipRect = floatingTip.getBoundingClientRect();
		floatingTip.style.left = (rect.left + rect.width / 2 - tipRect.width / 2) + 'px';
		floatingTip.style.top = (rect.top - tipRect.height - 6) + 'px';
	});

	document.addEventListener('mouseout', function (e) {
		var cell = e.target.closest('.health-status-cell[data-health-tip]');
		if (!cell) {
			return;
		}
		floatingTip.style.display = 'none';
	});

	healthBtn.addEventListener('click', function () {
		healthBtn.disabled = true;
		healthBtn.innerHTML = healthBtnLabel + ' <span class="spinner is-active" style="float:none;margin:0 0 0 6px;vertical-align:middle;"></span>';

		var data = new FormData();
		data.append('action', 'metasync_check_redirects_health');
		data.append('nonce', metasyncHealthCheck.healthNonce);

		fetch(metasyncHealthCheck.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) {
				return r.json(); 
			})
			.then(function (resp) {
				healthBtn.disabled = false;
				healthBtn.textContent = healthBtnLabel;

				if (!resp.success || !resp.data || !resp.data.results) {
					return;
				}

				var results = resp.data.results;
				var map = {};
				results.forEach(function (r) {
					map[r.id] = r; 
				});

				var statusIcons = { ok: '✅', loop: '🔴', chain_too_long: '⚠️', dead_end: '💀' };
				var statusLabels = { ok: 'OK', loop: 'Loop detected', chain_too_long: 'Chain too long', dead_end: 'Dead end' };

				// Insert header column
				var table = document.querySelector('.wp-list-table');
				if (!table) {
					return;
				}

				// Remove existing health columns (re-run support)
				table.querySelectorAll('.column-health_status').forEach(function (el) {
					el.remove(); 
				});

				var headerRows = table.querySelectorAll('thead tr, tfoot tr');
				headerRows.forEach(function (tr) {
					var statusTh = tr.querySelector('.column-status');
					if (!statusTh) {
						// Fallback: insert at end
						statusTh = tr.lastElementChild;
					}
					var th = document.createElement('th');
					th.className = 'manage-column column-health_status';
					th.textContent = 'Health';
					statusTh.parentNode.insertBefore(th, statusTh.nextSibling);
				});

				// Insert cell per row
				var bodyRows = table.querySelectorAll('tbody tr');
				bodyRows.forEach(function (tr) {
					var cb = tr.querySelector('input[type="checkbox"]');
					var id = cb ? parseInt(cb.value, 10) : 0;
					var info = map[id];

					var td = document.createElement('td');
					td.className = 'column-health_status';

					if (info) {
						var icon = statusIcons[info.status] || '❓';
						var label = statusLabels[info.status] || info.status;
						var tipText = label;
						if (info.status !== 'ok' && info.chain && info.chain.length > 0) {
							tipText += ': ' + info.chain.join(' \u2192 ');
						}
						td.innerHTML = '<span class="health-status-cell" data-health-tip="' +
							tipText.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;') +
							'">' + icon + '</span>';
					} else {
						td.textContent = '\u2014';
					}

					var statusTd = tr.querySelector('.column-status');
					if (statusTd) {
						statusTd.parentNode.insertBefore(td, statusTd.nextSibling);
					} else {
						tr.appendChild(td);
					}
				});


				// Scroll to the table so results are visible
				table.scrollIntoView({ behavior: 'smooth', block: 'start' });
			})
			.catch(function () {
				healthBtn.disabled = false;
				healthBtn.textContent = healthBtnLabel;
			});
	});
});

/**
 * AI Agents (MCP) page: client key create, reveal and revoke.
 */
/* global jQuery, ajaxurl, metasyncMcpKeys */
jQuery(function ($) {
	// Neutral fallbacks only: a missing localize payload must never bake the
	// original product name into a customer's client config.
	var cfg = window.metasyncMcpKeys || { nonce: '', mcp_url: '', server_name: 'mcp' };
	var serverName = cfg.server_name || 'mcp';
	var $form = $('#metasync-mcp-key-form');
	var $error = $form.find('.metasync-mcp-form-error');
	var $reveal = $('#metasync-mcp-reveal');
	var $live = $('#metasync-mcp-live');

	function announce(message) {
		// Clear first so repeating the same message is announced again.
		$live.text('');
		window.setTimeout(function () {
			$live.text(message);
		}, 50);
	}

	function showError(message) {
		$error.find('p').text(message);
		$error.prop('hidden', false);
	}

	function snippetClaudeCode(token) {
		return 'claude mcp add --transport http ' + serverName + ' ' + cfg.mcp_url +
			' --header "Authorization: Bearer ' + token + '"';
	}

	function snippetCursor(token) {
		var servers = {};
		// Use the effective (white-label aware) server name, never a hard-coded brand.
		servers[serverName] = {
			url: cfg.mcp_url,
			headers: { Authorization: 'Bearer ' + token }
		};
		return JSON.stringify({ mcpServers: servers }, null, 2);
	}

	function legacyCopy(text) {
		var $tmp = $('<textarea readonly>').val(text).css({ position: 'fixed', top: 0, left: '-9999px' }).appendTo('body');
		$tmp[0].select();
		var ok = false;
		try {
			ok = document.execCommand('copy');
		} catch (err) {
			ok = false;
		}
		$tmp.remove();
		return ok;
	}

	function copyText(text, $button) {
		var $label = $button.find('.metasync-mcp-btn-label');
		if (!$label.length) {
			$label = $button;
		}
		// Remember the real label once so rapid clicks never store "Copied!".
		if ($button.data('mcp-label') === undefined) {
			$button.data('mcp-label', $label.text());
		}

		var finish = function (ok) {
			window.clearTimeout($button.data('mcp-timer'));
			$label.text(ok ? 'Copied!' : 'Press Ctrl+C to copy');
			$button.toggleClass('is-copied', ok);
			announce(ok ? 'Copied to clipboard.' : 'Copy failed. Select the text and copy it manually.');
			$button.data('mcp-timer', window.setTimeout(function () {
				$label.text($button.data('mcp-label'));
				$button.removeClass('is-copied');
			}, 1800));
		};

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function () {
				finish(true);
			}, function () {
				finish(legacyCopy(text));
			});
		} else {
			finish(legacyCopy(text));
		}
	}

	// Copy uses the raw text when present (snippets), else the input value.
	$(document).on('click', '[data-mcp-copy]', function () {
		var $target = $($(this).data('mcp-copy'));
		var text;
		if ($target.data('raw') !== undefined) {
			text = $target.data('raw');
		} else if ($target.is('input, textarea')) {
			text = $target.val();
		} else {
			text = $target.text();
		}
		if (!text) {
			return;
		}
		copyText(text, $(this));
	});

	// Selecting a read-only field selects its whole value for manual copying.
	$('.metasync-mcp-keys-wrap').on('focus click', 'input[readonly]', function () {
		this.select();
	});

	// Connection guide tabs (WAI-ARIA tabs pattern, manual focus management).
	var $tabs = $reveal.find('[role="tab"]');

	function selectTab($tab, focus) {
		$tabs.each(function () {
			var selected = this === $tab[0];
			$(this).attr('aria-selected', selected ? 'true' : 'false').attr('tabindex', selected ? null : '-1');
			$('#' + $(this).attr('aria-controls')).prop('hidden', !selected);
		});
		if (focus) {
			$tab.trigger('focus');
		}
	}

	$tabs.on('click', function () {
		selectTab($(this), false);
	});

	$tabs.on('keydown', function (e) {
		var index = $tabs.index(this);
		var next = null;
		if (e.key === 'ArrowRight') {
			next = (index + 1) % $tabs.length;
		} else if (e.key === 'ArrowLeft') {
			next = (index - 1 + $tabs.length) % $tabs.length;
		} else if (e.key === 'Home') {
			next = 0;
		} else if (e.key === 'End') {
			next = $tabs.length - 1;
		}
		if (next !== null) {
			e.preventDefault();
			selectTab($tabs.eq(next), true);
		}
	});

	function selectedAccess() {
		return $form.find('input[name="access"]:checked').val() || 'read';
	}

	$form.on('change', 'input[name="access"]', function () {
		$('#metasync-mcp-full-warning').prop('hidden', selectedAccess() !== 'full');
	});

	function syncCustomExpiry() {
		var custom = $('#metasync-mcp-key-expiry').val() === 'custom';
		var $field = $('#metasync-mcp-custom-expiry');
		$field.prop('hidden', !custom);
		$('#metasync-mcp-key-expiry-days').prop('required', custom);
		if (!custom) {
			$('#metasync-mcp-key-expiry-days').removeAttr('aria-invalid').val('');
		}
	}

	$('#metasync-mcp-key-expiry').on('change', syncCustomExpiry);
	syncCustomExpiry();

	$form.on('submit', function (e) {
		e.preventDefault();
		$error.prop('hidden', true);

		var $name = $('#metasync-mcp-key-name');
		var name = $.trim($name.val());
		if (!name) {
			showError('Please give the key a name.');
			$name.attr('aria-invalid', 'true').trigger('focus');
			return;
		}
		$name.removeAttr('aria-invalid');

		var expiry = $('#metasync-mcp-key-expiry').val();
		var customDays = $('#metasync-mcp-key-expiry-days').val();
		if (expiry === 'custom' && (!/^[1-9][0-9]{0,2}$/.test(customDays || '') || parseInt(customDays, 10) > 365)) {
			showError('Enter a whole number from 1 to 365 days.');
			$('#metasync-mcp-key-expiry-days').attr('aria-invalid', 'true').trigger('focus');
			return;
		}
		$('#metasync-mcp-key-expiry-days').removeAttr('aria-invalid');

		var $button = $('#metasync-mcp-key-create');
		$button.prop('disabled', true);
		$form.find('.spinner').addClass('is-active');

		$.post(ajaxurl, {
			action: 'metasync_create_mcp_key',
			nonce: cfg.nonce,
			name: name,
			access: selectedAccess(),
			expiry: expiry,
			expiry_days: expiry === 'custom' ? customDays : ''
		}).done(function (response) {
			if (!response || !response.success) {
				showError((response && response.data && response.data.message) || 'Could not create the key.');
				return;
			}

			var token = response.data.token;

			$('#metasync-mcp-token').val(token);

			var claude = snippetClaudeCode(token);
			var cursor = snippetCursor(token);
			$('#metasync-mcp-claude-code').text(claude).data('raw', claude);
			$('#metasync-mcp-cursor').text(cursor).data('raw', cursor);
			$('#metasync-mcp-other-header-value').val('Bearer ' + token);

			selectTab($tabs.first(), false);
			$reveal.prop('hidden', false);
			$reveal[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
			$reveal.trigger('focus');
			$name.val('');
		}).fail(function (xhr) {
			var message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
			showError(message || 'Request failed. Please try again.');
		}).always(function () {
			$button.prop('disabled', false);
			$form.find('.spinner').removeClass('is-active');
		});
	});

	$('#metasync-mcp-reveal-done').on('click', function () {
		// Wipe every element that held the plaintext token before reloading.
		$reveal.find('.metasync-mcp-secret').each(function () {
			var $el = $(this);
			if ($el.is('input, textarea')) {
				$el.val('');
			} else {
				$el.text('');
			}
			$el.removeData('raw');
		});
		window.location.reload();
	});

	$(document).on('click', '.metasync-mcp-revoke', function () {
		var keyId = $(this).data('key-id');
		if (!window.confirm('Revoke this key? The AI agent using it loses access immediately.')) {
			return;
		}

		var $button = $(this);
		$button.prop('disabled', true);

		$.post(ajaxurl, {
			action: 'metasync_revoke_mcp_key',
			nonce: cfg.nonce,
			key_id: keyId
		}).done(function (response) {
			if (response && response.success) {
				window.location.reload();
			} else {
				window.alert((response && response.data && response.data.message) || 'Could not revoke the key.');
				$button.prop('disabled', false);
			}
		}).fail(function () {
			window.alert('Request failed. Please try again.');
			$button.prop('disabled', false);
		});
	});
});

(function () {
  'use strict';

  // All DOM lookups live inside mount(): wp-admin prints the footer SCRIPTS
  // (admin_print_footer_scripts) before the admin_footer-{$hook} callbacks
  // echo the wrap, and the Categories metabox is added by jQuery at
  // DOMContentLoaded — so at execution time none of these elements exist
  // yet. Bailing at IIFE level here would strand the select in the footer.
  function mount() {
    var config = window.metasyncClassicSeoFields || {};
    var wrap = document.getElementById('metasync-primary-category-wrap');
    var categoryDiv = document.getElementById('categorydiv');
    if (!wrap || !categoryDiv) {
      return;
    }

    var select = document.getElementById('metasync-primary-category-select');
    var checklist = document.getElementById('categorychecklist');
    if (!select || !checklist) {
      return;
    }

    var storedPrimary = parseInt(wrap.getAttribute('data-stored') || config.storedPrimary || 0, 10) || 0;
    var strings = config.i18n || {};

    function rebuildOptions() {
      var checked = [];
      checklist.querySelectorAll('label.selectit').forEach(function (label) {
        var input = label.querySelector('input[type="checkbox"]');
        if (!input || !input.checked) {
          return;
        }

        var termId = parseInt(input.value, 10) || 0;
        if (!termId) {
          return;
        }

        checked.push({
          id: termId,
          name: (label.textContent || '').trim(),
        });
      });

      var previous = parseInt(select.value, 10) || 0;
      select.replaceChildren(new Option(strings.none || '(none)', '0'));

      checked.forEach(function (category) {
        select.appendChild(new Option(category.name, String(category.id)));
      });

      // Prefer whatever the select already shows when it is still a checked
      // option: the select's own change event bubbles up to categoryDiv and
      // re-enters rebuildOptions, so preferring storedPrimary here would
      // instantly revert a fresh manual pick whenever the stored term is
      // still checked. Fall back to the stored primary, then to none.
      var selected = checked.some(function (category) {
        return category.id === previous;
      }) ? previous : (checked.some(function (category) {
        return category.id === storedPrimary;
      }) ? storedPrimary : 0);
      var selectionStillChecked = checked.some(function (category) {
        return category.id === selected;
      });

      select.value = selectionStillChecked ? String(selected) : '0';
      // storedPrimary is deliberately NOT zeroed when its term is momentarily
      // unchecked: uncheck-then-recheck before saving must round-trip the
      // stored value, not delete it.
    }

    // Relocate under the core Categories checklist — beside the box the user
    // is already interacting with, not inside the SEO Suite.
    var inside = categoryDiv.querySelector('.inside');
    (inside || categoryDiv).appendChild(wrap);
    wrap.style.display = '';
    rebuildOptions();

    // Ticking in either tab (All Categories or Most Used) bubbles a change
    // event up to the metabox, so one listener covers both lists.
    categoryDiv.addEventListener('change', rebuildOptions);
    // The "Add New Category" flow appends a checked <li> without firing a
    // change event; the observer covers it.
    var observer = new MutationObserver(rebuildOptions);
    observer.observe(checklist, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['class'],
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();

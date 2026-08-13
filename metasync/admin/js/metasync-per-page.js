/**
 * Results-per-page selectors for MetaSync admin list tables.
 *
 * A changed page size is a normal GET navigation, not an AJAX request: the
 * server validates and persists it before rebuilding the relevant list.
 */
(function () {
    'use strict';

    var transientParams = [
        '_wpnonce', '_wp_http_referer', 'action', 'action2', 'filter_action',
        'item', 'item[]', 'items', 'items[]', 'id'
    ];

    function controlValue(form, param) {
        var control = form.elements.namedItem(param);

        if (!control) {
            return null;
        }

        if (typeof control.value === 'string') {
            return control.value.trim();
        }

        return control.length && typeof control[0].value === 'string'
            ? control[0].value.trim()
            : null;
    }

    function syncFormState(params, form, stateParams) {
        stateParams.forEach(function (param) {
            var value = controlValue(form, param);

            if (value === null) {
                return;
            }

            params.delete(param);
            if (value !== '') {
                params.set(param, value);
            }
        });
    }

    window.metasyncNavigateListState = function (form, pageKey, changedSelector) {
        var selector = changedSelector || document.querySelector(
            '.metasync-per-page-select[data-page-key="' + pageKey + '"]'
        );

        if (!selector) {
            return false;
        }

        var perPageKey = selector.dataset.perPageKey;
        var pagedParam = selector.dataset.pagedParam;
        var stateParams = JSON.parse(selector.dataset.stateParams || '[]');
        var params = new URL(window.location.href).searchParams;

        transientParams.forEach(function (param) {
            params.delete(param);
        });
        if (pageKey === 'redirections' || pageKey === '404_monitor') {
            params.delete('s');
        }

        if (form) {
            syncFormState(params, form, stateParams);
        }

        params.set(perPageKey, selector.value);
        params.delete(pagedParam);

        if (params.get('page') === 'searchatlas-redirections') {
            params.set('tab', pageKey === '404_monitor' ? '404-monitor' : 'redirections');
        }

        window.location.assign('admin.php?' + params.toString());
        return true;
    };

    document.addEventListener('change', function (event) {
        var selector = event.target;

        if (!selector.matches('.metasync-per-page-select')) {
            return;
        }

        var pageKey = selector.dataset.pageKey;
        if (!pageKey) {
            return;
        }

        window.metasyncNavigateListState(selector.form, pageKey, selector);
    });
}());

(function ($) {
    'use strict';

    var defaults = {
        dark: {
            'dashboard-bg':             '#0f1419',
            'dashboard-card-bg':        '#1a1f26',
            'dashboard-card-hover':     '#222831',
            'dashboard-text-primary':   '#ffffff',
            'dashboard-text-secondary': '#9ca3af',
            'dashboard-accent':         '#3b82f6',
            'dashboard-accent-hover':   '#2563eb',
            'dashboard-success':        '#10b981',
            'dashboard-warning':        '#f59e0b',
            'dashboard-error':          '#ef4444',
            'dashboard-border':         '#374151',
            'dashboard-gradient-primary-from': '#667eea',
            'dashboard-gradient-primary-to':   '#764ba2',
            'dashboard-gradient-accent-from':  '#f093fb',
            'dashboard-gradient-accent-to':    '#f5576c'
        },
        light: {
            'dashboard-bg':             '#f8f9fa',
            'dashboard-card-bg':        '#ffffff',
            'dashboard-card-hover':     '#f1f3f5',
            'dashboard-text-primary':   '#1a1f26',
            'dashboard-text-secondary': '#6b7280',
            'dashboard-accent':         '#3b82f6',
            'dashboard-accent-hover':   '#2563eb',
            'dashboard-success':        '#10b981',
            'dashboard-warning':        '#f59e0b',
            'dashboard-error':          '#ef4444',
            'dashboard-border':         '#e5e7eb',
            'dashboard-gradient-primary-from': '#667eea',
            'dashboard-gradient-primary-to':   '#764ba2',
            'dashboard-gradient-accent-from':  '#f093fb',
            'dashboard-gradient-accent-to':    '#f5576c'
        }
    };

    // Gradient pairs: CSS variable name -> [from field, to field]
    var gradientPairs = {
        'dashboard-gradient-primary': ['dashboard-gradient-primary-from', 'dashboard-gradient-primary-to'],
        'dashboard-gradient-accent':  ['dashboard-gradient-accent-from',  'dashboard-gradient-accent-to']
    };

    function getCurrentTheme() {
        var wrap = document.querySelector('.metasync-dashboard-wrap');
        return (wrap && wrap.getAttribute('data-theme')) || 'dark';
    }

    function getFieldValue(theme, varName) {
        var $field = $('#metasync-color-palette-section .metasync-color-field[data-color-theme="' + theme + '"][data-css-var="' + varName + '"]');
        return $field.length ? $field.val() : '';
    }

    function applyLivePreview() {
        var theme = getCurrentTheme();
        var wrap = document.querySelector('.metasync-dashboard-wrap');
        if (!wrap) return;

        $('#metasync-color-palette-section .metasync-color-field[data-color-theme="' + theme + '"]').each(function () {
            var varName = $(this).data('css-var');
            // Skip gradient partials — they are composed below
            if (varName.indexOf('gradient-') !== -1) return;
            var value = $(this).val();
            if (value) {
                wrap.style.setProperty('--' + varName, value);
            }
        });

        // Build gradient CSS variables from paired from/to fields
        $.each(gradientPairs, function (gradVar, pair) {
            var from = getFieldValue(theme, pair[0]);
            var to   = getFieldValue(theme, pair[1]);
            if (from && to) {
                wrap.style.setProperty('--' + gradVar, 'linear-gradient(135deg, ' + from + ' 0%, ' + to + ' 100%)');
            }
        });
    }

    function updateHexDisplay($input) {
        $input.siblings('.metasync-color-hex-display').text($input.val());
    }

    function bindColorInputs() {
        $('#metasync-color-palette-section').on('input', '.metasync-color-field', function () {
            var $input = $(this);
            updateHexDisplay($input);
            applyLivePreview();
        });
    }

    function bindResetButtons() {
        $('#metasync-reset-dark-palette, #metasync-reset-light-palette').on('click', function (e) {
            e.preventDefault();
            var theme = $(this).data('reset-theme');
            var themeDefaults = defaults[theme];
            if (!themeDefaults) return;

            $('#metasync-color-palette-section .metasync-color-field[data-color-theme="' + theme + '"]').each(function () {
                var $input = $(this);
                var varName = $input.data('css-var');
                var defaultVal = themeDefaults[varName] || '';

                $input.val(defaultVal);
                updateHexDisplay($input);
            });

            applyLivePreview();
        });
    }

    function bindThemeTabs() {
        $(document).on('click', '.metasync-palette-tab', function (e) {
            e.preventDefault();
            var target = $(this).data('palette');

            $('.metasync-palette-tab').removeClass('active');
            $(this).addClass('active');

            $('.metasync-palette-panel').removeClass('active');
            $('#metasync-palette-' + target).addClass('active');
        });
    }

    function listenForThemeChange() {
        $(document).on('metasync-theme-changed', function () {
            applyLivePreview();
        });
    }

    $(document).ready(function () {
        if ($('#metasync-color-palette-section').length === 0) return;

        bindColorInputs();
        bindResetButtons();
        bindThemeTabs();
        listenForThemeChange();
        applyLivePreview();
    });

})(jQuery);

/**
 * MetaSync HTML Visual Editor
 *
 * Initializes GrapesJS editor with custom configuration
 * for editing raw HTML pages with full visual capabilities.
 * 
 * @global grapesjs - Loaded from GrapesJS library script
 * @global metasyncEditor - Injected via wp_localize_script
 */
/* eslint-env browser */
/* global grapesjs, metasyncEditor */

(function($) {
    'use strict';

    let editor;
    let hasUnsavedChanges = false;

    $(document).ready(function() {
        // Bind the header controls first so Back and Preview keep working even
        // if the canvas never starts.
        initializeEventHandlers();

        if (!startEditor()) {
            return;
        }

        preventAccidentalExit();
    });

    /**
     * Start the editor, reporting any dependency or start-up failure to the
     * user instead of leaving an unexplained empty canvas behind.
     *
     * @return {boolean} True when the canvas initialized.
     */
    function startEditor() {
        // Dependencies absent from disk (reported by PHP) plus any whose
        // request failed in the browser (blocked by CSP, an extension, etc).
        const missing = (metasyncEditor.missing || []).slice();
        const failed = (window.metasyncEditorAssets && window.metasyncEditorAssets.failed) || [];

        failed.forEach(function(name) {
            if (missing.indexOf(name) === -1) {
                missing.push(name);
            }
        });
        // The editor script is ordered after GrapesJS but WordPress cannot
        // guarantee the file actually arrived, so verify the global exists.
        if (typeof grapesjs === 'undefined') {
            if (missing.indexOf('grapesjs') === -1) {
                missing.push('grapesjs');
            }
            showLoadFailure(
                metasyncEditor.i18n.load_failed_core,
                missing,
                { fatal: true }
            );
            return false;
        }

        try {
            initializeEditor();
        } catch (err) {
            console.error('MetaSync HTML Editor failed to initialize:', err);
            showLoadFailure(
                metasyncEditor.i18n.load_failed_init,
                missing,
                { fatal: true }
            );
            return false;
        }

        // The blocks library is optional: without it the canvas still loads and
        // the page stays editable, only the extra block palette is missing.
        if (!resolveBlocksPlugin()) {
            if (missing.indexOf('grapesjs-blocks-basic') === -1) {
                missing.push('grapesjs-blocks-basic');
            }
            showLoadFailure(
                metasyncEditor.i18n.load_failed_blocks,
                missing,
                { fatal: false }
            );
        }

        // Pages the canvas cannot round-trip are announced after it starts, so
        // the layout stays visible for reference, but saving is disabled before
        // the user can edit: the document was already reduced when it was
        // parsed into the canvas, and saving that reduction is the data loss.
        showBlockedNotice();

        return true;
    }

    /**
     * Disable saving for a page the canvas cannot represent faithfully.
     *
     * GrapesJS builds body fragments. A complete document, or a page carrying
     * scripts, loses whatever the canvas cannot hold the moment it is parsed —
     * so the warning belongs at load, not at save, and the only honest action
     * is to send the user to the editor that can save the page intact.
     */
    function showBlockedNotice() {
        const reason = metasyncEditor.blocked_reason;

        if (!reason) {
            return;
        }

        const $panel = $('#metasync-editor-load-failure');

        const message = reason === 'lps'
            ? t('blocked_lps', 'This page was imported from Website Studio, which owns its content.')
            : t('blocked_full_document', 'This page is a complete HTML document, so saving here would discard its doctype, head and scripts.');

        $('.metasync-save-button')
            .prop('disabled', true)
            .attr('title', t('blocked_save_disabled', 'Saving is disabled to protect this page from being rewritten'));

        if (!$panel.length) {
            return;
        }

        $panel.find('.metasync-load-failure-title')
            .text(t('blocked_title', 'This page cannot be saved from the visual editor'));
        $panel.find('.metasync-load-failure-message').text(message);
        $panel.find('.metasync-load-failure-detail').hide();

        // Non-fatal styling: the canvas stays visible and usable for reading,
        // so the notice sits above it rather than covering it.
        $panel.removeClass('metasync-load-failure-fatal');

        // Reload cannot help here — the block is a property of the stored
        // page — so that button becomes the route to the lossless editor.
        const $primary = $panel.find('.metasync-load-failure-reload').off('click');

        if (metasyncEditor.direct_edit_url) {
            $primary
                .text(t('open_direct_editor', 'Edit HTML Directly'))
                .on('click', function(e) {
                    e.preventDefault();
                    window.location.href = metasyncEditor.direct_edit_url;
                })
                .show();
        } else {
            $primary.hide();
        }

        $panel.find('.metasync-load-failure-dismiss')
            .text(t('dismiss', 'Dismiss'))
            .off('click')
            .on('click', function(e) {
                e.preventDefault();
                $panel.attr('hidden', 'hidden');
            })
            .show();

        $panel.removeAttr('hidden');
        updateStatus('error');
    }

    /**
     * Resolve the grapesjs-blocks-basic plugin.
     *
     * Releases before 1.0.0 registered themselves under the name
     * 'gjs-blocks-basic'; 1.0.x only exposes a UMD export, so the plugin is
     * looked up by reference and the legacy registry name is only a fallback.
     *
     * @return {Function|undefined} The plugin function when available.
     */
    function resolveBlocksPlugin() {
        const exported = window['gjs-blocks-basic'];
        const plugin = exported && exported.default ? exported.default : exported;

        if (typeof plugin === 'function') {
            return plugin;
        }

        if (grapesjs.plugins && typeof grapesjs.plugins.get === 'function') {
            return grapesjs.plugins.get('gjs-blocks-basic');
        }

        return undefined;
    }

    /**
     * Report an asset-loading or start-up failure in the page.
     *
     * Only dependency names and the localized message are shown; no paths,
     * versions or server detail are exposed.
     *
     * @param {string} message Localized explanation.
     * @param {Array}  missing Names of the dependencies that did not load.
     * @param {Object} options Set fatal to true when the canvas is unusable.
     */
    function showLoadFailure(message, missing, options) {
        const settings = options || {};
        const $panel = $('#metasync-editor-load-failure');

        console.error('MetaSync HTML Editor: ' + message +
            (missing.length ? ' (' + missing.join(', ') + ')' : ''));

        if (!$panel.length) {
            return;
        }

        $panel.find('.metasync-load-failure-title').text(metasyncEditor.i18n.load_failed_title);
        $panel.find('.metasync-load-failure-message').text(message);

        const $detail = $panel.find('.metasync-load-failure-detail');
        if (missing.length) {
            $detail.text(
                (metasyncEditor.i18n.load_failed_detail || '%s').replace('%s', missing.join(', '))
            ).show();
        } else {
            $detail.hide();
        }

        $panel.find('.metasync-load-failure-reload')
            .text(metasyncEditor.i18n.reload)
            .off('click')
            .on('click', function(e) {
                e.preventDefault();
                window.location.reload();
            });

        $panel.toggleClass('metasync-load-failure-fatal', !!settings.fatal);
        // Clear `hidden` rather than calling .show(), which would set an inline
        // display and break the panel's flex centering.
        $panel.removeAttr('hidden');

        if (settings.fatal) {
            $('.metasync-save-button')
                .prop('disabled', true)
                .attr('title', metasyncEditor.i18n.save_disabled);
        } else {
            // Non-fatal: let the notice be dismissed and keep editing.
            $panel.find('.metasync-load-failure-dismiss')
                .text(metasyncEditor.i18n.dismiss)
                .off('click')
                .on('click', function(e) {
                    e.preventDefault();
                    $panel.hide();
                })
                .show();
        }

        updateStatus('error');
    }

    /**
     * Sidebar panels, in switcher order.
     *
     * Each entry maps a switcher button to the sidebar panel it reveals, the
     * command that activates it, and the localized-label key for its title.
     *
     * @type {Array<Object>}
     */
    const SIDEBAR_PANELS = [
        { id: 'styles', command: 'show-styles', icon: 'dashicons-art', labelKey: 'panel_styles', fallback: 'Styles' },
        { id: 'traits', command: 'show-traits', icon: 'dashicons-admin-generic', labelKey: 'panel_settings', fallback: 'Settings' },
        { id: 'layers', command: 'show-layers', icon: 'dashicons-menu', labelKey: 'panel_layers', fallback: 'Layers' },
        { id: 'blocks', command: 'show-blocks', icon: 'dashicons-grid-view', labelKey: 'panel_blocks', fallback: 'Blocks' }
    ];

    /**
     * Resolve a localized string with an English fallback.
     *
     * @param {string} key     Property under metasyncEditor.i18n.
     * @param {string} fallback Used when the payload predates the key.
     * @return {string}
     */
    function t(key, fallback) {
        return (metasyncEditor.i18n && metasyncEditor.i18n[key]) || fallback;
    }

    /**
     * Show a transient action notice in the page's live region.
     *
     * alert() blocks the UI thread, cannot be styled or dismissed by
     * keyboard, and is unreachable to screen-reader users mid-flow; the
     * notice element is announced automatically instead.
     *
     * @param {string} message Localized message to display.
     */
    let noticeTimer = null;
    function showNotice(message) {
        const $notice = $('#metasync-editor-notice');

        if (!$notice.length) {
            window.alert(message);
            return;
        }

        $notice.text(message).removeAttr('hidden');

        if (noticeTimer) {
            clearTimeout(noticeTimer);
        }
        noticeTimer = setTimeout(function() {
            $notice.attr('hidden', true);
        }, 6000);
    }

    /**
     * Render the sidebar's panel switcher buttons.
     *
     * The buttons are created in the sidebar itself rather than as GrapesJS
     * panel buttons, so the editor re-rendering its panels cannot detach them.
     */
    function buildPanelSwitcher() {
        const $switcher = $('#metasync-editor-panel-switcher');

        if (!$switcher.length) {
            return;
        }

        $switcher.empty();

        SIDEBAR_PANELS.forEach(function(panel) {
            const label = t(panel.labelKey, panel.fallback);
            const $button = $('<button/>', {
                type: 'button',
                'class': 'metasync-panel-switch',
                'data-metasync-panel-target': panel.id,
                'aria-pressed': 'false',
                title: label
            });

            $button.append($('<span/>', { 'class': 'dashicons ' + panel.icon, 'aria-hidden': 'true' }));
            $button.append($('<span/>', { 'class': 'metasync-panel-switch-label', text: label }));
            $button.on('click', function() {
                editor.runCommand(panel.command);
            });

            $switcher.append($button);
        });

        showPanel('styles');
    }

    /**
     * Reveal one sidebar panel and mark its switcher button active.
     *
     * @param {string} panelId Identifier from SIDEBAR_PANELS.
     */
    function showPanel(panelId) {
        SIDEBAR_PANELS.forEach(function(panel) {
            const isActive = panel.id === panelId;
            const target = document.getElementById('metasync-panel-' + panel.id);

            if (target) {
                target.hidden = !isActive;
            }

            $('.metasync-panel-switch[data-metasync-panel-target="' + panel.id + '"]')
                .toggleClass('is-active', isActive)
                .attr('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    /**
     * Initialize GrapesJS editor
     */
    function initializeEditor() {
        const htmlContent = $('#metasync-html-content').val();
        const blocksPlugin = resolveBlocksPlugin();

        editor = grapesjs.init({
            container: '#metasync-gjs-editor',
            fromElement: false,
            height: 'calc(100vh - 112px)',
            width: 'auto',
            storageManager: false, // Disable built-in storage

            // Plugins. Passed by reference so the editor does not depend on the
            // library registering itself under a legacy global name.
            plugins: blocksPlugin ? [blocksPlugin] : [],
            pluginsOpts: {},

            // Enable double-click to edit text
            allowScripts: 0,
            showOffsets: 1,
            noticeOnUnload: 0,

            // Make text components editable
            richTextEditor: {
                actions: ['bold', 'italic', 'underline', 'strikethrough', 'link']
            },

            // Upload images through the plugin's AJAX endpoint. Without this
            // the asset manager has no upload URL and its upload button does
            // nothing at all — no request, no asset, no error.
            assetManager: {
                upload: metasyncEditor.ajax_url,
                uploadName: 'file',
                // Values must stay flat strings: the uploader FormData-appends
                // each param verbatim, so a nested object would serialize as
                // the literal "[object Object]".
                params: {
                    action: 'metasync_upload_image',
                    nonce: metasyncEditor.nonce,
                    post_id: metasyncEditor.post_id
                },
                multiUpload: false,
                // The endpoint can fail two ways: a WordPress envelope that
                // reports the error with HTTP 200, and a non-200 response
                // (expired nonce answers "-1" with 403, a fatal answers HTML
                // with 500). Both must reject here, or the asset manager
                // would add the error payload as a broken, imageless asset.
                customFetch: function (url, options) {
                    return fetch(url, options)
                        .then(function (response) {
                            if (!response.ok) {
                                return response.text().then(function (text) {
                                    var body = null;
                                    try {
                                        body = JSON.parse(text);
                                    } catch (error) {
                                        body = null;
                                    }
                                    return Promise.reject(body || { data: { message: 'HTTP ' + response.status } });
                                });
                            }
                            return response.text();
                        })
                        .then(function (text) {
                            var body;
                            try {
                                body = JSON.parse(text);
                            } catch (error) {
                                return text;
                            }
                            if (body && body.success === false) {
                                return Promise.reject(body);
                            }
                            return text;
                        });
                }
            },

            // Canvas settings
            canvas: {
                styles: [],
                scripts: []
            },

            // Block Manager
            blockManager: {
                appendTo: '#metasync-editor-blocks',
                blocks: [
                    {
                        id: 'section',
                        label: '<div class="gjs-block-label">Section</div>',
                        content: '<section class="section"><h2>Section</h2><p>Add your content here</p></section>',
                        category: 'Basic',
                        attributes: { class: 'gjs-block-section' }
                    },
                    {
                        id: 'text',
                        label: '<div class="gjs-block-label">Text</div>',
                        content: '<div data-gjs-type="text">Insert your text here</div>',
                        category: 'Basic'
                    },
                    {
                        id: 'image',
                        label: '<div class="gjs-block-label">Image</div>',
                        content: { type: 'image' },
                        category: 'Basic',
                        activate: true
                    },
                    {
                        id: 'button',
                        label: '<div class="gjs-block-label">Button</div>',
                        content: '<a class="button">Button</a>',
                        category: 'Basic'
                    },
                    {
                        id: 'divider',
                        label: '<div class="gjs-block-label">Divider</div>',
                        content: '<hr/>',
                        category: 'Basic'
                    }
                ]
            },

            // Style Manager
            styleManager: {
                appendTo: '#metasync-editor-styles',
                sectors: [
                    {
                        name: 'Colors',
                        open: true,
                        properties: [
                            {
                                name: 'Text Color',
                                property: 'color',
                                type: 'color',
                                defaults: '#000000',
                                list: [
                                    { value: '#000000', name: 'Black' },
                                    { value: '#ffffff', name: 'White' },
                                    { value: '#e53e3e', name: 'Red' },
                                    { value: '#dd6b20', name: 'Orange' },
                                    { value: '#d69e2e', name: 'Yellow' },
                                    { value: '#38a169', name: 'Green' },
                                    { value: '#3182ce', name: 'Blue' },
                                    { value: '#805ad5', name: 'Purple' },
                                    { value: '#d53f8c', name: 'Pink' },
                                    { value: '#718096', name: 'Gray' }
                                ]
                            },
                            {
                                name: 'Background Color',
                                property: 'background-color',
                                type: 'color',
                                defaults: 'transparent',
                                list: [
                                    { value: 'transparent', name: 'Transparent' },
                                    { value: '#ffffff', name: 'White' },
                                    { value: '#f7fafc', name: 'Gray 50' },
                                    { value: '#edf2f7', name: 'Gray 100' },
                                    { value: '#e2e8f0', name: 'Gray 200' },
                                    { value: '#cbd5e0', name: 'Gray 300' },
                                    { value: '#000000', name: 'Black' },
                                    { value: '#e53e3e', name: 'Red' },
                                    { value: '#dd6b20', name: 'Orange' },
                                    { value: '#d69e2e', name: 'Yellow' },
                                    { value: '#38a169', name: 'Green' },
                                    { value: '#3182ce', name: 'Blue' },
                                    { value: '#805ad5', name: 'Purple' }
                                ]
                            },
                            {
                                name: 'Border Color',
                                property: 'border-color',
                                type: 'color',
                                defaults: '#e2e8f0'
                            },
                            {
                                name: 'Gradient Background',
                                property: 'background',
                                type: 'stack',
                                layerLabel: function (layer, {values}) {
                                    const type = values.type || '';
                                    return type.charAt(0).toUpperCase() + type.slice(1);
                                },
                                list: [
                                    { value: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', name: 'Purple Bliss' },
                                    { value: 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)', name: 'Pink Passion' },
                                    { value: 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)', name: 'Blue Sky' },
                                    { value: 'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)', name: 'Green Beach' },
                                    { value: 'linear-gradient(135deg, #fa709a 0%, #fee140 100%)', name: 'Sunset' },
                                    { value: 'linear-gradient(135deg, #30cfd0 0%, #330867 100%)', name: 'Deep Ocean' },
                                    { value: 'linear-gradient(135deg, #a8edea 0%, #fed6e3 100%)', name: 'Pastel Dream' },
                                    { value: 'linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%)', name: 'Pink Lady' },
                                    { value: 'linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%)', name: 'Peach' },
                                    { value: 'linear-gradient(135deg, #ff6e7f 0%, #bfe9ff 100%)', name: 'Cool Sunset' }
                                ]
                            },
                            {
                                name: 'Opacity',
                                property: 'opacity',
                                type: 'slider',
                                defaults: 1,
                                step: 0.01,
                                max: 1,
                                min: 0
                            }
                        ]
                    },
                    {
                        name: 'General',
                        open: false,
                        properties: [
                            'display',
                            'position',
                            'top',
                            'right',
                            'left',
                            'bottom'
                        ]
                    },
                    {
                        name: 'Dimensions',
                        open: false,
                        properties: [
                            'width',
                            'height',
                            'max-width',
                            'min-width',
                            'max-height',
                            'min-height',
                            'margin',
                            'padding'
                        ]
                    },
                    {
                        name: 'Typography',
                        open: false,
                        properties: [
                            {
                                name: 'Font Family',
                                property: 'font-family',
                                type: 'select',
                                defaults: 'Arial, sans-serif',
                                list: [
                                    { value: 'Arial, sans-serif', name: 'Arial' },
                                    { value: 'Georgia, serif', name: 'Georgia' },
                                    { value: 'Impact, sans-serif', name: 'Impact' },
                                    { value: 'Tahoma, sans-serif', name: 'Tahoma' },
                                    { value: '"Times New Roman", serif', name: 'Times New Roman' },
                                    { value: 'Verdana, sans-serif', name: 'Verdana' },
                                    { value: '"Courier New", monospace', name: 'Courier New' },
                                    { value: '"Lucida Console", monospace', name: 'Lucida Console' },
                                    { value: '"Trebuchet MS", sans-serif', name: 'Trebuchet MS' },
                                    { value: '"Helvetica Neue", Helvetica, Arial, sans-serif', name: 'Helvetica' }
                                ]
                            },
                            'font-size',
                            'font-weight',
                            'letter-spacing',
                            'line-height',
                            'text-align',
                            'text-decoration',
                            {
                                name: 'Text Shadow',
                                property: 'text-shadow',
                                type: 'stack',
                                layerLabel: function (layer, {values}) {
                                    return 'Shadow';
                                },
                                list: [
                                    { value: '2px 2px 4px rgba(0,0,0,0.3)', name: 'Soft Shadow' },
                                    { value: '0 0 10px rgba(0,0,0,0.5)', name: 'Glow' },
                                    { value: '3px 3px 0px rgba(0,0,0,1)', name: 'Hard Shadow' },
                                    { value: '0 1px 0 #ccc, 0 2px 0 #c9c9c9, 0 3px 0 #bbb', name: '3D Text' }
                                ]
                            }
                        ]
                    },
                    {
                        name: 'Decorations',
                        open: false,
                        properties: [
                            {
                                name: 'Border Radius',
                                property: 'border-radius',
                                type: 'composite',
                                defaults: '0',
                                properties: [
                                    { name: 'Top Left', property: 'border-top-left-radius', type: 'integer', units: ['px', '%'], defaults: '0' },
                                    { name: 'Top Right', property: 'border-top-right-radius', type: 'integer', units: ['px', '%'], defaults: '0' },
                                    { name: 'Bottom Right', property: 'border-bottom-right-radius', type: 'integer', units: ['px', '%'], defaults: '0' },
                                    { name: 'Bottom Left', property: 'border-bottom-left-radius', type: 'integer', units: ['px', '%'], defaults: '0' }
                                ],
                                list: [
                                    { value: '0', name: 'None' },
                                    { value: '4px', name: 'Small' },
                                    { value: '8px', name: 'Medium' },
                                    { value: '16px', name: 'Large' },
                                    { value: '50%', name: 'Circle' }
                                ]
                            },
                            'border',
                            {
                                name: 'Box Shadow',
                                property: 'box-shadow',
                                type: 'stack',
                                layerLabel: function (layer, {values}) {
                                    return 'Shadow';
                                },
                                list: [
                                    { value: 'none', name: 'None' },
                                    { value: '0 1px 3px 0 rgba(0, 0, 0, 0.1)', name: 'Small' },
                                    { value: '0 4px 6px -1px rgba(0, 0, 0, 0.1)', name: 'Medium' },
                                    { value: '0 10px 15px -3px rgba(0, 0, 0, 0.1)', name: 'Large' },
                                    { value: '0 20px 25px -5px rgba(0, 0, 0, 0.1)', name: 'X-Large' },
                                    { value: '0 0 0 3px rgba(66, 153, 225, 0.5)', name: 'Outline Blue' },
                                    { value: '0 0 15px rgba(0, 0, 0, 0.2)', name: 'Glow' },
                                    { value: 'inset 0 2px 4px 0 rgba(0, 0, 0, 0.06)', name: 'Inner' }
                                ]
                            }
                        ]
                    },
                    {
                        name: 'Effects',
                        open: false,
                        properties: [
                            {
                                name: 'Transition',
                                property: 'transition',
                                type: 'stack',
                                list: [
                                    { value: 'all 0.3s ease', name: 'Fast' },
                                    { value: 'all 0.5s ease', name: 'Normal' },
                                    { value: 'all 0.8s ease', name: 'Slow' },
                                    { value: 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)', name: 'Smooth' }
                                ]
                            },
                            'perspective',
                            {
                                name: 'Transform',
                                property: 'transform',
                                type: 'composite',
                                properties: [
                                    { name: 'Rotate', property: 'rotate', type: 'integer', units: ['deg'], defaults: '0' },
                                    { name: 'Scale X', property: 'scale-x', type: 'number', defaults: '1' },
                                    { name: 'Scale Y', property: 'scale-y', type: 'number', defaults: '1' }
                                ]
                            },
                            {
                                name: 'Filter',
                                property: 'filter',
                                type: 'composite',
                                properties: [
                                    { name: 'Blur', property: 'blur', type: 'integer', units: ['px'], defaults: '0' },
                                    { name: 'Brightness', property: 'brightness', type: 'integer', units: ['%'], defaults: '100' },
                                    { name: 'Contrast', property: 'contrast', type: 'integer', units: ['%'], defaults: '100' },
                                    { name: 'Grayscale', property: 'grayscale', type: 'integer', units: ['%'], defaults: '0' },
                                    { name: 'Hue Rotate', property: 'hue-rotate', type: 'integer', units: ['deg'], defaults: '0' },
                                    { name: 'Saturate', property: 'saturate', type: 'integer', units: ['%'], defaults: '100' }
                                ]
                            }
                        ]
                    },
                    {
                        name: 'Flex',
                        open: false,
                        properties: [
                            'flex-direction',
                            'flex-wrap',
                            'justify-content',
                            'align-items',
                            'align-content',
                            'order',
                            'flex-basis',
                            'flex-grow',
                            'flex-shrink',
                            'align-self'
                        ]
                    }
                ]
            },

            // Layer Manager
            layerManager: {
                appendTo: '#metasync-editor-layers'
            },

            // Traits Manager - for editing element properties
            traitManager: {
                appendTo: '#metasync-editor-traits'
            },

            // Panels
            panels: {
                defaults: [
                    {
                        id: 'basic-actions',
                        el: '.gjs-pn-options',
                        buttons: [
                            {
                                id: 'visibility',
                                active: true,
                                className: 'btn-toggle-borders',
                                label: '<span class="dashicons dashicons-editor-table"></span>',
                                command: 'sw-visibility'
                            }
                        ]
                    },
                    {
                        id: 'panel-devices',
                        el: '.gjs-pn-devices',
                        buttons: [
                            {
                                id: 'device-desktop',
                                label: '<span class="dashicons dashicons-desktop"></span>',
                                command: 'set-device-desktop',
                                active: true,
                                togglable: false
                            },
                            {
                                id: 'device-tablet',
                                label: '<span class="dashicons dashicons-tablet"></span>',
                                command: 'set-device-tablet',
                                togglable: false
                            },
                            {
                                id: 'device-mobile',
                                label: '<span class="dashicons dashicons-smartphone"></span>',
                                command: 'set-device-mobile',
                                togglable: false
                            }
                        ]
                    }
                ]
            },

            // Device Manager
            deviceManager: {
                devices: [
                    {
                        name: 'Desktop',
                        width: ''
                    },
                    {
                        name: 'Tablet',
                        width: '768px',
                        widthMedia: '992px'
                    },
                    {
                        name: 'Mobile',
                        width: '375px',
                        widthMedia: '480px'
                    }
                ]
            },

            // Selector Manager
            selectorManager: {
                appendTo: '#metasync-editor-selectors'
            }
        });

        // Each manager was handed its own `appendTo` target above, so GrapesJS
        // renders the panels into the sidebar itself. Nothing is re-parented
        // here, and no sector is registered a second time: the container is
        // emptied on init, so anything appended to it by hand is discarded.

        // Load HTML content
        editor.setComponents(htmlContent);

        // Extract and load styles
        const styleMatch = htmlContent.match(/<style[^>]*>([\s\S]*?)<\/style>/gi);
        if (styleMatch) {
            let allStyles = '';
            styleMatch.forEach(style => {
                allStyles += style.replace(/<\/?style[^>]*>/gi, '');
            });
            editor.setStyle(allStyles);
        }

        // Enhance component types with better traits
        editor.DomComponents.addType('text', {
            model: {
                defaults: {
                    traits: [
                        {
                            type: 'text',
                            name: 'content',
                            label: 'Text Content',
                            changeProp: 1
                        }
                    ]
                }
            }
        });

        editor.DomComponents.addType('link', {
            model: {
                defaults: {
                    traits: [
                        {
                            type: 'text',
                            name: 'href',
                            label: 'URL',
                            placeholder: 'https://example.com'
                        },
                        {
                            type: 'text',
                            name: 'title',
                            label: 'Title'
                        },
                        {
                            type: 'select',
                            name: 'target',
                            label: 'Target',
                            options: [
                                { value: '', name: 'Same Window' },
                                { value: '_blank', name: 'New Window' },
                                { value: '_parent', name: 'Parent Frame' },
                                { value: '_top', name: 'Top Frame' }
                            ]
                        }
                    ]
                }
            }
        });

        editor.DomComponents.addType('image', {
            model: {
                defaults: {
                    traits: [
                        {
                            type: 'text',
                            name: 'src',
                            label: 'Image URL',
                            placeholder: 'https://example.com/image.jpg'
                        },
                        {
                            type: 'text',
                            name: 'alt',
                            label: 'Alt Text',
                            placeholder: 'Image description'
                        },
                        {
                            type: 'text',
                            name: 'title',
                            label: 'Title'
                        }
                    ]
                }
            }
        });

        // Add device switching commands
        editor.Commands.add('set-device-desktop', {
            run: function(editor) {
                editor.setDevice('Desktop');
            }
        });

        editor.Commands.add('set-device-tablet', {
            run: function(editor) {
                editor.setDevice('Tablet');
            }
        });

        editor.Commands.add('set-device-mobile', {
            run: function(editor) {
                editor.setDevice('Mobile');
            }
        });

        // Panel-switching commands.
        //
        // Each manager renders into its own sidebar mount point, so switching
        // is purely a matter of revealing the right panel. These used to also
        // call render() on the manager and toggle GrapesJS panel classes,
        // which fought with the editor's own rendering.
        SIDEBAR_PANELS.forEach(function(panel) {
            editor.Commands.add(panel.command, {
                run: function() {
                    showPanel(panel.id);
                }
            });
        });

        // Build the sidebar's panel switcher, now that the commands its
        // buttons run are registered.
        //
        // These were previously GrapesJS panel buttons that a timer tried to
        // re-parent into the sidebar. The buttons are plain markup in the
        // sidebar now, so they cannot be detached by the editor re-rendering
        // its own panels.
        buildPanelSwitcher();

        // When an element is selected, automatically show the Styles panel
        editor.on('component:selected', function(component) {
            // Name the selected element in the sidebar. The indicator is part
            // of the page template, so it does not need to be injected here.
            const elementType = component.get('type') || 'div';
            const elementName = component.getName() || elementType;
            const indicator = document.getElementById('metasync-selected-element');

            if (indicator) {
                indicator.hidden = false;
            }

            $('#metasync-element-name').text(elementName.charAt(0).toUpperCase() + elementName.slice(1));

            // Automatically switch to Styles panel when selecting an element
            editor.runCommand('show-styles');
        });

        // Hide indicator when no element is selected
        editor.on('component:deselected', function() {
            const indicator = document.getElementById('metasync-selected-element');

            if (indicator) {
                indicator.hidden = true;
            }
        });

        // Track changes
        editor.on('change:changesCount', function() {
            hasUnsavedChanges = true;
            updateStatus('unsaved');
        });

        // Upload failures are rejected by the assetManager's customFetch and
        // surface here. Tell the user instead of failing silently.
        editor.on('asset:upload:error', function (error) {
            var message = error && error.data && error.data.message;
            showNotice(message || t('upload_failed', 'Image upload failed'));
        });
    }

    /**
     * Initialize event handlers
     */
    function initializeEventHandlers() {
        // Save button
        $('.metasync-save-button').on('click', saveHTML);

        // Preview button
        $('.metasync-preview-button').on('click', openPreview);

        // Keyboard shortcuts
        $(document).on('keydown', function(e) {
            // Ctrl/Cmd + S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                saveHTML();
            }
        });
    }

    /**
     * Save HTML via AJAX
     */
    function saveHTML() {
        // The save button is disabled after a fatal load, but the Ctrl/Cmd+S
        // binding still fires — there is no editor to read from then.
        if (!editor) {
            return;
        }

        // Same reasoning for a page the canvas cannot round-trip: the button is
        // disabled, but the keyboard shortcut would otherwise still overwrite
        // the stored document with the canvas's reduced copy of it.
        if (metasyncEditor.blocked_reason) {
            showBlockedNotice();
            return;
        }

        const $button = $('.metasync-save-button');
        const originalText = $button.text();

        // Get HTML and CSS from editor
        const html = editor.getHtml();
        const css = editor.getCss();

        // Combine HTML with CSS
        let fullHTML = html;
        if (css) {
            fullHTML = `<style>${css}</style>\n${html}`;
        }

        // Update button state
        $button.prop('disabled', true).text(t('saving', 'Saving...'));
        updateStatus('saving');

        // Send AJAX request
        $.ajax({
            url: metasyncEditor.ajax_url,
            type: 'POST',
            data: {
                action: 'metasync_save_html',
                nonce: metasyncEditor.nonce,
                post_id: metasyncEditor.post_id,
                html: fullHTML
            },
            success: function(response) {
                if (response.success) {
                    hasUnsavedChanges = false;
                    updateStatus('saved');
                    $button.text(t('saved', 'Saved!'));

                    setTimeout(function() {
                        $button.text(originalText);
                        updateStatus('ready');
                    }, 2000);
                } else {
                    showNotice((response.data && response.data.message) || t('error', 'Error saving'));
                    updateStatus('error');
                }
            },
            error: function(xhr) {
                // An expired nonce answers "-1" (or "0" logged-out) outside
                // the JSON envelope; anything else is a real failure. The
                // session-expired message warns that reloading will lose the
                // canvas, which the generic error message does not.
                var body = xhr && xhr.responseText;
                if (body === '-1' || body === '0') {
                    showNotice(t('session_expired', 'Your session has expired. Copy your work before reloading the page.'));
                } else {
                    var message = null;
                    try {
                        message = JSON.parse(body).data.message;
                    } catch (parseError) {
                        message = null;
                    }
                    showNotice(message || t('error', 'Error saving'));
                }
                updateStatus('error');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    }

    /**
     * Open preview in new tab
     */
    function openPreview() {
        if (hasUnsavedChanges) {
            if (!window.confirm(t('confirm_preview', 'You have unsaved changes. Preview will show the last saved version. Continue?'))) {
                return;
            }
        }
        window.open(metasyncEditor.preview_url, '_blank');
    }

    /**
     * Update status indicator
     */
    function updateStatus(status) {
        const $indicator = $('.metasync-status-indicator');
        const $text = $('.metasync-status-text');

        $indicator.removeClass('unsaved saving saved error ready');
        $indicator.addClass(status);

        const statusText = {
            ready: t('ready', 'Ready'),
            unsaved: t('unsaved_changes', 'Unsaved changes'),
            saving: t('saving', 'Saving...'),
            saved: t('saved', 'Saved!'),
            error: t('error', 'Error saving')
        };

        $text.text(statusText[status] || t('ready', 'Ready'));
    }

    /**
     * Prevent accidental exit with unsaved changes
     */
    function preventAccidentalExit() {
        $(window).on('beforeunload', function(e) {
            // Nothing on a blocked page can be saved, so warning about unsaved
            // changes would only trap the user on a page they were told to
            // leave for the lossless editor.
            if (hasUnsavedChanges && !metasyncEditor.blocked_reason) {
                const message = metasyncEditor.i18n.confirm_exit;
                e.returnValue = message;
                return message;
            }
        });
    }

})(jQuery);

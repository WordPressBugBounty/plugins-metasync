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

    console.log('MetaSync HTML Editor script loaded');
    console.log('GrapesJS available:', typeof grapesjs !== 'undefined');
    console.log('jQuery available:', typeof $ !== 'undefined');

    let editor;
    let hasUnsavedChanges = false;

    $(document).ready(function() {
        console.log('Document ready, initializing editor...');
        initializeEditor();
        initializeEventHandlers();
        preventAccidentalExit();
    });

    /**
     * Initialize GrapesJS editor
     */
    function initializeEditor() {
        const htmlContent = $('#metasync-html-content').val();

        editor = grapesjs.init({
            container: '#metasync-gjs-editor',
            fromElement: false,
            height: 'calc(100vh - 112px)',
            width: 'auto',
            storageManager: false, // Disable built-in storage

            // Plugins
            plugins: ['gjs-blocks-basic'],
            pluginsOpts: {
                'gjs-blocks-basic': {}
            },

            // Enable double-click to edit text
            allowScripts: 0,
            showOffsets: 1,
            noticeOnUnload: 0,

            // Make text components editable
            richTextEditor: {
                actions: ['bold', 'italic', 'underline', 'strikethrough', 'link']
            },

            // Canvas settings
            canvas: {
                styles: [],
                scripts: []
            },

            // Block Manager
            blockManager: {
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
            layerManager: {},

            // Traits Manager - for editing element properties
            traitManager: {},

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
                                label: '<i class="fa fa-clone"></i>',
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
                                label: '<i class="fa fa-television"></i>',
                                command: 'set-device-desktop',
                                active: true,
                                togglable: false
                            },
                            {
                                id: 'device-tablet',
                                label: '<i class="fa fa-tablet"></i>',
                                command: 'set-device-tablet',
                                togglable: false
                            },
                            {
                                id: 'device-mobile',
                                label: '<i class="fa fa-mobile"></i>',
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
                appendTo: ''
            }
        });

        // Create and show the right sidebar panel container
        const editorEl = editor.getContainer();
        let viewsContainer = editorEl.querySelector('.gjs-pn-views-container');

        if (!viewsContainer) {
            viewsContainer = document.createElement('div');
            viewsContainer.className = 'gjs-pn-views-container';
            editorEl.appendChild(viewsContainer);

            const viewsInner = document.createElement('div');
            viewsInner.className = 'gjs-pn-views';
            viewsContainer.appendChild(viewsInner);
        }

        // Add sectors to Style Manager first
        const sm = editor.StyleManager;
        sm.addSector('colors', {
            name: 'Colors',
            open: true,
            properties: [
                {
                    name: 'Text Color',
                    property: 'color',
                    type: 'color'
                },
                {
                    name: 'Background Color',
                    property: 'background-color',
                    type: 'color'
                },
                {
                    name: 'Border Color',
                    property: 'border-color',
                    type: 'color'
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
        });

        sm.addSector('typography', {
            name: 'Typography',
            open: false,
            properties: [
                'font-family',
                'font-size',
                'font-weight',
                'letter-spacing',
                'line-height',
                'text-align'
            ]
        });

        sm.addSector('decorations', {
            name: 'Decorations',
            open: false,
            properties: [
                'border-radius',
                'border',
                'box-shadow'
            ]
        });

        sm.addSector('dimensions', {
            name: 'Dimensions',
            open: false,
            properties: [
                'width',
                'height',
                'max-width',
                'min-width',
                'padding',
                'margin'
            ]
        });

        console.log('Style Manager sectors added:', sm.getSectors().length);

        // Render the Style Manager immediately after adding sectors
        const smEl = sm.render().el;
        console.log('Style Manager rendered, sectors in element:', $(smEl).find('.gjs-sm-sector').length);

        // Append panels to the container
        setTimeout(function() {
            const $views = $('.gjs-pn-views');
            if ($views.length) {
                console.log('Views container found, appending panels...');

                // Append already-rendered Style Manager
                $(smEl).show().css({'display': 'block', 'visibility': 'visible'});
                $views.append(smEl);
                console.log('Style Manager appended to sidebar');

                // Append Trait Manager (initially hidden)
                const tmEl = editor.TraitManager.render().el;
                $(tmEl).hide();
                $views.append(tmEl);
                console.log('Trait Manager appended');

                // Append Layer Manager (initially hidden)
                const lmEl = editor.LayerManager.render().el;
                $(lmEl).hide();
                $views.append(lmEl);
                console.log('Layer Manager appended');

                // Append Block Manager (initially hidden)
                const bmEl = editor.BlockManager.render().el;
                $(bmEl).hide();
                $views.append(bmEl);
                console.log('Block Manager appended');

                console.log('All panels appended to sidebar');
            } else {
                console.error('Views container not found!');
            }
        }, 300);

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

        // Add panel switcher buttons after editor initializes
        const panelManager = editor.Panels;
        const viewsPanel = panelManager.addPanel({
            id: 'panel-switcher'
        });

        viewsPanel.get('buttons').add([
            {
                id: 'show-style',
                active: true,
                label: '<i class="fa fa-paint-brush"></i><div class="gjs-pn-label">Styles</div>',
                command: 'show-styles',
                togglable: false
            },
            {
                id: 'show-traits',
                label: '<i class="fa fa-cog"></i><div class="gjs-pn-label">Settings</div>',
                command: 'show-traits',
                togglable: false
            },
            {
                id: 'show-layers',
                label: '<i class="fa fa-bars"></i><div class="gjs-pn-label">Layers</div>',
                command: 'show-layers',
                togglable: false
            },
            {
                id: 'show-blocks',
                label: '<i class="fa fa-th-large"></i><div class="gjs-pn-label">Blocks</div>',
                command: 'show-blocks',
                togglable: false
            }
        ]);

        // Move panel to the right sidebar
        setTimeout(function() {
            const $viewsContainer = $('.gjs-pn-views');
            if ($viewsContainer.length) {
                $('#panel-switcher').prependTo($viewsContainer);
            }
        }, 100);

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

        // Add custom commands for panel switching
        editor.Commands.add('show-styles', {
            run: function(editor) {
                const pnl = editor.Panels.getPanel('panel-switcher');
                if (pnl) {
                    pnl.get('buttons').each(function(btn) {
                        btn.set('active', btn.id === 'show-style');
                    });
                }

                const sm = editor.StyleManager;
                const tm = editor.TraitManager;
                const lm = editor.LayerManager;
                const bm = editor.BlockManager;

                sm.render();
                $('.gjs-pn-views .gjs-sm-sectors').show();
                $('.gjs-pn-views .gjs-trt-traits').hide();
                $('.gjs-pn-views .gjs-layers').hide();
                $('.gjs-pn-views .gjs-blocks-c').hide();
            }
        });

        editor.Commands.add('show-traits', {
            run: function(editor) {
                const pnl = editor.Panels.getPanel('panel-switcher');
                if (pnl) {
                    pnl.get('buttons').each(function(btn) {
                        btn.set('active', btn.id === 'show-traits');
                    });
                }

                const tm = editor.TraitManager;
                tm.render();
                $('.gjs-pn-views .gjs-sm-sectors').hide();
                $('.gjs-pn-views .gjs-trt-traits').show();
                $('.gjs-pn-views .gjs-layers').hide();
                $('.gjs-pn-views .gjs-blocks-c').hide();
            }
        });

        editor.Commands.add('show-layers', {
            run: function(editor) {
                const pnl = editor.Panels.getPanel('panel-switcher');
                if (pnl) {
                    pnl.get('buttons').each(function(btn) {
                        btn.set('active', btn.id === 'show-layers');
                    });
                }

                const lm = editor.LayerManager;
                lm.render();
                $('.gjs-pn-views .gjs-sm-sectors').hide();
                $('.gjs-pn-views .gjs-trt-traits').hide();
                $('.gjs-pn-views .gjs-layers').show();
                $('.gjs-pn-views .gjs-blocks-c').hide();
            }
        });

        editor.Commands.add('show-blocks', {
            run: function(editor) {
                const pnl = editor.Panels.getPanel('panel-switcher');
                if (pnl) {
                    pnl.get('buttons').each(function(btn) {
                        btn.set('active', btn.id === 'show-blocks');
                    });
                }

                const bm = editor.BlockManager;
                bm.render();
                $('.gjs-pn-views .gjs-sm-sectors').hide();
                $('.gjs-pn-views .gjs-trt-traits').hide();
                $('.gjs-pn-views .gjs-layers').hide();
                $('.gjs-pn-views .gjs-blocks-c').show();
            }
        });

        // Add selected element indicator
        setTimeout(function() {
            const $viewsContainer = $('.gjs-pn-views');
            if ($viewsContainer.length) {
                $viewsContainer.prepend('<div id="metasync-selected-element" style="display: none; padding: 16px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #ffffff; font-size: 13px; font-weight: 600; border-bottom: 1px solid #1a1e23;"><div style="font-size: 11px; opacity: 0.8; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Editing Element</div><div id="metasync-element-name"></div></div>');
            }
        }, 200);

        // When an element is selected, automatically show the Styles panel
        editor.on('component:selected', function(component) {
            // Show selected element indicator
            const elementType = component.get('type') || 'div';
            const elementName = component.getName() || elementType;
            $('#metasync-selected-element').show();
            $('#metasync-element-name').text(elementName.charAt(0).toUpperCase() + elementName.slice(1));

            // Automatically switch to Styles panel when selecting an element
            editor.runCommand('show-styles');

            // Open the Colors section by default
            setTimeout(function() {
                const $colorsSector = $('.gjs-sm-sector').first();
                if ($colorsSector.length && !$colorsSector.hasClass('gjs-sm-open')) {
                    $colorsSector.find('.gjs-sm-sector-title').click();
                }
            }, 100);
        });

        // Hide indicator when no element is selected
        editor.on('component:deselected', function() {
            $('#metasync-selected-element').hide();
        });

        // Track changes
        editor.on('change:changesCount', function() {
            hasUnsavedChanges = true;
            updateStatus('unsaved');
        });

        // Custom image upload
        editor.on('asset:upload:start', handleImageUpload);

        // Force render all managers to ensure panels appear
        setTimeout(function() {
            editor.StyleManager.render();
            editor.TraitManager.render();
            editor.LayerManager.render();
            editor.BlockManager.render();

            // Show the views container
            $('.gjs-pn-views-container, .gjs-pn-views').show().css({
                'display': 'block',
                'visibility': 'visible'
            });

            // Initialize with styles panel
            editor.runCommand('show-styles');

            console.log('Panels rendered and displayed');
        }, 500);

        console.log('MetaSync HTML Editor initialized');
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
        $button.prop('disabled', true).text(metasyncEditor.i18n.saving);
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
                    $button.text(metasyncEditor.i18n.saved);

                    setTimeout(function() {
                        $button.text(originalText);
                        updateStatus('ready');
                    }, 2000);
                } else {
                    alert(response.data.message || metasyncEditor.i18n.error);
                    updateStatus('error');
                }
            },
            error: function() {
                alert(metasyncEditor.i18n.error);
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
            if (!confirm('You have unsaved changes. Preview will show the last saved version. Continue?')) {
                return;
            }
        }
        window.open(metasyncEditor.preview_url, '_blank');
    }

    /**
     * Handle image upload
     */
    function handleImageUpload(e) {
        const file = e.target.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('action', 'metasync_upload_image');
        formData.append('nonce', metasyncEditor.nonce);
        formData.append('file', file);

        $.ajax({
            url: metasyncEditor.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    editor.AssetManager.add({ src: response.data.url });
                } else {
                    alert(response.data.message || 'Upload failed');
                }
            }
        });
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
            ready: 'Ready',
            unsaved: 'Unsaved changes',
            saving: 'Saving...',
            saved: 'Saved!',
            error: 'Error'
        };

        $text.text(statusText[status] || 'Ready');
    }

    /**
     * Prevent accidental exit with unsaved changes
     */
    function preventAccidentalExit() {
        $(window).on('beforeunload', function(e) {
            if (hasUnsavedChanges) {
                const message = metasyncEditor.i18n.confirm_exit;
                e.returnValue = message;
                return message;
            }
        });
    }

})(jQuery);

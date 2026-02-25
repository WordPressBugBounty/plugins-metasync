/**
 * MetaSync SEO Sidebar for Gutenberg Block Editor
 *
 * Provides SEO Title and Meta Description inputs directly in the post editor sidebar.
 *
 * @package MetaSync
 * @since 2.7.0
 */

(function(wp) {
    'use strict';

    const { registerPlugin } = wp.plugins;
    const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost;
    const { PanelBody, TextControl, TextareaControl, Button, ButtonGroup } = wp.components;
    const { useSelect, useDispatch } = wp.data;
    const { useState, useEffect, createElement: el } = wp.element;
    const { __ } = wp.i18n;

    // Get configuration from PHP
    const config = window.metasyncSeoSidebar || {
        iconUrl: '',
        metaKeys: {
            seoTitle: '_metasync_seo_title',
            metaDescription: '_metasync_seo_desc',
            // OTTO keys for fallback (read-only, used to prefill if manual fields are empty)
            ottoTitle: '_metasync_otto_title',
            ottoDescription: '_metasync_otto_description',
            // OTTO disabled per-post flag
            ottoDisabled: '_metasync_otto_disabled',
        },
        hasMetaKeys: {
            // Whether the manual meta keys exist in database (from PHP check)
            seoTitle: false,
            metaDescription: false,
        },
        otto: {
            globalEnabled: false,
            name: 'OTTO',
        },
        limits: {
            seoTitle: { min: 50, max: 60, absolute: 70 },
            metaDescription: { min: 120, max: 160, absolute: 200 },
        },
        i18n: {
            panelTitle: 'MetaSync SEO',
            seoTitleLabel: 'SEO Title',
            seoTitleHelp: 'The title that appears in search engine results. Optimal length: 50-60 characters.',
            metaDescriptionLabel: 'Meta Description',
            metaDescriptionHelp: 'A brief description for search engine results. Optimal length: 120-160 characters.',
            urlSlugLabel: 'URL Slug',
            urlSlugHelp: 'The URL-friendly version of the post name. Use lowercase letters, numbers, and hyphens only.',
            serpPreviewTitle: 'SERP Preview',
            serpPreviewHelp: 'Preview how your page will appear in Google search results.',
            serpDesktop: 'Desktop',
            serpMobile: 'Mobile',
            characters: 'characters',
            ottoPrefillHelp: 'Pre-filled from OTTO. Edit to customize.',
            ottoOverrideNotice: 'OTTO is enabled. Any SEO title and description changes from OTTO will be overwritten by your custom values entered here.',
        },
    };

    /**
     * Character Counter Component
     * Displays character count with color-coded indicator
     */
    const CharacterCounter = ({ count, min, max, absolute }) => {
        let status = 'optimal';
        let statusColor = '#00a32a'; // Green

        if (count === 0) {
            status = 'empty';
            statusColor = '#757575'; // Gray
        } else if (count < min) {
            status = 'short';
            statusColor = '#dba617'; // Yellow/Orange
        } else if (count > absolute) {
            status = 'too-long';
            statusColor = '#d63638'; // Red
        } else if (count > max) {
            status = 'long';
            statusColor = '#dba617'; // Yellow/Orange
        }

        const progressWidth = Math.min((count / absolute) * 100, 100);
        
        return el('div', { className: 'metasync-char-counter' },
            el('div', { className: 'metasync-char-counter-bar' },
                el('div', {
                    className: 'metasync-char-counter-progress',
                    style: {
                        width: progressWidth + '%',
                        backgroundColor: statusColor,
                    },
                })
            ),
            el('span', {
                className: 'metasync-char-counter-text',
                style: { color: statusColor },
            }, count + ' ' + config.i18n.characters)
        );
    };

    /**
     * OTTO Override Notice Component
     * Shows an informational notice when OTTO is enabled (globally + per-post) and user has custom values
     * Informs user that their custom values will take priority over OTTO
     */
    const OttoOverrideNotice = () => {
        // Check if OTTO is globally enabled
        const isOttoGloballyEnabled = config.otto.globalEnabled;

        // Get custom values and per-post OTTO disabled status
        const { hasCustomTitle, hasCustomDescription, isOttoDisabledForPost } = useSelect((select) => {
            const meta = select('core/editor').getEditedPostAttribute('meta') || {};
            const ottoDisabledValue = meta[config.metaKeys.ottoDisabled] || '';
            return {
                hasCustomTitle: !!(meta[config.metaKeys.seoTitle] || '').trim(),
                hasCustomDescription: !!(meta[config.metaKeys.metaDescription] || '').trim(),
                isOttoDisabledForPost: ottoDisabledValue === '1' || ottoDisabledValue === 'true',
            };
        }, []);

        // OTTO is active for this post if: globally enabled AND not disabled per-post
        const isOttoActiveForPost = isOttoGloballyEnabled && !isOttoDisabledForPost;

        // Only show notice if OTTO is active for this post AND user has custom values
        if (!isOttoActiveForPost || (!hasCustomTitle && !hasCustomDescription)) {
            return null;
        }

        return el('div', { className: 'metasync-otto-override-notice' },
            el('div', { className: 'metasync-otto-notice-icon' },
                el('svg', {
                    width: 20,
                    height: 20,
                    viewBox: '0 0 24 24',
                    fill: 'none',
                    xmlns: 'http://www.w3.org/2000/svg',
                },
                    el('path', {
                        d: 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z',
                        fill: 'currentColor',
                    })
                )
            ),
            el('div', { className: 'metasync-otto-notice-content' },
                el('strong', null, __('Custom Values Active', 'metasync')),
                el('p', null, config.i18n.ottoOverrideNotice)
            )
        );
    };

    /**
     * SEO Title Input Component
     * Falls back to OTTO title only if manual field has never been set
     */
    const SeoTitleInput = () => {
        const metaKey = config.metaKeys.seoTitle;
        const ottoKey = config.metaKeys.ottoTitle;
        const limits = config.limits.seoTitle;

        // Get both manual and OTTO values
        // Use PHP-provided hasMetaKeys to check if meta key exists in database
        const { manualValue, ottoValue } = useSelect((select) => {
            const meta = select('core/editor').getEditedPostAttribute('meta') || {};
            return {
                manualValue: meta[metaKey] || '',
                ottoValue: meta[ottoKey] || '',
            };
        }, [metaKey, ottoKey]);

        const { editPost } = useDispatch('core/editor');

        // Track if user has edited this field during this session
        const [hasBeenEdited, setHasBeenEdited] = useState(false);

        // Check if meta key exists in database (from PHP check)
        const hasMetaKeyInDb = config.hasMetaKeys.seoTitle;

        // Display value logic:
        // - If user has edited during this session, show manual value (even if empty)
        // - If manual value exists in database (even empty), show it
        // - Otherwise show OTTO as prefill
        const shouldShowOttoFallback = !hasBeenEdited && !hasMetaKeyInDb && ottoValue;
        const displayValue = shouldShowOttoFallback ? ottoValue : (manualValue || '');
        
        // Track if showing OTTO value (for visual indicator)
        const isOttoValue = shouldShowOttoFallback;

        const handleChange = (value) => {
            // Mark as edited so we don't fallback to OTTO anymore
            setHasBeenEdited(true);
            // Always save to manual field
            editPost({ meta: { [metaKey]: value } });
        };

        return el('div', { className: 'metasync-seo-field' },
            el(TextControl, {
                label: config.i18n.seoTitleLabel,
                value: displayValue,
                onChange: handleChange,
                help: isOttoValue 
                    ? config.i18n.ottoPrefillHelp
                    : config.i18n.seoTitleHelp,
                placeholder: __('Enter SEO title...', 'metasync'),
                className: isOttoValue ? 'metasync-prefilled-otto' : '',
            }),
            el(CharacterCounter, {
                count: displayValue.length,
                min: limits.min,
                max: limits.max,
                absolute: limits.absolute,
            })
        );
    };

    /**
     * Meta Description Input Component
     * Falls back to OTTO description only if manual field has never been set
     */
    const MetaDescriptionInput = () => {
        const metaKey = config.metaKeys.metaDescription;
        const ottoKey = config.metaKeys.ottoDescription;
        const limits = config.limits.metaDescription;

        // Get both manual and OTTO values
        // Use PHP-provided hasMetaKeys to check if meta key exists in database
        const { manualValue, ottoValue } = useSelect((select) => {
            const meta = select('core/editor').getEditedPostAttribute('meta') || {};
            return {
                manualValue: meta[metaKey] || '',
                ottoValue: meta[ottoKey] || '',
            };
        }, [metaKey, ottoKey]);

        const { editPost } = useDispatch('core/editor');

        // Track if user has edited this field during this session
        const [hasBeenEdited, setHasBeenEdited] = useState(false);

        // Check if meta key exists in database (from PHP check)
        const hasMetaKeyInDb = config.hasMetaKeys.metaDescription;

        // Display value logic:
        // - If user has edited during this session, show manual value (even if empty)
        // - If manual value exists in database (even empty), show it
        // - Otherwise show OTTO as prefill
        const shouldShowOttoFallback = !hasBeenEdited && !hasMetaKeyInDb && ottoValue;
        const displayValue = shouldShowOttoFallback ? ottoValue : (manualValue || '');
        
        // Track if showing OTTO value (for visual indicator)
        const isOttoValue = shouldShowOttoFallback;

        const handleChange = (value) => {
            // Mark as edited so we don't fallback to OTTO anymore
            setHasBeenEdited(true);
            // Always save to manual field
            editPost({ meta: { [metaKey]: value } });
        };

        return el('div', { className: 'metasync-seo-field' },
            el(TextareaControl, {
                label: config.i18n.metaDescriptionLabel,
                value: displayValue,
                onChange: handleChange,
                help: isOttoValue 
                    ? config.i18n.ottoPrefillHelp
                    : config.i18n.metaDescriptionHelp,
                placeholder: __('Enter meta description...', 'metasync'),
                rows: 4,
                className: isOttoValue ? 'metasync-prefilled-otto' : '',
            }),
            el(CharacterCounter, {
                count: displayValue.length,
                min: limits.min,
                max: limits.max,
                absolute: limits.absolute,
            })
        );
    };

    /**
     * URL Slug Input Component
     * Syncs with WordPress native post slug (permalink)
     */
    const UrlSlugInput = () => {
        // Get the current post slug and permalink
        const { slug, link, postId } = useSelect((select) => {
            const editor = select('core/editor');
            return {
                slug: editor.getEditedPostAttribute('slug') || '',
                link: editor.getPermalink() || '',
                postId: editor.getCurrentPostId(),
            };
        }, []);

        const { editPost } = useDispatch('core/editor');

        /**
         * Sanitize slug to match WordPress permalink standards
         * - Convert to lowercase
         * - Replace spaces with hyphens
         * - Remove special characters except hyphens
         * - Remove multiple consecutive hyphens
         */
        const sanitizeSlug = (value) => {
            return value
                .toLowerCase()
                .replace(/\s+/g, '-')           // Replace spaces with hyphens
                .replace(/[^a-z0-9-]/g, '')     // Remove special characters
                .replace(/-+/g, '-')            // Replace multiple hyphens with single
                .replace(/^-|-$/g, '');         // Remove leading/trailing hyphens
        };

        const handleChange = (value) => {
            const sanitized = sanitizeSlug(value);
            editPost({ slug: sanitized });
        };

        // Extract base URL for preview (remove the slug part)
        const baseUrl = link ? link.replace(/[^/]+\/?$/, '') : '';

        return el('div', { className: 'metasync-seo-field metasync-url-slug-field' },
            el(TextControl, {
                label: config.i18n.urlSlugLabel,
                value: slug,
                onChange: handleChange,
                help: config.i18n.urlSlugHelp,
                placeholder: __('enter-url-slug', 'metasync'),
            }),
            // Show permalink preview
            link && el('div', { className: 'metasync-permalink-preview' },
                el('span', { className: 'metasync-permalink-label' }, __('Permalink:', 'metasync') + ' '),
                el('a', { 
                    href: link, 
                    target: '_blank',
                    rel: 'noopener noreferrer',
                    className: 'metasync-permalink-link',
                }, 
                    el('span', { className: 'metasync-permalink-base' }, baseUrl),
                    el('strong', { className: 'metasync-permalink-slug' }, slug || __('(auto-generated)', 'metasync'))
                )
            )
        );
    };

    /**
     * SERP Preview Component
     * Shows a real-time preview of how the page will appear in Google search results
     */
    const SerpPreview = () => {
        const [viewMode, setViewMode] = useState('desktop'); // 'desktop' or 'mobile'

        // Get all the data needed for the preview (with OTTO fallbacks)
        const { seoTitle, metaDescription, postTitle, permalink, excerpt } = useSelect((select) => {
            const editor = select('core/editor');
            const meta = editor.getEditedPostAttribute('meta') || {};
            
            // Get manual values first, then fall back to OTTO values
            const manualTitle = meta[config.metaKeys.seoTitle] || '';
            const ottoTitle = meta[config.metaKeys.ottoTitle] || '';
            const manualDesc = meta[config.metaKeys.metaDescription] || '';
            const ottoDesc = meta[config.metaKeys.ottoDescription] || '';
            
            return {
                seoTitle: manualTitle || ottoTitle,  // Manual > OTTO
                metaDescription: manualDesc || ottoDesc,  // Manual > OTTO
                postTitle: editor.getEditedPostAttribute('title') || '',
                permalink: editor.getPermalink() || '',
                excerpt: editor.getEditedPostAttribute('excerpt') || '',
            };
        }, []);

        // Determine display values with fallbacks
        const displayTitle = seoTitle || postTitle || __('Page Title', 'metasync');
        const displayDescription = metaDescription || excerpt || __('Add a meta description to control how your page appears in search results.', 'metasync');
        
        // Format URL for display (remove protocol, truncate if needed)
        const formatUrl = (url) => {
            if (!url) return 'example.com';
            try {
                const urlObj = new URL(url);
                let displayUrl = urlObj.hostname + urlObj.pathname;
                // Remove trailing slash
                displayUrl = displayUrl.replace(/\/$/, '');
                return displayUrl;
            } catch (e) {
                return url;
            }
        };

        // Truncate text with ellipsis
        const truncate = (text, maxLength) => {
            if (!text) return '';
            if (text.length <= maxLength) return text;
            return text.substring(0, maxLength).trim() + '...';
        };

        // Get display limits based on view mode
        const titleLimit = viewMode === 'mobile' ? 55 : 60;
        const descLimit = viewMode === 'mobile' ? 120 : 160;

        const displayUrl = formatUrl(permalink);
        const truncatedTitle = truncate(displayTitle, titleLimit);
        const truncatedDescription = truncate(displayDescription, descLimit);

        // Generate breadcrumb from URL
        const getBreadcrumbs = (url) => {
            if (!url) return [];
            try {
                const urlObj = new URL(url);
                const pathParts = urlObj.pathname.split('/').filter(p => p);
                if (pathParts.length === 0) return [urlObj.hostname];
                return [urlObj.hostname, ...pathParts.slice(0, -1)];
            } catch (e) {
                return [];
            }
        };

        const breadcrumbs = getBreadcrumbs(permalink);

        return el('div', { className: 'metasync-serp-preview' },
            // Header with toggle
            el('div', { className: 'metasync-serp-header' },
                el('span', { className: 'metasync-serp-label' }, config.i18n.serpPreviewTitle),
                el(ButtonGroup, { className: 'metasync-serp-toggle' },
                    el(Button, {
                        isPrimary: viewMode === 'desktop',
                        isSecondary: viewMode !== 'desktop',
                        isSmall: true,
                        onClick: () => setViewMode('desktop'),
                        'aria-label': config.i18n.serpDesktop,
                    }, 
                        el('span', { className: 'dashicons dashicons-desktop' }),
                        ' ',
                        config.i18n.serpDesktop
                    ),
                    el(Button, {
                        isPrimary: viewMode === 'mobile',
                        isSecondary: viewMode !== 'mobile',
                        isSmall: true,
                        onClick: () => setViewMode('mobile'),
                        'aria-label': config.i18n.serpMobile,
                    },
                        el('span', { className: 'dashicons dashicons-smartphone' }),
                        ' ',
                        config.i18n.serpMobile
                    )
                )
            ),
            
            // Google-style preview
            el('div', { 
                className: 'metasync-serp-result ' + (viewMode === 'mobile' ? 'metasync-serp-mobile' : 'metasync-serp-desktop')
            },
                // Favicon and URL
                el('div', { className: 'metasync-serp-url-row' },
                    el('div', { className: 'metasync-serp-favicon' },
                        el('div', { className: 'metasync-serp-favicon-placeholder' })
                    ),
                    el('div', { className: 'metasync-serp-url-info' },
                        el('span', { className: 'metasync-serp-site-name' }, 
                            breadcrumbs[0] || 'example.com'
                        ),
                        el('span', { className: 'metasync-serp-breadcrumb' }, 
                            breadcrumbs.length > 1 ? ' › ' + breadcrumbs.slice(1).join(' › ') : ''
                        )
                    )
                ),
                
                // Title
                el('div', { className: 'metasync-serp-title' }, truncatedTitle),
                
                // Description
                el('div', { className: 'metasync-serp-description' }, truncatedDescription)
            ),
            
            // Help text
            el('p', { className: 'metasync-serp-help' }, config.i18n.serpPreviewHelp)
        );
    };

    /**
     * MetaSync SEO Sidebar Icon
     * Uses external SVG from admin/images/icon-256x256.svg
     */
    const MetaSyncIcon = config.iconUrl 
        ? el('img', {
            src: config.iconUrl,
            alt: 'MetaSync SEO',
            width: 20,
            height: 20,
            style: { 
                display: 'block',
                objectFit: 'contain',
            },
        })
        : el('svg', {
            width: 20,
            height: 20,
            viewBox: '0 0 24 24',
            fill: 'none',
            xmlns: 'http://www.w3.org/2000/svg',
        },
            el('path', {
                d: 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z',
                fill: 'currentColor',
            })
        );

    /**
     * Main Sidebar Component
     */
    const MetaSyncSeoSidebar = () => {
        return el(PluginSidebar, {
            name: 'metasync-seo-sidebar',
            title: config.i18n.panelTitle,
            icon: MetaSyncIcon,
        },
            el('div', { className: 'metasync-seo-sidebar-content' },
                // Show notice when user has custom values and OTTO is enabled
                el(OttoOverrideNotice, null),
                el(PanelBody, {
                    title: config.i18n.panelTitle,
                    initialOpen: true,
                },
                    el(SeoTitleInput, null),
                    el(MetaDescriptionInput, null),
                    el(UrlSlugInput, null)
                ),
                el(PanelBody, {
                    title: config.i18n.serpPreviewTitle,
                    initialOpen: true,
                },
                    el(SerpPreview, null)
                )
            )
        );
    };

    /**
     * Sidebar Menu Item Component
     */
    const MetaSyncSeoMenuItem = () => {
        return el(PluginSidebarMoreMenuItem, {
            target: 'metasync-seo-sidebar',
            icon: MetaSyncIcon,
        }, config.i18n.panelTitle);
    };

    /**
     * Combined Plugin Component
     */
    const MetaSyncSeoPlugin = () => {
        return el(wp.element.Fragment, null,
            el(MetaSyncSeoSidebar, null),
            el(MetaSyncSeoMenuItem, null)
        );
    };

    // Register the plugin
    registerPlugin('metasync-seo', {
        render: MetaSyncSeoPlugin,
        icon: MetaSyncIcon,
    });

})(window.wp);


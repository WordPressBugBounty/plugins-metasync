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
    const { PanelBody, TextControl, TextareaControl, Button, ButtonGroup, SelectControl, Spinner, Notice } = wp.components;
    const { useSelect, useDispatch, select: wpSelect, dispatch: wpDispatch } = wp.data;
    const { useState, useEffect, useCallback, createElement: el } = wp.element;
    const { __ } = wp.i18n;
    const apiFetch = wp.apiFetch;

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
            // Primary category
            primaryCategory: '_metasync_primary_category',
            primaryProductCat: '_metasync_primary_product_cat',
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
            primaryCategoryLabel: 'Primary Category',
            primaryCategoryHelp: 'Used in breadcrumbs and canonical URL when multiple categories are assigned.',
            primaryCategoryNote: 'Assign 2+ categories to enable this option.',
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
     * Primary Category Selector Component
     * Shows a dropdown to select the primary category when 2+ categories are assigned
     */
    const PrimaryCategorySelector = () => {
        const { categoryIds, postType, primaryCategoryId } = useSelect((select) => {
            const editor = select('core/editor');
            const meta = editor.getEditedPostAttribute('meta') || {};
            return {
                categoryIds: editor.getEditedPostAttribute('categories') || [],
                postType: editor.getEditedPostAttribute('type') || 'post',
                primaryCategoryId: meta[config.metaKeys.primaryCategory] || 0,
            };
        }, []);

        const { categories } = useSelect((select) => {
            if (!categoryIds || categoryIds.length < 2) {
                return { categories: [] };
            }
            return {
                categories: select('core').getEntityRecords('taxonomy', 'category', {
                    include: categoryIds,
                    per_page: 100,
                }) || [],
            };
        }, [categoryIds]);

        const { editPost } = useDispatch('core/editor');

        // Show hint when exactly 1 category is assigned (need 2+ to enable selector)
        if (!categoryIds || categoryIds.length < 2) {
            if (categoryIds && categoryIds.length === 1) {
                return el('p', { className: 'metasync-primary-category-note' },
                    config.i18n.primaryCategoryNote
                );
            }
            return null;
        }

        // Wait for categories to load from the store
        if (!categories || categories.length < 2) {
            return null;
        }

        const options = [
            { label: __('— Select —', 'metasync'), value: 0 },
        ].concat(categories.map(function(c) {
            return { label: c.name, value: c.id };
        }));

        return el('div', { className: 'metasync-seo-field' },
            el(SelectControl, {
                label: config.i18n.primaryCategoryLabel,
                value: primaryCategoryId,
                options: options,
                onChange: function(value) {
                    editPost({ meta: { [config.metaKeys.primaryCategory]: parseInt(value) || 0 } });
                },
            }),
            el('p', { className: 'metasync-primary-category-note' },
                config.i18n.primaryCategoryHelp
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
     * Internal Link Suggestions Panel Component
     * Fetches and displays internal link suggestions from the REST API.
     * Uses Gutenberg block APIs to insert links safely within individual blocks.
     */
    const LinkSuggestionsPanel = () => {
        const [suggestions, setSuggestions] = useState([]);
        const [isLoading, setIsLoading] = useState(false);
        const [error, setError] = useState(null);
        const [insertStatus, setInsertStatus] = useState(null);

        const postId = useSelect((select) => {
            return select('core/editor').getCurrentPostId();
        }, []);

        const blocks = useSelect((select) => {
            return select('core/block-editor').getBlocks();
        }, []);

        const lsConfig = config.linkSuggestions || {};
        const lsI18n = lsConfig.i18n || {};

        const escapeRegex = (str) => {
            return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        };

        const fetchSuggestions = () => {
            if (!postId || !lsConfig.restUrl) return;
            setIsLoading(true);
            setError(null);
            setInsertStatus(null);

            apiFetch({
                url: lsConfig.restUrl + '?post_id=' + postId + '&limit=10',
            }).then((response) => {
                setSuggestions(response.suggestions || []);
                setIsLoading(false);
            }).catch((err) => {
                setError(err.message || 'Failed to fetch suggestions');
                setIsLoading(false);
            });
        };

        useEffect(() => {
            fetchSuggestions();
        }, [postId]);

        /**
         * Get the plain text content of a block (strips HTML tags)
         */
        const getBlockText = (html) => {
            if (!html) return '';
            var doc = new DOMParser().parseFromString(html, 'text/html');
            return doc.body.textContent || '';
        };

        /**
         * Get the text content attribute from a block (handles different block types)
         */
        const getBlockContentHtml = (block) => {
            if (!block || !block.attributes) return '';
            // core/paragraph, core/heading, core/preformatted, core/verse use 'content'
            // core/list (older) uses 'values'
            // core/list-item uses 'content'
            return block.attributes.content || block.attributes.values || '';
        };

        /**
         * Flatten all blocks including nested innerBlocks into a single list
         */
        const flattenBlocks = (blockList) => {
            var result = [];
            if (!blockList) return result;
            for (var i = 0; i < blockList.length; i++) {
                result.push(blockList[i]);
                if (blockList[i].innerBlocks && blockList[i].innerBlocks.length > 0) {
                    result = result.concat(flattenBlocks(blockList[i].innerBlocks));
                }
            }
            return result;
        };

        /**
         * Check if a position in HTML is inside an HTML tag (i.e., between < and >)
         * This prevents matching text inside attributes like alt="...", title="...", etc.
         */
        const isInsideHtmlTag = (html, index) => {
            // Look backwards from the match position for < or >
            for (var i = index - 1; i >= 0; i--) {
                if (html[i] === '>') return false; // Closed tag before us — we're outside
                if (html[i] === '<') return true;  // Open tag before us — we're inside a tag
            }
            return false;
        };

        /**
         * Check if a position in HTML is inside an <a>...</a> element
         */
        const isInsideAnchorTag = (html, index) => {
            var before = html.substring(0, index);
            var openA = (before.match(/<a\s/gi) || []).length;
            var closeA = (before.match(/<\/a>/gi) || []).length;
            return openA > closeA;
        };

        /**
         * Check if phrase exists in any block and is not already linked
         */
        const isPhraseUnlinked = useCallback((phrase) => {
            if (!phrase || !blocks || blocks.length === 0) return false;

            var escaped = escapeRegex(phrase);
            var regex = new RegExp(escaped, 'gi');
            var allBlocks = flattenBlocks(blocks);

            for (var i = 0; i < allBlocks.length; i++) {
                var block = allBlocks[i];
                var html = getBlockContentHtml(block);
                if (!html) continue;

                var text = getBlockText(html);
                if (!(new RegExp(escaped, 'i')).test(text)) continue;

                // Search in raw HTML for an occurrence that is:
                // 1. NOT inside an HTML tag attribute
                // 2. NOT inside an <a> tag
                var match;
                regex.lastIndex = 0;
                while ((match = regex.exec(html)) !== null) {
                    if (!isInsideHtmlTag(html, match.index) && !isInsideAnchorTag(html, match.index)) {
                        return true;
                    }
                }
                regex.lastIndex = 0;
            }
            return false;
        }, [blocks]);

        /**
         * Insert a link using Gutenberg's block API
         * Finds the specific block containing the phrase and updates only that block
         */
        const insertLink = (suggestion) => {
            var phrase = suggestion.matched_phrase;
            var url = suggestion.url;
            if (!phrase || !url) return;

            var escaped = escapeRegex(phrase);
            var searchRegex = new RegExp(escaped, 'gi');

            // Sanitize URL for safe HTML insertion
            var safeUrl = url.replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

            // Find the block that contains this phrase (unlinked), including nested blocks
            var currentBlocks = flattenBlocks(wpSelect('core/block-editor').getBlocks());

            for (var i = 0; i < currentBlocks.length; i++) {
                var block = currentBlocks[i];
                var html = getBlockContentHtml(block);
                if (!html) continue;

                // Check if phrase exists in this block's text
                var text = getBlockText(html);
                if (!searchRegex.test(text)) {
                    searchRegex.lastIndex = 0;
                    continue;
                }
                searchRegex.lastIndex = 0;

                // Find the first unlinked occurrence in the HTML
                var match;
                var newHtml = html;
                var replaced = false;

                while ((match = searchRegex.exec(html)) !== null) {
                    // Skip matches inside HTML tag attributes (alt, title, src, etc.)
                    if (isInsideHtmlTag(html, match.index)) continue;
                    // Skip matches inside existing <a> tags
                    if (isInsideAnchorTag(html, match.index)) continue;

                    // This occurrence is safe to wrap
                    var originalPhrase = html.substring(match.index, match.index + match[0].length);
                    newHtml = html.substring(0, match.index) +
                        '<a href="' + safeUrl + '" target="_blank" rel="noopener noreferrer">' + originalPhrase + '</a>' +
                        html.substring(match.index + match[0].length);
                    replaced = true;
                    break;
                }
                searchRegex.lastIndex = 0;

                if (replaced) {
                    // Update ONLY this specific block, not the entire post content
                    // Use the correct attribute key (content vs values for list blocks)
                    var attrKey = (block.attributes && block.attributes.values !== undefined && !block.attributes.content) ? 'values' : 'content';
                    var updateAttrs = {};
                    updateAttrs[attrKey] = newHtml;
                    wpDispatch('core/block-editor').updateBlockAttributes(block.clientId, updateAttrs);

                    // Remove the inserted suggestion from the list
                    setSuggestions(function(prev) {
                        return prev.filter(function(s) {
                            return s.post_id !== suggestion.post_id;
                        });
                    });

                    setInsertStatus('Linked: "' + phrase + '"');
                    setTimeout(function() { setInsertStatus(null); }, 3000);
                    return;
                }
            }

            // If we got here, couldn't find a suitable block
            setInsertStatus('Could not find the phrase in any content block.');
            setTimeout(function() { setInsertStatus(null); }, 3000);
        };

        const truncateUrl = (url, maxLen) => {
            if (!url) return '';
            if (url.length <= maxLen) return url;
            return url.substring(0, maxLen) + '\u2026';
        };

        // Build panel children
        var children = [];

        // Header with Refresh button
        children.push(
            el('div', { className: 'metasync-link-suggestions-header', key: 'header' },
                el('span', null, ''),
                el(Button, {
                    isSmall: true,
                    isSecondary: true,
                    onClick: fetchSuggestions,
                    disabled: isLoading,
                }, lsI18n.refreshButton || 'Refresh')
            )
        );

        // Cross-plugin notices
        if (lsConfig.yoastPremiumActive) {
            children.push(
                el('div', { className: 'metasync-link-suggestions-notice', key: 'yoast-notice' },
                    lsI18n.yoastNotice || ''
                )
            );
        }
        if (lsConfig.rankMathActive) {
            children.push(
                el('div', { className: 'metasync-link-suggestions-notice', key: 'rankmath-notice' },
                    lsI18n.rankMathNotice || ''
                )
            );
        }

        // Insert status feedback
        if (insertStatus) {
            children.push(
                el('div', {
                    className: 'metasync-link-suggestions-notice',
                    key: 'insert-status',
                    style: { color: '#00a32a', fontWeight: 600 },
                }, insertStatus)
            );
        }

        if (isLoading) {
            children.push(el(Spinner, { key: 'spinner' }));
        } else if (error) {
            children.push(
                el('div', { className: 'metasync-link-suggestions-empty', key: 'error' }, error)
            );
        } else if (suggestions.length === 0) {
            children.push(
                el('div', { className: 'metasync-link-suggestions-empty', key: 'empty' },
                    lsI18n.noSuggestions || 'No suggestions found.'
                )
            );
        } else {
            var items = suggestions.map(function(suggestion) {
                var canInsert = isPhraseUnlinked(suggestion.matched_phrase);
                return el('li', {
                    className: 'metasync-link-suggestion-item',
                    key: suggestion.post_id,
                },
                    el('span', { className: 'metasync-suggestion-title' }, suggestion.title),
                    el('span', { className: 'metasync-suggestion-url' }, truncateUrl(suggestion.url, 40)),
                    el('span', { className: 'metasync-suggestion-phrase' },
                        (lsI18n.matchedPhrase || 'Matched phrase:') + ' ',
                        el('strong', null, suggestion.matched_phrase)
                    ),
                    suggestion.relevance_score ? el('span', {
                        className: 'metasync-suggestion-score',
                        style: { fontSize: '11px', color: '#646970', display: 'block', marginBottom: '4px' },
                    }, 'Relevance: ' + Math.round(suggestion.relevance_score * 100) + '%') : null,
                    el(Button, {
                        isSmall: true,
                        isPrimary: true,
                        onClick: function() { insertLink(suggestion); },
                        disabled: !canInsert,
                    }, lsI18n.insertButton || 'Insert')
                );
            });
            children.push(
                el('ul', { className: 'metasync-link-suggestions-list', key: 'list' }, items)
            );
        }

        return el('div', null, children);
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
                    el(UrlSlugInput, null),
                    el(PrimaryCategorySelector, null)
                ),
                el(PanelBody, {
                    title: config.i18n.serpPreviewTitle,
                    initialOpen: true,
                },
                    el(SerpPreview, null)
                ),
                el(PanelBody, {
                    title: (config.linkSuggestions && config.linkSuggestions.i18n && config.linkSuggestions.i18n.panelTitle) || 'Internal Link Suggestions',
                    initialOpen: false,
                },
                    el(LinkSuggestionsPanel, null)
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

    /**
     * Breadcrumb Title Override Component
     */
    const BreadcrumbTitleInput = () => {
        const metaKey = config.metaKeys.breadcrumbTitle || '_metasync_breadcrumb_title';

        const { value } = useSelect((select) => {
            const meta = select('core/editor').getEditedPostAttribute('meta') || {};
            return { value: meta[metaKey] || '' };
        }, [metaKey]);

        const { editPost } = useDispatch('core/editor');

        const handleChange = (newValue) => {
            editPost({ meta: { [metaKey]: newValue } });
        };

        return el(TextControl, {
            label: (config.i18n && config.i18n.breadcrumbTitleLabel) || 'Breadcrumb Title Override',
            value: value,
            onChange: handleChange,
            help: (config.i18n && config.i18n.breadcrumbTitleHelp) || 'Custom label for this page in breadcrumb trails.',
            placeholder: __('Leave empty to use post title', 'metasync'),
        });
    };

    /**
     * Primary Category Selector Component
     * Only shown for post types that support categories.
     */
    const PrimaryCategorySelect = () => {
        const metaKey = config.metaKeys.primaryCategory || '_metasync_primary_category';

        const { primaryCategoryId, postCategories, allCategories, postType } = useSelect((select) => {
            const editor = select('core/editor');
            const meta = editor.getEditedPostAttribute('meta') || {};
            const currentPostType = editor.getCurrentPostType();
            const assignedCatIds = editor.getEditedPostAttribute('categories') || [];

            // Fetch category terms for the assigned IDs.
            let cats = [];
            if (assignedCatIds.length > 0) {
                const allCats = select('core').getEntityRecords('taxonomy', 'category', {
                    include: assignedCatIds,
                    per_page: 100,
                });
                if (allCats) {
                    cats = allCats;
                }
            }

            return {
                primaryCategoryId: meta[metaKey] || 0,
                postCategories: assignedCatIds,
                allCategories: cats,
                postType: currentPostType,
            };
        }, [metaKey]);

        const { editPost } = useDispatch('core/editor');

        // Only show for post types with categories.
        const supportsCategories = useSelect((select) => {
            const type = select('core').getPostType(postType);
            if (type && type.taxonomies) {
                return type.taxonomies.indexOf('category') !== -1;
            }
            return false;
        }, [postType]);

        if (!supportsCategories || postCategories.length < 2) {
            return null;
        }

        const options = [
            { label: __('— Auto (first category) —', 'metasync'), value: 0 },
        ];

        if (allCategories && allCategories.length > 0) {
            allCategories.forEach(function(cat) {
                options.push({ label: cat.name, value: cat.id });
            });
        }

        const handleChange = (newValue) => {
            editPost({ meta: { [metaKey]: parseInt(newValue, 10) || 0 } });
        };

        return el(SelectControl, {
            label: (config.i18n && config.i18n.primaryCategoryLabel) || 'Primary Category',
            value: primaryCategoryId,
            options: options,
            onChange: handleChange,
            help: (config.i18n && config.i18n.primaryCategoryHelp) || 'Select which category appears in the breadcrumb path.',
        });
    };

    /**
     * Breadcrumbs Panel Component
     * Rendered inside the sidebar as a PluginDocumentSettingPanel.
     */
    const MetaSyncBreadcrumbsPanel = () => {
        const { PluginDocumentSettingPanel } = wp.editPost;

        return el(PluginDocumentSettingPanel, {
            name: 'metasync-breadcrumbs-panel',
            title: (config.i18n && config.i18n.breadcrumbPanelTitle) || 'Breadcrumbs',
            className: 'metasync-breadcrumbs-panel',
        },
            el(BreadcrumbTitleInput, null),
            el(PrimaryCategorySelect, null)
        );
    };

    // Register the breadcrumbs panel as a separate plugin.
    registerPlugin('metasync-breadcrumbs', {
        render: MetaSyncBreadcrumbsPanel,
    });

    // Register the plugin
    registerPlugin('metasync-seo', {
        render: MetaSyncSeoPlugin,
        icon: MetaSyncIcon,
    });

})(window.wp);


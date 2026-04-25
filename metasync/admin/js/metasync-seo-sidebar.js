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
        otherSeoPrimary: {
            yoastActive: false,
            rankMathActive: false,
            aioseoActive: false,
        },
        metaKeys: {
            seoTitle: '_metasync_seo_title',
            metaDescription: '_metasync_seo_desc',
            // OTTO keys for fallback (read-only, used to prefill if manual fields are empty)
            ottoTitle: '_metasync_otto_title',
            ottoDescription: '_metasync_otto_description',
            // OTTO disabled per-post flag
            ottoDisabled: '_metasync_otto_disabled',
            // Breadcrumb title override
            breadcrumbTitle: '_metasync_breadcrumb_title',
            // Primary category
            primaryCategory: '_metasync_primary_category',
            primaryProductCat: '_metasync_primary_product_cat',
            // Language alternates (hreflang) — JSON-encoded array
            hreflang: '_metasync_hreflang',
        },
        hasMetaKeys: {
            // Whether the manual meta keys exist in database (from PHP check)
            seoTitle: false,
            metaDescription: false,
        },
        wpmlEntries: [],
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
            breadcrumbTitleLabel: 'Breadcrumb Title Override',
            breadcrumbTitleHelp: 'Custom label for this page in breadcrumb trails. Leave empty to use the post title.',
            ottoPrefillHelp: 'Pre-filled from OTTO. Edit to customize.',
            ottoOverrideNotice: 'OTTO is enabled. Any SEO title and description changes from OTTO will be overwritten by your custom values entered here.',
            // Language Alternates (hreflang) panel strings
            languageAlternatesTitle: 'Language Alternates',
            addAlternate: 'Add alternate',
            langLabel: 'Language',
            regionLabel: 'Region',
            urlLabel: 'URL',
            editManually: 'Edit Manually',
            wpmlAutoPopulated: 'Auto-populated from WPML. Click Edit Manually to override.',
        },
    };

    // Check if another SEO plugin already provides a primary category selector.
    const otherSeo = config.otherSeoPrimary || {};
    const hasOtherSeoPrimary = otherSeo.yoastActive || otherSeo.rankMathActive || otherSeo.aioseoActive;

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

    // =========================================================================
    // Schema Content Panel Components
    // =========================================================================

    const schemaConfig = config.schemaContent || {};
    const schemaI18n = schemaConfig.i18n || {};

    /**
     * FAQ Panel - manages Q&A items
     */
    const FAQPanel = ({ fields, onChange }) => {
        const items = (fields && fields.faq_items) || [];

        const updateItem = (index, key, value) => {
            const next = items.slice();
            next[index] = Object.assign({}, next[index], { [key]: value });
            onChange(Object.assign({}, fields, { faq_items: next }));
        };

        const addItem = () => {
            const next = items.slice();
            next.push({ question: '', answer: '' });
            onChange(Object.assign({}, fields, { faq_items: next }));
        };

        const removeItem = (index) => {
            const next = items.slice();
            next.splice(index, 1);
            onChange(Object.assign({}, fields, { faq_items: next }));
        };

        return el('div', { className: 'metasync-schema-faq-panel' },
            items.map((item, i) =>
                el('div', { key: 'faq-' + i, className: 'metasync-schema-repeater-row', style: { marginBottom: '12px', padding: '8px', border: '1px solid #ddd', borderRadius: '4px' } },
                    el(TextControl, {
                        label: __('Question', 'metasync') + ' ' + (i + 1),
                        value: item.question || '',
                        onChange: (v) => updateItem(i, 'question', v),
                    }),
                    el(TextareaControl, {
                        label: __('Answer', 'metasync'),
                        value: item.answer || '',
                        onChange: (v) => updateItem(i, 'answer', v),
                        rows: 3,
                    }),
                    el(Button, {
                        isDestructive: true,
                        isSmall: true,
                        isLink: true,
                        onClick: () => removeItem(i),
                    }, schemaI18n.removeQuestion || 'Remove')
                )
            ),
            el(Button, {
                isSecondary: true,
                isSmall: true,
                onClick: addItem,
            }, schemaI18n.addQuestion || 'Add Question')
        );
    };

    /**
     * HowTo Panel - manages steps list
     */
    const HowToPanel = ({ fields, onChange }) => {
        const steps = (fields && fields.steps) || [];

        const updateStep = (index, key, value) => {
            const next = steps.slice();
            next[index] = Object.assign({}, next[index], { [key]: value });
            onChange(Object.assign({}, fields, { steps: next }));
        };

        const addStep = () => {
            const next = steps.slice();
            next.push({ instructions: '', image: '' });
            onChange(Object.assign({}, fields, { steps: next }));
        };

        const removeStep = (index) => {
            const next = steps.slice();
            next.splice(index, 1);
            onChange(Object.assign({}, fields, { steps: next }));
        };

        return el('div', { className: 'metasync-schema-howto-panel' },
            el(TextControl, {
                label: __('Total Time (minutes)', 'metasync'),
                value: (fields && fields.total_time) || '',
                onChange: (v) => onChange(Object.assign({}, fields, { total_time: v })),
                type: 'number',
            }),
            steps.map((step, i) =>
                el('div', { key: 'step-' + i, className: 'metasync-schema-repeater-row', style: { marginBottom: '12px', padding: '8px', border: '1px solid #ddd', borderRadius: '4px' } },
                    el(TextareaControl, {
                        label: __('Step', 'metasync') + ' ' + (i + 1) + ' — ' + __('Instructions', 'metasync'),
                        value: step.instructions || '',
                        onChange: (v) => updateStep(i, 'instructions', v),
                        rows: 2,
                    }),
                    el(TextControl, {
                        label: __('Image URL (optional)', 'metasync'),
                        value: step.image || '',
                        onChange: (v) => updateStep(i, 'image', v),
                    }),
                    el(Button, {
                        isDestructive: true,
                        isSmall: true,
                        isLink: true,
                        onClick: () => removeStep(i),
                    }, schemaI18n.removeStep || 'Remove')
                )
            ),
            el(Button, {
                isSecondary: true,
                isSmall: true,
                onClick: addStep,
            }, schemaI18n.addStep || 'Add Step')
        );
    };

    /**
     * Product Panel - price, currency, availability, condition, SKU, brand
     */
    const ProductPanel = ({ fields, onChange, woocommerceActive, woocommerceData }) => {
        const f = fields || {};

        const update = (key, value) => {
            onChange(Object.assign({}, f, { [key]: value }));
        };

        const autoPopulateWC = () => {
            if (woocommerceData) {
                onChange(Object.assign({}, f, {
                    price: woocommerceData.price || f.price || '',
                    currency: woocommerceData.currency || f.currency || 'USD',
                    availability: woocommerceData.availability || f.availability || 'InStock',
                    sku: woocommerceData.sku || f.sku || '',
                }));
            }
        };

        return el('div', { className: 'metasync-schema-product-panel' },
            woocommerceActive && woocommerceData && el(Button, {
                isSecondary: true,
                isSmall: true,
                onClick: autoPopulateWC,
                style: { marginBottom: '12px' },
            }, schemaI18n.autoPopulateWC || 'Auto-populate from WooCommerce'),
            el(TextControl, {
                label: __('Price', 'metasync'),
                value: f.price || '',
                onChange: (v) => update('price', v),
                type: 'number',
            }),
            el(SelectControl, {
                label: __('Currency', 'metasync'),
                value: f.currency || 'USD',
                options: [
                    { label: 'USD', value: 'USD' },
                    { label: 'EUR', value: 'EUR' },
                    { label: 'GBP', value: 'GBP' },
                    { label: 'CAD', value: 'CAD' },
                    { label: 'AUD', value: 'AUD' },
                ],
                onChange: (v) => update('currency', v),
            }),
            el(SelectControl, {
                label: __('Availability', 'metasync'),
                value: f.availability || 'InStock',
                options: [
                    { label: 'In Stock', value: 'InStock' },
                    { label: 'Out of Stock', value: 'OutOfStock' },
                    { label: 'Pre-Order', value: 'PreOrder' },
                ],
                onChange: (v) => update('availability', v),
            }),
            el(SelectControl, {
                label: __('Condition', 'metasync'),
                value: f.condition || 'NewCondition',
                options: [
                    { label: 'New', value: 'NewCondition' },
                    { label: 'Used', value: 'UsedCondition' },
                    { label: 'Refurbished', value: 'RefurbishedCondition' },
                ],
                onChange: (v) => update('condition', v),
            }),
            el(TextControl, {
                label: __('SKU', 'metasync'),
                value: f.sku || '',
                onChange: (v) => update('sku', v),
            }),
            el(TextControl, {
                label: __('Brand', 'metasync'),
                value: f.brand || '',
                onChange: (v) => update('brand', v),
            })
        );
    };

    /**
     * Recipe Panel - yield, times, calories, ingredients, instructions
     */
    const RecipePanel = ({ fields, onChange }) => {
        const f = fields || {};
        const ingredients = f.ingredients || [];
        const instructions = f.instructions || [];

        const update = (key, value) => {
            onChange(Object.assign({}, f, { [key]: value }));
        };

        const updateIngredient = (index, value) => {
            const next = ingredients.slice();
            next[index] = value;
            update('ingredients', next);
        };

        const addIngredient = () => {
            const next = ingredients.slice();
            next.push('');
            update('ingredients', next);
        };

        const removeIngredient = (index) => {
            const next = ingredients.slice();
            next.splice(index, 1);
            update('ingredients', next);
        };

        const updateInstruction = (index, value) => {
            const next = instructions.slice();
            next[index] = { text: value };
            update('instructions', next);
        };

        const addInstruction = () => {
            const next = instructions.slice();
            next.push({ text: '' });
            update('instructions', next);
        };

        const removeInstruction = (index) => {
            const next = instructions.slice();
            next.splice(index, 1);
            update('instructions', next);
        };

        return el('div', { className: 'metasync-schema-recipe-panel' },
            el(TextControl, {
                label: __('Yield (servings)', 'metasync'),
                value: f.yield || '',
                onChange: (v) => update('yield', v),
            }),
            el(TextControl, {
                label: __('Prep Time (minutes)', 'metasync'),
                value: f.prep_time || '',
                onChange: (v) => update('prep_time', v),
                type: 'number',
            }),
            el(TextControl, {
                label: __('Cook Time (minutes)', 'metasync'),
                value: f.cook_time || '',
                onChange: (v) => update('cook_time', v),
                type: 'number',
            }),
            el(TextControl, {
                label: __('Total Time (minutes)', 'metasync'),
                value: f.total_time || '',
                onChange: (v) => update('total_time', v),
                type: 'number',
            }),
            el(TextControl, {
                label: __('Calories', 'metasync'),
                value: f.calories || '',
                onChange: (v) => update('calories', v),
                type: 'number',
            }),
            el('h4', { style: { marginTop: '12px', marginBottom: '4px' } }, __('Ingredients', 'metasync')),
            ingredients.map((ing, i) =>
                el('div', { key: 'ing-' + i, style: { display: 'flex', alignItems: 'center', gap: '4px', marginBottom: '4px' } },
                    el(TextControl, {
                        value: typeof ing === 'string' ? ing : (ing || ''),
                        onChange: (v) => updateIngredient(i, v),
                        placeholder: __('Ingredient', 'metasync'),
                        style: { flex: 1 },
                    }),
                    el(Button, {
                        isDestructive: true,
                        isSmall: true,
                        isLink: true,
                        onClick: () => removeIngredient(i),
                    }, schemaI18n.removeIngredient || 'Remove')
                )
            ),
            el(Button, {
                isSecondary: true,
                isSmall: true,
                onClick: addIngredient,
                style: { marginBottom: '12px' },
            }, schemaI18n.addIngredient || 'Add Ingredient'),
            el('h4', { style: { marginTop: '12px', marginBottom: '4px' } }, __('Instructions', 'metasync')),
            instructions.map((inst, i) => {
                var instText = typeof inst === 'string' ? inst : ((inst && inst.text) || '');
                return el('div', { key: 'inst-' + i, style: { display: 'flex', alignItems: 'flex-start', gap: '4px', marginBottom: '4px' } },
                    el(TextareaControl, {
                        value: instText,
                        onChange: (v) => updateInstruction(i, v),
                        placeholder: __('Step', 'metasync') + ' ' + (i + 1),
                        rows: 2,
                        style: { flex: 1 },
                    }),
                    el(Button, {
                        isDestructive: true,
                        isSmall: true,
                        isLink: true,
                        onClick: () => removeInstruction(i),
                    }, schemaI18n.removeInstruction || 'Remove')
                );
            }),
            el(Button, {
                isSecondary: true,
                isSmall: true,
                onClick: addInstruction,
            }, schemaI18n.addInstruction || 'Add Instruction')
        );
    };

    /**
     * Schema Content Panel
     * Main panel that fetches schema data and renders type-specific sub-panels
     */
    const SchemaContentPanel = () => {
        const [schemaData, setSchemaData] = useState(null);
        const [isLoading, setIsLoading] = useState(false);
        const [isSavingSchema, setIsSavingSchema] = useState(false);
        const [saveNotice, setSaveNotice] = useState(null);
        const [validationWarnings, setValidationWarnings] = useState([]);
        const [pendingChanges, setPendingChanges] = useState({});
        // Only true after the user has actually edited a field; prevents firing
        // extra REST calls on every post save when nothing has changed.
        const [isDirty, setIsDirty] = useState(false);

        const postId = useSelect((select) => {
            return select('core/editor').getCurrentPostId();
        }, []);

        const isSavingPost = useSelect((select) => {
            return select('core/editor').isSavingPost();
        }, []);

        const restUrl = schemaConfig.restUrl || '';

        // Fetch schema content on mount
        useEffect(() => {
            if (!postId || !restUrl) return;
            setIsLoading(true);
            apiFetch({
                url: restUrl + '/' + postId,
            }).then((response) => {
                setSchemaData(response);
                // Initialize pending changes from fetched data
                var initial = {};
                if (response && response.types) {
                    response.types.forEach(function(t) {
                        initial[t.type] = t.fields || {};
                    });
                }
                setPendingChanges(initial);
                setIsLoading(false);
            }).catch(() => {
                setIsLoading(false);
            });
        }, [postId]);

        // Save schema content — serialises requests to avoid lost-update race condition
        const saveSchemaContent = useCallback(() => {
            if (!postId || !restUrl) return;
            var types = Object.keys(pendingChanges);
            if (types.length === 0) return;

            setIsSavingSchema(true);
            setSaveNotice(null);
            setValidationWarnings([]);

            var allWarnings = [];
            // Chain requests sequentially so each POST reads the latest DB state
            var chain = types.reduce(function(promise, schemaType) {
                return promise.then(function() {
                    return apiFetch({
                        url: restUrl + '/' + postId,
                        method: 'POST',
                        data: {
                            schema_type: schemaType,
                            fields: pendingChanges[schemaType],
                        },
                    }).then(function(resp) {
                        if (resp.validation_warnings && resp.validation_warnings.length > 0) {
                            allWarnings = allWarnings.concat(resp.validation_warnings);
                        }
                    });
                });
            }, Promise.resolve());

            chain.then(function() {
                setIsSavingSchema(false);
                setIsDirty(false);
                setValidationWarnings(allWarnings);
                setSaveNotice({ type: 'success', message: schemaI18n.saved || 'Schema content saved.' });
                setTimeout(function() { setSaveNotice(null); }, 4000);
            }).catch(function() {
                setIsSavingSchema(false);
                setSaveNotice({ type: 'error', message: schemaI18n.saveError || 'Failed to save schema content.' });
                setTimeout(function() { setSaveNotice(null); }, 4000);
            });
        }, [postId, restUrl, pendingChanges]);

        // Auto-save on post save — only when the user has edited schema fields
        const [wasSaving, setWasSaving] = useState(false);
        useEffect(() => {
            if (isSavingPost && !wasSaving) {
                setWasSaving(true);
            }
            if (!isSavingPost && wasSaving) {
                setWasSaving(false);
                if (isDirty) {
                    saveSchemaContent();
                }
            }
        }, [isSavingPost, wasSaving, isDirty, saveSchemaContent]);

        const updateTypeFields = (schemaType, newFields) => {
            setIsDirty(true);
            setPendingChanges(function(prev) {
                var next = Object.assign({}, prev);
                next[schemaType] = newFields;
                return next;
            });
        };

        // Build panel content
        var children = [];

        if (isLoading) {
            children.push(el(Spinner, { key: 'loading' }));
        } else if (!schemaData || !schemaData.types || schemaData.types.length === 0) {
            children.push(
                el('p', { key: 'no-types', style: { color: '#757575', fontStyle: 'italic' } },
                    schemaI18n.noSchemaTypes || 'No schema types configured.'
                )
            );
        } else {
            schemaData.types.forEach(function(typeData) {
                var schemaType = typeData.type;
                var currentFields = pendingChanges[schemaType] || typeData.fields || {};
                var onChangeFields = function(newFields) {
                    updateTypeFields(schemaType, newFields);
                };

                var subPanel = null;
                switch (schemaType) {
                    case 'FAQPage':
                        subPanel = el(FAQPanel, { key: 'faq', fields: currentFields, onChange: onChangeFields });
                        break;
                    case 'HowTo':
                        subPanel = el(HowToPanel, { key: 'howto', fields: currentFields, onChange: onChangeFields });
                        break;
                    case 'product':
                        subPanel = el(ProductPanel, {
                            key: 'product',
                            fields: currentFields,
                            onChange: onChangeFields,
                            woocommerceActive: schemaConfig.woocommerceActive || false,
                            woocommerceData: schemaConfig.woocommerceData || null,
                        });
                        break;
                    case 'recipe':
                        subPanel = el(RecipePanel, { key: 'recipe', fields: currentFields, onChange: onChangeFields });
                        break;
                    default:
                        subPanel = el('p', { key: 'unsupported-' + schemaType, style: { color: '#757575' } },
                            __('Content editing for', 'metasync') + ' ' + schemaType + ' ' + __('is available in the classic editor.', 'metasync')
                        );
                        break;
                }

                children.push(
                    el(PanelBody, {
                        key: 'schema-type-' + schemaType,
                        title: schemaType,
                        initialOpen: true,
                    }, subPanel)
                );
            });

            // Validation warnings
            if (validationWarnings.length > 0) {
                children.push(
                    el(Notice, {
                        key: 'validation-warnings',
                        status: 'warning',
                        isDismissible: false,
                        style: { marginTop: '8px' },
                    },
                        el('ul', { style: { margin: 0, paddingLeft: '16px' } },
                            validationWarnings.map(function(w, i) {
                                var msg = (typeof w === 'string') ? w : (w.message || w.error || JSON.stringify(w));
                                return el('li', { key: 'warn-' + i }, msg);
                            })
                        )
                    )
                );
            }

            // Save notice
            if (saveNotice) {
                children.push(
                    el(Notice, {
                        key: 'save-notice',
                        status: saveNotice.type === 'success' ? 'success' : 'error',
                        isDismissible: false,
                        style: { marginTop: '8px' },
                    }, saveNotice.message)
                );
            }

            // Save button
            children.push(
                el(Button, {
                    key: 'save-btn',
                    isPrimary: true,
                    onClick: saveSchemaContent,
                    disabled: isSavingSchema,
                    style: { marginTop: '12px' },
                }, isSavingSchema ? (schemaI18n.saving || 'Saving...') : (schemaI18n.saveButton || 'Save Schema Content'))
            );
        }

        return el('div', { className: 'metasync-schema-content-panel' }, children);
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
     * Breadcrumb Title Override Component
     * Now rendered inside the MetaSync SEO sidebar panel.
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
            label: config.i18n.breadcrumbTitleLabel || 'Breadcrumb Title Override',
            value: value,
            onChange: handleChange,
            help: config.i18n.breadcrumbTitleHelp || 'Custom label for this page in breadcrumb trails.',
            placeholder: __('Leave empty to use post title', 'metasync'),
        });
    };

    /**
     * Primary Category Selector Component
     *
     * Rendered in two places:
     * 1. Inside the MetaSync SEO sidebar panel
     * 2. Injected below the WordPress Categories checklist (via editor.PostTaxonomyType filter)
     *
     * Hidden from the Categories panel when Yoast, Rank Math, or AIOSEO is active
     * (those plugins provide their own selector), but always available in the SEO sidebar.
     */
    const PrimaryCategoryInjectPanel = () => {
        const metaKey = config.metaKeys.primaryCategory || '_metasync_primary_category';

        const { primaryCategoryId, postCategories, allCategories } = useSelect((select) => {
            const editor = select('core/editor');
            const meta = editor.getEditedPostAttribute('meta') || {};
            const assignedCatIds = editor.getEditedPostAttribute('categories') || [];

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
            };
        }, [metaKey]);

        const { editPost } = useDispatch('core/editor');

        if (postCategories.length < 2) {
            return null;
        }

        // Wait for categories to resolve from the store.
        if (!allCategories || allCategories.length < 2) {
            return null;
        }

        const options = [
            { label: __('— Auto (first category) —', 'metasync'), value: 0 },
        ];
        allCategories.forEach(function(cat) {
            options.push({ label: cat.name, value: cat.id });
        });

        return el('div', { className: 'metasync-primary-category-panel' },
            el(SelectControl, {
                label: config.i18n.primaryCategoryLabel || 'Primary Category',
                value: primaryCategoryId,
                options: options,
                onChange: function(value) {
                    editPost({ meta: { [metaKey]: parseInt(value, 10) || 0 } });
                },
                help: config.i18n.primaryCategoryHelp || 'Used in breadcrumbs and canonical URL when multiple categories are assigned.',
            })
        );
    };

    /**
     * Language Alternates (hreflang) Panel Component
     *
     * Renders the list of hreflang entries (read from the _metasync_hreflang
     * post meta as a JSON array of {lang, region, url} objects).
     *
     * When WPML auto-populated entries are available and the user has not
     * overridden them, renders them as read-only rows with an "Edit Manually"
     * button that switches to manual-edit mode. In manual-edit mode (and
     * when WPML is not active), renders editable rows plus an "Add alternate"
     * button.
     */
    const LanguageAlternatesPanel = () => {
        const metaKey = config.metaKeys.hreflang || '_metasync_hreflang';
        const wpmlEntries = Array.isArray(config.wpmlEntries) ? config.wpmlEntries : [];

        const { rawValue } = useSelect((select) => {
            const meta = select('core/editor').getEditedPostAttribute('meta') || {};
            return { rawValue: meta[metaKey] || '' };
        }, [metaKey]);

        const { editPost } = useDispatch('core/editor');

        let rows = [];
        if (rawValue) {
            try {
                const parsed = JSON.parse(rawValue);
                if (Array.isArray(parsed)) {
                    rows = parsed;
                }
            } catch (e) {
                rows = [];
            }
        }

        // Manual mode is active when the user explicitly overrode WPML
        // (tracked in state for the current session) or when manual rows
        // already exist in the stored meta.
        const [manualMode, setManualMode] = useState(rows.length > 0);

        const saveRows = (nextRows) => {
            editPost({ meta: { [metaKey]: JSON.stringify(nextRows) } });
        };

        const updateRow = (index, field, value) => {
            const next = rows.slice();
            next[index] = Object.assign({}, next[index] || { lang: '', region: '', url: '' });
            next[index][field] = value;
            saveRows(next);
        };

        const addRow = () => {
            const next = rows.slice();
            next.push({ lang: '', region: '', url: '' });
            saveRows(next);
        };

        const removeRow = (index) => {
            const next = rows.slice();
            next.splice(index, 1);
            saveRows(next);
        };

        const startManualEdit = () => {
            // Seed manual rows from the WPML entries so the user can tweak
            // rather than re-enter everything.
            if (rows.length === 0 && wpmlEntries.length > 0) {
                const seeded = wpmlEntries.map(function(e) {
                    return { lang: e.lang || '', region: '', url: e.url || '' };
                });
                saveRows(seeded);
            }
            setManualMode(true);
        };

        // WPML auto-populated, read-only view
        if (!manualMode && wpmlEntries.length > 0) {
            return el('div', { className: 'metasync-language-alternates' },
                el('p', { className: 'metasync-language-alternates-help' },
                    config.i18n.wpmlAutoPopulated || 'Auto-populated from WPML. Click Edit Manually to override.'
                ),
                el('table', { className: 'metasync-language-alternates-table' },
                    el('thead', null,
                        el('tr', null,
                            el('th', null, config.i18n.langLabel || 'Language'),
                            el('th', null, config.i18n.urlLabel || 'URL')
                        )
                    ),
                    el('tbody', null,
                        wpmlEntries.map(function(entry, i) {
                            return el('tr', { key: 'wpml-' + i },
                                el('td', null, entry.lang || ''),
                                el('td', null,
                                    el('a', {
                                        href: entry.url,
                                        target: '_blank',
                                        rel: 'noopener noreferrer',
                                    }, entry.url || '')
                                )
                            );
                        })
                    )
                ),
                el(Button, {
                    isSecondary: true,
                    isSmall: true,
                    onClick: startManualEdit,
                }, config.i18n.editManually || 'Edit Manually')
            );
        }

        // Manual-edit view
        const editableRows = rows.length > 0 ? rows : [];

        return el('div', { className: 'metasync-language-alternates' },
            editableRows.length === 0
                ? el('p', { className: 'metasync-language-alternates-help' },
                    __('No language alternates yet. Use "Add alternate" to create one.', 'metasync'))
                : null,
            editableRows.map(function(row, index) {
                return el('div', { className: 'metasync-language-alternate-row', key: 'row-' + index },
                    el(TextControl, {
                        label: config.i18n.langLabel || 'Language',
                        value: (row && row.lang) || '',
                        onChange: function(value) { updateRow(index, 'lang', value); },
                        placeholder: 'en',
                    }),
                    el(TextControl, {
                        label: config.i18n.regionLabel || 'Region',
                        value: (row && row.region) || '',
                        onChange: function(value) { updateRow(index, 'region', value); },
                        placeholder: 'us',
                    }),
                    el(TextControl, {
                        label: config.i18n.urlLabel || 'URL',
                        value: (row && row.url) || '',
                        onChange: function(value) { updateRow(index, 'url', value); },
                        placeholder: 'https://example.com/page/',
                    }),
                    el(Button, {
                        isLink: true,
                        isDestructive: true,
                        isSmall: true,
                        onClick: function() { removeRow(index); },
                    }, __('Remove', 'metasync'))
                );
            }),
            el(Button, {
                isSecondary: true,
                isSmall: true,
                onClick: addRow,
            }, config.i18n.addAlternate || 'Add alternate')
        );
    };

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
                    el(BreadcrumbTitleInput, null),
                    el(PrimaryCategoryInjectPanel, null)
                ),
                el(PanelBody, {
                    title: config.i18n.serpPreviewTitle,
                    initialOpen: true,
                },
                    el(SerpPreview, null)
                ),
                el(PanelBody, {
                    title: schemaI18n.panelTitle || 'Schema Markup Content',
                    initialOpen: false,
                },
                    el(SchemaContentPanel, null)
                ),
                el(PanelBody, {
                    title: config.i18n.languageAlternatesTitle || 'Language Alternates',
                    initialOpen: false,
                },
                    el(LanguageAlternatesPanel, null)
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
     * Hook into the WordPress Categories taxonomy panel.
     * Wraps the original component and appends our Primary Category selector
     * directly below the category checkboxes — same UX as AIOSEO.
     */
    if (!hasOtherSeoPrimary) {
        wp.hooks.addFilter(
            'editor.PostTaxonomyType',
            'metasync/primary-category',
            function(OriginalComponent) {
                return function(props) {
                    // Only inject into the 'category' taxonomy panel.
                    if (props.slug !== 'category') {
                        return el(OriginalComponent, props);
                    }

                    return el(wp.element.Fragment, null,
                        el(OriginalComponent, props),
                        el(PrimaryCategoryInjectPanel, null)
                    );
                };
            }
        );
    }

    // Register the SEO sidebar plugin.
    registerPlugin('metasync-seo', {
        render: MetaSyncSeoPlugin,
        icon: MetaSyncIcon,
    });

})(window.wp);


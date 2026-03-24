<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}


/**
 * Instant Indexing Settings page contents.
 *
 * @package Google Instant Indexing
 */
?>

<style>
/* Form styling for redirection form */
#add-redirection-form .form-table th {
    vertical-align: middle !important;
    text-align: center !important;
    padding: 20px 30px 20px 0 !important;
    width: 200px !important;
    font-weight: 600 !important;
    color: var(--dashboard-text-primary) !important;
    background: rgba(59, 130, 246, 0.05) !important;
    border-right: 2px solid var(--dashboard-border) !important;
    margin-right: 20px !important;
}

#add-redirection-form .form-table td {
    vertical-align: middle !important;
    padding: 15px !important;
}

#add-redirection-form .form-table input[type="text"],
#add-redirection-form .form-table input[type="url"] {
    padding: 10px 12px !important;
    height: 40px !important;
    border: 1px solid var(--dashboard-border) !important;
    border-radius: 6px !important;
    background: var(--dashboard-card-bg) !important;
    color: var(--dashboard-text-primary) !important;
    font-size: 14px !important;
}

#add-redirection-form .form-table select {
    padding: 10px 12px !important;
    height: 40px !important;
    border: 1px solid var(--dashboard-border) !important;
    border-radius: 6px !important;
    background: var(--dashboard-card-bg) !important;
    color: var(--dashboard-text-primary) !important;
    font-size: 14px !important;
    min-width: 150px !important;
}

#add-redirection-form .form-table ul {
    margin: 0 !important;
    padding: 0 !important;
    list-style: none !important;
}

#add-redirection-form .form-table ul li {
    margin-bottom: 8px !important;
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
}

#add-redirection-form .form-table ul li label {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    font-weight: 500 !important;
    color: var(--dashboard-text-primary) !important;
    cursor: pointer !important;
    margin: 0 !important;
    padding: 12px 16px !important;
    background: rgba(255, 255, 255, 0.02) !important;
    border-radius: 6px !important;
    border: 1px solid transparent !important;
    transition: all 0.2s ease !important;
}

#add-redirection-form .form-table ul li label:hover {
    background: rgba(59, 130, 246, 0.1) !important;
    border-color: var(--dashboard-accent) !important;
    transform: translateY(-1px) !important;
}

#add-redirection-form .form-table ul li input[type="radio"] {
    margin: 0 !important;
    width: 16px !important;
    height: 16px !important;
}

#add-redirection-form .form-table .description {
    margin-top: 8px !important;
    color: var(--dashboard-text-secondary) !important;
    font-size: 13px !important;
    font-style: italic !important;
}

#add-redirection-form .form-table .regular-text {
    width: 100% !important;
    max-width: 400px !important;
}

#add-redirection-form .form-table .button {
    padding: 10px 20px !important;
    height: 40px !important;
    border-radius: 6px !important;
    font-weight: 500 !important;
    text-decoration: none !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
}

#add-redirection-form .form-table .button-primary {
    background: var(--dashboard-accent) !important;
    border-color: var(--dashboard-accent) !important;
    color: white !important;
}

#add-redirection-form .form-table .button-secondary {
    background: var(--dashboard-card-bg) !important;
    border: 1px solid var(--dashboard-border) !important;
    color: var(--dashboard-text-primary) !important;
}

#add-redirection-form .form-table .button:hover {
    opacity: 0.9 !important;
    transform: translateY(-1px) !important;
    transition: all 0.2s ease !important;
}

/* Source URLs list styling */
#source_urls {
    margin: 0 !important;
    padding: 0 !important;
    list-style: none !important;
}

#source_urls li {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    margin-bottom: 12px !important;
    padding: 12px !important;
    background: var(--dashboard-card-hover) !important;
    border: 1px solid var(--dashboard-border) !important;
    border-radius: 6px !important;
}

#source_urls li input[type="text"] {
    flex: 1 !important;
    margin: 0 !important;
}

#source_urls li select {
    min-width: 140px !important;
    margin: 0 !important;
}

#source_urls li .source_url_delete {
    background: var(--dashboard-error) !important;
    color: white !important;
    border: none !important;
    padding: 8px 12px !important;
    border-radius: 4px !important;
    cursor: pointer !important;
    font-size: 12px !important;
    height: 32px !important;
}

#source_urls li .source_url_delete:hover {
    background: #dc2626 !important;
    transform: translateY(-1px) !important;
}

#addNewSourceUrl {
    margin-top: 12px !important;
    background: var(--dashboard-success) !important;
    color: white !important;
    border: none !important;
}

#addNewSourceUrl:hover {
    background: #059669 !important;
}

/* Form table row spacing */
#add-redirection-form .form-table tr {
    border-bottom: 1px solid var(--dashboard-border) !important;
    margin-bottom: 20px !important;
}

#add-redirection-form .form-table tr:hover th {
    background: rgba(59, 130, 246, 0.08) !important;
}

/* Add spacing between rows */
#add-redirection-form .form-table tbody tr {
    margin-bottom: 25px !important;
    padding-bottom: 15px !important;
}

#add-redirection-form .form-table tbody tr:not(:last-child) {
    margin-bottom: 30px !important;
    padding-bottom: 20px !important;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    #add-redirection-form .form-table th,
    #add-redirection-form .form-table td {
        display: block !important;
        width: 100% !important;
        padding: 15px !important;
    }
    
    #add-redirection-form .form-table th {
        margin-bottom: 12px !important;
        font-weight: 600 !important;
        background: rgba(59, 130, 246, 0.1) !important;
        border-right: none !important;
        border-bottom: 2px solid var(--dashboard-border) !important;
        padding: 20px !important;
        text-align: center !important;
    }
    
    #add-redirection-form .form-table td {
        padding: 15px !important;
    }
    
    #source_urls li {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 8px !important;
    }
    
    #source_urls li input[type="text"],
    #source_urls li select {
        width: 100% !important;
    }
}

/* Description text styling */
.description,
p.description {
    color: var(--dashboard-text-secondary, #9ca3af) !important;
    font-size: 13px !important;
    line-height: 1.5 !important;
}
</style>

<div id="add-redirection-form">
    <h1><?php echo (isset($_GET['action']) && $_GET['action'] == 'edit') ? 'Edit Redirection' : 'Add Redirection'; ?></h1>

    <!-- Redirection Tips Section -->
    <div class="redirection-tips-container" style="margin-bottom: 20px;">
        <button type="button" id="toggle-redirection-tips" class="button button-secondary" style="margin-bottom: 10px;">
            <span class="dashicons dashicons-info" style="margin-top: 3px;"></span> Show Redirection Tips & Examples
        </button>

        <div id="redirection-tips-content" style="display: none; background: var(--dashboard-card-bg); border: 1px solid var(--dashboard-border); border-radius: 8px; padding: 20px; margin-top: 10px;">
            <h3 style="margin-top: 0; color: var(--dashboard-text-primary);">📚 Redirection Pattern Guide</h3>

            <!-- Pattern Type Examples -->
            <div class="tips-section">
                <h4 style="color: var(--dashboard-accent); margin-bottom: 10px;">🎯 Pattern Types Explained</h4>

                <!-- Exact Match -->
                <details class="tip-item" style="margin-bottom: 15px; padding: 10px; background: rgba(59, 130, 246, 0.1); border-left: 3px solid var(--dashboard-accent); border-radius: 4px;">
                    <summary style="cursor: pointer; font-weight: 600; color: var(--dashboard-text-primary); padding: 5px;">
                        <span class="dashicons dashicons-yes-alt" style="color: var(--dashboard-accent);"></span> 1. Exact Match
                    </summary>
                    <div style="margin-top: 10px; padding-left: 20px; color: var(--dashboard-text-secondary);">
                        <p><strong>Use when:</strong> You want to redirect one specific URL only.</p>
                        <div style="background: var(--dashboard-card-hover); padding: 10px; border-radius: 4px; margin: 10px 0;">
                            <strong>Example:</strong><br>
                            <code>From: /old-page</code><br>
                            <code>To: /new-page</code><br>
                            <code>Pattern: Exact Match</code>
                        </div>
                        <p><strong>Matches:</strong> ✅ <code>yoursite.com/old-page</code></p>
                        <p><strong>Doesn't match:</strong> ❌ <code>yoursite.com/old-page/</code> or <code>yoursite.com/old-page-2</code></p>
                    </div>
                </details>

                <!-- Starts With -->
                <details class="tip-item" style="margin-bottom: 15px; padding: 10px; background: rgba(16, 185, 129, 0.1); border-left: 3px solid var(--dashboard-success); border-radius: 4px;">
                    <summary style="cursor: pointer; font-weight: 600; color: var(--dashboard-text-primary); padding: 5px;">
                        <span class="dashicons dashicons-arrow-right-alt" style="color: var(--dashboard-success);"></span> 2. Starts With
                    </summary>
                    <div style="margin-top: 10px; padding-left: 20px; color: var(--dashboard-text-secondary);">
                        <p><strong>Use when:</strong> You want to redirect all URLs that start with a specific path.</p>
                        <div style="background: var(--dashboard-card-hover); padding: 10px; border-radius: 4px; margin: 10px 0;">
                            <strong>Example - Category to Articles Migration:</strong><br>
                            <code>From: /category/blog/</code><br>
                            <code>To: /articles/article/</code><br>
                            <code>Pattern: Starts With</code>
                        </div>
                        <p><strong>Matches:</strong><br>
                        ✅ <code>yoursite.com/category/blog/my-post</code> → <code>yoursite.com/articles/article/</code><br>
                        ✅ <code>yoursite.com/category/blog/2024/january</code> → <code>yoursite.com/articles/article/</code><br>
                        ✅ <code>yoursite.com/category/blog</code> → <code>yoursite.com/articles/article/</code></p>
                        <p><strong>Doesn't match:</strong> ❌ <code>yoursite.com/old-category/blog/post</code></p>
                        <p><strong>Note:</strong> This pattern does NOT preserve the path after the matched part. All matching URLs redirect to the same destination. For path preservation, use Wildcard (*) pattern instead.</p>
                    </div>
                </details>

                <!-- Ends With -->
                <details class="tip-item" style="margin-bottom: 15px; padding: 10px; background: rgba(139, 92, 246, 0.1); border-left: 3px solid #8b5cf6; border-radius: 4px;">
                    <summary style="cursor: pointer; font-weight: 600; color: var(--dashboard-text-primary); padding: 5px;">
                        <span class="dashicons dashicons-arrow-left-alt" style="color: #8b5cf6;"></span> 3. Ends With
                    </summary>
                    <div style="margin-top: 10px; padding-left: 20px; color: var(--dashboard-text-secondary);">
                        <p><strong>Use when:</strong> You want to redirect URLs that end with specific text or file extensions.</p>
                        <div style="background: var(--dashboard-card-hover); padding: 10px; border-radius: 4px; margin: 10px 0;">
                            <strong>Example - PDF Redirect:</strong><br>
                            <code>From: .pdf</code><br>
                            <code>To: /downloads/</code><br>
                            <code>Pattern: Ends With</code>
                        </div>
                        <p><strong>Matches:</strong><br>
                        ✅ <code>yoursite.com/document.pdf</code><br>
                        ✅ <code>yoursite.com/files/report-2024.pdf</code></p>
                    </div>
                </details>

                <!-- Wildcard -->
                <details class="tip-item" style="margin-bottom: 15px; padding: 10px; background: rgba(34, 197, 94, 0.1); border-left: 3px solid #22c55e; border-radius: 4px;">
                    <summary style="cursor: pointer; font-weight: 600; color: var(--dashboard-text-primary); padding: 5px;">
                        <span class="dashicons dashicons-star-filled" style="color: #22c55e;"></span> 4. Wildcard (*) - Path Preservation
                    </summary>
                    <div style="margin-top: 10px; padding-left: 20px; color: var(--dashboard-text-secondary);">
                        <p><strong>Use when:</strong> You want to redirect URLs and preserve the remaining path structure. Perfect for section migrations!</p>

                        <div style="background: var(--dashboard-card-hover); padding: 10px; border-radius: 4px; margin: 10px 0;">
                            <strong>Example 1 - Blog to Articles (Preserve Full Path):</strong><br>
                            <code>From: /blog/*</code><br>
                            <code>To: /articles/*</code><br>
                            <code>Pattern: Wildcard (*)</code>
                        </div>
                        <p><strong>Result:</strong><br>
                        ✅ <code>/blog/my-post</code> → <code>/articles/my-post</code><br>
                        ✅ <code>/blog/2024/tech/ai</code> → <code>/articles/2024/tech/ai</code><br>
                        ✅ <code>/blog/category/news/post-123</code> → <code>/articles/category/news/post-123</code></p>

                        <div style="background: var(--dashboard-card-hover); padding: 10px; border-radius: 4px; margin: 10px 0;">
                            <strong>Example 2 - Year Archive Restructure:</strong><br>
                            <code>From: /2024/*</code><br>
                            <code>To: /archive/2024/*</code>
                        </div>
                        <p><strong>Result:</strong><br>
                        ✅ <code>/2024/january/post</code> → <code>/archive/2024/january/post</code><br>
                        ✅ <code>/2024/sales-report</code> → <code>/archive/2024/sales-report</code></p>

                        <div style="background: var(--dashboard-card-hover); padding: 10px; border-radius: 4px; margin: 10px 0;">
                            <strong>Example 3 - Prefix Addition:</strong><br>
                            <code>From: /post/*</code><br>
                            <code>To: /blog/post/*</code>
                        </div>
                        <p><strong>Result:</strong><br>
                        ✅ <code>/post/hello-world</code> → <code>/blog/post/hello-world</code></p>

                        <div style="background: rgba(34, 197, 94, 0.2); padding: 10px; border-radius: 4px; margin: 10px 0; border: 1px solid #22c55e;">
                            <strong>💡 How it works:</strong><br>
                            The <code>*</code> wildcard captures everything after the matched part and replaces the <code>*</code> in the destination URL.<br>
                            <strong>Much simpler than regex for common use cases!</strong>
                        </div>
                    </div>
                </details>

                <!-- Regex Pattern -->
                <details class="tip-item" style="margin-bottom: 15px; padding: 10px; background: rgba(239, 68, 68, 0.1); border-left: 3px solid var(--dashboard-error); border-radius: 4px;">
                    <summary style="cursor: pointer; font-weight: 600; color: var(--dashboard-text-primary); padding: 5px;">
                        <span class="dashicons dashicons-admin-tools" style="color: var(--dashboard-error);"></span> 5. Regex Pattern (Advanced)
                    </summary>
                    <div style="margin-top: 10px; padding-left: 20px; color: var(--dashboard-text-secondary);">
                        <p><strong>Use when:</strong> You need complex pattern matching with path preservation.</p>

                        <div style="background: var(--dashboard-card-hover); padding: 10px; border-radius: 4px; margin: 10px 0;">
                            <strong>Example 1 - Category to Articles Migration (Preserve Path):</strong><br>
                            <code>From: category-blog</code> (label only)<br>
                            <code>Regex Pattern: /^\/category\/blog\/(.*)$/</code><br>
                            <code>To: /articles/article/$1</code><br>
                            <code>Pattern: Regex Pattern</code>
                        </div>
                        <p><strong>Result:</strong><br>
                        ✅ <code>/category/blog/my-post</code> → <code>/articles/article/my-post</code><br>
                        ✅ <code>/category/blog/2024/tech</code> → <code>/articles/article/2024/tech</code><br>
                        ✅ <code>/category/blog/news/item-123</code> → <code>/articles/article/news/item-123</code></p>

                        <div style="background: var(--dashboard-card-hover); padding: 10px; border-radius: 4px; margin: 10px 0;">
                            <strong>Example 2 - Product ID Redirect:</strong><br>
                            <code>From: product-id</code> (label only)<br>
                            <code>Regex Pattern: /^\/product-(\d+)$/</code><br>
                            <code>To: /products/view?id=$1</code>
                        </div>
                        <p><strong>Result:</strong><br>
                        ✅ <code>/product-123</code> → <code>/products/view?id=123</code><br>
                        ✅ <code>/product-456</code> → <code>/products/view?id=456</code></p>

                        <div style="background: rgba(239, 68, 68, 0.2); padding: 10px; border-radius: 4px; margin: 10px 0; border: 1px solid var(--dashboard-error);">
                            <strong>⚠️ Regex Syntax:</strong><br>
                            <code>/^  $/</code> = Pattern delimiters (required)<br>
                            <code>^</code> = Start of URL path<br>
                            <code>$</code> = End of URL path<br>
                            <code>(.*)</code> = Capture everything (use $1 in destination)<br>
                            <code>(\d+)</code> = Capture numbers only<br>
                            <code>\/</code> = Escape forward slash<br>
                            <code>\.</code> = Escape dot for literal match
                        </div>
                    </div>
                </details>
            </div>

            <!-- HTTP Status Codes -->
            <div class="tips-section" style="margin-top: 20px;">
                <h4 style="color: var(--dashboard-accent); margin-bottom: 10px;">🔢 HTTP Status Codes Guide</h4>

                <details class="tip-item" style="margin-bottom: 10px; padding: 10px; background: rgba(255, 255, 255, 0.05); border-radius: 4px;">
                    <summary style="cursor: pointer; font-weight: 600; color: var(--dashboard-text-primary);">
                        <strong style="color: var(--dashboard-success);">301</strong> - Permanent Redirect (SEO Friendly)
                    </summary>
                    <div style="padding: 10px; color: var(--dashboard-text-secondary);">
                        <p><strong>Use for:</strong> Permanent URL changes, site restructuring</p>
                        <p><strong>Benefits:</strong> Search engines transfer page rank to new URL</p>
                        <p><strong>Example:</strong> Old domain to new domain, permanent page moves</p>
                    </div>
                </details>

                <details class="tip-item" style="margin-bottom: 10px; padding: 10px; background: rgba(255, 255, 255, 0.05); border-radius: 4px;">
                    <summary style="cursor: pointer; font-weight: 600; color: var(--dashboard-text-primary);">
                        <strong style="color: var(--dashboard-warning);">302</strong> - Temporary Redirect
                    </summary>
                    <div style="padding: 10px; color: var(--dashboard-text-secondary);">
                        <p><strong>Use for:</strong> Temporary changes, A/B testing, seasonal campaigns</p>
                        <p><strong>Note:</strong> Search engines don't transfer page rank</p>
                    </div>
                </details>

                <details class="tip-item" style="margin-bottom: 10px; padding: 10px; background: rgba(255, 255, 255, 0.05); border-radius: 4px;">
                    <summary style="cursor: pointer; font-weight: 600; color: var(--dashboard-text-primary);">
                        <strong style="color: var(--dashboard-error);">410</strong> - Content Deleted (No Destination)
                    </summary>
                    <div style="padding: 10px; color: var(--dashboard-text-secondary);">
                        <p><strong>Use for:</strong> Permanently removed content that won't return</p>
                        <p><strong>Note:</strong> No destination URL needed - page shows as permanently gone</p>
                        <p><strong>Better than 404 for SEO</strong></p>
                    </div>
                </details>

                <details class="tip-item" style="margin-bottom: 10px; padding: 10px; background: rgba(255, 255, 255, 0.05); border-radius: 4px;">
                    <summary style="cursor: pointer; font-weight: 600; color: var(--dashboard-text-primary);">
                        <strong>451</strong> - Unavailable for Legal Reasons
                    </summary>
                    <div style="padding: 10px; color: var(--dashboard-text-secondary);">
                        <p><strong>Use for:</strong> Content blocked due to legal requirements</p>
                    </div>
                </details>
            </div>

            <!-- Common Use Cases -->
            <div class="tips-section" style="margin-top: 20px;">
                <h4 style="color: var(--dashboard-accent); margin-bottom: 10px;">💡 Common Use Cases</h4>

                <div style="background: rgba(34, 197, 94, 0.1); padding: 15px; border-radius: 4px; margin-bottom: 10px; border-left: 3px solid #22c55e;">
                    <strong>⭐ Blog to Articles Migration (WITH Path Preservation):</strong>
                    <p style="margin: 5px 0; color: var(--dashboard-text-secondary);">
                        <strong>Use Wildcard:</strong> <code>/blog/*</code> → <code>/articles/*</code><br>
                        This preserves the entire path structure automatically!
                    </p>
                </div>

                <div style="background: var(--dashboard-card-hover); padding: 15px; border-radius: 4px; margin-bottom: 10px;">
                    <strong>✓ Multiple Old URLs → One New URL:</strong>
                    <p style="margin: 5px 0; color: var(--dashboard-text-secondary);">Add multiple source URLs (click "Add Another") all pointing to same destination.</p>
                </div>

                <div style="background: var(--dashboard-card-hover); padding: 15px; border-radius: 4px; margin-bottom: 10px;">
                    <strong>✓ Entire Section Migration (Simple):</strong>
                    <p style="margin: 5px 0; color: var(--dashboard-text-secondary);">Use "Starts With" pattern for /old-section/ → /new-section/ (doesn't preserve sub-paths)</p>
                </div>

                <div style="background: var(--dashboard-card-hover); padding: 15px; border-radius: 4px; margin-bottom: 10px;">
                    <strong>✓ File Extension Redirect:</strong>
                    <p style="margin: 5px 0; color: var(--dashboard-text-secondary);">Use "Ends With" pattern for .pdf → /downloads/ to redirect all PDF files</p>
                </div>
            </div>

            <!-- Best Practices -->
            <div class="tips-section" style="margin-top: 20px; padding: 15px; background: rgba(59, 130, 246, 0.1); border-radius: 4px;">
                <h4 style="color: var(--dashboard-accent); margin-top: 0;">⚡ Best Practices</h4>
                <ul style="color: var(--dashboard-text-secondary); margin: 0; padding-left: 20px;">
                    <li>Test with 302 first, then change to 301 when confirmed working</li>
                    <li>Use Exact Match when possible (faster performance)</li>
                    <li>Use Regex only when path preservation is needed</li>
                    <li>Monitor hit counts to identify most-used redirects</li>
                    <li>Add descriptions to help identify redirect purpose later</li>
                    <li>Keep redirects active to maintain SEO value</li>
                </ul>
            </div>
        </div>
    </div>

    <table class="form-table add-form-table">
        <tr valign="top">
            <th scope="row">
                Source From:
            </th>
            <td>
                <?php

                $record = [];
                $id = '';
                $source_form = ['' => 'exact'];
                $url_redirect_to = '';
                $http_code = '301';
                $status = 'active';
                $uri = '';

                $get_data =  metasync_sanitize_input_array($_GET);

                if (isset($get_data['action'])) {
                    if (isset($get_data['uri']) && ($get_data['action'] == 'redirect' && !empty($get_data['uri']))) {
                        // Decode the URI and sanitize it properly
                        $uri = urldecode($get_data['uri']);
                        $uri = sanitize_text_field($uri);

                        // Ensure URI starts with /
                        if (!str_starts_with($uri, '/')) {
                            $uri = '/' . $uri;
                        }
                    }

                    if (isset($get_data['id']) && $get_data['action'] == 'edit') {
                        // Get database instance
                        $db_instance = isset($this) && isset($this->db_redirection) ? $this->db_redirection : null;

                        if ($db_instance) {
                            $record = $db_instance->find(intval($get_data['id']));

                            if ($record) {
                                $id = isset($record->id) ? esc_attr($record->id) : '';
                                $source_form = isset($record->sources_from) && $record->sources_from ? unserialize($record->sources_from) : [];
                                $url_redirect_to = isset($record->url_redirect_to) ? esc_attr($record->url_redirect_to) : '';
                                $http_code = isset($record->http_code) ? esc_attr($record->http_code) : '';
                                $status = isset($record->status) ? esc_attr($record->status) : '';
                            }
                        }
                    }
                }

                $search_type = [
                    ['name' => 'Exact Match', 'value' => 'exact'],
                    ['name' => 'Starts With', 'value' => 'start'],
                    ['name' => 'Ends With', 'value' => 'end'],
                    ['name' => 'Wildcard (*)', 'value' => 'wildcard'],
                    ['name' => 'Regex Pattern', 'value' => 'regex'],
                ];

                ?>
                <ul id="source_urls">

                    <?php

                    foreach ($source_form as $source_name => $source_type) {

                    ?>
                        <li>
                            <input type="text" class="regular-text" name="source_url[]" value="<?php echo $uri ? esc_attr($uri) : esc_attr($source_name) ?>">
                            <select name="search_type[]">
                                <?php
                                foreach ($search_type as $type) {
                                    printf('<option value="%s" %s >%s</option>', esc_attr($type['value']), selected(esc_attr($type['value']), esc_attr($source_type)), esc_attr($type['name']));
                                }
                                ?>
                            </select>
                            <button type="button" class="source_url_delete">Remove</button>
                        </li>
                    <?php } ?>

                </ul>

                <?php
                printf(' <input type="hidden" name="redirect_id" value="%s"/>', esc_attr($id));
                printf(' <input class="button-secondary" type="button" id="addNewSourceUrl" value="Add Another">');
                ?>

            </td>
        </tr>

        <tr valign="top" id="destination" class="<?php if ($http_code == '410' || $http_code == '451') {
                                                        echo esc_attr('hide');
                                                    } ?>">
            <th scope="row">
                Destination URL:
            </th>
            <td>
                <input type="text" class="regular-text" name="destination_url" id="destination_url" value="<?php echo esc_url($url_redirect_to) ?>">
            </td>
        </tr>

        <tr valign="top">
            <th scope="row">
                Redirection Type:
            </th>
            <td>
                <ul>
                    <li>
                        <label>
                            <input type="radio" name="redirect_type" class="redirect_type" value="301" <?php checked($http_code, '301'); ?>>
                            301 Permanent Move
                        </label>
                    </li>
                    <li>
                        <label>
                            <input type="radio" name="redirect_type" class="redirect_type" value="302" <?php checked($http_code, '302'); ?>>
                            302 Temporary Move
                        </label>
                    </li>
                    <li>
                        <label>
                            <input type="radio" name="redirect_type" class="redirect_type" value="307" <?php checked($http_code, '307'); ?>>
                            307 Temporary Redirect
                        </label>
                    </li>
                    <li>
                        <label>
                            <input type="radio" name="redirect_type" class="redirect_type" value="410" <?php checked($http_code, '410'); ?>>
                            410 Content Deleted
                        </label>
                    </li>
                    <li>
                        <label>
                            <input type="radio" name="redirect_type" class="redirect_type" value="451" <?php checked($http_code, '451'); ?>>
                            451 Content Unavailable for Legal Reasons
                        </label>
                    </li>
                </ul>
            </td>
        </tr>

        <tr valign="top" id="regex_pattern_row" style="display: none;">
            <th scope="row">
                Regex Pattern:
            </th>
            <td>
                <input type="text" class="regular-text" name="regex_pattern" id="regex_pattern" 
                       value="<?php echo isset($record->regex_pattern) ? esc_attr($record->regex_pattern) : ''; ?>" 
                       placeholder="/^\/old-path\/.*$/">
                <p class="description">
                    Enter a valid regex pattern. Example: <code>/^\/old-path\/.*$/</code> to match all URLs starting with /old-path/
                </p>
            </td>
        </tr>

        <tr valign="top">
            <th scope="row">
                Description:
            </th>
            <td>
                <input type="text" class="regular-text" name="description" id="description" 
                       value="<?php echo isset($record->description) ? esc_attr($record->description) : ''; ?>" 
                       placeholder="Optional description for this redirection">
                <p class="description">
                    Optional description to help identify this redirection rule.
                </p>
            </td>
        </tr>

        <tr valign="top">
            <th scope="row">
                Status:
            </th>
            <td>
                <label class="pr">
                    <input type="radio" name="status" value="active" <?php checked($status, 'active'); ?>>
                    Active
                </label>
                <label class="pr">
                    <input type="radio" name="status" value="inactive" <?php checked($status, 'inactive'); ?>>
                    Inactive
                </label>
            </td>
        </tr>

        <tr valign="top">
            <td colspan="2">
                <input type="submit" name="submit" class="button button-primary" value="Save">
                <input type="button" id="cancel-redirection" class="button button-secondary" value="Cancel" data-cancel-url="<?php echo esc_url(admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '-redirections')); ?>">
            </td>
        </tr>
    </table>

</div>

<?php
$get_data =  metasync_sanitize_input_array($_GET);
if (isset($get_data['action']) && ($get_data['action'] == 'edit' || $get_data['action'] == 'redirect' || $get_data['action'] == 'add')) {
?>
<?php } ?>
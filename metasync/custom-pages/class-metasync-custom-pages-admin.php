<?php

/**
 * Custom Pages Admin Interface
 *
 * Provides the admin interface for managing custom HTML pages
 *
 * @package    Metasync
 * @subpackage Metasync/custom-pages
 * @since      1.0.0
 */

class Metasync_Custom_Pages_Admin
{
    /**
     * Render the Custom Pages management interface
     */
    public static function render_admin_page()
    {
        // Check user capabilities
        if (!Metasync::current_user_has_plugin_access()) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'metasync'));
        }

        // Handle actions
        self::handle_actions();

        ?>
        <!-- Custom HTML Pages Dashboard -->
        <div class="metasync-custom-pages-dashboard">
            <?php self::render_notice(); ?>
            
            <div class="dashboard-card" style="text-align: center; margin-bottom: 20px;">
                <a href="<?php echo esc_url(admin_url('post-new.php?post_type=page&metasync_html_page=1')); ?>" 
                   class="button button-primary button-large" style="font-size: 14px; padding: 10px 24px;">
                    <span class="dashicons dashicons-plus-alt" style="font-size: 16px; vertical-align: middle;"></span>
                    Add New Custom HTML Page
                </a>
            </div>

            <div class="metasync-info-cards-grid">
                <div class="dashboard-card">
                    <h2 style="color: var(--dashboard-accent, #3b82f6); margin-top: 0; font-size: 16px;">📄 About Custom HTML Pages</h2>
                    <p style="color: var(--dashboard-text-secondary); font-size: 14px; line-height: 1.6;">
                        Create dedicated HTML pages that are served directly <strong>without any theme styling</strong>. 
                        Perfect for:
                    </p>
                    <ul style="color: var(--dashboard-text-secondary); font-size: 14px; line-height: 1.8; margin-left: 20px;">
                        <li>Landing pages with custom designs</li>
                        <li>Marketing pages built with external tools</li>
                        <li>Standalone HTML pages with embedded CSS/JS</li>
                        <li>A/B testing pages with unique layouts</li>
                        <li><strong>Works with <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?> and all other MetaSync features!</strong></li>
                    </ul>
                    <p style="margin-top: 15px; padding: 10px; background: rgba(59, 130, 246, 0.1); border-left: 4px solid var(--dashboard-accent, #3b82f6); font-size: 13px; color: var(--dashboard-text-secondary);">
                        💡 <strong>Note:</strong> Pages created here won't interfere with your regular pages or page builders like Elementor, Divi, etc.
                    </p>
                </div>

                <div class="dashboard-card">
                    <h3 style="color: var(--dashboard-accent, #3b82f6); margin-top: 0; font-size: 16px;">🚀 How to Use</h3>
                    <ol style="color: var(--dashboard-text-secondary); font-size: 14px; line-height: 1.8; margin-left: 20px;">
                        <li>Click <strong>"Add New Custom HTML Page"</strong> above</li>
                        <li>Set your page title and URL slug</li>
                        <li>You'll see the <strong>"Custom HTML Settings"</strong> panel</li>
                        <li>Check <strong>"Enable Raw HTML Mode"</strong> (auto-enabled for new pages)</li>
                        <li>Upload your HTML file OR paste HTML code directly</li>
                        <li>Click <strong>"Publish"</strong> to make it live</li>
                    </ol>
                    <p style="margin-top: 15px; padding: 10px; background: rgba(255, 193, 7, 0.1); border-left: 4px solid #ffc107; font-size: 13px; color: var(--dashboard-text-secondary);">
                        💡 <strong>Tip:</strong> These pages work with <?php echo esc_html(Metasync::get_whitelabel_otto_name()); ?>, so your custom HTML will get AI-powered SEO enhancements!
                    </p>
                </div>
            </div>

            <?php self::render_pages_table(); ?>
        </div>

        <style>
            /* Custom HTML Pages Dashboard Styles */
            .metasync-custom-pages-dashboard {
                max-width: 1400px;
            }
            
            .metasync-info-cards-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
                gap: 20px;
                margin-bottom: 30px;
            }
            
            /* Pages Table - Match plugin table styling */
            .metasync-pages-table {
                margin-top: 0;
            }
            
            .metasync-pages-table h2 {
                margin: 0 0 20px 0;
                padding: 0;
                background: none;
                border: none;
                font-size: 18px;
                font-weight: 600;
                color: var(--dashboard-text-primary, #ffffff);
            }
            
            .metasync-pages-table table {
                width: 100%;
                border-collapse: collapse;
                background: var(--dashboard-card-bg, #1a1f26);
                border: 1px solid var(--dashboard-border, #374151);
                border-radius: 8px;
                overflow: hidden;
            }
            
            .metasync-pages-table th {
                background: var(--dashboard-card-bg, #22272e);
                padding: 12px 15px;
                text-align: left;
                font-weight: 600;
                font-size: 13px;
                color: var(--dashboard-text-secondary, #9ca3af);
                border-bottom: 1px solid var(--dashboard-border, #374151);
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .metasync-pages-table td {
                padding: 12px 15px;
                border-bottom: 1px solid var(--dashboard-border, #374151);
                vertical-align: middle;
                font-size: 14px;
                color: var(--dashboard-text-primary, #ffffff);
            }
            
            .metasync-pages-table tbody tr {
                transition: background-color 0.2s ease;
            }
            
            .metasync-pages-table tbody tr:hover {
                background: var(--dashboard-card-hover, #222831);
            }
            
            .metasync-pages-table tbody tr:last-child td {
                border-bottom: none;
            }
            
            .metasync-pages-table td a {
                color: var(--dashboard-accent, #3b82f6);
                text-decoration: none;
                font-weight: 500;
            }
            
            .metasync-pages-table td a:hover {
                color: var(--dashboard-accent-hover, #60a5fa);
                text-decoration: underline;
            }
            
            .metasync-page-status {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 4px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .metasync-page-status.metasync-status-publish {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }
            
            .metasync-page-status.metasync-status-draft {
                background: #fff3cd;
                color: #856404;
                border: 1px solid #ffeaa7;
            }
            
            .metasync-page-status.metasync-status-pending {
                background: #d1ecf1;
                color: #0c5460;
                border: 1px solid #bee5eb;
            }
            
            .metasync-page-actions {
                white-space: nowrap;
            }
            
            .metasync-page-actions .button {
                margin-right: 5px;
                font-size: 13px;
            }
            
            .metasync-page-url {
                color: var(--dashboard-accent, #3b82f6);
                font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, monospace;
                font-size: 13px;
                word-break: break-all;
            }
            
            .metasync-html-badge {
                display: inline-block;
                padding: 3px 8px;
                background: var(--dashboard-accent, #3b82f6);
                color: #fff;
                border-radius: 4px;
                font-size: 10px;
                font-weight: 600;
                margin-left: 8px;
                vertical-align: middle;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .metasync-api-badge {
                background: linear-gradient(135deg, #38bdf8 0%, #0ea5e9 100%);
                box-shadow: 0 2px 4px rgba(14, 165, 233, 0.3);
            }
            
            .metasync-empty-state {
                text-align: center;
                padding: 60px 20px;
                background: var(--dashboard-card-bg, #1a1f26);
                border: 2px dashed var(--dashboard-border, #374151);
                border-radius: 8px;
                margin: 0;
            }
            
            .metasync-empty-state h3 {
                color: var(--dashboard-text-primary, #ffffff);
                margin-bottom: 10px;
                font-size: 20px;
            }
            
            .metasync-empty-state p {
                color: var(--dashboard-text-secondary, #9ca3af);
                margin-bottom: 20px;
                font-size: 14px;
            }
            
            @media (max-width: 1024px) {
                .metasync-info-cards-grid {
                    grid-template-columns: 1fr;
                }
            }
            
            @media (max-width: 782px) {
                .metasync-pages-table table {
                    font-size: 12px;
                }
                
                .metasync-pages-table th,
                .metasync-pages-table td {
                    padding: 10px 8px;
                }
                
                .metasync-page-actions .button {
                    margin-bottom: 5px;
                }
            }
        </style>
        <?php
    }

    /**
     * Render pages table
     */
    private static function render_pages_table()
    {
        $pages = Metasync_Custom_Pages::get_custom_pages();

        ?>
        <div class="metasync-pages-table">
            <h2>Your Custom Pages</h2>
            
            <?php if (empty($pages)): ?>
                <div class="metasync-empty-state">
                    <h3>📄 No Custom HTML Pages Yet</h3>
                    <p>Create your first custom HTML page to get started!</p>
                    <a href="<?php echo esc_url(admin_url('post-new.php?post_type=page&metasync_html_page=1')); ?>" 
                       class="button button-primary button-large">
                        <span class="dashicons dashicons-plus-alt" style="margin-top: 3px;"></span>
                        Create Your First Custom HTML Page
                    </a>
                    <p style="margin-top: 15px; color: #666; font-size: 13px;">
                        Pages created here will have the Custom HTML Settings panel for uploading/editing HTML.
                    </p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 40%;">Page Title</th>
                            <th style="width: 25%;">URL</th>
                            <th style="width: 15%;">Status</th>
                            <th style="width: 20%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pages as $page):
                            $html_enabled = get_post_meta($page->ID, Metasync_Custom_Pages::META_HTML_ENABLED, true);
                            $created_via_api = get_post_meta($page->ID, Metasync_Custom_Pages::META_CREATED_VIA_API, true);
                            $page_url = get_permalink($page->ID);
                            $edit_url = get_edit_post_link($page->ID);
                            $delete_url = get_delete_post_link($page->ID);
                        ?>
                            <tr>
                                <td>
                                    <strong>
                                        <a href="<?php echo esc_url($edit_url); ?>">
                                            <?php echo esc_html($page->post_title ?: '(No title)'); ?>
                                        </a>
                                    </strong>
                                    <?php if ($created_via_api === '1'): ?>
                                        <span class="metasync-html-badge metasync-api-badge">API</span>
                                    <?php endif; ?>
                                    <?php if ($html_enabled === '1'): ?>
                                        <span class="metasync-html-badge">RAW HTML</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($page_url); ?>" 
                                       target="_blank" 
                                       class="metasync-page-url">
                                        <?php echo esc_html(wp_make_link_relative($page_url)); ?>
                                        <span class="dashicons dashicons-external" style="font-size: 14px;"></span>
                                    </a>
                                </td>
                                <td>
                                    <span class="metasync-page-status metasync-status-<?php echo esc_attr($page->post_status); ?>">
                                        <?php echo esc_html(ucfirst($page->post_status)); ?>
                                    </span>
                                </td>
                                <td class="metasync-page-actions">
                                    <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">
                                        <span class="dashicons dashicons-edit" style="font-size: 14px; margin-top: 3px;"></span>
                                        Edit
                                    </a>
                                    <?php if ($page->post_status === 'publish'): ?>
                                        <a href="<?php echo esc_url($page_url); ?>" 
                                           target="_blank" 
                                           class="button button-small button-primary">
                                            <span class="dashicons dashicons-external" style="font-size: 14px; margin-top: 3px;"></span>
                                            View
                                        </a>
                                    <?php else: ?>
                                        <a href="<?php echo esc_url(add_query_arg('preview', 'true', $page_url)); ?>" 
                                           target="_blank" 
                                           class="button button-small">
                                            <span class="dashicons dashicons-visibility" style="font-size: 14px; margin-top: 3px;"></span>
                                            Preview
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handle admin actions
     */
    private static function handle_actions()
    {
        // Handle any custom actions here (bulk delete, etc.)
        if (!isset($_GET['action'])) {
            return;
        }

        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'metasync_custom_pages_action')) {
            return;
        }

        $action = sanitize_text_field($_GET['action']);

        switch ($action) {
            case 'delete':
                if (isset($_GET['page_id'])) {
                    $page_id = intval($_GET['page_id']);
                    if (current_user_can('delete_post', $page_id)) {
                        wp_delete_post($page_id, true);
                        add_settings_error(
                            'metasync_custom_pages',
                            'page_deleted',
                            'Custom page deleted successfully.',
                            'success'
                        );
                    }
                }
                break;
        }
    }

    /**
     * Render admin notices
     */
    private static function render_notice()
    {
        settings_errors('metasync_custom_pages');
    }
}


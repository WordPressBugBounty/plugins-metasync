<?php
/**
 * Import External Data View
 *
 * @package Metasync
 */

if (!defined('ABSPATH')) {
    exit;
}

$importer = new Metasync_External_Importer($this->db_redirection);

// Define sections
$sections = [
    'seo_metadata' => [
        'title' => 'SEO Metadata',
        'icon' => 'dashicons-admin-settings',
        'desc' => 'Import SEO titles and descriptions from Yoast, Rank Math, or All in One SEO. One-click migration to preserve your search rankings.'
    ],
    'redirections' => [
        'title' => 'Redirections',
        'icon' => 'dashicons-randomize',
        'desc' => 'Import 301/302 redirections and 410 content deleted rules.'
    ],
    'sitemap' => [
        'title' => 'Sitemap Settings',
        'icon' => 'dashicons-networking',
        'desc' => 'Import sitemap configuration (enabled status, excluded post types).'
    ],
    'robots' => [
        'title' => 'Robots.txt',
        'icon' => 'dashicons-editor-code',
        'desc' => 'Import robots.txt content.'
    ],
    'indexation' => [
        'title' => 'Indexation Options',
        'icon' => 'dashicons-visibility',
        'desc' => 'Import per-post robots meta settings (noindex, nofollow, noarchive, etc.) and canonical URLs.'
    ],
    'schema' => [
        'title' => 'Schema Settings',
        'icon' => 'dashicons-layout',
        'desc' => 'Import per-post schema markup (Article, FAQPage, Product, Recipe) with field mapping and variable substitution.'
    ]
];

// Get active tab
$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'seo_metadata';
if (!array_key_exists($active_tab, $sections)) {
    $active_tab = 'seo_metadata';
}
?>

<style type="text/css">
    /* Root Variables - Dashboard Color Scheme */
    :root {
        --dashboard-bg: #0f1419;
        --dashboard-card-bg: #1a1f26;
        --dashboard-card-hover: #222831;
        --dashboard-text-primary: #ffffff;
        --dashboard-text-secondary: #9ca3af;
        --dashboard-accent: #3b82f6;
        --dashboard-accent-hover: #2563eb;
        --dashboard-success: #10b981;
        --dashboard-warning: #f59e0b;
        --dashboard-error: #ef4444;
        --dashboard-border: #374151;
        --dashboard-gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        --dashboard-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.1);
        --dashboard-shadow-hover: 0 20px 25px -5px rgba(0, 0, 0, 0.4), 0 10px 10px -5px rgba(0, 0, 0, 0.2);
    }

    /* Page background */
    body {
        background: var(--dashboard-bg) !important;
    }

    .metasync-dashboard-wrap {
        background: var(--dashboard-bg) !important;
        color: var(--dashboard-text-primary) !important;
        margin: 0 0 0 -20px;
        padding: 20px;
        max-width: 100% !important;
    }

    /* Reusing and adapting styles from Redirection Importer */
    .metasync-import-wrapper {
        display: flex;
        gap: 20px;
        margin-top: 20px;
        max-width: 1400px;
    }

    /* Sidebar Navigation */
    .metasync-import-nav {
        width: 250px;
        flex-shrink: 0;
        background: var(--dashboard-card-bg);
        border: 1px solid var(--dashboard-border);
        border-radius: 12px;
        overflow: hidden;
        box-shadow: var(--dashboard-shadow);
        align-self: flex-start;
    }

    .metasync-nav-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 15px 20px;
        color: var(--dashboard-text-secondary);
        text-decoration: none;
        border-bottom: 1px solid var(--dashboard-border);
        transition: all 0.2s ease;
        font-weight: 500;
        font-size: 14px;
    }

    .metasync-nav-item:last-child {
        border-bottom: none;
    }

    .metasync-nav-item:hover {
        background: var(--dashboard-card-hover);
        color: var(--dashboard-text-primary);
    }

    .metasync-nav-item.active {
        background: var(--dashboard-accent);
        color: white;
        border-color: var(--dashboard-accent);
    }

    .metasync-nav-item .dashicons {
        font-size: 18px;
        width: 18px;
        height: 18px;
    }

    /* Main Content Area */
    .metasync-import-content {
        flex: 1;
        min-width: 0;
    }

    .metasync-section-header {
        background: var(--dashboard-card-bg);
        border: 1px solid var(--dashboard-border);
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: var(--dashboard-shadow);
    }

    .metasync-section-title {
        color: var(--dashboard-text-primary);
        margin: 0 0 10px 0;
        font-size: 1.5rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .metasync-section-desc {
        color: var(--dashboard-text-secondary);
        margin: 0;
        font-size: 14px;
        line-height: 1.6;
    }

    /* Plugin Grid (Reused) */
    .metasync-plugins-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 24px;
    }

    .metasync-plugin-card {
        background: var(--dashboard-card-bg);
        border: 1px solid var(--dashboard-border);
        border-radius: 12px;
        padding: 24px;
        box-shadow: var(--dashboard-shadow);
        transition: all 0.3s ease;
        display: flex;
        flex-direction: column;
    }

    .metasync-plugin-card:hover {
        background: var(--dashboard-card-hover);
        box-shadow: var(--dashboard-shadow-hover);
        transform: translateY(-2px);
    }

    .metasync-plugin-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 16px;
    }

    .metasync-plugin-title {
        color: var(--dashboard-text-primary);
        font-size: 1.1rem;
        font-weight: 600;
        margin: 0;
    }

    .metasync-plugin-badge {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .metasync-badge-available {
        background: rgba(16, 185, 129, 0.15);
        color: var(--dashboard-success);
        border: 1px solid var(--dashboard-success);
    }

    .metasync-badge-unavailable {
        background: rgba(156, 163, 175, 0.15);
        color: var(--dashboard-text-secondary);
        border: 1px solid var(--dashboard-border);
    }

    .metasync-plugin-body {
        flex: 1;
        margin-bottom: 20px;
        color: var(--dashboard-text-secondary);
        font-size: 13px;
        line-height: 1.5;
    }

    .metasync-import-btn {
        background: var(--dashboard-accent);
        color: white;
        border: none;
        border-radius: 8px;
        padding: 10px 20px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        width: 100%;
        text-align: center;
    }

    .metasync-import-btn:hover:not(:disabled) {
        background: var(--dashboard-accent-hover);
        transform: translateY(-1px);
    }

    .metasync-import-btn:disabled {
        background: var(--dashboard-card-hover);
        color: var(--dashboard-text-secondary);
        cursor: not-allowed;
        border: 1px solid var(--dashboard-border);
    }
    
    .metasync-import-btn.success {
        background: var(--dashboard-success);
        pointer-events: none;
    }

    .metasync-import-result {
        margin-top: 15px;
        padding: 12px;
        border-radius: 6px;
        font-size: 13px;
        display: none;
    }

    .metasync-import-result.success {
        background: rgba(16, 185, 129, 0.1);
        color: var(--dashboard-success);
        border: 1px solid var(--dashboard-success);
    }

    .metasync-import-result.error {
        background: rgba(239, 68, 68, 0.1);
        color: var(--dashboard-error);
        border: 1px solid var(--dashboard-error);
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .metasync-import-wrapper {
            flex-direction: column;
        }
        .metasync-import-nav {
            width: 100%;
            display: flex;
            overflow-x: auto;
        }
        .metasync-nav-item {
            white-space: nowrap;
            border-bottom: none;
            border-right: 1px solid var(--dashboard-border);
        }
    }

    /* Description text styling */
    .wrap .description,
    .wrap p.description {
        color: var(--dashboard-text-secondary, #9ca3af) !important;
        font-size: 13px !important;
        line-height: 1.5 !important;
    }

    /* Modal Overlay */
    .metasync-modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.75);
        z-index: 100000;
        justify-content: center;
        align-items: center;
        backdrop-filter: blur(4px);
    }

    .metasync-modal-overlay.active {
        display: flex;
    }

    /* Modal Container */
    .metasync-modal {
        background: var(--dashboard-card-bg);
        border: 1px solid var(--dashboard-border);
        border-radius: 12px;
        max-width: 500px;
        width: 90%;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        animation: modalSlideIn 0.3s ease-out;
    }

    @keyframes modalSlideIn {
        from {
            transform: translateY(-50px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .metasync-modal-header {
        padding: 24px;
        border-bottom: 1px solid var(--dashboard-border);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .metasync-modal-title {
        color: var(--dashboard-text-primary);
        font-size: 1.25rem;
        font-weight: 600;
        margin: 0;
    }

    .metasync-modal-close {
        background: none;
        border: none;
        color: var(--dashboard-text-secondary);
        font-size: 24px;
        cursor: pointer;
        padding: 0;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 6px;
        transition: all 0.2s;
    }

    .metasync-modal-close:hover {
        background: var(--dashboard-card-hover);
        color: var(--dashboard-text-primary);
    }

    .metasync-modal-body {
        padding: 24px;
    }

    .metasync-modal-footer {
        padding: 16px 24px;
        border-top: 1px solid var(--dashboard-border);
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    /* Checkbox Group */
    .metasync-checkbox-group {
        display: flex;
        flex-direction: column;
        gap: 16px;
        margin-bottom: 20px;
    }

    .metasync-checkbox-item {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 12px;
        background: var(--dashboard-bg);
        border: 1px solid var(--dashboard-border);
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .metasync-checkbox-item:hover {
        background: var(--dashboard-card-hover);
        border-color: var(--dashboard-accent);
    }

    .metasync-checkbox-item input[type="checkbox"] {
        margin: 2px 0 0 0;
        width: 18px;
        height: 18px;
        cursor: pointer;
    }

    .metasync-checkbox-label {
        flex: 1;
        cursor: pointer;
    }

    .metasync-checkbox-label strong {
        color: var(--dashboard-text-primary);
        display: block;
        margin-bottom: 4px;
    }

    .metasync-checkbox-label span {
        color: var(--dashboard-text-secondary);
        font-size: 12px;
        line-height: 1.4;
    }

    /* Modal Buttons */
    .metasync-modal-btn {
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        border: none;
    }

    .metasync-modal-btn-primary {
        background: var(--dashboard-accent);
        color: white;
    }

    .metasync-modal-btn-primary:hover:not(:disabled) {
        background: var(--dashboard-accent-hover);
    }

    .metasync-modal-btn-secondary {
        background: transparent;
        color: var(--dashboard-text-secondary);
        border: 1px solid var(--dashboard-border);
    }

    .metasync-modal-btn-secondary:hover {
        background: var(--dashboard-card-hover);
        color: var(--dashboard-text-primary);
    }

    .metasync-modal-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* Progress Bar */
    .metasync-progress-container {
        display: none;
        margin-top: 16px;
    }

    .metasync-progress-container.active {
        display: block;
    }

    .metasync-progress-bar {
        width: 100%;
        height: 8px;
        background: var(--dashboard-bg);
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 8px;
    }

    .metasync-progress-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--dashboard-accent), var(--dashboard-accent-hover));
        border-radius: 4px;
        transition: width 0.3s ease;
        width: 0%;
    }

    .metasync-progress-text {
        color: var(--dashboard-text-secondary);
        font-size: 12px;
        text-align: center;
    }

    .metasync-progress-status {
        color: var(--dashboard-text-primary);
        font-size: 13px;
        margin-top: 12px;
        text-align: center;
    }
</style>

<div class="wrap metasync-dashboard-wrap">
    
    <?php $this->render_plugin_header('Import External Data'); ?>
    
    <?php
    // Map active tab to top-level menu item for highlighting
    $menu_map = [
        'seo_metadata' => 'general',
        'redirections' => 'redirections',
        'sitemap'      => 'xml_sitemap',
        'robots'       => 'robots_txt',
        'indexation'   => 'seo_controls',
        'schema'       => 'general'
    ];
    $active_menu = isset($menu_map[$active_tab]) ? $menu_map[$active_tab] : 'general';
    $this->render_navigation_menu($active_menu);
    ?>

    <div class="metasync-import-wrapper">
        
        <!-- Navigation Sidebar -->
        <div class="metasync-import-nav">
            <?php foreach ($sections as $key => $section): ?>
                <a href="<?php echo esc_url(add_query_arg('tab', $key)); ?>" 
                   class="metasync-nav-item <?php echo $active_tab === $key ? 'active' : ''; ?>"
                   id="nav-<?php echo esc_attr($key); ?>">
                    <span class="dashicons <?php echo esc_attr($section['icon']); ?>"></span>
                    <?php echo esc_html($section['title']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Content Area -->
        <div class="metasync-import-content">
            <?php 
            $current_section = $sections[$active_tab];
            $plugins = $importer->get_plugins_for_type($active_tab);
            ?>

            <div class="metasync-section-header">
                <h2 class="metasync-section-title">
                    <span class="dashicons <?php echo esc_attr($current_section['icon']); ?>" style="font-size: 24px; width: 24px; height: 24px;"></span>
                    <?php echo esc_html($current_section['title']); ?>
                </h2>
                <p class="metasync-section-desc"><?php echo esc_html($current_section['desc']); ?></p>
            </div>

            <div class="metasync-plugins-grid">
                <?php if (empty($plugins)): ?>
                    <p>No compatible plugins found for this import type.</p>
                <?php else: ?>
                    <?php foreach ($plugins as $plugin): ?>
                        <div class="metasync-plugin-card">
                            <div class="metasync-plugin-header">
                                <h3 class="metasync-plugin-title"><?php echo esc_html($plugin['name']); ?></h3>
                                <?php if ($plugin['has_data']): ?>
                                    <span class="metasync-plugin-badge metasync-badge-available">Available</span>
                                <?php elseif ($plugin['installed']): ?>
                                    <span class="metasync-plugin-badge metasync-badge-unavailable">No Data</span>
                                <?php else: ?>
                                    <span class="metasync-plugin-badge metasync-badge-unavailable">Not Found</span>
                                <?php endif; ?>
                            </div>

                            <div class="metasync-plugin-body">
                                <?php if ($plugin['has_data']): ?>
                                    <?php if ($active_tab === 'seo_metadata'): ?>
                                        Found <strong><?php echo esc_html($plugin['count']); ?></strong> <?php echo $plugin['count'] === 1 ? 'post' : 'posts'; ?> with SEO titles or descriptions ready to import.
                                    <?php elseif ($active_tab === 'redirections'): ?>
                                        Found <strong><?php echo esc_html($plugin['count']); ?></strong> redirections ready to import.
                                    <?php elseif ($active_tab === 'indexation'): ?>
                                        Found <strong><?php echo esc_html($plugin['count']); ?></strong> <?php echo $plugin['count'] === 1 ? 'post' : 'posts'; ?> with indexation settings ready to import.
                                    <?php elseif ($active_tab === 'schema'): ?>
                                        Found <strong><?php echo esc_html($plugin['count']); ?></strong> <?php echo $plugin['count'] === 1 ? 'post' : 'posts'; ?> with schema markup ready to import.
                                    <?php else: ?>
                                        Settings detected and ready to import.
                                    <?php endif; ?>
                                <?php elseif ($plugin['installed']): ?>
                                    Plugin is installed but no importable data was found.
                                <?php else: ?>
                                    Plugin is not installed or active on this site.
                                <?php endif; ?>
                            </div>

                            <button class="metasync-import-btn" 
                                    data-type="<?php echo esc_attr($active_tab); ?>"
                                    data-plugin="<?php echo esc_attr($plugin['key']); ?>"
                                    <?php echo !$plugin['has_data'] ? 'disabled' : ''; ?>>
                                <?php echo $plugin['has_data'] ? 'Import ' . esc_html($current_section['title']) : 'Unavailable'; ?>
                            </button>
                            
                            <div class="metasync-import-result"></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- SEO Metadata Import Options Modal -->
<div class="metasync-modal-overlay" id="metasync-seo-options-modal">
    <div class="metasync-modal">
        <div class="metasync-modal-header">
            <h2 class="metasync-modal-title">Import Options</h2>
            <button class="metasync-modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="metasync-modal-body">
            <div class="metasync-checkbox-group">
                <label class="metasync-checkbox-item">
                    <input type="checkbox" id="import-titles" checked>
                    <div class="metasync-checkbox-label">
                        <strong>Import Titles</strong>
                        <span>Copy SEO title metadata to MetaSync custom fields</span>
                    </div>
                </label>

                <label class="metasync-checkbox-item">
                    <input type="checkbox" id="import-descriptions" checked>
                    <div class="metasync-checkbox-label">
                        <strong>Import Descriptions</strong>
                        <span>Copy SEO description metadata to MetaSync custom fields</span>
                    </div>
                </label>

                <label class="metasync-checkbox-item">
                    <input type="checkbox" id="overwrite-existing">
                    <div class="metasync-checkbox-label">
                        <strong>Overwrite Existing Data</strong>
                        <span>Replace existing MetaSync data if already present (default: skip posts with existing data)</span>
                    </div>
                </label>
            </div>

            <div class="metasync-progress-container">
                <div class="metasync-progress-bar">
                    <div class="metasync-progress-fill"></div>
                </div>
                <div class="metasync-progress-text">0%</div>
                <div class="metasync-progress-status"></div>
            </div>
        </div>
        <div class="metasync-modal-footer">
            <button class="metasync-modal-btn metasync-modal-btn-secondary" id="modal-cancel">Cancel</button>
            <button class="metasync-modal-btn metasync-modal-btn-primary" id="modal-start-import">Start Import</button>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var currentImportData = null;

    // Handle import button clicks
    $('.metasync-import-btn').on('click', function() {
        var btn = $(this);
        var type = btn.data('type');
        var plugin = btn.data('plugin');
        var card = btn.closest('.metasync-plugin-card');

        // Special handling for SEO metadata - show options modal
        if (type === 'seo_metadata') {
            currentImportData = {
                btn: btn,
                type: type,
                plugin: plugin,
                card: card
            };
            $('#metasync-seo-options-modal').addClass('active');
            return;
        }

        // For other import types, proceed directly
        performImport(btn, type, plugin, card);
    });

    // Modal close handlers
    $('.metasync-modal-close, #modal-cancel').on('click', function() {
        $('#metasync-seo-options-modal').removeClass('active');
        $('.metasync-progress-container').removeClass('active');
        $('.metasync-progress-fill').css('width', '0%');
        $('.metasync-progress-text').text('0%');
        $('.metasync-progress-status').text('');
        currentImportData = null;
    });

    // Click outside modal to close
    $('.metasync-modal-overlay').on('click', function(e) {
        if (e.target === this) {
            $(this).find('.metasync-modal-close').click();
        }
    });

    // Start Import button in modal
    $('#modal-start-import').on('click', function() {
        if (!currentImportData) return;

        var options = {
            import_titles: $('#import-titles').is(':checked'),
            import_descriptions: $('#import-descriptions').is(':checked'),
            overwrite_existing: $('#overwrite-existing').is(':checked')
        };

        // Disable form elements
        $('#modal-start-import, #modal-cancel, #import-titles, #import-descriptions, #overwrite-existing')
            .prop('disabled', true);

        // Show progress
        $('.metasync-progress-container').addClass('active');

        // Start batch import
        performBatchImport(currentImportData, options);
    });

    // Perform direct import (for non-SEO metadata types)
    function performImport(btn, type, plugin, card) {
        var resultDiv = card.find('.metasync-import-result');

        btn.prop('disabled', true).text('Importing...');
        resultDiv.removeClass('success error').hide();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'metasync_import_external_data',
                nonce: '<?php echo wp_create_nonce("metasync_import_external_data"); ?>',
                type: type,
                plugin: plugin
            },
            success: function(response) {
                if (response.success) {
                    btn.addClass('success').text('✓ Imported');
                    resultDiv.addClass('success').html(response.data.message).slideDown();
                } else {
                    btn.prop('disabled', false).text('Import ' + type.charAt(0).toUpperCase() + type.slice(1));
                    resultDiv.addClass('error').html(response.data.message || 'Import failed.').slideDown();
                }
            },
            error: function() {
                btn.prop('disabled', false).text('Retry Import');
                resultDiv.addClass('error').html('Network error. Please try again.').slideDown();
            }
        });
    }

    // Perform batch import with progress tracking (for SEO metadata)
    function performBatchImport(importData, options) {
        var offset = 0;
        var totalImported = 0;
        var totalSkipped = 0;

        function processBatch() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'metasync_import_seo_metadata',
                    nonce: '<?php echo wp_create_nonce("metasync_import_seo_metadata"); ?>',
                    plugin: importData.plugin,
                    import_titles: options.import_titles ? 1 : 0,
                    import_descriptions: options.import_descriptions ? 1 : 0,
                    overwrite_existing: options.overwrite_existing ? 1 : 0,
                    offset: offset
                },
                success: function(response) {
                    if (response.success && response.data) {
                        var data = response.data;
                        totalImported += data.imported || 0;
                        totalSkipped += data.skipped || 0;

                        // Update progress bar
                        var percent = data.progress_percent || 0;
                        $('.metasync-progress-fill').css('width', percent + '%');
                        $('.metasync-progress-text').text(percent + '%');
                        $('.metasync-progress-status').text(
                            'Processing... ' + (data.processed || 0) + ' of ' + (data.total || 0) + ' posts'
                        );

                        if (data.is_complete) {
                            // Import complete
                            $('.metasync-progress-status').html(
                                '<strong style="color: var(--dashboard-success);">✓ Import Complete!</strong><br>' +
                                'Imported: ' + totalImported + ' posts | Skipped: ' + totalSkipped + ' posts'
                            );

                            importData.btn.addClass('success').text('✓ Imported');

                            // Close modal after 2 seconds
                            setTimeout(function() {
                                $('#metasync-seo-options-modal .metasync-modal-close').click();
                                // Re-enable button for future imports
                                $('#modal-start-import, #modal-cancel, #import-titles, #import-descriptions, #overwrite-existing')
                                    .prop('disabled', false);
                            }, 2000);
                        } else {
                            // Continue with next batch
                            offset = data.processed || 0;
                            processBatch();
                        }
                    } else {
                        // Error occurred
                        $('.metasync-progress-status').html(
                            '<strong style="color: var(--dashboard-error);">✗ Import Failed</strong><br>' +
                            (response.data && response.data.message ? response.data.message : 'Unknown error occurred')
                        );
                        $('#modal-start-import, #modal-cancel, #import-titles, #import-descriptions, #overwrite-existing')
                            .prop('disabled', false);
                    }
                },
                error: function() {
                    $('.metasync-progress-status').html(
                        '<strong style="color: var(--dashboard-error);">✗ Network Error</strong><br>Please try again'
                    );
                    $('#modal-start-import, #modal-cancel, #import-titles, #import-descriptions, #overwrite-existing')
                        .prop('disabled', false);
                }
            });
        }

        // Start first batch
        processBatch();
    }
});
</script>

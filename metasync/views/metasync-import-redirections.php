<?php
/**
 * Import Redirections Interface
 *
 * @package    Metasync
 * @subpackage Metasync/views
 */

# Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

$available_plugins = $importer->get_available_plugins();
$stats = $importer->get_import_stats();
?>

<style type="text/css">
    /* ===================================
       MetaSync Import - Production Styles
       =================================== */
    
    /* Container */
    .metasync-import-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px;
    }

    /* Header Section */
    .metasync-import-header {
        background: var(--dashboard-card-bg);
        border: 1px solid var(--dashboard-border);
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: var(--dashboard-shadow);
    }

    .metasync-import-header__title {
        color: var(--dashboard-text-primary);
        margin: 0 0 12px 0;
        font-size: 1.5rem;
        font-weight: 600;
    }

    .metasync-import-header__description {
        color: var(--dashboard-text-secondary);
        margin: 0;
        font-size: 14px;
        line-height: 1.6;
    }

    /* Statistics Cards */
    .metasync-import-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .metasync-stat-card {
        background: var(--dashboard-card-bg);
        border: 1px solid var(--dashboard-border);
        border-radius: 12px;
        padding: 24px;
        text-align: center;
        box-shadow: var(--dashboard-shadow);
        transition: all 0.3s ease;
    }

    .metasync-stat-card:hover {
        background: var(--dashboard-card-hover);
        box-shadow: var(--dashboard-shadow-hover);
        transform: translateY(-2px);
    }

    .metasync-stat-card__value {
        color: var(--dashboard-accent);
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 8px;
        line-height: 1;
    }

    .metasync-stat-card__label {
        color: var(--dashboard-text-secondary);
        font-size: 14px;
        font-weight: 500;
        text-transform: capitalize;
    }

    /* Plugin Cards Grid */
    .metasync-plugins-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 24px;
        margin-bottom: 30px;
    }

    /* Plugin Card */
    .metasync-plugin-card {
        background: var(--dashboard-card-bg);
        border: 1px solid var(--dashboard-border);
        border-radius: 12px;
        padding: 24px;
        box-shadow: var(--dashboard-shadow);
        transition: all 0.3s ease;
        position: relative;
        min-width: 0;
        display: flex;
        flex-direction: column;
    }

    .metasync-plugin-card:hover {
        background: var(--dashboard-card-hover);
        box-shadow: var(--dashboard-shadow-hover);
        transform: translateY(-2px);
    }

    /* Card Header */
    .metasync-plugin-card__header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 16px;
        gap: 12px;
    }

    .metasync-plugin-card__title {
        color: var(--dashboard-text-primary);
        font-size: 1.25rem;
        font-weight: 600;
        margin: 0;
        line-height: 1.3;
    }

    /* Status Badge */
    .metasync-plugin-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .metasync-plugin-badge--available {
        background: rgba(16, 185, 129, 0.15);
        color: var(--dashboard-success);
        border: 1px solid var(--dashboard-success);
    }

    .metasync-plugin-badge--unavailable {
        background: rgba(156, 163, 175, 0.15);
        color: var(--dashboard-text-secondary);
        border: 1px solid var(--dashboard-border);
    }

    .metasync-plugin-badge--no-data {
        background: rgba(245, 158, 11, 0.15);
        color: var(--dashboard-warning);
        border: 1px solid var(--dashboard-warning);
    }

    /* Card Content */
    .metasync-plugin-card__content {
        flex: 1;
        margin-bottom: 20px;
    }

    .metasync-plugin-card__count {
        color: var(--dashboard-text-primary);
        font-size: 14px;
        margin-bottom: 12px;
        font-weight: 500;
    }

    .metasync-plugin-card__count-number {
        color: var(--dashboard-accent);
        font-size: 1.5rem;
        font-weight: 700;
    }

    .metasync-plugin-card__description {
        color: var(--dashboard-text-secondary);
        font-size: 13px;
        line-height: 1.6;
    }

    /* Import Button */
    .metasync-import-btn {
        background: var(--dashboard-accent);
        border: 1px solid var(--dashboard-accent);
        color: white;
        border-radius: 8px;
        padding: 12px 24px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s ease;
        width: 100%;
        text-align: center;
        text-decoration: none;
        word-wrap: break-word;
        white-space: normal;
        line-height: 1.4;
        border: none;
        outline: none;
    }

    .metasync-import-btn:hover:not(:disabled) {
        background: var(--dashboard-accent-hover);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
    }

    .metasync-import-btn:disabled {
        background: var(--dashboard-card-hover);
        color: var(--dashboard-text-secondary);
        cursor: not-allowed;
        opacity: 0.6;
        border: 1px solid var(--dashboard-border);
    }

    .metasync-import-btn--importing {
        background: var(--dashboard-warning);
        pointer-events: none;
    }

    .metasync-import-btn--success {
        background: var(--dashboard-success);
    }

    /* Notice/Info Boxes */
    .metasync-notice {
        background: var(--dashboard-card-bg);
        border-left: 4px solid var(--dashboard-accent);
        border-radius: 8px;
        padding: 16px 20px;
        margin-bottom: 24px;
        color: var(--dashboard-text-secondary);
        font-size: 13px;
        line-height: 1.6;
    }

    .metasync-notice__title {
        color: var(--dashboard-text-primary);
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        font-size: 14px;
    }

    /* Back Button */
    .metasync-back-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: transparent;
        border: 1px solid var(--dashboard-border);
        color: var(--dashboard-text-secondary);
        border-radius: 8px;
        padding: 10px 20px;
        font-weight: 500;
        font-size: 14px;
        text-decoration: none;
        transition: all 0.3s ease;
        margin-bottom: 24px;
    }

    .metasync-back-btn:hover {
        background: var(--dashboard-card-hover);
        color: var(--dashboard-text-primary);
        border-color: var(--dashboard-accent);
        text-decoration: none;
        transform: translateX(-2px);
    }

    /* Loading Spinner */
    .metasync-loading-spinner {
        display: inline-block;
        width: 16px;
        height: 16px;
        border: 2px solid rgba(255, 255, 255, 0.3);
        border-radius: 50%;
        border-top-color: white;
        animation: metasync-spin 1s ease-in-out infinite;
    }

    @keyframes metasync-spin {
        to { transform: rotate(360deg); }
    }

    /* Import Result Box */
    .metasync-import-result {
        margin-top: 16px;
        padding: 14px 16px;
        border-radius: 8px;
        font-size: 13px;
        line-height: 1.6;
        word-wrap: break-word;
        overflow-wrap: break-word;
        max-width: 100%;
    }

    .metasync-import-result--success {
        background: rgba(16, 185, 129, 0.1);
        border: 1px solid var(--dashboard-success);
        color: var(--dashboard-success);
    }

    .metasync-import-result--error {
        background: rgba(239, 68, 68, 0.1);
        border: 1px solid var(--dashboard-error);
        color: var(--dashboard-error);
    }
    
    .metasync-import-result__title {
        display: block;
        margin-bottom: 8px;
        font-size: 14px;
        font-weight: 700;
    }
    
    .metasync-import-result__details {
        margin-top: 8px;
    }

    /* ===================================
       Responsive Styles
       =================================== */
    
    @media (max-width: 1024px) {
        .metasync-plugins-grid {
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
    }
    
    @media (max-width: 768px) {
        .metasync-import-container {
            padding: 15px;
        }

        .metasync-plugins-grid {
            grid-template-columns: 1fr;
            gap: 16px;
        }
        
        .metasync-plugin-card {
            padding: 20px;
        }
        
        .metasync-plugin-card__title {
            font-size: 1.1rem;
        }
        
        .metasync-import-result {
            font-size: 12px;
            padding: 12px;
        }

        .metasync-import-stats {
            gap: 12px;
        }

        .metasync-stat-card__value {
            font-size: 2rem;
        }
    }
    
    @media (max-width: 480px) {
        .metasync-import-container {
            padding: 10px;
        }

        .metasync-plugin-card {
            padding: 16px;
        }
        
        .metasync-plugin-card__title {
            font-size: 1rem;
        }

        .metasync-import-header {
            padding: 20px;
        }

        .metasync-import-header__title {
            font-size: 1.25rem;
        }

        .metasync-stat-card {
            padding: 20px;
        }

        .metasync-stat-card__value {
            font-size: 1.75rem;
        }
    }
</style>

<div class="wrap metasync-import-container">
    <a href="<?php echo esc_url(admin_url('admin.php?page=searchatlas-redirections')); ?>" class="metasync-back-btn">
        <span>←</span> Back to Redirections
    </a>

    <div class="metasync-import-header">
        <h2 class="metasync-import-header__title">Import Redirections from SEO Plugins</h2>
        <p class="metasync-import-header__description">Import existing redirections from popular SEO plugins. MetaSync will automatically detect and import redirections while avoiding duplicates.</p>
    </div>

    <div class="metasync-import-stats">
        <div class="metasync-stat-card">
            <div class="metasync-stat-card__value"><?php echo esc_html($stats['total_redirections']); ?></div>
            <div class="metasync-stat-card__label">Total Redirections</div>
        </div>
        <div class="metasync-stat-card">
            <div class="metasync-stat-card__value"><?php echo esc_html($stats['imported_redirections']); ?></div>
            <div class="metasync-stat-card__label">Previously Imported</div>
        </div>
    </div>

    <div class="metasync-plugins-grid">
        <?php foreach ($available_plugins as $plugin): ?>
            <div class="metasync-plugin-card" data-plugin="<?php echo esc_attr($plugin['key']); ?>">
                <div class="metasync-plugin-card__header">
                    <h3 class="metasync-plugin-card__title"><?php echo esc_html($plugin['name']); ?></h3>
                    <?php if ($plugin['has_data']): ?>
                        <span class="metasync-plugin-badge metasync-plugin-badge--available">
                            <span>✓</span> Available
                        </span>
                    <?php elseif ($plugin['installed']): ?>
                        <span class="metasync-plugin-badge metasync-plugin-badge--no-data">
                            No Data
                        </span>
                    <?php else: ?>
                        <span class="metasync-plugin-badge metasync-plugin-badge--unavailable">
                            Not Found
                        </span>
                    <?php endif; ?>
                </div>

                <div class="metasync-plugin-card__content">
                    <?php if ($plugin['has_data']): ?>
                        <div class="metasync-plugin-card__count">
                            Found <span class="metasync-plugin-card__count-number"><?php echo esc_html($plugin['count']); ?></span> redirection<?php echo $plugin['count'] !== 1 ? 's' : ''; ?>
                        </div>
                        <div class="metasync-plugin-card__description">
                            Click the button below to import all redirections from <?php echo esc_html($plugin['name']); ?>. Duplicates will be automatically skipped.
                        </div>
                    <?php elseif ($plugin['installed']): ?>
                        <div class="metasync-plugin-card__description">
                            <?php echo esc_html($plugin['name']); ?> is installed but no redirections were found.
                        </div>
                    <?php else: ?>
                        <div class="metasync-plugin-card__description">
                            <?php echo esc_html($plugin['name']); ?> is not installed or data table not found.
                        </div>
                    <?php endif; ?>
                </div>

                <button 
                    class="metasync-import-btn" 
                    data-plugin="<?php echo esc_attr($plugin['key']); ?>"
                    <?php echo !$plugin['has_data'] ? 'disabled' : ''; ?>
                >
                    <?php echo $plugin['has_data'] ? 'Import Redirections' : 'No Data Available'; ?>
                </button>

                <div class="metasync-import-result" style="display: none;"></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="metasync-notice">
        <span class="metasync-notice__title">Important Notes</span>
        <ul style="margin: 8px 0 0 20px; padding: 0;">
            <li>Existing redirections will not be affected</li>
            <li>Duplicate redirections are automatically skipped</li>
            <li>All imported redirections will be active</li>
            <li>You can review and modify them after import</li>
        </ul>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('.metasync-import-btn:not(:disabled)').on('click', function() {
        const button = $(this);
        const card = button.closest('.metasync-plugin-card');
        const plugin = button.data('plugin');
        const resultDiv = card.find('.metasync-import-result');

        // Disable button and show loading state
        button.prop('disabled', true);
        button.addClass('metasync-import-btn--importing');
        button.html('<span class="metasync-loading-spinner"></span> Importing...');
        resultDiv.hide();

        // Make AJAX request
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'metasync_import_redirections',
                plugin: plugin,
                nonce: '<?php echo wp_create_nonce('metasync_import_redirections'); ?>'
            },
            timeout: 30000,
            success: function(response) {
                if (response.success && response.data) {
                    button.removeClass('metasync-import-btn--importing').addClass('metasync-import-btn--success');
                    button.html('✓ Import Complete');
                    
                    resultDiv.removeClass('metasync-import-result--error').addClass('metasync-import-result--success');
                    
                    // Build message with proper formatting
                    var message = '<span class="metasync-import-result__title">' + 
                        (response.data.imported > 0 ? 'Success!' : 'Already Imported') + '</span>';
                    message += '<div class="metasync-import-result__details">';
                    message += 'Imported: <strong>' + (response.data.imported || 0) + '</strong><br>';
                    
                    if (response.data.skipped > 0) {
                        message += 'Skipped (duplicates): <strong>' + response.data.skipped + '</strong>';
                    }
                    
                    message += '</div>';
                    
                    resultDiv.html(message);
                    resultDiv.show();

                    // Redirect to redirections page after successful import
                    if (response.data.imported > 0) {
                        setTimeout(function() {
                            window.location.href = '<?php echo admin_url('admin.php?page=searchatlas-redirections'); ?>';
                        }, 2000);
                    }
                } else {
                    button.removeClass('metasync-import-btn--importing');
                    button.prop('disabled', false);
                    button.html('Import Redirections');
                    
                    resultDiv.removeClass('metasync-import-result--success').addClass('metasync-import-result--error');
                    var errorMsg = '<span class="metasync-import-result__title">Error</span>';
                    
                    if (response.data && response.data.message) {
                        errorMsg += '<div class="metasync-import-result__details">' + response.data.message + '</div>';
                    } else {
                        errorMsg += '<div class="metasync-import-result__details">Import failed. Please try again.</div>';
                    }
                    
                    resultDiv.html(errorMsg);
                    resultDiv.show();
                }
            },
            error: function(xhr, status, error) {
                button.removeClass('metasync-import-btn--importing');
                button.prop('disabled', false);
                button.html('Import Redirections');
                
                resultDiv.removeClass('metasync-import-result--success').addClass('metasync-import-result--error');
                resultDiv.html(
                    '<span class="metasync-import-result__title">Connection Error</span>' +
                    '<div class="metasync-import-result__details">Unable to connect to server. Please check your connection and try again.</div>'
                );
                resultDiv.show();
            }
        });
    });
});
</script>


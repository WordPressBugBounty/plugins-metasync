<?php
/**
 * The Report Issue admin page view
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 *
 * @package    Metasync
 * @subpackage Metasync/views
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

# Get general options (same way as used throughout the plugin)
$general_options = Metasync::get_option('general');
if (!is_array($general_options)) {
    $general_options = array();
}
$project_uuid = isset($general_options['otto_pixel_uuid']) ? sanitize_text_field($general_options['otto_pixel_uuid']) : '';

# Get WordPress and plugin information with error handling
global $wp_version;
$active_theme = wp_get_theme();
$theme_name = is_object($active_theme) ? $active_theme->get('Name') : get_template();

# Collect system information
$system_info = array(
    'website_url' => esc_url(home_url()),
    'site_title' => esc_html(get_bloginfo('name')),
    'admin_email' => sanitize_email(get_bloginfo('admin_email')),
    'plugin_version' => defined('METASYNC_VERSION') ? esc_html(METASYNC_VERSION) : '1.0.0',
    'wordpress_version' => esc_html($wp_version),
    'php_version' => esc_html(PHP_VERSION),
    'active_theme' => esc_html($theme_name),
    'memory_limit' => esc_html(ini_get('memory_limit')),
    'multisite' => is_multisite() ? 'Yes' : 'No'
);
?>

<div class="wrap metasync-dashboard-wrap">

    <?php $this->render_plugin_header('Report Issue'); ?>

    <?php $this->render_navigation_menu('report_issue'); ?>

    <div class="metasync-report-issue-container">

        <!-- System Information Card -->
        <div class="dashboard-card metasync-system-info-card">
            <h2>📋 System Information</h2>
            <p>This information will be automatically included with your report.</p>

            <div class="metasync-system-info-grid">
                <div class="metasync-info-item">
                    <span class="metasync-info-label">🌐 Website URL:</span>
                    <span class="metasync-info-value"><?php echo esc_html($system_info['website_url']); ?></span>
                </div>
                <div class="metasync-info-item">
                    <span class="metasync-info-label">🔌 Plugin Version:</span>
                    <span class="metasync-info-value"><?php echo esc_html($system_info['plugin_version']); ?></span>
                </div>
                <div class="metasync-info-item">
                    <span class="metasync-info-label">📦 WordPress Version:</span>
                    <span class="metasync-info-value"><?php echo esc_html($system_info['wordpress_version']); ?></span>
                </div>
                <div class="metasync-info-item">
                    <span class="metasync-info-label">⚙️ PHP Version:</span>
                    <span class="metasync-info-value"><?php echo esc_html($system_info['php_version']); ?></span>
                </div>
                <div class="metasync-info-item">
                    <span class="metasync-info-label">🎨 Active Theme:</span>
                    <span class="metasync-info-value"><?php echo esc_html($system_info['active_theme']); ?></span>
                </div>
                <div class="metasync-info-item">
                    <span class="metasync-info-label">🆔 Project UUID:</span>
                    <span class="metasync-info-value metasync-uuid-value">
                        <?php if (!empty($project_uuid)): ?>
                            <?php echo esc_html($project_uuid); ?>
                        <?php else: ?>
                            <em style="opacity: 0.6;">Not configured yet</em>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Report Form Card -->
        <div class="dashboard-card metasync-report-form-card">
            <h2>✍️ Report an Issue</h2>
            <p>Describe the issue you're experiencing and we'll receive it in our monitoring system.</p>
            
            <?php if (!empty($project_uuid)): ?>
                <div class="metasync-report-title-info">
                    <p><strong>Report Title:</strong> <code>Client Report <?php echo esc_html($project_uuid); ?></code></p>
                    <p class="metasync-form-help-text" style="margin-top: 5px;">This standardized title helps us prioritize your issue in our monitoring system.</p>
                </div>
            <?php endif; ?>

            <form id="metasync-report-issue-form" method="post">
                <?php wp_nonce_field('metasync_report_issue', 'metasync_report_issue_nonce'); ?>

                <div class="metasync-form-group">
                    <label for="metasync_issue_message" class="metasync-form-label">
                        <strong>Issue Description</strong>
                        <span class="metasync-required-badge">Required</span>
                    </label>
                    <textarea 
                        id="metasync_issue_message" 
                        name="issue_message" 
                        class="metasync-form-textarea"
                        placeholder="Please describe the issue in detail. Include any error messages, steps to reproduce, or relevant information..."
                        rows="8"
                        required
                    ></textarea>
                    <p class="metasync-form-help-text">Minimum 10 characters required</p>
                </div>

                <div class="metasync-form-group">
                    <label for="metasync_issue_severity" class="metasync-form-label">
                        <strong>Severity Level</strong>
                    </label>
                    <select id="metasync_issue_severity" name="issue_severity" class="metasync-form-select">
                        <option value="info">ℹ️ Info - General question or feedback</option>
                        <option value="warning" selected>⚠️ Warning - Non-critical issue</option>
                        <option value="error">❌ Error - Affecting functionality</option>
                        <option value="fatal">🔥 Critical - Site breaking issue</option>
                    </select>
                </div>

                <div class="metasync-form-group">
                    <label class="metasync-checkbox-label">
                        <input type="checkbox" id="metasync_include_user_info" name="include_user_info" checked />
                        <span>Include current user information (username, email)</span>
                    </label>
                </div>


                <div class="metasync-form-actions">
                    <button type="submit" class="button button-primary button-large" id="metasync-submit-report-btn">
                        <span class="metasync-btn-text">📤 Submit Report</span>
                        <span class="metasync-btn-loading">
                            <span class="spinner is-active metasync-spinner"></span>
                            Sending...
                        </span>
                    </button>
                </div>

                <div id="metasync-report-response-message" class="metasync-report-response"></div>
            </form>
        </div>

        <!-- Help Card -->
        <div class="dashboard-card metasync-help-card">
            <h3>💡 Tips for Reporting Issues</h3>
            <ul class="metasync-help-list">
                <li>✅ Be as specific as possible about the issue</li>
                <li>✅ Include steps to reproduce the problem</li>
                <li>✅ Mention any error messages you see</li>
                <li>✅ Include screen recording video link if possible (e.g., Loom, Jam.dev, or similar)</li>
                <li>✅ Note when the issue started occurring</li>
            </ul>
        </div>

    </div>

</div>

<style>
/* MetaSync Report Issue Styles - Matches plugin theme */
.metasync-report-issue-container {
    max-width: 1200px;
    margin: 20px 0;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

/* Card styling matching plugin dashboard cards */
.metasync-report-issue-container .dashboard-card {
    background: var(--dashboard-card-bg, #1a1f26);
    border: 1px solid var(--dashboard-border, #374151);
    border-radius: 12px;
    padding: 28px;
    margin-bottom: 24px;
    box-shadow: var(--dashboard-shadow, 0 10px 15px -3px rgba(0, 0, 0, 0.3));
    transition: none; /* No hover effect */
}

.metasync-report-issue-container .dashboard-card h2 {
    margin-top: 0;
    margin-bottom: 12px;
    font-size: 22px;
    font-weight: 600;
    color: var(--dashboard-text-primary, #ffffff);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.metasync-report-issue-container .dashboard-card h3 {
    margin-top: 0;
    margin-bottom: 12px;
    font-size: 18px;
    font-weight: 600;
    color: var(--dashboard-text-primary, #ffffff);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.metasync-report-issue-container .dashboard-card p {
    color: var(--dashboard-text-secondary, #9ca3af);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.metasync-system-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 16px;
}

.metasync-info-item {
    display: flex;
    flex-direction: column;
    padding: 16px;
    background: rgba(59, 130, 246, 0.1);
    border-radius: 8px;
    border-left: 3px solid var(--dashboard-accent, #3b82f6);
    transition: none; /* No hover effect */
}

.metasync-info-label {
    font-weight: 600;
    color: var(--dashboard-text-primary, #ffffff);
    font-size: 13px;
    margin-bottom: 6px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.metasync-info-value {
    color: var(--dashboard-text-secondary, #9ca3af);
    font-size: 14px;
    word-break: break-all;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.metasync-report-title-info {
    padding: 16px;
    background: rgba(59, 130, 246, 0.08);
    border-radius: 8px;
    border: 1px solid rgba(59, 130, 246, 0.2);
    margin-bottom: 24px;
}

.metasync-report-title-info p {
    margin: 0;
    color: var(--dashboard-text-primary, #ffffff);
    font-size: 14px;
}

.metasync-report-title-info code {
    background: rgba(0, 0, 0, 0.3);
    padding: 4px 10px;
    border-radius: 4px;
    font-family: 'Courier New', monospace;
    color: var(--dashboard-accent, #3b82f6);
    font-size: 13px;
}

.metasync-form-group {
    margin-bottom: 24px;
}

.metasync-form-label {
    display: block;
    margin-bottom: 8px;
    font-size: 14px;
    font-weight: 500;
    color: var(--dashboard-text-primary, #ffffff);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.metasync-required-badge {
    color: var(--dashboard-error, #ef4444);
    font-size: 12px;
    font-weight: normal;
    margin-left: 6px;
}

.metasync-optional-badge {
    color: var(--dashboard-text-secondary, #9ca3af);
    font-size: 12px;
    font-weight: normal;
    margin-left: 6px;
}

.metasync-form-input,
.metasync-form-textarea,
.metasync-form-select {
    width: 100%;
    padding: 12px 16px;
    font-size: 14px;
    border: 1px solid var(--dashboard-border, #374151);
    border-radius: 8px;
    box-sizing: border-box;
    background: rgba(15, 20, 25, 0.5);
    color: var(--dashboard-text-primary, #ffffff);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    transition: border-color 0.2s ease;
}

.metasync-form-input:focus,
.metasync-form-textarea:focus,
.metasync-form-select:focus {
    border-color: var(--dashboard-accent, #3b82f6);
    outline: none;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.metasync-form-input::placeholder,
.metasync-form-textarea::placeholder {
    color: var(--dashboard-text-secondary, #9ca3af);
    opacity: 0.6;
}

.metasync-form-textarea {
    resize: vertical;
    min-height: 150px;
    line-height: 1.6;
}

.metasync-form-select {
    cursor: pointer;
    color: #ffffff !important;
}

.metasync-form-select option {
    color: #ffffff;
    background: var(--dashboard-card-bg, #1a1f26);
}

.metasync-form-help-text {
    margin: 8px 0 0 0;
    font-size: 13px;
    color: var(--dashboard-text-secondary, #9ca3af);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.metasync-checkbox-label {
    display: flex;
    align-items: center;
    cursor: pointer;
    font-size: 14px;
    color: var(--dashboard-text-primary, #ffffff);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.metasync-checkbox-label input[type="checkbox"] {
    margin-right: 10px;
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.metasync-form-actions {
    margin-top: 32px;
}

.metasync-report-issue-container .button-large {
    padding: 12px 28px;
    height: auto;
    font-size: 15px;
    font-weight: 600;
    border-radius: 8px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    transition: opacity 0.2s ease;
}

.metasync-report-issue-container .button-large:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.metasync-btn-loading {
    display: flex;
    align-items: center;
}

.metasync-report-response {
    margin-top: 24px;
    padding: 16px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-weight: 500;
}

.metasync-report-response.success {
    background: rgba(16, 185, 129, 0.15);
    border: 1px solid var(--dashboard-success, #10b981);
    color: var(--dashboard-success, #10b981);
}

.metasync-report-response.error {
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid var(--dashboard-error, #ef4444);
    color: var(--dashboard-error, #ef4444);
}

.metasync-help-list {
    margin: 16px 0;
    padding-left: 24px;
}

.metasync-help-list li {
    margin-bottom: 10px;
    color: var(--dashboard-text-secondary, #9ca3af);
    line-height: 1.7;
    font-size: 14px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

/* UUID value styling */
.metasync-uuid-value {
    font-family: 'Courier New', monospace;
    font-size: 12px;
}

/* Button loading state */
.metasync-btn-loading {
    display: none;
}

.metasync-btn-loading .metasync-spinner {
    float: none;
    margin: 0 5px 0 0;
}

/* Response message hidden by default */
.metasync-report-response {
    display: none;
}

@media (max-width: 768px) {
    .metasync-system-info-grid {
        grid-template-columns: 1fr;
    }
    
    .metasync-report-issue-container .dashboard-card {
        padding: 20px;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Show/hide support access options based on checkbox
    $('#metasync-report-issue-form').on('submit', function(e) {
        e.preventDefault();

        const $form = $(this);
        const $submitBtn = $('#metasync-submit-report-btn');
        const $btnText = $submitBtn.find('.metasync-btn-text');
        const $btnLoading = $submitBtn.find('.metasync-btn-loading');
        const $responseMsg = $('#metasync-report-response-message');
        const $messageField = $('#metasync_issue_message');

        // Validate message length
        const message = $messageField.val() ? $messageField.val().trim() : '';
        if (message.length < 10) {
            $responseMsg
                .removeClass('success')
                .addClass('error')
                .html('❌ Please provide a more detailed description (at least 10 characters).')
                .css('display', 'block');
            return;
        }
        
        // Validate message length (max 5000 characters to prevent abuse)
        if (message.length > 5000) {
            $responseMsg
                .removeClass('success')
                .addClass('error')
                .html('❌ Message is too long. Please limit to 5000 characters.')
                .css('display', 'block');
            return;
        }

        // Disable submit button and show loading
        $submitBtn.prop('disabled', true);
        $btnText.css('display', 'none');
        $btnLoading.css('display', 'flex');
        $responseMsg.css('display', 'none');

        // Prepare form data (title will be auto-generated as "Client Report {UUID}")
        const formData = {
            action: 'metasync_submit_issue_report',
            nonce: $form.find('#metasync_report_issue_nonce').val(),
            issue_message: message,
            issue_severity: $('#metasync_issue_severity').val(),
            include_user_info: $('#metasync_include_user_info').is(':checked'),
        };

        // Submit via AJAX
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                // Ensure response is valid
                if (!response || typeof response !== 'object') {
                    $responseMsg
                        .removeClass('success')
                        .addClass('error')
                        .html('❌ Invalid response from server. Please try again.')
                        .css('display', 'block');
                    return;
                }
                
                if (response.success) {
                    const message = (response.data && response.data.message) ? response.data.message : 'Report submitted successfully!';
                    $responseMsg
                        .removeClass('error')
                        .addClass('success')
                        .text('✅ ' + message) // SECURITY: Use .text() to prevent XSS
                        .css('display', 'block');

                    // Reset form
                    $form[0].reset();

                    // Scroll to success message
                    if ($responseMsg.offset()) {
                        $('html, body').animate({
                            scrollTop: $responseMsg.offset().top - 100
                        }, 500);
                    }
                } else {
                    const errorMessage = (response.data && response.data.message) ? response.data.message : 'Failed to submit report. Please try again.';
                    $responseMsg
                        .removeClass('success')
                        .addClass('error')
                        .text('❌ ' + errorMessage) // SECURITY: Use .text() to prevent XSS
                        .css('display', 'block');
                }
            },
            error: function(xhr, status, error) {
                $responseMsg
                    .removeClass('success')
                    .addClass('error')
                    .html('❌ An error occurred while submitting your report. Please try again later.')
                    .css('display', 'block');
                
                // Log error only in debug mode
                if (typeof console !== 'undefined' && console.error && window.metasyncDebug) {
                    console.error('MetaSync report submission error:', error);
                }
            },
            complete: function() {
                // Re-enable submit button
                $submitBtn.prop('disabled', false);
                $btnText.css('display', 'inline');
                $btnLoading.css('display', 'none');
            }
        });
    });
});
</script>


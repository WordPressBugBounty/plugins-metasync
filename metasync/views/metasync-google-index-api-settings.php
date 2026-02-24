<?php

/**
 * MetaSync - Google Index API Settings
 *
 * This view integrates Google Index API settings into MetaSync's general settings
 *
 * @package MetaSync
 * @subpackage GoogleIndexDirect
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

?>

<div style="padding: 20px;">
   <!-- Requirements (Collapsible) -->
   <details style="margin-top: 20px; padding-top: 15px;">
        <summary style="cursor: pointer; font-weight: 600; margin-bottom: 10px;">📋 Requirements</summary>
        <div style="margin-top: 10px;">
            <ul style="margin-left: 20px;">
                <li>Google Cloud Project with Indexing API enabled</li>
                <li>Service Account with appropriate permissions</li>
                <li>Domain verified in Google Search Console</li>
                <li>Service account added as user in Search Console</li>
            </ul>
        </div>
    </details>    
    
    <!-- Configuration Status -->
    <div style="margin-bottom: 20px;">
        <?php if ($is_configured): ?>
            <div class="notice notice-success inline" style="margin: 0 0 15px 0; padding: 10px;">
                <p style="margin: 0;">
                    <strong>✅ Service Account Configured: </strong><br>
                    <strong>Email:</strong> <?php echo esc_html($service_info['client_email']); ?><br>
                    <strong>Project ID:</strong> <?php echo esc_html($service_info['project_id']); ?>
                </p>
            </div>
        <?php else: ?>
            <div class="notice notice-warning inline" style="margin: 0 0 15px 0; padding: 10px;">
                <p style="margin: 0;">
                    <strong>⚠️ Service Account Not Configured</strong><br>
                    Please provide your Google service account JSON below.
                </p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Settings Fields -->
    <table class="form-table" style="margin-top: 0;">
        <tr>
            <th scope="row" style="width: 200px;">
                <label for="google_index_service_account_json">Service Account JSON</label>
            </th>
            <td>
                <textarea name="google_index_service_account_json" 
                          id="google_index_service_account_json"
                          class="large-text code" 
                          rows="8" 
                          placeholder="Paste your Google service account JSON here..."><?php
                    // Only show placeholder text if not configured
                    if (!$is_configured) {
                        echo esc_textarea('');
                    } else {
                        echo esc_textarea("Service account configured ✅\nPaste new JSON here to update configuration.");
                    }
                ?></textarea>
                <p class="description">
                    <strong>How to get this:</strong><br>
                    1. Go to <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console → Credentials</a><br>
                    2. Create or select a Service Account → Generate JSON key<br>
                    3. Paste the JSON content above or upload the file below
                </p>
            </td>
        </tr>
        
        <tr>
            <th scope="row">
                <label for="google_index_service_account_file">Upload JSON File</label>
            </th>
            <td>
                <input type="file" 
                       name="google_index_service_account_file" 
                       id="google_index_service_account_file"
                       accept=".json" 
                       class="regular-text" />
                <p class="description">
                    Upload your service account JSON file. This will auto-populate the textarea above.
                </p>
            </td>
        </tr>
    </table>
    
    <!-- Action Buttons and Test Results -->
    <?php if ($is_configured): ?>
        <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">
            <button type="button" 
                    id="google-index-test-connection" 
                    class="button button-secondary">
                🧪 Test Connection
            </button>
            
            <button type="button" 
                    id="google-index-clear-config"
                    class="button button-link-delete" 
                    style="margin-left: 10px;">
                🗑️ Clear Configuration
            </button>
            
            <div id="google-index-test-results" style="margin-top: 15px;"></div>
        </div>
    <?php endif; ?>
    
</div>

<style>
/* Additional styles for Google Index API section */
#google-index-test-results .notice {
    margin-top: 10px;
}

#google-index-test-results ul {
    margin-left: 20px;
}

.metasync-accordion-section details[open] summary {
    margin-bottom: 15px;
}

.metasync-accordion-section pre {
    font-size: 12px;
    line-height: 1.4;
}

.metasync-accordion-section .notice.inline {
    display: block;
}
</style>

<!-- File upload handling and unsaved changes integration is now handled by the admin JavaScript -->

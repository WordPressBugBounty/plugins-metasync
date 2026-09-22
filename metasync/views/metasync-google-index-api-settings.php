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

/**
 * Whether this section is rendered outside any settings form.
 *
 * On General Settings and Indexation Control the section sits inside
 * #metaSyncGeneralSetting / #metaSyncSeoControlsForm, whose own save button
 * posts these fields along with everything else. The standalone Instant
 * Indexing page has no such form — its only button ("Save Post Types") posts
 * metasync_post_types and ignores the credentials entirely — so a service
 * account pasted or uploaded there could never be saved. In that context we
 * render a dedicated Save button wired to its own AJAX endpoint.
 *
 * Callers opt in by setting $google_index_standalone before the include.
 */
$google_index_standalone = !empty($google_index_standalone);

?>

<div style="padding: 20px;">
   <!-- Requirements (Collapsible) -->
   <details style="margin-top: 20px; padding-top: 15px;">
        <summary style="cursor: pointer; font-weight: 600; margin-bottom: 10px;">Requirements</summary>
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
                    <strong>Service Account Configured: </strong><br>
                    <strong>Email:</strong> <?php echo esc_html($service_info['client_email']); ?><br>
                    <strong>Project ID:</strong> <?php echo esc_html($service_info['project_id']); ?>
                </p>
            </div>
        <?php else: ?>
            <div class="notice notice-warning inline" style="margin: 0 0 15px 0; padding: 10px;">
                <p style="margin: 0;">
                    <strong>Service Account Not Configured</strong><br>
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
                <?php Metasync::render_tooltip_icon('google_index_service_account_json', 'Paste the private key file from a Google Cloud service account added to your Search Console property. This lets the plugin ask Google to crawl your pages. Treat it like a password. It is stored on your server and never shared.'); ?>
            </th>
            <td>
                <textarea name="google_index_service_account_json" 
                          id="google_index_service_account_json"
                          class="large-text code" 
                          rows="8" 
                          placeholder="Paste your Google service account JSON here..."><?php
                    if ($is_configured && !empty($saved_json_display)) {
                        echo esc_textarea($saved_json_display);
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
                <?php Metasync::render_tooltip_icon('google_index_service_account_file', 'Upload the service account JSON key file you downloaded from Google Cloud instead of pasting it. The file fills in the Service Account JSON box above. It is only stored once you save.'); ?>
            </th>
            <td>
                <input type="file"
                       name="google_index_service_account_file"
                       id="google_index_service_account_file"
                       accept=".json,application/json"
                       class="regular-text" />

                <!--
                    Selected-file row. Hidden until a file is chosen; the admin
                    JS fills in the name and reveals it. "Remove" clears the
                    input and restores the textarea to whatever it held before
                    the file was read, so a mis-click does not destroy a saved
                    (redacted) configuration the user never meant to replace.
                -->
                <p id="google-index-selected-file" style="display:none;margin:8px 0 0;">
                    <span class="dashicons dashicons-media-code" style="margin-top:3px;font-size:15px;width:15px;height:15px;"></span>
                    <strong id="google-index-selected-file-name"></strong>
                    <button type="button"
                            id="google-index-remove-file"
                            class="button-link"
                            style="margin-left:8px;color:var(--dashboard-error, #b32d2e);text-decoration:none;"
                            aria-label="Remove the selected JSON file">&times; Remove</button>
                </p>

                <!-- Inline validation feedback; replaces the old alert(). -->
                <div id="google-index-file-messages" style="margin-top:8px;"></div>

                <p class="description">
                    Upload your service account JSON file. This will auto-populate the textarea above.
                </p>
            </td>
        </tr>
    </table>

    <!-- Action Buttons and Test Results -->
    <?php if ($google_index_standalone): ?>
        <!--
            Standalone Instant Indexing page only. The embedded contexts are
            saved by their own form's save button, so adding a second one there
            would give the page two competing save paths.
        -->
        <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">
            <?php wp_nonce_field('metasync_google_index_account', 'metasync_google_index_account_nonce'); ?>
            <button type="button"
                    id="google-index-save-config"
                    class="button button-primary">
                <span class="dashicons dashicons-yes" style="margin-top:3px;font-size:15px;width:15px;height:15px;"></span> Save Configuration
            </button>

            <?php if ($is_configured): ?>
                <button type="button"
                        id="google-index-test-connection"
                        class="button button-secondary"
                        style="margin-left: 10px;">
                    <span class="dashicons dashicons-yes" style="margin-top:3px;font-size:15px;width:15px;height:15px;"></span> Test Connection
                </button>

                <button type="button"
                        id="google-index-clear-config"
                        class="button button-link-delete"
                        style="margin-left: 10px;">
                    <span class="dashicons dashicons-trash" style="margin-top:3px;font-size:15px;width:15px;height:15px;"></span> Clear Configuration
                </button>
            <?php endif; ?>

            <div id="google-index-save-messages" style="margin-top: 15px;"></div>
            <div id="google-index-test-results" style="margin-top: 15px;"></div>
        </div>
    <?php elseif ($is_configured): ?>
        <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">
            <button type="button"
                    id="google-index-test-connection"
                    class="button button-secondary">
                <span class="dashicons dashicons-yes" style="margin-top:3px;font-size:15px;width:15px;height:15px;"></span> Test Connection
            </button>

            <button type="button"
                    id="google-index-clear-config"
                    class="button button-link-delete"
                    style="margin-left: 10px;">
                <span class="dashicons dashicons-trash" style="margin-top:3px;font-size:15px;width:15px;height:15px;"></span> Clear Configuration
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

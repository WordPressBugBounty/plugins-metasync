/* global metasyncImportRedirData, jQuery, ajaxurl */
/**
 * MetaSync Import Redirections
 *
 * Extracted for Phase 5, #887.
 * Handles importing redirections from other plugins (Yoast, Rank Math, etc.).
 *
 * Localized object: metasyncImportRedirData
 *   - nonce       (string)
 *   - redirectUrl (string)
 *
 * @since Phase 5
 */
jQuery(document).ready(function ($) {
    var nonce = metasyncImportRedirData.nonce;
    var redirectUrl = metasyncImportRedirData.redirectUrl;

    $('.metasync-import-btn:not(:disabled)').on('click', function () {
        var button = $(this);
        var card = button.closest('.metasync-plugin-card');
        var plugin = button.data('plugin');
        var resultDiv = card.find('.metasync-import-result');

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
                nonce: nonce
            },
            timeout: 30000,
            success: function (response) {
                if (response.success && response.data) {
                    button.removeClass('metasync-import-btn--importing').addClass('metasync-import-btn--success');
                    button.html('\u2713 Import Complete');

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
                        setTimeout(function () {
                            window.location.href = redirectUrl;
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
            error: function (xhr, status, error) {
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

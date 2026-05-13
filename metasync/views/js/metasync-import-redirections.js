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
                    button.text('\u2713 Import Complete');

                    resultDiv.removeClass('metasync-import-result--error').addClass('metasync-import-result--success');

                    // Build message with proper formatting using safe DOM construction
                    var $title = $('<span>').addClass('metasync-import-result__title')
                        .text(response.data.imported > 0 ? 'Success!' : 'Already Imported');
                    var $details = $('<div>').addClass('metasync-import-result__details');
                    $details.append('Imported: ').append($('<strong>').text(response.data.imported || 0));
                    if (response.data.skipped > 0) {
                        $details.append(document.createTextNode(' Skipped (duplicates): '))
                            .append($('<strong>').text(response.data.skipped));
                    }

                    resultDiv.empty().append($title).append($details);
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
                    button.text('Import Redirections');

                    resultDiv.removeClass('metasync-import-result--success').addClass('metasync-import-result--error');
                    var $errTitle = $('<span>').addClass('metasync-import-result__title').text('Error');
                    var $errDetails = $('<div>').addClass('metasync-import-result__details');

                    if (response.data && response.data.message) {
                        $errDetails.text(response.data.message);
                    } else {
                        $errDetails.text('Import failed. Please try again.');
                    }

                    resultDiv.empty().append($errTitle).append($errDetails);
                    resultDiv.show();
                }
            },
            error: function (xhr, status, error) {
                button.removeClass('metasync-import-btn--importing');
                button.prop('disabled', false);
                button.text('Import Redirections');

                resultDiv.removeClass('metasync-import-result--success').addClass('metasync-import-result--error');
                resultDiv.empty()
                    .append($('<span>').addClass('metasync-import-result__title').text('Connection Error'))
                    .append($('<div>').addClass('metasync-import-result__details').text('Unable to connect to server. Please check your connection and try again.'));
                resultDiv.show();
            }
        });
    });
});

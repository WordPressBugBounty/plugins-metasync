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

    // The CSV button reuses the .metasync-import-btn styling but is a file
    // upload, so the generic plugin handler must skip it.
    $('.metasync-import-btn:not(:disabled):not(.metasync-import-btn--csv)').on('click', function () {
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

    // CSV upload — the readme-promised import path. Sends the chosen file to
    // the same AJAX action with plugin=csv and renders the same result UI.
    $('.metasync-import-btn--csv').on('click', function () {
        var button = $(this);
        var card = button.closest('.metasync-plugin-card');
        var resultDiv = card.find('.metasync-import-result');
        var fileInput = document.getElementById('metasync-csv-file');
        var file = fileInput && fileInput.files && fileInput.files.length ? fileInput.files[0] : null;

        if (!file) {
            resultDiv.removeClass('metasync-import-result--success').addClass('metasync-import-result--error');
            resultDiv.empty()
                .append($('<span>').addClass('metasync-import-result__title').text('Choose a File'))
                .append($('<div>').addClass('metasync-import-result__details').text('Select a .csv file to import first.'));
            resultDiv.show();
            return;
        }

        button.prop('disabled', true);
        button.addClass('metasync-import-btn--importing');
        button.html('<span class="metasync-loading-spinner"></span> Importing...');
        resultDiv.hide();

        var formData = new FormData();
        formData.append('action', 'metasync_import_redirections');
        formData.append('plugin', 'csv');
        formData.append('nonce', nonce);
        formData.append('csv_file', file);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            timeout: 120000,
            success: function (response) {
                button.prop('disabled', false);
                button.removeClass('metasync-import-btn--importing');

                if (response && response.success) {
                    var importedCount = response.data && response.data.imported ? parseInt(response.data.imported, 10) : 0;
                    button.addClass('metasync-import-btn--success').text('Imported');

                    resultDiv.removeClass('metasync-import-result--error').addClass('metasync-import-result--success');
                    resultDiv.empty()
                        .append($('<span>').addClass('metasync-import-result__title').text('Import Complete'))
                        .append($('<div>').addClass('metasync-import-result__details').text(response.data && response.data.message ? response.data.message : 'Import finished.'));
                    resultDiv.show();

                    if (importedCount > 0) {
                        setTimeout(function () {
                            window.location.href = redirectUrl;
                        }, 2000);
                    }
                } else {
                    button.text('Import CSV');

                    resultDiv.removeClass('metasync-import-result--success').addClass('metasync-import-result--error');
                    resultDiv.empty()
                        .append($('<span>').addClass('metasync-import-result__title').text('Import Failed'))
                        .append($('<div>').addClass('metasync-import-result__details').text(response && response.data && response.data.message ? response.data.message : 'Import failed. Please try again.'));
                    resultDiv.show();
                }
            },
            error: function () {
                button.prop('disabled', false);
                button.removeClass('metasync-import-btn--importing');
                button.text('Import CSV');

                resultDiv.removeClass('metasync-import-result--success').addClass('metasync-import-result--error');
                resultDiv.empty()
                    .append($('<span>').addClass('metasync-import-result__title').text('Connection Error'))
                    .append($('<div>').addClass('metasync-import-result__details').text('Unable to connect to server. Please check your connection and try again.'));
                resultDiv.show();
            }
        });
    });
});

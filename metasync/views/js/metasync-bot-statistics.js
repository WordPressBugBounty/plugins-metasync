/* global metasyncBotStatsData, jQuery, ajaxurl */
/**
 * MetaSync Bot Statistics
 *
 * Extracted for Phase 5, #887.
 * Copy-to-clipboard and reset bot statistics functionality.
 *
 * Localized object: metasyncBotStatsData
 *   - resetNonce (string)
 *
 * @since Phase 5
 */
jQuery(document).ready(function ($) {
    var resetNonce = metasyncBotStatsData.resetNonce;

    // Copy button functionality
    $('.copy-btn').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();

        var $btn = $(this);
        var textToCopy = $btn.data('copy');

        function showSuccess() {
            $btn.addClass('copied');
            $btn.find('.copy-tooltip').text('Copied!');
            setTimeout(function () {
                $btn.removeClass('copied');
                $btn.find('.copy-tooltip').text('Copy');
            }, 2000);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(textToCopy).then(function () {
                showSuccess();
            }).catch(function () {
                fallbackCopy(textToCopy, $btn, showSuccess);
            });
        } else {
            fallbackCopy(textToCopy, $btn, showSuccess);
        }
    });

    function fallbackCopy(text, $btn, successCallback) {
        var $temp = $('<textarea>');
        $temp.css({ position: 'absolute', left: '-9999px' });
        $('body').append($temp);
        $temp.val(text).select();
        try {
            document.execCommand('copy');
            successCallback();
        } catch (err) {
            alert('Failed to copy. Please copy manually.');
        }
        $temp.remove();
    }

    $('#reset-bot-stats').on('click', function (e) {
        e.preventDefault();

        if (!confirm('Are you sure you want to reset all bot detection statistics? This action cannot be undone.')) {
            return;
        }

        var $button = $(this);
        var originalText = $button.html();

        $button.prop('disabled', true).html('\u231B Resetting...');

        $.post(ajaxurl, {
            action: 'metasync_reset_bot_stats',
            nonce: resetNonce
        })
        .done(function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                $button.prop('disabled', false).html(originalText);
            }
        })
        .fail(function () {
            alert('Network error while resetting statistics');
            $button.prop('disabled', false).html(originalText);
        });
    });
});

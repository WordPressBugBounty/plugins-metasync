/* global jQuery, ajaxurl */
/**
 * MetaSync Bing IndexNow console.
 *
 * Extracted from views/metasync-bing-console.php (Phase 5, #887).
 */
jQuery(document).ready(function($) {
    $('#metasync-bing-response').hide();

    $('#metasync-bing-btn-send').on('click', function() {
        const $button = $(this);
        const $url = $('#metasync-bing-url');
        const $response = $('#metasync-bing-response');
        const originalText = $button.html();

        // Validate URLs
        if (!$url.val().trim()) {
            alert('Please enter at least one URL to submit.');
            return;
        }

        // Disable button and show loading state
        $button.html('🔄 Submitting...').prop('disabled', true);

        // Make AJAX request
        jQuery.ajax({
            method: 'POST',
            url: ajaxurl,
            data: {
                action: 'send_bing_indexnow',
                metasync_bing_url: $url.val()
            }
        })
        .done(function(response) {
            $response.show();

            // Parse response
            const urls = $url.val().split('\n').filter(Boolean);
            const urlsDisplay = urls.length > 1 ? urls.length + ' URLs submitted' : urls[0];

            $('.result-urls').html('<strong>Submitted:</strong> ' + urlsDisplay);

            if (response.success) {
                $('.result-status-code').text('✅ Success').css('color', '#4caf50');
                $('.result-message').html(response.message || 'URLs successfully submitted to IndexNow.');
            } else {
                $('.result-status-code').text('❌ Error').css('color', '#f44336');
                $('.result-message').html(response.message || 'Failed to submit URLs. Please check your API key configuration.');
            }

            // Show response details if available
            if (response.response_code) {
                $('.result-message').append('<br><small>Response Code: ' + response.response_code + '</small>');
            }
        })
        .fail(function(xhr, status, error) {
            $response.show();
            $('.result-urls').html('<strong>Error</strong>');
            $('.result-status-code').text('❌ Request Failed').css('color', '#f44336');
            $('.result-message').text('Network error: ' + error);
        })
        .always(function() {
            // Re-enable button
            $button.html(originalText).prop('disabled', false);
        });
    });
});

/* global jQuery, ajaxurl */
/**
 * MetaSync report issue form.
 *
 * Extracted from views/metasync-report-issue.php (Phase 5, #887).
 */
jQuery(document).ready(function($) {
    const CHAR_LIMIT = 1000;
    const $messageField = $('#metasync_issue_message');
    const $charCounter = $('#metasync_char_counter');

    // Update character counter and show feedback
    function updateCharCounter() {
        const len = $messageField.val() ? $messageField.val().length : 0;
        $charCounter.text(len + '/' + CHAR_LIMIT);
        $charCounter.removeClass('metasync-char-counter-warning metasync-char-counter-limit');
        if (len >= CHAR_LIMIT) {
            $charCounter.addClass('metasync-char-counter-limit');
        } else if (len >= CHAR_LIMIT - 50) {
            $charCounter.addClass('metasync-char-counter-warning');
        }
    }

    $messageField.on('input keyup paste', updateCharCounter);
    updateCharCounter(); // Initial state

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

        // Validate message length (max 1000 characters)
        if (message.length > 1000) {
            $responseMsg
                .removeClass('success')
                .addClass('error')
                .html('❌ Message is too long. Please limit to 1000 characters.')
                .css('display', 'block');
            return;
        }

        // Validate file size (max 5MB)
        const $fileInput = $('#metasync_issue_attachment')[0];
        if ($fileInput && $fileInput.files && $fileInput.files[0]) {
            const fileSize = $fileInput.files[0].size;
            const maxSize = 5 * 1024 * 1024; // 5MB
            if (fileSize > maxSize) {
                $responseMsg
                    .removeClass('success')
                    .addClass('error')
                    .html('❌ File size exceeds 5MB. Please choose a smaller file.')
                    .css('display', 'block');
                return;
            }
        }

        // Disable submit button and show loading
        $submitBtn.prop('disabled', true);
        $btnText.css('display', 'none');
        $btnLoading.css('display', 'flex');
        $responseMsg.css('display', 'none');

        // Prepare form data using FormData to support file uploads
        const formData = new FormData();
        formData.append('action', 'metasync_submit_issue_report');
        formData.append('nonce', $form.find('#metasync_report_issue_nonce').val());
        formData.append('issue_message', message);
        formData.append('issue_severity', $('#metasync_issue_severity').val());
        formData.append('include_user_info', $('#metasync_include_user_info').is(':checked'));
        formData.append('grant_support_access', $('#metasync_grant_support_access').is(':checked'));
        formData.append('access_duration', $('#metasync_access_duration').val());

        // Add file if selected
        if ($fileInput && $fileInput.files && $fileInput.files[0]) {
            formData.append('issue_attachment', $fileInput.files[0]);
        }

        // Submit via AJAX
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            dataType: 'json',
            processData: false,
            contentType: false,
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
                    updateCharCounter();

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

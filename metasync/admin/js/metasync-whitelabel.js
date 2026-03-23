/**
 * MetaSync Whitelabel Password Gate
 *
 * Extracted for Phase 5, #887 — Part B JS extraction.
 * Handles the whitelabel password login screen: focus, enter-key submit,
 * and the "Forgot Password" recovery AJAX flow.
 *
 * Localized data object: metasyncWhitelabelData
 *   - recoverNonce (string) — nonce for metasync_recover_password AJAX action
 *
 * @since Phase 5
 * @see   wp_localize_script() call in class-metasync-admin.php
 * @global metasyncWhitelabelData
 */
/* global metasyncWhitelabelData */

jQuery(document).ready(function($) {
    // Focus on password field when page loads
    $('#whitelabel_password').focus();

    // Add enter key support
    $('#whitelabel_password').on('keypress', function(e) {
        if (e.which === 13) {
            $(this).closest('form').submit();
        }
    });

    // Handle forgot password link
    $('#metasync-forgot-password-link').on('click', function(e) {
        e.preventDefault();

        var $link = $(this);
        var $message = $('#metasync-recovery-message');

        // Disable link and show loading state
        $link.css('pointer-events', 'none').css('opacity', '0.6');
        $message.removeClass('success error').hide();
        $message.html('\u23F3 Sending recovery email...').css('background', '#f0f6fc').css('color', '#0c5ba5').css('border', '1px solid #cfe2f3').fadeIn(200);

        // Send AJAX request
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'metasync_recover_password',
                nonce: metasyncWhitelabelData.recoverNonce
            },
            success: function(response) {
                $link.css('pointer-events', 'auto').css('opacity', '1');

                if (response.success) {
                    $message.addClass('success').html('\u2705 ' + response.data.message)
                        .css('background', '#d4edda')
                        .css('color', '#155724')
                        .css('border', '1px solid #c3e6cb');
                } else {
                    $message.addClass('error').html('\u274C ' + response.data.message)
                        .css('background', '#f8d7da')
                        .css('color', '#721c24')
                        .css('border', '1px solid #f5c6cb');
                }
            },
            error: function() {
                $link.css('pointer-events', 'auto').css('opacity', '1');
                $message.addClass('error').html('\u274C An error occurred. Please try again.')
                    .css('background', '#f8d7da')
                    .css('color', '#721c24')
                    .css('border', '1px solid #f5c6cb');
            }
        });
    });
});

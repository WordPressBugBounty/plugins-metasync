/* global metasyncConnectData, jQuery */
/**
 * MetaSync Connect — Whitelabel Validation Modal & Lock Section
 *
 * Extracted for Phase 5, #887 — Part B JS extraction.
 * Combines the whitelabel validation modal (password/recovery-email guards,
 * hide-settings checkbox logic, export button) with the lock-section handler.
 *
 * Localized data object: metasyncConnectData
 *   - optionKey        (string) — plugin option key for form field names
 *   - storedPassword   (string) — current stored settings password
 *   - adminPostUrl     (string) — admin-post.php URL
 *   - exportNonce      (string) — nonce for metasync_export_whitelabel action
 *   - ajaxUrl          (string) — admin-ajax.php URL
 *   - logoutNonceField (string) — full HTML nonce input for whitelabel logout
 *
 * @since Phase 5
 * @see   wp_localize_script() call in class-metasync-admin.php
 */

jQuery(document).ready(function($) {
    var isShowingModal = false;

    // Function to show custom modal
    function showModal(icon, title, message) {
        isShowingModal = true;
        $('#modal-icon').text(icon);
        $('#modal-title').text(title);
        $('#modal-message').html(message);
        $('#metasync-custom-modal').fadeIn(200);

        // Reset flag after modal animation
        setTimeout(function() {
            isShowingModal = false;
        }, 300);
    }

    // Close modal
    $('#modal-close-btn').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $('#metasync-custom-modal').fadeOut(200);
        return false;
    });

    // Close modal when clicking backdrop
    $('#metasync-custom-modal').on('click', function(e) {
        if (e.target === this) {
            $(this).fadeOut(200);
        }
    });

    // Function to check if password is set
    function hasPassword() {
        var passwordField = $('input[name="' + metasyncConnectData.optionKey + '[whitelabel][settings_password]"]');
        var passwordValue = passwordField.val();
        return passwordValue && passwordValue.length > 0;
    }

    // Function to check if recovery email is set
    function hasRecoveryEmail() {
        var recoveryEmailField = $('input[name="' + metasyncConnectData.optionKey + '[whitelabel][recovery_email]"]');
        var emailValue = recoveryEmailField.val();
        return emailValue && emailValue.length > 0 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue);
    }

    // Validate recovery email when password is being set
    var passwordField = $('input[name="' + metasyncConnectData.optionKey + '[whitelabel][settings_password]"]');
    var recoveryEmailField = $('input[name="' + metasyncConnectData.optionKey + '[whitelabel][recovery_email]"]');

    // Real-time validation for recovery email
    passwordField.on('blur', function() {
        if (hasPassword() && !hasRecoveryEmail()) {
            showModal(
                '\uD83D\uDCE7',
                'Recovery Email Required',
                'You must set a <strong>Recovery Email</strong> when setting a password.<br><br>Please enter a valid email address in the <strong>Recovery Email</strong> field.'
            );
            recoveryEmailField.focus();
        }
    });

    // Mark recovery email as required when password is set
    passwordField.on('input', function() {
        if (hasPassword()) {
            recoveryEmailField.attr('required', true);
            recoveryEmailField.closest('tr').find('th').addClass('required-field');
        } else {
            recoveryEmailField.removeAttr('required');
            recoveryEmailField.closest('tr').find('th').removeClass('required-field');
        }
    });

    // Trigger initial check
    passwordField.trigger('input');

    // Store original checkbox state
    var hideSettingsCheckbox = $('#checkbox_hide_settings');
    var originalCheckboxState = hideSettingsCheckbox.is(':checked');

    // Handle Hide Settings checkbox with click event (runs before change)
    hideSettingsCheckbox.on('click', function(e) {
        if (!originalCheckboxState && !hasPassword()) {
            // Prevent the click from checking the box
            e.preventDefault();
            e.stopImmediatePropagation();

            // Show modal asking to set password
            showModal(
                '\uD83D\uDD10',
                'Password Required',
                'You must set a <strong>Settings Password</strong> before enabling "Hide Settings".<br><br>Please scroll up to the <strong>Branding</strong> section and set a password first.'
            );

            // Focus on password field when modal closes
            setTimeout(function() {
                $('input[name="' + metasyncConnectData.optionKey + '[whitelabel][settings_password]"]').focus();
            }, 300);

            return false;
        }

        // Update original state when valid change happens
        setTimeout(function() {
            originalCheckboxState = hideSettingsCheckbox.is(':checked');
        }, 0);
    });

    // Prevent clearing password when Hide Settings is enabled
    var storedPassword = metasyncConnectData.storedPassword;

    passwordField.on('keydown', function(e) {
        var currentValue = $(this).val();

        // If Hide Settings is checked and user tries to clear password (backspace/delete on empty or last char)
        if (hideSettingsCheckbox.is(':checked') &&
            (currentValue.length <= 1 || !currentValue) &&
            (e.keyCode === 8 || e.keyCode === 46)) { // Backspace or Delete

            e.preventDefault();
            e.stopImmediatePropagation();

            // Show warning modal only once
            if (!isShowingModal) {
                showModal(
                    '\uD83D\uDEAB',
                    'Cannot Remove Password',
                    'You cannot remove the <strong>White Label Settings Password</strong> while "Hide Settings" is enabled.<br><br>Please uncheck <strong>"Hide Settings"</strong> first if you want to remove the password.'
                );
            }

            return false;
        }
    });

    // Listen for successful AJAX save to update password status
    $(document).on('metasync_settings_saved', function() {
        // Password status will be checked dynamically via hasPassword() function
    });

    // Close modal with Escape key
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            $('#metasync-custom-modal').fadeOut(200);
        }
    });

    // Handle export whitelabel settings button
    $('#metasync-export-whitelabel-btn').on('click', function(e) {
        e.preventDefault();

        var $button = $(this);
        var originalText = $button.html();

        // Disable button and show loading state
        $button.prop('disabled', true).html('\u23F3 Exporting...');

        // Create a form to submit the export request
        var form = $('<form>', {
            'method': 'POST',
            'action': metasyncConnectData.adminPostUrl,
            'target': '_blank'
        });

        form.append($('<input>', {
            'type': 'hidden',
            'name': 'action',
            'value': 'metasync_export_whitelabel_settings'
        }));

        form.append($('<input>', {
            'type': 'hidden',
            'name': '_wpnonce',
            'value': metasyncConnectData.exportNonce
        }));

        // Append form to body, submit, then remove
        $('body').append(form);
        form.submit();

        // Remove form after a short delay
        setTimeout(function() {
            form.remove();
            $button.prop('disabled', false).html(originalText);
        }, 2000);
    });

    /* ======================================================================
       Lock Section Handler
       ====================================================================== */

    // Ensure ajax URL is available in all admin contexts
    var ajaxUrl = (typeof window.ajaxurl !== 'undefined' && window.ajaxurl)
        ? window.ajaxurl
        : metasyncConnectData.ajaxUrl;

    // Handle Lock Section button clicks
    $('.metasync-lock-btn').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var currentTab = $(this).data('tab');

        // Create a hidden form to submit the logout request
        var logoutForm = $('<form>', {
            'method': 'post',
            'action': ''
        });

        // Add nonce field
        logoutForm.append(metasyncConnectData.logoutNonceField);

        // Add logout field
        logoutForm.append($('<input>', {
            'type': 'hidden',
            'name': 'whitelabel_logout',
            'value': '1'
        }));

        // Append to body and submit
        $('body').append(logoutForm);
        logoutForm.submit();

        return false;
    });
});

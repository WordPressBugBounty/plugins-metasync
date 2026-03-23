/* global metasyncDebugData */
/**
 * MetaSync Debug Mode
 *
 * Extracted for Phase 5, #887 — Part B JS extraction.
 * Handles the debug-mode timer UI and auto-refresh when debug mode expires.
 *
 * Localized data object: metasyncDebugData
 *   - enabled       (bool)   — whether debug mode is currently enabled
 *   - indefinite    (bool)   — whether debug mode is set to indefinite
 *   - timeRemaining (int)    — seconds remaining for debug mode
 *   - statusUrl     (string) — REST endpoint for debug-mode status
 *   - restNonce     (string) — wp_rest nonce for REST requests
 *
 * @since Phase 5
 * @see   wp_localize_script() call in class-metasync-admin.php
 */

jQuery(document).ready(function($) {
    // Show warning when indefinite mode is checked
    $('#indefinite-mode-advanced').on('change', function() {
        if ($(this).is(':checked')) {
            $('#indefinite-warning-advanced').slideDown();
        } else {
            $('#indefinite-warning-advanced').slideUp();
        }
    });

    // Auto-update time remaining every minute
    var initialTimeRemaining = metasyncDebugData.enabled && !metasyncDebugData.indefinite ? metasyncDebugData.timeRemaining : 0;
    var hasReloaded = false;

    function updateDebugTimeRemaining() {
        if (initialTimeRemaining <= 0 || hasReloaded) {
            return;
        }

        $.ajax({
            url: metasyncDebugData.statusUrl,
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', metasyncDebugData.restNonce);
            },
            success: function(response) {
                console.log('MetaSync Debug Mode Status:', response);

                if (response && typeof response.time_remaining !== 'undefined') {
                    if (response.time_remaining_formatted) {
                        $('.debug-time-remaining').text(response.time_remaining_formatted);
                    }

                    if (response.time_remaining <= 0 && initialTimeRemaining > 0 && !hasReloaded) {
                        hasReloaded = true;
                        console.log('Debug mode expired, reloading page...');
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('MetaSync Debug Mode: Failed to update time remaining', error);
            }
        });
    }

    if (metasyncDebugData.enabled && !metasyncDebugData.indefinite && initialTimeRemaining > 0) {
        setTimeout(updateDebugTimeRemaining, 2000);
        setInterval(updateDebugTimeRemaining, 60000);
    }
});

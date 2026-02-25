/**
 * MetaSync Debug Mode Widget JavaScript
 *
 * Handles interactions for the debug mode dashboard widget
 *
 * @package MetaSync
 * @since 1.0.0
 */

/* global metasyncDebug */

(function($) {
    'use strict';

    /**
     * Debug Mode Widget Handler
     */
    const DebugModeWidget = {
        /**
         * Initialize the widget
         */
        init: function() {
            this.bindEvents();
            this.startPolling();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Extend debug mode button
            $(document).on('click', '#metasync-extend-debug', function(e) {
                e.preventDefault();
                DebugModeWidget.extendDebugMode();
            });

            // Disable debug mode button
            $(document).on('click', '#metasync-disable-debug', function(e) {
                e.preventDefault();
                DebugModeWidget.disableDebugMode();
            });
        },

        /**
         * Extend debug mode for another 24 hours
         */
        extendDebugMode: function() {
            const button = $('#metasync-extend-debug');
            const originalText = button.text();

            button.prop('disabled', true).text('Extending...');

            $.ajax({
                url: metasyncDebug.restUrl + 'extend',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', metasyncDebug.restNonce);
                },
                success: function(response) {
                    if (response.success) {
                        DebugModeWidget.showNotice('Debug mode extended for another 24 hours.', 'success');
                        DebugModeWidget.updateWidget(response.status);
                    } else {
                        DebugModeWidget.showNotice('Failed to extend debug mode.', 'error');
                    }
                },
                error: function() {
                    DebugModeWidget.showNotice('Failed to extend debug mode.', 'error');
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Disable debug mode
         */
        disableDebugMode: function() {
            if (!confirm('Are you sure you want to disable debug mode?')) {
                return;
            }

            const button = $('#metasync-disable-debug');
            const originalText = button.text();

            button.prop('disabled', true).text('Disabling...');

            $.ajax({
                url: metasyncDebug.restUrl + 'disable',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', metasyncDebug.restNonce);
                },
                success: function(response) {
                    if (response.success) {
                        DebugModeWidget.showNotice('Debug mode disabled successfully.', 'success');
                        // Remove widget or reload page
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        DebugModeWidget.showNotice('Failed to disable debug mode.', 'error');
                    }
                },
                error: function() {
                    DebugModeWidget.showNotice('Failed to disable debug mode.', 'error');
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Update widget content with new status
         */
        updateWidget: function(status) {
            if (!status) {
                return;
            }

            // Update time remaining
            if (status.time_remaining_formatted) {
                $('.time-remaining').text(status.time_remaining_formatted);
            }

            // Update file size
            if (status.log_file_size_formatted && status.max_log_size_formatted) {
                $('.file-size').text(status.log_file_size_formatted + ' / ' + status.max_log_size_formatted);
            }

            // Update progress bar
            if (status.percentage_used !== undefined) {
                $('.progress-fill').css('width', status.percentage_used + '%');
            }
        },

        /**
         * Start polling for status updates
         */
        startPolling: function() {
            // Poll every 60 seconds
            setInterval(function() {
                DebugModeWidget.fetchStatus();
            }, 60000);
        },

        /**
         * Fetch current debug mode status
         */
        fetchStatus: function() {
            $.ajax({
                url: metasyncDebug.restUrl + 'status',
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', metasyncDebug.restNonce);
                },
                success: function(response) {
                    if (response && response.enabled) {
                        DebugModeWidget.updateWidget(response);
                    } else {
                        // Debug mode was disabled, reload page
                        location.reload();
                    }
                }
            });
        },

        /**
         * Show notice message
         */
        showNotice: function(message, type) {
            const noticeClass = 'notice notice-' + type + ' is-dismissible';
            const notice = $('<div>', {
                class: noticeClass,
                html: '<p><strong>MetaSync Debug Mode:</strong> ' + message + '</p>'
            });

            // Insert after page title
            if ($('.wrap h1').length) {
                $('.wrap h1').first().after(notice);
            } else {
                $('#wpbody-content').prepend(notice);
            }

            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        // Only initialize if widget exists
        if ($('#metasync_debug_mode_widget').length) {
            DebugModeWidget.init();
        }
    });

})(jQuery);

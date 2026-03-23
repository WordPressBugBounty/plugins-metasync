/* global metasyncExecSettingsData, jQuery, ajaxurl */
/**
 * MetaSync Execution Settings
 *
 * Extracted for Phase 5, #887.
 * Real-time validation and AJAX save for execution / performance settings.
 *
 * Localized object: metasyncExecSettingsData
 *   - serverMaxExecTime (int|string 'Infinity')
 *   - serverMaxMemory   (int|string 'Infinity')
 *   - canChangeMemory   (bool)
 *
 * @since Phase 5
 */
jQuery(document).ready(function ($) {
    var $form = $('#metasync-execution-settings-form');
    var $saveBtn = $('#metasync-execution-settings-save-btn');
    var $message = $('#metasync-execution-settings-message');

    // Server limit values from localized data
    var serverMaxExecTime = metasyncExecSettingsData.serverMaxExecTime;
    var serverMaxMemory = metasyncExecSettingsData.serverMaxMemory;
    var canChangeMemory = metasyncExecSettingsData.canChangeMemory;

    // Normalize string 'Infinity' to JS Infinity
    if (serverMaxExecTime === 'Infinity') {
        serverMaxExecTime = Infinity;
    }
    if (serverMaxMemory === 'Infinity') {
        serverMaxMemory = Infinity;
    }

    // Real-time validation for server limits
    function checkServerLimits() {
        var maxExecTime = parseInt($('#max_execution_time').val()) || 0;
        var maxMemory = parseInt($('#max_memory_limit').val()) || 0;

        // Check execution time limit
        if (serverMaxExecTime !== Infinity && maxExecTime > serverMaxExecTime) {
            $('#max_execution_time_warning').show();
        } else {
            $('#max_execution_time_warning').hide();
        }

        // Check memory limit (only if server allows changing it)
        if (canChangeMemory && serverMaxMemory !== Infinity && maxMemory > serverMaxMemory) {
            $('#max_memory_limit_warning').show();
        } else {
            $('#max_memory_limit_warning').hide();
        }
    }

    // Real-time validation on input change
    $('#max_execution_time, #max_memory_limit').on('input change', function () {
        checkServerLimits();
        // Remove error styling when user starts typing
        $(this).css('border-color', 'var(--dashboard-border)');
    });

    // Add visual feedback for invalid inputs
    function highlightInvalidField($field, isValid) {
        if (isValid) {
            $field.css({
                'border-color': 'var(--dashboard-border)',
                'box-shadow': 'none'
            });
        } else {
            $field.css({
                'border-color': '#ef4444',
                'box-shadow': '0 0 0 3px rgba(239, 68, 68, 0.1)'
            });
        }
    }

    // Validate individual fields on blur
    $('#max_execution_time').on('blur', function () {
        var value = parseInt($(this).val()) || 0;
        var isValid = value >= 1 && value <= 300 && (serverMaxExecTime === Infinity || value <= serverMaxExecTime);
        highlightInvalidField($(this), isValid);
    });

    $('#max_memory_limit').on('blur', function () {
        if (!canChangeMemory) return;
        var value = parseInt($(this).val()) || 0;
        var isValid = value >= 64 && value <= 512 && (serverMaxMemory === Infinity || value <= serverMaxMemory);
        highlightInvalidField($(this), isValid);
    });

    // Initial check on page load
    checkServerLimits();

    // Save button click (not form submit — form is nested inside #metaSyncGeneralSetting which would double-fire)
    $saveBtn.on('click', function (e) {
        e.preventDefault();

        var formData = {
            action: 'metasync_save_execution_settings',
            execution_settings_nonce: $('#execution_settings_nonce').val(),
            max_execution_time: $('#max_execution_time').val(),
            max_memory_limit: $('#max_memory_limit').val(),
            log_batch_size: $('#log_batch_size').val(),
            action_scheduler_batches: $('#action_scheduler_batches').val(),
            otto_rate_limit: $('#otto_rate_limit').val(),
            queue_cleanup_days: $('#queue_cleanup_days').val()
        };

        // Clear previous error highlights
        $('input[type="number"]').css({
            'border-color': 'var(--dashboard-border)',
            'box-shadow': 'none'
        });

        // Validate ranges
        var hasError = false;
        var errorField = null;

        if (formData.max_execution_time < 1 || formData.max_execution_time > 300) {
            showMessage('Max Execution Time must be between 1 and 300 seconds.', 'error');
            highlightInvalidField($('#max_execution_time'), false);
            errorField = $('#max_execution_time');
            hasError = true;
        } else if (serverMaxExecTime !== Infinity && formData.max_execution_time > serverMaxExecTime) {
            showMessage('Max Execution Time exceeds server limit of ' + serverMaxExecTime + ' seconds. Please reduce the value.', 'error');
            highlightInvalidField($('#max_execution_time'), false);
            errorField = $('#max_execution_time');
            hasError = true;
        }

        // Only validate memory limit if server allows changing it
        if (canChangeMemory) {
            if (formData.max_memory_limit < 64 || formData.max_memory_limit > 512) {
                showMessage('Max Memory Limit must be between 64 and 512 MB.', 'error');
                highlightInvalidField($('#max_memory_limit'), false);
                if (!hasError) {
                    errorField = $('#max_memory_limit');
                    hasError = true;
                }
            } else if (serverMaxMemory !== Infinity && formData.max_memory_limit > serverMaxMemory) {
                showMessage('Max Memory Limit exceeds server limit of ' + serverMaxMemory + ' MB. Please reduce the value.', 'error');
                highlightInvalidField($('#max_memory_limit'), false);
                if (!hasError) {
                    errorField = $('#max_memory_limit');
                    hasError = true;
                }
            }
        }

        if (formData.log_batch_size < 100 || formData.log_batch_size > 5000) {
            showMessage('Log Batch Size must be between 100 and 5000 lines.', 'error');
            highlightInvalidField($('#log_batch_size'), false);
            if (!hasError) {
                errorField = $('#log_batch_size');
                hasError = true;
            }
        }
        if (formData.action_scheduler_batches < 1 || formData.action_scheduler_batches > 10) {
            showMessage('Action Scheduler Batches must be between 1 and 10.', 'error');
            highlightInvalidField($('#action_scheduler_batches'), false);
            if (!hasError) {
                errorField = $('#action_scheduler_batches');
                hasError = true;
            }
        }
        if (formData.otto_rate_limit < 1 || formData.otto_rate_limit > 60) {
            showMessage('OTTO Rate Limit must be between 1 and 60 calls per minute.', 'error');
            highlightInvalidField($('#otto_rate_limit'), false);
            if (!hasError) {
                errorField = $('#otto_rate_limit');
                hasError = true;
            }
        }
        if (formData.queue_cleanup_days < 7 || formData.queue_cleanup_days > 90) {
            showMessage('Queue Cleanup Days must be between 7 and 90 days.', 'error');
            highlightInvalidField($('#queue_cleanup_days'), false);
            if (!hasError) {
                errorField = $('#queue_cleanup_days');
                hasError = true;
            }
        }

        if (hasError) {
            if (errorField) {
                errorField.focus();
                // Scroll to error field
                $('html, body').animate({
                    scrollTop: errorField.offset().top - 100
                }, 300);
            }
            return;
        }

        // Show loading state
        $saveBtn.prop('disabled', true);
        $saveBtn.find('.save-text').text('Saving...');
        $saveBtn.find('.save-spinner').show();
        $message.hide();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            success: function (response) {
                if (response.success) {
                    showMessage(response.data.message || 'Settings saved successfully!', 'success');
                    // Re-enable button
                    $saveBtn.prop('disabled', false);
                    $saveBtn.find('.save-text').text('Save Settings');
                    $saveBtn.find('.save-spinner').hide();
                    // Re-check server limits after save
                    setTimeout(function () {
                        checkServerLimits();
                    }, 100);
                    // Scroll to top to show success message
                    $('html, body').animate({
                        scrollTop: $form.offset().top - 100
                    }, 300);
                } else {
                    showMessage(response.data.message || 'Error saving settings.', 'error');
                    $saveBtn.prop('disabled', false);
                    $saveBtn.find('.save-text').text('Save Settings');
                    $saveBtn.find('.save-spinner').hide();
                    // Scroll to show error message
                    $('html, body').animate({
                        scrollTop: $message.offset().top - 100
                    }, 300);
                }
            },
            error: function () {
                showMessage('An error occurred while saving settings. Please try again.', 'error');
                $saveBtn.prop('disabled', false);
                $saveBtn.find('.save-text').text('Save Settings');
                $saveBtn.find('.save-spinner').hide();
                // Scroll to show error message
                $('html, body').animate({
                    scrollTop: $message.offset().top - 100
                }, 300);
            }
        });
    });

    function showMessage(text, type) {
        $message.removeClass('notice-success notice-error')
            .addClass('notice-' + type)
            .css({
                'background': type === 'success' ? 'rgba(34, 197, 94, 0.1)' : 'rgba(239, 68, 68, 0.1)',
                'border': '1px solid ' + (type === 'success' ? 'rgba(34, 197, 94, 0.3)' : 'rgba(239, 68, 68, 0.3)'),
                'color': type === 'success' ? '#22c55e' : '#ef4444',
                'padding': '12px 16px',
                'border-radius': '6px',
                'font-size': '14px',
                'line-height': '1.5',
                'display': 'block'
            })
            .html('<strong style="margin-right: 8px;">' + (type === 'success' ? '\u2713' : '\u2717') + '</strong>' + text)
            .show();

        // Auto-hide success messages after 5 seconds
        if (type === 'success') {
            setTimeout(function () {
                $message.fadeOut(300);
            }, 5000);
        }
    }
});

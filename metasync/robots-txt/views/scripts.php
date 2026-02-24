<?php
/**
 * Robots.txt Admin Page Scripts
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.5.6
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
?>
<script>
jQuery(document).ready(function($) {
    let allowSave = false;

    // Check for restore success message after page reload
    const restoreMessage = sessionStorage.getItem('metasync_robots_restore_message');
    if (restoreMessage) {
        // Remove the message from sessionStorage
        sessionStorage.removeItem('metasync_robots_restore_message');
        
        // Display the success message
        const $notice = $('<div class="notice notice-success is-dismissible" style="margin: 20px 0;"><p>' + restoreMessage + '</p></div>');
        $('.metasync-robots-txt-page').prepend($notice);
        
        // Scroll to top to show the message
        $('html, body').animate({ scrollTop: 0 }, 300);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
    }

    // Modal functions
    function showModal(type, title, message, showConfirm) {
        const $modal = $('#robots-validation-modal');
        const $header = $modal.find('.metasync-modal-header');
        const $confirmBtn = $('#modal-confirm-btn');

        // Set header style
        $header.removeClass('error warning success').addClass(type);

        // Set icon
        let icon = '';
        if (type === 'error') {
            icon = '<div class="metasync-modal-icon error">⛔</div>';
        } else if (type === 'warning') {
            icon = '<div class="metasync-modal-icon warning">⚠️</div>';
        } else {
            icon = '<div class="metasync-modal-icon success">✅</div>';
        }

        // Set content
        $('#modal-title').html(title);
        $('#modal-body').html(icon + '<div class="metasync-modal-message">' + message + '</div>');

        // Show/hide confirm button
        if (showConfirm) {
            $confirmBtn.show();
        } else {
            $confirmBtn.hide();
        }

        // Show modal
        $modal.fadeIn(200);
    }

    function closeModal() {
        const $modal = $('#robots-validation-modal');
        const $modalContent = $modal.find('.metasync-modal-content');
        const $confirmBtn = $('#modal-confirm-btn');
        const $cancelBtn = $('.metasync-modal-cancel');
        
        // Remove preview class when closing
        $modalContent.removeClass('preview');
        
        // Reset confirm button to original state
        $confirmBtn.removeClass('button-restore')
            .addClass('button-primary')
            .text('<?php esc_html_e('Save Anyway', 'metasync'); ?>')
            .removeData('backup-id')
            .removeData('nonce');
        
        // Reset cancel button
        $cancelBtn.text('<?php esc_html_e('Cancel', 'metasync'); ?>');
        
        $modal.fadeOut(200);
    }

    // Close modal on overlay or close button click
    $('.metasync-modal-overlay, .metasync-modal-close, .metasync-modal-cancel').on('click', function() {
        closeModal();
    });

    // Prevent closing when clicking modal content
    $('.metasync-modal-content').on('click', function(e) {
        e.stopPropagation();
    });

    // Form submission with validation
    $('#robots-txt-form').on('submit', function(e) {
        if (allowSave) {
            allowSave = false;
            return true; // Allow form submission
        }

        e.preventDefault();

        const content = $('#robots-txt-editor').val();

        // Validate via AJAX
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'metasync_validate_robots',
                content: content
            },
            success: function(response) {
                if (response.success && response.data) {
                    // Check for errors
                    if (response.data.errors && response.data.errors.length > 0) {
                        let errorHtml = '<div><strong><?php esc_html_e('The following errors must be fixed before saving:', 'metasync'); ?></strong><ul style="margin: 16px 0 0 20px; text-align: left;">';
                        response.data.errors.forEach(function(error) {
                            errorHtml += '<li>' + error + '</li>';
                        });
                        errorHtml += '</ul></div>';

                        showModal('error', '<?php esc_html_e('Syntax Errors Found', 'metasync'); ?>', errorHtml, false);
                        return;
                    }

                    // Check for warnings
                    if (response.data.warnings && response.data.warnings.length > 0) {
                        let warningHtml = '<div><strong><?php esc_html_e('The following warnings were detected:', 'metasync'); ?></strong><ul style="margin: 16px 0 0 20px; text-align: left;">';
                        response.data.warnings.forEach(function(warning) {
                            warningHtml += '<li>' + warning + '</li>';
                        });
                        warningHtml += '</ul><p style="margin-top: 16px;"><strong><?php esc_html_e('Do you want to save anyway?', 'metasync'); ?></strong></p></div>';

                        showModal('warning', '<?php esc_html_e('Validation Warnings', 'metasync'); ?>', warningHtml, true);
                        return;
                    }

                    // No errors or warnings, submit form
                    allowSave = true;
                    $('#robots-txt-form').submit();
                }
            }
        });
    });

    // Confirm button handler (for warnings and restore from preview)
    $('#modal-confirm-btn').on('click', function() {
        const $btn = $(this);
        
        // Check if this is a restore action from preview
        if ($btn.hasClass('button-restore')) {
            const backupId = $btn.data('backup-id');
            const nonce = $btn.data('nonce');
            
            if (!backupId) {
                alert('<?php esc_html_e('Invalid backup ID', 'metasync'); ?>');
                return;
            }
            
            if (!confirm('<?php esc_html_e('Are you sure you want to restore this backup? Current content will be backed up automatically.', 'metasync'); ?>')) {
                return;
            }
            
            // Show loading state
            const originalText = $btn.text();
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin"></span> <?php esc_html_e('Restoring...', 'metasync'); ?>');
            
            // Close the modal
            closeModal();
            
            // Perform restore via AJAX
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'metasync_restore_robots_backup',
                    backup_id: backupId,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Store success message in sessionStorage
                        if (response.data && response.data.message) {
                            sessionStorage.setItem('metasync_robots_restore_message', response.data.message);
                        }
                        
                        // Reload the page
                        window.location.reload();
                    } else {
                        // Show error message
                        const errorMessage = response.data && response.data.message ? response.data.message : '<?php esc_html_e('Failed to restore backup.', 'metasync'); ?>';
                        alert(errorMessage);
                        
                        // Restore button
                        $btn.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    alert('<?php esc_html_e('An error occurred while restoring the backup.', 'metasync'); ?>');
                    
                    // Restore button
                    $btn.prop('disabled', false).text(originalText);
                }
            });
            
            return;
        }
        
        // Original behavior for validation warnings
        closeModal();
        allowSave = true;
        $('#robots-txt-form').submit();
    });

    // Reset to default
    $('#reset-to-default').on('click', function() {
        if (confirm('<?php esc_html_e('Are you sure you want to reset to default content? This will replace your current content.', 'metasync'); ?>')) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'metasync_get_default_robots'
                },
                success: function(response) {
                    if (response.success) {
                        $('#robots-txt-editor').val(response.data.content);
                    }
                }
            });
        }
    });

    // Validate content button
    $('#validate-content').on('click', function() {
        const content = $('#robots-txt-editor').val();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'metasync_validate_robots',
                content: content
            },
            success: function(response) {
                const $results = $('#validation-results');
                $results.show();

                if (response.success && response.data && response.data.valid) {
                    $results.removeClass('error warning').addClass('success');
                    $results.html('<strong><?php esc_html_e('Validation passed!', 'metasync'); ?></strong> <?php esc_html_e('Your robots.txt syntax is correct.', 'metasync'); ?>');

                    if (response.data.warnings && response.data.warnings.length > 0) {
                        $results.removeClass('success').addClass('warning');
                        let html = '<strong><?php esc_html_e('Validation passed with warnings:', 'metasync'); ?></strong><ul style="margin: 8px 0 0 20px;">';

                        response.data.warnings.forEach(function(warning) {
                            html += '<li>' + warning + '</li>';
                        });

                        html += '</ul>';
                        $results.html(html);
                    }
                } else {
                    $results.removeClass('success warning').addClass('error');
                    let html = '<strong><?php esc_html_e('Validation failed:', 'metasync'); ?></strong><ul style="margin: 8px 0 0 20px;">';

                    if (response.data.errors) {
                        response.data.errors.forEach(function(error) {
                            html += '<li>' + error + '</li>';
                        });
                    }

                    html += '</ul>';
                    $results.html(html);
                }
            }
        });
    });

    // Auto-resize textarea
    $('#robots-txt-editor').on('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });

    // Preview backup content
    $('.metasync-preview-backup').on('click', function(e) {
        e.preventDefault();
        const backupId = $(this).data('backup-id');
        
        // Show loading state
        const $button = $(this);
        const originalText = $button.html();
        $button.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin" style="margin-top: 3px;"></span> <?php esc_html_e('Loading...', 'metasync'); ?>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'metasync_preview_robots_backup',
                backup_id: backupId
            },
            success: function(response) {
                if (response.success && response.data) {
                    // Format the date
                    let dateInfo = '';
                    if (response.data.created_at) {
                        dateInfo = '<p style="margin-bottom: 16px;"><strong><?php esc_html_e('Created:', 'metasync'); ?></strong> ' + response.data.created_at;
                        if (response.data.created_by_name) {
                            dateInfo += ' <?php esc_html_e('by', 'metasync'); ?> ' + response.data.created_by_name;
                        }
                        dateInfo += '</p>';
                    }
                    
                    // Create preview content with pre/code block for display
                    const previewContent = dateInfo + 
                        '<pre class="metasync-preview-code"><code>' + 
                        $('<div>').text(response.data.content).html() + 
                        '</code></pre>';
                    
                    // Show in modal
                    const $modal = $('#robots-validation-modal');
                    const $modalContent = $modal.find('.metasync-modal-content');
                    const $header = $modal.find('.metasync-modal-header');
                    const $confirmBtn = $('#modal-confirm-btn');
                    const $cancelBtn = $('.metasync-modal-cancel');
                    
                    // Add preview class for larger width
                    $modalContent.addClass('preview');
                    
                    // Set header style
                    $header.removeClass('error warning success').addClass('preview');
                    
                    // Set content
                    $('#modal-title').html('<?php esc_html_e('Backup Preview', 'metasync'); ?>');
                    $('#modal-body').html(previewContent);
                    
                    // Configure restore button (on the left)
                    $confirmBtn.html('<span class="dashicons dashicons-backup" style="margin-top: 3px;"></span> <?php esc_html_e('Restore', 'metasync'); ?>')
                        .removeClass('button-primary')
                        .addClass('button-restore')
                        .data('backup-id', backupId)
                        .data('nonce', '<?php echo wp_create_nonce('metasync_restore_robots_backup'); ?>')
                        .show();
                    
                    // Hide close button for preview
                    $cancelBtn.hide();
                    
                    // Show modal
                    $modal.fadeIn(200);
                } else {
                    alert('<?php esc_html_e('Failed to load backup content.', 'metasync'); ?>');
                }
                
                // Restore button state
                $button.prop('disabled', false).html(originalText);
            },
            error: function() {
                alert('<?php esc_html_e('An error occurred while loading the backup.', 'metasync'); ?>');
                // Restore button state
                $button.prop('disabled', false).html(originalText);
            }
        });
    });

    // Restore backup with AJAX
    $(document).on('click', '.metasync-restore-backup', function(e) {
        e.preventDefault();
        
        if (!confirm('<?php esc_html_e('Are you sure you want to restore this backup? Current content will be backed up automatically.', 'metasync'); ?>')) {
            return;
        }
        
        const $button = $(this);
        const backupId = $button.data('backup-id');
        const nonce = $button.data('nonce');
        
        // Show loading state
        const originalHtml = $button.html();
        $button.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin" style="margin-top: 3px;"></span> <?php esc_html_e('Restoring...', 'metasync'); ?>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'metasync_restore_robots_backup',
                backup_id: backupId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    // Store success message in sessionStorage
                    if (response.data && response.data.message) {
                        sessionStorage.setItem('metasync_robots_restore_message', response.data.message);
                    }
                    
                    // Reload the page to show updated content and backup list
                    window.location.reload();
                } else {
                    // Show error message
                    const errorMessage = response.data && response.data.message ? response.data.message : '<?php esc_html_e('Failed to restore backup.', 'metasync'); ?>';
                    alert(errorMessage);
                    
                    // Restore button state
                    $button.prop('disabled', false).html(originalHtml);
                }
            },
            error: function() {
                alert('<?php esc_html_e('An error occurred while restoring the backup.', 'metasync'); ?>');
                
                // Restore button state
                $button.prop('disabled', false).html(originalHtml);
            }
        });
    });

    // Delete backup with AJAX
    $(document).on('click', '.metasync-delete-backup', function(e) {
        e.preventDefault();
        
        if (!confirm('<?php esc_html_e('Are you sure you want to delete this backup?', 'metasync'); ?>')) {
            return;
        }
        
        const $button = $(this);
        const backupId = $button.data('backup-id');
        const nonce = $button.data('nonce');
        const $backupItem = $button.closest('.metasync-backup-item');
        
        // Show loading state
        const originalHtml = $button.html();
        $button.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin"></span>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'metasync_delete_robots_backup',
                backup_id: backupId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    // Fade out and remove the backup item
                    $backupItem.fadeOut(300, function() {
                        $(this).remove();
                        
                        // Check if there are any backups left
                        if ($('.metasync-backup-item').length === 0) {
                            $('.metasync-backups-list').html('<p class="description" style="text-align: center; padding: 20px;"><?php esc_html_e('No backups available yet. Backups are created automatically when you save changes.', 'metasync'); ?></p>');
                        }
                    });
                    
                    // Show success message (you can add a notification system here)
                    if (response.data && response.data.message) {
                        // Create a temporary success notice
                        const $notice = $('<div class="notice notice-success is-dismissible" style="margin: 20px 0;"><p>' + response.data.message + '</p></div>');
                        $('.metasync-robots-txt-page').prepend($notice);
                        
                        // Auto-dismiss after 3 seconds
                        setTimeout(function() {
                            $notice.fadeOut(300, function() {
                                $(this).remove();
                            });
                        }, 3000);
                    }
                } else {
                    // Show error message
                    const errorMessage = response.data && response.data.message ? response.data.message : '<?php esc_html_e('Failed to delete backup.', 'metasync'); ?>';
                    alert(errorMessage);
                    
                    // Restore button state
                    $button.prop('disabled', false).html(originalHtml);
                }
            },
            error: function() {
                alert('<?php esc_html_e('An error occurred while deleting the backup.', 'metasync'); ?>');
                
                // Restore button state
                $button.prop('disabled', false).html(originalHtml);
            }
        });
    });
});
</script>

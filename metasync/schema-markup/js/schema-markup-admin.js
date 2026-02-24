/**
 * Schema Markup Admin JavaScript
 * 
 * @package    MetaSync
 * @subpackage Schema_Markup
 */

jQuery(document).ready(function($) {
    let schemaTypeCounter = 0;
    
    // Initialize counter based on existing schema types
    function initializeSchemaTypeCounter() {
        const existingItems = $('.schema-type-item');
        if (existingItems.length > 0) {
            let maxIndex = -1;
            existingItems.each(function() {
                const index = parseInt($(this).attr('data-index'));
                if (index > maxIndex) {
                    maxIndex = index;
                }
            });
            schemaTypeCounter = maxIndex + 1;
        }
    }
    
    // Initialize counter when document is ready
    initializeSchemaTypeCounter();

    // Function to check if a schema type already exists
    function schemaTypeExists(schemaType) {
        let exists = false;
        $('.schema-type-item').each(function() {
            const existingType = $(this).find('input[name*="[type]"]').val();
            if (existingType === schemaType) {
                exists = true;
                return false; // break loop
            }
        });
        return exists;
    }

    // Function to add a new schema type
    function addSchemaType(schemaType) {
        // Check if this schema type already exists
        if (schemaTypeExists(schemaType)) {
            return;
        }
        
        const index = schemaTypeCounter++;
        const schemaTypeNames = {
            'article': 'Article',
            'FAQPage': 'FAQ',
            'product': 'Product',
            'recipe': 'Recipe'
        };
        const displayName = schemaTypeNames[schemaType] || schemaType;

        const schemaTypeHtml = '<div class="schema-type-item" data-index="' + index + '" style="margin: 15px 0; padding: 15px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;">' +
            '<div class="schema-type-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">' +
            '<h5 style="margin: 0; color: #333;">' +
            '<span class="dashicons dashicons-tag" style="color: #0073aa;"></span> ' + displayName + ' Schema' +
            '</h5>' +
            '<button type="button" class="button button-link remove-schema-type" style="color: #dc3232; text-decoration: none;">' +
            '<span class="dashicons dashicons-trash"></span> Remove' +
            '</button>' +
            '</div>' +
            '<div class="schema-type-content">' +
            '<input type="hidden" name="schema_markup[types][' + index + '][type]" value="' + schemaType + '">' +
            '<div class="schema-fields-container">' +
            '</div>' +
            '</div>' +
            '</div>';

        // Remove no-schema-types message if it exists
        $('.no-schema-types').remove();

        // Add the new schema type
        $('#schema-types-list').append(schemaTypeHtml);

        // Load the fields for this schema type
        loadSchemaFields(schemaType, index);
    }

    // Function to load schema fields via AJAX
    function loadSchemaFields(schemaType, index) {
        const postId = $('#post_ID').val() || 0;
        $.ajax({
            url: metasyncSchemaMarkup.ajaxurl,
            type: 'POST',
            data: {
                action: 'metasync_get_schema_fields',
                schema_type: schemaType,
                index: index,
                post_id: postId,
                nonce: metasyncSchemaMarkup.schema_fields_nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.schema-type-item[data-index="' + index + '"] .schema-fields-container').html(response.data);
                }
            }
        });
    }

    // Toggle schema fields based on enabled checkbox
    $('input[name="schema_markup[enabled]"]').change(function() {
        if ($(this).is(':checked')) {
            $('.schema-types-container').show();
        } else {
            $('.schema-types-container').hide();
        }
    });

    // Handle modal cancel button
    $(document).on('click', '#cancel-schema-type', function() {
        $('#schema-type-modal').remove();
    });

    // Handle modal add button
    $(document).on('click', '#add-selected-schema-type', function() {
        const selectedType = $('#new-schema-type-select').val();
        const selectedOption = $('#new-schema-type-select option:selected');
        
        if (!selectedType) {
            alert('Please select a schema type.');
            return;
        }
        
        // Check if the selected option is disabled
        if (selectedOption.prop('disabled')) {
            alert('This schema type has already been added. Please select a different schema type or remove the existing one first.');
            return;
        }
        
        addSchemaType(selectedType);
        $('#schema-type-modal').remove();
    });

    // Add new schema type
    $(document).on('click', '#add_schema_type_button', function(e) {
        e.preventDefault();
        
        // Show schema type selection modal or dropdown
        showSchemaTypeSelection();
    });

    // Remove schema type
    $(document).on('click', '.remove-schema-type', function(e) {
        e.preventDefault();
        
        if (confirm('Are you sure you want to remove this schema type?')) {
            $(this).closest('.schema-type-item').fadeOut(200, function() {
                $(this).remove();
                updateSchemaTypeIndices();
                updateNoSchemaTypesMessage();
            });
        }
    });

    function showSchemaTypeSelection() {
        const schemaTypes = [
            { value: 'article', label: 'Article' },
            { value: 'FAQPage', label: 'FAQ' },
            { value: 'product', label: 'Product' },
            { value: 'recipe', label: 'Recipe' }
        ];

        let optionsHtml = '<option value="">Select Schema Type</option>';
        schemaTypes.forEach(function(type) {
            const isAlreadyAdded = schemaTypeExists(type.value);
            let label = type.label;
            let disabledAttr = '';
            
            if (isAlreadyAdded) {
                label += ' - already added';
                disabledAttr = ' disabled';
            }
            
            optionsHtml += '<option value="' + type.value + '"' + disabledAttr + '>' + label + '</option>';
        });

        const modalHtml = '<div id="schema-type-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;">' +
            '<div style="background: white; padding: 20px; border-radius: 4px; min-width: 300px;">' +
            '<h3 style="margin-top: 0;">Add Schema Type</h3>' +
            '<p>Select the schema type you want to add:</p>' +
            '<select id="new-schema-type-select" style="width: 100%; margin-bottom: 15px;">' + optionsHtml + '</select>' +
            '<div style="text-align: right;">' +
            '<button type="button" class="button" id="cancel-schema-type">Cancel</button> ' +
            '<button type="button" class="button button-primary" id="add-selected-schema-type">Add Schema Type</button>' +
            '</div>' +
            '</div>' +
            '</div>';

        $('body').append(modalHtml);
    }


    function updateSchemaTypeIndices() {
        $('.schema-type-item').each(function(newIndex) {
            $(this).attr('data-index', newIndex);
            $(this).find('input[name*="[type]"]').attr('name', 'schema_markup[types][' + newIndex + '][type]');
            
            // Update all field names
            $(this).find('[name*="schema_markup[types]"]').each(function() {
                const name = $(this).attr('name');
                const newName = name.replace(/schema_markup\[types\]\[\d+\]/, 'schema_markup[types][' + newIndex + ']');
                $(this).attr('name', newName);
            });
        });
        
        // Update counter to be the next available index
        schemaTypeCounter = $('.schema-type-item').length;
    }

    function updateNoSchemaTypesMessage() {
        if ($('.schema-type-item').length === 0) {
            $('#schema-types-list').html('<div class="no-schema-types" style="text-align: center; padding: 20px; background: #f9f9f9; border: 2px dashed #ddd; border-radius: 4px; color: #666;"><p style="margin: 0;">No schema types added yet. Click "Add Schema Type" to get started.</p></div>');
        }
    }

    // Add ingredient
    $(document).on('click', '.add-ingredient', function() {
        const container = $(this).siblings('.ingredients-list');
        const schemaTypeItem = $(this).closest('.schema-type-item');
        const schemaIndex = schemaTypeItem.attr('data-index');
        
        const newItem = '<div class="ingredient-item">' +
            '<input type="text" name="schema_markup[types][' + schemaIndex + '][fields][ingredients][]" placeholder="Enter ingredient">' +
            '<button type="button" class="remove-item">Remove</button>' +
            '</div>';
        container.append(newItem);
    });

    // Add instruction
    $(document).on('click', '.add-instruction', function() {
        const container = $(this).siblings('.instructions-list');
        const schemaTypeItem = $(this).closest('.schema-type-item');
        const schemaIndex = schemaTypeItem.attr('data-index');
        
        const newItem = '<div class="instruction-item">' +
            '<textarea name="schema_markup[types][' + schemaIndex + '][fields][instructions][]" placeholder="Enter instruction step"></textarea>' +
            '<button type="button" class="remove-item">Remove</button>' +
            '</div>';
        container.append(newItem);
    });

    // Remove item
    $(document).on('click', '.remove-item', function() {
        $(this).parent().remove();
    });

    // Media uploader for organization logo
    let mediaUploader;
    
    $(document).on('click', '.upload-logo-button', function(e) {
        e.preventDefault();
        const targetIndex = $(this).data('target');
        
        // If the media frame already exists, reopen it
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }
        
        // Create the media frame
        mediaUploader = wp.media({
            title: 'Choose Organization Logo',
            button: {
                text: 'Use this logo'
            },
            multiple: false,
            library: {
                type: 'image'
            }
        });
        
        // When an image is selected, run a callback
        mediaUploader.on('select', function() {
            const attachment = mediaUploader.state().get('selection').first().toJSON();
            $('#article_organization_logo_' + targetIndex).val(attachment.url);
            $('#logo_preview_' + targetIndex + ' img').attr('src', attachment.url);
            $('#logo_preview_' + targetIndex).show();
            $('.remove-logo-button[data-target="' + targetIndex + '"]').show();
        });
        
        // Open the uploader dialog
        mediaUploader.open();
    });
    
    // Remove logo
    $(document).on('click', '.remove-logo-button', function(e) {
        e.preventDefault();
        const targetIndex = $(this).data('target');
        $('#article_organization_logo_' + targetIndex).val('');
        $('#logo_preview_' + targetIndex).hide();
        $(this).hide();
    });

    // Add FAQ item
    $(document).on('click', '.add-faq-item', function(e) {
        e.preventDefault();
        const container = $(this).siblings('.faq-items-list');
        const count = container.find('.faq-item').length;
        const schemaTypeItem = $(this).closest('.schema-type-item');
        const schemaIndex = schemaTypeItem.attr('data-index');
        
        const newItem = '<div class="faq-item" style="margin: 15px 0; padding: 15px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;">' +
            '<div class="schema-field">' +
            '<label>Question ' + (count + 1) + ':</label>' +
            '<input type="text" name="schema_markup[types][' + schemaIndex + '][fields][faq_items][' + count + '][question]" value="" placeholder="Enter your question" style="width: 100%; margin-bottom: 10px;">' +
            '</div>' +
            '<div class="schema-field">' +
            '<label>Answer ' + (count + 1) + ':</label>' +
            '<textarea name="schema_markup[types][' + schemaIndex + '][fields][faq_items][' + count + '][answer]" placeholder="Enter your answer" style="width: 100%; height: 80px; margin-bottom: 10px;"></textarea>' +
            '</div>' +
            '<button type="button" class="button remove-faq-item" style="background: #dc3232; color: white; border-color: #dc3232;">Remove Question</button>' +
            '</div>';
        container.append(newItem);
    });

    // Remove FAQ item
    $(document).on('click', '.remove-faq-item', function(e) {
        e.preventDefault();
        const container = $(this).closest('.faq-items-list');
        $(this).closest('.faq-item').fadeOut(200, function() {
            $(this).remove();
            // Update question numbers
            container.find('.faq-item').each(function(index) {
                $(this).find('label').first().text('Question ' + (index + 1) + ':');
                $(this).find('label').last().text('Answer ' + (index + 1) + ':');
            });
        });
    });

    // Function to display validation errors
    function displayValidationErrors(errors) {
        let errorHtml = '<div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; border-radius: 4px;">';
        errorHtml += '<h3 style="margin: 0 0 15px 0; color: #856404; font-size: 16px;">';
        errorHtml += '<span class="dashicons dashicons-warning" style="color: #ffc107; vertical-align: middle;"></span> ';
        errorHtml += 'Validation Issues Detected';
        errorHtml += '</h3>';
        errorHtml += '<p style="margin: 0 0 10px 0; color: #856404;">Schema preview is not possible because the following issues are present:</p>';
        errorHtml += '<ul style="margin: 0; padding-left: 20px; color: #856404;">';
        
        // Group errors by schema type
        const errorsByType = {};
        errors.forEach(function(error) {
            if (!errorsByType[error.schema_type]) {
                errorsByType[error.schema_type] = [];
            }
            errorsByType[error.schema_type].push(error);
        });
        
        // Display errors grouped by schema type
        for (const schemaType in errorsByType) {
            errorHtml += '<li style="margin-bottom: 8px;"><strong>' + schemaType + ' Schema:</strong><ul style="margin-top: 5px;">';
            errorsByType[schemaType].forEach(function(error) {
                errorHtml += '<li>' + error.message + '</li>';
            });
            errorHtml += '</ul></li>';
        }
        
        errorHtml += '</ul>';
        errorHtml += '</div>';
        
        // Display in preview area
        $('#schema-json-preview').html(errorHtml);
        $('#schema-preview-output').slideDown();
        $('#copy_schema_button').hide(); // Hide copy button for errors
    }

    // Preview Schema
    $(document).on('click', '#preview_schema_button', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const originalText = button.html();
        button.prop('disabled', true).html('<span class="dashicons dashicons-update dashicons-spin"></span> Generating Preview...');
        
        // Collect form data
        const formData = {
            action: 'metasync_preview_schema',
            nonce: metasyncSchemaMarkup.preview_schema_nonce,
            post_id: $('#post_ID').val(),
            schema_enabled: $('input[name="schema_markup[enabled]"]').is(':checked') ? 1 : 0,
            schema_types: []
        };
        
        // Collect all schema types and their fields
        $('.schema-type-item').each(function() {
            const schemaTypeItem = $(this);
            const schemaIndex = schemaTypeItem.attr('data-index');
            const schemaType = schemaTypeItem.find('input[name*="[type]"]').val();
            
            if (schemaType) {
                const schemaTypeData = {
                    type: schemaType,
                    fields: {}
                };
                
                // Collect fields for this schema type
                schemaTypeItem.find('[name*="schema_markup[types][' + schemaIndex + '][fields]"]').each(function() {
                    const name = $(this).attr('name');
                    const value = $(this).val();
                    
                    // Check if it's an array field with empty brackets (e.g., ingredients[])
                    if (name.match(/\[\]$/)) {
                        // Extract field name before []
                        const matches = name.match(/schema_markup\[types\]\[(\d+)\]\[fields\]\[([^\]]+)\]\[\]/);
                        if (matches) {
                            const fieldName = matches[2];
                            if (!schemaTypeData.fields[fieldName]) {
                                schemaTypeData.fields[fieldName] = [];
                            }
                            schemaTypeData.fields[fieldName].push(value);
                        }
                    } else {
                        // Parse the field name to get the structure
                        const matches = name.match(/schema_markup\[types\]\[(\d+)\]\[fields\]\[([^\]]+)\](?:\[([^\]]+)\])?(?:\[([^\]]+)\])?/);
                        if (matches) {
                            const field1 = matches[2];
                            const field2 = matches[3];
                            const field3 = matches[4];
                            
                            if (field3) {
                                // Nested array (e.g., faq_items[0][question])
                                if (!schemaTypeData.fields[field1]) {
                                    schemaTypeData.fields[field1] = [];
                                }
                                if (!schemaTypeData.fields[field1][field2]) {
                                    schemaTypeData.fields[field1][field2] = {};
                                }
                                schemaTypeData.fields[field1][field2][field3] = value;
                            } else if (field2) {
                                // Simple nested (e.g., ingredients[0])
                                if (!schemaTypeData.fields[field1]) {
                                    schemaTypeData.fields[field1] = [];
                                }
                                schemaTypeData.fields[field1].push(value);
                            } else {
                                // Simple field
                                schemaTypeData.fields[field1] = value;
                            }
                        }
                    }
                });
                
                formData.schema_types.push(schemaTypeData);
            }
        });
        
        $.ajax({
            url: metasyncSchemaMarkup.ajaxurl,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    // Show JSON preview
                    $('#schema-json-preview').text(response.data.json);
                    $('#schema-preview-output').slideDown();
                    $('#copy_schema_button').show();
                } else {
                    // Check if validation errors exist
                    if (response.data && response.data.validation_errors) {
                        // Display validation errors
                        displayValidationErrors(response.data.validation_errors);
                    } else {
                        alert('Error: ' + (response.data.message || 'Could not generate preview'));
                    }
                }
            },
            error: function() {
                alert('Error generating preview. Please try again.');
            },
            complete: function() {
                button.prop('disabled', false).html(originalText);
            }
        });
    });

    // Copy Schema to Clipboard
    $(document).on('click', '#copy_schema_button', function(e) {
        e.preventDefault();
        const schemaText = $('#schema-json-preview').text();
        
        // Create temporary textarea
        const temp = $('<textarea>');
        $('body').append(temp);
        temp.val(schemaText).select();
        document.execCommand('copy');
        temp.remove();
        
        // Visual feedback
        const button = $(this);
        const originalHtml = button.html();
        button.html('<span class="dashicons dashicons-yes" style="color: #46b450;"></span> Copied!');
        setTimeout(function() {
            button.html(originalHtml);
        }, 2000);
    });

    // Close Preview
    $(document).on('click', '#close_preview', function(e) {
        e.preventDefault();
        $('#schema-preview-output').slideUp();
        $('#copy_schema_button').hide();
    });

    // Restrict recipe time inputs to numbers and decimals only
    $(document).on('keydown', '.recipe-time-input', function(e) {
        const key = e.key;
        const value = $(this).val();
        
        // Allow navigation and control keys
        const allowedKeys = [
            'Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
            'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
            'Home', 'End'
        ];
        
        // Allow Ctrl/Cmd combinations (for copy, paste, select all, etc.)
        if (e.ctrlKey || e.metaKey) {
            return true;
        }
        
        // Allow navigation keys
        if (allowedKeys.indexOf(key) !== -1) {
            return true;
        }
        
        // Allow numbers (0-9)
        if (key >= '0' && key <= '9') {
            return true;
        }
        
        // Allow decimal point (period only), but only one
        if (key === '.') {
            // Prevent if decimal point already exists
            if (value.indexOf('.') !== -1) {
                e.preventDefault();
                return false;
            }
            return true;
        }
        
        // Block all other keys
        e.preventDefault();
        return false;
    });

    // Prevent paste of non-numeric content
    $(document).on('paste', '.recipe-time-input', function(e) {
        e.preventDefault();
        
        let pastedData = (e.originalEvent.clipboardData || window.clipboardData).getData('text');
        
        // Normalize comma to period for decimal separator
        pastedData = pastedData.replace(',', '.');
        
        // Remove any non-numeric characters except decimal point
        pastedData = pastedData.replace(/[^0-9.]/g, '');
        
        // Ensure only one decimal point
        const parts = pastedData.split('.');
        if (parts.length > 2) {
            pastedData = parts[0] + '.' + parts.slice(1).join('');
        }
        
        // Only allow numeric values with optional single decimal point
        const numericRegex = /^[0-9]*\.?[0-9]*$/;
        
        if (!numericRegex.test(pastedData)) {
            return false;
        }
        
        // Insert the cleaned pasted value
        const currentValue = $(this).val();
        const cursorPosition = this.selectionStart;
        const newValue = currentValue.substring(0, cursorPosition) + pastedData + currentValue.substring(this.selectionEnd);
        
        // Final check: ensure no more than one decimal point in final value
        if ((newValue.match(/\./g) || []).length > 1) {
            return false;
        }
        
        $(this).val(newValue);
        this.setSelectionRange(cursorPosition + pastedData.length, cursorPosition + pastedData.length);
        
        return false;
    });

    // Collapsible override fields section toggle
    $(document).on('click', '.schema-override-header', function(e) {
        e.preventDefault();
        const targetId = $(this).data('toggle-target');
        const content = $('#' + targetId);
        const icon = $(this).find('.toggle-icon');
        
        if (content.is(':visible')) {
            content.slideUp(200);
            icon.css('transform', 'rotate(0deg)');
        } else {
            content.slideDown(200);
            icon.css('transform', 'rotate(180deg)');
        }
    });

    // Reset override field button
    $(document).on('click', '.reset-override-field', function(e) {
        e.preventDefault();
        const fieldId = $(this).data('field');
        const field = $('#' + fieldId);
        
        // Determine placeholder based on field type
        let defaultValue = '';
        if (fieldId.indexOf('override_title_') !== -1) {
            defaultValue = '{{post_title}}';
        } else if (fieldId.indexOf('override_description_') !== -1) {
            defaultValue = '{{post_description}}';
        } else if (fieldId.indexOf('override_image_') !== -1) {
            defaultValue = '{{featured_image}}';
        } else {
            defaultValue = field.data('default-value') || '';
        }
        
        // Reset the field value to placeholder
        field.val(defaultValue);
        
        // If it's an image field, hide the preview when resetting to placeholder
        if (fieldId.indexOf('override_image_') !== -1) {
            const index = fieldId.replace('override_image_', '');
            const preview = $('#override_image_preview_' + index);
            // If resetting to placeholder, hide preview (will use default featured image)
            if (defaultValue === '{{featured_image}}') {
                preview.hide();
            }
        }
    });

    // Image uploader for override image field
    let overrideImageUploader;
    $(document).on('click', '.upload-image-button', function(e) {
        e.preventDefault();
        const targetIndex = $(this).data('target');
        const fieldId = $(this).data('field-id');
        
        // If the media frame already exists, reopen it
        if (overrideImageUploader) {
            overrideImageUploader.open();
            overrideImageUploader.targetField = fieldId;
            overrideImageUploader.targetPreview = 'override_image_preview_' + targetIndex;
            return;
        }
        
        // Create the media frame
        overrideImageUploader = wp.media({
            title: 'Choose Schema Image',
            button: {
                text: 'Use this image'
            },
            multiple: false,
            library: {
                type: 'image'
            }
        });
        
        // Store target field info
        overrideImageUploader.targetField = fieldId;
        overrideImageUploader.targetPreview = 'override_image_preview_' + targetIndex;
        
        // When an image is selected, run a callback
        overrideImageUploader.on('select', function() {
            const attachment = overrideImageUploader.state().get('selection').first().toJSON();
            $('#' + overrideImageUploader.targetField).val(attachment.url);
            $('#' + overrideImageUploader.targetPreview + ' img').attr('src', attachment.url);
            $('#' + overrideImageUploader.targetPreview).show();
        });
        
        // Open the uploader dialog
        overrideImageUploader.open();
    });

});



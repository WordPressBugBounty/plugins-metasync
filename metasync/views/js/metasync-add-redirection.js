/**
 * MetaSync Add Redirection Form
 *
 * Extracted for Phase 5, #887.
 * Pure JS — form display, tips toggle, source URL management,
 * unsaved-changes warning, and client-side validation.
 *
 * No localized data object required (pure JS, no PHP values).
 *
 * @since Phase 5
 */
(function () {
    // Show the add redirection form immediately
    function showForm() {
        var element = document.getElementById('add-redirection-form');
        if (element) {
            element.style.display = 'block';
        }
    }

    // Try immediately
    showForm();

    // Also try on DOMContentLoaded as backup
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', showForm);
    }
})();

// Handle tips toggle
document.addEventListener('DOMContentLoaded', function () {
    var toggleTipsBtn = document.getElementById('toggle-redirection-tips');
    var tipsContent = document.getElementById('redirection-tips-content');

    if (toggleTipsBtn && tipsContent) {
        toggleTipsBtn.addEventListener('click', function () {
            if (tipsContent.style.display === 'none') {
                tipsContent.style.display = 'block';
                toggleTipsBtn.innerHTML = '<span class="dashicons dashicons-info" style="margin-top: 3px;"></span> Hide Redirection Tips & Examples';
            } else {
                tipsContent.style.display = 'none';
                toggleTipsBtn.innerHTML = '<span class="dashicons dashicons-info" style="margin-top: 3px;"></span> Show Redirection Tips & Examples';
            }
        });
    }

    // Handle regex pattern field visibility
    var searchTypeSelects = document.querySelectorAll('select[name="search_type[]"]');
    var regexRow = document.getElementById('regex_pattern_row');

    function toggleRegexField() {
        var hasRegex = false;
        searchTypeSelects.forEach(function (select) {
            if (select.value === 'regex') {
                hasRegex = true;
            }
        });

        if (regexRow) {
            regexRow.style.display = hasRegex ? 'table-row' : 'none';
        }
    }

    // Initial check
    toggleRegexField();

    // Listen for changes
    searchTypeSelects.forEach(function (select) {
        select.addEventListener('change', toggleRegexField);
    });

    // Handle adding new source URLs
    var addButton = document.getElementById('addNewSourceUrl');
    if (addButton) {
        addButton.addEventListener('click', function () {
            var sourceUrlsList = document.getElementById('source_urls');
            var newItem = document.createElement('li');
            newItem.innerHTML =
                '<input type="text" class="regular-text" name="source_url[]" value="">' +
                '<select name="search_type[]">' +
                    '<option value="exact">Exact Match</option>' +
                    '<option value="start">Starts With</option>' +
                    '<option value="end">Ends With</option>' +
                    '<option value="wildcard">Wildcard (*)</option>' +
                    '<option value="regex">Regex Pattern</option>' +
                '</select>' +
                '<button type="button" class="source_url_delete">Remove</button>';
            sourceUrlsList.appendChild(newItem);

            // Add event listener to new select
            var newSelect = newItem.querySelector('select[name="search_type[]"]');
            newSelect.addEventListener('change', toggleRegexField);

            // Add event listener to remove button
            var removeButton = newItem.querySelector('.source_url_delete');
            removeButton.addEventListener('click', function () {
                newItem.remove();
                toggleRegexField();
            });
        });
    }

    // Handle remove buttons
    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('source_url_delete')) {
            e.target.closest('li').remove();
            toggleRegexField();
        }
    });

    // Track form changes for unsaved changes warning
    var formModified = false;
    var formInputs = document.querySelectorAll('#add-redirection-form input, #add-redirection-form select, #add-redirection-form textarea');

    formInputs.forEach(function (input) {
        // Skip hidden inputs and the cancel button itself
        if (input.type !== 'hidden' && input.id !== 'cancel-redirection') {
            input.addEventListener('change', function () {
                formModified = true;
            });
            input.addEventListener('input', function () {
                formModified = true;
            });
        }
    });

    // Handle cancel button
    var cancelButton = document.getElementById('cancel-redirection');
    if (cancelButton) {
        cancelButton.addEventListener('click', function (e) {
            e.preventDefault();

            // Check if form has been modified
            if (formModified) {
                var confirmCancel = confirm('You have unsaved changes. Are you sure you want to cancel?');
                if (!confirmCancel) {
                    return;
                }
            }

            // Redirect back to redirections list
            // The cancel URL is set as a data attribute on the button by the server
            var cancelUrl = cancelButton.getAttribute('data-cancel-url');
            if (cancelUrl) {
                window.location.href = cancelUrl;
            } else {
                window.history.back();
            }
        });
    }

    // Warn user about unsaved changes when leaving page
    window.addEventListener('beforeunload', function (e) {
        if (formModified) {
            e.preventDefault();
            e.returnValue = ''; // Modern browsers require this
            return ''; // Some older browsers show this message
        }
    });


    // Validation helper functions
    function showError(element, message) {
        // Remove any existing error
        hideError(element);

        // Create error message
        var errorDiv = document.createElement('div');
        errorDiv.className = 'validation-error';
        errorDiv.style.cssText = 'color: var(--dashboard-error); font-size: 13px; margin-top: 5px; font-weight: 500;';
        errorDiv.textContent = message;

        // Add error styling to input
        element.style.borderColor = 'var(--dashboard-error)';
        element.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';

        // Insert error message after the element
        element.parentNode.insertBefore(errorDiv, element.nextSibling);
    }

    function hideError(element) {
        // Remove error styling
        element.style.borderColor = '';
        element.style.boxShadow = '';

        // Remove error message
        var nextEl = element.nextSibling;
        if (nextEl && nextEl.classList && nextEl.classList.contains('validation-error')) {
            nextEl.remove();
        }
    }

    function validateURL(url) {
        // Allow relative paths starting with /
        if (url.startsWith('/')) {
            return true;
        }

        // Allow full URLs
        try {
            new URL(url);
            return true;
        } catch (e) {
            return false;
        }
    }

    function isValidRegex(pattern) {
        try {
            new RegExp(pattern);
            return true;
        } catch (e) {
            return false;
        }
    }

    // Real-time validation for inputs
    function setupRealtimeValidation() {
        // Validate source URLs on blur
        document.addEventListener('blur', function (e) {
            if (e.target.matches('input[name="source_url[]"]')) {
                var value = e.target.value.trim();
                if (value && !validateURL(value)) {
                    showError(e.target, 'Please enter a valid URL (e.g., /path or https://example.com)');
                } else {
                    hideError(e.target);
                }
            }
        }, true);

        // Validate destination URL on blur
        var destinationUrl = document.getElementById('destination_url');
        if (destinationUrl) {
            destinationUrl.addEventListener('blur', function () {
                var value = this.value.trim();
                var redirectType = document.querySelector('input[name="redirect_type"]:checked');

                if (redirectType && redirectType.value !== '410' && redirectType.value !== '451') {
                    if (value && !validateURL(value)) {
                        showError(this, 'Please enter a valid URL (e.g., /path or https://example.com)');
                    } else {
                        hideError(this);
                    }
                }
            });
        }

        // Validate regex pattern on blur
        var regexPattern = document.getElementById('regex_pattern');
        if (regexPattern) {
            regexPattern.addEventListener('blur', function () {
                var value = this.value.trim();
                if (value && !isValidRegex(value)) {
                    showError(this, 'Invalid regex pattern. Example: /^\\/old-path\\/.*$/');
                } else {
                    hideError(this);
                }
            });
        }
    }

    setupRealtimeValidation();

    // Form validation on submit
    var form = document.querySelector('#redirection-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            var isValid = true;
            var errors = [];

            // Clear all previous errors
            document.querySelectorAll('.validation-error').forEach(function (el) { el.remove(); });
            document.querySelectorAll('input[type="text"], input[type="url"], select').forEach(function (el) {
                el.style.borderColor = '';
                el.style.boxShadow = '';
            });

            // 1. Validate source URLs
            var sourceInputs = document.querySelectorAll('input[name="source_url[]"]');
            var sourceUrls = [];
            var hasEmptySource = false;
            var hasInvalidSource = false;

            sourceInputs.forEach(function (input) {
                var value = input.value.trim();

                if (!value) {
                    hasEmptySource = true;
                    showError(input, 'Source URL is required');
                    isValid = false;
                } else if (!validateURL(value)) {
                    hasInvalidSource = true;
                    showError(input, 'Please enter a valid URL (e.g., /path or https://example.com)');
                    isValid = false;
                } else {
                    // Check for duplicates
                    if (sourceUrls.includes(value)) {
                        showError(input, 'Duplicate source URL detected');
                        isValid = false;
                    } else {
                        sourceUrls.push(value);
                    }
                }
            });

            if (hasEmptySource) {
                errors.push('All source URL fields must be filled in.');
            }
            if (hasInvalidSource) {
                errors.push('Please enter valid URLs for all source fields.');
            }

            // 2. Validate redirection type
            var redirectType = document.querySelector('input[name="redirect_type"]:checked');
            if (!redirectType) {
                errors.push('Please select a redirection type.');
                isValid = false;
            }

            // 3. Validate destination URL (if required)
            var destinationUrlEl = document.getElementById('destination_url');
            if (redirectType && redirectType.value !== '410' && redirectType.value !== '451') {
                var destValue = destinationUrlEl.value.trim();
                if (!destValue) {
                    showError(destinationUrlEl, 'Destination URL is required for this redirect type');
                    errors.push('Please enter a destination URL.');
                    isValid = false;
                } else if (!validateURL(destValue)) {
                    showError(destinationUrlEl, 'Please enter a valid URL (e.g., /path or https://example.com)');
                    errors.push('Please enter a valid destination URL.');
                    isValid = false;
                }
            }

            // 4. Validate regex pattern (if regex is selected)
            var hasRegexPattern = false;
            document.querySelectorAll('select[name="search_type[]"]').forEach(function (select) {
                if (select.value === 'regex') {
                    hasRegexPattern = true;
                }
            });

            if (hasRegexPattern) {
                var regexPatternEl = document.getElementById('regex_pattern');
                var regexValue = regexPatternEl ? regexPatternEl.value.trim() : '';

                if (!regexValue) {
                    if (regexPatternEl) {
                        showError(regexPatternEl, 'Regex pattern is required when using "Regex Pattern" type');
                    }
                    errors.push('Please enter a regex pattern when using "Regex Pattern" as the pattern type.');
                    isValid = false;
                } else if (!isValidRegex(regexValue)) {
                    if (regexPatternEl) {
                        showError(regexPatternEl, 'Invalid regex pattern. Example: /^\\/old-path\\/.*$/');
                    }
                    errors.push('Invalid regex pattern. Please fix the regex pattern.');
                    isValid = false;
                }
            }

            // 5. Validate status is selected
            var status = document.querySelector('input[name="status"]:checked');
            if (!status) {
                errors.push('Please select a status (Active or Inactive).');
                isValid = false;
            }

            // Show consolidated error message if validation fails
            if (!isValid) {
                e.preventDefault();

                // Create or update error summary at the top of the form
                var errorSummary = document.getElementById('validation-error-summary');
                if (!errorSummary) {
                    errorSummary = document.createElement('div');
                    errorSummary.id = 'validation-error-summary';
                    errorSummary.style.cssText = 'background: rgba(239, 68, 68, 0.1); border: 1px solid var(--dashboard-error); border-radius: 8px; padding: 15px; margin-bottom: 20px; color: var(--dashboard-error);';

                    var formDiv = document.getElementById('add-redirection-form');
                    var firstTable = formDiv.querySelector('.form-table');
                    formDiv.insertBefore(errorSummary, firstTable);
                }

                var uniqueErrors = errors.filter(function (err, idx, arr) { return arr.indexOf(err) === idx; });
                errorSummary.innerHTML = '<strong>Please fix the following errors:</strong><ul style="margin: 10px 0 0 20px; padding: 0;">' +
                    uniqueErrors.map(function (err) { return '<li>' + err + '</li>'; }).join('') +
                    '</ul>';

                // Scroll to error summary
                errorSummary.scrollIntoView({ behavior: 'smooth', block: 'center' });

                return false;
            } else {
                // Remove error summary if it exists
                var existingSummary = document.getElementById('validation-error-summary');
                if (existingSummary) {
                    existingSummary.remove();
                }
            }

            // If validation passes, clear the modified flag to prevent beforeunload warning
            formModified = false;
        });
    }
});

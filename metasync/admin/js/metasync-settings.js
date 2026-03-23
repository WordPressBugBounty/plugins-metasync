/* global jQuery, ajaxurl */
/**
 * MetaSync settings page scripts.
 *
 * Combines: save button handler, clear settings confirm,
 * Bing API key generator, plugin access roles.
 * Extracted from admin/class-metasync-admin.php (Phase 5, #887).
 */
jQuery(document).ready(function($) {

    // --- Hosting Cache save button handler ---
    $('#metasync-hc-save-btn').on('click', function() {
        var $btn = $(this);
        var $msg = $('#metasync-hc-save-msg');

        $btn.prop('disabled', true).text('Saving…');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action:            'metasync_save_hosting_cache_settings',
                hosting_cache_nonce: $('#metasync-hc-nonce').val(),
                wpengine_enabled:  $('#metasync-hc-wpengine').is(':checked') ? '1' : '0',
                kinsta_enabled:    $('#metasync-hc-kinsta').is(':checked')   ? '1' : '0',
            },
            success: function(response) {
                if (response.success) {
                    $msg.text('✅ Saved').css('color', '#22c55e').show();
                } else {
                    $msg.text('❌ ' + (response.data.message || 'Save failed')).css('color', '#ef4444').show();
                }
            },
            error: function() {
                $msg.text('❌ Request failed').css('color', '#ef4444').show();
            },
            complete: function() {
                $btn.prop('disabled', false).text('💾 Save Settings');
                setTimeout(function() { $msg.fadeOut(); }, 4000);
            }
        });
    });

    // --- Clear All Settings confirmation ---
    $('#metasync-clear-settings-form').on('submit', function(e) {
        e.preventDefault();

        // First confirmation
        var firstConfirm = confirm("⚠️ WARNING: This will permanently delete ALL plugin settings!\n\nThis action cannot be undone. Are you sure you want to continue?");
        if (!firstConfirm) {
            return false;
        }

        // Second confirmation with more specific warning
        var secondConfirm = confirm("🚨 FINAL WARNING 🚨\n\nThis will delete:\n• All API keys and authentication tokens\n• White label branding settings\n• Plugin configuration and preferences\n• Instant indexing settings\n• All cached data\n\nYou will need to reconfigure the entire plugin from scratch.\n\nType 'DELETE' in the next prompt to confirm.");
        if (!secondConfirm) {
            return false;
        }

        // Third confirmation requiring typing "DELETE"
        var typeConfirm = prompt("Type 'DELETE' (in capital letters) to confirm you want to permanently clear all settings:");
        if (typeConfirm !== 'DELETE') {
            alert("Settings reset cancelled. Type 'DELETE' exactly to confirm.");
            return false;
        }

        // If all confirmations passed, allow form submission
        this.submit();
        return false;
    });

    // --- Bing API key generator ---
    // Generate random API key
    $('#generate-bing-api-key-inline').on('click', function() {
        const array = new Uint8Array(16);
        crypto.getRandomValues(array);
        const hexString = Array.from(array, byte => byte.toString(16).padStart(2, '0')).join('');
        $('#metasync_bing_api_key_inline').val(hexString);

        // Auto-enable toggle when API key is generated
        $('#enable_binginstantindex').prop('checked', true);

        // Trigger change event for unsaved changes detection
        $('#metasync_bing_api_key_inline').trigger('change');
    });

    // Auto-enable toggle when API key is entered manually
    $('#metasync_bing_api_key_inline').on('input', function() {
        const apiKey = $(this).val().trim();
        if (apiKey.length >= 8) {
            $('#enable_binginstantindex').prop('checked', true);
        }
    });

    // Show/hide API configuration based on enable toggle
    function toggleBingConfig() {
        const isEnabled = $('#enable_binginstantindex').is(':checked');
        const $apiConfig = $('#enable_binginstantindex').closest('tr').nextAll('tr').first();
        if (isEnabled) {
            $apiConfig.show();
        } else {
            $apiConfig.hide();
        }
    }

    // Initial state
    toggleBingConfig();

    // Toggle on change
    $('#enable_binginstantindex').on('change', toggleBingConfig);

    // --- Plugin access roles ---
    var $allRolesCheckbox = $('#plugin-access-all-roles');
    var $individualRoles = $('.plugin-access-individual-role');

    // When "All Roles" is checked, uncheck all individual roles
    $allRolesCheckbox.on('change', function() {
        if ($(this).is(':checked')) {
            $individualRoles.prop('checked', false);
        }
    });

    // When any individual role is checked, uncheck "All Roles"
    $individualRoles.on('change', function() {
        if ($(this).is(':checked')) {
            $allRolesCheckbox.prop('checked', false);
        }
    });
});

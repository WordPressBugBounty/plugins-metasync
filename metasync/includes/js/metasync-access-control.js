/**
 * MetaSync access control UI — toggle options and type selection.
 *
 * Extracted from includes/class-metasync-access-control-ui.php (Phase 5, #887).
 */
document.addEventListener('DOMContentLoaded', function() {

    // Toggle access control options (enabled/disabled)
    document.querySelectorAll('.metasync-access-toggle-input').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            var featureKey = this.getAttribute('data-feature-key');
            var enabled = this.checked;
            var optionsDiv = document.getElementById('access-options-' + featureKey);
            var toggleLabel = this.closest('.access-control-toggle').querySelector('.toggle-label');

            if (enabled) {
                optionsDiv.style.display = 'block';
                toggleLabel.textContent = 'Restricted';
            } else {
                optionsDiv.style.display = 'none';
                toggleLabel.textContent = 'Available to All';
            }
        });
    });

    // Show/hide access type options (none / role / user)
    document.querySelectorAll('.metasync-access-type-input').forEach(function(radio) {
        radio.addEventListener('change', function() {
            var featureKey = this.getAttribute('data-feature-key');
            var type = this.value;
            var roleSelection = document.getElementById('role-selection-' + featureKey);
            var userSelection = document.getElementById('user-selection-' + featureKey);

            roleSelection.style.display = type === 'role' ? 'block' : 'none';
            userSelection.style.display = type === 'user' ? 'block' : 'none';
        });
    });

    // Add form validation
    var form = document.querySelector('form[action="options.php"]');
    if (form) {
        form.addEventListener('submit', function(e) {
            // Validate that at least one role or user is selected when type is role/user
            var accessControls = document.querySelectorAll('.metasync-access-control-row');
            var hasError = false;

            accessControls.forEach(function(row) {
                var enabledEl = row.querySelector('[name*="[enabled]"]');
                if (!enabledEl) return;
                var featureKey = enabledEl.name.match(/\[(.*?)\]/)[1];
                var enabled = enabledEl.checked;

                if (enabled) {
                    var typeEl = row.querySelector('[name*="[type]"]:checked');
                    if (!typeEl) return;
                    var type = typeEl.value;

                    if (type === 'role') {
                        var rolesChecked = row.querySelectorAll('[name*="[allowed_roles]"]:checked');
                        if (rolesChecked.length === 0) {
                            alert('Please select at least one role for: ' + row.querySelector('th label').textContent);
                            hasError = true;
                        }
                    } else if (type === 'user') {
                        var usersSelected = row.querySelector('[name*="[allowed_users]"]').selectedOptions;
                        if (usersSelected.length === 0) {
                            alert('Please select at least one user for: ' + row.querySelector('th label').textContent);
                            hasError = true;
                        }
                    }
                }
            });

            if (hasError) {
                e.preventDefault();
                return false;
            }
        });
    }
});

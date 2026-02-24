<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Access Control UI Renderer
 *
 * Handles rendering of access control UI components in the whitelabel settings.
 * Provides a clean, user-friendly interface for managing feature access.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */

class Metasync_Access_Control_UI {

    /**
     * Render access control row for a single feature
     *
     * @param string $feature_key Feature identifier
     * @param string $feature_label Feature display name
     * @return void
     */
    public static function render_feature_row($feature_key, $feature_label) {
        $config = Metasync_Access_Control::get_feature_config($feature_key);
        $row_id = 'access-control-' . $feature_key;
        ?>
        <tr class="metasync-access-control-row">
            <th scope="row">
                <label for="<?php echo esc_attr($row_id); ?>">
                    <?php echo esc_html($feature_label); ?>
                </label>
            </th>
            <td>
                <div class="metasync-access-control-wrapper">
                    <!-- Enable/Disable Access Control -->
                    <div class="access-control-toggle">
                        <label class="metasync-switch">
                            <input type="checkbox"
                                   name="metasync_options[whitelabel][access_control][<?php echo esc_attr($feature_key); ?>][enabled]"
                                   value="1"
                                   id="<?php echo esc_attr($row_id); ?>"
                                   <?php checked($config['enabled'], true); ?>
                                   onchange="toggleAccessControlOptions('<?php echo esc_attr($feature_key); ?>', this.checked)">
                            <span class="metasync-slider"></span>
                        </label>
                        <span class="toggle-label">
                            <?php echo $config['enabled'] ? 'Restricted' : 'Available to All'; ?>
                        </span>
                    </div>

                    <!-- Access Control Options (shown when enabled) -->
                    <div class="access-control-options"
                         id="access-options-<?php echo esc_attr($feature_key); ?>"
                         style="display: <?php echo $config['enabled'] ? 'block' : 'none'; ?>;">

                        <!-- Access Type Selection -->
                        <div class="access-type-selector">
                            <label class="access-label">Restrict Access To:</label>
                            <div class="access-radio-group">
                                <label class="radio-option">
                                    <input type="radio"
                                           name="metasync_options[whitelabel][access_control][<?php echo esc_attr($feature_key); ?>][type]"
                                           value="none"
                                           <?php checked($config['type'], 'none'); ?>
                                           onchange="showAccessTypeOptions('<?php echo esc_attr($feature_key); ?>', 'none')">
                                    <span>Hide from Everyone</span>
                                </label>

                                <label class="radio-option">
                                    <input type="radio"
                                           name="metasync_options[whitelabel][access_control][<?php echo esc_attr($feature_key); ?>][type]"
                                           value="role"
                                           <?php checked($config['type'], 'role'); ?>
                                           onchange="showAccessTypeOptions('<?php echo esc_attr($feature_key); ?>', 'role')">
                                    <span>Specific User Roles</span>
                                </label>

                                <label class="radio-option">
                                    <input type="radio"
                                           name="metasync_options[whitelabel][access_control][<?php echo esc_attr($feature_key); ?>][type]"
                                           value="user"
                                           <?php checked($config['type'], 'user'); ?>
                                           onchange="showAccessTypeOptions('<?php echo esc_attr($feature_key); ?>', 'user')">
                                    <span>Specific Users</span>
                                </label>
                            </div>
                        </div>

                        <!-- Role Selection (shown when type is 'role') -->
                        <div class="role-selection-container"
                             id="role-selection-<?php echo esc_attr($feature_key); ?>"
                             style="display: <?php echo $config['type'] === 'role' ? 'block' : 'none'; ?>;">
                            <?php self::render_role_selector($feature_key, $config['allowed_roles']); ?>
                        </div>

                        <!-- User Selection (shown when type is 'user') -->
                        <div class="user-selection-container"
                             id="user-selection-<?php echo esc_attr($feature_key); ?>"
                             style="display: <?php echo $config['type'] === 'user' ? 'block' : 'none'; ?>;">
                            <?php self::render_user_selector($feature_key, $config['allowed_users']); ?>
                        </div>
                    </div>
                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * Render role selector checkboxes
     *
     * @param string $feature_key Feature identifier
     * @param array $selected_roles Currently selected roles
     * @return void
     */
    private static function render_role_selector($feature_key, $selected_roles = array()) {
        $roles = Metasync_Access_Control::get_wordpress_roles();
        ?>
        <div class="role-checkboxes">
            <label class="access-label">Select User Roles:</label>
            <p class="description" style="margin-top: 0; margin-bottom: 10px;">
                Only users with the selected roles will have access to this feature. All other users will not see it.
            </p>
            <div class="checkbox-grid">
                <?php foreach ($roles as $role_slug => $role_name): ?>
                    <label class="checkbox-option">
                        <input type="checkbox"
                               name="metasync_options[whitelabel][access_control][<?php echo esc_attr($feature_key); ?>][allowed_roles][]"
                               value="<?php echo esc_attr($role_slug); ?>"
                               <?php checked(in_array($role_slug, $selected_roles)); ?>>
                        <span><?php echo esc_html($role_name); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render user selector dropdown with multi-select
     *
     * @param string $feature_key Feature identifier
     * @param array $selected_users Currently selected user IDs
     * @return void
     */
    private static function render_user_selector($feature_key, $selected_users = array()) {
        $users = Metasync_Access_Control::get_users();
        ?>
        <div class="user-select-container">
            <label class="access-label">Select Users:</label>
            <p class="description" style="margin-top: 0; margin-bottom: 10px;">
                Only the selected users will have access to this feature. All other users (even administrators) will not see it.
            </p>
            <select name="metasync_options[whitelabel][access_control][<?php echo esc_attr($feature_key); ?>][allowed_users][]"
                    class="metasync-user-select"
                    multiple="multiple"
                    size="8">
                <?php foreach ($users as $user): ?>
                    <option value="<?php echo esc_attr($user->ID); ?>"
                            <?php selected(in_array($user->ID, $selected_users)); ?>>
                        <?php echo esc_html($user->display_name); ?> (<?php echo esc_html($user->user_email); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="description" style="margin-top: 8px; font-style: italic;">
                Tip: Hold Ctrl/Cmd to select multiple users
            </p>
        </div>
        <?php
    }

    /**
     * Render complete access control table for all features
     *
     * @return void
     */
    public static function render_access_control_table() {
        $features = Metasync_Access_Control::get_features();
        ?>
        <div class="metasync-access-control-section">
            <h2>🔐 Advanced Access Control</h2>
            <p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">
                Control who can access each feature. You can restrict access by user role or specific users.
            </p>

            <table class="form-table metasync-access-control-table" role="presentation">
                <tbody>
                    <?php foreach ($features as $feature_key => $feature_label): ?>
                        <?php self::render_feature_row($feature_key, $feature_label); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php self::render_inline_styles(); ?>
        <?php self::render_inline_scripts(); ?>
        <?php
    }

    /**
     * Render inline CSS styles for access control UI
     *
     * @return void
     */
    private static function render_inline_styles() {
        ?>
        <style>
            .metasync-access-control-section {
                background: var(--dashboard-card-bg);
                padding: 25px;
                border-radius: 8px;
                margin-top: 25px;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }

            .metasync-access-control-section h2 {
                color: var(--dashboard-text-primary);
                margin-top: 0;
            }

            .metasync-access-control-wrapper {
                display: flex;
                flex-direction: column;
                gap: 15px;
            }

            .access-control-toggle {
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .metasync-switch {
                position: relative;
                display: inline-block;
                width: 50px;
                height: 24px;
            }

            .metasync-switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }

            .metasync-slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #ccc;
                transition: .3s;
                border-radius: 24px;
            }

            .metasync-slider:before {
                position: absolute;
                content: "";
                height: 18px;
                width: 18px;
                left: 3px;
                bottom: 3px;
                background-color: white;
                transition: .3s;
                border-radius: 50%;
            }

            .metasync-switch input:checked + .metasync-slider {
                background-color: #2196F3;
            }

            .metasync-switch input:checked + .metasync-slider:before {
                transform: translateX(26px);
            }

            .toggle-label {
                font-weight: 500;
                color: var(--dashboard-text-primary);
            }

            .access-control-options {
                padding: 15px;
                background: var(--dashboard-bg);
                border-radius: 6px;
                border: 1px solid var(--dashboard-border);
            }

            .access-type-selector {
                margin-bottom: 15px;
            }

            .access-label {
                display: block;
                font-weight: 600;
                margin-bottom: 10px;
                color: var(--dashboard-text-primary);
            }

            .access-radio-group {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            .radio-option,
            .checkbox-option {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 8px 12px;
                background: var(--dashboard-card-bg);
                border-radius: 4px;
                cursor: pointer;
                transition: background 0.2s;
            }

            .radio-option:hover,
            .checkbox-option:hover {
                background: var(--dashboard-hover-bg, rgba(0, 0, 0, 0.05));
            }

            .radio-option input,
            .checkbox-option input {
                cursor: pointer;
            }

            .radio-option span,
            .checkbox-option span {
                color: var(--dashboard-text-primary);
            }

            .role-selection-container,
            .user-selection-container {
                padding-top: 15px;
                border-top: 1px solid var(--dashboard-border);
                margin-top: 15px;
            }

            .checkbox-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 10px;
            }

            .user-select-container {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }

            .metasync-user-select {
                width: 100%;
                max-width: 500px;
                padding: 8px;
                border: 1px solid var(--dashboard-border);
                border-radius: 4px;
                background-color: var(--dashboard-bg) !important;
                color: var(--dashboard-text-primary) !important;
            }

            /* Dark mode specific fixes */
            [data-theme="dark"] .metasync-user-select {
                background-color: #1e1e1e !important;
                color: #e0e0e0 !important;
                border-color: #444 !important;
            }

            .metasync-user-select option {
                padding: 8px;
                background-color: var(--dashboard-bg) !important;
                color: var(--dashboard-text-primary) !important;
            }

            [data-theme="dark"] .metasync-user-select option {
                background-color: #1e1e1e !important;
                color: #e0e0e0 !important;
            }

            .metasync-user-select option:checked {
                background-color: #2196F3 !important;
                color: #ffffff !important;
            }

            [data-theme="dark"] .metasync-user-select option:checked {
                background-color: #0d7dd8 !important;
                color: #ffffff !important;
            }

            .description {
                font-size: 12px;
                color: var(--dashboard-text-secondary);
                margin: 0;
            }

            .metasync-access-control-row {
                border-bottom: 1px solid var(--dashboard-border);
            }

            .metasync-access-control-row:last-child {
                border-bottom: none;
            }
        </style>
        <?php
    }

    /**
     * Render inline JavaScript for access control interactions
     *
     * @return void
     */
    private static function render_inline_scripts() {
        ?>
        <script>
            function toggleAccessControlOptions(featureKey, enabled) {
                const optionsDiv = document.getElementById('access-options-' + featureKey);
                const toggleLabel = event.target.closest('.access-control-toggle').querySelector('.toggle-label');

                if (enabled) {
                    optionsDiv.style.display = 'block';
                    toggleLabel.textContent = 'Restricted';
                } else {
                    optionsDiv.style.display = 'none';
                    toggleLabel.textContent = 'Available to All';
                }
            }

            function showAccessTypeOptions(featureKey, type) {
                const roleSelection = document.getElementById('role-selection-' + featureKey);
                const userSelection = document.getElementById('user-selection-' + featureKey);

                roleSelection.style.display = type === 'role' ? 'block' : 'none';
                userSelection.style.display = type === 'user' ? 'block' : 'none';
            }

            // Initialize on page load
            document.addEventListener('DOMContentLoaded', function() {
                // Add form validation
                const form = document.querySelector('form[action="options.php"]');
                if (form) {
                    form.addEventListener('submit', function(e) {
                        // Validate that at least one role or user is selected when type is role/user
                        const accessControls = document.querySelectorAll('.metasync-access-control-row');
                        let hasError = false;

                        accessControls.forEach(function(row) {
                            const featureKey = row.querySelector('[name*="[enabled]"]').name.match(/\[(.*?)\]/)[1];
                            const enabled = row.querySelector('[name*="[enabled]"]').checked;

                            if (enabled) {
                                const type = row.querySelector('[name*="[type]"]:checked').value;

                                if (type === 'role') {
                                    const rolesChecked = row.querySelectorAll('[name*="[allowed_roles]"]:checked');
                                    if (rolesChecked.length === 0) {
                                        alert('Please select at least one role for: ' + row.querySelector('th label').textContent);
                                        hasError = true;
                                    }
                                } else if (type === 'user') {
                                    const usersSelected = row.querySelector('[name*="[allowed_users]"]').selectedOptions;
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
        </script>
        <?php
    }
}

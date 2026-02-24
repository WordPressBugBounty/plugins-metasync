<?php
/**
 * Access Control Management
 *
 * Handles granular access control for plugin features based on user roles and specific users.
 * Provides methods to check permissions and manage access control settings.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */

class Metasync_Access_Control {

    /**
     * Feature identifiers and their display names
     *
     * @var array
     */
    private static $features = array(
        'hide_dashboard' => 'Dashboard',
        'hide_settings' => 'Settings',
        'hide_indexation_control' => 'Indexation Control',
        'hide_redirections' => 'Redirect Manager',
        'hide_robots' => 'Robots.txt',
        'hide_xml_sitemap' => 'XML Sitemap',
        'hide_custom_pages' => 'Custom Pages',
        'hide_sync_log' => 'Sync Log',
        'hide_compatibility' => 'Compatibility',
        'hide_report_issue' => 'Report Issue',

        'hide_advanced' => 'Advanced Settings'
    );

    /**
     * Get all available features
     *
     * @return array Features array
     */
    public static function get_features() {
        return self::$features;
    }

    /**
     * Check if current user has access to a specific feature
     *
     * @param string $feature_key Feature identifier (e.g., 'hide_redirections')
     * @return bool True if user has access, false otherwise
     */
    public static function user_can_access($feature_key) {
        // Get whitelabel settings
        $whitelabel_settings = Metasync::get_whitelabel_settings();

        // If access control is not set for this feature, check old hide logic
        if (!isset($whitelabel_settings['access_control'][$feature_key])) {
            // Fallback to old behavior: if hidden, nobody can access
            return empty($whitelabel_settings[$feature_key]);
        }

        $access_config = $whitelabel_settings['access_control'][$feature_key];

        // If access control is disabled, feature is visible to all
        if (empty($access_config['enabled'])) {
            return true;
        }

        // Get current user
        $current_user = wp_get_current_user();

        // Determine access type
        $access_type = isset($access_config['type']) ? $access_config['type'] : 'all';

        switch ($access_type) {
            case 'role':
                return self::check_role_access($current_user, $access_config);

            case 'user':
                return self::check_user_access($current_user, $access_config);

            case 'none':
                // Feature is hidden from everyone
                return false;

            case 'all':
            default:
                // Feature is visible to all logged-in users
                return true;
        }
    }

    /**
     * Check if user has access based on role
     *
     * @param WP_User $user Current user object
     * @param array $access_config Access configuration
     * @return bool True if user's role is allowed
     */
    private static function check_role_access($user, $access_config) {
        if (empty($access_config['allowed_roles']) || !is_array($access_config['allowed_roles'])) {
            return false;
        }

        // Check if user has any of the allowed roles
        $user_roles = $user->roles;
        $allowed_roles = $access_config['allowed_roles'];

        return !empty(array_intersect($user_roles, $allowed_roles));
    }

    /**
     * Check if user has access based on user ID
     *
     * @param WP_User $user Current user object
     * @param array $access_config Access configuration
     * @return bool True if user ID is in allowed users
     */
    private static function check_user_access($user, $access_config) {
        if (empty($access_config['allowed_users']) || !is_array($access_config['allowed_users'])) {
            return false;
        }

        return in_array($user->ID, array_map('intval', $access_config['allowed_users']));
    }

    /**
     * Get access control configuration for a feature
     *
     * @param string $feature_key Feature identifier
     * @return array Access control configuration
     */
    public static function get_feature_config($feature_key) {
        $whitelabel_settings = Metasync::get_whitelabel_settings();

        $default_config = array(
            'enabled' => false,
            'type' => 'all',
            'allowed_roles' => array(),
            'allowed_users' => array()
        );

        // Check if new access control format exists
        if (!isset($whitelabel_settings['access_control'][$feature_key])) {
            // Check for old format (legacy hide checkbox) and migrate
            if (!empty($whitelabel_settings[$feature_key])) {
                // Old format was enabled (feature was hidden), migrate to new format
                return array(
                    'enabled' => true,
                    'type' => 'none', // Old hide logic = hide from everyone
                    'allowed_roles' => array(),
                    'allowed_users' => array()
                );
            }
            return $default_config;
        }

        return wp_parse_args($whitelabel_settings['access_control'][$feature_key], $default_config);
    }

    /**
     * Save access control configuration for a feature
     *
     * @param string $feature_key Feature identifier
     * @param array $config Configuration array
     * @return bool True on success, false on failure
     */
    public static function save_feature_config($feature_key, $config) {
        $options = get_option('metasync_options', array());

        if (!isset($options['whitelabel'])) {
            $options['whitelabel'] = array();
        }

        if (!isset($options['whitelabel']['access_control'])) {
            $options['whitelabel']['access_control'] = array();
        }

        // Sanitize configuration
        $sanitized_config = array(
            'enabled' => !empty($config['enabled']),
            'type' => in_array($config['type'], array('all', 'role', 'user', 'none')) ? $config['type'] : 'all',
            'allowed_roles' => isset($config['allowed_roles']) && is_array($config['allowed_roles'])
                ? array_map('sanitize_text_field', $config['allowed_roles'])
                : array(),
            'allowed_users' => isset($config['allowed_users']) && is_array($config['allowed_users'])
                ? array_map('intval', $config['allowed_users'])
                : array()
        );

        $options['whitelabel']['access_control'][$feature_key] = $sanitized_config;

        return update_option('metasync_options', $options);
    }

    /**
     * Get all WordPress user roles that can access the plugin
     *
     * Only returns roles that have 'manage_options' capability (required for plugin access)
     *
     * @return array Array of role slug => role name
     */
    public static function get_wordpress_roles() {
        global $wp_roles;

        if (!isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }

        $all_roles = $wp_roles->get_names();
        $filtered_roles = array();

        // Only include roles that have manage_options capability
        foreach ($all_roles as $role_slug => $role_name) {
            $role = get_role($role_slug);
            if ($role && $role->has_cap('manage_options')) {
                $filtered_roles[$role_slug] = $role_name;
            }
        }

        return $filtered_roles;
    }

    /**
     * Get all users who can access the plugin (for dropdown selection)
     *
     * Only returns users with 'manage_options' capability (required for plugin access)
     *
     * @param array $args Optional arguments for get_users
     * @return array Array of user objects
     */
    public static function get_users($args = array()) {
        $default_args = array(
            'orderby' => 'display_name',
            'order' => 'ASC',
            'number' => 100 // Limit to prevent performance issues
        );

        $args = wp_parse_args($args, $default_args);
        $all_users = get_users($args);
        $filtered_users = array();

        // Only include users who have manage_options capability
        foreach ($all_users as $user) {
            if (user_can($user, 'manage_options')) {
                $filtered_users[] = $user;
            }
        }

        return $filtered_users;
    }

    /**
     * Sanitize access control settings from form submission
     *
     * @param array $form_data Form data from whitelabel settings (just the access_control array)
     * @return array Sanitized access control configuration
     */
    public static function sanitize_access_control($form_data) {
        if (!is_array($form_data)) {
            return array();
        }

        $sanitized_access_control = array();

        // Process ALL features, not just ones in form_data
        // This ensures disabled features are properly saved with enabled=false
        foreach (self::$features as $feature_key => $feature_name) {
            $config = isset($form_data[$feature_key]) ? $form_data[$feature_key] : array();

            $sanitized_config = array(
                'enabled' => !empty($config['enabled']),
                'type' => isset($config['type']) && in_array($config['type'], array('all', 'role', 'user', 'none'))
                    ? $config['type']
                    : 'all',
                'allowed_roles' => array(),
                'allowed_users' => array()
            );

            // Process allowed roles
            if ($sanitized_config['type'] === 'role' && isset($config['allowed_roles']) && is_array($config['allowed_roles'])) {
                $sanitized_config['allowed_roles'] = array_map('sanitize_text_field', $config['allowed_roles']);
            }

            // Process allowed users
            if ($sanitized_config['type'] === 'user' && isset($config['allowed_users']) && is_array($config['allowed_users'])) {
                $sanitized_config['allowed_users'] = array_map('intval', $config['allowed_users']);
            }

            $sanitized_access_control[$feature_key] = $sanitized_config;
        }

        return $sanitized_access_control;
    }

    /**
     * Bulk update access control settings from form submission
     * (For backward compatibility and direct updates)
     *
     * @param array $form_data Form data from whitelabel settings
     * @return bool True on success
     */
    public static function process_bulk_update($form_data) {
        if (!isset($form_data['access_control']) || !is_array($form_data['access_control'])) {
            return false;
        }

        $options = get_option('metasync_options', array());

        if (!isset($options['whitelabel'])) {
            $options['whitelabel'] = array();
        }

        $options['whitelabel']['access_control'] = self::sanitize_access_control($form_data['access_control']);

        return update_option('metasync_options', $options);
    }

    /**
     * Clear all access control settings
     *
     * @return bool True on success
     */
    public static function clear_all_settings() {
        $options = get_option('metasync_options', array());

        if (isset($options['whitelabel']['access_control'])) {
            unset($options['whitelabel']['access_control']);
        }

        return update_option('metasync_options', $options);
    }
}

<?php
/**
 * Authentication Manager for MetaSync Plugin
 *
 * Provides secure, transient-based authentication for protected areas
 * Replaces session-based authentication with WordPress-native solutions
 *
 * Best Practices:
 * - Uses WordPress Transients (works with object caching, Redis, Memcached)
 * - User Meta for persistent access
 * - No PHP sessions (works on all hosting environments)
 * - OOP design for reusability
 * - Supports multiple authentication contexts
 *
 * @package MetaSync
 * @subpackage MetaSync/includes
 * @since 2.5.12
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Auth_Manager {

    /**
     * Authentication context identifier
     *
     * @var string
     */
    private $context;

    /**
     * Transient timeout in seconds (default: 30 minutes)
     *
     * @var int
     */
    private $transient_timeout = 1800;

    /**
     * User meta key for persistent access
     *
     * @var string
     */
    private $user_meta_key;

    /**
     * Current user ID
     *
     * @var int|null
     */
    private $user_id;

    /**
     * Constructor
     *
     * @param string $context Authentication context (e.g., 'debug', 'whitelabel')
     * @param int $timeout Transient timeout in seconds (default: 1800 = 30 minutes)
     */
    public function __construct($context = 'default', $timeout = 1800) {
        $this->context = sanitize_key($context);
        $this->transient_timeout = absint($timeout);
        $this->user_meta_key = 'metasync_' . $this->context . '_access';

        // Don't call wp_get_current_user() in constructor - it might not be available yet
        // Will be set lazily when needed via get_user_id()
        $this->user_id = null;
    }

    /**
     * Get current user ID (lazy loaded)
     *
     * @return int|null User ID or null if not available
     */
    private function get_user_id() {
        if ($this->user_id === null && function_exists('wp_get_current_user')) {
            $current_user = wp_get_current_user();
            $this->user_id = $current_user && $current_user->ID ? $current_user->ID : 0;
        }
        return $this->user_id > 0 ? $this->user_id : null;
    }

    /**
     * Check if user has access (checks both persistent and transient)
     *
     * @return bool True if user has access, false otherwise
     */
    public function has_access() {
        $user_id = $this->get_user_id();
        if (!$user_id) {
            return false;
        }

        // Check persistent access (user meta)
        if ($this->has_persistent_access()) {
            // Refresh transient activity
            $this->update_activity();
            return true;
        }

        // Check temporary access (transient)
        if ($this->has_transient_access()) {
            // Refresh transient activity
            $this->update_activity();
            return true;
        }

        return false;
    }

    /**
     * Check if user has persistent access via user meta
     *
     * @return bool
     */
    public function has_persistent_access() {
        $user_id = $this->get_user_id();
        if (!$user_id) {
            return false;
        }

        $access = get_user_meta($user_id, $this->user_meta_key, true);
        return $access === 'granted';
    }

    /**
     * Check if user has temporary access via transient
     *
     * @return bool
     */
    public function has_transient_access() {
        $user_id = $this->get_user_id();
        if (!$user_id) {
            return false;
        }

        $transient_key = $this->get_transient_key();
        $access = get_transient($transient_key);
        return $access === 'granted';
    }

    /**
     * Grant persistent access (user meta)
     *
     * @return bool True on success, false on failure
     */
    public function grant_persistent_access() {
        $user_id = $this->get_user_id();
        if (!$user_id) {
            return false;
        }

        $result = update_user_meta($user_id, $this->user_meta_key, 'granted');

        // Also set transient for immediate access
        $this->grant_transient_access();

        return $result !== false;
    }

    /**
     * Grant temporary access (transient)
     *
     * @return bool True on success, false on failure
     */
    public function grant_transient_access($epoch = null) {
        $user_id = $this->get_user_id();
        if (!$user_id) {
            return false;
        }

        $transient_key = $this->get_transient_key($epoch);
        $result = set_transient($transient_key, 'granted', $this->transient_timeout);

        // Set activity timestamp
        $this->update_activity($epoch);

        return $result;
    }

    /**
     * Revoke all access (both persistent and transient)
     *
     * @return bool True on success, false on failure
     */
    public function revoke_access() {
        $user_id = $this->get_user_id();
        if (!$user_id) {
            return false;
        }

        // Delete user meta
        delete_user_meta($user_id, $this->user_meta_key);

        // Delete transients
        delete_transient($this->get_transient_key());
        delete_transient($this->get_activity_transient_key());

        return true;
    }

    /**
     * Revoke only persistent access (keeps transient until expiration)
     *
     * @return bool True on success, false on failure
     */
    public function revoke_persistent_access() {
        $user_id = $this->get_user_id();
        if (!$user_id) {
            return false;
        }

        return delete_user_meta($user_id, $this->user_meta_key);
    }

    /**
     * Revoke only transient access (keeps persistent if granted)
     *
     * @return bool True on success, false on failure
     */
    public function revoke_transient_access() {
        $user_id = $this->get_user_id();
        if (!$user_id) {
            return false;
        }

        delete_transient($this->get_transient_key());
        delete_transient($this->get_activity_transient_key());

        return true;
    }

    /**
     * Update last activity timestamp
     *
     * @return bool True on success, false on failure
     */
    private function update_activity($epoch = null) {
        $user_id = $this->get_user_id();
        if (!$user_id) {
            return false;
        }

        $activity_key = $this->get_activity_transient_key($epoch);
        return set_transient($activity_key, time(), $this->transient_timeout);
    }

    /**
     * Get last activity timestamp
     *
     * @return int|false Timestamp or false if not found
     */
    public function get_last_activity() {
        $user_id = $this->get_user_id();
        if (!$user_id) {
            return false;
        }

        $activity_key = $this->get_activity_transient_key();
        return get_transient($activity_key);
    }

    /**
     * Check if access has timed out due to inactivity
     *
     * @return bool True if timed out, false otherwise
     */
    public function is_timed_out() {
        $last_activity = $this->get_last_activity();

        if ($last_activity === false) {
            return true;
        }

        $inactive_time = time() - $last_activity;
        return $inactive_time > $this->transient_timeout;
    }

    /**
     * Get transient key for this user and context
     *
     * @return string|false Returns transient key or false if no user ID available
     */
    private function get_transient_key($epoch = null) {
        $user_id = $this->get_user_id();
        if (!$user_id) {
            return false;
        }
        $epoch = $epoch === null ? self::get_revocation_epoch($this->context) : (int) $epoch;
        return 'metasync_auth_' . $this->context . '_' . $epoch . '_' . $user_id;
    }

    /**
     * Get activity transient key for this user and context
     *
     * @return string|false Returns transient key or false if no user ID available
     */
    private function get_activity_transient_key($epoch = null) {
        $user_id = $this->get_user_id();
        if (!$user_id) {
            return false;
        }
        $epoch = $epoch === null ? self::get_revocation_epoch($this->context) : (int) $epoch;
        return 'metasync_activity_' . $this->context . '_' . $epoch . '_' . $user_id;
    }

    private static function get_revocation_epoch($context) {
        $epoch = (int) get_option('metasync_auth_revoke_epoch_' . sanitize_key($context), 1);
        return $epoch > 0 ? $epoch : 1;
    }

    private function revoke_epoch_transient_access($epoch) {
        $user_id = $this->get_user_id();
        if (!$user_id) return;
        delete_transient('metasync_auth_' . $this->context . '_' . $epoch . '_' . $user_id);
        delete_transient('metasync_activity_' . $this->context . '_' . $epoch . '_' . $user_id);
    }

    /**
     * Get authentication status information
     *
     * @return array Status information
     */
    public function get_status() {
        $user_id = $this->get_user_id();
        if (!$user_id) {
            return array(
                'authenticated' => false,
                'user_id' => null,
                'context' => $this->context,
                'error' => 'No user logged in'
            );
        }

        return array(
            'authenticated' => $this->has_access(),
            'user_id' => $user_id,
            'context' => $this->context,
            'persistent_access' => $this->has_persistent_access(),
            'transient_access' => $this->has_transient_access(),
            'last_activity' => $this->get_last_activity(),
            'is_timed_out' => $this->is_timed_out(),
            'transient_timeout' => $this->transient_timeout,
            'user_meta_key' => $this->user_meta_key
        );
    }

    /**
     * Verify password and grant access
     *
     * @param string $password Password to verify
     * @param string|array $valid_passwords Valid password(s) to check against
     * @param bool $persistent Whether to grant persistent access
     * @return bool True if password is valid and access granted, false otherwise
     */
    public function verify_and_grant($password, $valid_passwords, $persistent = false) {
        if ($this->context === 'whitelabel' && $persistent) {
            return false;
        }
        $epoch_before = $this->context === 'whitelabel' ? self::get_revocation_epoch($this->context) : 0;
        if (!is_array($valid_passwords)) {
            $valid_passwords = array($valid_passwords);
        }

        // Remove empty passwords
        $valid_passwords = array_filter($valid_passwords);

        if (empty($valid_passwords)) {
            return false;
        }

        // Check if password matches any valid password
        $password_valid = in_array($password, $valid_passwords, true);

        if ($password_valid) {
            if ($this->context === 'whitelabel' && $epoch_before !== self::get_revocation_epoch($this->context)) {
                return false;
            }
            $granted = $persistent ? $this->grant_persistent_access() : $this->grant_transient_access($this->context === 'whitelabel' ? $epoch_before : null);
            if ($granted && $this->context === 'whitelabel' && $epoch_before !== self::get_revocation_epoch($this->context)) {
                $this->revoke_epoch_transient_access($epoch_before);
                return false;
            }
            return $granted;
        }

        return false;
    }

    /**
     * Static helper: Grant persistent access to a user
     *
     * @param int $user_id User ID
     * @param string $context Authentication context
     * @return bool True on success, false on failure
     */
    public static function grant_user_access($user_id, $context = 'default') {
        $user_meta_key = 'metasync_' . sanitize_key($context) . '_access';
        return update_user_meta($user_id, $user_meta_key, 'granted') !== false;
    }

    /**
     * Static helper: Revoke persistent access from a user
     *
     * @param int $user_id User ID
     * @param string $context Authentication context
     * @return bool True on success, false on failure
     */
    public static function revoke_user_access($user_id, $context = 'default') {
        $user_meta_key = 'metasync_' . sanitize_key($context) . '_access';
        return delete_user_meta($user_id, $user_meta_key);
    }

    /** Revoke persistent and transient grants for every user on this site. */
    public static function revoke_all_access($context = 'default') {
        $context = sanitize_key($context);
        $meta_key = 'metasync_' . $context . '_access';
        $users = function_exists('get_users') ? get_users(array('fields' => 'ID', 'meta_key' => $meta_key)) : array();
        $success = true;
        foreach ($users as $user_id) {
            if (!delete_user_meta($user_id, $meta_key)) {
                $success = false;
            }
        }
        // Bump the namespace so transient-only grants from every user are invalidated.
        if (!update_option('metasync_auth_revoke_epoch_' . $context, self::get_revocation_epoch($context) + 1, false)) {
            $success = false;
        }
        return $success;
    }

    /**
     * Static helper: Check if user has access
     *
     * @param int $user_id User ID
     * @param string $context Authentication context
     * @return bool True if user has access, false otherwise
     */
    public static function user_has_access($user_id, $context = 'default') {
        $auth = new self($context);
        return $auth->has_access();
    }
}

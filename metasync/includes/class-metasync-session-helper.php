<?php
/**
 * Session Helper for MetaSync Plugin
 *
 * Provides safe session handling with fallback directory support
 * Centralizes session management to avoid code duplication
 *
 * @package MetaSync
 * @subpackage MetaSync/includes
 * @since 1.0.0
 * @deprecated 2.5.12 Use Metasync_Auth_Manager instead for authentication
 *
 * DEPRECATION NOTICE:
 * This class is deprecated and should not be used for new implementations.
 * For authentication purposes, use Metasync_Auth_Manager which provides:
 * - Better compatibility with all hosting environments (no PHP session dependencies)
 * - WordPress-native solutions (transients + user meta)
 * - Support for Redis, Memcached, and object caching
 * - OOP design with clear API
 *
 * @see Metasync_Auth_Manager For authentication and access control
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Session_Helper {

    /**
     * Custom session directory path
     *
     * @var string|null
     */
    private static $custom_session_path = null;

    /**
     * Safely start a session with error handling
     * Fixes session directory permission issues by using custom WordPress upload directory
     *
     * @return bool True if session started successfully, false otherwise
     */
    public static function safe_start() {
        // Check if session is already started
        if (session_status() == PHP_SESSION_ACTIVE) {
            return true;
        }

        // Check if we have a session ID already
        if (session_id()) {
            return true;
        }

        // Don't start sessions during REST API requests, AJAX requests, or cron
        if (self::should_skip_session()) {
            return false;
        }

        // Set up custom session path
        self::setup_custom_session_path();

        // Suppress errors and try to start session
        try {
            @session_start();
            return session_status() == PHP_SESSION_ACTIVE;
        } catch (Exception $e) {
            // Log error but don't break the site
            error_log('MetaSync: Failed to start session: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if session should be skipped based on request context
     *
     * @return bool True if session should be skipped, false otherwise
     */
    private static function should_skip_session() {
        return (defined('REST_REQUEST') && REST_REQUEST) ||
               (defined('DOING_AJAX') && DOING_AJAX) ||
               (defined('DOING_CRON') && DOING_CRON);
    }

    /**
     * Set up custom session save path
     * Creates directory if it doesn't exist and configures PHP to use it
     * Skips path modification if a custom session handler (like Redis) is configured
     *
     * @return bool True if custom path was set successfully, false otherwise
     */
    private static function setup_custom_session_path() {
        // Check if a custom session handler is configured (Redis, Memcached, etc.)
        $session_handler = ini_get('session.save_handler');
        
        // If using a custom handler (not 'files'), don't override session.save_path
        // Custom handlers like Redis use session.save_path for connection parameters, not file paths
        // This prevents "Failed to read session data: redis" errors
        if ($session_handler !== 'files' && $session_handler !== '' && $session_handler !== false) {
            // Custom handler detected (Redis, Memcached, etc.) - don't modify session.save_path
            return false;
        }

        // Get custom session path (cached after first call)
        if (self::$custom_session_path === null) {
            $upload_dir = wp_upload_dir();
            self::$custom_session_path = $upload_dir['basedir'] . '/metasync-sessions';
        }

        // Create custom session directory if it doesn't exist
        if (!file_exists(self::$custom_session_path)) {
            @mkdir(self::$custom_session_path, 0755, true);
        }

        // Only set custom session save path if using default 'files' handler
        if (is_dir(self::$custom_session_path)) {
            @ini_set('session.save_path', self::$custom_session_path);
            return true;
        }

        return false;
    }

    /**
     * Close session and write data
     * Safe wrapper for session_write_close()
     *
     * @return bool True if session was closed, false if no active session
     */
    public static function close() {
        if (session_status() == PHP_SESSION_ACTIVE) {
            session_write_close();
            return true;
        }
        return false;
    }

    /**
     * Check if session is active
     *
     * @return bool True if session is active, false otherwise
     */
    public static function is_active() {
        return session_status() == PHP_SESSION_ACTIVE;
    }

    /**
     * Get session value
     *
     * @param string $key Session key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed Session value or default
     */
    public static function get($key, $default = null) {
        if (self::is_active() && isset($_SESSION[$key])) {
            return $_SESSION[$key];
        }
        return $default;
    }

    /**
     * Set session value
     * Automatically starts session if not active
     *
     * @param string $key Session key
     * @param mixed $value Session value
     * @return bool True if value was set, false otherwise
     */
    public static function set($key, $value) {
        if (!self::is_active()) {
            self::safe_start();
        }

        if (self::is_active()) {
            $_SESSION[$key] = $value;
            return true;
        }

        return false;
    }

    /**
     * Delete session value
     *
     * @param string $key Session key
     * @return bool True if value was deleted, false otherwise
     */
    public static function delete($key) {
        if (self::is_active() && isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
            return true;
        }
        return false;
    }

    /**
     * Destroy session
     *
     * @return bool True if session was destroyed, false otherwise
     */
    public static function destroy() {
        if (self::is_active()) {
            session_destroy();
            return true;
        }
        return false;
    }

    /**
     * Get custom session directory path
     *
     * @return string|null Custom session path or null if not set
     */
    public static function get_session_path() {
        if (self::$custom_session_path === null) {
            $upload_dir = wp_upload_dir();
            self::$custom_session_path = $upload_dir['basedir'] . '/metasync-sessions';
        }
        return self::$custom_session_path;
    }

    /**
     * Check if custom session directory exists and is writable
     *
     * @return array Status information about session directory
     */
    public static function get_status() {
        $path = self::get_session_path();
        return array(
            'custom_path' => $path,
            'exists' => is_dir($path),
            'writable' => is_writable($path),
            'session_active' => self::is_active(),
            'session_id' => self::is_active() ? session_id() : null,
            'should_skip' => self::should_skip_session()
        );
    }
}

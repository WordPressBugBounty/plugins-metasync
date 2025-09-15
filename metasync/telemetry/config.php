<?php
/**
 * Telemetry Configuration
 *
 * Configuration constants and settings for the telemetry system
 * Compatible with PHP 7.1+
 *
 * @package     Search Engine Labs SEO
 * @subpackage  Telemetry
 * @since       1.0.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Telemetry Configuration Class
 */
class Metasync_Telemetry_Config {

    /**
     * Default API endpoint base URL
     */
    const DEFAULT_API_BASE = 'https://dash-api-telemetry.searchatlas.com';
    
    /**
     * API endpoint path
     */
    const API_ENDPOINT_PATH = '/collect';
    
    /**
     * JWT token expiration time (1 hour)
     */
    const JWT_EXPIRATION_TIME = 3600;
    
    /**
     * Maximum retry attempts for failed requests
     */
    const MAX_RETRY_ATTEMPTS = 3;
    
    /**
     * Maximum queue size before auto-flush
     */
    const MAX_QUEUE_SIZE = 10;
    
    /**
     * Batch size for queue processing
     */
    const QUEUE_BATCH_SIZE = 5;
    
    /**
     * API request timeout (seconds)
     */
    const REQUEST_TIMEOUT = 30;
    
    /**
     * Maximum query execution time to consider "slow" (seconds)
     */
    const SLOW_QUERY_THRESHOLD = 1.0;
    
    /**
     * Maximum number of slow queries to log per request
     */
    const MAX_SLOW_QUERIES_LOGGED = 5;
    
    /**
     * Maximum SQL query length for logging (characters)
     */
    const MAX_QUERY_LOG_LENGTH = 500;
    
    /**
     * Queue flush interval (cron)
     */
    const QUEUE_FLUSH_INTERVAL = 'hourly';
    
    /**
     * JWT secret key option name
     */
    const JWT_SECRET_OPTION_NAME = 'metasync_telemetry_jwt_secret';
    
    /**
     * Telemetry settings option name
     */
    const TELEMETRY_SETTINGS_OPTION_NAME = 'metasync_telemetry_settings';

    /**
     * Get the full API endpoint URL for a site
     *
     * @param string $site_url Optional site URL, uses current site if not provided
     * @return string Full API endpoint URL
     */
    public static function get_api_endpoint($site_url = '') {
        if (empty($site_url)) {
            $site_url = preg_replace('#^https?://#', '', home_url());
        }
        
        return self::DEFAULT_API_BASE . self::API_ENDPOINT_PATH . '/' . $site_url;
    }
    
    /**
     * Get telemetry configuration settings
     *
     * @return array Configuration settings
     */
    public static function get_settings() {
        $default_settings = array(
            'enabled' => true,
            'api_endpoint' => self::get_api_endpoint(),
            'jwt_expiration' => self::JWT_EXPIRATION_TIME,
            'max_retries' => self::MAX_RETRY_ATTEMPTS,
            'max_queue_size' => self::MAX_QUEUE_SIZE,
            'request_timeout' => self::REQUEST_TIMEOUT,
            'slow_query_threshold' => self::SLOW_QUERY_THRESHOLD,
            'debug_mode' => defined('WP_DEBUG') && WP_DEBUG,
            'environment' => self::detect_environment()
        );
        
        $saved_settings = get_option(self::TELEMETRY_SETTINGS_OPTION_NAME, array());
        
        return array_merge($default_settings, $saved_settings);
    }
    
    /**
     * Update telemetry settings
     *
     * @param array $settings New settings
     * @return bool Success status
     */
    public static function update_settings($settings) {
        $current_settings = self::get_settings();
        $updated_settings = array_merge($current_settings, $settings);
        
        return update_option(self::TELEMETRY_SETTINGS_OPTION_NAME, $updated_settings);
    }
    
    /**
     * Check if telemetry is enabled
     *
     * @return bool True if enabled
     */
    public static function is_enabled() {
        // Check for global disable constant
        if (defined('METASYNC_DISABLE_TELEMETRY') && METASYNC_DISABLE_TELEMETRY) {
            return false;
        }
        
        // Check for local development mode
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_LOCAL_DEV') && WP_LOCAL_DEV) {
            return false;
        }
        
        // Check plugin settings
        $main_options = get_option('metasync_options', array());
        if (isset($main_options['disable_telemetry']) && $main_options['disable_telemetry']) {
            return false;
        }
        
        // Check telemetry-specific settings
        $settings = self::get_settings();
        return $settings['enabled'];
    }
    
    /**
     * Detect current environment
     *
     * @return string Environment name
     */
    public static function detect_environment() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return 'development';
        }
        
        $host = parse_url(home_url(), PHP_URL_HOST);
        if (strpos($host, 'staging') !== false || strpos($host, 'dev') !== false) {
            return 'staging';
        }
        
        return 'production';
    }
    
    /**
     * Get site hash for identification
     *
     * @return string Site hash
     */
    public static function get_site_hash() {
        $site_data = home_url() . get_bloginfo('name');
        return substr(md5($site_data), 0, 16);
    }
    
    /**
     * Get plugin information for telemetry
     *
     * @return array Plugin info
     */
    public static function get_plugin_info() {
        return array(
            'name' => 'Search Engine Labs SEO',
            'identifier' => 'metasync',
            'version' => defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0',
            'file' => plugin_basename(__FILE__)
        );
    }
    
    /**
     * Get system context information
     *
     * @return array System context
     */
    public static function get_system_context() {
        global $wpdb;
        
        return array(
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'php_os' => PHP_OS_FAMILY,
            'mysql_version' => method_exists($wpdb, 'get_var') ? $wpdb->get_var('SELECT VERSION()') : 'unknown',
            'server_software' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'unknown',
            'active_plugins' => count(get_option('active_plugins', array())),
            'active_theme' => get_template(),
            'multisite' => is_multisite(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_input_vars' => ini_get('max_input_vars')
        );
    }
    
    /**
     * Get error level mapping
     *
     * @return array Error level mapping
     */
    public static function get_error_level_map() {
        return array(
            E_ERROR => 'error',
            E_WARNING => 'warning',
            E_PARSE => 'error',
            E_NOTICE => 'info',
            E_CORE_ERROR => 'error',
            E_CORE_WARNING => 'warning',
            E_COMPILE_ERROR => 'error',
            E_COMPILE_WARNING => 'warning',
            E_USER_ERROR => 'error',
            E_USER_WARNING => 'warning',
            E_USER_NOTICE => 'info',
            E_STRICT => 'info',
            E_RECOVERABLE_ERROR => 'error',
            E_DEPRECATED => 'warning',
            E_USER_DEPRECATED => 'warning'
        );
    }
    
    /**
     * Get allowed log levels
     *
     * @return array Allowed log levels
     */
    public static function get_allowed_log_levels() {
        return array('debug', 'info', 'warning', 'error');
    }
    
    /**
     * Validate log level
     *
     * @param string $level Log level to validate
     * @return string Valid log level
     */
    public static function validate_log_level($level) {
        $allowed_levels = self::get_allowed_log_levels();
        return in_array($level, $allowed_levels) ? $level : 'info';
    }
    
    /**
     * Get JWT configuration
     *
     * @return array JWT configuration
     */
    public static function get_jwt_config() {
        return array(
            'algorithm' => 'HS256',
            'expiration_time' => self::JWT_EXPIRATION_TIME,
            'issuer' => home_url(),
            'audience' => 'dash-api-telemetry'
        );
    }
    
    /**
     * Get request headers for API calls
     *
     * @return array Default headers
     */
    public static function get_default_headers() {
        $plugin_info = self::get_plugin_info();
        
        return array(
            'Content-Type' => 'application/json',
            'User-Agent' => 'MetaSyncTelemetry/' . $plugin_info['version'],
            'X-Plugin-Version' => $plugin_info['version'],
            'X-Site-Hash' => self::get_site_hash(),
            'X-WordPress-Version' => get_bloginfo('version'),
            'X-PHP-Version' => PHP_VERSION
        );
    }
    
    /**
     * Sanitize configuration value
     *
     * @param string $key Configuration key
     * @param mixed $value Value to sanitize
     * @return mixed Sanitized value
     */
    public static function sanitize_config_value($key, $value) {
        switch ($key) {
            case 'enabled':
                return (bool) $value;
            case 'api_endpoint':
                return esc_url_raw($value);
            case 'jwt_expiration':
            case 'max_retries':
            case 'max_queue_size':
            case 'request_timeout':
                return absint($value);
            case 'slow_query_threshold':
                return floatval($value);
            case 'environment':
                $allowed = array('development', 'staging', 'production');
                return in_array($value, $allowed) ? $value : 'production';
            default:
                return sanitize_text_field($value);
        }
    }
    
    /**
     * Reset telemetry configuration to defaults
     *
     * @return bool Success status
     */
    public static function reset_to_defaults() {
        delete_option(self::TELEMETRY_SETTINGS_OPTION_NAME);
        delete_option(self::JWT_SECRET_OPTION_NAME);
        return true;
    }
}

<?php
/**
 * Sentry-Compatible Telemetry Collector
 *
 * Collects and formats telemetry data in Sentry-compatible format
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
 * Sentry Telemetry Collector Class
 * 
 * Provides Sentry-compatible telemetry data collection and formatting
 */
class Metasync_Sentry_Telemetry {

    /**
     * Plugin version
     * @var string
     */
    private $plugin_version;

    /**
     * Environment (production, staging, development)
     * @var string
     */
    private $environment;

    /**
     * Release identifier
     * @var string
     */
    private $release;

    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_version = defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0';
        $this->environment = $this->detect_environment();
        $this->release = $this->plugin_version;
    }

    /**
     * Detect the current environment
     * 
     * @return string
     */
    private function detect_environment() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return 'development';
        }
        
        $host = parse_url(home_url(), PHP_URL_HOST);
        if ($host && (strpos($host, 'staging') !== false || strpos($host, 'dev') !== false)) {
            return 'staging';
        }
        
        return 'production';
    }

    /**
     * Capture an exception/error
     * 
     * @param Exception|Error|string $exception The exception/error to capture
     * @param array $extra Additional context data
     * @return array Sentry-compatible error data
     */
    public function capture_exception($exception, $extra = array()) {
        $error_data = array();

        if (is_object($exception)) {
            $error_data = array(
                'exception' => array(
                    'values' => array(
                        array(
                            'type' => get_class($exception),
                            'value' => $exception->getMessage(),
                            'stacktrace' => $this->format_stacktrace($exception->getTrace()),
                            'module' => $this->get_module_from_file($exception->getFile())
                        )
                    )
                )
            );
        } else {
            // Handle string errors
            $error_data = array(
                'message' => array(
                    'message' => is_string($exception) ? $exception : 'Unknown error',
                    'formatted' => is_string($exception) ? $exception : 'Unknown error'
                )
            );
        }

        return $this->create_telemetry_payload('error', $error_data, $extra);
    }

    /**
     * Capture a message/event
     * 
     * @param string $message The message to capture
     * @param string $level The log level (error, warning, info, debug)
     * @param array $extra Additional context data
     * @return array Sentry-compatible message data
     */
    public function capture_message($message, $level = 'info', $extra = array()) {
        $message_data = array(
            'message' => array(
                'message' => $message,
                'formatted' => $message
            )
        );

        return $this->create_telemetry_payload($level, $message_data, $extra);
    }

    /**
     * Capture plugin activation event
     * 
     * @param array $plugin_data Plugin information
     * @return array Telemetry data
     */
    public function capture_activation($plugin_data = array()) {
        $context = array_merge($plugin_data, array(
            'event_type' => 'plugin_activation',
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'plugins_count' => count(get_option('active_plugins', array())),
            'theme' => get_template()
        ));

        return $this->capture_message('Plugin activated', 'info', $context);
    }

    /**
     * Capture plugin deactivation event
     * 
     * @param array $context Additional context
     * @return array Telemetry data
     */
    public function capture_deactivation($context = array()) {
        $context = array_merge($context, array(
            'event_type' => 'plugin_deactivation'
        ));

        return $this->capture_message('Plugin deactivated', 'info', $context);
    }

    /**
     * Capture performance metrics
     * 
     * @param string $operation Operation name
     * @param float $duration Duration in seconds
     * @param array $context Additional context
     * @return array Telemetry data
     */
    public function capture_performance($operation, $duration, $context = array()) {
        $performance_data = array_merge($context, array(
            'event_type' => 'performance',
            'operation' => $operation,
            'duration' => $duration,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ));

        return $this->capture_message("Performance: {$operation}", 'info', $performance_data);
    }

    /**
     * Create base telemetry payload
     * 
     * @param string $level Log level
     * @param array $data Event-specific data
     * @param array $extra Additional context
     * @return array Complete telemetry payload
     */
    private function create_telemetry_payload($level, $data, $extra = array()) {
        $payload = array(
            'event_id' => $this->generate_event_id(),
            'timestamp' => gmdate('c'),
            'level' => $level,
            'platform' => 'php',
            'sdk' => array(
                'name' => 'metasync-telemetry',
                'version' => $this->plugin_version
            ),
            'server_name' => parse_url(home_url(), PHP_URL_HOST),
            'release' => $this->release,
            'environment' => $this->environment,
            'contexts' => array(
                'runtime' => array(
                    'name' => 'php',
                    'version' => PHP_VERSION
                ),
                'os' => array(
                    'name' => PHP_OS_FAMILY,
                    'version' => php_uname('r')
                ),
                'app' => array(
                    'app_name' => 'Search Engine Labs SEO',
                    'app_version' => $this->plugin_version,
                    'app_identifier' => 'metasync'
                )
            ),
            'tags' => array(
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'environment' => $this->environment,
                'plugin_version' => $this->plugin_version
            ),
            'user' => array(
                'id' => $this->get_user_hash(),
                'ip_address' => $this->get_client_ip()
            ),
            'extra' => array_merge($this->get_system_context(), $extra)
        );

        return array_merge($payload, $data);
    }

    /**
     * Generate a unique event ID
     * 
     * @return string 32-character hex event ID
     */
    private function generate_event_id() {
        return str_replace('-', '', wp_generate_uuid4());
    }

    /**
     * Format stacktrace for Sentry compatibility
     * 
     * @param array $trace PHP stack trace
     * @return array Formatted stacktrace
     */
    private function format_stacktrace($trace) {
        $frames = array();
        
        foreach ($trace as $frame) {
            $frames[] = array(
                'filename' => isset($frame['file']) ? $frame['file'] : '<unknown>',
                'lineno' => isset($frame['line']) ? $frame['line'] : 0,
                'function' => isset($frame['function']) ? $frame['function'] : '<unknown>',
                'module' => isset($frame['class']) ? $frame['class'] : null,
                'in_app' => $this->is_in_app($frame),
                'context_line' => $this->get_source_line($frame)
            );
        }

        return array('frames' => array_reverse($frames));
    }

    /**
     * Check if a stack frame is in application code
     * 
     * @param array $frame Stack frame
     * @return bool True if in application code
     */
    private function is_in_app($frame) {
        if (!isset($frame['file'])) {
            return false;
        }
        
        $wp_content_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
        return strpos($frame['file'], $wp_content_dir) !== false;
    }

    /**
     * Get source line from a stack frame
     * 
     * @param array $frame Stack frame
     * @return string|null Source line or null
     */
    private function get_source_line($frame) {
        if (!isset($frame['file']) || !isset($frame['line']) || !is_readable($frame['file'])) {
            return null;
        }

        $lines = file($frame['file']);
        $line_index = $frame['line'] - 1;
        
        return isset($lines[$line_index]) ? rtrim($lines[$line_index]) : null;
    }

    /**
     * Get module name from file path
     * 
     * @param string $file File path
     * @return string Module name
     */
    private function get_module_from_file($file) {
        if (strpos($file, plugin_dir_path(__FILE__)) === 0) {
            return 'metasync';
        }
        
        return basename(dirname($file));
    }

    /**
     * Get anonymous user hash
     * 
     * @return string Hashed user identifier
     */
    private function get_user_hash() {
        $site_url = home_url();
        return substr(md5($site_url), 0, 16);
    }

    /**
     * Get client IP address
     * 
     * @return string Client IP address
     */
    private function get_client_ip() {
        // Return anonymized IP for privacy
        return '0.0.0.0';
    }

    /**
     * Get system context information
     * 
     * @return array System context
     */
    private function get_system_context() {
        return array(
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'mysql_version' => $this->get_mysql_version(),
            'server_software' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'unknown',
            'active_plugins' => count(get_option('active_plugins', array())),
            'active_theme' => get_template(),
            'multisite' => is_multisite(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time')
        );
    }

    /**
     * Get MySQL version
     * 
     * @return string MySQL version
     */
    private function get_mysql_version() {
        global $wpdb;
        return $wpdb->get_var('SELECT VERSION()');
    }

    /**
     * Capture WordPress hook execution
     * 
     * @param string $hook Hook name
     * @param array $args Hook arguments
     * @param float $execution_time Execution time
     * @return array Telemetry data
     */
    public function capture_hook_execution($hook, $args, $execution_time) {
        $context = array(
            'event_type' => 'hook_execution',
            'hook_name' => $hook,
            'args_count' => count($args),
            'execution_time' => $execution_time
        );

        return $this->capture_message("Hook executed: {$hook}", 'debug', $context);
    }

    /**
     * Capture database query performance
     * 
     * @param string $query SQL query
     * @param float $execution_time Execution time
     * @param array $context Additional context
     * @return array Telemetry data
     */
    public function capture_db_query($query, $execution_time, $context = array()) {
        $query_context = array_merge($context, array(
            'event_type' => 'database_query',
            'query' => $this->sanitize_query($query),
            'execution_time' => $execution_time,
            'query_type' => $this->get_query_type($query)
        ));

        return $this->capture_message("Database query executed", 'debug', $query_context);
    }

    /**
     * Sanitize SQL query for logging
     * 
     * @param string $query SQL query
     * @return string Sanitized query
     */
    private function sanitize_query($query) {
        // Remove sensitive data and truncate long queries
        $query = preg_replace('/\b\d{4}[- ]?\d{4}[- ]?\d{4}[- ]?\d{4}\b/', 'XXXX-XXXX-XXXX-XXXX', $query);
        $query = preg_replace('/\b[\w\.-]+@[\w\.-]+\.\w+\b/', '[email]', $query);
        
        if (strlen($query) > 500) {
            $query = substr($query, 0, 497) . '...';
        }
        
        return $query;
    }

    /**
     * Get query type from SQL
     * 
     * @param string $query SQL query
     * @return string Query type
     */
    private function get_query_type($query) {
        $query = strtoupper(trim($query));
        if (preg_match('/^(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\b/', $query, $matches)) {
            return strtolower($matches[1]);
        }
        return 'unknown';
    }
}

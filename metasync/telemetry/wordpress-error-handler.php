<?php
/**
 * WordPress Error Handler for Sentry Integration
 * 
 * Automatically captures WordPress errors and sends them to Sentry
 * Includes WP URL and Plugin Version as required
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * WordPress Error Handler Class
 */
class MetaSync_WordPress_Error_Handler {
    
    /**
     * Plugin directory for filtering errors
     */
    private $plugin_dir;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_dir = dirname(__DIR__); // Parent directory of telemetry folder
        $this->setup_error_handlers();
    }
    
    /**
     * Setup WordPress error handlers
     */
    private function setup_error_handlers() {
        // Hook into WordPress error handling
        add_action('wp_die_handler', array($this, 'capture_wp_die'), 10, 1);
        
        // Hook into PHP error handling
        set_error_handler(array($this, 'capture_php_error'), E_ALL);
        set_exception_handler(array($this, 'capture_exception'));
        
        // Hook into WordPress fatal error handler
        add_action('wp_fatal_error_handler_enabled', '__return_true');
        add_filter('wp_fatal_error_handler', array($this, 'capture_fatal_error'));
        
        // Hook into plugin activation/deactivation errors
        add_action('activated_plugin', array($this, 'capture_plugin_activation'), 10, 2);
        add_action('deactivated_plugin', array($this, 'capture_plugin_deactivation'), 10, 2);
        
        // Hook into WordPress shutdown to catch fatal errors
        register_shutdown_function(array($this, 'capture_shutdown_error'));
    }
    
    /**
     * Capture WordPress die events
     */
    public function capture_wp_die($message) {
        if ($this->should_capture_error()) {
            $this->send_to_sentry('wp_die', $message, array(
                'error_type' => 'wp_die',
                'message' => is_string($message) ? $message : serialize($message),
                'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10)
            ));
        }
    }
    
    /**
     * Capture PHP errors
     */
    public function capture_php_error($severity, $message, $file, $line) {
        // Only capture errors from our plugin or related to our plugin
        if ($this->should_capture_error($file)) {
            $this->send_to_sentry('php_error', $message, array(
                'error_type' => 'php_error',
                'severity' => $severity,
                'file' => $file,
                'line' => $line,
                'error_level' => $this->get_error_level($severity),
                'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10)
            ));
        }
        
        // Don't prevent normal error handling
        return false;
    }
    
    /**
     * Capture uncaught exceptions
     */
    public function capture_exception($exception) {
        if ($this->should_capture_error($exception->getFile())) {
            $this->send_to_sentry('exception', $exception->getMessage(), array(
                'error_type' => 'uncaught_exception',
                'exception_class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
                'exception_object' => $exception
            ));
        }
    }
    
    /**
     * Capture fatal errors
     */
    public function capture_fatal_error($error) {
        if (is_array($error) && $this->should_capture_error($error['file'] ?? '')) {
            $this->send_to_sentry('fatal_error', $error['message'] ?? 'Fatal error', array(
                'error_type' => 'fatal_error',
                'error_details' => $error,
                'file' => $error['file'] ?? 'unknown',
                'line' => $error['line'] ?? 0
            ));
        }
        return $error;
    }
    
    /**
     * Capture shutdown errors
     */
    public function capture_shutdown_error() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            if ($this->should_capture_error($error['file'])) {
                $this->send_to_sentry('shutdown_error', $error['message'], array(
                    'error_type' => 'shutdown_error',
                    'error_details' => $error,
                    'file' => $error['file'],
                    'line' => $error['line']
                ));
            }
        }
    }
    
    /**
     * Capture plugin activation events
     */
    public function capture_plugin_activation($plugin, $network_wide) {
        // Only track our plugin
        if (strpos($plugin, 'metasync') !== false) {
            $this->send_to_sentry('plugin_activation', 'Plugin activated successfully', array(
                'event_type' => 'plugin_lifecycle',
                'action' => 'activation',
                'plugin' => $plugin,
                'network_wide' => $network_wide,
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION
            ));
        }
    }
    
    /**
     * Capture plugin deactivation events
     */
    public function capture_plugin_deactivation($plugin) {
        // Only track our plugin
        if (strpos($plugin, 'metasync') !== false) {
            $this->send_to_sentry('plugin_deactivation', 'Plugin deactivated', array(
                'event_type' => 'plugin_lifecycle',
                'action' => 'deactivation',
                'plugin' => $plugin,
                'reason' => 'user_action'
            ));
        }
    }
    
    /**
     * Determine if we should capture this error
     */
    private function should_capture_error($file = '') {
        // Always capture if no file specified
        if (empty($file)) {
            return true;
        }
        
        // Capture if error is from our plugin directory
        if (strpos($file, $this->plugin_dir) !== false) {
            return true;
        }
        
        // Capture if error mentions our plugin
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        foreach ($backtrace as $trace) {
            if (isset($trace['file']) && strpos($trace['file'], $this->plugin_dir) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Send error to Sentry with required metadata
     */
    private function send_to_sentry($error_type, $message, $context = array()) {
        // Add required metadata
        $context = array_merge($context, array(
            'wp_url' => home_url(),
            'plugin_version' => defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0',
            'plugin_name' => 'Search Engine Labs SEO (MetaSync)',
            'error_timestamp' => date('Y-m-d H:i:s'),
            'site_info' => array(
                'site_title' => get_bloginfo('name'),
                'wp_version' => get_bloginfo('version'),
                'admin_email' => get_bloginfo('admin_email'),
                'active_theme' => get_template(),
                'php_version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'multisite' => is_multisite()
            ),
            'request_info' => array(
                'url' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'referer' => $_SERVER['HTTP_REFERER'] ?? 'none'
            )
        ));
        
        // Determine severity level
        $level = $this->get_sentry_level($error_type);
        
        // Send to Sentry using the WordPress integration
        if (function_exists('metasync_sentry_capture_exception') && isset($context['exception_object'])) {
            metasync_sentry_capture_exception($context['exception_object'], $context);
        } elseif (function_exists('metasync_sentry_capture_message')) {
            metasync_sentry_capture_message($message, $level, $context);
        }
        
        // Also send to custom telemetry backend if available
        if (function_exists('metasync_telemetry')) {
            if (isset($context['exception_object'])) {
                metasync_telemetry()->send_exception($context['exception_object'], $context);
            } else {
                metasync_telemetry()->send_message($message, $level, $context);
            }
        }
    }
    
    /**
     * Get error level name
     */
    private function get_error_level($severity) {
        $levels = array(
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED'
        );
        
        return $levels[$severity] ?? 'UNKNOWN';
    }
    
    /**
     * Get Sentry level from error type
     */
    private function get_sentry_level($error_type) {
        $levels = array(
            'php_error' => 'error',
            'exception' => 'error',
            'uncaught_exception' => 'error',
            'fatal_error' => 'fatal',
            'shutdown_error' => 'fatal',
            'wp_die' => 'error',
            'plugin_activation' => 'info',
            'plugin_deactivation' => 'info'
        );
        
        return $levels[$error_type] ?? 'error';
    }
}

// Initialize the error handler
if (function_exists('add_action')) {
    add_action('init', function() {
        new MetaSync_WordPress_Error_Handler();
    }, 1);
}
?>

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
     * Plugin slug for filtering
     */
    private $plugin_slug = 'metasync';
    
    /**
     * Previous error handler to restore
     */
    private $previous_error_handler;
    
    /**
     * Previous exception handler to restore
     */
    private $previous_exception_handler;
    
    /**
     * Track sent errors to prevent duplicates (in-memory cache)
     */
    private $sent_errors = array();

    /**
     * Maximum number of errors to track in memory
     */
    private $max_tracked_errors = 100;

    /**
     * Transient key for persistent error tracking
     */
    private $transient_key = 'metasync_sent_errors';

    /**
     * How long to remember sent errors (in seconds)
     * Default: 1 hour - errors will be sent again after this time
     */
    private $error_memory_duration = 3600;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_dir = dirname(__DIR__); // Parent directory of telemetry folder
        $this->load_sent_errors_from_cache();
        $this->setup_error_handlers();
    }

    /**
     * Load previously sent errors from persistent cache
     */
    private function load_sent_errors_from_cache() {
        $cached_errors = get_transient($this->transient_key);
        if (is_array($cached_errors)) {
            $this->sent_errors = $cached_errors;
        }
    }

    /**
     * Save sent errors to persistent cache
     */
    private function save_sent_errors_to_cache() {
        set_transient($this->transient_key, $this->sent_errors, $this->error_memory_duration);
    }
    
    /**
     * Setup WordPress error handlers - Only for plugin-specific errors
     */
    private function setup_error_handlers() {
        // Store previous handlers to chain them properly
        $this->previous_error_handler = set_error_handler(array($this, 'capture_php_error'), E_ALL);
        $this->previous_exception_handler = set_exception_handler(array($this, 'capture_exception'));
        
        // Hook into WordPress fatal error handler (only for plugin errors)
        add_filter('wp_fatal_error_handler', array($this, 'capture_fatal_error'));
        
        // Hook into plugin activation/deactivation errors (only for our plugin)
        add_action('activated_plugin', array($this, 'capture_plugin_activation'), 10, 2);
        add_action('deactivated_plugin', array($this, 'capture_plugin_deactivation'), 10, 2);
        
        // Hook into WordPress shutdown to catch fatal errors (only plugin-related)
        register_shutdown_function(array($this, 'capture_shutdown_error'));
        
        // Remove wp_die handler as it captures too many system errors
        // add_action('wp_die_handler', array($this, 'capture_wp_die'), 10, 1);
    }
    
    /**
     * Capture WordPress die events - REMOVED
     * This method was too broad and captured system-wide wp_die events
     * We now only capture plugin-specific errors through other handlers
     */
    // public function capture_wp_die($message) - REMOVED TO PREVENT SYSTEM-WIDE ERROR CAPTURE
    
    /**
     * Capture PHP errors - Only plugin-specific errors
     */
    public function capture_php_error($severity, $message, $file, $line) {
        // First, call the previous error handler if it exists
        $handled = false;
        if ($this->previous_error_handler && is_callable($this->previous_error_handler)) {
            $handled = call_user_func($this->previous_error_handler, $severity, $message, $file, $line);
        }
        
        // Only capture errors from our plugin or directly related to our plugin
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
        
        // Return the result from the previous handler, or false to continue normal error handling
        return $handled;
    }
    
    /**
     * Capture uncaught exceptions - Only plugin-specific exceptions
     */
    public function capture_exception($exception) {
        // First, call the previous exception handler if it exists
        if ($this->previous_exception_handler && is_callable($this->previous_exception_handler)) {
            call_user_func($this->previous_exception_handler, $exception);
        }
        
        // Only capture exceptions from our plugin or directly related to our plugin
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
     * Determine if we should capture this error - Much more restrictive filtering
     */
    private function should_capture_error($file = '') {
        # Don't capture if no file specified - this prevents capturing system-wide errors
        if (empty($file)) {
            return false;
        }
        
        # Primary check: error must be directly from our plugin directory
        if (strpos($file, $this->plugin_dir) !== false) {
            return true;
        }
        
        # Secondary check: error must be in a file that contains our plugin slug
        if (strpos($file, $this->plugin_slug) !== false) {
            return true;
        }
        
        # REMOVED: Tertiary backtrace check that was too broad
        # The previous backtrace logic was capturing errors from other plugins
        # that happened to be called during MetaSync execution. We now only
        # capture errors that directly originate from MetaSync files.
        
        # Additional check: Only capture if the error file path contains 'metasync'
        # This is a more conservative approach to avoid false positives
        $file_lower = strtolower($file);
        if (strpos($file_lower, 'metasync') !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if the current environment is localhost/development
     */
    private function is_localhost() {
        $host = parse_url(home_url(), PHP_URL_HOST);
        
        // Check for common localhost patterns
        $localhost_patterns = [
            'localhost',
            '127.0.0.1',
            '::1',
            '0.0.0.0',
            '.local',
            '.test',
            '.dev',
            '.localhost'
        ];
        
        foreach ($localhost_patterns as $pattern) {
            if (strpos($host, $pattern) !== false) {
                return true;
            }
        }
        
        // Check if host is an IP address in private ranges
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = ip2long($host);
            if ($ip !== false) {
                // Private IP ranges: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16
                if (($ip >= ip2long('10.0.0.0') && $ip <= ip2long('10.255.255.255')) ||
                    ($ip >= ip2long('172.16.0.0') && $ip <= ip2long('172.31.255.255')) ||
                    ($ip >= ip2long('192.168.0.0') && $ip <= ip2long('192.168.255.255'))) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Send error to Sentry with required metadata
     */
    private function send_to_sentry($error_type, $message, $context = array()) {
        // Skip sending to Sentry if running on localhost/development environment
        if ($this->is_localhost()) {
            return;
        }
        
        // Generate error fingerprint for deduplication
        $error_fingerprint = $this->generate_error_fingerprint($error_type, $message, $context);
        
        // Check if this error has already been sent
        if ($this->is_error_already_sent($error_fingerprint)) {
            return; // Skip sending duplicate error
        }
        
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
        
        // Send directly to Sentry (wordpress-error-handler is the primary/only error capture point)
        $sentry_success = false;

        if (function_exists('metasync_sentry_capture_exception') && isset($context['exception_object'])) {
            $sentry_success = metasync_sentry_capture_exception($context['exception_object'], $context);
        } elseif (function_exists('metasync_sentry_capture_message')) {
            $sentry_success = metasync_sentry_capture_message($message, $level, $context);
        }

        // Only mark as sent if Sentry call was successful
        if ($sentry_success) {
            $this->mark_error_as_sent($error_fingerprint);
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
    
    /**
     * Generate a unique fingerprint for an error to detect duplicates
     * 
     * @param string $error_type Type of error
     * @param string $message Error message
     * @param array $context Error context
     * @return string Unique fingerprint
     */
    private function generate_error_fingerprint($error_type, $message, $context = array()) {
        // Create a fingerprint based on key error characteristics
        $fingerprint_data = array(
            'error_type' => $error_type,
            'message' => $message,
            'file' => $context['file'] ?? '',
            'line' => $context['line'] ?? 0,
            'exception_class' => $context['exception_class'] ?? '',
            'severity' => $context['severity'] ?? 0
        );
        
        // Create a hash of the fingerprint data
        return md5(serialize($fingerprint_data));
    }
    
    /**
     * Check if an error has already been sent
     *
     * @param string $error_fingerprint Error fingerprint
     * @return bool True if already sent
     */
    private function is_error_already_sent($error_fingerprint) {
        // Check if error exists and hasn't expired
        if (isset($this->sent_errors[$error_fingerprint])) {
            $sent_time = $this->sent_errors[$error_fingerprint];
            $time_elapsed = time() - $sent_time;

            // If error was sent recently (within memory duration), skip it
            if ($time_elapsed < $this->error_memory_duration) {
                return true;
            } else {
                // Error has expired, remove it from cache
                unset($this->sent_errors[$error_fingerprint]);
                $this->save_sent_errors_to_cache();
            }
        }

        return false;
    }
    
    /**
     * Mark an error as sent to prevent duplicates
     *
     * @param string $error_fingerprint Error fingerprint
     */
    private function mark_error_as_sent($error_fingerprint) {
        // Add to sent errors array
        $this->sent_errors[$error_fingerprint] = time();

        // Clean up old entries to prevent memory bloat
        $this->cleanup_old_errors();

        // Persist to cache
        $this->save_sent_errors_to_cache();
    }
    
    /**
     * Clean up old error entries to prevent memory bloat
     */
    private function cleanup_old_errors() {
        $current_time = time();

        // Remove expired errors
        foreach ($this->sent_errors as $fingerprint => $timestamp) {
            if ($current_time - $timestamp > $this->error_memory_duration) {
                unset($this->sent_errors[$fingerprint]);
            }
        }

        // If we still have too many errors tracked, remove the oldest ones
        if (count($this->sent_errors) > $this->max_tracked_errors) {
            // Sort by timestamp (oldest first)
            asort($this->sent_errors);

            // Remove oldest entries, keeping only the most recent ones
            $errors_to_remove = count($this->sent_errors) - $this->max_tracked_errors;
            $this->sent_errors = array_slice($this->sent_errors, $errors_to_remove, null, true);
        }
    }
}

// Initialize the error handler immediately when this file is loaded
// This ensures we catch errors that happen during plugin initialization
// Must be initialized early to catch parse errors and fatal errors
if (class_exists('MetaSync_WordPress_Error_Handler')) {
    new MetaSync_WordPress_Error_Handler();
}
?>

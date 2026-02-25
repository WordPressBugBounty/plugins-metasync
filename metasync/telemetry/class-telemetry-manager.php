<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Main Telemetry Manager Class
 * 
 * Handles telemetry system initialization and WordPress integration
 */
class Metasync_Telemetry_Manager {

    /**
     * Telemetry collector instance (Sentry format)
     * @var Metasync_Sentry_Telemetry
     */
    private $telemetry_collector;

    /**
     * Sentry integration instance
     * @var Metasync_Sentry_Integration
     */
    private $sentry_integration;

    /**
     * Plugin start time for performance tracking
     * @var float
     */
    private $plugin_start_time;

    /**
     * Page start time for performance tracking
     * @var float
     */
    private $page_start_time;

    /**
     * Whether telemetry is enabled
     * @var bool
     */
    private $telemetry_enabled = true;

    /**
     * Track sent errors to prevent duplicates
     * @var array
     */
    private $sent_errors = array();

    /**
     * Maximum number of errors to track in memory
     * @var int
     */
    private $max_tracked_errors = 100;

    /**
     * Cached memory limit to avoid repeated parsing
     * @var int|null
     */
    private static $cached_memory_limit = null;

    /**
     * Memory check counter for reduced frequency
     * @var int
     */
    private static $memory_check_counter = 0;

    /**
     * Singleton instance
     * @var Metasync_Telemetry_Manager
     */
    private static $instance = null;

    /**
     * Get singleton instance
     * 
     * @return Metasync_Telemetry_Manager
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton
     */
    private function __construct() {
        $this->plugin_start_time = microtime(true);
        $this->init_telemetry();
        #$this->setup_hooks();
    }

    /**
     * Initialize telemetry system
     */
    private function init_telemetry() {
        // Check if telemetry is enabled (allow opt-out)
        $this->telemetry_enabled = $this->is_telemetry_enabled();
        
        if (!$this->telemetry_enabled) {
            return;
        }

        try {
            $this->telemetry_collector = new Metasync_Sentry_Telemetry();

            // Initialize WordPress-native Sentry integration
            global $metasync_sentry_wordpress;
            $this->sentry_integration = $metasync_sentry_wordpress;

        } catch (Exception $e) {
            // Fallback if telemetry initialization fails
            // error_log('MetaSync: Telemetry initialization failed: ' . $e->getMessage());
            $this->telemetry_enabled = false;
        }
    }

    /**
     * Setup WordPress hooks
     */
    private function setup_hooks() {
        if (!$this->telemetry_enabled) {
            return;
        }

        // Plugin lifecycle hooks
        add_action('init', array($this, 'on_plugin_init'), 1);
        add_action('wp_loaded', array($this, 'on_wp_loaded'));
        add_action('shutdown', array($this, 'on_shutdown'));

        // Error handling hooks - removed global handlers to prevent capturing system-wide errors
        // Global error handlers are now managed by wordpress-error-handler.php with plugin-specific filtering

        // Performance monitoring hooks
        add_action('wp_head', array($this, 'start_page_timer'));
        add_action('wp_footer', array($this, 'end_page_timer'));

        // Database query monitoring (if SAVEQUERIES is enabled)
        if (defined('SAVEQUERIES') && constant('SAVEQUERIES')) {
            add_action('shutdown', array($this, 'analyze_db_queries'));
        }

        // Removed: Plugin activation/deactivation hooks - already handled by wordpress-error-handler.php
        // Removed: Queue processing - now using Sentry directly

        // Hook into existing log manager for integration
        add_action('metasync_log_preparation', array($this, 'on_log_preparation'));
    }

    /**
     * Check if telemetry is enabled
     * 
     * @return bool
     */
    private function is_telemetry_enabled() {
        // Allow users to opt out via wp-config.php
        if (defined('METASYNC_DISABLE_TELEMETRY') && constant('METASYNC_DISABLE_TELEMETRY')) {
            return false;
        }

        // Allow admin to disable via options
        $options = get_option('metasync_options', array());
        if (isset($options['disable_telemetry']) && $options['disable_telemetry']) {
            return false;
        }

        // Check if we're in development/testing environment
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_LOCAL_DEV') && constant('WP_LOCAL_DEV')) {
            return false;
        }

        return true;
    }

    /**
     * Handle plugin initialization
     */
    public function on_plugin_init() {
        if (!$this->telemetry_enabled) return;

        $this->send_message('Plugin initialized', 'debug', array(
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'plugin_version' => defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0'
        ));
    }

    /**
     * Handle WordPress loaded event
     */
    public function on_wp_loaded() {
        if (!$this->telemetry_enabled) return;

        $load_time = microtime(true) - $this->plugin_start_time;
        $this->send_performance('plugin_load', $load_time, array(
            'memory_usage' => memory_get_usage(true),
            'active_plugins' => count(get_option('active_plugins', array()))
        ));
    }

    /**
     * Handle shutdown event
     */
    public function on_shutdown() {
        if (!$this->telemetry_enabled) return;

        $total_time = microtime(true) - $this->plugin_start_time;
        $peak_memory = memory_get_peak_usage(true);

        // Send final performance metrics
        $this->send_performance('plugin_shutdown', $total_time, array(
            'peak_memory' => $peak_memory,
            'final_memory' => memory_get_usage(true)
        ), false); // Don't queue on shutdown
    }

    /**
     * Capture WordPress die events - REMOVED
     * This method was removed to prevent capturing system-wide wp_die events
     * Error handling is now managed by wordpress-error-handler.php with plugin-specific filtering
     */
    // public function capture_wp_die($message) - REMOVED TO PREVENT SYSTEM-WIDE ERROR CAPTURE

    /**
     * Capture PHP errors - REMOVED
     * This method was removed to prevent capturing system-wide PHP errors
     * Error handling is now managed by wordpress-error-handler.php with plugin-specific filtering
     */
    // public function capture_php_error($errno, $errstr, $errfile, $errline) - REMOVED TO PREVENT SYSTEM-WIDE ERROR CAPTURE

    /**
     * Capture uncaught exceptions - REMOVED
     * This method was removed to prevent capturing system-wide exceptions
     * Error handling is now managed by wordpress-error-handler.php with plugin-specific filtering
     */
    // public function capture_uncaught_exception($exception) - REMOVED TO PREVENT SYSTEM-WIDE ERROR CAPTURE

    /**
     * Start page load timer
     */
    public function start_page_timer() {
        if (!$this->telemetry_enabled) return;

        $this->page_start_time = microtime(true);
    }

    /**
     * End page load timer and send performance data
     */
    public function end_page_timer() {
        if (!$this->telemetry_enabled || !isset($this->page_start_time)) return;

        $page_load_time = microtime(true) - $this->page_start_time;
        
        $this->send_performance('page_load', $page_load_time, array(
            'is_admin' => is_admin(),
            'query_count' => get_num_queries(),
            'memory_usage' => memory_get_usage(true)
        ));
    }

    /**
     * Analyze database queries if SAVEQUERIES is enabled
     */
    public function analyze_db_queries() {
        if (!$this->telemetry_enabled) return;

        global $wpdb;
        if (!isset($wpdb->queries) || empty($wpdb->queries)) {
            return;
        }

        $slow_queries = array();
        $total_time = 0;

        foreach ($wpdb->queries as $query) {
            $query_time = $query[1];
            $total_time += $query_time;

            // Log slow queries (> 1 second)
            if ($query_time > 1.0) {
                $slow_queries[] = array(
                    'query' => $query[0],
                    'time' => $query_time,
                    'calling_function' => $query[2]
                );
            }
        }

        if (!empty($slow_queries)) {
            $this->send_message('Slow database queries detected', 'warning', array(
                'slow_query_count' => count($slow_queries),
                'total_queries' => count($wpdb->queries),
                'total_time' => $total_time,
                'slow_queries' => array_slice($slow_queries, 0, 5) // Limit to first 5
            ));
        }
    }

    /**
     * REMOVED: Plugin activation/deactivation tracking
     * These are now handled automatically by MetaSync_WordPress_Error_Handler
     * in wordpress-error-handler.php (lines 98-99, 198-224)
     */

    /**
     * Handle log preparation event
     */
    public function on_log_preparation() {
        if (!$this->telemetry_enabled) return;

        $this->send_message('Log preparation started', 'debug', array(
            'event_type' => 'log_preparation'
        ));
    }


    /**
     * Flush telemetry queue - REMOVED (now using Sentry directly)
     */
    public function flush_telemetry_queue() {
        // No longer needed - Sentry handles sending directly
    }

    /**
     * Send exception telemetry to Sentry
     *
     * @param Exception|Error|string $exception Exception to send
     * @param array $context Additional context
     * @param bool $use_queue Deprecated - kept for backward compatibility
     */
    public function send_exception($exception, $context = array(), $use_queue = true, $background = true) {
        if (!$this->telemetry_enabled) return;

        // Generate error fingerprint for deduplication
        $error_fingerprint = $this->generate_error_fingerprint('exception', $exception, $context);

        // Check if this error has already been sent
        if ($this->is_error_already_sent($error_fingerprint)) {
            return; // Skip sending duplicate error
        }

        // Check memory usage before sending telemetry
        if (!$this->is_memory_usage_safe(0.5)) {
            // error_log('MetaSync Telemetry: EMERGENCY - Disabling due to high memory usage');
            $this->telemetry_enabled = false; // Disable for this request
            return;
        }

        // Send directly to Sentry
        if ($this->sentry_integration) {
            $success = $this->sentry_integration->captureException($exception, $context);

            // Only mark as sent if the telemetry call was successful
            if ($success) {
                $this->mark_error_as_sent($error_fingerprint);
            }
        }
    }

    /**
     * Send message telemetry to Sentry
     *
     * @param string $message Message to send
     * @param string $level Log level
     * @param array $context Additional context
     * @param bool $use_queue Deprecated - kept for backward compatibility
     */
    public function send_message($message, $level = 'info', $context = array(), $use_queue = true) {
        if (!$this->telemetry_enabled) return;

        // Generate error fingerprint for deduplication (only for error level messages)
        $error_fingerprint = null;
        if (in_array($level, ['error', 'fatal', 'critical'])) {
            $error_fingerprint = $this->generate_error_fingerprint('message', $message, $context);

            // Check if this error has already been sent
            if ($this->is_error_already_sent($error_fingerprint)) {
                return; // Skip sending duplicate error
            }
        }

        // EMERGENCY MEMORY CHECK - Skip if memory usage is high
        if (!$this->is_memory_usage_safe(0.5)) {
             // NEW: Structured error logging with category and code
            if (class_exists('Metasync_Error_Logger')) {
                $memory_usage = memory_get_usage(true);
                $memory_limit = $this->get_cached_memory_limit();
                $memory_used_mb = round($memory_usage / 1024 / 1024, 2);
                $memory_limit_mb = round($memory_limit / 1024 / 1024, 2);
                $memory_percent = round(($memory_usage / $memory_limit) * 100, 1);
                
                Metasync_Error_Logger::log(
                    Metasync_Error_Logger::CATEGORY_MEMORY_EXHAUSTED,
                    Metasync_Error_Logger::SEVERITY_CRITICAL,
                    'Memory limit exceeded - emergency telemetry shutdown',
                    [
                        'memory_used_mb' => $memory_used_mb,
                        'memory_limit_mb' => $memory_limit_mb,
                        'memory_percent' => $memory_percent,
                        'threshold' => '50%',
                        'operation' => 'send_error',
                        'action' => 'telemetry_disabled',
                        'error_type' => $error_type ?? 'unknown'
                    ]
                );
            }
            
            // error_log('MetaSync Telemetry: EMERGENCY - Disabling due to high memory usage');
            $this->telemetry_enabled = false; // Disable for this request
            return;
        }

        // Send directly to Sentry
        if ($this->sentry_integration) {
            $success = $this->sentry_integration->captureMessage($message, $level, $context);

            // Only mark as sent if the telemetry call was successful and it's an error-level message
            if ($success && $error_fingerprint) {
                $this->mark_error_as_sent($error_fingerprint);
            }
        }
    }

    /**
     * Send activation telemetry to Sentry
     *
     * @param array $context Additional context
     */
    public function send_activation($context = array()) {
        if (!$this->telemetry_enabled) return;

        if ($this->sentry_integration && $this->telemetry_collector) {
            $telemetry_data = $this->telemetry_collector->capture_activation($context);
            $this->sentry_integration->captureMessage('Plugin activated', 'info', $context);
        }
    }

    /**
     * Send deactivation telemetry to Sentry
     *
     * @param array $context Additional context
     */
    public function send_deactivation($context = array()) {
        if (!$this->telemetry_enabled) return;

        if ($this->sentry_integration && $this->telemetry_collector) {
            $telemetry_data = $this->telemetry_collector->capture_deactivation($context);
            $this->sentry_integration->captureMessage('Plugin deactivated', 'info', $context);
        }
    }

    /**
     * Send performance telemetry to Sentry
     *
     * @param string $operation Operation name
     * @param float $duration Duration in seconds
     * @param array $context Additional context
     * @param bool $use_queue Deprecated - kept for backward compatibility
     */
    public function send_performance($operation, $duration, $context = array(), $use_queue = true) {
        if (!$this->telemetry_enabled) return;

        if ($this->sentry_integration && $this->telemetry_collector) {
            $telemetry_data = $this->telemetry_collector->capture_performance($operation, $duration, $context);
            $this->sentry_integration->captureMessage("Performance: {$operation}", 'info', array_merge($context, array(
                'duration' => $duration,
                'operation' => $operation
            )));
        }
    }

    /**
     * Test telemetry connection (Sentry)
     *
     * @return array Test results
     */
    public function test_telemetry_connection() {
        if (!$this->telemetry_enabled) {
            return array('success' => false, 'error' => 'Telemetry not enabled or not initialized');
        }

        return $this->test_sentry_connection();
    }

    /**
     * Get telemetry statistics
     *
     * @return array Telemetry stats
     */
    public function get_telemetry_stats() {
        if (!$this->telemetry_enabled) {
            return array('enabled' => false);
        }

        $stats = array();
        $stats['enabled'] = true;
        $stats['php_version'] = PHP_VERSION;
        $stats['plugin_version'] = defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0';
        $stats['sentry_enabled'] = function_exists('metasync_sentry_capture_exception');
        $stats['backend'] = 'sentry';

        return $stats;
    }

    /**
     * Test Sentry connection (now tests proxy connection)
     * 
     * @return array Test results
     */
    public function test_sentry_connection() {
        if (!$this->telemetry_enabled) {
            return array('success' => false, 'error' => 'Telemetry not enabled');
        }

        // Test the new proxy connection
        if ($this->sentry_integration && method_exists($this->sentry_integration, 'testProxyConnection')) {
            return $this->sentry_integration->testProxyConnection();
        }

        // Fallback to legacy test if available
        if (function_exists('metasync_sentry_test_connection')) {
            return metasync_sentry_test_connection();
        }

        return array('success' => false, 'error' => 'Sentry WordPress integration not available');
    }

    /**
     * Get cached memory limit to avoid repeated parsing
     * 
     * @return int Memory limit in bytes
     */
    private function get_cached_memory_limit() {
        if (self::$cached_memory_limit === null) {
            self::$cached_memory_limit = $this->parse_memory_limit(ini_get('memory_limit'));
        }
        return self::$cached_memory_limit;
    }

    /**
     * Check if memory check should be performed (reduced frequency)
     * 
     * @return bool True if memory should be checked
     */
    private function should_check_memory() {
        return (++self::$memory_check_counter % 10) === 0;
    }

    /**
     * Optimized memory usage check with caching
     * 
     * @param float $threshold Memory threshold (0.0 to 1.0)
     * @return bool True if memory usage is below threshold
     */
    private function is_memory_usage_safe($threshold = 0.5) {
        // Only check memory every 10th call to reduce overhead
        if (!$this->should_check_memory()) {
            return true; // Assume safe if not checking
        }

        $memory_usage = memory_get_usage(true);
        $memory_limit = $this->get_cached_memory_limit();
        
        return $memory_usage <= ($memory_limit * $threshold);
    }

    /**
     * Parse memory limit string to bytes
     * 
     * @param string $memory_limit Memory limit string (e.g., "256M", "1G")
     * @return int Memory limit in bytes
     */
    private function parse_memory_limit($memory_limit) {
        if ($memory_limit === '-1' || $memory_limit === -1) {
            return PHP_INT_MAX; // Unlimited
        }
        
        $memory_limit = trim($memory_limit);
        $last = strtolower($memory_limit[strlen($memory_limit) - 1]);
        $value = (int) $memory_limit;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
    
    /**
     * Generate a unique fingerprint for an error to detect duplicates
     * 
     * @param string $error_type Type of error
     * @param mixed $error_data Error data (exception, message, etc.)
     * @param array $context Error context
     * @return string Unique fingerprint
     */
    private function generate_error_fingerprint($error_type, $error_data, $context = array()) {
        // Create a fingerprint based on key error characteristics
        $fingerprint_data = array(
            'error_type' => $error_type,
            'message' => is_object($error_data) ? $error_data->getMessage() : (string)$error_data,
            'file' => $context['file'] ?? (is_object($error_data) ? $error_data->getFile() : ''),
            'line' => $context['line'] ?? (is_object($error_data) ? $error_data->getLine() : 0),
            'exception_class' => is_object($error_data) ? get_class($error_data) : '',
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
        return isset($this->sent_errors[$error_fingerprint]);
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
    }
    
    /**
     * Clean up old error entries to prevent memory bloat
     */
    private function cleanup_old_errors() {
        // If we have too many errors tracked, remove the oldest ones
        if (count($this->sent_errors) > $this->max_tracked_errors) {
            // Sort by timestamp (oldest first)
            asort($this->sent_errors);
            
            // Remove oldest entries, keeping only the most recent ones
            $errors_to_remove = count($this->sent_errors) - $this->max_tracked_errors;
            $this->sent_errors = array_slice($this->sent_errors, $errors_to_remove, null, true);
        }
    }
}

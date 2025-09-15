<?php
/**
 * Main Telemetry Manager Class
 * 
 * Handles telemetry system initialization and WordPress integration
 */
class Metasync_Telemetry_Manager {

    /**
     * Telemetry sender instance
     * @var Metasync_Telemetry_Sender
     */
    private $telemetry_sender;

    /**
     * Telemetry collector instance
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
        $this->setup_hooks();
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
            $this->telemetry_sender = new Metasync_Telemetry_Sender();
            
            // Initialize WordPress-native Sentry integration
            global $metasync_sentry_wordpress;
            $this->sentry_integration = $metasync_sentry_wordpress;
            
        } catch (Exception $e) {
            // Fallback if telemetry initialization fails
            error_log('MetaSync: Telemetry initialization failed: ' . $e->getMessage());
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

        // Error handling hooks
        add_action('wp_die_handler', array($this, 'capture_wp_die'));
        set_error_handler(array($this, 'capture_php_error'), E_ALL);
        set_exception_handler(array($this, 'capture_uncaught_exception'));

        // Performance monitoring hooks
        add_action('wp_head', array($this, 'start_page_timer'));
        add_action('wp_footer', array($this, 'end_page_timer'));

        // Database query monitoring (if SAVEQUERIES is enabled)
        if (defined('SAVEQUERIES') && SAVEQUERIES) {
            add_action('shutdown', array($this, 'analyze_db_queries'));
        }

        // Admin hooks for plugin management telemetry
        add_action('activated_plugin', array($this, 'on_plugin_activated'), 10, 2);
        add_action('deactivated_plugin', array($this, 'on_plugin_deactivated'), 10, 2);

        // Telemetry queue processing
        add_action('metasync_telemetry_queue_flush', array($this, 'flush_telemetry_queue'));

        // Hook into existing log manager for integration
        add_action('metasync_log_preparation', array($this, 'on_log_preparation'));
        add_action('metasync_log_upload', array($this, 'on_log_upload'));
    }

    /**
     * Check if telemetry is enabled
     * 
     * @return bool
     */
    private function is_telemetry_enabled() {
        // Allow users to opt out via wp-config.php
        if (defined('METASYNC_DISABLE_TELEMETRY') && METASYNC_DISABLE_TELEMETRY) {
            return false;
        }

        // Allow admin to disable via options
        $options = get_option('metasync_options', array());
        if (isset($options['disable_telemetry']) && $options['disable_telemetry']) {
            return false;
        }

        // Check if we're in development/testing environment
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_LOCAL_DEV') && WP_LOCAL_DEV) {
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
     * Capture WordPress die events
     * 
     * @param string $message Die message
     */
    public function capture_wp_die($message) {
        if (!$this->telemetry_enabled) return;

        $this->send_message('WordPress die event', 'error', array(
            'message' => is_string($message) ? $message : serialize($message),
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
        ), false);
    }

    /**
     * Capture PHP errors
     * 
     * @param int $errno Error number
     * @param string $errstr Error message
     * @param string $errfile Error file
     * @param int $errline Error line
     */
    public function capture_php_error($errno, $errstr, $errfile, $errline) {
        if (!$this->telemetry_enabled) return;

        // Only capture relevant errors
        if (!(error_reporting() & $errno)) {
            return;
        }

        // Skip if error is from outside our plugin
        if (strpos($errfile, plugin_dir_path(__FILE__)) === false) {
            return;
        }

        $error_types = array(
            E_ERROR => 'error',
            E_WARNING => 'warning',
            E_NOTICE => 'info',
            E_USER_ERROR => 'error',
            E_USER_WARNING => 'warning',
            E_USER_NOTICE => 'info'
        );

        $level = isset($error_types[$errno]) ? $error_types[$errno] : 'error';

        $this->send_message("PHP {$level}: {$errstr}", $level, array(
            'error_type' => $errno,
            'file' => $errfile,
            'line' => $errline,
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
        ));
    }

    /**
     * Capture uncaught exceptions
     * 
     * @param Exception $exception Uncaught exception
     */
    public function capture_uncaught_exception($exception) {
        if (!$this->telemetry_enabled) return;

        $this->send_exception($exception, array(
            'uncaught' => true,
            'fatal' => true
        ), false);
    }

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
     * Handle plugin activation
     * 
     * @param string $plugin Plugin file
     * @param bool $network_wide Network activation
     */
    public function on_plugin_activated($plugin, $network_wide) {
        if (!$this->telemetry_enabled) return;

        // Only track our own plugin activation
        if (strpos($plugin, 'metasync') === false) {
            return;
        }

        $this->send_activation(array(
            'plugin_file' => $plugin,
            'network_wide' => $network_wide,
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION
        ));
    }

    /**
     * Handle plugin deactivation
     * 
     * @param string $plugin Plugin file
     */
    public function on_plugin_deactivated($plugin) {
        if (!$this->telemetry_enabled) return;

        // Only track our own plugin deactivation
        if (strpos($plugin, 'metasync') === false) {
            return;
        }

        $this->send_deactivation(array(
            'plugin_file' => $plugin,
            'reason' => 'user_deactivated'
        ));
    }

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
     * Handle log upload event
     */
    public function on_log_upload() {
        if (!$this->telemetry_enabled) return;

        $this->send_message('Log upload initiated', 'debug', array(
            'event_type' => 'log_upload'
        ));
    }

    /**
     * Flush telemetry queue
     */
    public function flush_telemetry_queue() {
        if (!$this->telemetry_enabled || !$this->telemetry_sender) return;

        $results = $this->telemetry_sender->flush_queue();
        
        if ($results['processed'] > 0) {
            error_log("MetaSync Telemetry: Processed {$results['processed']} items. Success: {$results['success']}, Failed: {$results['failed']}");
        }
    }

    /**
     * Send exception telemetry
     * 
     * @param Exception|Error|string $exception Exception to send
     * @param array $context Additional context
     * @param bool $use_queue Whether to use queue
     */
    public function send_exception($exception, $context = array(), $use_queue = true, $background = true) {
        if (!$this->telemetry_enabled || !$this->telemetry_sender) return;

        // Check memory usage before sending telemetry
        $memory_usage = memory_get_usage(true);
        $memory_limit = $this->parse_memory_limit(ini_get('memory_limit'));
        
        // If memory usage is over 50% of limit, skip telemetry entirely
        if ($memory_usage > ($memory_limit * 0.5)) {
            error_log('MetaSync Telemetry: EMERGENCY - Disabling due to high memory usage');
            $this->telemetry_enabled = false; // Disable for this request
            return;
        }

        // Send to custom backend in background
        $this->telemetry_sender->send_exception($exception, $context, $use_queue, $background);
        
        // Skip Sentry to save memory and improve performance
    }

    /**
     * Send message telemetry
     * 
     * @param string $message Message to send
     * @param string $level Log level
     * @param array $context Additional context
     * @param bool $use_queue Whether to use queue
     */
    public function send_message($message, $level = 'info', $context = array(), $use_queue = true) {
        if (!$this->telemetry_enabled || !$this->telemetry_sender) return;

        // EMERGENCY MEMORY CHECK - Skip if memory usage is high
        $memory_usage = memory_get_usage(true);
        $memory_limit = $this->parse_memory_limit(ini_get('memory_limit'));
        
        // If memory usage is over 50% of limit, skip telemetry entirely
        if ($memory_usage > ($memory_limit * 0.5)) {
            error_log('MetaSync Telemetry: EMERGENCY - Disabling due to high memory usage');
            $this->telemetry_enabled = false; // Disable for this request
            return;
        }

        // Use background processing to prevent blocking the main thread
        $use_queue = true;
        $background = true;

        // Send to custom backend in background
        $this->telemetry_sender->send_message($message, $level, $context, $use_queue, $background);
        
        // Skip Sentry to save memory
    }

    /**
     * Send activation telemetry
     * 
     * @param array $context Additional context
     */
    public function send_activation($context = array()) {
        if (!$this->telemetry_enabled || !$this->telemetry_sender) return;

        $this->telemetry_sender->send_activation($context);
    }

    /**
     * Send deactivation telemetry
     * 
     * @param array $context Additional context
     */
    public function send_deactivation($context = array()) {
        if (!$this->telemetry_enabled || !$this->telemetry_sender) return;

        $this->telemetry_sender->send_deactivation($context);
    }

    /**
     * Send performance telemetry
     * 
     * @param string $operation Operation name
     * @param float $duration Duration in seconds
     * @param array $context Additional context
     * @param bool $use_queue Whether to use queue
     */
    public function send_performance($operation, $duration, $context = array(), $use_queue = true) {
        if (!$this->telemetry_enabled || !$this->telemetry_sender) return;

        $this->telemetry_sender->send_performance($operation, $duration, $context, $use_queue);
    }

    /**
     * Test telemetry connection
     * 
     * @return array Test results
     */
    public function test_telemetry_connection() {
        if (!$this->telemetry_enabled || !$this->telemetry_sender) {
            return array('success' => false, 'error' => 'Telemetry not enabled or not initialized');
        }

        return $this->telemetry_sender->test_connection();
    }

    /**
     * Get telemetry statistics
     * 
     * @return array Telemetry stats
     */
    public function get_telemetry_stats() {
        if (!$this->telemetry_enabled || !$this->telemetry_sender) {
            return array('enabled' => false);
        }

        $stats = $this->telemetry_sender->get_queue_stats();
        $stats['enabled'] = true;
        $stats['php_version'] = PHP_VERSION;
        $stats['plugin_version'] = defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0';
        $stats['sentry_enabled'] = function_exists('metasync_sentry_capture_exception');

        return $stats;
    }

    /**
     * Test Sentry connection
     * 
     * @return array Test results
     */
    public function test_sentry_connection() {
        if (!$this->telemetry_enabled) {
            return array('success' => false, 'error' => 'Telemetry not enabled');
        }

        if (function_exists('metasync_sentry_test_connection')) {
            return metasync_sentry_test_connection();
        }

        return array('success' => false, 'error' => 'Sentry WordPress integration not available');
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
}

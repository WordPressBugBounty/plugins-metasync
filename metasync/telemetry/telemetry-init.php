<?php
/**
 * Telemetry System Initialization
 *
 * Initializes the telemetry system and hooks into WordPress plugin lifecycle
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

// Include telemetry classes
require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'background-config.php';
require_once plugin_dir_path(__FILE__) . 'class-jwt-auth.php';
require_once plugin_dir_path(__FILE__) . 'class-sentry-telemetry.php';
require_once plugin_dir_path(__FILE__) . 'sentry-wordpress-integration.php';
require_once plugin_dir_path(__FILE__) . 'wordpress-error-handler.php';
require_once plugin_dir_path(__FILE__) . 'class-telemetry-sender.php';
require_once plugin_dir_path(__FILE__) . 'class-telemetry-manager.php';
require_once plugin_dir_path(__FILE__) . 'class-request_monitor.php';
require_once plugin_dir_path(__FILE__) . 'sentry-helper.php';


/**
 * Initialize telemetry system
 */
function init_metasync_telemetry() {
    // EMERGENCY MEMORY CHECK - Skip telemetry if memory is critically low
    $current_memory = memory_get_usage(true);
    $memory_limit_str = ini_get('memory_limit');
    
    // Parse memory limit manually since wp_convert_hr_to_bytes might not be available
    if ($memory_limit_str === '-1' || $memory_limit_str === -1) {
        $memory_limit = PHP_INT_MAX; // Unlimited
    } else {
        $memory_limit = trim($memory_limit_str);
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
        $memory_limit = $value;
    }
    
    // If we're using more than 70% of memory, skip telemetry entirely
    if ($current_memory > ($memory_limit * 0.7)) {
        error_log('MetaSync Telemetry: Disabled due to high memory usage (' . round($current_memory / 1024 / 1024, 2) . 'MB / ' . round($memory_limit / 1024 / 1024, 2) . 'MB)');
        return;
    }
    
    // Check if telemetry is explicitly disabled
    if (defined('METASYNC_DISABLE_TELEMETRY') && METASYNC_DISABLE_TELEMETRY) {
        return;
    }

    try {
        // Clean up old database options if they exist
        delete_option('metasync_sentry_dsn');
        delete_option('metasync_sentry_project_id'); 
        delete_option('metasync_sentry_environment');
        delete_option('metasync_sentry_release');
        delete_option('metasync_sentry_sample_rate');
        
        // Telemetry configuration is now handled by constants defined in metasync.php
        // METASYNC_SENTRY_PROJECT_ID, METASYNC_SENTRY_ENVIRONMENT, etc.

        // Only initialize if memory is still safe
        $memory_after_options = memory_get_usage(true);
        if ($memory_after_options > ($memory_limit * 0.8)) {
            error_log('MetaSync Telemetry: Skipping manager initialization due to memory usage');
            return;
        }

        Metasync_Telemetry_Manager::get_instance();

        // Initialize request monitoring for all pages (with memory checks)
        $memory_usage = memory_get_usage(true);
        $memory_limit_str = ini_get('memory_limit');
        
        // Parse memory limit manually
        if ($memory_limit_str === '-1' || $memory_limit_str === -1) {
            $memory_limit = PHP_INT_MAX;
        } else {
            $memory_limit = trim($memory_limit_str);
            $last = strtolower($memory_limit[strlen($memory_limit) - 1]);
            $value = (int) $memory_limit;
            
            switch ($last) {
                case 'g': $value *= 1024;
                case 'm': $value *= 1024;
                case 'k': $value *= 1024;
            }
            $memory_limit = $value;
        }
        
        // Only enable request monitoring if memory usage is reasonable
        if ($memory_usage < ($memory_limit * 0.6)) {
            $request_monitor = new Metasync_Telemetry_Request_Monitor();
            $request_monitor->monitor_api_calls();
        } else {
            error_log('MetaSync Telemetry: Request monitoring disabled due to high memory usage');
        }
        
        // Setup background processing hooks
        setup_metasync_background_processing();
        
    } catch (Exception $e) {
        // Fail silently to prevent site crashes
        error_log('MetaSync Telemetry: Initialization failed: ' . $e->getMessage());
    }
}

// Only initialize telemetry if not explicitly disabled and memory is available
if (!defined('METASYNC_DISABLE_TELEMETRY') || !METASYNC_DISABLE_TELEMETRY) {
    // Check memory before even adding the hook
    $current_memory = memory_get_usage(true);
    $memory_limit_str = ini_get('memory_limit');
    
    if ($memory_limit_str !== '-1') {
        $memory_limit = trim($memory_limit_str);
        $last = strtolower($memory_limit[strlen($memory_limit) - 1]);
        $value = (int) $memory_limit;
        
        switch ($last) {
            case 'g': $value *= 1024;
            case 'm': $value *= 1024;
            case 'k': $value *= 1024;
        }
        
        // Only add the hook if memory usage is below 60%
        if ($current_memory < ($value * 0.6)) {
            add_action('plugins_loaded', 'init_metasync_telemetry', 5);
        } else {
            error_log('MetaSync Telemetry: Skipping initialization due to high memory usage at startup');
        }
    } else {
        // Unlimited memory, safe to initialize
        add_action('plugins_loaded', 'init_metasync_telemetry', 5);
    }
}

/**
 * Helper function to get telemetry manager instance
 * 
 * @return Metasync_Telemetry_Manager
 */
function metasync_telemetry() {
    return Metasync_Telemetry_Manager::get_instance();
}

/**
 * Setup background processing hooks and handlers
 */
function setup_metasync_background_processing() {
    // WordPress Cron hook for background telemetry processing
    add_action('metasync_process_background_telemetry', array('Metasync_Telemetry_Sender', 'process_background_telemetry'));
    
    // AJAX handler for non-blocking requests
    add_action('wp_ajax_nopriv_metasync_background_telemetry', 'handle_metasync_background_telemetry');
    add_action('wp_ajax_metasync_background_telemetry', 'handle_metasync_background_telemetry');
    
    // Schedule file queue processing every 5 minutes
    if (!wp_next_scheduled('metasync_process_file_queue')) {
        wp_schedule_event(time(), 'hourly', 'metasync_process_file_queue');
    }
    add_action('metasync_process_file_queue', 'process_metasync_file_queue');
}

/**
 * Handle background telemetry via AJAX
 */
function handle_metasync_background_telemetry() {
    // Verify nonce for security
    if (!wp_verify_nonce($_POST['nonce'], 'metasync_bg_telemetry')) {
        wp_die('Security check failed');
    }
    
    // Decode telemetry data
    $telemetry_data = json_decode(base64_decode($_POST['telemetry_data']), true);
    
    if ($telemetry_data) {
        // Create sender and process immediately
        $sender = new Metasync_Telemetry_Sender();
        $sender->send_immediately($telemetry_data);
    }
    
    wp_die(); // Terminate AJAX request
}

/**
 * Process file-based telemetry queue
 */
function process_metasync_file_queue() {
    $sender = new Metasync_Telemetry_Sender();
    $sender->process_file_queue();
}

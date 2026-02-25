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

// Include telemetry classes (Sentry-only)
require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'class-sentry-telemetry.php';
require_once plugin_dir_path(__FILE__) . 'sentry-wordpress-integration.php';
require_once plugin_dir_path(__FILE__) . 'wordpress-error-handler.php';
require_once plugin_dir_path(__FILE__) . 'class-telemetry-manager.php';
require_once plugin_dir_path(__FILE__) . 'class-request_monitor.php';
require_once plugin_dir_path(__FILE__) . 'sentry-helper.php';


/**
 * Initialize telemetry system
 */
function init_metasync_telemetry() {
    // Optimized memory check with caching
    static $memory_check_done = false;
    static $memory_safe = true;
    
    // Only check memory once per request to reduce overhead
    if (!$memory_check_done) {
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
        $memory_safe = $current_memory <= ($memory_limit * 0.7);
        $memory_check_done = true;
        
        if (!$memory_safe) {
            // error_log('MetaSync Telemetry: Disabled due to high memory usage (' . round($current_memory / 1024 / 1024, 2) . 'MB / ' . round($memory_limit / 1024 / 1024, 2) . 'MB)');
            return;
        }
    } else if (!$memory_safe) {
        return; // Skip if memory was already determined to be unsafe
    }
    
    // Check if telemetry is explicitly disabled
    if (defined('METASYNC_DISABLE_TELEMETRY') && constant('METASYNC_DISABLE_TELEMETRY')) {
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
            // NEW: Structured error logging with category and code
            if (class_exists('Metasync_Error_Logger')) {
                $memory_used_mb = round($memory_after_options / 1024 / 1024, 2);
                $memory_limit_mb = round($memory_limit / 1024 / 1024, 2);
                $memory_percent = round(($memory_after_options / $memory_limit) * 100, 1);
                
                Metasync_Error_Logger::log(
                    Metasync_Error_Logger::CATEGORY_MEMORY_EXHAUSTED,
                    Metasync_Error_Logger::SEVERITY_CRITICAL,
                    'Memory limit nearly exhausted - telemetry initialization skipped',
                    [
                        'memory_used_mb' => $memory_used_mb,
                        'memory_limit_mb' => $memory_limit_mb,
                        'memory_percent' => $memory_percent,
                        'threshold' => '80%',
                        'operation' => 'telemetry_init',
                        'action' => 'skipped_initialization'
                    ]
                );
            }
            
            // error_log('MetaSync Telemetry: Skipping manager initialization due to memory usage');
            return;
        }

        Metasync_Telemetry_Manager::get_instance();

        // API request monitoring removed - creates excessive overhead
        // Class kept for backward compatibility but not instantiated
        // Already handled by log-sync.php
        
    } catch (Exception $e) {
        // Fail silently to prevent site crashes
        // error_log('MetaSync Telemetry: Initialization failed: ' . $e->getMessage());
    }
}

// Only initialize telemetry if not explicitly disabled
if (!defined('METASYNC_DISABLE_TELEMETRY') || !constant('METASYNC_DISABLE_TELEMETRY')) {
    // Add hook - memory check is now handled inside the function for efficiency
    add_action('plugins_loaded', 'init_metasync_telemetry', 5);
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
 * Cleanup scheduled cron jobs from old telemetry system
 */
function cleanup_metasync_legacy_cron_jobs() {
    // Remove old cron jobs
    $timestamp = wp_next_scheduled('metasync_process_background_telemetry');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'metasync_process_background_telemetry');
    }

    $timestamp = wp_next_scheduled('metasync_process_file_queue');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'metasync_process_file_queue');
    }

    $timestamp = wp_next_scheduled('metasync_telemetry_queue_flush');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'metasync_telemetry_queue_flush');
    }
}
add_action('init', 'cleanup_metasync_legacy_cron_jobs', 1);

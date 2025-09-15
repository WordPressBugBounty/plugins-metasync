<?php
/**
 * Background Processing Configuration
 * 
 * Configuration options for telemetry background processing
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Background processing configuration constants
 */
class Metasync_Background_Config {
    
    /**
     * Enable/disable background processing
     */
    const BACKGROUND_PROCESSING_ENABLED = true;
    
    /**
     * Preferred background processing method
     * Options: 'wp_cron', 'ajax', 'file_queue'
     */
    const PREFERRED_METHOD = 'wp_cron';
    
    /**
     * Maximum items to process in one batch
     */
    const MAX_BATCH_SIZE = 5;
    
    /**
     * File queue directory name (relative to uploads)
     */
    const QUEUE_DIR_NAME = 'metasync-telemetry-queue';
    
    /**
     * Transient expiry time for cron data (seconds)
     */
    const TRANSIENT_EXPIRY = 300; // 5 minutes
    
    /**
     * AJAX timeout for non-blocking requests (seconds)
     */
    const AJAX_TIMEOUT = 0.01;
    
    /**
     * File queue processing interval
     */
    const FILE_QUEUE_INTERVAL = 'hourly';
    
    /**
     * Check if background processing is enabled
     * 
     * @return bool
     */
    public static function is_background_enabled() {
        // Check for global disable constant
        if (defined('METASYNC_DISABLE_BACKGROUND_TELEMETRY') && METASYNC_DISABLE_BACKGROUND_TELEMETRY) {
            return false;
        }
        
        // Check plugin settings
        $settings = get_option('metasync_telemetry_settings', array());
        if (isset($settings['disable_background']) && $settings['disable_background']) {
            return false;
        }
        
        return self::BACKGROUND_PROCESSING_ENABLED;
    }
    
    /**
     * Get preferred processing method
     * 
     * @return string
     */
    public static function get_preferred_method() {
        $settings = get_option('metasync_telemetry_settings', array());
        return isset($settings['background_method']) ? $settings['background_method'] : self::PREFERRED_METHOD;
    }
    
    /**
     * Check if WordPress cron is available and working
     * 
     * @return bool
     */
    public static function is_wp_cron_available() {
        return function_exists('wp_schedule_single_event') && !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON;
    }
    
    /**
     * Check if AJAX method is available
     * 
     * @return bool
     */
    public static function is_ajax_available() {
        return function_exists('wp_remote_post') && function_exists('admin_url');
    }
    
    /**
     * Get optimal processing method based on environment
     * 
     * @return string
     */
    public static function get_optimal_method() {
        $preferred = self::get_preferred_method();
        
        // Check if preferred method is available
        switch ($preferred) {
            case 'wp_cron':
                if (self::is_wp_cron_available()) {
                    return 'wp_cron';
                }
                break;
            case 'ajax':
                if (self::is_ajax_available()) {
                    return 'ajax';
                }
                break;
            case 'file_queue':
                return 'file_queue'; // Always available
        }
        
        // Fallback to best available method
        if (self::is_wp_cron_available()) {
            return 'wp_cron';
        } elseif (self::is_ajax_available()) {
            return 'ajax';
        } else {
            return 'file_queue';
        }
    }
    
    /**
     * Get configuration array
     * 
     * @return array
     */
    public static function get_config() {
        return array(
            'enabled' => self::is_background_enabled(),
            'method' => self::get_optimal_method(),
            'max_batch_size' => self::MAX_BATCH_SIZE,
            'queue_dir' => self::QUEUE_DIR_NAME,
            'transient_expiry' => self::TRANSIENT_EXPIRY,
            'ajax_timeout' => self::AJAX_TIMEOUT,
            'file_queue_interval' => self::FILE_QUEUE_INTERVAL
        );
    }
}

<?php
/**
 * Enhanced Error Logger with Categories and Hooks
 *
 * Implements structured error categorization with business logic categories
 * for better integration with external monitoring tools.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 * @since      1.0.0
 * @author     Engineering Team <support@searchatlas.com>
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Enhanced Error Logger Class
 *
 * Provides structured error logging with:
 * - 8 business logic error categories
 * - Standardized error codes (MS-XXXX format)
 * - Structured log format with JSON context
 * - WordPress action hooks for external monitoring
 * - Error summary tracking in wp_options
 */
class Metasync_Error_Logger {
    
    /**
     * Error category constants
     */
    const CATEGORY_API_RATE_LIMIT = 'API_RATE_LIMIT';
    const CATEGORY_API_BACKOFF = 'API_BACKOFF';
    const CATEGORY_EXECUTION_TIMEOUT = 'EXECUTION_TIMEOUT';
    const CATEGORY_MEMORY_EXHAUSTED = 'MEMORY_EXHAUSTED';
    const CATEGORY_QUEUE_OVERFLOW = 'QUEUE_OVERFLOW';
    const CATEGORY_AUTHENTICATION_FAILURE = 'AUTHENTICATION_FAILURE';
    const CATEGORY_DATABASE_ERROR = 'DATABASE_ERROR';
    const CATEGORY_NETWORK_ERROR = 'NETWORK_ERROR';
    
    /**
     * Error codes mapping
     * Format: MS-XXXX
     */
    private static $error_codes = [
        self::CATEGORY_API_RATE_LIMIT => 'MS-1001',
        self::CATEGORY_API_BACKOFF => 'MS-1002',
        self::CATEGORY_EXECUTION_TIMEOUT => 'MS-2001',
        self::CATEGORY_MEMORY_EXHAUSTED => 'MS-2002',
        self::CATEGORY_QUEUE_OVERFLOW => 'MS-3001',
        self::CATEGORY_AUTHENTICATION_FAILURE => 'MS-4001',
        self::CATEGORY_DATABASE_ERROR => 'MS-5001',
        self::CATEGORY_NETWORK_ERROR => 'MS-6001',
    ];
    
    /**
     * Severity level constants
     */
    const SEVERITY_INFO = 'INFO';
    const SEVERITY_WARNING = 'WARNING';
    const SEVERITY_ERROR = 'ERROR';
    const SEVERITY_CRITICAL = 'CRITICAL';
    
    /**
     * Option name for error summary storage
     */
    const ERROR_SUMMARY_OPTION = 'metasync_error_summary';
    
    /**
     * Maximum number of unique errors to keep in summary
     */
    const MAX_SUMMARY_ENTRIES = 100;
    
    /**
     * Log file name
     */
    const LOG_FILE_NAME = 'metasync-errors.log';
    
    /**
     * Main logging function
     * 
     * Formats and writes structured error log with:
     * - Timestamp
     * - Category
     * - Severity
     * - Message
     * - JSON context
     * 
     * Also:
     * - Fires WordPress action hook
     * - Updates error summary in wp_options
     * 
     * @param string $category One of the CATEGORY_* constants
     * @param string $severity One of the SEVERITY_* constants
     * @param string $message Human-readable error message
     * @param array $context Additional context data (optional)
     * @return bool True on success, false on failure
     */
    public static function log($category, $severity, $message, $context = []) {
        // Validate inputs
        if (empty($category) || empty($severity) || empty($message)) {
            return false;
        }
        
        // Get error code for this category
        $error_code = self::$error_codes[$category] ?? 'MS-0000';
        
        // Prepare full context with error code
        $full_context = array_merge([
            'error_code' => $error_code
        ], $context);
        
        // Format timestamp
        $timestamp = date('Y-m-d H:i:s');
        
        // Format log line: [YYYY-MM-DD HH:MM:SS] [CATEGORY] [SEVERITY] Message {context_json}
        $log_line = sprintf(
            "[%s] [%s] [%s] %s %s\n",
            $timestamp,
            $category,
            $severity,
            $message,
            json_encode($full_context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        
        // Write to dedicated log file
        $log_written = self::write_to_log_file($log_line);
        
        // Fire WordPress action hook for external monitoring
        do_action('metasync_error_logged', $category, $error_code, $message, $full_context);
        
        // Update error summary in database
        self::update_error_summary($category, $error_code, $message, $full_context);
        
        return $log_written;
    }
    
    /**
     * Write log line to dedicated error log file
     * 
     * @param string $log_line Formatted log line
     * @return bool True on success, false on failure
     */
    private static function write_to_log_file($log_line) {
        // Get log directory (same as Log_Manager uses)
        $log_directory = WP_CONTENT_DIR . '/metasync_data';
        
        // Create directory if it doesn't exist
        if (!is_dir($log_directory)) {
            if (!@mkdir($log_directory, 0755, true)) {
                error_log('Metasync_Error_Logger: Failed to create log directory: ' . $log_directory);
                return false;
            }
        }
        
        // Verify directory is writable
        if (!is_writable($log_directory)) {
            @chmod($log_directory, 0755);
            if (!is_writable($log_directory)) {
                error_log('Metasync_Error_Logger: Directory not writable: ' . $log_directory);
                return false;
            }
        }
        
        // Get log file path
        $log_file = wp_normalize_path($log_directory . '/' . self::LOG_FILE_NAME);
        
        // Append to log file with file locking
        $result = @file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
        
        if ($result === false) {
            error_log('Metasync_Error_Logger: Failed to write to log file: ' . $log_file);
            return false;
        }
        
        // Set file permissions if file was just created
        if (!file_exists($log_file) || filesize($log_file) === strlen($log_line)) {
            @chmod($log_file, 0644);
        }
        
        return true;
    }
    
    /**
     * Update error summary in wp_options
     * 
     * Tracks last 100 unique errors with counts.
     * Unique key is based on category + first 50 chars of message.
     * 
     * @param string $category Error category
     * @param string $code Error code
     * @param string $message Error message
     * @param array $context Error context
     */
    private static function update_error_summary($category, $code, $message, $context) {
        // Get existing summary
        $summary = get_option(self::ERROR_SUMMARY_OPTION, []);
        
        if (!is_array($summary)) {
            $summary = [];
        }
        
        // Create unique key from category + first 50 chars of message
        $message_preview = substr($message, 0, 50);
        $key = $category . '|' . $message_preview;
        
        // Initialize entry if it doesn't exist
        if (!isset($summary[$key])) {
            $summary[$key] = [
                'category' => $category,
                'code' => $code,
                'message' => $message,
                'count' => 0,
                'first_seen' => current_time('mysql'),
                'last_seen' => current_time('mysql'),
                'severity' => isset($context['severity']) ? $context['severity'] : 'UNKNOWN'
            ];
        }
        
        // Update count and last seen time
        $summary[$key]['count']++;
        $summary[$key]['last_seen'] = current_time('mysql');
        
        // Keep only last 100 unique errors (prune oldest)
        if (count($summary) > self::MAX_SUMMARY_ENTRIES) {
            // Sort by last_seen (newest first)
            uasort($summary, function($a, $b) {
                return strtotime($b['last_seen']) - strtotime($a['last_seen']);
            });
            
            // Keep only the most recent entries
            $summary = array_slice($summary, 0, self::MAX_SUMMARY_ENTRIES, true);
        }
        
        // Save to database
        update_option(self::ERROR_SUMMARY_OPTION, $summary);
    }
    
    /**
     * Get error summary from wp_options
     * 
     * @return array Error summary array
     */
    public static function get_error_summary() {
        $summary = get_option(self::ERROR_SUMMARY_OPTION, []);
        return is_array($summary) ? $summary : [];
    }
    
    /**
     * Clear error summary
     * 
     * @return bool True on success
     */
    public static function clear_error_summary() {
        return delete_option(self::ERROR_SUMMARY_OPTION);
    }
    
    /**
     * Get error code for a category
     * 
     * @param string $category Error category
     * @return string Error code or 'MS-0000' if not found
     */
    public static function get_error_code($category) {
        return self::$error_codes[$category] ?? 'MS-0000';
    }
    
    /**
     * Get all error codes mapping
     * 
     * @return array Error codes array
     */
    public static function get_all_error_codes() {
            return self::$error_codes;
        }
    }


/**
 * Check Action Scheduler queue for overflow
 * This checks if pending actions exceed 1000 and logs QUEUE_OVERFLOW error
 * 
 * @param bool $force_check If true, bypasses transient throttling (for manual testing)
 * @return int|false Returns pending count if checked, false if skipped
 */
function metasync_check_action_scheduler_queue_overflow($force_check = false) {
    // Only check if Action Scheduler is available
    if (!class_exists('ActionScheduler_Store')) {
        return false;
    }
    
    // Only check if Error Logger is available
    if (!class_exists('Metasync_Error_Logger')) {
        return false;
    }
    
    // Throttle: Only check once per hour to avoid excessive logging (unless forced)
    if (!$force_check) {
        $transient_key = 'metasync_queue_overflow_check';
        $last_check = get_transient($transient_key);
        
        if ($last_check !== false) {
            return false; // Already checked recently
        }
    }
    
    try {
        $store = ActionScheduler_Store::instance();
        
        // Get count of pending actions
        $pending_count = (int) $store->query_actions([
            'status' => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 0, // We only need count
        ], 'count');
        
        // Check if queue overflow threshold is exceeded (>1000)
        if ($pending_count > 1000) {
            Metasync_Error_Logger::log(
                Metasync_Error_Logger::CATEGORY_QUEUE_OVERFLOW,
                Metasync_Error_Logger::SEVERITY_WARNING,
                'Action Scheduler queue overflow - too many pending actions',
                [
                    'pending_count' => $pending_count,
                    'threshold' => 1000,
                    'queue_system' => 'Action Scheduler',
                    'operation' => 'queue_processing'
                ]
            );
        }
        
        // Set transient to throttle future checks (1 hour) - only if not forced
        if (!$force_check) {
            $transient_key = 'metasync_queue_overflow_check';
            set_transient($transient_key, time(), HOUR_IN_SECONDS);
        }
        
        return $pending_count;
    } catch (Exception $e) {
        // Fail silently to prevent breaking queue processing
        // error_log('MetaSync: Failed to check queue overflow: ' . $e->getMessage());
        return false;
    }
}

// Hook into Action Scheduler before processing queue
add_action('action_scheduler_before_process_queue', 'metasync_check_action_scheduler_queue_overflow', 10, 0);

// Also check on shutdown (for immediate detection, throttled)
add_action('shutdown', function() {
    // Only check in admin or if triggered manually
    if (is_admin() || isset($_GET['metasync_check_queue'])) {
        metasync_check_action_scheduler_queue_overflow();
    }
}, 999);

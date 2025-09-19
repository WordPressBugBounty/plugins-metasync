<?php
/**
 * Telemetry Sender with Dash API Integration
 *
 * Handles sending telemetry data to dash-api-telemetry endpoint with JWT authentication
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
 * Telemetry Sender Class
 * 
 * Manages sending telemetry data to the backend endpoint with proper authentication
 */
class Metasync_Telemetry_Sender {

    /**
     * JWT Authentication instance
     * @var Metasync_JWT_Auth
     */
    private $jwt_auth;

    /**
     * Sentry Telemetry instance
     * @var Metasync_Sentry_Telemetry
     */
    private $telemetry_collector;

    /**
     * API endpoint base URL
     * @var string
     */
    private $api_endpoint;

    /**
     * Maximum retry attempts
     * @var int
     */
    private $max_retries = 3;

    /**
     * Queue for batching telemetry data
     * @var array
     */
    private $telemetry_queue = array();

    /**
     * Maximum queue size before auto-flush
     * @var int
     */
    private $max_queue_size = 2; // Drastically reduced to prevent memory exhaustion

    /**
     * Constructor
     * 
     * @param string $api_endpoint The API endpoint URL
     * @param string $jwt_secret JWT secret key
     */
    public function __construct($api_endpoint = '', $jwt_secret = '') {
        $this->api_endpoint = $api_endpoint ?: $this->get_default_endpoint();
        $this->jwt_auth = new Metasync_JWT_Auth($jwt_secret);
        $this->telemetry_collector = new Metasync_Sentry_Telemetry();
        
        // Schedule periodic queue flush
        $this->schedule_queue_flush();
    }

    /**
     * Get default API endpoint
     * 
     * @return string Default endpoint URL
     */
    private function get_default_endpoint() {
        $site_url = preg_replace('#^https?://#', '', home_url());
        return 'https://dash-api-telemetry.searchatlas.com/collect/' . $site_url;
    }

    /**
     * Send telemetry data immediately or in background
     * 
     * @param array $telemetry_data Telemetry data to send
     * @param bool $use_queue Whether to add to queue instead of sending immediately
     * @param bool $background Whether to process in background
     * @return bool|array Success status or response data
     */
    public function send_telemetry($telemetry_data, $use_queue = true, $background = true) {
        // Always use background processing for better performance
        if ($background) {
            return $this->send_to_background($telemetry_data);
        }
        
        if ($use_queue) {
            return $this->add_to_queue($telemetry_data);
        }

        return $this->send_immediately($telemetry_data);
    }

    /**
     * Send telemetry data immediately to the endpoint
     * 
     * @param array $telemetry_data Telemetry data to send
     * @return bool|array Success status or response data
     */
    public function send_immediately($telemetry_data) {
        $retry_count = 0;
        
        while ($retry_count < $this->max_retries) {
            $response = $this->make_api_request($telemetry_data);
            
            if ($response['success']) {
                $this->log_success($telemetry_data, $response);
                return $response;
            }
            
            $retry_count++;
            
            if ($retry_count < $this->max_retries) {
                // Exponential backoff: wait 2^retry_count seconds
                $wait_time = pow(2, $retry_count);
                sleep($wait_time);
            }
        }

        // Failed after all retries
        $this->log_failure($telemetry_data, $response);
        return false;
    }

    /**
     * Make API request to telemetry endpoint
     * 
     * @param array $telemetry_data Data to send
     * @return array Response data
     */
    private function make_api_request($telemetry_data) {
        $payload = array(
            'event_data' => $telemetry_data,
            'metadata' => array(
                'plugin_version' => defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0',
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'timestamp' => gmdate('c'),
                'site_hash' => $this->get_site_hash()
            )
        );

        $json_payload = wp_json_encode($payload);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'error' => 'JSON encoding error: ' . json_last_error_msg()
            );
        }

        // Prepare headers with JWT authentication
        $headers = array(
            'Content-Type' => 'application/json',
            'Authorization' => $this->jwt_auth->get_auth_header(array(
                'endpoint' => 'dash-api-telemetry',
                'action' => 'telemetry_collect'
            )),
            'X-Plugin-Version' => defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0',
            'X-Site-Hash' => $this->get_site_hash(),
            'User-Agent' => 'MetaSyncTelemetry/' . (defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0')
        );

        $args = array(
            'method' => 'POST',
            'headers' => $headers,
            'body' => $json_payload,
            'timeout' => 30,
            'sslverify' => true,
            'user-agent' => 'MetaSyncTelemetry/' . (defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0')
        );

        $response = wp_remote_post($this->api_endpoint, $args);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
                'error_code' => $response->get_error_code()
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code >= 200 && $response_code < 300) {
            return array(
                'success' => true,
                'response_code' => $response_code,
                'body' => $response_body
            );
        }

        return array(
            'success' => false,
            'response_code' => $response_code,
            'error' => $response_body ?: 'HTTP request failed'
        );
    }

    /**
     * Add telemetry data to queue for batch processing
     * 
     * @param array $telemetry_data Data to queue
     * @return bool Success status
     */
    public function add_to_queue($telemetry_data) {
        // EMERGENCY MEMORY CHECK - Skip if memory usage is high
        $memory_usage = memory_get_usage(true);
        $memory_limit = $this->parse_memory_limit(ini_get('memory_limit'));
        
        // If memory usage is over 60% of limit, skip entirely
        if ($memory_usage > ($memory_limit * 0.6)) {
            // error_log('MetaSync Telemetry: Skipping queue add due to high memory usage');
            return false;
        }
        
        // If queue is not empty, flush immediately to save memory
        if (!empty($this->telemetry_queue)) {
            $this->flush_queue();
        }

        $this->telemetry_queue[] = array(
            'data' => $telemetry_data,
            'timestamp' => time(),
            'attempts' => 0
        );

        // Auto-flush immediately if queue has any items
        if (count($this->telemetry_queue) >= $this->max_queue_size) {
            $this->flush_queue();
        }

        return true;
    }

    /**
     * Flush the telemetry queue
     * 
     * @return array Results of queue processing
     */
    public function flush_queue() {
        if (empty($this->telemetry_queue)) {
            return array('processed' => 0, 'success' => 0, 'failed' => 0);
        }

        // EMERGENCY: Process only one item at a time to prevent memory issues
        $results = array('processed' => 0, 'success' => 0, 'failed' => 0);
        $processed_items = array();
        
        // Process only the first item to minimize memory usage
        if (isset($this->telemetry_queue[0])) {
            $queued_item = $this->telemetry_queue[0];
            $result = $this->send_immediately($queued_item['data']);
            $results['processed']++;
            
            if ($result) {
                $results['success']++;
                $processed_items[] = 0;
            } else {
                $results['failed']++;
                // Remove failed items immediately to save memory
                $processed_items[] = 0;
            }
            
            // Remove processed item
            foreach ($processed_items as $index) {
                unset($this->telemetry_queue[$index]);
            }
            
            // Reindex array
            $this->telemetry_queue = array_values($this->telemetry_queue);
            
            // Force garbage collection
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        return $results;
    }

    /**
     * Schedule periodic queue flush
     */
    private function schedule_queue_flush() {
        if (!wp_next_scheduled('metasync_telemetry_queue_flush')) {
            wp_schedule_event(time(), 'hourly', 'metasync_telemetry_queue_flush');
        }
    }

    /**
     * Send exception telemetry
     * 
     * @param Exception|Error|string $exception Exception to send
     * @param array $context Additional context
     * @param bool $use_queue Whether to use queue
     * @return bool Success status
     */
    public function send_exception($exception, $context = array(), $use_queue = true, $background = true) {
        $telemetry_data = $this->telemetry_collector->capture_exception($exception, $context);
        return $this->send_telemetry($telemetry_data, $use_queue, $background);
    }

    /**
     * Send message telemetry
     * 
     * @param string $message Message to send
     * @param string $level Log level
     * @param array $context Additional context
     * @param bool $use_queue Whether to use queue
     * @return bool Success status
     */
    public function send_message($message, $level = 'info', $context = array(), $use_queue = true, $background = true) {
        $telemetry_data = $this->telemetry_collector->capture_message($message, $level, $context);
        return $this->send_telemetry($telemetry_data, $use_queue, $background);
    }

    /**
     * Send plugin activation telemetry
     * 
     * @param array $plugin_data Plugin information
     * @return bool Success status
     */
    public function send_activation($plugin_data = array()) {
        $telemetry_data = $this->telemetry_collector->capture_activation($plugin_data);
        return $this->send_telemetry($telemetry_data, false); // Send immediately for activation
    }

    /**
     * Send plugin deactivation telemetry
     * 
     * @param array $context Additional context
     * @return bool Success status
     */
    public function send_deactivation($context = array()) {
        $telemetry_data = $this->telemetry_collector->capture_deactivation($context);
        return $this->send_telemetry($telemetry_data, false); // Send immediately for deactivation
    }

    /**
     * Send performance telemetry
     * 
     * @param string $operation Operation name
     * @param float $duration Duration in seconds
     * @param array $context Additional context
     * @param bool $use_queue Whether to use queue
     * @return bool Success status
     */
    public function send_performance($operation, $duration, $context = array(), $use_queue = true, $background = true) {
        $telemetry_data = $this->telemetry_collector->capture_performance($operation, $duration, $context);
        return $this->send_telemetry($telemetry_data, $use_queue, $background);
    }

    /**
     * Get site hash for identification
     * 
     * @return string Site hash
     */
    private function get_site_hash() {
        $site_data = home_url() . get_bloginfo('name');
        return substr(md5($site_data), 0, 16);
    }

    /**
     * Log successful telemetry transmission
     * 
     * @param array $telemetry_data Sent data
     * @param array $response Response data
     */
    private function log_success($telemetry_data, $response) {
    }

    /**
     * Log failed telemetry transmission
     * 
     * @param array $telemetry_data Failed data
     * @param array $response Response data
     */
    private function log_failure($telemetry_data, $response) {
        $error_msg = 'MetaSync Telemetry: Failed to send telemetry data after ' . $this->max_retries . ' attempts.';
        if (isset($response['error'])) {
            $error_msg .= ' Error: ' . $response['error'];
        }
        // error_log($error_msg);
    }

    /**
     * Get queue statistics
     * 
     * @return array Queue statistics
     */
    public function get_queue_stats() {
        return array(
            'queue_size' => count($this->telemetry_queue),
            'max_queue_size' => $this->max_queue_size,
            'oldest_item' => !empty($this->telemetry_queue) ? min(array_column($this->telemetry_queue, 'timestamp')) : null,
            'newest_item' => !empty($this->telemetry_queue) ? max(array_column($this->telemetry_queue, 'timestamp')) : null
        );
    }

    /**
     * Clear the telemetry queue
     */
    public function clear_queue() {
        $this->telemetry_queue = array();
    }

    /**
     * Test the telemetry endpoint connection
     * 
     * @return array Test results
     */
    public function test_connection() {
        $test_data = $this->telemetry_collector->capture_message('Telemetry connection test', 'debug', array(
            'test' => true,
            'timestamp' => time()
        ));

        $response = $this->make_api_request($test_data);
        
        return array(
            'success' => $response['success'],
            'response_code' => isset($response['response_code']) ? $response['response_code'] : null,
            'error' => isset($response['error']) ? $response['error'] : null,
            'endpoint' => $this->api_endpoint,
            'jwt_valid' => !empty($this->jwt_auth->create_token())
        );
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
        if (empty($memory_limit)) {
            return 134217728; // Default 128MB
        }
        
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
     * Send telemetry data to background processing
     * 
     * @param array $telemetry_data Data to send in background
     * @return bool Success status
     */
    private function send_to_background($telemetry_data) {
        // Method 1: WordPress Cron (preferred)
        if ($this->schedule_background_send($telemetry_data)) {
            return true;
        }
        
        // Method 2: Non-blocking HTTP request
        if ($this->send_via_non_blocking_request($telemetry_data)) {
            return true;
        }
        
        // Method 3: File-based queue as fallback
        return $this->save_to_file_queue($telemetry_data);
    }

    /**
     * Schedule telemetry data to be sent via WordPress cron
     * 
     * @param array $telemetry_data Data to send
     * @return bool Success status
     */
    private function schedule_background_send($telemetry_data) {
        if (!function_exists('wp_schedule_single_event')) {
            return false;
        }

        // Sanitize data before storing in transient to prevent serialization errors
        $sanitized_data = $this->sanitize_for_serialization($telemetry_data);
        
        // Store data temporarily in transient
        $transient_key = 'metasync_telemetry_' . uniqid();
        set_transient($transient_key, $sanitized_data, 300); // 5 minutes expiry

        // Schedule immediate background processing
        $result = wp_schedule_single_event(time() + 1, 'metasync_process_background_telemetry', array($transient_key));
        
        return $result !== false;
    }

    /**
     * Send telemetry via non-blocking HTTP request
     * 
     * @param array $telemetry_data Data to send
     * @return bool Success status
     */
    private function send_via_non_blocking_request($telemetry_data) {
        // Create a loopback request to process telemetry
        $url = admin_url('admin-ajax.php');
        $data = array(
            'action' => 'metasync_background_telemetry',
            'telemetry_data' => base64_encode(json_encode($telemetry_data)),
            'nonce' => wp_create_nonce('metasync_bg_telemetry')
        );

        $args = array(
            'method' => 'POST',
            'timeout' => 0.01, // Very short timeout to make it non-blocking
            'blocking' => false, // Non-blocking request
            'body' => $data,
            'cookies' => array(),
            'sslverify' => false
        );

        $response = wp_remote_post($url, $args);
        return !is_wp_error($response);
    }

    /**
     * Save telemetry data to file-based queue
     * 
     * @param array $telemetry_data Data to save
     * @return bool Success status
     */
    private function save_to_file_queue($telemetry_data) {
        $upload_dir = wp_upload_dir();
        $queue_dir = $upload_dir['basedir'] . '/metasync-telemetry-queue';
        
        // Create directory if it doesn't exist
        if (!file_exists($queue_dir)) {
            wp_mkdir_p($queue_dir);
            // Add .htaccess to prevent direct access
            file_put_contents($queue_dir . '/.htaccess', 'Deny from all');
        }

        $filename = $queue_dir . '/telemetry_' . time() . '_' . uniqid() . '.json';
        $data = array(
            'timestamp' => time(),
            'data' => $telemetry_data
        );

        $result = file_put_contents($filename, json_encode($data), LOCK_EX);
        return $result !== false;
    }

    /**
     * Process background telemetry from WordPress cron
     * 
     * @param string $transient_key Transient key containing data
     */
    public static function process_background_telemetry($transient_key) {
        $telemetry_data = get_transient($transient_key);
        if ($telemetry_data === false) {
            return; // Data expired or not found
        }

        // Delete transient to prevent reprocessing
        delete_transient($transient_key);

        // Create sender instance and send immediately
        $sender = new self();
        $sender->send_immediately($telemetry_data);
    }

    /**
     * Process file-based queue
     */
    public function process_file_queue() {
        $upload_dir = wp_upload_dir();
        $queue_dir = $upload_dir['basedir'] . '/metasync-telemetry-queue';
        
        if (!file_exists($queue_dir)) {
            return;
        }

        $files = glob($queue_dir . '/telemetry_*.json');
        if (empty($files)) {
            return;
        }

        // Process up to 5 files at a time to prevent memory issues
        $files_to_process = array_slice($files, 0, 5);
        
        foreach ($files_to_process as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);
            
            if ($data && isset($data['data'])) {
                // Try to send the data
                $result = $this->send_immediately($data['data']);
                
                // Delete file regardless of success to prevent accumulation
                unlink($file);
            } else {
                // Invalid file, delete it
                unlink($file);
            }
        }
    }
    
    /**
     * Sanitize data for serialization to prevent errors
     * 
     * @param mixed $data Data to sanitize
     * @return mixed Sanitized data
     */
    private function sanitize_for_serialization($data) {
        if (is_array($data)) {
            $sanitized = array();
            foreach ($data as $key => $value) {
                $sanitized[$key] = $this->sanitize_for_serialization($value);
            }
            return $sanitized;
        } elseif (is_object($data)) {
            // Convert objects to arrays, but be careful with WordPress objects
            if (method_exists($data, 'to_array')) {
                return $this->sanitize_for_serialization($data->to_array());
            } elseif (method_exists($data, '__toString')) {
                return (string) $data;
            } else {
                // For complex objects, return a simplified representation
                return array(
                    'object_class' => get_class($data),
                    'object_id' => spl_object_id($data)
                );
            }
        } elseif (is_resource($data)) {
            // Resources cannot be serialized
            return '[resource]';
        } elseif (is_callable($data)) {
            // Callables cannot be serialized
            return '[callable]';
        } else {
            return $data;
        }
    }
}

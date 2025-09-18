<?php
/**
 * WordPress-Native Sentry Integration
 * 
 * Uses WordPress HTTP API to send data directly to Sentry
 * Compatible with PHP 7.1+ and WordPress 5.0+
 * 
 * This is the OFFICIAL way to use Sentry without Composer
 */
class MetaSync_Sentry_WordPress {
    
    private $dsn;
    private $options;
    private $environment;
    private $release;
    private $public_key;
    private $secret_key;
    private $project_id;
    private $host;
    private $scheme;
    
    public function __construct($dsn, $options = []) {
        $this->dsn = $dsn;
        $this->options = $options;
        
        // Use constants for configuration instead of detecting/parsing
        $this->environment = defined('METASYNC_SENTRY_ENVIRONMENT') ? METASYNC_SENTRY_ENVIRONMENT : $this->detectEnvironment();
        $this->release = defined('METASYNC_SENTRY_RELEASE') ? METASYNC_SENTRY_RELEASE : (defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0');
        
        // Handle proxy DSN format (proxy://project_id) or legacy DSN
        if (strpos($dsn, 'proxy://') === 0) {
            // New proxy format: proxy://project_id
            $this->project_id = str_replace('proxy://', '', $dsn);
            $this->public_key = null; // Not needed for proxy
            $this->secret_key = null; // Not needed for proxy
            $this->host = null; // Proxy handles this
            $this->scheme = 'proxy';
        } else {
            // Legacy DSN format for backward compatibility
            $parsed = parse_url($this->dsn);
            $this->public_key = isset($parsed['user']) ? $parsed['user'] : null;
            $this->secret_key = isset($parsed['pass']) ? $parsed['pass'] : null;
            $this->project_id = trim($parsed['path'], '/');
            $this->host = isset($parsed['host']) ? $parsed['host'] : null;
            $this->scheme = isset($parsed['scheme']) ? $parsed['scheme'] : 'https';
        }
    }
    
    /**
     * Capture an exception and send to Sentry
     */
    public function captureException($exception, $extra = []) {
        $data = $this->formatException($exception, $extra);
        return $this->sendToSentry($data);
    }
    
    /**
     * Capture a message and send to Sentry
     */
    public function captureMessage($message, $level = 'info', $extra = []) {
        $data = $this->formatMessage($message, $level, $extra);
        return $this->sendToSentry($data);
    }
    
    /**
     * Format exception data for Sentry API
     */
    private function formatException($exception, $extra = []) {
        $trace = [];
        if (is_object($exception) && method_exists($exception, 'getTrace')) {
            foreach ($exception->getTrace() as $frame) {
                $trace[] = [
                    'filename' => isset($frame['file']) ? $frame['file'] : '<unknown>',
                    'lineno' => isset($frame['line']) ? $frame['line'] : 0,
                    'function' => isset($frame['function']) ? $frame['function'] : '<unknown>',
                    'module' => isset($frame['class']) ? $frame['class'] : null,
                    'in_app' => $this->isInApp($frame)
                ];
            }
        }
        
        return [
            'event_id' => $this->generateEventId(),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => 'error',
            'platform' => 'php',
            'sdk' => [
                'name' => 'metasync-wordpress-sentry',
                'version' => $this->release
            ],
            'server_name' => $_SERVER['HTTP_HOST'] ?? 'unknown',
            'release' => $this->release,
            'environment' => $this->environment,
            'exception' => [
                'values' => [
                    [
                        'type' => is_object($exception) ? get_class($exception) : 'Error',
                        'value' => is_object($exception) ? $exception->getMessage() : (string)$exception,
                        'stacktrace' => ['frames' => array_reverse($trace)]
                    ]
                ]
            ],
            'tags' => $this->getTags(),
            'extra' => array_merge($this->getSystemContext(), $extra),
            'user' => $this->getUserContext(),
            'contexts' => $this->getContexts()
        ];
    }
    
    /**
     * Format message data for Sentry API
     */
    private function formatMessage($message, $level, $extra = []) {
        return [
            'event_id' => $this->generateEventId(),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $this->normalizeLevel($level),
            'platform' => 'php',
            'sdk' => [
                'name' => 'metasync-wordpress-sentry',
                'version' => $this->release
            ],
            'server_name' => $_SERVER['HTTP_HOST'] ?? 'unknown',
            'release' => $this->release,
            'environment' => $this->environment,
            'message' => [
                'message' => $message
            ],
            'tags' => $this->getTags(),
            'extra' => array_merge($this->getSystemContext(), $extra),
            'user' => $this->getUserContext(),
            'contexts' => $this->getContexts()
        ];
    }
    
    /**
     * Send data to Sentry API using WordPress HTTP functions (proxied through our system with JWT)
     */
    private function sendToSentry($data) {
        // Get JWT token for authentication
        if (!function_exists('metasync_get_jwt_token')) {
            return false;
        }
        
        try {
            $jwt_token = metasync_get_jwt_token();
        } catch (Exception $e) {
            return false;
        } catch (Error $e) {
            return false;
        }
        
        if (empty($jwt_token)) {
            return false;
        }
        
        // Use WordPress Sentry tunnel endpoint
        $url = 'https://wordpress.telemetry.staging.searchatlas.com/api/telemetry';
        
        // Convert Sentry data to envelope format
        $envelope = $this->createSentryEnvelope($data);
        
        $headers = [
            'Authorization' => 'Bearer ' . $jwt_token,
            'Content-Type' => 'application/x-sentry-envelope',
            'User-Agent' => 'WordPress MetaSync Plugin'
        ];
        
        // Use cURL directly to ensure proper envelope format
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $envelope);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_map(function($key, $value) {
            return $key . ': ' . $value;
        }, array_keys($headers), $headers));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout as requested
        curl_setopt($ch, CURLOPT_USERAGENT, 'WordPress MetaSync Plugin');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // 5 second connection timeout
        
        $response = curl_exec($ch);
        $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        // Return false silently on any error or timeout
        if ($error) {
            return false;
        }
        
        $success = $response_code >= 200 && $response_code < 300;
        
        return $success;
    }
    
    /**
     * Create Sentry envelope format from event data
     * 
     * @param array $data Sentry event data
     * @return string Envelope format string
     */
    private function createSentryEnvelope($data) {
        // Envelope header
        $envelope_header = [
            'event_id' => $data['event_id'] ?? null,
            'dsn' => $this->dsn,
            'sdk' => $data['sdk'] ?? null,
            'sent_at' => gmdate('c')
        ];
        
        // Item header  
        $item_header = [
            'type' => 'event',
            'content_type' => 'application/json'
        ];
        
        // Create envelope format: header\nitem_header\nitem_payload
        $envelope = wp_json_encode($envelope_header) . "\n";
        $envelope .= wp_json_encode($item_header) . "\n";
        $envelope .= wp_json_encode($data) . "\n";
        
        return $envelope;
    }
    
    /**
     * Test the Sentry proxy connection
     * 
     * @return array Test results
     */
    public function testProxyConnection() {
        $test_data = [
            'message' => [
                'message' => 'Sentry proxy connection test',
                'formatted' => 'Sentry proxy connection test'
            ],
            'level' => 'info',
            'timestamp' => gmdate('c'),
            'platform' => 'php',
            'sdk' => [
                'name' => 'metasync-telemetry-test',
                'version' => '1.0.0'
            ]
        ];
        
        $success = $this->sendToSentry($test_data);
        
        return [
            'success' => $success,
            'message' => $success ? 'Sentry tunnel connection successful' : 'Sentry tunnel connection failed',
            'endpoint' => 'https://wordpress.telemetry.staging.searchatlas.com/api/telemetry',
            'jwt_available' => function_exists('metasync_get_jwt_token') && !empty(metasync_get_jwt_token())
        ];
    }

    /**
     * Generate Sentry authentication header (legacy - now used for reference only)
     */
    private function getSentryAuthHeader() {
        $timestamp = time();
        $auth_parts = [
            'Sentry sentry_version=7',
            'sentry_client=metasync-wordpress/' . $this->release,
            'sentry_timestamp=' . $timestamp,
            'sentry_key=' . $this->public_key
        ];
        
        // Note: Modern Sentry DSNs only use public key, no secret key
        // The secret key is only used for server-side authentication
        if ($this->secret_key) {
            $auth_parts[] = 'sentry_secret=' . $this->secret_key;
        }
        
        return implode(', ', $auth_parts);
    }
    
    /**
     * Generate unique event ID
     */
    private function generateEventId() {
        return str_replace('-', '', wp_generate_uuid4());
    }
    
    /**
     * Get tags for the event
     */
    private function getTags() {
        return [
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'plugin_version' => $this->release,
            'environment' => $this->environment,
            'server_name' => $_SERVER['HTTP_HOST'] ?? 'unknown',
            'wp_url' => home_url(),
            'plugin_name' => 'metasync'
        ];
    }
    
    /**
     * Get system context information
     */
    private function getSystemContext() {
        global $wpdb;
        
        return [
            'wp_url' => home_url(),
            'plugin_version' => $this->release,
            'plugin_name' => 'Search Engine Labs SEO (MetaSync)',
            'wordpress_version' => get_bloginfo('version'),
            'site_title' => get_bloginfo('name'),
            'site_admin_email' => get_bloginfo('admin_email'),
            'php_version' => PHP_VERSION,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'active_plugins' => count(get_option('active_plugins', [])),
            'active_theme' => get_template(),
            'multisite' => is_multisite(),
            'mysql_version' => method_exists($wpdb, 'get_var') ? $wpdb->get_var('SELECT VERSION()') : 'unknown',
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ];
    }
    
    /**
     * Get contexts for Sentry
     */
    private function getContexts() {
        return [
            'runtime' => [
                'name' => 'php',
                'version' => PHP_VERSION
            ],
            'os' => [
                'name' => PHP_OS_FAMILY
            ],
            'app' => [
                'app_name' => 'MetaSync Plugin',
                'app_version' => $this->release
            ]
        ];
    }
    
    /**
     * Get user context (anonymized)
     */
    private function getUserContext() {
        return [
            'id' => substr(md5(home_url() . get_bloginfo('name')), 0, 16),
            'ip_address' => '{{auto}}' // Let Sentry handle IP detection and anonymization
        ];
    }
    
    /**
     * Detect current environment
     */
    private function detectEnvironment() {
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return 'development';
        }
        
        $host = parse_url(home_url(), PHP_URL_HOST);
        if (strpos($host, 'staging') !== false || strpos($host, 'dev') !== false) {
            return 'staging';
        }
        
        return 'production';
    }
    
    /**
     * Normalize log level for Sentry
     */
    private function normalizeLevel($level) {
        $levels = ['debug', 'info', 'warning', 'error', 'fatal'];
        return in_array($level, $levels) ? $level : 'info';
    }
    
    /**
     * Check if stack frame is in application code
     */
    private function isInApp($frame) {
        if (!isset($frame['file'])) {
            return false;
        }
        
        $wp_content_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
        return strpos($frame['file'], $wp_content_dir) !== false;
    }
    
    /**
     * Test the connection to Sentry
     */
    public function testConnection() {
        $test_data = $this->formatMessage('🧪 Sentry connection test', 'info', [
            'test' => true,
            'timestamp' => time(),
            'source' => 'connection_test'
        ]);
        
        $success = $this->sendToSentry($test_data);
        
        return [
            'success' => $success,
            'dsn_configured' => !empty($this->dsn),
            'project_id' => $this->project_id,
            'environment' => $this->environment,
            'release' => $this->release
        ];
    }
}

/**
 * Global Sentry instance
 */
global $metasync_sentry_wordpress;
$metasync_sentry_wordpress = null;

/**
 * Initialize Sentry with DSN configuration
 */
function init_metasync_sentry_wordpress() {
    global $metasync_sentry_wordpress;
    
    $dsn = '';
    
    // Use constants defined in metasync.php for configuration
    if (defined('METASYNC_SENTRY_PROJECT_ID')) {
        // Create proxy DSN format using the project ID constant
        $dsn = 'proxy://' . METASYNC_SENTRY_PROJECT_ID;
    } else {
        // Fallback: Check wp-config.php for custom DSN (for developers)
        if (defined('METASYNC_SENTRY_DSN')) {
            $dsn = METASYNC_SENTRY_DSN;
        }
    }
    
    if (!empty($dsn)) {
        try {
            $metasync_sentry_wordpress = new MetaSync_Sentry_WordPress($dsn);
            return $metasync_sentry_wordpress;
        } catch (Exception $e) {
            return null;
        }
    } else {
        return null;
    }
}

/**
 * Helper function to capture exceptions
 */
function metasync_sentry_capture_exception($exception, $extra = []) {
    global $metasync_sentry_wordpress;
    if (!$metasync_sentry_wordpress) {
        $metasync_sentry_wordpress = init_metasync_sentry_wordpress();
    }
    
    if ($metasync_sentry_wordpress) {
        return $metasync_sentry_wordpress->captureException($exception, $extra);
    }
    return false;
}

/**
 * Helper function to capture messages
 */
function metasync_sentry_capture_message($message, $level = 'info', $extra = []) {
    global $metasync_sentry_wordpress;
    if (!$metasync_sentry_wordpress) {
        $metasync_sentry_wordpress = init_metasync_sentry_wordpress();
    }
    
    if ($metasync_sentry_wordpress) {
        return $metasync_sentry_wordpress->captureMessage($message, $level, $extra);
    }
    return false;
}

/**
 * Test Sentry connection
 */
function metasync_sentry_test_connection() {
    global $metasync_sentry_wordpress;
    if (!$metasync_sentry_wordpress) {
        $metasync_sentry_wordpress = init_metasync_sentry_wordpress();
    }
    
    if ($metasync_sentry_wordpress) {
        // Use the new proxy test method if available
        if (method_exists($metasync_sentry_wordpress, 'testProxyConnection')) {
            return $metasync_sentry_wordpress->testProxyConnection();
        }
        // Fallback to legacy method
        return $metasync_sentry_wordpress->testConnection();
    }
    
    return [
        'success' => false,
        'error' => 'Sentry not initialized. Check DSN configuration.'
    ];
}

// Auto-initialize when file is loaded
init_metasync_sentry_wordpress();
?>

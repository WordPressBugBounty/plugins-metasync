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
        $this->environment = $this->detectEnvironment();
        $this->release = defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0';
        
        // Parse DSN to get project details
        $parsed = parse_url($this->dsn);
        $this->public_key = $parsed['user'];
        $this->secret_key = isset($parsed['pass']) ? $parsed['pass'] : null;
        $this->project_id = trim($parsed['path'], '/');
        $this->host = $parsed['host'];
        $this->scheme = $parsed['scheme'];
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
     * Send data to Sentry API using WordPress HTTP functions
     */
    private function sendToSentry($data) {
        $url = sprintf(
            '%s://%s/api/%s/store/',
            $this->scheme,
            $this->host,
            $this->project_id
        );
        
        $headers = [
            'Content-Type' => 'application/json',
            'X-Sentry-Auth' => $this->getSentryAuthHeader(),
            'User-Agent' => 'metasync-wordpress/' . $this->release
        ];
        
        $args = [
            'method' => 'POST',
            'headers' => $headers,
            'body' => wp_json_encode($data),
            'timeout' => 10,
            'sslverify' => true,
            'blocking' => true,
            'data_format' => 'body'
        ];
        
        // Add debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Sending to Sentry: ' . $url);
            error_log('Sentry Data: ' . wp_json_encode($data));
        }
        
        // Use cURL directly to ensure proper JSON content type
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, wp_json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_map(function($key, $value) {
            return $key . ': ' . $value;
        }, array_keys($headers), $headers));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'metasync-wordpress/' . $this->release);
        
        $response = curl_exec($ch);
        $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log('Sentry cURL Error: ' . $error);
            return false;
        }
        
        $success = $response_code >= 200 && $response_code < 300;
        
        if ($success) {
            error_log('✅ Sentry: Event sent successfully. Response: ' . $response_code);
        } else {
            error_log('❌ Sentry API Error: HTTP ' . $response_code . ' - ' . $response);
        }
        
        return $success;
    }
    
    /**
     * Generate Sentry authentication header
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
    
    // Check wp-config.php first (most secure) - for developers
    if (defined('METASYNC_SENTRY_DSN')) {
        $dsn = METASYNC_SENTRY_DSN;
    } else {
        // Check plugin options - for regular users
        $sentry_dsn = get_option('metasync_sentry_dsn', '');
        if (!empty($sentry_dsn)) {
            $dsn = $sentry_dsn;
        }
    }
    
    if (!empty($dsn)) {
        try {
            $metasync_sentry_wordpress = new MetaSync_Sentry_WordPress($dsn);
            error_log('✅ Sentry WordPress integration initialized with DSN');
            return $metasync_sentry_wordpress;
        } catch (Exception $e) {
            error_log('❌ Failed to initialize Sentry: ' . $e->getMessage());
            return null;
        }
    } else {
        error_log('⚠️ Sentry DSN not configured. Add METASYNC_SENTRY_DSN to wp-config.php');
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

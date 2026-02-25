<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}

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
        $result = $this->sendToSentry($data);
        return is_array($result) ? $result['success'] : $result;
    }
    
    /**
     * Capture a message and send to Sentry
     */
    public function captureMessage($message, $level = 'info', $extra = [], $attachment = null) {
        $data = $this->formatMessage($message, $level, $extra);
        $result = $this->sendToSentry($data, 'event', $attachment);
        return is_array($result) ? $result['success'] : $result;
    }
    
    /**
     * Capture user feedback and send to Sentry
     * 
     * Since Sentry requires user feedback to be associated with an event,
     * this method first creates an event with the feedback message, then
     * associates the feedback with that event.
     * 
     * @param array $feedback Feedback data with keys: name (optional), email (optional), message (required), event_id (optional), severity (optional)
     * @param array|null $attachment Optional attachment data
     * @return bool|array Success status, or array with success and event_id
     */
    public function captureFeedback($feedback, $attachment = null) {
        // Get the feedback message
        $message = '';
        if (isset($feedback['message']) && !empty($feedback['message'])) {
            $message = $feedback['message'];
        } elseif (isset($feedback['comments']) && !empty($feedback['comments'])) {
            $message = $feedback['comments'];
        }
        
        if (empty($message)) {
            return false;
        }
        
        // Get severity level if provided
        $severity = isset($feedback['severity']) ? sanitize_text_field($feedback['severity']) : '';
        $valid_severity_levels = array('info', 'warning', 'error', 'fatal');
        
        // Get otto_pixel_uuid from general options (same way as used in admin handler)
        $general_options = class_exists('Metasync') ? Metasync::get_option('general') : get_option('metasync_options', [])['general'] ?? [];
        if (!is_array($general_options)) {
            $general_options = [];
        }
        $project_uuid = isset($general_options['otto_pixel_uuid']) ? sanitize_text_field($general_options['otto_pixel_uuid']) : '';
        
        // Format event title as "Client Report {UUID}"
        $event_title = !empty($project_uuid) ? 'Client Report ' . $project_uuid : 'Client Report (UUID Not Configured)';
        
        // Build the message with title, severity, and user description
        // Format: "Client Report {uuid}\nSeverity: {level}\n\n{user description}"
        $formatted_message = $event_title;
        if (!empty($severity) && in_array($severity, $valid_severity_levels, true)) {
            $severity_label = ucfirst($severity);
            $formatted_message .= "\nSeverity: {$severity_label}";
        }

        // Add attachment indicator if an attachment is present
        if ($attachment && !empty($attachment['filename'])) {
            $formatted_message .= "\nAttachment: " . $attachment['filename'];
        }

        $formatted_message .= "\n\n" . $message;
        
        // If event_id is already provided, use it directly
        if (isset($feedback['event_id']) && !empty($feedback['event_id'])) {
            // Update the feedback message with formatted message (title + severity + description)
            $feedback['message'] = $formatted_message;
            $data = $this->formatFeedback($feedback);
            $result = $this->sendToSentry($data, 'user_report', $attachment);
            return is_array($result) ? $result['success'] : $result;
        }
        
        // Determine event level based on severity
        $event_level = !empty($severity) && in_array($severity, $valid_severity_levels, true) ? $severity : 'info';
        
        // Create an event with the formatted message (title + severity + user description)
        // The title will also be set in culprit field for Sentry to display
        $event_data = $this->formatMessage($formatted_message, $event_level, [
            'feedback_source' => 'user_report',
            'original_feedback' => $feedback,
            'report_title' => $event_title
        ]);
        
        // Set the culprit (title) field for Sentry to display as the event title
        // The culprit field is what Sentry uses to show the title in the issues list
        $event_data['culprit'] = $event_title;
        
        // Add the category tag
        if (isset($event_data['tags']) && is_array($event_data['tags'])) {
            $event_data['tags']['category'] = 'user-feedback';
        } else {
            $event_data['tags'] = ['category' => 'user-feedback'];
        }
        
        // Send the event first with attachment
        $event_result = $this->sendToSentry($event_data, 'event', $attachment);
        
        // Extract event_id from response
        $event_id = null;
        if (is_array($event_result) && isset($event_result['event_id'])) {
            $event_id = $event_result['event_id'];
        } elseif (is_array($event_result) && $event_result['success']) {
            // If event was sent but no event_id in response, use the one we generated
            $event_id = $event_data['event_id'] ?? null;
        }
        
        // If event creation failed, return false
        if (!$event_id) {
            return false;
        }
        
        // Now send the feedback with the event_id (no attachment on feedback, it's already on the event)
        // Make sure the feedback message includes title, severity, and user description
        $feedback['event_id'] = $event_id;
        $feedback['message'] = $formatted_message; // Use the formatted message (title + severity + description)
        $data = $this->formatFeedback($feedback);
        $feedback_result = $this->sendToSentry($data, 'user_report');
        
        return is_array($feedback_result) ? $feedback_result['success'] : $feedback_result;
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
     * Format user feedback data for Sentry User Feedback API
     * 
     * @param array $feedback Feedback data with keys: name, email, message (or comments), event_id
     * @return array Formatted feedback data
     */
    private function formatFeedback($feedback) {
        // Support both 'message' (JavaScript SDK API) and 'comments' (envelope format)
        // The JavaScript SDK accepts 'message' but converts it to 'comments' in the envelope
        $comments = '';
        if (isset($feedback['message']) && !empty($feedback['message'])) {
            $comments = $feedback['message'];
        } elseif (isset($feedback['comments']) && !empty($feedback['comments'])) {
            $comments = $feedback['comments'];
        }
        
        // Validate required fields
        if (empty($comments)) {
            throw new InvalidArgumentException('Message/comments field is required for user feedback');
        }
        
        // Build feedback payload according to Sentry User Feedback API envelope format
        // Reference: https://docs.sentry.io/platforms/javascript/user-feedback/#user-feedback-api
        // IMPORTANT: The envelope format uses 'comments' as the key (not 'message')
        // The JavaScript SDK accepts 'message' but converts it to 'comments' internally
        $feedback_data = [
            'comments' => sanitize_textarea_field($comments)
        ];
        
        // Add optional name field
        if (isset($feedback['name']) && !empty(trim($feedback['name']))) {
            $feedback_data['name'] = sanitize_text_field($feedback['name']);
        }
        
        // Add optional email field
        if (isset($feedback['email']) && !empty(trim($feedback['email']))) {
            $feedback_data['email'] = sanitize_email($feedback['email']);
        }
        
        // Add event_id if provided (to associate feedback with an event)
        // Note: In envelope format, event_id can be in the payload
        if (isset($feedback['event_id']) && !empty($feedback['event_id'])) {
            $feedback_data['event_id'] = sanitize_text_field($feedback['event_id']);
        }
        
        return $feedback_data;
    }
    
    /**
     * Check if the current environment is localhost/development
     */
    private function isLocalhost() {
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
     * Send data to Sentry API using WordPress HTTP functions (proxied through our system with JWT)
     * 
     * @param array $data Sentry event or feedback data
     * @param string $item_type Type of item: 'event' or 'user_report'
     * @param array|null $attachment Optional attachment data
     * @return bool|array Success status, or array with success and event_id
     */
    private function sendToSentry($data, $item_type = 'event', $attachment = null) {
        // Skip sending to Sentry if running on localhost/development environment
        if ($this->isLocalhost()) {
            return false;
        }
        
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
        $url = 'https://wordpress.telemetry.infra.searchatlas.com/api/4509950439849985/envelope/';

        // Convert Sentry data to envelope format, including attachment if present
        $envelope = $this->createSentryEnvelope($data, $item_type, $attachment);

        $plugin_version = defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0';

        $headers = [
            'Authorization' => 'Bearer ' . $jwt_token,
            'Content-Type' => 'application/x-sentry-envelope',
            'X-Plugin-Version' => $plugin_version,
            'User-Agent' => 'WordPress MetaSync Plugin/' . $plugin_version
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
        curl_setopt($ch, CURLOPT_USERAGENT, 'WordPress MetaSync Plugin/' . $plugin_version);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // 5 second connection timeout
        
        $response = curl_exec($ch);
        $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        #curl_close($ch);

        // Log errors in debug mode for troubleshooting
        if (defined('WP_DEBUG') && WP_DEBUG && WP_DEBUG_LOG) {
            if ($error) {
                error_log(sprintf(
                    'MetaSync Sentry Error (%s): %s | Response Code: %s | Item Type: %s',
                    $item_type,
                    $error,
                    $response_code,
                    $item_type
                ));
            } elseif ($response_code < 200 || $response_code >= 300) {
                error_log(sprintf(
                    'MetaSync Sentry HTTP Error (%s): Response Code: %s | Response: %s | Item Type: %s',
                    $item_type,
                    $response_code,
                    substr($response, 0, 200),
                    $item_type
                ));
            }
        }

        // Return false silently on any error or timeout
        if ($error) {
            return false;
        }

        $success = $response_code >= 200 && $response_code < 300;
        
        // Parse response to extract event_id if available
        $event_id = null;
        if ($success && !empty($response)) {
            $response_data = json_decode($response, true);
            if (is_array($response_data) && isset($response_data['id'])) {
                $event_id = $response_data['id'];
            }
        }
        
        // Return array with success status and event_id if available
        return [
            'success' => $success,
            'event_id' => $event_id
        ];
    }
    
    /**
     * Create Sentry envelope format from event or feedback data
     * 
     * @param array $data Sentry event or feedback data
     * @param string $item_type Type of item: 'event' or 'user_report'
     * @param array|null $attachment Optional attachment data
     * @return string Envelope format string
     */
    private function createSentryEnvelope($data, $item_type = 'event', $attachment = null) {
        // Envelope header
        // Note: When sending to the envelope endpoint directly, we don't include DSN
        // The project ID is already in the URL path
        $envelope_header = [
            'sent_at' => gmdate('c')
        ];
        
        // Add event_id to header if present (for events) or if it's in feedback data
        if ($item_type === 'event' && isset($data['event_id'])) {
            $envelope_header['event_id'] = $data['event_id'];
        } elseif ($item_type === 'user_report' && isset($data['event_id'])) {
            // For user feedback, event_id is in the payload, not header
            // But we can still include it in header if needed
        }

        // Item header - determine type based on parameter
        $item_header = [
            'type' => $item_type
        ];

        // Create envelope format: header\nitem_header\nitem_payload
        $envelope = wp_json_encode($envelope_header) . "\n";
        $envelope .= wp_json_encode($item_header) . "\n";
        $envelope .= wp_json_encode($data) . "\n";

        // Add attachment if present
        if ($attachment && isset($attachment['data']) && isset($attachment['filename'])) {
            // Attachment item header
            $attachment_header = [
                'type' => 'attachment',
                'length' => strlen($attachment['data']),
                'filename' => $attachment['filename'],
                'content_type' => $attachment['content_type'] ?? 'application/octet-stream'
            ];

            // Add attachment to envelope
            $envelope .= wp_json_encode($attachment_header) . "\n";
            $envelope .= $attachment['data'] . "\n";
        }

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
        
        $result = $this->sendToSentry($test_data);
        $success = is_array($result) ? $result['success'] : $result;
        
        return [
            'success' => $success,
            'message' => $success ? 'Sentry tunnel connection successful' : 'Sentry tunnel connection failed',
            'endpoint' => 'https://wordpress.telemetry.infra.searchatlas.com/api/4509950439849985/envelope/',
            'jwt_available' => function_exists('metasync_get_jwt_token') && !empty(metasync_get_jwt_token())
        ];
    }
    
    /**
     * Test user feedback submission
     * 
     * @return array Test results
     */
    public function testUserFeedback() {
        $test_feedback = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'message' => '🧪 Test user feedback submission - ' . gmdate('Y-m-d H:i:s') . ' - This is a test to verify the User Feedback API is working correctly.'
        ];
        
        $success = $this->captureFeedback($test_feedback);
        
        return [
            'success' => $success,
            'message' => $success ? 'User feedback test sent successfully' : 'User feedback test failed',
            'endpoint' => 'https://wordpress.telemetry.infra.searchatlas.com/api/4509950439849985/envelope/',
            'item_type' => 'user_report',
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
     * Get cached tags for the event (optimized)
     */
    private function getTags() {
        // Cache static tags
        $cache_key = 'metasync_sentry_tags';
        $cached_tags = wp_cache_get($cache_key, 'metasync');
        
        if ($cached_tags !== false) {
            // Add dynamic tags that change per request
            $cached_tags['server_name'] = $_SERVER['HTTP_HOST'] ?? 'unknown';
            return $cached_tags;
        }
        
        $tags = [
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'plugin_version' => $this->release,
            'environment' => $this->environment,
            'wp_url' => home_url(),
            'plugin_name' => 'metasync',
            // Dynamic tag added per request
            'server_name' => $_SERVER['HTTP_HOST'] ?? 'unknown'
        ];
        
        // Cache for 1 hour
        wp_cache_set($cache_key, $tags, 'metasync', HOUR_IN_SECONDS);
        
        return $tags;
    }
    
    /**
     * Get cached system context for Sentry (optimized)
     */
    private function getSystemContext() {
        // Use WordPress object cache to avoid repeated expensive operations
        $cache_key = 'metasync_system_context';
        $cached_context = wp_cache_get($cache_key, 'metasync');
        
        if ($cached_context !== false) {
            // Add dynamic data that changes per request
            $cached_context['memory_usage'] = memory_get_usage(true);
            $cached_context['memory_peak'] = memory_get_peak_usage(true);
            $cached_context['request_uri'] = $_SERVER['REQUEST_URI'] ?? 'unknown';
            return $cached_context;
        }
        
        // Collect static system context (expensive operations)
        global $wpdb;
        $context = [
            'wp_url' => home_url(),
            'plugin_version' => $this->release,
            'plugin_name' => 'Search Engine Labs SEO (MetaSync)',
            'wordpress_version' => get_bloginfo('version'),
            'site_title' => get_bloginfo('name'),
            'site_admin_email' => get_bloginfo('admin_email'),
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'active_plugins' => count(get_option('active_plugins', [])),
            'active_theme' => get_template(),
            'multisite' => is_multisite(),
            'mysql_version' => method_exists($wpdb, 'get_var') ? $wpdb->get_var('SELECT VERSION()') : 'unknown',
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            // Dynamic data added per request
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ];
        
        // Cache static context for 1 hour
        wp_cache_set($cache_key, $context, 'metasync', HOUR_IN_SECONDS);
        
        return $context;
    }
    
    /**
     * Get cached contexts for Sentry (optimized)
     */
    private function getContexts() {
        // Cache static context data
        $cache_key = 'metasync_sentry_contexts';
        $cached_contexts = wp_cache_get($cache_key, 'metasync');
        
        if ($cached_contexts !== false) {
            return $cached_contexts;
        }
        
        $contexts = [
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
        
        // Cache for 1 hour
        wp_cache_set($cache_key, $contexts, 'metasync', HOUR_IN_SECONDS);
        
        return $contexts;
    }
    
    /**
     * Get cached user context (anonymized)
     */
    private function getUserContext() {
        // Cache user context since it's based on static site data
        $cache_key = 'metasync_user_context';
        $cached_context = wp_cache_get($cache_key, 'metasync');
        
        if ($cached_context !== false) {
            return $cached_context;
        }
        
        $context = [
            'id' => substr(md5(home_url() . get_bloginfo('name')), 0, 16),
            'ip_address' => '{{auto}}' // Let Sentry handle IP detection and anonymization
        ];
        
        // Cache for 1 hour
        wp_cache_set($cache_key, $context, 'metasync', HOUR_IN_SECONDS);
        
        return $context;
    }
    
    /**
     * Detect current environment
     */
    private function detectEnvironment() {
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return 'development';
        }
        
        // Check if running on localhost/development environment
        if ($this->isLocalhost()) {
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
        
        $result = $this->sendToSentry($test_data);
        $success = is_array($result) ? $result['success'] : $result;
        
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
function metasync_sentry_capture_message($message, $level = 'info', $extra = [], $attachment = null) {
    global $metasync_sentry_wordpress;
    if (!$metasync_sentry_wordpress) {
        $metasync_sentry_wordpress = init_metasync_sentry_wordpress();
    }
    
    if ($metasync_sentry_wordpress) {
        return $metasync_sentry_wordpress->captureMessage($message, $level, $extra, $attachment);
    }
    return false;
}

/**
 * Helper function to capture user feedback
 * 
 * @param array $feedback Feedback data with keys: name (optional), email (optional), message (required), event_id (optional)
 * @return bool Success status
 */
function metasync_sentry_capture_feedback($feedback, $attachment = null) {
    global $metasync_sentry_wordpress;
    if (!$metasync_sentry_wordpress) {
        $metasync_sentry_wordpress = init_metasync_sentry_wordpress();
    }
    
    if ($metasync_sentry_wordpress) {
        return $metasync_sentry_wordpress->captureFeedback($feedback, $attachment);
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

/**
 * Test user feedback submission
 * 
 * @return array Test results
 */
function metasync_sentry_test_user_feedback() {
    global $metasync_sentry_wordpress;
    if (!$metasync_sentry_wordpress) {
        $metasync_sentry_wordpress = init_metasync_sentry_wordpress();
    }
    
    if ($metasync_sentry_wordpress) {
        if (method_exists($metasync_sentry_wordpress, 'testUserFeedback')) {
            return $metasync_sentry_wordpress->testUserFeedback();
        }
    }
    
    return [
        'success' => false,
        'error' => 'Sentry not initialized or test method not available.'
    ];
}

// Auto-initialize when file is loaded
init_metasync_sentry_wordpress();
?>

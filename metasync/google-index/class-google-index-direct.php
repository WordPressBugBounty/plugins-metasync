<?php

/**
 * Direct Google Indexing API Implementation
 * 
 * Lightweight implementation without external libraries
 * Uses WordPress options for secure credential management
 * 
 * @package GoogleIndexDirect
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Google_Index_Direct 
{
    /**
     * WordPress option key for storing service account credentials
     */
    private const SERVICE_ACCOUNT_OPTION_KEY = 'google_index_service_account';

    /**
     * Google Indexing API endpoints
     */
    private const API_BASE_URL = 'https://indexing.googleapis.com/v3';
    private const PUBLISH_URL = self::API_BASE_URL . '/urlNotifications:publish';
    private const STATUS_URL = self::API_BASE_URL . '/urlNotifications/metadata';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    
    /**
     * OAuth scope for Google Indexing API
     */
    private const SCOPE = 'https://www.googleapis.com/auth/indexing';
    
    /**
     * Cache key for access token
     */
    private const TOKEN_CACHE_KEY = 'google_index_access_token';
    
    /**
     * Get service account configuration from WordPress options
     * 
     * @return array|false Service account configuration or false if not found
     */
    private function get_service_account_config()
    {
        $config = get_option(self::SERVICE_ACCOUNT_OPTION_KEY);
        
        if (empty($config)) {
            error_log('MetaSync Google Index: Service account configuration not found in options table. Option key: ' . self::SERVICE_ACCOUNT_OPTION_KEY);
            return false;
        }
        
        // Decode JSON if stored as string
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $config = $decoded;
            }
        }
        
        // Validate required fields
        $required_fields = ['client_email', 'private_key', 'project_id'];
        foreach ($required_fields as $field) {
            if (empty($config[$field])) {
                error_log("MetaSync Google Index: Missing required field '{$field}' in service account configuration");
                return false;
            }
        }
        
        return $config;
    }
    
    /**
     * Get credentials test data for test_connection method
     * 
     * @return array Credentials test data
     */
    private function get_credentials_test_data()
    {
        $service_account = $this->get_service_account_config();
        if (!$service_account) {
            return [
                'error' => 'Service account configuration not found',
                'client_email' => 'Not configured',
                'project_id' => 'Not configured',
                'has_private_key' => false
            ];
        }
        
        return [
            'client_email' => $service_account['client_email'] ?? 'Not set',
            'project_id' => $service_account['project_id'] ?? 'Not set',
            'has_private_key' => !empty($service_account['private_key'])
        ];
    }
    
    /**
     * Save service account configuration to WordPress options
     * 
     * @param array $config Service account configuration array
     * @return bool True on success, false on failure
     */
    public function save_service_account_config($config)
    {
        // Validate required fields
        $required_fields = ['client_email', 'private_key', 'project_id'];
        foreach ($required_fields as $field) {
            if (empty($config[$field])) {
                error_log("MetaSync Google Index: Cannot save - missing required field '{$field}' in service account configuration");
                return false;
            }
        }
        
        // Clear any cached tokens when updating credentials
        $this->clear_token_cache();
        
        // Check if configuration already exists and is identical
        $existing_config = get_option(self::SERVICE_ACCOUNT_OPTION_KEY);
        if ($existing_config && $existing_config === $config) {
            return true; // Consider identical config as success
        }
        
        // Save to options table
        $result = update_option(self::SERVICE_ACCOUNT_OPTION_KEY, $config);

        if (!$result) {
            // update_option returns false if the value is the same OR if there was an error
            // Let's check if the option was actually saved correctly
            $saved_config = get_option(self::SERVICE_ACCOUNT_OPTION_KEY);
            if ($saved_config && $saved_config === $config) {
                return true;
            } else {
                // NEW: Structured error logging with category and code
                global $wpdb;
                if (class_exists('Metasync_Error_Logger') && !empty($wpdb->last_error)) {
                    Metasync_Error_Logger::log(
                        Metasync_Error_Logger::CATEGORY_DATABASE_ERROR,
                        Metasync_Error_Logger::SEVERITY_ERROR,
                        'Failed to save Google service account configuration to database',
                        [
                            'option_key' => self::SERVICE_ACCOUNT_OPTION_KEY,
                            'wpdb_error' => $wpdb->last_error,
                            'wpdb_last_query' => $wpdb->last_query,
                            'operation' => 'save_service_account_config',
                            'project_id' => $config['project_id'] ?? 'unknown'
                        ]
                    );
                }
                
                error_log('MetaSync Google Index: Failed to save service account configuration - database error or data too large');
                return false;
            }
        }
        
        return $result;
    }
    
    /**
     * Index a WordPress post by ID and type
     * 
     * @param int $post_id WordPress post ID
     * @param string $post_type WordPress post type (post, page, etc.)
     * @param string $action Action to perform: 'update', 'delete', or 'status'
     * @return array API response
     */
    public function index_post($post_id, $post_type = 'post', $action = 'update')
    {
        // Validate inputs
        if (!is_numeric($post_id) || $post_id <= 0) {
            return $this->error_response('Invalid post ID provided');
        }
        
        // Get post URL
        $post_url = get_permalink($post_id);
        if (!$post_url || is_wp_error($post_url)) {
            return $this->error_response('Unable to get permalink for post ID: ' . $post_id);
        }
        
        // Get post object for validation
        $post = get_post($post_id);
        if (!$post || $post->post_type !== $post_type) {
            return $this->error_response('Post not found or post type mismatch');
        }
        
        // Only index published posts (unless deleting)
        if ($action !== 'delete' && $post->post_status !== 'publish') {
            return $this->error_response('Only published posts can be indexed');
        }
        
        // Log the action
        error_log(sprintf(
            'Google Index Direct: %s action for %s (ID: %d, Type: %s, URL: %s)',
            strtoupper($action),
            $post->post_title,
            $post_id,
            $post_type,
            $post_url
        ));
        
        // Perform the API call
        return $this->call_indexing_api($post_url, $action);
    }
    
    /**
     * Index multiple posts by their IDs
     * 
     * @param array $post_ids Array of post IDs
     * @param string $post_type Post type to filter by
     * @param string $action Action to perform
     * @return array Results for each post
     */
    public function index_multiple_posts($post_ids, $post_type = 'post', $action = 'update')
    {
        $results = [];
        
        foreach ($post_ids as $post_id) {
            $results[$post_id] = $this->index_post($post_id, $post_type, $action);
            
            // Add small delay between requests to avoid rate limiting
            usleep(500000); // 0.5 second delay
        }
        
        return $results;
    }
    
    /**
     * Index a URL directly
     * 
     * @param string $url URL to index
     * @param string $action Action to perform
     * @return array API response
     */
    public function index_url($url, $action = 'update')
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->error_response('Invalid URL provided: ' . $url);
        }
        
        return $this->call_indexing_api($url, $action);
    }
    
    /**
     * Get indexing status for a URL
     * 
     * @param string $url URL to check status for
     * @return array API response
     */
    public function get_url_status($url)
    {
        return $this->index_url($url, 'status');
    }
    
    /**
     * Call Google Indexing API directly
     * 
     * @param string $url URL to process
     * @param string $action Action: update, delete, or status
     * @return array API response
     */
    private function call_indexing_api($url, $action)
    {
        // Get access token
        $access_token = $this->get_access_token();
        if (!$access_token) {
            return $this->error_response('Failed to obtain Google access token');
        }
        
        // Prepare request based on action
        switch ($action) {
            case 'status':
                return $this->get_url_metadata($url, $access_token);
            
            case 'update':
                return $this->publish_url_notification($url, 'URL_UPDATED', $access_token);
            
            case 'delete':
                return $this->publish_url_notification($url, 'URL_DELETED', $access_token);
            
            default:
                return $this->error_response('Invalid action: ' . $action);
        }
    }
    
    /**
     * Get URL metadata (status check)
     * 
     * @param string $url URL to check
     * @param string $access_token Google access token
     * @return array API response
     */
    private function get_url_metadata($url, $access_token)
    {
        $request_url = self::STATUS_URL . '?' . http_build_query(['url' => $url]);
        
        $response = wp_remote_get($request_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
                'User-Agent' => 'WordPress-GoogleIndex-Direct/1.0'
            ],
            'timeout' => 30,
            'sslverify' => true
        ]);
        
        return $this->handle_api_response($response, 'GET_STATUS');
    }
    
    /**
     * Publish URL notification (update or delete)
     * 
     * @param string $url URL to process
     * @param string $type Notification type: URL_UPDATED or URL_DELETED
     * @param string $access_token Google access token
     * @return array API response
     */
    private function publish_url_notification($url, $type, $access_token)
    {
        $payload = [
            'url' => $url,
            'type' => $type
        ];
        
        $response = wp_remote_post(self::PUBLISH_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
                'User-Agent' => 'WordPress-GoogleIndex-Direct/1.0'
            ],
            'body' => json_encode($payload),
            'timeout' => 30,
            'sslverify' => true
        ]);
        
        return $this->handle_api_response($response, $type);
    }
    
    /**
     * Handle API response
     * 
     * @param WP_Error|array $response WordPress HTTP response
     * @param string $action Action being performed
     * @return array Processed response
     */
    private function handle_api_response($response, $action)
    {
        // Check for HTTP errors
        if (is_wp_error($response)) {
            // NEW: Structured error logging with category and code
            if (class_exists('Metasync_Error_Logger')) {
                Metasync_Error_Logger::log(
                    Metasync_Error_Logger::CATEGORY_NETWORK_ERROR,
                    Metasync_Error_Logger::SEVERITY_ERROR,
                    'Google Index API network request failed',
                    [
                        'action' => $action,
                        'error_message' => $response->get_error_message(),
                        'error_code' => $response->get_error_code(),
                        'api_endpoint' => 'Google Indexing API',
                        'operation' => 'handle_api_response'
                    ]
                );
            }
            
            return $this->error_response(
                'HTTP request failed: ' . $response->get_error_message(),
                'HTTP_ERROR'
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Log the response for debugging
        error_log(sprintf(
            'Google Index API Response - Action: %s, Status: %d, Body: %s',
            $action,
            $status_code,
            $body
        ));
        
        // Parse response body
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->error_response(
                'Invalid JSON response from Google API',
                'INVALID_JSON',
                ['raw_response' => $body, 'status_code' => $status_code]
            );
        }
        
        // Handle different HTTP status codes
        if ($status_code >= 200 && $status_code < 300) {
            return $this->success_response($data, $action);
        } elseif ($status_code >= 400 && $status_code < 500) {
            $error_message = $data['error']['message'] ?? 'Client error occurred';
            return $this->error_response(
                $error_message,
                'CLIENT_ERROR',
                ['status_code' => $status_code, 'google_error' => $data]
            );
        } else {
            return $this->error_response(
                'Server error from Google API',
                'SERVER_ERROR',
                ['status_code' => $status_code, 'response' => $data]
            );
        }
    }
    
    /**
     * Get Google OAuth access token
     * 
     * @return string|false Access token or false on failure
     */
    private function get_access_token()
    {
        // Check cache first
        $cached_token = get_transient(self::TOKEN_CACHE_KEY);
        if ($cached_token) {
            return $cached_token;
        }
        
        // Generate new token
        $jwt_token = $this->create_jwt_token();
        if (!$jwt_token) {
            return false;
        }
        
        // Exchange JWT for access token
        $response = wp_remote_post(self::TOKEN_URL, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'body' => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt_token
            ]),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
             // NEW: Structured error logging with category and code
            if (class_exists('Metasync_Error_Logger')) {
                Metasync_Error_Logger::log(
                    Metasync_Error_Logger::CATEGORY_NETWORK_ERROR,
                    Metasync_Error_Logger::SEVERITY_ERROR,
                    'Google OAuth token request network error',
                    [
                        'error_message' => $response->get_error_message(),
                        'error_code' => $response->get_error_code(),
                        'api_endpoint' => 'Google OAuth Token API',
                        'operation' => 'get_access_token'
                    ]
                );
            }
            
            error_log('MetaSync Google Index: Failed to get access token - ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['access_token'])) {
            error_log('MetaSync Google Index: No access token in response - ' . print_r($body, true));
            return false;
        }
        
        $access_token = $body['access_token'];
        $expires_in = $body['expires_in'] ?? 3600;
        
        // Cache the token (expire 5 minutes before actual expiry)
        set_transient(self::TOKEN_CACHE_KEY, $access_token, $expires_in - 300);
        
        return $access_token;
    }
    
    /**
     * Create JWT token for Google API authentication
     * 
     * @return string|false JWT token or false on failure
     */
    private function create_jwt_token()
    {
        // Get service account configuration
        $service_account = $this->get_service_account_config();
        if (!$service_account) {
            return false;
        }
        
        $header = json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT'
        ]);
        
        $now = time();
        $payload = json_encode([
            'iss' => $service_account['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600 // 1 hour
        ]);
        
        $base64_header = $this->base64url_encode($header);
        $base64_payload = $this->base64url_encode($payload);
        
        $signature_input = $base64_header . '.' . $base64_payload;
        
        // Sign with private key
        $signature = '';
        $success = openssl_sign(
            $signature_input,
            $signature,
            $service_account['private_key'],
            'SHA256'
        );
        
        if (!$success) {
            error_log('MetaSync Google Index: Failed to sign JWT token');
            return false;
        }
        
        $base64_signature = $this->base64url_encode($signature);
        
        return $signature_input . '.' . $base64_signature;
    }
    
    /**
     * Base64 URL encode
     * 
     * @param string $data Data to encode
     * @return string Encoded data
     */
    private function base64url_encode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Create success response
     * 
     * @param array $data Response data
     * @param string $action Action performed
     * @return array Success response
     */
    private function success_response($data, $action)
    {
        return [
            'success' => true,
            'action' => $action,
            'timestamp' => current_time('mysql'),
            'data' => $data
        ];
    }
    
    /**
     * Create error response
     * 
     * @param string $message Error message
     * @param string $code Error code
     * @param array $details Additional error details
     * @return array Error response
     */
    private function error_response($message, $code = 'UNKNOWN_ERROR', $details = [])
    {
        error_log('MetaSync Google Index Error: ' . $message . ' (Code: ' . $code . ')');

        return [
            'success' => false,
            'error' => [
                'message' => $message,
                'code' => $code,
                'details' => $details
            ],
            'timestamp' => current_time('mysql')
        ];
    }
    
    /**
     * Test the API connection
     * 
     * @return array Test results
     */
    public function test_connection()
    {
        // Test with site homepage
        $homepage_url = get_home_url();
        
        return [
            'homepage_test' => $this->get_url_status($homepage_url),
            'token_test' => [
                'success' => !empty($this->get_access_token()),
                'cached' => !empty(get_transient(self::TOKEN_CACHE_KEY))
            ],
            'credentials_test' => $this->get_credentials_test_data()
        ];
    }
    
    /**
     * Clear cached access token
     */
    public function clear_token_cache()
    {
        delete_transient(self::TOKEN_CACHE_KEY);
    }
    
    /**
     * Get service account info (for debugging)
     * 
     * @return array Service account details
     */
    public function get_service_account_info()
    {
        $service_account = $this->get_service_account_config();
        if (!$service_account) {
            return [
                'error' => 'Service account configuration not found',
                'client_email' => 'Not configured',
                'project_id' => 'Not configured',
                'private_key_id' => 'Not configured',
                'has_private_key' => false
            ];
        }
        
        return [
            'client_email' => $service_account['client_email'] ?? 'Not set',
            'project_id' => $service_account['project_id'] ?? 'Not set',
            'private_key_id' => $service_account['private_key_id'] ?? 'Not set',
            'has_private_key' => !empty($service_account['private_key'])
        ];
    }
}

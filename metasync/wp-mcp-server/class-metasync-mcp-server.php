<?php
/**
 * MetaSync MCP Server
 *
 * Main MCP server class that implements the Model Context Protocol
 * for WordPress. Exposes WordPress operations as MCP tools.
 *
 * @package    MetaSync
 * @subpackage MCP_Server
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_MCP_Server {

    /**
     * JSON-RPC handler
     *
     * @var MCP_JSON_RPC_Handler
     */
    private $json_rpc_handler;

    /**
     * Tool registry
     *
     * @var MCP_Tool_Registry
     */
    private $tool_registry;

    /**
     * REST namespace
     */
    const REST_NAMESPACE = 'metasync/v1';

    /**
     * REST route
     */
    const REST_ROUTE = '/mcp';

    /**
     * JWT token expiration time (in seconds)
     * Default: 24 hours
     */
    const JWT_EXPIRATION = 86400;

    /**
     * Constructor
     */
    public function __construct() {
        // Load dependencies
        $this->load_dependencies();

        // Initialize components
        $this->json_rpc_handler = new MCP_JSON_RPC_Handler();
        $this->tool_registry = MCP_Tool_Registry::get_instance();

        // Register handlers
        $this->register_json_rpc_handlers();

        // WordPress hooks
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Load dependencies
     */
    private function load_dependencies() {
        require_once plugin_dir_path(__FILE__) . 'class-mcp-json-rpc-handler.php';
        require_once plugin_dir_path(__FILE__) . 'class-mcp-tool-base.php';
        require_once plugin_dir_path(__FILE__) . 'class-mcp-tool-registry.php';
    }

    /**
     * Register JSON-RPC method handlers
     */
    private function register_json_rpc_handlers() {
        $this->json_rpc_handler->register_handler('tools/list', [$this, 'handle_tools_list']);
        $this->json_rpc_handler->register_handler('tools/call', [$this, 'handle_tools_call']);
    }

    /**
     * Register REST routes
     */
    public function register_rest_routes() {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'handle_rest_request'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);

        // Health check endpoint
        register_rest_route(self::REST_NAMESPACE, '/mcp/health', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_health_check'],
            'permission_callback' => '__return_true',
        ]);

        // JWT authentication endpoint
        register_rest_route(self::REST_NAMESPACE, '/mcp/auth', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_jwt_auth'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Handle REST request
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function handle_rest_request($request) {
        $request_body = $request->get_body();

        // Process through JSON-RPC handler
        $response = $this->json_rpc_handler->handle_request($request_body);

        return new WP_REST_Response($response, 200);
    }

    /**
     * Handle health check
     *
     * @return WP_REST_Response
     */
    public function handle_health_check() {
        return new WP_REST_Response([
            'status' => 'ok',
            'version' => METASYNC_VERSION,
            'tools_count' => $this->tool_registry->get_tool_count(),
            'enabled' => true
        ], 200);
    }

    /**
     * Check permissions
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error
     */
    public function check_permissions($request) {
        // Check authentication
        $auth_result = $this->authenticate_request($request);
        if (is_wp_error($auth_result)) {
            return $auth_result;
        }

        // If authenticated via API key, skip capability check
        // API key already proves admin-level access
        $api_key = $request->get_header('X-API-Key');
        if ($api_key && $this->verify_plugin_auth_token($api_key)) {
            define('METASYNC_MCP_API_KEY_AUTH', true);
            return true;
        }

        // If authenticated via JWT, check if it's API key-based or user-based
        if (defined('METASYNC_MCP_JWT_AUTH') && METASYNC_MCP_JWT_AUTH) {
            // If JWT was generated from API key (user_id = 0), skip capability check
            // API key-based JWT tokens have full system-level access
            if (defined('METASYNC_MCP_API_KEY_AUTH') && METASYNC_MCP_API_KEY_AUTH) {
                return true;
            }

            // For user-based JWT tokens, check user capability
            if (!current_user_can('manage_options')) {
                return new WP_Error(
                    'insufficient_permissions',
                    'User does not have permission to use the MCP server',
                    ['status' => 403]
                );
            }
            return true;
        }

        // For nonce-based auth, check user capability
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'insufficient_permissions',
                'You do not have permission to use the MCP server',
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Authenticate request
     *
     * Supports three authentication methods:
     * 1. WordPress nonce (same-origin)
     * 2. Plugin auth token (external clients)
     * 3. JWT Bearer token (industry-standard)
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error
     */
    private function authenticate_request($request) {
        // Method 1: WordPress nonce (for same-origin requests)
        $nonce = $request->get_header('X-WP-Nonce');
        if ($nonce && wp_verify_nonce($nonce, 'wp_rest')) {
            return true;
        }

        // Method 2: Plugin auth token (for external clients like Claude Desktop)
        $api_key = $request->get_header('X-API-Key');
        if ($api_key && $this->verify_plugin_auth_token($api_key)) {
            return true;
        }

        // Method 3: JWT Bearer token (industry-standard authentication)
        $auth_header = $request->get_header('Authorization');
        if ($auth_header && preg_match('/Bearer\s+(.+)/i', $auth_header, $matches)) {
            $jwt_token = trim($matches[1]);
            $jwt_result = $this->verify_jwt_token($jwt_token);
            if ($jwt_result !== false) {
                // If user_id is 0, this is an API key-based JWT token (system-level access)
                // Treat it like API key authentication (no user context needed)
                if ($jwt_result['user_id'] === 0) {
                    define('METASYNC_MCP_JWT_AUTH', true);
                    define('METASYNC_MCP_API_KEY_AUTH', true);
                } else {
                    // Set the authenticated user from JWT
                    wp_set_current_user($jwt_result['user_id']);
                    define('METASYNC_MCP_JWT_AUTH', true);
                }
                return true;
            }
        }

        return new WP_Error(
            'authentication_failed',
            'Authentication required. Provide X-WP-Nonce, X-API-Key, or Authorization: Bearer <jwt_token> header.',
            ['status' => 401]
        );
    }

    /**
     * Verify plugin auth token
     *
     * @param string $provided_token Provided auth token
     * @return bool
     */
    private function verify_plugin_auth_token($provided_token) {
        $options = get_option('metasync_options', []);
        $stored_token = isset($options['general']['apikey']) ? $options['general']['apikey'] : null;

        if (empty($stored_token)) {
            return false;
        }

        return hash_equals($stored_token, $provided_token);
    }

    /**
     * Handle tools/list method
     *
     * @param array $params Request parameters
     * @return array
     */
    public function handle_tools_list($params) {
        $tools = $this->tool_registry->get_tools_list();

        return [
            'tools' => $tools
        ];
    }

    /**
     * Handle tools/call method
     *
     * @param array $params Request parameters
     * @return array
     * @throws InvalidArgumentException If parameters are invalid
     */
    public function handle_tools_call($params) {
        // Validate params
        if (!isset($params['name'])) {
            throw new InvalidArgumentException('Missing required parameter: name');
        }

        $tool_name = $params['name'];
        $tool_params = isset($params['arguments']) ? $params['arguments'] : [];

        // Execute tool
        try {
            $result = $this->tool_registry->execute_tool($tool_name, $tool_params);

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($result, JSON_PRETTY_PRINT)
                    ]
                ]
            ];
        } catch (Exception $e) {
            throw new Exception("Tool execution failed: " . $e->getMessage());
        }
    }

    /**
     * Register a tool
     *
     * @param MCP_Tool_Base $tool Tool instance
     * @return bool
     */
    public function register_tool(MCP_Tool_Base $tool) {
        return $this->tool_registry->register_tool($tool);
    }

    /**
     * Get tool registry instance
     * Allows internal components (like OTTO integration) to call MCP tools directly
     *
     * @return MCP_Tool_Registry
     */
    public function get_tool_registry() {
        return $this->tool_registry;
    }

    /**
     * Get plugin auth token
     *
     * @return string|false
     */
    public function get_api_key() {
        $options = get_option('metasync_options', []);
        return isset($options['general']['apikey']) ? $options['general']['apikey'] : false;
    }

    /**
     * Get server info
     *
     * @return array
     */
    public function get_server_info() {
        return [
            'enabled' => true,
            'endpoint' => rest_url(self::REST_NAMESPACE . self::REST_ROUTE),
            'tools_count' => $this->tool_registry->get_tool_count(),
            'version' => METASYNC_VERSION,
            'has_auth_token' => !empty($this->get_api_key())
        ];
    }

    /**
     * Handle JWT authentication request
     * Generates a JWT token by exchanging plugin API key for time-limited JWT
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function handle_jwt_auth($request) {
        $params = $request->get_json_params();

        // Get API key from request body or header
        $api_key = '';
        if (isset($params['api_key'])) {
            $api_key = sanitize_text_field($params['api_key']);
        } else {
            $api_key = $request->get_header('X-API-Key');
        }

        if (empty($api_key)) {
            return new WP_Error(
                'missing_api_key',
                'API key is required. Provide in request body as "api_key" or in X-API-Key header.',
                ['status' => 400]
            );
        }

        // Verify API key
        if (!$this->verify_plugin_auth_token($api_key)) {
            return new WP_Error(
                'invalid_api_key',
                'Invalid API key',
                ['status' => 401]
            );
        }

        // Generate JWT token
        // Use 0 as user_id to indicate API key authentication (system-level access)
        $token = $this->generate_jwt_token(0);

        if ($token === false) {
            return new WP_Error(
                'token_generation_failed',
                'Failed to generate JWT token',
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => self::JWT_EXPIRATION,
            'expires_at' => time() + self::JWT_EXPIRATION,
            'scope' => 'mcp:full_access'
        ], 200);
    }

    /**
     * Generate JWT token
     *
     * @param int $user_id WordPress user ID (0 for API key based tokens)
     * @return string|false JWT token or false on failure
     */
    private function generate_jwt_token($user_id) {
        $issued_at = time();
        $expiration = $issued_at + self::JWT_EXPIRATION;

        // JWT header
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT'
        ];

        // JWT payload
        // user_id = 0 indicates API key authentication (system-level access)
        $payload = [
            'sub' => $user_id === 0 ? 'api_key' : 'user:' . $user_id,
            'user_id' => $user_id,
            'iat' => $issued_at,
            'exp' => $expiration,
            'iss' => get_site_url(),
            'scope' => 'mcp:full_access'
        ];

        // Encode header and payload
        $header_encoded = $this->base64_url_encode(json_encode($header));
        $payload_encoded = $this->base64_url_encode(json_encode($payload));

        // Create signature
        $signature_input = $header_encoded . '.' . $payload_encoded;
        $secret = $this->get_jwt_secret();
        $signature = hash_hmac('sha256', $signature_input, $secret, true);
        $signature_encoded = $this->base64_url_encode($signature);

        // Create JWT token
        $jwt = $header_encoded . '.' . $payload_encoded . '.' . $signature_encoded;

        return $jwt;
    }

    /**
     * Verify JWT token
     *
     * @param string $token JWT token
     * @return array|false Decoded payload or false on failure
     */
    private function verify_jwt_token($token) {
        // Split token into parts
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return false;
        }

        list($header_encoded, $payload_encoded, $signature_encoded) = $parts;

        // Verify signature
        $signature_input = $header_encoded . '.' . $payload_encoded;
        $secret = $this->get_jwt_secret();
        $signature = hash_hmac('sha256', $signature_input, $secret, true);
        $signature_expected = $this->base64_url_encode($signature);

        if (!hash_equals($signature_expected, $signature_encoded)) {
            return false;
        }

        // Decode payload
        $payload_json = $this->base64_url_decode($payload_encoded);
        $payload = json_decode($payload_json, true);

        if (!$payload) {
            return false;
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return false;
        }

        // Check issuer
        if (isset($payload['iss']) && $payload['iss'] !== get_site_url()) {
            return false;
        }

        // Check user_id exists
        if (!isset($payload['user_id'])) {
            return false;
        }

        // If user_id is 0, this is an API key-based token (system-level access)
        // No need to validate user existence
        if ($payload['user_id'] === 0) {
            return $payload;
        }

        // For user-based tokens, verify user exists
        $user = get_user_by('id', $payload['user_id']);
        if (!$user) {
            return false;
        }

        return $payload;
    }

    /**
     * Get JWT secret key
     *
     * Uses WordPress NONCE_SALT as the secret key
     *
     * @return string
     */
    private function get_jwt_secret() {
        // Use WordPress NONCE_SALT as the JWT secret
        // This is secure and unique per WordPress installation
        if (defined('NONCE_SALT')) {
            return NONCE_SALT;
        }

        // Fallback to AUTH_KEY if NONCE_SALT is not defined
        if (defined('AUTH_KEY')) {
            return AUTH_KEY;
        }

        // Final fallback (should never happen in production)
        return wp_salt('auth');
    }

    /**
     * Base64 URL encode
     *
     * @param string $data Data to encode
     * @return string
     */
    private function base64_url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL decode
     *
     * @param string $data Data to decode
     * @return string
     */
    private function base64_url_decode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}

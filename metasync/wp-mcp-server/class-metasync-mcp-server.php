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
     * Authenticated identity for the current request
     *
     * Populated by authenticate_request() with the shape
     * ['type' => 'api_key'|'jwt'|'user'|'nonce', 'id' => <stable string>].
     *
     * @var array|null
     */
    private $authenticated_identity = null;

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
     * Default tool-call rate limit (requests per window) for authenticated MCP clients.
     */
    const DEFAULT_TOOL_CALL_LIMIT = 60;

    /**
     * Default tool-call rate-limit window in seconds.
     */
    const DEFAULT_TOOL_CALL_WINDOW = 60;

    /**
     * Failed-authentication attempts allowed per source within the window.
     */
    const AUTH_FAILURE_LIMIT = 10;

    /**
     * Failed-authentication window in seconds (per source and site-wide).
     */
    const AUTH_FAILURE_WINDOW = 900;

    /**
     * Site-wide failed-authentication ceiling within the window.
     *
     * Backstop for attackers who rotate their source address: the per-source
     * bucket alone cannot hold them, this one can.
     */
    const AUTH_FAILURE_SITE_LIMIT = 100;

    /**
     * Upper bound on the escalated lockout, in seconds.
     */
    const AUTH_FAILURE_MAX_LOCKOUT = 3600;

    /**
     * Bucket name used for the site-wide failed-authentication counter.
     */
    const AUTH_FAILURE_SITE_BUCKET = '__site__';

    /**
     * Prefix for failed-authentication counter transients.
     */
    const AUTH_FAILURE_PREFIX = 'metasync_mcp_auth_';

    /**
     * Evaluate every failed-authentication bucket, including the site-wide one.
     */
    const AUTH_SCOPE_ALL = 'all';

    /**
     * Evaluate only the caller's own bucket, ignoring the site-wide ceiling.
     *
     * Used for the pre-authentication gate: the site-wide ceiling must never
     * decide whether a *valid* credential is accepted.
     */
    const AUTH_SCOPE_SOURCE = 'source';

    /**
     * The request whose permission decision is memoised below.
     *
     * WordPress calls a route's permission_callback twice per request: once
     * from WP_REST_Server::respond_to_request() to authorise the call, and
     * again from rest_send_allow_header() on rest_post_dispatch to build the
     * Allow header. A callback with side effects therefore fires twice, which
     * would spend the caller's failure budget at double the documented rate.
     *
     * The request object itself is held rather than an spl_object_id: ids are
     * recycled once an object is freed, so a later request could collide with
     * a released one and silently inherit its decision. Keeping the reference
     * makes identity comparison exact.
     *
     * @var WP_REST_Request|null
     */
    private $permission_request = null;

    /**
     * Memoised decision for $permission_request.
     *
     * @var bool|WP_Error|null
     */
    private $permission_decision = null;

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
        require_once plugin_dir_path(__FILE__) . '../includes/class-metasync-rate-limiter.php';
    }

    /**
     * Register JSON-RPC method handlers
     */
    private function register_json_rpc_handlers() {
        $this->json_rpc_handler->register_handler('tools/list', [$this, 'handle_tools_list']);
        $this->json_rpc_handler->register_handler('tools/call', [$this, 'handle_tools_call']);
        $this->json_rpc_handler->register_handler('notifications/initialized', [$this, 'handle_initialized_notification']);
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
        $result = $this->process_json_rpc_request($request->get_body());
        $response = new WP_REST_Response($result['body'], $result['status']);

        foreach ($result['headers'] as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }

    /**
     * Authenticate a PHP bridge and establish its rate-limit identity.
     *
     * HTTP bridges pass the request header. Stdio bridges pass the key from
     * their private process environment.
     *
     * @param string|false $provided_key Bridge API key.
     * @return true|WP_Error
     */
    public function authenticate_bridge_request($provided_key) {
        $this->authenticated_identity = null;
        $configured_key = getenv('WP_MCP_API_KEY');

        if (!is_string($configured_key) || $configured_key === '') {
            return new WP_Error(
                'bridge_auth_not_configured',
                'MCP bridge authentication is not configured.',
                ['status' => 401]
            );
        }

        if (!is_string($provided_key) || !hash_equals($configured_key, $provided_key)) {
            return new WP_Error(
                'bridge_authentication_failed',
                'Invalid or missing API key.',
                ['status' => 401]
            );
        }

        $this->authenticated_identity = [
            'type' => 'api_key',
            'id'   => hash('sha256', $configured_key),
        ];

        if (!defined('METASYNC_MCP_API_KEY_AUTH')) {
            define('METASYNC_MCP_API_KEY_AUTH', true);
        }

        return true;
    }

    /**
     * Process authenticated JSON-RPC consistently for every transport.
     *
     * @param string $request_body Raw JSON-RPC request body.
     * @return array{body: array|null, status: int, headers: array}
     */
    public function process_json_rpc_request($request_body) {
        if ($this->authenticated_identity === null) {
            return [
                'body' => [
                    'jsonrpc' => '2.0',
                    'id'      => null,
                    'error'   => [
                        'code'    => MCP_JSON_RPC_Handler::ERROR_SERVER_ERROR,
                        'message' => 'Authentication required',
                    ],
                ],
                'status' => 401,
                'headers' => [],
            ];
        }

        // Only rate-limit tools/call requests, not tools/list or other methods.
        $decoded = json_decode($request_body, true);
        $is_tool_call = is_array($decoded)
            && isset($decoded['method'])
            && $decoded['method'] === 'tools/call';
        $is_notification = is_array($decoded) && !array_key_exists('id', $decoded);

        if ($is_tool_call && $this->authenticated_identity !== null) {
            $default_limits = [
                'max'    => self::DEFAULT_TOOL_CALL_LIMIT,
                'window' => self::DEFAULT_TOOL_CALL_WINDOW,
            ];
            $limits = apply_filters('metasync_mcp_tool_call_rate_limit', $default_limits, $this->authenticated_identity);

            $max    = isset($limits['max']) ? (int) $limits['max'] : self::DEFAULT_TOOL_CALL_LIMIT;
            $window = isset($limits['window']) ? (int) $limits['window'] : self::DEFAULT_TOOL_CALL_WINDOW;

            $rate_result = Metasync_Rate_Limiter::get_instance()->check_rate_limit(
                $this->authenticated_identity['id'],
                $max,
                $window,
                'mcp_tool_'
            );

            if (is_wp_error($rate_result)) {
                $error_data  = $rate_result->get_error_data();
                $retry_after = isset($error_data['retry_after']) ? (int) $error_data['retry_after'] : $window;

                $req_id = isset($decoded['id']) ? $decoded['id'] : null;

                $error_body = [
                    'jsonrpc' => '2.0',
                    'id'      => $req_id,
                    'error'   => [
                        'code'    => MCP_JSON_RPC_Handler::ERROR_RATE_LIMITED,
                        'message' => 'Rate limit exceeded',
                        'data'    => [
                            'retry_after' => $retry_after,
                        ],
                    ],
                ];

                return [
                    'body' => $is_notification ? null : $error_body,
                    'status' => 429,
                    'headers' => ['Retry-After' => (string) $retry_after],
                ];
            }
        }

        return [
            'body' => $this->json_rpc_handler->handle_request($request_body),
            'status' => 200,
            'headers' => [],
        ];
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
     * Failed authentication is rate limited here rather than inside
     * authenticate_request(): this is the permission_callback, so it is the
     * only seam that runs before WordPress decides whether to invoke the
     * route callback at all. Every rejected attempt is recorded before the
     * response is chosen, so the request that trips the limit is itself
     * counted.
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error
     */
    public function check_permissions($request) {
        // Core asks twice per request (authorisation, then the Allow header).
        // Only the first pass may record anything.
        if ($this->permission_request !== null && $this->permission_request === $request) {
            return $this->permission_decision;
        }

        $this->permission_request  = $request;
        $this->permission_decision = $this->evaluate_permissions($request);

        return $this->permission_decision;
    }

    /**
     * Evaluate the permission decision for a request.
     *
     * Side effects (recording a failure, clearing a counter) belong here, and
     * check_permissions() guarantees this runs at most once per request.
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error
     */
    private function evaluate_permissions($request) {
        $source = $this->get_auth_source_key();

        // Already locked out: refuse before verifying anything, otherwise the
        // caller could keep guessing credentials while "rate limited".
        //
        // Only this caller's own bucket gates the pre-authentication path.
        // The site-wide ceiling is deliberately left out here and applied to
        // failures below instead: were it enforced before the credential is
        // read, an attacker filling the site bucket from throwaway addresses
        // would lock out the legitimate client holding the correct key, and
        // every poll that client made would escalate its own bucket on the
        // way past. That turns a brute-force defence into a denial-of-service
        // lever, so the ceiling only ever rejects an attempt that has already
        // failed to authenticate.
        $lockout = $this->check_rate_limit($source, false, self::AUTH_SCOPE_SOURCE);
        if (is_wp_error($lockout)) {
            $this->escalate_auth_lockout($source);
            return $lockout;
        }

        // Check authentication
        $auth_result = $this->authenticate_request($request);
        if (is_wp_error($auth_result)) {
            // A revoked token is not a guess. Producing one takes a valid
            // signature over the current secret, so the caller held a real
            // credential and simply needs to re-authenticate after a key
            // rotation, a disconnect, or an upgrade that rebound tokens.
            // Charging it would drain the very bucket the auth endpoint
            // consults, locking a legitimate client out of its own recovery.
            if ($auth_result->get_error_code() !== 'token_revoked') {
                // Record this failure, then re-evaluate. Recording first means
                // the attempt that crosses the threshold is counted rather
                // than given away for free.
                $limited = $this->check_rate_limit($source, true);
                if (is_wp_error($limited)) {
                    return $limited;
                }
            }

            return $auth_result;
        }

        // Successful authentication clears the source counter so legitimate
        // clients are never penalised for an earlier typo or key rotation.
        $this->clear_auth_attempts($source);

        // If authenticated via API key, skip capability check
        // API key already proves admin-level access
        $api_key = $request->get_header('X-API-Key');
        if ($api_key && $this->verify_plugin_auth_token($api_key)) {
            if (!defined('METASYNC_MCP_API_KEY_AUTH')) {
                define('METASYNC_MCP_API_KEY_AUTH', true);
            }
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
            $this->authenticated_identity = [
                'type' => 'user',
                'id'   => 'user:' . get_current_user_id(),
            ];
            return true;
        }

        // Method 2: Plugin auth token (for external clients like Claude Desktop)
        $api_key = $request->get_header('X-API-Key');
        if ($api_key && $this->verify_plugin_auth_token($api_key)) {
            $this->authenticated_identity = [
                'type' => 'api_key',
                'id'   => hash('sha256', $api_key),
            ];
            return true;
        }

        // Method 3: JWT Bearer token (industry-standard authentication)
        $auth_header = $request->get_header('Authorization');
        if ($auth_header && preg_match('/Bearer\s+(.+)/i', $auth_header, $matches)) {
            $jwt_token = trim($matches[1]);
            $jwt_revoked = false;
            $jwt_result = $this->verify_jwt_token($jwt_token, $jwt_revoked);
            if ($jwt_result !== false) {
                // If user_id is 0, this is an API key-based JWT token (system-level access)
                // Treat it like API key authentication (no user context needed)
                if ($jwt_result['user_id'] === 0) {
                    if (!defined('METASYNC_MCP_JWT_AUTH')) {
                        define('METASYNC_MCP_JWT_AUTH', true);
                    }
                    if (!defined('METASYNC_MCP_API_KEY_AUTH')) {
                        define('METASYNC_MCP_API_KEY_AUTH', true);
                    }
                    $this->authenticated_identity = [
                        'type' => 'api_key',
                        'id'   => $jwt_result['sub'],
                    ];
                } else {
                    // Set the authenticated user from JWT
                    wp_set_current_user($jwt_result['user_id']);
                    if (!defined('METASYNC_MCP_JWT_AUTH')) {
                        define('METASYNC_MCP_JWT_AUTH', true);
                    }
                    $this->authenticated_identity = [
                        'type' => 'user',
                        'id'   => $jwt_result['sub'],
                    ];
                }
                return true;
            }

            if ($jwt_revoked) {
                return new WP_Error(
                    'token_revoked',
                    'This token is no longer valid for the current API key. Request a new one from the auth endpoint.',
                    ['status' => 401]
                );
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
     * Acknowledge the client initialization notification.
     *
     * JSON-RPC notifications intentionally produce no response body.
     *
     * @param array $params Notification parameters.
     * @return null
     */
    public function handle_initialized_notification($params) {
        return null;
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
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new Exception('Tool execution failed: ' . $e->getMessage());
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
        $source = $this->get_auth_source_key();

        // Enforce brute-force rate limit before any further processing.
        // Source-scoped for the same reason as check_permissions(): a valid
        // key must still be able to mint a token while the site-wide bucket
        // is full of somebody else's failures.
        $rate_limit_result = $this->check_rate_limit($source, false, self::AUTH_SCOPE_SOURCE);
        if (is_wp_error($rate_limit_result)) {
            return $rate_limit_result;
        }

        $params = $request->get_json_params();

        // Get API key from request body or header
        $api_key = '';
        if (isset($params['api_key'])) {
            $api_key = sanitize_text_field($params['api_key']);
        } else {
            $api_key = $request->get_header('X-API-Key');
        }

        if (empty($api_key)) {
            $limited = $this->check_rate_limit($source, true);
            if (is_wp_error($limited)) {
                return $limited;
            }

            return new WP_Error(
                'missing_api_key',
                'API key is required. Provide in request body as "api_key" or in X-API-Key header.',
                ['status' => 400]
            );
        }

        // Verify API key
        if (!$this->verify_plugin_auth_token($api_key)) {
            $limited = $this->check_rate_limit($source, true);
            if (is_wp_error($limited)) {
                return $limited;
            }

            return new WP_Error(
                'invalid_api_key',
                'Invalid API key',
                ['status' => 401]
            );
        }

        // Successful authentication — clear any accumulated failure counters
        $this->clear_auth_attempts($source);

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

        // Bind the token to the API key it is minted against. Without this
        // claim a token outlives the key that authorised it and cannot be
        // revoked before its expiry.
        $fingerprint = $this->get_api_key_fingerprint();
        if ($fingerprint === '') {
            return false;
        }

        // JWT payload
        // user_id = 0 indicates API key authentication (system-level access)
        $payload = [
            'sub' => $user_id === 0 ? 'api_key' : 'user:' . $user_id,
            'user_id' => $user_id,
            'iat' => $issued_at,
            'exp' => $expiration,
            'iss' => get_site_url(),
            'akf' => $fingerprint,
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
     * @param string $token   JWT token
     * @param bool   $revoked Set true when the token was well-formed, correctly
     *                        signed and unexpired but no longer matches the
     *                        current API key. Reaching that state requires the
     *                        signing secret, so it is a revocation to recover
     *                        from rather than a credential guess to punish.
     * @return array|false Decoded payload or false on failure
     */
    private function verify_jwt_token($token, &$revoked = false) {
        $revoked = false;

        // Split token into parts
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return false;
        }

        list($header_encoded, $payload_encoded, $signature_encoded) = $parts;

        // Validate header: enforce alg=HS256 and typ=JWT to defeat
        // algorithm-confusion attacks (e.g. "alg":"none").
        $header_json = $this->base64_url_decode($header_encoded);
        $header = json_decode($header_json, true);
        if (!is_array($header)) {
            return false;
        }
        if (!isset($header['alg']) || $header['alg'] !== 'HS256') {
            return false;
        }
        if (!isset($header['typ']) || $header['typ'] !== 'JWT') {
            return false;
        }

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

        // Check expiration — exp is mandatory; reject tokens missing or
        // with a non-numeric / past expiry.
        if (!isset($payload['exp']) || !is_numeric($payload['exp']) || (int) $payload['exp'] < time()) {
            return false;
        }

        // Check issuer — iss is mandatory and must match this site.
        if (!isset($payload['iss']) || $payload['iss'] !== get_site_url()) {
            return false;
        }

        // Check user_id exists
        if (!isset($payload['user_id'])) {
            return false;
        }

        // Check the API key binding. Tokens are minted against an API key
        // identity, so a rotated key must reject every token issued under the
        // old one, and a disconnected site — which leaves no key at all —
        // must reject them all. This runs before the user_id === 0 branch so
        // API key tokens cannot skip it.
        $fingerprint = $this->get_api_key_fingerprint();
        if ($fingerprint === '') {
            $revoked = true;
            return false;
        }
        if (!isset($payload['akf']) || !is_string($payload['akf'])) {
            $revoked = true;
            return false;
        }
        if (!hash_equals($fingerprint, $payload['akf'])) {
            $revoked = true;
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
     * Uses a dedicated random secret stored in the WordPress options table.
     * A new secret is generated on first use and persisted so that existing
     * tokens remain valid across requests.
     *
     * @return string
     */
    private function get_jwt_secret() {
        $secret = get_option('metasync_jwt_secret');
        if (!empty($secret)) {
            return $secret;
        }
        return $this->create_jwt_secret();
    }

    /**
     * Persist the JWT secret on first use, first writer wins.
     *
     * Two requests arriving before the secret exists both see an empty option.
     * add_option() and update_option() would each overwrite the other — core's
     * add_option() is an INSERT ... ON DUPLICATE KEY UPDATE — leaving the
     * loser's freshly signed token unverifiable. The insert below is a no-op
     * once the row exists, so every request adopts the same secret.
     *
     * @return string
     */
    private function create_jwt_secret() {
        global $wpdb;

        $candidate = wp_generate_password(64, true, true);

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)"
                . " VALUES (%s, %s, %s)"
                . " ON DUPLICATE KEY UPDATE option_id = option_id",
                'metasync_jwt_secret',
                $candidate,
                'no'
            )
        );

        // Read the row back rather than trusting the candidate: on a race the
        // winner's secret is the one that was persisted.
        $stored = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                'metasync_jwt_secret'
            )
        );

        if (is_string($stored) && $stored !== '') {
            // The options cache recorded a miss moments ago; drop it so later
            // get_option() calls in this request see the persisted secret.
            wp_cache_delete('metasync_jwt_secret', 'options');
            wp_cache_delete('notoptions', 'options');
            return $stored;
        }

        // The insert did not take for a reason other than the row already
        // existing. Fall back to the original write path so the request can
        // still mint a usable token.
        update_option('metasync_jwt_secret', $candidate, false);
        return $candidate;
    }

    /**
     * Fingerprint the API key that MCP tokens are minted against.
     *
     * HMACs the key with the JWT secret so the claim carried inside a token is
     * not an offline-crackable hash of the key itself.
     *
     * @return string Fingerprint, or an empty string when no API key is set.
     */
    private function get_api_key_fingerprint() {
        $options = get_option('metasync_options', []);
        $api_key = isset($options['general']['apikey']) ? $options['general']['apikey'] : '';

        if (!is_string($api_key) || $api_key === '') {
            return '';
        }

        return hash_hmac('sha256', $api_key, $this->get_jwt_secret());
    }

    /**
     * Resolve a stable, hard-to-forge key identifying the caller.
     *
     * REMOTE_ADDR alone is wrong behind a reverse proxy (every client
     * collapses into the proxy's bucket), but blindly trusting
     * CF-Connecting-IP / X-Forwarded-For is worse: any caller could mint a
     * fresh bucket per request, or poison somebody else's, simply by setting
     * a header. Forwarded headers are therefore honoured only when the TCP
     * peer is itself a configured trusted proxy, and the resolved client
     * address is bound to the proxy that vouched for it so two proxies can
     * never share a bucket.
     *
     * Sites behind Cloudflare/nginx/a load balancer should publish their
     * proxy ranges through the 'metasync_mcp_trusted_proxies' filter, e.g.
     *
     *     add_filter( 'metasync_mcp_trusted_proxies', function ( $proxies ) {
     *         $proxies[] = '173.245.48.0/20';
     *         return $proxies;
     *     } );
     *
     * The default is an empty list, i.e. only the un-forgeable TCP peer is
     * used. Combined with the site-wide bucket in check_rate_limit(), a
     * caller who rotates addresses still meets a ceiling.
     *
     * @return string Opaque source key (never empty).
     */
    private function get_auth_source_key() {
        $remote = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? trim($_SERVER['REMOTE_ADDR'])
            : '';

        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            $remote = '';
        }

        $trusted = apply_filters('metasync_mcp_trusted_proxies', []);
        if (!is_array($trusted)) {
            $trusted = [];
        }

        if ($remote !== '' && !empty($trusted) && $this->is_trusted_proxy($remote, $trusted)) {
            $forwarded = $this->get_forwarded_ip();
            if ($forwarded !== '') {
                return 'fwd:' . $remote . '|' . $forwarded;
            }
        }

        return $remote === '' ? 'unknown' : 'ip:' . $remote;
    }

    /**
     * Read the client address a trusted proxy forwarded.
     *
     * Only called once the TCP peer has been confirmed as a trusted proxy.
     * X-Forwarded-For is walked right-to-left because the trusted proxy
     * appends to the right; anything further left may have been supplied by
     * the client.
     *
     * @return string Client IP, or '' when no usable value was forwarded.
     */
    private function get_forwarded_ip() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && is_string($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cf_ip = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($cf_ip, FILTER_VALIDATE_IP)) {
                return $cf_ip;
            }
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) && is_string($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $candidates = array_reverse(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            foreach ($candidates as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        if (!empty($_SERVER['HTTP_X_REAL_IP']) && is_string($_SERVER['HTTP_X_REAL_IP'])) {
            $real_ip = trim($_SERVER['HTTP_X_REAL_IP']);
            if (filter_var($real_ip, FILTER_VALIDATE_IP)) {
                return $real_ip;
            }
        }

        return '';
    }

    /**
     * Test whether an address belongs to the configured trusted-proxy set.
     *
     * @param string $ip      Address to test (already validated).
     * @param array  $proxies Plain addresses and/or CIDR ranges.
     * @return bool
     */
    private function is_trusted_proxy($ip, $proxies) {
        foreach ($proxies as $proxy) {
            if (is_string($proxy) && $proxy !== '' && $this->ip_in_cidr($ip, trim($proxy))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match an address against a plain address or CIDR range.
     *
     * Works for both IPv4 and IPv6 by comparing packed addresses.
     *
     * @param string $ip    Address to test.
     * @param string $range Plain address or CIDR range.
     * @return bool
     */
    private function ip_in_cidr($ip, $range) {
        $ip_bin = @inet_pton($ip);
        if ($ip_bin === false) {
            return false;
        }

        if (strpos($range, '/') === false) {
            $range_bin = @inet_pton($range);
            return $range_bin !== false && $range_bin === $ip_bin;
        }

        list($subnet, $bits) = explode('/', $range, 2);

        $subnet_bin = @inet_pton(trim($subnet));
        if ($subnet_bin === false || strlen($subnet_bin) !== strlen($ip_bin)) {
            // Unparseable subnet, or an IPv4/IPv6 family mismatch.
            return false;
        }

        if (!is_numeric($bits)) {
            return false;
        }

        $bits    = (int) $bits;
        $max     = strlen($ip_bin) * 8;
        if ($bits < 0 || $bits > $max) {
            return false;
        }

        $whole_bytes = intdiv($bits, 8);
        $spare_bits  = $bits % 8;

        if ($whole_bytes > 0 && strncmp($ip_bin, $subnet_bin, $whole_bytes) !== 0) {
            return false;
        }

        if ($spare_bits === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $spare_bits)) & 0xFF);

        return ($ip_bin[$whole_bytes] & $mask) === ($subnet_bin[$whole_bytes] & $mask);
    }

    /**
     * Resolve the failed-authentication limits, filterable per source.
     *
     * @param string $source Source key.
     * @return array{max:int,window:int,site_max:int,site_window:int}
     */
    private function get_auth_failure_limits($source) {
        $defaults = [
            'max'         => self::AUTH_FAILURE_LIMIT,
            'window'      => self::AUTH_FAILURE_WINDOW,
            'site_max'    => self::AUTH_FAILURE_SITE_LIMIT,
            'site_window' => self::AUTH_FAILURE_WINDOW,
        ];

        $limits = apply_filters('metasync_mcp_auth_failure_rate_limit', $defaults, $source);
        if (!is_array($limits)) {
            $limits = $defaults;
        }

        return [
            'max'         => max(1, isset($limits['max']) ? (int) $limits['max'] : $defaults['max']),
            'window'      => max(1, isset($limits['window']) ? (int) $limits['window'] : $defaults['window']),
            'site_max'    => max(1, isset($limits['site_max']) ? (int) $limits['site_max'] : $defaults['site_max']),
            'site_window' => max(1, isset($limits['site_window']) ? (int) $limits['site_window'] : $defaults['site_window']),
        ];
    }

    /**
     * Transient name for a failed-authentication bucket.
     *
     * Hashing keeps the name within WordPress's length limit and keeps the
     * raw source address out of the options table.
     *
     * @param string $bucket Bucket identifier.
     * @return string
     */
    private function get_auth_bucket_key($bucket) {
        return self::AUTH_FAILURE_PREFIX . substr(hash('sha256', $bucket), 0, 40);
    }

    /**
     * Read a failed-authentication bucket, treating an elapsed window as empty.
     *
     * @param string $bucket Bucket identifier.
     * @return array{attempts:int,expires_at:int}
     */
    private function read_auth_bucket($bucket) {
        $data = get_transient($this->get_auth_bucket_key($bucket));
        $now  = time();

        if (!is_array($data)
            || !isset($data['attempts'], $data['expires_at'])
            || (int) $data['expires_at'] <= $now
        ) {
            return ['attempts' => 0, 'expires_at' => 0];
        }

        return [
            'attempts'   => (int) $data['attempts'],
            'expires_at' => (int) $data['expires_at'],
        ];
    }

    /**
     * Increment a failed-authentication bucket, escalating the lockout.
     *
     * Once the threshold is crossed the window is extended, doubling for each
     * further block of failures and capped at AUTH_FAILURE_MAX_LOCKOUT, so a
     * caller that keeps hammering waits progressively longer.
     *
     * @param string $bucket Bucket identifier.
     * @param int    $limit  Attempts allowed within the window.
     * @param int    $window Base window in seconds.
     * @return void
     */
    private function bump_auth_bucket($bucket, $limit, $window) {
        $state    = $this->read_auth_bucket($bucket);
        $now      = time();
        $attempts = $state['attempts'] + 1;
        $expires  = $state['expires_at'] > $now ? $state['expires_at'] : $now + $window;

        if ($attempts >= $limit) {
            $tier    = intdiv($attempts - $limit, $limit);
            $lockout = min($window * (1 << min($tier, 10)), self::AUTH_FAILURE_MAX_LOCKOUT);
            $expires = max($expires, $now + $lockout);
        }

        set_transient(
            $this->get_auth_bucket_key($bucket),
            ['attempts' => $attempts, 'expires_at' => $expires],
            max(1, $expires - $now)
        );
    }

    /**
     * Record a failed authentication attempt.
     *
     * Increments both the per-source bucket and the site-wide bucket. The
     * site-wide counter is the backstop for a caller that rotates its source
     * address faster than the per-source bucket can hold it.
     *
     * @param string $source Source key from get_auth_source_key().
     * @return void
     */
    private function record_failed_auth_attempt($source) {
        $limits = $this->get_auth_failure_limits($source);

        $this->bump_auth_bucket($source, $limits['max'], $limits['window']);
        $this->bump_auth_bucket(
            self::AUTH_FAILURE_SITE_BUCKET,
            $limits['site_max'],
            $limits['site_window']
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Log the bucket digest only — never the presented credential.
            error_log(sprintf(
                'MetaSync MCP: failed authentication attempt (source=%s)',
                substr(hash('sha256', $source), 0, 12)
            ));
        }
    }

    /**
     * Check whether the caller has exhausted the failed-authentication budget.
     *
     * When $record is true the attempt is written to both buckets *before*
     * the limit is evaluated, so the request that crosses the threshold is
     * itself counted rather than being handed out for free.
     *
     * The site-wide bucket is only consulted for AUTH_SCOPE_ALL. Recording,
     * when requested, always feeds both buckets regardless of scope.
     *
     * @param string $source Source key from get_auth_source_key().
     * @param bool   $record Record this attempt before evaluating.
     * @param string $scope  AUTH_SCOPE_ALL or AUTH_SCOPE_SOURCE.
     * @return true|WP_Error WP_Error carrying HTTP 429 when locked out.
     */
    private function check_rate_limit($source, $record = false, $scope = self::AUTH_SCOPE_ALL) {
        if ($record) {
            $this->record_failed_auth_attempt($source);
        }

        $limits = $this->get_auth_failure_limits($source);
        $now    = time();

        $buckets = [[$source, $limits['max']]];
        if ($scope !== self::AUTH_SCOPE_SOURCE) {
            $buckets[] = [self::AUTH_FAILURE_SITE_BUCKET, $limits['site_max']];
        }

        foreach ($buckets as $bucket) {
            list($name, $limit) = $bucket;

            $state = $this->read_auth_bucket($name);
            if ($state['attempts'] < $limit) {
                continue;
            }

            $retry_after = max(1, $state['expires_at'] - $now);

            return new WP_Error(
                'too_many_requests',
                'Too many failed authentication attempts. Try again later.',
                [
                    'status'      => 429,
                    'retry_after' => $retry_after,
                ]
            );
        }

        return true;
    }

    /**
     * Deepen an already-active lockout for a source that keeps knocking.
     *
     * Only the per-source bucket is advanced. Feeding the site-wide bucket
     * here would let a single blocked caller drive the site over its ceiling
     * and lock out everybody else, turning a brute-force defence into a
     * denial-of-service lever.
     *
     * @param string $source Source key from get_auth_source_key().
     * @return void
     */
    private function escalate_auth_lockout($source) {
        $limits = $this->get_auth_failure_limits($source);
        $this->bump_auth_bucket($source, $limits['max'], $limits['window']);
    }

    /**
     * Clear the failed-authentication counter for a source.
     *
     * Called on successful authentication so a legitimate client is never
     * penalised for earlier failures. The site-wide counter is deliberately
     * left intact: one success must not wipe the evidence of a distributed
     * attack in progress.
     *
     * @param string $source Source key from get_auth_source_key().
     * @return void
     */
    private function clear_auth_attempts($source) {
        delete_transient($this->get_auth_bucket_key($source));
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

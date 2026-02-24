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

        // Check user capability
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
     * Supports both WordPress nonce (same-origin) and plugin auth token (external clients)
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

        return new WP_Error(
            'authentication_failed',
            'Authentication required. Provide either X-WP-Nonce or X-API-Key header with your plugin auth token.',
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
}

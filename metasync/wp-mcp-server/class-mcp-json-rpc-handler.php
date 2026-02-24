<?php
/**
 * MCP JSON-RPC 2.0 Handler
 *
 * Handles parsing, validation, and routing of JSON-RPC 2.0 requests
 * according to the Model Context Protocol specification.
 *
 * @package    MetaSync
 * @subpackage MCP_Server
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCP_JSON_RPC_Handler {

    /**
     * JSON-RPC version
     */
    const JSONRPC_VERSION = '2.0';

    /**
     * Error codes (JSON-RPC 2.0 standard + custom)
     */
    const ERROR_PARSE_ERROR = -32700;
    const ERROR_INVALID_REQUEST = -32600;
    const ERROR_METHOD_NOT_FOUND = -32601;
    const ERROR_INVALID_PARAMS = -32602;
    const ERROR_INTERNAL_ERROR = -32603;
    const ERROR_SERVER_ERROR = -32000;

    /**
     * Method handlers
     *
     * @var array
     */
    private $handlers = [];

    /**
     * Constructor
     */
    public function __construct() {
        // Register default handlers
        $this->register_handler('initialize', [$this, 'handle_initialize']);
        $this->register_handler('ping', [$this, 'handle_ping']);
    }

    /**
     * Register a method handler
     *
     * @param string   $method   Method name
     * @param callable $callback Handler callback
     */
    public function register_handler($method, $callback) {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException("Handler for method '{$method}' must be callable");
        }
        $this->handlers[$method] = $callback;
    }

    /**
     * Handle incoming JSON-RPC request
     *
     * @param string $request_body Raw request body
     * @return array Response array
     */
    public function handle_request($request_body) {
        // Parse JSON
        $request = json_decode($request_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->error_response(null, self::ERROR_PARSE_ERROR, 'Parse error');
        }

        // Validate request structure
        if (!$this->validate_request($request)) {
            return $this->error_response(
                isset($request['id']) ? $request['id'] : null,
                self::ERROR_INVALID_REQUEST,
                'Invalid Request'
            );
        }

        $method = $request['method'];
        $params = isset($request['params']) ? $request['params'] : [];
        $id = isset($request['id']) ? $request['id'] : null;

        // Check if handler exists
        if (!isset($this->handlers[$method])) {
            return $this->error_response($id, self::ERROR_METHOD_NOT_FOUND, "Method not found: {$method}");
        }

        try {
            // Call handler
            $result = call_user_func($this->handlers[$method], $params);
            return $this->success_response($id, $result);
        } catch (InvalidArgumentException $e) {
            return $this->error_response($id, self::ERROR_INVALID_PARAMS, $e->getMessage());
        } catch (Exception $e) {
            // Log error
            error_log('MCP JSON-RPC Error: ' . $e->getMessage());
            return $this->error_response($id, self::ERROR_INTERNAL_ERROR, 'Internal error');
        }
    }

    /**
     * Validate JSON-RPC request structure
     *
     * @param mixed $request Request data
     * @return bool
     */
    private function validate_request($request) {
        if (!is_array($request)) {
            return false;
        }

        // Must have jsonrpc, method
        if (!isset($request['jsonrpc']) || $request['jsonrpc'] !== self::JSONRPC_VERSION) {
            return false;
        }

        if (!isset($request['method']) || !is_string($request['method'])) {
            return false;
        }

        // Params are optional but must be array or object if present
        if (isset($request['params']) && !is_array($request['params'])) {
            return false;
        }

        return true;
    }

    /**
     * Create success response
     *
     * @param mixed $id     Request ID
     * @param mixed $result Result data
     * @return array
     */
    public function success_response($id, $result) {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id' => $id,
            'result' => $result
        ];
    }

    /**
     * Create error response
     *
     * @param mixed  $id      Request ID
     * @param int    $code    Error code
     * @param string $message Error message
     * @param mixed  $data    Optional error data
     * @return array
     */
    public function error_response($id, $code, $message, $data = null) {
        $error = [
            'code' => $code,
            'message' => $message
        ];

        if ($data !== null) {
            $error['data'] = $data;
        }

        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id' => $id,
            'error' => $error
        ];
    }

    /**
     * Handle initialize method
     *
     * @param array $params Request parameters
     * @return array
     */
    private function handle_initialize($params) {
        return [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => [
                    'list' => true,
                    'call' => true
                ]
            ],
            'serverInfo' => [
                'name' => 'metasync-wordpress-mcp',
                'version' => METASYNC_VERSION
            ]
        ];
    }

    /**
     * Handle ping method
     *
     * @param array $params Request parameters
     * @return array
     */
    private function handle_ping($params) {
        return ['status' => 'ok'];
    }
}

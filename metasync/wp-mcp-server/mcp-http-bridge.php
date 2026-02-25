#!/usr/bin/env php
<?php
/**
 * MCP HTTP Bridge for WordPress (PHP Implementation)
 *
 * This bridge allows MCP clients to communicate with WordPress MCP tools
 * via HTTP using JSON-RPC protocol.
 *
 * Usage:
 *   # Run standalone HTTP server
 *   php mcp-http-bridge.php --port=3000 --host=localhost
 *
 *   # Or use PHP built-in server
 *   php -S localhost:3000 mcp-http-bridge.php
 *
 * Configuration (Claude Desktop/Code):
 *   {
 *     "mcpServers": {
 *       "wordpress-metasync": {
 *         "url": "http://localhost:3000",
 *         "transport": "http"
 *       }
 *     }
 *   }
 *
 * Environment Variables:
 *   WP_MCP_PORT=3000          - HTTP server port
 *   WP_MCP_HOST=localhost     - HTTP server host
 *   WP_MCP_API_KEY=secret     - Optional API key for authentication
 *
 * @package    Metasync
 * @subpackage Metasync/wp-mcp-server
 * @since      2.0.0
 */

// Check if running as standalone server or via PHP built-in server
$is_builtin_server = php_sapi_name() === 'cli-server';
$is_cli = php_sapi_name() === 'cli';

if (!$is_builtin_server && !$is_cli) {
	http_response_code(500);
	die("Error: This script must be run from the command line or via PHP built-in server\n");
}

// Suppress unnecessary WordPress output
define('WP_CLI', true);
define('DOING_AJAX', true);
define('WP_USE_THEMES', false);
define('DISABLE_WP_CRON', true);

// Determine WordPress root path
// Try multiple possible locations for WordPress installation

$possible_paths = [
	// Docker container path
	'/var/www/html/wp-load.php',
	// Standard installation (5 levels up from script)
	dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/wp-load.php',
	// Alternative: 4 levels up
	dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php',
	// Bedrock/custom structure
	dirname(dirname(dirname(dirname(dirname(dirname(__FILE__)))))) . '/wp-load.php',
];

$wp_load_path = null;
foreach ($possible_paths as $path) {
	if (file_exists($path)) {
		$wp_load_path = $path;
		break;
	}
}

if (!$wp_load_path) {
	$error_msg = "Error: Cannot find WordPress wp-load.php\nTried: " . implode(', ', $possible_paths);
	if ($is_builtin_server) {
		http_response_code(500);
		die($error_msg);
	} else {
		fwrite(STDERR, $error_msg . "\n");
		exit(1);
	}
}

// Bootstrap WordPress
require_once $wp_load_path;

// Verify MCP server is available
global $metasync_mcp_server;
if (!isset($metasync_mcp_server) || !$metasync_mcp_server) {
	if ($is_builtin_server) {
		http_response_code(500);
		die("Error: Metasync MCP server not initialized");
	} else {
		fwrite(STDERR, "Error: Metasync MCP server not initialized\n");
		exit(1);
	}
}

// Disable output buffering
ob_implicit_flush(true);
while (ob_get_level()) {
	ob_end_clean();
}

// If running via PHP built-in server, handle the request immediately
if ($is_builtin_server) {
	handle_http_request();
	exit(0);
}

// Otherwise, start standalone HTTP server
start_standalone_server();

/**
 * Start standalone HTTP server (socket-based)
 */
function start_standalone_server() {
	// Parse command line arguments
	$options = getopt('', ['port:', 'host:']);
	$port = $options['port'] ?? getenv('WP_MCP_PORT') ?: 3000;
	$host = $options['host'] ?? getenv('WP_MCP_HOST') ?: 'localhost';

	echo "Starting MCP HTTP Bridge (PHP)...\n";
	echo "WordPress version: " . get_bloginfo('version') . "\n";
	echo "Metasync version: " . (defined('METASYNC_VERSION') ? METASYNC_VERSION : 'unknown') . "\n";
	echo "Listening on http://{$host}:{$port}\n";
	echo "Press Ctrl+C to stop\n\n";

	// Create socket
	$socket = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);

	if (!$socket) {
		fwrite(STDERR, "Error: Could not create socket: $errstr ($errno)\n");
		exit(1);
	}

	// Accept connections in loop
	while (true) {
		$client = @stream_socket_accept($socket, -1);
		if (!$client) {
			continue;
		}

		// Read HTTP request
		$request = '';
		while (!feof($client)) {
			$line = fgets($client);
			$request .= $line;
			if (trim($line) === '') {
				// Headers ended, read body if present
				$headers = parse_http_headers($request);
				if (isset($headers['content-length'])) {
					$body = fread($client, (int)$headers['content-length']);
					$request .= $body;
				}
				break;
			}
		}

		// Process request
		$response = process_http_request($request);

		// Send response
		fwrite($client, $response);
		fclose($client);
	}

	fclose($socket);
}

/**
 * Handle HTTP request (for PHP built-in server)
 */
function handle_http_request() {
	// Set CORS headers
	set_cors_headers();

	// Handle OPTIONS (preflight)
	if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
		http_response_code(204);
		exit(0);
	}

	// Health check endpoint
	if ($_SERVER['REQUEST_METHOD'] === 'GET' && $_SERVER['REQUEST_URI'] === '/health') {
		handle_health_check();
		exit(0);
	}

	// MCP endpoint - must be POST
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		http_response_code(405);
		header('Content-Type: application/json');
		echo json_encode(['error' => 'Method not allowed. Use POST for MCP requests.']);
		exit(0);
	}

	// Check authentication if API key is set
	check_authentication();

	// Read request body
	$request_body = file_get_contents('php://input');

	if (empty($request_body)) {
		http_response_code(400);
		header('Content-Type: application/json');
		echo json_encode(['error' => 'Empty request body']);
		exit(0);
	}

	try {
		// Parse JSON-RPC request
		$request = json_decode($request_body, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception('Invalid JSON: ' . json_last_error_msg());
		}

		// Process MCP request
		$response = process_mcp_request($request);

		// Send response
		http_response_code(200);
		header('Content-Type: application/json');
		echo json_encode($response);

	} catch (Exception $e) {
		http_response_code(500);
		header('Content-Type: application/json');
		echo json_encode([
			'jsonrpc' => '2.0',
			'id' => isset($request['id']) ? $request['id'] : null,
			'error' => [
				'code' => -32603,
				'message' => $e->getMessage()
			]
		]);
	}
}

/**
 * Process HTTP request (for standalone server)
 */
function process_http_request($http_request) {
	$lines = explode("\r\n", $http_request);
	$request_line = $lines[0];
	$parts = explode(' ', $request_line);

	$method = $parts[0] ?? 'GET';
	$path = $parts[1] ?? '/';

	// Parse headers
	$headers = parse_http_headers($http_request);

	// Set default headers
	$response_headers = [
		'HTTP/1.1 200 OK',
		'Content-Type: application/json',
		'Access-Control-Allow-Origin: *',
		'Access-Control-Allow-Methods: GET, POST, OPTIONS',
		'Access-Control-Allow-Headers: Content-Type, X-API-Key',
	];

	// Handle OPTIONS (preflight)
	if ($method === 'OPTIONS') {
		$response_headers[0] = 'HTTP/1.1 204 No Content';
		return implode("\r\n", $response_headers) . "\r\n\r\n";
	}

	// Health check
	if ($method === 'GET' && $path === '/health') {
		$body = json_encode([
			'status' => 'ok',
			'service' => 'wordpress-metasync-mcp',
			'wordpress_version' => get_bloginfo('version'),
			'metasync_version' => defined('METASYNC_VERSION') ? METASYNC_VERSION : 'unknown',
			'timestamp' => time()
		]);

		$response_headers[] = 'Content-Length: ' . strlen($body);
		return implode("\r\n", $response_headers) . "\r\n\r\n" . $body;
	}

	// MCP endpoint - must be POST
	if ($method !== 'POST') {
		$response_headers[0] = 'HTTP/1.1 405 Method Not Allowed';
		$body = json_encode(['error' => 'Method not allowed']);
		$response_headers[] = 'Content-Length: ' . strlen($body);
		return implode("\r\n", $response_headers) . "\r\n\r\n" . $body;
	}

	// Check authentication
	$api_key = getenv('WP_MCP_API_KEY');
	if ($api_key && (!isset($headers['x-api-key']) || $headers['x-api-key'] !== $api_key)) {
		$response_headers[0] = 'HTTP/1.1 401 Unauthorized';
		$body = json_encode(['error' => 'Invalid or missing API key']);
		$response_headers[] = 'Content-Length: ' . strlen($body);
		return implode("\r\n", $response_headers) . "\r\n\r\n" . $body;
	}

	// Extract request body
	$body_start = strpos($http_request, "\r\n\r\n");
	$request_body = $body_start !== false ? substr($http_request, $body_start + 4) : '';

	if (empty($request_body)) {
		$response_headers[0] = 'HTTP/1.1 400 Bad Request';
		$body = json_encode(['error' => 'Empty request body']);
		$response_headers[] = 'Content-Length: ' . strlen($body);
		return implode("\r\n", $response_headers) . "\r\n\r\n" . $body;
	}

	try {
		// Parse JSON-RPC request
		$request = json_decode($request_body, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception('Invalid JSON: ' . json_last_error_msg());
		}

		// Process MCP request
		$response = process_mcp_request($request);
		$body = json_encode($response);

		$response_headers[] = 'Content-Length: ' . strlen($body);
		return implode("\r\n", $response_headers) . "\r\n\r\n" . $body;

	} catch (Exception $e) {
		$response_headers[0] = 'HTTP/1.1 500 Internal Server Error';
		$body = json_encode([
			'jsonrpc' => '2.0',
			'id' => isset($request['id']) ? $request['id'] : null,
			'error' => [
				'code' => -32603,
				'message' => $e->getMessage()
			]
		]);
		$response_headers[] = 'Content-Length: ' . strlen($body);
		return implode("\r\n", $response_headers) . "\r\n\r\n" . $body;
	}
}

/**
 * Parse HTTP headers
 */
function parse_http_headers($http_request) {
	$headers = [];
	$lines = explode("\r\n", $http_request);

	foreach ($lines as $line) {
		if (strpos($line, ':') !== false) {
			list($key, $value) = explode(':', $line, 2);
			$headers[strtolower(trim($key))] = trim($value);
		}
	}

	return $headers;
}

/**
 * Set CORS headers
 */
function set_cors_headers() {
	header('Access-Control-Allow-Origin: *');
	header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
	header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
}

/**
 * Check authentication
 */
function check_authentication() {
	$api_key = getenv('WP_MCP_API_KEY');
	if (!$api_key) {
		return; // No authentication required
	}

	$provided_key = $_SERVER['HTTP_X_API_KEY'] ?? '';

	if ($provided_key !== $api_key) {
		http_response_code(401);
		header('Content-Type: application/json');
		echo json_encode(['error' => 'Invalid or missing API key']);
		exit(0);
	}
}

/**
 * Handle health check
 */
function handle_health_check() {
	global $metasync_mcp_server;

	$tools_count = count($metasync_mcp_server->get_tools());

	http_response_code(200);
	header('Content-Type: application/json');
	echo json_encode([
		'status' => 'ok',
		'service' => 'wordpress-metasync-mcp',
		'wordpress_version' => get_bloginfo('version'),
		'metasync_version' => defined('METASYNC_VERSION') ? METASYNC_VERSION : 'unknown',
		'tools_count' => $tools_count,
		'timestamp' => time()
	]);
}

/**
 * Process MCP JSON-RPC request
 *
 * @param array $request JSON-RPC request
 * @return array JSON-RPC response
 */
function process_mcp_request($request) {
	global $metasync_mcp_server;

	$method = $request['method'] ?? '';
	$params = $request['params'] ?? [];
	$id = $request['id'] ?? null;

	switch ($method) {
		case 'initialize':
			return handle_initialize($id, $params);

		case 'notifications/initialized':
			// Client confirms initialization - no response needed for notification
			return null;

		case 'tools/list':
			return handle_tools_list($id);

		case 'tools/call':
			return handle_tools_call($id, $params);

		case 'ping':
			return [
				'jsonrpc' => '2.0',
				'id' => $id,
				'result' => [
					'status' => 'ok',
					'timestamp' => time()
				]
			];

		default:
			throw new Exception("Unknown method: $method");
	}
}

/**
 * Handle initialize request
 */
function handle_initialize($id, $params) {
	$client_info = $params['clientInfo'] ?? [];

	return [
		'jsonrpc' => '2.0',
		'id' => $id,
		'result' => [
			'protocolVersion' => '2024-11-05',
			'capabilities' => [
				'tools' => (object)[]
			],
			'serverInfo' => [
				'name' => 'wordpress-metasync',
				'version' => defined('METASYNC_VERSION') ? METASYNC_VERSION : '2.0.0'
			]
		]
	];
}

/**
 * Handle tools/list request
 */
function handle_tools_list($id) {
	global $metasync_mcp_server;

	$tools = [];
	$tool_objects = $metasync_mcp_server->get_tools();

	foreach ($tool_objects as $tool) {
		$tools[] = [
			'name' => $tool->get_name(),
			'description' => $tool->get_description(),
			'inputSchema' => $tool->get_input_schema()
		];
	}

	return [
		'jsonrpc' => '2.0',
		'id' => $id,
		'result' => [
			'tools' => $tools
		]
	];
}

/**
 * Handle tools/call request
 */
function handle_tools_call($id, $params) {
	global $metasync_mcp_server;

	$tool_name = $params['name'] ?? '';
	$arguments = $params['arguments'] ?? [];

	if (empty($tool_name)) {
		throw new Exception('Tool name is required');
	}

	// Find tool
	$tool = $metasync_mcp_server->get_tool($tool_name);
	if (!$tool) {
		throw new Exception("Tool not found: $tool_name");
	}

	// Execute tool
	$result = $tool->execute($arguments);

	// Format result as MCP response
	$content = [];

	if (is_array($result) || is_object($result)) {
		$content[] = [
			'type' => 'text',
			'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		];
	} else {
		$content[] = [
			'type' => 'text',
			'text' => (string)$result
		];
	}

	return [
		'jsonrpc' => '2.0',
		'id' => $id,
		'result' => [
			'content' => $content,
			'isError' => false
		]
	];
}

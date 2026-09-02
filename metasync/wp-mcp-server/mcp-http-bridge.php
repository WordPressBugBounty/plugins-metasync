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
 *   WP_MCP_API_KEY=secret     - Required API key for authentication
 *
 * @package    Metasync
 * @subpackage Metasync/wp-mcp-server
 * @since      2.0.0
 */

// Signal to metasync_init_mcp_server() that MCP init must not be skipped.
define('METASYNC_MCP_BRIDGE', true);

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

	// Authenticate every bridge request.
	check_authentication();

	// Read request body
	$request_body = file_get_contents('php://input');

	if (empty($request_body)) {
		http_response_code(400);
		header('Content-Type: application/json');
		echo json_encode(['error' => 'Empty request body']);
		exit(0);
	}

	$result = process_mcp_request($request_body);
	header('Content-Type: application/json');
	http_response_code($result['status']);
	foreach ($result['headers'] as $name => $value) {
		header($name . ': ' . $value);
	}
	if ($result['body'] !== null) {
		echo json_encode($result['body']);
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

	// Check authentication — deny by default when no API key is configured
	$auth_result = authenticate_bridge_key($headers['x-api-key'] ?? '');
	if (is_wp_error($auth_result)) {
		$response_headers[0] = 'HTTP/1.1 401 Unauthorized';
		$body = json_encode(['error' => $auth_result->get_error_message()]);
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

	$result = process_mcp_request($request_body);
	if ($result['status'] === 429) {
		$response_headers[0] = 'HTTP/1.1 429 Too Many Requests';
	} elseif ($result['status'] >= 400) {
		$response_headers[0] = 'HTTP/1.1 ' . $result['status'] . ' Error';
	}
	foreach ($result['headers'] as $name => $value) {
		$response_headers[] = $name . ': ' . $value;
	}
	$body = $result['body'] === null ? '' : json_encode($result['body']);
	$response_headers[] = 'Content-Length: ' . strlen($body);
	return implode("\r\n", $response_headers) . "\r\n\r\n" . $body;
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
	$allowed_origins = array();
	if (function_exists('home_url')) {
		$allowed_origins[] = home_url();
	}
	if (function_exists('site_url')) {
		$allowed_origins[] = site_url();
	}
	$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
	if (!empty($origin) && in_array($origin, $allowed_origins, true)) {
		header('Access-Control-Allow-Origin: ' . $origin);
	}
	header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
	header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
}

/**
 * Check authentication
 */
function check_authentication() {
	$auth_result = authenticate_bridge_key($_SERVER['HTTP_X_API_KEY'] ?? '');
	if (is_wp_error($auth_result)) {
		http_response_code(401);
		header('Content-Type: application/json');
		echo json_encode(['error' => $auth_result->get_error_message()]);
		exit(0);
	}
}

/**
 * Handle health check
 */
function handle_health_check() {
	global $metasync_mcp_server;

	$tools_count = $metasync_mcp_server->get_tool_registry()->get_tool_count();

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
 * Authenticate a bridge request through the shared MCP server.
 *
 * @param string $provided_key API key supplied by the transport.
 * @return true|WP_Error
 */
function authenticate_bridge_key($provided_key) {
	global $metasync_mcp_server;

	return $metasync_mcp_server->authenticate_bridge_request($provided_key);
}

/**
 * Process a raw JSON-RPC request through the shared MCP server path.
 *
 * @param string $request_body Raw JSON-RPC request body.
 * @return array Processing result with body, status, and headers.
 */
function process_mcp_request($request_body) {
	global $metasync_mcp_server;

	return $metasync_mcp_server->process_json_rpc_request($request_body);
}

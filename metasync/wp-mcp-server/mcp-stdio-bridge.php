#!/usr/bin/env php
<?php
/**
 * MCP stdio Bridge for WordPress (PHP Implementation)
 *
 * This bridge allows MCP clients (Claude Desktop, Claude Code) to communicate
 * with WordPress MCP tools via stdio (standard input/output).
 *
 * Usage:
 *   php mcp-stdio-bridge.php
 *
 * Configuration (Claude Desktop):
 *   {
 *     "mcpServers": {
 *       "wordpress-metasync": {
 *         "command": "php",
 *         "args": ["/path/to/mcp-stdio-bridge.php"]
 *       }
 *     }
 *   }
 *
 * @package    Metasync
 * @subpackage Metasync/wp-mcp-server
 * @since      2.0.0
 */

// Ensure we're running in CLI mode
if (php_sapi_name() !== 'cli') {
	fwrite(STDERR, "Error: This script must be run from the command line\n");
	exit(1);
}

// Suppress unnecessary WordPress output
// NOTE: Don't define WP_CLI constant - it causes Elementor to crash expecting WP_CLI class
define('DOING_AJAX', true);
define('WP_USE_THEMES', false);
define('DISABLE_WP_CRON', true);
define('METASYNC_MCP_BRIDGE', true); // Custom flag for our bridge

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
	fwrite(STDERR, "Error: Cannot find WordPress wp-load.php\n");
	fwrite(STDERR, "Tried the following paths:\n");
	foreach ($possible_paths as $path) {
		fwrite(STDERR, "  - $path\n");
	}
	fwrite(STDERR, "\nIf using Docker, run via mcp-stdio-bridge-docker.sh wrapper\n");
	exit(1);
}

// Bootstrap WordPress
require_once $wp_load_path;

// Verify MCP server is available
global $metasync_mcp_server;
if (!isset($metasync_mcp_server) || !$metasync_mcp_server) {
	fwrite(STDERR, "Error: Metasync MCP server not initialized\n");
	exit(1);
}

// Disable output buffering for real-time communication
ob_implicit_flush(true);
while (ob_get_level()) {
	ob_end_clean();
}

// Log startup to stderr (for debugging)
fwrite(STDERR, "MCP stdio bridge started (PHP)\n");
fwrite(STDERR, "WordPress version: " . get_bloginfo('version') . "\n");
fwrite(STDERR, "Metasync version: " . (defined('METASYNC_VERSION') ? METASYNC_VERSION : 'unknown') . "\n");
fwrite(STDERR, "Listening on stdin...\n");

// Main event loop - read from stdin, process, write to stdout
while (!feof(STDIN)) {
	$line = fgets(STDIN);

	// Skip empty lines
	if ($line === false || trim($line) === '') {
		continue;
	}

	try {
		// Parse JSON-RPC request
		$request = json_decode($line, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception('Invalid JSON: ' . json_last_error_msg());
		}

		// Validate request structure
		if (!isset($request['jsonrpc']) || $request['jsonrpc'] !== '2.0') {
			throw new Exception('Invalid JSON-RPC version');
		}

		if (!isset($request['method'])) {
			throw new Exception('Missing method in request');
		}

		// Process MCP request
		$response = process_mcp_request($request);

		// Write JSON-RPC response to stdout
		$response_json = json_encode($response);
		fwrite(STDOUT, $response_json . "\n");
		fflush(STDOUT);

	} catch (Exception $e) {
		// Write error to stderr (for debugging)
		fwrite(STDERR, "Error processing request: " . $e->getMessage() . "\n");
		fwrite(STDERR, "Request: " . $line . "\n");

		// Send JSON-RPC error response
		$error_response = [
			'jsonrpc' => '2.0',
			'id' => isset($request['id']) ? $request['id'] : null,
			'error' => [
				'code' => -32603,
				'message' => $e->getMessage(),
				'data' => [
					'line' => $line,
					'trace' => $e->getTraceAsString()
				]
			]
		];

		fwrite(STDOUT, json_encode($error_response) . "\n");
		fflush(STDOUT);
	}
}

fwrite(STDERR, "MCP stdio bridge terminated\n");

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
			// Client confirms initialization - no response needed
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

	fwrite(STDERR, "Client connected: " . json_encode($client_info) . "\n");

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

	fwrite(STDERR, "Listing " . count($tool_objects) . " tools\n");

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

	fwrite(STDERR, "Calling tool: $tool_name\n");

	// Find tool
	$tool = $metasync_mcp_server->get_tool($tool_name);
	if (!$tool) {
		throw new Exception("Tool not found: $tool_name");
	}

	// Execute tool
	$start_time = microtime(true);
	$result = $tool->execute($arguments);
	$execution_time = round((microtime(true) - $start_time) * 1000, 2);

	fwrite(STDERR, "Tool executed in {$execution_time}ms\n");

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

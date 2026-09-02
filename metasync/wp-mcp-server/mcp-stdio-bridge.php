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

$auth_result = $metasync_mcp_server->authenticate_bridge_request(getenv('WP_MCP_API_KEY'));
if (is_wp_error($auth_result)) {
	fwrite(STDERR, 'Error: ' . $auth_result->get_error_message() . "\n");
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

	$result = $metasync_mcp_server->process_json_rpc_request($line);
	if ($result['body'] !== null) {
		fwrite(STDOUT, json_encode($result['body']) . "\n");
		fflush(STDOUT);
	}
}

fwrite(STDERR, "MCP stdio bridge terminated\n");

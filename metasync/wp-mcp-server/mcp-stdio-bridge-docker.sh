#!/bin/bash
#
# Docker wrapper for PHP stdio MCP Bridge
#
# This script runs the PHP stdio bridge inside the WordPress Docker container
# where it has access to wp-load.php and the full WordPress installation.
#
# Usage:
#   ./mcp-stdio-bridge-docker.sh
#
# Configuration (Claude Desktop/Code):
#   {
#     "mcpServers": {
#       "wordpress-metasync": {
#         "command": "/path/to/wp-content/plugins/metasync/wp-mcp-server/mcp-stdio-bridge-docker.sh"
#       }
#     }
#   }
#

# Find the WordPress container name dynamically
# Try common patterns: wordpress, web-wordpress-1, etc.
CONTAINER_NAME=$(docker ps --format '{{.Names}}' | grep -E '(wordpress|wp)' | head -1)

if [ -z "$CONTAINER_NAME" ]; then
    echo "Error: Cannot find running WordPress container" >&2
    echo "Available containers:" >&2
    docker ps --format '{{.Names}}' >&2
    exit 1
fi

# Run the PHP bridge inside the container
# Pass stdin/stdout through to the container
# stderr is passed through for debugging (appears in Claude Desktop logs)
exec docker exec -i "$CONTAINER_NAME" \
    php /var/www/html/wp-content/plugins/metasync/wp-mcp-server/mcp-stdio-bridge.php

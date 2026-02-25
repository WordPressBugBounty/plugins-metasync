#!/usr/bin/env node

/**
 * MCP HTTP Bridge for MetaSync WordPress Plugin
 *
 * This bridge allows Claude Desktop to communicate with the MetaSync MCP server
 * running on WordPress via HTTP/REST API.
 */

import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  ListToolsRequestSchema,
  CallToolRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';

// Configuration from environment variables
const WORDPRESS_URL = process.env.WORDPRESS_URL || 'http://localhost';
const WORDPRESS_API_KEY = process.env.WORDPRESS_API_KEY;

if (!WORDPRESS_API_KEY) {
  console.error('Error: WORDPRESS_API_KEY environment variable is required');
  process.exit(1);
}

// MCP endpoint (single endpoint with JSON-RPC)
const MCP_ENDPOINT = `${WORDPRESS_URL}/wp-json/metasync/v1/mcp`;

// Request ID counter for JSON-RPC
let requestId = 1;

/**
 * Make JSON-RPC request to WordPress MCP endpoint
 */
async function callWordPressMCP(method, params = {}) {
  const payload = {
    jsonrpc: '2.0',
    id: requestId++,
    method: method,
    params: params
  };

  const options = {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-API-Key': WORDPRESS_API_KEY,
    },
    body: JSON.stringify(payload)
  };

  try {
    const response = await fetch(MCP_ENDPOINT, options);

    if (!response.ok) {
      const errorText = await response.text();
      throw new Error(`HTTP ${response.status}: ${errorText}`);
    }

    const jsonRpcResponse = await response.json();

    // Handle JSON-RPC error
    if (jsonRpcResponse.error) {
      throw new Error(jsonRpcResponse.error.message || 'Unknown JSON-RPC error');
    }

    // Return the result field from JSON-RPC response
    return jsonRpcResponse.result;
  } catch (error) {
    console.error(`[MCP Bridge] WordPress MCP Error: ${error.message}`);
    throw error;
  }
}

/**
 * Initialize MCP Server
 */
async function main() {
  console.error('[MCP Bridge] Initializing...');
  console.error(`[MCP Bridge] WordPress URL: ${WORDPRESS_URL}`);
  console.error(`[MCP Bridge] API Key: ${WORDPRESS_API_KEY.substring(0, 8)}...`);

  const server = new Server(
    {
      name: 'wordpress-metasync',
      version: '1.0.0',
    },
    {
      capabilities: {
        tools: {},
      },
    }
  );

  // Handle tools/list requests
  server.setRequestHandler(ListToolsRequestSchema, async () => {
    try {
      const result = await callWordPressMCP('tools/list');
      return result;
    } catch (error) {
      console.error('Error listing tools:', error);
      return { tools: [] };
    }
  });

  // Handle tools/call requests
  server.setRequestHandler(CallToolRequestSchema, async (request) => {
    try {
      console.error(`[MCP Bridge] Calling tool: ${request.params.name}`);
      const result = await callWordPressMCP('tools/call', {
        name: request.params.name,
        arguments: request.params.arguments || {}
      });
      return result;
    } catch (error) {
      console.error(`[MCP Bridge] Error calling tool:`, error);
      return {
        content: [
          {
            type: 'text',
            text: `Error: ${error.message}`,
          },
        ],
        isError: true,
      };
    }
  });

  // Connect to stdio transport
  const transport = new StdioServerTransport();
  await server.connect(transport);

  console.error('[MCP Bridge] ✓ Connected to Claude Desktop');
  console.error('[MCP Bridge] ✓ Ready to serve WordPress MCP tools');
}

main().catch((error) => {
  console.error('[MCP Bridge] Fatal error:', error);
  process.exit(1);
});

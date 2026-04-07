<?php
/**
 * REST Endpoint: SEO Inventory
 *
 * Registers GET /wp-json/metasync/v1/seo-inventory
 * Returns all published posts/pages with their SEO metadata in bulk.
 *
 * @package    MetaSync
 * @subpackage Includes
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_REST_SEO_Inventory {

    const REST_NAMESPACE = 'metasync/v1';
    const REST_ROUTE     = '/seo-inventory';

    /**
     * Register the REST route on rest_api_init.
     */
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register routes.
     */
    public function register_routes(): void {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_request'],
            'permission_callback' => [$this, 'check_permissions'],
            'args'                => $this->get_endpoint_args(),
        ]);
    }

    /**
     * Permission check — delegates to the MCP server's check_permissions().
     *
     * This reuses the exact same auth logic (API key, JWT, nonce) and ensures
     * constants like METASYNC_MCP_API_KEY_AUTH are defined correctly.
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_permissions(WP_REST_Request $request) {
        global $metasync_mcp_server;

        if ($metasync_mcp_server && method_exists($metasync_mcp_server, 'check_permissions')) {
            return $metasync_mcp_server->check_permissions($request);
        }

        return new WP_Error(
            'rest_forbidden',
            'MCP server not available for authentication.',
            ['status' => 500]
        );
    }

    /**
     * Handle the inventory request.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_request(WP_REST_Request $request): WP_REST_Response {
        $post_status = $request->get_param('post_status') ?? 'publish';

        // Sensitive statuses require edit_posts capability (matches MCP tool guard)
        $sensitive_statuses = ['private', 'draft', 'pending', 'any'];
        if (in_array($post_status, $sensitive_statuses, true) && !current_user_can('edit_posts')) {
            return new WP_REST_Response([
                'code'    => 'rest_forbidden',
                'message' => 'You need edit_posts capability to query non-public post statuses.',
            ], 403);
        }

        $args = [
            'post_type'      => $request->get_param('post_type') ?? 'any',
            'post_status'    => $post_status,
            'limit'          => $request->get_param('limit') ?? Metasync_SEO_Inventory_Builder::DEFAULT_LIMIT,
            'cursor'         => $request->get_param('cursor') ?? 0,
            'include_issues' => $request->get_param('include_issues') ?? true,
            'modified_after' => $request->get_param('modified_after') ?? '',
        ];

        $result = Metasync_SEO_Inventory_Builder::build($args);

        return new WP_REST_Response($result, 200);
    }

    /**
     * Define query parameter schema for the endpoint.
     *
     * @return array
     */
    private function get_endpoint_args(): array {
        return [
            'post_type' => [
                'type'              => 'string',
                'enum'              => ['post', 'page', 'any'],
                'default'           => 'any',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => 'Filter by post type.',
            ],
            'post_status' => [
                'type'              => 'string',
                'enum'              => ['publish', 'draft', 'pending', 'private', 'any'],
                'default'           => 'publish',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => 'Filter by post status.',
            ],
            'limit' => [
                'type'              => 'integer',
                'default'           => Metasync_SEO_Inventory_Builder::DEFAULT_LIMIT,
                'minimum'           => 1,
                'maximum'           => Metasync_SEO_Inventory_Builder::MAX_LIMIT,
                'sanitize_callback' => 'absint',
                'description'       => 'Number of items per page (max 500).',
            ],
            'cursor' => [
                'type'              => 'integer',
                'default'           => 0,
                'minimum'           => 0,
                'sanitize_callback' => 'absint',
                'description'       => 'Post ID to start after (keyset pagination).',
            ],
            'include_issues' => [
                'type'              => 'boolean',
                'default'           => true,
                'description'       => 'Include pre-computed SEO issue flags.',
            ],
            'modified_after' => [
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => 'Only return posts modified after this ISO 8601 date.',
            ],
        ];
    }
}

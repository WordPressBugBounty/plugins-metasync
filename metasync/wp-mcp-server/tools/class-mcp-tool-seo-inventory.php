<?php
/**
 * MCP Tool: SEO Inventory
 *
 * Bulk-fetches all posts/pages with their SEO metadata.
 * Delegates to Metasync_SEO_Inventory_Builder so the logic
 * is shared with the REST endpoint.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCP_Tool_List_Posts_SEO_Inventory extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_list_posts_seo_inventory';
    }

    public function get_description() {
        return 'Bulk list all posts/pages with their full SEO metadata (title, description, OG, twitter, schema, issue flags) — no crawling needed';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_type' => [
                    'type'        => 'string',
                    'description' => 'Filter by post type',
                    'enum'        => ['post', 'page', 'any'],
                    'default'     => 'any',
                ],
                'post_status' => [
                    'type'        => 'string',
                    'description' => 'Filter by post status',
                    'enum'        => ['publish', 'draft', 'pending', 'private', 'any'],
                    'default'     => 'publish',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Items per page (max 500)',
                    'default'     => 200,
                    'minimum'     => 1,
                    'maximum'     => 500,
                ],
                'cursor' => [
                    'type'        => 'integer',
                    'description' => 'Post ID to start after (use next_cursor from previous response)',
                    'default'     => 0,
                    'minimum'     => 0,
                ],
                'include_issues' => [
                    'type'        => 'boolean',
                    'description' => 'Include pre-computed SEO issue flags per post',
                    'default'     => true,
                ],
                'modified_after' => [
                    'type'        => 'string',
                    'description' => 'Only return posts modified after this ISO 8601 date (e.g. 2026-03-01T00:00:00Z)',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('read');

        // Sanitize BEFORE the security check so trimming can't bypass it
        $post_status = isset($params['post_status']) ? sanitize_text_field($params['post_status']) : 'publish';

        // Sensitive statuses require edit_posts capability
        $sensitive_statuses = ['private', 'draft', 'pending', 'any'];
        if (in_array($post_status, $sensitive_statuses, true)) {
            $this->require_capability('edit_posts');
        }

        $args = [
            'post_type'      => isset($params['post_type']) ? sanitize_text_field($params['post_type']) : 'any',
            'post_status'    => $post_status,
            'limit'          => isset($params['limit']) ? absint($params['limit']) : Metasync_SEO_Inventory_Builder::DEFAULT_LIMIT,
            'cursor'         => isset($params['cursor']) ? absint($params['cursor']) : 0,
            'include_issues' => isset($params['include_issues']) ? (bool) $params['include_issues'] : true,
            'modified_after' => isset($params['modified_after']) ? sanitize_text_field($params['modified_after']) : '',
        ];

        $result = Metasync_SEO_Inventory_Builder::build($args);

        return $this->success($result);
    }
}

<?php
/**
 * MCP Tool: Post Meta Operations
 *
 * Provides tools for managing WordPress post meta fields,
 * specifically SEO-related metadata.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Update Post Meta Tool
 */
class MCP_Tool_Update_Post_Meta extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_post_meta';
    }

    public function get_description() {
        return 'Update a WordPress post meta field (SEO data like title, description, keywords, robots settings)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'WordPress post or page ID',
                    'minimum' => 1
                ],
                'meta_key' => [
                    'type' => 'string',
                    'description' => 'Meta field to update',
                    'enum' => [
                        '_metasync_metatitle',
                        '_metasync_metadesc',
                        '_metasync_focus_keyword',
                        '_metasync_robots_index',
                        '_metasync_canonical_url'
                    ]
                ],
                'meta_value' => [
                    'type' => 'string',
                    'description' => 'Value to set for the meta field'
                ]
            ],
            'required' => ['post_id', 'meta_key', 'meta_value']
        ];
    }

    public function execute($params) {
        // Validate and sanitize
        $this->validate_params($params);
        $this->require_capability('edit_posts');

        $post_id = $this->sanitize_integer($params['post_id']);
        $meta_key = $this->sanitize_string($params['meta_key']);
        $meta_value = $this->sanitize_textarea($params['meta_value']);

        // Verify post exists
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception(sprintf("Post not found: %d", absint($post_id)));
        }

        // Update meta
        $updated = update_post_meta($post_id, $meta_key, $meta_value);

        if ($updated === false) {
            throw new Exception("Failed to update post meta");
        }

        return $this->success([
            'post_id' => $post_id,
            'meta_key' => $meta_key,
            'meta_value' => $meta_value,
            'post_title' => $post->post_title,
            'post_type' => $post->post_type
        ], "Meta field '{$meta_key}' updated successfully");
    }
}

/**
 * Get Post Meta Tool
 */
class MCP_Tool_Get_Post_Meta extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_post_meta';
    }

    public function get_description() {
        return 'Get WordPress post meta field value(s)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'WordPress post or page ID',
                    'minimum' => 1
                ],
                'meta_key' => [
                    'type' => 'string',
                    'description' => 'Specific meta key to retrieve (optional - omit to get all SEO meta)',
                ]
            ],
            'required' => ['post_id']
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('read');

        $post_id = $this->sanitize_integer($params['post_id']);

        // Verify post exists
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception(sprintf("Post not found: %d", absint($post_id)));
        }

        // Get meta
        if (isset($params['meta_key'])) {
            $meta_key = $this->sanitize_string($params['meta_key']);
            $meta_value = get_post_meta($post_id, $meta_key, true);

            return $this->success([
                'post_id' => $post_id,
                'post_title' => $post->post_title,
                'meta_key' => $meta_key,
                'meta_value' => $meta_value
            ]);
        } else {
            // Get all SEO meta
            $seo_meta = [
                'metatitle' => get_post_meta($post_id, '_metasync_metatitle', true),
                'metadesc' => get_post_meta($post_id, '_metasync_metadesc', true),
                'focus_keyword' => get_post_meta($post_id, '_metasync_focus_keyword', true),
                'robots_index' => get_post_meta($post_id, '_metasync_robots_index', true),
                'canonical_url' => get_post_meta($post_id, '_metasync_canonical_url', true)
            ];

            return $this->success([
                'post_id' => $post_id,
                'post_title' => $post->post_title,
                'post_type' => $post->post_type,
                'post_status' => $post->post_status,
                'seo_meta' => $seo_meta
            ]);
        }
    }
}

/**
 * Get SEO Meta Tool
 */
class MCP_Tool_Get_SEO_Meta extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_seo_meta';
    }

    public function get_description() {
        return 'Get all SEO-related metadata for a post including title, description, keywords, and indexing settings';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'WordPress post or page ID',
                    'minimum' => 1
                ]
            ],
            'required' => ['post_id']
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('read');

        $post_id = $this->sanitize_integer($params['post_id']);

        // Verify post exists
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception(sprintf("Post not found: %d", absint($post_id)));
        }

        // Get all SEO meta
        $seo_data = [
            'post_info' => [
                'id' => $post_id,
                'title' => $post->post_title,
                'type' => $post->post_type,
                'status' => $post->post_status,
                'url' => get_permalink($post_id)
            ],
            'seo_meta' => [
                'meta_title' => get_post_meta($post_id, '_metasync_metatitle', true),
                'meta_description' => get_post_meta($post_id, '_metasync_metadesc', true),
                'focus_keyword' => get_post_meta($post_id, '_metasync_focus_keyword', true),
                'robots_index' => get_post_meta($post_id, '_metasync_robots_index', true),
                'canonical_url' => get_post_meta($post_id, '_metasync_canonical_url', true)
            ],
            'analysis' => [
                'meta_title_length' => mb_strlen(get_post_meta($post_id, '_metasync_metatitle', true)),
                'meta_desc_length' => mb_strlen(get_post_meta($post_id, '_metasync_metadesc', true)),
                'has_focus_keyword' => !empty(get_post_meta($post_id, '_metasync_focus_keyword', true)),
                'is_indexable' => get_post_meta($post_id, '_metasync_robots_index', true) !== 'noindex'
            ]
        ];

        return $this->success($seo_data);
    }
}

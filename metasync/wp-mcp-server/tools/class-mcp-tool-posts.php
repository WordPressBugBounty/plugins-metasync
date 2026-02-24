<?php
/**
 * MCP Tool: Post Operations
 *
 * Provides tools for managing WordPress posts and pages.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get Post Tool
 */
class MCP_Tool_Get_Post extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_post';
    }

    public function get_description() {
        return 'Get a single WordPress post or page by ID with complete information';
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

        // Get post
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception(sprintf("Post not found: %d", absint($post_id)));
        }

        // Build response
        $result = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'type' => $post->post_type,
            'status' => $post->post_status,
            'author_id' => $post->post_author,
            'date' => $post->post_date,
            'modified' => $post->post_modified,
            'url' => get_permalink($post_id),
            'edit_url' => get_edit_post_link($post_id, 'raw')
        ];

        return $this->success($result);
    }
}

/**
 * List Posts Tool
 */
class MCP_Tool_List_Posts extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_list_posts';
    }

    public function get_description() {
        return 'List WordPress posts and pages with filters (type, status, limit)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_type' => [
                    'type' => 'string',
                    'description' => 'Post type to list',
                    'enum' => ['post', 'page', 'any'],
                    'default' => 'any'
                ],
                'post_status' => [
                    'type' => 'string',
                    'description' => 'Post status filter',
                    'enum' => ['publish', 'draft', 'pending', 'private', 'any'],
                    'default' => 'publish'
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of posts to return',
                    'default' => 10,
                    'minimum' => 1,
                    'maximum' => 100
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Number of posts to skip',
                    'default' => 0,
                    'minimum' => 0
                ]
            ],
            'required' => []
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('read');

        // Build query args
        $args = [
            'post_type' => isset($params['post_type']) ? $params['post_type'] : 'any',
            'post_status' => isset($params['post_status']) ? $params['post_status'] : 'publish',
            'posts_per_page' => isset($params['limit']) ? $this->sanitize_integer($params['limit']) : 10,
            'offset' => isset($params['offset']) ? $this->sanitize_integer($params['offset']) : 0,
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        // Get posts
        $query = new WP_Query($args);
        $posts = [];

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();

                $posts[] = [
                    'id' => $post_id,
                    'title' => get_the_title(),
                    'type' => get_post_type(),
                    'status' => get_post_status(),
                    'date' => get_the_date('c'),
                    'modified' => get_the_modified_date('c'),
                    'url' => get_permalink(),
                    'author_id' => get_post_field('post_author', $post_id),
                    'excerpt' => get_the_excerpt()
                ];
            }
            wp_reset_postdata();
        }

        return $this->success([
            'posts' => $posts,
            'total_found' => $query->found_posts,
            'query' => [
                'post_type' => $args['post_type'],
                'post_status' => $args['post_status'],
                'limit' => $args['posts_per_page'],
                'offset' => $args['offset']
            ]
        ]);
    }
}

/**
 * Update Post Tool
 */
class MCP_Tool_Update_Post extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_post';
    }

    public function get_description() {
        return 'Update a WordPress post title, content, or excerpt';
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
                'title' => [
                    'type' => 'string',
                    'description' => 'New post title (optional)'
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'New post content (optional)'
                ],
                'excerpt' => [
                    'type' => 'string',
                    'description' => 'New post excerpt (optional)'
                ]
            ],
            'required' => ['post_id']
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('edit_posts');

        $post_id = $this->sanitize_integer($params['post_id']);

        // Verify post exists
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception(sprintf("Post not found: %d", absint($post_id)));
        }

        // Build update args
        $update_args = ['ID' => $post_id];

        if (isset($params['title'])) {
            $update_args['post_title'] = $this->sanitize_string($params['title']);
        }

        if (isset($params['content'])) {
            $update_args['post_content'] = wp_kses_post($params['content']);
        }

        if (isset($params['excerpt'])) {
            $update_args['post_excerpt'] = $this->sanitize_textarea($params['excerpt']);
        }

        // Only update if we have fields to update
        if (count($update_args) === 1) {
            throw new InvalidArgumentException('At least one field (title, content, or excerpt) must be provided');
        }

        // Update post
        $updated_id = wp_update_post($update_args, true);

        if (is_wp_error($updated_id)) {
            throw new Exception("Failed to update post: " . $updated_id->get_error_message());
        }

        // Get updated post
        $updated_post = get_post($post_id);

        return $this->success([
            'post_id' => $post_id,
            'title' => $updated_post->post_title,
            'type' => $updated_post->post_type,
            'status' => $updated_post->post_status,
            'updated_fields' => array_keys(array_diff_key($update_args, ['ID' => null]))
        ], 'Post updated successfully');
    }
}

/**
 * Get Post Types Tool
 */
class MCP_Tool_Get_Post_Types extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_post_types';
    }

    public function get_description() {
        return 'Get list of available WordPress post types';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => []
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('read');

        // Get all post types
        $post_types = get_post_types(['public' => true], 'objects');
        $result = [];

        foreach ($post_types as $post_type) {
            $result[] = [
                'name' => $post_type->name,
                'label' => $post_type->label,
                'singular_label' => $post_type->labels->singular_name,
                'description' => $post_type->description,
                'hierarchical' => $post_type->hierarchical,
                'public' => $post_type->public
            ];
        }

        return $this->success(['post_types' => $result]);
    }
}

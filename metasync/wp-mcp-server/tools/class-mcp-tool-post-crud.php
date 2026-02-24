<?php
/**
 * MCP Tools for Post CRUD Operations
 *
 * Provides MCP tools for complete post CRUD operations (Create, Delete, Restore).
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(dirname(__FILE__)) . 'class-mcp-tool-base.php';

/**
 * Create Post Tool
 *
 * Creates a new post or page
 */
class MCP_Tool_Create_Post extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_create_post';
    }

    public function get_description() {
        return 'Create a new post or page';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Post title',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Post content',
                ],
                'excerpt' => [
                    'type' => 'string',
                    'description' => 'Post excerpt (optional)',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['publish', 'draft', 'pending', 'private'],
                    'description' => 'Post status (default: draft)',
                ],
                'post_type' => [
                    'type' => 'string',
                    'description' => 'Post type (default: post)',
                ],
                'author_id' => [
                    'type' => 'integer',
                    'description' => 'Author ID (optional, defaults to current user)',
                ],
                'category_ids' => [
                    'type' => 'array',
                    'description' => 'Array of category IDs (optional)',
                    'items' => ['type' => 'integer'],
                ],
                'tags' => [
                    'type' => 'array',
                    'description' => 'Array of tag names (optional)',
                    'items' => ['type' => 'string'],
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Post slug (optional)',
                ],
            ],
            'required' => ['title', 'content'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('edit_posts');

        $post_data = [
            'post_title' => sanitize_text_field($params['title']),
            'post_content' => wp_kses_post($params['content']),
            'post_status' => isset($params['status']) ? sanitize_text_field($params['status']) : 'draft',
            'post_type' => isset($params['post_type']) ? sanitize_text_field($params['post_type']) : 'post',
        ];

        if (isset($params['excerpt'])) {
            $post_data['post_excerpt'] = sanitize_textarea_field($params['excerpt']);
        }

        if (isset($params['author_id'])) {
            $post_data['post_author'] = intval($params['author_id']);
        }

        if (isset($params['slug'])) {
            $post_data['post_name'] = sanitize_title($params['slug']);
        }

        // Create post
        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            throw new Exception('Failed to create post: ' . $post_id->get_error_message());
        }

        // Set categories if provided
        if (isset($params['category_ids']) && is_array($params['category_ids'])) {
            wp_set_post_categories($post_id, array_map('intval', $params['category_ids']));
        }

        // Set tags if provided
        if (isset($params['tags']) && is_array($params['tags'])) {
            wp_set_post_tags($post_id, array_map('sanitize_text_field', $params['tags']));
        }

        $post = get_post($post_id);

        return $this->success([
            'post_id' => $post_id,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'url' => get_permalink($post_id),
            'edit_url' => get_edit_post_link($post_id, 'raw'),
            'status' => $post->post_status,
            'post_type' => $post->post_type,
            'created_at' => $post->post_date,
            'message' => 'Post created successfully',
        ]);
    }
}

/**
 * Delete Post Tool
 *
 * Deletes a post (moves to trash or permanently deletes)
 */
class MCP_Tool_Delete_Post extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_delete_post';
    }

    public function get_description() {
        return 'Delete a post (move to trash or delete permanently)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'Post ID to delete',
                ],
                'force_delete' => [
                    'type' => 'boolean',
                    'description' => 'If true, permanently delete (default: false, moves to trash)',
                ],
            ],
            'required' => ['post_id'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('delete_posts');

        $post_id = intval($params['post_id']);
        $force_delete = isset($params['force_delete']) ? (bool)$params['force_delete'] : false;

        // Validate post exists
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception(sprintf("Post not found: %d", absint($post_id)));
        }

        // Check permissions
        if (!current_user_can('delete_post', $post_id)) {
            throw new Exception('You do not have permission to delete this post');
        }

        $title = $post->post_title;
        $post_type = $post->post_type;

        // Delete post
        $result = wp_delete_post($post_id, $force_delete);

        if (!$result) {
            throw new Exception('Failed to delete post');
        }

        return $this->success([
            'post_id' => $post_id,
            'title' => $title,
            'post_type' => $post_type,
            'permanently_deleted' => $force_delete,
            'message' => $force_delete ? 'Post permanently deleted' : 'Post moved to trash',
        ]);
    }
}

/**
 * Restore Post Tool
 *
 * Restores a post from trash
 */
class MCP_Tool_Restore_Post extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_restore_post';
    }

    public function get_description() {
        return 'Restore a post from trash';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'Post ID to restore',
                ],
            ],
            'required' => ['post_id'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('delete_posts');

        $post_id = intval($params['post_id']);

        // Validate post exists
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception(sprintf("Post not found: %d", absint($post_id)));
        }

        // Check if post is in trash
        if ($post->post_status !== 'trash') {
            throw new Exception('Post is not in trash');
        }

        // Check permissions
        if (!current_user_can('delete_post', $post_id)) {
            throw new Exception('You do not have permission to restore this post');
        }

        // Restore post
        $result = wp_untrash_post($post_id);

        if (!$result) {
            throw new Exception('Failed to restore post');
        }

        $restored_post = get_post($post_id);

        return $this->success([
            'post_id' => $post_id,
            'title' => $restored_post->post_title,
            'status' => $restored_post->post_status,
            'url' => get_permalink($post_id),
            'edit_url' => get_edit_post_link($post_id, 'raw'),
            'message' => 'Post restored successfully',
        ]);
    }
}

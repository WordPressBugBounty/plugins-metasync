<?php
/**
 * MCP Tools for Bulk Operations
 *
 * Provides MCP tools for performing bulk operations on multiple posts.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(dirname(__FILE__)) . 'class-mcp-tool-base.php';

/**
 * Bulk Update Meta Tool
 *
 * Updates meta for multiple posts
 */
class MCP_Tool_Bulk_Update_Meta extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_bulk_update_meta';
    }

    public function get_description() {
        return 'Update post meta for multiple posts at once (max 100 posts)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_ids' => [
                    'type' => 'array',
                    'description' => 'Array of post IDs to update (max 100)',
                    'items' => ['type' => 'integer'],
                    'maxItems' => 100,
                ],
                'meta_key' => [
                    'type' => 'string',
                    'description' => 'Meta key to update',
                ],
                'meta_value' => [
                    'type' => 'string',
                    'description' => 'Meta value to set',
                ],
            ],
            'required' => ['post_ids', 'meta_key', 'meta_value'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('edit_posts');

        if (!is_array($params['post_ids'])) {
            throw new Exception('post_ids must be an array');
        }

        $post_ids = array_map('intval', $params['post_ids']);

        if (empty($post_ids)) {
            throw new Exception('No post IDs provided');
        }

        if (count($post_ids) > 100) {
            throw new Exception('Maximum 100 posts can be updated at once');
        }

        $meta_key = sanitize_text_field($params['meta_key']);
        $meta_value = $params['meta_value']; // Don't sanitize yet, depends on key

        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($post_ids as $post_id) {
            try {
                // Verify post exists
                $post = get_post($post_id);
                if (!$post) {
                    $results['failed'][] = [
                        'post_id' => $post_id,
                        'error' => 'Post not found',
                    ];
                    continue;
                }

                // Check permissions
                try {
                    $this->check_post_permission($post_id);
                } catch (Exception $e) {
                    $results['failed'][] = [
                        'post_id' => $post_id,
                        'error' => $e->getMessage(),
                    ];
                    continue;
                }

                // Update meta
                update_post_meta($post_id, $meta_key, $meta_value);

                $results['success'][] = [
                    'post_id' => $post_id,
                    'post_title' => $post->post_title,
                ];

            } catch (Exception $e) {
                $results['failed'][] = [
                    'post_id' => $post_id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $this->success([
            'total_requested' => count($post_ids),
            'success_count' => count($results['success']),
            'failed_count' => count($results['failed']),
            'results' => $results,
            'meta_key' => $meta_key,
            'message' => count($results['success']) . ' post(s) updated successfully',
        ]);
    }
}

/**
 * Bulk Set Categories Tool
 *
 * Sets categories for multiple posts
 */
class MCP_Tool_Bulk_Set_Categories extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_bulk_set_categories';
    }

    public function get_description() {
        return 'Set categories for multiple posts at once (max 100 posts)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_ids' => [
                    'type' => 'array',
                    'description' => 'Array of post IDs to update (max 100)',
                    'items' => ['type' => 'integer'],
                    'maxItems' => 100,
                ],
                'category_ids' => [
                    'type' => 'array',
                    'description' => 'Array of category IDs to assign',
                    'items' => ['type' => 'integer'],
                ],
            ],
            'required' => ['post_ids', 'category_ids'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('edit_posts');

        if (!is_array($params['post_ids'])) {
            throw new Exception('post_ids must be an array');
        }

        if (!is_array($params['category_ids'])) {
            throw new Exception('category_ids must be an array');
        }

        $post_ids = array_map('intval', $params['post_ids']);
        $category_ids = array_map('intval', $params['category_ids']);

        if (empty($post_ids)) {
            throw new Exception('No post IDs provided');
        }

        if (count($post_ids) > 100) {
            throw new Exception('Maximum 100 posts can be updated at once');
        }

        // Validate categories exist
        foreach ($category_ids as $category_id) {
            $category = get_term($category_id, 'category');
            if (is_wp_error($category) || !$category) {
                throw new Exception(sprintf("Category not found with ID: %d", absint($category_id)));
            }
        }

        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($post_ids as $post_id) {
            try {
                // Verify post exists
                $post = get_post($post_id);
                if (!$post) {
                    $results['failed'][] = [
                        'post_id' => $post_id,
                        'error' => 'Post not found',
                    ];
                    continue;
                }

                // Check permissions
                try {
                    $this->check_post_permission($post_id);
                } catch (Exception $e) {
                    $results['failed'][] = [
                        'post_id' => $post_id,
                        'error' => $e->getMessage(),
                    ];
                    continue;
                }

                // Set categories
                wp_set_post_categories($post_id, $category_ids);

                $results['success'][] = [
                    'post_id' => $post_id,
                    'post_title' => $post->post_title,
                ];

            } catch (Exception $e) {
                $results['failed'][] = [
                    'post_id' => $post_id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $this->success([
            'total_requested' => count($post_ids),
            'success_count' => count($results['success']),
            'failed_count' => count($results['failed']),
            'results' => $results,
            'category_ids' => $category_ids,
            'message' => count($results['success']) . ' post(s) updated successfully',
        ]);
    }
}

/**
 * Bulk Change Status Tool
 *
 * Changes status for multiple posts
 */
class MCP_Tool_Bulk_Change_Status extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_bulk_change_status';
    }

    public function get_description() {
        return 'Change status for multiple posts at once (max 100 posts)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_ids' => [
                    'type' => 'array',
                    'description' => 'Array of post IDs to update (max 100)',
                    'items' => ['type' => 'integer'],
                    'maxItems' => 100,
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['publish', 'draft', 'pending', 'private'],
                    'description' => 'New status for the posts',
                ],
            ],
            'required' => ['post_ids', 'status'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('edit_posts');

        if (!is_array($params['post_ids'])) {
            throw new Exception('post_ids must be an array');
        }

        $post_ids = array_map('intval', $params['post_ids']);
        $status = sanitize_text_field($params['status']);

        if (empty($post_ids)) {
            throw new Exception('No post IDs provided');
        }

        if (count($post_ids) > 100) {
            throw new Exception('Maximum 100 posts can be updated at once');
        }

        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($post_ids as $post_id) {
            try {
                // Verify post exists
                $post = get_post($post_id);
                if (!$post) {
                    $results['failed'][] = [
                        'post_id' => $post_id,
                        'error' => 'Post not found',
                    ];
                    continue;
                }

                // Check permissions
                try {
                    $this->check_post_permission($post_id);
                } catch (Exception $e) {
                    $results['failed'][] = [
                        'post_id' => $post_id,
                        'error' => $e->getMessage(),
                    ];
                    continue;
                }

                // Update status
                $result = wp_update_post([
                    'ID' => $post_id,
                    'post_status' => $status,
                ], true);

                if (is_wp_error($result)) {
                    $results['failed'][] = [
                        'post_id' => $post_id,
                        'error' => $result->get_error_message(),
                    ];
                    continue;
                }

                $results['success'][] = [
                    'post_id' => $post_id,
                    'post_title' => $post->post_title,
                    'old_status' => $post->post_status,
                    'new_status' => $status,
                ];

            } catch (Exception $e) {
                $results['failed'][] = [
                    'post_id' => $post_id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $this->success([
            'total_requested' => count($post_ids),
            'success_count' => count($results['success']),
            'failed_count' => count($results['failed']),
            'results' => $results,
            'new_status' => $status,
            'message' => count($results['success']) . ' post(s) status changed successfully',
        ]);
    }
}

/**
 * Bulk Delete Posts Tool
 *
 * Deletes multiple posts
 */
class MCP_Tool_Bulk_Delete_Posts extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_bulk_delete_posts';
    }

    public function get_description() {
        return 'Delete multiple posts at once (max 100 posts)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_ids' => [
                    'type' => 'array',
                    'description' => 'Array of post IDs to delete (max 100)',
                    'items' => ['type' => 'integer'],
                    'maxItems' => 100,
                ],
                'force_delete' => [
                    'type' => 'boolean',
                    'description' => 'If true, permanently delete (default: false, moves to trash)',
                ],
            ],
            'required' => ['post_ids'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('delete_posts');

        if (!is_array($params['post_ids'])) {
            throw new Exception('post_ids must be an array');
        }

        $post_ids = array_map('intval', $params['post_ids']);
        $force_delete = isset($params['force_delete']) ? (bool)$params['force_delete'] : false;

        if (empty($post_ids)) {
            throw new Exception('No post IDs provided');
        }

        if (count($post_ids) > 100) {
            throw new Exception('Maximum 100 posts can be deleted at once');
        }

        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($post_ids as $post_id) {
            try {
                // Verify post exists
                $post = get_post($post_id);
                if (!$post) {
                    $results['failed'][] = [
                        'post_id' => $post_id,
                        'error' => 'Post not found',
                    ];
                    continue;
                }

                // Check permissions
                if (!current_user_can('delete_post', $post_id)) {
                    $results['failed'][] = [
                        'post_id' => $post_id,
                        'error' => 'Permission denied',
                    ];
                    continue;
                }

                $title = $post->post_title;

                // Delete post
                $result = wp_delete_post($post_id, $force_delete);

                if (!$result) {
                    $results['failed'][] = [
                        'post_id' => $post_id,
                        'error' => 'Failed to delete post',
                    ];
                    continue;
                }

                $results['success'][] = [
                    'post_id' => $post_id,
                    'post_title' => $title,
                    'permanently_deleted' => $force_delete,
                ];

            } catch (Exception $e) {
                $results['failed'][] = [
                    'post_id' => $post_id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $this->success([
            'total_requested' => count($post_ids),
            'success_count' => count($results['success']),
            'failed_count' => count($results['failed']),
            'results' => $results,
            'permanently_deleted' => $force_delete,
            'message' => count($results['success']) . ' post(s) ' . ($force_delete ? 'permanently deleted' : 'moved to trash'),
        ]);
    }
}

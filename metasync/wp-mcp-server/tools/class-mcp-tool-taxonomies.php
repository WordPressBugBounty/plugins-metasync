<?php
/**
 * MCP Tools for Taxonomy Operations
 *
 * Provides MCP tools for managing categories and taxonomies.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(dirname(__FILE__)) . 'class-mcp-tool-base.php';

/**
 * List Categories Tool
 *
 * Lists all categories
 */
class MCP_Tool_List_Categories extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_list_categories';
    }

    public function get_description() {
        return 'List all categories with their details';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'hide_empty' => [
                    'type' => 'boolean',
                    'description' => 'Whether to hide categories with no posts (default: false)',
                ],
                'orderby' => [
                    'type' => 'string',
                    'enum' => ['name', 'slug', 'count', 'id'],
                    'description' => 'Order by field (default: name)',
                ],
                'order' => [
                    'type' => 'string',
                    'enum' => ['ASC', 'DESC'],
                    'description' => 'Sort order (default: ASC)',
                ],
            ],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_categories');

        $args = [
            'taxonomy' => 'category',
            'hide_empty' => isset($params['hide_empty']) ? (bool)$params['hide_empty'] : false,
            'orderby' => isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'name',
            'order' => isset($params['order']) ? sanitize_text_field($params['order']) : 'ASC',
        ];

        $categories = get_terms($args);

        if (is_wp_error($categories)) {
            throw new Exception('Failed to retrieve categories: ' . $categories->get_error_message());
        }

        $categories_data = [];
        foreach ($categories as $category) {
            $categories_data[] = [
                'term_id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'count' => $category->count,
                'parent' => $category->parent,
                'parent_name' => $category->parent ? get_term($category->parent)->name : null,
            ];
        }

        return $this->success([
            'count' => count($categories_data),
            'categories' => $categories_data,
        ]);
    }
}

/**
 * Get Category Tool
 *
 * Gets a specific category
 */
class MCP_Tool_Get_Category extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_category';
    }

    public function get_description() {
        return 'Get category details by ID or slug';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'category_id' => [
                    'type' => 'integer',
                    'description' => 'Category ID',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Category slug',
                ],
            ],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_categories');

        $category_id = isset($params['category_id']) ? intval($params['category_id']) : null;
        $slug = isset($params['slug']) ? sanitize_text_field($params['slug']) : null;

        if (empty($category_id) && empty($slug)) {
            throw new Exception('Either category_id or slug must be provided');
        }

        if (!empty($category_id)) {
            $category = get_term($category_id, 'category');
        } else {
            $category = get_term_by('slug', $slug, 'category');
        }

        if (is_wp_error($category)) {
            throw new Exception('Failed to retrieve category: ' . $category->get_error_message());
        }

        if (!$category) {
            throw new Exception('Category not found');
        }

        return $this->success([
            'term_id' => $category->term_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'count' => $category->count,
            'parent' => $category->parent,
            'parent_name' => $category->parent ? get_term($category->parent)->name : null,
        ]);
    }
}

/**
 * Create Category Tool
 *
 * Creates a new category
 */
class MCP_Tool_Create_Category extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_create_category';
    }

    public function get_description() {
        return 'Create a new category';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Category name',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Category slug (optional, auto-generated from name if not provided)',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Category description (optional)',
                ],
                'parent' => [
                    'type' => 'integer',
                    'description' => 'Parent category ID (optional)',
                ],
            ],
            'required' => ['name'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_categories');

        $args = [
            'description' => isset($params['description']) ? sanitize_textarea_field($params['description']) : '',
            'parent' => isset($params['parent']) ? intval($params['parent']) : 0,
        ];

        if (!empty($params['slug'])) {
            $args['slug'] = sanitize_title($params['slug']);
        }

        $result = wp_insert_term(
            sanitize_text_field($params['name']),
            'category',
            $args
        );

        if (is_wp_error($result)) {
            throw new Exception('Failed to create category: ' . $result->get_error_message());
        }

        $category = get_term($result['term_id'], 'category');

        return $this->success([
            'term_id' => $category->term_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'parent' => $category->parent,
            'message' => 'Category created successfully',
        ]);
    }
}

/**
 * Update Category Tool
 *
 * Updates an existing category
 */
class MCP_Tool_Update_Category extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_category';
    }

    public function get_description() {
        return 'Update an existing category';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'category_id' => [
                    'type' => 'integer',
                    'description' => 'Category ID to update',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Category name (optional)',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Category slug (optional)',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Category description (optional)',
                ],
                'parent' => [
                    'type' => 'integer',
                    'description' => 'Parent category ID (optional)',
                ],
            ],
            'required' => ['category_id'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_categories');

        $category_id = intval($params['category_id']);

        // Verify category exists
        $category = get_term($category_id, 'category');
        if (is_wp_error($category) || !$category) {
            throw new Exception(sprintf("Category not found with ID: %d", absint($category_id)));
        }

        $args = [];

        if (isset($params['name'])) {
            $args['name'] = sanitize_text_field($params['name']);
        }

        if (isset($params['slug'])) {
            $args['slug'] = sanitize_title($params['slug']);
        }

        if (isset($params['description'])) {
            $args['description'] = sanitize_textarea_field($params['description']);
        }

        if (isset($params['parent'])) {
            $args['parent'] = intval($params['parent']);
        }

        if (empty($args)) {
            throw new Exception('No fields to update provided');
        }

        $result = wp_update_term($category_id, 'category', $args);

        if (is_wp_error($result)) {
            throw new Exception('Failed to update category: ' . $result->get_error_message());
        }

        $updated_category = get_term($result['term_id'], 'category');

        return $this->success([
            'term_id' => $updated_category->term_id,
            'name' => $updated_category->name,
            'slug' => $updated_category->slug,
            'description' => $updated_category->description,
            'parent' => $updated_category->parent,
            'message' => 'Category updated successfully',
        ]);
    }
}

/**
 * Delete Category Tool
 *
 * Deletes a category
 */
class MCP_Tool_Delete_Category extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_delete_category';
    }

    public function get_description() {
        return 'Delete a category permanently';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'category_id' => [
                    'type' => 'integer',
                    'description' => 'Category ID to delete',
                ],
            ],
            'required' => ['category_id'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_categories');

        $category_id = intval($params['category_id']);

        // Verify category exists
        $category = get_term($category_id, 'category');
        if (is_wp_error($category) || !$category) {
            throw new Exception(sprintf("Category not found with ID: %d", absint($category_id)));
        }

        // Prevent deletion of default category
        $default_category = get_option('default_category');
        if ($category_id == $default_category) {
            throw new Exception('Cannot delete the default category');
        }

        $name = $category->name;

        $result = wp_delete_term($category_id, 'category');

        if (is_wp_error($result)) {
            throw new Exception('Failed to delete category: ' . $result->get_error_message());
        }

        if (!$result) {
            throw new Exception('Failed to delete category');
        }

        return $this->success([
            'category_id' => $category_id,
            'name' => $name,
            'message' => 'Category deleted successfully',
        ]);
    }
}

/**
 * Get Post Categories Tool
 *
 * Gets categories assigned to a post
 */
class MCP_Tool_Get_Post_Categories extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_post_categories';
    }

    public function get_description() {
        return 'Get all categories assigned to a post';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'Post ID',
                ],
            ],
            'required' => ['post_id'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('edit_posts');

        $post_id = intval($params['post_id']);

        // Validate post exists
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception(sprintf("Post not found: %d", absint($post_id)));
        }

        $categories = get_the_category($post_id);

        $categories_data = [];
        foreach ($categories as $category) {
            $categories_data[] = [
                'term_id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'parent' => $category->parent,
            ];
        }

        return $this->success([
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'count' => count($categories_data),
            'categories' => $categories_data,
        ]);
    }
}

/**
 * Set Post Categories Tool
 *
 * Sets categories for a post
 */
class MCP_Tool_Set_Post_Categories extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_set_post_categories';
    }

    public function get_description() {
        return 'Set categories for a post (replaces existing categories)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'Post ID',
                ],
                'category_ids' => [
                    'type' => 'array',
                    'description' => 'Array of category IDs to assign',
                    'items' => [
                        'type' => 'integer',
                    ],
                ],
            ],
            'required' => ['post_id', 'category_ids'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('edit_posts');

        $post_id = intval($params['post_id']);

        // Validate post exists and user can edit it
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception(sprintf("Post not found: %d", absint($post_id)));
        }

        if (!current_user_can('edit_post', $post_id)) {
            throw new Exception('You do not have permission to edit this post');
        }

        $category_ids = array_map('intval', $params['category_ids']);

        // Validate all categories exist
        foreach ($category_ids as $category_id) {
            $category = get_term($category_id, 'category');
            if (is_wp_error($category) || !$category) {
                throw new Exception(sprintf("Category not found with ID: %d", absint($category_id)));
            }
        }

        $result = wp_set_post_categories($post_id, $category_ids);

        if (is_wp_error($result)) {
            throw new Exception('Failed to set categories: ' . $result->get_error_message());
        }

        // Get updated categories
        $categories = get_the_category($post_id);
        $categories_data = [];
        foreach ($categories as $category) {
            $categories_data[] = [
                'term_id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
            ];
        }

        return $this->success([
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'categories' => $categories_data,
            'message' => 'Categories updated successfully',
        ]);
    }
}

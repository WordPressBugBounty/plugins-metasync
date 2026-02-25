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

        $this->check_post_permission($post_id);

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

/**
 * List Tags Tool
 *
 * Lists all tags
 */
class MCP_Tool_List_Tags extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_list_tags';
    }

    public function get_description() {
        return 'List all tags with their details';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'hide_empty' => [
                    'type' => 'boolean',
                    'description' => 'Whether to hide tags with no posts (default: false)',
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
                'number' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of tags to return (default: all)',
                ],
            ],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_categories'); // Same capability for tags

        $args = [
            'taxonomy' => 'post_tag',
            'hide_empty' => isset($params['hide_empty']) ? (bool)$params['hide_empty'] : false,
            'orderby' => isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'name',
            'order' => isset($params['order']) ? sanitize_text_field($params['order']) : 'ASC',
        ];

        if (isset($params['number'])) {
            $args['number'] = intval($params['number']);
        }

        $tags = get_terms($args);

        if (is_wp_error($tags)) {
            throw new Exception('Failed to retrieve tags: ' . $tags->get_error_message());
        }

        $tags_data = [];
        foreach ($tags as $tag) {
            $tags_data[] = [
                'term_id' => $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'description' => $tag->description,
                'count' => $tag->count,
            ];
        }

        return $this->success([
            'count' => count($tags_data),
            'tags' => $tags_data,
        ]);
    }
}

/**
 * Get Tag Tool
 *
 * Gets a specific tag
 */
class MCP_Tool_Get_Tag extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_tag';
    }

    public function get_description() {
        return 'Get tag details by ID or slug';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'tag_id' => [
                    'type' => 'integer',
                    'description' => 'Tag ID',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Tag slug',
                ],
            ],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_categories');

        $tag_id = isset($params['tag_id']) ? intval($params['tag_id']) : null;
        $slug = isset($params['slug']) ? sanitize_text_field($params['slug']) : null;

        if (empty($tag_id) && empty($slug)) {
            throw new Exception('Either tag_id or slug must be provided');
        }

        if (!empty($tag_id)) {
            $tag = get_term($tag_id, 'post_tag');
        } else {
            $tag = get_term_by('slug', $slug, 'post_tag');
        }

        if (is_wp_error($tag)) {
            throw new Exception('Failed to retrieve tag: ' . $tag->get_error_message());
        }

        if (!$tag) {
            throw new Exception('Tag not found');
        }

        return $this->success([
            'term_id' => $tag->term_id,
            'name' => $tag->name,
            'slug' => $tag->slug,
            'description' => $tag->description,
            'count' => $tag->count,
        ]);
    }
}

/**
 * Create Tag Tool
 *
 * Creates a new tag
 */
class MCP_Tool_Create_Tag extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_create_tag';
    }

    public function get_description() {
        return 'Create a new tag';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Tag name',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Tag slug (optional, auto-generated from name if not provided)',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Tag description (optional)',
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
        ];

        if (!empty($params['slug'])) {
            $args['slug'] = sanitize_title($params['slug']);
        }

        $result = wp_insert_term(
            sanitize_text_field($params['name']),
            'post_tag',
            $args
        );

        if (is_wp_error($result)) {
            throw new Exception('Failed to create tag: ' . $result->get_error_message());
        }

        $tag = get_term($result['term_id'], 'post_tag');

        return $this->success([
            'term_id' => $tag->term_id,
            'name' => $tag->name,
            'slug' => $tag->slug,
            'description' => $tag->description,
            'message' => 'Tag created successfully',
        ]);
    }
}

/**
 * Update Tag Tool
 *
 * Updates an existing tag
 */
class MCP_Tool_Update_Tag extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_tag';
    }

    public function get_description() {
        return 'Update an existing tag';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'tag_id' => [
                    'type' => 'integer',
                    'description' => 'Tag ID to update',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Tag name (optional)',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Tag slug (optional)',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Tag description (optional)',
                ],
            ],
            'required' => ['tag_id'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_categories');

        $tag_id = intval($params['tag_id']);

        // Verify tag exists
        $tag = get_term($tag_id, 'post_tag');
        if (is_wp_error($tag) || !$tag) {
            throw new Exception(sprintf("Tag not found with ID: %d", absint($tag_id)));
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

        if (empty($args)) {
            throw new Exception('No fields to update provided');
        }

        $result = wp_update_term($tag_id, 'post_tag', $args);

        if (is_wp_error($result)) {
            throw new Exception('Failed to update tag: ' . $result->get_error_message());
        }

        $updated_tag = get_term($result['term_id'], 'post_tag');

        return $this->success([
            'term_id' => $updated_tag->term_id,
            'name' => $updated_tag->name,
            'slug' => $updated_tag->slug,
            'description' => $updated_tag->description,
            'message' => 'Tag updated successfully',
        ]);
    }
}

/**
 * Delete Tag Tool
 *
 * Deletes a tag
 */
class MCP_Tool_Delete_Tag extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_delete_tag';
    }

    public function get_description() {
        return 'Delete a tag permanently';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'tag_id' => [
                    'type' => 'integer',
                    'description' => 'Tag ID to delete',
                ],
            ],
            'required' => ['tag_id'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_categories');

        $tag_id = intval($params['tag_id']);

        // Verify tag exists
        $tag = get_term($tag_id, 'post_tag');
        if (is_wp_error($tag) || !$tag) {
            throw new Exception(sprintf("Tag not found with ID: %d", absint($tag_id)));
        }

        $name = $tag->name;

        $result = wp_delete_term($tag_id, 'post_tag');

        if (is_wp_error($result)) {
            throw new Exception('Failed to delete tag: ' . $result->get_error_message());
        }

        if (!$result) {
            throw new Exception('Failed to delete tag');
        }

        return $this->success([
            'tag_id' => $tag_id,
            'name' => $name,
            'message' => 'Tag deleted successfully',
        ]);
    }
}

/**
 * Get Post Tags Tool
 *
 * Gets tags assigned to a post
 */
class MCP_Tool_Get_Post_Tags extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_post_tags';
    }

    public function get_description() {
        return 'Get all tags assigned to a post';
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

        $tags = get_the_tags($post_id);

        // get_the_tags() returns false if no tags
        if ($tags === false) {
            $tags = [];
        }

        $tags_data = [];
        foreach ($tags as $tag) {
            $tags_data[] = [
                'term_id' => $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'description' => $tag->description,
            ];
        }

        return $this->success([
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'count' => count($tags_data),
            'tags' => $tags_data,
        ]);
    }
}

/**
 * Set Post Tags Tool
 *
 * Sets tags for a post
 */
class MCP_Tool_Set_Post_Tags extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_set_post_tags';
    }

    public function get_description() {
        return 'Set tags for a post (replaces existing tags). Accepts tag IDs, names, or slugs.';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'Post ID',
                ],
                'tags' => [
                    'type' => 'array',
                    'description' => 'Array of tag IDs, names, or slugs. Tags that don\'t exist will be created automatically.',
                    'items' => [
                        'oneOf' => [
                            ['type' => 'integer'],
                            ['type' => 'string'],
                        ],
                    ],
                ],
                'append' => [
                    'type' => 'boolean',
                    'description' => 'If true, append tags to existing tags instead of replacing (default: false)',
                ],
            ],
            'required' => ['post_id', 'tags'],
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

        $this->check_post_permission($post_id);

        $tags = $params['tags'];
        $append = isset($params['append']) ? (bool)$params['append'] : false;

        // WordPress wp_set_post_tags() handles tag creation automatically
        $result = wp_set_post_tags($post_id, $tags, $append);

        if (is_wp_error($result)) {
            throw new Exception('Failed to set tags: ' . $result->get_error_message());
        }

        // Get updated tags
        $post_tags = get_the_tags($post_id);
        if ($post_tags === false) {
            $post_tags = [];
        }

        $tags_data = [];
        foreach ($post_tags as $tag) {
            $tags_data[] = [
                'term_id' => $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ];
        }

        return $this->success([
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'tags' => $tags_data,
            'message' => sprintf('%s successfully', $append ? 'Tags appended' : 'Tags updated'),
        ]);
    }
}

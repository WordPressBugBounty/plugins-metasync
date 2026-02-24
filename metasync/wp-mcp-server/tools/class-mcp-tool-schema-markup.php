<?php
/**
 * MCP Tools for Schema Markup Operations
 *
 * Provides MCP tools for managing schema markup on posts and pages.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(dirname(__FILE__)) . 'class-mcp-tool-base.php';

/**
 * Get Schema Markup Tool
 *
 * Retrieves schema markup configuration for a post
 */
class MCP_Tool_Get_Schema_Markup extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_schema_markup';
    }

    public function get_description() {
        return 'Get schema markup configuration for a post or page';
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

        // Get schema data
        $schema_data = get_post_meta($post_id, 'metasync_schema_markup', true);
        $validation_errors = get_post_meta($post_id, '_metasync_schema_validation_errors', true);

        $result = [
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'post_type' => $post->post_type,
            'schema_enabled' => false,
            'schema_types' => [],
            'has_validation_errors' => false,
            'validation_errors' => [],
        ];

        if (!empty($schema_data)) {
            $result['schema_enabled'] = isset($schema_data['enabled']) ? (bool)$schema_data['enabled'] : false;
            $result['schema_types'] = isset($schema_data['types']) ? $schema_data['types'] : [];
        }

        if (!empty($validation_errors) && is_array($validation_errors)) {
            $result['has_validation_errors'] = true;
            $result['validation_errors'] = $validation_errors;
        }

        return $this->success($result);
    }
}

/**
 * Update Schema Markup Tool
 *
 * Updates schema markup configuration for a post
 */
class MCP_Tool_Update_Schema_Markup extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_schema_markup';
    }

    public function get_description() {
        return 'Update schema markup configuration for a post or page';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'Post ID',
                ],
                'enabled' => [
                    'type' => 'boolean',
                    'description' => 'Enable or disable schema markup for this post',
                ],
                'types' => [
                    'type' => 'array',
                    'description' => 'Array of schema types with their configuration',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => [
                                'type' => 'string',
                                'enum' => ['article', 'FAQPage', 'product', 'recipe'],
                            ],
                            'fields' => [
                                'type' => 'object',
                                'description' => 'Schema-specific fields',
                            ],
                        ],
                    ],
                ],
            ],
            'required' => ['post_id', 'enabled'],
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

        // Build schema data
        $schema_data = [
            'enabled' => (bool)$params['enabled'],
            'types' => [],
        ];

        // Process schema types
        if (isset($params['types']) && is_array($params['types'])) {
            foreach ($params['types'] as $type_data) {
                if (!empty($type_data['type'])) {
                    $schema_data['types'][] = [
                        'type' => sanitize_text_field($type_data['type']),
                        'fields' => isset($type_data['fields']) ? $type_data['fields'] : [],
                    ];
                }
            }
        }

        // Save schema data
        update_post_meta($post_id, 'metasync_schema_markup', $schema_data);

        // Clear validation errors
        delete_post_meta($post_id, '_metasync_schema_validation_errors');

        return $this->success([
            'post_id' => $post_id,
            'schema_enabled' => $schema_data['enabled'],
            'schema_types_count' => count($schema_data['types']),
            'message' => 'Schema markup updated successfully',
        ]);
    }
}

/**
 * Add Schema Type Tool
 *
 * Adds a new schema type to a post
 */
class MCP_Tool_Add_Schema_Type extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_add_schema_type';
    }

    public function get_description() {
        return 'Add a new schema type (article, FAQ, product, recipe) to a post';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'Post ID',
                ],
                'schema_type' => [
                    'type' => 'string',
                    'enum' => ['article', 'FAQPage', 'product', 'recipe'],
                    'description' => 'Schema type to add',
                ],
                'fields' => [
                    'type' => 'object',
                    'description' => 'Schema-specific fields configuration',
                ],
            ],
            'required' => ['post_id', 'schema_type'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('edit_posts');

        $post_id = intval($params['post_id']);
        $schema_type = sanitize_text_field($params['schema_type']);
        $fields = isset($params['fields']) ? $params['fields'] : [];

        // Validate post exists and user can edit it
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception(sprintf("Post not found: %d", absint($post_id)));
        }

        if (!current_user_can('edit_post', $post_id)) {
            throw new Exception('You do not have permission to edit this post');
        }

        // Get existing schema data
        $schema_data = get_post_meta($post_id, 'metasync_schema_markup', true);
        if (empty($schema_data)) {
            $schema_data = ['enabled' => true, 'types' => []];
        }

        // Check if schema type already exists
        foreach ($schema_data['types'] as $existing_type) {
            if ($existing_type['type'] === $schema_type) {
                throw new Exception(sprintf("Schema type '%s' already exists for this post", esc_html($schema_type)));
            }
        }

        // Add new schema type
        $schema_data['types'][] = [
            'type' => $schema_type,
            'fields' => $fields,
        ];

        // Save updated schema data
        update_post_meta($post_id, 'metasync_schema_markup', $schema_data);

        return $this->success([
            'post_id' => $post_id,
            'schema_type' => $schema_type,
            'schema_types_count' => count($schema_data['types']),
            'message' => "Schema type '{$schema_type}' added successfully",
        ]);
    }
}

/**
 * Remove Schema Type Tool
 *
 * Removes a schema type from a post
 */
class MCP_Tool_Remove_Schema_Type extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_remove_schema_type';
    }

    public function get_description() {
        return 'Remove a schema type from a post';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'Post ID',
                ],
                'schema_type' => [
                    'type' => 'string',
                    'enum' => ['article', 'FAQPage', 'product', 'recipe'],
                    'description' => 'Schema type to remove',
                ],
            ],
            'required' => ['post_id', 'schema_type'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('edit_posts');

        $post_id = intval($params['post_id']);
        $schema_type = sanitize_text_field($params['schema_type']);

        // Validate post exists and user can edit it
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception(sprintf("Post not found: %d", absint($post_id)));
        }

        if (!current_user_can('edit_post', $post_id)) {
            throw new Exception('You do not have permission to edit this post');
        }

        // Get existing schema data
        $schema_data = get_post_meta($post_id, 'metasync_schema_markup', true);
        if (empty($schema_data) || empty($schema_data['types'])) {
            throw new Exception('No schema types found for this post');
        }

        // Remove the specified schema type
        $found = false;
        $new_types = [];
        foreach ($schema_data['types'] as $existing_type) {
            if ($existing_type['type'] === $schema_type) {
                $found = true;
                continue; // Skip this type (remove it)
            }
            $new_types[] = $existing_type;
        }

        if (!$found) {
            throw new Exception(sprintf("Schema type '%s' not found for this post", esc_html($schema_type)));
        }

        $schema_data['types'] = $new_types;

        // Save updated schema data
        update_post_meta($post_id, 'metasync_schema_markup', $schema_data);

        // Clear validation errors
        delete_post_meta($post_id, '_metasync_schema_validation_errors');

        return $this->success([
            'post_id' => $post_id,
            'schema_type' => $schema_type,
            'schema_types_count' => count($schema_data['types']),
            'message' => "Schema type '{$schema_type}' removed successfully",
        ]);
    }
}

/**
 * Validate Schema Tool
 *
 * Validates schema markup for a post
 */
class MCP_Tool_Validate_Schema extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_validate_schema';
    }

    public function get_description() {
        return 'Validate schema markup configuration for a post';
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

        // Get schema data
        $schema_data = get_post_meta($post_id, 'metasync_schema_markup', true);

        if (empty($schema_data) || !isset($schema_data['enabled']) || !$schema_data['enabled']) {
            return $this->success([
                'post_id' => $post_id,
                'valid' => true,
                'message' => 'Schema markup is not enabled for this post',
            ]);
        }

        if (empty($schema_data['types'])) {
            return $this->success([
                'post_id' => $post_id,
                'valid' => false,
                'errors' => [['message' => 'No schema types configured']],
            ]);
        }

        // Get the schema markup class for validation
        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'schema-markup/class-metasync-schema-markup.php';
        $schema_markup = new Metasync_Schema_Markup('metasync', METASYNC_VERSION);

        // Use reflection to access private validation method
        $reflection = new ReflectionClass($schema_markup);
        $validate_method = $reflection->getMethod('validate_schema_requirements');
        $validate_method->setAccessible(true);

        // Validate all schema types
        $all_errors = [];
        foreach ($schema_data['types'] as $schema_type_data) {
            $errors = $validate_method->invoke(
                $schema_markup,
                $post_id,
                $schema_type_data['type'],
                $schema_type_data['fields']
            );

            if (!empty($errors)) {
                $all_errors = array_merge($all_errors, $errors);
            }
        }

        $is_valid = empty($all_errors);

        // Update validation errors in post meta
        if ($is_valid) {
            delete_post_meta($post_id, '_metasync_schema_validation_errors');
        } else {
            update_post_meta($post_id, '_metasync_schema_validation_errors', $all_errors);
        }

        return $this->success([
            'post_id' => $post_id,
            'valid' => $is_valid,
            'errors' => $all_errors,
            'message' => $is_valid ? 'Schema markup is valid' : 'Schema markup has validation errors',
        ]);
    }
}

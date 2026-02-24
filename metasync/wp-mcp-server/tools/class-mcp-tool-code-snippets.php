<?php
/**
 * MCP Tools for Code Snippets Operations
 *
 * Provides MCP tools for managing header and footer code snippets
 * (both global and post-level).
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(dirname(__FILE__)) . 'class-mcp-tool-base.php';

/**
 * Get Header Snippet Tool
 *
 * Gets global header code snippet
 */
class MCP_Tool_Get_Header_Snippet extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_header_snippet';
    }

    public function get_description() {
        return 'Get global header code snippet that appears on all pages';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $options = get_option(Metasync::option_name, []);
        $code_snippets = isset($options['codesnippets']) ? $options['codesnippets'] : [];
        $header_snippet = isset($code_snippets['header_snippet']) ? $code_snippets['header_snippet'] : '';

        return $this->success([
            'header_snippet' => $header_snippet,
            'length' => strlen($header_snippet),
            'enabled' => !empty($header_snippet),
        ]);
    }
}

/**
 * Update Header Snippet Tool
 *
 * Updates global header code snippet
 */
class MCP_Tool_Update_Header_Snippet extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_header_snippet';
    }

    public function get_description() {
        return 'Update global header code snippet (HTML/JS/CSS) that appears on all pages';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'header_snippet' => [
                    'type' => 'string',
                    'description' => 'Header code snippet (HTML/JS/CSS)',
                ],
            ],
            'required' => ['header_snippet'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $header_snippet = wp_unslash($params['header_snippet']);

        // Get current options
        $options = get_option(Metasync::option_name, []);

        // Initialize codesnippets section if not exists
        if (!isset($options['codesnippets'])) {
            $options['codesnippets'] = [];
        }

        // Update header snippet
        $options['codesnippets']['header_snippet'] = $header_snippet;

        // Save options
        update_option(Metasync::option_name, $options);

        return $this->success([
            'header_snippet' => $header_snippet,
            'length' => strlen($header_snippet),
            'message' => 'Header snippet updated successfully',
        ]);
    }
}

/**
 * Get Footer Snippet Tool
 *
 * Gets global footer code snippet
 */
class MCP_Tool_Get_Footer_Snippet extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_footer_snippet';
    }

    public function get_description() {
        return 'Get global footer code snippet that appears on all pages';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $options = get_option(Metasync::option_name, []);
        $code_snippets = isset($options['codesnippets']) ? $options['codesnippets'] : [];
        $footer_snippet = isset($code_snippets['footer_snippet']) ? $code_snippets['footer_snippet'] : '';

        return $this->success([
            'footer_snippet' => $footer_snippet,
            'length' => strlen($footer_snippet),
            'enabled' => !empty($footer_snippet),
        ]);
    }
}

/**
 * Update Footer Snippet Tool
 *
 * Updates global footer code snippet
 */
class MCP_Tool_Update_Footer_Snippet extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_footer_snippet';
    }

    public function get_description() {
        return 'Update global footer code snippet (HTML/JS/CSS) that appears on all pages';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'footer_snippet' => [
                    'type' => 'string',
                    'description' => 'Footer code snippet (HTML/JS/CSS)',
                ],
            ],
            'required' => ['footer_snippet'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $footer_snippet = wp_unslash($params['footer_snippet']);

        // Get current options
        $options = get_option(Metasync::option_name, []);

        // Initialize codesnippets section if not exists
        if (!isset($options['codesnippets'])) {
            $options['codesnippets'] = [];
        }

        // Update footer snippet
        $options['codesnippets']['footer_snippet'] = $footer_snippet;

        // Save options
        update_option(Metasync::option_name, $options);

        return $this->success([
            'footer_snippet' => $footer_snippet,
            'length' => strlen($footer_snippet),
            'message' => 'Footer snippet updated successfully',
        ]);
    }
}

/**
 * Get Post Snippets Tool
 *
 * Gets post-level code snippets
 */
class MCP_Tool_Get_Post_Snippets extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_post_snippets';
    }

    public function get_description() {
        return 'Get post-level code snippets (header and footer) for a specific post';
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

        // Get all post-level snippets
        $custom_post_header = get_post_meta($post_id, 'custom_post_header', true);
        $custom_post_footer = get_post_meta($post_id, 'custom_post_footer', true);
        $searchatlas_embed_top = get_post_meta($post_id, 'searchatlas_embed_top', true);
        $searchatlas_embed_bottom = get_post_meta($post_id, 'searchatlas_embed_bottom', true);

        return $this->success([
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'snippets' => [
                'custom_post_header' => $custom_post_header ?: '',
                'custom_post_footer' => $custom_post_footer ?: '',
                'searchatlas_embed_top' => $searchatlas_embed_top ?: '',
                'searchatlas_embed_bottom' => $searchatlas_embed_bottom ?: '',
            ],
            'has_header_snippets' => !empty($custom_post_header) || !empty($searchatlas_embed_top),
            'has_footer_snippets' => !empty($custom_post_footer) || !empty($searchatlas_embed_bottom),
        ]);
    }
}

/**
 * Update Post Snippets Tool
 *
 * Updates post-level code snippets
 */
class MCP_Tool_Update_Post_Snippets extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_post_snippets';
    }

    public function get_description() {
        return 'Update post-level code snippets (header and footer) for a specific post';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'Post ID',
                ],
                'custom_post_header' => [
                    'type' => 'string',
                    'description' => 'Custom header snippet for this post (optional)',
                ],
                'custom_post_footer' => [
                    'type' => 'string',
                    'description' => 'Custom footer snippet for this post (optional)',
                ],
                'searchatlas_embed_top' => [
                    'type' => 'string',
                    'description' => 'SearchAtlas embed top snippet (optional)',
                ],
                'searchatlas_embed_bottom' => [
                    'type' => 'string',
                    'description' => 'SearchAtlas embed bottom snippet (optional)',
                ],
            ],
            'required' => ['post_id'],
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

        // Update snippets if provided
        if (isset($params['custom_post_header'])) {
            update_post_meta($post_id, 'custom_post_header', wp_unslash($params['custom_post_header']));
        }

        if (isset($params['custom_post_footer'])) {
            update_post_meta($post_id, 'custom_post_footer', wp_unslash($params['custom_post_footer']));
        }

        if (isset($params['searchatlas_embed_top'])) {
            update_post_meta($post_id, 'searchatlas_embed_top', wp_unslash($params['searchatlas_embed_top']));
        }

        if (isset($params['searchatlas_embed_bottom'])) {
            update_post_meta($post_id, 'searchatlas_embed_bottom', wp_unslash($params['searchatlas_embed_bottom']));
        }

        // Get updated snippets
        $custom_post_header = get_post_meta($post_id, 'custom_post_header', true);
        $custom_post_footer = get_post_meta($post_id, 'custom_post_footer', true);
        $searchatlas_embed_top = get_post_meta($post_id, 'searchatlas_embed_top', true);
        $searchatlas_embed_bottom = get_post_meta($post_id, 'searchatlas_embed_bottom', true);

        return $this->success([
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'snippets' => [
                'custom_post_header' => $custom_post_header ?: '',
                'custom_post_footer' => $custom_post_footer ?: '',
                'searchatlas_embed_top' => $searchatlas_embed_top ?: '',
                'searchatlas_embed_bottom' => $searchatlas_embed_bottom ?: '',
            ],
            'message' => 'Post snippets updated successfully',
        ]);
    }
}

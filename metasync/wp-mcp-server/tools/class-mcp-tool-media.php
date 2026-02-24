<?php
/**
 * MCP Tools for Media Operations
 *
 * Provides MCP tools for managing featured images and media library.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(dirname(__FILE__)) . 'class-mcp-tool-base.php';

/**
 * Get Featured Image Tool
 *
 * Gets featured image for a post
 */
class MCP_Tool_Get_Featured_Image extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_featured_image';
    }

    public function get_description() {
        return 'Get featured image details for a post';
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

        $thumbnail_id = get_post_thumbnail_id($post_id);

        if (!$thumbnail_id) {
            return $this->success([
                'post_id' => $post_id,
                'post_title' => $post->post_title,
                'has_featured_image' => false,
                'featured_image' => null,
            ]);
        }

        $attachment = get_post($thumbnail_id);
        $metadata = wp_get_attachment_metadata($thumbnail_id);

        return $this->success([
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'has_featured_image' => true,
            'featured_image' => [
                'attachment_id' => $thumbnail_id,
                'url' => wp_get_attachment_url($thumbnail_id),
                'title' => $attachment->post_title,
                'alt_text' => get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true),
                'caption' => $attachment->post_excerpt,
                'description' => $attachment->post_content,
                'mime_type' => $attachment->post_mime_type,
                'width' => isset($metadata['width']) ? $metadata['width'] : null,
                'height' => isset($metadata['height']) ? $metadata['height'] : null,
                'file_size' => isset($metadata['filesize']) ? $metadata['filesize'] : filesize(get_attached_file($thumbnail_id)),
            ],
        ]);
    }
}

/**
 * Set Featured Image Tool
 *
 * Sets featured image for a post by attachment ID
 */
class MCP_Tool_Set_Featured_Image extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_set_featured_image';
    }

    public function get_description() {
        return 'Set featured image for a post using an existing attachment ID';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'Post ID',
                ],
                'attachment_id' => [
                    'type' => 'integer',
                    'description' => 'Attachment ID of the image',
                ],
            ],
            'required' => ['post_id', 'attachment_id'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('edit_posts');

        $post_id = intval($params['post_id']);
        $attachment_id = intval($params['attachment_id']);

        // Validate post exists and user can edit it
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception(sprintf("Post not found: %d", absint($post_id)));
        }

        if (!current_user_can('edit_post', $post_id)) {
            throw new Exception('You do not have permission to edit this post');
        }

        // Validate attachment exists and is an image
        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            throw new Exception(sprintf("Attachment not found: %d", absint($attachment_id)));
        }

        if (!wp_attachment_is_image($attachment_id)) {
            throw new Exception('Attachment must be an image');
        }

        // Set featured image
        $result = set_post_thumbnail($post_id, $attachment_id);

        if (!$result) {
            throw new Exception('Failed to set featured image');
        }

        return $this->success([
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'attachment_id' => $attachment_id,
            'image_url' => wp_get_attachment_url($attachment_id),
            'message' => 'Featured image set successfully',
        ]);
    }
}

/**
 * Upload Featured Image Tool
 *
 * Uploads a new image and sets it as featured image
 */
class MCP_Tool_Upload_Featured_Image extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_upload_featured_image';
    }

    public function get_description() {
        return 'Upload a new image from URL and set it as featured image for a post';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'Post ID',
                ],
                'image_url' => [
                    'type' => 'string',
                    'description' => 'URL of the image to upload',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Image title (optional, defaults to filename)',
                ],
                'alt_text' => [
                    'type' => 'string',
                    'description' => 'Image alt text (optional)',
                ],
            ],
            'required' => ['post_id', 'image_url'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('upload_files');

        $post_id = intval($params['post_id']);
        $image_url = esc_url_raw($params['image_url']);

        // Validate post exists and user can edit it
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception(sprintf("Post not found: %d", absint($post_id)));
        }

        if (!current_user_can('edit_post', $post_id)) {
            throw new Exception('You do not have permission to edit this post');
        }

        // Validate image URL
        if (empty($image_url)) {
            throw new Exception('Invalid image URL');
        }

        // Download image
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($image_url);

        if (is_wp_error($tmp)) {
            throw new Exception('Failed to download image: ' . $tmp->get_error_message());
        }

        // Prepare file array
        $file_array = [
            'name' => basename($image_url),
            'tmp_name' => $tmp,
        ];

        // Upload to media library
        $attachment_id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            throw new Exception('Failed to upload image: ' . $attachment_id->get_error_message());
        }

        // Set title if provided
        if (isset($params['title'])) {
            wp_update_post([
                'ID' => $attachment_id,
                'post_title' => sanitize_text_field($params['title']),
            ]);
        }

        // Set alt text if provided
        if (isset($params['alt_text'])) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($params['alt_text']));
        }

        // Set as featured image
        set_post_thumbnail($post_id, $attachment_id);

        return $this->success([
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'attachment_id' => $attachment_id,
            'image_url' => wp_get_attachment_url($attachment_id),
            'message' => 'Image uploaded and set as featured image successfully',
        ]);
    }
}

/**
 * Remove Featured Image Tool
 *
 * Removes featured image from a post
 */
class MCP_Tool_Remove_Featured_Image extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_remove_featured_image';
    }

    public function get_description() {
        return 'Remove featured image from a post';
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

        // Validate post exists and user can edit it
        $post = get_post($post_id);
        if (!$post) {
            throw new Exception(sprintf("Post not found: %d", absint($post_id)));
        }

        if (!current_user_can('edit_post', $post_id)) {
            throw new Exception('You do not have permission to edit this post');
        }

        $thumbnail_id = get_post_thumbnail_id($post_id);

        if (!$thumbnail_id) {
            return $this->success([
                'post_id' => $post_id,
                'post_title' => $post->post_title,
                'message' => 'Post has no featured image',
            ]);
        }

        // Remove featured image
        $result = delete_post_thumbnail($post_id);

        if (!$result) {
            throw new Exception('Failed to remove featured image');
        }

        return $this->success([
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'message' => 'Featured image removed successfully',
        ]);
    }
}

/**
 * List Media Tool
 *
 * Lists media library items
 */
class MCP_Tool_List_Media extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_list_media';
    }

    public function get_description() {
        return 'List media library items with optional filters';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'mime_type' => [
                    'type' => 'string',
                    'description' => 'Filter by MIME type (e.g., image/jpeg, image/png, image)',
                ],
                'per_page' => [
                    'type' => 'integer',
                    'description' => 'Number of items per page (default: 20, max: 100)',
                ],
                'page' => [
                    'type' => 'integer',
                    'description' => 'Page number (default: 1)',
                ],
                'orderby' => [
                    'type' => 'string',
                    'enum' => ['date', 'title', 'name'],
                    'description' => 'Order by field (default: date)',
                ],
                'order' => [
                    'type' => 'string',
                    'enum' => ['ASC', 'DESC'],
                    'description' => 'Sort order (default: DESC)',
                ],
            ],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('upload_files');

        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => isset($params['per_page']) ? min(intval($params['per_page']), 100) : 20,
            'paged' => isset($params['page']) ? intval($params['page']) : 1,
            'orderby' => isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'date',
            'order' => isset($params['order']) ? sanitize_text_field($params['order']) : 'DESC',
        ];

        if (isset($params['mime_type'])) {
            $args['post_mime_type'] = sanitize_text_field($params['mime_type']);
        }

        $query = new WP_Query($args);

        $media_items = [];
        foreach ($query->posts as $attachment) {
            $metadata = wp_get_attachment_metadata($attachment->ID);

            $media_items[] = [
                'attachment_id' => $attachment->ID,
                'url' => wp_get_attachment_url($attachment->ID),
                'title' => $attachment->post_title,
                'alt_text' => get_post_meta($attachment->ID, '_wp_attachment_image_alt', true),
                'caption' => $attachment->post_excerpt,
                'mime_type' => $attachment->post_mime_type,
                'width' => isset($metadata['width']) ? $metadata['width'] : null,
                'height' => isset($metadata['height']) ? $metadata['height'] : null,
                'file_size' => isset($metadata['filesize']) ? $metadata['filesize'] : null,
                'uploaded_at' => $attachment->post_date,
            ];
        }

        return $this->success([
            'total' => $query->found_posts,
            'page' => $args['paged'],
            'per_page' => $args['posts_per_page'],
            'total_pages' => $query->max_num_pages,
            'media' => $media_items,
        ]);
    }
}

/**
 * Get Media Details Tool
 *
 * Gets detailed information about a media attachment
 */
class MCP_Tool_Get_Media_Details extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_media_details';
    }

    public function get_description() {
        return 'Get detailed information about a media attachment';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'attachment_id' => [
                    'type' => 'integer',
                    'description' => 'Attachment ID',
                ],
            ],
            'required' => ['attachment_id'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('upload_files');

        $attachment_id = intval($params['attachment_id']);

        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            throw new Exception(sprintf("Attachment not found: %d", absint($attachment_id)));
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        $file_path = get_attached_file($attachment_id);

        $result = [
            'attachment_id' => $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
            'title' => $attachment->post_title,
            'alt_text' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            'caption' => $attachment->post_excerpt,
            'description' => $attachment->post_content,
            'mime_type' => $attachment->post_mime_type,
            'file_name' => basename($file_path),
            'file_path' => $file_path,
            'file_size' => file_exists($file_path) ? filesize($file_path) : null,
            'uploaded_at' => $attachment->post_date,
            'uploaded_by' => $attachment->post_author,
        ];

        // Add image-specific data
        if (wp_attachment_is_image($attachment_id)) {
            $result['width'] = isset($metadata['width']) ? $metadata['width'] : null;
            $result['height'] = isset($metadata['height']) ? $metadata['height'] : null;
            $result['sizes'] = isset($metadata['sizes']) ? $metadata['sizes'] : [];
        }

        return $this->success($result);
    }
}

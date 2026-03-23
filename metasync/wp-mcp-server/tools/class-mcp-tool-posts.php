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
        $post = $this->verify_post_exists($post_id);

        // SECURITY: Check user has permission to read this specific post
        $this->check_post_permission($post_id);

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

        $post_status = isset($params['post_status']) ? $params['post_status'] : 'publish';

        // SECURITY: Sensitive statuses require edit_posts capability
        $sensitive_statuses = ['private', 'draft', 'pending', 'any'];
        if (in_array($post_status, $sensitive_statuses, true)) {
            $this->require_capability('edit_posts');
        }

        // Build query args
        $args = [
            'post_type' => isset($params['post_type']) ? $params['post_type'] : 'any',
            'post_status' => $post_status,
            'posts_per_page' => isset($params['limit']) ? $this->sanitize_integer($params['limit']) : 10,
            'offset'         => isset($params['offset']) ? $this->sanitize_integer($params['offset']) : 0,
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
        $post = $this->verify_post_exists($post_id);

        // SECURITY: Check user has permission to edit this specific post
        $this->check_post_permission($post_id);

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
            'properties' => (object)[],
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

/**
 * Get Post By URL Tool
 */
class MCP_Tool_Get_Post_By_URL extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_post_by_url';
    }

    public function get_description() {
        return 'Resolve a WordPress URL to its post ID and basic metadata. Accepts full URLs, relative paths, or slugs.';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'Full URL (https://example.com/my-post/), relative path (/my-post/), or slug (my-post)'
                ],
                'post_type' => [
                    'type' => 'string',
                    'description' => 'Narrow search to a specific post type (post, page, any). Defaults to any.',
                    'default' => 'any'
                ]
            ],
            'required' => ['url']
        ];
    }

    public function execute( $params ) {
        $this->validate_params( $params );
        $this->require_capability( 'read' );

        $raw        = trim( $this->sanitize_string( $params['url'] ) );
        $post_type  = isset( $params['post_type'] ) ? $this->sanitize_string( $params['post_type'] ) : 'any';

        if ( empty( $raw ) ) {
            throw new InvalidArgumentException( 'URL cannot be empty' );
        }

        // ── Strategy 1: url_to_postid() — handles full URLs and relative paths ──
        $post_id = $this->resolve_by_url( $raw );

        // ── Strategy 2: slug lookup — if input looks like a bare slug ──
        if ( ! $post_id ) {
            $post_id = $this->resolve_by_slug( $raw, $post_type );
        }

        // ── Strategy 3: strip query string / fragment and retry ──
        if ( ! $post_id ) {
            $clean = strtok( $raw, '?' );
            $clean = strtok( $clean, '#' );
            if ( $clean !== $raw ) {
                $post_id = $this->resolve_by_url( $clean );
            }
        }

        if ( ! $post_id ) {
            return $this->error( 'Post not found for the given URL. Tried url_to_postid() and slug lookup.' );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return $this->error( 'Post ID resolved but post no longer exists.' );
        }

        return $this->success( [
            'post_id'    => $post->ID,
            'title'      => $post->post_title,
            'post_type'  => $post->post_type,
            'post_status'=> $post->post_status,
            'slug'       => $post->post_name,
            'url'        => get_permalink( $post->ID ),
            'edit_url'   => get_edit_post_link( $post->ID, 'raw' ),
        ], 'Post resolved successfully' );
    }

    /**
     * Resolve via WordPress core url_to_postid().
     * Handles full URLs and relative paths by ensuring a full URL is passed.
     *
     * @param string $url
     * @return int|false
     */
    private function resolve_by_url( $url ) {
        // Make relative paths absolute so url_to_postid() can parse them
        if ( strpos( $url, 'http' ) !== 0 ) {
            $url = home_url( ltrim( $url, '/' ) );
        }

        $id = url_to_postid( $url );
        return $id ? (int) $id : false;
    }

    /**
     * Resolve by treating the input as a slug.
     * Extracts the last non-empty path segment from URLs, or uses the raw value directly.
     *
     * @param string $raw
     * @param string $post_type
     * @return int|false
     */
    private function resolve_by_slug( $raw, $post_type ) {
        // Extract last path segment (handles /blog/my-post/ → my-post)
        $path     = parse_url( $raw, PHP_URL_PATH );
        $segments = array_filter( explode( '/', $path ?? $raw ) );
        $slug     = sanitize_title( end( $segments ) );

        if ( empty( $slug ) ) {
            return false;
        }

        $args = [
            'name'           => $slug,
            'post_type'      => $post_type === 'any' ? [ 'post', 'page' ] : $post_type,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ];

        $query = new WP_Query( $args );

        if ( $query->have_posts() ) {
            return (int) $query->posts[0]->ID;
        }

        return false;
    }
}

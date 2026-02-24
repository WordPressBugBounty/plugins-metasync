<?php
/**
 * MCP Tool: Search Operations
 *
 * Provides tools for searching WordPress content.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Search Posts Tool
 */
class MCP_Tool_Search_Posts extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_search_posts';
    }

    public function get_description() {
        return 'Search WordPress posts and pages by title or content';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'search' => [
                    'type' => 'string',
                    'description' => 'Search term to find in post titles and content'
                ],
                'post_type' => [
                    'type' => 'string',
                    'description' => 'Post type to search',
                    'enum' => ['post', 'page', 'any'],
                    'default' => 'any'
                ],
                'post_status' => [
                    'type' => 'string',
                    'description' => 'Post status filter',
                    'enum' => ['publish', 'draft', 'pending', 'any'],
                    'default' => 'publish'
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results',
                    'default' => 10,
                    'minimum' => 1,
                    'maximum' => 100
                ]
            ],
            'required' => ['search']
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('read');

        $search_term = $this->sanitize_string($params['search']);

        if (empty($search_term)) {
            throw new InvalidArgumentException('Search term cannot be empty');
        }

        // Build query args
        $args = [
            's' => $search_term,
            'post_type' => isset($params['post_type']) ? $params['post_type'] : 'any',
            'post_status' => isset($params['post_status']) ? $params['post_status'] : 'publish',
            'posts_per_page' => isset($params['limit']) ? $this->sanitize_integer($params['limit']) : 10,
            'orderby' => 'relevance',
            'order' => 'DESC'
        ];

        // Execute search
        $query = new WP_Query($args);
        $results = [];

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();

                // Get excerpt with search term highlighted
                $excerpt = get_the_excerpt();
                if (empty($excerpt)) {
                    $excerpt = wp_trim_words(get_the_content(), 30);
                }

                $results[] = [
                    'id' => $post_id,
                    'title' => get_the_title(),
                    'excerpt' => $excerpt,
                    'type' => get_post_type(),
                    'status' => get_post_status(),
                    'date' => get_the_date('c'),
                    'url' => get_permalink(),
                    'edit_url' => get_edit_post_link($post_id, 'raw')
                ];
            }
            wp_reset_postdata();
        }

        return $this->success([
            'results' => $results,
            'total_found' => $query->found_posts,
            'search_term' => $search_term,
            'query' => [
                'post_type' => $args['post_type'],
                'post_status' => $args['post_status'],
                'limit' => $args['posts_per_page']
            ]
        ]);
    }
}

/**
 * Search by Keyword Tool
 */
class MCP_Tool_Search_By_Keyword extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_search_by_keyword';
    }

    public function get_description() {
        return 'Find posts that have a specific focus keyword set';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'keyword' => [
                    'type' => 'string',
                    'description' => 'Focus keyword to search for'
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results',
                    'default' => 10,
                    'minimum' => 1,
                    'maximum' => 100
                ]
            ],
            'required' => ['keyword']
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('read');

        $keyword = $this->sanitize_string($params['keyword']);
        $limit = isset($params['limit']) ? $this->sanitize_integer($params['limit']) : 10;

        if (empty($keyword)) {
            throw new InvalidArgumentException('Keyword cannot be empty');
        }

        // Query posts with this focus keyword
        $args = [
            'post_type' => 'any',
            'post_status' => 'any',
            'posts_per_page' => $limit,
            'meta_query' => [
                [
                    'key' => '_metasync_focus_keyword',
                    'value' => $keyword,
                    'compare' => 'LIKE'
                ]
            ]
        ];

        $query = new WP_Query($args);
        $results = [];

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();

                $results[] = [
                    'id' => $post_id,
                    'title' => get_the_title(),
                    'type' => get_post_type(),
                    'status' => get_post_status(),
                    'url' => get_permalink(),
                    'focus_keyword' => get_post_meta($post_id, '_metasync_focus_keyword', true),
                    'meta_title' => get_post_meta($post_id, '_metasync_metatitle', true)
                ];
            }
            wp_reset_postdata();
        }

        return $this->success([
            'results' => $results,
            'total_found' => $query->found_posts,
            'keyword' => $keyword
        ]);
    }
}

/**
 * Find Missing Meta Tool
 */
class MCP_Tool_Find_Missing_Meta extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_find_missing_meta';
    }

    public function get_description() {
        return 'Find posts that are missing meta descriptions, titles, or focus keywords';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'missing_field' => [
                    'type' => 'string',
                    'description' => 'Which meta field to check for',
                    'enum' => ['meta_title', 'meta_description', 'focus_keyword', 'any'],
                    'default' => 'meta_description'
                ],
                'post_type' => [
                    'type' => 'string',
                    'description' => 'Post type to check',
                    'enum' => ['post', 'page', 'any'],
                    'default' => 'post'
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results',
                    'default' => 10,
                    'minimum' => 1,
                    'maximum' => 100
                ]
            ],
            'required' => []
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('read');

        $missing_field = isset($params['missing_field']) ? $params['missing_field'] : 'meta_description';
        $post_type = isset($params['post_type']) ? $params['post_type'] : 'post';
        $limit = isset($params['limit']) ? $this->sanitize_integer($params['limit']) : 10;

        // Map field names to meta keys
        $meta_key_map = [
            'meta_title' => '_metasync_metatitle',
            'meta_description' => '_metasync_metadesc',
            'focus_keyword' => '_metasync_focus_keyword'
        ];

        // Build query
        $args = [
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        if ($missing_field !== 'any') {
            $meta_key = $meta_key_map[$missing_field];
            $args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key' => $meta_key,
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key' => $meta_key,
                    'value' => '',
                    'compare' => '='
                ]
            ];
        }

        $query = new WP_Query($args);
        $results = [];

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();

                $meta_title = get_post_meta($post_id, '_metasync_metatitle', true);
                $meta_desc = get_post_meta($post_id, '_metasync_metadesc', true);
                $focus_kw = get_post_meta($post_id, '_metasync_focus_keyword', true);

                // For 'any', check if at least one field is missing
                if ($missing_field === 'any') {
                    if (!empty($meta_title) && !empty($meta_desc) && !empty($focus_kw)) {
                        continue; // Skip if all fields are present
                    }
                }

                $results[] = [
                    'id' => $post_id,
                    'title' => get_the_title(),
                    'type' => get_post_type(),
                    'url' => get_permalink(),
                    'edit_url' => get_edit_post_link($post_id, 'raw'),
                    'missing' => [
                        'meta_title' => empty($meta_title),
                        'meta_description' => empty($meta_desc),
                        'focus_keyword' => empty($focus_kw)
                    ]
                ];
            }
            wp_reset_postdata();
        }

        return $this->success([
            'results' => $results,
            'total_found' => count($results),
            'checked_field' => $missing_field,
            'post_type' => $post_type
        ]);
    }
}

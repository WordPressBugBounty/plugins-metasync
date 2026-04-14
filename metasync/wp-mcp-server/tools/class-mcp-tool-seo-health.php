<?php
/**
 * MCP Tool: SEO Health Report
 *
 * Returns SEO health data for all posts as paginated JSON.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

class MCP_Tool_SEO_Health_Report extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_seo_health_report';
    }

    public function get_description() {
        return 'Get SEO health report for all posts/pages with completeness indicators';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_type' => [
                    'type' => 'string',
                    'description' => 'Filter by post type (e.g. post, page, product)',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['publish', 'draft', 'any'],
                    'description' => 'Filter by post status (default: any)',
                ],
                'page' => [
                    'type' => 'integer',
                    'description' => 'Page number (default: 1)',
                    'minimum' => 1,
                ],
                'per_page' => [
                    'type' => 'integer',
                    'description' => 'Results per page (default: 20)',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
                'filter' => [
                    'type' => 'string',
                    'enum' => ['missing_title', 'missing_description', 'missing_schema', 'missing_og_image', 'missing_alt_text'],
                    'description' => 'Filter by missing SEO element',
                ],
            ],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('edit_posts');

        $page = isset($params['page']) ? absint($params['page']) : 1;
        $per_page = isset($params['per_page']) ? absint($params['per_page']) : 20;
        $post_type_filter = isset($params['post_type']) ? sanitize_text_field($params['post_type']) : '';
        $status_filter = isset($params['status']) ? sanitize_text_field($params['status']) : 'any';
        $missing_filter = isset($params['filter']) ? sanitize_text_field($params['filter']) : '';

        // Determine post types
        $post_types = ['post', 'page'];
        if (class_exists('WooCommerce')) {
            $post_types[] = 'product';
        }
        $custom_types = get_post_types(['public' => true, '_builtin' => false], 'names');
        foreach ($custom_types as $cpt) {
            if (!in_array($cpt, $post_types, true)) {
                $post_types[] = $cpt;
            }
        }

        $args = [
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'post_type'      => !empty($post_type_filter) ? $post_type_filter : $post_types,
            'post_status'    => ($status_filter === 'any') ? ['publish', 'draft'] : $status_filter,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $query = new WP_Query($args);
        $posts = $query->posts;

        // Apply missing filter
        if (!empty($missing_filter)) {
            $posts = $this->apply_missing_filter($posts, $missing_filter);
        }

        $posts_data = [];
        foreach ($posts as $post) {
            $title_result = $this->get_seo_meta_with_fallback($post->ID, 'title');
            $desc_result = $this->get_seo_meta_with_fallback($post->ID, 'description');
            $has_schema = !empty(get_post_meta($post->ID, 'metasync_schema_markup', true));

            $og_image = get_post_meta($post->ID, '_metasync_og_image', true);
            if (empty($og_image)) {
                $thumbnail_id = get_post_meta($post->ID, '_thumbnail_id', true);
                if (!empty($thumbnail_id)) {
                    $og_image = wp_get_attachment_url($thumbnail_id);
                }
            }

            $alt_coverage = $this->calculate_alt_text_coverage($post->post_content);

            $posts_data[] = [
                'post_id'          => $post->ID,
                'title'            => $post->post_title,
                'post_type'        => $post->post_type,
                'status'           => $post->post_status,
                'seo_title'        => !empty($title_result['value']) ? $title_result['value'] : null,
                'seo_title_source' => !empty($title_result['source']) ? $title_result['source'] : null,
                'meta_description' => !empty($desc_result['value']) ? $desc_result['value'] : null,
                'meta_desc_source' => !empty($desc_result['source']) ? $desc_result['source'] : null,
                'has_schema'       => $has_schema,
                'has_og_image'     => !empty($og_image),
                'alt_text_coverage' => $alt_coverage,
                'last_modified'    => get_the_modified_date('c', $post),
            ];
        }

        return $this->success([
            'total'    => $query->found_posts,
            'page'     => $page,
            'per_page' => $per_page,
            'posts'    => $posts_data,
        ]);
    }

    private function get_seo_meta_with_fallback($post_id, $field) {
        $meta_keys = [];

        if ($field === 'title') {
            $meta_keys = [
                '_metasync_metatitle'      => '',
                '_metasync_otto_title'     => 'OTTO',
                '_yoast_wpseo_title'       => 'Yoast',
                'rank_math_title'          => 'Rank Math',
                '_aioseo_title'            => 'AIOSEO',
                '_metasync_og_title'       => 'OG',
            ];
        } elseif ($field === 'description') {
            $meta_keys = [
                '_metasync_metadesc'           => '',
                '_metasync_otto_description'   => 'OTTO',
                '_yoast_wpseo_metadesc'        => 'Yoast',
                'rank_math_description'        => 'Rank Math',
                '_aioseo_description'          => 'AIOSEO',
                'meta_description'             => '',
                '_metasync_og_description'     => 'OG',
            ];
        }

        foreach ($meta_keys as $meta_key => $source) {
            $value = get_post_meta($post_id, $meta_key, true);
            if (!empty($value)) {
                return ['value' => $value, 'source' => $source];
            }
        }

        return ['value' => '', 'source' => ''];
    }

    private function calculate_alt_text_coverage($content) {
        if (!preg_match_all('/<img[^>]*>/i', $content, $matches)) {
            return 100.0; // No images = 100% coverage
        }

        $total = count($matches[0]);
        $with_alt = 0;

        foreach ($matches[0] as $img_tag) {
            if (preg_match('/alt\s*=\s*["\']([^"\']+)["\']/i', $img_tag)) {
                $with_alt++;
            }
        }

        return $total > 0 ? round(($with_alt / $total) * 100, 1) : 100.0;
    }

    private function apply_missing_filter($posts, $filter) {
        $filtered = [];

        foreach ($posts as $post) {
            switch ($filter) {
                case 'missing_title':
                    $result = $this->get_seo_meta_with_fallback($post->ID, 'title');
                    if (empty($result['value'])) {
                        $filtered[] = $post;
                    }
                    break;

                case 'missing_description':
                    $result = $this->get_seo_meta_with_fallback($post->ID, 'description');
                    if (empty($result['value'])) {
                        $filtered[] = $post;
                    }
                    break;

                case 'missing_schema':
                    if (empty(get_post_meta($post->ID, 'metasync_schema_markup', true))) {
                        $filtered[] = $post;
                    }
                    break;

                case 'missing_og_image':
                    $og = get_post_meta($post->ID, '_metasync_og_image', true);
                    if (empty($og) && empty(get_post_meta($post->ID, '_thumbnail_id', true))) {
                        $filtered[] = $post;
                    }
                    break;

                case 'missing_alt_text':
                    if (preg_match_all('/<img[^>]*>/i', $post->post_content, $matches)) {
                        foreach ($matches[0] as $img_tag) {
                            if (!preg_match('/alt\s*=\s*["\']([^"\']+)["\']/i', $img_tag)) {
                                $filtered[] = $post;
                                break;
                            }
                        }
                    }
                    break;

                default:
                    $filtered[] = $post;
                    break;
            }
        }

        return $filtered;
    }
}

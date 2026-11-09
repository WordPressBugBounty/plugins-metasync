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

        if (!empty($missing_filter)) {
            // Missing-field predicates include precedence fallbacks and HTML
            // inspection, so candidates are scanned in bounded ID-keyset
            // chunks, matched in PHP, and the matching IDs cached so pages and
            // re-visits don't rescan. The list is keyed per consumer ('mcp'):
            // the admin list table caches under the same namespace but with
            // different predicates (structured has_schema() vs raw meta), so
            // the lists must never share a key.
            $use_id_cache = $this->class_provides('Metasync_SEO_Health', 'matching_ids_transient_key');
            $cache_key = $use_id_cache
                ? Metasync_SEO_Health::matching_ids_transient_key($missing_filter, $args['post_type'], $args['post_status'], '', 'mcp')
                : '';
            $matching_ids = $use_id_cache ? get_transient($cache_key) : false;

            if (!is_array($matching_ids)) {
                $matching_ids = $this->scan_matching_ids($args, $missing_filter);
                if ($use_id_cache) {
                    $ttl = defined('Metasync_SEO_Health::IDS_CACHE_TTL') ? constant('Metasync_SEO_Health::IDS_CACHE_TTL') : 300;
                    set_transient($cache_key, $matching_ids, $ttl);
                }
            }

            $total = count($matching_ids);
            $page_ids = array_slice($matching_ids, ($page - 1) * $per_page, $per_page);
            $posts = empty($page_ids) ? [] : get_posts([
                'post__in' => $page_ids,
                'orderby' => 'post__in',
                'posts_per_page' => count($page_ids),
                'post_type' => $args['post_type'],
                'post_status' => $args['post_status'],
                'no_found_rows' => true,
            ]);
        } else {
            // Exclude custom/LPS pages — their SEO is self-managed, so agents must
            // not see them flagged as missing SEO.
            $args['metasync_exclude_custom_pages'] = true;
            add_filter('posts_where', 'metasync_filter_exclude_custom_pages_where', 10, 2);

            try {
                $query = new WP_Query($args);
            } finally {
                remove_filter('posts_where', 'metasync_filter_exclude_custom_pages_where', 10);
            }
            $posts = $query->posts;
            $total = $query->found_posts;
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
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'posts'    => $posts_data,
        ]);
    }

    /**
     * Delegate to the admin SEO Health resolver so the MCP report, the table,
     * the summary cards, the missing filters and the CSV export all settle a
     * field through the same precedence chain instead of a private copy of it
     * drifting behind. Metasync_SEO_Health is composer-classmapped, so the
     * class_exists() check autoloads it here.
     */
    private function get_seo_meta_with_fallback($post_id, $field) {
        if ($this->class_provides('Metasync_SEO_Health', 'get_seo_meta_with_fallback')) {
            return Metasync_SEO_Health::get_seo_meta_with_fallback($post_id, $field);
        }

        return ['value' => '', 'source' => ''];
    }

    /**
     * $class and $method arrive as parameters rather than literals so the
     * availability check survives static analysis, which narrows a literal
     * method_exists() on a known class to a constant true. The skew this
     * guards against — a partially updated install pairing this file with an
     * older admin class that lacks the method — only exists at runtime.
     *
     * @param string $class  Class about to be called.
     * @param string $method Method about to be called on it.
     * @return bool
     */
    private function class_provides($class, $method) {
        return class_exists($class) && method_exists($class, $method);
    }

    /**
     * Scan candidate posts in bounded ID-keyset chunks and retain matching IDs,
     * newest first (the ordering the unfiltered report and the pre-cache
     * implementation both return). Each chunk fetches IDs only; the object and
     * meta caches primed for the PHP predicate are evicted again per chunk so
     * a large store never accumulates every post in memory.
     *
     * @param array  $args   Query population.
     * @param string $filter Missing SEO filter.
     * @return int[] Matching post IDs, post_date DESC.
     */
    private function scan_matching_ids($args, $filter) {
        $chunk_size = 500;
        $scan_args = $args;
        unset($scan_args['paged'], $scan_args['meta_query']);
        $scan_args['posts_per_page'] = $chunk_size;
        $scan_args['fields'] = 'ids';
        $scan_args['orderby'] = 'ID';
        $scan_args['order'] = 'ASC';
        $scan_args['no_found_rows'] = true;
        $scan_args['update_post_meta_cache'] = false;
        $scan_args['update_post_term_cache'] = false;
        $scan_args['metasync_exclude_custom_pages'] = true;
        $matching_ids = [];
        $matching_dates = [];
        $last_id = 0;
        add_filter('posts_where', 'metasync_filter_exclude_custom_pages_where', 10, 2);
        add_filter('posts_where', ['Metasync_SEO_Inventory_Builder', 'filter_where_id_gt'], 10, 2);
        try {
            while (true) {
                if ($last_id > 0) {
                    $scan_args['where_id_gt'] = $last_id;
                }
                $query = new WP_Query($scan_args);
                $ids = $query->posts;
                if (!$ids) {
                    break;
                }
                update_meta_cache('post', $ids);
                _prime_post_caches($ids, false, false);
                $objects = [];
                foreach ($ids as $id) {
                    $post = get_post($id);
                    if ($post) {
                        $objects[] = $post;
                    }
                }
                foreach ($this->apply_missing_filter($objects, $filter) as $post) {
                    $matching_ids[] = (int) $post->ID;
                    $matching_dates[] = (string) $post->post_date;
                }
                // Advance the cursor on the chunk itself, not on get_post()
                // success: a post deleted mid-scan must not stall the cursor
                // (re-reading a chunk would duplicate its matches).
                $last_id = max($last_id, (int) max(array_map('intval', $ids)));
                wp_cache_delete_multiple($ids, 'posts');
                wp_cache_delete_multiple($ids, 'post_meta');
                if (count($ids) < $chunk_size) {
                    break;
                }
            }
        } finally {
            remove_filter('posts_where', 'metasync_filter_exclude_custom_pages_where', 10);
            remove_filter('posts_where', ['Metasync_SEO_Inventory_Builder', 'filter_where_id_gt'], 10);
        }
        return self::sort_ids_by_date_desc($matching_ids, $matching_dates);
    }

    /**
     * Order matching IDs newest-first, restoring the date-DESC ordering the
     * unfiltered branch has always used. Dates are 'Y-m-d H:i:s' strings,
     * which sort lexically the same as chronologically.
     *
     * @param int[]    $ids   Post IDs.
     * @param string[] $dates Matching post_date values, parallel to $ids.
     * @return int[]
     */
    public static function sort_ids_by_date_desc(array $ids, array $dates) {
        if (count($ids) < 2) {
            return array_map('intval', $ids);
        }
        array_multisort($dates, SORT_DESC, SORT_STRING, $ids);

        return array_map('intval', $ids);
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

<?php
/**
 * SEO Inventory Builder
 *
 * Shared builder that fetches all published posts/pages with their SEO metadata
 * in bulk using cursor-based pagination. Used by both the REST endpoint and
 * the MCP tool so the logic lives in one place.
 *
 * @package    MetaSync
 * @subpackage Includes
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_SEO_Inventory_Builder {

    /**
     * Meta keys we read for every post (MCP field set — matches WP-134).
     */
    const SEO_META_KEYS = [
        'meta_title'          => '_metasync_metatitle',
        'meta_description'    => '_metasync_metadesc',
        'focus_keyword'       => '_metasync_focus_keyword',
        'robots'              => '_metasync_robots_index',
        'canonical_url'       => '_metasync_canonical_url',
        'og_title'            => '_metasync_og_title',
        'og_description'      => '_metasync_og_description',
        'og_image'            => '_metasync_og_image',
        'og_type'             => '_metasync_og_type',
        'twitter_title'       => '_metasync_twitter_title',
        'twitter_description' => '_metasync_twitter_description',
    ];

    const META_TITLE_MAX_LENGTH       = 60;
    const META_DESCRIPTION_MAX_LENGTH = 160;
    const DEFAULT_LIMIT               = 200;
    const MAX_LIMIT                   = 500;

    /**
     * Build the inventory response.
     *
     * @param array $args {
     *     @type string $post_type      'post', 'page', or 'any'. Default 'any'.
     *     @type string $post_status    'publish', 'draft', etc. Default 'publish'.
     *     @type int    $limit          Items per page (1–500). Default 200.
     *     @type int    $cursor         Post ID to start after (keyset pagination). Default 0.
     *     @type bool   $include_issues Whether to add pre-computed issue flags. Default true.
     *     @type string $modified_after ISO 8601 date — only return posts modified after this.
     * }
     * @return array Inventory payload with items, total, next_cursor, has_more.
     */
    public static function build(array $args = []): array {
        $post_type      = isset($args['post_type']) ? sanitize_text_field($args['post_type']) : 'any';
        $post_status    = isset($args['post_status']) ? sanitize_text_field($args['post_status']) : 'publish';
        $limit          = isset($args['limit']) ? absint($args['limit']) : self::DEFAULT_LIMIT;
        $cursor         = isset($args['cursor']) ? absint($args['cursor']) : 0;
        $include_issues = isset($args['include_issues']) ? (bool) $args['include_issues'] : true;
        $modified_after = isset($args['modified_after']) ? sanitize_text_field($args['modified_after']) : '';

        $limit = max(1, min($limit, self::MAX_LIMIT));

        $query_args = [
            'post_type'              => $post_type === 'any' ? ['post', 'page'] : $post_type,
            'post_status'            => $post_status,
            'posts_per_page'         => $limit + 1, // fetch one extra to detect has_more
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true, // skip SQL_CALC_FOUND_ROWS for performance
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ];

        // Keyset pagination: only posts with ID > cursor
        if ($cursor > 0) {
            $query_args['where_id_gt'] = $cursor; // handled by filter below
            add_filter('posts_where', [__CLASS__, 'filter_where_id_gt'], 10, 2);
        }

        // Modified-after filter
        if (!empty($modified_after)) {
            $query_args['date_query'] = [
                [
                    'after'    => $modified_after,
                    'column'   => 'post_modified_gmt',
                    'inclusive' => false,
                ],
            ];
        }

        $query = new WP_Query($query_args);

        // Remove the filter immediately so it doesn't affect other queries
        remove_filter('posts_where', [__CLASS__, 'filter_where_id_gt'], 10);

        $posts    = $query->posts;
        $has_more = count($posts) > $limit;

        if ($has_more) {
            array_pop($posts); // remove the extra sentinel post
        }

        // Meta cache is already primed by WP_Query (update_post_meta_cache => true)

        $items       = [];
        $next_cursor = 0;

        foreach ($posts as $post) {
            $item = self::build_item($post, $include_issues);
            $items[]     = $item;
            $next_cursor = $post->ID;
        }

        // Total count (separate lightweight query)
        $total = self::get_total_count($post_type, $post_status, $modified_after);

        return [
            'items'       => $items,
            'total'       => $total,
            'next_cursor' => $has_more ? $next_cursor : null,
            'has_more'    => $has_more,
        ];
    }

    /**
     * Build a single post's inventory item.
     *
     * @param WP_Post $post
     * @param bool    $include_issues
     * @return array
     */
    private static function build_item(WP_Post $post, bool $include_issues): array {
        $post_id = $post->ID;

        // Basic info
        $content_text = wp_strip_all_tags($post->post_content);
        $word_count   = str_word_count($content_text);

        $item = [
            'post_id'       => $post_id,
            'post_type'     => $post->post_type,
            'post_status'   => $post->post_status,
            'url'           => get_permalink($post_id),
            'slug'          => $post->post_name,
            'title'         => $post->post_title,
            'last_modified' => get_post_modified_time('c', true, $post_id),
            'word_count'    => $word_count,
        ];

        // SEO sub-object — cast to string to guard against array meta
        // (another plugin or manual DB edit could store non-string values,
        //  which would crash mb_strlen in compute_issues on PHP 8+)
        $seo = [];
        foreach (self::SEO_META_KEYS as $field => $meta_key) {
            $value = get_post_meta($post_id, $meta_key, true);
            $seo[$field] = is_string($value) ? $value : '';
        }

        // Schema types — handle both storage formats:
        // 1. User/MCP format: ['types' => [['type' => 'article', ...], ...]]
        // 2. OTTO format: ['otto_jsonld' => [...]]
        $schema_data = get_post_meta($post_id, 'metasync_schema_markup', true);
        $schema_types = [];
        if (!empty($schema_data) && is_array($schema_data)) {
            // Extract user-configured type names from 'types' array
            if (isset($schema_data['types']) && is_array($schema_data['types'])) {
                foreach ($schema_data['types'] as $type_entry) {
                    if (is_array($type_entry) && isset($type_entry['type'])) {
                        $schema_types[] = $type_entry['type'];
                    }
                }
            }
            // Include OTTO/other top-level schema keys (e.g. 'otto_jsonld')
            foreach (array_keys($schema_data) as $key) {
                if (!in_array($key, ['enabled', 'types'], true)) {
                    $schema_types[] = $key;
                }
            }
        }
        $seo['schema_types'] = $schema_types;

        $item['seo'] = $seo;

        // Pre-computed issue flags
        if ($include_issues) {
            $item['issues'] = self::compute_issues($seo);
        }

        return $item;
    }

    /**
     * Compute SEO issue flags for a post.
     *
     * @param array $seo The SEO sub-object.
     * @return array Boolean issue flags.
     */
    private static function compute_issues(array $seo): array {
        $meta_title = $seo['meta_title'] ?? '';
        $meta_desc  = $seo['meta_description'] ?? '';

        return [
            'missing_meta_title'       => empty($meta_title),
            'missing_meta_description' => empty($meta_desc),
            'meta_title_too_long'      => mb_strlen($meta_title) > self::META_TITLE_MAX_LENGTH,
            'meta_description_too_long' => mb_strlen($meta_desc) > self::META_DESCRIPTION_MAX_LENGTH,
            'is_noindex'               => ($seo['robots'] ?? '') === 'noindex',
            'missing_og_image'         => empty($seo['og_image'] ?? ''),
            'missing_schema'           => empty($seo['schema_types']),
            'missing_focus_keyword'    => empty($seo['focus_keyword'] ?? ''),
        ];
    }

    /**
     * Get total count of matching posts (lightweight).
     *
     * @param string $post_type
     * @param string $post_status
     * @param string $modified_after
     * @return int
     */
    private static function get_total_count(string $post_type, string $post_status, string $modified_after): int {
        $args = [
            'post_type'      => $post_type === 'any' ? ['post', 'page'] : $post_type,
            'post_status'    => $post_status,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false, // we need found_rows for total
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        if (!empty($modified_after)) {
            $args['date_query'] = [
                [
                    'after'     => $modified_after,
                    'column'    => 'post_modified_gmt',
                    'inclusive' => false,
                ],
            ];
        }

        $query = new WP_Query($args);
        return (int) $query->found_posts;
    }

    /**
     * WP_Query filter: WHERE ID > cursor for keyset pagination.
     *
     * @param string   $where
     * @param WP_Query $query
     * @return string
     */
    public static function filter_where_id_gt(string $where, WP_Query $query): string {
        $cursor = isset($query->query_vars['where_id_gt']) ? absint($query->query_vars['where_id_gt']) : 0;
        if ($cursor > 0) {
            global $wpdb;
            $where .= $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $cursor);
        }
        return $where;
    }
}

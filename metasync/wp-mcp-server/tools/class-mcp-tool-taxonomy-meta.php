<?php
/**
 * MCP Tools for Taxonomy Meta Operations
 *
 * Provides MCP tools for managing SEO metadata on categories, tags, and custom taxonomies.
 * Enables category/tag page SEO optimization with meta titles, descriptions, and social meta.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 * @since      2.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(dirname(__FILE__)) . 'class-mcp-tool-base.php';

/**
 * Get Taxonomy Term Meta Tool
 *
 * Retrieves SEO metadata for a category, tag, or custom taxonomy term
 */
class MCP_Tool_Get_Term_Meta extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_term_meta';
    }

    public function get_description() {
        return 'Get SEO metadata for a taxonomy term (category, tag, or custom taxonomy). Returns meta title, description, Open Graph, and Twitter Card data.';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'term_id' => [
                    'type' => 'integer',
                    'description' => 'Term ID',
                    'minimum' => 1,
                ],
                'taxonomy' => [
                    'type' => 'string',
                    'description' => 'Taxonomy name (optional, for validation)',
                ],
            ],
            'required' => ['term_id'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_categories');

        $term_id = intval($params['term_id']);
        $taxonomy = isset($params['taxonomy']) ? sanitize_text_field($params['taxonomy']) : '';

        // Get term
        $term = get_term($term_id, $taxonomy);
        if (is_wp_error($term) || !$term) {
            throw new Exception(sprintf("Term not found: %d", $term_id));
        }

        // Get all meta fields
        $seo_meta = [
            'meta_title' => get_term_meta($term_id, '_metasync_metatitle', true),
            'meta_description' => get_term_meta($term_id, '_metasync_metadesc', true),
            'focus_keyword' => get_term_meta($term_id, '_metasync_focus_keyword', true),
            'robots_index' => get_term_meta($term_id, '_metasync_robots_index', true),
            'canonical_url' => get_term_meta($term_id, '_metasync_canonical_url', true),
        ];

        $opengraph_meta = [
            'og_enabled' => get_term_meta($term_id, '_metasync_og_enabled', true),
            'og_title' => get_term_meta($term_id, '_metasync_og_title', true),
            'og_description' => get_term_meta($term_id, '_metasync_og_description', true),
            'og_image' => get_term_meta($term_id, '_metasync_og_image', true),
            'og_type' => get_term_meta($term_id, '_metasync_og_type', true),
        ];

        $twitter_meta = [
            'twitter_card' => get_term_meta($term_id, '_metasync_twitter_card', true),
            'twitter_title' => get_term_meta($term_id, '_metasync_twitter_title', true),
            'twitter_description' => get_term_meta($term_id, '_metasync_twitter_description', true),
            'twitter_image' => get_term_meta($term_id, '_metasync_twitter_image', true),
        ];

        // OTTO meta (if available)
        $otto_meta = [
            'keywords' => get_term_meta($term_id, '_metasync_otto_keywords', true),
            'og_title' => get_term_meta($term_id, '_metasync_otto_og_title', true),
            'og_description' => get_term_meta($term_id, '_metasync_otto_og_description', true),
            'twitter_title' => get_term_meta($term_id, '_metasync_otto_twitter_title', true),
            'twitter_description' => get_term_meta($term_id, '_metasync_otto_twitter_description', true),
            'last_update' => get_term_meta($term_id, '_metasync_otto_last_update', true),
        ];

        return $this->success([
            'term_id' => $term_id,
            'term_name' => $term->name,
            'term_slug' => $term->slug,
            'taxonomy' => $term->taxonomy,
            'description' => $term->description,
            'count' => $term->count,
            'parent' => $term->parent,
            'url' => get_term_link($term),
            'seo_meta' => $seo_meta,
            'opengraph_meta' => $opengraph_meta,
            'twitter_meta' => $twitter_meta,
            'otto_meta' => $otto_meta,
        ]);
    }
}

/**
 * Update Taxonomy Term Meta Tool
 *
 * Updates SEO metadata for a category, tag, or custom taxonomy term
 */
class MCP_Tool_Update_Term_Meta extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_term_meta';
    }

    public function get_description() {
        return 'Update SEO metadata for a taxonomy term (category, tag, etc.). Set meta title, description, Open Graph, and Twitter Card data for archive pages.';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'term_id' => [
                    'type' => 'integer',
                    'description' => 'Term ID',
                    'minimum' => 1,
                ],
                'taxonomy' => [
                    'type' => 'string',
                    'description' => 'Taxonomy name (optional, for validation)',
                ],
                'meta_title' => [
                    'type' => 'string',
                    'description' => 'SEO meta title for the term archive page',
                ],
                'meta_description' => [
                    'type' => 'string',
                    'description' => 'SEO meta description for the term archive page',
                ],
                'focus_keyword' => [
                    'type' => 'string',
                    'description' => 'Primary keyword for SEO',
                ],
                'robots_index' => [
                    'type' => 'string',
                    'enum' => ['index', 'noindex'],
                    'description' => 'Indexing directive',
                ],
                'canonical_url' => [
                    'type' => 'string',
                    'description' => 'Canonical URL',
                ],
                'og_enabled' => [
                    'type' => 'boolean',
                    'description' => 'Enable Open Graph tags',
                ],
                'og_title' => [
                    'type' => 'string',
                    'description' => 'Open Graph title',
                ],
                'og_description' => [
                    'type' => 'string',
                    'description' => 'Open Graph description',
                ],
                'og_image' => [
                    'type' => 'string',
                    'description' => 'Open Graph image URL',
                ],
                'twitter_card' => [
                    'type' => 'string',
                    'enum' => ['summary', 'summary_large_image'],
                    'description' => 'Twitter Card type',
                ],
                'twitter_title' => [
                    'type' => 'string',
                    'description' => 'Twitter Card title',
                ],
                'twitter_description' => [
                    'type' => 'string',
                    'description' => 'Twitter Card description',
                ],
                'twitter_image' => [
                    'type' => 'string',
                    'description' => 'Twitter Card image URL',
                ],
            ],
            'required' => ['term_id'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_categories');

        $term_id = intval($params['term_id']);
        $taxonomy = isset($params['taxonomy']) ? sanitize_text_field($params['taxonomy']) : '';

        // Verify term exists
        $term = get_term($term_id, $taxonomy);
        if (is_wp_error($term) || !$term) {
            throw new Exception(sprintf("Term not found: %d", $term_id));
        }

        $updated_fields = [];

        // Update SEO meta
        if (isset($params['meta_title'])) {
            update_term_meta($term_id, '_metasync_metatitle', sanitize_text_field($params['meta_title']));
            $updated_fields[] = 'meta_title';
        }

        if (isset($params['meta_description'])) {
            update_term_meta($term_id, '_metasync_metadesc', sanitize_textarea_field($params['meta_description']));
            $updated_fields[] = 'meta_description';
        }

        if (isset($params['focus_keyword'])) {
            update_term_meta($term_id, '_metasync_focus_keyword', sanitize_text_field($params['focus_keyword']));
            $updated_fields[] = 'focus_keyword';
        }

        if (isset($params['robots_index'])) {
            update_term_meta($term_id, '_metasync_robots_index', sanitize_text_field($params['robots_index']));
            $updated_fields[] = 'robots_index';
        }

        if (isset($params['canonical_url'])) {
            update_term_meta($term_id, '_metasync_canonical_url', esc_url_raw($params['canonical_url']));
            $updated_fields[] = 'canonical_url';
        }

        // Update Open Graph meta
        if (isset($params['og_enabled'])) {
            update_term_meta($term_id, '_metasync_og_enabled', $params['og_enabled'] ? '1' : '0');
            $updated_fields[] = 'og_enabled';
        }

        if (isset($params['og_title'])) {
            update_term_meta($term_id, '_metasync_og_title', sanitize_text_field($params['og_title']));
            $updated_fields[] = 'og_title';
        }

        if (isset($params['og_description'])) {
            update_term_meta($term_id, '_metasync_og_description', sanitize_textarea_field($params['og_description']));
            $updated_fields[] = 'og_description';
        }

        if (isset($params['og_image'])) {
            update_term_meta($term_id, '_metasync_og_image', esc_url_raw($params['og_image']));
            $updated_fields[] = 'og_image';
        }

        // Update Twitter Card meta
        if (isset($params['twitter_card'])) {
            update_term_meta($term_id, '_metasync_twitter_card', sanitize_text_field($params['twitter_card']));
            $updated_fields[] = 'twitter_card';
        }

        if (isset($params['twitter_title'])) {
            update_term_meta($term_id, '_metasync_twitter_title', sanitize_text_field($params['twitter_title']));
            $updated_fields[] = 'twitter_title';
        }

        if (isset($params['twitter_description'])) {
            update_term_meta($term_id, '_metasync_twitter_description', sanitize_textarea_field($params['twitter_description']));
            $updated_fields[] = 'twitter_description';
        }

        if (isset($params['twitter_image'])) {
            update_term_meta($term_id, '_metasync_twitter_image', esc_url_raw($params['twitter_image']));
            $updated_fields[] = 'twitter_image';
        }

        if (empty($updated_fields)) {
            throw new Exception('No meta fields provided to update');
        }

        return $this->success([
            'term_id' => $term_id,
            'term_name' => $term->name,
            'taxonomy' => $term->taxonomy,
            'updated_fields' => $updated_fields,
            'fields_count' => count($updated_fields),
            'message' => count($updated_fields) . ' meta field(s) updated successfully',
        ]);
    }
}

/**
 * Bulk Update Taxonomy Term Meta Tool
 *
 * Updates SEO metadata for multiple terms at once
 */
class MCP_Tool_Bulk_Update_Term_Meta extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_bulk_update_term_meta';
    }

    public function get_description() {
        return 'Update SEO metadata for multiple taxonomy terms at once (max 50 terms per request)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'updates' => [
                    'type' => 'array',
                    'description' => 'Array of term meta updates',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'term_id' => [
                                'type' => 'integer',
                                'description' => 'Term ID',
                            ],
                            'meta_title' => [
                                'type' => 'string',
                                'description' => 'SEO meta title',
                            ],
                            'meta_description' => [
                                'type' => 'string',
                                'description' => 'SEO meta description',
                            ],
                            'focus_keyword' => [
                                'type' => 'string',
                                'description' => 'Focus keyword',
                            ],
                        ],
                        'required' => ['term_id'],
                    ],
                    'maxItems' => 50,
                ],
            ],
            'required' => ['updates'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_categories');

        if (!is_array($params['updates'])) {
            throw new Exception('updates must be an array');
        }

        $updates = $params['updates'];

        if (count($updates) > 50) {
            throw new Exception('Maximum 50 terms can be updated at once');
        }

        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($updates as $update) {
            try {
                if (!isset($update['term_id'])) {
                    throw new Exception('Missing required field: term_id');
                }

                $term_id = intval($update['term_id']);

                // Verify term exists
                $term = get_term($term_id);
                if (is_wp_error($term) || !$term) {
                    $results['failed'][] = [
                        'term_id' => $term_id,
                        'error' => 'Term not found',
                    ];
                    continue;
                }

                $updated_fields = [];

                // Update meta fields
                if (isset($update['meta_title'])) {
                    update_term_meta($term_id, '_metasync_metatitle', sanitize_text_field($update['meta_title']));
                    $updated_fields[] = 'meta_title';
                }

                if (isset($update['meta_description'])) {
                    update_term_meta($term_id, '_metasync_metadesc', sanitize_textarea_field($update['meta_description']));
                    $updated_fields[] = 'meta_description';
                }

                if (isset($update['focus_keyword'])) {
                    update_term_meta($term_id, '_metasync_focus_keyword', sanitize_text_field($update['focus_keyword']));
                    $updated_fields[] = 'focus_keyword';
                }

                $results['success'][] = [
                    'term_id' => $term_id,
                    'term_name' => $term->name,
                    'taxonomy' => $term->taxonomy,
                    'updated_fields' => $updated_fields,
                ];

            } catch (Exception $e) {
                $results['failed'][] = [
                    'term_id' => isset($update['term_id']) ? $update['term_id'] : null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $this->success([
            'total_requested' => count($updates),
            'success_count' => count($results['success']),
            'failed_count' => count($results['failed']),
            'results' => $results,
            'message' => count($results['success']) . ' term(s) updated successfully',
        ]);
    }
}

/**
 * List Terms with SEO Meta Tool
 *
 * Lists all terms with their SEO metadata for a taxonomy
 */
class MCP_Tool_List_Terms_With_Meta extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_list_terms_with_meta';
    }

    public function get_description() {
        return 'List all terms for a taxonomy with their SEO metadata. Useful for auditing category/tag SEO.';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'taxonomy' => [
                    'type' => 'string',
                    'description' => 'Taxonomy name (category, post_tag, or custom taxonomy)',
                ],
                'hide_empty' => [
                    'type' => 'boolean',
                    'description' => 'Hide terms with no posts (default: false)',
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
            'required' => ['taxonomy'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_categories');

        $taxonomy = sanitize_text_field($params['taxonomy']);
        $hide_empty = isset($params['hide_empty']) ? (bool)$params['hide_empty'] : false;
        $orderby = isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'name';
        $order = isset($params['order']) ? sanitize_text_field($params['order']) : 'ASC';

        // Verify taxonomy exists
        if (!taxonomy_exists($taxonomy)) {
            throw new Exception(sprintf("Taxonomy not found: %s", $taxonomy));
        }

        // Get terms
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => $hide_empty,
            'orderby' => $orderby,
            'order' => $order,
        ]);

        if (is_wp_error($terms)) {
            throw new Exception('Failed to retrieve terms: ' . $terms->get_error_message());
        }

        $terms_data = [];
        $stats = [
            'with_meta_title' => 0,
            'with_meta_description' => 0,
            'with_og_data' => 0,
            'missing_seo' => 0,
        ];

        foreach ($terms as $term) {
            $meta_title = get_term_meta($term->term_id, '_metasync_metatitle', true);
            $meta_desc = get_term_meta($term->term_id, '_metasync_metadesc', true);
            $og_enabled = get_term_meta($term->term_id, '_metasync_og_enabled', true);
            $og_title = get_term_meta($term->term_id, '_metasync_og_title', true);

            // Update stats
            if (!empty($meta_title)) $stats['with_meta_title']++;
            if (!empty($meta_desc)) $stats['with_meta_description']++;
            if ($og_enabled === '1' && !empty($og_title)) $stats['with_og_data']++;
            if (empty($meta_title) && empty($meta_desc)) $stats['missing_seo']++;

            $terms_data[] = [
                'term_id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'count' => $term->count,
                'parent' => $term->parent,
                'url' => get_term_link($term),
                'seo_status' => [
                    'has_meta_title' => !empty($meta_title),
                    'has_meta_description' => !empty($meta_desc),
                    'has_og_data' => $og_enabled === '1' && !empty($og_title),
                    'needs_attention' => empty($meta_title) && empty($meta_desc),
                ],
                'meta_preview' => [
                    'meta_title' => !empty($meta_title) ? mb_substr($meta_title, 0, 60) . '...' : null,
                    'meta_description' => !empty($meta_desc) ? mb_substr($meta_desc, 0, 100) . '...' : null,
                ],
            ];
        }

        return $this->success([
            'taxonomy' => $taxonomy,
            'total_terms' => count($terms_data),
            'stats' => $stats,
            'terms' => $terms_data,
        ]);
    }
}

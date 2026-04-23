<?php
/**
 * MCP Tool: Post Meta Operations
 *
 * Provides tools for managing WordPress post meta fields,
 * specifically SEO-related metadata.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Update Post Meta Tool
 */
class MCP_Tool_Update_Post_Meta extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_update_post_meta';
    }

    public function get_description() {
        return 'Update a WordPress post meta field (SEO data like title, description, keywords, robots settings, Open Graph/social meta, hreflang/language alternates)';
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
                'meta_key' => [
                    'type' => 'string',
                    'description' => 'Meta field to update',
                    'enum' => [
                        '_metasync_metatitle',
                        '_metasync_metadesc',
                        '_metasync_focus_keyword',
                        '_metasync_robots_index',
                        '_metasync_canonical_url',
                        '_metasync_og_enabled',
                        '_metasync_og_title',
                        '_metasync_og_description',
                        '_metasync_og_image',
                        '_metasync_og_url',
                        '_metasync_og_type',
                        '_metasync_twitter_title',
                        '_metasync_twitter_description',
                        '_metasync_twitter_card',
                        '_metasync_primary_category',
                        '_metasync_otto_keywords',
                        '_metasync_og_article_author',
                        '_metasync_hreflang',
                        '_metasync_breadcrumb_title'
                    ]
                ],
                'meta_value' => [
                    'type' => 'string',
                    'description' => 'Value to set for the meta field'
                ]
            ],
            'required' => ['post_id', 'meta_key', 'meta_value']
        ];
    }

    public function execute($params) {
        // Validate and sanitize
        $this->validate_params($params);
        $this->require_capability('edit_posts');

        $post_id = $this->sanitize_integer($params['post_id']);
        $meta_key = $this->sanitize_string($params['meta_key']);
        $meta_value = $this->sanitize_textarea($params['meta_value']);

        // hreflang / language alternates: value must be a JSON array of
        // {lang, url} objects (with optional region). Parse from the raw
        // param to avoid textarea sanitization mangling the JSON, validate
        // shape, and re-encode to canonical form before storing.
        if ($meta_key === '_metasync_hreflang') {
            $raw_value = isset($params['meta_value']) ? (string) $params['meta_value'] : '';
            $decoded = json_decode($raw_value, true);
            if (!is_array($decoded)) {
                throw new Exception("_metasync_hreflang must be a JSON array");
            }
            foreach ($decoded as $entry) {
                if (!is_array($entry) || !isset($entry['lang']) || !isset($entry['url'])) {
                    throw new Exception("Each hreflang entry must have 'lang' and 'url' keys");
                }
            }
            // Sanitize each URL to strip javascript: and other unsafe protocols.
            foreach ($decoded as &$entry) {
                $entry['url'] = esc_url_raw($entry['url']);
            }
            unset($entry);
            $meta_value = wp_json_encode($decoded);
        }

        // SECURITY: Apply esc_url_raw() to URL-typed meta keys (prevent javascript: protocol).
        if (in_array($meta_key, ['_metasync_og_image', '_metasync_og_url', '_metasync_canonical_url', '_metasync_og_article_author'])) {
            $meta_value = esc_url_raw($meta_value);
        }

        // Sanitize integer meta fields
        if ($meta_key === '_metasync_primary_category') {
            $meta_value = absint($meta_value);
        }

        // Verify post exists
        $post = $this->verify_post_exists($post_id);

        // SECURITY: Check user has permission to edit this specific post
        $this->check_post_permission($post_id);

        // Update meta
        $current_value = get_post_meta($post_id, $meta_key, true);
        // Normalize numeric meta keys: WordPress returns stored values as strings,
        // so cast for type-safe comparison against the integer $meta_value above.
        if ($meta_key === '_metasync_primary_category') {
            $current_value = (int) $current_value;
        }
        if ($current_value === $meta_value) {
            // Value already matches — return success without update
            return $this->success([
                'post_id'    => $post_id,
                'meta_key'   => $meta_key,
                'meta_value' => $current_value,
                'updated'    => false,
                'post_title' => $post->post_title,
                'post_type'  => $post->post_type
            ], 'Meta value already matches, no update needed');
        }
        $updated = update_post_meta($post_id, $meta_key, $meta_value);

        if ($updated === false) {
            throw new Exception("Failed to update meta key '{$meta_key}'");
        }

        $stored_value = get_post_meta($post_id, $meta_key, true);
        return $this->success([
            'post_id'    => $post_id,
            'meta_key'   => $meta_key,
            'meta_value' => $stored_value,
            'updated'    => true,
            'post_title' => $post->post_title,
            'post_type'  => $post->post_type
        ], "Meta field '{$meta_key}' updated successfully");
    }
}

/**
 * Get Post Meta Tool
 */
class MCP_Tool_Get_Post_Meta extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_post_meta';
    }

    public function get_description() {
        return 'Get WordPress post meta field value(s)';
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
                'meta_key' => [
                    'type' => 'string',
                    'description' => 'Specific meta key to retrieve (optional - omit to get all SEO meta)',
                ]
            ],
            'required' => ['post_id']
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('read');

        $post_id = $this->sanitize_integer($params['post_id']);

        // Verify post exists
        $post = $this->verify_post_exists($post_id);

        // SECURITY: Check user has permission to read this specific post
        $this->check_post_permission($post_id);

        // Get meta
        if (isset($params['meta_key'])) {
            $meta_key = $this->sanitize_string($params['meta_key']);
            $meta_value = get_post_meta($post_id, $meta_key, true);

            return $this->success([
                'post_id' => $post_id,
                'post_title' => $post->post_title,
                'meta_key' => $meta_key,
                'meta_value' => $meta_value
            ]);
        } else {
            // Get all SEO meta
            $seo_meta = [
                'metatitle' => get_post_meta($post_id, '_metasync_metatitle', true),
                'metadesc' => get_post_meta($post_id, '_metasync_metadesc', true),
                'focus_keyword' => get_post_meta($post_id, '_metasync_focus_keyword', true),
                'robots_index' => get_post_meta($post_id, '_metasync_robots_index', true),
                'canonical_url' => get_post_meta($post_id, '_metasync_canonical_url', true)
            ];

            // Get Open Graph meta
            $opengraph_meta = [
                'og_enabled' => get_post_meta($post_id, '_metasync_og_enabled', true),
                'og_title' => get_post_meta($post_id, '_metasync_og_title', true),
                'og_description' => get_post_meta($post_id, '_metasync_og_description', true),
                'og_image' => get_post_meta($post_id, '_metasync_og_image', true),
                'og_url' => get_post_meta($post_id, '_metasync_og_url', true),
                'og_type' => get_post_meta($post_id, '_metasync_og_type', true)
            ];

            // Get Twitter Card meta
            $twitter_meta = [
                'twitter_card' => get_post_meta($post_id, '_metasync_twitter_card', true),
                'twitter_title' => get_post_meta($post_id, '_metasync_twitter_title', true),
                'twitter_description' => get_post_meta($post_id, '_metasync_twitter_description', true),
            ];

            return $this->success([
                'post_id' => $post_id,
                'post_title' => $post->post_title,
                'post_type' => $post->post_type,
                'post_status' => $post->post_status,
                'seo_meta' => $seo_meta,
                'opengraph_meta' => $opengraph_meta,
                'twitter_meta' => $twitter_meta
            ]);
        }
    }
}

/**
 * Get SEO Meta Tool
 */
class MCP_Tool_Get_SEO_Meta extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_seo_meta';
    }

    public function get_description() {
        return 'Get all SEO-related metadata for a post including title, description, keywords, indexing settings, and Open Graph/social meta';
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

        // Verify post exists
        $post = $this->verify_post_exists($post_id);

        // Get all SEO meta
        $seo_data = [
            'post_info' => [
                'id' => $post_id,
                'title' => $post->post_title,
                'type' => $post->post_type,
                'status' => $post->post_status,
                'url' => get_permalink($post_id)
            ],
            'seo_meta' => [
                'meta_title' => get_post_meta($post_id, '_metasync_metatitle', true),
                'meta_description' => get_post_meta($post_id, '_metasync_metadesc', true),
                'focus_keyword' => get_post_meta($post_id, '_metasync_focus_keyword', true),
                'robots_index' => get_post_meta($post_id, '_metasync_robots_index', true),
                'canonical_url' => get_post_meta($post_id, '_metasync_canonical_url', true),
                'primary_category' => $this->get_primary_category_data($post_id),
            ],
            'opengraph_meta' => [
                'enabled' => get_post_meta($post_id, '_metasync_og_enabled', true),
                'title' => get_post_meta($post_id, '_metasync_og_title', true),
                'description' => get_post_meta($post_id, '_metasync_og_description', true),
                'image' => get_post_meta($post_id, '_metasync_og_image', true),
                'url' => get_post_meta($post_id, '_metasync_og_url', true),
                'type' => get_post_meta($post_id, '_metasync_og_type', true)
            ],
            'twitter_meta' => [
                'twitter_card' => get_post_meta($post_id, '_metasync_twitter_card', true),
                'twitter_title' => get_post_meta($post_id, '_metasync_twitter_title', true),
                'twitter_description' => get_post_meta($post_id, '_metasync_twitter_description', true),
            ],
            'analysis' => [
                'meta_title_length' => mb_strlen(get_post_meta($post_id, '_metasync_metatitle', true)),
                'meta_desc_length' => mb_strlen(get_post_meta($post_id, '_metasync_metadesc', true)),
                'has_focus_keyword' => !empty(get_post_meta($post_id, '_metasync_focus_keyword', true)),
                'is_indexable' => get_post_meta($post_id, '_metasync_robots_index', true) !== 'noindex',
                'og_enabled' => get_post_meta($post_id, '_metasync_og_enabled', true) === '1',
                'has_og_image' => !empty(get_post_meta($post_id, '_metasync_og_image', true))
            ]
        ];

        return $this->success($seo_data);
    }

    /**
     * Get primary category data for a post
     *
     * @param int $post_id The post ID
     * @return array|null Primary category data or null if not set
     */
    private function get_primary_category_data($post_id) {
        $primary_cat_id = (int) get_post_meta($post_id, '_metasync_primary_category', true);

        if ($primary_cat_id === 0) {
            return null;
        }

        $term = get_term($primary_cat_id, 'category');
        if (!$term || is_wp_error($term)) {
            return null;
        }

        return [
            'term_id' => $primary_cat_id,
            'name' => $term->name,
            'slug' => $term->slug,
        ];
    }
}

/**
 * Get Hreflang Links Tool
 *
 * Returns all hreflang entries for a post (manual + WPML auto-detected),
 * validates that each referenced URL returns HTTP 200, and flags a missing
 * x-default entry.
 */
class MCP_Tool_Get_Hreflang_Links extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_hreflang_links';
    }

    public function get_description() {
        return 'Get all hreflang entries for a post (manual + WPML auto-detected), validate each URL returns HTTP 200, and flag a missing x-default entry';
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
        $post = $this->verify_post_exists($post_id);
        $this->check_post_permission($post_id);

        $manual_entries = $this->get_manual_entries($post_id);
        $wpml_entries   = $this->get_wpml_entries($post_id, $post);

        // Merge: auto-detected first, then manual entries override by
        // lang+region collision key.
        $by_key = [];
        foreach ($wpml_entries as $entry) {
            $by_key[$this->collision_key($entry)] = $entry;
        }
        foreach ($manual_entries as $entry) {
            $by_key[$this->collision_key($entry)] = $entry;
        }
        $entries = array_values($by_key);

        // Validate each URL returns HTTP 200 (try HEAD first, fall back to
        // GET on 405 Method Not Allowed).
        $has_x_default = false;
        foreach ($entries as &$entry) {
            $url = isset($entry['url']) ? $entry['url'] : '';
            $status = null;
            $error = null;

            if (!empty($url)) {
                $response = wp_remote_head($url, ['timeout' => 5, 'sslverify' => false]);
                if (is_wp_error($response)) {
                    $error = $response->get_error_message();
                } else {
                    $status = (int) wp_remote_retrieve_response_code($response);
                    if ($status === 405) {
                        $response = wp_remote_get($url, ['timeout' => 5, 'sslverify' => false]);
                        if (is_wp_error($response)) {
                            $error = $response->get_error_message();
                            $status = null;
                        } else {
                            $status = (int) wp_remote_retrieve_response_code($response);
                        }
                    }
                }
            }

            $entry['http_status'] = $status;
            $entry['http_ok'] = ($status === 200);
            if ($error !== null) {
                $entry['http_error'] = $error;
            }

            if (isset($entry['lang']) && $entry['lang'] === 'x-default') {
                $has_x_default = true;
            }
        }
        unset($entry);

        $missing_x_default = !$has_x_default;

        return $this->success([
            'post_id'           => $post_id,
            'post_title'        => $post->post_title,
            'entries'           => $entries,
            'missing_x_default' => $missing_x_default,
        ]);
    }

    /**
     * Read manual hreflang entries from `_metasync_hreflang` post meta.
     *
     * @param int $post_id Post ID.
     * @return array
     */
    private function get_manual_entries($post_id) {
        $raw = get_post_meta($post_id, '_metasync_hreflang', true);
        if (empty($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $entries = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entries[] = [
                'lang'   => isset($entry['lang']) ? (string) $entry['lang'] : '',
                'region' => isset($entry['region']) ? (string) $entry['region'] : '',
                'url'    => isset($entry['url']) ? (string) $entry['url'] : '',
                'source' => 'manual',
            ];
        }
        return $entries;
    }

    /**
     * Build WPML auto-detected entries by querying the icl_translations table.
     * Mirrors the logic in Metasync_Hreflang_Output::get_wpml_entries.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object (already verified).
     * @return array
     */
    private function get_wpml_entries($post_id, $post) {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return [];
        }
        global $wpdb;
        $table = $wpdb->prefix . 'icl_translations';
        $element_type = 'post_' . $post->post_type;

        $trid = $wpdb->get_var($wpdb->prepare(
            "SELECT trid FROM {$table} WHERE element_id = %d AND element_type = %s LIMIT 1",
            $post_id,
            $element_type
        ));
        if (empty($trid)) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT language_code, element_id FROM {$table} WHERE trid = %d",
            $trid
        ));
        if (empty($rows)) {
            return [];
        }

        $default_lang = apply_filters('wpml_default_language', null);
        if (empty($default_lang)) {
            $default_lang = get_option('wpml_default_language');
        }

        $entries = [];
        foreach ($rows as $row) {
            $permalink = get_permalink((int) $row->element_id);
            if (empty($permalink)) {
                continue;
            }
            $entries[] = [
                'lang'   => (string) $row->language_code,
                'region' => '',
                'url'    => $permalink,
                'source' => 'wpml',
            ];
            if (!empty($default_lang) && $row->language_code === $default_lang) {
                $entries[] = [
                    'lang'   => 'x-default',
                    'region' => '',
                    'url'    => $permalink,
                    'source' => 'wpml',
                ];
            }
        }
        return $entries;
    }

    /**
     * Collision key in the form `lang-region` (or just `lang` when region
     * is empty) for de-duplicating entries.
     */
    private function collision_key(array $entry) {
        $lang = isset($entry['lang']) ? (string) $entry['lang'] : '';
        $region = isset($entry['region']) ? (string) $entry['region'] : '';
        return $region !== '' ? $lang . '-' . $region : $lang;
    }
}

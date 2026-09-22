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
                        '_metasync_breadcrumb_title',
                        '_metasync_robots_advanced'
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
        // shape AND values (ISO language codes, absolute http(s) URLs),
        // normalise, and re-encode to canonical form before storing.
        if ($meta_key === '_metasync_hreflang') {
            $raw_value = isset($params['meta_value']) ? (string) $params['meta_value'] : '';
            $decoded = json_decode($raw_value, true);
            if (!is_array($decoded)) {
                throw new Exception("_metasync_hreflang must be a JSON array");
            }
            $clean = [];
            foreach ($decoded as $entry) {
                if (!is_array($entry) || !isset($entry['lang']) || !isset($entry['url'])) {
                    throw new Exception("Each hreflang entry must have 'lang' and 'url' keys");
                }
                $normalized = Metasync_Hreflang_Output::normalize_entry($entry);
                if (!$normalized['valid']) {
                    throw new Exception(sprintf(
                        "Invalid hreflang language code '%s' — use an ISO code like 'en', 'en-US' (hyphen, not underscore) or 'x-default'",
                        is_scalar($entry['lang']) ? (string) $entry['lang'] : gettype($entry['lang'])
                    ));
                }
                // Sanitize each URL to strip javascript: and other unsafe protocols.
                $url = esc_url_raw(trim((string) $entry['url']));
                if (!Metasync_Hreflang_Output::is_absolute_http_url($url)) {
                    throw new Exception(sprintf(
                        "hreflang URL '%s' must be an absolute http(s) URL (relative paths are not valid hreflang hrefs)",
                        (string) $entry['url']
                    ));
                }
                $clean[] = [
                    'lang'   => $normalized['lang'],
                    'region' => $normalized['region'],
                    'url'    => $url,
                ];
            }
            // update_metadata() runs wp_unslash() on the value; combined with
            // the default \uXXXX escaping this corrupted non-ASCII URLs
            // (…/über-uns/ stored as …/u00fcber-uns/). Encoding without the
            // escapes and passing slashed data — the same contract the REST
            // path uses — keeps the stored JSON byte-faithful.
            $meta_value = wp_slash(wp_json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        // Validate _metasync_robots_advanced JSON
        if ($meta_key === '_metasync_robots_advanced') {
            $raw_value = isset($params['meta_value']) ? (string) $params['meta_value'] : '';
            $decoded = json_decode($raw_value, true);
            if (!is_array($decoded)) {
                throw new Exception("_metasync_robots_advanced must be a JSON object");
            }
            $allowed_keys = ['nofollow', 'noarchive', 'nosnippet', 'noimageindex', 'max_snippet', 'max_image_preview', 'max_video_preview'];
            foreach (array_keys($decoded) as $k) {
                if (!in_array($k, $allowed_keys, true)) {
                    throw new Exception("Unknown key '{$k}' in _metasync_robots_advanced. Allowed: " . implode(', ', $allowed_keys));
                }
            }
            // Validate types
            foreach (['nofollow', 'noarchive', 'nosnippet', 'noimageindex'] as $bool_key) {
                if (isset($decoded[$bool_key]) && !is_bool($decoded[$bool_key])) {
                    $decoded[$bool_key] = (bool) $decoded[$bool_key];
                }
            }
            if (isset($decoded['max_snippet'])) {
                $decoded['max_snippet'] = (int) $decoded['max_snippet'];
            }
            if (isset($decoded['max_image_preview'])) {
                $valid = ['none', 'standard', 'large'];
                if (!in_array($decoded['max_image_preview'], $valid, true)) {
                    throw new Exception("max_image_preview must be one of: " . implode(', ', $valid));
                }
            }
            if (isset($decoded['max_video_preview'])) {
                $decoded['max_video_preview'] = (int) $decoded['max_video_preview'];
            }
            $meta_value = wp_json_encode($decoded);
        }

        // SECURITY: Apply esc_url_raw() to URL-typed meta keys (prevent javascript: protocol).
        if (in_array($meta_key, ['_metasync_og_image', '_metasync_og_url', '_metasync_canonical_url', '_metasync_og_article_author'])) {
            $meta_value = esc_url_raw($meta_value);
        }

        // Canonical must be a usable URL — reject corrupted values like
        // "Array"/"http://Array" so they can't re-enter storage.
        if ($meta_key === '_metasync_canonical_url') {
            $meta_value = Metasync_Canonical_Sanitizer::sanitize_for_save($meta_value);
            if ($meta_value === '') {
                return $this->error('canonical_url must be a valid URL');
            }
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
        // The hreflang payload is stored slashed (wp_slash) so it survives
        // update_metadata()'s wp_unslash(); the stored value is therefore the
        // UNSLASHED form. Compare against that, or an identical re-write would
        // look like a change, run update_post_meta(), come back false ("value
        // unchanged") and wrongly throw below.
        $compare_value = $meta_key === '_metasync_hreflang' ? wp_unslash($meta_value) : $meta_value;
        if ($current_value === $compare_value) {
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
                'robots_advanced' => json_decode(get_post_meta($post_id, '_metasync_robots_advanced', true) ?: '{}', true),
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
                'has_og_image' => !empty(get_post_meta($post_id, '_metasync_og_image', true)),
                'has_robots_advanced' => !empty(get_post_meta($post_id, '_metasync_robots_advanced', true))
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
 * validates that each referenced URL returns HTTP 200 and links back to
 * this post (hreflang reciprocity), and flags a missing x-default entry
 * and a missing self-reference.
 */
class MCP_Tool_Get_Hreflang_Links extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_hreflang_links';
    }

    public function get_description() {
        return 'Get all hreflang entries for a post (manual + WPML auto-detected), validate each URL returns HTTP 200 and links back to this post (reciprocity), and flag missing x-default / self-reference entries';
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

        // Merge: auto-detected first, then manual entries override by the
        // normalised language-code collision key. Rows with an invalid code
        // (empty key) are kept verbatim instead of collapsing into one —
        // the audit report is where they become visible.
        $by_key = [];
        $invalid = [];
        foreach ($wpml_entries as $entry) {
            $key = Metasync_Hreflang_Output::lang_code($entry);
            if ($key === '') {
                $invalid[] = $entry;
            } else {
                $by_key[$key] = $entry;
            }
        }
        foreach ($manual_entries as $entry) {
            $key = Metasync_Hreflang_Output::lang_code($entry);
            if ($key === '') {
                $invalid[] = $entry;
            } else {
                $by_key[$key] = $entry;
            }
        }
        $entries = array_merge(array_values($by_key), $invalid);

        $self_url = get_permalink($post_id);

        // Validate each URL: HTTP 200 (HEAD first, GET on 405) and — when
        // the target is an external alternate — a return link to this post
        // (hreflang must be bidirectional; Google ignores one-way clusters).
        $has_x_default = false;
        $has_self_reference = false;
        foreach ($entries as &$entry) {
            $url = isset($entry['url']) ? $entry['url'] : '';
            $status = null;
            $error = null;
            $return_link = null;

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

                // Reciprocity: fetch the alternate page and check whether
                // its hreflang set contains this post's URL.
                if ($error === null && $status === 200 && !empty($self_url) && $url !== $self_url) {
                    $response = wp_remote_get($url, ['timeout' => 5, 'sslverify' => false]);
                    if (!is_wp_error($response)) {
                        $body = (string) wp_remote_retrieve_body($response);
                        $return_link = self::body_links_back($body, $self_url);
                    }
                }
            }

            $entry['http_status'] = $status;
            $entry['http_ok'] = ($status === 200);
            if ($error !== null) {
                $entry['http_error'] = $error;
            }
            if ($return_link !== null) {
                $entry['return_link'] = $return_link;
            }

            if (Metasync_Hreflang_Output::lang_code($entry) === 'x-default') {
                $has_x_default = true;
            }
            if (!empty($self_url) && $url === $self_url) {
                $has_self_reference = true;
            }
        }
        unset($entry);

        return $this->success([
            'post_id'               => $post_id,
            'post_title'            => $post->post_title,
            'self_url'              => $self_url,
            'entries'               => $entries,
            'missing_x_default'     => !$has_x_default,
            'missing_self_reference' => !$has_self_reference,
        ]);
    }

    /**
     * True when the page body contains a hreflang link pointing back at the
     * given URL.
     *
     * @param string $body     HTML body of the alternate page.
     * @param string $self_url This post's permalink.
     * @return bool
     */
    private static function body_links_back($body, $self_url) {
        if (!preg_match_all('/<link[^>]+hreflang\s*=\s*[\'"]([^\'"]*)[\'"][^>]*>/i', (string) $body, $links)) {
            return false;
        }
        foreach ($links[0] as $tag) {
            if (stripos($tag, 'rel="alternate"') === false && stripos($tag, "rel='alternate'") === false) {
                continue;
            }
            if (strpos($tag, $self_url) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Read manual hreflang entries from `_metasync_hreflang` post meta.
     *
     * Rows are normalised and carry their final lang_code plus a valid flag,
     * so an invalid stored row is visible in the report (the emitter skips
     * it silently — this is the tool that surfaces it).
     *
     * @param int $post_id Post ID.
     * @return array
     */
    private function get_manual_entries($post_id) {
        $raw = get_post_meta($post_id, '_metasync_hreflang', true);
        if (empty($raw) || !is_string($raw)) {
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
            $normalized = Metasync_Hreflang_Output::normalize_entry($entry);
            $entries[] = [
                'lang'      => $normalized['lang'],
                'region'    => $normalized['region'],
                'url'       => $normalized['url'],
                'lang_code' => $normalized['lang_code'],
                'valid'     => $normalized['valid'],
                'source'    => 'manual',
            ];
        }
        return $entries;
    }

    /**
     * Build WPML auto-detected entries via the shared implementation in
     * Metasync_Hreflang_Output::get_wpml_entries() (publish-only rows,
     * x-default synthesis) so the audit reports exactly what the front end
     * emits.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object (already verified).
     * @return array
     */
    private function get_wpml_entries($post_id, $post) {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return [];
        }

        $entries = (new Metasync_Hreflang_Output())->get_wpml_entries($post_id);

        $out = [];
        foreach ($entries as $entry) {
            $normalized = Metasync_Hreflang_Output::normalize_entry($entry);
            $normalized['source'] = 'wpml';
            $out[] = $normalized;
        }
        return $out;
    }
}

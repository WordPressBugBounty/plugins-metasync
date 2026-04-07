<?php
/**
 * MCP Sync Logger
 *
 * Logs all write operations performed via MCP tools into the metasync_sync_history
 * table so they are visible in the Sync Log admin page.  Before-state is captured
 * and stored in meta_data JSON to enable rollback.
 *
 * @package    MetaSync
 * @subpackage MCP_Server
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_MCP_Sync_Logger {

    /**
     * Tool names that represent write operations and should be logged.
     * Covers post-meta, schema, taxonomy, redirects, robots, code snippets.
     */
    private static $write_tool_prefixes = [
        'wordpress_create_',
        'wordpress_update_',
        'wordpress_delete_',
        'wordpress_set_',
        'wordpress_add_',
    ];

    /**
     * Temporarily holds before-state keyed by tool name while the tool executes.
     * @var array
     */
    private $before_states = [];

    /**
     * Constructor — wire up action hooks.
     */
    public function __construct() {
        add_action('metasync_mcp_tool_before_execute', [ $this, 'capture_before_state' ], 10, 2);
        add_action('metasync_mcp_tool_executed',       [ $this, 'log_tool_execution'  ], 10, 4);
    }

    /**
     * Determine whether a tool name is a write operation.
     *
     * @param string $tool_name
     * @return bool
     */
    public static function is_write_tool($tool_name) {
        foreach (self::$write_tool_prefixes as $prefix) {
            if (strncmp($tool_name, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Capture before-state immediately before the tool executes.
     * Fires on metasync_mcp_tool_before_execute.
     *
     * @param string $tool_name
     * @param array  $params
     */
    public function capture_before_state($tool_name, $params) {
        if (!self::is_write_tool($tool_name)) {
            return;
        }
        $this->before_states[$tool_name] = $this->fetch_before_state($tool_name, $params);
    }

    /**
     * Log the tool execution after it completes.
     * Fires on metasync_mcp_tool_executed.
     *
     * @param string $tool_name
     * @param array  $params
     * @param mixed  $result
     * @param float  $execution_time
     */
    public function log_tool_execution($tool_name, $params, $result, $execution_time) {
        if (!self::is_write_tool($tool_name)) {
            return;
        }

        // Only log successful operations.
        if (is_array($result) && isset($result['success']) && $result['success'] === false) {
            return;
        }

        try {
            $before_state = isset($this->before_states[$tool_name])
                ? $this->before_states[$tool_name]
                : null;

            // Free memory immediately.
            unset($this->before_states[$tool_name]);

            $sync_db = new Metasync_Sync_History_Database();

            $post_id = isset($params['post_id']) ? intval($params['post_id']) : null;
            $url     = $post_id ? get_permalink($post_id) : null;

            $sync_db->add([
                'title'        => $this->build_log_title($tool_name, $params),
                'source'       => 'MCP Client',
                'status'       => 'published',
                'content_type' => $tool_name,
                'url'          => $url ?: '',
                'meta_data'    => wp_json_encode([
                    'tool'   => $tool_name,
                    'params' => $params,
                    'before' => $before_state,
                ]),
                'created_at'   => current_time('mysql'),
            ]);
        } catch (Exception $e) {
            // Never break MCP execution because of logging.
            error_log('MetaSync MCP Sync Logger error: ' . $e->getMessage());
        }
    }

    /**
     * Fetch the current (before) state for a write tool based on its params.
     *
     * @param string $tool_name
     * @param array  $params
     * @return array|null
     */
    private function fetch_before_state($tool_name, $params) {
        // Post meta (single key update)
        if ($tool_name === 'wordpress_update_post_meta') {
            $post_id  = isset($params['post_id'])  ? intval($params['post_id'])  : 0;
            $meta_key = isset($params['meta_key']) ? sanitize_text_field($params['meta_key']) : '';
            if ($post_id && $meta_key) {
                return [
                    'post_id'    => $post_id,
                    'meta_key'   => $meta_key,
                    'meta_value' => get_post_meta($post_id, $meta_key, true),
                ];
            }
        }

        // Schema markup
        if (in_array($tool_name, ['wordpress_update_schema_markup', 'wordpress_add_schema_type'], true)) {
            $post_id = isset($params['post_id']) ? intval($params['post_id']) : 0;
            if ($post_id) {
                return [
                    'post_id' => $post_id,
                    'schema'  => get_post_meta($post_id, '_metasync_schema_markup', true),
                ];
            }
        }

        // Post tags / categories (relationship)
        if (in_array($tool_name, ['wordpress_set_post_tags', 'wordpress_set_post_categories'], true)) {
            $post_id  = isset($params['post_id']) ? intval($params['post_id']) : 0;
            $taxonomy = ($tool_name === 'wordpress_set_post_tags') ? 'post_tag' : 'category';
            if ($post_id) {
                $terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
                return [
                    'post_id'  => $post_id,
                    'taxonomy' => $taxonomy,
                    'term_ids' => is_wp_error($terms) ? [] : $terms,
                ];
            }
        }

        // Category / tag update or delete
        if (in_array($tool_name, [
            'wordpress_update_category', 'wordpress_delete_category',
            'wordpress_update_tag',      'wordpress_delete_tag',
        ], true)) {
            $term_id  = isset($params['id']) ? intval($params['id']) : 0;
            $taxonomy = strpos($tool_name, 'category') !== false ? 'category' : 'post_tag';
            if ($term_id) {
                $term = get_term($term_id, $taxonomy);
                if ($term && !is_wp_error($term)) {
                    return [
                        'term_id'     => $term_id,
                        'taxonomy'    => $taxonomy,
                        'name'        => $term->name,
                        'slug'        => $term->slug,
                        'description' => $term->description,
                    ];
                }
            }
        }

        // For all other write tools just record the params as before-state context.
        return [ 'params_snapshot' => $params ];
    }

    /**
     * Build a human-readable log title.
     *
     * @param string $tool_name
     * @param array  $params
     * @return string
     */
    private function build_log_title($tool_name, $params) {
        $post_id  = isset($params['post_id']) ? intval($params['post_id']) : null;
        $post_title = $post_id ? get_the_title($post_id) : null;

        // Field-specific titles for post meta updates
        if ($tool_name === 'wordpress_update_post_meta') {
            $meta_key = isset($params['meta_key']) ? sanitize_text_field($params['meta_key']) : '';
            $meta_field_map = [
                '_metasync_metatitle'        => 'Meta Title',
                '_metasync_metadesc'         => 'Meta Description',
                '_metasync_focus_keyword'    => 'Focus Keyword',
                '_metasync_robots_index'     => 'Robots Index',
                '_metasync_canonical_url'    => 'Canonical URL',
                '_metasync_og_enabled'       => 'Open Graph',
                '_metasync_og_title'         => 'OG Title',
                '_metasync_og_description'   => 'OG Description',
                '_metasync_og_image'         => 'OG Image',
                '_metasync_og_url'           => 'OG URL',
                '_metasync_og_type'          => 'OG Type',
            ];

            $field_name = isset($meta_field_map[$meta_key])
                ? $meta_field_map[$meta_key]
                : ucwords(str_replace(['_metasync_', '_'], ['', ' '], $meta_key));

            if ($post_title) {
                return $field_name . ' Updated: ' . $post_title;
            }
            return $field_name . ' Updated via MCP';
        }

        // Schema-specific titles
        if ($tool_name === 'wordpress_add_schema_type') {
            $schema_type = isset($params['schema_type']) ? sanitize_text_field($params['schema_type']) : 'Schema';
            if ($post_title) {
                return 'Added ' . $schema_type . ' schema: ' . $post_title;
            }
            return 'Added ' . $schema_type . ' schema via MCP';
        }

        if ($tool_name === 'wordpress_update_schema_markup') {
            if ($post_title) {
                return 'Updated schema markup: ' . $post_title;
            }
            return 'Updated schema markup via MCP';
        }

        // Tag-specific titles
        if ($tool_name === 'wordpress_create_tag') {
            $name = isset($params['name']) ? sanitize_text_field($params['name']) : 'Tag';
            return 'Created tag: ' . $name;
        }

        if ($tool_name === 'wordpress_update_tag') {
            $id = isset($params['id']) ? intval($params['id']) : 0;
            $term = $id ? get_term($id, 'post_tag') : null;
            $tag_name = ($term && !is_wp_error($term)) ? $term->name : 'Tag';
            return 'Updated tag: ' . $tag_name;
        }

        if ($tool_name === 'wordpress_delete_tag') {
            $id = isset($params['id']) ? intval($params['id']) : 0;
            $term = $id ? get_term($id, 'post_tag') : null;
            $tag_name = ($term && !is_wp_error($term)) ? $term->name : 'Tag';
            return 'Deleted tag: ' . $tag_name;
        }

        if ($tool_name === 'wordpress_set_post_tags') {
            if ($post_title) {
                return 'Set post tags: ' . $post_title;
            }
            return 'Set post tags via MCP';
        }

        // Category-specific titles
        if ($tool_name === 'wordpress_create_category') {
            $name = isset($params['name']) ? sanitize_text_field($params['name']) : 'Category';
            return 'Created category: ' . $name;
        }

        if ($tool_name === 'wordpress_update_category') {
            $id = isset($params['id']) ? intval($params['id']) : 0;
            $term = $id ? get_term($id, 'category') : null;
            $cat_name = ($term && !is_wp_error($term)) ? $term->name : 'Category';
            return 'Updated category: ' . $cat_name;
        }

        if ($tool_name === 'wordpress_delete_category') {
            $id = isset($params['id']) ? intval($params['id']) : 0;
            $term = $id ? get_term($id, 'category') : null;
            $cat_name = ($term && !is_wp_error($term)) ? $term->name : 'Category';
            return 'Deleted category: ' . $cat_name;
        }

        if ($tool_name === 'wordpress_set_post_categories') {
            if ($post_title) {
                return 'Set post categories: ' . $post_title;
            }
            return 'Set post categories via MCP';
        }

        // Fallback for unknown tools
        $action = ucwords(str_replace(['wordpress_', '_'], ['', ' '], $tool_name));
        if ($post_title) {
            return $action . ': ' . $post_title;
        }
        if (isset($params['name'])) {
            return $action . ': ' . sanitize_text_field($params['name']);
        }
        return $action . ' via MCP';
    }

    /**
     * Rollback a single sync history entry that was logged by MCP Client.
     *
     * Reads the `before` state stored in meta_data and restores it.
     *
     * @param int $sync_history_id
     * @return array ['success' => bool, 'message' => string]
     */
    public static function rollback($sync_history_id) {
        $sync_db = new Metasync_Sync_History_Database();
        $record  = $sync_db->get_by_id(intval($sync_history_id));

        if (!$record) {
            return [ 'success' => false, 'message' => 'Sync history record not found.' ];
        }

        if ($record->source !== 'MCP Client') {
            return [ 'success' => false, 'message' => 'Only MCP Client entries can be rolled back.' ];
        }

        $meta = json_decode($record->meta_data, true);

        if (empty($meta['before'])) {
            return [ 'success' => false, 'message' => 'No before-state available for rollback.' ];
        }

        $tool_name = isset($meta['tool']) ? $meta['tool'] : '';
        $before    = $meta['before'];

        try {
            $result = self::apply_rollback($tool_name, $before);
            if ($result) {
                return [ 'success' => true, 'message' => 'Rollback applied successfully.' ];
            }
            return [ 'success' => false, 'message' => 'Rollback not supported for this tool.' ];
        } catch (Exception $e) {
            return [ 'success' => false, 'message' => 'Rollback failed: ' . $e->getMessage() ];
        }
    }

    /**
     * Apply the rollback using the before-state data.
     *
     * @param string $tool_name
     * @param array  $before
     * @return bool
     */
    private static function apply_rollback($tool_name, $before) {
        // Restore single post meta value
        if ($tool_name === 'wordpress_update_post_meta') {
            if (isset($before['post_id'], $before['meta_key'])) {
                update_post_meta($before['post_id'], $before['meta_key'], $before['meta_value']);
                return true;
            }
        }

        // Restore schema markup
        if (in_array($tool_name, ['wordpress_update_schema_markup', 'wordpress_add_schema_type'], true)) {
            if (isset($before['post_id'])) {
                update_post_meta($before['post_id'], '_metasync_schema_markup', $before['schema']);
                return true;
            }
        }

        // Restore post term relationships
        if (in_array($tool_name, ['wordpress_set_post_tags', 'wordpress_set_post_categories'], true)) {
            if (isset($before['post_id'], $before['taxonomy'], $before['term_ids'])) {
                wp_set_object_terms($before['post_id'], $before['term_ids'], $before['taxonomy']);
                return true;
            }
        }

        // Restore a term (category or tag) that was updated
        if (in_array($tool_name, ['wordpress_update_category', 'wordpress_update_tag'], true)) {
            if (isset($before['term_id'], $before['taxonomy'])) {
                wp_update_term($before['term_id'], $before['taxonomy'], [
                    'name'        => $before['name'],
                    'slug'        => $before['slug'],
                    'description' => $before['description'],
                ]);
                return true;
            }
        }

        return false;
    }
}

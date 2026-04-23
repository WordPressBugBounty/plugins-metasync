<?php
/**
 * MCP Tools: SEO Plugin Audit
 *
 * Provides four MCP tools for auditing SEO data across MetaSync and
 * active third-party SEO plugins (Yoast, Rank Math, AIOSEO):
 *
 *   - wordpress_read_seo_plugin_data
 *   - wordpress_seo_plugin_diff
 *   - wordpress_sync_to_active_plugins
 *   - wordpress_detect_seo_conflicts
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helper: detect active SEO plugins.
 *
 * Returns an associative array with boolean flags for each supported plugin.
 */
if (!function_exists('metasync_seo_audit_detect_active_plugins')) {
    function metasync_seo_audit_detect_active_plugins() {
        $detect = function ($slug) {
            if (function_exists('metasync_is_plugin_active')) {
                return metasync_is_plugin_active($slug);
            }
            if (!function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            return is_plugin_active($slug);
        };

        return [
            'yoast' => $detect('wordpress-seo/wp-seo.php')
                || $detect('wordpress-seo-premium/wp-seo-premium.php'),
            'rank_math' => $detect('seo-by-rank-math/rank-math.php')
                || $detect('seo-by-rankmath/rank-math.php')
                || $detect('seo-by-rank-math-pro/rank-math-pro.php'),
            'aioseo' => $detect('all-in-one-seo-pack/all_in_one_seo_pack.php')
                || $detect('all-in-one-seo-pack-pro/all_in_one_seo_pack.php'),
        ];
    }
}

/**
 * Tool: wordpress_read_seo_plugin_data
 *
 * Reads native meta storage for all active SEO plugins for a given
 * post and returns a normalized structure. Returns null for plugins
 * that are not installed (not errors).
 */
class MCP_Tool_Read_SEO_Plugin_Data extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_read_seo_plugin_data';
    }

    public function get_description() {
        return 'Read native SEO plugin meta (Yoast, Rank Math, AIOSEO) plus MetaSync values for a post in a normalized structure';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'WordPress post or page ID',
                    'minimum' => 1,
                ],
            ],
            'required' => ['post_id'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $post_id = $this->sanitize_integer($params['post_id']);
        $this->verify_post_exists($post_id);
        $this->check_post_permission($post_id);

        $active = metasync_seo_audit_detect_active_plugins();

        $active_list = [];
        foreach ($active as $slug => $is_active) {
            if ($is_active) {
                $active_list[] = $slug;
            }
        }

        $metasync_data = $this->read_metasync($post_id);
        $yoast_data = $active['yoast'] ? $this->read_yoast($post_id) : null;
        $rank_math_data = $active['rank_math'] ? $this->read_rank_math($post_id) : null;
        $aioseo_data = $active['aioseo'] ? $this->read_aioseo($post_id) : null;

        return $this->success([
            'post_id' => $post_id,
            'active_plugins' => $active_list,
            'metasync' => $metasync_data,
            'yoast' => $yoast_data,
            'rank_math' => $rank_math_data,
            'aioseo' => $aioseo_data,
        ]);
    }

    /**
     * Read MetaSync meta values. Always returned (MetaSync is always "active").
     */
    private function read_metasync($post_id) {
        $schema = get_post_meta($post_id, 'metasync_schema_markup', true);
        $schema_types = [];
        if (!empty($schema)) {
            $decoded = is_string($schema) ? json_decode($schema, true) : $schema;
            if (is_array($decoded)) {
                $this->collect_schema_types($decoded, $schema_types);
            }
        }

        return [
            'title' => get_post_meta($post_id, '_metasync_metatitle', true),
            'otto_title' => get_post_meta($post_id, '_metasync_otto_title', true),
            'description' => get_post_meta($post_id, '_metasync_metadesc', true),
            'otto_description' => get_post_meta($post_id, '_metasync_otto_description', true),
            'og_title' => get_post_meta($post_id, '_metasync_og_title', true),
            'og_description' => get_post_meta($post_id, '_metasync_og_description', true),
            'og_image' => get_post_meta($post_id, '_metasync_og_image', true),
            'twitter_title' => get_post_meta($post_id, '_metasync_twitter_title', true),
            'twitter_description' => get_post_meta($post_id, '_metasync_twitter_description', true),
            'focus_keyword' => get_post_meta($post_id, '_metasync_focus_keyword', true),
            'canonical' => get_post_meta($post_id, '_metasync_canonical_url', true) ?: null,
            'robots'    => get_post_meta($post_id, '_metasync_robots_index', true) ?: null,
            'schema_types' => array_values(array_unique($schema_types)),
        ];
    }

    /**
     * Recursively collect @type values from a schema array.
     */
    private function collect_schema_types($data, array &$types) {
        if (!is_array($data)) {
            return;
        }
        if (isset($data['@type'])) {
            if (is_array($data['@type'])) {
                foreach ($data['@type'] as $t) {
                    if (is_string($t)) {
                        $types[] = $t;
                    }
                }
            } elseif (is_string($data['@type'])) {
                $types[] = $data['@type'];
            }
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $this->collect_schema_types($value, $types);
            }
        }
    }

    private function read_yoast($post_id) {
        return [
            'title' => get_post_meta($post_id, '_yoast_wpseo_title', true),
            'description' => get_post_meta($post_id, '_yoast_wpseo_metadesc', true),
            'canonical' => get_post_meta($post_id, '_yoast_wpseo_canonical', true),
            'robots_noindex' => get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true),
            'og_title' => get_post_meta($post_id, '_yoast_wpseo_opengraph-title', true),
            'og_description' => get_post_meta($post_id, '_yoast_wpseo_opengraph-description', true),
        ];
    }

    private function read_rank_math($post_id) {
        return [
            'title' => get_post_meta($post_id, 'rank_math_title', true),
            'description' => get_post_meta($post_id, 'rank_math_description', true),
            'canonical' => get_post_meta($post_id, 'rank_math_canonical_url', true),
            'robots' => get_post_meta($post_id, 'rank_math_robots', true),
            'advanced_robots' => get_post_meta($post_id, 'rank_math_advanced_robots', true),
            'focus_keyword' => get_post_meta($post_id, 'rank_math_focus_keyword', true),
            'og_title' => get_post_meta($post_id, 'rank_math_facebook_title', true),
            'og_description' => get_post_meta($post_id, 'rank_math_facebook_description', true),
        ];
    }

    private function read_aioseo($post_id) {
        global $wpdb;
        try {
            $table = $wpdb->prefix . 'aioseo_posts';
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists !== $table) {
                return null;
            }

            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT title, description, canonical_url, robots_default, robots_noindex, robots_nofollow, schema_type
                     FROM {$wpdb->prefix}aioseo_posts
                     WHERE post_id = %d",
                    $post_id
                ),
                ARRAY_A
            );

            if (!$row) {
                return [
                    'title' => null,
                    'description' => null,
                    'canonical' => null,
                    'robots_default' => null,
                    'robots_noindex' => null,
                    'robots_nofollow' => null,
                    'schema_type' => null,
                ];
            }

            return [
                'title' => isset($row['title']) ? $row['title'] : null,
                'description' => isset($row['description']) ? $row['description'] : null,
                'canonical' => isset($row['canonical_url']) ? $row['canonical_url'] : null,
                'robots_default' => isset($row['robots_default']) ? $row['robots_default'] : null,
                'robots_noindex' => isset($row['robots_noindex']) ? $row['robots_noindex'] : null,
                'robots_nofollow' => isset($row['robots_nofollow']) ? $row['robots_nofollow'] : null,
                'schema_type' => isset($row['schema_type']) ? $row['schema_type'] : null,
            ];
        } catch (Exception $e) {
            return null;
        }
    }
}

/**
 * Tool: wordpress_seo_plugin_diff
 *
 * Reads MetaSync + active plugin values for a post and categorises
 * each canonical field as conflict / agreement / migration_opportunity /
 * sync_gap.
 */
class MCP_Tool_SEO_Plugin_Diff extends MCP_Tool_Base {

    /**
     * Canonical field map — defines how a logical field maps to each
     * plugin's stored meta/column key.
     *
     * null = plugin does not store this field natively.
     */
    private $field_map = [
        'title' => [
            'metasync' => ['title', 'otto_title'],
            'yoast' => 'title',
            'rank_math' => 'title',
            'aioseo' => 'title',
        ],
        'description' => [
            'metasync' => ['description', 'otto_description'],
            'yoast' => 'description',
            'rank_math' => 'description',
            'aioseo' => 'description',
        ],
        'canonical' => [
            'metasync' => ['canonical'],
            'yoast' => 'canonical',
            'rank_math' => 'canonical',
            'aioseo' => 'canonical',
        ],
        'og_title' => [
            'metasync' => ['og_title'],
            'yoast' => 'og_title',
            'rank_math' => 'og_title',
            'aioseo' => null,
        ],
        'og_description' => [
            'metasync' => ['og_description'],
            'yoast' => 'og_description',
            'rank_math' => 'og_description',
            'aioseo' => null,
        ],
        'focus_keyword' => [
            'metasync' => ['focus_keyword'],
            'yoast' => null,
            'rank_math' => 'focus_keyword',
            'aioseo' => null,
        ],
        'robots' => [
            'metasync' => ['robots'],
            'yoast' => 'robots_noindex',
            'rank_math' => 'robots',
            'aioseo' => 'robots_noindex',
        ],
    ];

    public function get_name() {
        return 'wordpress_seo_plugin_diff';
    }

    public function get_description() {
        return 'Compare MetaSync values against each active SEO plugin and categorise as conflicts, agreements, migration opportunities, or sync gaps';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'WordPress post or page ID',
                    'minimum' => 1,
                ],
            ],
            'required' => ['post_id'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $post_id = $this->sanitize_integer($params['post_id']);
        $this->verify_post_exists($post_id);
        $this->check_post_permission($post_id);

        $reader = new MCP_Tool_Read_SEO_Plugin_Data();
        $raw = $reader->execute(['post_id' => $post_id]);
        $data = isset($raw['data']) ? $raw['data'] : [];

        $metasync = isset($data['metasync']) ? $data['metasync'] : [];
        $plugin_data = [
            'yoast' => isset($data['yoast']) ? $data['yoast'] : null,
            'rank_math' => isset($data['rank_math']) ? $data['rank_math'] : null,
            'aioseo' => isset($data['aioseo']) ? $data['aioseo'] : null,
        ];

        $conflicts = [];
        $agreements = [];
        $migration_opportunities = [];
        $sync_gaps = [];

        foreach ($this->field_map as $field => $mapping) {
            $metasync_value = $this->resolve_metasync_value($metasync, $mapping['metasync']);

            $plugin_values = [];
            $field_agreements = [];
            foreach (['yoast', 'rank_math', 'aioseo'] as $plugin) {
                if ($plugin_data[$plugin] === null) {
                    continue;
                }
                $plugin_key = $mapping[$plugin];
                if ($plugin_key === null) {
                    continue;
                }
                $plugin_value = isset($plugin_data[$plugin][$plugin_key]) ? $plugin_data[$plugin][$plugin_key] : null;
                $plugin_values[$plugin] = $plugin_value;

                $this->categorise(
                    $field,
                    $plugin,
                    $metasync_value,
                    $plugin_value,
                    $conflicts,
                    $field_agreements,
                    $migration_opportunities,
                    $sync_gaps
                );
            }

            // Agreement-across-plugins roll-up: if metasync has a value and every
            // active plugin that supports the field has the same value, emit a
            // consolidated "all_plugins: true" agreement entry instead of the
            // per-plugin entries to avoid double-counting.
            if (!$this->is_empty($metasync_value) && !empty($plugin_values)) {
                $all_same = true;
                foreach ($plugin_values as $pv) {
                    if ($this->is_empty($pv) || (string) $pv !== (string) $metasync_value) {
                        $all_same = false;
                        break;
                    }
                }
                if ($all_same) {
                    $agreements[] = [
                        'field' => $field,
                        'value' => $metasync_value,
                        'all_plugins' => true,
                    ];
                } else {
                    foreach ($field_agreements as $entry) {
                        $agreements[] = $entry;
                    }
                }
            } else {
                foreach ($field_agreements as $entry) {
                    $agreements[] = $entry;
                }
            }
        }

        return $this->success([
            'post_id' => $post_id,
            'conflicts' => $conflicts,
            'agreements' => $agreements,
            'migration_opportunities' => $migration_opportunities,
            'sync_gaps' => $sync_gaps,
        ]);
    }

    /**
     * Resolve a MetaSync logical-field value using the first non-empty
     * candidate key (supports fallback chains such as title → otto_title).
     */
    private function resolve_metasync_value($metasync, $keys) {
        if ($keys === null) {
            return null;
        }
        if (!is_array($keys)) {
            $keys = [$keys];
        }
        foreach ($keys as $key) {
            if (isset($metasync[$key]) && !$this->is_empty($metasync[$key])) {
                return $metasync[$key];
            }
        }
        return '';
    }

    private function is_empty($value) {
        if ($value === null) {
            return true;
        }
        if (is_array($value)) {
            return empty($value);
        }
        return $value === '' || $value === '0';
    }

    private function categorise(
        $field,
        $plugin,
        $metasync_value,
        $plugin_value,
        array &$conflicts,
        array &$agreements,
        array &$migration_opportunities,
        array &$sync_gaps
    ) {
        $ms_empty = $this->is_empty($metasync_value);
        $pl_empty = $this->is_empty($plugin_value);

        if (!$ms_empty && !$pl_empty) {
            $ms_str = is_scalar($metasync_value) ? (string) $metasync_value : wp_json_encode($metasync_value);
            $pl_str = is_scalar($plugin_value) ? (string) $plugin_value : wp_json_encode($plugin_value);
            if ($ms_str === $pl_str) {
                $agreements[] = [
                    'field' => $field,
                    'plugin' => $plugin,
                    'value' => $metasync_value,
                ];
            } else {
                $conflicts[] = [
                    'field' => $field,
                    'metasync_value' => $metasync_value,
                    $plugin . '_value' => $plugin_value,
                    'winner' => 'metasync',
                    'reason' => sprintf(
                        'MetaSync filter runs at priority 999 via Metasync_SEO_Conflict_Handler, suppressing %s output when MetaSync has a value',
                        $plugin
                    ),
                ];
            }
            return;
        }

        if ($ms_empty && !$pl_empty) {
            $migration_opportunities[] = [
                'field' => $field,
                'source' => $plugin,
                'value' => $plugin_value,
                'note' => 'MetaSync has no ' . $field . ' set — plugin data can be imported into MetaSync',
            ];
            return;
        }

        if (!$ms_empty && $pl_empty) {
            $sync_gaps[] = [
                'field' => $field,
                'metasync_value' => $metasync_value,
                $plugin . '_value' => null,
                'note' => sprintf('MetaSync %s not written to %s (WP-196 sync needed)', $field, $plugin),
            ];
            return;
        }
        // both empty: nothing to report
    }
}

/**
 * Tool: wordpress_sync_to_active_plugins
 *
 * Bulk-syncs MetaSync data to each active third-party plugin via
 * the Metasync_Plugin_Sync class (WP-196). Guarded with class_exists.
 */
class MCP_Tool_Sync_To_Active_Plugins extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_sync_to_active_plugins';
    }

    public function get_description() {
        return 'Bulk-sync MetaSync SEO data to all active third-party SEO plugins (requires WP-196 Metasync_Plugin_Sync)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'post_ids' => [
                    'type' => 'array',
                    'description' => 'Post IDs to sync',
                    'items' => [
                        'type' => 'integer',
                        'minimum' => 1,
                    ],
                ],
                'fields' => [
                    'type' => 'array',
                    'description' => 'Optional list of fields to sync (empty = all)',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
            ],
            'required' => ['post_ids'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        if (!class_exists('Metasync_Plugin_Sync')) {
            throw new Exception(
                'Metasync_Plugin_Sync class not found — this tool requires WP-196 to be merged and active.'
            );
        }

        $post_ids = isset($params['post_ids']) && is_array($params['post_ids']) ? $params['post_ids'] : [];
        $fields = isset($params['fields']) && is_array($params['fields']) ? $params['fields'] : [];

        $syncer = new Metasync_Plugin_Sync();
        $results = [];
        $synced = 0;

        foreach ($post_ids as $raw_id) {
            $post_id = absint($raw_id);
            if ($post_id < 1) {
                $results[] = [
                    'post_id' => $raw_id,
                    'success' => false,
                    'details' => 'Invalid post_id',
                ];
                continue;
            }

            try {
                $this->verify_post_exists($post_id);
                $this->check_post_permission($post_id);
                $result = $syncer->sync_post($post_id, $fields);
                $results[] = [
                    'post_id' => $post_id,
                    'success' => true,
                    'details' => $result,
                ];
                $synced++;
            } catch (Exception $e) {
                $results[] = [
                    'post_id' => $post_id,
                    'success' => false,
                    'details' => $e->getMessage(),
                ];
            }
        }

        return $this->success([
            'synced' => $synced,
            'results' => $results,
        ]);
    }
}

/**
 * Tool: wordpress_detect_seo_conflicts
 *
 * Fetches live rendered HTML for a URL, parses the <head>, and reports
 * duplicate tags, mismatches against stored MetaSync values, and missing
 * tags. Returns a health score (100 = no issues).
 */
class MCP_Tool_Detect_SEO_Conflicts extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_detect_seo_conflicts';
    }

    public function get_description() {
        return 'Fetch live rendered HTML and detect duplicate/mismatched/missing SEO tags vs stored MetaSync values. Returns a health score.';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'Full URL to audit',
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $url = $this->sanitize_url($params['url']);
        if (empty($url)) {
            throw new InvalidArgumentException('Invalid URL');
        }

        $response = wp_remote_get($url, [
            'user-agent' => 'MetaSync-Audit/1.0',
            'timeout' => 10,
        ]);
        if (is_wp_error($response)) {
            throw new Exception('Failed to fetch URL: ' . $response->get_error_message());
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            throw new Exception('Empty response body for URL');
        }

        $rendered = $this->parse_head($html);
        $post_id = url_to_postid($url);
        if ($post_id) {
            $this->check_post_permission($post_id);
        }
        $stored = $post_id ? $this->read_stored_metasync($post_id) : [];

        $issues = [];

        // Duplicate detection
        if ($rendered['counts']['title'] > 1) {
            $issues[] = [
                'type' => 'duplicate',
                'tag' => 'title',
                'count' => $rendered['counts']['title'],
                'severity' => 'high',
            ];
        }
        if ($rendered['counts']['meta_description'] > 1) {
            $issues[] = [
                'type' => 'duplicate',
                'tag' => 'meta[name=description]',
                'count' => $rendered['counts']['meta_description'],
                'severity' => 'high',
            ];
        }
        if ($rendered['counts']['canonical'] > 1) {
            $issues[] = [
                'type' => 'duplicate',
                'tag' => 'link[rel=canonical]',
                'count' => $rendered['counts']['canonical'],
                'severity' => 'high',
            ];
        }
        if ($rendered['counts']['robots'] > 1) {
            $issues[] = [
                'type' => 'duplicate',
                'tag' => 'meta[name=robots]',
                'count' => $rendered['counts']['robots'],
                'severity' => 'medium',
            ];
        }
        foreach ($rendered['og_counts'] as $prop => $count) {
            if ($count > 1) {
                $issues[] = [
                    'type' => 'duplicate',
                    'tag' => 'meta[property=' . $prop . ']',
                    'count' => $count,
                    'severity' => 'high',
                ];
            }
        }

        // Mismatch / missing detection vs stored MetaSync values
        $comparisons = [
            'title' => [
                'stored' => $this->first_non_empty([
                    isset($stored['title']) ? $stored['title'] : '',
                    isset($stored['otto_title']) ? $stored['otto_title'] : '',
                ]),
                'rendered' => $rendered['title'],
            ],
            'description' => [
                'stored' => $this->first_non_empty([
                    isset($stored['description']) ? $stored['description'] : '',
                    isset($stored['otto_description']) ? $stored['otto_description'] : '',
                ]),
                'rendered' => $rendered['meta_description'],
            ],
            'og_title' => [
                'stored' => isset($stored['og_title']) ? $stored['og_title'] : '',
                'rendered' => isset($rendered['og']['og:title']) ? $rendered['og']['og:title'] : '',
            ],
            'og_description' => [
                'stored' => isset($stored['og_description']) ? $stored['og_description'] : '',
                'rendered' => isset($rendered['og']['og:description']) ? $rendered['og']['og:description'] : '',
            ],
            'og_image' => [
                'stored' => isset($stored['og_image']) ? $stored['og_image'] : '',
                'rendered' => isset($rendered['og']['og:image']) ? $rendered['og']['og:image'] : '',
            ],
        ];

        foreach ($comparisons as $field => $pair) {
            $stored_val = (string) $pair['stored'];
            $rendered_val = (string) $pair['rendered'];
            if ($stored_val === '') {
                continue;
            }
            if ($rendered_val === '') {
                $issues[] = [
                    'type' => 'missing',
                    'field' => $field,
                    'expected' => $stored_val,
                    'severity' => 'low',
                ];
            } elseif ($rendered_val !== $stored_val) {
                $issues[] = [
                    'type' => 'mismatch',
                    'field' => $field,
                    'stored' => $stored_val,
                    'rendered' => $rendered_val,
                    'severity' => 'medium',
                ];
            }
        }

        // Health score
        $health_score = 100;
        foreach ($issues as $issue) {
            switch ($issue['severity']) {
                case 'high':
                    $health_score -= 15;
                    break;
                case 'medium':
                    $health_score -= 5;
                    break;
                case 'low':
                    $health_score -= 2;
                    break;
            }
        }
        if ($health_score < 0) {
            $health_score = 0;
        }
        if ($health_score > 100) {
            $health_score = 100;
        }

        return $this->success([
            'url' => $url,
            'post_id' => $post_id ? $post_id : null,
            'rendered_head' => [
                'title' => $rendered['title'],
                'meta_description' => $rendered['meta_description'],
                'canonical' => $rendered['canonical'],
                'robots' => $rendered['robots'],
                'og' => $rendered['og'],
                'schema_types' => $rendered['schema_types'],
            ],
            'issues' => $issues,
            'health_score' => $health_score,
        ]);
    }

    /**
     * Parse the <head> of the rendered HTML using PHP DOMDocument.
     */
    private function parse_head($html) {
        $result = [
            'title' => '',
            'meta_description' => '',
            'canonical' => '',
            'robots' => '',
            'og' => [],
            'schema_types' => [],
            'counts' => [
                'title' => 0,
                'meta_description' => 0,
                'canonical' => 0,
                'robots' => 0,
            ],
            'og_counts' => [],
        ];

        if (!class_exists('DOMDocument')) {
            return $result;
        }

        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $titles = $dom->getElementsByTagName('title');
        $result['counts']['title'] = $titles->length;
        if ($titles->length > 0) {
            $result['title'] = trim($titles->item(0)->textContent);
        }

        $metas = $dom->getElementsByTagName('meta');
        foreach ($metas as $meta) {
            $name = $meta->getAttribute('name');
            $property = $meta->getAttribute('property');
            $content = $meta->getAttribute('content');

            $name_lc = strtolower($name);
            $property_lc = strtolower($property);

            if ($name_lc === 'description') {
                $result['counts']['meta_description']++;
                if ($result['meta_description'] === '') {
                    $result['meta_description'] = $content;
                }
            } elseif ($name_lc === 'robots') {
                $result['counts']['robots']++;
                if ($result['robots'] === '') {
                    $result['robots'] = $content;
                }
            }

            if ($property_lc !== '' && strpos($property_lc, 'og:') === 0) {
                if (!isset($result['og_counts'][$property_lc])) {
                    $result['og_counts'][$property_lc] = 0;
                }
                $result['og_counts'][$property_lc]++;
                if (!isset($result['og'][$property_lc])) {
                    $result['og'][$property_lc] = $content;
                }
            }
        }

        $links = $dom->getElementsByTagName('link');
        foreach ($links as $link) {
            $rel = strtolower($link->getAttribute('rel'));
            if ($rel === 'canonical') {
                $result['counts']['canonical']++;
                if ($result['canonical'] === '') {
                    $result['canonical'] = $link->getAttribute('href');
                }
            }
        }

        $scripts = $dom->getElementsByTagName('script');
        foreach ($scripts as $script) {
            $type = strtolower($script->getAttribute('type'));
            if ($type !== 'application/ld+json') {
                continue;
            }
            $json = trim($script->textContent);
            if ($json === '') {
                continue;
            }
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                continue;
            }
            $this->collect_schema_types($decoded, $result['schema_types']);
        }
        $result['schema_types'] = array_values(array_unique($result['schema_types']));

        return $result;
    }

    private function collect_schema_types($data, array &$types) {
        if (!is_array($data)) {
            return;
        }
        if (isset($data['@type'])) {
            if (is_array($data['@type'])) {
                foreach ($data['@type'] as $t) {
                    if (is_string($t)) {
                        $types[] = $t;
                    }
                }
            } elseif (is_string($data['@type'])) {
                $types[] = $data['@type'];
            }
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $this->collect_schema_types($value, $types);
            }
        }
    }

    private function read_stored_metasync($post_id) {
        return [
            'title' => get_post_meta($post_id, '_metasync_metatitle', true),
            'otto_title' => get_post_meta($post_id, '_metasync_otto_title', true),
            'description' => get_post_meta($post_id, '_metasync_metadesc', true),
            'otto_description' => get_post_meta($post_id, '_metasync_otto_description', true),
            'og_title' => get_post_meta($post_id, '_metasync_og_title', true),
            'og_description' => get_post_meta($post_id, '_metasync_og_description', true),
            'og_image' => get_post_meta($post_id, '_metasync_og_image', true),
        ];
    }

    private function first_non_empty(array $values) {
        foreach ($values as $v) {
            if ($v !== null && $v !== '' && $v !== '0') {
                return $v;
            }
        }
        return '';
    }
}

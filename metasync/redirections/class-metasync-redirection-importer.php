<?php

/**
 * Import redirections from other SEO plugins.
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/redirections
 * @author     Engineering Team <support@searchatlas.com>
 */

# Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Redirection_Importer
{
    private $db_redirection;

    /**
     * Supported plugins for import
     */
    const SUPPORTED_PLUGINS = [
        'redirection' => [
            'name' => 'Redirection',
            'slug' => 'redirection/redirection.php',
            'table' => 'redirection_items'
        ],
        'yoast' => [
            'name' => 'Yoast SEO Premium',
            'slugs' => [
                'wordpress-seo-premium/wp-seo-premium.php',
                'wordpress-seo-premium-main/wp-seo-premium.php',
                'wordpress-seo/wp-seo.php'
            ],
            'options' => [
                'wpseo-premium-redirects-base',
                'wpseo-premium-redirects-export-plain',
                'wpseo-premium-redirects-export-regex',
                'wpseo-premium-redirects',
                'wpseo-premium-redirects-regex'
            ]
        ],
        'rankmath' => [
            'name' => 'Rank Math',
            'slug' => 'seo-by-rank-math/rank-math.php',
            'table' => 'rank_math_redirections'
        ],
        'aioseo' => [
            'name' => 'All in One SEO',
            'slug' => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
            'table' => 'aioseo_redirects'
        ],
        'simple301' => [
            'name' => 'Simple 301 Redirects',
            'slug' => 'simple-301-redirects/simple-301-redirects.php',
            'option' => '301_redirects'
        ]
    ];

    public function __construct(&$db_redirection)
    {
        $this->db_redirection = $db_redirection;
    }

    /**
     * Get list of available plugins for import
     *
     * @return array List of plugins with their status
     */
    public function get_available_plugins()
    {
        global $wpdb;
        $available = [];

        foreach (self::SUPPORTED_PLUGINS as $key => $plugin) {
            $status = [
                'key' => $key,
                'name' => $plugin['name'],
                'installed' => false,
                'has_data' => false,
                'count' => 0
            ];

            # Check if plugin is installed/active
            if (isset($plugin['slug'])) {
                $status['installed'] = is_plugin_active($plugin['slug']) || $this->plugin_exists($plugin['slug']);
            } elseif (isset($plugin['slugs'])) {
                # Check multiple possible slugs (for Yoast)
                foreach ($plugin['slugs'] as $slug) {
                    if (is_plugin_active($slug) || $this->plugin_exists($slug)) {
                        $status['installed'] = true;
                        break;
                    }
                }
            }

            # Check if data exists
            if (isset($plugin['table'])) {
                $table_name = $wpdb->prefix . $plugin['table'];
                if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
                    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
                    if ($count > 0) {
                        $status['has_data'] = true;
                        $status['count'] = (int)$count;
                    }
                }
            } elseif (isset($plugin['option'])) {
                $option_data = get_option($plugin['option'], []);
                if (!empty($option_data)) {
                    $status['has_data'] = true;
                    $status['count'] = is_array($option_data) ? count($option_data) : 0;
                }
            } elseif (isset($plugin['options'])) {
                # Check multiple options (for Yoast)
                $total_count = 0;
                foreach ($plugin['options'] as $option_name) {
                    $option_data = get_option($option_name, []);
                    if (!empty($option_data)) {
                        $status['has_data'] = true;
                        if (is_array($option_data)) {
                            $total_count += count($option_data);
                        }
                    }
                }
                if ($total_count > 0) {
                    $status['count'] = $total_count;
                }
            }

            $available[] = $status;
        }

        return $available;
    }

    /**
     * Check if plugin exists (even if not active)
     */
    private function plugin_exists($plugin_path)
    {
        $plugin_file = WP_PLUGIN_DIR . '/' . $plugin_path;
        return file_exists($plugin_file);
    }

    /**
     * Import redirections from specified plugin
     *
     * @param string $plugin Plugin key
     * @return array Result with success status and message
     */
    public function import_from_plugin($plugin)
    {
        if (!isset(self::SUPPORTED_PLUGINS[$plugin])) {
            return [
                'success' => false,
                'message' => 'Unknown plugin specified.',
                'imported' => 0,
                'skipped' => 0
            ];
        }

        $method = "import_from_{$plugin}";
        if (method_exists($this, $method)) {
            return $this->$method();
        }

        return [
            'success' => false,
            'message' => 'Import method not implemented.',
            'imported' => 0,
            'skipped' => 0
        ];
    }

    /**
     * Import from Redirection plugin
     */
    private function import_from_redirection()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'redirection_items';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            return [
                'success' => false,
                'message' => 'Redirection plugin table not found.',
                'imported' => 0,
                'skipped' => 0
            ];
        }

        $redirects = $wpdb->get_results("
            SELECT * FROM $table_name
            WHERE status = 'enabled'
        ");

        $imported = 0;
        $skipped = 0;

        foreach ($redirects as $redirect) {
            $http_code = intval($redirect->action_code ?? 301);
            $target_url = $redirect->action_data ?? '';

            # For 410 and 451, target URL can be empty (they don't redirect)
            if (empty($target_url) && !in_array($http_code, [410, 451])) {
                $skipped++;
                continue;
            }

            # Check if already exists
            if ($this->redirection_exists($redirect->url)) {
                $skipped++;
                continue;
            }

            # Map Redirection plugin format to MetaSync format
            $pattern_type = 'exact';
            $regex_pattern = null;

            # Check if it's a regex
            if (isset($redirect->regex) && $redirect->regex == 1) {
                $pattern_type = 'regex';
                $regex_pattern = $redirect->url;
            }

            $sources = [
                $redirect->url => $pattern_type
            ];

            $args = [
                'sources_from' => serialize($sources),
                'url_redirect_to' => $target_url,
                'http_code' => $http_code,
                'hits_count' => $redirect->hits ?? 0,
                'status' => 'active',
                'pattern_type' => $pattern_type,
                'regex_pattern' => $regex_pattern,
                'description' => 'Imported from Redirection plugin',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ];

            if ($this->db_redirection->add($args)) {
                $imported++;
            }
        }

        return [
            'success' => true,
            'message' => "Successfully imported $imported redirections from Redirection plugin.",
            'imported' => $imported,
            'skipped' => $skipped
        ];
    }

    /**
     * Import from Yoast SEO Premium
     *
     * Yoast stores redirects in multiple options:
     * - wpseo-premium-redirects-base (main storage since v3.1)
     * - wpseo-premium-redirects-export-plain (plain redirects)
     * - wpseo-premium-redirects-export-regex (regex redirects)
     * - wpseo-premium-redirects (legacy plain)
     * - wpseo-premium-redirects-regex (legacy regex)
     */
    private function import_from_yoast()
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];

        # Try to import from base option first (preferred method)
        $base_redirects = get_option('wpseo-premium-redirects-base', []);

        if (!empty($base_redirects) && is_array($base_redirects)) {
            foreach ($base_redirects as $redirect) {
                $result = $this->process_yoast_redirect_base($redirect);
                if ($result === true) {
                    $imported++;
                } elseif ($result === 'skipped') {
                    $skipped++;
                } else {
                    $errors[] = $result;
                }
            }
        }

        # If no base redirects, try export options
        if ($imported === 0 && $skipped === 0) {
            # Import plain redirects
            $plain_redirects = get_option('wpseo-premium-redirects-export-plain', []);
            if (!empty($plain_redirects) && is_array($plain_redirects)) {
                foreach ($plain_redirects as $origin => $redirect_data) {
                    $result = $this->process_yoast_redirect_export($origin, $redirect_data, 'plain');
                    if ($result === true) {
                        $imported++;
                    } elseif ($result === 'skipped') {
                        $skipped++;
                    } else {
                        $errors[] = $result;
                    }
                }
            }

            # Import regex redirects
            $regex_redirects = get_option('wpseo-premium-redirects-export-regex', []);
            if (!empty($regex_redirects) && is_array($regex_redirects)) {
                foreach ($regex_redirects as $origin => $redirect_data) {
                    $result = $this->process_yoast_redirect_export($origin, $redirect_data, 'regex');
                    if ($result === true) {
                        $imported++;
                    } elseif ($result === 'skipped') {
                        $skipped++;
                    } else {
                        $errors[] = $result;
                    }
                }
            }
        }

        # If still no redirects, try legacy options
        if ($imported === 0 && $skipped === 0) {
            # Legacy plain redirects
            $legacy_plain = get_option('wpseo-premium-redirects', []);
            if (!empty($legacy_plain) && is_array($legacy_plain)) {
                foreach ($legacy_plain as $origin => $redirect_data) {
                    $result = $this->process_yoast_redirect_export($origin, $redirect_data, 'plain');
                    if ($result === true) {
                        $imported++;
                    } elseif ($result === 'skipped') {
                        $skipped++;
                    } else {
                        $errors[] = $result;
                    }
                }
            }

            # Legacy regex redirects
            $legacy_regex = get_option('wpseo-premium-redirects-regex', []);
            if (!empty($legacy_regex) && is_array($legacy_regex)) {
                foreach ($legacy_regex as $origin => $redirect_data) {
                    $result = $this->process_yoast_redirect_export($origin, $redirect_data, 'regex');
                    if ($result === true) {
                        $imported++;
                    } elseif ($result === 'skipped') {
                        $skipped++;
                    } else {
                        $errors[] = $result;
                    }
                }
            }
        }

        # Return results
        if ($imported === 0 && $skipped === 0) {
            return [
                'success' => false,
                'message' => 'No Yoast redirections found. ' . (!empty($errors) ? implode(' ', array_slice($errors, 0, 2)) : ''),
                'imported' => 0,
                'skipped' => 0,
                'errors' => $errors
            ];
        }

        return [
            'success' => true,
            'message' => $imported > 0
                ? "Successfully imported $imported redirections from Yoast SEO."
                : "All redirections already exist. Skipped $skipped duplicates.",
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }

    /**
     * Process a redirect from Yoast base option
     *
     * @param mixed $redirect Redirect data from base option
     * @return bool|string True on success, 'skipped' if exists, error message on failure
     */
    private function process_yoast_redirect_base($redirect)
    {
        try {
            # Base format: ['origin' => string, 'url' => string, 'type' => int, 'format' => string]
            if (!is_array($redirect)) {
                return 'Invalid redirect format';
            }

            $origin = isset($redirect['origin']) ? trim($redirect['origin']) : '';
            $target = isset($redirect['url']) ? trim($redirect['url']) : '';
            $type = isset($redirect['type']) ? $redirect['type'] : 301;
            $format = isset($redirect['format']) ? $redirect['format'] : 'plain';

            # Validate origin
            if (empty($origin)) {
                return 'Empty origin URL';
            }

            # For 410 and 451, target URL can be empty (they don't redirect)
            if (empty($target) && !in_array($type, [410, 451])) {
                return 'Empty target URL for non-410/451 redirect';
            }

            # Check if already exists
            if ($this->redirection_exists($origin)) {
                return 'skipped';
            }

            # Determine pattern type
            $pattern_type = ($format === 'regex') ? 'regex' : 'exact';
            $regex_pattern = ($format === 'regex') ? $origin : null;

            $sources = [
                $origin => $pattern_type
            ];

            $args = [
                'sources_from' => serialize($sources),
                'url_redirect_to' => $target,
                'http_code' => intval($type),
                'hits_count' => 0,
                'status' => 'active',
                'pattern_type' => $pattern_type,
                'regex_pattern' => $regex_pattern,
                'description' => 'Imported from Yoast SEO',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ];

            if ($this->db_redirection->add($args)) {
                return true;
            }

            return 'Failed to insert redirect';

        } catch (Exception $e) {
            return 'Exception: ' . $e->getMessage();
        }
    }

    /**
     * Process a redirect from Yoast export options
     *
     * @param string $origin Source URL
     * @param mixed $redirect_data Redirect data from export option
     * @param string $format Format type ('plain' or 'regex')
     * @return bool|string True on success, 'skipped' if exists, error message on failure
     */
    private function process_yoast_redirect_export($origin, $redirect_data, $format)
    {
        try {
            $origin = trim($origin);

            # Export format: ['url' => string, 'type' => int]
            if (is_array($redirect_data)) {
                $target = isset($redirect_data['url']) ? trim($redirect_data['url']) : '';
                $type = isset($redirect_data['type']) ? $redirect_data['type'] : 301;
            } elseif (is_string($redirect_data)) {
                # Simple string format (target URL only)
                $target = trim($redirect_data);
                $type = 301;
            } else {
                return 'Invalid redirect data format';
            }

            # Validate origin
            if (empty($origin)) {
                return 'Empty origin URL';
            }

            # For 410 and 451, target URL can be empty (they don't redirect)
            if (empty($target) && !in_array($type, [410, 451])) {
                return 'Empty target URL for non-410/451 redirect';
            }

            # Check if already exists
            if ($this->redirection_exists($origin)) {
                return 'skipped';
            }

            # Determine pattern type
            $pattern_type = ($format === 'regex') ? 'regex' : 'exact';
            $regex_pattern = ($format === 'regex') ? $origin : null;

            $sources = [
                $origin => $pattern_type
            ];

            $args = [
                'sources_from' => serialize($sources),
                'url_redirect_to' => $target,
                'http_code' => intval($type),
                'hits_count' => 0,
                'status' => 'active',
                'pattern_type' => $pattern_type,
                'regex_pattern' => $regex_pattern,
                'description' => 'Imported from Yoast SEO',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ];

            if ($this->db_redirection->add($args)) {
                return true;
            }

            return 'Failed to insert redirect';

        } catch (Exception $e) {
            return 'Exception: ' . $e->getMessage();
        }
    }

    /**
     * Import from Rank Math
     */
    private function import_from_rankmath()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rank_math_redirections';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            return [
                'success' => false,
                'message' => 'Rank Math redirections table not found.',
                'imported' => 0,
                'skipped' => 0,
                'errors' => ['Table not found: ' . $table_name]
            ];
        }

        # Get all redirections (not just active ones, Rank Math uses different status field)
        $redirects = $wpdb->get_results("SELECT * FROM $table_name");

        if (empty($redirects)) {
            return [
                'success' => false,
                'message' => 'No redirections found in Rank Math.',
                'imported' => 0,
                'skipped' => 0,
                'errors' => []
            ];
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($redirects as $redirect) {
            try {
                # Rank Math uses 'sources' field - it's a serialized PHP array
                $source_url = '';
                $comparison_type = 'exact'; // Default to exact

                if (isset($redirect->sources)) {
                    # First, try to unserialize (Rank Math format)
                    $unserialized = @maybe_unserialize($redirect->sources);

                    if (is_array($unserialized) && !empty($unserialized)) {
                        # Rank Math format: array of arrays with 'pattern', 'comparison', 'ignore' keys
                        $first_source = reset($unserialized);

                        if (is_array($first_source) && isset($first_source['pattern'])) {
                            # Extract the actual URL pattern
                            $source_url = $first_source['pattern'];

                            # Extract comparison type (exact, regex, contains, starts, ends)
                            if (isset($first_source['comparison'])) {
                                $comparison_type = $first_source['comparison'];
                            }
                        } elseif (is_string($first_source)) {
                            # Simple string format
                            $source_url = $first_source;
                        }
                    } elseif (is_string($unserialized)) {
                        # Direct string value
                        $source_url = $unserialized;
                    } else {
                        # Try as JSON
                        $parsed = json_decode($redirect->sources, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                            $source_url = is_array($parsed) ? reset($parsed) : $parsed;
                        } else {
                            # Last resort - use as-is
                            $source_url = $redirect->sources;
                        }
                    }
                } elseif (isset($redirect->url_from)) {
                    $source_url = $redirect->url_from;
                }

                # Clean up the source URL
                $source_url = trim($source_url);

                if (empty($source_url)) {
                    $errors[] = 'Empty source URL in redirect ID: ' . ($redirect->id ?? 'unknown');
                    $skipped++;
                    continue;
                }

                # Check if already exists
                if ($this->redirection_exists($source_url)) {
                    $skipped++;
                    continue;
                }

                # Map Rank Math comparison types to MetaSync pattern types
                # Rank Math: exact, regex, contains, starts, ends
                # MetaSync: exact, regex, contain, start, end
                $pattern_type_map = [
                    'exact' => 'exact',
                    'regex' => 'regex',
                    'contains' => 'contain',
                    'starts' => 'start',
                    'ends' => 'end'
                ];

                $pattern_type = isset($pattern_type_map[$comparison_type])
                    ? $pattern_type_map[$comparison_type]
                    : 'exact';

                # For regex patterns, store the pattern
                $regex_pattern = ($pattern_type === 'regex') ? $source_url : null;

                $sources = [
                    $source_url => $pattern_type
                ];

                $target_url = $redirect->url_to ?? '';
                $http_code = intval($redirect->header_code ?? 301);

                # For 410 and 451, target URL can be empty (they don't redirect)
                if (empty($target_url) && !in_array($http_code, [410, 451])) {
                    $errors[] = 'Empty target URL for source: ' . $source_url;
                    $skipped++;
                    continue;
                }

                $args = [
                    'sources_from' => serialize($sources),
                    'url_redirect_to' => $target_url,
                    'http_code' => $http_code,
                    'hits_count' => $redirect->hits ?? 0,
                    'status' => 'active',
                    'pattern_type' => $pattern_type,
                    'regex_pattern' => $regex_pattern,
                    'description' => 'Imported from Rank Math',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ];

                $result = $this->db_redirection->add($args);

                if ($result !== false && $result > 0) {
                    $imported++;
                } else {
                    $errors[] = 'Failed to insert redirect: ' . $source_url . ' -> ' . $target_url;
                    if ($wpdb->last_error) {
                        $errors[] = 'DB Error: ' . $wpdb->last_error;
                    }
                }

            } catch (Exception $e) {
                $errors[] = 'Exception: ' . $e->getMessage();
                $skipped++;
            }
        }

        # Return success if we processed redirections (even if all skipped)
        $has_results = ($imported + $skipped) > 0;

        return [
            'success' => $has_results,
            'message' => $imported > 0
                ? "Successfully imported $imported redirections from Rank Math."
                : ($skipped > 0
                    ? "All redirections already exist. Skipped $skipped duplicates."
                    : "No redirections found to import. " . (count($errors) > 0 ? implode(' ', array_slice($errors, 0, 2)) : '')),
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }

    /**
     * Import from All in One SEO
     */
    private function import_from_aioseo()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aioseo_redirects';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            return [
                'success' => false,
                'message' => 'All in One SEO redirects table not found.',
                'imported' => 0,
                'skipped' => 0
            ];
        }

        $redirects = $wpdb->get_results("
            SELECT * FROM $table_name
            WHERE enabled = 1
        ");

        $imported = 0;
        $skipped = 0;

        foreach ($redirects as $redirect) {
            $http_code = intval($redirect->redirect_code ?? 301);
            $target_url = $redirect->target_url ?? '';

            # For 410 and 451, target URL can be empty (they don't redirect)
            if (empty($target_url) && !in_array($http_code, [410, 451])) {
                $skipped++;
                continue;
            }

            # Check if already exists
            if ($this->redirection_exists($redirect->source_url)) {
                $skipped++;
                continue;
            }

            # Determine pattern type
            $pattern_type = 'exact';
            $regex_pattern = null;

            if (isset($redirect->regex) && $redirect->regex == 1) {
                $pattern_type = 'regex';
                $regex_pattern = $redirect->source_url;
            }

            $sources = [
                $redirect->source_url => $pattern_type
            ];

            $args = [
                'sources_from' => serialize($sources),
                'url_redirect_to' => $target_url,
                'http_code' => $http_code,
                'hits_count' => $redirect->hits ?? 0,
                'status' => 'active',
                'pattern_type' => $pattern_type,
                'regex_pattern' => $regex_pattern,
                'description' => 'Imported from All in One SEO',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ];

            if ($this->db_redirection->add($args)) {
                $imported++;
            }
        }

        return [
            'success' => true,
            'message' => "Successfully imported $imported redirections from All in One SEO.",
            'imported' => $imported,
            'skipped' => $skipped
        ];
    }

    /**
     * Import from Simple 301 Redirects
     */
    private function import_from_simple301()
    {
        # Simple 301 Redirects stores in options
        $redirects = get_option('301_redirects', []);

        if (empty($redirects)) {
            return [
                'success' => false,
                'message' => 'No Simple 301 Redirects found.',
                'imported' => 0,
                'skipped' => 0
            ];
        }

        $imported = 0;
        $skipped = 0;

        foreach ($redirects as $old_url => $new_url) {
            # Skip empty entries
            if (empty($old_url) || empty($new_url)) {
                $skipped++;
                continue;
            }

            # Check if already exists
            if ($this->redirection_exists($old_url)) {
                $skipped++;
                continue;
            }

            $sources = [
                $old_url => 'exact'
            ];

            $args = [
                'sources_from' => serialize($sources),
                'url_redirect_to' => $new_url,
                'http_code' => 301,
                'hits_count' => 0,
                'status' => 'active',
                'pattern_type' => 'exact',
                'regex_pattern' => null,
                'description' => 'Imported from Simple 301 Redirects',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ];

            if ($this->db_redirection->add($args)) {
                $imported++;
            }
        }

        return [
            'success' => true,
            'message' => "Successfully imported $imported redirections from Simple 301 Redirects.",
            'imported' => $imported,
            'skipped' => $skipped
        ];
    }

    /**
     * Check if a redirection already exists
     *
     * @param string $source_url Source URL to check
     * @return bool True if exists, false otherwise
     */
    private function redirection_exists($source_url)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . Metasync_Redirection_Database::$table_name;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE sources_from LIKE %s",
            '%' . $wpdb->esc_like($source_url) . '%'
        ));

        return $count > 0;
    }

    /**
     * Get import statistics
     *
     * @return array Statistics about imports
     */
    public function get_import_stats()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . Metasync_Redirection_Database::$table_name;

        $stats = [
            'total_redirections' => 0,
            'imported_redirections' => 0
        ];

        $stats['total_redirections'] = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

        $stats['imported_redirections'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE description LIKE %s",
            'Imported from%'
        ));

        return $stats;
    }
}

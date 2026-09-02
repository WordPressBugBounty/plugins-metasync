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

if (!class_exists('Metasync_Redirection_Validator')) {
    require_once dirname(__FILE__) . '/class-metasync-redirection-validator.php';
}

class Metasync_Redirection_Importer
{
    /**
     * Maximum rows accepted from one CSV import. A malformed or oversized
     * export must not turn into an unbounded loop or a memory blow-up.
     */
    const CSV_MAX_ROWS = 20000;

    private $db_redirection;

    private $redirection_helper = null;

    /**
     * @var array<string, true>|null Memoized exact-key index of stored sources.
     */
    private $source_index_cache = null;

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
     * Lazy-load the Metasync_Redirection helper used for loop detection.
     */
    private function get_redirection_helper()
    {
        if ($this->redirection_helper === null) {
            require_once dirname(__FILE__) . '/class-metasync-redirection.php';
            $this->redirection_helper = new Metasync_Redirection($this->db_redirection);
        }
        return $this->redirection_helper;
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
                'skipped' => 0,
                'loop_skipped' => 0
            ];
        }

        $redirects = $wpdb->get_results("
            SELECT * FROM $table_name
            WHERE status = 'enabled'
        ");

        $imported = 0;
        $skipped = 0;
        $loop_skipped = 0;
        $parse_skipped = 0;
        $unsafe_skipped = 0;
        $conditional_skipped = 0;

        # Exact-key index of every source already stored. One SELECT plus one
        # unserialize pass replaces the per-row LIKE rescan (quadratic on big
        # tables) and stops substring false-positives (/a "matching" /about).
        $existing_sources = $this->get_existing_source_index();

        foreach ($redirects as $redirect) {
            # Conditional redirects (query/referrer/agent/… matches) only fire
            # under conditions MetaSync cannot represent. Importing them as
            # unconditional redirects would silently change their semantics.
            if (isset($redirect->match_type) && $redirect->match_type !== '' && $redirect->match_type !== 'url') {
                $conditional_skipped++;
                continue;
            }

            $http_code = intval($redirect->action_code ?? 301);

            # action_data is a JSON envelope ('{"url":"…"}'), never a plain URL.
            # Storing it raw produced redirects to a literal '{"url":…}' string.
            $target_url = $this->extract_destination($redirect->action_data ?? '');

            # For 410 and 451, target URL can be empty (they don't redirect)
            if ($target_url === null || ($target_url === '' && !in_array($http_code, [410, 451]))) {
                $parse_skipped++;
                continue;
            }

            # Check if already exists (exact source-key match)
            if (isset($existing_sources[$redirect->url])) {
                $skipped++;
                continue;
            }

            # Map Redirection plugin format to MetaSync format
            $pattern_type = 'exact';
            $regex_pattern = null;

            # Check if it's a regex. Foreign patterns arrive unvalidated —
            # screen them with the same ReDoS guard the admin form applies.
            if (isset($redirect->regex) && $redirect->regex == 1) {
                if (!Metasync_Redirection_Validator::is_regex_safe((string) $redirect->url)) {
                    $parse_skipped++;
                    continue;
                }
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

            if (!$this->is_safe_destination($target_url, $http_code)) {
                $unsafe_skipped++;
                continue;
            }
            $args['url_redirect_to'] = $target_url;

            if (!in_array($http_code, [410, 451]) && $this->get_redirection_helper()->validate_no_loop($redirect->url, $target_url) !== null) {
                $loop_skipped++;
                continue;
            }

            if ($this->db_redirection->add($args)) {
                $imported++;
                $existing_sources[$redirect->url] = true;
                $this->remember_imported_source($redirect->url);
            }
        }

        $message = "Successfully imported $imported redirections from Redirection plugin.";
        if ($skipped > 0) {
            $message .= " Skipped $skipped already-existing redirection(s).";
        }
        if ($parse_skipped > 0) {
            $message .= " Skipped $parse_skipped redirection(s) with an unreadable destination.";
        }
        if ($unsafe_skipped > 0) {
            $message .= " Skipped $unsafe_skipped redirection(s) with an off-site destination.";
        }
        if ($conditional_skipped > 0) {
            $message .= " Skipped $conditional_skipped conditional redirection(s); conditions are not supported.";
        }
        if ($loop_skipped > 0) {
            $message .= " Skipped $loop_skipped redirect(s) that would have created loops.";
        }

        return [
            'success' => true,
            'message' => $message,
            'imported' => $imported,
            'skipped' => $skipped,
            'loop_skipped' => $loop_skipped,
            'skipped_parse' => $parse_skipped,
            'skipped_unsafe' => $unsafe_skipped,
            'skipped_conditional' => $conditional_skipped
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
        $loop_skipped = 0;

        # Try to import from base option first (preferred method)
        $base_redirects = get_option('wpseo-premium-redirects-base', []);

        if (!empty($base_redirects) && is_array($base_redirects)) {
            foreach ($base_redirects as $redirect) {
                $result = $this->process_yoast_redirect_base($redirect);
                if ($result === true) {
                    $imported++;
                } elseif ($result === 'skipped') {
                    $skipped++;
                } elseif ($result === 'loop_skipped') {
                    $loop_skipped++;
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
                    } elseif ($result === 'loop_skipped') {
                        $loop_skipped++;
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
                    } elseif ($result === 'loop_skipped') {
                        $loop_skipped++;
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
                    } elseif ($result === 'loop_skipped') {
                        $loop_skipped++;
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
                    } elseif ($result === 'loop_skipped') {
                        $loop_skipped++;
                    } else {
                        $errors[] = $result;
                    }
                }
            }
        }

        # Return results
        if ($imported === 0 && $skipped === 0 && $loop_skipped === 0) {
            return [
                'success' => false,
                'message' => 'No Yoast redirections found. ' . (!empty($errors) ? implode(' ', array_slice($errors, 0, 2)) : ''),
                'imported' => 0,
                'skipped' => 0,
                'loop_skipped' => 0,
                'errors' => $errors
            ];
        }

        $message = $imported > 0
            ? "Successfully imported $imported redirections from Yoast SEO."
            : "All redirections already exist. Skipped $skipped duplicates.";
        if ($loop_skipped > 0) {
            $message .= " Skipped $loop_skipped redirect(s) that would have created loops.";
        }

        return [
            'success' => true,
            'message' => $message,
            'imported' => $imported,
            'skipped' => $skipped,
            'loop_skipped' => $loop_skipped,
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

            # Determine pattern type. Foreign regex patterns get the same
            # ReDoS screen the admin form applies.
            if ($format === 'regex' && !Metasync_Redirection_Validator::is_regex_safe($origin)) {
                return 'skipped';
            }
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

            if (!$this->is_safe_destination($target, intval($type))) {
                return 'skipped';
            }
            $args['url_redirect_to'] = $target;

            if (!in_array(intval($type), [410, 451]) && $this->get_redirection_helper()->validate_no_loop($origin, $target) !== null) {
                return 'loop_skipped';
            }

            if ($this->db_redirection->add($args)) {
                $this->remember_imported_source($origin);
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

            # Determine pattern type. Foreign regex patterns get the same
            # ReDoS screen the admin form applies.
            if ($format === 'regex' && !Metasync_Redirection_Validator::is_regex_safe($origin)) {
                return 'skipped';
            }
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

            if (!$this->is_safe_destination($target, intval($type))) {
                return 'skipped';
            }
            $args['url_redirect_to'] = $target;

            if (!in_array(intval($type), [410, 451]) && $this->get_redirection_helper()->validate_no_loop($origin, $target) !== null) {
                return 'loop_skipped';
            }

            if ($this->db_redirection->add($args)) {
                $this->remember_imported_source($origin);
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
                'loop_skipped' => 0,
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
                'loop_skipped' => 0,
                'errors' => []
            ];
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $loop_skipped = 0;
        $parse_skipped = 0;
        $unsafe_skipped = 0;
        $inactive_skipped = 0;

        # Exact-key index of every source already stored — one pass instead of a
        # per-row LIKE rescan, with no substring false-positives.
        $existing_sources = $this->get_existing_source_index();

        foreach ($redirects as $redirect) {
            try {
                # Rank Math keeps disabled redirects in the same table; only
                # enabled rows must come across.
                if (isset($redirect->status) && !in_array((string) $redirect->status, ['active', 'enabled', '1', ''], true)) {
                    $inactive_skipped++;
                    continue;
                }

                # Rank Math uses 'sources' field - it's a serialized PHP array of
                # ['pattern' => …, 'comparison' => …] entries. Keep ALL of them:
                # taking only the first silently dropped every extra source.
                $row_sources = [];

                if (isset($redirect->sources)) {
                    $unserialized = @maybe_unserialize($redirect->sources);

                    if (!is_array($unserialized) || empty($unserialized)) {
                        # Try as JSON before giving up on the blob
                        $parsed = json_decode((string) $redirect->sources, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed) && !empty($parsed)) {
                            $unserialized = $parsed;
                        }
                    }

                    if (is_array($unserialized) && !empty($unserialized)) {
                        foreach ($unserialized as $source_entry) {
                            if (is_array($source_entry)) {
                                if (empty($source_entry['pattern']) || !is_string($source_entry['pattern'])) {
                                    continue;
                                }
                                $row_sources[trim($source_entry['pattern'])] = isset($source_entry['comparison'])
                                    ? (string) $source_entry['comparison']
                                    : 'exact';
                            } elseif (is_string($source_entry) && trim($source_entry) !== '') {
                                $row_sources[trim($source_entry)] = 'exact';
                            }
                        }
                    } elseif (is_string($unserialized) && trim($unserialized) !== '') {
                        # Direct string value
                        $row_sources[trim($unserialized)] = 'exact';
                    }
                }

                if (empty($row_sources) && isset($redirect->url_from) && trim((string) $redirect->url_from) !== '') {
                    $row_sources[trim((string) $redirect->url_from)] = 'exact';
                }

                if (empty($row_sources)) {
                    $errors[] = 'Empty source URL in redirect ID: ' . ($redirect->id ?? 'unknown');
                    $parse_skipped++;
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

                # Drop sources that already exist; if every source is a duplicate
                # the whole row is one, otherwise import the remainder.
                $sources = [];
                foreach ($row_sources as $source_pattern => $comparison_type) {
                    if (isset($existing_sources[$source_pattern])) {
                        continue;
                    }
                    $sources[$source_pattern] = isset($pattern_type_map[$comparison_type])
                        ? $pattern_type_map[$comparison_type]
                        : 'exact';
                }

                if (empty($sources)) {
                    $skipped++;
                    continue;
                }

                # Row-level pattern type only matters when there is a single source;
                # with several, per-source types in the serialized map drive matching.
                $pattern_type = count($sources) === 1 ? reset($sources) : 'exact';

                # For regex patterns, store the first regex source as the pattern
                $regex_pattern = null;
                if ($pattern_type === 'regex') {
                    $regex_pattern = (string) key($sources);
                    if (!Metasync_Redirection_Validator::is_regex_safe($regex_pattern)) {
                        $errors[] = 'Skipped unsafe regex pattern: ' . $regex_pattern;
                        $parse_skipped++;
                        continue;
                    }
                }

                $source_url = (string) key($sources);

                $target_url = $redirect->url_to ?? '';
                $http_code = intval($redirect->header_code ?? 301);

                # For 410 and 451, target URL can be empty (they don't redirect)
                if (empty($target_url) && !in_array($http_code, [410, 451])) {
                    $errors[] = 'Empty target URL for source: ' . $source_url;
                    $parse_skipped++;
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

                if (!$this->is_safe_destination($target_url, $http_code)) {
                    $unsafe_skipped++;
                    continue;
                }
                $args['url_redirect_to'] = $target_url;

                if (!in_array($http_code, [410, 451])) {
                    foreach (array_keys($sources) as $loop_source) {
                        if ($this->get_redirection_helper()->validate_no_loop($loop_source, $target_url) !== null) {
                            $loop_skipped++;
                            continue 2;
                        }
                    }
                }

                $result = $this->db_redirection->add($args);

                if ($result !== false && $result > 0) {
                    $imported++;
                    foreach (array_keys($sources) as $imported_source) {
                        $existing_sources[$imported_source] = true;
                        $this->remember_imported_source($imported_source);
                    }
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
        $has_results = ($imported + $skipped + $loop_skipped + $parse_skipped + $unsafe_skipped + $inactive_skipped) > 0;

        $message = $imported > 0
            ? "Successfully imported $imported redirections from Rank Math."
            : ($skipped > 0
                ? "All redirections already exist. Skipped $skipped duplicates."
                : "No redirections found to import. " . (count($errors) > 0 ? implode(' ', array_slice($errors, 0, 2)) : ''));
        if ($inactive_skipped > 0) {
            $message .= " Skipped $inactive_skipped disabled redirection(s).";
        }
        if ($parse_skipped > 0) {
            $message .= " Skipped $parse_skipped redirection(s) with unreadable source or destination.";
        }
        if ($unsafe_skipped > 0) {
            $message .= " Skipped $unsafe_skipped redirection(s) with an off-site destination.";
        }
        if ($loop_skipped > 0) {
            $message .= " Skipped $loop_skipped redirect(s) that would have created loops.";
        }

        return [
            'success' => $has_results,
            'message' => $message,
            'imported' => $imported,
            'skipped' => $skipped,
            'loop_skipped' => $loop_skipped,
            'skipped_parse' => $parse_skipped,
            'skipped_unsafe' => $unsafe_skipped,
            'skipped_inactive' => $inactive_skipped,
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
                'skipped' => 0,
                'loop_skipped' => 0
            ];
        }

        $redirects = $wpdb->get_results("
            SELECT * FROM $table_name
            WHERE enabled = 1
        ");

        $imported = 0;
        $skipped = 0;
        $loop_skipped = 0;
        $parse_skipped = 0;

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
                if (!Metasync_Redirection_Validator::is_regex_safe((string) $redirect->source_url)) {
                    $parse_skipped++;
                    continue;
                }
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

            if (!$this->is_safe_destination($target_url, $http_code)) {
                $skipped++;
                continue;
            }
            $args['url_redirect_to'] = $target_url;

            if (!in_array($http_code, [410, 451]) && $this->get_redirection_helper()->validate_no_loop($redirect->source_url, $target_url) !== null) {
                $loop_skipped++;
                continue;
            }

            if ($this->db_redirection->add($args)) {
                $imported++;
                $this->remember_imported_source($redirect->source_url);
            }
        }

        $message = "Successfully imported $imported redirections from All in One SEO.";
        if ($parse_skipped > 0) {
            $message .= " Skipped $parse_skipped redirection(s) with an unreadable source or destination.";
        }
        if ($loop_skipped > 0) {
            $message .= " Skipped $loop_skipped redirect(s) that would have created loops.";
        }

        return [
            'success' => true,
            'message' => $message,
            'imported' => $imported,
            'skipped' => $skipped,
            'loop_skipped' => $loop_skipped,
            'skipped_parse' => $parse_skipped
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
                'skipped' => 0,
                'loop_skipped' => 0
            ];
        }

        $imported = 0;
        $skipped = 0;
        $loop_skipped = 0;

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

            if (!$this->is_safe_destination($new_url, 301)) {
                $skipped++;
                continue;
            }
            $args['url_redirect_to'] = $new_url;

            if ($this->get_redirection_helper()->validate_no_loop($old_url, $new_url) !== null) {
                $loop_skipped++;
                continue;
            }

            if ($this->db_redirection->add($args)) {
                $imported++;
                $this->remember_imported_source($old_url);
            }
        }

        $message = "Successfully imported $imported redirections from Simple 301 Redirects.";
        if ($loop_skipped > 0) {
            $message .= " Skipped $loop_skipped redirect(s) that would have created loops.";
        }

        return [
            'success' => true,
            'message' => $message,
            'imported' => $imported,
            'skipped' => $skipped,
            'loop_skipped' => $loop_skipped
        ];
    }

    /**
     * Reject off-site destination URLs at import time so attacker-controlled
     * exports cannot smuggle external redirects in via plugin migration.
     *
     * 410 and 451 entries have no destination, so they are always safe.
     *
     * @param string $url       Destination URL from the source plugin.
     * @param int    $http_code HTTP status the redirect will serve.
     * @return bool True when the destination is empty-by-design or resolves on-site.
     */
    private function is_safe_destination(&$url, $http_code)
    {
        if (in_array(intval($http_code), [410, 451], true)) {
            $url = '';
            return true;
        }

        $url = (string) $url;
        if ($url === '') {
            return false;
        }

        # Backslashes never belong in a URL, but browsers read them as path
        # separators: '/\evil.com' slips past wp_validate_redirect (parse_url
        # sees no host) and still navigates off-site. Reject outright.
        if (!Metasync_Redirection_Validator::is_safe_destination_syntax($url)) {
            return false;
        }

        if (get_option('metasync_allow_external_redirects', 0)) {
            return true;
        }

        return wp_validate_redirect($url, '') !== '';
    }

    /**
     * Extract the destination URL from the Redirection plugin's action_data column.
     *
     * action_data is normally a JSON envelope ('{"url":"https://…"}'); older rows
     * and other action types store plain strings, arrays or serialized values.
     * Storing the raw envelope as the destination makes the redirect point at a
     * literal '{"url":…}' string, so parse the envelope and only accept a URL.
     *
     * @param mixed $action_data Raw action_data value from the source table.
     * @return string|null Destination URL ('' when absent), or null when unparseable.
     */
    private function extract_destination($action_data)
    {
        if ($action_data === null || $action_data === '') {
            return '';
        }

        $value = $action_data;

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return '';
            }
            if ($trimmed[0] === '{' || $trimmed[0] === '[') {
                $decoded = json_decode($trimmed, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return null;
                }
                $value = $decoded;
            } elseif (str_starts_with($trimmed, 'a:') || str_starts_with($trimmed, 's:')) {
                $unserialized = @unserialize($trimmed, ['allowed_classes' => false]);
                $value = ($unserialized !== false || $trimmed === 'b:0;') ? $unserialized : null;
            } else {
                return $trimmed;
            }
        }

        if (is_array($value)) {
            if (isset($value['url']) && is_string($value['url'])) {
                return trim($value['url']);
            }
            $first = reset($value);
            if (is_array($first) && isset($first['url']) && is_string($first['url'])) {
                return trim($first['url']);
            }
            if (is_string($first)) {
                return trim($first);
            }
            return null;
        }

        return is_string($value) ? trim($value) : null;
    }

    /**
     * Build an exact-key index of every source URL already stored in the
     * redirections table.
     *
     * One SELECT plus one unserialize pass replaces the previous per-row
     * `sources_from LIKE '%…%'` lookup, which rescanned the whole table for
     * every imported row (O(N²) on large tables) and matched substrings —
     * importing /a reported the existing /about as a duplicate.
     *
     * Legacy rows that predate keyed sources store a numeric list; for those
     * the VALUE is the source URL.
     *
     * @return array<string, true> Source URL/path keys.
     */
    private function get_existing_source_index()
    {
        if ($this->source_index_cache !== null) {
            return $this->source_index_cache;
        }

        $index = [];
        $rows = $this->db_redirection->getAllRecords();
        if (empty($rows)) {
            return $index;
        }

        foreach ($rows as $row) {
            $sources = !empty($row->sources_from)
                ? @unserialize($row->sources_from, ['allowed_classes' => false])
                : [];
            if (!is_array($sources)) {
                continue;
            }
            foreach ($sources as $source_key => $source_value) {
                // Numeric keys mark legacy list rows; the value is the URL there.
                $key = is_int($source_key) ? (string) $source_value : (string) $source_key;
                if ($key !== '') {
                    $index[$key] = true;
                }
            }
        }

        $this->source_index_cache = $index;

        return $index;
    }

    /**
     * Record a source imported during this request so the memoized index —
     * and with it every later duplicate check — knows about it.
     *
     * @param string $source Source URL/path just stored.
     */
    private function remember_imported_source($source)
    {
        $source = (string) $source;
        if ($source !== '' && $this->source_index_cache !== null) {
            $this->source_index_cache[$source] = true;
        }
    }

    /**
     * Import redirections from an uploaded CSV file.
     *
     * The readme has advertised ".csv file" import since the importer UI
     * shipped; this is the implementation. Expected columns per row:
     * source, destination, http code (optional, 301 by default). A header
     * row is detected and skipped, and every row passes the same dedup,
     * off-site, and loop guards the plugin imports use.
     *
     * @param string $file_path Path to the uploaded CSV temp file.
     * @return array Result with success status, message, and counters.
     */
    public function import_csv_file($file_path)
    {
        $handle = @fopen($file_path, 'r');

        if (!$handle) {
            return [
                'success' => false,
                'message' => 'Could not read the uploaded CSV file.',
                'imported' => 0,
                'skipped' => 0,
                'loop_skipped' => 0
            ];
        }

        $imported = 0;
        $skipped = 0;
        $parse_skipped = 0;
        $unsafe_skipped = 0;
        $loop_skipped = 0;
        $row_index = 0;
        $truncated = false;

        $allowed_codes = [301, 302, 307, 410, 451];
        $existing_sources = $this->get_existing_source_index();

        while (($raw = fgets($handle)) !== false) {
            # Bound the run: an oversized or malformed export must not turn
            # into an unbounded loop over the temp file.
            if ($row_index >= self::CSV_MAX_ROWS) {
                $truncated = true;
                break;
            }

            $row = str_getcsv($raw, ',', '"', '\\');

            # Header row ("source,destination,...") — skip it once.
            if ($row_index === 0 && isset($row[0]) && in_array(strtolower(trim((string) $row[0])), ['source', 'origin', 'from'], true)) {
                $row_index++;
                continue;
            }

            $row_index++;

            # str_getcsv() always returns an array, so a short row is the only
            # unusable shape to screen for here.
            if (count($row) < 2) {
                if (trim((string) $raw) !== '') {
                    $parse_skipped++;
                }
                continue;
            }

            $source = trim((string) $row[0]);
            $target = trim((string) $row[1]);
            $http_code = isset($row[2]) && trim((string) $row[2]) !== '' ? (int) trim((string) $row[2]) : 301;

            if ($source === '' || !in_array($http_code, $allowed_codes, true) || ($target === '' && !in_array($http_code, [410, 451], true))) {
                $parse_skipped++;
                continue;
            }

            # Skip rows whose source already has a rule.
            if (isset($existing_sources[(string) $source])) {
                $skipped++;
                continue;
            }

            $sources = [
                $source => 'exact'
            ];

            $args = [
                'sources_from' => serialize($sources),
                'url_redirect_to' => $target,
                'http_code' => $http_code,
                'hits_count' => 0,
                'status' => 'active',
                'pattern_type' => 'exact',
                'regex_pattern' => null,
                'description' => 'Imported from CSV file',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ];

            if (!$this->is_safe_destination($target, $http_code)) {
                $unsafe_skipped++;
                continue;
            }
            $args['url_redirect_to'] = $target;

            if (!in_array($http_code, [410, 451], true) && $this->get_redirection_helper()->validate_no_loop($source, $target) !== null) {
                $loop_skipped++;
                continue;
            }

            if ($this->db_redirection->add($args)) {
                $imported++;
                $existing_sources[(string) $source] = true;
                $this->remember_imported_source($source);
            }
        }
        fclose($handle);

        if ($imported === 0 && $skipped === 0 && $parse_skipped === 0 && $unsafe_skipped === 0 && $loop_skipped === 0) {
            return [
                'success' => false,
                'message' => 'No redirections found in the CSV file. Expected columns: source, destination, http code (optional).',
                'imported' => 0,
                'skipped' => 0,
                'loop_skipped' => 0
            ];
        }

        $message = "Successfully imported $imported redirections from CSV.";
        if ($skipped > 0) {
            $message .= " Skipped $skipped already-existing redirection(s).";
        }
        if ($parse_skipped > 0) {
            $message .= " Skipped $parse_skipped invalid redirection(s).";
        }
        if ($unsafe_skipped > 0) {
            $message .= " Skipped $unsafe_skipped redirection(s) with an off-site destination.";
        }
        if ($loop_skipped > 0) {
            $message .= " Skipped $loop_skipped redirect(s) that would have created loops.";
        }
        if ($truncated) {
            $message .= ' Import stopped after ' . self::CSV_MAX_ROWS . ' rows; split larger files and import them separately.';
        }

        return [
            'success' => true,
            'message' => $message,
            'imported' => $imported,
            'skipped' => $skipped,
            'loop_skipped' => $loop_skipped,
            'skipped_parse' => $parse_skipped,
            'skipped_unsafe' => $unsafe_skipped,
            'truncated' => $truncated
        ];
    }

    /**
     * Check whether a redirection already exists for a source.
     *
     * Exact key match against the memoized index; never a substring test,
     * so importing /a no longer "matches" an existing /about.
     *
     * @param string $source_url Source URL to check.
     * @return bool True if a redirect already stores this exact source.
     */
    private function redirection_exists($source_url)
    {
        $index = $this->get_existing_source_index();
        return isset($index[(string) $source_url]);
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

<?php
/**
 * MCP Tools for WordPress System Information & Diagnostics
 *
 * Provides tools for AI-assisted code analysis, plugin auditing,
 * cron inspection, and site-wide diagnostics.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(dirname(__FILE__)) . 'class-mcp-tool-base.php';

/**
 * System Diagnostics Tool
 *
 * Returns a full system snapshot: WordPress, PHP, server, DB, active
 * plugins, active theme, and cron summary — in one call.
 */
class MCP_Tool_System_Diagnostics extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_system_diagnostics';
    }

    public function get_description() {
        return 'Get a full system snapshot: WordPress version, PHP/server config, database info, active plugins, active theme, and WP-Cron summary. Use this as the starting point for any site-wide analysis.';
    }

    public function get_input_schema() {
        return [
            'type'       => 'object',
            'properties' => (object)[],
        ];
    }

    public function execute($params) {
        $this->require_capability('manage_options');

        global $wpdb;

        // ── WordPress ────────────────────────────────────────────────
        $wp = [
            'version'          => get_bloginfo('version'),
            'multisite'        => is_multisite(),
            'site_url'         => get_site_url(),
            'home_url'         => get_home_url(),
            'wp_debug'         => defined('WP_DEBUG') && WP_DEBUG,
            'wp_debug_log'     => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
            'wp_debug_display' => defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY,
            'script_debug'     => defined('SCRIPT_DEBUG') && SCRIPT_DEBUG,
            'memory_limit'     => defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : ini_get('memory_limit'),
            'max_memory_limit' => defined('WP_MAX_MEMORY_LIMIT') ? WP_MAX_MEMORY_LIMIT : 'N/A',
            'abspath'          => ABSPATH,
            'content_dir'      => WP_CONTENT_DIR,
            'uploads_dir'      => wp_upload_dir()['basedir'],
            'permalink_structure' => get_option('permalink_structure') ?: '(plain)',
            'timezone'         => wp_timezone_string(),
            'language'         => get_locale(),
        ];

        // ── PHP ──────────────────────────────────────────────────────
        $php = [
            'version'            => PHP_VERSION,
            'memory_limit'       => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize'=> ini_get('upload_max_filesize'),
            'post_max_size'      => ini_get('post_max_size'),
            'max_input_vars'     => ini_get('max_input_vars'),
            'display_errors'     => ini_get('display_errors'),
            'extensions'         => array_values(array_intersect(
                ['curl', 'gd', 'imagick', 'mbstring', 'openssl', 'xml', 'zip', 'intl', 'exif'],
                get_loaded_extensions()
            )),
        ];

        // ── Server ───────────────────────────────────────────────────
        $server = [
            'software'   => isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field($_SERVER['SERVER_SOFTWARE']) : 'unknown',
            'os'         => PHP_OS,
            'sapi'       => PHP_SAPI,
            'https'      => is_ssl(),
        ];

        // ── Database ─────────────────────────────────────────────────
        $db_version = $wpdb->get_var('SELECT VERSION()');
        $db = [
            'mysql_version' => $db_version,
            'db_name'       => defined('DB_NAME') ? DB_NAME : 'N/A',
            'db_host'       => defined('DB_HOST') ? DB_HOST : 'N/A',
            'db_charset'    => defined('DB_CHARSET') ? DB_CHARSET : 'N/A',
            'table_prefix'  => $wpdb->prefix,
            'table_count'   => (int) $wpdb->get_var(
                $wpdb->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s', DB_NAME)
            ),
        ];

        // ── Active Plugins ───────────────────────────────────────────
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins    = get_plugins();
        $active_slugs   = (array) get_option('active_plugins', []);
        $active_plugins = [];
        foreach ($active_slugs as $slug) {
            if (isset($all_plugins[$slug])) {
                $p = $all_plugins[$slug];
                $active_plugins[] = [
                    'slug'    => $slug,
                    'name'    => $p['Name'],
                    'version' => $p['Version'],
                    'author'  => $p['Author'],
                ];
            }
        }

        // ── Active Theme ─────────────────────────────────────────────
        $theme = wp_get_theme();
        $theme_data = [
            'name'       => $theme->get('Name'),
            'version'    => $theme->get('Version'),
            'author'     => $theme->get('Author'),
            'template'   => $theme->get_template(),
            'stylesheet' => $theme->get_stylesheet(),
            'parent'     => $theme->parent() ? $theme->parent()->get('Name') : null,
        ];

        // ── WP-Cron summary ──────────────────────────────────────────
        $cron_events = _get_cron_array();
        $cron_count  = 0;
        $cron_hooks  = [];
        if (is_array($cron_events)) {
            foreach ($cron_events as $timestamp => $hooks) {
                foreach ($hooks as $hook => $jobs) {
                    $cron_count += count($jobs);
                    $cron_hooks[] = $hook;
                }
            }
        }
        $cron_summary = [
            'total_events'   => $cron_count,
            'unique_hooks'   => count(array_unique($cron_hooks)),
            'hooks_preview'  => array_slice(array_unique($cron_hooks), 0, 20),
        ];

        return $this->success([
            'wordpress'      => $wp,
            'php'            => $php,
            'server'         => $server,
            'database'       => $db,
            'active_plugins' => $active_plugins,
            'active_theme'   => $theme_data,
            'cron_summary'   => $cron_summary,
        ], 'System diagnostics retrieved successfully');
    }
}

/**
 * List All Plugins Tool
 *
 * Returns all installed plugins (active and inactive) with full metadata.
 */
class MCP_Tool_List_All_Plugins extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_list_plugins';
    }

    public function get_description() {
        return 'List all installed WordPress plugins (active and inactive) with name, version, author, description, and active status. Useful for auditing dependencies and finding outdated or vulnerable plugins.';
    }

    public function get_input_schema() {
        return [
            'type'       => 'object',
            'properties' => [
                'status' => [
                    'type'        => 'string',
                    'enum'        => ['all', 'active', 'inactive'],
                    'description' => 'Filter by active status (default: all)',
                ],
            ],
        ];
    }

    public function execute($params) {
        $this->require_capability('manage_options');

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $filter       = isset($params['status']) ? $params['status'] : 'all';
        $all_plugins  = get_plugins();
        $active_slugs = (array) get_option('active_plugins', []);
        $result       = [];

        foreach ($all_plugins as $slug => $p) {
            $is_active = in_array($slug, $active_slugs, true);

            if ($filter === 'active'   && !$is_active) continue;
            if ($filter === 'inactive' &&  $is_active) continue;

            $result[] = [
                'slug'        => $slug,
                'name'        => $p['Name'],
                'version'     => $p['Version'],
                'author'      => wp_strip_all_tags($p['Author']),
                'description' => wp_strip_all_tags($p['Description']),
                'plugin_uri'  => $p['PluginURI'],
                'text_domain' => $p['TextDomain'],
                'requires_wp' => $p['RequiresWP'] ?? '',
                'requires_php'=> $p['RequiresPHP'] ?? '',
                'active'      => $is_active,
            ];
        }

        usort($result, function($a, $b) {
            if ($a['active'] !== $b['active']) return $a['active'] ? -1 : 1;
            return strcasecmp($a['name'], $b['name']);
        });

        return $this->success([
            'total'   => count($result),
            'plugins' => $result,
        ]);
    }
}

/**
 * Get WP-Cron Jobs Tool
 *
 * Lists all scheduled WP-Cron events with timestamps and recurrence.
 */
class MCP_Tool_Get_Cron_Jobs extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_cron_jobs';
    }

    public function get_description() {
        return 'List all scheduled WP-Cron events: hook name, next run time, recurrence interval, and arguments. Useful for diagnosing missed cron jobs or unexpected background processing.';
    }

    public function get_input_schema() {
        return [
            'type'       => 'object',
            'properties' => [
                'hook' => [
                    'type'        => 'string',
                    'description' => 'Filter by hook name (partial match, optional)',
                ],
            ],
        ];
    }

    public function execute($params) {
        $this->require_capability('manage_options');

        $cron_array = _get_cron_array();
        $hook_filter = isset($params['hook']) ? strtolower(sanitize_text_field($params['hook'])) : '';
        $events      = [];
        $now         = time();

        if (!is_array($cron_array)) {
            return $this->success(['total' => 0, 'events' => []], 'No cron events scheduled');
        }

        foreach ($cron_array as $timestamp => $hooks) {
            foreach ($hooks as $hook => $jobs) {
                if ($hook_filter && strpos(strtolower($hook), $hook_filter) === false) {
                    continue;
                }
                foreach ($jobs as $job) {
                    $schedule = $job['schedule'] ?? 'one-time';
                    $interval = $job['interval'] ?? null;
                    $events[] = [
                        'hook'           => $hook,
                        'next_run_ts'    => $timestamp,
                        'next_run'       => date('Y-m-d H:i:s', $timestamp),
                        'overdue_seconds'=> max(0, $now - $timestamp),
                        'overdue'        => $timestamp < $now,
                        'schedule'       => $schedule,
                        'interval_sec'   => $interval,
                        'args'           => $job['args'] ?? [],
                    ];
                }
            }
        }

        usort($events, function($a, $b) { return $a['next_run_ts'] - $b['next_run_ts']; });

        return $this->success([
            'total'  => count($events),
            'events' => $events,
        ]);
    }
}

/**
 * Get WP Option Tool
 *
 * Reads a single wp_options entry by key.
 */
class MCP_Tool_Get_WP_Option extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_option';
    }

    public function get_description() {
        return 'Read a WordPress option (wp_options row) by key. Returns the stored value. Useful for inspecting plugin configurations and site settings stored in the options table.';
    }

    public function get_input_schema() {
        return [
            'type'       => 'object',
            'properties' => [
                'option_name' => [
                    'type'        => 'string',
                    'description' => 'The option key to read (e.g. "blogname", "active_plugins", "siteurl")',
                ],
            ],
            'required'   => ['option_name'],
        ];
    }

    // Option keys that must never be exposed even to admins via this tool
    private $blocked_keys = [
        'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key',
        'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt',
    ];

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $key = sanitize_text_field($params['option_name']);

        if (in_array($key, $this->blocked_keys, true)) {
            throw new Exception("Option '{$key}' is blocked for security reasons");
        }

        $value = get_option($key, '__NOT_FOUND__');

        if ($value === '__NOT_FOUND__') {
            return $this->success([
                'option_name' => $key,
                'exists'      => false,
                'value'       => null,
            ], "Option '{$key}' does not exist");
        }

        // Serialize objects/arrays for readable output
        if (is_array($value) || is_object($value)) {
            $value = json_decode(json_encode($value), true);
        }

        return $this->success([
            'option_name' => $key,
            'exists'      => true,
            'value'       => $value,
            'type'        => gettype($value),
        ]);
    }
}

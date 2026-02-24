<?php
/**
 * MCP Tool: 404 Error Monitor
 *
 * Provides tools for monitoring and managing 404 errors.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * List 404 Errors Tool
 */
class MCP_Tool_List_404_Errors extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_list_404_errors';
    }

    public function get_description() {
        return 'List 404 errors with optional filters (search, date range, minimum hits)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'search' => [
                    'type' => 'string',
                    'description' => 'Search term for URL or user agent'
                ],
                'min_hits' => [
                    'type' => 'integer',
                    'description' => 'Minimum number of hits',
                    'minimum' => 1
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results',
                    'default' => 20,
                    'minimum' => 1,
                    'maximum' => 100
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Number of results to skip',
                    'default' => 0,
                    'minimum' => 0
                ]
            ],
            'required' => []
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        require_once plugin_dir_path(dirname(dirname(__FILE__))) . '404-monitor/class-metasync-404-monitor-database.php';
        $db = new Metasync_Error_Monitor_Database();

        // Build filters
        $filters = [];

        if (isset($params['search'])) {
            $filters['search'] = $this->sanitize_string($params['search']);
        }

        if (isset($params['min_hits'])) {
            $filters['min_hits'] = $this->sanitize_integer($params['min_hits']);
        }

        $filters['per_page'] = isset($params['limit']) ? $this->sanitize_integer($params['limit']) : 20;
        $filters['offset'] = isset($params['offset']) ? $this->sanitize_integer($params['offset']) : 0;
        $filters['order_by'] = 'hits_count';
        $filters['order'] = 'DESC';

        // Get 404 errors
        $errors = $db->search_404_errors($filters);
        $total = $db->count_404_errors($filters);

        // Format results
        $result = array_map(function($error) {
            return [
                'id' => $error->id,
                'uri' => $error->uri,
                'referer' => $error->referer ?? '',
                'hits' => $error->hits_count,
                'last_seen' => $error->date_time,
                'user_agent' => $error->user_agent ?? ''
            ];
        }, $errors);

        return $this->success([
            'errors' => $result,
            'total' => $total,
            'showing' => count($result),
            'offset' => $filters['offset']
        ]);
    }
}

/**
 * Get 404 Stats Tool
 */
class MCP_Tool_Get_404_Stats extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_404_stats';
    }

    public function get_description() {
        return 'Get 404 error statistics (total errors, total hits, top errors)';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => []
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        require_once plugin_dir_path(dirname(dirname(__FILE__))) . '404-monitor/class-metasync-404-monitor-database.php';
        $db = new Metasync_Error_Monitor_Database();

        global $wpdb;
        $table_name = $wpdb->prefix . 'metasync_404_logs';

        // Get stats
        $total_errors = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_hits = $wpdb->get_var("SELECT SUM(hits_count) FROM $table_name");

        // Get top 10 errors
        $top_errors = $wpdb->get_results("
            SELECT uri, hits_count, date_time
            FROM $table_name
            ORDER BY hits_count DESC
            LIMIT 10
        ");

        return $this->success([
            'total_unique_errors' => intval($total_errors),
            'total_hits' => intval($total_hits),
            'top_errors' => array_map(function($error) {
                return [
                    'uri' => $error->uri,
                    'hits' => $error->hits_count,
                    'last_seen' => $error->date_time
                ];
            }, $top_errors)
        ]);
    }
}

/**
 * Delete 404 Error Tool
 */
class MCP_Tool_Delete_404_Error extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_delete_404_error';
    }

    public function get_description() {
        return 'Delete a specific 404 error log entry by ID';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'error_id' => [
                    'type' => 'integer',
                    'description' => '404 error log ID',
                    'minimum' => 1
                ]
            ],
            'required' => ['error_id']
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        require_once plugin_dir_path(dirname(dirname(__FILE__))) . '404-monitor/class-metasync-404-monitor-database.php';
        $db = new Metasync_Error_Monitor_Database();

        $error_id = $this->sanitize_integer($params['error_id']);

        // Delete 404 error
        $result = $db->delete([$error_id]);

        if ($result === false) {
            throw new Exception('Failed to delete 404 error');
        }

        return $this->success([
            'error_id' => $error_id,
            'deleted' => true
        ], '404 error deleted successfully');
    }
}

/**
 * Clear All 404 Errors Tool
 */
class MCP_Tool_Clear_404_Errors extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_clear_404_errors';
    }

    public function get_description() {
        return 'Clear all 404 error logs';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'confirm' => [
                    'type' => 'boolean',
                    'description' => 'Confirmation flag (must be true to proceed)'
                ]
            ],
            'required' => ['confirm']
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        if (!isset($params['confirm']) || $params['confirm'] !== true) {
            throw new InvalidArgumentException('Confirmation required. Set confirm to true to proceed.');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'metasync_404_logs';

        // Clear all 404 errors
        $result = $wpdb->query("TRUNCATE TABLE $table_name");

        if ($result === false) {
            throw new Exception('Failed to clear 404 errors');
        }

        return $this->success([
            'cleared' => true
        ], 'All 404 errors cleared successfully');
    }
}

/**
 * Create Redirect from 404 Tool
 */
class MCP_Tool_Create_Redirect_From_404 extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_create_redirect_from_404';
    }

    public function get_description() {
        return 'Create a redirect from a 404 error and optionally delete the 404 log entry';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'error_id' => [
                    'type' => 'integer',
                    'description' => '404 error log ID',
                    'minimum' => 1
                ],
                'destination' => [
                    'type' => 'string',
                    'description' => 'Destination URL for the redirect'
                ],
                'type' => [
                    'type' => 'integer',
                    'description' => 'Redirect HTTP code',
                    'enum' => [301, 302],
                    'default' => 301
                ],
                'delete_404' => [
                    'type' => 'boolean',
                    'description' => 'Delete the 404 error after creating redirect',
                    'default' => true
                ]
            ],
            'required' => ['error_id', 'destination']
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        require_once plugin_dir_path(dirname(dirname(__FILE__))) . '404-monitor/class-metasync-404-monitor-database.php';
        require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'redirections/class-metasync-redirection-database.php';

        $error_db = new Metasync_Error_Monitor_Database();
        $redirect_db = new Metasync_Redirection_Database();

        $error_id = $this->sanitize_integer($params['error_id']);
        $destination = $this->sanitize_url($params['destination']);
        $http_code = isset($params['type']) ? $this->sanitize_integer($params['type']) : 301;
        $delete_404 = isset($params['delete_404']) ? (bool)$params['delete_404'] : true;

        // Get 404 error
        global $wpdb;
        $table_name = $wpdb->prefix . 'metasync_404_logs';
        $error = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $error_id));

        if (!$error) {
            throw new Exception(sprintf("404 error not found: %d", absint($error_id)));
        }

        // Create redirect
        $redirect_id = $redirect_db->add([
            'sources_from' => [$error->uri],
            'url_redirect_to' => $destination,
            'http_code' => $http_code,
            'description' => "Created from 404 error",
            'status' => 'active',
            'pattern_type' => 'exact'
        ]);

        if ($redirect_id === false) {
            throw new Exception('Failed to create redirect');
        }

        // Delete 404 error if requested
        if ($delete_404) {
            $error_db->delete([$error_id]);
        }

        return $this->success([
            'redirect_id' => $redirect_id,
            'source' => $error->uri,
            'destination' => $destination,
            'type' => $http_code,
            '404_deleted' => $delete_404
        ], 'Redirect created from 404 error');
    }
}
